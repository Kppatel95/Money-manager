import { download, request } from './client'
import type {
  Account,
  AccountBalance,
  AccountInput,
  Budget,
  BudgetInput,
  BudgetMeta,
  Category,
  CategoryInput,
  CategoryType,
  DashboardSummary,
  Envelope,
  LoginInput,
  PaginatedEnvelope,
  RecurringTransaction,
  RecurringTransactionInput,
  RegisterInput,
  Session,
  Transaction,
  TransactionFilters,
  TransactionInput,
  User,
} from '../types'

/**
 * One function per endpoint, each returning the unwrapped payload.
 *
 * Callers never see the `{data: ...}` envelope or build a URL, so a change to
 * either shape lands here and nowhere else.
 */

const unwrap = <T>(envelope: Envelope<T>): T => envelope.data

/* -------------------------------------------------------------------------- */

export const authApi = {
  async register(input: RegisterInput): Promise<Session> {
    return unwrap(await request<Envelope<Session>>('/auth/register', { method: 'POST', body: input }))
  },

  async login(input: LoginInput): Promise<Session> {
    return unwrap(await request<Envelope<Session>>('/auth/login', { method: 'POST', body: input }))
  },

  async me(signal?: AbortSignal): Promise<User> {
    return unwrap(await request<Envelope<User>>('/auth/me', { signal }))
  },

  async logout(refreshToken: string): Promise<void> {
    await request<void>('/auth/logout', { method: 'POST', body: { refresh_token: refreshToken } })
  },
}

/* -------------------------------------------------------------------------- */

export const accountsApi = {
  async list(includeArchived = false, signal?: AbortSignal): Promise<Account[]> {
    return unwrap(
      await request<Envelope<Account[]>>('/accounts', {
        query: { include_archived: includeArchived ? 'true' : undefined },
        signal,
      }),
    )
  },

  async create(input: AccountInput): Promise<Account> {
    return unwrap(await request<Envelope<Account>>('/accounts', { method: 'POST', body: input }))
  },

  async update(id: number, input: Partial<AccountInput>): Promise<Account> {
    return unwrap(await request<Envelope<Account>>(`/accounts/${id}`, { method: 'PUT', body: input }))
  },

  /** Archives instead of deleting when the account has transaction history. */
  async remove(id: number): Promise<void> {
    await request<void>(`/accounts/${id}`, { method: 'DELETE' })
  },

  async balance(id: number, signal?: AbortSignal): Promise<AccountBalance> {
    return unwrap(await request<Envelope<AccountBalance>>(`/accounts/${id}/balance`, { signal }))
  },
}

/* -------------------------------------------------------------------------- */

export const categoriesApi = {
  async list(type?: CategoryType, signal?: AbortSignal): Promise<Category[]> {
    return unwrap(await request<Envelope<Category[]>>('/categories', { query: { type }, signal }))
  },

  async create(input: CategoryInput): Promise<Category> {
    return unwrap(await request<Envelope<Category>>('/categories', { method: 'POST', body: input }))
  },

  async update(id: number, input: Partial<CategoryInput>): Promise<Category> {
    return unwrap(await request<Envelope<Category>>(`/categories/${id}`, { method: 'PUT', body: input }))
  },

  async remove(id: number): Promise<void> {
    await request<void>(`/categories/${id}`, { method: 'DELETE' })
  },
}

/* -------------------------------------------------------------------------- */

const transactionQuery = (filters: TransactionFilters) => ({
  account_id: filters.account_id,
  category_id: filters.category_id,
  type: filters.type,
  date_from: filters.date_from,
  date_to: filters.date_to,
  search: filters.search,
  page: filters.page,
  per_page: filters.per_page,
})

export const transactionsApi = {
  list(filters: TransactionFilters = {}, signal?: AbortSignal): Promise<PaginatedEnvelope<Transaction>> {
    return request<PaginatedEnvelope<Transaction>>('/transactions', { query: transactionQuery(filters), signal })
  },

  async get(id: number, signal?: AbortSignal): Promise<Transaction> {
    return unwrap(await request<Envelope<Transaction>>(`/transactions/${id}`, { signal }))
  },

  async create(input: TransactionInput): Promise<Transaction> {
    return unwrap(await request<Envelope<Transaction>>('/transactions', { method: 'POST', body: input }))
  },

  async update(id: number, input: Partial<TransactionInput>): Promise<Transaction> {
    return unwrap(await request<Envelope<Transaction>>(`/transactions/${id}`, { method: 'PUT', body: input }))
  },

  async remove(id: number): Promise<void> {
    await request<void>(`/transactions/${id}`, { method: 'DELETE' })
  },

  /** Streams the filtered set to the browser as a CSV download. */
  exportCsv(filters: TransactionFilters = {}): Promise<void> {
    const { page: _page, per_page: _perPage, ...rest } = filters
    return download('/transactions/export', { query: transactionQuery(rest) }, 'transactions.csv')
  },
}

/* -------------------------------------------------------------------------- */

export interface BudgetsPage {
  data: Budget[]
  meta: BudgetMeta
}

export const budgetsApi = {
  list(month: string, signal?: AbortSignal): Promise<BudgetsPage> {
    return request<BudgetsPage>('/budgets', { query: { month }, signal })
  },

  async create(input: BudgetInput): Promise<Budget> {
    return unwrap(await request<Envelope<Budget>>('/budgets', { method: 'POST', body: input }))
  },

  async update(id: number, input: Partial<BudgetInput>): Promise<Budget> {
    return unwrap(await request<Envelope<Budget>>(`/budgets/${id}`, { method: 'PUT', body: input }))
  },

  async remove(id: number): Promise<void> {
    await request<void>(`/budgets/${id}`, { method: 'DELETE' })
  },
}

/* -------------------------------------------------------------------------- */

export const recurringApi = {
  async list(signal?: AbortSignal): Promise<RecurringTransaction[]> {
    return unwrap(await request<Envelope<RecurringTransaction[]>>('/recurring-transactions', { signal }))
  },

  async create(input: RecurringTransactionInput): Promise<RecurringTransaction> {
    return unwrap(
      await request<Envelope<RecurringTransaction>>('/recurring-transactions', { method: 'POST', body: input }),
    )
  },

  async update(id: number, input: Partial<RecurringTransactionInput>): Promise<RecurringTransaction> {
    return unwrap(
      await request<Envelope<RecurringTransaction>>(`/recurring-transactions/${id}`, { method: 'PUT', body: input }),
    )
  },

  async remove(id: number): Promise<void> {
    await request<void>(`/recurring-transactions/${id}`, { method: 'DELETE' })
  },
}

/* -------------------------------------------------------------------------- */

export const dashboardApi = {
  async summary(month?: string, signal?: AbortSignal): Promise<DashboardSummary> {
    return unwrap(await request<Envelope<DashboardSummary>>('/dashboard/summary', { query: { month }, signal }))
  },
}
