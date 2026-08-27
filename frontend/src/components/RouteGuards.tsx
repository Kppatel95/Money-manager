import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { LoadingState } from './ui/States'

/**
 * Gate for the signed-in routes.
 *
 * While a stored session is being verified nothing is rendered but a spinner,
 * so a valid session never flashes the login screen on reload. The attempted
 * location rides along in router state, and `<GuestOnly>` sends the user back
 * there after signing in.
 */
export function RequireAuth({ children }: { children: ReactNode }) {
  const { isAuthenticated, isRestoring } = useAuth()
  const location = useLocation()

  if (isRestoring) return <LoadingState label="Restoring your session…" />
  if (!isAuthenticated) return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />

  return <>{children}</>
}

/** Keeps a signed-in user off the login and register screens. */
export function GuestOnly({ children }: { children: ReactNode }) {
  const { isAuthenticated, isRestoring } = useAuth()
  const location = useLocation()
  const from = (location.state as { from?: string } | null)?.from

  if (isRestoring) return <LoadingState label="Restoring your session…" />
  if (isAuthenticated) return <Navigate to={from ?? '/'} replace />

  return <>{children}</>
}
