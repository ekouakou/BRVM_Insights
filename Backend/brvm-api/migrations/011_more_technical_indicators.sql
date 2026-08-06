-- Migration 011 : nouvelles colonnes sur technical_indicators pour les
-- indicateurs déjà codés dans api_technical_indicators.php (formules
-- ad-hoc, jamais persistées) mais jusqu'ici jamais calculés à chaque
-- synchro ni affichés (voir TODO_ANALYSES.md, point 13).
--
-- adx_14 : force de la tendance (Average Directional Index, 14 jours,
--   lissage de Wilder) — ne dit pas si le marché monte ou descend, juste
--   à quel point la tendance en cours (quelle qu'elle soit) est marquée.
-- stoch_k / stoch_d : Oscillateur stochastique (%K sur 14 jours, %D =
--   moyenne mobile 3 jours de %K) — momentum, sur/sous-achat.
-- roc_12 : Rate of Change 12 jours (%) — momentum simple, cours actuel vs
--   cours il y a 12 jours.
-- obv : On-Balance Volume — cumul du volume signé (jour de hausse =
--   +volume, jour de baisse = -volume) depuis la première valeur calculée
--   pour l'entreprise. Valeur absolue arbitraire (dépend du point de
--   départ) : seule la tendance de la courbe a un sens, pas le niveau.
-- vwap : Volume Weighted Average Price du jour, calculé à partir des
--   relevés intrajournaliers (intraday_quotes) de CE jour uniquement — se
--   réinitialise donc chaque jour, contrairement à l'implémentation ad-hoc
--   existante dans api_technical_indicators.php (bug connu : cumule sur
--   toute la fenêtre demandée au lieu de repartir de zéro chaque jour).
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

ALTER TABLE technical_indicators
    ADD COLUMN adx_14 DECIMAL(10,4) NULL COMMENT 'Average Directional Index 14j (force de tendance, 0-100)' AFTER atr_14,
    ADD COLUMN stoch_k DECIMAL(10,4) NULL COMMENT 'Oscillateur stochastique %K (14j)' AFTER adx_14,
    ADD COLUMN stoch_d DECIMAL(10,4) NULL COMMENT 'Oscillateur stochastique %D (moyenne mobile 3j de %K)' AFTER stoch_k,
    ADD COLUMN roc_12 DECIMAL(10,4) NULL COMMENT 'Rate of Change 12j (%)' AFTER stoch_d,
    ADD COLUMN obv DECIMAL(20,4) NULL COMMENT 'On-Balance Volume cumulatif (valeur absolue arbitraire, seule la tendance compte)' AFTER roc_12,
    ADD COLUMN vwap DECIMAL(15,4) NULL COMMENT 'Volume Weighted Average Price du jour (basé sur intraday_quotes, se réinitialise chaque jour)' AFTER obv;
