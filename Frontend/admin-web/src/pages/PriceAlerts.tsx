import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { callApi } from '../lib/apiClient'
import type { Company, PriceAlert, PriceAlertCheckResult } from '../lib/types'
import { Button, Card, ErrorState, Input, LoadingState, SearchableSelect, Select, Table } from '../components/ui'

type AlertType = 'above' | 'below' | 'change_percent'

export function PriceAlerts() {
  const queryClient = useQueryClient()

  const [companyId, setCompanyId] = useState<number | ''>('')
  const [alertType, setAlertType] = useState<AlertType>('above')
  const [targetPrice, setTargetPrice] = useState('')
  const [targetPercent, setTargetPercent] = useState('')

  const companiesQuery = useQuery({
    queryKey: ['companies-list'],
    queryFn: () => callApi<Company[]>('api_companies.php', 'list', { per_page: 200, active: 1 }),
  })

  const alertsQuery = useQuery({
    queryKey: ['price-alerts'],
    queryFn: () => callApi<PriceAlert[]>('api_price_alerts.php', 'list', {}),
  })

  const createMutation = useMutation({
    mutationFn: () =>
      callApi('api_price_alerts.php', 'create', {
        company_id: companyId,
        alert_type: alertType,
        target_price: alertType !== 'change_percent' ? Number(targetPrice) : undefined,
        target_percent: alertType === 'change_percent' ? Number(targetPercent) : undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['price-alerts'] })
      setCompanyId('')
      setTargetPrice('')
      setTargetPercent('')
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => callApi('api_price_alerts.php', 'delete', { id }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['price-alerts'] }),
  })

  const toggleActiveMutation = useMutation({
    mutationFn: ({ id, active }: { id: number; active: boolean }) =>
      callApi('api_price_alerts.php', 'update', { id, active: active ? 1 : 0 }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['price-alerts'] }),
  })

  const checkMutation = useMutation({
    mutationFn: () => callApi<PriceAlertCheckResult>('api_price_alerts.php', 'check', {}),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['price-alerts'] }),
  })

  const companies = companiesQuery.data ?? []

  function alertLabel(a: PriceAlert) {
    if (a.alert_type === 'above') return `Cours ≥ ${a.target_price} FCFA`
    if (a.alert_type === 'below') return `Cours ≤ ${a.target_price} FCFA`
    return `|Variation| ≥ ${a.target_percent}%`
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Alertes de prix</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Définis des seuils de prix ou de variation par entreprise. La vérification est manuelle pour l'instant (bouton
          "Vérifier maintenant" ci-dessous, contre la dernière clôture connue) — aucun email ni webhook n'est envoyé
          automatiquement, seul le statut "déclenchée" est mis à jour.
        </p>
      </div>

      <Card title="Nouvelle alerte">
        <div className="flex flex-wrap items-end gap-4">
          <label className="min-w-[220px] flex-1">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Entreprise</span>
            <SearchableSelect
              value={companyId === '' ? '' : String(companyId)}
              onChange={(v) => setCompanyId(v ? Number(v) : '')}
              options={companies.map((c) => ({ value: String(c.company_id), label: `${c.symbol} — ${c.name}` }))}
            />
          </label>

          <label className="w-52">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Type</span>
            <Select value={alertType} onChange={(e) => setAlertType(e.target.value as AlertType)}>
              <option value="above">Cours au-dessus de</option>
              <option value="below">Cours en-dessous de</option>
              <option value="change_percent">Variation absolue au moins</option>
            </Select>
          </label>

          {alertType !== 'change_percent' ? (
            <label className="w-40">
              <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Prix cible (FCFA)</span>
              <Input type="number" value={targetPrice} onChange={(e) => setTargetPrice(e.target.value)} />
            </label>
          ) : (
            <label className="w-40">
              <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Variation (%)</span>
              <Input type="number" value={targetPercent} onChange={(e) => setTargetPercent(e.target.value)} />
            </label>
          )}

          <Button
            onClick={() => createMutation.mutate()}
            disabled={
              createMutation.isPending ||
              !companyId ||
              (alertType !== 'change_percent' ? !targetPrice : !targetPercent)
            }
          >
            {createMutation.isPending ? 'Création…' : 'Créer l’alerte'}
          </Button>
        </div>
        {createMutation.isError && <ErrorState message={(createMutation.error as Error).message} />}
      </Card>

      <div className="flex items-center justify-between">
        <h3 className="text-lg font-semibold">Alertes existantes</h3>
        <Button variant="secondary" onClick={() => checkMutation.mutate()} disabled={checkMutation.isPending}>
          {checkMutation.isPending ? 'Vérification…' : 'Vérifier maintenant'}
        </Button>
      </div>

      {checkMutation.isSuccess && (
        <p className="text-sm text-gray-500 dark:text-gray-400">
          {checkMutation.data.triggered_count > 0
            ? `${checkMutation.data.triggered_count} alerte(s) déclenchée(s) sur ${checkMutation.data.checked_count} vérifiée(s).`
            : `Aucune alerte déclenchée (${checkMutation.data.checked_count} vérifiée(s)).`}
        </p>
      )}
      {checkMutation.isError && <ErrorState message={(checkMutation.error as Error).message} />}

      {alertsQuery.isLoading && <LoadingState />}
      {alertsQuery.error && <ErrorState message={(alertsQuery.error as Error).message} />}

      {alertsQuery.data && alertsQuery.data.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Aucune alerte configurée.</p>
      )}

      {alertsQuery.data && alertsQuery.data.length > 0 && (
        <Card>
          <Table headers={['Symbole', 'Entreprise', 'Condition', 'Statut', 'Créée le', '']}>
            {alertsQuery.data.map((a) => (
              <tr key={a.id}>
                <td className="px-3 py-2 font-medium">{a.symbol}</td>
                <td className="px-3 py-2 text-gray-600 dark:text-gray-300">{a.name}</td>
                <td className="px-3 py-2">{alertLabel(a)}</td>
                <td className="px-3 py-2">
                  {a.triggered ? (
                    <span className="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-950 dark:text-amber-300">
                      Déclenchée{a.triggered_at ? ` (${a.triggered_at})` : ''}
                    </span>
                  ) : a.active ? (
                    <span className="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                      Active
                    </span>
                  ) : (
                    <span className="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                      Désactivée
                    </span>
                  )}
                </td>
                <td className="px-3 py-2 whitespace-nowrap text-gray-500 dark:text-gray-400">{a.created_at}</td>
                <td className="px-3 py-2 text-right">
                  <div className="flex justify-end gap-2">
                    {!a.triggered && (
                      <button
                        type="button"
                        onClick={() => toggleActiveMutation.mutate({ id: a.id, active: !a.active })}
                        className="text-xs text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                      >
                        {a.active ? 'Désactiver' : 'Réactiver'}
                      </button>
                    )}
                    <button
                      type="button"
                      onClick={() => deleteMutation.mutate(a.id)}
                      className="text-xs text-red-600 underline hover:text-red-700 dark:text-red-400"
                    >
                      Supprimer
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </Table>
        </Card>
      )}
    </div>
  )
}
