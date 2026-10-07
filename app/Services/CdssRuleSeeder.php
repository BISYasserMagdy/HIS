<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;

class CdssRuleSeeder
{
    public function seed(ConnectionInterface $database): void
    {
        $rules = [
            ['HYPERTENSION-001', 'Hypertensive Crisis', 'vital_sign', 'critical', 1, 12, 4, true, 'BP Systolic > 180 mmHg or Diastolic > 120 mmHg — immediate intervention required.'],
            ['TACHYCARDIA-001', 'Critical Tachycardia', 'vital_sign', 'critical', 1, 12, 4, true, 'Heart rate > 150 bpm — evaluate for arrhythmia or haemodynamic instability.'],
            ['BRADYCARDIA-001', 'Critical Bradycardia', 'vital_sign', 'critical', 1, 12, 4, true, 'Heart rate < 40 bpm — risk of haemodynamic compromise.'],
            ['HYPOXIA-001', 'Critical Hypoxia', 'vital_sign', 'critical', 1, 12, 4, true, 'SpO₂ < 90% — supplemental O₂ urgently required.'],
            ['HYPERTHERMIA-001', 'Hyperthermia / Fever', 'vital_sign', 'warning', 2, 24, 8, false, 'Temperature > 38.5 °C — evaluate for infection or sepsis.'],
            ['HYPOTHERMIA-001', 'Hypothermia', 'vital_sign', 'warning', 2, 24, 8, false, 'Temperature < 35 °C — active warming and monitoring required.'],
            ['GLUCOSE-CRITICAL-LOW', 'Severe Hypoglycaemia', 'lab_result', 'critical', 1, 24, 12, true, 'Glucose < 54 mg/dL — administer glucose immediately.'],
            ['GLUCOSE-LOW-001', 'Hypoglycaemia', 'lab_result', 'warning', 2, 24, 8, true, 'Glucose < 70 mg/dL — monitor closely and consider carbohydrate supplementation.'],
            ['GLUCOSE-HIGH-001', 'Hyperglycaemia', 'lab_result', 'warning', 2, 24, 8, false, 'Glucose > 200 mg/dL — review diabetic management plan.'],
            ['GLUCOSE-CRITICAL-HIGH', 'Critical Hyperglycaemia', 'lab_result', 'critical', 1, 24, 12, true, 'Glucose > 500 mg/dL — risk of diabetic ketoacidosis. Urgent review required.'],
            ['HGB-CRITICAL-LOW', 'Critical Anaemia', 'lab_result', 'critical', 1, 24, 12, true, 'Haemoglobin < 7 g/dL — consider urgent transfusion.'],
            ['HGB-LOW-001', 'Anaemia', 'lab_result', 'warning', 2, 24, 8, false, 'Haemoglobin < 12 g/dL — evaluate for cause of anaemia.'],
            ['CHOL-HIGH-001', 'High Total Cholesterol', 'lab_result', 'warning', 2, 48, 24, false, 'Total Cholesterol > 200 mg/dL — review cardiovascular risk and lipid therapy.'],
            ['CHOL-CRITICAL-001', 'Critically High Cholesterol', 'lab_result', 'critical', 1, 48, 24, true, 'Total Cholesterol > 300 mg/dL — significantly elevated cardiovascular risk.'],
            ['HYPER-K-001', 'Hyperkalemia — Critical', 'lab_result', 'critical', 1, 48, 12, true, 'Serum Potassium > 5.5 mEq/L — risk of cardiac arrhythmia.'],
            ['HYPO-K-001', 'Hypokalaemia', 'lab_result', 'warning', 2, 24, 8, true, 'Serum Potassium < 3.5 mEq/L — risk of arrhythmia; potassium replacement indicated.'],
            ['SODIUM-HIGH-001', 'Hypernatraemia', 'lab_result', 'warning', 2, 24, 8, false, 'Serum Sodium > 150 mEq/L — evaluate for dehydration.'],
            ['SODIUM-LOW-001', 'Hyponatraemia', 'lab_result', 'warning', 2, 24, 8, false, 'Serum Sodium < 130 mEq/L — monitor for neurological symptoms.'],
            ['CREATININE-HIGH-001', 'Elevated Creatinine', 'lab_result', 'warning', 2, 24, 8, false, 'Creatinine > 1.5 mg/dL — evaluate renal function.'],
            ['WBC-HIGH-001', 'Leukocytosis', 'lab_result', 'warning', 2, 24, 8, false, 'WBC > 11 × 10³/µL — evaluate for infection or inflammation.'],
            ['WBC-LOW-001', 'Leukopenia', 'lab_result', 'warning', 2, 24, 8, false, 'WBC < 4 × 10³/µL — evaluate for immunosuppression or haematological disorder.'],
            ['PLATELETS-LOW-001', 'Thrombocytopenia', 'lab_result', 'critical', 1, 24, 12, true, 'Platelets < 50 × 10³/µL — bleeding risk; review medications.'],
            ['HYPER-K-DIGOXIN-001', 'Hyperkalemia with Active Digoxin', 'drug_lab_interaction', 'critical', 1, 48, 12, true, 'Potassium > 5.0 mEq/L with active Digoxin — high risk of cardiac arrhythmia.'],
            ['NSAID-ACE-001', 'NSAID + ACE Inhibitor Interaction', 'drug_drug_interaction', 'warning', 2, 72, 24, true, 'Concurrent NSAID and ACE inhibitor — risk of renal impairment and reduced antihypertensive efficacy.'],
            ['PENICILLIN-ALLERGY', 'Penicillin Allergy — Active Prescription', 'allergy_interaction', 'critical', 1, 0, 0, true, 'Active penicillin allergy on record — review current prescriptions immediately.'],
            ['SODIUM-HIGH-002', 'Hypernatraemia (Sodium)', 'lab_result', 'warning', 2, 24, 8, false, 'Sodium > 150 mEq/L — evaluate for dehydration.'],
            ['SODIUM-LOW-002', 'Hyponatraemia (Sodium)', 'lab_result', 'warning', 2, 24, 8, false, 'Sodium < 130 mEq/L — monitor for neurological symptoms.'],
            ['POTASSIUM-HIGH-002', 'Hyperkalaemia (Potassium)', 'lab_result', 'critical', 1, 48, 12, true, 'Potassium > 5.5 mEq/L — risk of cardiac arrhythmia.'],
            ['POTASSIUM-LOW-002', 'Hypokalaemia (Potassium)', 'lab_result', 'warning', 2, 24, 8, true, 'Potassium < 3.5 mEq/L — arrhythmia risk; potassium replacement indicated.'],
            ['HBA1C-HIGH-001', 'Poorly Controlled Diabetes (HbA1c)', 'lab_result', 'warning', 2, 48, 24, false, 'HbA1c ≥ 7% — review glycaemic control and management plan.'],
            ['HBA1C-CRITICAL-001', 'Severely Uncontrolled Diabetes (HbA1c)', 'lab_result', 'critical', 1, 48, 24, true, 'HbA1c ≥ 10% — urgent diabetes management review required.'],
            ['TSH-HIGH-001', 'Elevated TSH — Hypothyroidism', 'lab_result', 'warning', 2, 48, 24, false, 'TSH > 4.0 mIU/L — evaluate for hypothyroidism.'],
            ['TSH-LOW-001', 'Suppressed TSH — Hyperthyroidism', 'lab_result', 'warning', 2, 48, 24, false, 'TSH < 0.4 mIU/L — evaluate for hyperthyroidism or overtreatment.'],
            ['BUN-HIGH-001', 'Elevated BUN', 'lab_result', 'warning', 2, 24, 8, false, 'BUN > 25 mg/dL — evaluate renal function and hydration status.'],
            ['ALT-HIGH-001', 'Elevated ALT — Liver Function', 'lab_result', 'warning', 2, 48, 24, false, 'ALT > 56 U/L — evaluate for hepatic dysfunction or medication toxicity.'],
            ['AST-HIGH-001', 'Elevated AST — Liver Function', 'lab_result', 'warning', 2, 48, 24, false, 'AST > 40 U/L — evaluate for hepatic or cardiac pathology.'],
        ];

        $criteria = [
            'HYPERTENSION-001' => [['vital', 'systolic_bp', 'gt', 180, 'mmHg', 'OR', null], ['vital', 'diastolic_bp', 'gt', 120, 'mmHg', 'AND', null]],
            'TACHYCARDIA-001' => [['vital', 'heart_rate', 'gt', 150, 'bpm', 'AND', null]],
            'BRADYCARDIA-001' => [['vital', 'heart_rate', 'lt', 40, 'bpm', 'AND', null]],
            'HYPOXIA-001' => [['vital', 'spo2', 'lt', 90, '%', 'AND', null]],
            'HYPERTHERMIA-001' => [['vital', 'body_temperature', 'gt', 38.5, 'C', 'AND', null]],
            'HYPOTHERMIA-001' => [['vital', 'body_temperature', 'lt', 35, 'C', 'AND', null]],
            'GLUCOSE-CRITICAL-LOW' => [['lab', 'glucose', 'lt', 54, 'mg/dL', 'AND', null]],
            'GLUCOSE-LOW-001' => [['lab', 'glucose', 'lt', 70, 'mg/dL', 'AND', null]],
            'GLUCOSE-HIGH-001' => [['lab', 'glucose', 'gt', 200, 'mg/dL', 'AND', null]],
            'GLUCOSE-CRITICAL-HIGH' => [['lab', 'glucose', 'gt', 500, 'mg/dL', 'AND', null]],
            'HGB-CRITICAL-LOW' => [['lab', 'hemoglobin', 'lt', 7, 'g/dL', 'AND', null]],
            'HGB-LOW-001' => [['lab', 'hemoglobin', 'lt', 12, 'g/dL', 'AND', null]],
            'CHOL-HIGH-001' => [['lab', 'total_cholesterol', 'gt', 200, 'mg/dL', 'AND', null]],
            'CHOL-CRITICAL-001' => [['lab', 'total_cholesterol', 'gt', 300, 'mg/dL', 'AND', null]],
            'HYPER-K-001' => [['lab', 'serum_potassium', 'gt', 5.5, 'mEq/L', 'AND', null]],
            'HYPO-K-001' => [['lab', 'serum_potassium', 'lt', 3.5, 'mEq/L', 'AND', null]],
            'SODIUM-HIGH-001' => [['lab', 'serum_sodium', 'gt', 150, 'mEq/L', 'AND', null]],
            'SODIUM-LOW-001' => [['lab', 'serum_sodium', 'lt', 130, 'mEq/L', 'AND', null]],
            'CREATININE-HIGH-001' => [['lab', 'creatinine', 'gt', 1.5, 'mg/dL', 'AND', null]],
            'WBC-HIGH-001' => [['lab', 'wbc', 'gt', 11, 'x10³/µL', 'AND', null]],
            'WBC-LOW-001' => [['lab', 'wbc', 'lt', 4, 'x10³/µL', 'AND', null]],
            'PLATELETS-LOW-001' => [['lab', 'platelets', 'lt', 50, 'x10³/µL', 'AND', null]],
            'HYPER-K-DIGOXIN-001' => [['lab', 'serum_potassium', 'gt', 5.0, 'mEq/L', 'AND', null], ['medication', 'medication_name', 'contains', null, null, 'AND', 'digoxin']],
            'NSAID-ACE-001' => [['medication', 'medication_name', 'contains', null, null, 'AND', 'nsaid,ibuprofen,naproxen,diclofenac,aspirin'], ['medication', 'medication_name', 'contains', null, null, 'AND', 'lisinopril,enalapril,ramipril,perindopril,captopril']],
            'PENICILLIN-ALLERGY' => [['allergy', 'allergy_flag', 'contains', null, null, 'AND', 'penicillin,amoxicillin,ampicillin']],
            'SODIUM-HIGH-002' => [['lab', 'sodium', 'gt', 150, 'mEq/L', 'AND', null]],
            'SODIUM-LOW-002' => [['lab', 'sodium', 'lt', 130, 'mEq/L', 'AND', null]],
            'POTASSIUM-HIGH-002' => [['lab', 'potassium', 'gt', 5.5, 'mEq/L', 'AND', null]],
            'POTASSIUM-LOW-002' => [['lab', 'potassium', 'lt', 3.5, 'mEq/L', 'AND', null]],
            'HBA1C-HIGH-001' => [['lab', 'hba1c', 'gte', 7.0, '%', 'AND', null]],
            'HBA1C-CRITICAL-001' => [['lab', 'hba1c', 'gte', 10.0, '%', 'AND', null]],
            'TSH-HIGH-001' => [['lab', 'tsh', 'gt', 4.0, 'mIU/L', 'AND', null]],
            'TSH-LOW-001' => [['lab', 'tsh', 'lt', 0.4, 'mIU/L', 'AND', null]],
            'BUN-HIGH-001' => [['lab', 'bun', 'gt', 25, 'mg/dL', 'AND', null]],
            'ALT-HIGH-001' => [['lab', 'alt', 'gt', 56, 'U/L', 'AND', null]],
            'AST-HIGH-001' => [['lab', 'ast', 'gt', 40, 'U/L', 'AND', null]],
        ];

        $database->transaction(function () use ($database, $rules, $criteria): void {
            foreach ($rules as [$code, $name, $domain, $severity, $tier, $suppression, $cooldown, $acknowledgment, $description]) {
                $database->table('clinical_rules')->insertOrIgnore([
                    'rule_code' => $code,
                    'rule_name' => $name,
                    'domain' => $domain,
                    'severity' => $severity,
                    'severity_tier' => $tier,
                    'suppression_window_hrs' => $suppression,
                    'cooldown_hrs' => $cooldown,
                    'requires_acknowledgment' => $acknowledgment,
                    'description' => $description,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($criteria as $code => $items) {
                $ruleId = $database->table('clinical_rules')->where('rule_code', $code)->value('id');
                if (!$ruleId) {
                    continue;
                }
                $existingCount = $database->table('rule_criteria')->where('rule_id', $ruleId)->count();
                foreach (array_slice($items, $existingCount, null, true) as $index => [$domain, $parameter, $operator, $threshold, $unit, $join, $pattern]) {
                    $database->table('rule_criteria')->insert([
                        'rule_id' => $ruleId,
                        'data_domain' => $domain,
                        'parameter_key' => $parameter,
                        'operator' => $operator,
                        'threshold_value' => $threshold,
                        'threshold_unit' => $unit,
                        'string_match_pattern' => $pattern,
                        'logic_join' => $join,
                        'sort_order' => $index + 1,
                    ]);
                }
            }
        });
    }
}
