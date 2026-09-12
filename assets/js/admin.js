/* ════════════════════════════════════════════════════════════
   admin.js — ลูกเล่นระบบจัดการ (vanilla JS)
   ════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var body = document.body;
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var LEVEL = (body.classList.contains('anim-off') || reduced) ? 0
            : body.classList.contains('anim-min') ? 1 : 2;

  /* ─── 15) กราฟแท่งโตขึ้นจากศูนย์ตอนโหลด ─── */
  document.querySelectorAll('.chart').forEach(function (chart) {
    var bars = chart.querySelectorAll('.bar');
    bars.forEach(function (b, i) { b.style.setProperty('--bd', (i * 45) + 'ms'); });
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { chart.classList.add('ready'); });
    });
  });

  /* ─── 16) การ์ดสถิติ: count-up + ไอคอนเด้ง ─── */
  document.querySelectorAll('.stat-card .si').forEach(function (el, i) {
    el.style.setProperty('--sd', (i * 90) + 'ms');
  });
  document.querySelectorAll('[data-count]').forEach(function (el) {
    var target = parseInt(el.getAttribute('data-count') || '0', 10);
    if (LEVEL < 2) { el.textContent = target.toLocaleString('th-TH'); return; }
    var dur = 1000, t0 = null;
    function step(ts) {
      if (!t0) t0 = ts;
      var p = Math.min((ts - t0) / dur, 1);
      el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))).toLocaleString('th-TH');
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  });

  /* ─── 18) Toast notification (จาก flash message) ─── */
  var toast = document.querySelector('.toast');
  if (toast) {
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { toast.classList.add('show'); });
    });
    setTimeout(function () {
      toast.classList.remove('show');
      setTimeout(function () { toast.remove(); }, 400);
    }, 3800);
  }

  /* ─── 19) เปลี่ยนธีมสีแบบ transition นุ่มๆ (preview สด) ─── */
  function shade(hex) {
    var n = parseInt(hex.slice(1), 16);
    var f = function (x) { return Math.max(0, Math.round(x * 0.68)); };
    return '#' + [f((n >> 16) & 255), f((n >> 8) & 255), f(n & 255)]
      .map(function (x) { return x.toString(16).padStart(2, '0'); }).join('');
  }
  function applyTheme(c, c700) {
    var html = document.documentElement;
    html.classList.add('theme-fade');
    html.style.setProperty('--blue', c);
    html.style.setProperty('--blue-700', c700 || shade(c));
    setTimeout(function () { html.classList.remove('theme-fade'); }, 600);
  }
  document.querySelectorAll('[data-theme-color]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      applyTheme(btn.getAttribute('data-theme-color'), btn.getAttribute('data-theme-dark'));
      var radio = btn.querySelector('input[type="radio"]');
      if (radio) radio.checked = true;
      document.querySelectorAll('[data-theme-color]').forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
      var custom = document.getElementById('custom-color');
      if (custom) custom.value = btn.getAttribute('data-theme-color');
    });
  });
  var customColor = document.getElementById('custom-color');
  if (customColor) {
    customColor.addEventListener('input', function () {
      applyTheme(customColor.value);
      var customRadio = document.getElementById('preset-custom');
      if (customRadio) customRadio.checked = true;
      document.querySelectorAll('[data-theme-color]').forEach(function (b) { b.classList.remove('active'); });
    });
  }

  /* ─── ยืนยันก่อนลบ: จัดการโดย dialog.js (custom dialog) แล้ว ─── */

  /* ─── Dropzone อัปโหลดไฟล์ (คลิก/ลากวาง) ─── */
  document.querySelectorAll('.dropzone').forEach(function (dz) {
    var input = dz.querySelector('input[type="file"]');
    if (!input) return;
    var nameEl = dz.querySelector('.dz-filename');
    function showName() {
      if (nameEl) nameEl.textContent = input.files.length ? input.files[0].name : '';
    }
    dz.addEventListener('click', function () { input.click(); });
    input.addEventListener('change', showName);
    dz.addEventListener('dragover', function (ev) { ev.preventDefault(); dz.classList.add('dragover'); });
    dz.addEventListener('dragleave', function () { dz.classList.remove('dragover'); });
    dz.addEventListener('drop', function (ev) {
      ev.preventDefault();
      dz.classList.remove('dragover');
      if (ev.dataTransfer.files.length) { input.files = ev.dataTransfer.files; showName(); }
    });
  });

  /* ─── เปลี่ยนชื่อไฟล์อัปโหลดเป็นอักษรอังกฤษก่อนส่ง ───
     ทำไมต้องมี: ชื่อไฟล์ภาษาไทย (เช่น "ผบกหน่อย.jpg") ถูกส่งเป็นอักขระ non-ASCII
     ในส่วนหัว multipart ของคำขอ HTTP — ไฟร์วอลล์ (WAF) ของโฮสต์บางเจ้าตีความว่าเป็นการโจมตี
     แล้วบล็อกทั้งคำขอด้วย 403 Forbidden ตั้งแต่ก่อนถึง PHP (พบจริงกับเว็บหน่วยงานราชการ:
     บันทึกข้อความอย่างเดียวผ่าน แต่พอแนบรูปชื่อไทยแล้วโดน 403 ทุกครั้ง)
     แก้ที่ฝั่งเบราว์เซอร์เท่านั้นได้ เพราะเซิร์ฟเวอร์ไม่มีโอกาสเห็นคำขอเลย
     (ฝั่งเซิร์ฟเวอร์ store_upload() ตั้งชื่อไฟล์ใหม่แบบสุ่มอยู่แล้ว ชื่อเดิมใช้แค่ตรวจนามสกุล
      การเปลี่ยนชื่อตรงนี้จึงไม่กระทบการทำงานใดๆ) */
  function safeUploadName(name) {
    var dot  = name.lastIndexOf('.');
    var ext  = dot > -1 ? name.slice(dot + 1).toLowerCase().replace(/[^a-z0-9]/g, '') : '';
    var base = (dot > -1 ? name.slice(0, dot) : name)
                 .replace(/[^A-Za-z0-9_-]+/g, '-')     /* อักขระที่ไม่ใช่ ASCII ปลอดภัย → ขีดกลาง */
                 .replace(/^-+|-+$/g, '')
                 .slice(0, 60);
    if (!base) base = 'file-' + Date.now();
    return ext ? base + '.' + ext : base;
  }

  function sanitizeFileInput(input) {
    if (!input.files || !input.files.length) return;
    if (typeof DataTransfer === 'undefined' || typeof File === 'undefined') return;
    var needs = false;
    for (var i = 0; i < input.files.length; i++) {
      if (/[^\x20-\x7E]/.test(input.files[i].name)) { needs = true; break; }
    }
    if (!needs) return;                                 /* ชื่อปลอดภัยอยู่แล้ว ไม่ต้องแตะ */
    try {
      var dt = new DataTransfer();
      for (var j = 0; j < input.files.length; j++) {
        var f = input.files[j];
        dt.items.add(new File([f], safeUploadName(f.name), { type: f.type, lastModified: f.lastModified }));
      }
      input.files = dt.files;
    } catch (e) { /* เบราว์เซอร์เก่าไม่รองรับ — ปล่อยผ่าน ไม่ทำให้อัปโหลดพัง */ }
  }

  /* ทำตอนกดส่งฟอร์ม — ครอบคลุมทุกทางที่ไฟล์เข้ามา (เลือกเอง/ลากวาง/วางจากคลิปบอร์ด) */
  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function () {
      form.querySelectorAll('input[type="file"]').forEach(sanitizeFileInput);
    });
  });

  /* ─── Ripple ปุ่ม (เหมือนหน้าเว็บ) ─── */
  if (LEVEL > 0) {
    document.addEventListener('click', function (ev) {
      var btn = ev.target.closest('.btn, .cat');
      if (!btn) return;
      var rect = btn.getBoundingClientRect();
      var d = Math.max(rect.width, rect.height);
      var ink = document.createElement('span');
      ink.className = 'ripple-ink';
      ink.style.width = ink.style.height = d + 'px';
      ink.style.left = (ev.clientX - rect.left - d / 2) + 'px';
      ink.style.top = (ev.clientY - rect.top - d / 2) + 'px';
      btn.appendChild(ink);
      setTimeout(function () { ink.remove(); }, 600);
    });
  }

  /* ─── ตัวจัดสไลด์มืออาชีพ: พรีวิวสด + จุดโฟกัส + ตำแหน่งข้อความ ─── */
  var prev = document.getElementById('slidePreview');
  if (prev) {
    var spImg = document.getElementById('spImg');
    var spText = document.getElementById('spText');
    var taIn = document.getElementById('text_align'), tvIn = document.getElementById('text_valign'), ttIn = document.getElementById('text_theme');

    function applyTextPos() {
      var va = tvIn.value, ha = taIn.value;
      spText.style.justifyContent = va === 'top' ? 'flex-start' : va === 'bottom' ? 'flex-end' : 'center';
      spText.style.alignItems = ha === 'left' ? 'flex-start' : ha === 'right' ? 'flex-end' : 'center';
      spText.style.textAlign = ha;
    }
    applyTextPos();

    /* ── ครอบตัดภาพ: ลาก (pan) + ซูม → คำนวณ ratio ส่งให้เซิร์ฟเวอร์ตัดด้วย GD ── */
    var cropApply = document.getElementById('crop_apply');
    var cropXi = document.getElementById('crop_x'), cropYi = document.getElementById('crop_y'),
        cropWi = document.getElementById('crop_w'), cropHi = document.getElementById('crop_h');
    var cropZoom = document.getElementById('cropZoom'), cropBar = document.getElementById('cropBar');
    var natW = 0, natH = 0, zoom = 1, ox = 0, oy = 0, hasImage = false;

    function vw() { return prev.clientWidth; }
    function vh() { return prev.clientHeight; }
    function dispDims() { var s = Math.max(vw() / natW, vh() / natH) * zoom; return { w: natW * s, h: natH * s }; }
    function layout() {
      if (!hasImage || !natW) return;
      var d = dispDims();
      ox = Math.min(0, Math.max(vw() - d.w, ox));
      oy = Math.min(0, Math.max(vh() - d.h, oy));
      spImg.style.position = 'absolute'; spImg.style.inset = 'auto';
      spImg.style.left = ox + 'px'; spImg.style.top = oy + 'px';
      spImg.style.width = d.w + 'px'; spImg.style.height = d.h + 'px'; spImg.style.objectFit = 'fill';
      if (cropXi) cropXi.value = Math.max(0, Math.min(1, (-ox) / d.w)).toFixed(5);
      if (cropYi) cropYi.value = Math.max(0, Math.min(1, (-oy) / d.h)).toFixed(5);
      if (cropWi) cropWi.value = Math.max(0, Math.min(1, vw() / d.w)).toFixed(5);
      if (cropHi) cropHi.value = Math.max(0, Math.min(1, vh() / d.h)).toFixed(5);
    }
    function centerImage() { var d = dispDims(); ox = (vw() - d.w) / 2; oy = (vh() - d.h) / 2; layout(); }
    function loadImage(src, markApply) {
      var img = new Image();
      img.onload = function () {
        natW = img.naturalWidth; natH = img.naturalHeight; hasImage = true;
        zoom = 1; if (cropZoom) cropZoom.value = 100;
        spImg.src = src; spImg.style.display = '';
        if (cropBar) cropBar.style.display = '';
        centerImage();
        if (markApply && cropApply) cropApply.value = '1';
        applyGrad();
      };
      img.src = src;
    }
    var initImg = prev.getAttribute('data-img');
    if (initImg) loadImage(initImg, false);

    var dragging = false, dsx = 0, dsy = 0, dox = 0, doy = 0;
    prev.addEventListener('pointerdown', function (ev) {
      if (!hasImage) return;
      dragging = true; dsx = ev.clientX; dsy = ev.clientY; dox = ox; doy = oy;
      try { prev.setPointerCapture(ev.pointerId); } catch (e) {}
      prev.classList.add('grabbing');
    });
    prev.addEventListener('pointermove', function (ev) {
      if (!dragging) return;
      ox = dox + (ev.clientX - dsx); oy = doy + (ev.clientY - dsy);
      layout(); if (cropApply) cropApply.value = '1';
    });
    function endDrag() { if (dragging) { dragging = false; prev.classList.remove('grabbing'); } }
    prev.addEventListener('pointerup', endDrag);
    prev.addEventListener('pointercancel', endDrag);

    if (cropZoom) cropZoom.addEventListener('input', function () {
      if (!hasImage) return;
      var cx = vw() / 2, cy = vh() / 2, b = dispDims();
      var bx = (cx - ox) / b.w, by = (cy - oy) / b.h;
      zoom = cropZoom.value / 100;
      var a = dispDims();
      ox = cx - bx * a.w; oy = cy - by * a.h;
      layout(); if (cropApply) cropApply.value = '1';
    });
    var cropResetBtn = document.getElementById('cropReset');
    if (cropResetBtn) cropResetBtn.addEventListener('click', function () {
      zoom = 1; if (cropZoom) cropZoom.value = 100; centerImage();
      if (cropApply) cropApply.value = '1';
    });

    /* live text */
    function bindText(inputId, target, btn) {
      var inp = document.getElementById(inputId), el = document.getElementById(target);
      if (!inp || !el) return;
      inp.addEventListener('input', function () {
        var v = inp.value.trim();
        el.textContent = v;
        if (btn || target === 'spSub' || target === 'spTitle') el.style.display = v ? '' : 'none';
      });
    }
    bindText('sf_title', 'spTitle', false);
    bindText('sf_sub', 'spSub', false);
    bindText('sf_btn', 'spBtn', true);

    /* ตาราง 3×3 ตำแหน่งข้อความ */
    document.querySelectorAll('#posGrid .pos-cell').forEach(function (cell) {
      cell.addEventListener('click', function () {
        document.querySelectorAll('#posGrid .pos-cell').forEach(function (c) { c.classList.remove('on'); });
        cell.classList.add('on');
        tvIn.value = cell.getAttribute('data-va');
        taIn.value = cell.getAttribute('data-ha');
        applyTextPos();
      });
    });

    /* สีข้อความ light/dark */
    document.querySelectorAll('.theme-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        ttIn.value = btn.getAttribute('data-theme');
        prev.classList.toggle('txt-dark', ttIn.value === 'dark');
        document.querySelectorAll('.theme-btn').forEach(function (b) { b.classList.remove('primary'); });
        btn.classList.add('primary');
      });
    });

    /* ความเข้มฉากหลัง */
    var ovr = document.getElementById('sf_overlay'), ovVal = document.getElementById('ovVal');
    if (ovr) ovr.addEventListener('input', function () {
      prev.style.setProperty('--ov', ovr.value / 100);
      if (ovVal) ovVal.textContent = ovr.value;
    });

    /* แสดงรูปที่เพิ่งเลือก + เข้าโหมดครอบตัดทันที (FileReader) */
    var fileInp = document.getElementById('sf_img');
    if (fileInp) fileInp.addEventListener('change', function () {
      if (!fileInp.files || !fileInp.files[0]) return;
      var reader = new FileReader();
      reader.onload = function (e) { loadImage(e.target.result, true); };
      reader.readAsDataURL(fileInp.files[0]);
    });

    /* ── จัดเต็ม: ขนาดหัวข้อ / สีข้อความ / เงา / kenburns / ปุ่ม / ไล่สี ── */
    var titleSizeIn = document.getElementById('sf_titlesize');
    var spTitle = document.getElementById('spTitle');
    var spSub = document.getElementById('spSub');
    var spBtn = document.getElementById('spBtn');
    var spBtn2 = document.getElementById('spBtn2');

    document.querySelectorAll('.size-btn').forEach(function (b) {
      b.addEventListener('click', function () {
        if (titleSizeIn) titleSizeIn.value = b.getAttribute('data-size');
        if (spTitle) spTitle.style.fontSize = b.getAttribute('data-px') + 'px';
        document.querySelectorAll('.size-btn').forEach(function (x) { x.classList.remove('primary'); });
        b.classList.add('primary');
      });
    });

    var tsh = document.getElementById('sf_textshadow');
    function applyShadow() { prev.classList.toggle('no-shadow', !!(tsh && !tsh.checked)); }
    if (tsh) tsh.addEventListener('change', applyShadow);
    applyShadow();

    var kb = document.getElementById('sf_kenburns');
    function applyKb() { prev.classList.toggle('kb', !!(kb && kb.checked)); }
    if (kb) kb.addEventListener('change', applyKb);
    applyKb();

    var tcol = document.getElementById('sf_textcolor'), tcolOn = document.getElementById('sf_textcolor_on');
    function applyTextColor() {
      var use = !!(tcolOn && tcolOn.checked);
      if (tcol) { tcol.disabled = !use; var cf = tcol.closest('.color-field'); if (cf) cf.classList.toggle('cleared', !use); }
      var c = use && tcol ? tcol.value : '';
      if (spTitle) spTitle.style.color = c;
      if (spSub) spSub.style.color = c;
    }
    if (tcolOn) tcolOn.addEventListener('change', applyTextColor);
    if (tcol) tcol.addEventListener('input', applyTextColor);
    applyTextColor();

    var bstyle = document.getElementById('sf_btnstyle');
    var bcol = document.getElementById('sf_btncolor'), bcolOn = document.getElementById('sf_btncolor_on');
    var btn2text = document.getElementById('sf_btn2');
    function styleBtn(el) {
      if (!el) return;
      var outline = bstyle && bstyle.value === 'outline';
      var use = !!(bcolOn && bcolOn.checked);
      var c = use && bcol ? bcol.value : '';
      if (outline) { var oc = c || '#ffffff'; el.style.background = 'transparent'; el.style.border = '2px solid ' + oc; el.style.color = oc; }
      else if (c) { el.style.background = c; el.style.border = '2px solid ' + c; el.style.color = '#fff'; }
      else { el.style.background = '#fff'; el.style.border = '2px solid #fff'; el.style.color = 'var(--blue-700)'; }
    }
    function applyBtns() {
      var use = !!(bcolOn && bcolOn.checked);
      if (bcol) { bcol.disabled = !use; var cf = bcol.closest('.color-field'); if (cf) cf.classList.toggle('cleared', !use); }
      styleBtn(spBtn); styleBtn(spBtn2);
    }
    if (bstyle) bstyle.addEventListener('change', applyBtns);
    if (bcol) bcol.addEventListener('input', applyBtns);
    if (bcolOn) bcolOn.addEventListener('change', applyBtns);
    if (btn2text && spBtn2) btn2text.addEventListener('input', function () {
      var v = btn2text.value.trim();
      spBtn2.textContent = v; spBtn2.style.display = v ? '' : 'none';
    });
    applyBtns();

    var gOn = document.getElementById('sf_grad_on'), gf = document.getElementById('sf_gradfrom'), gt = document.getElementById('sf_gradto');
    function applyGrad() {
      var use = !!(gOn && gOn.checked);
      if (gf) { gf.disabled = !use; var c1 = gf.closest('.color-field'); if (c1) c1.classList.toggle('cleared', !use); }
      if (gt) { gt.disabled = !use; var c2 = gt.closest('.color-field'); if (c2) c2.classList.toggle('cleared', !use); }
      var hasImg = spImg && spImg.style.display !== 'none' && spImg.src;
      if (use && !hasImg && gf && gt) {
        prev.style.setProperty('--gf', gf.value);
        prev.style.setProperty('--gt', gt.value);
        prev.classList.add('has-grad');
      } else {
        prev.classList.remove('has-grad');
      }
    }
    if (gOn) gOn.addEventListener('change', applyGrad);
    if (gf) gf.addEventListener('input', applyGrad);
    if (gt) gt.addEventListener('input', applyGrad);
    applyGrad();
  }

  /* ─── คัดลอกลิงก์ (คลังสื่อ ฯลฯ) ─── */
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-copy]');
    if (!btn) return;
    var val = btn.getAttribute('data-copy');
    var done = function () {
      var old = btn.innerHTML;
      btn.innerHTML = '<span class="material-symbols-rounded icon-sm">check</span>คัดลอกแล้ว';
      setTimeout(function () { btn.innerHTML = old; }, 1400);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(val).then(done).catch(function () {});
    } else {
      var t = document.createElement('textarea'); t.value = val; document.body.appendChild(t);
      t.select(); try { document.execCommand('copy'); done(); } catch (e) {}
      t.remove();
    }
  });

  /* ─── คลังสื่อ: โหมดเลือกรูป (ส่งกลับไปยังหน้าที่เปิด) ─── */
  document.querySelectorAll('.media-thumb.pickable').forEach(function (th) {
    function pick() {
      var card = th.closest('.media-card');
      var data = { govMediaPick: true, url: card.getAttribute('data-url'), path: card.getAttribute('data-path') };
      try { if (window.opener) window.opener.postMessage(data, '*'); } catch (e) {}
      try { if (window.parent && window.parent !== window) window.parent.postMessage(data, '*'); } catch (e) {}
      if (window.opener) window.close();
    }
    th.addEventListener('click', pick);
    th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); } });
  });

  /* ─── Menu Builder: ลากจัดลำดับเมนู (ภายในชั้นเดียวกัน) ─── */
  var mb = document.getElementById('menuBuilder');
  if (mb) {
    var mbCsrf = mb.getAttribute('data-csrf');
    var dragEl = null;
    function afterEl(ul, y) {
      var els = Array.prototype.slice.call(ul.children).filter(function (c) {
        return c.classList && c.classList.contains('menu-li') && c !== dragEl;
      });
      var closest = null, off = -Infinity;
      els.forEach(function (c) {
        var r = c.getBoundingClientRect(), d = y - (r.top + r.height / 2);
        if (d < 0 && d > off) { off = d; closest = c; }
      });
      return closest;
    }
    function persist(ul) {
      var ids = [];
      Array.prototype.forEach.call(ul.children, function (c) {
        if (c.classList && c.classList.contains('menu-li')) ids.push(c.getAttribute('data-id'));
      });
      var body = 'action=reorder&csrf_token=' + encodeURIComponent(mbCsrf) +
                 '&parent=' + encodeURIComponent(ul.getAttribute('data-parent')) +
                 '&order=' + encodeURIComponent(JSON.stringify(ids));
      fetch('menu.php', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body }).catch(function () {});
    }
    mb.querySelectorAll('.menu-li').forEach(function (li) {
      li.addEventListener('dragstart', function (e) {
        dragEl = li; li.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', ''); } catch (_) {}
      });
      li.addEventListener('dragend', function () {
        li.classList.remove('dragging');
        if (dragEl && dragEl.parentElement) persist(dragEl.parentElement);
        dragEl = null;
      });
    });
    mb.querySelectorAll('.menu-sort').forEach(function (ul) {
      ul.addEventListener('dragover', function (e) {
        if (!dragEl || dragEl.parentElement !== ul) return;   /* จัดเรียงเฉพาะในชั้นเดียวกัน */
        e.preventDefault();
        var a = afterEl(ul, e.clientY);
        if (a == null) ul.appendChild(dragEl); else ul.insertBefore(dragEl, a);
      });
    });
  }

  /* ─── Block Builder: เพิ่ม/เลื่อน/ลบ/บันทึกบล็อก + เลือกรูปจากคลัง ─── */
  var be = document.getElementById('blockEditor');
  if (be) {
    var blkList = document.getElementById('blocksList');
    var blkTpls = document.getElementById('blockTpls');
    var blkJson = document.getElementById('blocksJson');
    var pageForm = be.closest('form');   /* หาแบบ closest แทน id ตายตัว — ใช้ตัวแก้ไขบล็อกได้จากหลายหน้า (pages.php, site-info.php ฯลฯ) */

    be.querySelectorAll('[data-addblock]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var type = btn.getAttribute('data-addblock');
        var tpl = blkTpls.content.querySelector('[data-tpl="' + type + '"]');
        if (!tpl || !tpl.firstElementChild) return;
        var node = tpl.firstElementChild.cloneNode(true);
        blkList.appendChild(node);
        node.scrollIntoView({ block: 'nearest' });
        var first = node.querySelector('input, textarea'); if (first) first.focus();
      });
    });

    var pickTarget = null;
    blkList.addEventListener('click', function (ev) {
      var item = ev.target.closest('.block-item'); if (!item) return;
      if (ev.target.closest('[data-bdel]')) { item.remove(); return; }
      var mv = ev.target.closest('[data-bmove]');
      if (mv) {
        if (mv.getAttribute('data-bmove') === 'up' && item.previousElementSibling) blkList.insertBefore(item, item.previousElementSibling);
        else if (mv.getAttribute('data-bmove') === 'down' && item.nextElementSibling) blkList.insertBefore(item.nextElementSibling, item);
        return;
      }
      var mp = ev.target.closest('[data-mediapick]');
      if (mp) {
        pickTarget = { item: item, field: mp.getAttribute('data-mediapick') };
        window.open('media.php?pick=1', 'mediaPick', 'width=920,height=680');
      }
    });

    window.addEventListener('message', function (ev) {
      if (!ev.data || !ev.data.govMediaPick || !pickTarget) return;
      var val = ev.data.path || ev.data.url || '';
      var fld = pickTarget.item.querySelector('[data-field="' + pickTarget.field + '"]');
      if (fld) {
        if (pickTarget.field === 'urls') fld.value = (fld.value.trim() ? fld.value.trim() + '\n' : '') + val;
        else fld.value = val;
      }
      pickTarget = null;
    });

    if (pageForm) pageForm.addEventListener('submit', function () {
      var blocks = [];
      blkList.querySelectorAll('.block-item').forEach(function (item) {
        var b = { type: item.getAttribute('data-type') };
        item.querySelectorAll('[data-field]').forEach(function (f) {
          b[f.getAttribute('data-field')] = (f.type === 'checkbox') ? (f.checked ? '1' : '') : f.value;
        });
        blocks.push(b);
      });
      blkJson.value = JSON.stringify(blocks);
    });
  }

  /* ─── ลากจัดลำดับรายการแถวเดียว (ไม่มีชั้นย่อย) — ใช้ร่วมกันหลายหน้า ───
     ผูกกับ [data-sortlist] ทุกตัว: data-endpoint = ไฟล์ที่รับ action=reorder,
     data-cat = ตัวกรองเพิ่มเติม (เอกสารใช้แยกหมวด, หน้าอื่นเว้นว่างได้) */
  document.querySelectorAll('[data-sortlist]').forEach(function (ds) {
    var dsCsrf = ds.getAttribute('data-csrf');
    var dsCat = ds.getAttribute('data-cat') || '';
    var dsUrl = ds.getAttribute('data-endpoint');
    var dsList = ds.querySelector('.doc-sort');
    if (!dsList || !dsUrl) return;
    var dsDragEl = null;
    function dsAfterEl(y) {
      var els = Array.prototype.slice.call(dsList.children).filter(function (c) { return c !== dsDragEl; });
      var closest = null, off = -Infinity;
      els.forEach(function (c) {
        var r = c.getBoundingClientRect(), d = y - (r.top + r.height / 2);
        if (d < 0 && d > off) { off = d; closest = c; }
      });
      return closest;
    }
    function dsPersist() {
      var ids = [];
      Array.prototype.forEach.call(dsList.children, function (c) { ids.push(c.getAttribute('data-id')); });
      var body = 'action=reorder&csrf_token=' + encodeURIComponent(dsCsrf) +
                 '&cat=' + encodeURIComponent(dsCat) + '&order=' + encodeURIComponent(JSON.stringify(ids));
      fetch(dsUrl, { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body }).catch(function () {});
    }
    dsList.querySelectorAll('.doc-li').forEach(function (li) {
      li.addEventListener('dragstart', function (e) {
        dsDragEl = li; li.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', ''); } catch (_) {}
      });
      li.addEventListener('dragend', function () {
        li.classList.remove('dragging');
        if (dsDragEl) dsPersist();
        dsDragEl = null;
      });
    });
    dsList.addEventListener('dragover', function (e) {
      if (!dsDragEl) return;
      e.preventDefault();
      var a = dsAfterEl(e.clientY);
      if (a == null) dsList.appendChild(dsDragEl); else dsList.insertBefore(dsDragEl, a);
    });
  });

  /* ─── เลือกปลายทางลิงก์: สลับระหว่าง "หน้าในระบบ" กับ "ลิงก์ภายนอก" ───
     โชว์ทีละช่อง และ disabled ช่องที่ไม่ได้ใช้ เพื่อไม่ให้ name="url" ที่ซ้ำกันส่งค่าชนกัน
     (control ที่ disabled เบราว์เซอร์จะไม่ส่งค่าและไม่ตรวจ required ให้เอง) */
  document.querySelectorAll('[data-dest]').forEach(function (wrap) {
    var sys = wrap.querySelector('[data-dest-system]');
    var ext = wrap.querySelector('[data-dest-external]');
    var modes = wrap.querySelectorAll('[data-dest-mode]');
    if (!sys || !ext || !modes.length) return;
    function applyMode() {
      var picked = wrap.querySelector('[data-dest-mode]:checked');
      var useExt = picked && picked.value === 'external';
      sys.disabled = useExt;  sys.hidden = useExt;
      ext.disabled = !useExt; ext.hidden = !useExt;
    }
    modes.forEach(function (m) { m.addEventListener('change', applyMode); });
    applyMode();
  });

  /* ─── ตัวอย่างวิดีโอ YouTube สดในหน้า admin ───
     วางลิงก์แล้วเห็นภาพปกทันที = รู้เลยว่าลิงก์ถูกตัวไหม ไม่ต้องบันทึกก่อนถึงจะรู้
     (แกะ id ฝั่ง JS เพื่อ preview เท่านั้น — ตอนบันทึกจริง PHP แกะซ้ำด้วย youtube_id()) */
  var vidUrlInput = document.querySelector('[data-yt-input]');
  if (vidUrlInput) {
    var vidPrev = document.querySelector('[data-yt-preview]');
    var vidRe = /(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/;
    function vidRender() {
      var m = vidRe.exec(vidUrlInput.value.trim());
      if (!vidPrev) return;
      if (!m) {
        vidPrev.innerHTML = vidUrlInput.value.trim()
          ? '<div class="yt-prev-empty is-bad"><span class="material-symbols-rounded">error</span>ไม่พบรหัสวิดีโอในลิงก์นี้ — ตรวจสอบว่าคัดลอกลิงก์ YouTube มาครบหรือไม่</div>'
          : '<div class="yt-prev-empty"><span class="material-symbols-rounded">smart_display</span>วางลิงก์ YouTube แล้วภาพปกจะขึ้นตรงนี้</div>';
        return;
      }
      vidPrev.innerHTML =
        '<div class="yt-prev-thumb"><img src="https://i.ytimg.com/vi/' + m[1] + '/hqdefault.jpg" alt="">' +
        '<span class="material-symbols-rounded yt-prev-play">play_arrow</span></div>' +
        '<div class="yt-prev-info" data-yt-msg><span class="material-symbols-rounded" style="color:var(--success)">check_circle</span>' +
        'ลิงก์ถูกต้อง — รหัสวิดีโอ <code>' + m[1] + '</code></div>';
      /* YouTube ส่งภาพเทาเปล่า 120px (ไม่ใช่ error) เมื่อไม่มีวิดีโอรหัสนี้จริง — เตือนผู้ดูแลตั้งแต่ตอนกรอก */
      var pv = vidPrev.querySelector('img'), msg = vidPrev.querySelector('[data-yt-msg]');
      pv.onload = function () {
        if (pv.naturalWidth > 120 || !msg) return;
        pv.style.opacity = '0';
        msg.innerHTML = '<span class="material-symbols-rounded" style="color:var(--warning,#B07000)">warning</span>' +
          'รูปแบบลิงก์ถูกต้อง แต่ไม่พบวิดีโอรหัส <code>' + m[1] + '</code> บน YouTube — ตรวจสอบว่าวิดีโอถูกลบ ตั้งเป็นส่วนตัว หรือคัดลอกลิงก์มาผิดหรือไม่';
      };
    }
    vidUrlInput.addEventListener('input', vidRender);
    vidRender();
  }

  /* ─── ตัวเลือกไอคอน (Material Symbols) — คลิกเลือกจากรายการแทนต้องจำ/พิมพ์ชื่อไอคอนเอง ───
     ใช้กับ input ทุกจุดที่มี data-icon-input (หมวดเอกสาร/ลิงก์หน่วยงาน/ประเภทเนื้อหา ฯลฯ)
     รายชื่อไอคอนคัดมาเฉพาะที่เข้ากับงานราชการ ไม่ใช่ทั้งชุด (หลักพันตัว) เพื่อให้เลือกง่าย —
     พิมพ์เองในช่องได้เสมอถ้าต้องการไอคอนนอกรายการนี้ (preview จะอัปเดตสดตามที่พิมพ์) */
  var ICON_GROUPS = [
    { g: 'หมวดหมู่ทั่วไป', i: ['folder', 'folder_open', 'category', 'label', 'bookmark', 'tag', 'apps', 'grid_view', 'dashboard', 'widgets', 'list', 'view_list', 'table_chart', 'layers'] },
    { g: 'เอกสาร', i: ['description', 'article', 'menu_book', 'auto_stories', 'library_books', 'receipt_long', 'assignment', 'fact_check', 'summarize', 'request_quote', 'picture_as_pdf', 'insert_drive_file', 'drafts', 'history_edu', 'edit_document', 'content_paste', 'checklist', 'rule'] },
    { g: 'กฎหมาย/ราชการ', i: ['gavel', 'account_balance', 'policy', 'verified', 'verified_user', 'shield', 'security', 'badge', 'workspace_premium', 'corporate_fare', 'apartment', 'domain', 'flag', 'balance'] },
    { g: 'คน/องค์กร', i: ['group', 'groups', 'groups_2', 'person', 'supervisor_account', 'diversity_3', 'school', 'engineering', 'local_police', 'support_agent', 'family_restroom', 'elderly', 'child_care'] },
    { g: 'สาธารณสุข', i: ['health_and_safety', 'medical_services', 'local_hospital', 'vaccines', 'emergency', 'accessible'] },
    { g: 'การสื่อสาร', i: ['mail', 'forum', 'chat', 'campaign', 'notifications', 'contact_support', 'call', 'sms', 'rss_feed', 'feed', 'mark_email_read', 'question_answer'] },
    { g: 'สื่อ/ภาพ', i: ['image', 'photo_camera', 'videocam', 'collections', 'palette', 'brush', 'movie', 'mic', 'theaters', 'slideshow'] },
    { g: 'สถานที่/ขนส่ง', i: ['place', 'map', 'location_on', 'home', 'storefront', 'business_center', 'warehouse', 'factory', 'directions_car', 'local_shipping', 'train', 'flight', 'directions_bus'] },
    { g: 'การเงิน', i: ['payments', 'account_balance_wallet', 'receipt', 'savings', 'paid', 'price_check', 'monetization_on', 'currency_exchange'] },
    { g: 'การกระทำ/สัญลักษณ์', i: ['star', 'favorite', 'thumb_up', 'check_circle', 'info', 'warning', 'priority_high', 'schedule', 'event', 'calendar_month', 'download', 'upload_file', 'link', 'open_in_new', 'search', 'filter_list', 'sort', 'print', 'qr_code', 'key', 'lock', 'lock_open', 'visibility', 'send'] },
    { g: 'สิ่งแวดล้อม', i: ['eco', 'recycling', 'water_drop', 'park', 'forest', 'sunny', 'cloud'] },
    { g: 'เทคโนโลยี', i: ['computer', 'smartphone', 'wifi', 'storage', 'code', 'terminal', 'memory', 'hub', 'cloud_upload', 'cloud_download', 'qr_code_scanner'] }
  ];

  document.querySelectorAll('[data-icon-input]').forEach(function (input) {
    var wrap = input.closest('.icon-pick-wrap');
    if (!wrap) return;
    var preview = wrap.querySelector('[data-icon-preview]');
    var fallback = input.getAttribute('data-icon-default') || 'folder';

    function setPreview(name) { if (preview) preview.textContent = name.trim() || fallback; }
    input.addEventListener('input', function () { setPreview(input.value); });

    var panel = document.createElement('div');
    panel.className = 'icon-pick-panel';
    panel.innerHTML = '<input type="text" class="icon-pick-search" placeholder="ค้นหาไอคอน..." autocomplete="off">'
      + '<div class="icon-pick-body"></div>';
    wrap.appendChild(panel);
    var searchBox = panel.querySelector('.icon-pick-search');
    var body = panel.querySelector('.icon-pick-body');

    function renderList(q) {
      q = (q || '').trim().toLowerCase();
      body.innerHTML = '';
      var found = 0;
      ICON_GROUPS.forEach(function (grp) {
        var names = q ? grp.i.filter(function (n) { return n.indexOf(q) !== -1; }) : grp.i;
        if (!names.length) return;
        found += names.length;
        var h = document.createElement('div');
        h.className = 'icon-pick-group';
        h.textContent = grp.g;
        body.appendChild(h);
        var grid = document.createElement('div');
        grid.className = 'icon-pick-grid';
        names.forEach(function (name) {
          var it = document.createElement('div');
          it.className = 'icon-pick-item material-symbols-rounded';
          it.textContent = name;
          it.title = name;
          it.tabIndex = 0;
          it.addEventListener('click', function () {
            input.value = name;
            setPreview(name);
            closePanel();
          });
          grid.appendChild(it);
        });
        body.appendChild(grid);
      });
      if (!found) {
        var empty = document.createElement('div');
        empty.className = 'icon-pick-empty';
        empty.textContent = 'ไม่พบไอคอนที่ตรงกับ "' + q + '" — พิมพ์ชื่อไอคอนเองในช่องด้านบนได้เลย';
        body.appendChild(empty);
      }
    }

    function openPanel() {
      renderList('');
      searchBox.value = '';
      panel.classList.add('open');
    }
    function closePanel() { panel.classList.remove('open'); }

    input.addEventListener('focus', openPanel);
    input.addEventListener('click', openPanel);
    if (preview) preview.addEventListener('click', function () { input.focus(); openPanel(); });
    searchBox.addEventListener('input', function () { renderList(searchBox.value); });
    document.addEventListener('click', function (ev) {
      if (!wrap.contains(ev.target)) closePanel();
    });
    wrap.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { closePanel(); input.blur(); }
    });
  });
})();
