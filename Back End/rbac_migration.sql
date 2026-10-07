-- =============================================================================
-- Med-Alex HIS  —  RBAC Migration
-- Run this ONCE against your `healthcare_ehr` database.
-- All statements are idempotent-safe: they check existence before altering.
-- =============================================================================

USE `healthcare_ehr`;

-- -----------------------------------------------------------------------------
-- 1. USERS TABLE
--    Stores one account per staff member. `password_hash` holds a bcrypt hash
--    produced by password_hash($plain, PASSWORD_BCRYPT) — never store plaintext.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(80)     NOT NULL,
    `email`         VARCHAR(120)    NOT NULL,
    `password_hash` VARCHAR(255)    NOT NULL  COMMENT 'bcrypt hash via password_hash()',
    `role`          ENUM('admin','doctor','nurse')
                                    NOT NULL  DEFAULT 'nurse'
                                    COMMENT 'RBAC role — controls API access',
    `full_name`     VARCHAR(160)    NULL,
    `is_active`     TINYINT(1)      NOT NULL  DEFAULT 1,
    `created_at`    TIMESTAMP       NOT NULL  DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP       NOT NULL  DEFAULT CURRENT_TIMESTAMP
                                              ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_username` (`username`),
    UNIQUE KEY `uq_email`    (`email`),
    KEY `idx_role`           (`role`)
) ENGINE=InnoDB
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci
  COMMENT='Staff accounts. One row per user. Passwords are bcrypt hashes.';


-- -----------------------------------------------------------------------------
-- 2. ADD `role` COLUMN TO EXISTING TABLE (if the table already existed without it)
--    This ALTER is safe to run even after the CREATE above — IF NOT EXISTS guards it.
--    MySQL 8.0+: ALTER TABLE ... ADD COLUMN IF NOT EXISTS is supported.
--    For MySQL 5.7 compatibility the procedure below checks information_schema first.
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `AddRoleColumnIfMissing`;

DELIMITER $$
CREATE PROCEDURE `AddRoleColumnIfMissing`()
BEGIN
    -- Only ALTER if the column does not already exist
    IF NOT EXISTS (
        SELECT 1
        FROM   information_schema.COLUMNS
        WHERE  TABLE_SCHEMA = 'healthcare_ehr'
          AND  TABLE_NAME   = 'users'
          AND  COLUMN_NAME  = 'role'
    ) THEN
        ALTER TABLE `users`
            ADD COLUMN `role` ENUM('admin','doctor','nurse')
                NOT NULL DEFAULT 'nurse'
                COMMENT 'RBAC role — controls API access'
            AFTER `password_hash`;
    END IF;
END$$
DELIMITER ;

CALL `AddRoleColumnIfMissing`();
DROP PROCEDURE IF EXISTS `AddRoleColumnIfMissing`;


-- -----------------------------------------------------------------------------
-- 3. SEED: one account per role for local development / first run.
--
--    ⚠️  IMPORTANT: The hashes below are NOT valid bcrypt hashes — they are
--    placeholders only and password_verify() will ALWAYS fail against them.
--    Generate REAL hashes first by running this from a terminal:
--
--        php -r "echo password_hash('AdminPass123!',  PASSWORD_BCRYPT, ['cost'=>12]), PHP_EOL;"
--        php -r "echo password_hash('DoctorPass123!', PASSWORD_BCRYPT, ['cost'=>12]), PHP_EOL;"
--        php -r "echo password_hash('NursePass123!',  PASSWORD_BCRYPT, ['cost'=>12]), PHP_EOL;"
--
--    Then paste each generated hash (starts with $2y$12$...) in place of the
--    placeholder strings below before running this migration.
--$2y$12$jxwarz3V6/zdmQ76zeu3.u21UXFFPD3sFlRZe/Hjaw0618rs9/Zoi
--$2y$12$s7izI4cjwtWe2E28.iiMZuO9aak4ykcVpwPKX3RHNl4cHZUh3sXpS
--$2y$12$sJxpxaLugF1kKj19uZP6x.uYGEWwxeIbw/Wl/NrqaMmwtblZnZ8Mu
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO `users` (`username`, `email`, `password_hash`, `role`, `full_name`) VALUES
    ('admin_user',
     'admin@medalex.local',
     'PASTE_REAL_BCRYPT_HASH_FOR_AdminPass123_HERE',
     'admin',
     'System Administrator'),

    ('dr_smith',
     'dr.smith@medalex.local',
     'PASTE_REAL_BCRYPT_HASH_FOR_DoctorPass123_HERE',
     'doctor',
     'Dr. Sarah Smith'),

    ('nurse_jones',
     'nurse.jones@medalex.local',
     'PASTE_REAL_BCRYPT_HASH_FOR_NursePass123_HERE',
     'nurse',
     'Nurse Emily Jones');

-- After running the migration, fix up the hashes with:
--   UPDATE users SET password_hash = '<generated_hash>' WHERE username = '<user>';


-- -----------------------------------------------------------------------------
-- 4. SESSION AUDIT LOG (optional but recommended for a clinical system)
--    Tracks every login / logout event for compliance.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED    NOT NULL,
    `session_token` VARCHAR(64)     NOT NULL  COMMENT 'SHA-256 of PHP session ID',
    `ip_address`    VARCHAR(45)     NULL,
    `user_agent`    VARCHAR(255)    NULL,
    `action`        ENUM('login','logout','expired')
                                    NOT NULL  DEFAULT 'login',
    `created_at`    TIMESTAMP       NOT NULL  DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id`  (`user_id`),
    KEY `idx_token`    (`session_token`),
    CONSTRAINT `fk_us_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci
  COMMENT='Immutable login/logout audit log.';
