<?php
// =============================================================================
// CDSS — RULE EVALUATION ENGINE (PHP Prototype)
// Pattern: Hybrid Event-Observer + Async Batch Worker
//
// Architecture decision:
//   - Tier 1 (critical) + Tier 2 (warning) rules use the IMMEDIATE path.
//     They are evaluated synchronously after every new observation INSERT.
//   - Tier 3 (info) / composite rules use the ASYNC path.
//     A cron job calls CdssWorker::runBatch() every 5–15 minutes.
//
// Migration path to Laravel:
//   - This class maps 1:1 to a Laravel Service class: app/Services/CdssEngine.php
//   - RuleLoader maps to an Eloquent query builder scope.
//   - AlertEmitter maps to a Laravel Event + Listener pair.
//   - The cron job maps to a Laravel Scheduled Command.
//   - Python bridge maps to a Laravel HTTP Client call to the microservice.
// =============================================================================

class CdssEngine
{
    private mysqli $db;
    private array  $ruleCache = [];

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    // =========================================================================
    // ENTRY POINT: Immediate Observer
    // Called immediately after a new observation is inserted.
    // Usage: CdssEngine::onNewObservation($observationId)
    // In Laravel: fired as an Eloquent 'created' model event on PatientObservation
    // =========================================================================
    public function onNewObservation(int $observationId): void
    {
        $observation = $this->fetchObservation($observationId);
        if (!$observation) return;

        // Load only active rules relevant to this observation's domain
        // Filter to tier 1 & 2 (critical, warning) for immediate evaluation
        $rules = $this->loadActiveRules(
            domain: $observation['observation_type'],
            maxTier: 2  // Only critical and warning; tier 3 (info) goes to batch
        );

        $this->evaluateRulesForPatient($observation['patient_id'], $rules, $observation);
    }

    // =========================================================================
    // ENTRY POINT: Async Batch Worker
    // Called by cron job every 5–15 minutes.
    // Evaluates composite rules, tier-3 info alerts, and overdue-check rules.
    // In Laravel: implement as `php artisan cdss:evaluate-batch`
    // =========================================================================
    public function runBatch(): void
    {
        // Fetch all patients with any observation recorded in the last 24 hours
        $recentPatients = $this->fetchPatientsWithRecentActivity(windowHours: 24);

        // Load all active composite and tier-3 rules
        $rules = $this->loadActiveRules(domain: null, minTier: 3);

        foreach ($recentPatients as $patientId) {
            $this->evaluateRulesForPatient($patientId, $rules, observationContext: null);
        }
    }

    // =========================================================================
    // CORE EVALUATION LOOP
    // This is the engine's heart. It iterates each rule, evaluates its criteria,
    // checks suppression, then emits or suppresses the alert.
    // =========================================================================
    private function evaluateRulesForPatient(
        string $patientId,
        array  $rules,
        ?array $observationContext
    ): void {
        // Build the patient's current clinical payload — a flat key-value map
        // of all active observations. This is built ONCE per patient per run.
        $patientPayload = $this->buildPatientPayload($patientId);

        foreach ($rules as $rule) {
            // 1. Check suppression: has this rule already fired for this patient
            //    recently and is still within its suppression window?
            if ($this->isAlertSuppressed($patientId, $rule['id'])) {
                continue; // Skip — alert is still active or within cooldown
            }

            // 2. Evaluate all criteria for this rule
            $criteria = $this->loadCriteriaForRule($rule['id']);
            $ruleMatches = $this->evaluateCriteriaSet($patientPayload, $criteria);

            if ($ruleMatches) {
                // 3. Fire the alert
                $this->emitAlert($patientId, $rule, $criteria, $patientPayload, $observationContext);
            } else {
                // 4. If a previously active alert for this rule is now resolved
                //    (criteria no longer met), auto-resolve it
                $this->resolveIfActive($patientId, $rule['id']);
            }
        }
    }

    // =========================================================================
    // PAYLOAD BUILDER
    // Constructs a flat associative array of the patient's current clinical state.
    // This is the "patient context" the engine evaluates every rule against.
    //
    // FHIR note: This payload is structurally derived from FHIR Observation
    // resources. The JSON columns in patient_observations store the full
    // FHIR payload; the flat array is the engine-optimised evaluation form.
    //
    // Example output:
    // [
    //   'serum_potassium'    => 6.1,
    //   'systolic_bp'        => 185,
    //   'diastolic_bp'       => 112,
    //   'medication_name'    => ['digoxin', 'furosemide', 'losartan'],
    //   'allergy_flag'       => ['penicillin', 'sulfa'],
    // ]
    // =========================================================================
    private function buildPatientPayload(string $patientId): array
    {
        $payload = [];

        // Fetch all active observations for this patient
        $stmt = $this->db->prepare("
            SELECT parameter_key, numeric_value, string_value, observation_type
            FROM   patient_observations
            WHERE  patient_id = ?
              AND  status NOT IN ('entered-in-error')
            ORDER  BY recorded_at DESC
        ");
        $stmt->bind_param('s', $patientId);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($results as $obs) {
            $key = $obs['parameter_key'];

            // Numeric values (vitals, labs): take the MOST RECENT value only
            if ($obs['numeric_value'] !== null && !isset($payload[$key])) {
                $payload[$key] = (float) $obs['numeric_value'];
            }

            // String values (medications, allergies): collect ALL active values
            // into an array, since a patient has multiple active medications
            if ($obs['string_value'] !== null) {
                if (!isset($payload[$key])) {
                    $payload[$key] = [];
                }
                if (is_array($payload[$key])) {
                    $payload[$key][] = strtolower($obs['string_value']);
                }
            }
        }

        return $payload;
    }

    // =========================================================================
    // CRITERIA SET EVALUATOR
    // Iterates rule_criteria rows and evaluates each against the patient payload.
    // Respects the logic_join (AND / OR) between consecutive criteria.
    //
    // The AND/OR logic_join on each criterion refers to the join with the NEXT
    // criterion. The final criterion's join is irrelevant (ignored).
    //
    // This replaces all hardcoded if/else chains with a data-driven loop.
    // =========================================================================
    private function evaluateCriteriaSet(array $payload, array $criteria): bool
    {
        if (empty($criteria)) return false;

        // Sort criteria by sort_order
        usort($criteria, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        $result     = null;  // Accumulates the running boolean result
        $pendingJoin = 'AND'; // Join operator for the next criterion

        foreach ($criteria as $criterion) {
            $criterionResult = $this->evaluateSingleCriterion($payload, $criterion);

            if ($result === null) {
                $result = $criterionResult;
            } elseif ($pendingJoin === 'AND') {
                $result = $result && $criterionResult;
            } else { // OR
                $result = $result || $criterionResult;
            }

            $pendingJoin = $criterion['logic_join'];
        }

        return (bool) $result;
    }

    // =========================================================================
    // SINGLE CRITERION EVALUATOR
    // Evaluates one rule_criteria row against the patient payload.
    // All operator logic lives here — no logic lives in clinical_rules itself.
    // =========================================================================
    private function evaluateSingleCriterion(array $payload, array $criterion): bool
    {
        $key      = $criterion['parameter_key'];
        $operator = $criterion['operator'];
        $value    = $payload[$key] ?? null;

        if ($value === null) return false; // No data = criterion not met

        $threshold = (float) ($criterion['threshold_value'] ?? 0);

        return match ($operator) {
            'gt'          => is_numeric($value) && (float)$value >  $threshold,
            'gte'         => is_numeric($value) && (float)$value >= $threshold,
            'lt'          => is_numeric($value) && (float)$value <  $threshold,
            'lte'         => is_numeric($value) && (float)$value <= $threshold,
            'eq'          => is_numeric($value) && (float)$value == $threshold,
            'neq'         => is_numeric($value) && (float)$value != $threshold,
            'between'     => is_numeric($value)
                             && (float)$value >= $threshold
                             && (float)$value <= (float)($criterion['threshold_value2'] ?? PHP_INT_MAX),
            'contains'    => $this->matchStringValue($value, $criterion['string_match_pattern']),
            'not_contains'=> !$this->matchStringValue($value, $criterion['string_match_pattern']),
            'in_set'      => $this->matchStringValue($value, $criterion['string_match_pattern']),
            default       => false,
        };
    }

    // =========================================================================
    // STRING MATCHER (for medication names, allergies)
    // Supports multi-value payload arrays (e.g. all active medications).
    // pattern can be a comma-separated list: "digoxin,digitoxin"
    // =========================================================================
    private function matchStringValue(mixed $value, ?string $pattern): bool
    {
        if ($pattern === null) return false;

        $patterns = array_map('trim', explode(',', strtolower($pattern)));
        $values   = is_array($value) ? $value : [strtolower((string)$value)];

        foreach ($values as $v) {
            foreach ($patterns as $p) {
                if (str_contains($v, $p)) return true;
            }
        }

        return false;
    }

    // =========================================================================
    // SUPPRESSION CHECK
    // Returns true if a non-expired, non-resolved alert for this rule+patient
    // already exists, preventing alert storm / duplicate firing.
    // =========================================================================
    private function isAlertSuppressed(string $patientId, int $ruleId): bool
    {
        $stmt = $this->db->prepare("
            SELECT id FROM patient_alert_states
            WHERE  patient_id = ?
              AND  rule_id    = ?
              AND  state      IN ('active', 'suppressed')
              AND  (expires_at IS NULL OR expires_at > NOW())
            LIMIT 1
        ");
        $stmt->bind_param('si', $patientId, $ruleId);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();
        return $exists;
    }

    // =========================================================================
    // ALERT EMITTER
    // Creates a new patient_alert_states row with:
    //   - A snapshot of matched criteria values at time of firing (forensic record)
    //   - A full FHIR DetectedIssue JSON payload for enterprise integration
    //   - The computed expiry timestamp (triggered_at + suppression_window_hrs)
    // =========================================================================
    private function emitAlert(
        string $patientId,
        array  $rule,
        array  $criteria,
        array  $patientPayload,
        ?array $triggeringObs
    ): void {
        // Build a snapshot of the exact values that triggered the alert
        $matchedSnapshot = [];
        foreach ($criteria as $c) {
            $key = $c['parameter_key'];
            $matchedSnapshot[] = [
                'parameter_key' => $key,
                'patient_value' => $patientPayload[$key] ?? null,
                'operator'      => $c['operator'],
                'threshold'     => $c['threshold_value'],
                'unit'          => $c['threshold_unit'],
            ];
        }

        // Build FHIR DetectedIssue payload
        $fhirPayload = $this->buildFhirDetectedIssuePayload($patientId, $rule, $matchedSnapshot);

        $snapshotJson = json_encode($matchedSnapshot);
        $fhirJson     = json_encode($fhirPayload);
        $trigObsId    = $triggeringObs['id'] ?? null;
        $severityTier = (int) $rule['severity_tier'];
        $suppressHrs  = (int) $rule['suppression_window_hrs'];

        $stmt = $this->db->prepare("
            INSERT INTO patient_alert_states
                (patient_id, rule_id, triggering_observation_id, state, severity_tier,
                 matched_criteria_snapshot, fhir_detected_issue_payload, triggered_at, expires_at)
            VALUES
                (?, ?, ?, 'active', ?,
                 ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? HOUR))
        ");
        $stmt->bind_param('siiissi',
            $patientId,
            $rule['id'],
            $trigObsId,
            $severityTier,
            $snapshotJson,
            $fhirJson,
            $suppressHrs
        );
        $stmt->execute();
        $stmt->close();

        // In production: fire a real-time notification here
        // e.g. WebSocket push, FCM notification, nurse station pager
        // In Laravel: event(new ClinicalAlertFired($alertStateId, $rule['severity']));
    }

    // =========================================================================
    // AUTO-RESOLVE
    // If a rule's criteria are no longer met but an 'active' alert exists,
    // resolve it automatically (e.g. potassium normalised after treatment).
    // =========================================================================
    private function resolveIfActive(string $patientId, int $ruleId): void
    {
        $stmt = $this->db->prepare("
            UPDATE patient_alert_states
            SET    state       = 'resolved',
                   resolved_at = NOW()
            WHERE  patient_id  = ?
              AND  rule_id     = ?
              AND  state       = 'active'
        ");
        $stmt->bind_param('si', $patientId, $ruleId);
        $stmt->execute();
        $stmt->close();
    }

    // =========================================================================
    // FHIR DetectedIssue Payload Builder
    // Constructs a compliant FHIR R4 DetectedIssue JSON resource.
    // This is stored in patient_alert_states.fhir_detected_issue_payload.
    // In an enterprise integration: POST this to a FHIR server endpoint.
    // =========================================================================
    private function buildFhirDetectedIssuePayload(
        string $patientId,
        array  $rule,
        array  $matchedSnapshot
    ): array {
        $meta = json_decode($rule['fhir_detected_issue_meta'] ?? '{}', true) ?: [];

        return array_merge($meta, [
            'resourceType' => 'DetectedIssue',
            'status'       => 'preliminary',
            'severity'     => match ($rule['severity']) {
                'critical' => 'high',
                'warning'  => 'moderate',
                default    => 'low',
            },
            'patient' => [
                'reference' => "Patient/{$patientId}",
            ],
            'detail'     => "Rule [{$rule['rule_code']}] triggered: {$rule['rule_name']}",
            'identified' => date('c'), // ISO 8601 datetime
            'extension'  => [[
                'url'         => 'https://healthcare-hub.example/fhir/cdss-matched-criteria',
                'valueString' => json_encode($matchedSnapshot),
            ]],
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================
    private function fetchObservation(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM patient_observations WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function loadActiveRules(?string $domain, int $maxTier = 3, int $minTier = 1): array
    {
        // Cache rules in memory to avoid N+1 queries in batch mode
        $cacheKey = "{$domain}_{$maxTier}_{$minTier}";
        if (isset($this->ruleCache[$cacheKey])) {
            return $this->ruleCache[$cacheKey];
        }

        $domainClause = $domain ? "AND (domain = ? OR domain = 'composite')" : '';
        $sql = "
            SELECT * FROM clinical_rules
            WHERE  is_active     = 1
              AND  severity_tier >= ?
              AND  severity_tier <= ?
              {$domainClause}
        ";
        $stmt = $this->db->prepare($sql);
        if ($domain) {
            $stmt->bind_param('iis', $minTier, $maxTier, $domain);
        } else {
            $stmt->bind_param('ii', $minTier, $maxTier);
        }
        $stmt->execute();
        $rules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $this->ruleCache[$cacheKey] = $rules;
        return $rules;
    }

    private function loadCriteriaForRule(int $ruleId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM rule_criteria WHERE rule_id = ? ORDER BY sort_order ASC"
        );
        $stmt->bind_param('i', $ruleId);
        $stmt->execute();
        $criteria = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $criteria;
    }

    private function fetchPatientsWithRecentActivity(int $windowHours): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT patient_id FROM patient_observations
            WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->bind_param('i', $windowHours);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_column($rows, 'patient_id');
    }
}
