-- Add hospital scoping to staff accounts in the currently selected database.
-- Safe to run more than once on PostgreSQL.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS hospital VARCHAR(120) NOT NULL DEFAULT 'General Hospital';

CREATE INDEX IF NOT EXISTS idx_hospital ON users (hospital);

UPDATE users
SET hospital = 'Med-Alex Central'
WHERE username IN ('admin_user', 'dr_smith', 'nurse_jones');
