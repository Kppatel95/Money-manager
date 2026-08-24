import { AuthProvider, useAuth } from './context/AuthContext'
import { AuthPage } from './components/AuthPage'
import './App.css'

function AppShell() {
  const { isAuthenticated, user } = useAuth()

  if (!isAuthenticated) {
    return <AuthPage />
  }

  // Dashboard UI lands in the next commit.
  return (
    <div className="auth-page">
      <div className="auth-card">
        <h1 className="brand">Expense Tracker</h1>
        <p className="tagline">Signed in as {user?.name}. Dashboard coming next.</p>
      </div>
    </div>
  )
}

function App() {
  return (
    <AuthProvider>
      <AppShell />
    </AuthProvider>
  )
}

export default App
