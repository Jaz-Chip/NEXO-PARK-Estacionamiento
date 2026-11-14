<?php

// One long-lived CLI process serves browser requests through Laravel while a
// single outer MySQL transaction keeps every browser-created record temporary.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{Floor, Rate, Sector, Space, User, VehicleType};
use Illuminate\Support\Facades\{Auth, DB};

function sendLine(array $data): void
{
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    flush();
}

if (DB::connection()->getDriverName() !== 'mysql') {
    sendLine(['type' => 'error', 'message' => 'The configured DB is not MySQL.']);
    exit(1);
}
$app['env'] = 'testing'; // Only this local CLI HTTP harness bypasses browser CSRF.
config(['session.driver' => 'array', 'cache.default' => 'array']);
app(Illuminate\Foundation\Vite::class)->useHotFile(storage_path('framework/testing/unused-browser-hot-file'));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$admin = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('slug', 'administrador'))->firstOrFail();
$operator = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('slug', 'operador'))->firstOrFail();
$usersBefore = DB::table('users')->orderBy('id')->get()->toJson();
$tables = ['users', 'roles', 'permissions', 'role_permission', 'model_has_roles', 'model_has_permissions', 'clients', 'subscriptions', 'payment_methods', 'vehicles', 'vehicle_types', 'floors', 'sectors', 'spaces', 'rates', 'tickets', 'payments'];
$authorizationState = fn () => collect(['roles', 'permissions', 'role_permission', 'model_has_roles', 'model_has_permissions'])
    ->mapWithKeys(fn ($table) => [$table => hash('sha256', DB::table($table)->get()->toJson())])->all();
$authorizationBefore = $authorizationState();
$countsBefore = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
$tag = 'WEB-'.strtoupper(bin2hex(random_bytes(4)));
$currentUser = $admin;
$finished = false;
$reports = in_array('--reports', $argv, true);
$administration = in_array('--administration', $argv, true);
DB::beginTransaction();
try {
    if ($administration) {
        sendLine(['type' => 'ready', 'tag' => $tag]);
    } elseif ($reports) {
        Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::parse('2026-11-03 01:00:00', 'America/La_Paz'));
        Carbon\CarbonImmutable::setTestNow(Illuminate\Support\Carbon::now());
        $scenario = Tests\Support\ReportScenario::create($admin, $tag);
        sendLine(['type' => 'ready', 'type_id' => $scenario['car']->id, 'qr_id' => $scenario['qr']->id, 'plate' => $scenario['v1']->plate]);
    } else {
    $type = VehicleType::create(['name' => $tag.' Auto', 'is_active' => true]);
    $floor = Floor::create(['name' => $tag.' Piso', 'is_active' => true]);
    $sector = Sector::create(['name' => $tag.' Sector', 'floor_id' => $floor->id, 'is_active' => true]);
    $space = Space::create(['code' => $tag, 'floor_id' => $floor->id, 'sector_id' => $sector->id, 'vehicle_type_id' => $type->id, 'status' => 'available', 'is_active' => true]);
    $rate = Rate::create(['name' => $tag.' Hora', 'vehicle_type_id' => $type->id, 'unit' => 'hour', 'amount' => '5.25', 'is_active' => true]);
    sendLine(['type' => 'ready', 'plate' => $tag, 'type_id' => $type->id, 'space_id' => $space->id, 'rate_id' => $rate->id]);
    }
    while (($line = fgets(STDIN)) !== false) {
        $input = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (($input['command'] ?? null) === 'finish') {
            $finished = true;
            break;
        }
        if (($input['command'] ?? null) === 'role') {
            $currentUser = ($input['role'] ?? '') === 'operador' ? $operator : $admin;
            sendLine(['id' => $input['id'], 'status' => 200, 'body' => '{}']);
            continue;
        }
        Auth::setUser($currentUser);
        $contentType = $input['content_type'] ?? 'application/x-www-form-urlencoded';
        $isJson = str_contains($contentType, 'application/json');
        $payload = [];
        if (! $isJson) parse_str($input['body'] ?? '', $payload);
        $request = Illuminate\Http\Request::create(
            $input['url'], $input['method'], $payload, [], [],
            ['HTTP_ACCEPT' => $input['accept'] ?? 'text/html', 'HTTP_X_REQUESTED_WITH' => $input['ajax'] ?? '', 'CONTENT_TYPE' => $contentType],
            $isJson ? ($input['body'] ?? '') : null
        );
        try {
            $response = $kernel->handle($request);
            $binaryFile = $response instanceof Symfony\Component\HttpFoundation\BinaryFileResponse;
            $body = $binaryFile ? file_get_contents($response->getFile()->getPathname()) : $response->getContent();
            sendLine(['id' => $input['id'], 'status' => $response->getStatusCode(), 'body' => $reports ? '' : $body,
                'body_base64' => $reports ? base64_encode($body) : null, 'content_type' => $response->headers->get('Content-Type')]);
            if ($binaryFile) {
                // Laravel Excel creates this temporary download file for this request.
                $response->deleteFileAfterSend(true);
                ob_start();
                $response->sendContent();
                ob_end_clean();
            }
        } catch (Throwable $error) {
            sendLine(['id' => $input['id'], 'status' => 500, 'body' => json_encode(['message' => 'Bridge request failed.'])]);
        }
    }
} catch (Throwable $error) {
    sendLine(['type' => 'error', 'message' => $error->getMessage()]);
} finally {
    DB::rollBack();
    Illuminate\Support\Carbon::setTestNow();
    Carbon\CarbonImmutable::setTestNow();
    $countsAfter = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    $usersAfter = DB::table('users')->orderBy('id')->get()->toJson();
    sendLine(['type' => 'complete', 'rolled_back' => $countsBefore === $countsAfter && $usersBefore === $usersAfter && $authorizationBefore === $authorizationState(), 'finished' => $finished]);
}
