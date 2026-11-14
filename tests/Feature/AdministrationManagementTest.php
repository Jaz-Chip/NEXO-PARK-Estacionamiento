<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdministrationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'administrador')->value('id'), 'is_active' => true]);
    }

    private function employee(Role $role, string $email = 'empleado@example.test'): array
    {
        return ['name' => 'Empleado de prueba', 'email' => $email, 'password' => 'Empleado-test-123',
            'password_confirmation' => 'Empleado-test-123', 'role_id' => $role->id];
    }

    private function custom(array $permissions = [], string $name = 'Personal autorizado'): Role
    {
        $response = $this->postJson('/admin/roles', ['name' => $name, 'permissions' => $permissions])->assertCreated();

        return Role::findOrFail($response->json('roles.0.id'));
    }

    public function test_custom_role_can_be_created_renamed_and_have_all_permissions_revoked(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/roles')->assertOk()->assertSee('Nuevo rol')->assertSee('Seleccionar todos');
        $before = Permission::count();
        $role = $this->custom(['clientes.view', 'clientes.create']);
        $this->assertSame('web', $role->guard_name);
        $this->assertStringStartsWith('custom-', $role->slug);
        $this->assertTrue($role->hasPermissionTo('clientes.create'));
        $this->putJson('/admin/roles/permisos', ['roles' => [['id' => $role->id, 'name' => 'Recepción', 'permissions' => ['clientes.view']]]])
            ->assertOk()->assertJsonPath('roles.0.name', 'Recepción')->assertJsonCount(1, 'roles.0.permissions');
        $this->putJson('/admin/roles/permisos', ['roles' => [['id' => $role->id, 'permissions' => []]]])->assertOk();
        $this->assertSame(0, $role->permissions()->count());
        $this->assertSame($before, Permission::count());
        $this->assertSame('Recepción', $role->fresh()->name);
    }

    public function test_role_validation_prevents_duplicates_invented_permissions_and_system_role_changes(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $role = $this->custom([], 'Recepcionista');
        foreach (['Recepcionista', ' recepcionista ', 'ADMINISTRADOR', 'Operador / Cajero'] as $name) {
            $this->postJson('/admin/roles', ['name' => $name, 'permissions' => []])->assertUnprocessable();
        }
        $this->postJson('/admin/roles', ['name' => '   ', 'permissions' => []])->assertUnprocessable();
        $this->postJson('/admin/roles', ['name' => ['invalid'], 'permissions' => []])->assertUnprocessable();
        $this->postJson('/admin/roles', ['name' => 'Inventado', 'permissions' => ['inventado.manage']])->assertUnprocessable();
        $this->postJson('/admin/roles', ['name' => 'Suplantado', 'permissions' => [], 'slug' => 'administrador', 'guard_name' => 'api'])->assertUnprocessable();
        $otherGuard = Permission::create(['name' => 'api.only', 'key' => 'api.only', 'guard_name' => 'api', 'module' => 'administracion', 'label' => 'API']);
        $this->postJson('/admin/roles', ['name' => 'API', 'permissions' => [$otherGuard->name]])->assertUnprocessable();
        $this->putJson('/admin/roles/permisos', ['roles' => [['id' => $role->id, 'name' => 'Supervisor', 'permissions' => []]]])->assertUnprocessable();
        foreach (['administrador', 'operador', 'supervisor'] as $slug) {
            $system = Role::where('slug', $slug)->firstOrFail();
            $this->putJson('/admin/roles/permisos', ['roles' => [['id' => $system->id, 'name' => 'Renombrado', 'permissions' => []]]])->assertUnprocessable();
            $this->assertNotSame('Renombrado', $system->fresh()->name);
        }
        $this->deleteJson('/admin/roles/'.$admin->role_id)->assertNotFound();
    }

    public function test_employee_login_allowed_actions_revocation_and_persistence(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $role = $this->custom(['clientes.view', 'clientes.create']);
        $input = $this->employee($role);
        $response = $this->postJson('/admin/usuarios', $input)->assertCreated()->assertJsonMissingPath('password');
        $user = User::findOrFail($response->json('id'));
        $this->assertTrue(Hash::check($input['password'], $user->password));
        $this->assertTrue($user->hasRole($role, 'web'));
        $this->getJson('/admin/usuarios/datos')->assertOk()->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.0.remember_token');
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => $input['password']])->assertRedirect('/clientes');
        $this->postJson('/clientes', ['name' => 'Cliente sin cuenta', 'document' => 'EMP-1'])->assertCreated();
        $this->get('/admin/usuarios')->assertForbidden();
        $this->get('/dashboard')->assertForbidden();
        $this->actingAs($admin)->putJson('/admin/roles/permisos', ['roles' => [['id' => $role->id, 'permissions' => ['clientes.view']]]])->assertOk();
        $this->actingAs($user)->postJson('/clientes', ['name' => 'Denegado', 'document' => 'EMP-2'])->assertForbidden();
        $this->get('/clientes')->assertOk()->assertDontSee('id="catalog-new"', false);
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => $input['password']])->assertRedirect('/clientes');
        $this->postJson('/clientes', ['name' => 'Denegado', 'document' => 'EMP-3'])->assertForbidden();
    }

    public function test_employee_role_change_and_activation_preserve_passwords(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $first = $this->custom(['clientes.view']);
        $second = $this->custom(['vehiculos.view'], 'Control vehicular');
        $input = $this->employee($first);
        $id = $this->postJson('/admin/usuarios', $input)->assertCreated()->json('id');
        $user = User::findOrFail($id);
        $hash = $user->password;
        $this->putJson('/admin/usuarios/'.$id, ['name' => $user->name, 'email' => $user->email, 'role_id' => $second->id])->assertOk();
        $user = $user->fresh();
        $this->assertTrue($user->hasRole($second));
        $this->assertFalse($user->hasRole($first));
        $this->assertSame($hash, $user->password);
        $this->actingAs($user)->get('/clientes')->assertForbidden();
        $this->get('/vehiculos')->assertOk();
        $this->actingAs($admin)->patchJson('/admin/usuarios/'.$id.'/estado', ['is_active' => false])->assertOk();
        $this->actingAs($user->fresh())->getJson('/vehiculos/datos')->assertUnauthorized();
        $this->post('/login', ['email' => $user->email, 'password' => $input['password']])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($admin)->patchJson('/admin/usuarios/'.$id.'/estado', ['is_active' => true])->assertOk();
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => $input['password']])->assertRedirect('/vehiculos');
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_delegated_manager_cannot_grant_or_take_over_superior_roles_and_accounts(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $managerRole = $this->custom(['roles.view', 'roles.manage', 'usuarios.view', 'usuarios.status', 'clientes.view'], 'Delegado');
        $limited = $this->custom(['clientes.view'], 'Lectura');
        $powerful = $this->custom(['clientes.view', 'clientes.create'], 'Recepción completa');
        $manager = User::factory()->create(['role_id' => $managerRole->id, 'is_active' => true]);
        $target = User::factory()->create(['role_id' => $powerful->id, 'is_active' => true]);
        $this->actingAs($manager)->postJson('/admin/roles', ['name' => 'Escalada', 'permissions' => ['clientes.create']])->assertForbidden();
        $this->postJson('/admin/roles', ['name' => 'Lectura delegada', 'permissions' => ['clientes.view']])->assertCreated();
        $this->putJson('/admin/roles/permisos', ['roles' => [['id' => $limited->id, 'permissions' => ['clientes.create']]]])->assertForbidden();
        $this->putJson('/admin/roles/permisos', ['roles' => [['id' => $powerful->id, 'permissions' => []]]])->assertForbidden();
        $this->getJson('/admin/roles/'.$powerful->id.'/permisos')->assertOk()->assertJsonPath('canConfigure', false);
        $this->postJson('/admin/usuarios', $this->employee($powerful))->assertForbidden();
        $this->postJson('/admin/usuarios', $this->employee(Role::find($admin->role_id)))->assertForbidden();
        $this->postJson('/admin/usuarios', $this->employee($limited))->assertCreated();
        $this->putJson('/admin/usuarios/'.$target->id, ['name' => $target->name, 'email' => $target->email, 'role_id' => $limited->id])->assertForbidden();
        $this->patchJson('/admin/usuarios/'.$target->id.'/estado', ['is_active' => false])->assertForbidden();
        $this->assertTrue($target->fresh()->is_active);
        $this->assertTrue($target->fresh()->hasRole($powerful));
        $this->assertTrue($powerful->fresh()->hasPermissionTo('clientes.create'));
    }

    public function test_accounts_and_registration_remain_protected_and_input_is_validated(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $role = $this->custom();
        $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $before = $admin->password;
        $input = $this->employee($role);
        $this->postJson('/admin/usuarios', [...$input, 'password_confirmation' => 'incorrecta'])->assertUnprocessable();
        $this->postJson('/admin/usuarios', [...$input, 'password' => str_repeat('ñ', 40), 'password_confirmation' => str_repeat('ñ', 40)])->assertUnprocessable();
        $this->postJson('/admin/usuarios', [...$input, 'password' => "invalid\0password", 'password_confirmation' => "invalid\0password"])->assertUnprocessable();
        $this->postJson('/admin/usuarios', [...$input, 'email' => ['invalid']])->assertUnprocessable();
        $this->postJson('/admin/usuarios', [...$input, 'email' => strtoupper($user->email)])->assertUnprocessable();
        $this->postJson('/admin/usuarios', [...$input, 'role_id' => 999999])->assertUnprocessable();
        $this->postJson('/admin/usuarios', [...$input, 'permissions' => ['roles.manage']])->assertUnprocessable();
        $this->putJson('/admin/usuarios/'.$user->id, [...$input, 'email' => $user->email])->assertUnprocessable();
        $this->putJson('/admin/usuarios/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'role_id' => $role->id])->assertUnprocessable();
        $this->patchJson('/admin/usuarios/'.$admin->id.'/estado', ['is_active' => false])->assertUnprocessable();
        $secondAdmin = $this->admin();
        $this->actingAs($secondAdmin)->patchJson('/admin/usuarios/'.$admin->id.'/estado', ['is_active' => false])->assertUnprocessable();
        $this->assertSame($before, $admin->fresh()->password);
        $this->assertTrue($admin->fresh()->isAdministrator());
        $this->actingAs($user)->get('/admin/roles')->assertForbidden();
        $this->postJson('/admin/roles', ['name' => 'Sin autorización', 'permissions' => []])->assertForbidden();
        $this->postJson('/admin/usuarios', $input)->assertForbidden();
        $this->getJson('/admin/usuarios/datos')->assertForbidden();
        $this->post('/logout');
        $this->get('/register')->assertNotFound();
        $this->post('/register', $input)->assertNotFound();
        $this->postJson('/admin/usuarios', $input)->assertUnauthorized();
    }

    public function test_operator_can_register_customer_vehicles_without_creating_customer_accounts(): void
    {
        $operator = User::factory()->create(['role_id' => Role::where('slug', 'operador')->value('id'), 'is_active' => true]);
        $type = VehicleType::create(['name' => 'Automóvil', 'is_active' => true]);
        $count = User::count();
        $this->actingAs($operator)->postJson('/clientes', ['name' => 'Conductor sin cuenta', 'document' => 'DRIVER-1'])->assertCreated();
        $client = Client::where('document', 'DRIVER-1')->firstOrFail();
        $this->postJson('/vehiculos', ['plate' => 'INTERNO-1', 'vehicle_type_id' => $type->id, 'client_id' => $client->id])->assertCreated();
        $this->postJson('/vehiculos', ['plate' => 'INTERNO-2', 'vehicle_type_id' => $type->id])->assertCreated();
        $this->assertSame($count, User::count());
        $this->assertSame($client->id, Vehicle::where('plate', 'INTERNO-1')->value('client_id'));
    }
}
