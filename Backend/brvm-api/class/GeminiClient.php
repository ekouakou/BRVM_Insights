<?php
/**
 * Client HTTP minimal pour l'API Gemini (Google) — generateContent en mode
 * JSON forcé. Dédié (pas HttpFetcher, pensé pour du GET HTML/binaire avec
 * repli en cascade) : ici il faut un POST JSON authentifié par query param.
 */
class GeminiClient implements AiClientInterface, AiChatClientInterface {
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';
    private const DEFAULT_MODEL = 'gemini-flash-lite-latest';
    private const TIMEOUT_SECONDS = 60;

    private $apiKey;

    public function __construct() {
        $this->apiKey = getenv('GEMINI_API_KEY');

        if (empty($this->apiKey)) {
            throw new Exception(
                "GEMINI_API_KEY absente. Copie .env.example en .env et renseigne ta clé " .
                "(https://aistudio.google.com/apikey)."
            );
        }
    }

    /**
     * Envoie un prompt à Gemini et retourne la réponse déjà décodée en tableau
     * PHP (le modèle est forcé à répondre en JSON via responseMimeType).
     *
     * $responseSchema est ignoré ici : Gemini ne valide pas structurellement
     * sa sortie, il se base sur la description du schéma dans le prompt.
     *
     * @return array{success:bool, data?:array, raw?:string, error?:string}
     */
    public function generateContent(string $prompt, ?string $model = null, ?array $responseSchema = null, ?array $options = null): array {
        $model = $model ?: self::DEFAULT_MODEL;
        $url = self::API_BASE . '/' . rawurlencode($model) . ':generateContent?key=' . urlencode($this->apiKey);

        $body = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.2,
            ],
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => $options['timeout_seconds'] ?? self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => "Erreur réseau vers Gemini: $curlError"];
        }

        if ($httpCode !== 200) {
            $message = $this->extractErrorMessage($response) ?? "HTTP $httpCode";
            return ['success' => false, 'error' => "Erreur Gemini ($httpCode): $message", 'raw' => $response];
        }

        $decoded = json_decode($response, true);
        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($text === null) {
            $blockReason = $decoded['promptFeedback']['blockReason'] ?? null;
            $message = $blockReason ? "Réponse bloquée par Gemini ($blockReason)" : "Réponse Gemini vide/inattendue";
            return ['success' => false, 'error' => $message, 'raw' => $response];
        }

        $parsed = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => "Réponse Gemini non conforme au format JSON attendu: " . json_last_error_msg(),
                'raw' => $response,
            ];
        }

        return ['success' => true, 'data' => $parsed, 'raw' => $response];
    }

    private function extractErrorMessage($response) {
        $decoded = json_decode($response, true);
        return $decoded['error']['message'] ?? null;
    }

    /**
     * Conversation libre avec recherche Google activée nativement (tool
     * 'google_search', grounding) — pas de responseMimeType JSON ici : forcer
     * du JSON strict désactiverait le grounding côté Gemini, et on veut une
     * réponse rédigée librement (Markdown), pas un objet structuré.
     *
     * Le grounding (outil 'google_search') a un quota Google AI Studio
     * SÉPARÉ du quota de génération de texte de base, et ce quota est
     * souvent à 0 pour la génération de modèle courante tant que la
     * facturation n'est pas activée sur le projet — même quand la
     * génération de texte simple fonctionne très bien avec la même clé. Si
     * l'appel avec recherche échoue pour cette raison (429/quota), on
     * retente une fois SANS l'outil plutôt que de faire échouer tout le
     * chat : mieux vaut une réponse basée sur les données du tableau de
     * bord et les connaissances déjà entraînées du modèle qu'aucune
     * réponse. Le texte renvoyé signale alors explicitement l'absence de
     * recherche internet pour cet échange.
     *
     * @return array{success:bool, text?:string, sources?:array<array{title:?string,url:string}>, error?:string}
     */
    public function generateChatReply(string $systemPrompt, array $history, string $userMessage, ?string $model = null, ?array $options = null): array {
        $model = $model ?: self::DEFAULT_MODEL;

        $result = $this->callChatEndpoint($systemPrompt, $history, $userMessage, $model, $options, true);

        if (!$result['success'] && $this->isSearchQuotaError($result['error'] ?? '')) {
            $fallback = $this->callChatEndpoint($systemPrompt, $history, $userMessage, $model, $options, false);
            if ($fallback['success']) {
                $fallback['text'] =
                    "*Recherche internet indisponible pour le moment (quota de l'outil de recherche épuisé côté " .
                    "fournisseur, souvent lié à la facturation du projet Google AI Studio) — réponse basée " .
                    "uniquement sur les données du tableau de bord et les connaissances déjà entraînées du modèle.*" .
                    "\n\n" . $fallback['text'];
                $fallback['sources'] = [];
            }
            return $fallback;
        }

        return $result;
    }

    private function isSearchQuotaError(string $message): bool {
        return stripos($message, '429') !== false
            || stripos($message, 'quota') !== false
            || stripos($message, 'RESOURCE_EXHAUSTED') !== false;
    }

    private function callChatEndpoint(string $systemPrompt, array $history, string $userMessage, string $model, ?array $options, bool $withSearch): array {
        $url = self::API_BASE . '/' . rawurlencode($model) . ':generateContent?key=' . urlencode($this->apiKey);

        $contents = [];
        foreach ($history as $turn) {
            $role = ($turn['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $contents[] = ['role' => $role, 'parts' => [['text' => (string) ($turn['content'] ?? '')]]];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

        $body = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => $contents,
            'generationConfig' => ['temperature' => 0.3],
        ];
        if ($withSearch) {
            $body['tools'] = [['google_search' => new stdClass()]];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => $options['timeout_seconds'] ?? 120,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => "Erreur réseau vers Gemini: $curlError"];
        }

        if ($httpCode !== 200) {
            $message = $this->extractErrorMessage($response) ?? "HTTP $httpCode";
            return ['success' => false, 'error' => "Erreur Gemini ($httpCode): $message", 'raw' => $response];
        }

        $decoded = json_decode($response, true);
        $candidate = $decoded['candidates'][0] ?? null;

        $text = null;
        if ($candidate) {
            $textParts = [];
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (isset($part['text'])) {
                    $textParts[] = $part['text'];
                }
            }
            $text = $textParts ? implode('', $textParts) : null;
        }

        if ($text === null) {
            $blockReason = $decoded['promptFeedback']['blockReason'] ?? null;
            $message = $blockReason ? "Réponse bloquée par Gemini ($blockReason)" : "Réponse Gemini vide/inattendue";
            return ['success' => false, 'error' => $message, 'raw' => $response];
        }

        $sources = [];
        foreach ($candidate['groundingMetadata']['groundingChunks'] ?? [] as $chunk) {
            $web = $chunk['web'] ?? null;
            if ($web && !empty($web['uri'])) {
                $sources[] = ['title' => $web['title'] ?? null, 'url' => $web['uri']];
            }
        }

        return ['success' => true, 'text' => $text, 'sources' => $sources];
    }
}
