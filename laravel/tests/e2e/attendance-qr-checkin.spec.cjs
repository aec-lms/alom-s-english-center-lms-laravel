const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const script = fs.readFileSync(path.join(root, 'public/js/attendance-qr-checkin.js'));
const captured = [];

const fixture = token => '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="csrf-token" content="qr-e2e-token"><title>AEC QR Attendance</title></head><body>' +
    '<main id="attendance-qr-checkin" data-token="' + token + '" data-endpoint="/attendance/qr/check-in">' +
    '<label><input type="checkbox" id="gps-consent"> I consent to one-time GPS verification.</label>' +
    '<button id="checkin" type="button">Check in</button><div id="result" role="status" aria-live="polite">Ready</div>' +
    '</main><script src="/js/attendance-qr-checkin.js" defer></script></body></html>';

const server = http.createServer((req, res) => {
    if (req.url.startsWith('/?token=')) {
        const token = new URL(req.url, 'http://127.0.0.1').searchParams.get('token');
        res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
        return res.end(fixture(token));
    }
    if (req.url === '/js/attendance-qr-checkin.js') {
        res.writeHead(200, { 'content-type': 'text/javascript; charset=utf-8' });
        return res.end(script);
    }
    if (req.url === '/attendance/qr/check-in' && req.method === 'POST') {
        let body = '';
        req.on('data', chunk => { body += chunk; });
        req.on('end', () => {
            const payload = JSON.parse(body);
            captured.push(payload);
            if (payload.token === 'e'.repeat(64)) {
                res.writeHead(410, { 'content-type': 'application/json' });
                return res.end(JSON.stringify({ message: 'QR session has expired.' }));
            }
            if (payload.token === 'r'.repeat(64)) {
                res.writeHead(409, { 'content-type': 'application/json' });
                return res.end(JSON.stringify({ message: 'This QR session has already been used by this student.' }));
            }
            res.writeHead(200, { 'content-type': 'application/json' });
            return res.end(JSON.stringify({ status: 'ok', checked_in_at: '2026-11-17T10:00:00+06:00' }));
        });
        return;
    }
    res.writeHead(404);
    res.end('Not found');
});

(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const browser = await chromium.launch({ headless: true });
    try {
        const context = await browser.newContext({ permissions: ['geolocation'], geolocation: { latitude: 23.8103, longitude: 90.4125, accuracy: 5 } });
        const page = await context.newPage();
        const origin = 'http://127.0.0.1:' + server.address().port;

        await page.goto(origin + '/?token=' + 'v'.repeat(64));
        await page.locator('#gps-consent').check();
        await page.locator('#checkin').click();
        await page.getByRole('status').filter({ hasText: 'Attendance confirmed at' }).waitFor();
        assert.equal(captured[0].gps_consent, true);
        assert.equal(captured[0].latitude, 23.8103);
        assert.equal(captured[0].longitude, 90.4125);
        assert.equal(captured[0].gps_accuracy_meters, 5);

        await page.goto(origin + '/?token=' + 'r'.repeat(64));
        await page.locator('#checkin').click();
        await page.getByRole('status').filter({ hasText: 'already been used' }).waitFor();
        assert.equal(await page.locator('#checkin').isEnabled(), true);

        await page.goto(origin + '/?token=' + 'e'.repeat(64));
        await page.locator('#checkin').click();
        await page.getByRole('status').filter({ hasText: 'expired' }).waitFor();
        assert.equal(await page.locator('#checkin').isEnabled(), true);

        console.log('Attendance QR browser E2E passed: GPS consent payload, replay rejection and expiry handling.');
        await context.close();
    } finally {
        await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
