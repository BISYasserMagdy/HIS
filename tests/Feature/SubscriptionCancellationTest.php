<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SubscriptionCancellationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ehr', 'pharmacy'] as $connection) {
            config([
                "database.connections.$connection" => array_merge(config('database.connections.sqlite'), [
                    'database' => ':memory:',
                ]),
            ]);
            DB::purge($connection);
        }

        Schema::connection('pharmacy')->create('subscriptions', function ($table): void {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('status');
        });
        Schema::connection('pharmacy')->create('pharmacists', function ($table): void {
            $table->increments('id');
            $table->string('email')->nullable();
            $table->string('status');
        });

        Schema::connection('ehr')->create('hospitals', function ($table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('email');
            $table->string('status');
        });
        Schema::connection('ehr')->create('users', function ($table): void {
            $table->increments('id');
            $table->string('hospital');
            $table->string('role');
            $table->boolean('is_active')->default(true);
        });
    }

    public function test_pharmacy_cancellation_updates_subscription_and_pharmacist_atomically(): void
    {
        DB::connection('pharmacy')->table('subscriptions')->insert([
            'email' => 'owner@example.com',
            'status' => 'active',
        ]);
        DB::connection('pharmacy')->table('pharmacists')->insert([
            'email' => 'owner@example.com',
            'status' => 'active',
        ]);

        $this->postJson('/Back%20End/cancel_subscription.php', ['email' => 'owner@example.com'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('cancelled', DB::connection('pharmacy')->table('subscriptions')->value('status'));
        $this->assertSame('inactive', DB::connection('pharmacy')->table('pharmacists')->value('status'));
    }

    public function test_ehr_cancellation_deactivates_hospital_staff_without_deleting_records(): void
    {
        DB::connection('ehr')->table('hospitals')->insert([
            'name' => 'Example Hospital',
            'email' => 'owner@example.com',
            'status' => 'active',
        ]);
        DB::connection('ehr')->table('users')->insert([
            ['hospital' => 'Example Hospital', 'role' => 'doctor', 'is_active' => true],
            ['hospital' => 'Other Hospital', 'role' => 'doctor', 'is_active' => true],
        ]);

        $this->postJson('/Back%20End/cancel_subscription_ehr.php', ['email' => 'owner@example.com'])
            ->assertOk()
            ->assertJson(['success' => true, 'hospital' => 'Example Hospital', 'deactivated' => 1]);

        $this->assertSame('cancelled', DB::connection('ehr')->table('hospitals')->value('status'));
        $this->assertSame(0, DB::connection('ehr')->table('users')->where('hospital', 'Example Hospital')->value('is_active'));
        $this->assertSame(1, DB::connection('ehr')->table('users')->where('hospital', 'Other Hospital')->value('is_active'));
    }
}
