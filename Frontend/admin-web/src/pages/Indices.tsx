import { useEffect, useMemo, useState } from 'react'
import { useQuery, useQueries } from '@tanstack/react-query'
import { LineChart, Line, XAxis, YAxis, Tooltip, CartesianGrid, ResponsiveContainer, Legend, ReferenceLine } from 'recharts'
import { callApi } from '../lib/apiClient'
import type { IndexHistoryPoint, MarketIndex } from '../lib/types'
import { Card, ErrorState, InfoPanel, Input, LoadingState } from '../components/ui'
import { ChartAiAnalysis } from '../components/ChartAiAnalysis'
import { colorForCompany } from '../lib/companyGroups'

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function daysAgoIso(days: number) {
  const d = new Date()
  d.setDate(d.getDate() - days)
  return d.toISOString().slice(0, 10)
}

type DisplayMode = 'close' | 'percent'

export function Indices() {
  const [startDate, setStartDate] = useState(daysAgoIso(90))
  const [endDate, setEndDate] = useState(todayIso())
  const [selectedCodes, setSelectedCodes] = useState<string[]>([])
  const [displayMode, setDisplayMode] = useState<DisplayMode>('percent')

  const periodEnabled = !!startDate && !!endDate && startDate <= endDate

  const indicesQuery = useQuery({
    queryKey: ['market-indices-list'],
    queryFn: () => callApi<MarketIndex[]>('api_market.php', 'indices', { sub_action: 'list' }),
  })

  const indices = useMemo(
    () => [...(indicesQuery.data ?? [])].sort((a, b) => a.code.localeCompare(b.code)),
    [indicesQuery.data],
  )

  // Sélectionne BRVM-COMPOSITE par défaut dès que la liste des indices est connue.
  useEffect(() => {
    if (indices.length > 0 && selectedCodes.length === 0) {
      const composite = indices.find((idx) => idx.code === 'BRVM-COMPOSITE')
      setSelectedCodes([composite ? composite.code : indices[0].code])
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [indices])

  function toggleCode(code: string) {
    setSelectedCodes((prev) => (prev.includes(code) ? prev.filter((c) => c !== code) : [...prev, code]))
  }

  const historyQueries = useQueries({
    queries: selectedCodes.map((code) => ({
      queryKey: ['market-index-history', code, startDate, endDate],
      queryFn: () =>
        callApi<IndexHistoryPoint[]>('api_market.php', 'indices', {
          sub_action: 'history',
          code,
          start_date: startDate,
          end_date: endDate,
        }),
      enabled: periodEnabled,
    })),
  })

  const isLoading = historyQueries.some((q) => q.isLoading)
  const firstError = historyQueries.find((q) => q.error)?.error as Error | undefined

  const series = useMemo(
    () =>
      selectedCodes.map((code, i) => ({
        code,
        name: indices.find((idx) => idx.code === code)?.name ?? code,
        data: historyQueries[i]?.data ?? [],
      })),
    [selectedCodes, indices, historyQueries],
  )

  const chartData = useMemo(() => {
    const byDate = new Map<string, Record<string, number | string>>()

    for (const serie of series) {
      const sorted = [...serie.data].sort((a, b) => a.date.localeCompare(b.date))
      const firstClose = sorted.find((p) => p.close !== null)?.close ?? null
      const baseline = firstClose !== null ? Number(firstClose) : null

      for (const point of sorted) {
        if (point.close === null) continue
        const row = byDate.get(point.date) ?? { date: point.date }
        const closeNum = Number(point.close)
        row[serie.code] = displayMode === 'percent' && baseline ? ((closeNum - baseline) / baseline) * 100 : closeNum
        byDate.set(point.date, row)
      }
    }

    return Array.from(byDate.values()).sort((a, b) => String(a.date).localeCompare(String(b.date)))
  }, [series, displayMode])

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Indices BRVM</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Évolution des indices officiels de la Bourse Régionale des Valeurs Mobilières sur une période choisie.
        </p>
      </div>

      <InfoPanel>
        <p>
          <strong>À quoi sert cet écran.</strong> BRVM-COMPOSITE, BRVM-30, BRVM-PRESTIGE et BRVM-PRINCIPAL sont les
          indices officiels publiés quotidiennement par la BRVM — chacun regroupe un sous-ensemble de sociétés
          cotées, pondérées par leur capitalisation boursière, pour donner une mesure synthétique de l'évolution du
          marché (ou d'un compartiment du marché) dans son ensemble, plutôt qu'un titre à la fois.
        </p>
        <p>
          <strong>Niveau brut vs variation en %.</strong> Les indices n'ont pas la même base ni la même échelle
          entre eux (BRVM-COMPOSITE et BRVM-30 par exemple n'évoluent pas autour des mêmes valeurs) — les comparer
          en niveau brut sur un même graphe est donc trompeur. Le mode « Variation depuis le début » recale chaque
          indice à 0% au premier jour de la période sélectionnée, pour comparer directement leur performance
          relative. Le mode « Niveau » affiche le cours de clôture officiel tel quel, utile pour un seul indice à
          la fois.
        </p>
        <p>
          <strong>BRVM-COMPOSITE vs indices sectoriels internes.</strong> Ces indices sont ceux publiés
          officiellement par la BRVM elle-même — à ne pas confondre avec les indices sectoriels équipondérés
          recalculés en interne par l'application (page Statistiques, onglet « Performance sectorielle »), qui ne
          pondèrent pas par capitalisation et pèsent chaque entreprise du secteur de façon égale.
        </p>
      </InfoPanel>

      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <label className="w-40">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de début</span>
            <Input type="date" value={startDate} max={endDate} onChange={(e) => setStartDate(e.target.value)} />
          </label>
          <label className="w-40">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de fin</span>
            <Input type="date" value={endDate} min={startDate} max={todayIso()} onChange={(e) => setEndDate(e.target.value)} />
          </label>
          <div>
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Affichage</span>
            <div className="flex gap-1 rounded-md border border-gray-300 p-0.5 dark:border-gray-700">
              <button
                type="button"
                onClick={() => setDisplayMode('percent')}
                className={`rounded px-3 py-1.5 text-sm font-medium ${
                  displayMode === 'percent'
                    ? 'bg-indigo-600 text-white'
                    : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'
                }`}
              >
                Variation depuis le début
              </button>
              <button
                type="button"
                onClick={() => setDisplayMode('close')}
                className={`rounded px-3 py-1.5 text-sm font-medium ${
                  displayMode === 'close'
                    ? 'bg-indigo-600 text-white'
                    : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'
                }`}
              >
                Niveau
              </button>
            </div>
          </div>
        </div>

        <div className="mt-4 flex flex-wrap items-center gap-4">
          <span className="text-xs font-medium text-gray-700 dark:text-gray-300">Indices :</span>
          {indices.map((idx) => (
            <label key={idx.id} className="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
              <input type="checkbox" checked={selectedCodes.includes(idx.code)} onChange={() => toggleCode(idx.code)} />
              {idx.code}
            </label>
          ))}
        </div>

        {startDate > endDate && (
          <p className="mt-2 text-xs text-red-600 dark:text-red-400">La date de début doit précéder la date de fin.</p>
        )}
      </Card>

      {indicesQuery.isLoading && <LoadingState label="Chargement des indices…" />}
      {indicesQuery.error && <ErrorState message={(indicesQuery.error as Error).message} />}

      {selectedCodes.length === 0 && !indicesQuery.isLoading && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Sélectionne au moins un indice ci-dessus.</p>
      )}

      {selectedCodes.length > 0 && isLoading && <LoadingState label="Chargement de l'historique…" />}
      {firstError && <ErrorState message={firstError.message} />}

      {selectedCodes.length > 0 && !isLoading && chartData.length > 0 && (
        <Card title="Évolution des indices sélectionnés">
          <ResponsiveContainer width="100%" height={360}>
            <LineChart data={chartData}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
              <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
              <YAxis
                tick={{ fontSize: 11 }}
                width={60}
                tickFormatter={(v: number) => (displayMode === 'percent' ? `${v}%` : String(v))}
              />
              {displayMode === 'percent' && <ReferenceLine y={0} stroke="#9ca3af" strokeDasharray="3 3" />}
              <Tooltip
                formatter={(value, name) => [
                  displayMode === 'percent'
                    ? `${Number(value) > 0 ? '+' : ''}${Number(value).toFixed(2)}%`
                    : Number(value).toLocaleString('fr-FR', { maximumFractionDigits: 2 }),
                  name,
                ]}
              />
              <Legend wrapperStyle={{ fontSize: 12 }} />
              {series.map((serie, i) => (
                <Line
                  key={serie.code}
                  type="monotone"
                  dataKey={serie.code}
                  name={serie.code}
                  stroke={colorForCompany(i)}
                  dot={false}
                  strokeWidth={2}
                  connectNulls
                />
              ))}
            </LineChart>
          </ResponsiveContainer>

          <ChartAiAnalysis
            chartType="market_indices"
            parameters={{ index_codes: [...selectedCodes].sort(), start_date: startDate, end_date: endDate, display_mode: displayMode }}
            data={series}
          />
        </Card>
      )}

      {selectedCodes.length > 0 && !isLoading && chartData.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Aucune donnée pour la sélection sur cette période.</p>
      )}
    </div>
  )
}
