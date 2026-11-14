// Uses installed Edge and an external playwright-core installation. All operation
// requests are mocked; real MySQL transactions are covered by mysql-phase-three.php.
const { chromium } = require(process.env.PARKING_PLAYWRIGHT_PATH || 'playwright-core');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const fixture = path.join(root, 'storage/framework/testing/phase-three-ui');

(async () => {
    const browser = await chromium.launch({executablePath: process.env.PARKING_BROWSER_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true});
    const page = await browser.newPage({viewport: {width: 1360, height: 900}});
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    let navigationCount = 0;
    page.on('framenavigated', frame => {if (frame === page.mainFrame()) navigationCount++;});
    let vehicle = null; let ticket = null; let paid = false; let stale = true;
    const print = '<article class="parking-document"><h2>Estacionamiento</h2><p>E-UI-001</p><p>UI-123</p></article>';
    const quote = () => ({data: ticket, quote: {total: '5.25', rate_amount: '5.25', unit: 'hour', units: 1, duration_seconds: 60, fraction_minutes: null}, methods: {cash: true, qr: true}});
    const payment = {id: 1, number: 'E-UI-001', plate: 'UI-123', amount: '5.25', method: 'Transferencia / QR', operator: 'Operador', paid_at: new Date().toISOString(), reference: 'QR-UI', status: 'paid'};
    const json = (route, data, status = 200) => route.fulfill({status, contentType: 'application/json', body: JSON.stringify(data)});
    await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        const pathname = url.pathname;
        const data = new URLSearchParams(route.request().postData() || '');
        if (/^\/(entradas|salidas|tickets|caja)$/.test(pathname) && route.request().method() === 'GET') {
            return route.fulfill({contentType: 'text/html', body: fs.readFileSync(path.join(fixture, pathname.slice(1)+'.html'))});
        }
        if (pathname.startsWith('/build/assets/')) {
            return route.fulfill({contentType: pathname.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(path.join(root, 'public', pathname))});
        }
        if (pathname.endsWith('/datos')) return json(route, {data: pathname.startsWith('/caja') ? (paid ? [payment] : []) : (ticket && (!paid || pathname.startsWith('/tickets')) ? [ticket] : []), stats: {available: paid || !ticket ? 1 : 0, occupied: paid || !ticket ? 0 : 1, todayIncome: paid ? '5.25' : '0.00'}, filtered_total: '5.25'});
        if (pathname === '/entradas/opciones') return json(route, {vehicle, types: [{id: 1, name: 'Auto'}], spaces: vehicle ? [{id: 1, name: 'Piso 1 / Norte / A1'}] : [], rates: [{id: 1, name: 'Hora', amount: '5.25', unit: 'hour', fraction_minutes: null}]});
        if (pathname === '/vehiculos' && route.request().method() === 'POST') {
            assert.equal(data.get('plate'), 'UI-123');
            vehicle = {id: 1, plate: 'UI-123', type: 'Auto', is_active: true};
            return json(route, {data: vehicle}, 201);
        }
        if (pathname === '/entradas' && route.request().method() === 'POST') {
            assert.equal(data.get('vehicle_id'), '1'); assert.equal(data.get('space_id'), '1');
            ticket = {id: 1, number: 'E-UI-001', plate: 'UI-123', space: 'Piso 1 / Norte / A1', entered_at: new Date().toISOString(), exited_at: null, status: 'open', rate_amount: '5.25', unit: 'hour'};
            return json(route, {data: ticket, message: 'Entrada registrada.', print_html: print}, 201);
        }
        if (pathname === '/salidas/buscar' || pathname.endsWith('/cotizacion')) return json(route, quote());
        if (pathname.endsWith('/cobrar')) {
            assert.equal(data.get('method'), 'qr'); assert.equal(data.get('qr_confirmed'), '1');
            if (stale) {stale = false; return json(route, {errors: {expected_total: ['El importe cambió. Actualiza el cálculo.']}}, 422);}
            paid = true; ticket.status = 'closed';
            return json(route, {message: 'Pago registrado y espacio liberado.', print_html: print, payment_id: 1, total: '5.25'});
        }
        if (pathname.endsWith('/imprimir') || pathname.endsWith('/comprobante')) return json(route, {print_html: print});
        return route.fulfill({status: 404, body: ''});
    });
    try {
        await page.goto('http://localhost/entradas');
        await page.locator('#open-entry').click();
        await page.locator('#entry-modal.show').waitFor();
        await page.locator('#entry-plate').fill('UI-123');
        await page.locator('#find-vehicle').click();
        await page.waitForFunction(() => document.querySelector('#vehicle-result').textContent.includes('no encontrado'));
        await page.locator('#new-vehicle').click();
        await page.locator('#vehicle-modal.show').waitFor();
        await page.locator('#new-type').selectOption('1');
        await page.locator('#operation-vehicle-form [type=submit]').click();
        await page.locator('#entry-modal.show').waitFor();
        await page.locator('#entry-space').selectOption('1');
        await page.locator('#entry-rate').selectOption('1');
        await page.locator('#entry-form [type=submit]').click();
        await page.locator('#print-modal.show').waitFor();
        await page.locator('#operations-table').getByText('UI-123').waitFor();
        assert.equal(navigationCount, 1, 'Entry reloaded the page');
        await page.evaluate(() => {window.print = () => {window.printCalled = true;};});
        await page.locator('#print-operation').click();
        assert(await page.evaluate(() => window.printCalled));
        await page.goto('http://localhost/salidas');
        await page.locator('#open-search').click();
        await page.locator('#ticket-search').fill('UI-123');
        await page.locator('#ticket-search-form [type=submit]').click();
        await page.locator('#checkout-detail:not(.d-none)').waitFor();
        await page.locator('#checkout-method').selectOption('qr');
        await page.locator('[name=qr_confirmed]').check();
        await page.locator('#checkout-reference').fill('QR-UI');
        await page.locator('#checkout-form [type=submit]').click();
        await page.locator('.swal2-confirm').click();
        await page.locator('#checkout-form [data-form-errors]:not(.d-none)').waitFor();
        assert(await page.locator('#checkout-form [data-form-errors]').innerText().then(t => t.includes('importe')));
        await page.locator('#refresh-quote').click();
        await page.locator('#checkout-detail:not(.d-none)').waitFor();
        await page.locator('[name=qr_confirmed]').check();
        await page.locator('#checkout-form [type=submit]').click();
        await page.locator('.swal2-confirm').click();
        await page.locator('#print-modal.show').waitFor();
        await page.waitForFunction(() => document.querySelector('[data-stat=available]').textContent === '1');
        assert.equal(navigationCount, 2, 'Checkout reloaded the page');
        await page.goto('http://localhost/caja');
        await page.locator('#operations-table').getByText('UI-123').waitFor();
        await page.locator('#cash-method').selectOption('qr');
        await page.locator('#cash-filters button').click();
        await page.locator('[data-operation=receipt]').click();
        await page.locator('#print-modal.show').waitFor();
        await page.setViewportSize({width: 390, height: 844});
        await page.screenshot({path: path.join(fixture, 'cash-mobile.png'), fullPage: true});
        assert.equal(navigationCount, 3, 'Cash filtering reloaded the page');
        assert.deepEqual(errors, [], 'Browser JavaScript errors');
        console.log('Edge: vehicle modal, entry, ticket printing, lookup, QR, inline 422, recalculation, checkout, stats and cash receipt passed without page reloads.');
    } finally {
        await browser.close();
    }
})().catch(error => {console.error(error.message); process.exitCode = 1;});
