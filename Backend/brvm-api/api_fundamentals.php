<?php
/**
 * API des fondamentaux par entreprise
 * Endpoint: api_fundamentals.php
 *
 * Expose, pour chaque entreprise, les ratios fondamentaux (PER, P/B,
 * ROE, ROA, marges, rendement du dividende...) déjà extraits par IA à
 * partir de son dernier rapport financier traité avec succès
 * (company_report_analyses.details, colonnes key_financials/
 * valuation_assessment — voir class/AnthropicClient.php pour le schéma
 * exact). Aucun nouveau calcul IA ici, seulement une lecture/formatage
 * dédiée + le PEG (absent du schéma existant : PER ÷ croissance du CA,
 * les deux étant déjà présents séparément).
 *
 * Voir TODO_ANALYSES.md, point 24. Décision prise avec l'utilisateur :
 * exploiter cette filière IA existante plutôt que scraper les pages
 * "sociétés cotées" de brvm.org — vérifié en direct, ces pages affichent
 * des données obsolètes (jusqu'à 11 ans de retard selon l'entreprise) et
 * n'incluent pas les capitaux propres, ce qui aurait rendu une "source
 * fiable" scrapée en réalité moins à jour que les rapports déjà traités.
 *
 * Fiabilité différente des indicateurs techniques (calcul déterministe
 * sur des colonnes de base) : ces ratios dépendent de ce que le rapport
 * source a effectivement divulgué et de la qualité de l'extraction IA —
 * la date de publication du rapport source est donc toujours renvoyée
 * explicitement, jamais implicite.
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
require_once 'class/AuthGuard.php';
AuthGuard::requireAuth();

class FundamentalsAPI {
    /**
     * En-dessous de cet écart (en jours) entre le premier et le dernier
     * point disponible d'une série, un CAGR n'a pas de sens (ça reviendrait
     * à annualiser une variation trimestre-à-trimestre) — retourné null
     * plutôt que trompeur. ~300 jours = à peu près un exercice complet.
     */
    private const MIN_CAGR_SPAN_DAYS = 300;

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
                    return $this->listFundamentals($input);

                case 'years':
                    return $this->listAvailableYears();

                case 'history':
                    return $this->historyFundamentals($input);

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
     * Pour chaque entreprise active ayant au moins une analyse IA de
     * rapport réussie, renvoie les ratios extraits d'UN rapport par
     * entreprise : le plus récent par défaut, ou — si `as_of_year` est
     * fourni — le plus récent publié au plus tard le 31/12 de cette
     * année-là (permet de "voir les données d'une année spécifique" pour
     * TOUTES les entreprises à la fois, avec un seul filtre global en tête
     * de page plutôt qu'un sélecteur par entreprise dans le détail déplié).
     * `provider`/`model` optionnels restreignent aux analyses de cette IA
     * précise (voir dedupeByReportId() : un même rapport peut être analysé
     * plusieurs fois, par des IA différentes ou la même IA relancée à une
     * autre date). Une entreprise sans aucun rapport à cette date n'apparaît
     * pas dans `data` — comptées à part dans `companies_without_data` pour
     * que le frontend puisse l'annoncer explicitement plutôt que de laisser
     * croire à une liste complète.
     */
    private function listFundamentals($input) {
        $asOfYear = isset($input['as_of_year']) && $input['as_of_year'] !== null && $input['as_of_year'] !== ''
            ? (int) $input['as_of_year']
            : null;
        $provider = isset($input['provider']) && $input['provider'] !== '' ? (string) $input['provider'] : null;
        $model = isset($input['model']) && $input['model'] !== '' ? (string) $input['model'] : null;

        $rows = $this->fetchAnalysisRows(null, $provider, $model);

        $historyByCompany = [];
        foreach ($rows as $row) {
            $historyByCompany[(int) $row['company_id']][] = $row;
        }

        $bulletinDividendsByCompany = [];
        foreach ($this->fetchBulletinDividendActions(null) as $action) {
            $bulletinDividendsByCompany[(int) $action['company_id']][] = $action;
        }

        $result = [];
        foreach ($historyByCompany as $cid => $companyRows) {
            // Déduplique AVANT tout : un même rapport analysé par plusieurs IA (ou
            // relancé plusieurs fois) ne doit compter que pour un seul point, sinon
            // "le rapport le plus récent" comme les CAGR/graphes de croissance
            // afficheraient des doublons pour la même période.
            $companyRows = $this->dedupeByReportId($companyRows);

            // $companyRows reste en publish_date DESC après dédup : le premier
            // élément qui satisfait le filtre est donc le plus récent qui le satisfait.
            $chosen = null;
            foreach ($companyRows as $candidate) {
                if ($asOfYear === null || (int) date('Y', strtotime($candidate['publish_date'])) <= $asOfYear) {
                    $chosen = $candidate;
                    break;
                }
            }
            if ($chosen === null) {
                continue; // Aucun rapport de cette entreprise n'existe encore à/avant as_of_year.
            }

            $details = json_decode($chosen['details'] ?? 'null', true) ?: [];
            $financials = $details['key_financials'] ?? [];
            $valuation = $details['valuation_assessment'] ?? [];
            // Remis en ordre chronologique ASC pour le calcul des CAGR — toujours sur
            // l'historique COMPLET de l'entreprise, jamais tronqué à as_of_year : la
            // croissance décrit sa trajectoire globale, pas un instantané figé.
            $history = array_reverse($companyRows);

            $result[] = $this->buildRatios($cid, $chosen, $financials, $valuation, $history, $bulletinDividendsByCompany[$cid] ?? []);
        }

        $result = $this->applySectorComparables($result);

        $companiesWithData = array_column($result, 'company_id');
        $allActive = $this->crud->executeCustomQuery("SELECT id, symbol, name FROM companies WHERE active = 1") ?: [];
        $companiesWithoutData = array_values(array_filter($allActive, fn($c) => !in_array((int) $c['id'], $companiesWithData, true)));

        return [
            'success' => true,
            'data' => $result,
            'count' => count($result),
            'as_of_year' => $asOfYear,
            'companies_without_data' => array_map(fn($c) => ['company_id' => (int) $c['id'], 'symbol' => $c['symbol'], 'name' => $c['name']], $companiesWithoutData),
            'companies_without_data_count' => count($companiesWithoutData)
        ];
    }

    /**
     * Années distinctes ayant au moins un rapport analysé avec succès (toutes
     * entreprises confondues), triées décroissant — alimente le filtre
     * global "Année" du frontend (voir listFundamentals()/`as_of_year`).
     */
    private function listAvailableYears() {
        $rows = $this->crud->executeCustomQuery(
            "SELECT DISTINCT YEAR(cr.publish_date) AS y
             FROM company_report_analyses cra
             INNER JOIN company_reports cr ON cr.id = cra.report_id
             INNER JOIN companies c ON c.id = cra.company_id
             WHERE cra.status = 'success' AND c.active = 1
             ORDER BY y DESC"
        ) ?: [];

        return ['success' => true, 'data' => array_map(fn($r) => (int) $r['y'], $rows)];
    }

    /**
     * Même jeu de ratios que listFundamentals(), mais pour UNE entreprise et
     * TOUTES ses analyses réussies — CHAQUE analyse, pas dédupliquées par
     * report_id comme dans listFundamentals() : un même rapport peut être
     * analysé par plusieurs IA (fournisseur+modèle), ou par la même IA
     * relancée à une autre date, et chacune doit rester sélectionnable ici
     * pour permettre au frontend de proposer des filtres "Année"/"Type de
     * rapport"/"IA" (voir source_provider/source_model dans buildRatios())
     * — notamment pour comparer la qualité d'extraction entre IA sur un même
     * rapport. Les CAGR/séries de croissance, elles, restent calculées sur
     * l'historique DÉDUPLIQUÉ (une analyse par rapport, la plus récente) :
     * la trajectoire de l'entreprise ne doit pas compter deux fois le même
     * rapport sous prétexte qu'il a été analysé par deux IA différentes.
     */
    private function historyFundamentals($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if ($companyId <= 0) {
            throw new Exception("company_id requis");
        }

        $rows = $this->fetchAnalysisRows($companyId, null, null);

        if (empty($rows)) {
            return ['success' => true, 'data' => [], 'count' => 0];
        }

        $history = array_reverse($this->dedupeByReportId($rows));
        $bulletinDividendActions = $this->fetchBulletinDividendActions($companyId);

        $result = [];
        foreach ($rows as $row) {
            $details = json_decode($row['details'] ?? 'null', true) ?: [];
            $financials = $details['key_financials'] ?? [];
            $valuation = $details['valuation_assessment'] ?? [];
            $result[] = $this->buildRatios((int) $row['company_id'], $row, $financials, $valuation, $history, $bulletinDividendActions);
        }

        // Comparables sectoriels non applicables ici : ils supposent un
        // instantané comparable entre PLUSIEURS entreprises à un même
        // moment (voir applySectorComparables()), pas l'historique d'UNE
        // seule entreprise sur plusieurs exercices — champs renvoyés à
        // null/0 pour respecter le même contrat de champs que l'action
        // 'list' plutôt que de les omettre.
        $result = array_map(function ($row) {
            return array_merge($row, [
                'sector_peer_count' => 0,
                'sector_median_pe_ratio' => null,
                'pe_ratio_vs_sector_percent' => null,
                'sector_median_price_to_book' => null,
                'price_to_book_vs_sector_percent' => null,
                'sector_median_ev_to_ebitda' => null,
                'ev_to_ebitda_vs_sector_percent' => null,
                'sector_median_dividend_yield_percent' => null,
                'dividend_yield_percent_vs_sector_percent' => null,
            ]);
        }, $result);

        return [
            'success' => true,
            'data' => $result,
            'count' => count($result),
        ];
    }

    /**
     * Requête commune à listFundamentals()/historyFundamentals() : toutes
     * les analyses réussies (éventuellement restreintes à une entreprise
     * et/ou un fournisseur+modèle précis), triées company_id ASC puis
     * publish_date DESC. Le tri secondaire sur cra.id DESC est nécessaire :
     * deux analyses différentes (deux rapports partageant la même
     * publish_date, ou un même rapport analysé plusieurs fois) peuvent sinon
     * apparaître dans un ordre non déterministe d'un appel à l'autre.
     */
    private function fetchAnalysisRows(?int $companyId, ?string $provider, ?string $model): array {
        $conditions = ["cra.status = 'success'", "c.active = 1"];
        $params = [];
        if ($companyId !== null) {
            $conditions[] = "cra.company_id = ?";
            $params[] = $companyId;
        }
        if ($provider !== null) {
            $conditions[] = "cra.provider = ?";
            $params[] = $provider;
        }
        if ($model !== null) {
            $conditions[] = "cra.model = ?";
            $params[] = $model;
        }
        $where = implode(' AND ', $conditions);

        $sql = "
            SELECT
                cra.id AS analysis_id,
                cra.company_id,
                cra.details,
                cra.market_context_date,
                cra.provider,
                cra.model,
                cr.id AS report_id,
                cr.report_type,
                cr.title AS report_title,
                cr.publish_date,
                c.symbol,
                c.name,
                c.sector_id,
                s.name AS sector,
                sq.close_price AS market_price
            FROM company_report_analyses cra
            INNER JOIN company_reports cr ON cr.id = cra.report_id
            INNER JOIN companies c ON c.id = cra.company_id
            LEFT JOIN sectors s ON s.id = c.sector_id
            LEFT JOIN stock_quotes sq ON sq.company_id = cra.company_id AND sq.trading_date = cra.market_context_date
            WHERE $where
            ORDER BY cra.company_id ASC, cr.publish_date DESC, cra.id DESC
        ";
        return $this->crud->executeCustomQuery($sql, $params) ?: [];
    }

    /**
     * Un même rapport peut avoir plusieurs analyses réussies — des IA
     * différentes (fournisseur+modèle), ou la même IA relancée à une autre
     * date — ce qui produirait des points en double dans les graphes de
     * croissance et des CAGR faussés si on les gardait toutes. Une seule
     * analyse par report_id : la plus récente, tous fournisseurs confondus
     * (l'ORDER BY de fetchAnalysisRows() — publish_date DESC, cra.id DESC —
     * garantit que la première occurrence rencontrée par report_id est la
     * plus récente). N'affecte PAS historyFundamentals()'s propre liste de
     * résultats (chaque analyse doit y rester sélectionnable), seulement
     * l'historique utilisé pour les CAGR/graphes de croissance.
     */
    private function dedupeByReportId(array $rows): array {
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $reportId = (int) $row['report_id'];
            if (isset($seen[$reportId])) {
                continue;
            }
            $seen[$reportId] = true;
            $result[] = $row;
        }
        return $result;
    }

    /**
     * Dividendes déclarés extraits des BULLETINS BRVM (voir
     * BulletinCorporateActionsService), pas des rapports financiers — une
     * source indépendante : un bulletin annonce un dividende déclaré/mis en
     * paiement à une date précise, alors qu'un rapport ne divulgue le
     * dividende par action que rétrospectivement dans ses états financiers.
     * Seules les actions de type 'dividende' avec un montant ET une
     * entreprise reconnus (company_id non nul — voir CompanySlugMatcher)
     * sont retenues ; sans company_id ni montant, l'action n'est pas
     * exploitable pour un graphe fiable.
     */
    private function fetchBulletinDividendActions(?int $companyId): array {
        $conditions = ["mbca.action_type = 'dividende'", "mbca.amount IS NOT NULL", "mbca.company_id IS NOT NULL"];
        $params = [];
        if ($companyId !== null) {
            $conditions[] = "mbca.company_id = ?";
            $params[] = $companyId;
        }
        $where = implode(' AND ', $conditions);

        $sql = "
            SELECT
                mbca.id AS action_id,
                mbca.company_id,
                mbca.event_date,
                mbca.amount,
                mbca.description,
                mb.id AS bulletin_id,
                mb.title AS bulletin_title,
                mb.publish_date AS bulletin_publish_date
            FROM market_bulletin_corporate_actions mbca
            INNER JOIN market_bulletins mb ON mb.id = mbca.bulletin_id
            WHERE $where
            ORDER BY mbca.company_id ASC, mbca.event_date ASC, mbca.id ASC
        ";
        return $this->crud->executeCustomQuery($sql, $params) ?: [];
    }

    /**
     * Convertit les lignes fetchBulletinDividendActions() en points de série
     * (même forme que les *_history de computeGrowthMetrics(), pour que le
     * frontend réutilise le même composant de graphe) — date = date de
     * l'événement annoncé dans le bulletin, ou à défaut sa date de
     * publication (repli identique à resolvePeriodDate() pour les rapports).
     * 'report_id' recycle l'id de l'action (toujours unique, nécessaire pour
     * distinguer deux points de graphe qui partageraient la même date — voir
     * le bug de rendu Recharts corrigé côté frontend) ; 'report_type' vaut
     * toujours 'bulletin' pour que le frontend puisse distinguer cette
     * origine de celle des rapports dans la série combinée.
     */
    private function bulletinDividendSeries(array $actions): array {
        $series = [];
        foreach ($actions as $a) {
            $date = $a['event_date'] ?: $a['bulletin_publish_date'];
            if (!$date) {
                continue;
            }
            $series[] = [
                'date' => $date,
                'report_id' => (int) $a['action_id'],
                'report_title' => $a['description'] ?: "Bulletin du {$a['bulletin_publish_date']} : {$a['bulletin_title']}",
                'report_type' => 'bulletin',
                'value' => (float) $a['amount'],
            ];
        }
        return $series;
    }

    /**
     * Construit tous les ratios exposés pour une entreprise à partir du
     * seul JSON déjà extrait par l'IA (key_financials/valuation_assessment)
     * — aucun nouvel appel IA ici. Deux catégories :
     *   1. Champs déjà extraits mais jusqu'ici non renvoyés par cet
     *      endpoint (ex: quick_ratio, délais clients/fournisseurs/stocks,
     *      BFR, trésorerie, montants bruts) — simple lecture.
     *   2. Ratios dérivés calculés ici en PHP à partir de ces champs (PSR,
     *      EV/Sales, EV/EBIT, EV/FCF, PCF, FCF Yield, dette nette,
     *      couverture du dividende, taux de rétention, PEG sur la
     *      croissance du résultat net) — mêmes conventions que les ratios
     *      déjà calculés par l'IA (ex: 'ev_to_ebitda' utilise exactement
     *      la même formule d'EV, voir class/ReportAnalysisService.php).
     *
     * La capitalisation utilisée pour TOUS les multiples ici (existants ET
     * nouveaux) est calculée avec le cours de clôture à market_context_date
     * (celui que l'IA a utilisé pour pe_ratio/price_to_book/ev_to_ebitda) —
     * jamais le cours du jour de consultation, pour que tous les multiples
     * d'une même ligne restent mutuellement cohérents (mélanger un PER figé
     * à une ancienne date avec un PSR calculé au cours du jour donnerait des
     * ratios individuellement corrects mais incohérents entre eux).
     */
    private function buildRatios(int $companyId, array $row, array $financials, array $valuation, array $history = [], array $bulletinDividendActions = []): array {
        $peRatio = $this->toFloatOrNull($valuation['pe_ratio'] ?? null);
        $priceToBook = $this->toFloatOrNull($valuation['price_to_book'] ?? null);
        // Critère de Graham : PER × PBR, comparé traditionnellement à un seuil de
        // 22,5 (Benjamin Graham) au-delà duquel une action est jugée chère sur
        // ces deux mesures combinées — jamais une règle absolue, mais un repère
        // rapide couramment cité, d'où son exposition ici plutôt qu'un calcul
        // ad hoc côté frontend.
        $perPbrProduct = ($peRatio !== null && $priceToBook !== null)
            ? round($peRatio * $priceToBook, 3)
            : null;

        $revenueGrowth = $this->toFloatOrNull($financials['revenue_growth_percent'] ?? null);
        $pegRatio = ($peRatio !== null && $revenueGrowth !== null && $revenueGrowth != 0)
            ? round($peRatio / $revenueGrowth, 3)
            : null;

        $netIncome = $this->toFloatOrNull($financials['net_income'] ?? null);
        $netIncomePriorYear = $this->toFloatOrNull($financials['net_income_prior_year'] ?? null);
        $netIncomeGrowth = ($netIncome !== null && $netIncomePriorYear !== null && $netIncomePriorYear != 0)
            ? round((($netIncome - $netIncomePriorYear) / abs($netIncomePriorYear)) * 100, 2)
            : null;
        $pegEarningsRatio = ($peRatio !== null && $netIncomeGrowth !== null && $netIncomeGrowth != 0)
            ? round($peRatio / $netIncomeGrowth, 3)
            : null;

        $sharesOutstanding = $this->toFloatOrNull($valuation['shares_outstanding'] ?? null);
        $marketPrice = $this->toFloatOrNull($row['market_price'] ?? null);
        $marketCap = ($sharesOutstanding !== null && $marketPrice !== null) ? $sharesOutstanding * $marketPrice : null;

        // Flottant : % du capital extrait par l'IA (uniquement si la répartition de
        // l'actionnariat est explicitement chiffrée dans le rapport, voir
        // ReportAnalysisService::buildPrompt()) — le nombre d'actions et la
        // capitalisation flottante qui en découlent sont, eux, calculés ici.
        $freeFloatPercent = $this->toFloatOrNull($valuation['free_float_percent'] ?? null);
        $freeFloatShares = ($freeFloatPercent !== null && $sharesOutstanding !== null)
            ? round($sharesOutstanding * $freeFloatPercent / 100)
            : null;
        $freeFloatMarketCap = ($freeFloatShares !== null && $marketPrice !== null)
            ? round($freeFloatShares * $marketPrice, 2)
            : null;

        $totalDebt = $this->toFloatOrNull($financials['total_debt'] ?? null);
        $totalEquity = $this->toFloatOrNull($financials['total_equity'] ?? null);
        $cashPosition = $this->toFloatOrNull($financials['cash_position'] ?? null);
        $ebitda = $this->toFloatOrNull($financials['ebitda'] ?? null);
        $operatingIncome = $this->toFloatOrNull($financials['operating_income'] ?? null);
        $revenue = $this->toFloatOrNull($financials['revenue'] ?? null);
        $operatingCashFlow = $this->toFloatOrNull($financials['operating_cash_flow'] ?? null);
        $freeCashFlow = $this->toFloatOrNull($financials['free_cash_flow'] ?? null);
        $dividendPerShare = $this->toFloatOrNull($financials['dividend_per_share'] ?? null);
        $payoutRatio = $this->toFloatOrNull($valuation['payout_ratio_percent'] ?? null);

        $enterpriseValue = ($marketCap !== null && $totalDebt !== null && $cashPosition !== null)
            ? $marketCap + $totalDebt - $cashPosition
            : null;

        $netDebt = ($totalDebt !== null && $cashPosition !== null) ? $totalDebt - $cashPosition : null;
        $netDebtToEquity = ($netDebt !== null && $totalEquity !== null && $totalEquity != 0)
            ? round($netDebt / $totalEquity, 3)
            : null;
        $netDebtToEbitda = ($netDebt !== null && $ebitda !== null && $ebitda != 0)
            ? round($netDebt / $ebitda, 3)
            : null;

        $priceToSales = ($marketCap !== null && $revenue !== null && $revenue != 0)
            ? round($marketCap / $revenue, 3)
            : null;
        $priceToCashFlow = ($marketCap !== null && $operatingCashFlow !== null && $operatingCashFlow != 0)
            ? round($marketCap / $operatingCashFlow, 3)
            : null;
        $evToSales = ($enterpriseValue !== null && $revenue !== null && $revenue != 0)
            ? round($enterpriseValue / $revenue, 3)
            : null;
        $evToEbit = ($enterpriseValue !== null && $operatingIncome !== null && $operatingIncome != 0)
            ? round($enterpriseValue / $operatingIncome, 3)
            : null;
        $evToFcf = ($enterpriseValue !== null && $freeCashFlow !== null && $freeCashFlow != 0)
            ? round($enterpriseValue / $freeCashFlow, 3)
            : null;
        $fcfYield = ($freeCashFlow !== null && $marketCap !== null && $marketCap != 0)
            ? round(($freeCashFlow / $marketCap) * 100, 2)
            : null;

        $totalDividends = ($dividendPerShare !== null && $sharesOutstanding !== null)
            ? $dividendPerShare * $sharesOutstanding
            : null;
        $dividendCoverage = ($netIncome !== null && $totalDividends !== null && $totalDividends != 0)
            ? round($netIncome / $totalDividends, 2)
            : null;
        $retentionRatio = ($payoutRatio !== null) ? round(100 - $payoutRatio, 2) : null;

        // Ratios de rotation — pas de total_assets/COGS/stocks bruts dans le
        // schéma d'extraction, donc calculés par inversion de ratios déjà
        // extraits plutôt que par une nouvelle extraction IA :
        //   - receivable/payable/inventory turnover = 365 / délai en jours déjà extrait.
        //   - asset turnover = identité de DuPont (ROA = marge nette × rotation
        //     des actifs, donc rotation des actifs = ROA ÷ marge nette).
        $roaPercent = $this->toFloatOrNull($financials['roa_percent'] ?? null);
        $netMarginPercent = $this->toFloatOrNull($financials['net_margin_percent'] ?? null);
        $receivableDays = $this->toFloatOrNull($financials['receivable_days'] ?? null);
        $payableDays = $this->toFloatOrNull($financials['payable_days'] ?? null);
        $inventoryDays = $this->toFloatOrNull($financials['inventory_days'] ?? null);

        $assetTurnover = ($roaPercent !== null && $netMarginPercent !== null && $netMarginPercent != 0)
            ? round($roaPercent / $netMarginPercent, 3)
            : null;
        $receivableTurnover = ($receivableDays !== null && $receivableDays != 0) ? round(365 / $receivableDays, 2) : null;
        $payableTurnover = ($payableDays !== null && $payableDays != 0) ? round(365 / $payableDays, 2) : null;
        $inventoryTurnover = ($inventoryDays !== null && $inventoryDays != 0) ? round(365 / $inventoryDays, 2) : null;

        $growth = $this->computeGrowthMetrics($history);

        // Dividende PAR ACTION vu par les bulletins BRVM (déclarations de dividende,
        // voir fetchBulletinDividendActions()) — source indépendante des rapports
        // financiers. La série combinée fusionne les deux, chaque point marqué par
        // sa provenance ('rapport' ou 'bulletin') pour que le frontend puisse les
        // distinguer visuellement (les deux sources peuvent légitimement diverger
        // légèrement : un bulletin annonce le dividende déclaré, un rapport le
        // dividende effectivement versé sur l'exercice).
        $bulletinDividendHistory = $this->bulletinDividendSeries($bulletinDividendActions);
        $combinedDividendHistory = array_merge(
            array_map(fn($p) => $p + ['source' => 'rapport'], $growth['dividend_history']),
            array_map(fn($p) => $p + ['source' => 'bulletin'], $bulletinDividendHistory)
        );
        usort($combinedDividendHistory, fn($a, $b) => $a['date'] <=> $b['date']);

        return [
            'company_id' => $companyId,
            'symbol' => $row['symbol'],
            'name' => $row['name'],
            'sector_id' => $row['sector_id'] !== null ? (int) $row['sector_id'] : null,
            'sector' => $row['sector'],
            'source_report_id' => (int) $row['report_id'],
            'source_report_type' => $row['report_type'],
            'source_report_title' => $row['report_title'],
            'source_publish_date' => $row['publish_date'],
            // id de la ligne company_report_analyses elle-même (pas du rapport) — nécessaire pour cibler une
            // suppression précise (voir api_report_analysis.php, action 'delete') sans ambiguïté entre les
            // différentes analyses d'un même rapport.
            'source_analysis_id' => isset($row['analysis_id']) ? (int) $row['analysis_id'] : null,
            // Fournisseur/modèle IA ayant produit CETTE analyse précise — un même
            // rapport peut avoir été analysé par plusieurs IA (voir dedupeByReportId()
            // et le filtre "IA" du frontend, qui permet de comparer leurs extractions).
            'source_provider' => $row['provider'] ?? null,
            'source_model' => $row['model'] ?? null,
            'market_context_date' => $row['market_context_date'],
            'market_price' => $marketPrice,
            'currency' => $financials['currency'] ?? null,

            // Compte de résultat (montants bruts + marges déjà calculées par l'IA)
            'revenue' => $revenue,
            'revenue_prior_year' => $this->toFloatOrNull($financials['revenue_prior_year'] ?? null),
            'revenue_growth_percent' => $revenueGrowth,
            'gross_profit' => $this->toFloatOrNull($financials['gross_profit'] ?? null),
            'gross_margin_percent' => $this->toFloatOrNull($financials['gross_margin_percent'] ?? null),
            'operating_income' => $operatingIncome,
            'operating_margin_percent' => $this->toFloatOrNull($financials['operating_margin_percent'] ?? null),
            'ebitda' => $ebitda,
            'ebitda_margin_percent' => $this->toFloatOrNull($financials['ebitda_margin_percent'] ?? null),
            'net_income' => $netIncome,
            'net_income_prior_year' => $netIncomePriorYear,
            'net_income_growth_percent' => $netIncomeGrowth,
            'net_margin_percent' => $this->toFloatOrNull($financials['net_margin_percent'] ?? null),

            // Rentabilité
            'roe_percent' => $this->toFloatOrNull($financials['roe_percent'] ?? null),
            'roa_percent' => $roaPercent,

            // Rotation / efficacité (calculés — voir commentaire ci-dessus sur l'identité de DuPont et l'inversion des délais déjà extraits)
            'asset_turnover' => $assetTurnover,
            'receivable_turnover' => $receivableTurnover,
            'payable_turnover' => $payableTurnover,
            'inventory_turnover' => $inventoryTurnover,

            // Croissance historique (CAGR + séries complètes calculés sur tout l'historique d'analyses réussies de cette entreprise, voir computeGrowthMetrics())
            'historical_reports_count' => $growth['historical_reports_count'],
            'revenue_cagr' => $growth['revenue_cagr'],
            'net_income_cagr' => $growth['net_income_cagr'],
            'dividend_cagr' => $growth['dividend_cagr'],
            'revenue_history' => $growth['revenue_history'],
            'net_income_history' => $growth['net_income_history'],
            'dividend_history' => $growth['dividend_history'],
            'total_dividend_history' => $growth['total_dividend_history'],
            // Sourcés des bulletins BRVM (BulletinCorporateActionsService), pas des rapports — voir
            // fetchBulletinDividendActions()/bulletinDividendSeries() : indépendant de dividend_history ci-dessus.
            'bulletin_dividend_history' => $bulletinDividendHistory,
            'combined_dividend_history' => $combinedDividendHistory,

            // Structure financière / solvabilité
            'total_debt' => $totalDebt,
            'total_equity' => $totalEquity,
            'cash_position' => $cashPosition,
            'net_debt' => $netDebt,
            'debt_to_equity' => $this->toFloatOrNull($financials['debt_to_equity'] ?? null),
            'net_debt_to_equity' => $netDebtToEquity,
            'debt_to_ebitda' => $this->toFloatOrNull($financials['debt_to_ebitda'] ?? null),
            'net_debt_to_ebitda' => $netDebtToEbitda,
            'interest_expense' => $this->toFloatOrNull($financials['interest_expense'] ?? null),
            'interest_coverage_ratio' => $this->toFloatOrNull($financials['interest_coverage_ratio'] ?? null),

            // Liquidité / BFR
            'current_assets' => $this->toFloatOrNull($financials['current_assets'] ?? null),
            'current_liabilities' => $this->toFloatOrNull($financials['current_liabilities'] ?? null),
            'current_ratio' => $this->toFloatOrNull($financials['current_ratio'] ?? null),
            'quick_ratio' => $this->toFloatOrNull($financials['quick_ratio'] ?? null),
            'working_capital' => $this->toFloatOrNull($financials['working_capital'] ?? null),
            'receivable_days' => $this->toFloatOrNull($financials['receivable_days'] ?? null),
            'payable_days' => $this->toFloatOrNull($financials['payable_days'] ?? null),
            'inventory_days' => $this->toFloatOrNull($financials['inventory_days'] ?? null),

            // Cash-flow
            'operating_cash_flow' => $operatingCashFlow,
            'capex' => $this->toFloatOrNull($financials['capex'] ?? null),
            'free_cash_flow' => $freeCashFlow,
            'fcf_yield_percent' => $fcfYield,

            // Par action
            'shares_outstanding' => $sharesOutstanding,
            'eps' => $this->toFloatOrNull($valuation['eps'] ?? null),
            'book_value_per_share' => $this->toFloatOrNull($valuation['book_value_per_share'] ?? null),
            'dividend_per_share' => $dividendPerShare,

            // Valorisation (marché entier + par action)
            'market_cap' => $marketCap !== null ? round($marketCap, 2) : null,
            'enterprise_value' => $enterpriseValue !== null ? round($enterpriseValue, 2) : null,
            'pe_ratio' => $peRatio,
            'peg_ratio' => $pegRatio,
            'peg_earnings_ratio' => $pegEarningsRatio,
            'price_to_book' => $priceToBook,
            'per_pbr_product' => $perPbrProduct,
            'price_to_sales' => $priceToSales,
            'price_to_cash_flow' => $priceToCashFlow,
            'ev_to_ebitda' => $this->toFloatOrNull($valuation['ev_to_ebitda'] ?? null),
            'ev_to_ebit' => $evToEbit,
            'ev_to_sales' => $evToSales,
            'ev_to_fcf' => $evToFcf,

            // Flottant
            'free_float_percent' => $freeFloatPercent,
            'free_float_shares' => $freeFloatShares,
            'free_float_market_cap' => $freeFloatMarketCap,

            // Dividende
            'dividend_yield_percent' => $this->toFloatOrNull($valuation['dividend_yield_percent'] ?? null),
            'payout_ratio_percent' => $payoutRatio,
            'retention_ratio_percent' => $retentionRatio,
            'dividend_coverage' => $dividendCoverage,

            'valuation_verdict' => $valuation['verdict'] ?? null,
            'valuation_rationale' => $valuation['rationale'] ?? null,
        ];
    }

    /**
     * Comparables sectoriels — pour chaque entreprise, compare ses propres
     * multiples de valorisation à la MÉDIANE de son secteur (calculée sur
     * les autres entreprises du même secteur ayant une donnée pour ce
     * multiple précis, elle exclue) : un signal relatif à ses pairs de la
     * BRVM plutôt qu'un jugement absolu ("cher" ou "pas cher" n'a de sens
     * que par rapport à quelque chose). Médiane plutôt que moyenne : peu
     * sensible aux valeurs extrêmes, plus robuste avec un petit nombre
     * d'entreprises par secteur.
     *
     * 'sector_peer_count' donne toujours le nombre de PAIRS (donc hors
     * l'entreprise elle-même) disponibles pour au moins un multiple — avec
     * moins de 2 pairs, tous les champs sector_median_xxx et xxx_vs_sector
     * sont laissés null plutôt que de comparer une entreprise à elle-même
     * ou à un seul autre point, ce qui ne serait pas une vraie médiane.
     */
    private function applySectorComparables(array $result): array {
        $bySector = [];
        foreach ($result as $row) {
            if ($row['sector_id'] !== null) {
                $bySector[$row['sector_id']][] = $row;
            }
        }

        $metrics = ['pe_ratio', 'price_to_book', 'ev_to_ebitda', 'dividend_yield_percent'];

        return array_map(function ($row) use ($bySector, $metrics) {
            $sectorId = $row['sector_id'];
            $peers = $sectorId !== null
                ? array_filter($bySector[$sectorId], fn($r) => $r['company_id'] !== $row['company_id'])
                : [];
            $row['sector_peer_count'] = count($peers);

            foreach ($metrics as $metric) {
                $peerValues = array_values(array_filter(
                    array_map(fn($r) => $r[$metric], $peers),
                    fn($v) => $v !== null
                ));
                $median = count($peerValues) >= 2 ? $this->median($peerValues) : null;

                $row["sector_median_$metric"] = $median !== null ? round($median, 2) : null;
                $row["{$metric}_vs_sector_percent"] = ($median !== null && $row[$metric] !== null && $median != 0)
                    ? round((($row[$metric] - $median) / abs($median)) * 100, 1)
                    : null;
            }

            return $row;
        }, $result);
    }

    private function median(array $values): float {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];
    }

    /**
     * Extrait les séries chronologiques CA/résultat net/dividende par
     * action de tout l'historique d'analyses réussies d'une entreprise
     * ($history, déjà trié par date de publication croissante), et calcule
     * un CAGR pour chacune — voir cagrDetails() pour la formule et les
     * conditions qui la rendent applicable ou non.
     */
    private function computeGrowthMetrics(array $history): array {
        $revenueSeries = [];
        $netIncomeSeries = [];
        $dividendSeries = [];
        $totalDividendSeries = [];

        foreach ($history as $row) {
            $details = json_decode($row['details'] ?? 'null', true) ?: [];
            $financials = $details['key_financials'] ?? [];
            $valuation = $details['valuation_assessment'] ?? [];

            // Date de FIN DE PÉRIODE couverte par ces chiffres (ex. "exercice
            // clos le 31/12/2024" -> 2024-12-31), extraite par l'IA — jamais
            // la date de publication du rapport, souvent plusieurs mois après
            // la clôture de l'exercice. Repli sur publish_date pour les
            // analyses faites avant l'ajout de ce champ, ou si l'IA n'a pas
            // pu la déterminer.
            $date = $this->resolvePeriodDate($financials['period_end_date'] ?? null, $row['publish_date'] ?? null);
            if (!$date) {
                continue;
            }
            // report_type inclus dans chaque point : permet au frontend de filtrer une série sur un seul type
            // de rapport (ex. "rapports annuels" uniquement) — mélanger trimestriel/semestriel/annuel dans le
            // même graphe produit une courbe en dents de scie qui n'a pas de sens (périodes de durées différentes).
            $point = ['date' => $date, 'report_id' => (int) $row['report_id'], 'report_title' => $row['report_title'], 'report_type' => $row['report_type']];

            $revenue = $this->toFloatOrNull($financials['revenue'] ?? null);
            if ($revenue !== null) {
                $revenueSeries[] = $point + ['value' => $revenue];
            }
            $netIncome = $this->toFloatOrNull($financials['net_income'] ?? null);
            if ($netIncome !== null) {
                $netIncomeSeries[] = $point + ['value' => $netIncome];
            }
            $dividend = $this->toFloatOrNull($financials['dividend_per_share'] ?? null);
            if ($dividend !== null) {
                $dividendSeries[] = $point + ['value' => $dividend];
            }
            // Dividende TOTAL versé par l'entreprise (montant global, même
            // échelle que CA/résultat net) — distinct du dividende PAR ACTION
            // ci-dessus (échelle FCFA/action) : dividend_per_share × nombre
            // d'actions en circulation, nécessite les deux pour ce rapport.
            $sharesOutstanding = $this->toFloatOrNull($valuation['shares_outstanding'] ?? null);
            if ($dividend !== null && $sharesOutstanding !== null) {
                $totalDividendSeries[] = $point + ['value' => $dividend * $sharesOutstanding];
            }
        }

        // Le "date" de chaque point est désormais la fin de période (voir
        // resolvePeriodDate()), pas forcément dans le même ordre que
        // $history (trié par publish_date) — un rapport publié plus tard
        // peut couvrir une période antérieure à un autre déjà publié. Retri
        // explicite indispensable : cagrDetails() et les graphes supposent
        // un ordre chronologique strict par date de période.
        $byDate = fn($a, $b) => $a['date'] <=> $b['date'];
        usort($revenueSeries, $byDate);
        usort($netIncomeSeries, $byDate);
        usort($dividendSeries, $byDate);
        usort($totalDividendSeries, $byDate);

        return [
            'historical_reports_count' => count($history),
            'revenue_cagr' => $this->cagrDetails($revenueSeries),
            'net_income_cagr' => $this->cagrDetails($netIncomeSeries),
            'dividend_cagr' => $this->cagrDetails($dividendSeries),
            // Séries complètes (pas seulement le premier/dernier point utilisé par le CAGR) — pour tracer un
            // graphe de l'évolution dans le temps plutôt qu'un simple chiffre de croissance annualisée.
            'revenue_history' => $revenueSeries,
            'net_income_history' => $netIncomeSeries,
            'dividend_history' => $dividendSeries,
            'total_dividend_history' => $totalDividendSeries,
        ];
    }

    /**
     * CAGR = (valeur finale / valeur initiale)^(1/années) - 1, entre le
     * premier et le dernier point de $series (déjà en ordre chronologique
     * croissant, un point = une date de rapport + une valeur non nulle de
     * la métrique). Retourne toujours start_date/end_date/years pour la
     * transparence, même quand cagr_percent est null — permet au frontend
     * d'expliquer POURQUOI un CAGR est indisponible (pas assez de points,
     * période trop courte, valeur de départ négative ou nulle) plutôt que
     * de simplement afficher un tiret sans justification.
     *
     * cagr_percent volontairement null (pas de repli sur une valeur
     * absolue trompeuse) si :
     *   - moins de 2 points disponibles ou même date de rapport aux deux bouts,
     *   - écart entre les deux dates < MIN_CAGR_SPAN_DAYS (annualiser un
     *     intervalle trop court n'a pas de sens),
     *   - valeur de départ <= 0 (un CAGR sur un résultat net qui était une
     *     perte, ou nul, n'est pas mathématiquement interprétable de la
     *     même façon qu'une croissance normale).
     */
    private function cagrDetails(array $series): array {
        if (count($series) < 2) {
            return ['cagr_percent' => null, 'start_date' => null, 'end_date' => null, 'years' => null];
        }

        $first = $series[0];
        $last = $series[count($series) - 1];

        if ($first['date'] === $last['date']) {
            return ['cagr_percent' => null, 'start_date' => $first['date'], 'end_date' => $last['date'], 'years' => null];
        }

        $days = (strtotime($last['date']) - strtotime($first['date'])) / 86400;
        $years = round($days / 365.25, 2);

        if ($days < self::MIN_CAGR_SPAN_DAYS || $first['value'] <= 0 || $last['value'] <= 0) {
            return ['cagr_percent' => null, 'start_date' => $first['date'], 'end_date' => $last['date'], 'years' => $years];
        }

        $cagr = (pow($last['value'] / $first['value'], 1 / $years) - 1) * 100;

        return ['cagr_percent' => round($cagr, 2), 'start_date' => $first['date'], 'end_date' => $last['date'], 'years' => $years];
    }

    private function toFloatOrNull($value) {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }

    /**
     * Valide period_end_date (format YYYY-MM-DD strict, extrait par l'IA du
     * texte du rapport) et l'utilise si valide ; sinon retombe sur
     * publish_date. Validation stricte (DateTime::createFromFormat + re-
     * formatage identique) plutôt que strtotime() seul, qui accepterait
     * aussi des formats ambigus ou partiels que l'IA n'est pas censée
     * produire selon le prompt (voir ReportAnalysisService::buildPrompt()).
     */
    private function resolvePeriodDate(?string $periodEndDate, ?string $publishDate): ?string {
        if ($periodEndDate) {
            $parsed = DateTime::createFromFormat('Y-m-d', $periodEndDate);
            if ($parsed && $parsed->format('Y-m-d') === $periodEndDate) {
                return $periodEndDate;
            }
        }
        return $publishDate ?: null;
    }
}

// Exécution
$api = new FundamentalsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
