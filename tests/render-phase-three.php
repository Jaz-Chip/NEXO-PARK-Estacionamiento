<?php

// Read-only HTML fixtures for the browser interaction test; no login secrets needed.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['env'] = 'testing';
config(['session.driver' => 'array', 'cache.default' => 'array']);
$admin = App\Models\User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('slug', 'administrador'))->firstOrFail();
Illuminate\Support\Facades\Auth::setUser($admin);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$directory = storage_path('framework/testing/phase-three-ui');
app(Illuminate\Foundation\Vite::class)->useHotFile($directory.'/unused-hot-file');
if (! is_dir($directory)) mkdir($directory, 0777, true);
foreach (['entradas', 'salidas', 'tickets', 'caja'] as $module) {
    $response = $kernel->handle(Illuminate\Http\Request::create('/'.$module));
    if ($response->getStatusCode() !== 200) throw new RuntimeException('Unable to render '.$module);
    file_put_contents($directory.'/'.$module.'.html', $response->getContent());
}
echo "Read-only operations HTML fixtures rendered.\n";
