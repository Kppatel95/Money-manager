import { useEffect, useMemo, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { billScansApi, transactionsApi } from '../api/resources'
import { Badge, CategoryChip } from '../components/ui/Badge'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { ConfirmDialog } from '../components/ui/ConfirmDialog'
import { Modal } from '../components/ui/Modal'
import { PageHeader } from '../components/ui/PageHeader'
import { EmptyState, ErrorState, SkeletonRows, Spinner } from '../components/ui/States'
import { useReferenceData } from '../context/ReferenceDataContext'
import { useToast } from '../context/ToastContext'
import { BillScanReview } from '../forms/BillScanReview'
import { TransactionForm } from '../forms/TransactionForm'
import { useAsync } from '../hooks/useAsync'
import { useDebouncedValue } from '../hooks/useDebouncedValue'
import { formatDate } from '../lib/dates'
import { formatCents } from '../lib/money'
import type { BillScanDraft, Transaction, TransactionFilters, TransactionInput, TransactionType } from '../types'
import { TRANSACTION_TYPES } from '../types'

const PER_PAGE = 20

const TYPE_TONE = { income: 'income', expense: 'expense', transfer: 'transfer' } as const

/** Reads the filter set out of the URL so a filtered view is linkable. */
function readFilters(params: URLSearchParams): TransactionFilters {
  const number = (key: string) => {
    const value = Number(params.get(key))
    return Number.isFinite(value) && value > 0 ? value : undefined
  }
  const type = params.get('type')

  return {
    search: params.get('search') ?? undefined,
    account_id: number('account_id'),
    category_id: number('category_id'),
    subcategory_id: number('subcategory_id'),
    type: TRANSACTION_TYPES.includes(type as TransactionType) ? (type as TransactionType) : undefined,
    date_from: params.get('date_from') ?? undefined,
    date_to: params.get('date_to') ?? undefined,
    page: number('page') ?? 1,
    per_page: PER_PAGE,
  }
}

export function TransactionsPage() {
  const [params, setParams] = useSearchParams()
  const {
    activeAccounts,
    accounts,
    categories,
    subcategoriesByCategory,
    reload: reloadReference,
  } = useReferenceData()
  const toast = useToast()

  const filters = useMemo(() => readFilters(params), [params])

  // The search box is local and debounced; everything else writes straight to
  // the URL. Only the settled value becomes a request.
  const [searchDraft, setSearchDraft] = useState(filters.search ?? '')
  const debouncedSearch = useDebouncedValue(searchDraft, 350)

  const [editing, setEditing] = useState<Transaction | null>(null)
  const [isCreating, setCreating] = useState(false)
  const [pendingDelete, setPendingDelete] = useState<Transaction | null>(null)
  const [isExporting, setExporting] = useState(false)

  const [isScanOpen, setScanOpen] = useState(false)
  const [isScanning, setScanning] = useState(false)
  const [scanDrafts, setScanDrafts] = useState<BillScanDraft[] | null>(null)
  const fileInputRef = useRef<HTMLInputElement>(null)

  const patchParams = (patch: Record<string, string | undefined>, resetPage = true) => {
    const next = new URLSearchParams(params)
    for (const [key, value] of Object.entries(patch)) {
      if (value === undefined || value === '') next.delete(key)
      else next.set(key, value)
    }
    if (resetPage) next.delete('page')
    setParams(next, { replace: true })
  }

  useEffect(() => {
    if ((filters.search ?? '') === debouncedSearch) return
    patchParams({ search: debouncedSearch || undefined })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch])

  const { data, isLoading, isRefreshing, error, reload } = useAsync(
    (signal) => transactionsApi.list(filters, signal),
    [
      filters.search,
      filters.account_id,
      filters.category_id,
      filters.subcategory_id,
      filters.type,
      filters.date_from,
      filters.date_to,
      filters.page,
    ],
  )

  const transactions = data?.data ?? []
  const meta = data?.meta
  const hasFilters = Boolean(
    filters.search ||
      filters.account_id ||
      filters.category_id ||
      filters.subcategory_id ||
      filters.type ||
      filters.date_from ||
      filters.date_to,
  )
  const filterSubcategories = subcategoriesByCategory(filters.category_id)

  const afterWrite = () => {
    setEditing(null)
    setCreating(false)
    reload()
    // Balances move with the ledger, so the cached account list is now stale.
    reloadReference()
  }

  const save = async (input: TransactionInput) => {
    if (editing) {
      await transactionsApi.update(editing.id, input)
      toast.success('Transaction updated.')
    } else {
      await transactionsApi.create(input)
      toast.success('Transaction added.')
    }
    afterWrite()
  }

  const confirmDelete = async () => {
    if (!pendingDelete) return
    try {
      await transactionsApi.remove(pendingDelete.id)
      toast.success('Transaction deleted.')
      setPendingDelete(null)
      afterWrite()
    } catch (cause) {
      toast.error(cause)
    }
  }

  const exportCsv = async () => {
    setExporting(true)
    try {
      // Exports whatever is currently filtered, not just the page on screen.
      await transactionsApi.exportCsv(filters)
      toast.success('Export downloaded.')
    } catch (cause) {
      toast.error(cause)
    } finally {
      setExporting(false)
    }
  }

  const closeScan = () => {
    setScanOpen(false)
    setScanDrafts(null)
    if (fileInputRef.current) fileInputRef.current.value = ''
  }

  const pickBillFile = async (file: File | undefined) => {
    if (!file) return
    setScanning(true)
    try {
      const drafts = await billScansApi.scan(file)
      setScanDrafts(drafts)
    } catch (cause) {
      toast.error(cause)
    } finally {
      setScanning(false)
      if (fileInputRef.current) fileInputRef.current.value = ''
    }
  }

  const afterScanSaved = () => {
    closeScan()
    afterWrite()
  }

  if (error && !data) return <ErrorState error={error} onRetry={reload} />

  return (
    <>
      <PageHeader
        title="Transactions"
        description={meta ? `${meta.total} matching ${meta.total === 1 ? 'entry' : 'entries'}` : 'Loading…'}
        actions={
          <>
            <Button onClick={exportCsv} loading={isExporting} disabled={transactions.length === 0 && !hasFilters}>
              Export CSV
            </Button>
            <Button onClick={() => setScanOpen(true)} disabled={activeAccounts.length === 0}>
              Scan bill
            </Button>
            <Button variant="primary" onClick={() => setCreating(true)} disabled={activeAccounts.length === 0}>
              New transaction
            </Button>
          </>
        }
      />

      <Card
        className="filters-card"
        title="Filters"
        actions={
          hasFilters && (
            <Button
              size="sm"
              variant="ghost"
              onClick={() => {
                setSearchDraft('')
                setParams(new URLSearchParams(), { replace: true })
              }}
            >
              Clear all
            </Button>
          )
        }
      >
        <div className="filters">
          <label className="filters__field filters__field--search">
            <span className="filters__label">Search</span>
            <input
              className="input"
              type="search"
              value={searchDraft}
              onChange={(event) => setSearchDraft(event.target.value)}
              placeholder="Description, notes or tags"
            />
          </label>

          <label className="filters__field">
            <span className="filters__label">Account</span>
            <select
              className="select"
              value={params.get('account_id') ?? ''}
              onChange={(event) => patchParams({ account_id: event.target.value })}
            >
              <option value="">All accounts</option>
              {accounts.map((account) => (
                <option key={account.id} value={account.id}>
                  {account.name}
                  {account.archived ? ' (archived)' : ''}
                </option>
              ))}
            </select>
          </label>

          <label className="filters__field">
            <span className="filters__label">Category</span>
            <select
              className="select"
              value={params.get('category_id') ?? ''}
              onChange={(event) => patchParams({ category_id: event.target.value, subcategory_id: undefined })}
            >
              <option value="">All categories</option>
              {categories.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.icon ? `${category.icon} ` : ''}
                  {category.name}
                </option>
              ))}
            </select>
          </label>

          {filterSubcategories.length > 0 && (
            <label className="filters__field">
              <span className="filters__label">Subcategory</span>
              <select
                className="select"
                value={params.get('subcategory_id') ?? ''}
                onChange={(event) => patchParams({ subcategory_id: event.target.value })}
              >
                <option value="">All subcategories</option>
                {filterSubcategories.map((subcategory) => (
                  <option key={subcategory.id} value={subcategory.id}>
                    {subcategory.name}
                  </option>
                ))}
              </select>
            </label>
          )}

          <label className="filters__field">
            <span className="filters__label">Type</span>
            <select
              className="select"
              value={params.get('type') ?? ''}
              onChange={(event) => patchParams({ type: event.target.value })}
            >
              <option value="">All types</option>
              {TRANSACTION_TYPES.map((type) => (
                <option key={type} value={type}>
                  {type[0].toUpperCase() + type.slice(1)}
                </option>
              ))}
            </select>
          </label>

          <label className="filters__field">
            <span className="filters__label">From</span>
            <input
              className="input"
              type="date"
              value={params.get('date_from') ?? ''}
              onChange={(event) => patchParams({ date_from: event.target.value })}
            />
          </label>

          <label className="filters__field">
            <span className="filters__label">To</span>
            <input
              className="input"
              type="date"
              value={params.get('date_to') ?? ''}
              onChange={(event) => patchParams({ date_to: event.target.value })}
            />
          </label>
        </div>
      </Card>

      <Card
        className="transactions-card"
        bodyClassName="card__body--flush"
        title="Ledger"
        actions={isRefreshing ? <Spinner label="Refreshing" /> : undefined}
      >
        {isLoading ? (
          <div className="card__body">
            <SkeletonRows rows={6} />
          </div>
        ) : transactions.length === 0 ? (
          <EmptyState
            icon="🧾"
            title={hasFilters ? 'Nothing matches those filters' : 'No transactions yet'}
            message={
              hasFilters
                ? 'Try widening the date range or clearing a filter.'
                : 'Record what you earn and spend, and the dashboard, budgets and reports all fill in from here.'
            }
            action={
              hasFilters ? (
                <Button
                  onClick={() => {
                    setSearchDraft('')
                    setParams(new URLSearchParams(), { replace: true })
                  }}
                >
                  Clear filters
                </Button>
              ) : (
                <Button variant="primary" onClick={() => setCreating(true)} disabled={activeAccounts.length === 0}>
                  {activeAccounts.length === 0 ? 'Add an account first' : 'Add your first transaction'}
                </Button>
              )
            }
          />
        ) : (
          <>
            <div className="table-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Description</th>
                    <th scope="col">Category</th>
                    <th scope="col">Account</th>
                    <th scope="col">Type</th>
                    <th scope="col" className="cell--amount">
                      Amount
                    </th>
                    <th scope="col" className="cell--actions">
                      <span className="visually-hidden">Actions</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {transactions.map((transaction) => (
                    <tr key={transaction.id}>
                      <td className="text-muted">{formatDate(transaction.transaction_date)}</td>
                      <td className="cell--wrap">
                        <strong>{transaction.description}</strong>
                        {transaction.tags.length > 0 && (
                          <span className="tags">
                            {transaction.tags.map((tag) => (
                              <span key={tag} className="tag">
                                {tag}
                              </span>
                            ))}
                          </span>
                        )}
                      </td>
                      <td>
                        {transaction.category_name ? (
                          <>
                            <CategoryChip
                              name={transaction.category_name}
                              icon={transaction.category_icon}
                              color={transaction.category_color}
                            />
                            {transaction.subcategory_name && (
                              <div className="chip__subcategory">{transaction.subcategory_name}</div>
                            )}
                          </>
                        ) : (
                          <span className="text-subtle">—</span>
                        )}
                      </td>
                      <td className="text-muted">
                        {transaction.account_name}
                        {transaction.transfer_to_account_name && (
                          <>
                            {' '}
                            <span aria-label="to">→</span> {transaction.transfer_to_account_name}
                          </>
                        )}
                      </td>
                      <td>
                        <Badge tone={TYPE_TONE[transaction.type]}>{transaction.type}</Badge>
                      </td>
                      <td
                        className={`cell--amount ${
                          transaction.type === 'income'
                            ? 'text-positive'
                            : transaction.type === 'expense'
                              ? 'text-negative'
                              : ''
                        }`.trim()}
                      >
                        {transaction.type === 'income' ? '+' : transaction.type === 'expense' ? '−' : ''}
                        {formatCents(transaction.amount_cents)}
                      </td>
                      <td className="cell--actions">
                        <span className="row-actions">
                          <Button size="sm" variant="ghost" onClick={() => setEditing(transaction)}>
                            Edit
                          </Button>
                          <Button size="sm" variant="ghost" onClick={() => setPendingDelete(transaction)}>
                            Delete
                          </Button>
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {meta && meta.total_pages > 1 && (
              <nav className="pagination" aria-label="Pagination">
                <Button
                  size="sm"
                  disabled={meta.page <= 1}
                  onClick={() => patchParams({ page: String(meta.page - 1) }, false)}
                >
                  Previous
                </Button>
                <span className="pagination__status">
                  Page {meta.page} of {meta.total_pages}
                </span>
                <Button
                  size="sm"
                  disabled={meta.page >= meta.total_pages}
                  onClick={() => patchParams({ page: String(meta.page + 1) }, false)}
                >
                  Next
                </Button>
              </nav>
            )}
          </>
        )}
      </Card>

      <Modal
        open={isCreating || editing !== null}
        size="lg"
        title={editing ? 'Edit transaction' : 'New transaction'}
        onClose={() => {
          setCreating(false)
          setEditing(null)
        }}
      >
        <TransactionForm
          key={editing?.id ?? 'new'}
          transaction={editing ?? undefined}
          defaultType={filters.type ?? 'expense'}
          onSubmit={save}
          onCancel={() => {
            setCreating(false)
            setEditing(null)
          }}
        />
      </Modal>

      <Modal open={isScanOpen} size="lg" title="Scan a bill" onClose={closeScan}>
        {scanDrafts === null ? (
          <div className="form">
            <p className="text-muted">
              Upload a photo of a receipt, or a PDF of one or more bills or a statement. Each bill found becomes an
              editable draft transaction you can review before saving.
            </p>
            <input
              ref={fileInputRef}
              type="file"
              accept="image/jpeg,image/png,image/webp,application/pdf"
              disabled={isScanning}
              onChange={(event) => {
                void pickBillFile(event.target.files?.[0])
              }}
            />
            {isScanning && (
              <p className="text-muted">
                <Spinner label="Reading the file" /> Reading the file — this can take a little while for a
                multi-page PDF.
              </p>
            )}
            <div className="form__actions">
              <Button variant="ghost" onClick={closeScan} disabled={isScanning}>
                Cancel
              </Button>
            </div>
          </div>
        ) : (
          <BillScanReview drafts={scanDrafts} onSaved={afterScanSaved} onCancel={closeScan} />
        )}
      </Modal>

      <ConfirmDialog
        open={pendingDelete !== null}
        title="Delete transaction"
        message={
          <>
            Delete <strong>{pendingDelete?.description}</strong> for{' '}
            {pendingDelete ? formatCents(pendingDelete.amount_cents) : ''}? Account balances will be recalculated
            without it. This cannot be undone.
          </>
        }
        onConfirm={confirmDelete}
        onCancel={() => setPendingDelete(null)}
      />
    </>
  )
}
