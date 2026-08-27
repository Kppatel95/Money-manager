import { useMemo } from 'react'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { formatMonth, formatMonthShort } from '../../lib/dates'
import { formatCents, formatCentsCompact } from '../../lib/money'
import type { TrendPoint } from '../../types'
import { EmptyState } from '../ui/States'
import {
  axisTick,
  EXPENSE_COLOR,
  GRID_COLOR,
  INCOME_COLOR,
  tooltipItemStyle,
  tooltipLabelStyle,
  tooltipStyle,
} from './chartTheme'

interface TrendChartProps {
  trend: TrendPoint[]
  currency: string
}

/**
 * Six months of income against expense.
 *
 * Grouped bars, not two lines and certainly not two y-axes: both series are the
 * same measure in the same units, so they share one scale and the comparison is
 * a direct length comparison. Values stay in cents until the tick and tooltip
 * formatters, which are the only places a division happens.
 */
export function TrendChart({ trend, currency }: TrendChartProps) {
  const data = useMemo(
    () =>
      trend.map((point) => ({
        month: point.month,
        label: formatMonthShort(point.month),
        income: point.income_cents,
        expense: point.expense_cents,
      })),
    [trend],
  )

  const hasMovement = data.some((point) => point.income > 0 || point.expense > 0)

  if (!hasMovement) {
    return (
      <EmptyState
        title="No history yet"
        message="Add a few transactions and the last six months will chart themselves here."
      />
    )
  }

  return (
    <ResponsiveContainer width="100%" height={260}>
      <BarChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 4 }} barGap={4}>
        <CartesianGrid stroke={GRID_COLOR} strokeDasharray="3 3" vertical={false} />
        <XAxis dataKey="label" tick={axisTick} tickLine={false} axisLine={{ stroke: GRID_COLOR }} tickMargin={8} />
        <YAxis
          tick={axisTick}
          tickLine={false}
          axisLine={false}
          width={64}
          tickFormatter={(value: number) => formatCentsCompact(value, currency)}
        />
        <Tooltip
          cursor={{ fill: 'var(--bg-hover)' }}
          contentStyle={tooltipStyle}
          itemStyle={tooltipItemStyle}
          labelStyle={tooltipLabelStyle}
          labelFormatter={(label: string) => {
            const point = data.find((entry) => entry.label === label)
            return point ? formatMonth(point.month) : label
          }}
          formatter={(value: number, name: string) => [formatCents(value, currency), name]}
        />
        <Legend
          verticalAlign="bottom"
          iconType="circle"
          iconSize={9}
          wrapperStyle={{ fontSize: '0.8125rem', color: 'var(--text-muted)', paddingTop: 8 }}
        />
        <Bar dataKey="income" name="Income" fill={INCOME_COLOR} radius={[4, 4, 0, 0]} maxBarSize={26} />
        <Bar dataKey="expense" name="Expense" fill={EXPENSE_COLOR} radius={[4, 4, 0, 0]} maxBarSize={26} />
      </BarChart>
    </ResponsiveContainer>
  )
}
