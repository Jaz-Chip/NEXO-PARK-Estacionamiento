<?php

namespace App\Http\Controllers\Catalog;

use App\Models\Client;
use App\Models\Floor;
use App\Models\Rate;
use App\Models\Sector;
use App\Models\Space;
use App\Models\Subscription;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SubscriptionController extends CatalogController
{
    public function store(Request $request): JsonResponse
    {
        // Validation locks the vehicle and space before checking date overlaps.
        return DB::transaction(fn () => parent::store($request), 5);
    }

    protected function modelClass(): string
    {
        return Subscription::class;
    }

    protected function title(): string
    {
        return 'Abonados';
    }

    protected function fields(): array
    {
        return [
            ['client_id', 'Cliente', 'select:clients', true], ['vehicle_id', 'Vehículo', 'select:vehicles', true],
            ['rate_id', 'Tarifa mensual', 'select:rates', true], ['space_id', 'Espacio reservado (opcional)', 'select:spaces', false],
            ['starts_on', 'Fecha de inicio', 'date', true], ['ends_on', 'Vencimiento', 'date', true], ['amount', 'Importe (Bs)', 'number', true],
        ];
    }

    protected function columns(): array
    {
        return [['client.name', 'Cliente'], ['vehicle.plate', 'Placa'], ['rate.name', 'Tarifa'], ['starts_on', 'Inicio'], ['ends_on', 'Vencimiento'], ['amount', 'Importe Bs'], ['is_active', 'Estado']];
    }

    protected function lookups(): array
    {
        return [
            'clients' => Client::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'vehicles' => Vehicle::where('is_active', true)->orderBy('plate')->get(['id', 'plate as name', 'client_id', 'vehicle_type_id']),
            'rates' => Rate::where('is_active', true)->where('unit', 'month')->orderBy('name')->get(['id', 'name', 'vehicle_type_id', 'amount']),
            'spaces' => Space::operational()->where('status', 'available')->orderBy('code')->get(['id', 'code as name', 'vehicle_type_id']),
        ];
    }

    protected function with(): array
    {
        return ['client', 'vehicle', 'rate', 'space'];
    }

    protected function rules(?Model $item = null): array
    {
        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('is_active', true)],
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->where('is_active', true)],
            'rate_id' => ['required', 'integer', Rule::exists('rates', 'id')->where('is_active', true)->where('unit', 'month')],
            'space_id' => ['nullable', 'integer', Rule::exists('spaces', 'id')->where('is_active', true)->where('status', 'available')],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999.99'],
        ];
    }

    protected function check(array $data, ?Model $item, Validator $validator): void
    {
        // Use the same parent lock order as parking entries. Locking stable rows
        // also serializes reservations when no subscription exists yet.
        $vehicle = Vehicle::lockForUpdate()->findOrFail($data['vehicle_id']);
        $type = VehicleType::lockForUpdate()->findOrFail($vehicle->vehicle_type_id);
        $client = Client::lockForUpdate()->findOrFail($data['client_id']);
        if (! $vehicle->is_active || ! $type->is_active || ! $client->is_active) {
            $validator->errors()->add('vehicle_id', 'Cliente, vehículo y tipo deben estar activos.');
        }
        if ($vehicle?->client_id != $data['client_id']) {
            $validator->errors()->add('vehicle_id', 'El vehículo debe pertenecer al cliente.');
        }
        if (! empty($data['space_id'])) {
            $space = Space::lockForUpdate()->findOrFail($data['space_id']);
            $floor = $space->floor_id ? Floor::lockForUpdate()->find($space->floor_id) : null;
            $sector = $space->sector_id ? Sector::lockForUpdate()->find($space->sector_id) : null;
            if (! $space->is_active || $space->status !== 'available'
                || ($floor && ! $floor->is_active) || ($sector && (! $sector->is_active || $sector->floor_id !== $space->floor_id))
                || $space->tickets()->where('status', 'open')->lockForUpdate()->first()) {
                $validator->errors()->add('space_id', 'El espacio, piso o sector no está disponible.');
            }
            if ($space?->vehicle_type_id && $space->vehicle_type_id != $vehicle?->vehicle_type_id) {
                $validator->errors()->add('space_id', 'El espacio no admite este tipo de vehículo.');
            }
            if (Subscription::where('space_id', $data['space_id'])->where('is_active', true)->whereKeyNot($item?->id ?? 0)
                ->whereDate('starts_on', '<=', $data['ends_on'])->whereDate('ends_on', '>=', $data['starts_on'])->lockForUpdate()->first()) {
                $validator->errors()->add('space_id', 'El espacio ya está reservado en esas fechas.');
            }
        }
        $rate = Rate::lockForUpdate()->findOrFail($data['rate_id']);
        if (! $rate->is_active || $rate->unit !== 'month' || $rate->vehicle_type_id !== $vehicle->vehicle_type_id) {
            $validator->errors()->add('rate_id', 'Selecciona una tarifa mensual activa para este tipo de vehículo.');
        }
        if (Subscription::where('vehicle_id', $data['vehicle_id'])->where('is_active', true)->whereKeyNot($item?->id ?? 0)
            ->whereDate('starts_on', '<=', $data['ends_on'])->whereDate('ends_on', '>=', $data['starts_on'])->lockForUpdate()->first()) {
            $validator->errors()->add('starts_on', 'Ya existe un abono activo para ese vehículo en esas fechas.');
        }
    }

    protected function checkStatus(Model $item, bool $active): void
    {
        if (! $active) {
            return;
        }
        $data = [...$item->getAttributes(), 'starts_on' => $item->starts_on->toDateString(), 'ends_on' => $item->ends_on->toDateString()];
        $validator = validator($data, $this->rules($item));
        $validator->after(function (Validator $validator) use ($item) {
            if (! $validator->errors()->any()) {
                $this->check($validator->validated(), $item, $validator);
            }
        });
        $validator->validate();
    }
}
