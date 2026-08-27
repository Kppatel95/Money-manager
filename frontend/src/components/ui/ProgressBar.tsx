interface ProgressBarProps {
  /** Percentage used; may exceed 100. */
  percent: number
  label?: string
}

/**
 * Budget progress.
 *
 * The colour is derived from how much of the limit is gone rather than passed
 * in, so every budget bar in the app shifts through the same thresholds:
 * comfortable below 75%, a warning up to 100%, and over-budget beyond it. The
 * bar itself is clamped to 100% width while `aria-valuenow` keeps the true
 * figure, so an overspend reads correctly to a screen reader.
 */
export function ProgressBar({ percent, label }: ProgressBarProps) {
  const tone = percent > 100 ? 'over' : percent >= 75 ? 'warn' : 'ok'
  const width = Math.min(Math.max(percent, 0), 100)

  return (
    <div
      className={`progress progress--${tone}`}
      role="progressbar"
      aria-valuenow={Math.round(percent)}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-label={label}
    >
      <div className="progress__fill" style={{ width: `${width}%` }} />
    </div>
  )
}
