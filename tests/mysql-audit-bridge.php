<?php

// Browser-only CLI harness: real sessions, CSRF and authentication; all business
// writes stay inside one MySQL transaction. Never expose this file through HTTP.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{Role, User};
use Illuminate\Support\Facades\{Auth, DB};

function auditReply(array $data): void
{
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    flush();
}

if (DB::connection()->getDriverName() !== 'mysql' || $app->runningUnitTests()) {
    throw new RuntimeException('Use a local MySQL configuration with CSRF enabled.');
}
$tables = ['users', 'roles', 'permissions', 'role_permission', 'model_has_roles', 'model_has_permissions',
    'clients', 'subscriptions', 'payment_methods', 'vehicles', 'vehicle_types', 'floors', 'sectors', 'spaces', 'rates', 'tickets', 'payments', 'migrations'];
$engines = collect(DB::select('SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'))->keyBy('name');
foreach ($tables as $table) {
    if (($engines[$table]->engine ?? null) !== 'InnoDB') throw new RuntimeException('Transactional isolation unavailable for '.$table);
}
$fingerprint = function () use ($tables) {
    return collect($tables)->mapWithKeys(function ($table) {
        $rows = DB::table($table)->get()->map(fn ($row) => json_encode($row))->sort()->values();

        return [$table => hash('sha256', $rows->toJson())];
    })->all();
};
$before = $fingerprint();
$tag = 'AUD-'.strtoupper(bin2hex(random_bytes(4)));
$sessionDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'parking-'.$tag;
mkdir($sessionDirectory, 0700);
config(['session.driver' => 'file', 'session.files' => $sessionDirectory, 'session.cookie' => 'parking_audit_session',
    'session.domain' => null, 'session.secure' => false, 'cache.default' => 'array']);
app(Spatie\Permission\PermissionRegistrar::class)->initializeCache();
app(Illuminate\Foundation\Vite::class)->useHotFile($sessionDirectory.'/unused-hot-file');
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$finished = false;
DB::beginTransaction();
try {
    $password = bin2hex(random_bytes(20));
    $administrator = Role::where('slug', 'administrador')->where('guard_name', 'web')->firstOrFail();
    $admin = User::create(['name' => $tag, 'email' => strtolower($tag).'@example.test', 'password' => $password,
        'role_id' => $administrator->id, 'is_active' => true]);
    // These temporary credentials travel only through the private process pipe.
    auditReply(['type' => 'ready', 'tag' => $tag, 'email' => $admin->email, 'password' => $password, 'today' => today()->toDateString()]);
    while (($line = fgets(STDIN)) !== false) {
        $input = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (($input['command'] ?? null) === 'finish') { $finished = true; break; }
        Auth::forgetGuards();
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        Illuminate\Support\Facades\Session::clearResolvedInstance('session');
        // Redirector retains its session store; rebuild it for each HTTP request
        // just as a normal PHP request would, including flashed login errors.
        $app->forgetInstance('redirect');
        Illuminate\Support\Facades\Redirect::clearResolvedInstance('redirect');
        $app['cookie']->flushQueuedCookies();
        $headers = $input['headers'] ?? [];
        $server = [];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH']) ? $key : 'HTTP_'.$key] = $value;
        }
        $cookies = [];
        foreach (explode(';', $headers['cookie'] ?? '') as $cookie) {
            $parts = explode('=', trim($cookie), 2);
            if (count($parts) === 2) $cookies[$parts[0]] = urldecode($parts[1]);
        }
        $payload = [];
        $json = str_contains($headers['content-type'] ?? '', 'application/json');
        if (! $json) parse_str($input['body'] ?? '', $payload);
        $request = Illuminate\Http\Request::create($input['url'], $input['method'], $payload, $cookies, [], $server, $json ? ($input['body'] ?? '') : null);
        $response = $kernel->handle($request);
        $binary = $response instanceof Symfony\Component\HttpFoundation\BinaryFileResponse;
        $body = $binary ? file_get_contents($response->getFile()->getPathname()) : $response->getContent();
        $responseHeaders = [];
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) $responseHeaders[$name] = implode(', ', $values);
        $responseHeaders['Set-Cookie'] = implode("\n", array_map(strval(...), $response->headers->getCookies()));
        auditReply(['id' => $input['id'], 'status' => $response->getStatusCode(), 'headers' => $responseHeaders, 'body' => base64_encode($body)]);
        if ($binary) unlink($response->getFile()->getPathname());
    }
} catch (Throwable $error) {
    auditReply(['type' => 'error', 'message' => get_class($error).': '.$error->getMessage()]);
} finally {
    while (DB::transactionLevel() > 0) DB::rollBack();
    // Only files created in this unique temporary session directory are removed.
    foreach (glob($sessionDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) if (is_file($file)) unlink($file);
    rmdir($sessionDirectory);
    auditReply(['type' => 'complete', 'finished' => $finished, 'preserved' => $before === $fingerprint()]);
}
