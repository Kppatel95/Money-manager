import { useState } from 'react'
import type { Expense, ExpenseInput } from '../types'
import { ExpenseForm } from './ExpenseForm'

interface ExpenseListProps {
  expenses: Expense[]
  loading: boolean
  onUpdate: (id: number, input: ExpenseInput) => Promise<void>
  onDelete: (id: number) => Promise<void>
}

const currencyFormatter = new Intl.NumberFormat('en-US', {
  style: 'currency',
  currency: 'USD',
})

export function ExpenseList({ expenses, loading, onUpdate, onDelete }: ExpenseListProps) {
  const [editingId, setEditingId] = useState<number | null>(null)
  const [deletingId, setDeletingId] = useState<number | null>(null)

  async function handleDelete(id: number) {
    if (!window.confirm('Delete this expense? This cannot be undone.')) return
    setDeletingId(id)
    try {
      await onDelete(id)
    } finally {
      setDeletingId(null)
    }
  }

  if (loading) {
    return <p className="empty-state">Loading expenses…</p>
  }

  if (expenses.length === 0) {
    return <p className="empty-state">No expenses match your filters yet.</p>
  }

  return (
    <ul className="expense-list">
      {expenses.map((expense) => (
        <li key={expense.id} className="expense-row">
          {editingId === expense.id ? (
            <ExpenseForm
              initial={expense}
              onCancel={() => setEditingId(null)}
              onSubmit={async (input) => {
                await onUpdate(expense.id, input)
                setEditingId(null)
              }}
            />
          ) : (
            <>
              <div className="expense-main">
                <span className="expense-category-badge">{expense.category}</span>
                <div className="expense-details">
                  <span className="expense-description">
                    {expense.description || <em>No description</em>}
                  </span>
                  <span className="expense-date">{expense.expense_date}</span>
                </div>
              </div>
              <div className="expense-amount">{currencyFormatter.format(expense.amount)}</div>
              <div className="expense-row-actions">
                <button type="button" className="link-button" onClick={() => setEditingId(expense.id)}>
                  Edit
                </button>
                <button
                  type="button"
                  className="link-button danger"
                  onClick={() => handleDelete(expense.id)}
                  disabled={deletingId === expense.id}
                >
                  {deletingId === expense.id ? 'Deleting…' : 'Delete'}
                </button>
              </div>
            </>
          )}
        </li>
      ))}
    </ul>
  )
}
