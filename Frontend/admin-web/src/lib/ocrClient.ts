/**
 * Client du second backend, en Python, dédié à l'OCR des rapports scannés
 * (voir Backend/ocr-service/README.md).
 *
 * Volontairement séparé de `apiClient.ts` : ce service tourne sur un autre
 * hôte et un autre port, n'utilise pas le token d'authentification du
 * backend PHP, et renvoie ses erreurs au format FastAPI (`{detail}`) et non
 * au format maison (`{success, message}`).
 */

const OCR_BASE_URL = (import.meta.env.VITE_OCR_API_BASE_URL as string | undefined)?.replace(/\/$/, '') ?? ''

/** false = aucune URL configurée : l'écran s'affiche mais explique comment activer le service. */
export const ocrConfigured = OCR_BASE_URL !== ''

export class OcrError extends Error {
  status: number
  constructor(message: string, status: number) {
    super(message)
    this.status = status
  }
}

async function parse<T>(response: Response): Promise<T> {
  const raw = await response.text()
  let body: unknown = null
  try {
    body = raw ? JSON.parse(raw) : null
  } catch {
    body = null
  }
  if (!response.ok) {
    const detail =
      (body as { detail?: unknown } | null)?.detail ??
      (raw.slice(0, 300) || `Erreur HTTP ${response.status}`)
    throw new OcrError(typeof detail === 'string' ? detail : JSON.stringify(detail), response.status)
  }
  return body as T
}

/**
 * Message d'erreur réseau parlant : le cas de loin le plus fréquent est
 * « le service Python n'est pas démarré », qui se manifeste par un
 * TypeError de fetch sans aucune information utile.
 */
function networkError(): OcrError {
  return new OcrError(
    `Service OCR injoignable sur ${OCR_BASE_URL}. Démarre-le avec « cd Backend/ocr-service && ./run.sh ».`,
    0,
  )
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  let response: Response
  try {
    response = await fetch(`${OCR_BASE_URL}${path}`, init)
  } catch {
    throw networkError()
  }
  return parse<T>(response)
}

export interface OcrHealth {
  service: string
  ready: boolean
  tools: { tesseract: string | null; pdftoppm: string | null; pdfinfo: string | null }
  languages_installed: number
  french_available: boolean
  database: { available: boolean; reports?: number; database?: string; reason?: string }
  jobs_in_memory: number
}

export interface OcrPageDetail {
  page: number
  chars: number
  words: number
  confidence: number
}

/** Résultat d'une extraction terminée (synchrone ou via une tâche). */
export interface OcrResult {
  mode?: string
  ocr_effectue?: boolean
  message?: string
  text: string
  markdown?: string
  pages?: number
  total_pages?: number
  char_count: number
  word_count?: number
  mean_confidence?: number | null
  per_page?: OcrPageDetail[]
  saved_to_report_id?: number
}

/** Réponse quand le document est trop long pour une réponse immédiate. */
export interface OcrJobStarted {
  mode: 'asynchrone'
  job_id: string
  total_pages: number
  pages_a_traiter?: number
  report_id?: number
  suivi: string
  message?: string
}

/** Progression : page seule pour un document, + rapport en cours pour un lot. */
export interface OcrProgress {
  page: number
  total: number | null
  report_index?: number
  report_total?: number
  report_id?: number | null
  report_title?: string | null
}

/** Bilan d'un traitement par lot. */
export interface OcrBatchResult {
  reports: {
    id: number
    title: string
    status: 'ok' | 'echec' | 'fichier_absent'
    chars: number | null
    pages?: number
    confidence: number | null
    error: string | null
  }[]
  processed: number
  requested?: number
  succeeded?: number
  failed?: number
  total_chars: number
  saved?: boolean
}

export interface OcrJob {
  id: string
  kind: string
  label: string
  status: 'en_attente' | 'en_cours' | 'termine' | 'echec' | 'annule'
  progress: OcrProgress
  started_at: number
  finished_at: number | null
  duree_secondes?: number
  result: OcrResult | OcrBatchResult | null
  error: string | null
}

export function isBatchResult(r: OcrResult | OcrBatchResult | null): r is OcrBatchResult {
  return !!r && Array.isArray((r as OcrBatchResult).reports)
}

export interface OcrCandidate {
  id: number
  title: string | null
  local_path: string | null
  char_count: number | null
  fichier_present: boolean
}

export function isJobStarted(v: OcrResult | OcrJobStarted): v is OcrJobStarted {
  return (v as OcrJobStarted).mode === 'asynchrone'
}

export function ocrHealth(): Promise<OcrHealth> {
  return request<OcrHealth>('/health')
}

export function ocrCandidates(maxChars = 500, limit = 50): Promise<{ count: number; reports: OcrCandidate[] }> {
  return request(`/reports/candidates?max_chars=${maxChars}&limit=${limit}`)
}

export function ocrJob(jobId: string): Promise<OcrJob> {
  return request<OcrJob>(`/jobs/${jobId}`)
}

export interface OcrOptions {
  lang: string
  dpi: number
  maxPages: number | null
  force: boolean
}

export function ocrUpload(file: File, options: OcrOptions): Promise<OcrResult | OcrJobStarted> {
  const form = new FormData()
  form.append('file', file)
  form.append('lang', options.lang)
  form.append('dpi', String(options.dpi))
  form.append('force', String(options.force))
  if (options.maxPages !== null) form.append('max_pages', String(options.maxPages))
  return request('/extract', { method: 'POST', body: form })
}

export function ocrReport(
  reportId: number,
  options: OcrOptions & { save: boolean },
): Promise<OcrResult | OcrJobStarted> {
  const form = new FormData()
  form.append('lang', options.lang)
  form.append('dpi', String(options.dpi))
  form.append('save', String(options.save))
  if (options.maxPages !== null) form.append('max_pages', String(options.maxPages))
  return request(`/reports/${reportId}/extract`, { method: 'POST', body: form })
}

export interface OcrBatchStarted {
  mode: 'asynchrone' | 'rien_a_faire'
  job_id?: string
  count: number
  message?: string
}

/** Lance le traitement de tous les rapports en attente, l'un après l'autre. */
export function ocrBatch(
  options: OcrOptions & { save: boolean; limit: number; maxChars?: number },
): Promise<OcrBatchStarted> {
  const form = new FormData()
  form.append('lang', options.lang)
  form.append('dpi', String(options.dpi))
  form.append('save', String(options.save))
  form.append('limit', String(options.limit))
  form.append('max_chars', String(options.maxChars ?? 500))
  if (options.maxPages !== null) form.append('max_pages', String(options.maxPages))
  return request('/reports/extract-batch', { method: 'POST', body: form })
}

/** Demande l'arrêt d'une tâche ; le travail déjà enregistré est conservé. */
export function ocrCancel(jobId: string): Promise<{ status: string; message: string }> {
  return request(`/jobs/${jobId}/cancel`, { method: 'POST' })
}
