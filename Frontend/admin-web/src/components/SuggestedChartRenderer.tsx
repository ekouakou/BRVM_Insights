import { Bar, BarChart, CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { SuggestedChart } from '../lib/types'

const SERIES_COLORS = ['#4f46e5', '#0ea5e9', '#059669', '#eda100', '#e34948', '#8b5cf6', '#ec4899', '#0891b2']

/**
 * Trouve, dans les données brutes envoyées à l'IA pour analyse, le premier
 * tableau d'objets plats exploitable pour un graphe — soit `data`
 * lui-même s'il est déjà un tel tableau, soit la première propriété de
 * `data` qui en est une (une seule profondeur : ce composant relit les
 * données telles quelles, il ne les transforme jamais).
 */
function findRecordArray(data: unknown): Record<string, unknown>[] | null {
  const isRecordArray = (v: unknown): v is Record<string, unknown>[] =>
    Array.isArray(v) && v.length > 0 && v.every((row) => row !== null && typeof row === 'object' && !Array.isArray(row))

  if (isRecordArray(data)) return data
  if (data !== null && typeof data === 'object') {
    for (const value of Object.values(data as Record<string, unknown>)) {
      if (isRecordArray(value)) return value
    }
  }
  return null
}

function isNumeric(value: unknown): boolean {
  if (typeof value === 'number') return Number.isFinite(value)
  if (typeof value === 'string') return value.trim() !== '' && Number.isFinite(Number(value))
  return false
}

/**
 * Des analyses enregistrées avant l'ajout des libellés humains (x_label,
 * label par série) stockent encore "series" comme un simple tableau de
 * chaînes — sans normalisation, ces entrées d'historique plus anciennes ne
 * s'affichaient plus du tout (s.field valait undefined pour chaque entrée,
 * donc filtré). On tolère les deux formes plutôt que d'exiger de
 * relancer chaque analyse existante.
 */
function normalizeSeries(series: unknown[]): { field: string; label: string }[] {
  return series.map((s) => (typeof s === 'string' ? { field: s, label: s } : (s as { field: string; label: string })))
}

/**
 * Rendu d'un graphe proposé par l'IA en plus de son analyse (voir
 * ChartAnalysisService::buildPrompt(), champ "suggested_charts") — n'affiche
 * RIEN si les noms de champs cités (x_field/series) ne correspondent à rien
 * dans les données réellement analysées, plutôt que de risquer un graphe
 * vide ou cassé sur un nom de champ halluciné (le prompt décourage déjà ça
 * côté IA, mais un modèle peut se tromper — la validation reste ici, côté
 * frontend, seule autorité sur ce qui s'affiche réellement).
 */
export function SuggestedChartRenderer({ chart, data }: { chart: SuggestedChart; data: unknown }) {
  const rows = findRecordArray(data)
  if (!rows) return null

  const xLabel = chart.x_label || chart.x_field
  const allSeries = normalizeSeries(chart.series)
  const sample = rows.slice(0, Math.min(rows.length, 5))
  const hasXField = sample.some((r) => chart.x_field in r)
  const validSeries = allSeries.filter((s) => sample.some((r) => s.field in r && isNumeric(r[s.field])))
  if (!hasXField || validSeries.length === 0) return null

  const chartData = rows.map((r) => {
    const point: Record<string, string | number> = { [chart.x_field]: String(r[chart.x_field] ?? '') }
    for (const s of validSeries) {
      const raw = r[s.field]
      point[s.field] = typeof raw === 'number' ? raw : Number(raw)
    }
    return point
  })

  return (
    <div className="mt-3">
      <h5 className="text-sm font-semibold text-gray-700 dark:text-gray-300">{chart.title}</h5>
      <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">{chart.description}</p>
      <ResponsiveContainer width="100%" height={220}>
        {chart.chart_type === 'bar' ? (
          <BarChart data={chartData}>
            <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
            <XAxis dataKey={chart.x_field} tick={{ fontSize: 11 }} minTickGap={30} />
            <YAxis tick={{ fontSize: 11 }} width={60} />
            <Tooltip labelFormatter={(value) => `${xLabel} : ${value}`} />
            <Legend wrapperStyle={{ fontSize: 12 }} />
            {validSeries.map((s, i) => (
              <Bar key={s.field} dataKey={s.field} name={s.label} fill={SERIES_COLORS[i % SERIES_COLORS.length]} />
            ))}
          </BarChart>
        ) : (
          <LineChart data={chartData}>
            <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
            <XAxis dataKey={chart.x_field} tick={{ fontSize: 11 }} minTickGap={30} />
            <YAxis tick={{ fontSize: 11 }} width={60} />
            <Tooltip labelFormatter={(value) => `${xLabel} : ${value}`} />
            <Legend wrapperStyle={{ fontSize: 12 }} />
            {validSeries.map((s, i) => (
              <Line key={s.field} type="monotone" dataKey={s.field} name={s.label} stroke={SERIES_COLORS[i % SERIES_COLORS.length]} dot={false} strokeWidth={2} connectNulls />
            ))}
          </LineChart>
        )}
      </ResponsiveContainer>
    </div>
  )
}
