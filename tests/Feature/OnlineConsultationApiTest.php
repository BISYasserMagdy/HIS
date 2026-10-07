<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OnlineConsultationApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['appointments', 'ehr'] as $connection) {
            config([
                "database.connections.$connection" => array_merge(config('database.connections.sqlite'), [
                    'database' => ':memory:',
                ]),
            ]);
            DB::purge($connection);
        }

        Schema::connection('appointments')->create('doctors', function ($table): void {
            $table->increments('id');
            $table->string('doctor_id')->unique();
            $table->string('password_hash');
            $table->string('full_name');
            $table->string('specialty')->nullable();
            $table->string('email')->nullable();
            $table->string('avatar_color')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::connection('appointments')->create('appointments', function ($table): void {
            $table->increments('id');
            $table->string('doctor_id');
            $table->string('patient_name');
            $table->string('patient_email')->nullable();
            $table->string('patient_phone')->nullable();
            $table->string('ehr_patient_id')->nullable();
            $table->date('appt_date');
            $table->time('appt_time');
            $table->text('complaint')->nullable();
            $table->string('status')->default('scheduled');
            $table->string('type')->default('online');
            $table->text('notes')->nullable();
        });
        Schema::connection('ehr')->create('users', function ($table): void {
            $table->increments('id');
            $table->string('username')->unique();
            $table->string('email')->nullable();
            $table->string('password_hash');
            $table->string('role');
            $table->string('full_name')->nullable();
            $table->string('specialty')->nullable();
            $table->boolean('is_active')->default(true);
        });

        DB::connection('appointments')->table('doctors')->insert([
            'doctor_id' => 'DR-001',
            'password_hash' => password_hash('doctor123', PASSWORD_BCRYPT),
            'full_name' => 'Dr. Sarah Al-Hassan',
            'specialty' => 'Internal Medicine',
            'email' => 'doctor@example.com',
            'avatar_color' => '#123456',
        ]);
        DB::connection('appointments')->table('appointments')->insert([
            'id' => 1,
            'doctor_id' => 'DR-001',
            'patient_name' => 'Layla Mansour',
            'appt_date' => today()->toDateString(),
            'appt_time' => '10:30:00',
            'complaint' => 'Headache',
            'status' => 'scheduled',
            'type' => 'online',
        ]);
    }

    public function test_doctor_login_returns_a_signed_token_for_appointments(): void
    {
        $login = $this->postJson('/Back%20End/Online_Consultation_API.php?action=login', [
            'doctor_id' => 'DR-001',
            'password' => 'doctor123',
        ])->assertOk()->assertJsonPath('doctor.full_name', 'Dr. Sarah Al-Hassan');

        $token = $login->json('token');
        $this->getJson('/Back%20End/Online_Consultation_API.php?action=get_appointments&token=' . urlencode($token))
            ->assertOk()
            ->assertJsonPath('appointments.0.patient_name', 'Layla Mansour')
            ->assertJsonPath('appointments.0.appt_time_fmt', '10:30 AM');
    }

    public function test_invalid_tokens_cannot_read_appointments(): void
    {
        $this->getJson('/Back%20End/Online_Consultation_API.php?action=get_appointments&token=forged')
            ->assertUnauthorized();
    }
}