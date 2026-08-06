# Prochaines analyses à implémenter

Liste des chantiers identifiés le 04/08/2026, à démarrer à la fermeture du
marché (pour ne pas toucher au code de synchro pendant que le cron tourne
en heures de marché). Classés par priorité (rapport effort/valeur).

Mise à jour du 04/08/2026 (revue angle analyste boursier / contrôleur
financier) : ajout de 6 chantiers identifiés en creusant les données
réelles en base (pas de la spéculation — voir constats chiffrés dans
chaque section).

Mise à jour du 06/08/2026 : ajout des points 13 à 25, suite à un audit
complet du code existant (grep systématique, pas de supposition) comparé à
une liste de métriques boursières standard (technique, statistique, risque,
fondamental, comparatif, signaux) fournie par l'utilisateur pour aider à la
prise de décision. Constat principal de l'audit : `api_technical_indicators.php`
contient déjà ~30 formules d'indicateurs (ADX, Ichimoku, Stochastique, OBV,
VWAP, Williams %R, CCI, etc.), mais **seuls 6 sont persistés et seuls 3
affichés** à l'utilisateur (SMA/RSI/MACD/Bollinger/ATR calculés à chaque
synchro dans `technical_indicators`, mais seules les SMA10/20/50 sont
tracées sur un graphe) — le premier chantier ci-dessous est donc surtout du
branchement, pas du calcul nouveau. À l'inverse, toute la famille
statistique/risque (Sharpe réel, Sortino, Max Drawdown, VaR, Calmar,
skewness/kurtosis, rendements annualisés) est **totalement absente** :
`risk_adjusted` existant est décrit dans son propre code comme "proche d'un
Sharpe simplifié" (volatilité = churn intrajournalier cumulé, pas un
écart-type de rendements, pas de taux sans risque) — utile mais à ne pas
confondre avec les vraies métriques du même nom.

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

### ✅ 13. Brancher les indicateurs déjà codés mais jamais persistés (ADX, Stochastique, ROC, OBV, VWAP) — FAIT le 06/08/2026

**Constat** : `api_technical_indicators.php` contient déjà des actions
ad-hoc complètes pour ADX (`adx`, ligne ~854), Stochastique (`stochastic`,
~714), ROC (`roc`, ~1902), OBV (`obv`, ~1056) et VWAP (`vwap`, ~1620) —
formules correctes, mais calculées à la volée puis jetées : aucune colonne
dédiée dans `technical_indicators`, jamais appelées depuis
`TechnicalIndicatorsCalculator::computeAndPersist()`, jamais affichées.
**Bug à corriger au passage** : le VWAP actuel est cumulatif sans reset
quotidien (le commentaire du code dit qu'il devrait l'être) — le corriger
en persistant.

**Nouvelle(s) colonne(s) nécessaire(s)** sur `technical_indicators` (le
pattern de migration est déjà rodé — voir `class/TechnicalIndicatorsCalculator.php`,
3 étapes : migration `ALTER TABLE`, méthode `computeX()` + entrée dans le
tableau `$indicators`, la logique d'upsert existante gère le reste sans
modification) : `adx_14`, `stoch_k`, `stoch_d`, `roc_12`, `obv`, `vwap`.

**Fait le 06/08/2026** : `migrations/011_more_technical_indicators.sql`
(6 colonnes ci-dessus) ; méthodes `computeADX()`/`computeStochastic()`/
`computeROC()`/`computeOBV()`/`computeVWAP()` ajoutées à
`TechnicalIndicatorsCalculator.php`, formules reprises de
`api_technical_indicators.php` ; `scripts/backfill_new_indicators.php`
(rejoue toutes les lignes `technical_indicators` déjà existantes, dans
l'ordre chronologique par entreprise — l'OBV est incrémental, l'ordre
compte). Frontend : VWAP en overlay optionnel sur "Cours de clôture"
(`Quotes.tsx`), OBV en sous-graphe dédié, ADX/Stochastique/ROC regroupés
dans une carte "Indicateurs avancés" (chacun avec message "historique
insuffisant" tant que les données manquent, plutôt que masqué).

**Décisions prises en implémentant** (à connaître avant de retoucher) :
- **VWAP corrigé, pas juste porté** : l'implémentation ad-hoc buguée
  (cumul sans reset) a été abandonnée plutôt que reproduite — le nouveau
  `computeVWAP()` calcule un vrai VWAP quotidien à partir des relevés
  `intraday_quotes` de CE jour précis (`DATE(quote_datetime) = ?`), donc se
  réinitialise naturellement chaque jour. Conséquence : `vwap` est `null`
  tant qu'aucun relevé intrajournalier n'existe pour le jour demandé (ex.
  avant que le cron intrajournalier n'ait tourné ce jour-là).
- **OBV rendu incrémental** (aujourd'hui = hier + volume signé du jour,
  relit la valeur persistée la veille) plutôt que recalculé sur toute la
  fenêtre d'historique à chaque appel comme le fait l'ad-hoc original — la
  valeur absolue de l'OBV est arbitraire de toute façon (dépend du point de
  départ), autant garder une continuité jour après jour. Implication pour
  tout futur backfill/recalcul : toujours rejouer dans l'ordre
  chronologique croissant par entreprise, jamais en parallèle ni dans le
  désordre, sous peine d'OBV faux.
- **Ichimoku non fait**, comme prévu (mis de côté sauf demande explicite).
- **Constat après backfill (06/08/2026, 141 lignes recalculées)** : OBV et
  VWAP se remplissent immédiatement (besoin d'1-2 jours seulement), mais
  ADX/Stochastique/ROC restent `null` partout pour l'instant — l'historique
  de `technical_indicators` ne couvre que 3 jours (04-06/08/2026), alors
  qu'il en faut 13 à 29 selon l'indicateur. Comportement attendu, pas un
  bug (même cause que les signaux "Indéterminé" déjà documentés côté
  `Quotes.tsx`) — se résorbe automatiquement au fil des prochaines
  synchronisations quotidiennes.

### ✅ 14. Détection croisement de moyennes mobiles (golden cross / death cross) — FAIT le 06/08/2026

**Fait** : nouvelle action `crossovers` dans `api_signals.php`
(`getCrossovers()`) — compare SMA10/20 et SMA20/50 jour par jour sur la
période demandée (SMA déjà persistées, point 1), détecte les changements de
signe (golden = rapide passe au-dessus de la lente, death = l'inverse).
Frontend (`Quotes.tsx`) : points verts/rouges (`ReferenceDot`) directement
sur le graphe "Cours de clôture" aux dates détectées, plus une ligne
récapitulative "Derniers croisements" sous le graphe. Les marqueurs restent
visibles même si la SMA correspondante n'est pas cochée à l'affichage.
Le croisement 50/200 (le plus cité classiquement) n'est pas encore possible
faute d'historique suffisant — ajoutable sans changement de schéma une fois
que `technical_indicators` couvrira plus de 200 jours.

### ✅ 15. Détection de divergence RSI/prix — FAIT le 06/08/2026

**Fait** : nouvelle action `divergence` dans `api_signals.php`
(`getDivergence()`) — repère les pivots (sommets/creux locaux, fenêtre de 3
jours de part et d'autre) du cours de clôture, compare deux pivots
consécutifs de même type : sommet de prix plus haut mais RSI plus bas =
divergence baissière ; creux de prix plus bas mais RSI plus haut =
divergence haussière. Frontend : RSI maintenant affiché (jusqu'ici jamais
tracé, malgré son usage dans le score composite) dans un nouveau
sous-graphe de la carte "Indicateurs avancés", avec les divergences
détectées marquées en points colorés + liste récapitulative, sur le même
principe que les croisements de moyennes mobiles (point 14).

### ✅ 16. Renforcer le score composite avec liquidité + ATR — FAIT le 06/08/2026

**Fait** : `buildSignal()` (`api_signals.php`) reçoit maintenant le
classement de liquidité déjà calculé (nouvelle méthode
`getLiquidityByCompany()`, mêmes seuils que `api_quotes.php::getLiquidity()`
— dupliqués intentionnellement plutôt que de coupler les deux fichiers) ;
un score fort (±2) sur un titre "Illiquide" est automatiquement plafonné à
±1, avec `confidence_penalized_by_liquidity=true` dans la réponse. `atr_14`
et `atr_relative_percent` (ATR ÷ cours, en %) sont exposés en contexte
(jamais utilisés dans le calcul du score lui-même). Frontend
(`Quotes.tsx`) : icône ⚠︎ à côté du badge Signal quand le score a été
plafonné, ATR relatif affiché au survol du badge, InfoPanel mis à jour.
Testé directement sur les données réelles via réflexion PHP (contournement
d'`AuthGuard` pour un test local, pas un endpoint exposé) : le classement
de liquidité se calcule correctement (`ABJC` → "Élevée"), les nouveaux
champs sont bien présents dans la réponse.

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

### ✅ 17-22. Fondation quantitative + Sharpe/Sortino/Drawdown/Calmar/VaR/CVaR/skewness/kurtosis/bêta — FAIT le 06/08/2026

**Implémentés ensemble** (plutôt que 6 endpoints quasi-identiques
requêtant chacun la même série de cours, un seul passage par entreprise) :

- `class/ReturnsCalculator.php` (point 17) : classe utilitaire sans
  dépendance base de données — log-rendements quotidiens, moyenne/écart-
  type, CAGR, volatilité annualisée (×√252), downside deviation, maximum
  drawdown (+ dates pic/creux), VaR/CVaR historique (percentile empirique,
  pas de Monte-Carlo), skewness, excès de kurtosis, covariance/bêta.
- `api_risk_metrics.php`, action `compute` (points 18-22) : pour chaque
  entreprise sélectionnée sur une période, calcule rendement net, CAGR,
  volatilité annualisée, Sharpe, Sortino, Max Drawdown (+ dates), Calmar,
  VaR/CVaR à 95%, skewness, excès de kurtosis, et bêta vs BRVM-COMPOSITE
  (régression sur les rendements quotidiens des jours communs). Nouveau
  `chart_type` `risk_metrics_advanced` enregistré dans
  `ChartAnalysisService.php`.
- Frontend : 5ᵉ onglet "Métriques de risque avancées" sur `Statistics.tsx`
  (partage la sélection d'entreprises/dates des 4 autres onglets), tableau
  scrollable horizontalement (14 colonnes), `InfoPanel` détaillé
  expliquant chaque métrique en langage clair. Sélection de lignes +
  analyse IA, avec l'option "Inclure les résultats financiers"
  (`companyIdsForReports`, déjà construite pour l'écran Classements).

**Décisions prises en implémentant** :
- **Garde-fou "historique insuffisant"** (< 20 rendements journaliers,
  seuil non prévu explicitement dans le plan initial mais nécessaire) :
  toutes les métriques annualisées/statistiques restent à `null`
  (`insufficient_history=true` dans la réponse) plutôt que de calculer un
  Sharpe ou une volatilité "annualisée" sur 2-3 observations — testé
  directement : sans ce garde-fou, une entreprise avec seulement 3 jours
  d'historique affichait une volatilité annualisée de 158% et un Sharpe de
  11.2, des chiffres arithmétiquement corrects mais statistiquement du
  bruit. Seul le rendement net simple (2 points de cours suffisent)
  échappe au garde-fou. Ce seuil de 20 jours signifie que ces métriques
  resteront vides pour la plupart des entreprises tant que l'historique de
  synchronisation (3 jours au 06/08/2026) n'aura pas suffisamment
  progressé.
- **Taux sans risque** : fixé à 0% par défaut comme prévu, toujours
  renvoyé explicitement dans la réponse (`risk_free_rate_percent`) plutôt
  que laissé implicite — configurable via le paramètre d'entrée
  `risk_free_rate_percent` si une valeur UEMOA/BCEAO devient disponible un
  jour.
- **Graphe "underwater" pour le Max Drawdown non fait** (prévu en option
  dans le plan initial) — seules les valeurs numériques (drawdown max +
  dates pic/creux en tooltip) sont affichées pour l'instant, faute de
  temps ; ajoutable plus tard sans changement de backend (les cours
  historiques nécessaires sont déjà chargés côté serveur pour le calcul,
  il suffirait de les renvoyer en série complète).
- `risk_adjusted` (l'ancien ratio simplifié) n'a pas été renommé dans l'UI
  comme envisagé — les deux onglets sont désormais côte à côte avec des
  noms suffisamment distincts ("Performance ajustée au risque" vs
  "Métriques de risque avancées") et l'`InfoPanel` du nouvel onglet
  explique explicitement la différence.

### 23. Classement sectoriel des entreprises + screener multi-critères

**Constat** : `sector_performance` classe déjà les *secteurs* entre eux
(moyenne), et `volume_ranking`/`performance_ranking` classent déjà les
*entreprises* sur un seul critère à la fois — mais rien ne croise les deux
(classement des entreprises *au sein de* leur secteur) ni ne permet de
filtrer sur plusieurs critères en même temps (ex. "RSI &lt; 30 ET volume
élevé ET secteur Finance").

**Aucun changement de base de données nécessaire** (agrégation de données
déjà exposées par les endpoints existants : `list` signaux, `volume_ranking`,
`performance_ranking`, `liquidity`).

**À faire** :
- Backend : nouvelle action assemblant plusieurs sources déjà existantes
  par `company_id` (signal, volume, performance, liquidité, secteur) en une
  seule table filtrable côté serveur (seuils configurables par critère).
- Frontend : nouvel écran "Screener" — filtres empilables (façon les
  `Select`/`Input` déjà utilisés ailleurs) + tableau résultat triable,
  potentiellement le plus utile des nouveaux écrans pour la prise de
  décision rapide (« quelles entreprises correspondent à mes critères
  maintenant ? ») mais aussi le plus gros morceau frontend de ce plan.

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

### 24. Fondamentaux calculés depuis des données structurées (PER, P/B, ROE, ROA, marges, FCF yield, dividend yield, payout, PEG)

**Constat important — deux filières séparées existent déjà, à ne pas
confondre** :
- La table `companies` n'a **aucune colonne fondamentale** (ni EPS, ni
  valeur comptable, ni dette, ni dividende) — impossible de calculer un
  PER/P-B/ROE depuis la base relationnelle aujourd'hui.
- En revanche, `company_report_analyses` contient déjà des ratios
  équivalents **extraits par IA** depuis le texte des rapports PDF
  (`key_financials`/`valuation_assessment` dans le schéma
  `class/AnthropicClient.php` — revenue, marges, ROE/ROA, PER, P/B,
  EV/EBITDA, dividend yield, payout ratio...). C'est riche, mais
  **dépendant de la fiabilité d'extraction PDF** (voir les
  `extraction_error` déjà rencontrés, point 12) — une fiabilité
  différente d'un calcul déterministe sur des colonnes de base.
- **PEG ratio absent des deux filières** — PER et croissance existent
  séparément, rien ne les divise.

**Décision à prendre avant de coder** : soit (a) exploiter ce qui existe
déjà côté IA (rapide, mais hérite de la fragilité d'extraction PDF —
suffisant pour un premier écran "Fondamentaux" avec un avertissement clair
sur la source), soit (b) construire une vraie table `company_fundamentals`
alimentée par une source fiable (scraping d'une page dédiée sur brvm.org si
elle existe, ou saisie manuelle trimestrielle) — plus fiable mais plus
lourd, et sans garantie qu'une source structurée existe pour la BRVM.

**Nouvelle table nécessaire seulement pour l'option (b).**

**À faire (option a, plus rapide)** :
- Backend : nouvelle action agrégeant, par entreprise, le dernier
  `company_report_analyses` disponible (déjà stocké) — pas de nouveau
  calcul, juste une lecture/formatage dédiée avec le PEG calculé (PER ÷
  croissance du CA déjà présente) puisque c'est la seule pièce manquante.
- Frontend : nouvel écran "Fondamentaux" ou section sur la fiche
  entreprise, avec mention explicite "estimé par IA depuis le dernier
  rapport traité, à vérifier" — cohérent avec le disclaimer déjà utilisé
  partout ailleurs dans l'app pour l'IA.

### 25. Moteur de backtesting simple

**Gros chantier, à ne prendre qu'après les points prioritaires.** Aucun
code de backtest n'existe (seul un commentaire dans
`TechnicalIndicatorsCalculator.php` explique que la persistance des
indicateurs a été pensée *pour permettre* un futur backtesting). Objectif
minimal réaliste : tester une règle simple ("acheter si signal composite ≥
+1, vendre si ≤ -1", ou "acheter au golden cross, vendre au death cross")
sur l'historique déjà accumulé, et calculer la performance qu'aurait eue
cette règle par rapport à un simple "acheter et garder".

**Nouvelle table probable** (`backtest_runs`/`backtest_trades`) pour
stocker les résultats plutôt que tout recalculer à chaque affichage.

**À faire** :
- Backend : moteur simulant l'application d'une règle jour par jour sur
  l'historique `stock_quotes`/`technical_indicators`/signaux déjà
  persistés (pas de nouvelle collecte de données nécessaire, seulement du
  rejeu) ; calcul de la performance simulée, du nombre de trades, du
  taux de réussite, comparée au "buy & hold" sur la même période.
  **Limite à annoncer clairement à l'utilisateur** : historique encore
  très court aujourd'hui (3 jours de `technical_indicators` au 06/08/2026)
  — un backtest sur une fenêtre aussi courte n'a quasiment aucune valeur
  statistique ; ce chantier ne devient réellement utile qu'après plusieurs
  mois d'accumulation de données.
- Frontend : nouvel écran "Backtesting" — sélection d'une règle
  prédéfinie + période, résultat sous forme de courbe de performance
  cumulée vs buy & hold, avec l'avertissement ci-dessus toujours visible
  tant que l'historique reste court.

## Rappel — pourquoi attendre la fermeture du marché

Le point 8 (variation totale) modifie du code exécuté par le cron en
production toutes les ~10 minutes en heures de marché
(`BRVMSyncService::recordIntradaySnapshot()`) — à tester et déployer hors
séance pour ne pas risquer de perturber la synchro en direct. Le point 13
(nouveaux indicateurs ADX/Stochastique/ROC/OBV/VWAP) touche aussi du code
appelé par le cron, mais une fois par jour seulement
(`TechnicalIndicatorsCalculator::computeAndPersist()`, appelée depuis
`cron_sync_brvm.php` et non à chaque tick intrajournalier) — risque plus
faible, mais même précaution : tester hors séance avant de déployer. Tous
les autres points sont des endpoints de lecture (ou des scripts ponctuels
comme le peuplement du point 0), sans risque, déployables à tout moment.
