# Guide complet — BRVM Insights API

Ce guide explique, en langage simple, à quoi sert cette application, comment elle
fonctionne, et ce que fait chaque API. Il est écrit pour quelqu'un qui découvre
le projet — aucune connaissance technique préalable n'est supposée.

---

## Sommaire

1. [C'est quoi, cette application ?](#1-cest-quoi-cette-application)
2. [Comment le projet est organisé](#2-comment-le-projet-est-organisé)
3. [Comment les données arrivent dans la base](#3-comment-les-données-arrivent-dans-la-base)
4. [Comment appeler une API (les bases)](#4-comment-appeler-une-api-les-bases)
5. [Tour complet des API](#5-tour-complet-des-api)
   - [5.1 api_companies.php — les entreprises](#51-api_companiesphp--les-entreprises)
   - [5.2 api_quotes.php — les cotations](#52-api_quotesphp--les-cotations)
   - [5.3 api_market.php — le marché et les indices](#53-api_marketphp--le-marché-et-les-indices)
   - [5.4 api_technical_indicators.php — l'analyse technique](#54-api_technical_indicatorsphp--lanalyse-technique)
   - [5.5 api_signals.php — les signaux d'achat/vente](#55-api_signalsphp--les-signaux-dachatvente)
   - [5.6 api_brvm_sync.php — la synchronisation](#56-api_brvm_syncphp--la-synchronisation)
   - [5.7 api_reports.php — les rapports des sociétés](#57-api_reportsphp--les-rapports-des-sociétés)
6. [Le tableau de bord (dashboard.html)](#6-le-tableau-de-bord-dashboardhtml)
7. [Les scripts en ligne de commande](#7-les-scripts-en-ligne-de-commande)
8. [La base de données, en résumé](#8-la-base-de-données-en-résumé)
9. [Tester avec Postman](#9-tester-avec-postman)
10. [Glossaire](#10-glossaire)
11. [Questions fréquentes](#11-questions-fréquentes)

---

## 1. C'est quoi, cette application ?

C'est une **API** (un programme qui répond à des questions posées sur internet,
sans interface visuelle propre) qui :

1. **Récupère automatiquement** chaque jour les cours de bourse de la BRVM
   (Bourse Régionale des Valeurs Mobilières, qui couvre 8 pays d'Afrique de
   l'Ouest : Côte d'Ivoire, Sénégal, Burkina Faso, Bénin, Togo, Niger, Mali,
   Guinée-Bissau) directement depuis le site officiel brvm.org.
2. **Stocke** ces données dans une base de données (MySQL), jour après jour,
   pour construire un historique.
3. **Calcule des analyses** dessus : indicateurs techniques (moyennes, RSI,
   MACD...), scores d'achat/vente, performance par secteur, etc.
4. **Récupère aussi les rapports financiers** (PDF) publiés par chaque
   société cotée, et en extrait le texte — pour pouvoir, plus tard, les faire
   analyser par une IA.
5. **Expose tout ça** via des API (des URL qu'on interroge avec des outils
   comme Postman, un navigateur, ou une application) et un tableau de bord
   web (`dashboard.html`).

**Analogie simple** : imagine un stagiaire qui, chaque jour après la fermeture
de la bourse, va relever tous les cours sur le site de la BRVM, les recopie
dans un grand classeur (la base de données), calcule des statistiques dessus,
et range aussi une copie de chaque rapport annuel publié par les entreprises.
Toi, tu n'as qu'à lui poser des questions ("quel est le cours de telle
action ?", "quelles sont les 10 entreprises qui montent le plus ?") et il te
répond instantanément à partir de son classeur.

---

## 2. Comment le projet est organisé

Pas de framework (pas de Laravel, Symfony...) : c'est du PHP "à la main", avec
une organisation simple par dossiers.

```
brvm-api/
├── api_*.php              → Chaque fichier est une "porte d'entrée" (endpoint) qu'on interroge
├── config.php              → Réglages généraux (base de données, sécurité, horaires du marché...)
├── cron_sync_brvm.php      → Script qui synchronise les données automatiquement
├── dashboard.html           → Le tableau de bord visuel (graphes, tableaux)
├── psman.json               → Collection Postman : toutes les requêtes prêtes à tester
├── BD.sql                   → Le plan complet de la base de données (pour une installation neuve)
├── migrations/               → Les évolutions de la base appliquées après coup
├── class/                    → Le "moteur" : tout le code réutilisable (scraping, calculs, base de données...)
├── scripts/                  → Des programmes à lancer soi-même (pas des API) : sync auto, backfill des rapports
├── storage/reports/          → Les PDF téléchargés (rangés par symbole d'entreprise)
└── logs/                      → Journaux de bord : tout ce qui s'est passé, pour comprendre en cas de souci
```

**Règle simple à retenir** : un fichier qui commence par `api_` = une API
qu'on interroge depuis l'extérieur (Postman, navigateur, appli mobile...). Un
fichier dans `scripts/` = un programme qu'on lance soi-même depuis le
terminal, pas via internet.

---

## 3. Comment les données arrivent dans la base

Rien n'est inventé ni rentré à la main : tout vient du site **brvm.org**, via
un "scraper" (un programme qui lit une page web et en extrait les
informations, comme si un robot recopiait un tableau à la main).

- **Cours des actions** : lu sur `brvm.org/fr/cours-actions/0` (une requête
  par synchronisation, qui ramène la liste complète des cotations). Comme les
  cours évoluent en cours de séance, la synchro se répète toutes les 15
  minutes pendant les heures de marché plutôt qu'une seule fois par jour.
- **Indices** (BRVM-30, BRVM-COMPOSITE, BRVM-PRESTIGE, BRVM-PRINCIPAL) : lus
  sur `brvm.org/fr/indices`.
- **Rapports des sociétés** (PDF) : lus sur la page dédiée de chaque
  entreprise, ex: `brvm.org/fr/rapports-societe-cotes/air-liquide-ci`.

Cette récupération se déclenche de deux façons :

1. **Automatiquement**, toutes les 15 minutes pendant les heures de marché
   (08:30-16:00, lundi-vendredi), via un job planifié sur le Mac (voir
   [`CRON_SETUP.md`](CRON_SETUP.md) pour tous les détails). C'est ce qui garde
   la base à jour sans que tu aies à faire quoi que ce soit.
2. **Manuellement**, en appelant l'API `api_brvm_sync.php` (action
   `sync_now`) ou en cliquant sur le bouton "Synchroniser maintenant" du
   tableau de bord.

Chaque synchronisation met à jour deux choses différentes :
- `stock_quotes` : une seule ligne par entreprise et par jour (écrasée à
  chaque passage) — sert à l'historique jour par jour et aux indicateurs
  techniques.
- `intraday_quotes` : une nouvelle ligne à chaque synchronisation (jamais
  écrasée) — permet d'observer la variation du cours au fil de la séance.
  Consultable via `api_quotes.php` (action `intraday`, avec `company_id` et
  éventuellement `trading_date`).

## 3bis. Analyse IA des rapports (multi-fournisseurs)

Une fois qu'un rapport a du texte extrait (`company_reports.text_extracted = 1`,
via `scripts/backfill_reports.php`), on peut demander à une IA d'en faire une
analyse approfondie façon note de recherche actions : résumé, chiffres clés
et ratios calculés, SWOT, risques catégorisés, thèse bull/bear, une mise en
perspective par rapport au cours et aux indicateurs techniques récents, et un
**`valuation_assessment`** (PER, BPA, rendement du dividende, verdict
"sous-coté" / "surcoté" / "correctement valorisé" — calculé seulement si le
nombre d'actions est mentionné dans le rapport, sinon "indéterminable").

Le service n'est pas lié à un seul fournisseur — **Gemini (Google) par
défaut**, ou **Anthropic (Claude)** via `"provider": "anthropic"`. Ajouter un
nouveau fournisseur (OpenAI, Grok, Kimi...) ne demande qu'une nouvelle classe
implémentant `AiClientInterface` (voir `class/AnthropicClient.php` /
`class/GeminiClient.php`), enregistrée dans
`ReportAnalysisService::PROVIDERS`.

- Nécessite une clé dans `.env` (copier `.env.example`) : `ANTHROPIC_API_KEY`
  ([console.anthropic.com/settings/keys](https://console.anthropic.com/settings/keys))
  et/ou `GEMINI_API_KEY`
  ([aistudio.google.com/apikey](https://aistudio.google.com/apikey)).
- `api_report_analysis.php` (action `analyze`, avec `report_id`, `provider?`,
  `model?`) déclenche l'analyse. Le résultat est mis en cache dans
  `company_report_analyses` (une ligne par rapport + fournisseur + modèle +
  jour) : le rappeler le même jour ne refacture pas l'IA (sauf
  `force_refresh: true`). Actions `get` (lecture du cache, sans jamais
  appeler l'IA) et `history` (évolution des analyses dans le temps, tous
  fournisseurs confondus) sont aussi disponibles.
- L'analyse est informative, pas un conseil en investissement (rappelé dans
  chaque réponse via le champ `disclaimer`).
- Chaque analyse individuelle inclut aussi `chart_data.price_history` (180
  derniers jours de cours, même format que `api_quotes.php` action `ohlc`) —
  de quoi tracer un graphe de contexte sans requête supplémentaire.

## 3ter. Comparaison de rapports sur une période

Pour aller au-delà d'un seul rapport : `api_report_comparison.php` (action
`compare`) compare les rapports d'**une ou plusieurs entreprises** publiés
sur une période donnée (`start_date`/`end_date`, `report_type?` optionnel,
`company_ids` ou `symbols`). L'IA reçoit les analyses individuelles déjà
extraites de chaque rapport (pas le texte brut) et produit une lecture
approfondie : tendance financière dans le temps par entreprise
(`trend_analysis`), comparaison entre entreprises si plusieurs
(`cross_company_ranking`), lien avec la performance boursière
(`price_correlation_note`), évolution des risques (`risks_evolution`), et des
points d'appui à la décision (`decision_support_notes`, toujours informatifs,
jamais un conseil d'achat/vente).

La réponse inclut `chart_data` (`price_series` par entreprise sur la période,
`financials_series` : CA/résultat net/marge par rapport successif) — prêt à
tracer côté frontend. Comme pour l'analyse individuelle, le résultat est mis
en cache par jour (action `get` pour relire sans appeler l'IA).

---

## 4. Comment appeler une API (les bases)

Toutes les API de ce projet fonctionnent de la même façon :

- On envoie une requête **POST** (avec un outil comme Postman, ou du code) à
  l'adresse du fichier, par exemple :
  `http://localhost/BRVM_Insights/Backend/brvm-api/api_market.php`
- On met dans le corps de la requête (le "body", au format JSON) une clé
  `"action"` qui dit ce qu'on veut faire, plus éventuellement d'autres
  paramètres.
- L'API répond toujours en JSON, avec au minimum une clé `"success"`
  (`true`/`false`) et une clé `"data"` qui contient le résultat.

**Exemple concret** — obtenir la vue d'ensemble du marché :

Requête envoyée à `api_market.php` :
```json
{
  "action": "overview"
}
```

Réponse reçue :
```json
{
  "success": true,
  "data": {
    "date": "2026-07-31",
    "statistics": {
      "total_companies": 47,
      "gainers_count": "22",
      "losers_count": "15",
      "avg_variation": "0.62"
    },
    "top_gainers": [ ... ],
    "top_losers": [ ... ]
  }
}
```

Le moyen le plus simple de tester tout ça sans écrire de code : ouvrir
**Postman**, importer le fichier `psman.json` (voir [section 9](#9-tester-avec-postman)),
et cliquer sur "Send" sur n'importe quelle requête déjà préparée.

---

## 5. Tour complet des API

### 5.1 `api_companies.php` — les entreprises

Gère les fiches d'identité des sociétés cotées (nom, symbole, secteur, pays,
capitalisation...).

| Action | À quoi ça sert | Paramètres utiles |
|---|---|---|
| `list` | Liste toutes les entreprises, avec pagination | `page`, `per_page`, `active` |
| `get` | Détail d'une entreprise précise | `id` **ou** `symbol` |
| `search` | Recherche par mot-clé dans le nom | `query` |
| `by_sector` | Toutes les entreprises d'un secteur donné | `sector_id` |
| `by_country` | Toutes les entreprises d'un pays donné | `country_id` |
| `stats` | Statistiques sur une entreprise | `id` |
| `create` | Ajoute une nouvelle entreprise | `symbol`, `name`, ... |
| `update` | Modifie une entreprise existante | `id`, + champs à changer |
| `delete` | Désactive une entreprise (pas de suppression réelle) | `id` |

**Exemple** — trouver l'entreprise dont le symbole est "BICC" :
```json
{ "action": "get", "symbol": "BICC" }
```

---

### 5.2 `api_quotes.php` — les cotations

Gère l'historique des cours (ouverture, clôture, plus haut, plus bas,
volume...) jour par jour.

| Action | À quoi ça sert | Paramètres utiles |
|---|---|---|
| `latest` | Dernière cotation de **toutes** les entreprises | `page`, `per_page`, `sort`, `order` |
| `company_history` | Historique complet d'une entreprise sur une période | `symbol` ou `company_id`, `days` ou `start_date`/`end_date` |
| `company_latest` | Juste la toute dernière cotation d'une entreprise | `symbol` ou `company_id` |
| `date_range` | Cotations de toutes les entreprises sur une plage de dates | `start_date`, `end_date` |
| `compare` | Compare l'évolution de plusieurs entreprises entre elles | `symbols` (liste), `days` |
| `ohlc` | Données Open/High/Low/Close pour tracer un graphique en chandelier | `symbol` ou `company_id`, `days` |

**Exemple** — historique des 30 derniers jours de BICC :
```json
{ "action": "company_history", "symbol": "BICC", "days": 30 }
```

---

### 5.3 `api_market.php` — le marché et les indices

Vue d'ensemble du marché : qui monte, qui baisse, quels secteurs performent,
et les indices BRVM.

| Action | À quoi ça sert | Paramètres utiles |
|---|---|---|
| `overview` | Résumé du jour : stats globales + top 3 hausses/baisses/volumes | `date` (optionnel) |
| `top_gainers` | Les entreprises qui montent le plus | `limit`, `date` |
| `top_losers` | Les entreprises qui baissent le plus | `limit`, `date` |
| `volume_leaders` | Les entreprises les plus échangées (en volume) | `limit`, `date` |
| `sector_performance` | Performance moyenne par secteur d'activité | `date` |
| `market_breadth` | Amplitude du marché (nb hausses/baisses) sur plusieurs jours | `days`, `end_date` |
| `indices` (`sub_action: list`) | Liste des indices suivis (BRVM-30, BRVM-COMPOSITE...) | — |
| `indices` (`sub_action: values`) | Valeur actuelle de chaque indice | `date` |
| `indices` (`sub_action: history`) | Historique d'un indice précis | `code` (ex: `BRVM-30`), `days` |
| `heatmap` | Toutes les cotations du jour, regroupées par secteur (pour une carte de chaleur) | `date` |

> ⚠️ Note : `sector_performance` et `heatmap` ne renverront des résultats
> intéressants que si les entreprises ont un secteur renseigné en base — ce
> qui n'est pas encore fait automatiquement par le scraper (limite connue).

**Exemple** — valeurs actuelles des indices :
```json
{ "action": "indices", "sub_action": "values" }
```

---

### 5.4 `api_technical_indicators.php` — l'analyse technique

Le plus gros fichier de l'API (35 indicateurs). Chaque indicateur est une
formule mathématique classique utilisée en analyse boursière pour repérer des
tendances, des zones de sur-achat/survente, de la volatilité, etc.

| Action | À quoi ça sert |
|---|---|
| `calculate` | Calcule plusieurs indicateurs d'un coup pour une entreprise |
| `get_indicators` | Récupère les indicateurs déjà calculés et stockés en base (table `technical_indicators`, alimentée automatiquement à chaque synchro) |

Puis, indicateur par indicateur (tous suivent le même principe : on donne
`company_id`, une `period`, et un nombre de `days` d'historique) :

| Catégorie | Indicateurs disponibles |
|---|---|
| Moyennes mobiles | `sma` (moyenne simple), `ema` (moyenne exponentielle) |
| Oscillateurs | `rsi`, `stochastic`, `williams_r`, `cci`, `mfi`, `ultimate` |
| Tendance & momentum | `macd`, `adx`, `aroon`, `roc`, `trix` |
| Volatilité | `bollinger`, `atr`, `keltner`, `donchian` |
| Volume | `obv`, `ad_line`, `cmf`, `force_index`, `vwap` |
| Support & résistance | `pivot_points`, `fibonacci`, `support_resistance`, `parabolic_sar` |
| Système complet | `ichimoku` (nuage d'Ichimoku) |
| Avancés | `volume_profile`, `chaikin_oscillator`, `elder_ray`, `mass_index`, `vortex` |

Pas besoin de connaître chaque formule par cœur : la collection Postman
(section "5. Indicateurs Techniques") contient un exemple prêt à l'emploi
pour chacun. Si tu veux juste une synthèse simple prête à l'emploi, regarde
plutôt **`api_signals.php`** ci-dessous, qui combine les indicateurs les plus
utiles en une seule réponse facile à lire.

**Exemple** — RSI sur 14 jours pour l'entreprise n°1 :
```json
{ "action": "rsi", "company_id": 1, "period": 14, "days": 100 }
```

---

### 5.5 `api_signals.php` — les signaux d'achat/vente

C'est la brique "aide à la décision" : elle combine plusieurs indicateurs
techniques (RSI, MACD, tendance, Bollinger) déjà calculés et stockés en base,
pour donner **un seul score simple** par entreprise, de **-2** (vente forte) à
**+2** (achat fort).

⚠️ **Ce n'est pas un conseil financier** : c'est une synthèse mécanique de
formules classiques, à prendre comme un indice parmi d'autres, pas une
vérité absolue. Et tant que l'historique de cotations est trop court (moins
de 10-35 jours selon l'indicateur), le score affiche "Indéterminé" — c'est
normal, il faut laisser le temps à la base de se remplir.

| Action | À quoi ça sert | Paramètres utiles |
|---|---|---|
| `list` | Score de **toutes** les entreprises, triées par force du signal | `date` (optionnel) |
| `get` | Score d'une seule entreprise | `symbol` ou `company_id` |
| `history` | Évolution du score dans le temps pour une entreprise | `symbol`, `days` |

**Exemple** — signal du jour pour BICC :
```json
{ "action": "get", "symbol": "BICC" }
```

Réponse (simplifiée) :
```json
{
  "success": true,
  "data": {
    "symbol": "BICC",
    "score": 1,
    "label": "Achat",
    "details": {
      "rsi": { "value": 28.4, "signal": 1, "reason": "RSI < 30 (survendu)" },
      "macd": { "signal": 1, "reason": "MACD au-dessus de sa ligne de signal" }
    }
  }
}
```

---

### 5.6 `api_brvm_sync.php` — la synchronisation

C'est le "chef d'orchestre" qui déclenche la récupération des données
fraîches depuis brvm.org et les enregistre en base.

| Action | À quoi ça sert |
|---|---|
| `sync_now` | Lance immédiatement une synchronisation complète (cotations + indices + recalcul des indicateurs techniques) |
| `sync_status` | Donne le résultat de la toute dernière synchronisation |
| `sync_history` | Historique des synchronisations passées (succès/échecs) |
| `check_market_status` | Dit si le marché BRVM est actuellement ouvert ou fermé |

**Exemple** — forcer une synchronisation maintenant :
```json
{ "action": "sync_now" }
```

Réponse (simplifiée) :
```json
{
  "success": true,
  "quotes": { "processed": 47, "inserted": 0, "updated": 47, "failed": 0 },
  "indices": { "processed": 4, "inserted": 4, "updated": 0, "failed": 0 },
  "indicators_recomputed": 47
}
```

> C'est la même chose qui se déclenche automatiquement chaque jour via le
> job planifié — voir [`CRON_SETUP.md`](CRON_SETUP.md).

---

### 5.7 `api_reports.php` — les rapports des sociétés

Donne accès aux rapports financiers (PDF) téléchargés et à leur **texte déjà
extrait** — pensé pour être ensuite envoyé à une IA pour résumé/analyse.

| Action | À quoi ça sert | Paramètres utiles |
|---|---|---|
| `list_companies` | Liste les entreprises pour lesquelles des rapports ont été collectés, avec le nombre de rapports | — |
| `list` | Liste des rapports d'une entreprise (titres, dates, type) — sans le texte complet, pour rester léger | `symbol` ou `company_id`, `report_type` (optionnel) |
| `get` | Détail complet d'un rapport, **avec le texte extrait** | `id` (identifiant du rapport) |
| `stats` | Statistiques globales de la collecte (combien de rapports, combien avec texte, répartition par type) | — |

**Types de rapport** (`report_type`) : `annuel`, `semestriel`, `trimestriel`,
`etats_financiers`, `attestation_cac`, `autre`.

**Exemple** — récupérer le texte d'un rapport pour l'envoyer à une IA :
```json
{ "action": "get", "id": 1 }
```

Réponse (simplifiée) :
```json
{
  "success": true,
  "data": {
    "company": { "symbol": "SIVC", "name": "AIR LIQUIDE COTE D'IVOIRE" },
    "title": "ERIUM CI : Etats financiers - Exercice 2025",
    "report_type": "etats_financiers",
    "publish_date": "2026-06-26",
    "extraction_method": "text",
    "extracted_text": "BILAN SYSTEME NORMAL ..."
  }
}
```

`extraction_method` indique **comment** le texte a été obtenu :
- `text` : lu directement dans le PDF (fiable, PDF "numérique").
- `ocr` : le PDF était un scan (image), le texte a été reconnu par
  reconnaissance optique de caractères — généralement fiable mais peut
  contenir de petites erreurs, surtout sur des scans de mauvaise qualité.

> Cette collecte ne se fait **pas** automatiquement chaque jour : elle se
> lance à la main via `scripts/backfill_reports.php` (voir
> [section 7](#7-les-scripts-en-ligne-de-commande)), car elle peut prendre du
> temps et ne change pas d'un jour à l'autre.

---

## 6. Le tableau de bord (`dashboard.html`)

Une page web toute prête qui affiche visuellement les données, sans avoir
besoin d'utiliser Postman. Pour l'ouvrir :

```
http://localhost/BRVM_Insights/Backend/brvm-api/dashboard.html
```

Ce qu'on y trouve :
- Les valeurs actuelles des indices BRVM-30/COMPOSITE/PRESTIGE/PRINCIPAL
- Un résumé du marché du jour (hausses/baisses, volume, turnover)
- Les tableaux top hausses / top baisses
- La performance par secteur
- Les signaux d'achat/vente pour toutes les entreprises
- Un graphique du cours d'une action au choix (menu déroulant)
- Un bouton **"Synchroniser maintenant"** pour forcer une mise à jour des
  données sans passer par Postman

---

## 7. Les scripts en ligne de commande

Ce ne sont **pas** des API — on ne les appelle pas depuis un navigateur ou
Postman, mais depuis le Terminal, sur la machine où est installé le projet.

### `cron_sync_brvm.php`

Le script de synchronisation quotidienne (cotations + indices + indicateurs).
C'est ce que le job planifié (launchd) exécute automatiquement chaque jour —
voir [`CRON_SETUP.md`](CRON_SETUP.md) pour la mise en place complète.

Pour le lancer à la main :
```bash
/Applications/MAMP/bin/php/php8.2.0/bin/php cron_sync_brvm.php
```

### `scripts/run_daily_sync.sh`

Le "wrapper" utilisé par le job automatique : il vérifie que MAMP/MySQL est
démarré (et le démarre si besoin) avant de lancer `cron_sync_brvm.php`.

### `scripts/backfill_reports.php`

Le script qui va chercher les rapports (PDF) des sociétés cotées, les
télécharge, et en extrait le texte (voir [section 5.7](#57-api_reportsphp--les-rapports-des-sociétés)).
Contrairement à la synchro quotidienne, **celui-ci se lance à la main**,
de temps en temps (les rapports ne changent pas tous les jours) :

```bash
# Rattache les entreprises à leur page de rapports + télécharge tout
php scripts/backfill_reports.php

# Juste le rattachement entreprise ↔ page de rapports, sans télécharger
php scripts/backfill_reports.php --match-only

# Ne traiter qu'une seule entreprise (pratique pour tester)
php scripts/backfill_reports.php --symbol=BICC

# Corriger/forcer manuellement le rattachement d'une entreprise
php scripts/backfill_reports.php --set-slug=SYMBOLE:le-slug-sur-brvm.org
```

Il est **relançable sans risque** : il ne retéléchargera pas ce qui est déjà
téléchargé, ni ne réextraira ce qui est déjà extrait — utile pour reprendre
après une interruption, ou pour aller chercher les nouveaux rapports publiés
depuis la dernière fois.

---

## 8. La base de données, en résumé

Pas besoin de connaître le SQL pour comprendre l'essentiel — voici les tables
qui comptent, en langage simple :

| Table | Contient |
|---|---|
| `companies` | La fiche d'identité de chaque société cotée (nom, symbole, secteur, pays...) |
| `stock_quotes` | Une ligne par entreprise et par jour : cours d'ouverture, de clôture, volume, variation... |
| `market_indices` | La liste des indices suivis (BRVM-30, BRVM-COMPOSITE...) |
| `index_values` | Une ligne par indice et par jour : sa valeur, sa variation |
| `technical_indicators` | Les indicateurs techniques déjà calculés (SMA, RSI, MACD...), un par entreprise et par jour |
| `sync_logs` | L'historique de chaque synchronisation (réussie, échouée, combien de lignes traitées) |
| `company_reports` | La liste des rapports PDF trouvés pour chaque entreprise (titre, date, lien) |
| `company_report_contents` | Le texte extrait de chaque rapport (table séparée car ça peut être volumineux) |
| `sectors` / `countries` | Les listes de référence (secteurs d'activité, pays de la zone UEMOA) |

---

## 9. Tester avec Postman

[Postman](https://www.postman.com/) est un logiciel gratuit qui permet
d'envoyer des requêtes à une API sans écrire de code, et de voir la réponse.

**Étapes :**
1. Installer Postman (gratuit).
2. Menu **File → Import**, choisir le fichier `psman.json` du projet.
3. Toutes les requêtes de ce guide apparaissent déjà organisées par
   catégorie (Synchronisation, Entreprises, Cotations, Marché, Indicateurs
   Techniques, Signaux, Rapports des sociétés).
4. Cliquer sur une requête, puis sur **Send** : la réponse s'affiche
   immédiatement en bas.
5. Les valeurs comme le symbole de test (`BICC`) ou l'URL de base sont des
   **variables** modifiables en haut à droite de Postman (icône œil), pour
   ne pas avoir à les changer requête par requête.

---

## 10. Glossaire

| Terme | Explication simple |
|---|---|
| **BRVM** | Bourse Régionale des Valeurs Mobilières — la bourse commune à 8 pays d'Afrique de l'Ouest (siège à Abidjan) |
| **API** | Un programme qu'on interroge à distance (par une URL) pour obtenir ou envoyer des données |
| **Endpoint** | Un point d'entrée précis d'une API (ici, chaque fichier `api_*.php`) |
| **JSON** | Un format de texte simple pour échanger des données (clé : valeur), utilisé par toutes les réponses de ce projet |
| **Scraping** | Récupérer automatiquement des informations affichées sur un site web, comme si un robot lisait la page |
| **OHLC** | Open/High/Low/Close : les 4 prix clés d'une cotation (ouverture, plus haut, plus bas, clôture) |
| **Cron / launchd** | Un mécanisme qui déclenche automatiquement un programme à une heure précise, sans intervention humaine |
| **Indicateur technique** | Un calcul mathématique fait à partir des cours passés, utilisé en bourse pour repérer des tendances (ex: RSI, MACD) |
| **RSI** | Indicateur qui dit si une action est "survendue" (potentiel rebond) ou "surachetée" (potentiel repli), sur une échelle de 0 à 100 |
| **MACD** | Indicateur de tendance basé sur la comparaison de deux moyennes mobiles |
| **OCR** | Reconnaissance optique de caractères — transformer le texte visible sur une image/scan en texte exploitable par un ordinateur |
| **Backfill** | Rattraper/compléter des données historiques manquantes en une fois, plutôt qu'au fil de l'eau |
| **Upsert** | Contraction de "update" + "insert" : mettre à jour une ligne si elle existe déjà, sinon la créer |

---

## 11. Questions fréquentes

**Les scores de signaux affichent tous "Indéterminé", c'est normal ?**
Oui si la base ne contient pas encore assez de jours d'historique consécutifs
(il en faut au moins 10 à 35 selon l'indicateur). Ça se remplit tout seul au
fil des synchronisations quotidiennes.

**`sector_performance` et la heatmap sont vides, pourquoi ?**
Parce qu'aucune entreprise n'a de secteur renseigné en base pour l'instant —
c'est une limite connue du scraper actuel (il ne détecte que le pays, pas le
secteur). Il faudrait les assigner manuellement via `api_companies.php`
(action `update`) pour débloquer ces vues.

**Comment forcer une mise à jour des cours immédiatement ?**
Soit le bouton "Synchroniser maintenant" du dashboard, soit l'action
`sync_now` de `api_brvm_sync.php`, soit lancer `cron_sync_brvm.php` en ligne
de commande.

**Où sont stockés les identifiants de la base de données ?**
Dans `config.php` (constante `DB_CONFIG`). `class/DbConnect.php` les lit
automatiquement depuis là, pas besoin de les dupliquer ailleurs.

**Comment savoir si la synchro automatique fonctionne bien ?**
Consulter `logs/launchd_sync.log`, ou appeler `api_brvm_sync.php` avec
l'action `sync_status` / `sync_history`. Détails complets dans
[`CRON_SETUP.md`](CRON_SETUP.md).

**Un rapport a `extraction_error` rempli, ça veut dire quoi ?**
Que le texte n'a pas pu être extrait — le plus souvent parce que le PDF est
un scan de mauvaise qualité et que même l'OCR n'y a rien trouvé
d'exploitable. Le fichier PDF original reste téléchargé dans
`storage/reports/` si tu veux le consulter directement.



https://raw.githubusercontent.com/ekouakou/BRVM_Insights/main/parent-responsable/version.json

