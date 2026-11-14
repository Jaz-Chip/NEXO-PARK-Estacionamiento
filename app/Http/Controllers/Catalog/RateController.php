<?php

namespace App\Http\Controllers\Catalog;

use App\Models\Rate;
use App\Models\VehicleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RateController extends CatalogController
{
    protected function modelClass(): string
    {
        return Rate::class;
    }

    protected function title(): string
    {
        return 'Tarifas';
    }

    protected function fields(): array
    {
        return [
            ['name', 'Nombre', 'text', true], ['vehicle_type_id', 'Tipo de vehículo', 'select:types', true],
            ['unit', 'Unidad de cobro', 'select:units', true], ['fraction_minutes', 'Minutos por fracción', 'number', false],
            ['amount', 'Importe (Bs)', 'number', true],
        ];
    }

    protected function columns(): array
    {
        return [['name', 'Tarifa'], ['vehicle_type.name', 'Tipo'], ['unit', 'Unidad'], ['fraction_minutes', 'Min/fracción'], ['amount', 'Importe Bs'], ['is_active', 'Estado']];
    }

    protected function lookups(): array
    {
        return [
            'types' => VehicleType::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'units' => [['id' => 'minute', 'name' => 'Minuto'], ['id' => 'hour', 'name' => 'Hora'], ['id' => 'fraction', 'name' => 'Fracción'], ['id' => 'month', 'name' => 'Mes'], ['id' => 'day', 'name' => 'Día']],
        ];
    }

    protected function with(): array
    {
        return ['vehicleType'];
    }

    protected function rules(?Model $item = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'], 'vehicle_type_id' => ['required', 'integer', Rule::exists('vehicle_types', 'id')->where('is_active', true)],
            'unit' => ['required', Rule::in(['minute', 'hour', 'fraction', 'month', 'day'])],
            'fraction_minutes' => ['nullable', 'integer', 'min:1', 'max:59'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999.99'],
        ];
    }

    protected function check(array $data, ?Model $item, Validator $validator): void
    {
        if ($item && $item->tickets()->exists()) {
            foreach (['unit', 'fraction_minutes', 'vehicle_type_id'] as $field) {
                if (($data[$field] ?? null) != $item->$field) {
                    $validator->errors()->add($field, 'Esta tarifa ya tiene boletos. Crea otra tarifa para cambiar su unidad, fracción o tipo; el precio sí puede actualizarse para nuevas entradas.');
                }
            }
        }
        if ($data['unit'] === 'fraction' && empty($data['fraction_minutes'])) {
            $validator->errors()->add('fraction_minutes', 'Indica la duración de la fracción en minutos.');
        }
        if ($data['unit'] !== 'fraction' && ! empty($data['fraction_minutes'])) {
            $validator->errors()->add('fraction_minutes', 'Los minutos solo corresponden a tarifas por fracción.');
        }
    }

    protected function checkStatus(Model $item, bool $active): void
    {
        if ($active && ! $item->vehicleType?->is_active) {
            $this->invalid('is_active', 'Activa primero el tipo de vehículo.');
        }
    }
}
