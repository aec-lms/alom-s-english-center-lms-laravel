const SHELL_CACHE = 'aec-attendance-shell-v1';
const PAGE_CACHE = 'aec-attendance-private-page-v1';
const ATTENDANCE_PATHS = new Set(['/admin/attendance', '/admin/attendance/']);
const SHELL_ASSETS = [
    '/js/attendance-offline.js',
    '/css/attendance-offline.css',
    '/attendance.webmanifest',
    '/attendance-offline.html',
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(SHELL_CACHE)
            .then(cache => cache.addAll(SHELL_ASSETS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(names
            .filter(name => name.startsWith('aec-attendance-') && ![SHELL_CACHE, PAGE_CACHE].includes(name))
            .map(name => caches.delete(name)));
        await self.clients.claim();
    })());
});

const isAttendanceUrl = value => {
    const url = new URL(value, self.location.origin);
    return url.origin === self.location.origin && ATTENDANCE_PATHS.has(url.pathname);
};

const cacheAttendancePage = async url => {
    if (!isAttendanceUrl(url)) return false;
    const response = await fetch(url, {
        method: 'GET',
        credentials: 'include',
        cache: 'no-store',
        headers: { Accept: 'text/html' },
    });
    const finalUrl = new URL(response.url || url, self.location.origin);
    const contentType = response.headers.get('content-type') || '';
    if (!response.ok || !contentType.includes('text/html') || !ATTENDANCE_PATHS.has(finalUrl.pathname)) {
        return false;
    }
    const cache = await caches.open(PAGE_CACHE);
    await cache.put(new URL('/admin/attendance/', self.location.origin).href, response.clone());
    return true;
};

self.addEventListener('message', event => {
    const message = event.data || {};
    if (message.type === 'CACHE_ATTENDANCE_PAGE' && isAttendanceUrl(message.url)) {
        event.waitUntil(cacheAttendancePage(message.url).catch(() => false));
    }
    if (message.type === 'CLEAR_PRIVATE_ATTENDANCE_CACHE') {
        event.waitUntil(caches.delete(PAGE_CACHE));
    }
});

self.addEventListener('fetch', event => {
    const request = event.request;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (request.method === 'POST' && /\/logout\/?$/.test(url.pathname)) {
        event.respondWith(fetch(request).then(async response => {
            if (response.ok) await caches.delete(PAGE_CACHE);
            return response;
        }));
        return;
    }

    if (request.method !== 'GET') return;

    if (request.mode === 'navigate' && ATTENDANCE_PATHS.has(url.pathname)) {
        event.respondWith((async () => {
            try {
                const response = await fetch(request);
                const finalUrl = new URL(response.url || request.url);
                const contentType = response.headers.get('content-type') || '';
                if (response.ok && contentType.includes('text/html') && ATTENDANCE_PATHS.has(finalUrl.pathname)) {
                    const cache = await caches.open(PAGE_CACHE);
                    await cache.put(new URL('/admin/attendance/', self.location.origin).href, response.clone());
                }
                return response;
            } catch (error) {
                const pageCache = await caches.open(PAGE_CACHE);
                const cachedPage = await pageCache.match(new URL('/admin/attendance/', self.location.origin).href);
                if (cachedPage) return cachedPage;
                const shell = await caches.open(SHELL_CACHE);
                return (await shell.match('/attendance-offline.html')) || new Response(
                    'Attendance is not available offline yet. Open this page once while connected to the internet.',
                    { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } }
                );
            }
        })());
        return;
    }

    if (SHELL_ASSETS.includes(url.pathname)) {
        event.respondWith((async () => {
            const cache = await caches.open(SHELL_CACHE);
            const cached = await cache.match(request);
            if (cached) return cached;
            try {
                const response = await fetch(request);
                if (response.ok) await cache.put(request, response.clone());
                return response;
            } catch (error) {
                return new Response('', { status: 503 });
            }
        })());
    }
});
