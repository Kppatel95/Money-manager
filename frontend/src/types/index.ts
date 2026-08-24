export interface User {
  id: number
  name: string
  email: string
}

export interface Expense {
  id: number
  amount: number
  category: string
  description: string | null
  expense_date: string
  created_at: string
  updated_at: string
}

export interface ExpenseInput {
  amount: number
  category: string
  description?: string
  expense_date: string
}

export interface CategorySummary {
  category: string
  total: number
  count: number
}

export interface SummaryResponse {
  data: CategorySummary[]
  total: number
}

export interface ExpenseFilters {
  category?: string
  from?: string
  to?: string
}
