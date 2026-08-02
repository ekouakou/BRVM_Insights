<?php
/**
 * Restructure le texte brut extrait d'un Bulletin Officiel de la Cote (BOC)
 * — un dump pdftotext où les colonnes du PDF s'entremêlent — en un document
 * Markdown propre avec de vrais tableaux (indices, statistiques de marché,
 * plus fortes hausses/baisses, actions et obligations ligne par ligne).
 *
 * Appelé en arrière-plan (voir scripts/format_bulletin_markdown.php) : un
 * bulletin complet représente ~150k caractères de texte source et la
 * génération prend couramment ~2 minutes, largement au-delà de ce qu'une
 * requête web synchrone peut tenir (timeout FastCGI de MAMP à 30s).
 */
class BulletinMarkdownFormatterService {
    private const PROVIDERS = [
        'anthropic' => ['class' => 'AnthropicClient', 'default_model' => 'claude-opus-5'],
        'gemini' => ['class' => 'GeminiClient', 'default_model' => 'gemini-flash-lite-latest'],
    ];
    private const DEFAULT_PROVIDER = 'gemini';
    private const TIMEOUT_SECONDS = 280;
    private const MAX_TOKENS = 32000; // repli pour Anthropic si un jour utilisé ici (le document généré peut être volumineux)
    private const MAX_SOURCE_CHARS = 500000;

    private $crud;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
    }

    public function format(int $bulletinId, ?string $provider = null, ?string $model = null): void {
        $provider = $provider ?: self::DEFAULT_PROVIDER;
        if (!isset(self::PROVIDERS[$provider])) {
            $this->persistFailure($bulletinId, $provider, $model, "Fournisseur IA inconnu: $provider");
            return;
        }
        $model = $model ?: self::PROVIDERS[$provider]['default_model'];

        $content = $this->crud->find('market_bulletin_contents', ['bulletin_id' => $bulletinId]);
        $rawText = $content[0]['extracted_text'] ?? null;

        if (empty($rawText)) {
            $this->persistFailure($bulletinId, $provider, $model, "Aucun texte extrait pour ce bulletin");
            return;
        }

        $truncated = mb_strlen($rawText) > self::MAX_SOURCE_CHARS
            ? mb_substr($rawText, 0, self::MAX_SOURCE_CHARS) . "\n\n[...texte tronqué...]"
            : $rawText;

        $prompt = $this->buildPrompt($truncated);

        try {
            $client = $this->createClient($provider);
        } catch (Exception $e) {
            $this->persistFailure($bulletinId, $provider, $model, $e->getMessage());
            return;
        }

        $result = $client->generateContent(
            $prompt,
            $model,
            $this->responseSchema(),
            ['timeout_seconds' => self::TIMEOUT_SECONDS, 'max_tokens' => self::MAX_TOKENS]
        );

        if (!$result['success']) {
            $this->persistFailure($bulletinId, $provider, $model, $result['error']);
            return;
        }

        $markdown = $result['data']['markdown'] ?? null;
        if (empty($markdown)) {
            $this->persistFailure($bulletinId, $provider, $model, "Réponse IA sans contenu markdown");
            return;
        }

        $this->crud->merge('market_bulletin_contents', [
            'formatted_markdown' => $markdown,
            'markdown_status' => 'success',
            'markdown_error' => null,
            'markdown_provider' => $provider,
            'markdown_model' => $model,
            'markdown_updated_at' => date('Y-m-d H:i:s'),
        ], ['bulletin_id' => $bulletinId]);
    }

    private function persistFailure(int $bulletinId, string $provider, ?string $model, string $error): void {
        $this->crud->merge('market_bulletin_contents', [
            'markdown_status' => 'failed',
            'markdown_error' => $error,
            'markdown_provider' => $provider,
            'markdown_model' => $model,
            'markdown_updated_at' => date('Y-m-d H:i:s'),
        ], ['bulletin_id' => $bulletinId]);
    }

    private function createClient(string $provider): AiClientInterface {
        $class = self::PROVIDERS[$provider]['class'];
        return new $class();
    }

    private function buildPrompt(string $sourceText): string {
        return <<<PROMPT
Tu reçois le texte brut extrait d'un PDF de Bulletin Officiel de la Cote
(BOC) de la BRVM (extraction pdftotext : les colonnes du tableau original
sont parfois entremêlées). Restructure-le entièrement en un document
Markdown propre, avec de vrais tableaux Markdown, en suivant cette
organisation (adapte les sections à ce qui est réellement présent dans le
texte — omets une section si l'information correspondante n'existe pas
dans le texte source, mais n'en invente aucune) :

- Titre avec le numéro de bulletin et la date complète.
- Indices BRVM (tableau : Indice | Niveau | Variation jour | Variation annuelle).
- Statistiques du marché, actions puis obligations (tableau : Indicateur | Niveau | Évol. jour).
- Plus fortes hausses / plus fortes baisses (tableau : Titre | Cours | Evol. jour | Evol. annuelle).
- Indices par compartiment, sectoriels, total return si présents (tableau : ... | Nb sociétés | Valeur | Evol. jour | Evol. annuelle | Volume | Valeur (FCFA) | PER moyen).
- Indicateurs du marché (PER moyen, rendement, nombre de sociétés cotées, etc.).
- Actions par compartiment : un tableau complet PAR compartiment, avec toutes les colonnes disponibles (Symbole | Titre | Secteur | Précédent | Ouv. | Clôt. | Var. jour | Volume | Valeur | Var. annuelle | Div. net | Date div. | Rdt Net | PER), et une ligne TOTAL en bas de chaque tableau.
- Marché des droits si présent.
- Obligations : un tableau par catégorie (souveraines/institutions/entreprises/GSS/FCTC/sukuk/convertibles), avec toutes les lignes disponibles.
- Carnets d'ordres si présents.
- OPCVM (valeurs liquidatives) si présentes.
- Opérations en cours, avis, communiqués, calendrier des assemblées générales si présents.

RÈGLES IMPÉRATIVES :
- Reproduis TOUTES les lignes de données présentes dans le texte source pour chaque tableau — ne résume pas, ne tronque pas, n'omets aucune ligne (même si un tableau contient des dizaines ou des centaines de lignes).
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
