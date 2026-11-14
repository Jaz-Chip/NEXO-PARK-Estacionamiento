<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Rate;
use App\Models\Role;
use App\Models\Space;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseOneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
    }

    private function user(string $role = 'operador', bool $active = true): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role)->firstOrFail()->id, 'is_active' => $active, 'password' => 'Testing-password-123']);
    }

    public function test_guests_are_redirected_and_login_is_visible(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Iniciar sesión');
    }

    public function test_login_and_logout(): void
    {
        $user = $this->user();
        $this->post('/login', ['email' => $user->email, 'password' => 'Testing-password-123'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_wrong_password_and_inactive_accounts_cannot_login(): void
    {
        $user = $this->user();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $user->update(['is_active' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'Testing-password-123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $user = $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'Testing-password-123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_all_roles_can_view_dashboard_but_only_admin_manages_users(): void
    {
        foreach (['administrador', 'operador', 'supervisor'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->get('/dashboard')->assertOk();
            $response = $this->get('/admin/usuarios');
            $role === 'administrador' ? $response->assertOk() : $response->assertForbidden();
            if ($role !== 'administrador') {
                $this->patchJson('/admin/usuarios/'.$user->id.'/estado', ['is_active' => false])->assertForbidden();
            }
        }
    }

    public function test_admin_can_deactivate_and_reactivate_without_deletion(): void
    {
        $admin = $this->user('administrador');
        $user = $this->user();
        $url = '/admin/usuarios/'.$user->id.'/estado';
        $this->actingAs($admin)->patchJson($url, ['is_active' => false])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => false]);
        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $this->actingAs($admin)->patchJson($url, ['is_active' => true])->assertOk();
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_self_deactivation_and_invalid_status_are_rejected(): void
    {
        $admin = $this->user('administrador');
        $this->actingAs($admin)->patchJson('/admin/usuarios/'.$admin->id.'/estado', ['is_active' => false])->assertUnprocessable();
        $this->patchJson('/admin/usuarios/'.$admin->id.'/estado', ['is_active' => 'invalid'])->assertUnprocessable();
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_account_without_role_cannot_enter(): void
    {
        $user = User::factory()->create(['role_id' => null, 'is_active' => true]);
        $this->actingAs($user)->get('/dashboard')->assertForbidden();
    }

    public function test_dashboard_uses_database_and_relationships_work(): void
    {
        $user = $this->user();
        $client = Client::create(['name' => 'Cliente prueba', 'document' => 'TEST-1']);
        $type = VehicleType::create(['name' => 'Prueba']);
        $vehicle = Vehicle::create(['plate' => 'TEST-123', 'vehicle_type_id' => $type->id, 'client_id' => $client->id]);
        $space = Space::create(['code' => 'A1', 'vehicle_type_id' => $type->id, 'status' => 'occupied']);
        Space::create(['code' => 'A2', 'status' => 'available']);
        Space::create(['code' => 'A3', 'status' => 'maintenance']);
        $rate = Rate::create(['vehicle_type_id' => $type->id, 'name' => 'Hora', 'unit' => 'hour', 'amount' => 10]);
        $subscription = Subscription::create(['client_id' => $client->id, 'vehicle_id' => $vehicle->id, 'space_id' => $space->id, 'rate_id' => $rate->id, 'starts_on' => today(), 'ends_on' => today()->addMonth(), 'amount' => 100]);
        $ticket = Ticket::create(['number' => 'T1', 'vehicle_id' => $vehicle->id, 'space_id' => $space->id, 'rate_id' => $rate->id, 'subscription_id' => $subscription->id, 'opened_by' => $user->id, 'entered_at' => now(), 'rate_amount' => 10]);
        $method = PaymentMethod::create(['name' => 'Prueba']);
        foreach ([[25, 'paid', now()], [15, 'paid', now()->subDay()], [99, 'void', now()]] as [$amount,$status,$date]) {
            Payment::create(['ticket_id' => $ticket->id, 'payment_method_id' => $method->id, 'user_id' => $user->id, 'amount' => $amount, 'status' => $status, 'paid_at' => $date]);
        }
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertViewHas('vehicles', 1)->assertViewHas('available', 1)->assertViewHas('occupied', 1)->assertViewHas('income', fn ($n) => (float) $n === 40.0)->assertViewHas('todayIncome', fn ($n) => (float) $n === 25.0);
        $this->assertTrue($ticket->vehicle->client->is($client));
        $this->assertTrue($subscription->rate->vehicleType->is($type));
        $this->assertCount(3, $ticket->payments);
        $this->assertTrue($ticket->openedBy->is($user));
        $this->assertCount(1, $space->subscriptions);
    }

    public function test_seeders_are_idempotent_and_passwords_are_hashed(): void
    {
        foreach (['administrador', 'operador', 'supervisor'] as $slug) {
            config(["demo.users.$slug.password" => 'Demo-testing-12345']);
        }
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('roles', 3);
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('vehicle_types', 3);
        $this->assertDatabaseCount('payment_methods', 3);
        $user = User::where('email', config('demo.users.administrador.email'))->firstOrFail();
        $this->assertNotSame('Demo-testing-12345', $user->password);
        $this->assertTrue(Hash::check('Demo-testing-12345', $user->password));
        $user->update(['is_active' => false]);
        $this->seed(DatabaseSeeder::class);
        $this->assertFalse($user->fresh()->is_active);
    }
}
