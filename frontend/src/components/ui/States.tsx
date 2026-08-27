import type { ReactNode } from 'react'
import { errorMessage } from '../../api/client'
import { Button } from './Button'

/** Indeterminate spinner. */
export function Spinner({ label = 'Loading' }: { label?: string }) {
  return <span className="spinner" role="status" aria-label={label} />
}

/** Full-panel loading state, used while a page's first fetch is in flight. */
export function LoadingState({ label = 'Loading…' }: { label?: string }) {
  return (
    <div className="state state--loading">
      <Spinner />
      <p>{label}</p>
    </div>
  )
}

/**
 * Shimmering placeholder rows. Preferred over a spinner where the shape of the
 * result is already known, because the layout does not jump when data lands.
 */
export function SkeletonRows({ rows = 4, className = '' }: { rows?: number; className?: string }) {
  return (
    <div className={`skeleton ${className}`.trim()} aria-hidden="true">
      {Array.from({ length: rows }, (_, index) => (
        <div key={index} className="skeleton__row" />
      ))}
    </div>
  )
}

interface EmptyStateProps {
  icon?: ReactNode
  title: string
  message?: ReactNode
  action?: ReactNode
}

export function EmptyState({ icon, title, message, action }: EmptyStateProps) {
  return (
    <div className="state state--empty">
      {icon && (
        <span className="state__icon" aria-hidden="true">
          {icon}
        </span>
      )}
      <h3 className="state__title">{title}</h3>
      {message && <p className="state__message">{message}</p>}
      {action && <div className="state__action">{action}</div>}
    </div>
  )
}

interface ErrorStateProps {
  error: unknown
  onRetry?: () => void
}

/** Inline error panel with a retry, for a page-level fetch that failed. */
export function ErrorState({ error, onRetry }: ErrorStateProps) {
  return (
    <div className="state state--error" role="alert">
      <span className="state__icon" aria-hidden="true">
        !
      </span>
      <h3 className="state__title">Something went wrong</h3>
      <p className="state__message">{errorMessage(error)}</p>
      {onRetry && (
        <div className="state__action">
          <Button variant="secondary" onClick={onRetry}>
            Try again
          </Button>
        </div>
      )}
    </div>
  )
}

/** Compact banner for a non-fatal error, e.g. a form submission that failed. */
export function ErrorBanner({ message }: { message: string }) {
  return (
    <p className="banner banner--error" role="alert">
      {message}
    </p>
  )
}
