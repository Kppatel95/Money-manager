import type { ApiErrorBody, ApiErrorCode, Session } from '../types'
import { tokenStore } from './tokens'

const BASE_URL = `${(import.meta.env.VITE_API_URL ?? 'http://localhost:8000').replace(/\/+$/, '')}/api/v1`

/** Endpoints that must never trigger the refresh-and-retry dance. */
const AUTH_PATHS = ['/auth/login', '/auth/register', '/auth/refresh']

export class ApiError extends Error {
  readonly status: number
  readonly code: ApiErrorCode
  /** Field-level messages from a 422, keyed by field name. */
  readonly details: Record<string, string>
  /** Seconds to wait, from `Retry-After` on a 429. */
  readonly retryAfter: number | null

  constructor(
    message: string,
    status: number,
    code: ApiErrorCode = 'INTERNAL_ERROR',
    details: Record<string, string> = {},
    retryAfter: number | null = null,
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.details = details
    this.retryAfter = retryAfter
  }

  get isValidation(): boolean {
    return this.code === 'VALIDATION_ERROR'
  }
}

/** Thrown when the session is gone for good; the shell turns this into a redirect. */
export class SessionExpiredError extends ApiError {
  constructor() {
    super('Your session has expired. Please sign in again.', 401, 'UNAUTHORIZED')
    this.name = 'SessionExpiredError'
  }
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  /** Serialised as JSON. */
  body?: unknown
  query?: Record<string, string | number | boolean | undefined | null>
  signal?: AbortSignal
  /** Skip the Authorization header and the refresh retry. */
  anonymous?: boolean
}

function buildUrl(path: string, query?: RequestOptions['query']): string {
  const url = new URL(`${BASE_URL}${path}`, window.location.origin)
  if (query) {
    for (const [key, value] of Object.entries(query)) {
      if (value === undefined || value === null || value === '') continue
      url.searchParams.set(key, String(value))
    }
  }
  return url.toString()
}

async function toApiError(response: Response): Promise<ApiError> {
  let body: Partial<ApiErrorBody> = {}
  try {
    body = (await response.json()) as Partial<ApiErrorBody>
  } catch {
    /* non-JSON error body (a PHP fatal, a proxy page) — fall through */
  }

  const error = body.error
  const retryAfterHeader = response.headers.get('Retry-After')

  return new ApiError(
    error?.message ?? `Request failed with status ${response.status}`,
    response.status,
    error?.code ?? 'INTERNAL_ERROR',
    error?.details ?? {},
    retryAfterHeader ? Number(retryAfterHeader) : null,
  )
}

/* -------------------------------------------------------------------------- */
/* Refresh                                                                    */
/* -------------------------------------------------------------------------- */

/**
 * In-flight refresh, shared by every caller.
 *
 * A page that fires five requests at once would otherwise send five refreshes;
 * because the API rotates the refresh token on every use, four of them would
 * present an already-spent token and get a 401, logging the user out on an
 * otherwise healthy session. One promise, reused, avoids that entirely.
 */
let refreshInFlight: Promise<string> | null = null

async function refreshAccessToken(): Promise<string> {
  if (refreshInFlight) return refreshInFlight

  refreshInFlight = (async () => {
    const refreshToken = tokenStore.refreshToken()
    if (!refreshToken) throw new SessionExpiredError()

    const response = await fetch(buildUrl('/auth/refresh'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ refresh_token: refreshToken }),
    })

    if (!response.ok) throw new SessionExpiredError()

    const { data } = (await response.json()) as { data: Session }
    tokenStore.save(data)
    return data.access_token
  })()

  try {
    return await refreshInFlight
  } finally {
    refreshInFlight = null
  }
}

/* -------------------------------------------------------------------------- */
/* Core request                                                               */
/* -------------------------------------------------------------------------- */

async function send(path: string, options: RequestOptions, accessToken: string | null): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (options.body !== undefined) headers['Content-Type'] = 'application/json'
  if (accessToken) headers.Authorization = `Bearer ${accessToken}`

  return fetch(buildUrl(path, options.query), {
    method: options.method ?? 'GET',
    headers,
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
    signal: options.signal,
  })
}

/**
 * Performs a request and, on a 401, transparently refreshes the access token
 * once and replays the original request. If the refresh fails the session is
 * cleared and `SessionExpiredError` propagates — `AuthProvider` listens for the
 * cleared store and sends the user to the login screen.
 */
async function rawRequest(path: string, options: RequestOptions = {}): Promise<Response> {
  const anonymous = options.anonymous || AUTH_PATHS.includes(path)
  let response = await send(path, options, anonymous ? null : tokenStore.accessToken())

  if (response.status !== 401 || anonymous) return response

  try {
    const accessToken = await refreshAccessToken()
    response = await send(path, options, accessToken)
  } catch {
    tokenStore.clear()
    throw new SessionExpiredError()
  }

  if (response.status === 401) {
    tokenStore.clear()
    throw new SessionExpiredError()
  }

  return response
}

/** JSON request. Returns `undefined` for a 204. */
export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const response = await rawRequest(path, options)
  if (!response.ok) throw await toApiError(response)

  if (response.status === 204 || response.headers.get('Content-Length') === '0') {
    return undefined as T
  }

  const contentType = response.headers.get('Content-Type') ?? ''
  if (!contentType.includes('application/json')) return undefined as T

  return (await response.json()) as T
}

async function sendFile(path: string, file: File, fieldName: string, accessToken: string | null): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (accessToken) headers.Authorization = `Bearer ${accessToken}`

  const body = new FormData()
  body.append(fieldName, file)

  // No Content-Type header: the browser sets one with the correct multipart
  // boundary itself, which is not something JSON.stringify's callers need.
  return fetch(buildUrl(path), { method: 'POST', headers, body })
}

/** Same 401-refresh-and-replay dance as rawRequest, for a multipart upload. */
async function rawUpload(path: string, file: File, fieldName: string): Promise<Response> {
  let response = await sendFile(path, file, fieldName, tokenStore.accessToken())

  if (response.status !== 401) return response

  try {
    const accessToken = await refreshAccessToken()
    response = await sendFile(path, file, fieldName, accessToken)
  } catch {
    tokenStore.clear()
    throw new SessionExpiredError()
  }

  if (response.status === 401) {
    tokenStore.clear()
    throw new SessionExpiredError()
  }

  return response
}

/** Uploads a file as multipart/form-data and returns the parsed JSON body. */
export async function upload<T>(path: string, file: File, fieldName = 'file'): Promise<T> {
  const response = await rawUpload(path, file, fieldName)
  if (!response.ok) throw await toApiError(response)

  const contentType = response.headers.get('Content-Type') ?? ''
  if (!contentType.includes('application/json')) return undefined as T

  return (await response.json()) as T
}

/**
 * Downloads a file through the same authenticated pipeline.
 *
 * A plain `<a href>` cannot carry the bearer token, so the response is fetched
 * as a blob and handed to a temporary object URL. The filename comes from
 * `Content-Disposition` when the server sends one.
 */
export async function download(path: string, options: RequestOptions = {}, fallbackName = 'download'): Promise<void> {
  const response = await rawRequest(path, options)
  if (!response.ok) throw await toApiError(response)

  const disposition = response.headers.get('Content-Disposition') ?? ''
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)
  const filename = match ? decodeURIComponent(match[1]) : fallbackName

  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(url)
}

/** Turns anything thrown by the client into a message safe to show a user. */
export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) return error.message
  if (error instanceof TypeError) return 'Could not reach the server. Is the API running?'
  if (error instanceof Error) return error.message
  return 'Something went wrong.'
}
