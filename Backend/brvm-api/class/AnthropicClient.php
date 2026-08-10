<?php
/**
 * Client HTTP minimal pour l'API Anthropic (Claude) — Messages API en sortie
 * structurée (output_config.format json_schema). Curl direct comme
 * GeminiClient : ce projet n'a pas de composer.json/SDK, donc pas de SDK
 * officiel Anthropic ici non plus, pour rester cohérent avec le reste du code.
 */
class AnthropicClient implements AiClientInterface, AiChatClientInterface {
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_MODEL = 'claude-opus-5';
    private const MAX_TOKENS = 8192;
    private const TIMEOUT_SECONDS = 90;

    private $apiKey;

    public function __construct() {
        $this->apiKey = getenv('ANTHROPIC_API_KEY');

        if (empty($this->apiKey)) {
            throw new Exception(
                "ANTHROPIC_API_KEY absente. Copie .env.example en .env et renseigne ta clé " .
                "(https://console.anthropic.com/settings/keys)."
            );
        }
    }

    /**
     * $responseSchema : par défaut (null), utilise le schéma de l'analyse
     * individuelle d'un rapport (responseSchema() ci-dessous). Un appelant
     * avec un besoin de sortie différent (ex: comparaison de plusieurs
     * rapports) peut fournir son propre schéma JSON.
     *
     * @return array{success:bool, data?:array, raw?:string, error?:string}
     */
    public function generateContent(string $prompt, ?string $model = null, ?array $responseSchema = null, ?array $options = null): array {
        $model = $model ?: self::DEFAULT_MODEL;

        $body = [
            'model' => $model,
            'max_tokens' => $options['max_tokens'] ?? self::MAX_TOKENS,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'output_config' => [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => $responseSchema ?? $this->responseSchema(),
                ],
            ],
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::API_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT => $options['timeout_seconds'] ?? self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => "Erreur réseau vers Anthropic: $curlError"];
        }

        $decoded = json_decode($response, true);

        if ($httpCode !== 200) {
            $message = $decoded['error']['message'] ?? "HTTP $httpCode";
            return ['success' => false, 'error' => "Erreur Anthropic ($httpCode): $message", 'raw' => $response];
        }

        if (($decoded['stop_reason'] ?? null) === 'refusal') {
            $category = $decoded['stop_details']['category'] ?? null;
            $message = $category ? "Requête refusée par Anthropic (catégorie: $category)" : "Requête refusée par Anthropic";
            return ['success' => false, 'error' => $message, 'raw' => $response];
        }

        $text = null;
        foreach ($decoded['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text = $block['text'];
                break;
            }
        }

        if ($text === null) {
            return ['success' => false, 'error' => "Réponse Anthropic sans bloc texte", 'raw' => $response];
        }

        $parsed = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => "Réponse Anthropic non conforme au format JSON attendu: " . json_last_error_msg(),
                'raw' => $response,
            ];
        }

        return ['success' => true, 'data' => $parsed, 'raw' => $response];
    }

    /**
     * Schéma JSON forcé via output_config.format — mêmes champs que le
     * schéma décrit dans le prompt (voir ReportAnalysisService::buildPrompt).
     * Limitations de l'API structured outputs : pas de schémas récursifs, pas
     * de contraintes numériques/longueur, additionalProperties uniquement à
     * false (d'où "glossary" en tableau {term, explanation} plutôt qu'en map).
     */
    private function responseSchema(): array {
        $nullableString = ['type' => ['string', 'null']];
        $nullableNumber = ['type' => ['number', 'null']];

        $keyFinancialsFields = [
            'currency', 'revenue', 'revenue_prior_year', 'revenue_growth_percent',
            'gross_profit', 'gross_margin_percent', 'operating_income', 'operating_margin_percent',
            'ebitda', 'ebitda_margin_percent', 'net_income', 'net_income_prior_year', 'net_margin_percent',
            'operating_cash_flow', 'capex', 'free_cash_flow',
            'total_debt', 'total_equity', 'debt_to_equity', 'interest_expense', 'interest_coverage_ratio', 'debt_to_ebitda',
            'current_assets', 'current_liabilities', 'current_ratio', 'quick_ratio', 'working_capital', 'cash_position',
            'receivable_days', 'payable_days', 'inventory_days',
            'dividend_per_share', 'roe_percent', 'roa_percent',
        ];
        $keyFinancialsProperties = ['currency' => $nullableString];
        foreach ($keyFinancialsFields as $field) {
            if ($field !== 'currency') {
                $keyFinancialsProperties[$field] = $nullableNumber;
            }
        }

        return [
            'type' => 'object',
            'properties' => [
                'executive_summary' => ['type' => 'string'],
                'company_overview' => $nullableString,
                'key_financials' => [
                    'type' => 'object',
                    'properties' => $keyFinancialsProperties,
                    'required' => $keyFinancialsFields,
                    'additionalProperties' => false,
                ],
                'financial_analysis' => $nullableString,
                'growth_trends' => $nullableString,
                'cash_flow_analysis' => $nullableString,
                'swot' => [
                    'type' => 'object',
                    'properties' => [
                        'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'weaknesses' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'opportunities' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'threats' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['strengths', 'weaknesses', 'opportunities', 'threats'],
                    'additionalProperties' => false,
                ],
                'risks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                        ],
                        'required' => ['category', 'description'],
                        'additionalProperties' => false,
                    ],
                ],
                'governance_and_audit' => $nullableString,
                'outlook_guidance' => $nullableString,
                'market_context_note' => $nullableString,
                'technical_reading' => $nullableString,
                'valuation_assessment' => [
                    'type' => 'object',
                    'properties' => [
                        'shares_outstanding' => $nullableNumber,
                        'eps' => $nullableNumber,
                        'book_value_per_share' => $nullableNumber,
                        'pe_ratio' => $nullableNumber,
                        'price_to_book' => $nullableNumber,
                        'ev_to_ebitda' => $nullableNumber,
                        'dividend_yield_percent' => $nullableNumber,
                        'payout_ratio_percent' => $nullableNumber,
                        'verdict' => ['enum' => ['sous-coté', 'surcoté', 'correctement valorisé', 'indéterminable']],
                        'rationale' => ['type' => 'string'],
                    ],
                    'required' => ['shares_outstanding', 'eps', 'book_value_per_share', 'pe_ratio', 'price_to_book', 'ev_to_ebitda', 'dividend_yield_percent', 'payout_ratio_percent', 'verdict', 'rationale'],
                    'additionalProperties' => false,
                ],
                'investment_thesis' => [
                    'type' => 'object',
                    'properties' => [
                        'bull_case' => $nullableString,
                        'bear_case' => $nullableString,
                        'key_watch_points' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['bull_case', 'bear_case', 'key_watch_points'],
                    'additionalProperties' => false,
                ],
                'data_quality_note' => $nullableString,
                'glossary' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'term' => ['type' => 'string'],
                            'explanation' => ['type' => 'string'],
                        ],
                        'required' => ['term', 'explanation'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => [
                'executive_summary', 'company_overview', 'key_financials', 'financial_analysis',
                'growth_trends', 'cash_flow_analysis', 'swot', 'risks', 'governance_and_audit',
                'outlook_guidance', 'market_context_note', 'technical_reading', 'valuation_assessment',
                'investment_thesis', 'data_quality_note', 'glossary',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Conversation libre avec recherche web activée nativement (server tool
     * 'web_search') — Anthropic exécute les recherches côté serveur dans le
     * même appel (pas de boucle d'exécution d'outil à gérer ici), et renvoie
     * la réponse finale avec, le cas échéant, des blocs de citations pointant
     * vers les pages utilisées. Pas de output_config JSON ici : on veut du
     * texte libre (Markdown), pas un objet structuré.
     *
     * Comme pour GeminiClient::generateChatReply(), si l'appel avec l'outil
     * web_search échoue pour une raison de quota/plan (429, ou le tool
     * lui-même indisponible sur le plan tarifaire), on retente une fois SANS
     * l'outil plutôt que de faire échouer tout le chat.
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
                    "*Recherche internet indisponible pour le moment (quota/plan de l'outil de recherche côté " .
                    "fournisseur) — réponse basée uniquement sur les données du tableau de bord et les " .
                    "connaissances déjà entraînées du modèle.*\n\n" . $fallback['text'];
                $fallback['sources'] = [];
            }
            return $fallback;
        }

        return $result;
    }

    private function isSearchQuotaError(string $message): bool {
        return stripos($message, '429') !== false
            || stripos($message, 'quota') !== false
            || stripos($message, 'rate_limit') !== false;
    }

    private function callChatEndpoint(string $systemPrompt, array $history, string $userMessage, string $model, ?array $options, bool $withSearch): array {
        $messages = [];
        foreach ($history as $turn) {
            $role = ($turn['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => (string) ($turn['content'] ?? '')];
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $body = [
            'model' => $model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'system' => $systemPrompt,
            'messages' => $messages,
        ];
        if ($withSearch) {
            $body['tools'] = [
                ['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5],
            ];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::API_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT => $options['timeout_seconds'] ?? 120,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => "Erreur réseau vers Anthropic: $curlError"];
        }

        $decoded = json_decode($response, true);

        if ($httpCode !== 200) {
            $message = $decoded['error']['message'] ?? "HTTP $httpCode";
            return ['success' => false, 'error' => "Erreur Anthropic ($httpCode): $message", 'raw' => $response];
        }

        if (($decoded['stop_reason'] ?? null) === 'refusal') {
            $category = $decoded['stop_details']['category'] ?? null;
            $message = $category ? "Requête refusée par Anthropic (catégorie: $category)" : "Requête refusée par Anthropic";
            return ['success' => false, 'error' => $message, 'raw' => $response];
        }

        $textParts = [];
        $sourcesByUrl = [];
        foreach ($decoded['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $textParts[] = $block['text'];
                foreach ($block['citations'] ?? [] as $citation) {
                    if (!empty($citation['url'])) {
                        $sourcesByUrl[$citation['url']] = ['title' => $citation['title'] ?? null, 'url' => $citation['url']];
                    }
                }
            }
        }

        if (!$textParts) {
            return ['success' => false, 'error' => "Réponse Anthropic sans bloc texte", 'raw' => $response];
        }

        return ['success' => true, 'text' => implode("\n\n", $textParts), 'sources' => array_values($sourcesByUrl)];
    }
}
