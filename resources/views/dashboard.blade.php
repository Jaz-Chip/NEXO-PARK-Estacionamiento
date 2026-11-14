@extends('layouts.app')
@section('title', 'Panel de monitoreo')
@section('description', 'Estado operativo del parqueo en tiempo real.')

@section('content')
@php
    $totalActive = $available + $occupied;
    $percentage = $totalActive > 0 ? round(100 * $occupied / $totalActive) : 0;
@endphp

<section class="console-intro">
    <div>
        <div class="console-intro-tag">
            <span></span> OPERACIÓN DE ESTACIONAMIENTO
        </div>
        <h2>Centro de control</h2>
        <p>Información esencial de vehículos, ocupación y cobros.</p>
    </div>
    <div class="console-intro-right">
        <span>OCUPACIÓN GENERAL</span>
        <strong>{{ $percentage }}%</strong>
        <div class="progress">
            <div class="progress-bar" style="width: {{ $percentage }}%"></div>
        </div>
    </div>
</section>

<div class="console-grid">
    <article class="console-kpi">
        <div class="kpi-top">
            <span>01 / REGISTROS</span>
            <span>↗</span>
        </div>
        <strong>{{ number_format($vehicles) }}</strong>
        <h3>Vehículos</h3>
    </article>

    <article class="console-kpi">
        <div class="kpi-top">
            <span>02 / CAPACIDAD</span>
            <span>↗</span>
        </div>
        <strong>{{ number_format($available) }}</strong>
        <h3>Espacios libres</h3>
    </article>

    <article class="console-kpi">
        <div class="kpi-top">
            <span>03 / OCUPACIÓN</span>
            <span>↗</span>
        </div>
        <strong>{{ number_format($occupied) }}</strong>
        <h3>Espacios ocupados</h3>
    </article>

    <article class="console-kpi">
        <div class="kpi-top">
            <span>04 / INGRESOS</span>
            <span>Bs</span>
        </div>
        <strong>Bs {{ number_format($todayIncome, 2) }}</strong>
        <h3>Cobrado hoy</h3>
    </article>
</div>

<div class="console-actions">
    <div>
        <h3>Acciones rápidas</h3>
        <p>Elegí una operación para continuar.</p>
    </div>
    <div class="quick-actions">
        @can('entradas.create')
            <a class="btn btn-primary" href="{{ route('operations.entradas.index') }}">+ Registrar entrada</a>
        @endcan
        @can('salidas.view')
            <a class="btn btn-outline-primary" href="{{ route('operations.salidas.index') }}">Gestionar salida</a>
        @endcan
        @can('caja.view')
            <a class="btn btn-outline-secondary" href="{{ route('operations.caja.index') }}">Ver caja</a>
        @endcan
        @canany(['ingresos.view', 'estadisticas.view', 'ocupacion.view'])
            <a class="btn btn-outline-secondary" href="{{ route('reports.index') }}">Reportes</a>
        @endcanany
    </div>
</div>

<div class="console-compact">
    <div>
        <span>CAPACIDAD ACTIVA</span>
        <strong>{{ $totalActive }}</strong>
        <small>espacios habilitados</small>
    </div>
    <div>
        <span>OCUPACIÓN</span>
        <strong>{{ $occupied }}</strong>
        <small>estancias actualmente abiertas</small>
    </div>
    <div>
        <span>INGRESO HISTÓRICO</span>
        <strong>Bs {{ number_format($income, 2) }}</strong>
        <small>transacciones confirmadas</small>
    </div>
</div>
@endsection