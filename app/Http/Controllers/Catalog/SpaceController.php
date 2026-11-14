<?php

namespace App\Http\Controllers\Catalog;

use App\Models\Floor;
use App\Models\Sector;
use App\Models\Space;
use App\Models\VehicleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SpaceController extends CatalogController
{
    protected function modelClass(): string
    {
        return Space::class;
    }

    protected function title(): string
    {
        return 'Espacios';
    }

    protected function fields(): array
    {
        return [
            ['floor_id', 'Piso', 'select:floors', true], ['sector_id', 'Sector', 'select:sectors', true],
            ['code', 'Código', 'text', true], ['vehicle_type_id', 'Tipo permitido (opcional)', 'select:types', false],
            ['status', 'Condición', 'select:space_status', true],
        ];
    }

    protected function columns(): array
    {
        return [['floor.name', 'Piso'], ['sector.name', 'Sector'], ['code', 'Código'], ['vehicle_type.name', 'Tipo'], ['status', 'Condición'], ['is_active', 'Estado']];
    }

    protected function lookups(): array
    {
        return [
            'floors' => Floor::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'sectors' => Sector::where('is_active', true)->orderBy('name')->get(['id', 'name', 'floor_id']),
            'types' => VehicleType::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'space_status' => [['id' => 'available', 'name' => 'Disponible'], ['id' => 'maintenance', 'name' => 'Mantenimiento']],
        ];
    }

    protected function with(): array
    {
        return ['floor', 'sector', 'vehicleType'];
    }

    protected function rules(?Model $item = null): array
    {
        return [
            'floor_id' => ['required', 'integer', Rule::exists('floors', 'id')->where('is_active', true)],
            'sector_id' => ['required', 'integer', Rule::exists('sectors', 'id')->where('is_active', true)],
            'code' => ['required', 'string', 'max:20', Rule::unique('spaces', 'code')->where('floor_id', request('floor_id'))->where('sector_id', request('sector_id'))->ignore($item?->id)],
            'vehicle_type_id' => ['nullable', 'integer', Rule::exists('vehicle_types', 'id')->where('is_active', true)],
            'status' => ['required', Rule::in(['available', 'maintenance'])],
        ];
    }

    protected function check(array $data, ?Model $item, Validator $validator): void
    {
        if (Sector::whereKey($data['sector_id'])->value('floor_id') != $data['floor_id']) {
            $validator->errors()->add('sector_id', 'El sector no pertenece al piso seleccionado.');
        }
        if ($item && $item->status === 'occupied') {
            $validator->errors()->add('status', 'Un espacio ocupado no puede modificarse hasta que termine la estancia.');
        }
    }

    protected function prepare(array $data, ?Model $item = null): array
    {
        $data['code'] = mb_strtoupper(trim($data['code']));
        if ($item && $item->status === 'occupied') {
            $data['status'] = 'occupied';
        }

        return $data;
    }

    protected function normalize(Request $request): void
    {
        if ($request->filled('code')) {
            $request->merge(['code' => mb_strtoupper(trim($request->input('code')))]);
        }
    }

    protected function checkStatus(Model $item, bool $active): void
    {
        if ($active && (! $item->floor?->is_active || ! $item->sector?->is_active || ($item->vehicleType && ! $item->vehicleType->is_active))) {
            $this->invalid('is_active', 'Activa primero piso, sector y tipo de vehículo.');
        }
        if (! $active && ($item->status === 'occupied' || $item->tickets()->where('status', 'open')->exists() || $item->subscriptions()->where('is_active', true)->whereDate('ends_on', '>=', today())->exists())) {
            $this->invalid('is_active','No se puede desactivar un espacio ocupado o reservado por un abono vigente.');
        }
    }
}
