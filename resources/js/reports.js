import $ from 'jquery';
import * as tabler from '@tabler/core';
import DataTable from 'datatables.net-bs5';
import {ajaxJson, escapeHtml, errorText, markInvalidFields} from './common.js';

export async function initReports() {
    const node = document.getElementById('report-config');
    if (!node || node.dataset.initialized) return;
    node.dataset.initialized = 'true';
    const config = JSON.parse(node.textContent);
    const {default: Chart} = await import('chart.js/auto');
    const html = value => escapeHtml(value ?? '—');
    const formatted = (value, format) => {
        if (value === null || value === undefined) return '—';
        if (format === 'money') return `Bs ${Number(value).toLocaleString('es-BO', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
        if (format === 'percent') return `${value}%`;
        if (format === 'number') return Number(value).toLocaleString('es-BO', {maximumFractionDigits: 4});
        return String(value);
    };
    const language = {emptyTable: 'Sin registros para estos filtros', zeroRecords: 'Sin coincidencias', search: 'Buscar en tabla:', lengthMenu: 'Mostrar _MENU_', info: '_START_ a _END_ de _TOTAL_', infoEmpty: 'Sin registros', paginate: {next: 'Siguiente', previous: 'Anterior'}};
    const form = document.getElementById('report-filters');
    let current = config.initial, result = null, snapshot = null, table = null, detailTable = null, charts = [], sequence = 0;
    const columns = definition => definition.columns.map(column => ({data: column.key, title: column.label, defaultContent: '', render: (value, type) => type === 'display' ? html(formatted(value, column.format)) : (value ?? '')}));
    const renderTable = key => {
        if (!result) return;
        const definition = result.tables.find(t => t.key === key) ?? result.tables[0];
        table?.destroy();
        $('#report-table-host').html('<table id="report-table" class="table table-vcenter w-100"></table>');
        $('#report-table-title').text(definition.title);
        table = new DataTable('#report-table', {data: definition.rows, columns: [...columns(definition), {data: null, title: 'Detalle', orderable: false, searchable: false, render: (_, type) => type === 'display' ? '<button type="button" class="btn btn-sm btn-outline-primary" data-report-detail>Ver detalle</button>' : ''}], order: [], pageLength: 10, language});
    };
    const render = () => {
        $('#report-metrics').html(result.summary.map(metric => `<div class="col-sm-6 col-xl-3"><article class="card h-100 stat-card"><div class="card-body"><h2 class="stat-label text-secondary">${html(metric.label)}</h2><div class="stat-value">${html(formatted(metric.value, metric.format))}</div></div></article></div>`).join(''));
        charts.forEach(chart => chart.destroy()); charts = [];
        $('#report-charts').html(result.charts.map((chart, i) => `<div class="col-lg-6"><div class="card h-100"><div class="card-header"><h2 class="card-title">${html(chart.title)}</h2></div><div class="card-body"><div class="report-chart"><canvas id="report-chart-${i}" role="img"></canvas></div><p class="small text-secondary mt-2 mb-0">Los valores también están disponibles en las tablas.</p></div></div></div>`).join(''));
        result.charts.forEach((chart, i) => $(`#report-chart-${i}`).attr('aria-label', chart.title));
        const colors = ['#206bc4', '#2fb344', '#f59f00', '#ae3ec9', '#d63939', '#0ca678'];
        result.charts.forEach((chart, i) => charts.push(new Chart(document.getElementById(`report-chart-${i}`), {
            type: chart.type, data: {labels: chart.labels, datasets: chart.datasets.map((set, j) => ({...set, data: set.data.map(Number), backgroundColor: chart.type === 'doughnut' ? colors : colors[j % colors.length], borderColor: colors[j % colors.length], borderWidth: 2, tension: 0.15}))},
            options: {responsive: true, maintainAspectRatio: false, animation: false, plugins: {legend: {display: chart.type === 'doughnut' || chart.datasets.length > 1}}, ...(chart.type === 'doughnut' ? {} : {scales: {y: {beginAtZero: true}, x: {ticks: {maxTicksLimit: 12}}}})},
        })));
        $('#report-table-select').empty();
        result.tables.forEach(t => $('#report-table-select').append(new Option(t.title, t.key)));
        renderTable(result.tables[0].key);
        $('#report-notes').html(result.notes.map(note => `<p class="mb-2">${html(note)}</p>`).join(''));
        $('#report-status').text(`Consulta: ${result.filters.from} al ${result.filters.to} · Emitida ${result.generated_at} (America/La_Paz).`);
        $('#report-export').prop('disabled', false);
    };
    const reload = async () => {
        const requestNumber = ++sequence;
        snapshot = null;
        $('#report-export').prop('disabled', true);
        $('#report-errors').empty().addClass('d-none');
        $(form).find('.is-invalid').removeClass('is-invalid');
        $('#report-content').attr('aria-busy', 'true');
        $('#report-status').text('Consultando datos…');
        const isIncome = current === 'ingresos';
        $('#report-method-field').toggleClass('d-none', !isIncome);
        $('#report-method').prop('disabled', !isIncome);
        try {
            const response = await ajaxJson({url: `${config.baseUrl}/${current}/datos`, method: 'GET', data: $(form).serialize()});
            if (requestNumber !== sequence) return;
            result = response.data; snapshot = response.snapshot; render();
        } catch (error) {
            if (requestNumber !== sequence) return;
            $('#report-errors').removeClass('d-none').text(errorText(error, 'No se pudo consultar el reporte.'));
            markInvalidFields(form, error);
            $('#report-status').text('La consulta no se actualizó. Revisa los filtros.');
        } finally {if (requestNumber === sequence) $('#report-content').attr('aria-busy', 'false');}
    };
    $('#report-tabs').on('click', '[data-report]', function () {
        current = this.dataset.report;
        $('#report-tabs [data-report]').removeClass('active').attr('aria-selected', 'false');
        $(this).addClass('active').attr('aria-selected', 'true');
        $('#report-content').attr('aria-labelledby', this.id);
        // Hide previous report data during tab switches and failed requests.
        table?.destroy(); table = null; charts.forEach(chart => chart.destroy()); charts = [];
        $('#report-metrics, #report-charts, #report-table-host, #report-notes').empty();
        result = null;
        reload();
    });
    $(form).on('submit', event => {event.preventDefault(); reload();});
    $(form).on('change', 'input, select', () => {
        sequence++; snapshot = null;
        $('#report-content').attr('aria-busy', 'false');
        $('#report-export').prop('disabled', true);
        $('#report-status').text('Filtros modificados. Pulsa Consultar para actualizar los resultados.');
    });
    $('#report-table-select').on('change', function () {renderTable(this.value);});
    $('#report-table-host').on('click', '[data-report-detail]', function () {
        const row = table.row($(this).closest('tr')).data();
        const definition = result.tables.find(t => t.key === $('#report-table-select').val());
        $('#report-detail-title').text(definition.title);
        detailTable?.destroy(); detailTable = null;
        $('#report-detail-body').html(`<dl class="row">${definition.columns.map(column => `<dt class="col-sm-4">${html(column.label)}</dt><dd class="col-sm-8">${html(formatted(row[column.key], column.format))}</dd>`).join('')}</dl>`);
        let detail = null, rows = [];
        if (['daily', 'monthly', 'methods'].includes(definition.key)) {
            detail = result.tables.find(t => t.key === 'payments');
            rows = detail.rows.filter(payment => definition.key === 'daily' ? payment.date.slice(0, 10) === row.date : definition.key === 'monthly' ? payment.date.slice(0, 7) === row.month : payment.method === row.method);
        } else if (definition.key === 'ranking') {
            detail = result.tables.find(t => t.key === 'visits'); rows = detail.rows.filter(visit => visit.plate === row.plate);
        }
        if (detail) {
            $('#report-detail-body').append('<div class="table-responsive"><table id="report-detail-table" class="table w-100"></table></div>');
            detailTable = new DataTable('#report-detail-table', {data: rows, columns: columns(detail), order: [], pageLength: 10, language});
        }
        tabler.Modal.getOrCreateInstance(document.getElementById('report-detail-modal')).show();
    });
    $('#report-detail-modal').on('shown.bs.modal', () => detailTable?.columns.adjust());
    $('#report-export').on('click', () => {
        if (!snapshot) return;
        $('#report-export-errors').empty().addClass('d-none');
        $('#report-export-description').text(`${result.title} · ${result.filters.from} al ${result.filters.to}`);
        tabler.Modal.getOrCreateInstance(document.getElementById('report-export-modal')).show();
    });
    $('#report-export-form').on('submit', async function (event) {
        event.preventDefault();
        const button = $(this).find('[type=submit]');
        if (!snapshot || button.prop('disabled')) return;
        button.prop('disabled', true).text('Generando…');
        $('#report-export-errors').empty().addClass('d-none');
        const format = $('#report-export-format').val();
        const parameters = new URLSearchParams({snapshot, ...Object.fromEntries(Object.entries(result.filters).filter(([, v]) => v !== null))});
        try {
            const response = await fetch(`${config.baseUrl}/${result.report}/exportar/${format}?${parameters}`, {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.errors ? Object.values(error.errors).flat().join(' ') : error.message);
            }
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a'); link.href = url;
            link.download = `estacionamiento-${result.report}-${result.filters.from}-${result.filters.to}.${format}`;
            document.body.append(link); link.click(); link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 30000);
            tabler.Modal.getOrCreateInstance(document.getElementById('report-export-modal')).hide();
        } catch (error) {$('#report-export-errors').removeClass('d-none').text(error.message || 'No se pudo descargar el archivo.');}
        finally {button.prop('disabled', false).text('Descargar');}
    });
    reload();
}
