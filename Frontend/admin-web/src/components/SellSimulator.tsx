import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Bar, BarChart, Cell, LabelList, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { callApi } from '../lib/apiClient'
import type { SellSimulationResult } from '../lib/types'
import { Card, ErrorState, Input, LoadingState } from './ui'

/**
 * Simulateur « file d'attente & stratégie de vente » : répond à la question
 * concrète « si nous sommes plusieurs à vouloir vendre en même temps, où
 * est-ce que je me place, combien de temps j'attends, et que me coûte le
 * fait de passer devant ? ».
 *
 * Tout repose sur des faits observés (carnet publié en fin de séance,
 * exécutions reconstituées) ; les délais sont des estimations et les
 * limites de la simulation sont affichées à l'écran, jamais masquées.
 */

const nf = new Intl.NumberFormat('fr-FR')
const fmtF = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${nf.format(Math.round(v))} F`)
const fmtQty = (v: number | null | undefined) => (v === null || v === undefined ? '—' : nf.format(v))

/** Délai en séances traduit en langage courant. */
function sessionsLabel(v: number | null | undefined): string {
  if (v === null || v === undefined) return 'délai inconnu'
  if (v < 0.15) return 'quasi immédiat'
  if (v < 1) return `moins d'une séance (~${Math.round(v * 4.5)} h de cotation)`
  if (v < 2) return `environ ${v.toFixed(1)} séance`
  return `environ ${v.toFixed(1)} séances`
}

const IMPACT_STYLES: Record<string, { label: string; cls: string }> = {
  faible: { label: 'Impact faible', cls: 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300' },
  modere: { label: 'Impact modéré', cls: 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300' },
  eleve: { label: 'Impact élevé', cls: 'border-orange-300 bg-orange-50 text-orange-800 dark:border-orange-900 dark:bg-orange-950 dark:text-orange-300' },
  critique: { label: 'Impact critique', cls: 'border-red-300 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-300' },
}

export function SellSimulator({ companyId }: { companyId: number }) {
  const [quantity, setQuantity] = useState('1000')
  const [groupQuantity, setGroupQuantity] = useState('')

  const qty = Math.max(1, Number(quantity) || 0)
  const groupQty = Math.max(0, Number(groupQuantity) || 0)

  const query = useQuery({
    queryKey: ['sell-simulation', companyId, qty, groupQty],
    queryFn: () =>
      callApi<SellSimulationResult>('api_order_book.php', 'sell_simulation', {
        company_id: companyId,
        quantity: qty,
        group_quantity: groupQty || undefined,
      }),
    enabled: !!companyId && qty > 0,
  })

  const d = query.data
  const s = d?.scenarios
  const impact = d?.group_impact
  const impactStyle = impact ? IMPACT_STYLES[impact.level] : null

  // Barres de comparaison des trois stratégies : coût total en francs.
  const costChart = d
    ? [
        s?.immediate && {
          key: 'Vendre tout de suite',
          cost: s.immediate.total_cost ?? 0,
          detail: `${fmtQty(s.immediate.served_immediately)} titres servis immédiatement`,
          color: 'var(--chart-negative)',
        },
        s?.undercut && {
          key: 'Décoter d\'un pas',
          cost: s.undercut.total_cost,
          detail: `passe devant ${fmtQty(s.undercut.jumped_ahead_of)} titres`,
          color: 'var(--chart-2)',
        },
        s?.queue && {
          key: 'Faire la queue',
          cost: 0,
          detail: `${fmtQty(s.queue.queue_ahead)} titres devant vous`,
          color: 'var(--chart-positive)',
        },
      ].filter(Boolean as unknown as (v: unknown) => v is { key: string; cost: number; detail: string; color: string })
    : []

  return (
    <div className="flex flex-col gap-4">
      <Card title="Simulateur : où je me place dans la file de vente ?">
        <div className="flex flex-wrap items-end gap-4">
          <label className="w-48">
            <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Je veux vendre (titres)</span>
            <Input type="number" min="1" value={quantity} onChange={(e) => setQuantity(e.target.value)} />
          </label>
          <label className="w-64">
            <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
              Total que le groupe veut vendre (optionnel)
            </span>
            <Input
              type="number"
              min="0"
              placeholder="ex : 5000 si vous êtes plusieurs"
              value={groupQuantity}
              onChange={(e) => setGroupQuantity(e.target.value)}
            />
          </label>
        </div>
        <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
          Renseignez le total du groupe si plusieurs personnes veulent vendre le même titre en même temps : c'est ce
          qui détermine si vos ventes vont se gêner entre elles.
        </p>
      </Card>

      {query.isLoading && <LoadingState label="Simulation en cours…" />}
      {query.error && <ErrorState message={(query.error as Error).message} />}

      {d && !d.book && (
        <Card>
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Aucun carnet publié pour ce titre : impossible de savoir combien de vendeurs sont devant vous. Lancez
            l'extraction des bulletins depuis l'onglet Carnet &amp; liquidité, ou choisissez un titre coté plus
            régulièrement.
          </p>
        </Card>
      )}

      {d && d.book && (
        <>
          {impact && impactStyle && (
            <div className={`rounded-md border px-3 py-2.5 text-sm ${impactStyle.cls}`}>
              <strong>{impactStyle.label} — {impact.days_of_volume} jour{impact.days_of_volume > 1 ? 's' : ''} de volume habituel.</strong>{' '}
              {d.group_quantity > 0 ? (
                <>
                  Vous êtes plusieurs à vouloir vendre {fmtQty(impact.total_to_sell)} titres au total, alors que ce
                  titre n'en échange que {fmtQty(impact.median_daily_volume)} par séance en moyenne — soit{' '}
                  {impact.percent_of_daily_volume}% du volume quotidien. Votre part personnelle représente{' '}
                  {impact.my_share_percent}% du total du groupe.
                </>
              ) : (
                <>
                  Vos {fmtQty(impact.total_to_sell)} titres représentent {impact.percent_of_daily_volume}% du volume
                  échangé lors d'une séance ordinaire ({fmtQty(impact.median_daily_volume)} titres).
                </>
              )}{' '}
              {impact.level === 'critique' || impact.level === 'eleve' ? (
                <>
                  À ce niveau, la vraie question n'est plus « qui passe en premier » : en arrivant tous ensemble, vous
                  faites baisser le cours pour tout le monde, et le premier servi le sera lui aussi à un prix déjà
                  entamé. L'étalement ci-dessous compte davantage que la course à la première place.
                </>
              ) : (
                <>Le marché absorbe ce volume sans difficulté particulière : le choix de la stratégie relève surtout du confort.</>
              )}
            </div>
          )}

          <div className="rounded-md border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-950">
            <div className="text-sm font-semibold text-gray-900 dark:text-gray-100">
              Votre position dans la file de vente
            </div>
            {d.queue_position.status === 'premier' ? (
              <p className="mt-1 text-sm text-emerald-700 dark:text-emerald-400">
                <strong>Aucun vendeur n'attend devant vous</strong> au dernier carnet publié ({d.book.snapshot_date}).
                Si la situation n'a pas changé, votre ordre serait le premier de la file : inutile de décoter pour
                doubler qui que ce soit.
                {d.queue_position.buyers_waiting !== null && d.queue_position.buyers_waiting > 0 && (
                  <> {fmtQty(d.queue_position.buyers_waiting)} titres sont demandés à l'achat — c'est ce que vous
                    pourriez écouler tout de suite.</>
                )}
              </p>
            ) : d.queue_position.status === 'derriere' ? (
              <p className="mt-1 text-sm text-gray-700 dark:text-gray-300">
                <strong>{fmtQty(d.queue_position.ahead_of_me)} titres sont déjà proposés à la vente devant vous</strong>{' '}
                au meilleur prix, d'après le carnet du {d.book.snapshot_date}. Au rythme habituel du titre, il faut{' '}
                {sessionsLabel(d.queue_position.sessions_to_reach_front)} avant que cette file soit écoulée et que
                votre tour arrive — sauf si vous décotez pour passer devant (voir ci-dessous).
              </p>
            ) : (
              <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Position inconnue : aucun carnet publié pour ce titre.
              </p>
            )}
          </div>

          <Card title="Les trois stratégies, chiffrées">
            <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
              {s?.immediate && (
                <div className="rounded-md border border-gray-200 p-3 dark:border-gray-800">
                  <div className="text-sm font-semibold text-gray-900 dark:text-gray-100">Vendre tout de suite</div>
                  <div className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    Vous acceptez le prix des acheteurs déjà présents : {fmtF(s.immediate.price)}
                  </div>
                  <ul className="mt-2 flex flex-col gap-1 text-sm text-gray-700 dark:text-gray-300">
                    <li>Servi immédiatement : <strong>{fmtQty(s.immediate.served_immediately)} titres</strong></li>
                    <li>Reste à vendre ensuite : {fmtQty(s.immediate.remaining_after)} titres</li>
                    <li>
                      Ce que ça vous coûte : <strong>{fmtF(s.immediate.cost_per_share)} par titre</strong>
                      {s.immediate.cost_percent !== null && ` (${s.immediate.cost_percent}% du cours)`}
                    </li>
                  </ul>
                  <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Vous passez devant tout le monde, mais seulement pour la quantité que les acheteurs présents
                    réclament — souvent bien moins que ce que vous vouliez vendre.
                  </p>
                </div>
              )}

              {s?.undercut && (
                <div className="rounded-md border border-gray-200 p-3 dark:border-gray-800">
                  <div className="text-sm font-semibold text-gray-900 dark:text-gray-100">Décoter d'un pas</div>
                  <div className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    Vous vous placez juste sous les autres vendeurs : {fmtF(s.undercut.price)}
                  </div>
                  <ul className="mt-2 flex flex-col gap-1 text-sm text-gray-700 dark:text-gray-300">
                    <li>Vous passez devant : <strong>{fmtQty(s.undercut.jumped_ahead_of)} titres</strong></li>
                    <li>
                      Ce que ça vous coûte : <strong>{fmtF(s.undercut.cost_per_share)} par titre</strong> soit{' '}
                      {fmtF(s.undercut.total_cost)} au total ({s.undercut.cost_percent}% du cours)
                    </li>
                    <li>Délai estimé : {sessionsLabel(s.undercut.sessions_to_complete)}</li>
                  </ul>
                  <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Attention : vous passez devant ceux qui vendent plus cher, mais pas devant un éventuel vendeur
                    déjà placé à votre nouveau prix — le carnet publié ne le montre pas.
                  </p>
                </div>
              )}

              {s?.queue && (
                <div className="rounded-md border border-gray-200 p-3 dark:border-gray-800">
                  <div className="text-sm font-semibold text-gray-900 dark:text-gray-100">Faire la queue</div>
                  <div className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    Vous vous alignez sur le meilleur prix vendeur : {fmtF(s.queue.price)}
                  </div>
                  <ul className="mt-2 flex flex-col gap-1 text-sm text-gray-700 dark:text-gray-300">
                    <li>Devant vous : <strong>{fmtQty(s.queue.queue_ahead)} titres</strong></li>
                    <li>Avant d'atteindre le début de la file : {sessionsLabel(s.queue.sessions_to_reach_front)}</li>
                    <li>Pour tout écouler : {sessionsLabel(s.queue.sessions_to_complete)}</li>
                    <li>Ce que ça vous coûte : <strong>rien</strong> (vous gardez votre prix)</li>
                  </ul>
                  <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    La stratégie patiente : vous ne bradez pas, mais vous êtes servi après ceux déjà positionnés.
                  </p>
                </div>
              )}
            </div>

            {costChart.length > 0 && (
              <div className="mt-4">
                <ResponsiveContainer width="100%" height={160}>
                  <BarChart data={costChart} layout="vertical" margin={{ top: 5, right: 70, bottom: 5, left: 10 }}>
                    <XAxis type="number" tick={{ fontSize: 10 }} tickFormatter={(v) => nf.format(v)} />
                    <YAxis type="category" dataKey="key" width={140} tick={{ fontSize: 11 }} />
                    <ReferenceLine x={0} stroke="var(--chart-muted)" />
                    <Tooltip
                      content={({ active, payload }) => {
                        if (!active || !payload?.length) return null
                        const c = payload[0].payload as (typeof costChart)[number]
                        return (
                          <div className="rounded-md border border-gray-200 bg-white p-2 text-xs shadow dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                            <div className="font-medium">{c.key}</div>
                            <div>Coût pour {fmtQty(qty)} titres : {fmtF(c.cost)}</div>
                            <div className="text-gray-500 dark:text-gray-400">{c.detail}</div>
                          </div>
                        )
                      }}
                    />
                    <Bar dataKey="cost" name="Coût total">
                      {costChart.map((c) => (
                        <Cell key={c.key} fill={c.color} />
                      ))}
                      <LabelList dataKey="cost" position="right" formatter={(v: number) => fmtF(v)} style={{ fontSize: 10, fill: 'currentColor' }} />
                    </Bar>
                  </BarChart>
                </ResponsiveContainer>
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                  Ce que chaque stratégie vous coûte, en francs, pour vos {fmtQty(qty)} titres — comparé à la
                  patience (coût nul). Le coût de « vendre tout de suite » ne porte que sur les titres réellement
                  servis immédiatement.
                </p>
              </div>
            )}
          </Card>

          {d.stagger && (
            <Card title="Recommandation : étaler plutôt que courir">
              <p className="text-sm text-gray-700 dark:text-gray-300">
                Pour ne pas peser vous-même sur le cours, vendez par tranches d'environ{' '}
                <strong>{fmtQty(d.stagger.tranche_per_session)} titres par séance</strong> (soit{' '}
                {d.stagger.basis_percent_of_daily_volume}% du volume habituel du titre). Pour vos{' '}
                {fmtQty(d.quantity)} titres, cela représente <strong>{d.stagger.sessions_needed} séance
                {d.stagger.sessions_needed > 1 ? 's' : ''}</strong>.
              </p>
              {d.group_quantity > 0 && (
                <p className="mt-1.5 text-sm text-gray-700 dark:text-gray-300">
                  Si le groupe entier ({fmtQty(d.group_quantity)} titres) applique la même discipline, il faut{' '}
                  <strong>{d.stagger.group_sessions_needed} séances</strong> au total. Se répartir les jours plutôt
                  que de vendre tous le même matin évite de faire chuter le cours les uns pour les autres —
                  c'est le seul « accord » entre vous qui change vraiment quelque chose, puisque l'ordre d'arrivée
                  au même prix n'est ni visible ni négociable.
                </p>
              )}
            </Card>
          )}

          <Card title="Le carnet observé et les règles du marché">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <div>
                <div className="text-xs text-gray-500 dark:text-gray-400">Meilleur acheteur</div>
                <div className="text-sm font-semibold">{fmtF(d.book.best_bid_price)}</div>
                <div className="text-xs text-gray-500 dark:text-gray-400">{fmtQty(d.book.bid_residual_qty)} titres demandés</div>
              </div>
              <div>
                <div className="text-xs text-gray-500 dark:text-gray-400">Meilleur vendeur</div>
                <div className="text-sm font-semibold">{fmtF(d.book.best_ask_price)}</div>
                <div className="text-xs text-gray-500 dark:text-gray-400">{fmtQty(d.book.ask_residual_qty)} titres offerts</div>
              </div>
              <div>
                <div className="text-xs text-gray-500 dark:text-gray-400">Pas de cotation</div>
                <div className="text-sm font-semibold">{fmtF(d.tick_size)}</div>
                <div className="text-xs text-gray-500 dark:text-gray-400">plus petit écart de prix possible</div>
              </div>
              <div>
                <div className="text-xs text-gray-500 dark:text-gray-400">Débit habituel</div>
                <div className="text-sm font-semibold">{fmtQty(d.median_daily_volume)} / séance</div>
                <div className="text-xs text-gray-500 dark:text-gray-400">médiane sur {d.active_days_basis} séances actives</div>
              </div>
            </div>

            <div className="mt-3 rounded-md border border-gray-200 p-3 text-sm dark:border-gray-800">
              <div className="font-medium text-gray-800 dark:text-gray-200">La limite de ±{d.daily_limit.percent}% par séance</div>
              <p className="mt-1 text-gray-700 dark:text-gray-300">
                Le cours ne peut pas varier de plus de {d.daily_limit.percent}% dans une même séance. Pour ce titre,
                le prix ne peut pas descendre sous <strong>{fmtF(d.daily_limit.floor_price)}</strong> aujourd'hui :
                vous ne pouvez donc pas décoter de plus de {fmtF(d.daily_limit.max_undercut_per_share)} par titre.
                Une fois cette limite atteinte, la cotation est bloquée et <strong>plus personne ne vend</strong>, ni
                vous ni les autres — c'est la limite absolue de la stratégie « je casse le prix pour passer devant ».
              </p>
            </div>

            {(d.book.ask_at_market === 1 || d.book.bid_at_market === 1) && (
              <p className="mt-2 text-sm text-amber-600 dark:text-amber-400">
                Ce carnet contient des ordres « au marché » (sans prix limite) : ils sont exécutés avant tous les
                ordres à cours limité, y compris une décote. Impossible de passer devant eux autrement qu'en
                utilisant vous-même un ordre au marché.
              </p>
            )}

            <div className="mt-3 text-xs text-gray-500 dark:text-gray-400">
              <div className="font-medium">Ce que cette simulation ne peut pas savoir :</div>
              <ul className="mt-0.5 list-disc pl-4">
                {d.limits.map((l, i) => (
                  <li key={i}>{l}</li>
                ))}
              </ul>
            </div>
          </Card>
        </>
      )}
    </div>
  )
}
