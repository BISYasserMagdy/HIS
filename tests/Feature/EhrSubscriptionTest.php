<?php

namespace Tests\Feature;

use App\Services\StripePaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EhrSubscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.ehr' => array_merge(config('database.connections.sqlite'), [
                'database' => ':memory:',
            ]),
            'services.stripe.secret' => 'sk_test_fake',
            'services.stripe.ehr_plans' => [
                'monthly' => 500,
                'annual' => 6000,
            ],
            'mail.default' => 'array',
        ]);
        DB::purge('ehr');

        Schema::connection('ehr')->create('hospitals', function ($table): void {
            $table->increments('id');
            $table->string('name')->unique();
            $table->string('email')->nullable();
            $table->string('plan')->nullable();
            $table->string('status')->default('active');
        });
        Schema::connection('ehr')->create('users', function ($table): void {
            $table->increments('id');
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('role');
            $table->string('full_name')->nullable();
            $table->string('specialty')->nullable();
            $table->string('hospital');
            $table->boolean('is_active')->default(true);
        });
    }

    public function test_payment_intent_uses_server_side_plan_amount_and_metadata(): void
    {
        $stripe = \Mockery::mock(StripePaymentService::class);
        $stripe->shouldReceive('createEhrPaymentIntent')
            ->once()
            ->with('monthly', 500, 'owner@example.com', 'Example Hospital')
            ->andReturn(['id' => 'pi_test_123', 'client_secret' => 'pi_test_secret']);
        $this->instance(StripePaymentService::class, $stripe);

        $this->postJson('/Back%20End/create_payment_intent.php', [
            'email' => 'owner@example.com',
            'plan' => 'monthly',
            'hospital_name' => 'Example Hospital',
            'amount' => 1,
        ])->assertOk()->assertJson([
            'success' => true,
            'client_secret' => 'pi_test_secret',
        ]);
    }

    public function test_unconfirmed_payment_does_not_create_hospital_or_accounts(): void
    {
        $stripe = \Mockery::mock(StripePaymentService::class);
        $stripe->shouldReceive('retrieveEhrPaymentIntent')
            ->once()
            ->with('pi_test_unpaid')
            ->andReturn([
                'status' => 'requires_payment_method',
                'amount' => 500,
                'metadata' => [
                    'plan' => 'monthly',
                    'email' => 'owner@example.com',
                    'hospital_name' => 'Example Hospital',
                ],
            ]);
        $this->instance(StripePaymentService::class, $stripe);

        $this->postJson('/Back%20End/subscribe_ehr.php', [
            'email' => 'owner@example.com',
            'plan' => 'monthly',
            'payment_method' => 'visa',
            'hospital_name' => 'Example Hospital',
            'payment_intent_id' => 'pi_test_unpaid',
        ])->assertOk()->assertJson([
            'success' => false,
            'message' => 'Payment details do not match this subscription request.',
        ]);

        $this->assertSame(0, DB::connection('ehr')->table('hospitals')->count());
        $this->assertSame(0, DB::connection('ehr')->table('users')->count());
    }

    public function test_confirmed_payment_creates_hospital_and_four_staff_accounts(): void
    {
        $stripe = \Mockery::mock(StripePaymentService::class);
        $stripe->shouldReceive('retrieveEhrPaymentIntent')
            ->once()
            ->with('pi_test_paid')
            ->andReturn([
                'status' => 'succeeded',
                'amount' => 500,
                'metadata' => [
                    'plan' => 'monthly',
                    'email' => 'owner@example.com',
                    'hospital_name' => 'Example Hospital',
                ],
            ]);
        $this->instance(StripePaymentService::class, $stripe);

        $this->postJson('/Back%20End/subscribe_ehr.php', [
            'email' => 'owner@example.com',
            'plan' => 'monthly',
            'payment_method' => 'visa',
            'hospital_name' => 'Example Hospital',
            'payment_intent_id' => 'pi_test_paid',
        ])->assertOk()->assertJson([
            'success' => true,
            'email_sent' => true,
            'hospital' => 'Example Hospital',
        ])->assertJsonMissingPath('accounts');

        $this->assertSame(1, DB::connection('ehr')->table('hospitals')->count());
        $this->assertSame(4, DB::connection('ehr')->table('users')->count());
        $this->assertSame(4, DB::connection('ehr')->table('users')->where('is_active', true)->count());
    }
}
