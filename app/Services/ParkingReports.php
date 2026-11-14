<?php

namespace App\Services;

use App\Models\{PaymentMethod, Space, VehicleType};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ParkingReports
{
    public const TITLES = ['ingresos' => 'Ingresos', 'vehiculos' => 'Vehículos más frecuentes', 'ocupacion' => 'Ocupación del estacionamiento'];

    public static function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '').ParkingBilling::decimal(abs($cents));
    }

    private function local(string $value): CarbonImmutable
    {
        // Existing timestamps are written/read in the application's configured timezone.
        return CarbonImmutable::parse($value, config('app.timezone'))->setTimezone('America/La_Paz');
    }

    private function stored(CarbonImmutable $date): string
    {
        return $date->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    private function column(string $key, string $label, string $format = 'text'): array
    {
        return compact('key', 'label', 'format');
    }

    private function table(string $key, string $title, array $columns, array $rows): array
    {
        return compact('key', 'title', 'columns', 'rows');
    }

    private function metric(string $label, mixed $value, string $format = 'number'): array
    {
        return compact('label', 'value', 'format');
    }

    private function chart(string $title, string $type, array $labels, array $datasets): array
    {
        return compact('title', 'type', 'labels', 'datasets');
    }

    public function build(string $report, array $filters): array
    {
        return DB::transaction(function () use ($report, $filters) {
            $start = CarbonImmutable::parse($filters['from'], 'America/La_Paz')->startOfDay();
            $end = CarbonImmutable::parse($filters['to'], 'America/La_Paz')->addDay()->startOfDay();
            $at = CarbonImmutable::now('America/La_Paz')->startOfSecond();
            $result = match ($report) {
                'ingresos' => $this->income($filters, $start, $end),
                'vehiculos' => $this->vehicles($filters, $start, $end),
                'ocupacion' => $this->occupancy($filters, $start, $end, $at),
            };

            return [
                'report' => $report, 'title' => self::TITLES[$report], 'filters' => $filters,
                'filter_labels' => [
                    'Desde' => $filters['from'], 'Hasta' => $filters['to'],
                    'Tipo de vehículo' => $filters['vehicle_type_id'] ? VehicleType::findOrFail($filters['vehicle_type_id'])->name : 'Todos',
                    'Método de pago' => $filters['payment_method_id'] ? PaymentMethod::findOrFail($filters['payment_method_id'])->name : 'Todos',
                ],
                'generated_at' => $at->format('Y-m-d H:i:s'), 'timezone' => 'America/La_Paz', ...$result,
            ];
        });
    }

    private function payments(array $filters, CarbonImmutable $start, CarbonImmutable $end)
    {
        // All joins are many-to-one: each confirmed payment is counted exactly once,
        // including subscription payments and legacy rows linked to both entities.
        return DB::table('payments as p')->leftJoin('tickets as t', 't.id', '=', 'p.ticket_id')
            ->leftJoin('subscriptions as s', 's.id', '=', 'p.subscription_id')
            ->leftJoin('vehicles as v', 'v.id', '=', DB::raw('COALESCE(t.vehicle_id, s.vehicle_id)'))
            ->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.status', 'paid')->where('p.paid_at', '>=', $this->stored($start))->where('p.paid_at', '<', $this->stored($end))
            ->when($filters['vehicle_type_id'], fn ($q, $id) => $q->where('v.vehicle_type_id', $id))
            ->when($filters['payment_method_id'], fn ($q, $id) => $q->where('p.payment_method_id', $id))
            ->select(['p.id', 'p.paid_at', 'p.amount', 'p.reference', 'p.subscription_id', 'v.plate', 't.number', 'm.name as method', 'u.name as operator']);
    }

    private function income(array $filters, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $daily = $monthly = $methods = $detail = [];
        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $daily[$day->toDateString()] = ['date' => $day->toDateString(), 'transactions' => 0, 'cents' => 0];
            $monthly[$day->format('Y-m')] = ['month' => $day->format('Y-m'), 'transactions' => 0, 'cents' => 0];
        }
        foreach (PaymentMethod::when($filters['payment_method_id'], fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get() as $method) {
            $label = in_array($method->name, ['QR', 'Transferencia / QR']) ? 'QR' : $method->name;
            $methods[$label] = ['method' => $label, 'transactions' => 0, 'cents' => 0];
        }
        $total = $count = 0;
        foreach ($this->payments($filters, $start, $end)->orderBy('p.paid_at')->orderBy('p.id')->cursor() as $payment) {
            $when = $this->local($payment->paid_at);
            $cents = ParkingBilling::cents((string) $payment->amount);
            $label = in_array($payment->method, ['QR', 'Transferencia / QR']) ? 'QR' : $payment->method;
            $daily[$when->toDateString()]['transactions']++;
            $daily[$when->toDateString()]['cents'] += $cents;
            $monthly[$when->format('Y-m')]['transactions']++;
            $monthly[$when->format('Y-m')]['cents'] += $cents;
            $methods[$label]['transactions']++;
            $methods[$label]['cents'] += $cents;
            $total += $cents; $count++;
            $detail[] = ['id' => $payment->id, 'date' => $when->format('Y-m-d H:i:s'), 'plate' => $payment->plate ?? '—',
                'document' => $payment->number ?? ($payment->subscription_id ? 'Abono #'.$payment->subscription_id : 'Pago #'.$payment->id),
                'method' => $label, 'amount' => self::money($cents), 'operator' => $payment->operator, 'reference' => $payment->reference ?? ''];
        }
        $days = (int) $start->diffInDays($end);
        $previousStart = $start->subDays($days);
        $previousCents = $previousCount = 0;
        foreach ($this->payments($filters, $previousStart, $start)->cursor() as $payment) {
            $previousCents += ParkingBilling::cents((string) $payment->amount); $previousCount++;
        }
        $finalize = fn ($rows) => array_values(array_map(function ($row) {
            $row['amount'] = self::money($row['cents']); unset($row['cents']); return $row;
        }, $rows));
        $daily = $finalize($daily); $monthly = $finalize($monthly); $methods = $finalize($methods);
        $money = $this->column('amount', 'Importe (Bs)', 'money');
        $transactions = $this->column('transactions', 'Transacciones', 'number');
        $comparison = [
            ['period' => 'Actual', 'from' => $start->toDateString(), 'to' => $end->subDay()->toDateString(), 'transactions' => $count, 'amount' => self::money($total)],
            ['period' => 'Anterior', 'from' => $previousStart->toDateString(), 'to' => $start->subDay()->toDateString(), 'transactions' => $previousCount, 'amount' => self::money($previousCents)],
        ];

        return [
            'summary' => [$this->metric('Total recaudado (Bs)', self::money($total), 'money'), $this->metric('Transacciones confirmadas', $count),
                $this->metric('Periodo anterior (Bs)', self::money($previousCents), 'money'), $this->metric('Diferencia (Bs)', self::money($total - $previousCents), 'money'),
                $this->metric('Variación (%)', $previousCents ? round(($total - $previousCents) * 100 / $previousCents, 2) : null, 'percent')],
            'tables' => [
                $this->table('daily', 'Ingresos diarios', [$this->column('date', 'Fecha', 'date'), $transactions, $money], $daily),
                $this->table('monthly', 'Ingresos mensuales', [$this->column('month', 'Mes', 'month'), $transactions, $money], $monthly),
                $this->table('methods', 'Métodos de pago', [$this->column('method', 'Método'), $transactions, $money], $methods),
                $this->table('comparison', 'Comparación de periodos', [$this->column('period', 'Periodo'), $this->column('from', 'Desde', 'date'), $this->column('to', 'Hasta', 'date'), $transactions, $money], $comparison),
                $this->table('payments', 'Detalle de pagos confirmados', [$this->column('id', 'Pago #', 'number'), $this->column('date', 'Fecha', 'datetime'), $this->column('plate', 'Placa'), $this->column('document', 'Boleto / abono'), $this->column('method', 'Método'), $money, $this->column('operator', 'Operador'), $this->column('reference', 'Referencia')], $detail),
            ],
            'charts' => [
                $this->chart('Ingresos diarios (Bs)', 'line', array_column($daily, 'date'), [['label' => 'Bs', 'data' => array_column($daily, 'amount')]]),
                $this->chart('Ingresos mensuales (Bs)', 'bar', array_column($monthly, 'month'), [['label' => 'Bs', 'data' => array_column($monthly, 'amount')]]),
                $this->chart('Distribución de pagos (Bs)', 'doughnut', array_column($methods, 'method'), [['label' => 'Bs', 'data' => array_column($methods, 'amount')]]),
            ],
            'notes' => ['Solo pagos confirmados; los anulados quedan excluidos.', 'El periodo anterior contiene el mismo número de días inmediatamente anteriores al inicio. Si su total es cero, la variación porcentual no tiene base de comparación.', 'Los totales mensuales incluyen únicamente los días seleccionados.'],
        ];
    }

    private function tickets(array $filters)
    {
        return DB::table('tickets as t')->join('vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->join('vehicle_types as vt', 'vt.id', '=', 'v.vehicle_type_id')
            ->join('spaces as s', 's.id', '=', 't.space_id')
            ->whereIn('t.status', ['open', 'closed'])
            ->when($filters['vehicle_type_id'], fn ($q, $id) => $q->where('v.vehicle_type_id', $id))
            ->select(['t.id', 't.number', 't.vehicle_id', 't.space_id', 't.entered_at', 't.exited_at', 't.status', 'v.plate', 'vt.name as vehicle_type', 's.code as space']);
    }

    private function vehicles(array $filters, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $ranking = $types = $visits = [];
        foreach ($this->tickets($filters)->where('t.entered_at', '>=', $this->stored($start))->where('t.entered_at', '<', $this->stored($end))->orderBy('t.entered_at')->cursor() as $ticket) {
            $ranking[$ticket->vehicle_id] ??= ['plate' => $ticket->plate, 'vehicle_type' => $ticket->vehicle_type, 'visits' => 0];
            $ranking[$ticket->vehicle_id]['visits']++;
            $types[$ticket->vehicle_type] = ($types[$ticket->vehicle_type] ?? 0) + 1;
            $visits[] = ['number' => $ticket->number, 'plate' => $ticket->plate, 'vehicle_type' => $ticket->vehicle_type,
                'entered_at' => $this->local($ticket->entered_at)->format('Y-m-d H:i:s'), 'exited_at' => $ticket->exited_at ? $this->local($ticket->exited_at)->format('Y-m-d H:i:s') : null, 'space' => $ticket->space];
        }
        $ranking = array_values($ranking);
        usort($ranking, fn ($a, $b) => ($b['visits'] <=> $a['visits']) ?: strcmp($a['plate'], $b['plate']));

        return [
            'summary' => [$this->metric('Ingresos de vehículos', count($visits)), $this->metric('Vehículos distintos', count($ranking)), $this->metric('Placa más frecuente', $ranking[0]['plate'] ?? 'Sin datos', 'text')],
            'tables' => [
                $this->table('ranking', 'Ranking de vehículos', [$this->column('plate', 'Placa'), $this->column('vehicle_type', 'Tipo'), $this->column('visits', 'Visitas', 'number')], $ranking),
                $this->table('visits', 'Detalle de visitas', [$this->column('number', 'Boleto'), $this->column('plate', 'Placa'), $this->column('vehicle_type', 'Tipo'), $this->column('entered_at', 'Entrada', 'datetime'), $this->column('exited_at', 'Salida', 'datetime'), $this->column('space', 'Espacio')], $visits),
            ],
            'charts' => [$this->chart('Ingresos de vehículos por tipo', 'bar', array_keys($types), [['label' => 'Visitas', 'data' => array_values($types)]])],
            'notes' => ['Cada boleto no anulado cuenta como una visita según su fecha de entrada. Se incluyen vehículos y tipos actualmente desactivados para conservar el historial.'],
        ];
    }

    private function occupancy(array $filters, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $at): array
    {
        $spaces = Space::operational()->where('status', '!=', 'maintenance')
            ->when($filters['vehicle_type_id'], fn ($q, $id) => $q->where(fn ($q) => $q->whereNull('vehicle_type_id')->orWhere('vehicle_type_id', $id)))->get(['id', 'status']);
        $allTypes = [...$filters, 'vehicle_type_id' => null];
        $occupiedIds = $this->tickets($allTypes)->where('t.entered_at', '<=', $this->stored($at))
            ->where(fn ($q) => $q->where('t.exited_at', '>', $this->stored($at))->orWhere(fn ($q) => $q->whereNull('t.exited_at')->where('t.status', 'open')))
            ->distinct()->pluck('t.space_id')->all();
        $occupied = $spaces->whereIn('id', $occupiedIds)->count();
        $available = $spaces->whereNotIn('id', $occupiedIds)->where('status', 'available')->count();
        $blocked = $spaces->count() - $occupied - $available;
        $intervals = [];
        $effectiveEnd = $end->min($at);
        foreach ($this->tickets($filters)->where('t.entered_at', '<', $this->stored($effectiveEnd))
            ->where(fn ($q) => $q->where('t.exited_at', '>', $this->stored($start))->orWhere(fn ($q) => $q->whereNull('t.exited_at')->where('t.status', 'open')))
            ->orderBy('t.entered_at')->cursor() as $ticket) {
            $entry = $this->local($ticket->entered_at)->max($start);
            $exit = ($ticket->exited_at ? $this->local($ticket->exited_at) : $at)->min($effectiveEnd);
            if ($exit->gt($entry)) $intervals[$ticket->space_id][] = [$entry->timestamp, $exit->timestamp];
        }
        // Union intervals per space: duplicate legacy tickets must not inflate occupied time.
        $merged = [];
        foreach ($intervals as $id => $ranges) {
            usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
            foreach ($ranges as [$a, $b]) {
                $last = count($merged[$id] ?? []) - 1;
                if ($last >= 0 && $a <= $merged[$id][$last][1]) $merged[$id][$last][1] = max($merged[$id][$last][1], $b);
                else $merged[$id][] = [$a, $b];
            }
        }
        $rows = []; $totalSeconds = 0; $maxPeak = 0;
        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $dayStart = $day->timestamp; $dayEnd = $day->addDay()->min($at)->timestamp;
            $seconds = 0; $used = []; $events = [];
            foreach ($merged as $spaceId => $ranges) foreach ($ranges as [$a, $b]) {
                $a = max($a, $dayStart); $b = min($b, $dayEnd);
                if ($b <= $a) continue;
                $seconds += $b - $a; $used[$spaceId] = true;
                $events[$a] = ($events[$a] ?? 0) + 1; $events[$b] = ($events[$b] ?? 0) - 1;
            }
            ksort($events); $concurrent = $peak = 0;
            foreach ($events as $change) { $concurrent += $change; $peak = max($peak, $concurrent); }
            $totalSeconds += $seconds; $maxPeak = max($maxPeak, $peak);
            $rows[] = ['date' => $day->toDateString(), 'spaces_used' => count($used), 'peak' => $peak,
                'occupied_hours' => round($seconds / 3600, 4), 'average' => $dayEnd > $dayStart ? round($seconds / ($dayEnd - $dayStart), 4) : 0];
        }

        return [
            'summary' => [$this->metric('Capacidad operativa actual', $spaces->count()), $this->metric('Disponibles ahora', $available), $this->metric('Ocupados ahora (boletos)', $occupied),
                $this->metric('Ocupación actual (%)', $spaces->count() ? round($occupied * 100 / $spaces->count(), 2) : 0, 'percent'),
                $this->metric('Bloqueados sin boleto activo', $blocked), $this->metric('Horas-espacio del periodo', round($totalSeconds / 3600, 4)), $this->metric('Máximo simultáneo del periodo', $maxPeak)],
            'tables' => [$this->table('history', 'Ocupación histórica diaria', [$this->column('date', 'Fecha', 'date'), $this->column('spaces_used', 'Espacios utilizados', 'number'), $this->column('peak', 'Máximo simultáneo', 'number'), $this->column('occupied_hours', 'Horas-espacio', 'number'), $this->column('average', 'Ocupación media (espacios)', 'number')], $rows)],
            'charts' => [$this->chart('Evolución de ocupación (espacios)', 'line', array_column($rows, 'date'), [['label' => 'Media', 'data' => array_column($rows, 'average')], ['label' => 'Máximo simultáneo', 'data' => array_column($rows, 'peak')]])],
            'notes' => ['La ocupación actual se calcula al emitir el reporte; las fechas filtran el historial.',
                'La capacidad actual excluye espacios, pisos y sectores desactivados y espacios en mantenimiento. Con filtro de tipo incluye espacios compatibles, ocupados por cualquier vehículo.',
                'El historial mide horas-espacio, máximo simultáneo y ocupación media; incluye estancias que cruzan medianoche y espacios hoy desactivados. Hoy se mide hasta la hora de emisión.',
                'No se calcula un porcentaje histórico de capacidad: el sistema no registra los cambios pasados de habilitación o mantenimiento.'],
        ];
    }
}
