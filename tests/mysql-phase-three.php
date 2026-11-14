<?php

// Real MySQL integration check. No migrations/seeders, no retained test records.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{Floor, Payment, Rate, Sector, Space, Ticket, User, VehicleType};
use Illuminate\Support\Facades\{Auth, DB};

if (DB::connection()->getDriverName() !== 'mysql') {
    throw new RuntimeException('This verification requires the configured MySQL connection.');
}
$app['env'] = 'testing'; // CSRF bypass only in this CLI harness, like Laravel HTTP tests.
config(['session.driver' => 'array', 'cache.default' => 'array']);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$checks = 0;
function verify(bool $condition, string $message): void
{
    global $checks;
    if (! $condition) throw new RuntimeException($message);
    $checks++;
}
function ajax(string $method, string $url, array $data = [], int $expected = 200): array
{
    global $kernel;
    $request = Illuminate\Http\Request::create($url, $method, $data, [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $response = $kernel->handle($request);
    verify($response->getStatusCode() === $expected, "$method $url: expected $expected, received ".$response->getStatusCode());

    return json_decode($response->getContent(), true) ?? [];
}

$admin = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('slug', 'administrador'))->firstOrFail();
$tables = ['users', 'roles', 'permissions', 'role_permission', 'model_has_roles', 'model_has_permissions', 'vehicles', 'vehicle_types', 'floors', 'sectors', 'spaces', 'rates', 'tickets', 'payments'];
$countsBefore = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
// Compare privately; never print credentials or hashes.
$usersBefore = DB::table('users')->orderBy('id')->get()->toJson();
$tag = 'P3-'.strtoupper(bin2hex(random_bytes(4)));
$failed = false;
DB::beginTransaction();
try {
    Auth::setUser($admin);
    $type = VehicleType::create(['name' => $tag, 'is_active' => true]);
    $floor = Floor::create(['name' => $tag, 'is_active' => true]);
    $sector = Sector::create(['name' => $tag, 'floor_id' => $floor->id, 'is_active' => true]);
    $space = Space::create(['code' => $tag, 'floor_id' => $floor->id, 'sector_id' => $sector->id, 'vehicle_type_id' => $type->id, 'status' => 'available', 'is_active' => true]);
    $rate = Rate::create(['name' => $tag, 'vehicle_type_id' => $type->id, 'unit' => 'fraction', 'fraction_minutes' => 15, 'amount' => '0.10', 'is_active' => true]);
    $vehicle = ajax('POST', '/vehiculos', ['plate' => $tag, 'vehicle_type_id' => $type->id], 201)['data'];
    $payload = ['vehicle_id' => $vehicle['id'], 'space_id' => $space->id, 'rate_id' => $rate->id];
    $options = ajax('GET', '/entradas/opciones', ['plate' => strtolower($tag)]);
    verify(in_array($space->id, array_column($options['spaces'], 'id')), 'Available space missing');
    $ticket = ajax('POST', '/entradas', $payload, 201)['data'];
    verify($space->fresh()->status === 'occupied', 'Space not occupied');
    ajax('POST', '/entradas', $payload, 422);
    $second = ajax('POST', '/vehiculos', ['plate' => $tag.'B', 'vehicle_type_id' => $type->id], 201)['data'];
    ajax('POST', '/entradas', [...$payload, 'vehicle_id' => $second['id']], 422);

    // A separate physical connection must be blocked by both parent locks.
    config(['database.connections.phase_three_probe' => config('database.connections.'.config('database.default'))]);
    $probe = DB::connection('phase_three_probe');
    $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');
    foreach (['spaces' => $space->id, 'tickets' => $ticket['id']] as $table => $id) {
        $blocked = false;
        try {
            $probe->transaction(fn () => $probe->table($table)->where('id', $id)->lockForUpdate()->first());
        } catch (Illuminate\Database\QueryException $error) {
            $blocked = (int) ($error->errorInfo[1] ?? 0) === 1205;
            if (! $blocked) throw $error;
        }
        verify($blocked, "A second MySQL connection acquired the $table lock unexpectedly");
    }
    DB::purge('phase_three_probe');
    echo "MySQL: space and ticket locks block concurrent connections.\n";

    Ticket::findOrFail($ticket['id'])->update(['entered_at' => now()->subMinutes(31)]);
    $quote = ajax('GET', '/salidas/buscar', ['search' => $tag]);
    verify($quote['quote']['total'] === '0.30', 'Incorrect decimal tariff calculation');
    ajax('POST', '/salidas/'.$ticket['id'].'/cobrar', ['method' => 'cash', 'expected_total' => '0.10'], 422);
    verify($space->fresh()->status === 'occupied', 'Failed payment released space');
    $result = ajax('POST', '/salidas/'.$ticket['id'].'/cobrar', ['method' => 'cash', 'expected_total' => '0.30']);
    verify($result['total'] === '0.30', 'Wrong amount stored');
    verify($space->fresh()->status === 'available', 'Space not released');
    verify(Ticket::findOrFail($ticket['id'])->status === 'closed', 'Ticket not closed');
    verify(Payment::where('ticket_id', $ticket['id'])->count() === 1, 'Expected one payment');
    ajax('POST', '/salidas/'.$ticket['id'].'/cobrar', ['method' => 'cash', 'expected_total' => '0.30'], 422);
    ajax('GET', '/caja/'.$result['payment_id'].'/comprobante');
    ajax('GET', '/tickets/'.$ticket['id'].'/imprimir');
    $history = ajax('GET', '/caja/datos', ['search' => $tag, 'method' => 'cash', 'from' => today()->toDateString(), 'to' => today()->toDateString()]);
    verify(count($history['data']) === 1, 'Payment filters failed');
    $qr = ajax('POST', '/entradas', $payload, 201)['data'];
    ajax('POST', '/salidas/'.$qr['id'].'/cobrar', ['method' => 'qr', 'expected_total' => '0.10'], 422);
    ajax('POST', '/salidas/'.$qr['id'].'/cobrar', ['method' => 'qr', 'expected_total' => '0.10', 'qr_confirmed' => 1, 'reference' => $tag]);
    verify(Payment::whereIn('ticket_id', [$ticket['id'], $qr['id']])->count() === 2, 'Wrong payment count');

    foreach (['operador', 'supervisor'] as $slug) {
        $user = User::whereHas('roles', fn ($q) => $q->where('slug', $slug))->where('is_active', true)->firstOrFail();
        // Temporary assignments are inside the outer rollback transaction.
        $user->roles->first()->syncPermissions(['entradas.view', 'salidas.view', 'caja.view']);
        Auth::setUser($user->fresh());
        ajax('GET', '/entradas/datos');
        ajax('GET', '/caja/datos');
        ajax('POST', '/entradas', $payload, 403);
        ajax('POST', '/salidas/'.$qr['id'].'/cobrar', ['method' => 'cash', 'expected_total' => '0.10'], 403);
    }
    Auth::setUser($admin);
    foreach (['entradas', 'salidas', 'tickets', 'caja'] as $module) {
        $page = $kernel->handle(Illuminate\Http\Request::create('/'.$module));
        verify($page->getStatusCode() === 200, "$module page failed");
    }
    echo "MySQL: entry, decimal rates, cash, manual QR, release, duplicates and Spatie checks passed.\n";
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, $error->getMessage().PHP_EOL);
} finally {
    DB::rollBack();
    DB::purge('phase_three_probe');
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
}
$countsAfter = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
verify($countsBefore === $countsAfter, 'Table counts changed after rollback');
verify($usersBefore === DB::table('users')->orderBy('id')->get()->toJson(), 'User records changed');
echo "MySQL: $checks checks. Transaction rolled back; existing users and table counts preserved.\n";
exit($failed ? 1 : 0);
