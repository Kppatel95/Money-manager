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
import { recurringSchema, type RecurringFormValues } from '../lib/schemas'
import { FREQUENCIES, type Frequency, type RecurringTransaction, type RecurringTransactionInput } from '../types'

const FIELDS = ['type', 'account_id', 'category_id', 'amount', 'description', 'frequency', 'next_run_date'] as const

const FREQUENCY_LABELS: Record<Frequency, string> = {
  daily: 'Every day',
  weekly: 'Every week',
  monthly: 'Every month',
}

interface RecurringFormProps {
  schedule?: RecurringTransaction
  onSubmit: (input: RecurringTransactionInput) => Promise<void>
  onCancel: () => void
}

export function RecurringForm({ schedule, onSubmit, onCancel }: RecurringFormProps) {
  const { activeAccounts, expenseCategories, incomeCategories } = useReferenceData()
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    watch,
    setValue,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<RecurringFormValues>({
    resolver: zodResolver(recurringSchema),
    defaultValues: {
      type: schedule?.type ?? 'expense',
      account_id: schedule ? String(schedule.account_id) : String(activeAccounts[0]?.id ?? ''),
      category_id: schedule?.category_id ? String(schedule.category_id) : '',
      amount: schedule ? centsToDecimalString(schedule.amount_cents) : '',
      description: schedule?.description ?? '',
      frequency: schedule?.frequency ?? 'monthly',
      next_run_date: schedule?.next_run_date.slice(0, 10) ?? todayIso(),
      active: schedule?.active ?? true,
    },
  })

  const type = watch('type')
  const categories = type === 'income' ? incomeCategories : expenseCategories

  const submit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await onSubmit({
        type: values.type,
        account_id: Number(values.account_id),
        category_id: Number(values.category_id),
        amount: values.amount,
        description: values.description.trim(),
        frequency: values.frequency,
        next_run_date: values.next_run_date,
        active: values.active,
      })
    } catch (error) {
      if (applyApiFieldErrors(error, setError, FIELDS)) return
      setFormError(errorMessage(error))
    }
  })

  return (
    <form className="form" onSubmit={submit} noValidate>
      {formError && <ErrorBanner message={formError} />}

      <div className="segmented" role="group" aria-label="Schedule type">
        {(['expense', 'income'] as const).map((option) => (
          <button
            key={option}
            type="button"
            className={`segmented__option ${type === option ? 'segmented__option--active' : ''}`.trim()}
            aria-pressed={type === option}
            onClick={() => {
              setValue('type', option)
              // Income and expense categories are separate lists.
              setValue('category_id', '')
            }}
          >
            {option === 'expense' ? 'Expense' : 'Income'}
          </button>
        ))}
      </div>
      <input type="hidden" {...register('type')} />

      <FieldRow>
        <Field label="Account" error={errors.account_id?.message} required>
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

        <Field label="Category" error={errors.category_id?.message} required>
          {(props) => (
            <select {...props} {...register('category_id')} className="select">
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
      </FieldRow>

      <Field label="Description" error={errors.description?.message} required>
        {(props) => <input {...props} {...register('description')} className="input" placeholder="Rent" />}
      </Field>

      <FieldRow>
        <Field label="Amount" error={errors.amount?.message} required>
          {(props) => (
            <input {...props} {...register('amount')} className="input" inputMode="decimal" placeholder="850.00" />
          )}
        </Field>

        <Field label="Frequency" error={errors.frequency?.message} required>
          {(props) => (
            <select {...props} {...register('frequency')} className="select">
              {FREQUENCIES.map((frequency) => (
                <option key={frequency} value={frequency}>
                  {FREQUENCY_LABELS[frequency]}
                </option>
              ))}
            </select>
          )}
        </Field>
      </FieldRow>

      <Field
        label="Next run"
        error={errors.next_run_date?.message}
        hint="A date in the past is caught up on your next request."
        required
      >
        {(props) => <input {...props} {...register('next_run_date')} className="input" type="date" />}
      </Field>

      <label className="switch">
        <input type="checkbox" {...register('active')} />
        <span>Active — post transactions when this comes due</span>
      </label>

      <div className="form__actions">
        <Button variant="ghost" onClick={onCancel} disabled={isSubmitting}>
          Cancel
        </Button>
        <Button type="submit" variant="primary" loading={isSubmitting}>
          {schedule ? 'Save changes' : 'Create schedule'}
        </Button>
      </div>
    </form>
  )
}
