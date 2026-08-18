<?php
/**
 * Rapprochement d'une grille de tableur avec les postes d'un format d'état
 * financier (voir FinancialStatementSchemas).
 *
 * Objectif : UN SEUL fichier passe-partout. L'utilisateur exporte ou
 * recopie son document tel quel — une colonne de libellés, une ou plusieurs
 * colonnes de montants — et c'est le format sélectionné à l'écran qui
 * détermine à quels postes ces libellés correspondent. Le même fichier peut
 * donc servir pour n'importe quel onglet.
 *
 * Rien n'est enregistré directement : le rapprochement est PROPOSÉ, avec un
 * indice de confiance ligne par ligne, et l'utilisateur corrige avant
 * d'enregistrer. Un libellé mal reconnu serait sinon une fausse donnée
 * indétectable.
 */
class StatementImportMatcher {

    /** En dessous, on considère qu'aucune correspondance n'a été trouvée. */
    private const MIN_SCORE = 0.62;

    /**
     * Mots vides du vocabulaire comptable : ils gonflent artificiellement la
     * ressemblance entre deux libellés pourtant différents.
     */
    private const STOPWORDS = ['de', 'des', 'du', 'la', 'le', 'les', 'et', 'en', 'a', 'au', 'aux', 'sur', 'par', 'ou', 'd', 'l'];

    /**
     * @return array{
     *   columns: array<int,array>, label_column: int,
     *   rows: array<int,array>, matched: int, unmatched: int,
     *   lines: array<string,string>
     * }
     */
    public static function analyze(array $grid, string $statementType): array {
        $schema = FinancialStatementSchemas::get($statementType);

        // Postes du format, avec leurs libellés et leurs clés.
        $targets = [];
        foreach ($schema['groups'] as $group) {
            foreach ($group['lines'] as $line) {
                $targets[$line['key']] = [
                    'key' => $line['key'],
                    'label' => $line['label'],
                    'group' => $group['label'],
                    'sign' => $line['sign'],
                    'norm' => self::normalize($line['label']),
                    'tokens' => self::tokens($line['label']),
                ];
            }
        }

        // Les SOUS-TOTAUX imprimés dans le document ne sont pas des postes à
        // importer (ils sont recalculés) — mais les reconnaître permet deux
        // choses : ne pas les présenter comme des lignes non reconnues, et
        // s'en servir pour VÉRIFIER le calcul après import.
        $subtotalTargets = [];
        foreach ($schema['subtotals'] as $subtotal) {
            $subtotalTargets[$subtotal['key']] = [
                'key' => $subtotal['key'],
                'label' => $subtotal['label'],
                'norm' => self::normalize($subtotal['label']),
                'tokens' => self::tokens($subtotal['label']),
            ];
        }

        list($labelColumn, $valueColumns) = self::detectColumns($grid);

        $rows = [];
        $used = [];
        foreach ($grid as $index => $cells) {
            $label = isset($cells[$labelColumn]) ? trim((string) $cells[$labelColumn]) : '';
            if ($label === '') {
                continue;
            }
            $values = [];
            $hasNumber = false;
            foreach ($valueColumns as $col) {
                $raw = isset($cells[$col]) ? (string) $cells[$col] : '';
                $number = self::parseNumber($raw);
                $values[$col] = $number;
                if ($number !== null) {
                    $hasNumber = true;
                }
            }
            // Une ligne sans aucun montant est un titre de section ou une
            // ligne vide : inutile de la proposer au rapprochement.
            if (!$hasNumber) {
                continue;
            }

            $match = ['key' => null, 'score' => 0.0];   // affecté globalement plus bas
            $subtotalMatch = self::bestMatch($label, $subtotalTargets);

            // Un libellé qui ressemble davantage à un sous-total qu'à un
            // poste EST un sous-total : l'importer comme poste fausserait
            // tout (le montant serait compté deux fois).
            $isSubtotal = $subtotalMatch['key'] !== null && $subtotalMatch['score'] >= $match['score'];

            $rows[] = [
                'source_row' => $index + 1,
                'label' => $label,
                'values' => $values,
                'kind' => $isSubtotal ? 'subtotal' : 'line',
                'matched_key' => $isSubtotal ? null : $match['key'],
                'matched_label' => $isSubtotal ? null : ($match['key'] !== null ? $targets[$match['key']]['label'] : null),
                'matched_group' => $isSubtotal ? null : ($match['key'] !== null ? $targets[$match['key']]['group'] : null),
                'sign' => $isSubtotal ? null : ($match['key'] !== null ? $targets[$match['key']]['sign'] : null),
                'subtotal_key' => $isSubtotal ? $subtotalMatch['key'] : null,
                'subtotal_label' => $isSubtotal ? $subtotalTargets[$subtotalMatch['key']]['label'] : null,
                'score' => round($isSubtotal ? $subtotalMatch['score'] : $match['score'], 3),
                'confidence' => $isSubtotal ? 'sous_total' : self::confidenceLabel($match['score']),
            ];
            if (!$isSubtotal && $match['key'] !== null) {
                $used[$match['key']] = ($used[$match['key']] ?? 0) + 1;
            }
        }

        // --- Affectation GLOBALE ligne <-> poste -------------------------
        //
        // Un rapprochement ligne par ligne, chacune prenant son meilleur
        // score, attribue le même poste à deux lignes voisines dès que leurs
        // libellés se ressemblent — cas réel : « Achats de matières
        // premières ET FOURNITURES LIÉES » et « Variation de stocks de
        // matières premières et fournitures liées » partagent quatre mots
        // sur six. La valeur de l'une écrasait alors celle de l'autre.
        //
        // On calcule donc tous les scores possibles, on les trie du meilleur
        // au moins bon, et on attribue chaque poste à UNE SEULE ligne (et
        // chaque ligne à un seul poste) : la meilleure paire l'emporte, la
        // suivante se rabat sur son second choix.
        $candidates = [];
        foreach ($rows as $i => $row) {
            if ($row['kind'] === 'subtotal') {
                continue;
            }
            foreach ($targets as $key => $target) {
                $score = self::score($row['label'], $target);
                if ($score >= self::MIN_SCORE) {
                    $candidates[] = ['row' => $i, 'key' => $key, 'score' => $score];
                }
            }
        }
        usort($candidates, static function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $takenRows = [];
        $takenKeys = [];
        foreach ($candidates as $c) {
            if (isset($takenRows[$c['row']]) || isset($takenKeys[$c['key']])) {
                continue;
            }
            $takenRows[$c['row']] = true;
            $takenKeys[$c['key']] = true;
            $target = $targets[$c['key']];
            $rows[$c['row']]['matched_key'] = $c['key'];
            $rows[$c['row']]['matched_label'] = $target['label'];
            $rows[$c['row']]['matched_group'] = $target['group'];
            $rows[$c['row']]['sign'] = $target['sign'];
            $rows[$c['row']]['score'] = round($c['score'], 3);
            $rows[$c['row']]['confidence'] = self::confidenceLabel($c['score']);
            $used[$c['key']] = 1;
        }

        // L'affectation globale rend les doublons impossibles ; la
        // vérification reste par sécurité, si une évolution future la
        // réintroduisait.
        $duplicates = [];
        foreach ($used as $key => $count) {
            if ($count > 1) {
                $duplicates[] = $targets[$key]['label'];
            }
        }

        $columns = [];
        foreach ($valueColumns as $col) {
            $columns[] = [
                'index' => $col,
                'header' => self::columnHeader($grid, $col),
                'filled' => self::countNumbers($grid, $col),
            ];
        }

        $matched = 0;
        $subtotalRows = 0;
        $unmatched = 0;
        foreach ($rows as $r) {
            if ($r['kind'] === 'subtotal') {
                $subtotalRows++;
            } elseif ($r['matched_key'] !== null) {
                $matched++;
            } else {
                $unmatched++;
            }
        }

        return [
            'statement_type' => $statementType,
            'statement_label' => $schema['label'],
            'label_column' => $labelColumn,
            'columns' => $columns,
            'rows' => $rows,
            'matched' => $matched,
            'unmatched' => $unmatched,
            'subtotal_rows' => $subtotalRows,
            'duplicates' => $duplicates,
            'available_lines' => array_map(static function ($t) {
                return ['key' => $t['key'], 'label' => $t['label'], 'group' => $t['group']];
            }, array_values($targets)),
        ];
    }

    // ------------------------------------------------------------------

    /**
     * Colonne des libellés = celle qui contient le plus de texte non
     * numérique ; colonnes de montants = celles qui contiennent des nombres.
     * Les documents financiers ont souvent DEUX colonnes (exercice N et
     * N-1) : on les renvoie toutes, l'utilisateur choisit.
     */
    private static function detectColumns(array $grid): array {
        $textCount = [];
        $numberCount = [];
        foreach ($grid as $cells) {
            foreach ($cells as $col => $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }
                if (self::parseNumber($value) !== null) {
                    $numberCount[$col] = ($numberCount[$col] ?? 0) + 1;
                } elseif (mb_strlen($value) >= 3) {
                    $textCount[$col] = ($textCount[$col] ?? 0) + 1;
                }
            }
        }
        arsort($textCount);
        $labelColumn = !empty($textCount) ? (int) array_key_first($textCount) : 0;

        $valueColumns = [];
        foreach ($numberCount as $col => $count) {
            // Au moins 3 nombres : écarte une colonne qui ne contient qu'un
            // numéro de page ou une année isolée.
            if ((int) $col !== $labelColumn && $count >= 3) {
                $valueColumns[] = (int) $col;
            }
        }
        sort($valueColumns);
        if (empty($valueColumns) && !empty($numberCount)) {
            $valueColumns = array_map('intval', array_keys($numberCount));
            sort($valueColumns);
        }
        return [$labelColumn, $valueColumns];
    }

    /** En-tête d'une colonne : première cellule non numérique rencontrée. */
    private static function columnHeader(array $grid, int $col): string {
        foreach ($grid as $cells) {
            $value = isset($cells[$col]) ? trim((string) $cells[$col]) : '';
            if ($value !== '' && self::parseNumber($value) === null) {
                return mb_substr($value, 0, 60);
            }
        }
        return 'Colonne ' . self::columnLetter($col);
    }

    private static function columnLetter(int $index): string {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = (int) (($index - $mod) / 26);
        }
        return $letter;
    }

    private static function countNumbers(array $grid, int $col): int {
        $n = 0;
        foreach ($grid as $cells) {
            if (isset($cells[$col]) && self::parseNumber((string) $cells[$col]) !== null) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Nombres tels qu'on les trouve dans un état financier francophone :
     * « 129 854 698 964 », « -1 820 046 620 », « 1 234,56 », « (1 234) »
     * (parenthèses = négatif, convention comptable), espaces insécables.
     */
    public static function parseNumber(string $raw) {
        $s = trim($raw);
        if ($s === '' || $s === '-' || $s === '—') {
            return null;
        }
        $s = str_replace(["\xC2\xA0", "\xE2\x80\xAF", ' ', "'"], '', $s);
        $s = str_replace(['F', 'FCFA', 'XOF', '%'], '', $s);

        $negative = false;
        if (preg_match('/^\((.*)\)$/', $s, $m)) {
            $negative = true;
            $s = $m[1];
        }
        // Virgule décimale française, mais seulement si elle n'est pas un
        // séparateur de milliers (suivi de trois chiffres puis fin).
        if (preg_match('/,\d{1,2}$/', $s)) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
        if (!preg_match('/^[+-]?\d+(\.\d+)?$/', $s)) {
            return null;
        }
        $value = (float) $s;
        return $negative ? -$value : $value;
    }

    /** Minuscules, sans accents ni ponctuation, espaces normalisés. */
    private static function normalize(string $s): string {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $from = ['à','á','â','ä','ã','å','ç','è','é','ê','ë','ì','í','î','ï','ñ','ò','ó','ô','ö','õ','ù','ú','û','ü','ý','ÿ','œ','æ'];
        $to   = ['a','a','a','a','a','a','c','e','e','e','e','i','i','i','i','n','o','o','o','o','o','u','u','u','u','y','y','oe','ae'];
        $s = str_replace($from, $to, $s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** @return string[] mots significatifs d'un libellé */
    private static function tokens(string $s): array {
        $words = explode(' ', self::normalize($s));
        $out = [];
        foreach ($words as $w) {
            if ($w !== '' && !in_array($w, self::STOPWORDS, true)) {
                $out[] = $w;
            }
        }
        return $out;
    }

    /**
     * Meilleur poste correspondant à un libellé du fichier.
     *
     * Trois signaux combinés : égalité exacte après normalisation (score
     * plein), proportion de mots communs (l'essentiel en pratique, les
     * libellés comptables variant surtout par des mots de liaison), et
     * ressemblance globale des chaînes pour rattraper abréviations et
     * fautes de frappe.
     */
    /**
     * Score de ressemblance entre un libellé de fichier et un poste cible.
     *
     * Un libellé réel porte souvent une annotation finale entre parenthèses
     * — « (Note 5) », « (à saisir en négatif) », « (1) » — qui n'existe pas
     * dans le poste cible et dilue la ressemblance. On essaie donc aussi la
     * version sans cette annotation, en gardant le meilleur des deux ; les
     * parenthèses PORTEUSES de sens, comme « Commissions (produits) », sont
     * préservées puisque c'est le meilleur score qui l'emporte.
     */
    private static function score(string $label, array $target): float {
        $stripped = preg_replace('/\s*\([^)]*\)\s*$/u', '', $label);
        if ($stripped !== null && $stripped !== '' && $stripped !== $label) {
            return max(self::scoreExact($label, $target), self::scoreExact($stripped, $target));
        }
        return self::scoreExact($label, $target);
    }

    private static function scoreExact(string $label, array $target): float {
        $norm = self::normalize($label);
        if ($norm === '') {
            return 0.0;
        }
        if ($norm === $target['norm'] || $norm === self::normalize($target['key'])) {
            return 1.0;
        }
        $tokens = self::tokens($label);
        $tokenScore = 0.0;
        if (!empty($tokens) && !empty($target['tokens'])) {
            $common = array_intersect($tokens, $target['tokens']);
            $tokenScore = count($common) / max(count($tokens), count($target['tokens']));
        }
        $percent = 0.0;
        similar_text($norm, $target['norm'], $percent);
        return 0.65 * $tokenScore + 0.35 * ($percent / 100);
    }

    private static function bestMatch(string $label, array $targets): array {
        $norm = self::normalize($label);
        $tokens = self::tokens($label);
        if ($norm === '') {
            return ['key' => null, 'score' => 0.0];
        }

        $bestKey = null;
        $bestScore = 0.0;
        foreach ($targets as $target) {
            if ($norm === $target['norm']) {
                return ['key' => $target['key'], 'score' => 1.0];
            }
            // Le fichier peut porter directement la clé technique (export
            // depuis cette application) : correspondance certaine.
            if ($norm === self::normalize($target['key'])) {
                return ['key' => $target['key'], 'score' => 1.0];
            }

            $common = array_intersect($tokens, $target['tokens']);
            $tokenScore = 0.0;
            if (!empty($tokens) && !empty($target['tokens'])) {
                $tokenScore = count($common) / max(count($tokens), count($target['tokens']));
            }

            $percent = 0.0;
            similar_text($norm, $target['norm'], $percent);
            $textScore = $percent / 100;

            $score = 0.65 * $tokenScore + 0.35 * $textScore;
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestKey = $target['key'];
            }
        }

        if ($bestScore < self::MIN_SCORE) {
            return ['key' => null, 'score' => $bestScore];
        }
        return ['key' => $bestKey, 'score' => $bestScore];
    }

    /**
     * Lecture du MODÈLE TABULAIRE : une LIGNE = un état financier, les
     * champs en COLONNES — la disposition d'un export de base de données.
     *
     * La première ligne porte les noms techniques (statement_type,
     * period_end_date, puis une colonne par poste). Toute ligne suivante
     * dont statement_type est un format connu devient un état. Une ligne de
     * libellés lisibles peut suivre l'en-tête : sans montant ni format
     * valide, elle est ignorée d'elle-même.
     *
     * Le rapprochement est ici EXACT (nom de colonne = clé de poste), donc
     * sans ambiguïté : c'est ce qui rend ce format préférable au
     * rapprochement par ressemblance, réservé aux fichiers libres.
     *
     * @return array|null null si le fichier n'a pas cette structure
     */
    public static function analyzeTabular(array $grid) {
        $headerIndex = null;
        $header = [];
        foreach ($grid as $i => $cells) {
            $normalized = array_map(static function ($c) {
                return mb_strtolower(trim((string) $c));
            }, $cells);
            if (in_array('statement_type', $normalized, true) && in_array('period_end_date', $normalized, true)) {
                $headerIndex = $i;
                $header = $normalized;
                break;
            }
        }
        if ($headerIndex === null) {
            return null;   // fichier libre : rapprochement par ressemblance
        }

        $columnOf = [];
        foreach ($header as $col => $name) {
            if ($name !== '') {
                $columnOf[$name] = $col;
            }
        }
        $schemas = FinancialStatementSchemas::types();
        $statements = [];

        foreach (array_slice($grid, $headerIndex + 1, null, true) as $rowIndex => $cells) {
            $get = function ($name) use ($cells, $columnOf) {
                if (!isset($columnOf[$name])) {
                    return '';
                }
                $col = $columnOf[$name];
                return isset($cells[$col]) ? trim((string) $cells[$col]) : '';
            };

            $type = $get('statement_type');
            if ($type === '' || !isset($schemas[$type])) {
                // Ligne de libellés, notice en fin de fichier, ligne vide :
                // tout ce qui ne porte pas un format connu est ignoré, sans
                // bruit — sauf si un format est écrit mais inconnu.
                // On ne signale un format inconnu que si la ligne porte de
                // vrais montants : sinon c'est la ligne de libellés ou une
                // note, et l'annoncer comme une erreur serait du bruit.
                $hasNumber = false;
                foreach ($cells as $cell) {
                    if (self::parseNumber((string) $cell) !== null) {
                        $hasNumber = true;
                        break;
                    }
                }
                if ($type !== '' && strpos($type, '#') !== 0 && $hasNumber) {
                    $statements[] = [
                        'source_row' => $rowIndex + 1,
                        'statement_type' => $type,
                        'error' => "Format inconnu : « $type ». Utilisez la valeur d'origine de la colonne statement_type.",
                    ];
                }
                continue;
            }

            $schema = $schemas[$type];
            $values = [];
            $documentSubtotals = [];
            $ignored = [];

            foreach ($schema['groups'] as $group) {
                foreach ($group['lines'] as $line) {
                    $raw = $get($line['key']);
                    $number = self::parseNumber($raw);
                    if ($number !== null) {
                        $values[$line['key']] = $number;
                    } elseif ($raw !== '') {
                        $ignored[] = $line['key'] . ' = « ' . mb_substr($raw, 0, 20) . ' »';
                    }
                }
            }
            foreach ($schema['subtotals'] as $subtotal) {
                $number = self::parseNumber($get('controle_' . $subtotal['key']));
                if ($number !== null) {
                    $documentSubtotals[$subtotal['key']] = $number;
                }
            }

            $date = $get('period_end_date');
            $period = $get('period_type');
            $unit = self::parseNumber($get('unit_multiplier'));

            $error = null;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $error = "Date de clôture absente ou mal formée (« $date ») : attendu AAAA-MM-JJ.";
            } elseif (empty($values)) {
                $error = 'Aucun montant sur cette ligne.';
            }

            $statements[] = [
                'source_row' => $rowIndex + 1,
                'column_label' => 'Ligne ' . ($rowIndex + 1),
                'statement_type' => $type,
                'statement_label' => $schema['label'],
                'period_end_date' => $date,
                'period_type' => in_array($period, ['annuel', 'semestriel', 'trimestriel'], true) ? $period : 'annuel',
                'unit_multiplier' => in_array((int) $unit, [1, 1000, 1000000], true) ? (int) $unit : 1,
                'source_note' => $get('source_note') ?: null,
                'values' => $values,
                'document_subtotals' => $documentSubtotals,
                'lines_count' => count($values),
                'subtotals_count' => count($documentSubtotals),
                'unknown_labels' => $ignored,
                'error' => $error,
            ];
        }

        if (empty($statements)) {
            return null;
        }
        return [
            'mode' => 'tableau',
            'statements' => $statements,
            'ready' => count(array_filter($statements, static function ($s) {
                return empty($s['error']);
            })),
        ];
    }

    private static function confidenceLabel(float $score): string {
        if ($score >= 0.95) {
            return 'certaine';
        }
        if ($score >= 0.78) {
            return 'probable';
        }
        if ($score >= self::MIN_SCORE) {
            return 'a_verifier';
        }
        return 'aucune';
    }
}
