import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { errorMessage } from '../api/client'
import { Button } from '../components/ui/Button'
import { Field, FieldRow } from '../components/ui/Field'
import { ErrorBanner } from '../components/ui/States'
import { useReferenceData } from '../context/ReferenceDataContext'
import { applyApiFieldErrors } from '../lib/forms'
import { centsToDecimalString } from '../lib/money'
import { budgetSchema, type BudgetFormValues } from '../lib/schemas'
import type { Budget, BudgetInput } from '../types'

const FIELDS = ['category_id', 'month', 'amount_limit'] as const

interface BudgetFormProps {
  budget?: Budget
  month: string
  /** Categories already budgeted this month — one budget per category. */
  takenCategoryIds: number[]
  onSubmit: (input: BudgetInput) => Promise<void>
  onCancel: () => void
}

export function BudgetForm({ budget, month, takenCategoryIds, onSubmit, onCancel }: BudgetFormProps) {
  const { expenseCategories } = useReferenceData()
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<BudgetFormValues>({
    resolver: zodResolver(budgetSchema),
    defaultValues: {
      category_id: budget ? String(budget.category_id) : '',
      month: budget?.month ?? month,
      amount_limit: budget ? centsToDecimalString(budget.amount_limit_cents) : '',
    },
  })

  // Offering a category that already has a budget would only earn a 409, so
  // the taken ones are filtered out (except the one being edited).
  const available = expenseCategories.filter(
    (category) => category.id === budget?.category_id || !takenCategoryIds.includes(category.id),
  )

  const submit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await onSubmit({
        category_id: Number(values.category_id),
        month: values.month,
        amount_limit: values.amount_limit,
      })
    } catch (error) {
      if (applyApiFieldErrors(error, setError, FIELDS)) return
      setFormError(errorMessage(error))
    }
  })

  return (
    <form className="form" onSubmit={submit} noValidate>
      {formError && <ErrorBanner message={formError} />}

      <Field
        label="Category"
        error={errors.category_id?.message}
        hint={budget ? undefined : 'Only expense categories can be budgeted.'}
        required
      >
        {(props) => (
          <select {...props} {...register('category_id')} className="select" disabled={Boolean(budget)}>
            <option value="">Select a category…</option>
            {available.map((category) => (
              <option key={category.id} value={category.id}>
                {category.icon ? `${category.icon} ` : ''}
                {category.name}
              </option>
            ))}
          </select>
        )}
      </Field>

      <FieldRow>
        <Field label="Month" error={errors.month?.message} required>
          {(props) => <input {...props} {...register('month')} className="input" type="month" />}
        </Field>

        <Field label="Monthly limit" error={errors.amount_limit?.message} required>
          {(props) => (
            <input {...props} {...register('amount_limit')} className="input" inputMode="decimal" placeholder="400.00" />
          )}
        </Field>
      </FieldRow>

      <div className="form__actions">
        <Button variant="ghost" onClick={onCancel} disabled={isSubmitting}>
          Cancel
        </Button>
        <Button type="submit" variant="primary" loading={isSubmitting}>
          {budget ? 'Save changes' : 'Set budget'}
        </Button>
      </div>
    </form>
  )
}
