<?php

namespace Tests\Support;

use App\Models\{Client, Floor, Payment, PaymentMethod, Rate, Sector, Space, Subscription, Ticket, User, Vehicle, VehicleType};

class ReportScenario
{
    public static function create(User $user, string $tag): array
    {
        $car = VehicleType::create(['name' => $tag.' Auto', 'is_active' => true]);
        $bike = VehicleType::create(['name' => $tag.' Moto', 'is_active' => true]);
        $floor = Floor::create(['name' => $tag, 'is_active' => true]);
        $sector = Sector::create(['name' => $tag, 'floor_id' => $floor->id, 'is_active' => true]);
        $a = Space::create(['code' => $tag.'A', 'floor_id' => $floor->id, 'sector_id' => $sector->id, 'vehicle_type_id' => $car->id, 'status' => 'occupied', 'is_active' => true]);
        $b = Space::create(['code' => $tag.'B', 'floor_id' => $floor->id, 'sector_id' => $sector->id, 'vehicle_type_id' => $bike->id, 'status' => 'available', 'is_active' => true]);
        $client = Client::create(['name' => $tag, 'document' => $tag, 'is_active' => true]);
        $v1 = Vehicle::create(['plate' => $tag.'A', 'client_id' => $client->id, 'vehicle_type_id' => $car->id, 'is_active' => true]);
        $v2 = Vehicle::create(['plate' => $tag.'B', 'vehicle_type_id' => $bike->id, 'is_active' => true]);
        $rate = Rate::create(['name' => $tag, 'vehicle_type_id' => $car->id, 'unit' => 'hour', 'amount' => '5.25', 'is_active' => true]);
        $cash = PaymentMethod::firstOrCreate(['name' => 'Efectivo'], ['is_active' => true]);
        $qr = PaymentMethod::firstOrCreate(['name' => 'Transferencia / QR'], ['is_active' => true]);
        $makeTicket = fn ($vehicle, $space, $entry, $exit, $status = 'closed') => Ticket::create([
            'number' => $tag.'-'.bin2hex(random_bytes(4)), 'vehicle_id' => $vehicle->id, 'space_id' => $space->id, 'rate_id' => $rate->id,
            'opened_by' => $user->id, 'closed_by' => $exit ? $user->id : null, 'entered_at' => $entry, 'exited_at' => $exit,
            'rate_amount' => '5.25', 'total' => '0.00', 'status' => $status,
        ]);
        $overnight = $makeTicket($v1, $a, '2026-10-31 23:30:00', '2026-11-01 01:30:00');
        $daytime = $makeTicket($v1, $a, '2026-11-01 10:00:00', '2026-11-01 11:00:00');
        $bikeVisit = $makeTicket($v2, $b, '2026-11-01 10:30:00', '2026-11-01 12:00:00');
        $open = $makeTicket($v1, $a, '2026-11-02 23:00:00', null, 'open');
        $overlap = $makeTicket($v1, $a, '2026-11-03 00:00:00', '2026-11-03 00:30:00');
        $midnight = $makeTicket($v2, $b, '2026-11-02 23:00:00', '2026-11-03 00:00:00');
        $makeTicket($v1, $a, '2026-11-01 05:00:00', '2026-11-01 06:00:00', 'cancelled');
        $subscription = Subscription::create(['client_id' => $client->id, 'vehicle_id' => $v1->id, 'rate_id' => $rate->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'amount' => '10.00', 'is_active' => true]);
        $pay = fn ($ticket, $method, $date, $amount, $status = 'paid', $sub = null) => Payment::create([
            'ticket_id' => $ticket?->id, 'subscription_id' => $sub, 'payment_method_id' => $method->id, 'user_id' => $user->id,
            'amount' => $amount, 'status' => $status, 'paid_at' => $date, 'reference' => '=2+2',
        ]);
        $pay($overnight, $cash, '2026-11-01 00:10:00', '0.10');
        $pay($daytime, $qr, '2026-11-01 23:59:59', '0.20');
        $pay($bikeVisit, $qr, '2026-11-02 00:00:00', '5.25');
        // Both legacy links point at one payment; it must be counted only once.
        $pay($overnight, $cash, '2026-11-02 12:00:00', '10.00', 'paid', $subscription->id);
        $pay($overnight, $cash, '2026-11-01 10:00:00', '100.00', 'void');
        $pay($overnight, $cash, '2026-10-31 23:59:59', '2.00');
        $pay($overnight, $cash, '2026-11-04 00:00:00', '7.00');

        return compact('car', 'bike', 'floor', 'sector', 'a', 'b', 'v1', 'v2', 'rate', 'cash', 'qr', 'open', 'subscription');
    }
}
