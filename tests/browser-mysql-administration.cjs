// Real browser requests handled by Laravel/MySQL inside a transaction that is reverted.
const {chromium} = require(process.env.PARKING_PLAYWRIGHT_PATH || 'playwright-core');
const {spawn} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const readline = require('node:readline');
const root = path.resolve(__dirname, '..');
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
    const errors = []; let navigations = 0;
    page.on('pageerror', error => errors.push(error.message));
    page.on('framenavigated', frame => {if (frame === page.mainFrame()) navigations++;});
    await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.pathname.startsWith('/build/assets/')) return route.fulfill({status: 200, contentType: url.pathname.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(path.join(root, 'public', url.pathname))});
        const response = await call({url: url.pathname+url.search, method: request.method(), body: request.postData() || '', content_type: request.headers()['content-type'], accept: request.headers().accept || 'text/html', ajax: request.headers()['x-requested-with'] || ''});
        await route.fulfill({status: response.status, contentType: response.content_type || 'application/json', body: response.body});
    });
    const confirm = async () => {await page.locator('.swal2-confirm').click();};
    const saved = async modal => {
        await page.locator(modal).waitFor({state: 'hidden'});
        await page.locator('.swal2-container').waitFor({state: 'hidden'});
    };
    const artifacts = path.join(root, 'storage/framework/testing/admin-ui'); fs.mkdirSync(artifacts, {recursive: true});
    let failure;
    try {
        await page.goto('http://localhost/admin/roles');
        await page.locator('#role-new').click();
        await page.locator('#permissions-modal.show').waitFor();
        await page.locator('#role-name').fill('Administrador');
        await page.locator('#permissions-save').click(); await confirm();
        await page.locator('#permissions-feedback:not(.d-none)').waitFor();
        assert.match(await page.locator('#permissions-feedback').innerText(), /existe/);
        await page.locator('#role-name').fill(setup.tag);
        const group = page.locator('[data-permission-group]').filter({has: page.locator('#permissions-heading-gestion')});
        await group.locator('[data-select-permissions=all]').click();
        assert.equal(await group.locator('input:checked').count(), await group.locator('input').count());
        await group.locator('[data-select-permissions=none]').click();
        assert.equal(await group.locator('input:checked').count(), 0);
        for (const permission of ['clientes.view', 'clientes.create', 'vehiculos.view']) await page.locator(`[name="permissions[]"][value="${permission}"]`).check();
        await page.screenshot({path: path.join(artifacts, 'role-modal.png')});
        const creatingRole = page.waitForResponse(response => response.url().endsWith('/admin/roles') && response.request().method() === 'POST');
        await page.locator('#permissions-save').click(); await confirm();
        const roleResponse = await creatingRole; assert.equal(roleResponse.status(), 201);
        const roleId = (await roleResponse.json()).roles[0].id;
        await saved('#permissions-modal');
        const roleButton = page.locator(`[data-role-permissions="${roleId}"]`);
        await roleButton.click();
        await page.locator('#role-name:not([disabled])').waitFor();
        await page.locator('#role-name').fill(setup.tag+' Editado');
        await page.locator('[name="permissions[]"][value="clientes.create"]').uncheck();
        await page.locator('#permissions-save').click(); await confirm(); await saved('#permissions-modal');
        assert.equal(await page.locator(`[data-permission-count="${roleId}"]`).innerText(), '2');
        assert.equal(navigations, 1);
        await page.goto('http://localhost/admin/usuarios');
        await page.locator('#user-new').click();
        await page.locator('#user-modal.show').waitFor();
        const email = setup.tag.toLowerCase()+'@example.test';
        await page.locator('#user-name').fill(setup.tag+' Empleado');
        await page.locator('#user-email').fill(email);
        await page.locator('#user-password').fill('Browser-test-123');
        await page.locator('#user-password-confirmation').fill('Browser-test-123');
        await page.locator('#user-role').selectOption(String(roleId));
        await page.setViewportSize({width: 390, height: 844});
        await page.screenshot({path: path.join(artifacts, 'employee-modal-mobile.png')});
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth+1));
        const creatingUser = page.waitForResponse(response => response.url().endsWith('/admin/usuarios') && response.request().method() === 'POST');
        await page.locator('#user-save').click(); await confirm();
        const userResponse = await creatingUser; assert.equal(userResponse.status(), 201);
        const userId = (await userResponse.json()).id;
        await saved('#user-modal');
        await page.setViewportSize({width: 1360, height: 900});
        await page.locator('#users-table_wrapper input[type=search]').fill(email);
        const row = page.locator('#users-table tbody tr').filter({hasText: email});
        await row.locator('[data-user-action=edit]').click();
        await page.locator('#user-modal.show').waitFor();
        assert(await page.locator('#user-password').isDisabled());
        assert.equal(await page.locator('#user-role').inputValue(), String(roleId));
        const existingRole = await page.locator('#user-role option').filter({hasText: 'Supervisor'}).getAttribute('value');
        await page.locator('#user-role').selectOption(existingRole);
        await page.locator('#user-save').click(); await confirm(); await saved('#user-modal');
        assert.match(await row.innerText(), /Supervisor/);
        for (const label of ['Desactivar', 'Activar']) {
            await row.getByRole('button', {name: label, exact: true}).click(); await confirm();
            await page.locator('.swal2-container').waitFor({state: 'hidden'});
            await row.getByRole('button', {name: label === 'Desactivar' ? 'Activar' : 'Desactivar', exact: true}).waitFor();
        }
        assert.equal(navigations, 2, 'Administration mutations reloaded the page');
        const data = await call({url: '/admin/usuarios/datos', method: 'GET', accept: 'application/json'});
        const employee = JSON.parse(data.body).data.find(item => item.id === userId);
        assert.equal(employee.is_active, true); assert.equal(employee.role_label, 'Supervisor');
        assert.deepEqual(errors, []);
        console.log('Edge + MySQL: role creation, duplicate 422, group selection, rename/revoke, employee creation, role change, deactivate/reactivate, mobile modals and AJAX without page reloads passed.');
    } catch (error) {failure = error;}
    finally {
        await browser.close(); bridge.stdin.write(JSON.stringify({command: 'finish'})+'\n');
        const result = await completePromise;
        assert(result.rolled_back && result.finished, 'Existing users/roles/permissions were not restored'); bridge.stdin.end();
    }
    if (failure) throw failure;
})().catch(error => {console.error(error.stack, stderr); process.exitCode = 1; bridge.kill();});
