import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import {
  isBatchResult,
  isJobStarted,
  ocrBatch,
  ocrCancel,
  ocrCandidates,
  ocrConfigured,
  ocrHealth,
  ocrJob,
  ocrReport,
  ocrUpload,
  type OcrBatchResult,
  type OcrJob,
  type OcrOptions,
  type OcrResult,
} from '../lib/ocrClient'
import { Button, Card, ErrorState, InfoPanel, Input, LoadingState, Select, StatTile, Tabs } from '../components/ui'

/**
 * Écran de test du service OCR (second backend, Python — voir
 * Backend/ocr-service/README.md). Deux usages : joindre un fichier pour
 * l'extraire, ou re-traiter un rapport déjà en base dont le PDF est un scan.
 */

const nf = new Intl.NumberFormat('fr-FR')

/** Barre de confiance OCR : en dessous de 70 %, le texte mérite une relecture. */
function ConfidenceBadge({ value }: { value: number | null | undefined }) {
  if (value === null || value === undefined) return <span className="text-gray-400">—</span>
  const tone =
    value >= 85
      ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
      : value >= 70
        ? 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
        : 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
  return (
    <span className={`rounded px-1.5 py-0.5 text-xs font-semibold ${tone}`}>
      {value}%{value < 70 && ' — à relire'}
    </span>
  )
}

/**
 * Bilan d'un traitement par lot : se remplit au fur et à mesure, pour voir
 * défiler les rapports traités sans attendre la fin.
 */
function BatchResultView({ result, status }: { result: OcrBatchResult; status?: string }) {
  const done = result.reports.length
  const ok = result.reports.filter((r) => r.status === 'ok').length
  const ko = done - ok

  return (
    <Card
      title={
        status === 'en_cours'
          ? `Traitement en cours — ${done} rapport(s) déjà traité(s)`
          : status === 'annule'
            ? 'Traitement interrompu — bilan'
            : 'Traitement terminé — bilan'
      }
    >
      {status === 'annule' && (
        <div className="mb-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">
          <strong>Arrêt demandé.</strong> Les {ok} rapport(s) déjà extraits sont conservés
          {result.saved === false && ' (mode aperçu : rien n\'a été enregistré)'}. Relancer le lot reprendra les
          rapports restants.
        </div>
      )}

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <StatTile label="Rapports traités" value={`${done}${result.requested ? ` / ${result.requested}` : ''}`} />
        <StatTile label="Réussis" value={ok} tone={ok > 0 ? 'positive' : 'default'} />
        <StatTile label="En échec" value={ko} tone={ko > 0 ? 'negative' : 'default'} />
        <StatTile label="Caractères extraits" value={nf.format(result.total_chars)} />
      </div>

      <div className="mt-3 max-h-96 overflow-y-auto overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="sticky top-0 bg-white dark:bg-gray-950">
            <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
              <th className="pb-2 pr-3">#</th>
              <th className="pb-2 pr-3">Titre</th>
              <th className="pb-2 pr-3 text-right">Caractères</th>
              <th className="pb-2 pr-3">Confiance</th>
              <th className="pb-2">Résultat</th>
            </tr>
          </thead>
          <tbody>
            {[...result.reports].reverse().map((r) => (
              <tr key={r.id} className="border-t border-gray-100 align-top dark:border-gray-800">
                <td className="py-1.5 pr-3 tabular-nums text-gray-400">{r.id}</td>
                <td className="py-1.5 pr-3 max-w-md text-gray-700 dark:text-gray-300">{r.title || '—'}</td>
                <td className="py-1.5 pr-3 text-right tabular-nums">{r.chars !== null ? nf.format(r.chars) : '—'}</td>
                <td className="py-1.5 pr-3">
                  <ConfidenceBadge value={r.confidence} />
                </td>
                <td className="py-1.5 text-xs">
                  {r.status === 'ok' ? (
                    <span className="text-emerald-600 dark:text-emerald-400">
                      extrait{r.pages ? ` (${r.pages} page${r.pages > 1 ? 's' : ''})` : ''}
                    </span>
                  ) : r.status === 'fichier_absent' ? (
                    <span className="text-gray-400">PDF absent du disque</span>
                  ) : (
                    <span className="text-red-600 dark:text-red-400">{r.error ?? 'échec'}</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
        Les plus récents en haut. Un rapport en échec n'interrompt jamais le lot : le traitement passe au suivant et
        l'erreur est consignée ici.
      </p>
    </Card>
  )
}

function ResultView({ result, filename }: { result: OcrResult; filename: string }) {
  const [tab, setTab] = useState<'markdown' | 'texte' | 'pages'>('markdown')

  function download(content: string, extension: string) {
    const blob = new Blob([content], { type: 'text/plain;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${filename.replace(/\.[^.]+$/, '')}.${extension}`
    a.click()
    URL.revokeObjectURL(url)
  }

  return (
    <div className="flex flex-col gap-4">
      {result.ocr_effectue === false && (
        <div className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">
          <strong>Aucun OCR effectué.</strong> {result.message}
        </div>
      )}

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
        <StatTile label="Caractères extraits" value={nf.format(result.char_count)} />
        <StatTile label="Mots" value={result.word_count !== undefined ? nf.format(result.word_count) : '—'} />
        <StatTile label="Pages traitées" value={result.pages ?? '—'} />
        <StatTile label="Pages du document" value={result.total_pages ?? result.pages ?? '—'} />
        <StatTile
          label="Confiance moyenne"
          value={result.mean_confidence !== null && result.mean_confidence !== undefined ? `${result.mean_confidence}%` : '—'}
          tone={
            result.mean_confidence === null || result.mean_confidence === undefined
              ? 'default'
              : result.mean_confidence >= 85
                ? 'positive'
                : result.mean_confidence < 70
                  ? 'negative'
                  : 'default'
          }
        />
      </div>

      {result.saved_to_report_id !== undefined && (
        <div className="rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">
          Résultat enregistré sur le rapport <strong>#{result.saved_to_report_id}</strong> : le texte et le markdown
          sont désormais visibles dans l'écran Rapports et exploitables par les analyses IA.
        </div>
      )}

      <Card>
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <Tabs
            tabs={[
              { id: 'markdown', label: 'Markdown' },
              { id: 'texte', label: 'Texte brut' },
              { id: 'pages', label: 'Détail par page' },
            ]}
            active={tab}
            onChange={(id) => setTab(id as typeof tab)}
          />
          <span className="ml-auto flex gap-2">
            {result.markdown && (
              <Button variant="secondary" onClick={() => download(result.markdown as string, 'md')}>
                Télécharger le .md
              </Button>
            )}
            <Button variant="secondary" onClick={() => download(result.text, 'txt')}>
              Télécharger le .txt
            </Button>
          </span>
        </div>

        {tab === 'markdown' &&
          (result.markdown ? (
            <div className="prose prose-sm max-w-none overflow-x-auto dark:prose-invert">
              <ReactMarkdown remarkPlugins={[remarkGfm]}>{result.markdown}</ReactMarkdown>
            </div>
          ) : (
            <p className="text-sm text-gray-500 dark:text-gray-400">Aucun markdown produit pour ce document.</p>
          ))}

        {tab === 'texte' && (
          <pre className="max-h-[32rem] overflow-auto whitespace-pre-wrap rounded-md bg-gray-50 p-3 text-xs dark:bg-gray-800">
            {result.text || '(aucun texte)'}
          </pre>
        )}

        {tab === 'pages' &&
          (result.per_page && result.per_page.length > 0 ? (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                    <th className="pb-2 pr-3">Page</th>
                    <th className="pb-2 pr-3 text-right">Caractères</th>
                    <th className="pb-2 pr-3 text-right">Mots</th>
                    <th className="pb-2">Confiance</th>
                  </tr>
                </thead>
                <tbody>
                  {result.per_page.map((p) => (
                    <tr key={p.page} className="border-t border-gray-100 dark:border-gray-800">
                      <td className="py-1.5 pr-3 tabular-nums">{p.page}</td>
                      <td className="py-1.5 pr-3 text-right tabular-nums">{nf.format(p.chars)}</td>
                      <td className="py-1.5 pr-3 text-right tabular-nums">{nf.format(p.words)}</td>
                      <td className="py-1.5">
                        <ConfidenceBadge value={p.confidence} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Une page dont la confiance chute nettement sous les autres est généralement mal scannée (penchée,
                tachée, basse résolution) : son texte mérite une vérification avant toute exploitation.
              </p>
            </div>
          ) : (
            <p className="text-sm text-gray-500 dark:text-gray-400">Pas de détail par page pour ce document.</p>
          ))}
      </Card>
    </div>
  )
}

export function OcrReports() {
  const [tab, setTab] = useState<'fichier' | 'base'>('fichier')
  const [lang, setLang] = useState('fra')
  const [dpi, setDpi] = useState(300)
  const [maxPages, setMaxPages] = useState('')
  const [force, setForce] = useState(false)
  const [saveToDb, setSaveToDb] = useState(true)

  const [file, setFile] = useState<File | null>(null)
  const [result, setResult] = useState<OcrResult | null>(null)
  const [resultName, setResultName] = useState('extraction')
  const [jobId, setJobId] = useState<string | null>(null)
  const [job, setJob] = useState<OcrJob | null>(null)
  const [batchResult, setBatchResult] = useState<OcrBatchResult | null>(null)
  const [batchLimit, setBatchLimit] = useState(20)
  const [batchNotice, setBatchNotice] = useState<string | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)

  const options: OcrOptions = {
    lang,
    dpi,
    maxPages: maxPages.trim() === '' ? null : Math.max(1, Number(maxPages) || 1),
    force,
  }

  const healthQuery = useQuery({
    queryKey: ['ocr-health'],
    queryFn: ocrHealth,
    enabled: ocrConfigured,
    retry: false,
    refetchInterval: 30_000,
  })

  const candidatesQuery = useQuery({
    queryKey: ['ocr-candidates'],
    queryFn: () => ocrCandidates(500, 50),
    enabled: ocrConfigured && tab === 'base' && healthQuery.data?.database.available === true,
    retry: false,
  })

  // Suivi d'une extraction longue : le service traite page par page en
  // tâche de fond, on interroge son avancement jusqu'à la fin.
  useEffect(() => {
    if (!jobId) return
    let cancelled = false
    const timer = setInterval(async () => {
      try {
        const j = await ocrJob(jobId)
        if (cancelled) return
        setJob(j)
        // Un lot met son bilan à jour en continu : on l'affiche sans
        // attendre la fin, pour voir défiler les rapports traités.
        if (isBatchResult(j.result)) setBatchResult(j.result)
        if (j.status === 'termine' || j.status === 'echec' || j.status === 'annule') {
          clearInterval(timer)
          setJobId(null)
          if (j.result && !isBatchResult(j.result)) setResult(j.result as OcrResult)
        }
      } catch {
        clearInterval(timer)
      }
    }, 2000)
    return () => {
      cancelled = true
      clearInterval(timer)
    }
  }, [jobId])

  function handleStarted(response: OcrResult | { job_id: string }, label: string) {
    setResultName(label)
    setJob(null)
    if (isJobStarted(response as never)) {
      setResult(null)
      setJobId((response as { job_id: string }).job_id)
    } else {
      setResult(response as OcrResult)
    }
  }

  const uploadMutation = useMutation({
    mutationFn: () => {
      if (!file) throw new Error('Aucun fichier sélectionné')
      return ocrUpload(file, options)
    },
    onSuccess: (r) => handleStarted(r, file?.name ?? 'extraction'),
  })

  const reportMutation = useMutation({
    mutationFn: (reportId: number) => ocrReport(reportId, { ...options, save: saveToDb }),
    onSuccess: (r, reportId) => handleStarted(r, `rapport-${reportId}`),
  })

  // Traitement de tous les rapports en attente, l'un après l'autre.
  const batchMutation = useMutation({
    mutationFn: () => ocrBatch({ ...options, save: saveToDb, limit: batchLimit }),
    onSuccess: (r) => {
      setResult(null)
      setBatchResult(null)
      setBatchNotice(r.message ?? null)
      if (r.job_id) {
        setJob(null)
        setJobId(r.job_id)
      }
    },
  })

  const cancelMutation = useMutation({
    mutationFn: (id: string) => ocrCancel(id),
    onSuccess: (r) => setBatchNotice(r.message),
  })

  if (!ocrConfigured) {
    return (
      <div className="flex flex-col gap-4">
        <h2 className="text-xl font-semibold">OCR des rapports scannés</h2>
        <ErrorState message="Aucune URL de service OCR configurée : renseigne VITE_OCR_API_BASE_URL dans le fichier .env du frontend, puis relance le serveur de développement." />
      </div>
    )
  }

  const health = healthQuery.data
  const busy = uploadMutation.isPending || reportMutation.isPending || jobId !== null

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">OCR des rapports scannés</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Extraction du texte des PDF qui sont des images de pages, puis conversion en markdown.
        </p>
      </div>

      <InfoPanel>
        <p>
          <strong>À quoi sert cet écran.</strong> Certains rapports publiés à la BRVM sont des <strong>scans</strong> :
          le PDF ne contient pas de texte, seulement la photo de chaque page. L'extraction habituelle n'en tire alors
          que quelques caractères parasites, et le rapport reste inexploitable — ni consultation, ni analyse IA.
          Cet écran envoie ces documents à un second service qui <strong>lit les images</strong> (OCR) et reconstruit
          un markdown à partir de la mise en page : titres, paragraphes et tableaux financiers.
        </p>
        <p>
          Deux façons de l'utiliser : <strong>joindre un fichier</strong> pour tester n'importe quel PDF ou image, ou{' '}
          <strong>re-traiter un rapport déjà en base</strong> dont l'extraction a échoué — dans ce cas le résultat
          peut être réenregistré et devient immédiatement visible dans l'écran Rapports.
        </p>
        <p>
          La <strong>confiance</strong> affichée après chaque extraction indique la qualité de lecture. Au-dessus de
          85 % le texte est fiable ; en dessous de 70 %, le scan est de mauvaise qualité et le texte doit être relu
          avant d'en tirer des conclusions.
        </p>
      </InfoPanel>

      <Card title="État du service">
        {healthQuery.isLoading && <LoadingState label="Contact du service OCR…" />}
        {healthQuery.error && <ErrorState message={(healthQuery.error as Error).message} />}
        {health && (
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <StatTile
              label="Service"
              value={health.ready ? 'Prêt' : 'Indisponible'}
              tone={health.ready ? 'positive' : 'negative'}
            />
            <StatTile
              label="Français installé"
              value={health.french_available ? `Oui (${health.languages_installed} langues)` : 'Non'}
              tone={health.french_available ? 'positive' : 'negative'}
            />
            <StatTile
              label="Base de données"
              value={health.database.available ? `${nf.format(health.database.reports ?? 0)} rapports` : 'Non connectée'}
              tone={health.database.available ? 'positive' : 'default'}
            />
            <StatTile label="Tâches en mémoire" value={health.jobs_in_memory} />
          </div>
        )}
        {health && !health.database.available && health.database.reason && (
          <p className="mt-2 text-xs text-amber-600 dark:text-amber-400">
            Base non connectée ({health.database.reason}) : l'onglet « Rapports de la base » est indisponible, mais
            l'envoi de fichier fonctionne.
          </p>
        )}
      </Card>

      <Card title="Options d'extraction">
        <div className="flex flex-wrap items-end gap-4">
          <label className="w-40">
            <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Langue</span>
            <Select value={lang} onChange={(e) => setLang(e.target.value)}>
              <option value="fra">Français</option>
              <option value="eng">Anglais</option>
              <option value="fra+eng">Français + Anglais</option>
            </Select>
          </label>
          <label className="w-44">
            <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Résolution</span>
            <Select value={String(dpi)} onChange={(e) => setDpi(Number(e.target.value))}>
              <option value="200">200 dpi — rapide</option>
              <option value="300">300 dpi — recommandé</option>
              <option value="400">400 dpi — lent, scans difficiles</option>
            </Select>
          </label>
          <label className="w-44">
            <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
              Pages max (vide = tout)
            </span>
            <Input type="number" min="1" placeholder="ex : 5" value={maxPages} onChange={(e) => setMaxPages(e.target.value)} />
          </label>
          <label className="flex items-center gap-1.5 pb-2 text-xs text-gray-600 dark:text-gray-300">
            <input type="checkbox" checked={force} onChange={() => setForce(!force)} />
            Forcer l'OCR même si le PDF contient déjà du texte
          </label>
        </div>
        <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
          Comptez environ <strong>3 secondes par page</strong> à 300 dpi. Sur un rapport de 50 pages, limitez d'abord
          à quelques pages pour vérifier la qualité avant de lancer le document entier. Sans « forcer », un PDF qui
          contient déjà du texte est renvoyé tel quel au lieu d'être relu inutilement.
        </p>
      </Card>

      <Tabs
        tabs={[
          { id: 'fichier', label: 'Joindre un fichier' },
          { id: 'base', label: 'Rapports de la base' },
        ]}
        active={tab}
        onChange={(id) => setTab(id as typeof tab)}
      />

      {tab === 'fichier' && (
        <Card>
          <div className="flex flex-wrap items-center gap-3">
            <input
              ref={fileInput}
              type="file"
              accept=".pdf,.png,.jpg,.jpeg,.tif,.tiff,.bmp,.webp"
              onChange={(e) => {
                setFile(e.target.files?.[0] ?? null)
                setResult(null)
                setJob(null)
              }}
              className="text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-900 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white dark:text-gray-300 dark:file:bg-gray-100 dark:file:text-gray-900"
            />
            <Button disabled={!file || busy || !health?.ready} onClick={() => uploadMutation.mutate()}>
              {busy ? 'Extraction en cours…' : "Lancer l'extraction"}
            </Button>
            {file && (
              <span className="text-xs text-gray-500 dark:text-gray-400">
                {file.name} — {nf.format(Math.round(file.size / 1024))} Ko
              </span>
            )}
          </div>
          {uploadMutation.isError && (
            <p className="mt-2 text-sm text-red-600 dark:text-red-400">{(uploadMutation.error as Error).message}</p>
          )}
        </Card>
      )}

      {tab === 'base' && (
        <Card title="Rapports dont l'extraction a échoué ou n'a rien donné">
          <label className="mb-3 flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
            <input type="checkbox" checked={saveToDb} onChange={() => setSaveToDb(!saveToDb)} />
            Enregistrer le résultat en base (décocher pour un simple aperçu)
          </label>

          {/* Traitement global : enchaîne les rapports en attente l'un après
              l'autre, sans avoir à cliquer sur chaque ligne. */}
          <div className="mb-4 flex flex-wrap items-end gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900">
            <label className="w-44">
              <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                Rapports par lot
              </span>
              <Select value={String(batchLimit)} onChange={(e) => setBatchLimit(Number(e.target.value))}>
                <option value="5">5 rapports</option>
                <option value="20">20 rapports</option>
                <option value="50">50 rapports</option>
                <option value="100">100 rapports</option>
                <option value="500">Tout (jusqu'à 500)</option>
              </Select>
            </label>
            <Button
              disabled={busy || !health?.ready || !health?.database.available}
              onClick={() => {
                const msg = saveToDb
                  ? `Lancer l'extraction de ${batchLimit} rapport(s) à la suite ? Les résultats seront enregistrés en base.`
                  : `Lancer l'extraction de ${batchLimit} rapport(s) à la suite ? (aperçu seulement, rien ne sera enregistré)`
                if (window.confirm(msg)) batchMutation.mutate()
              }}
            >
              {busy ? 'Traitement en cours…' : '⚡ Tout extraire, un rapport après l\'autre'}
            </Button>
            {jobId && (
              <Button variant="secondary" disabled={cancelMutation.isPending} onClick={() => cancelMutation.mutate(jobId)}>
                Arrêter
              </Button>
            )}
            <p className="w-full text-xs text-gray-500 dark:text-gray-400">
              Les rapports sont traités <strong>séquentiellement</strong> et enregistrés au fur et à mesure : un arrêt
              en cours de route conserve tout ce qui a déjà été extrait, et relancer le lot reprend là où il s'était
              interrompu. Comptez environ 3 secondes par page — pensez à limiter le nombre de pages ci-dessus pour un
              premier essai.
            </p>
          </div>

          {batchNotice && (
            <p className="mb-3 text-xs text-gray-600 dark:text-gray-300">{batchNotice}</p>
          )}
          {batchMutation.isError && (
            <p className="mb-3 text-sm text-red-600 dark:text-red-400">{(batchMutation.error as Error).message}</p>
          )}
          {candidatesQuery.isLoading && <LoadingState label="Recherche des rapports concernés…" />}
          {candidatesQuery.error && <ErrorState message={(candidatesQuery.error as Error).message} />}
          {candidatesQuery.data && (
            <>
              <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
                {candidatesQuery.data.count} rapport(s) affiché(s) : aucun texte extrait, ou texte anormalement court
                (moins de 500 caractères) — la signature d'un PDF scanné.
              </p>
              <div className="max-h-96 overflow-y-auto overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead className="sticky top-0 bg-white dark:bg-gray-950">
                    <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                      <th className="pb-2 pr-3">#</th>
                      <th className="pb-2 pr-3">Titre</th>
                      <th className="pb-2 pr-3 text-right">Caractères actuels</th>
                      <th className="pb-2 pr-3">Fichier</th>
                      <th className="pb-2">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    {candidatesQuery.data.reports.map((r) => (
                      <tr key={r.id} className="border-t border-gray-100 dark:border-gray-800">
                        <td className="py-1.5 pr-3 tabular-nums text-gray-400">{r.id}</td>
                        <td className="py-1.5 pr-3 max-w-md text-gray-700 dark:text-gray-300">{r.title ?? '—'}</td>
                        <td className="py-1.5 pr-3 text-right tabular-nums">
                          {r.char_count === null ? (
                            <span className="text-red-500">aucun texte</span>
                          ) : (
                            r.char_count
                          )}
                        </td>
                        <td className="py-1.5 pr-3">
                          {r.fichier_present ? (
                            <span className="text-xs text-emerald-600 dark:text-emerald-400">présent</span>
                          ) : (
                            <span className="text-xs text-gray-400">absent du disque</span>
                          )}
                        </td>
                        <td className="py-1.5">
                          <button
                            type="button"
                            disabled={!r.fichier_present || busy}
                            onClick={() => reportMutation.mutate(r.id)}
                            className="text-xs text-gray-700 underline underline-offset-2 hover:text-black disabled:cursor-not-allowed disabled:text-gray-300 dark:text-gray-200 dark:disabled:text-gray-600"
                          >
                            {reportMutation.isPending && reportMutation.variables === r.id ? 'En cours…' : 'Extraire'}
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}
          {reportMutation.isError && (
            <p className="mt-2 text-sm text-red-600 dark:text-red-400">{(reportMutation.error as Error).message}</p>
          )}
        </Card>
      )}

      {job && job.status !== 'termine' && (
        <Card title="Extraction en cours">
          {job.progress.report_total ? (
            <p className="text-sm text-gray-700 dark:text-gray-300">
              Rapport <strong>{job.progress.report_index}</strong> sur {job.progress.report_total}
              {job.progress.report_id && <> — #{job.progress.report_id} {job.progress.report_title}</>}
              {job.progress.total ? (
                <> · page {job.progress.page}/{job.progress.total}</>
              ) : null}
            </p>
          ) : (
            <p className="text-sm text-gray-700 dark:text-gray-300">
              {job.label} — page <strong>{job.progress.page}</strong>
              {job.progress.total !== null && <> sur {job.progress.total}</>}
            </p>
          )}
          {(() => {
            const p = job.progress
            const ratio = p.report_total
              ? (p.report_index ?? 0) / p.report_total
              : p.total
                ? p.page / p.total
                : null
            if (ratio === null) return null
            return (
              <div className="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
                <div
                  className="h-full bg-gray-900 transition-all dark:bg-gray-100"
                  style={{ width: `${Math.round(ratio * 100)}%` }}
                />
              </div>
            )
          })()}
          {job.status === 'echec' && <ErrorState message={job.error ?? 'Échec inconnu'} />}
        </Card>
      )}

      {job?.status === 'echec' && <ErrorState message={job.error ?? "L'extraction a échoué"} />}

      {batchResult && <BatchResultView result={batchResult} status={job?.status} />}

      {result && <ResultView result={result} filename={resultName} />}
    </div>
  )
}
