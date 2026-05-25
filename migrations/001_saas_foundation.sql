-- ============================================================
-- 001_saas_foundation.sql — fatturapp SaaS Foundation
-- ============================================================
-- Aggiunge le tabelle SaaS e i campi tenant_id alle tabelle
-- business esistenti. NON modifica né rimuove colonne esistenti.
--
-- Eseguire come root:
--   mysql -u root -p fatturazione < migrations/001_saas_foundation.sql
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ============================================================
-- SEZIONE 1 — NUOVE TABELLE SAAS
-- ============================================================

-- ------------------------------------------------------------
-- 1a. Piani abbonamento
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `saas_plans` (
  `id`                INT(11)       NOT NULL AUTO_INCREMENT,
  `name`              VARCHAR(50)   NOT NULL UNIQUE          COMMENT 'Slug: free | pro',
  `display_name`      VARCHAR(100)  NOT NULL,
  `price_monthly`     DECIMAL(10,2) NOT NULL DEFAULT 0.00    COMMENT 'Prezzo in EUR/mese (0 = gratuito)',
  `max_fatture_mese`  INT(11)       NULL DEFAULT NULL        COMMENT 'NULL = illimitato',
  `max_clienti`       INT(11)       NULL DEFAULT NULL        COMMENT 'NULL = illimitato',
  `trial_days`        INT(11)       NOT NULL DEFAULT 30      COMMENT 'Giorni di trial (0 = nessun trial)',
  `is_active`         TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Piani abbonamento fatturapp SaaS';

INSERT IGNORE INTO `saas_plans`
  (`name`, `display_name`, `price_monthly`, `max_fatture_mese`, `max_clienti`, `trial_days`)
VALUES
  ('free', 'Free',  0.00,  3,    2,    0),
  ('pro',  'Pro',   7.00,  NULL, NULL, 30);


-- ------------------------------------------------------------
-- 1b. Tenant — un account SaaS registrato = un tenant
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `saas_tenants` (
  `id`            INT(11)   NOT NULL AUTO_INCREMENT,
  `owner_user_id` INT(11)   NOT NULL  COMMENT 'Utente principale del tenant (tb_utenti.id_utente)',
  `status`        ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Un record per ogni cliente SaaS registrato';

-- Tenant 1 = dati esistenti (owner = admin, id_utente = 1)
INSERT IGNORE INTO `saas_tenants` (`id`, `owner_user_id`, `status`)
VALUES (1, 1, 'active');


-- ------------------------------------------------------------
-- 1c. Abbonamenti
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `saas_subscriptions` (
  `id`                     INT(11)      NOT NULL AUTO_INCREMENT,
  `tenant_id`              INT(11)      NOT NULL,
  `plan_id`                INT(11)      NOT NULL,
  `status`                 ENUM('trial','active','expired','cancelled') NOT NULL DEFAULT 'trial',
  `trial_ends_at`          TIMESTAMP    NULL DEFAULT NULL,
  `current_period_start`   DATE         NULL DEFAULT NULL,
  `current_period_end`     DATE         NULL DEFAULT NULL,
  `payment_provider`       ENUM('paypal','stripe','none') NOT NULL DEFAULT 'none',
  `paypal_subscription_id` VARCHAR(255) NULL DEFAULT NULL  COMMENT 'ID piano PayPal ricorrente (I-xxx)',
  `paypal_order_id`        VARCHAR(255) NULL DEFAULT NULL  COMMENT 'ID ultimo ordine/capture PayPal',
  `cancelled_at`           TIMESTAMP    NULL DEFAULT NULL,
  `created_at`             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sub_tenant` (`tenant_id`),
  KEY `idx_sub_plan`   (`plan_id`),
  CONSTRAINT `fk_sub_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `saas_tenants`(`id`),
  CONSTRAINT `fk_sub_plan`   FOREIGN KEY (`plan_id`)   REFERENCES `saas_plans`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Abbonamento attivo per ogni tenant';

-- Tenant 1 già su piano Pro, attivo, nessun trial
INSERT IGNORE INTO `saas_subscriptions`
  (`tenant_id`, `plan_id`, `status`, `payment_provider`)
VALUES
  (1, 2, 'active', 'none');


-- ------------------------------------------------------------
-- 1d. Storico pagamenti
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `saas_payments` (
  `id`                  INT(11)       NOT NULL AUTO_INCREMENT,
  `tenant_id`           INT(11)       NOT NULL,
  `subscription_id`     INT(11)       NOT NULL,
  `amount`              DECIMAL(10,2) NOT NULL,
  `currency`            CHAR(3)       NOT NULL DEFAULT 'EUR',
  `payment_provider`    VARCHAR(50)   NOT NULL DEFAULT 'paypal',
  `provider_payment_id` VARCHAR(255)  NULL DEFAULT NULL  COMMENT 'PayPal capture/order ID',
  `status`              ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
  `paid_at`             TIMESTAMP     NULL DEFAULT NULL,
  `created_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pay_tenant` (`tenant_id`),
  KEY `idx_pay_sub`    (`subscription_id`),
  CONSTRAINT `fk_pay_tenant` FOREIGN KEY (`tenant_id`)       REFERENCES `saas_tenants`(`id`),
  CONSTRAINT `fk_pay_sub`    FOREIGN KEY (`subscription_id`) REFERENCES `saas_subscriptions`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Pagamenti PayPal ricevuti';


-- ------------------------------------------------------------
-- 1e. Settings per tenant
--     Multi-tenant di tb_impostazioni (ogni tenant ha i suoi
--     progressivi fattura e prefisso indipendenti)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `saas_tenant_settings` (
  `id`        INT(11)      NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11)      NOT NULL,
  `chiave`    VARCHAR(50)  NOT NULL,
  `valore`    VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_chiave` (`tenant_id`, `chiave`),
  CONSTRAINT `fk_tset_tenant` FOREIGN KEY (`tenant_id`)
    REFERENCES `saas_tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Impostazioni per-tenant (progressivo fattura, prefisso, ecc.)';

-- Copia le impostazioni globali esistenti come settings del tenant 1
INSERT IGNORE INTO `saas_tenant_settings` (`tenant_id`, `chiave`, `valore`)
SELECT 1, `chiave`, `valore` FROM `tb_impostazioni`;


-- ------------------------------------------------------------
-- 1f. Richieste export GDPR
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `saas_gdpr_exports` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `tenant_id`        INT(11)      NOT NULL,
  `requested_by`     INT(11)      NOT NULL  COMMENT 'tb_utenti.id_utente',
  `status`           ENUM('pending','processing','ready','expired') NOT NULL DEFAULT 'pending',
  `download_token`   VARCHAR(128) NULL DEFAULT NULL UNIQUE,
  `token_expires_at` TIMESTAMP    NULL DEFAULT NULL,
  `requested_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at`     TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_gdpr_tenant` (`tenant_id`),
  CONSTRAINT `fk_gdpr_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `saas_tenants`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Richieste export dati utente (GDPR Art.20)';


-- ============================================================
-- SEZIONE 2 — AGGIUNTE ALLE TABELLE BUSINESS ESISTENTI
--             Solo ADD COLUMN e ADD KEY, mai DROP/MODIFY
-- ============================================================

-- tb_utenti: tenant di appartenenza + campi registrazione/reset
ALTER TABLE `tb_utenti`
  ADD COLUMN IF NOT EXISTS `tenant_id`               INT(11)      NULL DEFAULT NULL       AFTER `data_creazione`,
  ADD COLUMN IF NOT EXISTS `email_verified_at`        TIMESTAMP    NULL DEFAULT NULL       AFTER `tenant_id`,
  ADD COLUMN IF NOT EXISTS `verification_token`       VARCHAR(128) NULL DEFAULT NULL       AFTER `email_verified_at`,
  ADD COLUMN IF NOT EXISTS `verification_token_exp`   TIMESTAMP    NULL DEFAULT NULL       AFTER `verification_token`,
  ADD COLUMN IF NOT EXISTS `reset_token`              VARCHAR(128) NULL DEFAULT NULL       AFTER `verification_token_exp`,
  ADD COLUMN IF NOT EXISTS `reset_token_exp`          TIMESTAMP    NULL DEFAULT NULL       AFTER `reset_token`;

-- Utenti esistenti: già verificati (account manuale precedente)
UPDATE `tb_utenti` SET `email_verified_at` = NOW() WHERE `email_verified_at` IS NULL;

ALTER TABLE `tb_anagrafiche`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NULL DEFAULT NULL AFTER `tasse_percentuale`;

ALTER TABLE `tb_clienti`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NULL DEFAULT NULL AFTER `SDI`;

ALTER TABLE `tb_progetti`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NULL DEFAULT NULL AFTER `id_cliente`;

ALTER TABLE `tb_fatture`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NULL DEFAULT NULL AFTER `data_creazione`;

ALTER TABLE `tb_fatture_dettaglio`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NULL DEFAULT NULL AFTER `subtotale`;

ALTER TABLE `tb_fatture_elettroniche`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NULL DEFAULT NULL AFTER `note`;

ALTER TABLE `tb_impostazioni`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NULL DEFAULT NULL AFTER `descrizione`;

ALTER TABLE `tb_ore_lavoro`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NULL DEFAULT NULL AFTER `data_inserimento`;

-- Indici sulle nuove colonne (separati per evitare errori se già esistono)
ALTER TABLE `tb_utenti`              ADD KEY IF NOT EXISTS `idx_utenti_tenant`      (`tenant_id`);
ALTER TABLE `tb_anagrafiche`         ADD KEY IF NOT EXISTS `idx_anagr_tenant`       (`tenant_id`);
ALTER TABLE `tb_clienti`             ADD KEY IF NOT EXISTS `idx_clienti_tenant`     (`tenant_id`);
ALTER TABLE `tb_progetti`            ADD KEY IF NOT EXISTS `idx_proj_tenant`        (`tenant_id`);
ALTER TABLE `tb_fatture`             ADD KEY IF NOT EXISTS `idx_fatt_tenant`        (`tenant_id`);
ALTER TABLE `tb_fatture_dettaglio`   ADD KEY IF NOT EXISTS `idx_fatt_det_tenant`   (`tenant_id`);
ALTER TABLE `tb_fatture_elettroniche` ADD KEY IF NOT EXISTS `idx_fatt_el_tenant`   (`tenant_id`);
ALTER TABLE `tb_impostazioni`        ADD KEY IF NOT EXISTS `idx_impost_tenant`      (`tenant_id`);
ALTER TABLE `tb_ore_lavoro`          ADD KEY IF NOT EXISTS `idx_ore_tenant`         (`tenant_id`);


-- ============================================================
-- SEZIONE 3 — ASSEGNAZIONE DATI ESISTENTI AL TENANT 1
-- ============================================================
UPDATE `tb_utenti`              SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
UPDATE `tb_anagrafiche`         SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
UPDATE `tb_clienti`             SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
UPDATE `tb_progetti`            SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
UPDATE `tb_fatture`             SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
UPDATE `tb_fatture_dettaglio`   SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
UPDATE `tb_fatture_elettroniche` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
UPDATE `tb_impostazioni`        SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
UPDATE `tb_ore_lavoro`          SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;

SET foreign_key_checks = 1;

-- ============================================================
-- VERIFICA FINALE
-- ============================================================
SELECT 'saas_plans'            AS tabella, COUNT(*) AS righe FROM saas_plans
UNION ALL
SELECT 'saas_tenants',          COUNT(*) FROM saas_tenants
UNION ALL
SELECT 'saas_subscriptions',    COUNT(*) FROM saas_subscriptions
UNION ALL
SELECT 'saas_tenant_settings',  COUNT(*) FROM saas_tenant_settings
UNION ALL
SELECT 'tb_utenti (con tenant_id)', COUNT(*) FROM tb_utenti WHERE tenant_id IS NOT NULL;
