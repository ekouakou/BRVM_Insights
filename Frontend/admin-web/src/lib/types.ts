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
  report: { id: number; title: string; report_type: string; publish_date: string | null }
  company: { id: number; symbol: string; name: string }
  provider: string
  model: string
  market_context_date: string | null
  status: 'success' | 'failed'
  error_message: string | null
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
  company_ids: number[]
  report_ids: number[]
  start_date: string
  end_date: string
  report_type: string | null
  provider: string
  model: string
  status: 'success' | 'failed'
  error_message: string | null
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

export interface CombinedAnalysisResult {
  report_ids: number[]
  bulletin_ids: number[]
  provider: string
  model: string
  status: 'success' | 'failed'
  error_message: string | null
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
}
