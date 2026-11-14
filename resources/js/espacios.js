import {initCatalogModule} from './catalogo-base.js';
export function init() {initCatalogModule('espacios', [{field: 'sector_id', source: 'floor_id', attribute: 'floor'}]);}
