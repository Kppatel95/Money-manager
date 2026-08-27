import type { ButtonHTMLAttributes, ReactNode } from 'react'

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger'
type Size = 'sm' | 'md'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant
  size?: Size
  /** Shows a spinner and blocks clicks. */
  loading?: boolean
  iconLeft?: ReactNode
}

export function Button({
  variant = 'secondary',
  size = 'md',
  loading = false,
  iconLeft,
  className = '',
  disabled,
  children,
  type = 'button',
  ...rest
}: ButtonProps) {
  return (
    <button
      {...rest}
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={`btn btn--${variant} btn--${size} ${className}`.trim()}
    >
      {loading ? <span className="btn__spinner" aria-hidden="true" /> : iconLeft}
      <span>{children}</span>
    </button>
  )
}
