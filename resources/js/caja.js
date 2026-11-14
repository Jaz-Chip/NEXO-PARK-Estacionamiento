import $ from 'jquery';
import {ajaxJson} from './common.js';
import {initOperationPage} from './operaciones-base.js';

export async function init() {
    const context = initOperationPage('caja');
    if (!context) return;
    const {config, table, reload, showPrint, fail} = context;
    $('#cash-filters').on('submit', event => {event.preventDefault(); reload();});
    $('#operations-table').on('click', '[data-operation="receipt"]', async function () {
        const row = table.row($(this).closest('tr')).data();
        if (!row) return;
        try {
            const response = await ajaxJson({url: `${config.cashBase}/${row.id}/comprobante`, method: 'GET'});
            showPrint(response.print_html);
        } catch (error) {fail(error);}
    });
    if (config.canQuote) {
        const {initCheckout} = await import('./salidas.js');
        initCheckout(context);
    }
}
