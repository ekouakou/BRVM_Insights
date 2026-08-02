# Comment fonctionne l'analyse IA d'un rapport, étape par étape

Ce document explique ce que fait réellement l'application quand tu appelles
`api_report_analysis.php` avec juste un `report_id` (et éventuellement un
`provider`/`model`) — tu n'écris jamais de prompt toi-même : **le prompt est
entièrement construit côté serveur**, à partir de données déjà en base. Ce
document explique ce que contient ce prompt et d'où viennent ses données.

Fichiers concernés :
- `api_report_analysis.php` — point d'entrée HTTP
- `class/ReportAnalysisService.php` — toute la logique (récupération des
  données, construction du prompt, appel IA, mise en cache)
- `class/GeminiClient.php` / `class/AnthropicClient.php` — appel HTTP au
  fournisseur IA choisi

---

## 1. Ce que tu envoies

Requête vers `api_report_analysis.php` :

```json
{
  "action": "analyze",
  "report_id": 187,
  "provider": "gemini",
  "model": "gemini-flash-lite-latest",
  "force_refresh": false
}
```

Seul `report_id` est obligatoire. Si `provider` est absent → `gemini` (le
défaut actuel, configurable dans `ReportAnalysisService::DEFAULT_PROVIDER`).
Si `model` est absent → le modèle par défaut de ce fournisseur
(`ReportAnalysisService::PROVIDERS`).

## 2. Étape par étape (`ReportAnalysisService::analyze()`)

### Étape 1 — Charger le rapport
```sql
SELECT * FROM company_reports WHERE id = :report_id
```
Colonnes utilisées ensuite : `company_id`, `report_type`, `title`, `publish_date`.

### Étape 2 — Charger le texte extrait du PDF
```sql
SELECT extracted_text, char_count FROM company_report_contents WHERE report_id = :report_id
```
C'est le texte que `scripts/backfill_reports.php` a extrait du PDF (via
`pdftotext` ou OCR) lors du backfill. **Si cette table n'a pas de ligne pour
ce rapport (texte pas encore extrait), l'analyse s'arrête ici** avec une
erreur qui te dit de lancer le backfill d'abord.

### Étape 3 — Charger l'entreprise
```sql
SELECT * FROM companies WHERE id = :company_id
```
Colonnes utilisées : `symbol`, `name`.

### Étape 4 — Déterminer la "date de contexte marché"
```sql
SELECT MAX(trading_date) FROM stock_quotes WHERE company_id = :company_id
```
C'est la date de la cotation la plus récente connue pour cette entreprise —
sert à savoir quel jour de `stock_quotes`/`technical_indicators` utiliser
comme contexte, et sert aussi de clé de cache (voir étape 5).

### Étape 5 — Vérifier le cache (évite de refacturer l'IA)
```sql
SELECT * FROM company_report_analyses
WHERE report_id = :report_id AND provider = :provider AND model = :model
  AND market_context_date <=> :market_context_date
LIMIT 1
```
Si une ligne existe déjà avec `status = 'success'` et que `force_refresh`
n'est pas demandé → **l'IA n'est jamais appelée**, le résultat déjà en base
est renvoyé tel quel. C'est pour ça que rappeler `analyze` le même jour sur le
même rapport (même fournisseur/modèle) est gratuit.

### Étape 6 — Charger le contexte marché (si pas de cache)
```sql
SELECT * FROM stock_quotes
WHERE company_id = :company_id AND trading_date = :market_context_date

SELECT * FROM technical_indicators
WHERE company_id = :company_id AND trading_date = :market_context_date
```
Colonnes utilisées de `stock_quotes` : `open_price`, `close_price`,
`high_price`, `low_price`, `variation_percent`, `volume`, `turnover`.
Colonnes utilisées de `technical_indicators` : `rsi_14`, `sma_20`, `sma_50`,
`sma_200`, `macd_line`, `macd_signal`.

### Étape 7 — Tronquer le texte du rapport si besoin
Le texte extrait est coupé à 500 000 caractères (`MAX_REPORT_CHARS`) — une
sécurité, quasiment jamais atteinte en pratique.

### Étape 8 — Construire le prompt (`buildPrompt()`)
**C'est ici que le "prompt que tu n'écris pas" est réellement fabriqué.** Le
gabarit exact (identique pour tous les fournisseurs) est reproduit en
totalité à la section 4 plus bas. En résumé, il assemble :
1. Un rôle fixe ("tu es un analyste financier senior...")
2. Le nom/symbole de l'entreprise et le type/date du rapport (étapes 1 et 3)
3. Le contexte marché formaté en phrase (étape 6)
4. La description exacte du JSON attendu en sortie (résumé, chiffres clés,
   SWOT, risques, thèse, etc.)
5. Le texte intégral (tronqué si besoin) du rapport (étape 2)

### Étape 9 — Appeler le fournisseur IA
`GeminiClient::generateContent($prompt, $model)` ou
`AnthropicClient::generateContent($prompt, $model)` selon `provider` —
POST HTTP vers l'API du fournisseur avec ce prompt en entrée, et une
contrainte technique pour forcer une sortie JSON strictement conforme
(`responseMimeType: application/json` côté Gemini, `output_config.format`
avec un schéma JSON précis côté Anthropic — voir section 5).

### Étape 10 — Enregistrer le résultat
```sql
INSERT/UPDATE company_report_analyses SET
  report_id, company_id, provider, model, market_context_date,
  summary, details (JSON), status, error_message, input_char_count, raw_response
```
`summary` = le résumé exécutif court (pour affichage rapide) ; `details` =
tout le reste (chiffres clés, SWOT, risques, thèse...) en un seul champ JSON ;
`raw_response` = la réponse brute du fournisseur, gardée pour audit/debug.

### Étape 11 — Renvoyer le résultat
La ligne fraîchement écrite est relue en base (pour récupérer
`created_at`/`updated_at` générés par MySQL) puis renvoyée au format de
réponse de l'API.

## 3. Résumé : tables lues vs table écrite

| Table | Usage | Colonnes clés |
|---|---|---|
| `company_reports` | lecture | `company_id`, `report_type`, `title`, `publish_date` |
| `company_report_contents` | lecture | `extracted_text`, `char_count` |
| `companies` | lecture | `symbol`, `name` |
| `stock_quotes` | lecture | `trading_date`, `open_price`, `close_price`, `high_price`, `low_price`, `variation_percent`, `volume`, `turnover` |
| `technical_indicators` | lecture | `trading_date`, `rsi_14`, `sma_20`, `sma_50`, `sma_200`, `macd_line`, `macd_signal` |
| `company_report_analyses` | lecture (cache) + écriture (résultat) | `report_id`, `provider`, `model`, `market_context_date`, `summary`, `details`, `status` |

## 4. Le prompt exact envoyé au modèle

Voici le gabarit produit par `ReportAnalysisService::buildPrompt()` — les
`$variables` sont remplacées par les vraies valeurs des étapes 1 à 7
ci-dessus :

```
Tu es un analyste financier senior et data analyst spécialisé sur les marchés
actions d'Afrique de l'Ouest (BRVM), avec une double expertise en lecture des
états financiers (normes SYSCOHADA/IFRS) et en analyse technique boursière.
Un client exigeant t'a confié la rédaction d'une note d'analyse approfondie,
du niveau d'une note de recherche actions professionnelle : précise,
quantitative, exhaustive, qui va au-delà d'un simple résumé. N'omets aucune
donnée chiffrée pertinente présente dans le texte, et calcule toi-même les
ratios/variations qui en découlent (croissance N/N-1, marges, ratios
d'endettement, etc.) plutôt que de te contenter de les recopier s'ils y sont,
ou de les laisser de côté s'ils n'y sont pas explicitement mais calculables.

Société : {symbole} - {nom entreprise}
Rapport analysé : {type de rapport} publié le {date de publication}

Contexte marché récent (BRVM) :
{phrase générée à partir de stock_quotes + technical_indicators, étape 6}

Réponds UNIQUEMENT avec un objet JSON de cette forme exacte (aucun texte
avant/après, pas de balises markdown) :

{
  "executive_summary": "synthèse dense en 4-8 phrases...",
  "company_overview": "activité, positionnement, actionnariat... ou null",
  "key_financials": { "currency", "revenue", "revenue_prior_year",
    "revenue_growth_percent", "net_income", "net_income_prior_year",
    "net_margin_percent", "ebitda", "total_debt", "total_equity",
    "debt_to_equity", "cash_position", "dividend_per_share", "roe_percent" },
  "financial_analysis": "analyse détaillée des chiffres ci-dessus...",
  "swot": { "strengths": [...], "weaknesses": [...], "opportunities": [...], "threats": [...] },
  "risks": [ { "category": "...", "description": "..." } ],
  "market_context_note": "le rapport confirme-t-il le cours/les indicateurs...",
  "technical_reading": "lecture technique factuelle, sans recommandation",
  "investment_thesis": { "bull_case", "bear_case", "key_watch_points": [...] },
  "data_quality_note": "ce qui manquait dans le texte, ou null",
  "glossary": [ { "term": "...", "explanation": "..." } ]
}

Règles impératives :
- N'invente JAMAIS un chiffre absent du texte : mets null plutôt que d'extrapoler.
- Distingue ce qui est extrait du texte de ce que tu calcules.
- Reste factuel et neutre : jamais de recommandation d'achat/vente explicite.
- N'inclus dans "glossary" que les termes réellement utilisés ailleurs.
- Réponds uniquement avec le JSON.

Texte du rapport :
{texte extrait du PDF, étape 2, tronqué à 500 000 caractères}
```

Ce même texte est envoyé tel quel, que le fournisseur soit Gemini ou
Anthropic — seule la mécanique pour forcer une sortie JSON valide diffère
(section suivante).

## 5. Différence Gemini vs Anthropic sur le "JSON forcé"

- **Gemini** (`class/GeminiClient.php`) : le prompt *décrit* le JSON attendu
  (ci-dessus), et l'appel passe `generationConfig.responseMimeType:
  "application/json"` — Gemini est forcé à répondre en JSON, mais rien ne
  garantit qu'il respecte exactement les champs demandés (juste une forte
  incitation via le prompt).
- **Anthropic** (`class/AnthropicClient.php`) : en plus du même prompt,
  l'appel passe `output_config.format` avec un **schéma JSON strict** (types,
  champs obligatoires, `additionalProperties: false`) — la réponse est
  validée structurellement par l'API elle-même, pas seulement suggérée par
  le prompt.

Dans les deux cas, ce que le modèle "reçoit" pour analyser n'est jamais
seulement `report_id` + `model` : c'est ce prompt complet (plusieurs
centaines de mots d'instructions + tout le texte du rapport), reconstruit à
chaque appel à partir des tables listées en section 3.
