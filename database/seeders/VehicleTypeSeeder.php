<?php

namespace Database\Seeders;

use App\Models\VehicleType;
use Illuminate\Database\Seeder;

class VehicleTypeSeeder extends Seeder
{
    public function run(): void
    {
        VehicleType::firstOrCreate(['name' => 'Automóvil']);
        VehicleType::firstOrCreate(['name' => 'Motocicleta']);
        VehicleType::firstOrCreate(['name' => 'Camioneta']);
    }
}
