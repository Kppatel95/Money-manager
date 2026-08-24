import { useState, type FormEvent } from 'react'
import type { Expense, ExpenseInput } from '../types'

interface ExpenseFormProps {
  initial?: Expense
  onSubmit: (input: ExpenseInput) => Promise<void>
  onCancel?: () => void
}

function todayIsoDate(): string {
  return new Date().toISOString().slice(0, 10)
}

export function ExpenseForm({ initial, onSubmit, onCancel }: ExpenseFormProps) {
  const [amount, setAmount] = useState(initial ? String(initial.amount) : '')
  const [category, setCategory] = useState(initial?.category ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [date, setDate] = useState(initial?.expense_date ?? todayIsoDate())
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setError(null)

    const parsedAmount = Number(amount)
    if (!Number.isFinite(parsedAmount) || parsedAmount <= 0) {
      setError('Enter an amount greater than zero.')
      return
    }
    if (category.trim() === '') {
      setError('Category is required.')
      return
    }

    setSubmitting(true)
    try {
      await onSubmit({
        amount: parsedAmount,
        category: category.trim(),
        description: description.trim() || undefined,
        expense_date: date,
      })
      if (!initial) {
        setAmount('')
        setCategory('')
        setDescription('')
        setDate(todayIsoDate())
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not save the expense.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="expense-form">
      <div className="expense-form-grid">
        <label>
          Amount
          <input
            type="number"
            step="0.01"
            min="0.01"
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            placeholder="0.00"
            required
          />
        </label>

        <label>
          Category
          <input
            type="text"
            value={category}
            onChange={(e) => setCategory(e.target.value)}
            placeholder="Food, Transport, Rent…"
            required
          />
        </label>

        <label>
          Date
          <input type="date" value={date} onChange={(e) => setDate(e.target.value)} required />
        </label>

        <label className="expense-form-description">
          Description (optional)
          <input
            type="text"
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            placeholder="What was this for?"
          />
        </label>
      </div>

      {error && <p className="form-error">{error}</p>}

      <div className="expense-form-actions">
        <button type="submit" className="primary" disabled={submitting}>
          {submitting ? 'Saving…' : initial ? 'Save changes' : 'Add expense'}
        </button>
        {onCancel && (
          <button type="button" className="secondary" onClick={onCancel}>
            Cancel
          </button>
        )}
      </div>
    </form>
  )
}
