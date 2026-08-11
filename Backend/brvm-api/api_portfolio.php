<?php
/**
 * API "Mon Équipe BRVM" — portefeuille en 4-3-3
 * Endpoint: api_portfolio.php
 *
 * Voir TODO_PORTFOLIO_TEAM.md pour le plan complet. Transforme les
 * positions de l'utilisateur (simulées ou réelles) en "équipe" : un rôle
 * par titre (Défense/Milieu/Attaque, le Gardien étant la réserve de cash),
 * une note par joueur (score composite 0-100 partagé avec
 * api_composite_score.php via class/CompositeScoreCalculator.php), une
 * note par ligne, et des alertes d'équilibre déterministes. Jamais un
 * conseil d'achat/vente — un outil d'aide à la construction du premier
 * portefeuille pour un investisseur débutant.
 *
 * PREMIÈRE fonctionnalité multi-tenant du projet : chaque action scope ses
 * lectures/écritures à AuthGuard::getCurrentUserId() — un oubli de clause
 * admin_user_id serait une vraie fuite de données entre comptes, pas un
 * détail de style.
 *
 * La classification de liquidité (getLiquidityByCompany) et les lectures
 * fondamentaux/benchmark (getFundamentalsByCompany/getBenchmarkReturn)
 * sont dupliquées depuis api_composite_score.php par convention (petites
 * requêtes, déjà dupliquées plusieurs fois dans le projet — seule la
 * formule de score, substantielle, est partagée via la classe commune).
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
require_once 'class/CompositeScoreCalculator.php';
require_once 'class/AuthGuard.php';
AuthGuard::requireAuth();
require_once 'class/AiClientInterface.php';
require_once 'class/AiChatClientInterface.php';
require_once 'class/GeminiClient.php';
require_once 'class/AnthropicClient.php';
require_once 'class/GrokClient.php';

class PortfolioAPI {
    /** Fenêtre "performance récente" utilisée pour Momentum/Marché (jours calendaires). */
    private const PERFORMANCE_WINDOW_DAYS = 30;

    // Seuils d'alertes — délibérément simples et documentés (voir
    // TODO_PORTFOLIO_TEAM.md, "Points à trancher" : tranchés ici avec des
    // valeurs de départ raisonnables, ajustables à l'usage).
    private const SECTOR_CONCENTRATION_THRESHOLD = 0.40;
    private const WEAK_RESERVE_THRESHOLD = 0.10;
    private const DIVIDEND_DEPENDENCY_VALUE_SHARE = 0.5;
    private const DIVIDEND_DEPENDENCY_MIN_YIELD = 5.0;

    // Pondérations de ligne cibles par profil (voir action suggestions).
    private const PROFILES = [
        'prudent' => ['defense' => 50, 'milieu' => 30, 'attaque' => 20],
        'equilibre' => ['defense' => 35, 'milieu' => 35, 'attaque' => 30],
        'dynamique' => ['defense' => 20, 'milieu' => 30, 'attaque' => 50],
    ];

    /**
     * Même registre de fournisseurs que les autres services IA du projet —
     * voir class/AiClientInterface.php pour ajouter un fournisseur.
     */
    private const AI_PROVIDERS = [
        'anthropic' => ['class' => 'AnthropicClient', 'default_model' => 'claude-opus-5'],
        'gemini' => ['class' => 'GeminiClient', 'default_model' => 'gemini-flash-lite-latest'],
        'grok' => ['class' => 'GrokClient', 'default_model' => 'grok-4-fast-reasoning'],
    ];
    private const AI_DEFAULT_PROVIDER = 'gemini';
    private const AI_DISCLAIMER = "Avis généré automatiquement à titre informatif, "
        . "ne constitue pas un conseil en investissement. C'est toi le coach : chaque proposition "
        . "reste à valider ou refuser individuellement.";

    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'team':
                    return $this->team($input);

                case 'suggestions':
                    return $this->suggestions($input);

                case 'propose_team':
                    return $this->proposeTeam($input);

                case 'propose_team_ai':
                    return $this->proposeTeamAi($input);

                case 'list_team_proposals':
                    return $this->listTeamProposals($input);

                case 'get_team_proposal':
                    return $this->getTeamProposal($input);

                case 'rate_team_proposal':
                    return $this->rateTeamProposal($input);

                case 'delete_team_proposal':
                    return $this->deleteTeamProposal($input);

                case 'adopt_team':
                    return $this->adoptTeam($input);

                case 'ai_review':
                    return $this->aiReview($input);

                case 'list_reviews':
                    return $this->listReviews($input);

                case 'get_review':
                    return $this->getReview($input);

                case 'delete_review':
                    return $this->deleteReview($input);

                case 'add_holding':
                    return $this->addHolding($input);

                case 'update_holding':
                    return $this->updateHolding($input);

                case 'remove_holding':
                    return $this->removeHolding($input);

                case 'set_cash_reserve':
                    return $this->setCashReserve($input);

                case 'save_thesis':
                    return $this->saveThesis($input);

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

    private function requireUserId(): int {
        $userId = AuthGuard::getCurrentUserId();
        if ($userId === null) {
            throw new Exception("Utilisateur courant introuvable — reconnecte-toi");
        }
        return $userId;
    }

    /**
     * Position existante appartenant à l'utilisateur courant — throw sinon
     * (jamais agir sur la position d'un autre compte, même par id deviné).
     */
    private function requireOwnedHolding(int $userId, int $holdingId): array {
        $rows = $this->crud->find('portfolio_holdings', ['id' => $holdingId, 'admin_user_id' => $userId]);
        if (empty($rows)) {
            throw new Exception("Position introuvable (id=$holdingId) pour cet utilisateur");
        }
        return $rows[0];
    }

    private function addHolding($input) {
        $userId = $this->requireUserId();

        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId) {
            throw new Exception("company_id requis");
        }
        if (!$this->crud->findById('companies', $companyId)) {
            throw new Exception("Entreprise inconnue (id=$companyId)");
        }

        $status = $input['status'] ?? 'simule';
        if (!in_array($status, ['simule', 'achete'], true)) {
            throw new Exception("status invalide: $status (attendu: simule ou achete)");
        }
        if ($status === 'achete') {
            $missing = [];
            if (!isset($input['quantity']) || $input['quantity'] === '') $missing[] = 'quantity';
            if (!isset($input['average_purchase_price']) || $input['average_purchase_price'] === '') $missing[] = 'average_purchase_price';
            if ($missing) {
                throw new Exception("Champs requis pour un achat réel: " . implode(', ', $missing));
            }
        }

        $existing = $this->crud->find('portfolio_holdings', ['admin_user_id' => $userId, 'company_id' => $companyId]);
        if (!empty($existing)) {
            throw new Exception("Cette entreprise est déjà dans l'équipe — utilise update_holding");
        }

        $roleOverride = $input['role_override'] ?? null;
        if ($roleOverride !== null && !in_array($roleOverride, ['gardien', 'defense', 'milieu', 'attaque'], true)) {
            throw new Exception("role_override invalide: $roleOverride");
        }

        $id = $this->crud->persist('portfolio_holdings', [
            'admin_user_id' => $userId,
            'company_id' => $companyId,
            'status' => $status,
            'target_amount_fcfa' => isset($input['target_amount_fcfa']) && $input['target_amount_fcfa'] !== '' ? (float) $input['target_amount_fcfa'] : null,
            'quantity' => isset($input['quantity']) && $input['quantity'] !== '' ? (float) $input['quantity'] : null,
            'average_purchase_price' => isset($input['average_purchase_price']) && $input['average_purchase_price'] !== '' ? (float) $input['average_purchase_price'] : null,
            'purchase_date' => !empty($input['purchase_date']) ? $input['purchase_date'] : null,
            'role_override' => $roleOverride,
        ]);

        return ['success' => true, 'data' => ['id' => (int) $id]];
    }

    private function updateHolding($input) {
        $userId = $this->requireUserId();
        $holdingId = (int) ($input['id'] ?? 0);
        if (!$holdingId) {
            throw new Exception("id requis");
        }
        $holding = $this->requireOwnedHolding($userId, $holdingId);

        $update = [];

        if (isset($input['status'])) {
            if (!in_array($input['status'], ['simule', 'achete'], true)) {
                throw new Exception("status invalide: {$input['status']}");
            }
            $update['status'] = $input['status'];
        }
        // Bascule vers 'achete' : quantité et prix requis, soit fournis dans
        // ce même appel, soit déjà présents sur la ligne.
        $finalStatus = $update['status'] ?? $holding['status'];
        if ($finalStatus === 'achete') {
            $finalQuantity = array_key_exists('quantity', $input) && $input['quantity'] !== '' ? $input['quantity'] : $holding['quantity'];
            $finalPrice = array_key_exists('average_purchase_price', $input) && $input['average_purchase_price'] !== '' ? $input['average_purchase_price'] : $holding['average_purchase_price'];
            if ($finalQuantity === null || $finalPrice === null) {
                throw new Exception("Champs requis pour un achat réel: quantity et average_purchase_price");
            }
        }

        foreach (['target_amount_fcfa', 'quantity', 'average_purchase_price'] as $numField) {
            if (array_key_exists($numField, $input)) {
                $update[$numField] = $input[$numField] !== '' && $input[$numField] !== null ? (float) $input[$numField] : null;
            }
        }
        if (array_key_exists('purchase_date', $input)) {
            $update['purchase_date'] = !empty($input['purchase_date']) ? $input['purchase_date'] : null;
        }
        if (array_key_exists('role_override', $input)) {
            $ro = $input['role_override'];
            if ($ro !== null && $ro !== '' && !in_array($ro, ['gardien', 'defense', 'milieu', 'attaque'], true)) {
                throw new Exception("role_override invalide: $ro");
            }
            $update['role_override'] = ($ro === '' || $ro === null) ? null : $ro;
        }

        if (empty($update)) {
            throw new Exception("Aucun champ à mettre à jour");
        }

        $this->crud->merge('portfolio_holdings', $update, ['id' => $holdingId, 'admin_user_id' => $userId]);

        return ['success' => true, 'data' => ['id' => $holdingId]];
    }

    private function removeHolding($input) {
        $userId = $this->requireUserId();
        $holdingId = (int) ($input['id'] ?? 0);
        if (!$holdingId) {
            throw new Exception("id requis");
        }
        $this->requireOwnedHolding($userId, $holdingId);

        // Le FK ON DELETE CASCADE sur portfolio_thesis nettoie la thèse liée.
        $this->crud->remove('portfolio_holdings', ['id' => $holdingId, 'admin_user_id' => $userId]);

        return ['success' => true, 'data' => ['id' => $holdingId]];
    }

    private function setCashReserve($input) {
        $userId = $this->requireUserId();

        if (!isset($input['amount']) || $input['amount'] === '' || (float) $input['amount'] < 0) {
            throw new Exception("amount requis (nombre >= 0)");
        }
        $amount = (float) $input['amount'];
        $currency = !empty($input['currency']) ? $input['currency'] : 'FCFA';

        $existing = $this->crud->find('portfolio_cash_reserve', ['admin_user_id' => $userId]);
        if (!empty($existing)) {
            $this->crud->merge('portfolio_cash_reserve', ['amount' => $amount, 'currency' => $currency], ['admin_user_id' => $userId]);
        } else {
            $this->crud->persist('portfolio_cash_reserve', ['admin_user_id' => $userId, 'amount' => $amount, 'currency' => $currency]);
        }

        return ['success' => true, 'data' => ['amount' => $amount, 'currency' => $currency]];
    }

    private function saveThesis($input) {
        $userId = $this->requireUserId();
        $holdingId = (int) ($input['holding_id'] ?? 0);
        if (!$holdingId) {
            throw new Exception("holding_id requis");
        }
        $this->requireOwnedHolding($userId, $holdingId);

        $data = [
            'buy_reason' => isset($input['buy_reason']) && $input['buy_reason'] !== '' ? $input['buy_reason'] : null,
            'exit_criteria' => isset($input['exit_criteria']) && $input['exit_criteria'] !== '' ? $input['exit_criteria'] : null,
        ];

        $existing = $this->crud->find('portfolio_thesis', ['holding_id' => $holdingId]);
        if (!empty($existing)) {
            $this->crud->merge('portfolio_thesis', $data, ['holding_id' => $holdingId]);
        } else {
            $this->crud->persist('portfolio_thesis', array_merge(['holding_id' => $holdingId], $data));
        }

        return ['success' => true, 'data' => ['holding_id' => $holdingId]];
    }

    /**
     * Vue d'équipe complète — tout ce dont le frontend a besoin en un appel.
     */
    private function team($input) {
        $userId = $this->requireUserId();

        // Positions + entreprise + dernier cours connu (même pattern
        // "dernière cotation par entreprise" qu'api_screener.php) + derniers
        // indicateurs techniques pour le signal.
        $sql = "
            SELECT
                h.id, h.company_id, h.status, h.target_amount_fcfa, h.quantity,
                h.average_purchase_price, h.purchase_date, h.role_override,
                c.symbol, c.name, c.sector_id, s.name AS sector,
                sq.close_price, sq.trading_date,
                ti.rsi_14, ti.macd_line, ti.macd_signal, ti.sma_10, ti.sma_20, ti.bb_upper, ti.bb_lower,
                t.buy_reason, t.exit_criteria
            FROM portfolio_holdings h
            INNER JOIN companies c ON c.id = h.company_id
            LEFT JOIN sectors s ON s.id = c.sector_id
            LEFT JOIN stock_quotes sq
                ON sq.company_id = c.id
                AND sq.trading_date = (SELECT MAX(trading_date) FROM stock_quotes WHERE company_id = c.id)
            LEFT JOIN technical_indicators ti
                ON ti.company_id = c.id AND ti.trading_date = sq.trading_date
            LEFT JOIN portfolio_thesis t ON t.holding_id = h.id
            WHERE h.admin_user_id = ?
            ORDER BY h.id ASC
        ";
        $rows = $this->crud->executeCustomQuery($sql, [$userId]) ?: [];

        $reserveRows = $this->crud->find('portfolio_cash_reserve', ['admin_user_id' => $userId]);
        $cashReserve = !empty($reserveRows)
            ? ['amount' => (float) $reserveRows[0]['amount'], 'currency' => $reserveRows[0]['currency']]
            : ['amount' => 0.0, 'currency' => 'FCFA'];

        $startDate = date('Y-m-d', strtotime('-' . self::PERFORMANCE_WINDOW_DAYS . ' days'));
        $endDate = date('Y-m-d');

        $companyIds = array_map(fn($r) => (int) $r['company_id'], $rows);
        $liquidityByCompany = $this->getLiquidityByCompany();
        $performanceByCompany = $this->getPerformanceByCompany($companyIds, $startDate, $endDate);
        $fundamentalsByCompany = $this->getFundamentalsByCompany();
        $benchmarkReturn = $this->getBenchmarkReturn($startDate, $endDate);

        // Rang sectoriel AU SEIN DE L'ÉQUIPE (pas du marché entier) : la
        // question ici est la diversification de MON équipe, pas le
        // classement absolu du titre sur toute la cote.
        $bySector = [];
        foreach ($rows as $row) {
            $cid = (int) $row['company_id'];
            if (isset($performanceByCompany[$cid])) {
                $bySector[$row['sector_id']][] = ['company_id' => $cid, 'performance' => $performanceByCompany[$cid]];
            }
        }
        $sectorRankByCompany = [];
        $sectorSizeByCompany = [];
        foreach ($bySector as $members) {
            usort($members, fn($a, $b) => $b['performance'] <=> $a['performance']);
            $rank = 1;
            foreach ($members as $m) {
                $sectorRankByCompany[$m['company_id']] = $rank;
                $sectorSizeByCompany[$m['company_id']] = count($members);
                $rank++;
            }
        }

        $holdings = [];
        $lines = [
            'defense' => ['weighted_sum' => 0.0, 'weight' => 0.0, 'value_fcfa' => 0.0, 'count' => 0],
            'milieu' => ['weighted_sum' => 0.0, 'weight' => 0.0, 'value_fcfa' => 0.0, 'count' => 0],
            'attaque' => ['weighted_sum' => 0.0, 'weight' => 0.0, 'value_fcfa' => 0.0, 'count' => 0],
        ];
        $valueBySector = [];
        $dividendHeavyValue = 0.0;

        foreach ($rows as $row) {
            $cid = (int) $row['company_id'];
            $liquidity = $liquidityByCompany[$cid] ?? null;
            $fundamentals = $fundamentalsByCompany[$cid] ?? null;
            $periodPerformance = $performanceByCompany[$cid] ?? null;

            $subScores = CompositeScoreCalculator::computeSubScores(
                $row, $fundamentals, $liquidity, $periodPerformance,
                $sectorRankByCompany[$cid] ?? null, $sectorSizeByCompany[$cid] ?? null,
                $benchmarkReturn
            );
            $weighted = CompositeScoreCalculator::weightedScore($subScores);

            $role = $this->classifyRole($row['role_override'], $subScores);
            $rolePartial = $subScores['fundamental'] === null;

            $closePrice = $row['close_price'] !== null ? (float) $row['close_price'] : null;
            $positionValue = $row['status'] === 'achete'
                ? (float) $row['quantity'] * ($closePrice ?? 0)
                : (float) ($row['target_amount_fcfa'] ?? 0);

            $holdings[] = [
                'id' => (int) $row['id'],
                'company_id' => $cid,
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'sector' => $row['sector'],
                'status' => $row['status'],
                'target_amount_fcfa' => $row['target_amount_fcfa'] !== null ? (float) $row['target_amount_fcfa'] : null,
                'quantity' => $row['quantity'] !== null ? (float) $row['quantity'] : null,
                'average_purchase_price' => $row['average_purchase_price'] !== null ? (float) $row['average_purchase_price'] : null,
                'purchase_date' => $row['purchase_date'],
                'role_override' => $row['role_override'],
                'role' => $role,
                'role_partial' => $rolePartial,
                'composite_score' => $weighted['composite_score'],
                'coverage_percent' => $weighted['coverage_percent'],
                'sub_scores' => $subScores,
                'close_price' => $closePrice,
                'position_value_fcfa' => round($positionValue, 2),
                'thesis' => ($row['buy_reason'] !== null || $row['exit_criteria'] !== null)
                    ? ['buy_reason' => $row['buy_reason'], 'exit_criteria' => $row['exit_criteria']]
                    : null,
            ];

            // 'gardien' en override manuel : compté hors des 3 lignes de jeu
            // (rejoint conceptuellement la réserve, mais reste une position).
            if (isset($lines[$role])) {
                $lines[$role]['value_fcfa'] += $positionValue;
                $lines[$role]['count']++;
                if ($weighted['composite_score'] !== null && $positionValue > 0) {
                    $lines[$role]['weighted_sum'] += $weighted['composite_score'] * $positionValue;
                    $lines[$role]['weight'] += $positionValue;
                }
            }

            if ($row['sector'] !== null) {
                $valueBySector[$row['sector']] = ($valueBySector[$row['sector']] ?? 0) + $positionValue;
            }
            if ($fundamentals !== null && $fundamentals['dividend_yield_percent'] !== null
                && $fundamentals['dividend_yield_percent'] >= self::DIVIDEND_DEPENDENCY_MIN_YIELD) {
                $dividendHeavyValue += $positionValue;
            }
        }

        $linesOut = [];
        foreach ($lines as $key => $l) {
            $linesOut[$key] = [
                'score' => $l['weight'] > 0 ? round($l['weighted_sum'] / $l['weight'], 1) : null,
                'value_fcfa' => round($l['value_fcfa'], 2),
                'count' => $l['count'],
            ];
        }

        $totalValue = array_sum(array_column($linesOut, 'value_fcfa'));
        // Les positions en override 'gardien' ne sont dans aucune ligne mais
        // comptent dans la valeur totale du portefeuille.
        foreach ($holdings as $h) {
            if ($h['role'] === 'gardien') {
                $totalValue += $h['position_value_fcfa'];
            }
        }
        $totalWithCash = $totalValue + $cashReserve['amount'];

        $alerts = $this->buildAlerts($linesOut, $valueBySector, $dividendHeavyValue, $totalValue, $totalWithCash, $cashReserve['amount']);
        $warningCount = count(array_filter($alerts, fn($a) => $a['severity'] === 'warning'));
        $balanceScore = max(0, 100 - 20 * $warningCount);

        return [
            'success' => true,
            'data' => [
                'cash_reserve' => $cashReserve,
                'holdings' => $holdings,
                'lines' => $linesOut,
                'total_portfolio_value_fcfa' => round($totalValue, 2),
                'total_value_with_cash_fcfa' => round($totalWithCash, 2),
                'balance_score' => $balanceScore,
                'alerts' => $alerts,
            ],
        ];
    }

    /**
     * Règle de classification déterministe (voir TODO_PORTFOLIO_TEAM.md,
     * "Logique de classification") — l'override manuel prime toujours.
     */
    private function classifyRole(?string $roleOverride, array $subScores): string {
        if ($roleOverride !== null && $roleOverride !== '') {
            return $roleOverride;
        }
        if ($subScores['fundamental'] !== null && $subScores['fundamental'] >= 60
            && ($subScores['momentum'] === null || $subScores['momentum'] < 60)) {
            return 'defense';
        }
        if (($subScores['momentum'] !== null && $subScores['momentum'] >= 65)
            || ($subScores['market'] !== null && $subScores['market'] >= 65)) {
            return 'attaque';
        }
        return 'milieu';
    }

    /**
     * Alertes d'équilibre — règles déterministes, jamais de génération
     * libre. Une alerte non déclenchée n'apparaît pas du tout dans le
     * tableau (pas d'entrée null/false).
     */
    private function buildAlerts(array $lines, array $valueBySector, float $dividendHeavyValue, float $totalValue, float $totalWithCash, float $cashAmount): array {
        $alerts = [];

        if ($totalValue > 0) {
            foreach ($valueBySector as $sector => $value) {
                $share = $value / $totalValue;
                if ($share > self::SECTOR_CONCENTRATION_THRESHOLD) {
                    $alerts[] = [
                        'type' => 'sector_concentration',
                        'severity' => 'warning',
                        'message' => "Forte concentration sur le secteur $sector (" . round($share * 100) . "% du portefeuille).",
                    ];
                }
            }

            if ($lines['attaque']['value_fcfa'] > ($lines['defense']['value_fcfa'] + $lines['milieu']['value_fcfa'])) {
                $alerts[] = [
                    'type' => 'offensive_imbalance',
                    'severity' => 'warning',
                    'message' => "Portefeuille très offensif : la ligne Attaque pèse plus que Défense et Milieu réunies.",
                ];
            }

            $dividendShare = $dividendHeavyValue / $totalValue;
            if ($dividendShare > self::DIVIDEND_DEPENDENCY_VALUE_SHARE) {
                $alerts[] = [
                    'type' => 'dividend_dependency',
                    'severity' => 'info',
                    'message' => "Portefeuille fortement dépendant des dividendes (plus de la moitié de sa valeur dans des titres à haut rendement) — neutre en soi, à avoir en tête si tu comptais aussi sur la plus-value.",
                ];
            }
        }

        if ($totalWithCash > 0) {
            $reserveRatio = $cashAmount / $totalWithCash;
            if ($reserveRatio < self::WEAK_RESERVE_THRESHOLD) {
                $alerts[] = [
                    'type' => 'weak_reserve',
                    'severity' => 'warning',
                    'message' => "Réserve de sécurité faible (" . round($reserveRatio * 100) . "% du total) — vise au moins 10%.",
                ];
            }
        }

        return $alerts;
    }

    /**
     * Meilleurs candidats par rôle sur toute la cote active, pour composer
     * la première équipe d'un utilisateur sans aucune position — voir
     * TODO_PORTFOLIO_TEAM.md, "Point de départ pour un utilisateur qui ne
     * possède encore rien".
     */
    private function suggestions($input) {
        $this->requireUserId();

        $profile = $input['profile'] ?? '';
        if (!isset(self::PROFILES[$profile])) {
            throw new Exception("profile invalide: $profile (attendu: " . implode(', ', array_keys(self::PROFILES)) . ")");
        }
        $targetWeights = self::PROFILES[$profile];
        $budget = isset($input['budget_fcfa']) && $input['budget_fcfa'] !== '' ? (float) $input['budget_fcfa'] : null;
        $perRoleCount = max(1, min(12, (int) ($input['per_role_count'] ?? 6)));

        $pools = $this->buildScoredPools();

        $candidates = [];
        foreach ($pools as $role => $pool) {
            // Sélection gloutonne avec plafond de 2 par secteur — une liste
            // incomplète est plus honnête qu'une diversification diluée.
            $selected = [];
            $perSector = [];
            foreach ($pool as $candidate) {
                if (count($selected) >= $perRoleCount) break;
                $sectorKey = $candidate['sector'] ?? '(sans secteur)';
                if (($perSector[$sectorKey] ?? 0) >= 2) continue;
                $perSector[$sectorKey] = ($perSector[$sectorKey] ?? 0) + 1;
                $selected[] = $candidate;
            }

            $n = count($selected);
            foreach ($selected as &$candidate) {
                $candidate['suggested_amount_fcfa'] = ($budget !== null && $n > 0)
                    ? round(($targetWeights[$role] / 100 * $budget) / $n)
                    : null;
            }
            unset($candidate);

            $candidates[$role] = $selected;
        }

        return [
            'success' => true,
            'data' => [
                'profile' => $profile,
                'target_weights' => $targetWeights,
                'budget_fcfa' => $budget,
                'candidates' => $candidates,
            ],
        ];
    }

    /**
     * Score + classification de TOUTE la cote active, par rôle, triée par
     * score décroissant (nulls en dernier) — brique commune à suggestions
     * (pioche manuelle) et propose_team (XI complet automatique). Rang
     * sectoriel marché entier ici (contrairement à team) : la question est
     * "ce titre est-il bon en général", pas "comment se place-t-il dans mon
     * équipe".
     *
     * @param int[] $excludeCompanyIds Entreprises à écarter (ex: déjà dans l'équipe)
     * @return array{defense: array, milieu: array, attaque: array}
     */
    private function buildScoredPools(array $excludeCompanyIds = []): array {
        $startDate = date('Y-m-d', strtotime('-' . self::PERFORMANCE_WINDOW_DAYS . ' days'));
        $endDate = date('Y-m-d');

        // Toute la cote active (même requête de base qu'api_composite_score.php).
        $sql = "
            SELECT
                c.id AS company_id, c.symbol, c.name, c.sector_id, s.name AS sector,
                sq.close_price,
                ti.rsi_14, ti.macd_line, ti.macd_signal, ti.sma_10, ti.sma_20, ti.bb_upper, ti.bb_lower
            FROM companies c
            LEFT JOIN sectors s ON s.id = c.sector_id
            INNER JOIN stock_quotes sq
                ON sq.company_id = c.id
                AND sq.trading_date = (SELECT MAX(trading_date) FROM stock_quotes WHERE company_id = c.id)
            LEFT JOIN technical_indicators ti
                ON ti.company_id = c.id AND ti.trading_date = sq.trading_date
            WHERE c.active = 1
        ";
        $rows = $this->crud->executeCustomQuery($sql) ?: [];

        $companyIds = array_map(fn($r) => (int) $r['company_id'], $rows);
        $liquidityByCompany = $this->getLiquidityByCompany();
        $performanceByCompany = $this->getPerformanceByCompany($companyIds, $startDate, $endDate);
        $fundamentalsByCompany = $this->getFundamentalsByCompany();
        $benchmarkReturn = $this->getBenchmarkReturn($startDate, $endDate);

        $bySector = [];
        foreach ($rows as $row) {
            $cid = (int) $row['company_id'];
            if (isset($performanceByCompany[$cid])) {
                $bySector[$row['sector_id']][] = ['company_id' => $cid, 'performance' => $performanceByCompany[$cid]];
            }
        }
        $sectorRankByCompany = [];
        $sectorSizeByCompany = [];
        foreach ($bySector as $members) {
            usort($members, fn($a, $b) => $b['performance'] <=> $a['performance']);
            $rank = 1;
            foreach ($members as $m) {
                $sectorRankByCompany[$m['company_id']] = $rank;
                $sectorSizeByCompany[$m['company_id']] = count($members);
                $rank++;
            }
        }

        $pools = ['defense' => [], 'milieu' => [], 'attaque' => []];
        foreach ($rows as $row) {
            $cid = (int) $row['company_id'];
            if (in_array($cid, $excludeCompanyIds, true)) continue;

            $liquidity = $liquidityByCompany[$cid] ?? null;
            $subScores = CompositeScoreCalculator::computeSubScores(
                $row, $fundamentalsByCompany[$cid] ?? null, $liquidity,
                $performanceByCompany[$cid] ?? null,
                $sectorRankByCompany[$cid] ?? null, $sectorSizeByCompany[$cid] ?? null,
                $benchmarkReturn
            );
            $weighted = CompositeScoreCalculator::weightedScore($subScores);
            $role = $this->classifyRole(null, $subScores);

            $fundamentals = $fundamentalsByCompany[$cid] ?? null;
            $pools[$role][] = [
                'company_id' => $cid,
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'sector' => $row['sector'],
                'composite_score' => $weighted['composite_score'],
                'coverage_percent' => $weighted['coverage_percent'],
                'sub_scores' => $subScores,
                'valuation_verdict' => $fundamentals !== null ? ($fundamentals['valuation_verdict'] ?? null) : null,
                'report_date' => $fundamentals !== null ? ($fundamentals['source_publish_date'] ?? null) : null,
            ];
        }

        foreach ($pools as &$pool) {
            usort($pool, function ($a, $b) {
                if ($a['composite_score'] === null && $b['composite_score'] === null) return 0;
                if ($a['composite_score'] === null) return 1;
                if ($b['composite_score'] === null) return -1;
                return $b['composite_score'] <=> $a['composite_score'];
            });
        }
        unset($pool);

        return $pools;
    }

    /**
     * XI complet proposé automatiquement en 4-3-3 (4 Défense, 3 Milieu,
     * 3 Attaque) + gardien (réserve conseillée à 10% du budget), à partir
     * des mêmes scores/classification que suggestions — mais avec un
     * plafond de diversification appliqué à L'ÉQUIPE ENTIÈRE (max 2 titres
     * par secteur sur les 10), pas seulement par ligne. Les entreprises
     * déjà dans l'équipe de l'utilisateur sont écartées d'office (la
     * proposition doit être adoptable telle quelle sans doublon). Chaque
     * joueur reçoit une justification déterministe construite depuis ses
     * sous-scores — jamais un texte généré par IA.
     */
    private function proposeTeam($input) {
        $userId = $this->requireUserId();

        $profile = $input['profile'] ?? '';
        if (!isset(self::PROFILES[$profile])) {
            throw new Exception("profile invalide: $profile (attendu: " . implode(', ', array_keys(self::PROFILES)) . ")");
        }
        $targetWeights = self::PROFILES[$profile];
        $budget = isset($input['budget_fcfa']) && $input['budget_fcfa'] !== '' ? (float) $input['budget_fcfa'] : null;

        $held = $this->crud->find('portfolio_holdings', ['admin_user_id' => $userId]);
        $heldIds = array_map(fn($h) => (int) $h['company_id'], $held);

        $pools = $this->buildScoredPools($heldIds);

        // Répartition du budget : 10% mis de côté pour le gardien (réserve
        // de sécurité — le seuil de l'alerte weak_reserve), le reste investi
        // selon les poids du profil.
        $reserve = $budget !== null ? round($budget * self::WEAK_RESERVE_THRESHOLD) : null;
        $invest = $budget !== null ? $budget - $reserve : null;

        $formation = ['defense' => 4, 'milieu' => 3, 'attaque' => 3];
        $team = [];
        $notes = [];
        $perSectorTeam = [];

        // Reclassement spécifique à la proposition (le composite_score
        // affiché reste inchangé partout ailleurs) : l'analyse des rapports
        // pèse au-delà du seul sous-score fondamental —
        //   1. verdict de valorisation du rapport analysé : sous-coté +5 /
        //      surcoté -5 sur le score de tri (sans effet aujourd'hui, 0
        //      verdict tranché en base, mais s'active à mesure que les
        //      analyses s'accumulent) ;
        //   2. à score ajusté égal, préférer la couverture la plus élevée —
        //      un titre adossé à un rapport analysé gagne l'égalité face à
        //      un titre noté sur les seules données de marché.
        foreach ($pools as &$pool) {
            usort($pool, function ($a, $b) {
                $adjA = $a['composite_score'] !== null ? $a['composite_score'] + $this->verdictBonus($a['valuation_verdict']) : null;
                $adjB = $b['composite_score'] !== null ? $b['composite_score'] + $this->verdictBonus($b['valuation_verdict']) : null;
                if ($adjA === null && $adjB === null) return 0;
                if ($adjA === null) return 1;
                if ($adjB === null) return -1;
                if ($adjA !== $adjB) return $adjB <=> $adjA;
                return $b['coverage_percent'] <=> $a['coverage_percent'];
            });
        }
        unset($pool);

        $bench = [];
        foreach ($formation as $role => $slots) {
            $selected = [];
            $selectedIds = [];
            foreach ($pools[$role] as $candidate) {
                if (count($selected) >= $slots) break;
                $sectorKey = $candidate['sector'] ?? '(sans secteur)';
                if (($perSectorTeam[$sectorKey] ?? 0) >= 2) continue;
                $perSectorTeam[$sectorKey] = ($perSectorTeam[$sectorKey] ?? 0) + 1;
                $candidate['reason'] = $this->buildPickReason($role, $candidate);
                $candidate['role_rule'] = $this->buildRoleRule($role, $candidate['sub_scores']);
                $selected[] = $candidate;
                $selectedIds[] = $candidate['company_id'];
            }

            if (count($selected) < $slots) {
                $notes[] = "Ligne " . ucfirst($role) . " incomplète (" . count($selected) . "/$slots) : pas assez de candidats nets pour ce rôle avec la diversification imposée (max 2 titres par secteur sur toute l'équipe) — mieux vaut une place vide qu'un titre forcé.";
            }

            $n = count($selected);
            foreach ($selected as &$candidate) {
                $candidate['suggested_amount_fcfa'] = ($invest !== null && $n > 0)
                    ? round(($targetWeights[$role] / 100 * $invest) / $n)
                    : null;
            }
            unset($candidate);

            $team[$role] = $selected;

            // Banc de remplaçants : les meilleurs candidats du rôle non
            // retenus dans le XI (écartés par le plafond sectoriel ou
            // au-delà du nombre de places) — pour que l'utilisateur puisse
            // faire des remplacements comme un vrai coach, sans relancer
            // toute la proposition. Montant null : un remplaçant hérite du
            // montant du joueur qu'il remplace (même rôle, même part de
            // budget — géré côté frontend).
            $roleBench = [];
            foreach ($pools[$role] as $candidate) {
                if (count($roleBench) >= 4) break;
                if (in_array($candidate['company_id'], $selectedIds, true)) continue;
                $candidate['reason'] = $this->buildPickReason($role, $candidate);
                $candidate['role_rule'] = $this->buildRoleRule($role, $candidate['sub_scores']);
                $candidate['suggested_amount_fcfa'] = null;
                $roleBench[] = $candidate;
            }
            $bench[$role] = $roleBench;
        }

        $data = [
            'origin' => 'algorithme',
            'provider' => null,
            'model' => null,
            'commentary' => null,
            'profile' => $profile,
            'target_weights' => $targetWeights,
            'budget_fcfa' => $budget,
            'reserve_fcfa' => $reserve,
            'invest_fcfa' => $invest,
            'formation' => $formation,
            'team' => $team,
            'bench' => $bench,
            'notes' => $notes,
        ];
        $data['id'] = $this->persistTeamProposal($userId, $data);

        return ['success' => true, 'data' => $data];
    }

    /**
     * Historise une proposition d'équipe (algorithmique ou IA) — notable
     * par étoiles et supprimable ensuite (voir migration 020).
     */
    private function persistTeamProposal(int $userId, array $data): int {
        return (int) $this->crud->persist('portfolio_team_proposals', [
            'admin_user_id' => $userId,
            'origin' => $data['origin'],
            'provider' => $data['provider'],
            'model' => $data['model'],
            'profile' => $data['profile'],
            'budget_fcfa' => $data['budget_fcfa'],
            'proposal' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'ai_commentary' => $data['commentary'],
        ]);
    }

    /**
     * Proposition d'équipe composée PAR L'IA — contrairement à propose_team
     * (règles déterministes), l'IA choisit elle-même le XI parmi un menu
     * fermé de candidats déjà notés, avec son raisonnement par joueur et un
     * commentaire global. Garde-fous serveur identiques dans l'esprit à
     * ai_review : uniquement des titres du menu, pas de doublon, plafond de
     * 2 titres par secteur appliqué après coup (les choix qui violent une
     * règle sont écartés et comptés dans les notes) — l'IA propose, le
     * serveur valide, l'utilisateur décide.
     */
    private function proposeTeamAi($input) {
        $userId = $this->requireUserId();

        $profile = $input['profile'] ?? '';
        if (!isset(self::PROFILES[$profile])) {
            throw new Exception("profile invalide: $profile (attendu: " . implode(', ', array_keys(self::PROFILES)) . ")");
        }
        $targetWeights = self::PROFILES[$profile];
        $budget = isset($input['budget_fcfa']) && $input['budget_fcfa'] !== '' ? (float) $input['budget_fcfa'] : null;

        $provider = $input['provider'] ?? self::AI_DEFAULT_PROVIDER;
        if (!isset(self::AI_PROVIDERS[$provider])) {
            throw new Exception("Fournisseur IA inconnu: $provider");
        }
        $model = !empty($input['model']) ? $input['model'] : self::AI_PROVIDERS[$provider]['default_model'];

        $held = $this->crud->find('portfolio_holdings', ['admin_user_id' => $userId]);
        $heldIds = array_map(fn($h) => (int) $h['company_id'], $held);
        $pools = $this->buildScoredPools($heldIds);

        $reserve = $budget !== null ? round($budget * self::WEAK_RESERVE_THRESHOLD) : null;
        $invest = $budget !== null ? $budget - $reserve : null;
        $formation = ['defense' => 4, 'milieu' => 3, 'attaque' => 3];

        // Menu fermé : top 8 par rôle, avec tout ce qu'il faut pour décider.
        $menu = [];
        $menuById = [];
        foreach ($pools as $role => $pool) {
            foreach (array_slice($pool, 0, 8) as $c) {
                $entry = [
                    'company_id' => $c['company_id'],
                    'symbol' => $c['symbol'],
                    'sector' => $c['sector'],
                    'role_auto' => $role,
                    'composite_score' => $c['composite_score'],
                    'coverage_percent' => $c['coverage_percent'],
                    'sub_scores' => $c['sub_scores'],
                    'valuation_verdict' => $c['valuation_verdict'],
                ];
                $menu[] = $entry;
                $menuById[$c['company_id']] = $c;
            }
        }

        $prompt = $this->buildProposeTeamAiPrompt($profile, $targetWeights, $invest, $formation, $menu);
        $clientClass = self::AI_PROVIDERS[$provider]['class'];
        $client = new $clientClass();
        $aiResult = $client->generateContent($prompt, $model, $this->proposeTeamAiSchema());

        if (!$aiResult['success']) {
            return ['success' => false, 'message' => $aiResult['error']];
        }

        $aiData = $aiResult['data'];
        $notes = [];
        $dropped = 0;
        $team = [];
        $usedIds = [];
        $perSectorTeam = [];

        foreach ($formation as $role => $slots) {
            $selected = [];
            foreach (($aiData['team'][$role] ?? []) as $pick) {
                if (count($selected) >= $slots) { $dropped++; continue; }
                $cid = isset($pick['company_id']) ? (int) $pick['company_id'] : 0;
                if (!isset($menuById[$cid]) || in_array($cid, $usedIds, true)) { $dropped++; continue; }
                $sectorKey = $menuById[$cid]['sector'] ?? '(sans secteur)';
                if (($perSectorTeam[$sectorKey] ?? 0) >= 2) { $dropped++; continue; }

                $candidate = $menuById[$cid];
                $perSectorTeam[$sectorKey] = ($perSectorTeam[$sectorKey] ?? 0) + 1;
                $usedIds[] = $cid;

                $autoRole = $this->classifyRole(null, $candidate['sub_scores']);
                $reason = trim((string) ($pick['reason'] ?? ''));
                if ($autoRole !== $role) {
                    // Placement hors du rôle automatique : signalé, pas interdit —
                    // c'est justement la valeur ajoutée possible de l'IA.
                    $reason .= ($reason !== '' ? ' ' : '') . "(Placement IA : le classement automatique aurait mis ce titre en " . ucfirst($autoRole) . ".)";
                }

                $selected[] = [
                    'company_id' => $cid,
                    'symbol' => $candidate['symbol'],
                    'name' => $candidate['name'],
                    'sector' => $candidate['sector'],
                    'composite_score' => $candidate['composite_score'],
                    'coverage_percent' => $candidate['coverage_percent'],
                    'sub_scores' => $candidate['sub_scores'],
                    'valuation_verdict' => $candidate['valuation_verdict'],
                    'report_date' => $candidate['report_date'],
                    'reason' => $reason !== '' ? $reason : $this->buildPickReason($role, $candidate),
                    'role_rule' => $this->buildRoleRule($autoRole, $candidate['sub_scores']),
                    'suggested_amount_fcfa' => isset($pick['amount_fcfa']) && is_numeric($pick['amount_fcfa']) && $pick['amount_fcfa'] > 0
                        ? round((float) $pick['amount_fcfa']) : null,
                ];
            }

            // Montants manquants : répartition égale de la part de ligne du
            // profil (même règle que l'algorithme) en repli.
            $n = count($selected);
            foreach ($selected as &$candidate) {
                if ($candidate['suggested_amount_fcfa'] === null && $invest !== null && $n > 0) {
                    $candidate['suggested_amount_fcfa'] = round(($targetWeights[$role] / 100 * $invest) / $n);
                }
            }
            unset($candidate);

            if ($n < $slots) {
                $notes[] = "Ligne " . ucfirst($role) . " incomplète (" . $n . "/$slots) dans la proposition IA.";
            }
            $team[$role] = $selected;
        }

        if ($dropped > 0) {
            $notes[] = "$dropped choix de l'IA écarté(s) par les garde-fous serveur (titre hors menu, doublon, ou plus de 2 titres du même secteur).";
        }

        // Banc : mêmes pools, moins tout ce qui a été retenu.
        $bench = [];
        foreach ($pools as $role => $pool) {
            $roleBench = [];
            foreach ($pool as $candidate) {
                if (count($roleBench) >= 4) break;
                if (in_array($candidate['company_id'], $usedIds, true)) continue;
                $candidate['reason'] = $this->buildPickReason($role, $candidate);
                $candidate['role_rule'] = $this->buildRoleRule($role, $candidate['sub_scores']);
                $candidate['suggested_amount_fcfa'] = null;
                $roleBench[] = $candidate;
            }
            $bench[$role] = $roleBench;
        }

        $data = [
            'origin' => 'ia',
            'provider' => $provider,
            'model' => $model,
            'commentary' => trim((string) ($aiData['commentary'] ?? '')),
            'profile' => $profile,
            'target_weights' => $targetWeights,
            'budget_fcfa' => $budget,
            'reserve_fcfa' => $reserve,
            'invest_fcfa' => $invest,
            'formation' => $formation,
            'team' => $team,
            'bench' => $bench,
            'notes' => $notes,
        ];
        $data['id'] = $this->persistTeamProposal($userId, $data);

        return ['success' => true, 'data' => $data];
    }

    private function buildProposeTeamAiPrompt(string $profile, array $targetWeights, ?float $invest, array $formation, array $menu): string {
        $menuJson = json_encode($menu, JSON_UNESCAPED_UNICODE);
        $investLabel = $invest !== null ? number_format($invest, 0, ',', ' ') . " FCFA" : "non précisé";

        return <<<PROMPT
Tu es un coach de portefeuille spécialisé sur la BRVM (Bourse Régionale
des Valeurs Mobilières, Afrique de l'Ouest). Compose la MEILLEURE équipe
possible en 4-3-3 pour un investisseur débutant de profil "$profile" :
exactement 4 titres en Défense (stabilité/rendement), 3 au Milieu (profil
équilibré), 3 en Attaque (recherche de croissance).

Montant à investir (hors réserve de sécurité, déjà mise de côté) :
$investLabel. Répartition cible par ligne : Défense {$targetWeights['defense']}%,
Milieu {$targetWeights['milieu']}%, Attaque {$targetWeights['attaque']}%.

Voici le MENU des seuls titres autorisés (déjà notés 0-100 par
l'application : sous-scores fondamental/technique/momentum/liquidité/rang
sectoriel/vs marché, verdict de valorisation du rapport analysé quand
disponible, et role_auto = le rôle que le classement mécanique leur
donnerait) :
$menuJson

Règles impératives :
- UNIQUEMENT des company_id présents dans le menu. Jamais deux fois le
  même titre.
- Maximum 2 titres du même secteur sur l'ensemble des 10 (diversification).
- Tu peux placer un titre dans une AUTRE ligne que son role_auto si tu le
  justifies solidement — c'est ta valeur ajoutée par rapport au classement
  mécanique, mais ne le fais pas sans raison.
- Pour chaque titre : amount_fcfa (montant entier en FCFA, la somme par
  ligne doit rester proche de la part cible de la ligne) et reason (2-3
  phrases : pourquoi CE titre, à CE poste, pour CE profil — appuie-toi sur
  les chiffres du menu, n'invente aucun chiffre).
- "commentary" : ta lecture d'ensemble en 4-6 phrases (logique de l'équipe,
  équilibres choisis, ce que tu as sciemment écarté et pourquoi).
- Jamais de promesse de gain ni de prédiction de cours.

Réponds UNIQUEMENT avec un objet JSON de cette forme exacte (aucun texte
avant/après, pas de balises markdown) :

{
  "commentary": "...",
  "team": {
    "defense": [{"company_id": 0, "amount_fcfa": 0, "reason": "..."}],
    "milieu": [{"company_id": 0, "amount_fcfa": 0, "reason": "..."}],
    "attaque": [{"company_id": 0, "amount_fcfa": 0, "reason": "..."}]
  }
}
PROMPT;
    }

    private function proposeTeamAiSchema(): array {
        $pickSchema = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['company_id', 'amount_fcfa', 'reason'],
                'properties' => [
                    'company_id' => ['type' => 'integer'],
                    'amount_fcfa' => ['type' => ['number', 'null']],
                    'reason' => ['type' => 'string'],
                ],
            ],
        ];
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['commentary', 'team'],
            'properties' => [
                'commentary' => ['type' => 'string'],
                'team' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['defense', 'milieu', 'attaque'],
                    'properties' => [
                        'defense' => $pickSchema,
                        'milieu' => $pickSchema,
                        'attaque' => $pickSchema,
                    ],
                ],
            ],
        ];
    }

    /**
     * Historique des propositions d'équipe de l'utilisateur courant.
     */
    private function listTeamProposals($input) {
        $userId = $this->requireUserId();

        $rows = $this->crud->executeCustomQuery(
            "SELECT id, origin, provider, model, profile, budget_fcfa, ai_commentary, rating, proposal, created_at
             FROM portfolio_team_proposals
             WHERE admin_user_id = ?
             ORDER BY id DESC",
            [$userId]
        ) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $proposal = json_decode($row['proposal'] ?? 'null', true) ?: [];
            $playersCount = 0;
            foreach (($proposal['team'] ?? []) as $line) {
                $playersCount += count($line);
            }
            $commentary = $row['ai_commentary'] ?? '';
            $result[] = [
                'id' => (int) $row['id'],
                'origin' => $row['origin'],
                'provider' => $row['provider'],
                'model' => $row['model'],
                'profile' => $row['profile'],
                'budget_fcfa' => $row['budget_fcfa'] !== null ? (float) $row['budget_fcfa'] : null,
                'rating' => $row['rating'] !== null ? (int) $row['rating'] : null,
                'players_count' => $playersCount,
                'commentary_excerpt' => mb_strlen($commentary) > 150 ? mb_substr($commentary, 0, 150) . '…' : $commentary,
                'created_at' => $row['created_at'],
            ];
        }

        return ['success' => true, 'data' => ['proposals' => $result, 'count' => count($result)]];
    }

    private function getTeamProposal($input) {
        $userId = $this->requireUserId();
        $proposalId = (int) ($input['id'] ?? 0);
        if (!$proposalId) {
            throw new Exception("id requis");
        }
        $rows = $this->crud->find('portfolio_team_proposals', ['id' => $proposalId, 'admin_user_id' => $userId]);
        if (empty($rows)) {
            throw new Exception("Proposition introuvable (id=$proposalId) pour cet utilisateur");
        }
        $row = $rows[0];
        $proposal = json_decode($row['proposal'] ?? 'null', true) ?: [];
        $proposal['id'] = (int) $row['id'];
        $proposal['rating'] = $row['rating'] !== null ? (int) $row['rating'] : null;
        $proposal['created_at'] = $row['created_at'];

        return ['success' => true, 'data' => $proposal];
    }

    private function rateTeamProposal($input) {
        $userId = $this->requireUserId();
        $proposalId = (int) ($input['id'] ?? 0);
        $rating = (int) ($input['rating'] ?? 0);
        if (!$proposalId || $rating < 1 || $rating > 5) {
            throw new Exception("id et rating (1-5) requis");
        }
        $rows = $this->crud->find('portfolio_team_proposals', ['id' => $proposalId, 'admin_user_id' => $userId]);
        if (empty($rows)) {
            throw new Exception("Proposition introuvable (id=$proposalId) pour cet utilisateur");
        }
        $this->crud->merge('portfolio_team_proposals', ['rating' => $rating], ['id' => $proposalId, 'admin_user_id' => $userId]);

        return ['success' => true, 'data' => ['id' => $proposalId, 'rating' => $rating]];
    }

    private function deleteTeamProposal($input) {
        $userId = $this->requireUserId();
        $proposalId = (int) ($input['id'] ?? 0);
        if (!$proposalId) {
            throw new Exception("id requis");
        }
        $rows = $this->crud->find('portfolio_team_proposals', ['id' => $proposalId, 'admin_user_id' => $userId]);
        if (empty($rows)) {
            throw new Exception("Proposition introuvable (id=$proposalId) pour cet utilisateur");
        }
        $this->crud->remove('portfolio_team_proposals', ['id' => $proposalId, 'admin_user_id' => $userId]);

        return ['success' => true, 'data' => ['id' => $proposalId]];
    }

    /**
     * Explication déterministe du CLASSEMENT dans un rôle — la règle
     * réellement déclenchée, avec les vrais chiffres du titre (jamais un
     * texte générique) : l'utilisateur doit pouvoir vérifier chaque mot
     * contre les sous-scores affichés.
     */
    private function buildRoleRule(string $role, array $s): string {
        $f = $s['fundamental'] !== null ? round($s['fundamental']) : null;
        $m = $s['momentum'] !== null ? round($s['momentum']) : null;
        $mk = $s['market'] !== null ? round($s['market']) : null;

        if ($role === 'defense') {
            $momPart = $m !== null ? "momentum modéré ($m < 60)" : "momentum indisponible";
            return "Classé en Défense : fondamental $f ≥ 60 (stabilité/rendement démontrés par le rapport financier) et $momPart — le profil type du titre qui tient sans chercher le mouvement.";
        }
        if ($role === 'attaque') {
            $triggers = [];
            if ($m !== null && $m >= 65) $triggers[] = "momentum $m ≥ 65 (dynamique de cours récente)";
            if ($mk !== null && $mk >= 65) $triggers[] = "surperformance vs BRVM-COMPOSITE $mk ≥ 65 (monte plus vite que le marché)";
            return "Classé en Attaque : " . implode(' et ', $triggers) . " — le profil du titre qui cherche la croissance, avec le risque de mouvement qui va avec.";
        }
        $fPart = $f !== null ? "fondamental $f" : "fondamental indisponible";
        $mPart = $m !== null ? "momentum $m" : "momentum indisponible";
        return "Classé en Milieu : profil intermédiaire ($fPart, $mPart) — ne remplit ni les critères Défense (fondamental ≥ 60 avec momentum < 60) ni les critères Attaque (momentum ou surperformance ≥ 65).";
    }

    /**
     * Justification déterministe d'un choix — les 2 sous-scores les plus
     * forts du titre, en français lisible. Pas d'IA : chaque mot est
     * traçable jusqu'à un chiffre affichable.
     */
    private function buildPickReason(string $role, array $candidate): string {
        $labels = [
            'fundamental' => 'fondamental',
            'technical' => 'technique',
            'momentum' => 'momentum',
            'liquidity' => 'liquidité',
            'sector' => 'rang sectoriel',
            'market' => 'surperformance vs marché',
        ];
        $available = [];
        foreach ($candidate['sub_scores'] as $key => $value) {
            if ($value !== null) {
                $available[$key] = $value;
            }
        }
        arsort($available);
        $top = array_slice($available, 0, 2, true);

        $parts = [];
        foreach ($top as $key => $value) {
            $parts[] = $labels[$key] . ' ' . round($value);
        }

        $roleLabels = ['defense' => 'Défense', 'milieu' => 'Milieu', 'attaque' => 'Attaque'];
        $reason = "Retenu en " . $roleLabels[$role] . " — points forts : " . implode(', ', $parts) . ".";
        if ($candidate['sub_scores']['fundamental'] === null) {
            $reason .= " Aucun rapport financier traité : classification sur les seules données de marché.";
        } else {
            $reportDate = !empty($candidate['report_date']) ? " du " . $candidate['report_date'] : "";
            $verdict = $candidate['valuation_verdict'] ?? null;
            $reason .= " Adossé à l'analyse du rapport financier$reportDate"
                . ($verdict !== null && $verdict !== '' && $verdict !== 'indéterminable' ? " (jugé $verdict)" : "")
                . ".";
        }
        return $reason;
    }

    /**
     * Bonus de tri lié au verdict de valorisation du rapport analysé —
     * uniquement pour le classement de propose_team, jamais intégré au
     * composite_score affiché (qui doit rester identique partout).
     */
    private function verdictBonus(?string $verdict): float {
        if ($verdict === 'sous-coté') return 5.0;
        if ($verdict === 'surcoté') return -5.0;
        return 0.0;
    }

    /**
     * Adoption en bloc d'une proposition d'équipe : ajoute toutes les
     * positions en mode simulé et pose la réserve du gardien en un seul
     * appel — les entreprises déjà présentes dans l'équipe sont ignorées
     * (comptées dans skipped) plutôt que de faire échouer tout le lot.
     */
    private function adoptTeam($input) {
        $userId = $this->requireUserId();

        $players = $input['players'] ?? [];
        if (!is_array($players) || empty($players)) {
            throw new Exception("players requis (liste de {company_id, target_amount_fcfa})");
        }

        $added = 0;
        $skipped = 0;
        foreach ($players as $player) {
            $companyId = (int) ($player['company_id'] ?? 0);
            if (!$companyId || !$this->crud->findById('companies', $companyId)) {
                $skipped++;
                continue;
            }
            $existing = $this->crud->find('portfolio_holdings', ['admin_user_id' => $userId, 'company_id' => $companyId]);
            if (!empty($existing)) {
                $skipped++;
                continue;
            }
            $this->crud->persist('portfolio_holdings', [
                'admin_user_id' => $userId,
                'company_id' => $companyId,
                'status' => 'simule',
                'target_amount_fcfa' => isset($player['target_amount_fcfa']) && $player['target_amount_fcfa'] !== '' && $player['target_amount_fcfa'] !== null
                    ? (float) $player['target_amount_fcfa'] : null,
            ]);
            $added++;
        }

        if (isset($input['cash_reserve_fcfa']) && $input['cash_reserve_fcfa'] !== '' && (float) $input['cash_reserve_fcfa'] >= 0) {
            $amount = (float) $input['cash_reserve_fcfa'];
            $existing = $this->crud->find('portfolio_cash_reserve', ['admin_user_id' => $userId]);
            if (!empty($existing)) {
                $this->crud->merge('portfolio_cash_reserve', ['amount' => $amount], ['admin_user_id' => $userId]);
            } else {
                $this->crud->persist('portfolio_cash_reserve', ['admin_user_id' => $userId, 'amount' => $amount, 'currency' => 'FCFA']);
            }
        }

        return ['success' => true, 'data' => ['added' => $added, 'skipped' => $skipped]];
    }

    /**
     * Avis IA sur l'équipe + propositions structurées et ACTIONNABLES —
     * chaque proposition est validée côté serveur (ajout uniquement depuis
     * le menu de candidats fourni, retrait/ajustement uniquement sur une
     * position réellement détenue) puis soumise à validation humaine côté
     * frontend avant toute application : l'IA propose, l'utilisateur
     * décide, et elle ne peut jamais inventer un titre. Pas de cache : la
     * composition change à chaque proposition appliquée, un avis mémorisé
     * serait périmé dès la première validation.
     */
    private function aiReview($input) {
        $userId = $this->requireUserId();

        $provider = $input['provider'] ?? self::AI_DEFAULT_PROVIDER;
        if (!isset(self::AI_PROVIDERS[$provider])) {
            throw new Exception("Fournisseur IA inconnu: $provider. Disponibles: " . implode(', ', array_keys(self::AI_PROVIDERS)));
        }
        $model = !empty($input['model']) ? $input['model'] : self::AI_PROVIDERS[$provider]['default_model'];

        $team = $this->team([])['data'];
        if (empty($team['holdings'])) {
            throw new Exception("L'équipe est vide — compose d'abord une équipe (ou adopte une proposition) avant de demander un avis.");
        }

        // Menu de candidats pour les propositions d'AJOUT — l'IA ne peut
        // proposer d'ajouter QUE ces titres-là (validé plus bas).
        $heldIds = array_map(fn($h) => (int) $h['company_id'], $team['holdings']);
        $pools = $this->buildScoredPools($heldIds);
        $menu = [];
        foreach ($pools as $role => $pool) {
            foreach (array_slice($pool, 0, 5) as $c) {
                $menu[] = [
                    'company_id' => $c['company_id'],
                    'symbol' => $c['symbol'],
                    'name' => $c['name'],
                    'sector' => $c['sector'],
                    'role_auto' => $role,
                    'composite_score' => $c['composite_score'],
                    'coverage_percent' => $c['coverage_percent'],
                    'valuation_verdict' => $c['valuation_verdict'],
                ];
            }
        }

        $prompt = $this->buildAiReviewPrompt($team, $menu);
        $clientClass = self::AI_PROVIDERS[$provider]['class'];
        $client = new $clientClass();
        $aiResult = $client->generateContent($prompt, $model, $this->aiReviewSchema());

        if (!$aiResult['success']) {
            // Erreur fournisseur IA/réseau/config : pas un crash serveur.
            return ['success' => false, 'message' => $aiResult['error']];
        }

        $data = $aiResult['data'];
        $validation = $this->validateAiProposals($data['proposals'] ?? [], $team, $menu);

        // Historisation : l'avis est conservé AVEC la composition de
        // l'équipe au moment de l'analyse — indispensable pour l'interpréter
        // plus tard, l'équipe ayant pu changer depuis (y compris via
        // l'application des propositions de cet avis lui-même).
        $reviewId = $this->crud->persist('portfolio_ai_reviews', [
            'admin_user_id' => $userId,
            'provider' => $provider,
            'model' => $model,
            'team_snapshot' => json_encode($team, JSON_UNESCAPED_UNICODE),
            'overall_opinion' => $data['overall_opinion'] ?? '',
            'strengths' => json_encode($data['strengths'] ?? [], JSON_UNESCAPED_UNICODE),
            'weaknesses' => json_encode($data['weaknesses'] ?? [], JSON_UNESCAPED_UNICODE),
            'proposals' => json_encode($validation['valid'], JSON_UNESCAPED_UNICODE),
            'dropped_proposals_count' => $validation['dropped'],
        ]);

        return [
            'success' => true,
            'data' => [
                'id' => (int) $reviewId,
                'created_at' => date('Y-m-d H:i:s'),
                'provider' => $provider,
                'model' => $model,
                'overall_opinion' => $data['overall_opinion'] ?? '',
                'strengths' => $data['strengths'] ?? [],
                'weaknesses' => $data['weaknesses'] ?? [],
                'proposals' => $validation['valid'],
                'dropped_proposals_count' => $validation['dropped'],
                'disclaimer' => self::AI_DISCLAIMER,
            ],
        ];
    }

    /**
     * Historique des avis du coach IA de l'utilisateur courant — résumés
     * seulement (l'avis complet + snapshot se chargent via get_review).
     */
    private function listReviews($input) {
        $userId = $this->requireUserId();

        $rows = $this->crud->executeCustomQuery(
            "SELECT id, provider, model, overall_opinion, proposals, team_snapshot, created_at
             FROM portfolio_ai_reviews
             WHERE admin_user_id = ?
             ORDER BY id DESC",
            [$userId]
        ) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $proposals = json_decode($row['proposals'] ?? '[]', true) ?: [];
            $snapshot = json_decode($row['team_snapshot'] ?? 'null', true) ?: [];
            $opinion = $row['overall_opinion'] ?? '';
            $result[] = [
                'id' => (int) $row['id'],
                'provider' => $row['provider'],
                'model' => $row['model'],
                'created_at' => $row['created_at'],
                'proposals_count' => count($proposals),
                'balance_score_at_review' => isset($snapshot['balance_score']) ? (int) $snapshot['balance_score'] : null,
                'holdings_count_at_review' => isset($snapshot['holdings']) ? count($snapshot['holdings']) : null,
                'opinion_excerpt' => mb_strlen($opinion) > 180 ? mb_substr($opinion, 0, 180) . '…' : $opinion,
            ];
        }

        return ['success' => true, 'data' => ['reviews' => $result, 'count' => count($result)]];
    }

    /**
     * Un avis historisé complet, avec le snapshot d'équipe de l'époque —
     * lecture seule (les propositions d'un avis passé ne sont jamais
     * ré-applicables : elles portaient sur une composition révolue).
     */
    private function getReview($input) {
        $userId = $this->requireUserId();
        $reviewId = (int) ($input['id'] ?? 0);
        if (!$reviewId) {
            throw new Exception("id requis");
        }

        $rows = $this->crud->find('portfolio_ai_reviews', ['id' => $reviewId, 'admin_user_id' => $userId]);
        if (empty($rows)) {
            throw new Exception("Avis introuvable (id=$reviewId) pour cet utilisateur");
        }
        $row = $rows[0];

        return [
            'success' => true,
            'data' => [
                'id' => (int) $row['id'],
                'provider' => $row['provider'],
                'model' => $row['model'],
                'created_at' => $row['created_at'],
                'overall_opinion' => $row['overall_opinion'],
                'strengths' => json_decode($row['strengths'] ?? '[]', true) ?: [],
                'weaknesses' => json_decode($row['weaknesses'] ?? '[]', true) ?: [],
                'proposals' => json_decode($row['proposals'] ?? '[]', true) ?: [],
                'dropped_proposals_count' => (int) $row['dropped_proposals_count'],
                'team_snapshot' => json_decode($row['team_snapshot'] ?? 'null', true),
                'disclaimer' => self::AI_DISCLAIMER,
            ],
        ];
    }

    private function deleteReview($input) {
        $userId = $this->requireUserId();
        $reviewId = (int) ($input['id'] ?? 0);
        if (!$reviewId) {
            throw new Exception("id requis");
        }
        $rows = $this->crud->find('portfolio_ai_reviews', ['id' => $reviewId, 'admin_user_id' => $userId]);
        if (empty($rows)) {
            throw new Exception("Avis introuvable (id=$reviewId) pour cet utilisateur");
        }
        $this->crud->remove('portfolio_ai_reviews', ['id' => $reviewId, 'admin_user_id' => $userId]);

        return ['success' => true, 'data' => ['id' => $reviewId]];
    }

    private function buildAiReviewPrompt(array $team, array $menu): string {
        $teamJson = json_encode([
            'lignes' => $team['lines'],
            'valeur_totale_fcfa' => $team['total_portfolio_value_fcfa'],
            'reserve_gardien_fcfa' => $team['cash_reserve']['amount'],
            'score_equilibre' => $team['balance_score'],
            'alertes' => $team['alerts'],
            'positions' => array_map(fn($h) => [
                'holding_id' => $h['id'],
                'symbol' => $h['symbol'],
                'name' => $h['name'],
                'secteur' => $h['sector'],
                'role' => $h['role'],
                'statut' => $h['status'],
                'valeur_fcfa' => $h['position_value_fcfa'],
                'score_composite' => $h['composite_score'],
                'couverture_percent' => $h['coverage_percent'],
                'sous_scores' => $h['sub_scores'],
                'these_du_coach' => $h['thesis'],
            ], $team['holdings']),
        ], JSON_UNESCAPED_UNICODE);

        $menuJson = json_encode($menu, JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
Tu es un coach de portefeuille spécialisé sur la BRVM (Bourse Régionale des
Valeurs Mobilières, Afrique de l'Ouest). L'application "Mon Équipe BRVM"
modélise le portefeuille d'un investisseur DÉBUTANT comme une équipe de
football en 4-3-3 : Défense (valeurs stables/rendement), Milieu (profil
équilibré), Attaque (recherche de croissance), et un Gardien (réserve de
liquidité hors marché). Les scores 0-100 sont des synthèses mécaniques de
données passées (fondamental extrait des rapports financiers analysés,
technique, momentum, liquidité, rang sectoriel, surperformance vs
BRVM-COMPOSITE).

Voici l'équipe actuelle de l'utilisateur (JSON) :
$teamJson

Voici le MENU des seuls titres que tu as le droit de proposer d'AJOUTER
(candidats déjà notés et diversifiés, hors titres déjà détenus) :
$menuJson

Ta mission :
1. Donne un avis global honnête et pédagogique sur cette équipe (équilibre
   des lignes, diversification sectorielle, taille de la réserve, qualité
   des scores, couverture des données).
2. Liste ses forces et ses faiblesses concrètes, chiffres à l'appui.
3. Propose 0 à 5 changements CONCRETS maximum, chacun avec sa raison.

Règles impératives sur les propositions :
- "ajouter" : UNIQUEMENT un titre du MENU ci-dessus (recopie exactement son
  company_id et son symbol). Jamais un titre absent du menu.
- "retirer" ou "ajuster_montant" : UNIQUEMENT une position existante
  (recopie exactement son holding_id et son symbol). Si la position a une
  "these_du_coach", ta raison doit y répondre explicitement — on ne
  contredit pas le plan de jeu écrit du coach sans s'y confronter.
- "ajuster_reserve" : propose un nouveau montant de réserve gardien en FCFA
  (amount_fcfa), par exemple pour viser au moins 10% du total.
- Ne propose un changement QUE s'il améliore réellement l'équilibre ou la
  qualité de l'équipe — une liste vide est une réponse valable si l'équipe
  est déjà bien construite.
- Jamais de promesse de gain ni de prédiction de cours : raisonne
  uniquement sur l'équilibre, la diversification et les données fournies.
- Reste accessible : l'utilisateur est débutant, explique sans jargon.

Réponds UNIQUEMENT avec un objet JSON de cette forme exacte (aucun texte
avant/après, pas de balises markdown) :

{
  "overall_opinion": "avis global en 3-6 phrases, pédagogique et chiffré",
  "strengths": ["force concrète 1", "..."],
  "weaknesses": ["faiblesse concrète 1", "..."],
  "proposals": [
    {
      "action": "ajouter | retirer | ajuster_montant | ajuster_reserve",
      "company_id": nombre (requis pour ajouter, sinon null),
      "holding_id": nombre (requis pour retirer/ajuster_montant, sinon null),
      "symbol": "symbole du titre concerné, ou null pour ajuster_reserve",
      "amount_fcfa": nombre (montant proposé : cible pour ajouter/ajuster_montant, nouveau montant de réserve pour ajuster_reserve, null pour retirer),
      "rationale": "pourquoi ce changement précis, en une ou deux phrases simples"
    }
  ]
}
PROMPT;
    }

    /**
     * Schéma JSON structuré (utilisé par AnthropicClient ; ignoré par
     * GeminiClient/GrokClient qui se fient au texte du prompt).
     */
    private function aiReviewSchema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['overall_opinion', 'strengths', 'weaknesses', 'proposals'],
            'properties' => [
                'overall_opinion' => ['type' => 'string'],
                'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
                'weaknesses' => ['type' => 'array', 'items' => ['type' => 'string']],
                'proposals' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['action', 'company_id', 'holding_id', 'symbol', 'amount_fcfa', 'rationale'],
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['ajouter', 'retirer', 'ajuster_montant', 'ajuster_reserve']],
                            'company_id' => ['type' => ['integer', 'null']],
                            'holding_id' => ['type' => ['integer', 'null']],
                            'symbol' => ['type' => ['string', 'null']],
                            'amount_fcfa' => ['type' => ['number', 'null']],
                            'rationale' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Garde-fou serveur : une proposition IA qui référence un titre hors
     * menu ou une position inexistante est écartée (comptée dans dropped),
     * jamais transmise au frontend comme actionnable.
     */
    private function validateAiProposals(array $proposals, array $team, array $menu): array {
        $menuById = [];
        foreach ($menu as $m) {
            $menuById[(int) $m['company_id']] = $m;
        }
        $holdingsById = [];
        foreach ($team['holdings'] as $h) {
            $holdingsById[(int) $h['id']] = $h;
        }

        $valid = [];
        $dropped = 0;
        foreach ($proposals as $p) {
            $action = $p['action'] ?? '';
            $companyId = isset($p['company_id']) && $p['company_id'] !== null ? (int) $p['company_id'] : null;
            $holdingId = isset($p['holding_id']) && $p['holding_id'] !== null ? (int) $p['holding_id'] : null;
            $amount = isset($p['amount_fcfa']) && $p['amount_fcfa'] !== null && is_numeric($p['amount_fcfa']) ? (float) $p['amount_fcfa'] : null;

            $ok = false;
            if ($action === 'ajouter' && $companyId !== null && isset($menuById[$companyId])) {
                // Symbole re-normalisé depuis le menu (jamais celui de l'IA).
                $p['symbol'] = $menuById[$companyId]['symbol'];
                $ok = true;
            } elseif (($action === 'retirer' || $action === 'ajuster_montant') && $holdingId !== null && isset($holdingsById[$holdingId])) {
                $p['symbol'] = $holdingsById[$holdingId]['symbol'];
                $ok = ($action === 'retirer') || ($amount !== null && $amount >= 0);
            } elseif ($action === 'ajuster_reserve') {
                $ok = $amount !== null && $amount >= 0;
            }

            if ($ok) {
                $p['company_id'] = $companyId;
                $p['holding_id'] = $holdingId;
                $p['amount_fcfa'] = $amount;
                $valid[] = $p;
            } else {
                $dropped++;
            }
        }

        return ['valid' => $valid, 'dropped' => $dropped];
    }

    /**
     * Performance de cours par entreprise sur la fenêtre (première vs
     * dernière clôture) — même calcul qu'api_composite_score.php.
     */
    private function getPerformanceByCompany(array $companyIds, string $startDate, string $endDate): array {
        if (empty($companyIds)) return [];
        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));
        $sql = "
            SELECT company_id, trading_date, close_price
            FROM stock_quotes
            WHERE company_id IN ($placeholders)
            AND trading_date >= ? AND trading_date <= ?
            ORDER BY company_id ASC, trading_date ASC
        ";
        $rows = $this->crud->executeCustomQuery($sql, array_merge($companyIds, [$startDate, $endDate])) ?: [];

        $firstClose = [];
        $lastClose = [];
        foreach ($rows as $row) {
            $cid = (int) $row['company_id'];
            if (!isset($firstClose[$cid])) {
                $firstClose[$cid] = (float) $row['close_price'];
            }
            $lastClose[$cid] = (float) $row['close_price'];
        }
        $result = [];
        foreach ($firstClose as $cid => $first) {
            if ($first > 0) {
                $result[$cid] = round((($lastClose[$cid] - $first) / $first) * 100, 2);
            }
        }
        return $result;
    }

    /**
     * Rendement net de BRVM-COMPOSITE sur la fenêtre — copie
     * d'api_composite_score.php::getBenchmarkReturn() (convention de
     * duplication des petites requêtes).
     */
    private function getBenchmarkReturn(string $startDate, string $endDate): ?float {
        $sql = "
            SELECT iv.close_value
            FROM index_values iv
            INNER JOIN market_indices mi ON mi.id = iv.index_id
            WHERE mi.code = 'BRVM-COMPOSITE'
            AND iv.trading_date >= ? AND iv.trading_date <= ?
            ORDER BY iv.trading_date ASC
        ";
        $rows = $this->crud->executeCustomQuery($sql, [$startDate, $endDate]) ?: [];
        if (count($rows) < 2) return null;

        $first = (float) $rows[0]['close_value'];
        $last = (float) end($rows)['close_value'];
        if ($first <= 0) return null;

        return round((($last - $first) / $first) * 100, 2);
    }

    /**
     * Dernier rapport financier traité avec succès par entreprise — copie
     * d'api_composite_score.php::getFundamentalsByCompany().
     */
    private function getFundamentalsByCompany(): array {
        $sql = "
            SELECT cra.company_id, cra.details, cr.publish_date
            FROM company_report_analyses cra
            INNER JOIN company_reports cr ON cr.id = cra.report_id
            INNER JOIN companies c ON c.id = cra.company_id
            WHERE cra.status = 'success'
            AND c.active = 1
            ORDER BY cra.company_id ASC, cr.publish_date DESC
        ";
        $rows = $this->crud->executeCustomQuery($sql) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $cid = (int) $row['company_id'];
            if (isset($result[$cid])) continue;

            $details = json_decode($row['details'] ?? 'null', true) ?: [];
            $financials = $details['key_financials'] ?? [];
            $valuation = $details['valuation_assessment'] ?? [];

            $result[$cid] = [
                'pe_ratio' => $this->toFloatOrNull($valuation['pe_ratio'] ?? null),
                'dividend_yield_percent' => $this->toFloatOrNull($valuation['dividend_yield_percent'] ?? null),
                'roe_percent' => $this->toFloatOrNull($financials['roe_percent'] ?? null),
                'revenue_growth_percent' => $this->toFloatOrNull($financials['revenue_growth_percent'] ?? null),
                'net_margin_percent' => $this->toFloatOrNull($financials['net_margin_percent'] ?? null),
                // Verdict de valorisation de l'analyse IA du rapport (sous-coté/
                // surcoté/correctement valorisé/indéterminable) — utilisé par
                // propose_team pour ajuster le classement, pas par le score.
                'valuation_verdict' => $valuation['verdict'] ?? null,
                'source_publish_date' => $row['publish_date'],
            ];
        }

        return $result;
    }

    private function toFloatOrNull($value): ?float {
        if ($value === null || $value === '') return null;
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Classement de liquidité par entreprise — copie
     * d'api_composite_score.php::getLiquidityByCompany() (mêmes seuils que
     * api_quotes.php::getLiquidity()).
     */
    private function getLiquidityByCompany() {
        $days = 30;
        $endDate = date('Y-m-d');

        $sql = "
            SELECT
                c.id AS company_id,
                AVG(sq.volume) AS avg_volume,
                SUM(CASE WHEN sq.volume = 0 OR sq.volume IS NULL THEN 1 ELSE 0 END) AS zero_volume_days,
                COUNT(*) AS total_days
            FROM stock_quotes sq
            INNER JOIN companies c ON c.id = sq.company_id
            WHERE sq.trading_date >= DATE_SUB(?, INTERVAL ? DAY)
            AND sq.trading_date <= ?
            AND c.active = 1
            GROUP BY c.id
        ";
        $rows = $this->crud->executeCustomQuery($sql, [$endDate, $days, $endDate]) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $avgVolume = (float) $row['avg_volume'];
            $totalDays = (int) $row['total_days'];
            $zeroDays = (int) $row['zero_volume_days'];
            $zeroRatio = $totalDays > 0 ? $zeroDays / $totalDays : 0;

            if ($zeroRatio > 0.3) {
                $label = 'Illiquide';
            } elseif ($avgVolume < 200) {
                $label = 'Faible';
            } elseif ($avgVolume < 2000) {
                $label = 'Moyenne';
            } else {
                $label = 'Élevée';
            }

            $result[(int) $row['company_id']] = $label;
        }

        return $result;
    }
}

// Exécution
$api = new PortfolioAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
