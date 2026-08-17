<?php
/**
 * API des états financiers saisis manuellement, tous formats
 * Endpoint: api_financial_statements.php — voir migration 023 et
 * class/FinancialStatementSchemas.php.
 *
 * Les émetteurs BRVM publient des états de structures très différentes
 * (compte de résultat commercial SYSCOHADA, compte de résultat bancaire,
 * bilan bancaire, flux de trésorerie, tableau d'activité trimestriel), avec
 * des conventions de signe opposées. Le stockage est donc générique (une
 * ligne = un poste) et chaque format vit dans le registre PHP, avec ses
 * formules de sous-totaux déclarées en coefficients.
 *
 * Aucun sous-total n'est stocké : tous sont recalculés à la lecture.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once 'class/DbConnect.php';
require_once 'class/DynamiqueCrud.php';
require_once 'class/FinancialStatementSchemas.php';
require_once 'class/AuthGuard.php';
AuthGuard::requireAuth();

class FinancialStatementsAPI {
    private const PERIOD_TYPES = ['annuel', 'semestriel', 'trimestriel'];
    private const UNITS = [1, 1000, 1000000];

    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['action'])) {
            return ['success' => false, 'message' => 'Action manquante'];
        }

        try {
            switch ($input['action']) {
                case 'types':
                    return ['success' => true, 'data' => [
                        'types' => FinancialStatementSchemas::summaries(),
                        'period_types' => self::PERIOD_TYPES,
                        'units' => [
                            ['value' => 1, 'label' => 'Francs CFA (tels quels)'],
                            ['value' => 1000, 'label' => 'Milliers de FCFA'],
                            ['value' => 1000000, 'label' => 'Millions de FCFA'],
                        ],
                    ]];
                case 'schema':
                    return $this->schema($input);
                case 'list':
                    return $this->listStatements($input);
                case 'get':
                    return $this->getStatement($input);
                case 'save':
                    return $this->save($input);
                case 'set_active':
                    return $this->setActive($input);
                case 'delete':
                    return $this->delete($input);
                case 'series':
                    return $this->series($input);
                default:
                    return ['success' => false, 'message' => 'Action inconnue: ' . $input['action']];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** Structure complète d'un format : groupes, postes, sous-totaux. */
    private function schema($input) {
        $type = $input['statement_type'] ?? '';
        $schema = FinancialStatementSchemas::get($type);
        $schema['key'] = $type;
        return ['success' => true, 'data' => $schema];
    }

    /** Postes saisis d'un état, indexés par clé. */
    private function loadLines($statementId) {
        $rows = $this->crud->executeCustomQuery(
            'SELECT line_key, value FROM financial_statement_lines WHERE statement_id = ?',
            [$statementId]
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[$r['line_key']] = (float) $r['value'];
        }
        return $out;
    }

    /**
     * Enrichit un état : conversion d'unité, sous-totaux, ratios de marché.
     * Les montants sont renvoyés convertis en francs — la seule échelle dans
     * laquelle les périodes sont comparables entre elles.
     */
    private function decorate(array $row) {
        $multiplier = (float) $row['unit_multiplier'];
        $raw = $this->loadLines((int) $row['id']);

        $values = [];
        foreach ($raw as $key => $value) {
            $values[$key] = round($value * $multiplier, 2);
        }

        $type = $row['statement_type'];
        $schema = FinancialStatementSchemas::get($type);
        $subtotals = FinancialStatementSchemas::computeSubtotals($type, $values);

        return [
            'id' => (int) $row['id'],
            'company_id' => (int) $row['company_id'],
            'symbol' => $row['symbol'] ?? null,
            'company_name' => $row['company_name'] ?? null,
            'statement_type' => $type,
            'statement_label' => $schema['label'],
            'period_end_date' => $row['period_end_date'],
            'period_type' => $row['period_type'],
            'fiscal_year' => (int) $row['fiscal_year'],
            'currency' => $row['currency'],
            'unit_multiplier' => (int) $multiplier,
            'is_active' => (int) $row['is_active'] === 1,
            'deactivated_reason' => $row['deactivated_reason'],
            'source_report_id' => $row['source_report_id'] !== null ? (int) $row['source_report_id'] : null,
            'source_note' => $row['source_note'],
            'raw_values' => $raw,
            'values' => $values,
            'subtotals' => $subtotals,
            'subtotal_labels' => array_column($schema['subtotals'], 'label', 'key'),
            'headline' => $schema['headline'],
            'ratios' => $this->computeRatios($schema, $subtotals, $values, (int) $row['company_id'], $row['period_end_date']),
            'lines_filled' => count($raw),
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * Marges et ratios de marché, adaptés au format : un bilan n'a pas de
     * marge d'exploitation, un compte de résultat bancaire a un PNB et non un
     * chiffre d'affaires. Ce qui n'est pas calculable est annoncé comme tel,
     * jamais deviné.
     */
    private function computeRatios(array $schema, array $subtotals, array $values, $companyId, $periodEnd) {
        $get = function ($key) use ($subtotals, $values) {
            if (array_key_exists($key, $subtotals)) {
                return $subtotals[$key];
            }
            return isset($values[$key]) ? $values[$key] : null;
        };

        // Base de revenu selon le format ET selon ce qui a été réellement
        // saisi : chiffre d'affaires pour une entreprise commerciale,
        // produit net bancaire pour une banque. Le libellé suit la source
        // retenue — afficher « chiffre d'affaires » devant un produit net
        // bancaire induirait en erreur sur la nature du chiffre.
        $revenueLabel = "Chiffre d'affaires";
        $revenue = $get('chiffre_affaires');
        if ($revenue === null || $revenue == 0.0) {
            $bankRevenue = $get('produit_net_bancaire');
            if ($bankRevenue !== null && $bankRevenue != 0.0) {
                $revenue = $bankRevenue;
                $revenueLabel = 'Produit net bancaire';
            }
        }
        // Format « chiffres clés » : le revenu passe par un sous-total unique
        // qui agrège les deux lignes ; on regarde donc quelle ligne est
        // remplie pour nommer correctement le chiffre.
        if (($revenue === null || $revenue == 0.0) && array_key_exists('revenu', $subtotals)) {
            $revenue = $subtotals['revenu'];
        }
        if (!empty($values['produit_net_bancaire'])) {
            $revenueLabel = 'Produit net bancaire';
        }
        $netIncome = $get('resultat_net');
        if ($netIncome === null) {
            $netIncome = $get('resultat_exercice');
        }
        $equity = $get('total_equity');
        if ($equity === null) {
            $equity = $get('capitaux_propres');
        }
        $assets = $get('total_assets');
        if ($assets === null) {
            $assets = $get('total_actif');
        }

        $company = $this->crud->executeCustomQuery(
            'SELECT shares_outstanding FROM companies WHERE id = ?', [$companyId]
        );
        $shares = (!empty($company) && $company[0]['shares_outstanding'] !== null)
            ? (float) $company[0]['shares_outstanding'] : null;

        // Cours retenu : la dernière clôture connue à la date de fin de
        // période, jamais le cours du jour — un PER d'exercice 2023 rapporté
        // au cours de 2026 ne voudrait rien dire.
        $quote = $this->crud->executeCustomQuery(
            "SELECT close_price, trading_date FROM stock_quotes
             WHERE company_id = ? AND trading_date <= ? AND close_price > 0
             ORDER BY trading_date DESC LIMIT 1",
            [$companyId, $periodEnd]
        );
        if (empty($quote)) {
            $quote = $this->crud->executeCustomQuery(
                "SELECT close_price, trading_date FROM stock_quotes
                 WHERE company_id = ? AND close_price > 0 ORDER BY trading_date ASC LIMIT 1",
                [$companyId]
            );
        }
        $price = !empty($quote) ? (float) $quote[0]['close_price'] : null;

        $eps = ($shares !== null && $shares > 0 && $netIncome !== null) ? $netIncome / $shares : null;
        $bvps = ($shares !== null && $shares > 0 && $equity !== null) ? $equity / $shares : null;

        // Un état de dividendes n'a ni PER ni PBR ni marge : y afficher les
        // raisons de non-calcul de ces ratios ne ferait que du bruit.
        $isDividend = array_key_exists('dividende_par_action', $subtotals);

        $reasons = [];
        if ($shares === null || $shares <= 0) {
            $reasons[] = "Nombre d'actions inconnu pour cette entreprise.";
        }
        if ($price === null && !$isDividend) {
            $reasons[] = 'Aucun cours de bourse disponible pour cette entreprise.';
        }
        if (!$isDividend) {
            if ($equity === null) {
                $reasons[] = 'Capitaux propres non renseignés : PBR et ROE non calculables.';
            }
            if ($netIncome === null) {
                $reasons[] = "Ce format ne publie pas de résultat net : PER et marges non applicables.";
            } elseif ($eps !== null && $eps <= 0) {
                $reasons[] = "Résultat net négatif ou nul : le PER n'a pas de sens et n'est pas affiché.";
            }
        }

        $pct = function ($value) use ($revenue) {
            return ($revenue !== null && $revenue != 0.0 && $value !== null)
                ? round($value / $revenue * 100, 2) : null;
        };

        // --- Ratios propres aux dividendes -------------------------------
        // Nuls pour les autres formats : un compte de résultat ne porte pas
        // de dividende, et afficher 0 laisserait croire à une absence de
        // distribution plutôt qu'à une donnée hors sujet.
        $dpaBrut = isset($values['dividende_brut_par_action']) ? (float) $values['dividende_brut_par_action'] : null;
        $dpaNet = isset($values['dividende_net_par_action']) ? (float) $values['dividende_net_par_action'] : null;
        $dpa = $dpaBrut !== null ? $dpaBrut : $dpaNet;
        // Cours retenu : celui saisi s'il l'a été (l'émetteur communique
        // parfois un cours de référence), sinon le cours de bourse à la date.
        $dividendPrice = !empty($values['cours_reference']) ? (float) $values['cours_reference'] : $price;
        $sharesPaid = !empty($values['nombre_actions_remunerees'])
            ? (float) $values['nombre_actions_remunerees'] : $shares;
        $totalPaid = isset($values['dividende_total_verse']) ? (float) $values['dividende_total_verse'] : null;
        if ($totalPaid === null && $dpa !== null && $sharesPaid !== null && $sharesPaid > 0) {
            $totalPaid = $dpa * $sharesPaid;
        }
        $resultForPayout = isset($values['resultat_net_exercice']) && $values['resultat_net_exercice'] !== null
            ? (float) $values['resultat_net_exercice'] : $netIncome;

        $dividendRatios = [
            'dividend_per_share' => $dpa,
            'dividend_per_share_net' => $dpaNet,
            'dividend_price' => $dividendPrice,
            'total_paid' => $totalPaid !== null ? round($totalPaid, 2) : null,
            'total_paid_estimated' => empty($values['dividende_total_verse']) && $totalPaid !== null,
            // Rendement : ce que le dividende rapporte au prix de l'action.
            'yield_percent' => ($dpa !== null && $dividendPrice !== null && $dividendPrice > 0)
                ? round($dpa / $dividendPrice * 100, 2) : null,
            // Taux de distribution : part du bénéfice reversée. Sans résultat
            // net, il n'est pas calculé — un dividende peut être prélevé sur
            // les réserves, le deviner serait faux.
            'payout_percent' => ($totalPaid !== null && $resultForPayout !== null && $resultForPayout > 0)
                ? round($totalPaid / $resultForPayout * 100, 2) : null,
        ];
        $dividendRatios['payout_exceeds_profit'] = $dividendRatios['payout_percent'] !== null
            && $dividendRatios['payout_percent'] > 100;

        if ($dpa !== null) {
            if ($dividendPrice === null) {
                $reasons[] = "Aucun cours disponible à cette date : rendement non calculable.";
            }
            if ($dividendRatios['payout_percent'] === null) {
                $reasons[] = "Résultat net de l'exercice non renseigné (ou négatif) : taux de distribution non calculé.";
            } elseif ($dividendRatios['payout_exceeds_profit']) {
                // Un taux au-dessus de 100 % n'est pas une erreur de saisie :
                // l'entreprise puise dans ses réserves. C'est soutenable une
                // année, rarement plusieurs — d'où le signalement.
                $reasons[] = "Taux de distribution supérieur à 100 % : l'entreprise a distribué plus que son bénéfice de l'exercice, en puisant dans ses réserves. Vérifiez que le résultat net saisi correspond bien à l'exercice rémunéré.";
            }
        }

        return array_merge($dividendRatios, [
            'revenue_base' => $revenue,
            'revenue_label' => $revenueLabel,
            'net_income' => $netIncome,
            'shares_outstanding' => $shares,
            'price' => $price,
            'price_date' => !empty($quote) ? $quote[0]['trading_date'] : null,
            'eps' => $eps !== null ? round($eps, 4) : null,
            'book_value_per_share' => $bvps !== null ? round($bvps, 4) : null,
            'per' => ($price !== null && $eps !== null && $eps > 0) ? round($price / $eps, 2) : null,
            'pbr' => ($price !== null && $bvps !== null && $bvps > 0) ? round($price / $bvps, 2) : null,
            'market_cap' => ($price !== null && $shares !== null) ? round($price * $shares, 2) : null,
            'marge_nette_percent' => $pct($netIncome),
            'marge_exploitation_percent' => $pct($get('resultat_exploitation')),
            'roe_percent' => ($equity !== null && $equity != 0.0 && $netIncome !== null)
                ? round($netIncome / $equity * 100, 2) : null,
            'roa_percent' => ($assets !== null && $assets != 0.0 && $netIncome !== null)
                ? round($netIncome / $assets * 100, 2) : null,
            'not_computable_reasons' => $reasons,
        ]);
    }

    private function baseSelect() {
        return "SELECT s.*, c.symbol, c.name AS company_name
                FROM financial_statements s
                JOIN companies c ON c.id = s.company_id";
    }

    private function listStatements($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if ($companyId <= 0) {
            throw new Exception('company_id requis');
        }
        $sql = $this->baseSelect() . ' WHERE s.company_id = ?';
        $params = [$companyId];

        if (!empty($input['statement_type'])) {
            $sql .= ' AND s.statement_type = ?';
            $params[] = $input['statement_type'];
        }
        // Par défaut on renvoie TOUT, actifs et désactivés : l'écran de
        // gestion doit pouvoir réactiver ce qui a été mis de côté.
        if (!empty($input['only_active'])) {
            $sql .= ' AND s.is_active = 1';
        }
        $sql .= ' ORDER BY s.statement_type, s.period_end_date DESC';

        $rows = $this->crud->executeCustomQuery($sql, $params) ?: [];
        $statements = array_map([$this, 'decorate'], $rows);

        // Regroupement par format : c'est la « compartimentation » attendue
        // à l'écran, un compte de résultat et un bilan ne se lisent pas
        // ensemble.
        $byType = [];
        foreach ($statements as $s) {
            $byType[$s['statement_type']][] = $s;
        }
        $groups = [];
        foreach (FinancialStatementSchemas::summaries() as $summary) {
            $items = $byType[$summary['key']] ?? [];
            $groups[] = [
                'type' => $summary['key'],
                'label' => $summary['label'],
                'description' => $summary['description'],
                'count' => count($items),
                'active_count' => count(array_filter($items, function ($i) { return $i['is_active']; })),
                'statements' => $items,
            ];
        }

        return ['success' => true, 'data' => [
            'groups' => $groups,
            'total' => count($statements),
        ]];
    }

    private function getStatement($input) {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('id requis');
        }
        $rows = $this->crud->executeCustomQuery($this->baseSelect() . ' WHERE s.id = ?', [$id]) ?: [];
        if (empty($rows)) {
            throw new Exception("État financier introuvable (id=$id)");
        }
        return ['success' => true, 'data' => $this->decorate($rows[0])];
    }

    /**
     * Création ou mise à jour. Clé métier : (entreprise, format, date de
     * clôture, type de période) — ressaisir la même période met à jour au
     * lieu de créer un doublon silencieux.
     */
    private function save($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if ($companyId <= 0) {
            throw new Exception('company_id requis');
        }
        $type = $input['statement_type'] ?? '';
        $schema = FinancialStatementSchemas::get($type); // lève si inconnu

        $periodEnd = $input['period_end_date'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)) {
            throw new Exception('period_end_date requise au format AAAA-MM-JJ');
        }
        $periodType = $input['period_type'] ?? 'annuel';
        if (!in_array($periodType, self::PERIOD_TYPES, true)) {
            throw new Exception('period_type invalide (annuel, semestriel ou trimestriel)');
        }
        $unit = (int) ($input['unit_multiplier'] ?? 1);
        if (!in_array($unit, self::UNITS, true)) {
            throw new Exception('unit_multiplier invalide (1, 1000 ou 1000000)');
        }
        $company = $this->crud->executeCustomQuery('SELECT id FROM companies WHERE id = ?', [$companyId]);
        if (empty($company)) {
            throw new Exception("Entreprise introuvable (id=$companyId)");
        }

        // Seuls les postes déclarés par le format sont acceptés : une clé
        // inconnue viendrait d'un bug frontend, l'accepter créerait une
        // donnée que plus rien ne saurait relire.
        $allowed = [];
        foreach ($schema['groups'] as $group) {
            foreach ($group['lines'] as $line) {
                $allowed[$line['key']] = true;
            }
        }

        $header = [
            'company_id' => $companyId,
            'statement_type' => $type,
            'period_end_date' => $periodEnd,
            'period_type' => $periodType,
            'fiscal_year' => (int) ($input['fiscal_year'] ?? substr($periodEnd, 0, 4)),
            'currency' => $input['currency'] ?? 'FCFA',
            'unit_multiplier' => $unit,
            'source_report_id' => !empty($input['source_report_id']) ? (int) $input['source_report_id'] : null,
            'source_note' => isset($input['source_note']) ? mb_substr((string) $input['source_note'], 0, 255) : null,
        ];

        $existing = $this->crud->executeCustomQuery(
            'SELECT id FROM financial_statements
             WHERE company_id = ? AND statement_type = ? AND period_end_date = ? AND period_type = ?',
            [$companyId, $type, $periodEnd, $periodType]
        );
        if (!empty($existing)) {
            $id = (int) $existing[0]['id'];
            $this->crud->merge('financial_statements', $header, ['id' => $id]);
        } else {
            $header['is_active'] = 1;
            $id = (int) $this->crud->persist('financial_statements', $header);
        }

        // Réécriture complète des postes : un poste vidé par l'utilisateur
        // doit disparaître, pas conserver son ancienne valeur.
        $this->crud->executeCustomQuery('DELETE FROM financial_statement_lines WHERE statement_id = ?', [$id]);
        $ignored = [];
        foreach ((array) ($input['values'] ?? []) as $key => $value) {
            if (!isset($allowed[$key])) {
                $ignored[] = $key;
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $this->crud->persist('financial_statement_lines', [
                'statement_id' => $id,
                'line_key' => $key,
                'value' => (float) $value,
            ]);
        }

        $saved = $this->getStatement(['id' => $id]);
        $saved['data']['created'] = empty($existing);
        $saved['data']['ignored_keys'] = $ignored;
        return $saved;
    }

    /**
     * Activation / désactivation. Désactiver conserve la saisie mais retire
     * l'état des graphes : c'est ce qu'il faut pour un document erroné ou
     * remplacé par une version certifiée, là où supprimer perdrait la trace.
     */
    private function setActive($input) {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('id requis');
        }
        $active = !empty($input['is_active']);
        $this->crud->merge('financial_statements', [
            'is_active' => $active ? 1 : 0,
            'deactivated_reason' => $active ? null
                : (isset($input['reason']) ? mb_substr((string) $input['reason'], 0, 255) : null),
        ], ['id' => $id]);
        return $this->getStatement(['id' => $id]);
    }

    private function delete($input) {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('id requis');
        }
        // Les postes partent avec l'en-tête (ON DELETE CASCADE).
        $this->crud->remove('financial_statements', ['id' => $id]);
        return ['success' => true, 'data' => ['deleted_id' => $id]];
    }

    /**
     * Série chronologique d'un format donné, pour les graphes. Seuls les
     * états ACTIFS sont retenus : c'est le sens même de la désactivation.
     */
    private function series($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if ($companyId <= 0) {
            throw new Exception('company_id requis');
        }
        $type = $input['statement_type'] ?? '';
        FinancialStatementSchemas::get($type);
        $periodType = in_array($input['period_type'] ?? '', self::PERIOD_TYPES, true) ? $input['period_type'] : null;

        $sql = $this->baseSelect() . ' WHERE s.company_id = ? AND s.statement_type = ? AND s.is_active = 1';
        $params = [$companyId, $type];
        if ($periodType !== null) {
            $sql .= ' AND s.period_type = ?';
            $params[] = $periodType;
        }
        $sql .= ' ORDER BY s.period_end_date ASC';

        $rows = $this->crud->executeCustomQuery($sql, $params) ?: [];
        $series = [];
        $previous = null;
        foreach ($rows as $row) {
            $item = $this->decorate($row);
            $growth = [];
            if ($previous !== null && $previous['period_type'] === $item['period_type']) {
                foreach ($item['subtotals'] as $key => $value) {
                    $before = $previous['subtotals'][$key] ?? null;
                    $growth[$key] = ($before !== null && $before != 0.0)
                        ? round(($value - $before) / abs($before) * 100, 2) : null;
                }
            }
            $item['growth'] = $growth;
            $series[] = $item;
            $previous = $item;
        }

        $schema = FinancialStatementSchemas::get($type);
        return ['success' => true, 'data' => [
            'series' => $series,
            'statement_type' => $type,
            'statement_label' => $schema['label'],
            'subtotals' => $schema['subtotals'],
            'headline' => $schema['headline'],
            'count' => count($series),
            'note' => "Seuls les états ACTIFS figurent dans les graphes. Les évolutions ne sont calculées qu'entre périodes de même type. Le PER et le PBR utilisent le cours le plus proche de la date de clôture, jamais le cours du jour.",
        ]];
    }
}

// Exécution
$api = new FinancialStatementsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
