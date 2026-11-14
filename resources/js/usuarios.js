import $ from 'jquery';
import * as tabler from '@tabler/core';
import Swal from 'sweetalert2';
import DataTable from 'datatables.net-bs5';
import {ajaxJson, escapeHtml, errorText, markInvalidFields, replaceTableRows, notifySuccess} from './common.js';

export function initUsuarios() {
    const node = document.getElementById('users-config');
    if (!node || node.dataset.initialized) return;
    node.dataset.initialized = 'true';
    const config = JSON.parse(node.textContent);
    const text = key => ({data: key, render: (value, type) => type === 'display' ? escapeHtml(value) : value});
    const form = document.getElementById('user-form'), modalNode = document.getElementById('user-modal');
    const modal = modalNode ? tabler.Modal.getOrCreateInstance(modalNode) : null;
    let editing = null, saving = false, opening = false, roles = [];
    const showError = (response, selector) => {
        $(selector).removeClass('d-none').text(errorText(response));
        if (selector === '#user-feedback') markInvalidFields(form, response);
    };
    const table = new DataTable('#users-table', {
        data: [], order: [[0, 'asc']], pageLength: 10,
        columns: [text('name'), text('email'), text('role_label'), {data: 'is_active', render: (value, type) => type === 'display' ? `<span class="badge ${value ? 'bg-success-lt' : 'bg-secondary-lt'}">${value ? 'Activo' : 'Inactivo'}</span>` : Number(value)},
            {data: null, orderable: false, searchable: false, render: (row, type) => type === 'display' ? `<div class="d-flex gap-2">${row.canEdit ? '<button type="button" class="btn btn-sm btn-outline-primary" data-user-action="edit">Editar</button>' : ''}${row.canStatus ? `<button type="button" class="btn btn-sm ${row.is_active ? 'btn-outline-danger' : 'btn-outline-success'}" data-user-action="status">${row.is_active ? 'Desactivar' : 'Activar'}</button>` : ''}${!row.canEdit && !row.canStatus ? `<span class="text-secondary">${row.protected ? 'Cuenta protegida' : 'Solo consulta'}</span>` : ''}</div>` : ''}],
        language: {emptyTable: 'No hay empleados', zeroRecords: 'Sin coincidencias', search: 'Buscar:', lengthMenu: 'Mostrar _MENU_', info: '_START_ a _END_ de _TOTAL_', infoEmpty: 'Sin registros', paginate: {next: 'Siguiente', previous: 'Anterior'}},
    });
    const reload = async () => {
        $('#users-feedback').addClass('d-none');
        try {
            const response = await ajaxJson({url: config.dataUrl, method: 'GET'});
            replaceTableRows(table, response.data); roles = response.roles;
            return true;
        } catch (response) {showError(response, '#users-feedback'); return false;}
    };
    $('#users-refresh').on('click', reload); reload();
    const open = async row => {
        if (!form || opening || saving) return;
        opening = true;
        if (!await reload()) {opening = false; return;}
        row = row ? table.rows().data().toArray().find(item => item.id === row.id) : null;
        if (row && !row.canEdit) {opening = false; return;}
        editing = row?.id ?? null; form.reset();
        $(form).find('.is-invalid').removeClass('is-invalid'); $('#user-feedback').addClass('d-none');
        $('#user-name').val(row?.name ?? ''); $('#user-email').val(row?.email ?? '');
        $('#user-password-fields').toggleClass('d-none', !!row).find('input').prop('disabled', !!row).prop('required', !row);
        $('#user-role').empty().append(new Option('Selecciona un rol', ''));
        roles.forEach(role => $('#user-role').append(new Option(role.name, role.id)));
        $('#user-role').val(row?.role_id ?? '');
        $('#user-role-note').text(row && !row.role_id ? 'Este empleado tiene varios roles o ninguno. Selecciona el rol que reemplazará sus asignaciones actuales.' : 'Se asignarán las funciones autorizadas para el rol seleccionado.');
        $('#user-modal-title').text(row ? 'Editar empleado' : 'Nuevo empleado');
        opening = false; modal.show();
    };
    $('#user-new').on('click', () => open(null));
    $(modalNode).on('hide.bs.modal', event => {if (saving) event.preventDefault();})
        .on('hidden.bs.modal', () => {form.reset(); editing = null;});
    $('#users-table').on('click', '[data-user-action]', async function () {
        const row = table.row($(this).closest('tr')).data();
        if (this.dataset.userAction === 'edit') return open(row);
        if (!row.canStatus || saving) return;
        saving = true;
        const result = await Swal.fire({title: row.is_active ? '¿Desactivar empleado?' : '¿Activar empleado?', text: 'Una cuenta desactivada no podrá iniciar sesión ni continuar usando el sistema.', icon: 'question', showCancelButton: true, confirmButtonText: 'Confirmar', cancelButtonText: 'Cancelar'});
        if (!result.isConfirmed) {saving = false; return;}
        try {
            const response = await ajaxJson({url: `${config.baseUrl}/${row.id}/estado`, method: 'PATCH', data: {is_active: row.is_active ? 0 : 1}});
            await reload(); notifySuccess(response.message, {timer: 1600});
        } catch (response) {showError(response, '#users-feedback');}
        finally {saving = false;}
    });
    $(form).on('submit', async event => {
        event.preventDefault(); if (saving) return;
        saving = true; $('#user-save').prop('disabled', true);
        $(form).find('.is-invalid').removeClass('is-invalid'); $('#user-feedback').addClass('d-none');
        const result = await Swal.fire({target: modalNode, title: editing ? '¿Guardar los cambios del empleado?' : '¿Crear la cuenta del empleado?', icon: 'question', showCancelButton: true, confirmButtonText: 'Confirmar', cancelButtonText: 'Cancelar'});
        if (!result.isConfirmed) {saving = false; $('#user-save').prop('disabled', false); return;}
        try {
            const response = await ajaxJson({url: editing ? `${config.baseUrl}/${editing}` : config.baseUrl, method: editing ? 'PUT' : 'POST', data: $(form).serialize()});
            saving = false; modal.hide(); await reload();
            notifySuccess(response.message, {timer: 1600});
        } catch (response) {showError(response, '#user-feedback');}
        finally {saving = false; $('#user-save').prop('disabled', false);}
    });
}
