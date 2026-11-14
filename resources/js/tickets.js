import $ from 'jquery';
import {ajaxJson} from './common.js';
import {initOperationPage} from './operaciones-base.js';

export async function init() {
    const context = initOperationPage('tickets');
    if (!context) return;
    initTicketPrint(context);
    if (context.config.canQuote) {
        const {initCheckout} = await import('./salidas.js');
        initCheckout(context);
    }
}

export function initTicketPrint({config, table, showPrint, fail}) {
    $('#operations-table').on('click', '[data-operation="ticket"]', async function () {
        const row = table.row($(this).closest('tr')).data();
        if (!row) return;
        try {
            const response = await ajaxJson({url: `${config.ticketBase}/${row.id}/imprimir`, method: 'GET'});
            showPrint(response.print_html);
        } catch (error) {fail(error);}
    });
}
