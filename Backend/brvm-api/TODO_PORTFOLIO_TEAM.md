# Mon Équipe BRVM — portefeuille en 4-3-3

Plan d'implémentation pour la métaphore football (4-3-3) proposée par
l'utilisateur : transformer un portefeuille BRVM en "équipe" avec un rôle
par titre (Gardien/Défense/Milieu/Attaque), une note par joueur, une note
par ligne, et des alertes d'équilibre en langage clair.

## ✅ FAIT le 11/08/2026 (étapes 1 à 5 du plan — le cœur utilisable de bout en bout)

Ce qui a été livré, et les décisions prises en implémentant :

- **`class/CompositeScoreCalculator.php`** (nouveau) — le moteur de score
  0-100 extrait d'`api_composite_score.php` en classe statique partagée
  (WEIGHTS, clamp, fundamentalSubScore, liquiditySubScore, buildSignal,
  computeSubScores, weightedScore). Exception assumée à la convention de
  duplication du projet : ~300 lignes de formule substantielle devant
  rester identiques entre 2 endpoints, contrairement aux petites requêtes
  SQL (liquidité/fondamentaux/benchmark) qui restent dupliquées par
  fichier comme d'habitude. `api_composite_score.php` refactoré pour
  l'utiliser, comportement vérifié inchangé sur données réelles.
- **`AuthGuard::getCurrentUserId()`** (nouveau) — première brique
  multi-tenant du projet ; duplique la petite extraction de token
  d'extractToken() (privée) plutôt que d'en changer la visibilité, pour ne
  pas toucher au comportement de requireAuth().
- **Migration 017** — les 3 tables du plan, avec un écart volontaire :
  UNIQUE KEY sur portfolio_thesis.holding_id (une seule thèse par
  position, cible d'upsert 1:1 — le brouillon du plan l'omettait).
- **`api_portfolio.php`** (nouveau) — actions `team`, `suggestions`,
  `add_holding`, `update_holding`, `remove_holding`, `set_cash_reserve`,
  `save_thesis`. Toutes scopées à getCurrentUserId(), avec vérification de
  propriété avant toute écriture sur une position (jamais agir sur l'id
  d'un autre compte). Décisions prises sur les points laissés ouverts :
  - Seuils de classification : fundamental ≥ 60 et momentum < 60 →
    Défense ; momentum ≥ 65 OU market ≥ 65 → Attaque ; sinon Milieu.
    `role_partial=true` quand les fondamentaux manquent.
  - Rang sectoriel dans `team` : calculé AU SEIN de l'équipe (la question
    est la diversification de MON équipe) ; dans `suggestions` : marché
    entier (la question est la qualité absolue du titre).
  - Alertes : concentration sectorielle > 40% (warning), Attaque >
    Défense+Milieu (warning), réserve < 10% du total avec cash (warning),
    dépendance dividendes > 50% de la valeur en titres à rendement ≥ 5%
    (info, pas warning — neutre en soi). Une alerte non déclenchée
    n'apparaît pas du tout.
  - Score d'équilibre : max(0, 100 − 20 × nb de warnings) — volontairement
    simple et directement explicable depuis la liste d'alertes visible.
  - Profils : prudent 50/30/20, équilibré 35/35/30, dynamique 20/30/50 ;
    suggestions avec plafond de 2 titres par secteur par rôle (liste
    incomplète plutôt que diversification diluée) ; budget réparti
    également entre les candidats retenus d'un rôle.
  - Un override manuel 'gardien' sur une position la sort des 3 lignes de
    jeu mais la compte dans la valeur totale.
- **Frontend** — `pages/PortfolioTeam.tsx` (`/my-team`, nav "Mon Équipe
  BRVM") : parcours de construction pour l'état vide (profil → budget →
  suggestions par rôle → ajout en un clic), visualisation par lignes avec
  badges score/statut/partielle, gardien éditable, alertes en bannières
  (ambre = warning, bleu = info, jamais rouge), StatTiles
  (valeur/valeur+cash/équilibre/nb titres), édition inline par position
  (statut simulé↔acheté, rôle manuel, carnet du coach avec thèse + critère
  de sortie).

**Testé sur données réelles** (cycle complet via réflexion PHP, session de
test créée puis supprimée) : équipe vide → réserve → suggestions (profil
équilibré, budget 500k : diversification 2/secteur respectée, montants
répartis correctement) → 2 ajouts (ABJC classé Défense avec fundamental
67.4/momentum 57.8 ; SICC classé Attaque avec momentum 94.9, partielle car
sans fondamentaux) → team (lignes et valeurs justes ; alertes
concentration 75% Agriculture et portefeuille trop offensif déclenchées ;
weak_reserve correctement NON déclenchée à 11,1% ≥ 10% ; équilibre 60 =
100−2×20) → override de rôle → thèse aller-retour → nettoyage complet
vérifié (0 ligne restante dans les 3 tables).

**Bug post-livraison trouvé et corrigé (11/08/2026)** : le frontend
affichait « Réponse invalide du serveur » sur /my-team — cause : l'Apache
de MAMP exécute PHP **7.4.33** alors que les tests CLI utilisaient le
binaire 8.2, et `CompositeScoreCalculator.php` (+ les méthodes résiduelles
d'`api_composite_score.php`, revenu à sa version pré-refactor après un
reset git) contenaient des `match` (PHP 8.0+) → erreur de parse HTML au
lieu du JSON. Corrigé (remplacement des `match` par des tableaux de
correspondance, refactor ré-appliqué), validé par de vrais appels HTTP
authentifiés sous Apache 7.4 (team, suggestions, compute — tous OK). Au
passage, l'onglet « Score composite » du Screener était cassé en HTTP par
le même bug depuis sa création. Règle durable : linter aussi avec
`/Applications/MAMP/bin/php/php7.4.33/bin/php -l` et valider par un curl
réel tout nouveau fichier servi par le web.

**Extension livrée le 11/08/2026 (demande utilisateur)** : en plus de la
composition manuelle, l'analyse peut **proposer une équipe complète** —
nouvelles actions `propose_team` (XI en 4-3-3 : 4 Défense / 3 Milieu / 3
Attaque + gardien conseillé à 10% du budget, plafond de diversification de
2 titres par secteur appliqué à L'ÉQUIPE ENTIÈRE et pas seulement par
ligne, entreprises déjà détenues exclues d'office, justification
déterministe par joueur construite depuis ses 2 sous-scores les plus forts
— jamais un texte IA) et `adopt_team` (adoption en bloc : tout en mode
simulé + pose de la réserve, doublons ignorés au lieu de faire échouer le
lot). `buildScoredPools()` factorisé entre `suggestions` et
`propose_team`. Frontend : bouton « Me proposer une équipe complète » dans
le parcours de construction (état vide) et carte « Me proposer des
renforts » sur une équipe existante — cases à cocher par joueur avant
adoption (la proposition n'est jamais imposée, l'utilisateur reste le
coach). Testé en HTTP réel (Apache PHP 7.4) : profil dynamique, budget
1 000 000 → réserve 100 000 + 900 000 répartis 20/30/50, 4-3-3 complet
sans violation de diversification, adoption 10/10, et l'équipe adoptée ne
déclenche aucune de ses propres alertes (équilibre 100/100). Ligne
incomplète signalée honnêtement dans `notes` plutôt que comblée par un
titre forcé.

**Complément du 11/08/2026 — la proposition prend davantage en compte
l'analyse des rapports** (question utilisateur : « il devrait aussi
prendre en compte l'analyse des rapports ? »). État des lieux : le
sous-score Fondamental (30%, le poids le plus fort) venait DÉJÀ des
analyses IA de rapports (PER/dividende/ROE/croissance/marge extraits de
company_report_analyses) — mais le verdict de valorisation n'était pas
exploité, et un titre sans rapport n'était jamais départagé face à un
titre documenté. Ajouts dans `propose_team` uniquement (le
composite_score affiché reste identique partout) :
1. **Bonus de tri par verdict** : sous-coté +5 / surcoté −5 sur le score
   de classement. Sans effet aujourd'hui (vérifié en base : 71
   « indéterminable », 4 « correctement valorisé », 0 tranché) — s'active
   automatiquement à mesure que les analyses s'accumulent.
2. **Départage par couverture** : à score ajusté égal, le titre adossé à
   un rapport analysé (couverture plus élevée) gagne face à un titre noté
   sur les seules données de marché.
3. **Justifications enrichies** : chaque joueur indique désormais
   explicitement « Adossé à l'analyse du rapport financier du JJ-MM-AAAA
   (jugé sous-coté) » ou « Aucun rapport financier traité » — la place de
   l'analyse de rapport dans chaque choix est visible, pas implicite.
Testé en HTTP réel : les 4 défenseurs proposés sont tous adossés à un
rapport (couverture 75%), mentions correctes partout.

**Étape 7 livrée le 11/08/2026 — sous une forme plus riche que prévue**
(demande utilisateur : « analyser l'équipe avec l'IA, avoir son avis et
ses propositions, et pouvoir valider ses propositions ») : plutôt que le
`chart_type` ChartAiAnalysis initialement envisagé (commentaire en texte
libre seulement), nouvelle action `ai_review` dans `api_portfolio.php` —
l'IA reçoit l'équipe complète (lignes, alertes, scores, thèses du coach)
PLUS un menu fermé de candidats (top 5 par rôle, hors titres détenus) et
renvoie un avis structuré (avis global, forces, faiblesses) et 0-5
**propositions actionnables** (`ajouter`/`retirer`/`ajuster_montant`/
`ajuster_reserve`), chacune justifiée. Garde-fous fidèles à la
philosophie du projet :
- l'IA ne peut proposer d'ajouter QUE des titres du menu fourni, et de
  retirer/ajuster QUE des positions réellement détenues — toute
  proposition hors clous est écartée côté serveur
  (`validateAiProposals()`, `dropped_proposals_count` renvoyé) ;
- si une position a une thèse du coach, le prompt exige que la raison d'un
  retrait y réponde explicitement ;
- rien n'est appliqué automatiquement : le frontend (`AiReviewPanel` dans
  PortfolioTeam.tsx) affiche les propositions cochables, et « Appliquer la
  sélection » les exécute séquentiellement via les actions CRUD existantes
  (déjà scopées/validées) — pas d'endpoint d'application dédié ;
- pas de cache : la composition change à chaque application, un avis
  mémorisé serait périmé dès la première validation.
Testé en HTTP réel (Gemini) sur une équipe volontairement déséquilibrée
(3 attaquants même secteur + 1 défenseur, réserve 3%) : diagnostic exact
(concentration 56%, milieu vide, réserve critique) et 3 propositions
valides (ajuster réserve à 35 000, ajouter CIEC au milieu, ajouter BICC
en défense), 0 écartée. Piège récurrent retrouvé : oubli du require
`AiChatClientInterface.php` avant `GeminiClient.php` → fatal silencieux
(réponse vide) côté Apache, visible uniquement dans
/Applications/MAMP/logs/php_error.log.

**Explications détaillées + remplacements dans la proposition (11/08/2026,
demande utilisateur)** : chaque joueur du XI proposé a désormais un détail
« Pourquoi ? » dépliable — la règle de classement RÉELLEMENT déclenchée
avec les vrais chiffres du titre (`role_rule`, ex: « Classé en Défense :
fondamental 67 ≥ 60 et momentum modéré (58 < 60) »), les 6 sous-scores, la
couverture, la mention rapport analysé/non, et l'explication du montant
(part de ligne ÷ nb de joueurs) — chaque mot vérifiable contre un chiffre
affiché. Et un **banc de remplaçants** par rôle (`bench`, jusqu'à 4
candidats non retenus dans le XI, renvoyés par propose_team) : bouton
« Remplacer » par joueur → le remplaçant hérite du montant du sortant
(même rôle, même part de budget), le sortant retourne en tête du banc
(remplacement inverse possible), et un garde-fou frontend bloque tout
remplacement qui dépasserait 2 titres du même secteur dans l'équipe (même
règle que le backend, en tenant compte de la place libérée par le
sortant). Un banc vide est affiché honnêtement (« les candidats de ce rôle
sont déjà tous dans le XI ») — constaté en test réel sur la Défense, où
seuls 4 profils défensifs existent sur toute la cote actuelle.

**Propositions d'équipe par l'IA + historique noté (11/08/2026, demande
utilisateur)** : migration 020 (`portfolio_team_proposals`, scopée
admin_user_id) — TOUTE proposition d'équipe est désormais historisée,
qu'elle vienne de l'algorithme déterministe (`propose_team`, origin
'algorithme') ou de la nouvelle action **`propose_team_ai`** (origin
'ia') : l'IA compose elle-même le XI en 4-3-3 parmi un menu fermé (top 8
par rôle avec sous-scores/verdicts/role_auto), avec un raisonnement par
joueur et un commentaire global. Garde-fous serveur : uniquement des
titres du menu, pas de doublon, plafond 2/secteur — les choix hors clous
sont écartés et comptés dans les notes (constaté en test réel : 1 choix
IA écarté, ligne Milieu honnêtement rendue 2/3). L'IA peut placer un
titre dans une AUTRE ligne que son rôle automatique (sa valeur ajoutée),
mais l'écart est alors signalé dans la raison affichée. Montants
manquants comblés par la répartition égale du profil (même règle que
l'algorithme). Actions d'historique : `list_team_proposals`
(résumés avec origine/profil/budget/nb joueurs/note/extrait de
commentaire), `get_team_proposal` (ré-ouvre la proposition complète, banc
compris — re-adoptable, adopt_team ignorant les titres déjà détenus),
`rate_team_proposal` (étoiles 1-5, composant StarRating existant) et
`delete_team_proposal`. Frontend : bouton « 🤖 Laisser l'IA composer
l'équipe » (avec choix du fournisseur) à côté du bouton algorithmique dans
les deux états de la page, bloc « Lecture d'ensemble de l'IA » dans la
proposition affichée, et carte « Historique des propositions d'équipe »
en bas de page (consultation/étoiles/suppression). Testé en HTTP réel
(Gemini) : équipe IA cohérente avec raisons chiffrées par joueur,
répartition ~35/35/30 respectée par l'IA elle-même, notation 4★ et
suppression vérifiées, base nettoyée.

**Historisation des avis (11/08/2026, demande utilisateur)** : migration
018 (`portfolio_ai_reviews`, scopée admin_user_id comme les autres tables
portfolio_*) — chaque avis est persisté automatiquement AVEC le
`team_snapshot` complet (positions, lignes, alertes, score d'équilibre au
moment de l'avis) : un avis relu plus tard n'est interprétable qu'avec ce
contexte, l'équipe ayant pu changer depuis — y compris via l'application
des propositions de cet avis lui-même. Nouvelles actions `list_reviews`
(résumés : date, fournisseur, nb propositions, équilibre et nb de titres
de l'époque, extrait d'avis), `get_review` (avis complet + snapshot,
ownership-checked) et `delete_review`. Frontend : section « Historique des
avis » dans AiReviewPanel — consultation dépliable avec rappel de la
composition de l'époque, propositions en LECTURE SEULE (jamais
ré-applicables : elles portaient sur une composition révolue — seul
l'avis fraîchement généré est actionnable), suppression avec
confirmation. Testé en HTTP réel : avis persisté (id/created_at
renvoyés), liste correcte (équilibre 100, 3 titres à l'époque, extrait),
détail avec snapshot fidèle (82 125 FCFA, 3 titres), suppression
vérifiée, base nettoyée.

---

Le plan d'origine ci-dessous est conservé pour référence.

**⚠️ Précision utilisateur (10/08/2026), importante pour la conception** :
les utilisateurs visés sont **nouveaux et ne possèdent encore aucune
action** — c'est le tout début de leur envie d'investir. Ce n'est donc PAS
d'abord un outil de **suivi** d'un portefeuille réel déjà constitué, c'est
d'abord un outil de **construction/simulation** du premier portefeuille :
aider quelqu'un qui part de zéro à composer une équipe équilibrée avant
d'avoir acheté quoi que ce soit, avec la possibilité de faire évoluer une
position simulée vers une position réelle une fois l'achat effectué. Voir
"Mode simulé vs réel" ci-dessous — ça change le modèle de données et le
parcours utilisateur par rapport à un simple tracker de positions.

## ⚠️ Nécessite de nouvelles tables

Contrairement à la plupart des points de `TODO_ANALYSES.md`, ceci est un
**changement de nature** de l'application, pas une nouvelle vue sur des
données déjà en base : jusqu'ici, BRVM Insights n'a jamais stocké de
notion de portefeuille personnel (que des données de marché publiques).
Voir section "Modèle de données" ci-dessous pour le détail des 3
nouvelles tables.

## Ce qui existe déjà et se réutilise directement (bonne nouvelle)

La partie la plus dure du concept — "noter chaque titre sur plusieurs
dimensions" — est **déjà construite** : `api_composite_score.php` (voir
TODO_ANALYSES.md, point 26) calcule déjà, par entreprise, exactement les
briques dont ce concept a besoin :

| Sous-score déjà calculé | Réutilisation ici |
|---|---|
| `fundamental` (PER, dividende, ROE, croissance CA, marge) | Note "Défense" (stabilité/rendement) et une partie de "Milieu" |
| `technical` (RSI/MACD/tendance/Bollinger) | Dynamique court terme, utile pour Attaque |
| `momentum` (performance période) | Note "Attaque" (recherche de croissance) |
| `liquidity` | Filtre de risque transversal (un titre illiquide reste un titre illiquide quel que soit son rôle) |
| `sector` (rang sectoriel) | Diversification sectorielle (alerte "forte concentration bancaire") |
| `market` (surperformance vs BRVM-COMPOSITE) | Nuance Attaque : "monte" vs "monte plus vite que le marché" |

Concrètement : **la classification Gardien/Défense/Milieu/Attaque n'est
pas une nouvelle IA à construire de zéro** — c'est une règle de décision
appliquée aux sous-scores déjà renvoyés par `api_composite_score.php`
(voir "Logique de classification" ci-dessous). Aucun nouveau calcul de
marché n'est nécessaire, seulement une couche de lecture par-dessus
l'existant + le stockage des positions réelles de l'utilisateur.

## Modèle de données (3 nouvelles tables)

```sql
-- Une ligne par titre "en équipe" (v1 : un seul portefeuille par
-- admin_user, pas de portefeuilles multiples nommés — sur-ingénierie non
-- justifiée tant qu'un seul compte admin existe réellement).
--
-- status distingue une position SIMULÉE (l'utilisateur envisage ce titre,
-- n'a rien acheté) d'une position RÉELLE (achat effectivement réalisé) —
-- voir "Mode simulé vs réel" plus bas. quantity/average_purchase_price/
-- purchase_date restent NULL tant que status='simule' ; target_amount_fcfa
-- sert au dimensionnement en mode simulé ("je compte mettre X FCFA sur ce
-- titre"), sans confondre une intention avec un achat réel.
CREATE TABLE portfolio_holdings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NOT NULL,
    company_id INT NOT NULL,
    status ENUM('simule','achete') NOT NULL DEFAULT 'simule',
    target_amount_fcfa DECIMAL(15,2) NULL COMMENT 'Montant envisagé (mode simulé) — indicatif, pas un ordre',
    quantity DECIMAL(15,4) NULL COMMENT 'Renseigné uniquement une fois status=achete',
    average_purchase_price DECIMAL(15,2) NULL COMMENT 'Renseigné uniquement une fois status=achete',
    purchase_date DATE NULL,
    -- Rôle : 'auto' = calculé depuis les sous-scores à chaque affichage
    -- (par défaut) ; sinon override manuel si l'utilisateur n'est pas
    -- d'accord avec le classement automatique (ex: il connaît une raison
    -- non captée par les chiffres).
    role_override ENUM('gardien','defense','milieu','attaque') NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id),
    UNIQUE KEY uk_user_company (admin_user_id, company_id),
    INDEX idx_admin_user (admin_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Le "gardien" : réserve de liquidité, explicitement séparée des actions
-- (voir remarque de l'utilisateur : le fonds d'urgence n'est pas une
-- position sur le terrain, c'est le dispositif de sécurité autour).
CREATE TABLE portfolio_cash_reserve (
    admin_user_id INT PRIMARY KEY,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'FCFA',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Le "carnet du coach" : thèse d'investissement + critère de sortie par
-- position — la partie la plus originale du concept (voir section
-- utilisateur "le coach doit avoir un plan de jeu écrit avant le match").
-- Volontairement en texte libre, jamais généré par l'IA à la place de
-- l'utilisateur : c'est SA thèse, pas une prédiction du système.
CREATE TABLE portfolio_thesis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holding_id INT NOT NULL,
    buy_reason TEXT NULL COMMENT 'Pourquoi ce titre a été retenu (valable dès le mode simulé, pas seulement après achat)',
    exit_criteria TEXT NULL COMMENT 'Ce qui ferait changer d''avis',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (holding_id) REFERENCES portfolio_holdings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Mode simulé vs réel

Deux usages coexistent dans la même table (`portfolio_holdings.status`),
pour accompagner l'utilisateur de "je découvre" à "j'ai investi" sans
rupture d'outil :

- **Simulé** (`status='simule'`) — le mode par défaut et le point d'entrée
  pour un nouvel utilisateur. Aucune quantité ni prix réels : juste "je
  retiens ce titre pour tel rôle, avec éventuellement un montant que
  j'envisage d'y mettre" (`target_amount_fcfa`, indicatif). Permet de
  composer et rééquilibrer une équipe complète sans aucun engagement réel
  — l'idée étant justement de pouvoir expérimenter (ajouter/retirer un
  titre, changer un attaquant contre un autre) avant de sortir l'argent.
- **Réel** (`status='achete'`) — une fois l'achat réellement effectué,
  l'utilisateur bascule la position en renseignant `quantity`/
  `average_purchase_price`/`purchase_date`. Le reste (rôle, score, alertes
  d'équilibre) continue de fonctionner à l'identique — juste pondéré par
  la valeur réelle investie plutôt que par le montant envisagé.
- Un portefeuille peut mélanger les deux statuts (ex: 3 titres déjà
  achetés + 2 encore à l'étude) — les scores de ligne et alertes
  d'équilibre doivent alors clairement indiquer sur quelle base (réelle,
  simulée, ou combinée) ils sont calculés, pour ne jamais laisser croire
  qu'une intention est déjà un fait.

## Point de départ pour un utilisateur qui ne possède encore rien

Puisque la cible est un nouvel investisseur sans aucune position de
départ, l'écran ne doit pas se limiter à un formulaire vide ("ajoute tes
titres") — il doit activement aider à construire la première équipe :

- **Suggestions par rôle** : à partir de `api_composite_score.php` calculé
  sur TOUTES les entreprises actives (pas seulement celles déjà
  sélectionnées par l'utilisateur), proposer les meilleurs candidats par
  rôle selon la même logique de classification que ci-dessous (ex: top 5-8
  "Défense" par `fundamental`, top 5-8 "Attaque" par `momentum`/`market`),
  avec diversification sectorielle déjà prise en compte dans la
  suggestion (éviter de proposer 4 défenseurs tous du secteur bancaire).
- **Budget total → répartition par ligne** : l'utilisateur indique un
  montant total qu'il envisage d'investir ; l'outil propose une
  répartition indicative par ligne selon un profil (ex: profil prudent =
  plus de Défense, profil dynamique = plus d'Attaque — rejoint le point
  de l'utilisateur "un jeune joueur peut se permettre plus d'attaque").
  Simple règle de répartition au départ (ex: 3 profils prédéfinis
  Prudent/Équilibré/Dynamique avec des poids de ligne différents), pas un
  optimiseur financier.
- Toutes les suggestions restent des **suggestions modifiables**, jamais
  un portefeuille pré-rempli imposé — l'utilisateur reste le "coach" qui
  décide, cohérent avec le reste du concept.

## Logique de classification (Gardien exclu, 4 rôles pour les titres)

Règle de décision déterministe (pas d'IA nécessaire pour cette partie),
appliquée aux `sub_scores` déjà renvoyés par `api_composite_score.php`
pour chaque titre détenu :

- **Défense** : `fundamental` élevé (stabilité/rendement) ET `technical`/
  `momentum` modérés (pas de recherche de mouvement) — typiquement
  `fundamental >= 60` et `momentum < 60`.
- **Attaque** : `momentum` et/ou `market` élevés (dynamique de cours,
  surperformance récente) — typiquement `momentum >= 65` ou `market >= 65`.
- **Milieu** : le reste — profil équilibré, ni franchement défensif ni
  franchement offensif.
- Cas `fundamentals_available = false` (pas de rapport financier traité,
  voir TODO_ANALYSES.md point 24) : classification basée uniquement sur
  `technical`/`momentum`/`market` disponibles, avec un badge "classification
  partielle" plutôt qu'un rôle affiché avec une fausse confiance.
- `role_override` (colonne ci-dessus) prime toujours sur le calcul
  automatique si renseigné.

Seuils volontairement simples et documentés dans le code (même philosophie
que les barèmes de `api_composite_score.php`) — ajustables si l'usage
réel montre qu'ils classent mal certains profils.

## Scores par ligne + moteur d'alertes d'équilibre

Une fois chaque titre classé :
- **Score de ligne** = moyenne pondérée des `composite_score` des titres
  de cette ligne, par `quantity × dernier cours connu` si `status=achete`,
  ou par `target_amount_fcfa` si `status=simule` (une grosse position/
  intention pèse plus qu'une petite dans la note de la ligne, comme un
  titulaire pèse plus qu'un remplaçant) — le calcul est donc identique
  dans les deux modes, seule la source du poids change.
- **Score d'équilibre** = à définir précisément à l'implémentation (piste :
  pénalise un écart-type élevé entre les 3 lignes, et/ou un déséquilibre
  du nombre de titres par ligne par rapport à un 4-3-3 cible).
- **Alertes textuelles** (règles déterministes, pas de génération libre) :
  - Concentration sectorielle : si un secteur dépasse un seuil (ex: 40%)
    de la valeur totale du portefeuille (hors gardien) → "Forte
    concentration {secteur}".
  - Déséquilibre de lignes : si Attaque a plus de titres/valeur que
    Défense+Milieu combinés → "Portefeuille trop offensif".
  - Gardien sous-dimensionné : si `portfolio_cash_reserve.amount` /
    valeur totale du portefeuille est sous un seuil (ex: 5-10%, à
    discuter avec l'utilisateur — pas de règle universelle en finance
    personnelle) → "Réserve de sécurité faible".
  - Dépendance aux dividendes : si la part de titres à haut rendement
    dividende dépasse un seuil → "Portefeuille fortement dépendant des
    dividendes" (neutre, pas négatif en soi, mais à signaler).

## Où l'IA intervient (et où elle n'intervient pas)

- **N'intervient PAS** dans le calcul des scores/rôles/alertes ci-dessus
  (déterministe, reproductible, expliqué chiffre par chiffre — cohérent
  avec toute l'app jusqu'ici).
  **N'écrit jamais** la thèse d'investissement ni le critère de sortie
  (`portfolio_thesis`) à la place de l'utilisateur — ce sont ses mots, pas
  une suggestion du système (l'utilisateur l'a explicitement noté : "le
  coach doit avoir un plan de jeu écrit avant le match").
- **Peut intervenir en option**, sur le même modèle que
  `ChartAiAnalysis`/`ChartAnalysisService` déjà en place : un nouveau
  `chart_type` `portfolio_team` qui prend en entrée la composition déjà
  calculée (lignes, scores, alertes) et produit un commentaire en langage
  naturel — mais TOUJOURS en reformulant des chiffres déjà calculés
  côté PHP, jamais en inventant un diagnostic. Optionnel, ajoutable en
  dernière phase sans bloquer le reste.

## Frontend — page "Mon Équipe BRVM"

- Nouvelle route `/my-team`, nouvelle page `pages/PortfolioTeam.tsx`.
- **Écran vide (0 position) → parcours de construction en avant, pas un
  formulaire vide** : profil Prudent/Équilibré/Dynamique (voir "Point de
  départ" ci-dessus) → suggestions de candidats par rôle → l'utilisateur
  pioche parmi les suggestions (ou ajoute un titre de son choix hors
  suggestion) pour composer son XI de départ, en mode simulé par défaut.
- Formulaire de gestion des positions (ajouter/modifier/supprimer une
  ligne `portfolio_holdings`, basculer simulé→acheté, montant du gardien).
- Visualisation "terrain" : 3 lignes (Défense/Milieu/Attaque) + le
  gardien à part, chaque titre affiché avec son score composite déjà
  connu (réutilise `CompositeScoreRow` existant), son rôle, et un badge
  simulé/acheté par position.
- Bloc alertes d'équilibre (texte simple, pas besoin d'IA pour l'affichage
  de base).
- Bloc "Carnet du coach" par position : thèse + critère de sortie,
  éditable en place, disponible dès le mode simulé.
- Bouton "Analyse IA du coach" (optionnel, voir section précédente) —
  réutilise le composant `ChartAiAnalysis` déjà existant.

## Ordre d'implémentation proposé (dynamique — utilisable après chaque étape)

1. **Migration** (3 tables ci-dessus) + `api_portfolio.php` (actions
   `list_holdings`/`add_holding`/`update_holding`/`remove_holding`/
   `set_cash_reserve`) — CRUD pur, aucune logique de classification
   encore. `add_holding` doit accepter le mode simulé dès le départ
   (`status='simule'`, `target_amount_fcfa`) — pas ajouté en rustine plus
   tard, c'est le mode par défaut pour la cible visée.
2. **Classification + scores de ligne** — nouvelle action `team` dans
   `api_portfolio.php` (ou nouveau fichier `api_portfolio_team.php`,
   à trancher à l'implémentation selon la taille du résultat) qui
   appelle en interne la même logique que `api_composite_score.php`
   (dupliquée par convention établie dans ce projet, ou factorisée si
   la duplication devient trop lourde à ce stade — à réévaluer alors)
   pour les titres de l'équipe (simulés + achetés), applique les règles
   de classification ci-dessus, calcule les scores de ligne.
3. **Moteur d'alertes d'équilibre** — ajouté à la même action `team`,
   règles déterministes listées ci-dessus.
4. **Moteur de suggestions par rôle** — nouvelle action `suggestions`
   (top candidats par rôle sur `api_composite_score.php` calculé sur
   toute la cote active, avec diversification sectorielle) + les 3
   profils Prudent/Équilibré/Dynamique. C'est le point d'entrée pour un
   utilisateur à 0 position — à ne pas repousser trop tard dans l'ordre
   d'implémentation malgré sa position dans cette liste, sans lui la
   page reste un formulaire vide peu engageant pour la cible visée.
5. **Frontend "Mon Équipe BRVM"** — parcours de construction (profil →
   suggestions → composition) + formulaire de gestion + visualisation
   terrain + alertes. Utilisable de bout en bout à cette étape.
6. **Carnet du coach** — table `portfolio_thesis` + UI d'édition (peut
   être fusionné à l'étape 5 si le formulaire de position inclut déjà
   les 2 champs texte).
7. **Analyse IA optionnelle** — `chart_type` `portfolio_team` +
   intégration `ChartAiAnalysis`. Dernière étape, non bloquante pour le
   reste.

Chaque étape est un livrable autonome et utile — pas besoin d'attendre
l'étape 7 pour avoir un outil fonctionnel dès l'étape 1-5.

## Points à trancher avec l'utilisateur avant de coder

- Seuils exacts de classification (Défense/Milieu/Attaque) et
  d'alertes (concentration sectorielle, réserve de sécurité minimale,
  déséquilibre de lignes) — les valeurs ci-dessus sont des propositions
  de départ, pas des seuils validés.
- Définition précise des 3 profils Prudent/Équilibré/Dynamique (poids de
  ligne cibles pour chacun) et du nombre de candidats suggérés par rôle.
- Faut-il permettre plusieurs portefeuilles nommés par utilisateur (v1
  prévoit un seul, volontairement simple) ?
- Le "gardien" (réserve de cash) doit-il être exprimé en montant absolu
  ou en % cible du patrimoine total — et le patrimoine total (hors BRVM)
  est-il même modélisé quelque part, ou seulement le portefeuille BRVM ?
