// Module loading and catalog modals in Edge against Laravel/MySQL.
// The bridge keeps all fixture rows inside one transaction and restores existing data.
const {chromium} = require(process.env.PARKING_PLAYWRIGHT_PATH || 'playwright-core');
const {spawn} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const readline = require('node:readline');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
const catalogNames = ['clientes', 'abonados', 'vehiculos', 'tipos-vehiculo', 'pisos', 'sectores', 'espacios', 'tarifas'];
for (const name of [...catalogNames, 'entradas', 'salidas', 'caja', 'tickets', 'reports', 'usuarios', 'roles-permisos']) {
    assert(manifest[`resources/js/${name}.js`]?.isDynamicEntry, `Missing Vite module: ${name}`);
}
const bridge = spawn('php', [path.join(__dirname, 'mysql-browser-bridge.php'), '--administration'], {cwd: root});
let ready, complete, nextId = 0, stderr = '';
const pending = new Map();
const readyPromise = new Promise(resolve => {ready = resolve;});
const completePromise = new Promise(resolve => {complete = resolve;});
bridge.stderr.on('data', chunk => {stderr += chunk.toString();});
readline.createInterface({input: bridge.stdout}).on('line', line => {
    const message = JSON.parse(line);
    if (message.type === 'ready' || message.type === 'error') return ready(message);
    if (message.type === 'complete') return complete(message);
    pending.get(message.id)?.(message); pending.delete(message.id);
});
function call(data) {
    return new Promise(resolve => {const id = ++nextId; pending.set(id, resolve); bridge.stdin.write(JSON.stringify({id, ...data})+'\n');});
}

(async () => {
    const setup = await readyPromise;
    assert.equal(setup.type, 'ready', setup.message);
    const browser = await chromium.launch({executablePath: process.env.PARKING_BROWSER_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true});
    const page = await browser.newPage({viewport: {width: 1360, height: 900}});
    const errors = [], assetRequests = [], mutations = [], ajaxMutations = [];
    let navigations = 0;
    page.on('pageerror', error => errors.push(error.message));
    page.on('framenavigated', frame => {if (frame === page.mainFrame()) navigations++;});
    page.on('request', request => {if (request.url().includes('/build/assets/')) assetRequests.push(new URL(request.url()).pathname);});
    await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.pathname.startsWith('/build/assets/')) return route.fulfill({status: 200, contentType: url.pathname.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(path.join(root, 'public', url.pathname))});
        if (['POST', 'PUT', 'PATCH'].includes(request.method())) {
            mutations.push(`${request.method()} ${url.pathname}`);
            if (request.headers()['x-requested-with'] === 'XMLHttpRequest') ajaxMutations.push(`${request.method()} ${url.pathname}`);
        }
        const response = await call({url: url.pathname+url.search, method: request.method(), body: request.postData() || '', content_type: request.headers()['content-type'], accept: request.headers().accept || 'text/html', ajax: request.headers()['x-requested-with'] || ''});
        await route.fulfill({status: response.status, contentType: response.content_type || 'application/json', body: response.body});
    });
    const go = async name => {
        const before = assetRequests.length;
        await page.goto(`http://localhost/${name}`);
        await page.waitForFunction(() => document.getElementById('catalog-config')?.dataset.initialized === 'true');
        assert.equal(await page.evaluate(() => window.jQuery?.fn.jquery), '4.0.0', `${name} did not load jQuery 4`);
        assert.equal(await page.evaluate(() => typeof window.jQuery?.ajax), 'function', `${name} did not expose $.ajax`);
        const expected = '/build/'+manifest[`resources/js/${name}.js`].file;
        await page.waitForFunction(expectedAsset => performance.getEntriesByType('resource').some(item => new URL(item.name).pathname === expectedAsset), expected);
        const pageAssets = assetRequests.slice(before);
        assert(pageAssets.includes(expected), `${name} chunk not requested`);
        for (const other of catalogNames.filter(module => module !== name)) {
            assert(!pageAssets.includes('/build/'+manifest[`resources/js/${other}.js`].file), `${name} loaded unrelated ${other} chunk`);
        }
        assert(!pageAssets.includes('/build/'+manifest['node_modules/chart.js/auto/auto.js'].file), `${name} loaded Chart.js`);
        await page.locator('#catalog-table tbody tr').first().waitFor();
    };
    const setFields = async values => {
        for (const [key, value] of Object.entries(values)) {
            const control = page.locator(`#field-${key}`);
            if (await control.evaluate(el => el.tagName === 'SELECT')) await control.selectOption(String(value));
            else await control.fill(String(value));
        }
    };
    const create = async (name, values, rowText) => {
        await go(name);
        await page.locator('#catalog-new').click();
        await page.locator('#catalog-modal.show').waitFor();
        await setFields(values);
        const saving = page.waitForResponse(response => new URL(response.url()).pathname === `/${name}` && response.request().method() === 'POST');
        await page.locator('#catalog-save').click();
        const response = await saving;
        assert.equal(response.status(), 201, `${name}: ${await response.text()}`);
        const id = (await response.json()).data.id;
        await page.locator('#catalog-modal').waitFor({state: 'hidden'});
        await page.locator('#catalog-table tbody tr').filter({hasText: rowText}).first().waitFor();
        return id;
    };
    let failure;
    try {
        const tag = setup.tag;
        const type = await create('tipos-vehiculo', {name: tag+' Auto'}, tag+' Auto');
        const floor = await create('pisos', {name: tag+' Piso'}, tag+' Piso');
        const sector = await create('sectores', {floor_id: floor, name: tag+' Sector'}, tag+' Sector');
        const space = await create('espacios', {floor_id: floor, sector_id: sector, code: tag+'A', vehicle_type_id: type, status: 'available'}, tag+'A');
        const hourly = await create('tarifas', {name: tag+' Hora', vehicle_type_id: type, unit: 'hour', amount: '5.25'}, tag+' Hora');
        assert(hourly);
        const monthly = await create('tarifas', {name: tag+' Mes', vehicle_type_id: type, unit: 'month', amount: '80.00'}, tag+' Mes');
        const client = await create('clientes', {name: tag+' Cliente', document: tag}, tag+' Cliente');
        await create('clientes', {name: '<img src=x onerror=window.__injected=1>', document: tag+'-X'}, 'window.__injected=1');
        assert.equal(await page.locator('#catalog-table tbody img').count(), 0, 'User HTML was rendered instead of escaped');
        assert.equal(await page.evaluate(() => window.__injected ?? 0), 0, 'User HTML executed in the catalog');
        const vehicle = await create('vehiculos', {plate: tag+'B', vehicle_type_id: type, client_id: client}, tag+'B');
        const subscription = await create('abonados', {client_id: client, vehicle_id: vehicle, rate_id: monthly, space_id: space, starts_on: '2026-11-12', ends_on: '2026-12-12', amount: '80.00'}, tag+'B');
        assert(subscription);

        await go('clientes');
        await page.locator('.dt-search input').fill(tag);
        const row = page.locator('#catalog-table tbody tr').filter({hasText: tag+' Cliente'}).first();
        await row.locator('[data-catalog-action=edit]').click();
        await page.locator('#catalog-modal.show').waitFor();
        await page.locator('#field-name').fill(tag+' Cliente Editado');
        const updating = page.waitForResponse(response => new URL(response.url()).pathname === `/clientes/${client}` && response.request().method() === 'PUT');
        await page.locator('#catalog-save').click();
        assert.equal((await updating).status(), 200);
        await page.locator('#catalog-table tbody tr').filter({hasText: tag+' Cliente Editado'}).first().waitFor();
        const before = mutations.filter(item => item === 'POST /clientes').length;
        await page.evaluate(async url => (await import(url)).init(), '/build/'+manifest['resources/js/clientes.js'].file);
        await page.locator('#catalog-new').click();
        await page.locator('#field-name').fill(tag+' Otro');
        await page.locator('#field-document').fill(tag);
        const duplicate = page.waitForResponse(response => new URL(response.url()).pathname === '/clientes' && response.request().method() === 'POST');
        await page.locator('#catalog-save').click();
        assert.equal((await duplicate).status(), 422);
        await page.locator('#field-document.is-invalid').waitFor();
        assert.equal(mutations.filter(item => item === 'POST /clientes').length, before+1, 'Module was initialized twice');
        assert.deepEqual(ajaxMutations, mutations, 'A catalog mutation did not use jQuery AJAX');
        await page.locator('.swal2-confirm').click();
        await page.locator('#catalog-modal .btn-close').click();
        await page.locator('#catalog-modal').waitFor({state: 'hidden'});
        await row.locator('[data-catalog-action=status]').click();
        await page.locator('.swal2-confirm').click();
        await page.waitForFunction(tag => [...document.querySelectorAll('#catalog-table tbody tr')].some(item => item.innerText.includes(tag+' Cliente Editado') && item.innerText.includes('Inactivo')), tag);
        assert.equal(navigations, 11, 'Catalog mutations reloaded a page');
        assert.deepEqual(errors, [], 'Browser JavaScript errors');
        console.log('Edge + MySQL: eight catalog chunks loaded independently; create, edit, validation 422, status, escaped user HTML, modals, DataTables and single event registration passed.');
    } catch (error) {failure = error;}
    finally {
        await browser.close(); bridge.stdin.write(JSON.stringify({command: 'finish'})+'\n');
        const result = await completePromise;
        assert(result.rolled_back && result.finished, 'Existing MySQL data was not restored'); bridge.stdin.end();
    }
    if (failure) throw failure;
})().catch(error => {console.error(error.stack, stderr); process.exitCode = 1; bridge.kill();});
