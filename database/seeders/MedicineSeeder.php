<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MedicineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
public function run()
{
    \DB::table('medicines')->insert([
        ['medicine_code' => 'MED001', 'name' => 'Panadol Advance', 'unit_price' => 20.00],
        ['medicine_code' => 'MED002', 'name' => 'Catafast', 'unit_price' => 15.00],
        ['medicine_code' => 'MED003', 'name' => 'Amoxicillin', 'unit_price' => 35.00]
    ]);
}
}
