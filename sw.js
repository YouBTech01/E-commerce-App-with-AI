/* ExamLegacy service worker — offline APP SHELL only.
 *
 * Security invariants (do not weaken):
 *   1. NOTHING under /api/ is ever cached — auth, payments, viewer streams,
 *      covers and AI responses are always fetched fresh from the network.
 *   2. Only same-origin static assets are precached; CDN libraries are
 *      runtime-cached (cache-first, version-pinned URLs).
 *   3. Non-GET requests pass straight through.
 *
 * Bump VERSION when shipping shell changes.
 */
'use strict';

const VERSION = 'examlegacy-v2';
const SHELL_CACHE = VERSION + '-shell';
const CDN_CACHE = VERSION + '-cdn';

const SHELL = [
  '/',
  '/index.html',
  '/admin/',
  '/assets/css/app.css',
  '/assets/js/config.js',
  '/assets/js/api.js',
  '/assets/js/auth.js',
  '/assets/js/ui.js',
  '/assets/js/viewer.js',
  '/assets/js/app.js',
  '/assets/img/logo.svg',
  '/assets/img/mark.svg',
  '/assets/img/icon-192.png',
  '/assets/img/icon-512.png',
  '/assets/img/icon-maskable-512.png',
  '/assets/img/apple-touch-icon.png',
  '/manifest.json',
  '/admin/assets/js/admin.js',
  '/admin/assets/css/admin.css'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(SHELL_CACHE).then(function (cache) {
      // Add individually so one 404 does not fail the whole install.
      return Promise.all(SHELL.map(function (url) {
        return cache.add(new Request(url, { cache: 'reload' })).catch(function () {});
      }));
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) {
        return k !== SHELL_CACHE && k !== CDN_CACHE;
      }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

function isApi(url) {
  return url.pathname.indexOf('/api/') === 0;
}

/* Cache-first for immutable static files (same-origin + versioned CDN). */
function cacheFirst(request, cacheName) {
  return caches.match(request).then(function (hit) {
    if (hit) return hit;
    return fetch(request).then(function (response) {
      if (response && (response.ok || response.type === 'opaque')) {
        var copy = response.clone();
        caches.open(cacheName).then(function (cache) { cache.put(request, copy); });
      }
      return response;
    });
  });
}

/* Network-first for navigations; offline falls back to the cached shell. */
function navigateNetworkFirst(request) {
  return fetch(request).then(function (response) {
    if (response && response.ok) {
      var copy = response.clone();
      caches.open(SHELL_CACHE).then(function (cache) { cache.put(request, copy); });
    }
    return response;
  }).catch(function () {
    var url = new URL(request.url);
    if (url.pathname.indexOf('/admin') === 0) {
      return caches.match('/admin/').then(function (r) { return r || caches.match('/index.html'); });
    }
    return caches.match('/index.html').then(function (r) {
      return r || new Response('You are offline. Connect to use ExamLegacy.', {
        status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' }
      });
    });
  });
}

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') return;                 // never touch POST/PATCH/DELETE
  var url = new URL(request.url);

  // (1) API is network-only — including viewer streams and covers.
  if (url.origin === self.location.origin && isApi(url)) return;

  // (2) Same-origin static shell assets.
  if (url.origin === self.location.origin) {
    if (request.mode === 'navigate') {
      event.respondWith(navigateNetworkFirst(request));
      return;
    }
    event.respondWith(cacheFirst(request, SHELL_CACHE));
    return;
  }

  // (3) Cross-origin CDN (fonts, MathJax, Firebase…): cache-first, pinned versions.
  if (request.destination === 'script' || request.destination === 'style' ||
      request.destination === 'font' || request.destination === 'image') {
    event.respondWith(cacheFirst(request, CDN_CACHE));
  }
  // Everything else: default browser behaviour.
});
