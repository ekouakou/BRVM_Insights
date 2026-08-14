import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  LabelList,
  Line,
  LineChart,
  ReferenceLine,
  ResponsiveContainer,
  Scatter,
  ScatterChart,
  Tooltip,
  XAxis,
  YAxis,
  ZAxis,
} from 'recharts'
import { callApi } from '../lib/apiClient'
import type {
  Company,
  RiskReturnResult,
  VolatilityDistributionResult,
  VolatilityRankingResult,
  VolatilitySeriesResult,
} from '../lib/types'
import { Card, ErrorState, LoadingState, Select } from './ui'
import { ChartAiAnalysis } from './ChartAiAnalysis'

/**
 * Graphes de volatilité (api_volatility.php) : ce que l'application
 * n'affichait qu'en tuiles et tableaux devient visuel — classement,
 * volatilité glissante, courbe de drawdown, nuage risque/rendement et
 * distribution des variations quotidiennes.
 *
 * La fiabilité dépend du nombre de séances disponibles : chaque bloc
 * signale explicitement un historique trop court plutôt que d'afficher un
 * chiffre fragile comme une vérité.
 */

const nf = new Intl.NumberFormat('fr-FR')
const pct = (v: number | null | undefined, digits = 1) =>
  v === null || v === undefined ? '—' : `${v > 0 ? '' : ''}${v.toFixed(digits)}%`

export function VolatilityCharts({
  companies,
  selectedIds,
  colorFor,
  startDate,
  endDate,
}: {
  companies: Company[]
  selectedIds: number[]
  colorFor: (companyId: number, selection?: number[]) => string
  startDate: string
  endDate: string
}) {
  const [window, setWindow] = useState(20)
  const [distributionId, setDistributionId] = useState<number | null>(null)

  const rankingQuery = useQuery({
    queryKey: ['vol-ranking', startDate, endDate],
    queryFn: () => callApi<VolatilityRankingResult>('api_volatility.php', 'ranking', { start_date: startDate, end_date: endDate }),
    enabled: startDate <= endDate,
  })
  const riskReturnQuery = useQuery({
    queryKey: ['vol-risk-return', startDate, endDate],
    queryFn: () => callApi<RiskReturnResult>('api_volatility.php', 'risk_return', { start_date: startDate, end_date: endDate }),
    enabled: startDate <= endDate,
  })
  const seriesQuery = useQuery({
    queryKey: ['vol-series', [...selectedIds].sort((a, b) => a - b), startDate, endDate, window],
    queryFn: () =>
      callApi<VolatilitySeriesResult>('api_volatility.php', 'series', {
        company_ids: selectedIds,
        start_date: startDate,
        end_date: endDate,
        window,
      }),
    enabled: selectedIds.length > 0 && startDate <= endDate,
  })

  const effectiveDistributionId = distributionId ?? selectedIds[0] ?? null
  const distributionQuery = useQuery({
    queryKey: ['vol-distribution', effectiveDistributionId, startDate, endDate],
    queryFn: () =>
      callApi<VolatilityDistributionResult>('api_volatility.php', 'distribution', {
        company_id: effectiveDistributionId,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!effectiveDistributionId && startDate <= endDate,
  })

  const symbolOf = (id: number) => companies.find((c) => c.company_id === id)?.symbol ?? String(id)

  // Séries fusionnées par date : vol_<id> et dd_<id>.
  const merged = useMemo(() => {
    const byDate = new Map<string, Record<string, number | string | null>>()
    for (const s of seriesQuery.data?.series ?? []) {
      for (const p of s.points) {
        const row = byDate.get(p.date) ?? { date: p.date }
        row[`vol_${s.company_id}`] = p.rolling_volatility_percent
        row[`dd_${s.company_id}`] = p.drawdown_percent
        byDate.set(p.date, row)
      }
    }
    return [...byDate.values()].sort((a, b) => String(a.date).localeCompare(String(b.date)))
  }, [seriesQuery.data])

  if (rankingQuery.isLoading) return <LoadingState label="Calcul de la volatilité…" />
  if (rankingQuery.error) return <ErrorState message={(rankingQuery.error as Error).message} />
  const ranking = rankingQuery.data
  if (!ranking) return null

  const rankChart = ranking.rows.filter((r) => r.annualized_volatility_percent !== null)
  const anyLowConfidence = ranking.rows.some((r) => r.low_confidence)
  const scatter = (riskReturnQuery.data?.points ?? []).filter((p) => p.return_percent !== null)

  return (
    <div className="flex flex-col gap-4">
      {anyLowConfidence && (
        <div className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">
          <strong>Historique court.</strong> Ces calculs reposent sur {ranking.rows[0]?.returns_count ?? 0} variations
          quotidiennes seulement (il en faudrait au moins {ranking.min_reliable_returns} pour une mesure solide). Les
          chiffres sont affichés parce qu'ils décrivent bien la période, mais un seul jour agité peut les faire
          bondir — ils se fiabiliseront à mesure que l'historique s'allonge.
        </div>
      )}

      <Card title="Classement par volatilité annualisée (calculé)">
        {rankChart.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Aucune entreprise n'a assez de séances sur la période pour calculer une volatilité.
          </p>
        ) : (
          <>
            <ResponsiveContainer width="100%" height={Math.max(220, 22 * rankChart.length + 40)}>
              <BarChart data={rankChart} layout="vertical" margin={{ top: 5, right: 45, bottom: 5, left: 10 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} horizontal={false} />
                <XAxis type="number" tick={{ fontSize: 10 }} tickFormatter={(v) => `${v}%`} />
                <YAxis type="category" dataKey="symbol" width={70} tick={{ fontSize: 10 }} interval={0} />
                <Tooltip
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const r = payload[0].payload as (typeof rankChart)[number]
                    return (
                      <div className="max-w-xs rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <div className="font-medium">{r.symbol} — {r.name}</div>
                        <div className="mt-0.5">Volatilité annualisée : <strong>{pct(r.annualized_volatility_percent)}</strong></div>
                        <div>Variation quotidienne typique : ±{pct(r.daily_volatility_percent, 2)}</div>
                        <div>Écart haut-bas moyen dans la séance : {pct(r.avg_amplitude_percent, 2)}</div>
                        <div>Pire recul depuis un sommet : {pct(r.max_drawdown_percent)}</div>
                        <div>Performance sur la période : {pct(r.net_return_percent)}</div>
                        <div className="mt-1 text-gray-500 dark:text-gray-400">
                          Calculé sur {r.returns_count} variation{r.returns_count > 1 ? 's' : ''} quotidienne
                          {r.returns_count > 1 ? 's' : ''}{r.low_confidence && ' — historique court, chiffre fragile'}
                        </div>
                      </div>
                    )
                  }}
                />
                <Bar dataKey="annualized_volatility_percent" name="Volatilité annualisée">
                  {rankChart.map((r) => {
                    const v = r.annualized_volatility_percent ?? 0
                    return (
                      <Cell
                        key={r.company_id}
                        fill={v >= 60 ? 'var(--chart-negative)' : v >= 30 ? 'var(--chart-2)' : 'var(--chart-positive)'}
                        fillOpacity={r.low_confidence ? 0.55 : 1}
                      />
                    )
                  })}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              La volatilité mesure l'<strong>agitation du cours</strong> : à quel point il monte et descend d'un jour
              à l'autre. Une volatilité annualisée de 30 % signifie qu'en un an, le cours pourrait raisonnablement
              s'écarter de ±30 % de sa trajectoire moyenne. Vert : calme (moins de 30 %) · orange : moyen ·
              rouge : agité (plus de 60 %). Barres pâles = calcul sur un historique court. {ranking.note}
            </p>
          </>
        )}
      </Card>

      <Card title="Risque contre rendement (calculé)">
        {riskReturnQuery.isLoading && <LoadingState />}
        {scatter.length > 0 && (
          <>
            <ResponsiveContainer width="100%" height={340}>
              <ScatterChart margin={{ top: 10, right: 20, bottom: 20, left: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                <XAxis
                  type="number"
                  dataKey="volatility_percent"
                  name="Volatilité"
                  tick={{ fontSize: 10 }}
                  tickFormatter={(v) => `${v}%`}
                  label={{ value: 'Agitation du cours →', position: 'insideBottom', offset: -10, fontSize: 11 }}
                />
                <YAxis
                  type="number"
                  dataKey="return_percent"
                  name="Performance"
                  tick={{ fontSize: 10 }}
                  tickFormatter={(v) => `${v}%`}
                  label={{ value: 'Performance ↑', angle: -90, position: 'insideLeft', fontSize: 11 }}
                />
                <ZAxis range={[60, 60]} />
                <ReferenceLine y={0} stroke="var(--chart-muted)" />
                <Tooltip
                  cursor={{ strokeDasharray: '3 3' }}
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const p = payload[0].payload as (typeof scatter)[number]
                    return (
                      <div className="max-w-xs rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <div className="font-medium">{p.symbol} — {p.name}</div>
                        <div>Performance : {pct(p.return_percent)}</div>
                        <div>Volatilité : {pct(p.volatility_percent)}</div>
                        <div>Pire recul : {pct(p.max_drawdown_percent)}</div>
                        <div className="mt-1">
                          Gain par unité de risque : <strong>{p.return_per_risk ?? '—'}</strong>
                          {p.return_per_risk !== null && p.return_per_risk > 0.3 && ' — bon compromis'}
                          {p.return_per_risk !== null && p.return_per_risk < 0 && ' — perte malgré le risque pris'}
                        </div>
                      </div>
                    )
                  }}
                />
                <Scatter data={scatter} name="Entreprises">
                  {scatter.map((p) => (
                    <Cell
                      key={p.company_id}
                      fill={selectedIds.includes(p.company_id) ? colorFor(p.company_id, selectedIds) : 'var(--chart-soft)'}
                      fillOpacity={selectedIds.includes(p.company_id) ? 1 : 0.55}
                    />
                  ))}
                  <LabelList dataKey="symbol" position="top" style={{ fontSize: 9, fill: 'currentColor' }} />
                </Scatter>
              </ScatterChart>
            </ResponsiveContainer>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              Chaque point est une entreprise. <strong>En haut à gauche</strong> = le meilleur compromis : ça a
              monté sans trop de secousses. <strong>En bas à droite</strong> = beaucoup d'agitation pour une
              performance décevante. Les entreprises que tu as sélectionnées ci-dessus sont en couleur, les autres
              en gris pour le contexte. {riskReturnQuery.data?.note}
            </p>
          </>
        )}
      </Card>

      {selectedIds.length > 0 && (
        <>
          <Card title="Volatilité glissante (calculée)">
            <label className="mb-2 block w-48">
              <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Fenêtre de calcul</span>
              <Select value={String(window)} onChange={(e) => setWindow(Number(e.target.value))}>
                <option value="5">5 séances (très réactif)</option>
                <option value="10">10 séances</option>
                <option value="20">20 séances (standard)</option>
                <option value="60">60 séances (tendance de fond)</option>
              </Select>
            </label>
            {seriesQuery.isLoading && <LoadingState />}
            {seriesQuery.data && merged.some((m) => Object.keys(m).some((k) => k.startsWith('vol_') && m[k] !== null)) ? (
              <>
                <ResponsiveContainer width="100%" height={250}>
                  <LineChart data={merged} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                    <XAxis dataKey="date" tick={{ fontSize: 10 }} tickFormatter={(d: string) => d.slice(5)} />
                    <YAxis tick={{ fontSize: 10 }} width={50} tickFormatter={(v) => `${v}%`} />
                    <Tooltip
                      content={({ active, payload, label }) => {
                        if (!active || !payload?.length) return null
                        return (
                          <div className="rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                            <div className="font-medium">{label}</div>
                            {payload
                              .filter((p) => p.value !== null && p.value !== undefined)
                              .map((p) => (
                                <div key={String(p.dataKey)} style={{ color: p.color }}>
                                  {symbolOf(Number(String(p.dataKey).slice(4)))} : {Number(p.value).toFixed(1)}%
                                </div>
                              ))}
                          </div>
                        )
                      }}
                    />
                    {selectedIds.map((id) => (
                      <Line key={id} dataKey={`vol_${id}`} name={symbolOf(id)} stroke={colorFor(id, selectedIds)} dot={false} strokeWidth={1.5} connectNulls />
                    ))}
                  </LineChart>
                </ResponsiveContainer>
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                  Volatilité recalculée à chaque séance sur les {window} dernières. Une courbe qui monte = le titre
                  devient plus nerveux (souvent autour d'une publication de résultats ou d'une annonce) ; une courbe
                  qui descend = le calme revient. La courbe ne démarre qu'à la {window}e séance : avant, il n'y a pas
                  assez de données pour un calcul honnête.
                </p>
              </>
            ) : (
              seriesQuery.data && (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  Pas encore assez de séances sur la période pour une fenêtre de {window}. Choisis une fenêtre plus
                  courte, ou élargis la période en haut de page.
                </p>
              )
            )}
          </Card>

          <Card title="Recul depuis le plus haut — courbe « sous l'eau » (calculée)">
            {seriesQuery.data && (
              <>
                <ResponsiveContainer width="100%" height={230}>
                  <AreaChart data={merged} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                    <XAxis dataKey="date" tick={{ fontSize: 10 }} tickFormatter={(d: string) => d.slice(5)} />
                    <YAxis tick={{ fontSize: 10 }} width={50} tickFormatter={(v) => `${v}%`} />
                    <Tooltip
                      content={({ active, payload, label }) => {
                        if (!active || !payload?.length) return null
                        return (
                          <div className="rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                            <div className="font-medium">{label}</div>
                            {payload
                              .filter((p) => p.value !== null && p.value !== undefined)
                              .map((p) => {
                                const v = Number(p.value)
                                return (
                                  <div key={String(p.dataKey)} style={{ color: p.color }}>
                                    {symbolOf(Number(String(p.dataKey).slice(3)))} :{' '}
                                    {v === 0 ? 'au plus haut' : `${v.toFixed(1)}% sous le plus haut`}
                                  </div>
                                )
                              })}
                          </div>
                        )
                      }}
                    />
                    <ReferenceLine y={0} stroke="var(--chart-muted)" />
                    {selectedIds.map((id) => (
                      <Area
                        key={id}
                        dataKey={`dd_${id}`}
                        name={symbolOf(id)}
                        stroke={colorFor(id, selectedIds)}
                        fill={colorFor(id, selectedIds)}
                        fillOpacity={0.15}
                        strokeWidth={1.5}
                        connectNulls
                      />
                    ))}
                  </AreaChart>
                </ResponsiveContainer>
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                  Cette courbe répond à : « si j'avais acheté au pire moment, combien serais-je en perte
                  aujourd'hui ? ». Elle vaut 0 % quand le titre est à son plus haut de la période, et plonge quand il
                  recule. Plus la courbe reste longtemps sous zéro, plus il faut de patience pour se refaire.
                </p>
              </>
            )}
          </Card>

          <Card title="Distribution des variations quotidiennes (calculée)">
            <label className="mb-2 block w-64">
              <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Entreprise</span>
              <Select
                value={String(effectiveDistributionId ?? '')}
                onChange={(e) => setDistributionId(e.target.value ? Number(e.target.value) : null)}
              >
                {selectedIds.map((id) => (
                  <option key={id} value={String(id)}>{symbolOf(id)}</option>
                ))}
              </Select>
            </label>
            {distributionQuery.isLoading && <LoadingState />}
            {distributionQuery.data && distributionQuery.data.bins.length > 0 ? (
              <>
                <ResponsiveContainer width="100%" height={230}>
                  <BarChart
                    data={distributionQuery.data.bins.map((b) => ({ ...b, label: `${b.from.toFixed(1)} à ${b.to.toFixed(1)}%` }))}
                    margin={{ top: 5, right: 10, bottom: 5, left: 0 }}
                  >
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                    <XAxis dataKey="label" tick={{ fontSize: 9 }} interval={0} angle={-25} textAnchor="end" height={50} />
                    <YAxis tick={{ fontSize: 10 }} width={35} allowDecimals={false} />
                    <Tooltip
                      content={({ active, payload }) => {
                        if (!active || !payload?.length) return null
                        const b = payload[0].payload as { label: string; count: number }
                        return (
                          <div className="rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                            <div>{b.count} séance{b.count > 1 ? 's' : ''} avec une variation de {b.label}</div>
                          </div>
                        )
                      }}
                    />
                    <Bar dataKey="count" name="Nombre de séances">
                      {distributionQuery.data.bins.map((b, i) => (
                        <Cell key={i} fill={b.to <= 0 ? 'var(--chart-negative)' : b.from >= 0 ? 'var(--chart-positive)' : 'var(--chart-soft)'} />
                      ))}
                    </Bar>
                  </BarChart>
                </ResponsiveContainer>
                <div className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                  <p>
                    Chaque barre compte les séances dont la variation tombe dans cette tranche : à gauche les jours de
                    baisse (rouge), à droite les jours de hausse (vert). Une distribution large = un titre imprévisible.
                  </p>
                  <p className="mt-1">
                    Pire séance : <strong>{pct(distributionQuery.data.worst_day?.variation_percent, 2)}</strong>
                    {distributionQuery.data.worst_day && ` le ${distributionQuery.data.worst_day.date}`} · Meilleure :{' '}
                    <strong>{pct(distributionQuery.data.best_day?.variation_percent, 2)}</strong>
                    {distributionQuery.data.best_day && ` le ${distributionQuery.data.best_day.date}`}
                    {distributionQuery.data.var_percent !== null && (
                      <> · Perte maximale attendue en un jour (hors cas exceptionnels) :{' '}
                        <strong>{pct(distributionQuery.data.var_percent, 2)}</strong></>
                    )}
                  </p>
                  {distributionQuery.data.var_percent === null && (
                    <p className="mt-1">
                      La perte maximale attendue (VaR) n'est pas calculable : elle demande davantage de séances que
                      l'historique actuel.
                    </p>
                  )}
                </div>
              </>
            ) : (
              distributionQuery.data && (
                <p className="text-sm text-gray-500 dark:text-gray-400">{distributionQuery.data.note}</p>
              )
            )}
          </Card>
        </>
      )}

      <ChartAiAnalysis
        chartType="volatility_analysis"
        parameters={{ start_date: startDate, end_date: endDate, window, company_ids: [...selectedIds].sort((a, b) => a - b) }}
        data={{ ranking: ranking.rows, risk_return: scatter, series: seriesQuery.data?.series ?? [] }}
        companyIdsForReports={selectedIds}
      />
    </div>
  )
}
