import { useEffect, useMemo, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  ResponsiveContainer,
  LineChart,
  Line,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  Tooltip,
  Legend,
  CartesianGrid,
  ReferenceLine,
  ReferenceDot,
} from 'recharts'
import { callApi } from '../lib/apiClient'
import type {
  Company,
  CompanyLiquidity,
  CompanyPriceSeries,
  CompanySignal,
  MaCrossoverEvent,
  OhlcPoint,
  RecentTradingDates,
  RsiDivergenceEvent,
  TechnicalIndicatorPoint,
} from '../lib/types'
import { Card, ErrorState, InfoPanel, Input, LoadingState, SearchableSelect, Select, Tabs } from '../components/ui'
import { ChartAiAnalysis } from '../components/ChartAiAnalysis'

/** Couleur badge par score de signal composite (-2 à +2, voir api_signals.php). */
function signalBadgeClass(score: number | null) {
  if (score === null) return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'
  if (score >= 2) return 'bg-emerald-600 text-white'
  if (score === 1) return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
  if (score === 0) return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'
  if (score === -1) return 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
  return 'bg-red-600 text-white'
}

/** Couleur badge par niveau de liquidité (api_quotes.php, action liquidity). */
function liquidityBadgeClass(liquidity: string) {
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

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function daysAgoIso(n: number) {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return d.toISOString().slice(0, 10)
}

export function Quotes() {
  const [activeTab, setActiveTab] = useState<'charts' | 'signals'>('charts')
  const [companyId, setCompanyId] = useState<number | null>(null)
  // Valeur de repli le temps que recentTradingDatesQuery ci-dessous réponde
  // (voir l'effet plus bas) — remplacée par les 3 derniers jours de
  // cotation RÉELS dès que connus, un simple "il y a N jours calendaires"
  // serait faux dès qu'un week-end ou un jour férié tombe dans la fenêtre
  // (constaté en base : aucune cotation le lundi 03/08/2026, un jour ouvré
  // classique — jour férié non prévisible par un calcul calendaire).
  const [startDate, setStartDate] = useState(daysAgoIso(7))
  const [endDate, setEndDate] = useState(todayIso())

  // Dates pour l'onglet "Signaux techniques". Un signal est une lecture à un
  // instant donné (pas une série continue sur une période) — "entre deux
  // dates" se traduit donc par une COMPARAISON de deux instantanés (signal
  // au début vs signal à la fin), pas par un calcul continu comme pour les
  // graphes ci-dessus. signalsEndDate vide = dernière séance pour laquelle
  // les indicateurs techniques ont été calculés (voir
  // api_signals.php::getLatestIndicatorsDate()) ; signalsStartDate vide =
  // pas de comparaison, le tableau affiche uniquement l'état à la date de fin.
  const [signalsStartDate, setSignalsStartDate] = useState('')
  const [signalsEndDate, setSignalsEndDate] = useState('')

  const [showSma, setShowSma] = useState({ sma_10: true, sma_20: true, sma_50: false })
  const [showVwap, setShowVwap] = useState(false)
  const [signalsSelected, setSignalsSelected] = useState<number[]>([])

  const companiesQuery = useQuery({
    queryKey: ['companies-list'],
    queryFn: () => callApi<Company[]>('api_companies.php', 'list', { per_page: 200, active: 1 }),
  })

  // Calendrier de bourse réel (marché entier, pas par entreprise) pour fixer
  // par défaut la période sur les 3 derniers jours de cotation — voir
  // api_quotes.php::getRecentTradingDates() pour pourquoi un calcul
  // calendaire (aujourd'hui - N jours) ne suffit pas.
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

  const ohlcQuery = useQuery({
    queryKey: ['ohlc', companyId, startDate, endDate],
    queryFn: () =>
      callApi<OhlcPoint[]>('api_quotes.php', 'ohlc', { company_id: companyId, start_date: startDate, end_date: endDate }),
    enabled: !!companyId && startDate <= endDate,
  })

  // Moyennes mobiles déjà calculées et stockées à chaque synchro
  // (class/TechnicalIndicatorsCalculator.php) — jamais affichées jusqu'ici.
  const indicatorsQuery = useQuery({
    queryKey: ['technical-indicators', companyId, startDate, endDate],
    queryFn: () =>
      callApi<TechnicalIndicatorPoint[]>('api_technical_indicators.php', 'get_indicators', {
        company_id: companyId,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!companyId && startDate <= endDate,
  })

  // Croisements de moyennes mobiles (golden/death cross) — détectés côté
  // backend à partir des SMA déjà persistées, voir TODO_ANALYSES.md,
  // point 14. Marqueurs positionnés sur le graphe "Cours de clôture".
  const crossoversQuery = useQuery({
    queryKey: ['ma-crossovers', companyId, startDate, endDate],
    queryFn: () =>
      callApi<MaCrossoverEvent[]>('api_signals.php', 'crossovers', {
        company_id: companyId,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!companyId && startDate <= endDate,
  })

  // Divergences cours/RSI — voir TODO_ANALYSES.md, point 15.
  const divergenceQuery = useQuery({
    queryKey: ['rsi-divergence', companyId, startDate, endDate],
    queryFn: () =>
      callApi<RsiDivergenceEvent[]>('api_signals.php', 'divergence', {
        company_id: companyId,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: !!companyId && startDate <= endDate,
  })

  // Score composite achat/vente pour l'entreprise sélectionnée — voir
  // api_signals.php pour le détail du calcul (synthèse mécanique RSI/MACD/
  // tendance/Bollinger, pas un conseil financier).
  const signalQuery = useQuery({
    queryKey: ['signal', companyId],
    queryFn: () => callApi<CompanySignal>('api_signals.php', 'get', { company_id: companyId }),
    enabled: !!companyId,
    retry: false,
  })

  // Signaux de toutes les entreprises actives, triés par force côté backend.
  // Sans `date`, le backend retient automatiquement la dernière séance
  // disponible — ne jamais envoyer la date du jour explicitement : si les
  // indicateurs du jour ne sont pas encore calculés, une correspondance
  // exacte sur "aujourd'hui" renverrait une liste vide au lieu de la
  // dernière séance connue. Avec `start_date`, chaque entreprise reçoit en
  // plus son score à cette date de début et l'évolution jusqu'à la date de
  // fin (voir api_signals.php::listSignals()).
  const signalsListQuery = useQuery({
    queryKey: ['signals-list', signalsStartDate, signalsEndDate],
    queryFn: () =>
      callApi<CompanySignal[]>('api_signals.php', 'list', {
        ...(signalsEndDate ? { date: signalsEndDate } : {}),
        ...(signalsStartDate ? { start_date: signalsStartDate } : {}),
      }),
    enabled: !signalsStartDate || !signalsEndDate || signalsStartDate <= signalsEndDate,
  })

  // Score de liquidité (volume moyen / part de jours sans transaction) sur
  // 30 jours glissants — un signal technique sur un titre illiquide (cours
  // figé faute d'acheteur/vendeur) est trompeur, ce badge donne le contexte.
  const liquidityQuery = useQuery({
    queryKey: ['liquidity-list'],
    queryFn: () => callApi<CompanyLiquidity[]>('api_quotes.php', 'liquidity', { days: 30 }),
  })
  const liquidityByCompany = useMemo(
    () => new Map((liquidityQuery.data ?? []).map((l) => [l.company_id, l])),
    [liquidityQuery.data],
  )

  // Relevés intrajournaliers (un point toutes les ~10 min pendant la séance)
  // sur la même période que les graphes ci-dessus (startDate/endDate, unique
  // sélecteur en haut de page) — contrairement à un jour unique, plusieurs
  // journées de marché se suivent alors sur l'axe du temps (même action
  // `compare` en granularity=intraday que la page Comparaison, mais
  // restreinte à une seule entreprise ici). Sans rafraîchissement
  // automatique, le graphe resterait figé sur les données chargées à
  // l'ouverture de la page jusqu'au prochain rechargement manuel — inutile
  // de reinterroger une période entièrement passée (immuable), donc
  // seulement activé quand la période affichée inclut aujourd'hui.
  const intradaySingleDay = startDate === endDate
  const intradayIsLive = endDate === todayIso()
  const intradayQuery = useQuery({
    queryKey: ['intraday', companyId, startDate, endDate],
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

  const companies = companiesQuery.data ?? []

  // Sélection par défaut dès que la liste des entreprises est chargée — une
  // seule fois, pour ne pas reforcer la sélection si l'utilisateur en choisit
  // une autre ensuite. Priorité : dernière entreprise consultée sur cet écran
  // (localStorage) > première entreprise de la liste (même comportement que
  // le tableau de bord entreprise).
  useEffect(() => {
    if (companyId === null && companies.length > 0) {
      const lastViewed = localStorage.getItem('brvm_quotes_last_company')
      const match = (lastViewed && companies.find((c) => c.symbol === lastViewed)) || companies[0]
      setCompanyId(match.company_id)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companies, companyId])

  function selectCompany(id: number | null) {
    setCompanyId(id)
    const company = companies.find((c) => c.company_id === id)
    if (company) localStorage.setItem('brvm_quotes_last_company', company.symbol)
  }
  // true dès qu'au moins une entreprise a un score_start non nul — sinon la
  // date de début choisie n'a produit aucune donnée exploitable (voir le
  // message d'avertissement ci-dessous).
  const signalsHasStartData = (signalsListQuery.data ?? []).some(
    (s) => s.score_start !== null && s.score_start !== undefined,
  )
  const intradayData = (intradayQuery.data?.[0]?.data ?? []).map((p) => ({
    // Un seul jour affiché : uniquement l'heure (HH:MM) sur l'axe X ; sur
    // plusieurs jours, la date reste dans la clé pour ne pas fusionner des
    // heures identiques de jours différents (même principe que Comparison.tsx).
    time: intradaySingleDay ? String(p.date).slice(11, 16) : String(p.date).slice(0, 16),
    price: Number(p.price),
    variation_percent: p.variation !== null ? Number(p.variation) : null,
  }))

  // Fusionne le cours de clôture avec les moyennes mobiles du même jour —
  // deux sources séparées (stock_quotes / technical_indicators) affichées
  // sur un seul graphe.
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

  // Séries ADX/Stochastique/ROC/OBV — mêmes indicateurs que priceWithSma
  // mais affichées sur des sous-graphes séparés (échelles trop différentes
  // du prix pour être superposées). null tant que l'historique est trop
  // court (voir commentaires dans TechnicalIndicatorsCalculator.php) —
  // filtré au niveau de chaque graphe, pas ici, pour détecter "aucune
  // donnée du tout" vs "pas encore assez d'historique".
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

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Cotations</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">Historique de prix et de volume par entreprise</p>
      </div>

      <Tabs
        tabs={[
          { id: 'charts', label: 'Graphes' },
          { id: 'signals', label: 'Signaux techniques' },
        ]}
        active={activeTab}
        onChange={(id) => setActiveTab(id as 'charts' | 'signals')}
      />

      {activeTab === 'charts' && (
        <>
      <InfoPanel>
        <p>
          <strong>À quoi sert cet onglet.</strong> Il permet d'étudier une seule entreprise en détail : évolution de
          son cours, de son volume, et son signal technique du moment. Utile pour creuser une entreprise repérée sur
          l'onglet « Signaux techniques » ou sur l'écran Classements.
        </p>
        <p>
          <strong>Signal composite (badge en haut).</strong> Score de -2 (vente forte) à +2 (achat fort), moyenne de
          jusqu'à 4 indicateurs techniques : RSI(14) (&lt;30 = survendu/signal d'achat, &gt;70 = suracheté/signal de
          vente), MACD (au-dessus de sa ligne de signal = momentum haussier), tendance (cours au-dessus de sa
          moyenne mobile = haussier), Bandes de Bollinger (cours sorti des bandes = potentiel retour à la moyenne).
          C'est une synthèse mécanique d'indicateurs, <strong>pas un conseil financier</strong> — un signal peut
          manquer si l'historique de l'entreprise est trop court pour le calculer.
        </p>
        <p>
          <strong>Variation intrajournalière.</strong> Un point par synchronisation (~toutes les 5-10 min pendant
          les heures de marché), qui montre l'écart en % par rapport à la clôture de la veille au fil de la/des
          séance(s) — suit la même période que les autres graphes de cet onglet (Date de début/fin ci-dessous) : sur
          un seul jour, utile pour voir à quel moment de la journée un mouvement s'est produit ; sur plusieurs jours,
          les séances se suivent sur l'axe du temps pour comparer l'agitation intrajournalière d'un jour à l'autre.
        </p>
        <p>
          <strong>Cours de clôture + moyennes mobiles (SMA).</strong> Chaque SMA-N est la moyenne des N derniers
          cours de clôture. Un cours au-dessus de sa SMA est généralement lu comme une tendance haussière,
          en-dessous comme baissière. Le VWAP (optionnel) est le cours moyen pondéré par le volume échangé chaque
          jour — utile pour situer la clôture par rapport au "juste prix" moyen de la séance.
        </p>
        <p>
          <strong>Points colorés sur le graphe — croisements de moyennes mobiles.</strong> Un point{' '}
          <span className="text-emerald-600 dark:text-emerald-400">vert</span> marque un <em>golden cross</em>{' '}
          (SMA rapide qui passe au-dessus de la SMA lente, ex. SMA10 au-dessus de SMA20) — signal haussier classique
          en analyse technique. Un point <span className="text-red-600 dark:text-red-400">rouge</span> marque un{' '}
          <em>death cross</em> (l'inverse) — signal baissier. Détectés sur les paires SMA10/20 et SMA20/50, qu'elles
          soient cochées ou non ci-dessus (les points restent visibles même si la SMA correspondante est masquée).
        </p>
        <p>
          <strong>Volume.</strong> Nombre d'actions échangées chaque jour — un pic ponctuel peut correspondre à une
          annonce ou à une transaction isolée sur un titre peu liquide, pas forcément à un mouvement de fond.
        </p>
      </InfoPanel>

      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <label className="flex-1 min-w-[220px]">
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

      {!companyId && <p className="text-sm text-gray-500 dark:text-gray-400">Sélectionne une entreprise pour afficher son graphe.</p>}

      {companyId && signalQuery.data && (
        <div className="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
          <span className={`rounded-full px-3 py-1 text-sm font-semibold ${signalBadgeClass(signalQuery.data.score)}`}>
            {signalQuery.data.label}
          </span>
          <span className="text-xs text-gray-500 dark:text-gray-400">
            Signal composite basé sur {signalQuery.data.indicators_used} indicateur(s) technique(s) — synthèse mécanique
            (RSI, MACD, tendance, Bollinger), pas un conseil financier.
          </span>
          {Object.entries(signalQuery.data.details).length > 0 && (
            <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
              {Object.entries(signalQuery.data.details).map(([key, d]) => (
                <li key={key}>{d.reason}</li>
              ))}
            </ul>
          )}
        </div>
      )}

      {companyId && (
        <Card title="Variation intrajournalière">
          <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
            Un point par synchronisation (toutes les ~5-10 min pendant les heures de marché), sur la période choisie
            en haut de page — jamais écrasé, contrairement au cours de clôture du jour ci-dessous.
            {!intradaySingleDay && ' Sur plusieurs jours, chaque journée de marché se suit sur l\'axe du temps.'}
          </p>

          {intradayQuery.isLoading && <LoadingState />}
          {intradayQuery.error && <ErrorState message={(intradayQuery.error as Error).message} />}
          {intradayQuery.data && intradayData.length === 0 && (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              Aucun relevé pour cette période (hors heures de marché, ou synchro pas encore passée).
            </p>
          )}
          {intradayData.length > 0 && (
            <ResponsiveContainer width="100%" height={240}>
              <LineChart data={intradayData}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                <XAxis dataKey="time" tick={{ fontSize: 11 }} minTickGap={30} />
                <YAxis
                  yAxisId="percent"
                  domain={['auto', 'auto']}
                  tick={{ fontSize: 11 }}
                  width={60}
                  tickFormatter={(v) => `${v}%`}
                />
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

      {companyId && ohlcQuery.isLoading && <LoadingState />}
      {companyId && ohlcQuery.error && <ErrorState message={(ohlcQuery.error as Error).message} />}

      {companyId && ohlcQuery.data && ohlcQuery.data.length < 2 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Historique insuffisant pour tracer un graphique.</p>
      )}

      {companyId && ohlcQuery.data && ohlcQuery.data.length >= 2 && (
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
            <ResponsiveContainer width="100%" height={280}>
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

            {(crossoversQuery.data ?? []).length > 0 && (
              <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span className="flex items-center gap-1.5">
                  <span className="h-2.5 w-2.5 rounded-full bg-emerald-600" /> Golden cross (SMA rapide passe
                  au-dessus de la lente)
                </span>
                <span className="flex items-center gap-1.5">
                  <span className="h-2.5 w-2.5 rounded-full bg-red-600" /> Death cross (l'inverse)
                </span>
                <span>
                  Derniers croisements :{' '}
                  {[...(crossoversQuery.data ?? [])]
                    .slice(-4)
                    .reverse()
                    .map((c) => `${c.date} (${c.pair}, ${c.type === 'golden' ? 'golden' : 'death'})`)
                    .join(' · ')}
                </span>
              </div>
            )}

            <ChartAiAnalysis
              chartType="quotes_close_sma"
              parameters={{ company_id: companyId, start_date: startDate, end_date: endDate, sma: showSma, vwap: showVwap }}
              data={priceWithSma}
            />
          </Card>

          <Card title="Volume">
            <ResponsiveContainer width="100%" height={140}>
              <BarChart data={ohlcQuery.data}>
                <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                <YAxis tick={{ fontSize: 11 }} width={70} />
                <Tooltip />
                <Bar dataKey="volume" fill="var(--chart-soft)" />
              </BarChart>
            </ResponsiveContainer>
          </Card>

          {hasAdvancedIndicator('obv') && (
            <Card title="On-Balance Volume (OBV)">
              <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
                Cumul du volume signé (jour de hausse : +volume, jour de baisse : -volume) — la valeur absolue n'a
                pas de sens en soi (elle dépend du point de départ du calcul), seule la <strong>tendance</strong> de
                la courbe compte : une pente qui monte en même temps que le cours confirme la tendance ; une
                divergence (OBV qui baisse pendant que le cours monte) peut signaler un essoufflement.
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
              <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
Quatre indicateurs complémentaires au signal composite ci-dessus — plus techniques, pensés pour un
                usage ponctuel plutôt qu'une lecture quotidienne. Chacun a besoin d'un historique minimum (13 à 29
                jours selon l'indicateur) : tant que l'historique de synchronisation de l'application est trop
                court, les courbes restent vides pour cette entreprise — ça se résorbe automatiquement au fil des
                jours.
              </p>

              {hasAdvancedIndicator('adx_14') ? (
                <div className="mb-4">
                  <div className="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">
                    ADX (14j) — force de la tendance, pas son sens
                  </div>
                  <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
                    Au-dessus de 25 : tendance marquée (à la hausse ou à la baisse, l'ADX ne le dit pas — voir le
                    signal composite ou le graphe de cours pour le sens). En-dessous de 20 : marché sans direction
                    claire, plus propice à un scénario de retour à la moyenne (Bollinger) qu'à du suivi de tendance.
                  </p>
                  <ResponsiveContainer width="100%" height={120}>
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
                <p className="mb-4 text-sm text-gray-400 dark:text-gray-600">
                  ADX : historique insuffisant pour cette entreprise (besoin d'environ 29 jours).
                </p>
              )}

              {hasAdvancedIndicator('stoch_k') ? (
                <div className="mb-4">
                  <div className="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">
                    Oscillateur stochastique (%K 14j / %D 3j)
                  </div>
                  <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
                    Au-dessus de 80 : suracheté. En-dessous de 20 : survendu. Plus réactif que le RSI (déjà utilisé
                    dans le signal composite) — utile en complément, pas en remplacement.
                  </p>
                  <ResponsiveContainer width="100%" height={120}>
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
                <p className="mb-4 text-sm text-gray-400 dark:text-gray-600">
                  Stochastique : historique insuffisant pour cette entreprise (besoin de 14 jours).
                </p>
              )}

              {hasAdvancedIndicator('roc_12') ? (
                <div className="mb-4">
                  <div className="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">
                    Rate of Change (12j)
                  </div>
                  <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
                    Variation en % du cours par rapport à il y a 12 jours — momentum simple. Au-dessus de 0 :
                    momentum haussier sur la période ; en-dessous : baissier.
                  </p>
                  <ResponsiveContainer width="100%" height={120}>
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
                <p className="mb-4 text-sm text-gray-400 dark:text-gray-600">
                  ROC : historique insuffisant pour cette entreprise (besoin de 13 jours).
                </p>
              )}

              {hasAdvancedIndicator('rsi_14') ? (
                <div>
                  <div className="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">
                    RSI (14j) et divergences cours/RSI
                  </div>
                  <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
                    Au-dessus de 70 : suracheté (déjà utilisé dans le signal composite). En-dessous de 30 : survendu.
                    Les points colorés marquent une <strong>divergence</strong> détectée entre deux sommets ou deux
                    creux de cours consécutifs :{' '}
                    <span className="text-red-600 dark:text-red-400">rouge</span> = baissière (le cours fait un plus
                    haut mais le RSI un plus bas — la hausse s'essouffle) ;{' '}
                    <span className="text-emerald-600 dark:text-emerald-400">vert</span> = haussière (l'inverse).
                    Souvent plus fiable qu'un simple seuil RSI.
                  </p>
                  <ResponsiveContainer width="100%" height={120}>
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
                  {(divergenceQuery.data ?? []).length > 0 && (
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                      Dernières divergences :{' '}
                      {[...(divergenceQuery.data ?? [])]
                        .slice(-3)
                        .reverse()
                        .map((d) => `${d.date} (${d.type === 'bearish' ? 'baissière' : 'haussière'})`)
                        .join(' · ')}
                    </p>
                  )}
                </div>
              ) : (
                <p className="text-sm text-gray-400 dark:text-gray-600">
                  RSI : historique insuffisant pour cette entreprise (besoin de 14 jours).
                </p>
              )}
            </Card>
        </>
      )}
        </>
      )}

      {activeTab === 'signals' && (
        <>
      <InfoPanel>
        <p>
          <strong>À quoi sert cet onglet.</strong> Il donne une vue d'ensemble de toute la cote, triée par force du
          signal technique — utile pour repérer rapidement les entreprises qui « se démarquent » techniquement
          avant d'aller les creuser une par une dans l'onglet « Graphes ».
        </p>
        <p>
          <strong>Colonne Signal.</strong> Score composite de -2 (vente forte) à +2 (achat fort), moyenne arrondie
          de jusqu'à 4 indicateurs techniques disponibles (RSI, MACD, tendance vs moyenne mobile, Bandes de
          Bollinger — détail au survol de l'en-tête de colonne). C'est une <strong>synthèse mécanique</strong>
          d'indicateurs de marché, pas un conseil financier ni une prédiction.
        </p>
        <p>
          <strong>Colonne Liquidité — à vérifier avant de se fier à un signal.</strong> Basée sur le volume moyen et
          la part de jours sans transaction sur les 30 derniers jours. Un titre « Illiquide » peut afficher un cours
          figé (donc des indicateurs techniques comme le RSI ou les Bollinger qui semblent « extrêmes ») simplement
          parce que personne n'a tradé récemment — pas parce que le marché juge le titre stable ou en tendance. Un
          signal fort sur un titre illiquide est donc moins fiable qu'un signal fort sur un titre activement
          échangé — le score le prend maintenant en compte automatiquement : un « Achat fort »/« Vente forte »
          sur un titre illiquide est plafonné à « Achat »/« Vente » simple, avec un{' '}
          <span className="text-amber-600 dark:text-amber-400">⚠︎</span> à côté du badge pour le signaler
          explicitement. Survole un badge Signal pour voir l'ATR relatif (agitation récente du titre en % de son
          cours) — un signal identique mérite plus de prudence sur un titre très agité que sur un titre stable.
        </p>
        <p>
          <strong>Comment l'utiliser.</strong> Coche des entreprises et lance une analyse IA en bas de tableau pour
          obtenir une synthèse en langage clair du pourquoi de chaque score.
        </p>
        <p>
          <strong>Dates.</strong> Un signal est une lecture à un instant donné (pas une série sur une période) —
          « entre deux dates » se traduit donc par une <strong>comparaison</strong> de deux instantanés, pas par un
          calcul continu comme pour les graphes du premier onglet. Par défaut (aucune date renseignée), le tableau
          montre uniquement l'état à la dernière séance pour laquelle les indicateurs ont été calculés. Renseigne
          une <strong>date de début</strong> pour voir aussi le score à cette date-là et son évolution jusqu'à la
          date de fin (ou jusqu'à la dernière séance si la date de fin est laissée vide).
        </p>
        <p>
          <strong>Signal « Indéterminé ».</strong> Un score RSI/MACD/tendance/Bollinger a besoin d'un minimum de
          jours de cotation précédents pour être calculable (ex: le RSI a besoin de 14 jours d'historique). Juste
          après le tout premier jour de synchronisation de l'application pour une entreprise, aucun indicateur n'est
          encore calculable — le signal affiche alors « Indéterminé » pour tout le marché, ce qui est normal et se
          résorbe automatiquement à mesure que l'historique s'accumule au fil des jours. Ce n'est pas un bug ni un
          problème de données.
        </p>
      </InfoPanel>

      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <label className="w-44">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de début (comparaison)</span>
            <Input
              type="date"
              value={signalsStartDate}
              max={signalsEndDate || todayIso()}
              onChange={(e) => setSignalsStartDate(e.target.value)}
            />
          </label>
          <label className="w-44">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de fin (référence)</span>
            <Input
              type="date"
              value={signalsEndDate}
              min={signalsStartDate}
              max={todayIso()}
              onChange={(e) => setSignalsEndDate(e.target.value)}
            />
          </label>
          {(signalsStartDate || signalsEndDate) && (
            <button
              type="button"
              onClick={() => {
                setSignalsStartDate('')
                setSignalsEndDate('')
              }}
              className="pb-2 text-sm text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
            >
              Revenir à la dernière séance (sans comparaison)
            </button>
          )}
          {!signalsStartDate && !signalsEndDate && (
            <p className="pb-2 text-xs text-gray-500 dark:text-gray-400">Dernière séance disponible, sans comparaison (par défaut).</p>
          )}
        </div>
        {signalsStartDate && signalsEndDate && signalsStartDate > signalsEndDate && (
          <p className="mt-2 text-xs text-red-600 dark:text-red-400">La date de début doit précéder la date de fin.</p>
        )}
      </Card>

      {signalsListQuery.isLoading && <LoadingState label="Chargement des signaux…" />}
      {signalsListQuery.error && <ErrorState message={(signalsListQuery.error as Error).message} />}

      {signalsListQuery.data && signalsListQuery.data.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">
          {signalsEndDate
            ? `Aucun indicateur technique calculé pour le ${signalsEndDate} (jour non coté ou synchro pas encore passée).`
            : "Aucun indicateur technique calculé pour l'instant."}
        </p>
      )}

      {signalsStartDate && signalsListQuery.data && signalsListQuery.data.length > 0 && !signalsHasStartData && (
        <p className="text-sm text-amber-600 dark:text-amber-400">
          Aucun indicateur calculé pour la date de début choisie ({signalsStartDate}) — colonnes « Signal (
          {signalsStartDate}) » et « Évolution » vides ci-dessous. Soit ce n'était pas un jour de bourse, soit cette
          date est antérieure au tout premier jour couvert par les données actuellement synchronisées. Essaie une
          date plus récente.
        </p>
      )}

      {signalsListQuery.data && signalsListQuery.data.length > 0 && (
        <Card>
          <div className="mb-3 flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
            <span>{signalsSelected.length} sélectionnée(s)</span>
            <button
              type="button"
              onClick={() =>
                setSignalsSelected(
                  signalsSelected.length === signalsListQuery.data!.length
                    ? []
                    : signalsListQuery.data!.map((s) => s.company_id),
                )
              }
              className="underline hover:text-gray-700 dark:hover:text-gray-200"
            >
              {signalsSelected.length === signalsListQuery.data.length ? 'Tout décocher' : 'Tout sélectionner'}
            </button>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  <th className="pb-2 pr-3"></th>
                  <th className="pb-2 pr-3" title="Code de cotation à la BRVM">
                    Symbole
                  </th>
                  <th className="pb-2 pr-3">Entreprise</th>
                  <th className="pb-2 pr-3" title="Secteur d'activité (classification interne, voir migrations/007_seed_company_sectors.sql)">
                    Secteur
                  </th>
                  <th className="pb-2 pr-3 text-right" title="Dernier cours de clôture connu (FCFA)">
                    Cours
                  </th>
                  <th className="pb-2 pr-3 text-right" title="Variation du cours par rapport à la clôture de la veille">
                    Variation
                  </th>
                  <th
                    className="pb-2 pr-3"
                    title="Basée sur le volume moyen et la part de jours sans transaction sur les 30 derniers jours — un titre 'Illiquide' peut afficher un cours figé faute d'acheteur/vendeur, pas parce que le marché le juge stable"
                  >
                    Liquidité
                  </th>
                  <th
                    className="pb-2 pr-3"
                    title="Score composite (-2 à +2) basé sur RSI, MACD, tendance (cours vs moyenne mobile) et Bandes de Bollinger — synthèse mécanique, pas un conseil financier"
                  >
                    Signal{signalsStartDate ? ` (${signalsEndDate || 'dernière séance'})` : ''}
                  </th>
                  {signalsStartDate && (
                    <>
                      <th className="pb-2 pr-3" title={`Même score, calculé à la date de début (${signalsStartDate})`}>
                        Signal ({signalsStartDate})
                      </th>
                      <th className="pb-2" title="Score à la date de fin moins score à la date de début — positif = signal qui s'est amélioré (vers l'achat), négatif = qui s'est dégradé (vers la vente)">
                        Évolution
                      </th>
                    </>
                  )}
                </tr>
              </thead>
              <tbody>
                {signalsListQuery.data.map((s) => {
                  const liquidity = liquidityByCompany.get(s.company_id)
                  return (
                  <tr key={s.company_id} className="border-t border-gray-100 dark:border-gray-800">
                    <td className="py-2 pr-3">
                      <input
                        type="checkbox"
                        checked={signalsSelected.includes(s.company_id)}
                        onChange={() =>
                          setSignalsSelected((prev) =>
                            prev.includes(s.company_id) ? prev.filter((id) => id !== s.company_id) : [...prev, s.company_id],
                          )
                        }
                      />
                    </td>
                    <td className="py-2 pr-3 font-medium">{s.symbol}</td>
                    <td className="py-2 pr-3 text-gray-600 dark:text-gray-300">{s.name}</td>
                    <td className="py-2 pr-3 text-gray-500 dark:text-gray-400">{s.sector ?? '—'}</td>
                    <td className="py-2 pr-3 text-right tabular-nums">{s.close_price ?? '—'}</td>
                    <td
                      className={`py-2 pr-3 text-right tabular-nums ${
                        Number(s.variation_percent) > 0
                          ? 'text-emerald-600 dark:text-emerald-400'
                          : Number(s.variation_percent) < 0
                            ? 'text-red-600 dark:text-red-400'
                            : 'text-gray-500'
                      }`}
                    >
                      {s.variation_percent !== null ? `${Number(s.variation_percent) > 0 ? '+' : ''}${Number(s.variation_percent).toFixed(2)}%` : '—'}
                    </td>
                    <td className="py-2 pr-3">
                      {liquidity ? (
                        <span
                          className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${liquidityBadgeClass(liquidity.liquidity)}`}
                          title={`Volume moyen : ${liquidity.avg_volume} · ${liquidity.zero_volume_ratio}% de jours sans transaction sur ${liquidity.total_days} jours`}
                        >
                          {liquidity.liquidity}
                        </span>
                      ) : (
                        '—'
                      )}
                    </td>
                    <td className="py-2 pr-3">
                      <span
                        className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${signalBadgeClass(s.score)}`}
                        title={
                          s.atr_relative_percent != null
                            ? `Agitation récente (ATR 14j) : ${s.atr_relative_percent}% du cours`
                            : undefined
                        }
                      >
                        {s.label}
                      </span>
                      {s.confidence_penalized_by_liquidity && (
                        <span
                          className="ml-1 text-amber-600 dark:text-amber-400"
                          title="Score plafonné : ce titre est illiquide, un signal 'fort' n'y serait pas fiable — voir la colonne Liquidité"
                        >
                          ⚠︎
                        </span>
                      )}
                    </td>
                    {signalsStartDate && (
                      <>
                        <td className="py-2 pr-3">
                          {s.score_start !== null && s.score_start !== undefined ? (
                            <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${signalBadgeClass(s.score_start)}`}>
                              {s.label_start}
                            </span>
                          ) : (
                            <span className="text-gray-400 dark:text-gray-600">—</span>
                          )}
                        </td>
                        <td
                          className={`py-2 text-right tabular-nums font-semibold ${
                            s.score_change === null || s.score_change === undefined || s.score_change === 0
                              ? 'text-gray-500'
                              : s.score_change > 0
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : 'text-red-600 dark:text-red-400'
                          }`}
                        >
                          {s.score_change !== null && s.score_change !== undefined
                            ? `${s.score_change > 0 ? '+' : ''}${s.score_change}`
                            : '—'}
                        </td>
                      </>
                    )}
                  </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          <ChartAiAnalysis
            chartType="quotes_signals"
            parameters={{
              selected_company_ids: [...signalsSelected].sort((a, b) => a - b),
              date: signalsEndDate || 'dernière séance disponible',
              start_date: signalsStartDate || undefined,
            }}
            data={signalsListQuery.data
              .filter((s) => signalsSelected.includes(s.company_id))
              .map((s) => ({
                symbol: s.symbol,
                name: s.name,
                sector: s.sector,
                close_price: s.close_price,
                variation_percent: s.variation_percent,
                score: s.score,
                label: s.label,
                indicators_used: s.indicators_used,
                details: s.details,
                liquidity: liquidityByCompany.get(s.company_id)?.liquidity ?? null,
                confidence_penalized_by_liquidity: s.confidence_penalized_by_liquidity ?? false,
                atr_relative_percent: s.atr_relative_percent ?? null,
              }))}
            disabled={signalsSelected.length === 0}
            disabledReason="Coche au moins une entreprise pour l'analyser."
          />
        </Card>
      )}
        </>
      )}
    </div>
  )
}
