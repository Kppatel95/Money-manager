import { useState } from 'react'
import { recurringApi } from '../api/resources'
import { Badge, CategoryChip } from '../components/ui/Badge'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { ConfirmDialog } from '../components/ui/ConfirmDialog'
import { Modal } from '../components/ui/Modal'
import { PageHeader } from '../components/ui/PageHeader'
import { EmptyState, ErrorState, SkeletonRows } from '../components/ui/States'
import { useReferenceData } from '../context/ReferenceDataContext'
import { useToast } from '../context/ToastContext'
import { RecurringForm } from '../forms/RecurringForm'
import { useAsync } from '../hooks/useAsync'
import { formatDate, relativeDays, todayIso } from '../lib/dates'
import { formatCents } from '../lib/money'
import type { Frequency, RecurringTransaction, RecurringTransactionInput } from '../types'

const FREQUENCY_LABELS: Record<Frequency, string> = {
  daily: 'Daily',
  weekly: 'Weekly',
  monthly: 'Monthly',
}

export function RecurringPage() {
  const { activeAccounts, reload: reloadReference } = useReferenceData()
  const toast = useToast()
  const { data, isLoading, error, reload } = useAsync((signal) => recurringApi.list(signal), [])

  const [editing, setEditing] = useState<RecurringTransaction | null>(null)
  const [isCreating, setCreating] = useState(false)
  const [pendingDelete, setPendingDelete] = useState<RecurringTransaction | null>(null)
  const [togglingId, setTogglingId] = useState<number | null>(null)

  const schedules = data ?? []
  const today = todayIso()

  const save = async (input: RecurringTransactionInput) => {
    if (editing) {
      await recurringApi.update(editing.id, input)
      toast.success('Schedule updated.')
    } else {
      await recurringApi.create(input)
      toast.success('Schedule created.')
    }
    setEditing(null)
    setCreating(false)
    reload()
    reloadReference()
  }

  const toggleActive = async (schedule: RecurringTransaction) => {
    setTogglingId(schedule.id)
    try {
      await recurringApi.update(schedule.id, { active: !schedule.active })
      toast.success(schedule.active ? 'Schedule paused.' : 'Schedule resumed.')
      reload()
    } catch (cause) {
      toast.error(cause)
    } finally {
      setTogglingId(null)
    }
  }

  const confirmDelete = async () => {
    if (!pendingDelete) return
    try {
      await recurringApi.remove(pendingDelete.id)
      toast.success('Schedule deleted.')
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
        title="Recurring"
        description="Schedules post themselves. Anything due is caught up on your next request — including runs missed while you were away."
        actions={
          <Button variant="primary" onClick={() => setCreating(true)} disabled={activeAccounts.length === 0}>
            New schedule
          </Button>
        }
      />

      <Card bodyClassName="card__body--flush">
        {isLoading ? (
          <div className="card__body">
            <SkeletonRows rows={4} />
          </div>
        ) : schedules.length === 0 ? (
          <EmptyState
            icon="🔁"
            title="No recurring transactions"
            message="Rent, a salary, a subscription — anything that repeats on a fixed rhythm. Set it once and it posts itself."
            action={
              <Button variant="primary" onClick={() => setCreating(true)} disabled={activeAccounts.length === 0}>
                {activeAccounts.length === 0 ? 'Add an account first' : 'Create a schedule'}
              </Button>
            }
          />
        ) : (
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th scope="col">Description</th>
                  <th scope="col">Category</th>
                  <th scope="col">Account</th>
                  <th scope="col">Frequency</th>
                  <th scope="col">Next run</th>
                  <th scope="col" className="cell--amount">
                    Amount
                  </th>
                  <th scope="col">Status</th>
                  <th scope="col" className="cell--actions">
                    <span className="visually-hidden">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {schedules.map((schedule) => {
                  const isDue = schedule.active && schedule.next_run_date.slice(0, 10) <= today

                  return (
                    <tr key={schedule.id} className={schedule.active ? undefined : 'row--muted'}>
                      <td className="cell--wrap">
                        <strong>{schedule.description}</strong>
                      </td>
                      <td>
                        {schedule.category_name ? (
                          <CategoryChip
                            name={schedule.category_name}
                            icon={schedule.category_icon}
                            color={schedule.category_color}
                          />
                        ) : (
                          <span className="text-subtle">—</span>
                        )}
                      </td>
                      <td className="text-muted">{schedule.account_name}</td>
                      <td>{FREQUENCY_LABELS[schedule.frequency]}</td>
                      <td>
                        <span>{formatDate(schedule.next_run_date)}</span>{' '}
                        <span className={isDue ? 'text-negative' : 'text-muted'}>
                          ({relativeDays(schedule.next_run_date)})
                        </span>
                      </td>
                      <td className={`cell--amount ${schedule.type === 'income' ? 'text-positive' : 'text-negative'}`}>
                        {schedule.type === 'income' ? '+' : '−'}
                        {formatCents(schedule.amount_cents)}
                      </td>
                      <td>
                        {schedule.active ? (
                          isDue ? (
                            <Badge tone="warn">Due</Badge>
                          ) : (
                            <Badge tone="income">Active</Badge>
                          )
                        ) : (
                          <Badge tone="muted">Paused</Badge>
                        )}
                      </td>
                      <td className="cell--actions">
                        <span className="row-actions">
                          <Button
                            size="sm"
                            variant="ghost"
                            loading={togglingId === schedule.id}
                            onClick={() => toggleActive(schedule)}
                          >
                            {schedule.active ? 'Pause' : 'Resume'}
                          </Button>
                          <Button size="sm" variant="ghost" onClick={() => setEditing(schedule)}>
                            Edit
                          </Button>
                          <Button size="sm" variant="ghost" onClick={() => setPendingDelete(schedule)}>
                            Delete
                          </Button>
                        </span>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal
        open={isCreating || editing !== null}
        size="lg"
        title={editing ? 'Edit schedule' : 'New schedule'}
        onClose={() => {
          setCreating(false)
          setEditing(null)
        }}
      >
        <RecurringForm
          key={editing?.id ?? 'new'}
          schedule={editing ?? undefined}
          onSubmit={save}
          onCancel={() => {
            setCreating(false)
            setEditing(null)
          }}
        />
      </Modal>

      <ConfirmDialog
        open={pendingDelete !== null}
        title="Delete schedule"
        message={
          <>
            Delete the schedule for <strong>{pendingDelete?.description}</strong>? Transactions it has already posted
            stay in your ledger — only future runs stop.
          </>
        }
        onConfirm={confirmDelete}
        onCancel={() => setPendingDelete(null)}
      />
    </>
  )
}
