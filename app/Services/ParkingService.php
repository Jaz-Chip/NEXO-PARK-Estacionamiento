<?php

namespace App\Services;

use App\Models\{Client, Floor, Payment, PaymentMethod, Rate, Sector, Space, Subscription, Ticket, User, Vehicle, VehicleType};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ParkingService
{
    public function __construct(private ParkingBilling $billing) {}

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    public function enter(array $data, User $operator): Ticket
    {
        return DB::transaction(function () use ($data, $operator) {
            // Lock stable parent rows, including when there are no tickets yet.
            // All entry requests serialize on the vehicle and then the space.
            $vehicle = Vehicle::lockForUpdate()->findOrFail($data['vehicle_id']);
            $type = VehicleType::lockForUpdate()->findOrFail($vehicle->vehicle_type_id);
            $client = $vehicle->client_id ? Client::lockForUpdate()->find($vehicle->client_id) : null;
            $this->require($vehicle->is_active && $type->is_active && (! $client || $client->is_active), 'vehicle_id', 'El vehículo, su tipo y su cliente deben estar activos.');
            $this->require(! Ticket::where('vehicle_id', $vehicle->id)->where('status', 'open')->lockForUpdate()->first(), 'vehicle_id', 'El vehículo ya tiene una entrada abierta.');

            $space = Space::lockForUpdate()->findOrFail($data['space_id']);
            $floor = $space->floor_id ? Floor::lockForUpdate()->find($space->floor_id) : null;
            $sector = $space->sector_id ? Sector::lockForUpdate()->find($space->sector_id) : null;
            $this->require($space->is_active && $space->status === 'available'
                && (! $floor || $floor->is_active) && (! $sector || ($sector->is_active && $sector->floor_id === $space->floor_id)),
                'space_id', 'El espacio, piso o sector no está disponible.');
            $this->require(! $space->vehicle_type_id || $space->vehicle_type_id === $vehicle->vehicle_type_id, 'space_id', 'El espacio no admite este tipo de vehículo.');
            $this->require(! Ticket::where('space_id', $space->id)->where('status', 'open')->lockForUpdate()->first(), 'space_id', 'El espacio ya tiene un boleto abierto.');
            $this->require(! Subscription::where('space_id', $space->id)->where('is_active', true)
                ->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today())
                ->where('vehicle_id', '!=', $vehicle->id)->lockForUpdate()->first(), 'space_id', 'El espacio está reservado para otro abonado.');

            $rate = Rate::lockForUpdate()->findOrFail($data['rate_id']);
            $this->require($rate->is_active && $rate->vehicle_type_id === $vehicle->vehicle_type_id, 'rate_id', 'Selecciona una tarifa activa para este tipo de vehículo.');
            $ticket = new Ticket([
                'number' => 'E-'.Str::ulid(), 'vehicle_id' => $vehicle->id, 'space_id' => $space->id,
                'rate_id' => $rate->id, 'opened_by' => $operator->id, 'entered_at' => now()->startOfSecond(),
                'rate_amount' => $rate->amount, 'total' => '0.00', 'status' => 'open',
            ]);
            $ticket->setRelation('rate', $rate);
            $this->billing->quote($ticket, $ticket->entered_at); // Reject malformed legacy rates before occupying.
            $ticket->save();
            $space->update(['status' => 'occupied']);

            return $ticket;
        }, 5);
    }

    public function checkout(int $id, array $data, User $operator): Payment
    {
        return DB::transaction(function () use ($id, $data, $operator) {
            $ticket = Ticket::lockForUpdate()->findOrFail($id);
            $this->require($ticket->status === 'open' && ! $ticket->exited_at, 'ticket', 'Este boleto ya fue cerrado o anulado.');
            $this->require(! $ticket->payments()->where('status', 'paid')->lockForUpdate()->first(), 'ticket', 'Este boleto ya tiene un pago registrado.');
            $space = Space::lockForUpdate()->findOrFail($ticket->space_id);
            $this->require($space->status === 'occupied', 'ticket', 'El estado del espacio no corresponde al boleto.');
            $this->require(! Ticket::where('space_id', $space->id)->where('status', 'open')->whereKeyNot($ticket->id)->lockForUpdate()->first(), 'ticket', 'Hay otro boleto abierto en este espacio. Revisa la inconsistencia antes de cobrar.');
            $at = now()->startOfSecond();
            $quote = $this->billing->quote($ticket, $at);
            // The submitted total is only a stale-quote check; the server calculates the charge.
            $this->require(ParkingBilling::cents($data['expected_total']) === ParkingBilling::cents($quote['total']), 'expected_total', 'El importe cambió por el tiempo transcurrido. Actualiza el cálculo y confirma nuevamente.');
            $method = PaymentMethod::where('is_active', true)
                ->whereIn('name', $data['method'] === 'cash' ? ['Efectivo'] : ['QR', 'Transferencia / QR'])
                ->orderBy('id')->lockForUpdate()->first();
            $this->require((bool) $method, 'method', 'El método de pago no está activo en el sistema.');
            $payment = Payment::create([
                'ticket_id' => $ticket->id, 'payment_method_id' => $method->id, 'user_id' => $operator->id,
                'amount' => $quote['total'], 'reference' => $data['reference'] ?? null, 'status' => 'paid', 'paid_at' => $at,
            ]);
            $ticket->update(['status' => 'closed', 'closed_by' => $operator->id, 'exited_at' => $at, 'total' => $quote['total']]);
            $space->update(['status' => 'available']);

            return $payment;
        }, 5);
    }
}
