<?php
/**
 * Extraction DÉTERMINISTE (sans IA) de la table « Quantité résiduelle à
 * l'achat / Achat / Vente / Quantité résiduelle à la vente / Cours de
 * référence » de la section MARCHE DES ACTIONS du Bulletin Officiel de la
 * Cote — le carnet d'ordres de fin de séance (meilleures limites), seule
 * photographie du carnet publiée publiquement par la BRVM. Alimente
 * order_book_snapshots (voir TODO_CARNET_ORDRES.md).
 *
 * Formats de ligne réellement observés sur le corpus (18 bulletins, 844
 * lignes, 6 motifs — aucun autre) ; les colonnes sont séparées par 2+
 * espaces, les milliers par UN espace ou une virgule :
 *   ABJC  SERVAIR ABIDJAN CI   466   2,800 / 3,015    160     2 800   (complet)
 *   ETIT  ECOBANK ...      328 938   Marché /                    68   (achat au marché, vente vide)
 *   ETIT  ECOBANK ...                Marché / 1 781 761          64   (achat vide, vente au marché — motif inversé : Marché à droite)
 *   ORGT  ORAGROUP TOGO      2 310   2,955  /                 2 955   (vente vide)
 *   STAC  SETAO CI                          / 2,875   62      2 690   (achat vide)
 *   SEMC  EVIOSYS ...                       /                   700   (carnet vide des deux côtés)
 *
 * « Marché » = ordres sans prix limite (au marché) : quantité renseignée,
 * prix NULL, drapeau bid/ask_at_market. Ré-extraction = source de vérité
 * (les snapshots du bulletin sont remplacés, jamais additionnés).
 */
class BulletinOrderBookService {
    /** Heure conventionnelle des snapshots BOC : fin de séance. */
    private const SNAPSHOT_TIME = '14:30:00';

    private $crud;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
    }

    /**
     * Extrait le carnet d'un bulletin et remplace ses snapshots.
     *
     * @return array{bulletin_id:int, snapshots_count:int, unmatched_symbols:string[], anomalies:string[], status:string}
     */
    public function extract(int $bulletinId): array {
        $bulletin = $this->crud->findById('market_bulletins', $bulletinId);
        if (!$bulletin) {
            throw new Exception("Bulletin non trouvé (id=$bulletinId)");
        }
        $content = $this->crud->find('market_bulletin_contents', ['bulletin_id' => $bulletinId]);
        $text = $content[0]['extracted_text'] ?? null;
        if ($text === null || $text === '') {
            throw new Exception("Texte non extrait pour le bulletin $bulletinId — lancer d'abord le traitement du PDF");
        }

        $this->crud->merge('market_bulletin_contents',
            ['order_book_status' => 'processing', 'order_book_error' => null],
            ['bulletin_id' => $bulletinId]);

        try {
            $parsed = $this->parseText($text);
            $symbolMap = $this->companySymbolMap();
            $snapshotDatetime = $bulletin['publish_date'] . ' ' . self::SNAPSHOT_TIME;

            $unmatched = [];
            $rows = [];
            foreach ($parsed['rows'] as $r) {
                if (!isset($symbolMap[$r['symbol']])) {
                    $unmatched[] = $r['symbol'];
                    continue;
                }
                $rows[] = $this->buildSnapshotRow($symbolMap[$r['symbol']], $snapshotDatetime, $bulletinId, $r);
            }

            // Remplacement (jamais d'addition) : les snapshots de CE bulletin.
            // DynamiqueCrud::remove() ajoute un LIMIT 1 systématique (pensé
            // pour supprimer UNE ligne identifiée) : sur ~47 entreprises par
            // bulletin, il n'en supprimerait qu'une seule et la réextraction
            // échouerait en silence sur la contrainte UNIQUE uk_snapshot —
            // d'où un DELETE direct, sans limite, ici (même correctif que
            // BulletinStockMetricsService/BulletinCorporateActionsService/
            // BulletinBondMetricsService).
            $this->crud->executeCustomQuery("DELETE FROM order_book_snapshots WHERE bulletin_id = ?", [$bulletinId]);
            foreach ($rows as $row) {
                $this->crud->persist('order_book_snapshots', $row);
            }

            $this->crud->merge('market_bulletin_contents',
                ['order_book_status' => 'success', 'order_book_error' => null],
                ['bulletin_id' => $bulletinId]);

            return [
                'bulletin_id' => $bulletinId,
                'snapshots_count' => count($rows),
                'unmatched_symbols' => array_values(array_unique($unmatched)),
                'anomalies' => $parsed['anomalies'],
                'status' => 'success',
            ];
        } catch (Exception $e) {
            $this->crud->merge('market_bulletin_contents',
                ['order_book_status' => 'failed', 'order_book_error' => mb_substr($e->getMessage(), 0, 2000)],
                ['bulletin_id' => $bulletinId]);
            throw $e;
        }
    }

    /**
     * Extrait tous les bulletins qui ont un texte mais pas encore de carnet
     * (ou tous si $force) — le backfill et le rattrapage quotidien.
     */
    public function extractAll(bool $force = false): array {
        $where = $force ? '' : "AND (c.order_book_status IS NULL OR c.order_book_status = 'failed')";
        $bulletins = $this->crud->executeCustomQuery(
            "SELECT b.id FROM market_bulletins b
             JOIN market_bulletin_contents c ON c.bulletin_id = b.id
             WHERE c.extracted_text IS NOT NULL $where
             ORDER BY b.publish_date"
        ) ?: [];

        $results = [];
        foreach ($bulletins as $b) {
            try {
                $results[] = $this->extract((int) $b['id']);
            } catch (Exception $e) {
                $results[] = ['bulletin_id' => (int) $b['id'], 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }
        return $results;
    }

    /**
     * Découpe le texte du BOC et parse chaque ligne de la section MARCHE DES
     * ACTIONS. Les lignes hors des 6 motifs connus sont remontées dans
     * `anomalies` (jamais interprétées en silence).
     *
     * @return array{rows: array[], anomalies: string[]}
     */
    public function parseText(string $text): array {
        $lines = preg_split('/\R/u', $text);
        $rows = [];
        $anomalies = [];
        $inside = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === 'MARCHE DES ACTIONS') {
                $inside = true;
                continue;
            }
            if ($inside && (strpos($trimmed, 'MARCHE DES OBLIGATIONS') === 0 || strpos($trimmed, 'MARCHE DES DROITS') === 0)) {
                break;
            }
            if (!$inside) {
                continue;
            }
            if (!preg_match('/^([A-Z0-9]{3,7})\s{2,}(.+)$/', rtrim($line), $m)) {
                continue;
            }
            if (strpos($m[2], '/') === false) {
                continue; // ligne de nom/entête sans colonnes carnet
            }
            $parsedLine = $this->parseLine($m[1], $m[2]);
            if ($parsedLine === null) {
                $anomalies[] = mb_substr(trim($line), 0, 200);
                continue;
            }
            $rows[] = $parsedLine;
        }

        if (!$inside) {
            throw new Exception('Section « MARCHE DES ACTIONS » introuvable dans le texte du bulletin');
        }
        return ['rows' => $rows, 'anomalies' => $anomalies];
    }

    /**
     * Parse la partie après le symbole. Retourne null si la ligne ne
     * correspond à aucun des 6 motifs observés (elle part en anomalie).
     */
    private function parseLine(string $symbol, string $rest) {
        $slash = strpos($rest, '/');
        $leftRaw = substr($rest, 0, $slash);
        $rightRaw = substr($rest, $slash + 1);

        $left = $this->tokenize($leftRaw);
        // Retire les tokens du NOM de la société (non numériques, non « Marché »)
        while (!empty($left) && !$this->isNumericToken($left[0]) && !$this->isMarketToken($left[0])) {
            array_shift($left);
        }
        $right = $this->tokenize($rightRaw);

        $row = [
            'symbol' => $symbol,
            'bid_qty' => null, 'bid_price' => null, 'bid_at_market' => 0,
            'ask_qty' => null, 'ask_price' => null, 'ask_at_market' => 0,
            'reference_price' => null,
        ];

        // Côté achat : [] (vide) ou [qty, prix|Marché]
        if (count($left) === 2 && $this->isNumericToken($left[0])) {
            $row['bid_qty'] = $this->parseNumber($left[0]);
            if ($this->isMarketToken($left[1])) {
                $row['bid_at_market'] = 1;
            } elseif ($this->isNumericToken($left[1])) {
                $row['bid_price'] = $this->parseNumber($left[1]);
            } else {
                return null;
            }
        } elseif (count($left) !== 0) {
            return null;
        }

        // Côté vente + référence : [ref] (vente vide) ou [prix|Marché, qty, ref]
        if (count($right) === 1 && $this->isNumericToken($right[0])) {
            $row['reference_price'] = $this->parseNumber($right[0]);
        } elseif (count($right) === 3 && $this->isNumericToken($right[1]) && $this->isNumericToken($right[2])) {
            if ($this->isMarketToken($right[0])) {
                $row['ask_at_market'] = 1;
            } elseif ($this->isNumericToken($right[0])) {
                $row['ask_price'] = $this->parseNumber($right[0]);
            } else {
                return null;
            }
            $row['ask_qty'] = $this->parseNumber($right[1]);
            $row['reference_price'] = $this->parseNumber($right[2]);
        } else {
            return null;
        }

        return $row;
    }

    /** Ligne de snapshot prête pour persist(), avec les colonnes dérivées (🟨). */
    private function buildSnapshotRow(int $companyId, string $snapshotDatetime, int $bulletinId, array $r): array {
        $spreadAbs = null;
        $spreadPercent = null;
        if ($r['bid_price'] !== null && $r['ask_price'] !== null) {
            $spreadAbs = $r['ask_price'] - $r['bid_price'];
            $mid = ($r['ask_price'] + $r['bid_price']) / 2;
            $spreadPercent = $mid > 0 ? round($spreadAbs / $mid * 100, 4) : null;
        }
        $imbalance = null;
        if ($r['bid_qty'] !== null || $r['ask_qty'] !== null) {
            $bidQty = $r['bid_qty'] !== null ? $r['bid_qty'] : 0;
            $askQty = $r['ask_qty'] !== null ? $r['ask_qty'] : 0;
            $total = $bidQty + $askQty;
            $imbalance = $total > 0 ? round($bidQty / $total, 4) : null;
        }

        return [
            'company_id' => $companyId,
            'snapshot_datetime' => $snapshotDatetime,
            'source' => 'bulletin_boc',
            'bulletin_id' => $bulletinId,
            'best_bid_price' => $r['bid_price'],
            'best_ask_price' => $r['ask_price'],
            'bid_at_market' => $r['bid_at_market'],
            'ask_at_market' => $r['ask_at_market'],
            'bid_residual_qty' => $r['bid_qty'] !== null ? (int) round($r['bid_qty']) : null,
            'ask_residual_qty' => $r['ask_qty'] !== null ? (int) round($r['ask_qty']) : null,
            'reference_price' => $r['reference_price'],
            'spread_abs' => $spreadAbs,
            'spread_percent' => $spreadPercent,
            'imbalance_ratio' => $imbalance,
        ];
    }

    /** Colonnes séparées par 2+ espaces ; les milliers par UN espace/virgule. */
    private function tokenize(string $part): array {
        $part = str_replace("\xC2\xA0", ' ', $part); // espaces insécables du PDF
        $tokens = preg_split('/\s{2,}/', trim($part));
        return array_values(array_filter($tokens, static function ($t) {
            return $t !== '';
        }));
    }

    private function isNumericToken(string $t): bool {
        return preg_match('/^\d[\d\s,\.]*$/', $t) === 1;
    }

    private function isMarketToken(string $t): bool {
        // « Marché » — tolérant à l'accent perdu à l'extraction PDF
        return stripos($t, 'March') === 0;
    }

    /**
     * "2,800" et "1 781 761" = milliers ; une virgule suivie de 1-2 chiffres
     * en fin de nombre = décimale (défensif — les cours FCFA sont entiers).
     */
    private function parseNumber(string $s) {
        $s = str_replace(["\xC2\xA0", ' '], '', trim($s));
        if (preg_match('/,\d{1,2}$/', $s) && !preg_match('/,\d{3}$/', $s)) {
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
        return is_numeric($s) ? (float) $s : null;
    }

    /** @return array<string,int> symbole => company_id */
    private function companySymbolMap(): array {
        $companies = $this->crud->executeCustomQuery('SELECT id AS company_id, symbol FROM companies') ?: [];
        $map = [];
        foreach ($companies as $c) {
            $map[strtoupper(trim($c['symbol']))] = (int) $c['company_id'];
        }
        return $map;
    }
}
