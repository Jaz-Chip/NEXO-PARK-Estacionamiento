<?php

namespace App\Http\Controllers;

use App\Exports\ParkingReportExport;
use App\Http\Requests\ReportRequest;
use App\Models\{PaymentMethod, VehicleType};
use App\Services\ParkingReports;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function __construct(private ParkingReports $reports) {}

    public function index(Request $request)
    {
        $permissions = ['ingresos' => 'ingresos.view', 'vehiculos' => 'estadisticas.view', 'ocupacion' => 'ocupacion.view'];
        $tabs = array_filter($permissions, fn ($permission) => $request->user()->can($permission));
        abort_unless(count($tabs), 403);

        return view('reports.index', [
            'tabs' => array_intersect_key(ParkingReports::TITLES, $tabs),
            'types' => VehicleType::orderBy('name')->get(['id', 'name']),
            'methods' => PaymentMethod::orderBy('name')->get(['id', 'name']),
            'config' => ['baseUrl' => url('/reportes'), 'initial' => array_key_first($tabs),
                'pdf' => $request->user()->can('exportaciones.pdf'), 'excel' => $request->user()->can('exportaciones.excel')],
        ]);
    }

    public function data(ReportRequest $request, string $report)
    {
        $data = $this->reports->build($report, $request->filters());
        $snapshot = (string) Str::uuid();
        Cache::put('parking-report:'.$snapshot, ['user_id' => $request->user()->id, 'data' => $data], now()->addMinutes(15));

        return response()->json(['data' => $data, 'snapshot' => $snapshot])->header('Cache-Control', 'private, no-store');
    }

    public function export(ReportRequest $request, string $report, string $format)
    {
        if ($request->filled('snapshot')) {
            $snapshot = Cache::get('parking-report:'.$request->validated('snapshot'));
            if (! $snapshot || $snapshot['user_id'] !== $request->user()->id || $snapshot['data']['report'] !== $report || $snapshot['data']['filters'] !== $request->filters()) {
                throw ValidationException::withMessages(['snapshot' => 'La consulta expiró o sus filtros cambiaron. Actualiza el reporte antes de exportar.']);
            }
            $data = $snapshot['data'];
        } else {
            $data = $this->reports->build($report, $request->filters());
        }
        $filename = 'estacionamiento-'.$report.'-'.$data['filters']['from'].'-'.$data['filters']['to'];
        if ($format === 'pdf') {
            $pdf = Pdf::loadView('reports.pdf', ['report' => $data])->setPaper('letter', 'landscape')
                ->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
            $pdf->render();
            $pdf->getDomPDF()->getCanvas()->page_text(650, 580, 'Página {PAGE_NUM} de {PAGE_COUNT}', null, 8, [0.35, 0.35, 0.35]);

            return $pdf->download($filename.'.pdf')->header('Cache-Control', 'private, no-store');
        }

        return Excel::download(new ParkingReportExport($data), $filename.'.xlsx', \Maatwebsite\Excel\Excel::XLSX, ['Cache-Control' => 'private, no-store']);
    }
}
