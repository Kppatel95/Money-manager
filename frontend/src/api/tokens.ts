import type { Session, User } from '../types'

/**
 * Token storage.
 *
 * Both tokens live in `localStorage` so a reload keeps you signed in. That is a
 * deliberate tradeoff: it is readable by any script that manages to run on the
 * page, and the safer arrangement is an access token held only in memory with
 * the refresh token in an httpOnly cookie. That needs the API to set cookies,
 * which this one does not, so the honest version is this plus a short-lived
 * (15 minute) access token.
 *
 * Everything that touches storage goes through here, so there is exactly one
 * place that knows the key names and exactly one place that clears them.
 */

const ACCESS_KEY = 'pf.access_token'
const REFRESH_KEY = 'pf.refresh_token'
const USER_KEY = 'pf.user'

type Listener = () => void

const listeners = new Set<Listener>()

function notify(): void {
  for (const listener of listeners) listener()
}

function read(key: string): string | null {
  try {
    return localStorage.getItem(key)
  } catch {
    return null
  }
}

function write(key: string, value: string): void {
  try {
    localStorage.setItem(key, value)
  } catch {
    /* private mode / quota — the session simply won't survive a reload */
  }
}

export const tokenStore = {
  accessToken: (): string | null => read(ACCESS_KEY),
  refreshToken: (): string | null => read(REFRESH_KEY),

  user(): User | null {
    const raw = read(USER_KEY)
    if (!raw) return null
    try {
      return JSON.parse(raw) as User
    } catch {
      return null
    }
  },

  save(session: Session): void {
    write(ACCESS_KEY, session.access_token)
    write(REFRESH_KEY, session.refresh_token)
    write(USER_KEY, JSON.stringify(session.user))
    notify()
  },

  saveUser(user: User): void {
    write(USER_KEY, JSON.stringify(user))
    notify()
  },

  clear(): void {
    try {
      localStorage.removeItem(ACCESS_KEY)
      localStorage.removeItem(REFRESH_KEY)
      localStorage.removeItem(USER_KEY)
    } catch {
      /* nothing to do */
    }
    notify()
  },

  /** Subscribe to sign-in/sign-out so React state can follow storage. */
  subscribe(listener: Listener): () => void {
    listeners.add(listener)
    return () => listeners.delete(listener)
  },
}
