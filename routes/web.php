<?php

use App\Http\Controllers\Admin\UserStatusController;
use App\Http\Controllers\Admin\RolePermissionController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Catalog\ClientController;
use App\Http\Controllers\Catalog\FloorController;
use App\Http\Controllers\Catalog\RateController;
use App\Http\Controllers\Catalog\SectorController;
use App\Http\Controllers\Catalog\SpaceController;
use App\Http\Controllers\Catalog\SubscriptionController;
use App\Http\Controllers\Catalog\VehicleController;
use App\Http\Controllers\Catalog\VehicleTypeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
});
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');
Route::middleware(['auth', 'active'])->group(function () {
    Route::view('/sin-acceso', 'auth.no-access')->name('access.none');
    Route::get('/dashboard', DashboardController::class)->middleware('can:dashboard.view')->name('dashboard');
    Route::get('/reportes', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reportes/{report}/datos', [ReportController::class, 'data'])->whereIn('report', ['ingresos', 'vehiculos', 'ocupacion'])->name('reports.data');
    foreach (['pdf' => 'pdf', 'xlsx' => 'excel'] as $format => $permission) {
        Route::get('/reportes/{report}/exportar/'.$format, [ReportController::class, 'export'])
            ->whereIn('report', ['ingresos', 'vehiculos', 'ocupacion'])->defaults('format', $format)
            ->middleware('can:exportaciones.'.$permission)->name('reports.export.'.$format);
    }
    foreach (['entradas', 'salidas', 'tickets', 'caja'] as $module) {
        Route::get('/'.$module, [OperationsController::class, 'index'])->middleware('can:'.$module.'.view')->name('operations.'.$module.'.index');
        Route::get('/'.$module.'/datos', [OperationsController::class, $module === 'caja' ? 'payments' : 'tickets'])
            ->middleware('can:'.$module.'.view')->name('operations.'.$module.'.data');
    }
    Route::get('/entradas/opciones', [OperationsController::class, 'options'])->middleware('can:entradas.create')->name('operations.entradas.options');
    Route::post('/entradas', [OperationsController::class, 'enter'])->middleware('can:entradas.create')->name('operations.entradas.store');
    Route::get('/salidas/buscar', [OperationsController::class, 'lookup'])->middleware('can:salidas.view')->name('operations.salidas.lookup');
    Route::get('/salidas/{ticket}/cotizacion', [OperationsController::class, 'quote'])->whereNumber('ticket')->middleware('can:salidas.view')->name('operations.salidas.quote');
    Route::post('/salidas/{ticket}/cobrar', [OperationsController::class, 'checkout'])->whereNumber('ticket')
        ->middleware(['can:salidas.execute', 'can:caja.charge'])->name('operations.salidas.checkout');
    Route::get('/tickets/{ticket}/imprimir', [OperationsController::class, 'printTicket'])->whereNumber('ticket')->middleware('can:tickets.reprint')->name('operations.tickets.print');
    Route::get('/caja/{payment}/comprobante', [OperationsController::class, 'printReceipt'])->whereNumber('payment')->middleware('can:caja.view')->name('operations.caja.receipt');
    foreach ([
        'clientes' => ClientController::class,
        'abonados' => SubscriptionController::class,
        'vehiculos' => VehicleController::class,
        'tipos-vehiculo' => VehicleTypeController::class,
        'pisos' => FloorController::class,
        'sectores' => SectorController::class,
        'espacios' => SpaceController::class,
        'tarifas' => RateController::class,
    ] as $slug => $controller) {
        Route::get("/$slug", [$controller, 'index'])->middleware("can:$slug.view")->name("catalog.$slug.index");
        Route::get("/$slug/datos", [$controller, 'data'])->middleware("can:$slug.view")->name("catalog.$slug.data");
        Route::post("/$slug", [$controller, 'store'])->middleware("can:$slug.create")->name("catalog.$slug.store");
        Route::put("/$slug/{id}", [$controller, 'update'])->middleware("can:$slug.update")->whereNumber('id')->name("catalog.$slug.update");
        Route::patch("/$slug/{id}/estado", [$controller, 'status'])->middleware("can:$slug.status")->whereNumber('id')->name("catalog.$slug.status");
    }
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/usuarios', [UserStatusController::class, 'index'])->middleware('can:usuarios.view')->name('users.index');
        Route::get('/usuarios/datos', [UserStatusController::class, 'data'])->middleware('can:usuarios.view')->name('users.data');
        Route::post('/usuarios', [UserStatusController::class, 'store'])->middleware(['can:usuarios.view', 'can:roles.manage'])->name('users.store');
        Route::put('/usuarios/{user}', [UserStatusController::class, 'edit'])->whereNumber('user')->middleware(['can:usuarios.view', 'can:roles.manage'])->name('users.update');
        Route::patch('/usuarios/{user}/estado', [UserStatusController::class, 'update'])->middleware('can:usuarios.status')->name('users.status');
        Route::get('/roles', [RolePermissionController::class, 'index'])->middleware('can:roles.view')->name('roles.index');
        Route::post('/roles', [RolePermissionController::class, 'store'])->middleware('can:roles.manage')->name('roles.store');
        Route::get('/roles/{role}/permisos', [RolePermissionController::class, 'show'])->whereNumber('role')->middleware('can:roles.view')->name('roles.permissions.show');
        Route::put('/roles/permisos', [RolePermissionController::class, 'update'])->middleware('can:roles.manage')->name('roles.permissions.update');
    });
});
