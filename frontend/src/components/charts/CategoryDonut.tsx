import { useMemo } from 'react'
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts'
import { formatCents, formatPercent } from '../../lib/money'
import type { CategoryBreakdownSlice } from '../../types'
import { EmptyState } from '../ui/States'
import { OTHER_COLOR, seriesColor, tooltipItemStyle, tooltipStyle } from './chartTheme'

/** Past this many slices the ring stops being readable, so the tail is pooled. */
const MAX_SLICES = 7

interface Slice {
  key: string
  name: string
  cents: number
  percent: number
  color: string
}

interface CategoryDonutProps {
  breakdown: CategoryBreakdownSlice[]
  currency: string
}

/**
 * Where the month's spending went.
 *
 * Two deliberate choices. The tail beyond seven categories collapses into a
 * single neutral "Other" rather than growing the palette — a ninth invented hue
 * would not be separable from the eight before it. And the slices take their
 * colour from the validated categorical ramp rather than from each category's
 * own `color` field: the API's colours are user-chosen decoration, fine beside a
 * text label in a table but with no guarantee that two adjacent slices are
 * distinguishable. The category's own colour still appears on its chip in the
 * legend, so the association is not lost.
 *
 * The legend doubles as the direct-label layer: every slice has its value and
 * share written out, so identity never depends on colour alone.
 */
export function CategoryDonut({ breakdown, currency }: CategoryDonutProps) {
  const slices = useMemo<Slice[]>(() => {
    const ranked = [...breakdown].sort((a, b) => b.total_cents - a.total_cents)
    const head = ranked.slice(0, MAX_SLICES)
    const tail = ranked.slice(MAX_SLICES)

    const result: Slice[] = head.map((slice, index) => ({
      key: String(slice.category_id ?? slice.name),
      name: slice.name,
      cents: slice.total_cents,
      percent: slice.percent,
      color: seriesColor(index),
    }))

    if (tail.length > 0) {
      result.push({
        key: '__other',
        name: `Other (${tail.length})`,
        cents: tail.reduce((sum, slice) => sum + slice.total_cents, 0),
        percent: tail.reduce((sum, slice) => sum + slice.percent, 0),
        color: OTHER_COLOR,
      })
    }

    return result
  }, [breakdown])

  const total = slices.reduce((sum, slice) => sum + slice.cents, 0)

  if (slices.length === 0 || total === 0) {
    return (
      <EmptyState
        title="Nothing spent yet"
        message="Once this month has some expenses, the split across categories shows up here."
      />
    )
  }

  return (
    <div className="donut">
      <div className="donut__chart">
        <ResponsiveContainer width="100%" height={230}>
          <PieChart>
            <Pie
              data={slices}
              dataKey="cents"
              nameKey="name"
              innerRadius={62}
              outerRadius={95}
              paddingAngle={2}
              stroke="var(--bg-elevated)"
              strokeWidth={2}
              isAnimationActive={false}
            >
              {slices.map((slice) => (
                <Cell key={slice.key} fill={slice.color} />
              ))}
            </Pie>
            <Tooltip
              contentStyle={tooltipStyle}
              itemStyle={tooltipItemStyle}
              formatter={(value: number, name: string) => [formatCents(value, currency), name]}
            />
          </PieChart>
        </ResponsiveContainer>

        {/* Centred total, drawn over the hole rather than as a chart label. */}
        <div className="donut__center">
          <span className="donut__center-label">Spent</span>
          <strong className="donut__center-value numeric">{formatCents(total, currency)}</strong>
        </div>
      </div>

      <ul className="legend">
        {slices.map((slice) => (
          <li key={slice.key} className="legend__item">
            <span className="legend__swatch" style={{ background: slice.color }} aria-hidden="true" />
            <span className="legend__label">{slice.name}</span>
            <span className="legend__value numeric">{formatCents(slice.cents, currency)}</span>
            <span className="legend__percent numeric">{formatPercent(slice.percent)}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}
