import type { Expense, ExpenseFilters, ExpenseInput, SummaryResponse, User } from '../types'

const API_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000'

export class ApiError extends Error {
  readonly status: number
  readonly fieldErrors?: Record<string, string>

  constructor(message: string, status: number, fieldErrors?: Record<string, string>) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.fieldErrors = fieldErrors
  }
}

function authHeaders(): Record<string, string> {
  const token = localStorage.getItem('expense_tracker_token')
  return token ? { Authorization: `Bearer ${token}` } : {}
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const res = await fetch(`${API_URL}${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      ...authHeaders(),
      ...options.headers,
    },
  })

  const isJson = res.headers.get('content-type')?.includes('application/json')
  const body = isJson ? await res.json() : null

  if (!res.ok) {
    const message = body?.error ?? `Request failed with status ${res.status}`
    throw new ApiError(message, res.status, body?.errors)
  }

  return body as T
}

export interface AuthResponse {
  token: string
  user: User
}

export const authApi = {
  register(name: string, email: string, password: string): Promise<AuthResponse> {
    return request('/api/register', {
      method: 'POST',
      body: JSON.stringify({ name, email, password }),
    })
  },

  login(email: string, password: string): Promise<AuthResponse> {
    return request('/api/login', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    })
  },
}

function buildQuery(filters: ExpenseFilters): string {
  const params = new URLSearchParams()
  if (filters.category) params.set('category', filters.category)
  if (filters.from) params.set('from', filters.from)
  if (filters.to) params.set('to', filters.to)
  const qs = params.toString()
  return qs ? `?${qs}` : ''
}

export const expensesApi = {
  list(filters: ExpenseFilters = {}): Promise<{ data: Expense[] }> {
    return request(`/api/expenses${buildQuery(filters)}`)
  },

  create(input: ExpenseInput): Promise<{ data: Expense }> {
    return request('/api/expenses', {
      method: 'POST',
      body: JSON.stringify(input),
    })
  },

  update(id: number, input: Partial<ExpenseInput>): Promise<{ data: Expense }> {
    return request(`/api/expenses/${id}`, {
      method: 'PUT',
      body: JSON.stringify(input),
    })
  },

  remove(id: number): Promise<{ message: string }> {
    return request(`/api/expenses/${id}`, { method: 'DELETE' })
  },

  summary(): Promise<SummaryResponse> {
    return request('/api/summary')
  },
}
