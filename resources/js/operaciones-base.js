import $ from 'jquery';
import * as tabler from '@tabler/core';
import Swal from 'sweetalert2';
import DataTable from 'datatables.net-bs5';
import {ajaxJson, escapeHtml, errorText, markInvalidFields, replaceTableRows} from './common.js';

export function initOperationPage(expectedModule) {
    const node = document.getElementById('operations-config');
    if (!node || node.dataset.initialized) return null;
    const config = JSON.parse(node.textContent);
    if (config.module !== expectedModule) return null;
    node.dataset.initialized = 'true';
    const html = value => escapeHtml(value ?? '—');
    const date = value => value ? new Date(value).toLocaleString('es-BO') : '—';
    const unitName = (unit, fraction) => ({minute: 'minuto', hour: 'hora', day: 'día', month: 'mes', fraction: `fracción de ${fraction} min`})[unit] ?? unit;
    const modal = id => tabler.Modal.getOrCreateInstance(document.getElementById(id));
    const resetErrors = form => {
        $(form).find('.is-invalid').removeClass('is-invalid');
        $(form).find('[data-form-errors]').addClass('d-none').empty();
    };
    const fail = (error, form = null) => {
        const box = form ? $(form).find('[data-form-errors]') : $('#operations-error');
        box.removeClass('d-none').text(errorText(error, 'No se pudo completar la solicitud. Revisa la conexión.'));
        markInvalidFields(form, error);
    };
    const notify = message => Swal.fire({toast: true, position: 'top-end', icon: 'success', title: message, timer: 2400, showConfirmButton: false});
    const switchModal = (from, to) => {
        if (from && document.getElementById(from)?.classList.contains('show')) {
            $(`#${from}`).one('hidden.bs.modal', () => modal(to).show());
            modal(from).hide();
        } else modal(to).show();
    };
    const showPrint = (content, from = null) => {
        // Content comes only from escaped server-rendered Blade templates.
        $('#print-document').html(content);
        switchModal(from, 'print-modal');
    };
    const textColumn = (key, title, formatter = null) => ({
        data: key, title, render: (value, type) => type === 'display' ? html(formatter ? formatter(value) : value) : (value ?? ''),
    });
    const cash = config.module === 'caja';
    const columns = cash ? [
        textColumn('id', '#'), textColumn('number', 'Boleto / abono'), textColumn('plate', 'Placa'),
        textColumn('amount', 'Importe Bs'), textColumn('method', 'Método'), textColumn('operator', 'Operador'),
        textColumn('paid_at', 'Fecha', date), textColumn('reference', 'Referencia'),
        textColumn('status', 'Estado', v => v === 'paid' ? 'Pagado' : 'Anulado'),
    ] : [
        textColumn('number', 'Boleto'), textColumn('plate', 'Placa'), textColumn('space', 'Espacio'),
        textColumn('entered_at', 'Entrada', date), textColumn('exited_at', 'Salida', date),
        textColumn('status', 'Estado', v => ({open: 'Estacionado', closed: 'Cerrado', cancelled: 'Anulado'})[v]),
    ];
    columns.push({data: null, title: 'Acciones', orderable: false, searchable: false, render: (_, type, row) => {
        if (type !== 'display') return '';
        if (cash) return '<button class="btn btn-sm btn-outline-primary" data-operation="receipt">Comprobante</button>';
        let buttons = '';
        if (row.status === 'open' && config.canQuote) buttons += '<button class="btn btn-sm btn-outline-success" data-operation="checkout">Salida / pago</button> ';
        if (config.canPrintTicket) buttons += '<button class="btn btn-sm btn-outline-primary" data-operation="ticket">Boleto</button>';
        return buttons;
    }});
    const table = new DataTable('#operations-table', {
        data: [], columns, order: [], pageLength: 10,
        language: {emptyTable: 'No hay registros', zeroRecords: 'Sin coincidencias', search: 'Buscar:', lengthMenu: 'Mostrar _MENU_', info: '_START_ a _END_ de _TOTAL_', infoEmpty: 'Sin registros', paginate: {next: 'Siguiente', previous: 'Anterior'}},
    });
    let reloadSequence = 0;
    const reload = async () => {
        const sequence = ++reloadSequence;
        $('#operations-error').addClass('d-none');
        try {
            const response = await ajaxJson({url: config.dataUrl, method: 'GET', data: cash ? $('#cash-filters').serialize() : {}});
            if (sequence !== reloadSequence) return;
            replaceTableRows(table, response.data);
            Object.entries(response.stats).forEach(([key, value]) => $(`[data-stat="${key}"]`).text(value));
            if (cash) $('#filtered-total').text(response.filtered_total);
        } catch (error) { if (sequence === reloadSequence) fail(error); }
    };
    reload();
    $('#refresh-operations').on('click', reload);

    $('#print-operation').on('click', () => {
        document.body.classList.add('printing-operation');
        window.print();
        document.body.classList.remove('printing-operation');
    });
    window.addEventListener('afterprint', () => document.body.classList.remove('printing-operation'));
    return {config, html, date, unitName, modal, resetErrors, fail, notify, switchModal, showPrint, table, reload};
}
