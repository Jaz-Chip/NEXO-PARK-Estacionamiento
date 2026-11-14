@extends('layouts.app')
@section('title', 'Reportes y estadísticas')
@section('description', 'Consulta ingresos, frecuencia de vehículos y ocupación con datos del estacionamiento.')

@section('content')
<div class="card mb-3">
    <div class="card-header p-0">
        <ul class="nav nav-tabs card-header-tabs m-0 flex-wrap" id="report-tabs" role="tablist">
            @foreach($tabs as $key => $label)
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $loop->first ? 'active' : '' }}" id="report-tab-{{ $key }}" data-report="{{ $key }}" type="button" role="tab" aria-selected="{{ $loop->first ? 'true' : 'false' }}" aria-controls="report-content">
                        {{ $label }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
    
    <div class="card-body">
        <form id="report-filters" class="row g-3 align-items-end">
            <div class="col-sm-6 col-xl-2">
                <label class="form-label" for="report-from">Fecha inicial</label>
                <input class="form-control" type="date" name="from" id="report-from" value="{{ now('America/La_Paz')->startOfMonth()->toDateString() }}" required>
            </div>

            <div class="col-sm-6 col-xl-2">
                <label class="form-label" for="report-to">Fecha final</label>
                <input class="form-control" type="date" name="to" id="report-to" value="{{ now('America/La_Paz')->toDateString() }}" max="{{ now('America/La_Paz')->toDateString() }}" required>
            </div>

            <div class="col-sm-6 col-xl-3">
                <label class="form-label" for="report-type">Tipo de vehículo</label>
                <select class="form-select" name="vehicle_type_id" id="report-type">
                    <option value="">Todos los tipos</option>
                    @foreach($types as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-sm-6 col-xl-3" id="report-method-field">
                <label class="form-label" for="report-method">Método de pago</label>
                <select class="form-select" name="payment_method_id" id="report-method">
                    <option value="">Todos los métodos</option>
                    @foreach($methods as $method)
                        <option value="{{ $method->id }}">{{ $method->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-auto">
                <button type="submit" class="btn btn-primary" id="report-refresh">Consultar</button>
            </div>

            @if($config['pdf'] || $config['excel'])
                <div class="col-auto">
                    <button type="button" class="btn btn-outline-primary" id="report-export" disabled>Exportar</button>
                </div>
            @endif
        </form>

        <div id="report-errors" class="alert alert-danger mt-3 mb-0 d-none" role="alert"></div>
        <p class="text-secondary small mt-3 mb-0" id="report-status" aria-live="polite">Preparando consulta…</p>
    </div>
</div>

<section id="report-content" role="tabpanel" aria-labelledby="report-tab-{{ $config['initial'] }}" aria-busy="true">
    <div class="row g-3 mb-3" id="report-metrics"></div>
    <div class="row g-3 mb-3" id="report-charts"></div>
    
    <div class="card mb-3">
        <div class="card-header gap-3 flex-wrap">
            <h2 class="card-title me-auto" id="report-table-title">Resultados</h2>
            <div>
                <label for="report-table-select" class="form-label visually-hidden">Tabla del reporte</label>
                <select id="report-table-select" class="form-select" aria-label="Tabla del reporte"></select>
            </div>
        </div>
        <div class="card-body">
            <div id="report-table-host" class="table-responsive"></div>
        </div>
    </div>

    <div class="card">
        <div class="card-body small text-secondary" id="report-notes"></div>
    </div>
</section>

<div class="modal modal-blur fade" id="report-export-modal" tabindex="-1" aria-labelledby="report-export-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form id="report-export-form" class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="report-export-title">Exportar reporte</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger d-none" id="report-export-errors" role="alert"></div>
                <p id="report-export-description"></p>
                <label class="form-label" for="report-export-format">Formato</label>
                <select class="form-select" id="report-export-format" name="format" required>
                    @if($config['pdf'])
                        <option value="pdf">PDF · Carta horizontal</option>
                    @endif
                    @if($config['excel'])
                        <option value="xlsx">Excel · Libro XLSX</option>
                    @endif
                </select>
                <p class="text-secondary small mt-3 mb-0">Incluye filtros, fecha de emisión, resumen y todas las tablas. Conserva los resultados de la consulta mostrada durante 15 minutos.</p>
            </div>
            <div class="modal-footer">
                <button class="btn" type="button" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-primary" type="submit">Descargar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal modal-blur fade" id="report-detail-modal" tabindex="-1" aria-labelledby="report-detail-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="report-detail-title">Detalle del resultado</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="report-detail-body"></div>
            <div class="modal-footer">
                <button class="btn" type="button" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script type="application/json" id="report-config">@json($config)</script>
@endsection