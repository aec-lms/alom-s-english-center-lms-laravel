const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'aec-attendance-pwa-'));
const assets = {
    '/js/attendance-offline.js': ['public/js/attendance-offline.js', 'text/javascript; charset=utf-8'],
    '/service-worker.js': ['public/service-worker.js', 'text/javascript; charset=utf-8'],
    '/css/attendance-offline.css': ['public/css/attendance-offline.css', 'text/css; charset=utf-8'],
    '/attendance.webmanifest': ['public/attendance.webmanifest', 'application/manifest+json; charset=utf-8'],
    '/attendance-offline.html': ['public/attendance-offline.html', 'text/html; charset=utf-8'],
    '/images/attendance-pwa.svg': ['public/images/attendance-pwa.svg', 'image/svg+xml'],
};
const fixture = `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="csrf-token" content="pwa-test-token"><meta name="viewport" content="width=device-width, initial-scale=1"><title>AEC Attendance PWA Test</title><link rel="manifest" href="/attendance.webmanifest"><link rel="stylesheet" href="/css/attendance-offline.css"></head>
<body>
<div id="attendance-sync-status" role="status" aria-live="polite">Online sync ready.</div>
<form id="attendance-register-form" data-actor-id="42" data-sync-url="/admin/attendance/sync">
<select id="attendance-batch" name="batch_id"><option value="17" data-branch="4" selected>Test Batch</option></select>
<input type="date" name="attendance_date" value="2026-11-17">
<table><tbody><tr data-student-branch="4"><td><input type="hidden" name="records[0][student_id]" value="91"></td><td><select name="records[0][status]"><option value="present" selected>Present</option><option value="absent">Absent</option><option value="late">Late</option><option value="excused">Excused</option></select></td><td><input name="records[0][remarks]" value="saved after restart"></td><td><input name="records[0][edit_reason]" value=""></td></tr></tbody></table>
<button type="submit">Save attendance</button></form>
<script src="/js/attendance-offline.js" defer></script>
</body></html>`;

const server = http.createServer((req, res) => {
    if (req.url === '/admin/attendance/' || req.url === '/admin/attendance') {
        res.writeHead(200, { 'content-type': 'text/html; charset=utf-8', 'cache-control': 'no-store' });
        return res.end(fixture);
    }
    if (assets[req.url]) {
        const [relative, contentType] = assets[req.url];
        res.writeHead(200, { 'content-type': contentType });
        return fs.createReadStream(path.join(root, relative)).pipe(res);
    }
    if (req.url === '/admin/attendance/sync') {
        res.writeHead(200, { 'content-type': 'application/json' });
        return res.end(JSON.stringify({ status: 'ok', synced: 1, duplicates: 0 }));
    }
    res.writeHead(404);
    res.end('Not found');
});

(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = 'http://127.0.0.1:' + server.address().port;
    let context;
    try {
        context = await chromium.launchPersistentContext(profile, { headless: true, serviceWorkers: 'allow' });
        let page = await context.newPage();
        await page.goto(origin + '/admin/attendance/');
        await page.waitForFunction(() => navigator.serviceWorker.controller !== null);
        await page.waitForFunction(async () => {
            const cache = await caches.open('aec-attendance-private-page-v1');
            return Boolean(await cache.match(location.origin + '/admin/attendance/'));
        });
        await context.close();

        // Simulate closing the browser and reopening the same installed PWA/profile without internet.
        context = await chromium.launchPersistentContext(profile, { headless: true, serviceWorkers: 'allow' });
        await context.setOffline(true);
        page = await context.newPage();
        await page.goto(origin + '/admin/attendance/');
        assert.equal(await page.title(), 'AEC Attendance PWA Test');
        assert.equal(await page.evaluate(() => navigator.onLine), false);
        await page.locator('#attendance-register-form button[type="submit"]').click();
        await page.getByRole('status').filter({ hasText: 'Attendance safely saved on this device' }).waitFor();

        const pendingAfterSave = await page.evaluate(() => new Promise((resolve, reject) => {
            const request = indexedDB.open('aec-lms-attendance-offline', 1);
            request.onsuccess = () => {
                const db = request.result;
                const read = db.transaction('pending-sync', 'readonly').objectStore('pending-sync').getAll();
                read.onsuccess = () => { db.close(); resolve(read.result); };
                read.onerror = () => reject(read.error);
            };
            request.onerror = () => reject(request.error);
        }));
        assert.equal(pendingAfterSave.length, 1);
        assert.equal(pendingAfterSave[0].actor_id, '42');
        assert.equal(pendingAfterSave[0].payload.source, 'offline');
        assert.equal(pendingAfterSave[0].payload.records[0].student_id, 91);
        await context.close();

        // A second restart must still load the cached register and retain the queued attendance.
        context = await chromium.launchPersistentContext(profile, { headless: true, serviceWorkers: 'allow' });
        await context.setOffline(true);
        page = await context.newPage();
        await page.goto(origin + '/admin/attendance/');
        assert.equal(await page.title(), 'AEC Attendance PWA Test');
        const queued = await page.evaluate(() => new Promise((resolve, reject) => {
            const request = indexedDB.open('aec-lms-attendance-offline', 1);
            request.onsuccess = () => {
                const db = request.result;
                const read = db.transaction('pending-sync', 'readonly').objectStore('pending-sync').getAll();
                read.onsuccess = () => { db.close(); resolve(read.result); };
                read.onerror = () => reject(read.error);
            };
            request.onerror = () => reject(request.error);
        }));
        assert.equal(queued.length, 1);
        assert.equal(queued[0].actor_id, '42');
        console.log('Attendance PWA E2E passed: service-worker page cache and IndexedDB attendance survive browser restarts offline.');
    } finally {
        if (context) await context.close();
        await new Promise(resolve => server.close(resolve));
        fs.rmSync(profile, { recursive: true, force: true });
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
