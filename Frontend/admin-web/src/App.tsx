import type { ReactNode } from 'react'
import { Routes, Route } from 'react-router-dom'
import { AuthProvider } from './context/AuthContext'
import { ProtectedRoute } from './components/ProtectedRoute'
import { Layout } from './components/Layout'
import { Login } from './pages/Login'
import { Overview } from './pages/Overview'
import { Quotes } from './pages/Quotes'
import { Sync } from './pages/Sync'
import { Reports } from './pages/Reports'
import { Bulletins } from './pages/Bulletins'
import { Analysis } from './pages/Analysis'
import { Comparison } from './pages/Comparison'
import { Combined } from './pages/Combined'

function Protected({ children }: { children: ReactNode }) {
  return (
    <ProtectedRoute>
      <Layout>{children}</Layout>
    </ProtectedRoute>
  )
}

export default function App() {
  return (
    <AuthProvider>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/" element={<Protected><Overview /></Protected>} />
        <Route path="/quotes" element={<Protected><Quotes /></Protected>} />
        <Route path="/sync" element={<Protected><Sync /></Protected>} />
        <Route path="/reports" element={<Protected><Reports /></Protected>} />
        <Route path="/bulletins" element={<Protected><Bulletins /></Protected>} />
        <Route path="/analysis" element={<Protected><Analysis /></Protected>} />
        <Route path="/comparison" element={<Protected><Comparison /></Protected>} />
        <Route path="/combined" element={<Protected><Combined /></Protected>} />
      </Routes>
    </AuthProvider>
  )
}
