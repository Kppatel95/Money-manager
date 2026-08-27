import { useToast } from '../../context/ToastContext'

const ICONS: Record<string, string> = {
  success: '✓',
  error: '!',
  info: 'i',
}

/**
 * Renders the toast queue. Lives once, at the root, so any component can raise
 * a message through `useToast()` without owning any UI of its own.
 */
export function Toaster() {
  const { toasts, dismiss } = useToast()

  if (toasts.length === 0) return null

  return (
    <div className="toaster" role="region" aria-label="Notifications">
      {toasts.map((toast) => (
        <output key={toast.id} className={`toast toast--${toast.tone}`} aria-live="polite">
          <span className="toast__icon" aria-hidden="true">
            {ICONS[toast.tone]}
          </span>
          <span className="toast__message">{toast.message}</span>
          <button type="button" className="toast__close" onClick={() => dismiss(toast.id)} aria-label="Dismiss">
            &times;
          </button>
        </output>
      ))}
    </div>
  )
}
