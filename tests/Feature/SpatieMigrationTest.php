<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SpatieMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A separate, disposable connection; never touches the configured MySQL.
        config(['database.default' => 'legacy_permissions', 'database.connections.legacy_permissions' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->foreignId('role_id')->nullable()->constrained();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        (require database_path('migrations/2026_11_11_000003_create_role_permissions.php'))->up();
        foreach (['administrador' => 'Administrador', 'operador' => 'Operador', 'supervisor' => 'Supervisor'] as $slug => $name) {
            DB::table('roles')->insert(['name' => $name, 'slug' => $slug]);
        }
        DB::table('users')->insert([
            'name' => 'Usuario existente', 'email' => 'existing@example.test',
            'password' => 'existing-hash-must-not-change', 'role_id' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('legacy_permissions');
        parent::tearDown();
    }

    public function test_conversion_preserves_ids_labels_grants_empty_roles_and_credentials(): void
    {
        DB::table('roles')->update(['permissions_initialized_at' => '2026-11-11 00:00:00']);
        DB::table('permissions')->insert([
            ['id' => 55, 'key' => 'clientes.view', 'name' => 'Consultar clientes existentes', 'module' => 'gestion'],
            ['id' => 56, 'key' => 'legacy.custom', 'name' => 'Permiso personalizado', 'module' => 'otros'],
        ]);
        DB::table('role_permission')->insert([
            ['role_id' => 2, 'permission_id' => 55], ['role_id' => 2, 'permission_id' => 56],
        ]);
        $usersBefore = DB::table('users')->get()->map(fn ($row) => (array) $row)->all();
        $rolesBefore = DB::table('roles')->select('id', 'name', 'slug')->get()->toArray();

        (require database_path('migrations/2026_11_11_000004_adapt_permissions_to_spatie.php'))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame($usersBefore, DB::table('users')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertEquals($rolesBefore, DB::table('roles')->select('id', 'name', 'slug')->get()->toArray());
        $this->assertDatabaseHas('permissions', ['id' => 55, 'name' => 'clientes.view', 'label' => 'Consultar clientes existentes', 'guard_name' => 'web']);
        $this->assertDatabaseHas('permissions', ['id' => 56, 'name' => 'legacy.custom', 'label' => 'Permiso personalizado']);
        $this->assertEqualsCanonicalizing([55, 56], DB::table('role_permission')->where('role_id', 2)->pluck('permission_id')->all());
        $this->assertSame(0, DB::table('role_permission')->where('role_id', 3)->count());
        $user = User::firstOrFail();
        $this->assertTrue($user->hasRole('Operador', 'web'));
        $this->assertTrue($user->can('clientes.view'));
        $this->assertTrue($user->can('legacy.custom'));
        $this->assertFalse($user->can('clientes.create'));
        $this->assertFalse(Schema::hasTable('role_has_permissions'));
    }

    public function test_conversion_from_phase_two_initializes_existing_access_once(): void
    {
        (require database_path('migrations/2026_11_11_000004_adapt_permissions_to_spatie.php'))->up();
        foreach (['administrador', 'operador', 'supervisor'] as $slug) {
            $role = Role::where('slug', $slug)->firstOrFail();
            $this->assertEqualsCanonicalizing(PermissionCatalog::defaults($slug), $role->permissions()->pluck('name')->all());
        }
        $this->assertDatabaseHas('model_has_roles', ['role_id' => 2, 'model_id' => 1, 'model_type' => User::class]);
        $this->assertDatabaseHas('users', ['id' => 1, 'password' => 'existing-hash-must-not-change', 'role_id' => 2]);
    }
}
