-- =============================================================================
-- Med-Alex HIS  —  Add `hospital` to users (multi-tenant scoping for admins)
--
-- Why this is needed:
--   The admin dashboard lets an admin manage doctors/nurses "in their own
--   hospital". Until now, `users` had no concept of which hospital a staff
--   member belongs to — every admin could see every user. This migration
--   adds that column and scopes the relevant queries.
--
-- Safe to re-run: uses the same information_schema-guard pattern as the
-- original rbac_migration.sql.
-- =============================================================================

USE `healthcare_ehr`;

DROP PROCEDURE IF EXISTS `AddHospitalColumnIfMissing`;

DELIMITER $$
CREATE PROCEDURE `AddHospitalColumnIfMissing`()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM   information_schema.COLUMNS
        WHERE  TABLE_SCHEMA = 'healthcare_ehr'
          AND  TABLE_NAME   = 'users'
          AND  COLUMN_NAME  = 'hospital'
    ) THEN
        ALTER TABLE `users`
            ADD COLUMN `hospital` VARCHAR(120) NOT NULL DEFAULT 'General Hospital'
                COMMENT 'Hospital/site this staff member belongs to — scopes admin user-management'
            AFTER `role`;

        -- Index it: every admin user-list query filters by hospital
        ALTER TABLE `users` ADD KEY `idx_hospital` (`hospital`);
    END IF;
END$$
DELIMITER ;

CALL `AddHospitalColumnIfMissing`();
DROP PROCEDURE IF EXISTS `AddHospitalColumnIfMissing`;


-- -----------------------------------------------------------------------------
-- Backfill / adjust the seed accounts created by rbac_migration.sql so the
-- admin dashboard has something realistic to show immediately.
--
--   admin_user  → Med-Alex Central   (the admin who manages this hospital)
--   dr_smith    → Med-Alex Central   (visible to admin_user)
--   nurse_jones → Med-Alex Central   (visible to admin_user)
--
-- If you have multiple hospitals, create a second admin and assign a
-- different `hospital` string — that admin will only see staff sharing
-- that exact string.
-- -----------------------------------------------------------------------------
UPDATE `users` SET `hospital` = 'Med-Alex Central' WHERE `username` IN ('admin_user', 'dr_smith', 'nurse_jones');
