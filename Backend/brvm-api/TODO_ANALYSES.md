# Prochaines analyses à implémenter

Liste des chantiers identifiés le 04/08/2026, à démarrer à la fermeture du
marché (pour ne pas toucher au code de synchro pendant que le cron tourne
en heures de marché). Classés par priorité (rapport effort/valeur).

Mise à jour du 04/08/2026 (revue angle analyste boursier / contrôleur
financier) : ajout de 6 chantiers identifiés en creusant les données
réelles en base (pas de la spéculation — voir constats chiffrés dans
chaque section).

## ✅ Implémenté le 04/08/2026

**Tout est fait sauf le point 12** (suivi des dividendes via les
bulletins), volontairement laissé de côté — voir sa section pour le
diagnostic de la cause racine trouvée en cours de route.

Points 1 à 4 faits avant la fermeture (lecture seule, zéro risque cron) :
`api_technical_indicators.php`/`api_signals.php` branchés sur `Quotes.tsx`
(SMA overlay, badge signal, tableau des signaux avec badge liquidité) ;
`api_quotes.php` (actions `liquidity` et `sector_performance`) ; nouveau
`api_data_quality.php` (réconciliation, sauts de prix, jours manquants) ;
nouvelle page `MarketHealth.tsx` (`/market-health`, performance par secteur
+ contrôle qualité).

Point 8 fait après la fermeture (seul point touchant le code du cron) :
accumulation de la variation totale dans `BRVMSyncService.php` +
`intraday_total_variation` (migration 008) + backfill + action
`total_variation` + nouvelle page `Statistics.tsx` (`/statistics`).

Points 0, 5, 6, 7, 9, 10, 11 faits dans la foulée : `market_cap` /
`shares_outstanding` / composition de l'indice BRVM-COMPOSITE peuplés
(`scripts/populate_market_cap.php`, scrapé depuis
`/fr/capitalisations/0`) ; largeur de marché et corrélation/performance
ajustée au risque/force relative ajoutées sur `MarketHealth.tsx` et
`Statistics.tsx` ; nouveau `api_price_alerts.php` (CRUD + vérification
manuelle, pas de dispatch email/webhook réel) avec sa page
`PriceAlerts.tsx` (`/price-alerts`). Le tableau de bord santé de la
synchro (point 5) s'est avéré déjà couvert par `Sync.tsx`, existant avant
cette session.

Détail conservé dans chaque section ci-dessous (marquée ✅) pour référence.

**Constat notable trouvé en testant la réconciliation** : de vrais écarts
entre `variation_percent` stocké et recalculé sur plusieurs titres le
04/08 (ex. UNLC : stocké +7,21% vs recalculé -0,26%) — à investiguer côté
`previous_close`/scraping avant de faire confiance aveuglément à ce champ
pour d'autres analyses. Visible directement sur `/market-health`.

## ✅ 0. Prérequis data — champs existants mais jamais peuplés

**Constat** : `companies.market_cap` et `companies.shares_outstanding` sont
**NULL sur les 47 entreprises**, et `index_composition` (composition des 4
indices BRVM-30/COMPOSITE/PRESTIGE/PRINCIPAL, avec poids) est **vide (0
ligne)** malgré `index_values` alimenté (8 lignes) — les indices eux-mêmes
sont synchronisés, mais on ne sait pas quelles entreprises les composent
ni avec quel poids.

Ça bloque plusieurs analyses ci-dessous (marquées "dépend du point 0") :
ratio de capitalisation, P/B, pondération par poids d'indice, force
relative/bêta vs indice. À traiter en amont : soit scraper la page de
composition des indices sur brvm.org (comme pour les cours), soit saisie
manuelle ponctuelle (la composition change rarement, contrairement aux
cours).

## Priorité haute

### ✅ 1. Signaux techniques (SMA/RSI/MACD/Bollinger)

**Aucun changement de base de données nécessaire.** Tout est déjà calculé
et persisté côté backend, juste jamais branché au frontend :

- `technical_indicators` (table) : sma_10/20/50/200, ema_10/20, rsi_14,
  macd_line/signal/histogram, bb_upper/middle/lower, atr_14 — une ligne par
  entreprise/jour.
- `class/TechnicalIndicatorsCalculator.php` : calcule et upsert ces
  indicateurs à chaque synchro (appelé depuis `cron_sync_brvm.php`).
- `api_signals.php` : combine déjà les indicateurs en signaux
  achat/vente/neutre.
- `api_technical_indicators.php` : expose les indicateurs bruts.

**À faire (pur frontend)** :
- Superposer SMA10/20/50 sur le graphe "Cours de clôture" existant
  (Quotes.tsx) — recharts, `<Line>` supplémentaires sur le même
  `LineChart`.
- Écran ou section affichant le signal courant par entreprise (via
  `api_signals.php`) — ex. tableau triable "Signal" à côté de la liste des
  entreprises, ou badge sur la page Quotes.
- Vérifier le format exact retourné par `api_signals.php` avant de coder le
  typing frontend (`types.ts`) — pas encore inspecté en détail.
- **⚠️ Croiser avec le score de liquidité (point 2)** : un RSI/MACD calculé
  sur un titre qui n'a pas tradé depuis 3 jours (prix figé) est trompeur —
  afficher un badge "illiquide" à côté du signal plutôt que de le présenter
  comme un signal fiable.

### ✅ 2. Score de liquidité par titre — NOUVEAU

**Constat chiffré (04/08/2026)** : plusieurs titres à **volume = 0** le
même jour (PRSC, SDSC, SIBC, SICC, UNXC sur la séance du 04/08), et des
volumes moyens historiques très faibles pour certains (SICC ~9,5,
UNLC ~19, BICC ~40 titres/jour). C'est une caractéristique connue des
marchés frontière comme la BRVM, mais aucune analyse actuelle ne la
signale — un cours qui ne bouge pas peut vouloir dire "stable" ou "pas
d'acheteur/vendeur", et c'est une nuance critique avant toute décision.

**Aucun changement de base de données nécessaire pour une v1** (calculable
sur `stock_quotes.volume` existant). Une v2 avec un vrai ratio de rotation
(volume / actions en circulation) dépend du point 0
(`shares_outstanding`).

**À faire** :
- Backend : nouvelle action — volume moyen sur N jours, % de jours à
  volume nul sur la période, éventuellement ratio de rotation si le
  point 0 est traité.
- Frontend : badge/colonne "liquidité" (ex. Élevée/Moyenne/Faible/Illiquide)
  sur les écrans de comparaison et de signaux — contexte à afficher à côté
  de n'importe quel graphe de prix pour ce genre de titre, pas un écran à
  part entière.

### ✅ 3. Contrôle qualité / réconciliation des données — NOUVEAU

**Motivation** : cette semaine seule, on a rencontré deux bugs de données
non détectés avant qu'un utilisateur ne les remarque (cron local sur le
mauvais port MySQL, clôture manquante un jour sur la prod). Un
contrôleur financier ne prendrait pas une variation à la lettre sans
vérifier sa cohérence interne.

**Aucun changement de base de données nécessaire** (requêtes de contrôle
sur les tables existantes).

**À faire** :
- Backend : requêtes de réconciliation — `variation_percent` stocké vs
  `(close_price - previous_close) / previous_close` recalculé (écart >
  tolérance = suspect) ; sauts de prix isolés entre deux relevés
  intrajournaliers consécutifs au-delà d'un seuil (ex. >20% en 10 min, peu
  crédible sur la BRVM) ; jours ouvrés sans aucune ligne `stock_quotes`
  pour une entreprise auparavant active.
- Frontend : section dédiée (peut être combinée avec le point 6 —
  tableau de bord santé de la synchro) listant les anomalies détectées,
  pas forcément une page séparée.

### ✅ 4. Performance moyenne par secteur

**Aucun changement de base de données nécessaire.** `companies.sector_id`
est peuplé (migration 007) et `stock_quotes.variation_percent` existe déjà.

**À faire** :
- Backend : nouvelle action dans `api_quotes.php` (ou nouveau fichier
  `api_sector_performance.php`) — `GROUP BY sector_id`, moyenne de
  `variation_percent` sur une période (pondération par `market_cap`
  possible une fois le point 0 traité, sinon moyenne simple pour commencer).
- Frontend : nouvel écran ou section — barres ou courbes, un secteur par
  couleur, réutilise `groupCompaniesBySector`/`colorForCompany` de
  `companyGroups.ts`.

## Priorité moyenne

### ✅ 5. Tableau de bord santé de la synchro

**Aucun changement de base de données nécessaire.** `sync_logs` existe déjà
et est alimenté à chaque synchro.

**À faire** :
- Backend : nouvelle action listant les synchros récentes (`sync_logs`),
  + détection des jours sans clôture (`stock_quotes`) sur les N derniers
  jours ouvrés.
- Frontend : nouvel écran "Synchronisation" (ou section sur une page
  existante) — dernière synchro réussie/échouée, liste des jours avec
  anomalie. Peut regrouper le point 3 (contrôle qualité) sur le même écran.

### ✅ 6. Largeur de marché historique (advance/decline) — NOUVEAU

**Constat** : `market-movers.php` calcule déjà hausses/baisses/inchangées,
mais seulement pour un jour à la fois — pas de vue dans le temps. La
"largeur de marché" (part de titres en hausse un jour donné) est un
indicateur classique de santé du marché : un indice qui monte porté par 3
titres seulement est un signal différent d'une hausse partagée par 30
titres.

**Aucun changement de base de données nécessaire** (agrégation de
`stock_quotes.variation_percent` déjà existant, jour par jour).

**À faire** :
- Backend : nouvelle action — par jour sur une période, compter
  hausses/baisses/inchangées (réutilise la logique déjà écrite dans
  `market-movers.php`, à extraire en fonction réutilisable).
- Frontend : graphe en aire ou barres empilées, % hausses vs baisses par
  jour sur la période sélectionnée.

### ✅ 7. Corrélation entre titres

**Aucun changement de base de données nécessaire** (calcul à la volée sur
`stock_quotes.variation_percent` existant ; une table de cache pourrait
être ajoutée plus tard si le calcul s'avère trop coûteux à chaque
affichage, mais pas nécessaire pour une première version).

**À faire** :
- Backend : nouvelle action calculant la corrélation de Pearson entre les
  séries de `variation_percent` de plusieurs entreprises sur une période.
- Frontend : matrice de corrélation (heatmap) ou liste "titres qui bougent
  ensemble" pour un titre donné — utile pour la diversification de
  portefeuille.

### ✅ 8. Variation totale (volatilité intrajournalière) — FAIT le 04/08/2026 après fermeture

Implémenté tel que spécifié ci-dessous : migration 008, accumulation en
continu dans `BRVMSyncService::accumulateTotalVariation()`, backfill
(`scripts/backfill_total_variation.php`, 2303 relevés rejoués → 94 lignes
entreprise/jour), action `total_variation` dans `api_quotes.php`, nouvelle
page `Statistics.tsx` (`/statistics`) avec le sélecteur 1/2/3 courbes.

Décision prise sur le point resté en suspens (premier relevé du jour) :
**démarre à 0** (option recommandée) — l'écart nocturne vs clôture de la
veille n'est pas compté dans la variation totale. Logique validée par un
test direct (exemple SAFC du TODO : delta % exacts, pas les écarts de prix
bruts utilisés dans l'exemple illustratif) et croisée avec les sauts de
prix déjà détectés par `api_data_quality.php` (UNLC +7,15% retrouvé à
l'identique dans `total_gain_percent`).

**Nouvelle table nécessaire.** Spec détaillée ci-dessous (déjà discutée en
détail avec l'utilisateur, prête à implémenter).

#### Définition de la métrique

Pour une entreprise donnée, sur une journée donnée : on prend la séquence
des relevés `intraday_quotes` (un toutes les ~10 min pendant la séance), et
pour chaque paire de relevés consécutifs on calcule la variation en % :
`(price[i] - price[i-1]) / price[i-1] * 100`. On additionne la **valeur
absolue** de chacune de ces variations sur toute la journée.

Exemple SAFC : `100 → 105 → 102 → 108 → 104` donne une variation nette de
clôture `+4%` (ce qu'on affiche déjà partout), mais une variation totale de
`|+5| + |-3| + |+6| + |-4| = 18%` (mesure de "churn"/volatilité — combien
le titre a bougé dans la journée, pas juste où il a fini).

On stocke trois nombres par entreprise/jour : somme des hausses
(`total_gain_percent`), somme des baisses en valeur absolue
(`total_loss_percent`), et leur somme (`total_variation_percent` =
gain + loss). Ça permet d'afficher au choix 1, 2 ou 3 courbes côté
frontend (décision utilisateur : affichage flexible, pas figé sur un seul
mode — voir "Frontend" plus bas).

#### ⚠️ Point encore à trancher : premier relevé de la journée

Le tout premier relevé du jour (~08:30) n'a pas de relevé précédent CE
jour pour calculer un delta. Deux options :

- **Démarre à 0 (recommandé, pas encore confirmé par l'utilisateur)** :
  seuls les mouvements observés *pendant* la journée comptent — l'écart
  d'ouverture par rapport à la clôture de la veille (déjà visible ailleurs
  via `stock_quotes.variation_percent`/`intraday_quotes.variation_percent`)
  n'est pas inclus dans le total.
- **Compare à la clôture de la veille** : le premier delta du jour capture
  aussi le "gap" d'ouverture nocturne dans le total accumulé.

**À trancher avec l'utilisateur avant de coder l'accumulation** — la
réponse change le sens de la métrique.

#### Architecture proposée

1. **Migration** `migrations/008_intraday_total_variation.sql` :
   ```sql
   CREATE TABLE intraday_total_variation (
       id INT AUTO_INCREMENT PRIMARY KEY,
       company_id INT NOT NULL,
       trading_date DATE NOT NULL,
       total_gain_percent DECIMAL(10,4) NOT NULL DEFAULT 0,
       total_loss_percent DECIMAL(10,4) NOT NULL DEFAULT 0,
       total_variation_percent DECIMAL(10,4) NOT NULL DEFAULT 0,
       snapshots_count INT NOT NULL DEFAULT 0,
       last_price DECIMAL(15,2) NULL,
       last_quote_datetime DATETIME NULL,
       UNIQUE KEY uniq_company_day (company_id, trading_date),
       CONSTRAINT fk_itv_company FOREIGN KEY (company_id) REFERENCES companies(id)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
   ```

2. **`class/BRVMSyncService.php`** — dans `recordIntradaySnapshot()` (déjà
   appelée à chaque synchro ~10 min), après l'insertion du nouveau relevé
   `intraday_quotes` :
   - Chercher la ligne `intraday_total_variation` pour
     `(company_id, today)`.
   - Si elle existe : `delta = (newPrice - last_price) / last_price * 100`
     ; si `delta > 0` ajouter à `total_gain_percent`, sinon ajouter
     `abs(delta)` à `total_loss_percent` ; mettre à jour `last_price`,
     `total_variation_percent`, `snapshots_count += 1`.
   - Sinon (premier relevé du jour pour cette entreprise) : créer la ligne
     avec les totaux à 0 (ou calculer le premier delta vs
     `stock_quotes.previous_close` — voir point à trancher ci-dessus),
     `last_price = newPrice`, `snapshots_count = 1`.
   - Coût : une upsert de plus par entreprise par synchro (~47 lignes /
     10 min), négligeable.

3. **`scripts/backfill_total_variation.php`** (nouveau script CLI) —
   reconstitue l'historique déjà présent dans `intraday_quotes` (03/08,
   04/08...) en rejouant chaque jour/entreprise dans l'ordre chronologique
   avec la même logique d'accumulation. Idempotent (TRUNCATE + rebuild, ou
   upsert).

4. **`api_quotes.php`** — nouvelle action (ex. `total_variation`),
   même forme que `compare` (`company_ids[]`, `start_date`, `end_date`) →
   retourne par entreprise une série
   `{date, total_gain_percent, total_loss_percent, total_variation_percent}`.

5. **Frontend** — nouvelle page **Statistiques** (nouvelle route + entrée
   de nav), même structure que la section "Comparaison entre entreprises"
   de `Quotes.tsx` :
   - Réutilise le sélecteur d'entreprises par secteur et les couleurs
     (`companyGroups.ts` — `groupCompaniesBySector`, `colorForCompany`),
     pas de duplication.
   - Filtres date début/fin, sélection multi-entreprises (comme
     `compareSelected`/`compareStartDate`/`compareEndDate` existants).
   - **Affichage flexible demandé par l'utilisateur** : contrôle (ex.
     boutons ou checkboxes) pour choisir d'afficher 1 courbe ("variation
     totale"), 2 courbes (hausses cumulées + baisses cumulées séparées),
     ou 3 (les deux + le total) — par entreprise sélectionnée. Pas de mode
     par défaut figé, laisser le contrôle à l'utilisateur.
   - Graphe : un point par jour (X = date), pas de vue intrajournalière ici
     (contrairement à la comparaison de prix existante) — c'est un
     agrégat quotidien par construction.

### ✅ 9. Performance ajustée au risque (rendement / volatilité) — NOUVEAU

**Dépend du point 8** (a besoin de `total_variation_percent` comme mesure
de risque). Une fois disponible : ratio simple
`variation_percent net (période) / total_variation_percent (période)` par
entreprise — un titre qui monte de 10% en bougeant peu (faible volatilité)
est un profil différent d'un titre qui monte de 10% en oscillant sans
cesse. Type d'analyse "quel titre a le meilleur rendement par unité de
risque pris", proche d'un ratio de Sharpe simplifié (sans taux sans
risque, non disponible ici).

**Aucune nouvelle table** : calcul dérivé des points 5 (`compare`) et 8
(`total_variation`) déjà en place.

### ✅ 10. Force relative vs indice (bêta simplifié) — NOUVEAU

**Dépend du point 0** (composition des indices). Une fois
`index_composition` peuplée : comparer la variation d'un titre à la
variation de l'indice auquel il appartient sur la même période
(`stock_quotes.variation_percent` vs `index_values.variation_percent`) —
« ce titre a-t-il surperformé ou sous-performé son indice de référence ? »
Permet aussi une vérification de cohérence : la somme pondérée des
variations des composants doit approximativement retomber sur la
variation publiée de l'indice (autre angle de contrôle qualité, point 3).

**Aucune nouvelle table** au-delà de peupler `index_composition` (point 0).

## Priorité basse / gros chantiers

### ✅ 11. Alertes de prix

La table `price_alerts` existe déjà dans le schéma (visible dans
`RESET_DATABASE.md`/`BD.sql`) mais est **totalement inutilisée** :
- Aucun endpoint PHP ne la lit ni ne l'écrit.
- Aucun code frontend n'y fait référence.

Ce n'est pas "brancher de l'existant" mais un vrai chantier complet à
construire : définition de seuils par utilisateur/entreprise, job qui
vérifie les seuils à chaque synchro, notification (OneSignal, déjà en
place pour d'autres usages — voir `class/OneSignalNotifier.php`), UI de
gestion des alertes (créer/modifier/supprimer).

### 12. Suivi des dividendes et opérations sur titres — NOUVEAU

**Constat** : `market_bulletins` contient déjà 11 bulletins téléchargés,
mais `market_bulletin_contents.extracted_text` est **vide (0 ligne
extraite)** — le texte n'a jamais été traité, alors que le pipeline
d'extraction/analyse IA existe déjà pour les rapports d'entreprise
(`company_report_analyses`, `ReportAnalysisService.php`). Les bulletins
officiels de la cote contiennent typiquement les annonces de détachement
de dividende, augmentations de capital, etc.

**Gros chantier** : appliquer le même pipeline d'extraction de texte +
analyse IA déjà construit pour les rapports, mais sur les bulletins ;
définir un schéma de sortie structuré (date, entreprise, type
d'opération, montant) plutôt que le résumé libre utilisé pour les
rapports ; construire un calendrier de dividendes côté frontend. À ne
prendre qu'une fois les chantiers prioritaires faits — dépend aussi de
la fiabilité de l'extraction de texte des rapports (déjà des échecs
observés ailleurs dans le projet, `extraction_error`).

**⏸️ Mis de côté le 04/08/2026** (sur demande explicite, pas par manque de
solution) — diagnostic fait en cours de route au cas où quelqu'un reprend
ce point plus tard :
- La cause des 10 échecs sur 11 bulletins était simplement **poppler/tesseract
  non installés** sur cette machine au moment des tentatives (erreur stockée :
  `pdftotext introuvable`). Les deux sont maintenant disponibles
  (`/opt/homebrew/bin/pdftotext`, `/opt/homebrew/bin/tesseract`) et
  l'extraction fonctionne quand testée directement (`PdfTextExtractor` sur
  `boc_20260730_2.pdf` → succès, méthode `text`, 157 463 caractères).
- Le bulletin #1 a `text_extracted = 1` mais un contenu illisible
  (caractères `�`) dans `market_bulletin_contents` — probablement hérité
  d'un des imports de dump avec encodage corrompu rencontrés cette semaine
  (voir `RESET_DATABASE.md`), pas un bug du pipeline d'extraction lui-même.
- Script déjà écrit et prêt (non exécuté) :
  `scripts/extract_pending_bulletins.php` — rejoue l'extraction pour tout
  bulletin à `text_extracted = 0` ou au contenu vide, réutilise
  `PdfTextExtractor`/`BRVMBulletinsScraper` directement (pas
  `api_bulletins.php`, qui fait `AuthGuard::requireAuth()` en tête de
  fichier, incompatible CLI).

## Rappel — pourquoi attendre la fermeture du marché

Seul le point 8 (variation totale) modifie du code exécuté par le cron en
production toutes les ~10 minutes en heures de marché
(`BRVMSyncService::recordIntradaySnapshot()`) — à tester et déployer hors
séance pour ne pas risquer de perturber la synchro en direct. Tous les
autres points sont des endpoints de lecture (ou des scripts ponctuels
comme le peuplement du point 0), sans risque, déployables à tout moment.
