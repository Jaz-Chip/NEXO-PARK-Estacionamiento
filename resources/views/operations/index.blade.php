@extends('layouts.app')
@section('title', $title)
@section('description', 'Reportes y estadísticas operativas del estacionamiento.')

@section('content')
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Generación de reportes</h2>
            </div>
            <div class="card-body">
                <form action="{{ route('reports.generate') }}" method="GET" target="_blank" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label" for="report-type">Tipo de reporte</label>
                        <select class="form-select" id="report-type" name="type" required>
                            <option value="caja">Corte de caja e ingresos</option>
                            <option value="estancias">Historial de estancias</option>
                            <option value="ocupacion">Estado actual de ocupación</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="report-from">Desde</label>
                        <input class="form-control" type="date" id="report-from" name="from" value="{{ date('Y-m-d') }}">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="report-to">Hasta</label>
                        <input class="form-control" type="date" id="report-to" name="to" value="{{ date('Y-m-d') }}">
                    </div>

                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Generar PDF</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection