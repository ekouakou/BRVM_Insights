<?php
/**
 * Rattachement automatique (sûr, jamais approximatif) des entreprises aux
 * slugs de l'annuaire /fr/rapports-societes-cotees de brvm.org (voir
 * class/BRVMReportsScraper.php::discoverCompanySlugs()). Extrait de
 * scripts/backfill_reports.php pour être réutilisable aussi depuis
 * api_reports.php (action match_companies, déclenchable depuis le panneau
 * d'admin — utile sur un hébergement sans accès CLI).
 */
class CompanySlugMatcher {
    // Mots vides retirés avant comparaison (pays, forme juridique) — évitent que deux
    // entreprises de pays différents ("BANK OF AFRICA BENIN" vs "... MALI") se
    // confondent sur leur seule partie commune.
    private const STOPWORDS = ['CI', 'COTE', 'D', 'IVOIRE', 'DIVOIRE', 'BENIN', 'BURKINA', 'FASO', 'SENEGAL', 'MALI', 'NIGER', 'TOGO', 'SA'];

    // Code pays (countries.code) -> suffixe utilisé dans les slugs brvm.org
    private const COUNTRY_SLUG_SUFFIX = ['CI' => 'ci', 'SN' => 'sn', 'BF' => 'bf', 'BJ' => 'bn', 'TG' => 'tg', 'NE' => 'ng', 'ML' => 'ml', 'GW' => 'gw'];

    public static function normalizeForMatch(string $str): string {
        $str = strtoupper($str);
        $str = iconv('UTF-8', 'ASCII//TRANSLIT', $str);
        $str = preg_replace('/[^A-Z0-9]+/', ' ', $str);
        $tokens = preg_split('/\s+/', trim($str));
        $tokens = array_filter($tokens, fn($t) => $t !== '' && !in_array($t, self::STOPWORDS));
        return implode(' ', $tokens);
    }

    /**
     * Calcule un rattachement automatique sûr (slug non ambigu, score >= 90%,
     * pas de collision avec une autre entreprise). Les cas incertains ne sont
     * PAS assignés — mieux vaut un rapport manquant qu'un rapport mal attribué.
     *
     * @param array $companies Lignes companies (avec au moins symbol, name, full_name, brvm_report_slug, country_code)
     * @param array $slugs Entrées de l'annuaire ['slug' => ..., 'name' => ...]
     * @return array{assignments: array<string,string>, review: array<string,array>}
     */
    public static function computeSlugAssignments(array $companies, array $slugs): array {
        $assignments = [];
        $review = [];

        foreach ($companies as $c) {
            if (!empty($c['brvm_report_slug'])) {
                continue; // déjà rattachée (auto précédemment ou manuellement)
            }

            $target = self::normalizeForMatch($c['full_name'] ?: $c['name']);
            $exactTier = [];
            $bestSlug = null;
            $bestScore = 0;

            foreach ($slugs as $s) {
                $candidate = self::normalizeForMatch($s['name']);
                if ($candidate === '') continue;

                similar_text($target, $candidate, $pct);
                if ($pct >= 90) {
                    $exactTier[] = array_merge($s, ['score' => $pct]);
                }
                if ($pct > $bestScore) {
                    $bestScore = $pct;
                    $bestSlug = $s['slug'];
                }
            }

            $chosen = null;
            if (count($exactTier) === 1) {
                $chosen = $exactTier[0]['slug'];
            } elseif (count($exactTier) > 1) {
                $suffix = self::COUNTRY_SLUG_SUFFIX[$c['country_code']] ?? null;
                $candidates = array_values(array_filter(
                    $exactTier,
                    fn($e) => $suffix && str_ends_with($e['slug'], "-{$suffix}")
                ));
                if (count($candidates) === 1) {
                    $chosen = $candidates[0]['slug'];
                }
            }

            if ($chosen) {
                $assignments[$c['symbol']] = $chosen;
            } else {
                $review[$c['symbol']] = ['suggestion' => $bestSlug, 'score' => $bestScore];
            }
        }

        // Sécurité supplémentaire : si deux entreprises se voient assigner le même
        // slug (ex: homonymes après normalisation), on annule les deux plutôt que
        // de deviner laquelle est la bonne.
        $bySlug = [];
        foreach ($assignments as $symbol => $slug) {
            $bySlug[$slug][] = $symbol;
        }
        foreach ($bySlug as $slug => $symbols) {
            if (count($symbols) > 1) {
                foreach ($symbols as $symbol) {
                    $review[$symbol] = ['suggestion' => $slug, 'score' => 100];
                    unset($assignments[$symbol]);
                }
            }
        }

        return ['assignments' => $assignments, 'review' => $review];
    }
}
