import type { CSSProperties } from 'react'

/**
 * Chart styling shared by every chart in the app.
 *
 * The values are CSS custom properties rather than hex literals. SVG accepts
 * `fill="var(--chart-1)"`, so the charts re-colour themselves when the theme
 * flips without React re-rendering or a `useTheme()` read in every chart.
 */

/** Categorical slots, assigned in order and never cycled. */
export const SERIES_COLORS = [
  'var(--chart-1)',
  'var(--chart-2)',
  'var(--chart-3)',
  'var(--chart-4)',
  'var(--chart-5)',
  'var(--chart-6)',
  'var(--chart-7)',
] as const

/** Everything past the last slot collapses into one neutral bucket. */
export const OTHER_COLOR = 'var(--chart-other)'

export const INCOME_COLOR = 'var(--chart-income)'
export const EXPENSE_COLOR = 'var(--chart-expense)'

export const AXIS_COLOR = 'var(--text-subtle)'
export const GRID_COLOR = 'var(--border)'

export const axisTick = { fill: 'var(--text-muted)', fontSize: 11 } as const

/** Tooltip chrome, matched to the app's cards. */
export const tooltipStyle: CSSProperties = {
  background: 'var(--bg-elevated)',
  border: '1px solid var(--border)',
  borderRadius: '10px',
  boxShadow: 'var(--shadow-md)',
  color: 'var(--text)',
  fontSize: '0.8125rem',
  padding: '0.5rem 0.7rem',
}

export const tooltipLabelStyle: CSSProperties = {
  color: 'var(--text-muted)',
  fontWeight: 600,
  marginBottom: '0.25rem',
}

export const tooltipItemStyle: CSSProperties = {
  color: 'var(--text)',
  padding: 0,
}

/** The colour for slot `index`, folding overflow into the neutral bucket. */
export function seriesColor(index: number): string {
  return SERIES_COLORS[index] ?? OTHER_COLOR
}
