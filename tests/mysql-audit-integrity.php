<?php

// Read-only inspection plus isolated regression requests; no migrations or seeders.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{Client, Floor, Rate, Sector, Space, Subscription, User, Vehicle, VehicleType};
use Illuminate\Support\Facades\{Auth, DB, Schema};

if (DB::connection()->getDriverName() !== 'mysql') throw new RuntimeException('MySQL required.');
$checks = 0;
function auditCheck(bool $condition, string $description): void
{
    global $checks;
    if (! $condition) throw new RuntimeException($description);
    $checks++;
}
$tables = ['users', 'roles', 'permissions', 'role_permission', 'model_has_roles', 'model_has_permissions',
    'clients', 'subscriptions', 'payment_methods', 'vehicles', 'vehicle_types', 'floors', 'sectors', 'spaces', 'rates', 'tickets', 'payments', 'migrations'];
$fingerprint = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => hash('sha256', DB::table($table)->get()->map(fn ($row) => json_encode($row))->sort()->values()->toJson())])->all();
$before = $fingerprint();
foreach ($tables as $table) {
    $engine = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    auditCheck($engine?->engine === 'InnoDB', $table.' is not transactional');
    foreach (Schema::getForeignKeys($table) as $foreign) {
        $query = DB::table($table.' as child')->leftJoin($foreign['foreign_table'].' as parent', function ($join) use ($foreign) {
            foreach ($foreign['columns'] as $index => $column) $join->on('child.'.$column, '=', 'parent.'.$foreign['foreign_columns'][$index]);
        });
        foreach ($foreign['columns'] as $column) $query->whereNotNull('child.'.$column);
        auditCheck(! $query->whereNull('parent.'.$foreign['foreign_columns'][0])->exists(), $table.' has an orphan foreign key');
    }
}
foreach (['space_id', 'vehicle_id'] as $column) auditCheck(! DB::table('tickets')->where('status', 'open')->groupBy($column)->havingRaw('COUNT(*) > 1')->exists(), 'Duplicate open ticket: '.$column);
auditCheck(! DB::table('payments')->where('status', 'paid')->whereNotNull('ticket_id')->groupBy('ticket_id')->havingRaw('COUNT(*) > 1')->exists(), 'Double confirmed payment');
auditCheck(! DB::table('tickets as t')->join('spaces as s', 's.id', '=', 't.space_id')->where('t.status', 'open')->where('s.status', '!=', 'occupied')->exists(), 'Open ticket without occupied space');
auditCheck(! DB::table('spaces as s')->where('status', 'occupied')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('tickets as t')->whereColumn('t.space_id', 's.id')->where('t.status', 'open'))->exists(), 'Occupied space without open ticket');
auditCheck(! DB::table('spaces as s')->join('sectors as c', 'c.id', '=', 's.sector_id')->whereColumn('s.floor_id', '!=', 'c.floor_id')->exists(), 'Space/sector floor mismatch');
auditCheck(! DB::table('tickets')->whereNotNull('exited_at')->whereColumn('exited_at', '<', 'entered_at')->exists(), 'Exit precedes entry');
auditCheck(! DB::table('payments as p')->join('tickets as t', 't.id', '=', 'p.ticket_id')->where('p.status', 'paid')->where(fn ($q) => $q->where('t.status', '!=', 'closed')->orWhereColumn('p.amount', '!=', 't.total'))->exists(), 'Payment/ticket mismatch');
foreach (User::all() as $user) auditCheck(password_get_info($user->getAuthPassword())['algoName'] !== 'unknown', 'A user password is not hashed');
foreach (['rates', 'subscriptions', 'payments'] as $table) {
    $column = DB::selectOne('SELECT DATA_TYPE AS type, NUMERIC_SCALE AS scale FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, 'amount']);
    auditCheck($column?->type === 'decimal' && (int) $column->scale === 2, 'Invalid money storage: '.$table);
}
echo 'MySQL existing records: foreign keys, InnoDB, decimal amounts, password hashes, occupancy and payment coherence verified.'.PHP_EOL;

$app['env'] = 'testing'; // This HTTP harness uses standard feature-test behavior; browser audit tests CSRF separately.
config(['session.driver' => 'array', 'cache.default' => 'array']);
app(Spatie\Permission\PermissionRegistrar::class)->initializeCache();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$admin = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('slug', 'administrador'))->firstOrFail();
function auditRequest(string $method, string $url, array $data, int $expected): array
{
    global $kernel, $admin;
    Auth::setUser($admin);
    $response = $kernel->handle(Illuminate\Http\Request::create($url, $method, $data, [], [], ['HTTP_ACCEPT' => 'application/json']));
    auditCheck($response->getStatusCode() === $expected, $method.' '.$url.' returned '.$response->getStatusCode().' instead of '.$expected);

    return json_decode($response->getContent(), true);
}
$tag = 'AUD-'.strtoupper(bin2hex(random_bytes(4)));
DB::beginTransaction();
try {
    $client = Client::create(['name' => $tag, 'document' => $tag]);
    $type = VehicleType::create(['name' => $tag]);
    $vehicle = Vehicle::create(['plate' => $tag, 'client_id' => $client->id, 'vehicle_type_id' => $type->id]);
    $floor = Floor::create(['name' => $tag]);
    $sector = Sector::create(['name' => $tag, 'floor_id' => $floor->id]);
    $space = Space::create(['code' => $tag, 'floor_id' => $floor->id, 'sector_id' => $sector->id, 'status' => 'available']);
    $rate = Rate::create(['name' => $tag, 'vehicle_type_id' => $type->id, 'unit' => 'month', 'amount' => '100.00']);
    $payload = ['client_id' => $client->id, 'vehicle_id' => $vehicle->id, 'space_id' => $space->id, 'rate_id' => $rate->id,
        'starts_on' => today()->toDateString(), 'ends_on' => today()->addMonth()->toDateString(), 'amount' => '100.00'];
    foreach ([$floor, $sector] as $parent) {
        $parent->update(['is_active' => false]);
        auditRequest('POST', '/abonados', $payload, 422);
        $parent->update(['is_active' => true]);
    }
    $subscription = auditRequest('POST', '/abonados', $payload, 201)['data'];
    auditRequest('POST', '/abonados', $payload, 422);
    auditRequest('PATCH', '/abonados/'.$subscription['id'].'/estado', ['is_active' => false], 200);
    $floor->update(['is_active' => false]);
    auditRequest('PATCH', '/abonados/'.$subscription['id'].'/estado', ['is_active' => true], 422);
    $floor->update(['is_active' => true]);
    auditRequest('PATCH', '/abonados/'.$subscription['id'].'/estado', ['is_active' => true], 200);
    $other = Floor::create(['name' => $tag.'-2']);
    auditRequest('PUT', '/sectores/'.$sector->id, ['name' => $sector->name, 'floor_id' => $other->id], 422);
    $sector->update(['floor_id' => $other->id]);
    auditCheck(! Space::operational()->whereKey($space->id)->exists(), 'Inconsistent legacy location is operational');
    $options = auditRequest('GET', '/entradas/opciones', ['plate' => $tag], 200);
    auditCheck(! in_array($space->id, array_column($options['spaces'], 'id')), 'Inconsistent location was offered');
} finally {
    while (DB::transactionLevel() > 0) DB::rollBack();
    auditCheck($fingerprint() === $before, 'Existing records changed during the audit');
}
echo 'MySQL audit: '.$checks.' checks passed; temporary records reverted and existing records unchanged.'.PHP_EOL;
