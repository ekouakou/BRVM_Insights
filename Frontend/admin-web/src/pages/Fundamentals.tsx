import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { callApi } from '../lib/apiClient'
import type { Company, FundamentalsRow } from '../lib/types'
import { Card, ErrorState, InfoPanel, LoadingState, SearchableSelect } from '../components/ui'
import { ChartAiAnalysis } from '../components/ChartAiAnalysis'

function fmt(n: number | string | null | undefined, digits = 2): string {
  if (n === null || n === undefined) return '—'
  const num = typeof n === 'string' ? parseFloat(n) : n
  if (Number.isNaN(num)) return '—'
  return num.toLocaleString('fr-FR', { maximumFractionDigits: digits })
}

function pct(n: number | null, digits = 2): string {
  if (n === null) return '—'
  return `${n > 0 ? '+' : ''}${n.toFixed(digits)}%`
}

function verdictBadgeClass(verdict: string | null) {
  switch (verdict) {
    case 'sous-coté':
      return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
    case 'surcoté':
      return 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
    case 'correctement valorisé':
      return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'
    default:
      return 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'
  }
}

export function Fundamentals() {
  const [selected, setSelected] = useState<number[]>([])
  // null = dernier rapport connu de chaque entreprise (comportement par
  // défaut) ; sinon une année choisie dans le filtre global "Année"
  // ci-dessous — s'applique au TABLEAU ENTIER (toutes les entreprises à la
  // fois) : voir api_fundamentals.php::listFundamentals(), paramètre as_of_year.
  const [asOfYear, setAsOfYear] = useState<number | null>(null)

  const companiesQuery = useQuery({
    queryKey: ['companies-list'],
    queryFn: () => callApi<Company[]>('api_companies.php', 'list', { per_page: 200, active: 1 }),
  })

  const yearsQuery = useQuery({
    queryKey: ['fundamentals-years'],
    queryFn: () => callApi<number[]>('api_fundamentals.php', 'years', {}),
  })

  const fundamentalsQuery = useQuery({
    queryKey: ['fundamentals-list', asOfYear],
    queryFn: () => callApi<FundamentalsRow[]>('api_fundamentals.php', 'list', { as_of_year: asOfYear }),
  })

  const rows = fundamentalsQuery.data ?? []

  const companiesWithoutData = useMemo(() => {
    const withData = new Set(rows.map((r) => r.company_id))
    return (companiesQuery.data ?? []).filter((c) => !withData.has(c.company_id))
  }, [companiesQuery.data, rows])

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Fondamentaux</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Ratios financiers (PER, P/B, ROE, ROA, marges, rendement du dividende...) extraits par IA du dernier
          rapport financier traité de chaque entreprise.
        </p>
      </div>

      <InfoPanel>
        <p>
          <strong>D'où viennent ces chiffres.</strong> Contrairement aux indicateurs techniques (calcul exact sur
          des cours en base), ces ratios sont <strong>extraits par IA</strong> du texte du dernier rapport financier
          de chaque entreprise déjà traité par l'application (états financiers, rapport annuel/semestriel/
          trimestriel...) — la fiabilité dépend de ce que ce rapport a effectivement divulgué et de la qualité de
          l'extraction, pas d'un calcul déterministe. La <strong>date du rapport source</strong> est toujours
          affichée : un chiffre issu d'un rapport vieux de plusieurs mois reste correct pour l'exercice qu'il
          couvre, mais ne reflète pas forcément la situation actuelle de l'entreprise.
        </p>
        <p>
          <strong>Pourquoi pas les pages "sociétés cotées" de brvm.org ?</strong> Vérifié directement : ces pages
          affichent des données bien plus anciennes (jusqu'à 11 ans de retard selon l'entreprise) et n'indiquent pas
          les capitaux propres — une source en apparence "officielle" mais en réalité moins à jour que les rapports
          déjà traités par cette application.
        </p>
        <p>
          <strong>Beaucoup de cases vides, normal.</strong> Un rapport ne divulgue pas systématiquement tous les
          éléments nécessaires à chaque ratio (ex. le nombre d'actions en circulation est rarement précisé, rendant
          le PER/EPS/P-B incalculables même quand le reste du rapport est bien traité) — un champ vide signifie
          « non divulgué dans ce rapport », pas une erreur de calcul.
        </p>
        <p>
          <strong>Tableau vs détail.</strong> Le tableau ci-dessous affiche les ratios les plus consultés ; clique
          "Détails" sur une ligne pour ouvrir la fiche complète de cette entreprise (une quarantaine de ratios
          regroupés par thème : compte de résultat, rentabilité, structure financière, liquidité, cash-flow,
          valorisation, dividende) dans l'onglet Fondamentaux du Tableau de bord entreprise.
          Une partie est directement extraite du rapport par l'IA, le reste (PSR, EV/Sales, EV/EBIT, EV/FCF, FCF
          Yield, dette nette, couverture du dividende, taux de rétention, PEG...) est calculé à partir de ces
          mêmes données — jamais un nouvel appel IA. Les multiples de marché (PER, P/B, EV/*, capitalisation...)
          utilisent tous le même cours de référence (celui du jour d'analyse du rapport, affiché en "Cours réf."),
          pour rester cohérents entre eux plutôt que de mélanger un ratio figé à une ancienne date avec un autre
          calculé au cours du jour.
        </p>
      </InfoPanel>

      {/* Filtre global "Année" — s'applique à TOUTES les entreprises du tableau (et à leur fiche détail) à la
          fois, pas à une seule ligne : chaque entreprise affiche alors son rapport le plus récent publié au plus
          tard le 31/12 de cette année-là (voir api_fundamentals.php::listFundamentals(), `as_of_year`). Les
          entreprises qui n'avaient encore aucun rapport à cette date disparaissent du tableau (comptées dans
          "sans donnée disponible" ci-dessous) plutôt que d'afficher un chiffre trop récent pour être correct
          pour l'année choisie. */}
      {(yearsQuery.data?.length ?? 0) > 0 && (
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <label className="flex items-center gap-2">
            <span className="text-gray-500 dark:text-gray-400">Année :</span>
            <div className="w-64">
              <SearchableSelect
                options={yearsQuery.data!.map((y) => ({ value: String(y), label: `Données au 31/12/${y}` }))}
                value={asOfYear !== null ? String(asOfYear) : ''}
                onChange={(v) => setAsOfYear(v === '' ? null : Number(v))}
                placeholder="Dernier rapport connu"
              />
            </div>
          </label>
          {asOfYear !== null && (
            <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-300">
              Vue historique — pas forcément le dernier rapport connu de chaque entreprise
            </span>
          )}
        </div>
      )}

      {fundamentalsQuery.isLoading && <LoadingState label="Chargement des fondamentaux…" />}
      {fundamentalsQuery.error && <ErrorState message={(fundamentalsQuery.error as Error).message} />}

      {fundamentalsQuery.data && rows.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">
          {asOfYear !== null
            ? `Aucune entreprise n'avait de rapport financier traité par IA au 31/12/${asOfYear}.`
            : "Aucune entreprise n'a encore de rapport financier traité par IA — voir la page Rapports."}
        </p>
      )}

      {rows.length > 0 && (
        <>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            {rows.length} entreprise(s) avec au moins un rapport analysé{asOfYear !== null && ` au 31/12/${asOfYear}`}
            {companiesWithoutData.length > 0 && (
              <>
                {' '}· {companiesWithoutData.length} sans donnée disponible ({companiesWithoutData.map((c) => c.symbol).join(', ')})
              </>
            )}
          </p>

          <Card>
            <div className="mb-3 flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
              <span>{selected.length} sélectionnée(s)</span>
              <button
                type="button"
                onClick={() => setSelected(selected.length === rows.length ? [] : rows.map((r) => r.company_id))}
                className="underline hover:text-gray-700 dark:hover:text-gray-200"
              >
                {selected.length === rows.length ? 'Tout décocher' : 'Tout sélectionner'}
              </button>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                    <th className="pb-2 pr-3"></th>
                    <th className="pb-2 pr-3">Symbole</th>
                    <th className="pb-2 pr-3">Entreprise</th>
                    <th className="pb-2 pr-3" title="Date de publication du rapport source de ces chiffres">
                      Rapport source
                    </th>
                    <th className="pb-2 pr-3 text-right">CA</th>
                    <th className="pb-2 pr-3 text-right" title="Croissance du chiffre d'affaires vs exercice précédent">
                      Croiss. CA
                    </th>
                    <th className="pb-2 pr-3 text-right">Résultat net</th>
                    <th className="pb-2 pr-3 text-right" title="Marge nette = résultat net / chiffre d'affaires">
                      Marge nette
                    </th>
                    <th className="pb-2 pr-3 text-right" title="Rendement des capitaux propres">
                      ROE
                    </th>
                    <th className="pb-2 pr-3 text-right" title="Rendement des actifs">
                      ROA
                    </th>
                    <th className="pb-2 pr-3 text-right" title="Dette totale / capitaux propres">
                      Dette/CP
                    </th>
                    <th className="pb-2 pr-3 text-right" title="Price/Earnings — cours / bénéfice par action">
                      PER
                    </th>
                    <th className="pb-2 pr-3 text-right" title="PER ÷ croissance du CA — calculé, absent du rapport source">
                      PEG
                    </th>
                    <th className="pb-2 pr-3 text-right" title="Price/Book — cours / valeur comptable par action">
                      P/B
                    </th>
                    <th className="pb-2 pr-3 text-right" title="Valeur d'entreprise / EBITDA">
                      EV/EBITDA
                    </th>
                    <th className="pb-2 pr-3 text-right">Rdt dividende</th>
                    <th className="pb-2 pr-3" title="Appréciation de valorisation formulée par l'IA à partir du rapport">
                      Verdict IA
                    </th>
                    <th className="pb-2"></th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.company_id} className="border-t border-gray-100 dark:border-gray-800">
                      <td className="py-2 pr-3">
                        <input
                          type="checkbox"
                          checked={selected.includes(r.company_id)}
                          onChange={() =>
                            setSelected((prev) =>
                              prev.includes(r.company_id) ? prev.filter((id) => id !== r.company_id) : [...prev, r.company_id],
                            )
                          }
                        />
                      </td>
                      <td className="py-2 pr-3 font-medium">{r.symbol}</td>
                      <td className="py-2 pr-3 text-gray-600 dark:text-gray-300">{r.name}</td>
                      <td className="py-2 pr-3 text-gray-500 dark:text-gray-400" title={r.source_report_title}>
                        {r.source_publish_date ?? '—'} ({r.source_report_type})
                      </td>
                      <td className="py-2 pr-3 text-right tabular-nums">{fmt(r.revenue, 0)}</td>
                      <td
                        className={`py-2 pr-3 text-right tabular-nums ${
                          r.revenue_growth_percent === null ? 'text-gray-500' : r.revenue_growth_percent > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'
                        }`}
                      >
                        {pct(r.revenue_growth_percent)}
                      </td>
                      <td className="py-2 pr-3 text-right tabular-nums">{fmt(r.net_income, 0)}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{pct(r.net_margin_percent)}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{pct(r.roe_percent)}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{pct(r.roa_percent)}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{fmt(r.debt_to_equity, 2)}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{fmt(r.pe_ratio, 1)}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{fmt(r.peg_ratio, 2)}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{fmt(r.price_to_book, 2)}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{fmt(r.ev_to_ebitda, 2)}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{pct(r.dividend_yield_percent)}</td>
                      <td className="py-2 pr-3">
                        <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${verdictBadgeClass(r.valuation_verdict)}`}>
                          {r.valuation_verdict ?? '—'}
                        </span>
                      </td>
                      <td className="py-2">
                        <Link
                          to={`/company?symbol=${r.symbol}&tab=fondamentaux`}
                          className="text-xs text-indigo-600 hover:underline dark:text-indigo-400"
                        >
                          Détails →
                        </Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <ChartAiAnalysis
              chartType="fundamentals"
              parameters={{ selected_company_ids: [...selected].sort((a, b) => a - b) }}
              data={rows.filter((r) => selected.length === 0 || selected.includes(r.company_id))}
              disabled={selected.length === 0}
              disabledReason="Coche au moins une entreprise pour l'analyser."
            />
          </Card>
        </>
      )}
    </div>
  )
}
