<?php
/**
 * Peuple company_subsidiaries et company_market_position (migration 034)
 * pour les quelques entreprises où ANALYSE_ENTREPRISES_BRVM.md donne des
 * faits concrets et nommés — pas une extraction automatique (le texte
 * source n'est pas structuré en puces régulières pour ces deux notions,
 * contrairement à Domaine/Produits/Cyclique) : données transcrites à la
 * main, seulement quand explicitement nommées par le document (jamais de
 * filiale ou de rang inventé pour compléter une liste).
 *
 * Idempotent : DELETE puis INSERT par company_id, rejouable sans risque.
 *
 * Usage : php scripts/seed_company_subsidiaries_and_positioning.php
 */

require_once __DIR__ . '/../config.php';

$db = getConfig('db');
$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset={$db['charset']}",
    $db['username'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

const SRC = 'ANALYSE_ENTREPRISES_BRVM.md';

// ---------------------------------------------------------------------
// 1. company_subsidiaries
// ---------------------------------------------------------------------
// symbol => liste de ['name','country'=>?,'percent'=>?,'linked_symbol'=>?,'note'=>?]
$subsidiaries = [
    'ORGT' => [
        ['name' => 'Orabank Côte d\'Ivoire', 'country' => 'Côte d\'Ivoire', 'percent' => 40.00, 'note' => 'BOAD conserve 40% du capital de cette filiale'],
        ['name' => 'Orabank Burkina Faso', 'country' => 'Burkina Faso'],
        ['name' => 'Orabank Guinée-Bissau', 'country' => 'Guinée-Bissau'],
        ['name' => 'Orabank Mali', 'country' => 'Mali'],
        ['name' => 'Orabank Niger', 'country' => 'Niger'],
        ['name' => 'Orabank Sénégal', 'country' => 'Sénégal'],
    ],
    'ETIT' => [
        ['name' => 'Ecobank Côte d\'Ivoire (ECOC)', 'country' => 'Côte d\'Ivoire', 'linked_symbol' => 'ECOC', 'note' => 'Filiale du groupe Ecobank également cotée à la BRVM'],
        ['name' => 'Ecobank Nigeria', 'country' => 'Nigeria', 'note' => 'Marché clé du groupe, exposé au risque de dévaluation du naira'],
        ['name' => 'Ecobank Ghana', 'country' => 'Ghana', 'note' => 'Marché clé du groupe, exposé au risque de dévaluation du cedi'],
        ['name' => 'Ecobank RD Congo', 'country' => 'RD Congo', 'note' => 'Marché à coût du risque élevé cité par le document'],
    ],
    'SNTS' => [
        ['name' => 'Orange Mali', 'country' => 'Mali'],
        ['name' => 'Orange Guinée', 'country' => 'Guinée'],
        ['name' => 'Orange Guinée-Bissau', 'country' => 'Guinée-Bissau'],
        ['name' => 'Orange Sierra Leone', 'country' => 'Sierra Leone'],
    ],
];

// ---------------------------------------------------------------------
// 2. company_market_position
// ---------------------------------------------------------------------
// symbol => liste de ['scope','category','rank'=>?,'label','share'=>?]
$positions = [
    'BICB' => [
        ['scope' => 'national', 'category' => 'Secteur bancaire béninois', 'label' => 'Leader du secteur bancaire béninois'],
    ],
    'SIBC' => [
        ['scope' => 'regional', 'category' => 'Banques de l\'UMOA', 'rank' => 7, 'label' => '7e banque de l\'UMOA par le total de bilan'],
    ],
    'CFAC' => [
        ['scope' => 'national', 'category' => 'Distribution automobile', 'rank' => 1, 'label' => '1er réseau automobile du pays', 'share' => 42.60],
    ],
    'PALC' => [
        ['scope' => 'national', 'category' => 'Agro-industrie', 'rank' => 1, 'label' => 'Groupe SIFCA, 1er groupe agro-industriel ivoirien'],
    ],
    'SCRC' => [
        ['scope' => 'national', 'category' => 'Agro-industrie', 'rank' => 1, 'label' => 'Groupe SIFCA, 1er groupe agro-industriel ivoirien'],
    ],
    'SICC' => [
        ['scope' => 'national', 'category' => 'Agro-industrie', 'rank' => 1, 'label' => 'Groupe SIFCA, 1er groupe agro-industriel ivoirien'],
    ],
    'SOGC' => [
        ['scope' => 'national', 'category' => 'Agro-industrie', 'rank' => 1, 'label' => 'Groupe SIFCA, 1er groupe agro-industriel ivoirien'],
    ],
    'SPHC' => [
        ['scope' => 'national', 'category' => 'Agro-industrie', 'rank' => 1, 'label' => 'Groupe SIFCA, 1er groupe agro-industriel ivoirien'],
        ['scope' => 'national', 'category' => 'Production d\'hévéa', 'rank' => 1, 'label' => '1er producteur ivoirien d\'hévéa'],
    ],
    'SDSC' => [
        ['scope' => 'local', 'category' => 'Terminaux à conteneurs (port d\'Abidjan)', 'rank' => 1, 'label' => 'Abidjan Terminal, 1er terminal à conteneurs du port d\'Abidjan'],
    ],
    'SNTS' => [
        ['scope' => 'regional', 'category' => 'Opérateurs télécoms', 'rank' => 1, 'label' => '1er opérateur télécoms d\'Afrique de l\'Ouest francophone'],
    ],
    'NEIC' => [
        ['scope' => 'national', 'category' => 'Édition', 'rank' => 1, 'label' => '1er éditeur de Côte d\'Ivoire'],
    ],
    'STBC' => [
        ['scope' => 'national', 'category' => 'Fabrication de cigarettes', 'rank' => 1, 'label' => 'Seul fabricant de cigarettes du pays', 'share' => 88.00],
    ],
];

// ---------------------------------------------------------------------
// Exécution
// ---------------------------------------------------------------------

function getCompanyId(PDO $pdo, string $symbol): ?int {
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare('SELECT id FROM companies WHERE symbol = ?');
    }
    $stmt->execute([$symbol]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

$deleteSubs = $pdo->prepare('DELETE FROM company_subsidiaries WHERE company_id = ?');
$insertSub = $pdo->prepare(
    'INSERT INTO company_subsidiaries (company_id, subsidiary_name, country, ownership_percent, linked_company_id, description, source_note)
     VALUES (:company_id, :name, :country, :percent, :linked_id, :desc, :source)'
);

$deletePositions = $pdo->prepare('DELETE FROM company_market_position WHERE company_id = ?');
$insertPosition = $pdo->prepare(
    'INSERT INTO company_market_position (company_id, scope, category, rank_value, rank_label, market_share_percent, source_note)
     VALUES (:company_id, :scope, :category, :rank, :label, :share, :source)'
);

$missing = [];
$counts = ['subsidiaries' => 0, 'positions' => 0];

$pdo->beginTransaction();
try {
    foreach ($subsidiaries as $symbol => $rows) {
        $companyId = getCompanyId($pdo, $symbol);
        if ($companyId === null) {
            $missing[] = $symbol;
            continue;
        }
        $deleteSubs->execute([$companyId]);
        foreach ($rows as $row) {
            $linkedId = isset($row['linked_symbol']) ? getCompanyId($pdo, $row['linked_symbol']) : null;
            $insertSub->execute([
                ':company_id' => $companyId,
                ':name' => $row['name'],
                ':country' => $row['country'] ?? null,
                ':percent' => $row['percent'] ?? null,
                ':linked_id' => $linkedId,
                ':desc' => $row['note'] ?? null,
                ':source' => SRC,
            ]);
            $counts['subsidiaries']++;
        }
    }

    foreach ($positions as $symbol => $rows) {
        $companyId = getCompanyId($pdo, $symbol);
        if ($companyId === null) {
            $missing[] = $symbol;
            continue;
        }
        $deletePositions->execute([$companyId]);
        foreach ($rows as $row) {
            $insertPosition->execute([
                ':company_id' => $companyId,
                ':scope' => $row['scope'],
                ':category' => $row['category'],
                ':rank' => $row['rank'] ?? null,
                ':label' => $row['label'],
                ':share' => $row['share'] ?? null,
                ':source' => SRC,
            ]);
            $counts['positions']++;
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

if (!empty($missing)) {
    echo "Symboles introuvables dans companies (ignorés) : " . implode(', ', array_unique($missing)) . "\n";
}

foreach ($counts as $label => $n) {
    echo "$label: $n lignes\n";
}
