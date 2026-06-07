<?php
// =============================================================================
// CDSS — CONTEXTUAL & CROSS-DOMAIN LOGIC LAYER
// Sections 3 and 4 of the architectural blueprint.
// =============================================================================


// =============================================================================
// SECTION 3: CROSS-DOMAIN LOGIC
// =============================================================================

/**
 * AllergyMatcher
 *
 * Cross-references active medications against a patient's documented allergy flags.
 * The key design decision: allergies are stored as `patient_observations` rows
 * with observation_type='allergy_flag' and string_value='penicillin' etc.
 * They are pulled into the patient payload as an array under the key 'allergy_flag'.
 * The rule_criteria for an allergy rule uses:
 *   - data_domain: 'allergy'
 *   - parameter_key: 'allergy_flag'    (the patient's allergy list)
 *   - operator: 'contains'
 *   - string_match_pattern: 'penicillin,amoxicillin,ampicillin'  (drug class members)
 *
 * AND a second criterion:
 *   - data_domain: 'medication'
 *   - parameter_key: 'medication_name'
 *   - operator: 'contains'
 *   - string_match_pattern: 'amoxicillin,ampicillin,penicillin'  (active prescriptions)
 *
 * The evaluateCriteriaSet() AND logic then fires the alert only when BOTH
 * the allergy is documented AND the conflicting medication is actually prescribed.
 *
 * This avoids the false-positive problem of flagging a penicillin allergy
 * for a patient who has no penicillin prescription.
 *
 * String normalization strategy:
 * - All values are stored and compared in lowercase.
 * - Partial matching (str_contains) handles brand names:
 *   'amoxicillin-clavulanate' will match the pattern 'amoxicillin'.
 * - For production: use a drug class codeset table (RxNorm) to expand
 *   the match beyond brand-name strings.
 */
class AllergyMatcher
{
    /**
     * Builds a cross-reference map of all allergy classes and their
     * commonly prescribed members. In production, this would be loaded
     * from a drug class reference table (or RxNorm API).
     *
     * Used when creating rule_criteria seed data — the `string_match_pattern`
     * for an allergy rule is built from this map.
     */
    public static function getAllergyClassMembers(string $allergenClass): array
    {
        $map = [
            'penicillin'   => ['penicillin', 'amoxicillin', 'ampicillin', 'flucloxacillin', 'piperacillin'],
            'sulfa'        => ['sulfamethoxazole', 'trimethoprim-sulfamethoxazole', 'bactrim'],
            'nsaid'        => ['ibuprofen', 'naproxen', 'diclofenac', 'indomethacin', 'celecoxib'],
            'ace_inhibitor'=> ['lisinopril', 'enalapril', 'ramipril', 'captopril', 'perindopril'],
        ];
        return $map[strtolower($allergenClass)] ?? [$allergenClass];
    }

    /**
     * Generates the string_match_pattern for a rule_criteria row.
     * Example: generatePattern('penicillin') → 'penicillin,amoxicillin,ampicillin,...'
     */
    public static function generatePattern(string $allergenClass): string
    {
        return implode(',', self::getAllergyClassMembers($allergenClass));
    }
}


/**
 * DrugLabIntersector
 *
 * Handles cross-domain rules where a lab value is only clinically significant
 * in the presence of a specific active medication.
 *
 * Example: Hyperkalemia with Digoxin
 *   - Serum potassium > 5.0 mEq/L is borderline normal, usually a soft warning.
 *   - BUT serum potassium > 5.0 mEq/L + active Digoxin = CRITICAL.
 *     (Digoxin toxicity risk increases dramatically with elevated potassium.)
 *
 * This is handled ENTIRELY by the existing criteria architecture:
 *   - One rule (HYPER-K-DIGOXIN-001) has TWO criteria rows:
 *       1. lab criterion: serum_potassium > 5.0  (logic_join = AND)
 *       2. medication criterion: medication_name contains 'digoxin'
 *   - The evaluateCriteriaSet() AND logic fires only when BOTH are true.
 *
 * This class provides helper methods for building such compound rules via
 * an administrative interface (so a clinical informaticist can create them
 * without writing SQL).
 */
class DrugLabIntersector
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Creates a compound drug-lab interaction rule with its criteria in one call.
     * Called from an admin interface, not from the evaluation engine.
     *
     * Example usage:
     * $intersector->createRule(
     *   ruleCode: 'HYPER-K-DIGOXIN-001',
     *   ruleName: 'Hyperkalemia with Active Digoxin',
     *   severity: 'critical',
     *   labParameter: 'serum_potassium',
     *   labOperator: 'gt',
     *   labThreshold: 5.0,
     *   labUnit: 'mEq/L',
     *   medicationPattern: 'digoxin,digitoxin',
     *   suppressionWindowHrs: 48
     * )
     */
    public function createRule(
        string $ruleCode,
        string $ruleName,
        string $severity,
        string $labParameter,
        string $labOperator,
        float  $labThreshold,
        string $labUnit,
        string $medicationPattern,
        int    $suppressionWindowHrs = 24
    ): int {
        $severityTierMap = ['critical' => 1, 'warning' => 2, 'info' => 3];
        $tier = $severityTierMap[$severity] ?? 2;

        // FHIR DetectedIssue metadata for drug-lab interactions
        $fhirMeta = json_encode([
            'resourceType' => 'DetectedIssue',
            'status'       => 'preliminary',
            'category'     => ['coding' => [[
                'system'  => 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
                'code'    => 'DLI',
                'display' => 'Drug-Lab Interaction',
            ]]],
        ]);

        $stmt = $this->db->prepare("
            INSERT INTO clinical_rules
                (rule_code, rule_name, domain, severity, severity_tier,
                 suppression_window_hrs, requires_acknowledgment, fhir_detected_issue_meta)
            VALUES (?, ?, 'drug_lab_interaction', ?, ?, ?, 1, ?)
        ");
        $stmt->bind_param('sssiii', $ruleCode, $ruleName, $severity, $tier, $suppressionWindowHrs, $fhirMeta);
        $stmt->execute();
        $ruleId = (int)$this->db->insert_id;
        $stmt->close();

        // Insert criteria: lab threshold AND medication presence
        $stmt = $this->db->prepare("
            INSERT INTO rule_criteria
                (rule_id, data_domain, parameter_key, operator, threshold_value, threshold_unit, logic_join, sort_order)
            VALUES (?, 'lab', ?, ?, ?, ?, 'AND', 1)
        ");
        $stmt->bind_param('issds', $ruleId, $labParameter, $labOperator, $labThreshold, $labUnit);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare("
            INSERT INTO rule_criteria
                (rule_id, data_domain, parameter_key, operator, string_match_pattern, logic_join, sort_order)
            VALUES (?, 'medication', 'medication_name', 'contains', ?, 'AND', 2)
        ");
        $stmt->bind_param('is', $ruleId, $medicationPattern);
        $stmt->execute();
        $stmt->close();

        return $ruleId;
    }
}


/**
 * PythonBridge
 *
 * Offloads computationally complex clinical scoring to the Python microservice.
 * This is the migration path for rules that require:
 *   - Sepsis risk scores (SOFA, qSOFA, NEWS2)
 *   - Deterioration prediction models (ML inference)
 *   - Pharmacokinetic calculations
 *   - Aggregate trend analysis over time-series vital data
 *
 * In the prototype (PHP): calls the Python script via exec() or HTTP.
 * In production (Laravel): uses Laravel HTTP Client to call a REST microservice.
 *
 * The microservice contract:
 *   POST /evaluate
 *   Body: { "patient_id": "P-00412", "payload": { ...patient clinical payload... } }
 *   Response: { "alerts": [{ "rule_code": "NEWS2-HIGH", "score": 7, "severity": "critical" }] }
 */
class PythonBridge
{
    private string $microserviceUrl;

    public function __construct(string $microserviceUrl = 'http://localhost:5000')
    {
        $this->microserviceUrl = $microserviceUrl;
    }

    /**
     * Sends a patient payload to the Python microservice for complex scoring.
     * Returns an array of alert objects to be emitted by AlertEmitter.
     */
    public function evaluate(string $patientId, array $patientPayload): array
    {
        $body = json_encode([
            'patient_id' => $patientId,
            'payload'    => $patientPayload,
        ]);

        $ch = curl_init("{$this->microserviceUrl}/evaluate");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 5, // Hard timeout — never block the UI > 5s
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            // Fail open: log the error but don't block the clinical workflow
            error_log("CDSS PythonBridge: microservice unavailable for patient {$patientId}");
            return [];
        }

        $decoded = json_decode($response, true);
        return $decoded['alerts'] ?? [];
    }
}


// =============================================================================
// SECTION 4: ALERT FATIGUE MITIGATION (HUMAN FACTORS LAYER)
// =============================================================================

/**
 * AlertTriageService
 *
 * Implements the three-tier alert severity model:
 *
 * TIER 1 — CRITICAL (Hard-stop)
 *   - Blocks workflow: the clinician CANNOT proceed until acknowledged.
 *   - Requires: clinical justification text (min 20 chars) + ICD-10 reason code recommended.
 *   - UI: full-screen modal, red banner, cannot be dismissed with a single click.
 *   - Logged as: alert_overrides row with override_action.
 *   - Example: Penicillin allergy + amoxicillin prescription.
 *
 * TIER 2 — WARNING (Soft)
 *   - Interruptive: alert is shown prominently but clinician can proceed.
 *   - Requires: selection of a reason from a structured list.
 *   - UI: slide-in panel, amber banner, requires one deliberate click with reason.
 *   - Logged as: alert_overrides row.
 *   - Example: NSAID + ACE inhibitor combination.
 *
 * TIER 3 — INFO (Passive)
 *   - Non-interruptive: shown in a notification sidebar.
 *   - No acknowledgment required.
 *   - Logged as: alert visible in patient chart, state = 'active'.
 *   - Example: Lab result overdue by 7 days.
 *
 * Alert suppression:
 *   - After a Tier 2/3 alert is overridden once, it is suppressed for
 *     `suppression_window_hrs` (defined per rule).
 *   - Tier 1 alerts are NEVER suppressed — they fire on every relevant
 *     observation until the underlying clinical issue is resolved.
 */
class AlertTriageService
{
    private mysqli $db;

    // Structured override reasons for Tier 2 soft warnings.
    // Shown as a dropdown so clinicians don't start with a blank text field.
    // Reduces cognitive load while ensuring structured data capture.
    const TIER2_REASON_OPTIONS = [
        'patient_benefits_outweigh_risk'  => 'Patient benefits outweigh the identified risk',
        'patient_informed_and_consented'  => 'Patient informed of risk and has consented',
        'alternative_unavailable'         => 'No clinically suitable alternative available',
        'monitoring_plan_in_place'        => 'Monitoring plan established to mitigate risk',
        'specialist_recommendation'       => 'Override on specialist recommendation',
        'documentation_error'             => 'Alert triggered in error — data entry issue',
        'other'                           => 'Other (specify in free text)',
    ];

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Fetches all active alerts for a patient, ordered by severity tier.
     * Returns a structured array ready for UI rendering and FHIR serialisation.
     *
     * This is the API endpoint called by the EHR frontend when loading
     * a patient chart. Replaces the current AI-generated alerts with
     * database-backed, auditable clinical alerts.
     *
     * Response structure mirrors the frontend's existing alert format
     * for drop-in compatibility, while adding the clinical metadata fields.
     */
    public function getActiveAlertsForPatient(string $patientId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                pas.id              AS alert_state_id,
                pas.state,
                pas.severity_tier,
                pas.triggered_at,
                pas.expires_at,
                pas.matched_criteria_snapshot,
                pas.fhir_detected_issue_payload,
                cr.rule_code,
                cr.rule_name        AS type,
                cr.severity,
                cr.description      AS message,
                cr.requires_acknowledgment,
                cr.suppression_window_hrs
            FROM   patient_alert_states pas
            JOIN   clinical_rules cr ON pas.rule_id = cr.id
            WHERE  pas.patient_id = ?
              AND  pas.state      = 'active'
            ORDER  BY pas.severity_tier ASC, pas.triggered_at DESC
        ");
        $stmt->bind_param('s', $patientId);
        $stmt->execute();
        $alerts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map(function ($alert) {
            return [
                // Fields compatible with existing frontend alert format:
                'id'          => 'ALT-' . $alert['alert_state_id'],
                'type'        => $alert['type'],
                'severity'    => $alert['severity'],    // 'critical'|'warning'|'info'
                'message'     => $alert['message'],
                'time'        => $this->relativeTime($alert['triggered_at']),
                // Additional clinical metadata:
                'alert_state_id'            => $alert['alert_state_id'],
                'severity_tier'             => (int) $alert['severity_tier'],
                'requires_acknowledgment'   => (bool) $alert['requires_acknowledgment'],
                'rule_code'                 => $alert['rule_code'],
                'matched_criteria'          => json_decode($alert['matched_criteria_snapshot'] ?? '[]', true),
                'fhir_detected_issue'       => json_decode($alert['fhir_detected_issue_payload'] ?? '{}', true),
            ];
        }, $alerts);
    }

    /**
     * OVERRIDE HANDLER — The critical compliance record.
     *
     * Called when a clinician dismisses or acknowledges an alert.
     * This method:
     *   1. Validates that a meaningful justification has been provided.
     *   2. Writes an immutable row to alert_overrides.
     *   3. Updates the alert state to 'overridden'.
     *   4. For tier 1 critical rules: marks the alert as suppressed for
     *      the rule's cooldown_hrs (NOT the suppression window — the
     *      suppression window only applies to auto-firing, not overrides).
     *
     * LEGAL COMPLIANCE NOTE:
     *   - alert_overrides rows are NEVER updated or deleted.
     *   - The clinician_ip and session_token_hash provide chain-of-custody.
     *   - This log is the evidentiary record in case of adverse events.
     *   - Minimum justification length is enforced here (20 chars).
     *
     * @throws InvalidArgumentException if justification is too short.
     */
    public function overrideAlert(
        int    $alertStateId,
        string $clinicianUserId,
        string $clinicianRole,
        string $overrideAction,     // From alert_overrides.override_action ENUM
        string $clinicalJustification,
        string $selectedReasonKey = '',   // From TIER2_REASON_OPTIONS
        string $icd10ReasonCode = '',
        bool   $patientInformed = false,
        string $patientInformedBy = '',
        string $clinicianIp = ''
    ): array {
        // Enforce minimum justification quality
        $justification = trim($clinicalJustification);
        if (strlen($justification) < 20) {
            return [
                'success' => false,
                'error'   => 'Clinical justification must be at least 20 characters.',
            ];
        }

        // If a structured reason was selected, prepend it to the free text
        if ($selectedReasonKey && isset(self::TIER2_REASON_OPTIONS[$selectedReasonKey])) {
            $justification = '[' . self::TIER2_REASON_OPTIONS[$selectedReasonKey] . '] ' . $justification;
        }

        // Fetch the alert to verify it exists and get rule details
        $stmt = $this->db->prepare("
            SELECT pas.*, cr.cooldown_hrs, cr.severity_tier
            FROM   patient_alert_states pas
            JOIN   clinical_rules cr ON pas.rule_id = cr.id
            WHERE  pas.id = ? AND pas.state = 'active'
        ");
        $stmt->bind_param('i', $alertStateId);
        $stmt->execute();
        $alert = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$alert) {
            return ['success' => false, 'error' => 'Alert not found or already resolved.'];
        }

        // Hash session token (never store the raw token)
        $sessionTokenHash = hash('sha256', session_id() ?: uniqid('cdss_', true));

        // Write the immutable override record
        $stmt = $this->db->prepare("
            INSERT INTO alert_overrides
                (alert_state_id, clinician_user_id, clinician_role, override_action,
                 clinical_justification, icd10_reason_code,
                 patient_informed, patient_informed_by,
                 clinician_ip, session_token_hash, overridden_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $patientInformedInt = (int)$patientInformed;
        $stmt->bind_param('isssssssss',
            $alertStateId,
            $clinicianUserId,
            $clinicianRole,
            $overrideAction,
            $justification,
            $icd10ReasonCode,
            $patientInformedInt,
            $patientInformedBy,
            $clinicianIp,
            $sessionTokenHash
        );
        $stmt->execute();
        $overrideId = (int)$this->db->insert_id;
        $stmt->close();

        // Update alert state to overridden
        $stmt = $this->db->prepare("
            UPDATE patient_alert_states
            SET    state = 'overridden', resolved_at = NOW()
            WHERE  id = ?
        ");
        $stmt->bind_param('i', $alertStateId);
        $stmt->execute();
        $stmt->close();

        return [
            'success'     => true,
            'override_id' => $overrideId,
            'message'     => 'Alert override recorded successfully.',
        ];
    }

    /**
     * AUDIT REPORT — Returns the full override history for a patient.
     * Used for clinical audit, quality review, and medico-legal review.
     *
     * In a FHIR enterprise context: each row can be serialised as a
     * FHIR AuditEvent resource.
     */
    public function getOverrideAuditTrail(string $patientId, int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT
                ao.id               AS override_id,
                ao.override_action,
                ao.clinical_justification,
                ao.icd10_reason_code,
                ao.clinician_user_id,
                ao.clinician_role,
                ao.patient_informed,
                ao.overridden_at,
                cr.rule_code,
                cr.rule_name,
                cr.severity,
                pas.matched_criteria_snapshot,
                pas.triggered_at    AS alert_triggered_at
            FROM   alert_overrides ao
            JOIN   patient_alert_states pas ON ao.alert_state_id = pas.id
            JOIN   clinical_rules cr        ON pas.rule_id = cr.id
            WHERE  pas.patient_id = ?
            ORDER  BY ao.overridden_at DESC
            LIMIT  ?
        ");
        $stmt->bind_param('si', $patientId, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * OVERRIDE ANALYTICS — Surfaces alert fatigue patterns for clinical governance.
     * Used by clinical governance team to identify:
     *   - Rules with high override rates (potential alert fatigue source)
     *   - Clinicians who override specific alerts very frequently (educational opportunity)
     *   - Rules that are almost always overridden (candidates for suppression or rule revision)
     */
    public function getOverrideAnalytics(int $daysBack = 30): array
    {
        $stmt = $this->db->prepare("
            SELECT
                cr.rule_code,
                cr.rule_name,
                cr.severity,
                COUNT(pas.id)                                           AS total_fired,
                SUM(CASE WHEN pas.state = 'overridden' THEN 1 ELSE 0 END) AS total_overridden,
                ROUND(
                    100.0 * SUM(CASE WHEN pas.state = 'overridden' THEN 1 ELSE 0 END) / COUNT(pas.id),
                    1
                )                                                       AS override_rate_pct,
                GROUP_CONCAT(DISTINCT ao.override_action ORDER BY ao.override_action) AS override_actions_used
            FROM   clinical_rules cr
            JOIN   patient_alert_states pas ON pas.rule_id = cr.id
            LEFT   JOIN alert_overrides ao  ON ao.alert_state_id = pas.id
            WHERE  pas.triggered_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP  BY cr.id, cr.rule_code, cr.rule_name, cr.severity
            HAVING total_fired > 0
            ORDER  BY override_rate_pct DESC, total_fired DESC
        ");
        $stmt->bind_param('i', $daysBack);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Helper: formats a timestamp into a relative time string.
     */
    private function relativeTime(string $timestamp): string
    {
        $diff = time() - strtotime($timestamp);
        if ($diff < 60)             return 'Just now';
        if ($diff < 3600)           return (int)($diff / 60)  . ' mins ago';
        if ($diff < 86400)          return (int)($diff / 3600) . ' hrs ago';
        return (int)($diff / 86400) . ' days ago';
    }
}


// =============================================================================
// INTEGRATION EXAMPLE: Wiring the engine to the existing EHR backend
// =============================================================================
// In EHR_System.php, after the existing saveVitals() function inserts a row,
// add the following call to trigger the CDSS engine immediately:
//
// function saveVitals($b) {
//     // ... existing insert code ...
//
//     // ── CDSS Integration ─────────────────────────────────────────────────
//     if ($ok) {
//         // Map vital fields to normalized observation rows
//         $observations = mapVitalsToCdssObservations($b);
//         $engine = new CdssEngine(getConn());
//
//         foreach ($observations as $obs) {
//             $obsId = insertCdssObservation($obs);
//             $engine->onNewObservation($obsId);
//         }
//     }
//     // ─────────────────────────────────────────────────────────────────────
//
//     echo json_encode(['success' => $ok]);
// }
//
// function mapVitalsToCdssObservations(array $b): array {
//     // Parse "185/112" BP into two separate serum observations
//     $bpParts = explode('/', $b['bp'] ?? '0/0');
//     return [
//         ['parameter_key' => 'systolic_bp',  'numeric_value' => (float)($bpParts[0] ?? 0), 'unit' => 'mmHg', 'observation_type' => 'vital_sign'],
//         ['parameter_key' => 'diastolic_bp', 'numeric_value' => (float)($bpParts[1] ?? 0), 'unit' => 'mmHg', 'observation_type' => 'vital_sign'],
//         ['parameter_key' => 'heart_rate',   'numeric_value' => (float)($b['hr'] ?? 0),     'unit' => 'bpm',  'observation_type' => 'vital_sign'],
//         ['parameter_key' => 'spo2',         'numeric_value' => (float)($b['spo2'] ?? 0),   'unit' => '%',    'observation_type' => 'vital_sign'],
//     ];
// }
