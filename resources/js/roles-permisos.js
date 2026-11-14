import $ from 'jquery';
import * as tabler from '@tabler/core';
import Swal from 'sweetalert2';
import {ajaxJson, errorText, notifySuccess} from './common.js';

export function initRolesPermisos() {
    const form = document.getElementById('permissions-form');
    if (!form || form.dataset.initialized) return;
    form.dataset.initialized = 'true';
    const config = JSON.parse(document.getElementById('roles-config').textContent);
    const modalNode = document.getElementById('permissions-modal');
    const modal = tabler.Modal.getOrCreateInstance(modalNode);
    const options = $('#permissions-options'), button = $('#permissions-save');
    let role = null, editable = false, saving = false, requestNumber = 0;
    const error = response => {
        $('#permissions-feedback').removeClass('d-none').text(errorText(response));
    };
    const prepare = (creating = false) => {
        form.reset(); role = null; editable = creating;
        $('#permissions-feedback, #permissions-readonly, #permissions-confirmation').addClass('d-none');
        $('#role-name-field').toggleClass('d-none', !creating);
        $('#role-name').prop('disabled', !creating);
        $('#permissions-loading').toggleClass('d-none', creating).text('Cargando permisos…');
        $('#permissions-modal-title').text(creating ? 'Nuevo rol' : 'Permisos del rol');
        options.prop('disabled', !creating);
        button.prop('disabled', !creating).toggleClass('d-none', !creating).text(creating ? 'Crear rol' : 'Guardar permisos');
        modal.show();
    };
    $(modalNode).on('hide.bs.modal', event => {if (saving) event.preventDefault();})
        .on('hidden.bs.modal', () => {requestNumber++; role = null; editable = false;});
    $('#role-new').on('click', () => {requestNumber++; prepare(true);});
    $('#roles-list').on('click', '[data-role-permissions]', async function () {
        const number = ++requestNumber;
        prepare();
        try {
            const response = await ajaxJson({url: this.dataset.permissionsUrl, method: 'GET'});
            if (number !== requestNumber) return;
            role = response.role; editable = response.canConfigure && !role.protected;
            $('#permissions-modal-title').text('Permisos · ' + role.name);
            $('#role-name-field').toggleClass('d-none', !role.custom);
            $('#role-name').val(role.name).prop('disabled', !editable || !role.custom);
            const granted = new Set(role.permissions);
            options.find('input[type=checkbox]').each((_, input) => {input.checked = granted.has(input.value);});
            options.prop('disabled', !editable);
            button.prop('disabled', !editable).toggleClass('d-none', !editable);
            $('#permissions-readonly').toggleClass('d-none', editable);
            $('#permissions-loading').addClass('d-none');
        } catch (response) {if (number === requestNumber) {$('#permissions-loading').addClass('d-none'); error(response);}}
    });
    options.on('click', '[data-select-permissions]', function () {
        if (!editable || saving) return;
        $(this).closest('[data-permission-group]').find('input[type=checkbox]:enabled').prop('checked', this.dataset.selectPermissions === 'all');
    });
    $(form).on('submit', async event => {
        event.preventDefault();
        if (!editable || saving) return;
        saving = true; button.prop('disabled', true);
        const permissions = options.find('input[type=checkbox]:checked:enabled').map((_, input) => input.value).get();
        const selection = {permissions};
        if (!role || role.custom) selection.name = $('#role-name').val().trim();
        const confirmation = await Swal.fire({target: modalNode, title: role ? '¿Guardar los cambios del rol?' : '¿Crear este rol?', text: 'Se asignarán las acciones marcadas. Las desmarcadas quedarán revocadas.', icon: 'question', showCancelButton: true, confirmButtonText: 'Confirmar', cancelButtonText: 'Cancelar'});
        if (!confirmation.isConfirmed) {saving = false; button.prop('disabled', false); return;}
        options.prop('disabled', true); $('#role-name').prop('disabled', true);
        $('#permissions-feedback').addClass('d-none');
        try {
            const response = await ajaxJson({url: role ? form.action : config.storeUrl, method: role ? 'PUT' : 'POST', contentType: 'application/json',
                data: JSON.stringify(role ? {roles: [{id: role.id, ...selection}]} : selection)});
            $('#roles-list').html(response.rolesHtml).toggleClass('d-none', !response.canView);
            $('#permission-navigation').html(response.navigation);
            $('#role-new').toggleClass('d-none', !response.canConfigure);
            if (response.grantablePermissions) {
                const allowed = new Set(response.grantablePermissions);
                options.find('input[type=checkbox]').each((_, input) => {input.disabled = !allowed.has(input.value);});
            }
            $('#permissions-notice').removeClass('d-none').text(response.message);
            saving = false; modal.hide();
            notifySuccess(response.message, {timer: 1600});
        } catch (response) {
            if (response.status === 403) editable = false;
            error(response);
        } finally {
            saving = false; button.prop('disabled', !editable); options.prop('disabled', !editable);
            $('#role-name').prop('disabled', !editable || (role && !role.custom));
        }
    });
}
