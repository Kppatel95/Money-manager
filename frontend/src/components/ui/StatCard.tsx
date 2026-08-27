import type { ReactNode } from 'react'

interface StatCardProps {
  label: string
  value: string
  hint?: ReactNode
  /** Tints the value: positive green, negative red, neutral inherits. */
  tone?: 'neutral' | 'positive' | 'negative'
  icon?: ReactNode
}

export function StatCard({ label, value, hint, tone = 'neutral', icon }: StatCardProps) {
  return (
    <div className="stat">
      <div className="stat__head">
        <span className="stat__label">{label}</span>
        {icon && (
          <span className="stat__icon" aria-hidden="true">
            {icon}
          </span>
        )}
      </div>
      <strong className={`stat__value stat__value--${tone}`}>{value}</strong>
      {hint && <span className="stat__hint">{hint}</span>}
    </div>
  )
}
