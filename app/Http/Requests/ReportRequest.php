<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = ['ingresos' => 'ingresos.view', 'vehiculos' => 'estadisticas.view', 'ocupacion' => 'ocupacion.view'][$this->route('report')] ?? null;

        return $permission && $this->user()?->can($permission);
    }

    protected function prepareForValidation(): void
    {
        $today = CarbonImmutable::now('America/La_Paz');
        $this->merge([
            'from' => $this->input('from', $today->startOfMonth()->toDateString()),
            'to' => $this->input('to', $today->toDateString()),
        ]);
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.CarbonImmutable::now('America/La_Paz')->toDateString()],
            'vehicle_type_id' => ['nullable', 'integer', 'exists:vehicle_types,id'],
            'payment_method_id' => [$this->route('report') === 'ingresos' ? 'nullable' : 'prohibited', 'integer', 'exists:payment_methods,id'],
            'snapshot' => ['nullable', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (! $validator->errors()->hasAny(['from', 'to']) && CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) > 365) {
                $validator->errors()->add('to', 'Consulta un máximo de 366 días por reporte.');
            }
        }];
    }

    public function filters(): array
    {
        return [
            'from' => $this->validated('from'), 'to' => $this->validated('to'),
            'vehicle_type_id' => $this->filled('vehicle_type_id') ? (int) $this->validated('vehicle_type_id') : null,
            'payment_method_id' => $this->filled('payment_method_id') ? (int) $this->validated('payment_method_id') : null,
        ];
    }
}
