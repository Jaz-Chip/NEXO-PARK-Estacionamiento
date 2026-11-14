<?php

namespace App\Http\Controllers\Catalog;

use App\Models\Floor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class FloorController extends CatalogController
{
    protected function modelClass(): string
    {
        return Floor::class;
    }

    protected function title(): string
    {
        return 'Pisos';
    }

    protected function fields(): array
    {
        return [['name', 'Nombre del piso', 'text', true]];
    }

    protected function columns(): array
    {
        return [['name', 'Piso'], ['is_active', 'Estado']];
    }

    protected function rules(?Model $item = null): array
    {
        return ['name' => ['required', 'string', 'max:255', Rule::unique('floors', 'name')->ignore($item?->id)]];
    }

    protected function checkStatus(Model $item, bool $active): void
    {
        if (! $active && $item->spaces()->where('is_active', true)->exists()) {
            $this->invalid('is_active', 'Desactiva primero los espacios de este piso.');
        }
    }
}
