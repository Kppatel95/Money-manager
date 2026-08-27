import { useState } from 'react'
import { dashboardApi, transactionsApi } from '../api/resources'
import { TrendChart } from '../components/charts/TrendChart'
import { seriesColor } from '../components/charts/chartTheme'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { PageHeader } from '../components/ui/PageHeader'
import { StatCard } from '../components/ui/StatCard'
import { EmptyState, ErrorState, SkeletonRows } from '../components/ui/States'
import { useReferenceData } from '../context/ReferenceDataContext'
import { useToast } from '../context/ToastContext'
import { useAsync } from '../hooks/useAsync'
import { currentMonth, formatMonth, monthEnd, monthStart, shiftMonth } from '../lib/dates'
import { formatCents, formatPercent, formatSignedCents } from '../lib/money'
import type { TransactionType } from '../types'
import { TRANSACTION_TYPES } from '../types'

/** Export presets, resolved against the month currently in view. */
const RANGE_PRESETS = [
  { id: 'month', label: 'This month', from: (m: string) => monthStart(m), to: (m: string) => monthEnd(m) },
  {
    id: 'quarter',
    label: 'Last 3 months',
    from: (m: string) => monthStart(shiftMonth(m, -2)),
    to: (m: string) => monthEnd(m),
  },
  { id: 'ytd', label: 'Year to date', from: (m: string) => `${m.slice(0, 4)}-01-01`, to: (m: string) => monthEnd(m) },
  { id: 'all', label: 'Everything', from: () => '', to: () => '' },
] as const

export function ReportsPage() {
  const [month, setMonth] = useState(currentMonth)
  const { accounts } = useReferenceData()
  const toast = useToast()

  const [preset, setPreset] = useState<(typeof RANGE_PRESETS)[number]['id']>('month')
  const [exportAccount, setExportAccount] = useState('')
  const [exportType, setExportType] = useState('')
  const [isExporting, setExporting] = useState(false)

  const { data, isLoading, error, reload } = useAsync((signal) => dashboardApi.summary(month, signal), [month])

  const currency = accounts[0]?.currency ?? 'USD'
  const breakdown = data?.category_breakdown ?? []
  const totals = data?.totals

  const runExport = async () => {
    const range = RANGE_PRESETS.find((entry) => entry.id === preset)!
    setExporting(true)
    try {
      await transactionsApi.exportCsv({
        date_from: range.from(month) || undefined,
        date_to: range.to(month) || undefined,
        account_id: exportAccount ? Number(exportAccount) : undefined,
        type: (exportType as TransactionType) || undefined,
      })
      toast.success('Export downloaded.')
    } catch (cause) {
      toast.error(cause)
    } finally {
      setExporting(false)
    }
  }

  if (error && !data) return <ErrorState error={error} onRetry={reload} />

  return (
    <>
      <PageHeader
        title="Reports"
        description="Where the money went, and a CSV of the underlying rows."
        actions={
          <div className="month-picker">
            <Button size="sm" onClick={() => setMonth((m) => shiftMonth(m, -1))} aria-label="Previous month">
              ‹
            </Button>
            <input
              className="input month-picker__input"
              type="month"
              value={month}
              max={currentMonth()}
              onChange={(event) => setMonth(event.target.value || currentMonth())}
              aria-label="Report month"
            />
            <Button
              size="sm"
              onClick={() => setMonth((m) => shiftMonth(m, 1))}
              disabled={month >= currentMonth()}
              aria-label="Next month"
            >
              ›
            </Button>
          </div>
        }
      />

      <div className="stack">
        <div className="grid grid--stats">
          <StatCard label="Income" value={totals ? formatCents(totals.income_cents, currency) : '—'} tone="positive" />
          <StatCard label="Expenses" value={totals ? formatCents(totals.expense_cents, currency) : '—'} tone="negative" />
          <StatCard
            label="Net"
            value={totals ? formatSignedCents(totals.net_cents, currency) : '—'}
            tone={!totals ? 'neutral' : totals.net_cents >= 0 ? 'positive' : 'negative'}
          />
          <StatCard
            label="Savings rate"
            value={totals ? formatPercent(totals.savings_rate, 1) : '—'}
            hint="Net as a share of income"
          />
        </div>

        <Card title="Income vs expense" subtitle="Six months ending with the month in view">
          {isLoading ? <SkeletonRows rows={5} /> : <TrendChart trend={data!.trend} currency={currency} />}
        </Card>

        <Card title="Spending by category" subtitle={formatMonth(month)} bodyClassName="card__body--flush">
          {isLoading ? (
            <div className="card__body">
              <SkeletonRows rows={5} />
            </div>
          ) : breakdown.length === 0 ? (
            <EmptyState title="No expenses this month" message="Pick another month, or add some transactions." />
          ) : (
            <div className="table-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th scope="col">Category</th>
                    <th scope="col">Share</th>
                    <th scope="col" className="cell--amount">
                      Transactions
                    </th>
                    <th scope="col" className="cell--amount">
                      Total
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {breakdown.map((slice, index) => (
                    <tr key={slice.category_id ?? slice.name}>
                      <td>
                        <span className="report-category">
                          <span
                            className="legend__swatch"
                            style={{ background: seriesColor(index) }}
                            aria-hidden="true"
                          />
                          {slice.icon && <span aria-hidden="true">{slice.icon}</span>}
                          <strong>{slice.name}</strong>
                        </span>
                      </td>
                      <td className="cell--share">
                        {/* Bar plus the number: the figure is never colour-alone. */}
                        <span className="share-bar" aria-hidden="true">
                          <span
                            className="share-bar__fill"
                            style={{ width: `${Math.min(slice.percent, 100)}%`, background: seriesColor(index) }}
                          />
                        </span>
                        <span className="numeric text-muted">{formatPercent(slice.percent, 1)}</span>
                      </td>
                      <td className="cell--amount text-muted">{slice.transaction_count}</td>
                      <td className="cell--amount">{formatCents(slice.total_cents, currency)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>

        <Card
          title="Export"
          subtitle="A CSV of the matching rows, straight from the API's export endpoint."
        >
          <div className="filters">
            <label className="filters__field">
              <span className="filters__label">Range</span>
              <select className="select" value={preset} onChange={(event) => setPreset(event.target.value as typeof preset)}>
                {RANGE_PRESETS.map((entry) => (
                  <option key={entry.id} value={entry.id}>
                    {entry.label}
                  </option>
                ))}
              </select>
            </label>

            <label className="filters__field">
              <span className="filters__label">Account</span>
              <select className="select" value={exportAccount} onChange={(event) => setExportAccount(event.target.value)}>
                <option value="">All accounts</option>
                {accounts.map((account) => (
                  <option key={account.id} value={account.id}>
                    {account.name}
                  </option>
                ))}
              </select>
            </label>

            <label className="filters__field">
              <span className="filters__label">Type</span>
              <select className="select" value={exportType} onChange={(event) => setExportType(event.target.value)}>
                <option value="">All types</option>
                {TRANSACTION_TYPES.map((type) => (
                  <option key={type} value={type}>
                    {type[0].toUpperCase() + type.slice(1)}
                  </option>
                ))}
              </select>
            </label>

            <div className="filters__field filters__field--action">
              <Button variant="primary" onClick={runExport} loading={isExporting}>
                Download CSV
              </Button>
            </div>
          </div>
        </Card>
      </div>
    </>
  )
}
