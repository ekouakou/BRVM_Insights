<?php
/**
 * Analyse IA générique des graphes/tableaux de comparaison de l'app (voir
 * TODO_CHART_AI_ANALYSIS.md) — un seul service pour tous les chart_type
 * plutôt qu'une classe par graphe, pour que brancher un nouveau graphe se
 * résume à ajouter une entrée dans METHODOLOGY plutôt qu'à dupliquer la
 * logique d'appel IA/cache/historique (déjà dupliquée 3 fois dans le
 * projet — ReportAnalysisService/BulletinAnalysisService/
 * CombinedAnalysisService — ce service s'inspire directement de leur
 * pattern commun sans en ajouter une 4e variante).
 *
 * Le frontend envoie les données déjà calculées (celles que le graphe
 * affiche déjà, obtenues via les endpoints existants) plutôt que de les
 * recalculer ici — ce service ne fait qu'analyser, jamais de requête SQL
 * sur les cours/indicateurs eux-mêmes.
 */
class ChartAnalysisService {
    private const PROVIDERS = [
        'anthropic' => ['class' => 'AnthropicClient', 'default_model' => 'claude-opus-5'],
        'gemini' => ['class' => 'GeminiClient', 'default_model' => 'gemini-flash-lite-latest'],
        'grok' => ['class' => 'GrokClient', 'default_model' => 'grok-4-fast-reasoning'],
    ];
    private const DEFAULT_PROVIDER = 'gemini';
    private const DISCLAIMER = "Analyse générée automatiquement à titre informatif, "
        . "ne constitue pas un conseil en investissement.";

    /**
     * Méthode de calcul par chart_type, en français — envoyée telle quelle
     * à l'IA (qui doit la restituer en langage clair dans sa réponse, voir
     * buildPrompt()) et sert aussi de registre des chart_type supportés.
     * À étendre au fur et à mesure qu'on branche de nouveaux graphes/
     * tableaux (voir TODO_CHART_AI_ANALYSIS.md, section "Périmètre").
     */
    private const METHODOLOGY = [
        'quotes_comparison' =>
            "Cours (ou variation en % depuis le début de la période affichée) de chaque entreprise " .
            "sélectionnée, au jour le jour ou par relevé intrajournalier selon le mode choisi. En mode " .
            "pourcentage, chaque entreprise est recalée à 0% au premier point de la période (indexée base " .
            "100 dans le calcul interne, affichée en écart en % pour rester lisible malgré des échelles de " .
            "prix très différentes d'une entreprise à l'autre).",
        'quotes_signals' =>
            "Score composite de -2 (vente forte) à +2 (achat fort) par entreprise, moyenne arrondie de " .
            "jusqu'à 4 signaux techniques disponibles : RSI(14) (<30 = signal d'achat/survendu, >70 = signal " .
            "de vente/suracheté), MACD (ligne au-dessus/en-dessous de sa ligne de signal = momentum " .
            "haussier/baissier), tendance (cours au-dessus/en-dessous de sa moyenne mobile SMA20 ou SMA10), " .
            "Bandes de Bollinger (cours au-dessus/en-dessous des bandes = potentiel retour à la moyenne). " .
            "Un signal peut manquer si l'historique de l'entreprise est trop court pour le calculer — le " .
            "score se base alors sur les signaux disponibles uniquement (voir api_signals.php::buildSignal()).",
        'quotes_close_sma' =>
            "Cours de clôture quotidien d'une entreprise, avec ses moyennes mobiles simples (SMA) superposées " .
            "— chaque point de la SMA-N est la moyenne des N derniers cours de clôture (N=10, 20 ou 50 selon " .
            "les courbes activées). Un cours au-dessus de sa SMA est généralement lu comme une tendance " .
            "haussière, en-dessous comme baissière ; un croisement entre deux SMA de périodes différentes " .
            "(ex. SMA10 qui croise SMA20) est un signal de changement de tendance classique.",
        'total_variation' =>
            "Pour chaque entreprise et chaque jour : somme de la valeur absolue des variations en % entre " .
            "relevés intrajournaliers successifs (~toutes les 10 min en séance), séparée en hausses cumulées " .
            "et baisses cumulées (affichées en négatif). Mesure combien un titre a « bougé » dans la journée " .
            "(churn/volatilité intrajournalière), pas où il a fini — un titre à +5% puis -3% puis +6% puis " .
            "-4% a une variation nette de +4% mais une variation totale d'environ 18%. Le premier relevé du " .
            "jour démarre l'accumulateur à 0 (l'écart avec la clôture de la veille n'est pas compté).",
        'correlation' =>
            "Corrélation de Pearson entre les séries de variation quotidienne (%) de chaque paire d'entreprises " .
            "sélectionnées, sur les jours de bourse communs aux deux (90 derniers jours). Va de -1 (évoluent " .
            "toujours en sens strictement opposé) à +1 (évoluent toujours ensemble) ; proche de 0 = pas de " .
            "relation linéaire détectable. Utile pour évaluer la diversification d'un portefeuille : des " .
            "titres très corrélés n'apportent pas de diversification l'un par rapport à l'autre.",
        'risk_adjusted' =>
            "Rendement net sur la période ((dernier cours de clôture - premier cours de clôture) / premier " .
            "cours) divisé par la volatilité cumulée (somme de la variation totale quotidienne — voir " .
            "'total_variation' — sur la même période). Un ratio plus élevé signifie un meilleur rendement " .
            "pour un même niveau d'agitation du cours ; deux titres au même rendement net peuvent avoir des " .
            "ratios très différents si l'un est monté en ligne quasi droite et l'autre en oscillant fortement.",
        'relative_strength' =>
            "Pour chaque entreprise et chaque jour : variation quotidienne (%) du titre moins variation " .
            "quotidienne (%) de l'indice BRVM-COMPOSITE le même jour. Positif = le titre a surperformé le " .
            "marché ce jour-là, négatif = sous-performé. BRVM-COMPOSITE regroupe l'ensemble des sociétés " .
            "cotées à la BRVM, pondérées par leur capitalisation boursière.",
        'sector_performance' =>
            "Indice équipondéré par secteur d'activité : pour chaque secteur et chaque jour, moyenne de " .
            "l'indice base 100 (cours du jour / cours au premier jour de la période × 100) de toutes les " .
            "entreprises actives de ce secteur. Affiché en écart en % par rapport à 100 (donc par rapport au " .
            "début de la période) pour rester lisible. Compare la performance de secteurs entiers plutôt que " .
            "d'entreprises individuelles ; chaque entreprise pèse le même poids dans son secteur (pas pondéré " .
            "par capitalisation).",
        'market_breadth' =>
            "Pour chaque jour de bourse : nombre d'entreprises actives en hausse, en baisse, et inchangées " .
            "(par rapport à leur clôture de la veille). La largeur de marché indique si un mouvement de " .
            "l'indice est porté par une majorité de titres (mouvement « sain », largement partagé) ou par " .
            "seulement quelques valeurs (mouvement plus fragile, susceptible de s'inverser).",
        'data_quality_reconciliation' =>
            "Contrôle de cohérence : recalcule la variation en % à partir de (clôture du jour - clôture de la " .
            "veille) / clôture de la veille, et la compare à la variation stockée en base (récupérée " .
            "directement depuis brvm.org). Un écart de plus de 0,5 point entre les deux est remonté comme " .
            "anomalie — ça ne veut pas forcément dire que la donnée est fausse (previous_close peut légitimement " .
            "différer selon le contexte), mais mérite une vérification humaine.",
        'data_quality_price_jumps' =>
            "Compare chaque relevé intrajournalier au relevé précédent de la même entreprise (~10 minutes plus " .
            "tôt en séance), et signale les écarts de plus de 15% entre deux relevés consécutifs. Un tel saut " .
            "peut être une vraie transaction sur un titre peu liquide (un seul échange peut fortement déplacer " .
            "le prix quand il y a peu d'acheteurs/vendeurs), ou une erreur de récupération des données — ce " .
            "contrôle ne tranche pas lequel, il liste seulement les cas à vérifier.",
        'data_quality_missing_days' =>
            "Pour chaque entreprise active : compare le nombre de jours, sur la période, où AU MOINS une " .
            "entreprise du marché a une clôture enregistrée (le calendrier de référence, déduit des données " .
            "elles-mêmes) au nombre de jours où CETTE entreprise précise en a une. Un écart signale une " .
            "synchronisation qui a échoué spécifiquement pour cette entreprise ce jour-là (pas une panne " .
            "générale du marché, qui affecterait toutes les entreprises pareil), ou une suspension de cotation.",
        'market_summary' =>
            "Statistiques agrégées de la séance du jour : nombre d'entreprises en hausse/en baisse/inchangées, " .
            "variation moyenne du marché, volume total échangé, plus les 3 classements — plus fortes hausses, " .
            "plus fortes baisses, volumes les plus élevés (chacun basé sur la variation ou le volume du jour " .
            "par rapport à la clôture de la veille).",
        'intraday_volume' =>
            "Volume échangé par entreprise sélectionnée, relevé toutes les ~10 minutes pendant la séance (même " .
            "source que les cours intrajournaliers). Permet de repérer les moments de la journée où un titre " .
            "s'échange le plus (ouverture, clôture, annonce) et de comparer l'activité entre entreprises sur la " .
            "même période — un pic isolé peut correspondre à une transaction ponctuelle sur un titre peu liquide " .
            "plutôt qu'à un mouvement de fond.",
        'share_turnover' =>
            "Taux de rotation du flottant par entreprise sélectionnée = volume total échangé sur la période ÷ " .
            "actions en circulation (total émis, pas seulement le flottant réel). Mesure directement comparable " .
            "entre entreprises de tailles différentes, contrairement au volume brut. 'Actions non retradées " .
            "(estimation basse)' = actions en circulation - volume échangé (ramené à 0 si le volume dépasse les " .
            "actions en circulation, cas où le capital a été retradé au moins une fois en moyenne sur la " .
            "période). 'Flottant réel' = capitalisation flottante ÷ capitalisation totale (donnée BRVM) : une " .
            "valeur faible signifie qu'une grande part du capital est verrouillée chez un actionnaire de " .
            "contrôle et n'est jamais mise en vente, indépendamment du nombre total d'actions émises.",
        'volume_ranking' =>
            "Classement de toutes les entreprises actives par volume total d'actions échangées sur la période " .
            "sélectionnée (somme du volume quotidien sur chaque séance de la plage de dates, api_quotes.php, " .
            "action 'volume_ranking'). Une entreprise sans transaction sur la période apparaît quand même, à 0, " .
            "plutôt que d'être exclue du classement. Un volume total élevé traduit une forte activité de " .
            "négociation cumulée sur la période, pas nécessairement un mouvement de prix — à croiser avec le " .
            "nombre de jours de cotation (une entreprise peu suivie peut avoir peu de séances avec transaction) " .
            "pour interpréter le classement. Inclut aussi, quand la donnée est disponible (companies." .
            "shares_outstanding, scrapée ponctuellement, pas à chaque synchro) : le nombre total d'actions en " .
            "circulation (émises, PAS un stock 'à vendre' — la BRVM ne publie pas de carnet d'ordres public), " .
            "le taux de rotation (volume échangé ÷ actions en circulation, en %), et une estimation basse du " .
            "nombre d'actions n'ayant pas retrouvé d'acheteur sur la période (actions en circulation - volume " .
            "échangé, plafonnée à 0 — les mêmes titres peuvent être retradés plusieurs fois, donc cette " .
            "estimation ne veut pas dire que ces actions précises sont restées immobiles).",
    ];

    private $crud;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
    }

    /**
     * @param array $parameters Paramètres exacts de la sélection (company_ids, dates, mode, selected_rows...)
     * @param array $data Données déjà calculées à analyser (ce que le graphe/tableau affiche)
     */
    public function analyze(string $chartType, array $parameters, array $data, ?string $provider = null, ?string $model = null, bool $forceRefresh = false): array {
        if (!isset(self::METHODOLOGY[$chartType])) {
            throw new Exception("Type de graphe non supporté: $chartType");
        }

        $provider = $provider ?: self::DEFAULT_PROVIDER;
        if (!isset(self::PROVIDERS[$provider])) {
            throw new Exception("Fournisseur IA inconnu: $provider. Disponibles: " . implode(', ', array_keys(self::PROVIDERS)));
        }
        $model = $model ?: self::PROVIDERS[$provider]['default_model'];

        if (empty($data)) {
            throw new Exception("Aucune donnée à analyser (sélection vide)");
        }

        $requestHash = $this->hashRequest($chartType, $parameters);

        $existing = $this->crud->executeCustomQuery(
            "SELECT * FROM chart_analyses WHERE request_hash = ? AND provider = ? AND model = ? LIMIT 1",
            [$requestHash, $provider, $model]
        );
        $existingRow = $existing[0] ?? null;

        if ($existingRow && $existingRow['status'] === 'success' && !$forceRefresh) {
            return $this->formatResult($existingRow, true);
        }

        $prompt = $this->buildPrompt($chartType, $parameters, $data);
        $client = $this->createClient($provider);
        $aiResult = $client->generateContent($prompt, $model, $this->responseSchema());

        $row = [
            'chart_type' => $chartType,
            'request_hash' => $requestHash,
            'parameters' => json_encode($parameters, JSON_UNESCAPED_UNICODE),
            'provider' => $provider,
            'model' => $model,
            'data_points_count' => $this->countDataPoints($data),
        ];

        if ($aiResult['success']) {
            $d = $aiResult['data'];
            $row['summary'] = $d['summary'] ?? null;
            $row['details'] = json_encode([
                'methodology_explained' => $d['methodology_explained'] ?? null,
                'key_observations' => $d['key_observations'] ?? [],
                'notable_points' => $d['notable_points'] ?? [],
                'suggested_charts' => $d['suggested_charts'] ?? [],
            ], JSON_UNESCAPED_UNICODE);
            $row['status'] = 'success';
            $row['error_message'] = null;
            $row['raw_response'] = $aiResult['raw'] ?? null;
        } else {
            $row['status'] = 'failed';
            $row['error_message'] = $aiResult['error'];
            $row['raw_response'] = $aiResult['raw'] ?? null;
            $row['summary'] = null;
            $row['details'] = null;
        }

        if ($existingRow) {
            $this->crud->merge('chart_analyses', $row, ['id' => $existingRow['id']]);
            $rowId = $existingRow['id'];
        } else {
            $rowId = $this->crud->persist('chart_analyses', $row);
        }

        if (!$aiResult['success']) {
            throw new Exception($aiResult['error']);
        }

        $savedRow = $this->crud->findById('chart_analyses', $rowId);
        return $this->formatResult($savedRow, false);
    }

    /**
     * Historique filtré à la sélection courante (chart_type + paramètres
     * exacts), tous fournisseurs/modèles confondus, du plus récent au plus
     * ancien — volontairement pas une liste globale toutes sélections
     * confondues (voir TODO_CHART_AI_ANALYSIS.md).
     */
    public function history(string $chartType, array $parameters): array {
        $requestHash = $this->hashRequest($chartType, $parameters);

        $rows = $this->crud->executeCustomQuery(
            "SELECT * FROM chart_analyses WHERE request_hash = ? ORDER BY id DESC",
            [$requestHash]
        ) ?: [];

        return array_map(fn($row) => $this->formatResult($row, true), $rows);
    }

    public function get(int $id): ?array {
        $row = $this->crud->findById('chart_analyses', $id);
        return $row ? $this->formatResult($row, true) : null;
    }

    /**
     * Supprime une analyse enregistrée — libère le (request_hash, provider,
     * model) correspondant, un nouveau clic sur "Analyser" redéclenchera un
     * appel IA plutôt que de retomber sur une ligne déjà supprimée.
     */
    public function remove(int $id): void {
        $row = $this->crud->findById('chart_analyses', $id);
        if (!$row) {
            throw new Exception("Analyse non trouvée (id=$id)");
        }
        $this->crud->remove('chart_analyses', ['id' => $id]);
    }

    /**
     * Note (1-5 étoiles) et/ou commentaire libre sur une analyse déjà
     * enregistrée — $rating à null efface la note, $notes à null (et non
     * absent) efface le commentaire ; seuls les champs explicitement
     * fournis par l'appelant sont modifiés (voir api_chart_analysis.php).
     */
    public function rate(int $id, ?int $rating, ?string $notes, bool $ratingProvided, bool $notesProvided): array {
        if ($ratingProvided && $rating !== null && ($rating < 1 || $rating > 5)) {
            throw new Exception("La note doit être comprise entre 1 et 5");
        }

        $row = $this->crud->findById('chart_analyses', $id);
        if (!$row) {
            throw new Exception("Analyse non trouvée (id=$id)");
        }

        $update = [];
        if ($ratingProvided) $update['rating'] = $rating;
        if ($notesProvided) $update['notes'] = $notes;

        if (!empty($update)) {
            $this->crud->merge('chart_analyses', $update, ['id' => $id]);
        }

        $updatedRow = $this->crud->findById('chart_analyses', $id);
        return $this->formatResult($updatedRow, true);
    }

    private function hashRequest(string $chartType, array $parameters): string {
        ksort($parameters);
        return hash('sha256', json_encode([$chartType, $parameters]));
    }

    private function countDataPoints($data): int {
        $count = 0;
        array_walk_recursive($data, function () use (&$count) {
            $count++;
        });
        return $count;
    }

    private function createClient(string $provider): AiClientInterface {
        $class = self::PROVIDERS[$provider]['class'];
        return new $class();
    }

    /**
     * Formule en français la période couverte par les paramètres de
     * sélection (start_date/end_date/date/days — les combinaisons varient
     * selon le chart_type, voir les usages de <ChartAiAnalysis> côté
     * frontend) — calculée ici, jamais laissée à la charge de l'IA, pour
     * que la période affichée soit toujours exacte plutôt que déduite/
     * devinée à partir des seules données. Retourne null si aucun indice
     * de période n'est présent dans les paramètres (rare, mais possible).
     */
    private function formatPeriod(array $parameters): ?string {
        $start = $parameters['start_date'] ?? null;
        $end = $parameters['end_date'] ?? null;
        $date = $parameters['date'] ?? null;
        $days = $parameters['days'] ?? null;

        if ($start && $end) {
            return $start === $end ? "le $start" : "du $start au $end";
        }
        if ($date) {
            return "le $date";
        }
        if ($days && $end) {
            return "les $days derniers jours (jusqu'au $end)";
        }
        if ($days) {
            return "les $days derniers jours";
        }
        if ($start) {
            return "depuis le $start";
        }
        if ($end) {
            return "jusqu'au $end";
        }
        return null;
    }

    private function buildPrompt(string $chartType, array $parameters, array $data): string {
        $methodology = self::METHODOLOGY[$chartType];
        $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $period = $this->formatPeriod($parameters);
        $periodBlock = $period
            ? "Période couverte par ces données : $period. Mentionne explicitement cette période dans ton résumé (\"summary\") — ne la déduis jamais toi-même à partir des données, utilise exactement celle donnée ici."
            : "Aucune période explicite n'a été fournie pour cette sélection — ne mentionne pas de période si tu ne peux pas la déduire avec certitude des données elles-mêmes.";

        return <<<PROMPT
Tu es un analyste financier senior spécialisé sur les marchés actions
d'Afrique de l'Ouest (BRVM), chargé d'analyser un graphe/tableau de
l'application BRVM Insights pour un investisseur qui n'est pas forcément
familier avec les indicateurs techniques.

Méthode de calcul utilisée pour produire les données ci-dessous (à
restituer en langage clair dans ta réponse, pas seulement à utiliser en
silence) :
$methodology

$periodBlock

Données à analyser (JSON) :
$dataJson

En plus de ton analyse, propose entre 0 et 3 graphes complémentaires
("suggested_charts") qui aideraient à mieux comprendre CES MÊMES données
sous un autre angle (ex: isoler un sous-ensemble, changer la métrique
affichée, comparer deux séries entre elles) — pas un graphe qui
nécessiterait des données que tu n'as pas reçues ci-dessus.

Contrainte stricte sur "suggested_charts" : chaque graphe proposé doit
être directement calculable à partir du JSON fourni ci-dessus, structuré
comme une liste d'enregistrements (objets avec les mêmes clés). "x_field"
doit être le nom exact d'une clé de ces enregistrements à utiliser en axe
X (souvent une date), et chaque entrée de "series" doit référencer le nom
exact d'une clé numérique de ces enregistrements à tracer en Y. N'invente
jamais un nom de champ absent des données. Si les données fournies ne sont
PAS structurées comme une liste plate d'enregistrements exploitable ainsi
(ex: une matrice, un objet unique imbriqué), renvoie un tableau vide pour
"suggested_charts" plutôt que de forcer une proposition inadaptée.

Chaque champ technique (x_field, et le "field" de chaque série) doit être
accompagné d'un libellé humain, clair et en français, à afficher à la
place du nom technique (jamais le nom de clé JSON brut du type
"net_return_percent" — l'utilisateur final ne doit jamais voir ces noms
techniques, seulement tes libellés) : "x_label" pour l'axe X, "label" pour
chaque série (avec l'unité si pertinent, ex: "Rendement net (%)").

Réponds UNIQUEMENT avec un objet JSON de cette forme exacte (aucun texte
avant/après, pas de balises markdown) :

{
  "summary": "résumé général en 2-4 phrases de ce que montrent ces données",
  "methodology_explained": "explication en langage clair et accessible de comment ces chiffres ont été calculés, reformulée à partir de la méthode fournie ci-dessus pour quelqu'un qui ne connaît pas le jargon technique",
  "key_observations": ["observation factuelle 1 sur les données", "observation factuelle 2", "..."],
  "notable_points": ["point notable 1 (valeur extrême, divergence, anomalie...) s'il y en a — sinon tableau vide", "..."],
  "suggested_charts": [
    {
      "title": "titre court du graphe proposé",
      "description": "pourquoi ce graphe apporte un angle différent de celui déjà affiché",
      "chart_type": "line ou bar",
      "x_field": "nom exact du champ à utiliser en axe X",
      "x_label": "libellé humain en français pour l'axe X, ex: \"Entreprise\" ou \"Date\"",
      "series": [
        {"field": "nom exact du champ numérique", "label": "libellé humain en français, avec unité si pertinent"}
      ]
    }
  ]
}

Règles impératives :
- N'invente aucun chiffre : base-toi uniquement sur les données fournies ci-dessus.
- Reste factuel et neutre : jamais de recommandation d'achat/vente explicite.
- Si les données sont insuffisantes pour une observation pertinente, dis-le plutôt que d'inventer.
- N'invente aucun nom de champ dans "suggested_charts" — uniquement des clés réellement présentes dans les données fournies. Dans le doute, renvoie un tableau vide.
- N'affiche jamais un nom de clé JSON brut comme libellé — toujours un "label"/"x_label" humain et compréhensible.
- Réponds uniquement avec le JSON.
PROMPT;
    }

    private function responseSchema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'methodology_explained', 'key_observations', 'notable_points', 'suggested_charts'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'methodology_explained' => ['type' => 'string'],
                'key_observations' => ['type' => 'array', 'items' => ['type' => 'string']],
                'notable_points' => ['type' => 'array', 'items' => ['type' => 'string']],
                'suggested_charts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'description', 'chart_type', 'x_field', 'x_label', 'series'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'chart_type' => ['enum' => ['line', 'bar']],
                            'x_field' => ['type' => 'string'],
                            'x_label' => ['type' => 'string'],
                            'series' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['field', 'label'],
                                    'properties' => [
                                        'field' => ['type' => 'string'],
                                        'label' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function formatResult(array $row, bool $cached): array {
        $details = json_decode($row['details'] ?? 'null', true) ?: [];
        $parameters = json_decode($row['parameters'], true) ?: [];

        return [
            'id' => (int) $row['id'],
            'chart_type' => $row['chart_type'],
            'parameters' => $parameters,
            'period' => $this->formatPeriod($parameters),
            'provider' => $row['provider'],
            'model' => $row['model'],
            'status' => $row['status'],
            'error_message' => $row['error_message'] ?? null,
            'rating' => isset($row['rating']) ? (int) $row['rating'] : null,
            'notes' => $row['notes'] ?? null,
            'summary' => $row['summary'],
            'methodology_explained' => $details['methodology_explained'] ?? null,
            'key_observations' => $details['key_observations'] ?? [],
            'notable_points' => $details['notable_points'] ?? [],
            'suggested_charts' => $details['suggested_charts'] ?? [],
            'disclaimer' => self::DISCLAIMER,
            'cached' => $cached,
            'created_at' => $row['created_at'] ?? null,
        ];
    }
}
