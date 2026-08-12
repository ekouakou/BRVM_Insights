import { useId, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { callApi } from '../lib/apiClient'
import type { ChartAnalysisResult } from '../lib/types'
import { Button, ErrorState, Input, Select, StarRating } from './ui'
import { AnalysisHistoryList } from './AnalysisHistoryList'
import { IconButton, RetryIcon, TrashIcon } from './icons'
import { AI_MODELS } from '../lib/aiModels'
import { SuggestedChartRenderer } from './SuggestedChartRenderer'

/**
 * Bouton "Analyser avec l'IA" + panneau résultat + historique, réutilisable
 * sur n'importe quel graphe/tableau de comparaison — voir
 * TODO_CHART_AI_ANALYSIS.md pour la conception. Un seul composant plutôt
 * qu'une implémentation par graphe : brancher un nouveau graphe se résume à
 * poser ce composant avec le bon `chartType` (déjà enregistré côté backend
 * dans ChartAnalysisService::METHODOLOGY) + les `parameters`/`data` de la
 * sélection courante.
 *
 * L'historique affiché est filtré à `chartType` + `parameters` exacts (pas
 * une liste globale) — change de sélection et la liste se met à jour en
 * conséquence.
 */
export function ChartAiAnalysis({
  chartType,
  parameters,
  data,
  disabled,
  disabledReason,
  companyIdsForReports,
}: {
  chartType: string
  parameters: Record<string, unknown>
  data: unknown
  disabled?: boolean
  disabledReason?: string
  /**
   * IDs des entreprises actuellement sélectionnées, pour lesquelles on peut
   * proposer d'inclure leurs rapports financiers déjà traités comme contexte
   * additionnel de l'analyse IA (voir la case "Inclure les résultats
   * financiers" ci-dessous). Prop omise = fonctionnalité masquée (tous les
   * graphes n'ont pas de notion d'entreprise sélectionnée) ; tableau vide =
   * affichée mais désactivée (aucune entreprise à qui rattacher un rapport).
   */
  companyIdsForReports?: number[]
}) {
  const queryClient = useQueryClient()
  const [provider, setProvider] = useState<'gemini' | 'anthropic' | 'grok'>('gemini')
  const [model, setModel] = useState('')
  const [selectedResult, setSelectedResult] = useState<ChartAnalysisResult | null>(null)
  const [includeReportContext, setIncludeReportContext] = useState(false)

  const reportsAvailable = (companyIdsForReports ?? []).length > 0
  const applyReportContext = includeReportContext && reportsAvailable
  // La case à cocher doit changer le hash de requête côté backend (voir
  // ChartAnalysisService::hashRequest()) pour que le cache/historique
  // distinguent une analyse "avec rapports" d'une analyse "sans" — le plus
  // simple est de la traiter comme n'importe quel autre paramètre de
  // sélection plutôt que d'ajouter un mécanisme de cache séparé.
  const effectiveParameters = applyReportContext ? { ...parameters, include_report_context: true } : parameters

  const historyQuery = useQuery({
    queryKey: ['chart-analysis-history', chartType, effectiveParameters],
    queryFn: () => callApi<ChartAnalysisResult[]>('api_chart_analysis.php', 'history', { chart_type: chartType, parameters: effectiveParameters }),
  })

  const analyzeMutation = useMutation({
    mutationFn: (forceRefresh: boolean) =>
      callApi<ChartAnalysisResult>('api_chart_analysis.php', 'analyze', {
        chart_type: chartType,
        parameters: effectiveParameters,
        data,
        provider,
        model: model || undefined,
        force_refresh: forceRefresh,
      }),
    onSuccess: (result) => {
      setSelectedResult(result)
      queryClient.invalidateQueries({ queryKey: ['chart-analysis-history', chartType, effectiveParameters] })
    },
  })

  const rateMutation = useMutation({
    mutationFn: ({ id, rating }: { id: number; rating: number }) =>
      callApi<ChartAnalysisResult>('api_chart_analysis.php', 'rate', { id, rating }),
    onSuccess: (updated) => {
      queryClient.invalidateQueries({ queryKey: ['chart-analysis-history', chartType, effectiveParameters] })
      setSelectedResult((prev) => (prev && prev.id === updated.id ? updated : prev))
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => callApi<null>('api_chart_analysis.php', 'delete', { id }),
    onSuccess: (_data, id) => {
      queryClient.invalidateQueries({ queryKey: ['chart-analysis-history', chartType, effectiveParameters] })
      setSelectedResult((prev) => (prev && prev.id === id ? null : prev))
    },
  })

  const history = historyQuery.data ?? []
  const shown = selectedResult ?? analyzeMutation.data ?? null
  const modelListId = useId()

  return (
    <div className="mt-3 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
      <div className="flex flex-wrap items-center gap-3">
        <div className="w-40">
          <Select
            value={provider}
            onChange={(e) => {
              setProvider(e.target.value as 'gemini' | 'anthropic' | 'grok')
              setModel('')
            }}
          >
            <option value="gemini">Gemini</option>
            <option value="anthropic">Anthropic</option>
            <option value="grok">Grok</option>
          </Select>
        </div>
        <div className="w-56">
          <Input
            list={modelListId}
            value={model}
            onChange={(e) => setModel(e.target.value)}
            placeholder="Modèle (défaut du fournisseur)"
          />
          <datalist id={modelListId}>
            {AI_MODELS[provider].map((m) => (
              <option key={m.value} value={m.value}>{m.label}</option>
            ))}
          </datalist>
        </div>
        <Button
          onClick={() => {
            setSelectedResult(null)
            analyzeMutation.mutate(false)
          }}
          disabled={disabled || analyzeMutation.isPending}
        >
          {analyzeMutation.isPending ? 'Analyse…' : 'Analyser avec l’IA'}
        </Button>
        {shown && (
          <Button
            variant="secondary"
            onClick={() => {
              setSelectedResult(null)
              analyzeMutation.mutate(true)
            }}
            disabled={disabled || analyzeMutation.isPending}
          >
            <span className="flex items-center gap-1.5">
              <RetryIcon spinning={analyzeMutation.isPending} />
              Relancer (remplacer)
            </span>
          </Button>
        )}
        {disabled && disabledReason && (
          <span className="text-xs text-gray-500 dark:text-gray-400">{disabledReason}</span>
        )}
      </div>

      {companyIdsForReports !== undefined && (
        <label
          className={`mt-3 flex items-start gap-2 text-xs ${reportsAvailable ? 'text-gray-600 dark:text-gray-300' : 'text-gray-400 dark:text-gray-600'}`}
        >
          <input
            type="checkbox"
            className="mt-0.5"
            checked={includeReportContext}
            disabled={!reportsAvailable}
            onChange={(e) => setIncludeReportContext(e.target.checked)}
          />
          <span>
            Inclure les résultats financiers (rapports déjà traités, priorité au texte reformaté en Markdown sur le
            texte brut extrait) — l'IA croisera alors le mouvement de cours avec les fondamentaux publiés.
            {!reportsAvailable && ' Sélectionne au moins une entreprise ci-dessus pour activer cette option.'}
          </span>
        </label>
      )}

      {analyzeMutation.isError && (
        <div className="mt-3">
          <ErrorState message={(analyzeMutation.error as Error).message} />
        </div>
      )}

      {shown && shown.status === 'failed' && (
        <div className="mt-3">
          <ErrorState message={shown.error_message ?? "Échec de l'analyse"} />
        </div>
      )}

      {shown && shown.status === 'success' && (
        <div className="mt-4 flex flex-col gap-3 text-sm">
          {shown.period && (
            <p className="text-xs font-medium text-gray-700 underline-offset-2 dark:text-gray-200">
              Période analysée : {shown.period}
            </p>
          )}
          {shown.parameters.include_report_context === true && (
            <p className="text-xs font-medium text-emerald-600 dark:text-emerald-400">
              Inclut les résultats financiers (rapports déjà traités) en plus du mouvement de cours.
            </p>
          )}
          <div>
            <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
              Résumé
            </h4>
            <p className="text-gray-700 dark:text-gray-300">{shown.summary}</p>
          </div>

          {shown.methodology_explained && (
            <div>
              <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                Méthode de calcul
              </h4>
              <p className="text-gray-700 dark:text-gray-300">{shown.methodology_explained}</p>
            </div>
          )}

          {shown.key_observations.length > 0 && (
            <div>
              <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                Observations
              </h4>
              <ul className="list-disc pl-4 text-gray-700 dark:text-gray-300">
                {shown.key_observations.map((o, i) => (
                  <li key={i}>{o}</li>
                ))}
              </ul>
            </div>
          )}

          {shown.notable_points.length > 0 && (
            <div>
              <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                Points notables
              </h4>
              <ul className="list-disc pl-4 text-gray-700 dark:text-gray-300">
                {shown.notable_points.map((o, i) => (
                  <li key={i}>{o}</li>
                ))}
              </ul>
            </div>
          )}

          {shown.suggested_charts.length > 0 && (
            <div>
              <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                Graphes proposés par l'IA
              </h4>
              {shown.suggested_charts.map((chart, i) => (
                <SuggestedChartRenderer key={i} chart={chart} data={data} />
              ))}
            </div>
          )}

          {shown.suggested_table && (
            <div>
              <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                {shown.suggested_table.title}
              </h4>
              <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">{shown.suggested_table.description}</p>
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead>
                    <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                      {shown.suggested_table.columns.map((col) => (
                        <th key={col.key} className="pb-2 pr-3">{col.label}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {shown.suggested_table.rows.map((row, i) => (
                      <tr key={i} className="border-t border-gray-100 dark:border-gray-800">
                        {shown.suggested_table!.columns.map((col) => (
                          <td key={col.key} className="py-2 pr-3 text-gray-700 dark:text-gray-300">
                            {row[col.key] ?? '—'}
                          </td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          <div className="flex items-center justify-between gap-3">
            <p className="text-xs italic text-gray-400 dark:text-gray-500">
              {shown.provider}/{shown.model} — {shown.created_at}
              {shown.cached ? ' (depuis le cache)' : ''}
            </p>
            <div className="flex items-center gap-1">
              <StarRating value={shown.rating} onChange={(rating) => rateMutation.mutate({ id: shown.id, rating })} />
              <IconButton
                title="Supprimer cette analyse"
                tone="danger"
                onClick={() => {
                  if (window.confirm('Supprimer définitivement cette analyse ? Cette action est irréversible.')) {
                    deleteMutation.mutate(shown.id)
                  }
                }}
              >
                <TrashIcon />
              </IconButton>
            </div>
          </div>
          <p className="text-xs italic text-gray-400 dark:text-gray-500">{shown.disclaimer}</p>
        </div>
      )}

      <AnalysisHistoryList
        items={history}
        selectedId={shown?.id ?? null}
        onSelect={setSelectedResult}
        onRate={(id, rating) => rateMutation.mutate({ id, rating })}
        onDelete={(id) => deleteMutation.mutate(id)}
        preview={(h) => h.summary}
      />
    </div>
  )
}
