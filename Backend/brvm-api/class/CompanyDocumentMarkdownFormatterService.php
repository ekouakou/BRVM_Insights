<?php
/**
 * Restructure le texte brut extrait d'un document complémentaire (upload
 * manuel, voir api_company_documents.php) en un document Markdown propre
 * avec de vrais tableaux — mirror exact de
 * class/ReportMarkdownFormatterService.php, adapté à un document dont le
 * type/la structure n'est pas connue à l'avance (contrairement à un rapport
 * scrapé sur brvm.org dont on connaît au moins le report_type).
 *
 * Appelé en arrière-plan (voir scripts/format_company_document_markdown.php) :
 * un document complet peut représenter des dizaines de milliers de
 * caractères de texte source et la génération peut prendre plusieurs
 * minutes, largement au-delà de ce qu'une requête web synchrone peut tenir
 * (timeout FastCGI de MAMP à 30s).
 */
class CompanyDocumentMarkdownFormatterService {
    private const PROVIDERS = [
        'anthropic' => ['class' => 'AnthropicClient', 'default_model' => 'claude-opus-5'],
        'gemini' => ['class' => 'GeminiClient', 'default_model' => 'gemini-flash-lite-latest'],
    ];
    private const DEFAULT_PROVIDER = 'gemini';
    private const TIMEOUT_SECONDS = 280;
    private const MAX_TOKENS = 32000;
    private const MAX_SOURCE_CHARS = 500000;

    private $crud;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
    }

    public function format(int $documentId, ?string $provider = null, ?string $model = null): void {
        $provider = $provider ?: self::DEFAULT_PROVIDER;
        if (!isset(self::PROVIDERS[$provider])) {
            $this->persistFailure($documentId, $provider, $model, "Fournisseur IA inconnu: $provider");
            return;
        }
        $model = $model ?: self::PROVIDERS[$provider]['default_model'];

        $document = $this->crud->findById('company_documents', $documentId);
        $company = $document ? $this->crud->findById('companies', $document['company_id']) : null;

        $content = $this->crud->find('company_document_contents', ['document_id' => $documentId]);
        $rawText = $content[0]['extracted_text'] ?? null;

        if (empty($rawText)) {
            $this->persistFailure($documentId, $provider, $model, "Aucun texte extrait pour ce document");
            return;
        }

        $truncated = mb_strlen($rawText) > self::MAX_SOURCE_CHARS
            ? mb_substr($rawText, 0, self::MAX_SOURCE_CHARS) . "\n\n[...texte tronqué...]"
            : $rawText;

        $prompt = $this->buildPrompt($document, $company, $truncated);

        try {
            $client = $this->createClient($provider);
        } catch (Exception $e) {
            $this->persistFailure($documentId, $provider, $model, $e->getMessage());
            return;
        }

        $result = $client->generateContent(
            $prompt,
            $model,
            $this->responseSchema(),
            ['timeout_seconds' => self::TIMEOUT_SECONDS, 'max_tokens' => self::MAX_TOKENS]
        );

        if (!$result['success']) {
            $this->persistFailure($documentId, $provider, $model, $result['error']);
            return;
        }

        $markdown = $result['data']['markdown'] ?? null;
        if (empty($markdown)) {
            $this->persistFailure($documentId, $provider, $model, "Réponse IA sans contenu markdown");
            return;
        }

        $this->crud->merge('company_document_contents', [
            'formatted_markdown' => $markdown,
            'markdown_status' => 'success',
            'markdown_error' => null,
            'markdown_provider' => $provider,
            'markdown_model' => $model,
            'markdown_updated_at' => date('Y-m-d H:i:s'),
        ], ['document_id' => $documentId]);
    }

    private function persistFailure(int $documentId, string $provider, ?string $model, string $error): void {
        $this->crud->merge('company_document_contents', [
            'markdown_status' => 'failed',
            'markdown_error' => $error,
            'markdown_provider' => $provider,
            'markdown_model' => $model,
            'markdown_updated_at' => date('Y-m-d H:i:s'),
        ], ['document_id' => $documentId]);
    }

    private function createClient(string $provider): AiClientInterface {
        $class = self::PROVIDERS[$provider]['class'];
        return new $class();
    }

    private function buildPrompt($document, $company, string $sourceText): string {
        $companyLabel = $company ? ($company['symbol'] . ' - ' . $company['name']) : 'entreprise inconnue';
        $documentLabel = $document ? $document['title'] : 'document';

        return <<<PROMPT
Tu reçois le texte brut extrait d'un PDF ajouté manuellement comme ressource
complémentaire pour une société cotée à la BRVM (extraction pdftotext : les
colonnes des tableaux originaux sont parfois entremêlées). Ce document peut
être de nature variée (rapport détaillé publié sur le site de l'entreprise,
présentation investisseurs, communiqué...) — adapte-toi à son contenu réel.
Restructure-le entièrement en un document Markdown propre, avec de vrais
tableaux Markdown pour toute donnée chiffrée tabulaire, en suivant cette
organisation générale (adapte les sections à ce qui est réellement présent
dans le texte — omets une section si l'information correspondante n'existe
pas dans le texte source, mais n'en invente aucune) :

- Titre avec le nom de la société ($companyLabel) et le document concerné ($documentLabel).
- Toute donnée financière chiffrée (compte de résultat, bilan, flux de trésorerie, ratios...) sous forme de tableaux Markdown, comparatif dans le temps si présent.
- Points clés / synthèse si le document en contient une.
- Toute autre information structurée présente dans le texte source (narratif conservé en texte, pas en tableau).

RÈGLES IMPÉRATIVES :
- Reproduis TOUTES les lignes de données présentes dans le texte source pour chaque tableau — ne résume pas, ne tronque pas, n'omets aucune ligne.
- N'invente AUCUNE donnée : si une valeur est illisible ou absente dans le texte source, mets "—".
- Utilise le format markdown table standard (| colonne | ... | avec une ligne de séparation |---|---|...).
- Le résultat va dans le champ JSON "markdown" ci-dessous — pas de texte avant/après ce document, pas de balises de code (```) autour de l'ensemble du document.

Texte source :
$sourceText
PROMPT;
    }

    private function responseSchema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['markdown'],
            'properties' => [
                'markdown' => ['type' => 'string'],
            ],
        ];
    }
}
