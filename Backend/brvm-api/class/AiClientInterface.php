<?php
/**
 * Contrat commun à tous les clients IA (Gemini, Anthropic, ...) utilisés par
 * ReportAnalysisService, pour pouvoir changer de fournisseur sans toucher au
 * reste du pipeline d'analyse.
 */
interface AiClientInterface {
    /**
     * $responseSchema est optionnel : certains fournisseurs (Anthropic) s'en
     * servent pour valider structurellement la sortie JSON ; d'autres
     * (Gemini) l'ignorent et se basent uniquement sur la description du
     * schéma dans le prompt. Sans $responseSchema, chaque client utilise son
     * schéma par défaut (analyse individuelle d'un rapport).
     *
     * $options permet de dépasser les valeurs par défaut du client pour un
     * appel donné : 'timeout_seconds' (les deux clients), 'max_tokens'
     * (Anthropic uniquement) — utile pour les générations longues (ex:
     * restructuration markdown complète d'un bulletin, voir
     * class/BulletinMarkdownFormatterService.php) qui dépassent largement
     * les timeouts/limites par défaut pensés pour une analyse classique.
     *
     * @return array{success:bool, data?:array, raw?:string, error?:string}
     */
    public function generateContent(string $prompt, ?string $model = null, ?array $responseSchema = null, ?array $options = null): array;
}
