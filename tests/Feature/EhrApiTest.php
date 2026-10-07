<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EhrApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.ehr' => array_merge(config('database.connections.sqlite'), [
                'database' => ':memory:',
            ]),
        ]);
        DB::purge('ehr');

        Schema::connection('ehr')->create('users', function ($table): void {
            $table->increments('id');
            $table->string('username')->unique();
            $table->string('email')->nullable()->unique();
            $table->string('password_hash');
            $table->string('role');
            $table->string('full_name')->nullable();
            $table->string('hospital')->nullable();
            $table->string('specialty')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::connection('ehr')->create('user_sessions', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('session_token', 64);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('action');
            $table->timestamps();
        });

        Schema::connection('ehr')->create('profile_requests', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('hospital');
            $table->string('field');
            $table->string('old_value')->nullable();
            $table->string('new_value');
            $table->string('status')->default('pending');
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::connection('ehr')->create('clinical_rules', function ($table): void {
            $table->increments('id');
            $table->string('rule_code')->unique();
            $table->string('rule_name');
            $table->text('description')->nullable();
            $table->string('domain')->default('vital_sign');
            $table->string('severity')->default('warning');
            $table->unsignedInteger('severity_tier')->default(2);
            $table->unsignedInteger('suppression_window_hrs')->default(24);
            $table->unsignedInteger('cooldown_hrs')->default(0);
            $table->boolean('requires_acknowledgment')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::connection('ehr')->create('rule_criteria', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('rule_id');
            $table->string('data_domain');
            $table->string('parameter_key');
            $table->string('operator');
            $table->decimal('threshold_value', 12, 4)->nullable();
            $table->string('threshold_unit')->nullable();
            $table->string('string_match_pattern')->nullable();
            $table->string('logic_join')->default('AND');
            $table->unsignedInteger('sort_order')->default(0);
        });

        Schema::connection('ehr')->create('patient_alert_states', function ($table): void {
            $table->increments('id');
            $table->string('patient_id');
            $table->unsignedInteger('rule_id');
            $table->string('state')->default('active');
            $table->unsignedInteger('severity_tier')->default(2);
            $table->text('matched_criteria_snapshot')->nullable();
            $table->timestamp('triggered_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
        });

        Schema::connection('ehr')->create('patients', function ($table): void {
            $table->increments('id');
            $table->string('patient_id')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('dob');
            $table->string('gender');
            $table->string('blood_type')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('insurance')->nullable();
            $table->string('physician')->nullable();
            $table->unsignedInteger('physician_id')->nullable();
            $table->text('allergies')->nullable();
            $table->text('conditions')->nullable();
            $table->string('smoking')->nullable();
            $table->string('alcohol')->nullable();
            $table->text('pmh')->nullable();
            $table->text('fmh')->nullable();
            $table->text('surgical')->nullable();
            $table->text('vaccines')->nullable();
            $table->string('avatar_color')->nullable();
            $table->string('status')->nullable();
            $table->string('last_visit')->nullable();
            $table->string('next_appt')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::connection('ehr')->create('timeline', function ($table): void {
            $table->increments('id');
            $table->string('patient_id');
            $table->string('entry_date');
            $table->string('dot_type')->nullable();
            $table->text('entry_text');
            $table->timestamp('created_at')->nullable();
        });

        Schema::connection('ehr')->create('vitals', function ($table): void {
            $table->increments('id');
            $table->string('patient_id');
            $table->string('recorded_at')->nullable();
            $table->string('nurse')->nullable();
            $table->string('bp')->nullable();
            $table->string('bp_status')->nullable();
            $table->string('hr')->nullable();
            $table->string('temp')->nullable();
            $table->string('spo2')->nullable();
            $table->string('rr')->nullable();
            $table->string('bmi')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::connection('ehr')->create('medications', function ($table): void {
            $table->increments('id');
            $table->string('patient_id');
            $table->string('med_name');
            $table->string('dose')->nullable();
            $table->string('prescribed_by')->nullable();
            $table->string('start_date')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::connection('ehr')->create('lab_results', function ($table): void {
            $table->increments('id');
            $table->string('patient_id');
            $table->string('panel_date')->nullable();
            $table->string('test_name');
            $table->string('test_value')->nullable();
            $table->string('ref_range')->nullable();
            $table->integer('pct')->nullable();
            $table->string('cls')->nullable();
            $table->string('label')->nullable();
            $table->string('color_type')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::connection('ehr')->table('users')->insert([
            'id' => 1,
            'username' => 'doctor1',
            'password_hash' => password_hash('secret123', PASSWORD_BCRYPT),
            'role' => 'doctor',
            'full_name' => 'Dr. Example',
            'hospital' => 'Example Hospital',
            'specialty' => 'Cardiology',
            'is_active' => true,
        ]);
    }

    public function test_ehr_login_session_and_logout_keep_the_legacy_json_contract(): void
    {
        $this->postJson('/Back%20End/EHR_System.php?action=login', [
            'username' => 'doctor1',
            'password' => 'secret123',
        ])->assertOk()->assertJson([
            'success' => true,
            'role' => 'doctor',
        ]);

        $this->getJson('/Back%20End/EHR_System.php?action=whoami')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'logged_in' => true,
                'username' => 'doctor1',
                'specialty' => 'Cardiology',
            ]);

        $this->postJson('/Back%20End/EHR_System.php?action=logout')
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->getJson('/Back%20End/EHR_System.php?action=whoami')
            ->assertOk()
            ->assertJson(['success' => false, 'logged_in' => false]);
    }

    public function test_ehr_ping_does_not_require_a_database_connection(): void
    {
        $this->getJson('/Back%20End/EHR_System.php?action=ping')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'EHR Laravel API is reachable',
            ]);
    }

    public function test_doctor_can_add_and_search_a_patient_and_registration_is_recorded(): void
    {
        $this->withSession([
            'user_id' => 1,
            'username' => 'doctor1',
            'role' => 'doctor',
            'full_name' => 'Dr. Example',
            'hospital' => 'Example Hospital',
        ])->postJson('/Back%20End/EHR_System.php?action=add_patient', [
            'first_name' => 'Layla',
            'last_name' => 'Mansour',
            'dob' => '1990-04-15',
            'gender' => 'Female',
            'allergies' => 'Penicillin, Latex',
        ])->assertOk()->assertJson([
            'success' => true,
            'patient_id' => 'P-00001',
        ]);

        $this->withSession([
            'user_id' => 1,
            'username' => 'doctor1',
            'role' => 'doctor',
        ])->getJson('/Back%20End/EHR_System.php?action=get_patients&q=Layla')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'P-00001')
            ->assertJsonPath('data.0.allergies', ['Penicillin', 'Latex']);

        $this->assertDatabaseHas('timeline', [
            'patient_id' => 'P-00001',
            'dot_type' => 'ok',
        ], 'ehr');
    }

    public function test_patient_records_require_a_session_and_role(): void
    {
        $this->getJson('/Back%20End/EHR_System.php?action=get_patients')->assertUnauthorized();

        $this->withSession(['user_id' => 7, 'role' => 'nurse'])
            ->deleteJson('/Back%20End/EHR_System.php?action=delete_patient', ['patient_id' => 'P-00001'])
            ->assertForbidden();
    }

    public function test_patient_updates_and_timeline_entries_keep_patient_identity(): void
    {
        DB::connection('ehr')->table('patients')->insert([
            'patient_id' => 'P-00002',
            'first_name' => 'Omar',
            'last_name' => 'Khalil',
            'dob' => '1985-02-10',
            'gender' => 'Male',
            'created_at' => now(),
        ]);

        $session = [
            'user_id' => 1,
            'username' => 'doctor1',
            'role' => 'doctor',
        ];

        $this->withSession($session)
            ->postJson('/Back%20End/EHR_System.php?action=update_patient', [
                'patient_id' => 'P-00002',
                'blood_type' => 'O+',
                'physician' => 'Dr. Example',
            ])->assertOk()->assertJson(['success' => true]);

        $this->withSession($session)
            ->postJson('/Back%20End/EHR_System.php?action=add_timeline', [
                'patient_id' => 'P-00002',
                'entry_text' => 'Follow-up completed',
                'dot_type' => 'warn',
            ])->assertOk()->assertJson(['success' => true]);

        $this->withSession($session)
            ->getJson('/Back%20End/EHR_System.php?action=get_timeline&patient_id=P-00002')
            ->assertOk()
            ->assertJsonPath('data.0.entry_text', 'Follow-up completed');

        $this->assertDatabaseHas('patients', [
            'patient_id' => 'P-00002',
            'blood_type' => 'O+',
        ], 'ehr');
    }

    public function test_nurse_can_save_and_update_vitals_but_readers_only_need_view_role(): void
    {
        DB::connection('ehr')->table('patients')->insert([
            'patient_id' => 'P-00003',
            'first_name' => 'Nadia',
            'last_name' => 'Farouk',
            'dob' => '1978-11-20',
            'gender' => 'Female',
            'created_at' => now(),
        ]);

        $nurseSession = ['user_id' => 2, 'username' => 'nurse1', 'role' => 'nurse'];
        $this->withSession($nurseSession)
            ->postJson('/Back%20End/EHR_System.php?action=save_vitals', [
                'patient_id' => 'P-00003',
                'bp' => '120/80',
                'hr' => '72',
                'spo2' => '98',
            ])->assertOk()->assertJson(['success' => true]);

        $this->withSession($nurseSession)
            ->postJson('/Back%20End/EHR_System.php?action=update_vitals', [
                'patient_id' => 'P-00003',
                'bp' => '118/78',
            ])->assertOk()->assertJson(['success' => true]);

        $this->withSession(['user_id' => 3, 'username' => 'manager1', 'role' => 'manager'])
            ->getJson('/Back%20End/EHR_System.php?action=get_vitals&patient_id=P-00003')
            ->assertOk()
            ->assertJsonPath('data.bp', '118/78');
    }

    public function test_medication_and_lab_actions_enforce_their_clinical_roles(): void
    {
        DB::connection('ehr')->table('patients')->insert([
            'patient_id' => 'P-00004',
            'first_name' => 'Samir',
            'last_name' => 'Nabil',
            'dob' => '1988-08-02',
            'gender' => 'Male',
            'created_at' => now(),
        ]);

        $doctorSession = ['user_id' => 1, 'username' => 'doctor1', 'role' => 'doctor'];
        $this->withSession($doctorSession)
            ->postJson('/Back%20End/EHR_System.php?action=add_med', [
                'patient_id' => 'P-00004',
                'med_name' => 'Amoxicillin',
                'dose' => '500 mg twice daily',
                'prescribed_by' => 'Dr. Example',
            ])->assertOk()->assertJson(['success' => true]);

        $this->withSession($doctorSession)
            ->getJson('/Back%20End/EHR_System.php?action=get_meds&patient_id=P-00004')
            ->assertOk()->assertJsonPath('data.0.med_name', 'Amoxicillin');

        $nurseSession = ['user_id' => 2, 'username' => 'nurse1', 'role' => 'nurse'];
        $this->withSession($nurseSession)
            ->postJson('/Back%20End/EHR_System.php?action=add_lab', [
                'patient_id' => 'P-00004',
                'test_name' => 'Hemoglobin',
                'test_value' => '13.4',
                'ref_range' => '12-16 g/dL',
                'label' => 'Normal',
            ])->assertOk()->assertJson(['success' => true]);

        $labId = DB::connection('ehr')->table('lab_results')->value('id');
        $this->withSession($nurseSession)
            ->postJson('/Back%20End/EHR_System.php?action=update_lab', [
                'id' => $labId,
                'test_name' => 'Hemoglobin',
            ])->assertForbidden();

        $this->withSession($doctorSession)
            ->getJson('/Back%20End/EHR_System.php?action=get_labs&patient_id=P-00004')
            ->assertOk()->assertJsonPath('data.0.test_value', '13.4');
    }

    public function test_admin_user_management_is_scoped_to_the_admins_hospital(): void
    {
        DB::connection('ehr')->table('users')->insert([
            'id' => 2,
            'username' => 'admin1',
            'email' => 'admin@example.com',
            'password_hash' => password_hash('secret123', PASSWORD_BCRYPT),
            'role' => 'admin',
            'full_name' => 'Hospital Admin',
            'hospital' => 'Example Hospital',
            'is_active' => true,
        ]);
        DB::connection('ehr')->table('users')->insert([
            'id' => 3,
            'username' => 'nurse2',
            'email' => 'nurse2@example.com',
            'password_hash' => password_hash('secret123', PASSWORD_BCRYPT),
            'role' => 'nurse',
            'full_name' => 'Nurse Two',
            'hospital' => 'Example Hospital',
            'is_active' => true,
        ]);
        DB::connection('ehr')->table('users')->insert([
            'id' => 4,
            'username' => 'doctor_other',
            'email' => 'doctor-other@example.com',
            'password_hash' => password_hash('secret123', PASSWORD_BCRYPT),
            'role' => 'doctor',
            'full_name' => 'Other Doctor',
            'hospital' => 'Other Hospital',
            'is_active' => true,
        ]);

        $admin = ['user_id' => 2, 'role' => 'admin', 'hospital' => 'Example Hospital'];
        $this->withSession($admin)
            ->getJson('/Back%20End/EHR_System.php?action=get_users')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['username' => 'doctor1'])
            ->assertJsonFragment(['username' => 'nurse2'])
            ->assertJsonMissing(['username' => 'doctor_other']);

        $this->withSession($admin)
            ->postJson('/Back%20End/EHR_System.php?action=create_user', [
                'username' => 'new_admin',
                'email' => 'new-admin@example.com',
                'password' => 'long-password',
                'role' => 'admin',
            ])->assertOk()->assertJson(['success' => false]);

        $this->withSession($admin)
            ->postJson('/Back%20End/EHR_System.php?action=deactivate_user', ['id' => 4])
            ->assertOk()->assertJson(['success' => false, 'message' => 'User not found in your hospital.']);

        $this->assertSame(1, DB::connection('ehr')->table('users')->where('id', 4)->value('is_active'));
    }

    public function test_admin_can_create_and_update_clinical_staff_but_cannot_deactivate_self(): void
    {
        DB::connection('ehr')->table('users')->insert([
            'id' => 2,
            'username' => 'admin1',
            'email' => 'admin@example.com',
            'password_hash' => password_hash('secret123', PASSWORD_BCRYPT),
            'role' => 'admin',
            'full_name' => 'Hospital Admin',
            'hospital' => 'Example Hospital',
            'is_active' => true,
        ]);
        $admin = ['user_id' => 2, 'role' => 'admin', 'hospital' => 'Example Hospital'];

        $this->withSession($admin)
            ->postJson('/Back%20End/EHR_System.php?action=create_user', [
                'username' => 'nurse_new',
                'email' => 'nurse-new@example.com',
                'password' => 'long-password',
                'role' => 'nurse',
                'full_name' => 'New Nurse',
            ])->assertOk()->assertJson(['success' => true]);

        $newUser = DB::connection('ehr')->table('users')->where('username', 'nurse_new')->first();
        $this->assertSame('Example Hospital', $newUser->hospital);
        $this->assertTrue(password_verify('long-password', $newUser->password_hash));

        $this->withSession($admin)
            ->postJson('/Back%20End/EHR_System.php?action=update_user', [
                'id' => $newUser->id,
                'full_name' => 'Updated Nurse',
                'role' => 'doctor',
            ])->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', [
            'id' => $newUser->id,
            'full_name' => 'Updated Nurse',
            'role' => 'doctor',
        ], 'ehr');

        $this->withSession($admin)
            ->postJson('/Back%20End/EHR_System.php?action=deactivate_user', ['id' => 2])
            ->assertOk()->assertJson(['success' => false, 'message' => 'You cannot deactivate your own account.']);
    }

    public function test_profile_changes_require_same_hospital_admin_approval(): void
    {
        DB::connection('ehr')->table('users')->insert([
            'id' => 2,
            'username' => 'admin1',
            'email' => 'admin@example.com',
            'password_hash' => password_hash('secret123', PASSWORD_BCRYPT),
            'role' => 'admin',
            'full_name' => 'Hospital Admin',
            'hospital' => 'Example Hospital',
            'is_active' => true,
        ]);

        $this->withSession(['user_id' => 1, 'role' => 'doctor', 'hospital' => 'Example Hospital'])
            ->postJson('/Back%20End/EHR_System.php?action=submit_profile_request', [
                'field' => 'full_name',
                'new_value' => 'Dr. Updated Example',
            ])->assertOk()->assertJson(['success' => true]);

        $requestId = DB::connection('ehr')->table('profile_requests')->value('id');
        $this->withSession(['user_id' => 1, 'role' => 'doctor'])
            ->getJson('/Back%20End/EHR_System.php?action=get_my_profile_requests')
            ->assertOk()->assertJsonPath('data.0.status', 'pending');

        $this->withSession(['user_id' => 2, 'role' => 'admin', 'hospital' => 'Example Hospital'])
            ->postJson('/Back%20End/EHR_System.php?action=review_profile_request', [
                'id' => $requestId,
                'decision' => 'approve',
                'note' => 'Verified',
            ])->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', [
            'id' => 1,
            'full_name' => 'Dr. Updated Example',
        ], 'ehr');
        $this->assertDatabaseHas('profile_requests', [
            'id' => $requestId,
            'status' => 'approved',
            'reviewed_by' => 2,
        ], 'ehr');
    }

    public function test_clinical_alerts_are_readable_by_clinical_roles_and_dismissible_by_doctors(): void
    {
        DB::connection('ehr')->table('clinical_rules')->insert([
            'id' => 1,
            'rule_code' => 'BP-HIGH',
            'rule_name' => 'High blood pressure',
            'description' => 'Systolic pressure is above threshold.',
            'requires_acknowledgment' => true,
        ]);
        DB::connection('ehr')->table('patient_alert_states')->insert([
            'id' => 11,
            'patient_id' => 'P-00009',
            'rule_id' => 1,
            'state' => 'active',
            'severity_tier' => 1,
            'matched_criteria_snapshot' => json_encode([[
                'parameter_key' => 'systolic_bp',
                'patient_value' => 190,
                'operator' => 'gt',
                'threshold' => 180,
                'unit' => 'mmHg',
            ]]),
            'triggered_at' => now()->subMinutes(5),
        ]);

        $this->withSession(['user_id' => 1, 'role' => 'nurse'])
            ->getJson('/Back%20End/EHR_System.php?action=get_clinical_alerts&patient_id=P-00009')
            ->assertOk()
            ->assertJsonPath('alert_count', 1)
            ->assertJsonPath('has_critical', true)
            ->assertJsonPath('data.0.trigger_detail', 'Systolic Bp: 190 mmHg (threshold: > 180 mmHg)');

        $this->withSession(['user_id' => 1, 'role' => 'nurse'])
            ->postJson('/Back%20End/EHR_System.php?action=dismiss_alert', ['alert_state_id' => 11])
            ->assertForbidden();

        $this->withSession(['user_id' => 1, 'role' => 'doctor'])
            ->postJson('/Back%20End/EHR_System.php?action=dismiss_alert', ['alert_state_id' => 11])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('patient_alert_states', ['id' => 11, 'state' => 'dismissed'], 'ehr');
    }

    public function test_admin_cdss_reseed_is_idempotent_and_expires_only_stale_alerts(): void
    {
        DB::connection('ehr')->table('clinical_rules')->insert([
            'id' => 1,
            'rule_code' => 'EXISTING-RULE',
            'rule_name' => 'Keep existing rule',
            'description' => 'Existing custom rule',
        ]);
        DB::connection('ehr')->table('patient_alert_states')->insert([
            ['id' => 20, 'patient_id' => 'P-1', 'rule_id' => 1, 'state' => 'active', 'expires_at' => now()->subMinute()],
            ['id' => 21, 'patient_id' => 'P-2', 'rule_id' => 1, 'state' => 'active', 'expires_at' => now()->addDay()],
        ]);

        $admin = ['user_id' => 2, 'role' => 'admin', 'hospital' => 'Example Hospital'];
        $url = '/Back%20End/EHR_System.php?action=reseed_cdss_rules';
        $this->withSession(['user_id' => 1, 'role' => 'doctor'])
            ->getJson($url)->assertForbidden();

        $this->withSession($admin)->getJson($url)->assertOk()->assertJson(['success' => true]);
        $ruleCount = DB::connection('ehr')->table('clinical_rules')->count();
        $criteriaCount = DB::connection('ehr')->table('rule_criteria')->count();

        $this->withSession($admin)->getJson($url)->assertOk()->assertJson(['success' => true]);

        $this->assertSame($ruleCount, DB::connection('ehr')->table('clinical_rules')->count());
        $this->assertSame($criteriaCount, DB::connection('ehr')->table('rule_criteria')->count());
        $this->assertDatabaseHas('patient_alert_states', ['id' => 20, 'state' => 'expired'], 'ehr');
        $this->assertDatabaseHas('patient_alert_states', ['id' => 21, 'state' => 'active'], 'ehr');
        $this->assertGreaterThan(0, $criteriaCount);
    }
}
