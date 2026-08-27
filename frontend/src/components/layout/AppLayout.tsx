import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'
import { ReferenceDataProvider } from '../../context/ReferenceDataContext'
import { NAV_ITEMS } from './navigation'
import { ThemeToggle } from './ThemeToggle'

/**
 * The signed-in shell: a persistent sidebar on desktop that becomes an
 * off-canvas drawer below 900px, with the routed page in the main column.
 *
 * `ReferenceDataProvider` sits inside the layout rather than at the root so the
 * accounts/categories fetch only happens once there is a session to fetch with.
 */
export function AppLayout() {
  const { user } = useAuth()
  const location = useLocation()
  const [isDrawerOpen, setDrawerOpen] = useState(false)

  // Navigating on mobile should close the drawer it was navigated from.
  useEffect(() => setDrawerOpen(false), [location.pathname])

  useEffect(() => {
    if (!isDrawerOpen) return undefined
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setDrawerOpen(false)
    }
    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [isDrawerOpen])

  const initials = (user?.name ?? '?')
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('')

  return (
    <ReferenceDataProvider>
      <div className={`shell ${isDrawerOpen ? 'shell--drawer-open' : ''}`.trim()}>
        <button
          type="button"
          className="shell__scrim"
          aria-label="Close navigation"
          tabIndex={isDrawerOpen ? 0 : -1}
          onClick={() => setDrawerOpen(false)}
        />

        <aside className="sidebar" id="app-navigation">
          <div className="sidebar__brand">
            <span className="sidebar__mark" aria-hidden="true">
              ₽
            </span>
            <span className="sidebar__wordmark">Ledger</span>
          </div>

          <nav className="sidebar__nav" aria-label="Main">
            {NAV_ITEMS.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.end}
                className={({ isActive }) => `sidebar__link ${isActive ? 'sidebar__link--active' : ''}`.trim()}
              >
                <span className="sidebar__link-icon">{item.icon}</span>
                <span>{item.label}</span>
              </NavLink>
            ))}
          </nav>

          <div className="sidebar__footer">
            <div className="sidebar__user">
              <span className="avatar" aria-hidden="true">
                {initials}
              </span>
              <span className="sidebar__user-text">
                <strong>{user?.name}</strong>
                <small>{user?.email}</small>
              </span>
            </div>
            <ThemeToggle />
          </div>
        </aside>

        <div className="shell__main">
          <header className="topbar">
            <button
              type="button"
              className="topbar__menu"
              onClick={() => setDrawerOpen((open) => !open)}
              aria-expanded={isDrawerOpen}
              aria-controls="app-navigation"
              aria-label="Toggle navigation"
            >
              <span aria-hidden="true" />
            </button>
            <span className="topbar__title">
              {NAV_ITEMS.find((item) => (item.end ? item.to === location.pathname : location.pathname.startsWith(item.to)))
                ?.label ?? 'Ledger'}
            </span>
            <ThemeToggle />
          </header>

          <main className="content">
            <Outlet />
          </main>
        </div>
      </div>
    </ReferenceDataProvider>
  )
}
