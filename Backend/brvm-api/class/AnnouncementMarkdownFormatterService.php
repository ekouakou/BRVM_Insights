<?php
/**
 * Restructure le texte brut extrait d'un PDF d'annonce émetteur/publication
 * BRVM (avis, convocation d'AG, notation, communiqué, franchissement de
 * seuil...) en Markdown propre — mirror de BulletinMarkdownFormatterService
 * pour issuer_announcement_contents. Les annonces sont bien plus courtes
 * qu'un bulletin (quelques pages), mais la génération passe quand même par
 * le script d'arrière-plan (scripts/format_announcement_markdown.php) pour
 * rester sous le timeout FastCGI de MAMP (30s) quel que soit le document.
 */
class AnnouncementMarkdownFormatterService {
    private const PROVIDERS = [
        'anthropic' => ['class' => 'AnthropicClient', 'default_model' => 'claude-opus-5'],
        'gemini' => ['class' => 'GeminiClient', 'default_model' => 'gemini-flash-lite-latest'],
    ];
    private const DEFAULT_PROVIDER = 'gemini';
    private const TIMEOUT_SECONDS = 280;
    private const MAX_TOKENS = 32000;
    private const MAX_SOURCE_CHARS = 300000;

    private $crud;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
    }

    public function format(int $announcementId, ?string $provider = null, ?string $model = null): void {
        $provider = $provider ?: self::DEFAULT_PROVIDER;
        if (!isset(self::PROVIDERS[$provider])) {
            $this->persistFailure($announcementId, $provider, $model, "Fournisseur IA inconnu: $provider");
            return;
        }
        $model = $model ?: self::PROVIDERS[$provider]['default_model'];

        $content = $this->crud->find('issuer_announcement_contents', ['announcement_id' => $announcementId]);
        $rawText = $content[0]['extracted_text'] ?? null;

        if (empty($rawText)) {
            $this->persistFailure($announcementId, $provider, $model, "Aucun texte extrait pour cette annonce");
            return;
        }

        $truncated = mb_strlen($rawText) > self::MAX_SOURCE_CHARS
            ? mb_substr($rawText, 0, self::MAX_SOURCE_CHARS) . "\n\n[...texte tronqué...]"
            : $rawText;

        $prompt = $this->buildPrompt($truncated);

        try {
            $client = $this->createClient($provider);
        } catch (Exception $e) {
            $this->persistFailure($announcementId, $provider, $model, $e->getMessage());
            return;
        }

        $result = $client->generateContent(
            $prompt,
            $model,
            $this->responseSchema(),
            ['timeout_seconds' => self::TIMEOUT_SECONDS, 'max_tokens' => self::MAX_TOKENS]
        );

        if (!$result['success']) {
            $this->persistFailure($announcementId, $provider, $model, $result['error']);
            return;
        }

        $markdown = $result['data']['markdown'] ?? null;
        if (empty($markdown)) {
            $this->persistFailure($announcementId, $provider, $model, "Réponse IA sans contenu markdown");
            return;
        }

        $this->crud->merge('issuer_announcement_contents', [
            'formatted_markdown' => $markdown,
            'markdown_status' => 'success',
            'markdown_error' => null,
            'markdown_provider' => $provider,
            'markdown_model' => $model,
            'markdown_updated_at' => date('Y-m-d H:i:s'),
        ], ['announcement_id' => $announcementId]);
    }

    private function persistFailure(int $announcementId, string $provider, ?string $model, string $error): void {
        $this->crud->merge('issuer_announcement_contents', [
            'markdown_status' => 'failed',
            'markdown_error' => $error,
            'markdown_provider' => $provider,
            'markdown_model' => $model,
            'markdown_updated_at' => date('Y-m-d H:i:s'),
        ], ['announcement_id' => $announcementId]);
    }

    private function createClient(string $provider): AiClientInterface {
        $class = self::PROVIDERS[$provider]['class'];
        return new $class();
    }

    private function buildPrompt(string $sourceText): string {
        return <<<PROMPT
Tu reçois le texte brut extrait d'un PDF d'annonce publiée sur le site de
la BRVM (Bourse Régionale des Valeurs Mobilières) : avis de convocation
d'assemblée générale, projet de résolutions, notation financière, avis de
paiement de dividende, communiqué d'émetteur, franchissement de seuil,
avis du marché ou publication économique. L'extraction pdftotext peut
avoir entremêlé la mise en page.

Restructure-le entièrement en un document Markdown propre et fidèle :
- Titre principal reprenant l'objet exact et l'émetteur/l'entité concernée.
- Métadonnées en tête si présentes dans le texte (date, référence/numéro
  d'avis, entité émettrice).
- Le corps intégral du document, restructuré en sections avec titres — si
  le document contient des listes (ordres du jour, résolutions), rends-les
  en listes Markdown ; s'il contient des tableaux (calendriers, montants,
  notations), rends-les en vrais tableaux Markdown.
- Reproduis TOUT le contenu textuel significatif — ne résume pas, ne
  tronque pas. Les mentions purement décoratives (en-têtes/pieds de page
  répétés) peuvent être omises.

RÈGLES IMPÉRATIVES :
- N'invente AUCUNE donnée : si une valeur est illisible dans le texte
  source, mets "—".
- Le résultat va dans le champ JSON "markdown" — pas de texte avant/après,
  pas de balises de code (```) autour de l'ensemble du document.

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
