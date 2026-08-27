import { useId, type ReactNode } from 'react'

interface FieldProps {
  label: ReactNode
  /** Validation message; presence switches the field into its error style. */
  error?: string
  hint?: ReactNode
  required?: boolean
  className?: string
  /** Receives the generated id and the aria wiring to spread onto the control. */
  children: (props: { id: string; 'aria-invalid': boolean; 'aria-describedby': string | undefined }) => ReactNode
}

/**
 * Label + control + message, with the accessibility wiring done once.
 *
 * The control is a render prop rather than a `<Field>`-owned `<input>` because
 * the forms need selects, textareas, radio groups and native date inputs, and
 * `react-hook-form`'s `register()` spread has to land on the real element.
 */
export function Field({ label, error, hint, required, className = '', children }: FieldProps) {
  const id = useId()
  const messageId = error ? `${id}-error` : hint ? `${id}-hint` : undefined

  return (
    <div className={`field ${error ? 'field--invalid' : ''} ${className}`.trim()}>
      <label className="field__label" htmlFor={id}>
        {label}
        {required && (
          <span className="field__required" aria-hidden="true">
            *
          </span>
        )}
      </label>

      {children({ id, 'aria-invalid': Boolean(error), 'aria-describedby': messageId })}

      {error ? (
        <p className="field__error" id={messageId} role="alert">
          {error}
        </p>
      ) : (
        hint && (
          <p className="field__hint" id={messageId}>
            {hint}
          </p>
        )
      )}
    </div>
  )
}

/** Horizontal group of fields that collapses to one column on narrow screens. */
export function FieldRow({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`field-row ${className}`.trim()}>{children}</div>
}
