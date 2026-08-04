import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  ResponsiveContainer,
  LineChart,
  Line,
  XAxis,
  YAxis,
  Tooltip,
  Legend,
  CartesianGrid,
  ReferenceLine,
} from 'recharts'
import { callApi } from '../lib/apiClient'
import type { Company, CorrelationResult, RelativeStrengthSeries, RiskAdjustedResult, TotalVariationSeries } from '../lib/types'
import { Card, ErrorState, Input, LoadingState } from '../components/ui'
import { colorForCompany, groupCompaniesBySector } from '../lib/companyGroups'

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function daysAgoIso(n: number) {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return d.toISOString().slice(0, 10)
}

type DisplayMode = 'total' | 'split' | 'both'

export function Statistics() {
  const [selected, setSelected] = useState<number[]>([])
  const [startDate, setStartDate] = useState(daysAgoIso(30))
  const [endDate, setEndDate] = useState(todayIso())
  const [displayMode, setDisplayMode] = useState<DisplayMode>('total')

  const companiesQuery = useQuery({
    queryKey: ['companies-list'],
    queryFn: () => callApi<Company[]>('api_companies.php', 'list', { per_page: 200, active: 1 }),
  })

  const totalVariationQuery = useQuery({
    queryKey: ['total-variation', selected, startDate, endDate],
    queryFn: () =>
      callApi<TotalVariationSeries[]>('api_quotes.php', 'total_variation', {
        company_ids: selected,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: selected.length > 0 && startDate <= endDate,
  })

  const correlationQuery = useQuery({
    queryKey: ['correlation', selected, startDate, endDate],
    queryFn: () =>
      callApi<CorrelationResult>('api_quotes.php', 'correlation', {
        company_ids: selected,
        end_date: endDate,
        days: 90,
      }),
    enabled: selected.length >= 2 && startDate <= endDate,
  })

  const riskAdjustedQuery = useQuery({
    queryKey: ['risk-adjusted', selected, startDate, endDate],
    queryFn: () =>
      callApi<RiskAdjustedResult[]>('api_quotes.php', 'risk_adjusted', {
        company_ids: selected,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: selected.length > 0 && startDate <= endDate,
  })

  const relativeStrengthQuery = useQuery({
    queryKey: ['relative-strength', selected, startDate, endDate],
    queryFn: () =>
      callApi<RelativeStrengthSeries[]>('api_quotes.php', 'relative_strength', {
        company_ids: selected,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: selected.length > 0 && startDate <= endDate,
  })

  const companies = companiesQuery.data ?? []
  const { sectors, unclassified } = useMemo(() => groupCompaniesBySector(companies), [companies])
  const series = totalVariationQuery.data ?? []

  function toggleSelected(id: number) {
    setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  // Un point par jour (agrégat quotidien par construction, pas de vue
  // intrajournalière ici) — une colonne par entreprise et par courbe
  // affichée (total / hausses / baisses selon le mode choisi). Les baisses
  // sont stockées en négatif pour que leur aire se dessine sous la ligne
  // zéro (convention hausse en vert au-dessus / baisse en rouge en-dessous).
  const chartData = useMemo(() => {
    const byDate = new Map<string, Record<string, number | string>>()
    for (const serie of series) {
      for (const point of serie.data) {
        const row = byDate.get(point.date) ?? { date: point.date }
        if (displayMode === 'total' || displayMode === 'both') {
          row[`${serie.symbol} · total`] = Number(point.total_variation_percent)
        }
        if (displayMode === 'split' || displayMode === 'both') {
          row[`${serie.symbol} · hausses`] = Number(point.total_gain_percent)
          row[`${serie.symbol} · baisses`] = -Number(point.total_loss_percent)
        }
        byDate.set(point.date, row)
      }
    }
    return Array.from(byDate.values()).sort((a, b) => String(a.date).localeCompare(String(b.date)))
  }, [series, displayMode])

  // Couleur par entreprise partout (colorForCompany, comme pour le reste de
  // l'app) — le vert/rouge universel rendait la comparaison entre
  // entreprises impossible dès qu'on en sélectionnait plusieurs. Simples
  // lignes, sans remplissage : les baisses restent en négatif (sous la
  // ligne zéro) avec un trait en pointillé, les hausses au-dessus en trait
  // continu — direction + style de trait, même couleur = même entreprise.
  const lines = useMemo(() => {
    const result: { key: string; color: string; width: number; dasharray?: string }[] = []
    for (const serie of series) {
      const color = colorForCompany(serie.company_id)
      if (displayMode === 'total' || displayMode === 'both') {
        result.push({ key: `${serie.symbol} · total`, color, width: displayMode === 'both' ? 2.5 : 2 })
      }
      if (displayMode === 'split' || displayMode === 'both') {
        result.push({ key: `${serie.symbol} · hausses`, color, width: 1.5 })
        result.push({ key: `${serie.symbol} · baisses`, color, width: 1.5, dasharray: '4 2' })
      }
    }
    return result
  }, [series, displayMode])

  const relativeStrengthSeries = relativeStrengthQuery.data ?? []
  const relativeStrengthChartData = useMemo(() => {
    const byDate = new Map<string, Record<string, number | string>>()
    for (const serie of relativeStrengthSeries) {
      for (const point of serie.data) {
        if (point.relative_strength === null) continue
        const row = byDate.get(point.date) ?? { date: point.date }
        row[serie.symbol] = point.relative_strength
        byDate.set(point.date, row)
      }
    }
    return Array.from(byDate.values()).sort((a, b) => String(a.date).localeCompare(String(b.date)))
  }, [relativeStrengthSeries])

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Statistiques — Variation totale</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Somme des mouvements absolus entre relevés intrajournaliers successifs, cumulée chaque jour — mesure combien
          un titre a "bougé" dans la journée (hausses et baisses confondues), pas seulement où il a fini. Un titre qui
          fait +5% puis -3% puis +6% puis -4% a une variation nette de +4% mais une variation totale de ~18%.
        </p>
      </div>

      <Card>
        <div className="flex flex-col gap-4">
          <div className="max-h-64 overflow-y-auto rounded-md border border-gray-200 p-3 dark:border-gray-800">
            {sectors.map((sector) => (
              <div key={sector.name} className="mb-3 last:mb-0">
                <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  {sector.name}
                </div>
                <div className="flex flex-wrap gap-2">
                  {sector.members.map((c) => (
                    <label
                      key={c.company_id}
                      className={`cursor-pointer rounded-full border px-3 py-1 text-xs font-medium ${
                        selected.includes(c.company_id)
                          ? 'text-white'
                          : 'border-gray-300 text-gray-600 dark:border-gray-700 dark:text-gray-300'
                      }`}
                      style={
                        selected.includes(c.company_id)
                          ? { backgroundColor: colorForCompany(c.company_id), borderColor: colorForCompany(c.company_id) }
                          : undefined
                      }
                    >
                      <input
                        type="checkbox"
                        className="hidden"
                        checked={selected.includes(c.company_id)}
                        onChange={() => toggleSelected(c.company_id)}
                      />
                      {c.symbol}
                    </label>
                  ))}
                </div>
              </div>
            ))}

            {unclassified.length > 0 && (
              <div>
                <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  Secteur non renseigné
                </div>
                <div className="flex flex-wrap gap-2">
                  {unclassified.map((c) => (
                    <label
                      key={c.company_id}
                      className={`cursor-pointer rounded-full border px-3 py-1 text-xs font-medium ${
                        selected.includes(c.company_id)
                          ? 'text-white'
                          : 'border-gray-300 text-gray-600 dark:border-gray-700 dark:text-gray-300'
                      }`}
                      style={
                        selected.includes(c.company_id)
                          ? { backgroundColor: colorForCompany(c.company_id), borderColor: colorForCompany(c.company_id) }
                          : undefined
                      }
                    >
                      <input
                        type="checkbox"
                        className="hidden"
                        checked={selected.includes(c.company_id)}
                        onChange={() => toggleSelected(c.company_id)}
                      />
                      {c.symbol}
                    </label>
                  ))}
                </div>
              </div>
            )}
          </div>

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

            <div className="flex flex-col gap-1">
              <span className="text-sm font-medium text-gray-700 dark:text-gray-300">Courbes affichées</span>
              <div className="flex gap-1 rounded-md border border-gray-300 p-0.5 dark:border-gray-700">
                {(
                  [
                    { value: 'total', label: '1 — Total' },
                    { value: 'split', label: '2 — Hausses/baisses' },
                    { value: 'both', label: '3 — Les trois' },
                  ] as { value: DisplayMode; label: string }[]
                ).map((opt) => (
                  <button
                    key={opt.value}
                    type="button"
                    onClick={() => setDisplayMode(opt.value)}
                    className={`rounded px-2.5 py-1 text-xs font-medium transition-colors ${
                      displayMode === opt.value
                        ? 'bg-indigo-600 text-white'
                        : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'
                    }`}
                  >
                    {opt.label}
                  </button>
                ))}
              </div>
            </div>

            {selected.length > 0 && (
              <button
                type="button"
                onClick={() => setSelected([])}
                className="pb-2 text-sm text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
              >
                Tout décocher
              </button>
            )}
          </div>
        </div>
      </Card>

      {selected.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Coche au moins une entreprise pour afficher le graphe.</p>
      )}

      {selected.length > 0 && totalVariationQuery.isLoading && <LoadingState label="Chargement…" />}
      {selected.length > 0 && totalVariationQuery.error && (
        <ErrorState message={(totalVariationQuery.error as Error).message} />
      )}

      {selected.length > 0 && chartData.length > 0 && (
        <Card title="Variation totale cumulée par jour">
          {(displayMode === 'split' || displayMode === 'both') && (
            <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
              Couleur = entreprise (comme partout ailleurs). Hausses : trait continu au-dessus de 0. Baisses : trait
              en pointillé en-dessous de 0 (valeurs inversées pour l'affichage).
            </p>
          )}
          <ResponsiveContainer width="100%" height={400}>
            <LineChart data={chartData}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
              <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
              <YAxis domain={['auto', 'auto']} tick={{ fontSize: 11 }} width={60} tickFormatter={(v: number) => `${v}%`} />
              <ReferenceLine y={0} stroke="#9ca3af" strokeDasharray="3 3" />
              <Tooltip formatter={(value, name) => [`${Number(value).toFixed(2)}%`, name]} />
              <Legend wrapperStyle={{ fontSize: 12 }} />
              {lines.map((line) => (
                <Line
                  key={line.key}
                  type="monotone"
                  dataKey={line.key}
                  stroke={line.color}
                  strokeWidth={line.width}
                  strokeDasharray={line.dasharray}
                  dot={false}
                  connectNulls
                />
              ))}
            </LineChart>
          </ResponsiveContainer>
        </Card>
      )}

      {selected.length > 0 && totalVariationQuery.data && chartData.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Aucune donnée pour la sélection sur cette période.</p>
      )}

      <div>
        <h2 className="text-xl font-semibold">Corrélation entre titres</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Corrélation de Pearson entre les variations quotidiennes (90 derniers jours, jours communs aux entreprises
          sélectionnées) — proche de +1 : bougent ensemble, proche de -1 : bougent en sens opposé, utile pour
          diversifier un portefeuille.
        </p>
      </div>

      {selected.length < 2 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Coche au moins 2 entreprises pour calculer une corrélation.</p>
      )}
      {selected.length >= 2 && correlationQuery.isLoading && <LoadingState label="Calcul de la corrélation…" />}
      {selected.length >= 2 && correlationQuery.error && <ErrorState message={(correlationQuery.error as Error).message} />}

      {correlationQuery.data && correlationQuery.data.symbols.length >= 2 && (
        <Card>
          <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
            {correlationQuery.data.common_days} jour(s) commun(s) utilisé(s) pour le calcul.
          </p>
          <div className="overflow-x-auto">
            <table className="text-sm">
              <thead>
                <tr>
                  <th className="pb-2 pr-3"></th>
                  {correlationQuery.data.symbols.map((s) => (
                    <th key={s} className="px-2 pb-2 text-center text-xs font-semibold text-gray-500 dark:text-gray-400">
                      {s}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {correlationQuery.data.symbols.map((rowSymbol) => (
                  <tr key={rowSymbol}>
                    <td className="pr-3 text-xs font-semibold text-gray-500 dark:text-gray-400">{rowSymbol}</td>
                    {correlationQuery.data!.symbols.map((colSymbol) => {
                      const value = correlationQuery.data!.matrix[rowSymbol]?.[colSymbol] ?? null
                      const bg =
                        value === null
                          ? 'transparent'
                          : value >= 0
                            ? `rgba(27, 175, 122, ${Math.abs(value) * 0.7})`
                            : `rgba(227, 73, 72, ${Math.abs(value) * 0.7})`
                      return (
                        <td key={colSymbol} className="p-2 text-center text-xs tabular-nums" style={{ backgroundColor: bg }}>
                          {value !== null ? value.toFixed(2) : '—'}
                        </td>
                      )
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      <div>
        <h2 className="text-xl font-semibold">Performance ajustée au risque</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Rendement net sur la période divisé par la volatilité cumulée (variation totale, voir ci-dessus) — un titre
          qui monte sans bouger a un meilleur ratio qu'un titre qui monte autant en oscillant sans cesse.
        </p>
      </div>

      {selected.length > 0 && riskAdjustedQuery.isLoading && <LoadingState label="Calcul en cours…" />}
      {selected.length > 0 && riskAdjustedQuery.error && <ErrorState message={(riskAdjustedQuery.error as Error).message} />}

      {riskAdjustedQuery.data && riskAdjustedQuery.data.length > 0 && (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  <th className="pb-2 pr-3">Symbole</th>
                  <th className="pb-2 pr-3">Entreprise</th>
                  <th className="pb-2 pr-3 text-right" title="(dernier cours - premier cours) / premier cours sur la période">
                    Rendement net
                  </th>
                  <th
                    className="pb-2 pr-3 text-right"
                    title="Somme de la variation totale quotidienne (hausses + baisses cumulées) sur la période — voir graphe ci-dessus"
                  >
                    Volatilité totale
                  </th>
                  <th className="pb-2 text-right" title="Rendement net / volatilité totale — plus haut est meilleur">
                    Ratio rendement/risque
                  </th>
                </tr>
              </thead>
              <tbody>
                {riskAdjustedQuery.data.map((r) => (
                  <tr key={r.company_id} className="border-t border-gray-100 dark:border-gray-800">
                    <td className="py-2 pr-3 font-medium">{r.symbol}</td>
                    <td className="py-2 pr-3 text-gray-600 dark:text-gray-300">{r.name}</td>
                    <td
                      className={`py-2 pr-3 text-right tabular-nums ${
                        (r.net_return_percent ?? 0) > 0
                          ? 'text-emerald-600 dark:text-emerald-400'
                          : (r.net_return_percent ?? 0) < 0
                            ? 'text-red-600 dark:text-red-400'
                            : 'text-gray-500'
                      }`}
                    >
                      {r.net_return_percent !== null ? `${r.net_return_percent > 0 ? '+' : ''}${r.net_return_percent.toFixed(2)}%` : '—'}
                    </td>
                    <td className="py-2 pr-3 text-right tabular-nums">
                      {r.total_volatility_percent !== null ? `${r.total_volatility_percent.toFixed(2)}%` : '—'}
                    </td>
                    <td className="py-2 text-right font-semibold tabular-nums">
                      {r.risk_adjusted_ratio !== null ? r.risk_adjusted_ratio.toFixed(3) : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      <div>
        <h2 className="text-xl font-semibold">Force relative vs indice BRVM-COMPOSITE</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Écart entre la variation quotidienne du titre et celle de l'indice BRVM-COMPOSITE le même jour — au-dessus de
          0% : le titre a surperformé le marché ce jour-là, en-dessous : il l'a sous-performé.
        </p>
      </div>

      {selected.length > 0 && relativeStrengthQuery.isLoading && <LoadingState label="Chargement…" />}
      {selected.length > 0 && relativeStrengthQuery.error && (
        <ErrorState message={(relativeStrengthQuery.error as Error).message} />
      )}

      {relativeStrengthChartData.length > 0 && (
        <Card title="Surperformance / sous-performance quotidienne vs BRVM-COMPOSITE">
          <ResponsiveContainer width="100%" height={320}>
            <LineChart data={relativeStrengthChartData}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
              <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
              <YAxis tick={{ fontSize: 11 }} width={60} tickFormatter={(v: number) => `${v}%`} />
              <ReferenceLine y={0} stroke="#9ca3af" strokeDasharray="3 3" />
              <Tooltip
                formatter={(value, name) => [`${Number(value) > 0 ? '+' : ''}${Number(value).toFixed(2)}%`, name]}
              />
              <Legend wrapperStyle={{ fontSize: 12 }} />
              {relativeStrengthSeries.map((serie) => (
                <Line
                  key={serie.company_id}
                  type="monotone"
                  dataKey={serie.symbol}
                  stroke={colorForCompany(serie.company_id)}
                  dot={false}
                  strokeWidth={2}
                  connectNulls
                />
              ))}
            </LineChart>
          </ResponsiveContainer>
        </Card>
      )}

      {selected.length > 0 && relativeStrengthQuery.data && relativeStrengthChartData.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Aucune donnée pour la sélection sur cette période.</p>
      )}
    </div>
  )
}
