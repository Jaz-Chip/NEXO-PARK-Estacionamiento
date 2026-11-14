<?php

namespace Tests\Feature;

use App\Models\{Client, Floor, Rate, Role, Sector, Space, Subscription, User, Vehicle, VehicleType};
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $this->actingAs(User::factory()->create(['role_id' => Role::where('slug', 'administrador')->firstOrFail()->id, 'is_active' => true]));
        $client = Client::create(['name' => 'Cliente auditoría', 'document' => 'AUDIT']);
        $type = VehicleType::create(['name' => 'Auto auditoría']);
        $vehicle = Vehicle::create(['plate' => 'AUDIT', 'client_id' => $client->id, 'vehicle_type_id' => $type->id]);
        $floor = Floor::create(['name' => 'Piso auditoría']);
        $sector = Sector::create(['name' => 'Sector auditoría', 'floor_id' => $floor->id]);
        $space = Space::create(['code' => 'AUDIT', 'floor_id' => $floor->id, 'sector_id' => $sector->id, 'status' => 'available']);
        $rate = Rate::create(['name' => 'Mensual', 'vehicle_type_id' => $type->id, 'unit' => 'month', 'amount' => '100.00']);
        $payload = ['client_id' => $client->id, 'vehicle_id' => $vehicle->id, 'rate_id' => $rate->id, 'space_id' => $space->id,
            'starts_on' => today()->toDateString(), 'ends_on' => today()->addMonth()->toDateString(), 'amount' => '100.00'];

        return compact('client', 'type', 'vehicle', 'floor', 'sector', 'space', 'rate', 'payload');
    }

    public function test_subscription_rejects_inactive_location_on_creation_and_reactivation(): void
    {
        $b = $this->scenario();
        foreach (['floor', 'sector'] as $relation) {
            $b[$relation]->update(['is_active' => false]);
            $this->postJson('/abonados', $b['payload'])->assertUnprocessable()->assertJsonValidationErrors('space_id');
            $b[$relation]->update(['is_active' => true]);
        }
        $subscription = Subscription::create([...$b['payload'], 'is_active' => false]);
        $b['floor']->update(['is_active' => false]);
        $this->patchJson('/abonados/'.$subscription->id.'/estado', ['is_active' => true])->assertUnprocessable();
        $this->assertFalse($subscription->fresh()->is_active);
    }

    public function test_subscription_reactivation_revalidates_ownership_and_type(): void
    {
        $b = $this->scenario();
        $subscription = Subscription::create([...$b['payload'], 'is_active' => false]);
        $otherClient = Client::create(['name' => 'Otro cliente', 'document' => 'AUDIT-2']);
        $b['vehicle']->update(['client_id' => $otherClient->id]);
        $this->patchJson('/abonados/'.$subscription->id.'/estado', ['is_active' => true])->assertUnprocessable();
        $b['vehicle']->update(['client_id' => $b['client']->id]);
        $b['type']->update(['is_active' => false]);
        $this->patchJson('/abonados/'.$subscription->id.'/estado', ['is_active' => true])->assertUnprocessable();
    }

    public function test_sector_cannot_move_to_another_floor_while_it_has_spaces(): void
    {
        $b = $this->scenario();
        $otherFloor = Floor::create(['name' => 'Otro piso']);
        $this->putJson('/sectores/'.$b['sector']->id, ['name' => $b['sector']->name, 'floor_id' => $otherFloor->id])
            ->assertUnprocessable()->assertJsonValidationErrors('floor_id');
        $this->assertSame($b['floor']->id, $b['sector']->fresh()->floor_id);
    }

    public function test_inconsistent_legacy_location_is_not_offered_as_available(): void
    {
        $b = $this->scenario();
        $otherFloor = Floor::create(['name' => 'Otro piso']);
        $b['sector']->update(['floor_id' => $otherFloor->id]);
        $this->get('/dashboard')->assertOk()->assertViewHas('available', 0);
        $this->getJson('/entradas/opciones?plate=AUDIT')->assertOk()->assertJsonCount(0, 'spaces');
    }
}
