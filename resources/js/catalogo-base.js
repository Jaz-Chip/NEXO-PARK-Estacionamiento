import $ from 'jquery';
import * as tabler from '@tabler/core';
import Swal from 'sweetalert2';
import DataTable from 'datatables.net-bs5';
import {ajaxJson, escapeHtml, validationErrors, markInvalidFields, replaceTableRows, notifySuccess} from './common.js';

const fieldValue = (row, path) => path.split('.').reduce((value, key) => value?.[key], row);

function showAjaxError(error, form = null) {
    const fields = validationErrors(error);
    markInvalidFields(form, error);
    const message = fields ? Object.values(fields).flat()[0] : error.responseJSON?.message;
    Swal.fire({title: 'No se pudo completar la operación', text: message || 'Revisa la conexión o vuelve a iniciar sesión.', icon: 'error'});
}

// Shared CRUD behavior; each catalog module supplies its own name and form dependencies.
export function initCatalogModule(module, dependencies = []) {
    const node = document.getElementById('catalog-config');
    if (!node || node.dataset.initialized) return;
    const config = JSON.parse(node.textContent);
    const pageModule = new URL(config.baseUrl, location.href).pathname.split('/').filter(Boolean).at(-1);
    if (pageModule !== module) return;
    const tableNode = document.getElementById('catalog-table');
    if (DataTable.isDataTable(tableNode)) return;
    node.dataset.initialized = 'true';

    const columns = config.columns.map(([key]) => ({
        data: null,
        render: (_, type, row) => {
            let value = fieldValue(row, key);
            if (key === 'is_active') {
                if (type !== 'display') return value ? 1 : 0;
                return value ? '<span class="badge bg-success-lt">Activo</span>' : '<span class="badge bg-secondary-lt">Inactivo</span>';
            }
            if (key === 'status') {
                const labels = {available: 'Disponible', occupied: 'Ocupado', maintenance: 'Mantenimiento'};
                if (type !== 'display') return labels[value] || value || '';
                return escapeHtml(labels[value] || value || '—');
            }
            if (key === 'unit') value = {minute: 'Minuto', hour: 'Hora', fraction: 'Fracción', month: 'Mes', day: 'Día'}[value] || value;
            if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T/.test(value)) value = value.slice(0, 10);
            if (key === 'amount' && value != null) value = Number(value).toFixed(2);
            return type === 'display' ? escapeHtml(value ?? '—') : (value ?? '');
        },
    }));
    if (config.canUpdate || config.canChangeStatus) columns.push({
        data: null, orderable: false, searchable: false,
        render: (_, type, row) => {
            if (type !== 'display') return '';
            const edit = config.canUpdate ? '<button type="button" class="btn btn-sm btn-outline-primary" data-catalog-action="edit">Editar</button>' : '';
            const status = config.canChangeStatus ? `<button type="button" class="btn btn-sm ${row.is_active ? 'btn-outline-danger' : 'btn-outline-success'}" data-catalog-action="status">${row.is_active ? 'Desactivar' : 'Activar'}</button>` : '';
            return `<div class="d-flex gap-1">${edit}${status}</div>`;
        },
    });
    const table = new DataTable(tableNode, {
        data: [], columns, pageLength: 10, order: [[0, 'asc']],
        language: {emptyTable: 'No hay registros', zeroRecords: 'No se encontraron coincidencias', search: 'Buscar:', lengthMenu: 'Mostrar _MENU_', info: 'Mostrando _START_ a _END_ de _TOTAL_', infoEmpty: 'Sin registros', paginate: {first: 'Primera', last: 'Última', next: 'Siguiente', previous: 'Anterior'}},
    });
    const reload = async () => {
        try {
            const response = await ajaxJson({url: config.dataUrl, method: 'GET'});
            replaceTableRows(table, response.data);
        } catch (error) {showAjaxError(error);}
    };
    reload();
    if (!config.canCreate && !config.canUpdate && !config.canChangeStatus) return;

    const form = document.getElementById('catalog-form');
    const modal = form ? tabler.Modal.getOrCreateInstance(document.getElementById('catalog-modal')) : null;
    let editingId = null;
    let changingStatus = false;
    const resetErrors = () => {
        $(form).find('.is-invalid').removeClass('is-invalid');
        $(form).find('[data-error-for]').text('');
        $('#catalog-form-error').addClass('d-none').text('');
    };
    const updateDependencies = () => {
        if (!form) return;
        for (const {field, source, attribute, sourceAttribute} of dependencies) {
            const select = form.elements.namedItem(field);
            if (!select) continue;
            const sourceControl = form.elements.namedItem(source);
            const parentValue = sourceAttribute ? sourceControl?.selectedOptions?.[0]?.dataset[sourceAttribute] : sourceControl?.value;
            for (const option of select.options) {
                if (!option.value) continue;
                option.hidden = !!parentValue && !!option.dataset[attribute] && option.dataset[attribute] !== parentValue;
            }
        }
    };
    if (form) $(form).on('change', 'select', updateDependencies);
    $('#catalog-new').on('click', () => {
        if (!config.canCreate || !form) return;
        editingId = null; form.reset(); resetErrors(); updateDependencies();
        $('#catalog-modal-title').text('Nuevo registro'); modal.show();
    });
    $(tableNode).on('click', '[data-catalog-action]', async function () {
        const row = table.row($(this).closest('tr')).data();
        if (!row) return;
        if (this.dataset.catalogAction === 'edit') {
            if (!config.canUpdate || !form) return;
            editingId = row.id; form.reset(); resetErrors();
            for (const [name] of config.fields) {
                const input = form.elements.namedItem(name);
                if (!input) continue;
                let value = row[name];
                if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T/.test(value)) value = value.slice(0, 10);
                input.value = value ?? '';
            }
            updateDependencies();
            $('#catalog-modal-title').text('Editar registro'); modal.show();
            return;
        }
        if (!config.canChangeStatus || changingStatus) return;
        changingStatus = true;
        const next = !row.is_active;
        try {
            const confirmation = await Swal.fire({title: next ? '¿Activar registro?' : '¿Desactivar registro?', icon: 'question', showCancelButton: true, confirmButtonText: 'Confirmar', cancelButtonText: 'Cancelar'});
            if (!confirmation.isConfirmed) return;
            const response = await ajaxJson({url: `${config.baseUrl}/${row.id}/estado`, method: 'PATCH', data: {is_active: next ? 1 : 0}});
            await reload();
            notifySuccess(response.message);
        } catch (error) {showAjaxError(error);}
        finally {changingStatus = false;}
    });
    if (!form) return;
    $(form).on('submit', async event => {
        event.preventDefault(); resetErrors();
        if (editingId ? !config.canUpdate : !config.canCreate) return;
        const button = $('#catalog-save');
        if (button.prop('disabled')) return;
        button.prop('disabled', true);
        const data = $(form).serializeArray().reduce((result, item) => {result[item.name] = item.value; return result;}, {});
        try {
            const response = await ajaxJson({url: editingId ? `${config.baseUrl}/${editingId}` : config.baseUrl, method: editingId ? 'PUT' : 'POST', data});
            modal.hide(); await reload();
            notifySuccess(response.message);
        } catch (error) {showAjaxError(error, form);}
        finally {button.prop('disabled', false);}
    });
}
