<?php

namespace App\Http\Controllers\Catalog;

use App\Models\Client;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehicleController extends CatalogController
{
    protected function modelClass(): string
    {
        return Vehicle::class;
    }

    protected function title(): string
    {
        return 'Vehículos';
    }

    protected function fields(): array
    {
        return [
            ['plate', 'Placa', 'text', true], ['brand', 'Marca', 'text', false], ['model', 'Modelo', 'text', false], ['color', 'Color', 'text', false],
            ['vehicle_type_id', 'Tipo de vehículo', 'select:types', true], ['client_id', 'Cliente (opcional)', 'select:clients', false],
        ];
    }

    protected function columns(): array
    {
        return [['plate', 'Placa'], ['vehicle_type.name', 'Tipo'], ['client.name', 'Cliente'], ['brand', 'Marca'], ['model', 'Modelo'], ['is_active', 'Estado']];
    }

    protected function lookups(): array
    {
        return ['types' => VehicleType::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'clients' => Client::where('is_active', true)->orderBy('name')->get(['id', 'name'])];
    }

    protected function with(): array
    {
        return ['client', 'vehicleType'];
    }

    protected function rules(?Model $item = null): array
    {
        return [
            'plate' => ['required', 'string', 'max:20', Rule::unique('vehicles', 'plate')->ignore($item?->id)],
            'brand' => ['nullable', 'string', 'max:255'], 'model' => ['nullable', 'string', 'max:255'], 'color' => ['nullable', 'string', 'max:255'],
            'vehicle_type_id' => ['required', 'integer', Rule::exists('vehicle_types', 'id')->where('is_active', true)],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('is_active', true)],
        ];
    }

    protected function prepare(array $data, ?Model $item = null): array
    {
        $data['plate'] = mb_strtoupper(trim($data['plate']));

        return $data;
    }

    protected function normalize(Request $request): void
    {
        if ($request->filled('plate')) {
            $request->merge(['plate' => mb_strtoupper(trim($request->input('plate')))]);
        }
    }

    protected function checkStatus(Model $item, bool $active): void
    {
        if ($active && (! $item->vehicleType?->is_active || ($item->client && ! $item->client->is_active))) {
            $this->invalid('is_active', 'Activa primero el tipo de vehículo y su cliente.');
        }
        if (! $active && ($item->tickets()->where('status', 'open')->exists() || $item->subscriptions()->where('is_active', true)->whereDate('ends_on', '>=', today())->exists())) {
            $this->invalid('is_active','No se puede desactivar un vehículo con boleto abierto o abono vigente.');
        }
    }
}
