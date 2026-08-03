-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Hôte : localhost:8889
-- Généré le : lun. 03 août 2026 à 20:10
-- Version du serveur : 5.7.39
-- Version de PHP : 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `brimmobi_brvm_trading_app`
--

DELIMITER $$
--
-- Procédures
--
CREATE DEFINER=`brimmobi`@`localhost` PROCEDURE `calculate_technical_indicators` (IN `p_company_id` INT, IN `p_trading_date` DATE)   BEGIN
    DECLARE v_sma_10 DECIMAL(15, 4);
    DECLARE v_sma_20 DECIMAL(15, 4);
    DECLARE v_sma_50 DECIMAL(15, 4);
    
    
    SELECT AVG(close_price) INTO v_sma_10
    FROM (
        SELECT close_price 
        FROM stock_quotes 
        WHERE company_id = p_company_id 
        AND trading_date <= p_trading_date
        ORDER BY trading_date DESC 
        LIMIT 10
    ) AS recent_prices;
    
    
    SELECT AVG(close_price) INTO v_sma_20
    FROM (
        SELECT close_price 
        FROM stock_quotes 
        WHERE company_id = p_company_id 
        AND trading_date <= p_trading_date
        ORDER BY trading_date DESC 
        LIMIT 20
    ) AS recent_prices;
    
    
    SELECT AVG(close_price) INTO v_sma_50
    FROM (
        SELECT close_price 
        FROM stock_quotes 
        WHERE company_id = p_company_id 
        AND trading_date <= p_trading_date
        ORDER BY trading_date DESC 
        LIMIT 50
    ) AS recent_prices;
    
    
    INSERT INTO technical_indicators (
        company_id, trading_date, sma_10, sma_20, sma_50
    ) VALUES (
        p_company_id, p_trading_date, v_sma_10, v_sma_20, v_sma_50
    )
    ON DUPLICATE KEY UPDATE
        sma_10 = v_sma_10,
        sma_20 = v_sma_20,
        sma_50 = v_sma_50;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Structure de la table `admin_sessions`
--

CREATE TABLE `admin_sessions` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `admin_sessions`
--

INSERT INTO `admin_sessions` (`id`, `user_id`, `token`, `expires_at`, `created_at`) VALUES
(1, 1, '0b1c04de7291fb644a74dd3e6692ce2fc77d5f81438d9a6cf379c06a64ce4fd3', '2026-08-10 00:53:46', '2026-08-03 00:53:46'),
(2, 1, 'b45191c72b81b19661fbd39f0ab5d3e8abae55103c24049857e79f7ecb6f5369', '2026-08-10 01:02:47', '2026-08-03 01:02:47'),
(4, 1, '8d432dd376259f98c0ad02cb1d571b8f5aa12df46c4a84360d01f2be04371a8a', '2026-08-10 02:31:07', '2026-08-03 02:31:07'),
(5, 1, '7ae07abf4c132e25246d9ca5a19ea69b04e14077858c79af1b36b6b5afa8945b', '2026-08-10 02:46:04', '2026-08-03 02:46:04'),
(6, 1, '9116856a38a52d5a2ea4a4805733aeafa121c84c72011293cf7bd01514080b7f', '2026-08-10 19:38:31', '2026-08-03 19:38:31');

-- --------------------------------------------------------

--
-- Structure de la table `admin_users`
--

CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `admin_users`
--

INSERT INTO `admin_users` (`id`, `username`, `password_hash`, `created_at`) VALUES
(1, 'brimmobi', '$2y$10$PKvwWx9fSWHCRNlnOrRDqemOnDjHzvN7Pu3JBDwvUkAji52pF/YYi', '2026-08-02 23:20:03');

-- --------------------------------------------------------

--
-- Structure de la table `combined_analyses`
--

CREATE TABLE `combined_analyses` (
  `id` bigint(20) NOT NULL,
  `request_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'sha256(report_ids triés + bulletin_ids triés)',
  `report_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `bulletin_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `provider` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `computed_date` date NOT NULL COMMENT 'date du calcul — cache "une fois par jour"',
  `summary` text COLLATE utf8mb4_unicode_ci,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin COMMENT 'analyse combinée complète + chart_data',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `input_char_count` int(11) DEFAULT NULL,
  `raw_response` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `companies`
--

CREATE TABLE `companies` (
  `id` int(11) NOT NULL,
  `symbol` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Symbole boursier',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sector_id` int(11) DEFAULT NULL,
  `country_id` int(11) DEFAULT NULL,
  `isin_code` varchar(12) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Code ISIN international',
  `market_cap` decimal(20,2) DEFAULT NULL COMMENT 'Capitalisation boursière',
  `shares_outstanding` bigint(20) DEFAULT NULL COMMENT 'Nombre d''actions en circulation',
  `listing_date` date DEFAULT NULL COMMENT 'Date d''introduction en bourse',
  `website` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `logo_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `brvm_report_slug` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Slug de /fr/rapports-societe-cotes/{slug} sur brvm.org',
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `companies`
--

INSERT INTO `companies` (`id`, `symbol`, `name`, `full_name`, `sector_id`, `country_id`, `isin_code`, `market_cap`, `shares_outstanding`, `listing_date`, `website`, `description`, `logo_url`, `brvm_report_slug`, `active`, `created_at`, `updated_at`) VALUES
(1, 'ABJC', 'SERVAIR ABIDJAN COTE D\'IVOIRE', 'SERVAIR ABIDJAN COTE D\'IVOIRE', 9, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'servair-abidjan-ci', 1, '2026-08-03 01:06:54', '2026-08-03 19:48:48'),
(2, 'BICB', 'BANQUE INTERNATIONALE POUR L’INDUSTRIE ET LE COMMERCE DU BENIN', 'BANQUE INTERNATIONALE POUR L’INDUSTRIE ET LE COMMERCE DU BENIN', 1, 4, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-03 01:06:54', '2026-08-03 19:48:48'),
(3, 'BICC', 'BICI COTE D\'IVOIRE', 'BICI COTE D\'IVOIRE', 1, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'bici-ci', 1, '2026-08-03 01:06:54', '2026-08-03 19:48:48'),
(4, 'BNBC', 'BERNABE COTE D\'IVOIRE', 'BERNABE COTE D\'IVOIRE', 3, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'bernabe-ci', 1, '2026-08-03 01:06:54', '2026-08-03 19:48:48'),
(5, 'BOAB', 'BANK OF AFRICA BENIN', 'BANK OF AFRICA BENIN', 1, 4, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'bank-africa-bn', 1, '2026-08-03 01:06:54', '2026-08-03 19:48:48'),
(6, 'BOABF', 'BANK OF AFRICA BURKINA FASO', 'BANK OF AFRICA BURKINA FASO', 1, 3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'bank-africa-bf', 1, '2026-08-03 01:06:54', '2026-08-03 19:48:48'),
(7, 'BOAC', 'BANK OF AFRICA COTE D\'IVOIRE', 'BANK OF AFRICA COTE D\'IVOIRE', 1, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'bank-africa-ci', 1, '2026-08-03 01:06:54', '2026-08-03 19:48:48'),
(8, 'BOAM', 'BANK OF AFRICA MALI', 'BANK OF AFRICA MALI', 1, 7, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'bank-africa-ml', 1, '2026-08-03 01:06:54', '2026-08-03 19:48:48'),
(9, 'BOAN', 'BANK OF AFRICA NIGER', 'BANK OF AFRICA NIGER', 1, 6, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'bank-africa-ng', 1, '2026-08-03 01:06:54', '2026-08-03 19:48:48'),
(10, 'BOAS', 'BANK OF AFRICA SENEGAL', 'BANK OF AFRICA SENEGAL', 1, 2, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'bank-africa-sn', 1, '2026-08-03 01:06:54', '2026-08-03 19:48:48'),
(11, 'CABC', 'SICABLE COTE D\'IVOIRE', 'SICABLE COTE D\'IVOIRE', 5, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'sicable', 1, '2026-08-03 01:06:54', '2026-08-03 19:48:48'),
(12, 'CBIBF', 'CORIS BANK INTERNATIONAL BURKINA FASO', 'CORIS BANK INTERNATIONAL BURKINA FASO', 1, 3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'coris-bank-international', 1, '2026-08-03 01:06:54', '2026-08-03 19:48:48'),
(13, 'CFAC', 'CFAO MOTORS COTE D\'IVOIRE', 'CFAO MOTORS COTE D\'IVOIRE', 3, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'cfao-motors-ci', 1, '2026-08-03 01:06:54', '2026-08-03 19:48:48'),
(14, 'CIEC', 'CIE COTE D\'IVOIRE', 'CIE COTE D\'IVOIRE', 8, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'cie-ci', 1, '2026-08-03 01:06:54', '2026-08-03 19:48:48'),
(15, 'ECOC', 'ECOBANK COTE D\'IVOIRE', 'ECOBANK COTE D\'IVOIRE', 1, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'ecobank-ci', 1, '2026-08-03 01:06:54', '2026-08-03 19:48:48'),
(16, 'ETIT', 'Ecobank Transnational Incorporated TOGO', 'Ecobank Transnational Incorporated TOGO', 1, 5, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-03 01:06:54', '2026-08-03 19:48:48'),
(17, 'FTSC', 'FILTISAC COTE D\'IVOIRE', 'FILTISAC COTE D\'IVOIRE', 5, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'filtisac-ci', 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(18, 'LNBB', 'LOTERIE NATIONALE DU BENIN', 'LOTERIE NATIONALE DU BENIN', 9, 4, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(19, 'NEIC', 'NEI-CEDA COTE D\'IVOIRE', 'NEI-CEDA COTE D\'IVOIRE', 9, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'nei-ceda-ci', 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(20, 'NSBC', 'NSIA BANQUE COTE D\'IVOIRE', 'NSIA BANQUE COTE D\'IVOIRE', 1, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(21, 'NTLC', 'NESTLE COTE D\'IVOIRE', 'NESTLE COTE D\'IVOIRE', 5, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'nestle-ci', 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(22, 'ONTBF', 'ONATEL BURKINA FASO', 'ONATEL BURKINA FASO', 7, 3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(23, 'ORAC', 'ORANGE COTE D\'IVOIRE', 'ORANGE COTE D\'IVOIRE', 7, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'orange-ci', 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(24, 'ORGT', 'ORAGROUP TOGO', 'ORAGROUP TOGO', 1, 5, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'oragroup', 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(25, 'PALC', 'PALM COTE D\'IVOIRE', 'PALM COTE D\'IVOIRE', 4, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'palm-ci', 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(26, 'PRSC', 'TRACTAFRIC MOTORS COTE D\'IVOIRE', 'TRACTAFRIC MOTORS COTE D\'IVOIRE', 3, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(27, 'SAFC', 'SAFCA COTE D\'IVOIRE', 'SAFCA COTE D\'IVOIRE', 9, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'safca-ci', 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(28, 'SCRC', 'SUCRIVOIRE COTE D\'IVOIRE', 'SUCRIVOIRE COTE D\'IVOIRE', 4, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'sucrivoire', 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(29, 'SDCC', 'SODE COTE D\'IVOIRE', 'SODE COTE D\'IVOIRE', 8, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(30, 'SDSC', 'AFRICA GLOBAL LOGISTICS COTE D\'IVOIRE', 'AFRICA GLOBAL LOGISTICS COTE D\'IVOIRE', 6, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(31, 'SEMC', 'EVIOSYS PACKAGING SIEM COTE D\'IVOIRE', 'EVIOSYS PACKAGING SIEM COTE D\'IVOIRE', 5, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(32, 'SGBC', 'SOCIETE GENERALE COTE D\'IVOIRE', 'SOCIETE GENERALE COTE D\'IVOIRE', 1, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(33, 'SHEC', 'VIVO ENERGY COTE D\'IVOIRE', 'VIVO ENERGY COTE D\'IVOIRE', 8, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'vivo-energy-ci', 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(34, 'SIBC', 'SOCIETE IVOIRIENNE DE BANQUE COTE D\'IVOIRE', 'SOCIETE IVOIRIENNE DE BANQUE COTE D\'IVOIRE', 1, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(35, 'SICC', 'SICOR COTE D\'IVOIRE', 'SICOR COTE D\'IVOIRE', 4, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'sicor', 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(36, 'SIVC', 'ERIUM COTE D’IVOIRE', 'ERIUM COTE D’IVOIRE', 5, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(37, 'SLBC', 'SOLIBRA COTE D\'IVOIRE', 'SOLIBRA COTE D\'IVOIRE', 5, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'solibra', 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(38, 'SMBC', 'SMB COTE D\'IVOIRE', 'SMB COTE D\'IVOIRE', 5, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'smb', 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(39, 'SNTS', 'SONATEL SENEGAL', 'SONATEL SENEGAL', 7, 2, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(40, 'SOGC', 'SOGB COTE D\'IVOIRE', 'SOGB COTE D\'IVOIRE', 4, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'sogb', 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(41, 'SPHC', 'SAPH COTE D\'IVOIRE', 'SAPH COTE D\'IVOIRE', 4, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'saph-ci', 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(42, 'STAC', 'SETAO COTE D\'IVOIRE', 'SETAO COTE D\'IVOIRE', 5, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'setao-ci', 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(43, 'STBC', 'SITAB COTE D\'IVOIRE', 'SITAB COTE D\'IVOIRE', 5, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'sitab', 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(44, 'TTLC', 'TOTALENERGIES MARKETING COTE D\'IVOIRE', 'TOTALENERGIES MARKETING COTE D\'IVOIRE', 8, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(45, 'TTLS', 'TOTALENERGIES MARKETING SENEGAL', 'TOTALENERGIES MARKETING SENEGAL', 8, 2, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(46, 'UNLC', 'UNILEVER COTE D\'IVOIRE', 'UNILEVER COTE D\'IVOIRE', 5, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'unilever-ci', 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48'),
(47, 'UNXC', 'UNIWAX COTE D\'IVOIRE', 'UNIWAX COTE D\'IVOIRE', 5, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uniwax-ci', 1, '2026-08-03 01:06:55', '2026-08-03 19:48:48');

-- --------------------------------------------------------

--
-- Structure de la table `company_reports`
--

CREATE TABLE `company_reports` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `report_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'annuel, semestriel, trimestriel, etats_financiers, attestation_cac, autre',
  `title` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `publish_date` date DEFAULT NULL COMMENT 'Déduite du préfixe YYYYMMDD du nom de fichier (peut être approximative)',
  `file_url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'URL source sur brvm.org',
  `local_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Chemin local du PDF téléchargé',
  `file_size` bigint(20) DEFAULT NULL COMMENT 'Taille du fichier en octets',
  `file_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'SHA-256 du contenu téléchargé (détection de doublons/changements)',
  `downloaded_at` timestamp NULL DEFAULT NULL,
  `text_extracted` tinyint(1) DEFAULT '0',
  `extraction_method` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'text (pdftotext) ou ocr (tesseract)',
  `extraction_error` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `company_reports`
--

INSERT INTO `company_reports` (`id`, `company_id`, `report_type`, `title`, `publish_date`, `file_url`, `local_path`, `file_size`, `file_hash`, `downloaded_at`, `text_extracted`, `extraction_method`, `extraction_error`, `created_at`, `updated_at`) VALUES
(1, 1, 'etats_financiers', 'SERVAIR ABIDJAN CI : Etats financiers IFRS - Exercice 2025', '2026-04-27', 'https://www.brvm.org/sites/default/files/20260427_-_etats_financiers_ifrs_-_exercice_2025_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20260427_-_etats_financiers_ifrs_-_exercice_2025_-_servair_abidjan_ci.pdf', 136490, 'b462e7b0ec2ad1693feaca6b1f887a897854f523a51ae960226f011cd53ed8f0', '2026-08-03 01:56:37', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:37'),
(2, 1, 'etats_financiers', 'SERVAIR ABIDJAN CI : Etats financiers SYSCOHADA - Exercice 2025', '2026-04-27', 'https://www.brvm.org/sites/default/files/20260427_-_etats_financiers_syscohada_-_exercice_2025_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20260427_-_etats_financiers_syscohada_-_exercice_2025_-_servair_abidjan_ci.pdf', 948241, 'e23e292ddf038441d0cfb6ac648c169202e9545bb40a0ed21d95c559ae79db71', '2026-08-03 01:56:36', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:36'),
(3, 1, 'trimestriel', 'SERVAIR ABIDJAN CI : Rapport d\'activités - 1er trimestre 2026', '2026-04-27', 'https://www.brvm.org/sites/default/files/20260427_-_rapport_dactivites_-_1er_trimestre_2026_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20260427_-_rapport_dactivites_-_1er_trimestre_2026_-_servair_abidjan_ci.pdf', 743857, '884befac3e6b3b89a7124336d958931296efdbf45195b289c6faa8431eb717ed', '2026-08-03 01:56:36', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:36'),
(4, 1, 'semestriel', 'SERVAIR ABIDJAN CÔTE D\'IVOIRE : Attestation des Commissaires Aux Comptes sur le rapport d\'activités - 1er semestre 2025', '2025-10-13', 'https://www.brvm.org/sites/default/files/20251013_-_attestation_des_commissaires_aux_comptes_sur_le_rapport_dactivites_-_1er_semestre_2025_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20251013_-_attestation_des_commissaires_aux_comptes_sur_le_rapport_dactivites_-_1er_semestre_2025_-_servair_abidjan_ci.pdf', 378198, 'd510b3f34a4906563395fd9b59135c43b01cb5c7b521865085a4a8bcf78b1bd9', '2026-08-03 01:56:38', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:38'),
(5, 1, 'semestriel', 'SERVAIR ABIDJAN CÔTE D\'IVOIRE : Rapport d\'activités - 1er semestre 2025', '2025-10-13', 'https://www.brvm.org/sites/default/files/20251013_-_rapport_dactivites_-_1er_semestre_2025_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20251013_-_rapport_dactivites_-_1er_semestre_2025_-_servair_abidjan_ci.pdf', 730187, 'e38ed3057859d65ada8fdef810e3799d418a69c3d9370106bd507683a44029d0', '2026-08-03 01:56:37', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:37'),
(6, 1, 'trimestriel', 'SERVAIR ABIDJAN CÔTE D\'IVOIRE : Rapport d\'activités - 3ème trimestre 2025', '2025-10-13', 'https://www.brvm.org/sites/default/files/20251013_-_rapport_dactivites_-_3eme_trimestre_2025_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20251013_-_rapport_dactivites_-_3eme_trimestre_2025_-_servair_abidjan_ci.pdf', 318591, 'a866ec215043eedcf5ca342b1c9cfd21cef5b8f3d43d6fd20ab63bc0a18fa414', '2026-08-03 01:56:37', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:37'),
(7, 1, 'etats_financiers', 'SERVAIR ABIDJAN CÔTE D\'IVOIRE : Etats financiers - Norme SYSCOHADA - Exercice 2024', '2025-04-30', 'https://www.brvm.org/sites/default/files/20250430_-_etats_financiers_-_norme_syscohada_-_exercice_2024_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20250430_-_etats_financiers_-_norme_syscohada_-_exercice_2024_-_servair_abidjan_ci.pdf', 540350, '6ad3a0ea3ed17d540b68efb560fa839eae62d5fe93dcca9ac72070888f3d2cfc', '2026-08-03 01:56:39', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:39'),
(8, 1, 'trimestriel', 'SERVAIR ABIDJAN CÔTE D\'IVOIRE : Rapport d\'activités du 1er trimestre 2025', '2025-04-30', 'https://www.brvm.org/sites/default/files/20250430_-_rapport_dactivites_-_1er_trimestre_2025_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20250430_-_rapport_dactivites_-_1er_trimestre_2025_-_servair_abidjan_ci.pdf', 154786, 'f1c811b14886925d526893d26b48d3fa59fec94e8a2424db3340e3832985ccab', '2026-08-03 01:56:38', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:38'),
(9, 1, 'etats_financiers', 'SERVAIR ABIDJAN CÔTE D\'IVOIRE :  Etats financiers - Norme IFRS - Exercice 2024', '2025-04-30', 'https://www.brvm.org/sites/default/files/20250430_-_etats_financiers_-_norme_ifrs_-_exercice_2024_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20250430_-_etats_financiers_-_norme_ifrs_-_exercice_2024_-_servair_abidjan_ci.pdf', 930815, '90f3becd82db6def81d3cc3151fc99bb0f5ed9173dd933b936f2cecc6d56864c', '2026-08-03 01:56:38', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:38'),
(10, 1, 'semestriel', 'SERVAIR ABIDJAN CI : Attestation des Commissaires Aux Comptes sur le rapport d\'activités sur le rapport d\'activités - 1er semestre 2024', '2024-11-04', 'https://www.brvm.org/sites/default/files/20241104_-_attestation_des_cacs_sur_le_rapport_dactivites_-_1er_semestre_2024_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20241104_-_attestation_des_cacs_sur_le_rapport_dactivites_-_1er_semestre_2024_-_servair_abidjan_ci.pdf', 314110, '48368a951881cc676bb746e990c36114ee9527958404850c217577e171895c02', '2026-08-03 01:56:39', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:39'),
(11, 1, 'trimestriel', 'SERVAIR ABIDJAN CI : Rapport d\'activités - 3ème trimestre 2024', '2024-10-31', 'https://www.brvm.org/sites/default/files/20241031_-_rapport_dactivites_-_3eme_trimestre_2024_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20241031_-_rapport_dactivites_-_3eme_trimestre_2024_-_servair_abidjan_ci.pdf', 300134, 'ef00d3bccec9df9ca73342c3b75292b6ab48f984b7ac9f8d98b21ae8df9640cd', '2026-08-03 01:56:40', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:40'),
(12, 1, 'semestriel', 'SERVAIR ABIDJAN CI : Rapport d\'activités attesté par les Commissaires Aux Comptes - 1er semestre 2024', '2024-10-31', 'https://www.brvm.org/sites/default/files/20241031_-_rapport_dactivites_atteste_par_les_cacs_-_1er_semestre_2024_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20241031_-_rapport_dactivites_atteste_par_les_cacs_-_1er_semestre_2024_-_servair_abidjan_ci.pdf', 371608, 'd2f20ef78b0bf4e971046772399c2ca46b672ae3027963be3de29d5bf39908e4', '2026-08-03 01:56:39', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:39'),
(13, 1, 'etats_financiers', 'SERVAIR ABIDJAN CI : Etats financiers approuvés - Exercice 2023', '2024-08-19', 'https://www.brvm.org/sites/default/files/20240819_-_etats_financiers_approuves_-_exercice_2023_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20240819_-_etats_financiers_approuves_-_exercice_2023_-_servair_abidjan_ci.pdf', 775238, '092b94a9670bdce08864f97a54ad37595e55a74cde2f94d92ac11684b35e4f08', '2026-08-03 01:56:40', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:40'),
(14, 1, 'etats_financiers', 'SERVAIR ABIDJAN CI : Etats financiers provisoires - Exercice 2023', '2024-04-25', 'https://www.brvm.org/sites/default/files/20240425_-_etats_financiers_provisoires_-_exercice_2023_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20240425_-_etats_financiers_provisoires_-_exercice_2023_-_servair_abidjan_ci.pdf', 526421, 'ef6464a4a3f23b469bfa4ad95432b73dbb3d8434f07c6624a7aef802bad95b78', '2026-08-03 01:56:40', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:40'),
(15, 1, 'trimestriel', 'SERVAIR ABIDJAN CI : Rapport d\'activités - 1er trimestre 2024', '2024-04-25', 'https://www.brvm.org/sites/default/files/20240425_-_rapport_dactivites_-_1er_trimestre_2024_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20240425_-_rapport_dactivites_-_1er_trimestre_2024_-_servair_abidjan_ci.pdf', 68576, '39ebdf489f907a92cdcd35d99e1a03d85aa629d20c9c49c76dc89b47e33d334e', '2026-08-03 01:56:40', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:40'),
(16, 1, 'semestriel', 'SERVAIR ABIDJAN CI : Rapport d\'activités et Attestation des Commissaires Aux Comptes du 1er Semestre 2022', '2024-01-03', 'https://www.brvm.org/sites/default/files/20240103_-_rapport_dactivites_et_attestation_des_cac_-_1er_semestre_2022_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20240103_-_rapport_dactivites_et_attestation_des_cac_-_1er_semestre_2022_-_servair_abidjan_ci.pdf', 433211, '6f2ad463868493b0866bea1941f3e2b3a94f267bba1568e3396e50fce39dddd7', '2026-08-03 01:56:41', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:41'),
(17, 1, 'trimestriel', 'SERVAIR ABIDJAN CI : Rapport d\'activités - 3ème trimestre 2023', '2024-01-03', 'https://www.brvm.org/sites/default/files/20240103_-_rapport_dactivites_-_3eme_trimestre_2023_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20240103_-_rapport_dactivites_-_3eme_trimestre_2023_-_servair_abidjan_ci.pdf', 83273, '4f73d7dcc1026397352b5b4e1f10072df7a76175c77d93e2c166d162be3d79ca', '2026-08-03 01:56:41', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:41'),
(18, 1, 'semestriel', 'SERVAIR ABIDJAN CI : Rapport d\'activités et Attestation des Commissaires Aux Comptes - 1er Semestre 2023', '2023-11-02', 'https://www.brvm.org/sites/default/files/20231102_-_rapport_dactivites_et_attestation_des_cac_-_1er_semestre_2023_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20231102_-_rapport_dactivites_et_attestation_des_cac_-_1er_semestre_2023_-_servair_abidjan_ci.pdf', 674945, '765ba27ecfecba755f53a15c888e6ec27189c6360e08d5f842c420d5002a425a', '2026-08-03 01:56:42', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:42'),
(19, 1, 'semestriel', 'SERVAIR ABIDJAN CI : Rapport d\'activités - 1er Semestre 2023', '2023-11-02', 'https://www.brvm.org/sites/default/files/20231102_-_rapport_dactivites_-_1er_semestre_2023_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20231102_-_rapport_dactivites_-_1er_semestre_2023_-_servair_abidjan_ci.pdf', 351648, 'd21bce45f15fe0db1d444638fd04697f8e17487b2b345234ffd03dadb05ab94a', '2026-08-03 01:56:41', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:41'),
(20, 1, 'semestriel', 'SERVAIR ABIDJAN CI : Rapport d\'activités du 1er Semestre 2023 (Annule et remplace le précédent)', '2023-07-21', 'https://www.brvm.org/sites/default/files/20230721_-_rapport_dactivites_-_1er_semestre_2023_-_servair_abidjan_ci_annule_et_remplace_le_precedent.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20230721_-_rapport_dactivites_-_1er_semestre_2023_-_servair_abidjan_ci_annule_et_remplace_le_precedent.pdf', 82265, 'baeb81c51c9242b3b13368d513824a6467ce102a2688aa79cdad90725d16cae4', '2026-08-03 01:56:42', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:42'),
(21, 1, 'semestriel', 'SERVAIR ABIDJAN CI : Rapport d\'activités du 1er Semestre 2023', '2023-07-18', 'https://www.brvm.org/sites/default/files/20230718_-_rapport_dactivites_-_1er_semestre_2023_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20230718_-_rapport_dactivites_-_1er_semestre_2023_-_servair_abidjan_ci.pdf', 81949, '04a422c6982683cb03cd03fa2537c0bfaa4f2b39d78b6c188ade2dcfeaa9fb05', '2026-08-03 01:56:42', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:42'),
(22, 1, 'etats_financiers', 'SERVAIR ABIDJAN CI : Etats financiers exercice 2022 IFRS', '2023-06-02', 'https://www.brvm.org/sites/default/files/20230602_-_etats_financiers_exercice_2022_ifrs_-_servair_abidjan_ci_0.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20230602_-_etats_financiers_exercice_2022_ifrs_-_servair_abidjan_ci_0.pdf', 254862, 'da82bc0f5252a1585138329d82dd00f92cfd3abfe604ba8235ca36764c4e5227', '2026-08-03 01:56:43', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:43'),
(23, 1, 'etats_financiers', 'SERVAIR ABIDJAN CI : Etats financiers exercice 2022 SYSCOHADA', '2023-06-02', 'https://www.brvm.org/sites/default/files/20230602_-_etats_financiers_exercice_2022_syscohada_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20230602_-_etats_financiers_exercice_2022_syscohada_-_servair_abidjan_ci.pdf', 227841, 'a45c3728cbcdb2af96dfcf5818f745aadf86cc8346997501c9e3cf25a4ad2ccc', '2026-08-03 01:56:42', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:42'),
(24, 1, 'etats_financiers', 'SERVAIR ABIDJAN CI : Etats financiers Exercice 2022', '2023-04-28', 'https://www.brvm.org/sites/default/files/20230428_-_etats_financiers_exercice_2022_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20230428_-_etats_financiers_exercice_2022_-_servair_abidjan_ci.pdf', 145552, '9b2fb622409e8790a6c5e8698025bc5e99dc17b3eca1a0fac443f376d7a9ccd9', '2026-08-03 01:56:43', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:43'),
(25, 1, 'trimestriel', 'SERVAIR ABIDJAN CI : Rapport d\'activité du 1er trimestre 2023', '2023-04-20', 'https://www.brvm.org/sites/default/files/20230420_-_rapport_dactivite_-_1er_trimestre_2023_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20230420_-_rapport_dactivite_-_1er_trimestre_2023_-_servair_abidjan_ci.pdf', 252258, '1d3305041eb1161466526e783259523742b8080c83045508c68c763857d3d334', '2026-08-03 01:56:43', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:43'),
(26, 1, 'etats_financiers', 'SERVAIR ABIDJAN CI : Etats Financiers exercice 2021', '2022-06-17', 'https://www.brvm.org/sites/default/files/20220617_-_etats_financiers_exercice_2021_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20220617_-_etats_financiers_exercice_2021_-_servair_abidjan_ci.pdf', 483995, '27e0868f54c0909bdd1a732c6c93d350aaf29f6919b08d7a6b717be00024d3df', '2026-08-03 01:56:44', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:44'),
(27, 1, 'semestriel', 'SERVAIR ABIDJAN CI : Rapport d\'activité du 1er semestre 2021', '2021-10-28', 'https://www.brvm.org/sites/default/files/20211028_-_rapport_dactivite_du_1er_semestre_2021_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20211028_-_rapport_dactivite_du_1er_semestre_2021_-_servair_abidjan_ci.pdf', 135320, '5ce633a3c2a591151426cc7f1e92e70211974a0f5aea321da60b2b43d4ebad1c', '2026-08-03 01:56:44', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:44'),
(28, 1, 'etats_financiers', 'SERVAIR ABIDJAN CI : Etats financiers annuels IFRS 2019', '2021-08-27', 'https://www.brvm.org/sites/default/files/20210827_-_etats_financiers_annuels_ifrs_2019_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20210827_-_etats_financiers_annuels_ifrs_2019_-_servair_abidjan_ci.pdf', 1523279, 'df8e656d1a6f54eb1181055ca00e4f2f27330c9d3e86d8948d83861e12a9e4c5', '2026-08-03 01:56:44', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:45'),
(29, 1, 'etats_financiers', 'SERVAIR ABIDJAN CI : Etats financiers annuels IFRS 2020', '2021-08-27', 'https://www.brvm.org/sites/default/files/20210827_-_etats_financiers_annuels_ifrs_2020_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20210827_-_etats_financiers_annuels_ifrs_2020_-_servair_abidjan_ci.pdf', 745538, '374e58fb6198dff4c67b46cc2c6476cac5e6a5dd3075d713de26960f987f7ef9', '2026-08-03 01:56:44', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:44'),
(30, 1, 'etats_financiers', 'SERVAIR ABIDJAN CÔTE D\'IVOIRE : Etats Financiers - Exercice 2020', '2021-04-26', 'https://www.brvm.org/sites/default/files/20210426_-_etats_financiers_2020_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20210426_-_etats_financiers_2020_-_servair_abidjan_ci.pdf', 570868, 'f820d56f148930d08f58e863f018a521645b7553568fedde836fb3b5f7d940b4', '2026-08-03 01:56:45', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:45'),
(31, 1, 'semestriel', 'SERVAIR ABIDJAN CI: Rapport d\'activités au 1er semestre 2020', '2020-12-30', 'https://www.brvm.org/sites/default/files/20201230_-_rapport_1er_semestre_2020_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20201230_-_rapport_1er_semestre_2020_-_servair_abidjan_ci.pdf', 123124, 'bb2671c7cf8d8c7d27b52d8008c7f1f58a3347f76ed66b9cf7c6abe86ee7447e', '2026-08-03 01:56:45', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:45'),
(32, 1, 'etats_financiers', 'SERVAIR ABIDJAN CI  : Etats Financiers de l\'Exercice 2019', '2020-10-07', 'https://www.brvm.org/sites/default/files/20201007_-_etats_financiers_provisoires_2019_-_servair_abj_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20201007_-_etats_financiers_provisoires_2019_-_servair_abj_ci.pdf', 556386, '6f769d12eb067bada90f4caccb4941dba96e63eaca7ccf3bcf7cc279238d5448', '2026-08-03 01:56:45', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:45'),
(33, 1, 'semestriel', 'SERVAIR ABIDJAN CI : Rapport d\'activités du 1er semestre 2019', '2019-10-18', 'https://www.brvm.org/sites/default/files/20191018_-_rapport_dactivite_du_1er_semestre_2019_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20191018_-_rapport_dactivite_du_1er_semestre_2019_-_servair_abidjan_ci.pdf', 109812, 'e24730028af7c79565ee4ee1a99ff7f2dad7deeed1258f550fe8ff698bbc52f0', '2026-08-03 01:56:46', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:46'),
(34, 1, 'annuel', 'SERVAIR ABIDJAN : Rapport Annuel 2018', NULL, 'https://www.brvm.org/sites/default/files/rapport_annuel_2018_servair_abidjan.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/rapport_annuel_2018_servair_abidjan.pdf', 2842044, 'baea54692b2076cce3a1fb94ce5a13979dbccb5f846127b3eedc0cc376b9b528', '2026-08-03 01:57:06', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:57:06'),
(35, 1, 'etats_financiers', 'SERVAIR ABIDJAN CÔTE D\'IVOIRE : Etats financiers Exercice 2018', '2019-03-29', 'https://www.brvm.org/sites/default/files/20190329_-_etats_financiers_2018_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20190329_-_etats_financiers_2018_-_servair_abidjan_ci.pdf', 573584, '4a5695ee48c0a2e0725772f778cad66dbadd9f3a1ed79d71a8489d6c648688cd', '2026-08-03 01:56:46', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:46'),
(36, 1, 'semestriel', 'SERVAIR ABIDJAN CI : 	Rapport d’activités du 1er  semestre 2018', '2018-10-30', 'https://www.brvm.org/sites/default/files/20181030_-_rapport_dactivites_du_1er_semestriel_2018_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20181030_-_rapport_dactivites_du_1er_semestriel_2018_-_servair_abidjan_ci.pdf', 88223, 'bd2ae1ca23ef2d74e1f730521b1580df7565ab6fd8011546aa22dbd8da010b9d', '2026-08-03 01:56:46', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:46'),
(37, 1, 'etats_financiers', 'SERVAIR  : Etats Financiers non Certifiés Exercice 2017', NULL, 'https://www.brvm.org/sites/default/files/etats_financiers_servair_non_certifies_exercice_2017.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/etats_financiers_servair_non_certifies_exercice_2017.pdf', 222470, '89965e801a9b196cece8d688ece692e65d4be97da6d363a6edc458be0b2173ab', '2026-08-03 01:57:06', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:57:06'),
(38, 1, 'semestriel', 'Rapport d\'activités et attestation des CAC - 1er semestre 2017 – SERVAIR ABIDJAN CI', NULL, 'https://www.brvm.org/sites/default/files/20170925-rapport_dactivites_et_attestation_des_cac_-_1er_semestre_2017_-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20170925-rapport_dactivites_et_attestation_des_cac_-_1er_semestre_2017_-_servair_abidjan_ci.pdf', 941564, '0b3cb16b2aa5ad4400140cbba16b2cfcd93f813485fe5ed6dde227e44083be3f', '2026-08-03 01:57:05', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:57:05'),
(39, 1, 'etats_financiers', 'Etats financiers 2016 et projet de repartition de resultat - SERVAIR ABIDJAN CI', NULL, 'https://www.brvm.org/sites/default/files/20170511-etats_financiers_exercice_2016_et_projet_de_repartition_de_resultat-_servair_abidjan_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20170511-etats_financiers_exercice_2016_et_projet_de_repartition_de_resultat-_servair_abidjan_ci.pdf', 359660, '17c0190d8b84d51913dcbee52e621e220d2a6248be3c51cc91c9ff274380d2a0', '2026-08-03 01:57:05', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:57:05'),
(40, 1, 'trimestriel', 'Rapport d\'activités du 1er trimestre 2017- SERVAIR CI', NULL, 'https://www.brvm.org/sites/default/files/20170510-_rapport_dactivites_du_1er_trimestre_2017-_servair_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20170510-_rapport_dactivites_du_1er_trimestre_2017-_servair_ci.pdf', 83973, 'bd6da648e4f27009a9a2c0ae33be4b761acfe1937f4c54b13213c6760dbfcc84', '2026-08-03 01:57:05', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:57:05'),
(41, 1, 'autre', 'Rapport de gestion Exercice 2015 SERVAIR ABIDJAN CI', '2016-07-14', 'https://www.brvm.org/sites/default/files/20160714_-_rg_-_servair_abidjan_ci_-_exercice_2015.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20160714_-_rg_-_servair_abidjan_ci_-_exercice_2015.pdf', 21658594, '024a8d7e6b0203c1c53eb04bb0f05b1f2973324b0a0dba4113df7db12864905d', '2026-08-03 01:56:47', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:47'),
(42, 1, 'etats_financiers', 'Etats financiers provisoires - SERVAIR ABIDJAN CI - Exercice 2015', '2016-04-29', 'https://www.brvm.org/sites/default/files/20160429_-_efp_-_servair_abidjan_ci_-_exercice_2015.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20160429_-_efp_-_servair_abidjan_ci_-_exercice_2015.pdf', 787579, '861dbd84e894bcf38c0f467172aa8ece70f323403746c6d867faae778bee3ca8', '2026-08-03 01:56:47', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:47'),
(43, 1, 'annuel', 'Rapport annuel Exercice 2014 SERVAIR ABIDJAN CI', '2016-03-15', 'https://www.brvm.org/sites/default/files/20160315_-_ra_-_servair_abidjan_ci_-_exercice_2014.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20160315_-_ra_-_servair_abidjan_ci_-_exercice_2014.pdf', 4896730, '60466bc417fe00f6ed165c2e3533cd42eaba67bf817daf166bb229747a6d7996', '2026-08-03 01:56:48', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:48'),
(44, 1, 'autre', 'Rapport de gestion Exercice 2013 SERVAIR ABIDJAN CI', '2016-02-25', 'https://www.brvm.org/sites/default/files/20160225_-_rg_-_servair_abidjan_ci_-_exercice_2013.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20160225_-_rg_-_servair_abidjan_ci_-_exercice_2013.pdf', 14069305, '34518abd919f5a8ccb76525cdba202be8788ebe1dc6cf1e4b6d51768d2ceef90', '2026-08-03 01:56:49', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:49'),
(45, 1, 'etats_financiers', 'Etats financiers provisoires - SERVAIR ABIDJAN CI - Exercice 2014', '2015-06-11', 'https://www.brvm.org/sites/default/files/20150611_-_efp_-_servair_abidjan_ci_-_exercice_2014.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20150611_-_efp_-_servair_abidjan_ci_-_exercice_2014.pdf', 794538, 'f44d9fc24a8274bcf59a7ccd3c0a43d9dc52ecac5d930ca3a3620075a8efbb5a', '2026-08-03 01:56:49', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:49'),
(46, 1, 'semestriel', 'Rapport semestriel - SERVAIR ABIDJAN CI - Exercice 2014', '2014-10-31', 'https://www.brvm.org/sites/default/files/20141031_-_rs_-_servair_abidjan_ci_-_exercice_2014.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20141031_-_rs_-_servair_abidjan_ci_-_exercice_2014.pdf', 441691, 'f10b5e694ce0a883c2b20b80484baa5b2e43527da4fdecb3a2a4334a9481c623', '2026-08-03 01:56:49', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:49'),
(47, 1, 'annuel', 'Rapport annuel Exercice 2013 SERVAIR ABIDJAN CI', '2014-06-04', 'https://www.brvm.org/sites/default/files/20140604_-_ra_-_servair_abidjan_ci_-_exercice_2013.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20140604_-_ra_-_servair_abidjan_ci_-_exercice_2013.pdf', 3435538, 'd43778bc8ff507a84ab24e1132b6a822b05d380746e4dcef8c33e6aeaecc8523', '2026-08-03 01:56:50', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:50'),
(48, 1, 'etats_financiers', 'Etats financiers provisoires - SERVAIR ABIDJAN CI - Exercice 2013', '2014-06-02', 'https://www.brvm.org/sites/default/files/20140602_-_efp_-_servair_abidjan_ci_-_exercice_2013.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20140602_-_efp_-_servair_abidjan_ci_-_exercice_2013.pdf', 787579, '87fceba4cc4178c1a4728c8409d2c1fe5e14a5b3eb2164c4a085072bd1313d7a', '2026-08-03 01:56:50', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:50'),
(49, 1, 'etats_financiers', 'Etats financiers normalisés - SERVAIR ABIDJAN CI - EXERCICE 2013', '2014-05-30', 'https://www.brvm.org/sites/default/files/20140530_-_efn_-_servair_abidjan_ci_-_exercice_2013.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20140530_-_efn_-_servair_abidjan_ci_-_exercice_2013.pdf', 1387461, '958374e6e1d38faf30237483a7a1894ba1fe1fcd4312ac794dc5e0d16ff1fef2', '2026-08-03 01:56:50', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:50'),
(50, 1, 'autre', 'Rapport de gestion Exercice 2012 SERVAIR ABIDJAN CI', '2013-12-10', 'https://www.brvm.org/sites/default/files/20131210_-_rg_-_servair_abidjan_ci_-_exercice_2012.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20131210_-_rg_-_servair_abidjan_ci_-_exercice_2012.pdf', 1954013, '3118437d09053f286608b01d6bb8b062df9f9bc9d64a0f6c1a09e19523314304', '2026-08-03 01:56:51', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:51'),
(51, 1, 'semestriel', 'Rapport semestriel - SERVAIR ABIDJAN CI - Exercice 2013', '2013-10-30', 'https://www.brvm.org/sites/default/files/20131030_-_rs_-_servair_abidjan_ci_-_exercice_2013.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20131030_-_rs_-_servair_abidjan_ci_-_exercice_2013.pdf', 74094, '3aaa68b55dc5569da46a59b89ff7dcc8b8ea7477575c57ac3e3648f78a668430', '2026-08-03 01:56:51', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:51'),
(52, 1, 'etats_financiers', 'Etats financiers approuvés - SERVAIR ABIDJAN CI - Exercice 2012', '2013-07-23', 'https://www.brvm.org/sites/default/files/20130723_-_efa_-_servair_abidjan_ci_-_exercice_2012.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20130723_-_efa_-_servair_abidjan_ci_-_exercice_2012.pdf', 327520, '567b1f69aaa37fe13bc29dd8b6bda9fc50911fd05f559df27b86f63c35b597fd', '2026-08-03 01:56:52', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:52'),
(53, 1, 'autre', 'Commentaires sur activités annuelles - SERVAIR ABIDJAN CI - Exercice 2012', '2013-07-23', 'https://www.brvm.org/sites/default/files/20130723_-_caa_-_servair_abidjan_ci_-_exercice_2012.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20130723_-_caa_-_servair_abidjan_ci_-_exercice_2012.pdf', 228609, 'ad3b9f53bb08c94d502ed813a75fa2bb2ce80b1b11d2acb3055478a0d0aa7ba8', '2026-08-03 01:56:52', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:52'),
(54, 1, 'etats_financiers', 'Etats financiers provisoires - SERVAIR ABIDJAN CI - Exercice 2012', '2013-05-08', 'https://www.brvm.org/sites/default/files/20130508_-_efp_-_servair_abidjan_ci_-_exercice_2012.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20130508_-_efp_-_servair_abidjan_ci_-_exercice_2012.pdf', 127296, 'a892cd46329c8724391d4fd6628502559f3ce55d0093dc254015ba28244be53a', '2026-08-03 01:56:53', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:53'),
(55, 1, 'trimestriel', 'Rapport premier trimestre - SERVAIR ABIDJAN CI - Exercice', '2013-04-25', 'https://www.brvm.org/sites/default/files/20130425_-_rt1_-_servair_abidjan_ci_-_exercice_2013.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20130425_-_rt1_-_servair_abidjan_ci_-_exercice_2013.pdf', 81066, 'bfa82a731eac2e8bdce81982a72e91173321920bc8c1227ffe10446c1475717e', '2026-08-03 01:56:53', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:53'),
(56, 1, 'semestriel', 'Rapport semestriel - SERVAIR ABIDJAN CI - Exercice 2012', '2012-10-01', 'https://www.brvm.org/sites/default/files/20121001_-_rs_-_servair_abidjan_ci_-_exercice_2012.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20121001_-_rs_-_servair_abidjan_ci_-_exercice_2012.pdf', 250835, '9c24e2a65237984eaeb89a860d7e81b14649aec80817ad66af6bf9a302998649', '2026-08-03 01:56:53', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:53'),
(57, 1, 'annuel', 'Rapport annuel Exercice 2011 SERVAIR ABIDJAN CI', '2012-08-09', 'https://www.brvm.org/sites/default/files/20120809_-_ra_-_servair_abidjan_ci_-_exercice_2011.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20120809_-_ra_-_servair_abidjan_ci_-_exercice_2011.pdf', 8499727, '381d989269e8e623acada8ded6c5d646aa7afffc62e2edd7638cf0ec0b1e6518', '2026-08-03 01:56:54', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:54'),
(58, 1, 'etats_financiers', 'Etats financiers approuvés - SERVAIR ABIDJAN CI - Exercice 2011', '2012-07-19', 'https://www.brvm.org/sites/default/files/20120719_-_efa_-_servair_abidjan_ci_-_exercice_2011.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20120719_-_efa_-_servair_abidjan_ci_-_exercice_2011.pdf', 339630, '3cdb3b89c5693847db001116e515b4180cc9a306a09414a675c88ec346576542', '2026-08-03 01:56:54', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:54'),
(59, 1, 'etats_financiers', 'Etats financiers provisoires - SERVAIR ABIDJAN CI - Exercice 2011', '2012-05-15', 'https://www.brvm.org/sites/default/files/20120515_-_efp_-_servair_abidjan_ci_-_exercice_2011.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20120515_-_efp_-_servair_abidjan_ci_-_exercice_2011.pdf', 334782, '1a4e2bbd2b491f7ee16ce10cdfd9dea0b70c46ce24ed16a31fb635055ad6e6ad', '2026-08-03 01:56:54', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:54'),
(60, 1, 'trimestriel', 'Rapport troisième trimestre - SERVAIR ABIDJAN CI - Exercice 2011', '2011-12-13', 'https://www.brvm.org/sites/default/files/20111213_-_rt3_-_servair_abidjan_ci_-_exercice_2011.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20111213_-_rt3_-_servair_abidjan_ci_-_exercice_2011.pdf', 49400, 'b77e3c6a57c1ce7f524c76ff76d775b058d5a7e6810b27b1138da9d9a389efab', '2026-08-03 01:56:55', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:55'),
(61, 1, 'semestriel', 'Rapport semestriel - SERVAIR ABIDJAN CI - Exercice 2011', '2011-10-28', 'https://www.brvm.org/sites/default/files/20111028_-_rs_-_servair_abidjan_ci_-_exercice_2011.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20111028_-_rs_-_servair_abidjan_ci_-_exercice_2011.pdf', 50771, 'e7fd8e90be483f58d761a2db5c31785cb5522523e1da1cfabdaad8c0d646badb', '2026-08-03 01:56:55', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:55'),
(62, 1, 'annuel', 'Rapport annuel Exercice 2010 SERVAIR ABIDJAN CI', '2011-10-14', 'https://www.brvm.org/sites/default/files/20111014_-_ra_-_servair_abidjan_ci_-_exercice_2010.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20111014_-_ra_-_servair_abidjan_ci_-_exercice_2010.pdf', 7540672, '5ee2c1125b5f092224be9ec1dc2c471595da2c1b86faa180f0c0d326351a6c93', '2026-08-03 01:56:55', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:55'),
(63, 1, 'etats_financiers', 'Etats financiers approuvés - SERVAIR ABIDJAN CI - Exercice 2010', '2011-07-18', 'https://www.brvm.org/sites/default/files/20110718_-_efa_-_servair_abidjan_ci_-_exercice_2010.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20110718_-_efa_-_servair_abidjan_ci_-_exercice_2010.pdf', 671220, 'd2753f63098cb27e8246f07cfeb85bb9796615dfcddb76b04760fd54e11a3ecc', '2026-08-03 01:56:56', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:56'),
(64, 1, 'semestriel', 'Rapport semestriel - SERVAIR ABIDJAN CI - Exercice 2010', '2010-11-11', 'https://www.brvm.org/sites/default/files/20101111_-_rs_-_servair_abidjan_ci_-_exercice_2010.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20101111_-_rs_-_servair_abidjan_ci_-_exercice_2010.pdf', 512834, '6e1f0a9067c73c11d59ee5be588b82bc339c1ae2d02f215da68b27047f5c88b8', '2026-08-03 01:56:56', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:56'),
(65, 1, 'annuel', 'Rapport annuel Exercice 2009 SERVAIR ABIDJAN CI', '2010-09-07', 'https://www.brvm.org/sites/default/files/20100907_-_ra_-_servair_abidjan_ci_-_exercice_2009.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20100907_-_ra_-_servair_abidjan_ci_-_exercice_2009.pdf', 11328295, '2bb815d3619e62f78aadb170f091c296b1702141ca688f2808aa40be5a4a8973', '2026-08-03 01:56:57', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:57'),
(66, 1, 'etats_financiers', 'Etats financiers provisoires - SERVAIR ABIDJAN CI - Exercice 2009', '2010-06-09', 'https://www.brvm.org/sites/default/files/20100609_-_efp_-_servair_abidjan_ci_-_exercice_2009.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20100609_-_efp_-_servair_abidjan_ci_-_exercice_2009.pdf', 1092889, '08fad556bdde919bade11fa43aa8ddd6f949319fa2cc09a3292b8d75d8f26301', '2026-08-03 01:56:57', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:57'),
(67, 1, 'semestriel', 'Rapport semestriel - SERVAIR ABIDJAN CI - Exercice 2009', '2009-11-12', 'https://www.brvm.org/sites/default/files/20091112_-_rs_-_servair_abidjan_ci_-_exercice_2009.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20091112_-_rs_-_servair_abidjan_ci_-_exercice_2009.pdf', 8652, '24a8829dbc11c437047bfc335c6a10a2968b394e0be245d41ce462ff572372a9', '2026-08-03 01:56:57', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:57'),
(68, 1, 'annuel', 'Rapport annuel Exercice 2006 ABIDJAN CATERING CI', '2009-06-22', 'https://www.brvm.org/sites/default/files/20090622_-_ra_-_abidjan_catering_ci_-_exercice_2006.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20090622_-_ra_-_abidjan_catering_ci_-_exercice_2006.pdf', 7665479, 'b999593078f0b6ce9396dad779767bb40c9d4d8363fade97966bf427caa51400', '2026-08-03 01:57:00', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:57:00'),
(69, 1, 'annuel', 'Rapport annuel Exercice 2008 ABIDJAN CATERING CI', '2009-09-01', 'https://www.brvm.org/sites/default/files/20090901_-_ra_-_abidjan_catering_ci_-_exercice_2008.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20090901_-_ra_-_abidjan_catering_ci_-_exercice_2008.pdf', 10708412, '964d8620eb6b1affbab3d34473ff811af2c1d4ba72aae741c067de194364eeb0', '2026-08-03 01:56:58', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:58'),
(70, 1, 'etats_financiers', 'Etats financiers provisoires - SERVAIR ABIDJAN CI - Exercice 2003', '2009-08-10', 'https://www.brvm.org/sites/default/files/20090810_-_efp_-_servair_abidjan_ci_-_exercice_2003.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20090810_-_efp_-_servair_abidjan_ci_-_exercice_2003.pdf', 31832, 'e20852c0dd93ace3f4b074f2f1405ac09f50eb7d0cb17cb7a235f95800a9563f', '2026-08-03 01:56:59', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:59'),
(71, 1, 'etats_financiers', 'Etats financiers provisoires - SERVAIR ABIDJAN CI - Exercice 2004 (ABIDJAN CATERING)', '2009-08-10', 'https://www.brvm.org/sites/default/files/20090810_-_efp_-_servair_abidjan_ci_-_exercice_2004_abidjan_catering.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20090810_-_efp_-_servair_abidjan_ci_-_exercice_2004_abidjan_catering.pdf', 25468, '1b6999d75b7cca5f8792b0b222b84ef3523c25bac59cb7b8577084bda6a11643', '2026-08-03 01:56:59', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:59'),
(72, 1, 'etats_financiers', 'Etats financiers provisoires - SERVAIR ABIDJAN CI - Exercice 2005 (ABIDJAN CATERING)', '2009-08-10', 'https://www.brvm.org/sites/default/files/20090810_-_efp_-_servair_abidjan_ci_-_exercice_2005_abidjan_catering.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20090810_-_efp_-_servair_abidjan_ci_-_exercice_2005_abidjan_catering.pdf', 22797, 'c279beee49bcea48cdad9f50bcb9c106007121fd7cb83d513683bc73098157c9', '2026-08-03 01:56:59', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:59'),
(73, 1, 'etats_financiers', 'Etats financiers provisoires - SERVAIR ABIDJAN CI - Exercice 2006 (ABIDJAN CATERING)', '2009-08-10', 'https://www.brvm.org/sites/default/files/20090810_-_efp_-_servair_abidjan_ci_-_exercice_2006_abidjan_catering.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20090810_-_efp_-_servair_abidjan_ci_-_exercice_2006_abidjan_catering.pdf', 48623, '0390f43dac77deca03a51b69c494e38bb27e177bf49773249bff45495bad06dd', '2026-08-03 01:56:58', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:59'),
(74, 1, 'annuel', 'Rapport annuel Exercice 2005 ABIDJAN CATERING CI', '2009-08-24', 'https://www.brvm.org/sites/default/files/20090824_-_ra_-_abidjan_catering_ci_-_exercice_2005.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20090824_-_ra_-_abidjan_catering_ci_-_exercice_2005.pdf', 5136914, '78263fe58c7137db636113beb296a6a045612cec7e0413d8afd9a3494b6d57fc', '2026-08-03 01:56:58', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:56:58'),
(75, 1, 'annuel', 'Rapport annuel Exercice 2007 ABIDJAN CATERING CI', '2009-06-11', 'https://www.brvm.org/sites/default/files/20090611_-_ra_-_abidjan_catering_ci_-_exercice_2007.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20090611_-_ra_-_abidjan_catering_ci_-_exercice_2007.pdf', 6388458, '7943b4cca2f1b940a1a015577e3ffed013bb09a80e0c2c2c3fd8d2f92a013e95', '2026-08-03 01:57:00', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:57:00'),
(76, 1, 'etats_financiers', 'Etats financiers normalisés - SERVAIR ABIDJAN CI - EXERCICE 2002', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_efn_-_servair_abidjan_ci_-_exercice_2002.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20090530_-_efn_-_servair_abidjan_ci_-_exercice_2002.pdf', 3243555, '6f4d5abd4b26d68dce512103cebb9f7ba36c38e100be5d5874bc12ebc14ad1c4', '2026-08-03 01:57:03', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:57:03');
INSERT INTO `company_reports` (`id`, `company_id`, `report_type`, `title`, `publish_date`, `file_url`, `local_path`, `file_size`, `file_hash`, `downloaded_at`, `text_extracted`, `extraction_method`, `extraction_error`, `created_at`, `updated_at`) VALUES
(77, 1, 'etats_financiers', 'Etats financiers provisoires - SERVAIR ABIDJAN CI - EXERCICE 2003', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_efp_-_servair_abidjan_ci_-_exercice_2003.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20090530_-_efp_-_servair_abidjan_ci_-_exercice_2003.pdf', 3493237, '759b17d68c35ccdb4895b90fd5573d59d277d73c5990b3c63fd637bb39dbd265', '2026-08-03 01:57:03', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:57:03'),
(78, 1, 'annuel', 'Rapport annuel Exercice 1998 SERVAIR ABIDJAN CI', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_servair_abidjan_ci_-_exercice_1998.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20090530_-_ra_-_servair_abidjan_ci_-_exercice_1998.pdf', 2223449, 'db9b9d56ffdb79148de1e34119f100cae6eee7f5e50106f60da0e980b2d590c7', '2026-08-03 01:57:02', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:57:02'),
(79, 1, 'annuel', 'Rapport annuel Exercice 1999 SERVAIR ABIDJAN CI', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_servair_abidjan_ci_-_exercice_1999.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20090530_-_ra_-_servair_abidjan_ci_-_exercice_1999.pdf', 1410031, '80f24cead1dba7f3e8fcef9eb841d9f419e9683501f07bc75a47fb3debcea3c9', '2026-08-03 01:57:01', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:57:01'),
(80, 1, 'annuel', 'Rapport annuel Exercice 2000 SERVAIR ABIDJAN CI', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_servair_abidjan_ci_-_exercice_2000.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20090530_-_ra_-_servair_abidjan_ci_-_exercice_2000.pdf', 1254958, '3a0e0fa13d8db38e81ea7e64e94790d598833078a1547d0b1fab41badc1a856a', '2026-08-03 01:57:01', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:57:01'),
(81, 1, 'annuel', 'Rapport annuel Exercice 2000 SERVAIR ABIDJAN CI', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_servair_abidjan_ci_-_exercice_2001.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20090530_-_ra_-_servair_abidjan_ci_-_exercice_2001.pdf', 770897, '20e5ec876da818116bed2e222686973e18b31841f41027d5a7143cfc60c46b7e', '2026-08-03 01:57:01', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:57:01'),
(82, 1, 'annuel', 'Rapport annuel Exercice 2004 SERVAIR ABIDJAN CI', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_servair_abidjan_ci_-_exercice_2004.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20090530_-_ra_-_servair_abidjan_ci_-_exercice_2004.pdf', 1194602, 'd5f480cb83555e1c51deb153b2d85b066c9c26aaaebc2da8bd58fd9f2cda2aae', '2026-08-03 01:57:00', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:57:00'),
(83, 1, 'semestriel', 'Rapport semestriel - SERVAIR ABIDJAN CI - Exercice 2008 (ABIDJAN CATERING)', '2008-10-31', 'https://www.brvm.org/sites/default/files/20081031_-_rs_-_servair_abidjan_ci_-_exercice_2008_abidjan_catering.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20081031_-_rs_-_servair_abidjan_ci_-_exercice_2008_abidjan_catering.pdf', 39172, '91d29e9feeeb6621472f5bf0b653ef5e3d8436aaa336159bb7d6dedd965bad6f', '2026-08-03 01:57:03', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:57:03'),
(84, 1, 'semestriel', 'Rapport semestriel - SERVAIR ABIDJAN CI - Exercice 2007 (ABIDJAN CATERING)', '2008-04-18', 'https://www.brvm.org/sites/default/files/20080418_-_rs_-_servair_abidjan_ci_-_exercice_2007_abidjan_catering.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20080418_-_rs_-_servair_abidjan_ci_-_exercice_2007_abidjan_catering.pdf', 8034, '70d83f9452ca3040e490e4ecfcdf38a33de53d68ed8e3ae2d2a7bf87ebb193a8', '2026-08-03 01:57:04', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:57:04'),
(85, 1, 'semestriel', 'Rapport semestriel - SERVAIR ABIDJAN CI - Exercice 2006 (ABIDJAN CATERING)', '2006-08-22', 'https://www.brvm.org/sites/default/files/20060822_-_rs_-_servair_abidjan_ci_-_exercice_2006_abidjan_catering.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20060822_-_rs_-_servair_abidjan_ci_-_exercice_2006_abidjan_catering.pdf', 67881, '3b56d91598abd26a5666b555b9e7ce7ab2b0db4ac82a285a6476f0053743f743', '2026-08-03 01:57:04', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:57:04'),
(86, 1, 'semestriel', 'Rapport semestriel - SERVAIR ABIDJAN CI - Exercice 2005 (ABIDJAN CATERING)', '2005-10-06', 'https://www.brvm.org/sites/default/files/20051006_-_rs_-_servair_abidjan_ci_-_exercice_2005_abidjan_catering.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/ABJC/20051006_-_rs_-_servair_abidjan_ci_-_exercice_2005_abidjan_catering.pdf', 57068, 'c7670e29aeaa9ec2d4cd0d8a5be1da0ce1c94cd18189173dfd0732da950f13ad', '2026-08-03 01:57:04', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:56:35', '2026-08-03 02:57:04'),
(87, 3, 'trimestriel', 'BICI CI : Rapport d\'activités - 1er trimestre 2026', '2026-04-30', 'https://www.brvm.org/sites/default/files/20260430_-_rapport_dactivites_-_1er_trimestre_2026_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20260430_-_rapport_dactivites_-_1er_trimestre_2026_-_bici_ci.pdf', 274579, 'cf3205f33b08ecb65e7520d6b918f5774daabec2d4991ce4b599ddbba4d772ce', '2026-08-03 01:57:13', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:13'),
(88, 3, 'etats_financiers', 'BICI CI  : Etats financiers - Exercice 2025', '2026-04-17', 'https://www.brvm.org/sites/default/files/20260417_-_etats_financiers_-_exercice_2025_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20260417_-_etats_financiers_-_exercice_2025_-_bici_ci.pdf', 3839013, '24a07bf1b1476bea597cf172533102cec574fdec99a8ffa27723205cb8e4a771', '2026-08-03 01:57:14', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:14'),
(89, 3, 'autre', 'BICI CI : Rapport d\'activités annuel - Exercice 2025', '2026-04-17', 'https://www.brvm.org/sites/default/files/20260417_-_rapport_dactivites_annuel_-_exercice_2025_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20260417_-_rapport_dactivites_annuel_-_exercice_2025_-_bici_ci.pdf', 3573385, '876783f3272a5ef54322fe1ac0e1ca85ae4d5c524e02e9334bd5dfd3be968a69', '2026-08-03 01:57:13', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:13'),
(90, 3, 'trimestriel', 'BICI CÔTE D\'IVOIRE : Rapport d\'activités - 3ème trimestre 2025', '2025-10-29', 'https://www.brvm.org/sites/default/files/20251029_-_rapport_dactivites_-_3eme_trimestre_2025_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20251029_-_rapport_dactivites_-_3eme_trimestre_2025_-_bici_ci.pdf', 276362, 'b11838cada6c53e17d8d97f17955f8dbdcdd64477927f85b51f3da07b9a6a28e', '2026-08-03 01:57:14', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:14'),
(91, 3, 'semestriel', 'BICI CÔTE D\'IVOIRE : Rapport d\'activités - 1er semestre 2025', '2025-10-21', 'https://www.brvm.org/sites/default/files/20251021_-_rapport_dactivites_-_1er_semestre_2025_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20251021_-_rapport_dactivites_-_1er_semestre_2025_-_bici_ci.pdf', 214705, 'f386147a5f0cf437cd897c2d1aa7804e1abfab52fbffbf1e9ecfce5aeae768fa', '2026-08-03 01:57:14', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:14'),
(92, 3, 'trimestriel', 'BICI CÔTE D\'IVOIRE : Rapport d\'activités - 1er trimestre 2025', '2025-04-30', 'https://www.brvm.org/sites/default/files/20250430_-_rapport_dactivites_-_1er_trimestre_2025_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20250430_-_rapport_dactivites_-_1er_trimestre_2025_-_bici_ci.pdf', 264935, '6f70cb8446b3314870fe578ba951fba6273ca27655f3490fe3a1bbebab842206', '2026-08-03 01:57:15', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:15'),
(93, 3, 'etats_financiers', 'BICI CÔTE D\'IVOIRE : Etats financiers - Exercice 2024', '2025-03-28', 'https://www.brvm.org/sites/default/files/20250328_-_etats_financiers_-_exercice_2024_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20250328_-_etats_financiers_-_exercice_2024_-_bici_ci.pdf', 1372667, '51f397023617f9efa071454160ae5f15b62484435a5842df0054e3eb24b77fe2', '2026-08-03 01:57:15', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:15'),
(94, 3, 'trimestriel', 'BICI CI : Rapport d\'activités - 3ème trimestre 2024', '2024-11-28', 'https://www.brvm.org/sites/default/files/20241128_-_rapport_dactivites_-_3eme_trimestre_2024_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20241128_-_rapport_dactivites_-_3eme_trimestre_2024_-_bici_ci.pdf', 183544, '631ad7c7a82e4ba1e15c27d2b3d66d7ffc9805478328415db20deb6081c76b51', '2026-08-03 01:57:15', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:15'),
(95, 3, 'semestriel', 'BICI CI : Rapport d\'activités - 1er semestre 2024', '2024-10-31', 'https://www.brvm.org/sites/default/files/20241031_-_rapport_dactivites_-_1er_semestre_2024_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20241031_-_rapport_dactivites_-_1er_semestre_2024_-_bici_ci.pdf', 224306, 'e75ebd6e40b099d047976f488865cd34bab3aaac0cf98d863f6d3d75b48882a8', '2026-08-03 01:57:15', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:16'),
(96, 3, 'etats_financiers', 'BICI CI : Etats financiers - Exercice 2023', '2024-05-02', 'https://www.brvm.org/sites/default/files/20240502_-_etats_financiers_-_exercice_2023_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20240502_-_etats_financiers_-_exercice_2023_-_bici_ci.pdf', 458215, 'bff9b89dc7197aa352166eb31ec87a6909d5d3d44186d9379bbd4e45ee6acb66', '2026-08-03 01:57:16', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:16'),
(97, 3, 'trimestriel', 'BICICI : Rapport d\'activités - 1er trimestre 2024', '2024-04-30', 'https://www.brvm.org/sites/default/files/20240430_-_rapport_dactivites_-_1er_trimestre_2024_-_bicici.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20240430_-_rapport_dactivites_-_1er_trimestre_2024_-_bicici.pdf', 136133, '0fcff7944c90e385dc63c02d6631ef14863d29e67143616593f92c7ac006f218', '2026-08-03 01:57:16', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:16'),
(98, 3, 'trimestriel', 'BICI CI : Rapport d\'activités - 3ème trimestre 2023', '2024-01-10', 'https://www.brvm.org/sites/default/files/20240110_-_rapport_dactivites_-_3eme_trimestre_2023_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20240110_-_rapport_dactivites_-_3eme_trimestre_2023_-_bici_ci.pdf', 662587, 'e26c4c4c3b6df9db3ff1584f8010320d10c62d6d9789b48add1621d1322b0b93', '2026-08-03 01:57:16', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:16'),
(99, 3, 'semestriel', 'BICICI : Rapport d\'activités du 1er Semestre 2023', '2023-07-28', 'https://www.brvm.org/sites/default/files/20230728_-_rapport_dactivites_-_1er_semestre_2023_-_bicici.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20230728_-_rapport_dactivites_-_1er_semestre_2023_-_bicici.pdf', 905620, '40b06d55b7e273829c11669961f6a3e30cf3ad27b056fc5434e4a654a370db8a', '2026-08-03 01:57:17', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:17'),
(100, 3, 'etats_financiers', 'BICICI : Etats financiers Exercice 2022', NULL, 'https://www.brvm.org/sites/default/files/bilan_et_compte_de_resultat_bicici_31_12_2022_.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/bilan_et_compte_de_resultat_bicici_31_12_2022_.pdf', 468571, 'f60882d1e98cf452423036ad2edbceb69ade1502917b88cbf790cc21d3a17f45', '2026-08-03 01:57:43', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:43'),
(101, 3, 'semestriel', 'BICICI : Rapport d\'activité - 1er semestre 2022', '2022-10-18', 'https://www.brvm.org/sites/default/files/20221018_-_rapport_dactivite_-_3eme_trimestre_2022_-_bicici.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20221018_-_rapport_dactivite_-_3eme_trimestre_2022_-_bicici.pdf', 123093, '492df4551aa01c2e97415323331b50a3f4eed65e952d38e2e97b7a524b64fae0', '2026-08-03 01:57:17', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:17'),
(102, 3, 'trimestriel', 'BICICI : Rapport d\'activité - 3ème trimestre 2022', '2022-10-18', 'https://www.brvm.org/sites/default/files/20221018_-_rapport_dactivite_-_3eme_trimestre_2022_-_bicici_0.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20221018_-_rapport_dactivite_-_3eme_trimestre_2022_-_bicici_0.pdf', 123093, '492df4551aa01c2e97415323331b50a3f4eed65e952d38e2e97b7a524b64fae0', '2026-08-03 01:57:17', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:17'),
(103, 3, 'semestriel', 'BICICI: Rapport d\'activité - 1er semestre 2022', '2022-09-07', 'https://www.brvm.org/sites/default/files/20220907_-_rapport_dactivite_-_1er_semestre_2022_-_bicici.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20220907_-_rapport_dactivite_-_1er_semestre_2022_-_bicici.pdf', 848717, '0a72f1336efb2a6ae2aced38a4744381ed1b66b14637dcfbc06ef497b396afe0', '2026-08-03 01:57:17', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:18'),
(104, 3, 'trimestriel', 'BICICI : Rapport d\'activité du 2ème trimestre 2022', '2022-07-29', 'https://www.brvm.org/sites/default/files/20220729_-_rapport_dactivite_-_2eme_trimestre_2022_-_bicici.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20220729_-_rapport_dactivite_-_2eme_trimestre_2022_-_bicici.pdf', 568003, '96c24dcc1175d5b4edaf54e52a9bbef1aa007f90a7d38f438af4a4b0943f55f9', '2026-08-03 01:57:18', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:18'),
(105, 3, 'trimestriel', 'BICI CI : Rapport d\'activité du 1er trimestre 2022', '2022-04-26', 'https://www.brvm.org/sites/default/files/20220426_-_rapport_dactivite_-_1er_trimestre_2022_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20220426_-_rapport_dactivite_-_1er_trimestre_2022_-_bici_ci.pdf', 836492, '67d847bc2060d4ed7cd95a6237ee564105934a221ab4aa92142ab3820f4a144b', '2026-08-03 01:57:18', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:18'),
(106, 3, 'etats_financiers', 'BICICI : Etats financiers exercice 2021', '2022-04-06', 'https://www.brvm.org/sites/default/files/20220406_-_etats_financiers_exercice_2021_-_bicici.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20220406_-_etats_financiers_exercice_2021_-_bicici.pdf', 759937, '084bc1930c638d99fa9700cd1799c0ed32a77292fb629d002816e520e11f9536', '2026-08-03 01:57:18', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:18'),
(107, 3, 'trimestriel', 'BICI CI : Rapport d\'activités au 4e trimestre 2021', NULL, 'https://www.brvm.org/sites/default/files/rapport_dactivite_au_4eme_trimestre_2021_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/rapport_dactivite_au_4eme_trimestre_2021_-_bici_ci.pdf', 705660, '76550d5357602d82ba54673b9eaf04757097456cbac6b7f6f1dd243e55b9d263', '2026-08-03 01:57:43', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:43'),
(108, 3, 'trimestriel', 'BICI CÔTE D\'IVOIRE : Rapport d\'activité au 3ème trimestre 2021', '2021-12-01', 'https://www.brvm.org/sites/default/files/20211201_-_rapport_dactivite_au_3eme_trimestre_2021_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20211201_-_rapport_dactivite_au_3eme_trimestre_2021_-_bici_ci.pdf', 722275, '6d2c34f9adecdccc8c92a909b784c00667f4000c8a9f3b685128d23ac88f023a', '2026-08-03 01:57:19', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:19'),
(109, 3, 'etats_financiers', 'BICI CI : Etats Financiers - Exercice 2020', '2021-04-29', 'https://www.brvm.org/sites/default/files/20210429_-_etats_financiers_-_exercice_2020_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20210429_-_etats_financiers_-_exercice_2020_-_bici_ci.pdf', 425166, '13f88bf22e2b9182459f0492f0190c69bccb3d0be70a9691225d4de88de2c6ae', '2026-08-03 01:57:19', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:19'),
(110, 3, 'semestriel', 'BICICI : Rapport d\'activités au 1er semestre 2020', '2020-11-20', 'https://www.brvm.org/sites/default/files/20201120_-_rapport_dactivites_au_1er_semestre_2020_-_bicici.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20201120_-_rapport_dactivites_au_1er_semestre_2020_-_bicici.pdf', 428644, '9c2492256100a353090ec83bab7e1cf8709bb5f24a96a78b889bb993db3da565', '2026-08-03 01:57:19', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:19'),
(111, 3, 'etats_financiers', 'BICICI : Etats Financiers exercice 2019 (Annule et remplace le précédent)', '2020-11-12', 'https://www.brvm.org/sites/default/files/20201112_-_etats_financiers_exercice_2019_-_bicici_annule_et_remplace_le_precedent.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20201112_-_etats_financiers_exercice_2019_-_bicici_annule_et_remplace_le_precedent.pdf', 677267, '9f3de16a9334927b813b94804e209c2a68a09f4c8a1bb1827e63b07ff349cd33', '2026-08-03 01:57:19', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:19'),
(112, 3, 'etats_financiers', 'BICICI : Etats Financiers exercice 2019', '2020-11-06', 'https://www.brvm.org/sites/default/files/20201106_-etats_financiers_exercice_2019_-_bicici.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20201106_-etats_financiers_exercice_2019_-_bicici.pdf', 167078, '00e21938c93e2d77fcf845812d18c6057e66eca9fd46db7c7d537480358ebd9b', '2026-08-03 01:57:20', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:20'),
(113, 3, 'semestriel', 'BICI CI : Attestation des Commissaires Aux Comptes et Rapport d\'activités au 1er semestre 2019', '2019-11-08', 'https://www.brvm.org/sites/default/files/20191108_-_attestation_des_cac_et_rapport_dactivite_semestriel_du_1er_semestre_2019_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20191108_-_attestation_des_cac_et_rapport_dactivite_semestriel_du_1er_semestre_2019_-_bici_ci.pdf', 815958, '69dff501f8547cd8f333317e0acac6a982dd9493042750bbddf1776e7c30853d', '2026-08-03 01:57:20', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:20'),
(114, 3, 'semestriel', 'BICI CI : Rapport d\'activité du 1er semestre 2019', '2019-10-24', 'https://www.brvm.org/sites/default/files/20191024_-_rapport_dactivite_du_1er_semestre_2019_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20191024_-_rapport_dactivite_du_1er_semestre_2019_-_bici_ci.pdf', 2888012, '59df3ba3afb121ff8fd83236f598db25b0be67521ac12303050903ed1527b2aa', '2026-08-03 01:57:20', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:20'),
(115, 3, 'annuel', 'BICICI : Rapport Annuel 2018', NULL, 'https://www.brvm.org/sites/default/files/rapport_annuel_2018_bicici.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/rapport_annuel_2018_bicici.pdf', 51138617, 'b4b9c9d73f0901316c078733e08cb23de69c9f21a731767d30b1505654332f86', '2026-08-03 01:57:43', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:43'),
(116, 3, 'etats_financiers', 'BICI CI : Etats financiers Exercice 2018', '2019-06-03', 'https://www.brvm.org/sites/default/files/20190603_-_etats_financiers_exercice_2018_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20190603_-_etats_financiers_exercice_2018_-_bici_ci.pdf', 639741, '949bcd716e5d2bb6431dbc39add28f49f0f77a46854115eb9208333405b073c7', '2026-08-03 01:57:21', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:21'),
(117, 3, 'semestriel', 'BICI CI : Attestation des Commissaires aux comptes du rapport d\'activités du 1er semestre 2018', '2018-10-30', 'https://www.brvm.org/sites/default/files/20181030_-_attestation_des_commissaires_aux_comptes_du_rapport_dactivites_du_1er_semestre_2018_-_bici_ci-web.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20181030_-_attestation_des_commissaires_aux_comptes_du_rapport_dactivites_du_1er_semestre_2018_-_bici_ci-web.pdf', 349767, 'ad1e4bb9dc783b8240e463bc1f6539cd06c798162cd6b61069135e7bbbe42c8d', '2026-08-03 01:57:21', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:21'),
(118, 3, 'semestriel', 'BICICI : Rapport d’activités du 1er semestre 2018', '2018-10-30', 'https://www.brvm.org/sites/default/files/20181030_-_rapport_dactivites_du_1er_semestre_2018_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20181030_-_rapport_dactivites_du_1er_semestre_2018_-_bici_ci.pdf', 493893, '562b767e3d96939fd83099a87c9fcb0bd66adc78808ec496d56c6a1634a2db08', '2026-08-03 01:57:21', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:21'),
(119, 3, 'etats_financiers', 'BICICI : Etats financiers exercice 2017', '2018-05-16', 'https://www.brvm.org/sites/default/files/20180516_-_publication_resultat_bicici_31_12_2017_sent.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20180516_-_publication_resultat_bicici_31_12_2017_sent.pdf', 312817, 'a61e5d6e3689970687b3925e559cab300aae435f98113c9357cb2e57db8f3518', '2026-08-03 01:57:21', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:21'),
(120, 3, 'semestriel', 'Rapport d\'activité du 1er semestre 2017 - BICICI', NULL, 'https://www.brvm.org/sites/default/files/20171031-rapport_d27activite_du_1er_semestre_2017_bicici.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20171031-rapport_d27activite_du_1er_semestre_2017_bicici.pdf', 635802, '18ab1ce3aa2882f67587e6bab44da8c6de880bb9e7a67890f798505aaff53778', '2026-08-03 01:57:42', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:42'),
(121, 3, 'etats_financiers', 'Etats Financiers Exercice 2016 - BICI CI', '2017-05-08', 'https://www.brvm.org/sites/default/files/20170508_-_etats_financiers_exercice_2016-bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20170508_-_etats_financiers_exercice_2016-bici_ci.pdf', 54754, '0b3977692e2fe61942e73803615dfc75e81abee0c3b6a08f31156ce0dafee41f', '2026-08-03 01:57:22', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:22'),
(122, 3, 'semestriel', 'Rapport d\'activités du 1er semestre 2016 - BICI CI', '2016-12-09', 'https://www.brvm.org/sites/default/files/20161209_-_rapport_dactivites_du_1er_semestre_2016_-_bici_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20161209_-_rapport_dactivites_du_1er_semestre_2016_-_bici_ci.pdf', 1431767, 'b7229b5a2519c1645375be461f539d852f30a881060a620b35dd7eef18e57476', '2026-08-03 01:57:22', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:22'),
(123, 3, 'annuel', 'Rapport annuel Exercice 2015 BICI CI', '2016-07-28', 'https://www.brvm.org/sites/default/files/20160728_-_ra_-_bici_ci_-_exercice_2015.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20160728_-_ra_-_bici_ci_-_exercice_2015.pdf', 11699945, 'ae144c280de1781a21a4c8d355fa7cdc69c4305a9cf9ac1348790845295c0064', '2026-08-03 01:57:22', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:22'),
(124, 3, 'etats_financiers', 'Etats financiers provisoires - BICI CI - Exercice 2015', '2016-05-25', 'https://www.brvm.org/sites/default/files/20160525_-_efp_-_bici_ci_-_exercice_2015.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20160525_-_efp_-_bici_ci_-_exercice_2015.pdf', 5702025, 'dfeae700b211312d096366f557ec199d731e1f9cc599e83482b312838ae2e07f', '2026-08-03 01:57:23', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:23'),
(125, 3, 'annuel', 'Rapport annuel Exercice 2014 BICI CI', '2015-11-02', 'https://www.brvm.org/sites/default/files/20151102_-_ra_-_bici_ci_-_exercice_2014.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20151102_-_ra_-_bici_ci_-_exercice_2014.pdf', 4567048, '8ea341fe46a01d6f650a8e849b8dea8fe4ca399398bffa254e280b35f673f77d', '2026-08-03 01:57:24', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:24'),
(126, 3, 'semestriel', 'Rapport semestriel - BICI CI - Exercice 2015', '2015-10-20', 'https://www.brvm.org/sites/default/files/20151020_-_rs_-_bici_ci_-_exercice_2015.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20151020_-_rs_-_bici_ci_-_exercice_2015.pdf', 2016767, 'efe9ee1918d2f38d3ecd8cd89274d0ea78d1d55aa968f7e88e6636065080ac9c', '2026-08-03 01:57:24', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:24'),
(127, 3, 'annuel', 'Rapport annuel Exercice 2014 BICI CI', '2015-07-07', 'https://www.brvm.org/sites/default/files/20150707_-_ra_-_bici_ci_-_exercice_2014.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20150707_-_ra_-_bici_ci_-_exercice_2014.pdf', 14482065, '3452db2a6527eaf4df0af2f18e4f527b9109d2d7644ff00b78be3fd22c89bacd', '2026-08-03 01:57:24', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:24'),
(128, 3, 'etats_financiers', 'Etats financiers provisoires - BICI CI - Exercice 2014', '2015-05-26', 'https://www.brvm.org/sites/default/files/20150526_-_efp_-_bici_ci_-_exercice_2014.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20150526_-_efp_-_bici_ci_-_exercice_2014.pdf', 41971, '8c8afcb124ec018dd1f626f384ef007c490752caaae659078bf18c5db96666ea', '2026-08-03 01:57:25', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:25'),
(129, 3, 'semestriel', 'Rapport semestriel - BICI CI - Exercice 2014', '2014-10-01', 'https://www.brvm.org/sites/default/files/20141001_-_rs_-_bici_ci_-_exercice_2014.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20141001_-_rs_-_bici_ci_-_exercice_2014.pdf', 389202, '0a0aa4b35bf0aabad8e693755a06d88a3c2eca0aabeaaedbdb0bba65096419be', '2026-08-03 01:57:25', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:25'),
(130, 3, 'annuel', 'Rapport annuel Exercice 2013 BICI CI', '2014-08-29', 'https://www.brvm.org/sites/default/files/20140829_-_ra_-_bici_ci_-_exercice_2013.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20140829_-_ra_-_bici_ci_-_exercice_2013.pdf', 5486586, '703db5beb5d70d0ac45f98c41c32147168be34c2ed65bf31755e64142202d855', '2026-08-03 01:57:25', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:25'),
(131, 3, 'etats_financiers', 'Etats financiers approuvés - BICI CI - Exercice 2013', '2014-06-13', 'https://www.brvm.org/sites/default/files/20140613_-_efa_-_bici_ci_-_exercice_2013.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20140613_-_efa_-_bici_ci_-_exercice_2013.pdf', 40980, '0dba292fda231bd9b07ac7ac56f13c7b1c8ce7a3f175ffed63a973b34ffdbb6d', '2026-08-03 01:57:26', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:26'),
(132, 3, 'etats_financiers', 'Etats financiers provisoires - BICI CI - Exercice 2013', '2014-05-14', 'https://www.brvm.org/sites/default/files/20140514_-_efp_-_bici_ci_-_exercice_2013.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20140514_-_efp_-_bici_ci_-_exercice_2013.pdf', 25466, '0e61d22cad9ebf50f4f7f385232a09a4ed442d21265a996a7ccf22df5ac9c24e', '2026-08-03 01:57:26', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:26'),
(133, 3, 'semestriel', 'Rapport semestriel - BICI CI - Exercice 2013', '2013-11-04', 'https://www.brvm.org/sites/default/files/20131104_-_rs_-_bici_ci_-_exercice_2013.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20131104_-_rs_-_bici_ci_-_exercice_2013.pdf', 88627, 'c66bccc40b97fc24b12f8dd7c50eab61935c6ce5c46af79ea09e3807ff1a4fcf', '2026-08-03 01:57:26', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:26'),
(134, 3, 'semestriel', 'Rapport semestriel - BICI CI - Exercice 2013', '2013-10-31', 'https://www.brvm.org/sites/default/files/20131031_-_rs_-_bici_ci_-_exercice_2013.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20131031_-_rs_-_bici_ci_-_exercice_2013.pdf', 40226, '83e28c6da311990fb2815fa77e2042893de858ac827d10541dbb4a75e6c12db8', '2026-08-03 01:57:26', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:26'),
(135, 3, 'annuel', 'Rapport annuel Exercice 2012 BICI CI', '2013-08-23', 'https://www.brvm.org/sites/default/files/20130823_-_ra_-_bici_ci_-_exercice_2012.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20130823_-_ra_-_bici_ci_-_exercice_2012.pdf', 24789639, '2462e6c328de4c83898b178229c8561b6551fda651bd0ca4a878208a316e9915', '2026-08-03 01:57:28', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:28'),
(136, 3, 'etats_financiers', 'Etats financiers approuvés - BICI CI - Exercice 2012', '2013-07-12', 'https://www.brvm.org/sites/default/files/20130712_-_efa_-_bici_ci_-_exercice_2012.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20130712_-_efa_-_bici_ci_-_exercice_2012.pdf', 25568, '119402c58cd3d1bdd405330b8a9fc9cd18145d3376cd3f4e4481dae2e15cfd5b', '2026-08-03 01:57:28', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:28'),
(137, 3, 'autre', 'Commentaires sur activités annuelles - BICI CI - Exercice 2012', '2013-07-12', 'https://www.brvm.org/sites/default/files/20130712_-_caa_-_bici_ci_-_exercice_2012.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20130712_-_caa_-_bici_ci_-_exercice_2012.pdf', 191446, '324154170249329aae4ac3de3470a9edb3c5e267be9eb495e0e726c578b461ba', '2026-08-03 01:57:28', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:28'),
(138, 3, 'etats_financiers', 'Etats financiers provisoires - BICI CI - Exercice 2012', '2013-04-30', 'https://www.brvm.org/sites/default/files/20130430_-_efp_-_bici_ci_-_exercice_2012.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20130430_-_efp_-_bici_ci_-_exercice_2012.pdf', 25593, '6825516acbaeb6f3b4d8f07a398cd2499b8e7be297cf965055e9e2205381155c', '2026-08-03 01:57:29', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:29'),
(139, 3, 'semestriel', 'Rapport semestriel - BICI CI - Exercice 2012', '2012-10-31', 'https://www.brvm.org/sites/default/files/20121031_-_rs_-_bici_ci_-_exercice_2012.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20121031_-_rs_-_bici_ci_-_exercice_2012.pdf', 32621, '1e9274a350e02d175c62bb68ba70a12bd70fe5d284238be1bbb3f4c1a72ddafd', '2026-08-03 01:57:29', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:29'),
(140, 3, 'etats_financiers', 'Etats financiers approuvés - BICI CI - Exercice 2011', '2012-08-02', 'https://www.brvm.org/sites/default/files/20120802_-_efa_-_bici_ci_-_exercice_2011.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20120802_-_efa_-_bici_ci_-_exercice_2011.pdf', 100812, 'a0a7673e25dea12a791bb3452217ba64985810986cd2b0d984cf44fa82331037', '2026-08-03 01:57:29', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:29'),
(141, 3, 'autre', 'Commentaires sur activités annuelles - BICI CI - Exercice 2011', '2012-08-02', 'https://www.brvm.org/sites/default/files/20120802_-_caa_-_bici_ci_-_exercice_2011.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20120802_-_caa_-_bici_ci_-_exercice_2011.pdf', 326123, '04f1e5072d910e484e126a633955c0c7d0c6b2160e4be1a815d6358514286d50', '2026-08-03 01:57:29', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:29'),
(142, 3, 'annuel', 'Rapport annuel Exercice 2011 BICI CI', '2012-07-20', 'https://www.brvm.org/sites/default/files/20120720_-_ra_-_bici_ci_-_exercice_2011.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20120720_-_ra_-_bici_ci_-_exercice_2011.pdf', 2131916, '435e5855a35e79f292881429ece2f7501be9f2ce4f4e7e886b26587417f0f3ed', '2026-08-03 01:57:30', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:30'),
(143, 3, 'etats_financiers', 'Etats financiers provisoires - BICI CI - Exercice 2011', '2012-06-04', 'https://www.brvm.org/sites/default/files/20120604_-_efp_-_bici_ci_-_exercice_2011.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20120604_-_efp_-_bici_ci_-_exercice_2011.pdf', 40364, 'c29e3472712d6676c77743b30c9b5547a76fda1c3c258217ff8c9a9a5ab92969', '2026-08-03 01:57:30', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:30'),
(144, 3, 'semestriel', 'Rapport semestriel - BICI CI - Exercice 2011', '2011-11-03', 'https://www.brvm.org/sites/default/files/20111103_-_rs_-_bici_ci_-_exercice_2011.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20111103_-_rs_-_bici_ci_-_exercice_2011.pdf', 176931, '60399e9e58c4b4b9a4b752b04394ac07df964e1d80b618f87cc1328634355314', '2026-08-03 01:57:30', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:30'),
(145, 3, 'semestriel', 'Rapport semestriel - BICI CI - Exercice 2011', '2011-10-28', 'https://www.brvm.org/sites/default/files/20111028_-_rs_-_bici_ci_-_exercice_2011.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20111028_-_rs_-_bici_ci_-_exercice_2011.pdf', 149716, 'ef2ae20c94f6faaf6ba4d8e6f5e708cb40a344021b0e575a9fd17717e6f29349', '2026-08-03 01:57:31', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:31'),
(146, 3, 'annuel', 'Rapport annuel Exercice 2010 BICI CI', '2011-10-11', 'https://www.brvm.org/sites/default/files/20111011_-_ra_-_bici_ci_-_exercice_2010.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20111011_-_ra_-_bici_ci_-_exercice_2010.pdf', 23088296, 'fc6f9a31938687ffb2819787637a2332077290088ad167da61b0485d07ff08f6', '2026-08-03 01:57:31', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:31'),
(147, 3, 'etats_financiers', 'Etats financiers approuvés - BICI CI - Exercice 2010', '2011-10-04', 'https://www.brvm.org/sites/default/files/20111004_-_efa_-_bici_ci_-_exercice_2010.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20111004_-_efa_-_bici_ci_-_exercice_2010.pdf', 39164, '535391e86e966521d7c34ec93751b14d1acffd4eaa69248d81cb1a8fa03a89b6', '2026-08-03 01:57:32', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:32'),
(148, 3, 'autre', 'Commentaires sur activités annuelles - BICI CI - Exercice 2010', '2011-10-04', 'https://www.brvm.org/sites/default/files/20111004_-_caa_-_bici_ci_-_exercice_2010.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20111004_-_caa_-_bici_ci_-_exercice_2010.pdf', 352414, '981b592c68f61cee05f86339ef250292bcf262c5fdb895ba1ee05d4905dc50d6', '2026-08-03 01:57:32', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:32'),
(149, 3, 'etats_financiers', 'Etats financiers provisoires - BICI CI - Exercice 2010', '2011-09-07', 'https://www.brvm.org/sites/default/files/20110907_-_efp_-_bici_ci_-_exercice_2010.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20110907_-_efp_-_bici_ci_-_exercice_2010.pdf', 40150, '2176f5de9f908725022f5ffeeed2f2acdf6b3ec826251b6ce392e5e3d718b949', '2026-08-03 01:57:32', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:32'),
(150, 3, 'semestriel', 'Rapport semestriel - BICI CI - Exercice 2010', '2010-10-29', 'https://www.brvm.org/sites/default/files/20101029_-_rs_-_bici_ci_-_exercice_2010.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20101029_-_rs_-_bici_ci_-_exercice_2010.pdf', 23509, 'ea4c2dfecfa7e8c216cb0d1698e42221e8074dc1e2187e779aa130e38b19f3cc', '2026-08-03 01:57:32', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:32'),
(151, 3, 'etats_financiers', 'Etats financiers approuvés - BICI CI - Exercice 2009', '2010-09-01', 'https://www.brvm.org/sites/default/files/20100901_-_efa_-_bici_ci_-_exercice_2009.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20100901_-_efa_-_bici_ci_-_exercice_2009.pdf', 87493, 'a9abe33c79283a5f4651958f5808fb0d9c61f252268689a13ce15ee4490ae5f8', '2026-08-03 01:57:32', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:32'),
(152, 3, 'annuel', 'Rapport annuel Exercice 2009 BICI CI', '2010-07-16', 'https://www.brvm.org/sites/default/files/20100716_-_ra_-_bici_ci_-_exercice_2009.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20100716_-_ra_-_bici_ci_-_exercice_2009.pdf', 6989637, 'ce2660522ed9ef1f620f3f0443e09533e3c8c17c144da32bcb21e924ef571571', '2026-08-03 01:57:33', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:33'),
(153, 3, 'etats_financiers', 'Etats financiers provisoires - BICI CI - Exercice 2009', '2010-04-21', 'https://www.brvm.org/sites/default/files/20100421_-_efp_-_bici_ci_-_exercice_2009.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20100421_-_efp_-_bici_ci_-_exercice_2009.pdf', 213372, '88dc3ef6e6aa1674f3b0532f2d073bc68a1aea35f1c2b15dc9715970cf4829fa', '2026-08-03 01:57:33', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:33'),
(154, 3, 'semestriel', 'Rapport semestriel - BICI CI - Exercice 2009', '2009-11-10', 'https://www.brvm.org/sites/default/files/20091110_-_rs_-_bici_ci_-_exercice_2009.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20091110_-_rs_-_bici_ci_-_exercice_2009.pdf', 597633, '85de595862af7d2c6d79d9ecf2b3445d762cf08e67b43086fd95f011f7d0589a', '2026-08-03 01:57:33', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:33'),
(155, 3, 'annuel', 'Rapport annuel Exercice 2005 BICI CI', '2009-09-29', 'https://www.brvm.org/sites/default/files/20090929_-_ra_-_bici_ci_-_exercice_2005.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20090929_-_ra_-_bici_ci_-_exercice_2005.pdf', 8685549, 'd6d4dbc23d2ad1d141043e4e8555f1e9c815f56fe56bf76bf848ca037d3616cf', '2026-08-03 01:57:34', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:34'),
(156, 3, 'annuel', 'Rapport annuel Exercice 2006 BICI CI', '2009-09-24', 'https://www.brvm.org/sites/default/files/20090924_-_ra_-_bici_ci_-_exercice_2006.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20090924_-_ra_-_bici_ci_-_exercice_2006.pdf', 1472607, 'e22ea933d94ea0389aa11ffafb0ba64a9b684c1507a8084f5994b88c4de3c145', '2026-08-03 01:57:34', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:34'),
(157, 3, 'annuel', 'Rapport annuel Exercice 2009 BICI CI', '2009-08-11', 'https://www.brvm.org/sites/default/files/20090811_-_ra_-_bici_ci_-_exercice_2009.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20090811_-_ra_-_bici_ci_-_exercice_2009.pdf', 9657961, '756fb422e7cb920986ba9fcbe37d32f5fa118f789d613b086be375aa5c182a6b', '2026-08-03 01:57:35', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:35');
INSERT INTO `company_reports` (`id`, `company_id`, `report_type`, `title`, `publish_date`, `file_url`, `local_path`, `file_size`, `file_hash`, `downloaded_at`, `text_extracted`, `extraction_method`, `extraction_error`, `created_at`, `updated_at`) VALUES
(158, 3, 'annuel', 'Rapport annuel Exercice 2007 BICI CI', '2009-06-22', 'https://www.brvm.org/sites/default/files/20090622_-_ra_-_bici_ci_-_exercice_2007.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20090622_-_ra_-_bici_ci_-_exercice_2007.pdf', 10320404, '178e2ebfa9171f7258265c6ea32d2435e1069ae2e46e6580afc3e309b8516ea9', '2026-08-03 01:57:35', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:35'),
(159, 3, 'annuel', 'Rapport annuel Exercice 2004 BICICI', '2009-06-16', 'https://www.brvm.org/sites/default/files/20090616_-_ra_-_bicici_-_exercice_2004.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20090616_-_ra_-_bicici_-_exercice_2004.pdf', 3737982, 'b8a06d1cdf81eb37eb3cac7783d8e22a7604f0f572502d0c982cbf83272e5267', '2026-08-03 01:57:36', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:36'),
(160, 3, 'annuel', 'Rapport annuel Exercice 2008 BICI CI', '2009-06-11', 'https://www.brvm.org/sites/default/files/20090611_-_ra_-_bici_ci_-_exercice_2008.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20090611_-_ra_-_bici_ci_-_exercice_2008.pdf', 1900886, '6bbafa5c4c2e7b8c0870befc43df4680f41db4ccfc83343094b88394a0374fed', '2026-08-03 01:57:36', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:36'),
(161, 3, 'annuel', 'Rapport annuel Exercice 2003 BICI CI', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_bici_ci_-_exercice_2003.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20090530_-_ra_-_bici_ci_-_exercice_2003.pdf', 1356105, '0c3de0e46ccdcae963cdc89417b63a9ec2f295f4140d00be451104f3abf8b81e', '2026-08-03 01:57:39', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:39'),
(162, 3, 'annuel', 'Rapport annuel Exercice 1998 BICI CI', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_bici_ci_-_exercice_1998.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20090530_-_ra_-_bici_ci_-_exercice_1998.pdf', 1253561, 'e660e2fa52c44fa841dd6b4376434298268e446ff6a426f2837b1a343f27ded1', '2026-08-03 01:57:38', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:38'),
(163, 3, 'annuel', 'Rapport annuel Exercice 1999 BICI CI', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_bici_ci_-_exercice_1999.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20090530_-_ra_-_bici_ci_-_exercice_1999.pdf', 1204485, 'e90df65c215c59a849a03c25af165b327db851bf3b6a35963eb8872a5c149312', '2026-08-03 01:57:38', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:38'),
(164, 3, 'annuel', 'Rapport annuel Exercice 2000 BICI CI', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_bici_ci_-_exercice_2000.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20090530_-_ra_-_bici_ci_-_exercice_2000.pdf', 1345041, 'c31c970db96b8f34155c291de616ae8ec5b955f57bd3280aed4db9daebc94ef2', '2026-08-03 01:57:38', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:38'),
(165, 3, 'annuel', 'Rapport annuel Exercice 2001 BICI CI', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_bici_ci_-_exercice_2001.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20090530_-_ra_-_bici_ci_-_exercice_2001.pdf', 1558791, '26c23d2328c8ff79e9edc611897bd0a1bf5d8955e35f5244761ca483013331d4', '2026-08-03 01:57:37', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:37'),
(166, 3, 'annuel', 'Rapport annuel Exercice 2002 BICI CI', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_bici_ci_-_exercice_2002.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20090530_-_ra_-_bici_ci_-_exercice_2002.pdf', 1470364, 'f1999d739c78123c5ea69fda2f2d94cfd0951ff0a20dbc273686ac8f0dc2e725', '2026-08-03 01:57:36', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:37'),
(167, 3, 'etats_financiers', 'Etats financiers provisoires - BICI CI - Exercice 2008', '2009-04-17', 'https://www.brvm.org/sites/default/files/20090417_-_efp_-_bici_ci_-_exercice_2008.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20090417_-_efp_-_bici_ci_-_exercice_2008.pdf', 7656, 'ad840c8ed6b3aae62eb002105fbca57ce4c20764388b6e6b306977fadcd483e3', '2026-08-03 01:57:39', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:39'),
(168, 3, 'semestriel', 'Rapport semestriel - BICI CI - Exercice 2008', '2008-10-28', 'https://www.brvm.org/sites/default/files/20081028_-_rs_-_bici_ci_-_exercice_2008.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20081028_-_rs_-_bici_ci_-_exercice_2008.pdf', 25865, '0eacc513dfabcd93543d16250060cb6c200c9390567dd011342fabf5985c1446', '2026-08-03 01:57:39', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:39'),
(169, 3, 'etats_financiers', 'Etats financiers provisoirses - BICI CI - Exercice 2007', '2008-05-16', 'https://www.brvm.org/sites/default/files/20080516_-_efp_-_bici_ci_-_exercice_2007.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20080516_-_efp_-_bici_ci_-_exercice_2007.pdf', 38999, 'a715d0e38e4117263bb71d92172a72c14656fb225e0de421b61c2ea493e7f473', '2026-08-03 01:57:40', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:40'),
(170, 3, 'semestriel', 'Rapport semestriel - BICI CI - Exercice 2007', '2007-10-30', 'https://www.brvm.org/sites/default/files/20071030_-_rs_-_bici_ci_-_exercice_2007.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20071030_-_rs_-_bici_ci_-_exercice_2007.pdf', 25483, '94f8d6475c0dc01e64341302c68f6ff6bff0fd9bcda66425c52a2a01dcca525a', '2026-08-03 01:57:40', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:40'),
(171, 3, 'etats_financiers', 'Etats financiers provisoires - BICI CI - Exercice 2006', '2007-04-27', 'https://www.brvm.org/sites/default/files/20070427_-_efp_-_bici_ci_-_exercice_2006.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20070427_-_efp_-_bici_ci_-_exercice_2006.pdf', 11858, '12e8fde1f564cba3c8c5ac5754a72601d7651a02d357d721441d4ba264734061', '2026-08-03 01:57:40', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:12', '2026-08-03 02:57:40'),
(172, 3, 'semestriel', 'Rapport semestriel - BICI CI - Exercice 2006', '2006-10-31', 'https://www.brvm.org/sites/default/files/20061031_-_rs_-_bici_ci_-_exercice_2006.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20061031_-_rs_-_bici_ci_-_exercice_2006.pdf', 25136, 'ecec85754bec5d643d68eb22d235335ab025aa64d060a3ca8d85ab7c71f024b9', '2026-08-03 01:57:40', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:13', '2026-08-03 02:57:40'),
(173, 3, 'semestriel', 'Commentaires sur activités semestrielles - BICI CI - Exercice 2005', '2005-10-27', 'https://www.brvm.org/sites/default/files/20051027_-_cas_-_bici_ci_-_exercice_2005.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20051027_-_cas_-_bici_ci_-_exercice_2005.pdf', 15701, '9161bc31889f6c01115c42554d8015d16a411205b20c0a2249247e9a0b1aa104', '2026-08-03 01:57:41', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:13', '2026-08-03 02:57:41'),
(174, 3, 'etats_financiers', 'Etats financiers provisoires - BICI CI - Exercice 2004', '2005-03-09', 'https://www.brvm.org/sites/default/files/20050309_-_efp_-_bici_ci_-_exercice_2004.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20050309_-_efp_-_bici_ci_-_exercice_2004.pdf', 56041, 'd56611b797c9f62a3e668aed35d76993684a80aabbae10fb0df7d240f1d7d205', '2026-08-03 01:57:41', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:13', '2026-08-03 02:57:41'),
(175, 3, 'semestriel', 'Commentaires sur activités semestrielles - BICI CI - Exercice 2004', '2004-10-15', 'https://www.brvm.org/sites/default/files/20041015_-_cas_-_bici_ci_-_exercice_2004.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20041015_-_cas_-_bici_ci_-_exercice_2004.pdf', 17169, 'f969e26611cc418ef0b59a91bbe18b9e2240e36ab03c92af3fbb6a7c46d0d50f', '2026-08-03 01:57:41', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:13', '2026-08-03 02:57:41'),
(176, 3, 'etats_financiers', 'Etats financiers provisoires - BICI CI - Exercice 2003', '2004-04-19', 'https://www.brvm.org/sites/default/files/20040419_-_efp_-_bici_ci_-_exercice_2003.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BICC/20040419_-_efp_-_bici_ci_-_exercice_2003.pdf', 33740, '410dbc4c3ae51406a2b70a0055257a57631193e8d259ff348928d3ae55f50945', '2026-08-03 01:57:41', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:13', '2026-08-03 02:57:41'),
(177, 4, 'etats_financiers', 'BERNABE CI : Etats financiers - Exercice 2025', '2026-04-30', 'https://www.brvm.org/sites/default/files/20260430_-_etats_financiers_-_exercice_2025_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20260430_-_etats_financiers_-_exercice_2025_-_bernabe_ci.pdf', 315449, 'e72bff00c1048ce0de81817355960542b6c9b9466d1a3a4827ce95c3b7262565', '2026-08-03 01:57:51', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:49', '2026-08-03 02:57:51'),
(178, 4, 'trimestriel', 'BERNABE CI : Rapport d\'activités - 1er trimestre 2026', '2026-04-30', 'https://www.brvm.org/sites/default/files/20260430_-_rapport_dactivites_-_1er_trimestre_2026_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20260430_-_rapport_dactivites_-_1er_trimestre_2026_-_bernabe_ci.pdf', 509586, 'ec9df3003f653428ec6fe4957f1096fc91c1ea90c3188a5095635cb3a289c12c', '2026-08-03 01:57:51', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:49', '2026-08-03 02:57:51'),
(179, 4, 'trimestriel', 'BERNABE CÔTE D\'IVOIRE : Rapport d\'activités - 3ème trimestre 2025', '2025-10-31', 'https://www.brvm.org/sites/default/files/20251031_-_rapport_dactivites_-_3eme_trimestre_2025_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20251031_-_rapport_dactivites_-_3eme_trimestre_2025_-_bernabe_ci.pdf', 476054, 'afacc4b03ac8405b020a4e780da1a8f96fb0246ae1703c269c3705df3c6aae1d', '2026-08-03 01:57:51', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:49', '2026-08-03 02:57:51'),
(180, 4, 'semestriel', 'BERNABE CÔTE D\'IVOIRE : Rapport d\'activités - 1er semestre 2025', '2025-10-31', 'https://www.brvm.org/sites/default/files/20251031_-_rapport_dactivites_-_1er_semestre_2025_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20251031_-_rapport_dactivites_-_1er_semestre_2025_-_bernabe_ci.pdf', 175009, '60fb4002ad66ac5220c368235a588f0d9dacd15e5e3e89edf2f231a602d63960', '2026-08-03 01:57:51', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:49', '2026-08-03 02:57:51'),
(181, 4, 'trimestriel', 'BERNABE CI : Rapport d\'activités - 1er trimestre 2025', '2025-05-02', 'https://www.brvm.org/sites/default/files/20250502_-_rapport_dactivites_-_1er_trimestre_2025_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20250502_-_rapport_dactivites_-_1er_trimestre_2025_-_bernabe_ci.pdf', 135424, '80e0c7565fe3754d742a327a4846e3020a75bffede290d4c9561eeb617e9eb62', '2026-08-03 01:57:52', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:49', '2026-08-03 02:57:52'),
(182, 4, 'etats_financiers', 'BERNABE CÔTE D\'IVOIRE : Etats financiers - Exercice 2024', '2025-04-30', 'https://www.brvm.org/sites/default/files/20250430_-_etats_financiers_-_exercice_2024_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20250430_-_etats_financiers_-_exercice_2024_-_bernabe_ci.pdf', 58176, '615494d21bd17b384d430abfaa5e5153aebc01dc41598c95cc3806535e71211f', '2026-08-03 01:57:52', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:49', '2026-08-03 02:57:52'),
(183, 4, 'trimestriel', 'BERNABE CI : Rapport d\'activités - 3ème trimestre 2024', '2024-11-20', 'https://www.brvm.org/sites/default/files/20241120_-_rapport_dactivites_-_3eme_trimestre_2024_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20241120_-_rapport_dactivites_-_3eme_trimestre_2024_-_bernabe_ci.pdf', 121508, 'b5a5a24b47c1af9a2b5932b7a768e4b33e74b0491d211dadeaadd6da6b078ea2', '2026-08-03 01:57:53', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:49', '2026-08-03 02:57:53'),
(184, 4, 'semestriel', 'BERNABE CI : Attestation des Commissaires Aux Comptes sur le rapport d\'activités - 1er semestre 2024', '2024-11-20', 'https://www.brvm.org/sites/default/files/20241120_-_attestation_des_cacs_sur_le_rapport_dactivites_-_1er_semestre_2024_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20241120_-_attestation_des_cacs_sur_le_rapport_dactivites_-_1er_semestre_2024_-_bernabe_ci.pdf', 84031, '8d3c9e0d6cd0d04d1830222bfeab27d5bbadea1d3775c8e5bb4c6d7751907e28', '2026-08-03 01:57:53', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:49', '2026-08-03 02:57:53'),
(185, 4, 'semestriel', 'BERNABE CI : Rapport d\'activités - 1er semestre 2024', '2024-11-20', 'https://www.brvm.org/sites/default/files/20241120_-_rapport_dactivites_-_1er_semestre_2024_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20241120_-_rapport_dactivites_-_1er_semestre_2024_-_bernabe_ci.pdf', 140391, 'd438a2468ee6dd0bf2ecc13613f2bd7e47b96d3ecc7ea015736d2a5b2c52549d', '2026-08-03 01:57:52', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:49', '2026-08-03 02:57:52'),
(186, 4, 'etats_financiers', 'BERNABE CÔTE D\'IVOIRE : Etats financiers de synthèse', '2024-05-07', 'https://www.brvm.org/sites/default/files/20240507_-_etats_financiers_de_synthese_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20240507_-_etats_financiers_de_synthese_-_bernabe_ci.pdf', 57310, 'e5f0565170ddbd0d7241a2cdaa017051a9388be326d901fc56a4787745418a1d', '2026-08-03 01:57:53', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:49', '2026-08-03 02:57:53'),
(187, 4, 'trimestriel', 'BERNABE CI : Rapport d\'activités - 1er trimestre 2024', '2024-05-02', 'https://www.brvm.org/sites/default/files/20240502_-_rapport_dactivites_-_1er_trimestre_2024_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20240502_-_rapport_dactivites_-_1er_trimestre_2024_-_bernabe_ci.pdf', 136399, '1992e370e396561d09f5107115b10996e70db43f019f98c124393011481acb8a', '2026-08-03 01:57:54', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:49', '2026-08-03 02:57:54'),
(188, 4, 'semestriel', 'BERNABE CÔTE D\'IVOIRE : Attestation des Commissaires Aux Comptes sur le Rapport d\'activités du 1er semestre 2023', '2023-11-02', 'https://www.brvm.org/sites/default/files/20231102_-_attestation_des_cac_sur_le_rapport_dactivites_du_1er_semestre_2023_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20231102_-_attestation_des_cac_sur_le_rapport_dactivites_du_1er_semestre_2023_-_bernabe_ci.pdf', 285048, '735659bcb90ced56f458154174be5694f3eb4d422a43c2d847ae05747c1c74ce', '2026-08-03 01:57:54', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:49', '2026-08-03 02:57:54'),
(189, 4, 'trimestriel', 'BERNABE CI : Rapport d\'activités du 3ème trimestre 2023', '2023-10-27', 'https://www.brvm.org/sites/default/files/20231027_-_rapport_dactivites_-_3eme_trimestre_2023_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20231027_-_rapport_dactivites_-_3eme_trimestre_2023_-_bernabe_ci.pdf', 140221, 'e35c78f5a75f33c551f22796cb618886c41d5721435eb6f667a7fb072cf34d3a', '2026-08-03 01:57:54', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:49', '2026-08-03 02:57:54'),
(190, 4, 'etats_financiers', 'BERNABE CI : Etats financiers certifiés et approuvés - Exercice 2022', '2023-08-01', 'https://www.brvm.org/sites/default/files/20230801_-_etats_financiers_certifies_et_approuves_-_exercice_2022_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20230801_-_etats_financiers_certifies_et_approuves_-_exercice_2022_-_bernabe_ci.pdf', 56000, '1d3cf98897003ae0068b4497cd293e639d0f255642c2381773c9496d1152a356', '2026-08-03 01:57:55', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:49', '2026-08-03 02:57:55'),
(191, 4, 'trimestriel', 'BERNABE CI : Rapport d\'activité du 1er trimestre 2023', '2023-04-28', 'https://www.brvm.org/sites/default/files/20230428_-_rapport_dactivite_-_1er_trimestre_2023_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20230428_-_rapport_dactivite_-_1er_trimestre_2023_-_bernabe_ci.pdf', 136307, '1a15248efdc66e6acc6d014da2651e81c386328a45442356bf0ad03526b6e4c8', '2026-08-03 01:57:55', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:49', '2026-08-03 02:57:55'),
(192, 4, 'etats_financiers', 'BERNABE CÔTE D\'IVOIRE : États financiers exercice 2022', '2023-04-25', 'https://www.brvm.org/sites/default/files/20230425_-_etats_financiers_exercice_2022_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20230425_-_etats_financiers_exercice_2022_-_bernabe_ci.pdf', 55037, '9de3fe2df8903803a89e1d669a886700a0422a93a6d2360a116f0e0dfeaa54ee', '2026-08-03 01:57:55', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:49', '2026-08-03 02:57:55'),
(193, 4, 'trimestriel', 'BERNABE CI : Rapport d\'activité du 3eme trimestre 2022', '2022-12-15', 'https://www.brvm.org/sites/default/files/20221215_-_rapport_dactivite_-_3eme_trimestre_2022_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20221215_-_rapport_dactivite_-_3eme_trimestre_2022_-_bernabe_ci.pdf', 154497, '9f62b9d8557f5dcad2557d9b110614b0258baf79faeda4945c6fa504e45fec9b', '2026-08-03 01:57:56', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:49', '2026-08-03 02:57:56'),
(194, 4, 'semestriel', 'BERNABE CI : Rapport d\'activités du 1er semestre 2022', '2022-09-26', 'https://www.brvm.org/sites/default/files/20220926_-_rapport_dactivite_du_1er_semestre_2022_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20220926_-_rapport_dactivite_du_1er_semestre_2022_-_bernabe_ci.pdf', 155911, 'f50ed4e5d54a1646db963fa18174db56d80889483dc55ac3882a6ac548ec6801', '2026-08-03 01:57:56', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:57:56'),
(195, 4, 'etats_financiers', 'BERNABE CI : Etats financiers Exercice 2021', '2022-05-03', 'https://www.brvm.org/sites/default/files/20220503_-_etats_financiers_exercice_2021_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20220503_-_etats_financiers_exercice_2021_-_bernabe_ci.pdf', 56417, '66b5895aed1bb2afafc89e96be59b56f320f8460da0077e3eea8b393dd624765', '2026-08-03 01:57:56', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:57:56'),
(196, 4, 'trimestriel', 'BERNABE CI : Rapport d\'Activité du 1er trimestre 2022', '2022-05-03', 'https://www.brvm.org/sites/default/files/20220503_-_rapport_dactivite_-_1er_trimestre_2022_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20220503_-_rapport_dactivite_-_1er_trimestre_2022_-_bernabe_ci.pdf', 451646, 'd545e264d55c7ef6db253c0a1b31ede733a43b41fdb42c2655d0f98e2fcdf140', '2026-08-03 01:57:56', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:57:56'),
(197, 4, 'trimestriel', 'BERNABE CI : Rapport d\'activité au 3ème trimestre 2021', '2021-11-03', 'https://www.brvm.org/sites/default/files/20211103_-_rapport_dactivite_au_3eme_trimestre_2021_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20211103_-_rapport_dactivite_au_3eme_trimestre_2021_-_bernabe_ci.pdf', 488408, '226d5eef058c644f8d271739dd56a428868c27a8fc4e766622c5db131b42c177', '2026-08-03 01:57:57', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:57:57'),
(198, 4, 'semestriel', 'BERNABE CI : Attestation des commissaires aux comptes du 1er semestre 2021', '2021-10-25', 'https://www.brvm.org/sites/default/files/20211025_-_attestation_des_commissaires_aux_comptes_-_1er_semestre_2021_-_bernabe_ci_0.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20211025_-_attestation_des_commissaires_aux_comptes_-_1er_semestre_2021_-_bernabe_ci_0.pdf', 960446, 'fdde78b1852094b37c3754e291866c10f69f83bac95d5ff527be8e81fb6160cf', '2026-08-03 01:57:57', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:57:57'),
(199, 4, 'semestriel', 'BERNABE CI : Rapport d\'activités du 1er Semestre 2021', '2021-09-13', 'https://www.brvm.org/sites/default/files/20210913_-_rapport_dactivites_du_1er_semestre_2021_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20210913_-_rapport_dactivites_du_1er_semestre_2021_-_bernabe_ci.pdf', 617081, '8adaaa78e8490d22c0f2e1f96144ed51862e22f4306ed78020286722cde953dd', '2026-08-03 01:57:57', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:57:57'),
(200, 4, 'etats_financiers', 'BERNABE CÔTE D\'IVOIRE : Etats Financiers - Exercice 2020', '2021-05-14', 'https://www.brvm.org/sites/default/files/20210514_-_etats_financiers_-_exercice_2020_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20210514_-_etats_financiers_-_exercice_2020_-_bernabe_ci.pdf', 45322, '0afd22add499b8a2bd8defeb9b43cf2909701402a7fc8c1c9f160f17bdcf97cd', '2026-08-03 01:57:58', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:57:58'),
(201, 4, 'etats_financiers', 'BERNABE CI : Etats Financiers - Exercice 2020', '2021-04-29', 'https://www.brvm.org/sites/default/files/20210429_-_etats_financiers_-_exercice_2020_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20210429_-_etats_financiers_-_exercice_2020_-_bernabe_ci.pdf', 42715, '2f68e84ce131dfde032fcf664cf0519ca15c655abdfc0b7af430b204251ef4cf', '2026-08-03 01:57:58', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:57:58'),
(202, 4, 'trimestriel', 'BERNABE CI: Rapport d\'activité au 1er trimestre 2021', '2021-04-29', 'https://www.brvm.org/sites/default/files/20210429_-_rapport_dactivites_au_1er_trimestre_2021_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20210429_-_rapport_dactivites_au_1er_trimestre_2021_-_bernabe_ci.pdf', 536575, 'bcf8ca9d578c446659fb57055349d41000d6c3fd8c42363c32649aabe461b8dd', '2026-08-03 01:57:58', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:57:58'),
(203, 4, 'semestriel', 'BERNABE CI : Rapport d\'activités du 1er semestre 2020 selon les normes IFRS', '2020-10-30', 'https://www.brvm.org/sites/default/files/20201030_-_rapport_dactivites_du_1er_semestre_2020_selon_les_normes_ifrs_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20201030_-_rapport_dactivites_du_1er_semestre_2020_selon_les_normes_ifrs_-_bernabe_ci.pdf', 408594, '35ceef78eadee3a545da30739057479aa2848390539efef79463eb0998ed01dd', '2026-08-03 01:57:58', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:57:58'),
(204, 4, 'trimestriel', 'BERNABE CI : Rapport d\'activités au 3ème trimestre 2020', '2020-10-28', 'https://www.brvm.org/sites/default/files/20201028_-_rapport_dactivites_du_3eme_trimestre_2020_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20201028_-_rapport_dactivites_du_3eme_trimestre_2020_-_bernabe_ci.pdf', 510603, '1a5525da498b049788615baa54a8bd3a3505b1d2e1ed11db6b7c05a2e823ddec', '2026-08-03 01:57:59', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:57:59'),
(205, 4, 'etats_financiers', 'BERNABE CI : Etats financiers Annuels IFRS exercice 2019 (annule et remplace le précédent)', NULL, 'https://www.brvm.org/sites/default/files/bernabe_-_etats_financiers_annuels_ifrs_2019.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/bernabe_-_etats_financiers_annuels_ifrs_2019.pdf', 571824, '572512919a5a1b6046f058d377a3adf8984612172b554cb86e528ab780cd6082', '2026-08-03 01:58:24', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:24'),
(206, 4, 'etats_financiers', 'BERNABE CI : Etats financiers exercice 2019', NULL, 'https://www.brvm.org/sites/default/files/bernabe_-_etats_financiers_ifrs_2019.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/bernabe_-_etats_financiers_ifrs_2019.pdf', 576677, 'a2046320c14c4d9aa94bdec757c534868ad8cc3743810a9282e274116fe782fb', '2026-08-03 01:58:24', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:24'),
(207, 4, 'semestriel', 'BERNABE CI : Rapport d\'activité du 1er semestre 2020', NULL, 'https://www.brvm.org/sites/default/files/rapport_dactivites_du_1er_semestre_2020_bernabe_ci_vf.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/rapport_dactivites_du_1er_semestre_2020_bernabe_ci_vf.pdf', 633100, '4c4d39ee6e98425b8a21741497afb69b1c7713ba94c2fdf1b17f9bdf5f79f868', '2026-08-03 01:58:23', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:23'),
(208, 4, 'etats_financiers', 'BERNABE CI : Etats financiers exercice 2019', '2020-09-09', 'https://www.brvm.org/sites/default/files/20200909_-_etats_financiers_exercice_2019_-_bernabe_ci_0.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20200909_-_etats_financiers_exercice_2019_-_bernabe_ci_0.pdf', 45730, '290cd6f3d8454d486b29960dca99285905921aab95525ac591396cbc2a9ecc16', '2026-08-03 01:57:59', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:57:59'),
(209, 4, 'etats_financiers', 'BERNABE CI : Etats financiers certifiés - exercice 2019', NULL, 'https://www.brvm.org/sites/default/files/etats_financiers_certifes_exercice_2019_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/etats_financiers_certifes_exercice_2019_-_bernabe_ci.pdf', 46200, '8694239c7cafd76f57e8b7db96fd01d4cc81aac5571be93875248e726015e2c2', '2026-08-03 01:58:23', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:23'),
(210, 4, 'etats_financiers', 'BERNABE CI : Etats financiers exercice 2019', '2020-04-30', 'https://www.brvm.org/sites/default/files/20200430_-_etats_financiers_exercice_2019_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20200430_-_etats_financiers_exercice_2019_-_bernabe_ci.pdf', 45856, 'ad8df903fb5b1f6f1280a3440731c671e2df194787d06a783b32ce75fe99396b', '2026-08-03 01:58:00', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:00'),
(211, 4, 'trimestriel', 'BERNABE CI : Rapport d\'activité du 1er trimestre 2020', '2020-04-30', 'https://www.brvm.org/sites/default/files/20200430_-_rapport_dactivite_du_1er_trimestre_2020_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20200430_-_rapport_dactivite_du_1er_trimestre_2020_-_bernabe_ci.pdf', 538039, '19a03c8cfbb5aa134b6cc91eaecd7496b5194c1245c7a4bcd64a5f49d0900296', '2026-08-03 01:57:59', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:57:59'),
(212, 4, 'trimestriel', 'BERNABE CI : Rapport d\'activités du 3e  trimestre 2019', '2019-10-31', 'https://www.brvm.org/sites/default/files/20191031_-_rapport_dactivites_au_3eme_trimestre_2019_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20191031_-_rapport_dactivites_au_3eme_trimestre_2019_-_bernabe_ci.pdf', 514617, '06f1459be259f1976a556e584f7543c5f0ec4eb627c9a76abba5e9c6d0c83dca', '2026-08-03 01:58:00', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:00'),
(213, 4, 'semestriel', 'BERNABE CI : Rapport d\'activités du 1er semestre 2019', '2019-10-21', 'https://www.brvm.org/sites/default/files/20191021_-_rapport_dactivites_du_1er_semestre_2019_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20191021_-_rapport_dactivites_du_1er_semestre_2019_-_bernabe_ci.pdf', 1308031, '476cdfc9dd0299ce308e7665f9eae498fc346c93d33afb0544e9f4d920e9bb74', '2026-08-03 01:58:01', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:01'),
(214, 4, 'semestriel', 'BERNABE CI : Attestation des Commissaires aux comptes sur le rapport d\'activités du 1er semestre 2019', '2019-10-21', 'https://www.brvm.org/sites/default/files/20191021_-_attestation_des_cac_sur_le_rapport_dactivites_1er_semestre_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20191021_-_attestation_des_cac_sur_le_rapport_dactivites_1er_semestre_-_bernabe_ci.pdf', 593168, '668bc68465b972d20c8470ba6cee0ebbb57e78513bf037a7571924c5d2fa4203', '2026-08-03 01:58:00', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:00'),
(215, 4, 'annuel', 'BERNABE CI : Rapport Annuel 2018', NULL, 'https://www.brvm.org/sites/default/files/rapport_annuel_2018_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/rapport_annuel_2018_bernabe_ci.pdf', 18880778, '6c9f1efddc1b392904c96682c54590e0c4c8f385f6576bbfa25d52961b8d2d19', '2026-08-03 01:58:23', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:23'),
(216, 4, 'trimestriel', 'BERNABE CI : Rapport d’activités du 1er trimestre 2019', '2019-06-03', 'https://www.brvm.org/sites/default/files/20190603_-_rapport_dactivites_du_1er_trimestre_2019_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20190603_-_rapport_dactivites_du_1er_trimestre_2019_-_bernabe_ci.pdf', 455216, '43de716346ff2217a2dd1a2f8812061aa8915ed7bd2dc30cfca7d63a746bb4b9', '2026-08-03 01:58:01', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:01'),
(217, 4, 'etats_financiers', 'BERNABE CI : Etats financiers Exercice 2018', '2019-05-13', 'https://www.brvm.org/sites/default/files/20190513_-_etats_financiers_exercice_2018_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20190513_-_etats_financiers_exercice_2018_-_bernabe_ci.pdf', 170589, '044ef8f28d14ba7603ca7c26bc430c754aa1df87d6850706f0dca5c15b92f969', '2026-08-03 01:58:02', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:02'),
(218, 4, 'semestriel', 'BERNABE CI : Attestation des Commissaires aux comptes du rapport d\'activités du 1er semestre 2018', '2018-10-26', 'https://www.brvm.org/sites/default/files/20181026_-_attestation_rapport_dactivite_semestriel_au_30_juin_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20181026_-_attestation_rapport_dactivite_semestriel_au_30_juin_-_bernabe_ci.pdf', 441375, 'c637794c69e79a46be2048f79915705851f08f9af8fa258e86dbbaae5026ffe2', '2026-08-03 01:58:03', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:03'),
(219, 4, 'semestriel', 'BERNABE CI : Rapport d’activités du 1er semestre 2018', '2018-10-26', 'https://www.brvm.org/sites/default/files/20181026_-_rapport_dactivites_du_1er_semestre_2018_-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20181026_-_rapport_dactivites_du_1er_semestre_2018_-_bernabe_ci.pdf', 627834, '44681784ca8dc07cd27b1e03fc2d75586522ed51df2330f1d2c6d396679e9bd4', '2026-08-03 01:58:02', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:02'),
(220, 4, 'trimestriel', 'BERNABE CI  : Rapport d’activités du 1er trimestre 2018', '2018-05-09', 'https://www.brvm.org/sites/default/files/20180509_-_rapport_dactivite_du_1er_trimestre_2018_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20180509_-_rapport_dactivite_du_1er_trimestre_2018_bernabe_ci.pdf', 320534, '72ae2b2eef1f85ddae68a81eaedaba1f79da6e20fc103344c6b0262265f43ec5', '2026-08-03 01:58:03', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:03'),
(221, 4, 'etats_financiers', 'BERNABE CI : Etats financiers exercice 2017', '2018-05-09', 'https://www.brvm.org/sites/default/files/20180509_-_etats_financiers_provisoires_bernabe_ci-_exercice_2017.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20180509_-_etats_financiers_provisoires_bernabe_ci-_exercice_2017.pdf', 115706, 'd64a5e8f9382cc987bc3e45bcc8bcb9e4c5ed2177dc432595f199a0c954eb42f', '2026-08-03 01:58:03', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:03'),
(222, 4, 'semestriel', 'Rapport d\'activités au 1er semestre 2017- BERNABE CI', NULL, 'https://www.brvm.org/sites/default/files/20171016-rapport_dactivite_1er_semestre_bernabe_ci_2017_version_modifiee.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20171016-rapport_dactivite_1er_semestre_bernabe_ci_2017_version_modifiee.pdf', 1623381, '0abde85583bb3b5ed81db170c611dfa487e58b5bc159189f33406180547c9ab1', '2026-08-03 01:58:22', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:22'),
(223, 4, 'semestriel', 'Attestation des Commissaires Aux Comptes relative  Rapport d\'activité du 1er semestre 2017 - BERNABE CI', NULL, 'https://www.brvm.org/sites/default/files/20171013-attestation_des_commissaires_aux_comptes_relative_rapport_dactivite_du_premier_semestre-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20171013-attestation_des_commissaires_aux_comptes_relative_rapport_dactivite_du_premier_semestre-_bernabe_ci.pdf', 1986947, '3823cedec58fcfc502651d5072cf6a56cb24f4eb6917e1d9866df4a6fe89b8fb', '2026-08-03 01:58:22', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:22'),
(224, 4, 'semestriel', 'Rapport d\'activité 1er Semestre -BERNABE CI', NULL, 'https://www.brvm.org/sites/default/files/20171013-rapport_dactivite_1er_semestre_-bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20171013-rapport_dactivite_1er_semestre_-bernabe_ci.pdf', 3691339, '524f1709e34364d9192e8fc761b6eab2f1b377d9960786ba24a20a000744b62e', '2026-08-03 01:58:21', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:21'),
(225, 4, 'etats_financiers', 'Etats Financiers Exercice 2016 - BERNABE CI', NULL, 'https://www.brvm.org/sites/default/files/20170526-_etats_financiers_exercice_2016-_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20170526-_etats_financiers_exercice_2016-_bernabe_ci.pdf', 127123, '823725564bdd1b7f0570dc829eae965a66dee1bd81fc5536752e4279e4bec4fb', '2026-08-03 01:58:21', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:21'),
(226, 4, 'etats_financiers', 'Etats Financiers Provisoires 2016 - BERNABE CI', NULL, 'https://www.brvm.org/sites/default/files/etats_financiers_provisoires_-_bernabe_ci-_exercice_2016.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/etats_financiers_provisoires_-_bernabe_ci-_exercice_2016.pdf', 130551, '07d4198fb88f8a177f3b509181917629e1b4dd44b6f9a318638c5ed4ce91866f', '2026-08-03 01:58:21', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:21'),
(227, 4, 'semestriel', 'Rapport d\'Activité du 1er Semestre 2016 BERNABE CI', '2016-12-01', 'https://www.brvm.org/sites/default/files/20161201_-_rapport_dactivite_du_1er_semestre_2016_bernabe_ci.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20161201_-_rapport_dactivite_du_1er_semestre_2016_bernabe_ci.pdf', 2127996, '8a5c80a832a8fcce08dc592dd5c35783f71045233f0749148c4bdef4249f2f6f', '2026-08-03 01:58:04', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:04'),
(228, 4, 'etats_financiers', 'Etats financiers provisoires - BERNABE CI - Exercice 2015', '2016-05-30', 'https://www.brvm.org/sites/default/files/20160530_-_efp_-_bernabe_ci_-_exercice_2015.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20160530_-_efp_-_bernabe_ci_-_exercice_2015.pdf', 271704, '2d15b7f92a925080e8b4831e126724f61ce081c4409cd56e3d2b9c5bae96fe80', '2026-08-03 01:58:04', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:04'),
(229, 4, 'semestriel', 'Rapport semestriel - BERNABE CI - Exercice 2015', '2015-10-22', 'https://www.brvm.org/sites/default/files/20151022_-_rs_-_bernabe_ci_-_exercice_2015.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20151022_-_rs_-_bernabe_ci_-_exercice_2015.pdf', 388554, '115866d10757e93adb4f6d031fe615ca634d0bcfb764411804ceae0cf1d7e74b', '2026-08-03 01:58:05', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:05'),
(230, 4, 'annuel', 'Rapport annuel Exercice 2014 BERNABE CI', '2015-10-16', 'https://www.brvm.org/sites/default/files/20151016_-_ra_-_bernabe_ci_-_exercice_2014.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20151016_-_ra_-_bernabe_ci_-_exercice_2014.pdf', 9406107, '440096a435f3d47d02e4f1d87848bf4194b346e63f05a66bf5da878e6a4e2b8d', '2026-08-03 01:58:05', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:05'),
(231, 4, 'etats_financiers', 'Etats financiers provisoires - BERNABE CI - Exercice 2014', '2015-05-21', 'https://www.brvm.org/sites/default/files/20150521_-_efp_-_bernabe_ci_-_exercice_2014.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20150521_-_efp_-_bernabe_ci_-_exercice_2014.pdf', 73383, 'a0c5ef8cb92b0cb898a31ff4f5cf178694e2d9c572bd2bf412c1ae4e98812814', '2026-08-03 01:58:05', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:05'),
(232, 4, 'semestriel', 'Rapport semestriel - BERNABE CI - Exercice 2014', '2014-10-22', 'https://www.brvm.org/sites/default/files/20141022_-_rs_-_bernabe_ci_-_exercice_2014.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20141022_-_rs_-_bernabe_ci_-_exercice_2014.pdf', 390064, '0dea8e9586f96eba59617599fd86c06ea36ce0738ceae1a2d60c39b35d72f6eb', '2026-08-03 01:58:06', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:06'),
(233, 4, 'annuel', 'Rapport annuel Exercice 2013 BERNABE CI', '2014-07-09', 'https://www.brvm.org/sites/default/files/20140709_-_ra_-_bernabe_ci_-_exercice_2013.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20140709_-_ra_-_bernabe_ci_-_exercice_2013.pdf', 450556, '81c6bf26361163a98b4a7c6e94c9c4962dbfbc9d52ea8f839f9b9049e552160c', '2026-08-03 01:58:06', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:06'),
(234, 4, 'etats_financiers', 'Etats financiers provisoires - BERNABE CI - Exercice 2013', '2014-05-28', 'https://www.brvm.org/sites/default/files/20140528_-_efp_-_bernabe_ci_-_exercice_2013.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20140528_-_efp_-_bernabe_ci_-_exercice_2013.pdf', 67615, 'c2e6d916c8ed63359cb6e7787ec17b3ba0c2b9f5bfd79700854b6e2072a6092a', '2026-08-03 01:58:07', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:07'),
(235, 4, 'autre', 'Commentaires sur activités annuelles - BERNABE CI - Exercice 2013', '2014-05-28', 'https://www.brvm.org/sites/default/files/20140528_-_caa_-_bernabe_ci_-_exercice_2013.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20140528_-_caa_-_bernabe_ci_-_exercice_2013.pdf', 87433, '679d9da5e50fedafbc30b6c4b93602d6cc846100155c93f58e0552cf7fd780a4', '2026-08-03 01:58:06', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:07'),
(236, 4, 'semestriel', 'Rapport semestriel - BERNABE CI - Exercice 2013', '2013-09-26', 'https://www.brvm.org/sites/default/files/20130926_-_rs_-_bernabe_ci_-_exercice_2013.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20130926_-_rs_-_bernabe_ci_-_exercice_2013.pdf', 41735, '3cdfa39fa646e1bc77c07323472d728c98a873799d0b05bd7e4161ce1f6300fb', '2026-08-03 01:58:07', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:07');
INSERT INTO `company_reports` (`id`, `company_id`, `report_type`, `title`, `publish_date`, `file_url`, `local_path`, `file_size`, `file_hash`, `downloaded_at`, `text_extracted`, `extraction_method`, `extraction_error`, `created_at`, `updated_at`) VALUES
(237, 4, 'annuel', 'Rapport annuel Exercice 2012 BERNABE CI', '2013-08-27', 'https://www.brvm.org/sites/default/files/20130827_-_ra_-_bernabe_ci_-_exercice_2012.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20130827_-_ra_-_bernabe_ci_-_exercice_2012.pdf', 1331872, '63f23ec6f0e590acd141a8d2c771199875f614ee2c5b4d908422ff3ff9439806', '2026-08-03 01:58:08', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:08'),
(238, 4, 'etats_financiers', 'Etats financiers approuvés - BERNABE CI - Exercice 2012', '2013-07-22', 'https://www.brvm.org/sites/default/files/20130722_-_efa_-_bernabe_ci_-_exercice_2012.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20130722_-_efa_-_bernabe_ci_-_exercice_2012.pdf', 81710, 'fb195286d30c6cca4c9ff94345bbca83d4c47254868f4e704e28fb0440e960ee', '2026-08-03 01:58:08', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:08'),
(239, 4, 'autre', 'Commentaires sur activités annuelles - BERNABE CI - Exercice 2012', '2013-07-22', 'https://www.brvm.org/sites/default/files/20130722_-_caa_-_bernabe_ci_-_exercice_2012.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20130722_-_caa_-_bernabe_ci_-_exercice_2012.pdf', 100281, 'f06707867427045e39f14a3af303c4cccca4a0149061269f79fe5f857c07e0e0', '2026-08-03 01:58:08', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:08'),
(240, 4, 'etats_financiers', 'Etats financiers provisoires - BERNABE CI - Exercice 2012', '2013-05-22', 'https://www.brvm.org/sites/default/files/20130522_-_efp_-_bernabe_ci_-_exercice_2012.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20130522_-_efp_-_bernabe_ci_-_exercice_2012.pdf', 70521, '5a362d925797e2fc95e4300c7c31b5333158d48d4c0ab44c1d1219eff6f2ca73', '2026-08-03 01:58:08', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:08'),
(241, 4, 'semestriel', 'Rapport semestriel - BERNABE CI - Exercice 2012', '2012-10-22', 'https://www.brvm.org/sites/default/files/20121022_-_rs_-_bernabe_ci_-_exercice_2012.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20121022_-_rs_-_bernabe_ci_-_exercice_2012.pdf', 51832, '3786bee6cca8f26380e9289fe03c0ebeb574eb27533263e57738495e94733021', '2026-08-03 01:58:09', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:09'),
(242, 4, 'annuel', 'Rapport annuel Exercice 2011 BERNABE CI', '2012-08-09', 'https://www.brvm.org/sites/default/files/20120809_-_ra_-_bernabe_ci_-_exercice_2011.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20120809_-_ra_-_bernabe_ci_-_exercice_2011.pdf', 3475548, '1ca8ff2cf68f9fae89267b34bb04bdbe3aa588fbbdebee092fed516c30cc437f', '2026-08-03 01:58:09', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:09'),
(243, 4, 'etats_financiers', 'Etats financiers approuvés - BERNABE CI - Exercice 2011', '2012-07-03', 'https://www.brvm.org/sites/default/files/20120703_-_efa_-_bernabe_ci_-_exercice_2011.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20120703_-_efa_-_bernabe_ci_-_exercice_2011.pdf', 197567, '1bfef770fe3deeda969754ae25d2aef14b535b1a598db6b8124fafde5419ee0a', '2026-08-03 01:58:09', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:10'),
(244, 4, 'etats_financiers', 'Etats financiers provisoires - BERNABE CI - Exercice 2011', '2012-05-23', 'https://www.brvm.org/sites/default/files/20120523_-_efa_-_bernabe_ci_-_exercice_2011.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20120523_-_efa_-_bernabe_ci_-_exercice_2011.pdf', 126347, '33a08d12c45e22d79f9d99fa27a3db145bd50ad1df6c6056a54de7091da3b748', '2026-08-03 01:58:10', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:10'),
(245, 4, 'semestriel', 'Rapport semestriel - BERNABE CI - Exercice 2011', '2011-10-20', 'https://www.brvm.org/sites/default/files/20111020_-_rs_-_bernabe_ci_-_exercice_2011.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20111020_-_rs_-_bernabe_ci_-_exercice_2011.pdf', 145671, '9f3307ebee21e274a32f0ceed9343e429cbb56c6856b136f0937f6cda94d3c40', '2026-08-03 01:58:10', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:10'),
(246, 4, 'annuel', 'Rapport annuel Exercice 2010 BERNABE CI', '2011-10-05', 'https://www.brvm.org/sites/default/files/20111005_-_ra_-_bernabe_ci_-_exercice_2010.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20111005_-_ra_-_bernabe_ci_-_exercice_2010.pdf', 1718294, '85db730af1fd3b5b57fa75b6f2131c566f7ae80f92fc05ff73a4e2234705acc7', '2026-08-03 01:58:11', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:11'),
(247, 4, 'etats_financiers', 'Etats financiers approuvés - BERNABE CI - Exercice 2010', '2011-07-27', 'https://www.brvm.org/sites/default/files/20110727_-_efa_-_bernabe_ci_-_exercice_2010.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20110727_-_efa_-_bernabe_ci_-_exercice_2010.pdf', 74186, '53a663ec0b4649bc780ddd45f8e4162bf4d9274c6991aadc3816463c637d4866', '2026-08-03 01:58:12', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:12'),
(248, 4, 'etats_financiers', 'Etats financiers provisoires - BERNABE CI - Exercice 2010', '2011-06-14', 'https://www.brvm.org/sites/default/files/20110614_-_efp_-_bernabe_ci_-_exercice_2010.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20110614_-_efp_-_bernabe_ci_-_exercice_2010.pdf', 80756, '72678820d6ec8451da4f2063751ecb6c95e83d5c6cff6353487dbd5195b4e621', '2026-08-03 01:58:12', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:12'),
(249, 4, 'semestriel', 'Rapport semestriel - BERNABE CI - Exercice 2010', '2010-08-17', 'https://www.brvm.org/sites/default/files/20100817_-_rs_-_bernabe_ci_-_exercice_2010.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20100817_-_rs_-_bernabe_ci_-_exercice_2010.pdf', 7910, '2c5bebb1c91308bd0b790e9942cd5bc0eed6d516cb7856ffdb61c381b051e303', '2026-08-03 01:58:13', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:13'),
(250, 4, 'annuel', 'Rapport annuel Exercice 2009 BERNABE CI', '2010-07-20', 'https://www.brvm.org/sites/default/files/20100720_-_ra_-_bernabe_ci_-_exercice_2009.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20100720_-_ra_-_bernabe_ci_-_exercice_2009.pdf', 826462, '93f62c076db0879e588f852f69a0563b6ed6671e3c490ef6bfb58cc49520a352', '2026-08-03 01:58:13', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:13'),
(251, 4, 'etats_financiers', 'Etats financiers provisoires - BERNABE CI - Exercice 2009 (rectificatif)', '2010-05-07', 'https://www.brvm.org/sites/default/files/20100507_-_efp_-_bernabe_ci_-_exercice_2009_rectificatif.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20100507_-_efp_-_bernabe_ci_-_exercice_2009_rectificatif.pdf', 72702, '4107bef172137123efb3c2f108e2c6384bc83540f3f7c0969dd83e889880f350', '2026-08-03 01:58:13', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:14'),
(252, 4, 'etats_financiers', 'Etats financiers provisoires - BERNABE CI - Exercice 2009', '2010-05-05', 'https://www.brvm.org/sites/default/files/20100505_-_efp_-_bernabe_ci_-_exercice_2009.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20100505_-_efp_-_bernabe_ci_-_exercice_2009.pdf', 25431, 'a8b6652e84c64adc0f888eb5b1ab375340f0c31e9fa1d13235adf1926c1a0b16', '2026-08-03 01:58:14', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:14'),
(253, 4, 'etats_financiers', 'Etats financiers approuvés - BERNABE CI - Exercice 2003', '2009-09-15', 'https://www.brvm.org/sites/default/files/20090915_-_efp_-_bernabe_ci_-_exercice_2003.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20090915_-_efp_-_bernabe_ci_-_exercice_2003.pdf', 199804, '3e9ba3c2958899308c84e2339d002d62651bec426f426b6271935f930fd3435c', '2026-08-03 01:58:15', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:15'),
(254, 4, 'etats_financiers', 'Etats financiers approuvés - BERNABE CI - Exercice 2004', '2009-09-15', 'https://www.brvm.org/sites/default/files/20090915_-_efa_-_bernabe_ci_-_exercice_2004.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20090915_-_efa_-_bernabe_ci_-_exercice_2004.pdf', 501637, '0aa84046c8cf1163374a0cf789f8ab3e443e58dbb25202eb443c856a1b557f60', '2026-08-03 01:58:15', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:15'),
(255, 4, 'etats_financiers', 'Etats financiers consolidés - CFAO MOTORS CI - Exercice 2006', '2009-09-15', 'https://www.brvm.org/sites/default/files/20090915_-_efc_-_cfao_motors_ci_-_exercice_2006.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20090915_-_efc_-_cfao_motors_ci_-_exercice_2006.pdf', 52556, '09bf9cea022102f99ce9ffd4e529f6fda61daeeda3799c1723b347c3cdcaea2e', '2026-08-03 01:58:15', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:15'),
(256, 4, 'etats_financiers', 'Etats financiers provisoires - BERNABE CI - Exercice 2005', '2009-09-15', 'https://www.brvm.org/sites/default/files/20090915_-_efp_-_bernabe_ci_-_exercice_2005.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20090915_-_efp_-_bernabe_ci_-_exercice_2005.pdf', 120905, 'ea4e98310c0293990ebf1fd375a36c0554a10d23b4964ec449663f8ebe78ff39', '2026-08-03 01:58:14', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:14'),
(257, 4, 'autre', 'Rapport de gestion Exercice 2007 BERNABE CI', '2009-08-31', 'https://www.brvm.org/sites/default/files/20090831_-_rg_-_bernabe_ci_-_exercice_2007.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20090831_-_rg_-_bernabe_ci_-_exercice_2007.pdf', 9236035, 'eec340e00e676561c41c3771f2fa839ae4717a2d9ec56b6d00657478564b8b11', '2026-08-03 01:58:16', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:16'),
(258, 4, 'semestriel', 'Rapport semestriel - BERNABE CI - Exercice 2009', '2009-08-25', 'https://www.brvm.org/sites/default/files/20090825_-_rs_-_bernabe_ci_-_exercice_2009.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20090825_-_rs_-_bernabe_ci_-_exercice_2009.pdf', 99038, '9375621a9f1dc8983132958d1b01056b51ebcadbb75bc984aad69896fcba17df', '2026-08-03 01:58:16', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:16'),
(259, 4, 'annuel', 'Rapport annuel Exercice 2008 BERNABE CI', '2009-07-20', 'https://www.brvm.org/sites/default/files/20090720_-_ra_-_bernabe_ci_-_exercice_2008.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20090720_-_ra_-_bernabe_ci_-_exercice_2008.pdf', 38152, 'a026c7fa8d04ccf95a11760e641fb51a0b7ceab5d1949323ed20b6f517713d85', '2026-08-03 01:58:17', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:17'),
(260, 4, 'annuel', 'Rapport annuel Exercice 2003 BERNABE CI', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_bernabe_ci_-_exercice_2003.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20090530_-_ra_-_bernabe_ci_-_exercice_2003.pdf', 811196, 'a5e82892048223897493a046f18112e8762932925c258f8ff42f3a399bbfe1fc', '2026-08-03 01:58:19', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:19'),
(261, 4, 'annuel', 'Rapport annuel Exercice 1998 BERNABE CI', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_bernabe_ci_-_exercice_1998.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20090530_-_ra_-_bernabe_ci_-_exercice_1998.pdf', 1016811, '286a02a260926cf1586ea3729f6bd2a3bd0ca862fe8bbdba4b284c4592309c18', '2026-08-03 01:58:18', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:18'),
(262, 4, 'annuel', 'Rapport annuel Exercice 1999 BERNABE CI', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_bernabe_ci_-_exercice_1999.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20090530_-_ra_-_bernabe_ci_-_exercice_1999.pdf', 1157218, '840a5ee90212c44611d730955f532f60fb358a56fca8148b0633c69017df01a0', '2026-08-03 01:58:18', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:18'),
(263, 4, 'annuel', 'Rapport annuel Exercice 2000 BERNABE CI', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_bernabe_ci_-_exercice_2000.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20090530_-_ra_-_bernabe_ci_-_exercice_2000.pdf', 993137, '9b31ea65bf7cf4b7d9b2ebe8212fe429e1a47c964dc91ed6f7cf6465f771e088', '2026-08-03 01:58:18', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:18'),
(264, 4, 'annuel', 'Rapport annuel Exercice 2001 BERNABE CI', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_bernabe_ci_-_exercice_2001.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20090530_-_ra_-_bernabe_ci_-_exercice_2001.pdf', 1174907, '7693435fd27d8575259787fc9a45d0311f144d70136686b826aeb96f1e05db27', '2026-08-03 01:58:18', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:18'),
(265, 4, 'annuel', 'Rapport annuel Exercice 2002 BERNABE CI', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_bernabe_ci_-_exercice_2002.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20090530_-_ra_-_bernabe_ci_-_exercice_2002.pdf', 1950600, '66482f1ada626033e4c422a201f5701f7d1cb58f1418d60c0886e7c98c6d49b4', '2026-08-03 01:58:17', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:17'),
(266, 4, 'etats_financiers', 'Etats financiers provisoires - BERNABE CI - Exercice 2008', '2009-05-11', 'https://www.brvm.org/sites/default/files/20090511_-_efp_-_bernabe_ci_-_exercice_2008.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20090511_-_efp_-_bernabe_ci_-_exercice_2008.pdf', 26097, '9be166a39c1dc7edc5e12561d2ae03a3d68b34ef81f8d06346c7d3d392b8bb1e', '2026-08-03 01:58:19', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:19'),
(267, 4, 'semestriel', 'Rapport semestriel - BERNABE CI - Exercice 2007', '2007-10-25', 'https://www.brvm.org/sites/default/files/20071025_-_rs_-_bernabe_ci_-_exercice_2007.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20071025_-_rs_-_bernabe_ci_-_exercice_2007.pdf', 58743, '36793636382223d88b33d9d1739ca4764ba9cfa5563a287a4b7d2425fe7a68b8', '2026-08-03 01:58:19', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:19'),
(268, 4, 'semestriel', 'Rapport semestriel - BERNABE CI - Exercice 2006', '2006-09-29', 'https://www.brvm.org/sites/default/files/20060929_-_rs_-_bernabe_ci_-_exercice_2006.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20060929_-_rs_-_bernabe_ci_-_exercice_2006.pdf', 29217, 'da53449b6e60e9391766b25c64f18ba881d54e952a6dffc44bc6b611c340e164', '2026-08-03 01:58:20', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:20'),
(269, 4, 'semestriel', 'Rapport semestriel - BERNABE CI - Exercice 2005', '2005-10-24', 'https://www.brvm.org/sites/default/files/20051024_-_rs_-_bernabe_ci_-_exercice_2005.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20051024_-_rs_-_bernabe_ci_-_exercice_2005.pdf', 26808, '8e1828c78234e18d07c1b9cfcc41ebd9409483d2cf09f0b70113a6c9806c9019', '2026-08-03 01:58:20', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:20'),
(270, 4, 'semestriel', 'Rapport semestriel - BERNABE CI - Exercice 2004', '2004-09-21', 'https://www.brvm.org/sites/default/files/20040921_-_rs_-_bernabe_ci_-_exercice_2004.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BNBC/20040921_-_rs_-_bernabe_ci_-_exercice_2004.pdf', 54102, '281b67b3ad0ff94477f72b83cc491af97cddefdd1d9325bd5bea2a9aa79736cf', '2026-08-03 01:58:20', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:57:50', '2026-08-03 02:58:20'),
(271, 5, 'semestriel', 'BOA BN : Rapport d\'activités - 1er semestre 2026', '2026-07-23', 'https://www.brvm.org/sites/default/files/20260723_-_rapport_dactivites_-_1er_semestre_2026_-_boa_bn.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20260723_-_rapport_dactivites_-_1er_semestre_2026_-_boa_bn.pdf', 359649, '302b7a416e64d97d2ec03b0dcbad340bf77da44cc40b6eb603f8ac2cfea3af2a', '2026-08-03 01:58:32', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:32'),
(272, 5, 'trimestriel', 'BOA BENIN : Rapport d\'activités - 1er trimestre 2026', '2026-05-04', 'https://www.brvm.org/sites/default/files/20260504_-_rapport_dactivites_-_1er_trimestre_2026_-_boa_benin.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20260504_-_rapport_dactivites_-_1er_trimestre_2026_-_boa_benin.pdf', 248268, '455ba06857b5ff8bc2be935fb26a1a643a7f0352a26fe9bb01959101c8ec8a0c', '2026-08-03 01:58:32', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:32'),
(273, 5, 'etats_financiers', 'BOA BN : Etats financiers - Exercice 2025', '2026-04-15', 'https://www.brvm.org/sites/default/files/20260415_-_etats_financiers_-_exercice_2025_-_boa_bn.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20260415_-_etats_financiers_-_exercice_2025_-_boa_bn.pdf', 768934, '0891d841e4e472ee336e9ac56923227160a2afa44814e3002d37e6db51f16528', '2026-08-03 01:58:32', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:32'),
(274, 5, 'trimestriel', 'BOA BENIN : Rapport d\'activités - 3ème trimestre 2025', '2025-12-05', 'https://www.brvm.org/sites/default/files/20251205_-_rapport_dactivites_-_3eme_trimestre_2025_-_boa_bn.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20251205_-_rapport_dactivites_-_3eme_trimestre_2025_-_boa_bn.pdf', 226399, 'a12dd5f0fa356180b1f185a6d40be27c433fb6a5cd6f5680108b057316803605', '2026-08-03 01:58:33', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:33'),
(275, 5, 'semestriel', 'BOA BN  : Rapport d\'activités - 1er semestre 2025', '2025-10-29', 'https://www.brvm.org/sites/default/files/20251029_-_rapport_dactivites_-_1er_semestre_2025_-_boa_bn.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20251029_-_rapport_dactivites_-_1er_semestre_2025_-_boa_bn.pdf', 343297, 'afe3434586f2f73911d09a8fa392ae1f09f80cd0af7c088452373603523869e5', '2026-08-03 01:58:33', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:33'),
(276, 5, 'trimestriel', 'BOA BENIN : Rapport d\'activités du 1er trimestre 2025', '2025-05-12', 'https://www.brvm.org/sites/default/files/20250512_-_rapport_dactivites_-_1er_trimestre_2025_-_boa_benin.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20250512_-_rapport_dactivites_-_1er_trimestre_2025_-_boa_benin.pdf', 249549, 'c82a98dbdeefdece4d5690a22bf2535eb0c4051a59000d7818769c42f08a2058', '2026-08-03 01:58:33', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:34'),
(277, 5, 'etats_financiers', 'BOA BENIN : Etats financiers certifiés par les Commissaires Aux Comptes - Exercice 2024', '2025-04-22', 'https://www.brvm.org/sites/default/files/20250422_-_etats_financiers_certifies_par_les_commissaires_aux_comptes_-_exercice_2024_-_boa_benin.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20250422_-_etats_financiers_certifies_par_les_commissaires_aux_comptes_-_exercice_2024_-_boa_benin.pdf', 934340, '4059fac30d58ea7fe736827e9fe9396711d17ceab7aa165293bf34356b26aaa7', '2026-08-03 01:58:34', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:34'),
(278, 5, 'trimestriel', 'BOA BENIN : Rapport d\'activités - 3ème trimestre 2024', '2024-12-03', 'https://www.brvm.org/sites/default/files/20241203_-_rapport_dactivites_-_3eme_trimestre_2024_-_boa_bn.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20241203_-_rapport_dactivites_-_3eme_trimestre_2024_-_boa_bn.pdf', 185179, 'da41b01e60f482fa983e9ec3f767550d9e1bb4d4c3874fa35cd1d8bea3615650', '2026-08-03 01:58:34', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:34'),
(279, 5, 'semestriel', 'BOA BENIN : Rapport d\'activités certifié par les Commissaires Aux Comptes - 1er semestre 2024', '2024-11-25', 'https://www.brvm.org/sites/default/files/20241125_-_rapport_dactivites_certifie_par_les_cacs_-_1er_semestre_2024_-_boa_bn.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20241125_-_rapport_dactivites_certifie_par_les_cacs_-_1er_semestre_2024_-_boa_bn.pdf', 263162, '141f0f530d2b0851e9333f9641ac66913156b0564d973fc7b9ac78c504a1c6e1', '2026-08-03 01:58:34', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:34'),
(280, 5, 'trimestriel', 'BOA BENIN  : Rapport d\'activités du1er trimestre 2024', '2024-06-12', 'https://www.brvm.org/sites/default/files/20240612_-_rapport_dactivites_-_1er_trimestre_2024_-_boa_benin.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20240612_-_rapport_dactivites_-_1er_trimestre_2024_-_boa_benin.pdf', 200173, '04350fc6bf79338ee7e585945f311d4d54a7c635bc7f7cd908223d2f329df293', '2026-08-03 01:58:35', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:35'),
(281, 5, 'etats_financiers', 'BOA BENIN : Etats financiers - Exercice 2023', '2024-04-03', 'https://www.brvm.org/sites/default/files/20240403_-_etats_financiers_-_exercice_2023_-_boa_benin.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20240403_-_etats_financiers_-_exercice_2023_-_boa_benin.pdf', 206131, '670da39f89166fb9db162654e50437e8902acd9b7129fc0267a0a09d802e3a07', '2026-08-03 01:58:35', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:35'),
(282, 5, 'trimestriel', 'BOA BN : Rapport d\'activités - 3ème trimestre 2023', '2023-11-24', 'https://www.brvm.org/sites/default/files/20231124_-_rapport_dactivites_-_3eme_trimestre_2023_-_boa_bn.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20231124_-_rapport_dactivites_-_3eme_trimestre_2023_-_boa_bn.pdf', 198299, '9a02245cbcf8ccb9a4359b329fdb77a386ecea479d6eda37fc5cca5fe7f8dfe6', '2026-08-03 01:58:35', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:35'),
(283, 5, 'semestriel', 'BOA BENIN : Rapport d\'activités du 1er Semestre 2023', '2023-11-03', 'https://www.brvm.org/sites/default/files/20231103_-_rapport_dactivites_-_1er_semestre_2023_-_boa_bn.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20231103_-_rapport_dactivites_-_1er_semestre_2023_-_boa_bn.pdf', 257105, '9e7972b70aedde5330f970d7cb224213742b406244c9b9bd238e36740328c511', '2026-08-03 01:58:36', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:36'),
(284, 5, 'trimestriel', 'BOA BENIN : Rapport d\'activité du 1er trimestre 2023', '2023-05-03', 'https://www.brvm.org/sites/default/files/20230503_-_rapport_dactivite_-_1er_trimestre_2023_-_boa_benin.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20230503_-_rapport_dactivite_-_1er_trimestre_2023_-_boa_benin.pdf', 194129, '80e94c82a7375caf2261cf46685a296338bcc4ebf73ecced775f992506d7c073', '2026-08-03 01:58:36', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:36'),
(285, 5, 'etats_financiers', 'BOA BENIN : Etats financiers 2022', NULL, 'https://www.brvm.org/sites/default/files/boa_bn_bilan_hors_bilan_et_compte_de_resultat_2022.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(286, 5, 'trimestriel', 'BOA BENIN : Rapport d\'activité du 3ème trimestre 2022', '2022-10-28', 'https://www.brvm.org/sites/default/files/20221028_-_rapport_dactivite_-_3eme_trimestre_2022_-_boa_bn.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20221028_-_rapport_dactivite_-_3eme_trimestre_2022_-_boa_bn.pdf', 188557, '5f2d20daba3ebd57e9d8f10b02017c832540bdac9fd74d7d75522ac5e43d174f', '2026-08-03 01:58:36', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:36'),
(287, 5, 'semestriel', 'BOA BENIN : Rapport d\'activités du 1er semestre 2022', '2022-10-04', 'https://www.brvm.org/sites/default/files/20221004_-_rapport_dactivite_du_1er_semestre_2022_-_boa_bn.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20221004_-_rapport_dactivite_du_1er_semestre_2022_-_boa_bn.pdf', 224585, '38eb4556e35b7113fa5f258fb380715f42123221dba796333e77a5f7a789de18', '2026-08-03 01:58:37', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:37'),
(288, 5, 'trimestriel', 'BOA BENIN : Rapport d\'activité 1er trimestre 2022', '2022-04-29', 'https://www.brvm.org/sites/default/files/20220429_-_rapport_dactivite_-_1er_trimestre_2022_-_boa_benin.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20220429_-_rapport_dactivite_-_1er_trimestre_2022_-_boa_benin.pdf', 194324, 'c57add866c1e51049cffb6ada554a703efe649767019d934dd06735fd246bcde', '2026-08-03 01:58:37', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:37'),
(289, 5, 'attestation_cac', 'BOA BENIN : Rapport des Commissaires aux Comptes sur les états financiers annuels 2021', '2022-04-20', 'https://www.brvm.org/sites/default/files/20220420_-_rapport_des_commissaires_aux_comptes_sur_les_etats_financiers_annuels_-_boa_benin.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20220420_-_rapport_des_commissaires_aux_comptes_sur_les_etats_financiers_annuels_-_boa_benin.pdf', 2816203, 'ba2f3c6c850c85ff64d0f2b3aa0274122f203f377059dffd104fb1e62312d60a', '2026-08-03 01:58:38', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:38'),
(290, 5, 'attestation_cac', 'BOA BENIN  : Rapport Spécial des Commissaires aux Comptes sur les Conventions Réglementées', '2022-04-20', 'https://www.brvm.org/sites/default/files/20220420_-_rapport_special_des_commissaires_aux_comptes_sur_les_conventions_reglementees_-_boa_benin.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20220420_-_rapport_special_des_commissaires_aux_comptes_sur_les_conventions_reglementees_-_boa_benin.pdf', 1069979, 'c2664c003d8ac56e8586ea8b77360a3b7a709f816acc30033c078c894c851639', '2026-08-03 01:58:37', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:37'),
(291, 5, 'etats_financiers', 'BOA BENIN : États financiers exercice 2021', '2022-03-22', 'https://www.brvm.org/sites/default/files/20220322_-_etats_financiers_exercice_2021_-_boa_bn.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20220322_-_etats_financiers_exercice_2021_-_boa_bn.pdf', 316824, '68ce47427e5e7b79bd564edd73b6a87daf4a1d4b0bd7776c1141fd72a8fa7708', '2026-08-03 01:58:38', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:38'),
(292, 5, 'trimestriel', 'BOA BENIN : Rapport d\'activité au 3ème trimestre 2021', '2021-12-06', 'https://www.brvm.org/sites/default/files/20211206_-_avis_ndeg232_brvmdg_-_premiere_cotation_-_tpci_590_2021-2031_1.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20211206_-_avis_ndeg232_brvmdg_-_premiere_cotation_-_tpci_590_2021-2031_1.pdf', 103228, 'd256b93d45984b9407e7b68b3698b4fd74c497d887829d5bbc5f753c5a6511a2', '2026-08-03 01:58:38', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:38'),
(293, 5, 'semestriel', 'BOA BENIN : Rapport d\'activité du 1er semestre 2021', '2021-10-04', 'https://www.brvm.org/sites/default/files/20211004_-_rapport_dactivite_1er_semestre_2021_-_boa_bn.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20211004_-_rapport_dactivite_1er_semestre_2021_-_boa_bn.pdf', 240001, '233c447cb4822341c203c60570b17e187507260a73afa40bdb34be65a1203e6b', '2026-08-03 01:58:39', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:39'),
(294, 5, 'semestriel', 'BOA BENIN : Rapport d\'examen limité des Commissaires Aux Comptes sur les états financiers semestriels', '2021-10-04', 'https://www.brvm.org/sites/default/files/20211004_-_rapport_examen_limite_des_cac_sur_les_etats_financiers_semestriels_-_boa_bn.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20211004_-_rapport_examen_limite_des_cac_sur_les_etats_financiers_semestriels_-_boa_bn.pdf', 562467, '01a46d6543120b83b3b86dc37ea60ebedbabaabe4fea986eea1ac70f80777cfe', '2026-08-03 01:58:39', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:39'),
(295, 5, 'trimestriel', 'BOA BENIN : Rapport d\'activité au 1er trimestre 2021', '2021-04-26', 'https://www.brvm.org/sites/default/files/20210426_-_rapport_dactivite_au_1er_trimestre_2021_-_boa_bn.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20210426_-_rapport_dactivite_au_1er_trimestre_2021_-_boa_bn.pdf', 185326, '0c945f982d15eeeb6766e2576a8d173f0d70ca29484efdfe7206c8f504df0ebf', '2026-08-03 01:58:39', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:39'),
(296, 5, 'etats_financiers', 'BANK OF AFRICA BENIN : Etats financiers et rapport des Commissaires Aux Comptes - Exercice 2020', '2021-04-21', 'https://www.brvm.org/sites/default/files/20210421_-_etats_financiers_et_rapport_des_commissaires_aux_comptes_-_exercice_2020_-_boa_benin.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20210421_-_etats_financiers_et_rapport_des_commissaires_aux_comptes_-_exercice_2020_-_boa_benin.pdf', 593497, 'aee66a842b7bff395a8c72035f2b6d354ccdd6ba53c510a8b0cea8f60c745b69', '2026-08-03 01:58:39', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:39'),
(297, 5, 'attestation_cac', 'BANK OF AFRICA BENIN  : Rapport spécial des Commissaires Aux Comptes sur les conventions Règlementées - Exercice 2020', NULL, 'https://www.brvm.org/sites/default/files/rapport_special_des_commissaires_aux_comptes_sur_les_conventions_reglementees_-_exercice_2020_-_boa_benin.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(298, 5, 'trimestriel', 'BOA BENIN : Rapport d’activités du 3ème trimestre 2020', '2020-10-22', 'https://www.brvm.org/sites/default/files/20201022_-_rapport_dactivites_du_3eme_trimestre_2020_-_boa_bn.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20201022_-_rapport_dactivites_du_3eme_trimestre_2020_-_boa_bn.pdf', 205361, '9d05c06a8375c45b51019a6fef36e1b9815386056e1535970fa0fd511e33c698', '2026-08-03 01:58:40', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:40'),
(299, 5, 'etats_financiers', 'BOA BENIN : Etats financiers du 1er semestre 2020', '2020-09-29', 'https://www.brvm.org/sites/default/files/20200929_-_etats_financiers_1er_semestre_2020_-_boa_benin.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20200929_-_etats_financiers_1er_semestre_2020_-_boa_benin.pdf', 1481944, '2f1af3e6215cf0bfd6967d3fc85d5a12639745c97c39d04baf1aef80104ce7ee', '2026-08-03 01:58:41', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:41'),
(300, 5, 'semestriel', 'BOA BENIN : Rapport d\'activité du 1er semestre 2020', '2020-09-29', 'https://www.brvm.org/sites/default/files/20200929_-_rapport_dactivite_-_1er_semestre_2020_-_boa_benin.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20200929_-_rapport_dactivite_-_1er_semestre_2020_-_boa_benin.pdf', 630033, '36c400d30e35327fcbe643960786071e227c94939eab57f9883b1b252253b0c6', '2026-08-03 01:58:40', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:40'),
(301, 5, 'trimestriel', 'BANK OF AFRICA BENIN : Rapport d\'activité du 1er trimestre 2020', '2020-04-10', 'https://www.brvm.org/sites/default/files/20200410_-_rapport_dactivite_1er_trimestre_2020_-_bank_of_africa_bn.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20200410_-_rapport_dactivite_1er_trimestre_2020_-_bank_of_africa_bn.pdf', 79150, '12ab8fae97b15075ff55b89b736de9ac9ae23442c73a51eee9b550006760959b', '2026-08-03 01:58:41', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:41'),
(302, 5, 'attestation_cac', 'BANK OF AFRICA BENIN : Rapport des Commissaires aux Comptes sur les états financiers annuels 2019', NULL, 'https://www.brvm.org/sites/default/files/rapport_des_cac_sur_les_etats_financiers_annuels_2019_-_boa_benin.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(303, 5, 'attestation_cac', 'BANK OF AFRICA BENIN : Rapport des Commissaires aux Comptes sur les conventions règlementées', NULL, 'https://www.brvm.org/sites/default/files/rapport_des_cac_sur_les_conventions_reglementees.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(304, 5, 'attestation_cac', 'BANK OF AFRICA BENIN : Rapport des Commissaires aux Comptes au Conseil d’Administration', NULL, 'https://www.brvm.org/sites/default/files/rapport_des_cac_au_conseil_dadministration_-_boa_benin.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(305, 5, 'trimestriel', 'BOA BENIN : Rapport d\'activités du 3e  trimestre 2019', '2019-11-19', 'https://www.brvm.org/sites/default/files/20191119_-_rapport_dactivite_au_3eme_trimestre_2019_-_boa_bn.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20191119_-_rapport_dactivite_au_3eme_trimestre_2019_-_boa_bn.pdf', 90217, 'f812a9c3e0491b2d48a3b4b7496cbfde987b599a59506cbf9ca1eb55e3894e96', '2026-08-03 01:58:41', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:41'),
(306, 5, 'semestriel', 'BOA BENIN : Rapport d’activité du 1er semestre 2019', NULL, 'https://www.brvm.org/sites/default/files/boa_benin_rapport_dactivite_semestriel_juin_2019.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(307, 5, 'semestriel', 'BOA BENIN : Attestation des Commissaires aux comptes du rapport d\'activités du 1er semestre 2019', NULL, 'https://www.brvm.org/sites/default/files/boa-benin_rapport_cac_juin_2019.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(308, 5, 'annuel', 'BOA BENIN : Rapport Annuel 2018', NULL, 'https://www.brvm.org/sites/default/files/rapport_annuel_2018_boa_benin.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(309, 5, 'trimestriel', 'BOA BENIN : Rapport d’activités du 1er trimestre 2019', NULL, 'https://www.brvm.org/sites/default/files/boa-benin_rapport_dactivites_1_t_2019.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(310, 5, 'etats_financiers', 'BOA BENIN : Etats financiers Exercice 2018', '2019-04-04', 'https://www.brvm.org/sites/default/files/20190404_-_etats_financiers_exercice_2018_-_boa_bn.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20190404_-_etats_financiers_exercice_2018_-_boa_bn.pdf', 108011, 'a8b23524bd4075dd399993fde2097d86a71dfa6acfb3d5e5a3cc59bbf765fcea', '2026-08-03 01:58:41', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:42'),
(311, 5, 'trimestriel', 'BOA BENIN : Rapport d’activités du 3e trimestre 2018', '2018-12-26', 'https://www.brvm.org/sites/default/files/20181226_-_boa-bn_-_rapport_dactivite_3e_trimestre.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20181226_-_boa-bn_-_rapport_dactivite_3e_trimestre.pdf', 272365, 'bebafbcb92fdb9fce86931fb1ebe1c9cd3f487d53134b5e1ac63d2ee0325dc18', '2026-08-03 01:58:42', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:42'),
(312, 5, 'semestriel', 'BOA BENIN : Rapport d’activités du 1er  semestre 2018', '2018-11-02', 'https://www.brvm.org/sites/default/files/20181102_-_rapport_dactivites_du_1er_semestre_2018_-_boab.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20181102_-_rapport_dactivites_du_1er_semestre_2018_-_boab.pdf', 110612, '3a790cfc2789de9ed8c23f7fac436939e632583dc6947efdaa738ac611d04d3b', '2026-08-03 01:58:42', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:42'),
(313, 5, 'semestriel', 'BOA BENIN : Attestation des Commissaires aux comptes du rapport d\'activités du 1er semestre 2018', '2018-11-02', 'https://www.brvm.org/sites/default/files/20181102_-_attestation_des_commissaires_aux_comptes_du_rapport_dactivites_du_1er_semestre_2018-boab.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20181102_-_attestation_des_commissaires_aux_comptes_du_rapport_dactivites_du_1er_semestre_2018-boab.pdf', 186499, 'ef3823e469b659351723edb1cfb21ace13aead9a6ed67c795e258f470f712a32', '2026-08-03 01:58:42', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:42'),
(314, 5, 'trimestriel', 'BOA BENIN : Rapport d’activités du 1er trimestre 2018', NULL, 'https://www.brvm.org/sites/default/files/20180530-_boab_-_rapport_dactivites_au_1er_trimestre_2018.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(315, 5, 'etats_financiers', 'BOA BENIN : Etats financiers – Exercice 2017', NULL, 'https://www.brvm.org/sites/default/files/boa-benin_etats_financiers_au_31_dec_2017.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(316, 5, 'etats_financiers', 'BOA BENIN : Etats financiers Exercice 2017', NULL, 'https://www.brvm.org/sites/default/files/boa-benin_etats_fin_31_dec_2017_0.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(317, 5, 'semestriel', 'Rapport activité 1er semestre 2017 - BANK OF AFRICA BN', NULL, 'https://www.brvm.org/sites/default/files/fiche_bj_0.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(318, 5, 'semestriel', 'Rapport d\'activités 1er semestre 2017 et attestation des Commissaires Aux Comptes - BOA BENIN', '2017-10-17', 'https://www.brvm.org/sites/default/files/20171017_-_rapport_dactivites_1er_semestre_et_attestaion_des_cac_-_boa_benin_-_exercice_2017_0.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20171017_-_rapport_dactivites_1er_semestre_et_attestaion_des_cac_-_boa_benin_-_exercice_2017_0.pdf', 3209359, '000bf46ebcf7aa3cbf47201b0e28efd138603e22e41048dc57a16b962117bd60', '2026-08-03 01:58:43', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:43'),
(319, 5, 'trimestriel', 'Rapport d\'activité 1er Trimestre 2017 - BOA BENIN', NULL, 'https://www.brvm.org/sites/default/files/20170518-rapport_dactivite_1er_trimestre_2017_boa-benin.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(320, 5, 'etats_financiers', 'Etats Financiers approuvés Exercice2016 - BOA BENIN', NULL, 'https://www.brvm.org/sites/default/files/20170512-_etats_financiers_approuves_exercice2016-_boa_benin.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(321, 5, 'trimestriel', 'Rapport premier trimestre - BANK OF AFRICA BN - Exercice 2016', '2016-05-11', 'https://www.brvm.org/sites/default/files/20160511_-_rt1_-_bank_of_africa_bn_-_exercice_2016.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20160511_-_rt1_-_bank_of_africa_bn_-_exercice_2016.pdf', 1174712, '876fae07a10d82ffb2cb7c530576f8f3527999a4d5e46fce1eb3c70df8bd867c', '2026-08-03 01:58:43', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:43'),
(322, 5, 'etats_financiers', 'Etats financiers provisoires - BANK OF AFRICA BN - Exercice 2015', '2016-03-31', 'https://www.brvm.org/sites/default/files/20160331_-_efp_-_bank_of_africa_bn_-_exercice_2015.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20160331_-_efp_-_bank_of_africa_bn_-_exercice_2015.pdf', 2922639, 'e94fd4f3238bb6672565eb33856d1f02d01f995ec681bc0eda08a13fad51be51', '2026-08-03 01:58:44', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:44'),
(323, 5, 'annuel', 'Rapport annuel Exercice 2014 BANK OF AFRICA BN', '2016-02-25', 'https://www.brvm.org/sites/default/files/20160225_-_ra_-_bank_of_africa_bn_-_exercice_2014.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20160225_-_ra_-_bank_of_africa_bn_-_exercice_2014.pdf', 5516688, 'd07cc48be5a2afd686e14e31ef93b75e0f588cc361ce56bede601933c25ee5ed', '2026-08-03 01:58:44', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:44');
INSERT INTO `company_reports` (`id`, `company_id`, `report_type`, `title`, `publish_date`, `file_url`, `local_path`, `file_size`, `file_hash`, `downloaded_at`, `text_extracted`, `extraction_method`, `extraction_error`, `created_at`, `updated_at`) VALUES
(324, 5, 'semestriel', 'Rapport semestriel - BANK OF AFRICA BN - Exercice 2015', '2015-09-02', 'https://www.brvm.org/sites/default/files/20150902_-_rs_-_bank_of_africa_bn_-_exercice_2015.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20150902_-_rs_-_bank_of_africa_bn_-_exercice_2015.pdf', 1504409, '43a9bd4dd4fcef8d963d15caf0320ac047a572168ce654e2b8cb4c28f5ec25f3', '2026-08-03 01:58:45', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:45'),
(325, 5, 'trimestriel', 'Rapport troisième trimestre - BANK OF AFRICA BN - Exercice 2014', '2014-12-16', 'https://www.brvm.org/sites/default/files/20141216_-_rt3_-_bank_of_africa_bn_-_exercice_2014.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20141216_-_rt3_-_bank_of_africa_bn_-_exercice_2014.pdf', 187667, '9a136d6691860187ec0be12c3be584e47904cdc2606b00a2efb1b0e1789963a5', '2026-08-03 01:58:45', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:45'),
(326, 5, 'semestriel', 'Rapport semestriel - BANK OF AFRICA BN - Exercice 2014', '2014-09-18', 'https://www.brvm.org/sites/default/files/20140918_-_rs_-_bank_of_africa_bn_-_exercice_2014.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20140918_-_rs_-_bank_of_africa_bn_-_exercice_2014.pdf', 397713, '26ddc64dea99d94d27542701b3a2c8b200a8efc0dc031fb6bf0bea88aaf04886', '2026-08-03 01:58:45', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:45'),
(327, 5, 'annuel', 'Rapport annuel Exercice 2013 BANK OF AFRICA BN', '2014-08-25', 'https://www.brvm.org/sites/default/files/20140825_-_ra_-_bank_of_africa_bn_-_exercice_2013.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20140825_-_ra_-_bank_of_africa_bn_-_exercice_2013.pdf', 2515156, '5065e6635cb14f9385071f9c9eb492b308a7124f87655af5bfbc637a36448efc', '2026-08-03 01:58:46', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:46'),
(328, 5, 'trimestriel', 'Rapport premier trimestre - BANK OF AFRICA BN - Exercice 2014', '2014-06-24', 'https://www.brvm.org/sites/default/files/20140624_-_rt1_-_bank_of_africa_bn_-_exercice_2014.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20140624_-_rt1_-_bank_of_africa_bn_-_exercice_2014.pdf', 145895, 'c4778481a2655dba946da6efc888266ce52a6ff22ab3a200c11dbd23e2b4553c', '2026-08-03 01:58:46', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:46'),
(329, 5, 'etats_financiers', 'Etats financiers provisoires - BANK OF AFRICA BN - Exercice 2013', '2014-04-16', 'https://www.brvm.org/sites/default/files/20140416_-_efp_-_bank_of_africa_bn_-_exercice_2013.pdf', '/home/brimmobi/public_html/brvmapi/storage/reports/BOAB/20140416_-_efp_-_bank_of_africa_bn_-_exercice_2013.pdf', 51091, '76471ab9b8f140d6499b1999d90eaa8af64bc0c320bcabb0cffb6a528000a668', '2026-08-03 01:58:46', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 02:58:30', '2026-08-03 02:58:46'),
(330, 5, 'annuel', 'Rapport annuel Exercice 2011 BANK OF AFRICA BN', '2013-09-23', 'https://www.brvm.org/sites/default/files/20130923_-_ra_-_bank_of_africa_bn_-_exercice_2011.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(331, 5, 'annuel', 'Rapport annuel Exercice 2012 BANK OF AFRICA BN', '2013-09-20', 'https://www.brvm.org/sites/default/files/20130920_-_ra_-_bank_of_africa_bn_-_exercice_2012.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(332, 5, 'semestriel', 'Rapport semestriel - BANK OF AFRICA BN - Exercice 2013', '2013-08-30', 'https://www.brvm.org/sites/default/files/20130830_-_rs_-_bank_of_africa_bn_-_exercice_2013.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(333, 5, 'trimestriel', 'Rapport premier trimestre - BANK OF AFRICA BN - Exercice 2013', '2013-05-08', 'https://www.brvm.org/sites/default/files/20130508_-_rt1_-_bank_of_africa_bn_-_exercice_2013.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:30', '2026-08-03 02:58:30'),
(334, 5, 'etats_financiers', 'Etats financiers approuvés - BANK OF AFRICA BN - Exercice 2012', '2013-05-08', 'https://www.brvm.org/sites/default/files/20130508_-_efa_-_bank_of_africa_bn_-_exercice_2012.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(335, 5, 'semestriel', 'Rapport semestriel - BANK OF AFRICA BN - Exercice 2012', '2012-10-31', 'https://www.brvm.org/sites/default/files/20121031_-_rs_-_bank_of_africa_bn_-_exercice_2012.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(336, 5, 'trimestriel', 'Rapport premier trimestre - BANK OF AFRICA BN - Exercice 2012', '2012-04-30', 'https://www.brvm.org/sites/default/files/20120430_-_rt1_-_bank_of_africa_bn_-_exercice_2012.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(337, 5, 'autre', 'Commentaires sur activités annuelles - BANK OF AFRICA BN - Exercice 2011', '2012-04-04', 'https://www.brvm.org/sites/default/files/20120404_-_caa_-_bank_of_africa_bn_-_exercice_2011.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(338, 5, 'etats_financiers', 'Etats financiers provisoires - BANK OF AFRICA BN - Exercice 2011', '2012-04-04', 'https://www.brvm.org/sites/default/files/20120404_-_efp_-_bank_of_africa_bn_-_exercice_2011.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(339, 5, 'trimestriel', 'Rapport troisième trimestre - BANK OF AFRICA BN - Exercice 2011', '2011-11-18', 'https://www.brvm.org/sites/default/files/20111118_-_rt3_-_bank_of_africa_bn_-_exercice_2011.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(340, 5, 'annuel', 'Rapport annuel Exercice 2010 BANK OF AFRICA BN', '2011-10-04', 'https://www.brvm.org/sites/default/files/20111004_-_ra_-_bank_of_africa_bn_-_exercice_2010.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(341, 5, 'semestriel', 'Rapport semestriel - BANK OF AFRICA BN - Exercice 2011', '2011-09-08', 'https://www.brvm.org/sites/default/files/20110908_-_rs_-_bank_of_africa_bn_-_exercice_2011.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(342, 5, 'etats_financiers', 'Etats financiers approuvés - BANK OF AFRICA BN - Exercice 2010', '2011-06-23', 'https://www.brvm.org/sites/default/files/20110623_-_efa_-_bank_of_africa_bn_-_exercice_2010.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(343, 5, 'trimestriel', 'Rapport premier trimestre - BANK OF AFRICA BN - Exercice 2011', '2011-04-19', 'https://www.brvm.org/sites/default/files/20110419_-_rt1_-_bank_of_africa_bn_-_exercice_2011.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(344, 5, 'etats_financiers', 'Etats financiers approuvés - BANK OF AFRICA BN - Exercice 2009', '2010-09-29', 'https://www.brvm.org/sites/default/files/20100929_-_efa_-_bank_of_africa_bn_-_exercice_2009.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(345, 5, 'semestriel', 'Rapport semestriel - BANK OF AFRICA BN - Exercice 2010', '2010-09-28', 'https://www.brvm.org/sites/default/files/20100928_-_rs_-_bank_of_africa_bn_-_exercice_2010.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(346, 5, 'annuel', 'Rapport annuel Exercice 2009 BANK OF AFRICA BN', '2010-07-19', 'https://www.brvm.org/sites/default/files/20100719_-_ra_-_bank_of_africa_bn_-_exercice_2009.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(347, 5, 'etats_financiers', 'Etats financiers approuvés - BANK OF AFRICA BN - Exercice 2009', '2010-04-19', 'https://www.brvm.org/sites/default/files/20100419_-_efa_-_bank_of_africa_bn_-_exercice_2009.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(348, 5, 'semestriel', 'Rapport semestriel - BANK OF AFRICA BN - Exercice 2009', '2009-10-20', 'https://www.brvm.org/sites/default/files/20091020_-_rs_-_bank_of_africa_bn_-_exercice_2009.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(349, 5, 'annuel', 'Rapport annuel Exercice 2005 BANK OF AFRICA BN', '2009-09-24', 'https://www.brvm.org/sites/default/files/20090924_-_ra_-_bank_of_africa_bn_-_exercice_2005.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(350, 5, 'annuel', 'Rapport annuel Exercice 2008 BANK OF AFRICA BN', '2009-07-18', 'https://www.brvm.org/sites/default/files/20090718_-_ra_-_bank_of_africa_bn_-_exercice_2008.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(351, 5, 'etats_financiers', 'Etats financiers provisoires - BANK OF AFRICA BN - Exercice 2003', '2009-08-10', 'https://www.brvm.org/sites/default/files/20090810_-_efp_-_bank_of_africa_bn_-_exercice_2003.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(352, 5, 'etats_financiers', 'Etats financiers provisoires - BANK OF AFRICA BN - Exercice 2004', '2009-08-10', 'https://www.brvm.org/sites/default/files/20090810_-_efp_-_bank_of_africa_bn_-_exercice_2004.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(353, 5, 'etats_financiers', 'Etats financiers provisoires - BANK OF AFRICA BN - Exercice 2005', '2009-08-10', 'https://www.brvm.org/sites/default/files/20090810_-_efp_-_bank_of_africa_bn_-_exercice_2005.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(354, 5, 'etats_financiers', 'Etats financiers provisoires - BANK OF AFRICA BN - Exercice 2006', '2009-08-10', 'https://www.brvm.org/sites/default/files/20090810_-_efp_-_bank_of_africa_bn_-_exercice_2006.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(355, 5, 'etats_financiers', 'Etats financiers provisoires - BANK OF AFRICA BN - Exercice 2007', '2009-08-10', 'https://www.brvm.org/sites/default/files/20090810_-_efp_-_bank_of_africa_bn_-_exercice_2007.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(356, 5, 'annuel', 'Rapport annuel Exercice 2004 BANK OF AFRICA BN', '2009-08-10', 'https://www.brvm.org/sites/default/files/20090810_-_ra_-_bank_of_africa_bn_-_exercice_2004.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(357, 5, 'annuel', 'Rapport annuel Exercice 2007 BANK OF AFRICA BN', '2009-11-06', 'https://www.brvm.org/sites/default/files/20091106_-_ra_-_bank_of_africa_bn_-_exercice_2007.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(358, 5, 'annuel', 'Rapport annuel Exercice 1998 BANK OF AFRICA BN', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_bank_of_africa_bn_-_exercice_1998.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(359, 5, 'annuel', 'Rapport annuel Exercice 1999 BANK OF AFRICA BN', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_bank_of_africa_bn_-_exercice_1999.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(360, 5, 'annuel', 'Rapport annuel Exercice 2000 BANK OF AFRICA BN', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_bank_of_africa_bn_-_exercice_2000.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(361, 5, 'annuel', 'Rapport annuel Exercice 2001 BANK OF AFRICA BN', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_bank_of_africa_bn_-_exercice_2001.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(362, 5, 'annuel', 'Rapport annuel Exercice 2002 BANK OF AFRICA BN', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_bank_of_africa_bn_-_exercice_2002.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(363, 5, 'annuel', 'Rapport annuel Exercice 2003 BANK OF AFRICA BN', '2009-05-30', 'https://www.brvm.org/sites/default/files/20090530_-_ra_-_bank_of_africa_bn_-_exercice_2003.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31'),
(364, 5, 'etats_financiers', 'Etats financiers provisoires - BANK OF AFRICA BN - Exercice 2008', '2009-03-18', 'https://www.brvm.org/sites/default/files/20090318_-_efp_-_bank_of_africa_bn_-_exercice_2008.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 02:58:31', '2026-08-03 02:58:31');

-- --------------------------------------------------------

--
-- Structure de la table `company_report_analyses`
--

CREATE TABLE `company_report_analyses` (
  `id` bigint(20) NOT NULL,
  `report_id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `provider` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'gemini' COMMENT 'anthropic, gemini...',
  `model` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `market_context_date` date DEFAULT NULL COMMENT 'trading_date des cours/indicateurs utilisés comme contexte',
  `summary` text COLLATE utf8mb4_unicode_ci COMMENT 'résumé exécutif court, pour affichage/listage rapide',
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin COMMENT 'analyse complète structurée (financials, SWOT, risques, thèse, glossaire...)',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success' COMMENT 'success|failed',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `input_char_count` int(11) DEFAULT NULL,
  `raw_response` longtext COLLATE utf8mb4_unicode_ci COMMENT 'réponse brute du fournisseur IA, pour audit/debug',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `company_report_comparisons`
--

CREATE TABLE `company_report_comparisons` (
  `id` bigint(20) NOT NULL,
  `request_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'sha256(company_ids triés + période + report_type)',
  `company_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'entreprises incluses dans la comparaison',
  `report_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin COMMENT 'rapports effectivement inclus, calculé à l’appel',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `report_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `computed_date` date NOT NULL COMMENT 'date du calcul — cache "une fois par jour"',
  `summary` text COLLATE utf8mb4_unicode_ci,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin COMMENT 'analyse comparative complète + chart_data',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `input_char_count` int(11) DEFAULT NULL,
  `raw_response` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `company_report_contents`
--

CREATE TABLE `company_report_contents` (
  `report_id` bigint(20) NOT NULL,
  `extracted_text` longtext COLLATE utf8mb4_unicode_ci,
  `formatted_markdown` longtext COLLATE utf8mb4_unicode_ci COMMENT 'Texte brut restructuré en markdown (tableaux) par IA, voir class/ReportMarkdownFormatterService.php',
  `markdown_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'processing|success|failed',
  `markdown_error` text COLLATE utf8mb4_unicode_ci,
  `markdown_provider` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `markdown_model` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `markdown_updated_at` timestamp NULL DEFAULT NULL,
  `char_count` int(11) DEFAULT NULL,
  `extracted_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `countries`
--

CREATE TABLE `countries` (
  `id` int(11) NOT NULL,
  `code` char(2) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Code ISO 2 lettres',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency_code` char(3) COLLATE utf8mb4_unicode_ci DEFAULT 'XOF' COMMENT 'Code devise ISO',
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `countries`
--

INSERT INTO `countries` (`id`, `code`, `name`, `currency_code`, `active`, `created_at`, `updated_at`) VALUES
(1, 'CI', 'Côte d\'Ivoire', 'XOF', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(2, 'SN', 'Sénégal', 'XOF', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(3, 'BF', 'Burkina Faso', 'XOF', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(4, 'BJ', 'Bénin', 'XOF', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(5, 'TG', 'Togo', 'XOF', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(6, 'NE', 'Niger', 'XOF', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(7, 'ML', 'Mali', 'XOF', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(8, 'GW', 'Guinée-Bissau', 'XOF', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32');

-- --------------------------------------------------------

--
-- Structure de la table `index_composition`
--

CREATE TABLE `index_composition` (
  `id` int(11) NOT NULL,
  `index_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `weight` decimal(10,6) DEFAULT NULL COMMENT 'Poids en %',
  `entry_date` date DEFAULT NULL,
  `exit_date` date DEFAULT NULL,
  `active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `index_values`
--

CREATE TABLE `index_values` (
  `id` bigint(20) NOT NULL,
  `index_id` int(11) NOT NULL,
  `trading_date` date NOT NULL,
  `open_value` decimal(15,2) DEFAULT NULL,
  `close_value` decimal(15,2) NOT NULL,
  `high_value` decimal(15,2) DEFAULT NULL,
  `low_value` decimal(15,2) DEFAULT NULL,
  `variation_percent` decimal(10,4) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `index_values`
--

INSERT INTO `index_values` (`id`, `index_id`, `trading_date`, `open_value`, `close_value`, `high_value`, `low_value`, `variation_percent`, `created_at`) VALUES
(1, 1, '2026-08-03', NULL, '231.45', NULL, NULL, '0.6000', '2026-08-03 01:06:56'),
(2, 2, '2026-08-03', NULL, '485.43', NULL, NULL, '0.5800', '2026-08-03 01:06:56'),
(3, 3, '2026-08-03', NULL, '176.88', NULL, NULL, '-0.3800', '2026-08-03 01:06:56'),
(4, 4, '2026-08-03', NULL, '370.92', NULL, NULL, '1.5500', '2026-08-03 01:06:56');

-- --------------------------------------------------------

--
-- Structure de la table `intraday_quotes`
--

CREATE TABLE `intraday_quotes` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `quote_datetime` datetime NOT NULL,
  `price` decimal(15,2) NOT NULL,
  `volume` bigint(20) DEFAULT '0',
  `variation_percent` decimal(10,4) DEFAULT NULL COMMENT 'Variation en % depuis la clôture précédente',
  `bid_price` decimal(15,2) DEFAULT NULL COMMENT 'Meilleure offre achat',
  `ask_price` decimal(15,2) DEFAULT NULL COMMENT 'Meilleure offre vente',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `intraday_quotes`
--

INSERT INTO `intraday_quotes` (`id`, `company_id`, `quote_datetime`, `price`, `volume`, `variation_percent`, `bid_price`, `ask_price`, `created_at`) VALUES
(1, 1, '2026-08-03 01:06:54', '3000.00', 2550, '0.0000', NULL, NULL, '2026-08-03 01:06:54'),
(2, 2, '2026-08-03 01:06:54', '7585.00', 11408, '-0.2000', NULL, NULL, '2026-08-03 01:06:54'),
(3, 3, '2026-08-03 01:06:54', '28670.00', 439, '-1.1400', NULL, NULL, '2026-08-03 01:06:54'),
(4, 4, '2026-08-03 01:06:54', '1890.00', 371, '0.5300', NULL, NULL, '2026-08-03 01:06:54'),
(5, 5, '2026-08-03 01:06:54', '8700.00', 2740, '0.0000', NULL, NULL, '2026-08-03 01:06:54'),
(6, 6, '2026-08-03 01:06:54', '7100.00', 6458, '0.0000', NULL, NULL, '2026-08-03 01:06:54'),
(7, 7, '2026-08-03 01:06:54', '10815.00', 5074, '0.1400', NULL, NULL, '2026-08-03 01:06:54'),
(8, 8, '2026-08-03 01:06:54', '5665.00', 5309, '0.0900', NULL, NULL, '2026-08-03 01:06:54'),
(9, 9, '2026-08-03 01:06:54', '5290.00', 5875, '0.0000', NULL, NULL, '2026-08-03 01:06:54'),
(10, 10, '2026-08-03 01:06:54', '7790.00', 3612, '1.1700', NULL, NULL, '2026-08-03 01:06:54'),
(11, 11, '2026-08-03 01:06:54', '3500.00', 10266, '0.0000', NULL, NULL, '2026-08-03 01:06:54'),
(12, 12, '2026-08-03 01:06:54', '28000.00', 1293, '1.0800', NULL, NULL, '2026-08-03 01:06:54'),
(13, 13, '2026-08-03 01:06:54', '1665.00', 2926, '0.3000', NULL, NULL, '2026-08-03 01:06:54'),
(14, 14, '2026-08-03 01:06:54', '5000.00', 5873, '0.0000', NULL, NULL, '2026-08-03 01:06:54'),
(15, 15, '2026-08-03 01:06:54', '16230.00', 1910, '-0.0300', NULL, NULL, '2026-08-03 01:06:54'),
(16, 16, '2026-08-03 01:06:55', '66.00', 2817309, '-2.9400', NULL, NULL, '2026-08-03 01:06:55'),
(17, 17, '2026-08-03 01:06:55', '1970.00', 861, '3.1400', NULL, NULL, '2026-08-03 01:06:55'),
(18, 18, '2026-08-03 01:06:55', '4500.00', 1094, '3.4500', NULL, NULL, '2026-08-03 01:06:55'),
(19, 19, '2026-08-03 01:06:55', '2280.00', 1257, '-0.8700', NULL, NULL, '2026-08-03 01:06:55'),
(20, 20, '2026-08-03 01:06:55', '24000.00', 775, '3.4300', NULL, NULL, '2026-08-03 01:06:55'),
(21, 21, '2026-08-03 01:06:55', '16500.00', 356, '-0.0300', NULL, NULL, '2026-08-03 01:06:55'),
(22, 22, '2026-08-03 01:06:55', '2995.00', 5108, '6.7700', NULL, NULL, '2026-08-03 01:06:55'),
(23, 23, '2026-08-03 01:06:55', '16995.00', 1793, '1.4000', NULL, NULL, '2026-08-03 01:06:55'),
(24, 24, '2026-08-03 01:06:55', '3140.00', 6342, '4.6700', NULL, NULL, '2026-08-03 01:06:55'),
(25, 25, '2026-08-03 01:06:55', '9000.00', 2606, '4.6500', NULL, NULL, '2026-08-03 01:06:55'),
(26, 26, '2026-08-03 01:06:55', '4515.00', 206, '-0.1100', NULL, NULL, '2026-08-03 01:06:55'),
(27, 27, '2026-08-03 01:06:55', '5130.00', 3153, '-1.4400', NULL, NULL, '2026-08-03 01:06:55'),
(28, 28, '2026-08-03 01:06:55', '3645.00', 5370, '-4.0800', NULL, NULL, '2026-08-03 01:06:55'),
(29, 29, '2026-08-03 01:06:55', '11900.00', 305, '2.1500', NULL, NULL, '2026-08-03 01:06:55'),
(30, 30, '2026-08-03 01:06:55', '2690.00', 13855, '-0.3700', NULL, NULL, '2026-08-03 01:06:55'),
(31, 31, '2026-08-03 01:06:55', '1485.00', 2355, '-1.0000', NULL, NULL, '2026-08-03 01:06:55'),
(32, 32, '2026-08-03 01:06:55', '38000.00', 2172, '0.0000', NULL, NULL, '2026-08-03 01:06:55'),
(33, 33, '2026-08-03 01:06:55', '2200.00', 2141, '0.0000', NULL, NULL, '2026-08-03 01:06:55'),
(34, 34, '2026-08-03 01:06:55', '9000.00', 9484, '3.4500', NULL, NULL, '2026-08-03 01:06:55'),
(35, 35, '2026-08-03 01:06:55', '6400.00', 78, '7.1100', NULL, NULL, '2026-08-03 01:06:55'),
(36, 36, '2026-08-03 01:06:55', '2200.00', 5877, '0.2300', NULL, NULL, '2026-08-03 01:06:55'),
(37, 37, '2026-08-03 01:06:55', '37750.00', 2246, '-4.8900', NULL, NULL, '2026-08-03 01:06:55'),
(38, 38, '2026-08-03 01:06:55', '15495.00', 3767, '-1.1800', NULL, NULL, '2026-08-03 01:06:55'),
(39, 39, '2026-08-03 01:06:55', '31000.00', 3054, '0.0000', NULL, NULL, '2026-08-03 01:06:55'),
(40, 40, '2026-08-03 01:06:55', '8305.00', 4773, '2.5300', NULL, NULL, '2026-08-03 01:06:55'),
(41, 41, '2026-08-03 01:06:55', '7565.00', 1066, '1.2000', NULL, NULL, '2026-08-03 01:06:55'),
(42, 42, '2026-08-03 01:06:55', '2680.00', 6520, '-4.2900', NULL, NULL, '2026-08-03 01:06:55'),
(43, 43, '2026-08-03 01:06:55', '23200.00', 6503, '0.0000', NULL, NULL, '2026-08-03 01:06:55'),
(44, 44, '2026-08-03 01:06:55', '2975.00', 2184, '7.4000', NULL, NULL, '2026-08-03 01:06:55'),
(45, 45, '2026-08-03 01:06:55', '3655.00', 1779, '0.1400', NULL, NULL, '2026-08-03 01:06:55'),
(46, 46, '2026-08-03 01:06:55', '51000.00', 4, '-5.5600', NULL, NULL, '2026-08-03 01:06:55'),
(47, 47, '2026-08-03 01:06:55', '1945.00', 10059, '2.3700', NULL, NULL, '2026-08-03 01:06:55'),
(48, 1, '2026-08-03 08:30:10', '3000.00', 2550, '0.0000', NULL, NULL, '2026-08-03 08:30:10'),
(49, 2, '2026-08-03 08:30:10', '7585.00', 11408, '-0.2000', NULL, NULL, '2026-08-03 08:30:10'),
(50, 3, '2026-08-03 08:30:10', '28670.00', 439, '-1.1400', NULL, NULL, '2026-08-03 08:30:10'),
(51, 4, '2026-08-03 08:30:10', '1890.00', 371, '0.5300', NULL, NULL, '2026-08-03 08:30:10'),
(52, 5, '2026-08-03 08:30:10', '8700.00', 2740, '0.0000', NULL, NULL, '2026-08-03 08:30:10'),
(53, 6, '2026-08-03 08:30:10', '7100.00', 6458, '0.0000', NULL, NULL, '2026-08-03 08:30:10'),
(54, 7, '2026-08-03 08:30:10', '10815.00', 5074, '0.1400', NULL, NULL, '2026-08-03 08:30:10'),
(55, 8, '2026-08-03 08:30:10', '5665.00', 5309, '0.0900', NULL, NULL, '2026-08-03 08:30:10'),
(56, 9, '2026-08-03 08:30:10', '5290.00', 5875, '0.0000', NULL, NULL, '2026-08-03 08:30:10'),
(57, 10, '2026-08-03 08:30:11', '7790.00', 3612, '1.1700', NULL, NULL, '2026-08-03 08:30:11'),
(58, 11, '2026-08-03 08:30:11', '3500.00', 10266, '0.0000', NULL, NULL, '2026-08-03 08:30:11'),
(59, 12, '2026-08-03 08:30:11', '28000.00', 1293, '1.0800', NULL, NULL, '2026-08-03 08:30:11'),
(60, 13, '2026-08-03 08:30:11', '1665.00', 2926, '0.3000', NULL, NULL, '2026-08-03 08:30:11'),
(61, 14, '2026-08-03 08:30:11', '5000.00', 5873, '0.0000', NULL, NULL, '2026-08-03 08:30:11'),
(62, 15, '2026-08-03 08:30:11', '16230.00', 1910, '-0.0300', NULL, NULL, '2026-08-03 08:30:11'),
(63, 16, '2026-08-03 08:30:11', '66.00', 2817309, '-2.9400', NULL, NULL, '2026-08-03 08:30:11'),
(64, 17, '2026-08-03 08:30:11', '1970.00', 861, '3.1400', NULL, NULL, '2026-08-03 08:30:11'),
(65, 18, '2026-08-03 08:30:11', '4500.00', 1094, '3.4500', NULL, NULL, '2026-08-03 08:30:11'),
(66, 19, '2026-08-03 08:30:11', '2280.00', 1257, '-0.8700', NULL, NULL, '2026-08-03 08:30:11'),
(67, 20, '2026-08-03 08:30:11', '24000.00', 775, '3.4300', NULL, NULL, '2026-08-03 08:30:11'),
(68, 21, '2026-08-03 08:30:11', '16500.00', 356, '-0.0300', NULL, NULL, '2026-08-03 08:30:11'),
(69, 22, '2026-08-03 08:30:11', '2995.00', 5108, '6.7700', NULL, NULL, '2026-08-03 08:30:11'),
(70, 23, '2026-08-03 08:30:11', '16995.00', 1793, '1.4000', NULL, NULL, '2026-08-03 08:30:11'),
(71, 24, '2026-08-03 08:30:11', '3140.00', 6342, '4.6700', NULL, NULL, '2026-08-03 08:30:11'),
(72, 25, '2026-08-03 08:30:11', '9000.00', 2606, '4.6500', NULL, NULL, '2026-08-03 08:30:11'),
(73, 26, '2026-08-03 08:30:11', '4515.00', 206, '-0.1100', NULL, NULL, '2026-08-03 08:30:11'),
(74, 27, '2026-08-03 08:30:11', '5130.00', 3153, '-1.4400', NULL, NULL, '2026-08-03 08:30:11'),
(75, 28, '2026-08-03 08:30:11', '3645.00', 5370, '-4.0800', NULL, NULL, '2026-08-03 08:30:11'),
(76, 29, '2026-08-03 08:30:11', '11900.00', 305, '2.1500', NULL, NULL, '2026-08-03 08:30:11'),
(77, 30, '2026-08-03 08:30:11', '2690.00', 13855, '-0.3700', NULL, NULL, '2026-08-03 08:30:11'),
(78, 31, '2026-08-03 08:30:11', '1485.00', 2355, '-1.0000', NULL, NULL, '2026-08-03 08:30:11'),
(79, 32, '2026-08-03 08:30:11', '38000.00', 2172, '0.0000', NULL, NULL, '2026-08-03 08:30:11'),
(80, 33, '2026-08-03 08:30:11', '2200.00', 2141, '0.0000', NULL, NULL, '2026-08-03 08:30:11'),
(81, 34, '2026-08-03 08:30:11', '9000.00', 9484, '3.4500', NULL, NULL, '2026-08-03 08:30:11'),
(82, 35, '2026-08-03 08:30:11', '6400.00', 78, '7.1100', NULL, NULL, '2026-08-03 08:30:11'),
(83, 36, '2026-08-03 08:30:11', '2200.00', 5877, '0.2300', NULL, NULL, '2026-08-03 08:30:11'),
(84, 37, '2026-08-03 08:30:11', '37750.00', 2246, '-4.8900', NULL, NULL, '2026-08-03 08:30:11'),
(85, 38, '2026-08-03 08:30:11', '15495.00', 3767, '-1.1800', NULL, NULL, '2026-08-03 08:30:11'),
(86, 39, '2026-08-03 08:30:11', '31000.00', 3054, '0.0000', NULL, NULL, '2026-08-03 08:30:11'),
(87, 40, '2026-08-03 08:30:11', '8305.00', 4773, '2.5300', NULL, NULL, '2026-08-03 08:30:11'),
(88, 41, '2026-08-03 08:30:11', '7565.00', 1066, '1.2000', NULL, NULL, '2026-08-03 08:30:11'),
(89, 42, '2026-08-03 08:30:11', '2680.00', 6520, '-4.2900', NULL, NULL, '2026-08-03 08:30:11'),
(90, 43, '2026-08-03 08:30:11', '23200.00', 6503, '0.0000', NULL, NULL, '2026-08-03 08:30:11'),
(91, 44, '2026-08-03 08:30:11', '2975.00', 2184, '7.4000', NULL, NULL, '2026-08-03 08:30:11'),
(92, 45, '2026-08-03 08:30:11', '3655.00', 1779, '0.1400', NULL, NULL, '2026-08-03 08:30:11'),
(93, 46, '2026-08-03 08:30:11', '51000.00', 4, '-5.5600', NULL, NULL, '2026-08-03 08:30:11'),
(94, 47, '2026-08-03 08:30:11', '1945.00', 10059, '2.3700', NULL, NULL, '2026-08-03 08:30:11'),
(95, 1, '2026-08-03 08:45:07', '3000.00', 2550, '0.0000', NULL, NULL, '2026-08-03 08:45:07'),
(96, 2, '2026-08-03 08:45:07', '7585.00', 11408, '-0.2000', NULL, NULL, '2026-08-03 08:45:07'),
(97, 3, '2026-08-03 08:45:07', '28670.00', 439, '-1.1400', NULL, NULL, '2026-08-03 08:45:07'),
(98, 4, '2026-08-03 08:45:07', '1890.00', 371, '0.5300', NULL, NULL, '2026-08-03 08:45:07'),
(99, 5, '2026-08-03 08:45:07', '8700.00', 2740, '0.0000', NULL, NULL, '2026-08-03 08:45:07'),
(100, 6, '2026-08-03 08:45:07', '7100.00', 6458, '0.0000', NULL, NULL, '2026-08-03 08:45:07'),
(101, 7, '2026-08-03 08:45:07', '10815.00', 5074, '0.1400', NULL, NULL, '2026-08-03 08:45:07'),
(102, 8, '2026-08-03 08:45:07', '5665.00', 5309, '0.0900', NULL, NULL, '2026-08-03 08:45:07'),
(103, 9, '2026-08-03 08:45:07', '5290.00', 5875, '0.0000', NULL, NULL, '2026-08-03 08:45:07'),
(104, 10, '2026-08-03 08:45:07', '7790.00', 3612, '1.1700', NULL, NULL, '2026-08-03 08:45:07'),
(105, 11, '2026-08-03 08:45:07', '3500.00', 10266, '0.0000', NULL, NULL, '2026-08-03 08:45:07'),
(106, 12, '2026-08-03 08:45:07', '28000.00', 1293, '1.0800', NULL, NULL, '2026-08-03 08:45:07'),
(107, 13, '2026-08-03 08:45:07', '1665.00', 2926, '0.3000', NULL, NULL, '2026-08-03 08:45:07'),
(108, 14, '2026-08-03 08:45:07', '5000.00', 5873, '0.0000', NULL, NULL, '2026-08-03 08:45:07'),
(109, 15, '2026-08-03 08:45:07', '16230.00', 1910, '-0.0300', NULL, NULL, '2026-08-03 08:45:07'),
(110, 16, '2026-08-03 08:45:07', '66.00', 2817309, '-2.9400', NULL, NULL, '2026-08-03 08:45:07'),
(111, 17, '2026-08-03 08:45:07', '1970.00', 861, '3.1400', NULL, NULL, '2026-08-03 08:45:07'),
(112, 18, '2026-08-03 08:45:07', '4500.00', 1094, '3.4500', NULL, NULL, '2026-08-03 08:45:07'),
(113, 19, '2026-08-03 08:45:07', '2280.00', 1257, '-0.8700', NULL, NULL, '2026-08-03 08:45:07'),
(114, 20, '2026-08-03 08:45:07', '24000.00', 775, '3.4300', NULL, NULL, '2026-08-03 08:45:07'),
(115, 21, '2026-08-03 08:45:07', '16500.00', 356, '-0.0300', NULL, NULL, '2026-08-03 08:45:07'),
(116, 22, '2026-08-03 08:45:07', '2995.00', 5108, '6.7700', NULL, NULL, '2026-08-03 08:45:07'),
(117, 23, '2026-08-03 08:45:07', '16995.00', 1793, '1.4000', NULL, NULL, '2026-08-03 08:45:07'),
(118, 24, '2026-08-03 08:45:07', '3140.00', 6342, '4.6700', NULL, NULL, '2026-08-03 08:45:07'),
(119, 25, '2026-08-03 08:45:07', '9000.00', 2606, '4.6500', NULL, NULL, '2026-08-03 08:45:07'),
(120, 26, '2026-08-03 08:45:07', '4515.00', 206, '-0.1100', NULL, NULL, '2026-08-03 08:45:07'),
(121, 27, '2026-08-03 08:45:08', '5130.00', 3153, '-1.4400', NULL, NULL, '2026-08-03 08:45:08'),
(122, 28, '2026-08-03 08:45:08', '3645.00', 5370, '-4.0800', NULL, NULL, '2026-08-03 08:45:08'),
(123, 29, '2026-08-03 08:45:08', '11900.00', 305, '2.1500', NULL, NULL, '2026-08-03 08:45:08'),
(124, 30, '2026-08-03 08:45:08', '2690.00', 13855, '-0.3700', NULL, NULL, '2026-08-03 08:45:08'),
(125, 31, '2026-08-03 08:45:08', '1485.00', 2355, '-1.0000', NULL, NULL, '2026-08-03 08:45:08'),
(126, 32, '2026-08-03 08:45:08', '38000.00', 2172, '0.0000', NULL, NULL, '2026-08-03 08:45:08'),
(127, 33, '2026-08-03 08:45:08', '2200.00', 2141, '0.0000', NULL, NULL, '2026-08-03 08:45:08'),
(128, 34, '2026-08-03 08:45:08', '9000.00', 9484, '3.4500', NULL, NULL, '2026-08-03 08:45:08'),
(129, 35, '2026-08-03 08:45:08', '6400.00', 78, '7.1100', NULL, NULL, '2026-08-03 08:45:08'),
(130, 36, '2026-08-03 08:45:08', '2200.00', 5877, '0.2300', NULL, NULL, '2026-08-03 08:45:08'),
(131, 37, '2026-08-03 08:45:08', '37750.00', 2246, '-4.8900', NULL, NULL, '2026-08-03 08:45:08'),
(132, 38, '2026-08-03 08:45:08', '15495.00', 3767, '-1.1800', NULL, NULL, '2026-08-03 08:45:08'),
(133, 39, '2026-08-03 08:45:08', '31000.00', 3054, '0.0000', NULL, NULL, '2026-08-03 08:45:08'),
(134, 40, '2026-08-03 08:45:08', '8305.00', 4773, '2.5300', NULL, NULL, '2026-08-03 08:45:08'),
(135, 41, '2026-08-03 08:45:08', '7565.00', 1066, '1.2000', NULL, NULL, '2026-08-03 08:45:08'),
(136, 42, '2026-08-03 08:45:08', '2680.00', 6520, '-4.2900', NULL, NULL, '2026-08-03 08:45:08'),
(137, 43, '2026-08-03 08:45:08', '23200.00', 6503, '0.0000', NULL, NULL, '2026-08-03 08:45:08'),
(138, 44, '2026-08-03 08:45:08', '2975.00', 2184, '7.4000', NULL, NULL, '2026-08-03 08:45:08'),
(139, 45, '2026-08-03 08:45:08', '3655.00', 1779, '0.1400', NULL, NULL, '2026-08-03 08:45:08'),
(140, 46, '2026-08-03 08:45:08', '51000.00', 4, '-5.5600', NULL, NULL, '2026-08-03 08:45:08'),
(141, 47, '2026-08-03 08:45:08', '1945.00', 10059, '2.3700', NULL, NULL, '2026-08-03 08:45:08'),
(142, 1, '2026-08-03 09:01:04', '3000.00', 2550, '0.0000', NULL, NULL, '2026-08-03 09:01:04'),
(143, 2, '2026-08-03 09:01:05', '7585.00', 11408, '-0.2000', NULL, NULL, '2026-08-03 09:01:05'),
(144, 3, '2026-08-03 09:01:05', '28670.00', 439, '-1.1400', NULL, NULL, '2026-08-03 09:01:05'),
(145, 4, '2026-08-03 09:01:05', '1890.00', 371, '0.5300', NULL, NULL, '2026-08-03 09:01:05'),
(146, 5, '2026-08-03 09:01:05', '8700.00', 2740, '0.0000', NULL, NULL, '2026-08-03 09:01:05'),
(147, 6, '2026-08-03 09:01:05', '7100.00', 6458, '0.0000', NULL, NULL, '2026-08-03 09:01:05'),
(148, 7, '2026-08-03 09:01:05', '10815.00', 5074, '0.1400', NULL, NULL, '2026-08-03 09:01:05'),
(149, 8, '2026-08-03 09:01:05', '5665.00', 5309, '0.0900', NULL, NULL, '2026-08-03 09:01:05'),
(150, 9, '2026-08-03 09:01:06', '5290.00', 5875, '0.0000', NULL, NULL, '2026-08-03 09:01:06'),
(151, 10, '2026-08-03 09:01:06', '7790.00', 3612, '1.1700', NULL, NULL, '2026-08-03 09:01:06'),
(152, 11, '2026-08-03 09:01:06', '3500.00', 10266, '0.0000', NULL, NULL, '2026-08-03 09:01:06'),
(153, 12, '2026-08-03 09:01:06', '28000.00', 1293, '1.0800', NULL, NULL, '2026-08-03 09:01:06'),
(154, 13, '2026-08-03 09:01:06', '1665.00', 2926, '0.3000', NULL, NULL, '2026-08-03 09:01:06'),
(155, 14, '2026-08-03 09:01:06', '5000.00', 5873, '0.0000', NULL, NULL, '2026-08-03 09:01:06'),
(156, 15, '2026-08-03 09:01:06', '16230.00', 1910, '-0.0300', NULL, NULL, '2026-08-03 09:01:06'),
(157, 16, '2026-08-03 09:01:06', '66.00', 2817309, '-2.9400', NULL, NULL, '2026-08-03 09:01:06'),
(158, 17, '2026-08-03 09:01:06', '1970.00', 861, '3.1400', NULL, NULL, '2026-08-03 09:01:06'),
(159, 18, '2026-08-03 09:01:06', '4500.00', 1094, '3.4500', NULL, NULL, '2026-08-03 09:01:06'),
(160, 19, '2026-08-03 09:01:06', '2280.00', 1257, '-0.8700', NULL, NULL, '2026-08-03 09:01:06'),
(161, 20, '2026-08-03 09:01:06', '24000.00', 775, '3.4300', NULL, NULL, '2026-08-03 09:01:06'),
(162, 21, '2026-08-03 09:01:06', '16500.00', 356, '-0.0300', NULL, NULL, '2026-08-03 09:01:06'),
(163, 22, '2026-08-03 09:01:06', '2995.00', 5108, '6.7700', NULL, NULL, '2026-08-03 09:01:06'),
(164, 23, '2026-08-03 09:01:06', '16995.00', 1793, '1.4000', NULL, NULL, '2026-08-03 09:01:06'),
(165, 24, '2026-08-03 09:01:06', '3140.00', 6342, '4.6700', NULL, NULL, '2026-08-03 09:01:06'),
(166, 25, '2026-08-03 09:01:06', '9000.00', 2606, '4.6500', NULL, NULL, '2026-08-03 09:01:06'),
(167, 26, '2026-08-03 09:01:06', '4515.00', 206, '-0.1100', NULL, NULL, '2026-08-03 09:01:06'),
(168, 27, '2026-08-03 09:01:06', '5130.00', 3153, '-1.4400', NULL, NULL, '2026-08-03 09:01:06'),
(169, 28, '2026-08-03 09:01:06', '3645.00', 5370, '-4.0800', NULL, NULL, '2026-08-03 09:01:06'),
(170, 29, '2026-08-03 09:01:06', '11900.00', 305, '2.1500', NULL, NULL, '2026-08-03 09:01:06'),
(171, 30, '2026-08-03 09:01:06', '2690.00', 13855, '-0.3700', NULL, NULL, '2026-08-03 09:01:06'),
(172, 31, '2026-08-03 09:01:06', '1485.00', 2355, '-1.0000', NULL, NULL, '2026-08-03 09:01:06'),
(173, 32, '2026-08-03 09:01:06', '38000.00', 2172, '0.0000', NULL, NULL, '2026-08-03 09:01:06'),
(174, 33, '2026-08-03 09:01:06', '2200.00', 2141, '0.0000', NULL, NULL, '2026-08-03 09:01:06'),
(175, 34, '2026-08-03 09:01:06', '9000.00', 9484, '3.4500', NULL, NULL, '2026-08-03 09:01:06'),
(176, 35, '2026-08-03 09:01:06', '6400.00', 78, '7.1100', NULL, NULL, '2026-08-03 09:01:06'),
(177, 36, '2026-08-03 09:01:06', '2200.00', 5877, '0.2300', NULL, NULL, '2026-08-03 09:01:06'),
(178, 37, '2026-08-03 09:01:06', '37750.00', 2246, '-4.8900', NULL, NULL, '2026-08-03 09:01:06'),
(179, 38, '2026-08-03 09:01:06', '15495.00', 3767, '-1.1800', NULL, NULL, '2026-08-03 09:01:06'),
(180, 39, '2026-08-03 09:01:06', '31000.00', 3054, '0.0000', NULL, NULL, '2026-08-03 09:01:06'),
(181, 40, '2026-08-03 09:01:06', '8305.00', 4773, '2.5300', NULL, NULL, '2026-08-03 09:01:06'),
(182, 41, '2026-08-03 09:01:06', '7565.00', 1066, '1.2000', NULL, NULL, '2026-08-03 09:01:06'),
(183, 42, '2026-08-03 09:01:06', '2680.00', 6520, '-4.2900', NULL, NULL, '2026-08-03 09:01:06'),
(184, 43, '2026-08-03 09:01:06', '23200.00', 6503, '0.0000', NULL, NULL, '2026-08-03 09:01:06'),
(185, 44, '2026-08-03 09:01:06', '2975.00', 2184, '7.4000', NULL, NULL, '2026-08-03 09:01:06'),
(186, 45, '2026-08-03 09:01:06', '3655.00', 1779, '0.1400', NULL, NULL, '2026-08-03 09:01:06'),
(187, 46, '2026-08-03 09:01:06', '51000.00', 4, '-5.5600', NULL, NULL, '2026-08-03 09:01:06'),
(188, 47, '2026-08-03 09:01:06', '1945.00', 10059, '2.3700', NULL, NULL, '2026-08-03 09:01:06'),
(189, 1, '2026-08-03 09:15:09', '3000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:09'),
(190, 2, '2026-08-03 09:15:09', '7585.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:09'),
(191, 3, '2026-08-03 09:15:09', '28670.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:09'),
(192, 4, '2026-08-03 09:15:09', '1890.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:09'),
(193, 5, '2026-08-03 09:15:09', '8700.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:09'),
(194, 6, '2026-08-03 09:15:09', '7100.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:09'),
(195, 7, '2026-08-03 09:15:09', '10815.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:09'),
(196, 8, '2026-08-03 09:15:09', '5665.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:09'),
(197, 9, '2026-08-03 09:15:09', '5290.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:09'),
(198, 10, '2026-08-03 09:15:09', '7790.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:09'),
(199, 11, '2026-08-03 09:15:09', '3500.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:09'),
(200, 12, '2026-08-03 09:15:10', '28000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(201, 13, '2026-08-03 09:15:10', '1665.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(202, 14, '2026-08-03 09:15:10', '5000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(203, 15, '2026-08-03 09:15:10', '16230.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(204, 16, '2026-08-03 09:15:10', '66.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(205, 17, '2026-08-03 09:15:10', '1970.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(206, 18, '2026-08-03 09:15:10', '4500.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(207, 19, '2026-08-03 09:15:10', '2280.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(208, 20, '2026-08-03 09:15:10', '24000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(209, 21, '2026-08-03 09:15:10', '16500.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(210, 22, '2026-08-03 09:15:10', '2995.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(211, 23, '2026-08-03 09:15:10', '16995.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(212, 24, '2026-08-03 09:15:10', '3140.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(213, 25, '2026-08-03 09:15:10', '9000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(214, 26, '2026-08-03 09:15:10', '4515.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(215, 27, '2026-08-03 09:15:10', '5130.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(216, 28, '2026-08-03 09:15:10', '3645.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(217, 29, '2026-08-03 09:15:10', '11900.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(218, 30, '2026-08-03 09:15:10', '2690.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(219, 31, '2026-08-03 09:15:10', '1485.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(220, 32, '2026-08-03 09:15:10', '38000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(221, 33, '2026-08-03 09:15:10', '2200.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(222, 34, '2026-08-03 09:15:10', '9000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(223, 35, '2026-08-03 09:15:10', '6400.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(224, 36, '2026-08-03 09:15:10', '2200.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(225, 37, '2026-08-03 09:15:10', '37750.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(226, 38, '2026-08-03 09:15:10', '15495.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(227, 39, '2026-08-03 09:15:10', '31000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(228, 40, '2026-08-03 09:15:10', '8305.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(229, 41, '2026-08-03 09:15:10', '7565.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(230, 42, '2026-08-03 09:15:10', '2680.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(231, 43, '2026-08-03 09:15:10', '23200.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(232, 44, '2026-08-03 09:15:10', '2975.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(233, 45, '2026-08-03 09:15:10', '3655.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(234, 46, '2026-08-03 09:15:10', '51000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(235, 47, '2026-08-03 09:15:10', '1945.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:15:10'),
(236, 1, '2026-08-03 09:31:20', '3000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:20'),
(237, 2, '2026-08-03 09:31:20', '7585.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:20'),
(238, 3, '2026-08-03 09:31:20', '28670.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:20'),
(239, 4, '2026-08-03 09:31:20', '1890.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:20'),
(240, 5, '2026-08-03 09:31:20', '8700.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:20'),
(241, 6, '2026-08-03 09:31:20', '7100.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:20'),
(242, 7, '2026-08-03 09:31:20', '10815.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:20'),
(243, 8, '2026-08-03 09:31:20', '5665.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:20'),
(244, 9, '2026-08-03 09:31:20', '5290.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:20'),
(245, 10, '2026-08-03 09:31:20', '7790.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:20'),
(246, 11, '2026-08-03 09:31:20', '3500.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:20'),
(247, 12, '2026-08-03 09:31:21', '28000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(248, 13, '2026-08-03 09:31:21', '1665.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(249, 14, '2026-08-03 09:31:21', '5000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(250, 15, '2026-08-03 09:31:21', '16230.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(251, 16, '2026-08-03 09:31:21', '66.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(252, 17, '2026-08-03 09:31:21', '1970.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(253, 18, '2026-08-03 09:31:21', '4500.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(254, 19, '2026-08-03 09:31:21', '2280.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(255, 20, '2026-08-03 09:31:21', '24000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(256, 21, '2026-08-03 09:31:21', '16500.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(257, 22, '2026-08-03 09:31:21', '2995.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(258, 23, '2026-08-03 09:31:21', '16995.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(259, 24, '2026-08-03 09:31:21', '3140.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(260, 25, '2026-08-03 09:31:21', '9000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(261, 26, '2026-08-03 09:31:21', '4515.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(262, 27, '2026-08-03 09:31:21', '5130.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(263, 28, '2026-08-03 09:31:21', '3645.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(264, 29, '2026-08-03 09:31:21', '11900.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(265, 30, '2026-08-03 09:31:21', '2690.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(266, 31, '2026-08-03 09:31:21', '1485.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(267, 32, '2026-08-03 09:31:21', '38000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(268, 33, '2026-08-03 09:31:21', '2200.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(269, 34, '2026-08-03 09:31:21', '9000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(270, 35, '2026-08-03 09:31:21', '6400.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(271, 36, '2026-08-03 09:31:21', '2200.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(272, 37, '2026-08-03 09:31:21', '37750.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(273, 38, '2026-08-03 09:31:21', '15495.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(274, 39, '2026-08-03 09:31:21', '31000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(275, 40, '2026-08-03 09:31:21', '8305.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(276, 41, '2026-08-03 09:31:21', '7565.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(277, 42, '2026-08-03 09:31:21', '2680.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(278, 43, '2026-08-03 09:31:21', '23200.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(279, 44, '2026-08-03 09:31:21', '2975.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(280, 45, '2026-08-03 09:31:21', '3655.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(281, 46, '2026-08-03 09:31:21', '51000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(282, 47, '2026-08-03 09:31:21', '1945.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:31:21'),
(283, 1, '2026-08-03 09:46:33', '3000.00', 466, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(284, 2, '2026-08-03 09:46:33', '7600.00', 362, '0.2000', NULL, NULL, '2026-08-03 09:46:33'),
(285, 3, '2026-08-03 09:46:33', '28675.00', 6, '0.0200', NULL, NULL, '2026-08-03 09:46:33'),
(286, 4, '2026-08-03 09:46:33', '1880.00', 75, '-0.5300', NULL, NULL, '2026-08-03 09:46:33'),
(287, 5, '2026-08-03 09:46:33', '8700.00', 173, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(288, 6, '2026-08-03 09:46:33', '7105.00', 119, '0.0700', NULL, NULL, '2026-08-03 09:46:33'),
(289, 7, '2026-08-03 09:46:33', '10815.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(290, 8, '2026-08-03 09:46:33', '5665.00', 197, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(291, 9, '2026-08-03 09:46:33', '5290.00', 198, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(292, 10, '2026-08-03 09:46:33', '7790.00', 792, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(293, 11, '2026-08-03 09:46:33', '3500.00', 640, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(294, 12, '2026-08-03 09:46:33', '28000.00', 58, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(295, 13, '2026-08-03 09:46:33', '1670.00', 312, '0.3000', NULL, NULL, '2026-08-03 09:46:33'),
(296, 14, '2026-08-03 09:46:33', '5000.00', 970, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(297, 15, '2026-08-03 09:46:33', '16230.00', 46, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(298, 16, '2026-08-03 09:46:33', '66.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(299, 17, '2026-08-03 09:46:33', '1970.00', 227, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(300, 18, '2026-08-03 09:46:33', '4500.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(301, 19, '2026-08-03 09:46:33', '2280.00', 49, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(302, 20, '2026-08-03 09:46:33', '24000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(303, 21, '2026-08-03 09:46:33', '16500.00', 76, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(304, 22, '2026-08-03 09:46:33', '2995.00', 565, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(305, 23, '2026-08-03 09:46:33', '17000.00', 485, '0.0300', NULL, NULL, '2026-08-03 09:46:33'),
(306, 24, '2026-08-03 09:46:33', '3140.00', 199, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(307, 25, '2026-08-03 09:46:33', '9000.00', 35, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(308, 26, '2026-08-03 09:46:33', '4520.00', 154, '0.1100', NULL, NULL, '2026-08-03 09:46:33'),
(309, 27, '2026-08-03 09:46:33', '5100.00', 497, '-0.5800', NULL, NULL, '2026-08-03 09:46:33'),
(310, 28, '2026-08-03 09:46:33', '3635.00', 698, '-0.2700', NULL, NULL, '2026-08-03 09:46:33'),
(311, 29, '2026-08-03 09:46:33', '11900.00', 529, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(312, 30, '2026-08-03 09:46:33', '2700.00', 1055, '0.3700', NULL, NULL, '2026-08-03 09:46:33'),
(313, 31, '2026-08-03 09:46:33', '1485.00', 119, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(314, 32, '2026-08-03 09:46:33', '38000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(315, 33, '2026-08-03 09:46:33', '2200.00', 640, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(316, 34, '2026-08-03 09:46:33', '9000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(317, 35, '2026-08-03 09:46:33', '6400.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(318, 36, '2026-08-03 09:46:33', '2200.00', 110, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(319, 37, '2026-08-03 09:46:33', '37760.00', 9, '0.0300', NULL, NULL, '2026-08-03 09:46:33'),
(320, 38, '2026-08-03 09:46:33', '15495.00', 281, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(321, 39, '2026-08-03 09:46:33', '31000.00', 552, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(322, 40, '2026-08-03 09:46:33', '8400.00', 102, '1.1400', NULL, NULL, '2026-08-03 09:46:33'),
(323, 41, '2026-08-03 09:46:33', '7595.00', 100, '0.4000', NULL, NULL, '2026-08-03 09:46:33'),
(324, 42, '2026-08-03 09:46:33', '2680.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(325, 43, '2026-08-03 09:46:33', '23205.00', 170, '0.0200', NULL, NULL, '2026-08-03 09:46:33'),
(326, 44, '2026-08-03 09:46:33', '2975.00', 3375, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(327, 45, '2026-08-03 09:46:33', '3655.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(328, 46, '2026-08-03 09:46:33', '51000.00', 0, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(329, 47, '2026-08-03 09:46:33', '1945.00', 1216, '0.0000', NULL, NULL, '2026-08-03 09:46:33'),
(330, 1, '2026-08-03 10:01:56', '3000.00', 466, '0.0000', NULL, NULL, '2026-08-03 10:01:56'),
(331, 2, '2026-08-03 10:01:56', '7600.00', 362, '0.2000', NULL, NULL, '2026-08-03 10:01:56'),
(332, 3, '2026-08-03 10:01:57', '28675.00', 6, '0.0200', NULL, NULL, '2026-08-03 10:01:57'),
(333, 4, '2026-08-03 10:01:57', '1880.00', 75, '-0.5300', NULL, NULL, '2026-08-03 10:01:57'),
(334, 5, '2026-08-03 10:01:57', '8700.00', 173, '0.0000', NULL, NULL, '2026-08-03 10:01:57'),
(335, 6, '2026-08-03 10:01:57', '7105.00', 119, '0.0700', NULL, NULL, '2026-08-03 10:01:57'),
(336, 7, '2026-08-03 10:01:57', '10815.00', 0, '0.0000', NULL, NULL, '2026-08-03 10:01:57'),
(337, 8, '2026-08-03 10:01:57', '5665.00', 197, '0.0000', NULL, NULL, '2026-08-03 10:01:57'),
(338, 9, '2026-08-03 10:01:57', '5290.00', 198, '0.0000', NULL, NULL, '2026-08-03 10:01:57'),
(339, 10, '2026-08-03 10:01:57', '7790.00', 792, '0.0000', NULL, NULL, '2026-08-03 10:01:57'),
(340, 11, '2026-08-03 10:01:57', '3500.00', 640, '0.0000', NULL, NULL, '2026-08-03 10:01:57'),
(341, 12, '2026-08-03 10:01:57', '28000.00', 58, '0.0000', NULL, NULL, '2026-08-03 10:01:57'),
(342, 13, '2026-08-03 10:01:57', '1670.00', 312, '0.3000', NULL, NULL, '2026-08-03 10:01:57'),
(343, 14, '2026-08-03 10:01:57', '5000.00', 970, '0.0000', NULL, NULL, '2026-08-03 10:01:57'),
(344, 15, '2026-08-03 10:01:57', '16230.00', 46, '0.0000', NULL, NULL, '2026-08-03 10:01:57'),
(345, 16, '2026-08-03 10:01:57', '66.00', 0, '0.0000', NULL, NULL, '2026-08-03 10:01:57'),
(346, 17, '2026-08-03 10:01:57', '1970.00', 227, '0.0000', NULL, NULL, '2026-08-03 10:01:57'),
(347, 18, '2026-08-03 10:01:57', '4500.00', 0, '0.0000', NULL, NULL, '2026-08-03 10:01:57'),
(348, 19, '2026-08-03 10:01:57', '2280.00', 49, '0.0000', NULL, NULL, '2026-08-03 10:01:57'),
(349, 20, '2026-08-03 10:01:57', '24000.00', 0, '0.0000', NULL, NULL, '2026-08-03 10:01:57'),
(350, 21, '2026-08-03 10:01:57', '16500.00', 76, '0.0000', NULL, NULL, '2026-08-03 10:01:57'),
(351, 22, '2026-08-03 10:01:57', '2995.00', 565, '0.0000', NULL, NULL, '2026-08-03 10:01:57'),
(352, 23, '2026-08-03 10:01:58', '17000.00', 485, '0.0300', NULL, NULL, '2026-08-03 10:01:58'),
(353, 24, '2026-08-03 10:01:58', '3140.00', 199, '0.0000', NULL, NULL, '2026-08-03 10:01:58'),
(354, 25, '2026-08-03 10:01:58', '9000.00', 35, '0.0000', NULL, NULL, '2026-08-03 10:01:58'),
(355, 26, '2026-08-03 10:01:58', '4520.00', 154, '0.1100', NULL, NULL, '2026-08-03 10:01:58'),
(356, 27, '2026-08-03 10:01:58', '5100.00', 497, '-0.5800', NULL, NULL, '2026-08-03 10:01:58'),
(357, 28, '2026-08-03 10:01:58', '3635.00', 698, '-0.2700', NULL, NULL, '2026-08-03 10:01:58'),
(358, 29, '2026-08-03 10:01:58', '11900.00', 529, '0.0000', NULL, NULL, '2026-08-03 10:01:58'),
(359, 30, '2026-08-03 10:01:58', '2700.00', 1055, '0.3700', NULL, NULL, '2026-08-03 10:01:58'),
(360, 31, '2026-08-03 10:01:58', '1485.00', 119, '0.0000', NULL, NULL, '2026-08-03 10:01:58'),
(361, 32, '2026-08-03 10:01:58', '38000.00', 0, '0.0000', NULL, NULL, '2026-08-03 10:01:58'),
(362, 33, '2026-08-03 10:01:58', '2200.00', 640, '0.0000', NULL, NULL, '2026-08-03 10:01:58'),
(363, 34, '2026-08-03 10:01:58', '9000.00', 0, '0.0000', NULL, NULL, '2026-08-03 10:01:58'),
(364, 35, '2026-08-03 10:01:58', '6400.00', 0, '0.0000', NULL, NULL, '2026-08-03 10:01:58'),
(365, 36, '2026-08-03 10:01:58', '2200.00', 110, '0.0000', NULL, NULL, '2026-08-03 10:01:58'),
(366, 37, '2026-08-03 10:01:58', '37760.00', 9, '0.0300', NULL, NULL, '2026-08-03 10:01:58'),
(367, 38, '2026-08-03 10:01:58', '15495.00', 281, '0.0000', NULL, NULL, '2026-08-03 10:01:58'),
(368, 39, '2026-08-03 10:01:58', '31000.00', 552, '0.0000', NULL, NULL, '2026-08-03 10:01:58'),
(369, 40, '2026-08-03 10:01:58', '8400.00', 102, '1.1400', NULL, NULL, '2026-08-03 10:01:58'),
(370, 41, '2026-08-03 10:01:58', '7595.00', 100, '0.4000', NULL, NULL, '2026-08-03 10:01:58'),
(371, 42, '2026-08-03 10:01:58', '2680.00', 0, '0.0000', NULL, NULL, '2026-08-03 10:01:58'),
(372, 43, '2026-08-03 10:01:58', '23205.00', 170, '0.0200', NULL, NULL, '2026-08-03 10:01:58'),
(373, 44, '2026-08-03 10:01:58', '2975.00', 3375, '0.0000', NULL, NULL, '2026-08-03 10:01:58'),
(374, 45, '2026-08-03 10:01:58', '3655.00', 0, '0.0000', NULL, NULL, '2026-08-03 10:01:58'),
(375, 46, '2026-08-03 10:01:58', '51000.00', 0, '0.0000', NULL, NULL, '2026-08-03 10:01:58'),
(376, 47, '2026-08-03 10:01:58', '1945.00', 1216, '0.0000', NULL, NULL, '2026-08-03 10:01:58'),
(377, 1, '2026-08-03 10:15:13', '3000.00', 523, '0.0000', NULL, NULL, '2026-08-03 10:15:13'),
(378, 2, '2026-08-03 10:15:13', '7600.00', 1106, '0.2000', NULL, NULL, '2026-08-03 10:15:13'),
(379, 3, '2026-08-03 10:15:13', '29015.00', 13, '1.2000', NULL, NULL, '2026-08-03 10:15:13'),
(380, 4, '2026-08-03 10:15:13', '1880.00', 75, '-0.5300', NULL, NULL, '2026-08-03 10:15:13'),
(381, 5, '2026-08-03 10:15:13', '8690.00', 330, '-0.1100', NULL, NULL, '2026-08-03 10:15:13'),
(382, 6, '2026-08-03 10:15:13', '7200.00', 1571, '1.4100', NULL, NULL, '2026-08-03 10:15:13'),
(383, 7, '2026-08-03 10:15:13', '10820.00', 953, '0.0500', NULL, NULL, '2026-08-03 10:15:13'),
(384, 8, '2026-08-03 10:15:13', '5670.00', 493, '0.0900', NULL, NULL, '2026-08-03 10:15:13'),
(385, 9, '2026-08-03 10:15:13', '5215.00', 199, '-1.4200', NULL, NULL, '2026-08-03 10:15:13'),
(386, 10, '2026-08-03 10:15:13', '7755.00', 1059, '-0.4500', NULL, NULL, '2026-08-03 10:15:13'),
(387, 11, '2026-08-03 10:15:13', '3500.00', 1203, '0.0000', NULL, NULL, '2026-08-03 10:15:13'),
(388, 12, '2026-08-03 10:15:13', '28100.00', 115, '0.3600', NULL, NULL, '2026-08-03 10:15:13'),
(389, 13, '2026-08-03 10:15:13', '1695.00', 338, '1.8000', NULL, NULL, '2026-08-03 10:15:13'),
(390, 14, '2026-08-03 10:15:13', '5050.00', 1361, '1.0000', NULL, NULL, '2026-08-03 10:15:13'),
(391, 15, '2026-08-03 10:15:13', '16200.00', 205, '-0.1800', NULL, NULL, '2026-08-03 10:15:13'),
(392, 16, '2026-08-03 10:15:13', '69.00', 229467, '4.5500', NULL, NULL, '2026-08-03 10:15:13'),
(393, 17, '2026-08-03 10:15:13', '1970.00', 310, '0.0000', NULL, NULL, '2026-08-03 10:15:13'),
(394, 18, '2026-08-03 10:15:13', '4835.00', 3972, '7.4400', NULL, NULL, '2026-08-03 10:15:13'),
(395, 19, '2026-08-03 10:15:13', '2280.00', 85, '0.0000', NULL, NULL, '2026-08-03 10:15:13'),
(396, 20, '2026-08-03 10:15:13', '24495.00', 276, '2.0600', NULL, NULL, '2026-08-03 10:15:13'),
(397, 21, '2026-08-03 10:15:13', '16600.00', 107, '0.6100', NULL, NULL, '2026-08-03 10:15:13'),
(398, 22, '2026-08-03 10:15:13', '2990.00', 694, '-0.1700', NULL, NULL, '2026-08-03 10:15:13'),
(399, 23, '2026-08-03 10:15:13', '16995.00', 541, '0.0000', NULL, NULL, '2026-08-03 10:15:13'),
(400, 24, '2026-08-03 10:15:13', '3200.00', 1042, '1.9100', NULL, NULL, '2026-08-03 10:15:13'),
(401, 25, '2026-08-03 10:15:13', '9000.00', 320, '0.0000', NULL, NULL, '2026-08-03 10:15:13'),
(402, 26, '2026-08-03 10:15:13', '4515.00', 249, '0.0000', NULL, NULL, '2026-08-03 10:15:13'),
(403, 27, '2026-08-03 10:15:13', '5095.00', 576, '-0.6800', NULL, NULL, '2026-08-03 10:15:13'),
(404, 28, '2026-08-03 10:15:13', '3640.00', 989, '-0.1400', NULL, NULL, '2026-08-03 10:15:13'),
(405, 29, '2026-08-03 10:15:13', '11900.00', 531, '0.0000', NULL, NULL, '2026-08-03 10:15:13'),
(406, 30, '2026-08-03 10:15:13', '2700.00', 1219, '0.3700', NULL, NULL, '2026-08-03 10:15:13'),
(407, 31, '2026-08-03 10:15:13', '1485.00', 119, '0.0000', NULL, NULL, '2026-08-03 10:15:13'),
(408, 32, '2026-08-03 10:15:13', '38000.00', 484, '0.0000', NULL, NULL, '2026-08-03 10:15:13'),
(409, 33, '2026-08-03 10:15:13', '2240.00', 690, '1.8200', NULL, NULL, '2026-08-03 10:15:13'),
(410, 34, '2026-08-03 10:15:13', '8705.00', 824, '-3.2800', NULL, NULL, '2026-08-03 10:15:13'),
(411, 35, '2026-08-03 10:15:13', '6400.00', 0, '0.0000', NULL, NULL, '2026-08-03 10:15:13'),
(412, 36, '2026-08-03 10:15:13', '2200.00', 978, '0.0000', NULL, NULL, '2026-08-03 10:15:13'),
(413, 37, '2026-08-03 10:15:13', '37800.00', 10, '0.1300', NULL, NULL, '2026-08-03 10:15:13'),
(414, 38, '2026-08-03 10:15:13', '15500.00', 471, '0.0300', NULL, NULL, '2026-08-03 10:15:13'),
(415, 39, '2026-08-03 10:15:13', '31000.00', 775, '0.0000', NULL, NULL, '2026-08-03 10:15:13'),
(416, 40, '2026-08-03 10:15:13', '8400.00', 112, '1.1400', NULL, NULL, '2026-08-03 10:15:13'),
(417, 41, '2026-08-03 10:15:13', '7595.00', 100, '0.4000', NULL, NULL, '2026-08-03 10:15:13'),
(418, 42, '2026-08-03 10:15:13', '2755.00', 2184, '2.8000', NULL, NULL, '2026-08-03 10:15:13'),
(419, 43, '2026-08-03 10:15:13', '23205.00', 190, '0.0200', NULL, NULL, '2026-08-03 10:15:13'),
(420, 44, '2026-08-03 10:15:13', '2975.00', 3790, '0.0000', NULL, NULL, '2026-08-03 10:15:13'),
(421, 45, '2026-08-03 10:15:13', '3650.00', 420, '-0.1400', NULL, NULL, '2026-08-03 10:15:13'),
(422, 46, '2026-08-03 10:15:13', '51000.00', 0, '0.0000', NULL, NULL, '2026-08-03 10:15:13'),
(423, 47, '2026-08-03 10:15:13', '1945.00', 1431, '0.0000', NULL, NULL, '2026-08-03 10:15:13'),
(424, 1, '2026-08-03 10:31:54', '3000.00', 1134, '0.0000', NULL, NULL, '2026-08-03 10:31:54'),
(425, 2, '2026-08-03 10:31:54', '7500.00', 7127, '-1.1200', NULL, NULL, '2026-08-03 10:31:54'),
(426, 3, '2026-08-03 10:31:54', '29015.00', 18, '1.2000', NULL, NULL, '2026-08-03 10:31:54'),
(427, 4, '2026-08-03 10:31:54', '1880.00', 75, '-0.5300', NULL, NULL, '2026-08-03 10:31:54'),
(428, 5, '2026-08-03 10:31:54', '8685.00', 506, '-0.1700', NULL, NULL, '2026-08-03 10:31:54'),
(429, 6, '2026-08-03 10:31:54', '7150.00', 1891, '0.7000', NULL, NULL, '2026-08-03 10:31:54'),
(430, 7, '2026-08-03 10:31:54', '11100.00', 1503, '2.6400', NULL, NULL, '2026-08-03 10:31:54'),
(431, 8, '2026-08-03 10:31:54', '5665.00', 803, '0.0000', NULL, NULL, '2026-08-03 10:31:54'),
(432, 9, '2026-08-03 10:31:54', '5200.00', 254, '-1.7000', NULL, NULL, '2026-08-03 10:31:54'),
(433, 10, '2026-08-03 10:31:54', '7700.00', 1473, '-1.1600', NULL, NULL, '2026-08-03 10:31:54'),
(434, 11, '2026-08-03 10:31:54', '3695.00', 1704, '5.5700', NULL, NULL, '2026-08-03 10:31:54'),
(435, 12, '2026-08-03 10:31:54', '28100.00', 137, '0.3600', NULL, NULL, '2026-08-03 10:31:54'),
(436, 13, '2026-08-03 10:31:54', '1695.00', 438, '1.8000', NULL, NULL, '2026-08-03 10:31:54'),
(437, 14, '2026-08-03 10:31:54', '5085.00', 1772, '1.7000', NULL, NULL, '2026-08-03 10:31:54'),
(438, 15, '2026-08-03 10:31:54', '16085.00', 423, '-0.8900', NULL, NULL, '2026-08-03 10:31:54'),
(439, 16, '2026-08-03 10:31:54', '69.00', 293673, '4.5500', NULL, NULL, '2026-08-03 10:31:54'),
(440, 17, '2026-08-03 10:31:55', '1980.00', 1505, '0.5100', NULL, NULL, '2026-08-03 10:31:55'),
(441, 18, '2026-08-03 10:31:55', '4505.00', 7743, '0.1100', NULL, NULL, '2026-08-03 10:31:55'),
(442, 19, '2026-08-03 10:31:55', '2280.00', 154, '0.0000', NULL, NULL, '2026-08-03 10:31:55'),
(443, 20, '2026-08-03 10:31:55', '24300.00', 377, '1.2500', NULL, NULL, '2026-08-03 10:31:55'),
(444, 21, '2026-08-03 10:31:55', '16500.00', 1338, '0.0000', NULL, NULL, '2026-08-03 10:31:55'),
(445, 22, '2026-08-03 10:31:55', '2945.00', 1017, '-1.6700', NULL, NULL, '2026-08-03 10:31:55'),
(446, 23, '2026-08-03 10:31:55', '17000.00', 615, '0.0300', NULL, NULL, '2026-08-03 10:31:55'),
(447, 24, '2026-08-03 10:31:55', '3200.00', 1445, '1.9100', NULL, NULL, '2026-08-03 10:31:55'),
(448, 25, '2026-08-03 10:31:55', '9100.00', 335, '1.1100', NULL, NULL, '2026-08-03 10:31:55'),
(449, 26, '2026-08-03 10:31:55', '4550.00', 299, '0.7800', NULL, NULL, '2026-08-03 10:31:55'),
(450, 27, '2026-08-03 10:31:55', '5015.00', 3755, '-2.2400', NULL, NULL, '2026-08-03 10:31:55'),
(451, 28, '2026-08-03 10:31:55', '3630.00', 1428, '-0.4100', NULL, NULL, '2026-08-03 10:31:55'),
(452, 29, '2026-08-03 10:31:55', '11900.00', 537, '0.0000', NULL, NULL, '2026-08-03 10:31:55'),
(453, 30, '2026-08-03 10:31:55', '2690.00', 1729, '0.0000', NULL, NULL, '2026-08-03 10:31:55'),
(454, 31, '2026-08-03 10:31:55', '1500.00', 322, '1.0100', NULL, NULL, '2026-08-03 10:31:55'),
(455, 32, '2026-08-03 10:31:55', '38000.00', 2293, '0.0000', NULL, NULL, '2026-08-03 10:31:55'),
(456, 33, '2026-08-03 10:31:55', '2225.00', 904, '1.1400', NULL, NULL, '2026-08-03 10:31:55'),
(457, 34, '2026-08-03 10:31:55', '8940.00', 947, '-0.6700', NULL, NULL, '2026-08-03 10:31:55'),
(458, 35, '2026-08-03 10:31:55', '6400.00', 0, '0.0000', NULL, NULL, '2026-08-03 10:31:55'),
(459, 36, '2026-08-03 10:31:55', '2185.00', 1908, '-0.6800', NULL, NULL, '2026-08-03 10:31:55'),
(460, 37, '2026-08-03 10:31:55', '38900.00', 12, '3.0500', NULL, NULL, '2026-08-03 10:31:55'),
(461, 38, '2026-08-03 10:31:55', '15495.00', 693, '0.0000', NULL, NULL, '2026-08-03 10:31:55'),
(462, 39, '2026-08-03 10:31:55', '31000.00', 1281, '0.0000', NULL, NULL, '2026-08-03 10:31:55'),
(463, 40, '2026-08-03 10:31:55', '8400.00', 172, '1.1400', NULL, NULL, '2026-08-03 10:31:55'),
(464, 41, '2026-08-03 10:31:55', '7595.00', 3100, '0.4000', NULL, NULL, '2026-08-03 10:31:55'),
(465, 42, '2026-08-03 10:31:55', '2810.00', 2514, '4.8500', NULL, NULL, '2026-08-03 10:31:55'),
(466, 43, '2026-08-03 10:31:55', '23490.00', 238, '1.2500', NULL, NULL, '2026-08-03 10:31:55'),
(467, 44, '2026-08-03 10:31:55', '2965.00', 3932, '-0.3400', NULL, NULL, '2026-08-03 10:31:55'),
(468, 45, '2026-08-03 10:31:55', '3625.00', 640, '-0.8200', NULL, NULL, '2026-08-03 10:31:55'),
(469, 46, '2026-08-03 10:31:55', '51000.00', 0, '0.0000', NULL, NULL, '2026-08-03 10:31:55'),
(470, 47, '2026-08-03 10:31:55', '1940.00', 1641, '-0.2600', NULL, NULL, '2026-08-03 10:31:55'),
(471, 1, '2026-08-03 10:47:31', '3020.00', 1903, '0.6700', NULL, NULL, '2026-08-03 10:47:31'),
(472, 2, '2026-08-03 10:47:31', '7500.00', 7923, '-1.1200', NULL, NULL, '2026-08-03 10:47:31'),
(473, 3, '2026-08-03 10:47:31', '29015.00', 18, '1.2000', NULL, NULL, '2026-08-03 10:47:31'),
(474, 4, '2026-08-03 10:47:31', '1990.00', 195, '5.2900', NULL, NULL, '2026-08-03 10:47:31'),
(475, 5, '2026-08-03 10:47:31', '8675.00', 640, '-0.2900', NULL, NULL, '2026-08-03 10:47:31'),
(476, 6, '2026-08-03 10:47:31', '7200.00', 1912, '1.4100', NULL, NULL, '2026-08-03 10:47:31'),
(477, 7, '2026-08-03 10:47:31', '11100.00', 1763, '2.6400', NULL, NULL, '2026-08-03 10:47:31'),
(478, 8, '2026-08-03 10:47:31', '5660.00', 974, '-0.0900', NULL, NULL, '2026-08-03 10:47:31'),
(479, 9, '2026-08-03 10:47:31', '5200.00', 294, '-1.7000', NULL, NULL, '2026-08-03 10:47:31'),
(480, 10, '2026-08-03 10:47:31', '7670.00', 1477, '-1.5400', NULL, NULL, '2026-08-03 10:47:31'),
(481, 11, '2026-08-03 10:47:31', '3500.00', 2725, '0.0000', NULL, NULL, '2026-08-03 10:47:31'),
(482, 12, '2026-08-03 10:47:31', '28200.00', 151, '0.7100', NULL, NULL, '2026-08-03 10:47:31'),
(483, 13, '2026-08-03 10:47:31', '1695.00', 518, '1.8000', NULL, NULL, '2026-08-03 10:47:31'),
(484, 14, '2026-08-03 10:47:31', '5085.00', 1861, '1.7000', NULL, NULL, '2026-08-03 10:47:31'),
(485, 15, '2026-08-03 10:47:31', '16075.00', 536, '-0.9600', NULL, NULL, '2026-08-03 10:47:31'),
(486, 16, '2026-08-03 10:47:31', '70.00', 337711, '6.0600', NULL, NULL, '2026-08-03 10:47:31'),
(487, 17, '2026-08-03 10:47:31', '1970.00', 1795, '0.0000', NULL, NULL, '2026-08-03 10:47:31'),
(488, 18, '2026-08-03 10:47:31', '4510.00', 7745, '0.2200', NULL, NULL, '2026-08-03 10:47:31'),
(489, 19, '2026-08-03 10:47:31', '2280.00', 156, '0.0000', NULL, NULL, '2026-08-03 10:47:31'),
(490, 20, '2026-08-03 10:47:31', '24300.00', 377, '1.2500', NULL, NULL, '2026-08-03 10:47:31'),
(491, 21, '2026-08-03 10:47:31', '16490.00', 1428, '-0.0600', NULL, NULL, '2026-08-03 10:47:31'),
(492, 22, '2026-08-03 10:47:31', '2945.00', 1045, '-1.6700', NULL, NULL, '2026-08-03 10:47:31'),
(493, 23, '2026-08-03 10:47:31', '16995.00', 630, '0.0000', NULL, NULL, '2026-08-03 10:47:31'),
(494, 24, '2026-08-03 10:47:31', '3120.00', 1845, '-0.6400', NULL, NULL, '2026-08-03 10:47:31'),
(495, 25, '2026-08-03 10:47:31', '9000.00', 346, '0.0000', NULL, NULL, '2026-08-03 10:47:31'),
(496, 26, '2026-08-03 10:47:31', '4550.00', 299, '0.7800', NULL, NULL, '2026-08-03 10:47:31'),
(497, 27, '2026-08-03 10:47:31', '5000.00', 9791, '-2.5300', NULL, NULL, '2026-08-03 10:47:31'),
(498, 28, '2026-08-03 10:47:31', '3640.00', 1562, '-0.1400', NULL, NULL, '2026-08-03 10:47:31'),
(499, 29, '2026-08-03 10:47:31', '11900.00', 537, '0.0000', NULL, NULL, '2026-08-03 10:47:31'),
(500, 30, '2026-08-03 10:47:31', '2690.00', 1854, '0.0000', NULL, NULL, '2026-08-03 10:47:31'),
(501, 31, '2026-08-03 10:47:31', '1485.00', 872, '0.0000', NULL, NULL, '2026-08-03 10:47:31'),
(502, 32, '2026-08-03 10:47:31', '38050.00', 3316, '0.1300', NULL, NULL, '2026-08-03 10:47:31'),
(503, 33, '2026-08-03 10:47:31', '2225.00', 1196, '1.1400', NULL, NULL, '2026-08-03 10:47:31'),
(504, 34, '2026-08-03 10:47:31', '8940.00', 1049, '-0.6700', NULL, NULL, '2026-08-03 10:47:31'),
(505, 35, '2026-08-03 10:47:31', '6400.00', 0, '0.0000', NULL, NULL, '2026-08-03 10:47:31'),
(506, 36, '2026-08-03 10:47:31', '2195.00', 1938, '-0.2300', NULL, NULL, '2026-08-03 10:47:31'),
(507, 37, '2026-08-03 10:47:31', '38900.00', 12, '3.0500', NULL, NULL, '2026-08-03 10:47:31'),
(508, 38, '2026-08-03 10:47:31', '15500.00', 794, '0.0300', NULL, NULL, '2026-08-03 10:47:31'),
(509, 39, '2026-08-03 10:47:31', '31000.00', 1377, '0.0000', NULL, NULL, '2026-08-03 10:47:31'),
(510, 40, '2026-08-03 10:47:31', '8330.00', 180, '0.3000', NULL, NULL, '2026-08-03 10:47:31'),
(511, 41, '2026-08-03 10:47:31', '7595.00', 3120, '0.4000', NULL, NULL, '2026-08-03 10:47:31'),
(512, 42, '2026-08-03 10:47:31', '2815.00', 3147, '5.0400', NULL, NULL, '2026-08-03 10:47:31'),
(513, 43, '2026-08-03 10:47:31', '23490.00', 273, '1.2500', NULL, NULL, '2026-08-03 10:47:31'),
(514, 44, '2026-08-03 10:47:31', '2975.00', 4175, '0.0000', NULL, NULL, '2026-08-03 10:47:31'),
(515, 45, '2026-08-03 10:47:31', '3625.00', 680, '-0.8200', NULL, NULL, '2026-08-03 10:47:31'),
(516, 46, '2026-08-03 10:47:31', '51000.00', 0, '0.0000', NULL, NULL, '2026-08-03 10:47:31'),
(517, 47, '2026-08-03 10:47:31', '1915.00', 1810, '-1.5400', NULL, NULL, '2026-08-03 10:47:31'),
(518, 1, '2026-08-03 11:02:02', '3020.00', 1903, '0.6700', NULL, NULL, '2026-08-03 11:02:02'),
(519, 2, '2026-08-03 11:02:02', '7500.00', 7923, '-1.1200', NULL, NULL, '2026-08-03 11:02:02'),
(520, 3, '2026-08-03 11:02:02', '29015.00', 18, '1.2000', NULL, NULL, '2026-08-03 11:02:02'),
(521, 4, '2026-08-03 11:02:02', '1990.00', 195, '5.2900', NULL, NULL, '2026-08-03 11:02:02'),
(522, 5, '2026-08-03 11:02:02', '8675.00', 640, '-0.2900', NULL, NULL, '2026-08-03 11:02:02'),
(523, 6, '2026-08-03 11:02:02', '7200.00', 1912, '1.4100', NULL, NULL, '2026-08-03 11:02:02'),
(524, 7, '2026-08-03 11:02:02', '11100.00', 1763, '2.6400', NULL, NULL, '2026-08-03 11:02:02'),
(525, 8, '2026-08-03 11:02:02', '5660.00', 974, '-0.0900', NULL, NULL, '2026-08-03 11:02:02'),
(526, 9, '2026-08-03 11:02:02', '5200.00', 294, '-1.7000', NULL, NULL, '2026-08-03 11:02:02'),
(527, 10, '2026-08-03 11:02:02', '7670.00', 1477, '-1.5400', NULL, NULL, '2026-08-03 11:02:02'),
(528, 11, '2026-08-03 11:02:02', '3500.00', 2725, '0.0000', NULL, NULL, '2026-08-03 11:02:02'),
(529, 12, '2026-08-03 11:02:02', '28200.00', 151, '0.7100', NULL, NULL, '2026-08-03 11:02:02'),
(530, 13, '2026-08-03 11:02:02', '1695.00', 518, '1.8000', NULL, NULL, '2026-08-03 11:02:02'),
(531, 14, '2026-08-03 11:02:02', '5085.00', 1861, '1.7000', NULL, NULL, '2026-08-03 11:02:02'),
(532, 15, '2026-08-03 11:02:02', '16075.00', 536, '-0.9600', NULL, NULL, '2026-08-03 11:02:02'),
(533, 16, '2026-08-03 11:02:02', '70.00', 337711, '6.0600', NULL, NULL, '2026-08-03 11:02:02'),
(534, 17, '2026-08-03 11:02:02', '1970.00', 1795, '0.0000', NULL, NULL, '2026-08-03 11:02:02'),
(535, 18, '2026-08-03 11:02:02', '4510.00', 7745, '0.2200', NULL, NULL, '2026-08-03 11:02:02');
INSERT INTO `intraday_quotes` (`id`, `company_id`, `quote_datetime`, `price`, `volume`, `variation_percent`, `bid_price`, `ask_price`, `created_at`) VALUES
(536, 19, '2026-08-03 11:02:02', '2280.00', 156, '0.0000', NULL, NULL, '2026-08-03 11:02:02'),
(537, 20, '2026-08-03 11:02:02', '24300.00', 377, '1.2500', NULL, NULL, '2026-08-03 11:02:02'),
(538, 21, '2026-08-03 11:02:02', '16490.00', 1428, '-0.0600', NULL, NULL, '2026-08-03 11:02:02'),
(539, 22, '2026-08-03 11:02:02', '2945.00', 1045, '-1.6700', NULL, NULL, '2026-08-03 11:02:02'),
(540, 23, '2026-08-03 11:02:02', '16995.00', 630, '0.0000', NULL, NULL, '2026-08-03 11:02:02'),
(541, 24, '2026-08-03 11:02:02', '3120.00', 1845, '-0.6400', NULL, NULL, '2026-08-03 11:02:02'),
(542, 25, '2026-08-03 11:02:02', '9000.00', 346, '0.0000', NULL, NULL, '2026-08-03 11:02:02'),
(543, 26, '2026-08-03 11:02:02', '4550.00', 299, '0.7800', NULL, NULL, '2026-08-03 11:02:02'),
(544, 27, '2026-08-03 11:02:02', '5000.00', 9791, '-2.5300', NULL, NULL, '2026-08-03 11:02:02'),
(545, 28, '2026-08-03 11:02:02', '3640.00', 1562, '-0.1400', NULL, NULL, '2026-08-03 11:02:02'),
(546, 29, '2026-08-03 11:02:02', '11900.00', 537, '0.0000', NULL, NULL, '2026-08-03 11:02:02'),
(547, 30, '2026-08-03 11:02:02', '2690.00', 1854, '0.0000', NULL, NULL, '2026-08-03 11:02:02'),
(548, 31, '2026-08-03 11:02:02', '1485.00', 872, '0.0000', NULL, NULL, '2026-08-03 11:02:02'),
(549, 32, '2026-08-03 11:02:02', '38050.00', 3316, '0.1300', NULL, NULL, '2026-08-03 11:02:02'),
(550, 33, '2026-08-03 11:02:03', '2225.00', 1196, '1.1400', NULL, NULL, '2026-08-03 11:02:03'),
(551, 34, '2026-08-03 11:02:03', '8940.00', 1049, '-0.6700', NULL, NULL, '2026-08-03 11:02:03'),
(552, 35, '2026-08-03 11:02:03', '6400.00', 0, '0.0000', NULL, NULL, '2026-08-03 11:02:03'),
(553, 36, '2026-08-03 11:02:03', '2195.00', 1938, '-0.2300', NULL, NULL, '2026-08-03 11:02:03'),
(554, 37, '2026-08-03 11:02:03', '38900.00', 12, '3.0500', NULL, NULL, '2026-08-03 11:02:03'),
(555, 38, '2026-08-03 11:02:03', '15500.00', 794, '0.0300', NULL, NULL, '2026-08-03 11:02:03'),
(556, 39, '2026-08-03 11:02:03', '31000.00', 1377, '0.0000', NULL, NULL, '2026-08-03 11:02:03'),
(557, 40, '2026-08-03 11:02:03', '8330.00', 180, '0.3000', NULL, NULL, '2026-08-03 11:02:03'),
(558, 41, '2026-08-03 11:02:03', '7595.00', 3120, '0.4000', NULL, NULL, '2026-08-03 11:02:03'),
(559, 42, '2026-08-03 11:02:03', '2815.00', 3147, '5.0400', NULL, NULL, '2026-08-03 11:02:03'),
(560, 43, '2026-08-03 11:02:03', '23490.00', 273, '1.2500', NULL, NULL, '2026-08-03 11:02:03'),
(561, 44, '2026-08-03 11:02:03', '2975.00', 4175, '0.0000', NULL, NULL, '2026-08-03 11:02:03'),
(562, 45, '2026-08-03 11:02:04', '3625.00', 680, '-0.8200', NULL, NULL, '2026-08-03 11:02:04'),
(563, 46, '2026-08-03 11:02:04', '51000.00', 0, '0.0000', NULL, NULL, '2026-08-03 11:02:04'),
(564, 47, '2026-08-03 11:02:04', '1915.00', 1810, '-1.5400', NULL, NULL, '2026-08-03 11:02:04'),
(565, 1, '2026-08-03 11:16:31', '3020.00', 2112, '0.6700', NULL, NULL, '2026-08-03 11:16:31'),
(566, 2, '2026-08-03 11:16:31', '7500.00', 8287, '-1.1200', NULL, NULL, '2026-08-03 11:16:31'),
(567, 3, '2026-08-03 11:16:31', '29015.00', 42, '1.2000', NULL, NULL, '2026-08-03 11:16:31'),
(568, 4, '2026-08-03 11:16:31', '1910.00', 1255, '1.0600', NULL, NULL, '2026-08-03 11:16:31'),
(569, 5, '2026-08-03 11:16:31', '8690.00', 1067, '-0.1100', NULL, NULL, '2026-08-03 11:16:31'),
(570, 6, '2026-08-03 11:16:31', '7150.00', 1952, '0.7000', NULL, NULL, '2026-08-03 11:16:31'),
(571, 7, '2026-08-03 11:16:31', '11245.00', 2242, '3.9800', NULL, NULL, '2026-08-03 11:16:31'),
(572, 8, '2026-08-03 11:16:31', '5665.00', 1023, '0.0000', NULL, NULL, '2026-08-03 11:16:31'),
(573, 9, '2026-08-03 11:16:31', '5205.00', 345, '-1.6100', NULL, NULL, '2026-08-03 11:16:31'),
(574, 10, '2026-08-03 11:16:31', '7700.00', 2066, '-1.1600', NULL, NULL, '2026-08-03 11:16:31'),
(575, 11, '2026-08-03 11:16:31', '3500.00', 2725, '0.0000', NULL, NULL, '2026-08-03 11:16:31'),
(576, 12, '2026-08-03 11:16:31', '28490.00', 163, '1.7500', NULL, NULL, '2026-08-03 11:16:31'),
(577, 13, '2026-08-03 11:16:31', '1695.00', 1965, '1.8000', NULL, NULL, '2026-08-03 11:16:31'),
(578, 14, '2026-08-03 11:16:31', '5090.00', 3148, '1.8000', NULL, NULL, '2026-08-03 11:16:31'),
(579, 15, '2026-08-03 11:16:31', '16200.00', 935, '-0.1800', NULL, NULL, '2026-08-03 11:16:31'),
(580, 16, '2026-08-03 11:16:31', '69.00', 424133, '4.5500', NULL, NULL, '2026-08-03 11:16:31'),
(581, 17, '2026-08-03 11:16:31', '1980.00', 1880, '0.5100', NULL, NULL, '2026-08-03 11:16:31'),
(582, 18, '2026-08-03 11:16:31', '4800.00', 8033, '6.6700', NULL, NULL, '2026-08-03 11:16:31'),
(583, 19, '2026-08-03 11:16:31', '2280.00', 180, '0.0000', NULL, NULL, '2026-08-03 11:16:31'),
(584, 20, '2026-08-03 11:16:31', '24000.00', 453, '0.0000', NULL, NULL, '2026-08-03 11:16:31'),
(585, 21, '2026-08-03 11:16:31', '16490.00', 1443, '-0.0600', NULL, NULL, '2026-08-03 11:16:31'),
(586, 22, '2026-08-03 11:16:31', '2800.00', 1759, '-6.5100', NULL, NULL, '2026-08-03 11:16:31'),
(587, 23, '2026-08-03 11:16:31', '16995.00', 649, '0.0000', NULL, NULL, '2026-08-03 11:16:31'),
(588, 24, '2026-08-03 11:16:31', '3120.00', 2026, '-0.6400', NULL, NULL, '2026-08-03 11:16:31'),
(589, 25, '2026-08-03 11:16:31', '8990.00', 386, '-0.1100', NULL, NULL, '2026-08-03 11:16:31'),
(590, 26, '2026-08-03 11:16:31', '4550.00', 299, '0.7800', NULL, NULL, '2026-08-03 11:16:31'),
(591, 27, '2026-08-03 11:16:31', '5005.00', 15938, '-2.4400', NULL, NULL, '2026-08-03 11:16:31'),
(592, 28, '2026-08-03 11:16:31', '3640.00', 1954, '-0.1400', NULL, NULL, '2026-08-03 11:16:31'),
(593, 29, '2026-08-03 11:16:31', '11900.00', 559, '0.0000', NULL, NULL, '2026-08-03 11:16:31'),
(594, 30, '2026-08-03 11:16:31', '2700.00', 2261, '0.3700', NULL, NULL, '2026-08-03 11:16:31'),
(595, 31, '2026-08-03 11:16:31', '1495.00', 1067, '0.6700', NULL, NULL, '2026-08-03 11:16:31'),
(596, 32, '2026-08-03 11:16:31', '38000.00', 3975, '0.0000', NULL, NULL, '2026-08-03 11:16:31'),
(597, 33, '2026-08-03 11:16:31', '2225.00', 1636, '1.1400', NULL, NULL, '2026-08-03 11:16:31'),
(598, 34, '2026-08-03 11:16:31', '8930.00', 1356, '-0.7800', NULL, NULL, '2026-08-03 11:16:31'),
(599, 35, '2026-08-03 11:16:31', '6400.00', 1, '0.0000', NULL, NULL, '2026-08-03 11:16:31'),
(600, 36, '2026-08-03 11:16:31', '2185.00', 1979, '-0.6800', NULL, NULL, '2026-08-03 11:16:31'),
(601, 37, '2026-08-03 11:16:31', '38800.00', 16, '2.7800', NULL, NULL, '2026-08-03 11:16:31'),
(602, 38, '2026-08-03 11:16:31', '15500.00', 1226, '0.0300', NULL, NULL, '2026-08-03 11:16:31'),
(603, 39, '2026-08-03 11:16:31', '31350.00', 2207, '1.1300', NULL, NULL, '2026-08-03 11:16:31'),
(604, 40, '2026-08-03 11:16:31', '8350.00', 181, '0.5400', NULL, NULL, '2026-08-03 11:16:31'),
(605, 41, '2026-08-03 11:16:31', '7565.00', 3245, '0.0000', NULL, NULL, '2026-08-03 11:16:31'),
(606, 42, '2026-08-03 11:16:31', '2845.00', 3498, '6.1600', NULL, NULL, '2026-08-03 11:16:31'),
(607, 43, '2026-08-03 11:16:31', '23500.00', 535, '1.2900', NULL, NULL, '2026-08-03 11:16:31'),
(608, 44, '2026-08-03 11:16:31', '2975.00', 5855, '0.0000', NULL, NULL, '2026-08-03 11:16:31'),
(609, 45, '2026-08-03 11:16:31', '3625.00', 1168, '-0.8200', NULL, NULL, '2026-08-03 11:16:31'),
(610, 46, '2026-08-03 11:16:32', '51035.00', 2, '0.0700', NULL, NULL, '2026-08-03 11:16:32'),
(611, 47, '2026-08-03 11:16:32', '1945.00', 1967, '0.0000', NULL, NULL, '2026-08-03 11:16:32'),
(612, 1, '2026-08-03 11:16:40', '3020.00', 2112, '0.6700', NULL, NULL, '2026-08-03 11:16:40'),
(613, 2, '2026-08-03 11:16:40', '7500.00', 8287, '-1.1200', NULL, NULL, '2026-08-03 11:16:40'),
(614, 3, '2026-08-03 11:16:40', '29015.00', 42, '1.2000', NULL, NULL, '2026-08-03 11:16:40'),
(615, 4, '2026-08-03 11:16:40', '1910.00', 1255, '1.0600', NULL, NULL, '2026-08-03 11:16:40'),
(616, 5, '2026-08-03 11:16:40', '8690.00', 1067, '-0.1100', NULL, NULL, '2026-08-03 11:16:40'),
(617, 6, '2026-08-03 11:16:40', '7150.00', 1952, '0.7000', NULL, NULL, '2026-08-03 11:16:40'),
(618, 7, '2026-08-03 11:16:40', '11245.00', 2242, '3.9800', NULL, NULL, '2026-08-03 11:16:40'),
(619, 8, '2026-08-03 11:16:40', '5665.00', 1023, '0.0000', NULL, NULL, '2026-08-03 11:16:40'),
(620, 9, '2026-08-03 11:16:40', '5205.00', 345, '-1.6100', NULL, NULL, '2026-08-03 11:16:40'),
(621, 10, '2026-08-03 11:16:40', '7700.00', 2066, '-1.1600', NULL, NULL, '2026-08-03 11:16:40'),
(622, 11, '2026-08-03 11:16:40', '3500.00', 2725, '0.0000', NULL, NULL, '2026-08-03 11:16:40'),
(623, 12, '2026-08-03 11:16:40', '28490.00', 163, '1.7500', NULL, NULL, '2026-08-03 11:16:40'),
(624, 13, '2026-08-03 11:16:40', '1695.00', 1965, '1.8000', NULL, NULL, '2026-08-03 11:16:40'),
(625, 14, '2026-08-03 11:16:40', '5090.00', 3148, '1.8000', NULL, NULL, '2026-08-03 11:16:40'),
(626, 15, '2026-08-03 11:16:40', '16200.00', 935, '-0.1800', NULL, NULL, '2026-08-03 11:16:40'),
(627, 16, '2026-08-03 11:16:40', '69.00', 424133, '4.5500', NULL, NULL, '2026-08-03 11:16:40'),
(628, 17, '2026-08-03 11:16:40', '1980.00', 1880, '0.5100', NULL, NULL, '2026-08-03 11:16:40'),
(629, 18, '2026-08-03 11:16:40', '4800.00', 8033, '6.6700', NULL, NULL, '2026-08-03 11:16:40'),
(630, 19, '2026-08-03 11:16:40', '2280.00', 180, '0.0000', NULL, NULL, '2026-08-03 11:16:40'),
(631, 20, '2026-08-03 11:16:40', '24000.00', 453, '0.0000', NULL, NULL, '2026-08-03 11:16:40'),
(632, 21, '2026-08-03 11:16:40', '16490.00', 1443, '-0.0600', NULL, NULL, '2026-08-03 11:16:40'),
(633, 22, '2026-08-03 11:16:40', '2800.00', 1759, '-6.5100', NULL, NULL, '2026-08-03 11:16:40'),
(634, 23, '2026-08-03 11:16:40', '16995.00', 649, '0.0000', NULL, NULL, '2026-08-03 11:16:40'),
(635, 24, '2026-08-03 11:16:40', '3120.00', 2026, '-0.6400', NULL, NULL, '2026-08-03 11:16:40'),
(636, 25, '2026-08-03 11:16:40', '8990.00', 386, '-0.1100', NULL, NULL, '2026-08-03 11:16:40'),
(637, 26, '2026-08-03 11:16:40', '4550.00', 299, '0.7800', NULL, NULL, '2026-08-03 11:16:40'),
(638, 27, '2026-08-03 11:16:40', '5005.00', 15938, '-2.4400', NULL, NULL, '2026-08-03 11:16:40'),
(639, 28, '2026-08-03 11:16:40', '3640.00', 1954, '-0.1400', NULL, NULL, '2026-08-03 11:16:40'),
(640, 29, '2026-08-03 11:16:40', '11900.00', 559, '0.0000', NULL, NULL, '2026-08-03 11:16:40'),
(641, 30, '2026-08-03 11:16:40', '2700.00', 2261, '0.3700', NULL, NULL, '2026-08-03 11:16:40'),
(642, 31, '2026-08-03 11:16:40', '1495.00', 1067, '0.6700', NULL, NULL, '2026-08-03 11:16:40'),
(643, 32, '2026-08-03 11:16:40', '38000.00', 3975, '0.0000', NULL, NULL, '2026-08-03 11:16:40'),
(644, 33, '2026-08-03 11:16:40', '2225.00', 1636, '1.1400', NULL, NULL, '2026-08-03 11:16:40'),
(645, 34, '2026-08-03 11:16:40', '8930.00', 1356, '-0.7800', NULL, NULL, '2026-08-03 11:16:40'),
(646, 35, '2026-08-03 11:16:40', '6400.00', 1, '0.0000', NULL, NULL, '2026-08-03 11:16:40'),
(647, 36, '2026-08-03 11:16:40', '2185.00', 1979, '-0.6800', NULL, NULL, '2026-08-03 11:16:40'),
(648, 37, '2026-08-03 11:16:40', '38800.00', 16, '2.7800', NULL, NULL, '2026-08-03 11:16:40'),
(649, 38, '2026-08-03 11:16:40', '15500.00', 1226, '0.0300', NULL, NULL, '2026-08-03 11:16:40'),
(650, 39, '2026-08-03 11:16:40', '31350.00', 2207, '1.1300', NULL, NULL, '2026-08-03 11:16:40'),
(651, 40, '2026-08-03 11:16:40', '8350.00', 181, '0.5400', NULL, NULL, '2026-08-03 11:16:40'),
(652, 41, '2026-08-03 11:16:40', '7565.00', 3245, '0.0000', NULL, NULL, '2026-08-03 11:16:40'),
(653, 42, '2026-08-03 11:16:40', '2845.00', 3498, '6.1600', NULL, NULL, '2026-08-03 11:16:40'),
(654, 43, '2026-08-03 11:16:40', '23500.00', 535, '1.2900', NULL, NULL, '2026-08-03 11:16:40'),
(655, 44, '2026-08-03 11:16:40', '2975.00', 5855, '0.0000', NULL, NULL, '2026-08-03 11:16:40'),
(656, 45, '2026-08-03 11:16:40', '3625.00', 1168, '-0.8200', NULL, NULL, '2026-08-03 11:16:40'),
(657, 46, '2026-08-03 11:16:40', '51035.00', 2, '0.0700', NULL, NULL, '2026-08-03 11:16:40'),
(658, 47, '2026-08-03 11:16:40', '1945.00', 1967, '0.0000', NULL, NULL, '2026-08-03 11:16:40'),
(659, 1, '2026-08-03 11:31:24', '3000.00', 2317, '0.0000', NULL, NULL, '2026-08-03 11:31:24'),
(660, 2, '2026-08-03 11:31:24', '7450.00', 9087, '-1.7800', NULL, NULL, '2026-08-03 11:31:24'),
(661, 3, '2026-08-03 11:31:24', '29015.00', 42, '1.2000', NULL, NULL, '2026-08-03 11:31:24'),
(662, 4, '2026-08-03 11:31:24', '1910.00', 1258, '1.0600', NULL, NULL, '2026-08-03 11:31:24'),
(663, 5, '2026-08-03 11:31:24', '8600.00', 2567, '-1.1500', NULL, NULL, '2026-08-03 11:31:24'),
(664, 6, '2026-08-03 11:31:24', '7190.00', 1961, '1.2700', NULL, NULL, '2026-08-03 11:31:24'),
(665, 7, '2026-08-03 11:31:24', '11100.00', 2303, '2.6400', NULL, NULL, '2026-08-03 11:31:24'),
(666, 8, '2026-08-03 11:31:24', '5665.00', 1226, '0.0000', NULL, NULL, '2026-08-03 11:31:24'),
(667, 9, '2026-08-03 11:31:24', '5200.00', 692, '-1.7000', NULL, NULL, '2026-08-03 11:31:24'),
(668, 10, '2026-08-03 11:31:24', '7660.00', 2169, '-1.6700', NULL, NULL, '2026-08-03 11:31:24'),
(669, 11, '2026-08-03 11:31:24', '3505.00', 2811, '0.1400', NULL, NULL, '2026-08-03 11:31:24'),
(670, 12, '2026-08-03 11:31:24', '28000.00', 403, '0.0000', NULL, NULL, '2026-08-03 11:31:24'),
(671, 13, '2026-08-03 11:31:24', '1695.00', 2105, '1.8000', NULL, NULL, '2026-08-03 11:31:24'),
(672, 14, '2026-08-03 11:31:24', '5055.00', 3485, '1.1000', NULL, NULL, '2026-08-03 11:31:24'),
(673, 15, '2026-08-03 11:31:24', '16200.00', 938, '-0.1800', NULL, NULL, '2026-08-03 11:31:24'),
(674, 16, '2026-08-03 11:31:24', '69.00', 469177, '4.5500', NULL, NULL, '2026-08-03 11:31:24'),
(675, 17, '2026-08-03 11:31:25', '1980.00', 1919, '0.5100', NULL, NULL, '2026-08-03 11:31:25'),
(676, 18, '2026-08-03 11:31:25', '4800.00', 8036, '6.6700', NULL, NULL, '2026-08-03 11:31:25'),
(677, 19, '2026-08-03 11:31:25', '2180.00', 299, '-4.3900', NULL, NULL, '2026-08-03 11:31:25'),
(678, 20, '2026-08-03 11:31:25', '24000.00', 453, '0.0000', NULL, NULL, '2026-08-03 11:31:25'),
(679, 21, '2026-08-03 11:31:25', '16490.00', 1443, '-0.0600', NULL, NULL, '2026-08-03 11:31:25'),
(680, 22, '2026-08-03 11:31:25', '2900.00', 1770, '-3.1700', NULL, NULL, '2026-08-03 11:31:25'),
(681, 23, '2026-08-03 11:31:25', '16995.00', 650, '0.0000', NULL, NULL, '2026-08-03 11:31:25'),
(682, 24, '2026-08-03 11:31:25', '3195.00', 2033, '1.7500', NULL, NULL, '2026-08-03 11:31:25'),
(683, 25, '2026-08-03 11:31:25', '8990.00', 386, '-0.1100', NULL, NULL, '2026-08-03 11:31:25'),
(684, 26, '2026-08-03 11:31:25', '4350.00', 314, '-3.6500', NULL, NULL, '2026-08-03 11:31:25'),
(685, 27, '2026-08-03 11:31:25', '5070.00', 16007, '-1.1700', NULL, NULL, '2026-08-03 11:31:25'),
(686, 28, '2026-08-03 11:31:25', '3630.00', 2000, '-0.4100', NULL, NULL, '2026-08-03 11:31:25'),
(687, 29, '2026-08-03 11:31:25', '11900.00', 568, '0.0000', NULL, NULL, '2026-08-03 11:31:25'),
(688, 30, '2026-08-03 11:31:25', '2695.00', 2434, '0.1900', NULL, NULL, '2026-08-03 11:31:25'),
(689, 31, '2026-08-03 11:31:25', '1480.00', 1857, '-0.3400', NULL, NULL, '2026-08-03 11:31:25'),
(690, 32, '2026-08-03 11:31:25', '38000.00', 3987, '0.0000', NULL, NULL, '2026-08-03 11:31:25'),
(691, 33, '2026-08-03 11:31:25', '2225.00', 1836, '1.1400', NULL, NULL, '2026-08-03 11:31:25'),
(692, 34, '2026-08-03 11:31:25', '8910.00', 1701, '-1.0000', NULL, NULL, '2026-08-03 11:31:25'),
(693, 35, '2026-08-03 11:31:25', '6400.00', 1, '0.0000', NULL, NULL, '2026-08-03 11:31:25'),
(694, 36, '2026-08-03 11:31:25', '2200.00', 2368, '0.0000', NULL, NULL, '2026-08-03 11:31:25'),
(695, 37, '2026-08-03 11:31:25', '38800.00', 17, '2.7800', NULL, NULL, '2026-08-03 11:31:25'),
(696, 38, '2026-08-03 11:31:25', '15500.00', 1262, '0.0300', NULL, NULL, '2026-08-03 11:31:25'),
(697, 39, '2026-08-03 11:31:25', '31000.00', 2815, '0.0000', NULL, NULL, '2026-08-03 11:31:25'),
(698, 40, '2026-08-03 11:31:25', '8350.00', 181, '0.5400', NULL, NULL, '2026-08-03 11:31:26'),
(699, 41, '2026-08-03 11:31:26', '7565.00', 3245, '0.0000', NULL, NULL, '2026-08-03 11:31:26'),
(700, 42, '2026-08-03 11:31:26', '2845.00', 3582, '6.1600', NULL, NULL, '2026-08-03 11:31:26'),
(701, 43, '2026-08-03 11:31:26', '23205.00', 1535, '0.0200', NULL, NULL, '2026-08-03 11:31:26'),
(702, 44, '2026-08-03 11:31:26', '2995.00', 5905, '0.6700', NULL, NULL, '2026-08-03 11:31:26'),
(703, 45, '2026-08-03 11:31:26', '3625.00', 1168, '-0.8200', NULL, NULL, '2026-08-03 11:31:26'),
(704, 46, '2026-08-03 11:31:26', '51035.00', 2, '0.0700', NULL, NULL, '2026-08-03 11:31:26'),
(705, 47, '2026-08-03 11:31:26', '1945.00', 1995, '0.0000', NULL, NULL, '2026-08-03 11:31:26'),
(706, 1, '2026-08-03 11:46:32', '3000.00', 2400, '0.0000', NULL, NULL, '2026-08-03 11:46:32'),
(707, 2, '2026-08-03 11:46:32', '7585.00', 10727, '0.0000', NULL, NULL, '2026-08-03 11:46:32'),
(708, 3, '2026-08-03 11:46:32', '29015.00', 42, '1.2000', NULL, NULL, '2026-08-03 11:46:32'),
(709, 4, '2026-08-03 11:46:32', '1965.00', 1262, '3.9700', NULL, NULL, '2026-08-03 11:46:32'),
(710, 5, '2026-08-03 11:46:32', '8690.00', 2601, '-0.1100', NULL, NULL, '2026-08-03 11:46:32'),
(711, 6, '2026-08-03 11:46:32', '7190.00', 2008, '1.2700', NULL, NULL, '2026-08-03 11:46:32'),
(712, 7, '2026-08-03 11:46:32', '11000.00', 3704, '1.7100', NULL, NULL, '2026-08-03 11:46:32'),
(713, 8, '2026-08-03 11:46:32', '5660.00', 1315, '-0.0900', NULL, NULL, '2026-08-03 11:46:32'),
(714, 9, '2026-08-03 11:46:32', '5200.00', 692, '-1.7000', NULL, NULL, '2026-08-03 11:46:32'),
(715, 10, '2026-08-03 11:46:32', '7660.00', 2197, '-1.6700', NULL, NULL, '2026-08-03 11:46:32'),
(716, 11, '2026-08-03 11:46:32', '3500.00', 3817, '0.0000', NULL, NULL, '2026-08-03 11:46:32'),
(717, 12, '2026-08-03 11:46:32', '28250.00', 413, '0.8900', NULL, NULL, '2026-08-03 11:46:32'),
(718, 13, '2026-08-03 11:46:32', '1690.00', 2131, '1.5000', NULL, NULL, '2026-08-03 11:46:32'),
(719, 14, '2026-08-03 11:46:33', '5055.00', 3890, '1.1000', NULL, NULL, '2026-08-03 11:46:33'),
(720, 15, '2026-08-03 11:46:33', '16200.00', 941, '-0.1800', NULL, NULL, '2026-08-03 11:46:33'),
(721, 16, '2026-08-03 11:46:33', '70.00', 486897, '6.0600', NULL, NULL, '2026-08-03 11:46:33'),
(722, 17, '2026-08-03 11:46:33', '1980.00', 1919, '0.5100', NULL, NULL, '2026-08-03 11:46:33'),
(723, 18, '2026-08-03 11:46:33', '4795.00', 8136, '6.5600', NULL, NULL, '2026-08-03 11:46:33'),
(724, 19, '2026-08-03 11:46:33', '2185.00', 312, '-4.1700', NULL, NULL, '2026-08-03 11:46:33'),
(725, 20, '2026-08-03 11:46:33', '24000.00', 453, '0.0000', NULL, NULL, '2026-08-03 11:46:33'),
(726, 21, '2026-08-03 11:46:33', '15805.00', 1616, '-4.2100', NULL, NULL, '2026-08-03 11:46:33'),
(727, 22, '2026-08-03 11:46:33', '2900.00', 1801, '-3.1700', NULL, NULL, '2026-08-03 11:46:33'),
(728, 23, '2026-08-03 11:46:33', '16995.00', 838, '0.0000', NULL, NULL, '2026-08-03 11:46:33'),
(729, 24, '2026-08-03 11:46:33', '3140.00', 2038, '0.0000', NULL, NULL, '2026-08-03 11:46:33'),
(730, 25, '2026-08-03 11:46:33', '9000.00', 600, '0.0000', NULL, NULL, '2026-08-03 11:46:33'),
(731, 26, '2026-08-03 11:46:33', '4350.00', 314, '-3.6500', NULL, NULL, '2026-08-03 11:46:33'),
(732, 27, '2026-08-03 11:46:33', '5005.00', 16547, '-2.4400', NULL, NULL, '2026-08-03 11:46:33'),
(733, 28, '2026-08-03 11:46:33', '3630.00', 2005, '-0.4100', NULL, NULL, '2026-08-03 11:46:33'),
(734, 29, '2026-08-03 11:46:33', '11900.00', 568, '0.0000', NULL, NULL, '2026-08-03 11:46:33'),
(735, 30, '2026-08-03 11:46:33', '2695.00', 2458, '0.1900', NULL, NULL, '2026-08-03 11:46:33'),
(736, 31, '2026-08-03 11:46:33', '1450.00', 2058, '-2.3600', NULL, NULL, '2026-08-03 11:46:33'),
(737, 32, '2026-08-03 11:46:33', '38000.00', 4027, '0.0000', NULL, NULL, '2026-08-03 11:46:33'),
(738, 33, '2026-08-03 11:46:33', '2295.00', 4356, '4.3200', NULL, NULL, '2026-08-03 11:46:33'),
(739, 34, '2026-08-03 11:46:33', '8910.00', 1861, '-1.0000', NULL, NULL, '2026-08-03 11:46:33'),
(740, 35, '2026-08-03 11:46:33', '6400.00', 1, '0.0000', NULL, NULL, '2026-08-03 11:46:33'),
(741, 36, '2026-08-03 11:46:33', '2200.00', 2368, '0.0000', NULL, NULL, '2026-08-03 11:46:33'),
(742, 37, '2026-08-03 11:46:33', '37800.00', 38, '0.1300', NULL, NULL, '2026-08-03 11:46:33'),
(743, 38, '2026-08-03 11:46:33', '15500.00', 1264, '0.0300', NULL, NULL, '2026-08-03 11:46:33'),
(744, 39, '2026-08-03 11:46:33', '31000.00', 2890, '0.0000', NULL, NULL, '2026-08-03 11:46:33'),
(745, 40, '2026-08-03 11:46:33', '8350.00', 181, '0.5400', NULL, NULL, '2026-08-03 11:46:33'),
(746, 41, '2026-08-03 11:46:33', '7565.00', 3245, '0.0000', NULL, NULL, '2026-08-03 11:46:33'),
(747, 42, '2026-08-03 11:46:33', '2840.00', 3640, '5.9700', NULL, NULL, '2026-08-03 11:46:33'),
(748, 43, '2026-08-03 11:46:33', '23205.00', 1535, '0.0200', NULL, NULL, '2026-08-03 11:46:33'),
(749, 44, '2026-08-03 11:46:33', '3000.00', 5990, '0.8400', NULL, NULL, '2026-08-03 11:46:33'),
(750, 45, '2026-08-03 11:46:33', '3625.00', 1173, '-0.8200', NULL, NULL, '2026-08-03 11:46:33'),
(751, 46, '2026-08-03 11:46:33', '51035.00', 2, '0.0700', NULL, NULL, '2026-08-03 11:46:33'),
(752, 47, '2026-08-03 11:46:33', '1945.00', 2400, '0.0000', NULL, NULL, '2026-08-03 11:46:33'),
(753, 1, '2026-08-03 12:02:04', '3000.00', 2400, '0.0000', NULL, NULL, '2026-08-03 12:02:04'),
(754, 2, '2026-08-03 12:02:04', '7585.00', 10727, '0.0000', NULL, NULL, '2026-08-03 12:02:04'),
(755, 3, '2026-08-03 12:02:04', '29015.00', 42, '1.2000', NULL, NULL, '2026-08-03 12:02:04'),
(756, 4, '2026-08-03 12:02:05', '1965.00', 1262, '3.9700', NULL, NULL, '2026-08-03 12:02:05'),
(757, 5, '2026-08-03 12:02:05', '8690.00', 2601, '-0.1100', NULL, NULL, '2026-08-03 12:02:05'),
(758, 6, '2026-08-03 12:02:05', '7190.00', 2008, '1.2700', NULL, NULL, '2026-08-03 12:02:05'),
(759, 7, '2026-08-03 12:02:05', '11000.00', 3704, '1.7100', NULL, NULL, '2026-08-03 12:02:05'),
(760, 8, '2026-08-03 12:02:05', '5660.00', 1315, '-0.0900', NULL, NULL, '2026-08-03 12:02:05'),
(761, 9, '2026-08-03 12:02:05', '5200.00', 692, '-1.7000', NULL, NULL, '2026-08-03 12:02:05'),
(762, 10, '2026-08-03 12:02:05', '7660.00', 2197, '-1.6700', NULL, NULL, '2026-08-03 12:02:05'),
(763, 11, '2026-08-03 12:02:05', '3500.00', 3817, '0.0000', NULL, NULL, '2026-08-03 12:02:05'),
(764, 12, '2026-08-03 12:02:05', '28250.00', 413, '0.8900', NULL, NULL, '2026-08-03 12:02:05'),
(765, 13, '2026-08-03 12:02:05', '1690.00', 2131, '1.5000', NULL, NULL, '2026-08-03 12:02:05'),
(766, 14, '2026-08-03 12:02:05', '5055.00', 3890, '1.1000', NULL, NULL, '2026-08-03 12:02:05'),
(767, 15, '2026-08-03 12:02:05', '16200.00', 941, '-0.1800', NULL, NULL, '2026-08-03 12:02:05'),
(768, 16, '2026-08-03 12:02:05', '70.00', 486897, '6.0600', NULL, NULL, '2026-08-03 12:02:05'),
(769, 17, '2026-08-03 12:02:05', '1980.00', 1919, '0.5100', NULL, NULL, '2026-08-03 12:02:05'),
(770, 18, '2026-08-03 12:02:05', '4795.00', 8136, '6.5600', NULL, NULL, '2026-08-03 12:02:05'),
(771, 19, '2026-08-03 12:02:05', '2185.00', 312, '-4.1700', NULL, NULL, '2026-08-03 12:02:05'),
(772, 20, '2026-08-03 12:02:05', '24000.00', 453, '0.0000', NULL, NULL, '2026-08-03 12:02:05'),
(773, 21, '2026-08-03 12:02:05', '15805.00', 1616, '-4.2100', NULL, NULL, '2026-08-03 12:02:05'),
(774, 22, '2026-08-03 12:02:06', '2900.00', 1801, '-3.1700', NULL, NULL, '2026-08-03 12:02:06'),
(775, 23, '2026-08-03 12:02:06', '16995.00', 838, '0.0000', NULL, NULL, '2026-08-03 12:02:06'),
(776, 24, '2026-08-03 12:02:06', '3140.00', 2038, '0.0000', NULL, NULL, '2026-08-03 12:02:06'),
(777, 25, '2026-08-03 12:02:06', '9000.00', 600, '0.0000', NULL, NULL, '2026-08-03 12:02:06'),
(778, 26, '2026-08-03 12:02:06', '4350.00', 314, '-3.6500', NULL, NULL, '2026-08-03 12:02:06'),
(779, 27, '2026-08-03 12:02:06', '5005.00', 16547, '-2.4400', NULL, NULL, '2026-08-03 12:02:06'),
(780, 28, '2026-08-03 12:02:06', '3630.00', 2005, '-0.4100', NULL, NULL, '2026-08-03 12:02:06'),
(781, 29, '2026-08-03 12:02:06', '11900.00', 568, '0.0000', NULL, NULL, '2026-08-03 12:02:06'),
(782, 30, '2026-08-03 12:02:06', '2695.00', 2458, '0.1900', NULL, NULL, '2026-08-03 12:02:06'),
(783, 31, '2026-08-03 12:02:06', '1450.00', 2058, '-2.3600', NULL, NULL, '2026-08-03 12:02:06'),
(784, 32, '2026-08-03 12:02:06', '38000.00', 4027, '0.0000', NULL, NULL, '2026-08-03 12:02:06'),
(785, 33, '2026-08-03 12:02:06', '2295.00', 4356, '4.3200', NULL, NULL, '2026-08-03 12:02:06'),
(786, 34, '2026-08-03 12:02:06', '8910.00', 1861, '-1.0000', NULL, NULL, '2026-08-03 12:02:06'),
(787, 35, '2026-08-03 12:02:06', '6400.00', 1, '0.0000', NULL, NULL, '2026-08-03 12:02:06'),
(788, 36, '2026-08-03 12:02:06', '2200.00', 2368, '0.0000', NULL, NULL, '2026-08-03 12:02:06'),
(789, 37, '2026-08-03 12:02:06', '37800.00', 38, '0.1300', NULL, NULL, '2026-08-03 12:02:06'),
(790, 38, '2026-08-03 12:02:06', '15500.00', 1264, '0.0300', NULL, NULL, '2026-08-03 12:02:06'),
(791, 39, '2026-08-03 12:02:06', '31000.00', 2890, '0.0000', NULL, NULL, '2026-08-03 12:02:06'),
(792, 40, '2026-08-03 12:02:06', '8350.00', 181, '0.5400', NULL, NULL, '2026-08-03 12:02:06'),
(793, 41, '2026-08-03 12:02:06', '7565.00', 3245, '0.0000', NULL, NULL, '2026-08-03 12:02:06'),
(794, 42, '2026-08-03 12:02:06', '2840.00', 3640, '5.9700', NULL, NULL, '2026-08-03 12:02:06'),
(795, 43, '2026-08-03 12:02:06', '23205.00', 1535, '0.0200', NULL, NULL, '2026-08-03 12:02:06'),
(796, 44, '2026-08-03 12:02:06', '3000.00', 5990, '0.8400', NULL, NULL, '2026-08-03 12:02:06'),
(797, 45, '2026-08-03 12:02:06', '3625.00', 1173, '-0.8200', NULL, NULL, '2026-08-03 12:02:06'),
(798, 46, '2026-08-03 12:02:06', '51035.00', 2, '0.0700', NULL, NULL, '2026-08-03 12:02:06'),
(799, 47, '2026-08-03 12:02:06', '1945.00', 2400, '0.0000', NULL, NULL, '2026-08-03 12:02:06'),
(800, 1, '2026-08-03 12:10:28', '3000.00', 2420, '0.0000', NULL, NULL, '2026-08-03 12:10:28'),
(801, 2, '2026-08-03 12:10:28', '7510.00', 10802, '-0.9900', NULL, NULL, '2026-08-03 12:10:28'),
(802, 3, '2026-08-03 12:10:28', '29015.00', 48, '1.2000', NULL, NULL, '2026-08-03 12:10:28'),
(803, 4, '2026-08-03 12:10:28', '1950.00', 1768, '3.1700', NULL, NULL, '2026-08-03 12:10:28'),
(804, 5, '2026-08-03 12:10:28', '8600.00', 2948, '-1.1500', NULL, NULL, '2026-08-03 12:10:28'),
(805, 6, '2026-08-03 12:10:28', '7190.00', 2048, '1.2700', NULL, NULL, '2026-08-03 12:10:29'),
(806, 7, '2026-08-03 12:10:29', '11240.00', 3836, '3.9300', NULL, NULL, '2026-08-03 12:10:29'),
(807, 8, '2026-08-03 12:10:29', '5665.00', 1385, '0.0000', NULL, NULL, '2026-08-03 12:10:29'),
(808, 9, '2026-08-03 12:10:29', '5200.00', 692, '-1.7000', NULL, NULL, '2026-08-03 12:10:29'),
(809, 10, '2026-08-03 12:10:29', '7690.00', 2287, '-1.2800', NULL, NULL, '2026-08-03 12:10:29'),
(810, 11, '2026-08-03 12:10:29', '3650.00', 3827, '4.2900', NULL, NULL, '2026-08-03 12:10:29'),
(811, 12, '2026-08-03 12:10:29', '28005.00', 826, '0.0200', NULL, NULL, '2026-08-03 12:10:29'),
(812, 13, '2026-08-03 12:10:29', '1690.00', 2218, '1.5000', NULL, NULL, '2026-08-03 12:10:29'),
(813, 14, '2026-08-03 12:10:29', '5050.00', 4268, '1.0000', NULL, NULL, '2026-08-03 12:10:29'),
(814, 15, '2026-08-03 12:10:29', '16200.00', 943, '-0.1800', NULL, NULL, '2026-08-03 12:10:29'),
(815, 16, '2026-08-03 12:10:29', '70.00', 499073, '6.0600', NULL, NULL, '2026-08-03 12:10:29'),
(816, 17, '2026-08-03 12:10:29', '1970.00', 2099, '0.0000', NULL, NULL, '2026-08-03 12:10:29'),
(817, 18, '2026-08-03 12:10:29', '4700.00', 8327, '4.4400', NULL, NULL, '2026-08-03 12:10:29'),
(818, 19, '2026-08-03 12:10:29', '2190.00', 333, '-3.9500', NULL, NULL, '2026-08-03 12:10:29'),
(819, 20, '2026-08-03 12:10:29', '24000.00', 453, '0.0000', NULL, NULL, '2026-08-03 12:10:29'),
(820, 21, '2026-08-03 12:10:29', '16490.00', 1627, '-0.0600', NULL, NULL, '2026-08-03 12:10:29'),
(821, 22, '2026-08-03 12:10:29', '2880.00', 1855, '-3.8400', NULL, NULL, '2026-08-03 12:10:29'),
(822, 23, '2026-08-03 12:10:29', '16995.00', 870, '0.0000', NULL, NULL, '2026-08-03 12:10:29'),
(823, 24, '2026-08-03 12:10:29', '3140.00', 2090, '0.0000', NULL, NULL, '2026-08-03 12:10:29'),
(824, 25, '2026-08-03 12:10:29', '9000.00', 653, '0.0000', NULL, NULL, '2026-08-03 12:10:29'),
(825, 26, '2026-08-03 12:10:29', '4350.00', 344, '-3.6500', NULL, NULL, '2026-08-03 12:10:29'),
(826, 27, '2026-08-03 12:10:29', '5010.00', 16563, '-2.3400', NULL, NULL, '2026-08-03 12:10:29'),
(827, 28, '2026-08-03 12:10:29', '3630.00', 2007, '-0.4100', NULL, NULL, '2026-08-03 12:10:29'),
(828, 29, '2026-08-03 12:10:29', '11900.00', 570, '0.0000', NULL, NULL, '2026-08-03 12:10:29'),
(829, 30, '2026-08-03 12:10:29', '2695.00', 2470, '0.1900', NULL, NULL, '2026-08-03 12:10:29'),
(830, 31, '2026-08-03 12:10:29', '1450.00', 2058, '-2.3600', NULL, NULL, '2026-08-03 12:10:29'),
(831, 32, '2026-08-03 12:10:29', '37995.00', 4097, '-0.0100', NULL, NULL, '2026-08-03 12:10:29'),
(832, 33, '2026-08-03 12:10:29', '2300.00', 4562, '4.5500', NULL, NULL, '2026-08-03 12:10:29'),
(833, 34, '2026-08-03 12:10:29', '8910.00', 1871, '-1.0000', NULL, NULL, '2026-08-03 12:10:29'),
(834, 35, '2026-08-03 12:10:29', '6400.00', 1, '0.0000', NULL, NULL, '2026-08-03 12:10:29'),
(835, 36, '2026-08-03 12:10:29', '2200.00', 2385, '0.0000', NULL, NULL, '2026-08-03 12:10:29'),
(836, 37, '2026-08-03 12:10:29', '38800.00', 53, '2.7800', NULL, NULL, '2026-08-03 12:10:29'),
(837, 38, '2026-08-03 12:10:29', '15495.00', 1347, '0.0000', NULL, NULL, '2026-08-03 12:10:29'),
(838, 39, '2026-08-03 12:10:29', '31000.00', 3491, '0.0000', NULL, NULL, '2026-08-03 12:10:29'),
(839, 40, '2026-08-03 12:10:29', '8395.00', 185, '1.0800', NULL, NULL, '2026-08-03 12:10:29'),
(840, 41, '2026-08-03 12:10:29', '7565.00', 3245, '0.0000', NULL, NULL, '2026-08-03 12:10:29'),
(841, 42, '2026-08-03 12:10:29', '2800.00', 3882, '4.4800', NULL, NULL, '2026-08-03 12:10:29'),
(842, 43, '2026-08-03 12:10:29', '23980.00', 1567, '3.3600', NULL, NULL, '2026-08-03 12:10:29'),
(843, 44, '2026-08-03 12:10:29', '2975.00', 6417, '0.0000', NULL, NULL, '2026-08-03 12:10:29'),
(844, 45, '2026-08-03 12:10:29', '3625.00', 1223, '-0.8200', NULL, NULL, '2026-08-03 12:10:29'),
(845, 46, '2026-08-03 12:10:29', '54825.00', 3, '7.5000', NULL, NULL, '2026-08-03 12:10:29'),
(846, 47, '2026-08-03 12:10:29', '1945.00', 2463, '0.0000', NULL, NULL, '2026-08-03 12:10:29'),
(847, 1, '2026-08-03 12:20:28', '3000.00', 2420, '0.0000', NULL, NULL, '2026-08-03 12:20:28'),
(848, 2, '2026-08-03 12:20:28', '7510.00', 10859, '-0.9900', NULL, NULL, '2026-08-03 12:20:28'),
(849, 3, '2026-08-03 12:20:28', '29015.00', 48, '1.2000', NULL, NULL, '2026-08-03 12:20:28'),
(850, 4, '2026-08-03 12:20:28', '1950.00', 1768, '3.1700', NULL, NULL, '2026-08-03 12:20:28'),
(851, 5, '2026-08-03 12:20:28', '8610.00', 2964, '-1.0300', NULL, NULL, '2026-08-03 12:20:28'),
(852, 6, '2026-08-03 12:20:28', '7200.00', 2075, '1.4100', NULL, NULL, '2026-08-03 12:20:28'),
(853, 7, '2026-08-03 12:20:28', '11200.00', 3861, '3.5600', NULL, NULL, '2026-08-03 12:20:28'),
(854, 8, '2026-08-03 12:20:28', '5660.00', 1416, '-0.0900', NULL, NULL, '2026-08-03 12:20:28'),
(855, 9, '2026-08-03 12:20:28', '5200.00', 712, '-1.7000', NULL, NULL, '2026-08-03 12:20:28'),
(856, 10, '2026-08-03 12:20:28', '7695.00', 2316, '-1.2200', NULL, NULL, '2026-08-03 12:20:28'),
(857, 11, '2026-08-03 12:20:28', '3650.00', 3827, '4.2900', NULL, NULL, '2026-08-03 12:20:28'),
(858, 12, '2026-08-03 12:20:28', '28000.00', 994, '0.0000', NULL, NULL, '2026-08-03 12:20:28'),
(859, 13, '2026-08-03 12:20:28', '1690.00', 2220, '1.5000', NULL, NULL, '2026-08-03 12:20:28'),
(860, 14, '2026-08-03 12:20:28', '5055.00', 4322, '1.1000', NULL, NULL, '2026-08-03 12:20:28'),
(861, 15, '2026-08-03 12:20:28', '16200.00', 943, '-0.1800', NULL, NULL, '2026-08-03 12:20:28'),
(862, 16, '2026-08-03 12:20:28', '69.00', 519395, '4.5500', NULL, NULL, '2026-08-03 12:20:28'),
(863, 17, '2026-08-03 12:20:28', '1980.00', 2101, '0.5100', NULL, NULL, '2026-08-03 12:20:28'),
(864, 18, '2026-08-03 12:20:28', '4700.00', 8524, '4.4400', NULL, NULL, '2026-08-03 12:20:28'),
(865, 19, '2026-08-03 12:20:28', '2190.00', 383, '-3.9500', NULL, NULL, '2026-08-03 12:20:28'),
(866, 20, '2026-08-03 12:20:28', '24000.00', 453, '0.0000', NULL, NULL, '2026-08-03 12:20:28'),
(867, 21, '2026-08-03 12:20:28', '16490.00', 1627, '-0.0600', NULL, NULL, '2026-08-03 12:20:28'),
(868, 22, '2026-08-03 12:20:28', '2880.00', 1930, '-3.8400', NULL, NULL, '2026-08-03 12:20:28'),
(869, 23, '2026-08-03 12:20:28', '16995.00', 935, '0.0000', NULL, NULL, '2026-08-03 12:20:28'),
(870, 24, '2026-08-03 12:20:28', '3140.00', 2385, '0.0000', NULL, NULL, '2026-08-03 12:20:28'),
(871, 25, '2026-08-03 12:20:28', '9000.00', 703, '0.0000', NULL, NULL, '2026-08-03 12:20:28'),
(872, 26, '2026-08-03 12:20:28', '4350.00', 344, '-3.6500', NULL, NULL, '2026-08-03 12:20:28'),
(873, 27, '2026-08-03 12:20:28', '5010.00', 16573, '-2.3400', NULL, NULL, '2026-08-03 12:20:28'),
(874, 28, '2026-08-03 12:20:28', '3630.00', 2088, '-0.4100', NULL, NULL, '2026-08-03 12:20:28'),
(875, 29, '2026-08-03 12:20:28', '11900.00', 571, '0.0000', NULL, NULL, '2026-08-03 12:20:28'),
(876, 30, '2026-08-03 12:20:28', '2690.00', 3024, '0.0000', NULL, NULL, '2026-08-03 12:20:28'),
(877, 31, '2026-08-03 12:20:28', '1500.00', 2083, '1.0100', NULL, NULL, '2026-08-03 12:20:28'),
(878, 32, '2026-08-03 12:20:28', '38000.00', 4385, '0.0000', NULL, NULL, '2026-08-03 12:20:28'),
(879, 33, '2026-08-03 12:20:28', '2300.00', 4597, '4.5500', NULL, NULL, '2026-08-03 12:20:28'),
(880, 34, '2026-08-03 12:20:29', '8925.00', 1884, '-0.8300', NULL, NULL, '2026-08-03 12:20:29'),
(881, 35, '2026-08-03 12:20:29', '6400.00', 1, '0.0000', NULL, NULL, '2026-08-03 12:20:29'),
(882, 36, '2026-08-03 12:20:29', '2200.00', 2485, '0.0000', NULL, NULL, '2026-08-03 12:20:29'),
(883, 37, '2026-08-03 12:20:29', '38800.00', 56, '2.7800', NULL, NULL, '2026-08-03 12:20:29'),
(884, 38, '2026-08-03 12:20:29', '15495.00', 1350, '0.0000', NULL, NULL, '2026-08-03 12:20:29'),
(885, 39, '2026-08-03 12:20:29', '31000.00', 4154, '0.0000', NULL, NULL, '2026-08-03 12:20:29'),
(886, 40, '2026-08-03 12:20:29', '8360.00', 212, '0.6600', NULL, NULL, '2026-08-03 12:20:29'),
(887, 41, '2026-08-03 12:20:29', '7500.00', 3506, '-0.8600', NULL, NULL, '2026-08-03 12:20:29'),
(888, 42, '2026-08-03 12:20:29', '2800.00', 3956, '4.4800', NULL, NULL, '2026-08-03 12:20:29'),
(889, 43, '2026-08-03 12:20:29', '23980.00', 1567, '3.3600', NULL, NULL, '2026-08-03 12:20:29'),
(890, 44, '2026-08-03 12:20:29', '2975.00', 6454, '0.0000', NULL, NULL, '2026-08-03 12:20:29'),
(891, 45, '2026-08-03 12:20:29', '3625.00', 1223, '-0.8200', NULL, NULL, '2026-08-03 12:20:29'),
(892, 46, '2026-08-03 12:20:29', '54825.00', 3, '7.5000', NULL, NULL, '2026-08-03 12:20:29'),
(893, 47, '2026-08-03 12:20:29', '1945.00', 2473, '0.0000', NULL, NULL, '2026-08-03 12:20:29'),
(894, 1, '2026-08-03 12:31:26', '3000.00', 2475, '0.0000', NULL, NULL, '2026-08-03 12:31:26'),
(895, 2, '2026-08-03 12:31:27', '7510.00', 10875, '-0.9900', NULL, NULL, '2026-08-03 12:31:27'),
(896, 3, '2026-08-03 12:31:27', '29015.00', 51, '1.2000', NULL, NULL, '2026-08-03 12:31:27'),
(897, 4, '2026-08-03 12:31:27', '1950.00', 1799, '3.1700', NULL, NULL, '2026-08-03 12:31:27'),
(898, 5, '2026-08-03 12:31:27', '8685.00', 3022, '-0.1700', NULL, NULL, '2026-08-03 12:31:27'),
(899, 6, '2026-08-03 12:31:27', '7200.00', 2117, '1.4100', NULL, NULL, '2026-08-03 12:31:27'),
(900, 7, '2026-08-03 12:31:27', '11200.00', 3967, '3.5600', NULL, NULL, '2026-08-03 12:31:27'),
(901, 8, '2026-08-03 12:31:27', '5650.00', 1572, '-0.2600', NULL, NULL, '2026-08-03 12:31:27'),
(902, 9, '2026-08-03 12:31:27', '5200.00', 768, '-1.7000', NULL, NULL, '2026-08-03 12:31:27'),
(903, 10, '2026-08-03 12:31:27', '7695.00', 2437, '-1.2200', NULL, NULL, '2026-08-03 12:31:27'),
(904, 11, '2026-08-03 12:31:27', '3500.00', 4827, '0.0000', NULL, NULL, '2026-08-03 12:31:27'),
(905, 12, '2026-08-03 12:31:27', '28000.00', 1046, '0.0000', NULL, NULL, '2026-08-03 12:31:27'),
(906, 13, '2026-08-03 12:31:27', '1695.00', 2295, '1.8000', NULL, NULL, '2026-08-03 12:31:27'),
(907, 14, '2026-08-03 12:31:27', '5030.00', 4429, '0.6000', NULL, NULL, '2026-08-03 12:31:27'),
(908, 15, '2026-08-03 12:31:27', '16195.00', 988, '-0.2200', NULL, NULL, '2026-08-03 12:31:27'),
(909, 16, '2026-08-03 12:31:27', '70.00', 538893, '6.0600', NULL, NULL, '2026-08-03 12:31:27'),
(910, 17, '2026-08-03 12:31:27', '1975.00', 2118, '0.2500', NULL, NULL, '2026-08-03 12:31:27'),
(911, 18, '2026-08-03 12:31:27', '4795.00', 8563, '6.5600', NULL, NULL, '2026-08-03 12:31:27'),
(912, 19, '2026-08-03 12:31:27', '2200.00', 406, '-3.5100', NULL, NULL, '2026-08-03 12:31:27'),
(913, 20, '2026-08-03 12:31:27', '24000.00', 453, '0.0000', NULL, NULL, '2026-08-03 12:31:27'),
(914, 21, '2026-08-03 12:31:27', '16490.00', 1687, '-0.0600', NULL, NULL, '2026-08-03 12:31:27'),
(915, 22, '2026-08-03 12:31:27', '2880.00', 1958, '-3.8400', NULL, NULL, '2026-08-03 12:31:27'),
(916, 23, '2026-08-03 12:31:27', '17000.00', 1024, '0.0300', NULL, NULL, '2026-08-03 12:31:27'),
(917, 24, '2026-08-03 12:31:27', '3125.00', 2815, '-0.4800', NULL, NULL, '2026-08-03 12:31:27'),
(918, 25, '2026-08-03 12:31:27', '9000.00', 740, '0.0000', NULL, NULL, '2026-08-03 12:31:27'),
(919, 26, '2026-08-03 12:31:27', '4400.00', 414, '-2.5500', NULL, NULL, '2026-08-03 12:31:27'),
(920, 27, '2026-08-03 12:31:27', '5010.00', 16604, '-2.3400', NULL, NULL, '2026-08-03 12:31:27'),
(921, 28, '2026-08-03 12:31:27', '3640.00', 2300, '-0.1400', NULL, NULL, '2026-08-03 12:31:27'),
(922, 29, '2026-08-03 12:31:27', '11860.00', 632, '-0.3400', NULL, NULL, '2026-08-03 12:31:27'),
(923, 30, '2026-08-03 12:31:27', '2660.00', 3348, '-1.1200', NULL, NULL, '2026-08-03 12:31:27'),
(924, 31, '2026-08-03 12:31:27', '1500.00', 2083, '1.0100', NULL, NULL, '2026-08-03 12:31:27'),
(925, 32, '2026-08-03 12:31:27', '38000.00', 4556, '0.0000', NULL, NULL, '2026-08-03 12:31:27'),
(926, 33, '2026-08-03 12:31:27', '2300.00', 4707, '4.5500', NULL, NULL, '2026-08-03 12:31:27'),
(927, 34, '2026-08-03 12:31:27', '8920.00', 1941, '-0.8900', NULL, NULL, '2026-08-03 12:31:27'),
(928, 35, '2026-08-03 12:31:27', '6600.00', 2, '3.1200', NULL, NULL, '2026-08-03 12:31:27'),
(929, 36, '2026-08-03 12:31:27', '2200.00', 2485, '0.0000', NULL, NULL, '2026-08-03 12:31:27'),
(930, 37, '2026-08-03 12:31:27', '37795.00', 79, '0.1200', NULL, NULL, '2026-08-03 12:31:27'),
(931, 38, '2026-08-03 12:31:27', '15495.00', 1350, '0.0000', NULL, NULL, '2026-08-03 12:31:27'),
(932, 39, '2026-08-03 12:31:27', '31000.00', 9117, '0.0000', NULL, NULL, '2026-08-03 12:31:27'),
(933, 40, '2026-08-03 12:31:27', '8360.00', 219, '0.6600', NULL, NULL, '2026-08-03 12:31:27'),
(934, 41, '2026-08-03 12:31:27', '7565.00', 3606, '0.0000', NULL, NULL, '2026-08-03 12:31:27'),
(935, 42, '2026-08-03 12:31:27', '2775.00', 4168, '3.5400', NULL, NULL, '2026-08-03 12:31:27'),
(936, 43, '2026-08-03 12:31:27', '23980.00', 1897, '3.3600', NULL, NULL, '2026-08-03 12:31:27'),
(937, 44, '2026-08-03 12:31:27', '2975.00', 6474, '0.0000', NULL, NULL, '2026-08-03 12:31:27'),
(938, 45, '2026-08-03 12:31:27', '3625.00', 1223, '-0.8200', NULL, NULL, '2026-08-03 12:31:27'),
(939, 46, '2026-08-03 12:31:27', '54800.00', 13, '7.4500', NULL, NULL, '2026-08-03 12:31:27'),
(940, 47, '2026-08-03 12:31:27', '1945.00', 2483, '0.0000', NULL, NULL, '2026-08-03 12:31:27'),
(941, 1, '2026-08-03 12:40:32', '3000.00', 2475, '0.0000', NULL, NULL, '2026-08-03 12:40:32'),
(942, 2, '2026-08-03 12:40:32', '7510.00', 10875, '-0.9900', NULL, NULL, '2026-08-03 12:40:32'),
(943, 3, '2026-08-03 12:40:32', '29015.00', 51, '1.2000', NULL, NULL, '2026-08-03 12:40:32'),
(944, 4, '2026-08-03 12:40:32', '1950.00', 1799, '3.1700', NULL, NULL, '2026-08-03 12:40:32'),
(945, 5, '2026-08-03 12:40:32', '8685.00', 3022, '-0.1700', NULL, NULL, '2026-08-03 12:40:32'),
(946, 6, '2026-08-03 12:40:32', '7200.00', 2117, '1.4100', NULL, NULL, '2026-08-03 12:40:32'),
(947, 7, '2026-08-03 12:40:32', '11200.00', 3967, '3.5600', NULL, NULL, '2026-08-03 12:40:32'),
(948, 8, '2026-08-03 12:40:32', '5650.00', 1572, '-0.2600', NULL, NULL, '2026-08-03 12:40:32'),
(949, 9, '2026-08-03 12:40:32', '5200.00', 768, '-1.7000', NULL, NULL, '2026-08-03 12:40:32'),
(950, 10, '2026-08-03 12:40:32', '7695.00', 2437, '-1.2200', NULL, NULL, '2026-08-03 12:40:32'),
(951, 11, '2026-08-03 12:40:32', '3500.00', 4827, '0.0000', NULL, NULL, '2026-08-03 12:40:32'),
(952, 12, '2026-08-03 12:40:32', '28000.00', 1046, '0.0000', NULL, NULL, '2026-08-03 12:40:32'),
(953, 13, '2026-08-03 12:40:33', '1695.00', 2295, '1.8000', NULL, NULL, '2026-08-03 12:40:33'),
(954, 14, '2026-08-03 12:40:33', '5030.00', 4429, '0.6000', NULL, NULL, '2026-08-03 12:40:33'),
(955, 15, '2026-08-03 12:40:33', '16195.00', 988, '-0.2200', NULL, NULL, '2026-08-03 12:40:33'),
(956, 16, '2026-08-03 12:40:33', '70.00', 538893, '6.0600', NULL, NULL, '2026-08-03 12:40:33'),
(957, 17, '2026-08-03 12:40:33', '1975.00', 2118, '0.2500', NULL, NULL, '2026-08-03 12:40:33'),
(958, 18, '2026-08-03 12:40:33', '4795.00', 8563, '6.5600', NULL, NULL, '2026-08-03 12:40:33'),
(959, 19, '2026-08-03 12:40:33', '2200.00', 406, '-3.5100', NULL, NULL, '2026-08-03 12:40:33'),
(960, 20, '2026-08-03 12:40:33', '24000.00', 453, '0.0000', NULL, NULL, '2026-08-03 12:40:33'),
(961, 21, '2026-08-03 12:40:33', '16490.00', 1687, '-0.0600', NULL, NULL, '2026-08-03 12:40:33'),
(962, 22, '2026-08-03 12:40:33', '2880.00', 1958, '-3.8400', NULL, NULL, '2026-08-03 12:40:33'),
(963, 23, '2026-08-03 12:40:33', '17000.00', 1024, '0.0300', NULL, NULL, '2026-08-03 12:40:33'),
(964, 24, '2026-08-03 12:40:33', '3125.00', 2815, '-0.4800', NULL, NULL, '2026-08-03 12:40:33'),
(965, 25, '2026-08-03 12:40:33', '9000.00', 740, '0.0000', NULL, NULL, '2026-08-03 12:40:33'),
(966, 26, '2026-08-03 12:40:33', '4400.00', 414, '-2.5500', NULL, NULL, '2026-08-03 12:40:33'),
(967, 27, '2026-08-03 12:40:33', '5010.00', 16604, '-2.3400', NULL, NULL, '2026-08-03 12:40:33'),
(968, 28, '2026-08-03 12:40:33', '3640.00', 2300, '-0.1400', NULL, NULL, '2026-08-03 12:40:33'),
(969, 29, '2026-08-03 12:40:33', '11860.00', 632, '-0.3400', NULL, NULL, '2026-08-03 12:40:33'),
(970, 30, '2026-08-03 12:40:33', '2660.00', 3348, '-1.1200', NULL, NULL, '2026-08-03 12:40:33'),
(971, 31, '2026-08-03 12:40:33', '1500.00', 2083, '1.0100', NULL, NULL, '2026-08-03 12:40:33'),
(972, 32, '2026-08-03 12:40:33', '38000.00', 4556, '0.0000', NULL, NULL, '2026-08-03 12:40:33'),
(973, 33, '2026-08-03 12:40:33', '2300.00', 4707, '4.5500', NULL, NULL, '2026-08-03 12:40:33'),
(974, 34, '2026-08-03 12:40:33', '8920.00', 1941, '-0.8900', NULL, NULL, '2026-08-03 12:40:33'),
(975, 35, '2026-08-03 12:40:33', '6600.00', 2, '3.1200', NULL, NULL, '2026-08-03 12:40:33'),
(976, 36, '2026-08-03 12:40:33', '2200.00', 2485, '0.0000', NULL, NULL, '2026-08-03 12:40:33'),
(977, 37, '2026-08-03 12:40:33', '37795.00', 79, '0.1200', NULL, NULL, '2026-08-03 12:40:33'),
(978, 38, '2026-08-03 12:40:33', '15495.00', 1350, '0.0000', NULL, NULL, '2026-08-03 12:40:33'),
(979, 39, '2026-08-03 12:40:33', '31000.00', 9117, '0.0000', NULL, NULL, '2026-08-03 12:40:33'),
(980, 40, '2026-08-03 12:40:33', '8360.00', 219, '0.6600', NULL, NULL, '2026-08-03 12:40:33'),
(981, 41, '2026-08-03 12:40:33', '7565.00', 3606, '0.0000', NULL, NULL, '2026-08-03 12:40:33'),
(982, 42, '2026-08-03 12:40:33', '2775.00', 4168, '3.5400', NULL, NULL, '2026-08-03 12:40:33'),
(983, 43, '2026-08-03 12:40:33', '23980.00', 1897, '3.3600', NULL, NULL, '2026-08-03 12:40:33'),
(984, 44, '2026-08-03 12:40:33', '2975.00', 6474, '0.0000', NULL, NULL, '2026-08-03 12:40:33'),
(985, 45, '2026-08-03 12:40:34', '3625.00', 1223, '-0.8200', NULL, NULL, '2026-08-03 12:40:34'),
(986, 46, '2026-08-03 12:40:34', '54800.00', 13, '7.4500', NULL, NULL, '2026-08-03 12:40:34'),
(987, 47, '2026-08-03 12:40:34', '1945.00', 2483, '0.0000', NULL, NULL, '2026-08-03 12:40:34'),
(988, 1, '2026-08-03 12:50:30', '2995.00', 2497, '-0.1700', NULL, NULL, '2026-08-03 12:50:31'),
(989, 2, '2026-08-03 12:50:31', '7600.00', 10934, '0.2000', NULL, NULL, '2026-08-03 12:50:31'),
(990, 3, '2026-08-03 12:50:31', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 12:50:31'),
(991, 4, '2026-08-03 12:50:31', '1950.00', 1799, '3.1700', NULL, NULL, '2026-08-03 12:50:31'),
(992, 5, '2026-08-03 12:50:31', '8650.00', 3033, '-0.5700', NULL, NULL, '2026-08-03 12:50:31'),
(993, 6, '2026-08-03 12:50:31', '7200.00', 2138, '1.4100', NULL, NULL, '2026-08-03 12:50:31'),
(994, 7, '2026-08-03 12:50:31', '11200.00', 4007, '3.5600', NULL, NULL, '2026-08-03 12:50:31'),
(995, 8, '2026-08-03 12:50:31', '5650.00', 1974, '-0.2600', NULL, NULL, '2026-08-03 12:50:31'),
(996, 9, '2026-08-03 12:50:31', '5190.00', 774, '-1.8900', NULL, NULL, '2026-08-03 12:50:31'),
(997, 10, '2026-08-03 12:50:31', '7690.00', 2539, '-1.2800', NULL, NULL, '2026-08-03 12:50:31'),
(998, 11, '2026-08-03 12:50:31', '3500.00', 5827, '0.0000', NULL, NULL, '2026-08-03 12:50:31'),
(999, 12, '2026-08-03 12:50:31', '28000.00', 1046, '0.0000', NULL, NULL, '2026-08-03 12:50:31'),
(1000, 13, '2026-08-03 12:50:31', '1690.00', 2356, '1.5000', NULL, NULL, '2026-08-03 12:50:31'),
(1001, 14, '2026-08-03 12:50:31', '5030.00', 4497, '0.6000', NULL, NULL, '2026-08-03 12:50:31'),
(1002, 15, '2026-08-03 12:50:31', '16000.00', 1039, '-1.4200', NULL, NULL, '2026-08-03 12:50:31'),
(1003, 16, '2026-08-03 12:50:31', '70.00', 577344, '6.0600', NULL, NULL, '2026-08-03 12:50:31'),
(1004, 17, '2026-08-03 12:50:31', '1970.00', 2413, '0.0000', NULL, NULL, '2026-08-03 12:50:31'),
(1005, 18, '2026-08-03 12:50:31', '4795.00', 8565, '6.5600', NULL, NULL, '2026-08-03 12:50:31'),
(1006, 19, '2026-08-03 12:50:31', '2280.00', 448, '0.0000', NULL, NULL, '2026-08-03 12:50:31'),
(1007, 20, '2026-08-03 12:50:31', '24020.00', 460, '0.0800', NULL, NULL, '2026-08-03 12:50:31'),
(1008, 21, '2026-08-03 12:50:31', '16490.00', 1721, '-0.0600', NULL, NULL, '2026-08-03 12:50:31'),
(1009, 22, '2026-08-03 12:50:31', '2950.00', 2356, '-1.5000', NULL, NULL, '2026-08-03 12:50:31'),
(1010, 23, '2026-08-03 12:50:31', '16995.00', 1034, '0.0000', NULL, NULL, '2026-08-03 12:50:31'),
(1011, 24, '2026-08-03 12:50:31', '3120.00', 3091, '-0.6400', NULL, NULL, '2026-08-03 12:50:31'),
(1012, 25, '2026-08-03 12:50:31', '9000.00', 776, '0.0000', NULL, NULL, '2026-08-03 12:50:31'),
(1013, 26, '2026-08-03 12:50:31', '4400.00', 434, '-2.5500', NULL, NULL, '2026-08-03 12:50:31'),
(1014, 27, '2026-08-03 12:50:31', '5010.00', 16604, '-2.3400', NULL, NULL, '2026-08-03 12:50:31'),
(1015, 28, '2026-08-03 12:50:31', '3635.00', 2451, '-0.2700', NULL, NULL, '2026-08-03 12:50:31'),
(1016, 29, '2026-08-03 12:50:31', '11860.00', 636, '-0.3400', NULL, NULL, '2026-08-03 12:50:31'),
(1017, 30, '2026-08-03 12:50:31', '2660.00', 3512, '-1.1200', NULL, NULL, '2026-08-03 12:50:31'),
(1018, 31, '2026-08-03 12:50:31', '1500.00', 2090, '1.0100', NULL, NULL, '2026-08-03 12:50:31'),
(1019, 32, '2026-08-03 12:50:31', '38000.00', 4592, '0.0000', NULL, NULL, '2026-08-03 12:50:31'),
(1020, 33, '2026-08-03 12:50:31', '2300.00', 5180, '4.5500', NULL, NULL, '2026-08-03 12:50:31'),
(1021, 34, '2026-08-03 12:50:31', '8850.00', 2551, '-1.6700', NULL, NULL, '2026-08-03 12:50:31'),
(1022, 35, '2026-08-03 12:50:31', '6600.00', 3, '3.1200', NULL, NULL, '2026-08-03 12:50:31'),
(1023, 36, '2026-08-03 12:50:31', '2185.00', 2581, '-0.6800', NULL, NULL, '2026-08-03 12:50:31'),
(1024, 37, '2026-08-03 12:50:31', '37700.00', 250, '-0.1300', NULL, NULL, '2026-08-03 12:50:31'),
(1025, 38, '2026-08-03 12:50:31', '15495.00', 1359, '0.0000', NULL, NULL, '2026-08-03 12:50:31'),
(1026, 39, '2026-08-03 12:50:31', '31000.00', 9652, '0.0000', NULL, NULL, '2026-08-03 12:50:31'),
(1027, 40, '2026-08-03 12:50:31', '8400.00', 2238, '1.1400', NULL, NULL, '2026-08-03 12:50:31'),
(1028, 41, '2026-08-03 12:50:31', '7500.00', 3671, '-0.8600', NULL, NULL, '2026-08-03 12:50:31'),
(1029, 42, '2026-08-03 12:50:31', '2775.00', 4390, '3.5400', NULL, NULL, '2026-08-03 12:50:31'),
(1030, 43, '2026-08-03 12:50:31', '23980.00', 1970, '3.3600', NULL, NULL, '2026-08-03 12:50:31'),
(1031, 44, '2026-08-03 12:50:31', '2975.00', 6556, '0.0000', NULL, NULL, '2026-08-03 12:50:31'),
(1032, 45, '2026-08-03 12:50:31', '3650.00', 1248, '-0.1400', NULL, NULL, '2026-08-03 12:50:31'),
(1033, 46, '2026-08-03 12:50:31', '54800.00', 13, '7.4500', NULL, NULL, '2026-08-03 12:50:31'),
(1034, 47, '2026-08-03 12:50:31', '1910.00', 2880, '-1.8000', NULL, NULL, '2026-08-03 12:50:31'),
(1035, 1, '2026-08-03 13:01:33', '2995.00', 2497, '-0.1700', NULL, NULL, '2026-08-03 13:01:33'),
(1036, 2, '2026-08-03 13:01:33', '7600.00', 10934, '0.2000', NULL, NULL, '2026-08-03 13:01:33'),
(1037, 3, '2026-08-03 13:01:33', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 13:01:33'),
(1038, 4, '2026-08-03 13:01:33', '1950.00', 1799, '3.1700', NULL, NULL, '2026-08-03 13:01:33'),
(1039, 5, '2026-08-03 13:01:33', '8650.00', 3033, '-0.5700', NULL, NULL, '2026-08-03 13:01:33'),
(1040, 6, '2026-08-03 13:01:33', '7200.00', 2138, '1.4100', NULL, NULL, '2026-08-03 13:01:33'),
(1041, 7, '2026-08-03 13:01:33', '11200.00', 4007, '3.5600', NULL, NULL, '2026-08-03 13:01:33'),
(1042, 8, '2026-08-03 13:01:33', '5650.00', 1974, '-0.2600', NULL, NULL, '2026-08-03 13:01:33'),
(1043, 9, '2026-08-03 13:01:33', '5190.00', 774, '-1.8900', NULL, NULL, '2026-08-03 13:01:33'),
(1044, 10, '2026-08-03 13:01:33', '7690.00', 2539, '-1.2800', NULL, NULL, '2026-08-03 13:01:33'),
(1045, 11, '2026-08-03 13:01:33', '3500.00', 5827, '0.0000', NULL, NULL, '2026-08-03 13:01:33'),
(1046, 12, '2026-08-03 13:01:33', '28000.00', 1046, '0.0000', NULL, NULL, '2026-08-03 13:01:33'),
(1047, 13, '2026-08-03 13:01:33', '1690.00', 2356, '1.5000', NULL, NULL, '2026-08-03 13:01:33'),
(1048, 14, '2026-08-03 13:01:33', '5030.00', 4497, '0.6000', NULL, NULL, '2026-08-03 13:01:33'),
(1049, 15, '2026-08-03 13:01:33', '16000.00', 1039, '-1.4200', NULL, NULL, '2026-08-03 13:01:33'),
(1050, 16, '2026-08-03 13:01:33', '70.00', 577344, '6.0600', NULL, NULL, '2026-08-03 13:01:33'),
(1051, 17, '2026-08-03 13:01:33', '1970.00', 2413, '0.0000', NULL, NULL, '2026-08-03 13:01:33'),
(1052, 18, '2026-08-03 13:01:33', '4795.00', 8565, '6.5600', NULL, NULL, '2026-08-03 13:01:33'),
(1053, 19, '2026-08-03 13:01:33', '2280.00', 448, '0.0000', NULL, NULL, '2026-08-03 13:01:33'),
(1054, 20, '2026-08-03 13:01:33', '24020.00', 460, '0.0800', NULL, NULL, '2026-08-03 13:01:33'),
(1055, 21, '2026-08-03 13:01:33', '16490.00', 1721, '-0.0600', NULL, NULL, '2026-08-03 13:01:33'),
(1056, 22, '2026-08-03 13:01:33', '2950.00', 2356, '-1.5000', NULL, NULL, '2026-08-03 13:01:33'),
(1057, 23, '2026-08-03 13:01:33', '16995.00', 1034, '0.0000', NULL, NULL, '2026-08-03 13:01:33'),
(1058, 24, '2026-08-03 13:01:33', '3120.00', 3091, '-0.6400', NULL, NULL, '2026-08-03 13:01:33'),
(1059, 25, '2026-08-03 13:01:33', '9000.00', 776, '0.0000', NULL, NULL, '2026-08-03 13:01:33'),
(1060, 26, '2026-08-03 13:01:33', '4400.00', 434, '-2.5500', NULL, NULL, '2026-08-03 13:01:33'),
(1061, 27, '2026-08-03 13:01:33', '5010.00', 16604, '-2.3400', NULL, NULL, '2026-08-03 13:01:33'),
(1062, 28, '2026-08-03 13:01:33', '3635.00', 2451, '-0.2700', NULL, NULL, '2026-08-03 13:01:33'),
(1063, 29, '2026-08-03 13:01:33', '11860.00', 636, '-0.3400', NULL, NULL, '2026-08-03 13:01:33'),
(1064, 30, '2026-08-03 13:01:33', '2660.00', 3512, '-1.1200', NULL, NULL, '2026-08-03 13:01:33');
INSERT INTO `intraday_quotes` (`id`, `company_id`, `quote_datetime`, `price`, `volume`, `variation_percent`, `bid_price`, `ask_price`, `created_at`) VALUES
(1065, 31, '2026-08-03 13:01:33', '1500.00', 2090, '1.0100', NULL, NULL, '2026-08-03 13:01:33'),
(1066, 32, '2026-08-03 13:01:33', '38000.00', 4592, '0.0000', NULL, NULL, '2026-08-03 13:01:33'),
(1067, 33, '2026-08-03 13:01:33', '2300.00', 5180, '4.5500', NULL, NULL, '2026-08-03 13:01:33'),
(1068, 34, '2026-08-03 13:01:33', '8850.00', 2551, '-1.6700', NULL, NULL, '2026-08-03 13:01:33'),
(1069, 35, '2026-08-03 13:01:33', '6600.00', 3, '3.1200', NULL, NULL, '2026-08-03 13:01:33'),
(1070, 36, '2026-08-03 13:01:33', '2185.00', 2581, '-0.6800', NULL, NULL, '2026-08-03 13:01:33'),
(1071, 37, '2026-08-03 13:01:34', '37700.00', 250, '-0.1300', NULL, NULL, '2026-08-03 13:01:34'),
(1072, 38, '2026-08-03 13:01:34', '15495.00', 1359, '0.0000', NULL, NULL, '2026-08-03 13:01:34'),
(1073, 39, '2026-08-03 13:01:34', '31000.00', 9652, '0.0000', NULL, NULL, '2026-08-03 13:01:34'),
(1074, 40, '2026-08-03 13:01:34', '8400.00', 2238, '1.1400', NULL, NULL, '2026-08-03 13:01:34'),
(1075, 41, '2026-08-03 13:01:34', '7500.00', 3671, '-0.8600', NULL, NULL, '2026-08-03 13:01:34'),
(1076, 42, '2026-08-03 13:01:34', '2775.00', 4390, '3.5400', NULL, NULL, '2026-08-03 13:01:34'),
(1077, 43, '2026-08-03 13:01:34', '23980.00', 1970, '3.3600', NULL, NULL, '2026-08-03 13:01:34'),
(1078, 44, '2026-08-03 13:01:34', '2975.00', 6556, '0.0000', NULL, NULL, '2026-08-03 13:01:34'),
(1079, 45, '2026-08-03 13:01:34', '3650.00', 1248, '-0.1400', NULL, NULL, '2026-08-03 13:01:34'),
(1080, 46, '2026-08-03 13:01:34', '54800.00', 13, '7.4500', NULL, NULL, '2026-08-03 13:01:34'),
(1081, 47, '2026-08-03 13:01:34', '1910.00', 2880, '-1.8000', NULL, NULL, '2026-08-03 13:01:34'),
(1082, 1, '2026-08-03 13:10:30', '2995.00', 2572, '-0.1700', NULL, NULL, '2026-08-03 13:10:30'),
(1083, 2, '2026-08-03 13:10:30', '7690.00', 11139, '1.3800', NULL, NULL, '2026-08-03 13:10:30'),
(1084, 3, '2026-08-03 13:10:30', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 13:10:30'),
(1085, 4, '2026-08-03 13:10:30', '1930.00', 1821, '2.1200', NULL, NULL, '2026-08-03 13:10:30'),
(1086, 5, '2026-08-03 13:10:30', '8685.00', 3051, '-0.1700', NULL, NULL, '2026-08-03 13:10:30'),
(1087, 6, '2026-08-03 13:10:30', '7200.00', 2227, '1.4100', NULL, NULL, '2026-08-03 13:10:30'),
(1088, 7, '2026-08-03 13:10:30', '11240.00', 4112, '3.9300', NULL, NULL, '2026-08-03 13:10:30'),
(1089, 8, '2026-08-03 13:10:30', '5655.00', 1988, '-0.1800', NULL, NULL, '2026-08-03 13:10:30'),
(1090, 9, '2026-08-03 13:10:30', '5195.00', 797, '-1.8000', NULL, NULL, '2026-08-03 13:10:30'),
(1091, 10, '2026-08-03 13:10:30', '7695.00', 2624, '-1.2200', NULL, NULL, '2026-08-03 13:10:30'),
(1092, 11, '2026-08-03 13:10:30', '3500.00', 6744, '0.0000', NULL, NULL, '2026-08-03 13:10:30'),
(1093, 12, '2026-08-03 13:10:30', '28155.00', 1051, '0.5500', NULL, NULL, '2026-08-03 13:10:30'),
(1094, 13, '2026-08-03 13:10:30', '1695.00', 2408, '1.8000', NULL, NULL, '2026-08-03 13:10:30'),
(1095, 14, '2026-08-03 13:10:30', '5030.00', 4759, '0.6000', NULL, NULL, '2026-08-03 13:10:30'),
(1096, 15, '2026-08-03 13:10:30', '16000.00', 1211, '-1.4200', NULL, NULL, '2026-08-03 13:10:30'),
(1097, 16, '2026-08-03 13:10:30', '70.00', 636852, '6.0600', NULL, NULL, '2026-08-03 13:10:30'),
(1098, 17, '2026-08-03 13:10:30', '1970.00', 2452, '0.0000', NULL, NULL, '2026-08-03 13:10:30'),
(1099, 18, '2026-08-03 13:10:30', '4560.00', 8678, '1.3300', NULL, NULL, '2026-08-03 13:10:30'),
(1100, 19, '2026-08-03 13:10:30', '2185.00', 591, '-4.1700', NULL, NULL, '2026-08-03 13:10:30'),
(1101, 20, '2026-08-03 13:10:30', '24295.00', 462, '1.2300', NULL, NULL, '2026-08-03 13:10:30'),
(1102, 21, '2026-08-03 13:10:30', '16490.00', 1721, '-0.0600', NULL, NULL, '2026-08-03 13:10:30'),
(1103, 22, '2026-08-03 13:10:30', '2880.00', 2444, '-3.8400', NULL, NULL, '2026-08-03 13:10:30'),
(1104, 23, '2026-08-03 13:10:30', '17000.00', 1077, '0.0300', NULL, NULL, '2026-08-03 13:10:30'),
(1105, 24, '2026-08-03 13:10:30', '3125.00', 3592, '-0.4800', NULL, NULL, '2026-08-03 13:10:30'),
(1106, 25, '2026-08-03 13:10:31', '9000.00', 779, '0.0000', NULL, NULL, '2026-08-03 13:10:31'),
(1107, 26, '2026-08-03 13:10:31', '4540.00', 436, '0.5500', NULL, NULL, '2026-08-03 13:10:31'),
(1108, 27, '2026-08-03 13:10:31', '5000.00', 18145, '-2.5300', NULL, NULL, '2026-08-03 13:10:31'),
(1109, 28, '2026-08-03 13:10:31', '3635.00', 2611, '-0.2700', NULL, NULL, '2026-08-03 13:10:31'),
(1110, 29, '2026-08-03 13:10:31', '11860.00', 636, '-0.3400', NULL, NULL, '2026-08-03 13:10:31'),
(1111, 30, '2026-08-03 13:10:31', '2660.00', 3566, '-1.1200', NULL, NULL, '2026-08-03 13:10:31'),
(1112, 31, '2026-08-03 13:10:31', '1500.00', 2122, '1.0100', NULL, NULL, '2026-08-03 13:10:31'),
(1113, 32, '2026-08-03 13:10:31', '37995.00', 4657, '-0.0100', NULL, NULL, '2026-08-03 13:10:31'),
(1114, 33, '2026-08-03 13:10:31', '2325.00', 5392, '5.6800', NULL, NULL, '2026-08-03 13:10:31'),
(1115, 34, '2026-08-03 13:10:31', '8925.00', 2776, '-0.8300', NULL, NULL, '2026-08-03 13:10:31'),
(1116, 35, '2026-08-03 13:10:31', '6625.00', 4, '3.5200', NULL, NULL, '2026-08-03 13:10:31'),
(1117, 36, '2026-08-03 13:10:31', '2185.00', 2869, '-0.6800', NULL, NULL, '2026-08-03 13:10:31'),
(1118, 37, '2026-08-03 13:10:31', '37690.00', 321, '-0.1600', NULL, NULL, '2026-08-03 13:10:31'),
(1119, 38, '2026-08-03 13:10:31', '15500.00', 1425, '0.0300', NULL, NULL, '2026-08-03 13:10:31'),
(1120, 39, '2026-08-03 13:10:31', '31000.00', 9681, '0.0000', NULL, NULL, '2026-08-03 13:10:31'),
(1121, 40, '2026-08-03 13:10:31', '8400.00', 2238, '1.1400', NULL, NULL, '2026-08-03 13:10:31'),
(1122, 41, '2026-08-03 13:10:31', '7500.00', 3999, '-0.8600', NULL, NULL, '2026-08-03 13:10:31'),
(1123, 42, '2026-08-03 13:10:31', '2840.00', 4458, '5.9700', NULL, NULL, '2026-08-03 13:10:31'),
(1124, 43, '2026-08-03 13:10:31', '23985.00', 1992, '3.3800', NULL, NULL, '2026-08-03 13:10:31'),
(1125, 44, '2026-08-03 13:10:31', '2950.00', 6646, '-0.8400', NULL, NULL, '2026-08-03 13:10:31'),
(1126, 45, '2026-08-03 13:10:31', '3655.00', 1251, '0.0000', NULL, NULL, '2026-08-03 13:10:31'),
(1127, 46, '2026-08-03 13:10:31', '51035.00', 16, '0.0700', NULL, NULL, '2026-08-03 13:10:31'),
(1128, 47, '2026-08-03 13:10:31', '1940.00', 2881, '-0.2600', NULL, NULL, '2026-08-03 13:10:31'),
(1129, 1, '2026-08-03 13:20:29', '2990.00', 2727, '-0.3300', NULL, NULL, '2026-08-03 13:20:29'),
(1130, 2, '2026-08-03 13:20:29', '7400.00', 19145, '-2.4400', NULL, NULL, '2026-08-03 13:20:29'),
(1131, 3, '2026-08-03 13:20:30', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 13:20:30'),
(1132, 4, '2026-08-03 13:20:30', '1930.00', 1821, '2.1200', NULL, NULL, '2026-08-03 13:20:30'),
(1133, 5, '2026-08-03 13:20:30', '8685.00', 3117, '-0.1700', NULL, NULL, '2026-08-03 13:20:30'),
(1134, 6, '2026-08-03 13:20:30', '7200.00', 2250, '1.4100', NULL, NULL, '2026-08-03 13:20:30'),
(1135, 7, '2026-08-03 13:20:30', '11250.00', 4549, '4.0200', NULL, NULL, '2026-08-03 13:20:30'),
(1136, 8, '2026-08-03 13:20:30', '5650.00', 1989, '-0.2600', NULL, NULL, '2026-08-03 13:20:30'),
(1137, 9, '2026-08-03 13:20:30', '5195.00', 799, '-1.8000', NULL, NULL, '2026-08-03 13:20:30'),
(1138, 10, '2026-08-03 13:20:30', '7695.00', 2707, '-1.2200', NULL, NULL, '2026-08-03 13:20:30'),
(1139, 11, '2026-08-03 13:20:30', '3500.00', 6744, '0.0000', NULL, NULL, '2026-08-03 13:20:30'),
(1140, 12, '2026-08-03 13:20:30', '28155.00', 1051, '0.5500', NULL, NULL, '2026-08-03 13:20:30'),
(1141, 13, '2026-08-03 13:20:30', '1695.00', 2408, '1.8000', NULL, NULL, '2026-08-03 13:20:30'),
(1142, 14, '2026-08-03 13:20:30', '5030.00', 4781, '0.6000', NULL, NULL, '2026-08-03 13:20:30'),
(1143, 15, '2026-08-03 13:20:30', '16000.00', 1211, '-1.4200', NULL, NULL, '2026-08-03 13:20:30'),
(1144, 16, '2026-08-03 13:20:30', '70.00', 688162, '6.0600', NULL, NULL, '2026-08-03 13:20:30'),
(1145, 17, '2026-08-03 13:20:30', '1970.00', 2452, '0.0000', NULL, NULL, '2026-08-03 13:20:30'),
(1146, 18, '2026-08-03 13:20:30', '4560.00', 8678, '1.3300', NULL, NULL, '2026-08-03 13:20:30'),
(1147, 19, '2026-08-03 13:20:30', '2185.00', 596, '-4.1700', NULL, NULL, '2026-08-03 13:20:30'),
(1148, 20, '2026-08-03 13:20:30', '24300.00', 478, '1.2500', NULL, NULL, '2026-08-03 13:20:30'),
(1149, 21, '2026-08-03 13:20:30', '16490.00', 1721, '-0.0600', NULL, NULL, '2026-08-03 13:20:30'),
(1150, 22, '2026-08-03 13:20:30', '2955.00', 2509, '-1.3400', NULL, NULL, '2026-08-03 13:20:30'),
(1151, 23, '2026-08-03 13:20:30', '17000.00', 1078, '0.0300', NULL, NULL, '2026-08-03 13:20:30'),
(1152, 24, '2026-08-03 13:20:30', '3125.00', 3652, '-0.4800', NULL, NULL, '2026-08-03 13:20:30'),
(1153, 25, '2026-08-03 13:20:30', '9000.00', 779, '0.0000', NULL, NULL, '2026-08-03 13:20:30'),
(1154, 26, '2026-08-03 13:20:30', '4540.00', 436, '0.5500', NULL, NULL, '2026-08-03 13:20:30'),
(1155, 27, '2026-08-03 13:20:30', '5000.00', 18145, '-2.5300', NULL, NULL, '2026-08-03 13:20:30'),
(1156, 28, '2026-08-03 13:20:30', '3645.00', 2668, '0.0000', NULL, NULL, '2026-08-03 13:20:30'),
(1157, 29, '2026-08-03 13:20:30', '11860.00', 636, '-0.3400', NULL, NULL, '2026-08-03 13:20:30'),
(1158, 30, '2026-08-03 13:20:30', '2660.00', 3566, '-1.1200', NULL, NULL, '2026-08-03 13:20:30'),
(1159, 31, '2026-08-03 13:20:30', '1500.00', 2122, '1.0100', NULL, NULL, '2026-08-03 13:20:30'),
(1160, 32, '2026-08-03 13:20:30', '38015.00', 4757, '0.0400', NULL, NULL, '2026-08-03 13:20:30'),
(1161, 33, '2026-08-03 13:20:30', '2325.00', 5392, '5.6800', NULL, NULL, '2026-08-03 13:20:30'),
(1162, 34, '2026-08-03 13:20:30', '8850.00', 2822, '-1.6700', NULL, NULL, '2026-08-03 13:20:30'),
(1163, 35, '2026-08-03 13:20:30', '6625.00', 16, '3.5200', NULL, NULL, '2026-08-03 13:20:30'),
(1164, 36, '2026-08-03 13:20:30', '2100.00', 3359, '-4.5500', NULL, NULL, '2026-08-03 13:20:30'),
(1165, 37, '2026-08-03 13:20:30', '37700.00', 335, '-0.1300', NULL, NULL, '2026-08-03 13:20:30'),
(1166, 38, '2026-08-03 13:20:30', '15500.00', 1429, '0.0300', NULL, NULL, '2026-08-03 13:20:30'),
(1167, 39, '2026-08-03 13:20:30', '31000.00', 9700, '0.0000', NULL, NULL, '2026-08-03 13:20:30'),
(1168, 40, '2026-08-03 13:20:30', '8400.00', 2239, '1.1400', NULL, NULL, '2026-08-03 13:20:30'),
(1169, 41, '2026-08-03 13:20:30', '7500.00', 3999, '-0.8600', NULL, NULL, '2026-08-03 13:20:30'),
(1170, 42, '2026-08-03 13:20:30', '2840.00', 4458, '5.9700', NULL, NULL, '2026-08-03 13:20:30'),
(1171, 43, '2026-08-03 13:20:30', '23600.00', 2042, '1.7200', NULL, NULL, '2026-08-03 13:20:30'),
(1172, 44, '2026-08-03 13:20:30', '2975.00', 6651, '0.0000', NULL, NULL, '2026-08-03 13:20:30'),
(1173, 45, '2026-08-03 13:20:30', '3650.00', 1266, '-0.1400', NULL, NULL, '2026-08-03 13:20:30'),
(1174, 46, '2026-08-03 13:20:30', '51035.00', 16, '0.0700', NULL, NULL, '2026-08-03 13:20:30'),
(1175, 47, '2026-08-03 13:20:30', '1940.00', 2889, '-0.2600', NULL, NULL, '2026-08-03 13:20:30'),
(1176, 1, '2026-08-03 13:31:21', '2990.00', 2727, '-0.3300', NULL, NULL, '2026-08-03 13:31:21'),
(1177, 2, '2026-08-03 13:31:21', '7490.00', 19164, '-1.2500', NULL, NULL, '2026-08-03 13:31:21'),
(1178, 3, '2026-08-03 13:31:21', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 13:31:21'),
(1179, 4, '2026-08-03 13:31:21', '1930.00', 1821, '2.1200', NULL, NULL, '2026-08-03 13:31:21'),
(1180, 5, '2026-08-03 13:31:21', '8685.00', 3139, '-0.1700', NULL, NULL, '2026-08-03 13:31:21'),
(1181, 6, '2026-08-03 13:31:21', '7200.00', 2259, '1.4100', NULL, NULL, '2026-08-03 13:31:21'),
(1182, 7, '2026-08-03 13:31:21', '11250.00', 4565, '4.0200', NULL, NULL, '2026-08-03 13:31:21'),
(1183, 8, '2026-08-03 13:31:21', '5655.00', 2127, '-0.1800', NULL, NULL, '2026-08-03 13:31:21'),
(1184, 9, '2026-08-03 13:31:21', '5200.00', 894, '-1.7000', NULL, NULL, '2026-08-03 13:31:21'),
(1185, 10, '2026-08-03 13:31:21', '7700.00', 3190, '-1.1600', NULL, NULL, '2026-08-03 13:31:21'),
(1186, 11, '2026-08-03 13:31:21', '3500.00', 6744, '0.0000', NULL, NULL, '2026-08-03 13:31:21'),
(1187, 12, '2026-08-03 13:31:21', '28155.00', 1051, '0.5500', NULL, NULL, '2026-08-03 13:31:21'),
(1188, 13, '2026-08-03 13:31:21', '1695.00', 2439, '1.8000', NULL, NULL, '2026-08-03 13:31:21'),
(1189, 14, '2026-08-03 13:31:21', '5030.00', 4861, '0.6000', NULL, NULL, '2026-08-03 13:31:21'),
(1190, 15, '2026-08-03 13:31:21', '16100.00', 1212, '-0.8000', NULL, NULL, '2026-08-03 13:31:21'),
(1191, 16, '2026-08-03 13:31:21', '69.00', 735706, '4.5500', NULL, NULL, '2026-08-03 13:31:21'),
(1192, 17, '2026-08-03 13:31:21', '1970.00', 2476, '0.0000', NULL, NULL, '2026-08-03 13:31:21'),
(1193, 18, '2026-08-03 13:31:21', '4790.00', 8683, '6.4400', NULL, NULL, '2026-08-03 13:31:21'),
(1194, 19, '2026-08-03 13:31:21', '2185.00', 596, '-4.1700', NULL, NULL, '2026-08-03 13:31:21'),
(1195, 20, '2026-08-03 13:31:21', '24300.00', 485, '1.2500', NULL, NULL, '2026-08-03 13:31:21'),
(1196, 21, '2026-08-03 13:31:21', '16490.00', 1721, '-0.0600', NULL, NULL, '2026-08-03 13:31:21'),
(1197, 22, '2026-08-03 13:31:21', '2955.00', 2622, '-1.3400', NULL, NULL, '2026-08-03 13:31:21'),
(1198, 23, '2026-08-03 13:31:21', '17000.00', 1083, '0.0300', NULL, NULL, '2026-08-03 13:31:21'),
(1199, 24, '2026-08-03 13:31:21', '3125.00', 3846, '-0.4800', NULL, NULL, '2026-08-03 13:31:21'),
(1200, 25, '2026-08-03 13:31:21', '9000.00', 789, '0.0000', NULL, NULL, '2026-08-03 13:31:21'),
(1201, 26, '2026-08-03 13:31:21', '4540.00', 436, '0.5500', NULL, NULL, '2026-08-03 13:31:21'),
(1202, 27, '2026-08-03 13:31:21', '5000.00', 18170, '-2.5300', NULL, NULL, '2026-08-03 13:31:21'),
(1203, 28, '2026-08-03 13:31:21', '3615.00', 2897, '-0.8200', NULL, NULL, '2026-08-03 13:31:21'),
(1204, 29, '2026-08-03 13:31:21', '11860.00', 650, '-0.3400', NULL, NULL, '2026-08-03 13:31:21'),
(1205, 30, '2026-08-03 13:31:21', '2670.00', 3616, '-0.7400', NULL, NULL, '2026-08-03 13:31:21'),
(1206, 31, '2026-08-03 13:31:21', '1500.00', 2122, '1.0100', NULL, NULL, '2026-08-03 13:31:21'),
(1207, 32, '2026-08-03 13:31:21', '38000.00', 4911, '0.0000', NULL, NULL, '2026-08-03 13:31:21'),
(1208, 33, '2026-08-03 13:31:21', '2325.00', 5504, '5.6800', NULL, NULL, '2026-08-03 13:31:21'),
(1209, 34, '2026-08-03 13:31:21', '8850.00', 2912, '-1.6700', NULL, NULL, '2026-08-03 13:31:21'),
(1210, 35, '2026-08-03 13:31:21', '6625.00', 16, '3.5200', NULL, NULL, '2026-08-03 13:31:21'),
(1211, 36, '2026-08-03 13:31:21', '2200.00', 3410, '0.0000', NULL, NULL, '2026-08-03 13:31:21'),
(1212, 37, '2026-08-03 13:31:21', '37700.00', 460, '-0.1300', NULL, NULL, '2026-08-03 13:31:21'),
(1213, 38, '2026-08-03 13:31:21', '15500.00', 1494, '0.0300', NULL, NULL, '2026-08-03 13:31:21'),
(1214, 39, '2026-08-03 13:31:21', '31000.00', 9720, '0.0000', NULL, NULL, '2026-08-03 13:31:21'),
(1215, 40, '2026-08-03 13:31:21', '8400.00', 2274, '1.1400', NULL, NULL, '2026-08-03 13:31:21'),
(1216, 41, '2026-08-03 13:31:21', '7500.00', 4009, '-0.8600', NULL, NULL, '2026-08-03 13:31:21'),
(1217, 42, '2026-08-03 13:31:21', '2800.00', 4508, '4.4800', NULL, NULL, '2026-08-03 13:31:21'),
(1218, 43, '2026-08-03 13:31:21', '23970.00', 2067, '3.3200', NULL, NULL, '2026-08-03 13:31:21'),
(1219, 44, '2026-08-03 13:31:21', '2975.00', 6656, '0.0000', NULL, NULL, '2026-08-03 13:31:21'),
(1220, 45, '2026-08-03 13:31:21', '3650.00', 1266, '-0.1400', NULL, NULL, '2026-08-03 13:31:21'),
(1221, 46, '2026-08-03 13:31:21', '51035.00', 16, '0.0700', NULL, NULL, '2026-08-03 13:31:21'),
(1222, 47, '2026-08-03 13:31:22', '1940.00', 2892, '-0.2600', NULL, NULL, '2026-08-03 13:31:22'),
(1223, 1, '2026-08-03 13:40:32', '2990.00', 2727, '-0.3300', NULL, NULL, '2026-08-03 13:40:32'),
(1224, 2, '2026-08-03 13:40:32', '7490.00', 19164, '-1.2500', NULL, NULL, '2026-08-03 13:40:32'),
(1225, 3, '2026-08-03 13:40:32', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 13:40:32'),
(1226, 4, '2026-08-03 13:40:32', '1930.00', 1821, '2.1200', NULL, NULL, '2026-08-03 13:40:32'),
(1227, 5, '2026-08-03 13:40:32', '8685.00', 3139, '-0.1700', NULL, NULL, '2026-08-03 13:40:32'),
(1228, 6, '2026-08-03 13:40:32', '7200.00', 2259, '1.4100', NULL, NULL, '2026-08-03 13:40:32'),
(1229, 7, '2026-08-03 13:40:32', '11250.00', 4565, '4.0200', NULL, NULL, '2026-08-03 13:40:32'),
(1230, 8, '2026-08-03 13:40:32', '5655.00', 2127, '-0.1800', NULL, NULL, '2026-08-03 13:40:32'),
(1231, 9, '2026-08-03 13:40:32', '5200.00', 894, '-1.7000', NULL, NULL, '2026-08-03 13:40:32'),
(1232, 10, '2026-08-03 13:40:32', '7700.00', 3190, '-1.1600', NULL, NULL, '2026-08-03 13:40:32'),
(1233, 11, '2026-08-03 13:40:32', '3500.00', 6744, '0.0000', NULL, NULL, '2026-08-03 13:40:32'),
(1234, 12, '2026-08-03 13:40:32', '28155.00', 1051, '0.5500', NULL, NULL, '2026-08-03 13:40:32'),
(1235, 13, '2026-08-03 13:40:32', '1695.00', 2439, '1.8000', NULL, NULL, '2026-08-03 13:40:32'),
(1236, 14, '2026-08-03 13:40:32', '5030.00', 4861, '0.6000', NULL, NULL, '2026-08-03 13:40:32'),
(1237, 15, '2026-08-03 13:40:32', '16100.00', 1212, '-0.8000', NULL, NULL, '2026-08-03 13:40:32'),
(1238, 16, '2026-08-03 13:40:32', '69.00', 735706, '4.5500', NULL, NULL, '2026-08-03 13:40:32'),
(1239, 17, '2026-08-03 13:40:32', '1970.00', 2476, '0.0000', NULL, NULL, '2026-08-03 13:40:32'),
(1240, 18, '2026-08-03 13:40:32', '4790.00', 8683, '6.4400', NULL, NULL, '2026-08-03 13:40:32'),
(1241, 19, '2026-08-03 13:40:32', '2185.00', 596, '-4.1700', NULL, NULL, '2026-08-03 13:40:32'),
(1242, 20, '2026-08-03 13:40:32', '24300.00', 485, '1.2500', NULL, NULL, '2026-08-03 13:40:32'),
(1243, 21, '2026-08-03 13:40:32', '16490.00', 1721, '-0.0600', NULL, NULL, '2026-08-03 13:40:32'),
(1244, 22, '2026-08-03 13:40:32', '2955.00', 2622, '-1.3400', NULL, NULL, '2026-08-03 13:40:32'),
(1245, 23, '2026-08-03 13:40:33', '17000.00', 1083, '0.0300', NULL, NULL, '2026-08-03 13:40:33'),
(1246, 24, '2026-08-03 13:40:33', '3125.00', 3846, '-0.4800', NULL, NULL, '2026-08-03 13:40:33'),
(1247, 25, '2026-08-03 13:40:33', '9000.00', 789, '0.0000', NULL, NULL, '2026-08-03 13:40:33'),
(1248, 26, '2026-08-03 13:40:33', '4540.00', 436, '0.5500', NULL, NULL, '2026-08-03 13:40:33'),
(1249, 27, '2026-08-03 13:40:33', '5000.00', 18170, '-2.5300', NULL, NULL, '2026-08-03 13:40:33'),
(1250, 28, '2026-08-03 13:40:33', '3615.00', 2897, '-0.8200', NULL, NULL, '2026-08-03 13:40:33'),
(1251, 29, '2026-08-03 13:40:33', '11860.00', 650, '-0.3400', NULL, NULL, '2026-08-03 13:40:33'),
(1252, 30, '2026-08-03 13:40:33', '2670.00', 3616, '-0.7400', NULL, NULL, '2026-08-03 13:40:33'),
(1253, 31, '2026-08-03 13:40:33', '1500.00', 2122, '1.0100', NULL, NULL, '2026-08-03 13:40:33'),
(1254, 32, '2026-08-03 13:40:33', '38000.00', 4911, '0.0000', NULL, NULL, '2026-08-03 13:40:33'),
(1255, 33, '2026-08-03 13:40:33', '2325.00', 5504, '5.6800', NULL, NULL, '2026-08-03 13:40:33'),
(1256, 34, '2026-08-03 13:40:33', '8850.00', 2912, '-1.6700', NULL, NULL, '2026-08-03 13:40:33'),
(1257, 35, '2026-08-03 13:40:33', '6625.00', 16, '3.5200', NULL, NULL, '2026-08-03 13:40:33'),
(1258, 36, '2026-08-03 13:40:33', '2200.00', 3410, '0.0000', NULL, NULL, '2026-08-03 13:40:33'),
(1259, 37, '2026-08-03 13:40:33', '37700.00', 460, '-0.1300', NULL, NULL, '2026-08-03 13:40:33'),
(1260, 38, '2026-08-03 13:40:33', '15500.00', 1494, '0.0300', NULL, NULL, '2026-08-03 13:40:33'),
(1261, 39, '2026-08-03 13:40:33', '31000.00', 9720, '0.0000', NULL, NULL, '2026-08-03 13:40:33'),
(1262, 40, '2026-08-03 13:40:33', '8400.00', 2274, '1.1400', NULL, NULL, '2026-08-03 13:40:33'),
(1263, 41, '2026-08-03 13:40:33', '7500.00', 4009, '-0.8600', NULL, NULL, '2026-08-03 13:40:33'),
(1264, 42, '2026-08-03 13:40:33', '2800.00', 4508, '4.4800', NULL, NULL, '2026-08-03 13:40:33'),
(1265, 43, '2026-08-03 13:40:33', '23970.00', 2067, '3.3200', NULL, NULL, '2026-08-03 13:40:33'),
(1266, 44, '2026-08-03 13:40:33', '2975.00', 6656, '0.0000', NULL, NULL, '2026-08-03 13:40:33'),
(1267, 45, '2026-08-03 13:40:33', '3650.00', 1266, '-0.1400', NULL, NULL, '2026-08-03 13:40:33'),
(1268, 46, '2026-08-03 13:40:33', '51035.00', 16, '0.0700', NULL, NULL, '2026-08-03 13:40:33'),
(1269, 47, '2026-08-03 13:40:33', '1940.00', 2892, '-0.2600', NULL, NULL, '2026-08-03 13:40:33'),
(1270, 1, '2026-08-03 13:50:28', '2990.00', 2727, '-0.3300', NULL, NULL, '2026-08-03 13:50:28'),
(1271, 2, '2026-08-03 13:50:29', '7450.00', 20037, '-1.7800', NULL, NULL, '2026-08-03 13:50:29'),
(1272, 3, '2026-08-03 13:50:29', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 13:50:29'),
(1273, 4, '2026-08-03 13:50:29', '1925.00', 1888, '1.8500', NULL, NULL, '2026-08-03 13:50:29'),
(1274, 5, '2026-08-03 13:50:29', '8700.00', 8654, '0.0000', NULL, NULL, '2026-08-03 13:50:29'),
(1275, 6, '2026-08-03 13:50:29', '7195.00', 3214, '1.3400', NULL, NULL, '2026-08-03 13:50:29'),
(1276, 7, '2026-08-03 13:50:29', '11250.00', 4584, '4.0200', NULL, NULL, '2026-08-03 13:50:29'),
(1277, 8, '2026-08-03 13:50:29', '5600.00', 3152, '-1.1500', NULL, NULL, '2026-08-03 13:50:29'),
(1278, 9, '2026-08-03 13:50:29', '5200.00', 1012, '-1.7000', NULL, NULL, '2026-08-03 13:50:29'),
(1279, 10, '2026-08-03 13:50:29', '7685.00', 3250, '-1.3500', NULL, NULL, '2026-08-03 13:50:29'),
(1280, 11, '2026-08-03 13:50:29', '3500.00', 6833, '0.0000', NULL, NULL, '2026-08-03 13:50:29'),
(1281, 12, '2026-08-03 13:50:29', '28150.00', 1061, '0.5400', NULL, NULL, '2026-08-03 13:50:29'),
(1282, 13, '2026-08-03 13:50:29', '1670.00', 2635, '0.3000', NULL, NULL, '2026-08-03 13:50:29'),
(1283, 14, '2026-08-03 13:50:29', '5030.00', 4948, '0.6000', NULL, NULL, '2026-08-03 13:50:29'),
(1284, 15, '2026-08-03 13:50:29', '16100.00', 1482, '-0.8000', NULL, NULL, '2026-08-03 13:50:29'),
(1285, 16, '2026-08-03 13:50:29', '68.00', 755434, '3.0300', NULL, NULL, '2026-08-03 13:50:29'),
(1286, 17, '2026-08-03 13:50:29', '1970.00', 2491, '0.0000', NULL, NULL, '2026-08-03 13:50:29'),
(1287, 18, '2026-08-03 13:50:29', '4790.00', 8738, '6.4400', NULL, NULL, '2026-08-03 13:50:29'),
(1288, 19, '2026-08-03 13:50:29', '2280.00', 601, '0.0000', NULL, NULL, '2026-08-03 13:50:29'),
(1289, 20, '2026-08-03 13:50:29', '24020.00', 675, '0.0800', NULL, NULL, '2026-08-03 13:50:29'),
(1290, 21, '2026-08-03 13:50:29', '16490.00', 1776, '-0.0600', NULL, NULL, '2026-08-03 13:50:29'),
(1291, 22, '2026-08-03 13:50:29', '2945.00', 2635, '-1.6700', NULL, NULL, '2026-08-03 13:50:29'),
(1292, 23, '2026-08-03 13:50:29', '17175.00', 1157, '1.0600', NULL, NULL, '2026-08-03 13:50:29'),
(1293, 24, '2026-08-03 13:50:29', '3125.00', 3849, '-0.4800', NULL, NULL, '2026-08-03 13:50:29'),
(1294, 25, '2026-08-03 13:50:29', '8990.00', 815, '-0.1100', NULL, NULL, '2026-08-03 13:50:29'),
(1295, 26, '2026-08-03 13:50:29', '4540.00', 436, '0.5500', NULL, NULL, '2026-08-03 13:50:29'),
(1296, 27, '2026-08-03 13:50:29', '5000.00', 18271, '-2.5300', NULL, NULL, '2026-08-03 13:50:29'),
(1297, 28, '2026-08-03 13:50:29', '3700.00', 4724, '1.5100', NULL, NULL, '2026-08-03 13:50:29'),
(1298, 29, '2026-08-03 13:50:30', '11860.00', 650, '-0.3400', NULL, NULL, '2026-08-03 13:50:30'),
(1299, 30, '2026-08-03 13:50:30', '2700.00', 4208, '0.3700', NULL, NULL, '2026-08-03 13:50:30'),
(1300, 31, '2026-08-03 13:50:30', '1500.00', 2142, '1.0100', NULL, NULL, '2026-08-03 13:50:30'),
(1301, 32, '2026-08-03 13:50:30', '37995.00', 5209, '-0.0100', NULL, NULL, '2026-08-03 13:50:30'),
(1302, 33, '2026-08-03 13:50:30', '2325.00', 5676, '5.6800', NULL, NULL, '2026-08-03 13:50:30'),
(1303, 34, '2026-08-03 13:50:30', '8990.00', 3632, '-0.1100', NULL, NULL, '2026-08-03 13:50:30'),
(1304, 35, '2026-08-03 13:50:30', '6625.00', 16, '3.5200', NULL, NULL, '2026-08-03 13:50:30'),
(1305, 36, '2026-08-03 13:50:30', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 13:50:30'),
(1306, 37, '2026-08-03 13:50:30', '37690.00', 770, '-0.1600', NULL, NULL, '2026-08-03 13:50:30'),
(1307, 38, '2026-08-03 13:50:30', '15500.00', 1905, '0.0300', NULL, NULL, '2026-08-03 13:50:30'),
(1308, 39, '2026-08-03 13:50:30', '31000.00', 10077, '0.0000', NULL, NULL, '2026-08-03 13:50:30'),
(1309, 40, '2026-08-03 13:50:30', '8400.00', 2274, '1.1400', NULL, NULL, '2026-08-03 13:50:30'),
(1310, 41, '2026-08-03 13:50:30', '7500.00', 4009, '-0.8600', NULL, NULL, '2026-08-03 13:50:30'),
(1311, 42, '2026-08-03 13:50:30', '2780.00', 4613, '3.7300', NULL, NULL, '2026-08-03 13:50:30'),
(1312, 43, '2026-08-03 13:50:30', '23980.00', 2081, '3.3600', NULL, NULL, '2026-08-03 13:50:30'),
(1313, 44, '2026-08-03 13:50:30', '2980.00', 6811, '0.1700', NULL, NULL, '2026-08-03 13:50:30'),
(1314, 45, '2026-08-03 13:50:30', '3655.00', 1368, '0.0000', NULL, NULL, '2026-08-03 13:50:30'),
(1315, 46, '2026-08-03 13:50:30', '51020.00', 20, '0.0400', NULL, NULL, '2026-08-03 13:50:30'),
(1316, 47, '2026-08-03 13:50:30', '1905.00', 4103, '-2.0600', NULL, NULL, '2026-08-03 13:50:30'),
(1317, 1, '2026-08-03 14:01:53', '2990.00', 2727, '-0.3300', NULL, NULL, '2026-08-03 14:01:53'),
(1318, 2, '2026-08-03 14:01:53', '7450.00', 20037, '-1.7800', NULL, NULL, '2026-08-03 14:01:53'),
(1319, 3, '2026-08-03 14:01:53', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 14:01:53'),
(1320, 4, '2026-08-03 14:01:53', '1925.00', 1888, '1.8500', NULL, NULL, '2026-08-03 14:01:53'),
(1321, 5, '2026-08-03 14:01:53', '8700.00', 8654, '0.0000', NULL, NULL, '2026-08-03 14:01:53'),
(1322, 6, '2026-08-03 14:01:53', '7195.00', 3214, '1.3400', NULL, NULL, '2026-08-03 14:01:53'),
(1323, 7, '2026-08-03 14:01:53', '11250.00', 4584, '4.0200', NULL, NULL, '2026-08-03 14:01:53'),
(1324, 8, '2026-08-03 14:01:54', '5600.00', 3152, '-1.1500', NULL, NULL, '2026-08-03 14:01:54'),
(1325, 9, '2026-08-03 14:01:54', '5200.00', 1012, '-1.7000', NULL, NULL, '2026-08-03 14:01:54'),
(1326, 10, '2026-08-03 14:01:54', '7685.00', 3250, '-1.3500', NULL, NULL, '2026-08-03 14:01:54'),
(1327, 11, '2026-08-03 14:01:54', '3500.00', 6833, '0.0000', NULL, NULL, '2026-08-03 14:01:54'),
(1328, 12, '2026-08-03 14:01:54', '28150.00', 1061, '0.5400', NULL, NULL, '2026-08-03 14:01:54'),
(1329, 13, '2026-08-03 14:01:54', '1670.00', 2635, '0.3000', NULL, NULL, '2026-08-03 14:01:54'),
(1330, 14, '2026-08-03 14:01:54', '5030.00', 4948, '0.6000', NULL, NULL, '2026-08-03 14:01:54'),
(1331, 15, '2026-08-03 14:01:54', '16100.00', 1482, '-0.8000', NULL, NULL, '2026-08-03 14:01:54'),
(1332, 16, '2026-08-03 14:01:54', '68.00', 755434, '3.0300', NULL, NULL, '2026-08-03 14:01:54'),
(1333, 17, '2026-08-03 14:01:54', '1970.00', 2491, '0.0000', NULL, NULL, '2026-08-03 14:01:54'),
(1334, 18, '2026-08-03 14:01:54', '4790.00', 8738, '6.4400', NULL, NULL, '2026-08-03 14:01:54'),
(1335, 19, '2026-08-03 14:01:54', '2280.00', 601, '0.0000', NULL, NULL, '2026-08-03 14:01:54'),
(1336, 20, '2026-08-03 14:01:54', '24020.00', 675, '0.0800', NULL, NULL, '2026-08-03 14:01:54'),
(1337, 21, '2026-08-03 14:01:54', '16490.00', 1776, '-0.0600', NULL, NULL, '2026-08-03 14:01:54'),
(1338, 22, '2026-08-03 14:01:54', '2945.00', 2635, '-1.6700', NULL, NULL, '2026-08-03 14:01:54'),
(1339, 23, '2026-08-03 14:01:54', '17175.00', 1157, '1.0600', NULL, NULL, '2026-08-03 14:01:54'),
(1340, 24, '2026-08-03 14:01:54', '3125.00', 3849, '-0.4800', NULL, NULL, '2026-08-03 14:01:54'),
(1341, 25, '2026-08-03 14:01:54', '8990.00', 815, '-0.1100', NULL, NULL, '2026-08-03 14:01:54'),
(1342, 26, '2026-08-03 14:01:54', '4540.00', 436, '0.5500', NULL, NULL, '2026-08-03 14:01:54'),
(1343, 27, '2026-08-03 14:01:54', '5000.00', 18271, '-2.5300', NULL, NULL, '2026-08-03 14:01:54'),
(1344, 28, '2026-08-03 14:01:54', '3700.00', 4724, '1.5100', NULL, NULL, '2026-08-03 14:01:54'),
(1345, 29, '2026-08-03 14:01:54', '11860.00', 650, '-0.3400', NULL, NULL, '2026-08-03 14:01:54'),
(1346, 30, '2026-08-03 14:01:54', '2700.00', 4208, '0.3700', NULL, NULL, '2026-08-03 14:01:54'),
(1347, 31, '2026-08-03 14:01:54', '1500.00', 2142, '1.0100', NULL, NULL, '2026-08-03 14:01:54'),
(1348, 32, '2026-08-03 14:01:54', '37995.00', 5209, '-0.0100', NULL, NULL, '2026-08-03 14:01:54'),
(1349, 33, '2026-08-03 14:01:54', '2325.00', 5676, '5.6800', NULL, NULL, '2026-08-03 14:01:54'),
(1350, 34, '2026-08-03 14:01:54', '8990.00', 3632, '-0.1100', NULL, NULL, '2026-08-03 14:01:54'),
(1351, 35, '2026-08-03 14:01:54', '6625.00', 16, '3.5200', NULL, NULL, '2026-08-03 14:01:54'),
(1352, 36, '2026-08-03 14:01:54', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 14:01:54'),
(1353, 37, '2026-08-03 14:01:54', '37690.00', 770, '-0.1600', NULL, NULL, '2026-08-03 14:01:54'),
(1354, 38, '2026-08-03 14:01:54', '15500.00', 1905, '0.0300', NULL, NULL, '2026-08-03 14:01:54'),
(1355, 39, '2026-08-03 14:01:54', '31000.00', 10077, '0.0000', NULL, NULL, '2026-08-03 14:01:54'),
(1356, 40, '2026-08-03 14:01:54', '8400.00', 2274, '1.1400', NULL, NULL, '2026-08-03 14:01:54'),
(1357, 41, '2026-08-03 14:01:54', '7500.00', 4009, '-0.8600', NULL, NULL, '2026-08-03 14:01:54'),
(1358, 42, '2026-08-03 14:01:54', '2780.00', 4613, '3.7300', NULL, NULL, '2026-08-03 14:01:54'),
(1359, 43, '2026-08-03 14:01:54', '23980.00', 2081, '3.3600', NULL, NULL, '2026-08-03 14:01:54'),
(1360, 44, '2026-08-03 14:01:54', '2980.00', 6811, '0.1700', NULL, NULL, '2026-08-03 14:01:54'),
(1361, 45, '2026-08-03 14:01:54', '3655.00', 1368, '0.0000', NULL, NULL, '2026-08-03 14:01:54'),
(1362, 46, '2026-08-03 14:01:54', '51020.00', 20, '0.0400', NULL, NULL, '2026-08-03 14:01:54'),
(1363, 47, '2026-08-03 14:01:54', '1905.00', 4103, '-2.0600', NULL, NULL, '2026-08-03 14:01:54'),
(1364, 1, '2026-08-03 14:10:31', '2990.00', 2837, '-0.3300', NULL, NULL, '2026-08-03 14:10:31'),
(1365, 2, '2026-08-03 14:10:31', '7690.00', 22293, '1.3800', NULL, NULL, '2026-08-03 14:10:31'),
(1366, 3, '2026-08-03 14:10:31', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 14:10:31'),
(1367, 4, '2026-08-03 14:10:31', '1930.00', 1928, '2.1200', NULL, NULL, '2026-08-03 14:10:31'),
(1368, 5, '2026-08-03 14:10:31', '8700.00', 8739, '0.0000', NULL, NULL, '2026-08-03 14:10:31'),
(1369, 6, '2026-08-03 14:10:31', '7200.00', 3795, '1.4100', NULL, NULL, '2026-08-03 14:10:31'),
(1370, 7, '2026-08-03 14:10:31', '11355.00', 5450, '4.9900', NULL, NULL, '2026-08-03 14:10:31'),
(1371, 8, '2026-08-03 14:10:31', '5600.00', 3152, '-1.1500', NULL, NULL, '2026-08-03 14:10:31'),
(1372, 9, '2026-08-03 14:10:31', '5200.00', 1012, '-1.7000', NULL, NULL, '2026-08-03 14:10:31'),
(1373, 10, '2026-08-03 14:10:31', '7685.00', 3314, '-1.3500', NULL, NULL, '2026-08-03 14:10:31'),
(1374, 11, '2026-08-03 14:10:31', '3645.00', 6834, '4.1400', NULL, NULL, '2026-08-03 14:10:31'),
(1375, 12, '2026-08-03 14:10:31', '28100.00', 1076, '0.3600', NULL, NULL, '2026-08-03 14:10:31'),
(1376, 13, '2026-08-03 14:10:31', '1670.00', 2635, '0.3000', NULL, NULL, '2026-08-03 14:10:31'),
(1377, 14, '2026-08-03 14:10:31', '5030.00', 5151, '0.6000', NULL, NULL, '2026-08-03 14:10:31'),
(1378, 15, '2026-08-03 14:10:31', '16100.00', 1495, '-0.8000', NULL, NULL, '2026-08-03 14:10:31'),
(1379, 16, '2026-08-03 14:10:31', '69.00', 768263, '4.5500', NULL, NULL, '2026-08-03 14:10:31'),
(1380, 17, '2026-08-03 14:10:31', '1970.00', 2508, '0.0000', NULL, NULL, '2026-08-03 14:10:31'),
(1381, 18, '2026-08-03 14:10:31', '4590.00', 8753, '2.0000', NULL, NULL, '2026-08-03 14:10:31'),
(1382, 19, '2026-08-03 14:10:31', '2280.00', 603, '0.0000', NULL, NULL, '2026-08-03 14:10:31'),
(1383, 20, '2026-08-03 14:10:32', '24020.00', 675, '0.0800', NULL, NULL, '2026-08-03 14:10:32'),
(1384, 21, '2026-08-03 14:10:32', '16550.00', 1778, '0.3000', NULL, NULL, '2026-08-03 14:10:32'),
(1385, 22, '2026-08-03 14:10:32', '2940.00', 2655, '-1.8400', NULL, NULL, '2026-08-03 14:10:32'),
(1386, 23, '2026-08-03 14:10:32', '17000.00', 1161, '0.0300', NULL, NULL, '2026-08-03 14:10:32'),
(1387, 24, '2026-08-03 14:10:32', '3135.00', 5515, '-0.1600', NULL, NULL, '2026-08-03 14:10:32'),
(1388, 25, '2026-08-03 14:10:32', '9000.00', 821, '0.0000', NULL, NULL, '2026-08-03 14:10:32'),
(1389, 26, '2026-08-03 14:10:32', '4400.00', 458, '-2.5500', NULL, NULL, '2026-08-03 14:10:32'),
(1390, 27, '2026-08-03 14:10:32', '5000.00', 18571, '-2.5300', NULL, NULL, '2026-08-03 14:10:32'),
(1391, 28, '2026-08-03 14:10:32', '3700.00', 4751, '1.5100', NULL, NULL, '2026-08-03 14:10:32'),
(1392, 29, '2026-08-03 14:10:32', '11900.00', 665, '0.0000', NULL, NULL, '2026-08-03 14:10:32'),
(1393, 30, '2026-08-03 14:10:32', '2750.00', 5295, '2.2300', NULL, NULL, '2026-08-03 14:10:32'),
(1394, 31, '2026-08-03 14:10:32', '1490.00', 2249, '0.3400', NULL, NULL, '2026-08-03 14:10:32'),
(1395, 32, '2026-08-03 14:10:32', '38000.00', 5302, '0.0000', NULL, NULL, '2026-08-03 14:10:32'),
(1396, 33, '2026-08-03 14:10:32', '2325.00', 5762, '5.6800', NULL, NULL, '2026-08-03 14:10:32'),
(1397, 34, '2026-08-03 14:10:32', '8985.00', 3652, '-0.1700', NULL, NULL, '2026-08-03 14:10:32'),
(1398, 35, '2026-08-03 14:10:32', '6600.00', 19, '3.1200', NULL, NULL, '2026-08-03 14:10:32'),
(1399, 36, '2026-08-03 14:10:32', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 14:10:32'),
(1400, 37, '2026-08-03 14:10:32', '37700.00', 909, '-0.1300', NULL, NULL, '2026-08-03 14:10:32'),
(1401, 38, '2026-08-03 14:10:32', '15500.00', 1969, '0.0300', NULL, NULL, '2026-08-03 14:10:32'),
(1402, 39, '2026-08-03 14:10:32', '31000.00', 10151, '0.0000', NULL, NULL, '2026-08-03 14:10:32'),
(1403, 40, '2026-08-03 14:10:32', '8400.00', 2291, '1.1400', NULL, NULL, '2026-08-03 14:10:32'),
(1404, 41, '2026-08-03 14:10:32', '7500.00', 4009, '-0.8600', NULL, NULL, '2026-08-03 14:10:32'),
(1405, 42, '2026-08-03 14:10:32', '2820.00', 4623, '5.2200', NULL, NULL, '2026-08-03 14:10:32'),
(1406, 43, '2026-08-03 14:10:32', '23980.00', 2114, '3.3600', NULL, NULL, '2026-08-03 14:10:32'),
(1407, 44, '2026-08-03 14:10:32', '2990.00', 6825, '0.5000', NULL, NULL, '2026-08-03 14:10:32'),
(1408, 45, '2026-08-03 14:10:32', '3625.00', 2069, '-0.8200', NULL, NULL, '2026-08-03 14:10:32'),
(1409, 46, '2026-08-03 14:10:32', '51020.00', 36, '0.0400', NULL, NULL, '2026-08-03 14:10:32'),
(1410, 47, '2026-08-03 14:10:32', '1940.00', 6043, '-0.2600', NULL, NULL, '2026-08-03 14:10:32'),
(1411, 1, '2026-08-03 14:20:31', '2990.00', 2837, '-0.3300', NULL, NULL, '2026-08-03 14:20:31'),
(1412, 2, '2026-08-03 14:20:31', '7690.00', 22293, '1.3800', NULL, NULL, '2026-08-03 14:20:31'),
(1413, 3, '2026-08-03 14:20:31', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 14:20:31'),
(1414, 4, '2026-08-03 14:20:31', '1930.00', 1928, '2.1200', NULL, NULL, '2026-08-03 14:20:31'),
(1415, 5, '2026-08-03 14:20:31', '8700.00', 8739, '0.0000', NULL, NULL, '2026-08-03 14:20:31'),
(1416, 6, '2026-08-03 14:20:31', '7200.00', 3795, '1.4100', NULL, NULL, '2026-08-03 14:20:31'),
(1417, 7, '2026-08-03 14:20:31', '11355.00', 5450, '4.9900', NULL, NULL, '2026-08-03 14:20:31'),
(1418, 8, '2026-08-03 14:20:31', '5600.00', 3152, '-1.1500', NULL, NULL, '2026-08-03 14:20:31'),
(1419, 9, '2026-08-03 14:20:31', '5200.00', 1012, '-1.7000', NULL, NULL, '2026-08-03 14:20:31'),
(1420, 10, '2026-08-03 14:20:31', '7685.00', 3314, '-1.3500', NULL, NULL, '2026-08-03 14:20:31'),
(1421, 11, '2026-08-03 14:20:31', '3645.00', 6834, '4.1400', NULL, NULL, '2026-08-03 14:20:31'),
(1422, 12, '2026-08-03 14:20:31', '28100.00', 1076, '0.3600', NULL, NULL, '2026-08-03 14:20:31'),
(1423, 13, '2026-08-03 14:20:31', '1670.00', 2635, '0.3000', NULL, NULL, '2026-08-03 14:20:31'),
(1424, 14, '2026-08-03 14:20:31', '5030.00', 5151, '0.6000', NULL, NULL, '2026-08-03 14:20:31'),
(1425, 15, '2026-08-03 14:20:31', '16100.00', 1495, '-0.8000', NULL, NULL, '2026-08-03 14:20:31'),
(1426, 16, '2026-08-03 14:20:31', '69.00', 768263, '4.5500', NULL, NULL, '2026-08-03 14:20:31'),
(1427, 17, '2026-08-03 14:20:31', '1970.00', 2508, '0.0000', NULL, NULL, '2026-08-03 14:20:31'),
(1428, 18, '2026-08-03 14:20:31', '4590.00', 8753, '2.0000', NULL, NULL, '2026-08-03 14:20:31'),
(1429, 19, '2026-08-03 14:20:31', '2280.00', 603, '0.0000', NULL, NULL, '2026-08-03 14:20:31'),
(1430, 20, '2026-08-03 14:20:31', '24020.00', 675, '0.0800', NULL, NULL, '2026-08-03 14:20:31'),
(1431, 21, '2026-08-03 14:20:31', '16550.00', 1778, '0.3000', NULL, NULL, '2026-08-03 14:20:31'),
(1432, 22, '2026-08-03 14:20:31', '2940.00', 2655, '-1.8400', NULL, NULL, '2026-08-03 14:20:31'),
(1433, 23, '2026-08-03 14:20:31', '17000.00', 1161, '0.0300', NULL, NULL, '2026-08-03 14:20:31'),
(1434, 24, '2026-08-03 14:20:31', '3135.00', 5515, '-0.1600', NULL, NULL, '2026-08-03 14:20:31'),
(1435, 25, '2026-08-03 14:20:31', '9000.00', 821, '0.0000', NULL, NULL, '2026-08-03 14:20:31'),
(1436, 26, '2026-08-03 14:20:31', '4400.00', 458, '-2.5500', NULL, NULL, '2026-08-03 14:20:31'),
(1437, 27, '2026-08-03 14:20:31', '5000.00', 18571, '-2.5300', NULL, NULL, '2026-08-03 14:20:31'),
(1438, 28, '2026-08-03 14:20:31', '3700.00', 4751, '1.5100', NULL, NULL, '2026-08-03 14:20:31'),
(1439, 29, '2026-08-03 14:20:31', '11900.00', 665, '0.0000', NULL, NULL, '2026-08-03 14:20:31'),
(1440, 30, '2026-08-03 14:20:31', '2750.00', 5295, '2.2300', NULL, NULL, '2026-08-03 14:20:31'),
(1441, 31, '2026-08-03 14:20:31', '1490.00', 2249, '0.3400', NULL, NULL, '2026-08-03 14:20:31'),
(1442, 32, '2026-08-03 14:20:31', '38000.00', 5302, '0.0000', NULL, NULL, '2026-08-03 14:20:31'),
(1443, 33, '2026-08-03 14:20:31', '2325.00', 5762, '5.6800', NULL, NULL, '2026-08-03 14:20:31'),
(1444, 34, '2026-08-03 14:20:31', '8985.00', 3652, '-0.1700', NULL, NULL, '2026-08-03 14:20:31'),
(1445, 35, '2026-08-03 14:20:31', '6600.00', 19, '3.1200', NULL, NULL, '2026-08-03 14:20:31'),
(1446, 36, '2026-08-03 14:20:31', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 14:20:31'),
(1447, 37, '2026-08-03 14:20:32', '37700.00', 909, '-0.1300', NULL, NULL, '2026-08-03 14:20:32'),
(1448, 38, '2026-08-03 14:20:32', '15500.00', 1969, '0.0300', NULL, NULL, '2026-08-03 14:20:32'),
(1449, 39, '2026-08-03 14:20:32', '31000.00', 10151, '0.0000', NULL, NULL, '2026-08-03 14:20:32'),
(1450, 40, '2026-08-03 14:20:32', '8400.00', 2291, '1.1400', NULL, NULL, '2026-08-03 14:20:32'),
(1451, 41, '2026-08-03 14:20:32', '7500.00', 4009, '-0.8600', NULL, NULL, '2026-08-03 14:20:32'),
(1452, 42, '2026-08-03 14:20:32', '2820.00', 4623, '5.2200', NULL, NULL, '2026-08-03 14:20:32'),
(1453, 43, '2026-08-03 14:20:32', '23980.00', 2114, '3.3600', NULL, NULL, '2026-08-03 14:20:32'),
(1454, 44, '2026-08-03 14:20:32', '2990.00', 6825, '0.5000', NULL, NULL, '2026-08-03 14:20:32'),
(1455, 45, '2026-08-03 14:20:32', '3625.00', 2069, '-0.8200', NULL, NULL, '2026-08-03 14:20:32'),
(1456, 46, '2026-08-03 14:20:32', '51020.00', 36, '0.0400', NULL, NULL, '2026-08-03 14:20:32'),
(1457, 47, '2026-08-03 14:20:32', '1940.00', 6043, '-0.2600', NULL, NULL, '2026-08-03 14:20:32'),
(1458, 1, '2026-08-03 14:31:58', '2990.00', 2842, '-0.3300', NULL, NULL, '2026-08-03 14:31:58'),
(1459, 2, '2026-08-03 14:31:58', '7690.00', 22433, '1.3800', NULL, NULL, '2026-08-03 14:31:58'),
(1460, 3, '2026-08-03 14:31:58', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 14:31:58'),
(1461, 4, '2026-08-03 14:31:58', '1930.00', 2336, '2.1200', NULL, NULL, '2026-08-03 14:31:58'),
(1462, 5, '2026-08-03 14:31:58', '8695.00', 8777, '-0.0600', NULL, NULL, '2026-08-03 14:31:58'),
(1463, 6, '2026-08-03 14:31:58', '7200.00', 3920, '1.4100', NULL, NULL, '2026-08-03 14:31:58'),
(1464, 7, '2026-08-03 14:31:58', '11350.00', 5608, '4.9500', NULL, NULL, '2026-08-03 14:31:58'),
(1465, 8, '2026-08-03 14:31:58', '5625.00', 3155, '-0.7100', NULL, NULL, '2026-08-03 14:31:58'),
(1466, 9, '2026-08-03 14:31:58', '5200.00', 1036, '-1.7000', NULL, NULL, '2026-08-03 14:31:58'),
(1467, 10, '2026-08-03 14:31:58', '7685.00', 3389, '-1.3500', NULL, NULL, '2026-08-03 14:31:58'),
(1468, 11, '2026-08-03 14:31:58', '3645.00', 6890, '4.1400', NULL, NULL, '2026-08-03 14:31:58'),
(1469, 12, '2026-08-03 14:31:58', '28490.00', 1113, '1.7500', NULL, NULL, '2026-08-03 14:31:58'),
(1470, 13, '2026-08-03 14:31:58', '1695.00', 2656, '1.8000', NULL, NULL, '2026-08-03 14:31:58'),
(1471, 14, '2026-08-03 14:31:58', '5000.00', 6428, '0.0000', NULL, NULL, '2026-08-03 14:31:58'),
(1472, 15, '2026-08-03 14:31:58', '16100.00', 1507, '-0.8000', NULL, NULL, '2026-08-03 14:31:58'),
(1473, 16, '2026-08-03 14:31:58', '69.00', 836211, '4.5500', NULL, NULL, '2026-08-03 14:31:58'),
(1474, 17, '2026-08-03 14:31:58', '1970.00', 2508, '0.0000', NULL, NULL, '2026-08-03 14:31:58'),
(1475, 18, '2026-08-03 14:31:58', '4560.00', 9055, '1.3300', NULL, NULL, '2026-08-03 14:31:58'),
(1476, 19, '2026-08-03 14:31:58', '2280.00', 643, '0.0000', NULL, NULL, '2026-08-03 14:31:58'),
(1477, 20, '2026-08-03 14:31:58', '24020.00', 688, '0.0800', NULL, NULL, '2026-08-03 14:31:58'),
(1478, 21, '2026-08-03 14:31:58', '16550.00', 1778, '0.3000', NULL, NULL, '2026-08-03 14:31:58'),
(1479, 22, '2026-08-03 14:31:58', '2990.00', 2793, '-0.1700', NULL, NULL, '2026-08-03 14:31:58'),
(1480, 23, '2026-08-03 14:31:58', '17000.00', 1175, '0.0300', NULL, NULL, '2026-08-03 14:31:58'),
(1481, 24, '2026-08-03 14:31:58', '3130.00', 5561, '-0.3200', NULL, NULL, '2026-08-03 14:31:58'),
(1482, 25, '2026-08-03 14:31:58', '8990.00', 831, '-0.1100', NULL, NULL, '2026-08-03 14:31:58'),
(1483, 26, '2026-08-03 14:31:58', '4400.00', 458, '-2.5500', NULL, NULL, '2026-08-03 14:31:58'),
(1484, 27, '2026-08-03 14:31:58', '5000.00', 18854, '-2.5300', NULL, NULL, '2026-08-03 14:31:58'),
(1485, 28, '2026-08-03 14:31:58', '3700.00', 4780, '1.5100', NULL, NULL, '2026-08-03 14:31:58'),
(1486, 29, '2026-08-03 14:31:58', '11900.00', 669, '0.0000', NULL, NULL, '2026-08-03 14:31:58'),
(1487, 30, '2026-08-03 14:31:58', '2750.00', 5315, '2.2300', NULL, NULL, '2026-08-03 14:31:58'),
(1488, 31, '2026-08-03 14:31:58', '1480.00', 2293, '-0.3400', NULL, NULL, '2026-08-03 14:31:58'),
(1489, 32, '2026-08-03 14:31:58', '37995.00', 5427, '-0.0100', NULL, NULL, '2026-08-03 14:31:58'),
(1490, 33, '2026-08-03 14:31:58', '2325.00', 5795, '5.6800', NULL, NULL, '2026-08-03 14:31:58'),
(1491, 34, '2026-08-03 14:31:58', '8800.00', 4617, '-2.2200', NULL, NULL, '2026-08-03 14:31:58'),
(1492, 35, '2026-08-03 14:31:58', '6600.00', 19, '3.1200', NULL, NULL, '2026-08-03 14:31:58'),
(1493, 36, '2026-08-03 14:31:58', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 14:31:58'),
(1494, 37, '2026-08-03 14:31:58', '37700.00', 935, '-0.1300', NULL, NULL, '2026-08-03 14:31:58'),
(1495, 38, '2026-08-03 14:31:58', '15500.00', 1972, '0.0300', NULL, NULL, '2026-08-03 14:31:58'),
(1496, 39, '2026-08-03 14:31:58', '31000.00', 10204, '0.0000', NULL, NULL, '2026-08-03 14:31:58'),
(1497, 40, '2026-08-03 14:31:58', '8400.00', 2652, '1.1400', NULL, NULL, '2026-08-03 14:31:58'),
(1498, 41, '2026-08-03 14:31:58', '7500.00', 4030, '-0.8600', NULL, NULL, '2026-08-03 14:31:58'),
(1499, 42, '2026-08-03 14:31:58', '2820.00', 4652, '5.2200', NULL, NULL, '2026-08-03 14:31:58'),
(1500, 43, '2026-08-03 14:31:58', '23985.00', 2331, '3.3800', NULL, NULL, '2026-08-03 14:31:58'),
(1501, 44, '2026-08-03 14:31:58', '2990.00', 7265, '0.5000', NULL, NULL, '2026-08-03 14:31:58'),
(1502, 45, '2026-08-03 14:31:58', '3655.00', 2070, '0.0000', NULL, NULL, '2026-08-03 14:31:58'),
(1503, 46, '2026-08-03 14:31:58', '51020.00', 37, '0.0400', NULL, NULL, '2026-08-03 14:31:58'),
(1504, 47, '2026-08-03 14:31:58', '1900.00', 6968, '-2.3100', NULL, NULL, '2026-08-03 14:31:58'),
(1505, 1, '2026-08-03 14:40:30', '2990.00', 2842, '-0.3300', NULL, NULL, '2026-08-03 14:40:30'),
(1506, 2, '2026-08-03 14:40:31', '7690.00', 22433, '1.3800', NULL, NULL, '2026-08-03 14:40:31'),
(1507, 3, '2026-08-03 14:40:31', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 14:40:31'),
(1508, 4, '2026-08-03 14:40:31', '1930.00', 2336, '2.1200', NULL, NULL, '2026-08-03 14:40:31'),
(1509, 5, '2026-08-03 14:40:31', '8695.00', 8777, '-0.0600', NULL, NULL, '2026-08-03 14:40:31'),
(1510, 6, '2026-08-03 14:40:31', '7200.00', 3920, '1.4100', NULL, NULL, '2026-08-03 14:40:31'),
(1511, 7, '2026-08-03 14:40:31', '11350.00', 5608, '4.9500', NULL, NULL, '2026-08-03 14:40:31'),
(1512, 8, '2026-08-03 14:40:32', '5625.00', 3155, '-0.7100', NULL, NULL, '2026-08-03 14:40:32'),
(1513, 9, '2026-08-03 14:40:32', '5200.00', 1036, '-1.7000', NULL, NULL, '2026-08-03 14:40:32'),
(1514, 10, '2026-08-03 14:40:32', '7685.00', 3389, '-1.3500', NULL, NULL, '2026-08-03 14:40:32'),
(1515, 11, '2026-08-03 14:40:32', '3645.00', 6890, '4.1400', NULL, NULL, '2026-08-03 14:40:32'),
(1516, 12, '2026-08-03 14:40:32', '28490.00', 1113, '1.7500', NULL, NULL, '2026-08-03 14:40:32'),
(1517, 13, '2026-08-03 14:40:32', '1695.00', 2656, '1.8000', NULL, NULL, '2026-08-03 14:40:32'),
(1518, 14, '2026-08-03 14:40:32', '5000.00', 6428, '0.0000', NULL, NULL, '2026-08-03 14:40:32'),
(1519, 15, '2026-08-03 14:40:32', '16100.00', 1507, '-0.8000', NULL, NULL, '2026-08-03 14:40:32'),
(1520, 16, '2026-08-03 14:40:32', '69.00', 836211, '4.5500', NULL, NULL, '2026-08-03 14:40:32'),
(1521, 17, '2026-08-03 14:40:32', '1970.00', 2508, '0.0000', NULL, NULL, '2026-08-03 14:40:32'),
(1522, 18, '2026-08-03 14:40:32', '4560.00', 9055, '1.3300', NULL, NULL, '2026-08-03 14:40:32'),
(1523, 19, '2026-08-03 14:40:32', '2280.00', 643, '0.0000', NULL, NULL, '2026-08-03 14:40:32'),
(1524, 20, '2026-08-03 14:40:32', '24020.00', 688, '0.0800', NULL, NULL, '2026-08-03 14:40:32'),
(1525, 21, '2026-08-03 14:40:33', '16550.00', 1778, '0.3000', NULL, NULL, '2026-08-03 14:40:33'),
(1526, 22, '2026-08-03 14:40:33', '2990.00', 2793, '-0.1700', NULL, NULL, '2026-08-03 14:40:33'),
(1527, 23, '2026-08-03 14:40:33', '17000.00', 1175, '0.0300', NULL, NULL, '2026-08-03 14:40:33'),
(1528, 24, '2026-08-03 14:40:33', '3130.00', 5561, '-0.3200', NULL, NULL, '2026-08-03 14:40:33'),
(1529, 25, '2026-08-03 14:40:33', '8990.00', 831, '-0.1100', NULL, NULL, '2026-08-03 14:40:33'),
(1530, 26, '2026-08-03 14:40:33', '4400.00', 458, '-2.5500', NULL, NULL, '2026-08-03 14:40:33'),
(1531, 27, '2026-08-03 14:40:33', '5000.00', 18854, '-2.5300', NULL, NULL, '2026-08-03 14:40:33'),
(1532, 28, '2026-08-03 14:40:33', '3700.00', 4780, '1.5100', NULL, NULL, '2026-08-03 14:40:33'),
(1533, 29, '2026-08-03 14:40:33', '11900.00', 669, '0.0000', NULL, NULL, '2026-08-03 14:40:33'),
(1534, 30, '2026-08-03 14:40:33', '2750.00', 5315, '2.2300', NULL, NULL, '2026-08-03 14:40:33'),
(1535, 31, '2026-08-03 14:40:33', '1480.00', 2293, '-0.3400', NULL, NULL, '2026-08-03 14:40:33'),
(1536, 32, '2026-08-03 14:40:33', '37995.00', 5427, '-0.0100', NULL, NULL, '2026-08-03 14:40:33'),
(1537, 33, '2026-08-03 14:40:33', '2325.00', 5795, '5.6800', NULL, NULL, '2026-08-03 14:40:33'),
(1538, 34, '2026-08-03 14:40:33', '8800.00', 4617, '-2.2200', NULL, NULL, '2026-08-03 14:40:33'),
(1539, 35, '2026-08-03 14:40:33', '6600.00', 19, '3.1200', NULL, NULL, '2026-08-03 14:40:33'),
(1540, 36, '2026-08-03 14:40:33', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 14:40:33'),
(1541, 37, '2026-08-03 14:40:33', '37700.00', 935, '-0.1300', NULL, NULL, '2026-08-03 14:40:33'),
(1542, 38, '2026-08-03 14:40:33', '15500.00', 1972, '0.0300', NULL, NULL, '2026-08-03 14:40:33'),
(1543, 39, '2026-08-03 14:40:33', '31000.00', 10204, '0.0000', NULL, NULL, '2026-08-03 14:40:33'),
(1544, 40, '2026-08-03 14:40:33', '8400.00', 2652, '1.1400', NULL, NULL, '2026-08-03 14:40:33'),
(1545, 41, '2026-08-03 14:40:33', '7500.00', 4030, '-0.8600', NULL, NULL, '2026-08-03 14:40:33'),
(1546, 42, '2026-08-03 14:40:33', '2820.00', 4652, '5.2200', NULL, NULL, '2026-08-03 14:40:33'),
(1547, 43, '2026-08-03 14:40:33', '23985.00', 2331, '3.3800', NULL, NULL, '2026-08-03 14:40:33'),
(1548, 44, '2026-08-03 14:40:33', '2990.00', 7265, '0.5000', NULL, NULL, '2026-08-03 14:40:33'),
(1549, 45, '2026-08-03 14:40:33', '3655.00', 2070, '0.0000', NULL, NULL, '2026-08-03 14:40:33'),
(1550, 46, '2026-08-03 14:40:33', '51020.00', 37, '0.0400', NULL, NULL, '2026-08-03 14:40:33'),
(1551, 47, '2026-08-03 14:40:33', '1900.00', 6968, '-2.3100', NULL, NULL, '2026-08-03 14:40:33'),
(1552, 1, '2026-08-03 14:50:29', '2990.00', 2842, '-0.3300', NULL, NULL, '2026-08-03 14:50:29'),
(1553, 2, '2026-08-03 14:50:29', '7690.00', 22443, '1.3800', NULL, NULL, '2026-08-03 14:50:29'),
(1554, 3, '2026-08-03 14:50:29', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 14:50:29'),
(1555, 4, '2026-08-03 14:50:29', '1930.00', 2336, '2.1200', NULL, NULL, '2026-08-03 14:50:29'),
(1556, 5, '2026-08-03 14:50:29', '8695.00', 8777, '-0.0600', NULL, NULL, '2026-08-03 14:50:29'),
(1557, 6, '2026-08-03 14:50:29', '7200.00', 3920, '1.4100', NULL, NULL, '2026-08-03 14:50:29'),
(1558, 7, '2026-08-03 14:50:29', '11350.00', 5608, '4.9500', NULL, NULL, '2026-08-03 14:50:29'),
(1559, 8, '2026-08-03 14:50:29', '5625.00', 3155, '-0.7100', NULL, NULL, '2026-08-03 14:50:29'),
(1560, 9, '2026-08-03 14:50:29', '5200.00', 1036, '-1.7000', NULL, NULL, '2026-08-03 14:50:29'),
(1561, 10, '2026-08-03 14:50:29', '7685.00', 3389, '-1.3500', NULL, NULL, '2026-08-03 14:50:29'),
(1562, 11, '2026-08-03 14:50:29', '3645.00', 6890, '4.1400', NULL, NULL, '2026-08-03 14:50:29'),
(1563, 12, '2026-08-03 14:50:29', '28490.00', 1113, '1.7500', NULL, NULL, '2026-08-03 14:50:29'),
(1564, 13, '2026-08-03 14:50:29', '1695.00', 2656, '1.8000', NULL, NULL, '2026-08-03 14:50:29'),
(1565, 14, '2026-08-03 14:50:29', '5000.00', 6428, '0.0000', NULL, NULL, '2026-08-03 14:50:29'),
(1566, 15, '2026-08-03 14:50:29', '16100.00', 1507, '-0.8000', NULL, NULL, '2026-08-03 14:50:29'),
(1567, 16, '2026-08-03 14:50:29', '69.00', 836211, '4.5500', NULL, NULL, '2026-08-03 14:50:29'),
(1568, 17, '2026-08-03 14:50:29', '1910.00', 3008, '-3.0500', NULL, NULL, '2026-08-03 14:50:29'),
(1569, 18, '2026-08-03 14:50:29', '4560.00', 9055, '1.3300', NULL, NULL, '2026-08-03 14:50:29'),
(1570, 19, '2026-08-03 14:50:29', '2280.00', 643, '0.0000', NULL, NULL, '2026-08-03 14:50:29'),
(1571, 20, '2026-08-03 14:50:29', '24020.00', 688, '0.0800', NULL, NULL, '2026-08-03 14:50:29'),
(1572, 21, '2026-08-03 14:50:29', '16550.00', 1778, '0.3000', NULL, NULL, '2026-08-03 14:50:29'),
(1573, 22, '2026-08-03 14:50:29', '2990.00', 2793, '-0.1700', NULL, NULL, '2026-08-03 14:50:29'),
(1574, 23, '2026-08-03 14:50:29', '17000.00', 1175, '0.0300', NULL, NULL, '2026-08-03 14:50:29'),
(1575, 24, '2026-08-03 14:50:29', '3130.00', 5561, '-0.3200', NULL, NULL, '2026-08-03 14:50:29'),
(1576, 25, '2026-08-03 14:50:29', '8990.00', 831, '-0.1100', NULL, NULL, '2026-08-03 14:50:29'),
(1577, 26, '2026-08-03 14:50:29', '4400.00', 458, '-2.5500', NULL, NULL, '2026-08-03 14:50:29'),
(1578, 27, '2026-08-03 14:50:29', '5000.00', 18854, '-2.5300', NULL, NULL, '2026-08-03 14:50:29'),
(1579, 28, '2026-08-03 14:50:29', '3700.00', 4780, '1.5100', NULL, NULL, '2026-08-03 14:50:29'),
(1580, 29, '2026-08-03 14:50:29', '11900.00', 669, '0.0000', NULL, NULL, '2026-08-03 14:50:29'),
(1581, 30, '2026-08-03 14:50:29', '2750.00', 5315, '2.2300', NULL, NULL, '2026-08-03 14:50:29'),
(1582, 31, '2026-08-03 14:50:29', '1480.00', 2293, '-0.3400', NULL, NULL, '2026-08-03 14:50:29'),
(1583, 32, '2026-08-03 14:50:29', '37995.00', 5427, '-0.0100', NULL, NULL, '2026-08-03 14:50:29'),
(1584, 33, '2026-08-03 14:50:29', '2325.00', 5795, '5.6800', NULL, NULL, '2026-08-03 14:50:29'),
(1585, 34, '2026-08-03 14:50:29', '8800.00', 4617, '-2.2200', NULL, NULL, '2026-08-03 14:50:29'),
(1586, 35, '2026-08-03 14:50:29', '6600.00', 19, '3.1200', NULL, NULL, '2026-08-03 14:50:29'),
(1587, 36, '2026-08-03 14:50:29', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 14:50:29');
INSERT INTO `intraday_quotes` (`id`, `company_id`, `quote_datetime`, `price`, `volume`, `variation_percent`, `bid_price`, `ask_price`, `created_at`) VALUES
(1588, 37, '2026-08-03 14:50:29', '37700.00', 935, '-0.1300', NULL, NULL, '2026-08-03 14:50:29'),
(1589, 38, '2026-08-03 14:50:29', '15500.00', 1972, '0.0300', NULL, NULL, '2026-08-03 14:50:29'),
(1590, 39, '2026-08-03 14:50:29', '31000.00', 10204, '0.0000', NULL, NULL, '2026-08-03 14:50:29'),
(1591, 40, '2026-08-03 14:50:29', '8400.00', 2652, '1.1400', NULL, NULL, '2026-08-03 14:50:29'),
(1592, 41, '2026-08-03 14:50:29', '7500.00', 4030, '-0.8600', NULL, NULL, '2026-08-03 14:50:29'),
(1593, 42, '2026-08-03 14:50:29', '2820.00', 4652, '5.2200', NULL, NULL, '2026-08-03 14:50:29'),
(1594, 43, '2026-08-03 14:50:29', '23985.00', 2331, '3.3800', NULL, NULL, '2026-08-03 14:50:29'),
(1595, 44, '2026-08-03 14:50:29', '2990.00', 7265, '0.5000', NULL, NULL, '2026-08-03 14:50:29'),
(1596, 45, '2026-08-03 14:50:29', '3655.00', 2070, '0.0000', NULL, NULL, '2026-08-03 14:50:29'),
(1597, 46, '2026-08-03 14:50:29', '51020.00', 37, '0.0400', NULL, NULL, '2026-08-03 14:50:29'),
(1598, 47, '2026-08-03 14:50:29', '1900.00', 6968, '-2.3100', NULL, NULL, '2026-08-03 14:50:29'),
(1599, 1, '2026-08-03 15:01:30', '2990.00', 2842, '-0.3300', NULL, NULL, '2026-08-03 15:01:30'),
(1600, 2, '2026-08-03 15:01:30', '7690.00', 22443, '1.3800', NULL, NULL, '2026-08-03 15:01:30'),
(1601, 3, '2026-08-03 15:01:30', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 15:01:30'),
(1602, 4, '2026-08-03 15:01:30', '1930.00', 2336, '2.1200', NULL, NULL, '2026-08-03 15:01:30'),
(1603, 5, '2026-08-03 15:01:31', '8695.00', 8777, '-0.0600', NULL, NULL, '2026-08-03 15:01:31'),
(1604, 6, '2026-08-03 15:01:31', '7200.00', 3920, '1.4100', NULL, NULL, '2026-08-03 15:01:31'),
(1605, 7, '2026-08-03 15:01:31', '11350.00', 5608, '4.9500', NULL, NULL, '2026-08-03 15:01:31'),
(1606, 8, '2026-08-03 15:01:31', '5625.00', 3155, '-0.7100', NULL, NULL, '2026-08-03 15:01:31'),
(1607, 9, '2026-08-03 15:01:31', '5200.00', 1036, '-1.7000', NULL, NULL, '2026-08-03 15:01:31'),
(1608, 10, '2026-08-03 15:01:31', '7685.00', 3389, '-1.3500', NULL, NULL, '2026-08-03 15:01:31'),
(1609, 11, '2026-08-03 15:01:31', '3645.00', 6890, '4.1400', NULL, NULL, '2026-08-03 15:01:31'),
(1610, 12, '2026-08-03 15:01:31', '28490.00', 1113, '1.7500', NULL, NULL, '2026-08-03 15:01:31'),
(1611, 13, '2026-08-03 15:01:31', '1695.00', 2656, '1.8000', NULL, NULL, '2026-08-03 15:01:31'),
(1612, 14, '2026-08-03 15:01:31', '5000.00', 6428, '0.0000', NULL, NULL, '2026-08-03 15:01:31'),
(1613, 15, '2026-08-03 15:01:31', '16100.00', 1507, '-0.8000', NULL, NULL, '2026-08-03 15:01:31'),
(1614, 16, '2026-08-03 15:01:31', '69.00', 836211, '4.5500', NULL, NULL, '2026-08-03 15:01:31'),
(1615, 17, '2026-08-03 15:01:31', '1910.00', 3008, '-3.0500', NULL, NULL, '2026-08-03 15:01:31'),
(1616, 18, '2026-08-03 15:01:31', '4560.00', 9055, '1.3300', NULL, NULL, '2026-08-03 15:01:31'),
(1617, 19, '2026-08-03 15:01:31', '2280.00', 643, '0.0000', NULL, NULL, '2026-08-03 15:01:31'),
(1618, 20, '2026-08-03 15:01:31', '24020.00', 688, '0.0800', NULL, NULL, '2026-08-03 15:01:31'),
(1619, 21, '2026-08-03 15:01:31', '16550.00', 1778, '0.3000', NULL, NULL, '2026-08-03 15:01:31'),
(1620, 22, '2026-08-03 15:01:31', '2990.00', 2793, '-0.1700', NULL, NULL, '2026-08-03 15:01:31'),
(1621, 23, '2026-08-03 15:01:31', '17000.00', 1175, '0.0300', NULL, NULL, '2026-08-03 15:01:31'),
(1622, 24, '2026-08-03 15:01:31', '3130.00', 5561, '-0.3200', NULL, NULL, '2026-08-03 15:01:31'),
(1623, 25, '2026-08-03 15:01:31', '8990.00', 831, '-0.1100', NULL, NULL, '2026-08-03 15:01:31'),
(1624, 26, '2026-08-03 15:01:31', '4400.00', 458, '-2.5500', NULL, NULL, '2026-08-03 15:01:31'),
(1625, 27, '2026-08-03 15:01:31', '5000.00', 18854, '-2.5300', NULL, NULL, '2026-08-03 15:01:31'),
(1626, 28, '2026-08-03 15:01:31', '3700.00', 4780, '1.5100', NULL, NULL, '2026-08-03 15:01:31'),
(1627, 29, '2026-08-03 15:01:31', '11900.00', 669, '0.0000', NULL, NULL, '2026-08-03 15:01:31'),
(1628, 30, '2026-08-03 15:01:31', '2750.00', 5315, '2.2300', NULL, NULL, '2026-08-03 15:01:31'),
(1629, 31, '2026-08-03 15:01:31', '1480.00', 2293, '-0.3400', NULL, NULL, '2026-08-03 15:01:31'),
(1630, 32, '2026-08-03 15:01:31', '37995.00', 5427, '-0.0100', NULL, NULL, '2026-08-03 15:01:31'),
(1631, 33, '2026-08-03 15:01:31', '2325.00', 5795, '5.6800', NULL, NULL, '2026-08-03 15:01:31'),
(1632, 34, '2026-08-03 15:01:31', '8800.00', 4617, '-2.2200', NULL, NULL, '2026-08-03 15:01:31'),
(1633, 35, '2026-08-03 15:01:31', '6600.00', 19, '3.1200', NULL, NULL, '2026-08-03 15:01:31'),
(1634, 36, '2026-08-03 15:01:31', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 15:01:31'),
(1635, 37, '2026-08-03 15:01:31', '37700.00', 935, '-0.1300', NULL, NULL, '2026-08-03 15:01:31'),
(1636, 38, '2026-08-03 15:01:31', '15500.00', 1972, '0.0300', NULL, NULL, '2026-08-03 15:01:31'),
(1637, 39, '2026-08-03 15:01:31', '31000.00', 10204, '0.0000', NULL, NULL, '2026-08-03 15:01:31'),
(1638, 40, '2026-08-03 15:01:31', '8400.00', 2652, '1.1400', NULL, NULL, '2026-08-03 15:01:31'),
(1639, 41, '2026-08-03 15:01:31', '7500.00', 4030, '-0.8600', NULL, NULL, '2026-08-03 15:01:31'),
(1640, 42, '2026-08-03 15:01:31', '2820.00', 4652, '5.2200', NULL, NULL, '2026-08-03 15:01:31'),
(1641, 43, '2026-08-03 15:01:31', '23985.00', 2331, '3.3800', NULL, NULL, '2026-08-03 15:01:31'),
(1642, 44, '2026-08-03 15:01:31', '2990.00', 7265, '0.5000', NULL, NULL, '2026-08-03 15:01:31'),
(1643, 45, '2026-08-03 15:01:31', '3655.00', 2070, '0.0000', NULL, NULL, '2026-08-03 15:01:31'),
(1644, 46, '2026-08-03 15:01:31', '51020.00', 37, '0.0400', NULL, NULL, '2026-08-03 15:01:31'),
(1645, 47, '2026-08-03 15:01:31', '1900.00', 6968, '-2.3100', NULL, NULL, '2026-08-03 15:01:31'),
(1646, 1, '2026-08-03 15:10:28', '2990.00', 2842, '-0.3300', NULL, NULL, '2026-08-03 15:10:28'),
(1647, 2, '2026-08-03 15:10:29', '7690.00', 22443, '1.3800', NULL, NULL, '2026-08-03 15:10:29'),
(1648, 3, '2026-08-03 15:10:29', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 15:10:29'),
(1649, 4, '2026-08-03 15:10:29', '1930.00', 2336, '2.1200', NULL, NULL, '2026-08-03 15:10:29'),
(1650, 5, '2026-08-03 15:10:29', '8695.00', 8777, '-0.0600', NULL, NULL, '2026-08-03 15:10:29'),
(1651, 6, '2026-08-03 15:10:29', '7200.00', 3920, '1.4100', NULL, NULL, '2026-08-03 15:10:29'),
(1652, 7, '2026-08-03 15:10:29', '11350.00', 5608, '4.9500', NULL, NULL, '2026-08-03 15:10:29'),
(1653, 8, '2026-08-03 15:10:29', '5625.00', 3155, '-0.7100', NULL, NULL, '2026-08-03 15:10:29'),
(1654, 9, '2026-08-03 15:10:29', '5200.00', 1036, '-1.7000', NULL, NULL, '2026-08-03 15:10:29'),
(1655, 10, '2026-08-03 15:10:29', '7685.00', 3389, '-1.3500', NULL, NULL, '2026-08-03 15:10:29'),
(1656, 11, '2026-08-03 15:10:29', '3645.00', 6890, '4.1400', NULL, NULL, '2026-08-03 15:10:29'),
(1657, 12, '2026-08-03 15:10:29', '28490.00', 1113, '1.7500', NULL, NULL, '2026-08-03 15:10:29'),
(1658, 13, '2026-08-03 15:10:29', '1695.00', 2656, '1.8000', NULL, NULL, '2026-08-03 15:10:29'),
(1659, 14, '2026-08-03 15:10:29', '5000.00', 6428, '0.0000', NULL, NULL, '2026-08-03 15:10:29'),
(1660, 15, '2026-08-03 15:10:29', '16100.00', 1507, '-0.8000', NULL, NULL, '2026-08-03 15:10:29'),
(1661, 16, '2026-08-03 15:10:29', '69.00', 836211, '4.5500', NULL, NULL, '2026-08-03 15:10:29'),
(1662, 17, '2026-08-03 15:10:29', '1910.00', 3008, '-3.0500', NULL, NULL, '2026-08-03 15:10:29'),
(1663, 18, '2026-08-03 15:10:29', '4560.00', 9055, '1.3300', NULL, NULL, '2026-08-03 15:10:29'),
(1664, 19, '2026-08-03 15:10:29', '2280.00', 643, '0.0000', NULL, NULL, '2026-08-03 15:10:29'),
(1665, 20, '2026-08-03 15:10:29', '24020.00', 688, '0.0800', NULL, NULL, '2026-08-03 15:10:29'),
(1666, 21, '2026-08-03 15:10:29', '16550.00', 1778, '0.3000', NULL, NULL, '2026-08-03 15:10:29'),
(1667, 22, '2026-08-03 15:10:29', '2990.00', 2793, '-0.1700', NULL, NULL, '2026-08-03 15:10:29'),
(1668, 23, '2026-08-03 15:10:29', '17000.00', 1175, '0.0300', NULL, NULL, '2026-08-03 15:10:29'),
(1669, 24, '2026-08-03 15:10:29', '3130.00', 5561, '-0.3200', NULL, NULL, '2026-08-03 15:10:29'),
(1670, 25, '2026-08-03 15:10:29', '8990.00', 831, '-0.1100', NULL, NULL, '2026-08-03 15:10:29'),
(1671, 26, '2026-08-03 15:10:29', '4400.00', 458, '-2.5500', NULL, NULL, '2026-08-03 15:10:29'),
(1672, 27, '2026-08-03 15:10:29', '5000.00', 18854, '-2.5300', NULL, NULL, '2026-08-03 15:10:29'),
(1673, 28, '2026-08-03 15:10:29', '3700.00', 4780, '1.5100', NULL, NULL, '2026-08-03 15:10:29'),
(1674, 29, '2026-08-03 15:10:29', '11900.00', 669, '0.0000', NULL, NULL, '2026-08-03 15:10:29'),
(1675, 30, '2026-08-03 15:10:29', '2750.00', 5315, '2.2300', NULL, NULL, '2026-08-03 15:10:29'),
(1676, 31, '2026-08-03 15:10:29', '1480.00', 2293, '-0.3400', NULL, NULL, '2026-08-03 15:10:29'),
(1677, 32, '2026-08-03 15:10:29', '37995.00', 5427, '-0.0100', NULL, NULL, '2026-08-03 15:10:29'),
(1678, 33, '2026-08-03 15:10:29', '2325.00', 5795, '5.6800', NULL, NULL, '2026-08-03 15:10:29'),
(1679, 34, '2026-08-03 15:10:29', '8800.00', 4617, '-2.2200', NULL, NULL, '2026-08-03 15:10:29'),
(1680, 35, '2026-08-03 15:10:29', '6600.00', 19, '3.1200', NULL, NULL, '2026-08-03 15:10:29'),
(1681, 36, '2026-08-03 15:10:29', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 15:10:29'),
(1682, 37, '2026-08-03 15:10:29', '37700.00', 935, '-0.1300', NULL, NULL, '2026-08-03 15:10:29'),
(1683, 38, '2026-08-03 15:10:29', '15500.00', 1972, '0.0300', NULL, NULL, '2026-08-03 15:10:29'),
(1684, 39, '2026-08-03 15:10:29', '31000.00', 10204, '0.0000', NULL, NULL, '2026-08-03 15:10:29'),
(1685, 40, '2026-08-03 15:10:29', '8400.00', 2652, '1.1400', NULL, NULL, '2026-08-03 15:10:29'),
(1686, 41, '2026-08-03 15:10:29', '7500.00', 4030, '-0.8600', NULL, NULL, '2026-08-03 15:10:29'),
(1687, 42, '2026-08-03 15:10:29', '2820.00', 4652, '5.2200', NULL, NULL, '2026-08-03 15:10:29'),
(1688, 43, '2026-08-03 15:10:30', '23985.00', 2331, '3.3800', NULL, NULL, '2026-08-03 15:10:30'),
(1689, 44, '2026-08-03 15:10:30', '2990.00', 7265, '0.5000', NULL, NULL, '2026-08-03 15:10:30'),
(1690, 45, '2026-08-03 15:10:30', '3655.00', 2070, '0.0000', NULL, NULL, '2026-08-03 15:10:30'),
(1691, 46, '2026-08-03 15:10:30', '51020.00', 37, '0.0400', NULL, NULL, '2026-08-03 15:10:30'),
(1692, 47, '2026-08-03 15:10:30', '1900.00', 6968, '-2.3100', NULL, NULL, '2026-08-03 15:10:30'),
(1693, 1, '2026-08-03 15:20:28', '2990.00', 2842, '-0.3300', NULL, NULL, '2026-08-03 15:20:28'),
(1694, 2, '2026-08-03 15:20:28', '7690.00', 22443, '1.3800', NULL, NULL, '2026-08-03 15:20:28'),
(1695, 3, '2026-08-03 15:20:28', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 15:20:28'),
(1696, 4, '2026-08-03 15:20:28', '1930.00', 2336, '2.1200', NULL, NULL, '2026-08-03 15:20:28'),
(1697, 5, '2026-08-03 15:20:28', '8695.00', 8777, '-0.0600', NULL, NULL, '2026-08-03 15:20:28'),
(1698, 6, '2026-08-03 15:20:28', '7200.00', 3920, '1.4100', NULL, NULL, '2026-08-03 15:20:28'),
(1699, 7, '2026-08-03 15:20:28', '11350.00', 5608, '4.9500', NULL, NULL, '2026-08-03 15:20:28'),
(1700, 8, '2026-08-03 15:20:28', '5625.00', 3155, '-0.7100', NULL, NULL, '2026-08-03 15:20:28'),
(1701, 9, '2026-08-03 15:20:28', '5200.00', 1036, '-1.7000', NULL, NULL, '2026-08-03 15:20:28'),
(1702, 10, '2026-08-03 15:20:28', '7685.00', 3389, '-1.3500', NULL, NULL, '2026-08-03 15:20:28'),
(1703, 11, '2026-08-03 15:20:28', '3645.00', 6890, '4.1400', NULL, NULL, '2026-08-03 15:20:28'),
(1704, 12, '2026-08-03 15:20:28', '28490.00', 1113, '1.7500', NULL, NULL, '2026-08-03 15:20:28'),
(1705, 13, '2026-08-03 15:20:28', '1695.00', 2656, '1.8000', NULL, NULL, '2026-08-03 15:20:28'),
(1706, 14, '2026-08-03 15:20:28', '5000.00', 6428, '0.0000', NULL, NULL, '2026-08-03 15:20:28'),
(1707, 15, '2026-08-03 15:20:28', '16100.00', 1507, '-0.8000', NULL, NULL, '2026-08-03 15:20:28'),
(1708, 16, '2026-08-03 15:20:28', '69.00', 836211, '4.5500', NULL, NULL, '2026-08-03 15:20:28'),
(1709, 17, '2026-08-03 15:20:28', '1910.00', 3008, '-3.0500', NULL, NULL, '2026-08-03 15:20:28'),
(1710, 18, '2026-08-03 15:20:28', '4560.00', 9055, '1.3300', NULL, NULL, '2026-08-03 15:20:28'),
(1711, 19, '2026-08-03 15:20:28', '2280.00', 643, '0.0000', NULL, NULL, '2026-08-03 15:20:28'),
(1712, 20, '2026-08-03 15:20:28', '24020.00', 688, '0.0800', NULL, NULL, '2026-08-03 15:20:28'),
(1713, 21, '2026-08-03 15:20:28', '16550.00', 1778, '0.3000', NULL, NULL, '2026-08-03 15:20:28'),
(1714, 22, '2026-08-03 15:20:28', '2990.00', 2793, '-0.1700', NULL, NULL, '2026-08-03 15:20:28'),
(1715, 23, '2026-08-03 15:20:28', '17000.00', 1175, '0.0300', NULL, NULL, '2026-08-03 15:20:28'),
(1716, 24, '2026-08-03 15:20:28', '3130.00', 5561, '-0.3200', NULL, NULL, '2026-08-03 15:20:28'),
(1717, 25, '2026-08-03 15:20:28', '8990.00', 831, '-0.1100', NULL, NULL, '2026-08-03 15:20:28'),
(1718, 26, '2026-08-03 15:20:28', '4400.00', 458, '-2.5500', NULL, NULL, '2026-08-03 15:20:28'),
(1719, 27, '2026-08-03 15:20:28', '5000.00', 18854, '-2.5300', NULL, NULL, '2026-08-03 15:20:28'),
(1720, 28, '2026-08-03 15:20:28', '3700.00', 4780, '1.5100', NULL, NULL, '2026-08-03 15:20:28'),
(1721, 29, '2026-08-03 15:20:28', '11900.00', 669, '0.0000', NULL, NULL, '2026-08-03 15:20:28'),
(1722, 30, '2026-08-03 15:20:28', '2750.00', 5315, '2.2300', NULL, NULL, '2026-08-03 15:20:28'),
(1723, 31, '2026-08-03 15:20:28', '1480.00', 2293, '-0.3400', NULL, NULL, '2026-08-03 15:20:28'),
(1724, 32, '2026-08-03 15:20:28', '37995.00', 5427, '-0.0100', NULL, NULL, '2026-08-03 15:20:28'),
(1725, 33, '2026-08-03 15:20:28', '2325.00', 5795, '5.6800', NULL, NULL, '2026-08-03 15:20:28'),
(1726, 34, '2026-08-03 15:20:28', '8800.00', 4617, '-2.2200', NULL, NULL, '2026-08-03 15:20:28'),
(1727, 35, '2026-08-03 15:20:28', '6600.00', 19, '3.1200', NULL, NULL, '2026-08-03 15:20:28'),
(1728, 36, '2026-08-03 15:20:28', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 15:20:28'),
(1729, 37, '2026-08-03 15:20:28', '37700.00', 935, '-0.1300', NULL, NULL, '2026-08-03 15:20:28'),
(1730, 38, '2026-08-03 15:20:28', '15500.00', 1972, '0.0300', NULL, NULL, '2026-08-03 15:20:28'),
(1731, 39, '2026-08-03 15:20:28', '31000.00', 10204, '0.0000', NULL, NULL, '2026-08-03 15:20:28'),
(1732, 40, '2026-08-03 15:20:28', '8400.00', 2652, '1.1400', NULL, NULL, '2026-08-03 15:20:28'),
(1733, 41, '2026-08-03 15:20:28', '7500.00', 4030, '-0.8600', NULL, NULL, '2026-08-03 15:20:28'),
(1734, 42, '2026-08-03 15:20:28', '2820.00', 4652, '5.2200', NULL, NULL, '2026-08-03 15:20:28'),
(1735, 43, '2026-08-03 15:20:28', '23985.00', 2331, '3.3800', NULL, NULL, '2026-08-03 15:20:28'),
(1736, 44, '2026-08-03 15:20:28', '2990.00', 7265, '0.5000', NULL, NULL, '2026-08-03 15:20:28'),
(1737, 45, '2026-08-03 15:20:28', '3655.00', 2070, '0.0000', NULL, NULL, '2026-08-03 15:20:28'),
(1738, 46, '2026-08-03 15:20:28', '51020.00', 37, '0.0400', NULL, NULL, '2026-08-03 15:20:28'),
(1739, 47, '2026-08-03 15:20:28', '1900.00', 6968, '-2.3100', NULL, NULL, '2026-08-03 15:20:28'),
(1740, 1, '2026-08-03 15:31:56', '2990.00', 2842, '-0.3300', NULL, NULL, '2026-08-03 15:31:56'),
(1741, 2, '2026-08-03 15:31:56', '7690.00', 22443, '1.3800', NULL, NULL, '2026-08-03 15:31:56'),
(1742, 3, '2026-08-03 15:31:56', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 15:31:56'),
(1743, 4, '2026-08-03 15:31:56', '1930.00', 2336, '2.1200', NULL, NULL, '2026-08-03 15:31:56'),
(1744, 5, '2026-08-03 15:31:56', '8695.00', 8777, '-0.0600', NULL, NULL, '2026-08-03 15:31:56'),
(1745, 6, '2026-08-03 15:31:56', '7200.00', 3920, '1.4100', NULL, NULL, '2026-08-03 15:31:56'),
(1746, 7, '2026-08-03 15:31:56', '11350.00', 5608, '4.9500', NULL, NULL, '2026-08-03 15:31:56'),
(1747, 8, '2026-08-03 15:31:56', '5625.00', 3155, '-0.7100', NULL, NULL, '2026-08-03 15:31:56'),
(1748, 9, '2026-08-03 15:31:56', '5200.00', 1036, '-1.7000', NULL, NULL, '2026-08-03 15:31:56'),
(1749, 10, '2026-08-03 15:31:56', '7685.00', 3389, '-1.3500', NULL, NULL, '2026-08-03 15:31:56'),
(1750, 11, '2026-08-03 15:31:56', '3645.00', 6890, '4.1400', NULL, NULL, '2026-08-03 15:31:56'),
(1751, 12, '2026-08-03 15:31:56', '28490.00', 1113, '1.7500', NULL, NULL, '2026-08-03 15:31:56'),
(1752, 13, '2026-08-03 15:31:56', '1695.00', 2656, '1.8000', NULL, NULL, '2026-08-03 15:31:56'),
(1753, 14, '2026-08-03 15:31:56', '5000.00', 6428, '0.0000', NULL, NULL, '2026-08-03 15:31:56'),
(1754, 15, '2026-08-03 15:31:56', '16100.00', 1507, '-0.8000', NULL, NULL, '2026-08-03 15:31:56'),
(1755, 16, '2026-08-03 15:31:56', '69.00', 836211, '4.5500', NULL, NULL, '2026-08-03 15:31:56'),
(1756, 17, '2026-08-03 15:31:56', '1910.00', 3008, '-3.0500', NULL, NULL, '2026-08-03 15:31:56'),
(1757, 18, '2026-08-03 15:31:56', '4560.00', 9055, '1.3300', NULL, NULL, '2026-08-03 15:31:56'),
(1758, 19, '2026-08-03 15:31:56', '2280.00', 643, '0.0000', NULL, NULL, '2026-08-03 15:31:56'),
(1759, 20, '2026-08-03 15:31:56', '24020.00', 688, '0.0800', NULL, NULL, '2026-08-03 15:31:56'),
(1760, 21, '2026-08-03 15:31:56', '16550.00', 1778, '0.3000', NULL, NULL, '2026-08-03 15:31:56'),
(1761, 22, '2026-08-03 15:31:56', '2990.00', 2793, '-0.1700', NULL, NULL, '2026-08-03 15:31:56'),
(1762, 23, '2026-08-03 15:31:56', '17000.00', 1175, '0.0300', NULL, NULL, '2026-08-03 15:31:56'),
(1763, 24, '2026-08-03 15:31:56', '3130.00', 5561, '-0.3200', NULL, NULL, '2026-08-03 15:31:56'),
(1764, 25, '2026-08-03 15:31:56', '8990.00', 831, '-0.1100', NULL, NULL, '2026-08-03 15:31:56'),
(1765, 26, '2026-08-03 15:31:56', '4400.00', 458, '-2.5500', NULL, NULL, '2026-08-03 15:31:56'),
(1766, 27, '2026-08-03 15:31:56', '5000.00', 18854, '-2.5300', NULL, NULL, '2026-08-03 15:31:56'),
(1767, 28, '2026-08-03 15:31:56', '3700.00', 4780, '1.5100', NULL, NULL, '2026-08-03 15:31:56'),
(1768, 29, '2026-08-03 15:31:56', '11900.00', 669, '0.0000', NULL, NULL, '2026-08-03 15:31:56'),
(1769, 30, '2026-08-03 15:31:56', '2750.00', 5315, '2.2300', NULL, NULL, '2026-08-03 15:31:56'),
(1770, 31, '2026-08-03 15:31:56', '1480.00', 2293, '-0.3400', NULL, NULL, '2026-08-03 15:31:56'),
(1771, 32, '2026-08-03 15:31:56', '37995.00', 5427, '-0.0100', NULL, NULL, '2026-08-03 15:31:56'),
(1772, 33, '2026-08-03 15:31:56', '2325.00', 5795, '5.6800', NULL, NULL, '2026-08-03 15:31:56'),
(1773, 34, '2026-08-03 15:31:56', '8800.00', 4617, '-2.2200', NULL, NULL, '2026-08-03 15:31:56'),
(1774, 35, '2026-08-03 15:31:56', '6600.00', 19, '3.1200', NULL, NULL, '2026-08-03 15:31:56'),
(1775, 36, '2026-08-03 15:31:56', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 15:31:56'),
(1776, 37, '2026-08-03 15:31:56', '37700.00', 935, '-0.1300', NULL, NULL, '2026-08-03 15:31:56'),
(1777, 38, '2026-08-03 15:31:56', '15500.00', 1972, '0.0300', NULL, NULL, '2026-08-03 15:31:56'),
(1778, 39, '2026-08-03 15:31:56', '31000.00', 10204, '0.0000', NULL, NULL, '2026-08-03 15:31:56'),
(1779, 40, '2026-08-03 15:31:56', '8400.00', 2652, '1.1400', NULL, NULL, '2026-08-03 15:31:56'),
(1780, 41, '2026-08-03 15:31:56', '7500.00', 4030, '-0.8600', NULL, NULL, '2026-08-03 15:31:56'),
(1781, 42, '2026-08-03 15:31:56', '2820.00', 4652, '5.2200', NULL, NULL, '2026-08-03 15:31:56'),
(1782, 43, '2026-08-03 15:31:56', '23985.00', 2331, '3.3800', NULL, NULL, '2026-08-03 15:31:56'),
(1783, 44, '2026-08-03 15:31:56', '2990.00', 7265, '0.5000', NULL, NULL, '2026-08-03 15:31:56'),
(1784, 45, '2026-08-03 15:31:56', '3655.00', 2070, '0.0000', NULL, NULL, '2026-08-03 15:31:56'),
(1785, 46, '2026-08-03 15:31:56', '51020.00', 37, '0.0400', NULL, NULL, '2026-08-03 15:31:56'),
(1786, 47, '2026-08-03 15:31:56', '1900.00', 6968, '-2.3100', NULL, NULL, '2026-08-03 15:31:56'),
(1787, 1, '2026-08-03 15:40:25', '2990.00', 2842, '-0.3300', NULL, NULL, '2026-08-03 15:40:25'),
(1788, 2, '2026-08-03 15:40:25', '7690.00', 22443, '1.3800', NULL, NULL, '2026-08-03 15:40:25'),
(1789, 3, '2026-08-03 15:40:25', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 15:40:25'),
(1790, 4, '2026-08-03 15:40:25', '1930.00', 2336, '2.1200', NULL, NULL, '2026-08-03 15:40:25'),
(1791, 5, '2026-08-03 15:40:25', '8695.00', 8777, '-0.0600', NULL, NULL, '2026-08-03 15:40:25'),
(1792, 6, '2026-08-03 15:40:25', '7200.00', 3920, '1.4100', NULL, NULL, '2026-08-03 15:40:25'),
(1793, 7, '2026-08-03 15:40:25', '11350.00', 5608, '4.9500', NULL, NULL, '2026-08-03 15:40:25'),
(1794, 8, '2026-08-03 15:40:25', '5625.00', 3155, '-0.7100', NULL, NULL, '2026-08-03 15:40:25'),
(1795, 9, '2026-08-03 15:40:25', '5200.00', 1036, '-1.7000', NULL, NULL, '2026-08-03 15:40:25'),
(1796, 10, '2026-08-03 15:40:25', '7685.00', 3389, '-1.3500', NULL, NULL, '2026-08-03 15:40:25'),
(1797, 11, '2026-08-03 15:40:25', '3645.00', 6890, '4.1400', NULL, NULL, '2026-08-03 15:40:25'),
(1798, 12, '2026-08-03 15:40:25', '28490.00', 1113, '1.7500', NULL, NULL, '2026-08-03 15:40:25'),
(1799, 13, '2026-08-03 15:40:25', '1695.00', 2656, '1.8000', NULL, NULL, '2026-08-03 15:40:25'),
(1800, 14, '2026-08-03 15:40:25', '5000.00', 6428, '0.0000', NULL, NULL, '2026-08-03 15:40:25'),
(1801, 15, '2026-08-03 15:40:25', '16100.00', 1507, '-0.8000', NULL, NULL, '2026-08-03 15:40:25'),
(1802, 16, '2026-08-03 15:40:25', '69.00', 836211, '4.5500', NULL, NULL, '2026-08-03 15:40:25'),
(1803, 17, '2026-08-03 15:40:25', '1910.00', 3008, '-3.0500', NULL, NULL, '2026-08-03 15:40:25'),
(1804, 18, '2026-08-03 15:40:25', '4560.00', 9055, '1.3300', NULL, NULL, '2026-08-03 15:40:25'),
(1805, 19, '2026-08-03 15:40:25', '2280.00', 643, '0.0000', NULL, NULL, '2026-08-03 15:40:25'),
(1806, 20, '2026-08-03 15:40:25', '24020.00', 688, '0.0800', NULL, NULL, '2026-08-03 15:40:25'),
(1807, 21, '2026-08-03 15:40:25', '16550.00', 1778, '0.3000', NULL, NULL, '2026-08-03 15:40:25'),
(1808, 22, '2026-08-03 15:40:25', '2990.00', 2793, '-0.1700', NULL, NULL, '2026-08-03 15:40:25'),
(1809, 23, '2026-08-03 15:40:26', '17000.00', 1175, '0.0300', NULL, NULL, '2026-08-03 15:40:26'),
(1810, 24, '2026-08-03 15:40:26', '3130.00', 5561, '-0.3200', NULL, NULL, '2026-08-03 15:40:26'),
(1811, 25, '2026-08-03 15:40:26', '8990.00', 831, '-0.1100', NULL, NULL, '2026-08-03 15:40:26'),
(1812, 26, '2026-08-03 15:40:26', '4400.00', 458, '-2.5500', NULL, NULL, '2026-08-03 15:40:26'),
(1813, 27, '2026-08-03 15:40:26', '5000.00', 18854, '-2.5300', NULL, NULL, '2026-08-03 15:40:26'),
(1814, 28, '2026-08-03 15:40:26', '3700.00', 4780, '1.5100', NULL, NULL, '2026-08-03 15:40:26'),
(1815, 29, '2026-08-03 15:40:26', '11900.00', 669, '0.0000', NULL, NULL, '2026-08-03 15:40:26'),
(1816, 30, '2026-08-03 15:40:26', '2750.00', 5315, '2.2300', NULL, NULL, '2026-08-03 15:40:26'),
(1817, 31, '2026-08-03 15:40:26', '1480.00', 2293, '-0.3400', NULL, NULL, '2026-08-03 15:40:26'),
(1818, 32, '2026-08-03 15:40:26', '37995.00', 5427, '-0.0100', NULL, NULL, '2026-08-03 15:40:26'),
(1819, 33, '2026-08-03 15:40:26', '2325.00', 5795, '5.6800', NULL, NULL, '2026-08-03 15:40:26'),
(1820, 34, '2026-08-03 15:40:26', '8800.00', 4617, '-2.2200', NULL, NULL, '2026-08-03 15:40:26'),
(1821, 35, '2026-08-03 15:40:26', '6600.00', 19, '3.1200', NULL, NULL, '2026-08-03 15:40:26'),
(1822, 36, '2026-08-03 15:40:26', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 15:40:26'),
(1823, 37, '2026-08-03 15:40:26', '37700.00', 935, '-0.1300', NULL, NULL, '2026-08-03 15:40:26'),
(1824, 38, '2026-08-03 15:40:26', '15500.00', 1972, '0.0300', NULL, NULL, '2026-08-03 15:40:26'),
(1825, 39, '2026-08-03 15:40:26', '31000.00', 10204, '0.0000', NULL, NULL, '2026-08-03 15:40:26'),
(1826, 40, '2026-08-03 15:40:26', '8400.00', 2652, '1.1400', NULL, NULL, '2026-08-03 15:40:26'),
(1827, 41, '2026-08-03 15:40:26', '7500.00', 4030, '-0.8600', NULL, NULL, '2026-08-03 15:40:26'),
(1828, 42, '2026-08-03 15:40:26', '2820.00', 4652, '5.2200', NULL, NULL, '2026-08-03 15:40:26'),
(1829, 43, '2026-08-03 15:40:26', '23985.00', 2331, '3.3800', NULL, NULL, '2026-08-03 15:40:26'),
(1830, 44, '2026-08-03 15:40:26', '2990.00', 7265, '0.5000', NULL, NULL, '2026-08-03 15:40:26'),
(1831, 45, '2026-08-03 15:40:26', '3655.00', 2070, '0.0000', NULL, NULL, '2026-08-03 15:40:26'),
(1832, 46, '2026-08-03 15:40:26', '51020.00', 37, '0.0400', NULL, NULL, '2026-08-03 15:40:26'),
(1833, 47, '2026-08-03 15:40:26', '1900.00', 6968, '-2.3100', NULL, NULL, '2026-08-03 15:40:26'),
(1834, 1, '2026-08-03 15:50:27', '2990.00', 2842, '-0.3300', NULL, NULL, '2026-08-03 15:50:27'),
(1835, 2, '2026-08-03 15:50:27', '7690.00', 22443, '1.3800', NULL, NULL, '2026-08-03 15:50:27'),
(1836, 3, '2026-08-03 15:50:27', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 15:50:27'),
(1837, 4, '2026-08-03 15:50:27', '1930.00', 2336, '2.1200', NULL, NULL, '2026-08-03 15:50:27'),
(1838, 5, '2026-08-03 15:50:27', '8695.00', 8777, '-0.0600', NULL, NULL, '2026-08-03 15:50:27'),
(1839, 6, '2026-08-03 15:50:27', '7200.00', 3920, '1.4100', NULL, NULL, '2026-08-03 15:50:27'),
(1840, 7, '2026-08-03 15:50:27', '11350.00', 5608, '4.9500', NULL, NULL, '2026-08-03 15:50:27'),
(1841, 8, '2026-08-03 15:50:27', '5625.00', 3155, '-0.7100', NULL, NULL, '2026-08-03 15:50:27'),
(1842, 9, '2026-08-03 15:50:27', '5200.00', 1036, '-1.7000', NULL, NULL, '2026-08-03 15:50:27'),
(1843, 10, '2026-08-03 15:50:27', '7685.00', 3389, '-1.3500', NULL, NULL, '2026-08-03 15:50:27'),
(1844, 11, '2026-08-03 15:50:27', '3645.00', 6890, '4.1400', NULL, NULL, '2026-08-03 15:50:27'),
(1845, 12, '2026-08-03 15:50:27', '28490.00', 1113, '1.7500', NULL, NULL, '2026-08-03 15:50:27'),
(1846, 13, '2026-08-03 15:50:27', '1695.00', 2656, '1.8000', NULL, NULL, '2026-08-03 15:50:27'),
(1847, 14, '2026-08-03 15:50:27', '5000.00', 6428, '0.0000', NULL, NULL, '2026-08-03 15:50:27'),
(1848, 15, '2026-08-03 15:50:27', '16100.00', 1507, '-0.8000', NULL, NULL, '2026-08-03 15:50:27'),
(1849, 16, '2026-08-03 15:50:27', '69.00', 836211, '4.5500', NULL, NULL, '2026-08-03 15:50:27'),
(1850, 17, '2026-08-03 15:50:27', '1910.00', 3008, '-3.0500', NULL, NULL, '2026-08-03 15:50:27'),
(1851, 18, '2026-08-03 15:50:27', '4560.00', 9055, '1.3300', NULL, NULL, '2026-08-03 15:50:27'),
(1852, 19, '2026-08-03 15:50:27', '2280.00', 643, '0.0000', NULL, NULL, '2026-08-03 15:50:27'),
(1853, 20, '2026-08-03 15:50:27', '24020.00', 688, '0.0800', NULL, NULL, '2026-08-03 15:50:27'),
(1854, 21, '2026-08-03 15:50:27', '16550.00', 1778, '0.3000', NULL, NULL, '2026-08-03 15:50:27'),
(1855, 22, '2026-08-03 15:50:27', '2990.00', 2793, '-0.1700', NULL, NULL, '2026-08-03 15:50:27'),
(1856, 23, '2026-08-03 15:50:27', '17000.00', 1175, '0.0300', NULL, NULL, '2026-08-03 15:50:27'),
(1857, 24, '2026-08-03 15:50:27', '3130.00', 5561, '-0.3200', NULL, NULL, '2026-08-03 15:50:27'),
(1858, 25, '2026-08-03 15:50:27', '8990.00', 831, '-0.1100', NULL, NULL, '2026-08-03 15:50:27'),
(1859, 26, '2026-08-03 15:50:27', '4400.00', 458, '-2.5500', NULL, NULL, '2026-08-03 15:50:27'),
(1860, 27, '2026-08-03 15:50:27', '5000.00', 18854, '-2.5300', NULL, NULL, '2026-08-03 15:50:27'),
(1861, 28, '2026-08-03 15:50:27', '3700.00', 4780, '1.5100', NULL, NULL, '2026-08-03 15:50:27'),
(1862, 29, '2026-08-03 15:50:27', '11900.00', 669, '0.0000', NULL, NULL, '2026-08-03 15:50:27'),
(1863, 30, '2026-08-03 15:50:27', '2750.00', 5315, '2.2300', NULL, NULL, '2026-08-03 15:50:27'),
(1864, 31, '2026-08-03 15:50:27', '1480.00', 2293, '-0.3400', NULL, NULL, '2026-08-03 15:50:27'),
(1865, 32, '2026-08-03 15:50:27', '37995.00', 5427, '-0.0100', NULL, NULL, '2026-08-03 15:50:27'),
(1866, 33, '2026-08-03 15:50:27', '2325.00', 5795, '5.6800', NULL, NULL, '2026-08-03 15:50:27'),
(1867, 34, '2026-08-03 15:50:27', '8800.00', 4617, '-2.2200', NULL, NULL, '2026-08-03 15:50:27'),
(1868, 35, '2026-08-03 15:50:27', '6600.00', 19, '3.1200', NULL, NULL, '2026-08-03 15:50:27'),
(1869, 36, '2026-08-03 15:50:27', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 15:50:27'),
(1870, 37, '2026-08-03 15:50:27', '37700.00', 935, '-0.1300', NULL, NULL, '2026-08-03 15:50:27'),
(1871, 38, '2026-08-03 15:50:27', '15500.00', 1972, '0.0300', NULL, NULL, '2026-08-03 15:50:27'),
(1872, 39, '2026-08-03 15:50:27', '31000.00', 10204, '0.0000', NULL, NULL, '2026-08-03 15:50:27'),
(1873, 40, '2026-08-03 15:50:27', '8400.00', 2652, '1.1400', NULL, NULL, '2026-08-03 15:50:27'),
(1874, 41, '2026-08-03 15:50:27', '7500.00', 4030, '-0.8600', NULL, NULL, '2026-08-03 15:50:27'),
(1875, 42, '2026-08-03 15:50:27', '2820.00', 4652, '5.2200', NULL, NULL, '2026-08-03 15:50:27'),
(1876, 43, '2026-08-03 15:50:27', '23985.00', 2331, '3.3800', NULL, NULL, '2026-08-03 15:50:27'),
(1877, 44, '2026-08-03 15:50:27', '2990.00', 7265, '0.5000', NULL, NULL, '2026-08-03 15:50:27'),
(1878, 45, '2026-08-03 15:50:27', '3655.00', 2070, '0.0000', NULL, NULL, '2026-08-03 15:50:27'),
(1879, 46, '2026-08-03 15:50:27', '51020.00', 37, '0.0400', NULL, NULL, '2026-08-03 15:50:27'),
(1880, 47, '2026-08-03 15:50:27', '1900.00', 6968, '-2.3100', NULL, NULL, '2026-08-03 15:50:27'),
(1881, 1, '2026-08-03 16:00:18', '2990.00', 2842, '-0.3300', NULL, NULL, '2026-08-03 16:00:18'),
(1882, 2, '2026-08-03 16:00:18', '7690.00', 22443, '1.3800', NULL, NULL, '2026-08-03 16:00:18'),
(1883, 3, '2026-08-03 16:00:18', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 16:00:18'),
(1884, 4, '2026-08-03 16:00:18', '1930.00', 2336, '2.1200', NULL, NULL, '2026-08-03 16:00:18'),
(1885, 5, '2026-08-03 16:00:18', '8695.00', 8777, '-0.0600', NULL, NULL, '2026-08-03 16:00:18'),
(1886, 6, '2026-08-03 16:00:18', '7200.00', 3920, '1.4100', NULL, NULL, '2026-08-03 16:00:18'),
(1887, 7, '2026-08-03 16:00:18', '11350.00', 5608, '4.9500', NULL, NULL, '2026-08-03 16:00:18'),
(1888, 8, '2026-08-03 16:00:18', '5625.00', 3155, '-0.7100', NULL, NULL, '2026-08-03 16:00:18'),
(1889, 9, '2026-08-03 16:00:18', '5200.00', 1036, '-1.7000', NULL, NULL, '2026-08-03 16:00:18'),
(1890, 10, '2026-08-03 16:00:18', '7685.00', 3389, '-1.3500', NULL, NULL, '2026-08-03 16:00:18'),
(1891, 11, '2026-08-03 16:00:18', '3645.00', 6890, '4.1400', NULL, NULL, '2026-08-03 16:00:18'),
(1892, 12, '2026-08-03 16:00:18', '28490.00', 1113, '1.7500', NULL, NULL, '2026-08-03 16:00:18'),
(1893, 13, '2026-08-03 16:00:18', '1695.00', 2656, '1.8000', NULL, NULL, '2026-08-03 16:00:18'),
(1894, 14, '2026-08-03 16:00:18', '5000.00', 6428, '0.0000', NULL, NULL, '2026-08-03 16:00:18'),
(1895, 15, '2026-08-03 16:00:19', '16100.00', 1507, '-0.8000', NULL, NULL, '2026-08-03 16:00:19'),
(1896, 16, '2026-08-03 16:00:19', '69.00', 836211, '4.5500', NULL, NULL, '2026-08-03 16:00:19'),
(1897, 17, '2026-08-03 16:00:19', '1910.00', 3008, '-3.0500', NULL, NULL, '2026-08-03 16:00:19'),
(1898, 18, '2026-08-03 16:00:19', '4560.00', 9055, '1.3300', NULL, NULL, '2026-08-03 16:00:19'),
(1899, 19, '2026-08-03 16:00:19', '2280.00', 643, '0.0000', NULL, NULL, '2026-08-03 16:00:19'),
(1900, 20, '2026-08-03 16:00:19', '24020.00', 688, '0.0800', NULL, NULL, '2026-08-03 16:00:19'),
(1901, 21, '2026-08-03 16:00:19', '16550.00', 1778, '0.3000', NULL, NULL, '2026-08-03 16:00:19'),
(1902, 22, '2026-08-03 16:00:19', '2990.00', 2793, '-0.1700', NULL, NULL, '2026-08-03 16:00:19'),
(1903, 23, '2026-08-03 16:00:19', '17000.00', 1175, '0.0300', NULL, NULL, '2026-08-03 16:00:19'),
(1904, 24, '2026-08-03 16:00:19', '3130.00', 5561, '-0.3200', NULL, NULL, '2026-08-03 16:00:19'),
(1905, 25, '2026-08-03 16:00:19', '8990.00', 831, '-0.1100', NULL, NULL, '2026-08-03 16:00:19'),
(1906, 26, '2026-08-03 16:00:19', '4400.00', 458, '-2.5500', NULL, NULL, '2026-08-03 16:00:19'),
(1907, 27, '2026-08-03 16:00:19', '5000.00', 18854, '-2.5300', NULL, NULL, '2026-08-03 16:00:19'),
(1908, 28, '2026-08-03 16:00:19', '3700.00', 4780, '1.5100', NULL, NULL, '2026-08-03 16:00:19'),
(1909, 29, '2026-08-03 16:00:19', '11900.00', 669, '0.0000', NULL, NULL, '2026-08-03 16:00:19'),
(1910, 30, '2026-08-03 16:00:19', '2750.00', 5315, '2.2300', NULL, NULL, '2026-08-03 16:00:19'),
(1911, 31, '2026-08-03 16:00:19', '1480.00', 2293, '-0.3400', NULL, NULL, '2026-08-03 16:00:19'),
(1912, 32, '2026-08-03 16:00:19', '37995.00', 5427, '-0.0100', NULL, NULL, '2026-08-03 16:00:19'),
(1913, 33, '2026-08-03 16:00:19', '2325.00', 5795, '5.6800', NULL, NULL, '2026-08-03 16:00:19'),
(1914, 34, '2026-08-03 16:00:19', '8800.00', 4617, '-2.2200', NULL, NULL, '2026-08-03 16:00:19'),
(1915, 35, '2026-08-03 16:00:19', '6600.00', 19, '3.1200', NULL, NULL, '2026-08-03 16:00:19'),
(1916, 36, '2026-08-03 16:00:19', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 16:00:19'),
(1917, 37, '2026-08-03 16:00:19', '37700.00', 935, '-0.1300', NULL, NULL, '2026-08-03 16:00:19'),
(1918, 38, '2026-08-03 16:00:19', '15500.00', 1972, '0.0300', NULL, NULL, '2026-08-03 16:00:19'),
(1919, 39, '2026-08-03 16:00:19', '31000.00', 10204, '0.0000', NULL, NULL, '2026-08-03 16:00:19'),
(1920, 40, '2026-08-03 16:00:19', '8400.00', 2652, '1.1400', NULL, NULL, '2026-08-03 16:00:19'),
(1921, 41, '2026-08-03 16:00:19', '7500.00', 4030, '-0.8600', NULL, NULL, '2026-08-03 16:00:19'),
(1922, 42, '2026-08-03 16:00:19', '2820.00', 4652, '5.2200', NULL, NULL, '2026-08-03 16:00:19'),
(1923, 43, '2026-08-03 16:00:19', '23985.00', 2331, '3.3800', NULL, NULL, '2026-08-03 16:00:19'),
(1924, 44, '2026-08-03 16:00:19', '2990.00', 7265, '0.5000', NULL, NULL, '2026-08-03 16:00:19'),
(1925, 45, '2026-08-03 16:00:19', '3655.00', 2070, '0.0000', NULL, NULL, '2026-08-03 16:00:19'),
(1926, 46, '2026-08-03 16:00:19', '51020.00', 37, '0.0400', NULL, NULL, '2026-08-03 16:00:19'),
(1927, 47, '2026-08-03 16:00:19', '1900.00', 6968, '-2.3100', NULL, NULL, '2026-08-03 16:00:19'),
(1928, 1, '2026-08-03 16:10:08', '2990.00', 2842, '-0.3300', NULL, NULL, '2026-08-03 16:10:08'),
(1929, 2, '2026-08-03 16:10:08', '7690.00', 22443, '1.3800', NULL, NULL, '2026-08-03 16:10:08'),
(1930, 3, '2026-08-03 16:10:08', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 16:10:08'),
(1931, 4, '2026-08-03 16:10:08', '1930.00', 2336, '2.1200', NULL, NULL, '2026-08-03 16:10:08'),
(1932, 5, '2026-08-03 16:10:08', '8695.00', 8777, '-0.0600', NULL, NULL, '2026-08-03 16:10:08'),
(1933, 6, '2026-08-03 16:10:08', '7200.00', 3920, '1.4100', NULL, NULL, '2026-08-03 16:10:08'),
(1934, 7, '2026-08-03 16:10:08', '11350.00', 5608, '4.9500', NULL, NULL, '2026-08-03 16:10:08'),
(1935, 8, '2026-08-03 16:10:08', '5625.00', 3155, '-0.7100', NULL, NULL, '2026-08-03 16:10:08'),
(1936, 9, '2026-08-03 16:10:08', '5200.00', 1036, '-1.7000', NULL, NULL, '2026-08-03 16:10:08'),
(1937, 10, '2026-08-03 16:10:08', '7685.00', 3389, '-1.3500', NULL, NULL, '2026-08-03 16:10:08'),
(1938, 11, '2026-08-03 16:10:08', '3645.00', 6890, '4.1400', NULL, NULL, '2026-08-03 16:10:08'),
(1939, 12, '2026-08-03 16:10:08', '28490.00', 1113, '1.7500', NULL, NULL, '2026-08-03 16:10:08'),
(1940, 13, '2026-08-03 16:10:08', '1695.00', 2656, '1.8000', NULL, NULL, '2026-08-03 16:10:08'),
(1941, 14, '2026-08-03 16:10:08', '5000.00', 6428, '0.0000', NULL, NULL, '2026-08-03 16:10:08'),
(1942, 15, '2026-08-03 16:10:08', '16100.00', 1507, '-0.8000', NULL, NULL, '2026-08-03 16:10:08'),
(1943, 16, '2026-08-03 16:10:08', '69.00', 836211, '4.5500', NULL, NULL, '2026-08-03 16:10:08'),
(1944, 17, '2026-08-03 16:10:08', '1910.00', 3008, '-3.0500', NULL, NULL, '2026-08-03 16:10:08'),
(1945, 18, '2026-08-03 16:10:08', '4560.00', 9055, '1.3300', NULL, NULL, '2026-08-03 16:10:08'),
(1946, 19, '2026-08-03 16:10:08', '2280.00', 643, '0.0000', NULL, NULL, '2026-08-03 16:10:08'),
(1947, 20, '2026-08-03 16:10:08', '24020.00', 688, '0.0800', NULL, NULL, '2026-08-03 16:10:08'),
(1948, 21, '2026-08-03 16:10:08', '16550.00', 1778, '0.3000', NULL, NULL, '2026-08-03 16:10:08'),
(1949, 22, '2026-08-03 16:10:08', '2990.00', 2793, '-0.1700', NULL, NULL, '2026-08-03 16:10:08'),
(1950, 23, '2026-08-03 16:10:08', '17000.00', 1175, '0.0300', NULL, NULL, '2026-08-03 16:10:08'),
(1951, 24, '2026-08-03 16:10:08', '3130.00', 5561, '-0.3200', NULL, NULL, '2026-08-03 16:10:08'),
(1952, 25, '2026-08-03 16:10:08', '8990.00', 831, '-0.1100', NULL, NULL, '2026-08-03 16:10:08'),
(1953, 26, '2026-08-03 16:10:08', '4400.00', 458, '-2.5500', NULL, NULL, '2026-08-03 16:10:08'),
(1954, 27, '2026-08-03 16:10:08', '5000.00', 18854, '-2.5300', NULL, NULL, '2026-08-03 16:10:08'),
(1955, 28, '2026-08-03 16:10:08', '3700.00', 4780, '1.5100', NULL, NULL, '2026-08-03 16:10:08'),
(1956, 29, '2026-08-03 16:10:08', '11900.00', 669, '0.0000', NULL, NULL, '2026-08-03 16:10:08'),
(1957, 30, '2026-08-03 16:10:09', '2750.00', 5315, '2.2300', NULL, NULL, '2026-08-03 16:10:09'),
(1958, 31, '2026-08-03 16:10:09', '1480.00', 2293, '-0.3400', NULL, NULL, '2026-08-03 16:10:09'),
(1959, 32, '2026-08-03 16:10:09', '37995.00', 5427, '-0.0100', NULL, NULL, '2026-08-03 16:10:09'),
(1960, 33, '2026-08-03 16:10:09', '2325.00', 5795, '5.6800', NULL, NULL, '2026-08-03 16:10:09'),
(1961, 34, '2026-08-03 16:10:09', '8800.00', 4617, '-2.2200', NULL, NULL, '2026-08-03 16:10:09'),
(1962, 35, '2026-08-03 16:10:09', '6600.00', 19, '3.1200', NULL, NULL, '2026-08-03 16:10:09'),
(1963, 36, '2026-08-03 16:10:09', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 16:10:09'),
(1964, 37, '2026-08-03 16:10:09', '37700.00', 935, '-0.1300', NULL, NULL, '2026-08-03 16:10:09'),
(1965, 38, '2026-08-03 16:10:09', '15500.00', 1972, '0.0300', NULL, NULL, '2026-08-03 16:10:09'),
(1966, 39, '2026-08-03 16:10:09', '31000.00', 10204, '0.0000', NULL, NULL, '2026-08-03 16:10:09'),
(1967, 40, '2026-08-03 16:10:09', '8400.00', 2652, '1.1400', NULL, NULL, '2026-08-03 16:10:09'),
(1968, 41, '2026-08-03 16:10:09', '7500.00', 4030, '-0.8600', NULL, NULL, '2026-08-03 16:10:09'),
(1969, 42, '2026-08-03 16:10:09', '2820.00', 4652, '5.2200', NULL, NULL, '2026-08-03 16:10:09'),
(1970, 43, '2026-08-03 16:10:09', '23985.00', 2331, '3.3800', NULL, NULL, '2026-08-03 16:10:09'),
(1971, 44, '2026-08-03 16:10:09', '2990.00', 7265, '0.5000', NULL, NULL, '2026-08-03 16:10:09'),
(1972, 45, '2026-08-03 16:10:09', '3655.00', 2070, '0.0000', NULL, NULL, '2026-08-03 16:10:09'),
(1973, 46, '2026-08-03 16:10:09', '51020.00', 37, '0.0400', NULL, NULL, '2026-08-03 16:10:09'),
(1974, 47, '2026-08-03 16:10:09', '1900.00', 6968, '-2.3100', NULL, NULL, '2026-08-03 16:10:09'),
(1975, 1, '2026-08-03 16:20:07', '2990.00', 2842, '-0.3300', NULL, NULL, '2026-08-03 16:20:07'),
(1976, 2, '2026-08-03 16:20:07', '7690.00', 22443, '1.3800', NULL, NULL, '2026-08-03 16:20:07'),
(1977, 3, '2026-08-03 16:20:07', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 16:20:07'),
(1978, 4, '2026-08-03 16:20:07', '1930.00', 2336, '2.1200', NULL, NULL, '2026-08-03 16:20:07'),
(1979, 5, '2026-08-03 16:20:07', '8695.00', 8777, '-0.0600', NULL, NULL, '2026-08-03 16:20:07'),
(1980, 6, '2026-08-03 16:20:07', '7200.00', 3920, '1.4100', NULL, NULL, '2026-08-03 16:20:07'),
(1981, 7, '2026-08-03 16:20:07', '11350.00', 5608, '4.9500', NULL, NULL, '2026-08-03 16:20:07'),
(1982, 8, '2026-08-03 16:20:07', '5625.00', 3155, '-0.7100', NULL, NULL, '2026-08-03 16:20:07'),
(1983, 9, '2026-08-03 16:20:07', '5200.00', 1036, '-1.7000', NULL, NULL, '2026-08-03 16:20:07'),
(1984, 10, '2026-08-03 16:20:07', '7685.00', 3389, '-1.3500', NULL, NULL, '2026-08-03 16:20:07'),
(1985, 11, '2026-08-03 16:20:07', '3645.00', 6890, '4.1400', NULL, NULL, '2026-08-03 16:20:07'),
(1986, 12, '2026-08-03 16:20:07', '28490.00', 1113, '1.7500', NULL, NULL, '2026-08-03 16:20:07'),
(1987, 13, '2026-08-03 16:20:07', '1695.00', 2656, '1.8000', NULL, NULL, '2026-08-03 16:20:07'),
(1988, 14, '2026-08-03 16:20:07', '5000.00', 6428, '0.0000', NULL, NULL, '2026-08-03 16:20:07'),
(1989, 15, '2026-08-03 16:20:07', '16100.00', 1507, '-0.8000', NULL, NULL, '2026-08-03 16:20:07'),
(1990, 16, '2026-08-03 16:20:07', '69.00', 836211, '4.5500', NULL, NULL, '2026-08-03 16:20:07'),
(1991, 17, '2026-08-03 16:20:07', '1910.00', 3008, '-3.0500', NULL, NULL, '2026-08-03 16:20:07'),
(1992, 18, '2026-08-03 16:20:07', '4560.00', 9055, '1.3300', NULL, NULL, '2026-08-03 16:20:07'),
(1993, 19, '2026-08-03 16:20:07', '2280.00', 643, '0.0000', NULL, NULL, '2026-08-03 16:20:07'),
(1994, 20, '2026-08-03 16:20:07', '24020.00', 688, '0.0800', NULL, NULL, '2026-08-03 16:20:07'),
(1995, 21, '2026-08-03 16:20:07', '16550.00', 1778, '0.3000', NULL, NULL, '2026-08-03 16:20:07'),
(1996, 22, '2026-08-03 16:20:07', '2990.00', 2793, '-0.1700', NULL, NULL, '2026-08-03 16:20:07'),
(1997, 23, '2026-08-03 16:20:07', '17000.00', 1175, '0.0300', NULL, NULL, '2026-08-03 16:20:07'),
(1998, 24, '2026-08-03 16:20:07', '3130.00', 5561, '-0.3200', NULL, NULL, '2026-08-03 16:20:07'),
(1999, 25, '2026-08-03 16:20:07', '8990.00', 831, '-0.1100', NULL, NULL, '2026-08-03 16:20:07'),
(2000, 26, '2026-08-03 16:20:07', '4400.00', 458, '-2.5500', NULL, NULL, '2026-08-03 16:20:07'),
(2001, 27, '2026-08-03 16:20:07', '5000.00', 18854, '-2.5300', NULL, NULL, '2026-08-03 16:20:07'),
(2002, 28, '2026-08-03 16:20:07', '3700.00', 4780, '1.5100', NULL, NULL, '2026-08-03 16:20:07'),
(2003, 29, '2026-08-03 16:20:07', '11900.00', 669, '0.0000', NULL, NULL, '2026-08-03 16:20:07'),
(2004, 30, '2026-08-03 16:20:07', '2750.00', 5315, '2.2300', NULL, NULL, '2026-08-03 16:20:07'),
(2005, 31, '2026-08-03 16:20:07', '1480.00', 2293, '-0.3400', NULL, NULL, '2026-08-03 16:20:07'),
(2006, 32, '2026-08-03 16:20:07', '37995.00', 5427, '-0.0100', NULL, NULL, '2026-08-03 16:20:07'),
(2007, 33, '2026-08-03 16:20:07', '2325.00', 5795, '5.6800', NULL, NULL, '2026-08-03 16:20:07'),
(2008, 34, '2026-08-03 16:20:07', '8800.00', 4617, '-2.2200', NULL, NULL, '2026-08-03 16:20:07'),
(2009, 35, '2026-08-03 16:20:07', '6600.00', 19, '3.1200', NULL, NULL, '2026-08-03 16:20:07'),
(2010, 36, '2026-08-03 16:20:07', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 16:20:07'),
(2011, 37, '2026-08-03 16:20:07', '37700.00', 935, '-0.1300', NULL, NULL, '2026-08-03 16:20:07'),
(2012, 38, '2026-08-03 16:20:07', '15500.00', 1972, '0.0300', NULL, NULL, '2026-08-03 16:20:07'),
(2013, 39, '2026-08-03 16:20:07', '31000.00', 10204, '0.0000', NULL, NULL, '2026-08-03 16:20:07'),
(2014, 40, '2026-08-03 16:20:07', '8400.00', 2652, '1.1400', NULL, NULL, '2026-08-03 16:20:07'),
(2015, 41, '2026-08-03 16:20:07', '7500.00', 4030, '-0.8600', NULL, NULL, '2026-08-03 16:20:07'),
(2016, 42, '2026-08-03 16:20:07', '2820.00', 4652, '5.2200', NULL, NULL, '2026-08-03 16:20:07'),
(2017, 43, '2026-08-03 16:20:07', '23985.00', 2331, '3.3800', NULL, NULL, '2026-08-03 16:20:07'),
(2018, 44, '2026-08-03 16:20:07', '2990.00', 7265, '0.5000', NULL, NULL, '2026-08-03 16:20:07'),
(2019, 45, '2026-08-03 16:20:07', '3655.00', 2070, '0.0000', NULL, NULL, '2026-08-03 16:20:07'),
(2020, 46, '2026-08-03 16:20:07', '51020.00', 37, '0.0400', NULL, NULL, '2026-08-03 16:20:07'),
(2021, 47, '2026-08-03 16:20:07', '1900.00', 6968, '-2.3100', NULL, NULL, '2026-08-03 16:20:07'),
(2022, 1, '2026-08-03 16:30:13', '2990.00', 2842, '-0.3300', NULL, NULL, '2026-08-03 16:30:13'),
(2023, 2, '2026-08-03 16:30:13', '7690.00', 22443, '1.3800', NULL, NULL, '2026-08-03 16:30:13'),
(2024, 3, '2026-08-03 16:30:13', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 16:30:13'),
(2025, 4, '2026-08-03 16:30:13', '1930.00', 2336, '2.1200', NULL, NULL, '2026-08-03 16:30:13'),
(2026, 5, '2026-08-03 16:30:13', '8695.00', 8777, '-0.0600', NULL, NULL, '2026-08-03 16:30:13'),
(2027, 6, '2026-08-03 16:30:13', '7200.00', 3920, '1.4100', NULL, NULL, '2026-08-03 16:30:13'),
(2028, 7, '2026-08-03 16:30:13', '11350.00', 5608, '4.9500', NULL, NULL, '2026-08-03 16:30:13'),
(2029, 8, '2026-08-03 16:30:13', '5625.00', 3155, '-0.7100', NULL, NULL, '2026-08-03 16:30:13'),
(2030, 9, '2026-08-03 16:30:13', '5200.00', 1036, '-1.7000', NULL, NULL, '2026-08-03 16:30:13'),
(2031, 10, '2026-08-03 16:30:13', '7685.00', 3389, '-1.3500', NULL, NULL, '2026-08-03 16:30:13'),
(2032, 11, '2026-08-03 16:30:13', '3645.00', 6890, '4.1400', NULL, NULL, '2026-08-03 16:30:13'),
(2033, 12, '2026-08-03 16:30:13', '28490.00', 1113, '1.7500', NULL, NULL, '2026-08-03 16:30:13'),
(2034, 13, '2026-08-03 16:30:13', '1695.00', 2656, '1.8000', NULL, NULL, '2026-08-03 16:30:13'),
(2035, 14, '2026-08-03 16:30:13', '5000.00', 6428, '0.0000', NULL, NULL, '2026-08-03 16:30:13'),
(2036, 15, '2026-08-03 16:30:13', '16100.00', 1507, '-0.8000', NULL, NULL, '2026-08-03 16:30:13'),
(2037, 16, '2026-08-03 16:30:14', '69.00', 836211, '4.5500', NULL, NULL, '2026-08-03 16:30:14'),
(2038, 17, '2026-08-03 16:30:14', '1910.00', 3008, '-3.0500', NULL, NULL, '2026-08-03 16:30:14'),
(2039, 18, '2026-08-03 16:30:14', '4560.00', 9055, '1.3300', NULL, NULL, '2026-08-03 16:30:14'),
(2040, 19, '2026-08-03 16:30:14', '2280.00', 643, '0.0000', NULL, NULL, '2026-08-03 16:30:14'),
(2041, 20, '2026-08-03 16:30:14', '24020.00', 688, '0.0800', NULL, NULL, '2026-08-03 16:30:14'),
(2042, 21, '2026-08-03 16:30:14', '16550.00', 1778, '0.3000', NULL, NULL, '2026-08-03 16:30:14'),
(2043, 22, '2026-08-03 16:30:14', '2990.00', 2793, '-0.1700', NULL, NULL, '2026-08-03 16:30:14'),
(2044, 23, '2026-08-03 16:30:14', '17000.00', 1175, '0.0300', NULL, NULL, '2026-08-03 16:30:14'),
(2045, 24, '2026-08-03 16:30:14', '3130.00', 5561, '-0.3200', NULL, NULL, '2026-08-03 16:30:14'),
(2046, 25, '2026-08-03 16:30:14', '8990.00', 831, '-0.1100', NULL, NULL, '2026-08-03 16:30:14'),
(2047, 26, '2026-08-03 16:30:14', '4400.00', 458, '-2.5500', NULL, NULL, '2026-08-03 16:30:14'),
(2048, 27, '2026-08-03 16:30:14', '5000.00', 18854, '-2.5300', NULL, NULL, '2026-08-03 16:30:14'),
(2049, 28, '2026-08-03 16:30:14', '3700.00', 4780, '1.5100', NULL, NULL, '2026-08-03 16:30:14'),
(2050, 29, '2026-08-03 16:30:14', '11900.00', 669, '0.0000', NULL, NULL, '2026-08-03 16:30:14'),
(2051, 30, '2026-08-03 16:30:14', '2750.00', 5315, '2.2300', NULL, NULL, '2026-08-03 16:30:14'),
(2052, 31, '2026-08-03 16:30:14', '1480.00', 2293, '-0.3400', NULL, NULL, '2026-08-03 16:30:14'),
(2053, 32, '2026-08-03 16:30:14', '37995.00', 5427, '-0.0100', NULL, NULL, '2026-08-03 16:30:14'),
(2054, 33, '2026-08-03 16:30:14', '2325.00', 5795, '5.6800', NULL, NULL, '2026-08-03 16:30:14'),
(2055, 34, '2026-08-03 16:30:14', '8800.00', 4617, '-2.2200', NULL, NULL, '2026-08-03 16:30:14'),
(2056, 35, '2026-08-03 16:30:14', '6600.00', 19, '3.1200', NULL, NULL, '2026-08-03 16:30:14'),
(2057, 36, '2026-08-03 16:30:14', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 16:30:14'),
(2058, 37, '2026-08-03 16:30:14', '37700.00', 935, '-0.1300', NULL, NULL, '2026-08-03 16:30:14'),
(2059, 38, '2026-08-03 16:30:14', '15500.00', 1972, '0.0300', NULL, NULL, '2026-08-03 16:30:14'),
(2060, 39, '2026-08-03 16:30:14', '31000.00', 10204, '0.0000', NULL, NULL, '2026-08-03 16:30:14'),
(2061, 40, '2026-08-03 16:30:14', '8400.00', 2652, '1.1400', NULL, NULL, '2026-08-03 16:30:14'),
(2062, 41, '2026-08-03 16:30:14', '7500.00', 4030, '-0.8600', NULL, NULL, '2026-08-03 16:30:14'),
(2063, 42, '2026-08-03 16:30:14', '2820.00', 4652, '5.2200', NULL, NULL, '2026-08-03 16:30:14'),
(2064, 43, '2026-08-03 16:30:14', '23985.00', 2331, '3.3800', NULL, NULL, '2026-08-03 16:30:14'),
(2065, 44, '2026-08-03 16:30:14', '2990.00', 7265, '0.5000', NULL, NULL, '2026-08-03 16:30:14'),
(2066, 45, '2026-08-03 16:30:14', '3655.00', 2070, '0.0000', NULL, NULL, '2026-08-03 16:30:14'),
(2067, 46, '2026-08-03 16:30:14', '51020.00', 37, '0.0400', NULL, NULL, '2026-08-03 16:30:14'),
(2068, 47, '2026-08-03 16:30:14', '1900.00', 6968, '-2.3100', NULL, NULL, '2026-08-03 16:30:14'),
(2069, 1, '2026-08-03 16:40:08', '2990.00', 2842, '-0.3300', NULL, NULL, '2026-08-03 16:40:08'),
(2070, 2, '2026-08-03 16:40:08', '7690.00', 22443, '1.3800', NULL, NULL, '2026-08-03 16:40:08'),
(2071, 3, '2026-08-03 16:40:08', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 16:40:08'),
(2072, 4, '2026-08-03 16:40:08', '1930.00', 2336, '2.1200', NULL, NULL, '2026-08-03 16:40:08'),
(2073, 5, '2026-08-03 16:40:08', '8695.00', 8777, '-0.0600', NULL, NULL, '2026-08-03 16:40:08'),
(2074, 6, '2026-08-03 16:40:08', '7200.00', 3920, '1.4100', NULL, NULL, '2026-08-03 16:40:08'),
(2075, 7, '2026-08-03 16:40:08', '11350.00', 5608, '4.9500', NULL, NULL, '2026-08-03 16:40:08'),
(2076, 8, '2026-08-03 16:40:08', '5625.00', 3155, '-0.7100', NULL, NULL, '2026-08-03 16:40:08'),
(2077, 9, '2026-08-03 16:40:08', '5200.00', 1036, '-1.7000', NULL, NULL, '2026-08-03 16:40:08'),
(2078, 10, '2026-08-03 16:40:08', '7685.00', 3389, '-1.3500', NULL, NULL, '2026-08-03 16:40:08'),
(2079, 11, '2026-08-03 16:40:08', '3645.00', 6890, '4.1400', NULL, NULL, '2026-08-03 16:40:08'),
(2080, 12, '2026-08-03 16:40:08', '28490.00', 1113, '1.7500', NULL, NULL, '2026-08-03 16:40:08'),
(2081, 13, '2026-08-03 16:40:08', '1695.00', 2656, '1.8000', NULL, NULL, '2026-08-03 16:40:08'),
(2082, 14, '2026-08-03 16:40:08', '5000.00', 6428, '0.0000', NULL, NULL, '2026-08-03 16:40:08'),
(2083, 15, '2026-08-03 16:40:08', '16100.00', 1507, '-0.8000', NULL, NULL, '2026-08-03 16:40:08'),
(2084, 16, '2026-08-03 16:40:08', '69.00', 836211, '4.5500', NULL, NULL, '2026-08-03 16:40:08'),
(2085, 17, '2026-08-03 16:40:08', '1910.00', 3008, '-3.0500', NULL, NULL, '2026-08-03 16:40:08'),
(2086, 18, '2026-08-03 16:40:08', '4560.00', 9055, '1.3300', NULL, NULL, '2026-08-03 16:40:08'),
(2087, 19, '2026-08-03 16:40:08', '2280.00', 643, '0.0000', NULL, NULL, '2026-08-03 16:40:08'),
(2088, 20, '2026-08-03 16:40:08', '24020.00', 688, '0.0800', NULL, NULL, '2026-08-03 16:40:08'),
(2089, 21, '2026-08-03 16:40:08', '16550.00', 1778, '0.3000', NULL, NULL, '2026-08-03 16:40:08'),
(2090, 22, '2026-08-03 16:40:08', '2990.00', 2793, '-0.1700', NULL, NULL, '2026-08-03 16:40:08'),
(2091, 23, '2026-08-03 16:40:08', '17000.00', 1175, '0.0300', NULL, NULL, '2026-08-03 16:40:08'),
(2092, 24, '2026-08-03 16:40:08', '3130.00', 5561, '-0.3200', NULL, NULL, '2026-08-03 16:40:08'),
(2093, 25, '2026-08-03 16:40:08', '8990.00', 831, '-0.1100', NULL, NULL, '2026-08-03 16:40:08'),
(2094, 26, '2026-08-03 16:40:08', '4400.00', 458, '-2.5500', NULL, NULL, '2026-08-03 16:40:08'),
(2095, 27, '2026-08-03 16:40:08', '5000.00', 18854, '-2.5300', NULL, NULL, '2026-08-03 16:40:08'),
(2096, 28, '2026-08-03 16:40:08', '3700.00', 4780, '1.5100', NULL, NULL, '2026-08-03 16:40:08'),
(2097, 29, '2026-08-03 16:40:08', '11900.00', 669, '0.0000', NULL, NULL, '2026-08-03 16:40:08'),
(2098, 30, '2026-08-03 16:40:08', '2750.00', 5315, '2.2300', NULL, NULL, '2026-08-03 16:40:08'),
(2099, 31, '2026-08-03 16:40:08', '1480.00', 2293, '-0.3400', NULL, NULL, '2026-08-03 16:40:08'),
(2100, 32, '2026-08-03 16:40:08', '37995.00', 5427, '-0.0100', NULL, NULL, '2026-08-03 16:40:08'),
(2101, 33, '2026-08-03 16:40:08', '2325.00', 5795, '5.6800', NULL, NULL, '2026-08-03 16:40:08'),
(2102, 34, '2026-08-03 16:40:08', '8800.00', 4617, '-2.2200', NULL, NULL, '2026-08-03 16:40:08'),
(2103, 35, '2026-08-03 16:40:08', '6600.00', 19, '3.1200', NULL, NULL, '2026-08-03 16:40:08'),
(2104, 36, '2026-08-03 16:40:08', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 16:40:08'),
(2105, 37, '2026-08-03 16:40:08', '37700.00', 935, '-0.1300', NULL, NULL, '2026-08-03 16:40:08'),
(2106, 38, '2026-08-03 16:40:08', '15500.00', 1972, '0.0300', NULL, NULL, '2026-08-03 16:40:08'),
(2107, 39, '2026-08-03 16:40:08', '31000.00', 10204, '0.0000', NULL, NULL, '2026-08-03 16:40:08'),
(2108, 40, '2026-08-03 16:40:08', '8400.00', 2652, '1.1400', NULL, NULL, '2026-08-03 16:40:08'),
(2109, 41, '2026-08-03 16:40:08', '7500.00', 4030, '-0.8600', NULL, NULL, '2026-08-03 16:40:08'),
(2110, 42, '2026-08-03 16:40:08', '2820.00', 4652, '5.2200', NULL, NULL, '2026-08-03 16:40:08');
INSERT INTO `intraday_quotes` (`id`, `company_id`, `quote_datetime`, `price`, `volume`, `variation_percent`, `bid_price`, `ask_price`, `created_at`) VALUES
(2111, 43, '2026-08-03 16:40:08', '23985.00', 2331, '3.3800', NULL, NULL, '2026-08-03 16:40:08'),
(2112, 44, '2026-08-03 16:40:08', '2990.00', 7265, '0.5000', NULL, NULL, '2026-08-03 16:40:08'),
(2113, 45, '2026-08-03 16:40:08', '3655.00', 2070, '0.0000', NULL, NULL, '2026-08-03 16:40:08'),
(2114, 46, '2026-08-03 16:40:08', '51020.00', 37, '0.0400', NULL, NULL, '2026-08-03 16:40:08'),
(2115, 47, '2026-08-03 16:40:08', '1900.00', 6968, '-2.3100', NULL, NULL, '2026-08-03 16:40:08'),
(2116, 1, '2026-08-03 16:50:08', '2990.00', 2842, '-0.3300', NULL, NULL, '2026-08-03 16:50:08'),
(2117, 2, '2026-08-03 16:50:08', '7690.00', 22443, '1.3800', NULL, NULL, '2026-08-03 16:50:08'),
(2118, 3, '2026-08-03 16:50:08', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 16:50:08'),
(2119, 4, '2026-08-03 16:50:08', '1930.00', 2336, '2.1200', NULL, NULL, '2026-08-03 16:50:08'),
(2120, 5, '2026-08-03 16:50:08', '8695.00', 8777, '-0.0600', NULL, NULL, '2026-08-03 16:50:08'),
(2121, 6, '2026-08-03 16:50:08', '7200.00', 3920, '1.4100', NULL, NULL, '2026-08-03 16:50:08'),
(2122, 7, '2026-08-03 16:50:08', '11350.00', 5608, '4.9500', NULL, NULL, '2026-08-03 16:50:08'),
(2123, 8, '2026-08-03 16:50:08', '5625.00', 3155, '-0.7100', NULL, NULL, '2026-08-03 16:50:08'),
(2124, 9, '2026-08-03 16:50:09', '5200.00', 1036, '-1.7000', NULL, NULL, '2026-08-03 16:50:09'),
(2125, 10, '2026-08-03 16:50:09', '7685.00', 3389, '-1.3500', NULL, NULL, '2026-08-03 16:50:09'),
(2126, 11, '2026-08-03 16:50:09', '3645.00', 6890, '4.1400', NULL, NULL, '2026-08-03 16:50:09'),
(2127, 12, '2026-08-03 16:50:09', '28490.00', 1113, '1.7500', NULL, NULL, '2026-08-03 16:50:09'),
(2128, 13, '2026-08-03 16:50:09', '1695.00', 2656, '1.8000', NULL, NULL, '2026-08-03 16:50:09'),
(2129, 14, '2026-08-03 16:50:09', '5000.00', 6428, '0.0000', NULL, NULL, '2026-08-03 16:50:09'),
(2130, 15, '2026-08-03 16:50:09', '16100.00', 1507, '-0.8000', NULL, NULL, '2026-08-03 16:50:09'),
(2131, 16, '2026-08-03 16:50:09', '69.00', 836211, '4.5500', NULL, NULL, '2026-08-03 16:50:09'),
(2132, 17, '2026-08-03 16:50:09', '1910.00', 3008, '-3.0500', NULL, NULL, '2026-08-03 16:50:09'),
(2133, 18, '2026-08-03 16:50:09', '4560.00', 9055, '1.3300', NULL, NULL, '2026-08-03 16:50:09'),
(2134, 19, '2026-08-03 16:50:09', '2280.00', 643, '0.0000', NULL, NULL, '2026-08-03 16:50:09'),
(2135, 20, '2026-08-03 16:50:09', '24020.00', 688, '0.0800', NULL, NULL, '2026-08-03 16:50:09'),
(2136, 21, '2026-08-03 16:50:09', '16550.00', 1778, '0.3000', NULL, NULL, '2026-08-03 16:50:09'),
(2137, 22, '2026-08-03 16:50:09', '2990.00', 2793, '-0.1700', NULL, NULL, '2026-08-03 16:50:09'),
(2138, 23, '2026-08-03 16:50:09', '17000.00', 1175, '0.0300', NULL, NULL, '2026-08-03 16:50:09'),
(2139, 24, '2026-08-03 16:50:09', '3130.00', 5561, '-0.3200', NULL, NULL, '2026-08-03 16:50:09'),
(2140, 25, '2026-08-03 16:50:09', '8990.00', 831, '-0.1100', NULL, NULL, '2026-08-03 16:50:09'),
(2141, 26, '2026-08-03 16:50:09', '4400.00', 458, '-2.5500', NULL, NULL, '2026-08-03 16:50:09'),
(2142, 27, '2026-08-03 16:50:09', '5000.00', 18854, '-2.5300', NULL, NULL, '2026-08-03 16:50:09'),
(2143, 28, '2026-08-03 16:50:09', '3700.00', 4780, '1.5100', NULL, NULL, '2026-08-03 16:50:09'),
(2144, 29, '2026-08-03 16:50:09', '11900.00', 669, '0.0000', NULL, NULL, '2026-08-03 16:50:09'),
(2145, 30, '2026-08-03 16:50:09', '2750.00', 5315, '2.2300', NULL, NULL, '2026-08-03 16:50:09'),
(2146, 31, '2026-08-03 16:50:09', '1480.00', 2293, '-0.3400', NULL, NULL, '2026-08-03 16:50:09'),
(2147, 32, '2026-08-03 16:50:09', '37995.00', 5427, '-0.0100', NULL, NULL, '2026-08-03 16:50:09'),
(2148, 33, '2026-08-03 16:50:09', '2325.00', 5795, '5.6800', NULL, NULL, '2026-08-03 16:50:09'),
(2149, 34, '2026-08-03 16:50:09', '8800.00', 4617, '-2.2200', NULL, NULL, '2026-08-03 16:50:09'),
(2150, 35, '2026-08-03 16:50:09', '6600.00', 19, '3.1200', NULL, NULL, '2026-08-03 16:50:09'),
(2151, 36, '2026-08-03 16:50:09', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 16:50:09'),
(2152, 37, '2026-08-03 16:50:09', '37700.00', 935, '-0.1300', NULL, NULL, '2026-08-03 16:50:09'),
(2153, 38, '2026-08-03 16:50:09', '15500.00', 1972, '0.0300', NULL, NULL, '2026-08-03 16:50:09'),
(2154, 39, '2026-08-03 16:50:09', '31000.00', 10204, '0.0000', NULL, NULL, '2026-08-03 16:50:09'),
(2155, 40, '2026-08-03 16:50:09', '8400.00', 2652, '1.1400', NULL, NULL, '2026-08-03 16:50:09'),
(2156, 41, '2026-08-03 16:50:09', '7500.00', 4030, '-0.8600', NULL, NULL, '2026-08-03 16:50:09'),
(2157, 42, '2026-08-03 16:50:09', '2820.00', 4652, '5.2200', NULL, NULL, '2026-08-03 16:50:09'),
(2158, 43, '2026-08-03 16:50:09', '23985.00', 2331, '3.3800', NULL, NULL, '2026-08-03 16:50:09'),
(2159, 44, '2026-08-03 16:50:09', '2990.00', 7265, '0.5000', NULL, NULL, '2026-08-03 16:50:09'),
(2160, 45, '2026-08-03 16:50:09', '3655.00', 2070, '0.0000', NULL, NULL, '2026-08-03 16:50:09'),
(2161, 46, '2026-08-03 16:50:09', '51020.00', 37, '0.0400', NULL, NULL, '2026-08-03 16:50:09'),
(2162, 47, '2026-08-03 16:50:09', '1900.00', 6968, '-2.3100', NULL, NULL, '2026-08-03 16:50:09'),
(2163, 1, '2026-08-03 17:00:20', '2990.00', 2842, '-0.3300', NULL, NULL, '2026-08-03 17:00:20'),
(2164, 2, '2026-08-03 17:00:20', '7690.00', 22443, '1.3800', NULL, NULL, '2026-08-03 17:00:20'),
(2165, 3, '2026-08-03 17:00:20', '29015.00', 57, '1.2000', NULL, NULL, '2026-08-03 17:00:20'),
(2166, 4, '2026-08-03 17:00:20', '1930.00', 2336, '2.1200', NULL, NULL, '2026-08-03 17:00:20'),
(2167, 5, '2026-08-03 17:00:20', '8695.00', 8777, '-0.0600', NULL, NULL, '2026-08-03 17:00:20'),
(2168, 6, '2026-08-03 17:00:20', '7200.00', 3920, '1.4100', NULL, NULL, '2026-08-03 17:00:21'),
(2169, 7, '2026-08-03 17:00:21', '11350.00', 5608, '4.9500', NULL, NULL, '2026-08-03 17:00:21'),
(2170, 8, '2026-08-03 17:00:21', '5625.00', 3155, '-0.7100', NULL, NULL, '2026-08-03 17:00:21'),
(2171, 9, '2026-08-03 17:00:21', '5200.00', 1036, '-1.7000', NULL, NULL, '2026-08-03 17:00:21'),
(2172, 10, '2026-08-03 17:00:21', '7685.00', 3389, '-1.3500', NULL, NULL, '2026-08-03 17:00:21'),
(2173, 11, '2026-08-03 17:00:21', '3645.00', 6890, '4.1400', NULL, NULL, '2026-08-03 17:00:21'),
(2174, 12, '2026-08-03 17:00:21', '28490.00', 1113, '1.7500', NULL, NULL, '2026-08-03 17:00:21'),
(2175, 13, '2026-08-03 17:00:21', '1695.00', 2656, '1.8000', NULL, NULL, '2026-08-03 17:00:21'),
(2176, 14, '2026-08-03 17:00:21', '5000.00', 6428, '0.0000', NULL, NULL, '2026-08-03 17:00:21'),
(2177, 15, '2026-08-03 17:00:21', '16100.00', 1507, '-0.8000', NULL, NULL, '2026-08-03 17:00:21'),
(2178, 16, '2026-08-03 17:00:21', '69.00', 836211, '4.5500', NULL, NULL, '2026-08-03 17:00:21'),
(2179, 17, '2026-08-03 17:00:21', '1910.00', 3008, '-3.0500', NULL, NULL, '2026-08-03 17:00:21'),
(2180, 18, '2026-08-03 17:00:21', '4560.00', 9055, '1.3300', NULL, NULL, '2026-08-03 17:00:21'),
(2181, 19, '2026-08-03 17:00:21', '2280.00', 643, '0.0000', NULL, NULL, '2026-08-03 17:00:21'),
(2182, 20, '2026-08-03 17:00:21', '24020.00', 688, '0.0800', NULL, NULL, '2026-08-03 17:00:21'),
(2183, 21, '2026-08-03 17:00:21', '16550.00', 1778, '0.3000', NULL, NULL, '2026-08-03 17:00:21'),
(2184, 22, '2026-08-03 17:00:21', '2990.00', 2793, '-0.1700', NULL, NULL, '2026-08-03 17:00:21'),
(2185, 23, '2026-08-03 17:00:21', '17000.00', 1175, '0.0300', NULL, NULL, '2026-08-03 17:00:21'),
(2186, 24, '2026-08-03 17:00:21', '3130.00', 5561, '-0.3200', NULL, NULL, '2026-08-03 17:00:21'),
(2187, 25, '2026-08-03 17:00:21', '8990.00', 831, '-0.1100', NULL, NULL, '2026-08-03 17:00:21'),
(2188, 26, '2026-08-03 17:00:21', '4400.00', 458, '-2.5500', NULL, NULL, '2026-08-03 17:00:21'),
(2189, 27, '2026-08-03 17:00:21', '5000.00', 18854, '-2.5300', NULL, NULL, '2026-08-03 17:00:21'),
(2190, 28, '2026-08-03 17:00:21', '3700.00', 4780, '1.5100', NULL, NULL, '2026-08-03 17:00:21'),
(2191, 29, '2026-08-03 17:00:21', '11900.00', 669, '0.0000', NULL, NULL, '2026-08-03 17:00:21'),
(2192, 30, '2026-08-03 17:00:21', '2750.00', 5315, '2.2300', NULL, NULL, '2026-08-03 17:00:21'),
(2193, 31, '2026-08-03 17:00:21', '1480.00', 2293, '-0.3400', NULL, NULL, '2026-08-03 17:00:21'),
(2194, 32, '2026-08-03 17:00:21', '37995.00', 5427, '-0.0100', NULL, NULL, '2026-08-03 17:00:21'),
(2195, 33, '2026-08-03 17:00:21', '2325.00', 5795, '5.6800', NULL, NULL, '2026-08-03 17:00:21'),
(2196, 34, '2026-08-03 17:00:21', '8800.00', 4617, '-2.2200', NULL, NULL, '2026-08-03 17:00:21'),
(2197, 35, '2026-08-03 17:00:21', '6600.00', 19, '3.1200', NULL, NULL, '2026-08-03 17:00:21'),
(2198, 36, '2026-08-03 17:00:21', '2200.00', 3414, '0.0000', NULL, NULL, '2026-08-03 17:00:21'),
(2199, 37, '2026-08-03 17:00:21', '37700.00', 935, '-0.1300', NULL, NULL, '2026-08-03 17:00:21'),
(2200, 38, '2026-08-03 17:00:21', '15500.00', 1972, '0.0300', NULL, NULL, '2026-08-03 17:00:21'),
(2201, 39, '2026-08-03 17:00:21', '31000.00', 10204, '0.0000', NULL, NULL, '2026-08-03 17:00:21'),
(2202, 40, '2026-08-03 17:00:21', '8400.00', 2652, '1.1400', NULL, NULL, '2026-08-03 17:00:21'),
(2203, 41, '2026-08-03 17:00:21', '7500.00', 4030, '-0.8600', NULL, NULL, '2026-08-03 17:00:21'),
(2204, 42, '2026-08-03 17:00:21', '2820.00', 4652, '5.2200', NULL, NULL, '2026-08-03 17:00:21'),
(2205, 43, '2026-08-03 17:00:21', '23985.00', 2331, '3.3800', NULL, NULL, '2026-08-03 17:00:21'),
(2206, 44, '2026-08-03 17:00:21', '2990.00', 7265, '0.5000', NULL, NULL, '2026-08-03 17:00:21'),
(2207, 45, '2026-08-03 17:00:21', '3655.00', 2070, '0.0000', NULL, NULL, '2026-08-03 17:00:21'),
(2208, 46, '2026-08-03 17:00:21', '51020.00', 37, '0.0400', NULL, NULL, '2026-08-03 17:00:21'),
(2209, 47, '2026-08-03 17:00:21', '1900.00', 6968, '-2.3100', NULL, NULL, '2026-08-03 17:00:21');

-- --------------------------------------------------------

--
-- Doublure de structure pour la vue `latest_quotes`
-- (Voir ci-dessous la vue réelle)
--
CREATE TABLE `latest_quotes` (
`company_id` int(11)
,`symbol` varchar(10)
,`name` varchar(255)
,`sector` varchar(100)
,`trading_date` date
,`open_price` decimal(15,2)
,`close_price` decimal(15,2)
,`high_price` decimal(15,2)
,`low_price` decimal(15,2)
,`previous_close` decimal(15,2)
,`volume` bigint(20)
,`variation_percent` decimal(10,4)
,`turnover` decimal(20,2)
);

-- --------------------------------------------------------

--
-- Structure de la table `market_bulletins`
--

CREATE TABLE `market_bulletins` (
  `id` bigint(20) NOT NULL,
  `publish_date` date NOT NULL COMMENT 'Déduite du préfixe boc_YYYYMMDD du nom de fichier',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'URL source sur brvm.org',
  `local_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Chemin local du PDF téléchargé',
  `file_size` bigint(20) DEFAULT NULL COMMENT 'Taille du fichier en octets',
  `file_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'SHA-256 du contenu téléchargé (détection de doublons/changements)',
  `downloaded_at` timestamp NULL DEFAULT NULL,
  `text_extracted` tinyint(1) DEFAULT '0',
  `extraction_method` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'text (pdftotext) ou ocr (tesseract)',
  `extraction_error` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `market_bulletins`
--

INSERT INTO `market_bulletins` (`id`, `publish_date`, `title`, `file_url`, `local_path`, `file_size`, `file_hash`, `downloaded_at`, `text_extracted`, `extraction_method`, `extraction_error`, `created_at`, `updated_at`) VALUES
(1, '2026-07-31', 'Bulletin Officiel de la Cote du 31 Juillet 2026', 'https://www.brvm.org/sites/default/files/boc_20260731_2.pdf', '/home/brimmobi/public_html/brvmapi/storage/bulletins/boc_20260731_2.pdf', 918276, '47641cc76b7c710598accfb2bfc4de8b380e34fe977d14663ac28b27e6e0e733', '2026-08-03 00:31:10', 1, NULL, NULL, '2026-08-03 01:30:54', '2026-08-03 02:20:05'),
(2, '2026-07-30', 'Bulletin Officiel de la Cote du 30 Juillet 2026', 'https://www.brvm.org/sites/default/files/boc_20260730_2.pdf', '/home/brimmobi/public_html/brvmapi/storage/bulletins/boc_20260730_2.pdf', 919151, 'c17808d890547ce70d953c13b3482aac4403b9df6612b9282161c3f65c636525', '2026-08-03 00:31:18', 0, NULL, 'pdftotext introuvable (installer poppler: brew install poppler) (OCR non disponible: installer tesseract avec \'brew install tesseract tesseract-lang\')', '2026-08-03 01:30:54', '2026-08-03 01:31:18'),
(3, '2026-07-29', 'Bulletin Officiel de la Cote du 29 Juillet 2026', 'https://www.brvm.org/sites/default/files/boc_20260729_2.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 01:30:54', '2026-08-03 01:30:54'),
(4, '2026-07-28', 'Bulletin Officiel de la Cote du 28 Juillet 2026', 'https://www.brvm.org/sites/default/files/boc_20260728_2.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 01:30:54', '2026-08-03 01:30:54'),
(5, '2026-07-27', 'Bulletin Officiel de la Cote du 27 Juillet 2026', 'https://www.brvm.org/sites/default/files/boc_20260727_2.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 01:30:54', '2026-08-03 01:30:54'),
(6, '2026-07-24', 'Bulletin Officiel de la Cote du 24 Juillet 2026', 'https://www.brvm.org/sites/default/files/boc_20260724_2.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 01:30:54', '2026-08-03 01:30:54'),
(7, '2026-07-23', 'Bulletin Officiel de la Cote du 23 Juillet 2026', 'https://www.brvm.org/sites/default/files/boc_20260723_2.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 01:30:54', '2026-08-03 01:30:54'),
(8, '2026-07-22', 'Bulletin Officiel de la Cote du 22 Juillet 2026', 'https://www.brvm.org/sites/default/files/boc_20260722_2.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 01:30:54', '2026-08-03 01:30:54'),
(9, '2026-07-21', 'Bulletin Officiel de la Cote du 21 Juillet 2026', 'https://www.brvm.org/sites/default/files/boc_20260721_2.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 01:30:54', '2026-08-03 01:30:54'),
(10, '2026-07-20', 'Bulletin Officiel de la Cote du 20 Juillet 2026', 'https://www.brvm.org/sites/default/files/boc_20260720_2.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 01:30:54', '2026-08-03 01:30:54'),
(11, '2025-12-31', 'Bulletin Officiel de la Cote du 31 Décembre 2025', 'https://www.brvm.org/sites/default/files/boc_20251231_2.pdf', NULL, NULL, NULL, NULL, 0, NULL, NULL, '2026-08-03 01:30:54', '2026-08-03 01:30:54');

-- --------------------------------------------------------

--
-- Structure de la table `market_bulletin_analyses`
--

CREATE TABLE `market_bulletin_analyses` (
  `id` bigint(20) NOT NULL,
  `bulletin_id` bigint(20) NOT NULL,
  `provider` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'gemini',
  `model` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text COLLATE utf8mb4_unicode_ci COMMENT 'résumé de séance court, pour affichage/listage rapide',
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin COMMENT 'analyse complète structurée (mouvements, secteurs, sentiment, glossaire...)',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success' COMMENT 'success|failed',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `input_char_count` int(11) DEFAULT NULL,
  `raw_response` longtext COLLATE utf8mb4_unicode_ci COMMENT 'réponse brute du fournisseur IA, pour audit/debug',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `market_bulletin_comparisons`
--

CREATE TABLE `market_bulletin_comparisons` (
  `id` bigint(20) NOT NULL,
  `request_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'sha256(bulletin_ids triés)',
  `bulletin_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `provider` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `computed_date` date NOT NULL COMMENT 'date du calcul — cache "une fois par jour"',
  `summary` text COLLATE utf8mb4_unicode_ci,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin COMMENT 'analyse comparative complète + chart_data',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `input_char_count` int(11) DEFAULT NULL,
  `raw_response` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `market_bulletin_contents`
--

CREATE TABLE `market_bulletin_contents` (
  `bulletin_id` bigint(20) NOT NULL,
  `extracted_text` longtext COLLATE utf8mb4_unicode_ci,
  `formatted_markdown` longtext COLLATE utf8mb4_unicode_ci COMMENT 'Texte brut restructuré en markdown (tableaux) par IA, voir class/BulletinMarkdownFormatterService.php',
  `markdown_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'processing|success|failed',
  `markdown_error` text COLLATE utf8mb4_unicode_ci,
  `markdown_provider` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `markdown_model` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `markdown_updated_at` timestamp NULL DEFAULT NULL,
  `char_count` int(11) DEFAULT NULL,
  `extracted_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `market_bulletin_contents`
--

INSERT INTO `market_bulletin_contents` (`bulletin_id`, `extracted_text`, `formatted_markdown`, `markdown_status`, `markdown_error`, `markdown_provider`, `markdown_model`, `markdown_updated_at`, `char_count`, `extracted_at`) VALUES
(1, NULL, '                                                                                                          vendredi 31 juillet 2026                                                                                   N° 144\n\n BRVM COMPOSITE                                        482,65                     BRVM 30                                         230,08                     BRVM PRESTIGE                                     177,56\n\n Variation Jour                                         0,24 %                    Variation Jour                                       0,56 %                Variation Jour                                      1,17 %\n\n\n Variation annuelle                                    39,60 %                    Variation annuelle                                38,40 %                  Variation annuelle                                 23,09 %\n\n\n\n\nActions                                                            Niveau                      Evol. Jour                Obligations                                              Niveau                       Evol. Jour\nCapitalisation boursière (FCFA)(Actions & Droits)                 18 593 354 163 067                       0,24 %        Capitalisation boursière (FCFA)                      13 026 198 162 671                          0,07 %\nVolume échangé (Actions & Droits)                                           2 990 556                     73,43 %        Volume échangé                                                         1 129                -99,36 %\nValeur transigée (FCFA) (Actions & Droits)                               1 529 105 408                    -10,51 %       Valeur transigée (FCFA)                                        11 496 929                   -99,32 %\nNombre de titres transigés                                                          47                     0,00 %        Nombre de titres transigés                                                9                 -25,00 %\nNombre de titres en hausse                                                          22                    37,50 %        Nombre de titres en hausse                                                6\nNombre de titres en baisse                                                          15                    -37,50 %       Nombre de titres en baisse                                                2                 -33,33 %\nNombre de titres inchangés                                                          10                    42,86 %        Nombre de titres inchangés                                                1                 -88,89 %\n\nPLUS FORTES HAUSSES                                                                                                      PLUS FORTES BAISSES\nTitres                                                           Cours           Evol. Jour          Evol.               Titres                                          Cours              Evol. Jour            Evol.\n                                                                                                    annuelle                                                                                                     annuelle\nTOTALENERGIES MARKETING CI (TTLC)                                        2 975           7,40 %            27,41 %       UNILEVER CI (UNLC)                                        51 000          -5,56 %               49,01 %\nLOTERIE NATIONALE DU BENIN (LNBB)                                        4 500           7,40 %             4,77 %       SOLIBRA CI (SLBC)                                         37 750          -4,89 %               30,62 %\nSICOR CI (SICC)                                                          6 400           7,11 %            93,94 %       SETAO CI (STAC)                                            2 680          -4,29 %           102,26 %\nONATEL BF (ONTBF)                                                        2 995           6,77 %            20,52 %       SUCRIVOIRE (SCRC)                                          3 645          -4,08 %           243,87 %\nORAGROUP TOGO (ORGT)                                                     3 140           4,67 %            30,83 %       ECOBANK TRANS. INCORP. TG (ETIT)                             66           -2,94 %           186,96 %\n\n INDICES PAR COMPARTIMENT\nBase = 100 au 02 janvier 2023                            Nombre de sociétés                     Valeur               Evol. Jour        Evol. annuelle       Volume                Valeur                       PER moyen\nBRVM-PRESTIGE                                                      12                            177,56                1,17 %                23,09 %         35 279               445 745 005                    12,80\nBRVM-PRINCIPAL          (**)                                       35                            365,27                -0,61 %               67,82 %        2 955 277         1 083 360 403                      17,17\n(**) PER moyen calculé sans la valeur UNILEVER CI\n INDICE TOTAL RETURN\n Base = 100 au 02 janvier 2025                           Nombre de sociétés                     Valeur               Evol. Jour        Evol. annuelle       Volume                 Valeur                      PER moyen\n BRVM – COMPOSITE TOTAL RETURN                  (**)               47                            192,62                0,27 %                44,67 %        2 990 556         1 529 105 408                      14,59\n(**) PER moyen calculé sans la valeur UNILEVER CI\n INDICES SECTORIELS\nBase = 100 au 02 janvier 2025                           Nombre de sociétés                     Valeur                Evol. Jour        Evol. annuelle       Volume                Valeur                       PER moyen\nBRVM - TELECOMMUNICATIONS                                          3                            112,93                 0,83 %                19,00 %          9 955            139 461 910                       11,11\nBRVM - CONSOMMATION DISCRETIONNAIRE                                7                            203,42                 0,84 %                17,57 %         18 463               40 817 410                     39,31\nBRVM - SERVICES FINANCIERS                                         16                           234,83                 0,35 %                61,00 %        2 883 353          796 145 538                       15,81\nBRVM - CONSOMMATION DE BASE                  (**)                  9                            279,38                 -1,98 %               29,29 %         23 002            333 663 235                       10,46\nBRVM - INDUSTRIELS                                                 6                            215,02                 -0,57 %               62,49 %         39 734            109 519 500                       22,31\nBRVM - ENERGIE                                                     4                            159,44                 2,10 %                43,76 %          9 871               75 927 905                     18,13\nBRVM - SERVICES PUBLICS                                            2                            222,36                 0,58 %               109,97 %          6 178               33 569 910                     20,47\n(**) PER moyen calculé sans la valeur UNILEVER CI\n\nIndicateurs                                                                      BRVM COMPOSITE                          Indicateurs                                                        BRVM COMPOSITE\nPER moyen du marché            (**)                                                      14,59                           Ratio moyen de liquidité                                                   59,02\nTaux de rendement moyen du marché                                                        6,21                            Ratio moyen de satisfaction                                                52,28\nTaux de rentabilité moyen du marché                                                      6,93                            Ratio moyen de tendance                                                    88,58\nNombre de sociétés cotées                                                                 47                             Ratio moyen de couverture                                                  112,89\nNombre de lignes obligataires                                                            206                             Taux de rotation moyen du marché                                               0,27\nVolume moyen annuel par séance                                                     1 927 409,00                          Prime de risque du marché                                                      1,10\nValeur moyenne annuelle par séance                                                2 777 536 624,59                       Nombre de SGI participantes                                                    37\n(**) PER moyen calculé sans la valeur UNILEVER CI\n\n\n\n\n                                                                                                                                   1\n    vendredi 31 juillet 2026\n\n\n\n\n2\n                                                                                                                                                                     vendredi 31 juillet 2026\n\n\n\nCode                                                          Cours du jour                         Séance de cotation                         Variation de        Dernier dividende                         PER\nSect.                                            Cours                                Variation                                    Cours\n Act.    Symbole              Titre                                                                                                               l\'année                payé                   Rdt. Net\n                                               Précédent                                jour                                     Référence\n  (*)                                                        Ouv.        Clôt.                      Volume       Valeur                         précédente       Montant net     Date\n\nCOMPARTIMENT PRESTIGE                          177,56        points                    1,17 %\n\n        NTLC    NESTLE CI                           16 505     16 490     16 500          -0,03 %        356      5 881 245          16 500           54,93 %           721,6    18-août-25        4,37 %     19,76\n CB\n\n        PALC    PALM CI                              8 600      8 600      9 000           4,65 %       2 606    23 058 930           9 000           11,11 %          441,04    29-juin-26        4,90 %      8,97\n CB\n\n        SPHC    SAPH CI                              7 475      7 475      7 565           1,20 %       1 066     8 015 620           7 565            -4,24 %         323,84    17-juil.-25       4,28 %      7,74\n CB\n\n        SMBC    SMB CI                              15 680     15 680     15 495          -1,18 %       3 767    58 475 555          15 495           63,11 %             616 15-sept.-25          3,98 %      9,24\nENE\n\n        TTLC    TOTALENERGIES                        2 770      2 975      2 975           7,40 %       2 184     6 494 700           2 975           27,41 %          195,67    3-sept.-25        6,58 %     20,61\nENE             MARKETING CI\n\n        TTLS    TOTALENERGIES                        3 650      3 650      3 655           0,14 %       1 779     6 419 350           3 655           46,20 %          176,65    17-juil.-26       4,83 %     17,56\nENE             MARKETING SN\n\n        ECOC    ECOBANK COTE D\'\'IVOIRE              16 235     16 230     16 230          -0,03 %       1 910    30 978 325          16 230            1,44 %          781,44    26-mai-26         4,81 %     14,07\n FIN\n\n        SGBC    SOCIETE GENERALE COTE               38 000     39 000     38 000           0,00 %       2 172    82 568 960          38 000           27,11 %         1645,78     5-août-25        4,33 %     11,66\n FIN            D\'IVOIRE\n\n        SIBC    SOCIETE IVOIRIENNE DE                8 700      9 000      9 000           3,45 %       9 484    84 390 410           9 000           56,52 %             374    31-juil.-26       4,16 %     16,19\n FIN            BANQUE\n\n        ONTBF   ONATEL BF                            2 805      2 805      2 995           6,77 %       5 108    14 778 660           2 995           20,52 %          145,32    15-juin-26        4,85 %     14,03\nTEL\n\n        ORAC    ORANGE COTE D\'IVOIRE                16 760     16 760     16 995           1,40 %       1 793    30 126 445          16 995           19,26 %             704     8-juin-26        4,14 %     15,26\nTEL\n\n        SNTS    SONATEL SN                          31 000     31 000     31 000           0,00 %       3 054    94 556 805          31 000           18,68 %            1740    26-mai-26         5,61 %      7,50\nTEL\n\n        TOTAL                                                                                          35 279    445 745 005\n\n\nCOMPARTIMENT PRINCIPAL                    365,27           points                     -0,61 %\n\n        SCRC    SUCRIVOIRE                           3 800      3 795      3 645          -4,08 %       5 370     19 894 465           3 645          243,87 %            40,5   20-août-21         1,11 %\n CB\n\n        SICC    SICOR CI                             5 975      6 400      6 400           7,11 %         78          499 200          6 400           93,94 %           1919 25-sept.-00\n CB\n\n        SLBC    SOLIBRA CI                          39 690     39 000     37 750          -4,89 %       2 246     85 496 840          37 750           30,62 %         1871,76    30-juil.-26       4,96 %    13,57\n CB\n\n        SOGC    SOGB CI                              8 100      8 400      8 305           2,53 %       4 773     38 849 985           8 305            5,13 %            528     15-juil.-25       6,36 %    14,36\n CB\n\n        STBC    SITAB CI                            23 200     23 200     23 200           0,00 %       6 503    151 753 950          23 200           17,29 %           2096    29-août-25         9,03 %    11,42\n CB\n\n        UNLC    UNILEVER CI                         54 000     54 000     51 000          -5,56 %            4        213 000         51 000           49,01 %           1233      9-juil.-12                731,42\n CB\n\n        ABJC    SERVAIR ABIDJAN CI                   3 000      3 000      3 000           0,00 %       2 550        7 608 510         3 000            3,45 %           206,2 30-sept.-24          6,87 %    24,60\n CD\n\n        BNBC    BERNABE CI                           1 880      1 895      1 890           0,53 %        371          698 550          1 890           34,04 %            150     24-juil.-23       7,94 % 560,95\n CD\n\n        CFAC    CFAO MOTORS CI                       1 660      1 660      1 665           0,30 %       2 926        4 863 825         1 665           16,43 %            7,04   19-août-25         0,42 %    35,88\n CD\n\n        LNBB    LOTERIE NATIONALE DU     (V)         4 190      4 350      4 500           7,40 %       1 094        4 782 700         4 500            4,77 %          164,17    3-août-26         3,65 %    19,47\n CD             BENIN\n\n        NEIC    NEI-CEDA CI                          2 300      2 290      2 280          -0,87 %       1 257        2 875 905         2 280           90,00 %           81,78    25-juin-24        3,59 %    14,29\n CD\n\n        PRSC    TRACTAFRIC MOTORS CI                 4 520      4 500      4 515          -0,11 %        206          928 875          4 515           16,52 %          182,16 30-sept.-25          4,03 %    19,53\n CD\n\n        UNXC    UNIWAX CI                            1 900      1 895      1 945           2,37 %      10 059     19 059 045           1 945           38,93 %           60,75    29-juil.-22       3,12 %\n CD\n\n        SHEC    VIVO ENERGY CI                       2 200      2 040      2 200           0,00 %       2 141        4 538 300         2 200           52,25 %           75,29    22-oct.-25        3,42 %    22,99\nENE\n\n        BICB    BIIC BN                              7 600      7 595      7 585          -0,20 %      11 408     86 033 510           7 585           52,46 %           254,6    31-juil.-26       3,36 %    12,09\n FIN\n\n        BICC    BICI CI                             29 000     29 005     28 670          -1,14 %        439      12 759 940          28 670           47,44 %          1157,2     6-juil.-26       4,04 %    13,08\n FIN\n\n        BOAB    BANK OF AFRICA BN                    8 700      8 700      8 700           0,00 %       2 740     23 837 340           8 700           48,72 %            585     26-mai-26         6,72 %    17,55\n FIN\n\n        BOABF   BANK OF AFRICA BF                    7 100      7 100      7 100           0,00 %       6 458     46 181 035           7 100           89,33 %            397     23-avr.-26        5,59 %    16,23\n FIN\n\n        BOAC    BANK OF AFRICA CI                   10 800     10 900     10 815           0,14 %       5 074     55 834 080          10 815           50,63 %          597,53     6-mai-26         5,52 %    12,17\n FIN\n\n        BOAM    BANK OF AFRICA ML                    5 660      5 660      5 665           0,09 %       5 309     30 031 805           5 665           41,63 %          305,04     3-juin-26        5,38 %    14,03\n FIN\n\n        BOAN    BANK OF AFRICA NG                    5 290      5 290      5 290           0,00 %       5 875     30 609 840           5 290          102,68 %          209,25     3-juin-25        3,96 % 268,85\n FIN\n\n        BOAS    BANK OF AFRICA SENEGAL               7 700      7 850      7 790           1,17 %       3 612     28 105 750           7 790           48,66 %            450      1-juin-26        5,78 %    12,80\n FIN\n\n        CBIBF   CORIS BANK                          27 700     27 995     28 000           1,08 %       1 293     36 194 750          28 000          159,74 %            900     19-juin-26        3,21 %    13,68\n FIN            INTERNATIONAL\n\n        ETIT    ECOBANK TRANS. INCORP.                  68          73           66       -2,94 %   2 817 309    194 453 823             66           186,96 %             ,92    30-juin-26        1,39 %     3,45\n FIN            TG\n\n        NSBC    NSIA BANQUE COTE         (d)        23 205     23 390     24 000           3,43 %        775      18 282 490          23 325          109,70 %          675,98    4-août-26         2,82 %    14,58\n FIN            D\'IVOIRE\n\n\n                                                                                                                 3\n                                                                                                                                                                                                vendredi 31 juillet 2026\n         ORGT          ORAGROUP TOGO                                    3 000        3 095        3 140          4,67 %            6 342      19 704 635       3 140                30,83 %        59,52     17-juil.-20    1,90 %     10,07\n FIN\n\n         SAFC          SAFCA CI                                         5 205        5 195        5 130         -1,44 %            3 153      16 178 845       5 130                55,22 %        23,04     29-juil.-11               59,42\n FIN\n\n         CABC          SICABLE CI                                       3 500        3 510        3 500          0,00 %           10 266      36 042 400       3 500                48,31 %       152,02      1-juin-26     4,34 %     14,39\n IND\n\n         FTSC          FILTISAC CI                                      1 910        1 980        1 970          3,14 %              861         1 685 685     1 970            -11,26 %         1726,56 30-sept.-25       87,64 %     59,63\n IND\n\n         SDSC          AFRICA GLOBAL LOGISTICS                          2 700        2 690        2 690         -0,37 %           13 855      37 197 795       2 690                77,56 %             92   29-août-25     3,42 %      6,95\n IND                   CI\n\n         SEMC          EVIOSYS PACKAGING SIEM                           1 500        1 500        1 485         -1,00 %            2 355         3 520 580     1 485            112,14 %                14   28-déc.-21     0,94 %     36,96\n IND                   CI\n\n         SIVC          ERIUM CI (Ex AIR LIQUIDE                         2 195        2 200        2 200          0,23 %            5 877      12 989 650       2 200                39,24 %             63 29-sept.-17                107,17\n IND                   CI)\n\n         STAC          SETAO CI                                         2 800        2 790        2 680         -4,29 %            6 520      18 083 390       2 680            102,26 %           66,15     18-juil.-22    2,47 %\n IND\n\n         CIEC          CIE CI                                           5 000        5 190        5 000          0,00 %            5 873      29 968 225       5 000            111,86 %          205,92     28-juil.-26    4,12 %     21,33\n SPU\n\n         SDCC          SODE CI                                        11 650       11 650       11 900           2,15 %              305         3 601 685    11 900            105,17 %               352 30-sept.-25      2,96 %     18,21\n SPU\n         TOTAL                                                                                                                 2 955 277    1 083 360 403\n\n\n\n\nLIBEL\n LE\nGRO\n UPE\n         TOTAL\n\nTOTAL - MARCHE DES ACTIONS                                                                                                    2 990 556    1 529 105 408\n\nSecteurs d\'activités (*) :\nTEL : BRVM-TELECOMMUNICATIONS ; FIN : BRVM - SERVICES FINANCIERS ; CD : BRVM - CONSOMMATION DISCRETIONNAIRE ; CB : BRVM - CONSOMMATION DE BASE ; IND : BRVM - INDUSTRIELS ; ENE : BRVM - ENERGIE ; SPU : BRVM - SERVICES PUBLICS\n\n\n\n\n                                                                                Cours du jour                                Séance de cotation                        Variation                              Periode de négociation\n                                                              Cours                                       Variation                                      Cours\nSymbole                         Titre                                                                                                                                    depuis               Parité\n                                                            Précédent                                       jour                                       Référence\n                                                                                Ouv.         Clôt.                           Volume        Valeur                       l\'origine                                 Début         fin\n\n\nTOTAL\n\n\nTOTAL - MARCHE DES DROITS\n\nLégende:\n(d) Ex-dividende (V) Variation par rapport au cours de référence\nCours du jour: Ouv.: Ouverture Clôt.: Clôture Moy.: Moyen\nMentions : NC: Non Coté         Ex-c: Ex-coupon Ex-d: Ex-droit         SP: Suspendu    Val-T: Valeur Théorique\nPER = Cours / BNPA selon les données disponibles       Rdt. Net: Rendement net (DNPA / Cours)\nComp.: 1 - Premier Compartiment        2 - Second Compartiment        3 - Compartiment de croissance\nRatio de liquidité = Titres échangés / Volume des ordres de vente\n: Progression de l\'indice sectoriel             : Stabilité de l\'indice sectoriel            : Recul de l\'indice sectoriel\n\n\n\n\n                                                                                                                                             4\n                                                                                                                                            vendredi 31 juillet 2026\n\n\n\n1. OBLIGATIONS CLASSIQUES\n                                                    Valeur        Cours   Cours du     Cours       Séance de cotation   Coupon            Coupon d\'intérêt\n Symbole                   Titre                                                                                                                                       Type Amort\n                                                   nominale     Précédent   jour     Référence      Volume    Valeur     couru     Période Montant net Eché.\nOBLIGATIONS SOUVERAINES\n\nEOM.O10    ETAT DU MALI 6,20 % 2022-2029               10 000       10 000      NC       10 000                           397,48      A         620,00    9-déc.-26       ACD\nEOM.O11    ETAT DU MALI 6,40 % 2023-2030               10 000       10 000      NC       10 000                           201,64      A         640,00     7-avr.-27      ACD\nEOM.O12    ETAT DU MALI 6,50 % 2023-2030               10 000        9 800      NC        9 800                             5,34      A         650,00   28-juil.-27      ACD\nEOM.O13    ETAT DU MALI 6,50 % 2024-2034               10 000       10 000      NC       10 000                           208,36      A         650,00     5-avr.-27      ACD\nEOM.O14    ETAT DU MALI 3,00 % 2024-2031     (*)       10 000       10 000      NC       10 000                           299,18      A         300,00    1-août-26       ACD\nEOM.O15    ETAT DU MALI 6,55 % 2024-2031               10 000       10 000      NC       10 000                           502,47      A         655,00    24-oct.-26      ACD\nEOM.O16    ETAT DU MALI 6,35 % 2024-2029               10 000       10 000      NC       10 000                           487,12      A         635,00    24-oct.-26      ACD\nEOM.O17    ETAT DU MALI 6,55% 2025-2032                10 000       10 000      NC       10 000                           224,32      A         655,00   28-mars-27       ACD\nEOM.O18    ETAT DU MALI 6,35% 2025-2030                10 000       10 000      NC       10 000                           217,47      A         635,00   28-mars-27       ACD\nEOM.O19    ETAT DU MALI 6,55% 2025-2032                10 000       10 000      NC       10 000                           628,08      A         655,00   15-août-26       ACD\nEOM.O20    ETAT DU MALI 6,35% 2025-2030                10 000       10 000      NC       10 000                           608,90      A         635,00   15-août-26       ACD\nEOM.O21    ETAT DU MALI 6,55% 2026-2036                10 000       10 000      NC       10 000                           263,79      A         655,00    6-mars-27       ACD\nEOM.O22    ETAT DU MALI 6,35% 2026-2033                10 000       10 000      NC       10 000                           255,74      A         635,00    6-mars-27       ACD\nEOM.O4     ETAT DU MALI 6,50% 2019-2027                 2 000        2 310      NC        2 310                             6,77      A         130,00   12-juil.-27       AC\nEOM.O5     ETAT DU MALI 6,50% 2020-2028                 4 000        4 000      NC        4 000                            89,75      A         260,00   27-mars-27       ACD\nEOM.O6     ETAT DU MALI 6,50% 2020-2027                 4 000        4 000      NC        4 000                           172,86      A         260,00    30-nov.-26       AC\nEOM.O7     ETAT DU MALI 6.50% 2021-2031                 6 250        6 250      NC        6 250                           117,98      A         406,25    16-avr.-27       AC\nEOM.O8     ETAT DU MALI 6,20 % 2022-2032               10 000       10 000      NC       10 000                           555,45      A         620,00    7-sept.-26      ACD\nEOM.O9     ETAT DU MALI 6,30% 2022-2032                10 000        9 795      NC        9 795                           403,89      A         630,00    9-déc.-26       ACD\nEOS.O10    ETAT DU SENEGAL 6,15 % 2023-                10 000       10 000      NC       10 000                           207,23      S         307,50   29-sept.-26      ACD\n           2030\nEOS.O11    ETAT DU SENEGAL 6,35 % 2023-                10 000       10 000      NC       10 000                           213,97      S         317,50   29-sept.-26      ACD\n           2033\nEOS.O12    ETAT DU SENEGAL 6,15 % 2023-                10 000        9 700      NC        9 700                           283,72      S         307,50   14-août-26       ACD\n           2028\nEOS.O13    ETAT DU SENEGAL 6,30 % 2023-                10 000        9 999      NC        9 999                           290,64      S         315,00   14-août-26       ACD\n           2030\nEOS.O14    ETAT DU SENEGAL 6,50 % 2023-                10 000        9 800      NC        9 800                           299,86      S         325,00   14-août-26       ACD\n           2033\nEOS.O15    ETAT DU SENEGAL 6,25 % 2024-                10 000       10 000      NC       10 000                           296,96      S         312,50    9-août-26       ACD\n           2029\nEOS.O16    ETAT DU SENEGAL 6,45 % 2024-                10 000       10 000      NC       10 000                           306,46      S         322,50    9-août-26       ACD\n           2031\nEOS.O17    ETAT DU SENEGAL 6,65 % 2024-                10 000       10 000      NC       10 000                           315,97      S         332,50    9-août-26       ACD\n           2034\nEOS.O18    ETAT DU SENEGAL 6,40 % 2025-20              10 000        9 950      NC        9 950                           185,36      S         320,00    16-oct.-26       IF\nEOS.O19    ETAT DU SENEGAL 6,60 % 2025-                10 000       10 000      NC       10 000                           191,15      S         330,00    16-oct.-26      ACD\n           2030\nEOS.O20    ETAT DU SENEGAL 6,75 % 2025-20              10 000        9 600      NC        9 600                           195,49      S         337,50    16-oct.-26      ACD\nEOS.O21    ETAT DU SENEGAL 6,95 % 2025-20              10 000       10 000      NC       10 000                           201,28      S         347,50    16-oct.-26      ACD\nEOS.O22    ETAT DU SENEGAL 6,40% 2025-202              10 000        9 800      NC        9 800                           184,11      S         320,88    17-oct.-26       IF\nEOS.O23    ETAT DU SENEGAL 6,60% 2025-203              10 000       10 000      NC       10 000                           189,86      S         330,90    17-oct.-26      ACD\nEOS.O24    ETAT DU SENEGAL 6,75% 2025-2032             10 000       10 000      NC       10 000                           194,18      S         338,42    17-oct.-26      ACD\n\nEOS.O25    ETAT DU SENEGAL 6,95% 2025-                 10 000       10 000      NC       10 000                           199,93      S         348,45    17-oct.-26      ACD\n           2035\nEOS.O26    ETAT DU SENEGAL 6,40% 2025-2028             10 000        9 720      NC        9 720                            34,78      S         320,00   11-janv.-27       IF\n\n\nEOS.O27    ETAT DU SENEGAL 6,60% 2025-2030             10 000        9 400      NC        9 400                            35,87      S         330,00   11-janv.-27      ACD\n\nEOS.O28    ETAT DU SENEGAL 6,75% 2025-2032             10 000        9 995      NC        9 995                            36,68      S         337,50   11-janv.-27      ACD\n\n\nEOS.O29    ETAT DU SENEGAL 6,95% 2025-                 10 000        9 998      NC        9 998                            37,77      S         347,50   11-janv.-27      ACD\n           2035\nEOS.O30    ETAT DU SENEGAL 6,40% 2025-2028             10 000        9 950      NC        9 950                            55,96      S         320,00   29-déc.-26        IF\n\n\nEOS.O31    ETAT DU SENEGAL 6,60% 2025-2030             10 000       10 000      NC       10 000                            57,70      S         330,00   29-déc.-26       ACD\n\nEOS.O32    ETAT DU SENEGAL 6,75% 2025-2032             10 000        9 600      NC        9 600                            59,02      S         337,50   29-déc.-26       ACD\n\n\nEOS.O33    ETAT DU SENEGAL 6,95% 2025-                 10 000       10 000      NC       10 000                            60,77      S         347,50   29-déc.-26       ACD\n           2035\nEOS.O8     ETAT DU SENEGAL 5,95% 2022-2034             10 000       10 000      NC       10 000                           326,00      A         595,00   29-déc.-26       ACD\n\n\nEOS.O9     ETAT DU SENEGAL 6,00 % 2023-20              10 000        9 800      NC        9 800                           202,17      S         300,00   29-sept.-26      ACD\nTPBF.O10   TPBF 6,50% 2020 - 2028                       3 333        3 333      NC      3 333.36                           78,30      S         108,33   20-sept.-26      ACD\nTPBF.O11   TPBF 6,50 % 2020-2028                        3 336        3 302      NC      3 302.31                           12,96      S         108,42    9-janv.-27      ACD\nTPBF.O12   TPBF 6.50% 2021-2031                        10 000        9 990      NC        9 990                           226,09      S         325,00   25-sept.-26      ACD\nTPBF.O13   TPBF 6,50 % 2021 - 2031                     10 000        9 600      NC        9 600                            44,16      S         325,00    6-janv.-27      ACD\n\n\n                                                                                                   5\n                                                                                                                             vendredi 31 juillet 2026\nTPBF.O14    TPBF 6,30% 2022-2034                    10 000    9 993      NC      9 993                          260,22   S       315,00    1-sept.-26   ACD\nTPBF.O15    ETAT DU BURKINA 6,30 % 2022-2           10 000    9 999      NC      9 999                          131,82   S       315,00    15-nov.-26   ACD\nTPBF.O16    ETAT DU BURKINA 6% 2023-2028            10 000    9 600      NC      9 600                           34,24   S       300,00   10-janv.-27   ACD\nTPBF.O17    TPBF 6,30 % 2023-2030                   10 000   10 000      NC     10 000                          189,34   S       315,00    12-oct.-26   ACD\nTPBF.O18    TPBF 6,50% 2023-2033                    10 000   10 000      NC     10 000                          195,36   S       325,00    12-oct.-26   ACD\nTPBF.O19    ETAT DU BURKINA 6,30% 2024-2029         10 000   10 000      NC     10 000                          147,23   S       315,00     6-nov.-26   ACD\n\n\nTPBF.O20    ETAT DU BURKINA 6,55% 2024-2031         10 000   10 000      NC     10 000                          153,07   S       327,50     6-nov.-26   ACD\n\nTPBF.O21    ETAT DU BURKINA 6,50% 2024-2029         10 000    9 650   10 000    10 000        100   1 000 000   113,04   S       325,00    28-nov.-26   ACD\n\n\nTPBF.O22    ETAT DU BURKINA 6,80% 2024-2032         10 000   10 000      NC     10 000                          118,26   S       340,00    28-nov.-26   ACD\n\nTPBF.O23    ETAT DU BURKINA 6,60% 2025-2030         10 000   10 300      NC     10 300                          176,72   S       330,00    24-oct.-26   ACD\n\n\nTPBF.O24    ETAT DU BURKINA 6,80% 2025-2032         10 000    9 158      NC      9 158                          182,08   S       340,00    24-oct.-26   ACD\n\nTPBF.O25    ETAT DU BURKINA 7% 2025-2035            10 000    9 999      NC      9 999                          187,43   S       350,00    24-oct.-26   ACD\nTPBF.O4     TPBF 6.50% 2017-2027              (*)   10 000   10 300      NC     10 300                          322,33   S       322,33   31-juil.-26   ACD\nTPBF.O8     TPBF 6,50% 2019-2027                    10 000    9 650      NC      9 650                          153,15   S       327,67     6-nov.-26   ACD\nTPBF.O9     TPBF 6,50% 2019 - 2027                  10 000   11 499      NC     11 499                          202,46   S       325,00     8-oct.-26   ACD\nTPBJ.O10    TPBJ 6,15% 2025-2035                    10 000   10 000      NC     10 000                          426,29   A       615,00    20-nov.-26   ACD\nTPBJ.O3     TPBJ 6,50% 2020-2028                    10 000   10 000      NC     10 000                          605,48   A       650,00   25-août-26    AC\nTPBJ.O4     TPBJ 5,50 % 2022-2037                   10 000   10 000      NC     10 000                          229,04   A       550,00    1-mars-27    AC\nTPBJ.O5     TPBJ 5,85 % 2022-2042                   10 000   10 000      NC     10 000                          243,62   A       585,00    1-mars-27    ACD\nTPBJ.O6     TPBJ 5,75% 2022-2037                    10 000   10 000   10 100    10 100        580   5 817 700     4,73   A       575,00   28-juil.-27   ACD\nTPBJ.O7     TPBJ 5,70% 2023-2030                    10 000   10 000      NC     10 000                          218,63   A       570,00   13-mars-27    AC\nTPBJ.O8     TPBJ 6,20% 2023-2038                    10 000   10 000      NC     10 000                          237,81   A       620,00   13-mars-27    AC\nTPBJ.O9     TPBJ 6,00% 2025-2032                    10 000    9 980      NC      9 980                          415,89   A       600,00    20-nov.-26   ACD\nTPCI.O100   TPCI 5,85% 2025-2032                    10 000   10 000      SP     10 000                          360,62   A       585,00   18-déc.-26    ACD\nTPCI.O101   TPCI 5,85% 2026-2033                    10 000   10 000      NC     10 000                          224,38   A       585,00   13-mars-27    ACD\nTPCI.O102   TPCI 6,00% 2026-2036                    10 000   10 000      NC     10 000                          230,14   A       600,00   13-mars-27    ACD\nTPCI.O21    TPCI 6% 2016-2028                       10 000   10 000      NC     10 000                          187,40   A       600,00     8-avr.-27   ACD\nTPCI.O23    TPCI 5.90% 2016-2026                    10 000   10 000      SP     10 000                          413,81   A       590,00    17-nov.-26   ACD\nTPCI.O24    TPCI 6.25% 2017-2029                    10 000    9 700      SP      9 700                          208,33   S       312,50   30-sept.-26   ACD\nTPCI.O28    TPCI 6% 2018 - 2026                     10 000    9 200      SP      9 200                          598,36   A       600,00    1-août-26    ACD\nTPCI.O29    TPCI 6% 2018-2026                       10 000   10 000      SP     10 000                          468,49   A       600,00    19-oct.-26   ACD\nTPCI.O34    TPCI 6% 2019-2029                        3 750    3 675      NC      3 675                           81,99   A       225,00   20-mars-27    ACD\nTPCI.O36    TPCI 5,75% 2019- 2026                   10 000   10 000      SP     10 000                          541,92   A       575,00   21-août-26    ACD\nTPCI.O37    TPCI 5,80% 2019-2026                     2 000    2 011      SP      2 011                           75,96   A       116,00    4-déc.-26    ACD\nTPCI.O38    TPCI 5,75% 2019-2026                     2 000    1 990      SP      1 990                           75,30   A       115,00    4-déc.-26    ACD\nTPCI.O39    TPCI 5,75% 2019 - 2026                   2 000    2 000      SP      2 000                           90,42   A       115,00    17-oct.-26   ACD\nTPCI.O40    TPCI 5,75% 2019-2026                     2 000    2 000      NC      2 000                           63,64   A       115,00   10-janv.-27   ACD\nTPCI.O41    TPCI 5,80% 2020-2027                    10 000   10 000      NC     10 000                          243,12   A       580,00   28-févr.-27   ACD\nTPCI.O42    TPCI 5,90% 2020-2030                    10 000   10 000      NC     10 000                          247,32   A       590,00   28-févr.-27   ACD\nTPCI.O43    TPCI 5,90% 2020 - 2030                  10 000   10 000      NC     10 000                          160,03   A       590,00    23-avr.-27   ACD\nTPCI.O44    TPCI 5,80% 2020 - 2027                  10 000    9 800      NC      9 800                          157,32   A       580,00    23-avr.-27   ACD\nTPCI.O45    TPCI 5,80% 2020 -2027                    2 000    2 000      NC      2 000                           14,30   A       116,00    16-juin-27   ACD\nTPCI.O46    TPCI 5,90% 2020 - 2030                   5 000    5 100      NC      5 100                           36,37   A       295,00    16-juin-27   ACD\nTPCI.O47    TPCI 5,80% 2020 - 2027                   2 000    2 050      NC      2 050                           14,30   A       116,00    16-juin-27   ACD\nTPCI.O48    TPCI 5,80% 2020- 2027                    2 000    2 020      SP    2 019.60                           0,64   A       116,00   29-juil.-27   ACD\nTPCI.O49    TPCI 5,90% 2020- 2030                    5 000    5 043      SP    5 042.50                           1,62   A       295,00   29-juil.-27   ACD\nTPCI.O50    TPCI 5,90% 2020-2030                     6 250    6 250      SP      6 250                          340,46   A       368,75   28-août-26    ACD\nTPCI.O51    TPCI 5,80 % 2020-2027                    4 000    4 000      SP      4 000                          191,96   A       232,00     2-oct.-26   ACD\nTPCI.O52    TPCI 5,90 % 2020 -2030                   6 250    6 250      SP      6 250                          305,10   A       368,75     2-oct.-26   ACD\nTPCI.O53    TPCI 5,80% 2020-2027                     4 000    4 240      SP      4 240                          175,43   A       232,00    28-oct.-26   ACD\nTPCI.O54    TPCI 5,90% 2020-2030                     6 250    6 250      SP      6 250                          278,84   A       368,75    28-oct.-26   ACD\nTPCI.O55    TPCI 5,80% 2020 -2027                    4 000    4 000      SP      4 000                          138,56   A       232,00   25-déc.-26    ACD\nTPCI.O56    TPCI 5,90% 2020- 2030                    6 250    7 726      SP    7 726.25                         220,24   A       368,75   25-déc.-26    ACD\nTPCI.O57    TPCI 5,80% 2020-2027                     4 000    3 880      SP      3 880                          160,81   A       232,00    20-nov.-26   ACD\nTPCI.O58    TPCI 5,80% 2021-2028                     4 000    4 000      NC      4 000                           91,53   A       232,00    9-mars-27    ACD\nTPCI.O59    TPCI 5,90% 2021-2031                     6 250    6 250      NC      6 250                          145,48   A       368,75    9-mars-27    ACD\nTPCI.O60    TPCI 5,80% 2021-2028                    10 000   10 000      NC     10 000                          125,53   A       580,00    13-mai-27    ACD\nTPCI.O61    TPCI 5,90% 2021-2031                    10 000   10 000      NC     10 000                          127,70   A       590,00    13-mai-27    ACD\nTPCI.O62    TPCI 5,80 % 2021 -2028                   4 000    4 000      NC      4 000                           19,70   A       232,00    30-juin-27   ACD\nTPCI.O63    TPCI 5,90% 2021 -2031                    6 250    6 250      NC      6 250                           31,32   A       368,75    30-juin-27   ACD\nTPCI.O64    TPCI 5,80 % 2021 - 2028                  6 000    5 969      SP    5 968.80                         332,75   A       348,00   16-août-26    ACD\nTPCI.O65    TPCI 5,90 % 2021-2031                    7 500    7 500      SP      7 500                          368,55   A       442,50   30-sept.-26   ACD\nTPCI.O66    TPCI 5,90% 2021-2031                     7 500    7 300      SP    7 299.75                         324,90   A       442,50     5-nov.-26   ACD\n\n                                                                                          6\n                                                                                                                           vendredi 31 juillet 2026\nTPCI.O67   TPCI 5,90 % 2021-2036                  10 000   10 000      SP     10 000                          366,93   A       590,00   16-déc.-26    ACD\nTPCI.O68   TPCI 5,90 % 2022-2037                  10 000   10 000      NC     10 000                          290,96   A       590,00    1-févr.-27   ACD\nTPCI.O69   TPCI 5,75 % 2022-2037                  10 000   10 000      NC     10 000                          206,37   A       575,00   22-mars-27    ACD\nTPCI.O70   TPCI 5,75 % 2022-2037                  10 000   10 000      NC     10 000                          146,51   A       575,00    29-avr.-27   ACD\nTPCI.O71   TPCI 5,65% 2022-2032                   10 000   10 000      NC     10 000                           71,21   A       565,00   15-juin-27    ACD\nTPCI.O72   TPCI 5,65% 2022-2029                    6 000    6 000      SP      6 000                            1,86   A       339,00   29-juil.-27   ACD\nTPCI.O73   TPCI 5,75% 2022-2032                    7 500    7 500      SP      7 500                            2,36   A       431,25   29-juil.-27   ACD\nTPCI.O74   TPCI 5,85% 2022-2042                    8 421    8 421      SP    8 421.05                           2,70   A       492,63   29-juil.-27   ACD\nTPCI.O75   TPCI 5,65% 2022-2029                   10 000   10 700      SP     10 700                          459,74   A       565,00    7-oct.-26    ACD\nTPCI.O76   TPCI 5,75% 2022-2032                   10 000   10 500      SP     10 500                          467,88   A       575,00    7-oct.-26    ACD\nTPCI.O77   TPCI (TAUX DE BASE + SPREAD) %         10 000   10 000      NC     10 000                          131,40   T       149,25   11-août-26    ACD\n           2022-2029\nTPCI.O78   TPCI 5,65% 2022-2029                   10 000   10 000      SP     10 000                          356,03   A       565,00   13-déc.-26    ACD\nTPCI.O79   TPCI 5,75% 2023-2030                    8 000    8 600      NC      8 600                          192,82   A       460,00   28-févr.-27   ACD\nTPCI.O80   TPCI 6% 2023-2030                      10 000   10 000      NC     10 000                          123,29   A       600,00    17-mai-27    ACD\nTPCI.O81   TPCI 5,90% 2023-2028             (*)   10 000   10 500      SP     10 500                          590,00   A       590,00   31-juil.-26   ACD\nTPCI.O82   TPCI 6,00% 2023-2030             (*)   10 000    9 900      SP      9 900                          600,00   A       600,00   31-juil.-26   ACD\nTPCI.O83   TPCI 5,90 % 2023-2028                  10 000    9 900      SP      9 900                          472,00   A       590,00   12-oct.-26    ACD\nTPCI.O84   TPCI 6,00 % 2023-2030                  10 000   10 000      SP     10 000                          480,00   A       600,00   12-oct.-26    ACD\nTPCI.O85   TPCI 5,90 % 2023 - 2028                10 000   10 000      SP     10 000                          370,16   A       590,00   14-déc.-26    ACD\nTPCI.O86   TPCI 6,00 % 2023 - 2030                10 000   10 000      SP     10 000                          376,44   A       600,00   14-déc.-26    ACD\nTPCI.O87   TPCI 5,90% 2024-2029                   10 000    9 700   10 000    10 000        100   1 000 000   206,90   A       590,00   25-mars-27    ACD\nTPCI.O88   TPCI 6,00% 2024-2031                   10 000   10 000      NC     10 000                          210,41   A       600,00   25-mars-27    ACD\nTPCI.O89   TPCI 5,90% 2024-2029                   10 000   12 100      SP     12 100                           19,40   A       590,00   19-juil.-27   ACD\nTPCI.O90   TPCI 6,00% 2024-2031                   10 000   10 000      SP     10 000                           19,73   A       600,00   19-juil.-27   ACD\nTPCI.O91   TPCI 5,90% 2024-2029                   10 000   10 000      SP     10 000                          547,97   A       590,00   26-août-26    ACD\nTPCI.O92   TPCI 6,00% 2024-2031                   10 000    9 900      SP      9 900                          557,26   A       600,00   26-août-26    ACD\nTPCI.O93   TPCI 5,90% 2024-2029                   10 000    9 700      SP      9 700                          410,58   A       590,00   19-nov.-26    ACD\nTPCI.O94   TPCI 6,00% 2024-2031                   10 000    9 800      SP      9 800                          417,53   A       600,00   19-nov.-26    ACD\nTPCI.O95   TPCI 5,90% 2025-2030                   10 000   10 000      NC     10 000                          237,62   A       590,00    6-mars-27    ACD\nTPCI.O96   TPCI 6,00% 2025-2032                   10 000   10 000      NC     10 000                          241,64   A       600,00    6-mars-27    ACD\nTPCI.O97   TPCI 5,90% 2025-2030                   10 000   10 000      SP     10 000                          581,92   A       590,00    5-août-26    ACD\nTPCI.O98   TPCI 6,00% 2025-2032                   10 000   10 000      SP     10 000                          591,78   A       600,00    5-août-26    ACD\nTPCI.O99   TPCI 5,60% 2025-2030                   10 000   10 000      SP     10 000                          345,21   A       560,00   18-déc.-26    ACD\nTPNE.O10   TRESOR PUBLIC DU NIGER 6,60 %          10 000   10 000      NC     10 000                          289,32   A       660,00   21-févr.-27   ACD\n           2025-2030\nTPNE.O2    TRESOR PUBLIC DU NIGER 6,50%           10 000    9 900      SP      9 900                          648,22   A       650,00    1-août-26    ACD\n           2019 - 2026\nTPNE.O3    TRESOR PUBLIC DU NIGER 6,5%      (*)    4 000    3 892      SP      3 892                          260,00   A       260,00   31-juil.-26   ACD\n           2020-2027\nTPNE.O4    TRESOR PUBLIC DU NIGER 6,30% 2          7 500    7 500      SP      7 500                          441,43   A       472,50   24-août-26    ACD\nTPNE.O5    TPNE 6,15 % 2022-2034                  10 000   11 550      SP     11 550                           93,70   A       300,82    4-déc.-26    ACD\nTPNE.O6    TRESOR PUBLIC DU NIGER 6,15%            9 000    8 955      SP      8 955                          347,26   A       553,50   14-déc.-26    ACD\n           2022 - 2034\nTPNE.O7    TRESOR PUBLIC DU NIGER 6,25 %           5 000    5 000      SP      5 000                           32,53   A       312,50   23-juin-27    ACD\n           2023-2028\nTPNE.O8    TRESOR PUBLIC DU NIGER 6,60%           10 000    9 500      NC      9 500                          370,68   A       660,00    7-janv.-27   ACD\n           2025-2030\nTPNE.O9    TRESOR PUBLIC DU NIGER 6,90%           10 000               NC                                     387,53   A       690,00    7-janv.-27   ACD\n           2025-2032\nTPTG.O2    TPTG 6% 2022-2037                      10 000   10 000      NC     10 000                          422,47   A       600,00   16-nov.-26    ACD\nTPTG.O3    TPTG 6,45% 2025-2030                   10 000   10 000      NC     10 000                          259,77   A       645,00    6-mars-27    ACD\nTPTG.O4    TPTG 6,60% 2025-2032                   10 000   10 000      NC     10 000                          265,81   A       660,00    6-mars-27    ACD\nTPTG.O5    TPTG 6,50% 2026-2031                   10 000   10 000      NC     10 000                          324,11   A       650,00   30-janv.-27   ACD\nTPTG.O6    TPTG 6,70% 2026-2033                   10 000   10 000      NC     10 000                          334,08   A       670,00   30-janv.-27   ACD\nTOTAL                                                                                       780   7 817 700\n\n\nOBLIGATIONS D’INSTITUTIONS FINANCIÈRES RÉGIONALES ET INTERNATIONALES\nBIDC.O4    BIDC-EBID 6.10% 2017-2027               1 250    1 200      NC      1 200                            9,40   A        76,25   16-juin-27    ACD\nBIDC.O6    BIDC-EBID 6,50 % 2021-2028              4 000    4 618    4 400     4 400         12     52 800     52,71   S       131,07   18-nov.-26    ACD\nBIDC.O7    BIDC-EBID 5,90% 2022-2029               6 000    6 000      NC      6 000                           50,43   S       177,48    9-déc.-26    ACD\nCRRH.O10   CRRH-UEMOA 6,10% 2022-2037       (*)   10 000   10 000      NC     10 000                          299,94   S       305,00    3-août-26    AC\nCRRH.O6    CRRH-UEMOA 5.85% 2016-2026             10 000    9 800      NC      9 800                           44,51   S       292,50    3-janv.-27   ACD\nCRRH.O7    CRRH-UEMOA 5.95% 2017-2029              2 917    2 917      NC      2 917                           35,37   S        86,77   17-nov.-26    AC\nCRRH.O8    CRRH-UEMOA 5.95% 2018-2030              3 750    3 713      NC    3 712.50                          24,39   S       111,56   21-déc.-26    AC\nCRRH.O9    CRRH-UEMOA 6.05% 2018-2033              5 000    5 000      NC      5 000                           33,06   S       151,25   21-déc.-26    AC\nTOTAL                                                                                        12      52 800\n\n\n\n\n                                                                                        7\n                                                                                                                                              vendredi 31 juillet 2026\nOBLIGATIONS D’ENTREPRISES\nFDFINBF.O1 FIDELIS FINANCE CAP25 7,00%              6 250          6 250       NC         6 250                              81,13       S        207,32    20-nov.-26       ACD\n           2023-2028\nFDFINBF.O2 FIDELIS FINANCE PME ELAN                10 000       10 000         NC        10 000                             337,53       S        347,12     5-août-26       ACD\n           CROISSANCE UMOA 7% 2025-2030\nFDFINBF.O3 FIDELIS FINANCE PME ELAN                10 000       10 000         NC        10 000                             295,34       S        347,12    27-août-26       ACD\n           CROISSANCE UMOA 2 - 7% 2025-\n           2030\nNRMC.O1     NOURMONY HOLDING 7,25% 2024-           10 000       10 000         NC        10 000                             301,72       S        352,33    26-août-26       ACD\n            2029\nORGT.O2     ORAGROUP SA 7,15% 2021-2028             5 000          5 900       NC      5 899.50                              93,77       S        178,75    26-oct.-26       ACD\n\nPADS.O3     PAD 6,60% 2020-2027                     3 000          2 970       NC         2 970                              55,72       S         99,00    19-oct.-26       ACD\n\nPTRC.O1     PETRO IVOIRE 6,80% 2022-2029           10 000       12 800      12 900       12 900        100    1 289 991      83,76       S        333,20    15-déc.-26       ACD\n\nSNTS.O2     SONATEL 6,50% 2020-2027                 2 000          2 000       NC         2 000                               5,02       S         61,60    16-janv.-27      ACD\n\nTOTAL                                                                                                  100    1 289 991\n\n\nTOTAL - OBLIGATIONS CLASSIQUES                                                                         892     9 160 491\n\n\n\n\n2. OBLIGATIONS VERTES, SOCIALEMENT RESPONSABLES ET DURABLES (GSS)\n                                               Valeur         Cours   Cours du         Cours       Séance de cotation      Coupon            Coupon d\'intérêt\n Symbole                   Titre                                                                                                                                          Type Amort\n                                              nominale      Précédent   jour         Référence      Volume    Valeur        couru     Période Montant net Eché.\nOBLIGATIONS GSS SOUVERAINES\n\nTOTAL\n\n\nOBLIGATIONS GSS D’INSTITUTIONS FINANCIÈRES RÉGIONALES ET INTERNATIONALES\nBIDC.O8     BIDC-EBID GSS BOND 6,50% 2024-         10 000          10 000       NC       10 000                                3,56      S         327,67   29-janv.-27      ACD\n            2031\nCRRH.O11    SOCIAL BOND CRRH-UEMOA 6,00%           10 000          10 000       NC       10 000                               93,70      S         300,82     4-déc.-26      ACD\n            2025-2040\nTOTAL\n\nOBLIGATIONS GSS D’ENTREPRISES\nBABS.O1     GSS BAOBAB 6,80% 2024-2029             10 000          10 000       NC       10 000                              171,62      S         320,48    24-oct.-26      ACD\n\nECOC.O1     GENDER BOND ECOBANK CI 6,50%           10 000          10 000       NC       10 000                              246,07      A         637,00   12-mars-27       ACD\n            2024-2029\nTOTAL\n\nTOTAL - OBLIGATIONS VERTES, SOCIALEMENT RESPONSABLES ET DURABLES\n\n\n\n\n3. FONDS COMMUNS DE TITRISATION DE CREANCES (FCTC)\n                                               Valeur         Cours   Cours du         Cours       Séance de cotation      Coupon            Coupon d\'intérêt\n Symbole                   Titre                                                                                                                                          Type Amort\n                                              nominale      Précédent   jour         Référence      Volume    Valeur        couru     Période Montant net Eché.\nFCTC D’ÉTAT ET D’INSTITUTIONS À PARTICIPATION MAJORITAIRE PUBLIQUE\n\nFEPTC.O1    FCTC EPT 7 % 2023–2030                  7 500           7 500       NC        7 500                               90,69      T         128,36   27-août-26       ACD\nFEPTC.O2    FCTC EPT 7,25 % 2023-2033               9 375           9 375       NC        9 375                              117,65      T         166,52   27-août-26       ACD\nFEPTC.O3    FCTC EPT 7,50% 2023-2038                9 615                       NC                                           124,83      T         176,68   27-août-26       ACD\nFEPTC.O4    FCTC EPT 7,5% 2025-2032                10 000          10 000       NC       10 000                               13,84      T         181,88    24-oct.-26      ACD\nFEPTC.O5    FCTC EPT 8% 2025-2035                  10 000                       NC                                            14,76      T         194,00    24-oct.-26      ACD\nFEPTC.O6    FCTC EPT 8,5% 2025-2040                10 000                       NC                                            15,68      T         206,13    24-oct.-26      ACD\nTOTAL\n\n\nFCTC D’INSTITUTIONS FINANCIÈRES RÉGIONALES ET INTERNATIONALES\nFBOAD.O1    FCTC BOAD DOLI-P 6,10% 2023-            4 600           4 600    4 600        4 600          3       13 800      102,24      S         142,22   21-sept.-26       AC\n            2030\nFBOAD.O2    FCTC BOAD DOLI-P 9,50% 2024-            5 500           6 238       NC      6 237.55                             103,07      S         263,40    20-nov.-26       AC\n            2029\nTOTAL                                                                                                    3       13 800\n\n\n\n\n                                                                                                   8\n                                                                                                                                                                                   vendredi 31 juillet 2026\nFCTC D’ENTREPRISES (CLASSIQUES)\nFCAGS.O1       FCTC CROISSANCE AGRICOLE 8%                              9 286             9 286           NC             9 286                                    174,57      S         349,14    31-oct.-26       AC\n               2025-2032\nFCAGS.O2       FCTC CROISSANCE AGRICOLE 9%                              9 286             9 286           NC             9 286                                    196,40      S         392,79    31-oct.-26       AC\n               2025-2032\nFCAS.O1        FCTC CROISSANCE ATLANTIQUE                               6 365             6 365           NC           6 364.67                                    25,48      T          92,73     5-oct.-26       AC\n               6,20% 2024-2029\nFNSBB.O1       FCTC KEUR SAMBA NSIA BANQUE                              7 600             7 600           NC             7 600                                     59,76      S         266,73   20-déc.-26        AC\n               7% 2025-2030\nFNSBB.O2       FCTC KEUR SAMBA NSIA BANQUE                              7 600             7 600           NC             7 600                                     76,83      S         342,94   20-déc.-26        AC\n               9% 2025-2030\nFNSBC.O2       FCTC KEUR SAMBA NSIA BANQUE CI                           7 619             7 619           NC           7 619.05                                   126,58      T         565,00   20-déc.-26        AC\n               7% 2025-2030\nFNSBC.O3       FCTC NSIA BANQUE CI 7,50% 2025-                          9 444             9 444           NC           9 444.44                                   156,83      T         171,77    8-août-26       ACD\n               2030\nFNSBC.O4       FCTC ZAKA RMBS NSIA BANQUE CI                            9 130             9 130           NC             9 130                                     71,79      S         320,43   20-déc.-26        AC\n               7,00% 2025-2036\nFNSBC.O5       FCTC ZAKA RMBS NSIA BANQUE CI                            9 130             9 130           NC             9 130                                     92,30      S         411,98   20-déc.-26        AC\n               9,00% 2025-2036\nFORBC.O1       FCTC KEUR SAMBA ORABANK CI 7%                            7 619             7 619           NC           7 619.05                                    27,93      T         124,66   20-déc.-26        AC\n               2025-2030\nFORBT.O1       FCTC ORABANK 7 % 2021-2026                               1 111             1 378           NC           1 377.76                                    38,83      T          28,58    28-juin-26      ACD\n\nFSNBBF.O1      FCTC SONABHY 8,10 % 2025-2031                           10 000           10 000            NC            10 000                                    262,77      S         380,70   26-sept.-26      ACD\n\nFSNTS.O1       FCTC SONATEL C-1 6,40 % 2023-2                          10 000             9 900           NC             9 900                                     19,62      S         300,80   19-janv.-27       AC\n\nFSNTS.O2       FCTC SONATEL C-2 6,60 % 2023-2                          10 000             9 950           NC             9 950                                     20,23      S         310,20   19-janv.-27       AC\n\nFTIMC.O1       FCTC TEYLIOM IMMO 7 % 2021-                              3 333             3 333           NC           3 333.33                                    32,93      T          57,17    8-sept.-26      ACD\n               2028\nTOTAL\n\n\nFCTC GSS D’ENTREPRISES\nFSNLC.O1       FCTC SENELEC SENIOR 8,15 % 2025                          9 500             9 486        9 975             9 975          100         997 500       169,70      S         390,31    12-nov.-26       AC\n               -2030\nFSNLC.O2       FCTC SENELEC MEZZANINE 10 %                              9 500           10 213         9 500             9 500           34         325 138       208,22      S         478,90    12-nov.-26       AC\n               2025-2030\nTOTAL                                                                                                                                   134        1 322 638\n\n\nTOTAL - FONDS COMMUNS DE TITRISATION DE CREANCES                                                                                        137        1 336 438\n\n\n\n\n4. SUKUK ET TITRES ASSIMILES\n                                                                 Valeur            Cours   Cours du               Cours           Séance de cotation            Coupon            Coupon d\'intérêt\n Symbole                          Titre                                                                                                                                                                        Type Amort\n                                                                nominale         Précédent   jour               Référence          Volume    Valeur              couru     Période Montant net Eché.\nSUKUK D’ÉTAT ET D’INSTITUTIONS À PARTICIPATION MAJORITAIRE PUBLIQUE\n\nSUKTG.S1       SUKUK TG 6.5% 2016-2026                                 10 000           10 300            NC            10 300                                    336,94      S         371,86   17-août-26       ACD\nTOTAL\n\n\nSUKUK D’INSTITUTIONS FINANCIÈRES RÉGIONALES ET INTERNATIONALES\nTOTAL\n\nSUKUK D\'ENTREPRISES\nTOTAL\n\nTOTAL - SUKUK ET TITRES ASSIMILES\n\n\n\n\n5. OBLIGATIONS CONVERTIBLES EN ACTIONS\n                                                                 Valeur            Cours   Cours du               Cours           Séance de cotation            Coupon            Coupon d\'intérêt\n Symbole                          Titre                                                                                                                                                                        Type Amort\n                                                                nominale         Précédent   jour               Référence          Volume    Valeur              couru     Période Montant net Eché.\n\nSCRC.O1        SUCRIVOIRE SA 8,55 % 2024-2031                          10 000             9 700       10 000            10 000          100       1 000 000       243,12      A         829,35    15-avr.-27      ACD\nTOTAL                                                                                                                                   100        1 000 000\n\nTOTAL - OBLIGATIONS CONVERTIBLES EN ACTIONS                                                                                             100        1 000 000\n\n\n\nTOTAL - MARCHE DES OBLIGATIONS                                                                                                         1 129      11 496 928\n\nLégende :\n(##) (*) Ex-Coupon couru (##)Cours de référence amorti / Coupon couru Ex-c (#)Ex-marge de profits\nCours du jour:      Ouv.: Ouverture         Clôt.: Clôture         Moy.: Moyen\nType Amort: Type d\'Amortissement         IF: In Fine       AC: Amortissement Constant    AD: Amortissement Dégressif      ACD: Amortissement Constant Différé\nPériode: Périodicité de paiement des coupons          A: Annuelle        S: Semestrielle     T: Trimestrielle\nEché.: Échéance de paiement des intérêts\nMentions: NC: Non Coté      Ex-c: Ex-coupon ou Ex-droit    SP: Suspendu\n\n\n\n\n                                                                                                                                  9\n                                                                                                                         vendredi 31 juillet 2026\n\n\n\n\nOPERATIONS EN COURS\nEmetteur                             Opération\n\nNESTLE CI                            Paiement de dividendes de 420 F CFA par action (IRVM applicable de 12 % pour les personnes physiques et 10 % pour les\n                                     personnes morales), le 07/09/2026\nCFAO MOTORS CI                       Paiement de dividendes de 63 F CFA par action (IRVM applicable de 12 % pour les personnes physiques et 10 % pour les\n                                     personnes morales), le 14/08/2026\nSITAB                                Paiement de dividendes de 1 707,2 F CFA net par action, le 13/08/2026\n\nSAFCA CI                             Admission à la cote des actions nouvelles de la société SAFCA CI, le 11 août 2026\n\nSOGB                                 Paiement de dividendes de 570 F CFA par action (IRVM applicable de 12 % pour les personnes physiques et 10 % pour les\n                                     personnes morales), le 06/08/2026\nNSBC                                 Paiement de dividendes de 768,16 F CFA par action (IRVM applicable de 12 % pour les personnes physiques et 10 % pour les\n                                     personnes morales), le 04/08/2026\nLNB                                  Paiement de dividendes de 164,1709 F CFA net par action, le 03/08/2026\n\nBIIC                                 Paiement de dividendes de 254,6 F CFA net par action, le 31/07/2026\n\nSIB                                  Paiement de dividendes de 425 F CFA par action (IRVM applicable de 12 % pour les personnes physiques et 10 % pour les\n                                     personnes morales), le 31/07/2026\n\n\nAVIS\nEntité           N°              Nature                                    Objet\n\n\nCOMMUNIQUES\nEmetteur              N°             Nature                                 Objet\n\nLNB                   20260731       Rapport d\'activités du 1er semestre    Rapport d\'activités et rapport d\'examen limité des Commissaires Aux Comptes- 1er\n                                                                            semestre 2026 - LNB BN\n\n\n\n\n                                                                               10\n                                                                                                                 vendredi 31 juillet 2026\n\n\n\n\nMARCHE DES ACTIONS\n                                                    Quantité                       Cours                Quantité\n        Symbole                            Titre   résiduelle à                 Achat / Vente        résiduelle à la         Cours de Référence\n                                                      l\'achat                                             vente\nABJC              SERVAIR ABIDJAN CI                                10    2,975      /      3,000                 1 794                           3 000\n\nBICB              BIIC BN                                          190    7,585      /      7,590                      100                        7 585\n\nBICC              BICI CI                                           80    28,670     /     29,015                       34                    28 670\n\nBNBC              BERNABE CI                                       111    1,880      /      1,895                       40                        1 890\n\nBOAB              BANK OF AFRICA BN                                 54    8,695      /      8,700                 2 771                           8 700\n\nBOABF             BANK OF AFRICA BF                                 70    7,085      /      7,100                       12                        7 100\n\nBOAC              BANK OF AFRICA CI                                 66    10,815     /     11,195                        1                    10 815\n\nBOAM              BANK OF AFRICA ML                                353    5,660      /      5,665                       13                        5 665\n\nBOAN              BANK OF AFRICA NG                                 21    5,215      /      5,290                      840                        5 290\n\nBOAS              BANK OF AFRICA SENEGAL                             3    7,785      /      7,790                      180                        7 790\n\nCABC              SICABLE CI                                         1    3,495      /      3,500                      627                        3 500\n\nCBIBF             CORIS BANK INTERNATIONAL                          10    27,995     /     28,000                       91                    28 000\n\nCFAC              CFAO MOTORS CI                                    23    1,660      /      1,665                       65                        1 665\n\nCIEC              CIE CI                                          1 198   5,000      /      5,175                 3 285                           5 000\n\nECOC              ECOBANK COTE D\'\'IVOIRE                           357    16,100     /     16,230                 2 892                       16 230\n\nETIT              ECOBANK TRANS. INCORP. TG                   18 558       66        /          70              203 666                             66\n\nFTSC              FILTISAC CI                                       58    1,960      /      1,970                      777                        1 970\n\nLNBB              LOTERIE NATIONALE DU BENIN                        10    4,350      /      4,500                      496                        4 500\n\nNEIC              NEI-CEDA CI                                        2    2,255      /      2,280                       91                        2 280\n\nNSBC              NSIA BANQUE COTE D\'IVOIRE                          5    23,990     /     24,000                      721                    24 000\n\nNTLC              NESTLE CI                                          5    16,500     /     16,580                       38                    16 500\n\nONTBF             ONATEL BF                                         15    2,930      /      2,995                      164                        2 995\n\nORAC              ORANGE COTE D\'IVOIRE                               1    16,800     /     16,995                      237                    16 995\n\nORGT              ORAGROUP TOGO                                     75    3,130      /      3,140                       12                        3 140\n\nPALC              PALM CI                                           92    8,650      /      9,000                        5                        9 000\n\nPRSC              TRACTAFRIC MOTORS CI                              28    4,495      /      4,515                       96                        4 515\n\nSAFC              SAFCA CI                                          26    5,105      /      5,130                      262                        5 130\n\nSCRC              SUCRIVOIRE                                       200    3,620      /      3,645                       47                        3 645\n\nSDCC              SODE CI                                            1    11,900     /     11,920                       97                    11 900\n\nSDSC              AFRICA GLOBAL LOGISTICS CI                        95    2,690      /      2,700                      164                        2 690\n\nSEMC              EVIOSYS PACKAGING SIEM CI                        110    1,485      /      1,500                      667                        1 485\n\nSGBC              SOCIETE GENERALE COTE D\'IVOIRE                    14    37,995     /     38,000                      188                    38 000\n\nSHEC              VIVO ENERGY CI                                     3    2,195      /      2,200                      331                        2 200\n\nSIBC              SOCIETE IVOIRIENNE DE BANQUE                      15    8,900      /      9,000                 2 754                           9 000\n\nSICC              SICOR CI                                          67    5,975      /      6,400                       50                        6 400\n\nSIVC              ERIUM CI (Ex AIR LIQUIDE CI)                     432    2,195      /      2,230                 2 033                           2 200\n\nSLBC              SOLIBRA CI                                         2    37,750     /     38,900                        1                    37 750\n\nSMBC              SMB CI                                           958    15,495     /     15,500                10 842                       15 495\n\nSNTS              SONATEL SN                                         2    30,800     /     31,000                 1 609                       31 000\n\nSOGC              SOGB CI                                           99    8,305      /      8,395                      100                        8 305\n\nSPHC              SAPH CI                                           96    7,560      /      7,565                       14                        7 565\n\nSTAC              SETAO CI                                          60    2,655      /      2,690                      194                        2 680\n\nSTBC              SITAB CI                                          68    23,200     /     24,000                      574                    23 200\n\nTTLC              TOTALENERGIES MARKETING CI                         3    2,970      /      2,975                 4 843                           2 975\n\nTTLS              TOTALENERGIES MARKETING SN                       133    3,625      /      3,655                      561                        3 655\n\nUNLC              UNILEVER CI                                       11    51,000     /     54,000                       11                    51 000\n\nUNXC              UNIWAX CI                                         85    1,915      /      1,945                      233                        1 945\n\n\n\n\n                                                                           11\n                                                                            vendredi 31 juillet 2026\n\n\n\n\nMARCHE DES DROITS\n                             Quantité              Cours           Quantité\n  Symbole           Titre   résiduelle à        Achat / Vente   résiduelle à la       Cours de Référence\n                               l\'achat                               vente\n\n\n\n\n                                           12\n                                                                                                                             vendredi 31 juillet 2026\n\n\n\n\nMARCHE DES OBLIGATIONS\n     Symbole                             Titre                  Quantité                      Cours                 Quantité             Cours de Référence\n                                                               résiduelle à                Achat / Vente         résiduelle à la\n                                                                  l\'achat                                             vente\nFBOAD.O1       FCTC BOAD DOLI-P 6,10% 2023-2030                                                 /      4,600                        16                        4 600\n\nFBOAD.O2       FCTC BOAD DOLI-P 9,50% 2024-2029                               100    6,215      /                                                             6 238\n\nFCAGS.O1       FCTC CROISSANCE AGRICOLE 8% 2025-2032                                            /                                                             9 286\n\nFCAGS.O2       FCTC CROISSANCE AGRICOLE 9% 2025-2032                            9    9,286      /                                                             9 286\n\nFCAS.O1        FCTC CROISSANCE ATLANTIQUE 6,20% 2024-2029                                       /                                                             6 365\n\nFEPTC.O1       FCTC EPT 7 % 2023–2030                                                           /                                                             7 500\n\nFTIMC.O1       FCTC TEYLIOM IMMO 7 % 2021-2028                                  4   3,333.33    /                                                             3 333\n\nFORBT.O1       FCTC ORABANK 7 % 2021-2026                                                       /                                                             1 378\n\nFSNBBF.O1      FCTC SONABHY 8,10 % 2025-2031                                   24   10,000      /                                                         10 000\n\nFSNLC.O1       FCTC SENELEC SENIOR 8,15 % 2025-2030                           100   9,357.50    /                                                             9 975\n\nFSNLC.O2       FCTC SENELEC MEZZANINE 10 % 2025-2030                                            /      9,500                       393                        9 500\n\nFSNTS.O1       FCTC SONATEL C-1 6,40 % 2023-2                                                   /                                                             9 900\n\nFSNTS.O2       FCTC SONATEL C-2 6,60 % 2023-2                                                   /                                                             9 950\n\nFNSBB.O2       FCTC KEUR SAMBA NSIA BANQUE 9% 2025-2030                                         /                                                             7 600\n\nFNSBC.O2       FCTC KEUR SAMBA NSIA BANQUE CI 7% 2025-2030                                      /                                                             7 619\n\nFNSBC.O3       FCTC NSIA BANQUE CI 7,50% 2025-2030                                              /     9,444.44                       2                        9 444\n\nFNSBC.O4       FCTC ZAKA RMBS NSIA BANQUE CI 7,00% 2025-2036                                    /                                                             9 130\n\nFNSBC.O5       FCTC ZAKA RMBS NSIA BANQUE CI 9,00% 2025-2036                                    /                                                             9 130\n\nFORBC.O1       FCTC KEUR SAMBA ORABANK CI 7% 2025-2030                                          /                                                             7 619\n\nFEPTC.O2       FCTC EPT 7,25 % 2023-2033                                                        /                                                             9 375\n\nFEPTC.O3       FCTC EPT 7,50% 2023-2038                                        20   Marché      /                                                             9 615\n\nFEPTC.O4       FCTC EPT 7,5% 2025-2032                                                          /                                                         10 000\n\nFEPTC.O5       FCTC EPT 8% 2025-2035                                                            /                                                         10 000\n\nFEPTC.O6       FCTC EPT 8,5% 2025-2040                                                          /                                                         10 000\n\nFNSBB.O1       FCTC KEUR SAMBA NSIA BANQUE 7% 2025-2030                                         /                                                             7 600\n\nBIDC.O4        BIDC-EBID 6.10% 2017-2027                                                        /                                                             1 200\n\nBIDC.O6        BIDC-EBID 6,50 % 2021-2028                                                       /      4,400                        13                        4 400\n\nBIDC.O7        BIDC-EBID 5,90% 2022-2029                                                        /      6,000                         5                        6 000\n\nCRRH.O10       CRRH-UEMOA 6,10% 2022-2037                                                       /                                                         10 000\n\nCRRH.O6        CRRH-UEMOA 5.85% 2016-2026                                                       /                                                             9 800\n\nCRRH.O7        CRRH-UEMOA 5.95% 2017-2029                                                       /                                                             2 917\n\nTPTG.O2        TPTG 6% 2022-2037                                                                /                                                         10 000\n\nTPTG.O3        TPTG 6,45% 2025-2030                                                             /                                                         10 000\n\nTPTG.O4        TPTG 6,60% 2025-2032                                                             /                                                         10 000\n\nTPTG.O5        TPTG 6,50% 2026-2031                                                             /     10,000                       139                    10 000\n\nTPTG.O6        TPTG 6,70% 2026-2033                                                             /                                                         10 000\n\nTPNE.O4        TRESOR PUBLIC DU NIGER 6,30% 2                                                   /                                                             7 500\n\nTPNE.O5        TPNE 6,15 % 2022-2034                                                            /                                                         11 550\n\nTPNE.O6        TRESOR PUBLIC DU NIGER 6,15% 2022 - 2034                                         /                                                             8 955\n\nTPNE.O7        TRESOR PUBLIC DU NIGER 6,25 % 2023-2028                                          /                                                             5 000\n\nTPNE.O8        TRESOR PUBLIC DU NIGER 6,60% 2025-2030                                           /     10,000                        24                        9 500\n\nTPNE.O9        TRESOR PUBLIC DU NIGER 6,90% 2025-2032                                           /                                                         10 000\n\nTPCI.O96       TPCI 6,00% 2025-2032                                                             /                                                         10 000\n\nTPCI.O97       TPCI 5,90% 2025-2030                                                             /                                                         10 000\n\nTPCI.O98       TPCI 6,00% 2025-2032                                                             /                                                         10 000\n\nTPCI.O99       TPCI 5,60% 2025-2030                                                             /                                                         10 000\n\nTPNE.O10       TRESOR PUBLIC DU NIGER 6,60 % 2025-2030                                          /                                                         10 000\n\nTPNE.O3        TRESOR PUBLIC DU NIGER 6,5% 2020-2027                                            /                                                             3 892\n\nTPCI.O90       TPCI 6,00% 2024-2031                                                             /                                                         10 000\n\nTPCI.O91       TPCI 5,90% 2024-2029                                                             /                                                         10 000\n\n                                                                                      13\n                                                                                 vendredi 31 juillet 2026\nTPCI.O92   TPCI 6,00% 2024-2031                                     /                                        9 900\n\nTPCI.O93   TPCI 5,90% 2024-2029                                     /                                        9 700\n\nTPCI.O94   TPCI 6,00% 2024-2031                                     /                                        9 800\n\nTPCI.O95   TPCI 5,90% 2025-2030                                     /                                       10 000\n\nTPCI.O84   TPCI 6,00 % 2023-2030                                    /                                       10 000\n\nTPCI.O85   TPCI 5,90 % 2023 - 2028                                  /                                       10 000\n\nTPCI.O86   TPCI 6,00 % 2023 - 2030                                  /                                       10 000\n\nTPCI.O87   TPCI 5,90% 2024-2029                                     /   9,820    45 514                     10 000\n\nTPCI.O88   TPCI 6,00% 2024-2031                                     /   9,650    98 596                     10 000\n\nTPCI.O89   TPCI 5,90% 2024-2029                                     /                                       12 100\n\nTPCI.O78   TPCI 5,65% 2022-2029                                     /                                       10 000\n\nTPCI.O79   TPCI 5,75% 2023-2030                                     /                                        8 600\n\nTPCI.O80   TPCI 6% 2023-2030                          200   9,800   /   10,000    2 490                     10 000\n\nTPCI.O81   TPCI 5,90% 2023-2028                                     /                                       10 500\n\nTPCI.O82   TPCI 6,00% 2023-2030                                     /                                        9 900\n\nTPCI.O83   TPCI 5,90 % 2023-2028                                    /                                        9 900\n\nTPCI.O72   TPCI 5,65% 2022-2029                                     /                                        6 000\n\nTPCI.O73   TPCI 5,75% 2022-2032                                     /                                        7 500\n\nTPCI.O74   TPCI 5,85% 2022-2042                                     /                                        8 421\n\nTPCI.O75   TPCI 5,65% 2022-2029                                     /                                       10 700\n\nTPCI.O76   TPCI 5,75% 2022-2032                                     /                                       10 500\n\nTPCI.O77   TPCI (TAUX DE BASE + SPREAD) % 2022-2029                 /                                       10 000\n\nTPCI.O66   TPCI 5,90% 2021-2031                                     /                                        7 300\n\nTPCI.O67   TPCI 5,90 % 2021-2036                                    /                                       10 000\n\nTPCI.O68   TPCI 5,90 % 2022-2037                                    /                                       10 000\n\nTPCI.O69   TPCI 5,75 % 2022-2037                                    /   10,000      99                      10 000\n\nTPCI.O70   TPCI 5,75 % 2022-2037                                    /                                       10 000\n\nTPCI.O71   TPCI 5,65% 2022-2032                                     /                                       10 000\n\nTPCI.O60   TPCI 5,80% 2021-2028                                     /                                       10 000\n\nTPCI.O61   TPCI 5,90% 2021-2031                                     /                                       10 000\n\nTPCI.O62   TPCI 5,80 % 2021 -2028                                   /                                        4 000\n\nTPCI.O63   TPCI 5,90% 2021 -2031                                    /                                        6 250\n\nTPCI.O64   TPCI 5,80 % 2021 - 2028                                  /                                        5 969\n\nTPCI.O65   TPCI 5,90 % 2021-2031                                    /                                        7 500\n\nTPCI.O54   TPCI 5,90% 2020-2030                                     /                                        6 250\n\nTPCI.O55   TPCI 5,80% 2020 -2027                                    /                                        4 000\n\nTPCI.O56   TPCI 5,90% 2020- 2030                                    /                                        7 726\n\nTPCI.O57   TPCI 5,80% 2020-2027                                     /                                        3 880\n\nTPCI.O58   TPCI 5,80% 2021-2028                                     /                                        4 000\n\nTPCI.O59   TPCI 5,90% 2021-2031                                     /                                        6 250\n\nTPCI.O48   TPCI 5,80% 2020- 2027                                    /                                        2 020\n\nTPCI.O49   TPCI 5,90% 2020- 2030                                    /                                        5 043\n\nTPCI.O50   TPCI 5,90% 2020-2030                                     /                                        6 250\n\nTPCI.O51   TPCI 5,80 % 2020-2027                                    /                                        4 000\n\nTPCI.O52   TPCI 5,90 % 2020 -2030                                   /                                        6 250\n\nTPCI.O53   TPCI 5,80% 2020-2027                                     /                                        4 240\n\nTPCI.O42   TPCI 5,90% 2020-2030                                     /                                       10 000\n\nTPCI.O43   TPCI 5,90% 2020 - 2030                                   /                                       10 000\n\nTPCI.O44   TPCI 5,80% 2020 - 2027                                   /                                        9 800\n\nTPCI.O45   TPCI 5,80% 2020 -2027                                    /                                        2 000\n\nTPCI.O46   TPCI 5,90% 2020 - 2030                                   /                                        5 100\n\nTPCI.O47   TPCI 5,80% 2020 - 2027                                   /                                        2 050\n\nTPCI.O36   TPCI 5,75% 2019- 2026                                    /                                       10 000\n\nTPCI.O37   TPCI 5,80% 2019-2026                                     /                                        2 011\n\nTPCI.O38   TPCI 5,75% 2019-2026                                     /                                        1 990\n\n\n                                                            14\n                                                                                               vendredi 31 juillet 2026\nTPCI.O39     TPCI 5,75% 2019 - 2026                                               /                                        2 000\n\nTPCI.O40     TPCI 5,75% 2019-2026                                                 /                                        2 000\n\nTPCI.O41     TPCI 5,80% 2020-2027                                                 /                                       10 000\n\nTPCI.O102    TPCI 6,00% 2026-2036                                                 /                                       10 000\n\nTPCI.O21     TPCI 6% 2016-2028                                     1   10,000     /                                       10 000\n\nTPCI.O23     TPCI 5.90% 2016-2026                                                 /                                       10 000\n\nTPCI.O24     TPCI 6.25% 2017-2029                                                 /                                        9 700\n\nTPCI.O29     TPCI 6% 2018-2026                                                    /                                       10 000\n\nTPCI.O34     TPCI 6% 2019-2029                                                    /                                        3 675\n\nTPBJ.O6      TPBJ 5,75% 2022-2037                                                 /   10,100      23                      10 100\n\nTPBJ.O7      TPBJ 5,70% 2023-2030                                                 /                                       10 000\n\nTPBJ.O8      TPBJ 6,20% 2023-2038                                                 /   9,800        7                      10 000\n\nTPBJ.O9      TPBJ 6,00% 2025-2032                                                 /                                        9 980\n\nTPCI.O100    TPCI 5,85% 2025-2032                                                 /                                       10 000\n\nTPCI.O101    TPCI 5,85% 2026-2033                                                 /                                       10 000\n\nTPBF.O8      TPBF 6,50% 2019-2027                                153    9,650     /                                        9 650\n\nTPBF.O9      TPBF 6,50% 2019 - 2027                                               /                                       11 499\n\nTPBJ.O10     TPBJ 6,15% 2025-2035                                                 /                                       10 000\n\nTPBJ.O3      TPBJ 6,50% 2020-2028                                                 /   10,000      22                      10 000\n\nTPBJ.O4      TPBJ 5,50 % 2022-2037                                                /                                       10 000\n\nTPBJ.O5      TPBJ 5,85 % 2022-2042                                                /   10,000      16                      10 000\n\nTPBF.O21     ETAT DU BURKINA 6,50% 2024-2029                                      /                                       10 000\n\nTPBF.O22     ETAT DU BURKINA 6,80% 2024-2032                                      /                                       10 000\n\nTPBF.O23     ETAT DU BURKINA 6,60% 2025-2030                                      /                                       10 300\n\nTPBF.O24     ETAT DU BURKINA 6,80% 2025-2032                                      /                                        9 158\n\nTPBF.O25     ETAT DU BURKINA 7% 2025-2035                                         /   9,999        2                       9 999\n\nTPBF.O4      TPBF 6.50% 2017-2027                                                 /   10,300       1                      10 300\n\nTPBF.O15     ETAT DU BURKINA 6,30 % 2022-2                                        /                                        9 999\n\nTPBF.O16     ETAT DU BURKINA 6% 2023-2028                          1    9,600     /                                        9 600\n\nTPBF.O17     TPBF 6,30 % 2023-2030                                                /   10,000     135                      10 000\n\nTPBF.O18     TPBF 6,50% 2023-2033                                                 /   10,000     161                      10 000\n\nTPBF.O19     ETAT DU BURKINA 6,30% 2024-2029                      29   10,000     /                                       10 000\n\nTPBF.O20     ETAT DU BURKINA 6,55% 2024-2031                                      /                                       10 000\n\nSNTS.O2      SONATEL 6,50% 2020-2027                                              /   2,000      153                       2 000\n\nTPBF.O10     TPBF 6,50% 2020 - 2028                               10   3,216.69   /                                        3 333\n\nTPBF.O11     TPBF 6,50 % 2020-2028                                                /                                        3 302\n\nTPBF.O12     TPBF 6.50% 2021-2031                                                 /                                        9 990\n\nTPBF.O13     TPBF 6,50 % 2021 - 2031                                              /                                        9 600\n\nTPBF.O14     TPBF 6,30% 2022-2034                                                 /                                        9 993\n\nFDFINBF.O2   FIDELIS FINANCE PME ELAN CROISSANCE UMOA 7% 2025-                    /                                       10 000\n             2030\nFDFINBF.O3   FIDELIS FINANCE PME ELAN CROISSANCE UMOA 2 - 7%                      /   10,000      30                      10 000\n             2025-2030\nNRMC.O1      NOURMONY HOLDING 7,25% 2024-2029                                     /                                       10 000\n\nORGT.O2      ORAGROUP SA 7,15% 2021-2028                           2   5,899.50   /   5,900    5 000                       5 900\n\nPADS.O3      PAD 6,60% 2020-2027                                                  /                                        2 970\n\nPTRC.O1      PETRO IVOIRE 6,80% 2022-2029                                         /   12,900      19                      12 900\n\nEOS.O31      ETAT DU SENEGAL 6,60% 2025-2030                                      /                                       10 000\n\nEOS.O32      ETAT DU SENEGAL 6,75% 2025-2032                                      /                                        9 600\n\nEOS.O33      ETAT DU SENEGAL 6,95% 2025-2035                                      /   10,000      74                      10 000\n\nEOS.O8       ETAT DU SENEGAL 5,95% 2022-2034                                      /                                       10 000\n\nEOS.O9       ETAT DU SENEGAL 6,00 % 2023-20                                       /                                        9 800\n\nFDFINBF.O1   FIDELIS FINANCE CAP25 7,00% 2023-2028                 4    6,250     /                                        6 250\n\nEOS.O25      ETAT DU SENEGAL 6,95% 2025-2035                                      /                                       10 000\n\nEOS.O26      ETAT DU SENEGAL 6,40% 2025-2028                                      /   9,700        2                       9 720\n\nEOS.O27      ETAT DU SENEGAL 6,60% 2025-2030                                      /                                        9 400\n\nEOS.O28      ETAT DU SENEGAL 6,75% 2025-2032                                      /                                        9 995\n\n                                                                         15\n                                                                                                                    vendredi 31 juillet 2026\nEOS.O29        ETAT DU SENEGAL 6,95% 2025-2035                                          /      10,000                     162                         9 998\n\nEOS.O30        ETAT DU SENEGAL 6,40% 2025-2028                                          /      9,900                       20                         9 950\n\nEOS.O19        ETAT DU SENEGAL 6,60 % 2025-2030                        10   10,000      /                                                            10 000\n\nEOS.O20        ETAT DU SENEGAL 6,75 % 2025-20                                           /      10,000                     100                         9 600\n\nEOS.O21        ETAT DU SENEGAL 6,95 % 2025-20                                           /                                                            10 000\n\nEOS.O22        ETAT DU SENEGAL 6,40% 2025-202                                           /                                                             9 800\n\nEOS.O23        ETAT DU SENEGAL 6,60% 2025-203                                           /                                                            10 000\n\nEOS.O24        ETAT DU SENEGAL 6,75% 2025-2032                                          /      9,800                22 273                           10 000\n\nEOS.O13        ETAT DU SENEGAL 6,30 % 2023-2030                                         /                                                             9 999\n\nEOS.O14        ETAT DU SENEGAL 6,50 % 2023-2033                                         /      10,000                       5                         9 800\n\nEOS.O15        ETAT DU SENEGAL 6,25 % 2024-2029                                         /                                                            10 000\n\nEOS.O16        ETAT DU SENEGAL 6,45 % 2024-2031                                         /                                                            10 000\n\nEOS.O17        ETAT DU SENEGAL 6,65 % 2024-2034                                         /                                                            10 000\n\nEOS.O18        ETAT DU SENEGAL 6,40 % 2025-20                                           /                                                             9 950\n\nEOM.O7         ETAT DU MALI 6.50% 2021-2031                                             /      6,250                       47                         6 250\n\nEOM.O8         ETAT DU MALI 6,20 % 2022-2032                                            /                                                            10 000\n\nEOM.O9         ETAT DU MALI 6,30% 2022-2032                                             /                                                             9 795\n\nEOS.O10        ETAT DU SENEGAL 6,15 % 2023-2030                                         /                                                            10 000\n\nEOS.O11        ETAT DU SENEGAL 6,35 % 2023-2033                                         /      10,000                       4                        10 000\n\nEOS.O12        ETAT DU SENEGAL 6,15 % 2023-2028                                         /                                                             9 700\n\nEOM.O20        ETAT DU MALI 6,35% 2025-2030                                             /                                                            10 000\n\nEOM.O21        ETAT DU MALI 6,55% 2026-2036                                             /                                                            10 000\n\nEOM.O22        ETAT DU MALI 6,35% 2026-2033                                             /                                                            10 000\n\nEOM.O4         ETAT DU MALI 6,50% 2019-2027                                             /                                                             2 310\n\nEOM.O5         ETAT DU MALI 6,50% 2020-2028                            10   3,940       /                                                             4 000\n\nEOM.O6         ETAT DU MALI 6,50% 2020-2027                                             /                                                             4 000\n\nEOM.O14        ETAT DU MALI 3,00 % 2024-2031                                            /                                                            10 000\n\nEOM.O15        ETAT DU MALI 6,55 % 2024-2031                                            /                                                            10 000\n\nEOM.O16        ETAT DU MALI 6,35 % 2024-2029                                            /                                                            10 000\n\nEOM.O17        ETAT DU MALI 6,55% 2025-2032                                             /                                                            10 000\n\nEOM.O18        ETAT DU MALI 6,35% 2025-2030                                             /                                                            10 000\n\nEOM.O19        ETAT DU MALI 6,55% 2025-2032                                             /                                                            10 000\n\nCRRH.O8        CRRH-UEMOA 5.95% 2018-2030                                               /                                                             3 713\n\nCRRH.O9        CRRH-UEMOA 6.05% 2018-2033                                               /                                                             5 000\n\nEOM.O10        ETAT DU MALI 6,20 % 2022-2029                                            /      10,000                       8                        10 000\n\nEOM.O11        ETAT DU MALI 6,40 % 2023-2030                                            /                                                            10 000\n\nEOM.O12        ETAT DU MALI 6,50 % 2023-2030                            5   Marché      /                                                             9 800\n\nEOM.O13        ETAT DU MALI 6,50 % 2024-2034                                            /                                                            10 000\n\nBABS.O1        GSS BAOBAB 6,80% 2024-2029                                               /                                                            10 000\n\nBIDC.O8        BIDC-EBID GSS BOND 6,50% 2024-2031                      30   10,000      /                                                            10 000\n\nCRRH.O11       SOCIAL BOND CRRH-UEMOA 6,00% 2025-2040                                   /                                                            10 000\n\nECOC.O1        GENDER BOND ECOBANK CI 6,50% 2024-2029                                   /                                                            10 000\n\n\nSUKUK ET TITRES ASSIMILES\n     Symbole                          Titre              Quantité                      Cours               Quantité             Cours de Référence\n                                                        résiduelle à                Achat / Vente       résiduelle à la\n                                                           l\'achat                                           vente\nSUKTG.S1       SUKUK TG 6.5% 2016-2026                                                  /                                                        10 300\n\n\n\n\n                                                                             16\n                                                                                              vendredi 31 juillet 2026\n\n\n\n\nANNEE : 2026\nSociété                      Nature           Date              Heure      Lieu\nECOBANK TG                   Extraordinaire   13/08/2026        10:30:00   Visioconférence\n\nORAGROUP                     Mixte            23/07/2026        10:00:00   Hôtel 2 Février situé à la Place de l\'indépendance, BP\n                                                                           131 Lomé- Togo\nUNIWAX CI                    Ordinaire        16/07/2026        09:00:00   Espace Latrille Events, sis à Abidjan, Cocody II-\n                                                                           Plateaux (Carrefour Duncan)\nSITAB                        Ordinaire        15/07/2026        10:00:00   SALLE JEWELS de la CGECI au Plateau\n\nERIUM CI                     Ordinaire        13/07/2026        09:00:00   SALLE DE CONFÉRENCE DE L’HOTEL TIAMA , à Abidjan\n                                                                           (République de Côte d’Ivoire)\nSODECI                       Ordinaire        30/06/2026        08:30:00   Espace CRYSTAL sis à Abidjan Zone 4C\n\nNSBC                         Ordinaire        30/06/2026        10:30:00   Espace Latrille Events, sis à Abidjan-Cocody, Deux-\n                                                                           Plateaux, Boulevard Latrille, à proximité du Carrefour\n                                                                           Duncan\nBIIC                         Ordinaire        30/06/2026        13:00:00   Salle bleue du palais des congrès de Cotonou\n\nLNB                          Ordinaire        30/06/2026        08:00:00   Salle bleue du palais des congrès de Cotonou\n\nTRACTAFRIC CI                Mixte            30/06/2026        10:00:00   Hôtel MOVENPICK ABIDJAN sis au Plateau, Avenue\n                                                                           Terrasson de Fougères, Ange Rue Gourgas\nNEI-CEDA CI                  Ordinaire        25/06/2026        10:00:00   Hôtel TIAMA, Abidjan-Plateau\n\nVIVO ENERGY CI               Ordinaire        24/06/2026        10:00:00   Espace JECKAD, Angré 7e tranche Rue L155, à côté de\n                                                                           l\'Hôtel Silver Moon\nCIE CI                       Ordinaire        24/06/2026        08:30:00   Visioconférence\n\nSMB                          Ordinaire        23/06/2026        09:30:00   Amphithéâtre de la CGECI (La Maison de l’Entreprise)\n                                                                           sis à Abidjan-Plateau, à l’Angle du Boulevard de la\n                                                                           République et de l’Avenue Lamblin ou par\n                                                                           visioconférence\nSAFCA CI                     Mixte            23/06/2026        09:00:00   Siège de Alios Finance Côte d’Ivoire, sis à Abidjan\n                                                                           Commune de Treichville, Rue des carrossiers, zone 3\nCFAO MOTORS CI               Ordinaire        23/06/2026        09:00:00   Siège social sis à Abidjan, 117, Boulevard de Marseille\n\nSGB CI                       Mixte            22/06/2026        15:00:00   Espace Latrille Events, sis à Abidjan commune de\n                                                                           Cocody, aux Deux-Plateaux Boulevard Latrille\nFILTISAC CI                  Ordinaire        18/06/2026        10:00:00   Immeuble C.R.R.A.E – U.M.O.A ABIDJAN – Plateau (RCI)\n\nTOTALENERGIES MARKETING SN   Ordinaire        17/06/2026        10:00:00   Noom Hôtel Dakar Sea Plaza (ex Radisson Blu de Dakar)\n\nBERNABE CI                   Ordinaire        15/06/2026        10:00:00   ABIDJAN, à la salle de conférence GEWELS de la Maison\n                                                                           du Patronat Ivoirien (CGECI) au Plateau\nNESTLE CI                    Ordinaire        11/06/2026        10:00:00   l’ESPACE LATRILLE EVENTS situé à Abidjan, Cocody,\n                                                                           Deux Plateaux, Boulevard Latrille, Carrefour Duncan\nSERVAIR ABIDJAN CI           Ordinaire        10/06/2026        10:30:00   Salle JEWELS de la Maison de l’Entreprise (CGECI) sise\n                                                                           au Plateau, Avenue Lamblin\nSOLIBRA                      Ordinaire        05/06/2026        09:00:00   Salle Jewels de la Maison de l’Entreprise sise au\n                                                                           Plateau, Avenue Lamblin\nECOBANK TG                   Ordinaire        03/06/2026        10:30:00   Salle de Conférence du Centre Panafricain Ecobank,\n                                                                           2365, Boulevard du Mono, Lomé, Togo\nSOGB                         Ordinaire        28/05/2026        10:30:00   Immeuble CRRAE – UEMOA sis à Abidjan Plateau\n\nSETAO CI                     Ordinaire        28/05/2026        10:00:00   Hôtel TIAMA (salon EBENE) - Abidjan Plateau\n\nSICOR                        Ordinaire        22/05/2026        09:00:00   Siège de la Société à l’Usine SICOR de Jacqueville\n\nSUCRIVOIRE                   Ordinaire        21/05/2026        09:30:00   Par visioconférence via la plateforme web dédiée à\n                                                                           l\'Assemblée Générale ou en présentiel à la Salle de\n                                                                           conférence de la CRRAE-UMOA ) Abidjan Plateau (Côte\n                                                                           d\'Ivoire)\nTOTAL                        Ordinaire        21/05/2026        10:00:00   Sofitel Abidjan Hôtel Ivoire sis au Boulevard Hassan II,\n                                                                           Cocody Abidjan Côte d’Ivoire\nSIB                          Ordinaire        12/05/2026        10:00:00   Par Visioconférence ou en présentiel à la CRRAE-UMOA\n                                                                           (Abidjan - Plateau, Angle Boulevard Botreau Roussel,\n                                                                           Rue Privée CRRAE-UMOA)\nECOBANK TG                   Extraordinaire   07/05/2026        10:30:00   Par Visioconférence\n\nBICI CI                      Ordinaire        04/05/2026        10:00:00   Auditorium de la Maison des Entreprises (CGECI) sise\n                                                                           au Plateau\nBANK OF AFRICA BN            Ordinaire        30/04/2026        10:00:00   GOLDEN TULIP LE DIPLOMATE à Cotonou\n\nORANGE CI                    Ordinaire        29/04/2026        10:00:00   En présentiel au siège de la société, sis à Abidjan,\n                                                                           Cocody Riviera Golf, Boulevard de France, Immeuble\n                                                                           Orange Village ou par visioconférence en envoyant un\n                                                                           courrier électronique à l’adresse ag2026-\n                                                                           orangeci@quorumenligne.com\nONATEL BF                    Ordinaire        29/04/2026        10:30:00   AZALAÏ HÔTEL, OUAGADOUGOU-BURKINA FASO\n\nECOBANK CI                   Ordinaire        28/04/2026        10:00:00   Par Visioconférence ou en présentiel à l’hôtel Radisson\n                                                                           Blu, sis à Abidjan Port-Bouët, route de l’Aéroport\n                                                                           International Félix Houphouët Boigny\n\n                                                           17\n                                                                                       vendredi 31 juillet 2026\nSONATEL                    Ordinaire   16/04/2026        10:00:00   Visioconférence, au NOOM Hôtel à Dakar\n\nCORIS BANK INTERNATIONAL   Ordinaire   16/04/2026        09:00:00   Sopatel Hôtel Silmandé SA\n\nBANK OF AFRICA CI          Ordinaire   15/04/2026        10:00:00   Salle Fromager de l\'hôtel IVOTEL du Plateau (10ème\n                                                                    étage)\nBANK OF AFRICA ML          Ordinaire   14/04/2026        09:30:00   Siège de la banque à l\'Immeuble BANK OF AFRICA,\n                                                                    Avenue du Mali, Quartier ACI 2000\nPALM CI                    Ordinaire   14/04/2026        09:00:00   Salle de Conférence de la CRRAE-UMOA à Abidjan-\n                                                                    Plateau (Côte d’Ivoire) ou par VISIOCONFERENCE, via\n                                                                    la plateforme web dédiée\nSAPH CI                    Ordinaire   09/04/2026        09:00:00   Salle de Conférence de la CRRAE-UMOA à Abidjan-\n                                                                    Plateau en présentiel ou par visioconférence, via la\n                                                                    plateforme web dédiée à l’Assemblée Générale\nBANK OF AFRICA SN          Ordinaire   07/04/2026        09:30:00   Immeuble Elan II au 2ème Etage, Almadies, Zone 12,\n                                                                    Route de Ngor à Dakar\nBANK OF AFRICA NG          Ordinaire   03/04/2026        10:00:00   Centre de Formation de BOA-NIGER (BOA-Siège)\n\nSICABLE                    Ordinaire   27/03/2026        10:00:00   Hôtel TIAMA, à ABIDJAN\n\nUNILEVER CI                Ordinaire   27/03/2026        10:00:00   Salle Sirocco 1 de l’Hôtel Novotel Adagio, situé à\n                                                                    Marcory Boulevard Felix Houphouet Boigny ex VGE, à\n                                                                    proximité de l’Hôtel Azalaï ou par Visioconférence en\n                                                                    envoyant un courriel à l’adresse :\n                                                                    servicesfinanciers@hudson-cie.com\nBANK OF AFRICA BF          Ordinaire   23/03/2026        10:00:00   AZALAÏ Hôtel à la salle DIMAKO\n\n\n\n\n                                                    18\n                                                                                                   OPCVM: FONDS COMMUNS DE PLACEMENT ET SOCIETES D\'INVESTISSEMENT A CAPITAL VARIABLE\n                                                                                                                                   vendredi 31 juillet 2026\n                                                                                                                                                                                             Valeur Liquidative\n      Sociétés de gestion                  Dépositaire                          OPCVM                 Catégorie                                     Précédente                                 Actuelle                                             Variation\n                                                                                                                         Origine                                                                                                          Origine                   Précédent\n                                                                                                                                             Valeur              Date              Valeur                    Date             Date                   %                  %\n                                                                                                                                     QUOTIDIENNES\nAFRICABOURSE ASSET             AFRICABOURSE SA                  FCP AAM EPARGNE CROISSANCE                D                    5 000             13 182,71       29/07/2026                      ND                 ND        19/11/2012                        -                    -\nMANAGEMENT\n                                                                FCP AAM OBLIGATIS                       OATC                   5 000                  9 522,37   29/07/2026                      ND                 ND        12/09/2012                        -                    -\n                                                                FCP AAM EPARGNE ACTION                    A                    5 000                12 866,89    29/07/2026                      ND                 ND        16/01/2017                        -                    -\n                                                                FCP AAM SERENITIS                       OATC                  10 000                13 277,72    29/07/2026                      ND                 ND        20/12/2023                        -                    -\nAFRICAM SA                     SBIF                             FCP EXPANSIO                              D                    5 000                14 304,34    24/07/2026                      ND                 ND        01/01/2013                        -                    -\n                                                                FCP SECURITAS                           OMLT                   5 000                 8 835,33    24/07/2026                      ND                 ND        01/01/2013                        -                   -\n                                                                FCP VALORIS                               A                    5 000                21 727,83    24/07/2026                      ND                 ND        01/01/2013                        -                   -\nAFRICAINE DE GESTION                                            FCP CAPITAL PLUS                          D                    1 000                 1 668,58    29/07/2026                1 671,45         30/07/2026        11/03/2019                   67,15%               0,17%\n                               SGI AGI\nD\'ACTIFS                                                        FCP CONFORT PLUS                        OMLT                       1000               1 552,17   29/07/2026                1 553,62         30/07/2026              11/03/19               55,36%           0,093%\n\nATLANTIC ASSET MANAGEMENT ATLANTIQUE FINANCE                    FCP ATLANTIQUE CROISSANCE                 D                    5 000                  5 802,43   16/01/2025                      ND                 ND        30/05/2015                        -                    -\n\n                                                                FCP ATLANTIQUE LIQUIDITE                 OCT                   5 000                  7 047,97   24/06/2026                      ND                 ND        12/07/2019                        -                    -\n                                                                FCP ATLANTIQUE ACTIONS                    A                    5 000                  9 679,97   16/01/2025                      ND                 ND        25/10/2019                        -                    -\n                                                                FCP ATLANTIQUE SERENITE                 OMLT                   5 000                  7 400,93   24/06/2026                      ND                 ND        12/07/2019                        -                    -\n                                                                FCP ATLANTIQUE HORIZON                   D                     5 000                12 977,45    24/06/2026                      ND                 ND        25/10/2019                        -                    -\n                                                                FCP ATLANTIQUE SECURITE                 OMLT                   5 000                 7 258,57    16/01/2025                      ND                 ND        30/05/2015                        -                    -\nBNI GESTION                    BNI FINANCES                     FCP CAPITAL CROISSANCE                   D                    10 000                       ND    09/07/2026                      ND         10/07/2026\n                                                                OBLIG SECURITE                          OMLT                  10 000                12 047,00    10/07/2026                      ND                 ND        06/06/2014                        -                   -\n                                                                FCP DYNAMIC SAVINGS                      A                    10 000                15 776,00    10/07/2026                      ND                 ND                                          -                   -\nBOA ASSET MANAGEMENT           BOA CAPITAL SECURITIES           FCP Emergence                            D                     5 000                 9 241,46    29/07/2026                9 241,84         30/07/2026              Fev.2010               84,84%               0,00%\n                                                                FCP Treso Monea                          OCT              25 000 000            38 678 589,76    23/10/2023                      ND                 ND          Dec. 2013                       -                    -\n                                                                FCP ACTIONS PHARMACIE                     D                        1000               1 320,26   29/07/2026                      ND                 ND              25/07/14                    -                    -\n                                                                FCP SALAM CI                              D                        1000               1 302,09   29/07/2026                      ND                 ND              01/03/17                    -                    -\n                                                                FCP AL BARAKA 2                           D                        1000               1 385,99   29/07/2026                      ND                 ND              25/01/18                    -                    -\n                                                                FCP ASSUR SENEGAL                         D                 1000000              1 843 139,66    29/07/2026                      ND                 ND              06/07/14                    -                    -\n                                                                FCP AVANTAGE AKWABA                       D                        1000               2 009,84   29/07/2026                      ND                 ND              29/03/13                    -                    -\nCGF GESTION                    CGF BOURSE\n                                                                FCP PLACEMENT CROISSANCE                  A                        1000               2 373,29   29/07/2026                      ND                 ND              29/03/13                    -                    -\n                                                                FCP POSTEFINANCES HORIZON                 D                        1000               2 901,03   29/07/2026                      ND                 ND              27/06/09                    -                    -\n                                                                FCP PLACEMENT QUIETUDE                    O                        1000               1 846,92   29/07/2026                      ND                 ND              29/03/13                    -                    -\n                                                                FCP LIQUIDITE-OPTIMUM                     D                    10000                14 882,39    29/07/2026                      ND                 ND              01/10/17                    -                    -\n                                                                FCP BNDE VALEURS                          D                        1000               1 587,60   29/07/2026                      ND                 ND              02/09/16                    -                    -\n                               CORIS BOURSE                     FCP CORIS ACTIONS                         A                    5 000                14 332,26    29/07/2026                      ND                 ND        11/11/2014                        -                    -\nCORIS ASSET MANAGEMENT                                          FCP ASSURANCES                           OCT                   5 000                  6 101,52   29/07/2026                      ND                 ND        22/11/2019                        -                    -\n                                                                FCP CORIS PERFORMANCE                     D                    5 000                11 670,00    29/07/2026                      ND                 ND        11/11/2014                        -                    -\nNSIA AM                        BOA CI                           NSIA FONDS DIVERSIFIE                     D                    5 000                 8 293,34    29/07/2026                      ND                 ND        03/12/2018                        -                    -\n                               UBA CI                           AURORE OPPORTUNITES                       A                    5 000                12 365,81    29/07/2026                      ND                 ND        08/03/2019                        -                    -\n                               NSIA BANQUE CI                   AURORE SECURITE                         OMLT                   5 000                  6 819,75   29/07/2026                      ND                 ND        03/09/2021                        -                    -\n                                                                NSIA ASSURANCES OPTIMUM                   D                1 000 000             1 557 503,85    29/07/2026                      ND                 ND        30/09/2021                        -                    -\n                                                                AURORE MONETARIS                          M                    5 000                  6 079,52   29/07/2026                      ND                 ND        24/04/2024                        -                    -\n                                                                TAWFIR HALAL                              D                    5 000                  6 810,26   29/07/2026                      ND                 ND        17/07/2024                        -                    -\n                               NSIA FINANCE                     AURORE SECURITE II                      OMLT                   6 133                  6 179,80   29/07/2026                      ND                 ND        17/10/2024                        -                    -\n                                                                AURORE OBLIGATIONS SOUVERAINES          OMLT                  10 000                11 324,67    29/07/2026                      ND                 ND        03/03/2025                        -                    -\n                                                                OBLIGATIONS PREMIUM                     OMLT                   5 000                  5 809,70   29/07/2026                      ND                 ND        26/02/2025                        -                    -\nOAM S.A                        SGI TOGO                         FCP-1 OPTI PLACEMENT                      A                    5 000                38 852,27    29/07/2026                      ND                 ND        01/02/2002                        -                    -\n                                                                FCP-2 OPTI REVENU                       OMLT                   5 000                 9 907,09    29/07/2026                      ND                 ND        01/02/2002                        -                    -\n                                                                FCP-3 OPTI CAPITAL                       D                     5 000                26 971,78    29/07/2026                      ND                 ND        24/01/2003                        -                    -\nSGCAMWA                        SGCSWA                           FCP SOGEAVENIR                           D                       500                 2 990,00    15/06/2026                      ND                 ND        01/10/2002                        -                    -\n                                                                FCP SOGEDEFI                              D                    4 888                  7 114,00   15/06/2026                      ND                 ND        23/12/2014                        -                    -\n                                                                FCP SOGEDYNAMIQUE                         A                    4 888                 7 816,00    15/06/2026                      ND                 ND        23/12/2014                        -                    -\n                               SGCI/BTCC                        FCP SOGELIQUID                            M               10 000 000            11 546 656,00    15/06/2026                      ND                 ND        16/06/2020                        -                    -\n                               SGCSWA                           FCP SOGEPRIVILEGE                         D                    4 888                 6 877,00    15/06/2026                      ND                 ND        23/12/2014                        -                    -\n                                                                FCP SOGESECURITE                        OMLT                   4 888                  5 305,00   15/06/2026                      ND                 ND        23/12/2014                        -                    -\n                                                                FCP SOGEVALOR                             A                    1 000                  7 580,00   15/06/2026                      ND                 ND        04/06/2002                        -                    -\n                               SGCI/BTCC                        FCP SOGE EPARGNE PLUS                     M                    5 000                  5 785,00   15/06/2026                      ND                 ND        24/06/2025                        -                    -\n                                                                FCP SOAGA EPARGNE ACTIVE                  D                   10 000                16 681,22    29/07/2026               16 702,61         30/07/2026        28/10/2016                   67,03%               0,13%\n                                                                SICAV Abdou DIOUF                         D               10 000 000             1 791 103,92    29/07/2026            1 793 110,57         30/07/2026        01/12/2003                  -82,07%               0,11%\n\n                                                                FCP BOAD CAPITAL RETRAITE               OMLT                  10 000                15 080,16    29/07/2026               15 081,95         30/07/2026        08/07/2020                   50,82%               0,01%\n                                                                FCP SOAGA EPARGNE OBLIGATIONS           OMLT                   5 000                  6 756,33   29/07/2026                6 755,14         30/07/2026        28/04/2021                   35,10%               -0,02%\nSOAGA-SA                       SGI-BOA CAPITAL SECURITIES\n                                                                FCP SOAGA EPARGNE ACTIONS                 A                    5 000                15 312,30    29/07/2026               15 256,22         30/07/2026        11/03/2020                  205,12%               -0,37%\n                                                                FCP SOAGA EPARGNE SERENITE              OMLT                  10 000                17 807,65    29/07/2026               17 809,56         30/07/2026        28/10/2016                   78,10%               0,01%\n                                                                FCP SOAGA EPARGNE QUIETUDE              OMLT                   5 000                 6 528,96    29/07/2026                6 529,59         30/07/2026        19/06/2023                   30,59%                0,01%\n                                                                FCP SOAGA EPARGNE DYNAMIQUE              A                     5 000                 9 603,61    29/07/2026                9 584,47         30/07/2026        19/06/2023                   91,69%               -0,20%\n                                                                FCP SOAGA TRESORERIE                     M                    10 000                10 726,05    29/07/2026               10 727,06         30/07/2026        30/05/2025                    7,27%                0,01%\nENKO CAPITAL WEST AFRICA       EDC Investment Corporation\n                                                                FCP GOORGOORLU                           OCT                   1 000                  1 022,37   17/07/2026                      ND                 ND        28/02/2025                        -                    -\n\nSAPHIR ASSET MANAGEMENT        SGI BENIN                        FCP SAPHIR DYNAMIQUE                      D                    5 000                  8 674,35   29/07/2026                8 644,35         30/07/2026        28/08/2017                   72,89%               -0,35%\n                                                                FCP SAPHIR QUIETUDE                     OMLT                   5 000                  6 887,99   29/07/2026                6 886,66         30/07/2026        28/08/2017                   37,73%               -0,02%\n                                                                FCP SAPHIR LIQUIDITE                     OCT                  10 000                10 148,99    29/07/2026               10 149,67         30/07/2026        01/07/2026                    1,50%               0,01%\nWAFI CAPITAL S.A.              SGI AGI BENIN                    SICAV WAFI CAPITAL                        D                   10 000                10 974,62    15/10/2025                      ND                 ND        05/06/2020                        -                    -\nSGO MALI FINANCES              SGI MALI SA                      FCP NYESIGUI                              D                   10 000                19 672,18    22/07/2026                      ND                 ND        01/07/2018                        -                    -\n                                                                                                                                      HEBDOMADAIRES\nBNI GESTION                    BNI FINANCES                     FCP INITIATIVES SOLIDARITE                D                    5 000                  6 765,00   30/06/2026                6 748,00         03/07/2026        22/05/2013                   34,96%               -0,25%\n                                                                FCPE SODEFOR                              D                    2 500                10 932,00    30/06/2026               10 906,00         03/07/2026        18/12/2009                  336,24%               -0,24%\n                                                                FCP PAM Actions                           A                   10 000                29 274,00    16/07/2026               29 475,00         23/07/2026        13/12/2017                  194,75%               0,69%\nPHOENIX AFRICA ASSET\n                               Phoenix Capital Management       FCP PAM Diversifié Equilibré              D                   10 000                23 570,00    16/07/2026               23 703,00         23/07/2026        13/12/2017                  137,03%               0,56%\nMANAGEMENT\n                                                                FCP PAM Diversifié Obligations            D                   10 000                19 794,00    16/07/2026               19 858,00         23/07/2026        13/12/2017                   98,58%               0,32%\n                                                                FCP Global Investors                      D                   25 000                62 263,57    17/07/2026               62 605,34         24/07/2026 Dec. 2012                          150,42%               0,55%\n                                                                FCP Boa Obligations                     OMLT                  10 000                13 805,68    17/07/2026               13 823,65         24/07/2026 Mars. 2017                          38,24%               0,13%\nBOA ASSET MANAGEMENT           BOA CAPITAL SECURITIES           FCP Boa Sécurité                        OMLT                 100 000               123 156,63    21/07/2026             123 296,16          28/07/2026 Mai. 2021                           23,30%               0,11%\n                                                                FCP Boa Actions                           A                   10 000                29 166,55    17/07/2026               29 499,22         24/07/2026 Juil. 2017                         194,99%               1,14%\n                                                                FCP Boa Rendement                       OMLT              25 000 000            40 516 245,99    17/07/2026        40 562 122,75            24/07/2026 Dec. 2017                           62,25%               0,11%\n                               BRIDGE SECURITIES                FCP BRIDGE EQUILIBRE                      D                    5 000                  8 842,23   08/05/2026                8 963,30         15/05/2026        27/09/2017                   79,27%               1,37%\nBRIDGE ASSET MANAGEMENT        BRIDGE SECURITIES                FCP BRIDGE DIVERSIFIE CROISSANCE          D                    5 000                  9 160,56   08/05/2026                9 282,58         15/05/2026        13/03/2018                   85,65%               1,33%\n                               BRIDGE SECURITIES                FCP BRIDGE OBLIGATIONS                  OMLT                   5 000                  6 895,52   08/05/2026                6 903,40         15/05/2026        01/10/2019                   38,07%               0,11%\nENKO CAPITAL WEST AFRICA       EDC Investment Corporation\n                                                                FCP ENKO CAPITAL GARANTI                  D                   10 000                22 391,29    15/07/2026               22 391,29         17/07/2026        21/09/2020                  123,91%               0,00%\n\n                                                                FCP PATRIMOINE                          OMLT                  10 000                13 885,08    13/07/2026               13 885,08         17/07/2026        18/10/2020                   38,85%               0,00%\n                                                                FCP ENKO CAPITAL OBLIGATIONS            OMLT                  10 000                12 847,88    15/07/2026               12 847,88         17/07/2026        16/03/2021                   28,48%               0,00%\n                                                                FCP ENKO CAPITAL LIQUIDITE              OMLT                  10 000                10 435,02    13/07/2026               10 435,02         17/07/2026        18/04/2024                    4,35%               0,00%\nNSIA AM                        UBA CI                           EVOLUTIS                                  D                    5 000                  8 589,21   17/07/2026                8 570,16         24/07/2026        16/12/2019                   71,40%               -0,22%\n\n                                                                FCP BAM TRESOR                           OCT                  10 000                12 002,61    23/07/2026               12 011,63         30/07/2026        21/12/2023                   20,12%               0,08%\nBAOBAB ASSET MANAGEMENT        FGI\n                                                                FCP BAM WURUS                             A                   10 000                20 145,69    10/07/2026               20 156,29         17/07/2026        12/04/2024                  101,56%               0,05%\n\nIMPAXIS ASSET MANAGEMENT       IMPAXIS SECURITIES               FCP SDE                                   D                    1 000                  2 992,44   23/07/2026                3 001,49           46 233,00       30/07/2026                  199,24%               0,30%\n\n                               CGF BOURSE                       FCPCR SONATEL                             D                    1 000                12 446,18    17/07/2026               12 401,81         24/07/2026        19/02/2004                 1140,18%               -0,36%\n                               IMPAXIS SECURITIES               FCPE FORCE PAD                            D                    1 000                  2 770,79   23/07/2026                2 793,65         30/07/2026        16/02/2014                  179,37%               0,83%\n                               CGF BOURSE                       FCPE SINI GNESIGUI                        D                    1 000                  2 418,21   22/07/2026                2 448,36         29/07/2026        25/02/2014                  144,84%               1,25%\n                               CGF BOURSE                       FCP EXPAT                                 D                    1 000                  1 469,08   23/07/2026                1 482,51         30/07/2026        20/03/2019                   48,25%               0,91%\n\n                               CGF BOURSE                       FCP CAPITAL RETRAITE                      D                    1 000                  1 375,67   22/07/2026                1 380,75         29/07/2026        20/03/2019                   38,07%               0,37%\n                               CGF BOURSE                       FCP RENTE PERPETUELLE                     D                    1 000                  1 387,00   22/07/2026                1 396,64         29/07/2026        20/03/2019                   39,66%               0,69%\nCGF GESTION                    CGF BOURSE                       FCP WALO                                  D                    1 000                  1 471,36   21/07/2026                1 478,22         28/07/2026        26/03/2019                   47,82%               0,47%\n                               CGF BOURSE                       FCP DJOLOF                                O                    1 000                  1 437,17   21/07/2026                1 439,41         28/07/2026        26/03/2019                   43,94%               0,16%\n                               CGF BOURSE                       FCP IMPACT DIASPORA                       D                    1 000                  1 393,09   21/07/2026                1 395,73         28/07/2026        19/03/2019                   39,57%               0,19%\n                               CGF BOURSE                       FCP IFC-BOAD                              O                  100 000               155 290,33    20/07/2026             155 260,18          27/07/2026        01/01/2018                   55,26%               -0,02%\n                               CGF BOURSE                       FCPE DP WORLD DAKAR                      D                     1 000                 1 803,39    21/07/2026                1 808,83         28/07/2026        04/10/2016                   80,88%            0,30%\n                               CGF BOURSE                       FCPR SEN FONDS                          OPCR                  10 000                11 605,21    01/01/2026               13 395,47         01/07/2026        23/02/2022                   33,95%           15,43%\n                               CGF BOURSE                       FCP TRANSVIE                             D                     1 000                 1 310,07    20/07/2026                1 314,04         27/07/2026        07/10/2024                   31,40%            0,30%\n                               UNITED CAPITAL FOR AFRICA        FCP UCA DOGUICIMI                        O                     5 000                 5 424,79    23/07/2026                5 428,05         30/07/2026        24/07/2025                    8,56%            0,06%\nEDC Asset Management           EDC Investment Corporation       FCP ECOBANK UEMOA DIVERSIFIE              D                    5 000                12 318,13    27/05/2026               12 589,97         03/06/2026        19/09/2007                  151,80%               2,21%\n\n                                                                FCP ECOBANK UEMOA OBLIGATAIRE           OMLT                  20 000                20 752,00    20/03/2024               20 780,00         27/03/2024        01/01/2018                   67,15%           67,15%\n\n                                                                FCP ECOBANK UEMOA RENDEMENT             OMLT               1 000 000             2 279 751,37    27/05/2026            2 331 409,90         03/06/2026        10/10/2007                  133,14%             2,27%\n                                                                FCP ECOBANK ACTIONS UEMOA                A                      5 000                7 463,44    27/05/2026                 7 699,41        03/06/2026        20/04/2016                   53,99%             3,16%\nSGO MALI FINANCES              SGI MALI SA                      FCPE ORANGE MALI                         D                    10 000                42 677,53    15/07/2026               42 527,15         22/07/2026        03/09/2012                  325,27%            -0,35%\nSGO MALI FINANCES              SGI MALI SA                      FCP TOUNKARANKE                         OMLT                 100 000               138 791,79    15/07/2026              138 607,52         22/07/2026        01/07/2018                  38,608%           -0,133%\n                                                                ATTIJARI OBLIG                          OMLT                   10000           14846,91          10/07/2026                14851,01         17/07/2026        13/07/2012                   48,51%             0,03%\n                                                                ATTIJARI LIQUIDITE                       OCT                   10000           16351,4           10/07/2026               16362,13          17/07/2026        30/08/2013                   63,62%               0,07%\n                                                                ATTIJARI HORIZON                        OMLT                   10000           17313,97          10/07/2026               17327,31          17/07/2026        30/08/2013                   73,27%               0,08%\n                                                                ATTIJARI ACTIONS                          A                    10000           36400,31          10/07/2026               36396,37          17/07/2026        21/05/2015                  263,96%               -0,01%\nATTIJARI ASSET MANAGEMENT      SGI AFRICAINE DE BOURSE          ATTIJARI DIVERSIFIE                      D                    10000         28327,2              10/07/2026                28305,37         17/07/2026        21/05/2015                  183,05%               -0,08%\n                                                                ATTIJARI INVEST                         OMLT                  10000        16604,85              10/07/2026                16617,02         17/07/2026        21/01/2016                   66,17%                0,07%\n                                                                ATTIJARI PATRIMOINE                     OMLT                  10000        16456,37              10/07/2026               16 469,42         17/07/2026        21/01/2016                   64,69%                0,08%\n                                                                CRBC-PROSPERITE                         OMLT                  10000        13110,15              10/07/2026                   13129         17/07/2026        04/02/2022                   31,29%                0,14%\n                                                                WAFA ASSURANCE UEMOA                    OMLT                  10000        11814,18              10/07/2026                11808,45         17/07/2026        05/05/2023                   18,08%               -0,05%\n                                                                FCP CRAT PERFORMANCE                     D                    10000        17099,45              10/07/2026                17025,89         17/07/2026        08/03/2024                   70,26%               -0,43%\n                                                                United Capital Sapphire                  D                     5000       6208,7255              17/07/2026              6190,3339          24/07/2026        01/08/2025                   23,81%               -0,30%\nUCAMWAL                        UBA CI\n                                                                United Capital Diamond                  OMLT                 100000      112895,7708             17/07/2026            112918,2289          24/07/2026        01/08/2025                   12,92%                0,02%\n                                                                                                                                     MENSUELLES\nBNI GESTION                    BNI FINANCES                     FCPE CNRA                                 D                    2 500             4 684,00        29/05/2026                4 730,00         30/06/2026        06/06/2014                   89,20%               0,98%\n                                                                FCPE BNI RETRAITE                         D                    2 500             6 141,00        29/05/2026                6 251,00         30/06/2026        06/06/2014                  150,04%               1,79%\n                                                                FCP KARIMA ETHIQUE                        D                    1 000             2 504,00        29/05/2026                2 548,00         30/06/2026        22/05/2013                  154,80%               1,76%\nOPCVM : Organisme de Placement Collectif en Valeurs Mobilières\nND: Non Disponible\nCatégories:                    OMLT: obligations à moyen et long terme\nA: actions                     C: contractuels\nOCT: obligations à court terme D: diversifiés\n', 'success', NULL, 'manuel', NULL, '2026-08-03 01:20:24', NULL, '2026-08-03 02:20:05');

-- --------------------------------------------------------

--
-- Structure de la table `market_indices`
--

CREATE TABLE `market_indices` (
  `id` int(11) NOT NULL,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Ex: BRVM10, BRVMC',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `base_value` decimal(15,2) DEFAULT '100.00' COMMENT 'Valeur de base',
  `base_date` date DEFAULT NULL COMMENT 'Date de base',
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `market_indices`
--

INSERT INTO `market_indices` (`id`, `code`, `name`, `description`, `base_value`, `base_date`, `active`, `created_at`, `updated_at`) VALUES
(1, 'BRVM-30', 'BRVM-30', 'Indice phare des 30 valeurs les plus actives (a remplacé BRVM10 en 2023)', '100.00', '1998-09-16', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(2, 'BRVM-COMPOSITE', 'BRVM - COMPOSITE', 'Indice composite de toutes les valeurs', '100.00', '1998-09-16', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(3, 'BRVM-PRESTIGE', 'BRVM - PRESTIGE', 'Indice des valeurs du compartiment Prestige', '100.00', '2014-01-02', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(4, 'BRVM-PRINCIPAL', 'BRVM - PRINCIPAL', 'Indice des valeurs du compartiment Principal', '100.00', '2014-01-02', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32');

-- --------------------------------------------------------

--
-- Structure de la table `price_alerts`
--

CREATE TABLE `price_alerts` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `alert_type` enum('above','below','change_percent') COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_price` decimal(15,2) DEFAULT NULL,
  `target_percent` decimal(10,4) DEFAULT NULL,
  `notification_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notification_webhook` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `triggered` tinyint(1) DEFAULT '0',
  `triggered_at` timestamp NULL DEFAULT NULL,
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `schema_migrations`
--

CREATE TABLE `schema_migrations` (
  `id` int(11) NOT NULL,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `schema_migrations`
--

INSERT INTO `schema_migrations` (`id`, `migration`, `applied_at`) VALUES
(1, '002_company_reports.sql', '2026-08-03 18:41:01'),
(2, '003_report_extraction_method.sql', '2026-08-03 18:41:01'),
(3, '004_admin_auth.sql', '2026-08-03 18:41:01'),
(4, '005_sync_interval_10min.sql', '2026-08-03 18:41:01'),
(5, '006_intraday_variation_percent.sql', '2026-08-03 19:36:36'),
(6, '007_seed_company_sectors.sql', '2026-08-03 19:48:48');

-- --------------------------------------------------------

--
-- Structure de la table `sectors`
--

CREATE TABLE `sectors` (
  `id` int(11) NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `sectors`
--

INSERT INTO `sectors` (`id`, `name`, `description`, `active`, `created_at`, `updated_at`) VALUES
(1, 'Banques', 'Institutions bancaires et financières', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(2, 'Assurances', 'Compagnies d\'assurance et de réassurance', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(3, 'Distribution', 'Commerce de gros et de détail', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(4, 'Agriculture', 'Agro-industrie et production agricole', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(5, 'Industrie', 'Industries manufacturières', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(6, 'Transport', 'Transport et logistique', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(7, 'Télécommunications', 'Opérateurs télécoms et services', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(8, 'Énergie', 'Production et distribution d\'énergie', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(9, 'Services', 'Services divers', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32'),
(10, 'Immobilier', 'Promotion et gestion immobilière', 1, '2026-08-02 22:44:32', '2026-08-02 22:44:32');

-- --------------------------------------------------------

--
-- Structure de la table `stock_quotes`
--

CREATE TABLE `stock_quotes` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `trading_date` date NOT NULL,
  `open_price` decimal(15,2) NOT NULL COMMENT 'Cours d''ouverture',
  `close_price` decimal(15,2) NOT NULL COMMENT 'Cours de clôture',
  `high_price` decimal(15,2) DEFAULT NULL COMMENT 'Plus haut',
  `low_price` decimal(15,2) DEFAULT NULL COMMENT 'Plus bas',
  `previous_close` decimal(15,2) DEFAULT NULL COMMENT 'Clôture veille',
  `volume` bigint(20) DEFAULT '0' COMMENT 'Volume échangé',
  `variation_percent` decimal(10,4) DEFAULT NULL COMMENT 'Variation en %',
  `variation_value` decimal(15,2) DEFAULT NULL COMMENT 'Variation en valeur',
  `turnover` decimal(20,2) DEFAULT NULL COMMENT 'Valeur échangée',
  `trades_count` int(11) DEFAULT '0' COMMENT 'Nombre de transactions',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `stock_quotes`
--

INSERT INTO `stock_quotes` (`id`, `company_id`, `trading_date`, `open_price`, `close_price`, `high_price`, `low_price`, `previous_close`, `volume`, `variation_percent`, `variation_value`, `turnover`, `trades_count`, `created_at`, `updated_at`) VALUES
(1, 1, '2026-08-03', '3000.00', '2990.00', '3000.00', '2990.00', '3000.00', 2842, '-0.3300', '-10.00', '8497580.00', 0, '2026-08-03 01:06:54', '2026-08-03 14:31:58'),
(2, 2, '2026-08-03', '7600.00', '7690.00', '7690.00', '7600.00', '7690.00', 22443, '1.3800', '0.00', '172586670.00', 0, '2026-08-03 01:06:54', '2026-08-03 14:50:29'),
(3, 3, '2026-08-03', '28675.00', '29015.00', '29015.00', '28675.00', '28670.00', 57, '1.2000', '345.00', '1653855.00', 0, '2026-08-03 01:06:54', '2026-08-03 12:50:31'),
(4, 4, '2026-08-03', '1900.00', '1930.00', '1930.00', '1900.00', '1930.00', 2336, '2.1200', '0.00', '4508480.00', 0, '2026-08-03 01:06:54', '2026-08-03 14:31:58'),
(5, 5, '2026-08-03', '8700.00', '8695.00', '8700.00', '8695.00', '8700.00', 8777, '-0.0600', '-5.00', '76316015.00', 0, '2026-08-03 01:06:54', '2026-08-03 14:31:58'),
(6, 6, '2026-08-03', '7105.00', '7200.00', '7200.00', '7105.00', '7100.00', 3920, '1.4100', '100.00', '28224000.00', 0, '2026-08-03 01:06:54', '2026-08-03 14:31:58'),
(7, 7, '2026-08-03', '10815.00', '11350.00', '11350.00', '10815.00', '11350.00', 5608, '4.9500', '0.00', '63650800.00', 0, '2026-08-03 01:06:54', '2026-08-03 14:31:58'),
(8, 8, '2026-08-03', '5670.00', '5625.00', '5670.00', '5625.00', '5665.00', 3155, '-0.7100', '-40.00', '17746875.00', 0, '2026-08-03 01:06:54', '2026-08-03 14:31:58'),
(9, 9, '2026-08-03', '5290.00', '5200.00', '5290.00', '5200.00', '5290.00', 1036, '-1.7000', '-90.00', '5387200.00', 0, '2026-08-03 01:06:54', '2026-08-03 14:31:58'),
(10, 10, '2026-08-03', '7790.00', '7685.00', '7790.00', '7685.00', '7790.00', 3389, '-1.3500', '-105.00', '26044465.00', 0, '2026-08-03 01:06:54', '2026-08-03 14:31:58'),
(11, 11, '2026-08-03', '3500.00', '3645.00', '3645.00', '3500.00', '3645.00', 6890, '4.1400', '0.00', '25114050.00', 0, '2026-08-03 01:06:54', '2026-08-03 14:31:58'),
(12, 12, '2026-08-03', '28000.00', '28490.00', '28490.00', '28000.00', '28000.00', 1113, '1.7500', '490.00', '31709370.00', 0, '2026-08-03 01:06:54', '2026-08-03 14:31:58'),
(13, 13, '2026-08-03', '1695.00', '1695.00', '1695.00', '1695.00', '1700.00', 2656, '1.8000', '-5.00', '4501920.00', 0, '2026-08-03 01:06:54', '2026-08-03 14:31:58'),
(14, 14, '2026-08-03', '5000.00', '5000.00', '5000.00', '5000.00', '5100.00', 6428, '0.0000', '-100.00', '32140000.00', 0, '2026-08-03 01:06:54', '2026-08-03 14:31:58'),
(15, 15, '2026-08-03', '16200.00', '16100.00', '16200.00', '16100.00', '16230.00', 1507, '-0.8000', '-130.00', '24262700.00', 0, '2026-08-03 01:06:54', '2026-08-03 14:31:58'),
(16, 16, '2026-08-03', '70.00', '69.00', '70.00', '69.00', '70.00', 836211, '4.5500', '-1.00', '57698559.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(17, 17, '2026-08-03', '1970.00', '1910.00', '1970.00', '1910.00', '1910.00', 3008, '-3.0500', '0.00', '5745280.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:50:29'),
(18, 18, '2026-08-03', '4835.00', '4560.00', '4835.00', '4560.00', '4590.00', 9055, '1.3300', '-30.00', '41290800.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(19, 19, '2026-08-03', '2280.00', '2280.00', '2280.00', '2280.00', '2280.00', 643, '0.0000', '0.00', '1466040.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(20, 20, '2026-08-03', '24500.00', '24020.00', '24500.00', '24020.00', '24000.00', 688, '0.0800', '20.00', '16525760.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(21, 21, '2026-08-03', '16500.00', '16550.00', '16550.00', '16500.00', '16490.00', 1778, '0.3000', '60.00', '29425900.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:10:32'),
(22, 22, '2026-08-03', '2995.00', '2990.00', '2995.00', '2990.00', '2945.00', 2793, '-0.1700', '45.00', '8351070.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(23, 23, '2026-08-03', '17000.00', '17000.00', '17000.00', '17000.00', '16995.00', 1175, '0.0300', '5.00', '19975000.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(24, 24, '2026-08-03', '3200.00', '3130.00', '3200.00', '3130.00', '3140.00', 5561, '-0.3200', '-10.00', '17405930.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(25, 25, '2026-08-03', '9000.00', '8990.00', '9000.00', '8990.00', '9000.00', 831, '-0.1100', '-10.00', '7470690.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(26, 26, '2026-08-03', '4515.00', '4400.00', '4515.00', '4400.00', '4400.00', 458, '-2.5500', '0.00', '2015200.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:10:32'),
(27, 27, '2026-08-03', '5100.00', '5000.00', '5100.00', '5000.00', '5015.00', 18854, '-2.5300', '-15.00', '94270000.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(28, 28, '2026-08-03', '3645.00', '3700.00', '3700.00', '3645.00', '3645.00', 4780, '1.5100', '55.00', '17686000.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(29, 29, '2026-08-03', '11900.00', '11900.00', '11900.00', '11900.00', '11900.00', 669, '0.0000', '0.00', '7961100.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(30, 30, '2026-08-03', '2700.00', '2750.00', '2750.00', '2700.00', '2750.00', 5315, '2.2300', '0.00', '14616250.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(31, 31, '2026-08-03', '1485.00', '1480.00', '1485.00', '1480.00', '1500.00', 2293, '-0.3400', '-20.00', '3393640.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(32, 32, '2026-08-03', '38015.00', '37995.00', '38015.00', '37995.00', '38015.00', 5427, '-0.0100', '-20.00', '206198865.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(33, 33, '2026-08-03', '2240.00', '2325.00', '2325.00', '2240.00', '2325.00', 5795, '5.6800', '0.00', '13473375.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(34, 34, '2026-08-03', '8800.00', '8800.00', '8800.00', '8800.00', '8940.00', 4617, '-2.2200', '-140.00', '40629600.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(35, 35, '2026-08-03', '6400.00', '6600.00', '6600.00', '6400.00', '6625.00', 19, '3.1200', '-25.00', '125400.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:10:32'),
(36, 36, '2026-08-03', '2200.00', '2200.00', '2200.00', '2200.00', '2200.00', 3414, '0.0000', '0.00', '7510800.00', 0, '2026-08-03 01:06:55', '2026-08-03 13:50:30'),
(37, 37, '2026-08-03', '37760.00', '37700.00', '37760.00', '37700.00', '37795.00', 935, '-0.1300', '-95.00', '35249500.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(38, 38, '2026-08-03', '15500.00', '15500.00', '15500.00', '15500.00', '15495.00', 1972, '0.0300', '5.00', '30566000.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(39, 39, '2026-08-03', '31000.00', '31000.00', '31000.00', '31000.00', '31000.00', 10204, '0.0000', '0.00', '316324000.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(40, 40, '2026-08-03', '8400.00', '8400.00', '8400.00', '8400.00', '8305.00', 2652, '1.1400', '95.00', '22276800.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(41, 41, '2026-08-03', '7595.00', '7500.00', '7595.00', '7500.00', '7565.00', 4030, '-0.8600', '-65.00', '30225000.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(42, 42, '2026-08-03', '2750.00', '2820.00', '2820.00', '2750.00', '2800.00', 4652, '5.2200', '20.00', '13118640.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(43, 43, '2026-08-03', '23205.00', '23985.00', '23985.00', '23205.00', '23985.00', 2331, '3.3800', '0.00', '55909035.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(44, 44, '2026-08-03', '2975.00', '2990.00', '2990.00', '2975.00', '2975.00', 7265, '0.5000', '15.00', '21722350.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(45, 45, '2026-08-03', '3625.00', '3655.00', '3655.00', '3625.00', '3625.00', 2070, '0.0000', '30.00', '7565850.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(46, 46, '2026-08-03', '51035.00', '51020.00', '51035.00', '51020.00', '51035.00', 37, '0.0400', '-15.00', '1887740.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58'),
(47, 47, '2026-08-03', '1950.00', '1900.00', '1950.00', '1900.00', '1915.00', 6968, '-2.3100', '-15.00', '13239200.00', 0, '2026-08-03 01:06:55', '2026-08-03 14:31:58');

-- --------------------------------------------------------

--
-- Structure de la table `sync_logs`
--

CREATE TABLE `sync_logs` (
  `id` bigint(20) NOT NULL,
  `sync_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'quotes, companies, indices',
  `sync_status` enum('started','success','failed','partial') COLLATE utf8mb4_unicode_ci NOT NULL,
  `records_processed` int(11) DEFAULT '0',
  `records_inserted` int(11) DEFAULT '0',
  `records_updated` int(11) DEFAULT '0',
  `records_failed` int(11) DEFAULT '0',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  `execution_time` int(11) DEFAULT NULL COMMENT 'Temps d''exécution en secondes'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `sync_logs`
--

INSERT INTO `sync_logs` (`id`, `sync_type`, `sync_status`, `records_processed`, `records_inserted`, `records_updated`, `records_failed`, `error_message`, `started_at`, `completed_at`, `execution_time`) VALUES
(1, 'quotes', 'success', 47, 47, 0, 0, NULL, '2026-08-03 00:06:55', '2026-08-03 00:06:55', NULL),
(2, 'indices', 'success', 4, 4, 0, 0, NULL, '2026-08-03 00:06:56', '2026-08-03 00:06:56', NULL),
(3, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 07:30:11', '2026-08-03 07:30:11', NULL),
(4, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 07:30:15', '2026-08-03 07:30:15', NULL),
(5, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 07:45:08', '2026-08-03 07:45:08', NULL),
(6, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 07:45:11', '2026-08-03 07:45:11', NULL),
(7, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 08:01:07', '2026-08-03 08:01:07', NULL),
(8, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 08:01:20', '2026-08-03 08:01:20', NULL),
(9, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 08:15:10', '2026-08-03 08:15:10', NULL),
(10, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 08:15:16', '2026-08-03 08:15:16', NULL),
(11, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 08:31:21', '2026-08-03 08:31:21', NULL),
(12, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 08:31:30', '2026-08-03 08:31:30', NULL),
(13, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 08:46:33', '2026-08-03 08:46:33', NULL),
(14, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 08:46:50', '2026-08-03 08:46:50', NULL),
(15, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 09:01:58', '2026-08-03 09:01:58', NULL),
(16, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 09:02:09', '2026-08-03 09:02:09', NULL),
(17, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 09:15:13', '2026-08-03 09:15:13', NULL),
(18, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 09:16:35', '2026-08-03 09:16:35', NULL),
(19, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 09:31:55', '2026-08-03 09:31:55', NULL),
(20, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 09:32:03', '2026-08-03 09:32:03', NULL),
(21, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 09:47:31', '2026-08-03 09:47:31', NULL),
(22, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 09:47:44', '2026-08-03 09:47:44', NULL),
(23, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 10:02:04', '2026-08-03 10:02:04', NULL),
(24, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 10:02:18', '2026-08-03 10:02:18', NULL),
(25, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 10:16:32', '2026-08-03 10:16:32', NULL),
(26, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 10:16:40', '2026-08-03 10:16:40', NULL),
(27, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 10:16:43', '2026-08-03 10:16:43', NULL),
(28, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 10:16:51', '2026-08-03 10:16:51', NULL),
(29, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 10:31:26', '2026-08-03 10:31:26', NULL),
(30, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 10:31:34', '2026-08-03 10:31:34', NULL),
(31, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 10:46:33', '2026-08-03 10:46:33', NULL),
(32, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 10:46:47', '2026-08-03 10:46:47', NULL),
(33, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 11:02:06', '2026-08-03 11:02:06', NULL),
(34, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 11:02:21', '2026-08-03 11:02:21', NULL),
(35, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 11:10:29', '2026-08-03 11:10:29', NULL),
(36, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 11:10:51', '2026-08-03 11:10:51', NULL),
(37, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 11:20:29', '2026-08-03 11:20:29', NULL),
(38, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 11:20:53', '2026-08-03 11:20:53', NULL),
(39, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 11:31:27', '2026-08-03 11:31:27', NULL),
(40, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 11:31:38', '2026-08-03 11:31:38', NULL),
(41, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 11:40:34', '2026-08-03 11:40:34', NULL),
(42, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 11:40:55', '2026-08-03 11:40:55', NULL),
(43, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 11:50:31', '2026-08-03 11:50:31', NULL),
(44, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 11:50:55', '2026-08-03 11:50:55', NULL),
(45, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 12:01:34', '2026-08-03 12:01:34', NULL),
(46, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 12:01:50', '2026-08-03 12:01:50', NULL),
(47, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 12:10:31', '2026-08-03 12:10:31', NULL),
(48, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 12:10:53', '2026-08-03 12:10:53', NULL),
(49, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 12:20:30', '2026-08-03 12:20:30', NULL),
(50, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 12:20:55', '2026-08-03 12:20:55', NULL),
(51, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 12:31:22', '2026-08-03 12:31:22', NULL),
(52, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 12:31:28', '2026-08-03 12:31:28', NULL),
(53, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 12:40:33', '2026-08-03 12:40:33', NULL),
(54, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 12:40:56', '2026-08-03 12:40:56', NULL),
(55, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 12:50:30', '2026-08-03 12:50:30', NULL),
(56, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 12:50:56', '2026-08-03 12:50:56', NULL),
(57, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 13:01:54', '2026-08-03 13:01:54', NULL),
(58, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 13:02:07', '2026-08-03 13:02:07', NULL),
(59, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 13:10:32', '2026-08-03 13:10:32', NULL),
(60, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 13:10:54', '2026-08-03 13:10:54', NULL),
(61, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 13:20:32', '2026-08-03 13:20:32', NULL),
(62, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 13:20:54', '2026-08-03 13:20:54', NULL),
(63, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 13:31:58', '2026-08-03 13:31:58', NULL),
(64, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 13:32:09', '2026-08-03 13:32:09', NULL),
(65, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 13:40:33', '2026-08-03 13:40:33', NULL),
(66, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 13:40:55', '2026-08-03 13:40:55', NULL),
(67, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 13:50:29', '2026-08-03 13:50:29', NULL),
(68, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 13:50:55', '2026-08-03 13:50:55', NULL),
(69, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 14:01:31', '2026-08-03 14:01:31', NULL),
(70, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 14:01:48', '2026-08-03 14:01:48', NULL),
(71, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 14:10:30', '2026-08-03 14:10:30', NULL),
(72, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 14:10:50', '2026-08-03 14:10:50', NULL),
(73, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 14:20:28', '2026-08-03 14:20:28', NULL),
(74, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 14:20:49', '2026-08-03 14:20:49', NULL),
(75, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 14:31:56', '2026-08-03 14:31:56', NULL),
(76, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 14:32:04', '2026-08-03 14:32:04', NULL),
(77, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 14:40:26', '2026-08-03 14:40:26', NULL),
(78, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 14:40:52', '2026-08-03 14:40:52', NULL),
(79, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 14:50:27', '2026-08-03 14:50:27', NULL),
(80, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 14:50:49', '2026-08-03 14:50:49', NULL),
(81, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 15:00:19', '2026-08-03 15:00:19', NULL),
(82, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 15:00:43', '2026-08-03 15:00:43', NULL),
(83, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 15:10:09', '2026-08-03 15:10:09', NULL),
(84, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 15:10:11', '2026-08-03 15:10:11', NULL),
(85, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 15:20:07', '2026-08-03 15:20:07', NULL),
(86, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 15:20:10', '2026-08-03 15:20:10', NULL),
(87, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 15:30:14', '2026-08-03 15:30:14', NULL),
(88, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 15:30:27', '2026-08-03 15:30:27', NULL),
(89, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 15:40:08', '2026-08-03 15:40:08', NULL),
(90, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 15:40:11', '2026-08-03 15:40:11', NULL),
(91, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 15:50:09', '2026-08-03 15:50:09', NULL),
(92, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 15:50:11', '2026-08-03 15:50:11', NULL),
(93, 'quotes', 'success', 47, 0, 47, 0, NULL, '2026-08-03 16:00:21', '2026-08-03 16:00:21', NULL),
(94, 'indices', 'success', 4, 0, 4, 0, NULL, '2026-08-03 16:00:32', '2026-08-03 16:00:32', NULL);

-- --------------------------------------------------------

--
-- Structure de la table `system_config`
--

CREATE TABLE `system_config` (
  `id` int(11) NOT NULL,
  `config_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `config_value` text COLLATE utf8mb4_unicode_ci,
  `description` text COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `system_config`
--

INSERT INTO `system_config` (`id`, `config_key`, `config_value`, `description`, `updated_at`) VALUES
(1, 'market_open_time', '08:30', 'Heure d\'ouverture du marché (format HH:MM)', '2026-08-02 22:44:32'),
(2, 'market_close_time', '16:00', 'Heure de fermeture du marché', '2026-08-02 22:44:32'),
(3, 'trading_days', 'monday,tuesday,wednesday,thursday,friday', 'Jours de trading', '2026-08-02 22:44:32'),
(4, 'sync_interval_minutes', '10', 'Intervalle de synchronisation pendant les heures de marché (minutes)', '2026-08-03 12:00:20'),
(5, 'timezone', 'Africa/Abidjan', 'Fuseau horaire du marché', '2026-08-02 22:44:32'),
(6, 'api_rate_limit', '100', 'Nombre de requêtes API par minute', '2026-08-02 22:44:32'),
(7, 'data_retention_days', '730', 'Nombre de jours de conservation des données (2 ans)', '2026-08-02 22:44:32');

-- --------------------------------------------------------

--
-- Structure de la table `technical_indicators`
--

CREATE TABLE `technical_indicators` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `trading_date` date NOT NULL,
  `sma_10` decimal(15,4) DEFAULT NULL COMMENT 'Moyenne mobile simple 10 jours',
  `sma_20` decimal(15,4) DEFAULT NULL,
  `sma_50` decimal(15,4) DEFAULT NULL,
  `sma_200` decimal(15,4) DEFAULT NULL,
  `ema_10` decimal(15,4) DEFAULT NULL COMMENT 'Moyenne mobile exponentielle 10 jours',
  `ema_20` decimal(15,4) DEFAULT NULL,
  `rsi_14` decimal(10,4) DEFAULT NULL COMMENT 'RSI 14 périodes',
  `macd_line` decimal(15,4) DEFAULT NULL,
  `macd_signal` decimal(15,4) DEFAULT NULL,
  `macd_histogram` decimal(15,4) DEFAULT NULL,
  `bb_upper` decimal(15,4) DEFAULT NULL,
  `bb_middle` decimal(15,4) DEFAULT NULL,
  `bb_lower` decimal(15,4) DEFAULT NULL,
  `atr_14` decimal(15,4) DEFAULT NULL COMMENT 'Average True Range',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Doublure de structure pour la vue `top_gainers`
-- (Voir ci-dessous la vue réelle)
--
CREATE TABLE `top_gainers` (
`symbol` varchar(10)
,`name` varchar(255)
,`close_price` decimal(15,2)
,`variation_percent` decimal(10,4)
,`volume` bigint(20)
,`trading_date` date
);

-- --------------------------------------------------------

--
-- Doublure de structure pour la vue `top_losers`
-- (Voir ci-dessous la vue réelle)
--
CREATE TABLE `top_losers` (
`symbol` varchar(10)
,`name` varchar(255)
,`close_price` decimal(15,2)
,`variation_percent` decimal(10,4)
,`volume` bigint(20)
,`trading_date` date
);

-- --------------------------------------------------------

--
-- Doublure de structure pour la vue `volume_leaders`
-- (Voir ci-dessous la vue réelle)
--
CREATE TABLE `volume_leaders` (
`symbol` varchar(10)
,`name` varchar(255)
,`close_price` decimal(15,2)
,`volume` bigint(20)
,`turnover` decimal(20,2)
,`variation_percent` decimal(10,4)
,`trading_date` date
);

-- --------------------------------------------------------

--
-- Structure de la vue `latest_quotes`
--
DROP TABLE IF EXISTS `latest_quotes`;

CREATE ALGORITHM=UNDEFINED DEFINER=`brimmobi`@`localhost` SQL SECURITY DEFINER VIEW `latest_quotes`  AS SELECT `c`.`id` AS `company_id`, `c`.`symbol` AS `symbol`, `c`.`name` AS `name`, `s`.`name` AS `sector`, `sq`.`trading_date` AS `trading_date`, `sq`.`open_price` AS `open_price`, `sq`.`close_price` AS `close_price`, `sq`.`high_price` AS `high_price`, `sq`.`low_price` AS `low_price`, `sq`.`previous_close` AS `previous_close`, `sq`.`volume` AS `volume`, `sq`.`variation_percent` AS `variation_percent`, `sq`.`turnover` AS `turnover` FROM ((`companies` `c` left join `sectors` `s` on((`c`.`sector_id` = `s`.`id`))) left join `stock_quotes` `sq` on((`c`.`id` = `sq`.`company_id`))) WHERE ((`sq`.`trading_date` = (select max(`stock_quotes`.`trading_date`) from `stock_quotes` where (`stock_quotes`.`company_id` = `c`.`id`))) AND (`c`.`active` = 1)) ORDER BY `c`.`symbol` ASC  ;

-- --------------------------------------------------------

--
-- Structure de la vue `top_gainers`
--
DROP TABLE IF EXISTS `top_gainers`;

CREATE ALGORITHM=UNDEFINED DEFINER=`brimmobi`@`localhost` SQL SECURITY DEFINER VIEW `top_gainers`  AS SELECT `c`.`symbol` AS `symbol`, `c`.`name` AS `name`, `sq`.`close_price` AS `close_price`, `sq`.`variation_percent` AS `variation_percent`, `sq`.`volume` AS `volume`, `sq`.`trading_date` AS `trading_date` FROM (`stock_quotes` `sq` join `companies` `c` on((`sq`.`company_id` = `c`.`id`))) WHERE ((`sq`.`trading_date` = (select max(`stock_quotes`.`trading_date`) from `stock_quotes`)) AND (`sq`.`variation_percent` > 0) AND (`c`.`active` = 1)) ORDER BY `sq`.`variation_percent` DESC LIMIT 0, 1010  ;

-- --------------------------------------------------------

--
-- Structure de la vue `top_losers`
--
DROP TABLE IF EXISTS `top_losers`;

CREATE ALGORITHM=UNDEFINED DEFINER=`brimmobi`@`localhost` SQL SECURITY DEFINER VIEW `top_losers`  AS SELECT `c`.`symbol` AS `symbol`, `c`.`name` AS `name`, `sq`.`close_price` AS `close_price`, `sq`.`variation_percent` AS `variation_percent`, `sq`.`volume` AS `volume`, `sq`.`trading_date` AS `trading_date` FROM (`stock_quotes` `sq` join `companies` `c` on((`sq`.`company_id` = `c`.`id`))) WHERE ((`sq`.`trading_date` = (select max(`stock_quotes`.`trading_date`) from `stock_quotes`)) AND (`sq`.`variation_percent` < 0) AND (`c`.`active` = 1)) ORDER BY `sq`.`variation_percent` ASC LIMIT 0, 1010  ;

-- --------------------------------------------------------

--
-- Structure de la vue `volume_leaders`
--
DROP TABLE IF EXISTS `volume_leaders`;

CREATE ALGORITHM=UNDEFINED DEFINER=`brimmobi`@`localhost` SQL SECURITY DEFINER VIEW `volume_leaders`  AS SELECT `c`.`symbol` AS `symbol`, `c`.`name` AS `name`, `sq`.`close_price` AS `close_price`, `sq`.`volume` AS `volume`, `sq`.`turnover` AS `turnover`, `sq`.`variation_percent` AS `variation_percent`, `sq`.`trading_date` AS `trading_date` FROM (`stock_quotes` `sq` join `companies` `c` on((`sq`.`company_id` = `c`.`id`))) WHERE ((`sq`.`trading_date` = (select max(`stock_quotes`.`trading_date`) from `stock_quotes`)) AND (`c`.`active` = 1)) ORDER BY `sq`.`volume` DESC LIMIT 0, 1010  ;

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `admin_sessions`
--
ALTER TABLE `admin_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Index pour la table `admin_users`
--
ALTER TABLE `admin_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Index pour la table `combined_analyses`
--
ALTER TABLE `combined_analyses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_comparison` (`request_hash`,`provider`,`model`,`computed_date`);

--
-- Index pour la table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `symbol` (`symbol`),
  ADD UNIQUE KEY `uk_brvm_report_slug` (`brvm_report_slug`),
  ADD KEY `idx_symbol` (`symbol`),
  ADD KEY `idx_sector` (`sector_id`),
  ADD KEY `idx_country` (`country_id`),
  ADD KEY `idx_active` (`active`),
  ADD KEY `idx_companies_sector_active` (`sector_id`,`active`);

--
-- Index pour la table `company_reports`
--
ALTER TABLE `company_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_file_url` (`file_url`(255)),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `idx_publish_date` (`publish_date`),
  ADD KEY `idx_report_type` (`report_type`);

--
-- Index pour la table `company_report_analyses`
--
ALTER TABLE `company_report_analyses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_report_provider_model_date` (`report_id`,`provider`,`model`,`market_context_date`),
  ADD KEY `idx_company` (`company_id`);

--
-- Index pour la table `company_report_comparisons`
--
ALTER TABLE `company_report_comparisons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_comparison` (`request_hash`,`provider`,`model`,`computed_date`);

--
-- Index pour la table `company_report_contents`
--
ALTER TABLE `company_report_contents`
  ADD PRIMARY KEY (`report_id`);

--
-- Index pour la table `countries`
--
ALTER TABLE `countries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_code` (`code`);

--
-- Index pour la table `index_composition`
--
ALTER TABLE `index_composition`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_index_company` (`index_id`,`company_id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `idx_active` (`active`);

--
-- Index pour la table `index_values`
--
ALTER TABLE `index_values`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_index_date` (`index_id`,`trading_date`),
  ADD KEY `idx_trading_date` (`trading_date`);

--
-- Index pour la table `intraday_quotes`
--
ALTER TABLE `intraday_quotes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_datetime` (`company_id`,`quote_datetime`),
  ADD KEY `idx_datetime` (`quote_datetime`);

--
-- Index pour la table `market_bulletins`
--
ALTER TABLE `market_bulletins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_publish_date` (`publish_date`),
  ADD UNIQUE KEY `uk_file_url` (`file_url`(255));

--
-- Index pour la table `market_bulletin_analyses`
--
ALTER TABLE `market_bulletin_analyses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_bulletin_provider_model` (`bulletin_id`,`provider`,`model`);

--
-- Index pour la table `market_bulletin_comparisons`
--
ALTER TABLE `market_bulletin_comparisons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_comparison` (`request_hash`,`provider`,`model`,`computed_date`);

--
-- Index pour la table `market_bulletin_contents`
--
ALTER TABLE `market_bulletin_contents`
  ADD PRIMARY KEY (`bulletin_id`);

--
-- Index pour la table `market_indices`
--
ALTER TABLE `market_indices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_code` (`code`);

--
-- Index pour la table `price_alerts`
--
ALTER TABLE `price_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_active` (`company_id`,`active`),
  ADD KEY `idx_triggered` (`triggered`);

--
-- Index pour la table `schema_migrations`
--
ALTER TABLE `schema_migrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `migration` (`migration`);

--
-- Index pour la table `sectors`
--
ALTER TABLE `sectors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_active` (`active`);

--
-- Index pour la table `stock_quotes`
--
ALTER TABLE `stock_quotes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_company_date` (`company_id`,`trading_date`),
  ADD KEY `idx_trading_date` (`trading_date`),
  ADD KEY `idx_company_id` (`company_id`),
  ADD KEY `idx_close_price` (`close_price`),
  ADD KEY `idx_volume` (`volume`),
  ADD KEY `idx_variation` (`variation_percent`),
  ADD KEY `idx_quotes_date_range` (`company_id`,`trading_date`,`close_price`),
  ADD KEY `idx_quotes_performance` (`trading_date`,`variation_percent`);

--
-- Index pour la table `sync_logs`
--
ALTER TABLE `sync_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sync_type` (`sync_type`),
  ADD KEY `idx_sync_status` (`sync_status`),
  ADD KEY `idx_started_at` (`started_at`);

--
-- Index pour la table `system_config`
--
ALTER TABLE `system_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `config_key` (`config_key`),
  ADD KEY `idx_key` (`config_key`);

--
-- Index pour la table `technical_indicators`
--
ALTER TABLE `technical_indicators`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_company_date` (`company_id`,`trading_date`),
  ADD KEY `idx_trading_date` (`trading_date`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `admin_sessions`
--
ALTER TABLE `admin_sessions`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT pour la table `admin_users`
--
ALTER TABLE `admin_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `combined_analyses`
--
ALTER TABLE `combined_analyses`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT pour la table `company_reports`
--
ALTER TABLE `company_reports`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=365;

--
-- AUTO_INCREMENT pour la table `company_report_analyses`
--
ALTER TABLE `company_report_analyses`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `company_report_comparisons`
--
ALTER TABLE `company_report_comparisons`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `countries`
--
ALTER TABLE `countries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT pour la table `index_composition`
--
ALTER TABLE `index_composition`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `index_values`
--
ALTER TABLE `index_values`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT pour la table `intraday_quotes`
--
ALTER TABLE `intraday_quotes`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2210;

--
-- AUTO_INCREMENT pour la table `market_bulletins`
--
ALTER TABLE `market_bulletins`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT pour la table `market_bulletin_analyses`
--
ALTER TABLE `market_bulletin_analyses`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `market_bulletin_comparisons`
--
ALTER TABLE `market_bulletin_comparisons`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `market_indices`
--
ALTER TABLE `market_indices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT pour la table `price_alerts`
--
ALTER TABLE `price_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `schema_migrations`
--
ALTER TABLE `schema_migrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT pour la table `sectors`
--
ALTER TABLE `sectors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT pour la table `stock_quotes`
--
ALTER TABLE `stock_quotes`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT pour la table `sync_logs`
--
ALTER TABLE `sync_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=95;

--
-- AUTO_INCREMENT pour la table `system_config`
--
ALTER TABLE `system_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT pour la table `technical_indicators`
--
ALTER TABLE `technical_indicators`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `admin_sessions`
--
ALTER TABLE `admin_sessions`
  ADD CONSTRAINT `admin_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `companies`
--
ALTER TABLE `companies`
  ADD CONSTRAINT `companies_ibfk_1` FOREIGN KEY (`sector_id`) REFERENCES `sectors` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `companies_ibfk_2` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `company_reports`
--
ALTER TABLE `company_reports`
  ADD CONSTRAINT `company_reports_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `company_report_analyses`
--
ALTER TABLE `company_report_analyses`
  ADD CONSTRAINT `company_report_analyses_ibfk_1` FOREIGN KEY (`report_id`) REFERENCES `company_reports` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `company_report_analyses_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `company_report_contents`
--
ALTER TABLE `company_report_contents`
  ADD CONSTRAINT `company_report_contents_ibfk_1` FOREIGN KEY (`report_id`) REFERENCES `company_reports` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `index_composition`
--
ALTER TABLE `index_composition`
  ADD CONSTRAINT `index_composition_ibfk_1` FOREIGN KEY (`index_id`) REFERENCES `market_indices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `index_composition_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `index_values`
--
ALTER TABLE `index_values`
  ADD CONSTRAINT `index_values_ibfk_1` FOREIGN KEY (`index_id`) REFERENCES `market_indices` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `intraday_quotes`
--
ALTER TABLE `intraday_quotes`
  ADD CONSTRAINT `intraday_quotes_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `market_bulletin_analyses`
--
ALTER TABLE `market_bulletin_analyses`
  ADD CONSTRAINT `market_bulletin_analyses_ibfk_1` FOREIGN KEY (`bulletin_id`) REFERENCES `market_bulletins` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `market_bulletin_contents`
--
ALTER TABLE `market_bulletin_contents`
  ADD CONSTRAINT `market_bulletin_contents_ibfk_1` FOREIGN KEY (`bulletin_id`) REFERENCES `market_bulletins` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `price_alerts`
--
ALTER TABLE `price_alerts`
  ADD CONSTRAINT `price_alerts_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `stock_quotes`
--
ALTER TABLE `stock_quotes`
  ADD CONSTRAINT `stock_quotes_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `technical_indicators`
--
ALTER TABLE `technical_indicators`
  ADD CONSTRAINT `technical_indicators_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
