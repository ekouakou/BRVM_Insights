import { createContext, useContext, useState, type ReactNode } from 'react'
import { callApi, getToken, setToken, clearToken } from '../lib/apiClient'

interface LoginResponse {
  token: string
  username: string
}

interface AuthContextValue {
  isAuthenticated: boolean
  username: string | null
  login: (username: string, password: string) => Promise<void>
  logout: () => void
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setTokenState] = useState<string | null>(getToken())
  const [username, setUsername] = useState<string | null>(
    localStorage.getItem('brvm_admin_username'),
  )

  async function login(usernameInput: string, password: string) {
    const data = await callApi<LoginResponse>('api_auth.php', 'login', {
      username: usernameInput,
      password,
    })
    setToken(data.token)
    localStorage.setItem('brvm_admin_username', data.username)
    setTokenState(data.token)
    setUsername(data.username)
  }

  function logout() {
    const currentToken = getToken()
    clearToken()
    localStorage.removeItem('brvm_admin_username')
    setTokenState(null)
    setUsername(null)
    if (currentToken) {
      callApi('api_auth.php', 'logout', { token: currentToken }).catch(() => {
        // déconnexion locale déjà effective, une erreur réseau ici est sans conséquence
      })
    }
  }

  return (
    <AuthContext.Provider value={{ isAuthenticated: !!token, username, login, logout }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) {
    throw new Error('useAuth doit être utilisé à l’intérieur de <AuthProvider>')
  }
  return ctx
}
