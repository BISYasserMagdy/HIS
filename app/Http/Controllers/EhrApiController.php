<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class EhrApiController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return response()->json([], 200);
        }

        $action = trim((string) $request->input('action', $request->query('action', '')));

        return match ($action) {
            'login' => $this->login($request),
            'logout' => $this->logout($request),
            'whoami' => $this->whoami($request),
            'get_patients' => $this->getPatients($request),
            'get_patient' => $this->getPatient($request),
            'next_patient_id' => $this->nextPatientId($request),
            'add_patient' => $this->addPatient($request),
            'update_patient' => $this->updatePatient($request),
            'delete_patient' => $this->deletePatient($request),
            'get_timeline' => $this->getTimeline($request),
            'add_timeline' => $this->addTimeline($request),
            'delete_timeline' => $this->deleteTimeline($request),
            'get_vitals' => $this->getVitals($request),
            'save_vitals' => $this->saveVitals($request),
            'update_vitals' => $this->updateVitals($request),
            'get_meds' => $this->getMedications($request),
            'add_med' => $this->addMedication($request),
            'delete_med' => $this->deleteMedication($request),
            'request_refill' => $this->requestRefill($request),
            'get_labs' => $this->getLabs($request),
            'add_lab' => $this->addLab($request),
            'update_lab' => $this->updateLab($request),
            'delete_lab' => $this->deleteLab($request),
            'get_users' => $this->getUsers($request),
            'create_user' => $this->createUser($request),
            'update_user' => $this->updateUser($request),
            'deactivate_user' => $this->deactivateUser($request),
            'get_staff_patients' => $this->getStaffPatients($request),
            'get_audit_log' => $this->getAuditLog($request),
            'reset_password' => $this->resetPassword($request),
            'submit_profile_request' => $this->submitProfileRequest($request),
            'get_my_profile_requests' => $this->getMyProfileRequests($request),
            'get_profile_requests' => $this->getProfileRequests($request),
            'review_profile_request' => $this->reviewProfileRequest($request),
            'get_clinical_alerts' => $this->getClinicalAlerts($request),
            'dismiss_alert' => $this->dismissAlert($request),
            'reseed_cdss_rules' => $this->reseedCdssRules($request),
            'ping' => response()->json([
                'success' => true,
                'message' => 'EHR Laravel API is reachable',
                'db' => config('database.connections.ehr.database'),
                'time' => now()->toDateTimeString(),
            ]),
            default => response()->json([
                'success' => false,
                'message' => 'This EHR action has not been migrated to Laravel yet.',
            ], 501),
        };
    }

    private function getPatients(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'manager', 'doctor', 'nurse'])) {
            return $denied;
        }

        $query = DB::connection('ehr')->table('patients');
        $search = trim((string) $request->query('q', ''));

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                foreach (['first_name', 'last_name', 'patient_id', 'conditions', 'physician'] as $column) {
                    $builder->orWhere($column, 'like', '%' . $search . '%');
                }
            });
        }

        $patients = $query->orderByDesc('created_at')->get();

        return response()->json([
            'success' => true,
            'data' => $patients->map(fn (object $patient): array => $this->formatPatient($patient))->all(),
        ]);
    }

    private function getPatient(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'manager', 'doctor', 'nurse'])) {
            return $denied;
        }

        $patientId = trim((string) $request->query('patient_id', ''));
        if ($patientId === '') {
            return response()->json(['success' => false, 'message' => 'patient_id required']);
        }

        $patient = DB::connection('ehr')->table('patients')->where('patient_id', $patientId)->first();
        if (!$patient) {
            return response()->json(['success' => false, 'message' => 'Not found']);
        }

        return response()->json(['success' => true, 'data' => $this->formatPatient($patient)]);
    }

    private function nextPatientId(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'doctor', 'nurse'])) {
            return $denied;
        }

        return response()->json([
            'success' => true,
            'patient_id' => $this->nextPatientIdentifier(),
        ]);
    }

    private function addPatient(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'doctor', 'nurse'])) {
            return $denied;
        }

        $firstName = trim((string) $request->input('first_name', ''));
        $lastName = trim((string) $request->input('last_name', ''));
        $dateOfBirth = trim((string) $request->input('dob', ''));
        $gender = trim((string) $request->input('gender', ''));

        if ($firstName === '' || $lastName === '' || $dateOfBirth === '' || $gender === '') {
            return response()->json([
                'success' => false,
                'message' => 'first_name, last_name, dob, gender are required',
            ]);
        }

        $database = DB::connection('ehr');
        $patientId = $this->nextPatientIdentifier();
        $colors = ['#1a73e8', '#00b4a6', '#8b5cf6', '#ec4899', '#f59e0b', '#ef4444', '#10b981', '#06b6d4', '#0b3c7a', '#6366f1'];
        $colorIndex = (int) $database->table('patients')->count() % count($colors);
        $physician = trim((string) $request->input('physician', ''));
        $physicianId = $request->input('physician_id');

        if ($physicianId === null && $physician !== '') {
            $physicianId = $database->table('users')
                ->where('full_name', $physician)
                ->whereIn('role', ['doctor', 'nurse'])
                ->value('id');
        }

        $patient = [
            'patient_id' => $patientId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'dob' => $dateOfBirth,
            'gender' => $gender,
            'blood_type' => $request->input('blood_type'),
            'phone' => $request->input('phone'),
            'email' => $request->input('email'),
            'insurance' => $request->input('insurance'),
            'physician' => $physician,
            'physician_id' => $physicianId ?: null,
            'allergies' => $request->input('allergies'),
            'conditions' => $request->input('conditions'),
            'smoking' => $request->input('smoking'),
            'alcohol' => $request->input('alcohol'),
            'pmh' => $request->input('pmh'),
            'fmh' => $request->input('fmh'),
            'surgical' => $request->input('surgical'),
            'vaccines' => $request->input('vaccines'),
            'avatar_color' => $colors[$colorIndex],
            'status' => 'active',
            'created_at' => now(),
        ];

        $database->transaction(function () use ($database, $patient, $patientId): void {
            $database->table('patients')->insert($patient);
            $database->table('timeline')->insert([
                'patient_id' => $patientId,
                'entry_date' => now()->format('d M Y'),
                'dot_type' => 'ok',
                'entry_text' => '<strong>Patient Registration</strong> — New patient registered in the EHR system.',
                'created_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'patient_id' => $patientId,
            'message' => "Patient $firstName $lastName registered ($patientId)",
        ]);
    }

    private function deletePatient(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin'])) {
            return $denied;
        }

        $patientId = trim((string) $request->input('patient_id', $request->query('patient_id', '')));
        if ($patientId === '') {
            return response()->json(['success' => false, 'message' => 'patient_id required']);
        }

        DB::connection('ehr')->table('patients')->where('patient_id', $patientId)->delete();

        return response()->json(['success' => true]);
    }

    private function updatePatient(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'doctor', 'nurse'])) {
            return $denied;
        }

        $patientId = trim((string) $request->input('patient_id', ''));
        if ($patientId === '') {
            return response()->json(['success' => false, 'message' => 'patient_id required']);
        }

        $allowed = [
            'first_name', 'last_name', 'phone', 'email', 'insurance', 'physician', 'blood_type',
            'allergies', 'conditions', 'smoking', 'alcohol', 'pmh', 'fmh', 'surgical', 'vaccines',
            'last_visit', 'next_appt', 'status',
        ];
        $updates = [];

        foreach ($allowed as $field) {
            if ($request->exists($field)) {
                $updates[$field] = $request->input($field);
            }
        }

        $database = DB::connection('ehr');
        if ($request->exists('physician_id')) {
            $updates['physician_id'] = $request->input('physician_id') !== ''
                ? (int) $request->input('physician_id')
                : null;
        } elseif ($request->exists('physician')) {
            $updates['physician_id'] = $request->input('physician') !== ''
                ? $database->table('users')
                    ->where('full_name', $request->input('physician'))
                    ->whereIn('role', ['doctor', 'nurse'])
                    ->value('id')
                : null;
        }

        if (!$updates) {
            return response()->json(['success' => false, 'message' => 'Nothing to update']);
        }

        $exists = $database->table('patients')->where('patient_id', $patientId)->exists();
        if (!$exists) {
            return response()->json(['success' => false, 'message' => 'Not found']);
        }

        $database->table('patients')->where('patient_id', $patientId)->update($updates);

        return response()->json(['success' => true]);
    }

    private function getTimeline(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'manager', 'doctor', 'nurse'])) {
            return $denied;
        }

        $patientId = trim((string) $request->query('patient_id', ''));
        if ($patientId === '') {
            return response()->json(['success' => false, 'message' => 'patient_id required']);
        }

        $entries = DB::connection('ehr')->table('timeline')
            ->where('patient_id', $patientId)
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $entries]);
    }

    private function addTimeline(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'doctor', 'nurse'])) {
            return $denied;
        }

        $patientId = trim((string) $request->input('patient_id', ''));
        $text = trim((string) $request->input('entry_text', ''));
        if ($patientId === '' || $text === '') {
            return response()->json(['success' => false, 'message' => 'patient_id and entry_text required']);
        }

        $dotType = (string) $request->input('dot_type', '');
        if (!in_array($dotType, ['', 'ok', 'warn', 'red'], true)) {
            $dotType = '';
        }

        $id = DB::connection('ehr')->table('timeline')->insertGetId([
            'patient_id' => $patientId,
            'entry_date' => $request->input('entry_date', now()->format('d M Y')),
            'dot_type' => $dotType,
            'entry_text' => $text,
            'created_at' => now(),
        ]);

        return response()->json(['success' => true, 'id' => $id]);
    }

    private function deleteTimeline(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'doctor', 'nurse'])) {
            return $denied;
        }

        $id = (int) $request->input('id', $request->query('id', 0));
        if (!$id) {
            return response()->json(['success' => false, 'message' => 'id required']);
        }

        DB::connection('ehr')->table('timeline')->where('id', $id)->delete();

        return response()->json(['success' => true]);
    }

    private function getVitals(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'manager', 'doctor', 'nurse'])) {
            return $denied;
        }

        $patientId = trim((string) $request->query('patient_id', ''));
        if ($patientId === '') {
            return response()->json(['success' => false, 'message' => 'patient_id required']);
        }

        $vitals = DB::connection('ehr')->table('vitals')
            ->where('patient_id', $patientId)
            ->orderByDesc('id')
            ->first();

        return response()->json(['success' => true, 'data' => $vitals]);
    }

    private function saveVitals(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'nurse', 'doctor'])) {
            return $denied;
        }

        $patientId = trim((string) $request->input('patient_id', ''));
        if ($patientId === '') {
            return response()->json(['success' => false, 'message' => 'patient_id required']);
        }

        $id = DB::connection('ehr')->table('vitals')->insertGetId([
            'patient_id' => $patientId,
            'recorded_at' => $request->input('recorded_at', now()->format('d M Y, h:i A')),
            'nurse' => $request->input('nurse', '—'),
            'bp' => $request->input('bp', '—'),
            'bp_status' => $request->input('bp_status', 'ok'),
            'hr' => $request->input('hr', '—'),
            'temp' => $request->input('temp', '—'),
            'spo2' => $request->input('spo2', '—'),
            'rr' => $request->input('rr', '—'),
            'bmi' => $request->input('bmi', '—'),
            'note' => $request->input('note', ''),
            'created_at' => now(),
        ]);

        return response()->json(['success' => true, 'id' => $id]);
    }

    private function updateVitals(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'nurse', 'doctor'])) {
            return $denied;
        }

        $patientId = trim((string) $request->input('patient_id', ''));
        if ($patientId === '') {
            return response()->json(['success' => false, 'message' => 'patient_id required']);
        }

        $database = DB::connection('ehr');
        $latestId = $database->table('vitals')
            ->where('patient_id', $patientId)
            ->orderByDesc('id')
            ->value('id');

        if (!$latestId) {
            return response()->json(['success' => false, 'message' => 'Not found']);
        }

        $fields = ['recorded_at', 'nurse', 'bp', 'bp_status', 'hr', 'temp', 'spo2', 'rr', 'bmi', 'note'];
        $updates = [];
        foreach ($fields as $field) {
            if ($request->exists($field)) {
                $updates[$field] = $request->input($field);
            }
        }

        $database->table('vitals')->where('id', $latestId)->update($updates);

        return response()->json(['success' => true]);
    }

    private function getMedications(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'manager', 'doctor', 'nurse'])) {
            return $denied;
        }

        $patientId = trim((string) $request->query('patient_id', ''));
        if ($patientId === '') {
            return response()->json(['success' => false, 'message' => 'patient_id required']);
        }

        $medications = DB::connection('ehr')->table('medications')
            ->where('patient_id', $patientId)
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $medications]);
    }

    private function addMedication(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'doctor'])) {
            return $denied;
        }

        $patientId = trim((string) $request->input('patient_id', ''));
        $name = trim((string) $request->input('med_name', ''));
        $dose = trim((string) $request->input('dose', ''));
        $prescribedBy = trim((string) $request->input('prescribed_by', ''));
        if ($patientId === '' || $name === '' || $dose === '' || $prescribedBy === '') {
            return response()->json(['success' => false, 'message' => 'Required fields missing']);
        }

        $database = DB::connection('ehr');
        $startDate = $request->input('start_date', now()->format('d M Y'));
        $id = $database->transaction(function () use ($database, $patientId, $name, $dose, $prescribedBy, $startDate, $request): int {
            $medicationId = $database->table('medications')->insertGetId([
                'patient_id' => $patientId,
                'med_name' => $name,
                'dose' => $dose,
                'prescribed_by' => $prescribedBy,
                'start_date' => $startDate,
                'status' => $request->input('status', 'active'),
                'created_at' => now(),
            ]);

            $database->table('timeline')->insert([
                'patient_id' => $patientId,
                'entry_date' => $startDate,
                'dot_type' => 'ok',
                'entry_text' => '<strong>E-Prescription: ' . e($name) . '</strong> — ' . e($dose) . ' prescribed by ' . e($prescribedBy) . '.',
                'created_at' => now(),
            ]);

            return $medicationId;
        });

        return response()->json(['success' => true, 'id' => $id]);
    }

    private function deleteMedication(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'doctor'])) {
            return $denied;
        }

        $id = (int) $request->input('id', $request->query('id', 0));
        if (!$id) {
            return response()->json(['success' => false, 'message' => 'id required']);
        }

        DB::connection('ehr')->table('medications')->where('id', $id)->delete();

        return response()->json(['success' => true]);
    }

    private function requestRefill(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'doctor', 'nurse'])) {
            return $denied;
        }

        $patientId = trim((string) $request->input('patient_id', ''));
        $name = trim((string) $request->input('med_name', ''));
        if ($patientId === '' || $name === '') {
            return response()->json(['success' => false, 'message' => 'patient_id and med_name required']);
        }

        $pharmacy = trim((string) $request->input('pharmacy', 'HealthCare Hub Pharmacy'));
        $quantity = trim((string) $request->input('qty', '30 days'));
        DB::connection('ehr')->table('timeline')->insert([
            'patient_id' => $patientId,
            'entry_date' => now()->format('d M Y'),
            'dot_type' => 'ok',
            'entry_text' => '<strong>Refill Request: ' . e($name) . '</strong> — ' . e($quantity) . ' supply sent to ' . e($pharmacy) . '.',
            'created_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    private function getLabs(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'manager', 'doctor', 'nurse'])) {
            return $denied;
        }

        $patientId = trim((string) $request->query('patient_id', ''));
        if ($patientId === '') {
            return response()->json(['success' => false, 'message' => 'patient_id required']);
        }

        $labs = DB::connection('ehr')->table('lab_results')
            ->where('patient_id', $patientId)
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $labs]);
    }

    private function addLab(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'doctor', 'nurse'])) {
            return $denied;
        }

        $patientId = trim((string) $request->input('patient_id', ''));
        $name = trim((string) $request->input('test_name', ''));
        $value = trim((string) $request->input('test_value', ''));
        $reference = trim((string) $request->input('ref_range', ''));
        $label = trim((string) $request->input('label', ''));
        if ($patientId === '' || $name === '' || $value === '' || $reference === '' || $label === '') {
            return response()->json(['success' => false, 'message' => 'Required fields missing']);
        }

        $classification = (string) $request->input('cls', 'ok');
        if (!in_array($classification, ['ok', 'med', 'hi'], true)) {
            $classification = 'ok';
        }
        $color = ['ok' => 'success', 'med' => 'accent2', 'hi' => 'danger'][$classification];
        $id = DB::connection('ehr')->table('lab_results')->insertGetId([
            'patient_id' => $patientId,
            'panel_date' => $request->input('panel_date', now()->format('d M Y')),
            'test_name' => $name,
            'test_value' => $value,
            'ref_range' => $reference,
            'pct' => min(100, max(0, (int) $request->input('pct', 50))),
            'cls' => $classification,
            'label' => $label,
            'color_type' => $color,
            'created_at' => now(),
        ]);

        return response()->json(['success' => true, 'id' => $id]);
    }

    private function updateLab(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'doctor'])) {
            return $denied;
        }

        $id = (int) $request->input('id', 0);
        if (!$id) {
            return response()->json(['success' => false, 'message' => 'id required']);
        }

        $classification = (string) $request->input('cls', 'ok');
        if (!in_array($classification, ['ok', 'med', 'hi'], true)) {
            $classification = 'ok';
        }

        DB::connection('ehr')->table('lab_results')->where('id', $id)->update([
            'test_name' => trim((string) $request->input('test_name', '')),
            'test_value' => trim((string) $request->input('test_value', '')),
            'ref_range' => trim((string) $request->input('ref_range', '')),
            'pct' => min(100, max(0, (int) $request->input('pct', 50))),
            'cls' => $classification,
            'label' => trim((string) $request->input('label', '')),
            'color_type' => ['ok' => 'success', 'med' => 'accent2', 'hi' => 'danger'][$classification],
            'panel_date' => $request->input('panel_date', now()->format('d M Y')),
        ]);

        return response()->json(['success' => true]);
    }

    private function deleteLab(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'doctor'])) {
            return $denied;
        }

        $id = (int) $request->input('id', $request->query('id', 0));
        if (!$id) {
            return response()->json(['success' => false, 'message' => 'id required']);
        }

        DB::connection('ehr')->table('lab_results')->where('id', $id)->delete();

        return response()->json(['success' => true]);
    }

    private function getUsers(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'manager'])) {
            return $denied;
        }

        $hospital = (string) $request->session()->get('hospital', '');
        $users = DB::connection('ehr')->table('users')
            ->select('id', 'username', 'email', 'role', 'full_name', 'specialty', 'hospital', 'is_active', 'created_at')
            ->where('hospital', $hospital)
            ->whereIn('role', ['doctor', 'nurse'])
            ->orderBy('role')
            ->orderBy('full_name')
            ->get();

        return response()->json(['success' => true, 'hospital' => $hospital, 'data' => $users]);
    }

    private function createUser(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin'])) {
            return $denied;
        }

        $username = trim((string) $request->input('username', ''));
        $email = filter_var(trim((string) $request->input('email', '')), FILTER_VALIDATE_EMAIL);
        $password = (string) $request->input('password', '');
        $role = (string) $request->input('role', 'nurse');
        $fullName = trim((string) $request->input('full_name', ''));
        $specialty = trim((string) $request->input('specialty', ''));

        if ($username === '' || !$email || $password === '') {
            return response()->json(['success' => false, 'message' => 'username, email, password are required']);
        }
        if (!in_array($role, ['doctor', 'nurse'], true)) {
            return response()->json(['success' => false, 'message' => 'Invalid role. Admins may only create doctor or nurse accounts.']);
        }
        if (strlen($password) < 8) {
            return response()->json(['success' => false, 'message' => 'Password must be at least 8 characters.']);
        }

        try {
            $id = DB::connection('ehr')->table('users')->insertGetId([
                'username' => $username,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                'role' => $role,
                'full_name' => $fullName,
                'specialty' => $specialty !== '' ? $specialty : null,
                'hospital' => $request->session()->get('hospital', ''),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException) {
            return response()->json(['success' => false, 'message' => 'Username or email already exists.']);
        }

        return response()->json(['success' => true, 'id' => $id, 'message' => "User $username created."]);
    }

    private function updateUser(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin'])) {
            return $denied;
        }

        $id = (int) $request->input('id', 0);
        if (!$id) {
            return response()->json(['success' => false, 'message' => 'id required']);
        }

        $database = DB::connection('ehr');
        $hospital = (string) $request->session()->get('hospital', '');
        $target = $database->table('users')->where('id', $id)->first();
        if (!$target || $target->hospital !== $hospital || !in_array($target->role, ['doctor', 'nurse'], true)) {
            return response()->json(['success' => false, 'message' => 'User not found in your hospital.']);
        }

        $updates = [];
        foreach (['full_name', 'specialty'] as $field) {
            if ($request->exists($field)) {
                $updates[$field] = trim((string) $request->input($field)) ?: null;
            }
        }
        if ($request->exists('email')) {
            $email = filter_var(trim((string) $request->input('email')), FILTER_VALIDATE_EMAIL);
            if (!$email) {
                return response()->json(['success' => false, 'message' => 'A valid email address is required.']);
            }
            $updates['email'] = $email;
        }
        if ($request->exists('role') && in_array($request->input('role'), ['doctor', 'nurse'], true)) {
            $updates['role'] = $request->input('role');
        }
        if ($request->exists('is_active')) {
            $updates['is_active'] = (bool) $request->input('is_active');
        }

        if (!$updates) {
            return response()->json(['success' => false, 'message' => 'Nothing to update']);
        }

        $updates['updated_at'] = now();
        try {
            $database->table('users')->where('id', $id)->update($updates);
        } catch (\Illuminate\Database\QueryException) {
            return response()->json(['success' => false, 'message' => 'Username or email already exists.']);
        }

        return response()->json(['success' => true]);
    }

    private function deactivateUser(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin'])) {
            return $denied;
        }

        $id = (int) $request->input('id', 0);
        if (!$id) {
            return response()->json(['success' => false, 'message' => 'id required']);
        }
        if ((int) $request->session()->get('user_id') === $id) {
            return response()->json(['success' => false, 'message' => 'You cannot deactivate your own account.']);
        }

        $database = DB::connection('ehr');
        $target = $database->table('users')->where('id', $id)->first();
        if (!$target
            || $target->hospital !== $request->session()->get('hospital', '')
            || !in_array($target->role, ['doctor', 'nurse'], true)) {
            return response()->json(['success' => false, 'message' => 'User not found in your hospital.']);
        }

        $database->table('users')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);

        return response()->json(['success' => true]);
    }

    private function getStaffPatients(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'manager'])) {
            return $denied;
        }

        $staffId = (int) $request->query('staff_id', 0);
        if (!$staffId) {
            return response()->json(['success' => false, 'message' => 'staff_id required']);
        }

        $database = DB::connection('ehr');
        $staff = $database->table('users')
            ->select('full_name')
            ->where('id', $staffId)
            ->where('hospital', $request->session()->get('hospital', ''))
            ->whereIn('role', ['doctor', 'nurse'])
            ->first();
        if (!$staff) {
            return response()->json(['success' => false, 'message' => 'Staff member not found in your hospital.']);
        }

        $patients = $database->table('patients')
            ->select('patient_id', 'first_name', 'last_name', 'dob', 'gender', 'status', 'last_visit', 'next_appt', 'conditions')
            ->where('physician_id', $staffId)
            ->orderByDesc('last_visit')
            ->get();

        return response()->json([
            'success' => true,
            'physician' => $staff->full_name,
            'count' => $patients->count(),
            'data' => $patients,
        ]);
    }

    private function getAuditLog(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'manager'])) {
            return $denied;
        }

        $hospital = (string) $request->session()->get('hospital', '');
        $limit = min(max((int) $request->query('limit', 100), 1), 500);
        $rows = DB::connection('ehr')->table('user_sessions as sessions')
            ->join('users', 'users.id', '=', 'sessions.user_id')
            ->where('users.hospital', $hospital)
            ->select(
                'sessions.id',
                'users.username',
                'users.full_name',
                'users.role',
                'sessions.action',
                'sessions.ip_address',
                'sessions.user_agent',
                'sessions.created_at'
            )
            ->orderByDesc('sessions.created_at')
            ->limit($limit)
            ->get();

        return response()->json(['success' => true, 'hospital' => $hospital, 'data' => $rows]);
    }

    private function resetPassword(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin'])) {
            return $denied;
        }

        $id = (int) $request->input('id', 0);
        $password = trim((string) $request->input('new_password', ''));
        if (!$id || strlen($password) < 8) {
            return response()->json(['success' => false, 'message' => 'id and new_password (min 8 chars) required']);
        }

        $hospital = (string) $request->session()->get('hospital', '');
        $database = DB::connection('ehr');
        $user = $database->table('users')
            ->where('id', $id)
            ->where('hospital', $hospital)
            ->whereIn('role', ['doctor', 'nurse', 'manager'])
            ->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Staff member not found in your hospital.']);
        }

        $database->table('users')->where('id', $id)->update([
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'updated_at' => now(),
        ]);

        $emailSent = false;
        try {
            Mail::html(
                '<p>Your password was reset by an administrator at ' . e($hospital) . '.</p>'
                . '<p>Username: ' . e($user->username) . '<br>New password: ' . e($password) . '</p>'
                . '<p>Please change the password after signing in.</p>',
                function ($message) use ($user, $hospital): void {
                    $message->to($user->email)->subject("Your Pharos HIS credentials — {$hospital}");
                }
            );
            $emailSent = true;
        } catch (\Throwable) {
        }

        return response()->json([
            'success' => true,
            'message' => $emailSent
                ? "Password reset and emailed to {$user->email}."
                : "Password reset, but email could not be sent to {$user->email}.",
            'email_sent' => $emailSent,
        ]);
    }

    private function submitProfileRequest(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['doctor', 'nurse', 'manager'])) {
            return $denied;
        }

        $field = trim((string) $request->input('field', ''));
        $newValue = trim((string) $request->input('new_value', ''));
        if (!in_array($field, ['full_name', 'specialty'], true)) {
            return response()->json(['success' => false, 'message' => 'Invalid field. Only full_name or specialty can be changed.']);
        }
        if ($newValue === '' || mb_strlen($newValue) > 160) {
            return response()->json(['success' => false, 'message' => 'New value is empty or too long.']);
        }

        $database = DB::connection('ehr');
        $userId = (int) $request->session()->get('user_id');
        $fieldValue = $database->table('users')->where('id', $userId)->value($field);
        if ($fieldValue !== null && trim((string) $fieldValue) === $newValue) {
            return response()->json(['success' => false, 'message' => 'That is already your current value.']);
        }

        $pending = $database->table('profile_requests')
            ->where('user_id', $userId)
            ->where('field', $field)
            ->where('status', 'pending')
            ->exists();
        if ($pending) {
            return response()->json(['success' => false, 'message' => 'You already have a pending request for this field. Wait for admin review.']);
        }

        $database->table('profile_requests')->insert([
            'user_id' => $userId,
            'hospital' => $request->session()->get('hospital', ''),
            'field' => $field,
            'old_value' => $fieldValue,
            'new_value' => $newValue,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Request submitted — pending admin approval.']);
    }

    private function getMyProfileRequests(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['doctor', 'nurse', 'manager'])) {
            return $denied;
        }

        $rows = DB::connection('ehr')->table('profile_requests')
            ->select('id', 'field', 'old_value', 'new_value', 'status', 'review_note', 'created_at', 'reviewed_at')
            ->where('user_id', $request->session()->get('user_id'))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    private function getProfileRequests(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin'])) {
            return $denied;
        }

        $hospital = (string) $request->session()->get('hospital', '');
        $query = DB::connection('ehr')->table('profile_requests as requests')
            ->join('users', 'users.id', '=', 'requests.user_id')
            ->where('requests.hospital', $hospital)
            ->select(
                'requests.id', 'requests.user_id', 'requests.field', 'requests.old_value',
                'requests.new_value', 'requests.status', 'requests.review_note',
                'requests.created_at', 'requests.reviewed_at', 'users.username',
                'users.full_name', 'users.role', 'users.email'
            );
        if ($request->query('status', 'pending') !== 'all') {
            $query->where('requests.status', 'pending');
        }

        return response()->json(['success' => true, 'data' => $query->orderByDesc('requests.created_at')->limit(100)->get()]);
    }

    private function reviewProfileRequest(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin'])) {
            return $denied;
        }

        $id = (int) $request->input('id', 0);
        $decision = (string) $request->input('decision', '');
        if (!$id || !in_array($decision, ['approve', 'reject'], true)) {
            return response()->json(['success' => false, 'message' => 'id and a valid decision (approve|reject) are required.']);
        }

        $database = DB::connection('ehr');
        $hospital = (string) $request->session()->get('hospital', '');
        $profileRequest = $database->table('profile_requests as requests')
            ->join('users', 'users.id', '=', 'requests.user_id')
            ->where('requests.id', $id)
            ->where('requests.hospital', $hospital)
            ->where('users.hospital', $hospital)
            ->select('requests.*')
            ->first();
        if (!$profileRequest) {
            return response()->json(['success' => false, 'message' => 'Request not found at your hospital.']);
        }
        if ($profileRequest->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'This request has already been reviewed.']);
        }

        $database->transaction(function () use ($database, $profileRequest, $decision, $request, $id): void {
            if ($decision === 'approve' && in_array($profileRequest->field, ['full_name', 'specialty'], true)) {
                $database->table('users')->where('id', $profileRequest->user_id)
                    ->where('hospital', $request->session()->get('hospital', ''))
                    ->update([$profileRequest->field => $profileRequest->new_value, 'updated_at' => now()]);
            }
            $database->table('profile_requests')->where('id', $id)->update([
                'status' => $decision === 'approve' ? 'approved' : 'rejected',
                'reviewed_by' => $request->session()->get('user_id'),
                'reviewed_at' => now(),
                'review_note' => trim((string) $request->input('note', '')) ?: null,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => $decision === 'approve' ? 'Request approved — profile updated.' : 'Request rejected.',
        ]);
    }

    private function getClinicalAlerts(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'manager', 'doctor', 'nurse'])) {
            return $denied;
        }

        $patientId = trim((string) $request->query('patient_id', ''));
        if ($patientId === '') {
            return response()->json(['success' => false, 'message' => 'patient_id is required']);
        }

        $rows = DB::connection('ehr')->table('patient_alert_states as states')
            ->join('clinical_rules as rules', 'rules.id', '=', 'states.rule_id')
            ->where('states.patient_id', $patientId)
            ->where('states.state', 'active')
            ->where(fn ($query) => $query->whereNull('states.expires_at')->orWhere('states.expires_at', '>', now()))
            ->select(
                'states.id as alert_state_id', 'states.state', 'states.severity_tier',
                'states.triggered_at', 'states.expires_at', 'states.matched_criteria_snapshot',
                'rules.rule_code', 'rules.rule_name', 'rules.description', 'rules.severity',
                'rules.requires_acknowledgment'
            )
            ->orderBy('states.severity_tier')
            ->orderByDesc('states.triggered_at')
            ->get();

        $metadata = [
            1 => ['icon' => '🔴', 'label' => 'Critical Warning', 'severity' => 'critical', 'class' => 'alert-critical'],
            2 => ['icon' => '🟡', 'label' => 'Soft Warning', 'severity' => 'warning', 'class' => 'alert-warning'],
            3 => ['icon' => '🔵', 'label' => 'Informational', 'severity' => 'info', 'class' => 'alert-info'],
        ];
        $alerts = $rows->map(function (object $row) use ($metadata, $patientId): array {
            $tier = (int) $row->severity_tier;
            $meta = $metadata[$tier] ?? $metadata[3];
            $snapshot = json_decode((string) $row->matched_criteria_snapshot, true) ?: [];
            $details = array_map(function (array $criterion): string {
                $operators = ['gt' => '>', 'gte' => '≥', 'lt' => '<', 'lte' => '≤', 'eq' => '='];
                $label = ucwords(str_replace('_', ' ', $criterion['parameter_key'] ?? ''));
                $operator = $operators[$criterion['operator'] ?? ''] ?? ($criterion['operator'] ?? '');

                return trim($label . ': ' . ($criterion['patient_value'] ?? '—') . ' '
                    . ($criterion['unit'] ?? '') . ' (threshold: ' . $operator . ' '
                    . ($criterion['threshold'] ?? '') . ' ' . ($criterion['unit'] ?? '') . ')');
            }, $snapshot);
            $seconds = max(0, now()->diffInSeconds($row->triggered_at, false) * -1);
            $relative = $seconds < 60 ? 'Just now' : ($seconds < 3600
                ? (int) ($seconds / 60) . ' mins ago'
                : ($seconds < 86400 ? (int) ($seconds / 3600) . ' hrs ago' : (int) ($seconds / 86400) . ' days ago'));

            return [
                'id' => 'ALT-' . $row->alert_state_id,
                'type' => $meta['icon'] . ' ' . $meta['label'],
                'severity' => $meta['severity'],
                'patient_id' => $patientId,
                'message' => '[' . $patientId . '] ' . ($row->description ?: $row->rule_name),
                'time' => $relative,
                'alert_state_id' => (int) $row->alert_state_id,
                'severity_tier' => $tier,
                'icon' => $meta['icon'],
                'triage_label' => $meta['label'],
                'css_class' => $meta['class'],
                'rule_code' => $row->rule_code,
                'rule_name' => $row->rule_name,
                'requires_acknowledgment' => (bool) $row->requires_acknowledgment,
                'trigger_detail' => implode(' | ', $details),
                'triggered_at' => $row->triggered_at,
                'expires_at' => $row->expires_at,
            ];
        })->all();

        if (!$alerts) {
            $alerts = [[
                'id' => 'ALT-NOMINAL',
                'type' => '🟢 System Status',
                'severity' => 'resolved',
                'message' => '🟢 No Active Alerts — All tracked parameters operating within nominal margins.',
                'time' => 'Now',
                'alert_state_id' => null,
                'severity_tier' => 0,
                'icon' => '🟢',
                'triage_label' => 'All Clear',
                'css_class' => 'alert-nominal',
                'rule_code' => 'NOMINAL',
                'rule_name' => 'Baseline — No Active Alerts',
                'requires_acknowledgment' => false,
                'trigger_detail' => '',
                'triggered_at' => now()->toDateTimeString(),
                'expires_at' => null,
            ]];
        }

        return response()->json([
            'success' => true,
            'patient_id' => $patientId,
            'alert_count' => ($alerts[0]['rule_code'] ?? '') === 'NOMINAL' ? 0 : count($alerts),
            'has_critical' => (bool) array_filter($alerts, fn (array $alert): bool => $alert['severity_tier'] === 1),
            'data' => $alerts,
        ]);
    }

    private function dismissAlert(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin', 'doctor'])) {
            return $denied;
        }

        $id = (int) $request->input('alert_state_id', 0);
        if (!$id) {
            return response()->json(['success' => false, 'message' => 'alert_state_id required']);
        }

        $updated = DB::connection('ehr')->table('patient_alert_states')
            ->where('id', $id)
            ->where('state', 'active')
            ->update(['state' => 'dismissed', 'resolved_at' => now()]);

        return response()->json(['success' => (bool) $updated]);
    }

    private function reseedCdssRules(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['admin'])) {
            return $denied;
        }

        $database = DB::connection('ehr');
        app(\App\Services\CdssRuleSeeder::class)->seed($database);
        $expired = $database->table('patient_alert_states')
            ->where('state', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['state' => 'expired']);

        return response()->json([
            'success' => true,
            'message' => 'CDSS rules seeded/verified.',
            'expired_alerts' => $expired,
        ]);
    }

    private function formatPatient(object $patient): array
    {
        $date = !empty($patient->dob) ? new \DateTimeImmutable($patient->dob) : null;
        $allergies = trim((string) ($patient->allergies ?? ''));

        return [
            'id' => $patient->patient_id,
            'name' => $patient->first_name . ' ' . $patient->last_name,
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'gender' => $patient->gender,
            'age' => $date ? $date->diff(new \DateTimeImmutable())->y : 0,
            'dob' => $date ? $date->format('d M Y') : '—',
            'dob_raw' => $patient->dob,
            'blood' => $patient->blood_type ?: 'Unknown',
            'phone' => $patient->phone ?: '—',
            'email' => $patient->email ?: '—',
            'insurance' => $patient->insurance ?: '—',
            'physician' => $patient->physician ?: '—',
            'allergies' => $allergies !== '' ? array_values(array_filter(array_map('trim', explode(',', $allergies)))) : [],
            'conditions' => $patient->conditions ?: 'None recorded',
            'smoking' => $patient->smoking ?: 'Not recorded',
            'alcohol' => $patient->alcohol ?: 'Not recorded',
            'pmh' => $patient->pmh ?: '',
            'fmh' => $patient->fmh ?: '',
            'surgical' => $patient->surgical ?: '',
            'vaccines' => $patient->vaccines ?: '',
            'color' => $patient->avatar_color ?: '#1a73e8',
            'status' => $patient->status ?: 'active',
            'lastVisit' => $patient->last_visit ?: 'Not yet visited',
            'nextAppt' => $patient->next_appt ?: 'None scheduled',
        ];
    }

    private function nextPatientIdentifier(): string
    {
        $latest = DB::connection('ehr')->table('patients')->orderByDesc('id')->value('patient_id');
        $number = preg_match('/^P-(\d+)$/', (string) $latest, $matches) ? (int) $matches[1] : 0;

        return 'P-' . str_pad((string) ($number + 1), 5, '0', STR_PAD_LEFT);
    }

    private function authorize(Request $request, array $roles): ?JsonResponse
    {
        $userId = $request->session()->get('user_id');
        $role = $request->session()->get('role');

        if (!$userId || !$role) {
            return response()->json([
                'success' => false,
                'status' => 'Unauthorized access',
                'message' => 'You must be logged in to perform this action.',
            ], 401);
        }

        if (!in_array($role, $roles, true)) {
            return response()->json([
                'success' => false,
                'status' => 'Unauthorized access',
                'message' => 'Your role (' . $role . ') does not have permission for this action.',
            ], 403);
        }

        return null;
    }

    private function login(Request $request): JsonResponse
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        if ($username === '' || $password === '') {
            return response()->json([
                'success' => false,
                'message' => 'Username and password are required.',
            ]);
        }

        $user = DB::connection('ehr')->table('users')
            ->select('id', 'username', 'password_hash', 'role', 'full_name', 'hospital', 'is_active')
            ->where('username', $username)
            ->first();

        if (!$user || !password_verify($password, $user->password_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid username or password.',
            ]);
        }

        if (!(bool) $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This account has been deactivated.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put([
            'user_id' => (int) $user->id,
            'username' => $user->username,
            'role' => $user->role,
            'full_name' => $user->full_name,
            'hospital' => $user->hospital,
        ]);

        DB::connection('ehr')->table('user_sessions')->insert([
            'user_id' => $user->id,
            'session_token' => hash('sha256', $request->session()->getId()),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'action' => 'login',
        ]);

        return response()->json([
            'success' => true,
            'name' => $user->full_name ?: $user->username,
            'role' => $user->role,
            'hospital' => $user->hospital,
        ]);
    }

    private function logout(Request $request): JsonResponse
    {
        $userId = $request->session()->get('user_id');

        if ($userId) {
            DB::connection('ehr')->table('user_sessions')->insert([
                'user_id' => $userId,
                'session_token' => hash('sha256', $request->session()->getId()),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'action' => 'logout',
            ]);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['success' => true, 'message' => 'Logged out.']);
    }

    private function whoami(Request $request): JsonResponse
    {
        $userId = $request->session()->get('user_id');

        if (!$userId) {
            return response()->json(['success' => false, 'logged_in' => false]);
        }

        $specialty = DB::connection('ehr')->table('users')
            ->where('id', $userId)
            ->value('specialty');

        return response()->json([
            'success' => true,
            'logged_in' => true,
            'user_id' => $userId,
            'username' => $request->session()->get('username'),
            'role' => $request->session()->get('role'),
            'name' => $request->session()->get('full_name') ?: $request->session()->get('username'),
            'hospital' => $request->session()->get('hospital'),
            'specialty' => $specialty,
        ]);
    }
}
