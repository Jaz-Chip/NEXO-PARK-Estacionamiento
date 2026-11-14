@extends('layouts.app')
@section('title', 'Usuarios')
@section('description', 'Cuentas internas del personal. Los clientes y conductores no necesitan una cuenta.')
@section('content')
<div class="card">
    <div class="card-header gap-2 flex-wrap"><h2 class="card-title me-auto">Acceso del personal</h2>
        <button type="button" id="users-refresh" class="btn btn-outline-secondary">Actualizar</button>
        @if($canManage)<button type="button" id="user-new" class="btn btn-primary">Nuevo empleado</button>@endif
    </div>
    <div class="card-body"><div id="users-feedback" class="alert alert-danger d-none" role="alert"></div><div class="table-responsive"><table id="users-table" class="table table-vcenter w-100"><thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Estado</th><th>Acciones</th></tr></thead></table></div></div>
</div>
@if($canManage)
<div class="modal modal-blur fade" id="user-modal" tabindex="-1" aria-labelledby="user-modal-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form id="user-form" class="modal-content">
    <div class="modal-header"><h2 class="modal-title" id="user-modal-title">Nuevo empleado</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <div class="modal-body">
        <div id="user-feedback" class="alert alert-danger d-none" role="alert"></div>
        <div class="mb-3"><label class="form-label required" for="user-name">Nombre</label><input type="text" class="form-control" id="user-name" name="name" required maxlength="255" autocomplete="name"></div>
        <div class="mb-3"><label class="form-label required" for="user-email">Correo</label><input type="email" class="form-control" id="user-email" name="email" required maxlength="255" autocomplete="off"></div>
        <div id="user-password-fields">
            <div class="mb-3"><label class="form-label required" for="user-password">Contraseña</label><input type="password" class="form-control" id="user-password" name="password" required minlength="8" maxlength="72" autocomplete="new-password"><div class="form-hint">Mínimo 8 caracteres.</div></div>
            <div class="mb-3"><label class="form-label required" for="user-password-confirmation">Confirmar contraseña</label><input type="password" class="form-control" id="user-password-confirmation" name="password_confirmation" required minlength="8" maxlength="72" autocomplete="new-password"></div>
        </div>
        <div class="mb-3"><label class="form-label required" for="user-role">Rol</label><select id="user-role" name="role_id" class="form-select" required></select></div>
        <p class="small text-secondary mb-0" id="user-role-note">Se asignarán las funciones autorizadas para el rol seleccionado.</p>
    </div>
    <div class="modal-footer"><button class="btn" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit" id="user-save">Guardar empleado</button></div>
</form></div></div>
@endif
<script type="application/json" id="users-config">@json(['baseUrl' => url('/admin/usuarios'), 'dataUrl' => route('admin.users.data'), 'canManage' => $canManage])</script>
@endsection
