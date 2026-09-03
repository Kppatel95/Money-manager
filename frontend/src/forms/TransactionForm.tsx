import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { errorMessage } from '../api/client'
import { Button } from '../components/ui/Button'
import { Field, FieldRow } from '../components/ui/Field'
import { ErrorBanner } from '../components/ui/States'
import { useReferenceData } from '../context/ReferenceDataContext'
import { todayIso } from '../lib/dates'
import { applyApiFieldErrors } from '../lib/forms'
import { centsToDecimalString } from '../lib/money'
import { parseTags, transactionSchema, type TransactionFormValues } from '../lib/schemas'
import { TRANSACTION_TYPES, type Transaction, type TransactionInput, type TransactionType } from '../types'

const FIELDS = [
  'type',
  'account_id',
  'category_id',
  'subcategory_id',
  'transfer_to_account_id',
  'amount',
  'description',
  'notes',
  'tags',
  'transaction_date',
  'payment_method',
] as const

const TYPE_LABELS: Record<TransactionType, string> = {
  expense: 'Expense',
  income: 'Income',
  transfer: 'Transfer',
}

interface TransactionFormProps {
  transaction?: Transaction
  /** Pre-selects the type when adding from a filtered view. */
  defaultType?: TransactionType
  onSubmit: (input: TransactionInput) => Promise<void>
  onCancel: () => void
}

/**
 * The most involved form in the app, and the reason `react-hook-form` and `zod`
 * are dependencies rather than hand-rolled state.
 *
 * A transaction changes shape with its type: income and expense need a
 * category, a transfer needs a destination account and must have no category at
 * all. That is a cross-field rule, so it lives in the schema's `superRefine`
 * rather than in the JSX, and the type picker only has to swap which control is
 * rendered.
 */
export function TransactionForm({ transaction, defaultType = 'expense', onSubmit, onCancel }: TransactionFormProps) {
  const { activeAccounts, expenseCategories, incomeCategories, subcategoriesByCategory } = useReferenceData()
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    watch,
    setValue,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<TransactionFormValues>({
    resolver: zodResolver(transactionSchema),
    defaultValues: {
      type: transaction?.type ?? defaultType,
      account_id: transaction ? String(transaction.account_id) : String(activeAccounts[0]?.id ?? ''),
      category_id: transaction?.category_id ? String(transaction.category_id) : '',
      subcategory_id: transaction?.subcategory_id ? String(transaction.subcategory_id) : '',
      transfer_to_account_id: transaction?.transfer_to_account_id ? String(transaction.transfer_to_account_id) : '',
      amount: transaction ? centsToDecimalString(transaction.amount_cents) : '',
      description: transaction?.description ?? '',
      notes: transaction?.notes ?? '',
      tags: transaction?.tags.join(', ') ?? '',
      transaction_date: transaction?.transaction_date.slice(0, 10) ?? todayIso(),
      payment_method: transaction?.payment_method ?? '',
    },
  })

  const type = watch('type')
  const accountId = watch('account_id')
  const categoryId = watch('category_id')
  const categories = type === 'income' ? incomeCategories : expenseCategories
  const subcategories = subcategoriesByCategory(categoryId ? Number(categoryId) : null)

  const chooseType = (next: TransactionType) => {
    setValue('type', next)
    // The two branches are mutually exclusive server-side, so clear whichever
    // field the new type forbids instead of sending a value that would 422.
    if (next === 'transfer') setValue('category_id', '')
    else setValue('transfer_to_account_id', '')
    // An expense category is meaningless on an income row and vice versa.
    if (next !== type) setValue('category_id', '')
    setValue('subcategory_id', '')
  }

  // A subcategory from the old category would not belong to the new one.
  const clearSubcategory = () => setValue('subcategory_id', '')

  const submit = handleSubmit(async (values) => {
    setFormError(null)
    const isTransfer = values.type === 'transfer'

    try {
      await onSubmit({
        type: values.type,
        account_id: Number(values.account_id),
        category_id: isTransfer ? null : Number(values.category_id),
        subcategory_id: isTransfer || !values.subcategory_id ? null : Number(values.subcategory_id),
        transfer_to_account_id: isTransfer ? Number(values.transfer_to_account_id) : null,
        amount: values.amount,
        description: values.description.trim(),
        notes: values.notes.trim() || null,
        tags: parseTags(values.tags),
        transaction_date: values.transaction_date,
        payment_method: values.payment_method.trim() || null,
      })
    } catch (error) {
      if (applyApiFieldErrors(error, setError, FIELDS)) return
      setFormError(errorMessage(error))
    }
  })

  return (
    <form className="form" onSubmit={submit} noValidate>
      {formError && <ErrorBanner message={formError} />}

      <div className="segmented" role="group" aria-label="Transaction type">
        {TRANSACTION_TYPES.map((option) => (
          <button
            key={option}
            type="button"
            className={`segmented__option ${type === option ? 'segmented__option--active' : ''}`.trim()}
            aria-pressed={type === option}
            onClick={() => chooseType(option)}
          >
            {TYPE_LABELS[option]}
          </button>
        ))}
      </div>
      {/* The picker above drives this; registering it keeps the value in the
          form state that the resolver validates. */}
      <input type="hidden" {...register('type')} />

      <FieldRow>
        <Field label={type === 'transfer' ? 'From account' : 'Account'} error={errors.account_id?.message} required>
          {(props) => (
            <select {...props} {...register('account_id')} className="select">
              <option value="">Select an account…</option>
              {activeAccounts.map((account) => (
                <option key={account.id} value={account.id}>
                  {account.name}
                </option>
              ))}
            </select>
          )}
        </Field>

        {type === 'transfer' ? (
          <Field label="To account" error={errors.transfer_to_account_id?.message} required>
            {(props) => (
              <select {...props} {...register('transfer_to_account_id')} className="select">
                <option value="">Select an account…</option>
                {activeAccounts
                  .filter((account) => String(account.id) !== accountId)
                  .map((account) => (
                    <option key={account.id} value={account.id}>
                      {account.name}
                    </option>
                  ))}
              </select>
            )}
          </Field>
        ) : (
          <Field label="Category" error={errors.category_id?.message} required>
            {(props) => (
              <select
                {...props}
                {...register('category_id', { onChange: clearSubcategory })}
                className="select"
              >
                <option value="">Select a category…</option>
                {categories.map((category) => (
                  <option key={category.id} value={category.id}>
                    {category.icon ? `${category.icon} ` : ''}
                    {category.name}
                  </option>
                ))}
              </select>
            )}
          </Field>
        )}
      </FieldRow>

      {type !== 'transfer' && subcategories.length > 0 && (
        <FieldRow>
          <Field label="Subcategory" error={errors.subcategory_id?.message} hint="Optional.">
            {(props) => (
              <select {...props} {...register('subcategory_id')} className="select">
                <option value="">No subcategory</option>
                {subcategories.map((subcategory) => (
                  <option key={subcategory.id} value={subcategory.id}>
                    {subcategory.name}
                  </option>
                ))}
              </select>
            )}
          </Field>
        </FieldRow>
      )}

      <FieldRow>
        <Field label="Amount" error={errors.amount?.message} required>
          {(props) => (
            <input {...props} {...register('amount')} className="input" inputMode="decimal" placeholder="42.55" />
          )}
        </Field>

        <Field label="Date" error={errors.transaction_date?.message} required>
          {(props) => <input {...props} {...register('transaction_date')} className="input" type="date" />}
        </Field>
      </FieldRow>

      <Field label="Description" error={errors.description?.message} required>
        {(props) => (
          <input {...props} {...register('description')} className="input" placeholder="Weekly groceries" />
        )}
      </Field>

      <Field label="Payment method" error={errors.payment_method?.message} hint="Optional.">
        {(props) => (
          <input {...props} {...register('payment_method')} className="input" placeholder="Credit Card" />
        )}
      </Field>

      <Field label="Tags" error={errors.tags?.message} hint="Comma separated, optional.">
        {(props) => <input {...props} {...register('tags')} className="input" placeholder="groceries, weekly" />}
      </Field>

      <Field label="Notes" error={errors.notes?.message}>
        {(props) => <textarea {...props} {...register('notes')} className="textarea" rows={3} />}
      </Field>

      <div className="form__actions">
        <Button variant="ghost" onClick={onCancel} disabled={isSubmitting}>
          Cancel
        </Button>
        <Button type="submit" variant="primary" loading={isSubmitting}>
          {transaction ? 'Save changes' : 'Add transaction'}
        </Button>
      </div>
    </form>
  )
}
