export interface IntradayPoint {
  quote_datetime: string
  price: string | number
  volume: string | number
  variation_percent: string | number | null
}

export interface CompanyQuoteRow {
  symbol: string
  name: string
  close_price: string | number
  variation_percent: string | number
  volume: string | number
  turnover: string | number
}

/** Classement par volume total sur une période (api_quotes.php, action volume_ranking). */
export interface VolumeRankingRow {
  company_id: number
  symbol: string
  name: string
  sector: string | null
  /** Actions en circulation (émises) — PAS un stock "à vendre", aucun carnet d'ordres public à la BRVM. Null si non renseigné. */
  shares_outstanding: number | null
  /** "Vendu" — volume total échangé sur la période. */
  total_volume: number
  total_turnover: number
  trading_days: number
  /** total_volume / shares_outstanding × 100. Null si shares_outstanding inconnu. */
  turnover_percent: number | null
  /** "Restant" — estimation basse (shares_outstanding - total_volume, plafonnée à 0). Null si shares_outstanding inconnu. */
  shares_remaining_estimate: number | null
  /** true si le volume échangé dépasse les actions en circulation (capital retradé au moins 1x en moyenne sur la période). */
  fully_rotated: boolean
}

/** Classement par performance de cours sur une période (api_quotes.php, action performance_ranking). */
export interface PerformanceRankingRow {
  company_id: number
  symbol: string
  name: string
  sector: string | null
  first_close_price: number | null
  last_close_price: number | null
  /** (last_close_price - first_close_price) / first_close_price × 100. Null si aucune cotation sur la période. */
  variation_percent: number | null
  trading_days: number
}

export interface MarketOverview {
  date: string
  statistics: {
    total_companies: number
    total_volume: string | number
    total_turnover: string | number
    avg_variation: string | number
    gainers_count: number
    losers_count: number
    unchanged_count: number
    max_gain: string | number
    max_loss: string | number
  }
  top_gainers: CompanyQuoteRow[]
  top_losers: CompanyQuoteRow[]
  volume_leaders: CompanyQuoteRow[]
}

export interface Company {
  company_id: number
  symbol: string
  name: string
  sector_id?: number | null
  sector_name?: string | null
  active?: number
}

export interface OhlcPoint {
  date: string
  open: string | number
  high: string | number
  low: string | number
  close: string | number
  volume: string | number
}

/** Taux de rotation du flottant sur une période (api_quotes.php, action share_turnover). */
export interface ShareTurnover {
  company_id: number
  symbol: string
  name: string
  shares_outstanding: number | null
  total_volume_traded: number
  turnover_percent: number | null
  /** actions en circulation − volume échangé, plafonné à 0 — estimation basse, voir fully_rotated */
  shares_untouched_estimate: number | null
  /** true si le volume échangé dépasse les actions en circulation (capital retradé au moins 1x en moyenne) */
  fully_rotated: boolean
  floating_market_cap: number | null
  market_cap: number | null
  /** floating_market_cap / market_cap — part réellement disponible au marché (hors participations stratégiques) */
  floating_percent: number | null
}

export interface CompanyPriceSeries {
  company_id: number
  symbol: string
  name: string
  data: { date: string; price: string | number; volume: string | number; variation: string | number | null }[]
}

/** Une ligne de `technical_indicators` (api_technical_indicators.php, action get_indicators) — un jour donné. */
export interface TechnicalIndicatorPoint {
  company_id: number
  trading_date: string
  sma_10: string | number | null
  sma_20: string | number | null
  sma_50: string | number | null
  sma_200: string | number | null
  ema_10: string | number | null
  ema_20: string | number | null
  rsi_14: string | number | null
  macd_line: string | number | null
  macd_signal: string | number | null
  macd_histogram: string | number | null
  bb_upper: string | number | null
  bb_middle: string | number | null
  bb_lower: string | number | null
  atr_14: string | number | null
  /** Average Directional Index 14j (0-100) — force de la tendance, ne dit pas dans quel sens. Null tant que l'historique est trop court (besoin d'~29 jours). */
  adx_14: string | number | null
  /** Oscillateur stochastique %K (14j) — momentum, 0-100. Null tant que l'historique est trop court (besoin de 14 jours). */
  stoch_k: string | number | null
  /** Oscillateur stochastique %D (moyenne mobile 3j de %K). */
  stoch_d: string | number | null
  /** Rate of Change 12j (%) — momentum simple. Null tant que l'historique est trop court (besoin de 13 jours). */
  roc_12: string | number | null
  /** On-Balance Volume cumulatif — valeur absolue arbitraire (dépend du point de départ), seule la tendance de la courbe a un sens. */
  obv: string | number | null
  /** Volume Weighted Average Price du jour (intraday_quotes, se réinitialise chaque jour). Null si aucun relevé intrajournalier pour ce jour. */
  vwap: string | number | null
}

/** Variation totale (churn intrajournalier) par entreprise (api_quotes.php, action total_variation). */
export interface TotalVariationSeries {
  company_id: number
  symbol: string
  name: string
  data: {
    date: string
    total_gain_percent: string | number
    total_loss_percent: string | number
    total_variation_percent: string | number
  }[]
}

/** Largeur de marché par jour (api_quotes.php, action market_breadth). */
export interface MarketBreadthPoint {
  date: string
  gainers: number
  losers: number
  unchanged: number
  total: number
  breadth_percent: number | null
}

/** Matrice de corrélation entre entreprises (api_quotes.php, action correlation). */
export interface CorrelationResult {
  symbols: string[]
  matrix: Record<string, Record<string, number | null>>
  common_days: number
}

/** Performance ajustée au risque par entreprise (api_quotes.php, action risk_adjusted). */
export interface RiskAdjustedResult {
  company_id: number
  symbol: string
  name: string
  net_return_percent: number | null
  total_volatility_percent: number | null
  risk_adjusted_ratio: number | null
}

/**
 * Métriques de risque/performance avancées (api_risk_metrics.php, action
 * 'compute') — Sharpe/Sortino réels, Maximum Drawdown, Calmar, VaR/CVaR
 * historique, skewness/kurtosis, bêta vs indice. À distinguer de
 * `RiskAdjustedResult` (ratio simplifié existant, volatilité = churn
 * intrajournalier plutôt qu'un écart-type de rendements).
 */
export interface RiskMetricsRow {
  company_id: number
  symbol: string
  name: string
  trading_days: number
  daily_returns_count: number
  /** true si moins de 20 rendements quotidiens disponibles — toutes les métriques sauf net_return_percent sont alors null plutôt que calculées sur un échantillon trop court. */
  insufficient_history: boolean
  net_return_percent: number | null
  cagr_percent: number | null
  annualized_volatility_percent: number | null
  sharpe_ratio: number | null
  sortino_ratio: number | null
  max_drawdown_percent: number | null
  max_drawdown_peak_date: string | null
  max_drawdown_trough_date: string | null
  calmar_ratio: number | null
  var_percent: number | null
  cvar_percent: number | null
  skewness: number | null
  excess_kurtosis: number | null
  beta: number | null
  beta_common_days: number
  /** Rendement net de l'indice BRVM-COMPOSITE sur la même période (identique pour toutes les entreprises de la sélection) — la valeur concrète face à laquelle le bêta se lit. */
  benchmark_return_percent: number | null
}

/** Force relative vs indice BRVM-COMPOSITE (api_quotes.php, action relative_strength). */
export interface RelativeStrengthSeries {
  company_id: number
  symbol: string
  name: string
  data: {
    date: string
    company_variation_percent: number | null
    index_variation_percent: number | null
    relative_strength: number | null
  }[]
}

/** Alerte de prix (api_price_alerts.php). */
export interface PriceAlert {
  id: number
  company_id: number
  symbol: string
  name: string
  alert_type: 'above' | 'below' | 'change_percent'
  target_price: string | number | null
  target_percent: string | number | null
  notification_email: string | null
  notification_webhook: string | null
  triggered: number
  triggered_at: string | null
  active: number
  created_at: string
}

/** Résultat de api_price_alerts.php, action check. */
export interface PriceAlertCheckResult {
  triggered: {
    id: number
    symbol: string
    name: string
    alert_type: 'above' | 'below' | 'change_percent'
    current_price: number
    current_variation: number | null
  }[]
  checked_count: number
  triggered_count: number
}

/** Résultat d'analyse IA d'un graphe/tableau (api_chart_analysis.php). */
export interface ChartAnalysisResult {
  id: number
  chart_type: string
  parameters: Record<string, unknown>
  provider: string
  model: string
  status: 'success' | 'failed'
  error_message: string | null
  rating: number | null
  notes: string | null
  /** Période couverte par la sélection, calculée côté backend à partir de `parameters` (pas par l'IA) — null si aucun indice de période (dates/days) n'est présent dans la sélection. */
  period: string | null
  summary: string | null
  methodology_explained: string | null
  key_observations: string[]
  notable_points: string[]
  suggested_charts: SuggestedChart[]
  suggested_table: SuggestedTable | null
  disclaimer: string
  cached: boolean
  created_at: string | null
}

/**
 * Graphe complémentaire proposé par l'IA à partir des données déjà
 * envoyées (voir ChartAnalysisService::buildPrompt()) — pas de garantie
 * que x_field/series existent réellement dans les données : à valider
 * avant rendu (voir components/SuggestedChartRenderer.tsx). `label`/
 * `x_label` sont les libellés humains fournis par l'IA — `field`/`x_field`
 * ne doivent jamais être affichés tels quels (ce sont des noms de clé
 * JSON bruts, ex: "net_return_percent").
 */
export interface SuggestedChart {
  title: string
  description: string
  chart_type: 'line' | 'bar'
  x_field: string
  x_label: string
  series: { field: string; label: string }[]
}

/**
 * Tableau de synthèse proposé par l'IA (voir ChartAnalysisService::buildPrompt())
 * — contrairement à `SuggestedChart` (qui ne fait que retracer des champs
 * déjà présents dans les données), les colonnes ici peuvent être
 * synthétisées par l'IA elle-même (ex: une colonne "raison" justifiant une
 * sélection/un classement). `null` si l'IA n'a rien jugé utile d'ajouter.
 * `label` de chaque colonne = libellé humain fourni par l'IA ; `key` = clé
 * technique à utiliser pour lire chaque ligne de `rows`, jamais à afficher
 * telle quelle.
 */
export interface SuggestedTable {
  title: string
  description: string
  columns: { key: string; label: string }[]
  rows: Record<string, string | number | null>[]
}

/** Indice base 100 par secteur (api_quotes.php, action sector_performance). */
export interface SectorPerformanceSeries {
  sector_id: number
  sector_name: string
  data: { date: string; index_value: number; companies_count: number }[]
}

/** api_data_quality.php, action reconciliation. */
export interface ReconciliationIssue {
  symbol: string
  name: string
  trading_date: string
  close_price: string | number
  previous_close: string | number
  stored_variation: string | number
  computed_variation: string | number
}

/** api_data_quality.php, action price_jumps. */
export interface PriceJumpIssue {
  symbol: string
  name: string
  previous_datetime: string
  previous_price: string | number
  quote_datetime: string
  price: string | number
  jump_percent: string | number
}

/** api_data_quality.php, action missing_days. */
export interface MissingDaysIssue {
  company_id: number
  symbol: string
  name: string
  expected_days: number
  actual_days: number
  missing_days: number
}

/** Score de liquidité par entreprise (api_quotes.php, action liquidity). */
export interface CompanyLiquidity {
  company_id: number
  symbol: string
  name: string
  avg_volume: number
  zero_volume_days: number
  total_days: number
  zero_volume_ratio: number
  liquidity: 'Illiquide' | 'Faible' | 'Moyenne' | 'Élevée'
}

/** Score composite achat/vente (api_signals.php) — synthèse mécanique, pas un conseil financier. */
export interface CompanySignal {
  company_id: number
  symbol: string
  name: string
  sector: string | null
  close_price: string | number | null
  variation_percent: string | number | null
  score: number | null
  label: string
  indicators_used: number
  details: Record<string, { value?: number; signal: number; reason: string }>
  /** Score à la date de début choisie (api_signals.php, action 'list' avec start_date) — null si aucune date de début fournie ou pas de donnée à cette date. */
  score_start?: number | null
  label_start?: string | null
  /** score - score_start. Null si l'un des deux est indisponible. */
  score_change?: number | null
  /** Classement de liquidité déjà croisé côté backend (voir api_signals.php::getLiquidityByCompany()). */
  liquidity?: 'Illiquide' | 'Faible' | 'Moyenne' | 'Élevée' | null
  /** true si le score a été plafonné de ±2 à ±1 car le titre est illiquide (voir buildSignal()). */
  confidence_penalized_by_liquidity?: boolean
  atr_14?: number | null
  /** atr_14 / close_price × 100 — agitation récente du titre en % de son cours, contexte pour nuancer le signal. */
  atr_relative_percent?: number | null
}

/** Croisement de moyennes mobiles détecté (api_signals.php, action 'crossovers'). */
export interface MaCrossoverEvent {
  date: string
  pair: '10/20' | '20/50'
  /** golden = SMA rapide passe au-dessus de la lente (signal haussier classique), death = l'inverse */
  type: 'golden' | 'death'
  fast_value: number
  slow_value: number
}

/** Divergence cours/RSI détectée (api_signals.php, action 'divergence'). */
export interface RsiDivergenceEvent {
  date: string
  /** bearish = sommet de prix plus haut mais RSI plus bas (essoufflement haussier), bullish = l'inverse */
  type: 'bearish' | 'bullish'
  previous_date: string
  price: number
  previous_price: number
  rsi: number
  previous_rsi: number
}

/** Ligne du screener multi-critères (api_screener.php, action 'screen'). */
export interface ScreenerRow {
  company_id: number
  symbol: string
  name: string
  sector_id: number | null
  sector: string | null
  trading_date: string
  close_price: string | number | null
  variation_percent: string | number | null
  volume: string | number | null
  rsi_14: string | number | null
  score: number | null
  label: string
  liquidity: 'Illiquide' | 'Faible' | 'Moyenne' | 'Élevée' | null
  /** Performance de cours sur la période sélectionnée (première vs dernière clôture). */
  period_performance_percent: number | null
  /** Rang de l'entreprise au sein de son secteur par period_performance_percent (1 = meilleure performance). Null si pas de cotation sur la période. */
  sector_rank: number | null
  sector_size: number | null
}

/**
 * Ratios fondamentaux extraits par IA du dernier rapport financier traité
 * avec succès (api_fundamentals.php, action 'list') — pas un calcul
 * déterministe : dépend de ce que le rapport source a divulgué. Chaque
 * champ peut être null si le rapport source ne le mentionnait pas.
 * `source_publish_date` doit toujours être affiché à côté des chiffres
 * (fiabilité liée à la fraîcheur du rapport, pas garantie "temps réel").
 */
export interface FundamentalsRow {
  company_id: number
  symbol: string
  name: string
  sector_id: number | null
  sector: string | null
  source_report_id: number
  source_report_type: string
  source_report_title: string
  source_publish_date: string | null
  currency: string | null
  revenue: number | null
  revenue_prior_year: number | null
  revenue_growth_percent: number | null
  net_income: number | null
  net_income_prior_year: number | null
  net_margin_percent: number | null
  gross_margin_percent: number | null
  operating_margin_percent: number | null
  ebitda_margin_percent: number | null
  roe_percent: number | null
  roa_percent: number | null
  debt_to_equity: number | null
  debt_to_ebitda: number | null
  current_ratio: number | null
  free_cash_flow: number | null
  dividend_per_share: number | null
  shares_outstanding: number | null
  eps: number | null
  book_value_per_share: number | null
  pe_ratio: number | null
  /** PER ÷ croissance du CA (%) — absent du schéma d'extraction IA existant, calculé ici. */
  peg_ratio: number | null
  price_to_book: number | null
  ev_to_ebitda: number | null
  dividend_yield_percent: number | null
  payout_ratio_percent: number | null
  valuation_verdict: string | null
  valuation_rationale: string | null
}

/** Une position ouverte/fermée simulée par le backtest. */
export interface BacktestTrade {
  entry_date: string
  entry_price: number
  exit_date: string
  exit_price: number
  return_percent: number
}

/** Un point de la courbe d'équity, base 100 au premier jour simulé. */
export interface BacktestEquityPoint {
  date: string
  strategy_equity_base100: number
  buy_hold_equity_base100: number
}

/**
 * Résultat d'une simulation de backtest (api_backtest.php, action 'run')
 * — synthèse mécanique d'une règle simple sur l'historique déjà persisté,
 * jamais un conseil en investissement. `insufficient_history` doit
 * toujours conditionner l'affichage d'un avertissement : sous 60 jours de
 * bourse simulés, le résultat n'a quasiment aucune valeur statistique.
 */
export interface BacktestResult {
  company_id: number
  symbol: string
  name: string
  rule: 'signal_score' | 'golden_cross'
  rule_params: Record<string, string | number>
  start_date: string
  end_date: string
  trading_days: number
  insufficient_history: boolean
  min_trading_days_for_reliability: number
  equity_curve: BacktestEquityPoint[]
  trades: BacktestTrade[]
  total_trades: number
  winning_trades: number
  win_rate_percent: number | null
  avg_trade_return_percent: number | null
  strategy_return_percent: number | null
  buy_hold_return_percent: number | null
  open_position: { entry_date: string; entry_price: number } | null
}

export interface SyncLog {
  id: number
  sync_type: string
  sync_status: string
  records_processed: number
  records_inserted: number
  records_updated: number
  records_failed: number
  started_at: string
  completed_at: string
}

export interface BackfillCompanyProgress {
  company_id: number
  symbol: string
  name: string
  total: number
  downloaded: number
  extracted: number
  errors: number
  pending: number
}

export interface ReportSummary {
  id: number
  report_type: string
  title: string
  publish_date: string | null
  file_url: string
  file_size: number | null
  text_extracted: boolean
  extraction_method: string | null
  extraction_error: string | null
  markdown_status: 'processing' | 'success' | 'failed' | null
  analyses_count: number
  analyzed_models: string[]
}

export interface ReportDetail {
  id: number
  company: { id: number | null; symbol: string | null; name: string | null }
  report_type: string
  title: string
  publish_date: string | null
  file_url: string
  file_size: number | null
  text_extracted: boolean
  extraction_method: string | null
  extraction_error: string | null
  extracted_text: string | null
  char_count: number | null
  formatted_markdown: string | null
  markdown_status: 'processing' | 'success' | 'failed' | null
  markdown_error: string | null
}

export interface CompanyWithReports {
  company_id: number
  symbol: string
  name: string
  brvm_report_slug: string | null
  reports_count: number
  reports_with_text: number | null
  latest_report_date: string | null
}

export interface DiscoverResult {
  company_id: number
  symbol: string
  total_on_site: number
  new_count: number
  new_reports: { id: number; title: string }[]
}

export interface CompanyMatchResult {
  total_slugs_found: number
  assigned: { symbol: string; slug: string }[]
  review: { symbol: string; suggestion: string | null; score: number }[]
}

export interface ReportProcessResult {
  id: number
  company_id: number
  symbol: string
  report_type: string
  title: string
  status: 'success' | 'failed'
  text_extracted: boolean
  extraction_method: string | null
  extraction_error: string | null
}

export interface KeyFinancials {
  currency?: string | null
  revenue?: number | null
  revenue_prior_year?: number | null
  revenue_growth_percent?: number | null
  net_income?: number | null
  net_income_prior_year?: number | null
  net_margin_percent?: number | null
  ebitda?: number | null
  roe_percent?: number | null
  roa_percent?: number | null
  [key: string]: unknown
}

export interface ValuationAssessment {
  shares_outstanding?: number | null
  eps?: number | null
  pe_ratio?: number | null
  price_to_book?: number | null
  ev_to_ebitda?: number | null
  dividend_yield_percent?: number | null
  payout_ratio_percent?: number | null
  verdict?: string
  rationale?: string
}

export interface ReportAnalysis {
  id: number
  report: { id: number; title: string; report_type: string; publish_date: string | null }
  company: { id: number; symbol: string; name: string }
  provider: string
  model: string
  market_context_date: string | null
  status: 'success' | 'failed'
  error_message: string | null
  rating: number | null
  notes: string | null
  analysis: {
    executive_summary: string
    company_overview?: string | null
    key_financials?: KeyFinancials
    financial_analysis?: string | null
    growth_trends?: string | null
    cash_flow_analysis?: string | null
    swot?: { strengths: string[]; weaknesses: string[]; opportunities: string[]; threats: string[] }
    risks?: { category: string; description: string }[]
    governance_and_audit?: string | null
    outlook_guidance?: string | null
    market_context_note?: string | null
    technical_reading?: string | null
    valuation_assessment?: ValuationAssessment
    investment_thesis?: { bull_case: string; bear_case: string; key_watch_points: string[] }
    data_quality_note?: string | null
    glossary?: { term: string; explanation: string }[]
  } | null
  chart_data: { price_history: OhlcPoint[] }
  disclaimer: string
  cached: boolean
  created_at: string | null
  updated_at: string | null
}

export interface ComparisonResult {
  id: number
  company_ids: number[]
  report_ids: number[]
  start_date: string
  end_date: string
  report_type: string | null
  provider: string
  model: string
  status: 'success' | 'failed'
  error_message: string | null
  rating: number | null
  notes: string | null
  analysis: {
    comparative_summary: string
    trend_analysis?: { company_symbol: string; company_name: string; narrative: string; revenue_trend_percent: number | null; net_income_trend_percent: number | null }[]
    cross_company_ranking?: string | null
    price_correlation_note?: string
    risks_evolution?: string
    decision_support_notes?: { company_symbol: string; bull_case: string; bear_case: string; key_watch_points: string[] }[]
  } | null
  companies: { id: number; symbol: string; name: string }[]
  chart_data: {
    price_series: { company_id: number; symbol: string; data: OhlcPoint[] }[]
    financials_series: { company_id: number; symbol: string; data: { report_id: number; publish_date: string; report_type: string; revenue: number | null; net_income: number | null; net_margin_percent: number | null; roe_percent: number | null }[] }[]
  } | null
  disclaimer: string
  cached: boolean
  created_at: string | null
}

export interface BulletinSummary {
  id: number
  publish_date: string
  title: string
  file_url: string
  file_size: number | null
  text_extracted: boolean
  extraction_method: string | null
  extraction_error: string | null
  markdown_status: 'processing' | 'success' | 'failed' | null
  analyses_count: number
  analyzed_models: string[]
}

export interface BulletinDetail {
  id: number
  publish_date: string
  title: string
  file_url: string
  file_size: number | null
  text_extracted: boolean
  extraction_method: string | null
  extraction_error: string | null
  extracted_text: string | null
  char_count: number | null
  formatted_markdown: string | null
  markdown_status: 'processing' | 'success' | 'failed' | null
  markdown_error: string | null
}

export interface BulletinStats {
  total: number
  downloaded: number
  extracted: number
  errors: number
  pending: number
  oldest_bulletin: string | null
  newest_bulletin: string | null
}

export interface BulletinLookupResult {
  found: boolean
  already_known: boolean
  bulletin: { id: number; title: string; publish_date: string } | null
}

export interface BulletinProcessResult {
  id: number
  publish_date: string
  title: string
  status: 'success' | 'failed'
  text_extracted: boolean
  extraction_method: string | null
  extraction_error: string | null
}

export interface BulletinDiscoverResult {
  total_on_site: number
  new_count: number
  new_bulletins: { id: number; title: string; publish_date: string }[]
}

export interface BulletinAnalysis {
  bulletin: { id: number; title: string; publish_date: string }
  provider: string
  model: string
  status: 'success' | 'failed'
  error_message: string | null
  analysis: {
    session_summary: string
    notable_movements: string
    sector_trends: string | null
    anomalies_or_alerts: string | null
    sentiment: { verdict: 'haussier' | 'baissier' | 'neutre' | 'mixte'; rationale: string }
    key_figures: {
      total_volume: number | null
      total_turnover: number | null
      advancers_count: number | null
      decliners_count: number | null
      unchanged_count: number | null
    }
    glossary: { term: string; explanation: string }[]
  } | null
  disclaimer: string
  cached: boolean
  created_at: string | null
  updated_at: string | null
}

export interface BulletinComparisonResult {
  bulletin_ids: number[]
  provider: string
  model: string
  status: 'success' | 'failed'
  error_message: string | null
  analysis: {
    period_overview: string
    trend_by_index: string
    recurring_movers: string
    volume_turnover_trend: string
    sentiment_evolution: string
    key_takeaways: string[]
    glossary: { term: string; explanation: string }[]
  } | null
  bulletins: { id: number; title: string; publish_date: string }[]
  chart_data: {
    index_series: { code: string; name: string; data: { date: string; close_value: number | string; variation_percent: number | string | null }[] }[]
    key_figures_series: {
      bulletin_id: number
      publish_date: string
      total_volume: number | null
      total_turnover: number | null
      advancers_count: number | null
      decliners_count: number | null
      unchanged_count: number | null
    }[]
  } | null
  skipped_bulletins: { bulletin_id: number; reason: string }[]
  disclaimer: string
  cached: boolean
}

/**
 * Une opération sur titres extraite par IA d'un bulletin (api_bulletin_corporate_actions.php).
 * `company_id` peut être null si le nom mentionné dans le bulletin n'a pas
 * pu être rapproché avec confiance suffisante — dans ce cas se fier à
 * `company_name_raw` (texte brut du bulletin).
 */
export interface CorporateAction {
  id: number
  bulletin_id: number
  company_id: number | null
  company_symbol: string | null
  company_name: string | null
  company_name_raw: string
  match_confidence: 'exact' | 'fuzzy' | null
  action_type: 'dividende' | 'augmentation_capital' | 'admission' | 'assemblee_generale' | 'autre'
  event_date: string | null
  amount: number | string | null
  currency: string
  description: string | null
  source_section: string | null
  bulletin_publish_date?: string
  bulletin_title?: string
}

/** Bulletin dont le texte est disponible mais pas encore extrait pour les opérations sur titres. */
export interface PendingCorporateActionsBulletin {
  id: number
  title: string
  publish_date: string
}

/** api_bulletin_corporate_actions.php, action 'list'. */
export interface CorporateActionsListResult {
  actions: CorporateAction[]
  count: number
  pending_bulletins: PendingCorporateActionsBulletin[]
  pending_count: number
}

/** api_bulletin_corporate_actions.php, action 'extract'/'get'. */
export interface CorporateActionsExtractResult {
  bulletin: { id: number; title: string; publish_date: string }
  status: 'success' | 'error' | null
  error_message: string | null
  provider: string | null
  model: string | null
  actions: CorporateAction[]
  cached: boolean
  updated_at: string | null
}

export interface CombinedAnalysisResult {
  id: number
  report_ids: number[]
  bulletin_ids: number[]
  provider: string
  model: string
  status: 'success' | 'failed'
  error_message: string | null
  rating: number | null
  notes: string | null
  analysis: {
    combined_overview: string
    company_performance_notes: { company_symbol: string; company_name: string; narrative: string }[]
    market_context_summary: string
    correlation_analysis: string
    timeline_narrative: string
    decision_support_notes: { company_symbol: string; bull_case: string; bear_case: string; key_watch_points: string[] }[]
    key_takeaways: string[]
    glossary: { term: string; explanation: string }[]
  } | null
  companies: { id: number; symbol: string; name: string }[]
  reports: { id: number; title: string; publish_date: string | null }[]
  bulletins: { id: number; title: string; publish_date: string }[]
  chart_data: {
    price_series: { company_id: number; symbol: string; data: OhlcPoint[] }[]
    index_series: { code: string; name: string; data: { date: string; close_value: number | string; variation_percent: number | string | null }[] }[]
  } | null
  skipped_reports: { report_id: number; reason: string }[]
  skipped_bulletins: { bulletin_id: number; reason: string }[]
  disclaimer: string
  cached: boolean
  created_at: string | null
}
