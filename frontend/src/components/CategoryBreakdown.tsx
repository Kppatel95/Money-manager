import type { CategorySummary } from '../types'

interface CategoryBreakdownProps {
  summary: CategorySummary[]
  total: number
  loading: boolean
}

const currencyFormatter = new Intl.NumberFormat('en-US', {
  style: 'currency',
  currency: 'USD',
})

export function CategoryBreakdown({ summary, total, loading }: CategoryBreakdownProps) {
  if (loading) {
    return <p className="empty-state">Loading summary…</p>
  }

  if (summary.length === 0) {
    return <p className="empty-state">Add an expense to see your breakdown by category.</p>
  }

  const max = Math.max(...summary.map((row) => row.total))

  return (
    <div>
      <div className="summary-total">
        <span>Total spent</span>
        <strong>{currencyFormatter.format(total)}</strong>
      </div>
      <ul className="breakdown-list">
        {summary.map((row) => (
          <li key={row.category} className="breakdown-row">
            <div className="breakdown-label">
              <span>{row.category}</span>
              <span className="breakdown-meta">
                {currencyFormatter.format(row.total)} · {row.count}{' '}
                {row.count === 1 ? 'expense' : 'expenses'}
              </span>
            </div>
            <div className="breakdown-bar-track">
              <div
                className="breakdown-bar-fill"
                style={{ width: `${max > 0 ? (row.total / max) * 100 : 0}%` }}
              />
            </div>
          </li>
        ))}
      </ul>
    </div>
  )
}
