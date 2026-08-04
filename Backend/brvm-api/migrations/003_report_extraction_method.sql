-- ALREADY-IN-BD-SQL
-- Migration 003 : trace la méthode d'extraction du texte (natif vs OCR) pour
-- chaque rapport, utile pour distinguer un texte fiable (pdftotext) d'un texte
-- potentiellement imparfait (OCR sur PDF scanné).

-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (le nom réel peut différer selon l'hébergeur — voir
-- scripts/migrate.php, qui applique ce fichier sans le modifier).

ALTER TABLE company_reports
    ADD COLUMN extraction_method VARCHAR(10) NULL COMMENT 'text (pdftotext) ou ocr (tesseract)'
        AFTER text_extracted;
