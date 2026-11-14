import $ from 'jquery';
import * as tabler from '@tabler/core';
import Swal from 'sweetalert2';
import {configureAjax} from './common.js';
import './ui-shell.js';

window.$ = window.jQuery = $;
window.tabler = tabler;
window.Swal = Swal;

configureAjax();

// Vite creates a separate chunk for each module. Only the current page loads it.
const catalogModules = {
    clientes: () => import('./clientes.js'),
    abonados: () => import('./abonados.js'),
    vehiculos: () => import('./vehiculos.js'),
    'tipos-vehiculo': () => import('./tipos-vehiculo.js'),
    pisos: () => import('./pisos.js'),
    sectores: () => import('./sectores.js'),
    espacios: () => import('./espacios.js'),
    tarifas: () => import('./tarifas.js'),
};
const operationModules = {
    entradas: () => import('./entradas.js'),
    salidas: () => import('./salidas.js'),
    tickets: () => import('./tickets.js'),
    caja: () => import('./caja.js'),
};

$(async () => {
    const catalog = document.getElementById('catalog-config');
    const operations = document.getElementById('operations-config');
    try {
        if (catalog) {
            const config = JSON.parse(catalog.textContent);
            const module = new URL(config.baseUrl, location.href).pathname.split('/').filter(Boolean).at(-1);
            const load = catalogModules[module];
            if (!load) throw new Error(`Módulo de catálogo desconocido: ${module}`);
            await (await load()).init();
        } else if (operations) {
            const module = JSON.parse(operations.textContent).module;
            const load = operationModules[module];
            if (!load) throw new Error(`Módulo operativo desconocido: ${module}`);
            await (await load()).init();
        } else if (document.getElementById('report-config')) {
            await (await import('./reports.js')).initReports();
        } else if (document.getElementById('users-config')) {
            (await import('./usuarios.js')).initUsuarios();
        } else if (document.getElementById('roles-config')) {
            (await import('./roles-permisos.js')).initRolesPermisos();
        }
    } catch (error) {
        console.error('No se pudo iniciar el módulo de esta página.', error);
    }
});
