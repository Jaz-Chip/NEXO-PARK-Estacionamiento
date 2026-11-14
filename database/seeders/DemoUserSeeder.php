<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $users = config('demo.users');
        foreach ($users as $user) {
            if (! is_string($user['password']) || strlen($user['password']) < 12) {
                throw new \RuntimeException('Configura todas las DEMO_*_PASSWORD con al menos 12 caracteres.');
            }
        }
        DB::transaction(function () use ($users) {
            foreach ($users as $slug => $data) {
                User::firstOrCreate(['email' => $data['email']], [
                    'name' => $data['name'], 'password' => $data['password'], 'role_id' => Role::where('slug', $slug)->firstOrFail()->id, 'is_active' => true,
                ]);
            }
        });
    }
}
