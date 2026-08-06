import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { BarChart, Bar, XAxis, YAxis, Tooltip, CartesianGrid, ResponsiveContainer, LabelList } from 'recharts'
import { callApi } from '../lib/apiClient'
import type { VolumeRankingRow } from '../lib/types'
import { Card, ErrorState, Input, LoadingState } from '../components/ui'
import { ChartAiAnalysis } from '../components/ChartAiAnalysis'

function fmt(n: string | number | null | undefined, digits = 0): string {
  if (n === null || n === undefined) return '—'
  const num = typeof n === 'string' ? parseFloat(n) : n
  if (Number.isNaN(num)) return '—'
  return num.toLocaleString('fr-FR', { maximumFractionDigits: digits })
}

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function daysAgoIso(days: number) {
  const d = new Date()
  d.setDate(d.getDate() - days)
  return d.toISOString().slice(0, 10)
}

/** Médaille pour le podium (top 3), sinon le rang brut. */
function RankBadge({ rank }: { rank: number }) {
  const medal = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : null
  if (medal) return <span title={`Rang ${rank}`}>{medal}</span>
  return <span className="text-gray-400 dark:text-gray-500">{rank}</span>
}

export function VolumeRanking() {
  const [startDate, setStartDate] = useState(daysAgoIso(30))
  const [endDate, setEndDate] = useState(todayIso())
  const [selected, setSelected] = useState<number[]>([])

  // Somme du volume (et du turnover) de chaque entreprise active sur toute
  // la plage de dates — pas seulement sa dernière séance connue. Une
  // entreprise sans transaction sur la période apparaît quand même, à 0
  // (voir api_quotes.php::getVolumeRanking, LEFT JOIN).
  const rankingQuery = useQuery({
    queryKey: ['volume-ranking', startDate, endDate],
    queryFn: () =>
      callApi<VolumeRankingRow[]>('api_quotes.php', 'volume_ranking', {
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!startDate && !!endDate && startDate <= endDate,
  })

  const rows = rankingQuery.data ?? []
  const chartData = rows.map((r) => ({ symbol: r.symbol, volume: r.total_volume }))

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Classement par volume</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Entreprises actives classées par nombre total d'actions échangées sur la période choisie — un volume
          élevé traduit une forte activité de négociation cumulée, pas nécessairement une hausse ou une baisse du
          cours.
        </p>
      </div>

      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <label className="w-44">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de début</span>
            <Input type="date" value={startDate} max={endDate} onChange={(e) => setStartDate(e.target.value)} />
          </label>
          <label className="w-44">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de fin</span>
            <Input type="date" value={endDate} min={startDate} max={todayIso()} onChange={(e) => setEndDate(e.target.value)} />
          </label>
        </div>
        {startDate > endDate && (
          <p className="mt-2 text-xs text-red-600 dark:text-red-400">La date de début doit précéder la date de fin.</p>
        )}
      </Card>

      {rankingQuery.isLoading && <LoadingState label="Chargement du classement…" />}
      {rankingQuery.error && <ErrorState message={(rankingQuery.error as Error).message} />}

      {chartData.length > 0 && (
        <Card title="Volumes par entreprise">
          <ResponsiveContainer width="100%" height={Math.max(240, chartData.length * 24)}>
            <BarChart data={chartData} layout="vertical" margin={{ left: 16, right: 24 }}>
              <CartesianGrid strokeDasharray="3 3" horizontal={false} className="stroke-gray-200 dark:stroke-gray-800" />
              <XAxis type="number" tick={{ fontSize: 11 }} tickFormatter={(v) => fmt(v)} />
              <YAxis type="category" dataKey="symbol" tick={{ fontSize: 11 }} width={60} />
              <Tooltip formatter={(value) => [fmt(value as number), 'Volume']} />
              <Bar dataKey="volume" fill="#4f46e5" radius={[0, 4, 4, 0]}>
                <LabelList dataKey="volume" position="right" formatter={(v) => fmt(v as number)} className="fill-gray-500 text-[10px] dark:fill-gray-400" />
              </Bar>
            </BarChart>
          </ResponsiveContainer>
        </Card>
      )}

      {rows.length > 0 && (
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
                  <th className="pb-2 pr-3">Rang</th>
                  <th className="pb-2 pr-3">Symbole</th>
                  <th className="pb-2 pr-3">Entreprise</th>
                  <th className="pb-2 pr-3">Secteur</th>
                  <th
                    className="pb-2 pr-3 text-right"
                    title="Actions en circulation (total émis, donnée BRVM scrapée ponctuellement) — PAS un stock « à vendre », il n'existe pas de carnet d'ordres public à la BRVM"
                  >
                    Actions en circulation
                  </th>
                  <th className="pb-2 pr-3 text-right" title="« Vendu » — somme du volume quotidien échangé sur la période">
                    Volume vendu
                  </th>
                  <th className="pb-2 pr-3 text-right" title="Volume vendu ÷ actions en circulation">
                    Rotation
                  </th>
                  <th
                    className="pb-2 pr-3 text-right"
                    title="« Restant » — estimation basse (actions en circulation - volume vendu, plafonnée à 0). Les mêmes actions peuvent être retradées plusieurs fois : ne signifie pas que ces actions précises sont restées immobiles."
                  >
                    Restant (est.)
                  </th>
                  <th className="pb-2 pr-3 text-right" title="Somme du turnover (cours × volume) quotidien sur la période">
                    Turnover total
                  </th>
                  <th className="pb-2" title="Nombre de séances avec une cotation enregistrée sur la période">
                    Jours cotés
                  </th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r, i) => {
                  const rank = i + 1
                  return (
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
                      <td className="py-2 pr-3">
                        <RankBadge rank={rank} />
                      </td>
                      <td className="py-2 pr-3 font-medium">{r.symbol}</td>
                      <td className="py-2 pr-3 text-gray-600 dark:text-gray-300">{r.name}</td>
                      <td className="py-2 pr-3 text-gray-500 dark:text-gray-400">{r.sector ?? '—'}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{fmt(r.shares_outstanding)}</td>
                      <td className="py-2 pr-3 text-right tabular-nums font-medium">{fmt(r.total_volume)}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">
                        {r.turnover_percent !== null ? `${fmt(r.turnover_percent, 2)}%` : '—'}
                      </td>
                      <td className="py-2 pr-3 text-right tabular-nums">
                        {r.shares_remaining_estimate !== null ? (
                          <span title={r.fully_rotated ? 'Capital retradé au moins 1× en moyenne sur la période — estimation à 0' : undefined}>
                            {fmt(r.shares_remaining_estimate)}
                          </span>
                        ) : (
                          '—'
                        )}
                      </td>
                      <td className="py-2 pr-3 text-right tabular-nums">{fmt(r.total_turnover)}</td>
                      <td className="py-2 text-gray-500 dark:text-gray-400">{r.trading_days}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          <ChartAiAnalysis
            chartType="volume_ranking"
            parameters={{ start_date: startDate, end_date: endDate, selected_company_ids: [...selected].sort((a, b) => a - b) }}
            data={rows.filter((r) => selected.length === 0 || selected.includes(r.company_id))}
          />
        </Card>
      )}
    </div>
  )
}
