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
}: {
  chartType: string
  parameters: Record<string, unknown>
  data: unknown
  disabled?: boolean
  disabledReason?: string
}) {
  const queryClient = useQueryClient()
  const [provider, setProvider] = useState<'gemini' | 'anthropic' | 'grok'>('gemini')
  const [model, setModel] = useState('')
  const [selectedResult, setSelectedResult] = useState<ChartAnalysisResult | null>(null)

  const historyQuery = useQuery({
    queryKey: ['chart-analysis-history', chartType, parameters],
    queryFn: () => callApi<ChartAnalysisResult[]>('api_chart_analysis.php', 'history', { chart_type: chartType, parameters }),
  })

  const analyzeMutation = useMutation({
    mutationFn: (forceRefresh: boolean) =>
      callApi<ChartAnalysisResult>('api_chart_analysis.php', 'analyze', {
        chart_type: chartType,
        parameters,
        data,
        provider,
        model: model || undefined,
        force_refresh: forceRefresh,
      }),
    onSuccess: (result) => {
      setSelectedResult(result)
      queryClient.invalidateQueries({ queryKey: ['chart-analysis-history', chartType, parameters] })
    },
  })

  const rateMutation = useMutation({
    mutationFn: ({ id, rating }: { id: number; rating: number }) =>
      callApi<ChartAnalysisResult>('api_chart_analysis.php', 'rate', { id, rating }),
    onSuccess: (updated) => {
      queryClient.invalidateQueries({ queryKey: ['chart-analysis-history', chartType, parameters] })
      setSelectedResult((prev) => (prev && prev.id === updated.id ? updated : prev))
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => callApi<null>('api_chart_analysis.php', 'delete', { id }),
    onSuccess: (_data, id) => {
      queryClient.invalidateQueries({ queryKey: ['chart-analysis-history', chartType, parameters] })
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
