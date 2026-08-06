import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { callApi } from '../lib/apiClient'
import type { Company, CorporateActionsListResult } from '../lib/types'
import { Button, Card, ErrorState, InfoPanel, Input, LoadingState, Select } from '../components/ui'
import { ChartAiAnalysis } from '../components/ChartAiAnalysis'

const ACTION_TYPES = [
  { value: 'dividende', label: 'Dividende' },
  { value: 'augmentation_capital', label: 'Augmentation de capital' },
  { value: 'admission', label: 'Admission' },
  { value: 'assemblee_generale', label: 'Assemblée générale' },
  { value: 'autre', label: 'Autre' },
] as const

function actionTypeLabel(type: string): string {
  return ACTION_TYPES.find((t) => t.value === type)?.label ?? type
}

function actionTypeBadgeClass(type: string): string {
  switch (type) {
    case 'dividende':
      return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
    case 'augmentation_capital':
      return 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300'
    case 'admission':
      return 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
    case 'assemblee_generale':
      return 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300'
    default:
      return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'
  }
}

function fmtAmount(amount: string | number | null, currency: string): string {
  if (amount === null) return '—'
  const num = typeof amount === 'string' ? parseFloat(amount) : amount
  if (Number.isNaN(num)) return '—'
  return `${num.toLocaleString('fr-FR', { maximumFractionDigits: 2 })} ${currency}`
}

export function CorporateActions() {
  const queryClient = useQueryClient()
  const [companyId, setCompanyId] = useState('')
  const [actionType, setActionType] = useState('')
  const [startDate, setStartDate] = useState('')
  const [endDate, setEndDate] = useState('')
  const [extractingId, setExtractingId] = useState<number | null>(null)

  const companiesQuery = useQuery({
    queryKey: ['companies-list'],
    queryFn: () => callApi<Company[]>('api_companies.php', 'list', { per_page: 200, active: 1 }),
  })

  const companyOptions = useMemo(() => {
    return [...(companiesQuery.data ?? [])].sort((a, b) => a.name.localeCompare(b.name))
  }, [companiesQuery.data])

  const filters = {
    company_id: companyId || undefined,
    action_type: actionType || undefined,
    start_date: startDate || undefined,
    end_date: endDate || undefined,
  }

  const listQuery = useQuery({
    queryKey: ['corporate-actions-list', filters],
    queryFn: () => callApi<CorporateActionsListResult>('api_bulletin_corporate_actions.php', 'list', filters),
  })

  const extractMutation = useMutation({
    mutationFn: (bulletinId: number) =>
      callApi('api_bulletin_corporate_actions.php', 'extract', { bulletin_id: bulletinId }),
    onMutate: (bulletinId) => setExtractingId(bulletinId),
    onSettled: () => {
      setExtractingId(null)
      queryClient.invalidateQueries({ queryKey: ['corporate-actions-list'] })
    },
  })

  const data = listQuery.data
  const rows = data?.actions ?? []

  function resetFilters() {
    setCompanyId('')
    setActionType('')
    setStartDate('')
    setEndDate('')
  }
  const hasActiveFilters = companyId !== '' || actionType !== '' || startDate !== '' || endDate !== ''

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Opérations sur titres</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Calendrier des dividendes, augmentations de capital, admissions et assemblées générales, extraits par IA
          des Bulletins Officiels de la Cote déjà traités.
        </p>
      </div>

      <InfoPanel>
        <p>
          <strong>À quoi sert cet écran.</strong> Chaque Bulletin Officiel de la Cote (BOC) peut annoncer des
          opérations sur titres — paiement de dividendes, augmentation de capital, admission d'un nouveau titre,
          convocation ou tenue d'une assemblée générale. Ces annonces sont noyées dans un document PDF de plusieurs
          dizaines de pages, différent chaque jour. Cet écran les extrait automatiquement par IA et les rassemble
          dans un calendrier interrogeable, plutôt que d'obliger à rouvrir chaque bulletin un par un.
        </p>
        <p>
          <strong>Rattachement à une entreprise.</strong> Le nom mentionné dans le bulletin est automatiquement
          rapproché d'une entreprise de la base. « Rattachement exact » = correspondance de nom sans ambiguïté ;
          « approximatif » = correspondance probable mais pas garantie (nom mal orthographié dans le bulletin,
          abréviation...) — vérifie la colonne « Nom dans le bulletin » en cas de doute. Aucun rattachement
          n'apparaît si le nom n'a pu être rapproché d'aucune entreprise avec une confiance suffisante.
        </p>
        <p>
          <strong>Date d'événement absente.</strong> Certaines annonces (ex. « assemblée générale à une date
          ultérieure ») ne précisent pas de date exacte dans le bulletin — la ligne apparaît alors sans date plutôt
          que d'inventer une échéance.
        </p>
        <p>
          <strong>Complétude.</strong> Ce calendrier ne contient que ce qui a été identifié dans les bulletins déjà
          extraits. L'absence d'une opération ne signifie pas qu'elle n'existe pas — clique sur « Extraire » pour
          les bulletins encore en attente ci-dessous pour compléter la couverture.
        </p>
      </InfoPanel>

      {!!data?.pending_count && (
        <Card>
          <div className="mb-2 flex items-center justify-between">
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">
              {data.pending_count} bulletin(s) pas encore extrait(s)
            </h3>
          </div>
          <div className="flex flex-col gap-2">
            {data.pending_bulletins.map((b) => (
              <div key={b.id} className="flex items-center justify-between gap-3 rounded-md border border-gray-100 px-3 py-2 text-sm dark:border-gray-800">
                <span className="text-gray-600 dark:text-gray-300">
                  {b.publish_date} — {b.title}
                </span>
                <Button
                  variant="secondary"
                  disabled={extractMutation.isPending && extractingId === b.id}
                  onClick={() => extractMutation.mutate(b.id)}
                >
                  {extractMutation.isPending && extractingId === b.id ? 'Extraction…' : 'Extraire'}
                </Button>
              </div>
            ))}
          </div>
          {extractMutation.isError && (
            <p className="mt-2 text-xs text-red-600 dark:text-red-400">
              {(extractMutation.error as Error).message}
            </p>
          )}
        </Card>
      )}

      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <label className="w-56">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Entreprise</span>
            <Select value={companyId} onChange={(e) => setCompanyId(e.target.value)}>
              <option value="">Toutes</option>
              {companyOptions.map((c) => (
                <option key={c.company_id} value={c.company_id}>{c.name}</option>
              ))}
            </Select>
          </label>
          <label className="w-52">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Type d'opération</span>
            <Select value={actionType} onChange={(e) => setActionType(e.target.value)}>
              <option value="">Tous</option>
              {ACTION_TYPES.map((t) => (
                <option key={t.value} value={t.value}>{t.label}</option>
              ))}
            </Select>
          </label>
          <label className="w-40">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de début</span>
            <Input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
          </label>
          <label className="w-40">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de fin</span>
            <Input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
          </label>
          {hasActiveFilters && (
            <button
              type="button"
              onClick={resetFilters}
              className="text-xs text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
            >
              Réinitialiser les filtres
            </button>
          )}
        </div>
      </Card>

      {listQuery.isLoading && <LoadingState label="Chargement du calendrier…" />}
      {listQuery.error && <ErrorState message={(listQuery.error as Error).message} />}

      {data && rows.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Aucune opération ne correspond à ces critères.
        </p>
      )}

      {rows.length > 0 && (
        <Card>
          <div className="mb-3 text-xs text-gray-500 dark:text-gray-400">{rows.length} opération(s)</div>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  <th className="pb-2 pr-3">Date</th>
                  <th className="pb-2 pr-3">Entreprise</th>
                  <th className="pb-2 pr-3">Nom dans le bulletin</th>
                  <th className="pb-2 pr-3">Type</th>
                  <th className="pb-2 pr-3 text-right">Montant</th>
                  <th className="pb-2 pr-3">Description</th>
                  <th className="pb-2 pr-3">Bulletin source</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id} className="border-t border-gray-100 align-top dark:border-gray-800">
                    <td className="py-2 pr-3 whitespace-nowrap tabular-nums">{r.event_date ?? '—'}</td>
                    <td className="py-2 pr-3">
                      {r.company_symbol ? (
                        <span className="font-medium">{r.company_symbol}</span>
                      ) : (
                        <span className="text-gray-400 dark:text-gray-500">Non rattaché</span>
                      )}
                      {r.match_confidence === 'fuzzy' && (
                        <span
                          className="ml-1.5 rounded px-1 py-0.5 text-[10px] font-semibold bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300"
                          title="Rattachement approximatif — à vérifier"
                        >
                          approx.
                        </span>
                      )}
                    </td>
                    <td className="py-2 pr-3 text-gray-500 dark:text-gray-400">{r.company_name_raw}</td>
                    <td className="py-2 pr-3">
                      <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${actionTypeBadgeClass(r.action_type)}`}>
                        {actionTypeLabel(r.action_type)}
                      </span>
                    </td>
                    <td className="py-2 pr-3 text-right tabular-nums">{fmtAmount(r.amount, r.currency)}</td>
                    <td className="py-2 pr-3 max-w-md text-gray-600 dark:text-gray-300">{r.description ?? '—'}</td>
                    <td className="py-2 pr-3 whitespace-nowrap text-gray-500 dark:text-gray-400">
                      {r.bulletin_publish_date}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <ChartAiAnalysis
            chartType="corporate_actions"
            parameters={filters}
            data={rows}
            disabled={rows.length === 0}
          />
        </Card>
      )}
    </div>
  )
}
