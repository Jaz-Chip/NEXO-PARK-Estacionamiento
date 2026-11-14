@extends('layouts.app')
@section('title', $title)
@section('description', 'Consulta y gestión de '.mb_strtolower($title).' del estacionamiento.')
@section('content')
<div class="alert alert-info mb-3">Las acciones del catálogo se realizan mediante modales y AJAX. Los registros inactivos se conservan.</div>
<div class="card">
 <div class="card-header d-flex align-items-center justify-content-between">
  <h2 class="card-title">CONTROL · {{ mb_strtoupper($title) }}</h2>
  @if($canCreate)<button type="button" class="btn btn-primary" id="catalog-new"><x-icon name="check" class="me-1" /> Nuevo registro</button>@endif
 </div>
 <div class="card-body"><div class="table-responsive">
  <table id="catalog-table" class="table table-striped table-vcenter w-100">
   <thead><tr>
    @foreach($columns as [$key, $label])<th>{{ $label }}</th>@endforeach
    @if($canUpdate || $canChangeStatus)<th>Acciones</th>@endif
   </tr></thead>
   <tbody></tbody>
  </table>
 </div></div>
</div>
@if($canCreate || $canUpdate)
<div class="modal fade" id="catalog-modal" tabindex="-1" aria-labelledby="catalog-modal-title" aria-hidden="true">
 <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
  <form id="catalog-form" novalidate>
   <div class="modal-header"><h2 class="modal-title" id="catalog-modal-title">Nuevo registro</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
   <div class="modal-body">
    <div class="row g-3">
     @foreach($fields as [$key, $label, $type, $required])
      <div class="col-12 col-md-6">
       <label for="field-{{ $key }}" class="form-label">{{ $label }} @if($required)<span class="text-danger">*</span>@endif</label>
       @if(str_starts_with($type, 'select:'))
        @php
            $optionKey = substr($type, 7);
        @endphp
        <select class="form-select" id="field-{{ $key }}" name="{{ $key }}" @if($required) required @endif>
         <option value="">Seleccione una opción</option>
         @foreach($lookups[$optionKey] as $option)
          <option value="{{ $option['id'] }}" @if(isset($option['floor_id'])) data-floor="{{ $option['floor_id'] }}" @endif @if(isset($option['client_id'])) data-client="{{ $option['client_id'] }}" @endif @if(isset($option['vehicle_type_id'])) data-type="{{ $option['vehicle_type_id'] }}" @endif>{{ $option['name'] }}</option>
         @endforeach
        </select>
       @else
        <input class="form-control" id="field-{{ $key }}" name="{{ $key }}" type="{{ $type }}" @if($type === 'number') step="{{ $key === 'fraction_minutes' ? '1' : '0.01' }}" min="0" @endif @if($required) required @endif>
       @endif
       <div class="invalid-feedback" data-error-for="{{ $key }}"></div>
      </div>
     @endforeach
    </div>
    <div id="catalog-form-error" class="alert alert-danger mt-3 d-none" role="alert"></div>
   </div>
   <div class="modal-footer"><button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary" id="catalog-save">Guardar</button></div>
  </form>
 </div></div>
</div>
@endif
@php
    $catalogConfig = [
 'baseUrl' => $baseUrl,
 'dataUrl' => $baseUrl.'/datos',
 'columns' => $columns,
 'fields' => $fields,
 'canCreate' => $canCreate,
 'canUpdate' => $canUpdate,
 'canChangeStatus' => $canChangeStatus,
    ];
@endphp
<script type="application/json" id="catalog-config">@json($catalogConfig)</script>
@endsection
