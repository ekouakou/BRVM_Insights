import type { ReactNode } from 'react'

export function Card({ title, children, className = '' }: { title?: string; children: ReactNode; className?: string }) {
  return (
    <div className={`rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 ${className}`}>
      {title && <h2 className="mb-3 text-sm font-semibold text-gray-500 dark:text-gray-400">{title}</h2>}
      {children}
    </div>
  )
}

/** Petit indicateur de statut de mise en forme markdown, affiché dans les listes. */
export function MarkdownBadge({ status }: { status: 'processing' | 'success' | 'failed' | null }) {
  if (!status) return null
  const config = {
    processing: { label: 'MD…', className: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' },
    success: { label: 'MD ✓', className: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' },
    failed: { label: 'MD ✗', className: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' },
  }[status]
  return (
    <span
      className={`ml-1.5 rounded px-1.5 py-0.5 text-[10px] font-semibold ${config.className}`}
      title={`Markdown : ${status === 'processing' ? 'formatage en cours' : status === 'success' ? 'formaté' : 'échec du formatage'}`}
    >
      {config.label}
    </span>
  )
}

/** Petit indicateur du nombre d'analyses IA déjà effectuées (et des modèles utilisés), affiché dans les listes. */
export function AnalysisBadge({ count, models }: { count: number; models: string[] }) {
  if (!count) return null
  return (
    <span
      className="ml-1.5 rounded px-1.5 py-0.5 text-[10px] font-semibold bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300"
      title={`Analysé ${count} fois par l'IA — modèle(s) : ${models.join(', ')}`}
    >
      {`IA ×${count}`}
    </span>
  )
}

export function StatTile({ label, value, tone = 'default' }: { label: string; value: ReactNode; tone?: 'default' | 'positive' | 'negative' }) {
  const toneClass =
    tone === 'positive' ? 'text-emerald-600 dark:text-emerald-400'
    : tone === 'negative' ? 'text-red-600 dark:text-red-400'
    : 'text-gray-900 dark:text-gray-100'

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
      <div className="text-xs text-gray-500 dark:text-gray-400">{label}</div>
      <div className={`mt-1 text-xl font-semibold ${toneClass}`}>{value}</div>
    </div>
  )
}

export function LoadingState({ label = 'Chargement…' }: { label?: string }) {
  return <p className="text-sm text-gray-500 dark:text-gray-400">{label}</p>
}

export function ErrorState({ message }: { message: string }) {
  return (
    <div className="rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
      {message}
    </div>
  )
}

export function Table({ headers, children }: { headers: string[]; children: ReactNode }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead>
          <tr className="border-b border-gray-200 text-gray-500 dark:border-gray-800 dark:text-gray-400">
            {headers.map((h) => (
              <th key={h} className="whitespace-nowrap px-3 py-2 font-medium">
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-100 dark:divide-gray-800">{children}</tbody>
      </table>
    </div>
  )
}

export function Button({
  children,
  onClick,
  disabled,
  variant = 'primary',
  type = 'button',
}: {
  children: ReactNode
  onClick?: () => void
  disabled?: boolean
  variant?: 'primary' | 'secondary'
  type?: 'button' | 'submit'
}) {
  const base = 'rounded-md px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-50'
  const styles =
    variant === 'primary'
      ? 'bg-indigo-600 text-white hover:bg-indigo-500'
      : 'border border-gray-300 text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800'

  return (
    <button type={type} onClick={onClick} disabled={disabled} className={`${base} ${styles}`}>
      {children}
    </button>
  )
}

export function Input(props: React.InputHTMLAttributes<HTMLInputElement>) {
  return (
    <input
      {...props}
      className={`w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 ${props.className ?? ''}`}
    />
  )
}

export function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <div
        className="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white p-5 shadow-xl dark:bg-gray-900"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-3 flex items-center justify-between gap-3">
          <h2 className="text-sm font-semibold text-gray-900 dark:text-gray-100">{title}</h2>
          <button
            type="button"
            onClick={onClose}
            aria-label="Fermer"
            className="rounded-md p-1 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
              <line x1="6" y1="6" x2="18" y2="18" />
              <line x1="18" y1="6" x2="6" y2="18" />
            </svg>
          </button>
        </div>
        {children}
      </div>
    </div>
  )
}

/**
 * Notation par étoiles (1-5), utilisée sur l'historique des analyses IA.
 * `onChange` absent = lecture seule (étoiles non cliquables) ; sinon chaque
 * étoile déclenche immédiatement `onChange` (pas de bouton "valider" séparé).
 */
export function StarRating({
  value,
  onChange,
  size = 'sm',
}: {
  value: number | null
  onChange?: (rating: number) => void
  size?: 'sm' | 'md'
}) {
  const textSize = size === 'md' ? 'text-lg' : 'text-sm'
  return (
    <div className="flex items-center gap-0.5" role={onChange ? 'radiogroup' : undefined} aria-label="Note">
      {[1, 2, 3, 4, 5].map((star) => (
        <button
          key={star}
          type="button"
          disabled={!onChange}
          onClick={(e) => {
            e.stopPropagation()
            onChange?.(star)
          }}
          aria-label={`${star} étoile${star > 1 ? 's' : ''}`}
          aria-pressed={value !== null && star <= value}
          className={`leading-none ${textSize} ${onChange ? 'cursor-pointer' : 'cursor-default'} ${
            value !== null && star <= value
              ? 'text-amber-400'
              : 'text-gray-300 dark:text-gray-700' + (onChange ? ' hover:text-amber-300' : '')
          }`}
        >
          ★
        </button>
      ))}
    </div>
  )
}

/** Barre d'onglets simple (soulignement de l'onglet actif) — bascule entre plusieurs vues au sein d'une même page, sans changer de route. */
export function Tabs({
  tabs,
  active,
  onChange,
}: {
  tabs: { id: string; label: string }[]
  active: string
  onChange: (id: string) => void
}) {
  return (
    <div role="tablist" className="flex gap-1 border-b border-gray-200 dark:border-gray-800">
      {tabs.map((tab) => (
        <button
          key={tab.id}
          type="button"
          role="tab"
          aria-selected={active === tab.id}
          onClick={() => onChange(tab.id)}
          className={`-mb-px border-b-2 px-4 py-2 text-sm font-medium transition-colors ${
            active === tab.id
              ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
              : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'
          }`}
        >
          {tab.label}
        </button>
      ))}
    </div>
  )
}

/**
 * Bloc d'explication repliable — "à quoi sert cet écran / comment lire ces
 * résultats", affiché sous les onglets. Replié par défaut pour ne pas
 * regonfler la page (l'intérêt des onglets est justement de gagner de la
 * place) ; ouvert au clic pour l'utilisateur qui a besoin du contexte.
 * `<details>` natif : accessible et sans état React à gérer.
 */
export function InfoPanel({ title = 'Comment lire cet écran ?', children }: { title?: string; children: ReactNode }) {
  return (
    <details className="group rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900/40">
      <summary className="flex cursor-pointer list-none items-center gap-2 px-4 py-2.5 text-sm font-medium text-gray-700 marker:content-none dark:text-gray-300">
        <span className="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-gray-300 text-[10px] font-bold text-white dark:bg-gray-600">
          ?
        </span>
        {title}
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          className="ml-auto h-4 w-4 shrink-0 text-gray-400 transition-transform group-open:rotate-180"
        >
          <polyline points="6 9 12 15 18 9" />
        </svg>
      </summary>
      <div className="flex flex-col gap-2 border-t border-gray-200 px-4 py-3 text-sm leading-relaxed text-gray-600 dark:border-gray-800 dark:text-gray-300">
        {children}
      </div>
    </details>
  )
}

export function Select(props: React.SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <select
      {...props}
      className={`w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 ${props.className ?? ''}`}
    />
  )
}
