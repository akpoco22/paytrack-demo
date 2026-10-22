// PayTrack Service Worker
const CACHE_NAME = 'paytrack-v3';
const OFFLINE_URL = '/public/offline.php';
const PRECACHE = [
  OFFLINE_URL,
  '/public/manifest.json',
  '/public/icons/icon-192.png',
  '/public/icons/icon-512.png',
  '/assets/js/pwa.js'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(PRECACHE))
      .then(() => self.skipWaiting())
      .catch(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(names => Promise.all(names.filter(n => n !== CACHE_NAME).map(n => caches.delete(n))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  // Only handle same-origin requests; let CDN etc. pass through
  if (url.origin !== self.location.origin) return;

  // Navigations: network first, fall back to offline page
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then(res => {
          // Don't cache logged-in pages; just pass through
          return res;
        })
        .catch(() => caches.match(OFFLINE_URL))
    );
    return;
  }

  // Static assets (icons, js, css): cache first
  if (url.pathname.startsWith('/public/icons/') || url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.match(request).then(cached => {
        const fetched = fetch(request).then(res => {
          if (res && res.ok) {
            const copy = res.clone();
            caches.open(CACHE_NAME).then(cache => cache.put(request, copy));
          }
          return res;
        }).catch(() => cached);
        return cached || fetched;
      })
    );
  }
});
