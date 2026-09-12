/* ════════════════════════════════════════════════════════════
   dialog.js — กล่องโต้ตอบที่สร้างเอง (แทน window.confirm/alert ของเบราว์เซอร์)
   ใช้ได้ทั้งหน้าเว็บประชาชนและระบบจัดการ
   API:  GovDialog.confirm(opts) -> Promise<boolean>
         GovDialog.alert(opts)   -> Promise<true>
   อีกทั้งดักปุ่ม/ลิงก์ที่มีแอตทริบิวต์ data-confirm ให้อัตโนมัติ
   ════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var overlay, card, iconEl, titleEl, msgEl, okBtn, cancelBtn, resolver, lastFocus;

  function build() {
    overlay = document.createElement('div');
    overlay.className = 'gov-dialog-overlay';
    overlay.style.display = 'none';
    overlay.innerHTML =
      '<div class="gov-dialog" role="dialog" aria-modal="true" aria-labelledby="govDlgTitle" aria-describedby="govDlgMsg">' +
        '<div class="gd-icon"><span class="material-symbols-rounded"></span></div>' +
        '<h3 id="govDlgTitle"></h3>' +
        '<p id="govDlgMsg"></p>' +
        '<div class="gd-actions">' +
          '<button type="button" class="btn gd-cancel"></button>' +
          '<button type="button" class="btn gd-ok"></button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(overlay);
    card = overlay.querySelector('.gov-dialog');
    iconEl = overlay.querySelector('.gd-icon .material-symbols-rounded');
    titleEl = overlay.querySelector('#govDlgTitle');
    msgEl = overlay.querySelector('#govDlgMsg');
    okBtn = overlay.querySelector('.gd-ok');
    cancelBtn = overlay.querySelector('.gd-cancel');

    okBtn.addEventListener('click', function () { close(true); });
    cancelBtn.addEventListener('click', function () { close(false); });
    overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) close(false); });
    document.addEventListener('keydown', function (e) {
      if (!overlay || overlay.style.display === 'none') return;
      if (e.key === 'Escape') { e.preventDefault(); close(false); }
      else if (e.key === 'Enter') { e.preventDefault(); close(true); }
      else if (e.key === 'Tab') {
        var f = []; if (cancelBtn.style.display !== 'none') f.push(cancelBtn); f.push(okBtn);
        var i = f.indexOf(document.activeElement);
        e.preventDefault();
        f[(i + (e.shiftKey ? -1 : 1) + f.length) % f.length].focus();
      }
    });
  }

  function close(val) {
    if (!overlay) return;
    overlay.classList.remove('show');
    document.body.classList.remove('gov-dialog-open');
    var r = resolver; resolver = null;
    setTimeout(function () { if (!overlay.classList.contains('show')) overlay.style.display = 'none'; }, 220);
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    if (r) r(val);
  }

  function open(opts) {
    opts = (typeof opts === 'string') ? { message: opts } : (opts || {});
    if (!overlay) build();
    var danger = !!opts.danger;
    var hideCancel = opts.cancelText === null || opts.hideCancel;

    card.classList.toggle('danger', danger);
    iconEl.textContent = opts.icon || (danger ? 'delete' : 'help');
    titleEl.textContent = opts.title || (danger ? 'ยืนยันการลบ' : 'ยืนยัน');
    msgEl.textContent = opts.message || '';
    msgEl.style.display = opts.message ? '' : 'none';
    okBtn.textContent = opts.okText || (danger ? 'ลบ' : 'ตกลง');
    okBtn.className = 'btn gd-ok ' + (danger ? 'danger' : 'primary');
    cancelBtn.textContent = opts.cancelText || 'ยกเลิก';
    cancelBtn.style.display = hideCancel ? 'none' : '';

    lastFocus = document.activeElement;
    overlay.style.display = '';
    overlay.offsetWidth;                 // reflow เพื่อให้ transition ทำงาน
    overlay.classList.add('show');
    document.body.classList.add('gov-dialog-open');
    (danger && !hideCancel ? cancelBtn : okBtn).focus();
    return new Promise(function (res) { resolver = res; });
  }

  window.GovDialog = {
    confirm: open,
    alert: function (opts) {
      opts = (typeof opts === 'string') ? { message: opts } : (opts || {});
      opts.hideCancel = true;
      if (!opts.icon) opts.icon = 'info';
      return open(opts);
    }
  };

  /* ── ดักปุ่ม/ลิงก์ data-confirm ทั้งเว็บ (capture phase เพื่อชนะ handler อื่น) ── */
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-confirm]');
    if (!el || el.dataset.govConfirmed) return;
    ev.preventDefault();
    ev.stopPropagation();
    var danger = el.classList.contains('danger');
    open({ message: el.getAttribute('data-confirm'), danger: danger, okText: danger ? 'ลบ' : 'ยืนยัน' })
      .then(function (ok) {
        if (!ok) return;
        var form = el.form || el.closest('form');
        if (form) {
          if (form.requestSubmit) {
            form.requestSubmit(el.tagName === 'BUTTON' ? el : undefined);
          } else {
            if (el.name) {
              var h = document.createElement('input');
              h.type = 'hidden'; h.name = el.name; h.value = el.value;
              form.appendChild(h);
            }
            form.submit();
          }
        } else if (el.tagName === 'A' && el.getAttribute('href')) {
          window.location.href = el.href;
        } else {
          el.dataset.govConfirmed = '1';
          el.click();
          delete el.dataset.govConfirmed;
        }
      });
  }, true);
})();
