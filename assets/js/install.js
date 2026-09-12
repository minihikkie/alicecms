/* install.js — เปลี่ยนขั้นตอนใน setup wizard */
(function () {
  'use strict';
  var steps = document.querySelectorAll('.wstep');
  var dots = document.querySelectorAll('.step-dots i');
  if (!steps.length) return;
  var cur = 0;

  function show(n) {
    if (n < 0 || n >= steps.length) return;
    steps[cur].classList.remove('show');
    cur = n;
    steps[cur].classList.add('show');
    dots.forEach(function (d, i) { d.classList.toggle('on', i <= cur); });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  document.querySelectorAll('[data-next]').forEach(function (b) {
    b.addEventListener('click', function () {
      /* ตรวจ field ที่ required ในขั้นปัจจุบันก่อนไปต่อ */
      var ok = true;
      steps[cur].querySelectorAll('input[required]').forEach(function (inp) {
        if (!inp.value.trim()) { inp.reportValidity(); ok = false; }
      });
      if (ok) show(cur + 1);
    });
  });
  document.querySelectorAll('[data-prev]').forEach(function (b) {
    b.addEventListener('click', function () { show(cur - 1); });
  });
})();
