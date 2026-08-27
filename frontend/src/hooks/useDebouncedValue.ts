import { useEffect, useState } from 'react'

/**
 * Trails `value` by `delay` ms.
 *
 * The transaction search box is bound to this so typing "groceries" is one
 * request rather than nine.
 */
export function useDebouncedValue<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(timer)
  }, [value, delay])

  return debounced
}
