import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import {
  ResponsiveContainer,
  LineChart,
  Line,
  BarChart,
  Bar,
  PieChart,
  Pie,
  Cell,
  XAxis,
  YAxis,
  Tooltip,
  Legend,
  CartesianGrid,
  ReferenceLine,
  ReferenceDot,
} from 'recharts'
import { callApi, companyDocumentDownloadUrl, uploadFile } from '../lib/apiClient'
import type {
  BacktestResult,
  ChartAnalysisResult,
  Company,
  DividendRankingResult,
  LiquidityRankingResult,
  LiquidityRankingRow,
  CompanyDocumentAnalysis,
  CompanyDocumentDetail,
  CompanyDocumentSummary,
  CompanyPriceSeries,
  CompanySignal,
  CorporateActionsListResult,
  FundamentalsRow,
  LiquidityHistorySeries,
  MaCrossoverEvent,
  MarketBreadthPoint,
  MissingDaysIssue,
  OhlcPoint,
  PriceJumpIssue,
  ReconciliationIssue,
  RelativeStrengthSeries,
  ReportAnalysis,
  ReportAnalysisStats,
  ReportSummary,
  RecentTradingDates,
  RiskAdjustedResult,
  RiskMetricsRow,
  RsiDivergenceEvent,
  ScreenerRow,
  SectorPerformanceSeries,
  TechnicalIndicatorPoint,
  TotalVariationSeries,
  ValuationModel,
} from '../lib/types'
import { AnalysisBadge, Button, Card, ErrorState, InfoPanel, Input, LoadingState, MarkdownBadge, Modal, SearchableSelect, Select, StatTile, Tabs } from '../components/ui'
import { ChartAiAnalysis } from '../components/ChartAiAnalysis'
import { CompanyChatBot } from '../components/CompanyChatBot'
import { CompanyMarketEvents } from '../components/CompanyMarketEvents'
import { CompanyAnnouncements } from '../components/CompanyAnnouncements'
import { ExecutionFlowPanel, OrderBookLiquidityPanel } from '../components/CompanyOrderBook'
import { CompanyDividends } from '../components/CompanyDividends'
import { FundamentalsDetailPanel, REPORT_TYPE_LABELS } from '../components/FundamentalsDetailPanel'
import { FinancialsEditForm } from '../components/FinancialsEditForm'
import { DailyQuotesChart } from '../components/DailyQuotesChart'
import { EditIcon, EyeIcon, IconButton, RetryIcon, TrashIcon } from '../components/icons'

/** Mêmes seuils/couleurs que Quotes.tsx et Backtest.tsx (pas de composant Badge partagé dans ce projet, voir les autres pages). */
function signalBadgeClass(score: number | null) {
  if (score === null) return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'
  if (score >= 2) return 'bg-emerald-600 text-white'
  if (score === 1) return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
  if (score === 0) return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'
  if (score === -1) return 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
  return 'bg-red-600 text-white'
}

/**
 * Rang d'une entreprise sur un critère du classement marché, les entreprises
 * non classables (valeur nulle) étant retirées du décompte plutôt que
 * comptées comme des zéros. Retourne null si l'entreprise elle-même n'est
 * pas classable.
 */
function rankOnRows<T extends { company_id: number }>(
  rows: T[],
  companyId: number | null,
  value: (r: T) => number | null,
): { value: number; rank: number; total: number } | null {
  const ranked = rows
    .map((r) => ({ r, v: value(r) }))
    .filter((x): x is { r: T; v: number } => x.v !== null)
    .sort((a, b) => b.v - a.v)
  const index = ranked.findIndex((x) => x.r.company_id === companyId)
  if (index < 0) return null
  return { value: ranked[index].v, rank: index + 1, total: ranked.length }
}

const rankOn = (
  rows: LiquidityRankingRow[],
  companyId: number | null,
  value: (r: LiquidityRankingRow) => number | null,
) => rankOnRows(rows, companyId, value)

/** Date courte JJ/MM pour les libellés du bandeau (2026-08-06 → 06/08). */
function frDate(iso: string) {
  return `${iso.slice(8, 10)}/${iso.slice(5, 7)}`
}

/** « 1er », « 2e »… pour les rangs affichés dans le bandeau. */
function ordinal(rank: number) {
  return rank === 1 ? '1er' : `${rank}e`
}

/** Ce que veut dire chaque libellé du badge de signal technique. */
const SIGNAL_MEANINGS: Record<number, string> = {
  2: "La plupart des indicateurs techniques pointent dans le même sens haussier.",
  1: "Les indicateurs techniques penchent plutôt du côté d'une hausse, sans unanimité.",
  0: "Les indicateurs techniques se contredisent ou restent neutres : aucune direction dominante.",
  [-1]: "Les indicateurs techniques penchent plutôt du côté d'une baisse, sans unanimité.",
  [-2]: "La plupart des indicateurs techniques pointent dans le même sens baissier.",
}

/**
 * Infobulle du badge de signal : explique CE QUE le badge mesure, d'où il
 * vient (moyenne des indicateurs techniques, ramenée sur une échelle de -2
 * à +2), le détail indicateur par indicateur, et surtout ce qu'il n'est
 * pas — un conseil d'achat ou de vente.
 */
function SignalExplanation({ signal }: { signal: CompanySignal }) {
  const meaning = signal.score !== null ? SIGNAL_MEANINGS[signal.score] : null
  const details = Object.entries(signal.details ?? {})

  return (
    <div className="flex flex-col gap-2 text-sm">
      <div>
        <span className="text-base font-semibold">{signal.label}</span>
        {signal.score !== null && (
          <span className="ml-2 text-xs text-gray-500 dark:text-gray-400">score {signal.score} sur une échelle de −2 à +2</span>
        )}
      </div>

      {meaning && <p className="text-gray-700 dark:text-gray-300">{meaning}</p>}

      <p className="text-gray-700 dark:text-gray-300">
        <strong>D'où vient ce badge.</strong> L'application calcule plusieurs indicateurs techniques à partir de
        l'historique des cours (moyennes mobiles, RSI, MACD, bandes de Bollinger…). Chacun vote : +1 s'il est
        haussier, −1 s'il est baissier, 0 s'il est neutre. La moyenne de ces votes est ramenée sur une échelle de
        −2 à +2, ce qui donne les cinq libellés : Vente forte, Vente, Neutre, Achat, Achat fort.
        {signal.indicators_used > 0 && ` Ici, ${signal.indicators_used} indicateur${signal.indicators_used > 1 ? 's ont' : ' a'} pu être calculé${signal.indicators_used > 1 ? 's' : ''}.`}
      </p>

      {details.length > 0 && (
        <div>
          <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
            Le vote de chaque indicateur
          </div>
          <ul className="flex flex-col gap-0.5">
            {details.map(([key, d]) => (
              <li key={key} className="flex items-baseline gap-2">
                <span
                  className={`inline-block w-6 shrink-0 text-center text-xs font-semibold ${
                    d.signal > 0
                      ? 'text-emerald-600 dark:text-emerald-400'
                      : d.signal < 0
                        ? 'text-red-600 dark:text-red-400'
                        : 'text-gray-400'
                  }`}
                >
                  {d.signal > 0 ? '+1' : d.signal < 0 ? '−1' : '0'}
                </span>
                <span className="text-gray-700 dark:text-gray-300">{d.reason}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {signal.indicators_used > 0 && signal.indicators_used <= 2 && (
        <p className="rounded-md border border-amber-300 bg-amber-50 px-2 py-1.5 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">
          <strong>
            Signal fragile : {signal.indicators_used} seul{signal.indicators_used > 1 ? 's' : ''} indicateur
            {signal.indicators_used > 1 ? 's' : ''} disponible{signal.indicators_used > 1 ? 's' : ''}.
          </strong>{' '}
          Le libellé reste « {signal.label} » parce qu'il traduit la moyenne des votes, mais avec si peu
          d'indicateurs cette moyenne ne vaut guère plus qu'un avis isolé. Les autres (RSI, MACD, Bollinger…)
          demandent davantage d'historique de cours : ils apparaîtront à mesure que les séances s'accumulent, et le
          signal gagnera alors en fiabilité.
        </p>
      )}

      {signal.confidence_penalized_by_liquidity && (
        <p className="rounded-md border border-amber-300 bg-amber-50 px-2 py-1.5 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">
          <strong>Signal atténué volontairement.</strong> Ce titre est illiquide : son cours peut rester figé faute
          d'acheteur ou de vendeur, ce qui trompe les indicateurs techniques (ils supposent un cours qui reflète
          l'offre et la demande du jour). Le signal a donc été ramené de « fort » à « simple ».
        </p>
      )}

      {signal.liquidity && (
        <p className="text-xs text-gray-500 dark:text-gray-400">
          Liquidité du titre : <strong>{signal.liquidity}</strong>
          {signal.atr_14 !== null && signal.atr_14 !== undefined && ` · agitation récente (ATR) : ${signal.atr_14}`}
        </p>
      )}

      <p className="rounded-md bg-gray-50 px-2 py-1.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">
        <strong>Ce que ce badge n'est pas.</strong> Ce n'est ni un conseil d'achat ou de vente, ni une prévision.
        Il résume uniquement ce que disent des formules appliquées aux cours passés — il ignore complètement les
        résultats de l'entreprise, ses dividendes, l'actualité et le contexte de marché. Un « Vente forte » sur une
        entreprise solide peut simplement signaler un titre qui vient de beaucoup monter et qui souffle.
      </p>
    </div>
  )
}

/**
 * Tuile compacte du bandeau résumé : une valeur, son rang sur le marché, et
 * un clic qui renvoie vers l'onglet détaillé correspondant.
 */
function BannerStat({
  label,
  value,
  rank,
  total,
  tone = 'neutral',
  title,
  onClick,
}: {
  label: string
  value: string
  rank?: number
  total?: number
  tone?: 'neutral' | 'positive' | 'warning' | 'negative'
  title?: string
  onClick?: () => void
}) {
  const valueClass =
    tone === 'positive'
      ? 'text-emerald-600 dark:text-emerald-400'
      : tone === 'negative'
        ? 'text-red-600 dark:text-red-400'
        : tone === 'warning'
          ? 'text-amber-600 dark:text-amber-400'
          : 'text-gray-900 dark:text-gray-100'

  const content = (
    <>
      <div className="text-[11px] uppercase tracking-wide text-gray-400 dark:text-gray-500">{label}</div>
      <div className={`text-lg font-semibold tabular-nums ${valueClass}`}>{value}</div>
      <div className="text-[11px] text-gray-500 dark:text-gray-400">
        {rank !== undefined && total !== undefined ? `${ordinal(rank)} / ${total} du marché` : '—'}
      </div>
    </>
  )

  if (!onClick) {
    return (
      <div className="rounded-md border border-dashed border-gray-200 px-3 py-1 text-right dark:border-gray-800" title={title}>
        {content}
      </div>
    )
  }
  return (
    <button
      type="button"
      onClick={onClick}
      title={title}
      className="rounded-md border border-gray-200 px-3 py-1 text-right hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800"
    >
      {content}
    </button>
  )
}

function verdictBadgeClass(verdict: string | null | undefined) {
  switch (verdict) {
    case 'sous-coté':
      return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
    case 'surcoté':
      return 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
    case 'correctement valorisé':
      return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'
    default:
      return 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'
  }
}

/** Couleurs de remplissage (camembert des verdicts) — mêmes teintes que verdictBadgeClass ci-dessus, en hexadécimal pour Recharts. */
function verdictColor(verdict: string) {
  switch (verdict) {
    case 'sous-coté':
      return '#059669'
    case 'surcoté':
      return '#dc2626'
    case 'correctement valorisé':
      return '#4f46e5'
    default:
      return '#6b7280'
  }
}

function liquidityBadgeClass(liquidity: string | null) {
  switch (liquidity) {
    case 'Élevée':
      return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
    case 'Moyenne':
      return 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
    case 'Faible':
      return 'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300'
    default:
      return 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
  }
}

const ACTION_TYPE_LABELS: Record<string, string> = {
  dividende: 'Dividende',
  augmentation_capital: 'Augm. capital',
  admission: 'Admission',
  assemblee_generale: 'AG',
  autre: 'Autre',
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

function fmt(n: number | string | null | undefined, digits = 2): string {
  if (n === null || n === undefined) return '—'
  const num = typeof n === 'string' ? parseFloat(n) : n
  if (Number.isNaN(num)) return '—'
  return num.toLocaleString('fr-FR', { maximumFractionDigits: digits })
}

function pct(n: number | null | undefined, digits = 2): string {
  if (n === null || n === undefined) return '—'
  return `${n > 0 ? '+' : ''}${n.toFixed(digits)}%`
}

function toneFor(n: number | null | undefined): 'default' | 'positive' | 'negative' {
  if (n === null || n === undefined || n === 0) return 'default'
  return n > 0 ? 'positive' : 'negative'
}

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function daysAgoIso(n: number) {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return d.toISOString().slice(0, 10)
}

const TABS = [
  { id: 'globale', label: 'Analyse IA globale' },
  { id: 'chat', label: 'Assistant IA' },
  { id: 'cours', label: 'Cours' },
  { id: 'flux', label: 'Flux & pression' },
  { id: 'carnet', label: 'Carnet & liquidité' },
  { id: 'fondamentaux', label: 'Fondamentaux' },
  { id: 'evenements', label: 'Événements' },
  { id: 'annonces', label: 'Annonces BRVM' },
  { id: 'dividendes', label: 'Dividendes' },
  { id: 'operations', label: 'Opérations sur titres' },
  { id: 'rapports', label: 'Rapports' },
  { id: 'backtest', label: 'Backtest rapide' },
  { id: 'risque', label: 'Performance & risque' },
  { id: 'classement', label: 'Classement' },
  { id: 'secteur', label: 'Marché & secteur' },
] as const
type TabId = (typeof TABS)[number]['id']

export function CompanyDashboard() {
  const queryClient = useQueryClient()
  const [searchParams, setSearchParams] = useSearchParams()
  const initialSymbol = searchParams.get('symbol')
  // Permet un lien direct vers un onglet précis (ex. depuis la page Fondamentaux : /company?symbol=ABJC&tab=fondamentaux)
  // — appliqué une seule fois au montage, comme initialSymbol ci-dessus.
  const initialTab = searchParams.get('tab')
  const [activeTab, setActiveTab] = useState<TabId>(
    () => (TABS.some((t) => t.id === initialTab) ? (initialTab as TabId) : 'cours'),
  )
  const [showSignalHelp, setShowSignalHelp] = useState(false)
  const [companyId, setCompanyId] = useState<number | null>(null)
  // Valeur de repli le temps que recentTradingDatesQuery ci-dessous réponde
  // (voir l'effet plus bas) — remplacée par les 3 derniers jours de
  // cotation RÉELS dès que connus (voir Quotes.tsx pour le détail : un
  // calcul calendaire "aujourd'hui - N jours" se trompe dès qu'un jour
  // férié tombe dans la fenêtre, constaté en base).
  const [startDate, setStartDate] = useState(daysAgoIso(7))
  const [endDate, setEndDate] = useState(todayIso())
  const [showSma, setShowSma] = useState({ sma_10: true, sma_20: true, sma_50: false })
  const [showVwap, setShowVwap] = useState(false)
  const [intradayStartTime, setIntradayStartTime] = useState('')
  const [intradayEndTime, setIntradayEndTime] = useState('')
  const [backtestRule, setBacktestRule] = useState<'signal_score' | 'golden_cross'>('signal_score')
  const [backtestBuyThreshold, setBacktestBuyThreshold] = useState(1)
  const [backtestSellThreshold, setBacktestSellThreshold] = useState(-1)
  const [backtestFastSma, setBacktestFastSma] = useState('10')
  const [backtestSlowSma, setBacktestSlowSma] = useState('20')
  const [documentTitle, setDocumentTitle] = useState('')
  const [viewDocumentId, setViewDocumentId] = useState<number | null>(null)
  const [showRawDocumentText, setShowRawDocumentText] = useState(false)
  const documentFileInput = useRef<HTMLInputElement | null>(null)
  const [globalAnalysisReportIds, setGlobalAnalysisReportIds] = useState<number[]>([])
  const [globalAnalysisDocumentIds, setGlobalAnalysisDocumentIds] = useState<number[]>([])
  const [compareAnalysisIds, setCompareAnalysisIds] = useState<number[]>([])
  const [includeDocumentsInStats, setIncludeDocumentsInStats] = useState(false)
  // '' = pas de filtre (comportement par défaut : dernier rapport connu) —
  // voir fundamentalsHistoryQuery/displayedFundamentals plus bas.
  const [fundamentalsHistoryYear, setFundamentalsHistoryYear] = useState('')
  const [fundamentalsHistoryReportType, setFundamentalsHistoryReportType] = useState('')
  const [fundamentalsHistoryProvider, setFundamentalsHistoryProvider] = useState('')
  const [editingFinancials, setEditingFinancials] = useState(false)

  const companiesQuery = useQuery({
    queryKey: ['companies-list'],
    queryFn: () => callApi<Company[]>('api_companies.php', 'list', { per_page: 200, active: 1 }),
  })
  const companies = useMemo(
    () => [...(companiesQuery.data ?? [])].sort((a, b) => a.symbol.localeCompare(b.symbol)),
    [companiesQuery.data],
  )

  // Calendrier de bourse réel (marché entier, pas par entreprise) pour fixer
  // par défaut la période sur les 3 derniers jours de cotation — voir
  // api_quotes.php::getRecentTradingDates().
  const recentTradingDatesQuery = useQuery({
    queryKey: ['recent-trading-dates'],
    queryFn: () => callApi<RecentTradingDates>('api_quotes.php', 'recent_trading_dates', { count: 3 }),
  })
  // N'applique la période par défaut qu'une seule fois au chargement — ne
  // doit jamais écraser un choix de date fait ensuite par l'utilisateur.
  const appliedDefaultPeriod = useRef(false)
  useEffect(() => {
    if (!appliedDefaultPeriod.current && recentTradingDatesQuery.data?.start_date) {
      setStartDate(recentTradingDatesQuery.data.start_date)
      setEndDate(recentTradingDatesQuery.data.end_date ?? todayIso())
      appliedDefaultPeriod.current = true
    }
  }, [recentTradingDatesQuery.data])

  // Sélection par défaut dès que la liste des entreprises est chargée — une
  // seule fois, pour ne pas reforcer la sélection si l'utilisateur en choisit
  // une autre ensuite dans le Select ci-dessous. Priorité : entreprise passée
  // dans l'URL (?symbol=XXX, ex: lien depuis une autre page) > dernière
  // entreprise consultée (localStorage) > première entreprise de la liste.
  useMemo(() => {
    if (companyId === null && companies.length > 0) {
      const lastViewed = localStorage.getItem('brvm_dashboard_last_company')
      const match =
        (initialSymbol && companies.find((c) => c.symbol === initialSymbol)) ||
        (lastViewed && companies.find((c) => c.symbol === lastViewed)) ||
        companies[0]
      setCompanyId(match.company_id)
    }
  }, [companies, companyId, initialSymbol])

  const selectedCompany = companies.find((c) => c.company_id === companyId) ?? null

  function selectCompany(id: number | null) {
    setCompanyId(id)
    const company = companies.find((c) => c.company_id === id)
    if (company) localStorage.setItem('brvm_dashboard_last_company', company.symbol)
    setSearchParams(company ? { symbol: company.symbol } : {}, { replace: true })
    setGlobalAnalysisReportIds([])
    setGlobalAnalysisDocumentIds([])
    setCompareAnalysisIds([])
    setFundamentalsHistoryYear('')
    setFundamentalsHistoryReportType('')
    setFundamentalsHistoryProvider('')
    setEditingFinancials(false)
  }

  const signalQuery = useQuery({
    queryKey: ['dashboard-signal', companyId],
    queryFn: () => callApi<CompanySignal>('api_signals.php', 'get', { company_id: companyId }),
    enabled: !!companyId,
    retry: false,
  })

  // Rendement du dividende et rang sur le marché, affichés dans le bandeau
  // résumé. Même clé de cache que l'onglet Dividendes (CompanyDividends) :
  // le classement complet du marché n'est donc chargé qu'une seule fois.
  const dividendRankingQuery = useQuery({
    queryKey: ['dividend-ranking', 24],
    queryFn: () => callApi<DividendRankingResult>('api_dividends.php', 'ranking', { months: 24 }),
  })
  const dividendStanding = useMemo(() => {
    const rows = (dividendRankingQuery.data?.rows ?? []).filter((r) => r.yield_percent !== null)
    const index = rows.findIndex((r) => r.company_id === companyId)
    if (index < 0) return null
    return { row: rows[index], rank: index + 1, total: rows.length }
  }, [dividendRankingQuery.data, companyId])

  // Carnet & liquidité du marché entier, pour situer l'entreprise dans le
  // bandeau. Période volontairement indépendante du sélecteur de dates de la
  // page : le bandeau doit rester stable quand on change la fenêtre des
  // graphes (le score de liquidité est de toute façon calculé sur 45-90 j,
  // et les titres en vente viennent du dernier carnet publié).
  const liquidityRankingQuery = useQuery({
    queryKey: ['ob-ranking-banner'],
    queryFn: () => callApi<LiquidityRankingResult>('api_order_book.php', 'ranking', {}),
  })

  /** Rang de l'entreprise sur un critère, en ignorant les non-classables. */
  const liquidityStanding = useMemo(
    () => rankOn(liquidityRankingQuery.data?.rows ?? [], companyId, (r) => r.liquidity_score),
    [liquidityRankingQuery.data, companyId],
  )
  const forSaleStanding = useMemo(
    () => rankOn(liquidityRankingQuery.data?.rows ?? [], companyId, (r) => r.last_ask_qty),
    [liquidityRankingQuery.data, companyId],
  )
  const tradedStanding = useMemo(
    () => rankOn(liquidityRankingQuery.data?.rows ?? [], companyId, (r) => r.last_session_volume),
    [liquidityRankingQuery.data, companyId],
  )
  const myLiquidityRow = useMemo(
    () => (liquidityRankingQuery.data?.rows ?? []).find((r) => r.company_id === companyId) ?? null,
    [liquidityRankingQuery.data, companyId],
  )

  // Performance et volatilité de TOUTES les entreprises sur la période
  // choisie, pour afficher la valeur de l'entreprise ET son rang dans le
  // bandeau. Contrairement au rendement et à la liquidité (fenêtres fixes),
  // ces deux chiffres SUIVENT le sélecteur de dates de la page — c'est le
  // sens même de « sur la période ».
  // Note : `net_return_percent` est exactement le même calcul que la
  // « performance » de l'écran Classements — (dernier cours − premier
  // cours) / premier cours — d'où une seule tuile et non deux.
  const marketPerfQuery = useQuery({
    queryKey: ['dashboard-perf-market', startDate, endDate, companies.length],
    queryFn: () =>
      callApi<RiskAdjustedResult[]>('api_quotes.php', 'risk_adjusted', {
        company_ids: companies.map((c) => c.company_id),
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: companies.length > 0 && startDate <= endDate,
  })

  const perfStanding = useMemo(
    () => rankOnRows(marketPerfQuery.data ?? [], companyId, (r) => r.net_return_percent),
    [marketPerfQuery.data, companyId],
  )
  const volatilityStanding = useMemo(
    () => rankOnRows(marketPerfQuery.data ?? [], companyId, (r) => r.total_volatility_percent),
    [marketPerfQuery.data, companyId],
  )

  const ohlcQuery = useQuery({
    queryKey: ['dashboard-ohlc', companyId, startDate, endDate],
    queryFn: () =>
      callApi<OhlcPoint[]>('api_quotes.php', 'ohlc', { company_id: companyId, start_date: startDate, end_date: endDate }),
    enabled: !!companyId && startDate <= endDate,
  })

  const indicatorsQuery = useQuery({
    queryKey: ['dashboard-indicators', companyId, startDate, endDate],
    queryFn: () =>
      callApi<TechnicalIndicatorPoint[]>('api_technical_indicators.php', 'get_indicators', {
        company_id: companyId,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!companyId && startDate <= endDate,
  })

  const crossoversQuery = useQuery({
    queryKey: ['dashboard-crossovers', companyId, startDate, endDate],
    queryFn: () =>
      callApi<MaCrossoverEvent[]>('api_signals.php', 'crossovers', {
        company_id: companyId,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!companyId && startDate <= endDate,
  })

  const divergenceQuery = useQuery({
    queryKey: ['dashboard-rsi-divergence', companyId, startDate, endDate],
    queryFn: () =>
      callApi<RsiDivergenceEvent[]>('api_signals.php', 'divergence', {
        company_id: companyId,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!companyId && startDate <= endDate,
  })

  // Relevés intrajournaliers — voir Quotes.tsx pour le détail du principe
  // (un point par synchro, séances qui se suivent sur plusieurs jours), même
  // logique de rafraîchissement automatique quand la période affichée
  // inclut aujourd'hui. Même période que les autres sections (startDate/
  // endDate, unique sélecteur en haut de page).
  const intradaySingleDay = startDate === endDate
  const intradayIsLive = endDate === todayIso()
  const intradayQuery = useQuery({
    queryKey: ['dashboard-intraday', companyId, startDate, endDate],
    queryFn: () =>
      callApi<CompanyPriceSeries[]>('api_quotes.php', 'compare', {
        company_ids: [companyId],
        granularity: 'intraday',
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!companyId && startDate <= endDate,
    refetchInterval: intradayIsLive ? 60_000 : false,
  })

  // Pas de filtre company_id côté backend pour cet endpoint (voir
  // Fundamentals.tsx) — on récupère la liste complète une seule fois
  // (partagée entre toutes les entreprises visitées grâce au cache
  // react-query) et on cherche la ligne de l'entreprise sélectionnée.
  const fundamentalsQuery = useQuery({
    queryKey: ['fundamentals-list'],
    queryFn: () => callApi<FundamentalsRow[]>('api_fundamentals.php', 'list', {}),
  })
  const fundamentals = fundamentalsQuery.data?.find((r) => r.company_id === companyId) ?? null

  // Tout l'historique d'analyses réussies de cette entreprise (pas
  // seulement son dernier rapport) — alimente les filtres "Année"/"Type de
  // rapport" ci-dessous pour consulter un exercice/rapport spécifique.
  // Chargé seulement quand l'onglet Fondamentaux est ouvert.
  const fundamentalsHistoryQuery = useQuery({
    queryKey: ['fundamentals-history', companyId],
    queryFn: () => callApi<FundamentalsRow[]>('api_fundamentals.php', 'history', { company_id: companyId }),
    enabled: !!companyId && activeTab === 'fondamentaux',
  })
  const fundamentalsHistory = fundamentalsHistoryQuery.data ?? []
  const fundamentalsHistoryYears = useMemo(
    () =>
      Array.from(new Set(fundamentalsHistory.map((r) => (r.source_publish_date ? Number(r.source_publish_date.slice(0, 4)) : null))))
        .filter((y): y is number => y !== null)
        .sort((a, b) => b - a),
    [fundamentalsHistory],
  )
  const fundamentalsHistoryReportTypes = useMemo(
    () => Array.from(new Set(fundamentalsHistory.map((r) => r.source_report_type))).sort(),
    [fundamentalsHistory],
  )
  // Un même rapport peut être analysé par plusieurs IA (fournisseur+modèle) — ce
  // filtre permet de choisir laquelle afficher, notamment pour comparer leurs
  // extractions sur un même rapport. Clé composite "provider::model" car les deux
  // vont toujours ensemble (un provider seul ne désigne pas une analyse précise).
  const fundamentalsHistoryProviders = useMemo(
    () =>
      Array.from(new Set(fundamentalsHistory.filter((r) => r.source_provider && r.source_model).map((r) => `${r.source_provider}::${r.source_model}`))).sort(),
    [fundamentalsHistory],
  )
  // Le plus récent rapport correspondant aux filtres (fundamentalsHistory
  // est déjà trié publish_date DESC par le backend) ; sans filtre actif, on
  // reste sur le dernier rapport connu (fundamentals) — comportement par défaut inchangé.
  const filteredFundamentalsHistory = fundamentalsHistory.filter(
    (r) =>
      (fundamentalsHistoryYear === '' || r.source_publish_date?.slice(0, 4) === fundamentalsHistoryYear) &&
      (fundamentalsHistoryReportType === '' || r.source_report_type === fundamentalsHistoryReportType) &&
      (fundamentalsHistoryProvider === '' || `${r.source_provider}::${r.source_model}` === fundamentalsHistoryProvider),
  )
  const historyFilterActive = fundamentalsHistoryYear !== '' || fundamentalsHistoryReportType !== '' || fundamentalsHistoryProvider !== ''
  const displayedFundamentals = historyFilterActive ? (filteredFundamentalsHistory[0] ?? null) : fundamentals

  // Chargé seulement quand l'onglet Fondamentaux est ouvert (pas à chaque
  // visite du tableau de bord) — implique un calcul de bêta sur l'historique
  // de cours, plus coûteux que le reste des données déjà chargées. Recalculé
  // quand les filtres Année/Type changent : le DCF/DDM doit refléter les
  // chiffres du rapport consulté, pas toujours le dernier. Voir
  // FundamentalsDetailPanel (composant partagé avec la page Fondamentaux).
  const valuationQuery = useQuery({
    queryKey: ['company-valuation', companyId, displayedFundamentals?.source_report_id],
    queryFn: () => callApi<ValuationModel>('api_valuation.php', 'compute', { company_id: companyId, fundamentals: displayedFundamentals }),
    enabled: !!companyId && displayedFundamentals !== null && activeTab === 'fondamentaux',
  })

  // Pré-remplit le formulaire "Modifier" avec les chiffres BRUTS (key_financials/valuation_assessment tels
  // qu'extraits par l'IA) de l'analyse EXACTEMENT affichée — pas juste "la dernière" comme sur la page Rapports :
  // ici l'utilisateur peut avoir choisi un rapport/IA précis via les filtres Année/Type/IA ci-dessus, la
  // correction doit porter sur CETTE analyse-là, sinon elle porterait sur une autre sans que ce soit visible.
  const editFinancialsQuery = useQuery({
    queryKey: ['report-analysis-exact', displayedFundamentals?.source_report_id, displayedFundamentals?.source_provider, displayedFundamentals?.source_model],
    queryFn: () =>
      callApi<ReportAnalysis | null>('api_report_analysis.php', 'get', {
        report_id: displayedFundamentals?.source_report_id,
        provider: displayedFundamentals?.source_provider,
        model: displayedFundamentals?.source_model,
      }),
    enabled: editingFinancials && displayedFundamentals !== null,
  })

  const corporateActionsQuery = useQuery({
    queryKey: ['dashboard-corporate-actions', companyId],
    queryFn: () => callApi<CorporateActionsListResult>('api_bulletin_corporate_actions.php', 'list', { company_id: companyId }),
    enabled: !!companyId,
  })

  const reportsQuery = useQuery({
    queryKey: ['dashboard-reports', selectedCompany?.symbol],
    queryFn: () => callApi<ReportSummary[]>('api_reports.php', 'list', { symbol: selectedCompany?.symbol }),
    enabled: !!selectedCompany,
  })

  // Recalculé côté backend à chaque appel à partir des analyses déjà en
  // base (pas de cache dédié) — reflète automatiquement tout nouveau
  // rapport analysé entre deux visites de cet onglet, sans changement de
  // code (voir ReportAnalysisService::getCompanyAnalysisStats()).
  const reportAnalysisStatsQuery = useQuery({
    queryKey: ['dashboard-report-analysis-stats', companyId, includeDocumentsInStats],
    queryFn: () =>
      callApi<ReportAnalysisStats>('api_report_analysis.php', 'stats', {
        company_id: companyId,
        include_documents: includeDocumentsInStats,
      }),
    enabled: !!companyId,
  })

  // Documents ajoutés manuellement (voir api_company_documents.php) — pas
  // scrapés depuis brvm.org, servent de ressource additionnelle pour l'IA.
  const companyDocumentsQuery = useQuery({
    queryKey: ['dashboard-company-documents', companyId],
    queryFn: () => callApi<CompanyDocumentSummary[]>('api_company_documents.php', 'list', { company_id: companyId }),
    enabled: !!companyId,
  })

  // Historique complet des analyses IA globales de cette entreprise, TOUTES
  // sélections confondues (dates, rapports/documents cochés) — contrairement
  // à l'historique interne de ChartAiAnalysis (scopé à la sélection EXACTE
  // courante, donc quasi toujours vide dès que la période ou les rapports
  // cochés changent), nécessaire ici pour comparer des analyses faites à des
  // moments différents (voir api_chart_analysis.php, action
  // 'list_by_company').
  const globalHistoryQuery = useQuery({
    queryKey: ['dashboard-global-analysis-history', companyId],
    queryFn: () => callApi<ChartAnalysisResult[]>('api_chart_analysis.php', 'list_by_company', { chart_type: 'company_dashboard', company_id: companyId }),
    enabled: !!companyId && activeTab === 'globale',
  })

  const documentDetailQuery = useQuery({
    queryKey: ['dashboard-company-document-detail', viewDocumentId],
    queryFn: () => callApi<CompanyDocumentDetail>('api_company_documents.php', 'get', { id: viewDocumentId }),
    enabled: viewDocumentId !== null,
    refetchInterval: (query) => (query.state.data?.markdown_status === 'processing' ? 4000 : false),
  })

  const uploadDocumentMutation = useMutation({
    mutationFn: ({ file, title, companyId }: { file: File; title: string; companyId: number }) =>
      uploadFile<{ id: number; status: string }>('api_company_documents.php', 'upload', file, { title, company_id: companyId }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['dashboard-company-documents', companyId] })
      setDocumentTitle('')
      if (documentFileInput.current) documentFileInput.current.value = ''
    },
  })

  const formatDocumentMarkdownMutation = useMutation({
    mutationFn: (id: number) => callApi<{ id: number; status: string }>('api_company_documents.php', 'format_markdown', { id }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['dashboard-company-document-detail', viewDocumentId] })
    },
  })

  // Dernière analyse IA en cache pour le document actuellement consulté
  // (aucun appel IA ici, juste une lecture) — voir CompanyDocumentAnalysisService::getLatest().
  const documentAnalysisQuery = useQuery({
    queryKey: ['dashboard-document-analysis', viewDocumentId],
    queryFn: () => callApi<CompanyDocumentAnalysis | null>('api_company_document_analysis.php', 'get', { document_id: viewDocumentId }),
    enabled: viewDocumentId !== null,
  })

  const analyzeDocumentMutation = useMutation({
    mutationFn: ({ id, forceRefresh }: { id: number; forceRefresh: boolean }) =>
      callApi<CompanyDocumentAnalysis>('api_company_document_analysis.php', 'analyze', { document_id: id, force_refresh: forceRefresh }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['dashboard-document-analysis', viewDocumentId] })
      queryClient.invalidateQueries({ queryKey: ['dashboard-company-documents', companyId] })
      queryClient.invalidateQueries({ queryKey: ['dashboard-report-analysis-stats', companyId] })
    },
  })

  const deleteDocumentMutation = useMutation({
    mutationFn: (id: number) => callApi('api_company_documents.php', 'delete', { id }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['dashboard-company-documents', companyId] })
      setViewDocumentId(null)
    },
  })

  function handleDocumentUpload() {
    const file = documentFileInput.current?.files?.[0]
    if (!companyId || !documentTitle.trim() || !file) return
    uploadDocumentMutation.mutate({ file, title: documentTitle.trim(), companyId })
  }

  // Réglages par défaut (Signal composite, seuils standards) modifiables
  // directement dans l'onglet — voir la page Backtesting complète pour la
  // règle golden_cross avec d'autres périodes de SMA si besoin.
  const backtestParams =
    backtestRule === 'signal_score'
      ? { buy_threshold: backtestBuyThreshold, sell_threshold: backtestSellThreshold }
      : { fast_sma: backtestFastSma, slow_sma: backtestSlowSma }

  const backtestQuery = useQuery({
    queryKey: ['dashboard-backtest', companyId, startDate, endDate, backtestRule, backtestParams],
    queryFn: () =>
      callApi<BacktestResult>('api_backtest.php', 'run', {
        company_id: companyId,
        rule: backtestRule,
        start_date: startDate,
        end_date: endDate,
        ...backtestParams,
      }),
    enabled: !!companyId && startDate <= endDate && (backtestRule !== 'golden_cross' || backtestFastSma !== backtestSlowSma),
  })

  const riskAdjustedQuery = useQuery({
    queryKey: ['dashboard-risk-adjusted', companyId, startDate, endDate],
    queryFn: () =>
      callApi<RiskAdjustedResult[]>('api_quotes.php', 'risk_adjusted', {
        company_ids: [companyId],
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!companyId && startDate <= endDate,
  })

  const relativeStrengthQuery = useQuery({
    queryKey: ['dashboard-relative-strength', companyId, startDate, endDate],
    queryFn: () =>
      callApi<RelativeStrengthSeries[]>('api_quotes.php', 'relative_strength', {
        company_ids: [companyId],
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!companyId && startDate <= endDate,
  })

  const totalVariationQuery = useQuery({
    queryKey: ['dashboard-total-variation', companyId, startDate, endDate],
    queryFn: () =>
      callApi<TotalVariationSeries[]>('api_quotes.php', 'total_variation', {
        company_ids: [companyId],
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!companyId && startDate <= endDate,
  })

  const riskMetricsQuery = useQuery({
    queryKey: ['dashboard-risk-metrics', companyId, startDate, endDate],
    queryFn: () =>
      callApi<RiskMetricsRow[]>('api_risk_metrics.php', 'compute', {
        company_ids: [companyId],
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!companyId && startDate <= endDate,
  })

  // Pas de filtre company_id côté backend (voir Screener.tsx) — on récupère
  // le classement complet du marché et on retrouve la ligne de l'entreprise
  // sélectionnée, plutôt que de dupliquer la logique de scoring côté front.
  const screenerQuery = useQuery({
    queryKey: ['dashboard-screener', startDate, endDate],
    queryFn: () => callApi<ScreenerRow[]>('api_screener.php', 'screen', { start_date: startDate, end_date: endDate }),
    enabled: !!companyId && startDate <= endDate,
  })

  const liquidityHistoryQuery = useQuery({
    queryKey: ['dashboard-liquidity-history', companyId, startDate, endDate],
    queryFn: () =>
      callApi<LiquidityHistorySeries[]>('api_quotes.php', 'liquidity_history', {
        company_ids: [companyId],
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!companyId && startDate <= endDate,
  })

  const sectorPerformanceQuery = useQuery({
    queryKey: ['dashboard-sector-performance', startDate, endDate],
    queryFn: () =>
      callApi<SectorPerformanceSeries[]>('api_quotes.php', 'sector_performance', { start_date: startDate, end_date: endDate }),
    enabled: !!companyId && startDate <= endDate,
  })

  const marketBreadthQuery = useQuery({
    queryKey: ['dashboard-market-breadth', endDate],
    queryFn: () => callApi<MarketBreadthPoint[]>('api_quotes.php', 'market_breadth', { end_date: endDate, days: 30 }),
    enabled: !!companyId,
  })

  // Contrôles qualité marché entier (pas de filtre company_id côté backend,
  // voir MarketHealth.tsx) — on filtre côté front sur cette seule entreprise.
  const reconciliationQuery = useQuery({
    queryKey: ['dashboard-data-quality-reconciliation'],
    queryFn: () => callApi<ReconciliationIssue[]>('api_data_quality.php', 'reconciliation', { days: 30 }),
    enabled: !!companyId,
  })

  const priceJumpsQuery = useQuery({
    queryKey: ['dashboard-data-quality-price-jumps'],
    queryFn: () => callApi<PriceJumpIssue[]>('api_data_quality.php', 'price_jumps', { days: 7 }),
    enabled: !!companyId,
  })

  const missingDaysQuery = useQuery({
    queryKey: ['dashboard-data-quality-missing-days'],
    queryFn: () => callApi<MissingDaysIssue[]>('api_data_quality.php', 'missing_days', { days: 30 }),
    enabled: !!companyId,
  })

  const priceWithSma = useMemo(() => {
    const indicatorsByDate = new Map((indicatorsQuery.data ?? []).map((i) => [i.trading_date, i]))
    return (ohlcQuery.data ?? []).map((p) => {
      const ind = indicatorsByDate.get(p.date)
      return {
        ...p,
        sma_10: ind?.sma_10 != null ? Number(ind.sma_10) : undefined,
        sma_20: ind?.sma_20 != null ? Number(ind.sma_20) : undefined,
        sma_50: ind?.sma_50 != null ? Number(ind.sma_50) : undefined,
        vwap: ind?.vwap != null ? Number(ind.vwap) : undefined,
      }
    })
  }, [ohlcQuery.data, indicatorsQuery.data])

  const advancedIndicatorsData = useMemo(
    () =>
      (indicatorsQuery.data ?? []).map((i) => ({
        date: i.trading_date,
        adx_14: i.adx_14 != null ? Number(i.adx_14) : null,
        stoch_k: i.stoch_k != null ? Number(i.stoch_k) : null,
        stoch_d: i.stoch_d != null ? Number(i.stoch_d) : null,
        roc_12: i.roc_12 != null ? Number(i.roc_12) : null,
        obv: i.obv != null ? Number(i.obv) : null,
        rsi_14: i.rsi_14 != null ? Number(i.rsi_14) : null,
      })),
    [indicatorsQuery.data],
  )
  const hasAdvancedIndicator = (key: 'adx_14' | 'stoch_k' | 'roc_12' | 'obv' | 'rsi_14') =>
    advancedIndicatorsData.some((d) => d[key] !== null)

  // timeOfDay (HH:MM, toujours extrait des mêmes positions du datetime
  // complet) sert au filtre heure début/fin ci-dessous, indépendamment de
  // "time" (le libellé affiché, qui inclut la date en mode multi-jours).
  const intradayDataAll = (intradayQuery.data?.[0]?.data ?? []).map((p, i, arr) => {
    const timeOfDay = String(p.date).slice(11, 16)
    // Volume CUMULÉ depuis l'ouverture (c'est ainsi que brvm.org le publie)
    // et titres réellement échangés depuis le relevé précédent. Le delta est
    // laissé à null au changement de journée et sur une baisse du cumul :
    // avant ~9h10 la page affiche encore la séance de la VEILLE, un delta
    // négatif ne signifie donc rien (même garde que ExecutionFlowBuilder).
    const prev = arr[i - 1]
    let volumeDelta: number | null = null
    if (
      prev &&
      p.volume !== null && p.volume !== undefined &&
      prev.volume !== null && prev.volume !== undefined &&
      String(prev.date).slice(0, 10) === String(p.date).slice(0, 10)
    ) {
      const delta = Number(p.volume) - Number(prev.volume)
      volumeDelta = delta >= 0 ? delta : null
    }
    return {
      time: intradaySingleDay ? timeOfDay : String(p.date).slice(0, 16),
      timeOfDay,
      price: Number(p.price),
      variation_percent: p.variation !== null ? Number(p.variation) : null,
      volume_cumulative: p.volume !== null && p.volume !== undefined ? Number(p.volume) : null,
      volume_delta: volumeDelta,
    }
  })
  const intradayData = intradayDataAll.filter(
    (p) => (!intradayStartTime || p.timeOfDay >= intradayStartTime) && (!intradayEndTime || p.timeOfDay <= intradayEndTime),
  )

  const riskAdjusted = riskAdjustedQuery.data?.[0] ?? null
  const relativeStrength = relativeStrengthQuery.data?.[0]?.data ?? []
  const corporateActions = corporateActionsQuery.data?.actions ?? []
  const allReports = reportsQuery.data ?? []
  const totalVariation = totalVariationQuery.data?.[0]?.data ?? []
  const riskMetrics = riskMetricsQuery.data?.[0] ?? null
  const screenerRow = screenerQuery.data?.find((r) => r.company_id === companyId) ?? null
  const liquidityHistory = liquidityHistoryQuery.data?.[0]?.data ?? []
  const companySectorSeries = sectorPerformanceQuery.data?.find((s) => s.sector_id === selectedCompany?.sector_id) ?? null
  const companyReconciliation = (reconciliationQuery.data ?? []).filter((r) => r.symbol === selectedCompany?.symbol)
  const companyPriceJumps = (priceJumpsQuery.data ?? []).filter((p) => p.symbol === selectedCompany?.symbol)
  const companyMissingDays = (missingDaysQuery.data ?? []).find((m) => m.company_id === companyId) ?? null

  // Agrégat de toutes les données déjà chargées par les onglets du tableau
  // de bord pour cette entreprise — envoyé tel quel à l'analyse IA globale
  // (voir ChartAnalysisService, chart_type 'company_dashboard') plutôt que
  // de recalculer quoi que ce soit côté backend. Bornée à un sous-ensemble
  // récent de l'historique de cours (60 derniers points) pour ne pas
  // gonfler inutilement le prompt même sur une période très longue.
  const globalAnalysisData = {
    company: selectedCompany ? { symbol: selectedCompany.symbol, name: selectedCompany.name, sector: selectedCompany.sector_name ?? null } : null,
    period: { start_date: startDate, end_date: endDate },
    signal: signalQuery.data ?? null,
    recent_price_history: priceWithSma.slice(-60),
    ma_crossovers: crossoversQuery.data ?? [],
    fundamentals: fundamentals,
    corporate_actions: corporateActions,
    backtest_default_rule: backtestQuery.data
      ? {
          rule: backtestQuery.data.rule,
          total_trades: backtestQuery.data.total_trades,
          win_rate_percent: backtestQuery.data.win_rate_percent,
          strategy_return_percent: backtestQuery.data.strategy_return_percent,
          buy_hold_return_percent: backtestQuery.data.buy_hold_return_percent,
          short_return_percent: backtestQuery.data.short_return_percent,
          short_total_trades: backtestQuery.data.short_total_trades,
          short_win_rate_percent: backtestQuery.data.short_win_rate_percent,
          insufficient_history: backtestQuery.data.insufficient_history,
        }
      : null,
    total_variation: totalVariation.slice(-60),
    risk_adjusted: riskAdjusted,
    relative_strength_recent: relativeStrength.slice(-60),
    risk_metrics_advanced: riskMetrics,
    screener_ranking: screenerRow,
    liquidity_history_recent: liquidityHistory.slice(-60),
    sector_performance: companySectorSeries ? { sector_name: companySectorSeries.sector_name, recent_data: companySectorSeries.data.slice(-60) } : null,
    market_breadth_recent: (marketBreadthQuery.data ?? []).slice(-30),
    data_quality_flags: {
      reconciliation_issues: companyReconciliation,
      price_jump_issues: companyPriceJumps,
      missing_days: companyMissingDays,
    },
    reports_available_count: allReports.length,
    documents_available_count: companyDocumentsQuery.data?.length ?? 0,
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Tableau de bord entreprise</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Vue consolidée d'une entreprise sélectionnée : cours, fondamentaux, opérations sur titres, rapports,
          backtest, performance/risque, classement et contexte sectoriel, réunis sur un seul écran.
        </p>
      </div>

      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <label className="flex-1 min-w-[240px]">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Entreprise</span>
            <SearchableSelect
              value={companyId !== null ? String(companyId) : ''}
              onChange={(v) => selectCompany(v ? Number(v) : null)}
              options={companies.map((c) => ({ value: String(c.company_id), label: `${c.symbol} — ${c.name}` }))}
            />
          </label>
          <label className="w-40">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de début</span>
            <Input type="date" value={startDate} max={endDate} onChange={(e) => setStartDate(e.target.value)} />
          </label>
          <label className="w-40">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de fin</span>
            <Input type="date" value={endDate} min={startDate} max={todayIso()} onChange={(e) => setEndDate(e.target.value)} />
          </label>
        </div>
        {startDate > endDate && (
          <p className="mt-2 text-xs text-red-600 dark:text-red-400">La date de début doit précéder la date de fin.</p>
        )}
      </Card>

      {!companyId && <p className="text-sm text-gray-500 dark:text-gray-400">Sélectionne une entreprise pour afficher son tableau de bord.</p>}

      {companyId && (
        <>
          {/* Bandeau résumé — toujours visible quel que soit l'onglet actif. */}
          <div className="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div>
              <div className="text-lg font-semibold">
                {selectedCompany?.symbol} <span className="font-normal text-gray-500 dark:text-gray-400">— {selectedCompany?.name}</span>
              </div>
              {selectedCompany?.sector_name && (
                <div className="text-xs text-gray-500 dark:text-gray-400">{selectedCompany.sector_name}</div>
              )}
            </div>
            <div className="ml-auto flex flex-wrap items-center justify-end gap-4">
              {/* Rendement du dividende + rang sur le marché (voir onglet
                  Dividendes pour le détail des versements). */}
              {dividendStanding ? (
                <BannerStat
                  label="Rendement"
                  value={`${dividendStanding.row.yield_percent}%`}
                  rank={dividendStanding.rank}
                  total={dividendStanding.total}
                  tone="positive"
                  title={`Dernier dividende : ${dividendStanding.row.last_amount} FCFA par action, versé le ${dividendStanding.row.last_date}. Cliquer pour voir le détail.`}
                  onClick={() => setActiveTab('dividendes')}
                />
              ) : (
                dividendRankingQuery.data && (
                  <div
                    className="rounded-md border border-dashed border-gray-200 px-3 py-1 text-right dark:border-gray-800"
                    title="Aucun dividende trouvé dans les bulletins déjà analysés — ce qui ne prouve pas que l'entreprise n'en verse pas."
                  >
                    <div className="text-[11px] uppercase tracking-wide text-gray-400 dark:text-gray-500">Rendement</div>
                    <div className="text-lg font-semibold tabular-nums text-gray-400 dark:text-gray-500">—</div>
                    <div className="text-[11px] text-gray-500 dark:text-gray-400">dividende non connu</div>
                  </div>
                )
              )}

              {/* Titres ÉCHANGÉS lors de la dernière séance : des transactions
                  CONCLUES. À ne pas confondre avec « Titres en vente »
                  ci-dessous, qui compte des ordres encore EN ATTENTE et
                  provient d'un carnet publié à une autre date — d'où la date
                  affichée dans les deux libellés. */}
              {tradedStanding && (
                <BannerStat
                  label={`Titres échangés${myLiquidityRow?.last_session_date ? ` (${frDate(myLiquidityRow.last_session_date)})` : ''}`}
                  value={new Intl.NumberFormat('fr-FR').format(tradedStanding.value)}
                  rank={tradedStanding.rank}
                  total={tradedStanding.total}
                  tone="neutral"
                  title={`Actions qui ont RÉELLEMENT changé de mains lors de la séance du ${myLiquidityRow?.last_session_date ?? '—'} : des transactions conclues. Rang 1 = le titre le plus échangé du marché. À ne pas confondre avec « Titres en vente », qui compte des ordres encore en attente. Cliquer pour ouvrir Flux & pression.`}
                  onClick={() => setActiveTab('flux')}
                />
              )}

              {/* Titres proposés à la vente au dernier carnet publié : la file
                  de vendeurs devant vous si vous vouliez vendre. Rang 1 = le
                  plus de titres en attente (ton neutre : ce n'est ni bon ni
                  mauvais en soi, cela dépend si l'on achète ou si l'on vend). */}
              {forSaleStanding ? (
                <BannerStat
                  label={`Titres en vente${myLiquidityRow?.last_book_date ? ` (${frDate(myLiquidityRow.last_book_date)})` : ''}`}
                  value={new Intl.NumberFormat('fr-FR').format(forSaleStanding.value)}
                  rank={forSaleStanding.rank}
                  total={forSaleStanding.total}
                  tone="neutral"
                  title={`Actions qui ATTENDENT un acheteur au meilleur prix de vente, d'après le carnet d'ordres publié le soir du ${myLiquidityRow?.last_book_date ?? '—'} : des intentions de vente, pas des transactions. C'est la file de vendeurs devant vous si vous vouliez vendre. Rang 1 = la plus grosse file du marché. Cliquer pour ouvrir le carnet.`}
                  onClick={() => setActiveTab('carnet')}
                />
              ) : (
                liquidityRankingQuery.data && (
                  <div
                    className="rounded-md border border-dashed border-gray-200 px-3 py-1 text-right dark:border-gray-800"
                    title="Aucun vendeur en attente au dernier carnet publié, ou aucun carnet disponible pour ce titre."
                  >
                    <div className="text-[11px] uppercase tracking-wide text-gray-400 dark:text-gray-500">Titres en vente</div>
                    <div className="text-lg font-semibold tabular-nums text-gray-400 dark:text-gray-500">—</div>
                    <div className="text-[11px] text-gray-500 dark:text-gray-400">aucun vendeur en attente</div>
                  </div>
                )
              )}

              {/* Performance et volatilité SUR LA PÉRIODE choisie en haut de
                  page — elles changent donc avec le sélecteur de dates,
                  contrairement au rendement et à la liquidité. */}
              {perfStanding && (
                <BannerStat
                  label="Perf. période"
                  value={`${perfStanding.value > 0 ? '+' : ''}${perfStanding.value}%`}
                  rank={perfStanding.rank}
                  total={perfStanding.total}
                  tone={perfStanding.value > 0 ? 'positive' : perfStanding.value < 0 ? 'negative' : 'neutral'}
                  title={`Évolution du cours du ${startDate} au ${endDate} : (dernier cours − premier cours) ÷ premier cours. Rang 1 = la meilleure performance du marché sur cette même période. Change avec le sélecteur de dates ci-dessus. Cliquer pour ouvrir Performance & risque.`}
                  onClick={() => setActiveTab('risque')}
                />
              )}

              {volatilityStanding && (
                <BannerStat
                  label="Volatilité"
                  value={`${volatilityStanding.value}%`}
                  rank={volatilityStanding.rank}
                  total={volatilityStanding.total}
                  tone={volatilityStanding.rank <= Math.ceil(volatilityStanding.total / 3) ? 'warning' : 'neutral'}
                  title={`Volatilité totale sur la période : somme des variations de cours enregistrées séance après séance, du ${startDate} au ${endDate}. Mesure l'agitation du titre, pas sa direction. Rang 1 = le titre le plus agité du marché. Cliquer pour ouvrir Performance & risque.`}
                  onClick={() => setActiveTab('risque')}
                />
              )}

              {liquidityStanding ? (
                <BannerStat
                  label="Liquidité"
                  value={`${liquidityStanding.value}/100`}
                  rank={liquidityStanding.rank}
                  total={liquidityStanding.total}
                  tone={liquidityStanding.value >= 70 ? 'positive' : liquidityStanding.value >= 45 ? 'warning' : 'neutral'}
                  title={`Facilité à revendre ce titre sans attendre ni brader (score estimé 0-100, couverture ${myLiquidityRow?.coverage_percent ?? '—'}%). Rang 1 = le titre le plus liquide du marché. Cliquer pour ouvrir le détail.`}
                  onClick={() => setActiveTab('carnet')}
                />
              ) : (
                liquidityRankingQuery.data && (
                  <div
                    className="rounded-md border border-dashed border-gray-200 px-3 py-1 text-right dark:border-gray-800"
                    title="Pas assez d'historique récent pour calculer un score de liquidité pour ce titre."
                  >
                    <div className="text-[11px] uppercase tracking-wide text-gray-400 dark:text-gray-500">Liquidité</div>
                    <div className="text-lg font-semibold tabular-nums text-gray-400 dark:text-gray-500">—</div>
                    <div className="text-[11px] text-gray-500 dark:text-gray-400">non calculable</div>
                  </div>
                )
              )}

              {signalQuery.data && (
                <>
                  <div className="text-right">
                    <div className="text-lg font-semibold tabular-nums">{signalQuery.data.close_price ?? '—'} FCFA</div>
                    <div
                      className={`text-sm tabular-nums ${
                        Number(signalQuery.data.variation_percent) > 0
                          ? 'text-emerald-600 dark:text-emerald-400'
                          : Number(signalQuery.data.variation_percent) < 0
                            ? 'text-red-600 dark:text-red-400'
                            : 'text-gray-500'
                      }`}
                    >
                      {signalQuery.data.variation_percent !== null
                        ? `${Number(signalQuery.data.variation_percent) > 0 ? '+' : ''}${Number(signalQuery.data.variation_percent).toFixed(2)}%`
                        : '—'}
                    </div>
                  </div>
                  <button
                    type="button"
                    onClick={() => setShowSignalHelp(true)}
                    title={`${signalQuery.data.label} — signal technique calculé à partir des cours passés. Cliquer pour comprendre ce que ça veut dire.`}
                    className={`rounded-full px-3 py-1 text-sm font-semibold ${signalBadgeClass(signalQuery.data.score)}`}
                  >
                    {signalQuery.data.label}
                    <span className="ml-1.5 opacity-70" aria-hidden="true">?</span>
                  </button>
                </>
              )}
            </div>
          </div>

          {showSignalHelp && signalQuery.data && (
            <Modal title="Comprendre le signal technique" onClose={() => setShowSignalHelp(false)}>
              <SignalExplanation signal={signalQuery.data} />
            </Modal>
          )}

          <Tabs tabs={[...TABS]} active={activeTab} onChange={(id) => setActiveTab(id as TabId)} />

          {activeTab === 'globale' && (
            <div className="flex flex-col gap-4">
              <Card title="Analyse IA globale">
                <InfoPanel title="Ce que couvre cette analyse">
                  <p>
                    Contrairement aux analyses de chaque onglet (qui portent chacune sur un seul graphe/tableau),
                    celle-ci prend en compte <strong>toutes les données déjà chargées du tableau de bord</strong>
                    pour cette entreprise en une seule fois : cours et signal, fondamentaux, opérations sur titres,
                    aperçu du backtest, performance/risque, classement et contexte sectoriel. Coche des rapports
                    et/ou des documents complémentaires dans l'onglet "Rapports" pour les inclure ici en texte
                    intégral (en plus des chiffres déjà agrégés). Chaque analyse est sauvegardée — voir la
                    comparaison ci-dessous pour suivre son évolution dans le temps.
                  </p>
                </InfoPanel>

                <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">
                  {globalAnalysisReportIds.length === 0 && globalAnalysisDocumentIds.length === 0
                    ? "Aucun rapport ni document coché — l'analyse se basera uniquement sur les données déjà agrégées ci-dessus."
                    : `${globalAnalysisReportIds.length} rapport(s) et ${globalAnalysisDocumentIds.length} document(s) coché(s) (onglet Rapports) seront inclus en texte intégral.`}
                </p>

                <ChartAiAnalysis
                  chartType="company_dashboard"
                  parameters={{
                    company_id: companyId,
                    start_date: startDate,
                    end_date: endDate,
                    selected_report_ids: [...globalAnalysisReportIds].sort((a, b) => a - b),
                    selected_document_ids: [...globalAnalysisDocumentIds].sort((a, b) => a - b),
                  }}
                  data={globalAnalysisData}
                />
              </Card>

              <Card title="Comparer des analyses précédentes">
                <div className="mb-3 flex items-start justify-between gap-3">
                  <p className="text-xs text-gray-500 dark:text-gray-400">
                    Toutes les analyses globales déjà sauvegardées pour cette entreprise, quelle que soit la période
                    ou la sélection de rapports/documents utilisée à l'époque — coche-en 2 (ou plus) pour les
                    comparer côte à côte.
                  </p>
                  <Button
                    variant="secondary"
                    onClick={() => queryClient.invalidateQueries({ queryKey: ['dashboard-global-analysis-history', companyId] })}
                  >
                    <span className="flex items-center gap-2">
                      <RetryIcon spinning={globalHistoryQuery.isFetching} />
                      Actualiser
                    </span>
                  </Button>
                </div>

                {globalHistoryQuery.isLoading && <LoadingState />}
                {globalHistoryQuery.error && <ErrorState message={(globalHistoryQuery.error as Error).message} />}

                {globalHistoryQuery.data && globalHistoryQuery.data.length === 0 && (
                  <p className="text-sm text-gray-500 dark:text-gray-400">Aucune analyse globale sauvegardée pour l'instant.</p>
                )}

                {globalHistoryQuery.data && globalHistoryQuery.data.length > 0 && (
                  <div className="flex flex-col gap-2">
                    {globalHistoryQuery.data.map((a) => (
                      <label key={a.id} className="flex cursor-pointer items-start gap-2 rounded-md border border-gray-200 p-2 text-sm dark:border-gray-800">
                        <input
                          type="checkbox"
                          className="mt-1"
                          checked={compareAnalysisIds.includes(a.id)}
                          onChange={() =>
                            setCompareAnalysisIds((prev) => (prev.includes(a.id) ? prev.filter((id) => id !== a.id) : [...prev, a.id]))
                          }
                        />
                        <span className="min-w-0 flex-1">
                          <span className="flex flex-wrap items-center gap-1.5 text-xs">
                            <span className="font-medium text-gray-700 dark:text-gray-300">{a.created_at}</span>
                            <span className="text-gray-400 dark:text-gray-500">
                              {a.provider}/{a.model}
                            </span>
                            {a.period && <span className="text-gray-400 dark:text-gray-500">· {a.period}</span>}
                            {a.status === 'failed' && (
                              <span className="rounded-full bg-red-100 px-2 py-0.5 font-medium text-red-700 dark:bg-red-950 dark:text-red-300">échec</span>
                            )}
                            {a.rating && <span className="text-amber-500">{'★'.repeat(a.rating)}</span>}
                          </span>
                          {a.summary && <p className="mt-1 truncate text-xs text-gray-600 dark:text-gray-400">{a.summary}</p>}
                        </span>
                      </label>
                    ))}
                  </div>
                )}

                {compareAnalysisIds.length >= 2 && (
                  <div className="mt-4 grid grid-cols-1 gap-4 overflow-x-auto lg:grid-cols-2">
                    {globalHistoryQuery.data
                      ?.filter((a) => compareAnalysisIds.includes(a.id))
                      .map((a) => (
                        <div key={a.id} className="rounded-md border border-gray-200 p-3 dark:border-gray-800">
                          <div className="mb-2 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                            <span>{a.created_at}</span>
                            <span>{a.provider}/{a.model}{a.period ? ` · ${a.period}` : ''}</span>
                          </div>
                          {a.status === 'failed' ? (
                            <ErrorState message={a.error_message ?? "Échec de l'analyse"} />
                          ) : (
                            <div className="flex flex-col gap-3 text-sm">
                              <div>
                                <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Résumé</h4>
                                <p className="text-gray-700 dark:text-gray-300">{a.summary}</p>
                              </div>
                              {a.key_observations.length > 0 && (
                                <div>
                                  <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Observations</h4>
                                  <ul className="list-disc pl-4 text-gray-700 dark:text-gray-300">
                                    {a.key_observations.map((o, i) => <li key={i}>{o}</li>)}
                                  </ul>
                                </div>
                              )}
                              {a.notable_points.length > 0 && (
                                <div>
                                  <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Points notables</h4>
                                  <ul className="list-disc pl-4 text-gray-700 dark:text-gray-300">
                                    {a.notable_points.map((o, i) => <li key={i}>{o}</li>)}
                                  </ul>
                                </div>
                              )}
                            </div>
                          )}
                        </div>
                      ))}
                  </div>
                )}
              </Card>
            </div>
          )}

          {activeTab === 'chat' && selectedCompany && (
            <div className="flex flex-col gap-4">
              <Card title={`Assistant IA — ${selectedCompany.symbol}`}>
                <InfoPanel title="Comment fonctionne cet assistant ?">
                  <p>
                    Discussion continue avec une IA qui s'appuie sur <strong>toutes les données déjà chargées de ce
                    tableau de bord</strong> (cours, signal, indicateurs techniques, fondamentaux, opérations sur
                    titres, backtest, performance/risque, classement, contexte sectoriel) et peut{' '}
                    <strong>chercher sur internet</strong> pour compléter (actualité récente, contexte de marché,
                    définitions). Les réponses sont volontairement détaillées et vulgarisées, pensées pour un
                    débutant en bourse — la conversation est sauvegardée et reprend là où tu l'as laissée.
                  </p>
                </InfoPanel>

                <div className="mt-3">
                  <CompanyChatBot
                    companyId={selectedCompany.company_id}
                    company={{ symbol: selectedCompany.symbol, name: selectedCompany.name, sector: selectedCompany.sector_name ?? null }}
                    dashboardData={globalAnalysisData}
                  />
                </div>
              </Card>
            </div>
          )}

          {activeTab === 'cours' && (
            <div className="flex flex-col gap-6">
              <InfoPanel>
                <p>
                  Cours de clôture, moyennes mobiles, volume, variation intrajournalière et indicateurs techniques
                  avancés de l'entreprise sélectionnée, sur la période choisie en haut de page — mêmes graphes que la
                  page Cotations. Les points{' '}
                  <span className="text-emerald-600 dark:text-emerald-400">verts</span>/
                  <span className="text-red-600 dark:text-red-400">rouges</span> marquent un croisement de moyennes
                  mobiles (golden/death cross) ou une divergence cours/RSI selon le graphe — signaux techniques
                  classiques, pas un conseil d'investissement.
                </p>
              </InfoPanel>

              {companyId && (
                <Card title="Variation intrajournalière">
                  <div className="mb-3 flex flex-wrap items-end gap-3">
                    <label className="w-32">
                      <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Heure début</span>
                      <Input type="time" value={intradayStartTime} onChange={(e) => setIntradayStartTime(e.target.value)} />
                    </label>
                    <label className="w-32">
                      <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Heure fin</span>
                      <Input type="time" value={intradayEndTime} onChange={(e) => setIntradayEndTime(e.target.value)} />
                    </label>
                    {(intradayStartTime || intradayEndTime) && (
                      <button
                        type="button"
                        onClick={() => { setIntradayStartTime(''); setIntradayEndTime('') }}
                        className="pb-2 text-xs text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                      >
                        Réinitialiser (toute la séance)
                      </button>
                    )}
                  </div>

                  {intradayQuery.isLoading && <LoadingState />}
                  {intradayQuery.error && <ErrorState message={(intradayQuery.error as Error).message} />}
                  {intradayQuery.data && intradayData.length === 0 && (
                    <p className="text-sm text-gray-500 dark:text-gray-400">
                      {intradayDataAll.length > 0
                        ? "Aucun relevé dans le créneau horaire choisi — élargis l'heure de début/fin."
                        : "Aucun relevé pour cette période (hors heures de marché, ou synchro pas encore passée)."}
                    </p>
                  )}
                  {intradayData.length > 0 && (
                    <ResponsiveContainer width="100%" height={200}>
                      <LineChart data={intradayData}>
                        <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                        <XAxis dataKey="time" tick={{ fontSize: 11 }} minTickGap={30} />
                        <YAxis yAxisId="percent" domain={['auto', 'auto']} tick={{ fontSize: 11 }} width={60} tickFormatter={(v) => `${v}%`} />
                        {/* Axe caché dédié au cours (FCFA) : sans lui, la ligne "price" (même
                            si invisible) partagerait l'axe de la variation en % et l'étirerait
                            à l'échelle du prix (des centaines/milliers de FCFA), écrasant la
                            courbe de variation à plat près de 0 — bug constaté et corrigé. */}
                        <YAxis yAxisId="price" domain={['auto', 'auto']} hide />
                        <ReferenceLine yAxisId="percent" y={0} stroke="var(--chart-muted)" strokeDasharray="3 3" />
                        <Tooltip
                          // Contenu personnalisé plutôt que `formatter` : le formatter par défaut de
                          // recharts colore chaque ligne d'infobulle avec le `stroke` de la Line
                          // correspondante — impossible d'avoir une couleur différente de celle du
                          // graphe (ici, la ligne "price" est transparente sur le graphe lui-même).
                          content={({ active, payload, label }) => {
                            if (!active || !payload || payload.length === 0) return null
                            const variation = payload.find((p) => p.dataKey === 'variation_percent')?.value
                            const price = payload.find((p) => p.dataKey === 'price')?.value
                            const point = payload[0]?.payload as
                              | { volume_delta: number | null; volume_cumulative: number | null }
                              | undefined
                            return (
                              <div className="rounded-md border border-gray-200 bg-white px-3 py-2 text-xs shadow-sm dark:border-gray-700 dark:bg-gray-900">
                                <div className="mb-1 font-medium text-gray-500 dark:text-gray-400">
                                  {intradaySingleDay ? `Heure : ${label}` : `Date/heure : ${label}`}
                                </div>
                                {variation !== undefined && (
                                  <div className="text-gray-700 underline-offset-2 dark:text-gray-200">
                                    Variation : {Number(variation).toFixed(2)}%
                                  </div>
                                )}
                                {price !== undefined && (
                                  <div className="text-red-600 dark:text-red-400">
                                    Cours : {Number(price).toLocaleString('fr-FR', { maximumFractionDigits: 2 })} FCFA
                                  </div>
                                )}
                                {point && (
                                  <div className="mt-1 border-t border-gray-100 pt-1 text-gray-600 dark:border-gray-800 dark:text-gray-300">
                                    <div>
                                      Échangés sur ce créneau :{' '}
                                      {point.volume_delta !== null
                                        ? `${point.volume_delta.toLocaleString('fr-FR')} titre${point.volume_delta > 1 ? 's' : ''}`
                                        : '—'}
                                    </div>
                                    <div className="text-gray-500 dark:text-gray-400">
                                      Cumul depuis l'ouverture :{' '}
                                      {point.volume_cumulative !== null
                                        ? `${point.volume_cumulative.toLocaleString('fr-FR')} titres`
                                        : '—'}
                                    </div>
                                  </div>
                                )}
                              </div>
                            )
                          }}
                        />
                        <Line yAxisId="percent" type="monotone" dataKey="variation_percent" stroke="var(--chart-1)" dot strokeWidth={2} />
                        {/* Ligne invisible : sert uniquement à faire apparaître le cours (FCFA) à côté
                            de la variation dans l'infobulle au survol, sans tracer une 2e courbe sur
                            une échelle différente (le cours n'est pas en %, il rendrait le graphe illisible). */}
                        <Line yAxisId="price" type="monotone" dataKey="price" stroke="transparent" dot={false} activeDot={false} legendType="none" isAnimationActive={false} />
                      </LineChart>
                    </ResponsiveContainer>
                  )}
                </Card>
              )}

              {ohlcQuery.isLoading && <LoadingState />}
              {ohlcQuery.error && <ErrorState message={(ohlcQuery.error as Error).message} />}

              {ohlcQuery.data && ohlcQuery.data.length < 2 && (
                <p className="text-sm text-gray-500 dark:text-gray-400">Historique insuffisant pour tracer un graphique.</p>
              )}

              {ohlcQuery.data && ohlcQuery.data.length >= 2 && (
                <>
                  <Card title="Cours de clôture">
                    <div className="mb-3 flex flex-wrap gap-4 text-xs text-gray-600 dark:text-gray-300">
                      <label className="flex items-center gap-1.5">
                        <input
                          type="checkbox"
                          checked={showSma.sma_10}
                          onChange={(e) => setShowSma((s) => ({ ...s, sma_10: e.target.checked }))}
                        />
                        SMA 10
                      </label>
                      <label className="flex items-center gap-1.5">
                        <input
                          type="checkbox"
                          checked={showSma.sma_20}
                          onChange={(e) => setShowSma((s) => ({ ...s, sma_20: e.target.checked }))}
                        />
                        SMA 20
                      </label>
                      <label className="flex items-center gap-1.5">
                        <input
                          type="checkbox"
                          checked={showSma.sma_50}
                          onChange={(e) => setShowSma((s) => ({ ...s, sma_50: e.target.checked }))}
                        />
                        SMA 50
                      </label>
                      <label className="flex items-center gap-1.5">
                        <input type="checkbox" checked={showVwap} onChange={(e) => setShowVwap(e.target.checked)} />
                        VWAP (jour)
                      </label>
                    </div>
                    <ResponsiveContainer width="100%" height={260}>
                      <LineChart data={priceWithSma}>
                        <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                        <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                        <YAxis domain={['auto', 'auto']} tick={{ fontSize: 11 }} width={70} />
                        <Tooltip />
                        <Legend />
                        <Line type="monotone" dataKey="close" name="Clôture" stroke="var(--chart-1)" dot={false} strokeWidth={2} />
                        {showSma.sma_10 && (
                          <Line type="monotone" dataKey="sma_10" name="SMA 10" stroke="var(--chart-5)" dot={false} strokeWidth={1.5} connectNulls />
                        )}
                        {showSma.sma_20 && (
                          <Line type="monotone" dataKey="sma_20" name="SMA 20" stroke="var(--chart-4)" dot={false} strokeWidth={1.5} connectNulls />
                        )}
                        {showSma.sma_50 && (
                          <Line type="monotone" dataKey="sma_50" name="SMA 50" stroke="var(--chart-5)" dot={false} strokeWidth={1.5} connectNulls />
                        )}
                        {showVwap && (
                          <Line type="monotone" dataKey="vwap" name="VWAP (jour)" stroke="var(--chart-negative)" dot={false} strokeWidth={1.5} strokeDasharray="4 2" connectNulls />
                        )}
                        {(crossoversQuery.data ?? []).map((c, i) => {
                          const point = priceWithSma.find((p) => p.date === c.date)
                          if (!point) return null
                          return (
                            <ReferenceDot
                              key={i}
                              x={c.date}
                              y={point.close}
                              r={5}
                              fill={c.type === 'golden' ? '#1baf7a' : '#e34948'}
                              stroke="#fff"
                              strokeWidth={1}
                            />
                          )
                        })}
                      </LineChart>
                    </ResponsiveContainer>

                    <ChartAiAnalysis
                      chartType="quotes_close_sma"
                      parameters={{ company_id: companyId, start_date: startDate, end_date: endDate, sma: showSma, vwap: showVwap }}
                      data={priceWithSma}
                    />
                  </Card>

                  {/* Chandeliers + activité (valeur transigée / volume) : remplace
                      l'ancienne carte "Volume" qui n'affichait que le nombre de
                      titres, alors que stock_quotes contient aussi l'ouverture, le
                      plus haut, le plus bas et la valeur transigée. */}
                  <DailyQuotesChart data={ohlcQuery.data} symbol={selectedCompany?.symbol ?? ''} />

                  <ChartAiAnalysis
                    chartType="daily_ohlc"
                    parameters={{ company_id: companyId, start_date: startDate, end_date: endDate }}
                    data={ohlcQuery.data}
                    companyIdsForReports={companyId ? [companyId] : []}
                  />

                  {hasAdvancedIndicator('obv') && (
                    <Card title="On-Balance Volume (OBV)">
                      <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
                        Cumul du volume signé — seule la <strong>tendance</strong> de la courbe compte, pas sa valeur
                        absolue.
                      </p>
                      <ResponsiveContainer width="100%" height={140}>
                        <LineChart data={advancedIndicatorsData}>
                          <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                          <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                          <YAxis tick={{ fontSize: 11 }} width={70} />
                          <Tooltip />
                          <Line type="monotone" dataKey="obv" name="OBV" stroke="var(--chart-3)" dot={false} strokeWidth={2} connectNulls />
                        </LineChart>
                      </ResponsiveContainer>
                    </Card>
                  )}

                  <Card title="Indicateurs avancés (tendance & momentum)">
                    {hasAdvancedIndicator('adx_14') ? (
                      <div className="mb-4">
                        <div className="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">
                          ADX (14j) — force de la tendance
                        </div>
                        <ResponsiveContainer width="100%" height={110}>
                          <LineChart data={advancedIndicatorsData}>
                            <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                            <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                            <YAxis domain={[0, 100]} tick={{ fontSize: 11 }} width={40} />
                            <ReferenceLine y={25} stroke="var(--chart-muted)" strokeDasharray="3 3" />
                            <Tooltip />
                            <Line type="monotone" dataKey="adx_14" name="ADX" stroke="var(--chart-2)" dot={false} strokeWidth={2} connectNulls />
                          </LineChart>
                        </ResponsiveContainer>
                      </div>
                    ) : (
                      <p className="mb-4 text-sm text-gray-400 dark:text-gray-600">ADX : historique insuffisant.</p>
                    )}

                    {hasAdvancedIndicator('stoch_k') ? (
                      <div className="mb-4">
                        <div className="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">
                          Oscillateur stochastique (%K 14j / %D 3j)
                        </div>
                        <ResponsiveContainer width="100%" height={110}>
                          <LineChart data={advancedIndicatorsData}>
                            <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                            <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                            <YAxis domain={[0, 100]} tick={{ fontSize: 11 }} width={40} />
                            <ReferenceLine y={80} stroke="var(--chart-negative)" strokeDasharray="3 3" />
                            <ReferenceLine y={20} stroke="var(--chart-4)" strokeDasharray="3 3" />
                            <Tooltip />
                            <Legend />
                            <Line type="monotone" dataKey="stoch_k" name="%K" stroke="var(--chart-2)" dot={false} strokeWidth={1.5} connectNulls />
                            <Line type="monotone" dataKey="stoch_d" name="%D" stroke="var(--chart-5)" dot={false} strokeWidth={1.5} connectNulls />
                          </LineChart>
                        </ResponsiveContainer>
                      </div>
                    ) : (
                      <p className="mb-4 text-sm text-gray-400 dark:text-gray-600">Stochastique : historique insuffisant.</p>
                    )}

                    {hasAdvancedIndicator('roc_12') ? (
                      <div className="mb-4">
                        <div className="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">Rate of Change (12j)</div>
                        <ResponsiveContainer width="100%" height={110}>
                          <LineChart data={advancedIndicatorsData}>
                            <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                            <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                            <YAxis tick={{ fontSize: 11 }} width={50} tickFormatter={(v: number) => `${v}%`} />
                            <ReferenceLine y={0} stroke="var(--chart-muted)" strokeDasharray="3 3" />
                            <Tooltip formatter={(value) => [`${Number(value).toFixed(2)}%`, 'ROC']} />
                            <Line type="monotone" dataKey="roc_12" name="ROC" stroke="var(--chart-5)" dot={false} strokeWidth={2} connectNulls />
                          </LineChart>
                        </ResponsiveContainer>
                      </div>
                    ) : (
                      <p className="mb-4 text-sm text-gray-400 dark:text-gray-600">ROC : historique insuffisant.</p>
                    )}

                    {hasAdvancedIndicator('rsi_14') ? (
                      <div>
                        <div className="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">
                          RSI (14j) et divergences cours/RSI
                        </div>
                        <ResponsiveContainer width="100%" height={110}>
                          <LineChart data={advancedIndicatorsData}>
                            <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                            <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                            <YAxis domain={[0, 100]} tick={{ fontSize: 11 }} width={40} />
                            <ReferenceLine y={70} stroke="var(--chart-negative)" strokeDasharray="3 3" />
                            <ReferenceLine y={30} stroke="var(--chart-4)" strokeDasharray="3 3" />
                            <Tooltip />
                            <Line type="monotone" dataKey="rsi_14" name="RSI" stroke="var(--chart-3)" dot={false} strokeWidth={2} connectNulls />
                            {(divergenceQuery.data ?? []).map((d, i) => {
                              const point = advancedIndicatorsData.find((p) => p.date === d.date)
                              if (!point || point.rsi_14 === null) return null
                              return (
                                <ReferenceDot
                                  key={i}
                                  x={d.date}
                                  y={point.rsi_14}
                                  r={5}
                                  fill={d.type === 'bearish' ? '#e34948' : '#1baf7a'}
                                  stroke="#fff"
                                  strokeWidth={1}
                                />
                              )
                            })}
                          </LineChart>
                        </ResponsiveContainer>
                      </div>
                    ) : (
                      <p className="text-sm text-gray-400 dark:text-gray-600">RSI : historique insuffisant.</p>
                    )}
                  </Card>
                </>
              )}

              <Link to="/quotes" className="text-sm text-gray-700 underline-offset-2 hover:underline dark:text-gray-200">
                Voir les signaux techniques de toute la cote (classement, filtres) →
              </Link>
            </div>
          )}

          {activeTab === 'fondamentaux' && (
            <div className="flex flex-col gap-4">
              <InfoPanel>
                <p>
                  Ratios financiers <strong>extraits par IA</strong> du dernier rapport financier traité de cette
                  entreprise (états financiers, rapport annuel/semestriel/trimestriel...) — la fiabilité dépend de ce
                  que ce rapport a effectivement divulgué, pas d'un calcul déterministe sur des données de marché.
                  Beaucoup de cases vides est normal : un rapport ne divulgue pas systématiquement tous les éléments
                  nécessaires à chaque ratio.
                </p>
              </InfoPanel>

              {fundamentalsQuery.isLoading && <LoadingState />}
              {fundamentalsQuery.error && <ErrorState message={(fundamentalsQuery.error as Error).message} />}

              {fundamentalsQuery.data && !fundamentals && (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  Aucun rapport financier encore analysé par IA pour cette entreprise — voir l'onglet Rapports ci-dessous.
                </p>
              )}

              {fundamentals && (
                <>
                  {/* Filtres Année/Type de rapport — permettent de consulter l'historique complet des analyses de
                      CETTE entreprise (fundamentalsHistoryQuery), pas seulement son dernier rapport connu. */}
                  {(fundamentalsHistoryYears.length > 1 || fundamentalsHistoryReportTypes.length > 1 || fundamentalsHistoryProviders.length > 1) && (
                    <div className="flex flex-wrap items-center gap-3 text-sm">
                      <label className="flex items-center gap-2">
                        <span className="text-gray-500 dark:text-gray-400">Année :</span>
                        <div className="w-48">
                          <SearchableSelect
                            options={fundamentalsHistoryYears.map((y) => ({ value: String(y), label: String(y) }))}
                            value={fundamentalsHistoryYear}
                            onChange={setFundamentalsHistoryYear}
                            placeholder="Toutes"
                          />
                        </div>
                      </label>
                      <label className="flex items-center gap-2">
                        <span className="text-gray-500 dark:text-gray-400">Type de rapport :</span>
                        <div className="w-56">
                          <SearchableSelect
                            options={fundamentalsHistoryReportTypes.map((t) => ({ value: t, label: REPORT_TYPE_LABELS[t] ?? t }))}
                            value={fundamentalsHistoryReportType}
                            onChange={setFundamentalsHistoryReportType}
                            placeholder="Tous"
                          />
                        </div>
                      </label>
                      {/* Un même rapport peut être analysé par plusieurs IA — ce filtre permet de choisir
                          laquelle afficher, pour comparer leurs extractions sur un même rapport. */}
                      {fundamentalsHistoryProviders.length > 1 && (
                        <label className="flex items-center gap-2">
                          <span className="text-gray-500 dark:text-gray-400">IA :</span>
                          <div className="w-56">
                            <SearchableSelect
                              options={fundamentalsHistoryProviders.map((pm) => {
                                const [provider, model] = pm.split('::')
                                return { value: pm, label: `${provider} (${model})` }
                              })}
                              value={fundamentalsHistoryProvider}
                              onChange={setFundamentalsHistoryProvider}
                              placeholder="Toutes"
                            />
                          </div>
                        </label>
                      )}
                      {historyFilterActive && (
                        <button
                          type="button"
                          onClick={() => {
                            setFundamentalsHistoryYear('')
                            setFundamentalsHistoryReportType('')
                            setFundamentalsHistoryProvider('')
                          }}
                          className="text-xs text-indigo-600 hover:underline dark:text-indigo-400"
                        >
                          Revenir au dernier rapport connu
                        </button>
                      )}
                    </div>
                  )}

                  {historyFilterActive && displayedFundamentals === null && (
                    <p className="text-sm text-gray-500 dark:text-gray-400">
                      Aucun rapport analysé ne correspond à ces filtres pour cette entreprise.
                    </p>
                  )}

                  {displayedFundamentals && (
                    <>
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-xs text-gray-500 dark:text-gray-400">
                          Chiffres extraits par IA du rapport « {displayedFundamentals.source_report_title} »
                          {' '}({displayedFundamentals.source_publish_date ?? '?'}, {displayedFundamentals.source_report_type})
                          {displayedFundamentals.source_provider && (
                            <> · analysé par <strong>{displayedFundamentals.source_provider}</strong> ({displayedFundamentals.source_model})</>
                          )}
                          {historyFilterActive && (
                            <span className="ml-2 rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-300">
                              Vue historique
                            </span>
                          )}
                        </p>
                        <button
                          type="button"
                          onClick={() => setEditingFinancials(true)}
                          className="flex shrink-0 items-center gap-1 text-xs text-indigo-600 hover:underline dark:text-indigo-400"
                        >
                          <EditIcon /> Modifier
                        </button>
                      </div>

                      <FundamentalsDetailPanel row={displayedFundamentals} valuationQuery={valuationQuery} />

                      <ChartAiAnalysis
                        chartType="fundamentals"
                        parameters={{ selected_company_ids: [companyId] }}
                        data={[displayedFundamentals]}
                      />
                    </>
                  )}
                </>
              )}

              <Link to="/fundamentals" className="text-sm text-gray-700 underline-offset-2 hover:underline dark:text-gray-200">
                Comparer avec les fondamentaux de toutes les entreprises →
              </Link>
            </div>
          )}

          {activeTab === 'flux' && companyId !== null && (
            <div className="flex flex-col gap-4">
              <InfoPanel>
                <p>
                  <strong>À quoi sert cet onglet.</strong> Le volume publié par la BRVM pendant la séance est un{' '}
                  <strong>cumul</strong> : la différence entre deux relevés (~10 min) donne donc les actions{' '}
                  <strong>réellement échangées</strong> dans l'intervalle — des faits, pas des suppositions. Le sens
                  (acheteurs ou vendeurs à l'initiative) est en revanche <strong>estimé 🟧</strong> par la direction du
                  prix (tick rule) : un prix qui monte pendant l'intervalle suggère une initiative acheteuse, un prix
                  qui baisse une initiative vendeuse. Le carnet d'ordres en continu n'est pas public à la BRVM — cet
                  onglet montre ce qui est observable (les exécutions), l'onglet « Carnet &amp; liquidité » montre la
                  photographie de fin de séance publiée au Bulletin Officiel. La période vient du sélecteur de dates en
                  haut de page ; le créneau horaire se règle ci-dessous.
                </p>
              </InfoPanel>
              <ExecutionFlowPanel companyId={companyId} startDate={startDate} endDate={endDate} />
            </div>
          )}

          {activeTab === 'carnet' && companyId !== null && (
            <div className="flex flex-col gap-4">
              <InfoPanel>
                <p>
                  <strong>À quoi sert cet onglet.</strong> Chaque soir, le Bulletin Officiel de la Cote publie pour
                  chaque action les <strong>meilleures limites</strong> du carnet : combien de titres attendent à
                  l'achat et à la vente, et à quels prix. Cet onglet en fait une série temporelle : évolution de
                  l'offre et de la demande, spread, équilibre, <strong>taux d'absorption</strong> (l'offre affichée la
                  veille a-t-elle trouvé preneur ?) et un <strong>score de liquidité 0-100</strong> qui répond à la
                  question clé : « si je possède N actions, le marché peut-il les absorber rapidement ? ». Attention à
                  ne jamais sur-interpréter : une baisse de l'offre peut venir d'exécutions <em>ou</em> d'annulations —
                  chaque lecture est confrontée aux volumes réellement échangés, et chaque chiffre porte sa nature
                  (🟦 observé, 🟨 calculé, 🟧 estimé).
                </p>
              </InfoPanel>
              <OrderBookLiquidityPanel companyId={companyId} startDate={startDate} endDate={endDate} />
            </div>
          )}

          {activeTab === 'dividendes' && companyId !== null && (
            <div className="flex flex-col gap-4">
              <InfoPanel>
                <p>
                  <strong>À quoi sert cet onglet.</strong> Un dividende, c'est la part du bénéfice que l'entreprise
                  reverse à ses actionnaires. Tu trouves ici <strong>tous les versements connus de cette
                  entreprise</strong> (graphe et tableau détaillé), et sa place parmi{' '}
                  <strong>tous les rendements du marché</strong> pour savoir si elle paie bien comparée aux autres.
                </p>
                <p>
                  Le <strong>rendement</strong> rapporte le dividende au cours actuel : c'est ce que te
                  rapporterait ton argent au prix d'aujourd'hui. Deux rappels : le jour du détachement, le cours
                  baisse mécaniquement d'environ le montant versé (ce n'est pas une chute) ; et les montants
                  affichés sont bruts, avant l'IRVM (10 à 12 % selon ta situation).
                </p>
              </InfoPanel>
              <CompanyDividends companyId={companyId} symbol={selectedCompany?.symbol ?? ''} />
            </div>
          )}

          {activeTab === 'evenements' && (
            <div className="flex flex-col gap-4">
              <InfoPanel>
                <p>
                  <strong>À quoi sert cet onglet.</strong> Le cours d'une action réagit aux informations — annonces,
                  résultats, contrats, changements de direction, litiges… Cet onglet constitue un <strong>journal
                  daté</strong> de ces informations pour cette entreprise : tu y gardes une trace de ce que tu
                  découvres (saisie manuelle), et tu peux demander à l'IA d'aller chercher sur internet les
                  informations récentes — <strong>rien n'est enregistré sans ta relecture et ta confirmation</strong>,
                  et le jugement d'impact (positif/négatif sur le cours) reste toujours le tien, jamais celui de
                  l'IA. Avec le temps, ce journal devient un historique interprétable : « que savait-on, et quand ? »
                </p>
              </InfoPanel>
              <CompanyMarketEvents companyId={companyId} companyName={selectedCompany?.name ?? ''} />
            </div>
          )}

          {activeTab === 'annonces' && (
            <div className="flex flex-col gap-4">
              <InfoPanel>
                <p>
                  <strong>À quoi sert cet onglet.</strong> La BRVM publie les annonces officielles des émetteurs —
                  convocations d'assemblées générales, projets de résolution, notations financières, avis de paiement
                  de dividendes, communiqués, changements de dirigeants, franchissements de seuil — ainsi que les
                  avis du marché et des publications économiques. Cet onglet les découvre automatiquement depuis
                  brvm.org, télécharge les PDF, en extrait le texte, peut les <strong>convertir en markdown</strong>
                  {' '}propre et les <strong>analyser par IA</strong> (résumé, points clés, dates et montants,
                  intérêt pour l'investisseur) — exactement le même pipeline que les rapports financiers et les
                  bulletins de cote. Le rattachement à l'entreprise se fait automatiquement depuis la colonne
                  « Société » du site (badge « approx. » quand le rapprochement n'est pas certain).
                </p>
              </InfoPanel>
              <CompanyAnnouncements companyId={companyId} />
            </div>
          )}

          {activeTab === 'operations' && (
            <div className="flex flex-col gap-4">
              <InfoPanel>
                <p>
                  Dividendes, augmentations de capital, admissions et assemblées générales concernant cette
                  entreprise, extraits par IA des Bulletins Officiels de la Cote déjà traités. Ce calendrier ne
                  contient que ce qui a été identifié dans les bulletins déjà extraits — l'absence d'une opération ne
                  signifie pas qu'elle n'existe pas, voir la page complète pour extraire les bulletins en attente.
                </p>
              </InfoPanel>

              {corporateActionsQuery.isLoading && <LoadingState />}
              {corporateActionsQuery.error && <ErrorState message={(corporateActionsQuery.error as Error).message} />}

              {corporateActionsQuery.data && corporateActions.length === 0 && (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  Aucune opération sur titres (dividende, augmentation de capital, admission, AG) identifiée pour cette entreprise.
                </p>
              )}

              {corporateActions.length > 0 && (
                <Card>
                  <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                      <thead>
                        <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                          <th className="pb-2 pr-3">Date</th>
                          <th className="pb-2 pr-3">Type</th>
                          <th className="pb-2 pr-3 text-right">Montant</th>
                          <th className="pb-2 pr-3">Description</th>
                        </tr>
                      </thead>
                      <tbody>
                        {corporateActions.map((a) => (
                          <tr key={a.id} className="border-t border-gray-100 align-top dark:border-gray-800">
                            <td className="py-2 pr-3 whitespace-nowrap tabular-nums">{a.event_date ?? '—'}</td>
                            <td className="py-2 pr-3">
                              <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${actionTypeBadgeClass(a.action_type)}`}>
                                {ACTION_TYPE_LABELS[a.action_type] ?? a.action_type}
                              </span>
                            </td>
                            <td className="py-2 pr-3 text-right tabular-nums">
                              {a.amount !== null ? `${fmt(a.amount)} ${a.currency}` : '—'}
                            </td>
                            <td className="py-2 pr-3 max-w-md text-gray-600 dark:text-gray-300">{a.description ?? '—'}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>

                  <ChartAiAnalysis chartType="corporate_actions" parameters={{ company_id: companyId }} data={corporateActions} />
                </Card>
              )}

              <Link to="/corporate-actions" className="text-sm text-gray-700 underline-offset-2 hover:underline dark:text-gray-200">
                Voir le calendrier complet des opérations sur titres →
              </Link>
            </div>
          )}

          {activeTab === 'rapports' && (
            <div className="flex flex-col gap-4">
              <InfoPanel>
                <p>
                  Tous les rapports connus de cette entreprise, du plus récent au plus ancien. Le badge "MD" indique
                  si le rapport a été reformaté en markdown, le badge "IA ×N" combien de fois il a déjà été analysé
                  par l'IA — clique "Analyser" pour lancer ou consulter cette analyse (résumé exécutif, chiffres
                  clés, SWOT, thèse d'investissement...).
                </p>
              </InfoPanel>

              {reportAnalysisStatsQuery.isLoading && <LoadingState label="Chargement de la synthèse des analyses IA…" />}
              {reportAnalysisStatsQuery.error && <ErrorState message={(reportAnalysisStatsQuery.error as Error).message} />}

              {reportAnalysisStatsQuery.data && (
                <Card title="Synthèse des analyses IA">
                  <label className="mb-3 flex w-fit cursor-pointer items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                    <input
                      type="checkbox"
                      checked={includeDocumentsInStats}
                      onChange={(e) => setIncludeDocumentsInStats(e.target.checked)}
                    />
                    Inclure les documents complémentaires (analysés séparément, mêmes graphiques ci-dessous)
                  </label>

                  <p className="mb-4 text-xs text-gray-500 dark:text-gray-400">
                    Calculée à partir des <strong>{reportAnalysisStatsQuery.data.analyzed_reports}</strong> rapport(s)
                    déjà analysé(s) par l'IA sur les <strong>{reportAnalysisStatsQuery.data.total_reports}</strong> rapports
                    connus de cette entreprise
                    {reportAnalysisStatsQuery.data.documents_included && (
                      <>
                        {' '}et des <strong>{reportAnalysisStatsQuery.data.analyzed_documents}</strong> document(s)
                        complémentaire(s) analysé(s) sur <strong>{reportAnalysisStatsQuery.data.total_documents}</strong>
                      </>
                    )}
                    {' '}— se met à jour automatiquement à mesure que d'autres rapports/documents sont analysés, une
                    seule analyse (la plus récente) comptant par élément.
                  </p>

                  {reportAnalysisStatsQuery.data.analyzed_reports + reportAnalysisStatsQuery.data.analyzed_documents === 0 ? (
                    <p className="text-sm text-gray-500 dark:text-gray-400">
                      Aucun rapport ni document encore analysé par l'IA pour cette entreprise — les graphiques
                      apparaîtront ici dès qu'au moins un élément sera analysé (bouton "Analyser" dans les listes
                      ci-dessous).
                    </p>
                  ) : (
                    <>
                      <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div>
                          <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                            Couverture des analyses
                          </h4>
                          <ResponsiveContainer width="100%" height={200}>
                            <PieChart>
                              <Pie
                                data={[
                                  {
                                    name: 'Analysés',
                                    value: reportAnalysisStatsQuery.data.analyzed_reports + reportAnalysisStatsQuery.data.analyzed_documents,
                                  },
                                  {
                                    name: 'En attente',
                                    value: reportAnalysisStatsQuery.data.pending_reports + reportAnalysisStatsQuery.data.pending_documents,
                                  },
                                ]}
                                dataKey="value"
                                nameKey="name"
                                cx="50%"
                                cy="50%"
                                innerRadius={45}
                                outerRadius={75}
                                paddingAngle={2}
                              >
                                <Cell fill="var(--chart-1)" />
                                <Cell fill="var(--chart-muted)" />
                              </Pie>
                              <Legend />
                              <Tooltip />
                            </PieChart>
                          </ResponsiveContainer>
                        </div>

                        {reportAnalysisStatsQuery.data.verdict_distribution.length > 0 && (
                          <div>
                            <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                              Répartition des verdicts de valorisation
                            </h4>
                            <ResponsiveContainer width="100%" height={200}>
                              <PieChart>
                                <Pie
                                  data={reportAnalysisStatsQuery.data.verdict_distribution}
                                  dataKey="count"
                                  nameKey="verdict"
                                  cx="50%"
                                  cy="50%"
                                  innerRadius={45}
                                  outerRadius={75}
                                  paddingAngle={2}
                                >
                                  {reportAnalysisStatsQuery.data.verdict_distribution.map((v) => (
                                    <Cell key={v.verdict} fill={verdictColor(v.verdict)} />
                                  ))}
                                </Pie>
                                <Legend />
                                <Tooltip />
                              </PieChart>
                            </ResponsiveContainer>
                          </div>
                        )}
                      </div>

                      {reportAnalysisStatsQuery.data.risk_category_distribution.length > 0 && (
                        <div className="mt-6">
                          <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                            Catégories de risques les plus citées
                          </h4>
                          <ResponsiveContainer width="100%" height={Math.max(120, reportAnalysisStatsQuery.data.risk_category_distribution.length * 36)}>
                            <BarChart data={reportAnalysisStatsQuery.data.risk_category_distribution} layout="vertical">
                              <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                              <XAxis type="number" allowDecimals={false} tick={{ fontSize: 11 }} />
                              <YAxis type="category" dataKey="category" tick={{ fontSize: 11 }} width={100} />
                              <Tooltip />
                              <Bar dataKey="count" name="Occurrences" fill="var(--chart-1)" radius={[0, 4, 4, 0]} />
                            </BarChart>
                          </ResponsiveContainer>
                        </div>
                      )}

                      {reportAnalysisStatsQuery.data.financial_trend.filter((f) => f.revenue !== null).length > 1 && (
                        <div className="mt-6">
                          <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                            Chiffre d'affaires par élément analysé (FCFA)
                          </h4>
                          <ResponsiveContainer width="100%" height={200}>
                            <BarChart data={reportAnalysisStatsQuery.data.financial_trend}>
                              <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                              <XAxis dataKey="publish_date" tick={{ fontSize: 11 }} minTickGap={30} />
                              <YAxis tick={{ fontSize: 11 }} width={70} tickFormatter={(v: number) => `${(v / 1_000_000_000).toLocaleString('fr-FR')} Md`} />
                              <Tooltip formatter={(value) => `${Number(value).toLocaleString('fr-FR')} FCFA`} labelFormatter={(_, items) => items[0]?.payload?.source_title ?? ''} />
                              <Bar dataKey="revenue" name="Chiffre d'affaires" fill="var(--chart-1)" radius={[4, 4, 0, 0]} />
                            </BarChart>
                          </ResponsiveContainer>
                        </div>
                      )}

                      {reportAnalysisStatsQuery.data.financial_trend.filter((f) => f.net_income !== null).length > 1 && (
                        <div className="mt-6">
                          <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                            Résultat net par élément analysé (FCFA)
                          </h4>
                          <ResponsiveContainer width="100%" height={200}>
                            <BarChart data={reportAnalysisStatsQuery.data.financial_trend}>
                              <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                              <XAxis dataKey="publish_date" tick={{ fontSize: 11 }} minTickGap={30} />
                              <YAxis tick={{ fontSize: 11 }} width={70} tickFormatter={(v: number) => `${(v / 1_000_000_000).toLocaleString('fr-FR')} Md`} />
                              <ReferenceLine y={0} stroke="var(--chart-muted)" />
                              <Tooltip formatter={(value) => `${Number(value).toLocaleString('fr-FR')} FCFA`} labelFormatter={(_, items) => items[0]?.payload?.source_title ?? ''} />
                              <Bar dataKey="net_income" name="Résultat net" radius={[4, 4, 0, 0]}>
                                {reportAnalysisStatsQuery.data.financial_trend.map((f) => (
                                  <Cell key={`${f.source_type}-${f.source_id}`} fill={f.net_income !== null && f.net_income < 0 ? '#dc2626' : '#059669'} />
                                ))}
                              </Bar>
                            </BarChart>
                          </ResponsiveContainer>
                        </div>
                      )}

                      <ChartAiAnalysis
                        chartType="report_analysis_stats"
                        parameters={{ company_id: companyId, include_documents: includeDocumentsInStats }}
                        data={reportAnalysisStatsQuery.data}
                      />
                    </>
                  )}
                </Card>
              )}

              {reportsQuery.isLoading && <LoadingState />}
              {reportsQuery.error && <ErrorState message={(reportsQuery.error as Error).message} />}

              {reportsQuery.data && allReports.length === 0 && (
                <p className="text-sm text-gray-500 dark:text-gray-400">Aucun rapport connu pour cette entreprise.</p>
              )}

              {allReports.length > 0 && (
                <Card title={`Rapports (${allReports.length} au total)`}>
                  <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
                    Coche un ou plusieurs rapports pour les inclure en texte intégral dans l'Analyse IA globale
                    ci-dessus.
                  </p>
                  <div className="flex max-h-[32rem] flex-col divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800">
                    {allReports.map((r) => (
                      <div key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <label className="flex cursor-pointer items-center gap-2">
                          <input
                            type="checkbox"
                            checked={globalAnalysisReportIds.includes(r.id)}
                            onChange={() =>
                              setGlobalAnalysisReportIds((prev) =>
                                prev.includes(r.id) ? prev.filter((id) => id !== r.id) : [...prev, r.id],
                              )
                            }
                          />
                          <span>
                            <span className="font-medium">{r.report_type}</span>{' '}
                            <span className="text-gray-500 dark:text-gray-400">— {r.publish_date ?? '?'} — {r.title}</span>
                            <MarkdownBadge status={r.markdown_status} />
                            <AnalysisBadge count={r.analyses_count} models={r.analyzed_models} />
                          </span>
                        </label>
                        <Link
                          to={`/analysis?symbol=${selectedCompany?.symbol}&report_id=${r.id}`}
                          className="shrink-0 text-xs text-gray-700 underline-offset-2 hover:underline dark:text-gray-200"
                        >
                          Analyser →
                        </Link>
                      </div>
                    ))}
                  </div>
                </Card>
              )}

              <Link to="/reports" className="text-sm text-gray-700 underline-offset-2 hover:underline dark:text-gray-200">
                Voir/traiter tous les rapports de cette entreprise →
              </Link>

              <div className="mt-2 border-t border-gray-200 pt-4 dark:border-gray-800">
                <h3 className="mb-2 text-sm font-semibold text-gray-500 dark:text-gray-400">Documents complémentaires</h3>
                <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
                  Ajoute manuellement des documents (PDF) absents de brvm.org — rapports détaillés du site de
                  l'entreprise, présentations investisseurs... Une fois le texte extrait, ils sont automatiquement
                  inclus comme contexte additionnel lors de l'analyse IA des rapports de cette entreprise (et
                  disponibles via la case "Inclure les résultats financiers" des analyses de graphes).
                </p>

                <Card>
                  <div className="flex flex-wrap items-end gap-3">
                    <label className="flex-1 min-w-[200px]">
                      <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Titre</span>
                      <Input
                        value={documentTitle}
                        onChange={(e) => setDocumentTitle(e.target.value)}
                        placeholder="ex: Rapport annuel détaillé 2025 (site entreprise)"
                      />
                    </label>
                    <label className="w-56">
                      <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Fichier PDF</span>
                      <input
                        ref={documentFileInput}
                        type="file"
                        accept="application/pdf"
                        className="block w-full text-sm text-gray-600 dark:text-gray-300"
                      />
                    </label>
                    <Button onClick={handleDocumentUpload} disabled={!documentTitle.trim() || uploadDocumentMutation.isPending}>
                      <span className="flex items-center gap-2">
                        <RetryIcon spinning={uploadDocumentMutation.isPending} />
                        {uploadDocumentMutation.isPending ? 'Envoi…' : 'Ajouter'}
                      </span>
                    </Button>
                  </div>
                  {uploadDocumentMutation.isError && (
                    <div className="mt-3">
                      <ErrorState message={(uploadDocumentMutation.error as Error).message} />
                    </div>
                  )}
                </Card>

                {companyDocumentsQuery.isLoading && <LoadingState />}
                {companyDocumentsQuery.error && <ErrorState message={(companyDocumentsQuery.error as Error).message} />}

                {companyDocumentsQuery.data && companyDocumentsQuery.data.length === 0 && (
                  <p className="mt-3 text-sm text-gray-500 dark:text-gray-400">Aucun document complémentaire ajouté pour cette entreprise.</p>
                )}

                {companyDocumentsQuery.data && companyDocumentsQuery.data.length > 0 && (
                  <Card className="mt-3">
                    <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
                      Coche un ou plusieurs documents pour les inclure en texte intégral dans l'Analyse IA globale
                      ci-dessus.
                    </p>
                    <div className="flex flex-col divide-y divide-gray-100 dark:divide-gray-800">
                      {companyDocumentsQuery.data.map((d) => (
                        <div key={d.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                          <label className={`flex items-center gap-2 ${d.text_extracted ? 'cursor-pointer' : 'cursor-not-allowed text-gray-400 dark:text-gray-600'}`}>
                            <input
                              type="checkbox"
                              checked={globalAnalysisDocumentIds.includes(d.id)}
                              disabled={!d.text_extracted}
                              onChange={() =>
                                setGlobalAnalysisDocumentIds((prev) =>
                                  prev.includes(d.id) ? prev.filter((id) => id !== d.id) : [...prev, d.id],
                                )
                              }
                            />
                            <span>
                              <span className="font-medium">{d.title}</span>{' '}
                              <span className="text-gray-500 dark:text-gray-400">— {d.uploaded_at}</span>
                              {d.text_extracted ? (
                                <span className="ml-1.5 text-emerald-600 dark:text-emerald-400">texte extrait</span>
                              ) : d.extraction_error ? (
                                <span className="ml-1.5 text-red-600 dark:text-red-400">échec extraction</span>
                              ) : (
                                <span className="ml-1.5 text-amber-600 dark:text-amber-400">en attente</span>
                              )}
                              <MarkdownBadge status={d.markdown_status} />
                              <AnalysisBadge count={d.analyses_count} models={d.analyzed_models} />
                            </span>
                          </label>
                          <div className="flex items-center gap-1">
                            <IconButton title="Consulter" onClick={() => setViewDocumentId(d.id)}>
                              <EyeIcon />
                            </IconButton>
                            <IconButton
                              title="Supprimer"
                              tone="danger"
                              onClick={() => {
                                if (window.confirm(`Supprimer définitivement "${d.title}" ? Cette action est irréversible.`)) {
                                  deleteDocumentMutation.mutate(d.id)
                                }
                              }}
                            >
                              <TrashIcon />
                            </IconButton>
                          </div>
                        </div>
                      ))}
                    </div>
                  </Card>
                )}
              </div>
            </div>
          )}

          {activeTab === 'backtest' && (
            <div className="flex flex-col gap-4">
              <InfoPanel>
                <p>
                  Simule l'application mécanique d'une règle de trading simple sur l'historique déjà synchronisé,
                  comparée à un simple "acheter et garder" et à son inverse théorique, la <strong>vente à
                  découvert</strong> (on emprunte le titre pour le vendre, dans l'espoir de le racheter moins cher —
                  on gagne quand le cours baisse, on perd quand il monte, exactement l'inverse d'acheter-garder) —
                  synthèse purement mécanique sur des données passées, <strong>pas un conseil en investissement</strong>,
                  et une performance passée ne garantit jamais une performance future. Pour l'historique complet des
                  opérations, utilise la page Backtesting complète.
                </p>
              </InfoPanel>

              <Card>
                <div className="flex flex-wrap items-end gap-4">
                  <label className="w-44">
                    <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Règle</span>
                    <Select value={backtestRule} onChange={(e) => setBacktestRule(e.target.value as 'signal_score' | 'golden_cross')}>
                      <option value="signal_score">Signal composite</option>
                      <option value="golden_cross">Golden/death cross</option>
                    </Select>
                  </label>

                  {backtestRule === 'signal_score' ? (
                    <>
                      <label className="w-36">
                        <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Seuil d'achat (≥)</span>
                        <Select value={backtestBuyThreshold} onChange={(e) => setBacktestBuyThreshold(Number(e.target.value))}>
                          {[2, 1, 0, -1].map((v) => <option key={v} value={v}>{v > 0 ? `+${v}` : v}</option>)}
                        </Select>
                      </label>
                      <label className="w-36">
                        <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Seuil de vente (≤)</span>
                        <Select value={backtestSellThreshold} onChange={(e) => setBacktestSellThreshold(Number(e.target.value))}>
                          {[1, 0, -1, -2].map((v) => <option key={v} value={v}>{v > 0 ? `+${v}` : v}</option>)}
                        </Select>
                      </label>
                    </>
                  ) : (
                    <>
                      <label className="w-36">
                        <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">SMA rapide</span>
                        <Select value={backtestFastSma} onChange={(e) => setBacktestFastSma(e.target.value)}>
                          {['10', '20', '50'].map((p) => <option key={p} value={p}>SMA {p}</option>)}
                        </Select>
                      </label>
                      <label className="w-36">
                        <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">SMA lente</span>
                        <Select value={backtestSlowSma} onChange={(e) => setBacktestSlowSma(e.target.value)}>
                          {['10', '20', '50'].map((p) => <option key={p} value={p}>SMA {p}</option>)}
                        </Select>
                      </label>
                      {backtestFastSma === backtestSlowSma && (
                        <p className="pb-2 text-xs text-red-600 dark:text-red-400">Les deux moyennes doivent être différentes.</p>
                      )}
                    </>
                  )}
                </div>
              </Card>

              {backtestQuery.isLoading && <LoadingState label="Simulation en cours…" />}
              {backtestQuery.error && <ErrorState message={(backtestQuery.error as Error).message} />}

              {backtestQuery.data && (
                <>
                  {backtestQuery.data.insufficient_history && (
                    <div className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">
                      ⚠️ Historique encore trop court ({backtestQuery.data.trading_days} jour(s) de bourse simulés) pour un résultat
                      statistiquement exploitable.
                    </div>
                  )}

                  <div className="grid grid-cols-2 gap-4 sm:grid-cols-5">
                    <StatTile label="Opérations" value={backtestQuery.data.total_trades} />
                    <StatTile
                      label="Taux de réussite"
                      value={backtestQuery.data.win_rate_percent !== null ? `${backtestQuery.data.win_rate_percent}%` : '—'}
                    />
                    <StatTile
                      label="Stratégie"
                      value={pct(backtestQuery.data.strategy_return_percent)}
                      tone={toneFor(backtestQuery.data.strategy_return_percent)}
                    />
                    <StatTile
                      label="Acheter & garder"
                      value={pct(backtestQuery.data.buy_hold_return_percent)}
                      tone={toneFor(backtestQuery.data.buy_hold_return_percent)}
                    />
                    <StatTile
                      label="Vente à découvert"
                      value={pct(backtestQuery.data.short_return_percent)}
                      tone={toneFor(backtestQuery.data.short_return_percent)}
                    />
                  </div>

                  <Card title="Courbe d'équity (base 100)">
                    <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
                      "Vente à découvert" simule une stratégie active indépendante (entrées/sorties inversées par
                      rapport à la stratégie longue, sans frais de portage/marge) — repère pour une thèse baissière,
                      pas un mode de trading réellement disponible sur la BRVM.
                    </p>
                    <ResponsiveContainer width="100%" height={220}>
                      <LineChart data={backtestQuery.data.equity_curve}>
                        <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                        <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                        <YAxis domain={['auto', 'auto']} tick={{ fontSize: 11 }} width={60} />
                        <ReferenceLine y={100} stroke="var(--chart-muted)" strokeDasharray="3 3" />
                        <Tooltip />
                        <Legend />
                        <Line type="monotone" dataKey="strategy_equity_base100" name="Stratégie" stroke="var(--chart-1)" dot={false} strokeWidth={2} />
                        <Line
                          type="monotone"
                          dataKey="buy_hold_equity_base100"
                          name="Acheter & garder"
                          stroke="var(--chart-muted)"
                          dot={false}
                          strokeWidth={2}
                          strokeDasharray="4 2"
                        />
                        <Line
                          type="monotone"
                          dataKey="short_equity_base100"
                          name="Vente à découvert"
                          stroke="var(--chart-negative)"
                          dot={false}
                          strokeWidth={2}
                          strokeDasharray="4 2"
                        />
                      </LineChart>
                    </ResponsiveContainer>
                  </Card>

                  <ChartAiAnalysis
                    chartType="backtest"
                    parameters={{
                      company_id: companyId,
                      rule: backtestRule,
                      ...backtestParams,
                      start_date: startDate,
                      end_date: endDate,
                    }}
                    data={backtestQuery.data}
                  />
                </>
              )}

              <Link to="/backtest" className="text-sm text-gray-700 underline-offset-2 hover:underline dark:text-gray-200">
                Régler la règle/les seuils sur la page Backtesting complète →
              </Link>
            </div>
          )}

          {activeTab === 'risque' && (
            <div className="flex flex-col gap-4">
              <InfoPanel>
                <p>
                  Regroupe les mesures de performance et de risque de la page Statistiques, appliquées à cette seule
                  entreprise : <strong>variation totale</strong> (agitation intrajournalière cumulée),{' '}
                  <strong>rendement net / volatilité / ratio rendement-risque</strong> (un ratio élevé signifie un
                  bon rendement pour le niveau de risque pris, pas nécessairement un rendement élevé en absolu),{' '}
                  <strong>force relative</strong> vs BRVM Composite (au-dessus de 0 = l'entreprise surperforme
                  l'indice), et des <strong>métriques de risque avancées</strong> (Sharpe, Sortino, Max Drawdown,
                  VaR/CVaR, bêta).
                </p>
              </InfoPanel>

              {(riskAdjustedQuery.isLoading || relativeStrengthQuery.isLoading || totalVariationQuery.isLoading) && <LoadingState />}
              {riskAdjustedQuery.error && <ErrorState message={(riskAdjustedQuery.error as Error).message} />}
              {relativeStrengthQuery.error && <ErrorState message={(relativeStrengthQuery.error as Error).message} />}
              {totalVariationQuery.error && <ErrorState message={(totalVariationQuery.error as Error).message} />}

              {totalVariation.length > 0 && (
                <Card title="Variation totale cumulée par jour (agitation)">
                  <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
                    Somme des variations absolues intrajournalières — mesure combien le titre "bouge" réellement dans
                    la journée, indépendamment de là où il termine (voir la page Statistiques pour le détail).
                  </p>
                  <ResponsiveContainer width="100%" height={180}>
                    <LineChart data={totalVariation}>
                      <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                      <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                      <YAxis tick={{ fontSize: 11 }} width={60} tickFormatter={(v: number) => `${v}%`} />
                      <Tooltip formatter={(value) => [`${Number(value).toFixed(2)}%`, 'Variation totale']} />
                      <Line
                        type="monotone"
                        dataKey="total_variation_percent"
                        stroke="var(--chart-5)"
                        dot={false}
                        strokeWidth={2}
                      />
                    </LineChart>
                  </ResponsiveContainer>

                  <ChartAiAnalysis
                    chartType="total_variation"
                    parameters={{ company_ids: [companyId], start_date: startDate, end_date: endDate, display_mode: 'total' }}
                    data={totalVariationQuery.data}
                  />
                </Card>
              )}

              {riskAdjusted && (
                <>
                  <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <StatTile label="Rendement net" value={pct(riskAdjusted.net_return_percent)} tone={toneFor(riskAdjusted.net_return_percent)} />
                    <StatTile label="Volatilité totale" value={pct(riskAdjusted.total_volatility_percent)} />
                    <StatTile label="Ratio rendement/risque" value={fmt(riskAdjusted.risk_adjusted_ratio, 3)} />
                  </div>

                  <ChartAiAnalysis
                    chartType="risk_adjusted"
                    parameters={{ company_ids: [companyId], start_date: startDate, end_date: endDate }}
                    data={riskAdjustedQuery.data}
                  />
                </>
              )}

              {relativeStrength.length > 1 && (
                <Card title="Force relative vs BRVM Composite">
                  <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
                    Écart entre la variation cumulée de l'entreprise et celle de l'indice sur la période — au-dessus
                    de 0 : l'entreprise surperforme l'indice.
                  </p>
                  <ResponsiveContainer width="100%" height={220}>
                    <LineChart data={relativeStrength}>
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
                    parameters={{ company_ids: [companyId], start_date: startDate, end_date: endDate }}
                    data={relativeStrengthQuery.data}
                  />
                </Card>
              )}

              {riskMetrics && !riskMetrics.insufficient_history && (
                <Card title="Métriques de risque avancées">
                  <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
                    Sharpe/Sortino (rendement par unité de risque, plus haut = mieux), Max Drawdown (pire perte
                    cumulée depuis un sommet), VaR/CVaR (perte potentielle sur un jour au seuil de confiance 95%),
                    bêta (sensibilité au BRVM Composite — 1 = évolue comme l'indice).
                  </p>
                  <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <StatTile label="CAGR" value={pct(riskMetrics.cagr_percent)} tone={toneFor(riskMetrics.cagr_percent)} />
                    <StatTile label="Volatilité annualisée" value={pct(riskMetrics.annualized_volatility_percent)} />
                    <StatTile label="Ratio de Sharpe" value={fmt(riskMetrics.sharpe_ratio, 2)} />
                    <StatTile label="Ratio de Sortino" value={fmt(riskMetrics.sortino_ratio, 2)} />
                    <StatTile label="Max Drawdown" value={pct(riskMetrics.max_drawdown_percent)} />
                    <StatTile label="Ratio de Calmar" value={fmt(riskMetrics.calmar_ratio, 2)} />
                    <StatTile label="VaR (95%)" value={pct(riskMetrics.var_percent)} />
                    <StatTile label="CVaR (95%)" value={pct(riskMetrics.cvar_percent)} />
                    <StatTile label="Bêta vs BRVM Composite" value={fmt(riskMetrics.beta, 2)} />
                  </div>

                  <ChartAiAnalysis
                    chartType="risk_metrics_advanced"
                    parameters={{ company_ids: [companyId], start_date: startDate, end_date: endDate, selected_company_ids: [companyId] }}
                    data={riskMetricsQuery.data}
                  />
                </Card>
              )}

              {riskMetrics?.insufficient_history && (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  Historique encore trop court pour les métriques de risque avancées (besoin d'au moins 20 rendements
                  quotidiens).
                </p>
              )}

              <Link to="/statistics" className="text-sm text-gray-700 underline-offset-2 hover:underline dark:text-gray-200">
                Comparer avec d'autres entreprises sur la page Statistiques →
              </Link>
            </div>
          )}

          {activeTab === 'classement' && (
            <div className="flex flex-col gap-4">
              <InfoPanel>
                <p>
                  Position de cette entreprise dans le classement complet du marché (screener), sur la période
                  choisie en haut de page — mêmes critères que la page Screener (signal composite, liquidité, RSI,
                  performance et rang au sein du secteur), sans filtre appliqué ici.
                </p>
              </InfoPanel>

              {screenerQuery.isLoading && <LoadingState />}
              {screenerQuery.error && <ErrorState message={(screenerQuery.error as Error).message} />}

              {screenerQuery.data && !screenerRow && (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  Aucune donnée de classement pour cette entreprise sur la période choisie.
                </p>
              )}

              {screenerRow && (
                <>
                  <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <StatTile
                      label="Rang secteur"
                      value={screenerRow.sector_rank !== null ? `${screenerRow.sector_rank}/${screenerRow.sector_size}` : '—'}
                    />
                    <StatTile
                      label="Performance période"
                      value={screenerRow.period_performance_percent !== null ? pct(screenerRow.period_performance_percent) : '—'}
                      tone={toneFor(screenerRow.period_performance_percent)}
                    />
                    <StatTile label="RSI (14j)" value={screenerRow.rsi_14 !== null ? fmt(screenerRow.rsi_14, 1) : '—'} />
                    <StatTile label="Volume" value={fmt(screenerRow.volume, 0)} />
                  </div>

                  <div className="flex items-center gap-3">
                    <span className={`rounded-full px-3 py-1 text-sm font-semibold ${liquidityBadgeClass(screenerRow.liquidity)}`}>
                      Liquidité : {screenerRow.liquidity ?? '—'}
                    </span>
                    <span className={`rounded-full px-3 py-1 text-sm font-semibold ${signalBadgeClass(screenerRow.score)}`}>
                      {screenerRow.label}
                    </span>
                  </div>

                  <ChartAiAnalysis
                    chartType="screener"
                    parameters={{ start_date: startDate, end_date: endDate, selected_company_ids: [companyId] }}
                    data={[screenerRow]}
                    companyIdsForReports={[companyId]}
                  />
                </>
              )}

              {liquidityHistoryQuery.isLoading && <LoadingState label="Chargement de l'historique de liquidité…" />}
              {liquidityHistoryQuery.error && <ErrorState message={(liquidityHistoryQuery.error as Error).message} />}

              {liquidityHistory.length > 1 && (
                <Card title="Historique de la liquidité">
                  <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
                    Même calcul que le badge ci-dessus (volume moyen sur une fenêtre glissante de 30 jours
                    calendaires, illiquide si plus de 30% de jours sans transaction dans la fenêtre), mais un point
                    par jour de bourse au lieu d'un seul chiffre pour toute la période — utile pour repérer une
                    liquidité qui se dégrade ou s'améliore dans le temps. Repères : sous 200 = <strong>Faible</strong>,
                    200 à 2 000 = <strong>Moyenne</strong>, au-dessus = <strong>Élevée</strong> (sauf si plus de 30%
                    de jours sans transaction, alors <strong>Illiquide</strong> quel que soit le volume).
                  </p>
                  <ResponsiveContainer width="100%" height={220}>
                    <LineChart data={liquidityHistory}>
                      <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                      <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                      <YAxis tick={{ fontSize: 11 }} width={60} />
                      <ReferenceLine y={200} stroke="var(--chart-warning)" strokeDasharray="3 3" />
                      <ReferenceLine y={2000} stroke="var(--chart-positive)" strokeDasharray="3 3" />
                      <Tooltip
                        formatter={(value, _name, item) => {
                          const point = item.payload as (typeof liquidityHistory)[number]
                          return [`${Number(value).toLocaleString('fr-FR')} (${point.liquidity})`, 'Volume moyen (fenêtre 30j)']
                        }}
                      />
                      <Line type="monotone" dataKey="avg_volume" stroke="var(--chart-1)" dot={false} strokeWidth={2} />
                    </LineChart>
                  </ResponsiveContainer>

                  <ChartAiAnalysis
                    chartType="liquidity_history"
                    parameters={{ company_ids: [companyId], start_date: startDate, end_date: endDate }}
                    data={liquidityHistoryQuery.data}
                  />
                </Card>
              )}

              <Link to="/screener" className="text-sm text-gray-700 underline-offset-2 hover:underline dark:text-gray-200">
                Filtrer tout le marché sur plusieurs critères à la fois (screener complet) →
              </Link>
            </div>
          )}

          {activeTab === 'secteur' && (
            <div className="flex flex-col gap-4">
              <InfoPanel>
                <p>
                  <strong>Performance du secteur</strong> de cette entreprise (base 100 au début de la période) —
                  pour comparer avec d'autres secteurs, voir la page Santé & secteurs.{' '}
                  <strong>Largeur de marché</strong> : contexte général (hausses/baisses/inchangés sur toute la cote,
                  30 derniers jours), pas spécifique à cette entreprise. <strong>Qualité des données</strong> :
                  anomalies détectées spécifiquement sur cette entreprise (écarts de variation, sauts de prix
                  intrajournaliers, jours de clôture manquants).
                </p>
              </InfoPanel>

              {sectorPerformanceQuery.isLoading && <LoadingState label="Chargement de la performance sectorielle…" />}
              {sectorPerformanceQuery.error && <ErrorState message={(sectorPerformanceQuery.error as Error).message} />}

              {sectorPerformanceQuery.data && !companySectorSeries && (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  Cette entreprise n'est rattachée à aucun secteur, ou aucune donnée pour la période choisie.
                </p>
              )}

              {companySectorSeries && (
                <Card title={`Secteur ${companySectorSeries.sector_name} — indice base 100`}>
                  <ResponsiveContainer width="100%" height={200}>
                    <LineChart data={companySectorSeries.data}>
                      <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                      <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                      <YAxis domain={['auto', 'auto']} tick={{ fontSize: 11 }} width={60} />
                      <ReferenceLine y={100} stroke="var(--chart-muted)" strokeDasharray="3 3" />
                      <Tooltip />
                      <Line type="monotone" dataKey="index_value" stroke="var(--chart-2)" dot={false} strokeWidth={2} />
                    </LineChart>
                  </ResponsiveContainer>

                  <ChartAiAnalysis
                    chartType="sector_performance"
                    parameters={{ start_date: startDate, end_date: endDate }}
                    data={[companySectorSeries]}
                  />
                </Card>
              )}

              {marketBreadthQuery.data && marketBreadthQuery.data.length > 0 && (
                <Card title="Largeur de marché (contexte général, 30 derniers jours)">
                  <ResponsiveContainer width="100%" height={160}>
                    <BarChart data={marketBreadthQuery.data}>
                      <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                      <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                      <YAxis tick={{ fontSize: 11 }} width={40} />
                      <Tooltip />
                      <Legend />
                      <Bar dataKey="gainers" name="Hausses" stackId="breadth" fill="var(--chart-4)" />
                      <Bar dataKey="unchanged" name="Inchangées" stackId="breadth" fill="var(--chart-muted)" />
                      <Bar dataKey="losers" name="Baisses" stackId="breadth" fill="var(--chart-negative)" />
                    </BarChart>
                  </ResponsiveContainer>
                </Card>
              )}

              {(reconciliationQuery.data || priceJumpsQuery.data || missingDaysQuery.data) && (
                <Card title="Qualité des données pour cette entreprise">
                  {companyReconciliation.length === 0 && companyPriceJumps.length === 0 && !companyMissingDays?.missing_days && (
                    <p className="text-sm text-gray-500 dark:text-gray-400">Aucune anomalie détectée pour cette entreprise.</p>
                  )}
                  {companyReconciliation.length > 0 && (
                    <p className="text-sm text-amber-600 dark:text-amber-400">
                      {companyReconciliation.length} écart(s) de variation stockée vs recalculée détecté(s) (30 derniers jours).
                    </p>
                  )}
                  {companyPriceJumps.length > 0 && (
                    <p className="text-sm text-amber-600 dark:text-amber-400">
                      {companyPriceJumps.length} saut(s) de prix intrajournalier anormal(aux) détecté(s) (7 derniers jours).
                    </p>
                  )}
                  {!!companyMissingDays?.missing_days && (
                    <p className="text-sm text-amber-600 dark:text-amber-400">
                      {companyMissingDays.missing_days} jour(s) de clôture manquant(s) sur {companyMissingDays.expected_days} attendus (30 derniers jours).
                    </p>
                  )}
                </Card>
              )}

              <Link to="/market-health" className="text-sm text-gray-700 underline-offset-2 hover:underline dark:text-gray-200">
                Voir la performance de tous les secteurs et le détail qualité des données →
              </Link>
            </div>
          )}
        </>
      )}

      {viewDocumentId !== null && (
        <Modal title="Consulter le document" onClose={() => { setViewDocumentId(null); setShowRawDocumentText(false) }}>
          {documentDetailQuery.isLoading && <LoadingState />}
          {documentDetailQuery.error && <ErrorState message={(documentDetailQuery.error as Error).message} />}
          {documentDetailQuery.data && (
            <div className="flex flex-col gap-3">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="text-sm font-semibold text-gray-900 dark:text-gray-100">{documentDetailQuery.data.title}</p>
                  <p className="text-xs text-gray-500 dark:text-gray-400">
                    {documentDetailQuery.data.company.symbol} · {documentDetailQuery.data.uploaded_at}
                    {documentDetailQuery.data.char_count ? ` · ${documentDetailQuery.data.char_count.toLocaleString('fr-FR')} caractères extraits` : ''}
                  </p>
                </div>
                {documentDetailQuery.data.markdown_status === 'success' && (
                  <Button variant="secondary" onClick={() => setShowRawDocumentText((v) => !v)}>
                    {showRawDocumentText ? 'Voir la version formatée' : 'Voir le texte brut'}
                  </Button>
                )}
              </div>

              <div className="flex flex-wrap items-center gap-2">
                <a
                  href={companyDocumentDownloadUrl(documentDetailQuery.data.id)}
                  target="_blank"
                  rel="noreferrer"
                  className="inline-flex w-fit items-center gap-2 rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
                >
                  <EyeIcon /> Ouvrir le PDF original
                </a>
                {documentDetailQuery.data.text_extracted && documentDetailQuery.data.markdown_status !== 'success' && documentDetailQuery.data.markdown_status !== 'processing' && (
                  <Button
                    variant="secondary"
                    onClick={() => formatDocumentMarkdownMutation.mutate(documentDetailQuery.data!.id)}
                    disabled={formatDocumentMarkdownMutation.isPending}
                  >
                    <span className="flex items-center gap-2">
                      <RetryIcon spinning={formatDocumentMarkdownMutation.isPending} />
                      {documentDetailQuery.data.markdown_status === 'failed' ? 'Réessayer le formatage' : 'Formater en tableaux'}
                    </span>
                  </Button>
                )}
              </div>

              {documentDetailQuery.data.markdown_status === 'processing' && (
                <p className="text-xs text-amber-600 dark:text-amber-400">
                  Formatage en cours (peut prendre plusieurs minutes)… la page se met à jour automatiquement.
                </p>
              )}
              {documentDetailQuery.data.markdown_status === 'failed' && (
                <ErrorState message={documentDetailQuery.data.markdown_error ?? "Échec du formatage markdown"} />
              )}

              {documentDetailQuery.data.markdown_status === 'success' && documentDetailQuery.data.formatted_markdown && !showRawDocumentText ? (
                <div className="max-h-[60vh] overflow-y-auto rounded-md border border-gray-200 p-3 dark:border-gray-800">
                  <ReactMarkdown
                    remarkPlugins={[remarkGfm]}
                    components={{
                      table: (props) => <table className="mb-3 w-full border-collapse text-xs" {...props} />,
                      th: (props) => <th className="border border-gray-200 bg-gray-50 px-2 py-1 text-left font-semibold dark:border-gray-700 dark:bg-gray-800" {...props} />,
                      td: (props) => <td className="border border-gray-200 px-2 py-1 dark:border-gray-700" {...props} />,
                      h1: (props) => <h1 className="mb-2 text-lg font-bold" {...props} />,
                      h2: (props) => <h2 className="mb-2 mt-4 text-base font-semibold" {...props} />,
                      h3: (props) => <h3 className="mb-1 mt-3 text-sm font-semibold" {...props} />,
                      p: (props) => <p className="mb-2 text-sm" {...props} />,
                    }}
                  >
                    {documentDetailQuery.data.formatted_markdown}
                  </ReactMarkdown>
                </div>
              ) : documentDetailQuery.data.text_extracted && documentDetailQuery.data.extracted_text ? (
                <pre className="max-h-[50vh] overflow-y-auto whitespace-pre-wrap rounded-md bg-gray-50 p-3 text-xs text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                  {documentDetailQuery.data.extracted_text}
                </pre>
              ) : (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  {documentDetailQuery.data.extraction_error
                    ? `Texte non disponible (${documentDetailQuery.data.extraction_error}).`
                    : "Texte pas encore extrait pour ce document."}
                </p>
              )}

              {documentDetailQuery.data.text_extracted && (
                <div className="mt-2 border-t border-gray-200 pt-4 dark:border-gray-800">
                  <div className="mb-3 flex items-center justify-between gap-2">
                    <h3 className="text-sm font-semibold text-gray-500 dark:text-gray-400">Analyse IA</h3>
                    <Button
                      variant="secondary"
                      disabled={analyzeDocumentMutation.isPending}
                      onClick={() => analyzeDocumentMutation.mutate({ id: documentDetailQuery.data!.id, forceRefresh: !!documentAnalysisQuery.data })}
                    >
                      <span className="flex items-center gap-2">
                        <RetryIcon spinning={analyzeDocumentMutation.isPending} />
                        {documentAnalysisQuery.data ? 'Réanalyser' : 'Analyser avec l’IA'}
                      </span>
                    </Button>
                  </div>

                  {analyzeDocumentMutation.isError && (
                    <ErrorState message={(analyzeDocumentMutation.error as Error).message} />
                  )}

                  {documentAnalysisQuery.isLoading && <LoadingState label="Vérification d'une analyse existante…" />}

                  {(analyzeDocumentMutation.data ?? documentAnalysisQuery.data)?.analysis && (
                    <div className="flex flex-col gap-3 rounded-md border border-gray-200 p-3 dark:border-gray-800">
                      {(() => {
                        const result = (analyzeDocumentMutation.data ?? documentAnalysisQuery.data)!
                        const analysis = result.analysis!
                        return (
                          <>
                            <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                              <span>{result.provider}/{result.model}{result.cached && ' · depuis le cache'}</span>
                              {analysis.valuation_assessment && (
                                <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${verdictBadgeClass(analysis.valuation_assessment.verdict)}`}>
                                  {analysis.valuation_assessment.verdict}
                                </span>
                              )}
                            </div>
                            <p className="text-sm text-gray-800 dark:text-gray-200">{analysis.executive_summary}</p>

                            {analysis.risks && analysis.risks.length > 0 && (
                              <div>
                                <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Risques</h4>
                                <ul className="flex flex-col gap-1 text-sm">
                                  {analysis.risks.map((r, i) => (
                                    <li key={i}>
                                      <span className="mr-2 rounded bg-gray-100 px-2 py-0.5 text-xs font-medium dark:bg-gray-800">{r.category}</span>
                                      {r.description}
                                    </li>
                                  ))}
                                </ul>
                              </div>
                            )}

                            {analysis.swot && (
                              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                  <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Forces</h4>
                                  <ul className="list-disc pl-4 text-sm">{analysis.swot.strengths.map((s, i) => <li key={i}>{s}</li>)}</ul>
                                </div>
                                <div>
                                  <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Faiblesses</h4>
                                  <ul className="list-disc pl-4 text-sm">{analysis.swot.weaknesses.map((s, i) => <li key={i}>{s}</li>)}</ul>
                                </div>
                              </div>
                            )}

                            <p className="text-xs italic text-gray-400">{result.disclaimer}</p>
                          </>
                        )
                      })()}
                    </div>
                  )}

                  {(analyzeDocumentMutation.data ?? documentAnalysisQuery.data)?.status === 'failed' && (
                    <ErrorState message={(analyzeDocumentMutation.data ?? documentAnalysisQuery.data)!.error_message ?? 'Échec de l’analyse'} />
                  )}
                </div>
              )}
            </div>
          )}
        </Modal>
      )}

      {editingFinancials && displayedFundamentals && (
        editFinancialsQuery.isLoading ? (
          <Modal title="Données financières" onClose={() => setEditingFinancials(false)}>
            <LoadingState label="Chargement de l'analyse à corriger…" />
          </Modal>
        ) : (
          <FinancialsEditForm
            reportId={displayedFundamentals.source_report_id}
            reportTitle={displayedFundamentals.source_report_title}
            initialKeyFinancials={editFinancialsQuery.data?.analysis?.key_financials ?? null}
            initialValuationAssessment={editFinancialsQuery.data?.analysis?.valuation_assessment ?? null}
            onClose={() => setEditingFinancials(false)}
            onSaved={() => {
              queryClient.invalidateQueries({ queryKey: ['fundamentals-list'] })
              queryClient.invalidateQueries({ queryKey: ['fundamentals-history', companyId] })
            }}
          />
        )
      )}
    </div>
  )
}
