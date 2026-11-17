const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const fixture = fs.readFileSync(path.join(__dirname, 'fixtures/attendance-offline.html'));

(async () => {
    const server = http.createServer((req, res) => {
        if (req.url === '/attendance-offline.js') {
            res.writeHead(200, { 'content-type': 'text/javascript; charset=utf-8' });
            return fs.createReadStream(path.join(root, 'public/js/attendance-offline.js')).pipe(res);
        }
        if (req.url === '/' || req.url === '/index.html') {
            res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
            return res.end(fixture);
        }
        res.writeHead(404);
        res.end('Not found');
    });

    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const address = server.address();
    const browser = await chromium.launch({ headless: true });
    try {
        const context = await browser.newContext();
        const page = await context.newPage();
        let syncAttempts = 0;
        let capturedPayload = null;

        await page.route('**/admin/attendance/sync', async route => {
            syncAttempts++;
            capturedPayload = route.request().postDataJSON();
            if (syncAttempts === 1) {
                return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Temporary sync outage' }) });
            }
            return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ status: 'ok', synced: 1, duplicates: 0 }) });
        });

        await page.goto('http://127.0.0.1:' + address.port + '/');
        await context.setOffline(true);
        await page.locator('#attendance-register-form button[type="submit"]').click();
        await page.getByRole('status').filter({ hasText: 'Attendance safely saved on this device' }).waitFor();
        assert.equal(await page.evaluate(() => navigator.onLine), false);

        await context.setOffline(false);
        await page.getByRole('status').filter({ hasText: 'Sync paused: Temporary sync outage' }).waitFor();
        assert.equal(syncAttempts, 1);

        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await page.getByRole('status').filter({ hasText: 'Attendance synchronized successfully.' }).waitFor();
        assert.equal(syncAttempts, 2);
        assert.equal(capturedPayload.source, 'offline');
        assert.equal(capturedPayload.records.length, 1);
        assert.equal(capturedPayload.records[0].student_id, 91);
        assert.match(capturedPayload.records[0].sync_uuid, /^[0-9a-f-]{36}$/i);

        const pending = await page.evaluate(() => new Promise((resolve, reject) => {
            const request = indexedDB.open('aec-lms-attendance-offline', 1);
            request.onsuccess = () => {
                const db = request.result;
                const read = db.transaction('pending-sync', 'readonly').objectStore('pending-sync').getAll();
                read.onsuccess = () => { db.close(); resolve(read.result); };
                read.onerror = () => reject(read.error);
            };
            request.onerror = () => reject(request.error);
        }));
        assert.equal(pending.length, 0);
        console.log('Attendance offline browser E2E passed: local queue, retry, idempotency key, and queue cleanup.');
        await context.close();
    } finally {
        await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
