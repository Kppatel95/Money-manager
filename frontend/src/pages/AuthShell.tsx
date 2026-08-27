import type { ReactNode } from 'react'
import { ThemeToggle } from '../components/layout/ThemeToggle'

interface AuthShellProps {
  title: string
  subtitle: string
  children: ReactNode
  footer: ReactNode
}

/**
 * Two-column signed-out layout: the form on the left, a product panel on the
 * right that collapses away below 860px.
 */
export function AuthShell({ title, subtitle, children, footer }: AuthShellProps) {
  return (
    <div className="auth">
      <div className="auth__panel">
        <div className="auth__panel-inner">
          <div className="auth__brand">
            <span className="sidebar__mark" aria-hidden="true">
              ₽
            </span>
            <span className="sidebar__wordmark">Ledger</span>
          </div>

          <div className="auth__form-head">
            <h1>{title}</h1>
            <p>{subtitle}</p>
          </div>

          {children}

          <p className="auth__footer">{footer}</p>
        </div>

        <div className="auth__toggle">
          <ThemeToggle />
        </div>
      </div>

      <aside className="auth__aside" aria-hidden="true">
        <div className="auth__aside-inner">
          <p className="auth__quote">Every account, every category, one ledger.</p>
          <ul className="auth__points">
            <li>Track cash, bank, card, wallet and savings accounts side by side.</li>
            <li>Categorise income and expenses, and move money between accounts.</li>
            <li>Set monthly budgets and watch the spend against them.</li>
            <li>Schedule the bills that repeat and let them post themselves.</li>
          </ul>
        </div>
      </aside>
    </div>
  )
}
