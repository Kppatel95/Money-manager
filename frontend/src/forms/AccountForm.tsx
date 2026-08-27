import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { errorMessage } from '../api/client'
import { Button } from '../components/ui/Button'
import { Field, FieldRow } from '../components/ui/Field'
import { ErrorBanner } from '../components/ui/States'
import { applyApiFieldErrors } from '../lib/forms'
import { centsToDecimalString } from '../lib/money'
import { accountSchema, type AccountFormValues } from '../lib/schemas'
import { ACCOUNT_TYPES, type Account, type AccountInput } from '../types'

const FIELDS = ['name', 'type', 'initial_balance', 'currency'] as const

const TYPE_LABELS: Record<(typeof ACCOUNT_TYPES)[number], string> = {
  cash: 'Cash',
  bank: 'Bank account',
  card: 'Credit card',
  wallet: 'Wallet',
  savings: 'Savings',
}

interface AccountFormProps {
  /** Present when editing; absent when creating. */
  account?: Account
  onSubmit: (input: AccountInput) => Promise<void>
  onCancel: () => void
}

export function AccountForm({ account, onSubmit, onCancel }: AccountFormProps) {
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<AccountFormValues>({
    resolver: zodResolver(accountSchema),
    defaultValues: {
      name: account?.name ?? '',
      type: account?.type ?? 'bank',
      initial_balance: account ? centsToDecimalString(account.initial_balance_cents) : '0.00',
      currency: account?.currency ?? 'USD',
    },
  })

  const submit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await onSubmit({
        name: values.name.trim(),
        type: values.type,
        initial_balance: values.initial_balance,
        currency: values.currency.toUpperCase(),
      })
    } catch (error) {
      if (applyApiFieldErrors(error, setError, FIELDS)) return
      setFormError(errorMessage(error))
    }
  })

  return (
    <form className="form" onSubmit={submit} noValidate id="account-form">
      {formError && <ErrorBanner message={formError} />}

      <Field label="Name" error={errors.name?.message} required>
        {(props) => <input {...props} {...register('name')} className="input" placeholder="Everyday current account" />}
      </Field>

      <FieldRow>
        <Field label="Type" error={errors.type?.message} required>
          {(props) => (
            <select {...props} {...register('type')} className="select">
              {ACCOUNT_TYPES.map((type) => (
                <option key={type} value={type}>
                  {TYPE_LABELS[type]}
                </option>
              ))}
            </select>
          )}
        </Field>

        <Field label="Currency" error={errors.currency?.message} hint="Three-letter code." required>
          {(props) => (
            <input {...props} {...register('currency')} className="input" maxLength={3} placeholder="USD" />
          )}
        </Field>
      </FieldRow>

      <Field
        label="Opening balance"
        error={errors.initial_balance?.message}
        hint={
          account
            ? 'Changing this shifts the account balance by the same amount.'
            : 'What the account held before you started tracking it. May be negative.'
        }
        required
      >
        {(props) => (
          <input
            {...props}
            {...register('initial_balance')}
            className="input"
            inputMode="decimal"
            placeholder="0.00"
          />
        )}
      </Field>

      <div className="form__actions">
        <Button variant="ghost" onClick={onCancel} disabled={isSubmitting}>
          Cancel
        </Button>
        <Button type="submit" variant="primary" loading={isSubmitting}>
          {account ? 'Save changes' : 'Add account'}
        </Button>
      </div>
    </form>
  )
}
