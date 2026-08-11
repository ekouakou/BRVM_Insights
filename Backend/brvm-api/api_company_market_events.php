<?php
/**
 * API du journal d'informations susceptibles d'affecter le cours
 * Endpoint: api_company_market_events.php
 *
 * Voir TODO_PENDING.md, point 27. Une ligne par événement découvert sur
 * une entreprise cotée (annonce, contrat, changement de direction,
 * litige...), saisi manuellement OU trouvé par la recherche web IA puis
 * CONFIRMÉ par l'utilisateur — l'action `search` ne stocke JAMAIS rien :
 * elle propose des candidats structurés que le frontend soumet ensuite un
 * par un à `add` après relecture/édition humaine (source_type
 * 'ia_recherche'). L'impact (positif/négatif/neutre) est le jugement de
 * l'UTILISATEUR, jamais rempli par l'IA.
 *
 * La recherche IA se fait en DEUX étapes : (1) generateChatReply() avec
 * recherche internet native (même mécanisme que le chat du tableau de
 * bord, class/CompanyChatService.php) → texte + sources citées ; (2)
 * generateContent() avec schéma structuré pour transformer ce texte en
 * candidats {title, description, event_date, source_url} — plus fiable
 * que d'exiger du JSON directement d'un appel avec outil de recherche.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once 'config.php';
require_once 'class/DbConnect.php';
require_once 'class/DynamiqueCrud.php';
require_once 'class/AuthGuard.php';
AuthGuard::requireAuth();
require_once 'class/AiClientInterface.php';
require_once 'class/AiChatClientInterface.php';
require_once 'class/GeminiClient.php';
require_once 'class/AnthropicClient.php';
require_once 'class/GrokClient.php';

class CompanyMarketEventsAPI {
    private const PROVIDERS = [
        'anthropic' => ['class' => 'AnthropicClient', 'default_model' => 'claude-opus-5'],
        'gemini' => ['class' => 'GeminiClient', 'default_model' => 'gemini-flash-lite-latest'],
        'grok' => ['class' => 'GrokClient', 'default_model' => 'grok-4-fast-reasoning'],
    ];
    private const DEFAULT_PROVIDER = 'gemini';

    private const IMPACTS = ['positif', 'negatif', 'neutre', 'indetermine'];

    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'list':
                    return $this->listEvents($input);

                case 'add':
                    return $this->addEvent($input);

                case 'update':
                    return $this->updateEvent($input);

                case 'delete':
                    return $this->deleteEvent($input);

                case 'search':
                    return $this->search($input);

                default:
                    throw new Exception("Action non reconnue: $action");
            }
        } catch (Exception $e) {
            http_response_code(500);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Journal d'une entreprise — événements datés d'abord (du plus récent
     * au plus ancien), puis les non datés (par date de saisie).
     */
    private function listEvents($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId) {
            throw new Exception("company_id requis");
        }

        $conditions = ['e.company_id = ?'];
        $params = [$companyId];
        if (!empty($input['start_date'])) {
            $conditions[] = 'e.event_date >= ?';
            $params[] = $input['start_date'];
        }
        if (!empty($input['end_date'])) {
            $conditions[] = 'e.event_date <= ?';
            $params[] = $input['end_date'];
        }
        if (!empty($input['impact_assessment']) && in_array($input['impact_assessment'], self::IMPACTS, true)) {
            $conditions[] = 'e.impact_assessment = ?';
            $params[] = $input['impact_assessment'];
        }

        $rows = $this->crud->executeCustomQuery(
            "SELECT e.*, u.username AS created_by_username
             FROM company_market_events e
             LEFT JOIN admin_users u ON u.id = e.created_by_admin_user_id
             WHERE " . implode(' AND ', $conditions) . "
             ORDER BY e.event_date IS NULL, e.event_date DESC, e.created_at DESC",
            $params
        ) ?: [];

        return ['success' => true, 'data' => ['events' => $rows, 'count' => count($rows)]];
    }

    private function addEvent($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId || !$this->crud->findById('companies', $companyId)) {
            throw new Exception("company_id requis (entreprise existante)");
        }
        $title = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        if ($title === '' || $description === '') {
            throw new Exception("title et description requis");
        }

        $sourceType = $input['source_type'] ?? 'utilisateur';
        if (!in_array($sourceType, ['utilisateur', 'ia_recherche'], true)) {
            throw new Exception("source_type invalide: $sourceType");
        }
        $impact = $input['impact_assessment'] ?? null;
        if ($impact !== null && $impact !== '' && !in_array($impact, self::IMPACTS, true)) {
            throw new Exception("impact_assessment invalide: $impact");
        }

        $id = $this->crud->persist('company_market_events', [
            'company_id' => $companyId,
            'title' => mb_substr($title, 0, 255),
            'description' => $description,
            'event_date' => $this->validDateOrNull($input['event_date'] ?? null),
            'source_type' => $sourceType,
            'source_url' => !empty($input['source_url']) ? mb_substr($input['source_url'], 0, 500) : null,
            'impact_assessment' => ($impact === '' || $impact === null) ? null : $impact,
            'created_by_admin_user_id' => AuthGuard::getCurrentUserId(),
        ]);

        return ['success' => true, 'data' => ['id' => (int) $id]];
    }

    private function updateEvent($input) {
        $eventId = (int) ($input['id'] ?? 0);
        if (!$eventId || !$this->crud->findById('company_market_events', $eventId)) {
            throw new Exception("Événement introuvable (id=$eventId)");
        }

        $update = [];
        if (array_key_exists('title', $input)) {
            $title = trim($input['title']);
            if ($title === '') throw new Exception("title ne peut pas être vide");
            $update['title'] = mb_substr($title, 0, 255);
        }
        if (array_key_exists('description', $input)) {
            $description = trim($input['description']);
            if ($description === '') throw new Exception("description ne peut pas être vide");
            $update['description'] = $description;
        }
        if (array_key_exists('event_date', $input)) {
            $update['event_date'] = $this->validDateOrNull($input['event_date']);
        }
        if (array_key_exists('source_url', $input)) {
            $update['source_url'] = !empty($input['source_url']) ? mb_substr($input['source_url'], 0, 500) : null;
        }
        if (array_key_exists('impact_assessment', $input)) {
            $impact = $input['impact_assessment'];
            if ($impact !== null && $impact !== '' && !in_array($impact, self::IMPACTS, true)) {
                throw new Exception("impact_assessment invalide: $impact");
            }
            $update['impact_assessment'] = ($impact === '' || $impact === null) ? null : $impact;
        }

        if (empty($update)) {
            throw new Exception("Aucun champ à mettre à jour");
        }
        $this->crud->merge('company_market_events', $update, ['id' => $eventId]);

        return ['success' => true, 'data' => ['id' => $eventId]];
    }

    private function deleteEvent($input) {
        $eventId = (int) ($input['id'] ?? 0);
        if (!$eventId || !$this->crud->findById('company_market_events', $eventId)) {
            throw new Exception("Événement introuvable (id=$eventId)");
        }
        $this->crud->remove('company_market_events', ['id' => $eventId]);

        return ['success' => true, 'data' => ['id' => $eventId]];
    }

    /**
     * Recherche web IA d'informations récentes sur une entreprise — ne
     * stocke RIEN : renvoie des candidats structurés + les sources citées,
     * à confirmer/éditer un par un côté frontend avant sauvegarde via
     * `add` (source_type 'ia_recherche').
     */
    private function search($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        $company = $companyId ? $this->crud->findById('companies', $companyId) : null;
        if (!$company) {
            throw new Exception("company_id requis (entreprise existante)");
        }

        $provider = $input['provider'] ?? self::DEFAULT_PROVIDER;
        if (!isset(self::PROVIDERS[$provider])) {
            throw new Exception("Fournisseur IA inconnu: $provider. Disponibles: " . implode(', ', array_keys(self::PROVIDERS)));
        }
        $model = !empty($input['model']) ? $input['model'] : self::PROVIDERS[$provider]['default_model'];

        $clientClass = self::PROVIDERS[$provider]['class'];
        $client = new $clientClass();

        // Étape 1 : recherche web (même mécanisme que le chat du tableau de
        // bord) — réponse rédigée + sources citées par le fournisseur.
        $companyLabel = $company['name'] . " (symbole BRVM : " . $company['symbol'] . ")";
        $today = date('d/m/Y');
        $systemPrompt = "Tu es un analyste de veille spécialisé sur la BRVM (Bourse Régionale des Valeurs "
            . "Mobilières, Afrique de l'Ouest). Tu utilises la recherche internet pour trouver des informations "
            . "RÉCENTES et FACTUELLES, toujours sourcées. Tu ne spécules jamais et tu n'inventes jamais un fait "
            . "ni une date. Nous sommes le $today.";
        $userMessage = "Recherche les informations récentes susceptibles d'influencer le cours de bourse de "
            . "$companyLabel : annonces officielles, résultats financiers, dividendes, changements de direction, "
            . "contrats importants, litiges, opérations sur capital, actualité sectorielle la concernant "
            . "directement. Pour CHAQUE information trouvée, donne : un titre court, un résumé factuel de 2-3 "
            . "phrases, la date de l'événement si connue (précise-la au format JJ/MM/AAAA), et l'URL de la "
            . "source. Si tu ne trouves rien de significatif, dis-le clairement plutôt que de meubler.";

        $chatResult = $client->generateChatReply($systemPrompt, [], $userMessage, $model, ['timeout_seconds' => 150]);
        if (!$chatResult['success']) {
            return ['success' => false, 'message' => $chatResult['error']];
        }
        $searchText = $chatResult['text'] ?? '';
        $sources = $chatResult['sources'] ?? [];

        // Étape 2 : extraction structurée du texte de veille en candidats
        // d'événements — appel séparé car les fournisseurs ne combinent pas
        // outil de recherche et sortie JSON contrainte de façon fiable.
        $extractPrompt = $this->buildExtractPrompt($companyLabel, $searchText, $sources);
        $extractResult = $client->generateContent($extractPrompt, $model, $this->extractSchema());
        if (!$extractResult['success']) {
            return ['success' => false, 'message' => $extractResult['error']];
        }

        $candidates = [];
        foreach (($extractResult['data']['events'] ?? []) as $event) {
            $title = trim((string) ($event['title'] ?? ''));
            $description = trim((string) ($event['description'] ?? ''));
            if ($title === '' || $description === '') continue;
            $candidates[] = [
                'title' => mb_substr($title, 0, 255),
                'description' => $description,
                'event_date' => $this->validDateOrNull($event['event_date'] ?? null),
                'source_url' => !empty($event['source_url']) ? mb_substr($event['source_url'], 0, 500) : null,
            ];
        }

        return [
            'success' => true,
            'data' => [
                'company_id' => $companyId,
                'provider' => $provider,
                'model' => $model,
                'candidates' => $candidates,
                'sources' => $sources,
                'raw_text' => $searchText,
                'disclaimer' => "Résultats de recherche IA à VÉRIFIER avant enregistrement — rien n'est stocké "
                    . "sans ta confirmation, et l'impact (positif/négatif) reste ton jugement, jamais celui de l'IA.",
            ],
        ];
    }

    private function buildExtractPrompt(string $companyLabel, string $searchText, array $sources): string {
        $sourcesJson = json_encode($sources, JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
Voici le résultat d'une recherche de veille sur $companyLabel, avec les
sources citées par le moteur de recherche :

Sources : $sourcesJson

Texte de veille :
$searchText

Transforme ce texte en une liste structurée d'événements distincts. Pour
chaque événement : un titre court et factuel, une description de 2-3
phrases reprenant UNIQUEMENT des faits présents dans le texte ci-dessus,
la date de l'événement au format YYYY-MM-DD si elle est explicitement
mentionnée (sinon null — n'invente jamais une date), et l'URL de la source
la plus pertinente parmi celles listées (sinon null). Si le texte dit
qu'aucune information significative n'a été trouvée, renvoie une liste
vide.

Réponds UNIQUEMENT avec un objet JSON de cette forme exacte (aucun texte
avant/après, pas de balises markdown) :

{"events": [{"title": "...", "description": "...", "event_date": "YYYY-MM-DD ou null", "source_url": "https://... ou null"}]}
PROMPT;
    }

    private function extractSchema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['events'],
            'properties' => [
                'events' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'description', 'event_date', 'source_url'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'event_date' => ['type' => ['string', 'null']],
                            'source_url' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function validDateOrNull($date): ?string {
        if (!$date || !is_string($date)) return null;
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return ($d && $d->format('Y-m-d') === $date) ? $date : null;
    }
}

// Exécution
$api = new CompanyMarketEventsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
