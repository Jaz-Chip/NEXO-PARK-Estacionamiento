<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Floor;
use App\Models\Rate;
use App\Models\Role;
use App\Models\Sector;
use App\Models\Space;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
    }

    private function signIn(string $role): User
    {
        $user = User::factory()->create(['role_id' => Role::where('slug', $role)->firstOrFail()->id, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function basics(): array
    {
        $client = Client::create(['name' => 'Cliente Uno', 'document' => 'DOC-1']);
        $type = VehicleType::create(['name' => 'Auto']);
        $vehicle = Vehicle::create(['plate' => 'ABC123', 'vehicle_type_id' => $type->id, 'client_id' => $client->id]);
        $floor = Floor::create(['name' => 'Piso 1']);
        $sector = Sector::create(['name' => 'Sector A', 'floor_id' => $floor->id]);
        $space = Space::create(['code' => 'A1', 'floor_id' => $floor->id, 'sector_id' => $sector->id, 'status' => 'available']);
        $rate = Rate::create(['name' => 'Mensual', 'vehicle_type_id' => $type->id, 'unit' => 'month', 'amount' => 100]);

        return compact('client', 'type', 'vehicle', 'floor', 'sector', 'space', 'rate');
    }

    public function test_guest_is_redirected_from_all_catalogs(): void
    {
        foreach (['clientes', 'abonados', 'vehiculos', 'tipos-vehiculo', 'pisos', 'sectores', 'espacios', 'tarifas'] as $path) {
            $this->get('/'.$path)->assertRedirect('/login');
            $this->getJson('/'.$path.'/datos')->assertUnauthorized();
            $this->postJson('/'.$path, [])->assertUnauthorized();
        }
    }

    public function test_supervisor_can_read_but_cannot_write_and_operator_scope_is_enforced(): void
    {
        $this->signIn('supervisor');
        foreach (['clientes', 'abonados', 'vehiculos', 'tipos-vehiculo', 'pisos', 'sectores', 'espacios', 'tarifas'] as $path) {
            $this->get('/'.$path)->assertOk();
            $this->getJson('/'.$path.'/datos')->assertOk()->assertJsonStructure(['data']);
            $this->postJson('/'.$path, [])->assertForbidden();
        }
        $this->signIn('operador');
        $page = $this->get('/clientes')->assertOk()->assertSee('catalog-table')->assertSee('catalog-modal');
        preg_match('/<script type="application\/json" id="catalog-config">(.*?)<\/script>/s', $page->getContent(), $match);
        $this->assertSame('clientes', basename(json_decode($match[1], true, 512, JSON_THROW_ON_ERROR)['baseUrl']));
        foreach (['tipos-vehiculo', 'pisos', 'sectores', 'tarifas'] as $path) {
            $this->postJson('/'.$path, [])->assertForbidden();
        }
        foreach (['clientes', 'abonados', 'vehiculos', 'espacios'] as $path) {
            $this->postJson('/'.$path, [])->assertUnprocessable();
        }
    }

    public function test_client_create_edit_duplicate_and_soft_status(): void
    {
        $this->signIn('operador');
        $this->postJson('/clientes', ['name' => 'Ana Pérez', 'document' => 'CI-77', 'email' => 'ana@example.test'])->assertCreated()->assertJsonPath('data.document', 'CI-77');
        $id = Client::where('document', 'CI-77')->firstOrFail()->id;
        $this->postJson('/clientes', ['name' => 'Otra Persona', 'document' => 'CI-77'])->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->putJson('/clientes/'.$id, ['name' => 'Ana Editada', 'document' => 'CI-77'])->assertOk()->assertJsonPath('data.name', 'Ana Editada');
        $this->put('/clientes/'.$id, ['name' => 'Ana Formulario', 'document' => 'CI-77'], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.name', 'Ana Formulario');
        $this->patchJson('/clientes/'.$id.'/estado', ['is_active' => false])->assertOk();
        $this->assertDatabaseHas('clients', ['id' => $id, 'is_active' => false]);
        $this->getJson('/clientes/datos')->assertJsonCount(1, 'data');
    }

    public function test_vehicle_plate_and_type_validation(): void
    {
        $this->signIn('operador');
        $b = $this->basics();
        $this->postJson('/vehiculos', ['plate' => ' abc123 ', 'vehicle_type_id' => $b['type']->id])->assertUnprocessable()->assertJsonValidationErrors('plate');
        $this->postJson('/vehiculos', ['plate' => 'XYZ987', 'vehicle_type_id' => $b['type']->id, 'client_id' => $b['client']->id])->assertCreated();
        $this->patchJson('/vehiculos/'.$b['vehicle']->id.'/estado', ['is_active' => false])->assertOk();
        $this->assertFalse($b['vehicle']->fresh()->is_active);
    }

    public function test_floor_sector_space_location_and_occupied_protection(): void
    {
        $this->signIn('administrador');
        $b = $this->basics();
        $other = Sector::create(['name' => 'Sector B', 'floor_id' => $b['floor']->id]);
        $this->postJson('/espacios', ['code' => 'A1', 'floor_id' => $b['floor']->id, 'sector_id' => $b['sector']->id, 'status' => 'available'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/espacios', ['code' => 'A1', 'floor_id' => $b['floor']->id, 'sector_id' => $other->id, 'status' => 'available'])->assertCreated();
        $secondFloor = Floor::create(['name' => 'Piso 2']);
        $this->postJson('/espacios', ['code' => 'A2', 'floor_id' => $secondFloor->id, 'sector_id' => $b['sector']->id, 'status' => 'available'])->assertUnprocessable()->assertJsonValidationErrors('sector_id');
        $b['space']->update(['status' => 'occupied']);
        $this->patchJson('/espacios/'.$b['space']->id.'/estado', ['is_active' => false])->assertUnprocessable();
        $this->putJson('/espacios/'.$b['space']->id, ['code' => 'A3', 'floor_id' => $b['floor']->id, 'sector_id' => $b['sector']->id, 'status' => 'available'])->assertUnprocessable();
        $this->assertTrue($b['space']->fresh()->is_active);
        $this->patchJson('/pisos/'.$b['floor']->id.'/estado', ['is_active' => false])->assertUnprocessable();
    }

    public function test_rate_billing_units_and_fraction_validation(): void
    {
        $this->signIn('administrador');
        $b = $this->basics();
        $base = ['name' => 'Fracción 15', 'vehicle_type_id' => $b['type']->id, 'unit' => 'fraction', 'amount' => '3.50'];
        $this->postJson('/tarifas', $base)->assertUnprocessable()->assertJsonValidationErrors('fraction_minutes');
        $this->postJson('/tarifas', $base + ['fraction_minutes' => 15])->assertCreated()->assertJsonPath('data.unit', 'fraction');
        $this->postJson('/tarifas', ['name' => 'Por minuto', 'vehicle_type_id' => $b['type']->id, 'unit' => 'minute', 'amount' => '0.10'])->assertCreated();
        $this->postJson('/tarifas', ['name' => 'Por hora', 'vehicle_type_id' => $b['type']->id, 'unit' => 'hour', 'amount' => 5])->assertCreated();
        $this->postJson('/tarifas', ['name' => 'Inválida', 'vehicle_type_id' => $b['type']->id, 'unit' => 'hour', 'amount' => 0])->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_related_catalogs_can_be_reactivated_and_dashboard_excludes_inactive_spaces(): void
    {
        $this->signIn('administrador');
        $b = $this->basics();
        foreach (['sectores' => 'sector', 'vehiculos' => 'vehicle', 'espacios' => 'space', 'tarifas' => 'rate'] as $slug => $key) {
            $item = $b[$key];
            $item->update(['is_active' => false]);
            $this->patchJson('/'.$slug.'/'.$item->id.'/estado', ['is_active' => true])->assertOk();
            $this->assertTrue($item->fresh()->is_active);
        }
        $b['space']->fresh()->update(['is_active' => false]);
        $this->get('/dashboard')->assertOk()->assertViewHas('available', 0);

        $b['space']->fresh()->update(['is_active' => true]);
        $b['floor']->update(['is_active' => false]);
        $this->get('/dashboard')->assertOk()->assertViewHas('available', 0);

        $b['floor']->update(['is_active' => true]);
        $b['sector']->fresh()->update(['is_active' => false]);
        $this->get('/dashboard')->assertOk()->assertViewHas('available', 0);
    }

    public function test_subscription_relationships_overlap_and_deactivation(): void
    {
        $this->signIn('operador');
        $b = $this->basics();
        $payload = ['client_id' => $b['client']->id, 'vehicle_id' => $b['vehicle']->id, 'rate_id' => $b['rate']->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'amount' => 100];
        $this->postJson('/abonados', $payload)->assertCreated();
        $id = Subscription::firstOrFail()->id;
        $this->postJson('/abonados', $payload)->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->putJson('/abonados/'.$id, $payload + ['space_id' => $b['space']->id])->assertOk();
        $this->patchJson('/abonados/'.$id.'/estado', ['is_active' => false])->assertOk();
        $this->assertDatabaseHas('subscriptions', ['id' => $id, 'is_active' => false]);
        $this->patchJson('/abonados/'.$id.'/estado', ['is_active' => true])->assertOk();
        $this->patchJson('/abonados/'.$id.'/estado', ['is_active' => false])->assertOk();
        $other = Client::create(['name' => 'Otro', 'document' => 'DOC-2']);
        $this->postJson('/abonados',array_merge($payload,['client_id' => $other->id]))->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
    }
}
