import { useTheme } from '../../context/ThemeContext'

/**
 * Light/dark switch. Cycles through the OS default too, because a hard-coded
 * light theme on a machine set to dark is a jarring first impression.
 */
export function ThemeToggle() {
  const { preference, theme, setPreference } = useTheme()

  const next = preference === 'system' ? 'light' : preference === 'light' ? 'dark' : 'system'
  const labels = { system: 'System theme', light: 'Light theme', dark: 'Dark theme' } as const

  return (
    <button
      type="button"
      className="theme-toggle"
      onClick={() => setPreference(next)}
      title={`${labels[preference]} — click for ${labels[next].toLowerCase()}`}
      aria-label={`${labels[preference]}. Switch to ${labels[next].toLowerCase()}.`}
    >
      <span className="theme-toggle__icon" aria-hidden="true">
        {preference === 'system' ? '◐' : theme === 'dark' ? '☾' : '☀'}
      </span>
      <span className="theme-toggle__text">{preference === 'system' ? 'Auto' : theme === 'dark' ? 'Dark' : 'Light'}</span>
    </button>
  )
}
