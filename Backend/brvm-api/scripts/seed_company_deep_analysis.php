<?php
/**
 * Peuple, pour les 47 entreprises BRVM, à partir du texte déjà rédigé dans
 * ANALYSE_ENTREPRISES_BRVM.md :
 *   - companies.description (Domaine), companies.products_services
 *     (Produits), companies.is_seasonal + companies.seasonal_detail
 *     (Saisonnière / Détail saisonnier) — migrations 030/031 ;
 *   - company_international_pricing_history (Prix international, historisé
 *     — migration 032), une ligne de référence sans date par entreprise ;
 *   - company_cyclicality_profile et company_analysis_notes
 *     (perspective_generale, facteur_hausse, facteur_baisse, signal_achat,
 *     signal_vente, levier_remuneration, perspective_remuneration) ;
 * à partir de :
 *   - sections "### SYMBOLE — Nom" (tiret cadratin, première partie du
 *     document) pour cyclicité/perspective/facteurs ;
 *   - section "# Quand acheter, quand vendre : guide pédagogique par
 *     entreprise" (sous-titres "### SYMBOLE(S) (Nom)", sans tiret cadratin —
 *     à ne pas confondre avec la reprise "Leviers spécifiques" plus bas, qui
 *     utilise le même format de titre mais un contenu différent) pour
 *     signal_achat/signal_vente ;
 *   - section "## Leviers spécifiques par entreprise" (liste à puces
 *     "- **SYMBOLE(S) (Nom)** : levier ... **Perspective (rémunération)** :
 *     ...") pour la politique de dividende.
 *
 * Idempotent : rejouable sans risque (UPDATE direct pour les colonnes
 * companies, ON DUPLICATE KEY UPDATE pour le profil de cyclicité,
 * DELETE+INSERT par company_id/note_type pour les notes qualitatives).
 *
 * Usage : php scripts/seed_company_deep_analysis.php
 */

require_once __DIR__ . '/../config.php';

$db = getConfig('db');
$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset={$db['charset']}",
    $db['username'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$mdPath = __DIR__ . '/../ANALYSE_ENTREPRISES_BRVM.md';
$fullContent = file_get_contents($mdPath);
if ($fullContent === false) {
    fwrite(STDERR, "Impossible de lire $mdPath\n");
    exit(1);
}

$KNOWN_SYMBOLS = [
    'ABJC','BICB','BICC','BNBC','BOAB','BOABF','BOAC','BOAM','BOAN','BOAS',
    'CABC','CBIBF','CFAC','CIEC','ECOC','ETIT','FTSC','LNBB','NEIC','NSBC',
    'NTLC','ONTBF','ORAC','ORGT','PALC','PRSC','SAFC','SCRC','SDCC','SDSC',
    'SEMC','SGBC','SHEC','SIBC','SICC','SIVC','SLBC','SMBC','SNTS','SOGC',
    'SPHC','STAC','STBC','TTLC','TTLS','UNLC','UNXC',
];

// Le document contient plusieurs reprises résumées du même contenu plus
// bas (sections "Indicateurs communs...", "Leviers spécifiques par
// entreprise"...) — on se limite à la toute première section détaillée
// (celle utilisée dans ce fichier pour la doc humaine), qui seule garantit
// des titres "### SYMBOLE — Nom" propres et complets.
$content = $fullContent;
$cutoff = mb_strpos($content, '## Indicateurs communs à toutes les sociétés cotées');
if ($cutoff !== false) {
    $content = mb_substr($content, 0, $cutoff);
}

// Découpe le document en blocs, chacun démarrant sur une ligne de titre de
// niveau 2 ou 3 ("## " ou "### ") — permet de borner chaque fiche société
// exactement jusqu'au prochain titre, quel que soit son niveau.
$chunks = preg_split('/\n(?=#{2,3} )/', $content);

/**
 * Retire un séparateur "---" ou un titre "### ..." / "## ..." de fin de
 * chaîne : pour le DERNIER champ bulleté d'un bloc (souvent "Perspective"),
 * le découpage en chunks (uniquement sur les lignes de titre) laisse
 * passer le séparateur et/ou le titre de la section suivante quand celle-ci
 * ne commence pas immédiatement par une ligne de titre — ex. "---" entre
 * deux secteurs, ou un sous-titre "### Secteur" dans la section "Leviers
 * spécifiques par entreprise". Aucun de ces deux motifs n'apparaît
 * légitimement dans un texte d'analyse.
 */
function stripTrailingArtifacts(string $text): string {
    $text = preg_replace('/\s*#{1,6}\s.*$/s', '', $text);
    $text = preg_replace('/\s*-{3,}\s*$/', '', $text);
    return trim($text);
}

function extractField(string $body, string $label): ?string {
    $pattern = '/-\s*\*\*' . preg_quote($label, '/') . '\*\*\s*:\s*(.*?)(?=\n-\s*\*\*|\z)/s';
    if (preg_match($pattern, $body, $m)) {
        $text = trim($m[1]);
        // Les puces continuent sur plusieurs lignes indentées ; on
        // recompacte en un paragraphe unique.
        $text = preg_replace('/\s*\n\s*/', ' ', $text);
        $text = stripTrailingArtifacts($text);
        return $text !== '' ? $text : null;
    }
    return null;
}

function mapTriState(?string $text): string {
    if ($text === null) {
        return 'non';
    }
    $t = mb_strtolower($text);
    if (strpos($t, 'non') === 0) {
        return 'non';
    }
    if (strpos($t, 'partiellement') === 0) {
        return 'partiellement';
    }
    if (strpos($t, 'oui') === 0) {
        return 'oui';
    }
    return 'non';
}

function mapCyclicality(?string $text): string {
    if ($text === null) {
        return 'modere';
    }
    $t = mb_strtolower($text);
    if (strpos($t, 'non') === 0) {
        return 'non_cyclique';
    }
    if (strpos($t, 'fortement') !== false || strpos($t, '(fort)') !== false) {
        return 'fort';
    }
    if (strpos($t, 'modérément') !== false || strpos($t, '(modéré)') !== false) {
        return 'modere';
    }
    // "Oui" sans qualificatif explicite : classé modéré par défaut, le
    // texte complet reste consultable dans notes.
    return 'modere';
}

function extractKnownSymbols(string $text, array $knownSymbols): array {
    $found = [];
    foreach ($knownSymbols as $symbol) {
        if (preg_match('/\b' . preg_quote($symbol, '/') . '\b/', $text)) {
            $found[] = $symbol;
        }
    }
    return $found;
}

$profiles = [];        // symbol => ['cyclicality_text' => ..., 'level' => ...]
$notes = [];           // symbol => ['perspective_generale' => ..., 'facteur_hausse' => ..., 'facteur_baisse' => ..., 'levier_remuneration' => ..., 'perspective_remuneration' => ...]
$companyProfile = [];  // symbol => ['description' => domaine, 'products_services' => ..., 'is_seasonal' => enum, 'seasonal_detail' => ...]
$internationalPricing = []; // symbol => ['level' => enum, 'explanation' => ...]

foreach ($chunks as $chunk) {
    if (!preg_match('/^### (.+)$/m', $chunk, $headerMatch)) {
        continue;
    }
    $headerLine = trim($headerMatch[1]);
    if (strpos($headerLine, '—') === false) {
        // Ce format de titre sans tiret cadratin correspond à la section
        // "Quand acheter, quand vendre" (traitée plus bas, troisième passe)
        // — pas de Domaine/Produits/Cyclique/Perspective à ce niveau-là,
        // donc rien à extraire ici pour cette boucle.
        continue;
    }

    [$symbolsPart] = explode('—', $headerLine, 2);
    $symbolsPart = trim($symbolsPart);
    // Retire un éventuel commentaire entre parenthèses collé aux symboles.
    $symbolsPart = preg_replace('/\(.*?\)/', '', $symbolsPart);
    $symbols = array_filter(array_map('trim', explode('/', $symbolsPart)));
    if (empty($symbols)) {
        continue;
    }

    $body = substr($chunk, strlen($headerMatch[0]));

    $cyclicalityText = extractField($body, 'Cyclique');
    $perspective = extractField($body, 'Perspective');
    $growth = extractField($body, 'Facteurs de hausse');
    $decline = extractField($body, 'Facteurs de baisse');
    $domain = extractField($body, 'Domaine');
    $products = extractField($body, 'Produits');
    $seasonalText = extractField($body, 'Saisonnière');
    $seasonalDetail = extractField($body, 'Détail saisonnier');
    $internationalPricingText = extractField($body, 'Prix international');

    foreach ($symbols as $symbol) {
        if ($cyclicalityText !== null) {
            $profiles[$symbol] = [
                'level' => mapCyclicality($cyclicalityText),
                'notes' => $cyclicalityText,
            ];
        }
        $companyProfile[$symbol] = [
            'description' => $domain,
            'products_services' => $products,
            'is_seasonal' => mapTriState($seasonalText),
            'seasonal_detail' => $seasonalDetail,
        ];
        if ($internationalPricingText !== null) {
            $internationalPricing[$symbol] = [
                'level' => mapTriState($internationalPricingText),
                'explanation' => $internationalPricingText,
            ];
        }
        $notes[$symbol] = array_merge($notes[$symbol] ?? [], [
            'perspective_generale' => $perspective,
            'facteur_hausse' => $growth,
            'facteur_baisse' => $decline,
        ]);
    }
}

// Deuxième passe : "## Leviers spécifiques par entreprise" (politique de
// dividende) — puces "- **SYMBOLE(S) (Nom)** : levier ... **Perspective
// (rémunération)** : ...", regroupées sous des sous-titres "### Secteur"
// non pertinents ici (on identifie chaque entreprise par ses symboles
// connus dans le libellé de la puce, pas par le sous-titre de secteur).
$leviersStart = mb_strpos($fullContent, '## Leviers spécifiques par entreprise');
$leviersEnd = mb_strpos($fullContent, '# Structure de base de données pour un suivi complet');
if ($leviersStart !== false && $leviersEnd !== false && $leviersEnd > $leviersStart) {
    $leviersSection = mb_substr($fullContent, $leviersStart, $leviersEnd - $leviersStart);
    $bulletChunks = preg_split('/\n(?=- \*\*)/', $leviersSection);

    foreach ($bulletChunks as $bulletChunk) {
        // Retire les éventuelles lignes de titre ("### Secteur") en tête de
        // bloc, restées collées au début du prochain "- **" après le split.
        $bulletChunk = preg_replace('/^(#{1,6} .*\n+)+/', '', $bulletChunk);
        if (!preg_match('/^- \*\*(.+?)\*\*\s*:\s*(.*)$/s', $bulletChunk, $m)) {
            continue;
        }
        $label = $m[1];
        // Retire un éventuel sous-titre "### SecteurSuivant" resté collé en
        // fin de bloc (même souci qu'en première passe, voir
        // stripTrailingArtifacts) — arrive pour le dernier symbole de
        // chaque sous-section.
        $rest = preg_replace('/\n#{1,6}\s.*$/s', '', $m[2]);
        $parts = preg_split('/\*\*Perspective \(rémunération\)\*\*\s*:\s*/u', $rest, 2);
        $levier = stripTrailingArtifacts(preg_replace('/\s*\n\s*/', ' ', $parts[0]));
        $perspectiveRem = isset($parts[1]) ? stripTrailingArtifacts(preg_replace('/\s*\n\s*/', ' ', $parts[1])) : null;
        $levier = $levier !== '' ? $levier : null;
        $perspectiveRem = ($perspectiveRem !== null && $perspectiveRem !== '') ? $perspectiveRem : null;

        $symbolsForBullet = extractKnownSymbols($label, $KNOWN_SYMBOLS);
        foreach ($symbolsForBullet as $symbol) {
            $notes[$symbol] = array_merge($notes[$symbol] ?? [], [
                'levier_remuneration' => $levier ?: null,
                'perspective_remuneration' => $perspectiveRem,
            ]);
        }
    }
}

// Troisième passe : "# Quand acheter, quand vendre : guide pédagogique par
// entreprise" — sous-titres "### SYMBOLE(S) (Nom)" (même format que la
// reprise "Leviers spécifiques", mais un contenu différent : puces
// "- **Acheter** : ..." / "- **Vendre** : ..."), avec parfois un paragraphe
// "Logique du secteur" en tête de sous-section (non extrait ici, propre à
// un secteur entier et non à une entreprise).
$buySellStart = mb_strpos($fullContent, '## Pour les débutants complets');
$buySellEnd = mb_strpos($fullContent, '# Leviers pour étudier la politique de rémunération');
if ($buySellStart !== false && $buySellEnd !== false && $buySellEnd > $buySellStart) {
    $buySellSection = mb_substr($fullContent, $buySellStart, $buySellEnd - $buySellStart);
    $buySellChunks = preg_split('/\n(?=#{2,3} )/', $buySellSection);

    foreach ($buySellChunks as $chunk) {
        if (!preg_match('/^### (.+)$/m', $chunk, $headerMatch)) {
            continue;
        }
        $body = substr($chunk, strlen($headerMatch[0]));
        $buy = extractField($body, 'Acheter');
        $sell = extractField($body, 'Vendre');
        if ($buy === null && $sell === null) {
            continue;
        }

        $symbolsForChunk = extractKnownSymbols($headerMatch[1], $KNOWN_SYMBOLS);
        foreach ($symbolsForChunk as $symbol) {
            $notes[$symbol] = array_merge($notes[$symbol] ?? [], [
                'signal_achat' => $buy,
                'signal_vente' => $sell,
            ]);
        }
    }
}

echo count($profiles) . " profils de cyclicité extraits, " . count($notes) . " fiches de notes extraites.\n";

$companyStmt = $pdo->prepare('SELECT id FROM companies WHERE symbol = ?');

$upsertProfile = $pdo->prepare(
    'INSERT INTO company_cyclicality_profile (company_id, cyclicality_level, notes)
     VALUES (:company_id, :level, :notes)
     ON DUPLICATE KEY UPDATE cyclicality_level = VALUES(cyclicality_level), notes = VALUES(notes)'
);

$updateCompanyProfile = $pdo->prepare(
    'UPDATE companies
     SET description = :description, products_services = :products_services,
         is_seasonal = :is_seasonal, seasonal_detail = :seasonal_detail
     WHERE id = :company_id'
);

// Ne touche qu'à la ligne « de référence » sans date (valid_from/valid_to
// tous deux NULL) posée par ce script — jamais aux entrées historisées
// qu'un admin aurait ajoutées depuis via l'écran dédié (avec une date), pour
// que ce script reste rejouable sans écraser un historique saisi à la main.
$deleteBaselinePricing = $pdo->prepare(
    'DELETE FROM company_international_pricing_history
     WHERE company_id = :company_id AND valid_from IS NULL AND valid_to IS NULL'
);
$insertPricing = $pdo->prepare(
    'INSERT INTO company_international_pricing_history (company_id, pricing_level, explanation, source_note)
     VALUES (:company_id, :level, :explanation, :source)'
);

$deleteNotes = $pdo->prepare(
    'DELETE FROM company_analysis_notes WHERE company_id = :company_id AND note_type = :note_type'
);
$insertNote = $pdo->prepare(
    'INSERT INTO company_analysis_notes (company_id, note_type, content) VALUES (:company_id, :note_type, :content)'
);

$noteTypeMap = [
    'perspective_generale' => 'perspective_generale',
    'facteur_hausse' => 'facteur_hausse',
    'facteur_baisse' => 'facteur_baisse',
    'signal_achat' => 'signal_achat',
    'signal_vente' => 'signal_vente',
    'levier_remuneration' => 'levier_remuneration',
    'perspective_remuneration' => 'perspective_remuneration',
];

$missingSymbols = [];
$pdo->beginTransaction();
try {
    $allSymbols = array_unique(array_merge(array_keys($profiles), array_keys($notes), array_keys($companyProfile), array_keys($internationalPricing)));
    sort($allSymbols);

    foreach ($allSymbols as $symbol) {
        $companyStmt->execute([$symbol]);
        $companyId = $companyStmt->fetchColumn();
        if ($companyId === false) {
            $missingSymbols[] = $symbol;
            continue;
        }

        if (isset($companyProfile[$symbol])) {
            $updateCompanyProfile->execute([
                ':company_id' => $companyId,
                ':description' => $companyProfile[$symbol]['description'],
                ':products_services' => $companyProfile[$symbol]['products_services'],
                ':is_seasonal' => $companyProfile[$symbol]['is_seasonal'],
                ':seasonal_detail' => $companyProfile[$symbol]['seasonal_detail'],
            ]);
        }

        if (isset($internationalPricing[$symbol])) {
            $deleteBaselinePricing->execute([':company_id' => $companyId]);
            $insertPricing->execute([
                ':company_id' => $companyId,
                ':level' => $internationalPricing[$symbol]['level'],
                ':explanation' => $internationalPricing[$symbol]['explanation'],
                ':source' => 'ANALYSE_ENTREPRISES_BRVM.md',
            ]);
        }

        if (isset($profiles[$symbol])) {
            $upsertProfile->execute([
                ':company_id' => $companyId,
                ':level' => $profiles[$symbol]['level'],
                ':notes' => $profiles[$symbol]['notes'],
            ]);
        }

        if (isset($notes[$symbol])) {
            foreach ($noteTypeMap as $key => $noteType) {
                $text = $notes[$symbol][$key] ?? null;
                if ($text === null) {
                    continue;
                }
                $deleteNotes->execute([':company_id' => $companyId, ':note_type' => $noteType]);
                $insertNote->execute([
                    ':company_id' => $companyId,
                    ':note_type' => $noteType,
                    ':content' => $text,
                ]);
            }
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

if (!empty($missingSymbols)) {
    echo "Symboles introuvables dans companies (ignorés) : " . implode(', ', $missingSymbols) . "\n";
}

$countProfiles = $pdo->query('SELECT COUNT(*) FROM company_cyclicality_profile')->fetchColumn();
$countNotes = $pdo->query('SELECT COUNT(*) FROM company_analysis_notes')->fetchColumn();
$countDescribed = $pdo->query("SELECT COUNT(*) FROM companies WHERE description IS NOT NULL AND description <> ''")->fetchColumn();
$countPricing = $pdo->query('SELECT COUNT(*) FROM company_international_pricing_history')->fetchColumn();
echo "Terminé. company_cyclicality_profile: $countProfiles lignes, company_analysis_notes: $countNotes lignes, "
    . "companies.description renseignée: $countDescribed lignes, company_international_pricing_history: $countPricing lignes.\n";
