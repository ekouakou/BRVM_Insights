/**
 * Modèles connus par fournisseur IA, proposés en suggestions (via
 * <datalist>) sur les sélecteurs de modèle — pas une liste fermée : le champ
 * reste un texte libre pour rester utilisable même si un nom de modèle
 * change côté fournisseur avant qu'on mette cette liste à jour ici. Valeur
 * vide = laisse le backend utiliser son modèle par défaut pour ce
 * fournisseur (voir PROVIDERS dans class/ChartAnalysisService.php et
 * consorts).
 */
export type AiProvider = 'gemini' | 'anthropic' | 'grok'

export const AI_PROVIDERS: { value: AiProvider; label: string }[] = [
  { value: 'gemini', label: 'Gemini' },
  { value: 'anthropic', label: 'Anthropic' },
  { value: 'grok', label: 'Grok' },
]

export const AI_MODELS: Record<AiProvider, { value: string; label: string }[]> = {
  gemini: [
    { value: 'gemini-flash-lite-latest', label: 'Gemini Flash Lite — rapide, économique (défaut)' },
    { value: 'gemini-flash-latest', label: 'Gemini Flash — équilibré' },
    { value: 'gemini-pro-latest', label: 'Gemini Pro — plus précis, plus lent' },
  ],
  anthropic: [
    { value: 'claude-opus-5', label: 'Claude Opus 5 — le plus capable (défaut)' },
    { value: 'claude-sonnet-5', label: 'Claude Sonnet 5 — équilibré' },
    { value: 'claude-haiku-4-5-20251001', label: 'Claude Haiku 4.5 — rapide, économique' },
  ],
  grok: [
    { value: 'grok-4-fast-reasoning', label: 'Grok 4 Fast (raisonnement) — défaut' },
    { value: 'grok-4-fast-non-reasoning', label: 'Grok 4 Fast — sans raisonnement, plus rapide' },
    { value: 'grok-4', label: 'Grok 4 — le plus capable' },
  ],
}
