<?php
/**
 * Chat bot IA du tableau de bord entreprise (Frontend/admin-web,
 * CompanyDashboard.tsx, onglet "Assistant IA") — conversation continue par
 * entreprise. Chaque réponse s'appuie sur DEUX sources combinées :
 *   1. les données déjà agrégées du tableau de bord, envoyées telles quelles
 *      par le frontend (même payload que celui utilisé pour l'"Analyse IA
 *      globale" via ChartAnalysisService, chart_type 'company_dashboard') ;
 *   2. la recherche internet native du fournisseur IA choisi (voir
 *      AiChatClientInterface) — contrairement à ChartAnalysisService, qui ne
 *      contacte jamais le web hormis le fournisseur IA lui-même, ici le
 *      fournisseur peut chercher en ligne pour du contexte que les données
 *      internes n'ont pas (actualité récente, comparaisons de marché,
 *      définitions...).
 *
 * Fichier indépendant de ChartAnalysisService par convention de ce projet
 * (voir son en-tête : "fichiers indépendants... plutôt qu'un couplage entre
 * classes") — même si certaines constantes (PROVIDERS) sont dupliquées.
 */
class CompanyChatService {
    private const PROVIDERS = [
        'anthropic' => ['class' => 'AnthropicClient', 'default_model' => 'claude-opus-5'],
        'gemini' => ['class' => 'GeminiClient', 'default_model' => 'gemini-flash-lite-latest'],
        'grok' => ['class' => 'GrokClient', 'default_model' => 'grok-4-fast-reasoning'],
    ];
    private const DEFAULT_PROVIDER = 'gemini';

    /**
     * Nombre de messages (pas d'échanges) d'historique renvoyés au
     * fournisseur comme contexte de conversation — borne la croissance du
     * prompt sur une discussion longue plutôt que de renvoyer tout
     * l'historique à chaque nouveau message.
     */
    private const MAX_HISTORY_MESSAGES = 16;

    /**
     * Le payload du tableau de bord peut être volumineux (historiques de
     * cours, séries sectorielles...) — même logique de troncature que
     * ChartAnalysisService::truncateReportText().
     */
    private const MAX_DASHBOARD_DATA_CHARS = 25000;

    private $crud;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
    }

    public function listMessages(int $companyId): array {
        $rows = $this->crud->executeCustomQuery(
            "SELECT * FROM company_chat_messages WHERE company_id = ? ORDER BY id ASC",
            [$companyId]
        ) ?: [];

        return array_map([$this, 'formatMessage'], $rows);
    }

    public function clearConversation(int $companyId): void {
        $this->crud->executeCustomQuery("DELETE FROM company_chat_messages WHERE company_id = ?", [$companyId]);
    }

    /**
     * @param array $company ['symbol'=>string,'name'=>string,'sector'=>?string]
     * @param array $dashboardData Données déjà agrégées du tableau de bord (voir CompanyDashboard.tsx, globalAnalysisData)
     */
    public function sendMessage(int $companyId, array $company, array $dashboardData, string $userMessage, ?string $provider, ?string $model): array {
        $provider = $provider ?: self::DEFAULT_PROVIDER;
        if (!isset(self::PROVIDERS[$provider])) {
            throw new Exception("Fournisseur IA inconnu: $provider. Disponibles: " . implode(', ', array_keys(self::PROVIDERS)));
        }
        $model = $model ?: self::PROVIDERS[$provider]['default_model'];

        $userMessage = trim($userMessage);
        if ($userMessage === '') {
            throw new Exception("Message vide");
        }

        // Historique AVANT insertion du nouveau message utilisateur : c'est
        // le contexte de conversation transmis au fournisseur, le nouveau
        // message étant passé séparément (voir AiChatClientInterface).
        $historyRows = $this->crud->executeCustomQuery(
            "SELECT role, content FROM company_chat_messages WHERE company_id = ? ORDER BY id DESC LIMIT ?",
            [$companyId, self::MAX_HISTORY_MESSAGES]
        ) ?: [];
        $history = array_reverse(array_map(
            fn($r) => ['role' => $r['role'], 'content' => $r['content']],
            $historyRows
        ));

        // Toujours persisté, même si l'appel IA échoue ensuite : l'utilisateur
        // a bien envoyé ce message, pas de raison de le perdre.
        $this->crud->persist('company_chat_messages', [
            'company_id' => $companyId,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        $systemPrompt = $this->buildSystemPrompt($company, $dashboardData);
        $client = $this->createClient($provider);
        $result = $client->generateChatReply($systemPrompt, $history, $userMessage, $model, ['timeout_seconds' => 150]);

        if (!$result['success']) {
            throw new Exception($result['error'] ?? "Échec de la réponse IA");
        }

        $sources = $result['sources'] ?? [];
        $newId = $this->crud->persist('company_chat_messages', [
            'company_id' => $companyId,
            'role' => 'assistant',
            'content' => $result['text'],
            'provider' => $provider,
            'model' => $model,
            'sources' => !empty($sources) ? json_encode($sources, JSON_UNESCAPED_UNICODE) : null,
        ]);

        $row = $this->crud->executeCustomQuery("SELECT * FROM company_chat_messages WHERE id = ?", [$newId]);
        return $this->formatMessage($row[0]);
    }

    private function formatMessage(array $row): array {
        return [
            'id' => (int) $row['id'],
            'company_id' => (int) $row['company_id'],
            'role' => $row['role'],
            'content' => $row['content'],
            'provider' => $row['provider'],
            'model' => $row['model'],
            'sources' => $row['sources'] ? json_decode($row['sources'], true) : [],
            'created_at' => $row['created_at'],
        ];
    }

    private function createClient(string $provider): AiChatClientInterface {
        $class = self::PROVIDERS[$provider]['class'];
        return new $class();
    }

    private function buildSystemPrompt(array $company, array $dashboardData): string {
        $symbol = $company['symbol'] ?? '?';
        $name = $company['name'] ?? $symbol;
        $sector = $company['sector'] ?? null;

        $dashboardJson = json_encode($dashboardData, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($dashboardJson === false) {
            $dashboardJson = '{}';
        }
        if (mb_strlen($dashboardJson) > self::MAX_DASHBOARD_DATA_CHARS) {
            $dashboardJson = mb_substr($dashboardJson, 0, self::MAX_DASHBOARD_DATA_CHARS) . "\n... [tronqué]";
        }

        $sectorPhrase = $sector
            ? ", cotée à la BRVM (Bourse Régionale des Valeurs Mobilières), secteur $sector."
            : ", cotée à la BRVM (Bourse Régionale des Valeurs Mobilières).";

        return
            "Tu es l'assistant IA intégré au tableau de bord de l'entreprise $name ($symbol)$sectorPhrase\n\n" .
            "Un utilisateur DÉBUTANT en finance et en bourse va te poser des questions dans un chat. Ton rôle : répondre en " .
            "choisissant la bonne source selon la question, en suivant CETTE LOGIQUE DE DÉCISION à chaque message :\n" .
            "1. La question porte sur quelque chose de présent dans les DONNÉES DU TABLEAU DE BORD ci-dessous (cours, " .
            "indicateurs techniques, fondamentaux extraits des rapports financiers, opérations sur titres, résultats de " .
            "backtest, mesures de risque, classement sectoriel...) ? → Réponds en priorité et en détail à partir de CES " .
            "données précises (ce sont les données internes de l'application, les plus fiables et les plus à jour pour ce " .
            "qu'elles couvrent) — ne les remplace pas par une recherche internet si elles suffisent déjà à répondre.\n" .
            "2. La question sort du périmètre de ces données (actualité récente, événement non encore dans les rapports, " .
            "information générale sur l'entreprise/son activité/son secteur, définition d'un terme, comparaison avec " .
            "d'autres sociétés ou d'autres marchés, contexte macroéconomique...) ou les données du tableau de bord ne " .
            "contiennent tout simplement pas de quoi répondre ? → Fais une recherche internet pour compléter. Privilégie, " .
            "par ordre de fiabilité pour une entreprise cotée à la BRVM : le site officiel de la BRVM (brvm.org), le site " .
            "internet officiel de l'entreprise elle-même (communiqués, rapports annuels, page investisseurs), Sika Finance " .
            "(sikafinance.com, référence pour l'actualité et les données boursières en Afrique de l'Ouest francophone), " .
            "puis d'autres sources financières sérieuses si besoin (presse économique régionale/internationale). Évite les " .
            "sources anonymes ou non spécialisées quand une source financière fiable existe.\n" .
            "3. Une question peut combiner les deux : commence par les données internes du tableau de bord, puis complète " .
            "explicitement avec ce que tu trouves en ligne.\n" .
            "Distingue toujours, au moins implicitement dans ta réponse, ce qui vient des données du tableau de bord de ce " .
            "qui vient de ta recherche internet (et de quelle source).\n\n" .
            "RÈGLES DE RÉPONSE (impératif) :\n" .
            "- Structure toujours ta réponse en Markdown clair : titres/sous-titres (## ou ###), listes à puces, **gras** sur " .
            "les chiffres et termes clés, tableau Markdown dès que tu compares plusieurs valeurs.\n" .
            "- Vulgarise systématiquement : pour chaque terme technique employé (PER, ROE, RSI, SMA/moyenne mobile, " .
            "dividende, capitalisation flottante, vente à découvert, spread...) ajoute une explication simple, courte, en " .
            "langage courant — écris pour quelqu'un qui n'a jamais suivi de cours de finance ni de bourse.\n" .
            "- Sois précis et détaillé : cite les chiffres exacts disponibles (avec leur date/période) plutôt que de rester " .
            "dans des généralités vagues.\n" .
            "- Termine toujours par une section \"## Points clés à retenir\" (3 à 5 puces très simples) qui résume la " .
            "réponse pour quelqu'un de pressé.\n" .
            "- N'invente jamais un chiffre : si une donnée n'est disponible ni dans le tableau de bord ni via ta recherche " .
            "internet, dis-le explicitement plutôt que d'estimer.\n" .
            "- Si la question porte sur une décision d'achat/vente, rappelle brièvement que tu expliques les données mais " .
            "ne donnes pas de conseil en investissement personnalisé — la décision finale appartient à l'utilisateur.\n" .
            "- Réponds toujours en français, quelle que soit la langue de la question.\n\n" .
            "DONNÉES ACTUELLES DU TABLEAU DE BORD (JSON, agrégeant les différents onglets du tableau de bord de cette " .
            "entreprise) :\n" .
            $dashboardJson;
    }
}
