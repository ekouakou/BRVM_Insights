import { Fragment, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BarChart, Bar, LineChart, Line, PieChart, Pie, Cell, ResponsiveContainer, XAxis, YAxis, Tooltip, Legend, CartesianGrid } from 'recharts'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { bulletinDownloadUrl, callApi, uploadFile } from '../lib/apiClient'
import type {
  BulletinAnalysis,
  BulletinAnalysisStats,
  BulletinComparisonResult,
  BulletinDetail,
  BulletinDiscoverResult,
  BulletinLookupResult,
  BulletinProcessResult,
  BulletinStats,
  BulletinSummary,
} from '../lib/types'
import { AnalysisBadge, Button, Card, ErrorState, Input, LoadingState, MarkdownBadge, Modal, StatTile, Table } from '../components/ui'
import { BoltIcon, CloseIcon, EyeIcon, IconButton, InfoIcon, RetryIcon, UploadIcon } from '../components/icons'
import { ChartAiAnalysis } from '../components/ChartAiAnalysis'

const INDEX_COLORS: Record<string, string> = {
  'BRVM-COMPOSITE': '#4f46e5',
  'BRVM-30': '#0ea5e9',
  'BRVM-PRESTIGE': '#f59e0b',
  'BRVM-PRINCIPAL': '#10b981',
}
const FALLBACK_COLORS = ['#4f46e5', '#0ea5e9', '#f59e0b', '#10b981', '#ec4899', '#8b5cf6']

/** Couleurs du camembert de sentiment de marché — haussier/baissier ont un sens directionnel clair (vert/rouge), mixte/indéterminé restent neutres. */
function sentimentColor(sentiment: string) {
  switch (sentiment) {
    case 'haussier':
      return '#059669'
    case 'baissier':
      return '#dc2626'
    case 'mixte':
      return '#f59e0b'
    default:
      return '#6b7280'
  }
}

export function Bulletins() {
  const [results, setResults] = useState<Record<number, BulletinProcessResult>>({})
  const [pendingId, setPendingId] = useState<number | null>(null)
  const [transportErrors, setTransportErrors] = useState<Record<number, string>>({})
  const [openErrorId, setOpenErrorId] = useState<number | null>(null)
  const [viewBulletinId, setViewBulletinId] = useState<number | null>(null)
  const [analyzeBulletinId, setAnalyzeBulletinId] = useState<number | null>(null)
  const [bulkProcessing, setBulkProcessing] = useState(false)
  const [bulkProgress, setBulkProgress] = useState<{ done: number; total: number } | null>(null)
  const [bulkAnalyzing, setBulkAnalyzing] = useState(false)
  const [bulkAnalyzeProgress, setBulkAnalyzeProgress] = useState<{ done: number; total: number; failed: number } | null>(null)
  const [bulkFormatting, setBulkFormatting] = useState(false)
  const [bulkFormatProgress, setBulkFormatProgress] = useState<{ done: number; total: number; failed: number } | null>(null)
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const [showComparison, setShowComparison] = useState(false)
  const [lookupDate, setLookupDate] = useState('')
  const [showRawText, setShowRawText] = useState(false)
  const [isEditingMarkdown, setIsEditingMarkdown] = useState(false)
  const [editedMarkdown, setEditedMarkdown] = useState('')
  const fileInputs = useRef<Record<number, HTMLInputElement | null>>({})
  const markdownFileInput = useRef<HTMLInputElement | null>(null)
  const queryClient = useQueryClient()

  const statsQuery = useQuery({
    queryKey: ['bulletin-stats'],
    queryFn: () => callApi<BulletinStats>('api_bulletins.php', 'stats'),
  })

  // Statistiques des analyses IA déjà réalisées (pas de filtre de période ici,
  // porte sur tout l'historique déjà analysé) — recalculées côté backend à
  // chaque appel, donc tout nouveau bulletin analysé apparaît automatiquement.
  const aiStatsQuery = useQuery({
    queryKey: ['bulletin-ai-stats'],
    queryFn: () => callApi<BulletinAnalysisStats>('api_bulletin_analysis.php', 'stats'),
  })

  const listQuery = useQuery({
    queryKey: ['bulletins-list'],
    queryFn: () => callApi<BulletinSummary[]>('api_bulletins.php', 'list'),
  })

  const viewQuery = useQuery({
    queryKey: ['bulletin-detail', viewBulletinId],
    queryFn: () => callApi<BulletinDetail>('api_bulletins.php', 'get', { id: viewBulletinId }),
    enabled: viewBulletinId !== null,
    // La restructuration markdown tourne en arrière-plan côté serveur (~2 min,
    // voir api_bulletins.php action format_markdown) : on ré-interroge tant
    // qu'elle est en cours, on s'arrête dès qu'un statut final est atteint.
    refetchInterval: (query) => (query.state.data?.markdown_status === 'processing' ? 4000 : false),
  })

  const formatMarkdownMutation = useMutation({
    mutationFn: (id: number) => callApi<{ id: number; status: string }>('api_bulletins.php', 'format_markdown', { id }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bulletin-detail', viewBulletinId] })
    },
  })

  const uploadMarkdownMutation = useMutation({
    mutationFn: ({ id, file }: { id: number; file: File }) =>
      uploadFile<{ id: number; status: string }>('api_bulletins.php', 'upload_markdown', file, { id }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bulletin-detail', viewBulletinId] })
      queryClient.invalidateQueries({ queryKey: ['bulletins-list'] })
    },
  })

  /**
   * Enregistre une correction saisie directement dans l'app — réutilise
   * l'action 'upload_markdown' déjà existante (voir uploadMarkdownMutation
   * ci-dessus, jusqu'ici réservée à l'import d'un fichier .md externe) en
   * empaquetant le texte corrigé dans un File synthétique côté client :
   * aucun changement backend nécessaire, même pattern que sur la page Rapports.
   */
  function saveEditedMarkdown() {
    if (!viewQuery.data) return
    const file = new File([editedMarkdown], `bulletin-${viewQuery.data.publish_date}.md`, { type: 'text/markdown' })
    uploadMarkdownMutation.mutate(
      { id: viewQuery.data.id, file },
      { onSuccess: () => setIsEditingMarkdown(false) },
    )
  }

  function downloadMarkdownFile(publishDate: string, markdown: string) {
    const blob = new Blob([markdown], { type: 'text/markdown;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `bulletin-${publishDate}.md`
    a.click()
    URL.revokeObjectURL(url)
  }

  const analyzeQuery = useQuery({
    queryKey: ['bulletin-analysis', analyzeBulletinId],
    queryFn: () => callApi<BulletinAnalysis>('api_bulletin_analysis.php', 'analyze', { bulletin_id: analyzeBulletinId, provider: 'gemini' }),
    enabled: analyzeBulletinId !== null,
  })

  // Relance une analyse en ignorant le cache serveur (force_refresh, voir
  // MarketBulletinAnalysisService::analyze()) — le résultat remplace celui
  // en cache react-query pour ce bulletin plutôt que de déclencher un
  // second fetch qui reviendrait sinon sur le cache serveur (non forcé).
  const reanalyzeMutation = useMutation({
    mutationFn: (id: number) => callApi<BulletinAnalysis>('api_bulletin_analysis.php', 'analyze', { bulletin_id: id, provider: 'gemini', force_refresh: true }),
    onSuccess: (data, id) => {
      queryClient.setQueryData(['bulletin-analysis', id], data)
      queryClient.invalidateQueries({ queryKey: ['bulletins-list'] })
    },
  })

  const discoverMutation = useMutation({
    mutationFn: () => callApi<BulletinDiscoverResult>('api_bulletins.php', 'discover_new'),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bulletins-list'] })
      queryClient.invalidateQueries({ queryKey: ['bulletin-stats'] })
    },
  })

  const lookupMutation = useMutation({
    mutationFn: (date: string) => callApi<BulletinLookupResult>('api_bulletins.php', 'lookup_by_date', { date }),
    onSuccess: (result) => {
      if (result.found) {
        queryClient.invalidateQueries({ queryKey: ['bulletins-list'] })
        queryClient.invalidateQueries({ queryKey: ['bulletin-stats'] })
      }
    },
  })

  const compareMutation = useMutation({
    mutationFn: (forceRefresh: boolean) =>
      callApi<BulletinComparisonResult>('api_bulletin_comparison.php', 'compare', {
        bulletin_ids: selectedIds,
        provider: 'gemini',
        force_refresh: forceRefresh,
      }),
  })

  function toggleSelect(id: number) {
    setSelectedIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  function openComparison() {
    setShowComparison(true)
    compareMutation.mutate(false)
  }

  function onProcessed(result: BulletinProcessResult) {
    setResults((prev) => ({ ...prev, [result.id]: result }))
    setPendingId(null)
    if (result.status === 'success') {
      setOpenErrorId((prev) => (prev === result.id ? null : prev))
    }
    queryClient.invalidateQueries({ queryKey: ['bulletins-list'] })
    queryClient.invalidateQueries({ queryKey: ['bulletin-stats'] })
  }

  // Une erreur de transport (délai MAMP dépassé, réponse non-JSON) ne veut pas
  // dire que le traitement a échoué côté serveur — voir apiClient.ts. On
  // rafraîchit donc la liste pour refléter l'état réel plutôt que de figer un
  // statut "échec" potentiellement faux.
  function onTransportError(id: number, error: unknown) {
    setPendingId(null)
    setTransportErrors((prev) => ({ ...prev, [id]: (error as Error).message }))
    queryClient.invalidateQueries({ queryKey: ['bulletins-list'] })
    queryClient.invalidateQueries({ queryKey: ['bulletin-stats'] })
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
    mutationFn: (id: number) => callApi<BulletinProcessResult>('api_bulletins.php', 'process', { id }),
    onMutate: (id) => { setPendingId(id); clearTransportError(id) },
    onSuccess: onProcessed,
    onError: (error, id) => onTransportError(id, error),
  })

  const uploadMutation = useMutation({
    mutationFn: ({ id, file }: { id: number; file: File }) =>
      uploadFile<BulletinProcessResult>('api_bulletins.php', 'upload_replacement', file, { id }),
    onMutate: ({ id }) => { setPendingId(id); clearTransportError(id) },
    onSuccess: onProcessed,
    onError: (error, { id }) => onTransportError(id, error),
  })

  function handleFileChosen(id: number, e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    if (file) uploadMutation.mutate({ id, file })
    e.target.value = ''
  }

  function isRowExtracted(b: BulletinSummary): boolean {
    const result = results[b.id]
    return result ? result.status === 'success' : b.text_extracted
  }

  const pendingBulletinIds = (listQuery.data ?? []).filter((b) => !isRowExtracted(b)).map((b) => b.id)
  const analyzableBulletinIds = (listQuery.data ?? []).filter((b) => isRowExtracted(b)).map((b) => b.id)

  // Équivalent "traiter tout" : appelle process séquentiellement (une requête
  // par bulletin) plutôt qu'un seul gros appel backend — un traitement par
  // lot ferait facilement dépasser les 30s d'idle timeout de MAMP (mod_fastcgi).
  async function runBulkProcess() {
    if (pendingBulletinIds.length === 0) return
    setBulkProcessing(true)
    setBulkProgress({ done: 0, total: pendingBulletinIds.length })
    for (const id of pendingBulletinIds) {
      try {
        await processMutation.mutateAsync(id)
      } catch {
        // déjà surfacé ligne par ligne (transportErrors/extraction_error) ; on continue
      }
      setBulkProgress((prev) => (prev ? { ...prev, done: prev.done + 1 } : prev))
    }
    setBulkProcessing(false)
  }

  // Même logique séquentielle pour l'analyse IA de tous les bulletins déjà
  // extraits (chaque appel est mis en cache côté backend, donc relancer ce
  // bouton plus tard ne refacture pas l'IA pour ce qui est déjà analysé).
  async function runBulkAnalyze() {
    if (analyzableBulletinIds.length === 0) return
    setBulkAnalyzing(true)
    setBulkAnalyzeProgress({ done: 0, total: analyzableBulletinIds.length, failed: 0 })
    for (const id of analyzableBulletinIds) {
      let failedThisOne = false
      try {
        const data = await callApi<{ status: string }>('api_bulletin_analysis.php', 'analyze', { bulletin_id: id, provider: 'gemini' })
        if (data.status !== 'success') failedThisOne = true
      } catch {
        failedThisOne = true
      }
      setBulkAnalyzeProgress((prev) => (prev ? { done: prev.done + 1, total: prev.total, failed: prev.failed + (failedThisOne ? 1 : 0) } : prev))
    }
    setBulkAnalyzing(false)
  }

  // Même pattern séquentiel, mais chaque appel lance un travail de fond
  // (~2 min chacun côté serveur) : on attend la fin (poll) avant de passer
  // au suivant plutôt que de tout lancer en parallèle, pour rester cohérent
  // avec le reste de l'UI — nettement plus lent que les autres boutons "tout"
  // pour beaucoup de bulletins, mais c'est attendu vu le temps par bulletin.
  async function runBulkFormat() {
    if (analyzableBulletinIds.length === 0) return
    setBulkFormatting(true)
    setBulkFormatProgress({ done: 0, total: analyzableBulletinIds.length, failed: 0 })
    for (const id of analyzableBulletinIds) {
      let failedThisOne = false
      try {
        await callApi('api_bulletins.php', 'format_markdown', { id })
        let status = 'processing'
        while (status === 'processing') {
          await new Promise((resolve) => setTimeout(resolve, 5000))
          const detail = await callApi<BulletinDetail>('api_bulletins.php', 'get', { id })
          status = detail.markdown_status ?? 'failed'
        }
        if (status !== 'success') failedThisOne = true
      } catch {
        failedThisOne = true
      }
      setBulkFormatProgress((prev) => (prev ? { done: prev.done + 1, total: prev.total, failed: prev.failed + (failedThisOne ? 1 : 0) } : prev))
    }
    setBulkFormatting(false)
    queryClient.invalidateQueries({ queryKey: ['bulletins-list'] })
  }

  if (statsQuery.isLoading) return <LoadingState label="Chargement des bulletins…" />
  if (statsQuery.error) return <ErrorState message={(statsQuery.error as Error).message} />
  if (!statsQuery.data) return null

  const stats = statsQuery.data

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Bulletins Officiels de la Cote</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Bulletins quotidiens scrapés depuis brvm.org (les ~10-11 derniers jours de bourse) — téléchargement, extraction et analyse IA
        </p>
      </div>

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-5">
        <StatTile label="Bulletins découverts" value={stats.total} />
        <StatTile label="Téléchargés" value={stats.downloaded} />
        <StatTile label="Texte extrait" value={stats.extracted} tone="positive" />
        <StatTile label="En erreur" value={stats.errors} tone="negative" />
        <StatTile label="En attente" value={stats.pending} />
      </div>

      {aiStatsQuery.isLoading && <LoadingState label="Chargement de la synthèse des analyses IA…" />}
      {aiStatsQuery.error && <ErrorState message={(aiStatsQuery.error as Error).message} />}

      {aiStatsQuery.data && (
        <Card title="Synthèse des analyses IA">
          <p className="mb-4 text-xs text-gray-500 dark:text-gray-400">
            Calculée à partir des <strong>{aiStatsQuery.data.analyzed_bulletins}</strong> bulletin(s) déjà analysé(s)
            par l'IA sur les <strong>{aiStatsQuery.data.total_bulletins}</strong> bulletins connus — se met à jour
            automatiquement à mesure que d'autres bulletins sont analysés, une seule analyse (la plus récente)
            comptant par bulletin. Portée marché entier (chaque bulletin couvre toute la séance), pas une
            entreprise en particulier.
          </p>

          {aiStatsQuery.data.analyzed_bulletins === 0 ? (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              Aucun bulletin encore analysé par l'IA — les graphiques apparaîtront ici dès qu'au moins un bulletin
              sera analysé (bouton "Analyser" dans la liste ci-dessous).
            </p>
          ) : (
            <>
              <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div>
                  <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                    Couverture des analyses
                  </h4>
                  <ResponsiveContainer width="100%" height={200}>
                    <PieChart>
                      <Pie
                        data={[
                          { name: 'Analysés', value: aiStatsQuery.data.analyzed_bulletins },
                          { name: 'En attente', value: aiStatsQuery.data.pending_bulletins },
                        ]}
                        dataKey="value"
                        nameKey="name"
                        cx="50%"
                        cy="50%"
                        innerRadius={45}
                        outerRadius={75}
                        paddingAngle={2}
                      >
                        <Cell fill="var(--chart-1)" />
                        <Cell fill="var(--chart-muted)" />
                      </Pie>
                      <Legend />
                      <Tooltip />
                    </PieChart>
                  </ResponsiveContainer>
                </div>

                {aiStatsQuery.data.sentiment_distribution.length > 0 && (
                  <div>
                    <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                      Répartition du sentiment de marché
                    </h4>
                    <ResponsiveContainer width="100%" height={200}>
                      <PieChart>
                        <Pie
                          data={aiStatsQuery.data.sentiment_distribution}
                          dataKey="count"
                          nameKey="sentiment"
                          cx="50%"
                          cy="50%"
                          innerRadius={45}
                          outerRadius={75}
                          paddingAngle={2}
                        >
                          {aiStatsQuery.data.sentiment_distribution.map((s) => (
                            <Cell key={s.sentiment} fill={sentimentColor(s.sentiment)} />
                          ))}
                        </Pie>
                        <Legend />
                        <Tooltip />
                      </PieChart>
                    </ResponsiveContainer>
                  </div>
                )}
              </div>

              {aiStatsQuery.data.market_trend.filter((m) => m.advancers_count !== null).length > 1 && (
                <div className="mt-6">
                  <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                    Respiration du marché (hausses / baisses / inchangés) par bulletin analysé
                  </h4>
                  <ResponsiveContainer width="100%" height={220}>
                    <BarChart data={aiStatsQuery.data.market_trend}>
                      <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                      <XAxis dataKey="publish_date" tick={{ fontSize: 11 }} minTickGap={30} />
                      <YAxis tick={{ fontSize: 11 }} width={40} allowDecimals={false} />
                      <Tooltip />
                      <Legend />
                      <Bar dataKey="advancers_count" name="Hausses" stackId="breadth" fill="var(--chart-positive)" />
                      <Bar dataKey="unchanged_count" name="Inchangés" stackId="breadth" fill="var(--chart-muted)" />
                      <Bar dataKey="decliners_count" name="Baisses" stackId="breadth" fill="var(--chart-negative)" />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              )}

              {aiStatsQuery.data.market_trend.filter((m) => m.total_volume !== null).length > 1 && (
                <div className="mt-6">
                  <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                    Volume total échangé par bulletin analysé
                  </h4>
                  <ResponsiveContainer width="100%" height={200}>
                    <BarChart data={aiStatsQuery.data.market_trend}>
                      <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                      <XAxis dataKey="publish_date" tick={{ fontSize: 11 }} minTickGap={30} />
                      <YAxis tick={{ fontSize: 11 }} width={60} tickFormatter={(v: number) => `${(v / 1_000_000).toLocaleString('fr-FR')}M`} />
                      <Tooltip formatter={(value) => Number(value).toLocaleString('fr-FR')} />
                      <Bar dataKey="total_volume" name="Volume total" fill="var(--chart-1)" radius={[4, 4, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              )}

              <ChartAiAnalysis chartType="bulletin_analysis_stats" parameters={{}} data={aiStatsQuery.data} />
            </>
          )}
        </Card>
      )}

      <Card>
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-sm font-semibold text-gray-500 dark:text-gray-400">Bulletins</h2>
          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" onClick={() => discoverMutation.mutate()} disabled={discoverMutation.isPending}>
              <span className="flex items-center gap-2">
                <RetryIcon spinning={discoverMutation.isPending} />
                {discoverMutation.isPending ? 'Vérification…' : 'Vérifier les nouveaux bulletins (brvm.org)'}
              </span>
            </Button>
            <Button variant="secondary" onClick={runBulkProcess} disabled={bulkProcessing || pendingBulletinIds.length === 0}>
              <span className="flex items-center gap-2">
                <RetryIcon spinning={bulkProcessing} />
                {bulkProcessing && bulkProgress
                  ? `Traitement… (${bulkProgress.done}/${bulkProgress.total})`
                  : `Traiter tous les bulletins en attente (${pendingBulletinIds.length})`}
              </span>
            </Button>
            <Button variant="secondary" onClick={runBulkAnalyze} disabled={bulkAnalyzing || analyzableBulletinIds.length === 0}>
              <span className="flex items-center gap-2">
                <BoltIcon />
                {bulkAnalyzing && bulkAnalyzeProgress
                  ? `Analyse IA… (${bulkAnalyzeProgress.done}/${bulkAnalyzeProgress.total}${bulkAnalyzeProgress.failed ? `, ${bulkAnalyzeProgress.failed} échec(s)` : ''})`
                  : 'Analyser tous les bulletins (IA)'}
              </span>
            </Button>
            <Button variant="secondary" onClick={runBulkFormat} disabled={bulkFormatting || analyzableBulletinIds.length === 0}>
              <span className="flex items-center gap-2">
                <RetryIcon spinning={bulkFormatting} />
                {bulkFormatting && bulkFormatProgress
                  ? `Formatage… (${bulkFormatProgress.done}/${bulkFormatProgress.total}${bulkFormatProgress.failed ? `, ${bulkFormatProgress.failed} échec(s)` : ''})`
                  : 'Formater tous les bulletins (Markdown)'}
              </span>
            </Button>
            <Button onClick={openComparison} disabled={selectedIds.length < 2}>
              <span className="flex items-center gap-2">
                <BoltIcon />
                {`Comparer les bulletins sélectionnés (${selectedIds.length})`}
              </span>
            </Button>
          </div>
        </div>

        <div className="mb-3 flex flex-wrap items-end gap-2 rounded-md border border-gray-200 p-3 dark:border-gray-800">
          <label className="w-44">
            <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Rechercher une date précise</span>
            <Input type="date" value={lookupDate} onChange={(e) => setLookupDate(e.target.value)} />
          </label>
          <Button
            variant="secondary"
            onClick={() => lookupMutation.mutate(lookupDate)}
            disabled={!lookupDate || lookupMutation.isPending}
          >
            <span className="flex items-center gap-2">
              <RetryIcon spinning={lookupMutation.isPending} />
              {lookupMutation.isPending ? 'Recherche…' : 'Rechercher sur brvm.org'}
            </span>
          </Button>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            Utile pour un bulletin plus ancien que les ~10-11 derniers jours (fiable depuis fin 2021 environ — le site utilisait un autre format d'URL avant).
          </p>
        </div>

        {lookupMutation.isError && (
          <div className="mb-3">
            <ErrorState message={(lookupMutation.error as Error).message} />
          </div>
        )}
        {lookupMutation.isSuccess && (
          <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
            {lookupMutation.data.found
              ? lookupMutation.data.already_known
                ? `Ce bulletin était déjà connu : ${lookupMutation.data.bulletin?.title}.`
                : `Bulletin trouvé et ajouté : ${lookupMutation.data.bulletin?.title}.`
              : `Aucun bulletin trouvé sur brvm.org pour le ${lookupDate}.`}
          </p>
        )}

        {discoverMutation.isError && (
          <div className="mb-3">
            <ErrorState message={(discoverMutation.error as Error).message} />
          </div>
        )}
        {discoverMutation.isSuccess && (
          <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
            {discoverMutation.data.new_count > 0
              ? `${discoverMutation.data.new_count} nouveau(x) bulletin(s) trouvé(s) et ajouté(s) (sur ${discoverMutation.data.total_on_site} au total sur brvm.org).`
              : `Aucun nouveau bulletin (${discoverMutation.data.total_on_site} sur brvm.org, tous déjà connus).`}
          </p>
        )}
        {!bulkAnalyzing && bulkAnalyzeProgress && (
          <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
            Analyse IA terminée : {bulkAnalyzeProgress.total - bulkAnalyzeProgress.failed}/{bulkAnalyzeProgress.total} bulletin(s) analysé(s) avec succès
            {bulkAnalyzeProgress.failed ? `, ${bulkAnalyzeProgress.failed} échec(s)` : ''}.
          </p>
        )}

        {listQuery.isLoading && <LoadingState />}
        {listQuery.error && <ErrorState message={(listQuery.error as Error).message} />}
        {listQuery.data && (
          <Table headers={['', 'Date', 'Titre', 'Statut', 'Action']}>
            {listQuery.data.map((b) => {
              const result = results[b.id]
              const isPending = pendingId === b.id && (processMutation.isPending || uploadMutation.isPending)
              const extracted = result ? result.status === 'success' : b.text_extracted
              const errorMessage = result ? result.extraction_error : b.extraction_error
              const failed = !extracted && !!errorMessage
              const transportNote = transportErrors[b.id]
              const detailOpen = openErrorId === b.id
              const hasDetail = !!transportNote || (failed && !!errorMessage)

              return (
                <Fragment key={b.id}>
                  <tr>
                    <td className="px-3 py-2">
                      <input
                        type="checkbox"
                        checked={selectedIds.includes(b.id)}
                        onChange={() => toggleSelect(b.id)}
                        disabled={!extracted}
                        title={extracted ? 'Sélectionner pour comparaison' : 'Traite le bulletin avant de le comparer'}
                      />
                    </td>
                    <td className="px-3 py-2 whitespace-nowrap">{b.publish_date}</td>
                    <td className="px-3 py-2 max-w-[360px] truncate" title={b.title}>{b.title}</td>
                    <td className="px-3 py-2">
                      {extracted ? (
                        <span className="text-emerald-600 dark:text-emerald-400">traité</span>
                      ) : failed ? (
                        <span className="text-red-600 dark:text-red-400">échec</span>
                      ) : (
                        <span className="text-amber-600 dark:text-amber-400">en attente</span>
                      )}
                      <MarkdownBadge status={b.markdown_status} />
                      <AnalysisBadge count={b.analyses_count} models={b.analyzed_models} />
                    </td>
                    <td className="px-3 py-2">
                      <div className="flex items-center gap-1">
                        <IconButton title="Consulter le bulletin" onClick={() => setViewBulletinId(b.id)}>
                          <EyeIcon />
                        </IconButton>
                        {extracted && (
                          <IconButton title="Analyser ce bulletin avec l'IA" onClick={() => setAnalyzeBulletinId(b.id)}>
                            <BoltIcon />
                          </IconButton>
                        )}
                        {!extracted && (
                          <IconButton
                            title={failed ? 'Réessayer' : 'Traiter (télécharger + extraire le texte)'}
                            onClick={() => processMutation.mutate(b.id)}
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
                              ref={(el) => { fileInputs.current[b.id] = el }}
                              onChange={(e) => handleFileChosen(b.id, e)}
                            />
                            <IconButton
                              title="Choisir un autre fichier PDF et le traiter"
                              onClick={() => fileInputs.current[b.id]?.click()}
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
                            onClick={() => setOpenErrorId(detailOpen ? null : b.id)}
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

      {viewBulletinId !== null && (
        <Modal
          title="Consulter le bulletin"
          onClose={() => { setViewBulletinId(null); setShowRawText(false); setIsEditingMarkdown(false) }}
        >
          {viewQuery.isLoading && <LoadingState />}
          {viewQuery.error && <ErrorState message={(viewQuery.error as Error).message} />}
          {viewQuery.data && (
            <div className="flex flex-col gap-3">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="text-sm font-semibold text-gray-900 dark:text-gray-100">{viewQuery.data.title}</p>
                  <p className="text-xs text-gray-500 dark:text-gray-400">
                    {viewQuery.data.publish_date}
                    {viewQuery.data.char_count ? ` · ${viewQuery.data.char_count.toLocaleString('fr-FR')} caractères extraits` : ''}
                  </p>
                </div>
                {viewQuery.data.markdown_status === 'success' && !isEditingMarkdown && (
                  <Button variant="secondary" onClick={() => setShowRawText((v) => !v)}>
                    {showRawText ? 'Voir la version formatée' : 'Voir le texte brut'}
                  </Button>
                )}
              </div>

              <div className="flex flex-wrap items-center gap-2">
                <a
                  href={bulletinDownloadUrl(viewQuery.data.id)}
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
                    onClick={() => downloadMarkdownFile(viewQuery.data!.publish_date, viewQuery.data!.formatted_markdown!)}
                  >
                    Télécharger le markdown
                  </Button>
                )}
                {viewQuery.data.markdown_status === 'success' && viewQuery.data.formatted_markdown && !isEditingMarkdown && (
                  <Button
                    variant="secondary"
                    onClick={() => {
                      setEditedMarkdown(viewQuery.data!.formatted_markdown!)
                      setShowRawText(false)
                      setIsEditingMarkdown(true)
                    }}
                  >
                    Corriger
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
                  disabled={uploadMarkdownMutation.isPending || isEditingMarkdown}
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
                  Formatage en cours (peut prendre ~2 minutes)… la page se met à jour automatiquement.
                </p>
              )}
              {viewQuery.data.markdown_status === 'failed' && (
                <ErrorState message={viewQuery.data.markdown_error ?? "Échec du formatage markdown"} />
              )}

              {isEditingMarkdown ? (
                <div className="flex flex-col gap-2">
                  <p className="text-xs text-gray-500 dark:text-gray-400">
                    Corrige directement le texte markdown ci-dessous (tableaux, chiffres, coquilles d'extraction...),
                    puis enregistre — remplace la version actuelle, même effet qu'un import de fichier .md.
                    Le PER/rendement et les opérations sur titres déjà extraits pour ce bulletin (page Opérations sur
                    titres) seront invalidés et réapparaîtront comme « en attente » pour être réextraits sur ce texte
                    corrigé.
                  </p>
                  <textarea
                    value={editedMarkdown}
                    onChange={(e) => setEditedMarkdown(e.target.value)}
                    rows={20}
                    className="w-full rounded-md border border-gray-300 p-3 font-mono text-xs focus:border-indigo-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                  />
                  <div className="flex items-center gap-2">
                    <Button onClick={saveEditedMarkdown} disabled={uploadMarkdownMutation.isPending || editedMarkdown.trim() === ''}>
                      {uploadMarkdownMutation.isPending ? 'Enregistrement…' : 'Enregistrer la correction'}
                    </Button>
                    <Button variant="secondary" onClick={() => setIsEditingMarkdown(false)} disabled={uploadMarkdownMutation.isPending}>
                      Annuler
                    </Button>
                  </div>
                </div>
              ) : viewQuery.data.markdown_status === 'success' && viewQuery.data.formatted_markdown && !showRawText ? (
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
                    : "Texte pas encore extrait pour ce bulletin. Ouvre le PDF original ci-dessus pour le consulter."}
                </p>
              )}
            </div>
          )}
        </Modal>
      )}

      {analyzeBulletinId !== null && (
        <Modal title="Analyse IA du bulletin" onClose={() => setAnalyzeBulletinId(null)}>
          {(analyzeQuery.isLoading || reanalyzeMutation.isPending) && (
            <LoadingState label={reanalyzeMutation.isPending ? 'Réanalyse en cours…' : 'Analyse en cours…'} />
          )}
          {!reanalyzeMutation.isPending && analyzeQuery.error && <ErrorState message={(analyzeQuery.error as Error).message} />}
          {reanalyzeMutation.isError && <ErrorState message={(reanalyzeMutation.error as Error).message} />}
          {!reanalyzeMutation.isPending && analyzeQuery.data && analyzeQuery.data.status === 'failed' && (
            <ErrorState message={analyzeQuery.data.error_message ?? "Échec de l'analyse"} />
          )}
          {!reanalyzeMutation.isPending && analyzeQuery.data && analyzeQuery.data.analysis && (
            <div className="flex flex-col gap-4">
              <div className="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                <span>{analyzeQuery.data.bulletin.title}</span>
                <span className="flex items-center gap-2">
                  {analyzeQuery.data.provider}/{analyzeQuery.data.model} {analyzeQuery.data.cached && '· depuis le cache'}
                  <Button variant="secondary" onClick={() => reanalyzeMutation.mutate(analyzeBulletinId!)} disabled={reanalyzeMutation.isPending}>
                    <span className="flex items-center gap-2">
                      <RetryIcon spinning={reanalyzeMutation.isPending} />
                      Réanalyser
                    </span>
                  </Button>
                </span>
              </div>

              <div>
                <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Résumé de séance</h3>
                <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{analyzeQuery.data.analysis.session_summary}</p>
              </div>

              <div className="flex items-center gap-2">
                <span className="inline-block rounded-full bg-gray-200 px-3 py-1 text-xs font-semibold text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                  {analyzeQuery.data.analysis.sentiment.verdict}
                </span>
                <span className="text-xs text-gray-500 dark:text-gray-400">{analyzeQuery.data.analysis.sentiment.rationale}</span>
              </div>

              <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                <div><div className="text-xs text-gray-500">Volume</div><div className="font-medium">{analyzeQuery.data.analysis.key_figures.total_volume?.toLocaleString('fr-FR') ?? '—'}</div></div>
                <div><div className="text-xs text-gray-500">Valeur échangée</div><div className="font-medium">{analyzeQuery.data.analysis.key_figures.total_turnover?.toLocaleString('fr-FR') ?? '—'}</div></div>
                <div><div className="text-xs text-gray-500">Hausses</div><div className="font-medium text-emerald-600 dark:text-emerald-400">{analyzeQuery.data.analysis.key_figures.advancers_count ?? '—'}</div></div>
                <div><div className="text-xs text-gray-500">Baisses</div><div className="font-medium text-red-600 dark:text-red-400">{analyzeQuery.data.analysis.key_figures.decliners_count ?? '—'}</div></div>
                <div><div className="text-xs text-gray-500">Inchangés</div><div className="font-medium">{analyzeQuery.data.analysis.key_figures.unchanged_count ?? '—'}</div></div>
              </div>

              <div>
                <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Mouvements notables</h3>
                <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{analyzeQuery.data.analysis.notable_movements}</p>
              </div>

              {analyzeQuery.data.analysis.sector_trends && (
                <div>
                  <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Tendances sectorielles</h3>
                  <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{analyzeQuery.data.analysis.sector_trends}</p>
                </div>
              )}

              {analyzeQuery.data.analysis.anomalies_or_alerts && (
                <div>
                  <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Éléments inhabituels</h3>
                  <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{analyzeQuery.data.analysis.anomalies_or_alerts}</p>
                </div>
              )}

              {analyzeQuery.data.analysis.glossary.length > 0 && (
                <div>
                  <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Glossaire</h3>
                  <dl className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    {analyzeQuery.data.analysis.glossary.map((g, i) => (
                      <div key={i}><dt className="font-medium">{g.term}</dt><dd className="text-gray-600 dark:text-gray-400">{g.explanation}</dd></div>
                    ))}
                  </dl>
                </div>
              )}

              <p className="text-xs italic text-gray-400">{analyzeQuery.data.disclaimer}</p>
            </div>
          )}
        </Modal>
      )}

      {showComparison && (
        <Modal title="Comparaison de bulletins" onClose={() => setShowComparison(false)}>
          {compareMutation.isPending && <LoadingState label="Analyse comparative en cours (peut prendre du temps si des bulletins n'ont pas encore été analysés individuellement)…" />}
          {compareMutation.isError && <ErrorState message={(compareMutation.error as Error).message} />}
          {compareMutation.data && compareMutation.data.status === 'failed' && (
            <ErrorState message={compareMutation.data.error_message ?? "Échec de la comparaison"} />
          )}
          {compareMutation.data && compareMutation.data.analysis && (
            <div className="flex flex-col gap-4">
              <div className="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                <span>{compareMutation.data.bulletins.map((b) => b.publish_date).join(', ')}</span>
                <span className="flex items-center gap-2">
                  {compareMutation.data.provider}/{compareMutation.data.model} {compareMutation.data.cached && '· depuis le cache'}
                  <Button variant="secondary" onClick={() => compareMutation.mutate(true)} disabled={compareMutation.isPending}>
                    Forcer
                  </Button>
                </span>
              </div>

              <div>
                <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Vue d'ensemble de la période</h3>
                <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{compareMutation.data.analysis.period_overview}</p>
              </div>

              {compareMutation.data.analysis.key_takeaways.length > 0 && (
                <div className="rounded-md border border-gray-300 bg-gray-100 p-3 dark:border-gray-700 dark:bg-gray-800/60">
                  <h3 className="mb-1 text-sm font-semibold text-gray-800 dark:text-gray-200">Points clés pour la décision</h3>
                  <ul className="list-disc pl-4 text-sm text-gray-800 dark:text-gray-200">
                    {compareMutation.data.analysis.key_takeaways.map((k, i) => <li key={i}>{k}</li>)}
                  </ul>
                </div>
              )}

              {compareMutation.data.chart_data && compareMutation.data.chart_data.index_series.length > 0 && (
                <Card title="Évolution des indices">
                  <ResponsiveContainer width="100%" height={240}>
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

              <div>
                <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Tendance par indice</h3>
                <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{compareMutation.data.analysis.trend_by_index}</p>
              </div>

              {compareMutation.data.chart_data && compareMutation.data.chart_data.key_figures_series.length > 0 && (
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                  <Card title="Volume et valeur échangée">
                    <ResponsiveContainer width="100%" height={200}>
                      <BarChart data={compareMutation.data.chart_data.key_figures_series}>
                        <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                        <XAxis dataKey="publish_date" tick={{ fontSize: 11 }} />
                        <YAxis tick={{ fontSize: 11 }} width={80} />
                        <Tooltip />
                        <Bar dataKey="total_volume" fill="var(--chart-1)" name="Volume" />
                      </BarChart>
                    </ResponsiveContainer>
                  </Card>
                  <Card title="Hausses / baisses / inchangés">
                    <ResponsiveContainer width="100%" height={200}>
                      <BarChart data={compareMutation.data.chart_data.key_figures_series}>
                        <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                        <XAxis dataKey="publish_date" tick={{ fontSize: 11 }} />
                        <YAxis tick={{ fontSize: 11 }} width={40} />
                        <Tooltip />
                        <Legend />
                        <Bar dataKey="advancers_count" stackId="a" fill="var(--chart-positive)" name="Hausses" />
                        <Bar dataKey="decliners_count" stackId="a" fill="var(--chart-negative)" name="Baisses" />
                        <Bar dataKey="unchanged_count" stackId="a" fill="var(--chart-muted)" name="Inchangés" />
                      </BarChart>
                    </ResponsiveContainer>
                  </Card>
                </div>
              )}

              <div>
                <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Volume et valeur échangée</h3>
                <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{compareMutation.data.analysis.volume_turnover_trend}</p>
              </div>

              <div>
                <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Titres/secteurs récurrents</h3>
                <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{compareMutation.data.analysis.recurring_movers}</p>
              </div>

              <div>
                <h3 className="mb-1 text-sm font-semibold text-gray-500 dark:text-gray-400">Évolution du sentiment</h3>
                <p className="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{compareMutation.data.analysis.sentiment_evolution}</p>
              </div>

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

              {compareMutation.data.skipped_bulletins.length > 0 && (
                <p className="text-xs text-amber-600 dark:text-amber-400">
                  {compareMutation.data.skipped_bulletins.length} bulletin(s) n'ont pas pu être inclus (analyse individuelle en échec).
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
