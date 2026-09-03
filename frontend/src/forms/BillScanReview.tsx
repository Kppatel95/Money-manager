import { useState } from 'react'
import { transactionsApi } from '../api/resources'
import { ApiError } from '../api/client'
import { Button } from '../components/ui/Button'
import { ErrorBanner } from '../components/ui/States'
import { useReferenceData } from '../context/ReferenceDataContext'
import { useToast } from '../context/ToastContext'
import type { BillScanDraft, TransactionInput, TransactionType } from '../types'

interface DraftRow {
  key: number
  type: Extract<TransactionType, 'income' | 'expense'>
  account_id: string
  category_id: string
  subcategory_id: string
  amount: string
  transaction_date: string
  payment_method: string
  description: string
  notes: string
  /** Field => message, from the last failed save attempt on this row. */
  fieldErrors: Record<string, string>
}

let nextKey = 1

function toRow(draft: BillScanDraft, defaultAccountId: string): DraftRow {
  return {
    key: nextKey++,
    type: draft.type,
    account_id: defaultAccountId,
    category_id: draft.category_id ? String(draft.category_id) : '',
    subcategory_id: draft.subcategory_id ? String(draft.subcategory_id) : '',
    amount: draft.amount,
    transaction_date: draft.transaction_date.slice(0, 10),
    payment_method: draft.payment_method ?? '',
    description: draft.description,
    notes: draft.notes ?? '',
    fieldErrors: {},
  }
}

function toInput(row: DraftRow): TransactionInput {
  return {
    type: row.type,
    account_id: Number(row.account_id),
    category_id: row.category_id ? Number(row.category_id) : null,
    subcategory_id: row.subcategory_id ? Number(row.subcategory_id) : null,
    amount: row.amount,
    description: row.description.trim() || 'Scanned bill',
    notes: row.notes.trim() || null,
    transaction_date: row.transaction_date,
    payment_method: row.payment_method.trim() || null,
  }
}

interface BillScanReviewProps {
  drafts: BillScanDraft[]
  onSaved: () => void
  onCancel: () => void
}

/**
 * One editable row per bill the scan found. Nothing is saved until "Save
 * all" -- each row is then submitted through the normal transaction-create
 * endpoint, one request per row, so a bad row can fail and be fixed without
 * losing the rest.
 */
export function BillScanReview({ drafts, onSaved, onCancel }: BillScanReviewProps) {
  const { activeAccounts, expenseCategories, incomeCategories, subcategoriesByCategory } = useReferenceData()
  const toast = useToast()
  const defaultAccountId = activeAccounts[0] ? String(activeAccounts[0].id) : ''

  const [rows, setRows] = useState<DraftRow[]>(() => drafts.map((draft) => toRow(draft, defaultAccountId)))
  const [isSaving, setSaving] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  const updateRow = (key: number, patch: Partial<DraftRow>) => {
    setRows((current) => current.map((row) => (row.key === key ? { ...row, ...patch } : row)))
  }

  const removeRow = (key: number) => {
    setRows((current) => current.filter((row) => row.key !== key))
  }

  const changeType = (key: number, type: DraftRow['type']) => {
    // A category picked for the old type is meaningless for the new one.
    updateRow(key, { type, category_id: '', subcategory_id: '' })
  }

  // A subcategory from the old category would not belong to the new one.
  const changeCategory = (key: number, categoryId: string) => {
    updateRow(key, { category_id: categoryId, subcategory_id: '' })
  }

  const save = async () => {
    setFormError(null)

    if (rows.length === 0) {
      onSaved()
      return
    }

    setSaving(true)
    const results = await Promise.allSettled(rows.map((row) => transactionsApi.create(toInput(row))))
    setSaving(false)

    const failed: DraftRow[] = []
    let succeeded = 0

    results.forEach((result, index) => {
      const row = rows[index]
      if (result.status === 'fulfilled') {
        succeeded += 1
        return
      }

      const fieldErrors = result.reason instanceof ApiError ? result.reason.details : {}
      failed.push({ ...row, fieldErrors })
    })

    if (succeeded > 0) {
      toast.success(`${succeeded} ${succeeded === 1 ? 'transaction' : 'transactions'} added.`)
    }

    if (failed.length === 0) {
      onSaved()
      return
    }

    setRows(failed)
    setFormError(
      succeeded > 0
        ? `${failed.length} of ${results.length} could not be saved. Fix the highlighted fields below and try again.`
        : `Could not save ${failed.length === 1 ? 'this transaction' : 'these transactions'}. Fix the highlighted fields below and try again.`,
    )
  }

  if (rows.length === 0) {
    return (
      <div className="form">
        <p className="text-muted">No bills were found in that file.</p>
        <div className="form__actions">
          <Button variant="primary" onClick={onCancel}>
            Close
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="form">
      {formError && <ErrorBanner message={formError} />}

      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th scope="col">Type</th>
              <th scope="col">Account</th>
              <th scope="col">Category</th>
              <th scope="col">Subcategory</th>
              <th scope="col">Amount</th>
              <th scope="col">Date</th>
              <th scope="col">Payment method</th>
              <th scope="col">Description</th>
              <th scope="col">
                <span className="visually-hidden">Remove</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              const categories = row.type === 'income' ? incomeCategories : expenseCategories
              const subcategories = subcategoriesByCategory(row.category_id ? Number(row.category_id) : null)
              const err = row.fieldErrors

              return (
                <tr key={row.key}>
                  <td>
                    <select
                      className="select"
                      value={row.type}
                      onChange={(event) => changeType(row.key, event.target.value as DraftRow['type'])}
                    >
                      <option value="expense">Expense</option>
                      <option value="income">Income</option>
                    </select>
                  </td>
                  <td>
                    <select
                      className={`select ${err.account_id ? 'field--invalid' : ''}`.trim()}
                      value={row.account_id}
                      onChange={(event) => updateRow(row.key, { account_id: event.target.value })}
                    >
                      <option value="">Select…</option>
                      {activeAccounts.map((account) => (
                        <option key={account.id} value={account.id}>
                          {account.name}
                        </option>
                      ))}
                    </select>
                    {err.account_id && <p className="field__error">{err.account_id}</p>}
                  </td>
                  <td>
                    <select
                      className={`select ${err.category_id ? 'field--invalid' : ''}`.trim()}
                      value={row.category_id}
                      onChange={(event) => changeCategory(row.key, event.target.value)}
                    >
                      <option value="">Uncategorised</option>
                      {categories.map((category) => (
                        <option key={category.id} value={category.id}>
                          {category.icon ? `${category.icon} ` : ''}
                          {category.name}
                        </option>
                      ))}
                    </select>
                    {err.category_id && <p className="field__error">{err.category_id}</p>}
                  </td>
                  <td>
                    <select
                      className={`select ${err.subcategory_id ? 'field--invalid' : ''}`.trim()}
                      value={row.subcategory_id}
                      disabled={subcategories.length === 0}
                      onChange={(event) => updateRow(row.key, { subcategory_id: event.target.value })}
                    >
                      <option value="">No subcategory</option>
                      {subcategories.map((subcategory) => (
                        <option key={subcategory.id} value={subcategory.id}>
                          {subcategory.name}
                        </option>
                      ))}
                    </select>
                    {err.subcategory_id && <p className="field__error">{err.subcategory_id}</p>}
                  </td>
                  <td>
                    <input
                      className={`input ${err.amount ? 'field--invalid' : ''}`.trim()}
                      inputMode="decimal"
                      value={row.amount}
                      onChange={(event) => updateRow(row.key, { amount: event.target.value })}
                    />
                    {err.amount && <p className="field__error">{err.amount}</p>}
                  </td>
                  <td>
                    <input
                      className={`input ${err.transaction_date ? 'field--invalid' : ''}`.trim()}
                      type="date"
                      value={row.transaction_date}
                      onChange={(event) => updateRow(row.key, { transaction_date: event.target.value })}
                    />
                    {err.transaction_date && <p className="field__error">{err.transaction_date}</p>}
                  </td>
                  <td>
                    <input
                      className="input"
                      value={row.payment_method}
                      onChange={(event) => updateRow(row.key, { payment_method: event.target.value })}
                    />
                  </td>
                  <td>
                    <input
                      className={`input ${err.description ? 'field--invalid' : ''}`.trim()}
                      value={row.description}
                      onChange={(event) => updateRow(row.key, { description: event.target.value })}
                    />
                    {err.description && <p className="field__error">{err.description}</p>}
                  </td>
                  <td className="cell--actions">
                    <Button size="sm" variant="ghost" onClick={() => removeRow(row.key)} disabled={isSaving}>
                      Remove
                    </Button>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      <div className="form__actions">
        <Button variant="ghost" onClick={onCancel} disabled={isSaving}>
          Cancel
        </Button>
        <Button
          type="button"
          variant="primary"
          loading={isSaving}
          disabled={activeAccounts.length === 0}
          onClick={() => void save()}
        >
          Save all ({rows.length})
        </Button>
      </div>
    </div>
  )
}
