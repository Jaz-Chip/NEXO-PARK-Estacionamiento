<?php

namespace App\Http\Controllers;

use App\Models\{Payment, PaymentMethod, Rate, Space, Ticket, Vehicle, VehicleType};
use App\Services\{ParkingBilling, ParkingService};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OperationsController extends Controller
{
    public function __construct(private ParkingService $parking, private ParkingBilling $billing) {}

    public function index(Request $request)
    {
        $module = explode('.', $request->route()->getName())[1];

        return view('operations.index', [
            'module' => $module, 'title' => config('permissions.resources.'.$module.'.label'),
            'config' => [
                'module' => $module, 'dataUrl' => route('operations.'.$module.'.data'),
                'entryUrl' => route('operations.entradas.store'),
                'optionsUrl' => route('operations.entradas.options'),
                'vehicleUrl' => route('catalog.vehiculos.store'),
                'lookupUrl' => route('operations.salidas.lookup'),
                'exitBase' => url('/salidas'), 'ticketBase' => url('/tickets'), 'cashBase' => url('/caja'),
                'canEnter' => $request->user()->can('entradas.create'),
                'canCreateVehicle' => $request->user()->can('vehiculos.create'),
                'canQuote' => $request->user()->can('salidas.view'),
                'canCharge' => $request->user()->can('salidas.execute') && $request->user()->can('caja.charge'),
                'canPrintTicket' => $request->user()->can('tickets.reprint'),
                'canViewCash' => $request->user()->can('caja.view'),
            ],
        ]);
    }

    private function stats(Request $request): array
    {
        $stats = [
            'available' => Space::operational()->where('status', 'available')->whereDoesntHave('tickets', fn ($q) => $q->where('status', 'open'))->count(),
            'occupied' => Space::operational()->where('status', 'occupied')->count(),
        ];
        if ($request->user()->can('caja.view')) {
            $stats['todayIncome'] = Payment::where('status', 'paid')->whereBetween('paid_at', [today(), today()->endOfDay()])->sum('amount');
        }

        return $stats;
    }

    private function ticketData(Ticket $ticket): array
    {
        $ticket->loadMissing(['vehicle.vehicleType', 'space.floor', 'space.sector', 'rate', 'openedBy', 'closedBy']);

        return [
            'id' => $ticket->id, 'number' => $ticket->number, 'plate' => $ticket->vehicle->plate,
            'vehicle_type' => $ticket->vehicle->vehicleType->name,
            'space' => implode(' / ', array_filter([$ticket->space->floor?->name, $ticket->space->sector?->name, $ticket->space->code])),
            'entered_at' => $ticket->entered_at->toIso8601String(), 'exited_at' => $ticket->exited_at?->toIso8601String(),
            'status' => $ticket->status, 'rate' => $ticket->rate->name, 'rate_amount' => $ticket->rate_amount,
            'unit' => $ticket->rate->unit, 'fraction_minutes' => $ticket->rate->fraction_minutes,
            'total' => $ticket->total, 'opened_by' => $ticket->openedBy->name, 'closed_by' => $ticket->closedBy?->name,
        ];
    }

    public function tickets(Request $request)
    {
        $history = $request->routeIs('operations.tickets.data');
        $data = Ticket::with(['vehicle.vehicleType', 'space.floor', 'space.sector', 'rate', 'openedBy', 'closedBy'])
            ->when(! $history, fn ($q) => $q->where('status', 'open'))
            ->orderByDesc('id')->get()->map(fn ($ticket) => $this->ticketData($ticket));

        return response()->json(['data' => $data, 'stats' => $this->stats($request)]);
    }

    public function options(Request $request)
    {
        $data = $request->validate(['plate' => ['nullable', 'string', 'max:20']]);
        $vehicle = ! empty($data['plate']) ? Vehicle::with('vehicleType')->where('plate', mb_strtoupper(trim($data['plate'])))->first() : null;
        $spaces = [];
        $rates = [];
        if ($vehicle && $vehicle->is_active && $vehicle->vehicleType->is_active) {
            $spaces = Space::operational()->with(['floor', 'sector'])->where('status', 'available')
                ->where(fn ($q) => $q->whereNull('vehicle_type_id')->orWhere('vehicle_type_id', $vehicle->vehicle_type_id))
                ->whereDoesntHave('tickets', fn ($q) => $q->where('status', 'open'))
                ->whereDoesntHave('subscriptions', fn ($q) => $q->where('is_active', true)->where('vehicle_id', '!=', $vehicle->id)
                    ->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today()))
                ->orderBy('code')->get()->map(fn ($s) => ['id' => $s->id, 'name' => implode(' / ', array_filter([$s->floor?->name, $s->sector?->name, $s->code]))]);
            $rates = Rate::where('is_active', true)->where('vehicle_type_id', $vehicle->vehicle_type_id)->orderBy('name')->get(['id', 'name', 'unit', 'fraction_minutes', 'amount']);
        }

        return response()->json([
            'vehicle' => $vehicle ? ['id' => $vehicle->id, 'plate' => $vehicle->plate, 'type' => $vehicle->vehicleType->name, 'is_active' => $vehicle->is_active] : null,
            'types' => $request->user()->can('vehiculos.create') ? VehicleType::where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
            'spaces' => $spaces, 'rates' => $rates,
        ]);
    }

    public function enter(Request $request)
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'space_id' => ['required', 'integer', 'exists:spaces,id'],
            'rate_id' => ['required', 'integer', 'exists:rates,id'],
        ]);
        $ticket = $this->parking->enter($data, $request->user());

        return response()->json(['message' => 'Entrada registrada.', 'data' => $this->ticketData($ticket),
            'print_html' => $this->ticketHtml($ticket)], 201);
    }

    public function lookup(Request $request)
    {
        $data = $request->validate(['search' => ['required', 'string', 'max:80']]);
        $search = mb_strtoupper(trim($data['search']));
        $ticket = Ticket::where('status', 'open')->where(fn ($q) => $q->where('number', $search)
            ->orWhereHas('vehicle', fn ($v) => $v->where('plate', $search)))->first();
        if (! $ticket) {
            throw \Illuminate\Validation\ValidationException::withMessages(['search' => 'No se encontró un boleto abierto con ese código o placa.']);
        }

        return $this->quote($ticket);
    }

    public function quote(Ticket $ticket)
    {
        if ($ticket->status !== 'open') {
            throw \Illuminate\Validation\ValidationException::withMessages(['ticket' => 'Este boleto ya fue cerrado o anulado.']);
        }

        return response()->json(['data' => $this->ticketData($ticket), 'quote' => $this->billing->quote($ticket, now()->startOfSecond()),
            'methods' => [
                'cash' => PaymentMethod::where('is_active', true)->where('name', 'Efectivo')->exists(),
                'qr' => PaymentMethod::where('is_active', true)->whereIn('name', ['QR', 'Transferencia / QR'])->exists(),
            ],
        ]);
    }

    public function checkout(Request $request, Ticket $ticket)
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(['cash', 'qr'])],
            'qr_confirmed' => ['required_if:method,qr', 'accepted_if:method,qr'],
            'reference' => ['nullable', 'string', 'max:255'],
            'expected_total' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
        ]);
        $payment = $this->parking->checkout($ticket->id, $data, $request->user());

        return response()->json(['message' => 'Pago registrado y espacio liberado.', 'payment_id' => $payment->id,
            'total' => $payment->amount, 'print_html' => $this->receiptHtml($payment)]);
    }

    public function payments(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'method' => ['nullable', Rule::in(['cash', 'qr'])], 'search' => ['nullable', 'string', 'max:80'],
        ]);
        $query = Payment::with(['ticket.vehicle', 'subscription.vehicle', 'paymentMethod', 'user'])
            ->when($data['from'] ?? null, fn ($q, $d) => $q->where('paid_at', '>=', $d.' 00:00:00'))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->where('paid_at', '<=', $d.' 23:59:59'))
            ->when($data['method'] ?? null, fn ($q, $m) => $q->whereHas('paymentMethod', fn ($mth) => $mth->whereIn('name', $m === 'cash' ? ['Efectivo'] : ['QR', 'Transferencia / QR'])))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.$s.'%')
                ->orWhereHas('ticket', fn ($t) => $t->where('number', 'like', '%'.$s.'%')->orWhereHas('vehicle', fn ($v) => $v->where('plate', 'like', '%'.$s.'%')))));
        $total = (clone $query)->where('status', 'paid')->sum('amount');
        $rows = $query->orderByDesc('id')->get()->map(fn ($p) => [
            'id' => $p->id, 'number' => $p->ticket?->number ?? 'Abono #'.$p->subscription_id,
            'plate' => $p->ticket?->vehicle?->plate ?? $p->subscription?->vehicle?->plate ?? '—',
            'amount' => $p->amount, 'method' => $p->paymentMethod->name, 'operator' => $p->user->name,
            'paid_at' => $p->paid_at->toIso8601String(), 'reference' => $p->reference, 'status' => $p->status,
        ]);

        return response()->json(['data' => $rows, 'filtered_total' => $total, 'stats' => $this->stats($request)]);
    }

    private function ticketHtml(Ticket $ticket): string
    {
        return view('operations.print', ['ticket' => $this->ticketData($ticket), 'payment' => null])->render();
    }

    private function receiptHtml(Payment $payment): string
    {
        $payment->loadMissing(['ticket', 'paymentMethod', 'user', 'subscription.vehicle']);

        return view('operations.print', ['ticket' => $payment->ticket ? $this->ticketData($payment->ticket) : null, 'payment' => $payment])->render();
    }

    public function printTicket(Ticket $ticket)
    {
        return response()->json(['print_html' => $this->ticketHtml($ticket)]);
    }

    public function printReceipt(Payment $payment)
    {
        return response()->json(['print_html' => $this->receiptHtml($payment)]);
    }
}
