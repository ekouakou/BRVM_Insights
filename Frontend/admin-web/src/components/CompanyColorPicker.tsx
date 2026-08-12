import { resolveCssColor } from '../lib/companyGroups'
import type { Company } from '../lib/types'

/**
 * Rangée de pipettes : une couleur modifiable par entreprise sélectionnée
 * dans un écran de comparaison. Le choix est persisté (localStorage) et
 * prime partout dans l'application ; « ↺ » revient à la couleur
 * automatique. Reçoit colorFor/setColor/overrides du hook useCompanyColors
 * de la page appelante (le même état pilote pipettes ET courbes — un
 * changement recolore le graphe immédiatement).
 */
export function SelectedCompanyColors({
  companies,
  selectedIds,
  colorFor,
  setColor,
  overrides,
}: {
  companies: Company[]
  selectedIds: number[]
  colorFor: (companyId: number, selection?: number[]) => string
  setColor: (companyId: number, color: string | null) => void
  overrides: Record<number, string>
}) {
  if (selectedIds.length === 0) return null

  return (
    <div className="flex flex-wrap items-center gap-3">
      <span className="text-xs font-medium text-gray-700 dark:text-gray-300">Couleurs :</span>
      {selectedIds.map((id) => {
        const company = companies.find((c) => c.company_id === id)
        if (!company) return null
        return (
          <span key={id} className="flex items-center gap-1 text-xs text-gray-600 dark:text-gray-300">
            <input
              type="color"
              value={resolveCssColor(colorFor(id, selectedIds))}
              onChange={(e) => setColor(id, e.target.value)}
              title={`Couleur de ${company.symbol}`}
              className="h-5 w-5 cursor-pointer rounded border border-gray-300 bg-transparent p-0 dark:border-gray-700"
            />
            {company.symbol}
            {overrides[id] && (
              <button
                type="button"
                onClick={() => setColor(id, null)}
                title="Revenir à la couleur automatique"
                className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
              >
                ↺
              </button>
            )}
          </span>
        )
      })}
    </div>
  )
}
