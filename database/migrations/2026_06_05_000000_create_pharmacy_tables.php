<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. إنشاء جدول الصيادلة
        Schema::create('pharmacists', function (Blueprint $table) {
            $table->id(); 
            $table->string('pharmacist_id', 50)->unique();
            $table->string('name', 100);
            $table->string('email', 100)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->string('password_hash', 255)->nullable();
            $table->timestamps(); 
        });

        // إدخال البيانات التجريبية (Demo Data) اللي كانت عندك
        DB::table('pharmacists')->insert([
            [
                'pharmacist_id' => 'PHARM001',
                'name' => 'Dr. Ahmed Hassan',
                'email' => 'ahmed@pharmacy.com',
                'status' => 'active',
                'password_hash' => password_hash('password123', PASSWORD_BCRYPT),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'pharmacist_id' => 'PHARM002',
                'name' => 'Dr. Sarah Johnson',
                'email' => 'sarah@pharmacy.com',
                'status' => 'active',
                'password_hash' => password_hash('secure456', PASSWORD_BCRYPT),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        // 2. إنشاء جدول الأدوية
        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->string('medicine_code', 50)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('strength', 50)->nullable();
            $table->integer('quantity_in_stock')->default(0);
            $table->decimal('unit_price', 10, 2);
            $table->integer('reorder_level')->default(10);
            $table->timestamps();
        });

        // 3. إنشاء جدول الروشيتات والوصفات الطبية
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->string('prescription_code', 50)->unique();
            $table->string('patient_id', 50)->nullable();
            $table->string('patient_name', 100)->nullable();
            $table->string('pharmacist_id', 50)->nullable();
            
            // ربط جدول الروشيتات بجدول الأدوية عبر الـ Foreign Key
            $table->foreignId('medicine_id')->constrained('medicines')->onDelete('cascade');
            
            $table->integer('quantity');
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'approved', 'dispensed', 'rejected'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('medicines');
        Schema::dropIfExists('pharmacists');
    }
};