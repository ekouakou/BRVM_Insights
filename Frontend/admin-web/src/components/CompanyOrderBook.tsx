import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Bar,
  CartesianGrid,
  Cell,
  ComposedChart,
  Line,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { callApi } from '../lib/apiClient'
import type {
  AbsorptionResult,
  ExecutionFlowResult,
  ExecutionIntervalRow,
  FixingPreviewResult,
  HeatmapResult,
  LiquidityScoreResult,
  OrderBookAnomaliesResult,
  OrderBookSnapshotsResult,
  OrderBookTimelineResult,
  PressureResult,
} from '../lib/types'
import { Card, ErrorState, InfoPanel, Input, LoadingState, Select, StatTile } from './ui'
import { ChartAiAnalysis } from './ChartAiAnalysis'
import { SellSimulator } from './SellSimulator'

/**
 * Onglets « Flux & pression » et « Carnet & liquidité » du tableau de bord
 * entreprise (voir TODO_CARNET_ORDRES.md). Règle d'affichage non
 * négociable : chaque chiffre porte sa nature — observé, calculé ou estimé
 * (pastilles NEUTRES en niveaux de gris, volontairement sans couleur pour ne
 * pas être confondues avec les couleurs des graphes) — une estimation n'est
 * JAMAIS présentée comme un fait.
 */

/**
 * Pastille de nature d'une donnée. Sans couleur (gris uniquement) : les
 * couleurs des graphes (vert/rouge/bleu…) portent un AUTRE message
 * (pression, côté du carnet…) — les mélanger rendrait la légende ambiguë.
 */
function Nature({ kind, className = '' }: { kind: 'observe' | 'calcule' | 'estime'; className?: string }) {
  const conf = {
    observe: { label: 'observé', cls: 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200' },
    calcule: { label: 'calculé', cls: 'border border-gray-400 text-gray-600 dark:border-gray-500 dark:text-gray-300' },
    estime: { label: 'estimé', cls: 'border border-dashed border-gray-400 italic text-gray-500 dark:border-gray-500 dark:text-gray-400' },
  }[kind]
  return (
    <span className={`inline-block rounded px-1 py-px align-middle text-[10px] font-medium not-italic leading-tight ${conf.cls} ${className}`}
      style={kind === 'estime' ? { fontStyle: 'italic' } : undefined}>
      {conf.label}
    </span>
  )
}

/** Carré de couleur reprenant EXACTEMENT une couleur utilisée dans les graphes. */
function Swatch({ color }: { color: string }) {
  return (
    <span
      className="inline-block h-3 w-3 rounded-sm border border-gray-300 align-middle dark:border-gray-600"
      style={{ backgroundColor: color }}
    />
  )
}

/**
 * Légende complète d'un onglet : nature des chiffres (pastilles grises),
 * couleurs réellement utilisées par les graphes de CET onglet, et
 * signification exacte de chaque valeur vide (« — », « n/d »).
 */
function OrderBookLegend({ variant }: { variant: 'flux' | 'carnet' }) {
  return (
    <div className="flex flex-col gap-2 rounded-md border border-gray-200 bg-gray-50 p-3 text-xs text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
      <div>
        <span className="font-semibold text-gray-700 dark:text-gray-200">Nature des chiffres</span>{' '}
        (pastilles grises, indépendantes des couleurs des graphes) :{' '}
        <Nature kind="observe" /> lu tel quel à la source (BRVM), sans transformation ·{' '}
        <Nature kind="calcule" /> arithmétique exacte sur des chiffres observés (ex. une différence entre deux relevés) ·{' '}
        <Nature kind="estime" /> hypothèse explicite dont la formule est affichée — jamais un fait certain.
      </div>
      <div>
        <span className="font-semibold text-gray-700 dark:text-gray-200">
          {variant === 'flux'
            ? 'Couleurs des graphes — chaque barre représente les titres échangés pendant ~10 minutes :'
            : 'Couleurs des graphes — chaque paire de barres représente le carnet en clôture d\'une séance :'}
        </span>
        {variant === 'flux' ? (
          <ul className="mt-0.5 flex flex-col gap-0.5 list-none pl-0">
            <li>
              <Swatch color="var(--chart-positive)" /> <strong>Pression acheteuse</strong> <Nature kind="estime" /> :
              pendant ces échanges, le prix est <em>monté</em>. On en déduit que les acheteurs étaient probablement
              les plus pressés : ils ont accepté de payer plus cher pour obtenir les titres. C'est une déduction à
              partir du sens du prix (méthode dite « tick rule »), pas une certitude.
            </li>
            <li>
              <Swatch color="var(--chart-negative)" /> <strong>Pression vendeuse</strong> <Nature kind="estime" /> :
              pendant ces échanges, le prix a <em>baissé</em>. Les vendeurs étaient probablement les plus pressés :
              ils ont accepté de brader un peu pour trouver preneur. Même méthode, même prudence.
            </li>
            <li>
              <Swatch color="var(--chart-soft)" /> <strong>Sens non estimable</strong> : des titres ont bien changé de
              mains, mais le prix n'a pas bougé — impossible de dire qui, des acheteurs ou des vendeurs, était à
              l'initiative. On l'affiche en gris plutôt que de deviner.
            </li>
            <li>
              <Swatch color="var(--chart-muted)" /> <strong>Fixing de clôture</strong> : en fin de journée, la bourse
              rassemble tous les ordres restants et les exécute en une seule fois, à un prix unique (le « fixing »).
              Cette barre regroupe les titres échangés à ce moment-là — sans heure précise ni sens estimable.
            </li>
            <li>
              <Swatch color="var(--chart-1)" /> <strong>Cours (ligne)</strong> : la ligne continue montre le prix de
              l'action au fil de la séance (échelle de droite). Elle permet de voir si les volumes échangés font
              monter ou baisser le prix.
            </li>
          </ul>
        ) : (
          <ul className="mt-0.5 flex flex-col gap-0.5 list-none pl-0">
            <li>
              <Swatch color="var(--chart-positive)" /> <strong>Demande</strong> : nombre de titres que des acheteurs
              attendaient d'acheter à la clôture (ordres d'achat en attente, au meilleur prix proposé).
            </li>
            <li>
              <Swatch color="var(--chart-negative)" /> <strong>Offre</strong> : nombre de titres que des vendeurs
              cherchaient à vendre à la clôture (ordres de vente en attente, au meilleur prix proposé).
            </li>
            <li>
              <Swatch color="var(--chart-1)" /> <strong>Cours de référence (ligne)</strong> : le prix officiel du
              titre publié le même soir (échelle de droite).
            </li>
            <li>
              <Swatch color="var(--chart-2)" /> <strong>Spread % (ligne)</strong> : l'écart entre le prix demandé par
              les vendeurs et le prix offert par les acheteurs, en % — plus il est petit, plus il est facile
              d'acheter/vendre sans perdre au passage.
            </li>
            <li>
              <Swatch color="var(--chart-5)" /> <strong>Équilibre demande/offre (ligne)</strong> : la part de la
              demande dans le total (demande + offre). Au-dessus de 0,5 : plus d'acheteurs en attente que de
              vendeurs ; en dessous : l'inverse.
            </li>
          </ul>
        )}
      </div>
      <div>
        <span className="font-semibold text-gray-700 dark:text-gray-200">Valeurs vides — ce que « — » et « n/d » veulent dire :</span>
        {variant === 'flux' ? (
          <ul className="mt-0.5 list-disc pl-4">
            <li><strong>Pression « — »</strong> : le prix n'a pas bougé pendant l'intervalle (ou fixing de clôture) — le sens acheteur/vendeur n'est alors pas estimable, on ne l'invente pas.</li>
            <li><strong>Case vide dans la heatmap</strong> : aucun échange détecté sur ce créneau — ce n'est pas une donnée manquante, c'est une absence d'activité (information en soi).</li>
            <li><strong>Aucun intervalle listé</strong> : le titre n'a pas coté sur la période/créneau, ou les relevés intraday n'existent pas encore pour ces dates.</li>
          </ul>
        ) : (
          <ul className="mt-0.5 list-disc pl-4">
            <li><strong>Demande ou Offre « — »</strong> : ce côté du carnet était <em>vide</em> en clôture (aucun ordre en attente) — c'est une vraie information de liquidité, pas un trou de données.</li>
            <li><strong>Prix « Au marché »</strong> : des ordres sans prix limite attendent (quantité connue, prix non applicable).</li>
            <li><strong>Δ « — »</strong> : pas de carnet la veille (premier bulletin de la période, jour férié…) — aucune comparaison possible.</li>
            <li><strong>Échangé « — »</strong> : pas de flux intraday consolidé ce jour-là — le taux d'absorption n'est alors pas calculé plutôt que deviné.</li>
            <li><strong>Sous-score « n/d »</strong> : pas assez d'historique pour le calculer — le score global est renormalisé sur les seuls sous-scores disponibles (voir « couverture »).</li>
          </ul>
        )}
      </div>
    </div>
  )
}

const nf = new Intl.NumberFormat('fr-FR')

function fmtInt(v: number | string | null | undefined): string {
  if (v === null || v === undefined) return '—'
  return nf.format(Math.round(Number(v)))
}

function fmtPrice(v: number | string | null | undefined): string {
  if (v === null || v === undefined) return '—'
  return nf.format(Number(v)) + ' F'
}

/** Créneaux horaires proposés (séance BRVM ~9h à ~14h45). */
const TIME_SLOTS = [
  { id: 'all', label: 'Toute la séance', start: '', end: '' },
  { id: 'matin', label: 'Matin (9h-12h)', start: '09:00', end: '12:00' },
  { id: 'apresmidi', label: 'Après-midi (12h-14h45)', start: '12:00', end: '14:45' },
  { id: 'custom', label: 'Créneau personnalisé…', start: '', end: '' },
] as const

// ---------------------------------------------------------------------------
// Onglet 1 : Flux & pression (exécutions intraday)
// ---------------------------------------------------------------------------

export function ExecutionFlowPanel({
  companyId,
  startDate,
  endDate,
}: {
  companyId: number
  startDate: string
  endDate: string
}) {
  const [slotId, setSlotId] = useState<(typeof TIME_SLOTS)[number]['id']>('all')
  const [customStart, setCustomStart] = useState('09:00')
  const [customEnd, setCustomEnd] = useState('14:45')
  const [heatmapMetric, setHeatmapMetric] = useState<'volume' | 'pression'>('volume')

  const slot = TIME_SLOTS.find((s) => s.id === slotId) ?? TIME_SLOTS[0]
  const timeStart = slotId === 'custom' ? customStart : slot.start
  const timeEnd = slotId === 'custom' ? customEnd : slot.end

  const flowQuery = useQuery({
    queryKey: ['ob-flow', companyId, startDate, endDate, timeStart, timeEnd],
    queryFn: () =>
      callApi<ExecutionFlowResult>('api_order_book.php', 'execution_flow', {
        company_id: companyId,
        start_date: startDate,
        end_date: endDate,
        time_start: timeStart || undefined,
        time_end: timeEnd || undefined,
      }),
    enabled: !!companyId,
  })

  const pressureQuery = useQuery({
    queryKey: ['ob-pressure', companyId, startDate, endDate, timeStart, timeEnd],
    queryFn: () =>
      callApi<PressureResult>('api_order_book.php', 'pressure', {
        company_id: companyId,
        start_date: startDate,
        end_date: endDate,
        time_start: timeStart || undefined,
        time_end: timeEnd || undefined,
      }),
    enabled: !!companyId,
  })

  const heatmapQuery = useQuery({
    queryKey: ['ob-heatmap', companyId, startDate, endDate],
    queryFn: () =>
      callApi<HeatmapResult>('api_order_book.php', 'heatmap', {
        company_id: companyId,
        start_date: startDate,
        end_date: endDate,
        slot_minutes: 30,
      }),
    enabled: !!companyId,
  })

  const intervals = flowQuery.data?.intervals ?? []
  const singleDay = useMemo(() => new Set(intervals.map((i) => i.trading_date)).size <= 1, [intervals])

  const chartData = useMemo(
    () =>
      intervals.map((iv) => ({
        ...iv,
        label: iv.is_closing_auction
          ? (singleDay ? 'Clôture' : `${iv.trading_date.slice(5)} clôture`)
          : singleDay
            ? iv.interval_end.slice(11, 16)
            : `${iv.trading_date.slice(5)} ${iv.interval_end.slice(11, 16)}`,
        price: iv.price_end !== null ? Number(iv.price_end) : null,
      })),
    [intervals, singleDay],
  )

  const totalExecuted = intervals.reduce((s, iv) => s + iv.executed_volume, 0)
  const hasLive = intervals.some((iv) => iv.live === 1)

  return (
    <div className="flex flex-col gap-4">
      <Card>
        <div className="flex flex-wrap items-end gap-3">
          <label className="w-56">
            <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Créneau horaire</span>
            <Select value={slotId} onChange={(e) => setSlotId(e.target.value as typeof slotId)}>
              {TIME_SLOTS.map((s) => (
                <option key={s.id} value={s.id}>{s.label}</option>
              ))}
            </Select>
          </label>
          {slotId === 'custom' && (
            <>
              <label className="w-32">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">De</span>
                <Input type="time" value={customStart} onChange={(e) => setCustomStart(e.target.value)} />
              </label>
              <label className="w-32">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">À</span>
                <Input type="time" value={customEnd} onChange={(e) => setCustomEnd(e.target.value)} />
              </label>
            </>
          )}
          <div className="ml-auto text-right">
            <div className="text-xs text-gray-500 dark:text-gray-400">Volume exécuté sur la sélection <Nature kind="calcule" /></div>
            <div className="text-lg font-semibold text-gray-900 dark:text-gray-100">{fmtInt(totalExecuted)} titres</div>
          </div>
        </div>
        {hasLive && (
          <p className="mt-2 text-xs text-amber-600 dark:text-amber-400">
            Séance du jour en cours : intervalles calculés en direct, consolidés après la clôture.
          </p>
        )}
      </Card>

      <OrderBookLegend variant="flux" />

      <InfoPanel title="Comment est calculé le fixing de clôture ?">
        <div className="flex flex-col gap-2">
          <p>
            En fin de séance, la bourse cesse d'exécuter les ordres au fil de l'eau et passe en{' '}
            <strong>enchère de clôture</strong>, en trois temps :
          </p>
          <ol className="list-decimal pl-5 flex flex-col gap-1">
            <li>
              <strong>Accumulation</strong> — pendant quelques minutes (la « pré-clôture »), les ordres d'achat et de
              vente s'empilent dans le carnet <em>sans être exécutés</em>. Chacun indique son prix limite (ou « au
              marché » = à n'importe quel prix).
            </li>
            <li>
              <strong>Calcul du prix d'équilibre</strong> — l'ordinateur de la bourse teste chaque prix possible et
              compte, pour chacun, combien de titres pourraient s'échanger : tous les acheteurs prêts à payer{' '}
              <em>au moins</em> ce prix face à tous les vendeurs prêts à vendre <em>au plus</em> ce prix. Le prix
              retenu est celui qui <strong>maximise le nombre de titres échangés</strong> (en cas d'égalité : celui
              qui laisse le moins d'ordres non servis, puis le plus proche du dernier cours).
            </li>
            <li>
              <strong>Exécution unique</strong> — tous les ordres compatibles sont exécutés d'un coup,{' '}
              <strong>au même prix</strong> pour tout le monde : celui qui offrait 5 600 F et celui qui offrait
              5 590 F paient tous les deux le prix de fixing. Ce prix devient le <strong>cours de clôture
              officiel</strong> — c'est aussi pour cela qu'il sert de cours de référence le lendemain.
            </li>
          </ol>
          <p><strong>Exemple chiffré</strong> — carnet au moment du fixing :</p>
          <div className="overflow-x-auto">
            <table className="text-xs tabular-nums">
              <thead>
                <tr className="text-gray-500 dark:text-gray-400">
                  <th className="pr-4 text-left font-medium">Prix testé</th>
                  <th className="pr-4 text-right font-medium">Acheteurs prêts à payer ≥ ce prix</th>
                  <th className="pr-4 text-right font-medium">Vendeurs prêts à vendre ≤ ce prix</th>
                  <th className="text-right font-medium">Titres échangeables (le minimum des deux)</th>
                </tr>
              </thead>
              <tbody className="text-gray-700 dark:text-gray-300">
                <tr><td className="pr-4">5 600 F</td><td className="pr-4 text-right">200</td><td className="pr-4 text-right">900</td><td className="text-right">200</td></tr>
                <tr><td className="pr-4">5 590 F</td><td className="pr-4 text-right">450</td><td className="pr-4 text-right">700</td><td className="text-right">450</td></tr>
                <tr className="font-semibold"><td className="pr-4">5 585 F ←</td><td className="pr-4 text-right">800</td><td className="pr-4 text-right">500</td><td className="text-right">500 (maximum)</td></tr>
                <tr><td className="pr-4">5 580 F</td><td className="pr-4 text-right">1 100</td><td className="pr-4 text-right">300</td><td className="text-right">300</td></tr>
              </tbody>
            </table>
          </div>
          <p>
            Le fixing se fait à <strong>5 585 F</strong> : c'est le prix où le plus de titres changent de mains
            (500). Les 300 titres d'acheteurs non servis (800 − 500) restent au carnet — on les retrouve le soir même
            dans l'onglet « Carnet &amp; liquidité » comme quantité résiduelle à l'achat.
          </p>
          <p className="text-gray-500 dark:text-gray-400">
            Pourquoi une barre « fixing de clôture » dans le graphe ci-dessous : notre collecte s'arrête avant cette
            enchère finale. Quand le volume officiel du jour dépasse le cumul de nos relevés, l'écart correspond aux
            titres échangés au fixing — la barre grise le représente, sans heure précise ni sens acheteur/vendeur
            (tout le monde échange au même prix, la « tick rule » ne s'applique pas).
          </p>
          <FixingRealData companyId={companyId} />
        </div>
      </InfoPanel>

      <Card title="Volumes exécutés par intervalle (~10 min) et cours">
        {flowQuery.isLoading && <LoadingState />}
        {flowQuery.error && <ErrorState message={(flowQuery.error as Error).message} />}
        {flowQuery.data && intervals.length === 0 && (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Aucun échange détecté sur la période/créneau — le titre n'a pas (ou peu) coté.
          </p>
        )}
        {intervals.length > 0 && (
          <>
            <ResponsiveContainer width="100%" height={300}>
              <ComposedChart data={chartData} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                <XAxis dataKey="label" tick={{ fontSize: 10 }} interval="preserveStartEnd" />
                <YAxis yAxisId="vol" tick={{ fontSize: 10 }} width={70} tickFormatter={(v) => nf.format(v)} />
                <YAxis yAxisId="price" orientation="right" tick={{ fontSize: 10 }} width={70}
                  domain={['auto', 'auto']} tickFormatter={(v) => nf.format(v)} />
                <Tooltip
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const d = payload[0].payload as (typeof chartData)[number]
                    return (
                      <div className="rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <div className="font-medium">{d.trading_date} {d.is_closing_auction ? '(fixing de clôture)' : d.interval_end.slice(11, 16)}</div>
                        <div>Volume exécuté (calculé) : {fmtInt(d.executed_volume)}</div>
                        <div>Cours : {fmtPrice(d.price)}</div>
                        {d.pressure_side && <div>Pression (estimée) : {d.pressure_side}</div>}
                        {d.live === 1 && <div className="text-amber-500">Séance en cours (non consolidé)</div>}
                      </div>
                    )
                  }}
                />
                <Bar yAxisId="vol" dataKey="executed_volume" name="Volume exécuté">
                  {chartData.map((d, i) => (
                    <Cell
                      key={i}
                      fill={
                        d.is_closing_auction
                          ? 'var(--chart-muted)'
                          : d.pressure_side === 'achat'
                            ? 'var(--chart-positive)'
                            : d.pressure_side === 'vente'
                              ? 'var(--chart-negative)'
                              : 'var(--chart-soft)'
                      }
                    />
                  ))}
                </Bar>
                <Line yAxisId="price" dataKey="price" name="Cours" stroke="var(--chart-1)" dot={false} strokeWidth={1.5} connectNulls />
              </ComposedChart>
            </ResponsiveContainer>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              Barres : <Swatch color="var(--chart-positive)" /> pression acheteuse <Nature kind="estime" /> ·{' '}
              <Swatch color="var(--chart-negative)" /> pression vendeuse <Nature kind="estime" /> ·{' '}
              <Swatch color="var(--chart-soft)" /> sens non estimable (prix inchangé) ·{' '}
              <Swatch color="var(--chart-muted)" /> fixing de clôture · <Swatch color="var(--chart-1)" /> cours (ligne).{' '}
              {flowQuery.data?.note}
            </p>
          </>
        )}
      </Card>

      <Card title="Pression acheteuse / vendeuse par séance (estimée)">
        {pressureQuery.isLoading && <LoadingState />}
        {pressureQuery.error && <ErrorState message={(pressureQuery.error as Error).message} />}
        {pressureQuery.data && pressureQuery.data.days.length > 0 && (
          <>
            <ResponsiveContainer width="100%" height={220}>
              <ComposedChart data={pressureQuery.data.days} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                <XAxis dataKey="trading_date" tick={{ fontSize: 10 }} tickFormatter={(d: string) => d.slice(5)} />
                <YAxis tick={{ fontSize: 10 }} width={70} tickFormatter={(v) => nf.format(v)} />
                <Tooltip
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const d = payload[0].payload as PressureResult['days'][number]
                    return (
                      <div className="rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <div className="font-medium">{d.trading_date} — dominante : {d.dominant} (estimé)</div>
                        <div>Volume acheteur estimé : {fmtInt(d.buy_volume)}</div>
                        <div>Volume vendeur estimé : {fmtInt(d.sell_volume)}</div>
                        <div>Non classé : {fmtInt(d.neutral_volume)}</div>
                        <div>Total échangé (calculé) : {fmtInt(d.total_volume)}</div>
                      </div>
                    )
                  }}
                />
                <ReferenceLine y={0} stroke="var(--chart-muted)" />
                <Bar dataKey="net_volume" name="Pression nette">
                  {pressureQuery.data.days.map((d, i) => (
                    <Cell key={i} fill={d.net_volume >= 0 ? 'var(--chart-positive)' : 'var(--chart-negative)'} />
                  ))}
                </Bar>
              </ComposedChart>
            </ResponsiveContainer>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{pressureQuery.data.note}</p>
          </>
        )}
        {pressureQuery.data && pressureQuery.data.days.length === 0 && (
          <p className="text-sm text-gray-500 dark:text-gray-400">Aucune donnée sur la période.</p>
        )}
      </Card>

      <Card title="Heatmap jour × tranche horaire">
        <div className="mb-2 flex items-center gap-3">
          <Select value={heatmapMetric} onChange={(e) => setHeatmapMetric(e.target.value as 'volume' | 'pression')}>
            <option value="volume">Intensité = volume exécuté (calculé)</option>
            <option value="pression">Intensité = pression nette (estimée)</option>
          </Select>
        </div>
        {heatmapQuery.isLoading && <LoadingState />}
        {heatmapQuery.error && <ErrorState message={(heatmapQuery.error as Error).message} />}
        {heatmapQuery.data && <HeatmapGrid data={heatmapQuery.data} metric={heatmapMetric} />}
      </Card>

      <Card title="Détail des intervalles">
        {intervals.length > 0 ? (
          <div className="max-h-96 overflow-y-auto overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="sticky top-0 bg-white dark:bg-gray-950">
                <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  <th className="pb-2 pr-3">Intervalle</th>
                  <th className="pb-2 pr-3 text-right">Cours fin <Nature kind="observe" /></th>
                  <th className="pb-2 pr-3 text-right">Volume exécuté <Nature kind="calcule" /></th>
                  <th className="pb-2 pr-3 text-right">Valeur (FCFA) <Nature kind="calcule" /></th>
                  <th className="pb-2">Pression <Nature kind="estime" /></th>
                </tr>
              </thead>
              <tbody>
                {intervals.map((iv, i) => (
                  <tr key={i} className="border-t border-gray-100 dark:border-gray-800">
                    <td className="py-1.5 pr-3 whitespace-nowrap tabular-nums">
                      {iv.trading_date} {iv.is_closing_auction ? '— fixing de clôture' : `${iv.interval_start.slice(11, 16)} → ${iv.interval_end.slice(11, 16)}`}
                      {iv.live === 1 && <span className="ml-1 text-amber-500">(en cours)</span>}
                    </td>
                    <td className="py-1.5 pr-3 text-right tabular-nums">{fmtPrice(iv.price_end)}</td>
                    <td className="py-1.5 pr-3 text-right tabular-nums font-medium">{fmtInt(iv.executed_volume)}</td>
                    <td className="py-1.5 pr-3 text-right tabular-nums">{fmtInt(iv.executed_value)}</td>
                    <td className="py-1.5">
                      {iv.pressure_side === 'achat' && <span className="text-emerald-600 dark:text-emerald-400">Acheteurs</span>}
                      {iv.pressure_side === 'vente' && <span className="text-red-600 dark:text-red-400">Vendeurs</span>}
                      {iv.pressure_side === null && <span className="text-gray-400">—</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="text-sm text-gray-500 dark:text-gray-400">Aucun intervalle sur la sélection.</p>
        )}
      </Card>

      {intervals.length > 0 && (
        <ChartAiAnalysis
          chartType="execution_flow"
          parameters={{
            company_id: companyId,
            start_date: startDate,
            end_date: endDate,
            time_start: timeStart || null,
            time_end: timeEnd || null,
          }}
          data={{ intervals, pressure_days: pressureQuery.data?.days ?? [] }}
          companyIdsForReports={[companyId]}
        />
      )}
    </div>
  )
}

/**
 * Section « avec les VRAIES données » du panneau fixing : dernier fixing
 * réellement observé pour l'entreprise + carnet résiduel = point de départ
 * de la prochaine séance (« une longueur d'avance »). La grille des prix
 * testés par l'enchère n'est pas publiée par la BRVM — on n'affiche que ce
 * qui est observable, avec les pastilles de nature habituelles.
 */
function FixingRealData({ companyId }: { companyId: number }) {
  const query = useQuery({
    queryKey: ['ob-fixing-preview', companyId],
    queryFn: () => callApi<FixingPreviewResult>('api_order_book.php', 'fixing_preview', { company_id: companyId }),
    enabled: !!companyId,
  })

  if (query.isLoading) return <LoadingState label="Chargement des données réelles…" />
  if (query.error) return <ErrorState message={(query.error as Error).message} />
  const d = query.data
  if (!d) return null
  const fix = d.last_fixing
  const next = d.next_session
  const book = d.residual_book

  return (
    <div className="mt-1 flex flex-col gap-3 rounded-md border border-gray-300 bg-white p-3 dark:border-gray-700 dark:bg-gray-950">
      <p className="font-semibold text-gray-800 dark:text-gray-100">
        Et avec les vraies données de cette entreprise :
      </p>

      {fix ? (
        <div>
          <p className="font-medium text-gray-700 dark:text-gray-200">
            Dernier fixing détecté — séance du <span className="tabular-nums">{fix.trading_date}</span>
          </p>
          <ul className="mt-1 list-disc pl-4">
            <li>
              Titres échangés à l'enchère de clôture <Nature kind="calcule" /> :{' '}
              <strong className="tabular-nums">{fmtInt(fix.fixing_volume)}</strong>
              {fix.fixing_share_percent !== null && !fix.coverage_caveat && (
                <> — soit {fix.fixing_share_percent}% des {fmtInt(fix.day_volume)} titres échangés ce jour-là <Nature kind="calcule" /></>
              )}
            </li>
            <li>
              Prix de fixing = cours de clôture officiel <Nature kind="observe" /> :{' '}
              <strong className="tabular-nums">{fmtPrice(fix.fixing_price)}</strong>
            </li>
          </ul>
          {fix.coverage_caveat && (
            <p className="mt-1 text-amber-600 dark:text-amber-400">
              Prudence : ce jour-là, nos relevés intraday n'ont couvert qu'une petite partie de la séance — ce volume
              inclut donc probablement bien plus que la seule enchère de clôture.
            </p>
          )}
        </div>
      ) : (
        <p className="text-gray-500 dark:text-gray-400">
          Aucun fixing distinct détecté pour cette entreprise : sur les séances consolidées, nos relevés couvraient
          déjà tout le volume officiel du jour (écart nul).
        </p>
      )}

      {book && next && (
        <div>
          <p className="font-medium text-gray-700 dark:text-gray-200">
            La longueur d'avance — carnet resté en attente après la clôture du{' '}
            <span className="tabular-nums">{book.snapshot_date}</span> <Nature kind="observe" /> :
          </p>
          <div className="mt-1 overflow-x-auto">
            <table className="text-xs tabular-nums">
              <thead>
                <tr className="text-gray-500 dark:text-gray-400">
                  <th className="pr-4 text-left font-medium">Côté</th>
                  <th className="pr-4 text-right font-medium">Titres en attente</th>
                  <th className="pr-4 text-right font-medium">Au prix de</th>
                  <th className="text-left font-medium">Ce que ça veut dire pour la prochaine séance <Nature kind="estime" /></th>
                </tr>
              </thead>
              <tbody className="text-gray-700 dark:text-gray-300">
                <tr>
                  <td className="pr-4"><Swatch color="var(--chart-positive)" /> Demande (achat)</td>
                  <td className="pr-4 text-right">{fmtInt(book.bid_residual_qty)}</td>
                  <td className="pr-4 text-right">{book.bid_at_market ? 'Au marché' : fmtPrice(book.best_bid_price)}</td>
                  <td>
                    {next.immediate_sell_capacity
                      ? `Un vendeur pressé trouverait preneur pour ~${fmtInt(next.immediate_sell_capacity.qty)} titres sans faire baisser le prix.`
                      : 'Aucun acheteur en attente : vendre vite obligerait à baisser le prix.'}
                  </td>
                </tr>
                <tr>
                  <td className="pr-4"><Swatch color="var(--chart-negative)" /> Offre (vente)</td>
                  <td className="pr-4 text-right">{fmtInt(book.ask_residual_qty)}</td>
                  <td className="pr-4 text-right">{book.ask_at_market ? 'Au marché' : fmtPrice(book.best_ask_price)}</td>
                  <td>
                    {next.immediate_buy_capacity
                      ? `Un acheteur pressé serait servi pour ~${fmtInt(next.immediate_buy_capacity.qty)} titres sans faire monter le prix.`
                      : 'Aucun vendeur en attente : acheter vite obligerait à surenchérir.'}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p className="mt-1">
            Cours de référence pour la prochaine séance <Nature kind="observe" /> :{' '}
            <strong className="tabular-nums">{fmtPrice(next.reference_price)}</strong>
            {next.spread_percent !== null && (
              <> · écart acheteurs/vendeurs au départ <Nature kind="calcule" /> :{' '}
                <strong className="tabular-nums">{Number(next.spread_percent).toFixed(2)}%</strong></>
            )}
            {next.imbalance_ratio !== null && (
              <> · rapport de force <Nature kind="calcule" /> :{' '}
                <strong className="tabular-nums">{Math.round(Number(next.imbalance_ratio) * 100)}%</strong> de la file
                d'attente côté acheteurs{Number(next.imbalance_ratio) < 0.5 ? ' (les vendeurs dominent)' : ' (les acheteurs dominent)'}</>
            )}
          </p>
          {!d.same_date && fix && (
            <p className="mt-1 text-gray-500 dark:text-gray-400">
              Nota : le dernier carnet publié ({book.snapshot_date}) et le dernier fixing détecté ({fix.trading_date})
              ne datent pas du même jour — les bulletins plus récents n'ont pas encore été traités.
            </p>
          )}
        </div>
      )}

      <p className="text-gray-500 dark:text-gray-400">{d.note}</p>
    </div>
  )
}

/** Heatmap CSS (recharts n'a pas de heatmap) : lignes = dates, colonnes = créneaux de 30 min. */
function HeatmapGrid({ data, metric }: { data: HeatmapResult; metric: 'volume' | 'pression' }) {
  const { dates, slots, byKey, maxAbs } = useMemo(() => {
    const dates = [...new Set(data.cells.map((c) => c.trading_date))].sort()
    const slots = [...new Set(data.cells.map((c) => c.slot.slice(0, 5)))].sort()
    const byKey = new Map<string, { volume: number; pression: number }>()
    let maxAbs = 0
    for (const c of data.cells) {
      const v = metric === 'volume' ? Number(c.executed_volume) : Number(c.net_pressure)
      byKey.set(`${c.trading_date}|${c.slot.slice(0, 5)}`, {
        volume: Number(c.executed_volume),
        pression: Number(c.net_pressure),
      })
      maxAbs = Math.max(maxAbs, Math.abs(v))
    }
    return { dates, slots, byKey, maxAbs }
  }, [data, metric])

  if (dates.length === 0) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">Aucun échange sur la période.</p>
  }

  return (
    <div className="overflow-x-auto">
      <table className="text-xs">
        <thead>
          <tr>
            <th className="pr-2 text-left font-medium text-gray-500 dark:text-gray-400">Date</th>
            {slots.map((s) => (
              <th key={s} className="px-0.5 pb-1 text-center font-normal text-gray-400 dark:text-gray-500">{s}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {dates.map((d) => (
            <tr key={d}>
              <td className="pr-2 tabular-nums text-gray-600 dark:text-gray-300 whitespace-nowrap">{d.slice(5)}</td>
              {slots.map((s) => {
                const cell = byKey.get(`${d}|${s}`)
                const v = cell ? (metric === 'volume' ? cell.volume : cell.pression) : 0
                const intensity = maxAbs > 0 ? Math.abs(v) / maxAbs : 0
                const color =
                  v === 0 || !cell
                    ? 'transparent'
                    : metric === 'volume'
                      ? `color-mix(in srgb, var(--chart-1) ${Math.round(15 + intensity * 85)}%, transparent)`
                      : v > 0
                        ? `color-mix(in srgb, var(--chart-positive) ${Math.round(15 + intensity * 85)}%, transparent)`
                        : `color-mix(in srgb, var(--chart-negative) ${Math.round(15 + intensity * 85)}%, transparent)`
                return (
                  <td key={s} className="p-0.5">
                    <div
                      className="h-6 w-10 rounded-sm border border-gray-100 dark:border-gray-800"
                      style={{ backgroundColor: color }}
                      title={cell ? `${d} ${s} — volume : ${fmtInt(cell.volume)} · pression nette : ${fmtInt(cell.pression)}` : `${d} ${s} — aucun échange détecté`}
                    />
                  </td>
                )
              })}
            </tr>
          ))}
        </tbody>
      </table>
      <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{data.note}</p>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Onglet 2 : Carnet & liquidité (snapshots BOC + score)
// ---------------------------------------------------------------------------

const SUB_SCORE_LABELS: Record<string, string> = {
  volume: 'Volume échangé',
  regularite: 'Régularité',
  spread: 'Spread',
  profondeur: 'Profondeur',
  absorption: 'Absorption',
  stabilite: 'Stabilité',
}

export function OrderBookLiquidityPanel({
  companyId,
  startDate,
  endDate,
}: {
  companyId: number
  startDate: string
  endDate: string
}) {
  const [sellQty, setSellQty] = useState('1000')

  const scoreQuery = useQuery({
    queryKey: ['ob-score', companyId, sellQty],
    queryFn: () =>
      callApi<LiquidityScoreResult>('api_order_book.php', 'liquidity_score', {
        company_id: companyId,
        quantity: Number(sellQty) > 0 ? Number(sellQty) : undefined,
      }),
    enabled: !!companyId,
  })

  const snapshotsQuery = useQuery({
    queryKey: ['ob-snapshots', companyId, startDate, endDate],
    queryFn: () =>
      callApi<OrderBookSnapshotsResult>('api_order_book.php', 'snapshots', {
        company_id: companyId,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!companyId,
  })

  const absorptionQuery = useQuery({
    queryKey: ['ob-absorption', companyId, startDate, endDate],
    queryFn: () =>
      callApi<AbsorptionResult>('api_order_book.php', 'absorption', {
        company_id: companyId,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!companyId,
  })

  const timelineQuery = useQuery({
    queryKey: ['ob-timeline', companyId, startDate, endDate],
    queryFn: () =>
      callApi<OrderBookTimelineResult>('api_order_book.php', 'timeline', {
        company_id: companyId,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!companyId,
  })

  const anomaliesQuery = useQuery({
    queryKey: ['ob-anomalies', companyId, startDate, endDate],
    queryFn: () =>
      callApi<OrderBookAnomaliesResult>('api_order_book.php', 'anomalies', {
        company_id: companyId,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!companyId,
  })

  const snapshots = snapshotsQuery.data?.snapshots ?? []
  const lastSnapshot = snapshots.length > 0 ? snapshots[snapshots.length - 1] : null
  const chartData = useMemo(
    () =>
      snapshots.map((s) => ({
        date: s.snapshot_date.slice(5),
        bid_qty: s.bid_residual_qty,
        ask_qty: s.ask_residual_qty,
        reference: s.reference_price !== null ? Number(s.reference_price) : null,
        spread: s.spread_percent !== null ? Number(s.spread_percent) : null,
        imbalance: s.imbalance_ratio !== null ? Number(s.imbalance_ratio) : null,
      })),
    [snapshots],
  )

  const score = scoreQuery.data

  return (
    <div className="flex flex-col gap-4">
      {lastSnapshot && (
        <p className="text-xs text-gray-500 dark:text-gray-400">
          Carnet <Nature kind="observe" /> : fin de séance du <strong>{lastSnapshot.snapshot_date}</strong> (Bulletin
          Officiel de la Cote) — meilleures limites uniquement, jamais du temps réel.
        </p>
      )}
      <OrderBookLegend variant="carnet" />

      <Card title="Score de liquidité (estimé)">
        {scoreQuery.isLoading && <LoadingState />}
        {scoreQuery.error && <ErrorState message={(scoreQuery.error as Error).message} />}
        {score && (
          <div className="flex flex-col gap-3">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
              <StatTile
                label={`Score global (couverture ${score.coverage_percent}%)`}
                value={score.score !== null ? `${score.score}/100` : '—'}
                tone={score.score !== null && score.score >= 70 ? 'positive' : score.score !== null && score.score < 45 ? 'negative' : 'default'}
              />
              {Object.entries(score.sub_scores).map(([key, v]) => (
                <StatTile
                  key={key}
                  label={`${SUB_SCORE_LABELS[key] ?? key} (${score.weights[key]}%)`}
                  value={v !== null ? String(v) : 'n/d'}
                />
              ))}
            </div>
            <p className="text-sm text-gray-700 dark:text-gray-300">{score.verdict}</p>

            <div className="rounded-md border border-gray-200 p-3 dark:border-gray-800">
              <div className="flex flex-wrap items-end gap-3">
                <label className="w-40">
                  <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                    « Si je veux vendre N actions… »
                  </span>
                  <Input type="number" min="1" value={sellQty} onChange={(e) => setSellQty(e.target.value)} />
                </label>
                {score.sell_estimate && score.sell_estimate.estimated_hours != null ? (
                  <div className="text-sm text-gray-700 dark:text-gray-300">
                    Temps estimé : <strong>{score.sell_estimate.estimated_hours} h de cotation</strong>
                    {' '}(≈ {score.sell_estimate.estimated_sessions} séance{Number(score.sell_estimate.estimated_sessions) > 1 ? 's' : ''})
                    — débit médian {fmtInt(score.sell_estimate.median_daily_volume)} titres/séance sur{' '}
                    {score.sell_estimate.active_days_basis} séances actives.
                    {score.sell_estimate.last_bid_residual && (
                      <> Demande affichée au dernier carnet (observée) : {fmtInt(score.sell_estimate.last_bid_residual.qty)} titres
                        ({score.sell_estimate.last_bid_residual.date}).</>
                    )}
                  </div>
                ) : (
                  <p className="text-sm text-gray-500 dark:text-gray-400">
                    {score.sell_estimate?.reason ?? 'Saisis une quantité pour estimer un temps de vente.'}
                  </p>
                )}
              </div>
              {score.sell_estimate?.formula && (
                <p className="mt-2 text-xs text-gray-400 dark:text-gray-500">Formule : {score.sell_estimate.formula}</p>
              )}
            </div>
            <p className="text-xs italic text-gray-400 dark:text-gray-500">{score.disclaimer}</p>
          </div>
        )}
      </Card>

      <SellSimulator companyId={companyId} />

      <Card title="Quantités résiduelles aux meilleures limites (fin de séance, observées)">
        {snapshotsQuery.isLoading && <LoadingState />}
        {snapshotsQuery.error && <ErrorState message={(snapshotsQuery.error as Error).message} />}
        {snapshots.length === 0 && snapshotsQuery.data && (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Aucun snapshot de carnet sur la période — élargis les dates ou lance l'extraction des bulletins
            (onglet Annonces BRVM des bulletins).
          </p>
        )}
        {snapshots.length > 0 && (
          <ResponsiveContainer width="100%" height={280}>
            <ComposedChart data={chartData} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
              <XAxis dataKey="date" tick={{ fontSize: 10 }} />
              <YAxis yAxisId="qty" tick={{ fontSize: 10 }} width={70} tickFormatter={(v) => nf.format(v)} />
              <YAxis yAxisId="price" orientation="right" tick={{ fontSize: 10 }} width={70} domain={['auto', 'auto']} tickFormatter={(v) => nf.format(v)} />
              <Tooltip
                content={({ active, payload }) => {
                  if (!active || !payload?.length) return null
                  const d = payload[0].payload as (typeof chartData)[number]
                  return (
                    <div className="rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                      <div className="font-medium">{d.date}</div>
                      <div className="text-emerald-600 dark:text-emerald-400">Demande (achat, observée) : {fmtInt(d.bid_qty)}</div>
                      <div className="text-red-600 dark:text-red-400">Offre (vente, observée) : {fmtInt(d.ask_qty)}</div>
                      <div>Cours de référence (observé) : {fmtPrice(d.reference)}</div>
                    </div>
                  )
                }}
              />
              <Bar yAxisId="qty" dataKey="bid_qty" name="Demande" fill="var(--chart-positive)" fillOpacity={0.75} />
              <Bar yAxisId="qty" dataKey="ask_qty" name="Offre" fill="var(--chart-negative)" fillOpacity={0.75} />
              <Line yAxisId="price" dataKey="reference" name="Cours de référence" stroke="var(--chart-1)" dot={false} strokeWidth={1.5} connectNulls />
            </ComposedChart>
          </ResponsiveContainer>
        )}
      </Card>

      {snapshots.length > 1 && (
        <Card title="Spread et équilibre du carnet (calculés)">
          <ResponsiveContainer width="100%" height={200}>
            <ComposedChart data={chartData} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
              <XAxis dataKey="date" tick={{ fontSize: 10 }} />
              <YAxis yAxisId="spread" tick={{ fontSize: 10 }} width={50} tickFormatter={(v) => `${v}%`} />
              <YAxis yAxisId="imb" orientation="right" domain={[0, 1]} tick={{ fontSize: 10 }} width={45} />
              <Tooltip
                content={({ active, payload }) => {
                  if (!active || !payload?.length) return null
                  const d = payload[0].payload as (typeof chartData)[number]
                  return (
                    <div className="rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                      <div className="font-medium">{d.date}</div>
                      <div>Spread (calculé) : {d.spread !== null ? `${d.spread}%` : '— (un des deux prix manque)'}</div>
                      <div>Part de la demande (calculée) : {d.imbalance !== null ? `${Math.round(d.imbalance * 100)}%` : '— (carnet vide)'}</div>
                    </div>
                  )
                }}
              />
              <ReferenceLine yAxisId="imb" y={0.5} stroke="var(--chart-muted)" strokeDasharray="4 4" />
              <Line yAxisId="spread" dataKey="spread" name="Spread %" stroke="var(--chart-2)" dot={false} connectNulls />
              <Line yAxisId="imb" dataKey="imbalance" name="Équilibre" stroke="var(--chart-5)" dot={false} connectNulls />
            </ComposedChart>
          </ResponsiveContainer>
          <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Équilibre = demande / (demande + offre) aux meilleures limites : au-dessus de 0,5 (pointillés), la demande
            domine ; en dessous, l'offre domine.
          </p>
        </Card>
      )}

      <Card title="Absorption : offre de la veille vs échanges du jour (estimée)">
        {absorptionQuery.isLoading && <LoadingState />}
        {absorptionQuery.error && <ErrorState message={(absorptionQuery.error as Error).message} />}
        {absorptionQuery.data && absorptionQuery.data.days.filter((d) => d.absorption_rate_percent !== null).length === 0 && (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Pas assez de données croisées (carnet veille + exécutions jour) sur la période.
          </p>
        )}
        {absorptionQuery.data && absorptionQuery.data.days.some((d) => d.absorption_rate_percent !== null) && (
          <>
            <ResponsiveContainer width="100%" height={200}>
              <ComposedChart data={absorptionQuery.data.days} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                <XAxis dataKey="trading_date" tick={{ fontSize: 10 }} tickFormatter={(d: string) => d.slice(5)} />
                <YAxis tick={{ fontSize: 10 }} width={50} tickFormatter={(v) => `${v}%`} />
                <Tooltip
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const d = payload[0].payload as AbsorptionResult['days'][number]
                    return (
                      <div className="max-w-xs rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <div className="font-medium">{d.trading_date}</div>
                        <div>Offert la veille en clôture (observé) : {fmtInt(d.offered_prev_close)}</div>
                        <div>Échangé ce jour (calculé) : {fmtInt(d.executed_volume)}</div>
                        <div>Taux (estimé) : {d.absorption_rate_percent !== null ? `${d.absorption_rate_percent}%` : '— (donnée veille ou flux du jour manquant)'}</div>
                        {d.reading && <div className="mt-1 text-gray-500 dark:text-gray-400">{d.reading}</div>}
                      </div>
                    )
                  }}
                />
                <ReferenceLine y={100} stroke="var(--chart-muted)" strokeDasharray="4 4" />
                <Bar dataKey="absorption_rate_percent" name="Taux d'absorption">
                  {absorptionQuery.data.days.map((d, i) => (
                    <Cell key={i} fill={(d.absorption_rate_percent ?? 0) >= 100 ? 'var(--chart-positive)' : 'var(--chart-2)'} />
                  ))}
                </Bar>
              </ComposedChart>
            </ResponsiveContainer>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{absorptionQuery.data.note}</p>
          </>
        )}
      </Card>

      {anomaliesQuery.data && anomaliesQuery.data.anomalies.length > 0 && (
        <Card title="⚠️ Anomalies statistiques sur la période (calculées)">
          <ul className="flex flex-col gap-1 text-sm">
            {anomaliesQuery.data.anomalies.map((a, i) => (
              <li key={i} className="text-gray-700 dark:text-gray-300">
                <span className="tabular-nums font-medium">{a.date}</span> — {a.metric} en {a.direction} :{' '}
                {fmtInt(a.value)} {a.unit} (moyenne {fmtInt(a.average)}, z = {a.z_score})
              </li>
            ))}
          </ul>
          <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">{anomaliesQuery.data.note}</p>
        </Card>
      )}

      <Card title="Timeline du carnet et des échanges">
        {timelineQuery.isLoading && <LoadingState />}
        {timelineQuery.data && timelineQuery.data.events.length === 0 && (
          <p className="text-sm text-gray-500 dark:text-gray-400">Aucun événement sur la période.</p>
        )}
        {timelineQuery.data && timelineQuery.data.events.length > 0 && (
          <ul className="max-h-80 overflow-y-auto flex flex-col gap-1 text-sm">
            {timelineQuery.data.events.map((e, i) => (
              <li key={i} className="flex items-baseline gap-2 text-gray-700 dark:text-gray-300">
                <span className="whitespace-nowrap tabular-nums text-xs text-gray-400 dark:text-gray-500">
                  {e.datetime.slice(0, 16)}
                </span>
                <span className={e.kind === 'carnet' ? 'text-gray-600 dark:text-gray-300' : ''}>
                  {(e.kind === 'execution' || e.kind === 'cloture') && <Nature kind="calcule" className="mr-1" />}
                  {e.kind === 'carnet' && <Nature kind="observe" className="mr-1" />}
                  {e.label}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card title="Détail des carnets de fin de séance">
        {snapshots.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  <th className="pb-2 pr-3">Date</th>
                  <th className="pb-2 pr-3 text-right">Demande <Nature kind="observe" /></th>
                  <th className="pb-2 pr-3 text-right">Achat <Nature kind="observe" /></th>
                  <th className="pb-2 pr-3 text-right">Vente <Nature kind="observe" /></th>
                  <th className="pb-2 pr-3 text-right">Offre <Nature kind="observe" /></th>
                  <th className="pb-2 pr-3 text-right">Δ Demande <Nature kind="calcule" /></th>
                  <th className="pb-2 pr-3 text-right">Δ Offre <Nature kind="calcule" /></th>
                  <th className="pb-2 pr-3 text-right">Échangé <Nature kind="calcule" /></th>
                  <th className="pb-2">Lecture <Nature kind="estime" /></th>
                </tr>
              </thead>
              <tbody>
                {snapshots.map((s) => (
                  <tr key={s.id} className="border-t border-gray-100 align-top dark:border-gray-800">
                    <td className="py-1.5 pr-3 whitespace-nowrap tabular-nums">{s.snapshot_date}</td>
                    <td className="py-1.5 pr-3 text-right tabular-nums">{fmtInt(s.bid_residual_qty)}</td>
                    <td className="py-1.5 pr-3 text-right tabular-nums whitespace-nowrap">
                      {s.bid_at_market ? 'Au marché' : fmtPrice(s.best_bid_price)}
                    </td>
                    <td className="py-1.5 pr-3 text-right tabular-nums whitespace-nowrap">
                      {s.ask_at_market ? 'Au marché' : fmtPrice(s.best_ask_price)}
                    </td>
                    <td className="py-1.5 pr-3 text-right tabular-nums">{fmtInt(s.ask_residual_qty)}</td>
                    <td className="py-1.5 pr-3 text-right tabular-nums">
                      {s.delta_bid_qty !== null ? (s.delta_bid_qty > 0 ? '+' : '') + fmtInt(s.delta_bid_qty) : '—'}
                    </td>
                    <td className="py-1.5 pr-3 text-right tabular-nums">
                      {s.delta_ask_qty !== null ? (s.delta_ask_qty > 0 ? '+' : '') + fmtInt(s.delta_ask_qty) : '—'}
                    </td>
                    <td className="py-1.5 pr-3 text-right tabular-nums">{fmtInt(s.executed_volume_day)}</td>
                    <td className="py-1.5 max-w-md text-xs text-gray-500 dark:text-gray-400">{s.reading ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="text-sm text-gray-500 dark:text-gray-400">Aucun carnet sur la période.</p>
        )}
      </Card>

      {snapshots.length > 0 && (
        <ChartAiAnalysis
          chartType="order_book_liquidity"
          parameters={{ company_id: companyId, start_date: startDate, end_date: endDate }}
          data={{
            snapshots,
            absorption_days: absorptionQuery.data?.days ?? [],
            liquidity_score: scoreQuery.data ?? null,
          }}
          companyIdsForReports={[companyId]}
        />
      )}
    </div>
  )
}
