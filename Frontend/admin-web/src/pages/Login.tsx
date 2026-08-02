import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { Button, ErrorState, Input } from '../components/ui'

export function Login() {
  const { isAuthenticated, login } = useAuth()
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  if (isAuthenticated) {
    return <Navigate to="/" replace />
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      await login(username, password)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Échec de connexion')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-gray-50 dark:bg-gray-950">
      <form
        onSubmit={handleSubmit}
        className="w-full max-w-sm rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900"
      >
        <h1 className="mb-1 text-lg font-semibold text-gray-900 dark:text-gray-100">BRVM Admin</h1>
        <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">Connecte-toi pour continuer</p>

        {error && (
          <div className="mb-4">
            <ErrorState message={error} />
          </div>
        )}

        <label className="mb-3 block">
          <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Identifiant</span>
          <Input value={username} onChange={(e) => setUsername(e.target.value)} autoFocus required />
        </label>

        <label className="mb-5 block">
          <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Mot de passe</span>
          <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
        </label>

        <Button type="submit" disabled={loading}>
          {loading ? 'Connexion…' : 'Se connecter'}
        </Button>
      </form>
    </div>
  )
}
