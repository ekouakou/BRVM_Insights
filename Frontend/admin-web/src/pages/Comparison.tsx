import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { BarChart, Bar, LineChart, Line, ResponsiveContainer, XAxis, YAxis, Tooltip, CartesianGrid } from 'recharts'
import { callApi } from '../lib/apiClient'
import type { Company, CompanyWithReports, ComparisonResult } from '../lib/types'
import { Button, Card, ErrorState, Input, LoadingState, Select } from '../components/ui'

export function Comparison() {
  const [selected, setSelected] = useState<number[]>([])
  const [startDate, setStartDate] = useState('2023-01-01')
  const [endDate, setEndDate] = useState(new Date().toISOString().slice(0, 10))
  const [reportType, setReportType] = useState('')
  const [provider, setProvider] = useState('gemini')
  const [model, setModel] = useState('')

  const companiesQuery = useQuery({
    queryKey: ['companies-list'],
    queryFn: () => callApi<Company[]>('api_companies.php', 'list', { per_page: 200, active: 1 }),
  })

  // Nombre de rapports au texte extrait par entreprise — la comparaison
  // (class/ReportComparisonService.php) exige text_extracted=1 sur les
  // rapports pris en compte, donc une entreprise sans aucun rapport traité
  // ne peut produire aucun résultat exploitable.
  const reportsReadinessQuery = useQuery({
    queryKey: ['companies-with-reports'],
    queryFn: () => callApi<CompanyWithReports[]>('api_reports.php', 'list_companies'),
  })
  const readyCompanyIds = new Set(
    (reportsReadinessQuery.data ?? []).filter((c) => (c.reports_with_text ?? 0) > 0).map((c) => c.company_id)
  )

  const compareMutation = useMutation({
    mutationFn: (forceRefresh: boolean) =>
      callApi<ComparisonResult>('api_report_comparison.php', 'compare', {
        company_ids: selected,
        start_date: startDate,
        end_date: endDate,
        report_type: reportType || undefined,
        provider,
        model: model || undefined,
        force_refresh: forceRefresh,
      }),
  })

  function toggle(id: number) {
    setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  const result = compareMutation.data

  // Regroupe les entreprises par secteur d'activité (banques ensemble, etc.)
  // — "Autres" en dernier pour celles sans secteur renseigné.
  const companiesBySector = new Map<string, Company[]>()
  for (const c of companiesQuery.data ?? []) {
    const sector = c.sector_name ?? 'Autres'
    if (!companiesBySector.has(sector)) companiesBySector.set(sector, [])
    companiesBySector.get(sector)!.push(c)
  }
  const sortedSectors = [...companiesBySector.keys()].sort((a, b) =>
    a === 'Autres' ? 1 : b === 'Autres' ? -1 : a.localeCompare(b)
  )

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Comparaison de rapports</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">Tendance dans le temps et/ou entre entreprises</p>
      </div>

      <Card>
        <div className="flex flex-col gap-4">
          <div>
            <span className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Entreprises</span>
            <div className="flex max-h-64 flex-col gap-3 overflow-y-auto rounded-md border border-gray-200 p-3 dark:border-gray-800">
              {sortedSectors.map((sector) => (
                <div key={sector}>
                  <div className="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">{sector}</div>
                  <div className="flex flex-wrap gap-2">
                    {companiesBySector.get(sector)!.map((c) => {
                      const ready = readyCompanyIds.has(c.company_id)
                      return (
                        <label
                          key={c.company_id}
                          title={ready ? undefined : "Aucun rapport avec texte extrait pour cette entreprise — indisponible pour la comparaison"}
                          className={`rounded-full border px-3 py-1 text-xs font-medium ${
                            !ready
                              ? 'cursor-not-allowed border-gray-200 text-gray-300 dark:border-gray-800 dark:text-gray-600'
                              : selected.includes(c.company_id)
                              ? 'cursor-pointer border-indigo-500 bg-indigo-600 text-white'
                              : 'cursor-pointer border-gray-300 text-gray-600 dark:border-gray-700 dark:text-gray-300'
                          }`}
                        >
                          <input
                            type="checkbox"
                            className="hidden"
                            checked={selected.includes(c.company_id)}
                            disabled={!ready}
                            onChange={() => toggle(c.company_id)}
                          />
                          {c.symbol}
                        </label>
                      )
                    })}
                  </div>
                </div>
              ))}
            </div>
          </div>

          <div className="flex flex-wrap items-end gap-4">
            <label className="w-40">
              <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Début</span>
              <Input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
            </label>
            <label className="w-40">
              <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Fin</span>
              <Input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
            </label>
            <label className="w-44">
              <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Type de rapport</span>
              <Select value={reportType} onChange={(e) => setReportType(e.target.value)}>
                <option value="">Tous</option>
                <option value="annuel">Annuel</option>
                <option value="semestriel">Semestriel</option>
                <option value="trimestriel">Trimestriel</option>
                <option value="etats_financiers">États financiers</option>
                <option value="attestation_cac">Attestation CAC</option>
              </Select>
            </label>
            <label className="w-36">
              <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Fournisseur</span>
              <Select value={provider} onChange={(e) => setProvider(e.target.value)}>
                <option value="gemini">Gemini</option>
                <option value="anthropic">Anthropic</option>
              </Select>
            </label>
            <label className="w-44">
              <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Modèle (optionnel)</span>
              <Input value={model} onChange={(e) => setModel(e.target.value)} placeholder="défaut du fournisseur" />
            </label>

            <Button onClick={() => compareMutation.mutate(false)} disabled={selected.length === 0 || compareMutation.isPending}>
              {compareMutation.isPending ? 'Comparaison…' : 'Comparer'}
            </Button>
            <Button variant="secondary" onClick={() => compareMutation.mutate(true)} disabled={selected.length === 0 || compareMutation.isPending}>
              Forcer
            </Button>
          </div>
        </div>
      </Card>

      {compareMutation.isPending && <LoadingState label="Analyse comparative en cours (peut prendre du temps si des rapports n’ont pas encore été analysés individuellement)…" />}
      {compareMutation.isError && <ErrorState message={(compareMutation.error as Error).message} />}

      {result && result.analysis && (
        <div className="flex flex-col gap-6">
          <Card>
            <div className="mb-2 text-xs text-gray-500 dark:text-gray-400">
              {result.companies.map((c) => c.symbol).join(', ')} · {result.start_date} → {result.end_date} · {result.provider}/{result.model}
              {result.cached && ' · depuis le cache'}
            </div>
            <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{result.analysis.comparative_summary}</p>
          </Card>

          {result.analysis.cross_company_ranking && (
            <Card title="Classement comparatif">
              <p className="text-sm text-gray-800 dark:text-gray-200">{result.analysis.cross_company_ranking}</p>
            </Card>
          )}

          {result.analysis.trend_analysis && result.analysis.trend_analysis.length > 0 && (
            <Card title="Tendance par entreprise">
              <div className="flex flex-col gap-4">
                {result.analysis.trend_analysis.map((t) => (
                  <div key={t.company_symbol}>
                    <div className="text-sm font-semibold">{t.company_symbol} — {t.company_name}</div>
                    <div className="text-xs text-gray-500 dark:text-gray-400">
                      CA: {t.revenue_trend_percent ?? '—'}% · Résultat net: {t.net_income_trend_percent ?? '—'}%
                    </div>
                    <p className="mt-1 text-sm text-gray-700 dark:text-gray-300">{t.narrative}</p>
                  </div>
                ))}
              </div>
            </Card>
          )}

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {result.analysis.price_correlation_note && (
              <Card title="Corrélation cours / fondamentaux">
                <p className="text-sm text-gray-800 dark:text-gray-200">{result.analysis.price_correlation_note}</p>
              </Card>
            )}
            {result.analysis.risks_evolution && (
              <Card title="Évolution des risques">
                <p className="text-sm text-gray-800 dark:text-gray-200">{result.analysis.risks_evolution}</p>
              </Card>
            )}
          </div>

          {result.analysis.decision_support_notes && result.analysis.decision_support_notes.length > 0 && (
            <Card title="Points d’appui à la décision">
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

          {result.chart_data?.price_series.map((serie) => (
            serie.data.length >= 2 && (
              <Card key={serie.company_id} title={`Cours — ${serie.symbol}`}>
                <ResponsiveContainer width="100%" height={200}>
                  <LineChart data={serie.data}>
                    <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                    <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                    <YAxis domain={['auto', 'auto']} tick={{ fontSize: 11 }} width={70} />
                    <Tooltip />
                    <Line type="monotone" dataKey="close" stroke="#4f46e5" dot={false} strokeWidth={2} />
                  </LineChart>
                </ResponsiveContainer>
              </Card>
            )
          ))}

          {result.chart_data?.financials_series.map((serie) => (
            serie.data.length > 0 && (
              <Card key={serie.company_id} title={`Chiffres clés dans le temps — ${serie.symbol}`}>
                <ResponsiveContainer width="100%" height={220}>
                  <BarChart data={serie.data}>
                    <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                    <XAxis dataKey="publish_date" tick={{ fontSize: 11 }} />
                    <YAxis tick={{ fontSize: 11 }} width={80} />
                    <Tooltip />
                    <Bar dataKey="revenue" fill="#4f46e5" name="Chiffre d'affaires" />
                    <Bar dataKey="net_income" fill="#a5b4fc" name="Résultat net" />
                  </BarChart>
                </ResponsiveContainer>
              </Card>
            )
          ))}

          <p className="text-xs italic text-gray-400">{result.disclaimer}</p>
        </div>
      )}
    </div>
  )
}
