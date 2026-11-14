<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        DB::transaction(function () {
            foreach (PermissionCatalog::definitions() as $key => $attributes) {
                Permission::firstOrCreate(['name' => $key, 'guard_name' => 'web'], [
                    'key' => $key, 'label' => $attributes['name'],
                    'module' => $attributes['module'], 'is_pending' => $attributes['is_pending'],
                ]);
            }

            foreach (Role::orderBy('id')->lockForUpdate()->get() as $role) {
                // Never restore revoked permissions when this seeder is rerun.
                if ($role->permissions_initialized_at && $role->slug !== 'administrador') {
                    continue;
                }
                $permissions = Permission::where('guard_name', 'web')
                    ->when($role->slug !== 'administrador', fn ($query) => $query->whereIn('name', PermissionCatalog::defaults($role->slug)))
                    ->get();
                $role->givePermissionTo($permissions);
                if (! $role->permissions_initialized_at) {
                    $role->forceFill(['permissions_initialized_at' => now()])->save();
                }
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
