<?php
/**
 * Extraction DÉTERMINISTE (sans IA) du volume et de la valeur transigés du
 * marché des actions, depuis le tableau « Statistiques du marché » de
 * chaque Bulletin Officiel de la Cote (BOC) — une ligne par bulletin
 * (contrairement à BulletinStockMetricsService/BulletinBondMetricsService,
 * qui produisent une ligne par valeur/obligation).
 *
 * Même philosophie que BulletinOrderBookService : ce tableau a un format
 * fixe et prévisible dans tous les BOC (même gabarit officiel BRVM chaque
 * jour), une extraction par regex sur extracted_text (le texte brut
 * pdftotext, pas le markdown reformaté par IA — donc disponible dès le
 * traitement du PDF, sans attendre le bouton « Formater en tableaux ») est
 * donc plus rapide et plus fiable qu'un appel IA pour ces deux nombres.
 *
 * Format réellement observé (30 bulletins du corpus, 100% de succès) : les
 * deux tableaux Actions et Obligations sont imprimés CÔTE À CÔTE sur la
 * même ligne physique du PDF (colonnes séparées par de larges espaces) —
 * seul le premier couple (valeur, %) après le libellé nous intéresse ici,
 * le second couple qui suit sur la même ligne appartient au tableau
 * Obligations et est ignoré par construction du regex (ancré sur le
 * libellé exact "(Actions & Droits)").
 */
class BulletinMarketStatsService {
    private $crud;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
    }

    /**
     * Extrait les statistiques marché d'un bulletin et remplace la ligne existante.
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

        try {
            $volume = $this->extractStat($text, 'Volume échangé (Actions & Droits)');
            $value = $this->extractStat($text, 'Valeur transigée (FCFA) (Actions & Droits)');
            $cap = $this->extractStat($text, 'Capitalisation boursière (FCFA)(Actions & Droits)');

            if ($volume === null && $value === null) {
                throw new Exception("Tableau « Statistiques du marché » introuvable dans ce bulletin (format inattendu)");
            }

            $oblCap = $this->extractStatObligations($text, 'Capitalisation boursière (FCFA)');
            $oblVolume = $this->extractStatObligations($text, 'Volume échangé');
            $oblValue = $this->extractStatObligations($text, 'Valeur transigée (FCFA)');

            [$actTraded, $oblTraded] = $this->extractTitlesPair($text, 'Nombre de titres transigés');
            [$actUp, $oblUp] = $this->extractTitlesPair($text, 'Nombre de titres en hausse');
            [$actDown, $oblDown] = $this->extractTitlesPair($text, 'Nombre de titres en baisse');
            [$actUnchanged, $oblUnchanged] = $this->extractTitlesPair($text, 'Nombre de titres inchangés');

            // Tableau « Indicateurs du marché » (BRVM COMPOSITE) — 14
            // indicateurs synthétiques, un seul niveau chacun (pas de %
            // d'évolution, contrairement aux tableaux ci-dessus).
            $perMoyen = $this->extractIndicator($text, 'PER moyen du marché');
            $tauxRendement = $this->extractIndicator($text, 'Taux de rendement moyen du marché');
            $tauxRentabilite = $this->extractIndicator($text, 'Taux de rentabilité moyen du marché');
            $nbSocietes = $this->extractIndicator($text, 'Nombre de sociétés cotées');
            $nbLignesObl = $this->extractIndicator($text, 'Nombre de lignes obligataires');
            $volumeMoyenAnnuel = $this->extractIndicator($text, 'Volume moyen annuel par séance');
            $valeurMoyenneAnnuelle = $this->extractIndicator($text, 'Valeur moyenne annuelle par séance');
            $ratioLiquidite = $this->extractIndicator($text, 'Ratio moyen de liquidité');
            $ratioSatisfaction = $this->extractIndicator($text, 'Ratio moyen de satisfaction');
            $ratioTendance = $this->extractIndicator($text, 'Ratio moyen de tendance');
            $ratioCouverture = $this->extractIndicator($text, 'Ratio moyen de couverture');
            $tauxRotation = $this->extractIndicator($text, 'Taux de rotation moyen du marché');
            $primeRisque = $this->extractIndicator($text, 'Prime de risque du marché');
            $nbSgi = $this->extractIndicator($text, 'Nombre de SGI participantes');

            $this->crud->remove('bulletin_market_stats', ['bulletin_id' => $bulletinId]);
            $this->crud->persist('bulletin_market_stats', [
                'bulletin_id' => $bulletinId,
                'publish_date' => $bulletin['publish_date'],
                'actions_volume' => $volume['value'] ?? null,
                'actions_volume_change_percent' => $volume['change_percent'] ?? null,
                'actions_value_traded' => $value['value'] ?? null,
                'actions_value_change_percent' => $value['change_percent'] ?? null,
                'actions_capitalization' => $cap['value'] ?? null,
                'actions_capitalization_change_percent' => $cap['change_percent'] ?? null,
                'actions_titles_traded' => $actTraded['value'] ?? null,
                'actions_titles_traded_change_percent' => $actTraded['change_percent'] ?? null,
                'actions_titles_up' => $actUp['value'] ?? null,
                'actions_titles_up_change_percent' => $actUp['change_percent'] ?? null,
                'actions_titles_down' => $actDown['value'] ?? null,
                'actions_titles_down_change_percent' => $actDown['change_percent'] ?? null,
                'actions_titles_unchanged' => $actUnchanged['value'] ?? null,
                'actions_titles_unchanged_change_percent' => $actUnchanged['change_percent'] ?? null,
                'obligations_capitalization' => $oblCap['value'] ?? null,
                'obligations_capitalization_change_percent' => $oblCap['change_percent'] ?? null,
                'obligations_volume' => $oblVolume['value'] ?? null,
                'obligations_volume_change_percent' => $oblVolume['change_percent'] ?? null,
                'obligations_value_traded' => $oblValue['value'] ?? null,
                'obligations_value_change_percent' => $oblValue['change_percent'] ?? null,
                'obligations_titles_traded' => $oblTraded['value'] ?? null,
                'obligations_titles_traded_change_percent' => $oblTraded['change_percent'] ?? null,
                'obligations_titles_up' => $oblUp['value'] ?? null,
                'obligations_titles_up_change_percent' => $oblUp['change_percent'] ?? null,
                'obligations_titles_down' => $oblDown['value'] ?? null,
                'obligations_titles_down_change_percent' => $oblDown['change_percent'] ?? null,
                'obligations_titles_unchanged' => $oblUnchanged['value'] ?? null,
                'obligations_titles_unchanged_change_percent' => $oblUnchanged['change_percent'] ?? null,
                'per_moyen_marche' => $perMoyen,
                'taux_rendement_moyen' => $tauxRendement,
                'taux_rentabilite_moyen' => $tauxRentabilite,
                'nombre_societes_cotees' => $nbSocietes,
                'nombre_lignes_obligataires' => $nbLignesObl,
                'volume_moyen_annuel_seance' => $volumeMoyenAnnuel,
                'valeur_moyenne_annuelle_seance' => $valeurMoyenneAnnuelle,
                'ratio_moyen_liquidite' => $ratioLiquidite,
                'ratio_moyen_satisfaction' => $ratioSatisfaction,
                'ratio_moyen_tendance' => $ratioTendance,
                'ratio_moyen_couverture' => $ratioCouverture,
                'taux_rotation_moyen' => $tauxRotation,
                'prime_risque_marche' => $primeRisque,
                'nombre_sgi_participantes' => $nbSgi,
            ]);

            $this->crud->merge('market_bulletin_contents',
                ['market_stats_status' => 'success', 'market_stats_error' => null],
                ['bulletin_id' => $bulletinId]);

            return ['bulletin_id' => $bulletinId, 'status' => 'success'];
        } catch (Exception $e) {
            $this->crud->merge('market_bulletin_contents',
                ['market_stats_status' => 'failed', 'market_stats_error' => mb_substr($e->getMessage(), 0, 2000)],
                ['bulletin_id' => $bulletinId]);
            throw $e;
        }
    }

    /**
     * Extrait tous les bulletins qui ont un texte mais pas encore de
     * statistiques marché (ou tous si $force).
     */
    public function extractAll(bool $force = false): array {
        $where = $force ? '' : "AND (c.market_stats_status IS NULL OR c.market_stats_status = 'failed')";
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
     * Série des statistiques marché déjà extraites sur une période, plus les
     * bulletins encore en attente — même forme que les autres listMetrics().
     */
    public function list(string $startDate, string $endDate): array {
        $rows = $this->crud->executeCustomQuery(
            "SELECT m.*, b.title AS bulletin_title
             FROM bulletin_market_stats m
             JOIN market_bulletins b ON b.id = m.bulletin_id
             WHERE m.publish_date BETWEEN ? AND ?
             ORDER BY m.publish_date ASC",
            [$startDate, $endDate]
        ) ?: [];

        $pending = $this->crud->executeCustomQuery(
            "SELECT b.id, b.title, b.publish_date
             FROM market_bulletins b
             JOIN market_bulletin_contents mbc ON mbc.bulletin_id = b.id
             WHERE (mbc.extracted_text IS NOT NULL AND mbc.extracted_text != '')
               AND (mbc.market_stats_status IS NULL OR mbc.market_stats_status != 'success')
             ORDER BY b.publish_date DESC"
        ) ?: [];

        return [
            'days' => $rows,
            'pending_bulletins' => $pending,
            'pending_count' => count($pending),
        ];
    }

    /**
     * Cherche le libellé exact dans le texte, capture le nombre (espaces en
     * séparateur de milliers) puis le pourcentage (virgule décimale) qui le
     * suivent — voir la note de format en tête de fichier. Retourne null si
     * le libellé n'apparaît pas (bulletin d'un format différent).
     */
    private function extractStat(string $text, string $label): ?array {
        $pattern = '/' . preg_quote($label, '/') . '\s+([\d][\d\s\x{00A0}]*\d|\d)\s+(-?[\d]+,[\d]+)\s*%/u';
        if (preg_match($pattern, $text, $m)) {
            return ['value' => $this->parseNumber($m[1]), 'change_percent' => $this->parseNumber($m[2])];
        }
        return null;
    }

    /**
     * Variante de extractStat() pour les libellés Obligations qui n'ont pas
     * de suffixe distinctif du libellé Actions correspondant (ex. « Volume
     * échangé » tout court, vs « Volume échangé (Actions & Droits) »). Le
     * lookahead négatif exclut l'occurrence Actions (immédiatement suivie de
     * « (Actions… ») pour ne matcher que celle qui suit sur la même ligne
     * physique du bulletin — voir la note de format en tête de fichier.
     */
    private function extractStatObligations(string $text, string $label): ?array {
        $pattern = '/' . preg_quote($label, '/') . '(?!\s*\(Actions)\s+([\d][\d\s\x{00A0}]*\d|\d)\s+(-?[\d]+,[\d]+)\s*%/u';
        if (preg_match($pattern, $text, $m)) {
            return ['value' => $this->parseNumber($m[1]), 'change_percent' => $this->parseNumber($m[2])];
        }
        return null;
    }

    /**
     * Tableau « Indicateurs du marché » : deux colonnes de paires
     * libellé/niveau imprimées côte à côte sur la même ligne physique (ex.
     * « PER moyen du marché   14,59   Ratio moyen de liquidité   59,02 »),
     * un seul niveau par libellé, jamais de % d'évolution — contrairement à
     * extractStat(). Le nombre est ancré sur le vrai séparateur de milliers
     * français (groupes de 3 chiffres) plutôt que « n'importe quel chiffre/
     * espace » : un motif trop permissif engloutirait le blanc de colonne
     * jusqu'au libellé suivant, y compris à travers un saut de ligne pour le
     * tout dernier indicateur de la liste (repéré sur le corpus réel).
     */
    private function extractIndicator(string $text, string $label): ?float {
        $pattern = '/' . preg_quote($label, '/') . '\s*(?:\(\*\*\))?\s+(\d{1,3}(?:[ \x{00A0}]\d{3})*(?:,\d+)?)/u';
        if (preg_match($pattern, $text, $m)) {
            return $this->parseNumber($m[1]);
        }
        return null;
    }

    /**
     * Les 4 libellés « Nombre de titres … » sont strictement identiques côté
     * Actions et côté Obligations (aucun suffixe pour les distinguer) —
     * seule leur ordre d'apparition sur la ligne les différencie (1re
     * occurrence = Actions, 2e = Obligations, mise en page BOC constante).
     *
     * Contrairement à extractStat(), niveau et variation sont ici chacun
     * optionnels indépendamment : quand un compteur tombe à 0, le bulletin
     * omet soit le niveau (la variation vaut alors -100,00 % sans nombre
     * devant), soit la variation (passage depuis 0, division non définie,
     * juste le niveau sans % derrière).
     *
     * @return array{0: ?array, 1: ?array} [stats Actions, stats Obligations]
     */
    private function extractTitlesPair(string $text, string $label): array {
        // Le niveau est petit ici (nombre de titres, pas un montant FCFA) et
        // suivi d'un large blanc de colonne avant le pourcentage : contrairement
        // à extractStat(), les deux groupes sont optionnels, donc rien ne force
        // le moteur à backtracker si un groupage glouton "mange" trop — d'où un
        // motif de nombre ancré sur le vrai séparateur de milliers français
        // (groupes de 3 chiffres) plutôt que "n'importe quel chiffre/espace",
        // pour ne pas déborder sur le blanc de colonne ni sur le pourcentage.
        $pattern = '/' . preg_quote($label, '/')
            . '(?:\s+(\d{1,3}(?:[ \x{00A0}]\d{3})*))?\s*(?:(-?[\d]+,[\d]+)\s*%)?/u';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

        $toStat = function (array $m): ?array {
            $value = ($m[1] ?? '') !== '' ? $this->parseNumber($m[1]) : null;
            $percent = ($m[2] ?? '') !== '' ? $this->parseNumber($m[2]) : null;
            return ($value === null && $percent === null) ? null : ['value' => $value, 'change_percent' => $percent];
        };

        return [
            isset($matches[0]) ? $toStat($matches[0]) : null,
            isset($matches[1]) ? $toStat($matches[1]) : null,
        ];
    }

    private function parseNumber(string $s): ?float {
        $s = trim($s);
        $s = str_replace([' ', "\xC2\xA0"], '', $s); // espace normal + espace insécable
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float) $s : null;
    }
}
