<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Space;
use App\Models\Vehicle;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $activeSpaces = Space::operational();

        return view('dashboard', [
            'vehicles' => Vehicle::count(),
            'available' => (clone $activeSpaces)->where('status', 'available')->whereDoesntHave('tickets', fn ($q) => $q->where('status', 'open'))->count(),
            'occupied' => (clone $activeSpaces)->where('status', 'occupied')->count(),
            'income' => Payment::where('status', 'paid')->sum('amount'),
            'todayIncome' => Payment::where('status', 'paid')->where('paid_at', '>=', today())->where('paid_at', '<', today()->addDay())->sum('amount'),
        ]);
    }
}
