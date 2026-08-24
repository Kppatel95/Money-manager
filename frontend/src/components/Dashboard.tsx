import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { useAuth } from '../context/AuthContext'
import { expensesApi } from '../api/client'
import type { CategorySummary, Expense, ExpenseFilters, ExpenseInput } from '../types'
import { ExpenseForm } from './ExpenseForm'
import { ExpenseList } from './ExpenseList'

export function Dashboard() {
  const { user, logout } = useAuth()

  const [expenses, setExpenses] = useState<Expense[]>([])
  const [summary, setSummary] = useState<CategorySummary[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const [categoryFilter, setCategoryFilter] = useState('')
  const [fromFilter, setFromFilter] = useState('')
  const [toFilter, setToFilter] = useState('')

  const loadData = useCallback(async (filters: ExpenseFilters) => {
    setLoading(true)
    setError(null)
    try {
      const [expensesRes, summaryRes] = await Promise.all([
        expensesApi.list(filters),
        expensesApi.summary(),
      ])
      setExpenses(expensesRes.data)
      setSummary(summaryRes.data)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not load your expenses.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadData({})
  }, [loadData])

  function applyFilters(e: FormEvent) {
    e.preventDefault()
    loadData({
      category: categoryFilter || undefined,
      from: fromFilter || undefined,
      to: toFilter || undefined,
    })
  }

  function clearFilters() {
    setCategoryFilter('')
    setFromFilter('')
    setToFilter('')
    loadData({})
  }

  async function handleCreate(input: ExpenseInput) {
    await expensesApi.create(input)
    await loadData({
      category: categoryFilter || undefined,
      from: fromFilter || undefined,
      to: toFilter || undefined,
    })
  }

  async function handleUpdate(id: number, input: ExpenseInput) {
    await expensesApi.update(id, input)
    await loadData({
      category: categoryFilter || undefined,
      from: fromFilter || undefined,
      to: toFilter || undefined,
    })
  }

  async function handleDelete(id: number) {
    await expensesApi.remove(id)
    await loadData({
      category: categoryFilter || undefined,
      from: fromFilter || undefined,
      to: toFilter || undefined,
    })
  }

  const categories = Array.from(new Set(summary.map((row) => row.category))).sort()

  return (
    <div className="dashboard">
      <header className="dashboard-header">
        <div>
          <h1 className="brand">Expense Tracker</h1>
          <p className="tagline">Welcome back, {user?.name}.</p>
        </div>
        <button type="button" className="secondary" onClick={logout}>
          Log out
        </button>
      </header>

      {error && <p className="form-error">{error}</p>}

      <div className="dashboard-grid">
        <section className="panel">
          <h2>Add an expense</h2>
          <ExpenseForm onSubmit={handleCreate} />
        </section>

        <section className="panel panel-wide">
          <div className="panel-header-row">
            <h2>Expenses</h2>
            <form className="filters" onSubmit={applyFilters}>
              <select value={categoryFilter} onChange={(e) => setCategoryFilter(e.target.value)}>
                <option value="">All categories</option>
                {categories.map((c) => (
                  <option key={c} value={c}>
                    {c}
                  </option>
                ))}
              </select>
              <input
                type="date"
                value={fromFilter}
                onChange={(e) => setFromFilter(e.target.value)}
                aria-label="From date"
              />
              <input
                type="date"
                value={toFilter}
                onChange={(e) => setToFilter(e.target.value)}
                aria-label="To date"
              />
              <button type="submit" className="secondary">
                Filter
              </button>
              <button type="button" className="link-button" onClick={clearFilters}>
                Clear
              </button>
            </form>
          </div>

          <ExpenseList
            expenses={expenses}
            loading={loading}
            onUpdate={handleUpdate}
            onDelete={handleDelete}
          />
        </section>
      </div>
    </div>
  )
}
