const API_BASE_URL = import.meta.env.VITE_API_BASE_URL as string
const TOKEN_STORAGE_KEY = 'brvm_admin_token'

export class ApiError extends Error {
  status: number
  constructor(message: string, status: number) {
    super(message)
    this.status = status
  }
}

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_STORAGE_KEY)
}

export function setToken(token: string) {
  localStorage.setItem(TOKEN_STORAGE_KEY, token)
}

export function clearToken() {
  localStorage.removeItem(TOKEN_STORAGE_KEY)
}

/**
 * URL de consultation directe d'un rapport (ouverture du PDF dans un nouvel
 * onglet). Le token voyage en query param ici, pas en header X-Auth-Token :
 * un lien/onglet de navigateur ne peut pas poser de header custom — voir
 * AuthGuard::extractToken() côté backend pour le repli correspondant.
 */
export function reportDownloadUrl(reportId: number): string {
  const token = getToken() ?? ''
  return `${API_BASE_URL}/api_reports.php?action=download&id=${reportId}&token=${encodeURIComponent(token)}`
}

/** Même principe que reportDownloadUrl(), pour les Bulletins Officiels de la Cote. */
export function bulletinDownloadUrl(bulletinId: number): string {
  const token = getToken() ?? ''
  return `${API_BASE_URL}/api_bulletins.php?action=download&id=${bulletinId}&token=${encodeURIComponent(token)}`
}

/**
 * Une réponse non-JSON (page d'erreur HTML) arrive surtout quand MAMP coupe
 * la connexion après 30s d'inactivité côté client (mod_fastcgi) alors que le
 * script PHP continue de tourner côté serveur — voir GUIDE.md. Le traitement
 * a pu réussir malgré tout : on le signale plutôt que de laisser passer une
 * erreur de parsing JSON brute.
 */
async function parseJsonBody(response: Response): Promise<{ success: boolean; data?: unknown; message?: string }> {
  const text = await response.text()
  try {
    return JSON.parse(text)
  } catch {
    throw new ApiError(
      "Réponse invalide du serveur (délai probablement dépassé). Le traitement a pu tout de même se terminer côté serveur — recharge la liste pour vérifier avant de réessayer.",
      response.status,
    )
  }
}

/**
 * Chaque endpoint PHP suit la même convention : POST JSON {action, ...params}
 * -> {success, data?, message?}. On centralise ça ici, avec le header
 * X-Auth-Token (pas "Authorization", voir class/AuthGuard.php côté backend).
 */
export async function callApi<T = unknown>(
  endpoint: string,
  action: string,
  params: Record<string, unknown> = {},
): Promise<T> {
  const token = getToken()

  const response = await fetch(`${API_BASE_URL}/${endpoint}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { 'X-Auth-Token': token } : {}),
    },
    body: JSON.stringify({ action, ...params }),
  })

  if (response.status === 401) {
    clearToken()
    throw new ApiError("Session expirée, reconnecte-toi", 401)
  }

  const body = await parseJsonBody(response)

  if (!body.success) {
    throw new ApiError(body.message ?? 'Erreur inconnue', response.status)
  }

  return body.data as T
}

/**
 * Variante de callApi() pour les actions qui envoient un fichier (multipart/
 * form-data au lieu de JSON) — ex: remplacement manuel d'un PDF de rapport.
 * Ne pas fixer Content-Type soi-même : le navigateur doit poser la boundary.
 */
export async function uploadFile<T = unknown>(
  endpoint: string,
  action: string,
  file: File,
  params: Record<string, string | number> = {},
): Promise<T> {
  const token = getToken()

  const formData = new FormData()
  formData.append('action', action)
  formData.append('file', file)
  Object.entries(params).forEach(([key, value]) => formData.append(key, String(value)))

  const response = await fetch(`${API_BASE_URL}/${endpoint}`, {
    method: 'POST',
    headers: {
      ...(token ? { 'X-Auth-Token': token } : {}),
    },
    body: formData,
  })

  if (response.status === 401) {
    clearToken()
    throw new ApiError("Session expirée, reconnecte-toi", 401)
  }

  const body = await parseJsonBody(response)

  if (!body.success) {
    throw new ApiError(body.message ?? 'Erreur inconnue', response.status)
  }

  return body.data as T
}
