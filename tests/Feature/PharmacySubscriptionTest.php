<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PharmacySubscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.pharmacy' => array_merge(config('database.connections.sqlite'), [
                'database' => ':memory:',
            ]),
            'mail.default' => 'array',
        ]);
        DB::purge('pharmacy');

        Schema::connection('pharmacy')->create('subscriptions', function ($table): void {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('plan');
            $table->string('payment_method');
            $table->string('manager_id');
            $table->string('manager_pass');
            $table->string('employee_id');
            $table->string('employee_pass');
            $table->string('status');
            $table->timestamp('subscribed_at')->nullable();
        });
        Schema::connection('pharmacy')->create('pharmacists', function ($table): void {
            $table->increments('id');
            $table->string('pharmacist_id')->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('status');
            $table->string('password_hash');
        });
    }

    public function test_pharmacy_subscription_saves_hashed_credentials_and_sends_email(): void
    {
        $response = $this->postJson('/Back%20End/subscribe.php', [
            'email' => 'owner@example.com',
            'plan' => 'monthly',
            'payment_method' => 'cash',
        ])->assertOk()->assertJson([
            'success' => true,
            'email_sent' => true,
        ])->assertJsonStructure(['manager_id', 'employee_id']);

        $subscription = DB::connection('pharmacy')->table('subscriptions')->first();
        $this->assertSame($response->json('manager_id'), $subscription->manager_id);
        $this->assertSame('bcrypt', password_get_info($subscription->manager_pass)['algoName']);
        $this->assertSame('bcrypt', password_get_info($subscription->employee_pass)['algoName']);
        $this->assertArrayNotHasKey('manager_pass', $response->json());
        $this->assertArrayNotHasKey('employee_pass', $response->json());
        $this->assertSame('active', DB::connection('pharmacy')->table('pharmacists')->value('status'));
    }

    public function test_active_pharmacy_subscription_cannot_be_created_twice(): void
    {
        DB::connection('pharmacy')->table('subscriptions')->insert([
            'email' => 'owner@example.com',
            'plan' => 'monthly',
            'payment_method' => 'cash',
            'manager_id' => 'MGR-EXISTING',
            'manager_pass' => password_hash('secret', PASSWORD_BCRYPT),
            'employee_id' => 'EMP-EXISTING',
            'employee_pass' => password_hash('secret', PASSWORD_BCRYPT),
            'status' => 'active',
        ]);

        $this->postJson('/Back%20End/subscribe.php', [
            'email' => 'owner@example.com',
            'plan' => 'monthly',
            'payment_method' => 'cash',
        ])->assertOk()->assertJson([
            'success' => false,
            'message' => 'This email is already subscribed.',
        ]);

        $this->assertSame(1, DB::connection('pharmacy')->table('subscriptions')->count());
    }
}
