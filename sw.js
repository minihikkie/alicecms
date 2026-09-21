/* sw.js — Service Worker (PWA): ออฟไลน์ + แคชไฟล์สเตติก
   ทำงานเฉพาะบน HTTPS หรือ localhost เท่านั้น (ข้อกำหนดของเบราว์เซอร์) */
'use strict';

/* ขึ้นเลขรุ่นแคชเมื่อใดก็ตามที่ต้องการล้างของเก่าในเครื่องผู้ใช้ทุกคน
   ตัว activate จะลบแคชที่ชื่อไม่ตรงทิ้งให้เองตอนผู้ใช้เปิดเว็บครั้งถัดไป

   v2 — รุ่น v1 เก็บคำตอบทุกสถานะรวมทั้ง 500/403 ไว้ด้วย ใครที่เผลอเปิดเว็บ
   ตอนเซิร์ฟเวอร์สะดุดจะมีหน้า error ค้างอยู่ในเครื่อง ต้องล้างทิ้งให้ */
var CACHE = 'govcms-cache-v2';
var CORE = [
  './',
  './offline.html',
  './theme-kit.css',
  './assets/css/site.css',
  './assets/css/animations.css',
  './assets/js/main.js',
  './assets/js/a11y.js',
];
/* ไม่เก็บไอคอนแอปกับไอคอนแท็บไว้ล่วงหน้า — ระบบเรียกไฟล์พวกนั้นด้วย URL ที่มี
   ?v=เวอร์ชัน กำกับ (กันไฟล์เก่าค้างเพราะกฎแคช 1 ปี) URL ที่เขียนไว้ตรงนี้จึงไม่ตรงกัน
   เก็บไปก็ไม่มีใครเรียกใช้ แถมยังเสี่ยงเสิร์ฟลายไอคอนเก่าทับของใหม่
   อีกอย่างคือไอคอนพวกนี้ระบบปฏิบัติการใช้ ไม่ใช่สิ่งที่หน้าเว็บต้องใช้ตอนออฟไลน์ */

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

/* เก็บลงแคชเฉพาะคำตอบที่ใช้ได้จริงเท่านั้น
   เดิมเก็บทุกคำตอบที่เครือข่ายส่งกลับมา ไม่ได้ดูสถานะเลย คำตอบ 500 หรือ 403
   จึงถูกเก็บไว้ในเครื่องผู้ใช้ด้วย พอเครือข่ายสะดุดครั้งถัดไป ผู้ใช้จะได้หน้า error
   เก่าที่ค้างอยู่แทนหน้า "ออฟไลน์" ที่เตรียมไว้ให้ ซึ่งสับสนกว่าเดิมมาก */
function cacheable(res) {
  return res && res.ok && res.status === 200 && res.type !== 'opaqueredirect';
}

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
        if (cacheable(res)) {
          var copy = res.clone();
          caches.open(CACHE).then(function (c) { c.put(req, copy); });
        }
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
          var net = fetch(req).then(function (res) {
            if (res && (res.ok || res.type === 'opaque')) c.put(req, res.clone());
            return res;
          }).catch(function () { return hit; });
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
          if (cacheable(res)) {
            var copy = res.clone();
            caches.open(CACHE).then(function (c) { c.put(req, copy); });
          }
          return res;
        }).catch(function () { return hit; });
        return hit || net;
      })
    );
  }
});
