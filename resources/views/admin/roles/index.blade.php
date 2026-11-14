@extends('layouts.app')
@section('title', 'Roles y permisos')
@section('description', 'Selecciona un rol para consultar o configurar sus funciones permitidas.')
@section('content')
<div id="permissions-notice" class="alert alert-success d-none" role="status"></div>
@can('roles.manage')<div class="mb-3"><button type="button" class="btn btn-primary" id="role-new">Nuevo rol</button></div>@endcan
<div class="row row-cards" id="roles-list">
    @include('admin.roles.list')
</div>
<div class="modal fade" id="permissions-modal" tabindex="-1" aria-labelledby="permissions-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <form id="permissions-form" action="{{ route('admin.roles.permissions.update') }}" class="d-flex flex-column overflow-hidden" method="POST">
                @csrf @method('PUT')
                <div class="modal-header">
                    <h2 class="modal-title" id="permissions-modal-title">Permisos del rol</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div id="permissions-loading" class="text-secondary mb-3" role="status">Cargando permisos…</div>
                    <div id="permissions-feedback" class="alert alert-danger d-none" role="alert"></div>
                    <div class="mb-3 d-none" id="role-name-field"><label class="form-label required" for="role-name">Nombre del rol</label><input type="text" class="form-control" id="role-name" name="name" maxlength="100" disabled required autocomplete="off"></div>
                    <div id="permissions-readonly" class="alert alert-info d-none">Estos permisos son de solo consulta. El Administrador conserva acceso completo.</div>
                    <p class="text-secondary">Consultar permite abrir cada módulo. Las acciones desmarcadas se revocan al guardar. Solo se aplicarán los permisos disponibles para los módulos actuales.</p>
                    <fieldset id="permissions-options" disabled>
                        @foreach($permissions as $module => $items)
                            <section class="mb-4" data-permission-group aria-labelledby="permissions-heading-{{ $module }}">
                                <h3 id="permissions-heading-{{ $module }}" class="border-bottom pb-2">{{ config('permissions.modules.'.$module, $module) }}</h3>
                                <div class="d-flex gap-2 mb-3"><button type="button" class="btn btn-sm btn-outline-primary" data-select-permissions="all">Seleccionar todos</button><button type="button" class="btn btn-sm btn-outline-secondary" data-select-permissions="none">Ninguno</button></div>
                                <div class="row g-3">
                                    @foreach($items as $permission)
                                        <div class="col-12 col-md-6 col-lg-4">
                                            <label class="form-check mb-0">
                                                <input type="checkbox" class="form-check-input" name="permissions[]" value="{{ $permission->name }}" @disabled(!auth()->user()->can($permission->name))>
                                                <span class="form-check-label">{{ $permission->label ?? $permission->name }}</span>
                                                
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </fieldset>
                </div>
                <div class="modal-footer">
                    <div id="permissions-confirmation" class="alert alert-warning w-100 mb-2 d-none" role="alert">Confirma los cambios: se habilitarán las acciones marcadas y se revocarán las desmarcadas.</div>
                    <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cerrar</button>
                    @can('roles.manage')<button type="submit" class="btn btn-primary" id="permissions-save" disabled>Guardar permisos</button>@endcan
                </div>
            </form>
        </div>
    </div>
</div>
<script type="application/json" id="roles-config">@json(['storeUrl' => route('admin.roles.store')])</script>
@endsection
