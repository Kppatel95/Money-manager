import type { ReactNode } from 'react'

export interface NavItem {
  to: string
  label: string
  icon: ReactNode
  /** Match this path exactly rather than by prefix. */
  end?: boolean
}

/**
 * Inline SVG icons rather than an icon dependency: eight glyphs is not worth a
 * package, and `currentColor` lets them inherit the active-link colour.
 */
const icon = (path: ReactNode) => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    {path}
  </svg>
)

export const NAV_ITEMS: NavItem[] = [
  {
    to: '/',
    label: 'Dashboard',
    end: true,
    icon: icon(
      <>
        <rect x="3" y="3" width="7" height="9" rx="1.5" />
        <rect x="14" y="3" width="7" height="5" rx="1.5" />
        <rect x="14" y="12" width="7" height="9" rx="1.5" />
        <rect x="3" y="16" width="7" height="5" rx="1.5" />
      </>,
    ),
  },
  {
    to: '/accounts',
    label: 'Accounts',
    icon: icon(
      <>
        <rect x="2.5" y="6" width="19" height="13" rx="2.5" />
        <path d="M2.5 10.5h19" />
        <path d="M6.5 15h4" />
      </>,
    ),
  },
  {
    to: '/transactions',
    label: 'Transactions',
    icon: icon(
      <>
        <path d="M4 8h13l-3-3" />
        <path d="M20 16H7l3 3" />
      </>,
    ),
  },
  {
    to: '/budgets',
    label: 'Budgets',
    icon: icon(
      <>
        <circle cx="12" cy="12" r="8.5" />
        <path d="M12 3.5v8.5l6 4.2" />
      </>,
    ),
  },
  {
    to: '/recurring',
    label: 'Recurring',
    icon: icon(
      <>
        <path d="M3.5 12a8.5 8.5 0 0 1 14.6-5.9" />
        <path d="M20.5 12a8.5 8.5 0 0 1-14.6 5.9" />
        <path d="M18.5 2.5v4h-4" />
        <path d="M5.5 21.5v-4h4" />
      </>,
    ),
  },
  {
    to: '/reports',
    label: 'Reports',
    icon: icon(
      <>
        <path d="M4 20V10" />
        <path d="M10 20V4" />
        <path d="M16 20v-7" />
        <path d="M22 20H2" />
      </>,
    ),
  },
  {
    to: '/settings',
    label: 'Settings',
    icon: icon(
      <>
        <circle cx="12" cy="12" r="3.2" />
        <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-2.9-1.2l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15H4.5a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.2-2.9l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 11.5 4.6V4.5a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.9 1.2l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.7 1.7 0 0 0 1.2 2.9h.1a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.03.37Z" />
      </>,
    ),
  },
]
