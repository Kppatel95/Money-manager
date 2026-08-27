import type { ReactNode } from 'react'
import { formatCents } from '../lib/money'
import type { Budget } from '../types'
import { CategoryChip } from './ui/Badge'
import { ProgressBar } from './ui/ProgressBar'

interface BudgetProgressRowProps {
  budget: Budget
  currency: string
  /** Edit/delete controls, shown only on the Budgets page. */
  actions?: ReactNode
}

/**
 * One budget line: category, limit, spend and the bar between them.
 *
 * Shared by the dashboard and the budgets page so a budget looks the same
 * wherever it appears; only the trailing controls differ.
 */
export function BudgetProgressRow({ budget, currency, actions }: BudgetProgressRowProps) {
  const remaining = budget.remaining_cents

  return (
    <li className="budget-row">
      <div className="budget-row__head">
        <CategoryChip name={budget.category_name} icon={budget.category_icon} color={budget.category_color} />
        <span className="budget-row__figures numeric">
          <strong>{formatCents(budget.spent_cents, currency)}</strong>
          <span className="text-muted"> of {formatCents(budget.amount_limit_cents, currency)}</span>
        </span>
        {actions}
      </div>

      <ProgressBar percent={budget.percent_used} label={`${budget.category_name} budget`} />

      <p className="budget-row__foot">
        <span className={budget.over_budget ? 'text-negative' : 'text-muted'}>
          {budget.over_budget
            ? `${formatCents(Math.abs(remaining), currency)} over budget`
            : `${formatCents(remaining, currency)} left`}
        </span>
        <span className="text-muted numeric">{Math.round(budget.percent_used)}% used</span>
      </p>
    </li>
  )
}
