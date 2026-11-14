// Real Edge -> Laravel HTTP kernel -> MySQL. Fixtures exist only inside a transaction.
const {chromium} = require(process.env.PARKING_PLAYWRIGHT_PATH || 'playwright-core');
const {spawn} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const readline = require('node:readline');
const root = path.resolve(__dirname, '..');
const bridge = spawn('php', [path.join(__dirname, 'mysql-browser-bridge.php'), '--reports'], {cwd: root});
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
    return new Promise(resolve => {
        const id = ++nextId; pending.set(id, resolve);
        bridge.stdin.write(JSON.stringify({id, ...data})+'\n');
    });
}
(async () => {
    const setup = await readyPromise;
    assert.equal(setup.type, 'ready', setup.message);
    const browser = await chromium.launch({executablePath: process.env.PARKING_BROWSER_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true});
    const page = await browser.newPage({viewport: {width: 1360, height: 900}, acceptDownloads: true});
    const errors = []; let navigations = 0;
    page.on('pageerror', error => errors.push(error.message));
    page.on('framenavigated', frame => {if (frame === page.mainFrame()) navigations++;});
    await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.pathname.startsWith('/build/assets/')) return route.fulfill({status: 200, contentType: url.pathname.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(path.join(root, 'public', url.pathname))});
        const response = await call({url: url.pathname+url.search, method: request.method(), body: request.postData() || '', accept: request.headers().accept || 'text/html', ajax: request.headers()['x-requested-with'] || ''});
        await route.fulfill({status: response.status, contentType: response.content_type || 'application/json', body: response.body_base64 ? Buffer.from(response.body_base64, 'base64') : response.body});
    });
    const loaded = () => page.waitForFunction(() => document.querySelector('#report-content').getAttribute('aria-busy') === 'false' && !document.querySelector('#report-export').disabled);
    async function refresh() {await page.locator('#report-refresh').click(); await loaded();}
    async function tab(name) {await page.locator(`[data-report="${name}"]`).click(); await loaded();}
    let failure;
    try {
        await page.goto('http://localhost/reportes'); await loaded();
        await page.locator('#report-from').fill('2026-11-01');
        await page.locator('#report-to').fill('2026-11-03');
        await page.locator('#report-type').selectOption(String(setup.type_id));
        await refresh();
        assert.match(await page.locator('#report-metrics').innerText(), /10[.,]30/);
        assert.equal(await page.locator('#report-charts canvas').count(), 3);
        assert(await page.locator('#report-charts canvas').first().evaluate(canvas => canvas.width > 0 && canvas.height > 0));
        await page.locator('[data-report-detail]').first().click();
        await page.locator('#report-detail-modal.show').waitFor();
        assert.match(await page.locator('#report-detail-body').innerText(), new RegExp(setup.plate));
        await page.locator('#report-detail-modal .modal-footer button').click();
        await page.locator('#report-detail-modal').waitFor({state: 'hidden'});
        for (const format of ['pdf', 'xlsx']) {
            await page.locator('#report-export').click();
            await page.locator('#report-export-modal.show').waitFor();
            await page.locator('#report-export-format').selectOption(format);
            const downloading = page.waitForEvent('download');
            await page.locator('#report-export-form [type=submit]').click();
            const download = await downloading;
            const bytes = fs.readFileSync(await download.path());
            assert(bytes.length > 1000);
            assert.equal(bytes.subarray(0, format === 'pdf' ? 5 : 2).toString(), format === 'pdf' ? '%PDF-' : 'PK');
            await page.locator('#report-export-modal').waitFor({state: 'hidden'});
        }
        await page.locator('#report-method').selectOption(String(setup.qr_id)); await refresh();
        assert.match(await page.locator('#report-metrics .stat-value').first().innerText(), /0[.,]20/);
        await tab('vehiculos');
        assert.equal(await page.locator('#report-method').isEnabled(), false);
        assert.match(await page.locator('#report-table').innerText(), new RegExp(setup.plate));
        await page.locator('[data-report-detail]').first().click();
        await page.locator('#report-detail-modal.show').waitFor();
        assert.equal(await page.locator('#report-detail-table tbody tr').count(), 3);
        await page.locator('#report-detail-modal .modal-footer button').click();
        await page.locator('#report-detail-modal').waitFor({state: 'hidden'});
        await tab('ocupacion');
        assert.equal(await page.locator('#report-charts canvas').count(), 1);
        assert.equal(await page.locator('#report-table tbody tr').count(), 3);
        await page.setViewportSize({width: 390, height: 844});
        await page.waitForFunction(() => document.documentElement.scrollWidth <= window.innerWidth + 1, null, {timeout: 5000}).catch(async error => {
            console.log(await page.evaluate(() => [...document.querySelectorAll('body *')].filter(el => el.getBoundingClientRect().right > innerWidth + 1).map(el => ({tag: el.tagName, id: el.id, cls: el.className, width: el.getBoundingClientRect().width})).slice(0, 25)));
            throw error;
        });
        await page.locator('#report-from').fill('2026-09-01');
        await page.locator('#report-to').fill('2026-09-02');
        await tab('vehiculos');
        assert.match(await page.locator('#report-table').innerText(), /Sin registros/);
        await page.locator('#report-from').fill('2026-09-03');
        await page.locator('#report-refresh').click();
        await page.locator('#report-errors:not(.d-none)').waitFor();
        assert(await page.locator('#report-export').isDisabled());
        assert.equal(navigations, 1, 'Report actions reloaded the page');
        assert.deepEqual(errors, []);
        console.log('Edge + MySQL: tabs, filters, 3 income charts, vehicle chart, occupancy chart, detail modals, PDF/XLSX downloads, mobile layout, empty period and inline 422 passed without reloading.');
    } catch (error) {failure = error;}
    finally {
        await browser.close();
        bridge.stdin.write(JSON.stringify({command: 'finish'})+'\n');
        const result = await completePromise;
        assert(result.rolled_back && result.finished, 'MySQL transaction was not restored');
        bridge.stdin.end();
    }
    if (failure) throw failure;
})().catch(error => {console.error(error.stack, stderr); process.exitCode = 1; bridge.kill();});
