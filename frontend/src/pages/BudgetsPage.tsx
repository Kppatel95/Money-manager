import { useState } from 'react'
import { budgetsApi } from '../api/resources'
import { BudgetProgressRow } from '../components/BudgetProgressRow'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { ConfirmDialog } from '../components/ui/ConfirmDialog'
import { Modal } from '../components/ui/Modal'
import { PageHeader } from '../components/ui/PageHeader'
import { StatCard } from '../components/ui/StatCard'
import { EmptyState, ErrorState, SkeletonRows } from '../components/ui/States'
import { useReferenceData } from '../context/ReferenceDataContext'
import { useToast } from '../context/ToastContext'
import { BudgetForm } from '../forms/BudgetForm'
import { useAsync } from '../hooks/useAsync'
import { currentMonth, formatMonth, shiftMonth } from '../lib/dates'
import { formatCents } from '../lib/money'
import type { Budget, BudgetInput } from '../types'

export function BudgetsPage() {
  const [month, setMonth] = useState(currentMonth)
  const { accounts } = useReferenceData()
  const toast = useToast()

  const { data, isLoading, error, reload } = useAsync((signal) => budgetsApi.list(month, signal), [month])

  const [editing, setEditing] = useState<Budget | null>(null)
  const [isCreating, setCreating] = useState(false)
  const [pendingDelete, setPendingDelete] = useState<Budget | null>(null)

  const budgets = data?.data ?? []
  const meta = data?.meta
  const currency = accounts[0]?.currency ?? 'USD'
  const overspent = budgets.filter((budget) => budget.over_budget)

  const save = async (input: BudgetInput) => {
    if (editing) {
      await budgetsApi.update(editing.id, { amount_limit: input.amount_limit, month: input.month })
      toast.success('Budget updated.')
    } else {
      await budgetsApi.create(input)
      toast.success('Budget set.')
    }
    setEditing(null)
    setCreating(false)
    // Editing can move a budget to a different month, which may take it off
    // this view entirely — reloading is the only honest way to reflect that.
    if (input.month === month) reload()
    else setMonth(input.month)
  }

  const confirmDelete = async () => {
    if (!pendingDelete) return
    try {
      await budgetsApi.remove(pendingDelete.id)
      toast.success(`Budget for ${pendingDelete.category_name} removed.`)
      setPendingDelete(null)
      reload()
    } catch (cause) {
      toast.error(cause)
    }
  }

  if (error && !data) return <ErrorState error={error} onRetry={reload} />

  return (
    <>
      <PageHeader
        title="Budgets"
        description={`A monthly limit per category. Spend is summed from the ledger at request time.`}
        actions={
          <>
            <div className="month-picker">
              <Button size="sm" onClick={() => setMonth((m) => shiftMonth(m, -1))} aria-label="Previous month">
                ‹
              </Button>
              <input
                className="input month-picker__input"
                type="month"
                value={month}
                onChange={(event) => setMonth(event.target.value || currentMonth())}
                aria-label="Budget month"
              />
              <Button size="sm" onClick={() => setMonth((m) => shiftMonth(m, 1))} aria-label="Next month">
                ›
              </Button>
            </div>
            <Button variant="primary" onClick={() => setCreating(true)}>
              New budget
            </Button>
          </>
        }
      />

      <div className="stack">
        {meta && budgets.length > 0 && (
          <div className="grid grid--stats">
            <StatCard label="Budgeted" value={formatCents(meta.total_limit_cents, currency)} hint={formatMonth(month)} />
            <StatCard
              label="Spent"
              value={formatCents(meta.total_spent_cents, currency)}
              hint={`${Math.round(
                meta.total_limit_cents > 0 ? (meta.total_spent_cents / meta.total_limit_cents) * 100 : 0,
              )}% of the total limit`}
            />
            <StatCard
              label="Remaining"
              value={formatCents(meta.total_remaining_cents, currency)}
              tone={meta.total_remaining_cents < 0 ? 'negative' : 'positive'}
            />
            <StatCard
              label="Over budget"
              value={String(overspent.length)}
              hint={overspent.length === 0 ? 'All within limits' : overspent.map((b) => b.category_name).join(', ')}
              tone={overspent.length > 0 ? 'negative' : 'neutral'}
            />
          </div>
        )}

        <Card title={formatMonth(month)} subtitle={budgets.length > 0 ? `${budgets.length} budgeted categories` : undefined}>
          {isLoading ? (
            <SkeletonRows rows={4} />
          ) : budgets.length === 0 ? (
            <EmptyState
              icon="◔"
              title={`No budgets for ${formatMonth(month)}`}
              message="Pick a category and a monthly limit. Spend against it is computed from your transactions, so there is nothing to keep up to date."
              action={
                <Button variant="primary" onClick={() => setCreating(true)}>
                  Set a budget
                </Button>
              }
            />
          ) : (
            <ul className="budget-list">
              {budgets.map((budget) => (
                <BudgetProgressRow
                  key={budget.id}
                  budget={budget}
                  currency={currency}
                  actions={
                    <span className="row-actions">
                      <Button size="sm" variant="ghost" onClick={() => setEditing(budget)}>
                        Edit
                      </Button>
                      <Button size="sm" variant="ghost" onClick={() => setPendingDelete(budget)}>
                        Delete
                      </Button>
                    </span>
                  }
                />
              ))}
            </ul>
          )}
        </Card>
      </div>

      <Modal
        open={isCreating || editing !== null}
        title={editing ? `Edit ${editing.category_name} budget` : 'New budget'}
        onClose={() => {
          setCreating(false)
          setEditing(null)
        }}
      >
        <BudgetForm
          key={editing?.id ?? `new-${month}`}
          budget={editing ?? undefined}
          month={month}
          takenCategoryIds={budgets.map((budget) => budget.category_id)}
          onSubmit={save}
          onCancel={() => {
            setCreating(false)
            setEditing(null)
          }}
        />
      </Modal>

      <ConfirmDialog
        open={pendingDelete !== null}
        title="Delete budget"
        message={
          <>
            Remove the {formatMonth(month)} budget for <strong>{pendingDelete?.category_name}</strong>? Your
            transactions are untouched — only the limit goes away.
          </>
        }
        onConfirm={confirmDelete}
        onCancel={() => setPendingDelete(null)}
      />
    </>
  )
}
