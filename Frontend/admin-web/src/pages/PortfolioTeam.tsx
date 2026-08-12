import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { callApi } from '../lib/apiClient'
import type {
  Company,
  PortfolioAiProposal,
  PortfolioAiReview,
  PortfolioAiReviewDetail,
  PortfolioAiReviewSummary,
  PortfolioTeamProposalSummary,
  PortfolioHolding,
  PortfolioSuggestionCandidate,
  PortfolioSuggestionsResult,
  PortfolioTeamProposal,
  PortfolioTeamResult,
} from '../lib/types'
import { Button, Card, ErrorState, InfoPanel, Input, LoadingState, SearchableSelect, Select, StarRating, StatTile } from '../components/ui'

type Profile = 'prudent' | 'equilibre' | 'dynamique'
type Role = 'defense' | 'milieu' | 'attaque'

const ROLE_LABELS: Record<string, string> = {
  gardien: 'Gardien',
  defense: 'Défense',
  milieu: 'Milieu',
  attaque: 'Attaque',
}

const PROFILE_DESCRIPTIONS: { id: Profile; label: string; description: string }[] = [
  { id: 'prudent', label: 'Prudent', description: 'Privilégie la stabilité et le rendement (50% Défense) — moins d\'exposition à la croissance.' },
  { id: 'equilibre', label: 'Équilibré', description: 'Répartition proche du 4-3-3 classique (35/35/30) — un peu de tout, sans excès.' },
  { id: 'dynamique', label: 'Dynamique', description: 'Cherche la croissance (50% Attaque) — accepte plus de mouvement en échange de plus de potentiel.' },
]

function fmtFcfa(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—'
  return `${n.toLocaleString('fr-FR', { maximumFractionDigits: 0 })} FCFA`
}

function scoreBadgeClass(score: number | null) {
  if (score === null) return 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'
  if (score >= 70) return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
  if (score >= 50) return 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
  return 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
}

/** Sélecteur d'entreprise + ajout — partagé entre l'état vide (hors suggestions) et l'équipe existante. */
function AddCompanyPicker({ excludeIds, onAdd, isPending }: { excludeIds: number[]; onAdd: (companyId: number, amount: number | null) => void; isPending: boolean }) {
  const [companyId, setCompanyId] = useState('')
  const [amount, setAmount] = useState('')

  const companiesQuery = useQuery({
    queryKey: ['companies-list'],
    queryFn: () => callApi<Company[]>('api_companies.php', 'list', { per_page: 200, active: 1 }),
  })
  const companies = useMemo(
    () => [...(companiesQuery.data ?? [])].filter((c) => !excludeIds.includes(c.company_id)).sort((a, b) => a.symbol.localeCompare(b.symbol)),
    [companiesQuery.data, excludeIds],
  )

  return (
    <div className="flex flex-wrap items-end gap-3">
      <label className="w-64">
        <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Entreprise</span>
        <SearchableSelect
          value={companyId}
          onChange={setCompanyId}
          options={companies.map((c) => ({ value: String(c.company_id), label: `${c.symbol} — ${c.name}` }))}
        />
      </label>
      <label className="w-44">
        <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Montant envisagé (FCFA)</span>
        <Input type="number" min={0} value={amount} onChange={(e) => setAmount(e.target.value)} />
      </label>
      <Button
        variant="secondary"
        disabled={!companyId || isPending}
        onClick={() => {
          onAdd(Number(companyId), amount !== '' ? Number(amount) : null)
          setCompanyId('')
          setAmount('')
        }}
      >
        Ajouter à l'équipe
      </Button>
    </div>
  )
}

/** Carte candidat dans les suggestions par rôle. */
function CandidateCard({ candidate, onAdd, isPending, alreadyInTeam }: { candidate: PortfolioSuggestionCandidate; onAdd: () => void; isPending: boolean; alreadyInTeam: boolean }) {
  return (
    <div className="flex items-center justify-between gap-2 rounded-md border border-gray-100 px-3 py-2 dark:border-gray-800">
      <div className="min-w-0">
        <div className="flex items-center gap-2">
          <span className="font-medium">{candidate.symbol}</span>
          <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${scoreBadgeClass(candidate.composite_score)}`}>
            {candidate.composite_score !== null ? candidate.composite_score.toFixed(0) : '—'}
          </span>
          <span className="text-xs text-gray-400 dark:text-gray-500">{candidate.coverage_percent}%</span>
        </div>
        <div className="truncate text-xs text-gray-500 dark:text-gray-400">{candidate.sector ?? '—'}{candidate.suggested_amount_fcfa !== null ? ` · ${fmtFcfa(candidate.suggested_amount_fcfa)}` : ''}</div>
      </div>
      <Button variant="secondary" disabled={isPending || alreadyInTeam} onClick={onAdd}>
        {alreadyInTeam ? 'Déjà pris' : 'Ajouter'}
      </Button>
    </div>
  )
}

const SUB_SCORE_LABELS: Record<string, string> = {
  fundamental: 'Fondamental',
  technical: 'Technique',
  momentum: 'Momentum',
  liquidity: 'Liquidité',
  sector: 'Rang sectoriel',
  market: 'Vs marché',
}

/**
 * Proposition d'équipe complète (4-3-3) — l'utilisateur reste le coach :
 * cases à cocher, détail « Pourquoi ? » vérifiable par joueur, et
 * remplacements depuis le banc (le remplaçant hérite du montant du joueur
 * sortant, garde-fou de diversification sectorielle appliqué au moment du
 * choix). La proposition n'est jamais imposée.
 */
function ProposalView({
  proposal,
  onAdopt,
  adopting,
}: {
  proposal: PortfolioTeamProposal
  onAdopt: (players: { company_id: number; target_amount_fcfa: number | null }[], cashReserve: number | null) => void
  adopting: boolean
}) {
  const [team, setTeam] = useState(proposal.team)
  const [bench, setBench] = useState(proposal.bench)
  const [unchecked, setUnchecked] = useState<number[]>([])
  const [whyOpenId, setWhyOpenId] = useState<number | null>(null)
  const [replacingId, setReplacingId] = useState<number | null>(null)

  const allPlayers = [...team.defense, ...team.milieu, ...team.attaque]
  const checkedPlayers = allPlayers.filter((p) => !unchecked.includes(p.company_id))

  // Comptage sectoriel du XI courant — pour bloquer un remplacement qui
  // dépasserait 2 titres du même secteur (même règle que le backend).
  const sectorCounts: Record<string, number> = {}
  for (const p of allPlayers) {
    const key = p.sector ?? '(sans secteur)'
    sectorCounts[key] = (sectorCounts[key] ?? 0) + 1
  }

  function swapPlayer(role: Role, outgoingId: number, incomingId: number) {
    const outgoing = team[role].find((p) => p.company_id === outgoingId)
    const incoming = bench[role].find((p) => p.company_id === incomingId)
    if (!outgoing || !incoming) return

    setTeam((prev) => ({
      ...prev,
      [role]: prev[role].map((p) =>
        p.company_id === outgoingId ? { ...incoming, suggested_amount_fcfa: outgoing.suggested_amount_fcfa } : p,
      ),
    }))
    // Le sortant rejoint le banc (en tête) — permet de revenir en arrière
    // par un remplacement inverse.
    setBench((prev) => ({
      ...prev,
      [role]: [{ ...outgoing, suggested_amount_fcfa: null }, ...prev[role].filter((p) => p.company_id !== incomingId)],
    }))
    setUnchecked((prev) => prev.filter((id) => id !== outgoingId && id !== incomingId))
    setReplacingId(null)
    setWhyOpenId(null)
  }

  return (
    <Card title={`Équipe proposée ${proposal.origin === 'ia' ? `par l'IA (${proposal.provider ?? '?'})` : "par l'analyse"} — 4-3-3, profil ${proposal.profile}`}>
      {proposal.commentary && (
        <div className="mb-3 rounded-md border border-gray-300 bg-gray-100 px-3 py-2 dark:border-gray-700 dark:bg-gray-800">
          <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-200">Lecture d'ensemble de l'IA</p>
          <p className="text-sm text-gray-900 dark:text-gray-100">{proposal.commentary}</p>
        </div>
      )}
      {proposal.reserve_fcfa !== null && (
        <p className="mb-3 text-sm text-gray-600 dark:text-gray-300">
          🧤 <strong>Gardien conseillé : {fmtFcfa(proposal.reserve_fcfa)}</strong> (10% du budget, gardés hors du
          terrain) — {fmtFcfa(proposal.invest_fcfa)} investis sur les lignes selon le profil.
        </p>
      )}
      {proposal.notes.map((n, i) => (
        <p key={i} className="mb-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">
          {n}
        </p>
      ))}
      <div className="grid gap-4 lg:grid-cols-3">
        {(['defense', 'milieu', 'attaque'] as Role[]).map((role) => (
          <div key={role}>
            <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
              {ROLE_LABELS[role]} ({team[role].length}/{proposal.formation[role]}) · {proposal.target_weights[role]}% du budget investi
            </h4>
            <div className="flex flex-col gap-2">
              {team[role].map((p) => (
                <div key={p.company_id} className="rounded-md border border-gray-100 px-3 py-2 dark:border-gray-800">
                  <div className="flex items-start gap-2">
                    <input
                      type="checkbox"
                      className="mt-1"
                      checked={!unchecked.includes(p.company_id)}
                      onChange={() =>
                        setUnchecked((prev) =>
                          prev.includes(p.company_id) ? prev.filter((id) => id !== p.company_id) : [...prev, p.company_id],
                        )
                      }
                    />
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{p.symbol}</span>
                        <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${scoreBadgeClass(p.composite_score)}`}>
                          {p.composite_score !== null ? p.composite_score.toFixed(0) : '—'}
                        </span>
                        {p.suggested_amount_fcfa !== null && (
                          <span className="text-xs tabular-nums text-gray-500 dark:text-gray-400">{fmtFcfa(p.suggested_amount_fcfa)}</span>
                        )}
                      </div>
                      <span className="block text-xs text-gray-500 dark:text-gray-400">{p.sector ?? '—'}</span>
                      <div className="mt-1 flex gap-3">
                        <button
                          type="button"
                          onClick={() => { setWhyOpenId(whyOpenId === p.company_id ? null : p.company_id); setReplacingId(null) }}
                          className="text-xs text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                        >
                          {whyOpenId === p.company_id ? 'Fermer' : 'Pourquoi ?'}
                        </button>
                        <button
                          type="button"
                          onClick={() => { setReplacingId(replacingId === p.company_id ? null : p.company_id); setWhyOpenId(null) }}
                          className="text-xs text-gray-700 underline-offset-2 underline hover:text-black dark:text-gray-200 dark:hover:text-white"
                        >
                          {replacingId === p.company_id ? 'Annuler' : 'Remplacer'}
                        </button>
                      </div>
                    </div>
                  </div>

                  {whyOpenId === p.company_id && (
                    <div className="mt-2 rounded-md bg-gray-50 px-3 py-2 text-xs dark:bg-gray-900/40">
                      <p className="mb-2 text-gray-700 dark:text-gray-300">{p.role_rule}</p>
                      <div className="mb-2 grid grid-cols-2 gap-x-4 gap-y-0.5">
                        {(Object.keys(SUB_SCORE_LABELS) as (keyof typeof p.sub_scores)[]).map((k) => (
                          <div key={k} className="flex justify-between">
                            <span className="text-gray-500 dark:text-gray-400">{SUB_SCORE_LABELS[k]}</span>
                            <span className="tabular-nums text-gray-700 dark:text-gray-300">
                              {p.sub_scores[k] !== null ? Math.round(p.sub_scores[k]!) : '—'}
                            </span>
                          </div>
                        ))}
                      </div>
                      <p className="text-gray-500 dark:text-gray-400">
                        Couverture des données : {p.coverage_percent}% des pondérations calculables. {p.reason}
                      </p>
                      {p.suggested_amount_fcfa !== null && (
                        <p className="mt-1 text-gray-500 dark:text-gray-400">
                          Montant proposé : {proposal.target_weights[role]}% du budget investi, réparti à parts égales
                          entre les {team[role].length} joueur(s) de la ligne.
                        </p>
                      )}
                    </div>
                  )}

                  {replacingId === p.company_id && (() => {
                    // Le sortant libère une place dans son secteur — un remplaçant du même
                    // secteur que lui ne peut donc jamais aggraver la concentration.
                    const outgoingSector = p.sector ?? '(sans secteur)'
                    const benchWithFlags = bench[role].map((b) => {
                      const benchSector = b.sector ?? '(sans secteur)'
                      const effectiveCount = (sectorCounts[benchSector] ?? 0) - (benchSector === outgoingSector ? 1 : 0)
                      return { ...b, blocked: effectiveCount >= 2 }
                    })
                    return (
                      <div className="mt-2 rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-900/40">
                        <p className="mb-2 text-xs font-semibold text-gray-600 dark:text-gray-300">Remplaçants disponibles ({ROLE_LABELS[role]}) :</p>
                        {benchWithFlags.length === 0 && (
                          <p className="text-xs text-gray-500 dark:text-gray-400">
                            Aucun remplaçant net pour ce rôle — les candidats classés {ROLE_LABELS[role]} sont déjà tous
                            dans le XI.
                          </p>
                        )}
                        <div className="flex flex-col gap-1.5">
                          {benchWithFlags.map((b) => (
                            <div key={b.company_id} className="flex items-center justify-between gap-2">
                              <div className="min-w-0 text-xs">
                                <span className="font-medium">{b.symbol}</span>
                                <span className={`ml-2 rounded-full px-1.5 py-0.5 font-semibold ${scoreBadgeClass(b.composite_score)}`}>
                                  {b.composite_score !== null ? b.composite_score.toFixed(0) : '—'}
                                </span>
                                <span className="ml-2 text-gray-500 dark:text-gray-400">{b.sector ?? '—'}</span>
                              </div>
                              <button
                                type="button"
                                disabled={b.blocked}
                                title={b.blocked ? `L'équipe compte déjà 2 titres du secteur ${b.sector ?? '(sans secteur)'}` : b.role_rule}
                                onClick={() => swapPlayer(role, p.company_id, b.company_id)}
                                className={`text-xs underline ${
                                  b.blocked
                                    ? 'cursor-not-allowed text-gray-300 dark:text-gray-600'
                                    : 'text-gray-700 underline-offset-2 hover:text-black dark:text-gray-200 dark:hover:text-white'
                                }`}
                              >
                                {b.blocked ? 'Secteur saturé' : 'Faire entrer'}
                              </button>
                            </div>
                          ))}
                        </div>
                        {benchWithFlags.some((b) => b.blocked) && (
                          <p className="mt-2 border-t border-gray-200 pt-2 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            🔒 <strong>« Secteur saturé »</strong> : l'équipe proposée ne peut pas compter plus de{' '}
                            <strong>2 titres du même secteur</strong> — règle de diversification (ne pas mettre tous
                            ses œufs dans le même panier : si un secteur entier traverse une crise, elle n'emporte pas
                            la moitié de l'équipe). Un remplaçant est bloqué s'il ferait passer son secteur à 3 titres
                            dans le XI. Remplacer un joueur par un autre du <em>même</em> secteur que lui reste
                            toujours possible (le sortant libère la place).
                          </p>
                        )}
                      </div>
                    )
                  })()}
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
      <div className="mt-4 flex items-center gap-3">
        <Button
          disabled={adopting || checkedPlayers.length === 0}
          onClick={() =>
            onAdopt(
              checkedPlayers.map((p) => ({ company_id: p.company_id, target_amount_fcfa: p.suggested_amount_fcfa })),
              proposal.reserve_fcfa,
            )
          }
        >
          {adopting ? 'Adoption…' : `Adopter cette équipe (${checkedPlayers.length} titre(s)${proposal.reserve_fcfa !== null ? ' + gardien' : ''})`}
        </Button>
        <span className="text-xs text-gray-500 dark:text-gray-400">
          Tout est ajouté en mode simulé — aucun achat réel, tu ajustes ensuite librement.
        </span>
      </div>
    </Card>
  )
}

/**
 * Historique des propositions d'équipe (algorithme + IA) — consultation,
 * notation par étoiles et suppression. La consultation ré-ouvre la
 * proposition telle qu'elle a été historisée (banc compris) : elle reste
 * adoptable, adopt_team ignorant proprement les titres déjà détenus.
 */
function ProposalHistory({ onConsult }: { onConsult: (p: PortfolioTeamProposal) => void }) {
  const queryClient = useQueryClient()
  const listQuery = useQuery({
    queryKey: ['portfolio-team-proposals'],
    queryFn: () => callApi<{ proposals: PortfolioTeamProposalSummary[]; count: number }>('api_portfolio.php', 'list_team_proposals', {}),
  })
  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['portfolio-team-proposals'] })

  const consultMutation = useMutation({
    mutationFn: (id: number) => callApi<PortfolioTeamProposal>('api_portfolio.php', 'get_team_proposal', { id }),
    onSuccess: (data) => onConsult(data),
  })
  const rateMutation = useMutation({
    mutationFn: (params: { id: number; rating: number }) => callApi('api_portfolio.php', 'rate_team_proposal', params),
    onSuccess: invalidate,
  })
  const deleteMutation = useMutation({
    mutationFn: (id: number) => callApi('api_portfolio.php', 'delete_team_proposal', { id }),
    onSuccess: invalidate,
  })

  const proposals = listQuery.data?.proposals ?? []
  if (proposals.length === 0) return null

  return (
    <Card title={`Historique des propositions d'équipe (${proposals.length})`}>
      <div className="flex flex-col gap-2">
        {proposals.map((p) => (
          <div key={p.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-gray-100 px-3 py-2 dark:border-gray-800">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2 text-sm">
                <span
                  className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
                    p.origin === 'ia'
                      ? 'bg-gray-200 text-gray-800 dark:bg-gray-800 dark:text-gray-200'
                      : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'
                  }`}
                >
                  {p.origin === 'ia' ? `🤖 IA (${p.provider ?? '?'})` : '⚡ Algorithme'}
                </span>
                <span className="font-medium">{p.created_at}</span>
                <span className="text-xs text-gray-500 dark:text-gray-400">
                  profil {p.profile} · {p.players_count} joueur(s){p.budget_fcfa !== null ? ` · budget ${fmtFcfa(p.budget_fcfa)}` : ''}
                </span>
              </div>
              {p.commentary_excerpt && (
                <p className="mt-0.5 truncate text-xs italic text-gray-400 dark:text-gray-500">{p.commentary_excerpt}</p>
              )}
            </div>
            <div className="flex items-center gap-3">
              <StarRating value={p.rating} onChange={(rating) => rateMutation.mutate({ id: p.id, rating })} />
              <button
                type="button"
                onClick={() => consultMutation.mutate(p.id)}
                className="text-xs text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
              >
                Consulter
              </button>
              <button
                type="button"
                onClick={() => {
                  if (window.confirm('Supprimer définitivement cette proposition historisée ?')) {
                    deleteMutation.mutate(p.id)
                  }
                }}
                className="text-xs text-red-500 underline hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
              >
                Supprimer
              </button>
            </div>
          </div>
        ))}
      </div>
      {consultMutation.isError && <p className="mt-2 text-xs text-red-600 dark:text-red-400">{(consultMutation.error as Error).message}</p>}
    </Card>
  )
}

const AI_PROPOSAL_ACTION_LABELS: Record<PortfolioAiProposal['action'], string> = {
  ajouter: 'Ajouter',
  retirer: 'Retirer',
  ajuster_montant: 'Ajuster le montant',
  ajuster_reserve: 'Ajuster la réserve (gardien)',
}

/**
 * Avis du coach IA + propositions à valider une par une — l'IA propose,
 * l'utilisateur décide : rien n'est appliqué sans cocher puis confirmer,
 * et le backend a déjà écarté toute proposition référençant un titre
 * inconnu ou une position inexistante.
 */
function AiReviewPanel({ onApplied }: { onApplied: () => void }) {
  const queryClient = useQueryClient()
  const [provider, setProvider] = useState<'gemini' | 'anthropic' | 'grok'>('gemini')
  const [review, setReview] = useState<PortfolioAiReview | null>(null)
  const [uncheckedIdx, setUncheckedIdx] = useState<number[]>([])
  const [openHistoryId, setOpenHistoryId] = useState<number | null>(null)

  const historyQuery = useQuery({
    queryKey: ['portfolio-ai-reviews'],
    queryFn: () => callApi<{ reviews: PortfolioAiReviewSummary[]; count: number }>('api_portfolio.php', 'list_reviews', {}),
  })

  const historyDetailQuery = useQuery({
    queryKey: ['portfolio-ai-review-detail', openHistoryId],
    queryFn: () => callApi<PortfolioAiReviewDetail>('api_portfolio.php', 'get_review', { id: openHistoryId }),
    enabled: openHistoryId !== null,
  })

  const deleteReviewMutation = useMutation({
    mutationFn: (id: number) => callApi('api_portfolio.php', 'delete_review', { id }),
    onSuccess: () => {
      setOpenHistoryId(null)
      queryClient.invalidateQueries({ queryKey: ['portfolio-ai-reviews'] })
    },
  })

  const reviewMutation = useMutation({
    mutationFn: () => callApi<PortfolioAiReview>('api_portfolio.php', 'ai_review', { provider }),
    onSuccess: (d) => {
      setReview(d)
      setUncheckedIdx([])
      queryClient.invalidateQueries({ queryKey: ['portfolio-ai-reviews'] })
    },
  })

  const applyMutation = useMutation({
    mutationFn: async (proposals: PortfolioAiProposal[]) => {
      // Application séquentielle via les actions CRUD existantes (chacune
      // déjà validée/scopée côté serveur) — pas d'endpoint "apply" dédié.
      for (const p of proposals) {
        if (p.action === 'ajouter' && p.company_id !== null) {
          await callApi('api_portfolio.php', 'add_holding', {
            company_id: p.company_id,
            status: 'simule',
            target_amount_fcfa: p.amount_fcfa ?? undefined,
          })
        } else if (p.action === 'retirer' && p.holding_id !== null) {
          await callApi('api_portfolio.php', 'remove_holding', { id: p.holding_id })
        } else if (p.action === 'ajuster_montant' && p.holding_id !== null) {
          await callApi('api_portfolio.php', 'update_holding', { id: p.holding_id, target_amount_fcfa: p.amount_fcfa })
        } else if (p.action === 'ajuster_reserve' && p.amount_fcfa !== null) {
          await callApi('api_portfolio.php', 'set_cash_reserve', { amount: p.amount_fcfa })
        }
      }
    },
    onSuccess: () => {
      setReview(null)
      onApplied()
    },
  })

  const checkedProposals = (review?.proposals ?? []).filter((_, i) => !uncheckedIdx.includes(i))

  return (
    <Card title="Avis du coach IA">
      <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
        L'IA analyse ta composition (lignes, diversification, réserve, scores, thèses) et peut proposer des
        changements concrets — chacun reste à valider individuellement, rien n'est appliqué sans ton accord.
      </p>
      <div className="flex flex-wrap items-end gap-3">
        <label className="w-40">
          <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Fournisseur IA</span>
          <Select value={provider} onChange={(e) => setProvider(e.target.value as 'gemini' | 'anthropic' | 'grok')}>
            <option value="gemini">Gemini</option>
            <option value="anthropic">Claude</option>
            <option value="grok">Grok</option>
          </Select>
        </label>
        <Button disabled={reviewMutation.isPending} onClick={() => reviewMutation.mutate()}>
          {reviewMutation.isPending ? 'Analyse en cours…' : "Demander l'avis du coach IA"}
        </Button>
      </div>
      {reviewMutation.isError && (
        <p className="mt-2 text-xs text-red-600 dark:text-red-400">{(reviewMutation.error as Error).message}</p>
      )}

      {review && (
        <div className="mt-4 flex flex-col gap-4">
          <div>
            <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Avis global</h4>
            <p className="text-sm text-gray-700 dark:text-gray-300">{review.overall_opinion}</p>
          </div>

          {review.strengths.length > 0 && (
            <div>
              <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Forces</h4>
              <ul className="list-disc pl-4 text-sm text-emerald-700 dark:text-emerald-400">
                {review.strengths.map((s, i) => <li key={i}>{s}</li>)}
              </ul>
            </div>
          )}

          {review.weaknesses.length > 0 && (
            <div>
              <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Faiblesses</h4>
              <ul className="list-disc pl-4 text-sm text-amber-700 dark:text-amber-400">
                {review.weaknesses.map((w, i) => <li key={i}>{w}</li>)}
              </ul>
            </div>
          )}

          <div>
            <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
              Propositions ({review.proposals.length})
            </h4>
            {review.proposals.length === 0 && (
              <p className="text-sm text-gray-500 dark:text-gray-400">
                Aucun changement proposé — l'IA juge l'équipe bien construite en l'état.
              </p>
            )}
            <div className="flex flex-col gap-2">
              {review.proposals.map((p, i) => (
                <label key={i} className="flex cursor-pointer items-start gap-2 rounded-md border border-gray-100 px-3 py-2 dark:border-gray-800">
                  <input
                    type="checkbox"
                    className="mt-1"
                    checked={!uncheckedIdx.includes(i)}
                    onChange={() =>
                      setUncheckedIdx((prev) => (prev.includes(i) ? prev.filter((x) => x !== i) : [...prev, i]))
                    }
                  />
                  <span>
                    <span className="flex flex-wrap items-center gap-2 text-sm">
                      <span className="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                        {AI_PROPOSAL_ACTION_LABELS[p.action]}
                      </span>
                      {p.symbol && <span className="font-medium">{p.symbol}</span>}
                      {p.amount_fcfa !== null && (
                        <span className="tabular-nums text-gray-600 dark:text-gray-300">{fmtFcfa(p.amount_fcfa)}</span>
                      )}
                    </span>
                    <span className="block text-xs text-gray-500 dark:text-gray-400">{p.rationale}</span>
                  </span>
                </label>
              ))}
            </div>
            {review.dropped_proposals_count > 0 && (
              <p className="mt-2 text-xs text-gray-400 dark:text-gray-500">
                {review.dropped_proposals_count} proposition(s) de l'IA écartée(s) automatiquement (titre hors menu ou
                position inexistante).
              </p>
            )}
          </div>

          {review.proposals.length > 0 && (
            <div className="flex items-center gap-3">
              <Button
                disabled={applyMutation.isPending || checkedProposals.length === 0}
                onClick={() => applyMutation.mutate(checkedProposals)}
              >
                {applyMutation.isPending ? 'Application…' : `Appliquer la sélection (${checkedProposals.length})`}
              </Button>
              {applyMutation.isError && (
                <span className="text-xs text-red-600 dark:text-red-400">{(applyMutation.error as Error).message}</span>
              )}
            </div>
          )}

          <p className="text-xs italic text-gray-400 dark:text-gray-500">
            {review.disclaimer} ({review.provider}/{review.model})
          </p>
        </div>
      )}

      {(historyQuery.data?.reviews.length ?? 0) > 0 && (
        <div className="mt-5 border-t border-gray-100 pt-4 dark:border-gray-800">
          <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
            Historique des avis ({historyQuery.data!.count})
          </h4>
          <div className="flex flex-col gap-2">
            {historyQuery.data!.reviews.map((r) => (
              <div key={r.id} className="rounded-md border border-gray-100 dark:border-gray-800">
                <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                  <div className="min-w-0 text-sm">
                    <span className="font-medium">{r.created_at}</span>
                    <span className="ml-2 text-xs text-gray-500 dark:text-gray-400">
                      {r.provider} · {r.proposals_count} proposition(s)
                      {r.holdings_count_at_review !== null && ` · ${r.holdings_count_at_review} titre(s) à l'époque`}
                      {r.balance_score_at_review !== null && ` · équilibre ${r.balance_score_at_review}/100`}
                    </span>
                    {openHistoryId !== r.id && (
                      <span className="block truncate text-xs text-gray-400 dark:text-gray-500">{r.opinion_excerpt}</span>
                    )}
                  </div>
                  <div className="flex items-center gap-3">
                    <button
                      type="button"
                      onClick={() => setOpenHistoryId(openHistoryId === r.id ? null : r.id)}
                      className="text-xs text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    >
                      {openHistoryId === r.id ? 'Fermer' : 'Consulter'}
                    </button>
                    <button
                      type="button"
                      onClick={() => {
                        if (window.confirm('Supprimer définitivement cet avis historisé ?')) {
                          deleteReviewMutation.mutate(r.id)
                        }
                      }}
                      className="text-xs text-red-500 underline hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                    >
                      Supprimer
                    </button>
                  </div>
                </div>

                {openHistoryId === r.id && (
                  <div className="border-t border-gray-100 px-3 py-3 dark:border-gray-800">
                    {historyDetailQuery.isLoading && <LoadingState label="Chargement de l'avis…" />}
                    {historyDetailQuery.error && <ErrorState message={(historyDetailQuery.error as Error).message} />}
                    {historyDetailQuery.data && historyDetailQuery.data.id === r.id && (
                      <div className="flex flex-col gap-3 text-sm">
                        {historyDetailQuery.data.team_snapshot && (
                          <p className="rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
                            <strong>Équipe au moment de l'avis :</strong>{' '}
                            {historyDetailQuery.data.team_snapshot.holdings.length} titre(s) ·{' '}
                            {fmtFcfa(historyDetailQuery.data.team_snapshot.total_portfolio_value_fcfa)} investis ·
                            réserve {fmtFcfa(historyDetailQuery.data.team_snapshot.cash_reserve.amount)} · équilibre{' '}
                            {historyDetailQuery.data.team_snapshot.balance_score}/100 —{' '}
                            {historyDetailQuery.data.team_snapshot.holdings.map((h) => h.symbol).join(', ')}
                          </p>
                        )}
                        <p className="text-gray-700 dark:text-gray-300">{historyDetailQuery.data.overall_opinion}</p>
                        {historyDetailQuery.data.strengths.length > 0 && (
                          <ul className="list-disc pl-4 text-emerald-700 dark:text-emerald-400">
                            {historyDetailQuery.data.strengths.map((s, i) => <li key={i}>{s}</li>)}
                          </ul>
                        )}
                        {historyDetailQuery.data.weaknesses.length > 0 && (
                          <ul className="list-disc pl-4 text-amber-700 dark:text-amber-400">
                            {historyDetailQuery.data.weaknesses.map((w, i) => <li key={i}>{w}</li>)}
                          </ul>
                        )}
                        {historyDetailQuery.data.proposals.length > 0 && (
                          <div>
                            <p className="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">
                              Propositions de l'époque (lecture seule — elles portaient sur la composition ci-dessus,
                              plus applicables telles quelles) :
                            </p>
                            <ul className="flex flex-col gap-1">
                              {historyDetailQuery.data.proposals.map((p, i) => (
                                <li key={i} className="text-xs text-gray-600 dark:text-gray-300">
                                  <span className="rounded-full bg-gray-100 px-2 py-0.5 font-semibold dark:bg-gray-800">
                                    {AI_PROPOSAL_ACTION_LABELS[p.action]}
                                  </span>{' '}
                                  {p.symbol ?? ''} {p.amount_fcfa !== null ? fmtFcfa(p.amount_fcfa) : ''} — {p.rationale}
                                </li>
                              ))}
                            </ul>
                          </div>
                        )}
                        <p className="text-xs italic text-gray-400 dark:text-gray-500">
                          {historyDetailQuery.data.provider}/{historyDetailQuery.data.model}
                        </p>
                      </div>
                    )}
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
      )}
    </Card>
  )
}

/** Formulaire d'édition inline d'une position (repliable). */
function HoldingEditor({ holding, onSaved }: { holding: PortfolioHolding; onSaved: () => void }) {
  const [status, setStatus] = useState(holding.status)
  const [targetAmount, setTargetAmount] = useState(holding.target_amount_fcfa !== null ? String(holding.target_amount_fcfa) : '')
  const [quantity, setQuantity] = useState(holding.quantity !== null ? String(holding.quantity) : '')
  const [price, setPrice] = useState(holding.average_purchase_price !== null ? String(holding.average_purchase_price) : '')
  const [purchaseDate, setPurchaseDate] = useState(holding.purchase_date ?? '')
  const [roleOverride, setRoleOverride] = useState(holding.role_override ?? '')
  const [buyReason, setBuyReason] = useState(holding.thesis?.buy_reason ?? '')
  const [exitCriteria, setExitCriteria] = useState(holding.thesis?.exit_criteria ?? '')
  const [error, setError] = useState<string | null>(null)

  const updateMutation = useMutation({
    mutationFn: async () => {
      await callApi('api_portfolio.php', 'update_holding', {
        id: holding.id,
        status,
        target_amount_fcfa: targetAmount !== '' ? Number(targetAmount) : null,
        quantity: quantity !== '' ? Number(quantity) : null,
        average_purchase_price: price !== '' ? Number(price) : null,
        purchase_date: purchaseDate || null,
        role_override: roleOverride || null,
      })
      await callApi('api_portfolio.php', 'save_thesis', {
        holding_id: holding.id,
        buy_reason: buyReason,
        exit_criteria: exitCriteria,
      })
    },
    onSuccess: () => { setError(null); onSaved() },
    onError: (e) => setError((e as Error).message),
  })

  return (
    <div className="mt-2 flex flex-col gap-3 rounded-md border border-gray-100 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900/40">
      <div className="flex flex-wrap items-end gap-3">
        <label className="w-36">
          <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Statut</span>
          <Select value={status} onChange={(e) => setStatus(e.target.value as 'simule' | 'achete')}>
            <option value="simule">Simulé</option>
            <option value="achete">Acheté</option>
          </Select>
        </label>
        {status === 'simule' ? (
          <label className="w-44">
            <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Montant envisagé (FCFA)</span>
            <Input type="number" min={0} value={targetAmount} onChange={(e) => setTargetAmount(e.target.value)} />
          </label>
        ) : (
          <>
            <label className="w-32">
              <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Quantité</span>
              <Input type="number" min={0} value={quantity} onChange={(e) => setQuantity(e.target.value)} />
            </label>
            <label className="w-40">
              <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Prix d'achat moyen</span>
              <Input type="number" min={0} value={price} onChange={(e) => setPrice(e.target.value)} />
            </label>
            <label className="w-40">
              <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Date d'achat</span>
              <Input type="date" value={purchaseDate} onChange={(e) => setPurchaseDate(e.target.value)} />
            </label>
          </>
        )}
        <label className="w-40">
          <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Rôle</span>
          <Select value={roleOverride} onChange={(e) => setRoleOverride(e.target.value)}>
            <option value="">Automatique</option>
            <option value="defense">Défense</option>
            <option value="milieu">Milieu</option>
            <option value="attaque">Attaque</option>
            <option value="gardien">Gardien</option>
          </Select>
        </label>
      </div>

      <div>
        <span className="mb-1 block text-xs font-semibold text-gray-700 dark:text-gray-300">Carnet du coach</span>
        <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
          Tes mots, jamais générés par l'IA — pourquoi ce titre, et qu'est-ce qui te ferait changer d'avis.
        </p>
        <div className="flex flex-col gap-2">
          <textarea
            value={buyReason}
            onChange={(e) => setBuyReason(e.target.value)}
            placeholder="Pourquoi ce titre est dans mon équipe…"
            rows={2}
            className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 dark:focus:border-gray-300 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
          />
          <textarea
            value={exitCriteria}
            onChange={(e) => setExitCriteria(e.target.value)}
            placeholder="Ce qui me ferait le sortir de l'équipe…"
            rows={2}
            className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 dark:focus:border-gray-300 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
          />
        </div>
      </div>

      {error && <p className="text-xs text-red-600 dark:text-red-400">{error}</p>}
      <div>
        <Button onClick={() => updateMutation.mutate()} disabled={updateMutation.isPending}>
          {updateMutation.isPending ? 'Enregistrement…' : 'Enregistrer'}
        </Button>
      </div>
    </div>
  )
}

export function PortfolioTeam() {
  const queryClient = useQueryClient()
  const [profile, setProfile] = useState<Profile | null>(null)
  const [budget, setBudget] = useState('')
  const [editingId, setEditingId] = useState<number | null>(null)
  const [showAddPicker, setShowAddPicker] = useState(false)
  const [cashInput, setCashInput] = useState<string | null>(null)

  const teamQuery = useQuery({
    queryKey: ['portfolio-team'],
    queryFn: () => callApi<PortfolioTeamResult>('api_portfolio.php', 'team', {}),
  })

  const suggestionsQuery = useQuery({
    queryKey: ['portfolio-suggestions', profile, budget],
    queryFn: () =>
      callApi<PortfolioSuggestionsResult>('api_portfolio.php', 'suggestions', {
        profile,
        budget_fcfa: budget !== '' ? Number(budget) : undefined,
      }),
    enabled: profile !== null,
  })

  const invalidateTeam = () => queryClient.invalidateQueries({ queryKey: ['portfolio-team'] })

  const addMutation = useMutation({
    mutationFn: (params: { company_id: number; target_amount_fcfa?: number }) =>
      callApi('api_portfolio.php', 'add_holding', { ...params, status: 'simule' }),
    onSuccess: invalidateTeam,
  })

  const removeMutation = useMutation({
    mutationFn: (id: number) => callApi('api_portfolio.php', 'remove_holding', { id }),
    onSuccess: invalidateTeam,
  })

  const cashMutation = useMutation({
    mutationFn: (amount: number) => callApi('api_portfolio.php', 'set_cash_reserve', { amount }),
    onSuccess: () => { setCashInput(null); invalidateTeam() },
  })

  // Proposition d'équipe complète — déclenchée à la demande (pas en
  // automatique : c'est un choix du coach, pas un état par défaut).
  // Deux origines : l'algorithme déterministe, ou l'IA (qui compose
  // elle-même le XI parmi un menu fermé, garde-fous serveur appliqués).
  // Toutes les propositions sont historisées côté backend.
  const [proposal, setProposal] = useState<PortfolioTeamProposal | null>(null)
  const [proposalProvider, setProposalProvider] = useState<'gemini' | 'anthropic' | 'grok'>('gemini')
  const proposeMutation = useMutation({
    mutationFn: () =>
      callApi<PortfolioTeamProposal>('api_portfolio.php', 'propose_team', {
        profile,
        budget_fcfa: budget !== '' ? Number(budget) : undefined,
      }),
    onSuccess: (data) => {
      setProposal(data)
      queryClient.invalidateQueries({ queryKey: ['portfolio-team-proposals'] })
    },
  })
  const proposeAiMutation = useMutation({
    mutationFn: () =>
      callApi<PortfolioTeamProposal>('api_portfolio.php', 'propose_team_ai', {
        profile,
        budget_fcfa: budget !== '' ? Number(budget) : undefined,
        provider: proposalProvider,
      }),
    onSuccess: (data) => {
      setProposal(data)
      queryClient.invalidateQueries({ queryKey: ['portfolio-team-proposals'] })
    },
  })
  const adoptMutation = useMutation({
    mutationFn: (params: { players: { company_id: number; target_amount_fcfa: number | null }[]; cash_reserve_fcfa: number | null }) =>
      callApi('api_portfolio.php', 'adopt_team', params),
    onSuccess: () => { setProposal(null); invalidateTeam() },
  })

  const team = teamQuery.data
  const holdings = team?.holdings ?? []
  const heldCompanyIds = holdings.map((h) => h.company_id)
  const isEmpty = team !== undefined && holdings.length === 0

  const holdingsByRole = useMemo(() => {
    const byRole: Record<string, PortfolioHolding[]> = { defense: [], milieu: [], attaque: [], gardien: [] }
    for (const h of holdings) {
      ;(byRole[h.role] ?? byRole.milieu).push(h)
    }
    return byRole
  }, [holdings])

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Mon Équipe BRVM</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Compose ton portefeuille comme une équipe de foot en 4-3-3 — d'abord en simulation, puis en réel quand tu
          passes à l'achat.
        </p>
      </div>

      <InfoPanel>
        <p>
          <strong>Le concept.</strong> Un bon portefeuille, c'est comme une bonne équipe : des <strong>défenseurs</strong> (valeurs
          stables et de rendement, qui encaissent et versent des dividendes), des <strong>milieux</strong> (profil équilibré,
          qui suivent le cycle économique), des <strong>attaquants</strong> (valeurs dynamiques qui cherchent la croissance,
          mais peuvent rater leur match) — et un <strong>gardien</strong> : ta réserve de liquidité, volontairement hors du
          terrain, qui te permet de jouer l'esprit tranquille.
        </p>
        <p>
          <strong>D'où viennent les rôles et les notes.</strong> Chaque titre reçoit le même score composite 0-100 que
          l'onglet « Score composite » du Screener (Fondamental 30%, Technique 25%, Momentum 15%, Liquidité 10%,
          Secteur 10%, Marché 10%). Le rôle est déduit automatiquement de ces sous-scores (fondamental élevé et
          momentum modéré → Défense ; momentum ou surperformance élevés → Attaque ; le reste → Milieu) — tu peux
          toujours forcer un autre rôle si tu connais une raison que les chiffres ne captent pas. Un badge
          « classification partielle » signale qu'aucun rapport financier n'a encore été traité pour ce titre : le
          rôle est alors déduit des seules données de marché, avec moins de confiance.
        </p>
        <p>
          <strong>Simulé vs acheté.</strong> « Simulé » = tu envisages ce titre, aucun argent n'a bougé — tu peux
          composer et recomposer librement. « Acheté » = tu as réellement acheté, la position est valorisée au
          dernier cours connu × ta quantité. Une même équipe peut mélanger les deux.
        </p>
        <p>
          <strong>Le carnet du coach.</strong> Pour chaque titre, écris <em>pourquoi</em> il est dans ton équipe et
          <em> ce qui te ferait changer d'avis</em> — avant d'acheter, comme un plan de jeu écrit avant le match. Ce
          sont tes mots : l'application ne les génère jamais à ta place.
        </p>
        <p>
          <strong>Diversification imposée dans les propositions.</strong> Une équipe proposée par l'analyse ne
          compte jamais plus de <strong>2 titres du même secteur</strong> (sur ses 10 joueurs) — ne pas mettre tous
          ses œufs dans le même panier : si un secteur entier traverse une crise (bancaire, agricole…), elle ne doit
          pas emporter la moitié de l'équipe. C'est ce que signifie « Secteur saturé » sur un remplaçant : le faire
          entrer porterait son secteur à 3 titres. Remplacer un joueur par un autre du même secteur reste toujours
          possible, et cette règle ne s'applique qu'aux propositions automatiques — tes ajouts manuels restent
          libres (l'alerte de concentration sectorielle te préviendra alors au-delà de 40% du portefeuille).
        </p>
        <p>
          <strong>Ce que cet écran n'est pas.</strong> Un outil d'aide à la réflexion et à l'équilibre, jamais une
          recommandation d'achat ou de vente. Les scores sont des synthèses mécaniques de données passées — ils ne
          prédisent pas l'avenir.
        </p>
      </InfoPanel>

      {teamQuery.isLoading && <LoadingState label="Chargement de l'équipe…" />}
      {teamQuery.error && <ErrorState message={(teamQuery.error as Error).message} />}

      {isEmpty && (
        <>
          <Card title="Composer ma première équipe">
            <p className="mb-3 text-sm text-gray-600 dark:text-gray-300">
              Choisis un profil de jeu — l'application te propose des candidats par ligne, notés et diversifiés par
              secteur. Tu restes le coach : pioche, remplace, ajuste.
            </p>
            <div className="grid gap-3 sm:grid-cols-3">
              {PROFILE_DESCRIPTIONS.map((p) => (
                <button
                  key={p.id}
                  type="button"
                  onClick={() => setProfile(p.id)}
                  className={`rounded-lg border p-3 text-left transition-colors ${
                    profile === p.id
                      ? 'border-gray-900 bg-gray-100 dark:border-white dark:bg-gray-800'
                      : 'border-gray-200 hover:border-gray-300 dark:border-gray-800 dark:hover:border-gray-700'
                  }`}
                >
                  <div className="font-medium">{p.label}</div>
                  <div className="mt-1 text-xs text-gray-500 dark:text-gray-400">{p.description}</div>
                </button>
              ))}
            </div>
            <div className="mt-4 flex flex-wrap items-end gap-3">
              <label className="w-52">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                  Budget total envisagé (FCFA, optionnel)
                </span>
                <Input type="number" min={0} value={budget} onChange={(e) => setBudget(e.target.value)} />
              </label>
              <Button disabled={!profile || proposeMutation.isPending || proposeAiMutation.isPending} onClick={() => proposeMutation.mutate()}>
                {proposeMutation.isPending ? 'Analyse en cours…' : '⚡ Me proposer une équipe complète (4-3-3)'}
              </Button>
              <label className="w-36">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Fournisseur IA</span>
                <Select value={proposalProvider} onChange={(e) => setProposalProvider(e.target.value as 'gemini' | 'anthropic' | 'grok')}>
                  <option value="gemini">Gemini</option>
                  <option value="anthropic">Claude</option>
                  <option value="grok">Grok</option>
                </Select>
              </label>
              <Button variant="secondary" disabled={!profile || proposeMutation.isPending || proposeAiMutation.isPending} onClick={() => proposeAiMutation.mutate()}>
                {proposeAiMutation.isPending ? "L'IA compose…" : "🤖 Laisser l'IA composer l'équipe"}
              </Button>
            </div>
            {proposeMutation.isError && (
              <p className="mt-2 text-xs text-red-600 dark:text-red-400">{(proposeMutation.error as Error).message}</p>
            )}
            {proposeAiMutation.isError && (
              <p className="mt-2 text-xs text-red-600 dark:text-red-400">{(proposeAiMutation.error as Error).message}</p>
            )}
          </Card>

          {proposal && (
            <ProposalView
              key={[...proposal.team.defense, ...proposal.team.milieu, ...proposal.team.attaque].map((p) => p.company_id).join('-')}
              proposal={proposal}
              adopting={adoptMutation.isPending}
              onAdopt={(players, cashReserve) => adoptMutation.mutate({ players, cash_reserve_fcfa: cashReserve })}
            />
          )}
          {adoptMutation.isError && <ErrorState message={(adoptMutation.error as Error).message} />}

          {profile && suggestionsQuery.isLoading && <LoadingState label="Calcul des suggestions…" />}
          {profile && suggestionsQuery.error && <ErrorState message={(suggestionsQuery.error as Error).message} />}

          {profile && suggestionsQuery.data && (
            <div className="grid gap-4 lg:grid-cols-3">
              {(['defense', 'milieu', 'attaque'] as Role[]).map((role) => (
                <Card key={role} title={`${ROLE_LABELS[role]} (${suggestionsQuery.data!.target_weights[role]}% du budget)`}>
                  <div className="flex flex-col gap-2">
                    {suggestionsQuery.data!.candidates[role].length === 0 && (
                      <p className="text-xs text-gray-500 dark:text-gray-400">
                        Aucun candidat net pour ce rôle actuellement.
                      </p>
                    )}
                    {suggestionsQuery.data!.candidates[role].map((c) => (
                      <CandidateCard
                        key={c.company_id}
                        candidate={c}
                        alreadyInTeam={heldCompanyIds.includes(c.company_id)}
                        isPending={addMutation.isPending}
                        onAdd={() =>
                          addMutation.mutate({
                            company_id: c.company_id,
                            target_amount_fcfa: c.suggested_amount_fcfa ?? undefined,
                          })
                        }
                      />
                    ))}
                  </div>
                </Card>
              ))}
            </div>
          )}

          <Card title="Ou ajouter un titre de mon choix">
            <AddCompanyPicker
              excludeIds={heldCompanyIds}
              isPending={addMutation.isPending}
              onAdd={(companyId, amount) =>
                addMutation.mutate({ company_id: companyId, target_amount_fcfa: amount ?? undefined })
              }
            />
          </Card>
          {addMutation.isError && <ErrorState message={(addMutation.error as Error).message} />}
        </>
      )}

      {team && holdings.length > 0 && (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile label="Valeur du portefeuille" value={fmtFcfa(team.total_portfolio_value_fcfa)} />
            <StatTile label="Avec la réserve (gardien)" value={fmtFcfa(team.total_value_with_cash_fcfa)} />
            <StatTile
              label="Score d'équilibre"
              value={`${team.balance_score}/100`}
              tone={team.balance_score >= 80 ? 'positive' : team.balance_score < 50 ? 'negative' : 'default'}
            />
            <StatTile label="Titres dans l'équipe" value={holdings.length} />
          </div>

          {team.alerts.length > 0 && (
            <div className="flex flex-col gap-2">
              {team.alerts.map((a, i) => (
                <div
                  key={i}
                  className={`rounded-md border px-4 py-3 text-sm ${
                    a.severity === 'warning'
                      ? 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300'
                      : 'border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-300'
                  }`}
                >
                  {a.message}
                </div>
              ))}
            </div>
          )}

          <AiReviewPanel onApplied={invalidateTeam} />

          <Card title="Gardien — réserve de sécurité">
            <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
              Ton argent gardé de côté, hors du terrain — c'est lui qui te sauve en cas d'imprévu, sans avoir à
              vendre tes positions au mauvais moment.
            </p>
            <div className="flex flex-wrap items-end gap-3">
              <label className="w-48">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Montant (FCFA)</span>
                <Input
                  type="number"
                  min={0}
                  value={cashInput ?? String(team.cash_reserve.amount)}
                  onChange={(e) => setCashInput(e.target.value)}
                />
              </label>
              {cashInput !== null && cashInput !== String(team.cash_reserve.amount) && (
                <Button onClick={() => cashMutation.mutate(Number(cashInput))} disabled={cashMutation.isPending}>
                  {cashMutation.isPending ? 'Enregistrement…' : 'Enregistrer'}
                </Button>
              )}
            </div>
          </Card>

          {(['defense', 'milieu', 'attaque'] as Role[]).map((role) => {
            const line = team.lines[role]
            const roleHoldings = holdingsByRole[role]
            return (
              <Card key={role} title={`${ROLE_LABELS[role]} · ${roleHoldings.length} titre(s) · ${fmtFcfa(line.value_fcfa)}${line.score !== null ? ` · note ${line.score.toFixed(0)}` : ''}`}>
                {roleHoldings.length === 0 && (
                  <p className="text-sm text-gray-500 dark:text-gray-400">Aucun titre sur cette ligne.</p>
                )}
                <div className="flex flex-col gap-2">
                  {roleHoldings.map((h) => (
                    <div key={h.id} className="rounded-md border border-gray-100 px-3 py-2 dark:border-gray-800">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="font-medium">{h.symbol}</span>
                          <span className="text-sm text-gray-500 dark:text-gray-400">{h.name}</span>
                          <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${scoreBadgeClass(h.composite_score)}`}>
                            {h.composite_score !== null ? h.composite_score.toFixed(0) : '—'}
                          </span>
                          <span
                            className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
                              h.status === 'achete'
                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                                : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'
                            }`}
                          >
                            {h.status === 'achete' ? 'Acheté' : 'Simulé'}
                          </span>
                          {h.role_partial && (
                            <span
                              className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-950 dark:text-amber-300"
                              title="Classification faite sans fondamentaux (aucun rapport financier traité pour ce titre) — moins de confiance."
                            >
                              partielle
                            </span>
                          )}
                          {h.role_override && (
                            <span className="text-xs text-gray-400 dark:text-gray-500" title="Rôle forcé manuellement">
                              (manuel)
                            </span>
                          )}
                        </div>
                        <div className="flex items-center gap-3">
                          <span className="text-sm tabular-nums text-gray-600 dark:text-gray-300">{fmtFcfa(h.position_value_fcfa)}</span>
                          <button
                            type="button"
                            onClick={() => setEditingId(editingId === h.id ? null : h.id)}
                            className="text-xs text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                          >
                            {editingId === h.id ? 'Fermer' : 'Modifier'}
                          </button>
                          <button
                            type="button"
                            onClick={() => {
                              if (window.confirm(`Retirer ${h.symbol} de l'équipe ? Le carnet du coach lié sera aussi supprimé.`)) {
                                removeMutation.mutate(h.id)
                              }
                            }}
                            className="text-xs text-red-500 underline hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                          >
                            Retirer
                          </button>
                        </div>
                      </div>
                      {h.thesis && editingId !== h.id && (
                        <p className="mt-1 text-xs italic text-gray-500 dark:text-gray-400">
                          « {h.thesis.buy_reason ?? '…'} »
                        </p>
                      )}
                      {editingId === h.id && (
                        <HoldingEditor holding={h} onSaved={() => { setEditingId(null); invalidateTeam() }} />
                      )}
                    </div>
                  ))}
                </div>
              </Card>
            )
          })}

          {holdingsByRole.gardien.length > 0 && (
            <Card title="Positions classées « Gardien » (manuellement)">
              <div className="flex flex-col gap-2">
                {holdingsByRole.gardien.map((h) => (
                  <div key={h.id} className="flex items-center justify-between rounded-md border border-gray-100 px-3 py-2 dark:border-gray-800">
                    <span className="font-medium">{h.symbol} <span className="text-sm font-normal text-gray-500">{h.name}</span></span>
                    <span className="text-sm tabular-nums">{fmtFcfa(h.position_value_fcfa)}</span>
                  </div>
                ))}
              </div>
            </Card>
          )}

          <Card title="Me proposer des renforts">
            <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
              L'analyse propose une équipe complète en 4-3-3 parmi les titres que tu n'as pas encore — tu décoches ce
              que tu ne veux pas avant d'adopter.
            </p>
            <div className="flex flex-wrap items-end gap-3">
              <label className="w-44">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Profil</span>
                <Select value={profile ?? ''} onChange={(e) => setProfile((e.target.value || null) as Profile | null)}>
                  <option value="">— Choisir —</option>
                  {PROFILE_DESCRIPTIONS.map((p) => (
                    <option key={p.id} value={p.id}>{p.label}</option>
                  ))}
                </Select>
              </label>
              <label className="w-52">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Budget (FCFA, optionnel)</span>
                <Input type="number" min={0} value={budget} onChange={(e) => setBudget(e.target.value)} />
              </label>
              <Button disabled={!profile || proposeMutation.isPending || proposeAiMutation.isPending} onClick={() => proposeMutation.mutate()}>
                {proposeMutation.isPending ? 'Analyse en cours…' : '⚡ Proposer'}
              </Button>
              <label className="w-36">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Fournisseur IA</span>
                <Select value={proposalProvider} onChange={(e) => setProposalProvider(e.target.value as 'gemini' | 'anthropic' | 'grok')}>
                  <option value="gemini">Gemini</option>
                  <option value="anthropic">Claude</option>
                  <option value="grok">Grok</option>
                </Select>
              </label>
              <Button variant="secondary" disabled={!profile || proposeMutation.isPending || proposeAiMutation.isPending} onClick={() => proposeAiMutation.mutate()}>
                {proposeAiMutation.isPending ? "L'IA compose…" : '🤖 Par l\'IA'}
              </Button>
            </div>
            {proposeMutation.isError && (
              <p className="mt-2 text-xs text-red-600 dark:text-red-400">{(proposeMutation.error as Error).message}</p>
            )}
            {proposeAiMutation.isError && (
              <p className="mt-2 text-xs text-red-600 dark:text-red-400">{(proposeAiMutation.error as Error).message}</p>
            )}
          </Card>

          {proposal && (
            <ProposalView
              key={[...proposal.team.defense, ...proposal.team.milieu, ...proposal.team.attaque].map((p) => p.company_id).join('-')}
              proposal={proposal}
              adopting={adoptMutation.isPending}
              onAdopt={(players, cashReserve) => adoptMutation.mutate({ players, cash_reserve_fcfa: cashReserve })}
            />
          )}
          {adoptMutation.isError && <ErrorState message={(adoptMutation.error as Error).message} />}

          <Card title="Ajouter un titre">
            {!showAddPicker ? (
              <Button variant="secondary" onClick={() => setShowAddPicker(true)}>+ Ajouter un titre</Button>
            ) : (
              <AddCompanyPicker
                excludeIds={heldCompanyIds}
                isPending={addMutation.isPending}
                onAdd={(companyId, amount) => {
                  addMutation.mutate({ company_id: companyId, target_amount_fcfa: amount ?? undefined })
                  setShowAddPicker(false)
                }}
              />
            )}
            {addMutation.isError && <p className="mt-2 text-xs text-red-600 dark:text-red-400">{(addMutation.error as Error).message}</p>}
          </Card>
        </>
      )}

      <ProposalHistory onConsult={setProposal} />
    </div>
  )
}
