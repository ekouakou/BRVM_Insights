# Analyse IA des graphes de comparaison

## ✅ Implémentation complète (05/08/2026, quatre tranches)

Infrastructure générique + intégration sur tous les chart_type du
périmètre + retrofit multi-fournisseurs des 3 systèmes d'analyse IA
préexistants du projet + granularité de sélection par ligne + vue
côte-à-côte + enrichissement du prompt de Comparaison + les 3 graphes de
`Comparison.tsx` (Cours comparés, Volumes intrajournaliers, Rotation du
flottant) + notation par étoiles et affichage d'historique redesigné (voir
4ème tranche ci-dessous).

**4ème tranche (sur demande)** : notation (1-5 étoiles) et commentaire
libre sur chaque entrée d'historique, + refonte visuelle de l'affichage de
l'historique, sur les 4 systèmes d'analyse IA du projet (nouveaux
graphes/tableaux ET les 3 systèmes préexistants) :
- `migrations/010_analysis_ratings.sql` — ajoute `rating` (TINYINT
  UNSIGNED NULL, 1-5) et `notes` (TEXT NULL) aux 4 tables d'analyse
  (`chart_analyses`, `company_report_analyses`,
  `company_report_comparisons`, `combined_analyses`). Pas de CHECK SQL
  (compat MySQL/MariaDB anciennes) — validation 1-5 côté PHP.
- Nouvelle méthode `rate(id, rating, notes, ratingProvided,
  notesProvided)` sur les 4 services, + nouvelle action `rate` sur les 4
  contrôleurs (`api_chart_analysis.php`, `api_report_analysis.php`,
  `api_report_comparison.php`, `api_combined_analysis.php`) — rating/notes
  ne sont modifiés que s'ils sont explicitement présents dans le payload
  (permet de mettre à jour l'un sans effacer l'autre). `rating`/`notes`
  ajoutés au `formatResult()` des 4 services. Testé contre données réelles
  (les 4 `rate()` + le cas d'erreur note hors bornes 1-5).
- Frontend : nouveau composant `components/ui.tsx::StarRating` (étoiles
  cliquables, mode lecture seule si `onChange` absent) et nouveau
  `components/AnalysisHistoryList.tsx` (liste générique réutilisée sur les
  4 systèmes) qui remplace l'ancien `<details><ul>` de simples liens par
  des cartes : badge provider/modèle coloré (gemini=bleu,
  anthropic=violet), date, badges cache/échec, aperçu (1 ligne tronquée du
  résumé), et étoiles cliquables par entrée — cliquer une étoile note
  immédiatement (pas de bouton "valider" séparé), sans recharger l'analyse
  affichée. Entrée actuellement affichée surlignée (`selectedId`). Étoiles
  également affichées sur le panneau de résultat couramment affiché (pas
  seulement dans la liste d'historique).
- Branché sur `ChartAiAnalysis.tsx` (donc tous les nouveaux
  graphes/tableaux) et sur `Analysis.tsx`/`Comparison.tsx`/`Combined.tsx`
  (y compris en mode côte-à-côte).
- Commentaire libre (`notes`) : colonne et action backend prêtes
  (`rate` accepte aussi `notes`), mais pas encore d'UI dédiée pour le
  saisir (seules les étoiles sont câblées côté frontend pour l'instant) —
  à ajouter si besoin (ex: petit champ texte sous les étoiles, sauvegarde
  au blur).

**Décisions utilisateur (2ème tranche)** :
- Graphe "Comparaison entre entreprises" de **Quotes.tsx** (page
  Cotations) : suppression considérée comme **intentionnelle**, non
  reconstruit sur cette page.
- Garde-fou de quota/fréquence sur les appels IA : **pas de limite pour
  l'instant**, point laissé de côté.

**3ème tranche (sur demande)** : les 3 graphes de `Comparison.tsx` (page
Comparaison — différente de Quotes.tsx, non concernée par la décision
ci-dessus) n'avaient aucun bouton d'analyse IA dédié — seule la section
"Comparaison de rapports (IA)" plus bas sur la même page en avait une
(enrichie en tranche 2, mais qui ne couvre que les rapports financiers,
pas directement les graphes). Ajout de 2 nouvelles entrées `METHODOLOGY`
(`intraday_volume`, `share_turnover`) et réutilisation de
`quotes_comparison` (qui restait dormant depuis la tranche 1, données de
même forme) — le registre passe de 13 à 15 chart_type :
- "Cours comparés" → `quotes_comparison` (déjà défini, jamais branché
  nulle part avant)
- "Volumes intrajournaliers" → `intraday_volume` (nouveau)
- "Rotation du flottant" → `share_turnover` (nouveau)

**Infrastructure `ChartAnalysisService` (nouveaux graphes/tableaux) :**
- `migrations/009_chart_analyses.sql` — appliquée en local (nouvelle table
  `chart_analyses`, aucune modif d'une table existante).
- `class/ChartAnalysisService.php` — générique sur `chart_type` (registre
  `METHODOLOGY`, 15 entrées, toutes branchées côté frontend — vérifié par
  recoupement `grep`), cache par
  `(request_hash, provider, model)`, `history()` filtrée à la sélection
  courante (paramètres exacts, pas une liste globale). Fournisseur par
  défaut `gemini` (défaut réel du projet partout ailleurs, y compris
  `ReportAnalysisService`).
- `api_chart_analysis.php` — actions `analyze`/`get`/`history`, miroir de
  `api_report_analysis.php`.
- `components/ChartAiAnalysis.tsx` — composant réutilisable (bouton,
  sélecteur provider gemini/anthropic, panneau résultat avec méthodologie
  expliquée, historique en `<details>` filtré à la sélection courante).
- Testé de bout en bout avec un vrai appel Gemini (clé ajoutée dans
  `.env`) : prompt, appel IA, conformité au schéma JSON, persistance DB,
  formatage — chaîne complète validée sur données réelles.

**Branché sur (15 chart_type, tous les menus) :**
- `Quotes.tsx` : `quotes_signals` (Signaux techniques, sélection de lignes
  par case à cocher), `quotes_close_sma` (Cours de clôture + SMA)
- `Statistics.tsx` : `total_variation`, `correlation`, `risk_adjusted`,
  `relative_strength`
- `MarketHealth.tsx` : `sector_performance`, `market_breadth`,
  `data_quality_reconciliation`, `data_quality_price_jumps`,
  `data_quality_missing_days`
- `Overview.tsx` : `market_summary`
- `Comparison.tsx` : `quotes_comparison`, `intraday_volume`,
  `share_turnover`

**Graphe "Comparaison entre entreprises" de Quotes.tsx** : confirmé par
l'utilisateur comme suppression intentionnelle — non reconstruit sur
cette page. `quotes_comparison` est maintenant utilisé sur `Comparison.tsx`
(voir 3ème tranche ci-dessus) — le chart_type n'est donc plus dormant.

**Granularité de sélection par ligne (2ème tranche)** — les 9 tableaux du
périmètre ont désormais une case à cocher par ligne (+ "tout
sélectionner/désélectionner"), avec la sélection intégrée aux
`parameters` envoyés (donc au `request_hash` : analyser un sous-ensemble
précis est mis en cache séparément d'une analyse du tableau entier) :
- `Quotes.tsx` → Signaux techniques (déjà fait en 1ère tranche)
- `MarketHealth.tsx` → Réconciliation (clé composite `symbole|date`),
  Sauts de prix (clé composite `symbole|horodatage`), Jours manquants
  (`company_id`) — bouton désactivé tant qu'aucune ligne n'est cochée.
- `Statistics.tsx` → Performance ajustée au risque et Corrélation : ces
  deux tableaux n'affichent déjà que les entreprises cochées dans le
  sélecteur global de la page (partagé avec les 2 autres graphes) — une
  sous-sélection indépendante a été ajoutée par-dessus (case à cocher par
  ligne/par entreprise de la matrice), pour restreindre l'analyse IA à un
  sous-ensemble sans changer la sélection globale. Vide = tout le tableau
  (comportement par défaut inchangé), non vide = restreint au
  sous-ensemble.
- `Overview.tsx` → Plus fortes hausses/baisses/Volumes : sélection
  partagée par symbole entre les 3 tableaux (dédoublonnage naturel d'une
  entreprise présente dans plusieurs listes), toujours branchée sur le
  bouton `market_summary` existant plutôt qu'un bouton par tableau — les
  statistiques globales de séance restent toujours incluses, seules les
  listes sont filtrées quand une sélection est active.

**Enrichissement du prompt de Comparaison (2ème tranche)** —
`ReportComparisonService::buildPrompt()` inclut désormais un bloc "Volume
échangé et rotation du flottant sur la période" (nouvelle méthode privée
`getTurnoverData()`, même requête SQL que
`api_quotes.php::getShareTurnover()`), pour que l'IA puisse pondérer un
mouvement de cours par sa liquidité réelle plutôt que par le seul cours
de clôture — couvre l'intention des cartes "Cours comparés"/"Rotation du
flottant" de `Comparison.tsx` sans dupliquer de système d'analyse séparé
pour elles. Testé contre données réelles (`getTurnoverData()` via
réflexion PHP).

**Vue côte-à-côte multi-fournisseurs (2ème tranche)** — sur les 3 pages
(`Analysis.tsx`, `Comparison.tsx`, `Combined.tsx`), une case à cocher
"Comparer 2 analyses de l'historique côte à côte" (visible dès 2 entrées
dans l'historique) fait apparaître 2 sélecteurs (Analyse A / Analyse B)
et affiche les deux résultats en 2 colonnes (`grid xl:grid-cols-2`)
plutôt qu'un seul à la fois. Rendu obtenu en extrayant le bloc JSX de
résultat de chaque page en composant (`AnalysisResultView`,
`ComparisonResultView`, `CombinedResultView`) pour l'instancier deux fois
sans dupliquer le code — même contenu que l'affichage single-résultat
existant, juste dédoublé.

**Retrofit multi-fournisseurs des 3 systèmes préexistants** (permet de
conserver et comparer les analyses Gemini vs Anthropic pour une même
sélection, extension demandée à l'ensemble du projet, pas seulement aux
nouveaux graphes) :
- `ReportAnalysisService.php` (`Analysis.tsx`, rapport individuel) :
  `history()` déjà présente, corrigée pour retourner le résultat complet
  (`formatResult()`, avec `id`) au lieu d'un résumé partiel — nécessaire
  pour réafficher une entrée d'historique sans nouvel appel API.
- `ReportComparisonService.php` (`Comparison.tsx`) : nouvelle méthode
  `history()` + action `history` sur `api_report_comparison.php`, même
  double-mode que `compare()` (report_ids explicite ou company_ids+dates).
- `CombinedAnalysisService.php` (`Combined.tsx`) : nouvelle méthode
  `history()` + action `history` sur `api_combined_analysis.php`.
- `id` ajouté au `formatResult()` des 3 services (absent auparavant,
  nécessaire comme clé React et pour une éventuelle action `get`-par-id
  future).
- Frontend : les 3 pages affichent désormais un historique cliquable
  (`<details>`, style cohérent avec `ChartAiAnalysis.tsx`) filtré à la
  sélection courante ; cliquer une entrée l'affiche sans reappeler l'IA
  (state `historyOverride`, prioritaire sur le dernier résultat de la
  mutation `analyze`/`compare`). Testé contre des données réelles
  existantes (`company_report_analyses`, `company_report_comparisons`,
  `combined_analyses`).
- Vue côte-à-côte disponible depuis la 2ème tranche — voir plus haut.

**Points restés hors scope, par choix explicite de l'utilisateur** :
reconstruction du graphe "Comparaison entre entreprises" (suppression
jugée intentionnelle), garde-fou de quota/fréquence sur les appels IA
(aucune limite pour l'instant).

**Vérifications** : `php -l` sur tous les fichiers touchés, `npx tsc -b`
et `npx vite build` sans nouvelle erreur (2 erreurs préexistantes et sans
rapport sur `Combined.tsx`/`Reports.tsx` confirmées via `git diff --stat`
comme non introduites par ce travail). `getTurnoverData()` et les
`history()` des 3 services testés via PHP CLI contre des données réelles.

---

# Version originale du document (avant implémentation) — conservée pour référence

Demande du 05/08/2026 : un bouton "Analyser avec l'IA" sur chaque graphe de
comparaison (entre entreprises, secteurs, etc.), avec conservation d'un
historique des analyses passées.

Mise à jour du 05/08/2026 : le périmètre couvre aussi les **tableaux**
(pas seulement les graphes), et pour ceux-ci l'IA doit en plus
**expliquer la méthodologie de calcul** derrière les chiffres affichés
(pas seulement les interpréter) — voir la nouvelle exigence commune
ci-dessous et la révision du périmètre "Santé & secteurs". L'historique
des analyses doit être filtré selon les éléments actuellement
sélectionnés, pas une liste globale (voir section Architecture). Les
tableaux doivent aussi permettre d'analyser une ligne, un sous-ensemble,
ou toutes les lignes (voir "Granularité de sélection").

Deuxième mise à jour du 05/08/2026 : possibilité de conserver et comparer
les analyses de **plusieurs fournisseurs/modèles IA** (Gemini vs
Anthropic) pour une même sélection, et **étendre ça à toutes les analyses
IA du projet**, pas seulement les nouveaux graphes/tableaux — voir la
section dédiée plus bas. Bonne nouvelle en creusant : les 3 tables
d'analyse existantes le permettent déjà côté base de données, il ne
manque qu'une vue de comparaison côté frontend.

## Extension : comparer plusieurs fournisseurs/modèles IA, sur toutes les analyses

Demande : pouvoir conserver et comparer les analyses de Gemini et
d'Anthropic (ou d'autres modèles) pour une même sélection, et étendre ça
à **l'ensemble des analyses IA du projet** — pas seulement les nouveaux
graphes/tableaux de ce document, mais aussi les 3 systèmes d'analyse déjà
existants (rapports individuels, comparaison de rapports, analyse
combinée).

### Constat en creusant le code existant

Bonne surprise : les 3 tables d'analyse déjà en place ont **déjà** une
contrainte qui permet à plusieurs fournisseurs/modèles de coexister pour
la même cible, sans rien changer côté base :

- `company_report_analyses` : `UNIQUE(report_id, provider, model,
  market_context_date)` — un même rapport peut avoir une ligne Gemini ET
  une ligne Anthropic en parallèle.
- `market_bulletin_analyses` : `UNIQUE(bulletin_id, provider, model)` —
  même principe.
- `combined_analyses` : `UNIQUE(request_hash, provider, model,
  computed_date)` — même principe.

Le vrai manque est **côté frontend**, et il est identique sur les 3
pages : `Analysis.tsx` et `Comparison.tsx` ont un sélecteur de provider
pour *lancer* une analyse (`gemini` par défaut, changeable), mais aucune
des 3 pages n'affiche plusieurs analyses stockées côte à côte — seul le
dernier résultat obtenu est montré. `Combined.tsx` n'a même pas de
sélecteur de provider (`'gemini'` codé en dur). Et côté backend,
`api_report_comparison.php` (Comparaison) et `api_combined_analysis.php`
(Analyse combinée) n'ont pas d'action `history` du tout — seuls
`compare`/`get`. Seul `api_report_analysis.php` (rapport individuel) a une
action `history`, mais elle n'est pas non plus consommée par le
frontend.

### Ce que ça implique concrètement

- **`chart_analyses` (nouvelle table, ce document)** : la concevoir avec
  la même contrainte dès le départ —
  `UNIQUE(request_hash, provider, model)` plutôt qu'un simple index sur
  `request_hash` — pour que Gemini et Anthropic puissent coexister sans
  s'écraser, cohérent avec les 3 tables existantes.
- **Les 3 tables existantes** : **aucune migration nécessaire**, la
  structure le permet déjà.
- **Backend** : ajouter l'action `history` manquante sur
  `api_report_comparison.php` et `api_combined_analysis.php` (le pattern
  existe déjà dans `api_report_analysis.php`, à répliquer).
- **Frontend** : sur les 4 écrans concernés (Analyse IA, Comparaison,
  Analyse combinée, + les nouveaux graphes/tableaux de ce document), une
  vue "Comparer les fournisseurs" — côte à côte ou par onglets — listant
  les analyses stockées pour la sélection courante, groupées par
  provider/model, plutôt que de n'afficher que la dernière obtenue.
  Potentiel composant réutilisable partagé avec `<ChartAiAnalysis>`
  (section Architecture plus bas) vu que le besoin est identique partout.

## Réponse directe à la question posée

**Oui, ça nécessite une nouvelle table** — aucune table existante ne
convient : `combined_analyses` est structurée autour de `report_ids`/
`bulletin_ids` (listes d'ID de documents texte), pas de séries de cours ou
de résultats de calcul (variation, corrélation...). Voir schéma proposé
plus bas (`chart_analyses`, migration 009). Aucune table existante à
modifier — uniquement une nouvelle table à créer, plus le fichier de
migration correspondant. Pas de risque pour les données existantes.

## Exigence commune à toutes les analyses : expliquer le calcul

Pour chaque `chart_type`, le prompt envoyé à l'IA doit systématiquement
inclure **la formule/méthode utilisée pour produire les chiffres
affichés** (déjà documentée en commentaire dans le code de chaque
endpoint — ex. `api_quotes.php::getRiskAdjustedPerformance()`,
`api_data_quality.php::checkReconciliation()`), et lui demander de la
restituer en langage clair dans sa réponse, en plus de son interprétation
des résultats. Deux avantages : ça rend l'IA pédagogique (utile pour
quelqu'un qui ne connaît pas la formule d'une corrélation de Pearson ou
d'un ratio rendement/risque), et ça réduit le risque d'hallucination —
l'IA rappelle la méthode plutôt que de deviner comment le chiffre a été
obtenu. Particulièrement important pour les 4 candidats "tableau" listés
ci-dessous (Signaux techniques, Contrôle qualité, Performance ajustée au
risque, Corrélation), où comprendre le calcul est souvent aussi utile que
le résultat lui-même.

## Granularité de sélection pour les tableaux

Pour les candidats "tableau" (Signaux techniques, les 3 tableaux de
Contrôle qualité, Performance ajustée au risque, Corrélation) : possibilité
d'analyser **une ligne, un sous-ensemble de lignes cochées, ou toutes les
lignes** — pas seulement "tout le tableau" comme prévu initialement. Les
graphes (Comparaison entre entreprises, Variation par secteur, Variation
totale, Force relative, Largeur de marché) ont déjà leur propre sélection
en amont (les cases à cocher qui déterminent quelles entreprises sont
tracées) et n'ont pas besoin de ce mécanisme séparément — cette section ne
concerne que les tableaux, qui aujourd'hui affichent systématiquement
toutes leurs lignes sans sélection possible.

**Ce que ça change concrètement :**

- **Frontend** : ajouter une case à cocher par ligne sur chacun des 9
  tableaux concernés (Signaux techniques, réconciliation, sauts de prix,
  jours manquants, performance ajustée au risque, corrélation, + les 3
  tableaux de Vue d'ensemble — Plus fortes hausses/baisses/Volumes, mêmes
  principe : sélectionner un sous-ensemble d'entreprises à inclure dans
  le "résumé de séance"), + un
  raccourci "Tout sélectionner"/"Tout désélectionner" (même pattern que
  les cases à cocher entreprises déjà utilisées sur Quotes.tsx/
  Statistics.tsx). Le bouton "Analyser avec l'IA" est désactivé tant
  qu'aucune ligne n'est cochée, et son libellé reflète le nombre coché
  (ex. "Analyser 1 ligne" / "Analyser 5 lignes" / "Analyser les 47
  lignes").
- **Backend** : `parameters` doit inclure la sélection exacte de lignes
  envoyée par le frontend. Chaque tableau n'a pas forcément un identifiant
  de ligne naturel en base — à définir par tableau :
  - Signaux techniques / Performance ajustée au risque : `company_id`
    (identifiant naturel, une ligne = une entreprise).
  - Corrélation : une "ligne" du tableau est une entreprise de la matrice
    (ses corrélations avec toutes les autres) — sélectionner un
    sous-ensemble de lignes revient à restreindre l'analyse à ce
    sous-ensemble d'entreprises parmi celles déjà comparées.
  - Réconciliation / Sauts de prix : pas d'ID naturel (ce sont des lignes
    calculées à la volée, pas lues depuis une table avec clé primaire) —
    utiliser une clé composite comme identifiant (`company_id` +
    `trading_date` pour la réconciliation, `company_id` +
    `quote_datetime` pour les sauts de prix), construite côté frontend à
    partir des données déjà affichées et transmise telle quelle.
  - Jours manquants : `company_id` (identifiant naturel).
  - Vue d'ensemble (hausses/baisses/volumes) : `company_id` (identifiant
    naturel, une ligne = une entreprise, potentiellement présente dans
    plusieurs des 3 tableaux à la fois — dédoublonner par `company_id`
    dans la sélection envoyée).
- **Cache/historique** : `request_hash` et le filtre d'historique (voir
  plus bas) doivent intégrer la sélection de lignes exacte — analyser 3
  entreprises précises sur "Signaux techniques" est une requête différente
  d'analyser les 47, même si le `chart_type` et la date sont identiques.

## Périmètre — revue complète menu par menu

Revu poste par poste sur le menu réel de l'app (`Layout.tsx`) — avec une
découverte importante : **trois pages ont déjà leur propre analyse IA**,
construite avant cette session (`ReportComparisonService.php` et
consorts). Le nouveau bouton "Analyser ce graphe" ne doit pas les
dupliquer, seulement couvrir ce qui n'a aucune lecture IA aujourd'hui.

### ✅ Déjà couvert par une analyse IA existante — ne pas dupliquer

- **Comparaison** (`/comparison`, `Comparison.tsx`) — section "Comparaison
  de rapports (IA)" déjà présente : classement comparatif, tendance par
  entreprise, corrélation cours/fondamentaux, évolution des risques,
  points d'appui à la décision. Ses graphes "Cours comparés", "Volumes
  intrajournaliers", "Rotation du flottant" n'ont PAS d'analyse dédiée
  eux-mêmes, mais vu que la page a déjà une analyse IA globale juste en
  dessous, un bouton supplémentaire par graphe ferait doublon — à la
  place, il vaudrait mieux enrichir le prompt existant pour qu'il tienne
  compte de ces 3 graphes plutôt que d'ajouter un système parallèle.
- **Analyse combinée** (`/combined`, `Combined.tsx`) — analyse IA complète
  déjà en place (performance par entreprise, évolution des indices,
  corrélation entreprise/marché, chronologie, points d'appui). Rien à
  ajouter.
- **Analyse IA** (`/analysis`, `Analysis.tsx`) — "Lecture technique" par
  rapport déjà en place (celle qu'on a corrigée plus tôt). Rien à ajouter.

### Candidats réels pour le nouveau bouton "Analyser ce graphe"

- **Cotations** (`/quotes`, `Quotes.tsx`) — 3 candidats indépendants :
  - Graphe "Cours de clôture" (+ SMA) d'une entreprise seule — lecture
    technique courte, différente de l'analyse de rapport (celle-ci se
    base sur le graphe/les indicateurs seuls, pas sur un rapport PDF).
  - "Comparaison entre entreprises" (le graphe visé dans la demande
    initiale) — tendances/divergences entre les entreprises sélectionnées.
  - Tableau "Signaux techniques" (toutes entreprises) — pas un graphe,
    mais candidat naturel pour une synthèse IA du type "quels signaux
    sont les plus significatifs aujourd'hui et pourquoi", **en expliquant
    aussi comment le score composite (-2 à +2) est calculé** (RSI, MACD,
    tendance, Bollinger — voir `api_signals.php::buildSignal()`) pour
    quelqu'un qui ne connaît pas la mécanique.
- **Vue d'ensemble** (`/`, `Overview.tsx`) — pas un graphe non plus, mais
  "Plus fortes hausses", "Plus fortes baisses", "Volumes les plus élevés"
  se prêtent bien à un "résumé de séance" IA (type synthèse de clôture de
  marché) — candidat à évaluer séparément, plus proche d'un "brief
  quotidien" que d'une "analyse de graphe".
- **Statistiques** (`/statistics`, `Statistics.tsx`) — 4 candidats :
  "Variation totale cumulée par jour" (barres hausses/baisses),
  "Corrélation entre titres" (matrice — expliquer ce qu'est une
  corrélation de Pearson et comment elle est calculée ici), "Performance
  ajustée au risque" (tableau — expliquer le ratio rendement net/volatilité
  cumulée), "Force relative vs indice BRVM-COMPOSITE".
- **Santé & secteurs** (`/market-health`, `MarketHealth.tsx`) — révisé :
  "Variation par secteur" **et** les 3 tableaux de contrôle qualité
  (réconciliation, sauts de prix, jours manquants) sont candidats. Pour
  ces derniers, le rôle de l'IA n'est **pas** de juger si une anomalie est
  grave (ça reste une décision humaine sur des données qui doivent rester
  exactes), mais d'**expliquer la méthode de détection** en langage clair
  (ex. "la réconciliation recalcule la variation à partir de clôture et
  clôture précédente, et signale un écart de plus de 0,5 point avec la
  valeur stockée" — voir `api_data_quality.php`) et de résumer ce que la
  liste actuelle montre (combien d'anomalies, sur quelles entreprises).
  "Largeur de marché" reste un candidat classique (interprétation de
  tendance, pas de méthodologie à documenter au-delà du calcul déjà
  simple : part de hausses/baisses par jour).

### Pas candidats du tout

- **Synchronisation** (`/sync`) — page opérationnelle (déclenchement
  manuel, historique de logs), rien à analyser.
- **Rapports** / **Bulletins** (`/reports`, `/bulletins`) — listes de
  documents, l'analyse se fait déjà au niveau de chaque rapport individuel
  via "Analyse IA".
- **Alertes de prix** (`/price-alerts`) — page de configuration CRUD, pas
  de graphe ni de série de données à interpréter.

## Architecture proposée

### 1. Nouvelle table `chart_analyses`

Suit le même schéma que `combined_analyses`/`company_report_analyses`
(déjà en place pour les analyses IA de rapports) pour rester cohérent
avec le reste du projet :

```sql
CREATE TABLE chart_analyses (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    chart_type VARCHAR(50) NOT NULL COMMENT 'ex: quotes_close_sma, quotes_comparison, quotes_signals, market_summary, total_variation, correlation, risk_adjusted, relative_strength, sector_performance, data_quality_reconciliation, data_quality_price_jumps, data_quality_missing_days, market_breadth',
    request_hash CHAR(64) NOT NULL COMMENT 'sha256(chart_type + paramètres normalisés) — détecte les requêtes identiques',
    parameters LONGTEXT NOT NULL COMMENT 'JSON des paramètres exacts (company_ids, sector, start_date, end_date, granularity, display_mode, selected_rows — identifiants ou clés composites des lignes cochées pour les tableaux...)',
    provider VARCHAR(30) NOT NULL DEFAULT 'anthropic',
    model VARCHAR(50) NOT NULL,
    summary TEXT NULL,
    details LONGTEXT NULL COMMENT 'JSON structuré : tendances, points notables, divergences détectées',
    status VARCHAR(20) NOT NULL DEFAULT 'success',
    error_message TEXT NULL,
    data_points_count INT NULL COMMENT 'taille des données envoyées, pour contexte/debug',
    raw_response LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chart_type (chart_type),
    UNIQUE KEY uk_request_provider_model (request_hash, provider, model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`migrations/009_chart_analyses.sql` — fichier de migration standard, comme
les précédents (`scripts/migrate.php` l'applique automatiquement).

### 2. Backend — nouveau `api_chart_analysis.php`

Même structure que `api_report_analysis.php` (déjà existant) :

- `analyze` : reçoit `chart_type` + `parameters` (+ les données déjà
  calculées par le graphe côté frontend, pour éviter de refaire les
  requêtes SQL côté serveur) → construit un prompt, appelle le
  fournisseur IA (`class/AnthropicClient.php` ou `class/GeminiClient.php`,
  déjà utilisés pour les rapports), stocke le résultat dans
  `chart_analyses`, le retourne.
  - Avant d'appeler l'IA : vérifie `(request_hash, provider, model)` — si
    une analyse existe déjà pour ce provider/modèle précis sur des
    paramètres identiques, la retourne directement (comme
    `ReportAnalysisService::analyze()` avec son `forceRefresh`) plutôt que
    de dépenser un appel IA pour rien. Un autre provider/modèle sur les
    mêmes paramètres déclenche bien un nouvel appel — c'est ce qui permet
    la comparaison (voir section dédiée plus haut).
- `history` : liste les analyses passées pour un `chart_type` **et les
  paramètres actuellement sélectionnés** (mêmes entreprises/dates/mode
  affichés à l'écran — comparaison exacte ou partielle sur `parameters`,
  à définir), triées par date décroissante. Confirmé : l'historique doit
  refléter ce qui est sélectionné maintenant, pas une liste globale toutes
  sélections confondues — sinon l'utilisateur verrait des analyses sans
  rapport avec ce qu'il regarde. Indépendant du cache par `request_hash`
  ci-dessus (qui sert à éviter un appel IA redondant, pas à filtrer
  l'affichage).
- `get` : récupère une analyse précise par ID (pour l'afficher sans la
  refaire).

**Ce qu'on envoie à l'IA** : pas une image du graphe, mais les données
structurées déjà calculées (même approche que `ReportAnalysisService`, qui
envoie du texte/JSON, jamais d'image) — plus fiable, moins cher, cohérent
avec l'infra IA déjà en place. Ex. pour "Comparaison entre entreprises" :
la même série `{symbol, data: [{date, price/variation}]}` que retourne
déjà `api_quotes.php` (action `compare`), envoyée telle quelle en JSON
dans le prompt, avec une consigne du type "identifie les tendances,
divergences notables entre entreprises, et tout point qui mérite
attention". Une vraie analyse d'image (vision) serait possible en
alternative plus tard (Claude/Gemini supportent la vision) mais demande
une capture du graphe rendu (canvas/svg → PNG) côté frontend — plus
complexe, pas nécessaire pour une v1 vu que les données structurées
portent déjà toute l'information du graphe.

### 3. Frontend — composant réutilisable

Un seul composant `<ChartAiAnalysis chartType="..." parameters={...}
data={...} />` à poser sur chaque graphe concerné plutôt que dupliquer la
logique 4-5 fois :

- Bouton "Analyser avec l'IA" (état loading pendant l'appel)
- Panneau résultat (résumé + détails, y compris l'explication de la
  méthode de calcul) une fois l'analyse reçue
- Section "Historique" (repliable) listant uniquement les analyses
  passées **pour la sélection actuelle** (mêmes paramètres) — si
  l'utilisateur change d'entreprises/de dates, la liste se met à jour en
  conséquence plutôt que de montrer tout l'historique du `chart_type`.
  Cliquer sur une entrée l'affiche sans reconsommer d'appel IA.

## Points d'attention avant d'implémenter

- **Clé API non configurée en local** : `.env` a `ANTHROPIC_API_KEY=` et
  `GEMINI_API_KEY=` vides ("non configuré pour l'instant" — voir
  commentaire existant dans `.env`). Cette fonctionnalité ne sera
  testable en local qu'une fois une clé renseignée (ou uniquement
  vérifiable en production, si une clé y est déjà configurée).
- **Coût/quota** : chaque clic sur "Analyser" = un appel IA facturé. Le
  cache par `request_hash` limite les doublons accidentels, mais pas de
  limite de fréquence prévue dans cette v1 — à voir si un garde-fou est
  nécessaire (ex: limite par jour) une fois en usage réel.
- **Cohérence des données envoyées** : pour un graphe avec beaucoup de
  points (ex. comparaison sur 1 an de plusieurs entreprises), le volume de
  données à envoyer à l'IA peut devenir important — peut nécessiter un
  résumé/échantillonnage côté backend avant l'envoi plutôt que la série
  brute complète (à trancher selon les volumes réels observés).

## Questions ouvertes à trancher avant de coder

1. Provider/modèle par défaut — même choix que les rapports
   (`anthropic`/`claude-opus-5`) ou un modèle plus léger/rapide vu que les
   données de graphe sont plus petites qu'un rapport PDF entier ? (Reste
   pertinent même avec la comparaison multi-fournisseurs : il faut un
   choix par défaut au premier clic, l'utilisateur pouvant ensuite lancer
   l'autre fournisseur pour comparer.)
2. Le périmètre ci-dessus (13 candidats répartis sur 4 pages — Cotations
   (3), Vue d'ensemble (1), Statistiques (4), Santé & secteurs (5, dont
   les 3 tableaux de contrôle qualité comptés séparément) — graphes ET
   tableaux inclus) convient-il tel quel, ou faut-il réduire à une
   première tranche plus petite pour une v1 (ex. seulement "Comparaison
   entre entreprises" + "Signaux techniques" pour commencer) ?
3. Pour **Comparaison** (`/comparison`) : enrichir le prompt IA existant
   pour qu'il couvre aussi "Cours comparés"/"Volumes intrajournaliers"/
   "Rotation du flottant", ou laisser cette page de côté pour l'instant et
   se concentrer sur les pages sans aucune IA ?
4. Pour les tableaux de contrôle qualité : le prompt doit-il recevoir le
   code source de la requête SQL concernée (pour une explication fidèle
   à 100%) ou une description en français de la méthode suffit-elle ?
   Envoyer le SQL est plus fiable mais expose un peu de détail
   d'implémentation dans le prompt (sans risque de sécurité vu que c'est
   un usage interne admin, mais à noter).
5. Faut-il un garde-fou de fréquence/quota dès la v1, ou seulement si un
   usage excessif est constaté ensuite ?
6. La vue "Comparer les fournisseurs" (nouvelle, voir section dédiée) :
   à construire en même temps que les nouveaux graphes/tableaux, ou
   d'abord sur les 3 analyses existantes (rapport, comparaison, combinée)
   qui n'ont actuellement aucune vue de ce type malgré une base de
   données déjà prête ? Vu qu'aucune migration n'est requise pour ces 3
   tables, ce pourrait être un gain rapide indépendant du reste de ce
   document.


   =======

   je veux une meilleur affichage des historique des analyse IA, je veux pouvoir notter et donnée des etoiles sur chaque historique
