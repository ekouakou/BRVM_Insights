import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ResponsiveContainer, LineChart, Line, XAxis, YAxis, Tooltip, Legend, CartesianGrid, ReferenceLine } from 'recharts'
import { callApi } from '../lib/apiClient'
import type { BacktestResult, Company } from '../lib/types'
import { Card, ErrorState, InfoPanel, Input, LoadingState, Select, StatTile } from '../components/ui'
import { ChartAiAnalysis } from '../components/ChartAiAnalysis'

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function daysAgoIso(n: number) {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return d.toISOString().slice(0, 10)
}

const SMA_PERIODS = ['10', '20', '50']

export function Backtest() {
  const [companyId, setCompanyId] = useState<number | null>(null)
  const [startDate, setStartDate] = useState(daysAgoIso(180))
  const [endDate, setEndDate] = useState(todayIso())
  const [rule, setRule] = useState<'signal_score' | 'golden_cross'>('signal_score')
  const [buyThreshold, setBuyThreshold] = useState(1)
  const [sellThreshold, setSellThreshold] = useState(-1)
  const [fastSma, setFastSma] = useState('10')
  const [slowSma, setSlowSma] = useState('20')

  const companiesQuery = useQuery({
    queryKey: ['companies-list'],
    queryFn: () => callApi<Company[]>('api_companies.php', 'list', { per_page: 200, active: 1 }),
  })

  const params =
    rule === 'signal_score'
      ? { buy_threshold: buyThreshold, sell_threshold: sellThreshold }
      : { fast_sma: fastSma, slow_sma: slowSma }

  const backtestQuery = useQuery({
    queryKey: ['backtest', companyId, rule, startDate, endDate, params],
    queryFn: () =>
      callApi<BacktestResult>('api_backtest.php', 'run', {
        company_id: companyId,
        rule,
        start_date: startDate,
        end_date: endDate,
        ...params,
      }),
    enabled: !!companyId && startDate <= endDate && (rule !== 'golden_cross' || fastSma !== slowSma),
  })

  const companies = companiesQuery.data ?? []
  const result = backtestQuery.data

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Backtesting</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Simule l'application d'une règle de trading simple sur l'historique déjà synchronisé, et compare à un
          simple "acheter et garder".
        </p>
      </div>

      <InfoPanel>
        <p>
          <strong>À quoi sert cet écran.</strong> Répond à la question « si j'avais suivi mécaniquement cette règle
          depuis le début de la période, qu'est-ce que ça aurait donné, comparé à si j'avais simplement acheté et
          gardé le titre ? ». C'est une synthèse purement mécanique d'une règle simple sur des données passées —{' '}
          <strong>pas un conseil en investissement</strong>, et une performance passée ne garantit jamais une
          performance future.
        </p>
        <p>
          <strong>⚠️ Historique encore court.</strong> Ce moteur de simulation vient d'être mis en place et
          fonctionne dès maintenant sur l'historique disponible — mais avec seulement quelques jours de données
          synchronisées, un backtest n'a quasiment aucune valeur statistique (souvent 0 ou 1 opération sur toute la
          période). Un avertissement explicite s'affiche tant que la simulation couvre moins de 60 jours de bourse.
          Les résultats deviendront progressivement significatifs à mesure que l'historique s'accumule au fil des
          synchronisations quotidiennes — aucune action de ta part n'est nécessaire, reviens simplement plus tard.
        </p>
        <p>
          <strong>Règle « Signal composite ».</strong> Entre en position quand le score composite du jour (même
          calcul que l'onglet Signaux techniques de la page Cotations) atteint le seuil d'achat choisi, sort quand
          il retombe au seuil de vente ou en-dessous.
        </p>
        <p>
          <strong>Règle « Golden/death cross ».</strong> Entre en position au croisement haussier de la paire de
          moyennes mobiles choisie (la rapide passe au-dessus de la lente), sort au croisement baissier (l'inverse)
          — même détection que les points colorés déjà affichés sur le graphe de cours de la page Cotations.
        </p>
        <p>
          <strong>Courbe et tableau.</strong> La courbe d'équity part de 100 au premier jour simulé pour les deux
          approches (stratégie et acheter-garder), ce qui permet de comparer directement leur performance relative.
          Le tableau des opérations liste chaque position ouverte puis fermée, avec son rendement individuel.
        </p>
      </InfoPanel>

      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <label className="flex-1 min-w-[220px]">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Entreprise</span>
            <Select value={companyId ?? ''} onChange={(e) => setCompanyId(e.target.value ? Number(e.target.value) : null)}>
              <option value="">— Choisir —</option>
              {companies.map((c) => (
                <option key={c.company_id} value={c.company_id}>
                  {c.symbol} — {c.name}
                </option>
              ))}
            </Select>
          </label>
          <label className="w-44">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Règle</span>
            <Select value={rule} onChange={(e) => setRule(e.target.value as 'signal_score' | 'golden_cross')}>
              <option value="signal_score">Signal composite</option>
              <option value="golden_cross">Golden/death cross</option>
            </Select>
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

        {rule === 'signal_score' ? (
          <div className="mt-4 flex flex-wrap items-end gap-4">
            <label className="w-40">
              <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Seuil d'achat (≥)</span>
              <Select value={buyThreshold} onChange={(e) => setBuyThreshold(Number(e.target.value))}>
                {[2, 1, 0, -1].map((v) => <option key={v} value={v}>{v > 0 ? `+${v}` : v}</option>)}
              </Select>
            </label>
            <label className="w-40">
              <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Seuil de vente (≤)</span>
              <Select value={sellThreshold} onChange={(e) => setSellThreshold(Number(e.target.value))}>
                {[1, 0, -1, -2].map((v) => <option key={v} value={v}>{v > 0 ? `+${v}` : v}</option>)}
              </Select>
            </label>
          </div>
        ) : (
          <div className="mt-4 flex flex-wrap items-end gap-4">
            <label className="w-40">
              <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">SMA rapide</span>
              <Select value={fastSma} onChange={(e) => setFastSma(e.target.value)}>
                {SMA_PERIODS.map((p) => <option key={p} value={p}>SMA {p}</option>)}
              </Select>
            </label>
            <label className="w-40">
              <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">SMA lente</span>
              <Select value={slowSma} onChange={(e) => setSlowSma(e.target.value)}>
                {SMA_PERIODS.map((p) => <option key={p} value={p}>SMA {p}</option>)}
              </Select>
            </label>
            {fastSma === slowSma && (
              <p className="pb-2 text-xs text-red-600 dark:text-red-400">Les deux moyennes doivent être différentes.</p>
            )}
          </div>
        )}
      </Card>

      {!companyId && <p className="text-sm text-gray-500 dark:text-gray-400">Sélectionne une entreprise pour lancer une simulation.</p>}

      {companyId && backtestQuery.isLoading && <LoadingState label="Simulation en cours…" />}
      {companyId && backtestQuery.error && <ErrorState message={(backtestQuery.error as Error).message} />}

      {result && (
        <>
          {result.insufficient_history && (
            <div className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">
              ⚠️ Seulement {result.trading_days} jour(s) de bourse simulé(s) sur les {result.min_trading_days_for_reliability} recommandés
              pour un résultat statistiquement exploitable — {result.total_trades} opération(s) détectée(s) sur toute la période. À
              interpréter avec beaucoup de prudence, ou à revoir une fois l'historique de synchronisation plus long.
            </div>
          )}

          <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <StatTile label="Opérations" value={result.total_trades} />
            <StatTile label="Taux de réussite" value={result.win_rate_percent !== null ? `${result.win_rate_percent}%` : '—'} />
            <StatTile
              label="Stratégie"
              value={result.strategy_return_percent !== null ? `${result.strategy_return_percent > 0 ? '+' : ''}${result.strategy_return_percent}%` : '—'}
              tone={result.strategy_return_percent === null ? 'default' : result.strategy_return_percent > 0 ? 'positive' : result.strategy_return_percent < 0 ? 'negative' : 'default'}
            />
            <StatTile
              label="Acheter & garder"
              value={result.buy_hold_return_percent !== null ? `${result.buy_hold_return_percent > 0 ? '+' : ''}${result.buy_hold_return_percent}%` : '—'}
              tone={result.buy_hold_return_percent === null ? 'default' : result.buy_hold_return_percent > 0 ? 'positive' : result.buy_hold_return_percent < 0 ? 'negative' : 'default'}
            />
            <StatTile
              label="Vente à découvert"
              value={result.short_return_percent !== null ? `${result.short_return_percent > 0 ? '+' : ''}${result.short_return_percent}%` : '—'}
              tone={result.short_return_percent === null ? 'default' : result.short_return_percent > 0 ? 'positive' : result.short_return_percent < 0 ? 'negative' : 'default'}
            />
          </div>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            "Vente à découvert" simule une stratégie active indépendante avec sa propre logique d'entrée/sortie
            (l'inverse exact des signaux d'achat/vente utilisés par la stratégie longue — sans frais de portage/marge)
            — profite d'une baisse du cours, perd sur une hausse. Sert de repère pour une thèse baissière, pas un
            mode de trading réellement disponible sur la BRVM. {result.short_total_trades} opération(s) de vente à
            découvert détectée(s), taux de réussite {result.short_win_rate_percent !== null ? `${result.short_win_rate_percent}%` : '—'}.
          </p>

          {result.open_position && (
            <p className="text-xs text-gray-500 dark:text-gray-400">
              Position longue encore ouverte à la fin de la période (entrée le {result.open_position.entry_date} à {result.open_position.entry_price}) — comptabilisée en valeur de marché dans la courbe et les statistiques ci-dessus, mais pas dans le tableau des opérations (pas encore clôturée).
            </p>
          )}

          {result.open_short_position && (
            <p className="text-xs text-gray-500 dark:text-gray-400">
              Position vendeuse encore ouverte à la fin de la période (entrée le {result.open_short_position.entry_date} à {result.open_short_position.entry_price}) — comptabilisée en valeur de marché dans la courbe et les statistiques ci-dessus, mais pas dans le tableau des opérations (pas encore clôturée).
            </p>
          )}

          <Card title={`${result.symbol} — courbe d'équity (base 100)`}>
            <ResponsiveContainer width="100%" height={300}>
              <LineChart data={result.equity_curve}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                <YAxis domain={['auto', 'auto']} tick={{ fontSize: 11 }} width={60} />
                <ReferenceLine y={100} stroke="#9ca3af" strokeDasharray="3 3" />
                <Tooltip />
                <Legend />
                <Line type="monotone" dataKey="strategy_equity_base100" name="Stratégie" stroke="#4f46e5" dot={false} strokeWidth={2} />
                <Line type="monotone" dataKey="buy_hold_equity_base100" name="Acheter & garder" stroke="#9ca3af" dot={false} strokeWidth={2} strokeDasharray="4 2" />
                <Line type="monotone" dataKey="short_equity_base100" name="Vente à découvert" stroke="#e34948" dot={false} strokeWidth={2} strokeDasharray="4 2" />
              </LineChart>
            </ResponsiveContainer>

            <ChartAiAnalysis
              chartType="backtest"
              parameters={{ company_id: companyId, rule, ...params, start_date: startDate, end_date: endDate }}
              data={result}
            />
          </Card>

          {result.trades.length > 0 && (
            <Card title="Opérations">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead>
                    <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                      <th className="pb-2 pr-3">Entrée</th>
                      <th className="pb-2 pr-3 text-right">Prix entrée</th>
                      <th className="pb-2 pr-3">Sortie</th>
                      <th className="pb-2 pr-3 text-right">Prix sortie</th>
                      <th className="pb-2 text-right">Rendement</th>
                    </tr>
                  </thead>
                  <tbody>
                    {result.trades.map((t, i) => (
                      <tr key={i} className="border-t border-gray-100 dark:border-gray-800">
                        <td className="py-2 pr-3">{t.entry_date}</td>
                        <td className="py-2 pr-3 text-right tabular-nums">{t.entry_price}</td>
                        <td className="py-2 pr-3">{t.exit_date}</td>
                        <td className="py-2 pr-3 text-right tabular-nums">{t.exit_price}</td>
                        <td
                          className={`py-2 text-right tabular-nums font-medium ${
                            t.return_percent > 0 ? 'text-emerald-600 dark:text-emerald-400' : t.return_percent < 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-500'
                          }`}
                        >
                          {t.return_percent > 0 ? '+' : ''}{t.return_percent}%
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>
          )}

          {result.short_trades.length > 0 && (
            <Card title="Opérations — vente à découvert">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead>
                    <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                      <th className="pb-2 pr-3">Entrée</th>
                      <th className="pb-2 pr-3 text-right">Prix entrée</th>
                      <th className="pb-2 pr-3">Sortie</th>
                      <th className="pb-2 pr-3 text-right">Prix sortie</th>
                      <th className="pb-2 text-right">Rendement</th>
                    </tr>
                  </thead>
                  <tbody>
                    {result.short_trades.map((t, i) => (
                      <tr key={i} className="border-t border-gray-100 dark:border-gray-800">
                        <td className="py-2 pr-3">{t.entry_date}</td>
                        <td className="py-2 pr-3 text-right tabular-nums">{t.entry_price}</td>
                        <td className="py-2 pr-3">{t.exit_date}</td>
                        <td className="py-2 pr-3 text-right tabular-nums">{t.exit_price}</td>
                        <td
                          className={`py-2 text-right tabular-nums font-medium ${
                            t.return_percent > 0 ? 'text-emerald-600 dark:text-emerald-400' : t.return_percent < 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-500'
                          }`}
                        >
                          {t.return_percent > 0 ? '+' : ''}{t.return_percent}%
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>
          )}
        </>
      )}
    </div>
  )
}
