import { useId, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { LineChart, Line, ResponsiveContainer, XAxis, YAxis, Tooltip, Legend, CartesianGrid } from 'recharts'
import { callApi } from '../lib/apiClient'
import type { BulletinSummary, CombinedAnalysisResult, Company, ReportSummary } from '../lib/types'
import { Button, Card, ErrorState, Input, LoadingState, MarkdownBadge, Modal, Select, StarRating } from '../components/ui'
import { BoltIcon, IconButton, TrashIcon } from '../components/icons'
import { AnalysisHistoryList } from '../components/AnalysisHistoryList'
import { AI_MODELS, type AiProvider } from '../lib/aiModels'

const INDEX_COLORS: Record<string, string> = {
  'BRVM-COMPOSITE': '#4f46e5',
  'BRVM-30': '#0ea5e9',
  'BRVM-PRESTIGE': '#f59e0b',
  'BRVM-PRINCIPAL': '#10b981',
}
const FALLBACK_COLORS = ['#4f46e5', '#0ea5e9', '#f59e0b', '#10b981', '#ec4899', '#8b5cf6']

/**
 * Rendu du résultat d'une analyse combinée — extrait en composant pour
 * l'instancier deux fois côte à côte en mode "Comparer 2 fournisseurs".
 */
function CombinedResultView({
  result,
  onRate,
  onDelete,
}: {
  result: CombinedAnalysisResult
  onRate?: (id: number, rating: number) => void
  onDelete?: (id: number) => void
}) {
  if (result.status === 'failed') {
    return <ErrorState message={result.error_message ?? "Échec de l'analyse combinée"} />
  }
  if (!result.analysis) return null

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
        <span>
          {result.reports.length} rapport(s), {result.bulletins.length} bulletin(s)
        </span>
        <span className="flex items-center gap-2">
          {result.provider}/{result.model} {result.cached && '· depuis le cache'}
          {onRate && <StarRating value={result.rating} onChange={(rating) => onRate(result.id, rating)} />}
          {onDelete && (
            <IconButton
              title="Supprimer cette analyse"
              tone="danger"
              onClick={() => {
                if (window.confirm('Supprimer définitivement cette analyse ? Cette action est irréversible.')) {
                  onDelete(result.id)
                }
              }}
            >
              <TrashIcon />
            </IconButton>
          )}
        </span>
      </div>

      <div>
        <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Vue d'ensemble</h3>
        <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{result.analysis.combined_overview}</p>
      </div>

      {result.analysis.key_takeaways.length > 0 && (
        <div className="rounded-md border border-gray-300 bg-gray-100 p-3 dark:border-gray-700 dark:bg-gray-800/60">
          <h3 className="mb-1 text-sm font-semibold text-gray-800 dark:text-gray-200">Points clés pour la décision</h3>
          <ul className="list-disc pl-4 text-sm text-gray-800 dark:text-gray-200">
            {result.analysis.key_takeaways.map((k, i) => <li key={i}>{k}</li>)}
          </ul>
        </div>
      )}

      {result.analysis.company_performance_notes.length > 0 && (
        <Card title="Performance par entreprise">
          <div className="flex flex-col gap-3">
            {result.analysis.company_performance_notes.map((n) => (
              <div key={n.company_symbol}>
                <div className="text-sm font-semibold">{n.company_symbol} — {n.company_name}</div>
                <p className="text-sm text-gray-700 dark:text-gray-300">{n.narrative}</p>
              </div>
            ))}
          </div>
        </Card>
      )}

      {result.chart_data && result.chart_data.index_series.length > 0 && (
        <Card title="Évolution des indices">
          <ResponsiveContainer width="100%" height={220}>
            <LineChart>
              <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
              <XAxis dataKey="date" type="category" allowDuplicatedCategory={false} tick={{ fontSize: 11 }} />
              <YAxis tick={{ fontSize: 11 }} width={70} />
              <Tooltip />
              <Legend />
              {result.chart_data.index_series.map((serie, i) => (
                <Line
                  key={serie.code}
                  data={serie.data}
                  dataKey="close_value"
                  name={serie.name}
                  stroke={INDEX_COLORS[serie.code] ?? FALLBACK_COLORS[i % FALLBACK_COLORS.length]}
                  dot
                  strokeWidth={2}
                />
              ))}
            </LineChart>
          </ResponsiveContainer>
        </Card>
      )}

      {result.chart_data && result.chart_data.price_series.some((s) => s.data.length >= 2) && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          {result.chart_data.price_series.filter((s) => s.data.length >= 2).map((serie) => (
            <Card key={serie.company_id} title={`Cours — ${serie.symbol}`}>
              <ResponsiveContainer width="100%" height={180}>
                <LineChart data={serie.data}>
                  <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                  <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                  <YAxis domain={['auto', 'auto']} tick={{ fontSize: 11 }} width={70} />
                  <Tooltip />
                  <Line type="monotone" dataKey="close" stroke="var(--chart-1)" dot={false} strokeWidth={2} />
                </LineChart>
              </ResponsiveContainer>
            </Card>
          ))}
        </div>
      )}

      <div>
        <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Contexte de marché</h3>
        <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{result.analysis.market_context_summary}</p>
      </div>

      <div>
        <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Corrélation entreprise / marché</h3>
        <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{result.analysis.correlation_analysis}</p>
      </div>

      <div>
        <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Chronologie</h3>
        <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{result.analysis.timeline_narrative}</p>
      </div>

      {result.analysis.decision_support_notes.length > 0 && (
        <Card title="Points d'appui à la décision">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {result.analysis.decision_support_notes.map((d) => (
              <div key={d.company_symbol} className="rounded-md border border-gray-200 p-3 dark:border-gray-800">
                <div className="mb-1 text-sm font-semibold">{d.company_symbol}</div>
                <div className="mb-1 text-sm"><span className="font-medium text-emerald-600 dark:text-emerald-400">Bull:</span> {d.bull_case}</div>
                <div className="mb-1 text-sm"><span className="font-medium text-red-600 dark:text-red-400">Bear:</span> {d.bear_case}</div>
                <ul className="mt-1 list-disc pl-4 text-xs text-gray-600 dark:text-gray-400">
                  {d.key_watch_points.map((p, i) => <li key={i}>{p}</li>)}
                </ul>
              </div>
            ))}
          </div>
        </Card>
      )}

      {result.analysis.glossary.length > 0 && (
        <div>
          <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Glossaire</h3>
          <dl className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            {result.analysis.glossary.map((g, i) => (
              <div key={i}><dt className="font-medium">{g.term}</dt><dd className="text-gray-600 dark:text-gray-400">{g.explanation}</dd></div>
            ))}
          </dl>
        </div>
      )}

      {(result.skipped_reports.length > 0 || result.skipped_bulletins.length > 0) && (
        <p className="text-xs text-amber-600 dark:text-amber-400">
          {result.skipped_reports.length} rapport(s) et {result.skipped_bulletins.length} bulletin(s) n'ont pas pu être inclus (analyse individuelle en échec).
        </p>
      )}

      <p className="text-xs italic text-gray-400">{result.disclaimer}</p>
    </div>
  )
}

export function Combined() {
  const queryClient = useQueryClient()
  const [companyId, setCompanyId] = useState<number | null>(null)
  const [selectedReports, setSelectedReports] = useState<{ id: number; label: string }[]>([])
  const [selectedBulletinIds, setSelectedBulletinIds] = useState<number[]>([])
  const [showResult, setShowResult] = useState(false)
  const [historyOverride, setHistoryOverride] = useState<CombinedAnalysisResult | null>(null)
  const [compareMode, setCompareMode] = useState(false)
  const [compareLeftId, setCompareLeftId] = useState<number | null>(null)
  const [compareRightId, setCompareRightId] = useState<number | null>(null)
  const [provider, setProvider] = useState<AiProvider>('gemini')
  const [model, setModel] = useState('')
  const modelListId = useId()

  const companiesQuery = useQuery({
    queryKey: ['companies-list'],
    queryFn: () => callApi<Company[]>('api_companies.php', 'list', { per_page: 200, active: 1 }),
  })

  const selectedCompany = companiesQuery.data?.find((c) => c.company_id === companyId)

  const reportsQuery = useQuery({
    queryKey: ['reports-for-company', selectedCompany?.symbol],
    queryFn: () => callApi<ReportSummary[]>('api_reports.php', 'list', { symbol: selectedCompany?.symbol }),
    enabled: !!selectedCompany,
  })

  const bulletinsQuery = useQuery({
    queryKey: ['bulletins-list'],
    queryFn: () => callApi<BulletinSummary[]>('api_bulletins.php', 'list'),
  })

  const reportIds = useMemo(() => selectedReports.map((r) => r.id), [selectedReports])

  const compareMutation = useMutation({
    mutationFn: (forceRefresh: boolean) =>
      callApi<CombinedAnalysisResult>('api_combined_analysis.php', 'compare', {
        report_ids: reportIds,
        bulletin_ids: selectedBulletinIds,
        provider,
        model: model || undefined,
        force_refresh: forceRefresh,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['combined-analysis-history', reportIds, selectedBulletinIds] })
    },
  })

  const historyQuery = useQuery({
    queryKey: ['combined-analysis-history', reportIds, selectedBulletinIds],
    queryFn: () =>
      callApi<CombinedAnalysisResult[]>('api_combined_analysis.php', 'history', {
        report_ids: reportIds,
        bulletin_ids: selectedBulletinIds,
      }),
    enabled: reportIds.length > 0 && selectedBulletinIds.length > 0,
  })

  const rateMutation = useMutation({
    mutationFn: ({ id, rating }: { id: number; rating: number }) =>
      callApi<CombinedAnalysisResult>('api_combined_analysis.php', 'rate', { id, rating }),
    onSuccess: (updated) => {
      queryClient.invalidateQueries({ queryKey: ['combined-analysis-history', reportIds, selectedBulletinIds] })
      setHistoryOverride((prev) => (prev && prev.id === updated.id ? updated : prev))
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => callApi<null>('api_combined_analysis.php', 'delete', { id }),
    onSuccess: (_data, id) => {
      queryClient.invalidateQueries({ queryKey: ['combined-analysis-history', reportIds, selectedBulletinIds] })
      setHistoryOverride((prev) => (prev && prev.id === id ? null : prev))
      if (compareMutation.data?.id === id) compareMutation.reset()
      if (compareLeftId === id) setCompareLeftId(null)
      if (compareRightId === id) setCompareRightId(null)
    },
  })

  const result = historyOverride ?? compareMutation.data
  const history = historyQuery.data ?? []
  const compareLeft = history.find((h) => h.id === compareLeftId) ?? null
  const compareRight = history.find((h) => h.id === compareRightId) ?? null

  const selectedReportIds = useMemo(() => new Set(selectedReports.map((r) => r.id)), [selectedReports])

  function toggleReport(report: ReportSummary) {
    if (!selectedCompany) return
    setSelectedReports((prev) => {
      if (prev.some((r) => r.id === report.id)) {
        return prev.filter((r) => r.id !== report.id)
      }
      const label = `${selectedCompany.symbol} — ${report.report_type} (${report.publish_date ?? '?'})`
      return [...prev, { id: report.id, label }]
    })
  }

  function toggleBulletin(id: number) {
    setSelectedBulletinIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  // Seuls les rapports/bulletins déjà mis en forme en markdown sont utilisables pour
  // l'analyse IA combinée (voir demande utilisateur) — les autres restent visibles
  // mais grisés/non sélectionnables plutôt que masqués.
  const eligibleReports = (reportsQuery.data ?? []).filter((r) => r.text_extracted)
  const selectableReports = eligibleReports.filter((r) => r.markdown_status === 'success')
  const allReportsSelected = selectableReports.length > 0 && selectableReports.every((r) => selectedReportIds.has(r.id))

  function toggleAllReports() {
    if (!selectedCompany) return
    if (allReportsSelected) {
      const idsToRemove = new Set(selectableReports.map((r) => r.id))
      setSelectedReports((prev) => prev.filter((r) => !idsToRemove.has(r.id)))
    } else {
      setSelectedReports((prev) => {
        const existingIds = new Set(prev.map((r) => r.id))
        const additions = selectableReports
          .filter((r) => !existingIds.has(r.id))
          .map((r) => ({ id: r.id, label: `${selectedCompany.symbol} — ${r.report_type} (${r.publish_date ?? '?'})` }))
        return [...prev, ...additions]
      })
    }
  }

  const selectableBulletinIds = useMemo(
    () => (bulletinsQuery.data ?? []).filter((b) => b.text_extracted && b.markdown_status === 'success').map((b) => b.id),
    [bulletinsQuery.data]
  )
  const allBulletinsSelected = selectableBulletinIds.length > 0 && selectableBulletinIds.every((id) => selectedBulletinIds.includes(id))

  function toggleAllBulletins() {
    if (allBulletinsSelected) {
      const idsToRemove = new Set(selectableBulletinIds)
      setSelectedBulletinIds((prev) => prev.filter((id) => !idsToRemove.has(id)))
    } else {
      setSelectedBulletinIds((prev) => {
        const existing = new Set(prev)
        const additions = selectableBulletinIds.filter((id) => !existing.has(id))
        return [...prev, ...additions]
      })
    }
  }

  function openAnalysis() {
    setShowResult(true)
    setHistoryOverride(null)
    compareMutation.mutate(false)
  }

  const bulletins = (bulletinsQuery.data ?? []).filter((b) => b.text_extracted)

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Analyse combinée</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Croise librement des rapports de sociétés et des bulletins de marché dans une même analyse IA
        </p>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card title="Rapports sélectionnés">
          <div className="flex flex-col gap-3">
            <label>
              <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Entreprise</span>
              <Select value={companyId ?? ''} onChange={(e) => setCompanyId(e.target.value ? Number(e.target.value) : null)}>
                <option value="">— Choisir —</option>
                {(companiesQuery.data ?? []).map((c) => (
                  <option key={c.company_id} value={c.company_id}>{c.symbol} — {c.name}</option>
                ))}
              </Select>
            </label>

            {reportsQuery.isLoading && <LoadingState />}
            {reportsQuery.data && (
              <div className="flex max-h-52 flex-col gap-1 overflow-y-auto rounded-md border border-gray-200 p-2 dark:border-gray-800">
                {eligibleReports.length === 0 && (
                  <p className="p-2 text-xs text-gray-500 dark:text-gray-400">Aucun rapport avec texte extrait pour cette entreprise.</p>
                )}
                {eligibleReports.length > 0 && (
                  <label className="flex cursor-pointer items-center gap-2 rounded border-b border-gray-100 px-2 py-1 text-xs font-medium text-gray-500 dark:border-gray-800 dark:text-gray-400">
                    <input type="checkbox" checked={allReportsSelected} onChange={toggleAllReports} disabled={selectableReports.length === 0} />
                    Tout sélectionner / tout désélectionner ({selectableReports.length} avec markdown)
                  </label>
                )}
                {eligibleReports.map((r) => {
                  const hasMarkdown = r.markdown_status === 'success'
                  return (
                    <label
                      key={r.id}
                      className={`flex items-center gap-2 rounded px-2 py-1 text-sm ${
                        hasMarkdown ? 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800' : 'cursor-not-allowed text-gray-400 dark:text-gray-600'
                      }`}
                      title={hasMarkdown ? undefined : "Pas encore de markdown généré pour ce rapport — indisponible pour l'analyse combinée"}
                    >
                      <input type="checkbox" checked={selectedReportIds.has(r.id)} onChange={() => toggleReport(r)} disabled={!hasMarkdown} />
                      <span className="truncate">{r.report_type} — {r.publish_date ?? '?'} — {r.title}</span>
                    </label>
                  )
                })}
              </div>
            )}

            <div>
              <span className="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">
                Rapports retenus ({selectedReports.length})
              </span>
              <div className="flex flex-wrap gap-1">
                {selectedReports.map((r) => (
                  <span key={r.id} className="inline-flex items-center gap-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                    {r.label}
                    <button type="button" onClick={() => setSelectedReports((prev) => prev.filter((x) => x.id !== r.id))} className="font-bold">×</button>
                  </span>
                ))}
              </div>
            </div>
          </div>
        </Card>

        <Card title="Bulletins sélectionnés">
          {bulletinsQuery.isLoading && <LoadingState />}
          {bulletinsQuery.data && (
            <div className="flex max-h-72 flex-col gap-1 overflow-y-auto rounded-md border border-gray-200 p-2 dark:border-gray-800">
              {bulletins.length === 0 && (
                <p className="p-2 text-xs text-gray-500 dark:text-gray-400">Aucun bulletin avec texte extrait.</p>
              )}
              {bulletins.length > 0 && (
                <label className="flex cursor-pointer items-center gap-2 rounded border-b border-gray-100 px-2 py-1 text-xs font-medium text-gray-500 dark:border-gray-800 dark:text-gray-400">
                  <input type="checkbox" checked={allBulletinsSelected} onChange={toggleAllBulletins} disabled={selectableBulletinIds.length === 0} />
                  Tout sélectionner / tout désélectionner ({selectableBulletinIds.length} avec markdown)
                </label>
              )}
              {bulletins.map((b) => {
                const hasMarkdown = b.markdown_status === 'success'
                return (
                  <label
                    key={b.id}
                    className={`flex items-center gap-2 rounded px-2 py-1 text-sm ${
                      hasMarkdown ? 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800' : 'cursor-not-allowed text-gray-400 dark:text-gray-600'
                    }`}
                    title={hasMarkdown ? undefined : "Pas encore de markdown généré pour ce bulletin — indisponible pour l'analyse combinée"}
                  >
                    <input type="checkbox" checked={selectedBulletinIds.includes(b.id)} onChange={() => toggleBulletin(b.id)} disabled={!hasMarkdown} />
                    <span className="truncate">{b.publish_date} — {b.title}</span>
                  </label>
                )
              })}
            </div>
          )}
          <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">{selectedBulletinIds.length} bulletin(s) retenu(s)</p>
        </Card>
      </div>

      <div>
        <div className="mb-3 flex flex-wrap items-end gap-3">
          <label className="w-40">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Fournisseur</span>
            <Select value={provider} onChange={(e) => { setProvider(e.target.value as AiProvider); setModel('') }}>
              <option value="gemini">Gemini</option>
              <option value="anthropic">Anthropic</option>
              <option value="grok">Grok</option>
            </Select>
          </label>
          <label className="w-56">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Modèle (optionnel)</span>
            <Input list={modelListId} value={model} onChange={(e) => setModel(e.target.value)} placeholder="défaut du fournisseur" />
            <datalist id={modelListId}>
              {AI_MODELS[provider].map((m) => (
                <option key={m.value} value={m.value}>{m.label}</option>
              ))}
            </datalist>
          </label>
          <Button onClick={openAnalysis} disabled={selectedReports.length === 0 || selectedBulletinIds.length === 0}>
            <span className="flex items-center gap-2">
              <BoltIcon />
              {`Analyser conjointement (${selectedReports.length} rapport(s), ${selectedBulletinIds.length} bulletin(s))`}
            </span>
          </Button>
        </div>

        <AnalysisHistoryList
          items={history}
          selectedId={result?.id ?? null}
          onSelect={(h) => { setHistoryOverride(h); setShowResult(true) }}
          onRate={(id, rating) => rateMutation.mutate({ id, rating })}
          onDelete={(id) => deleteMutation.mutate(id)}
          preview={(h) => h.analysis?.combined_overview}
          title="Historique des analyses pour cette sélection"
        />

        {history.length >= 2 && (
          <div className="mt-3">
            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
              <input
                type="checkbox"
                checked={compareMode}
                onChange={(e) => {
                  setCompareMode(e.target.checked)
                  if (e.target.checked) {
                    setCompareLeftId((prev) => prev ?? history[0].id)
                    setCompareRightId((prev) => prev ?? history[1].id)
                    setShowResult(true)
                  }
                }}
              />
              Comparer 2 analyses de l'historique côte à côte
            </label>

            {compareMode && (
              <div className="mt-2 flex flex-wrap gap-4">
                <label className="min-w-[220px] flex-1">
                  <span className="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Analyse A</span>
                  <Select value={compareLeftId ?? ''} onChange={(e) => setCompareLeftId(e.target.value ? Number(e.target.value) : null)}>
                    {history.map((h) => (
                      <option key={h.id} value={h.id}>{h.created_at} — {h.provider}/{h.model}</option>
                    ))}
                  </Select>
                </label>
                <label className="min-w-[220px] flex-1">
                  <span className="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Analyse B</span>
                  <Select value={compareRightId ?? ''} onChange={(e) => setCompareRightId(e.target.value ? Number(e.target.value) : null)}>
                    {history.map((h) => (
                      <option key={h.id} value={h.id}>{h.created_at} — {h.provider}/{h.model}</option>
                    ))}
                  </Select>
                </label>
              </div>
            )}
          </div>
        )}
      </div>

      {showResult && (
        <Modal title="Analyse combinée" onClose={() => setShowResult(false)}>
          {compareMutation.isPending && <LoadingState label="Analyse combinée en cours (peut prendre du temps si des éléments n'ont pas encore été analysés individuellement)…" />}
          {compareMutation.isError && <ErrorState message={(compareMutation.error as Error).message} />}

          {compareMode ? (
            <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
              <div>{compareLeft ? <CombinedResultView result={compareLeft} onRate={(id, rating) => rateMutation.mutate({ id, rating })} onDelete={(id) => deleteMutation.mutate(id)} /> : <p className="text-sm text-gray-400">Choisis une analyse A.</p>}</div>
              <div>{compareRight ? <CombinedResultView result={compareRight} onRate={(id, rating) => rateMutation.mutate({ id, rating })} onDelete={(id) => deleteMutation.mutate(id)} /> : <p className="text-sm text-gray-400">Choisis une analyse B.</p>}</div>
            </div>
          ) : (
            result && (
              <div className="flex flex-col gap-4">
                <div className="flex justify-end">
                  <Button variant="secondary" onClick={() => { setHistoryOverride(null); compareMutation.mutate(true) }} disabled={compareMutation.isPending}>
                    Forcer un nouvel appel
                  </Button>
                </div>
                <CombinedResultView result={result} onRate={(id, rating) => rateMutation.mutate({ id, rating })} onDelete={(id) => deleteMutation.mutate(id)} />
              </div>
            )
          )}
        </Modal>
      )}
    </div>
  )
}
