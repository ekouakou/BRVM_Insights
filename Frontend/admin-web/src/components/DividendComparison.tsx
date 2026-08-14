import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, Cell, LineChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { callApi } from '../lib/apiClient'
import type { Company, DividendCompareResult } from '../lib/types'
import { Card, ErrorState, LoadingState } from './ui'
import { ChartAiAnalysis } from './ChartAiAnalysis'

/**
 * Onglet « Dividendes » de l'écran Comparaison : confronte les entreprises
 * sélectionnées sur leur rendement, le montant versé par action et
 * l'historique des versements. Chaque entreprise garde sa couleur de
 * comparaison. Les entreprises sans dividende connu restent visibles et
 * annoncées comme telles — leur absence est une information.
 */

const nf = new Intl.NumberFormat('fr-FR')
const fmtF = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${nf.format(Math.round(v))} F`)

export function DividendComparisonPanel({
  companies,
  selectedIds,
  colorFor,
}: {
  companies: Company[]
  selectedIds: number[]
  colorFor: (companyId: number, selection?: number[]) => string
}) {
  const query = useQuery({
    queryKey: ['dividend-compare', [...selectedIds].sort((a, b) => a - b)],
    queryFn: () =>
      callApi<DividendCompareResult>('api_dividends.php', 'compare', { company_ids: selectedIds, months: 60 }),
    enabled: selectedIds.length > 0,
  })

  const data = query.data
  const symbolOf = (id: number) => companies.find((c) => c.company_id === id)?.symbol ?? String(id)

  const withDividend = useMemo(
    () => (data?.companies ?? []).filter((c) => c.payments_count > 0).sort((a, b) => (b.yield_percent ?? 0) - (a.yield_percent ?? 0)),
    [data],
  )
  const withoutDividend = useMemo(() => (data?.companies ?? []).filter((c) => c.payments_count === 0), [data])

  // Historique fusionné par date : une clé par entreprise.
  const merged = useMemo(() => {
    if (!data) return []
    const byDate = new Map<string, Record<string, number | string | null>>()
    for (const c of data.companies) {
      for (const p of c.payments) {
        const row = byDate.get(p.event_date) ?? { date: p.event_date }
        row[`y_${c.company_id}`] = p.yield_percent
        row[`a_${c.company_id}`] = p.amount
        byDate.set(p.event_date, row)
      }
    }
    return [...byDate.values()].sort((a, b) => String(a.date).localeCompare(String(b.date)))
  }, [data])

  if (selectedIds.length === 0) return null
  if (query.isLoading) return <LoadingState label="Comparaison des dividendes…" />
  if (query.error) return <ErrorState message={(query.error as Error).message} />
  if (!data) return null

  return (
    <div className="flex flex-col gap-4">
      {withoutDividend.length > 0 && (
        <div className="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
          <strong>Sans dividende connu :</strong> {withoutDividend.map((c) => c.symbol).join(', ')}. Aucun versement
          trouvé pour {withoutDividend.length > 1 ? 'ces titres' : 'ce titre'} dans les{' '}
          {data.coverage.bulletins_processed} bulletins analysés — ce qui ne prouve pas qu'
          {withoutDividend.length > 1 ? 'ils n\'en versent' : 'il n\'en verse'} pas.
        </div>
      )}

      <Card title="Rendement comparé (calculé)">
        {withDividend.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Aucune des entreprises sélectionnées n'a de dividende connu sur les bulletins déjà analysés.
          </p>
        ) : (
          <>
            <ResponsiveContainer width="100%" height={Math.max(160, 44 * withDividend.length + 40)}>
              <BarChart data={withDividend} layout="vertical" margin={{ top: 5, right: 45, bottom: 5, left: 10 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} horizontal={false} />
                <XAxis type="number" tick={{ fontSize: 10 }} tickFormatter={(v) => `${v}%`} />
                <YAxis type="category" dataKey="symbol" width={70} tick={{ fontSize: 11 }} />
                <Tooltip
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const c = payload[0].payload as (typeof withDividend)[number]
                    return (
                      <div className="max-w-xs rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <div className="font-medium">{c.symbol} — {c.name}</div>
                        <div className="mt-0.5">Rendement du dernier dividende : <strong>{c.yield_percent}%</strong></div>
                        <div>Dernier versement : {fmtF(c.last_amount)} le {c.last_date}</div>
                        <div>Cours de référence : {fmtF(c.last_price)} ({c.price_date})</div>
                        <div className="mt-1 text-gray-500 dark:text-gray-400">
                          Pour 1 000 000 F investis : ~{nf.format(Math.round((c.yield_percent ?? 0) * 10000))} F bruts.
                        </div>
                      </div>
                    )
                  }}
                />
                <Bar dataKey="yield_percent" name="Rendement %">
                  {withDividend.map((c) => (
                    <Cell key={c.company_id} fill={colorFor(c.company_id, selectedIds)} />
                  ))}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              Le rendement rapporte le dividende au prix de l'action : c'est le seul chiffre vraiment comparable
              entre entreprises, contrairement au montant en francs qui dépend du prix de l'action. {data.note}
            </p>
          </>
        )}
      </Card>

      {withDividend.length > 0 && (
        <Card title="Montant versé par action (observé)">
          <ResponsiveContainer width="100%" height={Math.max(160, 44 * withDividend.length + 40)}>
            <BarChart
              data={[...withDividend].sort((a, b) => (b.last_amount ?? 0) - (a.last_amount ?? 0))}
              layout="vertical"
              margin={{ top: 5, right: 55, bottom: 5, left: 10 }}
            >
              <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} horizontal={false} />
              <XAxis type="number" tick={{ fontSize: 10 }} tickFormatter={(v) => nf.format(v)} />
              <YAxis type="category" dataKey="symbol" width={70} tick={{ fontSize: 11 }} />
              <Tooltip
                content={({ active, payload }) => {
                  if (!active || !payload?.length) return null
                  const c = payload[0].payload as (typeof withDividend)[number]
                  return (
                    <div className="rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                      <div className="font-medium">{c.symbol}</div>
                      <div>{fmtF(c.last_amount)} par action ({c.last_date})</div>
                      <div>Cours : {fmtF(c.last_price)} · rendement {c.yield_percent}%</div>
                    </div>
                  )
                }}
              />
              <Bar dataKey="last_amount" name="F CFA par action">
                {withDividend.map((c) => (
                  <Cell key={c.company_id} fill={colorFor(c.company_id, selectedIds)} fillOpacity={0.65} />
                ))}
              </Bar>
            </BarChart>
          </ResponsiveContainer>
          <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Attention au piège : le titre qui verse le plus gros montant n'est pas forcément le plus rentable. Une
            action à 22 375 F qui verse 1 707 F rapporte 7,6 %, alors qu'une action à 17 450 F qui verse 420 F ne
            rapporte que 2,4 % — comparez toujours les rendements, pas les montants.
          </p>
        </Card>
      )}

      {merged.length > 1 && (
        <Card title="Historique des versements comparé">
          <ResponsiveContainer width="100%" height={240}>
            <LineChart data={merged} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
              <XAxis dataKey="date" tick={{ fontSize: 10 }} />
              <YAxis tick={{ fontSize: 10 }} width={50} tickFormatter={(v) => `${v}%`} />
              <Tooltip
                content={({ active, payload, label }) => {
                  if (!active || !payload?.length) return null
                  return (
                    <div className="rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                      <div className="font-medium">{label}</div>
                      {payload
                        .filter((p) => p.value !== null && p.value !== undefined)
                        .map((p) => {
                          const id = Number(String(p.dataKey).slice(2))
                          const c = data.companies.find((x) => x.company_id === id)
                          const pay = c?.payments.find((x) => x.event_date === label)
                          return (
                            <div key={String(p.dataKey)} style={{ color: p.color }}>
                              {symbolOf(id)} : {Number(p.value).toFixed(2)}%
                              {pay && ` (${fmtF(pay.amount)} par action)`}
                            </div>
                          )
                        })}
                    </div>
                  )
                }}
              />
              {selectedIds.map((id) => (
                <Line
                  key={id}
                  dataKey={`y_${id}`}
                  name={symbolOf(id)}
                  stroke={colorFor(id, selectedIds)}
                  strokeWidth={2}
                  dot={{ r: 4 }}
                  connectNulls
                />
              ))}
            </LineChart>
          </ResponsiveContainer>
          <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Chaque point est un versement, positionné à sa date, exprimé en % du cours actuel de l'entreprise. Les
            dates diffèrent d'une entreprise à l'autre (chacune paie après son assemblée générale) : les points ne
            sont donc pas alignés, c'est normal.
          </p>
        </Card>
      )}

      <Card title="Tableau comparatif">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                <th className="pb-2 pr-3">Entreprise</th>
                <th className="pb-2 pr-3 text-right">Rendement</th>
                <th className="pb-2 pr-3 text-right">Dernier dividende</th>
                <th className="pb-2 pr-3">Versé le</th>
                <th className="pb-2 pr-3 text-right">Cours</th>
                <th className="pb-2 pr-3 text-right">Versements connus</th>
                <th className="pb-2 pr-3 text-right">Cumul / action</th>
                <th className="pb-2 text-right">Pour 1 M F investis</th>
              </tr>
            </thead>
            <tbody>
              {[...withDividend, ...withoutDividend].map((c) => (
                <tr key={c.company_id} className="border-t border-gray-100 dark:border-gray-800">
                  <td className="py-1.5 pr-3 whitespace-nowrap">
                    <span className="mr-1.5 inline-block h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: colorFor(c.company_id, selectedIds) }} />
                    <span className="font-medium">{c.symbol}</span>
                    <span className="ml-1.5 text-xs text-gray-500 dark:text-gray-400">{c.name}</span>
                  </td>
                  <td className="py-1.5 pr-3 text-right font-semibold tabular-nums">
                    {c.yield_percent !== null ? `${c.yield_percent}%` : '—'}
                  </td>
                  <td className="py-1.5 pr-3 text-right tabular-nums">{fmtF(c.last_amount)}</td>
                  <td className="py-1.5 pr-3 whitespace-nowrap tabular-nums">{c.last_date ?? '—'}</td>
                  <td className="py-1.5 pr-3 text-right tabular-nums">{fmtF(c.last_price)}</td>
                  <td className="py-1.5 pr-3 text-right tabular-nums">{c.payments_count}</td>
                  <td className="py-1.5 pr-3 text-right tabular-nums">
                    {c.payments_count > 0 ? fmtF(c.total_amount) : '—'}
                  </td>
                  <td className="py-1.5 text-right tabular-nums">
                    {c.yield_percent !== null ? `${nf.format(Math.round(c.yield_percent * 10000))} F` : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
          « — » signifie qu'aucun dividende n'est connu pour cette entreprise dans les bulletins analysés, jamais
          qu'elle verse zéro. Montants bruts, avant l'IRVM (10 à 12 % selon votre situation).
        </p>
      </Card>

      <ChartAiAnalysis
        chartType="dividend_yield"
        parameters={{ company_ids: [...selectedIds].sort((a, b) => a - b), months: 60 }}
        data={data}
        companyIdsForReports={selectedIds}
      />
    </div>
  )
}
