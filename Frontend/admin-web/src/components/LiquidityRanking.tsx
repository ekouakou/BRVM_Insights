import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, Cell, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { callApi } from '../lib/apiClient'
import type { LiquidityRankingResult, LiquidityRankingRow } from '../lib/types'
import { Card, ErrorState, LoadingState, Select } from './ui'
import { ChartAiAnalysis } from './ChartAiAnalysis'

/**
 * Onglet « Liquidité & pression » de l'écran Classements : classe TOUTES les
 * entreprises actives sur les dimensions du moteur de carnet d'ordres
 * (TODO_CARNET_ORDRES.md) — facilité de vente, pression vendeuse/acheteuse,
 * offre et demande en attente, spread. Une seule requête serveur, le
 * critère de tri ne change que l'affichage.
 */

const nf = new Intl.NumberFormat('fr-FR')

type CriterionId =
  | 'facilite'
  | 'pression_vendeuse'
  | 'pression_acheteuse'
  | 'offre_attente'
  | 'demande_attente'
  | 'volume'
  | 'spread'

interface Criterion {
  id: CriterionId
  label: string
  /** Valeur triée/affichée ; null = entreprise non classable sur ce critère. */
  value: (r: LiquidityRankingRow) => number | null
  format: (v: number) => string
  /** Couleur de la barre selon la valeur. */
  color: (v: number) => string
  /** true = les plus petites valeurs en premier (ex. spread). */
  ascending?: boolean
  axisDomain?: [number, number]
  help: string
  emptyHelp: string
}

const CRITERIA: Criterion[] = [
  {
    id: 'facilite',
    label: 'Facilité de vente (score 0-100)',
    value: (r) => r.liquidity_score,
    format: (v) => `${v}/100`,
    color: (v) => (v >= 70 ? 'var(--chart-positive)' : v >= 45 ? 'var(--chart-2)' : 'var(--chart-negative)'),
    axisDomain: [0, 100],
    help:
      "Score de liquidité estimé : à quel point il est facile de revendre ce titre sans attendre ni brader. Il combine six éléments — la valeur échangée chaque jour (25%), la régularité des échanges (20%), le spread (20%), la profondeur du carnet (15%), l'absorption des ventes (10%) et la stabilité du spread (10%). En haut du classement : les titres qu'on revend en quelques heures. En bas : ceux qui peuvent bloquer un vendeur plusieurs séances.",
    emptyHelp: "Entreprises sans score : pas assez d'historique récent pour le calculer.",
  },
  {
    id: 'pression_vendeuse',
    label: 'Pression vendeuse (% des échanges)',
    value: (r) => r.sell_pressure_percent,
    format: (v) => `${v}%`,
    color: (v) => (v >= 60 ? 'var(--chart-negative)' : v >= 40 ? 'var(--chart-2)' : 'var(--chart-positive)'),
    axisDomain: [0, 100],
    help:
      "Part des échanges de la période où ce sont les vendeurs qui semblaient à l'initiative (le prix baissait au moment de l'échange). Au-dessus de 60% : les vendeurs mènent la danse, souvent un titre qui recule. En dessous de 40% : ce sont plutôt les acheteurs qui poussent.",
    emptyHelp: "Entreprises absentes : aucun échange classable sur la période (pas de transaction, ou prix toujours inchangé).",
  },
  {
    id: 'pression_acheteuse',
    label: 'Pression acheteuse (% des échanges)',
    value: (r) => r.buy_pressure_percent,
    format: (v) => `${v}%`,
    color: (v) => (v >= 60 ? 'var(--chart-positive)' : v >= 40 ? 'var(--chart-2)' : 'var(--chart-negative)'),
    axisDomain: [0, 100],
    help:
      "L'inverse du précédent : part des échanges où les acheteurs semblaient les plus pressés (le prix montait). Un titre durablement au-dessus de 60% attire la demande.",
    emptyHelp: "Entreprises absentes : aucun échange classable sur la période.",
  },
  {
    id: 'offre_attente',
    label: 'Titres en attente à la vente (moyenne)',
    value: (r) => r.avg_ask_qty,
    format: (v) => `${nf.format(v)} titres`,
    color: () => 'var(--chart-negative)',
    help:
      "Nombre moyen de titres que des vendeurs proposaient en fin de séance, au meilleur prix (données du Bulletin Officiel de la Cote). Beaucoup de titres en attente = une file de vendeurs qui n'ont pas trouvé preneur : un acheteur sera servi facilement, un vendeur supplémentaire devra faire la queue.",
    emptyHelp: "Entreprises absentes : aucun carnet publié sur la période, ou côté vente vide.",
  },
  {
    id: 'demande_attente',
    label: "Titres en attente à l'achat (moyenne)",
    value: (r) => r.avg_bid_qty,
    format: (v) => `${nf.format(v)} titres`,
    color: () => 'var(--chart-positive)',
    help:
      "Nombre moyen de titres que des acheteurs cherchaient à acheter en fin de séance, au meilleur prix. C'est la capacité d'absorption immédiate : si vous vendez, voilà à peu près combien de titres trouvent preneur tout de suite sans faire baisser le prix.",
    emptyHelp: "Entreprises absentes : aucun carnet publié sur la période, ou côté achat vide.",
  },
  {
    id: 'volume',
    label: 'Titres réellement échangés',
    value: (r) => (r.executed_volume > 0 ? r.executed_volume : null),
    format: (v) => `${nf.format(v)} titres`,
    color: () => 'var(--chart-1)',
    help:
      "Total des titres qui ont réellement changé de mains sur la période, reconstitué depuis les relevés intraday. C'est un fait mesuré, pas une estimation — mais il ne dit rien du sens du mouvement.",
    emptyHelp: "Entreprises absentes : aucun échange enregistré sur la période.",
  },
  {
    id: 'spread',
    label: 'Spread moyen (plus bas = mieux)',
    value: (r) => r.avg_spread_percent,
    format: (v) => `${v.toFixed(2)}%`,
    color: (v) => (v <= 1 ? 'var(--chart-positive)' : v <= 3 ? 'var(--chart-2)' : 'var(--chart-negative)'),
    ascending: true,
    help:
      "Écart moyen entre le prix demandé par les vendeurs et celui offert par les acheteurs, en % du cours. C'est le coût caché d'un aller-retour : acheter puis revendre immédiatement vous coûte à peu près ce pourcentage. Plus il est bas, plus le titre est liquide.",
    emptyHelp: "Entreprises absentes : carnet incomplet (un des deux côtés vide) sur toute la période.",
  },
]

export function LiquidityRankingPanel({ startDate, endDate }: { startDate: string; endDate: string }) {
  const [criterionId, setCriterionId] = useState<CriterionId>('facilite')
  const criterion = CRITERIA.find((c) => c.id === criterionId) ?? CRITERIA[0]

  const query = useQuery({
    queryKey: ['ob-ranking', startDate, endDate],
    queryFn: () =>
      callApi<LiquidityRankingResult>('api_order_book.php', 'ranking', {
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!startDate && !!endDate && startDate <= endDate,
  })

  const { ranked, unranked } = useMemo(() => {
    const rows = query.data?.rows ?? []
    const withValue = rows
      .map((r) => ({ row: r, value: criterion.value(r) }))
      .filter((x): x is { row: LiquidityRankingRow; value: number } => x.value !== null)
    withValue.sort((a, b) => (criterion.ascending ? a.value - b.value : b.value - a.value))
    return { ranked: withValue, unranked: rows.length - withValue.length }
  }, [query.data, criterion])

  if (query.isLoading) return <LoadingState label="Calcul des classements de liquidité…" />
  if (query.error) return <ErrorState message={(query.error as Error).message} />
  if (!query.data) return null

  const chartData = ranked.map(({ row, value }) => ({ symbol: row.symbol, name: row.name, value, row }))

  return (
    <div className="flex flex-col gap-4">
      <Card>
        <label className="block w-full max-w-md">
          <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Classer les entreprises par</span>
          <Select value={criterionId} onChange={(e) => setCriterionId(e.target.value as CriterionId)}>
            {CRITERIA.map((c) => (
              <option key={c.id} value={c.id}>{c.label}</option>
            ))}
          </Select>
        </label>
        <p className="mt-2 text-sm text-gray-600 dark:text-gray-300">{criterion.help}</p>
        {unranked > 0 && (
          <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
            {unranked} entreprise{unranked > 1 ? 's' : ''} non classée{unranked > 1 ? 's' : ''} sur ce critère. {criterion.emptyHelp}
          </p>
        )}
      </Card>

      <Card title={criterion.label}>
        {chartData.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Aucune entreprise classable sur ce critère pour la période choisie. {criterion.emptyHelp}
          </p>
        ) : (
          <ResponsiveContainer width="100%" height={Math.max(220, 22 * chartData.length + 40)}>
            <BarChart data={chartData} layout="vertical" margin={{ top: 5, right: 40, bottom: 5, left: 10 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} horizontal={false} />
              <XAxis type="number" domain={criterion.axisDomain ?? [0, 'auto']} tick={{ fontSize: 10 }} tickFormatter={(v) => nf.format(v)} />
              <YAxis type="category" dataKey="symbol" width={70} tick={{ fontSize: 10 }} interval={0} />
              {criterion.axisDomain && criterion.axisDomain[1] === 100 && criterion.id !== 'facilite' && (
                <ReferenceLine x={50} stroke="var(--chart-muted)" strokeDasharray="4 4" />
              )}
              <Tooltip
                content={({ active, payload }) => {
                  if (!active || !payload?.length) return null
                  const d = payload[0].payload as (typeof chartData)[number]
                  const r = d.row
                  return (
                    <div className="max-w-xs rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                      <div className="font-medium">{r.symbol} — {r.name}</div>
                      <div className="mt-0.5">{criterion.label} : <strong>{criterion.format(d.value)}</strong></div>
                      <div className="mt-1 text-gray-500 dark:text-gray-400">
                        Facilité de vente : {r.liquidity_score ?? 'n/d'}/100 · échangé : {nf.format(r.executed_volume)} titres
                        sur {r.active_days} séance{r.active_days > 1 ? 's' : ''}
                        {r.sell_pressure_percent !== null && ` · pression vendeuse ${r.sell_pressure_percent}%`}
                        {r.avg_spread_percent !== null && ` · spread ${r.avg_spread_percent.toFixed(2)}%`}
                      </div>
                    </div>
                  )
                }}
              />
              <Bar dataKey="value" name={criterion.label}>
                {chartData.map((d) => (
                  <Cell key={d.symbol} fill={criterion.color(d.value)} />
                ))}
              </Bar>
            </BarChart>
          </ResponsiveContainer>
        )}
      </Card>

      <Card title="Tableau complet">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                <th className="pb-2 pr-3">#</th>
                <th className="pb-2 pr-3">Entreprise</th>
                <th className="pb-2 pr-3 text-right">{criterion.label}</th>
                <th className="pb-2 pr-3 text-right">Facilité de vente</th>
                <th className="pb-2 pr-3 text-right">Échangé</th>
                <th className="pb-2 pr-3 text-right">Pression vendeuse</th>
                <th className="pb-2 pr-3 text-right">En attente vente</th>
                <th className="pb-2 pr-3 text-right">En attente achat</th>
                <th className="pb-2 text-right">Spread moyen</th>
              </tr>
            </thead>
            <tbody>
              {ranked.map(({ row, value }, i) => (
                <tr key={row.company_id} className="border-t border-gray-100 dark:border-gray-800">
                  <td className="py-1.5 pr-3 tabular-nums text-gray-400">{i + 1}</td>
                  <td className="py-1.5 pr-3 whitespace-nowrap">
                    <span className="font-medium">{row.symbol}</span>
                    <span className="ml-1.5 text-xs text-gray-500 dark:text-gray-400">{row.name}</span>
                  </td>
                  <td className="py-1.5 pr-3 text-right font-semibold tabular-nums">{criterion.format(value)}</td>
                  <td className="py-1.5 pr-3 text-right tabular-nums">{row.liquidity_score ?? 'n/d'}</td>
                  <td className="py-1.5 pr-3 text-right tabular-nums">{nf.format(row.executed_volume)}</td>
                  <td className="py-1.5 pr-3 text-right tabular-nums">
                    {row.sell_pressure_percent !== null ? `${row.sell_pressure_percent}%` : '—'}
                  </td>
                  <td className="py-1.5 pr-3 text-right tabular-nums">
                    {row.avg_ask_qty !== null ? nf.format(row.avg_ask_qty) : '—'}
                  </td>
                  <td className="py-1.5 pr-3 text-right tabular-nums">
                    {row.avg_bid_qty !== null ? nf.format(row.avg_bid_qty) : '—'}
                  </td>
                  <td className="py-1.5 text-right tabular-nums">
                    {row.avg_spread_percent !== null ? `${row.avg_spread_percent.toFixed(2)}%` : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
          « — » et « n/d » : donnée non disponible sur la période (aucun carnet publié, côté du carnet vide, ou pas
          assez d'historique) — jamais une valeur de zéro déguisée. {query.data.note}
        </p>
      </Card>

      <ChartAiAnalysis
        chartType="liquidity_ranking"
        parameters={{ criterion: criterionId, start_date: startDate, end_date: endDate }}
        data={{ criterion: criterion.label, rows: ranked.slice(0, 25).map(({ row }) => row) }}
      />
    </div>
  )
}
