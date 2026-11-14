// End-to-end browser check against real Laravel/MySQL through the transaction bridge.
// No HTTP server or persistent fixture rows are needed.
const {chromium} = require(process.env.PARKING_PLAYWRIGHT_PATH || 'playwright-core');
const {spawn} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const readline = require('node:readline');

const root = path.resolve(__dirname, '..');
const bridge = spawn('php', [path.join(__dirname, 'mysql-browser-bridge.php')], {cwd: root, stdio: ['pipe', 'pipe', 'pipe']});
const pending = new Map();
let ready, complete, nextId = 0;
let stderr = '';
bridge.stderr.on('data', chunk => {stderr += chunk.toString();});
const readyPromise = new Promise(resolve => {ready = resolve;});
const completePromise = new Promise(resolve => {complete = resolve;});
readline.createInterface({input: bridge.stdout}).on('line', line => {
    const message = JSON.parse(line);
    if (message.type === 'ready' || message.type === 'error') return ready(message);
    if (message.type === 'complete') return complete(message);
    const resolve = pending.get(message.id);
    if (resolve) {pending.delete(message.id); resolve(message);}
});
function bridgeCall(data) {
    return new Promise(resolve => {
        const id = ++nextId; pending.set(id, resolve);
        bridge.stdin.write(JSON.stringify({id, ...data})+'\n');
    });
}
const assets = pathname => path.join(root, 'public', pathname);

(async () => {
    const setup = await readyPromise;
    if (setup.type === 'error') throw new Error(setup.message);
    const browser = await chromium.launch({executablePath: process.env.PARKING_BROWSER_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true});
    const page = await browser.newPage({viewport: {width: 1360, height: 900}});
    const pageErrors = [];
    let navigations = 0;
    page.on('pageerror', error => pageErrors.push(error.message));
    page.on('framenavigated', frame => {if (frame === page.mainFrame()) navigations++;});
    await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.pathname.startsWith('/build/assets/')) {
            return route.fulfill({status: 200, contentType: url.pathname.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(assets(url.pathname))});
        }
        const response = await bridgeCall({url: url.pathname+url.search, method: route.request().method(), body: route.request().postData() || '', accept: route.request().headers().accept || 'text/html', ajax: route.request().headers()['x-requested-with'] || ''});
        const json = (route.request().headers().accept || '').includes('application/json');
        return route.fulfill({status: response.status, contentType: json ? 'application/json' : 'text/html', body: response.body});
    });
    let failure;
    try {
        await page.goto('http://localhost/entradas');
        await page.locator('#open-entry').click();
        await page.locator('#entry-plate').fill(setup.plate);
        await page.locator('#find-vehicle').click();
        await page.waitForFunction(() => document.querySelector('#vehicle-result').textContent.includes('no encontrado'));
        await page.locator('#new-vehicle').click();
        await page.locator('#vehicle-modal.show').waitFor();
        await page.locator('#new-type').selectOption(String(setup.type_id));
        await page.locator('#operation-vehicle-form [type=submit]').click();
        await page.locator('#entry-modal.show').waitFor();
        await page.locator('#entry-space').selectOption(String(setup.space_id));
        await page.locator('#entry-rate').selectOption(String(setup.rate_id));
        await page.locator('#entry-form [type=submit]').click();
        await page.locator('#print-modal.show').waitFor();
        assert((await page.locator('#print-document').innerText()).includes(setup.plate));
        assert.equal(navigations, 1, 'Entry caused a page reload');
        const ticket = await page.locator('#operations-table tbody tr').first().locator('td').first().innerText();
        assert(ticket.startsWith('E-'));
        await page.goto('http://localhost/salidas');
        await page.locator('#open-search').click();
        await page.locator('#ticket-search').fill(ticket);
        await page.locator('#ticket-search-form [type=submit]').click();
        await page.locator('#checkout-detail:not(.d-none)').waitFor();
        assert.equal(await page.locator('#checkout-total').innerText(), '5.25');
        await page.locator('#checkout-method').selectOption('qr');
        await page.locator('[name=qr_confirmed]').check();
        await page.locator('#checkout-reference').fill('WEB-QR-CHECK');
        await page.locator('#checkout-form [name=expected_total]').evaluate(input => {input.value = '0.00';});
        await page.locator('#checkout-form [type=submit]').click();
        await page.locator('.swal2-confirm').click();
        await page.locator('#checkout-form [data-form-errors]:not(.d-none)').waitFor();
        await page.locator('#refresh-quote').click();
        await page.locator('#checkout-detail:not(.d-none)').waitFor();
        await page.locator('#checkout-method').selectOption('qr');
        await page.locator('[name=qr_confirmed]').check();
        await page.locator('#checkout-form [type=submit]').click();
        await page.locator('.swal2-confirm').click();
        await page.locator('#print-modal.show').waitFor();
        assert((await page.locator('#print-document').innerText()).includes('5.25'));
        await page.waitForFunction(() => document.querySelector('[data-stat=available]').textContent !== '—');
        assert.equal(navigations, 2, 'Checkout caused a page reload');
        await page.goto('http://localhost/caja');
        await page.locator('#cash-search').fill(setup.plate);
        await page.locator('#cash-method').selectOption('qr');
        await page.locator('#cash-filters button').click();
        await page.locator('#operations-table').getByText(setup.plate).waitFor();
        await page.locator('#operations-table tbody tr').filter({hasText: setup.plate}).locator('[data-operation=receipt]').click();
        await page.locator('#print-modal.show').waitFor();
        assert.equal(navigations, 3, 'Cash search caused a page reload');
        assert.deepEqual(pageErrors, [], 'Browser JavaScript errors');
        const tickets = await bridgeCall({url: '/tickets/datos', method: 'GET', accept: 'application/json'});
        assert.equal(tickets.status, 200);
        const ticketId = JSON.parse(tickets.body).data.find(row => row.number === ticket)?.id;
        assert(ticketId);
        const ticketPrint = await bridgeCall({url: `/tickets/${ticketId}/imprimir`, method: 'GET', accept: 'application/json'});
        assert.equal(ticketPrint.status, 200);
        await bridgeCall({command: 'role', role: 'operador'});
        const forbidden = await bridgeCall({url: '/entradas', method: 'POST', body: `vehicle_id=1&space_id=${setup.space_id}&rate_id=${setup.rate_id}`, accept: 'application/json', ajax: 'XMLHttpRequest'});
        assert.equal(forbidden.status, 403, 'Spatie denied action check');
        await bridgeCall({command: 'role', role: 'administrador'});
        console.log('Edge + MySQL: vehicle modal, entry, ticket, server quote, inline 422, manual QR, release, cash filters, receipt and Spatie 403 passed without page reloads.');
    } catch (error) {failure = error;}
    finally {
        await browser.close();
        bridge.stdin.write(JSON.stringify({command: 'finish'})+'\n');
        const finished = await completePromise;
        assert(finished.rolled_back && finished.finished, 'The MySQL browser transaction did not roll back cleanly');
        bridge.stdin.end();
    }
    if (failure) throw failure;
})().catch(error => {console.error(error.message, stderr); process.exitCode = 1; bridge.kill();});
