import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { callApi } from '../lib/apiClient'
import type { Company, FundamentalsRow, ReportAnalysis, ValuationModel } from '../lib/types'
import { Button, Card, ErrorState, InfoPanel, LoadingState, Modal, SearchableSelect } from '../components/ui'
import { FundamentalsDetailPanel } from '../components/FundamentalsDetailPanel'
import { FinancialsEditForm } from '../components/FinancialsEditForm'
import { EditIcon, IconButton, TrashIcon } from '../components/icons'

function fmt(n: number | null): string {
  return n === null ? '—' : n.toLocaleString('fr-FR', { maximumFractionDigits: 0 })
}

type FormTarget = { mode: 'new' } | { mode: 'edit'; row: FundamentalsRow }

/**
 * Écran dédié à la saisie manuelle des fondamentaux d'une entreprise, exercice
 * par exercice — distinct de la correction ponctuelle déjà possible depuis
 * les pages Rapports / Tableau de bord entreprise (qui exigent un rapport
 * déjà importé) : ici, sélectionner une entreprise suffit, même sans aucun
 * rapport traité (voir ReportAnalysisService::saveManualFinancials(), qui
 * crée alors un rapport synthétique servant d'ancrage). Les données saisies
 * alimentent exactement les mêmes ratios/graphes que la page Fondamentaux
 * (même composant FundamentalsDetailPanel réutilisé ci-dessous) et restent
 * modifiables à tout moment.
 */
export function ManualFinancials() {
  const queryClient = useQueryClient()
  const [companyId, setCompanyId] = useState<number | null>(null)
  const [formTarget, setFormTarget] = useState<FormTarget | null>(null)
  const [confirmDelete, setConfirmDelete] = useState<{ analysisId: number; label: string } | null>(null)

  const companiesQuery = useQuery({
    queryKey: ['companies-list'],
    queryFn: () => callApi<Company[]>('api_companies.php', 'list', { per_page: 200, active: 1 }),
  })

  // Tout l'historique (IA + manuel confondus) de l'entreprise sélectionnée — la
  // page Fondamentaux exclut les entreprises sans AUCUN rapport IA (voir
  // listFundamentals()) ; ici on veut au contraire pouvoir en saisir une pour
  // la toute première fois, donc l'action 'history' (pas de ce filtre).
  const historyQuery = useQuery({
    queryKey: ['fundamentals-history', companyId],
    queryFn: () => callApi<FundamentalsRow[]>('api_fundamentals.php', 'history', { company_id: companyId }),
    enabled: companyId !== null,
  })
  const history = historyQuery.data ?? []
  const latest = history[0] ?? null

  const valuationQuery = useQuery({
    queryKey: ['company-valuation', companyId, latest?.source_report_id],
    queryFn: () => callApi<ValuationModel>('api_valuation.php', 'compute', { company_id: companyId, fundamentals: latest }),
    enabled: companyId !== null && latest !== null,
  })

  // Pré-remplissage du formulaire "Modifier" — chiffres bruts de l'analyse exacte visée, pas juste "la dernière".
  const editTarget = formTarget?.mode === 'edit' ? formTarget.row : null
  const editPrefillQuery = useQuery({
    queryKey: ['report-analysis-exact', editTarget?.source_report_id, editTarget?.source_provider, editTarget?.source_model],
    queryFn: () =>
      callApi<ReportAnalysis | null>('api_report_analysis.php', 'get', {
        report_id: editTarget?.source_report_id,
        provider: editTarget?.source_provider,
        model: editTarget?.source_model,
      }),
    enabled: editTarget !== null,
  })

  const deleteMutation = useMutation({
    mutationFn: (analysisId: number) => callApi('api_report_analysis.php', 'delete', { id: analysisId }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['fundamentals-history', companyId] })
      queryClient.invalidateQueries({ queryKey: ['fundamentals-list'] })
      setConfirmDelete(null)
    },
  })

  function refreshAfterSave() {
    queryClient.invalidateQueries({ queryKey: ['fundamentals-history', companyId] })
    queryClient.invalidateQueries({ queryKey: ['fundamentals-list'] })
    setFormTarget(null)
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Saisie manuelle</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Renseigne toi-même les données financières d'une entreprise, exercice par exercice.
        </p>
      </div>

      <InfoPanel>
        <p>
          Contrairement au bouton "Modifier" des pages Rapports / Tableau de bord entreprise (qui corrige un
          rapport déjà importé), cet écran fonctionne <strong>même sans aucun rapport traité</strong> pour
          l'entreprise choisie : un rapport "ancre" est créé automatiquement en arrière-plan, sans PDF, invisible
          sur la page Rapports. Les chiffres saisis alimentent exactement les mêmes ratios et graphes que la page
          Fondamentaux, et restent modifiables ou supprimables à tout moment ci-dessous.
        </p>
      </InfoPanel>

      <Card>
        <label className="block max-w-md">
          <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Entreprise</span>
          <SearchableSelect
            value={companyId !== null ? String(companyId) : ''}
            onChange={(v) => setCompanyId(v ? Number(v) : null)}
            options={(companiesQuery.data ?? []).map((c) => ({ value: String(c.company_id), label: `${c.symbol} — ${c.name}` }))}
            placeholder="— Choisir une entreprise —"
          />
        </label>
      </Card>

      {companyId !== null && (
        <>
          {historyQuery.isLoading && <LoadingState label="Chargement de l'historique…" />}
          {historyQuery.error && <ErrorState message={(historyQuery.error as Error).message} />}

          {historyQuery.data && (
            <Card>
              <div className="mb-3 flex items-center justify-between gap-3">
                <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">Exercices renseignés ({history.length})</h3>
                <Button onClick={() => setFormTarget({ mode: 'new' })}>+ Nouvelle saisie</Button>
              </div>

              {history.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">Aucune donnée pour cette entreprise pour l'instant.</p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-left text-sm">
                    <thead>
                      <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                        <th className="pb-2 pr-3">Rapport</th>
                        <th className="pb-2 pr-3">Type</th>
                        <th className="pb-2 pr-3">Source</th>
                        <th className="pb-2 pr-3 text-right">CA</th>
                        <th className="pb-2 pr-3 text-right">Résultat net</th>
                        <th className="pb-2"></th>
                      </tr>
                    </thead>
                    <tbody>
                      {history.map((row) => (
                        <tr key={row.source_analysis_id} className="border-t border-gray-100 dark:border-gray-800">
                          <td className="py-2 pr-3" title={row.source_report_title}>
                            {row.source_publish_date ?? '—'}
                          </td>
                          <td className="py-2 pr-3 text-gray-500 dark:text-gray-400">{row.source_report_type}</td>
                          <td className="py-2 pr-3">
                            <span
                              className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                                row.source_provider === 'manuel'
                                  ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300'
                                  : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'
                              }`}
                            >
                              {row.source_provider === 'manuel' ? 'Manuel' : `IA — ${row.source_provider}`}
                            </span>
                          </td>
                          <td className="py-2 pr-3 text-right tabular-nums">{fmt(row.revenue)}</td>
                          <td className="py-2 pr-3 text-right tabular-nums">{fmt(row.net_income)}</td>
                          <td className="py-2">
                            <div className="flex items-center gap-1">
                              <IconButton title="Modifier" onClick={() => setFormTarget({ mode: 'edit', row })}>
                                <EditIcon />
                              </IconButton>
                              {row.source_provider === 'manuel' && row.source_analysis_id !== null && (
                                <IconButton
                                  title="Supprimer cette saisie"
                                  tone="danger"
                                  onClick={() => setConfirmDelete({ analysisId: row.source_analysis_id!, label: row.source_report_title })}
                                >
                                  <TrashIcon />
                                </IconButton>
                              )}
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Card>
          )}

          {latest && <FundamentalsDetailPanel row={latest} valuationQuery={valuationQuery} />}
        </>
      )}

      {confirmDelete && (
        <Modal title="Supprimer cette saisie ?" onClose={() => setConfirmDelete(null)}>
          <p className="mb-4 text-sm text-gray-600 dark:text-gray-300">
            « {confirmDelete.label} » sera définitivement supprimée. Cette action est irréversible.
          </p>
          {deleteMutation.error && <ErrorState message={(deleteMutation.error as Error).message} />}
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setConfirmDelete(null)}>
              Annuler
            </Button>
            <Button onClick={() => deleteMutation.mutate(confirmDelete.analysisId)} disabled={deleteMutation.isPending}>
              {deleteMutation.isPending ? 'Suppression…' : 'Supprimer'}
            </Button>
          </div>
        </Modal>
      )}

      {formTarget?.mode === 'new' && companyId !== null && (
        <FinancialsEditForm
          reportId={null}
          companyId={companyId}
          initialKeyFinancials={null}
          initialValuationAssessment={null}
          onClose={() => setFormTarget(null)}
          onSaved={refreshAfterSave}
        />
      )}

      {formTarget?.mode === 'edit' && editTarget && (
        editPrefillQuery.isLoading ? (
          <Modal title="Données financières" onClose={() => setFormTarget(null)}>
            <LoadingState label="Chargement de l'analyse à corriger…" />
          </Modal>
        ) : (
          <FinancialsEditForm
            reportId={editTarget.source_report_id}
            reportTitle={editTarget.source_report_title}
            initialKeyFinancials={editPrefillQuery.data?.analysis?.key_financials ?? null}
            initialValuationAssessment={editPrefillQuery.data?.analysis?.valuation_assessment ?? null}
            onClose={() => setFormTarget(null)}
            onSaved={refreshAfterSave}
          />
        )
      )}
    </div>
  )
}
