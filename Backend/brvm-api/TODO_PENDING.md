# À faire — travaux planifiés, pas encore implémentés

Ce fichier centralise tout ce qui reste **à faire** (planifié, discuté,
mais pas encore codé), séparé de `TODO_ANALYSES.md` qui contient surtout
l'historique de ce qui est déjà `✅ FAIT` — mélanger les deux rendait
facile de perdre de vue ce qu'il reste réellement à implémenter au milieu
de 26 points déjà terminés. Nouvelle convention à partir du 10/08/2026 :
tout nouveau point encore à traiter va ici, pas dans `TODO_ANALYSES.md`
(qui reste le journal historique des chantiers terminés — voir la note en
tête de ce fichier-là).

Chantiers listés ici, du plus récent au plus ancien :

- **Annonces émetteurs & publications BRVM** — ✅ **FAIT le 11/08/2026**
  (demande utilisateur du jour). 3e pipeline documentaire complet, sur le
  modèle exact des bulletins : migration 021 (`issuer_announcements`/
  `_contents`/`_analyses`), `class/BRVMAnnouncementsScraper.php` (registre
  de 9 types — convocations d'AG, projets de résolution, notations,
  paiements de dividendes, communiqués, changements de dirigeants,
  franchissements de seuil, avis du marché, données économiques — avec 4
  variantes de parseur selon la structure du listing, pagination
  incrémentale ; `/fr/informations-permanentes` NON supportée : aucun
  contenu tabulaire côté HTML, rendu JS), rattachement entreprise à la
  découverte via `CompanySlugMatcher::matchCompanyName()`,
  `api_issuer_announcements.php` (types/discover/list/process/
  format_markdown en arrière-plan/analyze/get/get_analysis/download),
  `AnnouncementMarkdownFormatterService` + `IssuerAnnouncementAnalysisService`
  (mirror des services bulletins, analyse structurée : résumé, points
  clés, dates, montants, intérêt investisseur, glossaire), onglet
  « Annonces BRVM » du tableau de bord entreprise (découverte séquentielle
  type par type pour rester sous le timeout FastCGI, filtre par type,
  section optionnelle « annonces générales du marché », consultation
  markdown rendue, modal d'analyse). **Testé de bout en bout sur données
  réelles** : 25 annonces découvertes (rattachements exact/fuzzy corrects),
  PDF Nestlé téléchargé + extrait (1 522 caractères), markdown restructuré
  en arrière-plan (statut success), analyse Gemini exacte (dividende 420
  FCFA, ex-dividende 04/09, IRVM 12%). **Bug latent corrigé au passage
  dans api_bulletins.php aussi** : sous Apache/mod_fastcgi, PHP_BINARY
  peut être vide → la commande détachée de formatage markdown exécutait le
  script .php directement (« Permission denied », statut bloqué à
  'processing') — repli explicite sur le binaire CLI MAMP le plus récent.

- **Mon Équipe BRVM (portefeuille en 4-3-3)** — ✅ **FAIT le 11/08/2026**
  (étapes 1-5, utilisable de bout en bout — voir le compte rendu détaillé
  en tête de [`TODO_PORTFOLIO_TEAM.md`](TODO_PORTFOLIO_TEAM.md)). Seule
  l'étape 7 (analyse IA optionnelle de la composition) reste en attente,
  non bloquante.
- **Point 27 ci-dessous** — journal d'informations susceptibles
  d'affecter le cours (recherche IA + stockage). ✅ **FAIT le 11/08/2026**
  — voir le compte rendu inséré en tête de sa section.

---

## ✅ 27. Journal d'informations susceptibles d'affecter le cours — FAIT le 11/08/2026

**Livré** (conforme au plan ci-dessous, avec les décisions suivantes) :
- **Migration 019** : table `company_market_events` telle que spécifiée —
  journal PARTAGÉ entre admins (base de connaissance par entreprise, pas
  de scoping par utilisateur contrairement aux tables portfolio_* : une
  information de marché n'appartient pas à un compte). `created_by`
  conservé pour la traçabilité.
- **`api_company_market_events.php`** : actions `list` (filtrable
  période/impact, datés d'abord du plus récent au plus ancien),
  `add`/`update`/`delete`, et `search`. La recherche IA se fait en **2
  étapes** : (1) `generateChatReply()` avec recherche internet native
  (même mécanisme que le chat du tableau de bord) → texte + sources
  citées ; (2) `generateContent()` avec schéma structuré pour transformer
  ce texte en candidats {title, description, event_date, source_url} —
  plus fiable que d'exiger du JSON d'un appel avec outil de recherche
  (les fournisseurs ne combinent pas les deux proprement). `search` ne
  stocke JAMAIS rien : le frontend soumet chaque candidat relu/édité à
  `add` avec source_type='ia_recherche'.
- **Frontend** : nouvel onglet « Événements » dans le tableau de bord
  entreprise (`components/CompanyMarketEvents.tsx`) — recherche IA avec
  choix du fournisseur, candidats éditables un par un avant confirmation
  (« Confirmer et enregistrer » / « Ignorer »), sources cliquables, texte
  brut affiché quand 0 candidat (contient l'avertissement d'indisponibilité
  de la recherche), saisie manuelle, timeline avec badges impact
  (jugement UTILISATEUR, éditable en place) et origine (Manuel / IA
  confirmé), suppression avec confirmation.
- **Points tranchés** : pas de marqueur sur le graphe de cours en v1
  (event_date pas toujours fiable), pas de recherche non ciblée (par
  entreprise uniquement, comme proposé).

**Corrections de clients IA faites en route** (profitent aussi au chat du
tableau de bord) : xAI a DÉPRÉCIÉ la recherche live Grok (410 « Live
search is deprecated », même avec mode='off' — le paramètre
`search_parameters` déclenche l'erreur par sa seule présence) →
`GrokClient` n'envoie plus le paramètre qu'en cas de recherche demandée,
et son repli sans recherche couvre désormais 410/deprecated en plus des
quotas. Migration vers l'Agent Tools API xAI à faire si la recherche Grok
redevient nécessaire.

**Testé en HTTP réel (Apache PHP 7.4)** : CRUD complet vérifié (add avec
impact, list avec auteur, update d'impact, delete). Recherche IA : le
pipeline 2 étapes s'exécute de bout en bout, mais **aucun fournisseur ne
peut faire de vraie recherche web dans CET environnement aujourd'hui**
(quota de recherche Gemini épuisé côté Google AI Studio, compte xAI sans
crédits — 403, clé Anthropic invalide) — le système se comporte alors
exactement comme voulu : avertissement explicite dans le texte affiché,
et 0 événement inventé (l'extraction refuse de fabriquer des candidats
sans faits sourcés). À re-vérifier avec de vrais résultats une fois les
quotas/clés rétablis (production).

---

Plan d'origine ci-dessous, conservé pour référence.

## 27. Journal d'informations susceptibles d'affecter le cours (recherche + stockage) — NOUVEAU

**Demande utilisateur (10/08/2026)** : pouvoir stocker une information
importante découverte sur une entreprise cotée (pour garder un historique),
et pouvoir demander à l'IA d'aller chercher ce type d'information
elle-même — pas seulement analyser des données déjà en base comme partout
ailleurs dans l'app jusqu'ici.

**⚠️ Nécessite une nouvelle table** (`company_market_events` — journal
d'événements par entreprise, structuré, distinct des données de marché) —
détail ci-dessous.

**Bonne nouvelle : la partie "recherche IA" n'est pas à construire de
zéro.** Une session récente a déjà mis en place `AiChatClientInterface` +
`CompanyChatService` (`company_chat_messages`, migration 014) : chat IA
par entreprise avec **recherche internet native activée côté fournisseur**
(`generateChatReply(..., $withSearch)` chez `GeminiClient`), qui renvoie
déjà un texte rédigé + une liste de sources web citées (`sources` JSON,
`{title, url}`). C'est exactement le mécanisme qu'il faut pour "demander à
l'IA d'aller chercher des informations" — pas une nouvelle intégration IA,
une nouvelle **utilisation ciblée** de ce qui existe déjà, plus une couche
de curation pour transformer une réponse en texte libre en entrées
structurées et durables.

**Modèle de données proposé** :
```sql
CREATE TABLE company_market_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    -- Date de l'événement si connue (peut différer de la date de découverte/saisie) — NULL si non précisée par la source.
    event_date DATE NULL,
    source_type ENUM('utilisateur','ia_recherche') NOT NULL COMMENT 'Saisie manuelle vs trouvée par la recherche IA',
    source_url VARCHAR(500) NULL,
    -- Jugement de l'UTILISATEUR, jamais généré par l'IA à sa place (même principe que portfolio_thesis, voir TODO_PORTFOLIO_TEAM.md) — laisser vide si non tranché.
    impact_assessment ENUM('positif','negatif','neutre','indetermine') NULL,
    created_by_admin_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_admin_user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_company_date (company_id, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**À faire** :
1. **Migration** (table ci-dessus).
2. **`api_company_market_events.php`** (nouveau, même convention "un
   fichier par concern") — actions `list` (par entreprise, filtrable par
   période/impact), `add`/`update`/`delete` (saisie manuelle,
   `source_type='utilisateur'`).
3. **Recherche IA** — nouvelle action `search` (ou nouveau
   `CompanyMarketEventsSearchService`, à trancher selon la taille du
   résultat) : construit un prompt ciblé ("recherche les informations
   récentes — annonces, résultats, changements de direction, contrats,
   litiges... — susceptibles d'affecter le cours de {entreprise}"),
   appelle `generateChatReply()` (déjà avec recherche web activée),
   renvoie le texte + sources au frontend. **Étape de confirmation
   humaine obligatoire avant stockage** : l'IA propose, l'utilisateur
   choisit ce qu'il retient et édite avant sauvegarde dans
   `company_market_events` (`source_type='ia_recherche'`) — jamais
   d'écriture automatique en base depuis une réponse IA non relue, cohérent
   avec la philosophie déjà appliquée partout ailleurs dans l'app
   (l'IA analyse/suggère, l'utilisateur décide).
4. **Frontend** : nouvel onglet/section dans `CompanyDashboard.tsx` (qui
   héberge déjà `CompanyChatBot` pour la même entreprise) — bouton
   "Rechercher avec l'IA" + liste des résultats à confirmer/éditer/
   ignorer, formulaire de saisie manuelle, timeline chronologique des
   événements déjà enregistrés pour l'entreprise (type + impact +
   source cliquable).
5. Vérifier (`php -l`, `tsc`), tester la recherche IA sur données réelles
   (au moins une entreprise), documenter.

**Points à trancher avant de coder** :
- Faut-il relier un événement à une date de bourse précise pour pouvoir
  le superposer sur un graphe de cours (ex: marqueur sur le graphe
  Cotations le jour de l'événement) ? Séduisant visuellement mais
  `event_date` n'est pas toujours connue avec précision par la source.
- Faut-il aussi permettre une recherche non ciblée sur une entreprise
  (ex: "actualité BRVM du jour") plutôt que systématiquement par
  entreprise ? Hors périmètre v1 proposé ci-dessus, à confirmer.
