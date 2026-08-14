<?php
/**
 * Analyse IA générique des graphes/tableaux de comparaison de l'app (voir
 * TODO_CHART_AI_ANALYSIS.md) — un seul service pour tous les chart_type
 * plutôt qu'une classe par graphe, pour que brancher un nouveau graphe se
 * résume à ajouter une entrée dans METHODOLOGY plutôt qu'à dupliquer la
 * logique d'appel IA/cache/historique (déjà dupliquée 3 fois dans le
 * projet — ReportAnalysisService/BulletinAnalysisService/
 * CombinedAnalysisService — ce service s'inspire directement de leur
 * pattern commun sans en ajouter une 4e variante).
 *
 * Le frontend envoie les données déjà calculées (celles que le graphe
 * affiche déjà, obtenues via les endpoints existants) plutôt que de les
 * recalculer ici — ce service ne fait qu'analyser, jamais de requête SQL
 * sur les cours/indicateurs eux-mêmes.
 */
class ChartAnalysisService {
    private const PROVIDERS = [
        'anthropic' => ['class' => 'AnthropicClient', 'default_model' => 'claude-opus-5'],
        'gemini' => ['class' => 'GeminiClient', 'default_model' => 'gemini-flash-lite-latest'],
        'grok' => ['class' => 'GrokClient', 'default_model' => 'grok-4-fast-reasoning'],
    ];
    private const DEFAULT_PROVIDER = 'gemini';
    private const DISCLAIMER = "Analyse générée automatiquement à titre informatif, "
        . "ne constitue pas un conseil en investissement.";

    // Bornes du contexte "rapports financiers" optionnel (voir
    // buildReportContext()) — un prompt IA a une taille limitée, et le coût
    // d'un appel augmente avec le nombre de tokens envoyés : mieux vaut
    // tronquer proprement (avec un marqueur explicite) que de risquer un
    // prompt trop volumineux pour une sélection large d'entreprises.
    private const MAX_REPORT_COMPANIES = 15;
    private const MAX_REPORT_CHARS_PER_COMPANY = 15000;
    private const MAX_DOCUMENTS_PER_COMPANY = 3;
    private const MAX_DOCUMENT_CHARS = 15000;
    private const MAX_SELECTED_REPORTS = 5;

    /**
     * Méthode de calcul par chart_type, en français — envoyée telle quelle
     * à l'IA (qui doit la restituer en langage clair dans sa réponse, voir
     * buildPrompt()) et sert aussi de registre des chart_type supportés.
     * À étendre au fur et à mesure qu'on branche de nouveaux graphes/
     * tableaux (voir TODO_CHART_AI_ANALYSIS.md, section "Périmètre").
     */
    private const METHODOLOGY = [
        'quotes_comparison' =>
            "Cours (ou variation en % depuis le début de la période affichée) de chaque entreprise " .
            "sélectionnée, au jour le jour ou par relevé intrajournalier selon le mode choisi. En mode " .
            "pourcentage, chaque entreprise est recalée à 0% au premier point de la période (indexée base " .
            "100 dans le calcul interne, affichée en écart en % pour rester lisible malgré des échelles de " .
            "prix très différentes d'une entreprise à l'autre).",
        'quotes_signals' =>
            "Score composite de -2 (vente forte) à +2 (achat fort) par entreprise, moyenne arrondie de " .
            "jusqu'à 4 signaux techniques disponibles : RSI(14) (<30 = signal d'achat/survendu, >70 = signal " .
            "de vente/suracheté), MACD (ligne au-dessus/en-dessous de sa ligne de signal = momentum " .
            "haussier/baissier), tendance (cours au-dessus/en-dessous de sa moyenne mobile SMA20 ou SMA10), " .
            "Bandes de Bollinger (cours au-dessus/en-dessous des bandes = potentiel retour à la moyenne). " .
            "Un signal peut manquer si l'historique de l'entreprise est trop court pour le calculer — le " .
            "score se base alors sur les signaux disponibles uniquement (voir api_signals.php::buildSignal()). " .
            "Un score fort (±2) sur un titre classé 'Illiquide' (champ liquidity, basé sur le volume moyen et " .
            "la part de jours sans transaction sur 30 jours) est automatiquement plafonné à ±1 " .
            "(confidence_penalized_by_liquidity=true dans ce cas) : un cours figé faute d'acheteur/vendeur " .
            "produit des indicateurs trompeurs, pas un vrai signal fort. atr_relative_percent (ATR 14 jours " .
            "rapporté au cours, en %) donne le contexte de volatilité récente du titre, sans influencer le score.",
        'quotes_close_sma' =>
            "Cours de clôture quotidien d'une entreprise, avec ses moyennes mobiles simples (SMA) superposées " .
            "— chaque point de la SMA-N est la moyenne des N derniers cours de clôture (N=10, 20 ou 50 selon " .
            "les courbes activées). Un cours au-dessus de sa SMA est généralement lu comme une tendance " .
            "haussière, en-dessous comme baissière ; un croisement entre deux SMA de périodes différentes " .
            "(ex. SMA10 qui croise SMA20) est un signal de changement de tendance classique.",
        'total_variation' =>
            "Pour chaque entreprise et chaque jour : somme de la valeur absolue des variations en % entre " .
            "relevés intrajournaliers successifs (~toutes les 10 min en séance), séparée en hausses cumulées " .
            "et baisses cumulées (affichées en négatif). Mesure combien un titre a « bougé » dans la journée " .
            "(churn/volatilité intrajournalière), pas où il a fini — un titre à +5% puis -3% puis +6% puis " .
            "-4% a une variation nette de +4% mais une variation totale d'environ 18%. Le premier relevé du " .
            "jour démarre l'accumulateur à 0 (l'écart avec la clôture de la veille n'est pas compté).",
        'correlation' =>
            "Corrélation de Pearson entre les séries de variation quotidienne (%) de chaque paire d'entreprises " .
            "sélectionnées, sur les jours de bourse communs aux deux (90 derniers jours). Va de -1 (évoluent " .
            "toujours en sens strictement opposé) à +1 (évoluent toujours ensemble) ; proche de 0 = pas de " .
            "relation linéaire détectable. Utile pour évaluer la diversification d'un portefeuille : des " .
            "titres très corrélés n'apportent pas de diversification l'un par rapport à l'autre.",
        'risk_adjusted' =>
            "Rendement net sur la période ((dernier cours de clôture - premier cours de clôture) / premier " .
            "cours) divisé par la volatilité cumulée (somme de la variation totale quotidienne — voir " .
            "'total_variation' — sur la même période). Un ratio plus élevé signifie un meilleur rendement " .
            "pour un même niveau d'agitation du cours ; deux titres au même rendement net peuvent avoir des " .
            "ratios très différents si l'un est monté en ligne quasi droite et l'autre en oscillant fortement.",
        'liquidity_history' =>
            "Historique quotidien du score de liquidité d'une entreprise (api_quotes.php, action " .
            "'liquidity_history') — pour chaque jour de bourse de la période, volume moyen ('avg_volume') et " .
            "part de jours sans transaction ('zero_volume_ratio', en %) calculés sur une fenêtre glissante de " .
            "30 jours calendaires se terminant ce jour-là ('window_trading_days' = nombre de jours de bourse " .
            "réellement présents dans cette fenêtre, peut être inférieur à 30 en début de période ou sur un " .
            "titre peu actif), avec le label résultant ('liquidity' : Illiquide si plus de 30% de jours sans " .
            "transaction dans la fenêtre, sinon Faible sous 200 titres/jour en moyenne, Moyenne entre 200 et " .
            "2000, Élevée au-dessus). Contrairement à un badge unique pour toute la période (voir chart_type " .
            "'screener', champ 'liquidity'), cette série permet de repérer une liquidité qui se dégrade ou " .
            "s'améliore dans le temps — utile pour distinguer un titre durablement peu liquide d'un titre qui " .
            "vient de traverser une phase calme (assèchement récent des échanges) ou au contraire qui vient de " .
            "regagner en activité.",
        'relative_strength' =>
            "Pour chaque entreprise et chaque jour : variation quotidienne (%) du titre moins variation " .
            "quotidienne (%) de l'indice BRVM-COMPOSITE le même jour (champ 'relative_strength' des données " .
            "fournies). Positif = le titre a surperformé le marché ce jour-là, négatif = sous-performé. " .
            "BRVM-COMPOSITE regroupe l'ensemble des sociétés cotées à la BRVM, pondérées par leur " .
            "capitalisation boursière — sa variation quotidienne réelle est fournie telle quelle dans " .
            "'index_variation_percent' (identique pour toutes les entreprises à une date donnée) : appuie-toi " .
            "dessus pour distinguer un titre qui surperforme un marché déjà haussier d'un titre qui ne fait " .
            "que reculer moins vite qu'un marché baissier.",
        'execution_flow' =>
            "Flux d'exécution intraday d'une entreprise (api_order_book.php, action 'execution_flow') : le volume " .
            "publié par brvm.org pendant la séance étant CUMULATIF, chaque intervalle (~10 min) porte le nombre " .
            "d'actions réellement échangées (executed_volume, un CALCUL exact sur des cumuls observés — pas une " .
            "estimation), le cours en fin d'intervalle, et un sens estimé par tick rule (pressure_side 'achat' si " .
            "le prix a monté pendant l'intervalle, 'vente' s'il a baissé, null sinon). RÈGLE ABSOLUE : distingue " .
            "toujours les volumes échangés (faits) du sens acheteur/vendeur (estimation heuristique faillible, " .
            "surtout à ~10 min d'échantillonnage) — ne présente jamais la pression comme un décompte d'ordres " .
            "réels. is_closing_auction=1 marque le fixing de clôture (écart entre dernier cumul et volume officiel).",
        'order_book_liquidity' =>
            "Photographies quotidiennes du carnet d'ordres en FIN de séance (api_order_book.php, action " .
            "'snapshots'), extraites du Bulletin Officiel de la Cote : quantités résiduelles et meilleures limites " .
            "à l'achat (bid) et à la vente (ask), cours de référence, spread, ratio d'équilibre " .
            "(imbalance_ratio = demande / (demande + offre), > 0,5 = demande dominante). Seule photographie " .
            "publique du carnet BRVM — ni temps réel, ni profondeur au-delà de la meilleure limite. RÈGLE " .
            "ABSOLUE : une baisse de l'offre résiduelle entre deux séances ne prouve PAS des achats — elle peut " .
            "venir d'exécutions, d'annulations ou de modifications d'ordres ; confronte-la au volume réellement " .
            "échangé du jour (executed_volume_day) avant toute lecture, et formule les conclusions comme des " .
            "hypothèses (« compatible avec une absorption par le marché »), jamais comme des faits. bid_at_market/" .
            "ask_at_market=1 signalent des ordres « au marché » (quantité sans prix limite).",
        'daily_ohlc' =>
            "Cotations quotidiennes d'une entreprise en chandeliers (api_quotes.php, action 'ohlc') : pour chaque " .
            "séance, cours d'ouverture, plus haut, plus bas, clôture, variation en %, nombre de titres échangés et " .
            "valeur transigée en FCFA — toutes des données OBSERVÉES publiées par la BRVM. Un corps de chandelier " .
            "vert signifie clôture au-dessus de l'ouverture, rouge en dessous ; la mèche relie le plus bas au plus " .
            "haut de la séance. RÈGLE ABSOLUE : à la BRVM, beaucoup de séances ont plus haut = plus bas = clôture " .
            "(un seul prix traité dans la journée) — ne commente PAS une amplitude nulle comme une stabilité " .
            "remarquable, c'est le signe d'un titre peu animé. Distingue toujours le nombre de titres de la valeur " .
            "transigée (500 titres à 29 000 F pèsent plus que 5 000 titres à 100 F). Ne prédis jamais le prochain " .
            "mouvement à partir de figures chartistes.",
        'dividend_yield' =>
            "Dividendes des entreprises cotées (api_dividends.php) : montant par action et date de paiement " .
            "OBSERVÉS dans les Bulletins Officiels de la Cote, rendement CALCULÉ (dividende ÷ dernier cours de " .
            "clôture). Fournis : classement par rendement, prochains détachements, saisonnalité mensuelle et " .
            "couverture des données. RÈGLE ABSOLUE : la couverture est partielle (seuls les bulletins déjà " .
            "analysés) — une entreprise absente de la liste n'est PAS une entreprise sans dividende, dis-le " .
            "explicitement. Rappelle qu'un rendement élevé peut venir d'un cours qui a chuté plutôt que d'une " .
            "hausse du dividende, et que le cours baisse mécaniquement du montant du dividende le jour du " .
            "détachement (ce n'est pas une perte). Ne projette jamais un dividende futur à partir d'un seul " .
            "versement passé.",
        'volatility_analysis' =>
            "Volatilité et risque des entreprises cotées (api_volatility.php) : volatilité annualisée (écart-type " .
            "des variations quotidiennes × √250), volatilité glissante, recul maximal depuis un sommet " .
            "(drawdown), amplitude moyenne haut-bas, performance de la période et nuage risque/rendement. Tous " .
            "ces chiffres sont CALCULÉS sur les cours observés. RÈGLE ABSOLUE : la volatilité décrit l'agitation " .
            "PASSÉE, ce n'est jamais une prévision — n'annonce pas de mouvement futur. Vérifie systématiquement " .
            "le champ returns_count / low_confidence : sur un historique court, un seul jour agité fait bondir la " .
            "volatilité, dis-le au lieu de commenter le chiffre comme s'il était stable. Explique en langage " .
            "simple (agitation du cours, secousses, patience nécessaire) plutôt qu'en jargon statistique.",
        'liquidity_ranking' =>
            "Classement de TOUTES les entreprises actives sur une dimension du moteur de liquidité " .
            "(api_order_book.php, action 'ranking') : facilité de vente (score 0-100 estimé), pression vendeuse ou " .
            "acheteuse en % des échanges classés (tick rule), titres en attente à la vente / à l'achat au carnet de " .
            "fin de séance, volume réellement échangé, spread moyen. Le critère de tri actif est fourni dans " .
            "'criterion'. RÈGLE ABSOLUE : commente le classement affiché sans inventer de causes (une pression " .
            "vendeuse élevée décrit le sens des échanges observés, elle n'explique pas POURQUOI) ; rappelle que " .
            "les pressions sont estimées et qu'un titre peu échangé peut afficher des pourcentages extrêmes sur " .
            "très peu de transactions — vérifie le volume avant de conclure. Les entreprises non classables sont " .
            "absentes de la liste, ne les traite jamais comme des zéros.",
        'liquidity_comparison' =>
            "Comparaison inter-entreprises de liquidité (api_order_book.php, action 'compare') : pour chaque " .
            "entreprise sélectionnée, un score de liquidité 0-100 ESTIMÉ (pondération : valeur échangée 25%, " .
            "régularité des échanges 20%, spread 20%, profondeur du carnet 15%, absorption des ventes 10%, " .
            "stabilité du spread 10% — sous-scores volume/profondeur en percentile du marché, donc relatifs aux " .
            "autres titres cotés) et des séries quotidiennes CALCULÉES : volume exécuté (delta des cumuls " .
            "observés), spread % et équilibre du carnet en fin de séance (imbalance_ratio = demande / (demande + " .
            "offre), > 0,5 = demande dominante). RÈGLE ABSOLUE : le score est une estimation relative au marché " .
            "sur l'historique récent, pas une garantie d'exécution — dis pour chaque entreprise ce qui tire son " .
            "score vers le haut ou le bas en citant les sous-scores fournis, et rappelle qu'un titre peu liquide " .
            "peut bloquer un vendeur plusieurs séances. Ne jamais interpréter une baisse d'offre résiduelle comme " .
            "des achats certains (exécutions et annulations indistinguables).",
        'market_indices' =>
            "Cours de clôture quotidien officiel d'un ou plusieurs indices BRVM (BRVM-COMPOSITE — l'ensemble " .
            "des sociétés cotées, pondérées par capitalisation boursière — BRVM-30, BRVM-PRESTIGE, " .
            "BRVM-PRINCIPAL selon la sélection), avec variation quotidienne en % (api_market.php, action " .
            "'history'). Deux modes d'affichage possibles côté frontend : niveau brut de l'indice (les indices " .
            "n'ont pas la même base ni la même échelle entre eux, donc peu comparables visuellement en niveau " .
            "brut), ou variation cumulée en % depuis le premier jour de la période sélectionnée (indexée à 0% " .
            "à ce premier jour) — ce second mode est celui à privilégier pour comparer la performance relative " .
            "de plusieurs indices entre eux sur une même période, précise-le si la sélection comporte plusieurs " .
            "indices. À distinguer de 'sector_performance' (indices sectoriels internes équipondérés, " .
            "recalculés par l'application) : ici il s'agit des indices BRVM officiels tels que publiés.",
        'sector_performance' =>
            "Indice équipondéré par secteur d'activité : pour chaque secteur et chaque jour, moyenne de " .
            "l'indice base 100 (cours du jour / cours au premier jour de la période × 100) de toutes les " .
            "entreprises actives de ce secteur. Affiché en écart en % par rapport à 100 (donc par rapport au " .
            "début de la période) pour rester lisible. Compare la performance de secteurs entiers plutôt que " .
            "d'entreprises individuelles ; chaque entreprise pèse le même poids dans son secteur (pas pondéré " .
            "par capitalisation).",
        'market_breadth' =>
            "Pour chaque jour de bourse : nombre d'entreprises actives en hausse, en baisse, et inchangées " .
            "(par rapport à leur clôture de la veille). La largeur de marché indique si un mouvement de " .
            "l'indice est porté par une majorité de titres (mouvement « sain », largement partagé) ou par " .
            "seulement quelques valeurs (mouvement plus fragile, susceptible de s'inverser).",
        'data_quality_reconciliation' =>
            "Contrôle de cohérence : recalcule la variation en % à partir de (clôture du jour - clôture de la " .
            "veille) / clôture de la veille, et la compare à la variation stockée en base (récupérée " .
            "directement depuis brvm.org). Un écart de plus de 0,5 point entre les deux est remonté comme " .
            "anomalie — ça ne veut pas forcément dire que la donnée est fausse (previous_close peut légitimement " .
            "différer selon le contexte), mais mérite une vérification humaine.",
        'data_quality_price_jumps' =>
            "Compare chaque relevé intrajournalier au relevé précédent de la même entreprise (~10 minutes plus " .
            "tôt en séance), et signale les écarts de plus de 15% entre deux relevés consécutifs. Un tel saut " .
            "peut être une vraie transaction sur un titre peu liquide (un seul échange peut fortement déplacer " .
            "le prix quand il y a peu d'acheteurs/vendeurs), ou une erreur de récupération des données — ce " .
            "contrôle ne tranche pas lequel, il liste seulement les cas à vérifier.",
        'data_quality_missing_days' =>
            "Pour chaque entreprise active : compare le nombre de jours, sur la période, où AU MOINS une " .
            "entreprise du marché a une clôture enregistrée (le calendrier de référence, déduit des données " .
            "elles-mêmes) au nombre de jours où CETTE entreprise précise en a une. Un écart signale une " .
            "synchronisation qui a échoué spécifiquement pour cette entreprise ce jour-là (pas une panne " .
            "générale du marché, qui affecterait toutes les entreprises pareil), ou une suspension de cotation.",
        'market_summary' =>
            "Statistiques agrégées de la séance du jour : nombre d'entreprises en hausse/en baisse/inchangées, " .
            "variation moyenne du marché, volume total échangé, plus les 3 classements — plus fortes hausses, " .
            "plus fortes baisses, volumes les plus élevés (chacun basé sur la variation ou le volume du jour " .
            "par rapport à la clôture de la veille).",
        'intraday_volume' =>
            "Volume échangé par entreprise sélectionnée, relevé toutes les ~10 minutes pendant la séance (même " .
            "source que les cours intrajournaliers). Permet de repérer les moments de la journée où un titre " .
            "s'échange le plus (ouverture, clôture, annonce) et de comparer l'activité entre entreprises sur la " .
            "même période — un pic isolé peut correspondre à une transaction ponctuelle sur un titre peu liquide " .
            "plutôt qu'à un mouvement de fond.",
        'share_turnover' =>
            "Taux de rotation du flottant par entreprise sélectionnée = volume total échangé sur la période ÷ " .
            "actions en circulation (total émis, pas seulement le flottant réel). Mesure directement comparable " .
            "entre entreprises de tailles différentes, contrairement au volume brut. 'Actions non retradées " .
            "(estimation basse)' = actions en circulation - volume échangé (ramené à 0 si le volume dépasse les " .
            "actions en circulation, cas où le capital a été retradé au moins une fois en moyenne sur la " .
            "période). 'Flottant réel' = capitalisation flottante ÷ capitalisation totale (donnée BRVM) : une " .
            "valeur faible signifie qu'une grande part du capital est verrouillée chez un actionnaire de " .
            "contrôle et n'est jamais mise en vente, indépendamment du nombre total d'actions émises.",
        'volume_ranking' =>
            "Classement de toutes les entreprises actives par volume total d'actions échangées sur la période " .
            "sélectionnée (somme du volume quotidien sur chaque séance de la plage de dates, api_quotes.php, " .
            "action 'volume_ranking'). Une entreprise sans transaction sur la période apparaît quand même, à 0, " .
            "plutôt que d'être exclue du classement. Un volume total élevé traduit une forte activité de " .
            "négociation cumulée sur la période, pas nécessairement un mouvement de prix — à croiser avec le " .
            "nombre de jours de cotation (une entreprise peu suivie peut avoir peu de séances avec transaction) " .
            "pour interpréter le classement. Inclut aussi, quand la donnée est disponible (companies." .
            "shares_outstanding, scrapée ponctuellement, pas à chaque synchro) : le nombre total d'actions en " .
            "circulation (émises, PAS un stock 'à vendre' — la BRVM ne publie pas de carnet d'ordres public), " .
            "le taux de rotation (volume échangé ÷ actions en circulation, en %), et une estimation basse du " .
            "nombre d'actions n'ayant pas retrouvé d'acheteur sur la période (actions en circulation - volume " .
            "échangé, plafonnée à 0 — les mêmes titres peuvent être retradés plusieurs fois, donc cette " .
            "estimation ne veut pas dire que ces actions précises sont restées immobiles).",
        'performance_ranking' =>
            "Classement de toutes les entreprises actives par performance de cours sur la période sélectionnée " .
            "= (dernière clôture de la période - première clôture de la période) / première clôture, en % " .
            "(api_quotes.php, action 'performance_ranking') — même calcul que le rendement net de " .
            "'risk_adjusted', mais sur l'univers complet plutôt qu'une sélection, et sans le volet volatilité. " .
            "Une entreprise active sans aucune cotation sur la période apparaît avec une performance manquante " .
            "(pas 0%, qui suggérerait à tort une stabilité observée) — à distinguer d'une entreprise vraiment " .
            "stable sur la période.",
        'report_analysis_stats' =>
            "Statistiques agrégées des analyses IA déjà réalisées sur les rapports financiers (et, optionnellement, " .
            "les documents complémentaires) d'UNE entreprise (api_report_analysis.php, action 'stats', paramètre " .
            "'include_documents' — voir 'documents_included' dans les données pour savoir si cet appel les inclut) : " .
            "'total_reports' (tous les rapports officiels connus de l'entreprise) vs 'analyzed_reports' (ceux ayant " .
            "au moins une analyse IA réussie — une seule, la plus récente, comptée par rapport même si ré-analysé " .
            "plusieurs fois) vs 'pending_reports' (pas encore analysés avec succès) ; mêmes trois compteurs pour les " .
            "documents complémentaires ('total_documents'/'analyzed_documents'/'pending_documents', à 0 si " .
            "'documents_included' est false — ce sont des documents ajoutés manuellement par l'équipe, analysés " .
            "avec exactement le même schéma que les rapports officiels, voir class/CompanyDocumentAnalysisService.php). " .
            "IMPORTANT : cette synthèse ne porte QUE sur le sous-ensemble déjà analysé — les éléments 'pending' " .
            "sont un angle mort, pas une absence de risque/donnée, à rappeler explicitement plutôt qu'à ignorer, " .
            "et cette synthèse changera automatiquement à mesure que d'autres rapports/documents seront analysés " .
            "(jamais un instantané figé). 'verdict_distribution' compte combien d'éléments analysés (rapports, et " .
            "documents si inclus) concluent à 'sous-coté'/'surcoté'/'correctement valorisé'/'indéterminable' (voir " .
            "chart_type 'fundamentals' pour le détail de ce verdict) — permet de voir si l'avis de l'IA a été " .
            "stable ou a changé d'un exercice à l'autre. 'risk_category_distribution' compte la fréquence des " .
            "catégories de risques identifiées par l'IA à travers tous les éléments analysés (ex: 'opérationnel' " .
            "cité N fois) — une catégorie très récurrente signale un risque structurel plutôt que ponctuel. " .
            "'financial_trend' liste, par élément analysé et dans l'ordre chronologique ('source_type'='report' ou " .
            "'document', 'source_title'), le chiffre d'affaires, le résultat net (peut être négatif), la marge " .
            "nette, le ROE, le PER et le verdict extraits — 'publish_date' est la date de publication réelle pour " .
            "un rapport officiel, mais seulement la date d'AJOUT (uploaded_at) pour un document complémentaire, à " .
            "préciser si tu compares les deux. Plusieurs éléments peuvent partager la même date (ex: rapport " .
            "annuel et rapport des commissaires aux comptes publiés le même jour pour le même exercice) : ne pas " .
            "déduire une tendance d'un seul point isolé sur peu d'éléments analysés.",
        'risk_metrics_advanced' =>
            "Métriques de risque/performance calculées à partir des log-rendements quotidiens de chaque " .
            "entreprise sélectionnée (api_risk_metrics.php, action 'compute') : rendement net (simple, période " .
            "entière), CAGR (rendement annualisé composé), volatilité annualisée (écart-type des rendements × " .
            "√252), ratio de Sharpe = (rendement annualisé - taux sans risque) / volatilité annualisée (taux " .
            "sans risque fixé à 0% par défaut, faute de proxy simple pour les bons du Trésor UEMOA/BCEAO — " .
            "toujours renvoyé explicitement, jamais une hypothèse implicite), ratio de Sortino (même formule " .
            "mais avec l'écart-type des seuls rendements négatifs au dénominateur), Maximum Drawdown (perte la " .
            "plus forte depuis un sommet précédent sur la période) et ratio de Calmar (CAGR ÷ |drawdown max|), " .
            "VaR et CVaR historiques à 95% (percentile empirique des pires rendements, pas une hypothèse de " .
            "distribution normale), skewness et kurtosis (asymétrie et queues de distribution des rendements), " .
            "et bêta vs indice BRVM-COMPOSITE (covariance/variance des rendements quotidiens sur les jours " .
            "communs, régression classique — à distinguer du 'relative_strength' déjà existant, qui est un " .
            "écart de rendement jour par jour, pas un coefficient de régression). Le champ " .
            "'benchmark_return_percent' donne la performance RÉELLE de BRVM-COMPOSITE sur exactement la même " .
            "période (même calcul que 'net_return_percent', première vs dernière clôture connue de l'indice) — " .
            "identique pour toutes les entreprises de la sélection : toujours citer ce chiffre en le comparant " .
            "au bêta plutôt que de commenter le bêta seul, un bêta > 1 sur un marché en baisse est un signal " .
            "différent du même bêta sur un marché en hausse. En-dessous de 20 rendements quotidiens disponibles " .
            "(champ insufficient_history=true), toutes ces métriques sauf le rendement net simple sont " .
            "volontairement laissées à null plutôt que calculées sur un échantillon trop court pour être " .
            "statistiquement significatif.",
        'screener' =>
            "Classement filtrable de toutes les entreprises actives (ou du sous-ensemble déjà filtré côté " .
            "utilisateur) croisant plusieurs sources déjà calculées ailleurs dans l'application " .
            "(api_screener.php, action 'screen') : score composite achat/vente (-2 à +2, même formule que " .
            "api_signals.php, plafonné si le titre est illiquide), classement de liquidité (30 jours glissants), " .
            "performance de cours sur la période sélectionnée (première vs dernière clôture), et rang de " .
            "l'entreprise au sein de son propre secteur d'activité par cette même performance (sector_rank sur " .
            "sector_size, ex: 2 sur 4 = 2ᵉ meilleure performance parmi les 4 entreprises de ce secteur ayant une " .
            "cotation sur la période). Une entreprise sans cotation sur la période n'a pas de rang sectoriel " .
            "(sector_rank=null) plutôt qu'un rang par défaut trompeur.",
        'composite_score' =>
            "Score de synthèse 0-100 par entreprise (api_composite_score.php, action 'compute'), PAS un signal " .
            "d'achat/vente ni une prédiction : moyenne pondérée de 6 sous-scores 0-100 déjà normalisés — " .
            "Fondamental 30% (PER/rendement dividende/ROE/croissance CA/marge nette, barèmes simples documentés " .
            "dans le code, moyenne des seules composantes disponibles), Technique 25% (score composite achat/" .
            "vente -2/+2 rescalé en 0-100, même formule que 'quotes_signals'/'screener'), Momentum 15% " .
            "(performance de cours sur la période, rescalée), Liquidité 10% (classement Illiquide/Faible/Moyenne/" .
            "Élevée mappé en points fixes), Secteur 10% (rang de l'entreprise dans son secteur par performance, " .
            "rescalé), Marché 10% (performance de l'entreprise MOINS performance de BRVM-COMPOSITE sur la même " .
            "période — surperformance/sous-performance relative). IMPORTANT : 'coverage_percent' indique quelle " .
            "part des 100% de pondération a réellement pu être calculée pour cette entreprise (une entreprise " .
            "sans rapport financier traité n'a pas de sous-score Fondamental — le score est alors renormalisé " .
            "sur les dimensions disponibles, PAS pénalisé par un 0 silencieux) : toujours mentionner ce chiffre " .
            "quand tu compares deux entreprises, une comparaison entre une couverture de 45% et une de 90% n'a " .
            "pas la même fiabilité. Les barèmes de normalisation sont des heuristiques simples et documentées, " .
            "pas un calibrage statistique sur l'historique du marché — à présenter comme un outil de tri rapide, " .
            "jamais comme une note financière académique.",
        'fundamentals' =>
            "Ratios fondamentaux (PER, PEG, Price/Book, ROE, ROA, marges, EV/EBITDA, rendement du dividende, " .
            "payout ratio...) par entreprise sélectionnée (api_fundamentals.php, action 'list'). " .
            "IMPORTANT — nature de la donnée, différente des indicateurs techniques déjà analysés ailleurs dans " .
            "cette application (calcul déterministe sur des cours en base) : ces ratios sont EXTRAITS PAR IA du " .
            "texte du dernier rapport financier de chaque entreprise déjà traité avec succès (états financiers, " .
            "rapport annuel/semestriel/trimestriel), pas calculés depuis une source structurée fiable. Un champ " .
            "vide signifie que le rapport source ne divulguait pas l'élément nécessaire (ex. nombre d'actions en " .
            "circulation rarement précisé, rendant PER/EPS/P-B incalculables même sur un rapport bien traité) — " .
            "pas une erreur. La date du rapport source (source_publish_date) doit toujours être mentionnée : un " .
            "chiffre correct pour l'exercice couvert par le rapport peut être ancien par rapport à aujourd'hui. " .
            "'peg_ratio' est le seul champ calculé ici (PER ÷ croissance du chiffre d'affaires), tous les autres " .
            "proviennent tels quels de l'extraction IA du rapport.",
        'backtest' =>
            "Simulation mécanique, jour par jour, d'une règle de trading simple sur l'historique déjà persisté " .
            "d'une entreprise (api_backtest.php, action 'run'), comparée à un simple 'acheter et garder' sur la " .
            "même période. Deux règles possibles : 'signal_score' (entre en position quand le score composite — " .
            "même formule que 'quotes_signals' — atteint le seuil d'achat, sort quand il retombe au seuil de " .
            "vente) ou 'golden_cross' (entre au croisement haussier d'une paire de moyennes mobiles, sort au " .
            "croisement baissier). equity_curve donne l'évolution de 100 FCFA investis selon trois approches : la " .
            "stratégie, 'acheter et garder' (buy-and-hold), et la 'vente à découvert' (short_equity_base100) — " .
            "cette troisième courbe est une SIMULATION ACTIVE INDÉPENDANTE, pas un miroir mathématique de " .
            "buy-and-hold : elle a sa propre logique d'entrée/sortie, exactement inversée par rapport à la " .
            "stratégie longue (entre en position vendeuse quand le signal déclencherait normalement une sortie " .
            "longue — score au seuil de vente ou death cross —, rachète/couvre quand le signal déclencherait " .
            "normalement une entrée longue — score au seuil d'achat ou golden cross). On emprunte le titre pour " .
            "le vendre, dans l'espoir de le racheter moins cher, donc on gagne quand le cours baisse et on perd " .
            "quand il monte — PAS un mode de trading réellement disponible sur la BRVM, juste un repère théorique " .
            "pour une thèse baissière. short_return_percent (résultat final) et short_win_rate_percent/" .
            "short_total_trades (statistiques) proviennent de cette simulation réelle, donc short_return_percent " .
            "n'est PAS forcément égal à -buy_hold_return_percent — les deux stratégies peuvent avoir des " .
            "trajectoires de trades totalement différentes sur la même période. trades et short_trades listent " .
            "chaque position ouverte/fermée avec son rendement, pour la stratégie longue et vendeuse " .
            "respectivement. " .
            "IMPORTANT : quand insufficient_history=true (moins de 60 jours de bourse simulés), le résultat n'a " .
            "quasiment aucune valeur statistique (souvent 0 ou 1 trade) — à signaler explicitement dans ton " .
            "analyse plutôt que de commenter la performance comme si l'échantillon était suffisant. Ceci n'est " .
            "jamais un conseil en investissement, seulement le résultat mécanique d'une règle simple sur des " .
            "données passées.",
        'bulletin_analysis_stats' =>
            "Statistiques agrégées des analyses IA déjà réalisées sur les Bulletins Officiels de la Cote (BOC) " .
            "(api_bulletin_analysis.php, action 'stats') — PORTÉE MARCHÉ ENTIER, pas une entreprise en " .
            "particulier : un bulletin couvre TOUTE la séance de bourse, pas un titre isolé. " .
            "'total_bulletins' (tous les bulletins déjà scrapés) vs 'analyzed_bulletins' (ceux ayant au moins une " .
            "analyse IA réussie — une seule, la plus récente, comptée par bulletin même si ré-analysé plusieurs " .
            "fois) vs 'pending_bulletins' (pas encore analysés avec succès) : 'pending_bulletins' est un angle " .
            "mort, pas une absence d'activité de marché ces jours-là, à rappeler explicitement. Cette synthèse " .
            "change automatiquement à mesure que d'autres bulletins sont analysés (jamais un instantané figé), et " .
            "peut être filtrée sur une période ('start_date'/'end_date', absents = tout l'historique analysé). " .
            "'sentiment_distribution' compte combien de séances analysées concluent à 'haussier'/'baissier'/" .
            "'neutre'/'mixte' (avis de l'IA sur l'orientation générale du marché ce jour-là, PAS un indicateur " .
            "technique calculé). 'market_trend' liste, par bulletin analysé et dans l'ordre chronologique de " .
            "publication, le volume total échangé, la valeur totale transigée (FCFA), et la respiration du marché " .
            "(nombre de valeurs en hausse/en baisse/inchangées) — utile pour observer si l'activité du marché " .
            "(volume) et son orientation (hausses vs baisses) évoluent de concert ou divergent dans le temps. Ne " .
            "pas déduire une tendance de marché durable d'un seul point isolé sur peu de bulletins analysés — le " .
            "nombre de bulletins déjà scrapés (total_bulletins) dépasse presque toujours largement le nombre " .
            "analysés, donc la période couverte par ce graphe est souvent partielle/discontinue.",
        'corporate_actions' =>
            "Calendrier des opérations sur titres (dividendes, augmentations de capital, admissions, assemblées " .
            "générales...) extraites PAR IA du texte des Bulletins Officiels de la Cote (BOC) déjà traités " .
            "(api_bulletin_corporate_actions.php, action 'list'). Chaque ligne provient d'un bulletin précis " .
            "(bulletin_publish_date) et est rattachée à une entreprise de la base quand possible " .
            "(match_confidence='exact' ou 'fuzzy' ; company_id=null si le nom mentionné dans le bulletin n'a pas " .
            "pu être rapproché avec confiance suffisante — dans ce cas company_name_raw contient le nom tel " .
            "qu'écrit dans le bulletin). event_date peut être null si le bulletin ne précise pas de date exacte " .
            "pour l'opération (ex: assemblée générale 'à une date ultérieure'). Cette extraction dépend " .
            "entièrement de ce qui est explicitement écrit dans les bulletins déjà traités : l'absence d'une " .
            "opération dans ce calendrier ne signifie pas qu'elle n'existe pas, seulement qu'elle n'a pas encore " .
            "été identifiée dans un bulletin traité (pending_count indique combien de bulletins restent à " .
            "extraire).",
        'company_dashboard' =>
            "Vue d'ensemble d'une seule entreprise, agrégeant en une fois les données déjà calculées dans " .
            "plusieurs onglets du tableau de bord (Frontend/admin-web, page CompanyDashboard) : signal composite " .
            "et dernier cours ('signal'), historique récent de cours + SMA ('recent_price_history', 60 derniers " .
            "points), croisements de moyennes mobiles ('ma_crossovers'), ratios fondamentaux extraits par IA du " .
            "dernier rapport traité ('fundamentals', voir chart_type 'fundamentals' pour le détail de cette " .
            "source), calendrier des opérations sur titres ('corporate_actions', voir chart_type " .
            "'corporate_actions'), aperçu d'un backtest avec la règle par défaut signal_score seuils +1/-1 " .
            "('backtest_default_rule', voir chart_type 'backtest'), variation totale intrajournalière cumulée " .
            "('total_variation'), rendement/volatilité/ratio risque ('risk_adjusted'), force relative vs indice " .
            "('relative_strength_recent'), métriques de risque avancées Sharpe/Sortino/drawdown/VaR/bêta " .
            "('risk_metrics_advanced', voir chart_type 'risk_metrics_advanced'), classement/rang sectoriel " .
            "('screener_ranking', voir chart_type 'screener'), historique récent du score de liquidité " .
            "('liquidity_history_recent', 60 derniers points, voir chart_type 'liquidity_history' pour le détail " .
            "du calcul), performance du secteur de l'entreprise " .
            "('sector_performance'), largeur de marché générale à titre de contexte ('market_breadth_recent', " .
            "PAS spécifique à cette entreprise), et anomalies de qualité de données détectées spécifiquement " .
            "pour cette entreprise ('data_quality_flags'). Un champ peut être null si la donnée sous-jacente " .
            "est indisponible pour cette entreprise (ex: pas encore de rapport financier traité, pas de secteur " .
            "renseigné) — à signaler plutôt qu'à ignorer silencieusement. 'reports_available_count'/" .
            "'documents_available_count' indiquent combien de rapports/documents existent au total pour cette " .
            "entreprise (au-delà de ceux éventuellement inclus en texte intégral, voir contexte complémentaire " .
            "ci-dessous s'il est fourni).",
    ];

    private $crud;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
    }

    /**
     * @param array $parameters Paramètres exacts de la sélection (company_ids, dates, mode, selected_rows...)
     * @param array $data Données déjà calculées à analyser (ce que le graphe/tableau affiche)
     */
    public function analyze(string $chartType, array $parameters, array $data, ?string $provider = null, ?string $model = null, bool $forceRefresh = false): array {
        if (!isset(self::METHODOLOGY[$chartType])) {
            throw new Exception("Type de graphe non supporté: $chartType");
        }

        $provider = $provider ?: self::DEFAULT_PROVIDER;
        if (!isset(self::PROVIDERS[$provider])) {
            throw new Exception("Fournisseur IA inconnu: $provider. Disponibles: " . implode(', ', array_keys(self::PROVIDERS)));
        }
        $model = $model ?: self::PROVIDERS[$provider]['default_model'];

        if (empty($data)) {
            throw new Exception("Aucune donnée à analyser (sélection vide)");
        }

        $requestHash = $this->hashRequest($chartType, $parameters);

        $existing = $this->crud->executeCustomQuery(
            "SELECT * FROM chart_analyses WHERE request_hash = ? AND provider = ? AND model = ? LIMIT 1",
            [$requestHash, $provider, $model]
        );
        $existingRow = $existing[0] ?? null;

        if ($existingRow && $existingRow['status'] === 'success' && !$forceRefresh) {
            return $this->formatResult($existingRow, true);
        }

        // "include_report_context" est un paramètre de sélection comme un
        // autre (au même titre que company_ids/dates) — envoyé par le
        // frontend dans $parameters, donc déjà pris en compte par le hash de
        // cache ci-dessus : une analyse "avec rapports" et "sans rapports"
        // sur la même sélection sont mises en cache séparément.
        $reportContext = '';
        if (!empty($parameters['include_report_context'])) {
            $companyIds = $parameters['selected_company_ids'] ?? $parameters['company_ids'] ?? [];
            $reportContext = $this->buildReportContext(is_array($companyIds) ? $companyIds : []);
        } elseif (!empty($parameters['selected_report_ids']) || !empty($parameters['selected_document_ids'])) {
            // Sélection ciblée de rapports et/ou de documents complémentaires
            // précis (voir CompanyDashboard.tsx, section "Analyse IA
            // globale" — cases à cocher dans l'onglet Rapports) — différent
            // de buildReportContext() ci-dessus, qui prend automatiquement le
            // DERNIER rapport de chaque entreprise d'une sélection multi-
            // entreprises ; ici l'utilisateur choisit explicitement, pour une
            // seule entreprise.
            $reportContext = $this->buildSelectedResourcesContext(
                is_array($parameters['selected_report_ids'] ?? null) ? $parameters['selected_report_ids'] : [],
                is_array($parameters['selected_document_ids'] ?? null) ? $parameters['selected_document_ids'] : []
            );
        }

        $prompt = $this->buildPrompt($chartType, $parameters, $data, $reportContext);
        $client = $this->createClient($provider);
        $aiResult = $client->generateContent($prompt, $model, $this->responseSchema());

        $row = [
            'chart_type' => $chartType,
            'request_hash' => $requestHash,
            'parameters' => json_encode($parameters, JSON_UNESCAPED_UNICODE),
            'provider' => $provider,
            'model' => $model,
            'data_points_count' => $this->countDataPoints($data),
        ];

        if ($aiResult['success']) {
            $d = $aiResult['data'];
            $row['summary'] = $d['summary'] ?? null;
            $row['details'] = json_encode([
                'methodology_explained' => $d['methodology_explained'] ?? null,
                'key_observations' => $d['key_observations'] ?? [],
                'notable_points' => $d['notable_points'] ?? [],
                'suggested_charts' => $d['suggested_charts'] ?? [],
                'suggested_table' => $d['suggested_table'] ?? null,
            ], JSON_UNESCAPED_UNICODE);
            $row['status'] = 'success';
            $row['error_message'] = null;
            $row['raw_response'] = $aiResult['raw'] ?? null;
        } else {
            $row['status'] = 'failed';
            $row['error_message'] = $aiResult['error'];
            $row['raw_response'] = $aiResult['raw'] ?? null;
            $row['summary'] = null;
            $row['details'] = null;
        }

        if ($existingRow) {
            $this->crud->merge('chart_analyses', $row, ['id' => $existingRow['id']]);
            $rowId = $existingRow['id'];
        } else {
            $rowId = $this->crud->persist('chart_analyses', $row);
        }

        if (!$aiResult['success']) {
            throw new Exception($aiResult['error']);
        }

        $savedRow = $this->crud->findById('chart_analyses', $rowId);
        return $this->formatResult($savedRow, false);
    }

    /**
     * Historique filtré à la sélection courante (chart_type + paramètres
     * exacts), tous fournisseurs/modèles confondus, du plus récent au plus
     * ancien — volontairement pas une liste globale toutes sélections
     * confondues (voir TODO_CHART_AI_ANALYSIS.md).
     */
    public function history(string $chartType, array $parameters): array {
        $requestHash = $this->hashRequest($chartType, $parameters);

        $rows = $this->crud->executeCustomQuery(
            "SELECT * FROM chart_analyses WHERE request_hash = ? ORDER BY id DESC",
            [$requestHash]
        ) ?: [];

        return array_map(fn($row) => $this->formatResult($row, true), $rows);
    }

    public function get(int $id): ?array {
        $row = $this->crud->findById('chart_analyses', $id);
        return $row ? $this->formatResult($row, true) : null;
    }

    /**
     * Toutes les analyses d'un chart_type portant sur une entreprise donnée
     * (paramètres['company_id'] = $companyId), TOUTES sélections confondues
     * (dates, rapports/documents cochés...) — contrairement à history() qui
     * ne renvoie que la sélection EXACTE courante. Pensé pour comparer des
     * analyses faites à des moments différents (voir CompanyDashboard.tsx,
     * onglet "Analyse IA globale") : chaque appel avec des dates par défaut
     * "aujourd'hui - N jours" produit un hash différent d'un jour à l'autre,
     * donc history() seul ne suffit pas à retrouver les analyses passées
     * d'une même entreprise. JSON_EXTRACT fonctionne aussi bien sur MySQL
     * (5.7+) que MariaDB (10.2+, JSON stocké en LONGTEXT) — pas de
     * dépendance à une fonctionnalité MySQL-only.
     *
     * IMPORTANT : JSON_EXTRACT() seul renvoie une valeur typée JSON, pas un
     * entier SQL — comparée à un paramètre PDO lié en chaîne (comportement
     * par défaut de PDO), l'égalité échoue silencieusement (0 résultat, pas
     * d'erreur). CAST(... AS UNSIGNED) force une comparaison numérique
     * fiable quel que soit le type de liaison PDO. Vérifié en conditions
     * réelles avant ce choix — voir aussi l'alternative équivalente
     * parameters->>'$.company_id' (opérateur de "unquoting", supporté
     * MySQL 5.7.13+ et MariaDB 10.2.4+).
     */
    public function listByCompany(string $chartType, int $companyId): array {
        $rows = $this->crud->executeCustomQuery(
            "SELECT * FROM chart_analyses
             WHERE chart_type = ? AND CAST(JSON_EXTRACT(parameters, '$.company_id') AS UNSIGNED) = ?
             ORDER BY id DESC",
            [$chartType, $companyId]
        ) ?: [];

        return array_map(fn($row) => $this->formatResult($row, true), $rows);
    }

    /**
     * Supprime une analyse enregistrée — libère le (request_hash, provider,
     * model) correspondant, un nouveau clic sur "Analyser" redéclenchera un
     * appel IA plutôt que de retomber sur une ligne déjà supprimée.
     */
    public function remove(int $id): void {
        $row = $this->crud->findById('chart_analyses', $id);
        if (!$row) {
            throw new Exception("Analyse non trouvée (id=$id)");
        }
        $this->crud->remove('chart_analyses', ['id' => $id]);
    }

    /**
     * Note (1-5 étoiles) et/ou commentaire libre sur une analyse déjà
     * enregistrée — $rating à null efface la note, $notes à null (et non
     * absent) efface le commentaire ; seuls les champs explicitement
     * fournis par l'appelant sont modifiés (voir api_chart_analysis.php).
     */
    public function rate(int $id, ?int $rating, ?string $notes, bool $ratingProvided, bool $notesProvided): array {
        if ($ratingProvided && $rating !== null && ($rating < 1 || $rating > 5)) {
            throw new Exception("La note doit être comprise entre 1 et 5");
        }

        $row = $this->crud->findById('chart_analyses', $id);
        if (!$row) {
            throw new Exception("Analyse non trouvée (id=$id)");
        }

        $update = [];
        if ($ratingProvided) $update['rating'] = $rating;
        if ($notesProvided) $update['notes'] = $notes;

        if (!empty($update)) {
            $this->crud->merge('chart_analyses', $update, ['id' => $id]);
        }

        $updatedRow = $this->crud->findById('chart_analyses', $id);
        return $this->formatResult($updatedRow, true);
    }

    private function hashRequest(string $chartType, array $parameters): string {
        ksort($parameters);
        return hash('sha256', json_encode([$chartType, $parameters]));
    }

    private function countDataPoints($data): int {
        $count = 0;
        array_walk_recursive($data, function () use (&$count) {
            $count++;
        });
        return $count;
    }

    private function createClient(string $provider): AiClientInterface {
        $class = self::PROVIDERS[$provider]['class'];
        return new $class();
    }

    /**
     * Formule en français la période couverte par les paramètres de
     * sélection (start_date/end_date/date/days — les combinaisons varient
     * selon le chart_type, voir les usages de <ChartAiAnalysis> côté
     * frontend) — calculée ici, jamais laissée à la charge de l'IA, pour
     * que la période affichée soit toujours exacte plutôt que déduite/
     * devinée à partir des seules données. Retourne null si aucun indice
     * de période n'est présent dans les paramètres (rare, mais possible).
     */
    private function formatPeriod(array $parameters): ?string {
        $start = $parameters['start_date'] ?? null;
        $end = $parameters['end_date'] ?? null;
        $date = $parameters['date'] ?? null;
        $days = $parameters['days'] ?? null;

        if ($start && $end) {
            return $start === $end ? "le $start" : "du $start au $end";
        }
        if ($date) {
            return "le $date";
        }
        if ($days && $end) {
            return "les $days derniers jours (jusqu'au $end)";
        }
        if ($days) {
            return "les $days derniers jours";
        }
        if ($start) {
            return "depuis le $start";
        }
        if ($end) {
            return "jusqu'au $end";
        }
        return null;
    }

    /**
     * Contexte financier optionnel injecté dans le prompt (voir analyze()) —
     * un bloc de texte par entreprise sélectionnée, tiré de son rapport déjà
     * traité le plus récent (company_reports/company_report_contents).
     * Priorise le markdown reformaté (tableaux propres, voir
     * ReportMarkdownFormatterService) sur le texte brut extrait quand
     * disponible, comme ReportAnalysisService::analyzeReport() le fait déjà
     * pour l'analyse d'un rapport individuel — même logique, réutilisée ici
     * pour plusieurs entreprises à la fois plutôt qu'une seule.
     */
    private function buildReportContext(array $companyIds): string {
        $companyIds = array_slice(array_unique(array_map('intval', $companyIds)), 0, self::MAX_REPORT_COMPANIES);
        if (empty($companyIds)) {
            return '';
        }

        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));
        $companies = $this->crud->executeCustomQuery(
            "SELECT id, symbol, name FROM companies WHERE id IN ($placeholders)",
            $companyIds
        ) ?: [];
        $companyById = [];
        foreach ($companies as $c) {
            $companyById[(int) $c['id']] = $c;
        }

        $blocks = [];
        foreach ($companyIds as $companyId) {
            $company = $companyById[$companyId] ?? null;
            $label = $company ? "{$company['symbol']} — {$company['name']}" : "Entreprise #$companyId";
            $companyBlock = "### $label";

            $reports = $this->crud->executeCustomQuery(
                "SELECT id, report_type, publish_date FROM company_reports
                 WHERE company_id = ? AND text_extracted = 1
                 ORDER BY publish_date DESC LIMIT 1",
                [$companyId]
            );
            $report = $reports[0] ?? null;

            if (!$report) {
                $companyBlock .= "\nAucun rapport traité disponible pour cette entreprise.";
            } else {
                $contents = $this->crud->find('company_report_contents', ['report_id' => $report['id']]);
                $content = $contents[0] ?? null;
                $usingMarkdown = !empty($content['formatted_markdown']) && ($content['markdown_status'] ?? null) === 'success';
                $text = $usingMarkdown ? $content['formatted_markdown'] : ($content['extracted_text'] ?? null);

                if (empty($text)) {
                    $companyBlock .= "\nRapport {$report['report_type']} du {$report['publish_date']} référencé, " .
                        "mais texte pas encore extrait.";
                } else {
                    $text = $this->truncateReportText($text, self::MAX_REPORT_CHARS_PER_COMPANY);
                    $sourceNote = $usingMarkdown ? '' : ' (texte brut extrait, pas encore reformaté en Markdown)';
                    $companyBlock .= " — rapport {$report['report_type']} du {$report['publish_date']}$sourceNote\n\n$text";
                }
            }

            // Documents complémentaires ajoutés manuellement (voir
            // api_company_documents.php) — rapports détaillés publiés sur le
            // site de l'entreprise mais absents/résumés dans le rapport
            // officiel ci-dessus, présentations investisseurs, etc.
            $documents = $this->crud->executeCustomQuery(
                "SELECT id, title FROM company_documents
                 WHERE company_id = ? AND text_extracted = 1
                 ORDER BY uploaded_at DESC LIMIT ?",
                [$companyId, self::MAX_DOCUMENTS_PER_COMPANY]
            ) ?: [];

            foreach ($documents as $document) {
                $docContents = $this->crud->find('company_document_contents', ['document_id' => $document['id']]);
                $docContent = $docContents[0] ?? null;
                $usingMarkdown = !empty($docContent['formatted_markdown']) && ($docContent['markdown_status'] ?? null) === 'success';
                $docText = $usingMarkdown ? $docContent['formatted_markdown'] : ($docContent['extracted_text'] ?? null);

                if (empty($docText)) {
                    continue;
                }

                $docText = $this->truncateReportText($docText, self::MAX_DOCUMENT_CHARS);
                $sourceNote = $usingMarkdown ? '' : ' (texte brut extrait, pas encore reformaté en Markdown)';
                $companyBlock .= "\n\n#### Document complémentaire : {$document['title']}$sourceNote\n\n$docText";
            }

            $blocks[] = $companyBlock;
        }

        return implode("\n\n---\n\n", $blocks);
    }

    /**
     * Contexte financier ciblé sur des rapports et/ou des documents
     * complémentaires choisis explicitement par l'utilisateur (cases à
     * cocher dans CompanyDashboard.tsx, onglet Rapports, section "Analyse IA
     * globale", chart_type 'company_dashboard') — contrairement à
     * buildReportContext() ci-dessus qui prend automatiquement le dernier
     * rapport de chaque entreprise d'une sélection multi-entreprises, ici il
     * n'y a qu'une seule entreprise en jeu et le choix est manuel.
     */
    private function buildSelectedResourcesContext(array $reportIds, array $documentIds): string {
        $blocks = [];

        $reportIds = array_slice(array_unique(array_map('intval', $reportIds)), 0, self::MAX_SELECTED_REPORTS);
        foreach ($reportIds as $reportId) {
            $report = $this->crud->findById('company_reports', $reportId);
            if (!$report) {
                continue;
            }

            $company = $this->crud->findById('companies', $report['company_id']);
            $label = $company ? "{$company['symbol']} — {$company['name']}" : "Entreprise #{$report['company_id']}";

            $contents = $this->crud->find('company_report_contents', ['report_id' => $reportId]);
            $content = $contents[0] ?? null;
            $usingMarkdown = !empty($content['formatted_markdown']) && ($content['markdown_status'] ?? null) === 'success';
            $text = $usingMarkdown ? $content['formatted_markdown'] : ($content['extracted_text'] ?? null);

            if (empty($text)) {
                continue;
            }

            $text = $this->truncateReportText($text, self::MAX_REPORT_CHARS_PER_COMPANY);
            $sourceNote = $usingMarkdown ? '' : ' (texte brut extrait, pas encore reformaté en Markdown)';
            $blocks[] = "### $label — rapport {$report['report_type']} du {$report['publish_date']}$sourceNote\n\n$text";
        }

        $documentIds = array_slice(array_unique(array_map('intval', $documentIds)), 0, self::MAX_DOCUMENTS_PER_COMPANY);
        foreach ($documentIds as $documentId) {
            $document = $this->crud->findById('company_documents', $documentId);
            if (!$document) {
                continue;
            }

            $contents = $this->crud->find('company_document_contents', ['document_id' => $documentId]);
            $content = $contents[0] ?? null;
            $usingMarkdown = !empty($content['formatted_markdown']) && ($content['markdown_status'] ?? null) === 'success';
            $text = $usingMarkdown ? $content['formatted_markdown'] : ($content['extracted_text'] ?? null);

            if (empty($text)) {
                continue;
            }

            $text = $this->truncateReportText($text, self::MAX_DOCUMENT_CHARS);
            $sourceNote = $usingMarkdown ? '' : ' (texte brut extrait, pas encore reformaté en Markdown)';
            $blocks[] = "#### Document complémentaire : {$document['title']}$sourceNote\n\n$text";
        }

        return implode("\n\n", $blocks);
    }

    private function truncateReportText(string $text, int $maxChars): string {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        return mb_substr($text, 0, $maxChars) . "\n\n[...texte tronqué...]";
    }

    private function buildPrompt(string $chartType, array $parameters, array $data, string $reportContext = ''): string {
        $methodology = self::METHODOLOGY[$chartType];
        $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $period = $this->formatPeriod($parameters);
        $periodBlock = $period
            ? "Période couverte par ces données : $period. Mentionne explicitement cette période dans ton résumé (\"summary\") — ne la déduis jamais toi-même à partir des données, utilise exactement celle donnée ici."
            : "Aucune période explicite n'a été fournie pour cette sélection — ne mentionne pas de période si tu ne peux pas la déduire avec certitude des données elles-mêmes.";

        $reportContextBlock = $reportContext !== ''
            ? "Contexte financier complémentaire (extraits des rapports déjà traités par l'application pour les " .
              "entreprises sélectionnées — le texte reformaté en Markdown est utilisé en priorité sur le texte brut " .
              "extrait quand disponible). Utilise-le pour enrichir ton analyse avec les fondamentaux de chaque " .
              "entreprise (chiffre d'affaires, résultat net, tendances, risques...) EN PLUS du mouvement de cours ci-" .
              "dessous — ne mélange jamais un chiffre issu d'un rapport avec une donnée de marché sans préciser sa " .
              "source :\n\n$reportContext\n"
            : '';

        return <<<PROMPT
Tu es un analyste financier senior spécialisé sur les marchés actions
d'Afrique de l'Ouest (BRVM), chargé d'analyser un graphe/tableau de
l'application BRVM Insights pour un investisseur qui n'est pas forcément
familier avec les indicateurs techniques.

Méthode de calcul utilisée pour produire les données ci-dessous (à
restituer en langage clair dans ta réponse, pas seulement à utiliser en
silence) :
$methodology

$periodBlock

$reportContextBlock
Données à analyser (JSON) :
$dataJson

En plus de ton analyse, propose entre 0 et 3 graphes complémentaires
("suggested_charts") qui aideraient à mieux comprendre CES MÊMES données
sous un autre angle (ex: isoler un sous-ensemble, changer la métrique
affichée, comparer deux séries entre elles) — pas un graphe qui
nécessiterait des données que tu n'as pas reçues ci-dessus.

Contrainte stricte sur "suggested_charts" : chaque graphe proposé doit
être directement calculable à partir du JSON fourni ci-dessus, structuré
comme une liste d'enregistrements (objets avec les mêmes clés). "x_field"
doit être le nom exact d'une clé de ces enregistrements à utiliser en axe
X (souvent une date), et chaque entrée de "series" doit référencer le nom
exact d'une clé numérique de ces enregistrements à tracer en Y. N'invente
jamais un nom de champ absent des données. Si les données fournies ne sont
PAS structurées comme une liste plate d'enregistrements exploitable ainsi
(ex: une matrice, un objet unique imbriqué), renvoie un tableau vide pour
"suggested_charts" plutôt que de forcer une proposition inadaptée.

Chaque champ technique (x_field, et le "field" de chaque série) doit être
accompagné d'un libellé humain, clair et en français, à afficher à la
place du nom technique (jamais le nom de clé JSON brut du type
"net_return_percent" — l'utilisateur final ne doit jamais voir ces noms
techniques, seulement tes libellés) : "x_label" pour l'axe X, "label" pour
chaque série (avec l'unité si pertinent, ex: "Rendement net (%)").

En plus des graphes, tu peux proposer UN tableau de synthèse
("suggested_table") si — et seulement si — ton analyse dégage une
sélection ou un classement qui apporte un angle nouveau par rapport au(x)
tableau(x) déjà affiché(s) (ex: "titres à surveiller en priorité" croisant
plusieurs colonnes des données fournies, avec ta propre justification par
ligne). Contrairement à "suggested_charts" (qui ne fait que retracer des
champs déjà présents dans les données), "suggested_table" peut inclure des
colonnes que TU synthétises toi-même à partir de ton analyse (ex: une
colonne "raison" expliquant pourquoi cette ligne a été retenue) — mais
chaque ligne doit obligatoirement correspondre à une entité réellement
présente dans les données fournies (même symbole/nom exact), jamais une
entreprise inventée ou absente de la sélection. Si aucune sélection/
synthèse n'apporte de valeur ajoutée réelle par rapport aux données déjà
affichées, renvoie null plutôt que de dupliquer inutilement un tableau
déjà visible à l'écran.

Réponds UNIQUEMENT avec un objet JSON de cette forme exacte (aucun texte
avant/après, pas de balises markdown) :

{
  "summary": "résumé général en 2-4 phrases de ce que montrent ces données",
  "methodology_explained": "explication en langage clair et accessible de comment ces chiffres ont été calculés, reformulée à partir de la méthode fournie ci-dessus pour quelqu'un qui ne connaît pas le jargon technique",
  "key_observations": ["observation factuelle 1 sur les données", "observation factuelle 2", "..."],
  "notable_points": ["point notable 1 (valeur extrême, divergence, anomalie...) s'il y en a — sinon tableau vide", "..."],
  "suggested_charts": [
    {
      "title": "titre court du graphe proposé",
      "description": "pourquoi ce graphe apporte un angle différent de celui déjà affiché",
      "chart_type": "line ou bar",
      "x_field": "nom exact du champ à utiliser en axe X",
      "x_label": "libellé humain en français pour l'axe X, ex: \"Entreprise\" ou \"Date\"",
      "series": [
        {"field": "nom exact du champ numérique", "label": "libellé humain en français, avec unité si pertinent"}
      ]
    }
  ],
  "suggested_table": {
    "title": "titre court du tableau proposé",
    "description": "pourquoi cette sélection/ce classement apporte un angle différent de ce qui est déjà affiché",
    "columns": [
      {"key": "symbol", "label": "Symbole"},
      {"key": "raison", "label": "Pourquoi"}
    ],
    "rows": [
      {"symbol": "SYMBOLE_EXACT_DES_DONNEES_FOURNIES", "raison": "justification courte basée sur ton analyse"}
    ]
  }
}

Si "suggested_table" n'apporte rien, renvoie null pour ce champ (pas un objet avec des tableaux vides).

Règles impératives :
- N'invente aucun chiffre : base-toi uniquement sur les données fournies ci-dessus.
- Reste factuel et neutre : jamais de recommandation d'achat/vente explicite.
- Si les données sont insuffisantes pour une observation pertinente, dis-le plutôt que d'inventer.
- N'invente aucun nom de champ dans "suggested_charts" — uniquement des clés réellement présentes dans les données fournies. Dans le doute, renvoie un tableau vide.
- Dans "suggested_table", chaque ligne doit correspondre à une entité réellement présente dans les données fournies (symbole/nom exact) — jamais une entreprise inventée.
- N'affiche jamais un nom de clé JSON brut comme libellé — toujours un "label"/"x_label" humain et compréhensible.
- Si un contexte financier (rapports) a été fourni ci-dessus, précise dans "summary" et/ou "key_observations" quand une observation s'appuie sur ce contexte plutôt que sur les données de marché, et signale explicitement si un rapport manque pour une entreprise sélectionnée plutôt que de rester silencieux dessus.
- Réponds uniquement avec le JSON.
PROMPT;
    }

    private function responseSchema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'methodology_explained', 'key_observations', 'notable_points', 'suggested_charts', 'suggested_table'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'methodology_explained' => ['type' => 'string'],
                'key_observations' => ['type' => 'array', 'items' => ['type' => 'string']],
                'notable_points' => ['type' => 'array', 'items' => ['type' => 'string']],
                'suggested_charts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'description', 'chart_type', 'x_field', 'x_label', 'series'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'chart_type' => ['enum' => ['line', 'bar']],
                            'x_field' => ['type' => 'string'],
                            'x_label' => ['type' => 'string'],
                            'series' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['field', 'label'],
                                    'properties' => [
                                        'field' => ['type' => 'string'],
                                        'label' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'suggested_table' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => false,
                    'required' => ['title', 'description', 'columns', 'rows'],
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'columns' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['key', 'label'],
                                'properties' => [
                                    'key' => ['type' => 'string'],
                                    'label' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'rows' => [
                            'type' => 'array',
                            'items' => ['type' => 'object'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function formatResult(array $row, bool $cached): array {
        $details = json_decode($row['details'] ?? 'null', true) ?: [];
        $parameters = json_decode($row['parameters'], true) ?: [];

        return [
            'id' => (int) $row['id'],
            'chart_type' => $row['chart_type'],
            'parameters' => $parameters,
            'period' => $this->formatPeriod($parameters),
            'provider' => $row['provider'],
            'model' => $row['model'],
            'status' => $row['status'],
            'error_message' => $row['error_message'] ?? null,
            'rating' => isset($row['rating']) ? (int) $row['rating'] : null,
            'notes' => $row['notes'] ?? null,
            'summary' => $row['summary'],
            'methodology_explained' => $details['methodology_explained'] ?? null,
            'key_observations' => $details['key_observations'] ?? [],
            'notable_points' => $details['notable_points'] ?? [],
            'suggested_charts' => $details['suggested_charts'] ?? [],
            'suggested_table' => $details['suggested_table'] ?? null,
            'disclaimer' => self::DISCLAIMER,
            'cached' => $cached,
            'created_at' => $row['created_at'] ?? null,
        ];
    }
}
