<?php
/**
 * Lecture de tableurs SANS dépendance externe : CSV et XLSX.
 *
 * Un .xlsx est une archive ZIP de fichiers XML — ZipArchive et SimpleXML,
 * tous deux présents dans le PHP de MAMP comme sur l'hébergement, suffisent
 * donc à le lire. Cela évite d'imposer Composer/PhpSpreadsheet pour un
 * besoin simple : récupérer une grille de cellules.
 *
 * Le lecteur ne connaît RIEN aux états financiers : il rend une grille de
 * chaînes. L'interprétation (quelle colonne contient les libellés, quel
 * poste correspond à quelle ligne) est faite par StatementImportMatcher.
 */
class SpreadsheetReader {

    /** Nombre de lignes au-delà duquel on arrête : garde-fou mémoire. */
    private const MAX_ROWS = 2000;

    /**
     * @return array{rows: array<int,array<int,string>>, format: string, sheet: ?string}
     */
    public static function read(string $path, string $originalName = ''): array {
        $extension = strtolower(pathinfo($originalName !== '' ? $originalName : $path, PATHINFO_EXTENSION));
        if ($extension === 'xlsx' || $extension === 'xlsm') {
            return self::readXlsx($path);
        }
        if ($extension === 'csv' || $extension === 'txt' || $extension === 'tsv') {
            return self::readCsv($path);
        }
        // Extension absente ou inattendue : on regarde la signature du
        // fichier plutôt que de refuser sur un simple nom.
        $head = file_get_contents($path, false, null, 0, 4);
        if ($head !== false && strncmp($head, "PK\x03\x04", 4) === 0) {
            return self::readXlsx($path);
        }
        return self::readCsv($path);
    }

    // ------------------------------------------------------------------
    // CSV
    // ------------------------------------------------------------------

    private static function readCsv(string $path): array {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new Exception('Fichier illisible');
        }
        // BOM UTF-8 : sans le retirer, le premier libellé ne correspond
        // jamais lors du rapprochement.
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (!self::isUtf8($content)) {
            // Excel francophone exporte encore en Windows-1252 : sans
            // conversion, tous les accents deviennent illisibles.
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $lines = preg_split("/\r\n|\n|\r/", $content);
        $delimiter = self::detectDelimiter($lines);

        $rows = [];
        foreach ($lines as $line) {
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, $delimiter);
            $rows[] = array_map(static function ($c) {
                return trim((string) $c);
            }, $cells);
        }
        return ['rows' => $rows, 'format' => 'csv', 'sheet' => null];
    }

    private static function isUtf8(string $s): bool {
        return (bool) preg_match('//u', $s);
    }

    /**
     * Délimiteur du CSV, choisi sur la RÉGULARITÉ du découpage et non sur le
     * simple nombre d'occurrences.
     *
     * Compter les occurrences échoue dès qu'une ligne de texte contient des
     * virgules : « marge commerciale, valeur ajoutée, EBE, résultat… » suffit
     * à faire élire la virgule sur un fichier pourtant séparé par des
     * points-virgules, et plus rien n'est découpé. Le bon délimiteur est
     * celui qui produit un MÊME nombre de colonnes (supérieur à 1) sur le
     * plus grand nombre de lignes.
     */
    private static function detectDelimiter(array $lines): string {
        $sample = [];
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $sample[] = $line;
            }
            if (count($sample) >= 40) {
                break;
            }
        }
        if (empty($sample)) {
            return ';';
        }

        $best = ';';
        $bestScore = -1;
        foreach ([';', ',', "\t", '|'] as $delimiter) {
            $counts = [];
            foreach ($sample as $line) {
                $fields = count(str_getcsv($line, $delimiter));
                if ($fields > 1) {
                    $counts[$fields] = ($counts[$fields] ?? 0) + 1;
                }
            }
            if (empty($counts)) {
                continue;
            }
            arsort($counts);
            $dominant = array_key_first($counts);
            // Nombre de lignes partageant le découpage dominant ; départage
            // par le nombre de colonnes, un séparateur qui isole davantage
            // de colonnes cohérentes étant le bon.
            $score = $counts[$dominant] * 100 + (int) $dominant;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $delimiter;
            }
        }
        return $best;
    }

    // ------------------------------------------------------------------
    // XLSX
    // ------------------------------------------------------------------

    private static function readXlsx(string $path): array {
        if (!class_exists('ZipArchive')) {
            throw new Exception("Lecture .xlsx impossible : l'extension ZIP de PHP est absente. Exportez le fichier en CSV.");
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new Exception('Fichier .xlsx illisible (archive corrompue ?)');
        }

        try {
            $shared = self::sharedStrings($zip);
            list($sheetPath, $sheetName) = self::firstSheet($zip);
            $xml = $zip->getFromName($sheetPath);
            if ($xml === false) {
                throw new Exception('Feuille de calcul introuvable dans le fichier');
            }
            $rows = self::parseSheet($xml, $shared);
        } finally {
            $zip->close();
        }

        return ['rows' => $rows, 'format' => 'xlsx', 'sheet' => $sheetName];
    }

    /** Table des chaînes partagées : les cellules texte y renvoient par index. */
    private static function sharedStrings(ZipArchive $zip): array {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            return [];
        }
        $out = [];
        foreach ($doc->si as $si) {
            // Une chaîne peut être découpée en plusieurs fragments <t>
            // (mise en forme partielle) : on les concatène.
            $text = '';
            if (isset($si->t)) {
                $text = (string) $si->t;
            }
            foreach ($si->r as $r) {
                $text .= (string) $r->t;
            }
            $out[] = $text;
        }
        return $out;
    }

    /** Première feuille déclarée dans le classeur. */
    private static function firstSheet(ZipArchive $zip): array {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $name = null;
        if ($workbook !== false) {
            $doc = @simplexml_load_string($workbook);
            if ($doc !== false && isset($doc->sheets->sheet[0])) {
                $name = (string) $doc->sheets->sheet[0]['name'];
            }
        }
        // Chemin standard ; on vérifie qu'il existe, sinon on prend la
        // première feuille trouvée dans l'archive.
        if ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
            return ['xl/worksheets/sheet1.xml', $name];
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (strpos($entry, 'xl/worksheets/') === 0 && substr($entry, -4) === '.xml') {
                return [$entry, $name];
            }
        }
        throw new Exception('Aucune feuille de calcul dans le fichier');
    }

    private static function parseSheet(string $xml, array $shared): array {
        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            throw new Exception('Feuille de calcul illisible');
        }

        $rows = [];
        foreach ($doc->sheetData->row as $row) {
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
            $cells = [];
            foreach ($row->c as $c) {
                // La référence (A1, B2…) donne la vraie position : une
                // cellule vide est omise du XML, sans cela les colonnes se
                // décaleraient sur les lignes trouées.
                $index = self::columnIndex((string) $c['r']);
                $cells[$index] = self::cellValue($c, $shared);
            }
            if (empty($cells)) {
                $rows[] = [];
                continue;
            }
            $max = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) {
                $line[] = isset($cells[$i]) ? $cells[$i] : '';
            }
            $rows[] = $line;
        }
        return $rows;
    }

    private static function cellValue(SimpleXMLElement $c, array $shared): string {
        $type = (string) $c['t'];
        if ($type === 's') {
            $index = (int) $c->v;
            return isset($shared[$index]) ? $shared[$index] : '';
        }
        if ($type === 'inlineStr') {
            $text = '';
            if (isset($c->is->t)) {
                $text = (string) $c->is->t;
            }
            foreach ($c->is->r as $r) {
                $text .= (string) $r->t;
            }
            return $text;
        }
        // Formule : <f> porte la formule, <v> la dernière valeur calculée —
        // c'est celle-ci qui nous intéresse.
        if (isset($c->v)) {
            return (string) $c->v;
        }
        return '';
    }

    /** « BC12 » -> index 54 (colonne BC), base 0. */
    private static function columnIndex(string $ref): int {
        if (!preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) {
            return 0;
        }
        $letters = $m[1];
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return $index - 1;
    }
}
