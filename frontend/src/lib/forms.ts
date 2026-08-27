import { ApiError } from '../api/client'

/**
 * `react-hook-form`'s `setError`, loosened at the name parameter.
 *
 * Its real signature is keyed to the form's own field union, which cannot be
 * checked against the arbitrary strings the API sends back; `fields` below is
 * the runtime allow-list that takes its place.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type SetFieldError = (name: any, error: { type: string; message: string }) => void

/**
 * Maps a 422 back onto the form.
 *
 * The API returns `details` keyed by field name, and those names match the
 * form's fields, so a server-side rule the client does not know about (a
 * duplicate budget for a category/month, an account that has been archived
 * since the form opened) lands on the offending input instead of a generic
 * toast. Anything that doesn't match a known field is reported by the caller.
 *
 * Returns true when at least one message was placed on a field.
 */
export function applyApiFieldErrors(error: unknown, setError: SetFieldError, fields: readonly string[]): boolean {
  if (!(error instanceof ApiError) || !error.isValidation) return false

  let placed = false
  for (const [field, message] of Object.entries(error.details)) {
    if (!fields.includes(field)) continue
    setError(field, { type: 'server', message })
    placed = true
  }
  return placed
}
