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

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once 'class/DbConnect.php';
require_once 'class/DynamiqueCrud.php';
require_once 'class/FinancialStatementSchemas.php';
require_once 'class/SpreadsheetReader.php';
require_once 'class/StatementImportMatcher.php';
require_once 'class/AuthGuard.php';
AuthGuard::requireAuth();

/**
 * Téléchargement du modèle de saisie : sert un CSV et non du JSON, il doit
 * donc être traité AVANT l'en-tête Content-Type de l'API. Le token passe en
 * paramètre d'URL (un téléchargement de navigateur ne peut pas poser
 * d'en-tête X-Auth-Token — même repli que reportDownloadUrl côté frontend).
 *
 * Le modèle est ENGENDRÉ depuis le registre des formats : il ne peut donc
 * pas se désynchroniser des postes réellement attendus, contrairement à un
 * fichier d'exemple maintenu à la main.
 */
if (($_GET['action'] ?? '') === 'import_template') {
    try {
        // FORMAT TABLEAU CLASSIQUE : une LIGNE = un état financier, les
        // champs en COLONNES. C'est la disposition d'un export de base de
        // données, et la seule praticable pour saisir dix exercices — dix
        // lignes plutôt que dix colonnes à faire défiler horizontalement.
        //
        // Colonnes : les 5 champs de description de l'état, puis un poste
        // par colonne, puis les sous-totaux préfixés « controle_ » qui
        // servent à vérifier l'import sans être importés.
        $only = $_GET['statement_type'] ?? '';
        $blankRows = max(1, min(50, (int) ($_GET['rows'] ?? 5)));

        $prefillCompany = (int) ($_GET['prefill_company_id'] ?? 0);
        $prefillReport = (int) ($_GET['prefill_report_id'] ?? 0);
        // Sélection explicite depuis l'écran Rapports : « 12,45,78 ».
        $prefillIds = [];
        foreach (explode(',', (string) ($_GET['prefill_report_ids'] ?? '')) as $piece) {
            $id = (int) trim($piece);
            if ($id > 0) {
                $prefillIds[] = $id;
            }
        }
        $prefill = [];
        if ($prefillCompany > 0 || $prefillReport > 0 || !empty($prefillIds)) {
            $prefill = (new FinancialStatementsAPI())->prefillColumns($prefillCompany, $prefillReport, $prefillIds);
            if (!empty($prefill)) {
                $only = 'activite_simplifie';
            }
        }

        // Un fichier par format : les postes diffèrent d'un format à
        // l'autre, les réunir donnerait plus de cent colonnes dont la
        // plupart vides sur chaque ligne.
        if ($only === '') {
            $only = 'syscohada_resultat';
        }
        $schema = FinancialStatementSchemas::get($only);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="modele_' . preg_replace('/[^a-z0-9_]/', '', $only) . '.csv"');

        $out = fopen('php://output', 'w');
        // BOM UTF-8 : sans lui, Excel affiche les accents en caractères
        // illisibles à l'ouverture d'un CSV.
        fwrite($out, "\xEF\xBB\xBF");
        // Point-virgule : séparateur attendu par Excel en configuration
        // francophone (la virgule y est le séparateur décimal).
        $sep = ';';

        // Colonnes : description de l'état, puis les postes, puis les
        // contrôles.
        $metaColumns = ['statement_type', 'period_end_date', 'period_type', 'unit_multiplier', 'source_note'];
        $metaLabels = [
            'Format (ne pas modifier)', 'Date de clôture (AAAA-MM-JJ)',
            'Période (annuel/semestriel/trimestriel)', 'Unité (1, 1000 ou 1000000)', 'Source (libre)',
        ];

        $lineKeys = [];
        $lineLabels = [];
        foreach ($schema['groups'] as $group) {
            foreach ($group['lines'] as $line) {
                $lineKeys[] = $line['key'];
                $lineLabels[] = $line['label'] . ($line['sign'] === 'charge' ? ' [négatif]' : '');
            }
        }
        $controlKeys = [];
        $controlLabels = [];
        foreach ($schema['subtotals'] as $subtotal) {
            $controlKeys[] = 'controle_' . $subtotal['key'];
            $controlLabels[] = 'CONTRÔLE — ' . $subtotal['label'];
        }

        // Ligne 1 : les NOMS TECHNIQUES, seule ligne lue à l'import.
        fputcsv($out, array_merge($metaColumns, $lineKeys, $controlKeys), $sep);
        // Ligne 2 : les libellés lisibles. Sans montant, elle est ignorée à
        // la relecture — elle n'existe que pour vous repérer dans Excel.
        fputcsv($out, array_merge($metaLabels, $lineLabels, $controlLabels), $sep);

        if (!empty($prefill)) {
            foreach ($prefill as $column) {
                $row = [
                    $column['format'], $column['date'], $column['periode'], $column['unite'],
                    'Pré-rempli — ' . $column['label'],
                ];
                foreach ($lineKeys as $key) {
                    $row[] = isset($column['values'][$key]) ? (string) $column['values'][$key] : '';
                }
                foreach ($controlKeys as $ignored) {
                    $row[] = '';
                }
                fputcsv($out, $row, $sep);
            }
        } else {
            // Lignes vides prêtes à remplir, avec le format et la période
            // déjà posés : il ne reste que la date et les montants.
            for ($i = 0; $i < $blankRows; $i++) {
                $row = array_merge([$only, '', 'annuel', '1', ''],
                    array_fill(0, count($lineKeys) + count($controlKeys), ''));
                fputcsv($out, $row, $sep);
            }
        }

        // Notice en fin de fichier : en tête, elle décalerait la ligne
        // d'en-tête que l'import doit trouver en premier.
        fputcsv($out, [], $sep);
        fputcsv($out, ['# MODE D\'EMPLOI — ' . $schema['label']], $sep);
        fputcsv($out, ['# Une LIGNE = un état financier. Ajoutez autant de lignes que d\'exercices à importer.'], $sep);
        fputcsv($out, ['# Ne modifiez NI la 1re ligne (noms techniques) NI la colonne statement_type : elles pilotent l\'import.'], $sep);
        fputcsv($out, ['# La 2e ligne ne sert qu\'à vous repérer ; elle est ignorée à l\'import.'], $sep);
        fputcsv($out, ['# SIGNES : ' . $schema['sign_note']], $sep);
        fputcsv($out, ['# unit_multiplier : 1 = francs CFA, 1000 = milliers, 1000000 = millions. Il s\'applique à toute la ligne.'], $sep);
        fputcsv($out, ['# Colonnes controle_* : recopiez-y les totaux imprimés sur votre document. Ils ne sont pas importés, ils VÉRIFIENT le calcul.'], $sep);
        if (!empty($prefill)) {
            fputcsv($out, ['# CE FICHIER EST PRÉ-REMPLI depuis ' . count($prefill) . ' rapport(s) analysé(s) par IA : CHIFFRES ET DATES À VÉRIFIER.'], $sep);
            fputcsv($out, ['# L\'extraction se trompe régulièrement d\'unité, et les dates sont celles de PUBLICATION, pas de clôture.'], $sep);
        }

        fclose($out);
        exit(0);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit(0);
    }
}

header('Content-Type: application/json');

class FinancialStatementsAPI {
    private const PERIOD_TYPES = ['annuel', 'semestriel', 'trimestriel'];
    private const UNITS = [1, 1000, 1000000];

    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        // L'import de fichier arrive en multipart/form-data : le corps n'est
        // pas du JSON, il faut le traiter avant toute tentative de décodage.
        if (!empty($_POST['action']) && $_POST['action'] === 'import_preview') {
            try {
                return $this->importPreview();
            } catch (Exception $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
        }

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
                case 'save_many':
                    return $this->saveMany($input);
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

        // PER/rendement officiels BRVM, tels qu'imprimés dans le Bulletin
        // Officiel de la Cote le plus proche (à la date de fin de période ou
        // avant) : calculés par la BRVM elle-même avec le nombre d'actions
        // en circulation à cette date précise, contrairement à notre PER
        // maison qui s'appuie sur companies.shares_outstanding (une seule
        // valeur "actuelle", non historisée — voir migration 024). Affiché
        // à côté du calcul propre à l'application, pas à sa place.
        $bulletinMetric = $this->crud->executeCustomQuery(
            "SELECT per, yield_net_percent, close_price, publish_date
             FROM bulletin_stock_metrics
             WHERE company_id = ? AND publish_date <= ?
             ORDER BY publish_date DESC LIMIT 1",
            [$companyId, $periodEnd]
        );
        $perBrvm = !empty($bulletinMetric) && $bulletinMetric[0]['per'] !== null
            ? (float) $bulletinMetric[0]['per'] : null;
        $yieldNetBrvm = !empty($bulletinMetric) && $bulletinMetric[0]['yield_net_percent'] !== null
            ? (float) $bulletinMetric[0]['yield_net_percent'] : null;

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
            'per_brvm' => $perBrvm,
            'yield_net_brvm_percent' => $yieldNetBrvm,
            'per_brvm_date' => !empty($bulletinMetric) ? $bulletinMetric[0]['publish_date'] : null,
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

    /**
     * Analyse un fichier joint (CSV ou XLSX) et PROPOSE un rapprochement
     * avec les postes du format sélectionné. Rien n'est enregistré ici :
     * l'utilisateur valide le rapprochement à l'écran puis déclenche un
     * `save` classique — un libellé mal reconnu deviendrait sinon une
     * fausse donnée indétectable.
     *
     * Le même fichier convient à n'importe quel format : c'est le format
     * choisi qui détermine les postes cibles.
     */
    private function importPreview() {
        // Facultatif : le modèle unique porte lui-même le format de chaque
        // colonne. Il n'est requis que pour un fichier libre.
        $type = $_POST['statement_type'] ?? '';

        if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            $code = isset($_FILES['file']['error']) ? (int) $_FILES['file']['error'] : -1;
            if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
                throw new Exception('Fichier trop volumineux pour le serveur (limite ' . ini_get('post_max_size') . '). Exportez la feuille seule en CSV.');
            }
            throw new Exception('Aucun fichier reçu');
        }

        $name = (string) $_FILES['file']['name'];
        $grid = SpreadsheetReader::read($_FILES['file']['tmp_name'], $name);
        if (empty($grid['rows'])) {
            throw new Exception('Fichier vide ou illisible');
        }

        // Modèle unique multi-états : reconnu à ses lignes @format/@date.
        // Le rapprochement y est direct (clé de format + libellé exact),
        // donc plus fiable que la ressemblance utilisée pour un fichier
        // libre.
        $universal = StatementImportMatcher::analyzeTabular($grid['rows']);
        if ($universal !== null) {
            $universal['file'] = [
                'name' => $name,
                'format' => $grid['format'],
                'sheet' => $grid['sheet'],
                'rows_read' => count($grid['rows']),
            ];
            $universal['note'] = "Modèle tabulaire détecté : " . count($universal['statements'])
                . " ligne(s) lue(s), " . $universal['ready'] . " état(s) prêt(s) à importer. "
                . "Rien n'est encore enregistré.";
            return ['success' => true, 'data' => $universal];
        }

        $analysis = StatementImportMatcher::analyze($grid['rows'], $type);
        $schema = FinancialStatementSchemas::get($type); // lève si inconnu
        $analysis['file'] = [
            'name' => $name,
            'format' => $grid['format'],
            'sheet' => $grid['sheet'],
            'rows_read' => count($grid['rows']),
        ];
        $analysis['sign_note'] = $schema['sign_note'];
        $analysis['sign_convention'] = $schema['sign_convention'];
        $analysis['note'] = "Rien n'est encore enregistré : vérifiez les rapprochements, choisissez la colonne à importer, puis enregistrez. Les sous-totaux du document ne sont pas importés — ils servent à vérifier le calcul.";
        return ['success' => true, 'data' => $analysis];
    }

    /**
     * Compare les sous-totaux RECALCULÉS aux sous-totaux IMPRIMÉS dans le
     * document importé. C'est le contrôle qui transforme un import en
     * saisie fiable : si les deux coïncident, aucun poste n'a été oublié ni
     * mal rapproché.
     */
    private function verifySubtotals(string $type, array $values, array $documentSubtotals) {
        if (empty($documentSubtotals)) {
            return null;
        }
        $computed = FinancialStatementSchemas::computeSubtotals($type, $values);
        $checks = [];
        $labels = [];
        foreach (FinancialStatementSchemas::get($type)['subtotals'] as $st) {
            $labels[$st['key']] = $st['label'];
        }
        $allMatch = true;
        foreach ($documentSubtotals as $key => $expected) {
            if (!array_key_exists($key, $computed) || $expected === null || $expected === '') {
                continue;
            }
            $expected = (float) $expected;
            $got = $computed[$key];
            // Tolérance d'un franc : les documents publiés sont parfois
            // arrondis au niveau du total.
            $ok = abs($got - $expected) < 1.0;
            if (!$ok) {
                $allMatch = false;
            }
            $checks[] = [
                'key' => $key,
                'label' => $labels[$key] ?? $key,
                'document' => round($expected, 2),
                'computed' => $got,
                'difference' => round($got - $expected, 2),
                'matches' => $ok,
            ];
        }
        if (empty($checks)) {
            return null;
        }
        return [
            'checks' => $checks,
            'all_match' => $allMatch,
            'message' => $allMatch
                ? "Les sous-totaux recalculés correspondent à ceux imprimés sur le document : la saisie est cohérente."
                : "Certains sous-totaux recalculés diffèrent du document : un poste a probablement été oublié, mal rapproché, ou saisi avec le mauvais signe.",
        ];
    }

    /**
     * Colonnes de pré-remplissage construites depuis les analyses IA des
     * rapports : une colonne par rapport porteur de chiffres.
     *
     * Seul le format « Chiffres clés » est alimentable : l'extraction IA ne
     * restitue que des agrégats (chiffre d'affaires, résultat, capitaux
     * propres), jamais le détail des postes d'un compte de résultat.
     *
     * L'analyse retenue est la PLUS RÉCENTE de chaque rapport, y compris une
     * correction manuelle : c'est elle qui fait foi partout ailleurs dans
     * l'application, le fichier doit refléter la même chose.
     *
     * @return array<int,array{label:string,format:string,date:string,periode:string,unite:int,values:array}>
     */
    public function prefillColumns(int $companyId, int $reportId, array $reportIds = []): array {
        // Une sélection explicite prime sur l'entreprise entière : c'est
        // l'utilisateur qui a coché les rapports voulus.
        if (!empty($reportIds)) {
            $reportIds = array_slice(array_values(array_unique($reportIds)), 0, 50);
            $where = 'r.id IN (' . implode(',', array_fill(0, count($reportIds), '?')) . ')';
            $params = $reportIds;
        } elseif ($reportId > 0) {
            $where = 'r.id = ?';
            $params = [$reportId];
        } else {
            $where = 'r.company_id = ?';
            $params = [$companyId];
        }

        $rows = $this->crud->executeCustomQuery(
            "SELECT r.id, r.title, r.report_type, r.publish_date, a.details
             FROM company_reports r
             JOIN company_report_analyses a ON a.report_id = r.id
             JOIN (SELECT report_id, MAX(id) AS last_id FROM company_report_analyses GROUP BY report_id) t
               ON t.report_id = a.report_id AND t.last_id = a.id
             WHERE $where AND r.publish_date IS NOT NULL
             ORDER BY r.publish_date ASC",
            $params
        ) ?: [];

        // Correspondance entre les champs de l'extraction IA et les postes du
        // format « Chiffres clés ».
        $map = [
            'revenue' => 'chiffre_affaires',
            'operating_income' => 'resultat_exploitation',
            'net_income' => 'resultat_net_saisi',
            'total_equity' => 'total_equity',
            'total_debt' => 'total_debt',
        ];
        // Les rapports d'une banque parlent de produit net bancaire : si le
        // champ existe, il prime sur « revenue ».
        $bankRevenueKeys = ['net_banking_income', 'produit_net_bancaire'];

        $periodMap = [
            'annuel' => 'annuel', 'etats_financiers' => 'annuel',
            'semestriel' => 'semestriel', 'trimestriel' => 'trimestriel',
        ];

        $columns = [];
        $seen = [];
        foreach ($rows as $row) {
            $details = json_decode((string) $row['details'], true);
            $kf = is_array($details) ? ($details['key_financials'] ?? []) : [];
            if (!is_array($kf)) {
                continue;
            }

            $values = [];
            foreach ($map as $source => $target) {
                if (isset($kf[$source]) && is_numeric($kf[$source])) {
                    $values[$target] = 0 + $kf[$source];
                }
            }
            foreach ($bankRevenueKeys as $bank) {
                if (isset($kf[$bank]) && is_numeric($kf[$bank])) {
                    $values['produit_net_bancaire'] = 0 + $kf[$bank];
                    unset($values['chiffre_affaires']);
                    break;
                }
            }
            if (empty($values)) {
                continue;   // rapport analysé mais sans chiffre exploitable
            }

            $date = (string) $row['publish_date'];
            $periode = $periodMap[$row['report_type']] ?? 'annuel';
            // Deux rapports peuvent couvrir la même période (états financiers
            // + rapport d'activité) : une seule colonne par couple
            // date/période, sinon l'import écraserait le premier par le
            // second sans prévenir.
            $key = $date . '|' . $periode;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $columns[] = [
                'label' => mb_substr((string) $row['title'], 0, 45) . ' (#' . $row['id'] . ')',
                'format' => 'activite_simplifie',
                'date' => $date,
                'periode' => $periode,
                'unite' => 1,
                'values' => $values,
            ];
        }

        // Garde-fou : au-delà, le fichier devient impraticable à relire.
        return array_slice($columns, -20);
    }

    private function baseSelect() {
        return "SELECT s.*, c.symbol, c.name AS company_name
                FROM financial_statements s
                JOIN companies c ON c.id = s.company_id";
    }

    /**
     * Filtre commun aux listes et aux graphes : périodicité et intervalle de
     * dates de clôture.
     *
     * Un émetteur publie souvent un annuel, deux semestriels et quatre
     * trimestriels pour un même exercice. Mélangés sur un même graphe, ils
     * donnent des barres incomparables — un trimestre à côté d'une année —
     * et une courbe de ratios qui n'a aucun sens. La périodicité est donc un
     * filtre de LECTURE, pas une préférence d'affichage.
     *
     * Les dates malformées lèvent au lieu d'être ignorées : un filtre
     * silencieusement inopérant afficherait des données hors période en
     * laissant croire qu'elles sont dans la période demandée.
     *
     * @return array{sql: string, params: array, applied: array}
     */
    private function periodFilter($input) {
        $sql = '';
        $params = [];
        $applied = ['period_type' => null, 'date_from' => null, 'date_to' => null];

        if (!empty($input['period_type'])) {
            if (!in_array($input['period_type'], self::PERIOD_TYPES, true)) {
                throw new Exception('period_type invalide (annuel, semestriel ou trimestriel)');
            }
            $sql .= ' AND s.period_type = ?';
            $params[] = $input['period_type'];
            $applied['period_type'] = $input['period_type'];
        }

        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            if (empty($input[$key])) {
                continue;
            }
            $date = (string) $input[$key];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
                throw new Exception("$key invalide : attendu AAAA-MM-JJ, reçu « $date »");
            }
            $sql .= " AND s.period_end_date $operator ?";
            $params[] = $date;
            $applied[$key] = $date;
        }

        if ($applied['date_from'] !== null && $applied['date_to'] !== null
            && $applied['date_from'] > $applied['date_to']) {
            throw new Exception('La date de début est postérieure à la date de fin.');
        }

        return ['sql' => $sql, 'params' => $params, 'applied' => $applied];
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

        $filter = $this->periodFilter($input);
        $sql .= $filter['sql'];
        $params = array_merge($params, $filter['params']);
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

        // Combien d'états le filtre écarte-t-il ? Sans ce chiffre, un état
        // saisi hors de la période affichée paraîtrait n'avoir jamais été
        // enregistré, et serait ressaisi en double.
        $totalAll = (int) ($this->crud->executeCustomQuery(
            'SELECT COUNT(*) AS n FROM financial_statements WHERE company_id = ?',
            [$companyId]
        )[0]['n'] ?? 0);

        return ['success' => true, 'data' => [
            'groups' => $groups,
            'total' => count($statements),
            'total_unfiltered' => $totalAll,
            'hidden_by_filter' => max(0, $totalAll - count($statements)),
            'filters' => $filter['applied'],
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

        // Si l'appelant a transmis les sous-totaux lus dans le document
        // (cas d'un import), on lui dit si le calcul les retrouve.
        if (!empty($input['document_subtotals']) && is_array($input['document_subtotals'])) {
            $multiplier = (float) $unit;
            $converted = [];
            foreach ($input['document_subtotals'] as $k => $v) {
                if ($v !== null && $v !== '') {
                    $converted[$k] = (float) $v * $multiplier;
                }
            }
            $saved['data']['subtotal_verification'] =
                $this->verifySubtotals($type, $saved['data']['values'], $converted);
        }
        return $saved;
    }

    /**
     * Activation / désactivation. Désactiver conserve la saisie mais retire
     * l'état des graphes : c'est ce qu'il faut pour un document erroné ou
     * remplacé par une version certifiée, là où supprimer perdrait la trace.
     */
    /**
     * Enregistre PLUSIEURS états d'un coup — le lot issu du modèle unique.
     *
     * Chaque état est traité indépendamment : un échec (date absente,
     * format inconnu) n'empêche pas les autres d'être enregistrés, et le
     * rapport détaillé dit exactement ce qui est passé et ce qui a échoué.
     */
    private function saveMany($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if ($companyId <= 0) {
            throw new Exception('company_id requis');
        }
        $statements = is_array($input['statements'] ?? null) ? $input['statements'] : [];
        if (empty($statements)) {
            throw new Exception('Aucun état à enregistrer');
        }

        $results = [];
        $ok = 0;
        foreach ($statements as $statement) {
            $label = ($statement['statement_label'] ?? $statement['statement_type'] ?? '?')
                . ' — ' . ($statement['period_end_date'] ?? 'sans date');
            try {
                $saved = $this->save(array_merge($statement, [
                    'company_id' => $companyId,
                    'source_note' => $statement['source_note'] ?? null,
                ]));
                $data = $saved['data'];
                $ok++;
                $results[] = [
                    'label' => $label,
                    'success' => true,
                    'id' => $data['id'],
                    'created' => $data['created'],
                    'lines_filled' => $data['lines_filled'],
                    'verification' => $data['subtotal_verification'] ?? null,
                ];
            } catch (Exception $e) {
                $results[] = ['label' => $label, 'success' => false, 'error' => $e->getMessage()];
            }
        }

        return ['success' => true, 'data' => [
            'saved' => $ok,
            'failed' => count($results) - $ok,
            'results' => $results,
        ]];
    }

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

        $sql = $this->baseSelect() . ' WHERE s.company_id = ? AND s.statement_type = ? AND s.is_active = 1';
        $params = [$companyId, $type];
        $filter = $this->periodFilter($input);
        $sql .= $filter['sql'];
        $params = array_merge($params, $filter['params']);
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
            'count_unfiltered' => (int) ($this->crud->executeCustomQuery(
                'SELECT COUNT(*) AS n FROM financial_statements
                 WHERE company_id = ? AND statement_type = ? AND is_active = 1',
                [$companyId, $type]
            )[0]['n'] ?? 0),
            'filters' => $filter['applied'],
            'note' => "Seuls les états ACTIFS figurent dans les graphes. Les évolutions ne sont calculées qu'entre périodes de même type. Le PER et le PBR utilisent le cours le plus proche de la date de clôture, jamais le cours du jour.",
        ]];
    }
}

// Exécution
$api = new FinancialStatementsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
