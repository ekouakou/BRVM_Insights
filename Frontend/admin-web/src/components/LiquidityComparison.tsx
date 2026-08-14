import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Line,
  LineChart,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { callApi } from '../lib/apiClient'
import type { Company, LiquidityCompareResult } from '../lib/types'
import { Card, ErrorState, LoadingState } from './ui'
import { ChartAiAnalysis } from './ChartAiAnalysis'

/**
 * Onglet « Liquidité & carnet » de l'écran Comparaison : confronte les
 * entreprises sélectionnées sur tout le moteur de liquidité (score 0-100 et
 * sous-scores, volume exécuté quotidien, spread, équilibre du carnet) — voir
 * TODO_CARNET_ORDRES.md. Chaque entreprise garde sa couleur de comparaison
 * (useCompanyColors, personnalisable via les pipettes en haut de page).
 */

const nf = new Intl.NumberFormat('fr-FR')

const SUB_LABELS: Record<string, string> = {
  volume: 'Volume échangé',
  regularite: 'Régularité',
  spread: 'Spread',
  profondeur: 'Profondeur',
  absorption: 'Absorption',
  stabilite: 'Stabilité',
}

export function LiquidityComparisonPanel({
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
  const compareQuery = useQuery({
    queryKey: ['ob-compare', [...selectedIds].sort((a, b) => a - b), startDate, endDate],
    queryFn: () =>
      callApi<LiquidityCompareResult>('api_order_book.php', 'compare', {
        company_ids: selectedIds,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: selectedIds.length > 0,
  })

  const data = compareQuery.data
  const symbolOf = (id: number) => companies.find((c) => c.company_id === id)?.symbol ?? String(id)

  // Séries fusionnées par date pour les graphes multi-lignes : une clé par
  // entreprise (v_<id>, s_<id>, i_<id>).
  const merged = useMemo(() => {
    if (!data) return []
    const byDate = new Map<string, Record<string, number | string | null>>()
    for (const serie of data.series) {
      for (const day of serie.days) {
        const row = byDate.get(day.date) ?? { date: day.date }
        row[`v_${serie.company_id}`] = day.executed_volume
        row[`p_${serie.company_id}`] = day.net_pressure_percent
        row[`s_${serie.company_id}`] = day.spread_percent
        row[`i_${serie.company_id}`] = day.imbalance_ratio
        byDate.set(day.date, row)
      }
    }
    return [...byDate.values()].sort((a, b) => String(a.date).localeCompare(String(b.date)))
  }, [data])

  // Cumuls achat/vente de la période, en barres divergentes (vendeurs vers
  // la gauche) — comparaison d'un coup d'œil de la pression par titre.
  const pressureTotals = useMemo(() => {
    if (!data) return []
    return data.series
      .map((serie) => {
        let buy = 0
        let sell = 0
        let total = 0
        for (const d of serie.days) {
          buy += d.buy_volume ?? 0
          sell += d.sell_volume ?? 0
          total += d.executed_volume ?? 0
        }
        const company = data.companies.find((c) => c.company_id === serie.company_id)
        return {
          company_id: serie.company_id,
          symbol: company?.symbol ?? String(serie.company_id),
          buy,
          sell,
          sellNeg: -sell,
          neutral: Math.max(0, total - buy - sell),
          total,
        }
      })
      .sort((a, b) => b.total - a.total)
  }, [data])

  if (selectedIds.length === 0) return null
  if (compareQuery.isLoading) return <LoadingState label="Comparaison de la liquidité…" />
  if (compareQuery.error) return <ErrorState message={(compareQuery.error as Error).message} />
  if (!data) return null

  const scoreChart = data.companies.map((c) => ({
    ...c,
    label: c.symbol,
    fill: colorFor(c.company_id, selectedIds),
  }))

  const multiLineTooltip = (unit: string, digits = 0) =>
    function MultiTooltip({ active, payload, label }: { active?: boolean; payload?: { dataKey?: string | number; value?: number | string | null; color?: string }[]; label?: string }) {
      if (!active || !payload?.length) return null
      return (
        <div className="rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
          <div className="font-medium">{label}</div>
          {payload
            .filter((p) => p.value !== null && p.value !== undefined)
            .map((p) => {
              const id = Number(String(p.dataKey).slice(2))
              return (
                <div key={String(p.dataKey)} style={{ color: p.color }}>
                  {symbolOf(id)} : {nf.format(Number(Number(p.value).toFixed(digits)))}{unit}
                </div>
              )
            })}
        </div>
      )
    }

  return (
    <div className="flex flex-col gap-4">
      <Card title="Score de liquidité 0-100 (estimé) — classement de la sélection">
        <ResponsiveContainer width="100%" height={Math.max(160, 40 * scoreChart.length + 40)}>
          <BarChart data={scoreChart} layout="vertical" margin={{ top: 5, right: 30, bottom: 5, left: 10 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} horizontal={false} />
            <XAxis type="number" domain={[0, 100]} tick={{ fontSize: 10 }} />
            <YAxis type="category" dataKey="label" width={70} tick={{ fontSize: 11 }} />
            <Tooltip
              content={({ active, payload }) => {
                if (!active || !payload?.length) return null
                const c = payload[0].payload as (typeof scoreChart)[number]
                return (
                  <div className="rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                    <div className="font-medium">{c.symbol} — {c.name}</div>
                    <div>Score : {c.score ?? 'n/d'}/100 (couverture {c.coverage_percent}%)</div>
                    {Object.entries(c.sub_scores).map(([k, v]) => (
                      <div key={k}>{SUB_LABELS[k] ?? k} : {v ?? 'n/d'}</div>
                    ))}
                  </div>
                )
              }}
            />
            <Bar dataKey="score" name="Score">
              {scoreChart.map((c) => (
                <Cell key={c.company_id} fill={c.fill} />
              ))}
            </Bar>
          </BarChart>
        </ResponsiveContainer>
        <div className="mt-2 overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                <th className="pb-2 pr-3">Entreprise</th>
                <th className="pb-2 pr-3 text-right">Score</th>
                {Object.entries(data.weights).map(([k, w]) => (
                  <th key={k} className="pb-2 pr-3 text-right">{SUB_LABELS[k] ?? k} ({w}%)</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.companies.map((c) => (
                <tr key={c.company_id} className="border-t border-gray-100 dark:border-gray-800">
                  <td className="py-1.5 pr-3 whitespace-nowrap">
                    <span className="mr-1.5 inline-block h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: colorFor(c.company_id, selectedIds) }} />
                    {c.symbol}
                  </td>
                  <td className="py-1.5 pr-3 text-right font-semibold tabular-nums">{c.score ?? 'n/d'}</td>
                  {Object.keys(data.weights).map((k) => (
                    <td key={k} className="py-1.5 pr-3 text-right tabular-nums">{c.sub_scores[k] ?? 'n/d'}</td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
          « n/d » = pas assez d'historique pour ce sous-score (le score global est renormalisé sur le reste).{' '}
          {data.note}
        </p>
      </Card>

      <Card title="Volume exécuté par séance (calculé) — titres réellement échangés">
        <ResponsiveContainer width="100%" height={250}>
          <LineChart data={merged} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
            <XAxis dataKey="date" tick={{ fontSize: 10 }} tickFormatter={(d: string) => d.slice(5)} />
            <YAxis tick={{ fontSize: 10 }} width={70} tickFormatter={(v) => nf.format(v)} />
            <Tooltip content={multiLineTooltip(' titres')} />
            {selectedIds.map((id) => (
              <Line key={id} dataKey={`v_${id}`} name={symbolOf(id)} stroke={colorFor(id, selectedIds)} dot={false} strokeWidth={1.5} connectNulls />
            ))}
          </LineChart>
        </ResponsiveContainer>
      </Card>

      <Card title="Pression nette par séance (estimée) — acheteurs contre vendeurs">
        <ResponsiveContainer width="100%" height={240}>
          <LineChart data={merged} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
            <XAxis dataKey="date" tick={{ fontSize: 10 }} tickFormatter={(d: string) => d.slice(5)} />
            <YAxis domain={[-100, 100]} tick={{ fontSize: 10 }} width={45} tickFormatter={(v) => `${v}%`} />
            <Tooltip
              content={({ active, payload, label }) => {
                if (!active || !payload?.length) return null
                return (
                  <div className="rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                    <div className="font-medium">{label}</div>
                    {payload
                      .filter((p) => p.value !== null && p.value !== undefined)
                      .map((p) => {
                        const id = Number(String(p.dataKey).slice(2))
                        const v = Number(p.value)
                        const serie = data.series.find((s) => s.company_id === id)
                        const day = serie?.days.find((d) => d.date === label)
                        return (
                          <div key={String(p.dataKey)} style={{ color: p.color }}>
                            {symbolOf(id)} : {v > 0 ? '+' : ''}{v.toFixed(0)}% —{' '}
                            {v > 5 ? 'acheteurs dominants' : v < -5 ? 'vendeurs dominants' : 'équilibré'}
                            {day && ` (${nf.format(day.buy_volume ?? 0)} achat / ${nf.format(day.sell_volume ?? 0)} vente)`}
                          </div>
                        )
                      })}
                  </div>
                )
              }}
            />
            <ReferenceLine y={0} stroke="var(--chart-muted)" />
            {selectedIds.map((id) => (
              <Line key={id} dataKey={`p_${id}`} name={symbolOf(id)} stroke={colorFor(id, selectedIds)} dot={false} strokeWidth={1.5} connectNulls />
            ))}
          </LineChart>
        </ResponsiveContainer>
        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
          Échelle normalisée de −100% (tous les échanges classés à l'initiative des vendeurs) à +100% (tous à
          l'initiative des acheteurs), pour comparer des titres de tailles très différentes. Au-dessus de 0 : ce sont
          plutôt les acheteurs qui poussent ; en dessous : les vendeurs. Le sens est estimé par la direction du prix
          (tick rule) — un jour où le prix n'a pas bougé n'est pas classable et laisse un trou dans la courbe.
        </p>
      </Card>

      <Card title="Volumes achat / vente cumulés sur la période (estimés)">
        <ResponsiveContainer width="100%" height={Math.max(160, 44 * pressureTotals.length + 40)}>
          <BarChart data={pressureTotals} layout="vertical" margin={{ top: 5, right: 30, bottom: 5, left: 10 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} horizontal={false} />
            <XAxis type="number" tick={{ fontSize: 10 }} tickFormatter={(v) => nf.format(Math.abs(v))} />
            <YAxis type="category" dataKey="symbol" width={70} tick={{ fontSize: 11 }} />
            <Tooltip
              content={({ active, payload }) => {
                if (!active || !payload?.length) return null
                const r = payload[0].payload as (typeof pressureTotals)[number]
                return (
                  <div className="rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                    <div className="font-medium">{r.symbol}</div>
                    <div className="text-emerald-600 dark:text-emerald-400">À l'initiative des acheteurs : {nf.format(r.buy)} titres</div>
                    <div className="text-red-600 dark:text-red-400">À l'initiative des vendeurs : {nf.format(r.sell)} titres</div>
                    <div>Non classé (prix inchangé) : {nf.format(r.neutral)}</div>
                    <div>Total échangé (calculé) : {nf.format(r.total)}</div>
                  </div>
                )
              }}
            />
            <ReferenceLine x={0} stroke="var(--chart-muted)" />
            <Bar dataKey="sellNeg" name="Vendeurs" stackId="p" fill="var(--chart-negative)" />
            <Bar dataKey="buy" name="Acheteurs" stackId="p" fill="var(--chart-positive)" />
          </BarChart>
        </ResponsiveContainer>
        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
          Vers la gauche (rouge) : titres échangés quand le prix baissait — vendeurs à l'initiative. Vers la droite
          (vert) : quand le prix montait — acheteurs à l'initiative. Une barre très asymétrique signale un titre sous
          pression d'un seul côté sur la période.
        </p>
      </Card>

      <Card title="Spread en fin de séance (calculé) — coût d'un aller-retour immédiat">
        <ResponsiveContainer width="100%" height={220}>
          <LineChart data={merged} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
            <XAxis dataKey="date" tick={{ fontSize: 10 }} tickFormatter={(d: string) => d.slice(5)} />
            <YAxis tick={{ fontSize: 10 }} width={50} tickFormatter={(v) => `${v}%`} />
            <Tooltip content={multiLineTooltip('%', 2)} />
            {selectedIds.map((id) => (
              <Line key={id} dataKey={`s_${id}`} name={symbolOf(id)} stroke={colorFor(id, selectedIds)} dot={false} strokeWidth={1.5} connectNulls />
            ))}
          </LineChart>
        </ResponsiveContainer>
        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
          Plus le spread est bas, moins on perd en achetant puis revendant immédiatement — un marqueur direct de
          liquidité. Trous dans une courbe = pas de carnet publié ce jour-là (ou un des deux prix absent).
        </p>
      </Card>

      <Card title="Équilibre du carnet en fin de séance (calculé) — qui domine la file d'attente ?">
        <ResponsiveContainer width="100%" height={220}>
          <LineChart data={merged} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
            <XAxis dataKey="date" tick={{ fontSize: 10 }} tickFormatter={(d: string) => d.slice(5)} />
            <YAxis domain={[0, 1]} tick={{ fontSize: 10 }} width={40} />
            <Tooltip content={multiLineTooltip('', 2)} />
            <ReferenceLine y={0.5} stroke="var(--chart-muted)" strokeDasharray="4 4" />
            {selectedIds.map((id) => (
              <Line key={id} dataKey={`i_${id}`} name={symbolOf(id)} stroke={colorFor(id, selectedIds)} dot={false} strokeWidth={1.5} connectNulls />
            ))}
          </LineChart>
        </ResponsiveContainer>
        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
          Part de la demande dans le total (demande + offre) aux meilleures limites : au-dessus des pointillés (0,5),
          plus d'acheteurs en attente que de vendeurs ; en dessous, l'inverse. Durablement sous 0,5 = titre sous
          pression vendeuse.
        </p>
      </Card>

      <ChartAiAnalysis
        chartType="liquidity_comparison"
        parameters={{
          company_ids: [...selectedIds].sort((a, b) => a - b),
          start_date: startDate,
          end_date: endDate,
        }}
        data={data}
        companyIdsForReports={selectedIds}
      />
    </div>
  )
}
