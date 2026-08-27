import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router-dom'
import { errorMessage } from '../api/client'
import { Button } from '../components/ui/Button'
import { Field } from '../components/ui/Field'
import { ErrorBanner } from '../components/ui/States'
import { useAuth } from '../context/AuthContext'
import { applyApiFieldErrors } from '../lib/forms'
import { registerSchema, type RegisterFormValues } from '../lib/schemas'
import { AuthShell } from './AuthShell'

const FIELDS = ['name', 'email', 'password'] as const

export function RegisterPage() {
  const { register: createAccount } = useAuth()
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<RegisterFormValues>({
    resolver: zodResolver(registerSchema),
    defaultValues: { name: '', email: '', password: '' },
  })

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await createAccount(values)
    } catch (error) {
      // "That email is already registered" belongs on the email field.
      if (applyApiFieldErrors(error, setError, FIELDS)) return
      setFormError(errorMessage(error))
    }
  })

  return (
    <AuthShell
      title="Create your account"
      subtitle="A ledger for every account you actually use."
      footer={<>Already have an account? <Link to="/login">Sign in</Link></>}
    >
      <form className="form" onSubmit={onSubmit} noValidate>
        {formError && <ErrorBanner message={formError} />}

        <Field label="Name" error={errors.name?.message} required>
          {(props) => (
            <input {...props} {...register('name')} className="input" autoComplete="name" placeholder="Ada Lovelace" />
          )}
        </Field>

        <Field label="Email" error={errors.email?.message} required>
          {(props) => (
            <input
              {...props}
              {...register('email')}
              className="input"
              type="email"
              autoComplete="email"
              placeholder="you@example.com"
            />
          )}
        </Field>

        <Field label="Password" error={errors.password?.message} hint="At least 8 characters." required>
          {(props) => (
            <input
              {...props}
              {...register('password')}
              className="input"
              type="password"
              autoComplete="new-password"
              placeholder="••••••••"
            />
          )}
        </Field>

        <Button type="submit" variant="primary" loading={isSubmitting} className="btn--block">
          Create account
        </Button>
      </form>
    </AuthShell>
  )
}
