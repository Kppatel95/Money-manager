import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { authApi } from '../api/resources'
import { tokenStore } from '../api/tokens'
import type { LoginInput, RegisterInput, User } from '../types'

interface AuthContextValue {
  user: User | null
  isAuthenticated: boolean
  /** True until the stored session has been checked against the API. */
  isRestoring: boolean
  login: (input: LoginInput) => Promise<void>
  register: (input: RegisterInput) => Promise<void>
  logout: () => Promise<void>
  refreshUser: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(() => tokenStore.user())
  const [isRestoring, setRestoring] = useState<boolean>(() => tokenStore.accessToken() !== null)

  /**
   * The API client clears the store when a refresh fails. Subscribing here is
   * what turns that into a re-render, so a session that dies mid-session (or in
   * another tab) drops the user back to the login screen without any page
   * needing to know about it.
   */
  useEffect(() => tokenStore.subscribe(() => setUser(tokenStore.user())), [])

  /**
   * A stored token may already be dead. Confirming it once on boot means the
   * shell never renders a signed-in UI for a session the API has forgotten —
   * and it warms the recurring-transaction catch-up the backend runs on the
   * first authenticated request.
   */
  useEffect(() => {
    if (!tokenStore.accessToken()) return undefined

    const controller = new AbortController()
    authApi
      .me(controller.signal)
      .then((fresh) => tokenStore.saveUser(fresh))
      .catch(() => {
        /* SessionExpiredError already cleared the store */
      })
      .finally(() => setRestoring(false))

    return () => controller.abort()
  }, [])

  const login = useCallback(async (input: LoginInput) => {
    tokenStore.save(await authApi.login(input))
  }, [])

  const register = useCallback(async (input: RegisterInput) => {
    tokenStore.save(await authApi.register(input))
  }, [])

  const logout = useCallback(async () => {
    const refreshToken = tokenStore.refreshToken()
    try {
      // Best effort: revoke server-side so the refresh token cannot be replayed.
      if (refreshToken) await authApi.logout(refreshToken)
    } catch {
      /* offline, or the token was already revoked — clear locally regardless */
    } finally {
      tokenStore.clear()
    }
  }, [])

  const refreshUser = useCallback(async () => {
    tokenStore.saveUser(await authApi.me())
  }, [])

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      isAuthenticated: user !== null,
      isRestoring,
      login,
      register,
      logout,
      refreshUser,
    }),
    [user, isRestoring, login, register, logout, refreshUser],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used inside an <AuthProvider>')
  return context
}
