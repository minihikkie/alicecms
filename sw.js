/* sw.js — Service Worker (PWA): ออฟไลน์ + แคชไฟล์สเตติก
   ทำงานเฉพาะบน HTTPS หรือ localhost เท่านั้น (ข้อกำหนดของเบราว์เซอร์) */
'use strict';

var CACHE = 'govcms-cache-v1';
var CORE = [
  './',
  './offline.html',
  './theme-kit.css',
  './assets/css/site.css',
  './assets/css/animations.css',
  './assets/js/main.js',
  './assets/js/a11y.js',
  './assets/img/favicon.svg',
  './assets/img/pwa/icon-192.png',
  './assets/img/pwa/icon-512.png'
];

self.addEventListener('install', function (e) {
  e.waitUntil(
    caches.open(CACHE).then(function (c) {
      // เพิ่มทีละไฟล์ ไม่ให้ล้มทั้งชุดถ้าไฟล์ใดโหลดไม่ได้
      return Promise.all(CORE.map(function (u) {
        return c.add(new Request(u, { cache: 'reload' })).catch(function () {});
      }));
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (e) {
  e.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (k) {
        if (k !== CACHE) return caches.delete(k);
      }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;

  var url = new URL(req.url);
  var sameOrigin = url.origin === self.location.origin;

  // ไม่ยุ่งกับระบบหลังบ้าน/ติดตั้ง/ดาวน์โหลดไฟล์ — ให้ผ่านไปเครือข่ายตรงๆ
  if (sameOrigin && /\/(admin|install\.php|download\.php|backup\.php)/.test(url.pathname)) return;

  // หน้าเว็บ (navigate): เครือข่ายก่อน → ถ้าออฟไลน์ใช้แคช → สุดท้ายหน้า offline
  if (req.mode === 'navigate') {
    e.respondWith(
      fetch(req).then(function (res) {
        var copy = res.clone();
        caches.open(CACHE).then(function (c) { c.put(req, copy); });
        return res;
      }).catch(function () {
        return caches.match(req).then(function (hit) {
          return hit || caches.match('./offline.html');
        });
      })
    );
    return;
  }

  // ฟอนต์ Google (ข้ามโดเมน): stale-while-revalidate
  if (!sameOrigin && /fonts\.(googleapis|gstatic)\.com/.test(url.host)) {
    e.respondWith(
      caches.open(CACHE).then(function (c) {
        return c.match(req).then(function (hit) {
          var net = fetch(req).then(function (res) { c.put(req, res.clone()); return res; }).catch(function () { return hit; });
          return hit || net;
        });
      })
    );
    return;
  }

  // ไฟล์สเตติกในเว็บ (css/js/รูป/ฟอนต์): แคชก่อน + อัปเดตเบื้องหลัง
  if (sameOrigin && /\.(css|js|png|jpg|jpeg|webp|svg|gif|ico|woff2?)$/.test(url.pathname)) {
    e.respondWith(
      caches.match(req).then(function (hit) {
        var net = fetch(req).then(function (res) {
          var copy = res.clone();
          caches.open(CACHE).then(function (c) { c.put(req, copy); });
          return res;
        }).catch(function () { return hit; });
        return hit || net;
      })
    );
  }
});
