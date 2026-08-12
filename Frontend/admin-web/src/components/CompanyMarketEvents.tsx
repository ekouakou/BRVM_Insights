import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { callApi } from '../lib/apiClient'
import type { CompanyMarketEvent, MarketEventCandidate, MarketEventSearchResult } from '../lib/types'
import { Button, Card, ErrorState, Input, LoadingState, Select } from './ui'

const IMPACT_OPTIONS = [
  { value: '', label: 'Non tranché' },
  { value: 'positif', label: 'Positif' },
  { value: 'negatif', label: 'Négatif' },
  { value: 'neutre', label: 'Neutre' },
  { value: 'indetermine', label: 'Indéterminé' },
] as const

function impactBadgeClass(impact: CompanyMarketEvent['impact_assessment']) {
  switch (impact) {
    case 'positif':
      return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
    case 'negatif':
      return 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
    case 'neutre':
      return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'
    case 'indetermine':
      return 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
    default:
      return 'bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500'
  }
}

/** Formulaire d'événement — partagé entre saisie manuelle et confirmation d'un candidat IA. */
function EventForm({
  initial,
  onSubmit,
  isPending,
  submitLabel,
}: {
  initial?: Partial<MarketEventCandidate>
  onSubmit: (fields: { title: string; description: string; event_date: string | null; source_url: string | null; impact_assessment: string | null }) => void
  isPending: boolean
  submitLabel: string
}) {
  const [title, setTitle] = useState(initial?.title ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [eventDate, setEventDate] = useState(initial?.event_date ?? '')
  const [sourceUrl, setSourceUrl] = useState(initial?.source_url ?? '')
  const [impact, setImpact] = useState('')

  return (
    <div className="flex flex-col gap-2">
      <Input placeholder="Titre de l'événement" value={title} onChange={(e) => setTitle(e.target.value)} />
      <textarea
        placeholder="Description factuelle…"
        value={description}
        onChange={(e) => setDescription(e.target.value)}
        rows={3}
        className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 dark:focus:border-gray-300 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
      />
      <div className="flex flex-wrap items-end gap-3">
        <label className="w-40">
          <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Date de l'événement</span>
          <Input type="date" value={eventDate} onChange={(e) => setEventDate(e.target.value)} />
        </label>
        <label className="w-64">
          <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">URL source</span>
          <Input type="url" placeholder="https://…" value={sourceUrl} onChange={(e) => setSourceUrl(e.target.value)} />
        </label>
        <label className="w-40">
          <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300" title="Ton jugement — jamais rempli par l'IA">
            Impact (ton jugement)
          </span>
          <Select value={impact} onChange={(e) => setImpact(e.target.value)}>
            {IMPACT_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </Select>
        </label>
        <Button
          disabled={isPending || title.trim() === '' || description.trim() === ''}
          onClick={() =>
            onSubmit({
              title: title.trim(),
              description: description.trim(),
              event_date: eventDate || null,
              source_url: sourceUrl.trim() || null,
              impact_assessment: impact || null,
            })
          }
        >
          {isPending ? 'Enregistrement…' : submitLabel}
        </Button>
      </div>
    </div>
  )
}

/**
 * Journal d'informations susceptibles d'affecter le cours d'une entreprise
 * — saisie manuelle + recherche web IA avec confirmation humaine
 * obligatoire avant tout stockage (voir TODO_PENDING.md, point 27).
 */
export function CompanyMarketEvents({ companyId, companyName }: { companyId: number; companyName: string }) {
  const queryClient = useQueryClient()
  const [showManualForm, setShowManualForm] = useState(false)
  const [provider, setProvider] = useState<'gemini' | 'anthropic' | 'grok'>('gemini')
  const [searchResult, setSearchResult] = useState<MarketEventSearchResult | null>(null)
  const [savedCandidates, setSavedCandidates] = useState<number[]>([])
  const [dismissedCandidates, setDismissedCandidates] = useState<number[]>([])
  const [editingEventId, setEditingEventId] = useState<number | null>(null)

  const eventsQuery = useQuery({
    queryKey: ['company-market-events', companyId],
    queryFn: () => callApi<{ events: CompanyMarketEvent[]; count: number }>('api_company_market_events.php', 'list', { company_id: companyId }),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['company-market-events', companyId] })

  const addMutation = useMutation({
    mutationFn: (params: Record<string, unknown>) => callApi('api_company_market_events.php', 'add', { company_id: companyId, ...params }),
    onSuccess: invalidate,
  })

  const updateImpactMutation = useMutation({
    mutationFn: (params: { id: number; impact_assessment: string | null }) =>
      callApi('api_company_market_events.php', 'update', params),
    onSuccess: () => { setEditingEventId(null); invalidate() },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => callApi('api_company_market_events.php', 'delete', { id }),
    onSuccess: invalidate,
  })

  const searchMutation = useMutation({
    mutationFn: () => callApi<MarketEventSearchResult>('api_company_market_events.php', 'search', { company_id: companyId, provider }),
    onSuccess: (d) => { setSearchResult(d); setSavedCandidates([]); setDismissedCandidates([]) },
  })

  const events = eventsQuery.data?.events ?? []

  return (
    <div className="flex flex-col gap-4">
      <Card title={`Rechercher avec l'IA — ${companyName}`}>
        <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
          L'IA cherche sur internet les informations récentes susceptibles d'influencer le cours (annonces,
          résultats, contrats, litiges…). <strong>Rien n'est enregistré sans ta confirmation</strong> : tu relis,
          édites et valides chaque résultat un par un — et l'impact (positif/négatif) reste ton jugement, jamais le
          sien.
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
          <Button disabled={searchMutation.isPending} onClick={() => searchMutation.mutate()}>
            {searchMutation.isPending ? 'Recherche en cours…' : '🔎 Lancer la recherche'}
          </Button>
        </div>
        {searchMutation.isError && (
          <p className="mt-2 text-xs text-red-600 dark:text-red-400">{(searchMutation.error as Error).message}</p>
        )}

        {searchResult && (
          <div className="mt-4 flex flex-col gap-3">
            {searchResult.candidates.length === 0 && (
              <div className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">
                <p className="mb-1 font-semibold">Aucun événement exploitable trouvé.</p>
                <p className="whitespace-pre-wrap">{searchResult.raw_text.slice(0, 600)}</p>
              </div>
            )}
            {searchResult.candidates.map((c, i) => {
              if (savedCandidates.includes(i)) {
                return (
                  <p key={i} className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">
                    ✓ « {c.title} » enregistré dans le journal.
                  </p>
                )
              }
              if (dismissedCandidates.includes(i)) return null
              return (
                <div key={i} className="rounded-md border border-gray-200 px-3 py-3 dark:border-gray-700">
                  <div className="mb-2 flex items-start justify-between gap-2">
                    <span className="text-xs font-semibold text-gray-500 dark:text-gray-400">
                      Résultat IA à vérifier — édite librement avant d'enregistrer
                    </span>
                    <button
                      type="button"
                      onClick={() => setDismissedCandidates((prev) => [...prev, i])}
                      className="text-xs text-gray-400 underline hover:text-gray-600 dark:hover:text-gray-300"
                    >
                      Ignorer
                    </button>
                  </div>
                  <EventForm
                    initial={c}
                    isPending={addMutation.isPending}
                    submitLabel="Confirmer et enregistrer"
                    onSubmit={(fields) =>
                      addMutation.mutate(
                        { ...fields, source_type: 'ia_recherche' },
                        { onSuccess: () => { setSavedCandidates((prev) => [...prev, i]); invalidate() } },
                      )
                    }
                  />
                </div>
              )
            })}
            {searchResult.sources.length > 0 && (
              <div className="text-xs text-gray-500 dark:text-gray-400">
                <span className="font-semibold">Sources citées par la recherche : </span>
                {searchResult.sources.map((s, i) => (
                  <a key={i} href={s.url} target="_blank" rel="noreferrer" className="mr-2 underline hover:text-gray-700 dark:hover:text-gray-200">
                    {s.title ?? s.url}
                  </a>
                ))}
              </div>
            )}
            <p className="text-xs italic text-gray-400 dark:text-gray-500">
              {searchResult.disclaimer} ({searchResult.provider}/{searchResult.model})
            </p>
          </div>
        )}
      </Card>

      <Card title="Ajouter une information manuellement">
        {!showManualForm ? (
          <Button variant="secondary" onClick={() => setShowManualForm(true)}>+ Ajouter une information</Button>
        ) : (
          <EventForm
            isPending={addMutation.isPending}
            submitLabel="Enregistrer"
            onSubmit={(fields) =>
              addMutation.mutate(
                { ...fields, source_type: 'utilisateur' },
                { onSuccess: () => { setShowManualForm(false); invalidate() } },
              )
            }
          />
        )}
        {addMutation.isError && (
          <p className="mt-2 text-xs text-red-600 dark:text-red-400">{(addMutation.error as Error).message}</p>
        )}
      </Card>

      <Card title={`Journal des informations (${events.length})`}>
        {eventsQuery.isLoading && <LoadingState />}
        {eventsQuery.error && <ErrorState message={(eventsQuery.error as Error).message} />}
        {eventsQuery.data && events.length === 0 && (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Aucune information enregistrée pour cette entreprise — utilise la recherche IA ou la saisie manuelle
            ci-dessus pour constituer l'historique.
          </p>
        )}
        <div className="flex flex-col gap-2">
          {events.map((e) => (
            <div key={e.id} className="rounded-md border border-gray-100 px-3 py-2 dark:border-gray-800">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-sm font-medium">{e.title}</span>
                  <span className="text-xs tabular-nums text-gray-500 dark:text-gray-400">{e.event_date ?? 'date inconnue'}</span>
                  <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${impactBadgeClass(e.impact_assessment)}`}>
                    {e.impact_assessment ? IMPACT_OPTIONS.find((o) => o.value === e.impact_assessment)?.label : 'Impact non tranché'}
                  </span>
                  <span
                    className="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-500 dark:bg-gray-800 dark:text-gray-400"
                    title={e.source_type === 'ia_recherche' ? 'Trouvé par la recherche IA, confirmé par un utilisateur' : 'Saisi manuellement'}
                  >
                    {e.source_type === 'ia_recherche' ? 'IA (confirmé)' : 'Manuel'}
                  </span>
                </div>
                <div className="flex items-center gap-3">
                  <button
                    type="button"
                    onClick={() => setEditingEventId(editingEventId === e.id ? null : e.id)}
                    className="text-xs text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                  >
                    Impact
                  </button>
                  <button
                    type="button"
                    onClick={() => {
                      if (window.confirm(`Supprimer définitivement « ${e.title} » du journal ?`)) {
                        deleteMutation.mutate(e.id)
                      }
                    }}
                    className="text-xs text-red-500 underline hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                  >
                    Supprimer
                  </button>
                </div>
              </div>
              <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">{e.description}</p>
              {e.source_url && (
                <a href={e.source_url} target="_blank" rel="noreferrer" className="text-xs text-gray-700 underline-offset-2 underline hover:text-black dark:text-gray-200">
                  Source
                </a>
              )}
              {editingEventId === e.id && (
                <div className="mt-2 flex flex-wrap items-end gap-3">
                  <label className="w-44">
                    <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                      Mon jugement d'impact
                    </span>
                    <Select
                      defaultValue={e.impact_assessment ?? ''}
                      onChange={(ev) => updateImpactMutation.mutate({ id: e.id, impact_assessment: ev.target.value || null })}
                    >
                      {IMPACT_OPTIONS.map((o) => (
                        <option key={o.value} value={o.value}>{o.label}</option>
                      ))}
                    </Select>
                  </label>
                </div>
              )}
            </div>
          ))}
        </div>
      </Card>
    </div>
  )
}
