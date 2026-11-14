<?php

namespace App\Http\Controllers\Catalog;

use App\Models\VehicleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class VehicleTypeController extends CatalogController
{
    protected function modelClass(): string
    {
        return VehicleType::class;
    }

    protected function title(): string
    {
        return 'Tipos de vehículos';
    }

    protected function fields(): array
    {
        return [['name', 'Nombre del tipo', 'text', true]];
    }

    protected function columns(): array
    {
        return [['name', 'Tipo'], ['is_active', 'Estado']];
    }

    protected function rules(?Model $item = null): array
    {
        return ['name' => ['required', 'string', 'max:255', Rule::unique('vehicle_types', 'name')->ignore($item?->id)]];
    }

    protected function checkStatus(Model $item, bool $active): void
    {
        if (! $active && ($item->vehicles()->where('is_active', true)->exists() || $item->spaces()->where('is_active', true)->exists() || $item->rates()->where('is_active', true)->exists())) {
            $this->invalid('is_active', 'El tipo tiene vehículos, espacios o tarifas activos.');
        }
    }
}
