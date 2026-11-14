<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('guard_name', 100)->default('web');
            $table->unique(['name', 'guard_name']);
        });
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('guard_name', 100)->default('web');
            $table->string('label')->nullable();
        });

        // Spatie uses name for the stable permission key. Keep original IDs,
        // keys and labels, so every existing role_permission row remains valid.
        DB::table('permissions')->orderBy('id')->chunkById(200, function ($permissions) {
            foreach ($permissions as $permission) {
                DB::table('permissions')->where('id', $permission->id)->update([
                    'label' => $permission->name, 'name' => $permission->key,
                ]);
            }
        });
        Schema::table('permissions', fn (Blueprint $table) => $table->unique(['name', 'guard_name']));

        foreach (['roles' => 'role_id', 'permissions' => 'permission_id'] as $tableName => $pivotKey) {
            Schema::create('model_has_'.$tableName, function (Blueprint $table) use ($tableName, $pivotKey) {
                $table->foreignId($pivotKey)->constrained($tableName)->restrictOnDelete();
                $table->string('model_type', 125);
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type']);
                $table->primary([$pivotKey, 'model_id', 'model_type']);
            });
        }

        DB::transaction(function () {
            $roles = Role::all()->keyBy('id');
            User::whereNotNull('role_id')->orderBy('id')->chunkById(200, function ($users) use ($roles) {
                foreach ($users as $user) {
                    if ($role = $roles->get($user->role_id)) {
                        $user->assignRole($role);
                    }
                }
            });
            (new PermissionSeeder)->run();
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        throw new RuntimeException('La conversión de permisos conserva asignaciones. Revierte mediante una migración de datos revisada, sin eliminar las tablas.');
    }
};
