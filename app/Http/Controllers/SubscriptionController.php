<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\StripePaymentService;

class SubscriptionController extends Controller
{
    public function createEhrPaymentIntent(Request $request, StripePaymentService $stripe): JsonResponse
    {
        $email = filter_var(trim((string) $request->input('email', '')), FILTER_VALIDATE_EMAIL);
        $plan = trim((string) $request->input('plan', ''));
        $hospitalName = trim((string) $request->input('hospital_name', ''));
        $plans = config('services.stripe.ehr_plans', []);
        $amount = $plans[$plan] ?? null;

        if ($amount === null) {
            return response()->json(['success' => false, 'message' => 'Unknown or missing plan.']);
        }
        if (!$email) {
            return response()->json(['success' => false, 'message' => 'A valid email address is required.']);
        }
        if ($hospitalName === '') {
            return response()->json(['success' => false, 'message' => 'Hospital name is required.']);
        }

        if (!config('services.stripe.secret')) {
            return response()->json(['success' => false, 'message' => 'Stripe is not configured on the server.'], 503);
        }

        try {
            $hospital = DB::connection('ehr')->table('hospitals')->where('name', $hospitalName)->first();
        } catch (\Throwable) {
            return response()->json(['success' => false, 'message' => 'Could not check the hospital subscription.'], 503);
        }

        if ($hospital && $hospital->status === 'active') {
            return response()->json(['success' => false, 'message' => 'A hospital with this name is already subscribed.']);
        }

        try {
            $intent = $stripe->createEhrPaymentIntent($plan, (int) $amount, $email, $hospitalName);
        } catch (\Throwable) {
            return response()->json(['success' => false, 'message' => 'Could not start payment.'], 502);
        }

        return response()->json(['success' => true, 'client_secret' => $intent['client_secret']]);
    }

    public function subscribeEhr(Request $request, StripePaymentService $stripe): JsonResponse
    {
        $email = filter_var(trim((string) $request->input('email', '')), FILTER_VALIDATE_EMAIL);
        $plan = trim((string) $request->input('plan', ''));
        $paymentMethod = trim((string) $request->input('payment_method', ''));
        $hospitalName = trim((string) $request->input('hospital_name', ''));
        $plans = config('services.stripe.ehr_plans', []);
        $amount = $plans[$plan] ?? null;

        if (!$email || $amount === null || $paymentMethod === '' || $hospitalName === '') {
            return response()->json([
                'success' => false,
                'message' => 'Missing or invalid fields. Hospital name and a valid email are required.',
            ]);
        }

        $database = DB::connection('ehr');

        try {
            $existing = $database->table('hospitals')->where('name', $hospitalName)->first();
        } catch (\Throwable) {
            return response()->json(['success' => false, 'message' => 'Could not access the EHR database.'], 503);
        }

        if ($existing && $existing->status === 'active') {
            return response()->json(['success' => false, 'message' => 'A hospital with this name is already subscribed.']);
        }

        if ($paymentMethod === 'visa') {
            $paymentIntentId = trim((string) $request->input('payment_intent_id', ''));
            if ($paymentIntentId === '') {
                return response()->json(['success' => false, 'message' => 'Missing payment confirmation. Please try again.']);
            }
            if (!config('services.stripe.secret')) {
                return response()->json(['success' => false, 'message' => 'Stripe is not configured on the server.'], 503);
            }

            try {
                $intent = $stripe->retrieveEhrPaymentIntent($paymentIntentId);
            } catch (\Throwable) {
                return response()->json(['success' => false, 'message' => 'Could not verify payment.'], 502);
            }

            $metadata = $intent['metadata'];
            if ($intent['status'] !== 'succeeded'
                || $intent['amount'] !== (int) $amount
                || ($metadata['plan'] ?? null) !== $plan
                || ($metadata['email'] ?? null) !== $email
                || ($metadata['hospital_name'] ?? null) !== $hospitalName) {
                return response()->json(['success' => false, 'message' => 'Payment details do not match this subscription request.']);
            }
        }

        $suffix = strtoupper(Str::random(4));
        $slug = Str::of($hospitalName)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(35, '');
        $slug = (string) $slug ?: 'hospital';
        $emailParts = explode('@', $email, 2);
        $roleConfig = [
            'admin' => ['System Administrator', null],
            'manager' => ['Hospital Manager', null],
            'doctor' => ['Dr. New Doctor', 'General Medicine'],
            'nurse' => ['New Nurse', null],
        ];
        $accounts = [];

        foreach ($roleConfig as $role => [$fullName, $specialty]) {
            $password = Str::password(12);
            $accounts[$role] = [
                'username' => "{$role}_{$slug}_{$suffix}",
                'email' => $emailParts[0] . '+' . $role . '_' . strtolower($suffix) . '@' . ($emailParts[1] ?? 'example.com'),
                'password' => $password,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                'full_name' => $fullName,
                'specialty' => $specialty,
            ];
        }

        try {
            $database->transaction(function () use ($database, $existing, $hospitalName, $email, $plan, $accounts): void {
                if ($existing) {
                    $database->table('hospitals')->where('id', $existing->id)->update([
                        'email' => $email,
                        'plan' => $plan,
                        'status' => 'active',
                    ]);
                } else {
                    $database->table('hospitals')->insert([
                        'name' => $hospitalName,
                        'email' => $email,
                        'plan' => $plan,
                        'status' => 'active',
                    ]);
                }

                foreach ($accounts as $role => $account) {
                    $database->table('users')->insert([
                        'username' => $account['username'],
                        'email' => $account['email'],
                        'password_hash' => $account['password_hash'],
                        'role' => $role,
                        'full_name' => $account['full_name'],
                        'specialty' => $account['specialty'],
                        'hospital' => $hospitalName,
                        'is_active' => true,
                    ]);
                }
            });
        } catch (\Throwable) {
            return response()->json(['success' => false, 'message' => 'Could not create the hospital accounts.'], 500);
        }

        $emailSent = false;
        $emailError = '';
        try {
            $accountRows = '';
            foreach ($accounts as $role => $account) {
                $accountRows .= '<h3>' . e(ucfirst($role)) . ' account</h3><p>Username: '
                    . e($account['username']) . '<br>Password: ' . e($account['password']) . '</p>';
            }
            $body = '<h2>EHR subscription confirmed for ' . e($hospitalName) . '</h2>' . $accountRows;
            Mail::html($body, function ($message) use ($email, $hospitalName): void {
                $message->to($email)->subject("Your Pharos HIS EHR credentials — {$hospitalName}");
            });
            $emailSent = true;
        } catch (\Throwable $exception) {
            $emailError = $exception->getMessage();
        }

        $response = [
            'success' => true,
            'message' => $emailSent
                ? "Subscription successful for {$hospitalName}. Credentials sent to {$email}."
                : "Subscription saved, but the credentials email failed ({$emailError}). Showing credentials below — please save them now and change passwords after first login.",
            'email_sent' => $emailSent,
            'hospital' => $hospitalName,
            'usernames' => array_map(fn (array $account): string => $account['username'], $accounts),
        ];

        if (!$emailSent) {
            $response['accounts'] = array_map(
                fn (array $account): array => ['username' => $account['username'], 'password' => $account['password']],
                $accounts
            );
        }

        return response()->json($response);
    }

    public function subscribePharmacy(Request $request): JsonResponse
    {
        $email = filter_var(trim((string) $request->input('email', '')), FILTER_VALIDATE_EMAIL);
        $plan = trim((string) $request->input('plan', ''));
        $paymentMethod = trim((string) $request->input('payment_method', ''));

        if (!$email || $plan === '' || $paymentMethod === '') {
            return response()->json(['success' => false, 'message' => 'Missing or invalid fields.']);
        }

        $database = DB::connection('pharmacy');
        $existing = $database->table('subscriptions')->where('email', $email)->first();

        if ($existing && $existing->status === 'active') {
            return response()->json(['success' => false, 'message' => 'This email is already subscribed.']);
        }

        $managerId = $this->generateCredentialId('MGR');
        $managerPassword = Str::random(12);
        $employeeId = $this->generateCredentialId('EMP');
        $employeePassword = Str::random(12);
        $managerHash = password_hash($managerPassword, PASSWORD_BCRYPT);
        $employeeHash = password_hash($employeePassword, PASSWORD_BCRYPT);
        $managerName = 'Manager (' . $email . ')';

        $database->transaction(function () use (
            $database,
            $existing,
            $email,
            $plan,
            $paymentMethod,
            $managerId,
            $managerHash,
            $employeeId,
            $employeeHash,
            $managerName
        ): void {
            $subscription = [
                'plan' => $plan,
                'payment_method' => $paymentMethod,
                'manager_id' => $managerId,
                'manager_pass' => $managerHash,
                'employee_id' => $employeeId,
                'employee_pass' => $employeeHash,
                'status' => 'active',
                'subscribed_at' => now(),
            ];

            if ($existing) {
                $database->table('subscriptions')->where('email', $email)->update($subscription);
            } else {
                $database->table('subscriptions')->insert($subscription + ['email' => $email]);
            }

            $database->table('pharmacists')->updateOrInsert(
                ['email' => $email],
                [
                    'pharmacist_id' => $managerId,
                    'name' => $managerName,
                    'status' => 'active',
                    'password_hash' => $managerHash,
                ]
            );
        });

        $emailSent = false;
        $emailError = '';

        try {
            $safePlan = e(strtoupper($plan));
            $emailBody = "<h2>Pharmacy ERP subscription confirmed</h2>
                <p>Your Pharos HIS subscription is active. Plan: {$safePlan}</p>
                <h3>Manager account</h3><p>ID: {$managerId}<br>Password: {$managerPassword}</p>
                <h3>Employee account</h3><p>ID: {$employeeId}<br>Password: {$employeePassword}</p>
                <p>Please change these passwords after your first login.</p>";

            Mail::html($emailBody, function ($message) use ($email): void {
                $message->to($email)->subject('Your Pharos HIS Pharmacy ERP credentials');
            });
            $emailSent = true;
        } catch (\Throwable $exception) {
            $emailError = $exception->getMessage();
        }

        return response()->json([
            'success' => true,
            'message' => $emailSent
                ? "Subscription successful. Credentials sent to $email."
                : "Subscription saved, but the credentials email could not be sent ($emailError). Please contact support.",
            'email_sent' => $emailSent,
            'manager_id' => $managerId,
            'employee_id' => $employeeId,
        ]);
    }

    public function cancelPharmacy(Request $request): JsonResponse
    {
        $email = filter_var(trim((string) $request->input('email', '')), FILTER_VALIDATE_EMAIL);

        if (!$email) {
            return response()->json(['success' => false, 'message' => 'Invalid email address.']);
        }

        $database = DB::connection('pharmacy');
        $subscription = $database->table('subscriptions')->where('email', $email)->first();

        if (!$subscription) {
            return response()->json(['success' => false, 'message' => 'No subscription found for this email.']);
        }

        if ($subscription->status === 'cancelled') {
            return response()->json(['success' => false, 'message' => 'Subscription is already cancelled.']);
        }

        $database->transaction(function () use ($database, $email): void {
            $database->table('subscriptions')->where('email', $email)->update(['status' => 'cancelled']);
            $database->table('pharmacists')->where('email', $email)->update(['status' => 'inactive']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Subscription for ' . $email . ' has been cancelled. Access remains active until end of billing period.',
        ]);
    }

    public function cancelEhr(Request $request): JsonResponse
    {
        $email = filter_var(trim((string) $request->input('email', '')), FILTER_VALIDATE_EMAIL);

        if (!$email) {
            return response()->json(['success' => false, 'message' => 'Please enter a valid email address.']);
        }

        $database = DB::connection('ehr');
        $hospital = $database->table('hospitals')->where('email', $email)->first();

        if (!$hospital) {
            return response()->json(['success' => false, 'message' => 'No subscription found for this email address.']);
        }

        if ($hospital->status === 'cancelled') {
            return response()->json(['success' => false, 'message' => 'This subscription is already cancelled.']);
        }

        $deactivated = $database->transaction(function () use ($database, $hospital): int {
            $database->table('hospitals')->where('id', $hospital->id)->update(['status' => 'cancelled']);

            return $database->table('users')
                ->where('hospital', $hospital->name)
                ->whereIn('role', ['admin', 'manager', 'doctor', 'nurse'])
                ->update(['is_active' => false]);
        });

        return response()->json([
            'success' => true,
            'message' => 'The EHR subscription for ' . $hospital->name . ' has been cancelled and ' . $deactivated . ' staff account(s) deactivated.',
            'hospital' => $hospital->name,
            'deactivated' => $deactivated,
        ]);
    }

    private function generateCredentialId(string $prefix): string
    {
        return $prefix . '-' . strtoupper(Str::random(8));
    }
}
