import { useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { LineChart, Line, ResponsiveContainer, XAxis, YAxis, Tooltip, Legend, CartesianGrid } from 'recharts'
import { callApi } from '../lib/apiClient'
import type { BulletinSummary, CombinedAnalysisResult, Company, ReportSummary } from '../lib/types'
import { Button, Card, ErrorState, LoadingState, MarkdownBadge, Modal, Select } from '../components/ui'
import { BoltIcon } from '../components/icons'

const INDEX_COLORS: Record<string, string> = {
  'BRVM-COMPOSITE': '#4f46e5',
  'BRVM-30': '#0ea5e9',
  'BRVM-PRESTIGE': '#f59e0b',
  'BRVM-PRINCIPAL': '#10b981',
}
const FALLBACK_COLORS = ['#4f46e5', '#0ea5e9', '#f59e0b', '#10b981', '#ec4899', '#8b5cf6']

export function Combined() {
  const [companyId, setCompanyId] = useState<number | null>(null)
  const [selectedReports, setSelectedReports] = useState<{ id: number; label: string }[]>([])
  const [selectedBulletinIds, setSelectedBulletinIds] = useState<number[]>([])
  const [showResult, setShowResult] = useState(false)

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

  const compareMutation = useMutation({
    mutationFn: (forceRefresh: boolean) =>
      callApi<CombinedAnalysisResult>('api_combined_analysis.php', 'compare', {
        report_ids: selectedReports.map((r) => r.id),
        bulletin_ids: selectedBulletinIds,
        provider: 'gemini',
        force_refresh: forceRefresh,
      }),
  })

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
                  <span key={r.id} className="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2 py-0.5 text-xs text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
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
        <Button onClick={openAnalysis} disabled={selectedReports.length === 0 || selectedBulletinIds.length === 0}>
          <span className="flex items-center gap-2">
            <BoltIcon />
            {`Analyser conjointement (${selectedReports.length} rapport(s), ${selectedBulletinIds.length} bulletin(s))`}
          </span>
        </Button>
      </div>

      {showResult && (
        <Modal title="Analyse combinée" onClose={() => setShowResult(false)}>
          {compareMutation.isPending && <LoadingState label="Analyse combinée en cours (peut prendre du temps si des éléments n'ont pas encore été analysés individuellement)…" />}
          {compareMutation.isError && <ErrorState message={(compareMutation.error as Error).message} />}
          {compareMutation.data && compareMutation.data.status === 'failed' && (
            <ErrorState message={compareMutation.data.error_message ?? "Échec de l'analyse combinée"} />
          )}
          {compareMutation.data && compareMutation.data.analysis && (
            <div className="flex flex-col gap-4">
              <div className="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                <span>
                  {compareMutation.data.reports.length} rapport(s), {compareMutation.data.bulletins.length} bulletin(s)
                </span>
                <span className="flex items-center gap-2">
                  {compareMutation.data.provider}/{compareMutation.data.model} {compareMutation.data.cached && '· depuis le cache'}
                  <Button variant="secondary" onClick={() => compareMutation.mutate(true)} disabled={compareMutation.isPending}>
                    Forcer
                  </Button>
                </span>
              </div>

              <div>
                <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Vue d'ensemble</h3>
                <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{compareMutation.data.analysis.combined_overview}</p>
              </div>

              {compareMutation.data.analysis.key_takeaways.length > 0 && (
                <div className="rounded-md border border-indigo-200 bg-indigo-50 p-3 dark:border-indigo-900 dark:bg-indigo-950/40">
                  <h3 className="mb-1 text-sm font-semibold text-indigo-700 dark:text-indigo-300">Points clés pour la décision</h3>
                  <ul className="list-disc pl-4 text-sm text-gray-800 dark:text-gray-200">
                    {compareMutation.data.analysis.key_takeaways.map((k, i) => <li key={i}>{k}</li>)}
                  </ul>
                </div>
              )}

              {compareMutation.data.analysis.company_performance_notes.length > 0 && (
                <Card title="Performance par entreprise">
                  <div className="flex flex-col gap-3">
                    {compareMutation.data.analysis.company_performance_notes.map((n) => (
                      <div key={n.company_symbol}>
                        <div className="text-sm font-semibold">{n.company_symbol} — {n.company_name}</div>
                        <p className="text-sm text-gray-700 dark:text-gray-300">{n.narrative}</p>
                      </div>
                    ))}
                  </div>
                </Card>
              )}

              {compareMutation.data.chart_data && compareMutation.data.chart_data.index_series.length > 0 && (
                <Card title="Évolution des indices">
                  <ResponsiveContainer width="100%" height={220}>
                    <LineChart>
                      <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                      <XAxis dataKey="date" type="category" allowDuplicatedCategory={false} tick={{ fontSize: 11 }} />
                      <YAxis tick={{ fontSize: 11 }} width={70} />
                      <Tooltip />
                      <Legend />
                      {compareMutation.data.chart_data.index_series.map((serie, i) => (
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

              {compareMutation.data.chart_data && compareMutation.data.chart_data.price_series.some((s) => s.data.length >= 2) && (
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                  {compareMutation.data.chart_data.price_series.filter((s) => s.data.length >= 2).map((serie) => (
                    <Card key={serie.company_id} title={`Cours — ${serie.symbol}`}>
                      <ResponsiveContainer width="100%" height={180}>
                        <LineChart data={serie.data}>
                          <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                          <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                          <YAxis domain={['auto', 'auto']} tick={{ fontSize: 11 }} width={70} />
                          <Tooltip />
                          <Line type="monotone" dataKey="close" stroke="#4f46e5" dot={false} strokeWidth={2} />
                        </LineChart>
                      </ResponsiveContainer>
                    </Card>
                  ))}
                </div>
              )}

              <div>
                <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Contexte de marché</h3>
                <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{compareMutation.data.analysis.market_context_summary}</p>
              </div>

              <div>
                <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Corrélation entreprise / marché</h3>
                <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{compareMutation.data.analysis.correlation_analysis}</p>
              </div>

              <div>
                <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Chronologie</h3>
                <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{compareMutation.data.analysis.timeline_narrative}</p>
              </div>

              {compareMutation.data.analysis.decision_support_notes.length > 0 && (
                <Card title="Points d'appui à la décision">
                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {compareMutation.data.analysis.decision_support_notes.map((d) => (
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

              {compareMutation.data.analysis.glossary.length > 0 && (
                <div>
                  <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Glossaire</h3>
                  <dl className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    {compareMutation.data.analysis.glossary.map((g, i) => (
                      <div key={i}><dt className="font-medium">{g.term}</dt><dd className="text-gray-600 dark:text-gray-400">{g.explanation}</dd></div>
                    ))}
                  </dl>
                </div>
              )}

              {(compareMutation.data.skipped_reports.length > 0 || compareMutation.data.skipped_bulletins.length > 0) && (
                <p className="text-xs text-amber-600 dark:text-amber-400">
                  {compareMutation.data.skipped_reports.length} rapport(s) et {compareMutation.data.skipped_bulletins.length} bulletin(s) n'ont pas pu être inclus (analyse individuelle en échec).
                </p>
              )}

              <p className="text-xs italic text-gray-400">{compareMutation.data.disclaimer}</p>
            </div>
          )}
        </Modal>
      )}
    </div>
  )
}
