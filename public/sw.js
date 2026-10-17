/*
 * FUN-19: the service worker that makes the web app installable and usable on a weak connection.
 *
 *  - App files (scripts, styles, fonts, images: versioned by the build) come from the cache first.
 *  - Pages: the network first; offline, the app shell last seen, or offline.html.
 *  - Data: never cached, except the hearing-day views (/api/v1/today and /api/v1/court-diary), which are kept for
 *    offline reading and wiped when someone signs in or out (the app posts "signed-in" / "signed-out").
 *  - Client-facing pages (portal, payment, signing), feeds, callbacks and every other API call are not touched.
 */
const VERSION = 'v1';
const SHELL = `shell-${VERSION}`;
const ASSETS = `assets-${VERSION}`;
const DATA = `data-${VERSION}`;
const OFFLINE_DATA = /^\/api\/v1\/(today|court-diary)(\?|$)/;
const NOT_OURS = /^\/(api|portal|pay|sign|calendar|sanctum|login|logout|platform|signup|health|status|storage)(\/|$|\?)|\.ics$/;

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(SHELL).then((c) => c.addAll(['/offline.html', '/images/pwa/icon-192.png'])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys()
        .then((keys) => Promise.all(keys.filter((k) => ![SHELL, ASSETS, DATA].includes(k)).map((k) => caches.delete(k))))
        .then(() => self.clients.claim()));
});

self.addEventListener('message', (event) => {
    if (event.data === 'signed-out' || event.data === 'signed-in') {
        event.waitUntil(Promise.all([caches.delete(DATA), caches.open(SHELL).then((c) => c.delete('/'))]));
    }
});

const isAsset = (url) => /^\/(build|js|css|fonts|images)\//.test(url.pathname) || url.pathname === '/favicon.ico';

self.addEventListener('fetch', (event) => {
    const req = event.request;
    const url = new URL(req.url);
    if (req.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (OFFLINE_DATA.test(url.pathname + url.search)) {
        event.respondWith(fetch(req).then((res) => {
            if (res.ok) {
                const copy = res.clone();
                caches.open(DATA).then((c) => c.put(req, copy));
            }
            return res;
        }).catch(() => caches.open(DATA).then((c) => c.match(req)).then((hit) => hit || Response.error())));
        return;
    }

    if (isAsset(url)) {
        event.respondWith(caches.open(ASSETS).then((c) => c.match(req).then((hit) => hit || fetch(req).then((res) => {
            if (res.ok) c.put(req, res.clone());
            return res;
        }))));
        return;
    }

    if (req.mode === 'navigate' && !NOT_OURS.test(url.pathname)) {
        event.respondWith(fetch(req).then((res) => {
            if (res.ok && res.headers.get('content-type')?.includes('text/html')) {
                const copy = res.clone();
                caches.open(SHELL).then((c) => c.put('/', copy));   // every app route serves the same shell
            }
            return res;
        }).catch(() => caches.open(SHELL).then((c) => c.match('/').then((hit) => hit || c.match('/offline.html')))));
    }
});
