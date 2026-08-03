import { useState } from 'react'
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
  CartesianGrid,
  ReferenceLine,
} from 'recharts'
import { callApi } from '../lib/apiClient'
import type { Company, IntradayPoint, OhlcPoint } from '../lib/types'
import { Card, ErrorState, Input, LoadingState, Select } from '../components/ui'

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

export function Quotes() {
  const [companyId, setCompanyId] = useState<number | null>(null)
  const [days, setDays] = useState(180)
  const [intradayDate, setIntradayDate] = useState(todayIso())

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

  const companies = companiesQuery.data ?? []
  const intradayData = (intradayQuery.data ?? []).map((p) => ({
    ...p,
    time: p.quote_datetime.slice(11, 16), // "HH:MM" pour l'axe X
    price: Number(p.price),
    variation_percent: p.variation_percent !== null ? Number(p.variation_percent) : null,
  }))

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
                  formatter={(value: number, name) =>
                    name === 'variation_percent' ? [`${value.toFixed(2)}%`, 'Variation'] : [value, name]
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
    </div>
  )
}
