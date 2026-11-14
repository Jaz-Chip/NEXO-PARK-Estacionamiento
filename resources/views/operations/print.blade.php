<article class="parking-document">
    <h2 class="text-center">NEXO PARK</h2>
    <h3 class="text-center">{{ $payment ? 'Comprobante de pago #' . $payment->id : 'Boleto de estacionamiento' }}</h3>

    @if($ticket)
        <p class="text-center fw-bold">{{ $ticket['number'] }}</p>
        <dl>
            <dt>Placa</dt>
            <dd>{{ $ticket['plate'] }} · {{ $ticket['vehicle_type'] }}</dd>

            <dt>Espacio</dt>
            <dd>{{ $ticket['space'] }}</dd>

            <dt>Entrada</dt>
            <dd>{{ \Carbon\Carbon::parse($ticket['entered_at'])->timezone(config('app.timezone'))->format('d/m/Y H:i:s') }}</dd>

            <dt>Tarifa al ingresar</dt>
            <dd>
                {{ $ticket['rate_amount'] }} Bs / {{ ['minute' => 'minuto', 'hour' => 'hora', 'day' => 'día', 'month' => 'mes', 'fraction' => 'fracción'][$ticket['unit']] ?? $ticket['unit'] }}
                {{ $ticket['unit'] === 'fraction' ? ' de ' . $ticket['fraction_minutes'] . ' minutos' : '' }}
            </dd>

            <dt>Operador de entrada</dt>
            <dd>{{ $ticket['opened_by'] }}</dd>

            @if($ticket['exited_at'])
                <dt>Salida</dt>
                <dd>{{ \Carbon\Carbon::parse($ticket['exited_at'])->timezone(config('app.timezone'))->format('d/m/Y H:i:s') }}</dd>
            @endif
        </dl>
    @else
        <p>Abono #{{ $payment->subscription_id }} · {{ $payment->subscription?->vehicle?->plate }}</p>
    @endif

    @if($payment)
        <hr>
        <dl>
            <dt>Importe</dt>
            <dd class="fs-2 fw-bold">{{ $payment->amount }} Bs</dd>

            <dt>Método</dt>
            <dd>{{ $payment->paymentMethod->name }}</dd>

            <dt>Fecha del pago</dt>
            <dd>{{ $payment->paid_at->format('d/m/Y H:i:s') }}</dd>

            <dt>Operador de cobro</dt>
            <dd>{{ $payment->user->name }}</dd>

            @if($payment->reference)
                <dt>Referencia</dt>
                <dd>{{ $payment->reference }}</dd>
            @endif

            <dt>Estado</dt>
            <dd>{{ $payment->status === 'paid' ? 'Pagado' : 'Anulado' }}</dd>
        </dl>
        <p class="small text-secondary">Comprobante interno de pago. No constituye factura fiscal.</p>
    @else
        <p class="small">Conserva este boleto. Se cobra por unidad iniciada, con un mínimo de una unidad.</p>
    @endif
</article>