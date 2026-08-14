/** Dernières dates de bourse connues, marché entier (api_quotes.php, action recent_trading_dates). */
export interface RecentTradingDates {
  dates: string[]
  start_date: string | null
  end_date: string | null
}

export interface IntradayPoint {
  quote_datetime: string
  price: string | number
  volume: string | number
  variation_percent: string | number | null
}

/** Un indice de marché BRVM (api_market.php, action 'list' — market_indices). */
export interface MarketIndex {
  id: number
  code: string
  name: string
  active?: number
}

/** Un point d'historique d'un indice (api_market.php, action 'history' — index_values). */
export interface IndexHistoryPoint {
  date: string
  open: string | number | null
  close: string | number | null
  high: string | number | null
  low: string | number | null
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
  /** Champs stock_quotes ajoutés pour le graphe en chandeliers (DailyQuotesChart). */
  previous_close?: string | number | null
  turnover?: string | number | null
  variation_percent?: string | number | null
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

/**
 * Historique du score de liquidité (api_quotes.php, action
 * 'liquidity_history') — même calcul que le badge "Liquidité" (fenêtre
 * glissante de `liquidity_window_days` jours calendaires, volume moyen +
 * part de jours sans transaction), mais un point par jour de bourse au lieu
 * d'un seul badge pour toute la période.
 */
export interface LiquidityHistorySeries {
  company_id: number
  symbol: string
  name: string
  data: {
    date: string
    avg_volume: number
    zero_volume_ratio: number
    window_trading_days: number
    liquidity: 'Illiquide' | 'Faible' | 'Moyenne' | 'Élevée'
  }[]
}

/**
 * Statistiques agrégées des analyses IA déjà réalisées pour les rapports
 * d'une entreprise (api_report_analysis.php, action 'stats') — recalculées
 * à chaque appel à partir de company_report_analyses, donc reflètent
 * automatiquement tout nouveau rapport analysé (pas un instantané figé).
 * Une seule analyse (la plus récente réussie) est comptée par rapport.
 */
export interface ReportAnalysisStats {
  company_id: number
  /** true si les documents complémentaires ont été inclus dans cet appel (paramètre include_documents). */
  documents_included: boolean
  total_reports: number
  analyzed_reports: number
  pending_reports: number
  /** Toujours à 0 quand documents_included=false (non calculé côté backend dans ce cas). */
  total_documents: number
  analyzed_documents: number
  pending_documents: number
  verdict_distribution: { verdict: string; count: number }[]
  risk_category_distribution: { category: string; count: number }[]
  financial_trend: {
    source_type: 'report' | 'document'
    source_id: number
    source_title: string
    /** Date de publication pour un rapport officiel, date d'ajout (uploaded_at) pour un document complémentaire. */
    publish_date: string | null
    revenue: number | null
    net_income: number | null
    net_margin_percent: number | null
    roe_percent: number | null
    pe_ratio: number | null
    verdict: string | null
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
 * Score composite 0-100 (api_composite_score.php, action 'compute') —
 * synthèse pondérée de 6 dimensions (Fondamental 30%, Technique 25%,
 * Momentum 15%, Liquidité 10%, Secteur 10%, Marché 10%), PAS un signal
 * d'achat/vente. `coverage_percent` = somme des poids réellement
 * calculables (une entreprise sans fondamentaux disponibles a un score
 * renormalisé sur les 70% restants, jamais pénalisée par un 0 silencieux)
 * — toujours afficher ce chiffre à côté du score, une couverture faible
 * rend la comparaison entre deux entreprises moins fiable.
 */
export interface CompositeScoreRow {
  company_id: number
  symbol: string
  name: string
  sector_id: number | null
  sector: string | null
  composite_score: number | null
  coverage_percent: number
  sub_scores: {
    fundamental: number | null
    technical: number | null
    momentum: number | null
    liquidity: number | null
    sector: number | null
    market: number | null
  }
  weights: { fundamental: number; technical: number; momentum: number; liquidity: number; sector: number; market: number }
  close_price: string | number | null
  variation_percent: string | number | null
  period_performance_percent: number | null
  benchmark_return_percent: number | null
  liquidity: 'Illiquide' | 'Faible' | 'Moyenne' | 'Élevée' | null
  signal_score: number | null
  signal_label: string
  sector_rank: number | null
  sector_size: number | null
  fundamentals_available: boolean
}

/** Un type d'annonce du registre (api_issuer_announcements.php, action 'types'). */
export interface IssuerAnnouncementType {
  key: string
  label: string
  url: string
  has_company_column: boolean
}

/** Une annonce émetteur/publication BRVM (api_issuer_announcements.php, action 'list'). */
export interface IssuerAnnouncementRow {
  id: number
  announcement_type: string
  publish_date: string | null
  company_name_raw: string | null
  company_id: number | null
  company_symbol: string | null
  company_name: string | null
  match_confidence: 'exact' | 'fuzzy' | null
  title: string
  file_url: string
  text_extracted: number
  extraction_error: string | null
  markdown_status: 'processing' | 'success' | 'failed' | null
  char_count: number | null
  analyses_count: number
}

/** Détail d'une annonce, texte et markdown inclus (action 'get'). */
export interface IssuerAnnouncementDetail {
  id: number
  announcement_type: string
  type_label: string
  publish_date: string | null
  company_name_raw: string | null
  company: { id: number; symbol: string; name: string } | null
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

/** Analyse IA structurée d'une annonce (actions 'analyze'/'get_analysis'). */
export interface IssuerAnnouncementAnalysis {
  announcement: { id: number; title: string; announcement_type: string; publish_date: string | null }
  provider: string
  model: string
  status: 'success' | 'failed'
  error_message: string | null
  analysis: {
    summary: string
    key_points: string[]
    important_dates: { date: string; event: string }[]
    amounts: { label: string; value: string }[]
    potential_market_relevance: string | null
    glossary: { term: string; explanation: string }[]
  } | null
  disclaimer: string
  cached: boolean
  created_at: string | null
  updated_at: string | null
}

/**
 * Un événement du journal d'informations d'une entreprise
 * (api_company_market_events.php) — saisi manuellement ou trouvé par la
 * recherche IA puis confirmé par l'utilisateur. `impact_assessment` est le
 * jugement de l'utilisateur, jamais rempli par l'IA.
 */
export interface CompanyMarketEvent {
  id: number
  company_id: number
  title: string
  description: string
  event_date: string | null
  source_type: 'utilisateur' | 'ia_recherche'
  source_url: string | null
  impact_assessment: 'positif' | 'negatif' | 'neutre' | 'indetermine' | null
  created_by_admin_user_id: number | null
  created_by_username: string | null
  created_at: string
  updated_at: string
}

/** Un candidat d'événement proposé par la recherche IA — RIEN n'est stocké sans confirmation humaine. */
export interface MarketEventCandidate {
  title: string
  description: string
  event_date: string | null
  source_url: string | null
}

/** api_company_market_events.php, action 'search'. */
export interface MarketEventSearchResult {
  company_id: number
  provider: string
  model: string
  candidates: MarketEventCandidate[]
  sources: { title: string | null; url: string }[]
  /** Texte brut de la veille — contient l'avertissement explicite si la recherche web était indisponible (quota/dépréciation fournisseur). */
  raw_text: string
  disclaimer: string
}

/** Sous-scores 0-100 (ou null si donnée indisponible) — voir class/CompositeScoreCalculator.php. */
export interface PortfolioSubScores {
  fundamental: number | null
  technical: number | null
  momentum: number | null
  liquidity: number | null
  sector: number | null
  market: number | null
}

/**
 * Une position de "Mon Équipe BRVM" (api_portfolio.php, action 'team') —
 * simulée (target_amount_fcfa, aucun achat réel) ou réelle (quantity/
 * average_purchase_price renseignés). `role` est calculé automatiquement
 * depuis les sous-scores sauf si `role_override` est posé ; `role_partial`
 * signale une classification faite sans fondamentaux disponibles.
 */
export interface PortfolioHolding {
  id: number
  company_id: number
  symbol: string
  name: string
  sector: string | null
  status: 'simule' | 'achete'
  target_amount_fcfa: number | null
  quantity: number | null
  average_purchase_price: number | null
  purchase_date: string | null
  role_override: 'gardien' | 'defense' | 'milieu' | 'attaque' | null
  role: 'gardien' | 'defense' | 'milieu' | 'attaque'
  role_partial: boolean
  composite_score: number | null
  coverage_percent: number
  sub_scores: PortfolioSubScores
  close_price: number | null
  position_value_fcfa: number
  thesis: { buy_reason: string | null; exit_criteria: string | null } | null
}

export interface PortfolioLine {
  score: number | null
  value_fcfa: number
  count: number
}

/** api_portfolio.php, action 'team'. */
export interface PortfolioTeamResult {
  cash_reserve: { amount: number; currency: string }
  holdings: PortfolioHolding[]
  lines: { defense: PortfolioLine; milieu: PortfolioLine; attaque: PortfolioLine }
  total_portfolio_value_fcfa: number
  total_value_with_cash_fcfa: number
  balance_score: number
  alerts: { type: string; severity: 'info' | 'warning'; message: string }[]
}

export interface PortfolioSuggestionCandidate {
  company_id: number
  symbol: string
  name: string
  sector: string | null
  composite_score: number | null
  coverage_percent: number
  sub_scores: PortfolioSubScores
  suggested_amount_fcfa: number | null
}

/** Un joueur de la proposition d'équipe automatique (api_portfolio.php, action 'propose_team'). */
export interface PortfolioProposedPlayer extends PortfolioSuggestionCandidate {
  /** Justification déterministe construite depuis les sous-scores — jamais générée par IA. */
  reason: string
  /** Règle de classement réellement déclenchée, avec les vrais chiffres du titre (vérifiable contre les sous-scores). */
  role_rule: string
}

/**
 * api_portfolio.php, action 'propose_team' — XI complet en 4-3-3 proposé
 * par l'analyse (max 2 titres par secteur sur toute l'équipe), avec réserve
 * gardien conseillée à 10% du budget. `notes` signale honnêtement une ligne
 * incomplète plutôt que de forcer un titre inadapté.
 */
export interface PortfolioTeamProposal {
  /** Id de la proposition historisée (portfolio_team_proposals) — notation par étoiles et suppression possibles. */
  id: number
  /** 'algorithme' = sélection déterministe ; 'ia' = équipe composée par l'IA (garde-fous serveur appliqués). */
  origin: 'algorithme' | 'ia'
  provider: string | null
  model: string | null
  /** Raisonnement global de l'IA (origin='ia' uniquement). */
  commentary: string | null
  rating?: number | null
  created_at?: string
  profile: 'prudent' | 'equilibre' | 'dynamique'
  target_weights: { defense: number; milieu: number; attaque: number }
  budget_fcfa: number | null
  reserve_fcfa: number | null
  invest_fcfa: number | null
  formation: { defense: number; milieu: number; attaque: number }
  team: {
    defense: PortfolioProposedPlayer[]
    milieu: PortfolioProposedPlayer[]
    attaque: PortfolioProposedPlayer[]
  }
  /** Remplaçants par rôle (meilleurs candidats non retenus dans le XI) — un remplaçant hérite du montant du joueur qu'il remplace. */
  bench: {
    defense: PortfolioProposedPlayer[]
    milieu: PortfolioProposedPlayer[]
    attaque: PortfolioProposedPlayer[]
  }
  notes: string[]
}

/**
 * Une proposition actionnable du coach IA (api_portfolio.php, action
 * 'ai_review') — déjà validée côté serveur (un ajout référence forcément
 * un titre du menu fourni à l'IA, un retrait/ajustement une position
 * réellement détenue). Reste TOUJOURS à valider par l'utilisateur avant
 * application.
 */
export interface PortfolioAiProposal {
  action: 'ajouter' | 'retirer' | 'ajuster_montant' | 'ajuster_reserve'
  company_id: number | null
  holding_id: number | null
  symbol: string | null
  amount_fcfa: number | null
  rationale: string
}

/** api_portfolio.php, action 'ai_review' — avis du coach IA sur l'équipe (historisé automatiquement). */
export interface PortfolioAiReview {
  id: number
  created_at: string
  provider: string
  model: string
  overall_opinion: string
  strengths: string[]
  weaknesses: string[]
  proposals: PortfolioAiProposal[]
  /** Propositions IA écartées par le garde-fou serveur (titre hors menu, position inexistante...). */
  dropped_proposals_count: number
  disclaimer: string
}

/** Résumé d'un avis historisé (api_portfolio.php, action 'list_reviews'). */
export interface PortfolioAiReviewSummary {
  id: number
  provider: string
  model: string
  created_at: string
  proposals_count: number
  balance_score_at_review: number | null
  holdings_count_at_review: number | null
  opinion_excerpt: string
}

/**
 * Avis historisé complet (api_portfolio.php, action 'get_review') — avec
 * le snapshot de l'équipe AU MOMENT de l'avis (indispensable pour
 * l'interpréter : l'équipe a pu changer depuis). Lecture seule : les
 * propositions d'un avis passé ne sont jamais ré-applicables.
 */
export interface PortfolioAiReviewDetail extends PortfolioAiReview {
  team_snapshot: PortfolioTeamResult | null
}

/** Résumé d'une proposition d'équipe historisée (api_portfolio.php, action 'list_team_proposals'). */
export interface PortfolioTeamProposalSummary {
  id: number
  origin: 'algorithme' | 'ia'
  provider: string | null
  model: string | null
  profile: string
  budget_fcfa: number | null
  rating: number | null
  players_count: number
  commentary_excerpt: string
  created_at: string
}

/** api_portfolio.php, action 'suggestions'. */
export interface PortfolioSuggestionsResult {
  profile: 'prudent' | 'equilibre' | 'dynamique'
  target_weights: { defense: number; milieu: number; attaque: number }
  budget_fcfa: number | null
  candidates: {
    defense: PortfolioSuggestionCandidate[]
    milieu: PortfolioSuggestionCandidate[]
    attaque: PortfolioSuggestionCandidate[]
  }
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
  /** Position vendeuse simulée indépendamment (entrées/sorties inversées de la stratégie longue), pas un simple miroir de buy-and-hold. */
  short_equity_base100: number
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
  short_return_percent: number | null
  open_position: { entry_date: string; entry_price: number } | null
  /** Position vendeuse (vente à découvert) simulée indépendamment avec sa propre logique d'entrée/sortie inversée. */
  short_trades: BacktestTrade[]
  short_total_trades: number
  short_winning_trades: number
  short_win_rate_percent: number | null
  short_avg_trade_return_percent: number | null
  open_short_position: { entry_date: string; entry_price: number } | null
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

export interface CompanyDocumentSummary {
  id: number
  company_id: number
  title: string
  original_filename: string
  file_size: number | null
  uploaded_at: string
  text_extracted: boolean
  extraction_method: string | null
  extraction_error: string | null
  markdown_status: 'processing' | 'success' | 'failed' | null
  analyses_count: number
  analyzed_models: string[]
}

export interface CompanyDocumentDetail {
  id: number
  company: { id: number | null; symbol: string | null; name: string | null }
  title: string
  original_filename: string
  file_size: number | null
  uploaded_at: string
  text_extracted: boolean
  extraction_method: string | null
  extraction_error: string | null
  extracted_text: string | null
  char_count: number | null
  formatted_markdown: string | null
  markdown_status: 'processing' | 'success' | 'failed' | null
  markdown_error: string | null
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

/** Corps structuré partagé par ReportAnalysis et CompanyDocumentAnalysis (même schéma de sortie IA, voir class/ReportAnalysisService.php et class/CompanyDocumentAnalysisService.php). */
export interface StructuredAnalysisContent {
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
  analysis: StructuredAnalysisContent | null
  chart_data: { price_history: OhlcPoint[] }
  disclaimer: string
  cached: boolean
  created_at: string | null
  updated_at: string | null
}

/** Analyse IA d'un document complémentaire (api_company_document_analysis.php) — même forme que ReportAnalysis, source 'document' au lieu de 'report'. */
export interface CompanyDocumentAnalysis {
  id: number
  document: { id: number; title: string; uploaded_at: string | null }
  company: { id: number; symbol: string; name: string }
  provider: string
  model: string
  market_context_date: string | null
  status: 'success' | 'failed'
  error_message: string | null
  rating: number | null
  notes: string | null
  analysis: StructuredAnalysisContent | null
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

/**
 * Statistiques agrégées des analyses IA déjà réalisées sur les bulletins
 * (api_bulletin_analysis.php, action 'stats') — portée marché entier, pas
 * une entreprise en particulier.
 */
export interface BulletinAnalysisStats {
  total_bulletins: number
  analyzed_bulletins: number
  pending_bulletins: number
  sentiment_distribution: { sentiment: string; count: number }[]
  market_trend: {
    bulletin_id: number
    publish_date: string | null
    sentiment: 'haussier' | 'baissier' | 'neutre' | 'mixte' | null
    total_volume: number | null
    total_turnover: number | null
    advancers_count: number | null
    decliners_count: number | null
    unchanged_count: number | null
  }[]
  start_date: string | null
  end_date: string | null
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

/**
 * Un tour de la conversation avec l'assistant IA du tableau de bord
 * entreprise (api_company_chat.php) — 'sources' n'est renseigné que pour
 * role='assistant' quand le fournisseur IA a effectué une recherche
 * internet (voir class/AiChatClientInterface.php côté backend).
 */
export interface CompanyChatMessage {
  id: number
  company_id: number
  role: 'user' | 'assistant'
  content: string
  provider: 'gemini' | 'anthropic' | 'grok' | null
  model: string | null
  sources: { title: string | null; url: string }[]
  created_at: string
}

// ---------------------------------------------------------------------------
// Carnet d'ordres & liquidité (api_order_book.php — voir TODO_CARNET_ORDRES.md)
// Convention d'étiquetage : 🟦 observé (lu à la source), 🟨 calculé
// (arithmétique exacte), 🟧 estimé (hypothèse explicite) — le backend
// renvoie ces natures, le frontend DOIT les afficher.
// ---------------------------------------------------------------------------

/** Photographie du carnet fin de séance (Bulletin Officiel de la Cote). */
export interface OrderBookSnapshotRow {
  id: number
  company_id: number
  snapshot_datetime: string
  snapshot_date: string
  source: string
  bulletin_id: number | null
  best_bid_price: string | null
  best_ask_price: string | null
  bid_at_market: number
  ask_at_market: number
  bid_residual_qty: number | null
  ask_residual_qty: number | null
  reference_price: string | null
  spread_abs: string | null
  spread_percent: string | null
  imbalance_ratio: string | null
  delta_bid_qty: number | null
  delta_ask_qty: number | null
  executed_volume_day: number | null
  reading: string | null
}

export interface OrderBookSnapshotsResult {
  snapshots: OrderBookSnapshotRow[]
  source: string
}

/** Intervalle d'exécution intraday (delta du volume cumulé brvm.org). */
export interface ExecutionIntervalRow {
  trading_date: string
  interval_start: string
  interval_end: string
  price_start: string | number | null
  price_end: string | number | null
  executed_volume: number
  executed_value: string | number | null
  price_direction: number
  pressure_side: 'achat' | 'vente' | null
  is_closing_auction: number
  live: number
}

export interface ExecutionFlowResult {
  intervals: ExecutionIntervalRow[]
  note: string
}

export interface PressureDayRow {
  trading_date: string
  total_volume: string
  buy_volume: string
  sell_volume: string
  neutral_volume: string
  total_value: string | null
  net_volume: number
  dominant: 'achat' | 'vente' | 'equilibre'
}

export interface PressureResult {
  days: PressureDayRow[]
  note: string
}

export interface HeatmapCell {
  trading_date: string
  slot: string
  executed_volume: string
  net_pressure: string
}

export interface HeatmapResult {
  cells: HeatmapCell[]
  slot_minutes: number
  note: string
}

export interface AbsorptionDayRow {
  trading_date: string
  offered_prev_close: number | null
  executed_volume: number | null
  absorption_rate_percent: number | null
  reading: string | null
}

export interface AbsorptionResult {
  days: AbsorptionDayRow[]
  note: string
}

export interface OrderBookTimelineEvent {
  datetime: string
  kind: 'execution' | 'cloture' | 'carnet'
  nature: string
  label: string
  value: number
}

export interface OrderBookTimelineResult {
  events: OrderBookTimelineEvent[]
}

export interface LiquiditySellEstimate {
  quantity: number
  median_daily_volume?: number
  active_days_basis?: number
  estimated_hours?: number | null
  estimated_sessions?: number | null
  last_bid_residual?: { date: string; qty: number } | null
  formula?: string
  estimate?: null
  reason?: string
}

export interface LiquidityScoreResult {
  score: number | null
  coverage_percent: number
  sub_scores: Record<string, number | null>
  weights: Record<string, number>
  details: Record<string, Record<string, number | string>>
  sell_estimate: LiquiditySellEstimate | null
  verdict: string
  disclaimer: string
}

export interface OrderBookAnomalyRow {
  date: string
  metric: string
  value: number
  unit: string
  average: number
  z_score: number
  direction: 'hausse' | 'baisse'
}

export interface OrderBookAnomaliesResult {
  anomalies: OrderBookAnomalyRow[]
  threshold: number
  note: string
}

/** Données réelles autour du dernier fixing de clôture (action fixing_preview). */
export interface FixingPreviewResult {
  last_fixing: {
    trading_date: string
    fixing_volume: number
    fixing_price: number | null
    day_volume: number | null
    fixing_share_percent: number | null
    coverage_caveat: boolean
  } | null
  residual_book: {
    snapshot_date: string
    best_bid_price: string | null
    best_ask_price: string | null
    bid_at_market: number
    ask_at_market: number
    bid_residual_qty: number | null
    ask_residual_qty: number | null
    reference_price: string | null
    spread_percent: string | null
    imbalance_ratio: string | null
  } | null
  same_date: boolean
  next_session: {
    reference_price: string | null
    spread_percent: string | null
    imbalance_ratio: string | null
    immediate_sell_capacity: { qty: number; price: string | null; at_market: number } | null
    immediate_buy_capacity: { qty: number; price: string | null; at_market: number } | null
  } | null
  note: string
}

/** Comparateur de liquidité inter-entreprises (action compare). */
export interface LiquidityCompareCompany {
  company_id: number
  symbol: string
  name: string
  score: number | null
  coverage_percent: number
  sub_scores: Record<string, number | null>
}

export interface LiquidityCompareSeriesDay {
  date: string
  executed_volume: number | null
  buy_volume: number | null
  sell_volume: number | null
  net_pressure: number | null
  /** Pression nette normalisée -100..+100 : comparable entre titres de tailles différentes. */
  net_pressure_percent: number | null
  spread_percent: number | null
  imbalance_ratio: number | null
  bid_residual_qty: number | null
  ask_residual_qty: number | null
}

export interface LiquidityCompareResult {
  companies: LiquidityCompareCompany[]
  series: { company_id: number; days: LiquidityCompareSeriesDay[] }[]
  weights: Record<string, number>
  note: string
}

/** Classement marché sur les dimensions du moteur de liquidité (action ranking). */
export interface LiquidityRankingRow {
  company_id: number
  symbol: string
  name: string
  liquidity_score: number | null
  coverage_percent: number
  sub_scores: Record<string, number | null>
  executed_volume: number
  executed_value: number
  active_days: number
  buy_volume: number
  sell_volume: number
  net_pressure: number
  sell_pressure_percent: number | null
  buy_pressure_percent: number | null
  avg_ask_qty: number | null
  avg_bid_qty: number | null
  avg_spread_percent: number | null
  avg_imbalance: number | null
  last_book_date: string | null
  last_bid_qty: number | null
  last_ask_qty: number | null
  /** Volume ÉCHANGÉ lors de la dernière séance cotée (transactions conclues). */
  last_session_volume: number | null
  last_session_date: string | null
}

export interface LiquidityRankingResult {
  rows: LiquidityRankingRow[]
  weights: Record<string, number>
  period: { start_date: string; end_date: string }
  note: string
}

// ---------------------------------------------------------------------------
// Dividendes (api_dividends.php) et volatilité (api_volatility.php)
// ---------------------------------------------------------------------------

export interface DividendCoverage {
  distinct_payments: number
  companies: number
  first_date: string | null
  last_date: string | null
  bulletins_processed: number
}

export interface DividendRankingRow {
  company_id: number
  symbol: string
  name: string
  total_amount: number
  payments: number
  last_amount: number | null
  last_date: string | null
  first_date: string | null
  last_price: number | null
  price_date: string | null
  yield_percent: number | null
  total_yield_percent: number | null
}

export interface DividendRankingResult {
  rows: DividendRankingRow[]
  without_dividend: { company_id: number; symbol: string; name: string }[]
  months: number
  coverage: DividendCoverage
  note: string
}

export interface DividendCalendarEntry {
  company_id: number
  symbol: string
  name: string
  event_date: string
  amount: number
  currency: string
  description: string | null
  days_from_today: number
}

export interface DividendCalendarResult {
  upcoming: DividendCalendarEntry[]
  past: DividendCalendarEntry[]
  monthly: { month: string; total_amount: number; payments: number }[]
  coverage: DividendCoverage
  note: string
}

export interface VolatilityRow {
  company_id: number
  symbol: string
  name: string
  trading_days: number
  returns_count: number
  low_confidence: boolean
  daily_volatility_percent: number | null
  annualized_volatility_percent: number | null
  avg_amplitude_percent: number | null
  max_drawdown_percent: number | null
  max_drawdown_peak_date: string | null
  max_drawdown_trough_date: string | null
  net_return_percent: number | null
}

export interface VolatilityRankingResult {
  rows: VolatilityRow[]
  skipped: { symbol: string; name: string; trading_days: number }[]
  period: { start_date: string; end_date: string }
  min_reliable_returns: number
  note: string
}

export interface VolatilitySeriesPoint {
  date: string
  close: number
  rolling_volatility_percent: number | null
  drawdown_percent: number | null
}

export interface VolatilitySeriesResult {
  series: {
    company_id: number
    symbol: string
    name: string
    returns_count: number
    low_confidence: boolean
    window_reached: boolean
    points: VolatilitySeriesPoint[]
  }[]
  window: number
  min_reliable_returns: number
  note: string
}

export interface RiskReturnPoint {
  company_id: number
  symbol: string
  name: string
  volatility_percent: number
  return_percent: number | null
  max_drawdown_percent: number | null
  returns_count: number
  low_confidence: boolean
  return_per_risk: number | null
}

export interface RiskReturnResult {
  points: RiskReturnPoint[]
  skipped: { symbol: string; trading_days: number }[]
  period: { start_date: string; end_date: string }
  min_reliable_returns: number
  note: string
}

export interface VolatilityDistributionResult {
  symbol?: string
  name?: string
  bins: { from: number; to: number; count: number }[]
  returns_count: number
  low_confidence: boolean
  var_percent: number | null
  cvar_percent: number | null
  confidence?: number
  worst_day: { date: string; variation_percent: number } | null
  best_day: { date: string; variation_percent: number } | null
  note: string
}

/** Comparaison des dividendes entre entreprises (api_dividends.php, action compare). */
export interface DividendComparePayment {
  event_date: string
  amount: number
  yield_percent: number | null
  description: string | null
}

export interface DividendCompareCompany {
  company_id: number
  symbol: string
  name: string
  last_price: number | null
  price_date: string | null
  payments: DividendComparePayment[]
  payments_count: number
  total_amount: number
  last_amount: number | null
  last_date: string | null
  yield_percent: number | null
  total_yield_percent: number | null
}

export interface DividendCompareResult {
  companies: DividendCompareCompany[]
  months: number
  coverage: DividendCoverage
  note: string
}

/** Simulateur de file d'attente et de stratégie de vente (api_order_book.php, action sell_simulation). */
export interface SellSimulationResult {
  company: { company_id: number; symbol: string; name: string }
  quantity: number
  group_quantity: number
  book: {
    snapshot_date: string
    best_bid_price: number | null
    best_ask_price: number | null
    bid_residual_qty: number | null
    ask_residual_qty: number | null
    reference_price: number | null
    spread_percent: number | null
    bid_at_market: number
    ask_at_market: number
  } | null
  tick_size: number | null
  median_daily_volume: number | null
  active_days_basis: number
  queue_position: {
    status: 'premier' | 'derriere' | 'inconnu'
    ahead_of_me: number | null
    sessions_to_reach_front: number | null
    buyers_waiting: number | null
  }
  scenarios: {
    immediate: {
      price: number
      served_immediately: number | null
      remaining_after: number | null
      cost_per_share: number | null
      total_cost: number | null
      cost_percent: number | null
      queue_ahead: number
    } | null
    queue: {
      price: number
      queue_ahead: number | null
      sessions_to_reach_front: number | null
      sessions_to_complete: number | null
      cost_per_share: number
      total_cost: number
    } | null
    undercut: {
      price: number
      jumped_ahead_of: number | null
      cost_per_share: number
      total_cost: number
      cost_percent: number
      still_above_bid: boolean | null
      sessions_to_complete: number | null
    } | null
  }
  daily_limit: { percent: number; floor_price: number | null; max_undercut_per_share: number | null }
  group_impact: {
    total_to_sell: number
    median_daily_volume: number
    days_of_volume: number
    percent_of_daily_volume: number
    level: 'faible' | 'modere' | 'eleve' | 'critique'
    my_share_percent: number | null
  } | null
  stagger: {
    tranche_per_session: number
    sessions_needed: number
    group_sessions_needed: number
    basis_percent_of_daily_volume: number
  } | null
  limits: string[]
}
