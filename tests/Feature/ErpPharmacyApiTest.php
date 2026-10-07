<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ErpPharmacyApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.pharmacy' => array_merge(config('database.connections.sqlite'), [
                'database' => ':memory:',
            ]),
        ]);
        DB::purge('pharmacy');

        Schema::connection('pharmacy')->create('pharmacists', function ($table): void {
            $table->increments('id');
            $table->string('pharmacist_id')->unique();
            $table->string('name');
            $table->string('status');
            $table->string('password_hash')->nullable();
        });
        Schema::connection('pharmacy')->create('employees', function ($table): void {
            $table->increments('id');
            $table->string('employee_id')->unique();
            $table->string('name');
            $table->string('status');
            $table->string('password_hash')->nullable();
        });
        Schema::connection('pharmacy')->create('medicines', function ($table): void {
            $table->increments('id');
            $table->string('medicine_code')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('category')->nullable();
            $table->integer('quantity_in_stock')->default(0);
            $table->decimal('unit_price', 10, 2);
            $table->integer('reorder_level')->default(10);
            $table->date('expiry_date')->nullable();
        });
        Schema::connection('pharmacy')->create('sales', function ($table): void {
            $table->increments('id');
            $table->string('invoice_number')->unique();
            $table->string('user_id');
            $table->string('user_name')->nullable();
            $table->text('items');
            $table->decimal('total_amount', 10, 2);
            $table->string('status');
            $table->timestamp('created_at')->nullable();
        });
        Schema::connection('pharmacy')->create('suppliers', function ($table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('city')->nullable();
            $table->string('status')->default('Active');
            $table->timestamp('created_at')->nullable();
        });
        Schema::connection('pharmacy')->create('purchases', function ($table): void {
            $table->increments('id');
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->timestamp('created_at')->nullable();
        });
        Schema::connection('pharmacy')->create('returns', function ($table): void {
            $table->increments('id');
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->timestamp('created_at')->nullable();
        });

        DB::connection('pharmacy')->table('pharmacists')->insert([
            'pharmacist_id' => 'PHARM001',
            'name' => 'Dr. Ahmed Hassan',
            'status' => 'active',
            'password_hash' => password_hash('password123', PASSWORD_BCRYPT),
        ]);
        DB::connection('pharmacy')->table('medicines')->insert([
            'id' => 1,
            'medicine_code' => 'P001',
            'name_en' => 'Paracetamol',
            'name_ar' => 'باراسيتامول',
            'category' => 'Pain relief',
            'quantity_in_stock' => 5,
            'unit_price' => 3.50,
            'reorder_level' => 2,
        ]);
    }

    public function test_pharmacist_login_and_inventory_routes_keep_the_legacy_contract(): void
    {
        $this->post('/Back%20End/ERP_Pharmacy_System.php', [
            'user_type' => 'pharmacist',
            'user_id' => 'PHARM001',
            'password' => 'password123',
        ])->assertOk()->assertJson([
            'success' => true,
            'user_type' => 'pharmacist',
            'redirect' => 'ERP_Dashboard.html',
        ]);

        $this->getJson('/Back%20End/ERP_Pharmacy_System.php?action=products&lang=ar')
            ->assertOk()->assertJsonPath('products.0.name', 'باراسيتامول');

        $this->getJson('/Back%20End/ERP_Pharmacy_System.php?action=dashboard_stats')
            ->assertOk()->assertJsonPath('total_stock', 5);
    }

    public function test_sale_uses_database_prices_and_rejects_insufficient_stock(): void
    {
        $session = [
            'user_id' => 'PHARM001',
            'user_type' => 'pharmacist',
            'user_name' => 'Dr. Ahmed Hassan',
        ];

        $this->withSession($session)
            ->postJson('/Back%20End/ERP_Pharmacy_System.php?action=submit_sale', [
                'items' => [['id' => 1, 'quantity' => 2, 'unit_price' => 0.01]],
                'total_amount' => 0.02,
                'user_id' => 'tampered-user',
            ])->assertOk()->assertJson([
                'success' => true,
                'total_amount' => 7.0,
            ]);

        $this->assertSame(3, (int) DB::connection('pharmacy')->table('medicines')->value('quantity_in_stock'));
        $this->assertSame(7.0, (float) DB::connection('pharmacy')->table('sales')->value('total_amount'));

        $this->withSession($session)
            ->postJson('/Back%20End/ERP_Pharmacy_System.php?action=submit_sale', [
                'items' => [['id' => 1, 'quantity' => 4]],
            ])->assertBadRequest()->assertJson(['success' => false]);

        $this->assertSame(3, (int) DB::connection('pharmacy')->table('medicines')->value('quantity_in_stock'));
        $this->assertSame(1, DB::connection('pharmacy')->table('sales')->count());
    }

    public function test_pharmacist_can_read_reports_and_manage_suppliers(): void
    {
        $session = ['user_id' => 'PHARM001', 'user_type' => 'pharmacist', 'user_name' => 'Dr. Ahmed Hassan'];
        DB::connection('pharmacy')->table('sales')->insert([
            'invoice_number' => 'INV-TEST-1',
            'user_id' => 'PHARM001',
            'user_name' => 'Dr. Ahmed Hassan',
            'items' => '[]',
            'total_amount' => 42.50,
            'status' => 'completed',
            'created_at' => now(),
        ]);

        $this->withSession($session)
            ->getJson('/Back%20End/ERP_Pharmacy_System.php?action=monthly_summary&start_date=' . today()->toDateString() . '&end_date=' . today()->toDateString())
            ->assertOk()->assertJsonPath('total_sales', 42.5);

        $this->withSession($session)
            ->postJson('/Back%20End/ERP_Pharmacy_System.php?action=add_supplier', [
                'name' => 'Test Medical Supply',
                'city' => 'Cairo',
            ])->assertOk()->assertJson(['success' => true]);

        $this->withSession($session)
            ->getJson('/Back%20End/ERP_Pharmacy_System.php?action=get_suppliers')
            ->assertOk()->assertJsonPath('suppliers.0.name', 'Test Medical Supply');

        $this->withSession(['user_id' => 'EMP001', 'user_type' => 'employee'])
            ->getJson('/Back%20End/ERP_Pharmacy_System.php?action=get_suppliers')
            ->assertForbidden();
    }
}