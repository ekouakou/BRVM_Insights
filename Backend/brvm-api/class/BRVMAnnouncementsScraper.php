<?php
/**
 * Scraper des annonces émetteurs & publications de brvm.org — découvre les
 * documents listés sur les pages d'annonces (voir TYPES) pour alimenter
 * issuer_announcements (migration 021). Même philosophie que
 * BRVMBulletinsScraper : découverte incrémentale (les documents déjà connus
 * par file_url sont ignorés par l'appelant), téléchargement PDF à part.
 *
 * Quatre structures de listing distinctes constatées sur le site (08/2026) :
 *  - standard    : Date | Société | Titre | lien PDF (annonces émetteurs)
 *  - avis        : Titre | Date | lien PDF (pas de colonne société)
 *  - dividendes  : Emetteur | Obligation | Action | Exercice | Date paiement
 *                  | Date ex-dividende | Montant net | lien Avis
 *  - simple      : Titre | lien PDF (ni date ni société)
 * La page /fr/informations-permanentes n'a AUCUN contenu tabulaire côté
 * HTML (rendu JS ou page vide) — non supportée, volontairement absente du
 * registre plutôt qu'un type qui échouerait silencieusement.
 */
class BRVMAnnouncementsScraper {
    public const TYPES = [
        'convocations_ag' => [
            'label' => "Convocations d'assemblées générales",
            'url' => 'https://www.brvm.org/fr/emetteurs/type-annonces/convocations-assemblees-generales',
            'parser' => 'standard',
        ],
        'projets_resolution' => [
            'label' => 'Projets de résolution',
            'url' => 'https://www.brvm.org/fr/emetteurs/type-annonces/projets-de-resolution',
            'parser' => 'standard',
        ],
        'notations_financieres' => [
            'label' => 'Notations financières',
            'url' => 'https://www.brvm.org/fr/emetteurs/type-annonces/notations-financieres',
            'parser' => 'standard',
        ],
        'paiement_dividendes' => [
            'label' => 'Paiements de dividendes',
            'url' => 'https://www.brvm.org/fr/esv/paiement-de-dividendes',
            'parser' => 'dividendes',
        ],
        'communiques' => [
            'label' => 'Communiqués',
            'url' => 'https://www.brvm.org/fr/emetteurs/type-annonces/communiques',
            'parser' => 'standard',
        ],
        'changements_dirigeants' => [
            'label' => 'Changements de dirigeants',
            'url' => 'https://www.brvm.org/fr/emetteurs/type-annonces/changements-de-dirigeants',
            'parser' => 'standard',
        ],
        'franchissements_seuil' => [
            'label' => 'Franchissements de seuil',
            'url' => 'https://www.brvm.org/fr/emetteurs/type-annonces/franchissements-de-seuil',
            'parser' => 'standard',
        ],
        'avis_marche' => [
            'label' => 'Avis du marché',
            'url' => 'https://www.brvm.org/fr/marche/avis-et-publications/avis',
            'parser' => 'avis',
        ],
        'donnees_economiques' => [
            'label' => 'Données économiques',
            'url' => 'https://www.brvm.org/fr/publications/donnees-economiques',
            'parser' => 'simple',
        ],
    ];

    private const FRENCH_MONTHS = [
        'janvier' => '01', 'février' => '02', 'fevrier' => '02', 'mars' => '03',
        'avril' => '04', 'mai' => '05', 'juin' => '06', 'juillet' => '07',
        'août' => '08', 'aout' => '08', 'septembre' => '09', 'octobre' => '10',
        'novembre' => '11', 'décembre' => '12', 'decembre' => '12',
    ];

    /**
     * Découvre les annonces d'un type sur les N premières pages du listing
     * (pagination Drupal ?page=0..N-1 — les listings profonds comme les avis
     * du marché ont 180+ pages d'historique, la découverte reste
     * incrémentale : on ne remonte que ce qui est récent, l'appelant ignore
     * les file_url déjà connus).
     *
     * @return array<array{publish_date: ?string, company_name_raw: ?string, title: string, file_url: string}>
     */
    public function discover(string $typeKey, int $maxPages = 2): array {
        if (!isset(self::TYPES[$typeKey])) {
            throw new Exception("Type d'annonce inconnu: $typeKey");
        }
        $type = self::TYPES[$typeKey];

        $items = [];
        for ($page = 0; $page < $maxPages; $page++) {
            $url = $type['url'] . ($page > 0 ? '?page=' . $page : '');
            $html = $this->fetchHTML($url);
            if (!$html) {
                break;
            }
            $pageItems = $this->parseListing($html, $type['parser']);
            if (empty($pageItems)) {
                break; // au-delà de la dernière page, le listing est vide
            }
            $items = array_merge($items, $pageItems);
        }

        return $items;
    }

    public function downloadFile(string $url, string $localPath): bool {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($data === false || $httpCode !== 200 || strlen($data) < 100) {
            return false;
        }
        return file_put_contents($localPath, $data) !== false;
    }

    private function fetchHTML(string $url): ?string {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($html !== false && $httpCode === 200) ? $html : null;
    }

    /**
     * Parse le tableau du contenu principal (#block-system-main) — les
     * tableaux latéraux (Top 5/Flop 5...) sont hors de ce bloc.
     */
    private function parseListing(string $html, string $parser): array {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);

        $main = $xpath->query("//*[@id='block-system-main']")->item(0);
        if (!$main) {
            return [];
        }
        $rows = $xpath->query(".//table//tr", $main);

        $items = [];
        foreach ($rows as $row) {
            $cells = [];
            $links = [];
            foreach ($xpath->query('.//td', $row) as $td) {
                $cells[] = trim(preg_replace('/\s+/', ' ', $td->textContent));
                foreach ($xpath->query('.//a[@href]', $td) as $a) {
                    $links[] = $a->getAttribute('href');
                }
            }
            if (empty($cells)) {
                continue; // ligne d'en-têtes (th)
            }

            $fileUrl = null;
            foreach ($links as $link) {
                if (stripos($link, '.pdf') !== false || stripos($link, '/sites/default/files/') !== false) {
                    $fileUrl = $this->absoluteUrl($link);
                    break;
                }
            }
            if (!$fileUrl) {
                continue;
            }

            $item = $this->mapCells($parser, $cells);
            if ($item === null || $item['title'] === '') {
                continue;
            }
            $item['file_url'] = $fileUrl;
            $items[] = $item;
        }

        return $items;
    }

    private function mapCells(string $parser, array $cells): ?array {
        if ($parser === 'standard') {
            // Date | Société | Titre | Télécharger
            if (count($cells) < 3) return null;
            return [
                'publish_date' => $this->parseDate($cells[0]),
                'company_name_raw' => $cells[1] !== '' ? $cells[1] : null,
                'title' => $cells[2],
            ];
        }
        if ($parser === 'avis') {
            // Titre | Date | Télécharger — pas de société ; certains titres
            // commencent par "Avis : ..." avec parfois un symbole en fin.
            if (count($cells) < 2) return null;
            return [
                'publish_date' => $this->parseDate($cells[1]),
                'company_name_raw' => null,
                'title' => $cells[0],
            ];
        }
        if ($parser === 'dividendes') {
            // Emetteur | Obligation | Action | Exercice | Date paiement | Date ex-div | Montant | Avis
            if (count($cells) < 7) return null;
            $title = "Paiement de dividende " . $cells[0]
                . ($cells[3] !== '' ? " — exercice {$cells[3]}" : '')
                . ($cells[6] !== '' ? " — {$cells[6]}" : '')
                . ($cells[4] !== '' ? " (paiement le {$cells[4]}" . ($cells[5] !== '' ? ", ex-dividende le {$cells[5]}" : '') . ")" : '');
            return [
                'publish_date' => $this->parseDate($cells[4]),
                'company_name_raw' => $cells[0] !== '' ? $cells[0] : null,
                'title' => $title,
            ];
        }
        // simple : Titre | Télécharger
        if (count($cells) < 1) return null;
        return [
            'publish_date' => null,
            'company_name_raw' => null,
            'title' => $cells[0],
        ];
    }

    /**
     * "03/08/2026" ou "24 août 2026" -> "2026-08-03" / "2026-08-24" (null sinon).
     */
    private function parseDate(string $raw): ?string {
        $raw = trim($raw);
        if ($raw === '') return null;

        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $raw, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('#^(\d{1,2})(?:er)?\s+(\p{L}+)\s+(\d{4})$#u', $raw, $m)) {
            $month = self::FRENCH_MONTHS[mb_strtolower($m[2])] ?? null;
            if ($month) {
                return $m[3] . '-' . $month . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
            }
        }
        return null;
    }

    private function absoluteUrl(string $url): string {
        if (strpos($url, 'http') === 0) {
            return $url;
        }
        return 'https://www.brvm.org' . (strpos($url, '/') === 0 ? '' : '/') . $url;
    }
}
