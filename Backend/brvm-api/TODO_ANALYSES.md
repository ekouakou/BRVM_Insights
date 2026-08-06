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

#### Point tranché : premier relevé de la journée

Décision "démarre à 0" confirmée et implémentée dans
`BRVMSyncService::accumulateTotalVariation()` — le premier relevé du jour
crée simplement la ligne `intraday_total_variation` avec les totaux à 0,
sans comparaison à la clôture de la veille.

Précision apportée le 06/08/2026 : la bourse peut mettre du temps à ouvrir
le matin (~08:30) — la synchro tourne quand même à l'heure prévue et
récupère les données disponibles à ce moment-là. Ça ne change rien à la
logique ci-dessus : "premier relevé du jour" = premier appel à
`accumulateTotalVariation()` qui trouve encore aucune ligne pour
`(company_id, today)`, quelle que soit l'heure réelle à laquelle ça se
produit (pas de dépendance à une heure d'ouverture fixe côté code).

Autre précision : il n'y a pas de "clôture" calculée séparément — le
dernier relevé `intraday_quotes` du jour (le plus grand `quote_datetime`
pour `trading_date` donnée) EST la clôture. C'est cohérent avec
`stock_quotes.close_price`, mis à jour par upsert (`merge`) à chaque
synchro : après la dernière synchro du jour, il contient nécessairement le
prix du dernier relevé intrajournalier.

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

### ✅ 23. Classement sectoriel des entreprises + screener multi-critères — FAIT le 06/08/2026

**Fait** : nouveau fichier `api_screener.php`, action `screen` — pour
chaque entreprise active, assemble en une seule ligne : dernière cotation
connue (cours/variation/volume), derniers indicateurs techniques, score
composite (RSI/MACD/tendance/Bollinger, même formule que
`api_signals.php`, plafonné si illiquide), classification de liquidité (30
jours glissants), performance de cours sur la période choisie, et **rang
au sein du secteur** par cette performance (calculé en PHP par tri/
regroupement, pas en SQL — cohérent avec le reste du projet qui évite les
window functions SQL pour rester compatible d'anciennes versions
MySQL/MariaDB). Filtres combinables (ET logique) : secteur, score min/max,
RSI min/max, variation min/max, volume min, liquidité (multi-choix).
Nouveau `chart_type` `screener` enregistré. Frontend : nouvel écran
`/screener` (nav "Screener") — Card de filtres empilables, tableau
résultat avec colonne "Rang secteur" (ex. "2/4"), sélection de lignes +
analyse IA (avec l'option "Inclure les résultats financiers").

**Décision prise en implémentant** : la formule du score composite et la
classification de liquidité sont **dupliquées une 3ᵉ fois** (déjà
dupliquées de `api_quotes.php` vers `api_signals.php` au point 16) plutôt
que factorisées dans une classe partagée — choix délibéré pour rester
cohérent avec la convention déjà établie dans ce projet (fichiers API
indépendants, pas de couplage entre classes) et pour ne prendre aucun
risque de régression sur le code déjà testé du point 16. Si la formule du
score ou les seuils de liquidité changent un jour, il faudra les mettre à
jour aux 3 endroits (`api_quotes.php`, `api_signals.php`,
`api_screener.php`) — signalé en commentaire en tête de chacun des deux
derniers fichiers.

**Testé sur données réelles** (via réflexion PHP) : 47 entreprises
retournées sans filtre, rang sectoriel correct (ex. ABJC classée 2ᵉ/4 du
secteur Services sur la période testée), filtre liquidité fonctionnel
(47 → 32 entreprises avec liquidité "Élevée" uniquement).

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

### ✅ 12. Suivi des dividendes et opérations sur titres — FAIT le 06/08/2026

**Repris après la mise de côté du 04/08/2026** (voir historique ci-dessous) :
extraction structurée (une ligne par opération, pas un simple résumé texte)
des dividendes, augmentations de capital, admissions et assemblées
générales mentionnées dans les Bulletins Officiels de la Cote (BOC) déjà
traités, sur le modèle de `MarketBulletinAnalysisService.php` mais avec un
schéma de sortie tabulaire plutôt qu'un résumé de séance.

**Historique (04/08/2026)** : la cause des échecs d'extraction de texte à
l'époque était poppler/tesseract non installés sur la machine. Une fois
réglé, `scripts/extract_pending_bulletins.php` a été exécuté avec succès
sur les 9 bulletins restés en attente — les 16 bulletins en base ont
désormais tous `extracted_text` + `formatted_markdown` (`markdown_status
= success`).

**Fait** :
1. **Migration `012_bulletin_corporate_actions.sql`** — colonnes
   `corporate_actions_status/error/provider/model/updated_at` sur
   `market_bulletin_contents` (même statut de cache que pour le markdown) ;
   nouvelle table `market_bulletin_corporate_actions` (une ligne par
   opération : `bulletin_id`, `company_id` nullable, `company_name_raw`,
   `match_confidence`, `action_type`, `event_date`, `amount`, `currency`,
   `description`, `source_section`).
2. **`CompanySlugMatcher::matchCompanyName()`** (nouvelle méthode statique,
   même classe que `computeSlugAssignments` déjà utilisée pour le
   rattachement des rapports) — rattache un nom libre extrait par l'IA à
   une entreprise de la base, en 3 paliers : nom exact, ticker
   contenu/préfixe (dans les deux sens — les bulletins omettent parfois le
   dernier caractère du symbole, ex. "SIB" pour SIBC), puis similarité
   textuelle sur nom ET symbole (seuil 75%).
3. **`class/BulletinCorporateActionsService.php`** (nouveau, mirror de
   `MarketBulletinAnalysisService.php`) — méthode `extract()` : préfère le
   markdown restructuré au texte brut, prompt dédié demandant une extraction
   exhaustive au format `{"actions": [...]}`, ré-extraction = source de
   vérité (DELETE + re-INSERT des lignes du bulletin, pas d'accumulation de
   doublons à chaque relance). `listActions()` : vue calendrier filtrable
   (entreprise/type/période) + liste des bulletins encore en attente
   d'extraction.
4. **`api_bulletin_corporate_actions.php`** (nouveau, 3ᵉ fichier sibling
   de `api_bulletins.php`/`api_bulletin_analysis.php`) — actions
   `extract`/`list`/`get`. `chart_type` IA `corporate_actions` enregistré
   dans `ChartAnalysisService::METHODOLOGY`.
5. **`scripts/extract_corporate_actions.php`** (nouveau, CLI batch,
   `--provider=`/`--force`) + **`scripts/rematch_corporate_actions.php`**
   (nouveau, rejoue uniquement le rattachement entreprise sans rappeler
   l'IA — utile après une correction de `CompanySlugMatcher`, comme celle
   ci-dessous, sans regénérer un coût IA).
6. **Frontend** : nouveaux types (`CorporateAction`,
   `CorporateActionsListResult`, `CorporateActionsExtractResult`), nouvelle
   page `pages/CorporateActions.tsx` (`/corporate-actions`, nav "Opérations
   sur titres") — calendrier filtrable, bouton "Extraire" par bulletin en
   attente, badge "approx." sur les rattachements `fuzzy`.

**Bugs trouvés et corrigés en testant sur données réelles** (important :
ces trois cas n'étaient visibles qu'en confrontant l'algorithme à de vrais
noms de bulletins, pas en le relisant) :
- **"SIB" rattaché à tort à SITAB (STBC) au lieu de SIBC** — le repli par
  similarité ne comparait le texte brut qu'aux NOMS complets des
  entreprises, jamais à leurs symboles. Un ticker court écrit tel quel dans
  le bulletin ("SIB") pouvait donc perdre face au nom complet d'une
  entreprise sans rapport ("SITAB") simplement parce que la chaîne se
  trouvait être plus proche en caractères. Corrigé en comparant aussi au
  symbole de chaque entreprise, et en autorisant explicitement le sens
  "symbole commence par la cible" (pas seulement "cible contient le
  symbole") pour couvrir les tickers tronqués d'un caractère.
- **"BIIC" à égalité parfaite (75%) entre deux entreprises différentes**
  (BICB, Bénin vs BICC, Côte d'Ivoire) — l'algorithme prenait alors le
  premier candidat rencontré, arbitraire et non reproductible. Ajout d'un
  garde-fou : si le meilleur score est partagé par ≥ 2 entreprises
  différentes, ne rattacher à aucune plutôt que deviner (même philosophie
  que le garde-fou anti-collision déjà présent dans
  `computeSlugAssignments`).
- **"BANK OF AFRICA BN/ML/SN/NG/BF" jamais rattachées** (6 entités
  distinctes, une par pays, BRVM-COMPOSITE) — le nom de base identique
  ("BANK OF AFRICA") les mettait systématiquement à égalité, tombant dans
  le garde-fou ci-dessus. Or le bulletin précise bien le pays en dernier
  mot. Ajout d'un palier dédié : si le dernier mot de la cible est une
  abréviation pays reconnue (`BULLETIN_COUNTRY_TOKEN_TO_CODE` — distincte
  de `COUNTRY_SLUG_SUFFIX`, les abréviations utilisées dans les bulletins
  différant parfois du code ISO, ex. "NG" pour le Niger), on restreint la
  comparaison aux entreprises de ce pays avant de comparer le nom de base.
  Nécessite `country_code` dans la requête `companies` passée au matcher
  (jointure `countries`, même requête que `api_reports.php`/
  `scripts/backfill_reports.php` pour `computeSlugAssignments`).

**Testé sur données réelles** : extraction lancée sur les 16 bulletins en
base (fournisseur Gemini par défaut) — **13 réussies, 573 opérations
extraites**, taux de rattachement entreprise 77-100% selon le type
d'opération (92% pour les assemblées générales, le type le plus fréquent).
3 bulletins (#4, #8, #10 — 22-28 juillet 2026) échouent de façon
persistante avec un timeout réseau Gemini (60s, 0 octet reçu) même après
plusieurs tentatives et un essai avec le fournisseur Anthropic (bloqué par
une clé API invalide dans cet environnement, pas un problème du code) —
probablement une taille/complexité de contenu qui pousse Gemini au-delà de
son délai habituel sur ces bulletins précis. Pas bloquant : ces 3
bulletins restent visibles dans `pending_bulletins` côté frontend
(bouton "Extraire" par bulletin), à relancer individuellement plus tard.
Les cas non rattachés restants après correction (ex. "TOTAL" seul — deux
entités Total distinctes en base, Côte d'Ivoire et Sénégal, sans indice de
pays dans le texte ; les titres de créance type "FCTC EPT...", qui ne sont
pas des actions donc absents de la table `companies`) sont des ambiguïtés
réelles, pas des bugs — cohérent avec la philosophie "mieux vaut un
rattachement manquant qu'un rattachement faux" déjà appliquée ailleurs
dans cette classe.

### ✅ 24. Fondamentaux (PER, P/B, ROE, ROA, marges, dividend yield, payout, PEG) — FAIT le 06/08/2026

**Décision initiale de l'utilisateur : option (b)** (nouvelle table
fiable, alimentée par scraping brvm.org). **Vérifié en direct sur
brvm.org avant de coder quoi que ce soit** (pages individuelles
`/fr/emetteurs/societes-cotees/{slug}`, structure `DOMDocument`/`DOMXPath`
cohérente et scrapable comme `scripts/populate_market_cap.php`) — mais
constat bloquant : **les données y sont périmées, de façon très inégale
selon l'entreprise** (Bank of Africa Bénin : dernier exercice affiché
2015 ; Africa Global Logistics : 2022 — soit 4 à 11 ans de retard par
rapport à aujourd'hui), et **aucune "Capitaux propres" n'y figure**
(seulement "Capital social", pas équivalent — bloquant pour ROE/P-B). Une
"source fiable" scrapée se serait donc révélée en réalité **moins à jour**
que les rapports déjà traités par la filière IA existante. Remonté à
l'utilisateur, qui a basculé sur l'**option (a)**.

**Fait (option a)** : nouveau fichier `api_fundamentals.php`, action
`list` — pour chaque entreprise active, prend son dernier
`company_report_analyses` réussi (par date de publication du rapport
source), extrait `key_financials`/`valuation_assessment` du JSON déjà
stocké (`details`), calcule le PEG (PER ÷ croissance du CA — seule pièce
manquante du schéma IA existant). `companies_without_data`/count
retournés pour que le frontend annonce explicitement les entreprises sans
donnée plutôt que de laisser croire à une liste complète. Nouveau
`chart_type` `fundamentals` enregistré. Frontend : nouvel écran
`/fundamentals` (nav "Fondamentaux") — tableau avec date du rapport
source toujours visible par ligne, `InfoPanel` expliquant explicitement
la nature IA de la donnée (et pourquoi brvm.org a été écarté), sélection
+ analyse IA.

**Testé sur données réelles** : sur 47 entreprises actives, **10
seulement** ont au moins un rapport analysé avec succès (37 sans donnée,
listées explicitement dans l'UI) ; sur les analyses existantes, le PER
n'est renseigné que dans 6/83 cas (le nombre d'actions en circulation est
rarement précisé dans les rapports sources, rendant PER/EPS/P-B
incalculables même sur un rapport par ailleurs bien traité) — attendu et
expliqué dans l'`InfoPanel`, pas un bug. La couverture s'améliorera
naturellement au fil du traitement de nouveaux rapports (page Rapports).

### ✅ 25. Moteur de backtesting simple — FAIT le 06/08/2026

**Décision de l'utilisateur** : construire le moteur dès maintenant plutôt
que d'attendre un historique plus long — le code fonctionne dès
aujourd'hui sur ce qui existe (peu ou pas de trades pour l'instant) et
deviendra utile progressivement, sans changement de code, à mesure que
l'historique s'accumule au fil des synchronisations quotidiennes.

**Fait** : nouveau fichier `api_backtest.php`, action `run` — simule
jour par jour, pour une entreprise et une période, l'une des deux règles
prévues (`signal_score` : entre/sort selon le score composite, seuils
configurables ; `golden_cross` : entre/sort au croisement d'une paire de
SMA configurable parmi 10/20/50), et compare la courbe d'équity obtenue
(base 100) à un simple "acheter et garder" sur la même période. Renvoie
le nombre d'opérations, le taux de réussite, le rendement moyen par
opération, et `insufficient_history` (moins de 60 jours de bourse
simulés) pour que le frontend affiche un avertissement explicite et
persistant plutôt que de laisser croire à un résultat fiable. Nouveau
`chart_type` `backtest` enregistré. Frontend : nouvel écran `/backtest`
(nav "Backtesting") — sélection entreprise/règle/paramètres/période,
bandeau d'avertissement ambre quand l'historique est insuffisant, courbe
d'équity stratégie vs acheter-garder, tableau des opérations.

**Décisions prises en implémentant** :
- **Pas de nouvelle table** (`backtest_runs`/`backtest_trades` envisagées
  dans le plan initial) — la simulation est rejouée à la demande à chaque
  affichage, peu coûteuse sur l'historique actuel. À revoir si
  l'historique devient trop long pour un recalcul systématique rapide, ou
  si comparer plusieurs runs entre eux devient un besoin réel.
- **Formule du score composite dupliquée une 4ᵉ fois** (déjà dupliquée
  dans `api_signals.php` et `api_screener.php`) — même choix de
  cohérence avec la convention déjà établie dans ce projet (fichiers API
  indépendants) plutôt qu'une factorisation qui aurait nécessité de
  toucher aux fichiers déjà testés.
- **Testé sur données réelles** (via réflexion PHP, les deux règles) :
  avec 3 jours d'historique, 0 trade détecté dans les deux cas
  (`insufficient_history=true`), courbe d'équity correctement calculée
  malgré tout (acheter-garder reflète le vrai mouvement du cours, la
  stratégie reste plate faute d'avoir jamais pu entrer en position) —
  comportement attendu, pas un bug.

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



Points 17-22

Backend api_screener.php (testé sur données réelles)

Enregistrer chart_type screener + type frontend

Créer Screener.tsx (filtres + tableau) + route/nav

Vérifier (php -l, tsc), documenter point 23

Statuer sur points 24 (décision utilisateur) et 25 (différé)