<?php
/**
 * Extraction IA structurée du marché obligataire, tel qu'imprimé dans les
 * tableaux « Obligations » de chaque Bulletin Officiel de la Cote (BOC).
 * Même architecture que BulletinStockMetricsService (voir ce fichier pour le
 * pattern d'ensemble) : chaque ligne obligataire du bulletin devient une
 * ligne interrogeable dans bulletin_bond_metrics, une extraction par
 * bulletin écrase la précédente (source de vérité, pas d'accumulation).
 *
 * Pourquoi un service séparé plutôt qu'une extension de
 * BulletinStockMetricsService : les colonnes du tableau obligations
 * (coupon couru, périodicité, échéance, type d'amortissement) n'ont aucun
 * équivalent côté actions, et l'identification se fait par symbole/titre
 * seul — pas de rattachement à `companies` (la plupart des émetteurs
 * obligataires sont des États ou des fonds de titrisation, pas des sociétés
 * cotées en actions).
 */
class BulletinBondMetricsService {
    private const MAX_BULLETIN_CHARS = 500000;
    /** Le marché obligataire d'un bulletin dépasse souvent 150 lignes (vs ~47 actions) : réponse JSON bien plus volumineuse, même ordre de grandeur que BulletinMarkdownFormatterService. */
    private const TIMEOUT_SECONDS = 280;

    /** Même registre de fournisseurs que BulletinStockMetricsService. */
    private const PROVIDERS = [
        'anthropic' => ['class' => 'AnthropicClient', 'default_model' => 'claude-opus-5'],
        'gemini' => ['class' => 'GeminiClient', 'default_model' => 'gemini-flash-lite-latest'],
        'grok' => ['class' => 'GrokClient', 'default_model' => 'grok-4-fast-reasoning'],
    ];
    private const DEFAULT_PROVIDER = 'gemini';

    private const CATEGORIES = [
        'sovereign', 'financial_institution', 'corporate',
        'gss_financial', 'gss_corporate',
        'fctc_public', 'fctc_financial', 'fctc_corporate', 'fctc_gss_corporate',
        'sukuk', 'convertible',
    ];

    private $crud;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
    }

    /**
     * Lance (ou relit en cache) l'extraction du marché obligataire d'un
     * bulletin. Comme pour les métriques par valeur : pas de cache par
     * (bulletin_id, provider, model), une réextraction remplace les lignes
     * existantes pour ce bulletin.
     */
    public function extract(int $bulletinId, ?string $provider = null, ?string $model = null, bool $forceRefresh = false): array {
        $provider = $provider ?: self::DEFAULT_PROVIDER;
        if (!isset(self::PROVIDERS[$provider])) {
            throw new Exception("Fournisseur IA inconnu: $provider. Disponibles: " . implode(', ', array_keys(self::PROVIDERS)));
        }
        $model = $model ?: self::PROVIDERS[$provider]['default_model'];

        $bulletin = $this->crud->findById('market_bulletins', $bulletinId);
        if (!$bulletin) {
            throw new Exception("Bulletin non trouvé (id=$bulletinId)");
        }

        $content = $this->crud->find('market_bulletin_contents', ['bulletin_id' => $bulletinId]);
        $row = $content[0] ?? null;

        $usingMarkdown = !empty($row['formatted_markdown']) && $row['markdown_status'] === 'success';
        $sourceText = $usingMarkdown ? $row['formatted_markdown'] : ($row['extracted_text'] ?? null);

        if (empty($sourceText)) {
            throw new Exception(
                "Le texte de ce bulletin n'a pas encore été extrait. " .
                "Utilise le bouton 'Traiter' avant de l'extraire."
            );
        }

        if (!$forceRefresh && ($row['bond_metrics_status'] ?? null) === 'success') {
            return $this->getStoredMetrics($bulletinId, $bulletin, true);
        }

        $truncatedText = $this->truncate($sourceText);
        $prompt = $this->buildPrompt($bulletin, $truncatedText);
        $client = $this->createClient($provider);
        $aiResult = $client->generateContent($prompt, $model, $this->responseSchema(), ['timeout_seconds' => self::TIMEOUT_SECONDS]);

        $update = [
            'bond_metrics_provider' => $provider,
            'bond_metrics_model' => $model,
            'bond_metrics_updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($aiResult['success']) {
            $bonds = $aiResult['data']['bonds'] ?? [];

            // Réextraction = source de vérité : on repart de zéro pour ce
            // bulletin plutôt que d'accumuler des doublons à chaque relance.
            // DynamiqueCrud::remove() ajoute un LIMIT 1 systématique (pensé
            // pour supprimer UNE ligne identifiée) : sur plusieurs dizaines
            // de lignes obligataires par bulletin, il n'en supprimerait
            // qu'une seule — d'où un DELETE direct, sans limite, ici (même
            // correctif que BulletinStockMetricsService/
            // BulletinCorporateActionsService).
            $this->crud->executeCustomQuery("DELETE FROM bulletin_bond_metrics WHERE bulletin_id = ?", [$bulletinId]);

            foreach ($bonds as $bond) {
                $symbol = trim((string) ($bond['symbol'] ?? ''));
                if ($symbol === '') continue;

                $category = (string) ($bond['category'] ?? '');
                if (!in_array($category, self::CATEGORIES, true)) {
                    $category = 'corporate';
                }

                $dayStatus = strtoupper(trim((string) ($bond['day_price_status'] ?? '')));
                if (!in_array($dayStatus, ['NC', 'SP'], true)) {
                    $dayStatus = null;
                }

                $this->crud->persist('bulletin_bond_metrics', [
                    'bulletin_id' => $bulletinId,
                    'publish_date' => $bulletin['publish_date'],
                    'symbol' => $symbol,
                    'title' => $bond['title'] ?? null,
                    'category' => $category,
                    'nominal_value' => is_numeric($bond['nominal_value'] ?? null) ? $bond['nominal_value'] : null,
                    'previous_price' => is_numeric($bond['previous_price'] ?? null) ? $bond['previous_price'] : null,
                    'day_price' => is_numeric($bond['day_price'] ?? null) ? $bond['day_price'] : null,
                    'day_price_status' => $dayStatus,
                    'reference_price' => is_numeric($bond['reference_price'] ?? null) ? $bond['reference_price'] : null,
                    'volume' => is_numeric($bond['volume'] ?? null) ? $bond['volume'] : null,
                    'value_traded' => is_numeric($bond['value_traded'] ?? null) ? $bond['value_traded'] : null,
                    'accrued_coupon' => is_numeric($bond['accrued_coupon'] ?? null) ? $bond['accrued_coupon'] : null,
                    'period_type' => in_array($bond['period_type'] ?? null, ['A', 'S', 'T'], true) ? $bond['period_type'] : null,
                    'net_amount' => is_numeric($bond['net_amount'] ?? null) ? $bond['net_amount'] : null,
                    'maturity_date' => $this->validDateOrNull($bond['maturity_date'] ?? null),
                    'amortization_type' => $bond['amortization_type'] ?? null,
                ]);
            }

            $update['bond_metrics_status'] = 'success';
            $update['bond_metrics_error'] = null;
        } else {
            $update['bond_metrics_status'] = 'error';
            $update['bond_metrics_error'] = $aiResult['error'];
        }

        if ($row) {
            $this->crud->merge('market_bulletin_contents', $update, ['bulletin_id' => $bulletinId]);
        }

        if (!$aiResult['success']) {
            throw new Exception($aiResult['error']);
        }

        return $this->getStoredMetrics($bulletinId, $bulletin, false);
    }

    /**
     * Lecture cache-only des lignes obligataires déjà extraites pour un bulletin.
     */
    public function getStoredMetrics(int $bulletinId, ?array $bulletin = null, bool $cached = true): array {
        $bulletin = $bulletin ?: $this->crud->findById('market_bulletins', $bulletinId);
        if (!$bulletin) {
            throw new Exception("Bulletin non trouvé (id=$bulletinId)");
        }

        $content = $this->crud->find('market_bulletin_contents', ['bulletin_id' => $bulletinId]);
        $contentRow = $content[0] ?? null;

        $rows = $this->crud->executeCustomQuery(
            "SELECT * FROM bulletin_bond_metrics WHERE bulletin_id = ? ORDER BY category ASC, symbol ASC",
            [$bulletinId]
        ) ?: [];

        return [
            'bulletin' => [
                'id' => $bulletin['id'],
                'title' => $bulletin['title'],
                'publish_date' => $bulletin['publish_date'],
            ],
            'status' => $contentRow['bond_metrics_status'] ?? null,
            'error_message' => $contentRow['bond_metrics_error'] ?? null,
            'provider' => $contentRow['bond_metrics_provider'] ?? null,
            'model' => $contentRow['bond_metrics_model'] ?? null,
            'bonds' => $rows,
            'cached' => $cached,
            'updated_at' => $contentRow['bond_metrics_updated_at'] ?? null,
        ];
    }

    /**
     * Vue d'ensemble filtrable de toutes les lignes obligataires déjà
     * extraites, plus la liste des bulletins dont le texte est disponible
     * mais pas encore traité — même forme que
     * BulletinStockMetricsService::listMetrics().
     */
    public function listMetrics(array $filters = []): array {
        $conditions = [];
        $params = [];

        if (!empty($filters['symbol'])) {
            $conditions[] = 'm.symbol LIKE ?';
            $params[] = '%' . $filters['symbol'] . '%';
        }
        if (!empty($filters['category'])) {
            $conditions[] = 'm.category = ?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['bulletin_id'])) {
            $conditions[] = 'm.bulletin_id = ?';
            $params[] = $filters['bulletin_id'];
        }
        if (!empty($filters['start_date'])) {
            $conditions[] = 'm.publish_date >= ?';
            $params[] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $conditions[] = 'm.publish_date <= ?';
            $params[] = $filters['end_date'];
        }

        $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
        $limit = max(1, min(2000, (int) ($filters['limit'] ?? 500)));

        $rows = $this->crud->executeCustomQuery(
            "SELECT m.*, b.title AS bulletin_title
             FROM bulletin_bond_metrics m
             JOIN market_bulletins b ON b.id = m.bulletin_id
             $where
             ORDER BY m.publish_date DESC, m.category ASC, m.symbol ASC
             LIMIT $limit",
            $params
        ) ?: [];

        $pending = $this->crud->executeCustomQuery(
            "SELECT b.id, b.title, b.publish_date
             FROM market_bulletins b
             JOIN market_bulletin_contents mbc ON mbc.bulletin_id = b.id
             WHERE (mbc.extracted_text IS NOT NULL AND mbc.extracted_text != '')
               AND (mbc.bond_metrics_status IS NULL OR mbc.bond_metrics_status != 'success')
             ORDER BY b.publish_date DESC"
        ) ?: [];

        // Bulletins déjà extraits, pour peupler un sélecteur explicite « choisir
        // un bulletin » — respecte le filtre symbole (ne propose que les
        // bulletins où ce titre apparaît) mais jamais les filtres de date,
        // même raison que BulletinStockMetricsService::listMetrics().
        $bulletinConditions = ['mbc.bond_metrics_status = \'success\''];
        $bulletinParams = [];
        if (!empty($filters['symbol'])) {
            $bulletinConditions[] = 'EXISTS (SELECT 1 FROM bulletin_bond_metrics m2 WHERE m2.bulletin_id = b.id AND m2.symbol LIKE ?)';
            $bulletinParams[] = '%' . $filters['symbol'] . '%';
        }
        $bulletins = $this->crud->executeCustomQuery(
            "SELECT b.id, b.title, b.publish_date
             FROM market_bulletins b
             JOIN market_bulletin_contents mbc ON mbc.bulletin_id = b.id
             WHERE " . implode(' AND ', $bulletinConditions) . "
             ORDER BY b.publish_date DESC",
            $bulletinParams
        ) ?: [];

        return [
            'bonds' => $rows,
            'count' => count($rows),
            'bulletins' => $bulletins,
            'pending_bulletins' => $pending,
            'pending_count' => count($pending),
        ];
    }

    /**
     * Liste distincte des symboles obligataires déjà rencontrés (toutes
     * dates confondues), pour peupler un sélecteur « choisir un titre » côté
     * frontend sans renvoyer toutes les lignes.
     */
    public function listSymbols(): array {
        return $this->crud->executeCustomQuery(
            "SELECT symbol, MAX(title) AS title, MAX(category) AS category
             FROM bulletin_bond_metrics
             GROUP BY symbol
             ORDER BY symbol ASC"
        ) ?: [];
    }

    private function createClient(string $provider): AiClientInterface {
        $class = self::PROVIDERS[$provider]['class'];
        return new $class();
    }

    private function truncate(string $text): string {
        if (mb_strlen($text) <= self::MAX_BULLETIN_CHARS) {
            return $text;
        }
        return mb_substr($text, 0, self::MAX_BULLETIN_CHARS) . "\n\n[...texte tronqué...]";
    }

    /** Même garde-fou que BulletinCorporateActionsService::validDateOrNull() : une date IA mal formée devient NULL plutôt qu'une erreur SQL. */
    private function validDateOrNull(?string $date): ?string {
        if (!$date) return null;
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return ($d && $d->format('Y-m-d') === $date) ? $date : null;
    }

    private function buildPrompt(array $bulletin, string $bulletinText): string {
        $dateLabel = $bulletin['publish_date'];
        $categories = implode(', ', self::CATEGORIES);

        return <<<PROMPT
Tu es un analyste de marché spécialisé sur la BRVM (Bourse Régionale des
Valeurs Mobilières, Afrique de l'Ouest). Le Bulletin Officiel de la Cote
(BOC) du $dateLabel ci-dessous contient une ou plusieurs sections
« Obligations » (marché obligataire), organisées en sous-catégories :

- Obligations souveraines (États : Mali, Sénégal, Burkina, Côte d'Ivoire,
  Niger, Togo, Bénin...) → category "sovereign"
- Obligations d'institutions financières régionales et internationales
  (BIDC-EBID, CRRH-UEMOA...) → category "financial_institution"
- Obligations d'entreprises (classiques) → category "corporate"
- Obligations Vertes/Sociales/Durables (GSS) d'institutions financières →
  category "gss_financial"
- Obligations GSS d'entreprises → category "gss_corporate"
- Fonds Communs de Titrisation de Créances (FCTC) d'État ou d'institutions à
  participation majoritaire publique → category "fctc_public"
- FCTC d'institutions financières régionales et internationales →
  category "fctc_financial"
- FCTC d'entreprises (classiques) → category "fctc_corporate"
- FCTC GSS d'entreprises → category "fctc_gss_corporate"
- Sukuk et titres assimilés → category "sukuk"
- Obligations convertibles en actions → category "convertible"

Chaque ligne du tableau représente UNE ligne obligataire, avec des colonnes
telles que : Symbole, Titre, Valeur nominale, Cours Précédent, Cours du
jour (ou "NC" = non coté ce jour, "SP" = suspendu, si aucune transaction),
Cours de Référence, Volume, Valeur (transigée), Coupon couru, Période
(A=annuel, S=semestriel, T=trimestriel), Montant net, Échéance, Type
d'amortissement (ACD, AC, IF...).

Ta tâche : extraire de façon EXHAUSTIVE CHAQUE ligne obligataire de TOUTES
les sous-catégories présentes dans ce texte. N'en oublie aucune (il peut y
en avoir plus de 150), mais n'en invente aucune. Ignore les lignes "TOTAL"
récapitulatives de chaque tableau — ce ne sont pas des lignes obligataires.

Réponds UNIQUEMENT avec un objet JSON de cette forme exacte (aucun texte
avant/après, pas de balises markdown) :

{
  "bonds": [
    {
      "symbol": "code exact tel qu'écrit dans le bulletin (ex: TPCI.O87, EOM.O10, SUKTG.S1)",
      "title": "nom de la ligne obligataire tel qu'écrit dans le bulletin",
      "category": "une valeur parmi : $categories",
      "nominal_value": nombre ou null si absent,
      "previous_price": nombre (Cours Précédent) ou null si absent ou tiret,
      "day_price": nombre (Cours du jour) ou null si la cellule affiche "NC", "SP", un tiret, ou est absente,
      "day_price_status": "NC" si la cellule Cours du jour affiche "NC", "SP" si elle affiche "SP", sinon null,
      "reference_price": nombre (Cours de Référence) ou null si absent,
      "volume": nombre (quantité échangée) ou null si absent ou tiret,
      "value_traded": nombre (Valeur transigée) ou null si absent ou tiret,
      "accrued_coupon": nombre (Coupon couru) ou null si absent,
      "period_type": "A", "S" ou "T" (colonne Période) ou null si absent,
      "net_amount": nombre (Montant net) ou null si absent,
      "maturity_date": "date d'échéance au format YYYY-MM-DD si mentionnée explicitement (ex: '9-déc.-26' devient '2026-12-09'), sinon null (ne déduis jamais une date approximative)",
      "amortization_type": "code tel qu'écrit (ex: ACD, AC, IF)" ou null si absent
    }
  ]
}

Règles impératives :
- Une ligne par ligne obligataire du tableau, jamais une ligne par action ou par ligne d'indice.
- N'invente JAMAIS une valeur numérique absente ou illisible du tableau : mets null plutôt que d'extrapoler ou d'estimer.
- Si aucune section Obligations n'est présente dans ce texte, réponds avec "bonds": [].
- Réponds uniquement avec le JSON.

Texte du bulletin :
$bulletinText
PROMPT;
    }

    private function responseSchema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['bonds'],
            'properties' => [
                'bonds' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'symbol', 'title', 'category', 'nominal_value', 'previous_price',
                            'day_price', 'day_price_status', 'reference_price', 'volume',
                            'value_traded', 'accrued_coupon', 'period_type', 'net_amount',
                            'maturity_date', 'amortization_type',
                        ],
                        'properties' => [
                            'symbol' => ['type' => 'string'],
                            'title' => ['type' => ['string', 'null']],
                            'category' => ['type' => 'string', 'enum' => self::CATEGORIES],
                            'nominal_value' => ['type' => ['number', 'null']],
                            'previous_price' => ['type' => ['number', 'null']],
                            'day_price' => ['type' => ['number', 'null']],
                            'day_price_status' => ['type' => ['string', 'null']],
                            'reference_price' => ['type' => ['number', 'null']],
                            'volume' => ['type' => ['number', 'null']],
                            'value_traded' => ['type' => ['number', 'null']],
                            'accrued_coupon' => ['type' => ['number', 'null']],
                            'period_type' => ['type' => ['string', 'null']],
                            'net_amount' => ['type' => ['number', 'null']],
                            'maturity_date' => ['type' => ['string', 'null']],
                            'amortization_type' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
