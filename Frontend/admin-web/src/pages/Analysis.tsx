import { useEffect, useId, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { LineChart, Line, ResponsiveContainer, XAxis, YAxis, Tooltip, CartesianGrid } from 'recharts'
import { callApi } from '../lib/apiClient'
import type { Company, ReportAnalysis, ReportSummary } from '../lib/types'
import { Button, Card, ErrorState, Input, Select, StarRating } from '../components/ui'
import { AnalysisHistoryList } from '../components/AnalysisHistoryList'
import { IconButton, TrashIcon } from '../components/icons'
import { AI_MODELS, type AiProvider } from '../lib/aiModels'

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div>
      <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">{title}</h3>
      <div className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{children}</div>
    </div>
  )
}

function fmt(n: number | string | null | undefined): string {
  if (n === null || n === undefined) return '—'
  const num = typeof n === 'string' ? parseFloat(n) : n
  if (Number.isNaN(num)) return '—'
  return num.toLocaleString('fr-FR', { maximumFractionDigits: 2 })
}

/**
 * Rendu du résultat d'une analyse — extrait en composant pour pouvoir
 * l'instancier deux fois côte à côte en mode "Comparer 2 fournisseurs"
 * sans dupliquer ce gros bloc JSX.
 */
function AnalysisResultView({
  result,
  onRate,
  onDelete,
}: {
  result: ReportAnalysis
  onRate?: (id: number, rating: number) => void
  onDelete?: (id: number) => void
}) {
  if (result.status === 'failed') {
    return <ErrorState message={result.error_message ?? 'Échec de l’analyse'} />
  }
  if (!result.analysis) return null

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <div className="mb-3 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
          <span>{result.company.symbol} — {result.report.report_type} du {result.report.publish_date}</span>
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
        <Section title="Résumé exécutif">{result.analysis.executive_summary}</Section>
      </Card>

      {result.chart_data.price_history.length >= 2 && (
        <Card title="Historique de prix (180 jours)">
          <ResponsiveContainer width="100%" height={220}>
            <LineChart data={result.chart_data.price_history}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
              <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
              <YAxis domain={['auto', 'auto']} tick={{ fontSize: 11 }} width={70} />
              <Tooltip />
              <Line type="monotone" dataKey="close" stroke="var(--chart-1)" dot={false} strokeWidth={2} />
            </LineChart>
          </ResponsiveContainer>
        </Card>
      )}

      {result.analysis.key_financials && (
        <Card title="Chiffres clés">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {Object.entries(result.analysis.key_financials)
              .filter(([k]) => k !== 'currency')
              .map(([k, v]) => (
                <div key={k}>
                  <div className="text-xs text-gray-500 dark:text-gray-400">{k}</div>
                  <div className="font-medium">{fmt(v as number)}</div>
                </div>
              ))}
          </div>
        </Card>
      )}

      {result.analysis.valuation_assessment && (
        <Card title="Valorisation">
          <div className="mb-2 inline-block rounded-full bg-gray-200 px-3 py-1 text-xs font-semibold text-gray-800 dark:bg-gray-800 dark:text-gray-200">
            {result.analysis.valuation_assessment.verdict}
          </div>
          <div className="mb-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div><div className="text-xs text-gray-500">PER</div><div className="font-medium">{fmt(result.analysis.valuation_assessment.pe_ratio)}</div></div>
            <div><div className="text-xs text-gray-500">BPA</div><div className="font-medium">{fmt(result.analysis.valuation_assessment.eps)}</div></div>
            <div><div className="text-xs text-gray-500">Price/Book</div><div className="font-medium">{fmt(result.analysis.valuation_assessment.price_to_book)}</div></div>
            <div><div className="text-xs text-gray-500">Rendement div.</div><div className="font-medium">{fmt(result.analysis.valuation_assessment.dividend_yield_percent)}%</div></div>
          </div>
          <p className="text-sm text-gray-700 dark:text-gray-300">{result.analysis.valuation_assessment.rationale}</p>
        </Card>
      )}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {result.analysis.financial_analysis && (
          <Card><Section title="Analyse financière">{result.analysis.financial_analysis}</Section></Card>
        )}
        {result.analysis.growth_trends && (
          <Card><Section title="Tendance de croissance">{result.analysis.growth_trends}</Section></Card>
        )}
        {result.analysis.cash_flow_analysis && (
          <Card><Section title="Flux de trésorerie">{result.analysis.cash_flow_analysis}</Section></Card>
        )}
        {result.analysis.governance_and_audit && (
          <Card><Section title="Gouvernance / audit">{result.analysis.governance_and_audit}</Section></Card>
        )}
        {result.analysis.market_context_note && (
          <Card><Section title="Contexte marché">{result.analysis.market_context_note}</Section></Card>
        )}
        {result.analysis.technical_reading && (
          <Card><Section title="Lecture technique">{result.analysis.technical_reading}</Section></Card>
        )}
      </div>

      {result.analysis.swot && (
        <Card title="SWOT">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Section title="Forces"><ul className="list-disc pl-4">{result.analysis.swot.strengths.map((s, i) => <li key={i}>{s}</li>)}</ul></Section>
            <Section title="Faiblesses"><ul className="list-disc pl-4">{result.analysis.swot.weaknesses.map((s, i) => <li key={i}>{s}</li>)}</ul></Section>
            <Section title="Opportunités"><ul className="list-disc pl-4">{result.analysis.swot.opportunities.map((s, i) => <li key={i}>{s}</li>)}</ul></Section>
            <Section title="Menaces"><ul className="list-disc pl-4">{result.analysis.swot.threats.map((s, i) => <li key={i}>{s}</li>)}</ul></Section>
          </div>
        </Card>
      )}

      {result.analysis.risks && result.analysis.risks.length > 0 && (
        <Card title="Risques">
          <ul className="flex flex-col gap-2">
            {result.analysis.risks.map((r, i) => (
              <li key={i}><span className="mr-2 rounded bg-gray-100 px-2 py-0.5 text-xs font-medium dark:bg-gray-800">{r.category}</span>{r.description}</li>
            ))}
          </ul>
        </Card>
      )}

      {result.analysis.investment_thesis && (
        <Card title="Thèse d’investissement">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Section title="Bull case">{result.analysis.investment_thesis.bull_case}</Section>
            <Section title="Bear case">{result.analysis.investment_thesis.bear_case}</Section>
          </div>
          <div className="mt-3">
            <Section title="Points à surveiller">
              <ul className="list-disc pl-4">{result.analysis.investment_thesis.key_watch_points.map((p, i) => <li key={i}>{p}</li>)}</ul>
            </Section>
          </div>
        </Card>
      )}

      {result.analysis.glossary && result.analysis.glossary.length > 0 && (
        <Card title="Glossaire">
          <dl className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            {result.analysis.glossary.map((g, i) => (
              <div key={i}><dt className="font-medium">{g.term}</dt><dd className="text-gray-600 dark:text-gray-400">{g.explanation}</dd></div>
            ))}
          </dl>
        </Card>
      )}

      <p className="text-xs italic text-gray-400">{result.disclaimer}</p>
    </div>
  )
}

export function Analysis() {
  const [searchParams, setSearchParams] = useSearchParams()
  const queryClient = useQueryClient()
  const [companyId, setCompanyId] = useState<number | null>(null)
  const [reportId, setReportId] = useState<number | null>(null)
  const [reportTypeFilter, setReportTypeFilter] = useState('')
  const [provider, setProvider] = useState<AiProvider>('gemini')
  const [model, setModel] = useState('')
  const modelListId = useId()
  const appliedParamsRef = useRef(false)

  const companiesQuery = useQuery({
    queryKey: ['companies-list'],
    queryFn: () => callApi<Company[]>('api_companies.php', 'list', { per_page: 200, active: 1 }),
  })

  const selectedCompany = companiesQuery.data?.find((c) => c.company_id === companyId)

  const reportsQuery = useQuery({
    queryKey: ['reports-for-company', selectedCompany?.symbol, reportTypeFilter],
    queryFn: () => callApi<ReportSummary[]>('api_reports.php', 'list', { symbol: selectedCompany?.symbol, report_type: reportTypeFilter || undefined }),
    enabled: !!selectedCompany,
  })

  const [historyOverride, setHistoryOverride] = useState<ReportAnalysis | null>(null)
  const [compareMode, setCompareMode] = useState(false)
  const [compareLeftId, setCompareLeftId] = useState<number | null>(null)
  const [compareRightId, setCompareRightId] = useState<number | null>(null)

  const historyQuery = useQuery({
    queryKey: ['report-analysis-history', reportId],
    queryFn: () => callApi<ReportAnalysis[]>('api_report_analysis.php', 'history', { report_id: reportId }),
    enabled: !!reportId,
  })

  const analyzeMutation = useMutation({
    // report_id vient explicitement de l'appelant (pas lu depuis le state `reportId`) :
    // le déclenchement automatique depuis l'effet ci-dessous appelle setReportId() et
    // mutate() dans le même tick, avant le re-render — un mutationFn qui lirait le
    // state `reportId` capturerait encore l'ancienne valeur (null) à cet instant.
    mutationFn: ({ targetReportId, forceRefresh }: { targetReportId: number; forceRefresh: boolean }) =>
      callApi<ReportAnalysis>('api_report_analysis.php', 'analyze', {
        report_id: targetReportId,
        provider,
        model: model || undefined,
        force_refresh: forceRefresh,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['report-analysis-history', reportId] })
    },
  })

  const rateMutation = useMutation({
    mutationFn: ({ id, rating }: { id: number; rating: number }) =>
      callApi<ReportAnalysis>('api_report_analysis.php', 'rate', { id, rating }),
    onSuccess: (updated) => {
      queryClient.invalidateQueries({ queryKey: ['report-analysis-history', reportId] })
      setHistoryOverride((prev) => (prev && prev.id === updated.id ? updated : prev))
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => callApi<null>('api_report_analysis.php', 'delete', { id }),
    onSuccess: (_data, id) => {
      queryClient.invalidateQueries({ queryKey: ['report-analysis-history', reportId] })
      setHistoryOverride((prev) => (prev && prev.id === id ? null : prev))
      if (analyzeMutation.data?.id === id) analyzeMutation.reset()
      if (compareLeftId === id) setCompareLeftId(null)
      if (compareRightId === id) setCompareRightId(null)
    },
  })

  // Arrivée depuis la page Rapports via un lien "Analyser" (?symbol=...&report_id=...) :
  // présélectionne l'entreprise puis le rapport, et lance l'analyse automatiquement.
  useEffect(() => {
    if (appliedParamsRef.current || companyId !== null) return
    const symbolParam = searchParams.get('symbol')
    if (!symbolParam || !companiesQuery.data) return
    const match = companiesQuery.data.find((c) => c.symbol === symbolParam)
    if (match) setCompanyId(match.company_id)
  }, [searchParams, companiesQuery.data, companyId])

  useEffect(() => {
    if (appliedParamsRef.current) return
    const reportIdParam = searchParams.get('report_id')
    if (!reportIdParam || !reportsQuery.data) return
    const id = Number(reportIdParam)
    const match = reportsQuery.data.find((r) => r.id === id && r.text_extracted)
    if (match) {
      appliedParamsRef.current = true
      setReportId(id)
      setSearchParams({}, { replace: true })
      analyzeMutation.mutate({ targetReportId: id, forceRefresh: false })
    }
  }, [searchParams, reportsQuery.data])

  const result = historyOverride ?? analyzeMutation.data
  const history = historyQuery.data ?? []
  const compareLeft = history.find((h) => h.id === compareLeftId) ?? null
  const compareRight = history.find((h) => h.id === compareRightId) ?? null

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Analyse IA d’un rapport</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">Résumé, chiffres clés, SWOT, valorisation…</p>
      </div>

      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <label className="min-w-[220px] flex-1">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Entreprise</span>
            <Select value={companyId ?? ''} onChange={(e) => { setCompanyId(e.target.value ? Number(e.target.value) : null); setReportId(null) }}>
              <option value="">— Choisir —</option>
              {(companiesQuery.data ?? []).map((c) => (
                <option key={c.company_id} value={c.company_id}>{c.symbol} — {c.name}</option>
              ))}
            </Select>
          </label>

          <label className="w-44">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Type de rapport</span>
            <Select value={reportTypeFilter} onChange={(e) => { setReportTypeFilter(e.target.value); setReportId(null) }}>
              <option value="">Tous les types</option>
              <option value="annuel">Annuel</option>
              <option value="semestriel">Semestriel</option>
              <option value="trimestriel">Trimestriel</option>
              <option value="etats_financiers">États financiers</option>
              <option value="attestation_cac">Attestation CAC</option>
            </Select>
          </label>

          <label className="min-w-[260px] flex-1">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Rapport</span>
            <Select value={reportId ?? ''} onChange={(e) => setReportId(e.target.value ? Number(e.target.value) : null)} disabled={!reportsQuery.data}>
              <option value="">— Choisir —</option>
              {(reportsQuery.data ?? []).map((r) => (
                <option key={r.id} value={r.id} disabled={!r.text_extracted}>
                  {r.report_type} — {r.publish_date ?? '?'} {!r.text_extracted ? '(texte non extrait)' : ''}
                </option>
              ))}
            </Select>
          </label>

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

          <Button
            onClick={() => { setHistoryOverride(null); reportId && analyzeMutation.mutate({ targetReportId: reportId, forceRefresh: false }) }}
            disabled={!reportId || analyzeMutation.isPending}
          >
            {analyzeMutation.isPending ? 'Analyse…' : 'Analyser'}
          </Button>
          <Button
            variant="secondary"
            onClick={() => { setHistoryOverride(null); reportId && analyzeMutation.mutate({ targetReportId: reportId, forceRefresh: true }) }}
            disabled={!reportId || analyzeMutation.isPending}
          >
            Forcer un nouvel appel
          </Button>
        </div>

        <AnalysisHistoryList
          items={history}
          selectedId={result?.id ?? null}
          onSelect={setHistoryOverride}
          onRate={(id, rating) => rateMutation.mutate({ id, rating })}
          onDelete={(id) => deleteMutation.mutate(id)}
          preview={(h) => h.analysis?.executive_summary}
          title="Historique des analyses"
        />

        {history.length >= 2 && (
          <div className="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800">
            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
              <input
                type="checkbox"
                checked={compareMode}
                onChange={(e) => {
                  setCompareMode(e.target.checked)
                  if (e.target.checked && history.length >= 2) {
                    setCompareLeftId((prev) => prev ?? history[0].id)
                    setCompareRightId((prev) => prev ?? history[1].id)
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
      </Card>

      {analyzeMutation.isError && <ErrorState message={(analyzeMutation.error as Error).message} />}

      {compareMode ? (
        <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
          <div>{compareLeft ? <AnalysisResultView result={compareLeft} onRate={(id, rating) => rateMutation.mutate({ id, rating })} onDelete={(id) => deleteMutation.mutate(id)} /> : <p className="text-sm text-gray-400">Choisis une analyse A.</p>}</div>
          <div>{compareRight ? <AnalysisResultView result={compareRight} onRate={(id, rating) => rateMutation.mutate({ id, rating })} onDelete={(id) => deleteMutation.mutate(id)} /> : <p className="text-sm text-gray-400">Choisis une analyse B.</p>}</div>
        </div>
      ) : (
        result && <AnalysisResultView result={result} onRate={(id, rating) => rateMutation.mutate({ id, rating })} onDelete={(id) => deleteMutation.mutate(id)} />
      )}
    </div>
  )
}
