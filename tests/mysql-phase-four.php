<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\{Auth, DB};
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser;
use Tests\Support\ReportScenario;

if (DB::connection()->getDriverName() !== 'mysql') throw new RuntimeException('MySQL required.');
$app['env'] = 'testing';
config(['cache.default' => 'array', 'session.driver' => 'array']);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$admin = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('slug', 'administrador'))->firstOrFail();
$protected = ['users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_permission', 'migrations'];
$fingerprint = fn () => collect($protected)->mapWithKeys(fn ($table) => [$table => hash('sha256', DB::table($table)->get()->toJson())])->all();
$before = $fingerprint();
$counts = fn () => collect(['clients', 'vehicles', 'vehicle_types', 'floors', 'sectors', 'spaces', 'rates', 'subscriptions', 'tickets', 'payments'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
$countsBefore = $counts();
$checks = 0; $failed = false;
function checkReport(bool $condition, string $message): void {global $checks; if (! $condition) throw new RuntimeException($message); $checks++;}
function requestReport(string $url, int $expected = 200) {
    global $kernel;
    $response = $kernel->handle(Illuminate\Http\Request::create($url, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));
    checkReport($response->getStatusCode() === $expected, $url.' returned '.$response->getStatusCode().' instead of '.$expected);
    return $response;
}
DB::beginTransaction();
try {
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-11-03 01:00:00', 'America/La_Paz'));
    Auth::setUser($admin);
    $b = ReportScenario::create($admin, 'R4-'.strtoupper(bin2hex(random_bytes(3))));
    $filters = ['from' => '2026-11-01', 'to' => '2026-11-03', 'vehicle_type_id' => $b['car']->id];
    $query = http_build_query($filters);
    $response = json_decode(requestReport('/reportes/ingresos/datos?'.$query)->getContent(), true);
    $data = $response['data'];
    checkReport($data['summary'][0]['value'] === '10.30', 'Wrong car income');
    checkReport($data['summary'][1]['value'] === 3, 'Duplicate payment counted');
    $table = fn ($data, $key) => collect($data['tables'])->firstWhere('key', $key)['rows'];
    checkReport(array_column($table($data, 'daily'), 'amount') === ['0.30', '10.00', '0.00'], 'Daily totals');
    checkReport($table($data, 'monthly')[0]['amount'] === '10.30', 'Monthly totals');
    checkReport(collect($table($data, 'methods'))->firstWhere('method', 'QR')['amount'] === '0.20', 'QR totals');
    checkReport($data['summary'][2]['value'] === '2.00', 'Previous period');
    $qr = json_decode(requestReport('/reportes/ingresos/datos?'.$query.'&payment_method_id='.$b['qr']->id)->getContent(), true)['data'];
    checkReport($qr['summary'][0]['value'] === '0.20', 'Payment method filter');
    $vehicles = json_decode(requestReport('/reportes/vehiculos/datos?'.$query)->getContent(), true)['data'];
    checkReport($table($vehicles, 'ranking')[0]['visits'] === 3, 'Ranking frequency');
    $occupancy = json_decode(requestReport('/reportes/ocupacion/datos?'.$query)->getContent(), true)['data'];
    checkReport(array_column($table($occupancy, 'history'), 'occupied_hours') == [2.5, 1, 1], 'Overnight / overlapping historical occupancy');
    checkReport($occupancy['summary'][2]['value'] >= 1, 'Current occupied spaces');
    foreach (['ingresos', 'vehiculos', 'ocupacion'] as $report) {
        $pdf = requestReport('/reportes/'.$report.'/exportar/pdf?'.$query.($report === 'ingresos' ? '&snapshot='.$response['snapshot'] : ''));
        checkReport(str_starts_with($pdf->getContent(), '%PDF-'), 'Invalid PDF');
        $parsed = (new Parser)->parseContent($pdf->getContent());
        checkReport(str_contains($parsed->getText(), 'Sistema Web de Estacionamiento'), 'PDF content missing');
        if ($report === 'ingresos') checkReport(str_contains($parsed->getText(), '10.30'), 'PDF/screen total differs');
        $excel = requestReport('/reportes/'.$report.'/exportar/xlsx?'.$query.($report === 'ingresos' ? '&snapshot='.$response['snapshot'] : ''));
        $path = $excel->getFile()->getPathname();
        $workbook = IOFactory::load($path);
        checkReport($workbook->getSheetCount() > 1, 'Excel tables missing');
        if ($report === 'ingresos') {
            checkReport((float) $workbook->getSheet(0)->getCell('B7')->getValue() === 10.30, 'Excel/screen total differs');
            checkReport($workbook->getSheet(0)->getCell('B7')->getDataType() === 'n', 'Money exported as text');
            checkReport($workbook->getSheet(1)->getCell('A7')->getDataType() === 'n', 'Date exported as text');
            checkReport($workbook->getSheet(5)->getCell('H7')->getDataType() === 's', 'Reference interpreted as formula');
        }
        $workbook->disconnectWorksheets(); unlink($path);
    }
    $empty = json_decode(requestReport('/reportes/ingresos/datos?'.http_build_query([...$filters, 'from' => '2025-01-01', 'to' => '2025-01-03']))->getContent(), true)['data'];
    checkReport($empty['summary'][0]['value'] === '0.00', 'Empty period');
    requestReport('/reportes/ingresos/datos?from=invalid&to=2026-11-03', 422);
    foreach (['operador', 'supervisor'] as $slug) {
        $user = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('slug', $slug))->firstOrFail();
        Auth::setUser($user);
        foreach (['ingresos' => 'ingresos.view', 'vehiculos' => 'estadisticas.view', 'ocupacion' => 'ocupacion.view'] as $report => $permission) {
            requestReport('/reportes/'.$report.'/datos?'.$query, $user->can($permission) ? 200 : 403);
            foreach (['pdf' => 'pdf', 'xlsx' => 'excel'] as $format => $suffix) {
                if (! $user->can($permission) || ! $user->can('exportaciones.'.$suffix)) requestReport('/reportes/'.$report.'/exportar/'.$format.'?'.$query, 403);
            }
        }
    }
} catch (Throwable $error) {
    $failed = true; fwrite(STDERR, $error->getMessage().PHP_EOL);
} finally {
    DB::rollBack(); \Carbon\Carbon::setTestNow();
}
checkReport($before === $fingerprint(), 'Existing users, roles, permissions or migrations changed');
checkReport($countsBefore === $counts(), 'Test rows were retained');
echo "MySQL reports: $checks checks; existing records and permissions preserved; transaction reverted.\n";
exit($failed ? 1 : 0);
