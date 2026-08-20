# Analyse des 47 entreprises cotées à la BRVM

Ce document passe en revue les 47 entreprises actuellement cotées à la Bourse
Régionale des Valeurs Mobilières (BRVM), telles que présentes dans la table
`companies` de l'application (voir `migrations/007_seed_company_sectors.sql`
pour la classification par secteur). Pour chacune :

- **Domaine d'activité** : ce que fait réellement l'entreprise.
- **Produits / services** : ses principales lignes de produits ou services.
- **Entreprise saisonnière ?** : son activité varie-t-elle fortement selon les
  saisons (campagnes agricoles, saison sèche/pluies, rentrée scolaire, fêtes) ?
  Quand cela s'applique, un **détail saisonnier** précise la période de haute
  et de basse activité/production dans l'année.
- **Prix fixés à l'international ?** : le prix de vente (ou au minimum le coût
  de ses intrants) dépend-il de cours mondiaux (matières premières cotées,
  pétrole, métaux) plutôt que d'un prix purement local/régulé ?
- **Produits cycliques ?** : la demande/le résultat de l'entreprise suit-il le
  cycle économique général (croissance/récession) ou les cycles de cours des
  matières premières mondiales — par opposition à une activité "défensive"
  (biens de première nécessité, services essentiels) dont la demande reste
  stable quelle que soit la conjoncture ?
- **Facteurs de hausse / de baisse** : les principaux éléments qui peuvent
  faire progresser ou régresser l'activité et les résultats de l'entreprise
  (conjoncture, cours mondiaux, politique publique, concurrence, climat,
  stabilité politique/sécuritaire de son pays d'implantation, etc.).
- **Perspective** : une lecture prospective — les tendances de fond et
  points de vigilance à surveiller pour anticiper la trajectoire de
  l'entreprise dans les prochaines années (transition énergétique,
  digitalisation, démographie, climat, contexte géopolitique régional —
  notamment la sortie du Mali, du Burkina Faso et du Niger de la CEDEAO au
  sein de l'Alliance des États du Sahel, effective depuis 2025).

> ⚠️ Analyse basée sur la connaissance générale de ces groupes et de leur
> secteur d'activité (classification établie dans la migration 007). Elle ne
> remplace pas les rapports annuels / notes d'information officielles de
> chaque société pour des décisions d'investissement — les activités,
> l'actionnariat ou le positionnement de certaines filiales peuvent avoir
> évolué depuis la dernière mise à jour de ce document.

## Vue d'ensemble

| Symbole | Nom | Secteur | Saisonnière | Prix international | Cyclique |
|---|---|---|---|---|---|
| ABJC | Servair Abidjan Côte d'Ivoire | Services | Partiellement | Non | Oui |
| BICB | Banque Internationale pour l'Industrie et le Commerce du Bénin | Banques | Non | Non | Oui (modéré) |
| BICC | BICI Côte d'Ivoire | Banques | Non | Non | Oui (modéré) |
| BNBC | Bernabé Côte d'Ivoire | Distribution | Partiellement | Partiellement | Oui |
| BOAB | Bank of Africa Bénin | Banques | Non | Non | Oui (modéré) |
| BOABF | Bank of Africa Burkina Faso | Banques | Non | Non | Oui (modéré) |
| BOAC | Bank of Africa Côte d'Ivoire | Banques | Non | Non | Oui (modéré) |
| BOAM | Bank of Africa Mali | Banques | Non | Non | Oui (modéré) |
| BOAN | Bank of Africa Niger | Banques | Non | Non | Oui (modéré) |
| BOAS | Bank of Africa Sénégal | Banques | Non | Non | Oui (modéré) |
| CABC | Sicable Côte d'Ivoire | Industrie | Partiellement | Partiellement | Oui |
| CBIBF | Coris Bank International Burkina Faso | Banques | Non | Non | Oui (modéré) |
| CFAC | CFAO Motors Côte d'Ivoire | Distribution | Partiellement | Partiellement | Oui |
| CIEC | CIE Côte d'Ivoire | Énergie | Partiellement | Partiellement | Non |
| ECOC | Ecobank Côte d'Ivoire | Banques | Non | Non | Oui (modéré) |
| ETIT | Ecobank Transnational Incorporated (Togo) | Banques | Non | Non | Oui (modéré) |
| FTSC | Filtisac Côte d'Ivoire | Industrie | Partiellement | Partiellement | Oui (modéré) |
| LNBB | Loterie Nationale du Bénin | Services | Partiellement | Non | Non |
| NEIC | NEI-CEDA Côte d'Ivoire | Services | Oui | Partiellement | Non |
| NSBC | NSIA Banque Côte d'Ivoire | Banques | Non | Non | Oui (modéré) |
| NTLC | Nestlé Côte d'Ivoire | Industrie | Partiellement | Partiellement | Non |
| ONTBF | Onatel Burkina Faso (Moov Africa) | Télécommunications | Non | Non | Non |
| ORAC | Orange Côte d'Ivoire | Télécommunications | Non | Non | Non |
| ORGT | Oragroup Togo | Banques | Non | Non | Oui (modéré) |
| PALC | Palm Côte d'Ivoire | Agriculture | Oui | Oui | Oui (fort) |
| PRSC | TracTafric Motors Côte d'Ivoire | Distribution | Partiellement | Partiellement | Oui |
| SAFC | Safca Côte d'Ivoire | Services | Non | Non | Oui |
| SCRC | Sucrivoire Côte d'Ivoire | Agriculture | Oui | Partiellement | Oui (fort) |
| SDCC | Sodeci Côte d'Ivoire | Énergie | Partiellement | Non | Non |
| SDSC | Africa Global Logistics Côte d'Ivoire | Transport | Oui | Partiellement | Oui (fort) |
| SEMC | Eviosys Packaging Siem Côte d'Ivoire | Industrie | Partiellement | Partiellement | Non |
| SGBC | Société Générale Côte d'Ivoire | Banques | Non | Non | Oui (modéré) |
| SHEC | Vivo Energy Côte d'Ivoire | Énergie | Partiellement | Oui | Oui (modéré) |
| SIBC | Société Ivoirienne de Banque | Banques | Non | Non | Oui (modéré) |
| SICC | Sicor Côte d'Ivoire | Agriculture | Oui | Partiellement | Oui (fort) |
| SIVC | Erium Côte d'Ivoire | Industrie | Partiellement | Partiellement | Non |
| SLBC | Solibra Côte d'Ivoire | Industrie | Oui | Partiellement | Oui (modéré) |
| SMBC | SMB Côte d'Ivoire | Industrie | Partiellement | Oui | Oui (fort) |
| SNTS | Sonatel Sénégal | Télécommunications | Non | Non | Non |
| SOGC | SOGB Côte d'Ivoire | Agriculture | Oui | Oui | Oui (fort) |
| SPHC | SAPH Côte d'Ivoire | Agriculture | Oui | Oui | Oui (fort) |
| STAC | Setao Côte d'Ivoire | Industrie | Partiellement | Non | Oui (fort) |
| STBC | Sitab Côte d'Ivoire | Industrie | Non | Partiellement | Non |
| TTLC | TotalEnergies Marketing Côte d'Ivoire | Énergie | Partiellement | Oui | Oui (modéré) |
| TTLS | TotalEnergies Marketing Sénégal | Énergie | Partiellement | Oui | Oui (modéré) |
| UNLC | Unilever Côte d'Ivoire | Industrie | Partiellement | Partiellement | Non |
| UNXC | Uniwax Côte d'Ivoire | Industrie | Oui | Partiellement | Oui |

---

## Banques (15)

Secteur globalement **non saisonnier** (l'activité de crédit/dépôt est
récurrente toute l'année, même si certains pics existent autour des campagnes
agricoles qu'elles financent, ou en fin d'année pour les retraits) et dont les
prix (taux d'intérêt, frais) sont **fixés localement**, encadrés par la banque
centrale régionale (BCEAO) plutôt que par un marché mondial — même si les taux
directeurs mondiaux influencent indirectement les conditions de refinancement.
Le secteur reste **modérément cyclique** : la demande de crédit et le coût du
risque suivent la croissance économique régionale et la santé des filières
agricoles d'exportation que les banques financent, sans être aussi volatils
qu'un cours de matière première.

### BICB — Banque Internationale pour l'Industrie et le Commerce du Bénin (BIIC)
- **Domaine** : banque commerciale de détail et d'entreprise au Bénin, née
  en 2020 de la fusion entre la Banque Internationale du Bénin (BIBE) et la
  Banque Africaine pour l'Industrie et le Commerce (BAIC) ; leader du
  secteur bancaire béninois. Actionnaire de référence : la Caisse des
  Dépôts et Consignations du Bénin (CDC Bénin, entité publique). L'État a
  introduit la banque à la BRVM en avril 2025 en ouvrant plus de 33 % du
  capital au public.
- **Produits** : comptes courants/épargne, crédits aux particuliers et PME,
  financement du commerce, services de trésorerie.
- **Saisonnière** : Non.
- **Prix international** : Non.
- **Cyclique** : Oui, modérément — l'activité de crédit suit le cycle
  économique béninois, en particulier la santé de la filière coton
  (principal moteur agricole du pays) qu'elle contribue à financer.
- **Facteurs de hausse** : croissance économique du Bénin et de l'UEMOA,
  hausse de la bancarisation, bons résultats de la campagne cotonnière,
  soutien et expertise du groupe actionnaire.
- **Facteurs de baisse** : ralentissement économique, hausse des créances
  douteuses en cas de mauvaise campagne cotonnière, durcissement des ratios
  prudentiels BCEAO, concurrence du mobile money/microfinance.
- **Perspective** : croissance attendue portée par la bancarisation continue
  et le redressement de la filière coton béninoise ; à surveiller : le
  rythme de digitalisation et l'exposition au risque souverain béninois.

### BICC — BICI Côte d'Ivoire
- **Domaine** : banque commerciale ivoirienne. **Important** : BNP Paribas
  a cédé sa participation en septembre 2022 à un consortium ivoirien
  (BNI, CNPS, CDC-CI, CGRAE) ; depuis juillet 2026, la BNI a cédé sa part à
  Brandon & McCain Capital (Ahmed Cissé), qui détient désormais 40,2 % du
  capital et en est le premier actionnaire, devant l'IPS-CNPS (21,54 %). La
  banque n'est donc plus adossée à un groupe bancaire international mais à
  un actionnariat ivoirien public/privé.
- **Produits** : banque de détail, banque d'entreprise, financements
  structurés, moyens de paiement.
- **Saisonnière** : Non.
- **Prix international** : Non.
- **Cyclique** : Oui, modérément — forte sensibilité au commerce extérieur
  transitant par le port d'Abidjan, qu'elle finance largement.
- **Facteurs de hausse** : croissance ivoirienne (parmi les plus fortes de
  l'UEMOA), financement du commerce extérieur et des grandes entreprises,
  bénéfice net en forte hausse récemment (+57 % en 2024, +39 % en 2025).
- **Facteurs de baisse** : ralentissement du commerce mondial affectant les
  flux portuaires, risque politique local (élections, tensions sociales),
  incertitude liée au nouvel actionnariat privé ivoirien (gouvernance,
  stratégie à moyen terme d'un actionnaire entré récemment).
- **Perspective** : la « ivoirisation » de son actionnariat (sortie de BNP
  Paribas, montée d'Ahmed Cissé/Brandon & McCain Capital à 40,2 %) est le
  fait marquant à surveiller — elle peut ouvrir la voie à une stratégie de
  croissance plus agressive sur le marché local, mais introduit aussi une
  incertitude de gouvernance nouvelle par rapport à l'ancien actionnariat
  BNP Paribas.

### BOAB / BOABF / BOAC / BOAM / BOAN / BOAS — Bank of Africa (Bénin, Burkina Faso, Côte d'Ivoire, Mali, Niger, Sénégal)
- **Domaine** : filiales nationales du groupe panafricain Bank of Africa,
  présent dans une quinzaine de pays.
- **Produits** : banque de détail, banque d'entreprise, microfinance
  associée, financement du commerce régional et international.
- **Saisonnière** : Non (léger pic autour du financement des campagnes
  agricoles locales — coton, cacao, café selon le pays).
- **Prix international** : Non — taux et tarifs fixés localement.
- **Cyclique** : Oui, modérément — exposées au cycle économique de chacun
  des six pays, avec des profils de risque très contrastés (Côte
  d'Ivoire/Sénégal/Bénin plus stables ; Mali/Niger/Burkina Faso confrontés à
  une instabilité sécuritaire et politique aiguë ces dernières années).
- **Facteurs de hausse** : expansion du réseau d'agences et de la clientèle
  dans une région encore sous-bancarisée, digitalisation (banque mobile),
  financement des filières agricoles d'exportation (coton au Mali/Burkina
  Faso, cacao en Côte d'Ivoire), synergies de refinancement au sein du
  groupe.
- **Facteurs de baisse** : instabilité politique et sécuritaire au Sahel
  (coups d'État au Mali, au Burkina Faso et au Niger, sanctions régionales
  passées, insécurité liée aux groupes armés) qui pèse sur l'activité
  économique et le coût du risque dans ces trois filiales, restrictions
  éventuelles sur les mouvements de capitaux entre filiales, concurrence
  bancaire accrue.
- **Perspective** : trajectoire contrastée selon les filiales — croissance
  attendue en Côte d'Ivoire, au Sénégal et au Bénin portée par la reprise
  économique régionale, tandis que les filiales du Mali, du Burkina Faso et
  du Niger restent sous la pression du contexte sécuritaire et de leur
  sortie de la CEDEAO au sein de l'Alliance des États du Sahel (effective
  depuis 2025), qui complique les transferts et l'intégration régionale ;
  le groupe continue par ailleurs d'investir dans le mobile banking pour
  capter la clientèle non bancarisée.

### CBIBF — Coris Bank International Burkina Faso
- **Domaine** : groupe bancaire burkinabè fondé en janvier 2008 par
  **Idrissa Nassa**, entrepreneur et banquier burkinabè qui en préside le
  conseil d'administration ; capital majoritairement burkinabè, en
  expansion régionale ouest-africaine (Côte d'Ivoire, Mali, Togo, Sénégal,
  Bénin, Niger).
- **Produits** : banque de détail et d'entreprise, financement de PME,
  transferts d'argent.
- **Saisonnière** : Non.
- **Prix international** : Non.
- **Cyclique** : Oui, modérément — très exposée au cycle économique et au
  contexte sécuritaire burkinabè.
- **Facteurs de hausse** : expansion rapide du réseau régional (présence
  dans plusieurs pays UEMOA/CEMAC), forte croissance historique du groupe,
  financement de la filière aurifère (premier produit d'exportation du
  Burkina Faso) et de l'agriculture.
- **Facteurs de baisse** : instabilité sécuritaire et politique au Burkina
  Faso (zones sous contrôle de groupes armés, contexte post-coup d'État),
  ralentissement économique lié au contexte sécuritaire, hausse du coût du
  risque.
- **Perspective** : poursuite probable de l'expansion régionale du groupe
  vers de nouveaux pays, mais la situation sécuritaire au Burkina Faso
  restera le principal déterminant de la trajectoire à court/moyen terme ;
  la tenue du cours de l'or pourrait continuer de soutenir l'économie
  locale malgré les tensions.

### ECOC — Ecobank Côte d'Ivoire
- **Domaine** : filiale ivoirienne du groupe panafricain Ecobank.
- **Produits** : banque de détail, banque d'entreprise, banque
  d'investissement, mobile banking.
- **Saisonnière** : Non.
- **Prix international** : Non.
- **Cyclique** : Oui, modérément — suit le cycle économique ivoirien et le
  financement du commerce extérieur.
- **Facteurs de hausse** : croissance ivoirienne, expansion du mobile
  banking (Ecobank Mobile/Xpress), synergies avec le réseau panafricain
  d'Ecobank pour le trade finance régional.
- **Facteurs de baisse** : ralentissement économique, concurrence bancaire
  très dense à Abidjan (place la plus bancarisée de l'UEMOA), risque de
  crédit sur les grandes entreprises exportatrices.
- **Perspective** : profitera de la poursuite de la croissance ivoirienne et
  de la digitalisation des paiements (Ecobank Xpress) ; environnement
  concurrentiel de plus en plus dense à surveiller.

### ETIT — Ecobank Transnational Incorporated (Togo)
- **Domaine** : société holding basée à Lomé qui chapeaute le groupe bancaire
  panafricain Ecobank, présent dans une trentaine de pays africains.
  Principaux actionnaires : Qatar National Bank (~20,95 %, entrée en 2014),
  Arise BV (~14,7 %), Public Investment Corporation d'Afrique du Sud
  (~14,05 %).
- **Produits** : consolidation des résultats du groupe ; dividendes issus des
  filiales bancaires nationales.
- **Saisonnière** : Non.
- **Prix international** : Non (le cours de l'action peut être sensible aux
  devises des différents pays où le groupe opère, mais ce n'est pas un "prix
  de produit" fixé par un marché mondial de matières premières).
- **Cyclique** : Oui — exposée simultanément aux cycles économiques et
  politiques d'une trentaine de pays (effet de diversification, mais aussi
  cumul de risques spécifiques à de grands marchés comme le Nigeria ou le
  Ghana).
- **Facteurs de hausse** : diversification géographique unique en Afrique
  (réduit la dépendance à un seul pays), croissance du mobile money
  (Ecobank Xpress/Rapidtransfer), digitalisation, hausse des taux d'intérêt
  mondiaux favorable à la marge d'intermédiation.
- **Facteurs de baisse** : dévaluations locales dans certains pays du
  portefeuille (naira nigérian, cedi ghanéen) qui pèsent sur les résultats
  consolidés, risques politiques disséminés sur tout le continent, coût du
  risque élevé sur certains marchés (Nigeria, RD Congo).
- **Perspective** : le titre reste un pari sur la trajectoire économique de
  l'Afrique dans son ensemble ; la poursuite de la stabilisation
  macroéconomique au Nigeria et au Ghana (deux marchés clés du groupe) et
  la croissance du mobile money seront déterminantes, avec de possibles
  cessions d'actifs non stratégiques pour renforcer les fonds propres.

### NSBC — NSIA Banque Côte d'Ivoire
- **Domaine** : banque de détail et d'entreprise. Actionnariat détaillé :
  NSIA Vie Assurances Côte d'Ivoire (31,55 %), NSIA Participations
  (28,49 %), Caisse Nationale de Prévoyance Sociale/CNPS (17,65 %), public
  via la BRVM (16,87 %), IPS-CGRAE (5 %) — d'où de fortes synergies
  bancassurance avec les filiales assurance du groupe NSIA, elles-mêmes
  actionnaires directes de la banque.
- **Produits** : comptes, crédits, bancassurance en lien avec les filiales
  assurance du groupe.
- **Saisonnière** : Non.
- **Prix international** : Non.
- **Cyclique** : Oui, modérément.
- **Facteurs de hausse** : synergies de bancassurance avec le pôle
  assurance NSIA (vente croisée), croissance ivoirienne, digitalisation.
- **Facteurs de baisse** : ralentissement économique, concurrence des
  grandes banques internationales installées à Abidjan.
- **Perspective** : croissance attendue portée par les synergies
  bancassurance et l'expansion du groupe NSIA en Afrique de l'Ouest et
  centrale ; potentiel de consolidation du secteur bancaire ivoirien.

### ORGT — Oragroup Togo
- **Domaine** : groupe bancaire panafricain (ex-Orabank), présent dans une
  douzaine de pays d'Afrique de l'Ouest et centrale, siège à Lomé.
  Actionnariat institutionnel : Emerging Capital Partners (ECP, entré en
  2008, en cours de cession partielle de ses parts), Proparco, BIO, DEG,
  la BOAD (qui conserve notamment 40 % du capital d'Orabank Côte d'Ivoire
  et de plusieurs filiales — Burkina Faso, Guinée-Bissau, Mali, Niger,
  Sénégal), le Fonds Gabonais d'Investissements Stratégiques et l'IPS-CGRAE
  ivoirienne. **À noter** : un projet de rachat par le groupe Vista Bank
  s'est heurté à des obstacles et le groupe a dû se recapitaliser — signe
  d'une situation actionnariale encore mouvante à surveiller de près.
- **Produits** : banque de détail, PME, grandes entreprises, trade finance.
- **Saisonnière** : Non.
- **Prix international** : Non.
- **Cyclique** : Oui, modérément — profil plus volatil que les grandes
  banques (taille plus modeste), exposée à 12 pays dont certains fragiles.
- **Facteurs de hausse** : expansion du réseau régional, potentiel de
  consolidation/rapprochement avec d'autres groupes bancaires,
  digitalisation.
- **Facteurs de baisse** : instabilité politique dans certains pays
  d'implantation, coût du risque plus élevé que la moyenne du secteur,
  besoins réguliers de renforcement des fonds propres, incertitude sur
  l'issue du processus de cession/recapitalisation en cours.
- **Perspective** : l'échec du rachat par Vista Bank et le passage par une
  recapitalisation ouvrent une période d'incertitude sur l'actionnaire de
  référence à moyen terme ; des opportunités de croissance existent dans
  des marchés encore peu bancarisés (Guinée, RDC, Tchad) mais le profil de
  risque restera élevé tant que la question actionnariale n'est pas
  stabilisée.

### SGBC — Société Générale Côte d'Ivoire
- **Domaine** : banque commerciale, filiale ivoirienne du groupe français
  Société Générale.
- **Produits** : banque de détail, banque d'entreprise, gestion de fortune.
- **Saisonnière** : Non.
- **Prix international** : Non.
- **Cyclique** : Oui, modérément.
- **Facteurs de hausse** : solidité de l'actionnaire, forte présence dans
  le financement des grandes entreprises et de l'État ivoirien, gestion de
  fortune en croissance.
- **Facteurs de baisse** : stratégie de recentrage géographique du groupe
  Société Générale (risque de cession de participations africaines),
  concurrence accrue, ralentissement économique.
- **Perspective** : dépendra largement des décisions stratégiques du groupe
  Société Générale sur ses activités africaines (le groupe a déjà réduit
  son empreinte dans plusieurs pays) ; à surveiller de près une éventuelle
  cession ou recomposition de l'actionnariat.

### SIBC — Société Ivoirienne de Banque
- **Domaine** : banque commerciale ivoirienne historique, détenue à 75 %
  par **Attijariwafa Bank** (via sa filiale Attijari Ivoire Holding
  Offshore) depuis le rachat de la participation de l'État ivoirien en
  2015 ; 7ᵉ banque de l'UMOA par le total de bilan.
- **Produits** : banque de détail et d'entreprise.
- **Saisonnière** : Non.
- **Prix international** : Non.
- **Cyclique** : Oui, modérément.
- **Facteurs de hausse** : adossement à Attijariwafa Bank (accès à des
  capitaux et à une expertise régionale reconnue), croissance ivoirienne,
  digitalisation.
- **Facteurs de baisse** : concurrence dense sur la place d'Abidjan,
  ralentissement économique, hausse du coût du risque en cas de choc sur
  les filières financées.
- **Perspective** : croissance attendue en ligne avec l'économie ivoirienne,
  appuyée par l'expertise régionale d'Attijariwafa ; accélération de la
  digitalisation des services nécessaire pour rester compétitive face aux
  fintechs.

---

## Distribution (3)

Secteur avec une **saisonnalité modérée** (campagnes agricoles pour le
matériel agricole, chantiers en saison sèche pour le BTP) et des **coûts
d'achat partiellement internationaux** : les véhicules, pièces et matériaux
sont importés et facturés en devises (euro, via la parité fixe du FCFA), donc
sensibles aux cours mondiaux de l'acier/des matières premières industrielles,
même si le prix final de vente reste fixé localement par le distributeur. Le
secteur est **cyclique** : les biens qu'il vend (véhicules, matériaux de
construction) sont des achats importants et différables, très sensibles au
cycle économique et au crédit disponible.

### BNBC — Bernabé Côte d'Ivoire
- **Domaine** : distribution de matériaux de construction et de quincaillerie.
- **Produits** : ciment, fers à béton, peintures, sanitaire, outillage,
  matériel électrique.
- **Saisonnière** : Partiellement — plus forte activité en saison sèche
  (période propice aux chantiers de construction).
- **Détail saisonnier** : haute saison de novembre à avril (saison sèche,
  chantiers actifs) ; basse saison de mai à octobre (grande saison des
  pluies, chantiers ralentis ou arrêtés, notamment en juin-juillet).
- **Prix international** : Partiellement — coûts d'approvisionnement liés
  aux cours de l'acier/du ciment importés ; prix de vente local.
- **Cyclique** : Oui — fortement lié à l'investissement dans le BTP et la
  construction résidentielle, des dépenses différables en période de
  ralentissement.
- **Facteurs de hausse** : boom de la construction et de l'urbanisation en
  Côte d'Ivoire, grands programmes d'infrastructures publiques et de
  logements sociaux, hausse du pouvoir d'achat des ménages.
- **Facteurs de baisse** : ralentissement de l'investissement public/privé
  dans le BTP, hausse des coûts d'importation (matériaux, fret),
  dépréciation de l'euro/FCFA face aux devises d'achat, concurrence
  informelle.
- **Perspective** : soutenue par un déficit de logements encore important en
  Côte d'Ivoire et par les grands programmes gouvernementaux
  d'infrastructures ; sensible aux cycles de dépenses publiques et à
  l'inflation des matériaux importés.

### CFAC — CFAO Motors Côte d'Ivoire
- **Domaine** : distribution automobile (véhicules, pièces détachées,
  après-vente) ; filiale de CFAO, elle-même détenue à 100 % par le groupe
  japonais **Toyota Tsusho Corporation** depuis 2016. Premier
  concessionnaire automobile de Côte d'Ivoire, avec ~42,6 % de part de
  marché et le plus grand réseau du secteur (Abidjan, Yamoussoukro,
  Bouaké, distributeur à San Pedro).
- **Produits** : véhicules et pièces des marques Toyota, Citroën, Peugeot,
  Mitsubishi, Yamaha, Suzuki, JCB et Bridgestone, services d'entretien.
- **Saisonnière** : Partiellement — pics de ventes en fin d'année et lors des
  campagnes de financement liées aux récoltes (achats de véhicules
  utilitaires par les coopératives agricoles).
- **Détail saisonnier** : haute saison octobre-décembre (démarrage de la
  campagne cacao/café, trésorerie des coopératives et primes de fin
  d'année qui dopent les achats de véhicules) ; basse saison en milieu
  d'année (juin-août, période de soudure entre les campagnes).
- **Prix international** : Partiellement — véhicules importés, prix indexé
  sur le coût d'achat en devises et les cours des matières premières
  entrant dans la fabrication automobile.
- **Cyclique** : Oui — l'achat d'un véhicule (bien durable) est très
  sensible au cycle économique et à l'accès au crédit.
- **Facteurs de hausse** : parc automobile ivoirien encore faible par
  habitant (fort potentiel de croissance), renouvellement des flottes
  d'entreprises et administrations, bonnes campagnes agricoles
  d'exportation qui financent les achats de véhicules utilitaires,
  développement du crédit-auto.
- **Facteurs de baisse** : ralentissement économique, hausse des taux
  d'intérêt (crédit auto plus cher), hausse du coût des véhicules importés
  (droits de douane, taux de change), concurrence des véhicules d'occasion
  importés.
- **Perspective** : potentiel de croissance porté par un taux de
  motorisation encore très faible en Côte d'Ivoire et par l'essor du
  financement automobile ; à surveiller à plus long terme, l'émergence
  progressive des véhicules électriques/hybrides sur le marché ouest-
  africain.

### PRSC — TracTafric Motors Côte d'Ivoire
- **Domaine** : distribution automobile et d'engins de BTP, filiale de
  TracTafric Motors Corporation (groupe français OPTORG). **Précision** :
  la société distribue les marques BMW, Hyundai, Ford, Mazda, JAC, Chery,
  MCV et Mercedes-Benz Trucks — pas Renault (Renault est distribué en
  Côte d'Ivoire par SOCIDA, filiale du groupe Bernard-Hayot/GBH, une
  société concurrente non cotée à la BRVM).
- **Produits** : véhicules particuliers/utilitaires, engins de travaux
  publics, poids lourds, pièces détachées, service après-vente.
- **Saisonnière** : Partiellement — ventes de tracteurs/matériel agricole
  liées aux campagnes agricoles ; matériel BTP lié à la saison sèche.
- **Détail saisonnier** : haute saison en amont des récoltes (août-octobre,
  préparation de la campagne agricole) et en saison sèche pour le matériel
  de BTP (novembre-avril) ; basse saison pendant la grande saison des
  pluies (mai-septembre).
- **Prix international** : Partiellement — mêmes logiques d'importation que
  CFAO Motors.
- **Cyclique** : Oui — même logique que CFAO Motors, avec en plus une forte
  dépendance aux investissements agricoles et publics (achats de tracteurs
  et d'engins de chantier).
- **Facteurs de hausse** : mécanisation croissante de l'agriculture
  ouest-africaine, grands chantiers d'infrastructures, hausse des revenus
  agricoles.
- **Facteurs de baisse** : ralentissement de l'investissement agricole ou
  public en BTP, hausse du coût des équipements importés, concurrence du
  matériel d'occasion.
- **Perspective** : la mécanisation agricole étant encore peu répandue en
  Afrique de l'Ouest, le potentiel de croissance reste important, porté par
  les politiques de soutien à l'agriculture ; dépend fortement du calendrier
  des grands travaux publics.

---

## Agriculture (5)

Le secteur le plus nettement **saisonnier** et le plus **cyclique** de la
cote : hévéa (caoutchouc naturel), huile de palme et sucre sont tous des
commodités qui s'échangent sur des marchés internationaux (Singapour/SICOM
pour le caoutchouc, Bursa Malaysia pour l'huile de palme), aux cours très
volatils d'une année sur l'autre, même si une partie de la production reste
vendue sur le marché local ou régional UEMOA (le sucre, en particulier,
bénéficie d'une protection tarifaire régionale qui déconnecte partiellement
son prix domestique du cours mondial).

### PALC — Palm Côte d'Ivoire
- **Domaine** : plantation et transformation de palmier à huile (groupe
  SIFCA).
- **Produits** : huile de palme brute et raffinée, palmiste.
- **Saisonnière** : Oui — production cyclique avec pics et creux de récolte
  selon la pluviométrie, bien que la récolte du palmier soit étalée sur
  l'année (moins marquée que l'hévéa).
- **Détail saisonnier** : haute production (« grande traite ») d'avril à
  août-septembre, la pluviométrie abondante favorisant le remplissage des
  régimes ; basse production (« petite traite ») de décembre à mars,
  pendant la saison sèche.
- **Prix international** : Oui — l'huile de palme est une commodité cotée
  sur les marchés mondiaux (référence Bursa Malaysia).
- **Cyclique** : Oui, fortement — le cours mondial de l'huile de palme est
  très volatil (spéculation, cours du pétrole qui influence la demande en
  biodiesel, politiques commerciales indonésienne/malaisienne).
- **Facteurs de hausse** : hausse du cours mondial de l'huile de palme,
  hausse de la demande alimentaire régionale et de biodiesel, amélioration
  des rendements (nouvelles plantations arrivant à maturité), FCFA/euro
  moins fort rendant les exportations plus compétitives.
- **Facteurs de baisse** : chute du cours mondial de l'huile de palme
  (surproduction en Asie du Sud-Est), aléas climatiques (sécheresse
  réduisant les rendements), maladies des palmiers, hausse des coûts de
  production (engrais, énergie).
- **Perspective** : les cours de l'huile de palme resteront probablement
  volatils (arbitrage biodiesel/alimentaire, politiques indonésiennes et
  malaisiennes), mais la demande alimentaire régionale, structurellement
  croissante, soutient la demande à moyen terme ; le changement climatique
  (irrégularité croissante des pluies) est un risque à surveiller sur les
  rendements futurs.

### SCRC — Sucrivoire Côte d'Ivoire
- **Domaine** : culture de canne à sucre et production de sucre.
- **Produits** : sucre roux et blanc, mélasse.
- **Saisonnière** : Oui — campagne sucrière concentrée sur une partie de
  l'année (récolte/broyage de la canne).
- **Détail saisonnier** : haute saison (campagne de coupe et de broyage) de
  novembre à avril/mai, quand la teneur en sucre de la canne est optimale
  en saison sèche ; basse saison de mai/juin à octobre, période
  d'inter-campagne où l'usine tourne au ralenti pour l'entretien pendant
  que la canne repousse sous les pluies.
- **Prix international** : Partiellement — le sucre a un cours mondial, mais
  le marché ivoirien/UEMOA est protégé par des quotas et droits de douane
  qui déconnectent en partie le prix local du cours international.
- **Cyclique** : Oui, fortement — combine le cycle des cours mondiaux du
  sucre et l'incertitude sur la politique commerciale régionale (durée de
  la protection tarifaire UEMOA).
- **Facteurs de hausse** : hausse du cours mondial du sucre, bonnes
  conditions climatiques pour la campagne, maintien de la protection
  tarifaire régionale contre le sucre importé, hausse de la consommation
  régionale.
- **Facteurs de baisse** : chute des cours mondiaux, remise en cause de la
  protection tarifaire (accords de libre-échange), mauvaise pluviométrie
  affectant les rendements de la canne, vieillissement de l'outil
  industriel.
- **Perspective** : l'avenir dépendra beaucoup du maintien ou non de la
  protection tarifaire régionale sur le sucre face aux pressions de
  libéralisation commerciale ; la modernisation de l'outil industriel sera
  nécessaire pour rester compétitive face aux importations à bas coût.

### SICC — Sicor Côte d'Ivoire
- **Domaine** : production sucrière (site de Ferkessédougou, nord de la
  Côte d'Ivoire).
- **Produits** : sucre roux et blanc.
- **Saisonnière** : Oui — même logique de campagne sucrière que Sucrivoire.
- **Détail saisonnier** : haute saison novembre-avril/mai (coupe et
  broyage) ; basse saison mai/juin-octobre (inter-campagne).
- **Prix international** : Partiellement — même remarque que Sucrivoire.
- **Cyclique** : Oui, fortement — mêmes facteurs que Sucrivoire (cours
  mondial du sucre, protection tarifaire régionale, pluviométrie).
- **Facteurs de hausse** : mêmes que Sucrivoire (cours mondial favorable,
  bonne campagne, protection tarifaire maintenue).
- **Facteurs de baisse** : mêmes que Sucrivoire (chute des cours,
  libéralisation des importations, aléas climatiques).
- **Perspective** : mêmes enjeux que Sucrivoire (maintien de la protection
  tarifaire, modernisation industrielle), avec un potentiel de
  rapprochement/synergies accrues entre les deux sociétés sucrières
  ivoiriennes, appartenant au même groupe.

### SOGC — SOGB Côte d'Ivoire (Société des Caoutchoucs de Grand-Béréby)
- **Domaine** : plantation d'hévéa et de palmier à huile.
- **Produits** : caoutchouc naturel (fonds de tasse, latex), huile de palme.
- **Saisonnière** : Oui — la production de latex varie fortement avec la
  saison des pluies/saison sèche (arrêt de saignée en saison sèche).
- **Détail saisonnier** : haute production de mai à novembre, pendant la
  saison des pluies quand l'arbre est en feuillaison complète ; basse
  production (voire arrêt de la saignée) de décembre à février, période de
  « défoliation »/hivernage de l'hévéa où la production de latex chute
  fortement. La production d'huile de palme associée suit le même cycle
  que Palm Côte d'Ivoire (haute avril-août, basse décembre-mars).
- **Prix international** : Oui — le caoutchouc naturel est une matière
  première cotée sur les marchés internationaux (référence SICOM Singapour).
- **Client historique clé** : le manufacturier pneumatique **Michelin**,
  actionnaire direct de SAPH (~14,8 %) et de l'entité liée SIPH (~33,7 %),
  est le principal client de SAPH/SOGB parmi les industriels du pneu — un
  partenariat privilégié de long terme entre Michelin et le groupe SIFCA.
- **Cyclique** : Oui, fortement — cours mondiaux du caoutchouc et de
  l'huile de palme, tous deux volatils et liés indirectement au cours du
  pétrole (le caoutchouc synthétique, substitut du naturel, est un dérivé
  pétrolier).
- **Facteurs de hausse** : hausse des cours mondiaux du caoutchouc naturel
  (demande pneumatique mondiale, notamment Chine/Inde), hausse du cours de
  l'huile de palme, extension des surfaces plantées.
- **Facteurs de baisse** : chute des cours du caoutchouc (surproduction en
  Asie du Sud-Est, ralentissement de l'industrie automobile mondiale),
  maladies des hévéas, hausse des coûts de main-d'œuvre (activité très
  intensive en travail pour la saignée).
- **Perspective** : la demande mondiale de caoutchouc naturel devrait rester
  soutenue par l'industrie pneumatique (y compris les véhicules
  électriques, également gros consommateurs de pneus), mais la concurrence
  des grands pays producteurs asiatiques et les nouvelles exigences de
  traçabilité anti-déforestation (règlement européen RDUE) pèseront sur la
  compétitivité à moyen terme ; la diversification vers l'huile de palme
  est un facteur de résilience.

### SPHC — SAPH Côte d'Ivoire (Société Africaine de Plantations d'Hévéas)
- **Domaine** : premier producteur ivoirien d'hévéa (plantations
  industrielles et achats aux planteurs villageois).
- **Produits** : caoutchouc naturel destiné à l'industrie du pneumatique.
- **Saisonnière** : Oui — même logique que SOGB (saignée réduite en saison
  sèche).
- **Détail saisonnier** : haute production mai-novembre (saison des
  pluies) ; basse production décembre-février (hivernage/défoliation des
  hévéas, saignée fortement réduite ou suspendue sur les parcelles
  concernées).
- **Prix international** : Oui — prix aligné sur les cours mondiaux du
  caoutchouc naturel.
- **Cyclique** : Oui, fortement — mêmes facteurs que SOGB, avec un poids
  dominant du caoutchouc dans le chiffre d'affaires.
- **Facteurs de hausse** : hausse du cours mondial du caoutchouc, hausse de
  la demande de pneumatiques (industrie automobile mondiale, notamment
  Chine), extension du verger hévéicole.
- **Facteurs de baisse** : chute du cours mondial du caoutchouc, concurrence
  des grands pays producteurs asiatiques (Thaïlande, Indonésie, Vietnam) à
  coûts plus faibles, aléas climatiques, pénurie de main-d'œuvre pour la
  saignée.
- **Perspective** : même dynamique de fond que SOGB ; à surveiller, le
  développement du caoutchouc synthétique/recyclé qui pourrait limiter la
  hausse des prix à long terme, ainsi que les nouvelles normes européennes
  de traçabilité anti-déforestation qui exigeront des efforts de
  certification supplémentaires pour les exportations vers l'UE.

---

## Industrie (11)

Secteur hétérogène, à la cyclicité très variable selon le produit : les biens
de consommation courante (agroalimentaire, hygiène, tabac) sont plutôt
**défensifs** (demande stable quel que soit le cycle économique), tandis que
le BTP, les câbles, le bitume ou le textile sont **cycliques**, liés à
l'investissement et au pouvoir d'achat discrétionnaire. La saisonnalité suit
la même logique (boissons/confiseries : forte chaleur et fêtes de fin
d'année ; BTP/bitume : saison sèche ; textile : fêtes).

### CABC — Sicable Côte d'Ivoire
- **Domaine** : fabrication de câbles électriques. **Précision** : Sicable
  est une filiale du **groupe Prysmian** (et non Nexans), qui en fait sa
  plateforme commerciale pour l'Afrique et distribue les marques Prysmian
  et Draka.
- **Produits** : câbles basse et moyenne tension pour le bâtiment, l'énergie
  et les télécommunications.
- **Saisonnière** : Partiellement — demande liée aux chantiers de BTP et aux
  programmes d'électrification.
- **Détail saisonnier** : haute saison novembre-avril (saison sèche,
  chantiers de bâtiment et d'électrification actifs) ; basse saison
  mai-octobre (saison des pluies, chantiers ralentis).
- **Prix international** : Partiellement — le cuivre, matière première
  principale, est coté sur le London Metal Exchange (LME) ; le prix final
  au client reste négocié localement.
- **Cyclique** : Oui — liée à l'investissement en BTP/énergie et au cours
  du cuivre.
- **Facteurs de hausse** : grands programmes d'électrification rurale et
  d'infrastructures (énergie, télécoms, BTP) en Côte d'Ivoire et sous-région,
  hausse du cours du cuivre (revalorise le chiffre d'affaires à volume
  constant), croissance urbaine.
- **Facteurs de baisse** : ralentissement de l'investissement public/privé,
  baisse du cours du cuivre, concurrence de câbles importés à bas coût
  (notamment chinois).
- **Perspective** : les besoins d'électrification et de modernisation des
  réseaux électriques ouest-africains restent considérables (taux d'accès à
  l'électricité encore faible dans plusieurs pays), ce qui soutient une
  demande de long terme ; une éventuelle baisse des cours du cuivre
  allégerait les coûts de production.

### FTSC — Filtisac Côte d'Ivoire
- **Domaine** : fabrication de sacs en polypropylène tissé et de sacherie.
- **Produits** : sacs pour cacao, café, riz, ciment et autres produits
  agricoles/industriels.
- **Saisonnière** : Partiellement — demande liée aux campagnes cacao/café
  qui consomment le plus de sacs.
- **Détail saisonnier** : haute demande octobre-décembre (démarrage de la
  campagne principale cacao/café, forte consommation de sacs
  d'ensachage) ; basse demande avril-septembre (campagne intermédiaire,
  volumes de récolte plus faibles).
- **Prix international** : Partiellement — le polypropylène est un dérivé
  pétrochimique dont le coût suit les cours mondiaux du pétrole.
- **Cyclique** : Oui, modérément — liée aux volumes des filières agricoles
  d'exportation (cacao, café, coton) qu'elle emballe, et au cours du
  pétrole (matière première polypropylène).
- **Facteurs de hausse** : bonnes campagnes agricoles (volumes de
  cacao/café/coton à ensacher), hausse de la demande de sacs de ciment liée
  au BTP.
- **Facteurs de baisse** : mauvaises campagnes agricoles, concurrence de
  sacs importés moins chers, concurrence de l'ensachage en vrac/big-bags.
- **Perspective** : dépend de la pérennité de la demande d'ensachage
  traditionnel face à la progression du vrac/big-bag dans le commerce
  international des matières premières ; les niveaux de prix élevés du
  cacao ces dernières années soutiennent indirectement les revenus des
  producteurs, donc les volumes à ensacher.

### NTLC — Nestlé Côte d'Ivoire
- **Domaine** : fabrication et distribution de produits agroalimentaires
  (filiale du groupe suisse Nestlé).
- **Produits** : café soluble (Nescafé), cubes et condiments (Maggi), lait,
  nutrition infantile.
- **Saisonnière** : Partiellement — légers pics saisonniers (période
  scolaire pour la nutrition infantile, fêtes de fin d'année).
- **Détail saisonnier** : haute demande en septembre (rentrée scolaire,
  produits Nescafé/petit-déjeuner) et décembre (fêtes) ; reste de l'année
  relativement stable, la nutrition infantile suivant une demande continue
  peu marquée par les saisons.
- **Prix international** : Partiellement — intrants (café, poudre de lait,
  cacao) achetés sur des marchés mondiaux ; prix de vente au consommateur
  fixé localement.
- **Cyclique** : Non, peu cyclique — biens de consommation courante
  (agroalimentaire) à demande relativement stable même en période de
  ralentissement économique (activité défensive).
- **Facteurs de hausse** : croissance démographique et urbanisation
  ivoirienne (marché de consommation en expansion), montée en gamme de la
  consommation, innovation produits (nutrition infantile, cafés).
- **Facteurs de baisse** : inflation alimentaire réduisant le pouvoir
  d'achat (arbitrage vers des marques locales moins chères), hausse du coût
  des matières premières importées (cacao, lait), concurrence de marques
  locales/génériques.
- **Perspective** : la croissance démographique et l'urbanisation continue
  de l'Afrique de l'Ouest (parmi les plus fortes du monde) soutiennent une
  trajectoire de croissance de long terme pour les biens de consommation
  courante ; le principal risque reste la pression inflationniste sur le
  pouvoir d'achat des ménages, favorable aux marques locales moins chères.

### SEMC — Eviosys Packaging Siem Côte d'Ivoire
- **Domaine** : fabrication d'emballages métalliques (boîtes de conserve),
  anciennement Crown Siem.
- **Produits** : boîtes métalliques pour l'industrie agroalimentaire
  (conserves de thon, tomate, lait, boissons).
- **Saisonnière** : Partiellement — liée aux campagnes de pêche/conserverie
  de ses clients industriels.
- **Détail saisonnier** : haute production mai-septembre (pic de la saison
  de pêche thonière dans le golfe de Guinée, forte demande de boîtes des
  conserveries) ; basse production en début d'année (janvier-mars,
  activité de pêche plus faible).
- **Prix international** : Partiellement — le fer-blanc/acier utilisé est
  une matière première dont le coût suit les cours mondiaux.
- **Cyclique** : Non, peu cyclique — dépend de la demande alimentaire en
  conserve (défensive), bien que liée aux volumes de pêche de ses clients.
- **Facteurs de hausse** : croissance de l'industrie thonière ivoirienne
  (premier port thonier d'Afrique), demande croissante de conserves
  alimentaires en Afrique de l'Ouest.
- **Facteurs de baisse** : hausse du cours de l'acier/fer-blanc (matière
  première), quotas de pêche internationaux limitant les volumes de thon
  débarqués, concurrence d'emballages alternatifs (plastique, verre).
- **Perspective** : la croissance de l'industrie thonière ivoirienne (le
  port d'Abidjan renforce sa position de hub thonier régional) est un
  facteur structurellement favorable ; à surveiller, l'évolution des
  accords de pêche UE-Côte d'Ivoire et la concurrence des emballages
  alternatifs.

### SIVC — Erium Côte d'Ivoire
- **Domaine** : fabrication d'huiles alimentaires et de savons à partir
  d'oléagineux (huile de palme, coprah).
- **Produits** : huiles de cuisine, savons, margarine.
- **Saisonnière** : Partiellement — disponibilité de la matière première
  (huile de palme) suit le cycle des récoltes.
- **Détail saisonnier** : approvisionnement abondant et coûts plus bas
  d'avril à août (grande traite du palmier) ; approvisionnement plus
  tendu et coûteux de décembre à mars (petite traite, saison sèche).
- **Prix international** : Partiellement — huile de palme brute achetée à
  un prix influencé par les cours mondiaux ; prix de vente final local
  (souvent encadré par des mesures de régulation des prix des produits de
  première nécessité).
- **Cyclique** : Non, peu cyclique — huile de cuisine et savon sont des
  biens de première nécessité, à demande relativement inélastique.
- **Facteurs de hausse** : croissance démographique, hausse du cours
  mondial de l'huile de palme si l'entreprise dispose de sa propre matière
  première, politiques de substitution aux importations d'huile.
- **Facteurs de baisse** : hausse du cours mondial de l'huile de palme si
  elle doit l'acheter à l'extérieur (compression des marges quand le prix
  de vente est régulé), concurrence des huiles importées à bas coût.
- **Perspective** : la demande d'huiles alimentaires locales devrait
  continuer de croître avec la population, mais l'entreprise devra composer
  avec la volatilité du cours de l'huile de palme et une régulation
  possiblement accrue des prix des produits de première nécessité.

### SLBC — Solibra Côte d'Ivoire
- **Domaine** : brasserie et embouteillage de boissons. Actionnaire de
  référence : BGI (Brasseries et Glacières Internationales), filiale du
  groupe Castel (Pierre Castel), qui détient environ 77 % du capital et
  possède une quarantaine de brasseries en Afrique. **À noter** : Solibra,
  longtemps en situation de quasi-monopole, est depuis 2017 concurrencée
  par un nouvel entrant, Brassivoire (filiale du groupe Heineken/CFAO), ce
  qui a rebattu les cartes du marché brassicole ivoirien.
- **Produits** : bières (Bock, Flag), boissons gazeuses, eaux.
- **Saisonnière** : Oui — ventes fortement liées à la saison chaude et aux
  périodes de fêtes (Noël, Nouvel An, fêtes locales).
- **Détail saisonnier** : haute saison de décembre à avril (saison sèche
  et chaude, pic autour des fêtes de fin d'année et du Nouvel An) ; basse
  saison pendant la grande saison des pluies (juin-septembre), période
  plus fraîche avec une consommation de boissons réduite.
- **Prix international** : Partiellement — malt et houblon importés
  (cours mondiaux), prix de vente final fixé localement.
- **Cyclique** : Oui, modérément — biens de consommation discrétionnaire
  sensibles au pouvoir d'achat, mais avec une demande assez résiliente.
- **Facteurs de hausse** : croissance démographique et urbanisation
  (population jeune et en forte croissance), hausse du pouvoir d'achat,
  grands événements (fêtes, compétitions sportives), innovation produits.
- **Facteurs de baisse** : hausse de la fiscalité sur l'alcool/les boissons
  sucrées, inflation réduisant le pouvoir d'achat, hausse du coût des
  intrants importés (malt, houblon, emballages), concurrence de la
  production informelle et, depuis 2017, concurrence directe de Brassivoire
  sur le marché de la bière.
- **Perspective** : la population jeune et en forte croissance de la Côte
  d'Ivoire est un moteur structurel de la consommation de boissons ; la
  fiscalité sur l'alcool et les boissons sucrées, dans une logique de santé
  publique et en hausse dans plusieurs pays de la zone, est le principal
  risque réglementaire à moyen terme.

### SMBC — SMB Côte d'Ivoire (Société Multinationale de Bitumes)
- **Domaine** : production et distribution de bitume pour la construction
  routière.
- **Produits** : bitume routier, produits dérivés du pétrole pour le BTP.
- **Saisonnière** : Partiellement — activité de pose plus intense en saison
  sèche.
- **Détail saisonnier** : haute activité novembre-avril (saison sèche,
  chantiers routiers en cours) ; basse activité mai-octobre (saison des
  pluies, pose d'enrobé difficile voire arrêtée).
- **Prix international** : Oui — le bitume est un dérivé direct du pétrole
  brut, dont le prix suit de près les cours internationaux du pétrole.
- **Cyclique** : Oui, fortement — directement lié aux dépenses publiques
  d'infrastructures routières (budget de l'État) et au cours du pétrole.
- **Facteurs de hausse** : grands programmes routiers nationaux et
  régionaux (financements de bailleurs comme la BAD ou la Banque mondiale),
  hausse du cours du pétrole si elle peut être répercutée sur les prix de
  vente.
- **Facteurs de baisse** : gel ou report de projets routiers publics
  (contraintes budgétaires de l'État), hausse du cours du pétrole si elle
  ne peut être répercutée (compression des marges), concurrence
  d'importateurs directs de bitume.
- **Perspective** : les besoins en infrastructures routières restent
  considérables dans la sous-région, ce qui soutient une demande de long
  terme, sous réserve de la capacité de financement des États — la dette
  publique en hausse dans plusieurs pays de l'UEMOA pourrait limiter le
  rythme des nouveaux projets routiers.

### STAC — Setao Côte d'Ivoire
- **Domaine** : bâtiment et travaux publics (BTP).
- **Produits** : construction de routes, ouvrages d'art, bâtiments.
- **Saisonnière** : Partiellement — activité de chantier ralentie en saison
  des pluies.
- **Détail saisonnier** : haute activité novembre-avril (saison sèche,
  gros œuvre et terrassement facilités) ; basse activité mai-octobre,
  notamment juin-juillet (pic des pluies rendant certains chantiers
  inaccessibles ou dangereux).
- **Prix international** : Non — prix fixés par appels d'offres/contrats
  locaux, même si certains intrants (bitume, acier) sont importés.
- **Cyclique** : Oui, fortement — dépend directement des mises en chantier
  publiques et privées.
- **Facteurs de hausse** : grands travaux d'infrastructures (routes, ponts,
  bâtiments publics) financés par l'État ou des bailleurs internationaux,
  urbanisation rapide.
- **Facteurs de baisse** : contraintes budgétaires publiques limitant les
  nouveaux appels d'offres, retards de paiement de l'État (problème
  récurrent du secteur BTP en Afrique), hausse du coût des matériaux
  importés, concurrence internationale très présente sur les marchés
  publics.
- **Perspective** : dépend des capacités budgétaires des États de la
  sous-région (endettement en hausse) et de la poursuite des grands
  programmes d'infrastructures ; la concurrence internationale restera un
  facteur de pression sur les marges.

### STBC — Sitab Côte d'Ivoire (Société Ivoirienne de Tabac)
- **Domaine** : fabrication de cigarettes, détenue à 73 % par le groupe
  britannique **Imperial Brands** ; seul fabricant de cigarettes en Côte
  d'Ivoire, avec une part de marché d'environ 88 % (concurrencée
  localement par British American Tobacco Côte d'Ivoire, qui distribue
  sans y fabriquer).
- **Produits** : cigarettes (marques Fine, Gauloises Blondes, Mustang,
  entre autres) commercialisées en Côte d'Ivoire et sous-région.
- **Saisonnière** : Non — consommation relativement stable toute l'année.
- **Prix international** : Partiellement — feuilles de tabac achetées sur
  un marché mondial ; prix de vente final très encadré par la fiscalité
  locale (accises).
- **Cyclique** : Non — le tabac est un produit addictif à demande
  inélastique, peu sensible au cycle économique (activité défensive
  classique).
- **Facteurs de hausse** : croissance démographique, gains de parts de
  marché régionales, répercussion des hausses d'accises dans les prix de
  vente.
- **Facteurs de baisse** : hausse continue de la fiscalité sur le tabac
  (politiques de santé publique, harmonisation UEMOA des accises),
  campagnes anti-tabac, contrebande/marché informel qui érode les volumes
  officiels.
- **Perspective** : la consommation de tabac devrait continuer de reculer en
  volume sous l'effet des politiques de santé publique et de la hausse
  continue de la fiscalité, une tendance de long terme partiellement
  compensée par la hausse des prix — illustration récente : chiffre
  d'affaires 2025 en hausse de 25 % (268 milliards FCFA) mais résultat net
  en baisse de 18 % (36,2 milliards FCFA), du fait de la hausse des droits
  d'accises.

### UNLC — Unilever Côte d'Ivoire
- **Domaine** : fabrication de produits de grande consommation (filiale du
  groupe Unilever). **Point de vigilance important** : le titre a été
  **suspendu de cotation à la BRVM**, son flottant réel n'étant que de
  9,47 % du capital, très en-deçà du seuil minimal de 20 % désormais exigé
  par la BRVM pour rester coté — une régularisation (élargissement du
  flottant) est nécessaire pour un retour à la négociation.
- **Produits** : trois familles — alimentaire (thés, mayonnaises), entretien
  du foyer (savons, lessives), et soins de la personne (dentifrices,
  brosses à dents, savons).
- **Saisonnière** : Partiellement — légers pics en période de fêtes.
- **Détail saisonnier** : haute demande en décembre (grand nettoyage et
  achats de fêtes) ; reste de l'année relativement stable, les produits
  d'hygiène étant des biens de consommation courante.
- **Prix international** : Partiellement — huiles végétales et autres
  intrants chimiques importés, prix de vente final local.
- **Cyclique** : Non, peu cyclique — produits d'hygiène de grande
  consommation à demande relativement stable (activité défensive).
- **Facteurs de hausse** : croissance démographique et urbanisation, montée
  en gamme de la consommation.
- **Facteurs de baisse** : hausse du coût des matières premières importées,
  concurrence des marques locales/génériques, faible liquidité du titre en
  bourse limitant l'intérêt des investisseurs (facteur boursier plus
  qu'opérationnel).
- **Perspective** : la priorité immédiate est la sortie de la suspension de
  cotation via une remise en conformité du flottant (20 % minimum exigé par
  la BRVM) ; au-delà, croissance structurelle liée à la démographie, mais
  l'évolution de la stratégie du groupe Unilever en Afrique (recentrage vers
  certains marchés) reste à surveiller.

### UNXC — Uniwax Côte d'Ivoire
- **Domaine** : fabrication de tissus imprimés (pagnes wax). **Changement
  d'actionnariat majeur (février 2026)** : le groupe néerlandais Vlisco,
  actionnaire historique depuis la création d'Uniwax en 1967 (à l'origine
  avec Unilever), a cédé la totalité de sa participation de 72,29 % du
  capital à la **Compagnie Ivoirienne de Coton (COIC)**, une société
  ivoirienne du secteur cotonnier — Uniwax passe ainsi sous pavillon
  ivoirien.
- **Produits** : pagnes et tissus imprimés pour l'habillement.
- **Saisonnière** : Oui — pics de vente marqués en période de fêtes
  (Noël, fêtes religieuses, cérémonies).
- **Détail saisonnier** : haute saison novembre-décembre (Noël/Nouvel An)
  et autour des grandes fêtes religieuses/cérémonies traditionnelles
  (Tabaski, Pâques, mariages en saison sèche) ; basse saison en début
  d'année (janvier-mars, creux après les fêtes) et pendant la grande
  saison des pluies.
- **Prix international** : Partiellement — le coton/fil de coton est une
  matière première dont le cours mondial influence les coûts de
  production.
- **Cyclique** : Oui — le textile/habillement est une dépense
  discrétionnaire arbitrable, sensible au pouvoir d'achat et à la mode.
- **Facteurs de hausse** : événements et cérémonies (mariages, fêtes
  religieuses, rentrées), regain d'intérêt pour les tissus locaux
  authentiques.
- **Facteurs de baisse** : concurrence très forte des imitations de wax
  importées à bas coût, contrebande, hausse du coût du coton importé, recul
  du pouvoir d'achat des ménages.
- **Perspective** : le changement d'actionnaire de référence (Vlisco → COIC,
  un acteur ivoirien de la filière coton) début 2026 est l'élément le plus
  structurant à surveiller : il peut ouvrir la voie à une meilleure
  intégration avec la filière cotonnière locale (approvisionnement en
  matière première), mais aussi faire perdre l'accès aux réseaux de
  distribution et au savoir-faire du groupe Vlisco (leader historique du
  wax en Afrique de l'Ouest). Par ailleurs, la lutte contre la contrefaçon
  et l'importation illégale de wax (mesures douanières, labellisation
  "wax véritable") reste déterminante pour la compétitivité future face
  aux imitations asiatiques.

---

## Transport (1)

### SDSC — Africa Global Logistics Côte d'Ivoire
- **Domaine** : logistique, manutention portuaire et transit (ex-Bolloré
  Africa Logistics / SDV, renommé Africa Global Logistics en 2023 après le
  rachat de 100 % de Bolloré Africa Logistics par le groupe maritime
  suisso-italien **MSC** en décembre 2022, pour une valeur d'entreprise de
  5,7 milliards d'euros). Le groupe exploite Abidjan Terminal (1ᵉʳ
  terminal à conteneurs du port d'Abidjan) et a mis en service un second
  terminal, Côte d'Ivoire Terminal (investissement de 400 millions
  d'euros, 37,5 hectares, capacité de 1,5 million d'EVP/an).
- **Produits** : manutention de conteneurs, transit, commissionnaire de
  transport, logistique pour les filières d'exportation (cacao, coton,
  hydrocarbures).
- **Saisonnière** : Oui — volumes fortement liés aux campagnes
  d'exportation agricoles (cacao, café, coton, anacarde) et à la saisonnalité
  du trafic maritime.
- **Détail saisonnier** : haute activité octobre-janvier (démarrage et pic
  de la campagne principale cacao/café, plus forts volumes à
  l'exportation) et février-mai (campagne anacarde/noix de cajou) ; basse
  activité juin-septembre (grande saison des pluies, entre les campagnes).
- **Prix international** : Partiellement — tarifs de fret et de
  manutention indexés en partie sur les cours mondiaux du transport
  maritime (soutes, taux de fret).
- **Cyclique** : Oui, fortement — lié aux volumes du commerce extérieur
  ivoirien, donc au cycle économique mondial et régional.
- **Facteurs de hausse** : croissance du commerce extérieur ivoirien,
  expansion des infrastructures portuaires (extension du port d'Abidjan,
  terminaux à conteneurs), bonnes campagnes d'exportation (cacao, anacarde,
  coton).
- **Facteurs de baisse** : ralentissement du commerce international,
  congestion ou sous-investissement portuaire, mauvaises campagnes
  agricoles d'exportation, concurrence d'autres corridors logistiques
  régionaux (Ghana, Togo).
- **Perspective** : l'expansion du port d'Abidjan (nouveaux terminaux à
  conteneurs) et la croissance structurelle du commerce extérieur ivoirien
  soutiennent une trajectoire de croissance de long terme ; à surveiller,
  la concurrence des corridors logistiques voisins et l'impact de la
  sortie du Mali et du Burkina Faso de la CEDEAO sur les flux de
  marchandises en transit vers ces pays enclavés, dont une partie passe par
  Abidjan.

---

## Télécommunications (3)

Secteur **non saisonnier** (revenus récurrents des abonnements et du mobile
money) et dont les tarifs sont **fixés localement**, encadrés par les
régulateurs télécoms nationaux, sans lien direct avec des cours de matières
premières mondiales. Secteur **défensif, peu cyclique** : les télécoms sont
devenues un service quasi-essentiel dont la consommation résiste bien aux
ralentissements économiques.

### ONTBF — Onatel Burkina Faso (marque Moov Africa)
- **Domaine** : opérateur historique des télécommunications au Burkina
  Faso (téléphonie fixe, mobile, internet), rebaptisé commercialement
  Moov Africa Burkina Faso depuis janvier 2021. Actionnaires de référence :
  groupe **Maroc Telecom** (61 % du capital) et État du Burkina Faso
  (16 %).
- **Produits** : abonnements mobiles/fixes, internet, mobile money.
- **Saisonnière** : Non.
- **Prix international** : Non.
- **Cyclique** : Non — service essentiel à demande récurrente, même si les
  investissements (déploiement data) suivent la croissance économique.
- **Facteurs de hausse** : croissance de la data mobile et du mobile money,
  expansion de la 4G/5G, croissance démographique.
- **Facteurs de baisse** : instabilité sécuritaire au Burkina Faso affectant
  le déploiement et l'exploitation du réseau dans certaines zones,
  concurrence tarifaire, taxation sectorielle.
- **Perspective** : la croissance de la data et du mobile money reste le
  principal moteur, mais l'instabilité persistante au Burkina Faso limite
  les investissements dans certaines régions ; le programme de
  transformation numérique de l'État est un facteur de soutien potentiel.

### ORAC — Orange Côte d'Ivoire
- **Domaine** : opérateur de téléphonie mobile et internet (filiale du
  groupe Orange).
- **Produits** : forfaits mobiles, internet mobile/fixe, Orange Money.
- **Saisonnière** : Non (légers pics de trafic voix/data en période de
  fêtes).
- **Prix international** : Non.
- **Cyclique** : Non — activité défensive.
- **Facteurs de hausse** : croissance de la data et d'Orange Money (paiement
  mobile en forte croissance en Côte d'Ivoire), expansion de la couverture
  4G/5G, croissance démographique et urbanisation.
- **Facteurs de baisse** : intensité concurrentielle (MTN, Moov Africa),
  régulation tarifaire, taxation sectorielle spécifique (télécoms fortement
  taxées en Côte d'Ivoire).
- **Perspective** : Orange Money et la data mobile restent des moteurs de
  croissance solides pour les années à venir, portés par la bancarisation
  numérique croissante ; la concurrence et la pression fiscale resteront
  les principaux vents contraires.

### SNTS — Sonatel Sénégal
- **Domaine** : premier opérateur télécoms d'Afrique de l'Ouest francophone
  (marque Orange au Sénégal, Mali, Guinée, Guinée-Bissau, Sierra Leone).
  Actionnariat : Orange S.A. (42,33 %), État du Sénégal (27,7 %), public
  via la BRVM (20 %), salariés de Sonatel (10 %).
- **Produits** : téléphonie mobile/fixe, internet, Orange Money.
- **Saisonnière** : Non.
- **Prix international** : Non.
- **Cyclique** : Non — activité défensive, leader régional très rentable.
- **Facteurs de hausse** : croissance de la data et d'Orange Money sur les
  cinq marchés couverts, expansion de la fibre optique, statut de leader
  avec forte rentabilité.
- **Facteurs de baisse** : concurrence accrue, régulation tarifaire,
  instabilité politique dans certains marchés du groupe (Mali, Guinée),
  taxation sectorielle.
- **Perspective** : Sonatel reste le mieux placé du secteur télécoms de la
  BRVM pour profiter de la croissance de la data et des paiements mobiles
  sur ses cinq marchés ; l'instabilité politique au Mali et en Guinée est le
  principal facteur de risque à surveiller.

---

## Énergie (5)

Secteur mixte : électricité et eau ont des **tarifs réglementés localement**
(peu ou pas de lien direct avec un marché mondial, même si le coût de
production peut en dépendre indirectement via le fioul/gaz utilisé pour
produire l'électricité) et sont **peu cycliques** (services essentiels) ; à
l'inverse, les distributeurs de produits pétroliers sont **directement
exposés** au cours international du pétrole brut et **modérément cycliques**
(liés au volume d'activité économique et de transport), même si le prix à la
pompe reste souvent encadré par des mécanismes de régulation/subvention
nationaux.

### CIEC — CIE Côte d'Ivoire (Compagnie Ivoirienne d'Électricité)
- **Domaine** : concessionnaire du transport et de la distribution
  d'électricité en Côte d'Ivoire.
- **Produits** : fourniture d'électricité aux particuliers et entreprises.
- **Saisonnière** : Partiellement — pics de consommation en saison chaude
  (climatisation) et lors des périodes de faible hydraulicité (recours accru
  au thermique).
- **Détail saisonnier** : haute consommation février-avril (pic de chaleur
  en fin de saison sèche, forte utilisation de la climatisation) ; basse
  consommation pendant la saison des pluies (juin-septembre), températures
  plus clémentes et meilleur productible hydraulique.
- **Prix international** : Partiellement — tarifs réglementés localement,
  mais coût de production sensible au prix international du gaz/fioul
  utilisé dans les centrales thermiques.
- **Cyclique** : Non, peu cyclique — service essentiel régulé, demande
  relativement stable/croissante, bien que le résultat dépende du mix de
  production (hydraulique/thermique).
- **Facteurs de hausse** : croissance démographique et industrialisation
  ivoirienne, extension du réseau électrique national (accès à
  l'électricité en hausse), bonne hydraulicité réduisant les coûts de
  production thermique.
- **Facteurs de baisse** : sécheresse réduisant la production
  hydroélectrique (recours au thermique plus coûteux), hausse du cours du
  gaz/fioul, tarifs réglementés ne suivant pas toujours la hausse des coûts
  (compression des marges), pertes techniques/commerciales (fraude sur le
  réseau).
- **Perspective** : les investissements dans la diversification du mix
  énergétique (solaire, gaz) et l'extension du réseau électrique national
  soutiennent une trajectoire de croissance de la demande ; la variabilité
  croissante de la pluviométrie liée au changement climatique est un
  facteur de risque à surveiller pour la production hydroélectrique.

### SDCC — Sodeci Côte d'Ivoire (Société de Distribution d'Eau de Côte d'Ivoire)
- **Domaine** : production et distribution d'eau potable.
- **Produits** : fourniture d'eau aux particuliers et entreprises.
- **Saisonnière** : Partiellement — consommation plus forte en saison sèche.
- **Détail saisonnier** : haute consommation novembre-avril, pic en
  février-avril (saison sèche et chaude) ; basse consommation mai-octobre
  (saison des pluies).
- **Prix international** : Non — tarif de l'eau fixé par convention avec
  l'État, sans lien avec un marché mondial.
- **Cyclique** : Non — service essentiel régulé, demande stable (activité
  défensive).
- **Facteurs de hausse** : croissance démographique et urbanisation,
  extension du réseau d'adduction d'eau, programmes d'accès à l'eau
  potable.
- **Facteurs de baisse** : tarifs réglementés limitant la révision des prix
  face à la hausse des coûts, vétusté du réseau (pertes d'eau), stress
  hydrique en cas de sécheresse prolongée.
- **Perspective** : la demande d'eau potable continuera de croître avec
  l'urbanisation, mais les investissements nécessaires pour moderniser le
  réseau (réduction des pertes) et faire face au stress hydrique croissant
  seront déterminants pour la trajectoire future.

### SHEC — Vivo Energy Côte d'Ivoire
- **Domaine** : distribution de produits pétroliers (licence de la marque
  Shell).
- **Produits** : carburants (essence, gasoil), lubrifiants, GPL, stations-
  service.
- **Saisonnière** : Partiellement — légère hausse de la demande en carburant
  liée aux campagnes de transport agricole et aux périodes de vacances.
- **Détail saisonnier** : haute demande octobre-janvier (transport des
  récoltes de la campagne cacao/café) et décembre-janvier/juillet-août
  (déplacements liés aux fêtes et vacances) ; basse demande pendant la
  grande saison des pluies (juin-septembre), transport routier ralenti.
- **Prix international** : Oui — prix directement lié au cours mondial du
  pétrole brut (Brent), même si le prix final à la pompe est souvent
  encadré par une structure des prix fixée par l'État.
- **Cyclique** : Oui, modérément — liée au volume d'activité économique et
  de transport, mais la demande de carburant reste relativement inélastique
  à court terme.
- **Facteurs de hausse** : croissance du parc automobile et du transport
  routier, expansion du réseau de stations-service, diversification
  (boutiques, lubrifiants).
- **Facteurs de baisse** : volatilité du cours du pétrole compressant les
  marges si mal répercutée, réglementation des marges de distribution par
  l'État, concurrence des distributeurs indépendants/informels, transition
  énergétique à long terme (électrification des véhicules).
- **Perspective** : la croissance du parc automobile ouest-africain soutient
  la demande à moyen terme, mais la transition énergétique mondiale est un
  risque structurel de long terme pour le secteur, largement compensé pour
  l'instant par la faible pénétration des véhicules électriques en Afrique
  de l'Ouest ; la diversification vers les boutiques et les services
  associés est un facteur de résilience.

### TTLC — TotalEnergies Marketing Côte d'Ivoire
- **Domaine** : distribution de produits pétroliers (filiale de
  TotalEnergies).
- **Produits** : carburants, lubrifiants, GPL, stations-service, boutiques
  associées.
- **Saisonnière** : Partiellement — même logique que Vivo Energy.
- **Détail saisonnier** : haute demande octobre-janvier (campagne
  cacao/café) et périodes de fêtes/vacances ; basse demande pendant la
  grande saison des pluies (juin-septembre).
- **Prix international** : Oui — même exposition directe au cours du
  pétrole brut.
- **Cyclique** : Oui, modérément — mêmes facteurs que Vivo Energy.
- **Facteurs de hausse** : croissance du parc automobile, diversification
  des revenus (boutiques, lubrifiants, GPL, éventuellement solaire),
  synergies avec le groupe TotalEnergies.
- **Facteurs de baisse** : volatilité des cours pétroliers, réglementation
  des marges par l'État, concurrence, transition énergétique.
- **Perspective** : même dynamique que Vivo Energy, portée par la croissance
  du parc automobile ivoirien et la diversification des revenus (boutiques,
  lubrifiants, solaire) ; la transition énergétique reste un risque de long
  terme mais limité à court/moyen terme.

### TTLS — TotalEnergies Marketing Sénégal
- **Domaine** : distribution de produits pétroliers au Sénégal (filiale de
  TotalEnergies).
- **Produits** : carburants, lubrifiants, GPL.
- **Saisonnière** : Partiellement.
- **Détail saisonnier** : haute demande pendant la campagne arachidière
  (novembre-mars, principale récolte d'exportation du Sénégal) et les
  périodes de fêtes/vacances ; basse demande pendant l'hivernage
  (juillet-septembre, saison des pluies au Sénégal).
- **Prix international** : Oui.
- **Cyclique** : Oui, modérément — mêmes facteurs que TotalEnergies Côte
  d'Ivoire, avec une dépendance particulière à la campagne arachidière
  sénégalaise.
- **Facteurs de hausse** : bonne campagne arachidière (transport des
  récoltes), croissance du parc automobile sénégalais, diversification des
  revenus.
- **Facteurs de baisse** : volatilité des cours pétroliers, mauvaise
  campagne arachidière, réglementation des marges par l'État.
- **Perspective** : la croissance du parc automobile sénégalais et la
  poursuite du développement des infrastructures pétrolières et gazières du
  pays (Sénégal désormais producteur d'hydrocarbures) sont des facteurs
  structurellement favorables à moyen terme.

---

## Services (4)

### ABJC — Servair Abidjan Côte d'Ivoire
- **Domaine** : restauration aérienne (catering) pour les compagnies
  aériennes à l'aéroport d'Abidjan, filiale depuis 2008 de **Servair**
  (filiale historique du groupe **Air France-KLM**) — environ 12 000
  plateaux-repas produits par semaine. Ses deux principaux clients sont
  **Air Côte d'Ivoire** (compagnie nationale) et **Air France** ; la
  société sert aussi Emirates, Afriqiyah Airways, Ethiopian Airlines,
  Kenya Airways, Air Burkina, South African Airways, Air Sénégal et
  Brussels Airlines.
- **Produits** : plateaux-repas et prestations de restauration à bord pour
  les compagnies clientes.
- **Saisonnière** : Partiellement — activité liée au trafic aérien, avec des
  pics lors des périodes de forte affluence touristique/professionnelle
  (fêtes de fin d'année, grands événements).
- **Détail saisonnier** : haute activité en décembre-janvier (fêtes,
  vacances) et juillet-août (grands départs en vacances) ; activité plus
  faible en début d'année (janvier-février, après les fêtes) et pendant la
  grande saison des pluies (juin-juillet, moins de trafic touristique).
- **Prix international** : Non — contrats négociés directement avec les
  compagnies aériennes clientes, même si certaines denrées sont importées.
- **Cyclique** : Oui — lié au trafic aérien, donc au tourisme et aux
  affaires, sensible aux chocs économiques et sanitaires.
- **Facteurs de hausse** : croissance du trafic aérien à Abidjan (hub
  régional en développement), croissance du tourisme et des voyages
  d'affaires, arrivée de nouvelles compagnies desservant Abidjan.
- **Facteurs de baisse** : chocs sur le trafic aérien (crises sanitaires,
  hausse du prix du carburant aérien renchérissant les billets), concurrence
  d'autres prestataires de catering, dépendance à un nombre limité de
  compagnies clientes.
- **Perspective** : la croissance attendue du trafic aérien régional
  (Abidjan qui se positionne comme hub émergent) est favorable à moyen
  terme, mais l'activité reste sensible aux chocs exogènes (crises
  sanitaires, tensions géopolitiques affectant le transport aérien).

### LNBB — Loterie Nationale du Bénin
- **Domaine** : exploitation des jeux de hasard et paris (loterie
  nationale).
- **Produits** : jeux de loterie, paris sportifs, tirages spéciaux.
- **Saisonnière** : Partiellement — pics de mise lors de tirages spéciaux ou
  périodes de fêtes.
- **Détail saisonnier** : haute activité en décembre (fêtes de fin
  d'année, tirages spéciaux) ; reste de l'année porté par l'activité
  récurrente des jeux réguliers, sans creux marqué.
- **Prix international** : Non — mises et gains fixés localement par la
  réglementation nationale des jeux.
- **Cyclique** : Non, plutôt contra-cyclique — les jeux de hasard peuvent
  même profiter des périodes économiques difficiles ("loterie de
  l'espoir"), demande relativement peu sensible au cycle.
- **Facteurs de hausse** : digitalisation des jeux (paris en ligne, mobile),
  lancement de nouveaux jeux/tirages, croissance démographique.
- **Facteurs de baisse** : concurrence des opérateurs de paris sportifs
  privés et des plateformes en ligne informelles, durcissement
  réglementaire (lutte contre l'addiction au jeu, fiscalité).
- **Perspective** : la digitalisation croissante des jeux (paris en ligne,
  mobile) est le principal moteur de croissance future, mais la concurrence
  des plateformes internationales et informelles de paris sportifs est un
  risque grandissant.

### NEIC — NEI-CEDA Côte d'Ivoire
- **Domaine** : édition et distribution de livres, née de la fusion en
  2012 des Nouvelles Éditions Ivoiriennes (NEI, 1992) et du Centre
  d'Édition et de Diffusion Africaines (CEDA) ; premier éditeur de Côte
  d'Ivoire. Chiffre d'affaires très dépendant des marchés publics de
  manuels scolaires (appels d'offres de l'État et des bailleurs), ce qui
  rend ses résultats irréguliers d'une année sur l'autre.
- **Produits** : manuels scolaires, ouvrages pédagogiques, littérature
  générale.
- **Saisonnière** : Oui — l'essentiel des ventes se concentre autour de la
  rentrée scolaire (juillet-octobre).
- **Détail saisonnier** : haute saison juillet-octobre (préparatifs de la
  rentrée scolaire, rentrée effective en septembre) ; basse saison
  novembre-juin, ventes résiduelles limitées à la littérature générale et
  aux réassorts en cours d'année scolaire.
- **Prix international** : Partiellement — le papier et l'impression
  dépendent de cours internationaux de la pâte à papier, mais le prix des
  manuels scolaires est souvent négocié/encadré avec les ministères de
  l'Éducation.
- **Cyclique** : Non — l'éducation est une dépense prioritaire des ménages,
  peu sensible au cycle économique (activité défensive), bien que
  fortement saisonnière.
- **Facteurs de hausse** : croissance démographique scolaire, réformes de
  programmes scolaires nécessitant de nouveaux manuels, marchés publics de
  fourniture de manuels (ministères de l'Éducation).
- **Facteurs de baisse** : piratage/photocopie illégale des manuels
  scolaires, contraintes budgétaires des ménages/États limitant les achats
  de manuels neufs, concurrence d'éditeurs internationaux.
- **Perspective** : la croissance démographique scolaire en Afrique de
  l'Ouest reste un facteur structurellement favorable à long terme, mais la
  digitalisation progressive de l'éducation (manuels numériques) est une
  tendance à surveiller qui pourrait transformer le modèle économique du
  secteur de l'édition scolaire.

### SAFC — Safca Côte d'Ivoire
- **Domaine** : crédit-bail et financement automobile/équipement
  (leasing).
- **Produits** : location avec option d'achat de véhicules et
  d'équipements professionnels, crédit-bail mobilier.
- **Saisonnière** : Non — activité de financement relativement stable sur
  l'année.
- **Prix international** : Non — taux et loyers de crédit-bail fixés
  localement.
- **Cyclique** : Oui — le crédit-bail dépend directement de l'investissement
  des entreprises, très sensible au cycle économique et aux taux d'intérêt.
- **Facteurs de hausse** : croissance de l'investissement des entreprises et
  du parc automobile professionnel, développement du financement
  alternatif au crédit bancaire classique.
- **Facteurs de baisse** : hausse des taux d'intérêt (coût de
  refinancement), ralentissement économique réduisant les besoins
  d'investissement des entreprises, concurrence des banques classiques sur
  le financement d'équipement.
- **Perspective** : le développement du financement alternatif (leasing)
  devrait continuer de croître avec la structuration du secteur privé
  ouest-africain, mais la trajectoire des taux d'intérêt régionaux sera
  déterminante pour la rentabilité future.

---

# Indicateurs de suivi clés par secteur

Pour bien suivre l'évolution d'une entreprise dans le temps, il faut combiner
deux familles d'indicateurs :

1. **Les indicateurs financiers/boursiers**, communs à toutes les sociétés
   cotées, disponibles selon un calendrier réglementaire précis.
2. **Les indicateurs opérationnels sectoriels** (production, clients,
   volumes...) qui expliquent réellement *pourquoi* le chiffre d'affaires ou
   le résultat évolue — ce sont eux qui donnent l'avance sur le marché.

> ⚠️ Réalité du reporting BRVM : seules les publications **semestrielle**
> (comptes au 30 juin, à publier dans les deux mois, donc généralement fin
> août) et **annuelle** (comptes au 31 décembre, à publier dans les quatre
> mois, généralement avant fin avril, suivis de l'assemblée générale
> ordinaire qui statue sur le dividende) sont **obligatoires** pour les
> sociétés cotées à la BRVM, à la différence de marchés comme les
> États-Unis où le trimestriel est une obligation légale. Un suivi
> **mensuel** ou **trimestriel** véritablement chiffré n'est donc, pour la
> plupart des indicateurs opérationnels ci-dessous, disponible qu'auprès de
> **sources tierces** (BCEAO, régulateurs sectoriels, autorités portuaires,
> ministères, associations professionnelles) plutôt que directement
> publié par l'entreprise elle-même — ou via des communiqués volontaires
> que certaines sociétés choisissent de publier pour informer le marché.

## Indicateurs communs à toutes les sociétés cotées

| Indicateur | Fréquence | Où le suivre |
|---|---|---|
| Cours de l'action, volume échangé | Quotidien | Bulletin de la cote BRVM |
| Capitalisation boursière, PER, rendement du dividende (dividend yield) | Mensuel (calculé) | BRVM / calcul à partir du cours et des derniers résultats publiés |
| Chiffre d'affaires, résultat opérationnel, résultat net | Semestriel + Annuel | Rapport de gestion / états financiers publiés |
| Capitaux propres, dette financière, ratio d'endettement (gearing) | Semestriel + Annuel | Bilan |
| Dividende par action, taux de distribution | Annuel | Assemblée générale ordinaire (AGO) |
| Effectifs, masse salariale | Annuel | Rapport annuel |
| Notation financière (si notée), covenants de dette obligataire | Annuel (ou lors d'émission) | Agence de notation régionale, note d'information visée par le CREPMF |

## Banques (15)

| Indicateur | Fréquence | Où le suivre |
|---|---|---|
| Taux directeur et taux d'intérêt du marché monétaire régional | Mensuel | BCEAO |
| Encours de crédits à la clientèle, encours de dépôts | Semestriel + Annuel | États financiers |
| Produit net bancaire (PNB) | Semestriel + Annuel | Compte de résultat |
| Coefficient d'exploitation (charges/PNB) | Semestriel + Annuel | États financiers |
| Coût du risque, taux de créances en souffrance (NPL) | Semestriel + Annuel | États financiers / notes annexes |
| Ratio de solvabilité (fonds propres CET1) | Semestriel + Annuel | États financiers, reporting prudentiel BCEAO |
| Rentabilité (ROE, ROA) | Semestriel + Annuel | États financiers |
| **Nombre de clients / comptes actifs, nombre d'agences** | Annuel (parfois communiqué en semestriel) | Rapport annuel, communiqués de la banque |
| Taux de bancarisation du pays | Annuel | BCEAO |

## Distribution (3)

| Indicateur | Fréquence | Où le suivre |
|---|---|---|
| Immatriculations de véhicules neufs sur le marché national, part de marché | Mensuel | Direction des transports / douanes / association des concessionnaires |
| **Nombre de véhicules ou d'équipements vendus** | Annuel (rarement infra-annuel) | Rapport annuel, communiqués |
| Chiffre d'affaires, marge brute | Semestriel + Annuel | États financiers |
| Réseau de points de vente / concessions | Annuel | Rapport annuel |
| Coût des importations (indice prix acier/ciment/véhicules, taux de change euro) | Mensuel | Douanes, statistiques nationales |

## Agriculture (5)

| Indicateur | Fréquence | Où le suivre |
|---|---|---|
| **Production (tonnes)** par produit — caoutchouc, huile de palme, sucre | Trimestriel à annuel selon la société (certaines communiquent en semestriel) | Rapport de gestion, communiqués de production |
| Cours mondial de la matière première suivie (SICOM pour le caoutchouc, Bursa Malaysia pour l'huile de palme, ICE pour le sucre) | Quotidien | Marchés à terme internationaux |
| Surface plantée / exploitée en production, âge moyen du verger | Annuel | Rapport annuel |
| Rendement à l'hectare | Annuel | Rapport annuel |
| Prix de vente moyen réalisé sur la période | Semestriel + Annuel | Rapport de gestion |
| Pluviométrie cumulée sur la zone de production | Mensuel | Services météorologiques nationaux (indicateur avancé de production) |
| Effectifs / main-d'œuvre saisonnière mobilisée | Annuel | Rapport annuel |

## Industrie (11)

| Indicateur | Fréquence | Où le suivre |
|---|---|---|
| **Volumes produits/vendus** par ligne de produit | Semestriel + Annuel (trimestriel volontaire pour certaines) | Rapport de gestion |
| Taux d'utilisation des capacités de production | Annuel | Rapport annuel |
| Chiffre d'affaires par segment/activité | Semestriel + Annuel | États financiers |
| Cours des matières premières suivies (cuivre LME, pétrole Brent, cacao, coton, malt) | Quotidien/Mensuel | Marchés à terme internationaux |
| Effectifs | Annuel | Rapport annuel |

## Transport / Logistique (1)

| Indicateur | Fréquence | Où le suivre |
|---|---|---|
| **Volume de conteneurs manutentionnés (en EVP)**, tonnage total traité | Mensuel | Port Autonome d'Abidjan (statistiques portuaires publiques) |
| Nombre de navires traités, temps d'attente moyen au port | Mensuel | Port Autonome d'Abidjan |
| Chiffre d'affaires | Semestriel + Annuel | États financiers |
| Volumes des filières exportatrices suivies (cacao, anacarde, coton) | Mensuel (campagne) | Conseil du Café-Cacao, autorités des filières agricoles |

## Télécommunications (3)

| Indicateur | Fréquence | Où le suivre |
|---|---|---|
| **Nombre d'abonnés mobiles/internet**, taux de pénétration | Trimestriel | Régulateur télécoms national (ex. ARTCI en Côte d'Ivoire, ARTP au Sénégal) |
| Nombre de comptes mobile money actifs, volume de transactions | Trimestriel | Régulateur télécoms/BCEAO |
| Revenu moyen par abonné (ARPU) | Semestriel + Annuel | Rapport de gestion |
| Chiffre d'affaires, EBITDA et marge d'EBITDA | Semestriel + Annuel | États financiers |
| Investissements réseau (capex / chiffre d'affaires) | Annuel | Rapport annuel |

## Énergie (5)

| Indicateur | Fréquence | Où le suivre |
|---|---|---|
| **Électricité** : production et consommation nationales (GWh) | Mensuel | Ministère en charge de l'Énergie / CIE |
| **Eau** : volume produit et distribué (m³) | Mensuel/Annuel | Ministère en charge de l'Eau / Sodeci |
| **Carburants** : volumes de carburants vendus (m³) | Mensuel | Ministère en charge du Pétrole / association professionnelle des pétroliers |
| Cours international du pétrole brut (Brent) | Quotidien | Marchés internationaux |
| Nombre de clients/abonnés raccordés, taux d'accès/de couverture | Annuel | Rapport annuel, statistiques nationales |
| Nombre de stations-service (distributeurs de carburant) | Annuel | Rapport annuel |
| Chiffre d'affaires | Semestriel + Annuel | États financiers |

## Services (4)

| Indicateur | Fréquence | Où le suivre |
|---|---|---|
| **Trafic aérien** (passagers, mouvements d'avions à l'aéroport desservi) — pour Servair | Mensuel | Société gestionnaire de l'aéroport (AERIA à Abidjan) |
| **Montant des mises collectées / gains distribués** — pour la loterie | Semestriel + Annuel | Rapport de gestion |
| **Nombre de manuels vendus, parts de marché sur les titres au programme** — pour l'édition scolaire | Annuel (pic en juillet-octobre) | Rapport annuel, ministères de l'Éducation |
| **Encours de financement, nombre de contrats signés dans l'année** — pour le crédit-bail | Semestriel + Annuel | Rapport de gestion |
| Chiffre d'affaires | Semestriel + Annuel | États financiers |

---

# Autres éléments indispensables au suivi (au-delà des chiffres sectoriels)

Les tableaux ci-dessus couvrent la performance opérationnelle et financière
de chaque entreprise. Un suivi complet suppose d'y ajouter cinq autres
familles d'indicateurs, transversales à toutes les sociétés, souvent
négligées mais tout aussi déterminantes pour anticiper l'évolution du titre.

## 1. Contexte macroéconomique et monétaire

Aucune de ces 47 entreprises n'évolue en vase clos : leur activité est
directement irriguée par la conjoncture régionale.

| Indicateur | Fréquence | Où le suivre |
|---|---|---|
| Taux directeur, taux d'inflation UEMOA | Mensuel | BCEAO |
| Croissance du PIB par pays (Côte d'Ivoire, Sénégal, Bénin, Burkina Faso, Mali, Niger, Togo) | Trimestriel + Annuel | BCEAO, FMI, instituts nationaux de statistique |
| Taux de change FCFA/euro (fixe) et FCFA/dollar (variable — pertinent pour les matières premières cotées en USD) | Quotidien | BCEAO, marché des changes |
| Endettement public des États de l'UEMOA (soutenabilité des budgets d'infrastructures) | Trimestriel + Annuel | BCEAO, FMI |
| Cours du pétrole brut (Brent), indices de matières premières agricoles (caoutchouc, huile de palme, sucre, cacao, coton) | Quotidien | Marchés internationaux |
| Indices BRVM Composite et indices sectoriels BRVM | Quotidien | BRVM |
| Indicateurs de risque politique/sécuritaire par pays (élections, zones d'instabilité au Sahel) | Continu | Presse spécialisée, agences de notation risque-pays |

## 2. Gouvernance et actionnariat

Un changement d'actionnaire de référence ou une opération capitalistique en
dit souvent plus long, à moyen terme, que le résultat trimestriel.

| Indicateur | Fréquence | Où le suivre |
|---|---|---|
| Structure de l'actionnariat, part du flottant, part de l'actionnaire de référence | Annuel (ou lors d'un mouvement) | Rapport annuel, avis BRVM |
| Composition du conseil d'administration, changements de direction générale | Continu | Communiqués de la société, avis BRVM |
| Transactions des dirigeants et actionnaires de référence (achats/cessions de blocs) | Continu | Avis BRVM, déclarations réglementaires |
| Opérations sur le capital : augmentation de capital, émission obligataire, rachat d'actions, offre publique | Continu (selon opérations) | Notes d'information visées CREPMF, BRVM |
| Conventions réglementées / transactions avec les parties liées (ex. flux avec la maison mère à l'étranger) | Annuel | Rapport annuel (notes annexes) |

## 3. Marché et liquidité boursière du titre

La qualité d'une information financière ne sert à rien si le titre ne se
négocie pas — un enjeu réel sur plusieurs valeurs de la BRVM.

| Indicateur | Fréquence | Où le suivre |
|---|---|---|
| Volume moyen quotidien échangé, nombre de jours sans transaction | Mensuel | BRVM |
| Flottant réel (part du capital effectivement disponible à l'échange) | Annuel | BRVM, rapport annuel |
| Part des investisseurs étrangers dans le capital/les échanges | Annuel | BRVM, dépositaire central (DC/BR) |
| Écart cours acheteur/vendeur (spread), volatilité du titre | Continu | BRVM |

## 4. Calendrier des événements de l'entreprise

Le calendrier importe autant que les chiffres eux-mêmes pour anticiper les
mouvements du titre.

| Événement | Fréquence | Où le suivre |
|---|---|---|
| Date de publication des comptes semestriels et annuels | Fixe chaque année (semestriel ~fin août, annuel ~avril) | Calendrier BRVM, communiqués de la société |
| Date de l'assemblée générale ordinaire (AGO) et décision sur le dividende | Annuel | Convocation publiée par la société |
| Date de détachement du coupon et date de mise en paiement du dividende | Annuel | BRVM |
| Échéances de la dette (remboursement d'emprunts obligataires, covenants) | Selon plan d'amortissement | Notes d'information des emprunts obligataires |
| Renouvellement de licences/concessions (ex. concession CIE, licence télécom, agrément bancaire) | Selon durée du contrat (souvent pluriannuelle) | Journal officiel, communiqués |

## 5. Durabilité, environnement et conformité (ESG)

De plus en plus suivis par les investisseurs institutionnels et les
bailleurs qui financent certains de ces groupes (BAD, IFC, bailleurs
climat).

| Indicateur | Fréquence | Où le suivre |
|---|---|---|
| Certifications sectorielles (RSPO pour l'huile de palme, FSC pour le bois/caoutchouc, ISO 9001/14001) | Annuel (renouvellement) | Rapport RSE, sites des organismes certificateurs |
| Incidents de sécurité/accidents du travail (secteurs industriels, agricoles, BTP) | Annuel | Rapport RSE/annuel |
| Empreinte carbone, consommation d'eau/énergie (surtout pertinent pour l'agro-industrie et l'énergie) | Annuel | Rapport RSE |
| Conformité aux nouvelles normes d'import export (ex. règlement européen anti-déforestation RDUE pour l'hévéa et l'huile de palme) | Continu (échéances réglementaires) | Communiqués, rapport annuel |
| Litiges en cours, contentieux fiscaux ou fonciers | Continu | Rapport annuel (notes annexes), presse |

---

Avec ces cinq familles d'indicateurs en complément des tableaux
sectoriels ci-dessus, le suivi couvre l'ensemble de la chaîne de causalité :
**contexte macro → activité opérationnelle → résultats financiers →
gouvernance/actionnariat → traduction en cours de bourse**.

---

# Partenaires et clients clés par secteur

> ⚠️ **Deux niveaux d'information** : les **actionnaires/partenaires de
> marque avec un pourcentage** ci-dessous ont été **vérifiés par
> recherche web en août 2026** (sources : rapports BRVM, presse
> économique spécialisée — Jeune Afrique, Agence Ecofin, Sika Finance,
> sites institutionnels des groupes) ; ils peuvent néanmoins évoluer
> (cessions, recompositions capitalistiques) et méritent d'être
> reconfirmés dans le rapport annuel le plus récent avant toute décision.
> Les **catégories de clients** restent une logique structurelle du métier
> (stable dans le temps), sauf lorsqu'un client nommé a pu être vérifié
> (indiqué explicitement). Je ne fournis toujours **pas** de liste
> nominative de contrats/chantiers non vérifiables ("telle société a livré
> tel projet en telle année") au-delà de ce que les sources ci-dessus
> confirment explicitement.

## Banques

| Société | Actionnaire(s) de référence (vérifié) | Catégories de clients typiques |
|---|---|---|
| BICC (BICI CI) | **Plus d'actionnaire international** depuis 2022 : consortium ivoirien BNI/CNPS/CDC-CI/CGRAE ; depuis juillet 2026, Brandon & McCain Capital (Ahmed Cissé) détient 40,2 %, IPS-CNPS 21,54 % | Grandes entreprises, PME, financements structurés |
| SIBC | **Attijariwafa Bank** (Maroc) — 75 % du capital | Entreprises, particuliers, secteur public |
| SGBC | **Société Générale** (France) — actionnaire confirmé encore fin 2025 (contexte : SG a cédé d'autres filiales africaines à Vista Group — Burkina Faso, Congo, Guinée Équatoriale — mais pas la Côte d'Ivoire à ce jour) | Grandes entreprises, État, gestion de fortune |
| BOAB/BOABF/BOAC/BOAM/BOAN/BOAS | **Groupe Bank of Africa**, référence bancaire **BMCE/Bank of Africa** (Maroc) à hauteur d'environ 35 % du capital du groupe | PME, particuliers, filières agricoles d'exportation |
| ECOC | Groupe **Ecobank** / Ecobank Transnational Incorporated (voir ETIT) | Entreprises régionales, commerce transfrontalier |
| ETIT | **Qatar National Bank** (~20,95 %), **Arise BV** (~14,7 %), **Public Investment Corporation** d'Afrique du Sud (~14,05 %) | Filiales bancaires nationales du groupe (30 pays africains) |
| CBIBF | Fondée et présidée par **Idrissa Nassa** (Burkinabè), capital majoritairement burkinabè, indépendant des grands groupes internationaux | PME, filière aurifère et agricole |
| NSBC | NSIA Vie Assurances CI (31,55 %), NSIA Participations (28,49 %), CNPS (17,65 %), public BRVM (16,87 %), IPS-CGRAE (5 %) | Particuliers, entreprises, bancassurance |
| ORGT | **Emerging Capital Partners**, Proparco, BIO, DEG, **BOAD** (40 % dans plusieurs filiales), Fonds Gabonais d'Investissements Stratégiques, IPS-CGRAE — actionnariat en recomposition après l'échec du rachat par Vista Bank | PME, grandes entreprises, trade finance régional |
| BICB (BIIC) | **Caisse des Dépôts et Consignations du Bénin** (CDC Bénin, entité publique), 33 %+ du capital ouvert au public lors de l'IPO d'avril 2025 | Particuliers, PME, filière cotonnière béninoise |
| *Tous* | — | État et collectivités (financement de la dette publique, marché des titres UEMOA), banques correspondantes internationales pour le trade finance |

## Distribution

| Société | Partenaire constructeur/fournisseur (vérifié) | Catégories de clients typiques |
|---|---|---|
| CFAC | **Toyota Tsusho Corporation** (Japon, actionnaire à 100 % de CFAO) — distribue Toyota, Citroën, Peugeot, Mitsubishi, Yamaha, Suzuki, JCB, Bridgestone ; ~42,6 % de part de marché, 1ᵉʳ réseau du pays | Particuliers, flottes d'entreprises, administrations, ONG/agences internationales |
| PRSC (TracTafric Motors) | Filiale de TracTafric Motors Corporation (groupe français **OPTORG**) — distribue BMW, Hyundai, Ford, Mazda, JAC, Chery, MCV, Mercedes-Benz Trucks (**pas Renault**, distribué par le concurrent non coté SOCIDA/groupe Bernard-Hayot) | Entreprises de BTP, administrations (marchés publics), particuliers |
| BNBC | Fournisseurs de matériaux (cimentiers, sidérurgistes) | Entreprises de BTP, promoteurs immobiliers, particuliers |

## Agriculture

Les cinq sociétés du secteur (PALC, SCRC, SICC, SOGB, SPHC) appartiennent
toutes au **groupe SIFCA**, premier groupe agro-industriel ivoirien.

| Filière | Client/partenaire vérifié | Catégories de clients typiques |
|---|---|---|
| Caoutchouc naturel (SOGB, SAPH) | **Michelin**, actionnaire direct de SAPH (~14,8 %) et de SIPH (~33,7 %), est le **principal client** de SAPH parmi les manufacturiers pneumatiques — partenariat privilégié de long terme avec le groupe SIFCA | Autres grands manufacturiers pneumatiques mondiaux (Bridgestone, Continental, Goodyear) |
| Huile de palme (Palm CI, huile associée de SOGB) | — | Industries agroalimentaires et savonnières régionales, marché de consommation domestique |
| Sucre (Sucrivoire, Sicor) | — | Industries agroalimentaires (boissons, confiserie), grande distribution, marché domestique UEMOA |

## Industrie

| Société | Groupe actionnaire / partenaire (vérifié) | Catégories de clients typiques |
|---|---|---|
| CABC (Sicable) | **Prysmian Group** (Italie — et non Nexans) : plateforme commerciale Prysmian/Draka pour l'Afrique | Localement : **CIE, Bouygues Énergies et Services, Sogelux, DMEIB**. À l'export : **SONABEL** (Burkina Faso), **ENEO** (Cameroun), **EDM** (Mali) |
| FTSC (Filtisac) | Partenaire historique de la filière café-cacao-anacarde depuis 1965 ; fournit les sacs des campagnes officielles pour le compte du **Conseil du Café-Cacao** et du **Conseil Coton-Anacarde** | Exportateurs de cacao/café/coton, cimentiers |
| NTLC (Nestlé) | **Nestlé** (Suisse) | Réseau de distributeurs/grossistes locaux, grande distribution |
| SEMC (Eviosys) | Groupe **Eviosys** (ex-Crown Siem, activité rachetée par le fonds américain **KPS Partners** en septembre 2021) | Conserveries thonières et agroalimentaires d'Abidjan |
| SLBC (Solibra) | **BGI** (Brasseries et Glacières Internationales), filiale du groupe **Castel** — ~77 % du capital ; concurrencée depuis 2017 par le nouvel entrant **Brassivoire** (Heineken/CFAO) | Grossistes, cafés/restaurants, grande distribution |
| SMBC (bitume) | — | Administrations routières (agences nationales d'entretien routier), entreprises de BTP |
| STAC (Setao) | Filiale du groupe français **Bouygues Construction** depuis 1950 ; réalisations notables : 3ᵉ pont d'Abidjan, extension du centre commercial Cap Sud, complexe universitaire de Yamoussoukro (INP-HB) | État (ministères des infrastructures, agences routières), bailleurs internationaux (Banque mondiale, BAD, AFD, UE) |
| STBC (Sitab) | **Imperial Brands** (Royaume-Uni) — 73 % du capital ; seul fabricant de cigarettes du pays (~88 % de part de marché), concurrencé par le distributeur BAT Côte d'Ivoire | Distributeurs et grossistes locaux |
| UNLC (Unilever) | **Unilever** (Royaume-Uni/Pays-Bas) — **titre suspendu de cotation BRVM** (flottant de 9,47 %, sous le seuil réglementaire de 20 %) | Grande distribution, grossistes |
| UNXC (Uniwax) | **Changement d'actionnaire en février 2026** : le groupe néerlandais Vlisco (actionnaire depuis 1967) a cédé ses 72,29 % à la **Compagnie Ivoirienne de Coton (COIC)**, société ivoirienne | Grossistes textiles, marché de l'habillement |

## Transport / Logistique

| Société | Groupe actionnaire / partenaire (vérifié) | Catégories de clients typiques |
|---|---|---|
| SDSC (Africa Global Logistics) | **Groupe MSC** (Suisse-Italie), qui a racheté 100 % de Bolloré Africa Logistics en décembre 2022 (5,7 Md€) ; exploite Abidjan Terminal et le nouveau Côte d'Ivoire Terminal (1,5 million d'EVP/an de capacité) | Compagnies maritimes, négociants exportateurs de cacao/anacarde/coton, État (via le Port Autonome d'Abidjan) |

## Télécommunications

| Société | Groupe actionnaire / partenaire (vérifié) | Catégories de clients typiques |
|---|---|---|
| ORAC | Groupe **Orange** (France) | Grand public, entreprises (contrats data/cloud B2B) |
| SNTS (Sonatel) | **Orange S.A.** (42,33 %), État du Sénégal (27,7 %), public BRVM (20 %), salariés (10 %) | Grand public, entreprises |
| ONTBF | **Maroc Telecom** (61 %), État du Burkina Faso (16 %) — marque commerciale Moov Africa depuis janvier 2021 | Grand public, entreprises |
| *Tous* | Équipementiers télécoms (Ericsson, Huawei, Nokia notamment, selon les marchés) pour l'infrastructure réseau | — |

## Énergie

| Société | Groupe actionnaire / partenaire (vérifié) | Catégories de clients typiques |
|---|---|---|
| CIEC | **Eranove** — 54,02 % du capital, concession avec l'État ivoirien renouvelée pour la période 2020-2032 | Ensemble des abonnés, producteurs indépendants d'électricité (CIPREL, Azito) |
| SDCC | **Eranove** — 46,07 % du capital, opère sous contrat d'affermage avec l'État ivoirien | Ensemble des abonnés |
| SHEC (Vivo Energy) | Licence de la marque **Shell** ; capital détenu à 100 % par **Vitol** et **Helios Investment Partners** depuis 2017 (rachat des 20 % restants de Shell) | Automobilistes, transporteurs, entreprises (lubrifiants) |
| TTLC / TTLS | Groupe **TotalEnergies** (France) | Automobilistes, transporteurs, entreprises |

## Services

| Société | Groupe actionnaire / partenaire (vérifié) | Catégories de clients typiques |
|---|---|---|
| ABJC (Servair) | **Servair** (filiale du groupe **Air France-KLM**) depuis le rachat d'Abidjan Catering en 2008 ; ~12 000 plateaux-repas/semaine | Clients confirmés : **Air Côte d'Ivoire et Air France** (deux principaux clients), plus Emirates, Afriqiyah Airways, Ethiopian Airlines, Kenya Airways, Air Burkina, South African Airways, Air Sénégal, Brussels Airlines |
| NEIC | Née de la fusion NEI + CEDA en 2012 ; dépend fortement des appels d'offres publics de manuels scolaires (État + bailleurs) | Ministères de l'Éducation, librairies scolaires |
| LNBB | Tutelle de l'État béninois | Joueurs particuliers |
| SAFC | — | Entreprises (financement de flottes de véhicules et d'équipements), partenaires bancaires pour le refinancement |

---

# Quand acheter, quand vendre : guide pédagogique par entreprise

> ⚠️ **Ceci n'est pas un conseil en investissement personnalisé.** Ce qui
> suit explique la **logique et les raisons** qui peuvent rendre une
> période plus ou moins favorable pour chaque titre, en s'appuyant sur ce
> qui a été établi plus haut dans ce document (saisonnalité, cyclicité,
> actionnariat, perspectives). Ce n'est **pas une prévision de cours**, ni
> une garantie : les tendances passées (saisons, cycles de matières
> premières) sont des probabilités, pas des certitudes — une mauvaise
> nouvelle imprévisible peut casser n'importe quel schéma à tout moment.
> Avant d'investir, passe par une **Société de Gestion et d'Intermédiation
> (SGI)** agréée par la BRVM, vérifie le cours et les derniers résultats
> réels du jour, et n'investis que de l'argent dont tu n'as pas besoin à
> court terme. **Diversifie** : ne mets jamais tout ton argent sur un seul
> titre, aussi convaincant soit l'argument.

## Pour les débutants complets : comprendre "acheter bas, vendre haut"

Une action, c'est une petite part d'une entreprise. Son prix (le "cours")
monte quand beaucoup de gens veulent l'acheter (ils sont optimistes sur
l'avenir de l'entreprise) et baisse quand beaucoup veulent la vendre (ils
sont pessimistes). L'idée de base — acheter quand personne n'en veut (pas
cher) et revendre quand tout le monde en veut (cher) — est simple à
énoncer, mais demande de savoir **anticiper** ces moments. Ce document
utilise trois familles de raisons pour t'aider à repérer ces moments :

1. **La raison saisonnière** (se répète chaque année) : une entreprise
   dont l'activité est plus forte à une période de l'année (ex. les
   ventes de boissons Solibra en saison chaude) voit souvent son résultat
   — et donc son cours — mieux orienté après cette période forte, et plus
   terne après la période creuse. Idée simple : la peur/l'ennui du marché
   pendant la basse saison fait parfois baisser le cours *avant même* que
   les mauvais chiffres ne soient publiés — c'est souvent (pas toujours) un
   bon moment pour acheter, à condition d'être patient jusqu'à la saison
   suivante.
2. **La raison cyclique** (se répète sur plusieurs années) : pour les
   entreprises liées à des matières premières mondiales (caoutchouc, huile
   de palme, sucre, pétrole, cuivre), le bon réflexe est **l'inverse de
   l'instinct naturel** : acheter quand le cours mondial de la matière
   première est bas depuis longtemps et que "tout le monde est
   pessimiste" (le potentiel de rebond futur est le plus grand, même si le
   titre semble "en mauvaise forme" au moment de l'achat), et vendre quand
   ce cours mondial est à un sommet et que "tout le monde est euphorique"
   (le risque de retournement à la baisse devient le plus grand).
3. **La raison "calendrier d'entreprise"** : les résultats semestriels
   (~fin août) et annuels (~avril) sont les moments où le marché "vérifie
   ses copies" — un cours peut fortement bouger juste après leur
   publication. Le dividende, lui, n'est **pas un cadeau gratuit** : le
   jour où il est détaché (versé), le cours de l'action baisse
   mécaniquement du montant du dividende — l'acheter juste avant "pour
   le dividende" ne fait donc pas gagner d'argent en soi, sauf si tu
   comptes le garder longtemps pour l'encaisser année après année.

> ⚠️ **Attention à la liquidité** : sur la BRVM, plusieurs titres
> s'échangent peu chaque jour (voire pas du tout certains jours). Même si
> "le bon moment" est identifié selon la logique ci-dessus, il se peut
> qu'aucun acheteur/vendeur ne soit disponible au prix souhaité ce jour-là
> — un frein pratique important à garder en tête, en particulier sur les
> valeurs les moins actives (voir la colonne "Marché et liquidité
> boursière" plus haut dans ce document).

## Banques (15)

**Logique du secteur** : les banques sont des valeurs **défensives**, sans
saison forte/faible marquée dans l'année — donc pas de fenêtre calendaire
évidente. Le bon moment dépend surtout (a) de la confiance du marché à un
instant donné, (b) du cycle économique régional, et (c) d'événements
propres à chaque banque (nouvel actionnaire, crise dans un pays
d'implantation, publication de résultats). Règle simple : **acheter**
plutôt après une bonne publication de résultats (on a la confirmation que
tout va bien, même si on paie un peu plus cher) ou pendant un accès de
pessimisme du marché qui ne concerne pas vraiment la banque en question ;
**vendre** dès qu'un vrai signal de dégradation apparaît (hausse des
impayés annoncée, instabilité politique grave dans le pays, actionnaire
qui se retire) ou après une forte hausse du cours sans nouvelle qui la
justifie (prise de bénéfices).

### BICB (BIIC)
- **Acheter** : après une publication de résultats confirmant sa position
  de leader au Bénin, ou dans les mois suivant son entrée en bourse
  (avril 2025) si le cours reste raisonnable — un titre récemment introduit
  peut encore être sous-évalué le temps que le marché apprenne à le
  connaître. Aussi favorable : à l'annonce d'une bonne campagne cotonnière
  béninoise (la banque la finance largement).
- **Vendre** : si une mauvaise campagne cotonnière s'annonce, si l'État
  béninois montre des signes de fragilité budgétaire (lien fort via son
  actionnaire CDC Bénin), ou après une forte hausse du cours sans nouvelle
  qui la justifie vraiment.

### BICC (BICI Côte d'Ivoire)
- **Acheter** : juste après une bonne publication de résultats (déjà en
  forte hausse en 2024 et 2025), ce qui confirmerait que le nouvel
  actionnaire ivoirien gère bien la banque ; ou pendant une phase de doute
  du marché sur ce changement d'actionnariat si l'activité réelle
  (résultats, crédits) reste bonne — le doute fait parfois baisser un
  cours sans raison de fond.
- **Vendre** : si la nouvelle direction prend des décisions qui inquiètent
  le marché, ou en cas de net ralentissement du commerce extérieur
  ivoirien (son principal moteur d'activité).

### BOAB / BOABF / BOAC / BOAM / BOAN / BOAS (Bank of Africa)
- **Acheter** : pour les filiales **Côte d'Ivoire, Sénégal, Bénin** —
  après de bons résultats, en profitant d'un accès de pessimisme régional
  qui ne les concerne pas directement. Pour les filiales **Mali, Burkina
  Faso, Niger** — beaucoup plus délicat : n'envisager un achat que si l'on
  accepte un risque élevé, éventuellement quand le cours a beaucoup baissé
  à cause du contexte sécuritaire alors que l'activité bancaire réelle
  continue de tourner (un pari réservé à un investisseur averti, pas à un
  débutant).
- **Vendre** : dès qu'une nouvelle dégradation sécuritaire ou politique
  touche l'un des trois pays du Sahel, ou en cas de restriction des
  transferts de capitaux entre filiales du groupe (signal d'alerte fort).

### CBIBF (Coris Bank International)
- **Acheter** : après une bonne annonce sur la filière aurifère burkinabè
  (premier produit d'exportation du pays, moteur de son économie), ou
  après des résultats confirmant la solidité de la banque malgré le
  contexte sécuritaire.
- **Vendre** : en cas de nouvelle grave touchant le Burkina Faso
  (sécurité, sanctions), ou si le cours mondial de l'or chute fortement
  (moins de revenus dans le pays, donc plus de risque pour la banque).

### ECOC (Ecobank Côte d'Ivoire)
- **Acheter** : après de bons résultats semestriels/annuels, en profitant
  d'un marché ivoirien globalement dynamique.
- **Vendre** : en cas de ralentissement net de l'économie ivoirienne, ou
  si la concurrence bancaire très dense à Abidjan finit par éroder
  durablement ses marges.

### ETIT (Ecobank Transnational Incorporated)
- **Acheter** : pour un investisseur qui croit en une amélioration
  économique au Nigeria et au Ghana (deux grands marchés du groupe) —
  acheter quand ces économies montrent des signes de stabilisation (moins
  d'inflation, monnaie plus stable), car cela profite directement aux
  résultats consolidés du groupe.
- **Vendre** : en cas de nouvelle dévaluation du naira ou du cedi, ou de
  nouvelle crise politique dans un grand marché du portefeuille (Nigeria,
  Ghana, RD Congo).

### NSBC (NSIA Banque Côte d'Ivoire)
- **Acheter** : après de bons résultats combinés banque + assurance (le
  modèle "bancassurance" de NSIA), en période de croissance ivoirienne.
- **Vendre** : si le pôle assurance du groupe (qui détient une grosse part
  du capital de la banque) rencontre des difficultés — cela peut aussi
  peser sur la banque.

### ORGT (Oragroup)
- **Acheter** : seulement pour un investisseur acceptant un risque élevé,
  et de préférence **après** une clarification de la situation
  actionnariale (fin de la recapitalisation, actionnaire de référence
  stabilisé) — avant cette clarification, le titre est trop incertain pour
  un débutant.
- **Vendre** : si la situation actionnariale reste bloquée trop longtemps,
  ou si un nouveau pays du groupe entre en crise politique/sécuritaire.

### SGBC (Société Générale Côte d'Ivoire)
- **Acheter** : après de bons résultats (bénéfice déjà en hausse de 12 %
  rapporté récemment), tant que Société Générale reste actionnaire de
  référence.
- **Vendre** : dès qu'une rumeur ou une annonce de cession de la filiale
  ivoirienne à un autre groupe apparaît (comme cela s'est produit pour
  d'autres filiales SG en Afrique) — les périodes de transition
  d'actionnaire sont souvent risquées pour le cours à court terme.

### SIBC (Société Ivoirienne de Banque)
- **Acheter** : après de bons résultats (déjà en hausse de 6 % mi-2024),
  en profitant du soutien du groupe marocain Attijariwafa Bank.
- **Vendre** : en cas de signe de désengagement d'Attijariwafa, ou de
  ralentissement économique ivoirien marqué.

## Distribution (3)

**Logique du secteur** : valeurs cycliques et modérément saisonnières.
**Acheter** plutôt en fin de basse saison (juin-septembre, période calme),
avant que la demande ne reparte en fin d'année ; **vendre** plutôt en fin
de haute saison (décembre-janvier), une fois les bonnes ventes de fin
d'année déjà connues et intégrées dans le cours.

### BNBC (Bernabé)
- **Acheter** : en milieu d'année, pendant la saison des pluies (chantiers
  ralentis), quand le titre est souvent moins demandé, en anticipant la
  reprise de la construction dès la saison sèche.
- **Vendre** : après la publication des résultats semestriels (fin août),
  qui capturent la bonne saison sèche précédente, avant que la nouvelle
  saison des pluies ne ralentisse de nouveau l'activité.

### CFAC (CFAO Motors)
- **Acheter** : en période de soudure (juin-août), quand les ventes de
  véhicules sont plus calmes, en anticipant la reprise de fin d'année
  (campagne cacao, primes qui financent les achats de véhicules).
- **Vendre** : en début d'année (janvier-février), une fois le pic des
  ventes de fin d'année passé et déjà connu du marché.

### PRSC (TracTafric Motors)
- **Acheter** : en période creuse (grande saison des pluies, mai-
  septembre).
- **Vendre** : après le pic d'activité de fin de campagne agricole/saison
  sèche (autour de mars-avril).

## Agriculture (5)

**Logique du secteur** : pour ces valeurs, le vrai signal n'est pas la
saison dans l'année mais le **cycle des cours mondiaux** (caoutchouc,
huile de palme, sucre), qui dure plusieurs années. Règle simple, contraire
à l'instinct naturel : **acheter** quand le cours mondial de la matière
première est bas depuis longtemps (creux de cycle, pessimisme général,
personne n'en veut) car il finit statistiquement par remonter ; **vendre**
quand ce cours est à un sommet historique ou proche (euphorie générale,
tout le monde en parle) car il finit par redescendre.

### PALC (Palm Côte d'Ivoire)
- **Acheter** : quand le cours mondial de l'huile de palme est bas depuis
  plusieurs mois/années et que les résultats de l'entreprise semblent
  "décevants" à cause de ce prix bas — c'est justement souvent le bon
  moment, car un cours mondial ne reste rarement bas indéfiniment.
- **Vendre** : quand le cours mondial de l'huile de palme atteint un
  sommet historique et que la presse spécialisée parle d'un "âge d'or" de
  la filière — c'est souvent le signe que le sommet est proche.

### SCRC (Sucrivoire) et SICC (Sicor)
- **Acheter** : quand le cours mondial du sucre est bas **et** que la
  protection tarifaire régionale (UEMOA) reste solidement en place —
  combinaison rare mais idéale, qui protège la rentabilité locale même en
  période de cours mondial déprimé.
- **Vendre** : dès qu'une menace sérieuse de libéralisation du marché
  sucrier UEMOA apparaît (remise en cause des droits de douane), même si
  le cours mondial est haut — c'est le vrai risque à surveiller pour ces
  deux titres, plus important que le cours mondial lui-même.

### SOGC (SOGB) et SPHC (SAPH) — hévéa/caoutchouc
- **Acheter** : quand le cours mondial du caoutchouc naturel est proche de
  ses plus bas historiques (souvent après plusieurs années de
  surproduction asiatique) — c'est le moment où l'action a statistiquement
  le plus grand potentiel de rebond.
- **Vendre** : quand le cours mondial du caoutchouc grimpe fortement
  (souvent porté par une forte demande de l'industrie automobile mondiale)
  et atteint des niveaux très élevés par rapport à sa moyenne historique —
  à ce stade, le risque de retournement à la baisse dépasse souvent le
  potentiel de hausse restant.

## Industrie (11)

### CABC (Sicable)
- **Acheter** : quand le cours du cuivre est bas (moins coûteux à
  produire) **et** qu'un grand programme d'électrification régional est
  annoncé — la combinaison des deux est le signal le plus favorable.
- **Vendre** : quand le cours du cuivre grimpe très fort sans que Sicable
  puisse répercuter la hausse sur ses prix de vente — dans ce cas, un
  cuivre cher est en fait une **mauvaise** nouvelle pour la rentabilité,
  contrairement à l'intuition qui associerait matière première chère et
  bon chiffre d'affaires.

### FTSC (Filtisac)
- **Acheter** : avant le démarrage de la campagne cacao/café
  (août-septembre), en anticipant la forte demande de sacs qui accompagne
  la récolte.
- **Vendre** : après le pic de la campagne (décembre-janvier), une fois la
  plus grosse partie des commandes de sacs déjà livrée.

### NTLC (Nestlé Côte d'Ivoire)
- **Acheter** : valeur défensive, sans fenêtre temporelle précise —
  privilégier un achat progressif (petites quantités régulières plutôt
  qu'en une fois), en profitant des moments de pessimisme général du
  marché ivoirien (le titre baisse alors sans vraie raison propre à
  Nestlé).
- **Vendre** : seulement en cas de vraie dégradation durable (perte de
  parts de marché structurelle face aux marques locales moins chères,
  nouvelle taxe spécifique au secteur).

### SEMC (Eviosys)
- **Acheter** : avant/pendant la saison de pêche thonière (mai-septembre),
  en anticipant la forte demande de boîtes de conserve des usines locales.
- **Vendre** : en début d'année (janvier-mars), une fois la saison de
  pêche terminée et l'activité naturellement ralentie.

### SIVC (Erium)
- **Acheter** : en période de grande traite du palmier (avril-août), quand
  la matière première est abondante et moins chère, ce qui améliore les
  marges.
- **Vendre** : en période de petite traite (décembre-mars), quand les
  coûts de matière première grimpent et pèsent sur les marges.

### SLBC (Solibra)
- **Acheter** : en milieu d'année (saison des pluies, ventes de boissons
  plus calmes), en anticipant la reprise en saison sèche/fêtes de fin
  d'année.
- **Vendre** : après les fêtes de fin d'année et le pic de chaleur
  (mars-avril), une fois la meilleure période de vente de l'année passée
  et intégrée au cours. **Signal à surveiller en plus de la saison** :
  toute annonce sur la progression de son concurrent Brassivoire — une
  perte de parts de marché durable serait un motif de vente plus
  important que le simple calendrier saisonnier.

### SMBC (bitume)
- **Acheter** : en début de saison des pluies (mai-juin), quand l'activité
  de pose ralentit et le titre est peu demandé, en anticipant la reprise
  en saison sèche (novembre).
- **Vendre** : en fin de saison sèche (mars-avril), une fois la plupart
  des chantiers routiers de l'année déjà réalisés.

### STAC (Setao)
- **Acheter** : en saison des pluies (chantiers ralentis, juin-août), ou
  surtout **juste après l'annonce d'un grand programme d'infrastructures**
  financé par un bailleur international — un signal plus fort et plus
  durable que la simple saison.
- **Vendre** : en cas d'annonce de restrictions budgétaires publiques ou
  de retards de paiement de l'État (un risque connu et récurrent du
  secteur BTP en Afrique) — à prendre très au sérieux.

### STBC (Sitab)
- **Acheter** : valeur défensive et peu saisonnière — plutôt après une
  baisse du cours liée à une annonce de hausse des accises sur le tabac
  (le marché réagit souvent de façon excessive à cette nouvelle, alors que
  l'entreprise répercute généralement la hausse dans ses prix de vente).
- **Vendre** : si la part de marché face à la contrebande/au marché
  informel se dégrade durablement — regarder les **volumes vendus**, pas
  seulement le chiffre d'affaires, qui peut être gonflé par la seule
  hausse des prix (comme observé en 2025 : CA +25 % mais résultat net
  -18 %).

### UNLC (Unilever Côte d'Ivoire)
- **Acheter** : **impossible pour le moment** — le titre est suspendu de
  cotation à la BRVM (flottant insuffisant). À surveiller : l'annonce
  d'une régularisation du flottant (élargissement à 20 % minimum), seul
  événement qui permettrait un retour à la négociation.
- **Vendre** : sans objet tant que le titre reste suspendu.

### UNXC (Uniwax)
- **Acheter** : de préférence **après** la période de transition avec le
  nouvel actionnaire COIC (début/mi-2026) — laisser le temps à un ou deux
  résultats semestriels de confirmer si ce changement est positif
  (meilleure intégration avec la filière coton locale) ou négatif (perte
  du savoir-faire et des réseaux de distribution de Vlisco) avant
  d'acheter dans l'incertitude immédiate.
- **Vendre** : avant les grandes fêtes de fin d'année si le titre a déjà
  bien monté en anticipation des ventes de Noël (novembre), ou dès les
  premiers signes que la transition d'actionnaire tourne mal (départs de
  cadres clés, perte d'accès aux motifs/à la marque Vlisco).

## Transport (1)

### SDSC (Africa Global Logistics)
- **Acheter** : pendant la basse saison (juin-septembre, entre les
  campagnes d'exportation), en anticipant la reprise d'octobre.
- **Vendre** : après le pic de la campagne cacao/café (décembre-janvier),
  une fois les meilleurs volumes de l'année déjà réalisés et connus du
  marché.

## Télécommunications (3)

**Logique du secteur** : valeurs défensives, peu liées à une saison.
**Acheter** en période de pessimisme général du marché (souvent une
inquiétude sur la fiscalité ou la régulation, qui n'affecte pas la
demande réelle de data/mobile money, en croissance continue) ; **vendre**
seulement en cas de vraie mauvaise nouvelle structurelle (nouvelle taxe
sectorielle lourde, perte de licence, concurrent très agressif qui gagne
durablement des parts de marché).

### ONTBF (Moov Africa Burkina Faso)
- **Acheter** : après une amélioration de la situation sécuritaire au
  Burkina Faso, ou après des résultats montrant que l'activité résiste
  malgré le contexte.
- **Vendre** : en cas de nouvelle dégradation sécuritaire majeure touchant
  directement les infrastructures télécoms.

### ORAC (Orange Côte d'Ivoire)
- **Acheter** : en période de pessimisme lié à la fiscalité télécoms
  (souvent temporaire), pendant que la croissance d'Orange Money continue
  en arrière-plan.
- **Vendre** : si un concurrent (MTN, Moov Africa) gagne durablement des
  parts de marché sur le mobile money, le service le plus rentable
  d'Orange CI.

### SNTS (Sonatel)
- **Acheter** : en période de tension politique au Mali ou en Guinée (deux
  marchés du groupe) qui fait baisser le cours par prudence générale,
  alors que le cœur de l'activité (Sénégal) continue de bien fonctionner.
- **Vendre** : si le Sénégal lui-même (premier marché du groupe) montre
  des signes de ralentissement économique ou de tension politique — un
  signal plus grave que les difficultés dans les autres marchés.

## Énergie (5)

### CIEC (CIE)
- **Acheter** : après une bonne saison des pluies (bons niveaux des
  barrages, donc électricité moins coûteuse à produire, meilleures marges
  à venir).
- **Vendre** : après une sécheresse sévère qui oblige à recourir davantage
  aux centrales thermiques plus coûteuses (compression des marges visible
  avant même la publication des résultats officiels).

### SDCC (Sodeci)
- **Acheter** : valeur peu volatile, adaptée à une **détention longue**
  plutôt qu'à un moment précis d'achat — profiter d'un accès de
  pessimisme général du marché.
- **Vendre** : seulement en cas de remise en cause du contrat d'affermage
  avec l'État (risque rare mais à surveiller).

### SHEC (Vivo Energy), TTLC et TTLS (TotalEnergies)
- **Acheter** : quand le cours mondial du pétrole (Brent) est bas depuis
  un moment — contre-intuitivement, un pétrole pas cher n'est pas
  forcément mauvais pour ces distributeurs (leurs marges dépendent
  surtout du volume vendu et d'une marge réglementée, pas du niveau
  absolu du prix), et un pétrole bas limite aussi le risque que l'État
  gèle les prix à la pompe en cas de flambée.
- **Vendre** : en cas de flambée brutale du Brent qui n'est pas répercutée
  rapidement dans la structure des prix fixée par l'État — les marges de
  distribution sont alors comprimées, ce qui pèse directement sur les
  résultats du trimestre suivant.

## Services (4)

### ABJC (Servair)
- **Acheter** : en période creuse du trafic aérien (janvier-février,
  juin-juillet), en anticipant la reprise des grands départs de fin
  d'année et d'été.
- **Vendre** : après les pics de trafic (décembre-janvier, juillet-août),
  une fois les bons chiffres déjà connus du marché.

### LNBB (Loterie Nationale du Bénin)
- **Acheter** : valeur défensive/contra-cyclique, sans fenêtre précise —
  éventuellement renforcer sa position avant les grands tirages spéciaux
  de fin d'année si le marché ne l'a pas encore anticipé.
- **Vendre** : en cas de durcissement réglementaire sérieux sur les jeux
  d'argent (nouvelle fiscalité, restrictions).

### NEIC (NEI-CEDA)
- **Acheter** : en début d'année (janvier-mars), en pleine basse saison
  des ventes, quand le titre est souvent délaissé, en anticipant la
  rentrée scolaire de septembre.
- **Vendre** : après la rentrée scolaire (octobre-novembre), une fois la
  plus grosse partie des ventes de manuels de l'année réalisée et connue
  du marché. **Signal plus important que la saison** : la perte d'un
  grand marché public de manuels (appel d'offres remporté par un
  concurrent) peut représenter une grosse part du chiffre d'affaires
  annuel — un vrai motif de vente si cela se confirme.

### SAFC (Safca)
- **Acheter** : en période de taux d'intérêt régionaux bas ou en baisse
  (son activité de crédit-bail devient plus rentable et plus demandée).
- **Vendre** : en période de hausse des taux directeurs BCEAO (son coût de
  refinancement augmente, ce qui pèse directement sur ses marges).

---

# Leviers pour étudier la politique de rémunération (dividendes)

La "politique de rémunération" d'une entreprise cotée, c'est essentiellement
sa **politique de dividende** : combien elle reverse à ses actionnaires, à
quel rythme, et avec quelle régularité. Voici les leviers — les éléments
concrets à regarder — pour l'analyser, d'abord de façon générale, puis
appliqués à chacune des 47 entreprises.

## Les leviers communs à examiner pour n'importe quelle entreprise

| Levier | Ce qu'il révèle |
|---|---|
| **Taux de distribution** (payout ratio = dividende total / résultat net) | La part du bénéfice reversée aux actionnaires plutôt que réinvestie. Un taux élevé (>60-70 %) signale une entreprise mature à faibles besoins d'investissement ; un taux faible signale une entreprise qui préfère financer sa croissance. |
| **Rendement du dividende** (dividende par action / cours de l'action) | Ce que rapporte le dividende par rapport au prix payé aujourd'hui. Le rendement moyen à la BRVM se situe autour de **5 à 7 % par an**, ce qui en fait une place réputée pour les revenus passifs. |
| **Régularité et tendance dans le temps** | Une entreprise qui verse un dividende stable ou croissant année après année (ex. Sonatel) inspire davantage confiance qu'une entreprise aux versements erratiques. |
| **Capacité de génération de free cash-flow** | Résultat net moins les investissements nécessaires (capex) pour maintenir/développer l'activité. Une entreprise à forte intensité capitalistique (BTP, énergie, agro-industrie en phase de replantation) dispose structurellement de moins de marge pour distribuer. |
| **Endettement et contraintes réglementaires** | Une entreprise très endettée doit d'abord honorer sa dette. Pour les **banques**, la BCEAO a durci depuis le 1ᵉʳ janvier 2023 les normes prudentielles de solvabilité et de division des risques, et invite explicitement les banques de l'UEMOA à **la prudence dans la distribution de dividendes** tant que leurs fonds propres ne sont pas suffisamment renforcés — un levier réglementaire spécifique et récent à surveiller pour tout le secteur bancaire. |
| **Besoins de cash de l'actionnaire de référence** | Un État actionnaire (ex. CDC Bénin dans BICB, État ivoirien dans CIE/Sodeci) a souvent besoin du dividende pour son propre budget, ce qui pousse à une distribution régulière. Une maison-mère étrangère (Nestlé, Unilever, TotalEnergies...) rapatrie généralement une partie des bénéfices de sa filiale via le dividende. |
| **Phase du cycle** (matières premières, BTP) | Pour les valeurs cycliques, le dividende peut être exceptionnellement généreux en haut de cycle (bons cours mondiaux, gros carnet de commandes) et réduit — voire supprimé — en bas de cycle. Il faut donc analyser le dividende **en le rapportant à l'année en cours**, pas comme un montant "habituel". |
| **Fiscalité du dividende selon le pays d'émission** | L'impôt sur le revenu des valeurs mobilières (IRVM), prélevé à la source, varie selon le pays où est basée l'entreprise : **12,5 % au Burkina Faso**, **10 % en Côte d'Ivoire et au Sénégal**, **7 % au Mali**, **5 % au Bénin**, **7 % (personnes morales) / 3 % (personnes physiques) au Togo**. À rendement brut affiché égal, le dividende **net** perçu par l'investisseur diffère donc selon le pays d'émission du titre. |
| **Calendrier** | Date de l'AGO (décision du montant), date de détachement du coupon (le cours baisse alors du montant du dividende) et date de mise en paiement — à retrouver dans les avis BRVM de chaque société. |

## Nuances par secteur

- **Télécommunications** : les moins capitalistiques une fois le réseau
  déployé — génèrent beaucoup de cash disponible pour le dividende.
  **Sonatel** en est la référence régionale : dividende brut de 1 933
  FCFA/action au titre de 2025 (174 milliards FCFA au total), rendement
  d'environ 6,24 %, et réputation d'être l'une des valeurs BRVM les plus
  régulières — voire en progression — dans sa politique de distribution.
- **Banques** : sous la contrainte directe des ratios prudentiels BCEAO
  (renforcés depuis 2023) — leur capacité de distribution dépend d'abord
  du respect de leurs ratios de solvabilité, à vérifier avant tout autre
  levier.
- **Agriculture (caoutchouc, huile de palme, sucre)** : dividende très
  variable, calé sur le cycle des cours mondiaux. Exemple concret : SOGB a
  décidé un dividende brut de 570 FCFA/action (12,3 milliards FCFA au
  total) au titre de l'exercice 2025 — un montant à comparer chaque année
  au résultat net réellement réalisé, qui fluctue fortement avec les cours
  du caoutchouc.
- **Énergie régulée (électricité, eau)** : dividende plus stable, encadré
  indirectement par les termes du contrat de concession/d'affermage avec
  l'État et par le niveau des investissements réseau exigés.
- **BTP/Distribution/Industrie cyclique** : dividende qui suit
  l'irrégularité des grands contrats/campagnes — à analyser en tendance
  sur plusieurs années plutôt que sur un seul exercice.

## Leviers spécifiques par entreprise

### Banques
- **BICB (BIIC)** : pas encore d'historique de dividende (IPO d'avril
  2025) — le premier exercice complet publié donnera le premier vrai
  signal. Levier à surveiller : les besoins budgétaires de son actionnaire
  public, la CDC Bénin, qui pousseront probablement vers une distribution
  régulière une fois la banque stabilisée en bourse.
  **Perspective (rémunération)** : montée en puissance progressive
  probable du dividende à mesure que la banque installe sa crédibilité de
  titre coté ; l'actionnaire public devrait pousser vers une politique
  régulière dès que les premiers exercices confirmeront la solidité des
  résultats.
- **BICC** : le changement d'actionnaire (Ahmed Cissé/Brandon & McCain
  Capital) est le levier clé — une direction entrante peut soit maintenir
  la politique historique pour rassurer le marché, soit préférer retenir
  les bénéfices (déjà en forte hausse : +57 % en 2024, +39 % en 2025) pour
  financer sa propre stratégie de croissance.
  **Perspective (rémunération)** : trajectoire incertaine à court terme
  tant que le nouvel actionnaire n'a pas communiqué sa doctrine, mais
  plutôt haussière si Brandon & McCain Capital cherche à rassurer le
  marché après le changement d'actionnariat, la croissance des résultats
  offrant une marge de manœuvre réelle.
- **BOAB/BOABF/BOAC/BOAM/BOAN/BOAS** : le ratio de solvabilité de chaque
  filiale prise séparément est le levier prioritaire (recommandation de
  prudence BCEAO) ; le groupe peut aussi choisir de retenir davantage de
  cash dans les filiales les plus exposées au risque sécuritaire
  (Mali/Burkina Faso/Niger) plutôt que de le distribuer.
  **Perspective (rémunération)** : dividende probablement maintenu ou en
  légère hausse pour les filiales stables (Côte d'Ivoire, Sénégal, Bénin),
  mais restriction voire gel probable dans les filiales sahéliennes tant
  que le contexte sécuritaire ne s'améliore pas — une trajectoire à deux
  vitesses selon le pays.
- **CBIBF** : banque en forte phase d'expansion régionale — lever à
  vérifier : un taux de distribution plus faible que la moyenne du secteur
  serait cohérent avec une entreprise qui réinvestit dans l'ouverture de
  nouveaux marchés plutôt que de privilégier le dividende immédiat.
  **Perspective (rémunération)** : taux de distribution probablement
  appelé à rester modéré tant que dure la phase de croissance régionale ;
  une hausse sensible du dividende serait le signal que cette expansion
  ralentit au profit d'une politique de rendement plus généreuse.
- **ECOC** : à comparer avec la politique du groupe Ecobank (voir ETIT
  ci-dessous) et avec la contrainte prudentielle BCEAO commune au secteur.
  **Perspective (rémunération)** : évolution alignée sur celle du groupe
  Ecobank, avec un potentiel de hausse si la reprise du dividende
  observée au niveau du groupe (voir ETIT) se confirme durablement.
- **ETIT** : levier notable et récent — la presse spécialisée rapporte
  qu'Ecobank a "**retrouvé**" le chemin du dividende, porté par ses
  "meilleurs fondamentaux depuis dix ans" ; à surveiller : si cette reprise
  se confirme dans la durée ou dépend d'une embellie ponctuelle au Nigeria
  et au Ghana.
  **Perspective (rémunération)** : signal positif fort, mais sa pérennité
  dépendra directement de la poursuite de la stabilisation macroéconomique
  au Nigeria et au Ghana — deux marchés qui conditionnent la capacité du
  groupe à maintenir cette dynamique dans la durée plutôt qu'un simple
  rebond ponctuel.
- **NSBC** : ses actionnaires de référence (NSIA Vie Assurances CI + NSIA
  Participations, ~60 % du capital à eux deux) ont potentiellement besoin
  de dividendes remontant vers le groupe pour financer sa propre expansion
  régionale — un levier à surveiller à travers les flux intra-groupe.
  **Perspective (rémunération)** : dividende probablement stable à
  légèrement croissant, porté par les synergies bancassurance, sauf choc
  affectant l'un des deux pôles (banque ou assurance) du groupe.
- **ORGT** : en cours de recapitalisation — levier prioritaire : vérifier
  si l'entreprise verse un dividende du tout tant que sa situation
  actionnariale n'est pas stabilisée (probable absence ou dividende
  symbolique dans l'intervalle).
  **Perspective (rémunération)** : dividende probablement suspendu ou très
  limité tant que la recapitalisation et la clarification actionnariale ne
  sont pas achevées ; une reprise n'est plausible qu'après stabilisation
  d'un actionnaire de référence solide.
- **SGBC** : la politique du groupe Société Générale sur ses filiales
  africaines est le levier à surveiller — un contexte de désengagement du
  groupe (déjà observé sur d'autres pays) peut se traduire soit par un
  dividende exceptionnel avant cession, soit par un gel en cas
  d'incertitude sur l'actionnariat futur.
  **Perspective (rémunération)** : maintien probable à court terme tant
  que Société Générale reste actionnaire, mais avec un risque réel de
  rupture brutale de la politique en cas d'annonce de cession — à
  surveiller de très près étant donné les précédents dans d'autres pays du
  groupe.
- **SIBC** : la politique d'Attijariwafa Bank sur ses filiales africaines
  (généralement portée vers des dividendes réguliers rapatriés vers le
  Maroc) est le principal levier de lecture.
  **Perspective (rémunération)** : dividende probablement stable et
  régulier, aligné sur la politique généralement prévisible d'Attijariwafa
  Bank sur l'ensemble de ses filiales africaines.

### Distribution
- **BNBC, CFAC, PRSC** : dividende qui suit l'irrégularité du cycle
  BTP/automobile — le levier clé est de comparer le taux de distribution
  d'une bonne année (fin de saison sèche/campagne agricole faste) à celui
  d'une année creuse, pour voir si l'entreprise "lisse" ses versements ou
  les fait varier au même rythme que ses résultats.
  **Perspective (rémunération)** : le dividende devrait continuer à suivre
  les cycles du BTP et de l'automobile ; une hausse durable signalerait
  une consolidation de la demande locale (urbanisation, motorisation),
  tandis qu'un ralentissement des grands travaux publics pèserait sur les
  prochains exercices.

### Agriculture
- **PALC, SOGC (SOGB), SPHC (SAPH)** : le levier central est le **cours
  mondial de la matière première de l'exercice concerné** (huile de palme,
  caoutchouc) — exemple vérifié : SOGB a annoncé 570 FCFA/action brut pour
  2025 (12,3 milliards FCFA), un montant à comparer chaque année plutôt
  que de considérer un "dividende habituel", tant les cours mondiaux
  varient d'un exercice à l'autre.
  **Perspective (rémunération)** : volatilité structurelle appelée à
  perdurer, mais la demande mondiale de fond (industrie pneumatique pour
  le caoutchouc, alimentaire pour l'huile de palme) reste porteuse à
  moyen terme, ce qui soutient un potentiel de dividende globalement
  favorable sur plusieurs années, sous réserve d'un cycle de prix pas
  trop défavorable au moment de chaque exercice.
- **SCRC (Sucrivoire), SICC (Sicor)** : levier supplémentaire par rapport
  aux deux précédents — le maintien ou non de la protection tarifaire
  régionale UEMOA sur le sucre, qui sécurise (ou fragilise) la capacité de
  distribution en atténuant l'exposition au cours mondial.
  **Perspective (rémunération)** : la trajectoire du dividende dépendra
  avant tout du maintien de la protection tarifaire régionale ; sa remise
  en cause serait le principal risque baissier pour la capacité de
  distribution future de ces deux titres.

### Industrie
- **CABC (Sicable)** : arbitrage à surveiller entre investissements de
  modernisation de l'outil industriel (capex) et dividende.
  **Perspective (rémunération)** : dividende probablement stable tant que
  les investissements de modernisation restent maîtrisés ; un programme
  d'investissement massif dans l'outil industriel pourrait temporairement
  réduire la distribution.
- **FTSC (Filtisac)** : résultats irréguliers selon les campagnes
  cacao/café — dividende à lire en cohérence avec le volume de la
  campagne de l'exercice concerné.
  **Perspective (rémunération)** : dividende qui devrait rester en phase
  avec le cycle des campagnes cacao/café/coton, avec un potentiel de
  soutien si les niveaux de prix du cacao restent élevés (volumes à
  ensacher stables ou en hausse).
- **NTLC (Nestlé), UNLC (Unilever)** : dépend de la politique globale du
  groupe international actionnaire sur le rapatriement de dividendes
  depuis ses filiales africaines. Pour **UNLC en particulier**, le
  dividende est de facto gelé tant que le titre reste suspendu de
  cotation à la BRVM (flottant insuffisant).
  **Perspective (rémunération)** : pour Nestlé CI, dividende probablement
  stable en lien avec la politique globale du groupe ; pour Unilever CI,
  aucune perspective concrète tant que le titre reste suspendu — la
  priorité est la régularisation du flottant avant toute discussion de
  rémunération.
- **SEMC (Eviosys)** : actionnaire de référence désormais un fonds
  d'investissement (KPS Partners), souvent orienté vers une rentabilité
  et une remontée de cash plus rapides qu'un actionnaire industriel de
  long terme — un levier à surveiller dans les prochains exercices.
  **Perspective (rémunération)** : dividende potentiellement poussé à la
  hausse à moyen terme si le fonds actionnaire privilégie une remontée de
  cash rapide, au risque d'un moindre réinvestissement dans l'outil
  industriel.
- **SLBC (Solibra)** : le groupe Castel/BGI est historiquement un bon
  distributeur sur ses filiales africaines, mais la concurrence croissante
  de Brassivoire (depuis 2017) est le levier à surveiller : une
  compression des marges réduirait la capacité de distribution future.
  **Perspective (rémunération)** : pression modérée à moyen terme si
  Brassivoire continue de gagner des parts de marché, sauf si Solibra
  parvient à défendre ses marges par l'innovation produit.
- **SMBC (bitume), STAC (Setao)** : dividende très dépendant du carnet de
  commandes publiques de l'exercice — à comparer aux années de grands
  travaux financés par des bailleurs internationaux (années fastes) versus
  les années de restrictions budgétaires publiques (années creuses).
  **Perspective (rémunération)** : trajectoire très dépendante du
  calendrier des grands travaux publics régionaux ; une reprise des
  investissements en infrastructures routières (BAD, Banque mondiale)
  soutiendrait une trajectoire favorable à moyen terme.
- **STBC (Sitab)** : activité défensive à forte génération de cash
  (peu de capex récurrent), historiquement bonne distributrice. Levier à
  vérifier sur le dernier exercice : le résultat net 2025 a reculé de
  18 % malgré un chiffre d'affaires en hausse de 25 % (hausse des accises)
  — à voir si le dividende suit cette baisse du résultat net ou si la
  trésorerie accumulée permet de le maintenir.
  **Perspective (rémunération)** : risque à court terme sur le montant du
  dividende si la pression fiscale sur le tabac continue de s'intensifier
  ; la trésorerie accumulée par cette activité historiquement très
  rentable devrait toutefois limiter une baisse brutale.
- **UNXC (Uniwax)** : le changement d'actionnaire de référence (Vlisco →
  COIC, février 2026) est le levier prioritaire — une société ivoirienne
  du secteur cotonnier peut avoir une politique de rémunération différente
  de celle d'un groupe néerlandais qui rapatriait une partie du dividende
  aux Pays-Bas ; à observer sur les premiers exercices sous la nouvelle
  direction.
  **Perspective (rémunération)** : trop tôt pour se prononcer sous la
  nouvelle direction — les premiers exercices sous COIC donneront le
  signal (maintien, baisse ou changement de nature de la politique de
  rémunération).

### Transport
- **SDSC (Africa Global Logistics)** : son intégration récente dans le
  groupe MSC (maison mère internationale, décembre 2022) est le levier à
  surveiller — un programme d'investissement portuaire ambitieux (le
  nouveau Côte d'Ivoire Terminal) peut inciter à une plus grande rétention
  de cash au détriment du dividende dans les prochaines années.
  **Perspective (rémunération)** : priorité probable donnée à
  l'investissement (nouveau terminal portuaire) sur la distribution dans
  les prochaines années, le temps que ces investissements soient amortis.

### Télécommunications
- **SNTS (Sonatel)** : la référence du secteur — dividende brut de 1 933
  FCFA/action pour 2025 (174 milliards FCFA au total), rendement
  d'environ 6,24 %, réputation de régularité voire de progression
  continue. Levier de lecture : sa capacité à maintenir ce niveau dépend
  de la poursuite de la croissance de la data et d'Orange Money sur ses
  cinq marchés.
  **Perspective (rémunération)** : la politique généreuse et régulière de
  Sonatel devrait se maintenir tant que la croissance de la data et
  d'Orange Money se poursuit sur ses cinq marchés — la valeur la plus
  prévisible du secteur sur ce plan.
- **ORAC (Orange Côte d'Ivoire), ONTBF (Moov Africa Burkina Faso)** :
  même logique sectorielle que Sonatel (faible capex récurrent une fois le
  réseau déployé, forte capacité de distribution), à ajuster selon le
  levier fiscal local : IRVM de 10 % en Côte d'Ivoire contre 12,5 % au
  Burkina Faso, ce qui réduit davantage le rendement net perçu par
  l'investisseur sur ce dernier.
  **Perspective (rémunération)** : dividende probablement soutenu à moyen
  terme grâce à la faible intensité capitalistique du secteur, sauf choc
  réglementaire/fiscal ou, pour Onatel BF, dégradation sécuritaire majeure
  au Burkina Faso.

### Énergie
- **CIEC (CIE), SDCC (Sodeci)** : dividende encadré indirectement par les
  termes de la concession/de l'affermage avec l'État ivoirien (actionnaire
  minoritaire ayant lui-même intérêt à un dividende soutenu) et par le
  niveau des investissements réseau exigés sur la période contractuelle
  (2020-2032 pour CIE).
  **Perspective (rémunération)** : dividende probablement stable sur la
  durée du contrat de concession en cours, sous réserve d'un partage
  équilibré entre distribution et investissements réseau exigés par
  l'État.
- **SHEC (Vivo Energy), TTLC/TTLS (TotalEnergies)** : la marge de
  distribution réglementée par l'État limite mécaniquement la variabilité
  du résultat, donc du dividende potentiel ; le levier à surveiller est la
  politique propre de l'actionnaire (Vitol/Helios pour Vivo Energy,
  TotalEnergies pour les deux autres) sur le rapatriement de dividendes.
  **Perspective (rémunération)** : dividende probablement stable à moyen
  terme, la marge réglementée limitant les grosses variations de
  résultat ; risque de pression baissière seulement en cas de transition
  énergétique accélérée réduisant les volumes vendus à très long terme.

### Services
- **ABJC (Servair)** : dépend de la politique du groupe Air France-KLM/
  Servair sur le rapatriement de dividendes de ses filiales africaines.
  **Perspective (rémunération)** : dividende dépendant de la reprise
  durable du trafic aérien régional ; probable stabilité à moyen terme si
  Abidjan confirme sa position de hub émergent.
- **LNBB (Loterie Nationale du Bénin)** : sous tutelle directe de l'État
  béninois, qui a probablement besoin des dividendes de la loterie
  nationale pour son propre budget — un levier qui pousse structurellement
  vers une distribution régulière.
  **Perspective (rémunération)** : dividende probablement stable et
  régulier, la tutelle de l'État béninois créant une pression structurelle
  vers une distribution constante.
- **NEIC (NEI-CEDA)** : résultats irréguliers selon les marchés publics de
  manuels remportés ou non — le dividende doit être analysé en tenant
  compte de la trésorerie cumulée sur plusieurs exercices plutôt que du
  seul résultat de l'année en cours.
  **Perspective (rémunération)** : dividende qui restera irrégulier tant
  que le modèle économique dépendra fortement des marchés publics ; une
  diversification vers d'autres sources de revenus (édition numérique,
  littérature générale) pourrait à terme stabiliser la politique de
  rémunération.
- **SAFC (Safca)** : arbitrage propre à l'activité de crédit-bail — entre
  retenir du capital pour financer la croissance des encours de
  location-financement et distribuer aux actionnaires ; à surveiller via
  l'évolution du taux de distribution en période de forte croissance de
  l'activité versus en période plus calme.
  **Perspective (rémunération)** : dividende qui dépendra de l'arbitrage
  entre croissance des encours de crédit-bail et rémunération des
  actionnaires ; probablement stable tant que les taux d'intérêt régionaux
  restent maîtrisés.

---

# Structure de base de données pour un suivi complet des entreprises

Pour pouvoir saisir et exploiter dans l'application tous les éléments
présentés dans ce document (actionnariat, clients/partenaires, chiffre
d'affaires/résultat net, indicateurs sectoriels, saisonnalité, cyclicité,
dividendes, ESG, calendrier, liquidité...), voici les tables à créer —
en réutilisant ce qui existe déjà dans le schéma actuel (`companies`,
`financial_statements`, `market_bulletin_corporate_actions`,
`company_market_events`) plutôt que de le dupliquer.

## Ce qui existe déjà et n'est pas à recréer

| Besoin | Table déjà en place | Remarque |
|---|---|---|
| Fiche entreprise (nom, secteur, pays, capitalisation, actions en circulation...) | `companies` | Quelques colonnes à ajouter (voir plus bas) |
| **Chiffre d'affaires, résultat net, bilan, PNB bancaire...** | `financial_statements` + `financial_statement_lines` | Modèle générique par `line_key` (migration 023) — n'y touche pas, il gère déjà tous les formats (SYSCOHADA, bancaire, flux de trésorerie) |
| **Montants de dividendes versés, augmentations de capital, admissions** | `market_bulletin_corporate_actions` | Alimentée par l'extraction IA des bulletins (migration 012) — couvre les *montants* réels ; les tables ci-dessous couvrent l'analyse *qualitative* de la politique de rémunération |
| Journal d'événements de marché (contrats, litiges, changements de direction) | `company_market_events` | Réutilisable tel quel pour les événements ponctuels — pas besoin d'une nouvelle table d'événements |
| Rapports annuels/documents sources | `company_reports`, `company_documents` | Sert de `source_report_id` pour les nouvelles tables ci-dessous |
| Carnet d'ordres, flux d'exécution (liquidité fine, intraday) | `order_book_snapshots`, `intraday_execution_flow` | Couvre déjà la liquidité *au jour le jour* — la table `company_market_liquidity_snapshots` ci-dessous couvre des indicateurs *structurels* à plus basse fréquence (flottant, part étrangère) |

## Extension de la table `companies`

Quatre colonnes suffisent pour les informations les plus consultées (évite
une jointure systématique) — le détail historisé reste dans les tables
dédiées plus bas.

```sql
ALTER TABLE companies
    ADD COLUMN listing_status ENUM('cotee','suspendue','radiee') NOT NULL DEFAULT 'cotee'
        COMMENT 'Ex. Unilever CI suspendue pour flottant insuffisant',
    ADD COLUMN parent_group_name VARCHAR(150) NULL
        COMMENT 'Nom court du groupe/actionnaire de référence pour affichage rapide (ex. "Attijariwafa Bank"), le détail chiffré et historisé vit dans company_shareholders',
    ADD COLUMN free_float_percent DECIMAL(5,2) NULL
        COMMENT 'Dernier flottant connu, en % du capital — copie rapide du dernier snapshot de company_market_liquidity_snapshots',
    ADD COLUMN dividend_regularity ENUM('reguliere','irreguliere','suspendue','jamais_verse') NULL
        COMMENT 'Résumé rapide pour affichage liste/écran de synthèse ; le détail des leviers vit dans company_analysis_notes';
```

## 1. `company_shareholders` — actionnariat

Historise QUI détient le capital, avec période de validité — indispensable
pour capter un changement d'actionnaire (ex. BICI CI : consortium ivoirien
→ Ahmed Cissé ; Uniwax : Vlisco → COIC) sans écraser l'ancienne donnée.

```sql
CREATE TABLE company_shareholders (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    shareholder_name VARCHAR(200) NOT NULL,
    shareholder_type ENUM(
        'etat', 'groupe_industriel', 'banque_institution_financiere',
        'fonds_investissement', 'flottant_public', 'salaries', 'autre'
    ) NOT NULL,
    ownership_percent DECIMAL(5,2) NULL COMMENT 'NULL si non chiffré précisément par la source',
    is_reference_shareholder TINYINT(1) NOT NULL DEFAULT 0,
    valid_from DATE NULL COMMENT 'Date de prise de participation si connue',
    valid_to DATE NULL COMMENT 'NULL = participation actuelle ; renseigné le jour où elle cesse (cession, dilution)',
    source_note VARCHAR(255) NULL COMMENT 'Ex. "Rapport annuel 2025" ou "Communiqué BRVM du 12/07/2026"',
    source_url VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_current (company_id, valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> Le **nombre d'actionnaires** demandé peut se lire de deux façons
> différentes selon le sens voulu : `SELECT COUNT(*) FROM
> company_shareholders WHERE company_id=? AND valid_to IS NULL` donne le
> nombre d'actionnaires *de référence identifiés* ; le nombre total de
> porteurs (y compris les petits actionnaires individuels du flottant)
> n'est en revanche **pas disponible publiquement** pour la plupart des
> sociétés BRVM — seul le dépositaire central (DC/BR) le connaît.

## 2. `company_business_relationships` — partenaires ET clients

Les "partenaires" (actionnaire technique, licence de marque, fournisseur,
équipementier) et les "clients" (nommés ou par catégorie) ont la même
forme — une contrepartie liée à l'entreprise avec un rôle — d'où une seule
table avec un type, plutôt que deux tables quasi identiques.

```sql
CREATE TABLE company_business_relationships (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    relationship_type ENUM(
        'actionnaire_technique', 'licence_marque', 'fournisseur_cle',
        'equipementier', 'distributeur', 'client_principal',
        'client_categorie', 'autre'
    ) NOT NULL,
    counterparty_name VARCHAR(200) NOT NULL COMMENT 'Nom de l''entreprise/entité, ou libellé de catégorie si is_named=0 (ex. "Grande distribution")',
    is_named TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = contrepartie nommée et vérifiée, 0 = catégorie générique de clients',
    rank_importance TINYINT NULL COMMENT '1 = relation la plus importante connue, 2 = suivante, etc. NULL si non classé',
    description TEXT NULL,
    since_date DATE NULL,
    until_date DATE NULL COMMENT 'NULL = relation toujours active',
    source_note VARCHAR(255) NULL,
    source_url VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_type (company_id, relationship_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 3. `company_operational_metrics` — indicateurs opérationnels sectoriels

Même logique que `financial_statements`/`financial_statement_lines` (déjà
en place) : plutôt que des colonnes figées qui ne conviendraient qu'à un
secteur (nombre de clients bancaires ≠ tonnes de caoutchouc produites ≠
abonnés mobiles ≠ GWh distribués), un **modèle générique clé/valeur**, avec
un registre PHP (`class/OperationalMetricSchemas.php`, à créer sur le
modèle de `FinancialStatementSchemas.php`) qui définit les clés valables
par secteur, leur libellé et leur unité.

```sql
CREATE TABLE company_operational_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    metric_key VARCHAR(60) NOT NULL COMMENT 'Ex. nombre_clients, nombre_agences, production_tonnes, abonnes_mobiles, arpu, gwh_distribues, evp_manutentionnes',
    value DECIMAL(24,4) NOT NULL,
    unit VARCHAR(20) NOT NULL COMMENT 'nombre | tonnes | GWh | m3 | FCFA | % | EVP | ...',
    period_end_date DATE NOT NULL,
    period_type ENUM('mensuel','trimestriel','semestriel','annuel') NOT NULL,
    fiscal_year SMALLINT NOT NULL,
    source_type ENUM('rapport_entreprise','communique_entreprise','source_tierce','estimation') NOT NULL,
    source_name VARCHAR(150) NULL COMMENT 'Ex. BCEAO, ARTCI, Port Autonome d''Abidjan, Conseil du Café-Cacao',
    source_url VARCHAR(500) NULL,
    source_report_id BIGINT NULL COMMENT 'Vers company_reports si applicable',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_metric (company_id, metric_key, period_end_date, period_type),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_key (company_id, metric_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> C'est ici que va le **nombre de clients** d'une banque (`metric_key =
> 'nombre_clients'`), le **nombre d'agences**, la **production en
> tonnes** d'une agro-industrielle, les **abonnés mobiles** d'un
> opérateur télécoms, etc. — tout ce qui a été listé dans la section
> « Indicateurs de suivi clés par secteur » plus haut dans ce document.

## 4. `company_seasonality_calendar` — profil saisonnier mensuel structuré

Version chiffrée/structurée du « détail saisonnier » en texte libre déjà
rédigé plus haut — utile pour piloter des alertes ou un calendrier visuel
dans l'application (ex. surligner automatiquement "novembre : haute
saison" sur la fiche Sicable).

```sql
CREATE TABLE company_seasonality_calendar (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    month TINYINT NOT NULL COMMENT '1 à 12',
    activity_level ENUM('haute','normale','basse') NOT NULL,
    note VARCHAR(255) NULL COMMENT 'Ex. "Grande traite du palmier", "Campagne cacao/café"',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY uk_company_month (company_id, month),
    CONSTRAINT chk_month CHECK (month BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 5. `company_cyclicality_profile` — profil de cyclicité

Une ligne par entreprise (mise à jour en place plutôt qu'historisée : la
classification change rarement).

```sql
CREATE TABLE company_cyclicality_profile (
    company_id INT NOT NULL PRIMARY KEY,
    cyclicality_level ENUM('non_cyclique','modere','fort') NOT NULL,
    cycle_driver VARCHAR(100) NULL COMMENT 'Ex. cours_caoutchouc, cours_huile_palme, cycle_btp, cycle_petrole',
    commodity_reference VARCHAR(100) NULL COMMENT 'Ex. SICOM Singapour, Bursa Malaysia, ICE sucre, LME cuivre, Brent',
    notes TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 6. `company_analysis_notes` — analyses qualitatives structurées

Couvre tout ce qui, dans ce document, est un texte d'analyse plutôt qu'un
chiffre : perspective générale, facteurs de hausse/baisse, signaux
d'achat/vente, leviers et perspective de la politique de rémunération.
Un `note_type` plutôt que sept tables distinctes, dans la même logique
générique que les tables précédentes.

```sql
CREATE TABLE company_analysis_notes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    note_type ENUM(
        'perspective_generale', 'facteur_hausse', 'facteur_baisse',
        'signal_achat', 'signal_vente',
        'levier_remuneration', 'perspective_remuneration'
    ) NOT NULL,
    content TEXT NOT NULL,
    display_order TINYINT NOT NULL DEFAULT 0 COMMENT 'Ordre d''affichage entre notes de même type',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Désactiver plutôt que supprimer une note obsolète (même logique que financial_statements.is_active)',
    created_by_admin_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_admin_user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_company_type (company_id, note_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 7. `company_esg_records` — durabilité, conformité, gouvernance

```sql
CREATE TABLE company_esg_records (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    record_type ENUM(
        'certification', 'incident_securite', 'litige',
        'conformite_reglementaire', 'autre'
    ) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    status VARCHAR(50) NULL COMMENT 'Ex. actif, résolu, en cours, expiré',
    event_date DATE NULL,
    source_url VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_type (company_id, record_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 8. `company_governance_events` — calendrier d'entreprise

Couvre les échéances passées ET à venir (AGO, détachement de dividende,
publications, échéances de dette, renouvellement de concession/licence).

```sql
CREATE TABLE company_governance_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    event_type ENUM(
        'ago', 'detachement_dividende', 'publication_semestrielle',
        'publication_annuelle', 'echeance_dette',
        'renouvellement_licence_concession', 'autre'
    ) NOT NULL,
    event_date DATE NOT NULL,
    is_estimated TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = date projetée par récurrence (ex. AGO "généralement en avril"), 0 = date confirmée par une source',
    description TEXT NULL,
    source_url VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_date (company_id, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 9. `company_market_liquidity_snapshots` — liquidité structurelle

Photographie périodique (mensuelle/trimestrielle) des indicateurs de
liquidité *structurels* — distincte de `order_book_snapshots` (carnet
d'ordres quotidien déjà en place) et de `intraday_execution_flow`.

```sql
CREATE TABLE company_market_liquidity_snapshots (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    snapshot_date DATE NOT NULL,
    free_float_percent DECIMAL(5,2) NULL,
    foreign_ownership_percent DECIMAL(5,2) NULL,
    avg_daily_volume_30d BIGINT NULL,
    trading_days_with_zero_volume_30d TINYINT NULL,
    is_suspended TINYINT(1) NOT NULL DEFAULT 0,
    suspension_reason VARCHAR(255) NULL COMMENT 'Ex. "Flottant sous le seuil réglementaire de 20 %" (cas Unilever CI)',
    source_note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY uk_company_date (company_id, snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Récapitulatif : où va chaque élément du document

| Élément documenté plus haut | Table |
|---|---|
| Domaine d'activité, produits, secteur | `companies` (existant) |
| Chiffre d'affaires, résultat net, PNB, bilan | `financial_statements` + `financial_statement_lines` (existant) |
| Montants de dividendes réellement versés | `market_bulletin_corporate_actions` (existant) |
| Actionnariat, % de capital, changements d'actionnaire | `company_shareholders` |
| Partenaires (licence, actionnaire technique, fournisseur) | `company_business_relationships` |
| Clients nommés ou par catégorie | `company_business_relationships` |
| Nombre de clients/agences, production, abonnés, volumes... | `company_operational_metrics` |
| Détail saisonnier (haute/basse saison par mois) | `company_seasonality_calendar` |
| Cyclicité et matière première/cycle suivi | `company_cyclicality_profile` |
| Perspective, facteurs de hausse/baisse, signaux achat/vente | `company_analysis_notes` |
| Leviers et perspective de la politique de rémunération | `company_analysis_notes` |
| Certifications, incidents, litiges, conformité (ESG) | `company_esg_records` |
| Calendrier (AGO, dividende, dette, licences) | `company_governance_events` |
| Flottant, part étrangère, suspension de cotation | `company_market_liquidity_snapshots` |
| Événements ponctuels (contrats, changements de direction) | `company_market_events` (existant) |

> 💡 Si tu veux, je peux transformer ces neuf `CREATE TABLE` en un vrai
> fichier de migration (`migrations/026_company_deep_analysis.sql`,
> suivant la numérotation en cours) prêt à exécuter via
> `scripts/migrate.php` — dis-le-moi et je le crée directement dans le
> projet plutôt que de le laisser seulement dans ce document.
