<?php
/**
 * Client HTTP minimal pour l'API Grok (xAI) — Chat Completions au format
 * compatible OpenAI (endpoint /v1/chat/completions, auth Bearer). Curl direct
 * comme GeminiClient/AnthropicClient : pas de SDK, pas de composer.json dans
 * ce projet.
 */
class GrokClient implements AiClientInterface {
    private const API_URL = 'https://api.x.ai/v1/chat/completions';
    private const DEFAULT_MODEL = 'grok-4-fast-reasoning';
    private const TIMEOUT_SECONDS = 90;

    private $apiKey;

    public function __construct() {
        $this->apiKey = getenv('GROK_API_KEY');

        if (empty($this->apiKey)) {
            throw new Exception(
                "GROK_API_KEY absente. Copie .env.example en .env et renseigne ta clé " .
                "(https://console.x.ai)."
            );
        }
    }

    /**
     * $responseSchema est ignoré ici (comme GeminiClient) : on force juste le
     * mode JSON (response_format json_object, pas de validation structurelle
     * côté xAI via ce client) et on s'appuie sur la description du schéma
     * dans le prompt, cohérent avec le reste du pipeline d'analyse.
     *
     * @return array{success:bool, data?:array, raw?:string, error?:string}
     */
    public function generateContent(string $prompt, ?string $model = null, ?array $responseSchema = null, ?array $options = null): array {
        $model = $model ?: self::DEFAULT_MODEL;

        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.2,
        ];
        if (isset($options['max_tokens'])) {
            $body['max_tokens'] = $options['max_tokens'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::API_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => $options['timeout_seconds'] ?? self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => "Erreur réseau vers Grok: $curlError"];
        }

        $decoded = json_decode($response, true);

        if ($httpCode !== 200) {
            // Le champ "error" de l'API xAI est parfois une simple chaîne (ex:
            // erreurs de facturation/permissions), parfois un objet {message}
            // (ex: erreurs de validation compatibles OpenAI) — on gère les deux.
            $errorField = $decoded['error'] ?? null;
            $message = is_array($errorField) ? ($errorField['message'] ?? null) : $errorField;
            $message = $message ?? "HTTP $httpCode";
            return ['success' => false, 'error' => "Erreur Grok ($httpCode): $message", 'raw' => $response];
        }

        $finishReason = $decoded['choices'][0]['finish_reason'] ?? null;
        if ($finishReason === 'content_filter') {
            return ['success' => false, 'error' => "Réponse filtrée par Grok (content_filter)", 'raw' => $response];
        }

        $text = $decoded['choices'][0]['message']['content'] ?? null;
        if ($text === null) {
            return ['success' => false, 'error' => "Réponse Grok vide/inattendue", 'raw' => $response];
        }

        $parsed = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => "Réponse Grok non conforme au format JSON attendu: " . json_last_error_msg(),
                'raw' => $response,
            ];
        }

        return ['success' => true, 'data' => $parsed, 'raw' => $response];
    }
}
