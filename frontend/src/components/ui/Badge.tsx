import type { ReactNode } from 'react'

type Tone = 'neutral' | 'income' | 'expense' | 'transfer' | 'muted' | 'warn'

export function Badge({ tone = 'neutral', children }: { tone?: Tone; children: ReactNode }) {
  return <span className={`badge badge--${tone}`}>{children}</span>
}

/** Category chip, tinted with the category's own colour from the API. */
export function CategoryChip({
  name,
  icon,
  color,
}: {
  name: string
  icon?: string | null
  color?: string | null
}) {
  return (
    <span className="chip" style={color ? { '--chip-color': color } as React.CSSProperties : undefined}>
      {icon && (
        <span className="chip__icon" aria-hidden="true">
          {icon}
        </span>
      )}
      {name}
    </span>
  )
}
