import { createContext, useCallback, useContext, useMemo, type ReactNode } from 'react'
import { accountsApi, categoriesApi } from '../api/resources'
import { useAsync } from '../hooks/useAsync'
import type { Account, Category } from '../types'

/**
 * Accounts and categories are needed by almost every page — as picker options,
 * and to resolve ids to names and colours. Loading them once at the shell level
 * keeps four pages from each fetching the same two lists on mount.
 */

interface ReferenceDataValue {
  accounts: Account[]
  /** Excludes archived accounts — nothing new should be filed against one. */
  activeAccounts: Account[]
  categories: Category[]
  expenseCategories: Category[]
  incomeCategories: Category[]
  isLoading: boolean
  error: unknown
  /** Call after creating/editing an account or category. */
  reload: () => void
  accountById: (id: number | null | undefined) => Account | undefined
  categoryById: (id: number | null | undefined) => Category | undefined
}

const ReferenceDataContext = createContext<ReferenceDataValue | null>(null)

export function ReferenceDataProvider({ children }: { children: ReactNode }) {
  const state = useAsync(
    async (signal) => ({
      accounts: await accountsApi.list(true, signal),
      categories: await categoriesApi.list(undefined, signal),
    }),
    [],
  )

  const accounts = useMemo(() => state.data?.accounts ?? [], [state.data])
  const categories = useMemo(() => state.data?.categories ?? [], [state.data])

  const accountIndex = useMemo(() => new Map(accounts.map((account) => [account.id, account])), [accounts])
  const categoryIndex = useMemo(() => new Map(categories.map((category) => [category.id, category])), [categories])

  const accountById = useCallback(
    (id: number | null | undefined) => (id == null ? undefined : accountIndex.get(id)),
    [accountIndex],
  )
  const categoryById = useCallback(
    (id: number | null | undefined) => (id == null ? undefined : categoryIndex.get(id)),
    [categoryIndex],
  )

  const value = useMemo<ReferenceDataValue>(
    () => ({
      accounts,
      activeAccounts: accounts.filter((account) => !account.archived),
      categories,
      expenseCategories: categories.filter((category) => category.type === 'expense'),
      incomeCategories: categories.filter((category) => category.type === 'income'),
      isLoading: state.isLoading,
      error: state.error,
      reload: state.reload,
      accountById,
      categoryById,
    }),
    [accounts, categories, state.isLoading, state.error, state.reload, accountById, categoryById],
  )

  return <ReferenceDataContext.Provider value={value}>{children}</ReferenceDataContext.Provider>
}

export function useReferenceData(): ReferenceDataValue {
  const context = useContext(ReferenceDataContext)
  if (!context) throw new Error('useReferenceData must be used inside a <ReferenceDataProvider>')
  return context
}
