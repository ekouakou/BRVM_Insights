import { useState } from 'react'
import type { UseQueryResult } from '@tanstack/react-query'
import { BarChart, Bar, Cell, ResponsiveContainer, XAxis, YAxis, Tooltip, CartesianGrid } from 'recharts'
import type { CombinedDividendPoint, FundamentalsCagr, FundamentalsHistoryPoint, FundamentalsRow, ValuationModel } from '../lib/types'
import { ErrorState, SearchableSelect, StatTile, Tabs } from './ui'
import { ChartAiAnalysis } from './ChartAiAnalysis'

function fmt(n: number | string | null | undefined, digits = 2): string {
  if (n === null || n === undefined) return '—'
  const num = typeof n === 'string' ? parseFloat(n) : n
  if (Number.isNaN(num)) return '—'
  return num.toLocaleString('fr-FR', { maximumFractionDigits: digits })
}

function pct(n: number | null, digits = 2): string {
  if (n === null) return '—'
  return `${n > 0 ? '+' : ''}${n.toFixed(digits)}%`
}

/**
 * cagr_percent peut être null même quand years est renseigné (période trop
 * courte, ou valeur de départ négative/nulle) — le message explique pourquoi
 * plutôt que d'afficher un tiret nu (voir FundamentalsCagr dans lib/types.ts).
 */
function fmtCagr(cagr: FundamentalsCagr): string {
  if (cagr.cagr_percent !== null) {
    return `${pct(cagr.cagr_percent)} (${cagr.years} an${(cagr.years ?? 0) > 1 ? 's' : ''}, ${cagr.start_date} → ${cagr.end_date})`
  }
  if (cagr.start_date && cagr.end_date) {
    return `— (période trop courte ou valeur négative : ${cagr.start_date} → ${cagr.end_date})`
  }
  return '— (historique insuffisant)'
}

/** Formatte un montant en unité compacte (Md/M/K) — les 3 séries historiques n'ont pas la même échelle (CA/résultat net en milliards, dividende/action en dizaines-centaines de FCFA). */
function fmtCompact(n: number): string {
  const abs = Math.abs(n)
  if (abs >= 1_000_000_000) return `${(n / 1_000_000_000).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} Md`
  if (abs >= 1_000_000) return `${(n / 1_000_000).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} M`
  if (abs >= 1_000) return `${(n / 1_000).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} K`
  return n.toLocaleString('fr-FR', { maximumFractionDigits: 1 })
}

const FUNDAMENTALS_CHART_COLORS_KEY = 'brvm_fundamentals_chart_colors'

const DEFAULT_CHART_COLORS: Record<string, string> = {
  revenue: '#4f46e5',
  net_income: '#059669',
  total_dividend: '#f59e0b',
  dividend_per_share: '#f59e0b',
  bulletin_dividend: '#ec4899',
}

const CHART_COLOR_LABELS: Record<string, string> = {
  revenue: "Chiffre d'affaires",
  net_income: 'Résultat net',
  total_dividend: 'Dividendes totaux',
  dividend_per_share: 'Dividende/action (rapports)',
  bulletin_dividend: 'Dividende/action (bulletins)',
}

/** Libellés lisibles pour company_reports.report_type — voir le filtre "Type de rapport" des graphes de croissance (aussi réutilisé par le filtre Année/Type de rapport du Tableau de bord entreprise, voir CompanyDashboard.tsx). */
export const REPORT_TYPE_LABELS: Record<string, string> = {
  annuel: 'Annuel',
  semestriel: 'Semestriel',
  trimestriel: 'Trimestriel',
  etats_financiers: 'États financiers',
  attestation_cac: 'Attestation CAC',
  autre: 'Autre',
}

/**
 * Types de rapport distincts présents dans les séries de croissance d'une
 * entreprise — alimente le filtre "Type de rapport" : mélanger trimestriel/
 * semestriel/annuel dans un même graphe produit une courbe en dents de scie
 * (périodes de durées différentes), filtrer sur un seul type la rend lisible.
 */
function availableReportTypes(row: FundamentalsRow): string[] {
  const types = new Set<string>()
  for (const series of [row.revenue_history, row.net_income_history, row.dividend_history, row.total_dividend_history]) {
    for (const p of series) types.add(p.report_type)
  }
  return Array.from(types).sort()
}

function loadChartColorOverrides(): Record<string, string> {
  try {
    const raw = JSON.parse(localStorage.getItem(FUNDAMENTALS_CHART_COLORS_KEY) ?? '{}')
    return raw && typeof raw === 'object' ? raw : {}
  } catch {
    return {}
  }
}

/**
 * Couleurs des graphes "Croissance historique", personnalisables et
 * persistées (localStorage) — mêmes conventions que useCompanyColors()
 * (lib/companyGroups.ts) pour les courbes de comparaison, mais ici par
 * métrique (CA, résultat net...) plutôt que par entreprise : une préférence
 * globale à l'application, pas liée à l'entreprise consultée.
 */
function useFundamentalsChartColors() {
  const [overrides, setOverrides] = useState<Record<string, string>>(loadChartColorOverrides)

  function setColor(key: string, color: string | null) {
    setOverrides((prev) => {
      const next = { ...prev }
      if (color === null) {
        delete next[key]
      } else {
        next[key] = color
      }
      localStorage.setItem(FUNDAMENTALS_CHART_COLORS_KEY, JSON.stringify(next))
      return next
    })
  }

  function colorFor(key: string) {
    return overrides[key] ?? DEFAULT_CHART_COLORS[key]
  }

  return { colorFor, setColor, overrides }
}

/** Rangée de pipettes, une par métrique des graphes ci-dessous — voir useFundamentalsChartColors(). */
function ChartColorPicker({
  colorFor,
  setColor,
  overrides,
}: {
  colorFor: (key: string) => string
  setColor: (key: string, color: string | null) => void
  overrides: Record<string, string>
}) {
  return (
    <div className="flex flex-wrap items-center gap-3 text-xs">
      <span className="font-medium text-gray-700 dark:text-gray-300">Couleurs des graphes :</span>
      {Object.keys(CHART_COLOR_LABELS).map((key) => (
        <span key={key} className="flex items-center gap-1 text-gray-600 dark:text-gray-300">
          <input
            type="color"
            value={colorFor(key)}
            onChange={(e) => setColor(key, e.target.value)}
            title={CHART_COLOR_LABELS[key]}
            className="h-5 w-5 cursor-pointer rounded border border-gray-300 bg-transparent p-0 dark:border-gray-700"
          />
          {CHART_COLOR_LABELS[key]}
          {overrides[key] && (
            <button
              type="button"
              onClick={() => setColor(key, null)}
              title="Revenir à la couleur par défaut"
              className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
            >
              ↺
            </button>
          )}
        </span>
      ))}
    </div>
  )
}

/**
 * Graphe en barres d'une seule métrique dans le temps, un point par rapport
 * analysé où elle était disponible (voir FundamentalsRow.*_history,
 * alimentés par FundamentalsAPI::computeGrowthMetrics() côté backend) — un
 * graphe séparé par métrique plutôt qu'un seul combiné : CA, résultat net et
 * dividendes n'ont pas la même échelle et se lisent mieux chacun sur son
 * propre axe qu'en barres groupées ou en courbe superposée.
 */
function HistoryBarChart({
  title,
  data,
  color,
  valueFormatter,
}: {
  title: string
  data: FundamentalsHistoryPoint[]
  color: string
  valueFormatter: (v: number) => string
}) {
  return (
    <div>
      <h5 className="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">{title}</h5>
      {data.length < 2 ? (
        <p className="text-xs text-gray-400 dark:text-gray-600">Historique insuffisant pour un graphe (moins de 2 rapports avec cette donnée).</p>
      ) : (
        <ResponsiveContainer width="100%" height={180}>
          <BarChart data={data}>
            <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
            {/* dataKey="report_id" plutôt que "date" : deux rapports différents (ex. rapport annuel + états
                financiers) peuvent couvrir exactement la même date de clôture — un axe catégoriel avec des
                valeurs en double fait disparaître visuellement une des deux barres (collision Recharts), même si
                les deux points existent bien dans les données (visible en survolant). report_id est garanti
                unique ; tickFormatter réaffiche la date pour l'utilisateur. */}
            <XAxis
              dataKey="report_id"
              tick={{ fontSize: 10 }}
              minTickGap={20}
              tickFormatter={(id: number) => data.find((p) => p.report_id === id)?.date ?? ''}
            />
            <YAxis tick={{ fontSize: 10 }} width={55} tickFormatter={(v: number) => valueFormatter(v)} />
            <Tooltip
              formatter={(value) => [value === null || value === undefined ? '—' : valueFormatter(Number(value)), title]}
              labelFormatter={(_, items) => {
                const point = items[0]?.payload as FundamentalsHistoryPoint | undefined
                return point ? `${point.report_title} (${point.date})` : ''
              }}
            />
            <Bar dataKey="value" fill={color} radius={[3, 3, 0, 0]} />
          </BarChart>
        </ResponsiveContainer>
      )}
    </div>
  )
}

/**
 * Dividende par action fusionnant rapports financiers ET bulletins BRVM
 * (voir FundamentalsRow.combined_dividend_history), chaque barre colorée
 * selon sa provenance — un bulletin annonce le dividende déclaré à une date
 * précise, un rapport le dividende effectivement versé sur l'exercice ; les
 * deux peuvent légitimement diverger légèrement, d'où l'intérêt de les
 * distinguer visuellement plutôt que de les mélanger sans distinction.
 */
function CombinedDividendChart({
  data,
  colorRapport,
  colorBulletin,
}: {
  data: CombinedDividendPoint[]
  colorRapport: string
  colorBulletin: string
}) {
  if (data.length < 2) {
    return <p className="text-xs text-gray-400 dark:text-gray-600">Historique insuffisant pour un graphe (moins de 2 points, rapports et bulletins confondus).</p>
  }

  // "source-report_id" plutôt que "report_id" seul : les deux sources ont chacune leur propre espace
  // d'identifiants (id de rapport vs id d'action bulletin), une collision numérique entre les deux est possible
  // même si rare — voir le même souci de clé unique corrigé pour HistoryBarChart ci-dessus.
  const dataKeyOf = (p: CombinedDividendPoint) => `${p.source}-${p.report_id}`

  return (
    <div>
      <div className="mb-1 flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
        <span className="flex items-center gap-1">
          <span className="inline-block h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: colorRapport }} />
          Rapports
        </span>
        <span className="flex items-center gap-1">
          <span className="inline-block h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: colorBulletin }} />
          Bulletins
        </span>
      </div>
      <ResponsiveContainer width="100%" height={200}>
        <BarChart data={data}>
          <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
          <XAxis
            dataKey={dataKeyOf}
            tick={{ fontSize: 10 }}
            minTickGap={20}
            tickFormatter={(key: string) => data.find((p) => dataKeyOf(p) === key)?.date ?? ''}
          />
          <YAxis tick={{ fontSize: 10 }} width={50} tickFormatter={(v: number) => fmt(v, 0)} />
          <Tooltip
            formatter={(value, _name, item) => {
              const point = item?.payload as CombinedDividendPoint | undefined
              const label = point?.source === 'bulletin' ? 'Dividende/action (bulletin)' : 'Dividende/action (rapport)'
              return [value === null || value === undefined ? '—' : `${fmt(Number(value), 2)} FCFA`, label]
            }}
            labelFormatter={(_, items) => {
              const point = items[0]?.payload as CombinedDividendPoint | undefined
              return point ? `${point.report_title} (${point.date})` : ''
            }}
          />
          <Bar dataKey="value" radius={[3, 3, 0, 0]}>
            {data.map((p) => (
              <Cell key={dataKeyOf(p)} fill={p.source === 'bulletin' ? colorBulletin : colorRapport} />
            ))}
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}

function verdictBadgeClass(verdict: string | null) {
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

/** Une ligne {label, value} du panneau de détail — value est déjà formaté (chaîne) par l'appelant. */
function Ratio({ label, value, title }: { label: string; value: string; title?: string }) {
  return (
    <div title={title}>
      <div className="text-xs text-gray-500 dark:text-gray-400">{label}</div>
      <div className="font-medium tabular-nums">{value}</div>
    </div>
  )
}

function RatioGroup({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div>
      <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{title}</h4>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">{children}</div>
    </div>
  )
}

/**
 * DCF/DDM/EVA/WACC pour l'entreprise affichée — requête dédiée (voir
 * valuationQuery, passée par l'appelant), affichée séparément du reste de
 * la fiche car elle repose sur des hypothèses de marché explicites (voir
 * ValuationModel.assumptions) et non sur de simples ratios dérivés.
 */
function ValuationModelSection({
  companyId,
  sourceReportId,
  query,
}: {
  companyId: number
  sourceReportId: number
  query: UseQueryResult<ValuationModel>
}) {
  if (query.isLoading) {
    return <p className="text-xs text-gray-500 dark:text-gray-400">Calcul du modèle de valorisation (bêta sur l'historique de cours)…</p>
  }
  if (query.error) {
    return <ErrorState message={(query.error as Error).message} />
  }
  if (!query.data) {
    return null
  }

  const v = query.data
  const a = v.assumptions

  return (
    <div className="flex flex-col gap-4 border-t border-gray-200 pt-4 dark:border-gray-800">
      <div>
        <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
          Valorisation intrinsèque (modèle)
        </h4>
        <p className="text-xs text-gray-500 dark:text-gray-400">
          Hypothèses : taux sans risque {pct(a.risk_free_rate_percent)}, prime de risque marché {pct(a.market_risk_premium_percent)},
          imposition {pct(a.corporate_tax_rate_percent)}, croissance terminale {pct(a.terminal_growth_rate_percent)},
          projection explicite sur {a.dcf_explicit_years} ans.
        </p>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        <Ratio
          label="Bêta"
          value={fmt(v.beta, 2)}
          title={v.beta_source === 'computed' ? `Calculé sur ${v.beta_sample_days} jours de cotation communs avec l'indice` : "Historique de cours encore trop court — hypothèse neutre (bêta=1) par défaut"}
        />
        <Ratio label="Coût des capitaux propres" value={pct(v.cost_of_equity_percent)} title="CAPM : taux sans risque + bêta × prime de risque" />
        <Ratio label="Coût de la dette (après impôt)" value={pct(v.wacc.cost_of_debt_after_tax_percent)} title={v.wacc.cost_of_debt_source === 'extracted' ? "Charges d'intérêt ÷ dette totale, extraites du rapport" : "Charges d'intérêt non extraites — hypothèse taux sans risque + prime de crédit forfaitaire"} />
        <Ratio label="WACC" value={pct(v.wacc.wacc_percent)} title="Coût moyen pondéré du capital, taux d'actualisation du DCF" />
      </div>
      {v.wacc.wacc_percent === null && (
        <p className="text-xs text-amber-600 dark:text-amber-400">{v.wacc.reason}</p>
      )}

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        <Ratio label="NOPAT" value={fmt(v.roic_eva.nopat, 0)} title="Résultat opérationnel après impôt théorique" />
        <Ratio label="Capital investi" value={fmt(v.roic_eva.invested_capital, 0)} title="Dette totale + capitaux propres" />
        <Ratio label="ROIC" value={pct(v.roic_eva.roic_percent)} title="NOPAT ÷ capital investi" />
        <Ratio
          label="EVA (spread)"
          value={v.roic_eva.eva_spread_percent !== null ? `${fmt(v.roic_eva.eva, 0)} (${pct(v.roic_eva.eva_spread_percent)})` : '—'}
          title="Valeur ajoutée économique = NOPAT - (capital investi × WACC) ; le spread ROIC-WACC en % résume si l'entreprise crée de la valeur au-delà de son coût de financement"
        />
      </div>

      <div>
        <h5 className="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">DCF (flux de trésorerie actualisés)</h5>
        {!v.dcf.applicable ? (
          <p className="text-xs text-gray-500 dark:text-gray-400">{v.dcf.reason}</p>
        ) : (
          <div className="flex flex-col gap-2">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
              <Ratio label="Croissance projetée" value={pct(v.dcf.growth_rate_percent)} title={`Source : ${v.dcf.growth_rate_source}, plafonnée entre -10% et +15%`} />
              <Ratio label="Valeur terminale (VA)" value={fmt(v.dcf.present_value_terminal_value, 0)} />
              <Ratio label="Valeur d'entreprise" value={fmt(v.dcf.enterprise_value, 0)} />
              <Ratio label="Valeur des capitaux propres" value={fmt(v.dcf.equity_value, 0)} title="Valeur d'entreprise - dette nette" />
            </div>
            <div className="flex flex-wrap items-baseline gap-3 rounded-md bg-gray-100 px-3 py-2 text-sm dark:bg-gray-800">
              <span>Valeur par action : <strong>{fmt(v.dcf.value_per_share, 0)} FCFA</strong></span>
              <span className="text-gray-500 dark:text-gray-400">vs cours {fmt(v.dcf.market_price, 0)} FCFA</span>
              {v.dcf.upside_percent !== null && (
                <span className={v.dcf.upside_percent >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'}>
                  ({pct(v.dcf.upside_percent)})
                </span>
              )}
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead>
                  <tr className="text-gray-400 dark:text-gray-500">
                    <th className="pb-1 pr-3">Année</th>
                    <th className="pb-1 pr-3 text-right">FCF projeté</th>
                    <th className="pb-1 text-right">Valeur actualisée</th>
                  </tr>
                </thead>
                <tbody>
                  {v.dcf.projected_free_cash_flows.map((f) => (
                    <tr key={f.year} className="border-t border-gray-100 dark:border-gray-800">
                      <td className="py-1 pr-3">{f.year}</td>
                      <td className="py-1 pr-3 text-right tabular-nums">{fmt(f.fcf, 0)}</td>
                      <td className="py-1 text-right tabular-nums">{fmt(f.present_value, 0)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>

      <div>
        <h5 className="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">DDM (modèle de Gordon sur les dividendes)</h5>
        {!v.ddm.applicable ? (
          <p className="text-xs text-gray-500 dark:text-gray-400">{v.ddm.reason}</p>
        ) : (
          <div className="flex flex-col gap-2">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
              <Ratio label="Croissance du dividende" value={pct(v.ddm.growth_rate_percent)} title={`Source : ${v.ddm.growth_rate_source}`} />
              <Ratio label="Dividende projeté (D1)" value={fmt(v.ddm.projected_dividend_per_share, 2)} />
            </div>
            <div className="flex flex-wrap items-baseline gap-3 rounded-md bg-gray-100 px-3 py-2 text-sm dark:bg-gray-800">
              <span>Valeur par action : <strong>{fmt(v.ddm.value_per_share, 0)} FCFA</strong></span>
              <span className="text-gray-500 dark:text-gray-400">vs cours {fmt(v.ddm.market_price, 0)} FCFA</span>
              {v.ddm.upside_percent !== null && (
                <span className={v.ddm.upside_percent >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'}>
                  ({pct(v.ddm.upside_percent)})
                </span>
              )}
            </div>
          </div>
        )}
      </div>

      <p className="text-xs italic text-gray-400 dark:text-gray-600">{v.disclaimer}</p>

      {/* source_report_id dans les paramètres : la même entreprise peut afficher plusieurs rapports au fil du
          temps (filtre "Année" de la page Fondamentaux) — sans cet identifiant, l'historique/cache des analyses
          IA (filtré par chartType + parameters exacts, voir ChartAiAnalysis) mélangerait les écritures IA de
          rapports différents sous une même clé company_id. */}
      <ChartAiAnalysis chartType="valuation_model" parameters={{ company_id: companyId, source_report_id: sourceReportId }} data={v} />
    </div>
  )
}

/**
 * Onglets de la fiche détaillée — regroupe la douzaine de sections de
 * ratios par thème plutôt que tout empiler dans un seul long scroll.
 */
const FUNDAMENTALS_DETAIL_TABS = [
  { id: 'apercu', label: "Vue d'ensemble" },
  { id: 'rentabilite', label: 'Rentabilité & structure' },
  { id: 'valorisation', label: 'Valorisation' },
  { id: 'modele', label: 'Modèle DCF/DDM' },
] as const
type FundamentalsDetailTab = (typeof FUNDAMENTALS_DETAIL_TABS)[number]['id']

/**
 * Fiche détaillée des fondamentaux d'une entreprise (une quarantaine de
 * ratios regroupés par thème, historique de croissance, comparables
 * sectoriels, modèle DCF/DDM) — composant partagé entre la page
 * Fondamentaux (colonne "Détails" du tableau, avant refonte) et l'onglet
 * "Fondamentaux" du Tableau de bord entreprise, pour éviter d'avoir deux
 * versions de la même fiche à maintenir sur deux écrans différents.
 */
export function FundamentalsDetailPanel({ row, valuationQuery }: { row: FundamentalsRow; valuationQuery: UseQueryResult<ValuationModel> }) {
  const [detailTab, setDetailTab] = useState<FundamentalsDetailTab>('apercu')
  const chartColors = useFundamentalsChartColors()
  // '' = tous les types de rapport confondus — voir availableReportTypes() et le filtre "Type de rapport" ci-dessous.
  const [historyReportType, setHistoryReportType] = useState('')
  const reportTypes = availableReportTypes(row)
  const filterByReportType = (data: FundamentalsHistoryPoint[]) =>
    historyReportType === '' ? data : data.filter((p) => p.report_type === historyReportType)

  return (
    <div className="rounded-md border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-950">
      <div className="mb-3 text-xs text-gray-500 dark:text-gray-400">
        {row.symbol} — cours de référence <strong>{fmt(row.market_price, 0)} FCFA</strong>
        {row.market_context_date && ` (${row.market_context_date})`}
        {row.market_cap !== null && <> · capitalisation <strong>{fmt(row.market_cap, 0)} FCFA</strong></>}
        {row.enterprise_value !== null && <> · valeur d'entreprise <strong>{fmt(row.enterprise_value, 0)} FCFA</strong></>}
      </div>

      {/* Indicateurs clés — toujours visibles, quel que soit l'onglet actif ci-dessous. */}
      <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <StatTile label="PER" value={fmt(row.pe_ratio, 2)} />
        <StatTile label="P/B" value={fmt(row.price_to_book, 2)} />
        <StatTile label="Capitalisation" value={row.market_cap !== null ? fmtCompact(row.market_cap) : '—'} />
        <StatTile label="Rdt dividende" value={pct(row.dividend_yield_percent)} />
        <StatTile
          label="CAGR chiffre d'affaires"
          value={row.revenue_cagr.cagr_percent !== null ? pct(row.revenue_cagr.cagr_percent) : '—'}
          tone={row.revenue_cagr.cagr_percent === null ? 'default' : row.revenue_cagr.cagr_percent > 0 ? 'positive' : 'negative'}
        />
        <StatTile
          label="Verdict IA"
          value={
            <span className={`rounded-full px-2 py-0.5 text-sm font-semibold ${verdictBadgeClass(row.valuation_verdict)}`}>
              {row.valuation_verdict ?? '—'}
            </span>
          }
        />
      </div>

      <div className="mb-4">
        <Tabs tabs={[...FUNDAMENTALS_DETAIL_TABS]} active={detailTab} onChange={(id) => setDetailTab(id as FundamentalsDetailTab)} />
      </div>

      {detailTab === 'apercu' && (
        <div className="flex flex-col gap-5">
          <RatioGroup title="Compte de résultat">
            <Ratio label="Chiffre d'affaires" value={fmt(row.revenue, 0)} />
            <Ratio label="CA exercice précédent" value={fmt(row.revenue_prior_year, 0)} />
            <Ratio label="Croissance CA" value={pct(row.revenue_growth_percent)} />
            <Ratio label="Marge brute" value={fmt(row.gross_profit, 0)} />
            <Ratio label="Marge brute %" value={pct(row.gross_margin_percent)} />
            <Ratio label="Résultat opérationnel" value={fmt(row.operating_income, 0)} />
            <Ratio label="Marge opérationnelle" value={pct(row.operating_margin_percent)} />
            <Ratio label="EBITDA" value={fmt(row.ebitda, 0)} />
            <Ratio label="Marge EBITDA" value={pct(row.ebitda_margin_percent)} />
            <Ratio label="Résultat net" value={fmt(row.net_income, 0)} />
            <Ratio label="Résultat net (N-1)" value={fmt(row.net_income_prior_year, 0)} />
            <Ratio label="Croissance résultat net" value={pct(row.net_income_growth_percent)} />
            <Ratio label="Marge nette" value={pct(row.net_margin_percent)} />
          </RatioGroup>

          <RatioGroup title="Croissance historique">
            <div className="col-span-2 sm:col-span-3 lg:col-span-4 -mb-1 text-xs text-gray-400 dark:text-gray-500">
              Calculée sur {row.historical_reports_count} rapport(s) analysé(s) au total pour cette entreprise (pas seulement le plus récent).
            </div>
            <Ratio label="CAGR chiffre d'affaires" value={fmtCagr(row.revenue_cagr)} />
            <Ratio label="CAGR résultat net" value={fmtCagr(row.net_income_cagr)} />
            <Ratio label="CAGR dividende/action" value={fmtCagr(row.dividend_cagr)} />
          </RatioGroup>

          <ChartColorPicker colorFor={chartColors.colorFor} setColor={chartColors.setColor} overrides={chartColors.overrides} />

          {/* Filtre "Type de rapport" — mélanger trimestriel/semestriel/annuel dans un même graphe produit une
              courbe en dents de scie (périodes de durées différentes) ; filtrer sur un seul type la rend lisible,
              par exemple pour comparer les exercices complets entre eux ("Annuel" seul). */}
          {reportTypes.length > 1 && (
            <label className="flex items-center gap-2 text-xs">
              <span className="font-medium text-gray-700 dark:text-gray-300">Type de rapport :</span>
              <div className="w-56">
                <SearchableSelect
                  options={reportTypes.map((t) => ({ value: t, label: REPORT_TYPE_LABELS[t] ?? t }))}
                  value={historyReportType}
                  onChange={setHistoryReportType}
                  placeholder="Tous"
                />
              </div>
            </label>
          )}

          <div className="flex flex-col gap-4">
            <HistoryBarChart title="Chiffre d'affaires" data={filterByReportType(row.revenue_history)} color={chartColors.colorFor('revenue')} valueFormatter={fmtCompact} />
            <HistoryBarChart title="Résultat net" data={filterByReportType(row.net_income_history)} color={chartColors.colorFor('net_income')} valueFormatter={fmtCompact} />
            <HistoryBarChart title="Dividendes totaux versés" data={filterByReportType(row.total_dividend_history)} color={chartColors.colorFor('total_dividend')} valueFormatter={fmtCompact} />
            <HistoryBarChart title="Dividende par action (rapports)" data={filterByReportType(row.dividend_history)} color={chartColors.colorFor('dividend_per_share')} valueFormatter={(v) => `${fmt(v, 2)} FCFA`} />
            {/* Sourcé des BULLETINS BRVM (déclarations de dividende), pas des rapports financiers — voir
                FundamentalsRow.bulletin_dividend_history. Pas de filtre "Type de rapport" ici : les bulletins
                n'ont pas de notion de trimestriel/semestriel/annuel, cela viderait toujours le graphe. */}
            <HistoryBarChart
              title="Dividende par action (bulletins BRVM)"
              data={row.bulletin_dividend_history}
              color={chartColors.colorFor('bulletin_dividend')}
              valueFormatter={(v) => `${fmt(v, 2)} FCFA`}
            />
            <div>
              <h5 className="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">Dividende par action (rapports + bulletins combinés)</h5>
              <CombinedDividendChart
                data={row.combined_dividend_history}
                colorRapport={chartColors.colorFor('dividend_per_share')}
                colorBulletin={chartColors.colorFor('bulletin_dividend')}
              />
            </div>
          </div>
        </div>
      )}

      {detailTab === 'rentabilite' && (
        <div className="flex flex-col gap-5">
          <RatioGroup title="Rentabilité">
            <Ratio label="ROE" value={pct(row.roe_percent)} title="Rendement des capitaux propres" />
            <Ratio label="ROA" value={pct(row.roa_percent)} title="Rendement des actifs" />
          </RatioGroup>

          <RatioGroup title="Rotation / efficacité">
            <Ratio label="Rotation des actifs" value={fmt(row.asset_turnover, 3)} title="ROA ÷ marge nette (identité de DuPont) — instable si la marge nette est proche de 0" />
            <Ratio label="Rotation créances clients" value={fmt(row.receivable_turnover, 2)} title="365 ÷ délai clients (jours)" />
            <Ratio label="Rotation fournisseurs" value={fmt(row.payable_turnover, 2)} title="365 ÷ délai fournisseurs (jours)" />
            <Ratio label="Rotation des stocks" value={fmt(row.inventory_turnover, 2)} title="365 ÷ délai stocks (jours)" />
          </RatioGroup>

          <RatioGroup title="Structure financière / solvabilité">
            <Ratio label="Dette totale" value={fmt(row.total_debt, 0)} />
            <Ratio label="Capitaux propres" value={fmt(row.total_equity, 0)} />
            <Ratio label="Trésorerie" value={fmt(row.cash_position, 0)} />
            <Ratio label="Dette nette" value={fmt(row.net_debt, 0)} title="Dette totale - trésorerie" />
            <Ratio label="Dette/CP" value={fmt(row.debt_to_equity, 3)} />
            <Ratio label="Dette nette/CP" value={fmt(row.net_debt_to_equity, 3)} />
            <Ratio label="Dette/EBITDA" value={fmt(row.debt_to_ebitda, 2)} />
            <Ratio label="Dette nette/EBITDA" value={fmt(row.net_debt_to_ebitda, 2)} />
            <Ratio label="Charges d'intérêt" value={fmt(row.interest_expense, 0)} />
            <Ratio label="Couverture des intérêts" value={fmt(row.interest_coverage_ratio, 2)} title="EBIT / charges d'intérêt" />
          </RatioGroup>

          <RatioGroup title="Liquidité / BFR">
            <Ratio label="Actifs courants" value={fmt(row.current_assets, 0)} />
            <Ratio label="Passifs courants" value={fmt(row.current_liabilities, 0)} />
            <Ratio label="Current Ratio" value={fmt(row.current_ratio, 2)} />
            <Ratio label="Quick Ratio" value={fmt(row.quick_ratio, 2)} />
            <Ratio label="BFR" value={fmt(row.working_capital, 0)} title="Besoin en fonds de roulement" />
            <Ratio label="Délai clients" value={row.receivable_days !== null ? `${fmt(row.receivable_days, 0)} j` : '—'} />
            <Ratio label="Délai fournisseurs" value={row.payable_days !== null ? `${fmt(row.payable_days, 0)} j` : '—'} />
            <Ratio label="Délai stocks" value={row.inventory_days !== null ? `${fmt(row.inventory_days, 0)} j` : '—'} />
          </RatioGroup>

          <RatioGroup title="Cash-flow">
            <Ratio label="Flux de trésorerie d'exploitation" value={fmt(row.operating_cash_flow, 0)} />
            <Ratio label="Capex" value={fmt(row.capex, 0)} />
            <Ratio label="Free Cash Flow" value={fmt(row.free_cash_flow, 0)} />
            <Ratio label="FCF Yield" value={pct(row.fcf_yield_percent)} title="FCF / capitalisation" />
          </RatioGroup>

          <RatioGroup title="Par action">
            <Ratio label="Actions en circulation" value={fmt(row.shares_outstanding, 0)} />
            <Ratio label="BPA" value={fmt(row.eps, 2)} />
            <Ratio label="Valeur comptable/action" value={fmt(row.book_value_per_share, 2)} />
            <Ratio label="Dividende/action" value={fmt(row.dividend_per_share, 2)} />
          </RatioGroup>
        </div>
      )}

      {detailTab === 'valorisation' && (
        <div className="flex flex-col gap-5">
          <RatioGroup title="Valorisation">
            <Ratio label="PER" value={fmt(row.pe_ratio, 2)} />
            <Ratio label="PEG (÷ croiss. CA)" value={fmt(row.peg_ratio, 2)} />
            <Ratio label="PEG (÷ croiss. résultat)" value={fmt(row.peg_earnings_ratio, 2)} />
            <Ratio label="P/B" value={fmt(row.price_to_book, 2)} />
            <Ratio
              label="PER × PBR (Graham)"
              value={fmt(row.per_pbr_product, 2)}
              title="Critère de Graham : au-delà de 22,5, l'action est traditionnellement jugée chère sur ces deux mesures combinées — un repère, pas une règle absolue"
            />
            <Ratio label="P/CA (PSR)" value={fmt(row.price_to_sales, 2)} />
            <Ratio label="P/CF" value={fmt(row.price_to_cash_flow, 2)} />
            <Ratio label="EV/EBITDA" value={fmt(row.ev_to_ebitda, 2)} />
            <Ratio label="EV/EBIT" value={fmt(row.ev_to_ebit, 2)} />
            <Ratio label="EV/CA" value={fmt(row.ev_to_sales, 2)} />
            <Ratio label="EV/FCF" value={fmt(row.ev_to_fcf, 2)} />
          </RatioGroup>

          <RatioGroup title="Flottant">
            <div className="col-span-2 -mb-1 text-xs text-gray-400 dark:text-gray-500 sm:col-span-3 lg:col-span-4">
              Extrait par IA uniquement si la répartition de l'actionnariat est chiffrée dans le rapport source — souvent absent, un champ vide est normal.
            </div>
            <Ratio label="Flottant" value={pct(row.free_float_percent)} title="Part du capital détenue par le public, hors actionnaires de référence/stratégiques" />
            <Ratio label="Actions flottantes" value={row.free_float_shares !== null ? fmtCompact(row.free_float_shares) : '—'} />
            <Ratio label="Capitalisation flottante" value={row.free_float_market_cap !== null ? `${fmtCompact(row.free_float_market_cap)} FCFA` : '—'} />
          </RatioGroup>

          <RatioGroup title="Dividende">
            <Ratio label="Rendement" value={pct(row.dividend_yield_percent)} />
            <Ratio label="Taux de distribution (payout)" value={pct(row.payout_ratio_percent)} />
            <Ratio label="Taux de rétention" value={pct(row.retention_ratio_percent)} />
            <Ratio label="Couverture du dividende" value={fmt(row.dividend_coverage, 2)} title="Résultat net / dividendes versés" />
          </RatioGroup>

          <RatioGroup title="Comparables sectoriels">
            <div className="col-span-2 -mb-1 text-xs text-gray-400 dark:text-gray-500 sm:col-span-3 lg:col-span-4">
              {row.sector_peer_count > 0
                ? `Comparé à ${row.sector_peer_count} autre(s) entreprise(s) du secteur "${row.sector ?? '?'}" ayant une donnée disponible.`
                : `Aucune autre entreprise du secteur "${row.sector ?? '?'}" n'a de donnée disponible pour comparer.`}
            </div>
            <Ratio
              label="PER vs médiane secteur"
              value={row.sector_median_pe_ratio !== null ? `${fmt(row.sector_median_pe_ratio, 2)} (${pct(row.pe_ratio_vs_sector_percent, 0)})` : '—'}
              title="Médiane du secteur, puis écart de cette entreprise à cette médiane"
            />
            <Ratio
              label="P/B vs médiane secteur"
              value={row.sector_median_price_to_book !== null ? `${fmt(row.sector_median_price_to_book, 2)} (${pct(row.price_to_book_vs_sector_percent, 0)})` : '—'}
            />
            <Ratio
              label="EV/EBITDA vs médiane secteur"
              value={row.sector_median_ev_to_ebitda !== null ? `${fmt(row.sector_median_ev_to_ebitda, 2)} (${pct(row.ev_to_ebitda_vs_sector_percent, 0)})` : '—'}
            />
            <Ratio
              label="Rdt dividende vs médiane secteur"
              value={row.sector_median_dividend_yield_percent !== null ? `${pct(row.sector_median_dividend_yield_percent)} (${pct(row.dividend_yield_percent_vs_sector_percent, 0)})` : '—'}
            />
          </RatioGroup>

          {row.valuation_rationale && (
            <div>
              <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                Justification du verdict IA
              </h4>
              <p className="text-sm text-gray-700 dark:text-gray-300">{row.valuation_rationale}</p>
            </div>
          )}
        </div>
      )}

      {detailTab === 'modele' && (
        <ValuationModelSection companyId={row.company_id} sourceReportId={row.source_report_id} query={valuationQuery} />
      )}
    </div>
  )
}
