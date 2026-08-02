<?php
/**
 * Client HTTP minimal pour l'API Gemini (Google) — generateContent en mode
 * JSON forcé. Dédié (pas HttpFetcher, pensé pour du GET HTML/binaire avec
 * repli en cascade) : ici il faut un POST JSON authentifié par query param.
 */
class GeminiClient implements AiClientInterface {
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
}
