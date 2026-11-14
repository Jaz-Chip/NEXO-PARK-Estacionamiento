<?php

namespace Tests\Feature;

use App\Models\{Floor, Payment, PaymentMethod, Rate, Role, Sector, Space, Ticket, User, Vehicle, VehicleType};
use App\Services\ParkingBilling;
use Database\Seeders\{PaymentMethodSeeder, RoleSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseThreeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RoleSeeder::class, PaymentMethodSeeder::class]);
        $this->travelTo(now()->setDate(2026, 11, 11)->setTime(10, 0));
    }

    private function user(string $slug = 'administrador'): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->firstOrFail()->id, 'is_active' => true]);
    }

    private function fixtures(string $unit = 'hour', string $amount = '5.25'): array
    {
        $type = VehicleType::create(['name' => 'Auto '.fake()->uuid(), 'is_active' => true]);
        $floor = Floor::create(['name' => 'Piso '.fake()->uuid(), 'is_active' => true]);
        $sector = Sector::create(['floor_id' => $floor->id, 'name' => 'Norte', 'is_active' => true]);
        $space = Space::create(['code' => 'A1', 'floor_id' => $floor->id, 'sector_id' => $sector->id, 'vehicle_type_id' => $type->id, 'status' => 'available', 'is_active' => true]);
        $vehicle = Vehicle::create(['plate' => strtoupper(fake()->bothify('###????')), 'vehicle_type_id' => $type->id, 'is_active' => true]);
        $rate = Rate::create(['name' => 'Tarifa', 'vehicle_type_id' => $type->id, 'unit' => $unit, 'fraction_minutes' => $unit === 'fraction' ? 15 : null, 'amount' => $amount, 'is_active' => true]);

        return compact('type', 'floor', 'sector', 'space', 'vehicle', 'rate');
    }

    private function enter(array $b)
    {
        return $this->postJson('/entradas', ['vehicle_id' => $b['vehicle']->id, 'space_id' => $b['space']->id, 'rate_id' => $b['rate']->id]);
    }

    private function pay(int $id, string $total = '5.25', array $extra = [])
    {
        return $this->postJson('/salidas/'.$id.'/cobrar', array_replace(['method' => 'cash', 'expected_total' => $total], $extra));
    }

    public function test_entry_search_ticket_and_statistics(): void
    {
        $this->actingAs($this->user());
        $b = $this->fixtures();
        foreach (['entradas', 'salidas', 'tickets', 'caja'] as $module) {
            $this->get('/'.$module)->assertOk()->assertSee('operations-config');
        }
        $this->getJson('/entradas/opciones?plate='.strtolower($b['vehicle']->plate))->assertOk()->assertJsonPath('vehicle.id', $b['vehicle']->id)->assertJsonCount(1, 'spaces');
        $result = $this->enter($b)->assertCreated()->assertJsonPath('data.plate', $b['vehicle']->plate)->assertJsonPath('data.status', 'open');
        $ticket = Ticket::findOrFail($result->json('data.id'));
        $this->assertSame(now()->timestamp, $ticket->entered_at->timestamp);
        $this->assertSame('5.25', $ticket->rate_amount);
        $this->assertSame('occupied', $b['space']->fresh()->status);
        $this->assertStringContainsString($ticket->number, $result->json('print_html'));
        $this->getJson('/entradas/datos')->assertJsonPath('stats.available', 0)->assertJsonPath('stats.occupied', 1)->assertJsonCount(1, 'data');
        $this->getJson('/salidas/buscar?search='.$ticket->number)->assertOk()->assertJsonPath('data.id', $ticket->id);
        $this->getJson('/salidas/buscar?search='.strtolower($b['vehicle']->plate))->assertOk()->assertJsonPath('data.id', $ticket->id);
        $this->getJson('/tickets/'.$ticket->id.'/imprimir')->assertOk();
        $this->getJson('/entradas/opciones?plate='.$b['vehicle']->plate)->assertJsonCount(0, 'spaces');
    }

    public function test_duplicate_vehicle_and_occupied_or_inactive_spaces_are_rejected(): void
    {
        $this->actingAs($this->user());
        $b = $this->fixtures();
        foreach (['space', 'floor', 'sector'] as $key) {
            $b[$key]->update(['is_active' => false]);
            $this->enter($b)->assertUnprocessable()->assertJsonValidationErrors('space_id');
            $this->getJson('/entradas/opciones?plate='.$b['vehicle']->plate)->assertJsonCount(0, 'spaces');
            $b[$key]->update(['is_active' => true]);
        }
        $b['space']->update(['status' => 'maintenance']);
        $this->enter($b)->assertUnprocessable();
        $b['space']->update(['status' => 'available']);
        $this->enter($b)->assertCreated();
        $this->enter($b)->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
        $b['vehicle'] = Vehicle::create(['plate' => 'NEW100', 'vehicle_type_id' => $b['type']->id, 'is_active' => true]);
        $this->enter($b)->assertUnprocessable()->assertJsonValidationErrors('space_id');
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_rate_and_vehicle_validation_and_reserved_space(): void
    {
        $this->actingAs($this->user());
        $b = $this->fixtures();
        $b['vehicle']->update(['is_active' => false]);
        $this->enter($b)->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
        $b['vehicle']->update(['is_active' => true]);
        $b['rate']->update(['is_active' => false]);
        $this->enter($b)->assertUnprocessable()->assertJsonValidationErrors('rate_id');
        $b['rate']->update(['is_active' => true]);
        $other = $this->fixtures();
        $bad = $b; $bad['rate'] = $other['rate'];
        $this->enter($bad)->assertUnprocessable()->assertJsonValidationErrors('rate_id');
        $bad = $b; $bad['space'] = $other['space'];
        $this->enter($bad)->assertUnprocessable()->assertJsonValidationErrors('space_id');
        $client = \App\Models\Client::create(['name' => 'Abonado', 'document' => '123', 'is_active' => true]);
        \App\Models\Subscription::create(['client_id' => $client->id, 'vehicle_id' => $other['vehicle']->id, 'space_id' => $b['space']->id, 'rate_id' => $b['rate']->id, 'starts_on' => today(), 'ends_on' => today()->addDay(), 'amount' => '100.00', 'is_active' => true]);
        $this->enter($b)->assertUnprocessable()->assertJsonValidationErrors('space_id');
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_exact_decimal_calculation_rounding_and_snapshot(): void
    {
        $this->actingAs($this->user());
        foreach ([['minute', 60, '0.10'], ['hour', 3600, '0.10'], ['fraction', 900, '0.10'], ['day', 86400, '0.10']] as [$unit, $seconds, $price]) {
            $b = $this->fixtures($unit, $price);
            $id = $this->enter($b)->assertCreated()->json('data.id');
            $ticket = Ticket::findOrFail($id);
            $billing = app(ParkingBilling::class);
            $this->assertSame('0.10', $billing->quote($ticket, $ticket->entered_at)['total']);
            $this->assertSame('0.10', $billing->quote($ticket, $ticket->entered_at->copy()->addSeconds($seconds))['total']);
            $this->assertSame('0.20', $billing->quote($ticket, $ticket->entered_at->copy()->addSeconds($seconds + 1))['total']);
            $this->assertSame('0.30', $billing->quote($ticket, $ticket->entered_at->copy()->addSeconds(3 * $seconds))['total']);
            $payload = $b['rate']->only(['name', 'vehicle_type_id', 'unit', 'fraction_minutes']);
            $this->putJson('/tarifas/'.$b['rate']->id, [...$payload, 'amount' => '9.99'])->assertOk();
            $this->assertSame('0.10', $billing->quote($ticket->fresh(), $ticket->entered_at)['total']);
            $this->putJson('/tarifas/'.$b['rate']->id, [...$payload, 'unit' => 'month', 'fraction_minutes' => null, 'amount' => '9.99'])->assertUnprocessable();
        }
        $this->travelTo(now()->setDate(2026, 1, 31));
        $b = $this->fixtures('month', '100.00');
        $id = $this->enter($b)->assertCreated()->json('data.id');
        $ticket = Ticket::findOrFail($id);
        $end = $ticket->entered_at->copy()->addMonthNoOverflow();
        $this->assertSame('100.00', app(ParkingBilling::class)->quote($ticket, $end)['total']);
        $this->assertSame('200.00', app(ParkingBilling::class)->quote($ticket, $end->addSecond())['total']);
    }

    public function test_payment_exit_release_and_duplicate_collection(): void
    {
        $user = $this->user(); $this->actingAs($user);
        $b = $this->fixtures();
        $id = $this->enter($b)->assertCreated()->json('data.id');
        $this->travel(61)->minutes();
        $this->getJson('/salidas/'.$id.'/cotizacion')->assertOk()->assertJsonPath('quote.total', '10.50');
        $this->pay($id)->assertUnprocessable()->assertJsonValidationErrors('expected_total');
        $this->assertSame('occupied', $b['space']->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
        $result = $this->pay($id, '10.50')->assertOk()->assertJsonPath('total', '10.50');
        $this->assertDatabaseHas('tickets', ['id' => $id, 'status' => 'closed', 'closed_by' => $user->id]);
        $this->assertDatabaseHas('payments', ['ticket_id' => $id, 'user_id' => $user->id, 'amount' => '10.50']);
        $this->assertSame('available', $b['space']->fresh()->status);
        $this->assertNotNull(Ticket::findOrFail($id)->exited_at);
        $this->pay($id, '10.50')->assertUnprocessable();
        $this->assertDatabaseCount('payments', 1);
        $this->getJson('/entradas/datos')->assertJsonCount(0, 'data')->assertJsonPath('stats.available', 1);
        $this->getJson('/caja/'.$result->json('payment_id').'/comprobante')->assertOk();
        $this->getJson('/caja/datos?method=cash&search='.$b['vehicle']->plate)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/caja/datos?method=qr')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/caja/datos?from=2026-11-12&to=2026-11-13')->assertOk()->assertJsonCount(0, 'data');
        $this->enter($b)->assertCreated(); // A new stay is allowed after closing the previous one.
        $this->assertDatabaseCount('tickets', 2);
    }

    public function test_manual_qr_confirmation_and_disabled_payment_method(): void
    {
        $this->actingAs($this->user()); $b = $this->fixtures();
        $id = $this->enter($b)->assertCreated()->json('data.id');
        $this->pay($id, '5.25', ['method' => 'qr'])->assertUnprocessable()->assertJsonValidationErrors('qr_confirmed');
        $this->pay($id, '5.25', ['method' => 'qr', 'qr_confirmed' => false])->assertUnprocessable();
        PaymentMethod::where('name', 'Transferencia / QR')->update(['is_active' => false]);
        $this->pay($id, '5.25', ['method' => 'qr', 'qr_confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors('method');
        PaymentMethod::where('name', 'Transferencia / QR')->update(['is_active' => true]);
        $this->pay($id, '5.25', ['method' => 'qr', 'qr_confirmed' => true, 'reference' => 'QR-123'])->assertOk();
        $this->assertDatabaseHas('payments', ['reference' => 'QR-123', 'ticket_id' => $id]);
        $this->getJson('/caja/datos?method=qr')->assertJsonCount(1, 'data');
    }

    public function test_failure_after_creating_payment_rolls_back_the_entire_checkout(): void
    {
        $this->actingAs($this->user()); $b = $this->fixtures();
        $id = $this->enter($b)->assertCreated()->json('data.id');
        // Fail at the final SQL write, after the payment and ticket update.
        DB::unprepared("CREATE TRIGGER reject_release BEFORE UPDATE ON spaces WHEN NEW.status = 'available' BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");
        try {
            $this->pay($id)->assertStatus(500);
            $this->assertDatabaseCount('payments', 0);
            $this->assertSame('open', Ticket::findOrFail($id)->status);
            $this->assertSame('occupied', $b['space']->fresh()->status);
        } finally {
            DB::unprepared('DROP TRIGGER reject_release');
        }
        $this->pay($id)->assertOk();
    }

    public function test_existing_paid_ticket_cannot_be_collected_again_even_if_open(): void
    {
        $user = $this->user(); $this->actingAs($user); $b = $this->fixtures();
        $id = $this->enter($b)->assertCreated()->json('data.id');
        Payment::create(['ticket_id' => $id, 'payment_method_id' => PaymentMethod::firstOrFail()->id, 'user_id' => $user->id, 'amount' => '5.25', 'status' => 'paid', 'paid_at' => now()]);
        $this->pay($id)->assertUnprocessable()->assertJsonValidationErrors('ticket');
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('occupied', $b['space']->fresh()->status);
    }

    public function test_spatie_authorization_requires_both_exit_and_charge_and_revocation_is_immediate(): void
    {
        $operator = $this->user('operador'); $supervisor = $this->user('supervisor');
        $b = $this->fixtures();
        $this->actingAs($operator);
        foreach (['entradas', 'salidas', 'tickets', 'caja'] as $module) {
            $this->getJson('/'.$module.'/datos')->assertForbidden();
        }
        $this->enter($b)->assertForbidden();
        $operator->roles->first()->syncPermissions(['entradas.view', 'entradas.create', 'salidas.view', 'salidas.execute']);
        $operator = $operator->fresh(); $this->actingAs($operator);
        $this->get('/entradas')->assertOk()->assertSee('Registrar entrada')->assertDontSee('id="new-vehicle"', false);
        $id = $this->enter($b)->assertCreated()->json('data.id');
        $this->pay($id)->assertForbidden();
        $this->getJson('/tickets/'.$id.'/imprimir')->assertForbidden();
        $operator->roles->first()->givePermissionTo('caja.charge');
        $this->actingAs($operator->fresh());
        $this->pay($id)->assertOk();
        $operator->roles->first()->revokePermissionTo('entradas.create');
        $this->actingAs($operator->fresh());
        $this->enter($b)->assertForbidden();
        $supervisor->roles->first()->syncPermissions(['salidas.view', 'caja.view']);
        $this->actingAs($supervisor->fresh());
        $this->get('/caja')->assertOk()->assertDontSee('Confirmar pago y salida');
        $this->pay($id)->assertForbidden();
        $this->getJson('/caja/datos')->assertOk()->assertJsonCount(1, 'data');
    }
}
