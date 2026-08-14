import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, Cell, ComposedChart, Line, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { callApi } from '../lib/apiClient'
import type { DividendCoverage, DividendRankingResult } from '../lib/types'
import { Card, ErrorState, LoadingState, StatTile } from './ui'
import { ChartAiAnalysis } from './ChartAiAnalysis'

/**
 * Onglet « Dividendes » du tableau de bord entreprise : l'historique des
 * versements de CETTE entreprise (graphe + tableau) et sa position parmi
 * tous les rendements du marché — pour répondre à « est-ce que ce titre
 * paie bien, comparé aux autres ? » sans quitter la fiche.
 */

interface CompanyHistoryResult {
  series: { event_date: string; amount: number; yield_percent: number | null; description: string | null }[]
  total_amount: number
  last_price: number | null
  payments: number
  coverage: DividendCoverage
  note: string
}

const nf = new Intl.NumberFormat('fr-FR')
const fmtF = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${nf.format(Math.round(v))} F`)

export function CompanyDividends({ companyId, symbol }: { companyId: number; symbol: string }) {
  const historyQuery = useQuery({
    queryKey: ['company-dividends', companyId],
    queryFn: () => callApi<CompanyHistoryResult>('api_dividends.php', 'company_history', { company_id: companyId, months: 60 }),
    enabled: !!companyId,
  })
  const marketQuery = useQuery({
    queryKey: ['dividend-ranking', 24],
    queryFn: () => callApi<DividendRankingResult>('api_dividends.php', 'ranking', { months: 24 }),
  })

  const history = historyQuery.data
  const market = marketQuery.data

  // Classement du marché avec l'entreprise courante mise en évidence.
  const marketChart = useMemo(
    () =>
      (market?.rows ?? [])
        .filter((r) => r.yield_percent !== null)
        .map((r) => ({ ...r, value: r.yield_percent as number, isCurrent: r.company_id === companyId })),
    [market, companyId],
  )
  const myRank = marketChart.findIndex((r) => r.isCurrent)
  const marketAvg = marketChart.length > 0 ? marketChart.reduce((s, r) => s + r.value, 0) / marketChart.length : null

  if (historyQuery.isLoading) return <LoadingState label="Chargement des dividendes…" />
  if (historyQuery.error) return <ErrorState message={(historyQuery.error as Error).message} />
  if (!history) return null

  const lastYield = history.series.length > 0 ? history.series[history.series.length - 1].yield_percent : null

  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <StatTile label="Versements connus" value={history.payments} />
        <StatTile label="Dernier rendement" value={lastYield !== null ? `${lastYield}%` : '—'} tone={lastYield !== null && lastYield >= 5 ? 'positive' : 'default'} />
        <StatTile label="Cumul par action" value={fmtF(history.total_amount)} />
        <StatTile
          label="Rang sur le marché"
          value={myRank >= 0 ? `${myRank + 1}e / ${marketChart.length}` : 'non classé'}
        />
      </div>

      <Card title={`Historique des dividendes de ${symbol}`}>
        {history.series.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Aucun dividende trouvé pour cette entreprise dans les {history.coverage.bulletins_processed} bulletins
            déjà analysés. Cela ne prouve pas qu'elle n'en verse pas : un versement annoncé dans un bulletin non
            encore traité n'apparaît pas ici.
          </p>
        ) : (
          <>
            <ResponsiveContainer width="100%" height={260}>
              <ComposedChart data={history.series} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                <XAxis dataKey="event_date" tick={{ fontSize: 10 }} />
                <YAxis yAxisId="amount" tick={{ fontSize: 10 }} width={70} tickFormatter={(v) => nf.format(v)} />
                <YAxis yAxisId="yield" orientation="right" tick={{ fontSize: 10 }} width={50} tickFormatter={(v) => `${v}%`} />
                <Tooltip
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const d = payload[0].payload as CompanyHistoryResult['series'][number]
                    return (
                      <div className="max-w-xs rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <div className="font-medium">Versement du {d.event_date}</div>
                        <div>Montant : <strong>{fmtF(d.amount)}</strong> par action</div>
                        <div>Rendement au cours actuel : {d.yield_percent !== null ? `${d.yield_percent}%` : '—'}</div>
                        {d.description && <div className="mt-1 text-gray-500 dark:text-gray-400">{d.description}</div>}
                      </div>
                    )
                  }}
                />
                <Bar yAxisId="amount" dataKey="amount" name="Montant par action" fill="var(--chart-1)" />
                <Line yAxisId="yield" dataKey="yield_percent" name="Rendement %" stroke="var(--chart-positive)" strokeWidth={2} dot={{ r: 3 }} connectNulls />
              </ComposedChart>
            </ResponsiveContainer>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              Barres : le montant versé par action à chaque date (échelle de gauche). Ligne verte : ce que ce
              montant représenterait aujourd'hui en % du cours actuel ({fmtF(history.last_price)}) — le rendement
              est donc recalculé au même cours pour tous les versements, ce qui permet de comparer leur poids
              entre eux. {history.note}
            </p>

            <div className="mt-3 overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                    <th className="pb-2 pr-3">Date de versement</th>
                    <th className="pb-2 pr-3 text-right">Montant / action</th>
                    <th className="pb-2 pr-3 text-right">Rendement</th>
                    <th className="pb-2 pr-3 text-right">Pour 1 M F investis</th>
                    <th className="pb-2">Détail de l'annonce</th>
                  </tr>
                </thead>
                <tbody>
                  {history.series.map((d, i) => (
                    <tr key={i} className="border-t border-gray-100 align-top dark:border-gray-800">
                      <td className="py-1.5 pr-3 whitespace-nowrap tabular-nums">{d.event_date}</td>
                      <td className="py-1.5 pr-3 text-right font-medium tabular-nums">{fmtF(d.amount)}</td>
                      <td className="py-1.5 pr-3 text-right tabular-nums">
                        {d.yield_percent !== null ? `${d.yield_percent}%` : '—'}
                      </td>
                      <td className="py-1.5 pr-3 text-right tabular-nums">
                        {d.yield_percent !== null ? `${nf.format(Math.round(d.yield_percent * 10000))} F` : '—'}
                      </td>
                      <td className="py-1.5 max-w-md text-xs text-gray-500 dark:text-gray-400">{d.description ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="border-t border-gray-300 font-semibold dark:border-gray-600">
                    <td className="py-1.5 pr-3">Total ({history.payments} versement{history.payments > 1 ? 's' : ''})</td>
                    <td className="py-1.5 pr-3 text-right tabular-nums">{fmtF(history.total_amount)}</td>
                    <td className="py-1.5 pr-3 text-right tabular-nums">
                      {history.last_price ? `${((history.total_amount / history.last_price) * 100).toFixed(2)}%` : '—'}
                    </td>
                    <td className="py-1.5 pr-3 text-right tabular-nums">
                      {history.last_price
                        ? `${nf.format(Math.round((history.total_amount / history.last_price) * 100 * 10000))} F`
                        : '—'}
                    </td>
                    <td />
                  </tr>
                </tfoot>
              </table>
            </div>
          </>
        )}
      </Card>

      <Card title="Tous les rendements du marché">
        {marketQuery.isLoading && <LoadingState />}
        {marketChart.length > 0 && (
          <>
            <ResponsiveContainer width="100%" height={Math.max(200, 24 * marketChart.length + 40)}>
              <BarChart data={marketChart} layout="vertical" margin={{ top: 5, right: 40, bottom: 5, left: 10 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} horizontal={false} />
                <XAxis type="number" tick={{ fontSize: 10 }} tickFormatter={(v) => `${v}%`} />
                <YAxis type="category" dataKey="symbol" width={70} tick={{ fontSize: 10 }} interval={0} />
                {marketAvg !== null && <ReferenceLine x={marketAvg} stroke="var(--chart-muted)" strokeDasharray="4 4" />}
                <Tooltip
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const r = payload[0].payload as (typeof marketChart)[number]
                    return (
                      <div className="rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <div className="font-medium">{r.symbol} — {r.name}{r.isCurrent && ' (entreprise affichée)'}</div>
                        <div>Rendement : <strong>{r.value}%</strong></div>
                        <div>Dernier dividende : {fmtF(r.last_amount)} le {r.last_date}</div>
                      </div>
                    )
                  }}
                />
                <Bar dataKey="value" name="Rendement %">
                  {marketChart.map((r) => (
                    <Cell
                      key={r.company_id}
                      fill={r.isCurrent ? 'var(--chart-1)' : 'var(--chart-soft)'}
                      fillOpacity={r.isCurrent ? 1 : 0.6}
                    />
                  ))}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              {symbol} apparaît en couleur, les autres entreprises en gris pour situer son rendement.
              {marketAvg !== null && ` La ligne pointillée est la moyenne du marché (${marketAvg.toFixed(2)}%).`}
              {myRank >= 0
                ? ` ${symbol} se classe ${myRank + 1}e sur ${marketChart.length} entreprises versant un dividende connu.`
                : ` ${symbol} n'a pas de dividende connu : elle n'apparaît pas dans ce classement.`}
            </p>
          </>
        )}
      </Card>

      <ChartAiAnalysis
        chartType="dividend_yield"
        parameters={{ company_id: companyId, months: 60 }}
        data={{ company: symbol, history: history.series, market_ranking: market?.rows ?? [], coverage: history.coverage }}
        companyIdsForReports={[companyId]}
      />
    </div>
  )
}
