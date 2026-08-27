/**
 * Money helpers.
 *
 * The API is authoritative on integer cents, so every display path starts from
 * a `*_cents` value and divides only at the very last step. Nothing in the UI
 * adds or compares floats.
 */

const formatterCache = new Map<string, Intl.NumberFormat>()

function formatter(currency: string, options: Intl.NumberFormatOptions): Intl.NumberFormat {
  const key = `${currency}:${JSON.stringify(options)}`
  let cached = formatterCache.get(key)
  if (!cached) {
    try {
      cached = new Intl.NumberFormat(undefined, { style: 'currency', currency, ...options })
    } catch {
      // The API stores a free-text currency label; fall back if it isn't ISO 4217.
      cached = new Intl.NumberFormat(undefined, { style: 'decimal', minimumFractionDigits: 2, ...options })
    }
    formatterCache.set(key, cached)
  }
  return cached
}

export function formatCents(cents: number, currency = 'USD'): string {
  return formatter(currency, {}).format(cents / 100)
}

/** Drops the decimals — for axis ticks and other places where they are noise. */
export function formatCentsCompact(cents: number, currency = 'USD'): string {
  const abs = Math.abs(cents)
  if (abs >= 1_000_00) {
    return formatter(currency, { maximumFractionDigits: 1, notation: 'compact' }).format(cents / 100)
  }
  return formatter(currency, { maximumFractionDigits: 0 }).format(cents / 100)
}

export function formatSignedCents(cents: number, currency = 'USD'): string {
  const formatted = formatCents(Math.abs(cents), currency)
  if (cents > 0) return `+${formatted}`
  if (cents < 0) return `-${formatted}`
  return formatted
}

/** Cents back to the major-unit string the API wants on writes. */
export function centsToDecimalString(cents: number): string {
  return (cents / 100).toFixed(2)
}

/** Normalises user input into the major-unit string the API wants on writes. */
export function toAmountString(value: number | string): string {
  const numeric = typeof value === 'number' ? value : Number(value)
  return Number.isFinite(numeric) ? numeric.toFixed(2) : '0.00'
}

export function formatPercent(value: number, fractionDigits = 0): string {
  return `${value.toFixed(fractionDigits)}%`
}

/** Signed cents for a transaction as it affects `accountId`. */
export function signedAmountForAccount(
  transaction: { type: string; amount_cents: number; account_id: number; transfer_to_account_id: number | null },
  accountId: number,
): number {
  if (transaction.type === 'income') return transaction.amount_cents
  if (transaction.type === 'expense') return -transaction.amount_cents
  return transaction.transfer_to_account_id === accountId ? transaction.amount_cents : -transaction.amount_cents
}
