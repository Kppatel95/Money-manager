import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router-dom'
import { ApiError, errorMessage } from '../api/client'
import { Button } from '../components/ui/Button'
import { Field } from '../components/ui/Field'
import { ErrorBanner } from '../components/ui/States'
import { useAuth } from '../context/AuthContext'
import { loginSchema, type LoginFormValues } from '../lib/schemas'
import { AuthShell } from './AuthShell'

export function LoginPage() {
  const { login } = useAuth()
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: '', password: '' },
  })

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await login(values)
      // The route guard notices the session and redirects; nothing to do here.
    } catch (error) {
      // The API rate-limits an email after five failed attempts; that message
      // is far more useful than a generic "invalid credentials".
      if (error instanceof ApiError && error.code === 'RATE_LIMITED') {
        const minutes = Math.ceil((error.retryAfter ?? 900) / 60)
        setFormError(`Too many failed attempts. Try again in about ${minutes} minute${minutes === 1 ? '' : 's'}.`)
        return
      }
      setFormError(errorMessage(error))
    }
  })

  return (
    <AuthShell
      title="Welcome back"
      subtitle="Sign in to pick up where you left off."
      footer={<>New here? <Link to="/register">Create an account</Link></>}
    >
      <form className="form" onSubmit={onSubmit} noValidate>
        {formError && <ErrorBanner message={formError} />}

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

        <Field label="Password" error={errors.password?.message} required>
          {(props) => (
            <input
              {...props}
              {...register('password')}
              className="input"
              type="password"
              autoComplete="current-password"
              placeholder="••••••••"
            />
          )}
        </Field>

        <Button type="submit" variant="primary" loading={isSubmitting} className="btn--block">
          Sign in
        </Button>
      </form>
    </AuthShell>
  )
}
