import type { ReactNode } from 'react'
import { Routes, Route, useLocation } from 'react-router-dom'
import { AuthProvider } from './context/AuthContext'
import { ProtectedRoute } from './components/ProtectedRoute'
import { Layout } from './components/Layout'
import { ErrorBoundary } from './components/ErrorBoundary'
import { Login } from './pages/Login'
import { Overview } from './pages/Overview'
import { CompanyDashboard } from './pages/CompanyDashboard'
import { Quotes } from './pages/Quotes'
import { Sync } from './pages/Sync'
import { Reports } from './pages/Reports'
import { Bulletins } from './pages/Bulletins'
import { Analysis } from './pages/Analysis'
import { Comparison } from './pages/Comparison'
import { Combined } from './pages/Combined'
import { MarketHealth } from './pages/MarketHealth'
import { Statistics } from './pages/Statistics'
import { PriceAlerts } from './pages/PriceAlerts'
import { VolumeRanking } from './pages/VolumeRanking'
import { Screener } from './pages/Screener'
import { Fundamentals } from './pages/Fundamentals'
import { ManualFinancials } from './pages/ManualFinancials'
import { Backtest } from './pages/Backtest'
import { CorporateActions } from './pages/CorporateActions'
import { BondMarket } from './pages/BondMarket'
import { Indices } from './pages/Indices'
import { PortfolioTeam } from './pages/PortfolioTeam'
import { OcrReports } from './pages/OcrReports'
import { FinancialStatements } from './pages/FinancialStatements'

function Protected({ children }: { children: ReactNode }) {
  const location = useLocation()
  return (
    <ProtectedRoute>
      <Layout>
        {/* La limite est remontée par la clé : changer d'écran repart d'un
            état sain, sinon une erreur figerait toute la navigation. Elle est
            placée SOUS le Layout pour que le menu reste utilisable. */}
        <ErrorBoundary key={location.pathname} label={location.pathname}>
          {children}
        </ErrorBoundary>
      </Layout>
    </ProtectedRoute>
  )
}

export default function App() {
  return (
    <AuthProvider>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/" element={<Protected><Overview /></Protected>} />
        <Route path="/company" element={<Protected><CompanyDashboard /></Protected>} />
        <Route path="/quotes" element={<Protected><Quotes /></Protected>} />
        <Route path="/volume-ranking" element={<Protected><VolumeRanking /></Protected>} />
        <Route path="/sync" element={<Protected><Sync /></Protected>} />
        <Route path="/reports" element={<Protected><Reports /></Protected>} />
        <Route path="/bulletins" element={<Protected><Bulletins /></Protected>} />
        <Route path="/analysis" element={<Protected><Analysis /></Protected>} />
        <Route path="/comparison" element={<Protected><Comparison /></Protected>} />
        <Route path="/combined" element={<Protected><Combined /></Protected>} />
        <Route path="/market-health" element={<Protected><MarketHealth /></Protected>} />
        <Route path="/statistics" element={<Protected><Statistics /></Protected>} />
        <Route path="/price-alerts" element={<Protected><PriceAlerts /></Protected>} />
        <Route path="/screener" element={<Protected><Screener /></Protected>} />
        <Route path="/fundamentals" element={<Protected><Fundamentals /></Protected>} />
        <Route path="/manual-financials" element={<Protected><ManualFinancials /></Protected>} />
        <Route path="/backtest" element={<Protected><Backtest /></Protected>} />
        <Route path="/corporate-actions" element={<Protected><CorporateActions /></Protected>} />
        <Route path="/bond-market" element={<Protected><BondMarket /></Protected>} />
        <Route path="/indices" element={<Protected><Indices /></Protected>} />
        <Route path="/my-team" element={<Protected><PortfolioTeam /></Protected>} />
        <Route path="/ocr" element={<Protected><OcrReports /></Protected>} />
        <Route path="/financial-statements" element={<Protected><FinancialStatements /></Protected>} />
      </Routes>
    </AuthProvider>
  )
}
