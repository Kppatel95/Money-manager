/**
 * Wire types for the Personal Finance API (v1).
 *
 * These mirror `backend/openapi.yaml` exactly. Every monetary value arrives as
 * an integer `*_cents` field, which is what the UI reads and does arithmetic
 * on; the matching decimal field is convenience only and is deliberately not
 * used for maths. Writes go the other way and send major-unit strings.
 */

/* -------------------------------------------------------------------------- */
/* Envelopes                                                                  */
/* -------------------------------------------------------------------------- */

export interface Envelope<T> {
  data: T
}

export interface PaginatedEnvelope<T> {
  data: T[]
  meta: PageMeta
}

export interface PageMeta {
  page: number
  per_page: number
  total: number
  total_pages: number
  filters?: Record<string, unknown>
}

export type ApiErrorCode =
  | 'BAD_REQUEST'
  | 'UNAUTHORIZED'
  | 'FORBIDDEN'
  | 'NOT_FOUND'
  | 'METHOD_NOT_ALLOWED'
  | 'CONFLICT'
  | 'VALIDATION_ERROR'
  | 'RATE_LIMITED'
  | 'INTERNAL_ERROR'

export interface ApiErrorBody {
  error: {
    code: ApiErrorCode
    message: string
    details?: Record<string, string>
  }
}

/* -------------------------------------------------------------------------- */
/* Auth                                                                       */
/* -------------------------------------------------------------------------- */

export interface User {
  id: number
  name: string
  email: string
  created_at: string
}

export interface Session {
  user: User
  access_token: string
  refresh_token: string
  token_type: 'Bearer'
  /** Access-token lifetime in seconds. */
  expires_in: number
}

export interface LoginInput {
  email: string
  password: string
}

export interface RegisterInput extends LoginInput {
  name: string
}

/* -------------------------------------------------------------------------- */
/* Accounts                                                                   */
/* -------------------------------------------------------------------------- */

export const ACCOUNT_TYPES = ['cash', 'bank', 'card', 'wallet', 'savings'] as const
export type AccountType = (typeof ACCOUNT_TYPES)[number]

export interface Account {
  id: number
  name: string
  type: AccountType
  currency: string
  initial_balance_cents: number
  initial_balance: number
  /** Derived: opening balance plus every transaction touching the account. */
  balance_cents: number
  balance: number
  archived: boolean
  created_at: string
}

export interface AccountInput {
  name: string
  type: AccountType
  /** Major units, as a string — may be negative. */
  initial_balance?: string
  currency?: string
}

export interface AccountBalance {
  account_id: number
  name: string
  currency: string
  initial_balance_cents: number
  income_cents: number
  expense_cents: number
  transfer_in_cents: number
  transfer_out_cents: number
  balance_cents: number
  balance: number
}

/* -------------------------------------------------------------------------- */
/* Categories                                                                 */
/* -------------------------------------------------------------------------- */

export type CategoryType = 'income' | 'expense'

export interface Category {
  id: number
  name: string
  type: CategoryType
  icon: string | null
  color: string | null
  /** System categories are shared across users and read-only. */
  is_system: boolean
  created_at: string | null
}

export interface CategoryInput {
  name: string
  type: CategoryType
  icon?: string
  color?: string
}

export interface Subcategory {
  id: number
  category_id: number
  name: string
  created_at: string | null
}

/* -------------------------------------------------------------------------- */
/* Transactions                                                               */
/* -------------------------------------------------------------------------- */

export const TRANSACTION_TYPES = ['income', 'expense', 'transfer'] as const
export type TransactionType = (typeof TRANSACTION_TYPES)[number]

export interface Transaction {
  id: number
  type: TransactionType
  amount_cents: number
  amount: number
  account_id: number
  account_name: string
  transfer_to_account_id: number | null
  transfer_to_account_name: string | null
  category_id: number | null
  category_name: string | null
  category_icon: string | null
  category_color: string | null
  subcategory_id: number | null
  subcategory_name: string | null
  description: string
  notes: string | null
  tags: string[]
  transaction_date: string
  payment_method: string | null
  created_at: string
  updated_at: string
}

export interface TransactionInput {
  type: TransactionType
  account_id: number
  /** Required for income/expense, must be null for transfers. */
  category_id?: number | null
  /** Optional; must belong to category_id, and must be null for transfers. */
  subcategory_id?: number | null
  /** Required for transfers only. */
  transfer_to_account_id?: number | null
  /** Major units, always positive, as a string. */
  amount: string
  description: string
  notes?: string | null
  tags?: string[]
  transaction_date: string
  payment_method?: string | null
}

/* -------------------------------------------------------------------------- */
/* Bill scanning                                                             */
/* -------------------------------------------------------------------------- */

/**
 * One transaction extracted from a scanned bill, not yet saved. Missing
 * `account_id` -- only the user can say which of their accounts paid for it.
 */
export interface BillScanDraft {
  type: 'income' | 'expense'
  amount: string
  transaction_date: string
  description: string
  category_id: number | null
  /** Informational only; the id is what gets submitted. */
  category_name: string | null
  subcategory_id: number | null
  /** Informational only; the id is what gets submitted. */
  subcategory_name: string | null
  payment_method: string | null
  notes: string | null
}

export interface TransactionFilters {
  account_id?: number
  category_id?: number
  subcategory_id?: number
  type?: TransactionType
  date_from?: string
  date_to?: string
  search?: string
  page?: number
  per_page?: number
}

/* -------------------------------------------------------------------------- */
/* Budgets                                                                    */
/* -------------------------------------------------------------------------- */

export interface Budget {
  id: number
  category_id: number
  category_name: string
  category_icon: string | null
  category_color: string | null
  /** `YYYY-MM`. */
  month: string
  amount_limit_cents: number
  amount_limit: number
  spent_cents: number
  spent: number
  /** Negative when overspent. */
  remaining_cents: number
  remaining: number
  percent_used: number
  over_budget: boolean
  created_at: string | null
}

export interface BudgetMeta {
  month: string
  total_limit_cents: number
  total_limit: number
  total_spent_cents: number
  total_spent: number
  total_remaining_cents: number
  total_remaining: number
}

export interface BudgetInput {
  category_id: number
  month: string
  /** Major units, as a string. */
  amount_limit: string
}

/* -------------------------------------------------------------------------- */
/* Recurring transactions                                                     */
/* -------------------------------------------------------------------------- */

export const FREQUENCIES = ['daily', 'weekly', 'monthly'] as const
export type Frequency = (typeof FREQUENCIES)[number]

export interface RecurringTransaction {
  id: number
  type: CategoryType
  amount_cents: number
  amount: number
  account_id: number
  account_name: string
  category_id: number | null
  category_name: string | null
  category_icon: string | null
  category_color: string | null
  description: string
  frequency: Frequency
  next_run_date: string
  active: boolean
  created_at: string
}

export interface RecurringTransactionInput {
  type: CategoryType
  account_id: number
  category_id: number
  /** Major units, as a string. */
  amount: string
  description: string
  frequency: Frequency
  next_run_date: string
  active?: boolean
}

/* -------------------------------------------------------------------------- */
/* Dashboard                                                                  */
/* -------------------------------------------------------------------------- */

export interface DashboardTotals {
  income_cents: number
  income: number
  expense_cents: number
  expense: number
  net_cents: number
  net: number
  /** Net as a percentage of income. */
  savings_rate: number
}

export interface CategoryBreakdownSlice {
  category_id: number | null
  name: string
  icon: string | null
  color: string | null
  total_cents: number
  total: number
  transaction_count: number
  percent: number
}

export interface TrendPoint {
  month: string
  income_cents: number
  income: number
  expense_cents: number
  expense: number
  net_cents: number
  net: number
}

export interface DashboardSummary {
  month: string
  net_worth_cents: number
  net_worth: number
  accounts: Account[]
  totals: DashboardTotals
  /** Expense totals per category for the month, largest first. */
  category_breakdown: CategoryBreakdownSlice[]
  /** Six months ending with the requested one, oldest first. */
  trend: TrendPoint[]
  budgets: Budget[]
}
