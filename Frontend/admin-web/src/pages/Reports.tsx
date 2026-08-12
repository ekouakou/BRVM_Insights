import { Fragment, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { BarChart, Bar, LineChart, Line, ResponsiveContainer, XAxis, YAxis, Tooltip, CartesianGrid } from 'recharts'
import { callApi, reportDownloadUrl, uploadFile } from '../lib/apiClient'
import type { BackfillCompanyProgress, ComparisonResult, CompanyMatchResult, DiscoverResult, ReportDetail, ReportProcessResult, ReportSummary } from '../lib/types'
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
  const [bulkAllRunning, setBulkAllRunning] = useState(false)
  const [bulkAllStatus, setBulkAllStatus] = useState<string | null>(null)
  const [bulkAllSummary, setBulkAllSummary] = useState<{
    matched: number
    review: CompanyMatchResult['review']
    companies: number
    newReports: number
    processed: number
    failed: number
  } | null>(null)
  const [selectedReportIds, setSelectedReportIds] = useState<number[]>([])
  const [showReportComparison, setShowReportComparison] = useState(false)
  const [preAnalyzeProgress, setPreAnalyzeProgress] = useState<{ done: number; total: number } | null>(null)
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

  // Analyse groupée d'une sélection explicite de rapports (checkboxes du
  // tableau, éventuellement à cheval sur plusieurs entreprises visitées) —
  // voir ReportComparisonService::compare() côté backend, mode report_ids.
  const reportComparisonMutation = useMutation({
    mutationFn: (forceRefresh: boolean) =>
      callApi<ComparisonResult>('api_report_comparison.php', 'compare', {
        report_ids: selectedReportIds,
        provider: 'gemini',
        force_refresh: forceRefresh,
      }),
  })

  function toggleReportSelection(id: number) {
    setSelectedReportIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  // Pré-analyse chaque rapport sélectionné individuellement (séquentiel,
  // depuis le frontend) AVANT d'appeler compare() : ReportComparisonService
  // déclenche sinon ces mêmes analyses dans une seule requête serveur, ce qui
  // dépasse facilement le délai d'inactivité fastcgi de MAMP (30s, voir
  // apache_error.log) ou le max_execution_time de 30s courant sur un
  // hébergement mutualisé dès que 2-3 rapports n'ont jamais été analysés. Une
  // fois chaque rapport en cache, l'appel compare() final reste rapide (une
  // seule synthèse IA, pas de ré-analyse).
  async function openReportComparison() {
    setShowReportComparison(true)
    setPreAnalyzeProgress({ done: 0, total: selectedReportIds.length })
    try {
      for (const id of selectedReportIds) {
        try {
          await callApi('api_report_analysis.php', 'analyze', { report_id: id, provider: 'gemini' })
        } catch {
          // Erreur remontée pour de bon par compare() ensuite (skipped_reports côté service) ; on continue.
        }
        setPreAnalyzeProgress((prev) => (prev ? { ...prev, done: prev.done + 1 } : prev))
      }
    } finally {
      setPreAnalyzeProgress(null)
    }
    reportComparisonMutation.mutate(false)
  }

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

  // Rattache d'abord les entreprises sans brvm_report_slug à l'annuaire
  // brvm.org (voir CompanySlugMatcher/api_reports.php::matchCompanies), puis
  // enchaîne — pour CHAQUE entreprise ainsi rattachée (+ celles déjà
  // rattachées) — la découverte (toutes les pages) et le téléchargement des
  // rapports en attente. Purement séquentiel côté frontend (une requête à la
  // fois) : équivalent web de `php scripts/backfill_reports.php`, sur un
  // hébergement sans accès CLI. Peut prendre longtemps pour beaucoup
  // d'entreprises — garder l'onglet ouvert jusqu'à la fin.
  async function runMatchAndDownloadAll() {
    setBulkAllRunning(true)
    setBulkAllSummary(null)
    try {
      setBulkAllStatus('Rattachement des entreprises (annuaire brvm.org)…')
      const matchResult = await callApi<CompanyMatchResult>('api_reports.php', 'match_companies')

      setBulkAllStatus('Récupération de la liste des entreprises…')
      const companies = await callApi<{ company_id: number; symbol: string; brvm_report_slug: string | null }[]>(
        'api_reports.php',
        'list_companies'
      )
      const withSlug = companies.filter((c) => c.brvm_report_slug)

      let newReportsTotal = 0
      let processedTotal = 0
      let failedTotal = 0

      for (let i = 0; i < withSlug.length; i++) {
        const c = withSlug[i]
        setBulkAllStatus(`Entreprise ${i + 1}/${withSlug.length} — ${c.symbol} : recherche des rapports…`)

        let discovered: DiscoverResult
        try {
          discovered = await callApi<DiscoverResult>('api_reports.php', 'discover_new', { symbol: c.symbol })
          newReportsTotal += discovered.new_count
        } catch {
          continue // entreprise suivante si la découverte échoue (ex: page indisponible)
        }

        const freshReports = await callApi<ReportSummary[]>('api_reports.php', 'list', { symbol: c.symbol })
        const pending = freshReports.filter((r) => !r.text_extracted)

        for (let j = 0; j < pending.length; j++) {
          setBulkAllStatus(`Entreprise ${i + 1}/${withSlug.length} — ${c.symbol} : téléchargement ${j + 1}/${pending.length}`)
          try {
            const result = await callApi<ReportProcessResult>('api_reports.php', 'process', { id: pending[j].id })
            if (result.status === 'success') processedTotal++
            else failedTotal++
          } catch {
            failedTotal++
          }
        }
      }

      setBulkAllSummary({
        matched: matchResult.assigned.length,
        review: matchResult.review,
        companies: withSlug.length,
        newReports: newReportsTotal,
        processed: processedTotal,
        failed: failedTotal,
      })
      queryClient.invalidateQueries({ queryKey: ['backfill-progress'] })
      queryClient.invalidateQueries({ queryKey: ['reports-list', selectedSymbol] })
    } finally {
      setBulkAllRunning(false)
      setBulkAllStatus(null)
    }
  }

  if (progressQuery.isLoading) return <LoadingState label="Chargement des rapports…" />
  if (progressQuery.error) return <ErrorState message={(progressQuery.error as Error).message} />
  if (!progressQuery.data) return null

  const { by_company, totals } = progressQuery.data
  const sorted = [...by_company].sort((a, b) => b.pending - a.pending)

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold">Rapports</h2>
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Avancement de la collecte (voir aussi <code>scripts/backfill_reports.php --stats</code>)
          </p>
        </div>
        <Button onClick={runMatchAndDownloadAll} disabled={bulkAllRunning}>
          <span className="flex items-center gap-2">
            <RetryIcon spinning={bulkAllRunning} />
            {bulkAllRunning ? (bulkAllStatus ?? 'En cours…') : 'Rattacher les entreprises et charger tous les rapports'}
          </span>
        </Button>
      </div>

      {bulkAllSummary && (
        <div className="rounded-md border border-gray-300 bg-gray-100 p-3 text-sm dark:border-gray-700 dark:bg-gray-800/60">
          <p className="text-gray-900 dark:text-gray-100">
            {bulkAllSummary.matched} entreprise(s) nouvellement rattachée(s) à l'annuaire brvm.org ·{' '}
            {bulkAllSummary.companies} entreprise(s) traitée(s) au total · {bulkAllSummary.newReports} nouveau(x) rapport(s)
            trouvé(s) · {bulkAllSummary.processed} téléchargé(s)/extrait(s) avec succès
            {bulkAllSummary.failed ? `, ${bulkAllSummary.failed} échec(s)` : ''}.
          </p>
          {bulkAllSummary.review.length > 0 && (
            <div className="mt-2">
              <p className="text-xs font-medium text-gray-900 dark:text-gray-100">
                {bulkAllSummary.review.length} entreprise(s) à rattacher manuellement (correspondance incertaine) — voir
                <code className="mx-1">companies.brvm_report_slug</code>en base :
              </p>
              <ul className="mt-1 list-disc pl-4 text-xs text-gray-900 dark:text-gray-200">
                {bulkAllSummary.review.map((r) => (
                  <li key={r.symbol}>
                    {r.symbol} — suggestion : {r.suggestion ?? '—'} ({r.score}%)
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}

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
                  selectedSymbol === row.symbol ? 'bg-gray-100 dark:bg-gray-800' : ''
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
                <Button onClick={openReportComparison} disabled={selectedReportIds.length === 0}>
                  <span className="flex items-center gap-2">
                    <BoltIcon />
                    {`Analyser les rapports sélectionnés (${selectedReportIds.length})`}
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
            <Table headers={['', 'Type', 'Titre', 'Date', 'Statut', 'Action']}>
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
                      <td className="px-3 py-2">
                        <input
                          type="checkbox"
                          checked={selectedReportIds.includes(r.id)}
                          disabled={!extracted}
                          onChange={() => toggleReportSelection(r.id)}
                          title={extracted ? 'Sélectionner pour analyse groupée' : 'Traite ce rapport avant de le sélectionner'}
                        />
                      </td>
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
                        <td colSpan={6} className="bg-red-50 px-3 py-2 dark:bg-red-950/40">
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

      {showReportComparison && (
        <Modal title="Analyse groupée des rapports sélectionnés" onClose={() => setShowReportComparison(false)}>
          {preAnalyzeProgress && (
            <LoadingState label={`Analyse individuelle des rapports… (${preAnalyzeProgress.done}/${preAnalyzeProgress.total})`} />
          )}
          {!preAnalyzeProgress && reportComparisonMutation.isPending && (
            <LoadingState label="Synthèse comparative en cours…" />
          )}
          {reportComparisonMutation.isError && <ErrorState message={(reportComparisonMutation.error as Error).message} />}
          {reportComparisonMutation.data && reportComparisonMutation.data.status === 'failed' && (
            <ErrorState message={reportComparisonMutation.data.error_message ?? "Échec de l'analyse"} />
          )}
          {reportComparisonMutation.data && reportComparisonMutation.data.analysis && (
            <div className="flex flex-col gap-6">
              <div className="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                <span>{reportComparisonMutation.data.companies.map((c) => c.symbol).join(', ')}</span>
                <span className="flex items-center gap-2">
                  {reportComparisonMutation.data.provider}/{reportComparisonMutation.data.model}
                  {reportComparisonMutation.data.cached && ' · depuis le cache'}
                  <Button variant="secondary" onClick={() => reportComparisonMutation.mutate(true)} disabled={reportComparisonMutation.isPending}>
                    Forcer
                  </Button>
                </span>
              </div>

              <Card>
                <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">
                  {reportComparisonMutation.data.analysis.comparative_summary}
                </p>
              </Card>

              {reportComparisonMutation.data.analysis.cross_company_ranking && (
                <Card title="Classement comparatif">
                  <p className="text-sm text-gray-800 dark:text-gray-200">{reportComparisonMutation.data.analysis.cross_company_ranking}</p>
                </Card>
              )}

              {reportComparisonMutation.data.analysis.trend_analysis && reportComparisonMutation.data.analysis.trend_analysis.length > 0 && (
                <Card title="Tendance">
                  <div className="flex flex-col gap-4">
                    {reportComparisonMutation.data.analysis.trend_analysis.map((t) => (
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

              <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                {reportComparisonMutation.data.analysis.price_correlation_note && (
                  <Card title="Corrélation cours / fondamentaux">
                    <p className="text-sm text-gray-800 dark:text-gray-200">{reportComparisonMutation.data.analysis.price_correlation_note}</p>
                  </Card>
                )}
                {reportComparisonMutation.data.analysis.risks_evolution && (
                  <Card title="Évolution des risques">
                    <p className="text-sm text-gray-800 dark:text-gray-200">{reportComparisonMutation.data.analysis.risks_evolution}</p>
                  </Card>
                )}
              </div>

              {reportComparisonMutation.data.analysis.decision_support_notes && reportComparisonMutation.data.analysis.decision_support_notes.length > 0 && (
                <Card title="Points d'appui à la décision">
                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {reportComparisonMutation.data.analysis.decision_support_notes.map((d) => (
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

              {reportComparisonMutation.data.chart_data?.price_series.map((serie) => (
                serie.data.length >= 2 && (
                  <Card key={serie.company_id} title={`Cours — ${serie.symbol}`}>
                    <ResponsiveContainer width="100%" height={200}>
                      <LineChart data={serie.data}>
                        <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                        <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                        <YAxis domain={['auto', 'auto']} tick={{ fontSize: 11 }} width={70} />
                        <Tooltip />
                        <Line type="monotone" dataKey="close" stroke="var(--chart-1)" dot={false} strokeWidth={2} />
                      </LineChart>
                    </ResponsiveContainer>
                  </Card>
                )
              ))}

              {reportComparisonMutation.data.chart_data?.financials_series.map((serie) => (
                serie.data.length > 0 && (
                  <Card key={serie.company_id} title={`Chiffres clés dans le temps — ${serie.symbol}`}>
                    <ResponsiveContainer width="100%" height={220}>
                      <BarChart data={serie.data}>
                        <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                        <XAxis dataKey="publish_date" tick={{ fontSize: 11 }} />
                        <YAxis tick={{ fontSize: 11 }} width={80} />
                        <Tooltip />
                        <Bar dataKey="revenue" fill="var(--chart-1)" name="Chiffre d'affaires" />
                        <Bar dataKey="net_income" fill="var(--chart-soft)" name="Résultat net" />
                      </BarChart>
                    </ResponsiveContainer>
                  </Card>
                )
              ))}

              <p className="text-xs italic text-gray-400">{reportComparisonMutation.data.disclaimer}</p>
            </div>
          )}
        </Modal>
      )}
    </div>
  )
}
