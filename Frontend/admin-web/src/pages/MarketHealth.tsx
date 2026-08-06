import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  ResponsiveContainer,
  LineChart,
  Line,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  Tooltip,
  Legend,
  CartesianGrid,
  ReferenceLine,
} from 'recharts'
import { callApi } from '../lib/apiClient'
import type { MarketBreadthPoint, MissingDaysIssue, PriceJumpIssue, ReconciliationIssue, SectorPerformanceSeries } from '../lib/types'
import { Card, ErrorState, Input, LoadingState } from '../components/ui'
import { ChartAiAnalysis } from '../components/ChartAiAnalysis'

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function daysAgoIso(n: number) {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return d.toISOString().slice(0, 10)
}

/** Palette fixe par secteur (ordre stable, indépendant de la sélection). */
const SECTOR_PALETTE = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948', '#7a5c3e', '#5c6b7a']
function colorForSector(sectorId: number) {
  return SECTOR_PALETTE[sectorId % SECTOR_PALETTE.length]
}

function reconciliationKey(r: ReconciliationIssue): string {
  return `${r.symbol}|${r.trading_date}`
}
function priceJumpKey(p: PriceJumpIssue): string {
  return `${p.symbol}|${p.quote_datetime}`
}

export function MarketHealth() {
  const [startDate, setStartDate] = useState(daysAgoIso(90))
  const [endDate, setEndDate] = useState(todayIso())

  const [reconciliationSelected, setReconciliationSelected] = useState<string[]>([])
  const [priceJumpsSelected, setPriceJumpsSelected] = useState<string[]>([])
  const [missingDaysSelected, setMissingDaysSelected] = useState<number[]>([])

  const sectorQuery = useQuery({
    queryKey: ['sector-performance', startDate, endDate],
    queryFn: () =>
      callApi<SectorPerformanceSeries[]>('api_quotes.php', 'sector_performance', {
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: startDate <= endDate,
  })

  const breadthQuery = useQuery({
    queryKey: ['market-breadth', startDate, endDate],
    queryFn: () => callApi<MarketBreadthPoint[]>('api_quotes.php', 'market_breadth', { end_date: endDate, days: 30 }),
  })

  const reconciliationQuery = useQuery({
    queryKey: ['data-quality-reconciliation'],
    queryFn: () => callApi<ReconciliationIssue[]>('api_data_quality.php', 'reconciliation', { days: 30 }),
  })

  const priceJumpsQuery = useQuery({
    queryKey: ['data-quality-price-jumps'],
    queryFn: () => callApi<PriceJumpIssue[]>('api_data_quality.php', 'price_jumps', { days: 7 }),
  })

  const missingDaysQuery = useQuery({
    queryKey: ['data-quality-missing-days'],
    queryFn: () => callApi<MissingDaysIssue[]>('api_data_quality.php', 'missing_days', { days: 30 }),
  })

  function toggleReconciliation(key: string) {
    setReconciliationSelected((prev) => (prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]))
  }
  function togglePriceJump(key: string) {
    setPriceJumpsSelected((prev) => (prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]))
  }
  function toggleMissingDay(id: number) {
    setMissingDaysSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  const sectorSeries = sectorQuery.data ?? []

  // Même logique de fusion "format large" que la comparaison d'entreprises
  // (Quotes.tsx) — un point par jour, une colonne par secteur, affiché en %
  // de variation depuis le début de la période plutôt qu'en indice base 100.
  const sectorChartData = useMemo(() => {
    const byDate = new Map<string, Record<string, number | string>>()
    for (const serie of sectorSeries) {
      for (const point of serie.data) {
        const row = byDate.get(point.date) ?? { date: point.date }
        row[serie.sector_name] = point.index_value - 100
        byDate.set(point.date, row)
      }
    }
    return Array.from(byDate.values()).sort((a, b) => String(a.date).localeCompare(String(b.date)))
  }, [sectorSeries])

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Santé du marché & des données</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Performance moyenne par secteur d'activité, et contrôles automatiques de cohérence des données synchronisées.
        </p>
      </div>

      <div>
        <h3 className="text-lg font-semibold">Performance par secteur</h3>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Indice équipondéré (moyenne des entreprises du secteur, base 100 au début de la période) — compare des secteurs
          entiers plutôt que des entreprises individuelles.
        </p>
      </div>

      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <label className="w-40">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de début</span>
            <Input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
          </label>
          <label className="w-40">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de fin</span>
            <Input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
          </label>
          {startDate > endDate && (
            <p className="pb-2 text-sm text-red-600 dark:text-red-400">La date de début doit précéder la date de fin.</p>
          )}
        </div>
      </Card>

      {sectorQuery.isLoading && <LoadingState label="Chargement de la performance sectorielle…" />}
      {sectorQuery.error && <ErrorState message={(sectorQuery.error as Error).message} />}

      {sectorChartData.length > 0 && (
        <Card title="Variation par secteur depuis le début de la période">
          <ResponsiveContainer width="100%" height={360}>
            <LineChart data={sectorChartData}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
              <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
              <YAxis
                domain={['auto', 'auto']}
                tick={{ fontSize: 11 }}
                width={60}
                tickFormatter={(v: number) => `${v.toFixed(1)}%`}
              />
              <ReferenceLine y={0} stroke="#9ca3af" strokeDasharray="3 3" />
              <Tooltip
                formatter={(value, name) => [`${Number(value) > 0 ? '+' : ''}${Number(value).toFixed(2)}%`, name]}
              />
              <Legend />
              {sectorSeries.map((serie) => (
                <Line
                  key={serie.sector_id}
                  type="monotone"
                  dataKey={serie.sector_name}
                  stroke={colorForSector(serie.sector_id)}
                  dot={false}
                  strokeWidth={2}
                  connectNulls
                />
              ))}
            </LineChart>
          </ResponsiveContainer>

          <ChartAiAnalysis
            chartType="sector_performance"
            parameters={{ start_date: startDate, end_date: endDate }}
            data={sectorSeries}
          />
        </Card>
      )}

      {sectorQuery.data && sectorChartData.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Aucune donnée pour cette période.</p>
      )}

      <div>
        <h3 className="text-lg font-semibold">Largeur de marché</h3>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Part de titres en hausse/baisse chaque jour (30 derniers jours) — un marché qui monte porté par 3 titres
          seulement n'a pas le même sens qu'une hausse partagée par 30.
        </p>
      </div>

      {breadthQuery.isLoading && <LoadingState label="Chargement de la largeur de marché…" />}
      {breadthQuery.error && <ErrorState message={(breadthQuery.error as Error).message} />}

      {breadthQuery.data && breadthQuery.data.length > 0 && (
        <Card title="Hausses / baisses / inchangées par jour">
          <ResponsiveContainer width="100%" height={280}>
            <BarChart data={breadthQuery.data}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
              <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
              <YAxis tick={{ fontSize: 11 }} width={40} />
              <Tooltip />
              <Legend />
              <Bar dataKey="gainers" name="Hausses" stackId="breadth" fill="#1baf7a" />
              <Bar dataKey="unchanged" name="Inchangées" stackId="breadth" fill="#9ca3af" />
              <Bar dataKey="losers" name="Baisses" stackId="breadth" fill="#e34948" />
            </BarChart>
          </ResponsiveContainer>

          <ChartAiAnalysis chartType="market_breadth" parameters={{ end_date: endDate, days: 30 }} data={breadthQuery.data} />
        </Card>
      )}

      {breadthQuery.data && breadthQuery.data.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Aucune donnée pour cette période.</p>
      )}

      <div>
        <h3 className="text-lg font-semibold">Contrôle qualité des données</h3>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Détection automatique d'anomalies dans les données synchronisées — à vérifier, pas forcément des erreurs (un
          titre illiquide peut légitimement bondir sur un seul échange).
        </p>
      </div>

      <Card title="Réconciliation des variations (30 derniers jours)">
        <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
          Variation stockée vs recalculée depuis (clôture - clôture précédente) / clôture précédente — un écart
          signale une valeur mal récupérée depuis brvm.org, ou une incohérence dans previous_close.
        </p>
        {reconciliationQuery.isLoading && <LoadingState />}
        {reconciliationQuery.error && <ErrorState message={(reconciliationQuery.error as Error).message} />}
        {reconciliationQuery.data && reconciliationQuery.data.length === 0 && (
          <p className="text-sm text-gray-500 dark:text-gray-400">Aucun écart détecté.</p>
        )}
        {reconciliationQuery.data && reconciliationQuery.data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  <th className="pb-2 pr-3">
                    <input
                      type="checkbox"
                      checked={reconciliationSelected.length === reconciliationQuery.data.length}
                      onChange={() =>
                        setReconciliationSelected(
                          reconciliationSelected.length === reconciliationQuery.data!.length
                            ? []
                            : reconciliationQuery.data!.map(reconciliationKey)
                        )
                      }
                    />
                  </th>
                  <th className="pb-2 pr-3" title="Code de cotation à la BRVM">
                    Symbole
                  </th>
                  <th className="pb-2 pr-3" title="Jour de la clôture concernée">
                    Date
                  </th>
                  <th className="pb-2 pr-3 text-right" title="Prix de clôture du jour (FCFA)">
                    Clôture
                  </th>
                  <th className="pb-2 pr-3 text-right" title="Prix retenu comme référence pour calculer la variation (FCFA)">
                    Clôture précédente
                  </th>
                  <th className="pb-2 pr-3 text-right" title="Variation en % telle que récupérée depuis brvm.org et enregistrée en base">
                    Variation stockée
                  </th>
                  <th
                    className="pb-2 text-right"
                    title="(clôture - clôture précédente) / clôture précédente, recalculée côté serveur — en rouge quand elle diverge de plus de 0,5 point de la variation stockée"
                  >
                    Variation recalculée
                  </th>
                </tr>
              </thead>
              <tbody>
                {reconciliationQuery.data.map((r, i) => {
                  const key = reconciliationKey(r)
                  return (
                    <tr key={i} className="border-t border-gray-100 dark:border-gray-800">
                      <td className="py-2 pr-3">
                        <input type="checkbox" checked={reconciliationSelected.includes(key)} onChange={() => toggleReconciliation(key)} />
                      </td>
                      <td className="py-2 pr-3 font-medium">{r.symbol}</td>
                      <td className="py-2 pr-3 text-gray-500 dark:text-gray-400">{r.trading_date}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{r.close_price}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{r.previous_close}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{Number(r.stored_variation).toFixed(2)}%</td>
                      <td className="py-2 text-right tabular-nums text-red-600 dark:text-red-400">
                        {Number(r.computed_variation).toFixed(2)}%
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
        {reconciliationQuery.data && reconciliationQuery.data.length > 0 && (
          <>
            <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">{reconciliationSelected.length} ligne(s) sélectionnée(s)</p>
            <ChartAiAnalysis
              chartType="data_quality_reconciliation"
              parameters={{ days: 30, selected_rows: [...reconciliationSelected].sort() }}
              data={reconciliationQuery.data.filter((r) => reconciliationSelected.includes(reconciliationKey(r)))}
              disabled={reconciliationSelected.length === 0}
              disabledReason="Coche au moins une ligne à analyser."
            />
          </>
        )}
      </Card>

      <Card title="Sauts de prix intrajournaliers (7 derniers jours, > 15% entre deux relevés)">
        <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
          Compare chaque relevé intrajournalier au relevé précédent de la même entreprise (~10 min plus tôt) — un
          mouvement isolé de plus de 15% entre deux relevés consécutifs est à vérifier, pas forcément une erreur.
        </p>
        {priceJumpsQuery.isLoading && <LoadingState />}
        {priceJumpsQuery.error && <ErrorState message={(priceJumpsQuery.error as Error).message} />}
        {priceJumpsQuery.data && priceJumpsQuery.data.length === 0 && (
          <p className="text-sm text-gray-500 dark:text-gray-400">Aucun saut anormal détecté.</p>
        )}
        {priceJumpsQuery.data && priceJumpsQuery.data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  <th className="pb-2 pr-3">
                    <input
                      type="checkbox"
                      checked={priceJumpsSelected.length === priceJumpsQuery.data.length}
                      onChange={() =>
                        setPriceJumpsSelected(
                          priceJumpsSelected.length === priceJumpsQuery.data!.length ? [] : priceJumpsQuery.data!.map(priceJumpKey)
                        )
                      }
                    />
                  </th>
                  <th className="pb-2 pr-3" title="Code de cotation à la BRVM">
                    Symbole
                  </th>
                  <th className="pb-2 pr-3" title="Horodatage du relevé intrajournalier précédent (~10 min avant, selon le cron)">
                    Relevé précédent
                  </th>
                  <th className="pb-2 pr-3 text-right" title="Prix à ce relevé précédent (FCFA)">
                    Prix précédent
                  </th>
                  <th className="pb-2 pr-3" title="Horodatage du relevé où le saut est observé">
                    Relevé
                  </th>
                  <th className="pb-2 pr-3 text-right" title="Prix à ce relevé (FCFA)">
                    Prix
                  </th>
                  <th
                    className="pb-2 text-right"
                    title="Variation en % entre les deux relevés consécutifs — un saut isolé de cette ampleur en quelques minutes est rare : soit une erreur de récupération des données, soit une transaction unique sur un titre peu liquide qui déplace fortement le prix"
                  >
                    Écart
                  </th>
                </tr>
              </thead>
              <tbody>
                {priceJumpsQuery.data.map((p, i) => {
                  const key = priceJumpKey(p)
                  return (
                    <tr key={i} className="border-t border-gray-100 dark:border-gray-800">
                      <td className="py-2 pr-3">
                        <input type="checkbox" checked={priceJumpsSelected.includes(key)} onChange={() => togglePriceJump(key)} />
                      </td>
                      <td className="py-2 pr-3 font-medium">{p.symbol}</td>
                      <td className="py-2 pr-3 text-gray-500 dark:text-gray-400">{p.previous_datetime}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{p.previous_price}</td>
                      <td className="py-2 pr-3 text-gray-500 dark:text-gray-400">{p.quote_datetime}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{p.price}</td>
                      <td className="py-2 text-right tabular-nums text-red-600 dark:text-red-400">
                        {Number(p.jump_percent) > 0 ? '+' : ''}
                        {Number(p.jump_percent).toFixed(2)}%
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
        {priceJumpsQuery.data && priceJumpsQuery.data.length > 0 && (
          <>
            <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">{priceJumpsSelected.length} ligne(s) sélectionnée(s)</p>
            <ChartAiAnalysis
              chartType="data_quality_price_jumps"
              parameters={{ days: 7, selected_rows: [...priceJumpsSelected].sort() }}
              data={priceJumpsQuery.data.filter((p) => priceJumpsSelected.includes(priceJumpKey(p)))}
              disabled={priceJumpsSelected.length === 0}
              disabledReason="Coche au moins une ligne à analyser."
            />
          </>
        )}
      </Card>

      <Card title="Jours de clôture manquants (30 derniers jours)">
        <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
          Compare, pour chaque entreprise active, le nombre de jours où elle a une clôture enregistrée par rapport au
          nombre de jours où au moins une autre entreprise en a une — un écart signale une synchro qui a échoué pour
          cette entreprise précisément, pas pour tout le marché.
        </p>
        {missingDaysQuery.isLoading && <LoadingState />}
        {missingDaysQuery.error && <ErrorState message={(missingDaysQuery.error as Error).message} />}
        {missingDaysQuery.data && missingDaysQuery.data.length === 0 && (
          <p className="text-sm text-gray-500 dark:text-gray-400">Aucun jour manquant — toutes les entreprises actives ont une clôture pour chaque jour synchronisé.</p>
        )}
        {missingDaysQuery.data && missingDaysQuery.data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  <th className="pb-2 pr-3">
                    <input
                      type="checkbox"
                      checked={missingDaysSelected.length === missingDaysQuery.data.length}
                      onChange={() =>
                        setMissingDaysSelected(
                          missingDaysSelected.length === missingDaysQuery.data!.length
                            ? []
                            : missingDaysQuery.data!.map((m) => m.company_id)
                        )
                      }
                    />
                  </th>
                  <th className="pb-2 pr-3" title="Code de cotation à la BRVM">
                    Symbole
                  </th>
                  <th className="pb-2 pr-3">Entreprise</th>
                  <th
                    className="pb-2 pr-3 text-right"
                    title="Nombre de jours, sur la période, où AU MOINS une entreprise a une clôture enregistrée — calendrier de référence déduit des données elles-mêmes, pas une liste de jours fériés BRVM codée en dur"
                  >
                    Jours attendus
                  </th>
                  <th className="pb-2 pr-3 text-right" title="Nombre de jours où CETTE entreprise a effectivement une clôture enregistrée">
                    Jours présents
                  </th>
                  <th
                    className="pb-2 text-right"
                    title="Jours attendus moins jours présents — peut indiquer un échec de synchro spécifique à cette entreprise, ou une suspension de cotation"
                  >
                    Manquants
                  </th>
                </tr>
              </thead>
              <tbody>
                {missingDaysQuery.data.map((m) => (
                  <tr key={m.company_id} className="border-t border-gray-100 dark:border-gray-800">
                    <td className="py-2 pr-3">
                      <input
                        type="checkbox"
                        checked={missingDaysSelected.includes(m.company_id)}
                        onChange={() => toggleMissingDay(m.company_id)}
                      />
                    </td>
                    <td className="py-2 pr-3 font-medium">{m.symbol}</td>
                    <td className="py-2 pr-3 text-gray-600 dark:text-gray-300">{m.name}</td>
                    <td className="py-2 pr-3 text-right tabular-nums">{m.expected_days}</td>
                    <td className="py-2 pr-3 text-right tabular-nums">{m.actual_days}</td>
                    <td className="py-2 text-right tabular-nums text-red-600 dark:text-red-400">{m.missing_days}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {missingDaysQuery.data && missingDaysQuery.data.length > 0 && (
          <>
            <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">{missingDaysSelected.length} ligne(s) sélectionnée(s)</p>
            <ChartAiAnalysis
              chartType="data_quality_missing_days"
              parameters={{ days: 30, selected_company_ids: [...missingDaysSelected].sort((a, b) => a - b) }}
              data={missingDaysQuery.data.filter((m) => missingDaysSelected.includes(m.company_id))}
              disabled={missingDaysSelected.length === 0}
              disabledReason="Coche au moins une ligne à analyser."
            />
          </>
        )}
      </Card>
    </div>
  )
}
