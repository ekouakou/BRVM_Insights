import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { callApi, dbDumpDownloadUrl } from '../lib/apiClient'
import type { SyncLog } from '../lib/types'
import { Button, Card, ErrorState, LoadingState, StatTile, Table } from '../components/ui'

interface MarketStatus {
  is_open: boolean
  current_time: string
  current_day: string
  market_open_time: string
  market_close_time: string
  next_sync: string
}

interface SyncNowResult {
  success: boolean
  message: string
  quotes: { processed?: number; inserted?: number; updated?: number; failed?: number; error?: string } | null
  indices: { processed?: number; inserted?: number; updated?: number; failed?: number; error?: string } | null
  execution_time: number
}

export function Sync() {
  const queryClient = useQueryClient()

  const statusQuery = useQuery({
    queryKey: ['market-status'],
    queryFn: () => callApi<{ market_status: MarketStatus }>('api_brvm_sync.php', 'check_market_status'),
  })

  const historyQuery = useQuery({
    queryKey: ['sync-history'],
    queryFn: () => callApi<SyncLog[]>('api_brvm_sync.php', 'sync_history', { page: 1, per_page: 15 }),
  })

  const syncMutation = useMutation({
    mutationFn: () => callApi<SyncNowResult>('api_brvm_sync.php', 'sync_now'),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sync-history'] })
      queryClient.invalidateQueries({ queryKey: ['market-status'] })
    },
  })

  const status = statusQuery.data?.market_status

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-semibold">Synchronisation</h2>
          <p className="text-sm text-gray-500 dark:text-gray-400">Cotations et indices BRVM</p>
        </div>
        <Button onClick={() => syncMutation.mutate()} disabled={syncMutation.isPending}>
          {syncMutation.isPending ? 'Synchronisation…' : 'Synchroniser maintenant'}
        </Button>
      </div>

      {status && (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <StatTile label="Marché" value={status.is_open ? 'Ouvert' : 'Fermé'} tone={status.is_open ? 'positive' : 'default'} />
          <StatTile label="Heure locale" value={status.current_time} />
          <StatTile label="Horaires marché" value={`${status.market_open_time} – ${status.market_close_time}`} />
          <StatTile label="Prochaine synchro prévue" value={status.next_sync} />
        </div>
      )}

      {syncMutation.isError && <ErrorState message={(syncMutation.error as Error).message} />}

      {syncMutation.data && (
        <Card title="Résultat de la dernière synchronisation manuelle">
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <StatTile label="Cotations traitées" value={syncMutation.data.quotes?.processed ?? '—'} />
            <StatTile label="Cotations mises à jour" value={syncMutation.data.quotes?.updated ?? '—'} />
            <StatTile label="Indices traités" value={syncMutation.data.indices?.processed ?? '—'} />
            <StatTile label="Durée" value={`${syncMutation.data.execution_time}s`} />
          </div>
        </Card>
      )}

      <Card title="Sauvegarde / export de la base">
        <p className="mb-3 text-sm text-gray-600 dark:text-gray-400">
          Télécharge un dump SQL complet (schéma + données, tables et vues), compressé en .sql.gz — utile pour
          continuer le développement en local avec des données réelles, ou avant une opération risquée. Décompresse
          le fichier (<code>gunzip</code>, 7-Zip, The Unarchiver…) avant de l'importer. Voir{' '}
          <code>RESET_DATABASE.md</code> pour la procédure complète. Peut prendre un moment sur une base volumineuse.
        </p>
        <a
          href={dbDumpDownloadUrl()}
          className="inline-flex w-fit items-center gap-2 rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
        >
          Télécharger un dump de la base (.sql.gz)
        </a>
      </Card>

      <Card title="Historique des synchronisations">
        {historyQuery.isLoading && <LoadingState />}
        {historyQuery.error && <ErrorState message={(historyQuery.error as Error).message} />}
        {historyQuery.data && (
          <Table headers={['Type', 'Statut', 'Traités', 'Insérés', 'Mis à jour', 'Échoués', 'Démarré', 'Terminé']}>
            {historyQuery.data.map((log) => (
              <tr key={log.id}>
                <td className="px-3 py-2">{log.sync_type}</td>
                <td className="px-3 py-2">
                  <span
                    className={
                      log.sync_status === 'success'
                        ? 'text-emerald-600 dark:text-emerald-400'
                        : log.sync_status === 'partial'
                          ? 'text-amber-600 dark:text-amber-400'
                          : 'text-red-600 dark:text-red-400'
                    }
                  >
                    {log.sync_status}
                  </span>
                </td>
                <td className="px-3 py-2">{log.records_processed}</td>
                <td className="px-3 py-2">{log.records_inserted}</td>
                <td className="px-3 py-2">{log.records_updated}</td>
                <td className="px-3 py-2">{log.records_failed}</td>
                <td className="px-3 py-2 whitespace-nowrap">{log.started_at}</td>
                <td className="px-3 py-2 whitespace-nowrap">{log.completed_at}</td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
    </div>
  )
}
