<?php
/**
 * Registre des formats d'états financiers publiés par les émetteurs BRVM.
 *
 * Chaque format déclare ses groupes de postes, ses libellés, le signe attendu
 * de chaque poste, et ses sous-totaux sous forme de COEFFICIENTS (+1 / -1)
 * appliqués aux postes. Cette forme déclarative permet de vérifier une
 * formule d'un coup d'œil, et d'ajouter un format sans toucher ni à la base
 * (modèle générique, migration 023) ni au calcul.
 *
 * ATTENTION aux conventions de signe, qui DIFFÈRENT d'un format à l'autre :
 *  - SYSCOHADA commercial : les charges sont saisies en NÉGATIF, telles
 *    qu'imprimées ; les sous-totaux sont de simples sommes (coefficients +1) ;
 *  - compte de résultat BANCAIRE : les charges sont imprimées en POSITIF ;
 *    ce sont les coefficients -1 des formules qui les retranchent.
 * Les quatre formats ci-dessous ont été vérifiés au chiffre près sur des
 * états financiers réellement publiés.
 */
class FinancialStatementSchemas {

    /** @return array<string,array> le registre complet, clé = statement_type */
    public static function types(): array {
        return [
            'syscohada_resultat' => self::syscohadaResultat(),
            'bancaire_resultat' => self::bancaireResultat(),
            'bancaire_bilan' => self::bancaireBilan(),
            'flux_tresorerie' => self::fluxTresorerie(),
            'activite_simplifie' => self::activiteSimplifie(),
            'dividendes' => self::dividendes(),
        ];
    }

    public static function get(string $type): array {
        $types = self::types();
        if (!isset($types[$type])) {
            throw new Exception("Format d'état financier inconnu : $type");
        }
        return $types[$type];
    }

    /** Liste légère (sans les postes) pour alimenter un menu de choix. */
    public static function summaries(): array {
        $out = [];
        foreach (self::types() as $key => $schema) {
            $lines = 0;
            foreach ($schema['groups'] as $group) {
                $lines += count($group['lines']);
            }
            $out[] = [
                'key' => $key,
                'label' => $schema['label'],
                'description' => $schema['description'],
                'sign_convention' => $schema['sign_convention'],
                'lines_count' => $lines,
                'subtotals_count' => count($schema['subtotals']),
            ];
        }
        return $out;
    }

    /**
     * Applique les formules de sous-totaux d'un format à des valeurs saisies.
     * Un sous-total peut référencer un autre sous-total déjà calculé (la
     * valeur ajoutée part de la marge commerciale) : les formules sont donc
     * évaluées dans l'ordre de déclaration.
     */
    public static function computeSubtotals(string $type, array $values): array {
        $schema = self::get($type);
        $computed = [];
        foreach ($schema['subtotals'] as $subtotal) {
            $sum = 0.0;
            foreach ($subtotal['formula'] as $key => $coefficient) {
                $base = array_key_exists($key, $computed)
                    ? $computed[$key]
                    : (isset($values[$key]) && $values[$key] !== null ? (float) $values[$key] : 0.0);
                $sum += $coefficient * $base;
            }
            $computed[$subtotal['key']] = round($sum, 2);
        }
        return $computed;
    }

    // ------------------------------------------------------------------
    // Formats
    // ------------------------------------------------------------------

    /** Compte de résultat commercial OHADA — charges saisies en négatif. */
    private static function syscohadaResultat(): array {
        return [
            'label' => 'Compte de résultat (SYSCOHADA, entreprise commerciale/industrielle)',
            'description' => "Format OHADA classique : marge commerciale, valeur ajoutée, EBE, résultat d'exploitation, financier, HAO et résultat net.",
            'sign_convention' => 'charges_negatives',
            'sign_note' => "Saisissez les charges en NÉGATIF, exactement comme elles apparaissent dans le document. Chaque sous-total devient alors une simple addition.",
            'headline' => ['chiffre_affaires', 'resultat_net'],
            'groups' => [
                ['key' => 'commercial', 'label' => 'Activité commerciale', 'lines' => [
                    ['key' => 'ventes_marchandises', 'label' => 'Ventes de marchandises', 'sign' => 'produit'],
                    ['key' => 'variation_stocks_marchandises', 'label' => 'Variation de stocks de marchandises', 'sign' => 'mixte'],
                ]],
                ['key' => 'production', 'label' => 'Production', 'lines' => [
                    ['key' => 'ventes_produits_fabriques', 'label' => 'Ventes de produits fabriqués', 'sign' => 'produit'],
                    ['key' => 'travaux_services_vendus', 'label' => 'Travaux, services vendus', 'sign' => 'produit'],
                    ['key' => 'produits_accessoires', 'label' => 'Produits accessoires', 'sign' => 'produit'],
                    ['key' => 'production_stockee', 'label' => 'Production stockée (ou déstockage)', 'sign' => 'mixte'],
                    ['key' => 'production_immobilisee', 'label' => 'Production immobilisée', 'sign' => 'produit'],
                    ['key' => 'autres_produits', 'label' => 'Autres produits', 'sign' => 'produit'],
                    ['key' => 'transferts_charges_exploitation', 'label' => "Transferts de charges d'exploitation", 'sign' => 'produit'],
                ]],
                ['key' => 'consommations', 'label' => 'Consommations', 'lines' => [
                    ['key' => 'achats_matieres_premieres', 'label' => 'Achats de matières premières et fournitures liées', 'sign' => 'charge'],
                    ['key' => 'variation_stocks_matieres', 'label' => 'Variation de stocks de matières premières', 'sign' => 'mixte'],
                    ['key' => 'autres_achats', 'label' => 'Autres achats', 'sign' => 'charge'],
                    ['key' => 'variation_stocks_approvisionnements', 'label' => "Variation de stocks d'autres approvisionnements", 'sign' => 'mixte'],
                    ['key' => 'transports', 'label' => 'Transports', 'sign' => 'charge'],
                    ['key' => 'services_exterieurs', 'label' => 'Services extérieurs', 'sign' => 'charge'],
                    ['key' => 'impots_taxes', 'label' => 'Impôts et taxes', 'sign' => 'charge'],
                    ['key' => 'autres_charges', 'label' => 'Autres charges', 'sign' => 'charge'],
                ]],
                ['key' => 'personnel', 'label' => 'Personnel et amortissements', 'lines' => [
                    ['key' => 'charges_personnel', 'label' => 'Charges de personnel', 'sign' => 'charge'],
                    ['key' => 'reprises_amortissements', 'label' => "Reprises d'amortissements, provisions et dépréciations", 'sign' => 'produit'],
                    ['key' => 'dotations_amortissements', 'label' => 'Dotations aux amortissements, provisions et dépréciations', 'sign' => 'charge'],
                ]],
                ['key' => 'financier', 'label' => 'Financier', 'lines' => [
                    ['key' => 'revenus_financiers', 'label' => 'Revenus financiers et assimilés', 'sign' => 'produit'],
                    ['key' => 'reprises_provisions_financieres', 'label' => 'Reprises de provisions et dépréciations financières', 'sign' => 'produit'],
                    ['key' => 'frais_financiers', 'label' => 'Frais financiers et charges assimilées', 'sign' => 'charge'],
                    ['key' => 'dotations_provisions_financieres', 'label' => 'Dotations aux provisions et dépréciations financières', 'sign' => 'charge'],
                ]],
                ['key' => 'hao', 'label' => 'Hors activités ordinaires (HAO)', 'lines' => [
                    ['key' => 'produits_cessions_immobilisations', 'label' => "Produits des cessions d'immobilisations", 'sign' => 'produit'],
                    ['key' => 'autres_produits_hao', 'label' => 'Autres produits HAO', 'sign' => 'produit'],
                    ['key' => 'valeurs_comptables_cessions', 'label' => "Valeurs comptables des cessions d'immobilisations", 'sign' => 'charge'],
                    ['key' => 'autres_charges_hao', 'label' => 'Autres charges HAO', 'sign' => 'charge'],
                ]],
                ['key' => 'impot', 'label' => 'Impôt', 'lines' => [
                    ['key' => 'impots_resultat', 'label' => 'Impôts sur le résultat', 'sign' => 'charge'],
                ]],
                ['key' => 'bilan', 'label' => 'Postes de bilan (facultatifs — nécessaires au PBR, ROE et gearing)', 'lines' => [
                    ['key' => 'total_equity', 'label' => 'Capitaux propres', 'sign' => 'produit'],
                    ['key' => 'total_debt', 'label' => 'Dettes financières', 'sign' => 'produit'],
                    ['key' => 'total_assets', 'label' => 'Total bilan', 'sign' => 'produit'],
                ]],
            ],
            'subtotals' => [
                ['key' => 'marge_commerciale', 'label' => 'MARGE COMMERCIALE', 'formula' => [
                    'ventes_marchandises' => 1, 'variation_stocks_marchandises' => 1,
                ]],
                ['key' => 'chiffre_affaires', 'label' => "CHIFFRE D'AFFAIRES", 'formula' => [
                    'ventes_marchandises' => 1, 'ventes_produits_fabriques' => 1,
                    'travaux_services_vendus' => 1, 'produits_accessoires' => 1,
                ]],
                // Part de la MARGE COMMERCIALE et non des ventes brutes : la
                // variation de stocks est déjà comptée dans la marge.
                ['key' => 'valeur_ajoutee', 'label' => 'VALEUR AJOUTÉE', 'formula' => [
                    'marge_commerciale' => 1, 'ventes_produits_fabriques' => 1, 'travaux_services_vendus' => 1,
                    'produits_accessoires' => 1, 'production_stockee' => 1, 'production_immobilisee' => 1,
                    'autres_produits' => 1, 'transferts_charges_exploitation' => 1,
                    'achats_matieres_premieres' => 1, 'variation_stocks_matieres' => 1, 'autres_achats' => 1,
                    'variation_stocks_approvisionnements' => 1, 'transports' => 1, 'services_exterieurs' => 1,
                    'impots_taxes' => 1, 'autres_charges' => 1,
                ]],
                ['key' => 'excedent_brut_exploitation', 'label' => "EXCÉDENT BRUT D'EXPLOITATION", 'formula' => [
                    'valeur_ajoutee' => 1, 'charges_personnel' => 1,
                ]],
                ['key' => 'resultat_exploitation', 'label' => "RÉSULTAT D'EXPLOITATION", 'formula' => [
                    'excedent_brut_exploitation' => 1, 'reprises_amortissements' => 1, 'dotations_amortissements' => 1,
                ]],
                ['key' => 'resultat_financier', 'label' => 'RÉSULTAT FINANCIER', 'formula' => [
                    'revenus_financiers' => 1, 'reprises_provisions_financieres' => 1,
                    'frais_financiers' => 1, 'dotations_provisions_financieres' => 1,
                ]],
                ['key' => 'resultat_activites_ordinaires', 'label' => 'RÉSULTAT DES ACTIVITÉS ORDINAIRES', 'formula' => [
                    'resultat_exploitation' => 1, 'resultat_financier' => 1,
                ]],
                ['key' => 'resultat_hao', 'label' => 'RÉSULTAT HORS ACTIVITÉS ORDINAIRES', 'formula' => [
                    'produits_cessions_immobilisations' => 1, 'autres_produits_hao' => 1,
                    'valeurs_comptables_cessions' => 1, 'autres_charges_hao' => 1,
                ]],
                ['key' => 'resultat_net', 'label' => 'RÉSULTAT NET', 'formula' => [
                    'resultat_activites_ordinaires' => 1, 'resultat_hao' => 1, 'impots_resultat' => 1,
                ]],
            ],
        ];
    }

    /** Compte de résultat bancaire — charges saisies en POSITIF. */
    private static function bancaireResultat(): array {
        return [
            'label' => 'Compte de résultat bancaire',
            'description' => "Format des banques : produit net bancaire, coût du risque, résultat avant impôt.",
            'sign_convention' => 'charges_positives',
            'sign_note' => "Saisissez TOUS les montants en POSITIF, comme dans le document : ce sont les formules qui retranchent les charges. Ce format est l'inverse du SYSCOHADA commercial.",
            'headline' => ['produit_net_bancaire', 'resultat_net'],
            'groups' => [
                ['key' => 'interets', 'label' => 'Intérêts et titres', 'lines' => [
                    ['key' => 'interets_produits', 'label' => 'Intérêts et produits assimilés', 'sign' => 'produit'],
                    ['key' => 'interets_charges', 'label' => 'Intérêts et charges assimilées', 'sign' => 'charge_positive'],
                    ['key' => 'revenus_titres_variable', 'label' => 'Revenus des titres à revenu variable', 'sign' => 'produit'],
                ]],
                ['key' => 'commissions', 'label' => 'Commissions et opérations de marché', 'lines' => [
                    ['key' => 'commissions_produits', 'label' => 'Commissions (produits)', 'sign' => 'produit'],
                    ['key' => 'commissions_charges', 'label' => 'Commissions (charges)', 'sign' => 'charge_positive'],
                    ['key' => 'gains_negociation', 'label' => 'Gains ou pertes nets sur portefeuille de négociation', 'sign' => 'mixte'],
                    ['key' => 'gains_placement', 'label' => 'Gains ou pertes nets sur portefeuille de placement', 'sign' => 'mixte'],
                ]],
                ['key' => 'exploitation_bancaire', 'label' => 'Autres produits et charges bancaires', 'lines' => [
                    ['key' => 'autres_produits_bancaires', 'label' => "Autres produits d'exploitation bancaire", 'sign' => 'produit'],
                    ['key' => 'autres_charges_bancaires', 'label' => "Autres charges d'exploitation bancaire", 'sign' => 'charge_positive'],
                ]],
                ['key' => 'charges_structure', 'label' => 'Charges de structure', 'lines' => [
                    ['key' => 'subvention_investissement', 'label' => "Subvention d'investissement", 'sign' => 'produit'],
                    ['key' => 'charges_generales', 'label' => "Charges générales d'exploitation", 'sign' => 'charge_positive'],
                    ['key' => 'dotations_amortissements', 'label' => 'Dotations aux amortissements et dépréciations des immobilisations', 'sign' => 'charge_positive'],
                ]],
                ['key' => 'risque', 'label' => 'Risque et éléments exceptionnels', 'lines' => [
                    ['key' => 'cout_du_risque', 'label' => 'Coût du risque', 'sign' => 'charge_positive'],
                    ['key' => 'gains_actifs_immobilises', 'label' => 'Gains ou pertes nets sur actifs immobilisés', 'sign' => 'mixte'],
                    ['key' => 'impots_benefices', 'label' => 'Impôts sur les bénéfices', 'sign' => 'charge_positive'],
                ]],
                ['key' => 'bilan', 'label' => 'Postes de bilan (facultatifs — nécessaires au PBR et au ROE)', 'lines' => [
                    ['key' => 'total_equity', 'label' => 'Capitaux propres', 'sign' => 'produit'],
                    ['key' => 'total_assets', 'label' => 'Total bilan', 'sign' => 'produit'],
                ]],
            ],
            'subtotals' => [
                ['key' => 'produit_net_bancaire', 'label' => 'PRODUIT NET BANCAIRE', 'formula' => [
                    'interets_produits' => 1, 'interets_charges' => -1, 'revenus_titres_variable' => 1,
                    'commissions_produits' => 1, 'commissions_charges' => -1,
                    'gains_negociation' => 1, 'gains_placement' => 1,
                    'autres_produits_bancaires' => 1, 'autres_charges_bancaires' => -1,
                ]],
                ['key' => 'resultat_brut_exploitation', 'label' => "RÉSULTAT BRUT D'EXPLOITATION", 'formula' => [
                    'produit_net_bancaire' => 1, 'subvention_investissement' => 1,
                    'charges_generales' => -1, 'dotations_amortissements' => -1,
                ]],
                ['key' => 'resultat_exploitation', 'label' => "RÉSULTAT D'EXPLOITATION", 'formula' => [
                    'resultat_brut_exploitation' => 1, 'cout_du_risque' => -1,
                ]],
                ['key' => 'resultat_avant_impot', 'label' => 'RÉSULTAT AVANT IMPÔT', 'formula' => [
                    'resultat_exploitation' => 1, 'gains_actifs_immobilises' => 1,
                ]],
                ['key' => 'resultat_net', 'label' => 'RÉSULTAT NET', 'formula' => [
                    'resultat_avant_impot' => 1, 'impots_benefices' => -1,
                ]],
            ],
        ];
    }

    /** Bilan bancaire — actif, passif et capitaux propres. */
    private static function bancaireBilan(): array {
        return [
            'label' => 'Bilan bancaire',
            'description' => "Actif, passif et capitaux propres d'un établissement bancaire. Le total actif doit égaler le total passif.",
            'sign_convention' => 'tout_positif',
            'sign_note' => "Tous les montants se saisissent en POSITIF. Le contrôle d'équilibre (total actif = total passif) signale immédiatement une erreur de saisie.",
            'headline' => ['total_actif', 'capitaux_propres'],
            'groups' => [
                ['key' => 'actif', 'label' => 'ACTIF', 'lines' => [
                    ['key' => 'caisse_banque_centrale', 'label' => 'Caisse, banque centrale, CCP', 'sign' => 'produit'],
                    ['key' => 'effets_publics', 'label' => 'Effets publics et valeurs assimilées', 'sign' => 'produit'],
                    ['key' => 'creances_interbancaires', 'label' => 'Créances interbancaires et assimilées', 'sign' => 'produit'],
                    ['key' => 'creances_clientele', 'label' => 'Créances clientèle', 'sign' => 'produit'],
                    ['key' => 'obligations_revenu_fixe', 'label' => 'Obligations et autres titres à revenu fixe', 'sign' => 'produit'],
                    ['key' => 'actions_revenu_variable', 'label' => 'Actions et autres titres à revenu variable', 'sign' => 'produit'],
                    ['key' => 'actionnaires_associes', 'label' => 'Actionnaires ou associés', 'sign' => 'produit'],
                    ['key' => 'autres_actifs', 'label' => 'Autres actifs', 'sign' => 'produit'],
                    ['key' => 'compte_regularisation_actif', 'label' => 'Compte de régularisation (actif)', 'sign' => 'produit'],
                    ['key' => 'participations', 'label' => 'Participations et autres titres détenus à long terme', 'sign' => 'produit'],
                    ['key' => 'parts_entreprises_liees', 'label' => 'Parts dans les entreprises liées', 'sign' => 'produit'],
                    ['key' => 'prets_subordonnes', 'label' => 'Prêts subordonnés', 'sign' => 'produit'],
                    ['key' => 'immobilisations_incorporelles', 'label' => 'Immobilisations incorporelles', 'sign' => 'produit'],
                    ['key' => 'immobilisations_corporelles', 'label' => 'Immobilisations corporelles', 'sign' => 'produit'],
                ]],
                ['key' => 'passif', 'label' => 'PASSIF (hors capitaux propres)', 'lines' => [
                    ['key' => 'banque_centrale_passif', 'label' => 'Banque centrale, CCP', 'sign' => 'produit'],
                    ['key' => 'dettes_interbancaires', 'label' => 'Dettes interbancaires et assimilées', 'sign' => 'produit'],
                    ['key' => 'dettes_clientele', 'label' => "Dettes à l'égard de la clientèle", 'sign' => 'produit'],
                    ['key' => 'dettes_titres', 'label' => 'Dettes représentées par un titre', 'sign' => 'produit'],
                    ['key' => 'autres_passifs', 'label' => 'Autres passifs', 'sign' => 'produit'],
                    ['key' => 'compte_regularisation_passif', 'label' => 'Comptes de régularisation (passif)', 'sign' => 'produit'],
                    ['key' => 'provisions', 'label' => 'Provisions', 'sign' => 'produit'],
                    ['key' => 'emprunts_titres_subordonnes', 'label' => 'Emprunts et titres émis subordonnés', 'sign' => 'produit'],
                ]],
                ['key' => 'capitaux', 'label' => 'CAPITAUX PROPRES ET RESSOURCES ASSIMILÉES', 'lines' => [
                    ['key' => 'capital_souscrit', 'label' => 'Capital souscrit', 'sign' => 'produit'],
                    ['key' => 'capital_non_verse', 'label' => 'Capital souscrit appelé non versé', 'sign' => 'mixte'],
                    ['key' => 'reserves', 'label' => 'Réserves', 'sign' => 'produit'],
                    ['key' => 'prime_capital', 'label' => 'Prime liée au capital', 'sign' => 'produit'],
                    ['key' => 'resultat_instance_affectation', 'label' => "Résultat en instance d'affectation", 'sign' => 'mixte'],
                    ['key' => 'report_a_nouveau', 'label' => 'Report à nouveau', 'sign' => 'mixte'],
                    ['key' => 'resultat_exercice', 'label' => "Résultat de l'exercice", 'sign' => 'mixte'],
                ]],
            ],
            'subtotals' => [
                ['key' => 'total_actif', 'label' => 'TOTAL ACTIF', 'formula' => [
                    'caisse_banque_centrale' => 1, 'effets_publics' => 1, 'creances_interbancaires' => 1,
                    'creances_clientele' => 1, 'obligations_revenu_fixe' => 1, 'actions_revenu_variable' => 1,
                    'actionnaires_associes' => 1, 'autres_actifs' => 1, 'compte_regularisation_actif' => 1,
                    'participations' => 1, 'parts_entreprises_liees' => 1, 'prets_subordonnes' => 1,
                    'immobilisations_incorporelles' => 1, 'immobilisations_corporelles' => 1,
                ]],
                ['key' => 'capitaux_propres', 'label' => 'CAPITAUX PROPRES ET RESSOURCES ASSIMILÉES', 'formula' => [
                    'capital_souscrit' => 1, 'capital_non_verse' => 1, 'reserves' => 1, 'prime_capital' => 1,
                    'resultat_instance_affectation' => 1, 'report_a_nouveau' => 1, 'resultat_exercice' => 1,
                ]],
                ['key' => 'total_passif', 'label' => 'TOTAL PASSIF', 'formula' => [
                    'banque_centrale_passif' => 1, 'dettes_interbancaires' => 1, 'dettes_clientele' => 1,
                    'dettes_titres' => 1, 'autres_passifs' => 1, 'compte_regularisation_passif' => 1,
                    'provisions' => 1, 'emprunts_titres_subordonnes' => 1, 'capitaux_propres' => 1,
                ]],
                // Doit valoir 0 : c'est le contrôle d'équilibre du bilan.
                ['key' => 'ecart_equilibre', 'label' => 'ÉCART ACTIF − PASSIF (doit être nul)', 'formula' => [
                    'total_actif' => 1, 'total_passif' => -1,
                ]],
            ],
        ];
    }

    /** Tableau des flux de trésorerie OHADA (références ZA à ZH). */
    private static function fluxTresorerie(): array {
        return [
            'label' => 'Tableau des flux de trésorerie',
            'description' => "Flux OHADA (références ZA à ZH) : trésorerie générée par l'exploitation, l'investissement et le financement.",
            'sign_convention' => 'valeurs_signees',
            'sign_note' => "Saisissez les montants AVEC LEUR SIGNE, tels qu'imprimés : les décaissements sont négatifs, les encaissements positifs. Les sous-totaux sont alors de simples additions.",
            'headline' => ['flux_operationnels', 'variation_tresorerie'],
            'groups' => [
                ['key' => 'ouverture', 'label' => 'Trésorerie d\'ouverture', 'lines' => [
                    ['key' => 'ZA', 'label' => 'ZA — Trésorerie nette au 1er janvier', 'sign' => 'mixte'],
                ]],
                ['key' => 'operationnel', 'label' => 'Activités opérationnelles', 'lines' => [
                    ['key' => 'FA', 'label' => "FA — Capacité d'autofinancement globale (CAFG)", 'sign' => 'mixte'],
                    ['key' => 'FB', 'label' => "FB — Variation d'actif circulant HAO", 'sign' => 'mixte'],
                    ['key' => 'FC', 'label' => 'FC — Variation des stocks', 'sign' => 'mixte'],
                    ['key' => 'FD', 'label' => 'FD — Variation des créances', 'sign' => 'mixte'],
                    ['key' => 'FE', 'label' => 'FE — Variation du passif circulant', 'sign' => 'mixte'],
                ]],
                ['key' => 'investissement', 'label' => 'Activités d\'investissement', 'lines' => [
                    ['key' => 'FF', 'label' => "FF — Acquisitions d'immobilisations incorporelles", 'sign' => 'charge'],
                    ['key' => 'FG', 'label' => "FG — Acquisitions d'immobilisations corporelles", 'sign' => 'charge'],
                    ['key' => 'FH', 'label' => "FH — Acquisitions d'immobilisations financières", 'sign' => 'charge'],
                    ['key' => 'FI', 'label' => 'FI — Cessions d\'immobilisations incorporelles et corporelles', 'sign' => 'produit'],
                    ['key' => 'FJ', 'label' => "FJ — Cessions d'immobilisations financières", 'sign' => 'produit'],
                ]],
                ['key' => 'capitaux_propres', 'label' => 'Financement par capitaux propres', 'lines' => [
                    ['key' => 'FK', 'label' => 'FK — Augmentations de capital par apports nouveaux', 'sign' => 'produit'],
                    ['key' => 'FL', 'label' => "FL — Subventions d'investissement reçues", 'sign' => 'produit'],
                    ['key' => 'FM', 'label' => 'FM — Prélèvements sur le capital', 'sign' => 'charge'],
                    ['key' => 'FN', 'label' => 'FN — Dividendes versés', 'sign' => 'charge'],
                ]],
                ['key' => 'capitaux_etrangers', 'label' => 'Financement par capitaux étrangers', 'lines' => [
                    ['key' => 'FO', 'label' => 'FO — Emprunts', 'sign' => 'produit'],
                    ['key' => 'FP', 'label' => 'FP — Autres dettes financières diverses', 'sign' => 'produit'],
                    ['key' => 'FQ', 'label' => 'FQ — Remboursements des emprunts et dettes financières', 'sign' => 'charge'],
                ]],
            ],
            'subtotals' => [
                ['key' => 'flux_operationnels', 'label' => 'ZB — Flux des activités opérationnelles', 'formula' => [
                    'FA' => 1, 'FB' => 1, 'FC' => 1, 'FD' => 1, 'FE' => 1,
                ]],
                ['key' => 'flux_investissement', 'label' => "ZC — Flux des activités d'investissement", 'formula' => [
                    'FF' => 1, 'FG' => 1, 'FH' => 1, 'FI' => 1, 'FJ' => 1,
                ]],
                ['key' => 'flux_capitaux_propres', 'label' => 'ZD — Flux des capitaux propres', 'formula' => [
                    'FK' => 1, 'FL' => 1, 'FM' => 1, 'FN' => 1,
                ]],
                ['key' => 'flux_capitaux_etrangers', 'label' => 'ZE — Flux des capitaux étrangers', 'formula' => [
                    'FO' => 1, 'FP' => 1, 'FQ' => 1,
                ]],
                ['key' => 'flux_financement', 'label' => 'ZF — Flux des activités de financement', 'formula' => [
                    'flux_capitaux_propres' => 1, 'flux_capitaux_etrangers' => 1,
                ]],
                ['key' => 'variation_tresorerie', 'label' => 'ZG — Variation de la trésorerie nette', 'formula' => [
                    'flux_operationnels' => 1, 'flux_investissement' => 1, 'flux_financement' => 1,
                ]],
                ['key' => 'tresorerie_cloture', 'label' => 'ZH — Trésorerie nette au 31 décembre', 'formula' => [
                    'variation_tresorerie' => 1, 'ZA' => 1,
                ]],
            ],
        ];
    }

    /**
     * Dividendes versés — saisie directe d'une distribution.
     *
     * Complète les dividendes extraits automatiquement des bulletins BRVM
     * (écran « Opérations sur titres ») : ceux-ci ne couvrent que les
     * bulletins déjà analysés, et un même versement y apparaît parfois en
     * double. Ici la distribution est saisie une fois, proprement, avec sa
     * date de paiement.
     *
     * La DATE DE PAIEMENT est la date de clôture de l'en-tête : un dividende
     * n'a pas de « période », il a une date. Le champ « Date de clôture » du
     * formulaire sert donc de date de versement.
     *
     * Le montant NET (après IRVM, l'impôt sur les revenus de valeurs
     * mobilières, 10 à 12 % selon la situation de l'actionnaire) est saisi à
     * part du BRUT : c'est le net qui arrive réellement sur le compte, mais
     * c'est le brut qui est annoncé par l'émetteur.
     */
    private static function dividendes(): array {
        return [
            'label' => 'Dividendes versés',
            'description' => "Une distribution par saisie : montant par action, brut et net d'IRVM, avec calcul automatique du rendement et du taux de distribution. La date de clôture sert de date de paiement.",
            'sign_convention' => 'tout_positif',
            'sign_note' => "Saisissez les montants en POSITIF. Renseignez au minimum le dividende BRUT par action : le reste est facultatif et sert à affiner les calculs.",
            'headline' => ['dividende_par_action', 'dividende_total'],
            'groups' => [
                ['key' => 'par_action', 'label' => 'Montant par action', 'lines' => [
                    ['key' => 'dividende_brut_par_action', 'label' => 'Dividende brut par action (montant annoncé)', 'sign' => 'produit'],
                    ['key' => 'dividende_net_par_action', 'label' => "Dividende net par action (après IRVM, si connu)", 'sign' => 'produit'],
                ]],
                ['key' => 'global', 'label' => 'Montant global (facultatif)', 'lines' => [
                    ['key' => 'dividende_total_verse', 'label' => 'Montant total distribué', 'sign' => 'produit'],
                    ['key' => 'nombre_actions_remunerees', 'label' => "Nombre d'actions rémunérées (si différent du capital)", 'sign' => 'produit'],
                ]],
                ['key' => 'contexte', 'label' => "Contexte de l'exercice (facultatif — permet le taux de distribution)", 'lines' => [
                    ['key' => 'resultat_net_exercice', 'label' => "Résultat net de l'exercice concerné", 'sign' => 'mixte'],
                    ['key' => 'cours_reference', 'label' => 'Cours de référence retenu (sinon cours de bourse à la date)', 'sign' => 'produit'],
                ]],
            ],
            'subtotals' => [
                // Le brut fait foi ; à défaut, le net évite une case vide.
                ['key' => 'dividende_par_action', 'label' => 'DIVIDENDE PAR ACTION (brut)', 'formula' => [
                    'dividende_brut_par_action' => 1,
                ]],
                ['key' => 'dividende_net', 'label' => "DIVIDENDE NET PAR ACTION", 'formula' => [
                    'dividende_net_par_action' => 1,
                ]],
                ['key' => 'dividende_total', 'label' => 'MONTANT TOTAL DISTRIBUÉ', 'formula' => [
                    'dividende_total_verse' => 1,
                ]],
            ],
        ];
    }

    /**
     * Chiffres clés — saisie rapide, sans le détail des postes.
     *
     * Pensé pour deux situations très courantes : les communications
     * trimestrielles, qui ne publient que quelques agrégats déjà consolidés,
     * et le cas où l'on veut simplement alimenter les graphes et les ratios
     * sans ressaisir un compte de résultat entier.
     *
     * Seul format où le RÉSULTAT NET se SAISIT directement : ailleurs il est
     * recalculé depuis les postes, ce qui sert de contrôle de cohérence.
     * Ici il n'y a rien à recalculer, l'émetteur publie déjà le total.
     *
     * Couvre aussi bien une entreprise commerciale (chiffre d'affaires)
     * qu'une banque (produit net bancaire) : les ratios utilisent celui des
     * deux qui est renseigné.
     */
    private static function activiteSimplifie(): array {
        return [
            'label' => 'Chiffres clés (saisie rapide)',
            'description' => "Quelques agrégats seulement, sans le détail des postes : chiffre d'affaires ou produit net bancaire, résultat net, capitaux propres. Suffit à alimenter les graphes, le PER, le PBR et le ROE.",
            'sign_convention' => 'valeurs_signees',
            'sign_note' => "Saisissez les montants AVEC LEUR SIGNE, tels qu'imprimés : un résultat déficitaire se saisit en négatif. C'est le seul format où le résultat net se saisit directement — ailleurs il est recalculé depuis les postes.",
            'headline' => ['revenu', 'resultat_net'],
            'groups' => [
                ['key' => 'revenus', 'label' => "Revenu d'activité — renseignez celui qui figure dans votre document", 'lines' => [
                    ['key' => 'chiffre_affaires', 'label' => "Chiffre d'affaires (entreprise commerciale/industrielle)", 'sign' => 'produit'],
                    ['key' => 'produit_net_bancaire', 'label' => 'Produit net bancaire (banque)', 'sign' => 'produit'],
                ]],
                ['key' => 'resultats', 'label' => 'Résultats publiés', 'lines' => [
                    ['key' => 'resultat_exploitation', 'label' => "Résultat d'exploitation", 'sign' => 'mixte'],
                    ['key' => 'resultat_activites_ordinaires', 'label' => 'Résultat des activités ordinaires', 'sign' => 'mixte'],
                    ['key' => 'resultat_net_saisi', 'label' => 'Résultat net', 'sign' => 'mixte'],
                ]],
                ['key' => 'bilan', 'label' => 'Postes de bilan (facultatifs — nécessaires au PBR, au ROE et au ROA)', 'lines' => [
                    ['key' => 'total_equity', 'label' => 'Capitaux propres', 'sign' => 'produit'],
                    ['key' => 'total_debt', 'label' => 'Dettes financières', 'sign' => 'produit'],
                    ['key' => 'total_assets', 'label' => 'Total bilan', 'sign' => 'produit'],
                ]],
            ],
            // Les agrégats sont publiés tels quels : les « sous-totaux » ne
            // font que les reprendre sous les clés communes à tous les
            // formats, pour que graphes et ratios lisent toujours la même
            // chose quel que soit le document saisi.
            'subtotals' => [
                ['key' => 'revenu', 'label' => "REVENU D'ACTIVITÉ", 'formula' => [
                    'chiffre_affaires' => 1, 'produit_net_bancaire' => 1,
                ]],
                ['key' => 'resultat_exploitation', 'label' => "RÉSULTAT D'EXPLOITATION", 'formula' => ['resultat_exploitation' => 1]],
                ['key' => 'resultat_activites_ordinaires', 'label' => 'RÉSULTAT DES ACTIVITÉS ORDINAIRES', 'formula' => ['resultat_activites_ordinaires' => 1]],
                ['key' => 'resultat_net', 'label' => 'RÉSULTAT NET', 'formula' => ['resultat_net_saisi' => 1]],
            ],
        ];
    }
}
