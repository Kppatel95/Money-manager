import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { authApi } from '../api/resources'
import { errorMessage } from '../api/client'
import { Button } from '../components/ui/Button'
import { Field } from '../components/ui/Field'
import { ErrorBanner } from '../components/ui/States'
import { useToast } from '../context/ToastContext'
import { resetPasswordSchema, type ResetPasswordFormValues } from '../lib/schemas'
import { AuthShell } from './AuthShell'

export function ResetPasswordPage() {
  const [params] = useSearchParams()
  const token = params.get('token')
  const navigate = useNavigate()
  const toast = useToast()
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<ResetPasswordFormValues>({
    resolver: zodResolver(resetPasswordSchema),
    defaultValues: { password: '', confirmPassword: '' },
  })

  const onSubmit = handleSubmit(async (values) => {
    if (!token) return
    setFormError(null)
    try {
      await authApi.resetPassword({ token, password: values.password })
      toast.success('Password changed. Sign in with your new password.')
      navigate('/login', { replace: true })
    } catch (error) {
      setFormError(errorMessage(error))
    }
  })

  if (!token) {
    return (
      <AuthShell
        title="Reset your password"
        subtitle="This link is missing its token."
        footer={<>Need a new link? <Link to="/forgot-password">Request one</Link></>}
      >
        <ErrorBanner message="This reset link looks incomplete. Request a new one below." />
      </AuthShell>
    )
  }

  return (
    <AuthShell
      title="Choose a new password"
      subtitle="Pick something you haven't used before."
      footer={<>Link expired or not working? <Link to="/forgot-password">Request a new one</Link></>}
    >
      <form className="form" onSubmit={onSubmit} noValidate>
        {formError && <ErrorBanner message={formError} />}

        <Field label="New password" error={errors.password?.message} hint="At least 8 characters." required>
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

        <Field label="Confirm new password" error={errors.confirmPassword?.message} required>
          {(props) => (
            <input
              {...props}
              {...register('confirmPassword')}
              className="input"
              type="password"
              autoComplete="new-password"
              placeholder="••••••••"
            />
          )}
        </Field>

        <Button type="submit" variant="primary" loading={isSubmitting} className="btn--block">
          Reset password
        </Button>
      </form>
    </AuthShell>
  )
}
