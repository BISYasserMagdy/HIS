<?php

namespace App\Services;

use Stripe\StripeClient;

class StripePaymentService
{
    public function createEhrPaymentIntent(string $plan, int $amount, string $email, string $hospitalName): array
    {
        $intent = $this->client()->paymentIntents->create([
            'amount' => $amount,
            'currency' => 'usd',
            'metadata' => [
                'plan' => $plan,
                'email' => $email,
                'hospital_name' => $hospitalName,
                'product' => 'pharos_his_ehr_subscription',
            ],
            'receipt_email' => $email,
        ]);

        return [
            'id' => $intent->id,
            'client_secret' => $intent->client_secret,
        ];
    }

    public function retrieveEhrPaymentIntent(string $paymentIntentId): array
    {
        $intent = $this->client()->paymentIntents->retrieve($paymentIntentId);

        return [
            'status' => $intent->status,
            'amount' => (int) $intent->amount,
            'metadata' => $intent->metadata->toArray(),
        ];
    }

    private function client(): StripeClient
    {
        $secret = config('services.stripe.secret');
        if (!$secret) {
            throw new \RuntimeException('Stripe secret key is not configured.');
        }

        return new StripeClient($secret);
    }
}
