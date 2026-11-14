<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(['slug' => 'administrador'], ['name' => 'Administrador', 'guard_name' => 'web']);
        Role::firstOrCreate(['slug' => 'operador'], ['name' => 'Operador', 'guard_name' => 'web']);
        Role::firstOrCreate(['slug' => 'supervisor'], ['name' => 'Supervisor', 'guard_name' => 'web']);
        $this->call(PermissionSeeder::class);
    }
}
