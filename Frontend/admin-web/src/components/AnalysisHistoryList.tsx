import { StarRating } from './ui'
import { IconButton, TrashIcon } from './icons'

/** Champs communs aux 4 formes de résultat d'analyse IA du projet (chart, rapport, comparaison, combinée). */
export interface AnalysisHistoryItem {
  id: number
  provider: string
  model: string
  status: 'success' | 'failed'
  cached?: boolean
  created_at: string | null
  rating: number | null
}

const PROVIDER_STYLE: Record<string, string> = {
  gemini: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
  anthropic: 'bg-purple-100 text-purple-700 dark:bg-purple-950 dark:text-purple-300',
}

/**
 * Historique des analyses IA — remplace l'ancienne liste de simples liens
 * texte par des cartes (provider/modèle, date, statut, aperçu, étoiles)
 * réutilisées sur les 4 systèmes d'analyse du projet (ChartAiAnalysis,
 * Analysis.tsx, Comparison.tsx, Combined.tsx) plutôt que de dupliquer ce
 * rendu 4 fois avec de petites variations.
 */
export function AnalysisHistoryList<T extends AnalysisHistoryItem>({
  items,
  onSelect,
  onRate,
  onDelete,
  preview,
  title = 'Historique',
  selectedId,
}: {
  items: T[]
  onSelect: (item: T) => void
  onRate: (id: number, rating: number) => void
  onDelete?: (id: number) => void
  preview?: (item: T) => string | null | undefined
  title?: string
  selectedId?: number | null
}) {
  if (items.length === 0) return null

  return (
    <details className="mt-4" open={items.length <= 3}>
      <summary className="cursor-pointer text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
        {title} ({items.length})
      </summary>
      <ul className="mt-2 flex flex-col gap-2">
        {items.map((item) => {
          const previewText = preview?.(item)
          return (
            <li
              key={item.id}
              className={`overflow-hidden rounded-md border p-2 ${
                selectedId === item.id
                  ? 'border-indigo-300 bg-indigo-50 dark:border-indigo-800 dark:bg-indigo-950/30'
                  : 'border-gray-200 dark:border-gray-800'
              }`}
            >
              <div className="flex min-w-0 items-start justify-between gap-3">
                <button type="button" onClick={() => onSelect(item)} className="flex min-w-0 flex-1 flex-col text-left">
                  <div className="flex min-w-0 flex-wrap items-center gap-1.5 text-xs">
                    <span className={`shrink-0 rounded-full px-2 py-0.5 font-medium ${PROVIDER_STYLE[item.provider] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'}`}>
                      {item.provider}/{item.model}
                    </span>
                    <span className="shrink-0 text-gray-400 dark:text-gray-500">{item.created_at}</span>
                    {item.cached && <span className="shrink-0 text-gray-400 dark:text-gray-500">· cache</span>}
                    {item.status === 'failed' && (
                      <span className="shrink-0 rounded-full bg-red-100 px-2 py-0.5 font-medium text-red-700 dark:bg-red-950 dark:text-red-300">
                        échec
                      </span>
                    )}
                  </div>
                  {previewText && (
                    <p className="mt-1 w-full min-w-0 truncate text-xs text-gray-600 dark:text-gray-400">{previewText}</p>
                  )}
                </button>
                <div className="flex shrink-0 items-center gap-1 pt-0.5">
                  <div title={item.rating ? `Noté ${item.rating}/5` : 'Pas encore noté'}>
                    <StarRating value={item.rating} onChange={(r) => onRate(item.id, r)} />
                  </div>
                  {onDelete && (
                    <IconButton
                      title="Supprimer cette analyse"
                      tone="danger"
                      onClick={() => {
                        if (window.confirm('Supprimer définitivement cette analyse ? Cette action est irréversible.')) {
                          onDelete(item.id)
                        }
                      }}
                    >
                      <TrashIcon />
                    </IconButton>
                  )}
                </div>
              </div>
            </li>
          )
        })}
      </ul>
    </details>
  )
}
