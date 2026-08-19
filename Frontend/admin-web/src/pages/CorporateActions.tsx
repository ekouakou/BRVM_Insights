import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { Bar, BarChart, CartesianGrid, Cell, LabelList, Legend, Line, LineChart, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { callApi } from '../lib/apiClient'
import type {
  Company,
  CorporateActionsListResult,
  IndexHistoryPoint,
  MarketIndex,
  MarketPressureResult,
  OrderBookSnapshotsResult,
  PerformanceRankingRow,
  RelativeStrengthSeries,
  StockMetric,
  StockMetricsListResult,
} from '../lib/types'
import { Button, Card, ErrorState, InfoPanel, Input, LoadingState, SearchableSelect, Select, StatTile, Tabs } from '../components/ui'
import { DividendCharts } from '../components/DividendCharts'
import { CompanyDividends } from '../components/CompanyDividends'
import { CompanyAnnouncements } from '../components/CompanyAnnouncements'
import { ChartAiAnalysis } from '../components/ChartAiAnalysis'
import { DividendComparisonPanel } from '../components/DividendComparison'
import { LiquidityComparisonPanel } from '../components/LiquidityComparison'
import { colorForCompany, groupCompaniesBySector, useCompanyColors, usePersistedSelection } from '../lib/companyGroups'

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function daysAgoIso(days: number) {
  const d = new Date()
  d.setDate(d.getDate() - days)
  return d.toISOString().slice(0, 10)
}

function fmtNum(value: number | string | null, digits = 2): string {
  if (value === null) return '—'
  const num = typeof value === 'string' ? parseFloat(value) : value
  if (Number.isNaN(num)) return '—'
  return num.toLocaleString('fr-FR', { minimumFractionDigits: digits, maximumFractionDigits: digits })
}

const ACTION_TYPES = [
  { value: 'dividende', label: 'Dividende' },
  { value: 'augmentation_capital', label: 'Augmentation de capital' },
  { value: 'admission', label: 'Admission' },
  { value: 'assemblee_generale', label: 'Assemblée générale' },
  { value: 'autre', label: 'Autre' },
] as const

function actionTypeLabel(type: string): string {
  return ACTION_TYPES.find((t) => t.value === type)?.label ?? type
}

function actionTypeBadgeClass(type: string): string {
  switch (type) {
    case 'dividende':
      return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
    case 'augmentation_capital':
      return 'bg-gray-200 text-gray-800 dark:bg-gray-800 dark:text-gray-200'
    case 'admission':
      return 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
    case 'assemblee_generale':
      return 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300'
    default:
      return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'
  }
}

function fmtAmount(amount: string | number | null, currency: string): string {
  if (amount === null) return '—'
  const num = typeof amount === 'string' ? parseFloat(amount) : amount
  if (Number.isNaN(num)) return '—'
  return `${num.toLocaleString('fr-FR', { maximumFractionDigits: 2 })} ${currency}`
}

type HistoryPoint = {
  date: string
  close_price: number | null
  per: number | null
  yield_net_percent: number | null
  bulletin_title: string | null
}

/** Infobulle partagée par les 3 graphes d'historique : mêmes trois valeurs à chaque point, seule la mise en avant change. */
function HistoryTooltip({ active, payload }: { active?: boolean; payload?: { payload: HistoryPoint }[] }) {
  if (!active || !payload?.length) return null
  const p = payload[0].payload
  return (
    <div className="rounded-md border border-gray-200 bg-white px-3 py-2 text-xs shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
      <div className="mb-1 font-medium">{p.date}</div>
      <div className="grid grid-cols-2 gap-x-3 tabular-nums">
        <span className="text-gray-500 dark:text-gray-400">Cours de clôture</span>
        <span className="text-right">{fmtNum(p.close_price)}</span>
        <span className="text-gray-500 dark:text-gray-400">PER (BRVM)</span>
        <span className="text-right">{fmtNum(p.per)}</span>
        <span className="text-gray-500 dark:text-gray-400">Rendement net</span>
        <span className="text-right">{p.yield_net_percent !== null ? `${fmtNum(p.yield_net_percent)} %` : '—'}</span>
      </div>
      {p.bulletin_title && <div className="mt-1 text-gray-500 dark:text-gray-400">{p.bulletin_title}</div>}
    </div>
  )
}

export function CorporateActions() {
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()
  // Lien direct depuis le tableau de bord entreprise (bandeau « Rendement »)
  // : /corporate-actions?tab=dividendes&symbol=ORAC ouvre directement
  // l'onglet Dividendes avec cette entreprise déjà sélectionnée.
  const initialTabParam = searchParams.get('tab')
  const initialSymbol = searchParams.get('symbol')
  // Entreprise sélectionnée : globale à toute la page (les 3 onglets la
  // partagent) — un seul sélecteur affiché sous les onglets plutôt qu'un
  // par onglet, pour ne pas avoir à re-choisir la même entreprise trois fois.
  const [selectedCompanyId, setSelectedCompanyId] = useState('')
  const [actionType, setActionType] = useState('')
  const [startDate, setStartDate] = useState('')
  const [endDate, setEndDate] = useState('')
  const [extractingId, setExtractingId] = useState<number | null>(null)
  const [metricsExtractingId, setMetricsExtractingId] = useState<number | null>(null)
  const [metricsAsOfDate, setMetricsAsOfDate] = useState('')
  const [metricsBulletinId, setMetricsBulletinId] = useState('')
  // Performance du cours (rendement net = plus/moins-value) entre deux dates
  // choisies, toutes entreprises — distinct du « Rdt. Net » officiel BRVM
  // (colonne du bulletin) tracé plus bas : ici c'est un calcul sur le
  // mouvement du cours, comparable à l'onglet « Par performance » de
  // VolumeRanking.tsx (même endpoint), mais intégré à cet écran pour ne pas
  // avoir à changer de page.
  const [perfStartDate, setPerfStartDate] = useState(daysAgoIso(90))
  const [perfEndDate, setPerfEndDate] = useState(todayIso())
  const [indexStartDate, setIndexStartDate] = useState(daysAgoIso(90))
  const [indexEndDate, setIndexEndDate] = useState(todayIso())
  const [selectedIndexCodes, setSelectedIndexCodes] = useState<string[]>([])
  const [pressureStartDate, setPressureStartDate] = useState(daysAgoIso(90))
  const [pressureEndDate, setPressureEndDate] = useState(todayIso())
  // Onglet « Comparaison entreprises » : sélection MULTIPLE (contrairement
  // au sélecteur unique partagé par les autres onglets ci-dessus), même
  // pattern que Comparison.tsx/Statistics.tsx (chips groupées par secteur,
  // sélection persistée, couleur stable par entreprise).
  const [comparisonSelected, setComparisonSelected] = usePersistedSelection('brvm_corporate_actions_comparison_selection')
  const { colorFor: comparisonColorFor } = useCompanyColors()
  const [comparisonStartDate, setComparisonStartDate] = useState(daysAgoIso(90))
  const [comparisonEndDate, setComparisonEndDate] = useState(todayIso())
  const [comparisonSubTab, setComparisonSubTab] = useState<'liste' | 'dividendes' | 'per' | 'indices' | 'pression'>('liste')
  const [activeTab, setActiveTab] = useState<'liste' | 'dividendes' | 'per' | 'indices' | 'annonces' | 'pression' | 'comparaison'>(
    () =>
      initialTabParam === 'dividendes' || initialTabParam === 'per' || initialTabParam === 'indices' ||
      initialTabParam === 'annonces' || initialTabParam === 'pression' || initialTabParam === 'comparaison'
        ? initialTabParam
        : 'liste',
  )

  const companiesQuery = useQuery({
    queryKey: ['companies-list'],
    queryFn: () => callApi<Company[]>('api_companies.php', 'list', { per_page: 200, active: 1 }),
  })

  const companyOptions = useMemo(() => {
    return [...(companiesQuery.data ?? [])].sort((a, b) => a.name.localeCompare(b.name))
  }, [companiesQuery.data])

  // Groupement par secteur pour les chips de sélection multiple de l'onglet
  // Comparaison — même utilitaire que Comparison.tsx/Statistics.tsx.
  const { sectors: comparisonSectors, unclassified: comparisonUnclassified } = useMemo(
    () => groupCompaniesBySector(companyOptions),
    [companyOptions],
  )

  function toggleComparisonCompany(id: number) {
    setComparisonSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  // Applique le symbole passé dans l'URL une seule fois, dès que la liste
  // des entreprises est chargée — ne doit jamais écraser un choix fait
  // ensuite dans le sélecteur (même garde que CompanyDashboard.tsx).
  const appliedInitialSymbol = useRef(false)
  useEffect(() => {
    if (appliedInitialSymbol.current || !initialSymbol || companyOptions.length === 0) return
    const match = companyOptions.find((c) => c.symbol === initialSymbol)
    if (match) setSelectedCompanyId(String(match.company_id))
    appliedInitialSymbol.current = true
  }, [companyOptions, initialSymbol])

  const indicesQuery = useQuery({
    queryKey: ['market-indices-list'],
    queryFn: () => callApi<MarketIndex[]>('api_market.php', 'indices', { sub_action: 'list' }),
    enabled: activeTab === 'indices',
  })
  const marketIndices = useMemo(
    () => [...(indicesQuery.data ?? [])].sort((a, b) => a.code.localeCompare(b.code)),
    [indicesQuery.data],
  )
  // BRVM-COMPOSITE sélectionné par défaut dès que la liste des indices est connue.
  useEffect(() => {
    if (marketIndices.length > 0 && selectedIndexCodes.length === 0) {
      const composite = marketIndices.find((idx) => idx.code === 'BRVM-COMPOSITE')
      setSelectedIndexCodes([composite ? composite.code : marketIndices[0].code])
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [marketIndices])

  function toggleIndexCode(code: string) {
    setSelectedIndexCodes((prev) => (prev.includes(code) ? prev.filter((c) => c !== code) : [...prev, code]))
  }

  const indexPeriodEnabled = activeTab === 'indices' && !!indexStartDate && !!indexEndDate && indexStartDate <= indexEndDate

  const indexHistoryQueries = useQueries({
    queries: selectedIndexCodes.map((code) => ({
      queryKey: ['market-index-history', code, indexStartDate, indexEndDate],
      queryFn: () =>
        callApi<IndexHistoryPoint[]>('api_market.php', 'indices', {
          sub_action: 'history',
          code,
          start_date: indexStartDate,
          end_date: indexEndDate,
        }),
      enabled: indexPeriodEnabled,
    })),
  })

  const indexSeries = useMemo(
    () =>
      selectedIndexCodes.map((code, i) => ({
        code,
        name: marketIndices.find((idx) => idx.code === code)?.name ?? code,
        data: indexHistoryQueries[i]?.data ?? [],
      })),
    [selectedIndexCodes, marketIndices, indexHistoryQueries],
  )

  const indexChartData = useMemo(() => {
    const byDate = new Map<string, Record<string, number | string>>()
    for (const serie of indexSeries) {
      const sorted = [...serie.data].sort((a, b) => a.date.localeCompare(b.date))
      const firstClose = sorted.find((p) => p.close !== null)?.close ?? null
      const baseline = firstClose !== null ? Number(firstClose) : null
      for (const point of sorted) {
        if (point.close === null || !baseline) continue
        const row = byDate.get(point.date) ?? { date: point.date }
        row[serie.code] = ((Number(point.close) - baseline) / baseline) * 100
        byDate.set(point.date, row)
      }
    }
    return Array.from(byDate.values()).sort((a, b) => String(a.date).localeCompare(String(b.date)))
  }, [indexSeries])

  // Force relative de l'entreprise sélectionnée (globale à la page) vs
  // BRVM Composite, même endpoint/calcul que l'onglet « Performance & risque »
  // du tableau de bord entreprise — reproduit ici pour éviter d'avoir à
  // changer de page pour croiser une entreprise avec l'indice.
  const relativeStrengthQuery = useQuery({
    queryKey: ['corporate-actions-relative-strength', selectedCompanyId, indexStartDate, indexEndDate],
    queryFn: () =>
      callApi<RelativeStrengthSeries[]>('api_quotes.php', 'relative_strength', {
        company_ids: [Number(selectedCompanyId)],
        start_date: indexStartDate,
        end_date: indexEndDate,
      }),
    enabled: activeTab === 'indices' && !!selectedCompanyId && indexPeriodEnabled,
  })
  const relativeStrengthData = relativeStrengthQuery.data?.[0]?.data ?? []

  const pressurePeriodEnabled = pressureStartDate !== '' && pressureEndDate !== '' && pressureStartDate <= pressureEndDate

  const marketPressureQuery = useQuery({
    queryKey: ['market-pressure', pressureStartDate, pressureEndDate],
    queryFn: () =>
      callApi<MarketPressureResult>('api_order_book.php', 'market_pressure', {
        start_date: pressureStartDate,
        end_date: pressureEndDate,
      }),
    enabled: activeTab === 'pression' && pressurePeriodEnabled,
  })

  // Extraction déterministe (regex, pas d'IA) du carnet d'ordres — un seul
  // appel traite TOUS les bulletins en attente d'un coup (voir
  // BulletinOrderBookService::extractAll()), contrairement aux extractions
  // IA des autres onglets qui doivent y aller bulletin par bulletin.
  const parseOrderBooksMutation = useMutation({
    mutationFn: () => callApi('api_order_book.php', 'parse_bulletins', {}),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['market-pressure'] })
    },
  })

  // Carnet d'ordres résiduel d'UNE entreprise (l'entreprise sélectionnée en
  // haut de page) sur la période choisie — répond à « ses actionnaires
  // sont-ils plutôt acheteurs ou vendeurs ? », par opposition au marché
  // entier. Même formule que marketPressure() côté backend (net = achat -
  // vente, imbalance_ratio = achat / (achat+vente)), reprise ici côté
  // client car `snapshots` ne calcule ni net_qty ni dominant par ligne
  // (contrairement à market_pressure qui agrège déjà tout le marché).
  const companyOrderBookQuery = useQuery({
    queryKey: ['company-order-book-snapshots', selectedCompanyId, pressureStartDate, pressureEndDate],
    queryFn: () =>
      callApi<OrderBookSnapshotsResult>('api_order_book.php', 'snapshots', {
        company_id: selectedCompanyId,
        start_date: pressureStartDate,
        end_date: pressureEndDate,
      }),
    enabled: activeTab === 'pression' && !!selectedCompanyId && pressurePeriodEnabled,
  })

  const pressureChartData = useMemo(() => {
    const rows = companyOrderBookQuery.data?.snapshots ?? []
    return [...rows]
      .sort((a, b) => a.snapshot_date.localeCompare(b.snapshot_date))
      .filter((r) => r.bid_residual_qty !== null || r.ask_residual_qty !== null)
      .map((r) => {
        const bid = r.bid_residual_qty ?? 0
        const ask = r.ask_residual_qty ?? 0
        const net_qty = bid - ask
        const dominant: 'acheteur' | 'vendeur' | 'equilibre' = bid > ask ? 'acheteur' : ask > bid ? 'vendeur' : 'equilibre'
        return {
          date: r.snapshot_date,
          bid_qty: bid,
          ask_qty: ask,
          net_qty,
          imbalance_ratio: r.imbalance_ratio !== null ? Number(r.imbalance_ratio) : null,
          dominant,
        }
      })
  }, [companyOrderBookQuery.data])
  const buyerDaysCount = pressureChartData.filter((d) => d.dominant === 'acheteur').length
  const sellerDaysCount = pressureChartData.filter((d) => d.dominant === 'vendeur').length
  const lastPressureDay = pressureChartData[pressureChartData.length - 1] ?? null

  // --- Onglet « Comparaison entreprises » -----------------------------
  // Trois requêtes dédiées, indépendantes des filtres/sélecteurs des autres
  // onglets (qui portent sur UNE entreprise) : la comparaison porte sur
  // `comparisonSelected` (plusieurs). Dividendes et Agitation du marché
  // n'ont pas besoin de requête ici : DividendComparisonPanel et
  // LiquidityComparisonPanel s'alimentent eux-mêmes.
  const comparisonPeriodEnabled = comparisonStartDate !== '' && comparisonEndDate !== '' && comparisonStartDate <= comparisonEndDate
  const comparisonActive = activeTab === 'comparaison' && comparisonSelected.length > 0

  // Opérations sur titres : l'API ne filtre que sur UN company_id à la
  // fois — on récupère donc tout sur la période puis on filtre côté client
  // sur la sélection multiple (même contournement que le classement PER
  // ci-dessus, qui repose déjà sur cette API sans filtre entreprise).
  const comparisonActionsQuery = useQuery({
    queryKey: ['corporate-actions-comparison', comparisonStartDate, comparisonEndDate],
    queryFn: () =>
      callApi<CorporateActionsListResult>('api_bulletin_corporate_actions.php', 'list', {
        start_date: comparisonStartDate || undefined,
        end_date: comparisonEndDate || undefined,
      }),
    enabled: comparisonActive && comparisonSubTab === 'liste',
  })
  const comparisonActionRows = (comparisonActionsQuery.data?.actions ?? []).filter(
    (r) => r.company_id !== null && comparisonSelected.includes(r.company_id),
  )

  // PER & rendement net : même contournement — pas de filtre multi-entreprise
  // côté API, filtrage client sur bulletin_stock_metrics non filtré.
  const comparisonMetricsQuery = useQuery({
    queryKey: ['stock-metrics-comparison', comparisonStartDate, comparisonEndDate],
    queryFn: () =>
      callApi<StockMetricsListResult>('api_bulletin_stock_metrics.php', 'list', {
        start_date: comparisonStartDate || undefined,
        end_date: comparisonEndDate || undefined,
      }),
    enabled: comparisonActive && comparisonSubTab === 'per',
  })
  const comparisonYieldChartData = useMemo(() => {
    const rows = (comparisonMetricsQuery.data?.metrics ?? []).filter(
      (r) => r.company_id !== null && comparisonSelected.includes(r.company_id),
    )
    const byDate = new Map<string, Record<string, number | string>>()
    for (const r of rows) {
      if (r.yield_net_percent === null) continue
      const row = byDate.get(r.publish_date) ?? { date: r.publish_date }
      row[r.company_symbol ?? r.symbol_raw] = Number(r.yield_net_percent)
      byDate.set(r.publish_date, row)
    }
    return Array.from(byDate.values()).sort((a, b) => String(a.date).localeCompare(String(b.date)))
  }, [comparisonMetricsQuery.data, comparisonSelected])

  // Indice & performance : `relative_strength` accepte déjà un tableau de
  // company_ids — même endpoint que l'onglet Indices ci-dessus, juste avec
  // la sélection multiple au lieu d'un seul id (voir Statistics.tsx pour le
  // même usage multi-entreprises de cet endpoint).
  const comparisonRelativeStrengthQuery = useQuery({
    queryKey: ['corporate-actions-comparison-relative-strength', comparisonSelected, comparisonStartDate, comparisonEndDate],
    queryFn: () =>
      callApi<RelativeStrengthSeries[]>('api_quotes.php', 'relative_strength', {
        company_ids: comparisonSelected,
        start_date: comparisonStartDate,
        end_date: comparisonEndDate,
      }),
    enabled: comparisonActive && comparisonSubTab === 'indices' && comparisonPeriodEnabled,
  })
  const comparisonRelativeStrengthSeries = comparisonRelativeStrengthQuery.data ?? []
  const comparisonRelativeStrengthChartData = useMemo(() => {
    const byDate = new Map<string, Record<string, number | string>>()
    for (const serie of comparisonRelativeStrengthSeries) {
      for (const point of serie.data) {
        const row = byDate.get(point.date) ?? { date: point.date }
        if (point.relative_strength !== null) row[serie.symbol] = point.relative_strength
        if (point.index_variation_percent !== null) row['BRVM-COMPOSITE'] = point.index_variation_percent
        byDate.set(point.date, row)
      }
    }
    return Array.from(byDate.values()).sort((a, b) => String(a.date).localeCompare(String(b.date)))
  }, [comparisonRelativeStrengthSeries])
  // ----------------------------------------------------------------------

  const filters = {
    company_id: selectedCompanyId || undefined,
    action_type: actionType || undefined,
    start_date: startDate || undefined,
    end_date: endDate || undefined,
  }

  const listQuery = useQuery({
    queryKey: ['corporate-actions-list', filters],
    queryFn: () => callApi<CorporateActionsListResult>('api_bulletin_corporate_actions.php', 'list', filters),
  })

  const extractMutation = useMutation({
    mutationFn: (bulletinId: number) =>
      callApi('api_bulletin_corporate_actions.php', 'extract', { bulletin_id: bulletinId }),
    onMutate: (bulletinId) => setExtractingId(bulletinId),
    onSettled: () => {
      setExtractingId(null)
      queryClient.invalidateQueries({ queryKey: ['corporate-actions-list'] })
    },
  })

  // « Date de référence » : filtre les bulletins jusqu'à cette date incluse
  // (pas de start_date) — combiné au tri par publish_date décroissant côté
  // API, la première ligne devient naturellement le PER connu le plus
  // proche de cette date, sans jamais regarder après (même logique que le
  // cours retenu pour le PER calculé dans États financiers).
  const metricsFilters = {
    company_id: selectedCompanyId || undefined,
    bulletin_id: metricsBulletinId || undefined,
    end_date: metricsBulletinId ? undefined : metricsAsOfDate || undefined,
  }

  const metricsQuery = useQuery({
    queryKey: ['stock-metrics-list', metricsFilters],
    queryFn: () => callApi<StockMetricsListResult>('api_bulletin_stock_metrics.php', 'list', metricsFilters),
    enabled: activeTab === 'per',
  })

  const perfPeriodEnabled = activeTab === 'per' && !!perfStartDate && !!perfEndDate && perfStartDate <= perfEndDate

  const performanceRankingQuery = useQuery({
    queryKey: ['corporate-actions-performance-ranking', perfStartDate, perfEndDate],
    queryFn: () =>
      callApi<PerformanceRankingRow[]>('api_quotes.php', 'performance_ranking', {
        start_date: perfStartDate,
        end_date: perfEndDate,
      }),
    enabled: perfPeriodEnabled,
  })

  const perfRankingChartData = useMemo(
    () =>
      (performanceRankingQuery.data ?? [])
        .filter((r) => r.variation_percent !== null)
        .map((r) => ({ company_id: r.company_id, symbol: r.symbol, name: r.name, variation: r.variation_percent as number })),
    [performanceRankingQuery.data],
  )

  const metricsExtractMutation = useMutation({
    mutationFn: (bulletinId: number) =>
      callApi('api_bulletin_stock_metrics.php', 'extract', { bulletin_id: bulletinId }),
    onMutate: (bulletinId) => setMetricsExtractingId(bulletinId),
    onSettled: () => {
      setMetricsExtractingId(null)
      queryClient.invalidateQueries({ queryKey: ['stock-metrics-list'] })
    },
  })

  // Historique complet de l'entreprise choisie, TOUS bulletins confondus —
  // volontairement indépendant du bulletin/de la date de référence
  // sélectionnés ci-dessus (qui ne servent qu'à figer la fiche résumée sur
  // une séance précise) : le graphe d'évolution doit rester visible même
  // quand on choisit un bulletin ou une date passée pour la fiche.
  const companyHistoryQuery = useQuery({
    queryKey: ['stock-metrics-history', selectedCompanyId],
    queryFn: () => callApi<StockMetricsListResult>('api_bulletin_stock_metrics.php', 'list', { company_id: selectedCompanyId }),
    enabled: activeTab === 'per' && !!selectedCompanyId,
  })

  const historyChartData = useMemo(() => {
    const rows: StockMetric[] = companyHistoryQuery.data?.metrics ?? []
    return [...rows]
      .sort((a, b) => a.publish_date.localeCompare(b.publish_date))
      .map((r) => ({
        date: r.publish_date,
        close_price: r.close_price !== null ? Number(r.close_price) : null,
        per: r.per !== null ? Number(r.per) : null,
        yield_net_percent: r.yield_net_percent !== null ? Number(r.yield_net_percent) : null,
        bulletin_title: r.bulletin_title ?? null,
      }))
  }, [companyHistoryQuery.data])

  // Classement multi-entreprises du rendement net officiel BRVM — une seule
  // barre par entreprise (dernière valeur connue), construit à partir de
  // metricsRows qui contient déjà toutes les entreprises quand aucune n'est
  // sélectionnée en haut de page. metricsRows est trié par publish_date
  // décroissant (côté API), donc la première ligne rencontrée par entreprise
  // est la plus récente — même logique que latestMetric ci-dessous, répétée
  // par entreprise plutôt que pour une seule.
  const yieldRankingChartData = useMemo(() => {
    if (selectedCompanyId) return []
    const rows: StockMetric[] = metricsQuery.data?.metrics ?? []
    const seen = new Set<number | string>()
    const result: { key: string; symbol: string; name: string; value: number; publish_date: string; bulletin_title: string | null }[] = []
    for (const r of rows) {
      const key = r.company_id ?? r.symbol_raw
      if (seen.has(key)) continue
      seen.add(key)
      if (r.yield_net_percent === null) continue
      result.push({
        key: String(key),
        symbol: r.company_symbol ?? r.symbol_raw,
        name: r.company_name ?? r.company_name_raw ?? r.symbol_raw,
        value: Number(r.yield_net_percent),
        publish_date: r.publish_date,
        bulletin_title: r.bulletin_title ?? null,
      })
    }
    return result.sort((a, b) => b.value - a.value)
  }, [metricsQuery.data, selectedCompanyId])

  const data = listQuery.data
  const rows = data?.actions ?? []
  const metricsData = metricsQuery.data
  const metricsRows = metricsData?.metrics ?? []
  // Ligne la plus récente pour l'entreprise filtrée (et, si une date de
  // référence est choisie, la plus récente à cette date ou avant) :
  // metricsRows est trié par publish_date décroissant, donc la première
  // ligne est le PER "du jour de cotation" recherché.
  const latestMetric = selectedCompanyId && metricsRows.length > 0 ? metricsRows[0] : null
  const selectedCompany = companyOptions.find((c) => String(c.company_id) === selectedCompanyId) ?? null

  function resetFilters() {
    setActionType('')
    setStartDate('')
    setEndDate('')
  }
  const hasActiveFilters = actionType !== '' || startDate !== '' || endDate !== ''

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Opérations sur titres</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Calendrier des dividendes, augmentations de capital, admissions et assemblées générales, extraits par IA
          des Bulletins Officiels de la Cote déjà traités.
        </p>
      </div>

      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <label className="w-full sm:w-96">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Entreprise</span>
            <SearchableSelect
              value={selectedCompanyId}
              onChange={setSelectedCompanyId}
              placeholder="Toutes les entreprises"
              options={companyOptions.map((c) => ({ value: String(c.company_id), label: `${c.symbol} — ${c.name}` }))}
            />
          </label>
          {selectedCompanyId !== '' && (
            <button
              type="button"
              onClick={() => setSelectedCompanyId('')}
              className="text-xs text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
            >
              Réinitialiser
            </button>
          )}
        </div>
        <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
          Cette sélection s'applique aux trois onglets ci-dessous — liste des opérations, dividendes détaillés, et
          PER/rendement BRVM.
        </p>
      </Card>

      <Tabs
        tabs={[
          { id: 'liste', label: 'Toutes les opérations' },
          { id: 'dividendes', label: 'Dividendes (graphes)' },
          { id: 'per', label: 'PER & rendement (BRVM)' },
          { id: 'indices', label: 'Indice & performance' },
          { id: 'annonces', label: 'Annonces BRVM' },
          { id: 'pression', label: 'Agitation du marché' },
          { id: 'comparaison', label: 'Comparaison entreprises' },
        ]}
        active={activeTab}
        onChange={(id) => setActiveTab(id as 'liste' | 'dividendes' | 'per' | 'indices' | 'annonces' | 'pression' | 'comparaison')}
      />

      {activeTab === 'dividendes' && (
        <>
          <InfoPanel>
            <p>
              <strong>À quoi sert cet onglet.</strong> Un dividende, c'est la part du bénéfice qu'une entreprise
              reverse à ses actionnaires. La question utile pour l'investisseur n'est pas « combien ? » mais
              « combien par rapport au prix que je paie ? » — c'est le <strong>rendement</strong>. Choisis une
              entreprise dans le sélecteur en haut de page pour son historique détaillé (graphe + tableau des
              versements), ou laisse-le vide pour la vue d'ensemble du marché (classement, calendrier, saisonnalité).
            </p>
            <p>
              Deux mises en garde : le jour du détachement, le cours de l'action baisse mécaniquement d'environ le
              montant du dividende (ce n'est pas une chute du titre) ; et un rendement très élevé vient parfois
              d'un cours qui s'est effondré, pas d'une entreprise généreuse. Toutes ces données viennent des
              dividendes extraits par IA des Bulletins Officiels de la Cote déjà traités — la couverture dépend donc
              du nombre de bulletins déjà analysés.
            </p>
          </InfoPanel>

          {selectedCompany ? (
            <CompanyDividends companyId={selectedCompany.company_id} symbol={selectedCompany.symbol} />
          ) : (
            <DividendCharts />
          )}
        </>
      )}

      {activeTab === 'liste' && (
      <>

      <InfoPanel>
        <p>
          <strong>À quoi sert cet écran.</strong> Chaque Bulletin Officiel de la Cote (BOC) peut annoncer des
          opérations sur titres — paiement de dividendes, augmentation de capital, admission d'un nouveau titre,
          convocation ou tenue d'une assemblée générale. Ces annonces sont noyées dans un document PDF de plusieurs
          dizaines de pages, différent chaque jour. Cet écran les extrait automatiquement par IA et les rassemble
          dans un calendrier interrogeable, plutôt que d'obliger à rouvrir chaque bulletin un par un.
        </p>
        <p>
          <strong>Rattachement à une entreprise.</strong> Le nom mentionné dans le bulletin est automatiquement
          rapproché d'une entreprise de la base. « Rattachement exact » = correspondance de nom sans ambiguïté ;
          « approximatif » = correspondance probable mais pas garantie (nom mal orthographié dans le bulletin,
          abréviation...) — vérifie la colonne « Nom dans le bulletin » en cas de doute. Aucun rattachement
          n'apparaît si le nom n'a pu être rapproché d'aucune entreprise avec une confiance suffisante.
        </p>
        <p>
          <strong>Date d'événement absente.</strong> Certaines annonces (ex. « assemblée générale à une date
          ultérieure ») ne précisent pas de date exacte dans le bulletin — la ligne apparaît alors sans date plutôt
          que d'inventer une échéance.
        </p>
        <p>
          <strong>Complétude.</strong> Ce calendrier ne contient que ce qui a été identifié dans les bulletins déjà
          extraits. L'absence d'une opération ne signifie pas qu'elle n'existe pas — clique sur « Extraire » pour
          les bulletins encore en attente ci-dessous pour compléter la couverture.
        </p>
      </InfoPanel>

      {!!data?.pending_count && (
        <Card>
          <div className="mb-2 flex items-center justify-between">
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">
              {data.pending_count} bulletin(s) pas encore extrait(s)
            </h3>
          </div>
          <div className="flex flex-col gap-2">
            {data.pending_bulletins.map((b) => (
              <div key={b.id} className="flex items-center justify-between gap-3 rounded-md border border-gray-100 px-3 py-2 text-sm dark:border-gray-800">
                <span className="text-gray-600 dark:text-gray-300">
                  {b.publish_date} — {b.title}
                </span>
                <Button
                  variant="secondary"
                  disabled={extractMutation.isPending && extractingId === b.id}
                  onClick={() => extractMutation.mutate(b.id)}
                >
                  {extractMutation.isPending && extractingId === b.id ? 'Extraction…' : 'Extraire'}
                </Button>
              </div>
            ))}
          </div>
          {extractMutation.isError && (
            <p className="mt-2 text-xs text-red-600 dark:text-red-400">
              {(extractMutation.error as Error).message}
            </p>
          )}
        </Card>
      )}

      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <label className="w-52">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Type d'opération</span>
            <Select value={actionType} onChange={(e) => setActionType(e.target.value)}>
              <option value="">Tous</option>
              {ACTION_TYPES.map((t) => (
                <option key={t.value} value={t.value}>{t.label}</option>
              ))}
            </Select>
          </label>
          <label className="w-40">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de début</span>
            <Input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
          </label>
          <label className="w-40">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de fin</span>
            <Input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
          </label>
          {hasActiveFilters && (
            <button
              type="button"
              onClick={resetFilters}
              className="text-xs text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
            >
              Réinitialiser les filtres
            </button>
          )}
        </div>
      </Card>

      {listQuery.isLoading && <LoadingState label="Chargement du calendrier…" />}
      {listQuery.error && <ErrorState message={(listQuery.error as Error).message} />}

      {data && rows.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Aucune opération ne correspond à ces critères.
        </p>
      )}

      {rows.length > 0 && (
        <Card>
          <div className="mb-3 text-xs text-gray-500 dark:text-gray-400">{rows.length} opération(s)</div>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  <th className="pb-2 pr-3">Date</th>
                  <th className="pb-2 pr-3">Entreprise</th>
                  <th className="pb-2 pr-3">Nom dans le bulletin</th>
                  <th className="pb-2 pr-3">Type</th>
                  <th className="pb-2 pr-3 text-right">Montant</th>
                  <th className="pb-2 pr-3">Description</th>
                  <th className="pb-2 pr-3">Bulletin source</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id} className="border-t border-gray-100 align-top dark:border-gray-800">
                    <td className="py-2 pr-3 whitespace-nowrap tabular-nums">{r.event_date ?? '—'}</td>
                    <td className="py-2 pr-3">
                      {r.company_symbol ? (
                        <span className="font-medium">{r.company_symbol}</span>
                      ) : (
                        <span className="text-gray-400 dark:text-gray-500">Non rattaché</span>
                      )}
                      {r.match_confidence === 'fuzzy' && (
                        <span
                          className="ml-1.5 rounded px-1 py-0.5 text-[10px] font-semibold bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300"
                          title="Rattachement approximatif — à vérifier"
                        >
                          approx.
                        </span>
                      )}
                    </td>
                    <td className="py-2 pr-3 text-gray-500 dark:text-gray-400">{r.company_name_raw}</td>
                    <td className="py-2 pr-3">
                      <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${actionTypeBadgeClass(r.action_type)}`}>
                        {actionTypeLabel(r.action_type)}
                      </span>
                    </td>
                    <td className="py-2 pr-3 text-right tabular-nums">{fmtAmount(r.amount, r.currency)}</td>
                    <td className="py-2 pr-3 max-w-md text-gray-600 dark:text-gray-300">{r.description ?? '—'}</td>
                    <td className="py-2 pr-3 whitespace-nowrap text-gray-500 dark:text-gray-400">
                      {r.bulletin_publish_date}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <ChartAiAnalysis
            chartType="corporate_actions"
            parameters={filters}
            data={rows}
            disabled={rows.length === 0}
          />
        </Card>
      )}
      </>
      )}

      {activeTab === 'per' && (
        <>
          <InfoPanel>
            <p>
              <strong>À quoi sert cet écran.</strong> Chaque Bulletin Officiel de la Cote (BOC) publie, dans son
              tableau « par valeur », un PER et un rendement net calculés par la BRVM elle-même pour chaque action,
              séance par séance. Contrairement au PER calculé par ailleurs dans l'application (voir l'écran États
              financiers), ce PER officiel utilise toujours le nombre d'actions en circulation réel à la date du
              bulletin — pas le nombre actuel de l'entreprise. Cet écran extrait cette ligne par valeur et par
              bulletin pour l'afficher en complément.
            </p>
            <p>
              <strong>Rattachement à une entreprise.</strong> Le code (symbole) imprimé dans le bulletin est
              rapproché directement de <code>companies.symbol</code> — une correspondance exacte, pas une
              estimation par nom.
            </p>
            <p>
              <strong>Complétude.</strong> Seuls les bulletins déjà extraits ici apparaissent dans le tableau —
              clique sur « Extraire » pour les bulletins encore en attente ci-dessous.
            </p>
          </InfoPanel>

          {!!metricsData?.pending_count && (
            <Card>
              <div className="mb-2 flex items-center justify-between">
                <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">
                  {metricsData.pending_count} bulletin(s) pas encore extrait(s)
                </h3>
              </div>
              <div className="flex flex-col gap-2">
                {metricsData.pending_bulletins.map((b) => (
                  <div key={b.id} className="flex items-center justify-between gap-3 rounded-md border border-gray-100 px-3 py-2 text-sm dark:border-gray-800">
                    <span className="text-gray-600 dark:text-gray-300">
                      {b.publish_date} — {b.title}
                    </span>
                    <Button
                      variant="secondary"
                      disabled={metricsExtractMutation.isPending && metricsExtractingId === b.id}
                      onClick={() => metricsExtractMutation.mutate(b.id)}
                    >
                      {metricsExtractMutation.isPending && metricsExtractingId === b.id ? 'Extraction…' : 'Extraire'}
                    </Button>
                  </div>
                ))}
              </div>
              {metricsExtractMutation.isError && (
                <p className="mt-2 text-xs text-red-600 dark:text-red-400">
                  {(metricsExtractMutation.error as Error).message}
                </p>
              )}
            </Card>
          )}

          <Card>
            <div className="flex flex-wrap items-end gap-4">
              <label className="w-64">
                <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Bulletin</span>
                <Select
                  value={metricsBulletinId}
                  onChange={(e) => {
                    setMetricsBulletinId(e.target.value)
                    if (e.target.value) setMetricsAsOfDate('')
                  }}
                >
                  <option value="">Automatique (le plus récent, ou par date)</option>
                  {(metricsData?.bulletins ?? []).map((b) => (
                    <option key={b.id} value={String(b.id)}>{b.publish_date} — {b.title}</option>
                  ))}
                </Select>
              </label>
              <label className="w-48">
                <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de référence</span>
                <Input
                  type="date"
                  value={metricsAsOfDate}
                  onChange={(e) => {
                    setMetricsAsOfDate(e.target.value)
                    if (e.target.value) setMetricsBulletinId('')
                  }}
                  disabled={metricsBulletinId !== ''}
                />
              </label>
              {(metricsAsOfDate !== '' || metricsBulletinId !== '') && (
                <button
                  type="button"
                  onClick={() => {
                    setMetricsAsOfDate('')
                    setMetricsBulletinId('')
                  }}
                  className="text-xs text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                >
                  Réinitialiser les filtres
                </button>
              )}
            </div>
            <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
              Choisis un bulletin précis pour voir exactement sa séance, ou une date de référence pour prendre
              automatiquement le bulletin le plus proche de cette date (sans jamais regarder après) — les deux sont
              exclusifs. Sans les deux : dernier bulletin traité. Une entreprise doit être sélectionnée en haut de
              page pour afficher la fiche résumée ci-dessous.
            </p>
          </Card>

          {latestMetric && (
            <div>
              <h3 className="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
                {metricsBulletinId
                  ? 'PER du bulletin sélectionné'
                  : metricsAsOfDate
                  ? `PER au ${metricsAsOfDate} (ou séance connue la plus proche avant)`
                  : 'PER du jour de cotation (dernier bulletin traité)'}
                {' — '}{latestMetric.company_symbol ?? latestMetric.symbol_raw}
              </h3>
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatTile
                  label={`PER (BRVM, ${latestMetric.publish_date})`}
                  value={fmtNum(latestMetric.per)}
                  tooltip="PER officiel BRVM du bulletin le plus proche de la date de référence choisie (ou du dernier bulletin traité si aucune date n'est choisie), sans jamais regarder une séance après cette date."
                />
                <StatTile
                  label="Cours de clôture"
                  value={fmtNum(latestMetric.close_price)}
                  tooltip="Cours de clôture tel qu'imprimé dans ce même bulletin."
                />
                <StatTile
                  label="Rendement net"
                  value={latestMetric.yield_net_percent !== null ? `${fmtNum(latestMetric.yield_net_percent)} %` : '—'}
                  tooltip="Rendement net officiel BRVM (colonne « Rdt. Net » du bulletin)."
                />
                <StatTile
                  label="Bulletin source"
                  value={latestMetric.bulletin_title ?? latestMetric.publish_date}
                  tooltip="Bulletin dont est extraite cette ligne — extrais un bulletin plus récent ci-dessus si disponible pour rafraîchir cette valeur."
                />
              </div>
            </div>
          )}

          {!selectedCompany && (
            <Card title="Rendement net officiel BRVM par entreprise (classement)">
              {metricsQuery.isLoading && <LoadingState label="Chargement des rendements…" />}
              {!metricsQuery.isLoading && yieldRankingChartData.length === 0 && (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  Aucun rendement net à classer pour ces critères — choisis un bulletin, une date de référence, ou
                  extrais d'autres bulletins ci-dessus.
                </p>
              )}
              {yieldRankingChartData.length > 0 && (
                <>
                  <ResponsiveContainer width="100%" height={Math.max(200, 26 * yieldRankingChartData.length + 40)}>
                    <BarChart data={yieldRankingChartData} layout="vertical" margin={{ top: 5, right: 40, bottom: 5, left: 10 }}>
                      <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} horizontal={false} />
                      <XAxis type="number" tick={{ fontSize: 10 }} tickFormatter={(v) => `${v}%`} />
                      <YAxis type="category" dataKey="symbol" width={70} tick={{ fontSize: 11 }} interval={0} />
                      <Tooltip
                        content={({ active, payload }) => {
                          if (!active || !payload?.length) return null
                          const r = payload[0].payload as (typeof yieldRankingChartData)[number]
                          return (
                            <div className="max-w-xs rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                              <div className="font-medium">{r.symbol} — {r.name}</div>
                              <div className="mt-0.5">Rendement net : <strong>{fmtNum(r.value)} %</strong></div>
                              <div className="text-gray-500 dark:text-gray-400">{r.publish_date}{r.bulletin_title ? ` — ${r.bulletin_title}` : ''}</div>
                            </div>
                          )
                        }}
                      />
                      <Bar dataKey="value" name="Rendement net %">
                        {yieldRankingChartData.map((r) => (
                          <Cell
                            key={r.key}
                            fill={r.value >= 6 ? 'var(--chart-positive)' : r.value >= 3 ? 'var(--chart-2)' : 'var(--chart-soft)'}
                          />
                        ))}
                      </Bar>
                    </BarChart>
                  </ResponsiveContainer>
                  <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Rendement net officiel BRVM (colonne « Rdt. Net » du bulletin), dernière valeur connue par
                    entreprise selon le bulletin ou la date de référence choisis ci-dessus. Vert : au-dessus de 6 % ·
                    orange : 3 à 6 % · gris : en dessous de 3 %. Choisis une entreprise dans le sélecteur en haut de
                    page pour voir son évolution dans le temps plutôt que ce classement à un instant donné.
                  </p>
                </>
              )}
            </Card>
          )}

          {selectedCompany && (
            <Card title={`Évolution — ${selectedCompany.name}`}>
              {companyHistoryQuery.isLoading && <LoadingState label="Chargement de l'historique…" />}
              {companyHistoryQuery.error && <ErrorState message={(companyHistoryQuery.error as Error).message} />}
              {!companyHistoryQuery.isLoading && historyChartData.length === 0 && (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  Aucun bulletin déjà extrait ne contient cette entreprise.
                </p>
              )}
              {historyChartData.length === 1 && (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  Un seul bulletin extrait contient cette entreprise pour l'instant — pas assez de points pour tracer
                  une évolution. Extrais d'autres bulletins ci-dessus pour faire apparaître les graphes.
                </p>
              )}
              {historyChartData.length > 1 && (
                <div className="flex flex-col gap-6">
                  <div>
                    <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                      Cours de clôture (par bulletin)
                    </h4>
                    <ResponsiveContainer width="100%" height={180}>
                      <LineChart data={historyChartData} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                        <XAxis dataKey="date" tick={{ fontSize: 10 }} minTickGap={20} />
                        <YAxis tick={{ fontSize: 10 }} width={70} domain={['auto', 'auto']} tickFormatter={(v) => fmtNum(v, 0)} />
                        <Tooltip content={<HistoryTooltip />} />
                        <Line
                          type="monotone"
                          dataKey="close_price"
                          name="Cours de clôture"
                          stroke="var(--chart-1)"
                          strokeWidth={2}
                          dot={{ r: 3 }}
                          connectNulls
                          isAnimationActive={false}
                        />
                      </LineChart>
                    </ResponsiveContainer>
                  </div>

                  <div>
                    <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                      PER officiel BRVM (par bulletin)
                    </h4>
                    <ResponsiveContainer width="100%" height={180}>
                      <LineChart data={historyChartData} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                        <XAxis dataKey="date" tick={{ fontSize: 10 }} minTickGap={20} />
                        <YAxis tick={{ fontSize: 10 }} width={70} domain={['auto', 'auto']} tickFormatter={(v) => fmtNum(v, 1)} />
                        <Tooltip content={<HistoryTooltip />} />
                        <Line
                          type="monotone"
                          dataKey="per"
                          name="PER"
                          stroke="var(--chart-2)"
                          strokeWidth={2}
                          dot={{ r: 3 }}
                          connectNulls
                          isAnimationActive={false}
                        />
                      </LineChart>
                    </ResponsiveContainer>
                  </div>

                  <div>
                    <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                      Rendement net officiel BRVM (par bulletin)
                    </h4>
                    <ResponsiveContainer width="100%" height={180}>
                      <LineChart data={historyChartData} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                        <XAxis dataKey="date" tick={{ fontSize: 10 }} minTickGap={20} />
                        <YAxis tick={{ fontSize: 10 }} width={70} domain={['auto', 'auto']} tickFormatter={(v) => `${fmtNum(v, 1)}%`} />
                        <Tooltip content={<HistoryTooltip />} />
                        <Line
                          type="monotone"
                          dataKey="yield_net_percent"
                          name="Rendement net"
                          stroke="var(--chart-3)"
                          strokeWidth={2}
                          dot={{ r: 3 }}
                          connectNulls
                          isAnimationActive={false}
                        />
                      </LineChart>
                    </ResponsiveContainer>
                  </div>
                </div>
              )}
              <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Un point par bulletin déjà extrait contenant cette entreprise — pas une séance sur deux, seulement les
                bulletins traités jusqu'ici (voir la liste des bulletins en attente plus haut pour compléter la
                couverture). Une valeur absente du bulletin (tiret) n'est pas tracée plutôt que d'être devinée.
              </p>
            </Card>
          )}

          {metricsQuery.isLoading && <LoadingState label="Chargement des métriques…" />}
          {metricsQuery.error && <ErrorState message={(metricsQuery.error as Error).message} />}

          {metricsData && metricsRows.length === 0 && (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              Aucune métrique ne correspond à ces critères.
            </p>
          )}

          {metricsRows.length > 0 && (
            <Card>
              <div className="mb-3 text-xs text-gray-500 dark:text-gray-400">{metricsRows.length} ligne(s)</div>
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead>
                    <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                      <th className="pb-2 pr-3">Date</th>
                      <th className="pb-2 pr-3">Entreprise</th>
                      <th className="pb-2 pr-3 text-right">Cours de clôture</th>
                      <th className="pb-2 pr-3 text-right">PER (BRVM)</th>
                      <th className="pb-2 pr-3 text-right">Rendement net</th>
                      <th className="pb-2 pr-3">Bulletin source</th>
                    </tr>
                  </thead>
                  <tbody>
                    {metricsRows.map((r) => (
                      <tr key={r.id} className="border-t border-gray-100 align-top dark:border-gray-800">
                        <td className="py-2 pr-3 whitespace-nowrap tabular-nums">{r.publish_date}</td>
                        <td className="py-2 pr-3">
                          {r.company_symbol ? (
                            <span className="font-medium">{r.company_symbol}</span>
                          ) : (
                            <span className="text-gray-400 dark:text-gray-500" title={r.symbol_raw}>
                              Non rattaché ({r.symbol_raw})
                            </span>
                          )}
                        </td>
                        <td className="py-2 pr-3 text-right tabular-nums">{fmtNum(r.close_price)}</td>
                        <td className="py-2 pr-3 text-right tabular-nums">{fmtNum(r.per)}</td>
                        <td className="py-2 pr-3 text-right tabular-nums">
                          {r.yield_net_percent !== null ? `${fmtNum(r.yield_net_percent)} %` : '—'}
                        </td>
                        <td className="py-2 pr-3 whitespace-nowrap text-gray-500 dark:text-gray-400">
                          {r.bulletin_title}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>
          )}

          <InfoPanel>
            <p>
              <strong>Rendement net sur une période — attention, autre calcul.</strong> Les graphes ci-dessus tracent
              le « Rdt. Net » imprimé par la BRVM dans le bulletin, séance par séance. Le graphe ci-dessous répond à
              une question différente : « combien mon capital a-t-il gagné (ou perdu) entre deux dates que je
              choisis ? » — c'est la variation du cours de clôture sur la période, pas la colonne du bulletin. Ce
              calcul ne tient pas compte des dividendes versés entre les deux dates.
            </p>
          </InfoPanel>

          <Card>
            <div className="flex flex-wrap items-end gap-4">
              <label className="w-40">
                <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de début</span>
                <Input type="date" value={perfStartDate} max={perfEndDate} onChange={(e) => setPerfStartDate(e.target.value)} />
              </label>
              <label className="w-40">
                <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de fin</span>
                <Input type="date" value={perfEndDate} min={perfStartDate} max={todayIso()} onChange={(e) => setPerfEndDate(e.target.value)} />
              </label>
            </div>
            {perfStartDate > perfEndDate && (
              <p className="mt-2 text-xs text-red-600 dark:text-red-400">La date de début doit précéder la date de fin.</p>
            )}
          </Card>

          {performanceRankingQuery.isLoading && <LoadingState label="Chargement de la performance…" />}
          {performanceRankingQuery.error && <ErrorState message={(performanceRankingQuery.error as Error).message} />}

          {performanceRankingQuery.data && perfRankingChartData.length === 0 && (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              Aucune cotation sur cette période pour calculer une performance.
            </p>
          )}

          {perfRankingChartData.length > 0 && (
            <Card title={`Performance du cours entre le ${perfStartDate} et le ${perfEndDate}`}>
              <ResponsiveContainer width="100%" height={Math.max(240, perfRankingChartData.length * 24)}>
                <BarChart data={perfRankingChartData} layout="vertical" margin={{ top: 5, right: 24, bottom: 5, left: 16 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} horizontal={false} />
                  <XAxis type="number" tick={{ fontSize: 11 }} tickFormatter={(v) => `${fmtNum(v, 1)}%`} />
                  <YAxis type="category" dataKey="symbol" width={70} tick={{ fontSize: 11 }} interval={0} />
                  <ReferenceLine x={0} stroke="var(--chart-muted)" />
                  <Tooltip
                    content={({ active, payload }) => {
                      if (!active || !payload?.length) return null
                      const r = payload[0].payload as (typeof perfRankingChartData)[number]
                      return (
                        <div className="rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                          <div className="font-medium">{r.symbol} — {r.name}</div>
                          <div className="mt-0.5">Performance : <strong>{r.variation > 0 ? '+' : ''}{fmtNum(r.variation)} %</strong></div>
                        </div>
                      )
                    }}
                  />
                  <Bar dataKey="variation" name="Performance %" radius={[0, 4, 4, 0]}>
                    {perfRankingChartData.map((r) => (
                      <Cell key={r.company_id} fill={r.variation >= 0 ? 'var(--chart-positive)' : 'var(--chart-negative)'} />
                    ))}
                    <LabelList dataKey="variation" position="right" formatter={(v) => `${fmtNum(v as number, 1)}%`} className="fill-gray-500 text-[10px] dark:fill-gray-400" />
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
              <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Vert : hausse du cours sur la période · rouge : baisse. Entreprises sans cotation sur la période
                absentes du graphe (pas de valeur à calculer).
              </p>
            </Card>
          )}
        </>
      )}

      {activeTab === 'indices' && (
        <>
          <InfoPanel>
            <p>
              <strong>À quoi sert cet onglet.</strong> BRVM-COMPOSITE, BRVM-30, BRVM-PRESTIGE et BRVM-PRINCIPAL sont
              les indices officiels publiés quotidiennement par la BRVM — chacun regroupe un sous-ensemble de
              sociétés cotées, pondérées par leur capitalisation, pour mesurer l'évolution du marché dans son
              ensemble. Le graphe recale chaque indice à 0% au premier jour de la période choisie, pour comparer
              directement leur performance relative.
            </p>
            <p>
              <strong>Comparer une entreprise à l'indice.</strong> Choisis une entreprise dans le sélecteur en haut
              de page : son écart de performance cumulée avec le BRVM Composite s'affiche ci-dessous — au-dessus de
              0%, elle surperforme l'indice sur la période ; en dessous, elle sous-performe.
            </p>
          </InfoPanel>

          <Card>
            <div className="flex flex-wrap items-end gap-4">
              <label className="w-40">
                <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de début</span>
                <Input type="date" value={indexStartDate} max={indexEndDate} onChange={(e) => setIndexStartDate(e.target.value)} />
              </label>
              <label className="w-40">
                <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de fin</span>
                <Input type="date" value={indexEndDate} min={indexStartDate} max={todayIso()} onChange={(e) => setIndexEndDate(e.target.value)} />
              </label>
            </div>

            <div className="mt-4 flex flex-wrap items-center gap-4">
              <span className="text-xs font-medium text-gray-700 dark:text-gray-300">Indices :</span>
              {marketIndices.map((idx) => (
                <label key={idx.id} className="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                  <input type="checkbox" checked={selectedIndexCodes.includes(idx.code)} onChange={() => toggleIndexCode(idx.code)} />
                  {idx.code}
                </label>
              ))}
            </div>

            {indexStartDate > indexEndDate && (
              <p className="mt-2 text-xs text-red-600 dark:text-red-400">La date de début doit précéder la date de fin.</p>
            )}
          </Card>

          {indicesQuery.isLoading && <LoadingState label="Chargement des indices…" />}
          {indicesQuery.error && <ErrorState message={(indicesQuery.error as Error).message} />}

          {selectedIndexCodes.length === 0 && !indicesQuery.isLoading && (
            <p className="text-sm text-gray-500 dark:text-gray-400">Sélectionne au moins un indice ci-dessus.</p>
          )}

          {selectedIndexCodes.length > 0 && indexChartData.length > 0 && (
            <Card title="Évolution des indices sélectionnés (variation depuis le début)">
              <ResponsiveContainer width="100%" height={320}>
                <LineChart data={indexChartData}>
                  <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                  <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                  <YAxis tick={{ fontSize: 11 }} width={60} tickFormatter={(v: number) => `${v}%`} />
                  <ReferenceLine y={0} stroke="var(--chart-muted)" strokeDasharray="3 3" />
                  <Tooltip formatter={(value, name) => [`${Number(value) > 0 ? '+' : ''}${Number(value).toFixed(2)}%`, name]} />
                  <Legend wrapperStyle={{ fontSize: 12 }} />
                  {indexSeries.map((serie, i) => (
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
                parameters={{ index_codes: [...selectedIndexCodes].sort(), start_date: indexStartDate, end_date: indexEndDate, display_mode: 'percent' }}
                data={indexSeries}
              />
            </Card>
          )}

          {selectedIndexCodes.length > 0 && indexChartData.length === 0 && !indicesQuery.isLoading && (
            <p className="text-sm text-gray-500 dark:text-gray-400">Aucune donnée pour la sélection sur cette période.</p>
          )}

          {!selectedCompany && (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              Choisis une entreprise dans le sélecteur en haut de page pour comparer sa performance à celle du BRVM
              Composite.
            </p>
          )}

          {selectedCompany && relativeStrengthQuery.isLoading && <LoadingState label="Chargement de la force relative…" />}
          {selectedCompany && relativeStrengthQuery.error && (
            <ErrorState message={(relativeStrengthQuery.error as Error).message} />
          )}

          {selectedCompany && relativeStrengthData.length > 1 && (
            <Card title={`Force relative — ${selectedCompany.name} vs BRVM Composite`}>
              <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
                Écart entre la variation cumulée de l'entreprise et celle de l'indice sur la période — au-dessus de
                0 : l'entreprise surperforme l'indice.
              </p>
              <ResponsiveContainer width="100%" height={220}>
                <LineChart data={relativeStrengthData}>
                  <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                  <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                  <YAxis tick={{ fontSize: 11 }} width={60} tickFormatter={(v: number) => `${v}%`} />
                  <ReferenceLine y={0} stroke="var(--chart-muted)" strokeDasharray="3 3" />
                  <Tooltip formatter={(value) => [`${Number(value).toFixed(2)}%`, 'Force relative']} />
                  <Line type="monotone" dataKey="relative_strength" stroke="var(--chart-1)" dot={false} strokeWidth={2} connectNulls />
                </LineChart>
              </ResponsiveContainer>

              <ChartAiAnalysis
                chartType="relative_strength"
                parameters={{ company_ids: [selectedCompany.company_id], start_date: indexStartDate, end_date: indexEndDate }}
                data={relativeStrengthQuery.data}
              />
            </Card>
          )}

          {selectedCompany && relativeStrengthQuery.data && relativeStrengthData.length <= 1 && (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              Pas assez d'historique de cours sur cette période pour calculer une force relative.
            </p>
          )}
        </>
      )}

      {activeTab === 'annonces' && (
        <>
          <InfoPanel>
            <p>
              <strong>À quoi sert cet onglet.</strong> En plus du Bulletin Officiel de la Cote, la BRVM publie sur
              son site des <strong>avis et communiqués</strong> par émetteur — convocations d'assemblées, résultats
              financiers, opérations sur titres détaillées, communiqués divers — chacun sous forme de PDF distinct.
              Cet onglet les découvre, les télécharge et les extrait par IA, séparément du pipeline des bulletins.
            </p>
            <p>
              <strong>Rattachement à une entreprise.</strong> Le nom de la société dans le listing brvm.org est
              automatiquement rapproché d'une entreprise de la base ; « approx. » signale une correspondance
              probable mais pas garantie. Certaines annonces (avis généraux, données économiques) ne concernent
              aucune entreprise en particulier — elles restent consultables via la case « annonces générales du
              marché » ci-dessous.
            </p>
          </InfoPanel>

          {selectedCompany ? (
            <CompanyAnnouncements companyId={selectedCompany.company_id} />
          ) : (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              Choisis une entreprise dans le sélecteur en haut de page pour voir ses annonces émetteurs (et, en
              option depuis cet écran, les annonces générales du marché).
            </p>
          )}
        </>
      )}

      {activeTab === 'pression' && (
        <>
          <InfoPanel>
            <p>
              <strong>À quoi sert cet onglet.</strong> Chaque Bulletin Officiel de la Cote publie, pour l'action
              choisie dans le sélecteur en haut de page, un carnet d'ordres fin de séance : ce qu'il reste de
              quantité en attente à l'achat et à la vente, non exécuté faute de contrepartie. Cet onglet répond à
              « ses actionnaires sont-ils plutôt acheteurs ou plutôt vendeurs sur la période choisie ? ».
            </p>
            <p>
              <strong>Ce que ça mesure, et ce que ça ne mesure pas.</strong> C'est <strong>observé</strong> dans le
              bulletin (pas une estimation), mais uniquement sur ce qui reste affiché en carnet en fin de séance —
              pas un décompte de tous les ordres passés dans la journée.
            </p>
          </InfoPanel>

          {!selectedCompany && (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              Choisis une entreprise dans le sélecteur en haut de page pour voir si ses actionnaires sont plutôt
              acheteurs ou vendeurs.
            </p>
          )}

          {selectedCompany && (
            <>
              {!!marketPressureQuery.data?.pending_count && (
                <Card>
                  <div className="flex items-center justify-between gap-3">
                    <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">
                      {marketPressureQuery.data.pending_count} bulletin(s) sans carnet d'ordres extrait
                    </h3>
                    <Button
                      variant="secondary"
                      disabled={parseOrderBooksMutation.isPending}
                      onClick={() => parseOrderBooksMutation.mutate()}
                    >
                      {parseOrderBooksMutation.isPending ? 'Extraction…' : "Extraire tous les carnets manquants"}
                    </Button>
                  </div>
                  <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Extraction déterministe (lecture directe du tableau « Quantité résiduelle » du bulletin, pas
                    d'IA) — traite tous les bulletins en attente en un seul clic, toutes entreprises confondues.
                  </p>
                  {parseOrderBooksMutation.isError && (
                    <p className="mt-2 text-xs text-red-600 dark:text-red-400">
                      {(parseOrderBooksMutation.error as Error).message}
                    </p>
                  )}
                </Card>
              )}

              <Card>
                <div className="flex flex-wrap items-end gap-4">
                  <label className="w-40">
                    <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de début</span>
                    <Input type="date" value={pressureStartDate} max={pressureEndDate} onChange={(e) => setPressureStartDate(e.target.value)} />
                  </label>
                  <label className="w-40">
                    <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de fin</span>
                    <Input type="date" value={pressureEndDate} min={pressureStartDate} max={todayIso()} onChange={(e) => setPressureEndDate(e.target.value)} />
                  </label>
                </div>
                {pressureStartDate > pressureEndDate && (
                  <p className="mt-2 text-xs text-red-600 dark:text-red-400">La date de début doit précéder la date de fin.</p>
                )}
              </Card>

              {companyOrderBookQuery.isLoading && <LoadingState label="Chargement…" />}
              {companyOrderBookQuery.error && <ErrorState message={(companyOrderBookQuery.error as Error).message} />}

              {companyOrderBookQuery.data && pressureChartData.length === 0 && (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  Aucun carnet d'ordres extrait pour {selectedCompany.name} sur cette période — extrais les
                  bulletins en attente ci-dessus.
                </p>
              )}

              {pressureChartData.length > 0 && (
                <>
                  <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <StatTile
                      label="Séances plutôt acheteuses"
                      value={`${buyerDaysCount} / ${pressureChartData.length}`}
                      tone={buyerDaysCount > sellerDaysCount ? 'positive' : 'default'}
                    />
                    <StatTile
                      label="Séances plutôt vendeuses"
                      value={`${sellerDaysCount} / ${pressureChartData.length}`}
                      tone={sellerDaysCount > buyerDaysCount ? 'negative' : 'default'}
                    />
                    <StatTile
                      label="Dernière séance connue"
                      value={lastPressureDay ? lastPressureDay.date : '—'}
                    />
                    <StatTile
                      label="Ratio achat/(achat+vente) — dernière séance"
                      value={lastPressureDay?.imbalance_ratio !== null && lastPressureDay?.imbalance_ratio !== undefined ? `${(lastPressureDay.imbalance_ratio * 100).toFixed(1)} %` : '—'}
                      tooltip="0,5 (50%) = équilibre. Au-dessus : actionnaires plutôt acheteurs. En dessous : plutôt vendeurs."
                      tone={
                        lastPressureDay?.imbalance_ratio === null || lastPressureDay?.imbalance_ratio === undefined
                          ? 'default'
                          : lastPressureDay.imbalance_ratio > 0.5
                            ? 'positive'
                            : lastPressureDay.imbalance_ratio < 0.5
                              ? 'negative'
                              : 'default'
                      }
                    />
                  </div>

                  <Card title={`Pression nette (quantité résiduelle achat − vente) — ${selectedCompany.name}`}>
                    <ResponsiveContainer width="100%" height={220}>
                      <BarChart data={pressureChartData} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                        <XAxis dataKey="date" tick={{ fontSize: 10 }} minTickGap={20} />
                        <YAxis tick={{ fontSize: 10 }} width={80} tickFormatter={(v) => fmtNum(v, 0)} />
                        <ReferenceLine y={0} stroke="var(--chart-muted)" />
                        <Tooltip
                          content={({ active, payload }) => {
                            if (!active || !payload?.length) return null
                            const p = payload[0].payload as (typeof pressureChartData)[number]
                            return (
                              <div className="rounded-md border border-gray-200 bg-white px-3 py-2 text-xs shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                <div className="mb-1 font-medium">{p.date}</div>
                                <div>Pression nette : <strong>{fmtNum(p.net_qty, 0)}</strong> ({p.net_qty > 0 ? 'acheteur' : p.net_qty < 0 ? 'vendeur' : 'équilibre'})</div>
                                <div>Résiduel achat : {fmtNum(p.bid_qty, 0)} · vente : {fmtNum(p.ask_qty, 0)}</div>
                              </div>
                            )
                          }}
                        />
                        <Bar dataKey="net_qty" name="Pression nette">
                          {pressureChartData.map((d, i) => (
                            <Cell key={i} fill={d.net_qty > 0 ? 'var(--chart-positive)' : d.net_qty < 0 ? 'var(--chart-negative)' : 'var(--chart-muted)'} />
                          ))}
                        </Bar>
                      </BarChart>
                    </ResponsiveContainer>
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                      Vert au-dessus de la ligne : quantité résiduelle à l'achat supérieure à la vente sur cette
                      séance (actionnaires plutôt acheteurs). Rouge en dessous : l'inverse (plutôt vendeurs).
                    </p>
                  </Card>

                  <Card title="Ratio achat/(achat+vente) dans le temps">
                    <ResponsiveContainer width="100%" height={200}>
                      <LineChart data={pressureChartData} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                        <XAxis dataKey="date" tick={{ fontSize: 10 }} minTickGap={20} />
                        <YAxis tick={{ fontSize: 10 }} width={50} domain={[0, 1]} tickFormatter={(v) => `${Math.round(v * 100)}%`} />
                        <ReferenceLine y={0.5} stroke="var(--chart-muted)" strokeDasharray="3 3" />
                        <Tooltip formatter={(value) => [value !== null ? `${(Number(value) * 100).toFixed(1)} %` : '—', 'Ratio achat']} />
                        <Line
                          type="monotone"
                          dataKey="imbalance_ratio"
                          name="Ratio achat"
                          stroke="var(--chart-1)"
                          strokeWidth={2}
                          dot={{ r: 3 }}
                          connectNulls
                          isAnimationActive={false}
                        />
                      </LineChart>
                    </ResponsiveContainer>
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                      Au-dessus de la ligne des 50% : plus de quantité résiduelle à l'achat qu'à la vente ce
                      jour-là (actionnaires plutôt acheteurs). En dessous : l'inverse.
                    </p>
                  </Card>

                  <Card>
                    <div className="mb-3 text-xs text-gray-500 dark:text-gray-400">{pressureChartData.length} séance(s)</div>
                    <div className="overflow-x-auto">
                      <table className="w-full text-left text-sm">
                        <thead>
                          <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                            <th className="pb-2 pr-3">Date</th>
                            <th className="pb-2 pr-3 text-right">Qté résid. achat</th>
                            <th className="pb-2 pr-3 text-right">Qté résid. vente</th>
                            <th className="pb-2 pr-3 text-right">Pression nette</th>
                            <th className="pb-2 pr-3 text-right">Ratio achat</th>
                          </tr>
                        </thead>
                        <tbody>
                          {[...pressureChartData].sort((a, b) => b.date.localeCompare(a.date)).map((d) => (
                            <tr key={d.date} className="border-t border-gray-100 align-top dark:border-gray-800">
                              <td className="py-2 pr-3 whitespace-nowrap tabular-nums">{d.date}</td>
                              <td className="py-2 pr-3 text-right tabular-nums">{fmtNum(d.bid_qty, 0)}</td>
                              <td className="py-2 pr-3 text-right tabular-nums">{fmtNum(d.ask_qty, 0)}</td>
                              <td className={`py-2 pr-3 text-right font-semibold tabular-nums ${d.net_qty > 0 ? 'text-emerald-600 dark:text-emerald-400' : d.net_qty < 0 ? 'text-red-600 dark:text-red-400' : ''}`}>
                                {fmtNum(d.net_qty, 0)}
                              </td>
                              <td className="py-2 pr-3 text-right tabular-nums">
                                {d.imbalance_ratio !== null ? `${(d.imbalance_ratio * 100).toFixed(1)} %` : '—'}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </Card>
                </>
              )}
            </>
          )}
        </>
      )}

      {activeTab === 'comparaison' && (
        <>
          <InfoPanel>
            <p>
              <strong>À quoi sert cet onglet.</strong> Les autres onglets ci-dessus se concentrent sur UNE entreprise
              (le sélecteur en haut de page) ou sur le marché entier. Celui-ci répond à « comment ces entreprises se
              comparent-elles entre elles ? » — coche 2 entreprises ou plus ci-dessous, choisis une période, puis
              une des 5 vues (opérations, dividendes, PER/rendement, indice/performance, agitation du marché).
            </p>
            <p>
              Cette sélection et ces dates sont propres à cet onglet — indépendantes du sélecteur d'entreprise unique
              utilisé par les autres onglets de cette page.
            </p>
          </InfoPanel>

          <Card>
            <div className="flex flex-col gap-4">
              <div>
                <span className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Entreprises à comparer</span>
                <div className="max-h-64 overflow-y-auto rounded-md border border-gray-200 p-3 dark:border-gray-800">
                  {comparisonSectors.map((sector) => (
                    <div key={sector.name} className="mb-3 last:mb-0">
                      <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                        {sector.name}
                      </div>
                      <div className="flex flex-wrap gap-2">
                        {sector.members.map((c) => {
                          const isSelected = comparisonSelected.includes(c.company_id)
                          return (
                            <label
                              key={c.company_id}
                              className={`cursor-pointer rounded-full border px-3 py-1 text-xs font-medium ${
                                isSelected ? 'text-white' : 'border-gray-300 text-gray-600 dark:border-gray-700 dark:text-gray-300'
                              }`}
                              style={
                                isSelected
                                  ? { backgroundColor: comparisonColorFor(c.company_id, comparisonSelected), borderColor: comparisonColorFor(c.company_id, comparisonSelected) }
                                  : undefined
                              }
                            >
                              <input type="checkbox" className="hidden" checked={isSelected} onChange={() => toggleComparisonCompany(c.company_id)} />
                              {c.symbol}
                            </label>
                          )
                        })}
                      </div>
                    </div>
                  ))}
                  {comparisonUnclassified.length > 0 && (
                    <div>
                      <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                        Secteur non renseigné
                      </div>
                      <div className="flex flex-wrap gap-2">
                        {comparisonUnclassified.map((c) => {
                          const isSelected = comparisonSelected.includes(c.company_id)
                          return (
                            <label
                              key={c.company_id}
                              className={`cursor-pointer rounded-full border px-3 py-1 text-xs font-medium ${
                                isSelected ? 'text-white' : 'border-gray-300 text-gray-600 dark:border-gray-700 dark:text-gray-300'
                              }`}
                              style={
                                isSelected
                                  ? { backgroundColor: comparisonColorFor(c.company_id, comparisonSelected), borderColor: comparisonColorFor(c.company_id, comparisonSelected) }
                                  : undefined
                              }
                            >
                              <input type="checkbox" className="hidden" checked={isSelected} onChange={() => toggleComparisonCompany(c.company_id)} />
                              {c.symbol}
                            </label>
                          )
                        })}
                      </div>
                    </div>
                  )}
                </div>
                {comparisonSelected.length > 0 && (
                  <button
                    type="button"
                    onClick={() => setComparisonSelected([])}
                    className="mt-1.5 text-xs text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                  >
                    Tout décocher
                  </button>
                )}
              </div>

              <div className="flex flex-wrap items-end gap-4">
                <label className="w-40">
                  <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de début</span>
                  <Input type="date" value={comparisonStartDate} max={comparisonEndDate} onChange={(e) => setComparisonStartDate(e.target.value)} />
                </label>
                <label className="w-40">
                  <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de fin</span>
                  <Input type="date" value={comparisonEndDate} min={comparisonStartDate} max={todayIso()} onChange={(e) => setComparisonEndDate(e.target.value)} />
                </label>
              </div>
              {comparisonStartDate > comparisonEndDate && (
                <p className="text-xs text-red-600 dark:text-red-400">La date de début doit précéder la date de fin.</p>
              )}
            </div>
          </Card>

          {comparisonSelected.length === 0 && (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              Coche au moins 2 entreprises ci-dessus pour afficher une comparaison.
            </p>
          )}

          {comparisonSelected.length > 0 && (
            <>
              <Tabs
                tabs={[
                  { id: 'liste', label: 'Opérations sur titres' },
                  { id: 'dividendes', label: 'Dividendes' },
                  { id: 'per', label: 'PER & rendement' },
                  { id: 'indices', label: 'Indice & performance' },
                  { id: 'pression', label: 'Agitation du marché' },
                ]}
                active={comparisonSubTab}
                onChange={(id) => setComparisonSubTab(id as 'liste' | 'dividendes' | 'per' | 'indices' | 'pression')}
              />

              {comparisonSubTab === 'liste' && (
                <Card title="Opérations sur titres — entreprises sélectionnées">
                  {comparisonActionsQuery.isLoading && <LoadingState label="Chargement…" />}
                  {comparisonActionsQuery.error && <ErrorState message={(comparisonActionsQuery.error as Error).message} />}
                  {comparisonActionsQuery.data && comparisonActionRows.length === 0 && (
                    <p className="text-sm text-gray-500 dark:text-gray-400">
                      Aucune opération pour ces entreprises sur la période choisie.
                    </p>
                  )}
                  {comparisonActionRows.length > 0 && (
                    <div className="overflow-x-auto">
                      <table className="w-full text-left text-sm">
                        <thead>
                          <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                            <th className="pb-2 pr-3">Date</th>
                            <th className="pb-2 pr-3">Entreprise</th>
                            <th className="pb-2 pr-3">Type</th>
                            <th className="pb-2 pr-3 text-right">Montant</th>
                            <th className="pb-2 pr-3">Description</th>
                          </tr>
                        </thead>
                        <tbody>
                          {[...comparisonActionRows]
                            .sort((a, b) => (b.event_date ?? '').localeCompare(a.event_date ?? ''))
                            .map((r) => (
                              <tr key={r.id} className="border-t border-gray-100 align-top dark:border-gray-800">
                                <td className="py-2 pr-3 whitespace-nowrap tabular-nums">{r.event_date ?? '—'}</td>
                                <td className="py-2 pr-3 whitespace-nowrap">
                                  <span
                                    className="mr-1.5 inline-block h-2 w-2 rounded-full align-middle"
                                    style={{ backgroundColor: comparisonColorFor(r.company_id as number, comparisonSelected) }}
                                  />
                                  <span className="font-medium">{r.company_symbol}</span>
                                </td>
                                <td className="py-2 pr-3">
                                  <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${actionTypeBadgeClass(r.action_type)}`}>
                                    {actionTypeLabel(r.action_type)}
                                  </span>
                                </td>
                                <td className="py-2 pr-3 text-right tabular-nums">{fmtAmount(r.amount, r.currency)}</td>
                                <td className="py-2 pr-3 max-w-md text-gray-600 dark:text-gray-300">{r.description ?? '—'}</td>
                              </tr>
                            ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </Card>
              )}

              {comparisonSubTab === 'dividendes' && (
                <DividendComparisonPanel companies={companyOptions} selectedIds={comparisonSelected} colorFor={comparisonColorFor} />
              )}

              {comparisonSubTab === 'per' && (
                <Card title="Rendement net officiel BRVM (par bulletin) — entreprises sélectionnées">
                  {comparisonMetricsQuery.isLoading && <LoadingState label="Chargement…" />}
                  {comparisonMetricsQuery.error && <ErrorState message={(comparisonMetricsQuery.error as Error).message} />}
                  {comparisonMetricsQuery.data && comparisonYieldChartData.length === 0 && (
                    <p className="text-sm text-gray-500 dark:text-gray-400">
                      Aucun rendement net connu pour ces entreprises sur la période choisie.
                    </p>
                  )}
                  {comparisonYieldChartData.length > 0 && (
                    <ResponsiveContainer width="100%" height={320}>
                      <LineChart data={comparisonYieldChartData}>
                        <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                        <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                        <YAxis tick={{ fontSize: 11 }} width={60} tickFormatter={(v: number) => `${v}%`} />
                        <Tooltip formatter={(value, name) => [`${fmtNum(value as number)} %`, name]} />
                        <Legend wrapperStyle={{ fontSize: 12 }} />
                        {comparisonSelected.map((id) => {
                          const symbol = companyOptions.find((c) => c.company_id === id)?.symbol
                          if (!symbol) return null
                          return (
                            <Line
                              key={id}
                              type="monotone"
                              dataKey={symbol}
                              stroke={comparisonColorFor(id, comparisonSelected)}
                              dot={{ r: 3 }}
                              strokeWidth={2}
                              connectNulls
                              isAnimationActive={false}
                            />
                          )
                        })}
                      </LineChart>
                    </ResponsiveContainer>
                  )}
                  <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Un point par bulletin déjà extrait contenant l'entreprise — une valeur absente du bulletin n'est
                    pas tracée plutôt que d'être devinée.
                  </p>
                </Card>
              )}

              {comparisonSubTab === 'indices' && (
                <Card title="Surperformance / sous-performance quotidienne vs BRVM-COMPOSITE">
                  {comparisonRelativeStrengthQuery.isLoading && <LoadingState label="Chargement…" />}
                  {comparisonRelativeStrengthQuery.error && (
                    <ErrorState message={(comparisonRelativeStrengthQuery.error as Error).message} />
                  )}
                  {comparisonRelativeStrengthQuery.data && comparisonRelativeStrengthChartData.length === 0 && (
                    <p className="text-sm text-gray-500 dark:text-gray-400">
                      Aucune donnée pour cette sélection sur la période choisie.
                    </p>
                  )}
                  {comparisonRelativeStrengthChartData.length > 0 && (
                    <ResponsiveContainer width="100%" height={320}>
                      <LineChart data={comparisonRelativeStrengthChartData}>
                        <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                        <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                        <YAxis tick={{ fontSize: 11 }} width={60} tickFormatter={(v: number) => `${v}%`} />
                        <ReferenceLine y={0} stroke="var(--chart-muted)" strokeDasharray="3 3" />
                        <Tooltip formatter={(value, name) => [`${Number(value) > 0 ? '+' : ''}${Number(value).toFixed(2)}%`, name]} />
                        <Legend wrapperStyle={{ fontSize: 12 }} />
                        <Line
                          type="monotone"
                          dataKey="BRVM-COMPOSITE"
                          name="BRVM-COMPOSITE (variation quotidienne)"
                          stroke="var(--chart-muted)"
                          strokeDasharray="5 3"
                          dot={false}
                          strokeWidth={1.5}
                          connectNulls
                        />
                        {comparisonRelativeStrengthSeries.map((serie) => (
                          <Line
                            key={serie.company_id}
                            type="monotone"
                            dataKey={serie.symbol}
                            stroke={comparisonColorFor(serie.company_id, comparisonSelected)}
                            dot={false}
                            strokeWidth={2}
                            connectNulls
                          />
                        ))}
                      </LineChart>
                    </ResponsiveContainer>
                  )}
                  <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Au-dessus de 0% : l'entreprise a fait mieux que le marché ce jour-là. En-dessous : moins bien —
                    même si son propre cours a progressé.
                  </p>
                </Card>
              )}

              {comparisonSubTab === 'pression' && (
                <LiquidityComparisonPanel
                  companies={companyOptions}
                  selectedIds={comparisonSelected}
                  colorFor={comparisonColorFor}
                  startDate={comparisonStartDate}
                  endDate={comparisonEndDate}
                />
              )}
            </>
          )}
        </>
      )}
    </div>
  )
}
