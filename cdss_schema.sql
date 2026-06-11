-- =============================================================================
-- CDSS ENGINE — FULL DATABASE SCHEMA
-- Stack: MySQL 8.0+, InnoDB, utf8mb4
-- FHIR Alignment: Observation (vitals/labs) + DetectedIssue (alerts)
-- Migration Path: Compatible with Laravel Eloquent ORM (snake_case, timestamps)
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `healthcare_cdss`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `healthcare_cdss`;

-- =============================================================================
-- TABLE: clinical_rules
-- Stores every clinical decision rule as a data row, not hardcoded logic.
-- FHIR alignment: maps to DetectedIssue.code (the category of the issue).
-- =============================================================================
CREATE TABLE IF NOT EXISTS `clinical_rules` (
    `id`                        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `rule_code`                 VARCHAR(50)     NOT NULL COMMENT 'Unique machine-readable code, e.g. HYPER-K-DIGOXIN-001',
    `rule_name`                 VARCHAR(200)    NOT NULL COMMENT 'Human-readable name shown in UI',
    `description`               TEXT            NULL     COMMENT 'Clinical rationale for this rule',
    `domain`                    ENUM(
                                    'vital_sign',
                                    'lab_result',
                                    'medication',
                                    'allergy_interaction',
                                    'drug_lab_interaction',
                                    'drug_drug_interaction',
                                    'composite'
                                )               NOT NULL DEFAULT 'vital_sign',
    `severity`                  ENUM(
                                    'critical',   -- Hard-stop: blocks workflow
                                    'warning',    -- Soft: requires acknowledgment
                                    'info'        -- Passive: logged only
                                )               NOT NULL DEFAULT 'warning',
    `severity_tier`             TINYINT UNSIGNED NOT NULL DEFAULT 2
                                COMMENT '1=critical, 2=warning, 3=info — numeric for sorting',
    `is_active`                 TINYINT(1)      NOT NULL DEFAULT 1,
    `suppression_window_hrs`    INT UNSIGNED    NOT NULL DEFAULT 24
                                COMMENT 'Hours to suppress duplicate alerts after one fires',
    `cooldown_hrs`              INT UNSIGNED    NOT NULL DEFAULT 0
                                COMMENT 'Hours before the same rule can re-fire for same patient',
    `requires_acknowledgment`   TINYINT(1)      NOT NULL DEFAULT 0
                                COMMENT '1 = clinician must provide justification to dismiss',
    `fhir_detected_issue_meta`  JSON            NULL
                                COMMENT 'FHIR DetectedIssue resource metadata: category, code, severity mappings',
    -- Example fhir_detected_issue_meta:
    -- {
    --   "resourceType": "DetectedIssue",
    --   "status": "preliminary",
    --   "category": { "coding": [{ "system": "http://terminology.hl7.org/CodeSystem/v3-ActCode", "code": "DI", "display": "Drug Interaction" }] },
    --   "severity": "high",
    --   "code": { "coding": [{ "system": "http://snomed.info/sct", "code": "79899007", "display": "Drug interaction" }] }
    -- }
    `created_at`                TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rule_code` (`rule_code`),
    KEY `idx_domain_active` (`domain`, `is_active`),
    KEY `idx_severity_active` (`severity`, `is_active`)
) ENGINE=InnoDB COMMENT='Each row is one clinical decision rule. No logic is hardcoded.';


-- =============================================================================
-- TABLE: rule_criteria
-- Stores the individual threshold/matching conditions for each rule.
-- Multiple rows per rule, joined by logic_join (AND/OR).
-- This is what the Rule Evaluation Engine iterates over.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `rule_criteria` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `rule_id`               INT UNSIGNED    NOT NULL,
    `data_domain`           ENUM(
                                'vital',
                                'lab',
                                'medication',
                                'allergy',
                                'demographic'
                            )               NOT NULL,
    `parameter_key`         VARCHAR(100)    NOT NULL
                            COMMENT 'Matches a key in patient_observations.parameter_key, e.g. serum_potassium, systolic_bp, medication_name',
    `operator`              ENUM(
                                'gt',           -- greater than
                                'gte',          -- greater than or equal
                                'lt',           -- less than
                                'lte',          -- less than or equal
                                'eq',           -- equals (numeric)
                                'neq',          -- not equals
                                'contains',     -- string LIKE match
                                'not_contains', -- string NOT LIKE match
                                'in_set',       -- value in a defined codeset
                                'between'       -- range check (uses threshold_value + threshold_value2)
                            )               NOT NULL,
    `threshold_value`       DECIMAL(12, 4)  NULL COMMENT 'Primary numeric threshold',
    `threshold_value2`      DECIMAL(12, 4)  NULL COMMENT 'Secondary threshold for BETWEEN operator',
    `threshold_unit`        VARCHAR(30)     NULL COMMENT 'e.g. mEq/L, mmHg, bpm',
    `string_match_pattern`  VARCHAR(500)    NULL COMMENT 'For contains/in_set operators. Comma-separated or regex pattern.',
    `logic_join`            ENUM('AND','OR') NOT NULL DEFAULT 'AND'
                            COMMENT 'How this criterion joins with the NEXT criterion in this rule',
    `sort_order`            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_rule_id` (`rule_id`),
    CONSTRAINT `fk_rc_rule` FOREIGN KEY (`rule_id`) REFERENCES `clinical_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Individual evaluation conditions for each clinical rule.';


-- =============================================================================
-- TABLE: patient_observations
-- Normalised store for all observable patient data: vitals, labs, medications.
-- FHIR alignment: mirrors the FHIR Observation resource structure.
-- This replaces the current fragmented vitals/lab_results/medications tables
-- as the single source of truth the engine evaluates against.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `patient_observations` (
    `id`                        INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `patient_id`                VARCHAR(20)         NOT NULL,
    `observation_type`          ENUM(
                                    'vital_sign',
                                    'lab_result',
                                    'medication_active',
                                    'allergy_flag',
                                    'clinical_note'
                                )                   NOT NULL,
    `loinc_code`                VARCHAR(20)         NULL    COMMENT 'LOINC code for interoperability, e.g. 2823-3 for Serum Potassium',
    `parameter_key`             VARCHAR(100)        NOT NULL COMMENT 'Normalized snake_case key, e.g. serum_potassium, systolic_bp',
    `numeric_value`             DECIMAL(12, 4)      NULL,
    `string_value`              VARCHAR(500)        NULL    COMMENT 'For meds, allergies, or categorical results',
    `unit`                      VARCHAR(30)         NULL,
    `reference_range_low`       DECIMAL(12, 4)      NULL,
    `reference_range_high`      DECIMAL(12, 4)      NULL,
    `status`                    ENUM(
                                    'final',
                                    'preliminary',
                                    'entered-in-error',
                                    'amended'
                                )                   NOT NULL DEFAULT 'final',
    `recorded_by`               VARCHAR(100)        NULL,
    `fhir_observation_payload`  JSON                NULL
                                COMMENT 'Full FHIR R4 Observation resource JSON for enterprise integration',
    -- Example fhir_observation_payload:
    -- {
    --   "resourceType": "Observation",
    --   "status": "final",
    --   "code": { "coding": [{ "system": "http://loinc.org", "code": "2823-3", "display": "Potassium [Moles/volume] in Serum" }] },
    --   "valueQuantity": { "value": 6.1, "unit": "mEq/L", "system": "http://unitsofmeasure.org" },
    --   "referenceRange": [{ "low": { "value": 3.5 }, "high": { "value": 5.0 } }]
    -- }
    `recorded_at`               TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_patient_param` (`patient_id`, `parameter_key`),
    KEY `idx_patient_type` (`patient_id`, `observation_type`),
    KEY `idx_recorded_at` (`recorded_at`)
) ENGINE=InnoDB COMMENT='FHIR Observation-aligned. All patient data the engine evaluates.';


-- =============================================================================
-- TABLE: patient_alert_states
-- Tracks the lifecycle of every alert: active, resolved, suppressed, overridden.
-- FHIR alignment: maps to DetectedIssue resource.
-- This prevents alert re-firing during suppression windows.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `patient_alert_states` (
    `id`                            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `patient_id`                    VARCHAR(20)     NOT NULL,
    `rule_id`                       INT UNSIGNED    NOT NULL,
    `triggering_observation_id`     INT UNSIGNED    NULL    COMMENT 'The observation row that caused this alert to fire',
    `state`                         ENUM(
                                        'active',       -- Alert is live and visible to clinicians
                                        'resolved',     -- Underlying condition resolved automatically
                                        'overridden',   -- Clinician dismissed with justification
                                        'suppressed',   -- Within suppression window; won't re-fire
                                        'expired'       -- Passed expiry without action
                                    )               NOT NULL DEFAULT 'active',
    `severity_tier`                 TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT 'Denormalized from rule for fast queries',
    `matched_criteria_snapshot`     JSON            NULL
                                    COMMENT 'Snapshot of the exact criteria values that triggered this alert at the time of firing',
    `fhir_detected_issue_payload`   JSON            NULL
                                    COMMENT 'Full FHIR R4 DetectedIssue JSON for enterprise integration',
    -- Example fhir_detected_issue_payload:
    -- {
    --   "resourceType": "DetectedIssue",
    --   "status": "final",
    --   "severity": "high",
    --   "patient": { "reference": "Patient/P-00412" },
    --   "implicated": [{ "reference": "MedicationStatement/123" }, { "reference": "Observation/456" }],
    --   "detail": "Serum potassium 6.1 mEq/L with active Digoxin prescription. Risk of cardiac arrhythmia."
    -- }
    `triggered_at`                  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved_at`                   TIMESTAMP       NULL,
    `expires_at`                    TIMESTAMP       NULL    COMMENT 'Computed: triggered_at + rule.suppression_window_hrs',
    PRIMARY KEY (`id`),
    KEY `idx_patient_state` (`patient_id`, `state`),
    KEY `idx_patient_rule` (`patient_id`, `rule_id`),
    KEY `idx_expires` (`expires_at`),
    KEY `idx_severity_state` (`severity_tier`, `state`),
    CONSTRAINT `fk_pas_rule` FOREIGN KEY (`rule_id`) REFERENCES `clinical_rules` (`id`),
    CONSTRAINT `fk_pas_obs`  FOREIGN KEY (`triggering_observation_id`) REFERENCES `patient_observations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='FHIR DetectedIssue-aligned. Full alert lifecycle tracking.';


-- =============================================================================
-- TABLE: alert_overrides
-- Immutable audit log of every clinician dismissal or acknowledgment.
-- This is the legal and compliance record. Rows are NEVER deleted or updated.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `alert_overrides` (
    `id`                        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `alert_state_id`            INT UNSIGNED    NOT NULL,
    `clinician_user_id`         VARCHAR(50)     NOT NULL,
    `clinician_role`            VARCHAR(80)     NULL    COMMENT 'e.g. attending_physician, resident, pharmacist',
    `override_action`           ENUM(
                                    'dismissed',        -- Clinician reviewed and cleared
                                    'acknowledged',     -- Clinician noted; will monitor
                                    'escalated',        -- Clinician escalated to senior
                                    'patient_informed', -- Patient counseled about the risk
                                    'order_cancelled'   -- Underlying order cancelled to resolve
                                )               NOT NULL,
    `clinical_justification`    TEXT            NOT NULL COMMENT 'Free-text mandatory reason. Min 10 chars enforced at app layer.',
    `icd10_reason_code`         VARCHAR(20)     NULL    COMMENT 'Optional structured ICD-10 code for the clinical reason',
    `patient_informed`          TINYINT(1)      NOT NULL DEFAULT 0,
    `patient_informed_by`       VARCHAR(100)    NULL,
    `clinician_ip`              VARCHAR(45)     NULL    COMMENT 'IPv4 or IPv6 for audit trail',
    `session_token_hash`        VARCHAR(255)    NULL    COMMENT 'SHA-256 hash of session token at time of override (not the token itself)',
    `overridden_at`             TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_alert_state` (`alert_state_id`),
    KEY `idx_clinician` (`clinician_user_id`),
    KEY `idx_overridden_at` (`overridden_at`),
    CONSTRAINT `fk_ao_alert` FOREIGN KEY (`alert_state_id`) REFERENCES `patient_alert_states` (`id`)
) ENGINE=InnoDB COMMENT='Immutable override audit log. Never UPDATE or DELETE rows.';


-- =============================================================================
-- SEED DATA: Example clinical rules (illustrative)
-- =============================================================================
INSERT INTO `clinical_rules`
    (`rule_code`, `rule_name`, `domain`, `severity`, `severity_tier`, `suppression_window_hrs`, `cooldown_hrs`, `requires_acknowledgment`)
VALUES
    ('HYPER-K-001',         'Hyperkalemia — Critical',                  'lab_result',           'critical', 1, 48, 12, 1),
    ('HYPER-K-DIGOXIN-001', 'Hyperkalemia with Active Digoxin',         'drug_lab_interaction',  'critical', 1, 48, 12, 1),
    ('HYPERTENSION-001',    'Hypertensive Crisis — BP > 180/120',        'vital_sign',           'critical', 1, 12,  4, 1),
    ('NSAID-ACE-001',       'NSAID + ACE Inhibitor Interaction',         'drug_drug_interaction', 'warning',  2, 72, 24, 1),
    ('PENICILLIN-ALLERGY',  'Penicillin Allergy — Active Prescription',  'allergy_interaction',  'critical', 1,  0,  0, 1),
    ('OVERDUE-LAB-001',     'Overdue Lab Follow-up > 7 Days',            'lab_result',           'info',     3, 24, 24, 0);

-- Criteria for HYPER-K-001: Potassium > 5.5 mEq/L
INSERT INTO `rule_criteria` (`rule_id`, `data_domain`, `parameter_key`, `operator`, `threshold_value`, `threshold_unit`, `logic_join`, `sort_order`)
SELECT id, 'lab', 'serum_potassium', 'gt', 5.5, 'mEq/L', 'AND', 1 FROM `clinical_rules` WHERE `rule_code` = 'HYPER-K-001';

-- Criteria for HYPER-K-DIGOXIN-001: Potassium > 5.0 AND active Digoxin
INSERT INTO `rule_criteria` (`rule_id`, `data_domain`, `parameter_key`, `operator`, `threshold_value`, `threshold_unit`, `logic_join`, `sort_order`)
SELECT id, 'lab', 'serum_potassium', 'gt', 5.0, 'mEq/L', 'AND', 1 FROM `clinical_rules` WHERE `rule_code` = 'HYPER-K-DIGOXIN-001';
INSERT INTO `rule_criteria` (`rule_id`, `data_domain`, `parameter_key`, `operator`, `string_match_pattern`, `logic_join`, `sort_order`)
SELECT id, 'medication', 'medication_name', 'contains', 'digoxin', 'AND', 2 FROM `clinical_rules` WHERE `rule_code` = 'HYPER-K-DIGOXIN-001';

-- Criteria for HYPERTENSION-001: Systolic > 180 OR Diastolic > 120
INSERT INTO `rule_criteria` (`rule_id`, `data_domain`, `parameter_key`, `operator`, `threshold_value`, `threshold_unit`, `logic_join`, `sort_order`)
SELECT id, 'vital', 'systolic_bp', 'gt', 180, 'mmHg', 'OR', 1 FROM `clinical_rules` WHERE `rule_code` = 'HYPERTENSION-001';
INSERT INTO `rule_criteria` (`rule_id`, `data_domain`, `parameter_key`, `operator`, `threshold_value`, `threshold_unit`, `logic_join`, `sort_order`)
SELECT id, 'vital', 'diastolic_bp', 'gt', 120, 'mmHg', 'AND', 2 FROM `clinical_rules` WHERE `rule_code` = 'HYPERTENSION-001';

-- =============================================================================
-- EXTENDED SEED DATA: Lab + Vital rules for glucose, haemoglobin, cholesterol,
-- SpO2, HR, temperature, electrolytes, and renal/haematology panels.
-- All inserts use INSERT IGNORE to be idempotent on re-run.
-- =============================================================================

-- ── Additional clinical rules ─────────────────────────────────────────────────
INSERT IGNORE INTO `clinical_rules`
    (`rule_code`, `rule_name`, `domain`, `severity`, `severity_tier`,
     `suppression_window_hrs`, `cooldown_hrs`, `requires_acknowledgment`, `description`)
VALUES
    ('TACHYCARDIA-001',       'Critical Tachycardia',              'vital_sign',  'critical', 1, 12,  4, 1, 'Heart rate > 150 bpm — evaluate for arrhythmia or haemodynamic instability.'),
    ('BRADYCARDIA-001',       'Critical Bradycardia',              'vital_sign',  'critical', 1, 12,  4, 1, 'Heart rate < 40 bpm — risk of haemodynamic compromise.'),
    ('HYPOXIA-001',           'Critical Hypoxia',                  'vital_sign',  'critical', 1, 12,  4, 1, 'SpO₂ < 90% — supplemental O₂ urgently required.'),
    ('HYPERTHERMIA-001',      'Hyperthermia / Fever',              'vital_sign',  'warning',  2, 24,  8, 0, 'Temperature > 38.5 °C — evaluate for infection or sepsis.'),
    ('HYPOTHERMIA-001',       'Hypothermia',                       'vital_sign',  'warning',  2, 24,  8, 0, 'Temperature < 35 °C — active warming and monitoring required.'),
    ('GLUCOSE-CRITICAL-LOW',  'Severe Hypoglycaemia',              'lab_result',  'critical', 1, 24, 12, 1, 'Glucose < 54 mg/dL — administer glucose immediately.'),
    ('GLUCOSE-LOW-001',       'Hypoglycaemia',                     'lab_result',  'warning',  2, 24,  8, 1, 'Glucose < 70 mg/dL — monitor closely and consider carbohydrate supplementation.'),
    ('GLUCOSE-HIGH-001',      'Hyperglycaemia',                    'lab_result',  'warning',  2, 24,  8, 0, 'Glucose > 200 mg/dL — review diabetic management plan.'),
    ('GLUCOSE-CRITICAL-HIGH', 'Critical Hyperglycaemia',           'lab_result',  'critical', 1, 24, 12, 1, 'Glucose > 500 mg/dL — risk of DKA. Urgent review required.'),
    ('HGB-CRITICAL-LOW',      'Critical Anaemia',                  'lab_result',  'critical', 1, 24, 12, 1, 'Haemoglobin < 7 g/dL — consider urgent transfusion.'),
    ('HGB-LOW-001',           'Anaemia',                           'lab_result',  'warning',  2, 24,  8, 0, 'Haemoglobin < 12 g/dL — evaluate for cause of anaemia.'),
    ('CHOL-HIGH-001',         'High Total Cholesterol',            'lab_result',  'warning',  2, 48, 24, 0, 'Total Cholesterol > 200 mg/dL — review cardiovascular risk and lipid therapy.'),
    ('CHOL-CRITICAL-001',     'Critically High Cholesterol',       'lab_result',  'critical', 1, 48, 24, 1, 'Total Cholesterol > 300 mg/dL — significantly elevated cardiovascular risk.'),
    ('HYPO-K-001',            'Hypokalaemia',                      'lab_result',  'warning',  2, 24,  8, 1, 'Serum Potassium < 3.5 mEq/L — arrhythmia risk; potassium replacement indicated.'),
    ('SODIUM-HIGH-001',       'Hypernatraemia',                    'lab_result',  'warning',  2, 24,  8, 0, 'Serum Sodium > 150 mEq/L — evaluate for dehydration.'),
    ('SODIUM-LOW-001',        'Hyponatraemia',                     'lab_result',  'warning',  2, 24,  8, 0, 'Serum Sodium < 130 mEq/L — monitor for neurological symptoms.'),
    ('CREATININE-HIGH-001',   'Elevated Creatinine',               'lab_result',  'warning',  2, 24,  8, 0, 'Creatinine > 1.5 mg/dL — evaluate renal function.'),
    ('WBC-HIGH-001',          'Leukocytosis',                      'lab_result',  'warning',  2, 24,  8, 0, 'WBC > 11 × 10³/µL — evaluate for infection or inflammation.'),
    ('WBC-LOW-001',           'Leukopenia',                        'lab_result',  'warning',  2, 24,  8, 0, 'WBC < 4 × 10³/µL — evaluate for immunosuppression or haematological disorder.'),
    ('PLATELETS-LOW-001',     'Thrombocytopenia',                  'lab_result',  'critical', 1, 24, 12, 1, 'Platelets < 50 × 10³/µL — bleeding risk; review medications.');

-- ── Criteria for new rules ────────────────────────────────────────────────────
INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'vital','heart_rate','gt',150,'bpm','AND',1 FROM `clinical_rules` WHERE `rule_code`='TACHYCARDIA-001';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'vital','heart_rate','lt',40,'bpm','AND',1 FROM `clinical_rules` WHERE `rule_code`='BRADYCARDIA-001';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'vital','spo2','lt',90,'%','AND',1 FROM `clinical_rules` WHERE `rule_code`='HYPOXIA-001';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'vital','body_temperature','gt',38.5,'C','AND',1 FROM `clinical_rules` WHERE `rule_code`='HYPERTHERMIA-001';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'vital','body_temperature','lt',35,'C','AND',1 FROM `clinical_rules` WHERE `rule_code`='HYPOTHERMIA-001';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'lab','glucose','lt',54,'mg/dL','AND',1 FROM `clinical_rules` WHERE `rule_code`='GLUCOSE-CRITICAL-LOW';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'lab','glucose','lt',70,'mg/dL','AND',1 FROM `clinical_rules` WHERE `rule_code`='GLUCOSE-LOW-001';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'lab','glucose','gt',200,'mg/dL','AND',1 FROM `clinical_rules` WHERE `rule_code`='GLUCOSE-HIGH-001';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'lab','glucose','gt',500,'mg/dL','AND',1 FROM `clinical_rules` WHERE `rule_code`='GLUCOSE-CRITICAL-HIGH';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'lab','hemoglobin','lt',7,'g/dL','AND',1 FROM `clinical_rules` WHERE `rule_code`='HGB-CRITICAL-LOW';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'lab','hemoglobin','lt',12,'g/dL','AND',1 FROM `clinical_rules` WHERE `rule_code`='HGB-LOW-001';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'lab','total_cholesterol','gt',200,'mg/dL','AND',1 FROM `clinical_rules` WHERE `rule_code`='CHOL-HIGH-001';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'lab','total_cholesterol','gt',300,'mg/dL','AND',1 FROM `clinical_rules` WHERE `rule_code`='CHOL-CRITICAL-001';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'lab','serum_potassium','lt',3.5,'mEq/L','AND',1 FROM `clinical_rules` WHERE `rule_code`='HYPO-K-001';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'lab','serum_sodium','gt',150,'mEq/L','AND',1 FROM `clinical_rules` WHERE `rule_code`='SODIUM-HIGH-001';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'lab','serum_sodium','lt',130,'mEq/L','AND',1 FROM `clinical_rules` WHERE `rule_code`='SODIUM-LOW-001';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'lab','creatinine','gt',1.5,'mg/dL','AND',1 FROM `clinical_rules` WHERE `rule_code`='CREATININE-HIGH-001';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'lab','wbc','gt',11,'x10³/µL','AND',1 FROM `clinical_rules` WHERE `rule_code`='WBC-HIGH-001';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'lab','wbc','lt',4,'x10³/µL','AND',1 FROM `clinical_rules` WHERE `rule_code`='WBC-LOW-001';

INSERT IGNORE INTO `rule_criteria` (`rule_id`,`data_domain`,`parameter_key`,`operator`,`threshold_value`,`threshold_unit`,`logic_join`,`sort_order`)
SELECT id,'lab','platelets','lt',50,'x10³/µL','AND',1 FROM `clinical_rules` WHERE `rule_code`='PLATELETS-LOW-001';
