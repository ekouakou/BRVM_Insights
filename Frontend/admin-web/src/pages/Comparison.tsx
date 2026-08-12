import { useId, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  BarChart,
  Bar,
  LineChart,
  Line,
  ResponsiveContainer,
  XAxis,
  YAxis,
  Tooltip,
  Legend,
  CartesianGrid,
  ReferenceLine,
} from 'recharts'
import { callApi } from '../lib/apiClient'
import type { Company, CompanyPriceSeries, CompanyWithReports, ComparisonResult, ShareTurnover } from '../lib/types'
import { Button, Card, ErrorState, InfoPanel, Input, LoadingState, Select, StarRating, Tabs } from '../components/ui'
import { IconButton, TrashIcon } from '../components/icons'
import { groupCompaniesBySector, useCompanyColors, usePersistedSelection } from '../lib/companyGroups'
import { SelectedCompanyColors } from '../components/CompanyColorPicker'
import { ChartAiAnalysis } from '../components/ChartAiAnalysis'
import { AnalysisHistoryList } from '../components/AnalysisHistoryList'
import { AI_MODELS, type AiProvider } from '../lib/aiModels'

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function daysAgoIso(n: number) {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return d.toISOString().slice(0, 10)
}

/**
 * Rendu du résultat d'une comparaison — extrait en composant pour
 * l'instancier deux fois côte à côte en mode "Comparer 2 fournisseurs".
 */
function ComparisonResultView({
  result,
  onRate,
  onDelete,
}: {
  result: ComparisonResult
  onRate?: (id: number, rating: number) => void
  onDelete?: (id: number) => void
}) {
  if (!result.analysis) return null

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <div className="mb-2 flex items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
          <span>
            {result.companies.map((c) => c.symbol).join(', ')} · {result.start_date} → {result.end_date} · {result.provider}/{result.model}
            {result.cached && ' · depuis le cache'}
          </span>
          <span className="flex items-center gap-2">
            {onRate && <StarRating value={result.rating} onChange={(rating) => onRate(result.id, rating)} />}
            {onDelete && (
              <IconButton
                title="Supprimer cette comparaison"
                tone="danger"
                onClick={() => {
                  if (window.confirm('Supprimer définitivement cette comparaison ? Cette action est irréversible.')) {
                    onDelete(result.id)
                  }
                }}
              >
                <TrashIcon />
              </IconButton>
            )}
          </span>
        </div>
        <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{result.analysis.comparative_summary}</p>
      </Card>

      {result.analysis.cross_company_ranking && (
        <Card title="Classement comparatif">
          <p className="text-sm text-gray-800 dark:text-gray-200">{result.analysis.cross_company_ranking}</p>
        </Card>
      )}

      {result.analysis.trend_analysis && result.analysis.trend_analysis.length > 0 && (
        <Card title="Tendance par entreprise">
          <div className="flex flex-col gap-4">
            {result.analysis.trend_analysis.map((t) => (
              <div key={t.company_symbol}>
                <div className="text-sm font-semibold">{t.company_symbol} — {t.company_name}</div>
                <div className="text-xs text-gray-500 dark:text-gray-400">
                  CA: {t.revenue_trend_percent ?? '—'}% · Résultat net: {t.net_income_trend_percent ?? '—'}%
                </div>
                <p className="mt-1 text-sm text-gray-700 dark:text-gray-300">{t.narrative}</p>
              </div>
            ))}
          </div>
        </Card>
      )}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {result.analysis.price_correlation_note && (
          <Card title="Corrélation cours / fondamentaux">
            <p className="text-sm text-gray-800 dark:text-gray-200">{result.analysis.price_correlation_note}</p>
          </Card>
        )}
        {result.analysis.risks_evolution && (
          <Card title="Évolution des risques">
            <p className="text-sm text-gray-800 dark:text-gray-200">{result.analysis.risks_evolution}</p>
          </Card>
        )}
      </div>

      {result.analysis.decision_support_notes && result.analysis.decision_support_notes.length > 0 && (
        <Card title="Points d’appui à la décision">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {result.analysis.decision_support_notes.map((d) => (
              <div key={d.company_symbol} className="rounded-md border border-gray-200 p-3 dark:border-gray-800">
                <div className="mb-1 text-sm font-semibold">{d.company_symbol}</div>
                <div className="mb-1 text-sm"><span className="font-medium text-emerald-600 dark:text-emerald-400">Bull:</span> {d.bull_case}</div>
                <div className="mb-1 text-sm"><span className="font-medium text-red-600 dark:text-red-400">Bear:</span> {d.bear_case}</div>
                <ul className="mt-1 list-disc pl-4 text-xs text-gray-600 dark:text-gray-400">
                  {d.key_watch_points.map((p, i) => <li key={i}>{p}</li>)}
                </ul>
              </div>
            ))}
          </div>
        </Card>
      )}

      {result.chart_data?.price_series.map((serie) => (
        serie.data.length >= 2 && (
          <Card key={serie.company_id} title={`Cours — ${serie.symbol}`}>
            <ResponsiveContainer width="100%" height={200}>
              <LineChart data={serie.data}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                <YAxis domain={['auto', 'auto']} tick={{ fontSize: 11 }} width={70} />
                <Tooltip />
                <Line type="monotone" dataKey="close" stroke="var(--chart-1)" dot={false} strokeWidth={2} />
              </LineChart>
            </ResponsiveContainer>
          </Card>
        )
      ))}

      {result.chart_data?.financials_series.map((serie) => (
        serie.data.length > 0 && (
          <Card key={serie.company_id} title={`Chiffres clés dans le temps — ${serie.symbol}`}>
            <ResponsiveContainer width="100%" height={220}>
              <BarChart data={serie.data}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                <XAxis dataKey="publish_date" tick={{ fontSize: 11 }} />
                <YAxis tick={{ fontSize: 11 }} width={80} />
                <Tooltip />
                <Bar dataKey="revenue" fill="var(--chart-1)" name="Chiffre d'affaires" />
                <Bar dataKey="net_income" fill="var(--chart-soft)" name="Résultat net" />
              </BarChart>
            </ResponsiveContainer>
          </Card>
        )
      ))}

      <p className="text-xs italic text-gray-400">{result.disclaimer}</p>
    </div>
  )
}

export function Comparison() {
  // Sélection d'entreprises unique, partagée par toutes les comparaisons de
  // cette page (rapports IA, cours, volumes, rotation du flottant) — tout ce
  // qui est "comparaison entre entreprises" vit ici, plus dans la page
  // Cotations (qui reste dédiée à une entreprise à la fois).
  const queryClient = useQueryClient()
  const [activeTab, setActiveTab] = useState<'price' | 'volume' | 'turnover' | 'reports'>('price')
  const [selected, setSelected] = usePersistedSelection('brvm_comparison_selection')
  const { colorFor, setColor, overrides: colorOverrides } = useCompanyColors()
  const [historyOverride, setHistoryOverride] = useState<ComparisonResult | null>(null)
  const [compareMode, setCompareMode] = useState(false)
  const [compareLeftId, setCompareLeftId] = useState<number | null>(null)
  const [compareRightId, setCompareRightId] = useState<number | null>(null)

  const [startDate, setStartDate] = useState('2023-01-01')
  const [endDate, setEndDate] = useState(todayIso())
  const [reportType, setReportType] = useState('')
  const [provider, setProvider] = useState<AiProvider>('gemini')
  const [model, setModel] = useState('')
  const modelListId = useId()

  const [priceGranularity, setPriceGranularity] = useState<'daily' | 'intraday'>('daily')
  const [priceStartDate, setPriceStartDate] = useState(daysAgoIso(180))
  const [priceEndDate, setPriceEndDate] = useState(todayIso())
  const [showPercent, setShowPercent] = useState(true)

  const [volumeStartDate, setVolumeStartDate] = useState(todayIso())
  const [volumeEndDate, setVolumeEndDate] = useState(todayIso())

  const companiesQuery = useQuery({
    queryKey: ['companies-list'],
    queryFn: () => callApi<Company[]>('api_companies.php', 'list', { per_page: 200, active: 1 }),
  })

  // Nombre de rapports au texte extrait par entreprise — la comparaison IA
  // (class/ReportComparisonService.php) exige text_extracted=1 sur les
  // rapports pris en compte. Une entreprise sans rapport traité reste
  // sélectionnable (utile pour les cours/volumes/rotation), mais est exclue
  // de l'appel à l'analyse IA elle-même — voir aiReadySelected plus bas.
  const reportsReadinessQuery = useQuery({
    queryKey: ['companies-with-reports'],
    queryFn: () => callApi<CompanyWithReports[]>('api_reports.php', 'list_companies'),
  })
  const readyCompanyIds = new Set(
    (reportsReadinessQuery.data ?? []).filter((c) => (c.reports_with_text ?? 0) > 0).map((c) => c.company_id)
  )
  const aiReadySelected = selected.filter((id) => readyCompanyIds.has(id))
  const notReadySelected = selected.filter((id) => !readyCompanyIds.has(id))

  const compareMutation = useMutation({
    mutationFn: (forceRefresh: boolean) =>
      callApi<ComparisonResult>('api_report_comparison.php', 'compare', {
        company_ids: aiReadySelected,
        start_date: startDate,
        end_date: endDate,
        report_type: reportType || undefined,
        provider,
        model: model || undefined,
        force_refresh: forceRefresh,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['report-comparison-history', aiReadySelected, startDate, endDate, reportType] })
    },
  })

  const historyQuery = useQuery({
    queryKey: ['report-comparison-history', aiReadySelected, startDate, endDate, reportType],
    queryFn: () =>
      callApi<ComparisonResult[]>('api_report_comparison.php', 'history', {
        company_ids: aiReadySelected,
        start_date: startDate,
        end_date: endDate,
        report_type: reportType || undefined,
      }),
    enabled: aiReadySelected.length > 0,
  })

  const rateMutation = useMutation({
    mutationFn: ({ id, rating }: { id: number; rating: number }) =>
      callApi<ComparisonResult>('api_report_comparison.php', 'rate', { id, rating }),
    onSuccess: (updated) => {
      queryClient.invalidateQueries({ queryKey: ['report-comparison-history', aiReadySelected, startDate, endDate, reportType] })
      setHistoryOverride((prev) => (prev && prev.id === updated.id ? updated : prev))
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => callApi<null>('api_report_comparison.php', 'delete', { id }),
    onSuccess: (_data, id) => {
      queryClient.invalidateQueries({ queryKey: ['report-comparison-history', aiReadySelected, startDate, endDate, reportType] })
      setHistoryOverride((prev) => (prev && prev.id === id ? null : prev))
      if (compareMutation.data?.id === id) compareMutation.reset()
      if (compareLeftId === id) setCompareLeftId(null)
      if (compareRightId === id) setCompareRightId(null)
    },
  })

  // Cours comparés (clôtures quotidiennes ou relevés intrajournaliers) —
  // repris de l'ancienne section "Comparaison" de la page Cotations.
  const priceIsLive = priceGranularity === 'intraday' && priceEndDate === todayIso()
  const priceQuery = useQuery({
    queryKey: ['compare-price', selected, priceGranularity, priceStartDate, priceEndDate],
    queryFn: () =>
      callApi<CompanyPriceSeries[]>('api_quotes.php', 'compare', {
        company_ids: selected,
        ...(priceGranularity === 'intraday' ? { granularity: 'intraday' } : {}),
        start_date: priceStartDate,
        end_date: priceEndDate,
      }),
    enabled: selected.length > 0 && priceStartDate <= priceEndDate,
    refetchInterval: priceIsLive ? 60_000 : false,
  })

  // Volumes intrajournaliers des entreprises sélectionnées sur une plage de
  // dates — même action que la comparaison de cours (api_quotes.php,
  // `compare` en granularity=intraday), mais on lit ici le champ `volume`
  // plutôt que `price`.
  const volumeIsLive = volumeEndDate === todayIso()
  const intradayVolumeQuery = useQuery({
    queryKey: ['compare-intraday-volume', selected, volumeStartDate, volumeEndDate],
    queryFn: () =>
      callApi<CompanyPriceSeries[]>('api_quotes.php', 'compare', {
        company_ids: selected,
        granularity: 'intraday',
        start_date: volumeStartDate,
        end_date: volumeEndDate,
      }),
    enabled: selected.length > 0 && volumeStartDate <= volumeEndDate,
    refetchInterval: volumeIsLive ? 60_000 : false,
  })

  // Taux de rotation du flottant (actions en circulation vs volume total
  // échangé) sur la période de comparaison de rapports déjà choisie plus
  // haut (réutilisée pour éviter un 3e couple de dates sur la page).
  const turnoverQuery = useQuery({
    queryKey: ['share-turnover', selected, startDate, endDate],
    queryFn: () =>
      callApi<ShareTurnover[]>('api_quotes.php', 'share_turnover', {
        company_ids: selected,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: selected.length > 0,
  })

  function toggle(id: number) {
    setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  const result = historyOverride ?? compareMutation.data
  const history = historyQuery.data ?? []
  const compareLeft = history.find((h) => h.id === compareLeftId) ?? null
  const compareRight = history.find((h) => h.id === compareRightId) ?? null
  const companies = companiesQuery.data ?? []
  const { sectors, unclassified } = useMemo(() => groupCompaniesBySector(companies), [companies])

  const priceSeries = priceQuery.data ?? []
  // Un seul jour affiché : uniquement l'heure (HH:MM) sur l'axe X ; sur
  // plusieurs jours, la date reste dans la clé pour ne pas fusionner des
  // heures identiques de jours différents (même principe pour les deux
  // graphes intrajournaliers de cette page).
  const priceSingleDay = priceGranularity === 'intraday' && priceStartDate === priceEndDate
  const priceChartData = useMemo(() => {
    const byKey = new Map<string, Record<string, number | string>>()
    for (const serie of priceSeries) {
      const basePrice = serie.data.length > 0 ? Number(serie.data[0].price) : 0
      for (const point of serie.data) {
        const key =
          priceGranularity !== 'intraday' ? point.date : priceSingleDay ? point.date.slice(11, 16) : point.date.slice(0, 16)
        const row = byKey.get(key) ?? { date: key }
        const price = Number(point.price)
        row[serie.symbol] = showPercent ? (basePrice ? (price / basePrice - 1) * 100 : 0) : price
        byKey.set(key, row)
      }
    }
    return Array.from(byKey.values()).sort((a, b) => String(a.date).localeCompare(String(b.date)))
  }, [priceSeries, showPercent, priceGranularity, priceSingleDay])

  const volumeSeries = intradayVolumeQuery.data ?? []
  const volumeSingleDay = volumeStartDate === volumeEndDate
  const volumeChartData = useMemo(() => {
    const byKey = new Map<string, Record<string, number | string>>()
    for (const serie of volumeSeries) {
      for (const point of serie.data) {
        const key = volumeSingleDay ? point.date.slice(11, 16) : point.date.slice(0, 16)
        const row = byKey.get(key) ?? { time: key }
        row[serie.symbol] = Number(point.volume)
        byKey.set(key, row)
      }
    }
    return Array.from(byKey.values()).sort((a, b) => String(a.time).localeCompare(String(b.time)))
  }, [volumeSeries, volumeSingleDay])

  function renderCompanyChip(c: Company) {
    const isSelected = selected.includes(c.company_id)
    return (
      <label
        key={c.company_id}
        title={readyCompanyIds.has(c.company_id) ? undefined : "Aucun rapport avec texte extrait — exclue de l'analyse IA (reste disponible pour cours/volumes/rotation)"}
        className={`cursor-pointer rounded-full border px-3 py-1 text-xs font-medium ${
          isSelected ? 'text-white' : 'border-gray-300 text-gray-600 dark:border-gray-700 dark:text-gray-300'
        }`}
        style={isSelected ? { backgroundColor: colorFor(c.company_id, selected), borderColor: colorFor(c.company_id, selected) } : undefined}
      >
        <input type="checkbox" className="hidden" checked={isSelected} onChange={() => toggle(c.company_id)} />
        {c.symbol}
        {!readyCompanyIds.has(c.company_id) && <span className="ml-1 opacity-70">·</span>}
      </label>
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Comparaison entre entreprises</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Rapports (IA), cours, volumes et rotation du flottant — tout au même endroit. Coche des entreprises
          ci-dessous, regroupées par secteur d'activité.
        </p>
      </div>

      <Card>
        <div className="flex flex-col gap-4">
          <div>
            <span className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Entreprises</span>
            <div className="max-h-64 overflow-y-auto rounded-md border border-gray-200 p-3 dark:border-gray-800">
              {sectors.map((sector) => (
                <div key={sector.name} className="mb-3 last:mb-0">
                  <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                    {sector.name}
                  </div>
                  <div className="flex flex-wrap gap-2">{sector.members.map(renderCompanyChip)}</div>
                </div>
              ))}
              {unclassified.length > 0 && (
                <div>
                  <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                    Secteur non renseigné
                  </div>
                  <div className="flex flex-wrap gap-2">{unclassified.map(renderCompanyChip)}</div>
                </div>
              )}
            </div>
            <p className="mt-1.5 text-xs text-gray-400 dark:text-gray-500">
              · = pas de rapport traité (exclue de l'analyse IA ci-dessous, disponible pour le reste)
            </p>
            {selected.length > 0 && (
              <button
                type="button"
                onClick={() => setSelected([])}
                className="mt-1.5 text-xs text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
              >
                Tout décocher
              </button>
            )}
            <div className="mt-2">
              <SelectedCompanyColors
                companies={companies}
                selectedIds={selected}
                colorFor={colorFor}
                setColor={setColor}
                overrides={colorOverrides}
              />
            </div>
          </div>
        </div>
      </Card>

      {selected.length === 0 && (
        <p className="text-sm text-gray-400 dark:text-gray-500">Sélectionne une ou plusieurs entreprises ci-dessus pour commencer.</p>
      )}

      <Tabs
        tabs={[
          { id: 'price', label: 'Cours' },
          { id: 'volume', label: 'Volumes' },
          { id: 'turnover', label: 'Rotation du flottant' },
          { id: 'reports', label: 'Rapports (IA)' },
        ]}
        active={activeTab}
        onChange={(id) => setActiveTab(id as 'price' | 'volume' | 'turnover' | 'reports')}
      />

      {activeTab === 'price' && (
        <>
          <InfoPanel>
            <p>
              <strong>À quoi sert cet onglet.</strong> Superposer les cours de plusieurs entreprises sur un même
              graphe pour comparer leurs trajectoires — repérer laquelle a le mieux performé, ou si elles évoluent
              en phase ou non.
            </p>
            <p>
              <strong>Mode « Variation en % » (recommandé, activé par défaut).</strong> Chaque courbe est recalée à
              0% au premier jour de la période affichée : ça permet de comparer des entreprises dont le cours brut
              est à des échelles totalement différentes (ex : 70 FCFA vs 38 900 FCFA) sur un même graphe lisible.
              Décoche la case pour voir les cours bruts en FCFA à la place — utile pour connaître le niveau de prix
              réel, mais rend la comparaison de trajectoires plus difficile entre titres d'échelles différentes.
            </p>
            <p>
              <strong>Granularité.</strong> « Clôtures quotidiennes » trace un point par jour de bourse ; «
              Intrajournalier » trace un point par relevé (~toutes les 10 min en séance) pour une même journée ou une
              plage courte — utile pour observer un mouvement en cours de séance plutôt que sa clôture.
            </p>
          </InfoPanel>

      {selected.length > 0 && (
        <Card title="Cours comparés">
          <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
            En % (recommandé), chaque courbe part de 0 au début de la période : ça permet de comparer des entreprises
            dont le cours brut est à des échelles très différentes (ex: 70 FCFA vs 38 900 FCFA) sur un même graphe.
            Décoche « Variation en % » pour voir les cours bruts en FCFA à la place.
          </p>
          <div className="mb-3 flex flex-wrap items-end gap-4">
            <label className="w-40">
              <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Granularité</span>
              <Select value={priceGranularity} onChange={(e) => setPriceGranularity(e.target.value as 'daily' | 'intraday')}>
                <option value="daily">Clôtures quotidiennes</option>
                <option value="intraday">Intrajournalier</option>
              </Select>
            </label>
            <label className="w-40">
              <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Début</span>
              <Input type="date" value={priceStartDate} onChange={(e) => setPriceStartDate(e.target.value)} />
            </label>
            <label className="w-40">
              <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Fin</span>
              <Input type="date" value={priceEndDate} onChange={(e) => setPriceEndDate(e.target.value)} />
            </label>
            {priceStartDate > priceEndDate && (
              <p className="pb-2 text-sm text-red-600 dark:text-red-400">La date de début doit précéder la date de fin.</p>
            )}
            <label className="flex items-center gap-2 pb-2 text-sm text-gray-700 dark:text-gray-300">
              <input type="checkbox" checked={showPercent} onChange={(e) => setShowPercent(e.target.checked)} />
              Variation en % (recommandé)
            </label>
          </div>

          {priceQuery.isLoading && <LoadingState label="Chargement des cotations…" />}
          {priceQuery.error && <ErrorState message={(priceQuery.error as Error).message} />}
          {priceQuery.data && priceChartData.length === 0 && (
            <p className="text-sm text-gray-500 dark:text-gray-400">Aucune cotation trouvée pour la sélection sur cette période.</p>
          )}
          {priceChartData.length > 0 && (
            <ResponsiveContainer width="100%" height={320}>
              <LineChart data={priceChartData}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                <YAxis
                  domain={['auto', 'auto']}
                  tick={{ fontSize: 11 }}
                  width={60}
                  tickFormatter={(v: number) => (showPercent ? `${v.toFixed(1)}%` : String(v))}
                />
                {showPercent && <ReferenceLine y={0} stroke="var(--chart-muted)" strokeDasharray="3 3" />}
                <Tooltip
                  labelFormatter={(label) => (priceGranularity === 'intraday' ? `Heure : ${label}` : `Date : ${label}`)}
                  formatter={(value, name) =>
                    showPercent ? [`${Number(value) > 0 ? '+' : ''}${Number(value).toFixed(2)}%`, name] : [value, name]
                  }
                />
                <Legend />
                {priceSeries.map((serie) => (
                  <Line
                    key={serie.company_id}
                    type="monotone"
                    dataKey={serie.symbol}
                    stroke={colorFor(serie.company_id, selected)}
                    dot={false}
                    strokeWidth={2}
                    connectNulls
                  />
                ))}
              </LineChart>
            </ResponsiveContainer>
          )}

          {priceChartData.length > 0 && (
            <ChartAiAnalysis
              chartType="quotes_comparison"
              parameters={{
                company_ids: [...selected].sort((a, b) => a - b),
                granularity: priceGranularity,
                start_date: priceStartDate,
                end_date: priceEndDate,
                display_mode: showPercent ? 'percent' : 'raw',
              }}
              data={priceSeries}
            />
          )}
        </Card>
      )}
        </>
      )}

      {activeTab === 'volume' && (
        <>
          <InfoPanel>
            <p>
              <strong>À quoi sert cet onglet.</strong> Comparer l'activité de négociation intrajournalière de
              plusieurs entreprises sur une même échelle de temps — repérer à quel moment de la séance chacune
              s'échange le plus, et laquelle est la plus active.
            </p>
            <p>
              <strong>Comment lire le graphe.</strong> Un point par relevé intrajournalier (~toutes les 10 min
              pendant la séance) et par entreprise, montrant le volume échangé à ce moment précis — pas un cumul sur
              la journée. Sur plusieurs jours, chaque journée de marché apparaît côte à côte sur l'axe du temps.
            </p>
            <p>
              <strong>À retenir.</strong> Un pic isolé peut correspondre à une transaction ponctuelle sur un titre
              peu liquide (qui déplace fortement la mesure sans refléter une tendance) plutôt qu'à un vrai regain
              d'intérêt du marché — croise avec l'onglet « Rotation du flottant » ou l'écran Classements pour une
              vue sur une période plus large.
            </p>
          </InfoPanel>

      {selected.length > 0 && (
        <Card title="Volumes intrajournaliers">
          <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
            Volume échangé relevé toutes les ~10 min pendant la séance — utile pour repérer les moments de la journée
            où un titre s'échange le plus (ex: ouverture, annonce). Sur plusieurs jours, chaque journée de marché
            apparaît côte à côte sur l'axe du temps.
          </p>
          <div className="mb-3 flex flex-wrap items-end gap-4">
            <label className="w-40">
              <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Début</span>
              <Input type="date" value={volumeStartDate} onChange={(e) => setVolumeStartDate(e.target.value)} />
            </label>
            <label className="w-40">
              <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Fin</span>
              <Input type="date" value={volumeEndDate} onChange={(e) => setVolumeEndDate(e.target.value)} />
            </label>
            {volumeStartDate > volumeEndDate && (
              <p className="pb-2 text-sm text-red-600 dark:text-red-400">La date de début doit précéder la date de fin.</p>
            )}
          </div>
          {intradayVolumeQuery.isLoading && <LoadingState />}
          {intradayVolumeQuery.error && <ErrorState message={(intradayVolumeQuery.error as Error).message} />}
          {intradayVolumeQuery.data && volumeChartData.length === 0 && (
            <p className="text-sm text-gray-500 dark:text-gray-400">Aucun relevé intrajournalier sur cette période.</p>
          )}
          {volumeChartData.length > 0 && (
            <ResponsiveContainer width="100%" height={280}>
              <LineChart data={volumeChartData}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                <XAxis dataKey="time" tick={{ fontSize: 11 }} minTickGap={30} />
                <YAxis tick={{ fontSize: 11 }} width={70} />
                <Tooltip />
                <Legend />
                {volumeSeries.map((serie) => (
                  <Line
                    key={serie.company_id}
                    type="monotone"
                    dataKey={serie.symbol}
                    stroke={colorFor(serie.company_id, selected)}
                    dot={false}
                    strokeWidth={2}
                  />
                ))}
              </LineChart>
            </ResponsiveContainer>
          )}

          {volumeChartData.length > 0 && (
            <ChartAiAnalysis
              chartType="intraday_volume"
              parameters={{ company_ids: [...selected].sort((a, b) => a - b), start_date: volumeStartDate, end_date: volumeEndDate }}
              data={volumeSeries}
            />
          )}
        </Card>
      )}
        </>
      )}

      {activeTab === 'turnover' && (
        <>
          <InfoPanel>
            <p>
              <strong>À quoi sert cet onglet.</strong> Situer le volume échangé de chaque entreprise par rapport à la
              taille de son capital — un volume brut de 10 000 actions n'a pas le même sens pour une entreprise qui
              en a émis 1 million que pour une qui en a émis 500 millions. Cet onglet remet le volume à l'échelle.
            </p>
            <p>
              <strong>« Actions en circulation » — attention au terme.</strong> C'est le total des actions déjà
              émises par l'entreprise, détenues par quelqu'un (fondateurs, État, public…) depuis son introduction en
              bourse. Ce n'est <strong>pas</strong> un stock « en attente de vente » — il n'existe pas de carnet
              d'ordres public à la BRVM, donc pas de notion réelle de volume « à vendre ».
            </p>
            <p>
              <strong>Les 4 graphes, dans l'ordre.</strong> (1) Actions en circulation vs volume échangé, sur deux
              axes séparés pour donner l'ordre de grandeur de chaque entreprise. (2) Taux de rotation (volume ÷
              actions en circulation, en %) — la mesure directement comparable entre entreprises de tailles
              différentes ; un taux proche de 0% signifie un flottant peu actif, pas une entreprise en difficulté.
              (3) Actions non retradées (estimation basse) — l'inverse du taux de rotation en nombre d'actions ;
              « estimation basse » car un même titre peut être retradé plusieurs fois dans la période, donc le vrai
              nombre d'actions jamais touchées peut être plus élevé. (4) Flottant réel (donnée BRVM) — part de la
              capitalisation réellement disponible au marché, hors participations stratégiques verrouillées ; la
              lecture la plus proche d'« actions réellement disponibles à l'achat ».
            </p>
          </InfoPanel>

      {selected.length > 0 && (
        <Card title="Rotation du flottant">
          <p className="mb-1 text-sm text-gray-600 dark:text-gray-400">
            Sur la période {startDate} → {endDate} (dates de la comparaison de rapports ci-dessous).
          </p>
          <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
            « Actions en circulation » = le total des actions émises par l'entreprise, déjà détenues par quelqu'un
            (fondateurs, État, public…) depuis son introduction en bourse — pas un stock en attente de vente. Trois
            lectures différentes ci-dessous : le taux de rotation (part retradée sur la période), une estimation des
            actions non retradées, et le flottant réellement disponible au marché (donnée BRVM, hors participations
            stratégiques bloquées).
          </p>
          {turnoverQuery.isLoading && <LoadingState />}
          {turnoverQuery.error && <ErrorState message={(turnoverQuery.error as Error).message} />}
          {turnoverQuery.data && (
            <div className="flex flex-col gap-6">
              <div>
                <div className="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">
                  Actions en circulation (total émis) vs volume échangé sur la période
                </div>
                <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
                  Deux axes séparés (gauche/droite) volontairement : les actions en circulation se comptent en
                  dizaines de millions, le volume échangé en milliers — sur un seul axe, la barre volume serait
                  invisible. Ce graphe donne l'ordre de grandeur de chaque entreprise ; pour comparer directement
                  entre entreprises, voir le taux de rotation ci-dessous.
                </p>
                <ResponsiveContainer width="100%" height={260}>
                  <BarChart data={turnoverQuery.data}>
                    <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                    <XAxis dataKey="symbol" tick={{ fontSize: 11 }} />
                    <YAxis yAxisId="left" tick={{ fontSize: 11 }} width={90} />
                    <YAxis yAxisId="right" orientation="right" tick={{ fontSize: 11 }} width={90} />
                    <Tooltip />
                    <Legend />
                    <Bar yAxisId="left" dataKey="shares_outstanding" name="Actions en circulation (total émis)" fill="var(--chart-muted)" />
                    <Bar yAxisId="right" dataKey="total_volume_traded" name="Volume échangé (période)" fill="var(--chart-1)" />
                  </BarChart>
                </ResponsiveContainer>
              </div>

              <div>
                <div className="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">
                  Taux de rotation — part du capital ayant changé de main sur la période
                </div>
                <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
                  = volume échangé ÷ actions en circulation. La métrique directement comparable entre entreprises,
                  quelle que soit leur taille. Un taux proche de 0% ne veut pas dire que l'entreprise va mal — juste
                  que ses actions changent peu de main (flottant réduit, peu d'investisseurs actifs sur ce titre).
                </p>
                <ResponsiveContainer width="100%" height={200}>
                  <BarChart data={turnoverQuery.data}>
                    <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                    <XAxis dataKey="symbol" tick={{ fontSize: 11 }} />
                    <YAxis tick={{ fontSize: 11 }} width={60} unit="%" />
                    <Tooltip />
                    <Bar dataKey="turnover_percent" name="Taux de rotation (%)" fill="var(--chart-positive)" />
                  </BarChart>
                </ResponsiveContainer>
              </div>

              <div>
                <div className="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">
                  Actions non retradées sur la période (estimation basse)
                </div>
                <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
                  = actions en circulation − volume échangé. Le nombre d'actions restées chez leur détenteur actuel
                  sans trouver d'acheteur sur la période — l'inverse du taux de rotation, en nombre d'actions plutôt
                  qu'en %. Estimation basse seulement : les mêmes titres peuvent être retradés plusieurs fois dans la
                  période, donc le vrai nombre d'actions jamais touchées peut être plus élevé que cette barre.
                </p>
                <ResponsiveContainer width="100%" height={200}>
                  <BarChart data={turnoverQuery.data}>
                    <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                    <XAxis dataKey="symbol" tick={{ fontSize: 11 }} />
                    <YAxis tick={{ fontSize: 11 }} width={90} />
                    <Tooltip />
                    <Bar dataKey="shares_untouched_estimate" name="Actions non retradées (estimation basse)" fill="var(--chart-5)" />
                  </BarChart>
                </ResponsiveContainer>
                {turnoverQuery.data.some((t) => t.fully_rotated) && (
                  <p className="mt-1 text-xs italic text-amber-600 dark:text-amber-400">
                    Pour {turnoverQuery.data.filter((t) => t.fully_rotated).map((t) => t.symbol).join(', ')}, le
                    volume échangé dépasse le nombre d'actions en circulation sur cette période — le capital a été
                    retradé au moins une fois en moyenne, la barre est ramenée à 0 (l'estimation ne descend pas en
                    dessous, mais ne veut pas dire "aucune action non retradée").
                  </p>
                )}
              </div>

              <div>
                <div className="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">
                  Flottant réel — part de la capitalisation réellement disponible au marché (donnée BRVM)
                </div>
                <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
                  = capitalisation flottante ÷ capitalisation globale, publiées par BRVM. Une valeur faible (voire
                  0%) signifie que l'essentiel du capital est verrouillé chez un actionnaire de contrôle (État,
                  groupe fondateur…) et n'est jamais mis en vente sur le marché — indépendamment du nombre total
                  d'actions émises affiché plus haut. La lecture la plus proche de « actions réellement disponibles
                  à l'achat ».
                </p>
                {turnoverQuery.data.every((t) => t.floating_percent === null) ? (
                  <p className="text-sm text-gray-500 dark:text-gray-400">
                    Donnée manquante pour la sélection — relance scripts/populate_market_cap.php.
                  </p>
                ) : (
                  <ResponsiveContainer width="100%" height={200}>
                    <BarChart data={turnoverQuery.data}>
                      <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                      <XAxis dataKey="symbol" tick={{ fontSize: 11 }} />
                      <YAxis tick={{ fontSize: 11 }} width={60} unit="%" domain={[0, 100]} />
                      <Tooltip />
                      <Bar dataKey="floating_percent" name="Flottant réel (%)" fill="var(--chart-2)" />
                    </BarChart>
                  </ResponsiveContainer>
                )}
              </div>

              {turnoverQuery.data.some((t) => t.shares_outstanding === null) && (
                <p className="text-xs italic text-amber-600 dark:text-amber-400">
                  Nombre d'actions en circulation manquant pour certaines entreprises (scripts/populate_market_cap.php
                  n'a pas encore été exécuté pour elles) — métriques non calculables dans ce cas.
                </p>
              )}

              <ChartAiAnalysis
                chartType="share_turnover"
                parameters={{ company_ids: [...selected].sort((a, b) => a - b), start_date: startDate, end_date: endDate }}
                data={turnoverQuery.data}
              />
            </div>
          )}
        </Card>
      )}
        </>
      )}

      {activeTab === 'reports' && (
        <>
      <InfoPanel>
        <p>
          <strong>À quoi sert cet onglet.</strong> Générer une analyse comparative rédigée par IA à partir des
          rapports financiers déjà publiés par les entreprises sélectionnées (annuels, semestriels, trimestriels…) —
          utile pour une synthèse en langage clair plutôt que de lire chaque rapport un par un.
        </p>
        <p>
          <strong>Pré-requis.</strong> Seules les entreprises ayant au moins un rapport avec texte déjà extrait sont
          incluses dans l'analyse IA (repérables au « · » à côté de leur nom dans la liste de sélection en haut de
          page) — les autres restent utilisables pour les onglets Cours/Volumes/Rotation, mais sont exclues d'ici.
        </p>
        <p>
          <strong>Contenu du résultat.</strong> Résumé comparatif, classement entre entreprises si pertinent,
          tendance financière par entreprise (chiffre d'affaires, résultat net), corrélation entre cours de bourse
          et fondamentaux, évolution des risques identifiés, et points d'appui à la décision (arguments haussiers et
          baissiers) — le tout généré à partir du texte des rapports, <strong>pas un conseil en investissement</strong>{' '}
          (voir la mention en bas de résultat).
        </p>
        <p>
          <strong>Historique et comparaison A/B.</strong> Chaque analyse déjà générée reste consultable dans
          l'historique ci-dessous (filtré par sélection d'entreprises + période + type de rapport). Avec 2 analyses
          ou plus dans l'historique, la case « Comparer 2 analyses côte à côte » permet de visualiser deux résultats
          en parallèle (ex. avant/après un nouveau rapport publié).
        </p>
      </InfoPanel>

      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <label className="w-40">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Début</span>
            <Input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
          </label>
          <label className="w-40">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Fin</span>
            <Input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
          </label>
          <label className="w-44">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Type de rapport</span>
            <Select value={reportType} onChange={(e) => setReportType(e.target.value)}>
              <option value="">Tous</option>
              <option value="annuel">Annuel</option>
              <option value="semestriel">Semestriel</option>
              <option value="trimestriel">Trimestriel</option>
              <option value="etats_financiers">États financiers</option>
              <option value="attestation_cac">Attestation CAC</option>
            </Select>
          </label>
          <label className="w-36">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Fournisseur</span>
            <Select value={provider} onChange={(e) => { setProvider(e.target.value as AiProvider); setModel('') }}>
              <option value="gemini">Gemini</option>
              <option value="anthropic">Anthropic</option>
              <option value="grok">Grok</option>
            </Select>
          </label>
          <label className="w-56">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Modèle (optionnel)</span>
            <Input list={modelListId} value={model} onChange={(e) => setModel(e.target.value)} placeholder="défaut du fournisseur" />
            <datalist id={modelListId}>
              {AI_MODELS[provider].map((m) => (
                <option key={m.value} value={m.value}>{m.label}</option>
              ))}
            </datalist>
          </label>

          <Button onClick={() => { setHistoryOverride(null); compareMutation.mutate(false) }} disabled={aiReadySelected.length === 0 || compareMutation.isPending}>
            {compareMutation.isPending ? 'Comparaison…' : 'Comparer'}
          </Button>
          <Button variant="secondary" onClick={() => { setHistoryOverride(null); compareMutation.mutate(true) }} disabled={aiReadySelected.length === 0 || compareMutation.isPending}>
            Forcer
          </Button>
        </div>

        {notReadySelected.length > 0 && (
          <p className="mt-3 text-xs text-amber-600 dark:text-amber-400">
            {notReadySelected.length} entreprise(s) sélectionnée(s) sans rapport traité seront exclues de l'analyse IA :{' '}
            {companies.filter((c) => notReadySelected.includes(c.company_id)).map((c) => c.symbol).join(', ')}
          </p>
        )}

        <AnalysisHistoryList
          items={history}
          selectedId={result?.id ?? null}
          onSelect={setHistoryOverride}
          onRate={(id, rating) => rateMutation.mutate({ id, rating })}
          onDelete={(id) => deleteMutation.mutate(id)}
          preview={(h) => h.analysis?.comparative_summary}
          title="Historique des comparaisons"
        />

        {history.length >= 2 && (
          <div className="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800">
            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
              <input
                type="checkbox"
                checked={compareMode}
                onChange={(e) => {
                  setCompareMode(e.target.checked)
                  if (e.target.checked && history.length >= 2) {
                    setCompareLeftId((prev) => prev ?? history[0].id)
                    setCompareRightId((prev) => prev ?? history[1].id)
                  }
                }}
              />
              Comparer 2 analyses de l'historique côte à côte
            </label>

            {compareMode && (
              <div className="mt-2 flex flex-wrap gap-4">
                <label className="min-w-[220px] flex-1">
                  <span className="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Analyse A</span>
                  <Select value={compareLeftId ?? ''} onChange={(e) => setCompareLeftId(e.target.value ? Number(e.target.value) : null)}>
                    {history.map((h) => (
                      <option key={h.id} value={h.id}>{h.provider}/{h.model}</option>
                    ))}
                  </Select>
                </label>
                <label className="min-w-[220px] flex-1">
                  <span className="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Analyse B</span>
                  <Select value={compareRightId ?? ''} onChange={(e) => setCompareRightId(e.target.value ? Number(e.target.value) : null)}>
                    {history.map((h) => (
                      <option key={h.id} value={h.id}>{h.provider}/{h.model}</option>
                    ))}
                  </Select>
                </label>
              </div>
            )}
          </div>
        )}
      </Card>

      {compareMutation.isPending && <LoadingState label="Analyse comparative en cours (peut prendre du temps si des rapports n’ont pas encore été analysés individuellement)…" />}
      {compareMutation.isError && <ErrorState message={(compareMutation.error as Error).message} />}

      {compareMode ? (
        <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
          <div>{compareLeft ? <ComparisonResultView result={compareLeft} onRate={(id, rating) => rateMutation.mutate({ id, rating })} onDelete={(id) => deleteMutation.mutate(id)} /> : <p className="text-sm text-gray-400">Choisis une analyse A.</p>}</div>
          <div>{compareRight ? <ComparisonResultView result={compareRight} onRate={(id, rating) => rateMutation.mutate({ id, rating })} onDelete={(id) => deleteMutation.mutate(id)} /> : <p className="text-sm text-gray-400">Choisis une analyse B.</p>}</div>
        </div>
      ) : (
        result && <ComparisonResultView result={result} onRate={(id, rating) => rateMutation.mutate({ id, rating })} onDelete={(id) => deleteMutation.mutate(id)} />
      )}
        </>
      )}
    </div>
  )
}
