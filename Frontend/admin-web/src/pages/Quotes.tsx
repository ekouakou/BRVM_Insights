import { useMemo, useState } from 'react'
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
} from 'recharts'
import { callApi } from '../lib/apiClient'
import type {
  Company,
  CompanyLiquidity,
  CompanyPriceSeries,
  CompanySignal,
  IntradayPoint,
  OhlcPoint,
  TechnicalIndicatorPoint,
} from '../lib/types'
import { Card, ErrorState, Input, LoadingState, Select } from '../components/ui'
import { colorForCompany, groupCompaniesBySector } from '../lib/companyGroups'

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
  const [companyId, setCompanyId] = useState<number | null>(null)
  const [days, setDays] = useState(180)
  const [intradayDate, setIntradayDate] = useState(todayIso())

  const [showSma, setShowSma] = useState({ sma_10: true, sma_20: true, sma_50: false })

  const [compareSelected, setCompareSelected] = useState<number[]>([])
  const [compareGranularity, setCompareGranularity] = useState<'daily' | 'intraday'>('daily')
  const [compareStartDate, setCompareStartDate] = useState(daysAgoIso(180))
  const [compareEndDate, setCompareEndDate] = useState(todayIso())
  const [showPercent, setShowPercent] = useState(true)

  const companiesQuery = useQuery({
    queryKey: ['companies-list'],
    queryFn: () => callApi<Company[]>('api_companies.php', 'list', { per_page: 200, active: 1 }),
  })

  const ohlcQuery = useQuery({
    queryKey: ['ohlc', companyId, days],
    queryFn: () => callApi<OhlcPoint[]>('api_quotes.php', 'ohlc', { company_id: companyId, days }),
    enabled: !!companyId,
  })

  // Moyennes mobiles déjà calculées et stockées à chaque synchro
  // (class/TechnicalIndicatorsCalculator.php) — jamais affichées jusqu'ici.
  const indicatorsQuery = useQuery({
    queryKey: ['technical-indicators', companyId, days],
    queryFn: () =>
      callApi<TechnicalIndicatorPoint[]>('api_technical_indicators.php', 'get_indicators', { company_id: companyId, days }),
    enabled: !!companyId,
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
  const signalsListQuery = useQuery({
    queryKey: ['signals-list'],
    queryFn: () => callApi<CompanySignal[]>('api_signals.php', 'list', {}),
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

  // Un relevé intrajournalier arrive toutes les ~10 min pendant la séance —
  // sans rafraîchissement automatique, le graphe reste figé sur les données
  // chargées à l'ouverture de la page jusqu'au prochain rechargement manuel.
  // Inutile de reinterroger une date passée (immuable), donc seulement
  // activé quand la période affichée inclut aujourd'hui.
  const intradayIsLive = intradayDate === todayIso()
  const intradayQuery = useQuery({
    queryKey: ['intraday', companyId, intradayDate],
    queryFn: () =>
      callApi<IntradayPoint[]>('api_quotes.php', 'intraday', { company_id: companyId, trading_date: intradayDate }),
    enabled: !!companyId,
    refetchInterval: intradayIsLive ? 60_000 : false,
  })

  const compareIsLive = compareGranularity === 'intraday' && compareEndDate === todayIso()
  const compareQuery = useQuery({
    queryKey: ['compare-quotes', compareSelected, compareGranularity, compareStartDate, compareEndDate],
    queryFn: () =>
      callApi<CompanyPriceSeries[]>('api_quotes.php', 'compare', {
        company_ids: compareSelected,
        ...(compareGranularity === 'intraday' ? { granularity: 'intraday' } : {}),
        start_date: compareStartDate,
        end_date: compareEndDate,
      }),
    enabled: compareSelected.length > 0 && compareStartDate <= compareEndDate,
    refetchInterval: compareIsLive ? 60_000 : false,
  })

  const companies = companiesQuery.data ?? []
  const intradayData = (intradayQuery.data ?? []).map((p) => ({
    ...p,
    time: p.quote_datetime.slice(11, 16), // "HH:MM" pour l'axe X
    price: Number(p.price),
    variation_percent: p.variation_percent !== null ? Number(p.variation_percent) : null,
  }))

  const { sectors, unclassified } = useMemo(() => groupCompaniesBySector(companies), [companies])

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
      }
    })
  }, [ohlcQuery.data, indicatorsQuery.data])

  function toggleCompare(id: number) {
    setCompareSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  const compareSeries = compareQuery.data ?? []

  // Fusionne les séries par date ("format large" attendu par recharts : une
  // ligne par date, une colonne par entreprise) — variation en % depuis le
  // début de la période par défaut, car les cours bruts ont des échelles
  // trop différentes d'une entreprise à l'autre (ex: 70 FCFA vs 38 900 FCFA)
  // pour être lisibles sur un même axe.
  // En intraday, les entreprises ne sont pas forcément relevées à la même
  // seconde exacte (cron ~10 min, mais les relevés d'un même passage peuvent
  // différer de quelques secondes) — on regroupe donc par minute plutôt que
  // par timestamp exact, sinon chaque entreprise finirait sur sa propre ligne.
  // Sur un seul jour on n'affiche que l'heure (HH:MM) ; sur plusieurs jours,
  // la date reste dans la clé pour ne pas fusionner des heures identiques de
  // jours différents.
  const compareSingleDay = compareGranularity === 'intraday' && compareStartDate === compareEndDate
  const compareChartData = useMemo(() => {
    const byKey = new Map<string, Record<string, number | string>>()

    for (const serie of compareSeries) {
      const basePrice = serie.data.length > 0 ? Number(serie.data[0].price) : 0
      for (const point of serie.data) {
        const key =
          compareGranularity !== 'intraday' ? point.date : compareSingleDay ? point.date.slice(11, 16) : point.date.slice(0, 16)
        const row = byKey.get(key) ?? { date: key }
        const price = Number(point.price)
        row[serie.symbol] = showPercent ? (basePrice ? (price / basePrice - 1) * 100 : 0) : price
        byKey.set(key, row)
      }
    }

    return Array.from(byKey.values()).sort((a, b) => String(a.date).localeCompare(String(b.date)))
  }, [compareSeries, showPercent, compareGranularity, compareSingleDay])

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Cotations</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">Historique de prix et de volume par entreprise</p>
      </div>

      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <label className="flex-1 min-w-[220px]">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Entreprise</span>
            <Select
              value={companyId ?? ''}
              onChange={(e) => setCompanyId(e.target.value ? Number(e.target.value) : null)}
            >
              <option value="">— Choisir —</option>
              {companies.map((c) => (
                <option key={c.company_id} value={c.company_id}>
                  {c.symbol} — {c.name}
                </option>
              ))}
            </Select>
          </label>

          <label className="w-40">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Période (jours)</span>
            <Select value={days} onChange={(e) => setDays(Number(e.target.value))}>
              <option value={30}>30 jours</option>
              <option value={90}>90 jours</option>
              <option value={180}>180 jours</option>
              <option value={365}>1 an</option>
            </Select>
          </label>
        </div>
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
          <div className="mb-3 flex flex-wrap items-end gap-4">
            <label className="w-44">
              <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Journée de cotation</span>
              <Input type="date" value={intradayDate} onChange={(e) => setIntradayDate(e.target.value)} />
            </label>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              Un point par synchronisation (toutes les ~5 min pendant les heures de marché) — jamais écrasé, contrairement
              au cours de clôture du jour ci-dessous.
            </p>
          </div>

          {intradayQuery.isLoading && <LoadingState />}
          {intradayQuery.error && <ErrorState message={(intradayQuery.error as Error).message} />}
          {intradayQuery.data && intradayData.length === 0 && (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              Aucun relevé pour cette date (hors heures de marché, ou synchro pas encore passée).
            </p>
          )}
          {intradayData.length > 0 && (
            <ResponsiveContainer width="100%" height={240}>
              <LineChart data={intradayData}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                <XAxis dataKey="time" tick={{ fontSize: 11 }} minTickGap={30} />
                <YAxis
                  domain={['auto', 'auto']}
                  tick={{ fontSize: 11 }}
                  width={60}
                  tickFormatter={(v) => `${v}%`}
                />
                <ReferenceLine y={0} stroke="#9ca3af" strokeDasharray="3 3" />
                <Tooltip
                  formatter={(value, name) =>
                    name === 'variation_percent' ? [`${Number(value).toFixed(2)}%`, 'Variation'] : [value, name]
                  }
                  labelFormatter={(label) => `Heure : ${label}`}
                />
                <Line type="monotone" dataKey="variation_percent" stroke="#4f46e5" dot strokeWidth={2} />
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
            </div>
            <ResponsiveContainer width="100%" height={280}>
              <LineChart data={priceWithSma}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                <YAxis domain={['auto', 'auto']} tick={{ fontSize: 11 }} width={70} />
                <Tooltip />
                <Legend />
                <Line type="monotone" dataKey="close" name="Clôture" stroke="#4f46e5" dot={false} strokeWidth={2} />
                {showSma.sma_10 && (
                  <Line type="monotone" dataKey="sma_10" name="SMA 10" stroke="#eb6834" dot={false} strokeWidth={1.5} connectNulls />
                )}
                {showSma.sma_20 && (
                  <Line type="monotone" dataKey="sma_20" name="SMA 20" stroke="#1baf7a" dot={false} strokeWidth={1.5} connectNulls />
                )}
                {showSma.sma_50 && (
                  <Line type="monotone" dataKey="sma_50" name="SMA 50" stroke="#eda100" dot={false} strokeWidth={1.5} connectNulls />
                )}
              </LineChart>
            </ResponsiveContainer>
          </Card>

          <Card title="Volume">
            <ResponsiveContainer width="100%" height={140}>
              <BarChart data={ohlcQuery.data}>
                <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                <YAxis tick={{ fontSize: 11 }} width={70} />
                <Tooltip />
                <Bar dataKey="volume" fill="#a5b4fc" />
              </BarChart>
            </ResponsiveContainer>
          </Card>
        </>
      )}

      <div>
        <h2 className="text-xl font-semibold">Comparaison entre entreprises</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Coche des entreprises pour superposer leurs cours sur un même graphe — regroupées par secteur d'activité
          pour les retrouver facilement.
        </p>
      </div>

      <Card>
        <div className="flex flex-col gap-4">
          <div className="max-h-64 overflow-y-auto rounded-md border border-gray-200 p-3 dark:border-gray-800">
            {sectors.map((sector) => (
              <div key={sector.name} className="mb-3 last:mb-0">
                <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  {sector.name}
                </div>
                <div className="flex flex-wrap gap-2">
                  {sector.members.map((c) => (
                    <label
                      key={c.company_id}
                      className={`cursor-pointer rounded-full border px-3 py-1 text-xs font-medium ${
                        compareSelected.includes(c.company_id)
                          ? 'text-white'
                          : 'border-gray-300 text-gray-600 dark:border-gray-700 dark:text-gray-300'
                      }`}
                      style={
                        compareSelected.includes(c.company_id)
                          ? { backgroundColor: colorForCompany(c.company_id), borderColor: colorForCompany(c.company_id) }
                          : undefined
                      }
                    >
                      <input
                        type="checkbox"
                        className="hidden"
                        checked={compareSelected.includes(c.company_id)}
                        onChange={() => toggleCompare(c.company_id)}
                      />
                      {c.symbol}
                    </label>
                  ))}
                </div>
              </div>
            ))}

            {unclassified.length > 0 && (
              <div>
                <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  Secteur non renseigné
                </div>
                <div className="flex flex-wrap gap-2">
                  {unclassified.map((c) => (
                    <label
                      key={c.company_id}
                      className={`cursor-pointer rounded-full border px-3 py-1 text-xs font-medium ${
                        compareSelected.includes(c.company_id)
                          ? 'text-white'
                          : 'border-gray-300 text-gray-600 dark:border-gray-700 dark:text-gray-300'
                      }`}
                      style={
                        compareSelected.includes(c.company_id)
                          ? { backgroundColor: colorForCompany(c.company_id), borderColor: colorForCompany(c.company_id) }
                          : undefined
                      }
                    >
                      <input
                        type="checkbox"
                        className="hidden"
                        checked={compareSelected.includes(c.company_id)}
                        onChange={() => toggleCompare(c.company_id)}
                      />
                      {c.symbol}
                    </label>
                  ))}
                </div>
              </div>
            )}
          </div>

          <div className="flex flex-wrap items-end gap-4">
            <label className="w-40">
              <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Granularité</span>
              <Select
                value={compareGranularity}
                onChange={(e) => setCompareGranularity(e.target.value as 'daily' | 'intraday')}
              >
                <option value="daily">Clôtures quotidiennes</option>
                <option value="intraday">Intrajournalier</option>
              </Select>
            </label>

            <label className="w-40">
              <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de début</span>
              <Input type="date" value={compareStartDate} onChange={(e) => setCompareStartDate(e.target.value)} />
            </label>

            <label className="w-40">
              <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de fin</span>
              <Input type="date" value={compareEndDate} onChange={(e) => setCompareEndDate(e.target.value)} />
            </label>

            {compareStartDate > compareEndDate && (
              <p className="pb-2 text-sm text-red-600 dark:text-red-400">La date de début doit précéder la date de fin.</p>
            )}

            <label className="flex items-center gap-2 pb-2 text-sm text-gray-700 dark:text-gray-300">
              <input type="checkbox" checked={showPercent} onChange={(e) => setShowPercent(e.target.checked)} />
              Variation en % (recommandé)
            </label>

            {compareSelected.length > 0 && (
              <button
                type="button"
                onClick={() => setCompareSelected([])}
                className="pb-2 text-sm text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
              >
                Tout décocher
              </button>
            )}
          </div>
        </div>
      </Card>

      {compareSelected.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Coche au moins une entreprise pour afficher le graphe combiné.</p>
      )}

      {compareSelected.length > 0 && compareQuery.isLoading && <LoadingState label="Chargement des cotations…" />}
      {compareSelected.length > 0 && compareQuery.error && <ErrorState message={(compareQuery.error as Error).message} />}

      {compareSelected.length > 0 && compareChartData.length > 0 && (
        <Card
          title={
            (showPercent ? 'Variation depuis le début de la période' : 'Cours (FCFA)') +
            (compareIsLive ? ' · actualisation auto (1 min)' : '')
          }
        >
          <ResponsiveContainer width="100%" height={360}>
            <LineChart data={compareChartData}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
              <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
              <YAxis
                domain={['auto', 'auto']}
                tick={{ fontSize: 11 }}
                width={60}
                tickFormatter={(v: number) => (showPercent ? `${v.toFixed(1)}%` : String(v))}
              />
              {showPercent && <ReferenceLine y={0} stroke="#9ca3af" strokeDasharray="3 3" />}
              <Tooltip
                labelFormatter={(label) => (compareGranularity === 'intraday' ? `Heure : ${label}` : `Date : ${label}`)}
                formatter={(value, name) =>
                  showPercent ? [`${Number(value) > 0 ? '+' : ''}${Number(value).toFixed(2)}%`, name] : [value, name]
                }
              />
              <Legend />
              {compareSeries.map((serie) => (
                <Line
                  key={serie.company_id}
                  type="monotone"
                  dataKey={serie.symbol}
                  stroke={colorForCompany(serie.company_id)}
                  dot={false}
                  strokeWidth={2}
                  connectNulls
                />
              ))}
            </LineChart>
          </ResponsiveContainer>
        </Card>
      )}

      {compareSelected.length > 0 && compareQuery.data && compareChartData.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Aucune cotation trouvée pour la sélection sur cette période.</p>
      )}

      <div>
        <h2 className="text-xl font-semibold">Signaux techniques</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Score composite (-2 à +2) basé sur RSI, MACD, tendance et Bollinger — synthèse mécanique des indicateurs déjà
          calculés à chaque synchro, classée par force. Pas un conseil financier. Survole les en-têtes de colonnes pour
          le détail de chaque mesure — vérifie en particulier la colonne Liquidité avant de te fier à un signal sur un
          titre qui n'a pas tradé récemment.
        </p>
      </div>

      {signalsListQuery.isLoading && <LoadingState label="Chargement des signaux…" />}
      {signalsListQuery.error && <ErrorState message={(signalsListQuery.error as Error).message} />}

      {signalsListQuery.data && signalsListQuery.data.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Aucun indicateur technique calculé pour l'instant.
        </p>
      )}

      {signalsListQuery.data && signalsListQuery.data.length > 0 && (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
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
                    className="pb-2"
                    title="Score composite (-2 à +2) basé sur RSI, MACD, tendance (cours vs moyenne mobile) et Bandes de Bollinger — synthèse mécanique, pas un conseil financier"
                  >
                    Signal
                  </th>
                </tr>
              </thead>
              <tbody>
                {signalsListQuery.data.map((s) => {
                  const liquidity = liquidityByCompany.get(s.company_id)
                  return (
                  <tr key={s.company_id} className="border-t border-gray-100 dark:border-gray-800">
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
                    <td className="py-2">
                      <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${signalBadgeClass(s.score)}`}>
                        {s.label}
                      </span>
                    </td>
                  </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  )
}
