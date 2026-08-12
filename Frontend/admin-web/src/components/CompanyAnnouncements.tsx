import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { announcementDownloadUrl, callApi } from '../lib/apiClient'
import type {
  IssuerAnnouncementAnalysis,
  IssuerAnnouncementDetail,
  IssuerAnnouncementRow,
  IssuerAnnouncementType,
} from '../lib/types'
import { Button, Card, ErrorState, LoadingState, MarkdownBadge, AnalysisBadge, Modal, Select } from './ui'

/**
 * Annonces émetteurs & publications BRVM d'une entreprise (+ annonces
 * générales du marché en option) — découverte, traitement PDF, conversion
 * markdown et analyse IA, même pipeline que les rapports et bulletins.
 */
export function CompanyAnnouncements({ companyId }: { companyId: number }) {
  const queryClient = useQueryClient()
  const [typeFilter, setTypeFilter] = useState('')
  const [showMarketWide, setShowMarketWide] = useState(false)
  const [viewId, setViewId] = useState<number | null>(null)
  const [analysisId, setAnalysisId] = useState<number | null>(null)
  const [discoverProgress, setDiscoverProgress] = useState<string | null>(null)

  const typesQuery = useQuery({
    queryKey: ['announcement-types'],
    queryFn: () => callApi<{ types: IssuerAnnouncementType[] }>('api_issuer_announcements.php', 'types', {}),
  })
  const types = typesQuery.data?.types ?? []
  const typeLabel = (key: string) => types.find((t) => t.key === key)?.label ?? key

  const companyListQuery = useQuery({
    queryKey: ['announcements', companyId, typeFilter],
    queryFn: () =>
      callApi<{ announcements: IssuerAnnouncementRow[]; count: number }>('api_issuer_announcements.php', 'list', {
        company_id: companyId,
        type: typeFilter || undefined,
      }),
  })

  const marketListQuery = useQuery({
    queryKey: ['announcements-market', typeFilter],
    queryFn: () =>
      callApi<{ announcements: IssuerAnnouncementRow[]; count: number }>('api_issuer_announcements.php', 'list', {
        only_unlinked: 1,
        type: typeFilter || undefined,
        limit: 50,
      }),
    enabled: showMarketWide,
  })

  const invalidateLists = () => {
    queryClient.invalidateQueries({ queryKey: ['announcements', companyId] })
    queryClient.invalidateQueries({ queryKey: ['announcements-market'] })
  }

  // Découverte séquentielle type par type, et lot de pages par lot de pages
  // (10 pages du listing brvm.org par requête — tout d'un coup dépasserait
  // le timeout FastCGI). Chaque listing est parcouru jusqu'à sa DERNIÈRE
  // page (has_more), avec un raccourci : si un lot entier de 10 pages ne
  // contient que des annonces déjà connues, les pages plus profondes le
  // sont forcément aussi (le listing est antéchronologique) — on passe au
  // type suivant, ce qui rend les découvertes suivantes quasi instantanées.
  const PAGES_PER_BATCH = 10
  const discoverMutation = useMutation({
    mutationFn: async () => {
      let totalNew = 0
      for (const [i, t] of types.entries()) {
        let startPage = 0
        for (;;) {
          setDiscoverProgress(
            `Découverte : ${t.label} (type ${i + 1}/${types.length}) — pages ${startPage + 1} à ${startPage + PAGES_PER_BATCH} du listing… (${totalNew} nouvelle(s) annonce(s) pour l'instant)`,
          )
          const res = await callApi<{ new_count: number; total_on_site: number; has_more: boolean }>(
            'api_issuer_announcements.php',
            'discover',
            { type: t.key, pages: PAGES_PER_BATCH, start_page: startPage },
          )
          totalNew += res.new_count
          if (!res.has_more) break // dernière page du listing atteinte
          // Lot entier (10 pages) 100% déjà connu : les pages plus profondes
          // le sont forcément aussi — inutile de creuser plus loin.
          if (res.total_on_site > 0 && res.new_count === 0) break
          startPage += PAGES_PER_BATCH
          if (startPage >= 1000) break // garde-fou
        }
      }
      return totalNew
    },
    onSuccess: (totalNew) => {
      setDiscoverProgress(`Découverte terminée : ${totalNew} nouvelle(s) annonce(s).`)
      invalidateLists()
    },
    onError: (e) => setDiscoverProgress(`Erreur : ${(e as Error).message}`),
  })

  const processMutation = useMutation({
    mutationFn: (id: number) => callApi<{ status: string; error?: string }>('api_issuer_announcements.php', 'process', { id }),
    onSuccess: invalidateLists,
  })

  const markdownMutation = useMutation({
    mutationFn: (id: number) => callApi('api_issuer_announcements.php', 'format_markdown', { id }),
    onSuccess: invalidateLists,
  })

  const detailQuery = useQuery({
    queryKey: ['announcement-detail', viewId],
    queryFn: () => callApi<IssuerAnnouncementDetail>('api_issuer_announcements.php', 'get', { id: viewId }),
    enabled: viewId !== null,
    // Markdown en cours de génération : re-vérifie périodiquement.
    refetchInterval: (query) => (query.state.data?.markdown_status === 'processing' ? 5000 : false),
  })

  const analysisMutation = useMutation({
    mutationFn: (id: number) => callApi<IssuerAnnouncementAnalysis>('api_issuer_announcements.php', 'analyze', { id }),
    onSuccess: (_, id) => {
      invalidateLists()
      setAnalysisId(id)
    },
  })

  const analysisQuery = useQuery({
    queryKey: ['announcement-analysis', analysisId],
    queryFn: () => callApi<IssuerAnnouncementAnalysis | null>('api_issuer_announcements.php', 'get_analysis', { id: analysisId }),
    enabled: analysisId !== null,
  })

  function AnnouncementTable({ rows, emptyLabel }: { rows: IssuerAnnouncementRow[]; emptyLabel: string }) {
    if (rows.length === 0) {
      return <p className="text-sm text-gray-500 dark:text-gray-400">{emptyLabel}</p>
    }
    return (
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead>
            <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
              <th className="pb-2 pr-3">Date</th>
              <th className="pb-2 pr-3">Type</th>
              <th className="pb-2 pr-3">Titre</th>
              <th className="pb-2 pr-3">Statut</th>
              <th className="pb-2">Actions</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((a) => (
              <tr key={a.id} className="border-t border-gray-100 align-top dark:border-gray-800">
                <td className="py-2 pr-3 whitespace-nowrap tabular-nums">{a.publish_date ?? '—'}</td>
                <td className="py-2 pr-3 text-xs text-gray-500 dark:text-gray-400">{typeLabel(a.announcement_type)}</td>
                <td className="py-2 pr-3 max-w-md text-gray-700 dark:text-gray-300">
                  {a.title}
                  {a.match_confidence === 'fuzzy' && (
                    <span className="ml-1.5 rounded px-1 py-0.5 text-[10px] font-semibold bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300" title="Rattachement entreprise approximatif">
                      approx.
                    </span>
                  )}
                </td>
                <td className="py-2 pr-3 whitespace-nowrap">
                  {a.text_extracted ? (
                    <span className="text-xs text-emerald-600 dark:text-emerald-400">Texte ✓</span>
                  ) : a.extraction_error ? (
                    <span className="text-xs text-red-500" title={a.extraction_error}>Échec</span>
                  ) : (
                    <span className="text-xs text-gray-400">À traiter</span>
                  )}
                  <MarkdownBadge status={a.markdown_status} />
                  <AnalysisBadge count={a.analyses_count} models={[]} />
                </td>
                <td className="py-2 whitespace-nowrap text-xs">
                  {!a.text_extracted && (
                    <button
                      type="button"
                      disabled={processMutation.isPending}
                      onClick={() => processMutation.mutate(a.id)}
                      className="mr-2 text-gray-700 underline-offset-2 underline hover:text-black dark:text-gray-200"
                    >
                      Traiter
                    </button>
                  )}
                  {!!a.text_extracted && a.markdown_status !== 'success' && a.markdown_status !== 'processing' && (
                    <button
                      type="button"
                      disabled={markdownMutation.isPending}
                      onClick={() => markdownMutation.mutate(a.id)}
                      className="mr-2 text-gray-700 underline-offset-2 underline hover:text-black dark:text-gray-200"
                    >
                      Markdown
                    </button>
                  )}
                  {!!a.text_extracted && (
                    <button
                      type="button"
                      disabled={analysisMutation.isPending}
                      onClick={() => (a.analyses_count > 0 ? setAnalysisId(a.id) : analysisMutation.mutate(a.id))}
                      className="mr-2 text-gray-700 underline-offset-2 underline hover:text-black dark:text-gray-200"
                    >
                      {analysisMutation.isPending && analysisMutation.variables === a.id
                        ? 'Analyse…'
                        : a.analyses_count > 0 ? 'Voir analyse' : 'Analyser'}
                    </button>
                  )}
                  <button
                    type="button"
                    onClick={() => setViewId(a.id)}
                    className="mr-2 text-gray-500 underline hover:text-gray-700 dark:text-gray-400"
                  >
                    Consulter
                  </button>
                  <a href={a.file_url} target="_blank" rel="noreferrer" className="text-gray-500 underline hover:text-gray-700 dark:text-gray-400">
                    PDF
                  </a>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <Card>
        <div className="flex flex-wrap items-end gap-3">
          <label className="w-64">
            <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Type d'annonce</span>
            <Select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)}>
              <option value="">Tous les types</option>
              {types.map((t) => (
                <option key={t.key} value={t.key}>{t.label}</option>
              ))}
            </Select>
          </label>
          <Button variant="secondary" disabled={discoverMutation.isPending || types.length === 0} onClick={() => discoverMutation.mutate()}>
            {discoverMutation.isPending ? 'Découverte en cours…' : '🔄 Découvrir les annonces (toutes les pages brvm.org)'}
          </Button>
          <label className="flex items-center gap-1.5 pb-2 text-xs text-gray-600 dark:text-gray-300">
            <input type="checkbox" checked={showMarketWide} onChange={() => setShowMarketWide(!showMarketWide)} />
            Afficher aussi les annonces générales du marché (avis, données économiques…)
          </label>
        </div>
        {discoverProgress && <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">{discoverProgress}</p>}
        {processMutation.data?.status === 'failed' && (
          <p className="mt-2 text-xs text-red-600 dark:text-red-400">Traitement échoué : {processMutation.data.error}</p>
        )}
        {analysisMutation.isError && (
          <p className="mt-2 text-xs text-red-600 dark:text-red-400">{(analysisMutation.error as Error).message}</p>
        )}
      </Card>

      <Card title={`Annonces de l'entreprise (${companyListQuery.data?.count ?? '…'})`}>
        {companyListQuery.isLoading && <LoadingState />}
        {companyListQuery.error && <ErrorState message={(companyListQuery.error as Error).message} />}
        {companyListQuery.data && (
          <AnnouncementTable
            rows={companyListQuery.data.announcements}
            emptyLabel="Aucune annonce rattachée à cette entreprise — lance une découverte ci-dessus, ou l'entreprise n'a simplement pas d'annonce récente sur brvm.org."
          />
        )}
      </Card>

      {showMarketWide && (
        <Card title={`Annonces générales du marché (${marketListQuery.data?.count ?? '…'})`}>
          {marketListQuery.isLoading && <LoadingState />}
          {marketListQuery.error && <ErrorState message={(marketListQuery.error as Error).message} />}
          {marketListQuery.data && (
            <AnnouncementTable
              rows={marketListQuery.data.announcements}
              emptyLabel="Aucune annonce générale découverte pour l'instant."
            />
          )}
        </Card>
      )}

      {viewId !== null && (
        <Modal title="Consultation de l'annonce" onClose={() => setViewId(null)}>
          {detailQuery.isLoading && <LoadingState />}
          {detailQuery.error && <ErrorState message={(detailQuery.error as Error).message} />}
          {detailQuery.data && (
            <div className="flex flex-col gap-3 text-sm">
              <div>
                <p className="font-medium">{detailQuery.data.title}</p>
                <p className="text-xs text-gray-500 dark:text-gray-400">
                  {detailQuery.data.type_label} · {detailQuery.data.publish_date ?? 'date inconnue'}
                  {detailQuery.data.company && ` · ${detailQuery.data.company.symbol}`}
                  {' · '}
                  <a
                    href={detailQuery.data.text_extracted ? announcementDownloadUrl(detailQuery.data.id) : detailQuery.data.file_url}
                    target="_blank"
                    rel="noreferrer"
                    className="underline"
                  >
                    PDF
                  </a>
                </p>
              </div>
              {detailQuery.data.markdown_status === 'processing' && (
                <p className="text-xs text-amber-600 dark:text-amber-400">Conversion markdown en cours… (rafraîchissement automatique)</p>
              )}
              {detailQuery.data.formatted_markdown ? (
                <div className="prose prose-sm max-w-none dark:prose-invert">
                  <ReactMarkdown remarkPlugins={[remarkGfm]}>{detailQuery.data.formatted_markdown}</ReactMarkdown>
                </div>
              ) : detailQuery.data.extracted_text ? (
                <pre className="max-h-96 overflow-y-auto whitespace-pre-wrap rounded-md bg-gray-50 p-3 text-xs dark:bg-gray-800">
                  {detailQuery.data.extracted_text}
                </pre>
              ) : (
                <p className="text-xs text-gray-500 dark:text-gray-400">
                  Texte pas encore extrait — utilise « Traiter » depuis la liste.
                </p>
              )}
            </div>
          )}
        </Modal>
      )}

      {analysisId !== null && (
        <Modal title="Analyse IA de l'annonce" onClose={() => setAnalysisId(null)}>
          {analysisQuery.isLoading && <LoadingState />}
          {analysisQuery.error && <ErrorState message={(analysisQuery.error as Error).message} />}
          {analysisQuery.data?.analysis && (
            <div className="flex flex-col gap-3 text-sm">
              <p className="text-gray-700 dark:text-gray-300">{analysisQuery.data.analysis.summary}</p>
              {analysisQuery.data.analysis.key_points.length > 0 && (
                <div>
                  <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Points clés</h4>
                  <ul className="list-disc pl-4 text-gray-700 dark:text-gray-300">
                    {analysisQuery.data.analysis.key_points.map((p, i) => <li key={i}>{p}</li>)}
                  </ul>
                </div>
              )}
              {analysisQuery.data.analysis.important_dates.length > 0 && (
                <div>
                  <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Dates importantes</h4>
                  <ul className="list-disc pl-4 text-gray-700 dark:text-gray-300">
                    {analysisQuery.data.analysis.important_dates.map((d, i) => (
                      <li key={i}><span className="tabular-nums font-medium">{d.date}</span> — {d.event}</li>
                    ))}
                  </ul>
                </div>
              )}
              {analysisQuery.data.analysis.amounts.length > 0 && (
                <div>
                  <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Montants</h4>
                  <ul className="list-disc pl-4 text-gray-700 dark:text-gray-300">
                    {analysisQuery.data.analysis.amounts.map((a, i) => (
                      <li key={i}>{a.label} : <span className="font-medium">{a.value}</span></li>
                    ))}
                  </ul>
                </div>
              )}
              {analysisQuery.data.analysis.potential_market_relevance && (
                <div>
                  <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Intérêt pour l'investisseur</h4>
                  <p className="text-gray-700 dark:text-gray-300">{analysisQuery.data.analysis.potential_market_relevance}</p>
                </div>
              )}
              {analysisQuery.data.analysis.glossary.length > 0 && (
                <div>
                  <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Glossaire</h4>
                  <ul className="list-disc pl-4 text-xs text-gray-500 dark:text-gray-400">
                    {analysisQuery.data.analysis.glossary.map((g, i) => (
                      <li key={i}><strong>{g.term}</strong> : {g.explanation}</li>
                    ))}
                  </ul>
                </div>
              )}
              <p className="text-xs italic text-gray-400 dark:text-gray-500">
                {analysisQuery.data.disclaimer} ({analysisQuery.data.provider}/{analysisQuery.data.model})
              </p>
            </div>
          )}
          {analysisQuery.data === null && (
            <p className="text-sm text-gray-500 dark:text-gray-400">Aucune analyse en cache pour cette annonce.</p>
          )}
        </Modal>
      )}
    </div>
  )
}
