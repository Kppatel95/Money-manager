import { useState } from 'react'
import { authApi } from '../api/resources'
import { Badge } from '../components/ui/Badge'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { ConfirmDialog } from '../components/ui/ConfirmDialog'
import { PageHeader } from '../components/ui/PageHeader'
import { ErrorState, SkeletonRows } from '../components/ui/States'
import { useAuth } from '../context/AuthContext'
import { useReferenceData } from '../context/ReferenceDataContext'
import { useTheme, type ThemePreference } from '../context/ThemeContext'
import { useToast } from '../context/ToastContext'
import { useAsync } from '../hooks/useAsync'
import { formatDate } from '../lib/dates'

const THEME_OPTIONS: { id: ThemePreference; label: string }[] = [
  { id: 'light', label: 'Light' },
  { id: 'dark', label: 'Dark' },
  { id: 'system', label: 'System' },
]

const API_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000'

export function SettingsPage() {
  const { logout } = useAuth()
  const { accounts, categories } = useReferenceData()
  const { preference, setPreference } = useTheme()
  const toast = useToast()

  const [isSigningOut, setSigningOut] = useState(false)
  const [confirmSignOut, setConfirmSignOut] = useState(false)

  // Read straight from /auth/me rather than the cached copy, so this page
  // always shows what the API believes about the session.
  const { data: user, isLoading, error, reload } = useAsync((signal) => authApi.me(signal), [])

  const signOut = async () => {
    setSigningOut(true)
    try {
      await logout()
      toast.success('Signed out.')
    } finally {
      setSigningOut(false)
      setConfirmSignOut(false)
    }
  }

  const systemCategories = categories.filter((category) => category.is_system).length

  return (
    <>
      <PageHeader title="Settings" description="Your account and how this app is configured." />

      <div className="stack settings">
        <Card title="Profile">
          {isLoading ? (
            <SkeletonRows rows={3} />
          ) : error ? (
            <ErrorState error={error} onRetry={reload} />
          ) : (
            <dl className="definition-list">
              <div>
                <dt>Name</dt>
                <dd>{user!.name}</dd>
              </div>
              <div>
                <dt>Email</dt>
                <dd>{user!.email}</dd>
              </div>
              <div>
                <dt>Member since</dt>
                <dd>{formatDate(user!.created_at)}</dd>
              </div>
              <div>
                <dt>User ID</dt>
                <dd className="numeric">#{user!.id}</dd>
              </div>
            </dl>
          )}
        </Card>

        <Card title="Appearance" subtitle="Remembered in this browser.">
          <div className="segmented segmented--inline" role="group" aria-label="Theme">
            {THEME_OPTIONS.map((option) => (
              <button
                key={option.id}
                type="button"
                className={`segmented__option ${preference === option.id ? 'segmented__option--active' : ''}`.trim()}
                aria-pressed={preference === option.id}
                onClick={() => setPreference(option.id)}
              >
                {option.label}
              </button>
            ))}
          </div>
        </Card>

        <Card title="Workspace">
          <dl className="definition-list">
            <div>
              <dt>Accounts</dt>
              <dd>
                {accounts.filter((account) => !account.archived).length} active
                {accounts.some((account) => account.archived) &&
                  `, ${accounts.filter((account) => account.archived).length} archived`}
              </dd>
            </div>
            <div>
              <dt>Categories</dt>
              <dd>
                {categories.length} total <Badge tone="neutral">{systemCategories} system</Badge>
              </dd>
            </div>
            <div>
              <dt>API</dt>
              <dd className="numeric">{API_URL}/api/v1</dd>
            </div>
          </dl>
        </Card>

        <Card title="Session" subtitle="Signing out revokes the refresh token on the server.">
          <Button variant="danger" onClick={() => setConfirmSignOut(true)} loading={isSigningOut}>
            Sign out
          </Button>
        </Card>
      </div>

      <ConfirmDialog
        open={confirmSignOut}
        title="Sign out"
        confirmLabel="Sign out"
        tone="primary"
        message="You'll need to sign in again to get back to your ledger."
        onConfirm={signOut}
        onCancel={() => setConfirmSignOut(false)}
      />
    </>
  )
}
