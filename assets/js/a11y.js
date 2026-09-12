/* ════════════════════════════════════════════════════════════
   a11y.js — แถบช่วยการเข้าถึง (Web Accessibility)
   ปรับขนาดตัวอักษร (3 ระดับ) + โหมดสีตัดกันสูง — จำค่าใน localStorage
   ════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var doc = document.documentElement;
  var STEPS = ['sm', '', 'lg', 'xl'];   // '' = ปกติ

  function curIndex() {
    var f = doc.getAttribute('data-fontscale') || '';
    var i = STEPS.indexOf(f);
    return i < 0 ? 1 : i;
  }
  function setFont(i) {
    i = Math.max(0, Math.min(STEPS.length - 1, i));
    var v = STEPS[i];
    if (v) doc.setAttribute('data-fontscale', v); else doc.removeAttribute('data-fontscale');
    try { localStorage.setItem('a11yFont', v); } catch (e) {}
  }
  function toggleContrast() {
    var on = doc.classList.toggle('contrast');
    try { localStorage.setItem('a11yContrast', on ? '1' : '0'); } catch (e) {}
    var btn = document.querySelector('[data-a11y="contrast"]');
    if (btn) btn.setAttribute('aria-pressed', on ? 'true' : 'false');
  }

  /* เปิด/ปิดแผงตัวเลือก (ปุ่มลอยมุมขวาล่าง) */
  var wrap = document.querySelector('.a11y-fab-wrap');
  var fab = document.getElementById('a11yFab');
  if (fab && wrap) {
    var setOpen = function (open) {
      wrap.classList.toggle('open', open);
      fab.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    fab.addEventListener('click', function (e) {
      e.stopPropagation();
      setOpen(!wrap.classList.contains('open'));
    });
    /* คลิกนอกแผง = ปิด */
    document.addEventListener('click', function (e) {
      if (wrap.classList.contains('open') && !wrap.contains(e.target)) setOpen(false);
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
  }

  document.querySelectorAll('[data-a11y]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      switch (btn.getAttribute('data-a11y')) {
        case 'font-inc':   setFont(curIndex() + 1); break;
        case 'font-dec':   setFont(curIndex() - 1); break;
        case 'font-reset': setFont(1); break;
        case 'contrast':   toggleContrast(); break;
      }
    });
  });

  /* ตั้งสถานะปุ่ม contrast ให้ตรงกับค่าที่จำไว้ */
  var cbtn = document.querySelector('[data-a11y="contrast"]');
  if (cbtn) cbtn.setAttribute('aria-pressed', doc.classList.contains('contrast') ? 'true' : 'false');
})();
