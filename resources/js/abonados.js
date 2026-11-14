import {initCatalogModule} from './catalogo-base.js';
export function init() {
    initCatalogModule('abonados', [
        {field: 'vehicle_id', source: 'client_id', attribute: 'client'},
        {field: 'rate_id', source: 'vehicle_id', sourceAttribute: 'type', attribute: 'type'},
        {field: 'space_id', source: 'vehicle_id', sourceAttribute: 'type', attribute: 'type'},
    ]);
}
