import { AuthProvider, useAuth } from './context/AuthContext'
import { AuthPage } from './components/AuthPage'
import { Dashboard } from './components/Dashboard'
import './App.css'

function AppShell() {
  const { isAuthenticated } = useAuth()
  return isAuthenticated ? <Dashboard /> : <AuthPage />
}

function App() {
  return (
    <AuthProvider>
      <AppShell />
    </AuthProvider>
  )
}

export default App
