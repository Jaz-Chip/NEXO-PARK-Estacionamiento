<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
    }

    private function user(string $slug = 'administrador'): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'is_active' => true,
            'password' => 'Permission-test-123',
        ]);
    }

    private function selection(User $user, array $keys): array
    {
        return ['id' => $user->role_id, 'permissions' => $keys];
    }

    private function save(User $admin, User $target, array $keys)
    {
        return $this->actingAs($admin)->putJson('/admin/roles/permisos', ['roles' => [$this->selection($target, $keys)]]);
    }

    public function test_admin_sees_five_sections_and_can_configure_both_roles(): void
    {
        $admin = $this->user();
        $operator = $this->user('operador');
        $supervisor = $this->user('supervisor');
        $this->actingAs($admin)->get('/admin/roles')->assertOk()
            ->assertSee('id="permissions-modal"', false)->assertSee('permissions-save')
            ->assertSee('permanente')->assertSee('Próximo')
            ->assertSee('id="nav-gestion"', false)->assertSee('id="nav-operaciones"', false)
            ->assertSee('id="nav-reportes"', false)->assertSee('id="nav-administracion"', false);
        $this->putJson('/admin/roles/permisos', ['roles' => [
            $this->selection($operator, ['dashboard.view', 'pisos.view', 'pisos.create']),
            $this->selection($supervisor, ['clientes.view', 'clientes.create']),
        ]])->assertOk();

        // These actions used to be forbidden by fixed role names.
        $this->actingAs($operator)->postJson('/pisos', ['name' => 'Piso autorizado'])->assertCreated();
        $this->putJson('/pisos/1', ['name' => 'No autorizado'])->assertForbidden();
        $this->actingAs($supervisor)->postJson('/clientes', ['name' => 'Cliente autorizado', 'document' => 'PERM-1'])->assertCreated();
        $this->get('/dashboard')->assertForbidden();
    }

    public function test_unchecking_revokes_access_immediately_even_with_loaded_relations(): void
    {
        $admin = $this->user();
        $operator = $this->user('operador');
        $operator->load('roles.permissions');
        $this->actingAs($operator)->postJson('/clientes', ['name' => 'Antes', 'document' => 'BEFORE'])->assertCreated();
        $this->save($admin, $operator, ['dashboard.view', 'clientes.view'])->assertOk();
        $this->actingAs($operator)->postJson('/clientes', ['name' => 'Después', 'document' => 'AFTER'])->assertForbidden();
        $this->get('/clientes')->assertOk()->assertDontSee('id="catalog-new"', false)
            ->assertDontSee('id="catalog-modal"', false);
        $this->assertFalse($operator->can('clientes.create'));

        $this->save($admin, $operator, [])->assertOk();
        $this->assertSame(0, $operator->roles()->firstOrFail()->permissions()->count());
        $this->actingAs($operator)->get('/clientes')->assertForbidden();
        $this->get('/dashboard')->assertForbidden();
        $this->get('/sin-acceso')->assertOk()->assertSee('Sin módulos habilitados');
    }

    public function test_every_catalog_route_denies_unassigned_permissions(): void
    {
        $operator = $this->user('operador');
        $this->save($this->user(), $operator, [])->assertOk();
        $this->actingAs($operator);
        foreach (['clientes', 'abonados', 'vehiculos', 'tipos-vehiculo', 'pisos', 'sectores', 'espacios', 'tarifas'] as $slug) {
            $this->get('/'.$slug)->assertForbidden();
            $this->getJson('/'.$slug.'/datos')->assertForbidden();
            $this->postJson('/'.$slug, [])->assertForbidden();
            $this->putJson('/'.$slug.'/1', [])->assertForbidden();
            $this->patchJson('/'.$slug.'/1/estado', ['is_active' => false])->assertForbidden();
        }
        $this->get('/admin/usuarios')->assertForbidden();
        $this->patchJson('/admin/usuarios/'.$operator->id.'/estado', ['is_active' => false])->assertForbidden();
        $this->assertFalse($operator->can('undefined.permission'));
    }

    public function test_edit_and_status_permissions_are_independent_from_create(): void
    {
        $admin = $this->user();
        $supervisor = $this->user('supervisor');
        $client = Client::create(['name' => 'Inicial', 'document' => 'INDEPENDENT']);
        $this->save($admin, $supervisor, ['clientes.view', 'clientes.status'])->assertOk();
        $page = $this->actingAs($supervisor)->get('/clientes')->assertOk();
        $page->assertViewHas('canCreate', false)->assertViewHas('canUpdate', false)->assertViewHas('canChangeStatus', true)
            ->assertDontSee('id="catalog-modal"', false);
        $this->patchJson('/clientes/'.$client->id.'/estado', ['is_active' => false])->assertOk();
        $this->putJson('/clientes/'.$client->id, ['name' => 'Prohibido', 'document' => 'INDEPENDENT'])->assertForbidden();
        $this->postJson('/clientes', [])->assertForbidden();

        $this->save($admin, $supervisor, ['clientes.view', 'clientes.update'])->assertOk();
        $this->actingAs($supervisor)->get('/clientes')->assertOk()->assertViewHas('canCreate', false)
            ->assertViewHas('canUpdate', true)->assertViewHas('canChangeStatus', false)->assertSee('id="catalog-modal"', false);
        $this->putJson('/clientes/'.$client->id, ['name' => 'Editado', 'document' => 'INDEPENDENT'])->assertOk();
        $this->patchJson('/clientes/'.$client->id.'/estado', ['is_active' => true])->assertForbidden();
    }

    public function test_unauthorized_users_cannot_read_or_modify_the_matrix(): void
    {
        $this->get('/admin/roles')->assertRedirect('/login');
        $this->putJson('/admin/roles/permisos', [])->assertUnauthorized();
        foreach (['operador', 'supervisor'] as $slug) {
            $user = $this->user($slug);
            $before = $user->roles()->firstOrFail()->permissions()->pluck('key')->all();
            $this->actingAs($user)->get('/admin/roles')->assertForbidden();
            $this->putJson('/admin/roles/permisos', ['roles' => [$this->selection($user, ['roles.manage'])]])->assertForbidden();
            $this->assertSame($before, $user->roles()->firstOrFail()->permissions()->pluck('key')->all());
        }
    }

    public function test_matrix_read_access_does_not_allow_configuration(): void
    {
        $supervisor = $this->user('supervisor');
        $this->save($this->user(), $supervisor, ['roles.view'])->assertOk();
        $this->actingAs($supervisor)->get('/admin/roles')->assertOk()
            ->assertSee('solo consulta')->assertDontSee('id="permissions-save"', false);
        $this->putJson('/admin/roles/permisos', ['roles' => [$this->selection($supervisor, [])]])->assertForbidden();
    }

    public function test_admin_cannot_lose_permissions_and_updates_are_atomic(): void
    {
        $admin = $this->user();
        $operator = $this->user('operador');
        $before = $operator->roles()->firstOrFail()->permissions()->pluck('key')->all();
        $this->actingAs($admin)->putJson('/admin/roles/permisos', ['roles' => [
            $this->selection($operator, []), $this->selection($admin, []),
        ]])->assertUnprocessable()->assertJsonValidationErrors('roles');
        $this->assertSame($before, $operator->roles()->firstOrFail()->permissions()->pluck('key')->all());
        $admin->roles()->firstOrFail()->permissions()->detach();
        foreach (array_keys(PermissionCatalog::definitions()) as $key) {
            $this->assertTrue($admin->can($key), $key);
        }
        $this->assertFalse($admin->can('undefined.permission'));
        $this->get('/admin/roles')->assertOk();
        $this->save($admin, $operator, ['dashboard.view'])->assertOk();
    }

    public function test_invalid_payloads_do_not_change_grants(): void
    {
        $operator = $this->user('operador');
        $this->actingAs($this->user());
        $before = $operator->roles()->firstOrFail()->permissions()->pluck('key')->all();
        $this->putJson('/admin/roles/permisos', ['roles' => [['id' => $operator->role_id]]])->assertUnprocessable();
        $this->putJson('/admin/roles/permisos', ['roles' => [$this->selection($operator, ['undefined.permission'])]])->assertUnprocessable();
        $this->putJson('/admin/roles/permisos', ['roles' => [
            $this->selection($operator, []), $this->selection($operator, ['dashboard.view']),
        ]])->assertUnprocessable();
        $this->putJson('/admin/roles/permisos', ['roles' => [['id' => 999999, 'permissions' => []]]])->assertUnprocessable();
        $this->assertSame($before, $operator->roles()->firstOrFail()->permissions()->pluck('key')->all());
    }

    public function test_grants_persist_after_logout_and_login_with_a_valid_landing_page(): void
    {
        $supervisor = $this->user('supervisor');
        $this->save($this->user(), $supervisor, ['clientes.view', 'clientes.create'])->assertOk();
        $this->post('/logout')->assertRedirect('/login');
        for ($i = 0; $i < 2; $i++) {
            $this->post('/login', ['email' => $supervisor->email, 'password' => 'Permission-test-123'])->assertRedirect('/clientes');
            $this->postJson('/clientes', ['name' => 'Persistente', 'document' => 'SESSION-'.$i])->assertCreated();
            $this->get('/dashboard')->assertForbidden();
            $this->post('/logout')->assertRedirect('/login');
        }
    }

    public function test_menu_matches_grants_and_new_roles_are_denied_by_default(): void
    {
        $operator = $this->user('operador');
        $this->save($this->user(), $operator, ['dashboard.view', 'vehiculos.view', 'entradas.view', 'entradas.create'])->assertOk();
        $this->actingAs($operator)->get('/dashboard')->assertOk()
            ->assertSee('href="'.route('catalog.vehiculos.index').'"', false)
            ->assertDontSee('href="'.route('catalog.clientes.index').'"', false)
            ->assertDontSee('href="'.route('admin.roles.index').'"', false)
            ->assertSee('id="nav-operaciones"', false)->assertDontSee('id="nav-reportes"', false)
            ->assertDontSee('id="nav-administracion"', false)
            ->assertSee('href="'.route('operations.entradas.index').'"', false);

        $role = Role::create(['name' => 'Nuevo rol', 'slug' => 'nuevo']);
        $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $this->actingAs($user)->get('/dashboard')->assertForbidden();
        $this->assertFalse($user->can('clientes.view'));
    }

    public function test_permission_seeder_is_idempotent_and_never_restores_revoked_grants(): void
    {
        $admin = $this->user();
        $operator = $this->user('operador');
        $supervisor = $this->user('supervisor');
        $this->save($admin, $operator, [])->assertOk();
        $this->save($admin, $supervisor, ['pisos.create', 'exportaciones.pdf'])->assertOk();
        $passwords = User::pluck('password', 'id')->all();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->assertDatabaseCount('permissions', count(PermissionCatalog::definitions()));
        $this->assertSame(0, $operator->roles()->firstOrFail()->permissions()->count());
        $this->assertEqualsCanonicalizing(['pisos.create', 'exportaciones.pdf'], $supervisor->roles()->firstOrFail()->permissions()->pluck('key')->all());
        $this->assertSame($passwords, User::pluck('password', 'id')->all());
        $this->assertTrue($admin->can('roles.manage'));
    }

    public function test_user_management_permissions_keep_account_protections(): void
    {
        $admin = $this->user();
        $operator = $this->user('operador');
        $this->save($admin, $operator, ['usuarios.view'])->assertOk();
        $this->actingAs($operator)->get('/admin/usuarios')->assertOk()->assertDontSee('class="user-status-form"', false);
        $this->patchJson('/admin/usuarios/'.$admin->id.'/estado', ['is_active' => false])->assertForbidden();
        $this->save($admin, $operator, ['usuarios.view', 'usuarios.status'])->assertOk();
        $this->actingAs($operator)->patchJson('/admin/usuarios/'.$operator->id.'/estado', ['is_active' => false])->assertUnprocessable();
        $this->patchJson('/admin/usuarios/'.$admin->id.'/estado', ['is_active' => false])->assertUnprocessable();
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_modal_reads_current_spatie_permissions_and_save_returns_updates_without_redirect(): void
    {
        $admin = $this->user();
        $operator = $this->user('operador');
        $this->actingAs($admin)->getJson('/admin/roles/'.$operator->role_id.'/permisos')->assertOk()
            ->assertJsonPath('role.name', 'Operador / Cajero')->assertJsonPath('role.protected', false)
            ->assertJsonPath('canConfigure', true);
        $this->save($admin, $operator, ['dashboard.view', 'clientes.view'])->assertOk()
            ->assertJsonPath('roles.0.permissions', ['dashboard.view', 'clientes.view'])
            ->assertJsonStructure(['message', 'navigation', 'canConfigure', 'canView'])
            ->assertJsonMissingPath('redirect');
        $this->getJson('/admin/roles/'.$operator->role_id.'/permisos')->assertOk()->assertJsonCount(2, 'role.permissions');
        $this->getJson('/admin/roles/'.$admin->role_id.'/permisos')->assertOk()->assertJsonPath('role.protected', true);
        $this->actingAs($operator)->getJson('/admin/roles/'.$operator->role_id.'/permisos')->assertForbidden();
    }

    public function test_official_spatie_assignments_are_the_authorization_source(): void
    {
        $user = User::factory()->create(['role_id' => null, 'is_active' => true]);
        $operator = Role::where('slug', 'operador')->firstOrFail();
        $user->assignRole($operator);
        $this->assertTrue($user->hasRole('Operador', 'web'));
        $this->actingAs($user)->get('/clientes')->assertOk();
        $user->removeRole($operator);
        $this->get('/clientes')->assertForbidden();
    }
}
