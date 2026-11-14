// Real browser sessions and CSRF over the Laravel HTTP kernel; temporary MySQL
// records are rolled back by mysql-audit-bridge.php, including on test failure.
const {chromium} = require(process.env.PARKING_PLAYWRIGHT_PATH || 'playwright-core');
const {spawn} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const readline = require('node:readline');
const http = require('node:http');
const root = path.resolve(__dirname, '..');
const bridge = spawn('php', [path.join(__dirname, 'mysql-audit-bridge.php')], {cwd: root});
let ready, complete, nextId = 0;
const pending = new Map();
const readyPromise = new Promise(resolve => {ready = resolve;});
const completePromise = new Promise(resolve => {complete = resolve;});
readline.createInterface({input: bridge.stdout}).on('line', line => {
    const message = JSON.parse(line);
    if (message.type === 'ready') return ready(message);
    if (message.type === 'complete') return complete(message);
    if (message.type === 'error') {
        ready(message);
        for (const {reject} of pending.values()) reject(new Error(message.message));
        return;
    }
    pending.get(message.id)?.resolve(message); pending.delete(message.id);
});
bridge.on('exit', code => {
    if (code) {
        ready({type: 'error', message: `Audit bridge exited: ${code}`});
        for (const {reject} of pending.values()) reject(new Error(`Bridge exited: ${code}`));
    }
});
function call(data) {
    return new Promise((resolve, reject) => {
        const id = ++nextId; pending.set(id, {resolve, reject});
        bridge.stdin.write(JSON.stringify({id, ...data})+'\n');
    });
}

(async () => {
    const setup = await readyPromise;
    assert.equal(setup.type, 'ready', setup.message);
    const browser = await chromium.launch({executablePath: process.env.PARKING_BROWSER_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true});
    const errors = [];
    let mutations = 0;
    // A loopback HTTP server follows real browser redirects and carries cookies.
    // Playwright route interception alone does not cover every redirected request.
    const server = http.createServer(async (request, response) => {
        try {
            const url = new URL(request.url, base);
            if (url.pathname.startsWith('/build/assets/')) {
                response.writeHead(200, {'Content-Type': url.pathname.endsWith('.css') ? 'text/css' : 'application/javascript'});
                return response.end(fs.readFileSync(path.join(root, 'public', url.pathname)));
            }
            const headers = request.headers;
            if (['POST', 'PUT', 'PATCH'].includes(request.method) && !['/login', '/logout'].includes(url.pathname)) {
                assert(headers['x-csrf-token'], 'AJAX mutation must send its CSRF token');
                assert.equal(headers['x-requested-with'], 'XMLHttpRequest'); mutations++;
            }
            const chunks = [];
            for await (const chunk of request) chunks.push(chunk);
            const result = await call({url: url.href, method: request.method, headers, body: Buffer.concat(chunks).toString()});
            if (result.headers['Set-Cookie']) result.headers['Set-Cookie'] = result.headers['Set-Cookie'].split('\n');
            else delete result.headers['Set-Cookie'];
            response.writeHead(result.status, result.headers); response.end(Buffer.from(result.body, 'base64'));
        } catch (error) {errors.push(error.message); response.writeHead(500); response.end('Audit HTTP bridge failed.');}
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const base = `http://127.0.0.1:${server.address().port}`;
    async function context() {
        const ctx = await browser.newContext({viewport: {width: 1360, height: 900}});
        const page = await ctx.newPage();
        page.on('pageerror', error => errors.push(error.message));
        page.on('response', response => {if (response.status() >= 500) console.error('Server error:', response.status(), new URL(response.url()).pathname);});
        return page;
    }
    const page = await context(), employee = await context();
    async function login(target, email, password) {
        await target.goto(base+'/login');
        await target.locator('#email').fill(email); await target.locator('#password').fill(password);
        await target.locator('button[type=submit]').click();
        await target.waitForURL(url => url.pathname !== '/login');
    }
    async function json(target, url, method = 'GET', data = {}) {
        await target.waitForFunction(() => typeof window.jQuery?.ajax === 'function').catch(async error => {
            console.error('JavaScript unavailable:', new URL(target.url()).pathname, await target.title(), errors);
            throw error;
        });
        return target.evaluate(({url, method, data}) => new Promise(resolve => {
            window.jQuery.ajax({url, method, data, dataType: 'json'})
                .done((data, _, xhr) => resolve({status: xhr.status, data}))
                .fail(error => resolve({status: error.status, data: error.responseJSON}));
        }), {url, method, data});
    }
    async function saved(modal) {
        await page.locator(modal).waitFor({state: 'hidden'});
        await page.locator('.swal2-container').waitFor({state: 'hidden'});
    }
    async function createCatalog(module, values) {
        await page.goto(base+'/'+module);
        await page.locator('#catalog-new').click();
        await page.locator('#catalog-modal.show').waitFor();
        for (const [field, value] of Object.entries(values)) {
            const input = page.locator('#field-'+field);
            if (await input.evaluate(el => el.tagName === 'SELECT')) await input.selectOption(String(value));
            else await input.fill(String(value));
        }
        const responsePromise = page.waitForResponse(r => new URL(r.url()).pathname === '/'+module && r.request().method() === 'POST');
        await page.locator('#catalog-save').click();
        const response = await responsePromise;
        assert.equal(response.status(), 201, module+' creation failed');
        const result = await response.json(); await saved('#catalog-modal');
        return result.data.id;
    }
    let failure;
    try {
        await login(page, setup.email, setup.password);
        assert.equal(new URL(page.url()).pathname, '/dashboard');
        // Cross-site/tokenless requests must fail even though normal same-origin
        // browser requests also benefit from Laravel 13's origin verification.
        const cookies = (await page.context().cookies()).map(c => `${c.name}=${c.value}`).join('; ');
        const csrf = await call({url: base+'/clientes', method: 'POST', headers: {accept: 'application/json', cookie: cookies, 'content-type': 'application/json', 'sec-fetch-site': 'cross-site'}, body: '{}'});
        assert.equal(csrf.status, 419);
        await page.goto(base+'/admin/roles');
        await page.locator('#role-new').click();
        await page.locator('#role-name').fill(setup.tag+' Empleados');
        for (const permission of ['clientes.view', 'clientes.create', 'vehiculos.view']) await page.locator(`[name="permissions[]"][value="${permission}"]`).check();
        const rolePromise = page.waitForResponse(r => r.url().endsWith('/admin/roles') && r.request().method() === 'POST');
        await page.locator('#permissions-save').click(); await page.locator('.swal2-confirm').click();
        const roleResponse = await rolePromise; assert.equal(roleResponse.status(), 201);
        const roleId = (await roleResponse.json()).roles[0].id; await saved('#permissions-modal');
        await page.goto(base+'/admin/usuarios');
        await page.locator('#user-new').click(); await page.locator('#user-modal.show').waitFor();
        const employeeEmail = 'employee-'+setup.email;
        await page.locator('#user-name').fill(setup.tag+' Empleado');
        await page.locator('#user-email').fill(employeeEmail);
        await page.locator('#user-password').fill(setup.password);
        await page.locator('#user-password-confirmation').fill(setup.password);
        await page.locator('#user-role').selectOption(String(roleId));
        const userPromise = page.waitForResponse(r => r.url().endsWith('/admin/usuarios') && r.request().method() === 'POST');
        await page.locator('#user-save').click(); await page.locator('.swal2-confirm').click();
        const userResponse = await userPromise; assert.equal(userResponse.status(), 201);
        const userId = (await userResponse.json()).id; await saved('#user-modal');
        await login(employee, employeeEmail, setup.password);
        assert.equal(new URL(employee.url()).pathname, '/clientes');
        assert.equal((await json(employee, '/clientes', 'POST', {name: setup.tag, document: setup.tag})).status, 201);
        assert.equal((await json(employee, '/tarifas', 'POST')).status, 403);
        assert.equal((await json(employee, '/admin/roles')).status, 403);
        await page.goto(base+'/admin/roles');
        await page.locator(`[data-role-permissions="${roleId}"]`).click();
        await page.locator('#permissions-modal.show').waitFor();
        await page.locator('#role-name:not([disabled])').waitFor();
        await page.locator('#permissions-loading').waitFor({state: 'hidden'});
        await page.locator('[name="permissions[]"][value="clientes.create"]').uncheck();
        const revokePromise = page.waitForResponse(r => new URL(r.url()).pathname === '/admin/roles/permisos' && r.request().method() === 'PUT');
        await page.locator('#permissions-save').click(); await page.locator('.swal2-confirm').click();
        const revokeResponse = await revokePromise; assert.equal(revokeResponse.status(), 200);
        assert(!(await revokeResponse.json()).roles[0].permissions.includes('clientes.create'), 'Permission was not removed from the role');
        await saved('#permissions-modal');
        assert.equal((await json(employee, '/clientes', 'POST', {name: setup.tag, document: setup.tag+'-2'})).status, 403);
        await employee.locator('form[action$="/logout"] button').click();
        await employee.waitForURL('**/login');
        await login(employee, employeeEmail, setup.password);
        assert.equal(await employee.locator('#catalog-new').count(), 0, 'Revocation did not survive logout');
        assert.equal((await json(employee, '/clientes', 'POST')).status, 403);
        assert.equal((await json(page, `/admin/usuarios/${userId}/estado`, 'PATCH', {is_active: 0})).status, 200);
        assert.equal((await json(employee, '/clientes/datos')).status, 401, 'Disabled active session survived');
        await employee.goto(base+'/login');
        await employee.locator('#email').fill(employeeEmail); await employee.locator('#password').fill(setup.password);
        await employee.locator('button[type=submit]').click();
        await employee.locator('.alert-danger').waitFor();
        assert.equal(new URL(employee.url()).pathname, '/login');
        assert.equal((await json(page, `/admin/usuarios/${userId}/estado`, 'PATCH', {is_active: 1})).status, 200);
        console.log('Edge + MySQL: login/logout, CSRF 419, role/employee modals, effective 403, persistent revocation and disabled sessions passed.');

        const type = await createCatalog('tipos-vehiculo', {name: setup.tag+' Auto'});
        const client = await createCatalog('clientes', {name: setup.tag+' Cliente', document: setup.tag+'-CLIENT'});
        const vehicle = await createCatalog('vehiculos', {plate: setup.tag, client_id: client, vehicle_type_id: type});
        const floor = await createCatalog('pisos', {name: setup.tag+' Piso'});
        const sector = await createCatalog('sectores', {name: setup.tag+' Sector', floor_id: floor});
        const space = await createCatalog('espacios', {code: setup.tag, floor_id: floor, sector_id: sector, vehicle_type_id: type, status: 'available'});
        const rate = await createCatalog('tarifas', {name: setup.tag+' Hora', vehicle_type_id: type, unit: 'hour', amount: '5.25'});
        assert(vehicle);
        await page.goto(base+'/entradas');
        const before = (await json(page, '/entradas/datos')).data.stats;
        await page.locator('#open-entry').click(); await page.locator('#entry-plate').fill(setup.tag); await page.locator('#find-vehicle').click();
        await page.locator('#entry-space').selectOption(String(space)); await page.locator('#entry-rate').selectOption(String(rate));
        const entryPromise = page.waitForResponse(r => r.url().endsWith('/entradas') && r.request().method() === 'POST');
        await page.locator('#entry-form [type=submit]').click();
        const entryResponse = await entryPromise; assert.equal(entryResponse.status(), 201);
        const ticket = (await entryResponse.json()).data;
        await page.locator('#print-modal.show').waitFor();
        assert.match(await page.locator('#print-document').innerText(), new RegExp(setup.tag));
        const occupied = (await json(page, '/entradas/datos')).data.stats;
        assert.equal(occupied.available, before.available-1); assert.equal(occupied.occupied, before.occupied+1);
        assert.equal((await json(page, '/entradas', 'POST', {vehicle_id: vehicle, space_id: space, rate_id: rate})).status, 422);
        await page.goto(base+'/salidas'); await page.locator('#open-search').click();
        await page.locator('#ticket-search').fill(ticket.number); await page.locator('#ticket-search-form [type=submit]').click();
        await page.locator('#checkout-detail:not(.d-none)').waitFor();
        assert.equal(await page.locator('#checkout-total').innerText(), '5.25');
        await page.locator('#checkout-method').selectOption('cash');
        await page.locator('#checkout-form [type=submit]').click(); await page.locator('.swal2-confirm').click();
        await page.locator('#print-modal.show').waitFor();
        assert.match(await page.locator('#print-document').innerText(), /5\.25/);
        const released = (await json(page, '/salidas/datos')).data.stats;
        assert.equal(released.available, before.available); assert.equal(released.occupied, before.occupied);
        assert.equal((await json(page, `/salidas/${ticket.id}/cobrar`, 'POST', {method: 'cash', expected_total: '5.25'})).status, 422);
        await page.goto(base+'/caja'); await page.locator('#cash-search').fill(setup.tag);
        await page.locator('#cash-method').selectOption('cash'); await page.locator('#cash-filters button').click();
        await page.locator('#operations-table tbody tr').filter({hasText: setup.tag}).locator('[data-operation=receipt]').click();
        await page.locator('#print-modal.show').waitFor();
        assert.match(await page.locator('#print-document').innerText(), /5\.25/);
        console.log('Edge + MySQL: customer/vehicle/location/rate modals, entry, ticket, occupancy, cash exit, receipt, release and duplicate rejection passed.');

        await page.goto(base+'/reportes');
        const loaded = () => page.waitForFunction(() => document.querySelector('#report-content')?.getAttribute('aria-busy') === 'false' && !document.querySelector('#report-export').disabled);
        await loaded(); await page.locator('#report-from').fill(setup.today); await page.locator('#report-to').fill(setup.today);
        await page.locator('#report-type').selectOption(String(type)); await page.locator('#report-refresh').click(); await loaded();
        assert.match(await page.locator('#report-metrics .stat-value').first().innerText(), /5[.,]25/);
        for (const format of ['pdf', 'xlsx']) {
            await page.locator('#report-export').click(); await page.locator('#report-export-format').selectOption(format);
            const downloading = page.waitForEvent('download'); await page.locator('#report-export-form [type=submit]').click();
            const download = await downloading;
            const bytes = fs.readFileSync(await download.path());
            assert.equal(bytes.subarray(0, format === 'pdf' ? 5 : 2).toString(), format === 'pdf' ? '%PDF-' : 'PK');
            // Parse the actual browser download, not a separate server request.
            const {spawnSync} = require('node:child_process');
            const checked = spawnSync('php', [path.join(__dirname, 'verify-audit-export.php'), await download.path(), format, setup.tag, '5.25'], {cwd: root, encoding: 'utf8'});
            assert.equal(checked.status, 0, checked.stdout+checked.stderr);
            await page.locator('#report-export-modal').waitFor({state: 'hidden'});
        }
        for (const report of ['vehiculos', 'ocupacion']) {await page.locator(`[data-report="${report}"]`).click(); await loaded(); assert.equal(await page.locator('#report-charts canvas').count(), 1);}
        assert.deepEqual(errors, []); assert(mutations > 15);
        console.log('Edge + MySQL: report filters, charts and downloaded PDF/XLSX content matched the confirmed payment; no JavaScript errors.');
    } catch (error) {failure = error;}
    finally {
        await browser.close(); await new Promise(resolve => server.close(resolve));
        bridge.stdin.write(JSON.stringify({command: 'finish'})+'\n');
        const result = await completePromise;
        assert(result.finished && result.preserved, 'Existing MySQL records changed'); bridge.stdin.end();
    }
    if (failure) throw failure;
})().catch(error => {console.error(error.stack); process.exitCode = 1; bridge.kill();});
