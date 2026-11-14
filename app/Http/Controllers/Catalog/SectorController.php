<?php

namespace App\Http\Controllers\Catalog;

use App\Models\Floor;
use App\Models\Sector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SectorController extends CatalogController
{
    protected function modelClass(): string
    {
        return Sector::class;
    }

    protected function title(): string
    {
        return 'Sectores';
    }

    protected function fields(): array
    {
        return [['floor_id', 'Piso', 'select:floors', true], ['name', 'Nombre del sector', 'text', true]];
    }

    protected function columns(): array
    {
        return [['floor.name', 'Piso'], ['name', 'Sector'], ['is_active', 'Estado']];
    }

    protected function lookups(): array
    {
        return ['floors' => Floor::where('is_active', true)->orderBy('name')->get(['id', 'name'])];
    }

    protected function with(): array
    {
        return ['floor'];
    }

    protected function rules(?Model $item = null): array
    {
        return [
            'floor_id' => ['required', 'integer', Rule::exists('floors', 'id')->where('is_active', true)],
            'name' => ['required', 'string', 'max:255', Rule::unique('sectors', 'name')->where('floor_id', request('floor_id'))->ignore($item?->id)],
        ];
    }

    protected function check(array $data, ?Model $item, Validator $validator): void
    {
        if ($item && $item->floor_id != $data['floor_id'] && $item->spaces()->exists()) {
            $validator->errors()->add('floor_id', 'No se puede cambiar el piso de un sector que tiene espacios asociados.');
        }
    }

    protected function checkStatus(Model $item, bool $active): void
    {
        if ($active && ! $item->floor?->is_active) {
            $this->invalid('is_active', 'Activa primero el piso del sector.');
        }
        if (! $active && $item->spaces()->where('is_active', true)->exists()) {
            $this->invalid('is_active', 'Desactiva primero los espacios de este sector.');
        }
    }
}
