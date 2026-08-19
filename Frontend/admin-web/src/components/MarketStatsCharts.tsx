import { useMemo } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, ComposedChart, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { callApi } from '../lib/apiClient'
import type { MarketStatsListResult } from '../lib/types'
import { Button, Card, ErrorState, LoadingState, StatTile } from './ui'
import { ChartAiAnalysis } from './ChartAiAnalysis'

/**
 * Graphes des « Statistiques du marché » de chaque Bulletin Officiel de la
 * Cote — Actions et Obligations, capitalisation, volume/valeur transigés,
 * compteurs de titres transigés/en hausse/en baisse/inchangés. Source unique
 * = extraction déterministe (regex, pas d'IA) côté BulletinMarketStatsService,
 * même philosophie que DividendCharts/VolatilityCharts : composant autonome
 * avec sa propre requête, piloté par les dates passées en props.
 */

const SHORT_MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.']
/** Date courte JJ mmm AA (2026-08-06 → 06 août 26), même format que les bulletins papier. */
function frShortDate(iso: string): string {
  const [y, m, d] = iso.split('-')
  return `${d} ${SHORT_MONTHS[Number(m) - 1] ?? m} ${y.slice(2)}`
}

function fmtNum(value: number | string | null | undefined, digits = 2): string {
  if (value === null || value === undefined) return '—'
  const num = typeof value === 'string' ? parseFloat(value) : value
  if (Number.isNaN(num)) return '—'
  return num.toLocaleString('fr-FR', { minimumFractionDigits: digits, maximumFractionDigits: digits })
}

function toNum(value: number | string | null): number | null {
  if (value === null) return null
  const num = typeof value === 'string' ? parseFloat(value) : value
  return Number.isNaN(num) ? null : num
}

export function MarketStatsCharts({ startDate, endDate, enabled }: { startDate: string; endDate: string; enabled: boolean }) {
  const queryClient = useQueryClient()

  const marketStatsQuery = useQuery({
    queryKey: ['market-stats', startDate, endDate],
    queryFn: () => callApi<MarketStatsListResult>('api_bulletin_market_stats.php', 'list', { start_date: startDate, end_date: endDate }),
    enabled,
  })

  const parseMarketStatsMutation = useMutation({
    mutationFn: () => callApi('api_bulletin_market_stats.php', 'extract_all', {}),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['market-stats'] })
    },
  })

  const chartData = useMemo(() => {
    const days = marketStatsQuery.data?.days ?? []
    return [...days]
      .sort((a, b) => a.publish_date.localeCompare(b.publish_date))
      .map((d) => ({
        date: frShortDate(d.publish_date),
        publish_date: d.publish_date,
        bulletin_title: d.bulletin_title,
        actions_capitalization_billions: (toNum(d.actions_capitalization) ?? 0) / 1_000_000_000,
        obligations_capitalization_billions: (toNum(d.obligations_capitalization) ?? 0) / 1_000_000_000,
        actions_volume: toNum(d.actions_volume),
        actions_value_millions: toNum(d.actions_value_traded) !== null ? (toNum(d.actions_value_traded) as number) / 1_000_000 : null,
        obligations_volume: toNum(d.obligations_volume),
        obligations_value_millions: toNum(d.obligations_value_traded) !== null ? (toNum(d.obligations_value_traded) as number) / 1_000_000 : null,
        actions_titles_up: toNum(d.actions_titles_up) ?? 0,
        actions_titles_down: toNum(d.actions_titles_down) ?? 0,
        actions_titles_unchanged: toNum(d.actions_titles_unchanged) ?? 0,
        obligations_titles_up: toNum(d.obligations_titles_up) ?? 0,
        obligations_titles_down: toNum(d.obligations_titles_down) ?? 0,
        obligations_titles_unchanged: toNum(d.obligations_titles_unchanged) ?? 0,
        per_moyen_marche: toNum(d.per_moyen_marche),
        taux_rendement_moyen: toNum(d.taux_rendement_moyen),
        taux_rentabilite_moyen: toNum(d.taux_rentabilite_moyen),
        ratio_moyen_liquidite: toNum(d.ratio_moyen_liquidite),
        ratio_moyen_satisfaction: toNum(d.ratio_moyen_satisfaction),
        ratio_moyen_tendance: toNum(d.ratio_moyen_tendance),
        ratio_moyen_couverture: toNum(d.ratio_moyen_couverture),
        taux_rotation_moyen: toNum(d.taux_rotation_moyen),
        prime_risque_marche: toNum(d.prime_risque_marche),
        nombre_societes_cotees: toNum(d.nombre_societes_cotees),
        nombre_lignes_obligataires: toNum(d.nombre_lignes_obligataires),
        nombre_sgi_participantes: toNum(d.nombre_sgi_participantes),
        volume_moyen_annuel_seance: toNum(d.volume_moyen_annuel_seance),
        valeur_moyenne_annuelle_seance: toNum(d.valeur_moyenne_annuelle_seance),
      }))
  }, [marketStatsQuery.data])

  const lastIndicators = chartData[chartData.length - 1] ?? null

  return (
    <>
      {!!marketStatsQuery.data?.pending_count && (
        <Card>
          <div className="flex items-center justify-between gap-3">
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">
              {marketStatsQuery.data.pending_count} bulletin(s) sans statistiques marché extraites
            </h3>
            <Button variant="secondary" disabled={parseMarketStatsMutation.isPending} onClick={() => parseMarketStatsMutation.mutate()}>
              {parseMarketStatsMutation.isPending ? 'Extraction…' : 'Extraire les statistiques manquantes'}
            </Button>
          </div>
          <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
            Extraction déterministe (lecture directe du tableau « Statistiques du marché » du bulletin, pas d'IA) —
            traite tous les bulletins en attente en un seul clic.
          </p>
          {parseMarketStatsMutation.isError && (
            <p className="mt-2 text-xs text-red-600 dark:text-red-400">{(parseMarketStatsMutation.error as Error).message}</p>
          )}
        </Card>
      )}

      {marketStatsQuery.isLoading && <LoadingState label="Chargement…" />}
      {marketStatsQuery.error && <ErrorState message={(marketStatsQuery.error as Error).message} />}

      {marketStatsQuery.data && chartData.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Aucune statistique marché extraite sur cette période — extrais les bulletins en attente ci-dessus.
        </p>
      )}

      {chartData.length > 0 && (
        <>
          <Card title="Capitalisation boursière (Actions vs Obligations)">
            <ResponsiveContainer width="100%" height={280}>
              <LineChart data={chartData} margin={{ top: 5, right: 20, bottom: 5, left: 10 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                <XAxis dataKey="date" tick={{ fontSize: 10 }} minTickGap={20} />
                <YAxis
                  tick={{ fontSize: 10 }}
                  width={60}
                  label={{ value: 'Milliards FCFA', angle: -90, position: 'insideLeft', style: { fontSize: 10, fill: 'var(--chart-muted)' } }}
                  tickFormatter={(v) => fmtNum(v, 0)}
                />
                <Tooltip
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const p = payload[0].payload as (typeof chartData)[number]
                    return (
                      <div className="rounded-md border border-gray-200 bg-white px-3 py-2 text-xs shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <div className="mb-1 font-medium">{p.date}</div>
                        <div>
                          Actions : <strong>{fmtNum(p.actions_capitalization_billions, 0)}</strong> Mds FCFA
                        </div>
                        <div>
                          Obligations : <strong>{fmtNum(p.obligations_capitalization_billions, 0)}</strong> Mds FCFA
                        </div>
                      </div>
                    )
                  }}
                />
                <Legend wrapperStyle={{ fontSize: 11 }} />
                <Line type="monotone" dataKey="actions_capitalization_billions" name="Actions" stroke="var(--chart-1)" strokeWidth={2.5} dot={{ r: 3 }} connectNulls />
                <Line
                  type="monotone"
                  dataKey="obligations_capitalization_billions"
                  name="Obligations"
                  stroke="var(--chart-warning)"
                  strokeWidth={2.5}
                  dot={{ r: 3 }}
                  connectNulls
                />
              </LineChart>
            </ResponsiveContainer>
          </Card>

          {(
            [
              { key: 'actions', label: 'Actions', volumeKey: 'actions_volume', valueKey: 'actions_value_millions' },
              { key: 'obligations', label: 'Obligations', volumeKey: 'obligations_volume', valueKey: 'obligations_value_millions' },
            ] as const
          ).map((side) => (
            <Card key={side.key} title={`Volumes et valeurs transigés — ${side.label}`}>
              <ResponsiveContainer width="100%" height={280}>
                <ComposedChart data={chartData} margin={{ top: 5, right: 20, bottom: 5, left: 10 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                  <XAxis dataKey="date" tick={{ fontSize: 10 }} minTickGap={20} />
                  <YAxis
                    yAxisId="volume"
                    tick={{ fontSize: 10 }}
                    width={70}
                    label={{ value: 'Volume en titres', angle: -90, position: 'insideLeft', style: { fontSize: 10, fill: 'var(--chart-muted)' } }}
                    tickFormatter={(v) => fmtNum(v, 0)}
                  />
                  <YAxis
                    yAxisId="value"
                    orientation="right"
                    tick={{ fontSize: 10 }}
                    width={60}
                    label={{ value: 'Valeur en millions FCFA', angle: 90, position: 'insideRight', style: { fontSize: 10, fill: 'var(--chart-muted)' } }}
                    tickFormatter={(v) => fmtNum(v, 0)}
                  />
                  <Tooltip
                    content={({ active, payload }) => {
                      if (!active || !payload?.length) return null
                      const p = payload[0].payload as (typeof chartData)[number]
                      return (
                        <div className="rounded-md border border-gray-200 bg-white px-3 py-2 text-xs shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                          <div className="mb-1 font-medium">{p.date}</div>
                          <div>
                            Volume : <strong>{fmtNum(p[side.volumeKey], 0)}</strong> titres
                          </div>
                          <div>
                            Valeur : <strong>{fmtNum(p[side.valueKey], 0)}</strong> millions FCFA
                          </div>
                          {p.bulletin_title && <div className="mt-1 text-gray-500 dark:text-gray-400">{p.bulletin_title}</div>}
                        </div>
                      )
                    }}
                  />
                  <Legend wrapperStyle={{ fontSize: 11 }} />
                  <Bar yAxisId="volume" dataKey={side.volumeKey} name="Volume" fill="var(--chart-1)" />
                  <Line yAxisId="value" type="monotone" dataKey={side.valueKey} name="Valeur" stroke="var(--chart-warning)" strokeWidth={2.5} dot={{ r: 4 }} connectNulls />
                </ComposedChart>
              </ResponsiveContainer>
            </Card>
          ))}

          {(
            [
              { key: 'actions', label: 'Actions', upKey: 'actions_titles_up', downKey: 'actions_titles_down', unchangedKey: 'actions_titles_unchanged' },
              {
                key: 'obligations',
                label: 'Obligations',
                upKey: 'obligations_titles_up',
                downKey: 'obligations_titles_down',
                unchangedKey: 'obligations_titles_unchanged',
              },
            ] as const
          ).map((side) => (
            <Card key={side.key} title={`Titres transigés — ${side.label}`}>
              <ResponsiveContainer width="100%" height={260}>
                <BarChart data={chartData} margin={{ top: 5, right: 20, bottom: 5, left: 10 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                  <XAxis dataKey="date" tick={{ fontSize: 10 }} minTickGap={20} />
                  <YAxis tick={{ fontSize: 10 }} width={40} allowDecimals={false} />
                  <Tooltip
                    content={({ active, payload }) => {
                      if (!active || !payload?.length) return null
                      const p = payload[0].payload as (typeof chartData)[number]
                      const total = p[side.upKey] + p[side.downKey] + p[side.unchangedKey]
                      return (
                        <div className="rounded-md border border-gray-200 bg-white px-3 py-2 text-xs shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                          <div className="mb-1 font-medium">{p.date}</div>
                          <div>
                            En hausse : <strong>{fmtNum(p[side.upKey], 0)}</strong>
                          </div>
                          <div>
                            En baisse : <strong>{fmtNum(p[side.downKey], 0)}</strong>
                          </div>
                          <div>
                            Inchangés : <strong>{fmtNum(p[side.unchangedKey], 0)}</strong>
                          </div>
                          <div className="mt-1 text-gray-500 dark:text-gray-400">Total transigés (calculé) : {fmtNum(total, 0)}</div>
                        </div>
                      )
                    }}
                  />
                  <Legend wrapperStyle={{ fontSize: 11 }} />
                  <Bar stackId="titres" dataKey={side.upKey} name="En hausse" fill="var(--chart-positive)" />
                  <Bar stackId="titres" dataKey={side.downKey} name="En baisse" fill="var(--chart-negative)" />
                  <Bar stackId="titres" dataKey={side.unchangedKey} name="Inchangés" fill="var(--chart-muted)" />
                </BarChart>
              </ResponsiveContainer>
            </Card>
          ))}

          <Card title="PER, rendement et rentabilité moyens du marché (BRVM COMPOSITE)">
            <ResponsiveContainer width="100%" height={260}>
              <ComposedChart data={chartData} margin={{ top: 5, right: 20, bottom: 5, left: 10 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                <XAxis dataKey="date" tick={{ fontSize: 10 }} minTickGap={20} />
                <YAxis yAxisId="per" tick={{ fontSize: 10 }} width={40} tickFormatter={(v) => fmtNum(v, 1)} />
                <YAxis yAxisId="percent" orientation="right" tick={{ fontSize: 10 }} width={40} tickFormatter={(v) => `${fmtNum(v, 1)}%`} />
                <Tooltip
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const p = payload[0].payload as (typeof chartData)[number]
                    return (
                      <div className="rounded-md border border-gray-200 bg-white px-3 py-2 text-xs shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <div className="mb-1 font-medium">{p.date}</div>
                        <div>PER moyen : <strong>{fmtNum(p.per_moyen_marche)}</strong></div>
                        <div>Rendement moyen : <strong>{fmtNum(p.taux_rendement_moyen)}</strong> %</div>
                        <div>Rentabilité moyenne : <strong>{fmtNum(p.taux_rentabilite_moyen)}</strong> %</div>
                        {p.bulletin_title && <div className="mt-1 text-gray-500 dark:text-gray-400">{p.bulletin_title}</div>}
                      </div>
                    )
                  }}
                />
                <Legend wrapperStyle={{ fontSize: 11 }} />
                <Line yAxisId="per" type="monotone" dataKey="per_moyen_marche" name="PER moyen" stroke="var(--chart-1)" strokeWidth={2.5} dot={{ r: 3 }} connectNulls />
                <Line yAxisId="percent" type="monotone" dataKey="taux_rendement_moyen" name="Rendement moyen (%)" stroke="var(--chart-positive)" strokeWidth={2} dot={{ r: 3 }} connectNulls />
                <Line yAxisId="percent" type="monotone" dataKey="taux_rentabilite_moyen" name="Rentabilité moyenne (%)" stroke="var(--chart-warning)" strokeWidth={2} dot={{ r: 3 }} connectNulls />
              </ComposedChart>
            </ResponsiveContainer>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              PER sur l'échelle de gauche, rendement/rentabilité (en %) sur l'échelle de droite — trois indicateurs
              synthétiques du tableau « Indicateurs du marché » de chaque bulletin, calculés par la BRVM sur le
              BRVM COMPOSITE.
            </p>
          </Card>

          <Card title="Ratios moyens du marché">
            <ResponsiveContainer width="100%" height={260}>
              <LineChart data={chartData} margin={{ top: 5, right: 20, bottom: 5, left: 10 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                <XAxis dataKey="date" tick={{ fontSize: 10 }} minTickGap={20} />
                <YAxis tick={{ fontSize: 10 }} width={40} tickFormatter={(v) => fmtNum(v, 0)} />
                <Tooltip
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const p = payload[0].payload as (typeof chartData)[number]
                    return (
                      <div className="rounded-md border border-gray-200 bg-white px-3 py-2 text-xs shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <div className="mb-1 font-medium">{p.date}</div>
                        <div>Liquidité : <strong>{fmtNum(p.ratio_moyen_liquidite)}</strong></div>
                        <div>Satisfaction : <strong>{fmtNum(p.ratio_moyen_satisfaction)}</strong></div>
                        <div>Tendance : <strong>{fmtNum(p.ratio_moyen_tendance)}</strong></div>
                        <div>Couverture : <strong>{fmtNum(p.ratio_moyen_couverture)}</strong></div>
                        {p.bulletin_title && <div className="mt-1 text-gray-500 dark:text-gray-400">{p.bulletin_title}</div>}
                      </div>
                    )
                  }}
                />
                <Legend wrapperStyle={{ fontSize: 11 }} />
                <Line type="monotone" dataKey="ratio_moyen_liquidite" name="Liquidité" stroke="var(--chart-1)" strokeWidth={2} dot={{ r: 2 }} connectNulls />
                <Line type="monotone" dataKey="ratio_moyen_satisfaction" name="Satisfaction" stroke="var(--chart-positive)" strokeWidth={2} dot={{ r: 2 }} connectNulls />
                <Line type="monotone" dataKey="ratio_moyen_tendance" name="Tendance" stroke="var(--chart-warning)" strokeWidth={2} dot={{ r: 2 }} connectNulls />
                <Line type="monotone" dataKey="ratio_moyen_couverture" name="Couverture" stroke="var(--chart-negative)" strokeWidth={2} dot={{ r: 2 }} connectNulls />
              </LineChart>
            </ResponsiveContainer>
          </Card>

          <Card title="Taux de rotation et prime de risque du marché">
            <ResponsiveContainer width="100%" height={220}>
              <LineChart data={chartData} margin={{ top: 5, right: 20, bottom: 5, left: 10 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                <XAxis dataKey="date" tick={{ fontSize: 10 }} minTickGap={20} />
                <YAxis tick={{ fontSize: 10 }} width={40} tickFormatter={(v) => `${fmtNum(v, 2)}%`} />
                <Tooltip
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const p = payload[0].payload as (typeof chartData)[number]
                    return (
                      <div className="rounded-md border border-gray-200 bg-white px-3 py-2 text-xs shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <div className="mb-1 font-medium">{p.date}</div>
                        <div>Taux de rotation moyen : <strong>{fmtNum(p.taux_rotation_moyen)}</strong> %</div>
                        <div>Prime de risque du marché : <strong>{fmtNum(p.prime_risque_marche)}</strong> %</div>
                        {p.bulletin_title && <div className="mt-1 text-gray-500 dark:text-gray-400">{p.bulletin_title}</div>}
                      </div>
                    )
                  }}
                />
                <Legend wrapperStyle={{ fontSize: 11 }} />
                <Line type="monotone" dataKey="taux_rotation_moyen" name="Taux de rotation moyen" stroke="var(--chart-1)" strokeWidth={2} dot={{ r: 3 }} connectNulls />
                <Line type="monotone" dataKey="prime_risque_marche" name="Prime de risque" stroke="var(--chart-warning)" strokeWidth={2} dot={{ r: 3 }} connectNulls />
              </LineChart>
            </ResponsiveContainer>
          </Card>

          {lastIndicators && (
            <Card title={`Composition du marché — ${lastIndicators.date}`}>
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                <StatTile label="Sociétés cotées" value={fmtNum(lastIndicators.nombre_societes_cotees, 0)} />
                <StatTile label="Lignes obligataires" value={fmtNum(lastIndicators.nombre_lignes_obligataires, 0)} />
                <StatTile label="SGI participantes" value={fmtNum(lastIndicators.nombre_sgi_participantes, 0)} />
                <StatTile label="Volume moyen annuel/séance" value={fmtNum(lastIndicators.volume_moyen_annuel_seance, 0)} />
                <StatTile
                  label="Valeur moyenne annuelle/séance"
                  value={
                    lastIndicators.valeur_moyenne_annuelle_seance !== null
                      ? `${fmtNum(lastIndicators.valeur_moyenne_annuelle_seance / 1_000_000, 0)} M FCFA`
                      : '—'
                  }
                />
              </div>
              <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Valeurs du bulletin le plus récent de la période — ces indicateurs sont des moyennes annuelles,
                elles évoluent peu d'un bulletin au suivant, un graphe dans le temps y apporterait peu.
              </p>
            </Card>
          )}

          <ChartAiAnalysis
            chartType="bulletin_market_stats"
            parameters={{ start_date: startDate, end_date: endDate }}
            data={chartData}
          />
        </>
      )}
    </>
  )
}
