import $ from 'jquery';
import Swal from 'sweetalert2';
import {initOperationPage} from './operaciones-base.js';
import {initTicketPrint} from './tickets.js';
import {ajaxJson} from './common.js';

export function init() {
    const context = initOperationPage('salidas');
    if (!context) return;
    initTicketPrint(context);
    initCheckout(context);
}

export function initCheckout(context) {
    if (!document.getElementById('checkout-modal')) return;
    const {config, html, date, unitName, modal, resetErrors, fail, notify, showPrint, table, reload} = context;
    let currentTicket = null;
    let quoteSequence = 0;
    const checkoutForm = document.getElementById('checkout-form');
    const showQuote = response => {
        currentTicket = response.data.id;
        const t = response.data; const q = response.quote;
        const minutes = Math.ceil(q.duration_seconds / 60);
        const details = {Boleto: t.number, Placa: t.plate, Espacio: t.space, Entrada: date(t.entered_at), Duración: `${Math.floor(minutes / 60)} h ${minutes % 60} min`, Tarifa: `${q.rate_amount} Bs / ${unitName(q.unit, q.fraction_minutes)}`, 'Unidades iniciadas': q.units};
        $('#stay-detail').html(Object.entries(details).map(([label, value]) => `<dt class="col-sm-4">${html(label)}</dt><dd class="col-sm-8">${html(value)}</dd>`).join(''));
        $('#checkout-total').text(q.total);
        $(checkoutForm).find('[name="expected_total"]').val(q.total);
        $('#checkout-method option').each(function () { this.disabled = !response.methods[this.value]; });
        if ($('#checkout-method option:selected').prop('disabled')) $('#checkout-method').val(response.methods.cash ? 'cash' : (response.methods.qr ? 'qr' : ''));
        $('#checkout-method').trigger('change');
        $('#checkout-detail').removeClass('d-none');
    };
    const requestQuote = async (url, data = {}, errorForm = checkoutForm) => {
        const sequence = ++quoteSequence;
        currentTicket = null;
        $('#checkout-detail').addClass('d-none'); resetErrors(checkoutForm);
        try {
            const response = await ajaxJson({url, method: 'GET', data});
            if (sequence === quoteSequence) showQuote(response);
        } catch (error) { if (sequence === quoteSequence) fail(error, errorForm); }
    };
    $('#open-search').on('click', () => {
        quoteSequence++; currentTicket = null;
        $('#checkout-detail').addClass('d-none');
        document.getElementById('ticket-search-form').reset(); checkoutForm.reset();
        resetErrors(checkoutForm); resetErrors(document.getElementById('ticket-search-form'));
        modal('checkout-modal').show();
    });
    $('#ticket-search-form').on('submit', function (event) {
        event.preventDefault(); resetErrors(this); checkoutForm.reset();
        requestQuote(config.lookupUrl, $(this).serialize(), this);
    });
    $('#refresh-quote').on('click', () => { if (currentTicket) requestQuote(`${config.exitBase}/${currentTicket}/cotizacion`, {}, document.getElementById('ticket-search-form')); });
    $('#checkout-method').on('change', function () {
        $('#qr-confirmation').toggleClass('d-none', this.value !== 'qr');
        $(checkoutForm).find('[name="qr_confirmed"]').prop('checked', false);
    });
    $('#operations-table').on('click', '[data-operation="checkout"]', async function () {
        const row = table.row($(this).closest('tr')).data();
        if (!row) return;
        checkoutForm.reset(); resetErrors(document.getElementById('ticket-search-form'));
        $('#ticket-search').val(row.number); modal('checkout-modal').show();
        await requestQuote(`${config.exitBase}/${row.id}/cotizacion`, {}, document.getElementById('ticket-search-form'));
    });
    $('#checkout-form').on('submit', async function (event) {
        event.preventDefault();
        const button = $(this).find('[type="submit"]');
        if (!currentTicket || button.prop('disabled')) return;
        button.prop('disabled', true); resetErrors(this);
        const ticketId = currentTicket;
        const payload = $(this).serialize();
        try {
            const answer = await Swal.fire({target: document.getElementById('checkout-modal'), title: '¿Confirmar pago y salida?', text: `Se registrarán ${$(this).find('[name="expected_total"]').val()} Bs y se liberará el espacio.`, icon: 'question', showCancelButton: true, confirmButtonText: 'Confirmar pago', cancelButtonText: 'Cancelar'});
            if (!answer.isConfirmed) return;
            const response = await ajaxJson({url: `${config.exitBase}/${ticketId}/cobrar`, method: 'POST', data: payload});
            currentTicket = null; showPrint(response.print_html, 'checkout-modal'); reload(); notify(response.message);
        } catch (error) { fail(error, this); }
        finally { button.prop('disabled', false); }
    });
}
