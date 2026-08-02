import { Fragment, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { callApi, reportDownloadUrl, uploadFile } from '../lib/apiClient'
import type { BackfillCompanyProgress, DiscoverResult, ReportDetail, ReportProcessResult, ReportSummary } from '../lib/types'
import { AnalysisBadge, Button, Card, ErrorState, LoadingState, MarkdownBadge, Modal, StatTile, Table } from '../components/ui'
import { BoltIcon, CloseIcon, EyeIcon, IconButton, InfoIcon, RetryIcon, UploadIcon } from '../components/icons'

interface BackfillProgress {
  by_company: BackfillCompanyProgress[]
  totals: { total: number; downloaded: number; extracted: number; errors: number; pending: number }
}

export function Reports() {
  const navigate = useNavigate()
  const [selectedSymbol, setSelectedSymbol] = useState<string | null>(null)
  const [results, setResults] = useState<Record<number, ReportProcessResult>>({})
  const [pendingId, setPendingId] = useState<number | null>(null)
  const [transportErrors, setTransportErrors] = useState<Record<number, string>>({})
  const [openErrorId, setOpenErrorId] = useState<number | null>(null)
  const [viewReportId, setViewReportId] = useState<number | null>(null)
  const [bulkProcessing, setBulkProcessing] = useState(false)
  const [bulkProgress, setBulkProgress] = useState<{ done: number; total: number } | null>(null)
  const [bulkDiscovering, setBulkDiscovering] = useState(false)
  const [bulkAnalyzing, setBulkAnalyzing] = useState(false)
  const [bulkAnalyzeProgress, setBulkAnalyzeProgress] = useState<{ done: number; total: number; failed: number } | null>(null)
  const [bulkFormatting, setBulkFormatting] = useState(false)
  const [bulkFormatProgress, setBulkFormatProgress] = useState<{ done: number; total: number; failed: number } | null>(null)
  const [showRawText, setShowRawText] = useState(false)
  const fileInputs = useRef<Record<number, HTMLInputElement | null>>({})
  const markdownFileInput = useRef<HTMLInputElement | null>(null)
  const queryClient = useQueryClient()

  const progressQuery = useQuery({
    queryKey: ['backfill-progress'],
    queryFn: () => callApi<BackfillProgress>('api_reports.php', 'backfill_progress'),
  })

  const reportsQuery = useQuery({
    queryKey: ['reports-list', selectedSymbol],
    queryFn: () => callApi<ReportSummary[]>('api_reports.php', 'list', { symbol: selectedSymbol }),
    enabled: !!selectedSymbol,
  })

  const viewQuery = useQuery({
    queryKey: ['report-detail', viewReportId],
    queryFn: () => callApi<ReportDetail>('api_reports.php', 'get', { id: viewReportId }),
    enabled: viewReportId !== null,
    // La restructuration markdown tourne en arrière-plan côté serveur (peut
    // prendre plusieurs minutes, voir api_reports.php action format_markdown) :
    // on ré-interroge tant qu'elle est en cours, on s'arrête sinon.
    refetchInterval: (query) => (query.state.data?.markdown_status === 'processing' ? 4000 : false),
  })

  const formatMarkdownMutation = useMutation({
    mutationFn: (id: number) => callApi<{ id: number; status: string }>('api_reports.php', 'format_markdown', { id }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['report-detail', viewReportId] })
    },
  })

  const uploadMarkdownMutation = useMutation({
    mutationFn: ({ id, file }: { id: number; file: File }) =>
      uploadFile<{ id: number; status: string }>('api_reports.php', 'upload_markdown', file, { id }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['report-detail', viewReportId] })
      queryClient.invalidateQueries({ queryKey: ['reports-list', selectedSymbol] })
    },
  })

  function downloadMarkdownFile(title: string, markdown: string) {
    const safeName = title.replace(/[^A-Za-z0-9._-]+/g, '_').slice(0, 80)
    const blob = new Blob([markdown], { type: 'text/markdown;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${safeName || 'rapport'}.md`
    a.click()
    URL.revokeObjectURL(url)
  }

  const discoverMutation = useMutation({
    mutationFn: (symbol: string) => callApi<DiscoverResult>('api_reports.php', 'discover_new', { symbol }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['reports-list', selectedSymbol] })
      queryClient.invalidateQueries({ queryKey: ['backfill-progress'] })
    },
  })

  function onProcessed(result: ReportProcessResult) {
    setResults((prev) => ({ ...prev, [result.id]: result }))
    setPendingId(null)
    if (result.status === 'success') {
      setOpenErrorId((prev) => (prev === result.id ? null : prev))
    }
    queryClient.invalidateQueries({ queryKey: ['reports-list', selectedSymbol] })
    queryClient.invalidateQueries({ queryKey: ['backfill-progress'] })
  }

  // Une erreur de transport (délai MAMP dépassé, réponse non-JSON) ne veut pas
  // dire que le traitement a échoué côté serveur — voir apiClient.ts. On
  // rafraîchit donc la liste pour refléter l'état réel plutôt que de figer un
  // statut "échec" potentiellement faux.
  function onTransportError(id: number, error: unknown) {
    setPendingId(null)
    setTransportErrors((prev) => ({ ...prev, [id]: (error as Error).message }))
    queryClient.invalidateQueries({ queryKey: ['reports-list', selectedSymbol] })
    queryClient.invalidateQueries({ queryKey: ['backfill-progress'] })
  }

  function clearTransportError(id: number) {
    setTransportErrors((prev) => {
      if (!(id in prev)) return prev
      const next = { ...prev }
      delete next[id]
      return next
    })
  }

  const processMutation = useMutation({
    mutationFn: (id: number) => callApi<ReportProcessResult>('api_reports.php', 'process', { id }),
    onMutate: (id) => { setPendingId(id); clearTransportError(id) },
    onSuccess: onProcessed,
    onError: (error, id) => onTransportError(id, error),
  })

  const uploadMutation = useMutation({
    mutationFn: ({ id, file }: { id: number; file: File }) =>
      uploadFile<ReportProcessResult>('api_reports.php', 'upload_replacement', file, { id }),
    onMutate: ({ id }) => { setPendingId(id); clearTransportError(id) },
    onSuccess: onProcessed,
    onError: (error, { id }) => onTransportError(id, error),
  })

  function handleFileChosen(id: number, e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    if (file) uploadMutation.mutate({ id, file })
    e.target.value = ''
  }

  function isRowExtracted(r: ReportSummary): boolean {
    const result = results[r.id]
    return result ? result.status === 'success' : r.text_extracted
  }

  const pendingReportIds = (reportsQuery.data ?? []).filter((r) => !isRowExtracted(r)).map((r) => r.id)
  const analyzableReportIds = (reportsQuery.data ?? []).filter((r) => isRowExtracted(r)).map((r) => r.id)

  // Équivalent "traiter tout" pour une entreprise : appelle process séquentiellement
  // (une requête par rapport) plutôt qu'un seul gros appel backend — un traitement
  // par lot ferait facilement dépasser les 30s d'idle timeout de MAMP (mod_fastcgi)
  // sur une entreprise avec beaucoup de rapports en attente.
  async function runBulkProcess() {
    if (pendingReportIds.length === 0) return
    setBulkProcessing(true)
    setBulkProgress({ done: 0, total: pendingReportIds.length })
    for (const id of pendingReportIds) {
      try {
        await processMutation.mutateAsync(id)
      } catch {
        // déjà surfacé ligne par ligne (transportErrors/extraction_error) ; on continue
      }
      setBulkProgress((prev) => (prev ? { ...prev, done: prev.done + 1 } : prev))
    }
    setBulkProcessing(false)
  }

  // Enchaîne la découverte (scan de TOUTES les pages de pagination brvm.org,
  // voir class/BRVMReportsScraper.php::scrapeCompanyReports) et le
  // téléchargement + extraction des rapports ainsi trouvés, en un seul clic —
  // évite d'avoir à cliquer "Vérifier les nouveaux rapports" puis "Traiter
  // tous les rapports en attente" séparément. Utilise reportsQuery.refetch()
  // plutôt que pendingReportIds (calculé au rendu précédent) pour être sûr de
  // traiter les rapports qui viennent d'être découverts.
  async function runDiscoverAndDownloadAll() {
    if (!selectedSymbol) return
    setBulkDiscovering(true)
    try {
      await discoverMutation.mutateAsync(selectedSymbol)
      const { data: freshReports } = await reportsQuery.refetch()
      const idsToProcess = (freshReports ?? []).filter((r) => !isRowExtracted(r)).map((r) => r.id)

      if (idsToProcess.length > 0) {
        setBulkProcessing(true)
        setBulkProgress({ done: 0, total: idsToProcess.length })
        for (const id of idsToProcess) {
          try {
            await processMutation.mutateAsync(id)
          } catch {
            // déjà surfacé ligne par ligne (transportErrors/extraction_error) ; on continue
          }
          setBulkProgress((prev) => (prev ? { ...prev, done: prev.done + 1 } : prev))
        }
        setBulkProcessing(false)
      }
    } finally {
      setBulkDiscovering(false)
    }
  }

  // Même logique séquentielle pour l'analyse IA de tous les rapports déjà
  // extraits d'une entreprise (chaque appel est mis en cache par jour côté
  // backend, donc relancer ce bouton plus tard ne refacture pas l'IA pour ce
  // qui a déjà été analysé aujourd'hui).
  async function runBulkAnalyze() {
    if (analyzableReportIds.length === 0) return
    setBulkAnalyzing(true)
    setBulkAnalyzeProgress({ done: 0, total: analyzableReportIds.length, failed: 0 })
    for (const id of analyzableReportIds) {
      let failedThisOne = false
      try {
        const data = await callApi<{ status: string }>('api_report_analysis.php', 'analyze', { report_id: id, provider: 'gemini' })
        if (data.status !== 'success') failedThisOne = true
      } catch {
        failedThisOne = true
      }
      setBulkAnalyzeProgress((prev) => (prev ? { done: prev.done + 1, total: prev.total, failed: prev.failed + (failedThisOne ? 1 : 0) } : prev))
    }
    setBulkAnalyzing(false)
  }

  // Même pattern séquentiel : chaque appel lance un travail de fond côté
  // serveur (peut prendre plusieurs minutes chacun) — on attend la fin
  // (poll) avant de passer au suivant plutôt que de tout lancer en parallèle.
  async function runBulkFormat() {
    if (analyzableReportIds.length === 0) return
    setBulkFormatting(true)
    setBulkFormatProgress({ done: 0, total: analyzableReportIds.length, failed: 0 })
    for (const id of analyzableReportIds) {
      let failedThisOne = false
      try {
        await callApi('api_reports.php', 'format_markdown', { id })
        let status = 'processing'
        while (status === 'processing') {
          await new Promise((resolve) => setTimeout(resolve, 5000))
          const detail = await callApi<ReportDetail>('api_reports.php', 'get', { id })
          status = detail.markdown_status ?? 'failed'
        }
        if (status !== 'success') failedThisOne = true
      } catch {
        failedThisOne = true
      }
      setBulkFormatProgress((prev) => (prev ? { done: prev.done + 1, total: prev.total, failed: prev.failed + (failedThisOne ? 1 : 0) } : prev))
    }
    setBulkFormatting(false)
  }

  if (progressQuery.isLoading) return <LoadingState label="Chargement des rapports…" />
  if (progressQuery.error) return <ErrorState message={(progressQuery.error as Error).message} />
  if (!progressQuery.data) return null

  const { by_company, totals } = progressQuery.data
  const sorted = [...by_company].sort((a, b) => b.pending - a.pending)

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Rapports</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Avancement de la collecte (voir aussi <code>scripts/backfill_reports.php --stats</code>)
        </p>
      </div>

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-5">
        <StatTile label="Rapports découverts" value={totals.total} />
        <StatTile label="Téléchargés" value={totals.downloaded} />
        <StatTile label="Texte extrait" value={totals.extracted} tone="positive" />
        <StatTile label="En erreur" value={totals.errors} tone="negative" />
        <StatTile label="En attente" value={totals.pending} />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[380px_minmax(0,1fr)]">
        <Card title="Par entreprise (triée par nombre en attente)">
          <Table headers={['Symbole', 'Total', 'Extraits', 'Erreurs', 'En attente']}>
            {sorted.map((row) => (
              <tr
                key={row.company_id}
                onClick={() => { setSelectedSymbol(row.symbol); discoverMutation.reset() }}
                className={`cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 ${
                  selectedSymbol === row.symbol ? 'bg-indigo-50 dark:bg-indigo-950' : ''
                }`}
              >
                <td className="px-3 py-2 font-medium">{row.symbol}</td>
                <td className="px-3 py-2">{row.total}</td>
                <td className="px-3 py-2">{row.extracted}</td>
                <td className="px-3 py-2">{row.errors > 0 ? <span className="text-red-600 dark:text-red-400">{row.errors}</span> : 0}</td>
                <td className="px-3 py-2">{row.pending}</td>
              </tr>
            ))}
          </Table>
        </Card>

        <Card>
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-sm font-semibold text-gray-500 dark:text-gray-400">
              {selectedSymbol ? `Rapports — ${selectedSymbol}` : 'Sélectionne une entreprise'}
            </h2>
            {selectedSymbol && (
              <div className="flex flex-wrap gap-2">
                <Button
                  variant="secondary"
                  onClick={() => discoverMutation.mutate(selectedSymbol)}
                  disabled={discoverMutation.isPending}
                >
                  <span className="flex items-center gap-2">
                    <RetryIcon spinning={discoverMutation.isPending} />
                    {discoverMutation.isPending ? 'Vérification…' : 'Vérifier les nouveaux rapports (brvm.org)'}
                  </span>
                </Button>
                <Button
                  onClick={runDiscoverAndDownloadAll}
                  disabled={bulkDiscovering || bulkProcessing}
                  title="Parcourt toutes les pages de pagination brvm.org pour cette entreprise, puis télécharge et extrait le texte des rapports trouvés"
                >
                  <span className="flex items-center gap-2">
                    <RetryIcon spinning={bulkDiscovering || bulkProcessing} />
                    {bulkDiscovering && !bulkProcessing
                      ? 'Recherche sur toutes les pages…'
                      : bulkProcessing && bulkProgress
                      ? `Téléchargement… (${bulkProgress.done}/${bulkProgress.total})`
                      : 'Récupérer + télécharger tous les rapports (toutes les pages)'}
                  </span>
                </Button>
                <Button variant="secondary" onClick={runBulkProcess} disabled={bulkProcessing || pendingReportIds.length === 0}>
                  <span className="flex items-center gap-2">
                    <RetryIcon spinning={bulkProcessing} />
                    {bulkProcessing && bulkProgress
                      ? `Traitement… (${bulkProgress.done}/${bulkProgress.total})`
                      : `Traiter tous les rapports en attente (${pendingReportIds.length})`}
                  </span>
                </Button>
                <Button variant="secondary" onClick={runBulkAnalyze} disabled={bulkAnalyzing || analyzableReportIds.length === 0}>
                  <span className="flex items-center gap-2">
                    <BoltIcon />
                    {bulkAnalyzing && bulkAnalyzeProgress
                      ? `Analyse IA… (${bulkAnalyzeProgress.done}/${bulkAnalyzeProgress.total}${bulkAnalyzeProgress.failed ? `, ${bulkAnalyzeProgress.failed} échec(s)` : ''})`
                      : 'Analyser tous les rapports (IA)'}
                  </span>
                </Button>
                <Button variant="secondary" onClick={runBulkFormat} disabled={bulkFormatting || analyzableReportIds.length === 0}>
                  <span className="flex items-center gap-2">
                    <RetryIcon spinning={bulkFormatting} />
                    {bulkFormatting && bulkFormatProgress
                      ? `Formatage… (${bulkFormatProgress.done}/${bulkFormatProgress.total}${bulkFormatProgress.failed ? `, ${bulkFormatProgress.failed} échec(s)` : ''})`
                      : 'Formater tous les rapports (Markdown)'}
                  </span>
                </Button>
              </div>
            )}
          </div>

          {discoverMutation.isError && (
            <div className="mb-3">
              <ErrorState message={(discoverMutation.error as Error).message} />
            </div>
          )}
          {discoverMutation.isSuccess && (
            <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
              {discoverMutation.data.new_count > 0
                ? `${discoverMutation.data.new_count} nouveau(x) rapport(s) trouvé(s) et ajouté(s) (sur ${discoverMutation.data.total_on_site} au total sur brvm.org).`
                : `Aucun nouveau rapport (${discoverMutation.data.total_on_site} sur brvm.org, tous déjà connus).`}
            </p>
          )}
          {!bulkAnalyzing && bulkAnalyzeProgress && (
            <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
              Analyse IA terminée : {bulkAnalyzeProgress.total - bulkAnalyzeProgress.failed}/{bulkAnalyzeProgress.total} rapport(s) analysé(s) avec succès
              {bulkAnalyzeProgress.failed ? `, ${bulkAnalyzeProgress.failed} échec(s)` : ''}.
            </p>
          )}

          {!selectedSymbol && <p className="text-sm text-gray-500 dark:text-gray-400">Clique une ligne à gauche pour voir le détail.</p>}
          {selectedSymbol && reportsQuery.isLoading && <LoadingState />}
          {selectedSymbol && reportsQuery.error && <ErrorState message={(reportsQuery.error as Error).message} />}
          {selectedSymbol && reportsQuery.data && (
            <Table headers={['Type', 'Titre', 'Date', 'Statut', 'Action']}>
              {reportsQuery.data.map((r) => {
                const result = results[r.id]
                const isPending = pendingId === r.id && (processMutation.isPending || uploadMutation.isPending)
                const extracted = result ? result.status === 'success' : r.text_extracted
                const errorMessage = result ? result.extraction_error : r.extraction_error
                const failed = !extracted && !!errorMessage
                const transportNote = transportErrors[r.id]
                const detailOpen = openErrorId === r.id
                const hasDetail = !!transportNote || (failed && !!errorMessage)

                return (
                  <Fragment key={r.id}>
                    <tr>
                      <td className="px-3 py-2 whitespace-nowrap">{r.report_type}</td>
                      <td className="px-3 py-2 max-w-[240px] truncate" title={r.title}>{r.title}</td>
                      <td className="px-3 py-2 whitespace-nowrap">{r.publish_date ?? '—'}</td>
                      <td className="px-3 py-2">
                        {extracted ? (
                          <span className="text-emerald-600 dark:text-emerald-400">traité</span>
                        ) : failed ? (
                          <span className="text-red-600 dark:text-red-400">échec</span>
                        ) : (
                          <span className="text-amber-600 dark:text-amber-400">en attente</span>
                        )}
                        <MarkdownBadge status={r.markdown_status} />
                        <AnalysisBadge count={r.analyses_count} models={r.analyzed_models} />
                      </td>
                      <td className="px-3 py-2">
                        <div className="flex items-center gap-1">
                          <IconButton title="Consulter le rapport" onClick={() => setViewReportId(r.id)}>
                            <EyeIcon />
                          </IconButton>
                          {extracted && (
                            <IconButton
                              title="Analyser ce rapport avec l'IA"
                              onClick={() => navigate(`/analysis?symbol=${selectedSymbol}&report_id=${r.id}`)}
                            >
                              <BoltIcon />
                            </IconButton>
                          )}
                          {!extracted && (
                            <IconButton
                              title={failed ? 'Réessayer' : 'Traiter (télécharger + extraire le texte)'}
                              onClick={() => processMutation.mutate(r.id)}
                              disabled={isPending}
                            >
                              <RetryIcon spinning={isPending} />
                            </IconButton>
                          )}
                          {failed && (
                            <>
                              <input
                                type="file"
                                accept="application/pdf"
                                className="hidden"
                                ref={(el) => { fileInputs.current[r.id] = el }}
                                onChange={(e) => handleFileChosen(r.id, e)}
                              />
                              <IconButton
                                title="Choisir un autre fichier PDF et le traiter"
                                onClick={() => fileInputs.current[r.id]?.click()}
                                disabled={isPending}
                              >
                                <UploadIcon />
                              </IconButton>
                            </>
                          )}
                          {hasDetail && (
                            <IconButton
                              title="Voir le détail de l'erreur"
                              tone="danger"
                              onClick={() => setOpenErrorId(detailOpen ? null : r.id)}
                            >
                              <InfoIcon />
                            </IconButton>
                          )}
                        </div>
                      </td>
                    </tr>
                    {detailOpen && hasDetail && (
                      <tr>
                        <td colSpan={5} className="bg-red-50 px-3 py-2 dark:bg-red-950/40">
                          <div className="flex items-start justify-between gap-3">
                            <p className="text-xs text-red-700 dark:text-red-300">
                              {transportNote ?? errorMessage}
                            </p>
                            <IconButton title="Fermer" onClick={() => setOpenErrorId(null)}>
                              <CloseIcon />
                            </IconButton>
                          </div>
                        </td>
                      </tr>
                    )}
                  </Fragment>
                )
              })}
            </Table>
          )}
        </Card>
      </div>

      {viewReportId !== null && (
        <Modal
          title="Consulter le rapport"
          onClose={() => { setViewReportId(null); setShowRawText(false) }}
        >
          {viewQuery.isLoading && <LoadingState />}
          {viewQuery.error && <ErrorState message={(viewQuery.error as Error).message} />}
          {viewQuery.data && (
            <div className="flex flex-col gap-3">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="text-sm font-semibold text-gray-900 dark:text-gray-100">{viewQuery.data.title}</p>
                  <p className="text-xs text-gray-500 dark:text-gray-400">
                    {viewQuery.data.company.symbol} · {viewQuery.data.report_type} · {viewQuery.data.publish_date ?? 'date inconnue'}
                    {viewQuery.data.char_count ? ` · ${viewQuery.data.char_count.toLocaleString('fr-FR')} caractères extraits` : ''}
                  </p>
                </div>
                {viewQuery.data.markdown_status === 'success' && (
                  <Button variant="secondary" onClick={() => setShowRawText((v) => !v)}>
                    {showRawText ? 'Voir la version formatée' : 'Voir le texte brut'}
                  </Button>
                )}
              </div>

              <div className="flex flex-wrap items-center gap-2">
                <a
                  href={reportDownloadUrl(viewQuery.data.id)}
                  target="_blank"
                  rel="noreferrer"
                  className="inline-flex w-fit items-center gap-2 rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
                >
                  <EyeIcon /> Ouvrir le PDF original
                </a>
                {viewQuery.data.text_extracted && viewQuery.data.markdown_status !== 'success' && viewQuery.data.markdown_status !== 'processing' && (
                  <Button variant="secondary" onClick={() => formatMarkdownMutation.mutate(viewQuery.data!.id)} disabled={formatMarkdownMutation.isPending}>
                    <span className="flex items-center gap-2">
                      <RetryIcon spinning={formatMarkdownMutation.isPending} />
                      {viewQuery.data.markdown_status === 'failed' ? 'Réessayer le formatage' : 'Formater en tableaux'}
                    </span>
                  </Button>
                )}
                {viewQuery.data.markdown_status === 'success' && viewQuery.data.formatted_markdown && (
                  <Button
                    variant="secondary"
                    onClick={() => downloadMarkdownFile(viewQuery.data!.title, viewQuery.data!.formatted_markdown!)}
                  >
                    Télécharger le markdown
                  </Button>
                )}
                <input
                  type="file"
                  accept=".md,.markdown,.txt"
                  className="hidden"
                  ref={markdownFileInput}
                  onChange={(e) => {
                    const file = e.target.files?.[0]
                    if (file && viewQuery.data) uploadMarkdownMutation.mutate({ id: viewQuery.data.id, file })
                    e.target.value = ''
                  }}
                />
                <Button
                  variant="secondary"
                  onClick={() => markdownFileInput.current?.click()}
                  disabled={uploadMarkdownMutation.isPending}
                >
                  <span className="flex items-center gap-2">
                    <UploadIcon />
                    {uploadMarkdownMutation.isPending ? 'Import…' : 'Importer un markdown'}
                  </span>
                </Button>
              </div>
              {uploadMarkdownMutation.isError && (
                <ErrorState message={(uploadMarkdownMutation.error as Error).message} />
              )}

              {viewQuery.data.markdown_status === 'processing' && (
                <p className="text-xs text-amber-600 dark:text-amber-400">
                  Formatage en cours (peut prendre plusieurs minutes)… la page se met à jour automatiquement.
                </p>
              )}
              {viewQuery.data.markdown_status === 'failed' && (
                <ErrorState message={viewQuery.data.markdown_error ?? "Échec du formatage markdown"} />
              )}

              {viewQuery.data.markdown_status === 'success' && viewQuery.data.formatted_markdown && !showRawText ? (
                <div className="max-h-[60vh] overflow-y-auto rounded-md border border-gray-200 p-3 dark:border-gray-800">
                  <ReactMarkdown
                    remarkPlugins={[remarkGfm]}
                    components={{
                      table: (props) => <table className="mb-3 w-full border-collapse text-xs" {...props} />,
                      th: (props) => <th className="border border-gray-200 bg-gray-50 px-2 py-1 text-left font-semibold dark:border-gray-700 dark:bg-gray-800" {...props} />,
                      td: (props) => <td className="border border-gray-200 px-2 py-1 dark:border-gray-700" {...props} />,
                      h1: (props) => <h1 className="mb-2 text-lg font-bold" {...props} />,
                      h2: (props) => <h2 className="mb-2 mt-4 text-base font-semibold" {...props} />,
                      h3: (props) => <h3 className="mb-1 mt-3 text-sm font-semibold" {...props} />,
                      h4: (props) => <h4 className="mb-1 mt-2 text-sm font-semibold" {...props} />,
                      p: (props) => <p className="mb-2 text-sm" {...props} />,
                    }}
                  >
                    {viewQuery.data.formatted_markdown}
                  </ReactMarkdown>
                </div>
              ) : viewQuery.data.text_extracted && viewQuery.data.extracted_text ? (
                <pre className="max-h-[50vh] overflow-y-auto whitespace-pre-wrap rounded-md bg-gray-50 p-3 text-xs text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                  {viewQuery.data.extracted_text}
                </pre>
              ) : (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  {viewQuery.data.extraction_error
                    ? `Texte non disponible (${viewQuery.data.extraction_error}). Ouvre le PDF original ci-dessus pour le consulter.`
                    : "Texte pas encore extrait pour ce rapport. Ouvre le PDF original ci-dessus pour le consulter."}
                </p>
              )}
            </div>
          )}
        </Modal>
      )}
    </div>
  )
}
