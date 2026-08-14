import { useMemo, useState } from 'react'
import {
  Area,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  ComposedChart,
  Legend,
  Line,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import type { OhlcPoint } from '../lib/types'
import { Card, Select } from './ui'

/**
 * Graphe des cotations quotidiennes (table stock_quotes). Affiche les quatre
 * cours de chaque séance — ouverture, plus haut, plus bas, clôture — que
 * l'application stockait sans jamais les montrer (les autres graphes ne
 * tracent que la clôture), plus la valeur transigée.
 *
 * Deux lectures au choix, COURBES par défaut : sur peu de séances les
 * chandeliers deviennent des rectangles géants illisibles, alors qu'une
 * courbe de clôture posée sur une bande plus bas → plus haut reste claire
 * quel que soit le nombre de points.
 */

const nf = new Intl.NumberFormat('fr-FR')
const fmtF = (v: number | null | undefined) =>
  v === null || v === undefined ? '—' : `${nf.format(Math.round(v))} F`

interface Session {
  date: string
  open: number
  high: number
  low: number
  close: number
  volume: number
  turnover: number | null
  variation_percent: number | null
  /** Intervalle [bas, haut] : dessine la bande d'amplitude (et positionne les chandeliers). */
  range: [number, number]
  rising: boolean
  flat: boolean
}

/**
 * Chandelier : `y` correspond au plus haut de la séance et `y + height` au
 * plus bas (recharts a positionné l'intervalle range) ; on en déduit la
 * position de l'ouverture et de la clôture par règle de trois.
 */
function CandleShape(props: { x?: number; y?: number; width?: number; height?: number; payload?: Session }) {
  const { x = 0, y = 0, width = 0, height = 0, payload } = props
  if (!payload) return null

  const { high, low, open, close, rising, flat } = payload
  const span = high - low
  const bodyWidth = Math.min(28, Math.max(3, width * 0.5))
  const bodyX = x + (width - bodyWidth) / 2
  const wickX = x + width / 2
  const color = flat ? 'var(--chart-muted)' : rising ? 'var(--chart-positive)' : 'var(--chart-negative)'

  if (span <= 0) {
    return <line x1={bodyX} x2={bodyX + bodyWidth} y1={y} y2={y} stroke={color} strokeWidth={2} />
  }

  const yOf = (value: number) => y + ((high - value) / span) * height
  const yOpen = yOf(open)
  const yClose = yOf(close)

  return (
    <g>
      <line x1={wickX} x2={wickX} y1={y} y2={y + height} stroke={color} strokeWidth={1} />
      <rect
        x={bodyX}
        y={Math.min(yOpen, yClose)}
        width={bodyWidth}
        height={Math.max(1, Math.abs(yClose - yOpen))}
        fill={rising ? color : 'transparent'}
        stroke={color}
        strokeWidth={1.5}
      />
    </g>
  )
}

export function DailyQuotesChart({ data, symbol }: { data: OhlcPoint[]; symbol: string }) {
  const [view, setView] = useState<'courbes' | 'chandeliers'>('courbes')
  const [metric, setMetric] = useState<'turnover' | 'volume'>('turnover')

  const sessions = useMemo<Session[]>(
    () =>
      data.map((p) => {
        const open = Number(p.open)
        const close = Number(p.close)
        const high = Number(p.high)
        const low = Number(p.low)
        return {
          date: p.date,
          open,
          high,
          low,
          close,
          volume: Number(p.volume),
          turnover: p.turnover !== null && p.turnover !== undefined ? Number(p.turnover) : null,
          variation_percent:
            p.variation_percent !== null && p.variation_percent !== undefined ? Number(p.variation_percent) : null,
          range: [low, high],
          rising: close > open,
          flat: close === open,
        }
      }),
    [data],
  )

  if (sessions.length === 0) return null

  const min = Math.min(...sessions.map((s) => s.low))
  const max = Math.max(...sessions.map((s) => s.high))
  const pad = (max - min) * 0.08 || max * 0.01 || 1
  const noAmplitude = sessions.every((s) => s.high === s.low)

  const SessionTooltip = ({ active, payload }: { active?: boolean; payload?: { payload: Session }[] }) => {
    if (!active || !payload?.length) return null
    const s = payload[0].payload
    return (
      <div className="rounded-md border border-gray-200 bg-white px-3 py-2 text-xs shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
        <div className="mb-1 font-medium">{s.date}</div>
        <div className="grid grid-cols-2 gap-x-3 tabular-nums">
          <span className="text-gray-500 dark:text-gray-400">Ouverture</span>
          <span className="text-right">{fmtF(s.open)}</span>
          <span className="text-gray-500 dark:text-gray-400">Plus haut</span>
          <span className="text-right">{fmtF(s.high)}</span>
          <span className="text-gray-500 dark:text-gray-400">Plus bas</span>
          <span className="text-right">{fmtF(s.low)}</span>
          <span className="text-gray-500 dark:text-gray-400">Clôture</span>
          <span
            className={`text-right font-semibold ${
              s.rising ? 'text-emerald-600 dark:text-emerald-400' : s.flat ? '' : 'text-red-600 dark:text-red-400'
            }`}
          >
            {fmtF(s.close)}
          </span>
        </div>
        <div className="mt-1 border-t border-gray-100 pt-1 tabular-nums dark:border-gray-800">
          <div>
            Variation :{' '}
            {s.variation_percent !== null
              ? `${s.variation_percent > 0 ? '+' : ''}${s.variation_percent.toFixed(2)}%`
              : '—'}
          </div>
          <div className="text-gray-500 dark:text-gray-400">Titres échangés : {nf.format(s.volume)}</div>
          <div className="text-gray-500 dark:text-gray-400">Valeur transigée : {fmtF(s.turnover)}</div>
        </div>
      </div>
    )
  }

  return (
    <>
      <Card title={`Cotations quotidiennes de ${symbol}`}>
        <label className="mb-2 block w-56">
          <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Affichage</span>
          <Select value={view} onChange={(e) => setView(e.target.value as 'courbes' | 'chandeliers')}>
            <option value="courbes">Courbes (recommandé)</option>
            <option value="chandeliers">Chandeliers japonais</option>
          </Select>
        </label>

        <ResponsiveContainer width="100%" height={320}>
          <ComposedChart data={sessions} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
            <XAxis dataKey="date" tick={{ fontSize: 10 }} minTickGap={20} />
            <YAxis domain={[min - pad, max + pad]} tick={{ fontSize: 10 }} width={80} tickFormatter={(v) => nf.format(v)} />
            <Tooltip content={<SessionTooltip />} cursor={{ stroke: 'var(--chart-muted)', strokeDasharray: '3 3' }} />
            {view === 'courbes' && <Legend wrapperStyle={{ fontSize: 11 }} />}

            {view === 'courbes' ? (
              <>
                {/* Bande plus bas → plus haut : remplace la mèche du chandelier
                    par une zone lisible, même avec beaucoup de séances. */}
                <Area
                  dataKey="range"
                  name="Amplitude de la séance (plus bas → plus haut)"
                  stroke="none"
                  fill="var(--chart-soft)"
                  fillOpacity={0.35}
                  isAnimationActive={false}
                  activeDot={false}
                />
                <Line
                  dataKey="open"
                  name="Ouverture"
                  stroke="var(--chart-muted)"
                  strokeWidth={1.5}
                  strokeDasharray="4 3"
                  dot={false}
                  isAnimationActive={false}
                />
                <Line
                  dataKey="close"
                  name="Clôture"
                  stroke="var(--chart-1)"
                  strokeWidth={2.5}
                  dot={{ r: 3 }}
                  isAnimationActive={false}
                />
              </>
            ) : (
              <Bar dataKey="range" shape={<CandleShape />} isAnimationActive={false} />
            )}
          </ComposedChart>
        </ResponsiveContainer>

        {view === 'courbes' ? (
          <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
            La <strong>courbe pleine</strong> est le cours de <strong>clôture</strong>, celui qui fait référence :
            c'est le prix retenu en fin de séance. La <strong>courbe en pointillés</strong> est le cours
            d'<strong>ouverture</strong> : quand la clôture passe au-dessus, la séance a été gagnante, en dessous
            elle a été perdante. La <strong>zone grisée</strong> montre l'écart entre le plus bas et le plus haut
            atteints dans la journée : plus elle est épaisse, plus le prix a bougé avant de se fixer.
            {noAmplitude && (
              <>
                {' '}
                <strong>Ici la zone est invisible</strong> : sur toutes les séances affichées, le plus haut égale le
                plus bas — le titre s'est échangé à un prix unique chaque jour, ce qui est courant à la BRVM sur les
                valeurs peu animées.
              </>
            )}
          </p>
        ) : (
          <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Chaque chandelier résume une séance. Le <strong>corps</strong> va de l'ouverture à la clôture :{' '}
            <span className="text-emerald-600 dark:text-emerald-400">plein vert</span> si le titre a monté,{' '}
            <span className="text-red-600 dark:text-red-400">creux rouge</span> s'il a baissé, gris s'il a terminé à
            son prix d'ouverture. Le <strong>trait vertical</strong> relie le plus bas au plus haut de la journée.
            Sur peu de séances, les corps deviennent très larges : l'affichage en courbes reste alors plus lisible.
          </p>
        )}
      </Card>

      <Card title="Activité par séance">
        <label className="mb-2 block w-64">
          <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Mesure affichée</span>
          <Select value={metric} onChange={(e) => setMetric(e.target.value as 'turnover' | 'volume')}>
            <option value="turnover">Valeur transigée (FCFA)</option>
            <option value="volume">Nombre de titres échangés</option>
          </Select>
        </label>
        <ResponsiveContainer width="100%" height={200}>
          <BarChart data={sessions} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
            <XAxis dataKey="date" tick={{ fontSize: 10 }} minTickGap={20} />
            <YAxis tick={{ fontSize: 10 }} width={80} tickFormatter={(v) => nf.format(v)} />
            <ReferenceLine y={0} stroke="var(--chart-muted)" />
            <Tooltip
              content={({ active, payload }) => {
                if (!active || !payload?.length) return null
                const s = payload[0].payload as Session
                return (
                  <div className="rounded-md border border-gray-200 bg-white px-3 py-2 text-xs shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <div className="font-medium">{s.date}</div>
                    <div>Valeur transigée : {fmtF(s.turnover)}</div>
                    <div>Titres échangés : {nf.format(s.volume)}</div>
                    <div className="text-gray-500 dark:text-gray-400">Clôture : {fmtF(s.close)}</div>
                  </div>
                )
              }}
            />
            <Bar dataKey={metric} name={metric === 'turnover' ? 'Valeur transigée' : 'Titres échangés'}>
              {sessions.map((s, i) => (
                <Cell
                  key={i}
                  fill={s.rising ? 'var(--chart-positive)' : s.flat ? 'var(--chart-soft)' : 'var(--chart-negative)'}
                />
              ))}
            </Bar>
          </BarChart>
        </ResponsiveContainer>
        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
          La <strong>valeur transigée</strong> est l'argent réellement échangé dans la séance (titres × prix). Elle
          complète le nombre de titres : 500 actions à 29 000 F pèsent bien plus lourd que 5 000 actions à 100 F.
          Les barres sont vertes quand la séance a fini en hausse, rouges en baisse — ce qui montre d'un coup d'œil
          si les grosses journées se font plutôt à l'achat ou à la vente.
        </p>
      </Card>
    </>
  )
}
