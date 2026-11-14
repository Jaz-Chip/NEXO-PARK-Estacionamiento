<?php

namespace App\Services;

use App\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class ParkingBilling
{
    public static function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function quote(Ticket $ticket, CarbonInterface $at): array
    {
        $seconds = max(0, $at->getTimestamp() - $ticket->entered_at->getTimestamp());
        $unit = $ticket->rate->unit;
        if ($unit === 'month') {
            // Calendar months anchored to entry, including month-end dates.
            $units = 1;
            while ($ticket->entered_at->copy()->addMonthsNoOverflow($units)->lt($at)) {
                $units++;
            }
        } else {
            $period = match ($unit) {
                'minute' => 60,
                'hour' => 3600,
                'day' => 86400,
                'fraction' => (int) $ticket->rate->fraction_minutes * 60,
                default => 0,
            };
            if ($period <= 0) {
                throw ValidationException::withMessages(['rate_id' => 'La tarifa no tiene una unidad de cobro válida.']);
            }
            $units = max(1, intdiv($seconds + $period - 1, $period));
        }
        $cents = self::cents($ticket->rate_amount) * $units;
        if ($cents > 999999999999) {
            throw ValidationException::withMessages(['total' => 'El importe excede el límite admitido.']);
        }

        return [
            'total' => self::decimal($cents), 'units' => $units, 'unit' => $unit,
            'fraction_minutes' => $ticket->rate->fraction_minutes,
            'rate_amount' => $ticket->rate_amount, 'duration_seconds' => $seconds,
            'calculated_at' => $at->toIso8601String(),
        ];
    }
}
