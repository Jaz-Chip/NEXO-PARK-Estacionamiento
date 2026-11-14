import $ from 'jquery';
import {initOperationPage} from './operaciones-base.js';
import {initTicketPrint} from './tickets.js';
import {ajaxJson} from './common.js';

export async function init() {
    const context = initOperationPage('entradas');
    if (!context) return;
    const {config, unitName, modal, resetErrors, fail, notify, switchModal, showPrint, reload} = context;
    initTicketPrint(context);
    if (config.canQuote) {
        const {initCheckout} = await import('./salidas.js');
        initCheckout(context);
    }
    const entryForm = document.getElementById('entry-form');
    let lookupSequence = 0;
    const resetVehicle = () => {
        $(entryForm).find('[name="vehicle_id"]').val('');
        $('#entry-space, #entry-rate').empty();
    };
    const findVehicle = async () => {
        const sequence = ++lookupSequence;
        resetErrors(entryForm);
        resetVehicle();
        const plate = $('#entry-plate').val().trim();
        $('#vehicle-result').text('Buscando…');
        try {
            const response = await ajaxJson({url: config.optionsUrl, method: 'GET', data: {plate}});
            if (sequence !== lookupSequence) return;
            $('#new-type').empty().append(new Option('Selecciona un tipo', ''));
            response.types.forEach(type => $('#new-type').append(new Option(type.name, type.id)));
            if (!response.vehicle) {
                $('#vehicle-result').text(plate ? 'Vehículo no encontrado. Puedes registrarlo si tienes permiso.' : 'Escribe una placa y pulsa Buscar.');
                return;
            }
            $('#vehicle-result').text(`${response.vehicle.plate} · ${response.vehicle.type}${response.vehicle.is_active ? '' : ' · Inactivo'}`);
            $(entryForm).find('[name="vehicle_id"]').val(response.vehicle.id);
            $('#entry-space').append(new Option('Selecciona un espacio', ''));
            response.spaces.forEach(space => $('#entry-space').append(new Option(space.name, space.id)));
            $('#entry-rate').append(new Option('Selecciona una tarifa', ''));
            response.rates.forEach(rate => $('#entry-rate').append(new Option(`${rate.name}: ${rate.amount} Bs / ${unitName(rate.unit, rate.fraction_minutes)}`, rate.id)));
        } catch (error) { if (sequence === lookupSequence) fail(error, entryForm); }
    };
    $('#open-entry').on('click', () => {
        entryForm.reset(); resetErrors(entryForm); resetVehicle();
        modal('entry-modal').show(); findVehicle();
    });
    $('#entry-plate').on('input', () => { lookupSequence++; resetVehicle(); $('#vehicle-result').text('Pulsa Buscar para cargar el vehículo.'); });
    $('#entry-plate').on('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); findVehicle(); } });
    $('#find-vehicle').on('click', findVehicle);
    $('#new-vehicle').on('click', () => {
        const form = document.getElementById('operation-vehicle-form');
        form.reset(); resetErrors(form); $('#new-plate').val($('#entry-plate').val());
        switchModal('entry-modal', 'vehicle-modal');
    });
    $('#vehicle-modal').on('hidden.bs.modal', () => modal('entry-modal').show());
    $('#operation-vehicle-form').on('submit', async function (event) {
        event.preventDefault();
        const button = $(this).find('[type="submit"]');
        if (button.prop('disabled')) return;
        button.prop('disabled', true); resetErrors(this);
        try {
            const response = await ajaxJson({url: config.vehicleUrl, method: 'POST', data: $(this).serialize()});
            $('#entry-plate').val(response.data.plate);
            modal('vehicle-modal').hide();
            await findVehicle(); notify('Vehículo registrado. Selecciona espacio y tarifa.');
        } catch (error) { fail(error, this); }
        finally { button.prop('disabled', false); }
    });
    $('#entry-form').on('submit', async function (event) {
        event.preventDefault();
        const button = $(this).find('[type="submit"]');
        if (button.prop('disabled')) return;
        button.prop('disabled', true); resetErrors(this);
        try {
            const response = await ajaxJson({url: config.entryUrl, method: 'POST', data: $(this).serialize()});
            showPrint(response.print_html, 'entry-modal'); reload(); notify(response.message);
        } catch (error) { fail(error, this); }
        finally { button.prop('disabled', false); }
    });

}
