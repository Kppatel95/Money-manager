/**
 * Date helpers.
 *
 * The API speaks `YYYY-MM-DD` and `YYYY-MM` and nothing else. These functions
 * stay in that string space wherever possible: constructing a `Date` from a
 * bare date string parses it as UTC, which silently shifts the day for anyone
 * west of Greenwich.
 */

const pad = (value: number): string => String(value).padStart(2, '0')

/** Today as `YYYY-MM-DD`, in the viewer's own timezone. */
export function todayIso(): string {
  const now = new Date()
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

/** The current month as `YYYY-MM`. */
export function currentMonth(): string {
  return todayIso().slice(0, 7)
}

/** First day of the given `YYYY-MM` as `YYYY-MM-DD`. */
export function monthStart(month: string): string {
  return `${month}-01`
}

/** Last day of the given `YYYY-MM` as `YYYY-MM-DD`. */
export function monthEnd(month: string): string {
  const [year, m] = month.split('-').map(Number)
  return `${month}-${pad(new Date(year, m, 0).getDate())}`
}

/** Shifts a `YYYY-MM` by whole months. */
export function shiftMonth(month: string, delta: number): string {
  const [year, m] = month.split('-').map(Number)
  const shifted = new Date(year, m - 1 + delta, 1)
  return `${shifted.getFullYear()}-${pad(shifted.getMonth() + 1)}`
}

/** `2026-05` to `May 2026`. */
export function formatMonth(month: string, style: 'long' | 'short' = 'long'): string {
  const [year, m] = month.split('-').map(Number)
  if (!year || !m) return month
  return new Date(year, m - 1, 1).toLocaleDateString(undefined, { month: style, year: 'numeric' })
}

/** `2026-05` to `May` — for chart axes, where the year is repetitive. */
export function formatMonthShort(month: string): string {
  const [year, m] = month.split('-').map(Number)
  if (!year || !m) return month
  return new Date(year, m - 1, 1).toLocaleDateString(undefined, { month: 'short' })
}

/** `2026-05-04` to a locale medium date, parsed as local time. */
export function formatDate(iso: string): string {
  const [year, month, day] = iso.slice(0, 10).split('-').map(Number)
  if (!year || !month || !day) return iso
  return new Date(year, month - 1, day).toLocaleDateString(undefined, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

/** "in 3 days" / "2 days ago" / "today", for recurring next-run dates. */
export function relativeDays(iso: string): string {
  const [year, month, day] = iso.slice(0, 10).split('-').map(Number)
  if (!year || !month || !day) return iso

  const target = new Date(year, month - 1, day)
  const now = new Date()
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const days = Math.round((target.getTime() - today.getTime()) / 86_400_000)

  if (days === 0) return 'today'
  if (days === 1) return 'tomorrow'
  if (days === -1) return 'yesterday'
  return days > 0 ? `in ${days} days` : `${Math.abs(days)} days ago`
}
