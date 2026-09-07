import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router-dom'
import { authApi } from '../api/resources'
import { errorMessage } from '../api/client'
import { Button } from '../components/ui/Button'
import { Field } from '../components/ui/Field'
import { ErrorBanner } from '../components/ui/States'
import { forgotPasswordSchema, type ForgotPasswordFormValues } from '../lib/schemas'
import { AuthShell } from './AuthShell'

export function ForgotPasswordPage() {
  const [formError, setFormError] = useState<string | null>(null)
  const [submitted, setSubmitted] = useState(false)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<ForgotPasswordFormValues>({
    resolver: zodResolver(forgotPasswordSchema),
    defaultValues: { email: '' },
  })

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await authApi.forgotPassword(values)
      // The API answers the same way whether or not the email is registered,
      // so the UI must too -- this is not a "your email was found" message.
      setSubmitted(true)
    } catch (error) {
      setFormError(errorMessage(error))
    }
  })

  return (
    <AuthShell
      title="Reset your password"
      subtitle="We'll email you a link to choose a new one."
      footer={<>Remembered it after all? <Link to="/login">Sign in</Link></>}
    >
      {submitted ? (
        <div className="form">
          <p>
            If an account exists for that email, we've sent a link to reset the password. It expires in an hour.
          </p>
        </div>
      ) : (
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

          <Button type="submit" variant="primary" loading={isSubmitting} className="btn--block">
            Send reset link
          </Button>
        </form>
      )}
    </AuthShell>
  )
}
