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

export interface DiscoverResult {
  company_id: number
  symbol: string
  total_on_site: number
  new_count: number
  new_reports: { id: number; title: string }[]
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
