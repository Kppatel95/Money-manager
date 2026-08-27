import type { ReactNode } from 'react'

interface CardProps {
  title?: ReactNode
  subtitle?: ReactNode
  /** Rendered on the right of the header — filters, buttons, a legend. */
  actions?: ReactNode
  className?: string
  bodyClassName?: string
  children: ReactNode
}

export function Card({ title, subtitle, actions, className = '', bodyClassName = '', children }: CardProps) {
  return (
    <section className={`card ${className}`.trim()}>
      {(title || actions) && (
        <header className="card__header">
          <div className="card__titles">
            {title && <h2 className="card__title">{title}</h2>}
            {subtitle && <p className="card__subtitle">{subtitle}</p>}
          </div>
          {actions && <div className="card__actions">{actions}</div>}
        </header>
      )}
      <div className={`card__body ${bodyClassName}`.trim()}>{children}</div>
    </section>
  )
}
