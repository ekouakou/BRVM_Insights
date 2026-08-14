import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { callApi } from '../lib/apiClient'
import type { DividendCalendarResult, DividendRankingResult } from '../lib/types'
import { Card, ErrorState, LoadingState, Select, StatTile } from './ui'
import { ChartAiAnalysis } from './ChartAiAnalysis'

/**
 * Graphes de dividendes : rendement par entreprise, calendrier des
 * prochains détachements, saisonnalité des paiements. Source unique = les
 * dividendes extraits des Bulletins Officiels de la Cote (api_dividends.php,
 * dédoublonnés côté serveur). La couverture dépend du nombre de bulletins
 * déjà traités — elle est affichée en toutes lettres plutôt que masquée.
 */

const nf = new Intl.NumberFormat('fr-FR')
const fmtF = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${nf.format(Math.round(v))} F`)

/** Libellé de mois lisible (2026-08 → août 2026). */
function monthLabel(key: string): string {
  const [y, m] = key.split('-')
  const names = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.']
  return `${names[Number(m) - 1] ?? m} ${y}`
}

export function DividendCharts() {
  const [months, setMonths] = useState(24)

  const rankingQuery = useQuery({
    queryKey: ['dividend-ranking', months],
    queryFn: () => callApi<DividendRankingResult>('api_dividends.php', 'ranking', { months }),
  })
  const calendarQuery = useQuery({
    queryKey: ['dividend-calendar', months],
    queryFn: () => callApi<DividendCalendarResult>('api_dividends.php', 'calendar', { months }),
  })

  const ranking = rankingQuery.data
  const calendar = calendarQuery.data

  const yieldChart = useMemo(
    () => (ranking?.rows ?? []).filter((r) => r.yield_percent !== null).map((r) => ({ ...r, value: r.yield_percent as number })),
    [ranking],
  )
  const amountChart = useMemo(
    () =>
      (ranking?.rows ?? [])
        .filter((r) => r.last_amount !== null)
        .slice()
        .sort((a, b) => (b.last_amount ?? 0) - (a.last_amount ?? 0))
        .map((r) => ({ ...r, value: r.last_amount as number })),
    [ranking],
  )

  if (rankingQuery.isLoading || calendarQuery.isLoading) return <LoadingState label="Chargement des dividendes…" />
  if (rankingQuery.error) return <ErrorState message={(rankingQuery.error as Error).message} />
  if (calendarQuery.error) return <ErrorState message={(calendarQuery.error as Error).message} />
  if (!ranking || !calendar) return null

  const cov = ranking.coverage
  const bestYield = yieldChart[0]

  return (
    <div className="flex flex-col gap-4">
      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <label className="w-56">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Fenêtre analysée</span>
            <Select value={String(months)} onChange={(e) => setMonths(Number(e.target.value))}>
              <option value="12">12 derniers mois</option>
              <option value="24">24 derniers mois</option>
              <option value="36">36 derniers mois</option>
              <option value="60">5 ans</option>
            </Select>
          </label>
          <div className="text-xs text-gray-500 dark:text-gray-400">
            Données issues de <strong>{cov.bulletins_processed} bulletin{cov.bulletins_processed > 1 ? 's' : ''}</strong> déjà analysé
            {cov.bulletins_processed > 1 ? 's' : ''} : {cov.distinct_payments} paiement{cov.distinct_payments > 1 ? 's' : ''} distinct
            {cov.distinct_payments > 1 ? 's' : ''} pour {cov.companies} entreprise{cov.companies > 1 ? 's' : ''}
            {cov.first_date && cov.last_date && <> (du {cov.first_date} au {cov.last_date})</>}.
          </div>
        </div>
      </Card>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <StatTile label="Entreprises avec dividende connu" value={ranking.rows.length} />
        <StatTile label="Sans dividende connu" value={ranking.without_dividend.length} />
        <StatTile
          label="Meilleur rendement"
          value={bestYield ? `${bestYield.symbol} · ${bestYield.value}%` : '—'}
          tone={bestYield ? 'positive' : 'default'}
        />
        <StatTile label="Prochains détachements" value={calendar.upcoming.length} />
      </div>

      <Card title="Rendement du dividende par entreprise (calculé)">
        {yieldChart.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Aucun rendement calculable : il faut un dividende connu ET un cours récent.
          </p>
        ) : (
          <>
            <ResponsiveContainer width="100%" height={Math.max(200, 26 * yieldChart.length + 40)}>
              <BarChart data={yieldChart} layout="vertical" margin={{ top: 5, right: 40, bottom: 5, left: 10 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} horizontal={false} />
                <XAxis type="number" tick={{ fontSize: 10 }} tickFormatter={(v) => `${v}%`} />
                <YAxis type="category" dataKey="symbol" width={70} tick={{ fontSize: 11 }} interval={0} />
                <Tooltip
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const r = payload[0].payload as (typeof yieldChart)[number]
                    return (
                      <div className="max-w-xs rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <div className="font-medium">{r.symbol} — {r.name}</div>
                        <div className="mt-0.5">Rendement : <strong>{r.value}%</strong></div>
                        <div>Dernier dividende : {fmtF(r.last_amount)} par action, versé le {r.last_date}</div>
                        <div>Cours de référence : {fmtF(r.last_price)} ({r.price_date})</div>
                        <div className="mt-1 text-gray-500 dark:text-gray-400">
                          Pour 1 000 000 F investis, ce dividende rapporterait ~{nf.format(Math.round(r.value * 10000))} F bruts.
                        </div>
                      </div>
                    )
                  }}
                />
                <Bar dataKey="value" name="Rendement %">
                  {yieldChart.map((r) => (
                    <Cell
                      key={r.company_id}
                      fill={r.value >= 6 ? 'var(--chart-positive)' : r.value >= 3 ? 'var(--chart-2)' : 'var(--chart-soft)'}
                    />
                  ))}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              Le rendement, c'est ce que le dividende rapporte rapporté au prix payé aujourd'hui : 5 % signifie
              50 000 F de dividende brut pour 1 000 000 F investis. Vert : au-dessus de 6 % · orange : 3 à 6 % ·
              gris : en dessous de 3 %. Attention, un rendement très élevé vient parfois d'un cours qui a chuté,
              pas d'une générosité particulière. {ranking.note}
            </p>
          </>
        )}
      </Card>

      <Card title="Tableau des rendements">
        {ranking.rows.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400">Aucun dividende connu sur la fenêtre choisie.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  <th className="pb-2 pr-3">#</th>
                  <th className="pb-2 pr-3">Entreprise</th>
                  <th className="pb-2 pr-3 text-right">Rendement</th>
                  <th className="pb-2 pr-3 text-right">Dernier dividende</th>
                  <th className="pb-2 pr-3">Versé le</th>
                  <th className="pb-2 pr-3 text-right">Cours</th>
                  <th className="pb-2 pr-3 text-right">Versements</th>
                  <th className="pb-2 pr-3 text-right">Cumul / action</th>
                  <th className="pb-2 text-right">Pour 1 M F investis</th>
                </tr>
              </thead>
              <tbody>
                {ranking.rows.map((r, i) => (
                  <tr key={r.company_id} className="border-t border-gray-100 dark:border-gray-800">
                    <td className="py-1.5 pr-3 tabular-nums text-gray-400">{i + 1}</td>
                    <td className="py-1.5 pr-3 whitespace-nowrap">
                      <span className="font-medium">{r.symbol}</span>
                      <span className="ml-1.5 text-xs text-gray-500 dark:text-gray-400">{r.name}</span>
                    </td>
                    <td className="py-1.5 pr-3 text-right font-semibold tabular-nums">
                      {r.yield_percent !== null ? (
                        <span
                          className={
                            r.yield_percent >= 6
                              ? 'text-emerald-600 dark:text-emerald-400'
                              : r.yield_percent >= 3
                                ? 'text-amber-600 dark:text-amber-400'
                                : ''
                          }
                        >
                          {r.yield_percent}%
                        </span>
                      ) : (
                        '—'
                      )}
                    </td>
                    <td className="py-1.5 pr-3 text-right tabular-nums">{fmtF(r.last_amount)}</td>
                    <td className="py-1.5 pr-3 whitespace-nowrap tabular-nums">{r.last_date ?? '—'}</td>
                    <td className="py-1.5 pr-3 text-right tabular-nums">{fmtF(r.last_price)}</td>
                    <td className="py-1.5 pr-3 text-right tabular-nums">{r.payments}</td>
                    <td className="py-1.5 pr-3 text-right tabular-nums">{fmtF(r.total_amount)}</td>
                    <td className="py-1.5 text-right tabular-nums">
                      {r.yield_percent !== null ? `${nf.format(Math.round(r.yield_percent * 10000))} F` : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
          « Pour 1 M F investis » traduit le rendement en francs : c'est le dividende brut que rapporterait
          1 000 000 F placés sur ce titre au cours actuel, avant l'IRVM (impôt sur les revenus de valeurs
          mobilières, 10 à 12 % selon votre situation). « Cumul / action » additionne tous les versements connus
          sur la fenêtre — il dépend donc du nombre de bulletins analysés, contrairement au rendement du dernier
          dividende qui reste comparable entre entreprises.
        </p>
      </Card>

      <Card title="Montant du dernier dividende par action (observé)">
        {amountChart.length > 0 && (
          <>
            <ResponsiveContainer width="100%" height={Math.max(200, 26 * amountChart.length + 40)}>
              <BarChart data={amountChart} layout="vertical" margin={{ top: 5, right: 50, bottom: 5, left: 10 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} horizontal={false} />
                <XAxis type="number" tick={{ fontSize: 10 }} tickFormatter={(v) => nf.format(v)} />
                <YAxis type="category" dataKey="symbol" width={70} tick={{ fontSize: 11 }} interval={0} />
                <Tooltip
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const r = payload[0].payload as (typeof amountChart)[number]
                    return (
                      <div className="rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <div className="font-medium">{r.symbol}</div>
                        <div>{fmtF(r.last_amount)} par action ({r.last_date})</div>
                        <div>Rendement : {r.yield_percent !== null ? `${r.yield_percent}%` : '—'}</div>
                      </div>
                    )
                  }}
                />
                <Bar dataKey="value" name="F CFA par action" fill="var(--chart-1)" />
              </BarChart>
            </ResponsiveContainer>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              Montant brut annoncé par action. Un gros montant n'est pas un bon rendement en soi : tout dépend du prix
              de l'action — d'où le graphe précédent.
            </p>
          </>
        )}
      </Card>

      <Card title="Prochains détachements">
        {calendar.upcoming.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Aucun paiement à venir dans les bulletins déjà traités.
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  <th className="pb-2 pr-3">Date</th>
                  <th className="pb-2 pr-3">Dans</th>
                  <th className="pb-2 pr-3">Entreprise</th>
                  <th className="pb-2 pr-3 text-right">Montant / action</th>
                  <th className="pb-2">Détail</th>
                </tr>
              </thead>
              <tbody>
                {calendar.upcoming.map((u, i) => (
                  <tr key={i} className="border-t border-gray-100 align-top dark:border-gray-800">
                    <td className="py-1.5 pr-3 whitespace-nowrap tabular-nums">{u.event_date}</td>
                    <td className="py-1.5 pr-3 whitespace-nowrap tabular-nums">
                      {u.days_from_today === 0 ? "aujourd'hui" : `${u.days_from_today} j`}
                    </td>
                    <td className="py-1.5 pr-3 whitespace-nowrap">
                      <span className="font-medium">{u.symbol}</span>
                      <span className="ml-1.5 text-xs text-gray-500 dark:text-gray-400">{u.name}</span>
                    </td>
                    <td className="py-1.5 pr-3 text-right font-semibold tabular-nums">{fmtF(u.amount)}</td>
                    <td className="py-1.5 max-w-md text-xs text-gray-500 dark:text-gray-400">{u.description ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">{calendar.note}</p>
      </Card>

      {calendar.monthly.length > 1 && (
        <Card title="Saisonnalité : montants versés par mois">
          <ResponsiveContainer width="100%" height={220}>
            <BarChart data={calendar.monthly} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
              <XAxis dataKey="month" tick={{ fontSize: 10 }} tickFormatter={monthLabel} />
              <YAxis tick={{ fontSize: 10 }} width={70} tickFormatter={(v) => nf.format(v)} />
              <Tooltip
                content={({ active, payload }) => {
                  if (!active || !payload?.length) return null
                  const m = payload[0].payload as DividendCalendarResult['monthly'][number]
                  return (
                    <div className="rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                      <div className="font-medium">{monthLabel(m.month)}</div>
                      <div>{m.payments} paiement{m.payments > 1 ? 's' : ''}</div>
                      <div>Cumul des montants par action : {fmtF(m.total_amount)}</div>
                    </div>
                  )
                }}
              />
              <Bar dataKey="total_amount" name="Cumul par action" fill="var(--chart-5)" />
            </BarChart>
          </ResponsiveContainer>
          <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
            À la BRVM, les dividendes se concentrent après les assemblées générales (souvent de juin à septembre) :
            ce graphe montre quand tomberaient les versements sur la fenêtre analysée. Les montants de plusieurs
            entreprises sont additionnés — c'est un repère de calendrier, pas un revenu.
          </p>
        </Card>
      )}

      {ranking.without_dividend.length > 0 && (
        <Card title={`Entreprises sans dividende connu (${ranking.without_dividend.length})`}>
          <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
            Aucun dividende trouvé pour ces titres dans les bulletins déjà traités. Cela ne prouve pas qu'elles n'en
            versent pas : un dividende annoncé dans un bulletin non encore analysé n'apparaît pas ici.
          </p>
          <div className="flex flex-wrap gap-1.5">
            {ranking.without_dividend.map((c) => (
              <span key={c.company_id} className="rounded border border-gray-200 px-1.5 py-0.5 text-xs text-gray-600 dark:border-gray-700 dark:text-gray-300" title={c.name}>
                {c.symbol}
              </span>
            ))}
          </div>
        </Card>
      )}

      <ChartAiAnalysis
        chartType="dividend_yield"
        parameters={{ months }}
        data={{ ranking: ranking.rows, upcoming: calendar.upcoming, monthly: calendar.monthly, coverage: cov }}
      />
    </div>
  )
}
