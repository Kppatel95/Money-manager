import { useCallback, useEffect, useRef, useState } from 'react'
import { SessionExpiredError } from '../api/client'

export interface AsyncState<T> {
  data: T | null
  /** True on the first load only, so refreshes don't blank the screen. */
  isLoading: boolean
  /** True while a background refetch is running. */
  isRefreshing: boolean
  error: unknown
  reload: () => void
  /** Optimistically replace the cached value without a round trip. */
  setData: (updater: T | ((current: T | null) => T)) => void
}

/**
 * Runs an async loader and tracks its lifecycle.
 *
 * Two details matter here. Requests are aborted when `deps` change or the
 * component unmounts, so switching filters quickly cannot leave a slow earlier
 * response to overwrite a newer one. And an expired session is swallowed rather
 * than surfaced: the client has already cleared the tokens and the shell is
 * about to redirect, so showing "your session has expired" in a page-level
 * error banner would just be noise on the way out.
 */
export function useAsync<T>(loader: (signal: AbortSignal) => Promise<T>, deps: readonly unknown[]): AsyncState<T> {
  const [data, setDataState] = useState<T | null>(null)
  const [isLoading, setLoading] = useState(true)
  const [isRefreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<unknown>(null)
  const [nonce, setNonce] = useState(0)

  const hasLoaded = useRef(false)
  const loaderRef = useRef(loader)
  loaderRef.current = loader

  useEffect(() => {
    const controller = new AbortController()
    let cancelled = false

    if (hasLoaded.current) setRefreshing(true)
    else setLoading(true)

    loaderRef
      .current(controller.signal)
      .then((result) => {
        if (cancelled) return
        setDataState(result)
        setError(null)
      })
      .catch((cause: unknown) => {
        if (cancelled || controller.signal.aborted) return
        if (cause instanceof SessionExpiredError) return
        setError(cause)
      })
      .finally(() => {
        if (cancelled) return
        hasLoaded.current = true
        setLoading(false)
        setRefreshing(false)
      })

    return () => {
      cancelled = true
      controller.abort()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, nonce])

  const setData = useCallback((updater: T | ((current: T | null) => T)) => {
    setDataState((current) => (typeof updater === 'function' ? (updater as (c: T | null) => T)(current) : updater))
  }, [])

  const reload = useCallback(() => setNonce((n) => n + 1), [])

  return { data, isLoading, isRefreshing, error, reload, setData }
}
