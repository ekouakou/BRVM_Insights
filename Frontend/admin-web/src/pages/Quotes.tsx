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
import type { Company, CompanyPriceSeries, IntradayPoint, OhlcPoint } from '../lib/types'
import { Card, ErrorState, Input, LoadingState, Select } from '../components/ui'
import { colorForCompany, groupCompaniesBySector } from '../lib/companyGroups'

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

  const intradayQuery = useQuery({
    queryKey: ['intraday', companyId, intradayDate],
    queryFn: () =>
      callApi<IntradayPoint[]>('api_quotes.php', 'intraday', { company_id: companyId, trading_date: intradayDate }),
    enabled: !!companyId,
  })

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
  })

  const companies = companiesQuery.data ?? []
  const intradayData = (intradayQuery.data ?? []).map((p) => ({
    ...p,
    time: p.quote_datetime.slice(11, 16), // "HH:MM" pour l'axe X
    price: Number(p.price),
    variation_percent: p.variation_percent !== null ? Number(p.variation_percent) : null,
  }))

  const { sectors, unclassified } = useMemo(() => groupCompaniesBySector(companies), [companies])

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
            <ResponsiveContainer width="100%" height={280}>
              <LineChart data={ohlcQuery.data}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                <YAxis domain={['auto', 'auto']} tick={{ fontSize: 11 }} width={70} />
                <Tooltip />
                <Line type="monotone" dataKey="close" stroke="#4f46e5" dot={false} strokeWidth={2} />
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
          title={showPercent ? 'Variation depuis le début de la période' : 'Cours (FCFA)'}
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
    </div>
  )
}
