<?php
/**
 * Peuple company_shareholders, company_business_relationships,
 * company_seasonality_calendar, company_esg_records,
 * company_governance_events, company_market_liquidity_snapshots, ainsi que
 * les 4 colonnes ajoutées à `companies` (listing_status, parent_group_name,
 * free_float_percent, dividend_regularity) — données transcrites à la main
 * depuis ANALYSE_ENTREPRISES_BRVM.md (sections "Partenaires et clients clés
 * par secteur", fiches "Détail saisonnier" par entreprise, et mentions
 * ponctuelles dans les fiches individuelles), plutôt que parsées
 * automatiquement : contrairement à company_cyclicality_profile/
 * company_analysis_notes (seed_company_deep_analysis.php), cette
 * information est éparpillée en prose libre, pas alignée sur des puces
 * "- **Label** :" régulières.
 *
 * Ne couvre que ce qui est explicitement vérifié/chiffré dans le document
 * (le document lui-même précise que les % d'actionnariat ci-dessous ont été
 * vérifiés par recherche web en août 2026) — pas de comblement des données
 * manquantes par supposition.
 *
 * Idempotent : DELETE puis INSERT par company_id (et par company_id+month
 * pour le calendrier saisonnier), rejouable sans risque.
 *
 * Usage : php scripts/seed_company_shareholders_and_more.php
 */

require_once __DIR__ . '/../config.php';

$db = getConfig('db');
$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset={$db['charset']}",
    $db['username'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

const SRC = 'ANALYSE_ENTREPRISES_BRVM.md, vérifié août 2026';

// ---------------------------------------------------------------------
// 1. company_shareholders
// ---------------------------------------------------------------------
// symbol => liste de ['name','type','percent'=>float|null,'ref'=>bool,'from'=>date|null,'to'=>date|null,'note'=>string|null]
$shareholders = [
    'BICB' => [
        ['name' => 'Caisse des Dépôts et Consignations du Bénin (CDC Bénin)', 'type' => 'etat', 'percent' => null, 'ref' => true, 'note' => 'Entité publique ; plus de 33% ouvert au public lors de l\'IPO d\'avril 2025'],
    ],
    'BICC' => [
        ['name' => 'Brandon & McCain Capital (Ahmed Cissé)', 'type' => 'fonds_investissement', 'percent' => 40.20, 'ref' => true, 'from' => '2026-07-01', 'note' => 'A racheté la part de la BNI en juillet 2026'],
        ['name' => 'IPS-CNPS', 'type' => 'banque_institution_financiere', 'percent' => 21.54, 'ref' => false],
        ['name' => 'Consortium ivoirien (BNI, CNPS, CDC-CI, CGRAE)', 'type' => 'banque_institution_financiere', 'percent' => null, 'ref' => false, 'from' => '2022-09-01', 'to' => '2026-07-01', 'note' => 'A remplacé BNP Paribas en septembre 2022 ; la BNI a cédé sa part en juillet 2026'],
    ],
    'BOAB' => null, 'BOABF' => null, 'BOAC' => null, 'BOAM' => null, 'BOAN' => null, 'BOAS' => null, // rempli via boucle ci-dessous
    'CBIBF' => [
        ['name' => 'Idrissa Nassa', 'type' => 'autre', 'percent' => null, 'ref' => true, 'note' => 'Fondateur (janvier 2008) et président du conseil d\'administration ; capital majoritairement burkinabè'],
    ],
    'ECOC' => [
        ['name' => 'Ecobank Transnational Incorporated (groupe Ecobank)', 'type' => 'banque_institution_financiere', 'percent' => null, 'ref' => true],
    ],
    'ETIT' => [
        ['name' => 'Qatar National Bank', 'type' => 'banque_institution_financiere', 'percent' => 20.95, 'ref' => true, 'from' => '2014-01-01'],
        ['name' => 'Arise BV', 'type' => 'fonds_investissement', 'percent' => 14.70, 'ref' => false],
        ['name' => 'Public Investment Corporation (Afrique du Sud)', 'type' => 'fonds_investissement', 'percent' => 14.05, 'ref' => false],
    ],
    'NSBC' => [
        ['name' => 'NSIA Vie Assurances Côte d\'Ivoire', 'type' => 'groupe_industriel', 'percent' => 31.55, 'ref' => true],
        ['name' => 'NSIA Participations', 'type' => 'groupe_industriel', 'percent' => 28.49, 'ref' => false],
        ['name' => 'Caisse Nationale de Prévoyance Sociale (CNPS)', 'type' => 'banque_institution_financiere', 'percent' => 17.65, 'ref' => false],
        ['name' => 'Public (flottant BRVM)', 'type' => 'flottant_public', 'percent' => 16.87, 'ref' => false],
        ['name' => 'IPS-CGRAE', 'type' => 'banque_institution_financiere', 'percent' => 5.00, 'ref' => false],
    ],
    'ORGT' => [
        ['name' => 'Emerging Capital Partners (ECP)', 'type' => 'fonds_investissement', 'percent' => null, 'ref' => true, 'from' => '2008-01-01', 'note' => 'En cours de cession partielle'],
        ['name' => 'Proparco', 'type' => 'fonds_investissement', 'percent' => null, 'ref' => false],
        ['name' => 'BIO', 'type' => 'fonds_investissement', 'percent' => null, 'ref' => false],
        ['name' => 'DEG', 'type' => 'fonds_investissement', 'percent' => null, 'ref' => false],
        ['name' => 'BOAD', 'type' => 'banque_institution_financiere', 'percent' => 40.00, 'ref' => false, 'note' => '40% dans plusieurs filiales (dont Orabank Côte d\'Ivoire), pas nécessairement au niveau du holding ORGT'],
        ['name' => 'Fonds Gabonais d\'Investissements Stratégiques', 'type' => 'fonds_investissement', 'percent' => null, 'ref' => false],
        ['name' => 'IPS-CGRAE (Côte d\'Ivoire)', 'type' => 'banque_institution_financiere', 'percent' => null, 'ref' => false],
    ],
    'SGBC' => [
        ['name' => 'Société Générale (France)', 'type' => 'groupe_industriel', 'percent' => null, 'ref' => true, 'note' => 'Actionnaire confirmé encore fin 2025 ; le groupe a cédé d\'autres filiales africaines (Burkina Faso, Congo, Guinée Équatoriale) à Vista Group'],
    ],
    'SIBC' => [
        ['name' => 'Attijariwafa Bank (via Attijari Ivoire Holding Offshore)', 'type' => 'banque_institution_financiere', 'percent' => 75.00, 'ref' => true, 'from' => '2015-01-01'],
    ],
    'CFAC' => [
        ['name' => 'Toyota Tsusho Corporation (via CFAO)', 'type' => 'groupe_industriel', 'percent' => 100.00, 'ref' => true, 'from' => '2016-01-01', 'note' => 'Détient 100% de CFAO, maison mère de CFAO Motors CI'],
    ],
    'PRSC' => [
        ['name' => 'TracTafric Motors Corporation (groupe OPTORG)', 'type' => 'groupe_industriel', 'percent' => null, 'ref' => true],
    ],
    'PALC' => [['name' => 'Groupe SIFCA', 'type' => 'groupe_industriel', 'percent' => null, 'ref' => true]],
    'SCRC' => [['name' => 'Groupe SIFCA', 'type' => 'groupe_industriel', 'percent' => null, 'ref' => true]],
    'SICC' => [['name' => 'Groupe SIFCA', 'type' => 'groupe_industriel', 'percent' => null, 'ref' => true]],
    'SOGC' => [['name' => 'Groupe SIFCA', 'type' => 'groupe_industriel', 'percent' => null, 'ref' => true]],
    'SPHC' => [
        ['name' => 'Groupe SIFCA', 'type' => 'groupe_industriel', 'percent' => null, 'ref' => true],
        ['name' => 'Michelin', 'type' => 'groupe_industriel', 'percent' => 14.80, 'ref' => false, 'note' => 'Également principal client de SAPH parmi les manufacturiers pneumatiques'],
    ],
    'CABC' => [['name' => 'Prysmian Group (Italie)', 'type' => 'groupe_industriel', 'percent' => null, 'ref' => true]],
    'NTLC' => [['name' => 'Nestlé (Suisse)', 'type' => 'groupe_industriel', 'percent' => null, 'ref' => true]],
    'SEMC' => [['name' => 'KPS Partners (fonds américain)', 'type' => 'fonds_investissement', 'percent' => null, 'ref' => true, 'from' => '2021-09-01', 'note' => 'Rachat d\'Eviosys (ex-Crown Siem) en septembre 2021']],
    'SLBC' => [['name' => 'BGI (Brasseries et Glacières Internationales) / groupe Castel', 'type' => 'groupe_industriel', 'percent' => 77.00, 'ref' => true]],
    'STAC' => [['name' => 'Bouygues Construction (groupe français)', 'type' => 'groupe_industriel', 'percent' => null, 'ref' => true, 'from' => '1950-01-01']],
    'STBC' => [['name' => 'Imperial Brands (Royaume-Uni)', 'type' => 'groupe_industriel', 'percent' => 73.00, 'ref' => true]],
    'UNLC' => [
        ['name' => 'Unilever (Royaume-Uni/Pays-Bas)', 'type' => 'groupe_industriel', 'percent' => null, 'ref' => true],
        ['name' => 'Public (flottant BRVM)', 'type' => 'flottant_public', 'percent' => 9.47, 'ref' => false, 'note' => 'Flottant sous le seuil réglementaire de 20% — titre suspendu de cotation'],
    ],
    'UNXC' => [
        ['name' => 'Compagnie Ivoirienne de Coton (COIC)', 'type' => 'groupe_industriel', 'percent' => 72.29, 'ref' => true, 'from' => '2026-02-01'],
        ['name' => 'Vlisco (Pays-Bas)', 'type' => 'groupe_industriel', 'percent' => 72.29, 'ref' => false, 'from' => '1967-01-01', 'to' => '2026-02-01', 'note' => 'Actionnaire historique depuis la création d\'Uniwax en 1967, a cédé sa participation à COIC en février 2026'],
    ],
    'SDSC' => [['name' => 'Groupe MSC (Suisse-Italie)', 'type' => 'groupe_industriel', 'percent' => 100.00, 'ref' => true, 'from' => '2022-12-01', 'note' => 'Rachat de 100% de Bolloré Africa Logistics en décembre 2022 (5,7 Md€)']],
    'ORAC' => [['name' => 'Groupe Orange (France)', 'type' => 'groupe_industriel', 'percent' => null, 'ref' => true]],
    'SNTS' => [
        ['name' => 'Orange S.A.', 'type' => 'groupe_industriel', 'percent' => 42.33, 'ref' => true],
        ['name' => 'État du Sénégal', 'type' => 'etat', 'percent' => 27.70, 'ref' => false],
        ['name' => 'Public (flottant BRVM)', 'type' => 'flottant_public', 'percent' => 20.00, 'ref' => false],
        ['name' => 'Salariés de Sonatel', 'type' => 'salaries', 'percent' => 10.00, 'ref' => false],
    ],
    'ONTBF' => [
        ['name' => 'Maroc Telecom', 'type' => 'groupe_industriel', 'percent' => 61.00, 'ref' => true],
        ['name' => 'État du Burkina Faso', 'type' => 'etat', 'percent' => 16.00, 'ref' => false],
    ],
    'CIEC' => [['name' => 'Eranove', 'type' => 'groupe_industriel', 'percent' => 54.02, 'ref' => true]],
    'SDCC' => [['name' => 'Eranove', 'type' => 'groupe_industriel', 'percent' => 46.07, 'ref' => true]],
    'SHEC' => [
        ['name' => 'Vitol', 'type' => 'groupe_industriel', 'percent' => null, 'ref' => true, 'from' => '2017-01-01', 'note' => 'Rachat des 20% restants détenus par Shell en 2017, aux côtés de Helios ; répartition exacte entre Vitol et Helios non précisée par la source'],
        ['name' => 'Helios Investment Partners', 'type' => 'fonds_investissement', 'percent' => null, 'ref' => false, 'from' => '2017-01-01'],
    ],
    'TTLC' => [['name' => 'TotalEnergies (France)', 'type' => 'groupe_industriel', 'percent' => null, 'ref' => true]],
    'TTLS' => [['name' => 'TotalEnergies (France)', 'type' => 'groupe_industriel', 'percent' => null, 'ref' => true]],
    'ABJC' => [['name' => 'Servair (groupe Air France-KLM)', 'type' => 'groupe_industriel', 'percent' => null, 'ref' => true, 'from' => '2008-01-01']],
    'LNBB' => [['name' => 'État béninois', 'type' => 'etat', 'percent' => null, 'ref' => true, 'note' => 'Tutelle directe de l\'État']],
];
foreach (['BOAB', 'BOABF', 'BOAC', 'BOAM', 'BOAN', 'BOAS'] as $boaSymbol) {
    $shareholders[$boaSymbol] = [
        ['name' => 'BMCE Bank of Africa (Maroc)', 'type' => 'banque_institution_financiere', 'percent' => 35.00, 'ref' => true, 'note' => '% au niveau du groupe Bank of Africa, pas nécessairement identique filiale par filiale'],
    ];
}

// ---------------------------------------------------------------------
// 2. company_business_relationships
// ---------------------------------------------------------------------
// symbol => liste de ['type','name','named'=>bool,'rank'=>int|null,'desc'=>string|null]
$relationships = [
    'CFAC' => [
        ['type' => 'licence_marque', 'name' => 'Toyota, Citroën, Peugeot, Mitsubishi, Yamaha, Suzuki, JCB, Bridgestone', 'named' => true, 'desc' => 'Marques distribuées par CFAO Motors CI ; ~42,6% de part de marché, 1er réseau du pays'],
    ],
    'PRSC' => [
        ['type' => 'licence_marque', 'name' => 'BMW, Hyundai, Ford, Mazda, JAC, Chery, MCV, Mercedes-Benz Trucks', 'named' => true, 'desc' => 'Marques distribuées par TracTafric Motors (pas Renault, distribué par le concurrent non coté SOCIDA/groupe Bernard-Hayot)'],
    ],
    'CABC' => [
        ['type' => 'client_principal', 'name' => 'CIE, Bouygues Énergies et Services, Sogelux, DMEIB', 'named' => true, 'rank' => 1, 'desc' => 'Clients locaux vérifiés'],
        ['type' => 'client_principal', 'name' => 'SONABEL (Burkina Faso), ENEO (Cameroun), EDM (Mali)', 'named' => true, 'rank' => 2, 'desc' => 'Clients à l\'export'],
    ],
    'FTSC' => [
        ['type' => 'client_principal', 'name' => 'Conseil du Café-Cacao, Conseil Coton-Anacarde', 'named' => true, 'desc' => 'Fournit les sacs des campagnes officielles depuis 1965'],
    ],
    'STAC' => [
        ['type' => 'client_categorie', 'name' => 'État ivoirien (ministères des infrastructures, agences routières), bailleurs internationaux (Banque mondiale, BAD, AFD, UE)', 'named' => true, 'desc' => 'Réalisations notables : 3e pont d\'Abidjan, extension du centre commercial Cap Sud, complexe universitaire INP-HB de Yamoussoukro'],
    ],
    'SOGC' => [
        ['type' => 'client_principal', 'name' => 'Michelin', 'named' => true, 'desc' => 'Principal client de SOGB parmi les manufacturiers pneumatiques'],
        ['type' => 'client_categorie', 'name' => 'Bridgestone, Continental, Goodyear', 'named' => true, 'desc' => 'Autres grands manufacturiers pneumatiques mondiaux — catégorie de clients, non individuellement vérifiés comme Michelin'],
    ],
    'SPHC' => [
        ['type' => 'client_principal', 'name' => 'Michelin', 'named' => true, 'desc' => 'Principal client de SAPH parmi les manufacturiers pneumatiques (Michelin est aussi actionnaire direct, voir company_shareholders)'],
        ['type' => 'client_categorie', 'name' => 'Bridgestone, Continental, Goodyear', 'named' => true, 'desc' => 'Autres grands manufacturiers pneumatiques mondiaux — catégorie de clients, non individuellement vérifiés comme Michelin'],
    ],
    'ABJC' => [
        ['type' => 'client_principal', 'name' => 'Air Côte d\'Ivoire, Air France', 'named' => true, 'rank' => 1, 'desc' => 'Deux principaux clients'],
        ['type' => 'client_principal', 'name' => 'Emirates, Afriqiyah Airways, Ethiopian Airlines, Kenya Airways, Air Burkina, South African Airways, Air Sénégal, Brussels Airlines', 'named' => true, 'rank' => 2, 'desc' => 'Autres compagnies clientes desservies'],
    ],
    'ORAC' => [
        ['type' => 'equipementier', 'name' => 'Ericsson, Huawei, Nokia', 'named' => true, 'desc' => 'Équipementiers réseau, selon les marchés'],
    ],
    'SNTS' => [
        ['type' => 'equipementier', 'name' => 'Ericsson, Huawei, Nokia', 'named' => true, 'desc' => 'Équipementiers réseau, selon les marchés'],
    ],
    'ONTBF' => [
        ['type' => 'equipementier', 'name' => 'Ericsson, Huawei, Nokia', 'named' => true, 'desc' => 'Équipementiers réseau, selon les marchés'],
    ],
];

// ---------------------------------------------------------------------
// 3. company_seasonality_calendar
// ---------------------------------------------------------------------
// symbol => ['haute' => [mois...], 'basse' => [mois...], 'note' => string|null]
$seasonality = [
    'BNBC' => ['haute' => [11,12,1,2,3,4], 'basse' => [5,6,7,8,9,10], 'note' => 'Chantiers BTP en saison sèche'],
    'CFAC' => ['haute' => [10,11,12], 'basse' => [6,7,8], 'note' => 'Démarrage campagne cacao/café, primes de fin d\'année'],
    'PRSC' => ['haute' => [8,9,10,11,12,1,2,3,4], 'basse' => [5,6,7], 'note' => 'Préparation campagne agricole (août-oct.) et saison sèche BTP (nov.-avril)'],
    'PALC' => ['haute' => [4,5,6,7,8,9], 'basse' => [12,1,2,3], 'note' => 'Grande traite (avril-sept.) / petite traite (déc.-mars)'],
    'SCRC' => ['haute' => [11,12,1,2,3,4], 'basse' => [6,7,8,9,10], 'note' => 'Campagne de coupe et broyage de la canne'],
    'SICC' => ['haute' => [11,12,1,2,3,4], 'basse' => [6,7,8,9,10], 'note' => 'Campagne de coupe et broyage de la canne'],
    'SOGC' => ['haute' => [5,6,7,8,9,10,11], 'basse' => [12,1,2], 'note' => 'Saignée hévéa en saison des pluies / arrêt en hivernage'],
    'SPHC' => ['haute' => [5,6,7,8,9,10,11], 'basse' => [12,1,2], 'note' => 'Saignée hévéa en saison des pluies / arrêt en hivernage'],
    'CABC' => ['haute' => [11,12,1,2,3,4], 'basse' => [5,6,7,8,9,10], 'note' => 'Chantiers de bâtiment et d\'électrification en saison sèche'],
    'FTSC' => ['haute' => [10,11,12], 'basse' => [4,5,6,7,8,9], 'note' => 'Démarrage campagne principale cacao/café'],
    'NTLC' => ['haute' => [9,12], 'basse' => [], 'note' => 'Rentrée scolaire (sept.) et fêtes (déc.) ; reste de l\'année stable'],
    'SEMC' => ['haute' => [5,6,7,8,9], 'basse' => [1,2,3], 'note' => 'Pic de la saison de pêche thonière'],
    'SIVC' => ['haute' => [4,5,6,7,8], 'basse' => [12,1,2,3], 'note' => 'Grande traite du palmier (approvisionnement abondant)'],
    'SLBC' => ['haute' => [12,1,2,3,4], 'basse' => [6,7,8,9], 'note' => 'Saison chaude et fêtes de fin d\'année'],
    'SMBC' => ['haute' => [11,12,1,2,3,4], 'basse' => [5,6,7,8,9,10], 'note' => 'Pose d\'enrobé en saison sèche'],
    'STAC' => ['haute' => [11,12,1,2,3,4], 'basse' => [5,6,7,8,9,10], 'note' => 'Gros œuvre/terrassement en saison sèche ; pic des pluies en juin-juillet particulièrement bas'],
    'UNLC' => ['haute' => [12], 'basse' => [], 'note' => 'Grand nettoyage et achats de fêtes ; reste de l\'année stable'],
    'UNXC' => ['haute' => [11,12], 'basse' => [1,2,3], 'note' => 'Fêtes de fin d\'année et cérémonies traditionnelles ; creux après les fêtes'],
    'SDSC' => ['haute' => [10,11,12,1,2,3,4,5], 'basse' => [6,7,8,9], 'note' => 'Campagne cacao/café (oct.-janv.) puis anacarde (fév.-mai)'],
    'CIEC' => ['haute' => [2,3,4], 'basse' => [6,7,8,9], 'note' => 'Pic de chaleur/climatisation en fin de saison sèche'],
    'SDCC' => ['haute' => [11,12,1,2,3,4], 'basse' => [5,6,7,8,9,10], 'note' => 'Saison sèche et chaude, pic février-avril'],
    'SHEC' => ['haute' => [10,11,12,1,7,8], 'basse' => [6,9], 'note' => 'Transport des récoltes cacao/café (oct.-janv.) et vacances (juil.-août)'],
    'TTLC' => ['haute' => [10,11,12,1], 'basse' => [6,7,8,9], 'note' => 'Campagne cacao/café et périodes de fêtes/vacances'],
    'TTLS' => ['haute' => [11,12,1,2,3], 'basse' => [7,8,9], 'note' => 'Campagne arachidière (nov.-mars) ; hivernage (juil.-sept.)'],
    'ABJC' => ['haute' => [12,1,7,8], 'basse' => [2,6], 'note' => 'Fêtes de fin d\'année et grands départs en vacances (juillet-août)'],
    'LNBB' => ['haute' => [12], 'basse' => [], 'note' => 'Fêtes de fin d\'année, tirages spéciaux ; reste de l\'année sans creux marqué'],
    'NEIC' => ['haute' => [7,8,9,10], 'basse' => [11,12,1,2,3,4,5,6], 'note' => 'Préparatifs et rentrée scolaire (juillet-octobre)'],
];

// ---------------------------------------------------------------------
// 4. Colonnes companies (listing_status, parent_group_name,
// free_float_percent, dividend_regularity)
// ---------------------------------------------------------------------
$companyUpdates = [
    'BICC' => ['parent_group_name' => 'Brandon & McCain Capital'],
    'CBIBF' => ['parent_group_name' => 'Groupe Coris (Idrissa Nassa)'],
    'ETIT' => ['parent_group_name' => 'Qatar National Bank'],
    'NSBC' => ['parent_group_name' => 'Groupe NSIA'],
    'SGBC' => ['parent_group_name' => 'Société Générale'],
    'SIBC' => ['parent_group_name' => 'Attijariwafa Bank', 'dividend_regularity' => 'reguliere'],
    'CFAC' => ['parent_group_name' => 'Toyota Tsusho / CFAO'],
    'PRSC' => ['parent_group_name' => 'OPTORG / TracTafric Motors'],
    'PALC' => ['parent_group_name' => 'Groupe SIFCA'],
    'SCRC' => ['parent_group_name' => 'Groupe SIFCA'],
    'SICC' => ['parent_group_name' => 'Groupe SIFCA'],
    'SOGC' => ['parent_group_name' => 'Groupe SIFCA'],
    'SPHC' => ['parent_group_name' => 'Groupe SIFCA'],
    'CABC' => ['parent_group_name' => 'Prysmian Group'],
    'NTLC' => ['parent_group_name' => 'Nestlé'],
    'SEMC' => ['parent_group_name' => 'KPS Partners (Eviosys)'],
    'SLBC' => ['parent_group_name' => 'Groupe Castel (BGI)'],
    'STAC' => ['parent_group_name' => 'Bouygues Construction'],
    'STBC' => ['parent_group_name' => 'Imperial Brands'],
    'UNLC' => [
        'parent_group_name' => 'Unilever',
        'listing_status' => 'suspendue',
        'free_float_percent' => 9.47,
        'dividend_regularity' => 'suspendue',
    ],
    'UNXC' => ['parent_group_name' => 'Compagnie Ivoirienne de Coton (COIC)'],
    'SDSC' => ['parent_group_name' => 'Groupe MSC'],
    'ORAC' => ['parent_group_name' => 'Groupe Orange'],
    'SNTS' => ['parent_group_name' => 'Orange S.A.', 'dividend_regularity' => 'reguliere'],
    'ONTBF' => ['parent_group_name' => 'Maroc Telecom'],
    'CIEC' => ['parent_group_name' => 'Eranove'],
    'SDCC' => ['parent_group_name' => 'Eranove'],
    'SHEC' => ['parent_group_name' => 'Vitol / Helios Investment Partners'],
    'TTLC' => ['parent_group_name' => 'TotalEnergies'],
    'TTLS' => ['parent_group_name' => 'TotalEnergies'],
    'ABJC' => ['parent_group_name' => 'Servair / Air France-KLM'],
    'ORGT' => ['dividend_regularity' => 'irreguliere'],
];
foreach (['BOAB', 'BOABF', 'BOAC', 'BOAM', 'BOAN', 'BOAS'] as $boaSymbol) {
    $companyUpdates[$boaSymbol] = ['parent_group_name' => 'Bank of Africa (BMCE)'];
}

// ---------------------------------------------------------------------
// 5. company_esg_records
// ---------------------------------------------------------------------
$esgRecords = [
    'SOGC' => [
        ['type' => 'conformite_reglementaire', 'title' => 'RDUE — Règlement européen anti-déforestation', 'desc' => 'Nouvelles exigences de traçabilité anti-déforestation pour les exportations de caoutchouc vers l\'UE', 'status' => 'en cours'],
    ],
    'SPHC' => [
        ['type' => 'conformite_reglementaire', 'title' => 'RDUE — Règlement européen anti-déforestation', 'desc' => 'Nouvelles normes européennes de traçabilité anti-déforestation exigeant des efforts de certification supplémentaires pour les exportations de caoutchouc vers l\'UE', 'status' => 'en cours'],
    ],
];

// ---------------------------------------------------------------------
// 6. company_governance_events
// ---------------------------------------------------------------------
$governanceEvents = [
    'CIEC' => [
        ['type' => 'renouvellement_licence_concession', 'date' => '2032-12-31', 'estimated' => 1, 'desc' => 'Échéance actuelle de la concession de transport/distribution d\'électricité avec l\'État ivoirien (période 2020-2032)'],
    ],
];

// ---------------------------------------------------------------------
// 7. company_market_liquidity_snapshots
// ---------------------------------------------------------------------
$liquiditySnapshots = [
    'UNLC' => [
        'date' => '2026-08-20',
        'free_float_percent' => 9.47,
        'is_suspended' => 1,
        'suspension_reason' => 'Flottant sous le seuil réglementaire de 20% exigé par la BRVM',
        'source_note' => SRC,
    ],
];

// ---------------------------------------------------------------------
// Exécution
// ---------------------------------------------------------------------

$companyIdStmt = $pdo->prepare('SELECT id FROM companies WHERE symbol = ?');
function getCompanyId(PDO $pdo, string $symbol): ?int {
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare('SELECT id FROM companies WHERE symbol = ?');
    }
    $stmt->execute([$symbol]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

$deleteShareholders = $pdo->prepare('DELETE FROM company_shareholders WHERE company_id = ?');
$insertShareholder = $pdo->prepare(
    'INSERT INTO company_shareholders
        (company_id, shareholder_name, shareholder_type, ownership_percent, is_reference_shareholder, valid_from, valid_to, source_note)
     VALUES (:company_id, :name, :type, :percent, :ref, :from, :to, :source)'
);

$deleteRelationships = $pdo->prepare('DELETE FROM company_business_relationships WHERE company_id = ?');
$insertRelationship = $pdo->prepare(
    'INSERT INTO company_business_relationships
        (company_id, relationship_type, counterparty_name, is_named, rank_importance, description, source_note)
     VALUES (:company_id, :type, :name, :named, :rank, :desc, :source)'
);

$deleteSeasonality = $pdo->prepare('DELETE FROM company_seasonality_calendar WHERE company_id = ?');
$insertSeasonality = $pdo->prepare(
    'INSERT INTO company_seasonality_calendar (company_id, month, activity_level, note)
     VALUES (:company_id, :month, :level, :note)'
);

$updateCompanyStmts = [];
foreach (['listing_status', 'parent_group_name', 'free_float_percent', 'dividend_regularity'] as $col) {
    $updateCompanyStmts[$col] = $pdo->prepare("UPDATE companies SET $col = ? WHERE id = ?");
}

$deleteEsg = $pdo->prepare('DELETE FROM company_esg_records WHERE company_id = ? AND title = ?');
$insertEsg = $pdo->prepare(
    'INSERT INTO company_esg_records (company_id, record_type, title, description, status)
     VALUES (:company_id, :type, :title, :desc, :status)'
);

$deleteGovernance = $pdo->prepare('DELETE FROM company_governance_events WHERE company_id = ? AND event_type = ? AND event_date = ?');
$insertGovernance = $pdo->prepare(
    'INSERT INTO company_governance_events (company_id, event_type, event_date, is_estimated, description)
     VALUES (:company_id, :type, :date, :estimated, :desc)'
);

$deleteLiquidity = $pdo->prepare('DELETE FROM company_market_liquidity_snapshots WHERE company_id = ? AND snapshot_date = ?');
$insertLiquidity = $pdo->prepare(
    'INSERT INTO company_market_liquidity_snapshots
        (company_id, snapshot_date, free_float_percent, is_suspended, suspension_reason, source_note)
     VALUES (:company_id, :date, :float, :suspended, :reason, :source)'
);

$missing = [];
$counts = ['shareholders' => 0, 'relationships' => 0, 'seasonality' => 0, 'companies' => 0, 'esg' => 0, 'governance' => 0, 'liquidity' => 0];

$pdo->beginTransaction();
try {
    foreach ($shareholders as $symbol => $rows) {
        if ($rows === null) {
            continue;
        }
        $companyId = getCompanyId($pdo, $symbol);
        if ($companyId === null) {
            $missing[] = $symbol;
            continue;
        }
        $deleteShareholders->execute([$companyId]);
        foreach ($rows as $row) {
            $insertShareholder->execute([
                ':company_id' => $companyId,
                ':name' => $row['name'],
                ':type' => $row['type'],
                ':percent' => $row['percent'] ?? null,
                ':ref' => !empty($row['ref']) ? 1 : 0,
                ':from' => $row['from'] ?? null,
                ':to' => $row['to'] ?? null,
                ':source' => trim((SRC) . (isset($row['note']) ? ' — ' . $row['note'] : '')),
            ]);
            $counts['shareholders']++;
        }
    }

    foreach ($relationships as $symbol => $rows) {
        $companyId = getCompanyId($pdo, $symbol);
        if ($companyId === null) {
            $missing[] = $symbol;
            continue;
        }
        $deleteRelationships->execute([$companyId]);
        foreach ($rows as $row) {
            $insertRelationship->execute([
                ':company_id' => $companyId,
                ':type' => $row['type'],
                ':name' => $row['name'],
                ':named' => !empty($row['named']) ? 1 : 0,
                ':rank' => $row['rank'] ?? null,
                ':desc' => $row['desc'] ?? null,
                ':source' => SRC,
            ]);
            $counts['relationships']++;
        }
    }

    foreach ($seasonality as $symbol => $data) {
        $companyId = getCompanyId($pdo, $symbol);
        if ($companyId === null) {
            $missing[] = $symbol;
            continue;
        }
        $deleteSeasonality->execute([$companyId]);
        foreach ($data['haute'] as $month) {
            $insertSeasonality->execute([':company_id' => $companyId, ':month' => $month, ':level' => 'haute', ':note' => $data['note']]);
            $counts['seasonality']++;
        }
        foreach ($data['basse'] as $month) {
            $insertSeasonality->execute([':company_id' => $companyId, ':month' => $month, ':level' => 'basse', ':note' => $data['note']]);
            $counts['seasonality']++;
        }
    }

    foreach ($companyUpdates as $symbol => $cols) {
        $companyId = getCompanyId($pdo, $symbol);
        if ($companyId === null) {
            $missing[] = $symbol;
            continue;
        }
        foreach ($cols as $col => $value) {
            $updateCompanyStmts[$col]->execute([$value, $companyId]);
            $counts['companies']++;
        }
    }

    foreach ($esgRecords as $symbol => $rows) {
        $companyId = getCompanyId($pdo, $symbol);
        if ($companyId === null) {
            $missing[] = $symbol;
            continue;
        }
        foreach ($rows as $row) {
            $deleteEsg->execute([$companyId, $row['title']]);
            $insertEsg->execute([
                ':company_id' => $companyId,
                ':type' => $row['type'],
                ':title' => $row['title'],
                ':desc' => $row['desc'],
                ':status' => $row['status'],
            ]);
            $counts['esg']++;
        }
    }

    foreach ($governanceEvents as $symbol => $rows) {
        $companyId = getCompanyId($pdo, $symbol);
        if ($companyId === null) {
            $missing[] = $symbol;
            continue;
        }
        foreach ($rows as $row) {
            $deleteGovernance->execute([$companyId, $row['type'], $row['date']]);
            $insertGovernance->execute([
                ':company_id' => $companyId,
                ':type' => $row['type'],
                ':date' => $row['date'],
                ':estimated' => $row['estimated'],
                ':desc' => $row['desc'],
            ]);
            $counts['governance']++;
        }
    }

    foreach ($liquiditySnapshots as $symbol => $row) {
        $companyId = getCompanyId($pdo, $symbol);
        if ($companyId === null) {
            $missing[] = $symbol;
            continue;
        }
        $deleteLiquidity->execute([$companyId, $row['date']]);
        $insertLiquidity->execute([
            ':company_id' => $companyId,
            ':date' => $row['date'],
            ':float' => $row['free_float_percent'],
            ':suspended' => $row['is_suspended'],
            ':reason' => $row['suspension_reason'],
            ':source' => $row['source_note'],
        ]);
        $counts['liquidity']++;
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
    echo "$label: $n lignes/mises à jour\n";
}
