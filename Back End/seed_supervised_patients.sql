-- =============================================================================
-- SEED: Sample patients assigned to existing staff (Dr. Sarah Smith /
-- Nurse Emily Jones) with observations that trigger the CDSS engine.
--
-- Run this AFTER EHR_System.php has been loaded at least once (so the
-- `users`, `patients`, and `patient_observations` tables, including the
-- `physician_id` FK on `patients`, already exist).
--
-- Idempotent: uses INSERT IGNORE / ON DUPLICATE KEY where sensible, and
-- predictable patient_ids (P-90001, P-90002) so re-running is safe.
-- =============================================================================

-- NOTE: All tables (users, patients, timeline, patient_observations,
-- clinical_rules, etc.) live in the `healthcare_ehr` database as created
-- by EHR_System.php's getConn(). Run this against that database
-- (e.g. `USE healthcare_ehr;` first, or pass it on the mysql command line).


-- ── Patient 1: assigned to Dr. Sarah Smith (dr_smith) ───────────────────────
-- Will trigger: HYPER-K-001 (Hyperkalemia), HYPERTENSION-001 (Hypertensive Crisis)
INSERT INTO `patients`
    (patient_id, first_name, last_name, dob, gender, blood_type, phone, email,
     insurance, physician, physician_id, allergies, conditions, smoking, alcohol,
     pmh, fmh, surgical, vaccines, avatar_color, status, last_visit, next_appt)
SELECT
    'P-90001', 'Karim', 'Mostafa', '1968-04-12', 'Male', 'O+', '+20-100-555-0101',
    'karim.mostafa@example.com', 'MedAlex Insurance Co.',
    u.full_name, u.id,
    'Penicillin', 'Chronic Kidney Disease, Hypertension', 'Former smoker', 'Occasional',
    'CKD stage 3, diagnosed 2021', 'Father: hypertension', 'Appendectomy (2005)',
    'Influenza (2025), Tetanus (2022)', '#ef4444', 'active',
    'Jun 09, 2026', 'Jun 20, 2026'
FROM `users` u
WHERE u.full_name = 'Dr. Sarah Smith'
ON DUPLICATE KEY UPDATE
    physician = VALUES(physician),
    physician_id = VALUES(physician_id);

-- Observations for P-90001
INSERT INTO `patient_observations`
    (patient_id, observation_type, loinc_code, parameter_key, numeric_value, unit,
     reference_range_low, reference_range_high, status, recorded_by)
VALUES
    -- Serum potassium 6.1 mEq/L -> triggers HYPER-K-001 (> 5.5)
    ('P-90001', 'lab_result', '2823-3', 'serum_potassium', 6.1, 'mEq/L', 3.5, 5.0, 'final', 'Dr. Sarah Smith'),
    -- BP 190/130 -> triggers HYPERTENSION-001 (systolic > 180 OR diastolic > 120)
    ('P-90001', 'vital_sign', '8480-6', 'systolic_bp', 190, 'mmHg', 90, 120, 'final', 'Dr. Sarah Smith'),
    ('P-90001', 'vital_sign', '8462-4', 'diastolic_bp', 130, 'mmHg', 60, 80, 'final', 'Dr. Sarah Smith'),
    -- Baseline vitals (no alert)
    ('P-90001', 'vital_sign', '8867-4', 'heart_rate', 88, 'bpm', 60, 100, 'final', 'Dr. Sarah Smith'),
    ('P-90001', 'vital_sign', '8310-5', 'body_temperature', 37.1, 'C', 36.1, 37.2, 'final', 'Dr. Sarah Smith');

-- Timeline entry for P-90001
INSERT INTO `timeline` (patient_id, entry_date, dot_type, entry_text)
SELECT 'P-90001', DATE_FORMAT(NOW(), '%d %b %Y'), 'warn',
       '<strong>Lab Review</strong> — Elevated potassium and blood pressure flagged for follow-up.'
WHERE EXISTS (SELECT 1 FROM `patients` WHERE patient_id = 'P-90001');


-- ── Patient 2: assigned to Nurse Emily Jones (nurse_jones) ──────────────────
-- Will trigger: TACHYCARDIA-001 (Critical Tachycardia), HYPOXIA-001 (Critical Hypoxia)
INSERT INTO `patients`
    (patient_id, first_name, last_name, dob, gender, blood_type, phone, email,
     insurance, physician, physician_id, allergies, conditions, smoking, alcohol,
     pmh, fmh, surgical, vaccines, avatar_color, status, last_visit, next_appt)
SELECT
    'P-90002', 'Nourhan', 'Ezzat', '1990-11-03', 'Female', 'A-', '+20-100-555-0102',
    'nourhan.ezzat@example.com', 'Pharos Health Plan',
    u.full_name, u.id,
    'None known', 'Asthma', 'Never smoked', 'None',
    'Asthma since childhood', 'Mother: asthma', 'None',
    'COVID-19 booster (2025)', '#10b981', 'active',
    'Jun 11, 2026', 'Jun 25, 2026'
FROM `users` u
WHERE u.full_name = 'Nurse Emily Jones'
ON DUPLICATE KEY UPDATE
    physician = VALUES(physician),
    physician_id = VALUES(physician_id);

-- Observations for P-90002
INSERT INTO `patient_observations`
    (patient_id, observation_type, loinc_code, parameter_key, numeric_value, unit,
     reference_range_low, reference_range_high, status, recorded_by)
VALUES
    -- Heart rate 162 bpm -> triggers TACHYCARDIA-001 (> 150)
    ('P-90002', 'vital_sign', '8867-4', 'heart_rate', 162, 'bpm', 60, 100, 'final', 'Nurse Emily Jones'),
    -- SpO2 86% -> triggers HYPOXIA-001 (< 90)
    ('P-90002', 'vital_sign', '59408-5', 'spo2', 86, '%', 95, 100, 'final', 'Nurse Emily Jones'),
    -- Baseline glucose (no alert)
    ('P-90002', 'lab_result', '2345-7', 'glucose', 98, 'mg/dL', 70, 100, 'final', 'Nurse Emily Jones'),
    ('P-90002', 'vital_sign', '8310-5', 'body_temperature', 37.0, 'C', 36.1, 37.2, 'final', 'Nurse Emily Jones');

-- Timeline entry for P-90002
INSERT INTO `timeline` (patient_id, entry_date, dot_type, entry_text)
SELECT 'P-90002', DATE_FORMAT(NOW(), '%d %b %Y'), 'alert',
       '<strong>Vitals Check</strong> — Tachycardia and low SpO2 detected during routine monitoring.'
WHERE EXISTS (SELECT 1 FROM `patients` WHERE patient_id = 'P-90002');
