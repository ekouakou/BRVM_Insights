<?php
/**
 * Restructure le texte brut extrait d'un rapport de société cotée (dump
 * pdftotext où les colonnes du PDF s'entremêlent) en un document Markdown
 * propre avec de vrais tableaux (compte de résultat, bilan, flux de
 * trésorerie, notes chiffrées). Mirror exact de
 * class/BulletinMarkdownFormatterService.php, adapté à la structure d'un
 * rapport financier plutôt qu'à un bulletin de marché.
 *
 * Appelé en arrière-plan (voir scripts/format_report_markdown.php) : un
 * rapport complet peut représenter des dizaines de milliers de caractères de
 * texte source et la génération peut prendre plusieurs minutes, largement
 * au-delà de ce qu'une requête web synchrone peut tenir (timeout FastCGI de
 * MAMP à 30s).
 */
class ReportMarkdownFormatterService {
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

    public function format(int $reportId, ?string $provider = null, ?string $model = null): void {
        $provider = $provider ?: self::DEFAULT_PROVIDER;
        if (!isset(self::PROVIDERS[$provider])) {
            $this->persistFailure($reportId, $provider, $model, "Fournisseur IA inconnu: $provider");
            return;
        }
        $model = $model ?: self::PROVIDERS[$provider]['default_model'];

        $report = $this->crud->findById('company_reports', $reportId);
        $company = $report ? $this->crud->findById('companies', $report['company_id']) : null;

        $content = $this->crud->find('company_report_contents', ['report_id' => $reportId]);
        $rawText = $content[0]['extracted_text'] ?? null;

        if (empty($rawText)) {
            $this->persistFailure($reportId, $provider, $model, "Aucun texte extrait pour ce rapport");
            return;
        }

        $truncated = mb_strlen($rawText) > self::MAX_SOURCE_CHARS
            ? mb_substr($rawText, 0, self::MAX_SOURCE_CHARS) . "\n\n[...texte tronqué...]"
            : $rawText;

        $prompt = $this->buildPrompt($report, $company, $truncated);

        try {
            $client = $this->createClient($provider);
        } catch (Exception $e) {
            $this->persistFailure($reportId, $provider, $model, $e->getMessage());
            return;
        }

        $result = $client->generateContent(
            $prompt,
            $model,
            $this->responseSchema(),
            ['timeout_seconds' => self::TIMEOUT_SECONDS, 'max_tokens' => self::MAX_TOKENS]
        );

        if (!$result['success']) {
            $this->persistFailure($reportId, $provider, $model, $result['error']);
            return;
        }

        $markdown = $result['data']['markdown'] ?? null;
        if (empty($markdown)) {
            $this->persistFailure($reportId, $provider, $model, "Réponse IA sans contenu markdown");
            return;
        }

        $this->crud->merge('company_report_contents', [
            'formatted_markdown' => $markdown,
            'markdown_status' => 'success',
            'markdown_error' => null,
            'markdown_provider' => $provider,
            'markdown_model' => $model,
            'markdown_updated_at' => date('Y-m-d H:i:s'),
        ], ['report_id' => $reportId]);
    }

    private function persistFailure(int $reportId, string $provider, ?string $model, string $error): void {
        $this->crud->merge('company_report_contents', [
            'markdown_status' => 'failed',
            'markdown_error' => $error,
            'markdown_provider' => $provider,
            'markdown_model' => $model,
            'markdown_updated_at' => date('Y-m-d H:i:s'),
        ], ['report_id' => $reportId]);
    }

    private function createClient(string $provider): AiClientInterface {
        $class = self::PROVIDERS[$provider]['class'];
        return new $class();
    }

    private function buildPrompt($report, $company, string $sourceText): string {
        $companyLabel = $company ? ($company['symbol'] . ' - ' . $company['name']) : 'entreprise inconnue';
        $reportLabel = $report ? (($report['report_type'] ?? 'rapport') . ' publié le ' . ($report['publish_date'] ?? 'date inconnue')) : 'rapport';

        return <<<PROMPT
Tu reçois le texte brut extrait d'un PDF de rapport financier d'une société
cotée à la BRVM (extraction pdftotext : les colonnes des tableaux originaux
sont parfois entremêlées). Restructure-le entièrement en un document
Markdown propre, avec de vrais tableaux Markdown, en suivant cette
organisation (adapte les sections à ce qui est réellement présent dans le
texte — omets une section si l'information correspondante n'existe pas dans
le texte source, mais n'en invente aucune) :

- Titre avec le nom de la société ($companyLabel) et le rapport concerné ($reportLabel).
- Compte de résultat (produits/charges, résultat d'exploitation, résultat net...) — tableau avec toutes les lignes disponibles, comparatif N / N-1 (et années antérieures si présentes) en colonnes.
- Bilan — actif puis passif, tableau avec toutes les lignes disponibles, comparatif N / N-1.
- Tableau des flux de trésorerie si présent, avec toutes les lignes.
- Notes annexes chiffrées (dettes, immobilisations, provisions, effectifs, engagements...) sous forme de tableaux si le texte les présente déjà sous forme de tableau.
- Ratios ou indicateurs clés si mentionnés (tableau Indicateur | Valeur).
- Rapport du commissaire aux comptes / gouvernance si présent (texte, pas un tableau).
- Toute autre information structurée présente dans le texte source.

RÈGLES IMPÉRATIVES :
- Reproduis TOUTES les lignes de données présentes dans le texte source pour chaque tableau — ne résume pas, ne tronque pas, n'omets aucune ligne (même si un tableau contient des dizaines de lignes).
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
