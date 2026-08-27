import { useState } from 'react'
import { Link } from 'react-router-dom'
import { dashboardApi } from '../api/resources'
import { BudgetProgressRow } from '../components/BudgetProgressRow'
import { CategoryDonut } from '../components/charts/CategoryDonut'
import { TrendChart } from '../components/charts/TrendChart'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { PageHeader } from '../components/ui/PageHeader'
import { StatCard } from '../components/ui/StatCard'
import { EmptyState, ErrorState, SkeletonRows } from '../components/ui/States'
import { useAsync } from '../hooks/useAsync'
import { currentMonth, formatMonth, shiftMonth } from '../lib/dates'
import { formatCents, formatPercent, formatSignedCents } from '../lib/money'

const ACCOUNT_ICONS: Record<string, string> = {
  cash: '💵',
  bank: '🏦',
  card: '💳',
  wallet: '👛',
  savings: '🐷',
}

export function DashboardPage() {
  const [month, setMonth] = useState(currentMonth)
  const { data, isLoading, error, reload } = useAsync((signal) => dashboardApi.summary(month, signal), [month])

  // One request covers the whole page, so a single failure covers it too.
  if (error && !data) return <ErrorState error={error} onRetry={reload} />

  const currency = data?.accounts[0]?.currency ?? 'USD'
  const totals = data?.totals
  const isEmpty = Boolean(data) && data!.accounts.length === 0

  return (
    <>
      <PageHeader
        title="Dashboard"
        description={`An overview of ${formatMonth(month)}.`}
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
              aria-label="Month"
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

      {isEmpty ? (
        <Card>
          <EmptyState
            icon="🏦"
            title="Start with an account"
            message="Everything here is derived from your accounts and the transactions filed against them. Add your first account and the dashboard fills in."
            action={
              <Link className="btn btn--primary btn--md" to="/accounts">
                Add an account
              </Link>
            }
          />
        </Card>
      ) : (
        <div className="stack">
          <div className="grid grid--stats">
            <StatCard
              label="Net worth"
              value={isLoading ? '—' : formatCents(data!.net_worth_cents, currency)}
              hint={`${data?.accounts.filter((a) => !a.archived).length ?? 0} active accounts`}
              icon="◈"
            />
            <StatCard
              label="Income"
              value={isLoading ? '—' : formatCents(totals!.income_cents, currency)}
              hint={formatMonth(month, 'short')}
              tone="positive"
              icon="↓"
            />
            <StatCard
              label="Expenses"
              value={isLoading ? '—' : formatCents(totals!.expense_cents, currency)}
              hint={formatMonth(month, 'short')}
              tone="negative"
              icon="↑"
            />
            <StatCard
              label="Net"
              value={isLoading ? '—' : formatSignedCents(totals!.net_cents, currency)}
              hint={isLoading ? undefined : `Savings rate ${formatPercent(totals!.savings_rate, 1)}`}
              tone={!totals ? 'neutral' : totals.net_cents > 0 ? 'positive' : totals.net_cents < 0 ? 'negative' : 'neutral'}
              icon="Σ"
            />
          </div>

          <div className="grid grid--split">
            <Card title="Income vs expense" subtitle="Last six months">
              {isLoading ? <SkeletonRows rows={5} /> : <TrendChart trend={data!.trend} currency={currency} />}
            </Card>

            <Card title="Where it went" subtitle={formatMonth(month)}>
              {isLoading ? (
                <SkeletonRows rows={5} />
              ) : (
                <CategoryDonut breakdown={data!.category_breakdown} currency={currency} />
              )}
            </Card>
          </div>

          <div className="grid grid--split">
            <Card
              title="Budgets"
              subtitle={formatMonth(month)}
              actions={
                <Link className="btn btn--ghost btn--sm" to="/budgets">
                  Manage
                </Link>
              }
            >
              {isLoading ? (
                <SkeletonRows rows={3} />
              ) : data!.budgets.length === 0 ? (
                <EmptyState
                  icon="◔"
                  title="No budgets for this month"
                  message="Set a monthly limit on a category and its progress shows up here."
                  action={
                    <Link className="btn btn--secondary btn--md" to="/budgets">
                      Set a budget
                    </Link>
                  }
                />
              ) : (
                <ul className="budget-list">
                  {data!.budgets.map((budget) => (
                    <BudgetProgressRow key={budget.id} budget={budget} currency={currency} />
                  ))}
                </ul>
              )}
            </Card>

            <Card
              title="Accounts"
              actions={
                <Link className="btn btn--ghost btn--sm" to="/accounts">
                  Manage
                </Link>
              }
            >
              {isLoading ? (
                <SkeletonRows rows={3} />
              ) : (
                <ul className="mini-list">
                  {data!.accounts
                    .filter((account) => !account.archived)
                    .map((account) => (
                      <li key={account.id} className="mini-list__item">
                        <span className="mini-list__icon" aria-hidden="true">
                          {ACCOUNT_ICONS[account.type] ?? '💼'}
                        </span>
                        <span className="mini-list__text">
                          <strong>{account.name}</strong>
                          <small className="text-muted">{account.type}</small>
                        </span>
                        <span
                          className={`mini-list__value numeric ${account.balance_cents < 0 ? 'text-negative' : ''}`.trim()}
                        >
                          {formatCents(account.balance_cents, account.currency)}
                        </span>
                      </li>
                    ))}
                </ul>
              )}
            </Card>
          </div>
        </div>
      )}
    </>
  )
}
