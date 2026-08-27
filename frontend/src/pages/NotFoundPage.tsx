import { Link } from 'react-router-dom'
import { EmptyState } from '../components/ui/States'

export function NotFoundPage() {
  return (
    <div className="notfound">
      <EmptyState
        icon="404"
        title="Page not found"
        message="That route doesn't exist. It may have been renamed, or the link that brought you here is out of date."
        action={
          <Link className="btn btn--primary btn--md" to="/">
            Back to the dashboard
          </Link>
        }
      />
    </div>
  )
}
