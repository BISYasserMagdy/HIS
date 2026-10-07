<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OnlineConsultationApiController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return response()->json([], 200);
        }

        $action = trim((string) $request->input('action', $request->query('action', '')));

        return match ($action) {
            'login' => $this->login($request),
            'session_login' => $this->sessionLogin($request),
            'get_appointments' => $this->getAppointments($request),
            'get_consultation' => $this->getConsultation($request),
            'logout' => response()->json(['success' => true, 'message' => 'Logged out.']),
            default => response()->json(['success' => false, 'message' => 'Unknown action']),
        };
    }

    private function login(Request $request): JsonResponse
    {
        $doctorId = trim((string) $request->input('doctor_id', ''));
        $password = (string) $request->input('password', '');
        if ($doctorId === '' || $password === '') {
            return response()->json(['success' => false, 'message' => 'Doctor ID and password are required.']);
        }

        $doctor = DB::connection('appointments')->table('doctors')->where('doctor_id', $doctorId)->first();
        if ($doctor && password_verify($password, $doctor->password_hash)) {
            return $this->loginResponse($doctor);
        }

        $ehrUser = DB::connection('ehr')->table('users')
            ->where('username', $doctorId)
            ->whereIn('role', ['doctor', 'admin'])
            ->where('is_active', 1)
            ->first();

        if (!$ehrUser || !password_verify($password, $ehrUser->password_hash)) {
            return response()->json(['success' => false, 'message' => 'Invalid Doctor ID or password.']);
        }

        $doctor = $this->ensureDoctorRecord(
            $doctorId,
            $ehrUser->full_name ?: $ehrUser->username,
            $ehrUser->specialty,
            $ehrUser->email
        );

        return $this->loginResponse($doctor);
    }

    private function sessionLogin(Request $request): JsonResponse
    {
        $role = $request->session()->get('role');
        if (!$request->session()->get('user_id') || !in_array($role, ['admin', 'doctor'], true)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized. Please log in.'], 401);
        }

        $username = (string) $request->session()->get('username', '');
        if ($username === '') {
            return response()->json(['success' => false, 'message' => 'No EHR session found.']);
        }

        $doctor = $this->ensureDoctorRecord(
            $username,
            (string) $request->session()->get('full_name', $username),
            null,
            null
        );

        return $this->loginResponse($doctor);
    }

    private function getAppointments(Request $request): JsonResponse
    {
        $doctorId = $this->authenticatedDoctorId($request);
        if ($doctorId instanceof JsonResponse) {
            return $doctorId;
        }

        $today = today()->toDateString();
        $appointments = DB::connection('appointments')->table('appointments')
            ->select('id', 'patient_name', 'patient_email', 'patient_phone', 'ehr_patient_id', 'appt_date', 'appt_time', 'complaint', 'status')
            ->where('doctor_id', $doctorId)
            ->whereDate('appt_date', $today)
            ->where('type', 'online')
            ->where('status', 'scheduled')
            ->orderBy('appt_time')
            ->get()
            ->map(function (object $appointment): array {
                $row = (array) $appointment;
                $row['appt_time_fmt'] = date('h:i A', strtotime((string) $appointment->appt_time));
                $row['appt_time_raw'] = $appointment->appt_time;

                return $row;
            })->all();

        return response()->json(['success' => true, 'date' => $today, 'appointments' => $appointments]);
    }

    private function getConsultation(Request $request): JsonResponse
    {
        $doctorId = $this->authenticatedDoctorId($request);
        if ($doctorId instanceof JsonResponse) {
            return $doctorId;
        }

        $appointmentId = (int) $request->query('appointment_id', 0);
        if (!$appointmentId) {
            return response()->json(['success' => false, 'message' => 'appointment_id is required.']);
        }

        $appointment = DB::connection('appointments')->table('appointments')
            ->select('id', 'patient_name', 'patient_email', 'patient_phone', 'ehr_patient_id', 'appt_date', 'appt_time', 'complaint', 'status', 'notes')
            ->where('id', $appointmentId)
            ->where('doctor_id', $doctorId)
            ->first();

        if (!$appointment) {
            return response()->json(['success' => false, 'message' => 'Appointment not found or access denied.']);
        }

        $appointment = (array) $appointment;
        $appointment['appt_time_fmt'] = date('h:i A', strtotime((string) $appointment['appt_time']));
        if (!$appointment['ehr_patient_id']) {
            return response()->json([
                'success' => true,
                'patient_type' => 'new',
                'appointment' => $appointment,
                'ehr' => null,
            ]);
        }

        $patientId = $appointment['ehr_patient_id'];
        $ehr = DB::connection('ehr');
        $patient = $ehr->table('patients')->where('patient_id', $patientId)->first();
        if (!$patient) {
            return response()->json([
                'success' => true,
                'patient_type' => 'new',
                'appointment' => $appointment,
                'ehr' => null,
            ]);
        }

        $vitals = $ehr->table('vitals')->where('patient_id', $patientId)->orderByDesc('id')->first();
        $medications = $ehr->table('medications')->where('patient_id', $patientId)
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'inactive' THEN 1 WHEN 'stopped' THEN 2 ELSE 3 END")
            ->orderByDesc('id')->get();
        $labs = $ehr->table('lab_results')->where('patient_id', $patientId)->orderByDesc('id')->get();

        return response()->json([
            'success' => true,
            'patient_type' => 'existing',
            'appointment' => $appointment,
            'ehr' => [
                'patient' => (array) $patient,
                'vitals' => $vitals,
                'medications' => $medications,
                'labs' => $labs,
            ],
        ]);
    }

    private function loginResponse(object $doctor): JsonResponse
    {
        return response()->json([
            'success' => true,
            'token' => $this->createToken((string) $doctor->doctor_id),
            'doctor' => [
                'doctor_id' => $doctor->doctor_id,
                'full_name' => $doctor->full_name,
                'specialty' => $doctor->specialty,
                'email' => $doctor->email,
                'avatar_color' => $doctor->avatar_color,
            ],
        ]);
    }

    private function ensureDoctorRecord(string $doctorId, string $fullName, ?string $specialty, ?string $email): object
    {
        $database = DB::connection('appointments');
        $doctor = $database->table('doctors')->where('doctor_id', $doctorId)->first();

        if (!$doctor) {
            $colors = ['#0b3c7a', '#1a73e8', '#00b4a6', '#7c3aed', '#c2410c'];
            $database->table('doctors')->insert([
                'doctor_id' => $doctorId,
                'password_hash' => password_hash(Str::random(48), PASSWORD_BCRYPT),
                'full_name' => $fullName,
                'specialty' => $specialty,
                'email' => $email,
                'avatar_color' => $colors[array_rand($colors)],
                'created_at' => now(),
            ]);
            $doctor = $database->table('doctors')->where('doctor_id', $doctorId)->first();
        }

        return $doctor;
    }

    private function createToken(string $doctorId): string
    {
        $payload = $doctorId . ':' . time() . ':' . bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $payload, (string) config('app.key'));

        return base64_encode($payload . ':' . $signature);
    }

    private function authenticatedDoctorId(Request $request): string|JsonResponse
    {
        $token = '';
        $authorization = (string) $request->header('Authorization', '');
        if (str_starts_with($authorization, 'Bearer ')) {
            $token = substr($authorization, 7);
        } else {
            $token = (string) $request->query('token', '');
        }

        $decoded = base64_decode($token, true);
        $parts = $decoded === false ? [] : explode(':', $decoded, 4);
        if (count($parts) !== 4) {
            return response()->json(['success' => false, 'message' => 'Unauthorised. Please log in.'], 401);
        }

        [$doctorId, $issuedAt, $nonce, $signature] = $parts;
        $payload = implode(':', [$doctorId, $issuedAt, $nonce]);
        $expected = hash_hmac('sha256', $payload, (string) config('app.key'));
        if (!hash_equals($expected, $signature) || time() - (int) $issuedAt > 28800 || (int) $issuedAt > time() + 60) {
            return response()->json(['success' => false, 'message' => 'Session expired. Please log in again.'], 401);
        }

        $exists = DB::connection('appointments')->table('doctors')->where('doctor_id', $doctorId)->exists();
        if (!$exists) {
            return response()->json(['success' => false, 'message' => 'Doctor account not found.'], 401);
        }

        return $doctorId;
    }
}