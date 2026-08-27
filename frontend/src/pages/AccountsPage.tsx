import { useState } from 'react'
import { accountsApi } from '../api/resources'
import { Badge } from '../components/ui/Badge'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { ConfirmDialog } from '../components/ui/ConfirmDialog'
import { Modal } from '../components/ui/Modal'
import { PageHeader } from '../components/ui/PageHeader'
import { EmptyState, ErrorState, SkeletonRows } from '../components/ui/States'
import { useReferenceData } from '../context/ReferenceDataContext'
import { useToast } from '../context/ToastContext'
import { AccountForm } from '../forms/AccountForm'
import { formatDate } from '../lib/dates'
import { formatCents } from '../lib/money'
import type { Account, AccountInput, AccountType } from '../types'

const TYPE_META: Record<AccountType, { icon: string; label: string }> = {
  cash: { icon: '💵', label: 'Cash' },
  bank: { icon: '🏦', label: 'Bank' },
  card: { icon: '💳', label: 'Card' },
  wallet: { icon: '👛', label: 'Wallet' },
  savings: { icon: '🐷', label: 'Savings' },
}

export function AccountsPage() {
  const { accounts, isLoading, error, reload } = useReferenceData()
  const toast = useToast()

  const [editing, setEditing] = useState<Account | null>(null)
  const [isCreating, setCreating] = useState(false)
  const [pendingDelete, setPendingDelete] = useState<Account | null>(null)
  const [showArchived, setShowArchived] = useState(false)

  const visible = accounts.filter((account) => showArchived || !account.archived)
  const archivedCount = accounts.filter((account) => account.archived).length
  const activeTotal = accounts
    .filter((account) => !account.archived)
    .reduce((sum, account) => sum + account.balance_cents, 0)
  const currency = accounts[0]?.currency ?? 'USD'

  const save = async (input: AccountInput) => {
    if (editing) {
      await accountsApi.update(editing.id, input)
      toast.success(`Updated ${input.name}.`)
    } else {
      await accountsApi.create(input)
      toast.success(`Added ${input.name}.`)
    }
    setEditing(null)
    setCreating(false)
    reload()
  }

  const confirmDelete = async () => {
    if (!pendingDelete) return
    try {
      await accountsApi.remove(pendingDelete.id)
      // The API archives rather than deletes when there is history behind the
      // account, so the wording stays deliberately non-committal.
      toast.success(`${pendingDelete.name} removed.`)
      setPendingDelete(null)
      reload()
    } catch (cause) {
      toast.error(cause)
    }
  }

  if (error && accounts.length === 0) return <ErrorState error={error} onRetry={reload} />

  return (
    <>
      <PageHeader
        title="Accounts"
        description={
          isLoading ? 'Loading…' : `${visible.length} shown · ${formatCents(activeTotal, currency)} across active accounts`
        }
        actions={
          <>
            {archivedCount > 0 && (
              <Button size="sm" onClick={() => setShowArchived((value) => !value)}>
                {showArchived ? 'Hide archived' : `Show archived (${archivedCount})`}
              </Button>
            )}
            <Button variant="primary" onClick={() => setCreating(true)}>
              New account
            </Button>
          </>
        }
      />

      {isLoading ? (
        <Card>
          <SkeletonRows rows={4} />
        </Card>
      ) : visible.length === 0 ? (
        <Card>
          <EmptyState
            icon="🏦"
            title="No accounts yet"
            message="An account is where money sits — a current account, a card, the cash in your pocket. Transactions are filed against one."
            action={
              <Button variant="primary" onClick={() => setCreating(true)}>
                Add your first account
              </Button>
            }
          />
        </Card>
      ) : (
        <div className="account-grid">
          {visible.map((account) => {
            const meta = TYPE_META[account.type]
            const movement = account.balance_cents - account.initial_balance_cents

            return (
              <article key={account.id} className={`account ${account.archived ? 'account--archived' : ''}`.trim()}>
                <header className="account__head">
                  <span className="account__icon" aria-hidden="true">
                    {meta.icon}
                  </span>
                  <div className="account__titles">
                    <h2 className="account__name">{account.name}</h2>
                    <p className="account__meta">
                      {meta.label} · {account.currency}
                    </p>
                  </div>
                  {account.archived && <Badge tone="muted">Archived</Badge>}
                </header>

                <p className={`account__balance numeric ${account.balance_cents < 0 ? 'text-negative' : ''}`.trim()}>
                  {formatCents(account.balance_cents, account.currency)}
                </p>

                <dl className="account__facts">
                  <div>
                    <dt>Opening</dt>
                    <dd className="numeric">{formatCents(account.initial_balance_cents, account.currency)}</dd>
                  </div>
                  <div>
                    <dt>Movement</dt>
                    <dd className={`numeric ${movement < 0 ? 'text-negative' : movement > 0 ? 'text-positive' : ''}`.trim()}>
                      {formatCents(movement, account.currency)}
                    </dd>
                  </div>
                  <div>
                    <dt>Opened</dt>
                    <dd>{formatDate(account.created_at)}</dd>
                  </div>
                </dl>

                <footer className="account__actions">
                  <Button size="sm" onClick={() => setEditing(account)}>
                    Edit
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => setPendingDelete(account)}>
                    {account.archived ? 'Delete' : 'Archive'}
                  </Button>
                </footer>
              </article>
            )
          })}
        </div>
      )}

      <Modal
        open={isCreating || editing !== null}
        title={editing ? `Edit ${editing.name}` : 'New account'}
        description={editing ? undefined : 'Where money sits. You can add more later.'}
        onClose={() => {
          setCreating(false)
          setEditing(null)
        }}
      >
        <AccountForm
          key={editing?.id ?? 'new'}
          account={editing ?? undefined}
          onSubmit={save}
          onCancel={() => {
            setCreating(false)
            setEditing(null)
          }}
        />
      </Modal>

      <ConfirmDialog
        open={pendingDelete !== null}
        title={pendingDelete?.archived ? 'Delete account' : 'Remove account'}
        confirmLabel={pendingDelete?.archived ? 'Delete' : 'Remove'}
        message={
          <>
            Remove <strong>{pendingDelete?.name}</strong>? If it has any transaction history it will be archived rather
            than deleted, so nothing in your ledger is lost.
          </>
        }
        onConfirm={confirmDelete}
        onCancel={() => setPendingDelete(null)}
      />
    </>
  )
}
