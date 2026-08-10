import type { ReactNode } from 'react'
import { NavLink } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

const NAV_ITEMS = [
  { to: '/', label: 'Vue d’ensemble', end: true },
  { to: '/company', label: 'Tableau de bord entreprise' },
  { to: '/quotes', label: 'Cotations' },
  { to: '/volume-ranking', label: 'Classements' },
  { to: '/sync', label: 'Synchronisation' },
  { to: '/reports', label: 'Rapports' },
  { to: '/bulletins', label: 'Bulletins' },
  { to: '/analysis', label: 'Analyse IA' },
  { to: '/comparison', label: 'Comparaison' },
  { to: '/combined', label: 'Analyse combinée' },
  { to: '/market-health', label: 'Santé & secteurs' },
  { to: '/statistics', label: 'Statistiques' },
  { to: '/price-alerts', label: 'Alertes de prix' },
  { to: '/screener', label: 'Screener' },
  { to: '/fundamentals', label: 'Fondamentaux' },
  { to: '/backtest', label: 'Backtesting' },
  { to: '/corporate-actions', label: 'Opérations sur titres' },
]

export function Layout({ children }: { children: ReactNode }) {
  const { username, logout } = useAuth()

  return (
    <div className="flex min-h-screen bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-gray-100">
      <aside className="w-56 shrink-0 border-r border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        <h1 className="mb-6 px-2 text-lg font-semibold tracking-tight">BRVM Admin</h1>
        <nav className="flex flex-col gap-1">
          {NAV_ITEMS.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={({ isActive }) =>
                `rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                  isActive
                    ? 'bg-indigo-600 text-white'
                    : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'
                }`
              }
            >
              {item.label}
            </NavLink>
          ))}
        </nav>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex items-center justify-between border-b border-gray-200 bg-white px-6 py-3 dark:border-gray-800 dark:bg-gray-900">
          <span className="text-sm text-gray-500 dark:text-gray-400">
            Connecté en tant que <strong>{username}</strong>
          </span>
          <button
            onClick={logout}
            className="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
          >
            Déconnexion
          </button>
        </header>

        <main className="min-w-0 flex-1 p-6">{children}</main>
      </div>
    </div>
  )
}
