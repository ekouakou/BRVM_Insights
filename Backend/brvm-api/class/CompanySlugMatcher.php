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

    // Abréviation pays telle qu'utilisée en toute fin de nom dans les bulletins
    // (ex: "BANK OF AFRICA BN") -> countries.code. Différent de COUNTRY_SLUG_SUFFIX
    // (sens inverse, et abréviations parfois différentes : "NG" pour le Niger
    // dans les bulletins, alors que le code ISO est "NE").
    private const BULLETIN_COUNTRY_TOKEN_TO_CODE = ['CI' => 'CI', 'SN' => 'SN', 'BF' => 'BF', 'ML' => 'ML', 'TG' => 'TG', 'GW' => 'GW', 'NG' => 'NE', 'NE' => 'NE', 'BN' => 'BJ', 'BJ' => 'BJ'];

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

    /**
     * Rattache un nom d'entreprise libre (tel qu'extrait par l'IA d'un bulletin,
     * ex: "NESTLE CI" ou "SONATEL") à une entreprise de la table companies.
     * Contrairement à computeSlugAssignments (rattachement en masse, sûr par
     * construction), ici on tolère un score plus bas ('fuzzy') car le nom
     * source est du texte libre d'IA et non un annuaire structuré — mais on
     * retourne toujours le niveau de confiance pour que l'appelant puisse
     * décider d'afficher un doute (voir BulletinCorporateActionsService).
     *
     * @param array $companies Lignes companies (avec au moins id, symbol, name, full_name)
     * @return array{company_id:int, confidence:string, score:int}|null
     */
    public static function matchCompanyName(string $rawName, array $companies): ?array {
        $target = self::normalizeForMatch($rawName);
        if ($target === '') {
            return null;
        }

        foreach ($companies as $c) {
            $candidateName = self::normalizeForMatch($c['full_name'] ?: $c['name']);
            if ($candidateName !== '' && $candidateName === $target) {
                return ['company_id' => (int) $c['id'], 'confidence' => 'exact', 'score' => 100];
            }
        }

        foreach ($companies as $c) {
            $symbol = self::normalizeForMatch($c['symbol']);
            if ($symbol === '') continue;
            // Les bulletins utilisent parfois le ticker BRVM brut, qui omet le
            // dernier caractère du symbole en base (ex: "SIB" pour SIBC) — d'où
            // la vérification dans les deux sens, pas seulement symbole ⊂ cible.
            if (str_contains($target, $symbol) || (mb_strlen($target) >= 3 && str_starts_with($symbol, $target))) {
                return ['company_id' => (int) $c['id'], 'confidence' => 'fuzzy', 'score' => 90];
            }
        }

        // Groupes régionaux listés séparément par pays (ex: Bank of Africa,
        // une entité par pays) : le nom de base est identique d'un pays à
        // l'autre ("BANK OF AFRICA"), donc systématiquement à égalité sur la
        // seule similarité textuelle. Les bulletins précisent le pays en
        // dernier mot ("BANK OF AFRICA BN") — si présent et reconnu, on
        // restreint la comparaison aux entreprises de ce pays avant de
        // départager, plutôt que de renoncer à un rattachement pourtant
        // résoluble avec cette information.
        $tokens = explode(' ', $target);
        $lastToken = end($tokens);
        if ($lastToken !== false && isset(self::BULLETIN_COUNTRY_TOKEN_TO_CODE[$lastToken]) && count($tokens) > 1) {
            $countryCode = self::BULLETIN_COUNTRY_TOKEN_TO_CODE[$lastToken];
            $targetBase = implode(' ', array_slice($tokens, 0, -1));
            $sameCountry = array_values(array_filter($companies, fn($c) => ($c['country_code'] ?? null) === $countryCode));

            if (!empty($sameCountry)) {
                $match = self::bestFuzzyMatch($targetBase, $sameCountry);
                if ($match !== null) {
                    return $match;
                }
            }
        }

        // Repli par similarité textuelle : compare la cible à la fois au nom
        // complet ET au symbole de chaque entreprise (pas seulement le nom).
        // Sans ça, un ticker court comme "SIB" (Société Ivoirienne de Banque)
        // peut se retrouver rapproché par erreur d'une entreprise sans lien
        // dont le NOM complet ressemble davantage au texte brut (ex: "SITAB")
        // — un symbole doit toujours pouvoir gagner face à un nom.
        return self::bestFuzzyMatch($target, $companies);
    }

    /**
     * Meilleure correspondance par similarité textuelle (nom complet OU
     * symbole) parmi $companies, avec garde-fou anti-égalité : si le
     * meilleur score est partagé par au moins deux entreprises différentes
     * (ex: "BIIC" à 75% aussi bien de BICB - Bénin - que de BICC - Côte
     * d'Ivoire), on ne rattache à aucune plutôt que de deviner selon l'ordre
     * d'itération, arbitraire et non reproductible côté métier.
     */
    private static function bestFuzzyMatch(string $target, array $companies): ?array {
        $bestCompanyId = null;
        $bestScore = 0;
        $tiedCompanyIds = [];

        foreach ($companies as $c) {
            $candidateName = self::normalizeForMatch($c['full_name'] ?: $c['name']);
            $symbol = self::normalizeForMatch($c['symbol']);

            $companyBest = 0;
            if ($candidateName !== '') {
                similar_text($target, $candidateName, $pct);
                $companyBest = max($companyBest, $pct);
            }
            if ($symbol !== '') {
                similar_text($target, $symbol, $pct);
                $companyBest = max($companyBest, $pct);
            }

            if (abs($companyBest - $bestScore) < 0.01) {
                $tiedCompanyIds[] = (int) $c['id'];
            } elseif ($companyBest > $bestScore) {
                $bestScore = $companyBest;
                $bestCompanyId = (int) $c['id'];
                $tiedCompanyIds = [(int) $c['id']];
            }
        }

        if (count(array_unique($tiedCompanyIds)) > 1) {
            return null;
        }

        if ($bestCompanyId !== null && $bestScore >= 75) {
            return ['company_id' => $bestCompanyId, 'confidence' => 'fuzzy', 'score' => (int) round($bestScore)];
        }

        return null;
    }
}
