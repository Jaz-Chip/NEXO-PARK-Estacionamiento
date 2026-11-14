<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        PaymentMethod::firstOrCreate(['name' => 'Efectivo']);
        PaymentMethod::firstOrCreate(['name' => 'Tarjeta']);
        PaymentMethod::firstOrCreate(['name' => 'Transferencia / QR']);
    }
}
