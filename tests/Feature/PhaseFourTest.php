<?php

namespace Tests\Feature;

use App\Models\{Payment, Role, User};
use App\Services\ParkingReports;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser;
use Tests\Support\ReportScenario;
use Tests\TestCase;

class PhaseFourTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private array $b;
    private array $filters = ['from' => '2026-11-01', 'to' => '2026-11-03'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $this->travelTo(\Carbon\Carbon::parse('2026-11-03 01:00:00', 'America/La_Paz'));
        $this->admin = User::factory()->create(['role_id' => Role::where('slug', 'administrador')->firstOrFail()->id, 'is_active' => true]);
        $this->b = ReportScenario::create($this->admin, 'RPT');
        $this->actingAs($this->admin);
    }

    private function data(string $report, array $filters = []): array
    {
        return $this->getJson('/reportes/'.$report.'/datos?'.http_build_query([...$this->filters, ...$filters]))->assertOk()->json();
    }

    private function table(array $data, string $key): array
    {
        return collect($data['data']['tables'])->firstWhere('key', $key)['rows'];
    }

    public function test_daily_monthly_income_methods_comparison_and_filters(): void
    {
        $result = $this->data('ingresos');
        $this->assertSame('15.55', $result['data']['summary'][0]['value']);
        $this->assertSame(4, $result['data']['summary'][1]['value']);
        $this->assertSame('2.00', $result['data']['summary'][2]['value']);
        $this->assertSame('13.55', $result['data']['summary'][3]['value']);
        $this->assertSame(['0.30', '15.25', '0.00'], array_column($this->table($result, 'daily'), 'amount'));
        $this->assertSame('15.55', $this->table($result, 'monthly')[0]['amount']);
        $this->assertSame(['10.10', '5.45'], array_column($this->table($result, 'methods'), 'amount'));
        $this->assertCount(4, $this->table($result, 'payments'));
        $this->assertSame('5.45', $this->data('ingresos', ['payment_method_id' => $this->b['qr']->id])['data']['summary'][0]['value']);
        $this->assertSame('10.30', $this->data('ingresos', ['vehicle_type_id' => $this->b['car']->id])['data']['summary'][0]['value']);
        $range = $this->data('ingresos', ['from' => '2026-10-31', 'to' => '2026-11-01']);
        $this->assertSame(['2.00', '0.30'], array_column($this->table($range, 'monthly'), 'amount'));
    }

    public function test_vehicle_ranking_types_and_inactive_history(): void
    {
        $result = $this->data('vehiculos');
        $this->assertSame([3, 2], array_column($this->table($result, 'ranking'), 'visits'));
        $this->assertSame(['RPTA', 'RPTB'], array_column($this->table($result, 'ranking'), 'plate'));
        $this->assertCount(5, $this->table($result, 'visits'));
        $this->assertSame([3, 2], $result['data']['charts'][0]['datasets'][0]['data']);
        $this->b['v1']->update(['is_active' => false]);
        $this->b['car']->update(['is_active' => false]);
        $filtered = $this->data('vehiculos', ['vehicle_type_id' => $this->b['car']->id]);
        $this->assertCount(1, $this->table($filtered, 'ranking'));
        $this->assertSame(3, $this->table($filtered, 'ranking')[0]['visits']);
    }

    public function test_occupancy_handles_midnight_overlap_open_tickets_and_current_capacity(): void
    {
        $result = $this->data('ocupacion');
        $this->assertSame([2, 1, 1, 50, 0, 7, 2], array_column($result['data']['summary'], 'value'));
        $history = $this->table($result, 'history');
        $this->assertEquals([4, 2, 1], array_column($history, 'occupied_hours'));
        $this->assertSame([2, 2, 1], array_column($history, 'peak'));
        $this->assertEquals([0.1667, 0.0833, 1], array_column($history, 'average'));
        $this->b['b']->update(['status' => 'maintenance']);
        $this->assertSame(1, $this->data('ocupacion')['data']['summary'][0]['value']);
        $this->b['floor']->update(['is_active' => false]);
        $inactive = $this->data('ocupacion');
        $this->assertSame(0, $inactive['data']['summary'][0]['value']);
        $this->assertEquals([4, 2, 1], array_column($this->table($inactive, 'history'), 'occupied_hours'));
    }

    public function test_empty_periods_and_input_validation(): void
    {
        $empty = $this->data('ingresos', ['from' => '2025-01-01', 'to' => '2025-01-03']);
        $this->assertSame('0.00', $empty['data']['summary'][0]['value']);
        $this->assertNull($empty['data']['summary'][4]['value']);
        $this->assertCount(3, $this->table($empty, 'daily'));
        $this->assertSame([], $this->table($empty, 'payments'));
        $this->assertSame([], $this->table($this->data('vehiculos', ['from' => '2025-01-01', 'to' => '2025-01-03']), 'ranking'));
        $this->assertEquals([0, 0, 0], array_column($this->table($this->data('ocupacion', ['from' => '2025-01-01', 'to' => '2025-01-03']), 'history'), 'occupied_hours'));
        foreach ([['from' => 'invalid'], ['from' => '2026-11-03', 'to' => '2026-11-01'], ['to' => '2026-11-04'], ['from' => '2020-01-01'], ['vehicle_type_id' => 999999], ['payment_method_id' => 999999]] as $invalid) {
            $this->getJson('/reportes/ingresos/datos?'.http_build_query([...$this->filters, ...$invalid]))->assertUnprocessable();
        }
        $this->getJson('/reportes/vehiculos/datos?'.http_build_query([...$this->filters, 'payment_method_id' => $this->b['cash']->id]))->assertUnprocessable();
    }

    public function test_pdf_and_excel_match_the_screen_and_snapshot_survives_new_payments(): void
    {
        $result = $this->data('ingresos');
        $query = http_build_query([...$this->filters, 'snapshot' => $result['snapshot']]);
        Payment::create(['ticket_id' => $this->b['open']->id, 'payment_method_id' => $this->b['cash']->id, 'user_id' => $this->admin->id, 'amount' => '100.00', 'status' => 'paid', 'paid_at' => now()]);
        $this->assertSame('115.55', $this->data('ingresos')['data']['summary'][0]['value']);
        $pdf = $this->get('/reportes/ingresos/exportar/pdf?'.$query)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $parsed = (new Parser)->parseContent($pdf->getContent());
        $this->assertStringContainsString('15.55', $parsed->getText());
        $this->assertStringNotContainsString('115.55', $parsed->getText());
        $this->assertStringContainsString('Sistema Web de Estacionamiento', $parsed->getText());
        $this->assertStringContainsString('Página', $parsed->getText());
        $this->assertMatchesRegularExpression('/MediaBox\s*\[\s*0(?:\.\d+)?\s+0(?:\.\d+)?\s+792(?:\.\d+)?\s+612(?:\.\d+)?\s*\]/', $pdf->getContent());
        $xlsx = $this->get('/reportes/ingresos/exportar/xlsx?'.$query)->assertOk();
        $filename = $xlsx->baseResponse->getFile()->getPathname();
        $workbook = IOFactory::load($filename);
        $this->assertEquals(15.55, $workbook->getSheet(0)->getCell('B7')->getValue());
        $this->assertSame('n', $workbook->getSheet(0)->getCell('B7')->getDataType());
        $this->assertSame('n', $workbook->getSheet(1)->getCell('A7')->getDataType());
        $this->assertEquals(0.30, $workbook->getSheet(1)->getCell('C7')->getValue());
        $this->assertStringContainsString('Bs', $workbook->getSheet(1)->getStyle('C7')->getNumberFormat()->getFormatCode());
        $this->assertSame('=2+2', $workbook->getSheet(5)->getCell('H7')->getValue());
        $this->assertSame('s', $workbook->getSheet(5)->getCell('H7')->getDataType());
        $workbook->disconnectWorksheets();
        @unlink($filename);
    }

    public function test_other_exports_empty_pdf_pagination_and_snapshot_validation(): void
    {
        foreach (['vehiculos', 'ocupacion'] as $kind) {
            $this->get('/reportes/'.$kind.'/exportar/pdf?'.http_build_query($this->filters))->assertOk();
            $xlsx = $this->get('/reportes/'.$kind.'/exportar/xlsx?'.http_build_query($this->filters))->assertOk();
            @unlink($xlsx->baseResponse->getFile()->getPathname());
        }
        $pdf = $this->get('/reportes/ingresos/exportar/pdf?from=2025-01-01&to=2025-03-31')->assertOk();
        $parsed = (new Parser)->parseContent($pdf->getContent());
        $this->assertGreaterThan(1, count($parsed->getPages()));
        $this->assertStringContainsString('Sin registros', $parsed->getText());
        $result = $this->data('ingresos');
        $this->getJson('/reportes/ingresos/exportar/pdf?'.http_build_query([...$this->filters, 'snapshot' => $result['snapshot'], 'from' => '2026-11-02']))->assertUnprocessable();
        $this->travel(16)->minutes();
        $this->getJson('/reportes/ingresos/exportar/pdf?'.http_build_query([...$this->filters, 'snapshot' => $result['snapshot']]))->assertUnprocessable();
    }

    public function test_spatie_requires_query_permission_and_separate_export_grant(): void
    {
        $operator = User::factory()->create(['role_id' => Role::where('slug', 'operador')->firstOrFail()->id, 'is_active' => true]);
        $this->actingAs($operator)->get('/reportes')->assertForbidden();
        foreach (['ingresos', 'vehiculos', 'ocupacion'] as $kind) {
            $this->getJson('/reportes/'.$kind.'/datos')->assertForbidden();
            $this->getJson('/reportes/'.$kind.'/exportar/pdf')->assertForbidden();
            $this->getJson('/reportes/'.$kind.'/exportar/xlsx')->assertForbidden();
        }
        $operator->roles->first()->syncPermissions(['estadisticas.view']);
        $this->actingAs($operator->fresh())->get('/reportes')->assertOk()->assertSee('report-tab-vehiculos')->assertDontSee('id="report-tab-ingresos"', false)->assertDontSee('id="report-export"', false);
        $this->getJson('/reportes/vehiculos/datos')->assertOk();
        $this->getJson('/reportes/vehiculos/exportar/pdf')->assertForbidden();
        $operator->roles->first()->givePermissionTo('exportaciones.pdf');
        $this->actingAs($operator->fresh())->get('/reportes/vehiculos/exportar/pdf?'.http_build_query($this->filters))->assertOk();
        $this->getJson('/reportes/ingresos/exportar/pdf')->assertForbidden();
        $this->getJson('/reportes/vehiculos/exportar/xlsx')->assertForbidden();
        $this->assertSame('reports.index', $operator->fresh()->startRoute());
        $operator->roles->first()->revokePermissionTo('estadisticas.view');
        $this->actingAs($operator->fresh())->getJson('/reportes/vehiculos/datos')->assertForbidden();
    }

    public function test_boundaries_use_la_paz_when_storage_timezone_is_utc(): void
    {
        config(['app.timezone' => 'UTC']);
        $base = ['ticket_id' => $this->b['open']->id, 'payment_method_id' => $this->b['cash']->id, 'user_id' => $this->admin->id, 'status' => 'paid'];
        // Raw DB values in configured storage timezone, outside the other fixtures.
        \Illuminate\Support\Facades\DB::table('payments')->insert([
            [...$base, 'amount' => '1.25', 'paid_at' => '2025-01-02 03:59:59'],
            [...$base, 'amount' => '2.50', 'paid_at' => '2025-01-02 04:00:00'],
        ]);
        $result = $this->data('ingresos', ['from' => '2025-01-01', 'to' => '2025-01-02']);
        $this->assertSame(['1.25', '2.50'], array_column($this->table($result, 'daily'), 'amount'));
    }
}
