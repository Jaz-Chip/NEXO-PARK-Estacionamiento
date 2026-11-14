import $ from 'jquery';
import Swal from 'sweetalert2';

// Keep transport and CSRF configuration in one place; modules still own their requests.
export function configureAjax() {
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            Accept: 'application/json',
        },
    });
}

export const ajaxJson = options => $.ajax({dataType: 'json', ...options});
export const escapeHtml = value => $('<span>').text(value ?? '').html();
export const validationErrors = error => error.status === 422 ? (error.responseJSON?.errors ?? {}) : null;

export function errorText(error, fallback = 'No se pudo completar la operación.') {
    const fields = validationErrors(error);
    return fields && Object.keys(fields).length
        ? Object.values(fields).flat().join(' ')
        : (error.responseJSON?.message || fallback);
}

export function markInvalidFields(form, error) {
    if (!form) return;
    Object.entries(validationErrors(error) ?? {}).forEach(([name, messages]) => {
        const input = form.elements.namedItem(name);
        if (!input) return;
        $(input).addClass('is-invalid');
        $(form).find('[data-error-for]').filter((_, node) => node.dataset.errorFor === name).text(messages[0]);
    });
}

export function replaceTableRows(table, rows) {
    table.clear().rows.add(rows).draw(false);
}

export function notifySuccess(message, options = {}) {
    return Swal.fire({title: message, icon: 'success', timer: 1800, showConfirmButton: false, ...options});
}
