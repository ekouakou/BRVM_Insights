# TODO — Moteur de liquidité & dynamique du carnet d'ordres

Nouveaux onglets du tableau de bord entreprise : tracer l'évolution de l'offre
(vente) et de la demande (achat), estimer les volumes exécutés, mesurer la
liquidité réelle de chaque titre, et répondre à la question finale de
l'investisseur : **« si je possède 1 000 actions, le marché peut-il les
absorber rapidement ? »**

---

## 1. Ce que la demande initiale supposait — et ce que les données permettent VRAIMENT

La demande initiale (exemple BOAM à 09h30 / 10h30 / 11h30) suppose qu'on
observe le **carnet d'ordres intraday** (volumes à l'achat et à la vente à
chaque instant). Audit des sources réellement disponibles (fait le
14/08/2026) :

| Source | Contenu carnet | Fréquence | Verdict |
|---|---|---|---|
| `brvm.org/fr/cours-actions/0` (déjà pollée ~toutes les 10 min par `BRVMSyncService`) | cours, volume **cumulé** de la séance, variation — AUCUN bid/ask | intraday | ✅ exploitable pour le **flux d'exécution** |
| Bulletin Officiel de la Cote (BOC, PDF déjà téléchargé + texte extrait en base) | table « Quantité résiduelle à l'achat / **Achat / Vente** (meilleures limites) / Quantité résiduelle à la vente / Cours de référence » pour TOUTES les actions | 1×/jour (fin de séance) | ✅ exploitable pour le **carnet fin de séance** |
| Page valeur brvm.org, Sikafinance | pas de carnet public (le « Achat/Vente » de Sikafinance est un sondage d'opinion) | — | ❌ |
| Flux de marché BRVM / terminal SGI | carnet complet temps réel | temps réel | 💰 payant / compte SGI — hors périmètre, mais le schéma doit être prêt à l'accueillir |

**Conséquence structurante** : il n'existe AUCUNE source publique gratuite du
carnet intraday. Le « volume disponible à la vente à 09h30 vs 10h30 » n'est
pas observable aujourd'hui. En revanche, deux choses le sont, et elles
suffisent à construire un vrai moteur de liquidité :

1. **Le flux d'exécution intraday** (observé) : le volume affiché par
   brvm.org est cumulatif sur la séance (vérifié en base : 0 → 18 → 21 → 75
   → 189…). La différence entre deux relevés = actions **réellement
   échangées** dans l'intervalle. C'est plus fiable qu'une déduction de
   carnet : une exécution est un fait, pas une estimation.
2. **Le carnet de fin de séance** (observé) : le BOC publie chaque jour,
   pour chaque action, les meilleures limites et les quantités résiduelles.
   Exemple réel (BOC du 05/08/2026) : `BOAM — 27 titres résiduels à l'achat,
   5 585 / 5 590, 272 titres résiduels à la vente, référence 5 585`.

Le moteur est donc structuré en **3 étages** :

- **Étage 1 — Flux d'exécution intraday** (données observées, dispo immédiatement)
- **Étage 2 — Carnet quotidien fin de séance** (données observées, dispo immédiatement — 19 bulletins déjà en base à backfiller, historique complet récupérable depuis brvm.org)
- **Étage 3 — Carnet intraday** (futur : le schéma accueille des snapshots horodatés dès qu'une source existe, sans nouvelle migration)

Le croisement Étage 1 × Étage 2 donne les **estimations d'absorption** :
« les 272 titres offerts à la vente hier soir sur BOAM ont-ils été absorbés
par les 350 titres échangés aujourd'hui ? »

---

## 2. Le principe non négociable : observé / calculé / estimé

Chaque nombre affiché porte une étiquette de nature. C'est la colonne
vertébrale de tout l'écran (même philosophie que le reste de l'appli :
raisons déterministes traçables jusqu'aux chiffres affichés).

| Nature | Définition | Exemples |
|---|---|---|
| 🟦 **Observé** | lu tel quel à la source | cours, volume cumulé, meilleures limites BOC, quantités résiduelles |
| 🟨 **Calculé** | arithmétique exacte sur des observés | Δ volume entre deux relevés, spread, ratio achat/vente, VWAP |
| 🟧 **Estimé** | hypothèse explicite + formule affichée | sens acheteur/vendeur d'une exécution (tick rule), absorption jour+1, temps de vente de N actions |

Règles :
- une estimation n'est JAMAIS présentée comme une transaction confirmée ;
- chaque estimation affiche sa formule et ses données d'entrée au survol ;
- une baisse de quantité résiduelle entre deux séances n'est jamais
  interprétée seule : elle est confrontée au volume exécuté observé du jour
  (exécution ≠ annulation ≠ modification — exactement le piège décrit dans
  l'exemple BOAM de la demande initiale) ;
- si `Δ résiduel vente ≤ volume exécuté du jour` → « compatible avec une
  absorption par le marché » ; sinon → « annulations/retraits probables » —
  formulations prudentes imposées.

---

## 3. Base de données — OUI, 2 nouvelles tables (+ 1 optionnelle)

### 3.1 `order_book_snapshots` — migration 022 (NOUVELLE — obligatoire)

Un enregistrement = une photographie du carnet d'une entreprise à un
instant. Quotidienne (BOC) aujourd'hui, intraday demain (étage 3) — d'où le
`DATETIME` et la colonne `source`.

```sql
CREATE TABLE order_book_snapshots (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    snapshot_datetime DATETIME NOT NULL,        -- BOC : date du bulletin à 14:30 (fin de séance)
    source VARCHAR(20) NOT NULL DEFAULT 'bulletin_boc',  -- 'bulletin_boc' | futurs : 'sgi_feed', 'manual'
    bulletin_id BIGINT NULL,                     -- traçabilité vers market_bulletins
    best_bid_price DECIMAL(15,2) NULL,           -- 🟦 meilleure limite à l'achat
    best_ask_price DECIMAL(15,2) NULL,           -- 🟦 meilleure limite à la vente
    bid_residual_qty BIGINT NULL,                -- 🟦 quantité résiduelle à l'achat (à la meilleure limite)
    ask_residual_qty BIGINT NULL,                -- 🟦 quantité résiduelle à la vente
    reference_price DECIMAL(15,2) NULL,          -- 🟦 cours de référence
    spread_abs DECIMAL(15,2) NULL,               -- 🟨 ask - bid
    spread_percent DECIMAL(10,4) NULL,           -- 🟨 spread / mid × 100
    imbalance_ratio DECIMAL(10,4) NULL,          -- 🟨 bid_qty / (bid_qty + ask_qty), NULL si carnet vide
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_snapshot (company_id, snapshot_datetime, source),
    KEY idx_company_date (company_id, snapshot_datetime),
    KEY idx_bulletin (bulletin_id)
);
```

Pourquoi une table (et pas un calcul à la volée depuis le texte des
bulletins) : le parsing PDF→texte→table est coûteux et fragile ; on parse
UNE fois, on requête ensuite en SQL indexé. Et l'étage 3 (intraday) aura
besoin de cette table telle quelle.

### 3.2 `intraday_execution_flow` — migration 022 (NOUVELLE — obligatoire)

Un enregistrement = un intervalle entre deux relevés intraday consécutifs
d'une même séance, avec le volume exécuté (Δ du cumul) pré-calculé. C'est la
matière première de la heatmap jour×heure et de l'analyse par créneau — la
recalculer à chaque affichage depuis `intraday_quotes` (46 entreprises × ~50
relevés × N jours) serait trop lent et dupliquerait la logique anti-pièges.

```sql
CREATE TABLE intraday_execution_flow (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    trading_date DATE NOT NULL,
    interval_start DATETIME NOT NULL,            -- 🟦 relevé précédent
    interval_end DATETIME NOT NULL,              -- 🟦 relevé courant
    price_start DECIMAL(15,2) NULL,              -- 🟦
    price_end DECIMAL(15,2) NULL,                -- 🟦
    executed_volume BIGINT NOT NULL,             -- 🟨 Δ volume cumulé (≥ 0)
    executed_value DECIMAL(20,2) NULL,           -- 🟨 executed_volume × prix moyen de l'intervalle
    price_direction TINYINT NOT NULL DEFAULT 0,  -- 🟨 signe de (price_end - price_start) : -1/0/+1
    pressure_side VARCHAR(10) NULL,              -- 🟧 'achat'|'vente'|NULL — tick rule, NULL si prix inchangé
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_interval (company_id, interval_end),
    KEY idx_company_date (company_id, trading_date)
);
```

**Pièges du calcul (vérifiés sur données réelles, à encoder dans le builder) :**
- avant ~09h10, la page brvm.org affiche ENCORE la séance de la veille
  (constaté : volume 5 155 à 08h30 puis 0 à 09h10) → la séance commence au
  premier relevé où le cumul retombe à 0 ou en dessous du relevé précédent ;
  tout relevé antérieur à ce reset est ignoré pour la journée ;
- Δ négatif en cours de séance (correction du site) → intervalle marqué
  invalide, jamais un volume négatif ;
- le cumul intraday peut différer du volume officiel de `stock_quotes`
  (fixing de clôture après le dernier relevé) → un « intervalle de clôture »
  synthétique porte l'écart `volume_officiel - dernier_cumul` s'il est > 0.

### 3.3 `liquidity_scores` — PAS de table (calcul à la volée)

Le score de liquidité 0-100 est calculé à la demande par l'API (même
convention que `api_composite_score.php` / `CompositeScoreCalculator`) : il
dérive entièrement de tables déjà persistées et indexées, pour ~46
entreprises — pas de raison de matérialiser. Si un jour un historique de
score devient nécessaire (suivre l'évolution de la liquidité d'un titre sur
12 mois), ajouter alors une table `liquidity_score_history` alimentée par un
cron — noté ici, non fait.

**Réponse à la question posée : oui, la fonctionnalité implique 2 nouvelles
tables (`order_book_snapshots`, `intraday_execution_flow`), livrées dans une
seule migration `022_order_book_liquidity.sql`. Aucune table existante n'est
modifiée.**

---

## 4. Backend

### 4.1 `class/BulletinOrderBookService.php` (nouveau)

Parse la section « MARCHE DES ACTIONS — Quantité résiduelle » du texte déjà
extrait des BOC (`market_bulletin_contents.extracted_text` — le texte est DÉJÀ
en base, aucun re-téléchargement) et upsert dans `order_book_snapshots`.

- Format réel observé (BOC n°147 du 05/08/2026) :
  `BOAM   BANK OF AFRICA ML   27   5,585 / 5,590   272   5 585`
  → regex ancrée sur le symbole en début de ligne + « prix / prix » au
  centre ; nombres avec espace ou virgule comme séparateurs de milliers ;
- carnet vide d'un côté (valeur suspendue, réservée) → colonnes NULL, ligne
  quand même enregistrée (l'absence d'offre est une information de
  liquidité) ;
- rattachement par symbole (les symboles BOC = symboles `companies`, pas de
  fuzzy matching nécessaire — contrairement aux annonces) ;
- idempotent (UNIQUE uk_snapshot) : re-parser un bulletin ne duplique rien ;
- statut de parsing par bulletin (colonne à ajouter à
  `market_bulletin_contents` : `order_book_status` VARCHAR(20) NULL +
  `order_book_error` TEXT NULL — même pattern que `corporate_actions_status`,
  inclus dans la migration 022).

### 4.2 `class/ExecutionFlowBuilder.php` (nouveau)

Construit `intraday_execution_flow` depuis `intraday_quotes` pour un couple
(company_id, date) avec les 3 pièges du §3.2 encodés. Appelé :
- en fin de séance par le cron de sync existant (hook dans
  `BRVMSyncService` après le dernier relevé — ou cron dédié) ;
- en backfill par `scripts/backfill_execution_flow.php` (tout l'historique
  `intraday_quotes` existant) ;
- à la demande par l'API si la séance du jour n'est pas encore consolidée
  (les intervalles du jour en cours sont calculés à la volée et non
  persistés — persistés seulement une fois la séance close).

### 4.3 `api_order_book.php` (nouveau — pattern standard CORS → requires → AuthGuard → switch)

| Action | Rôle | Sources |
|---|---|---|
| `snapshots` | série des carnets fin de séance (période) : limites, résiduels, spread, imbalance | `order_book_snapshots` |
| `execution_flow` | intervalles d'une séance ou d'une période, filtrable par créneau horaire (`time_start`/`time_end`) | `intraday_execution_flow` |
| `timeline` | fusion chronologique lisible : relevés + Δ + événements carnet (« +800 à la vente ») | les 2 tables |
| `pressure` | agrégats pression acheteuse/vendeuse par séance ou par créneau (volumes signés par tick rule 🟧) | `intraday_execution_flow` |
| `heatmap` | matrice jour × tranche horaire (volume exécuté, ou pression nette) | `intraday_execution_flow` |
| `absorption` | croisement carnet veille × exécutions jour : taux et verdict prudent (§2) | les 2 tables |
| `liquidity_score` | score 0-100 + sous-scores + réponse « vendre N actions » (paramètre `quantity`) | tout |
| `anomalies` | z-score > seuil sur volume exécuté, spread, résiduels — liste datée avec valeurs | les 2 tables |
| `parse_bulletins` | (ré)extraction carnet des bulletins en base — backfill et rattrapage | `market_bulletin_contents` |

Contrainte `callApi` : toute réponse dans `data`. Lint 7.4 + 8.2 ET curl
réel obligatoires (PHP 7.4 sous Apache — pas de `match`, pas de promotion).

### 4.4 Indicateurs — définitions exactes

| # | Indicateur | Formule | Nature |
|---|---|---|---|
| 1 | Taux d'absorption | `volume exécuté jour J / ask_residual_qty (J-1)` — plafonné à l'affichage (« > 100 % » = l'offre affichée a tourné plusieurs fois) | 🟧 |
| 2 | Temps moyen d'absorption de N actions | `N / débit médian d'exécution (actions/heure, séances actives des 30 derniers jours, créneaux à volume > 0)` | 🟧 |
| 3 | Rotation du carnet | `volume exécuté jour / (bid_residual + ask_residual) fin de séance` | 🟨 |
| 4 | Pression vendeuse | hausse de `ask_residual_qty` sur n séances + part des intervalles intraday à pression 'vente' | 🟨+🟧 |
| 5 | Pression acheteuse | symétrique côté bid | 🟨+🟧 |
| 6 | Ratio achat/vente | `bid_residual_qty / ask_residual_qty` (et sa moyenne mobile 5 séances) | 🟨 |
| 7 | Persistance des ordres | nb de séances consécutives où `ask_residual_qty` reste > seuil sans exécutions suffisantes pour l'expliquer | 🟧 |
| 8 | Score de liquidité 0-100 | pondération : volume moyen échangé 25 / régularité (% séances avec volume > 0 sur 60 j) 20 / spread relatif moyen 20 / profondeur (résiduels × prix) 15 / taux d'absorption 10 / stabilité du spread (écart-type) 10 — même style de `WEIGHTS` const + clamp que `CompositeScoreCalculator` | 🟧 |

Le verdict final reprend le format demandé (« Liquidité : 82/100 —
Absorption : élevée — Temps estimé pour 1 000 actions : ~18 min ») avec
chaque ligne étiquetée 🟦/🟨/🟧.

---

## 5. Frontend — 2 nouveaux onglets dans `CompanyDashboard.tsx`

Deux onglets et non un : les natures de données sont différentes (intraday
observé vs quotidien + estimations), les mélanger dans un seul écran
brouillerait la distinction observé/estimé.

### 5.1 Onglet « Flux & pression » (étage 1)

- **Sélecteur de créneau** : Toute la séance / Matin / Après-midi /
  personnalisé (heure début-fin) — s'ajoute au sélecteur de dates global de
  la page (3 derniers jours de cotation par défaut, convention existante) ;
- **Graphique 1 — volumes exécutés** : barres par intervalle (~10 min),
  colorées par `pressure_side` (achat/vente/neutre — couleurs sémantiques
  `--chart-positive`/`--chart-negative`/`--chart-muted`), courbe de prix en
  overlay (axe Y séparé — leçon de l'incident « 3750 % ») ;
- **Graphique 3 — pression** : aire nette cumulée (volumes signés 🟧) avec
  bandeau explicatif de la tick rule et de ses limites ;
- **Graphique 4 — heatmap jour × tranche horaire** : intensité = volume
  exécuté ou pression nette (toggle) ;
- **Tableau détaillé** (celui de la demande) : Heure | Prix | Volume exécuté
  🟨 | Sens estimé 🟧 | Cumul séance — export visuel simple ;
- InfoPanel « comment lire ces chiffres » : ce qui est observé, ce qui est
  estimé, pourquoi on ne voit PAS le carnet en continu.

### 5.2 Onglet « Carnet & liquidité » (étages 2 + croisements)

- **Score de liquidité** en StatTiles (score global + sous-scores, style
  composite score) + bloc verdict « vendre N actions » avec input quantité ;
- **Graphique 1bis — évolution des résiduels** : deux courbes (résiduel
  achat / résiduel vente) par séance sur la période + cours de référence en
  overlay ;
- **Graphique spread & imbalance** : spread % et `imbalance_ratio` (zone
  > 0,5 = demande dominante) ;
- **Graphique 5 — timeline du carnet** : liste datée des variations
  notables (« 05/08 : +800 à la vente 🟨 — compatible annulations 🟧 ») ;
- **Absorption** : barres taux d'absorption par séance + anomalies
  signalées ;
- **Tableau multi-séances** : Date | Bid | Ask | Résiduel A | Résiduel V |
  Δ A 🟨 | Δ V 🟨 | Volume exécuté 🟦 | Lecture 🟧 ;
- badge de fraîcheur : « Carnet : fin de séance du JJ/MM (BOC n°X) » — ne
  jamais laisser croire à du temps réel.

### 5.3 Analyse IA (réutilisation de l'existant)

Ajouter 2 `chart_types` à `ChartAnalysisService` : `execution_flow` et
`order_book_liquidity` — mêmes règles maison (chiffres affichés uniquement,
`suggested_table`, distinction estimé/observé imposée dans le prompt).

---

## 6. Phases d'implémentation

### Phase 1 — Fondations données ✅ (implémentée et vérifiée le 14/08/2026)
- [x] Migration `022_order_book_liquidity.sql` (2 tables + 2 colonnes statut sur `market_bulletin_contents`) — et refléter dans `BD.sql` (convention `scripts/migrate.php` : BD.sql = schéma complet pour base vierge)
- [x] `BulletinOrderBookService` + action `parse_bulletins` + backfill des 19 bulletins en base
- [x] Vérification manuelle : snapshots BOAM/ETIT/BOAC vs PDF source (ETIT = cas volumes énormes 148 781 ; tester aussi une valeur suspendue)
- [x] `ExecutionFlowBuilder` + `scripts/backfill_execution_flow.php` sur tout l'historique `intraday_quotes`
- [x] Vérification des invariants : `SUM(executed_volume)` par séance ≈ `stock_quotes.volume` (écart = intervalle de clôture), aucun volume négatif, reset de veille bien détecté
- [x] Hook fin de séance dans le cron de sync

### Phase 2 — API
- [x] `api_order_book.php` : `snapshots`, `execution_flow`, `timeline`, `heatmap` (lecture pure)
- [x] `pressure`, `absorption`, `liquidity_score` (+ paramètre `quantity`), `anomalies`
- [x] Lint 7.4/8.2 + tests curl réels avec token temporaire (toutes les actions)

### Phase 3 — Frontend onglet « Flux & pression »
- [x] Types TS + onglet + sélecteur de créneau + graphiques 1/3/4 + tableau + InfoPanel
- [x] `tsc` + `vite build`

### Phase 4 — Frontend onglet « Carnet & liquidité »
- [x] Score + verdict N actions + graphiques résiduels/spread/absorption + timeline + tableau multi-séances + badge fraîcheur

### Phase 5 — IA & finitions
- [x] `chart_types` `execution_flow` + `order_book_liquidity` dans `ChartAnalysisService`
- [ ] Récupération de l'historique BOC plus profond (le scraper de bulletins sait paginer — étendre le stock de bulletins pour allonger les séries de carnets)
- [x] Documentation utilisateur dans les InfoPanels (légende 🟦🟨🟧 partout)

### Étage 3 (plus tard — non planifié)
- [ ] Source carnet intraday (compte SGI / flux payant) → snapshots intraday dans `order_book_snapshots` (`source` dédiée), les écrans étage 2 gagnent la granularité horaire sans migration

---

## 7. Limites assumées (à afficher, pas à cacher)

- Pas de carnet intraday public : l'évolution 09h30→10h30 des volumes en
  attente n'est pas observable — l'écran l'explique au lieu de l'inventer.
- Le BOC ne donne que la MEILLEURE limite de chaque côté, pas la profondeur
  complète (5 niveaux) : « profondeur du carnet » = profondeur à la
  meilleure limite, dit explicitement.
- La tick rule (sens acheteur/vendeur) est une heuristique reconnue mais
  faillible, surtout à ~10 min d'échantillonnage — toujours 🟧.
- L'échantillonnage ~10 min borne la résolution des créneaux horaires
  (pas de créneau < 10 min).
