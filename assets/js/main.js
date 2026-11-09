/* ════════════════════════════════════════════════════════════
   main.js — อนิเมชันและลูกเล่นฝั่งหน้าเว็บ (vanilla JS เท่านั้น)
   ระดับอนิเมชัน: 2 = จัดเต็ม, 1 = น้อย, 0 = ปิด/prefers-reduced-motion
   ════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var body = document.body;
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var LEVEL = (body.classList.contains('anim-off') || reduced) ? 0
            : body.classList.contains('anim-min') ? 1 : 2;

  /* ─── ปุ่มคัดลอกลิงก์ + พิมพ์ (หน้าข่าว) ─── */
  document.querySelectorAll('[data-copy-link]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var link = btn.getAttribute('data-copy-link');
      var done = function () {
        var old = btn.innerHTML;
        btn.classList.add('copied');
        btn.innerHTML = '<span class="material-symbols-rounded icon-sm">check</span>คัดลอกแล้ว';
        setTimeout(function () { btn.classList.remove('copied'); btn.innerHTML = old; }, 1800);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(link).then(done, done);
      } else {
        var t = document.createElement('textarea'); t.value = link; document.body.appendChild(t);
        t.select(); try { document.execCommand('copy'); } catch (e) {} t.remove(); done();
      }
    });
  });
  document.querySelectorAll('[data-print]').forEach(function (btn) {
    btn.addEventListener('click', function () { window.print(); });
  });

  /* ─── เมนูมือถือ (hamburger) ─── */
  var menuBtn = document.querySelector('.menu-btn');
  if (menuBtn) {
    menuBtn.addEventListener('click', function () {
      document.querySelector('.nav').classList.toggle('open');
    });
  }

  /* ─── เมนูย่อย (dropdown) บนแถบเมนู ───
     บนจอคอมพิวเตอร์เมนูย่อยกางด้วยการชี้เมาส์อยู่แล้ว แต่จอสัมผัสไม่มี hover
     และคนที่ใช้คีย์บอร์ดอย่างเดียวก็เข้าไม่ถึง จึงต้องมีปุ่มกดเปิด/ปิดด้วย
     (บนมือถือ CSS กางเมนูย่อยค้างไว้และซ่อนปุ่มนี้ โค้ดตรงนี้จึงไม่ถูกเรียก) */
  document.querySelectorAll('.nav-subtoggle').forEach(function (btn) {
    var box = btn.closest('.nav-has-sub');
    if (!box) return;
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      var open = !box.classList.contains('open');
      /* เปิดได้ทีละอันเดียว ไม่งั้นเมนูย่อยหลายชุดจะซ้อนทับกัน */
      document.querySelectorAll('.nav-has-sub.open').forEach(function (o) {
        if (o !== box) {
          o.classList.remove('open');
          o.querySelectorAll('.nav-subtoggle').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
        }
      });
      box.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('.nav-has-sub')) return;
    document.querySelectorAll('.nav-has-sub.open').forEach(function (o) {
      o.classList.remove('open');
      o.querySelectorAll('.nav-subtoggle').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
    });
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    document.querySelectorAll('.nav-has-sub.open').forEach(function (o) {
      o.classList.remove('open');
      o.querySelectorAll('.nav-subtoggle').forEach(function (b) {
        b.setAttribute('aria-expanded', 'false');
        b.focus();
      });
    });
  });

  /* ─── 8) Topbar หดเมื่อเลื่อน ─── */
  var topbar = document.querySelector('.topbar');
  var ticking = false;
  function onScroll() {
    if (topbar) topbar.classList.toggle('scrolled', window.scrollY > 16);
    updateToTop();
    ticking = false;
  }
  window.addEventListener('scroll', function () {
    if (!ticking) { requestAnimationFrame(onScroll); ticking = true; }
  }, { passive: true });

  /* ─── 1) Scroll reveal แบบ stagger ─── */
  var reveals = document.querySelectorAll('.reveal, .reveal-left, .reveal-right');
  if (LEVEL === 0) {
    reveals.forEach(function (el) { el.classList.add('in'); });
  } else if ('IntersectionObserver' in window) {
    // stagger: ไล่ลำดับการ์ดในกลุ่มเดียวกัน ใบละ 80ms
    document.querySelectorAll('[data-reveal-group]').forEach(function (group) {
      var i = 0;
      group.querySelectorAll('.reveal').forEach(function (el) {
        el.style.setProperty('--rd', (i++ * 80) + 'ms');
      });
    });
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    reveals.forEach(function (el) { io.observe(el); });
  } else {
    reveals.forEach(function (el) { el.classList.add('in'); });
  }

  /* ─── 2) ตัวเลขสถิติวิ่ง (count-up) ─── */
  function countUp(el) {
    var target = parseInt(el.getAttribute('data-count') || '0', 10);
    if (LEVEL < 2) { el.textContent = target.toLocaleString('th-TH'); return; }
    var dur = 1200, t0 = null;
    function step(ts) {
      if (!t0) t0 = ts;
      var p = Math.min((ts - t0) / dur, 1);
      var eased = 1 - Math.pow(1 - p, 3); // easeOutCubic
      el.textContent = Math.round(target * eased).toLocaleString('th-TH');
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }
  var counters = document.querySelectorAll('[data-count]');
  if (counters.length) {
    if ('IntersectionObserver' in window && LEVEL === 2) {
      var cio = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) { countUp(en.target); cio.unobserve(en.target); }
        });
      }, { threshold: 0.4 });
      counters.forEach(function (el) { cio.observe(el); });
    } else {
      counters.forEach(countUp);
    }
  }

  /* ─── 3) แบนเนอร์สไลด์ ─── */
  var slider = document.getElementById('hslider');
  if (slider) {
    var slides = slider.querySelectorAll('.slide');
    var dots = slider.querySelectorAll('.dots button');
    var progBar = slider.querySelector('.hs-prog > i');
    var INTERVAL = parseInt(slider.getAttribute('data-interval') || '6000', 10);
    var cur = 0, paused = false, elapsed = 0;

    // ระยะแสดงของสไลด์ปัจจุบัน (data-dur วินาที override ค่ารวม)
    function curInterval() {
      var d = parseInt(slides[cur].getAttribute('data-dur') || '0', 10);
      return d > 0 ? d * 1000 : INTERVAL;
    }

    function show(n) {
      slides[cur].classList.remove('show');
      if (dots[cur]) { dots[cur].classList.remove('on'); dots[cur].setAttribute('aria-selected', 'false'); }
      cur = (n + slides.length) % slides.length;
      slides[cur].classList.add('show');
      if (dots[cur]) { dots[cur].classList.add('on'); dots[cur].setAttribute('aria-selected', 'true'); }
      elapsed = 0;
    }

    if (slides.length > 1) {
      // progress bar + auto advance ด้วย rAF เดียว (ลื่นและ pause ได้)
      var last = performance.now();
      var rafLoop = function (now) {
        var dt = now - last; last = now;
        if (!paused && !document.hidden) {
          var iv = curInterval();
          elapsed += dt;
          if (elapsed >= iv) show(cur + 1);
          if (progBar && LEVEL === 2) progBar.style.width = Math.min(elapsed / iv, 1) * 100 + '%';
        }
        requestAnimationFrame(rafLoop);
      };
      requestAnimationFrame(rafLoop);

      // หยุดเมื่อ hover
      slider.addEventListener('mouseenter', function () { paused = true; });
      slider.addEventListener('mouseleave', function () { paused = false; });

      // จุดเลือกสไลด์
      dots.forEach(function (d, i) { d.addEventListener('click', function () { show(i); }); });

      // swipe บนมือถือ
      var touchX = null;
      slider.addEventListener('touchstart', function (ev) {
        touchX = ev.touches[0].clientX; paused = true;
      }, { passive: true });
      slider.addEventListener('touchend', function (ev) {
        if (touchX !== null) {
          var dx = ev.changedTouches[0].clientX - touchX;
          if (Math.abs(dx) > 45) show(cur + (dx < 0 ? 1 : -1));
        }
        touchX = null; paused = false;
      }, { passive: true });
    }
  }

  /* ─── 9) Ticker: ทำซ้ำเนื้อหาให้วนต่อเนื่อง + ตั้งความเร็วตามความยาว ─── */
  var tickerTrack = document.querySelector('.ticker-track');
  if (tickerTrack && LEVEL === 2 && tickerTrack.children.length) {
    tickerTrack.innerHTML += tickerTrack.innerHTML; // วนรอบไร้รอยต่อ
    var w = tickerTrack.scrollWidth / 2;
    tickerTrack.style.setProperty('--ticker-dur', Math.max(18, Math.round(w / 55)) + 's');
  }

  /* ─── 6) Ripple ตอนคลิกปุ่ม ─── */
  if (LEVEL > 0) {
    document.addEventListener('click', function (ev) {
      var btn = ev.target.closest('.btn, .tool, .cat');
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

  /* ─── 10) Lazy-load รูปแบบ blur-up ─── */
  document.querySelectorAll('img.lazyimg').forEach(function (img) {
    if (img.complete && img.naturalWidth) { img.classList.add('loaded'); return; }
    img.addEventListener('load', function () { img.classList.add('loaded'); });
    img.addEventListener('error', function () { img.classList.add('loaded'); });
  });

  /* ─── 11) Lightbox รูปข่าว ─── */
  var lbTriggers = document.querySelectorAll('[data-lightbox]');
  if (lbTriggers.length) {
    var lb = document.createElement('div');
    lb.className = 'lightbox';
    lb.innerHTML = '<button class="lb-close" aria-label="ปิด"><span class="material-symbols-rounded">close</span></button><img alt="">';
    document.body.appendChild(lb);
    var lbImg = lb.querySelector('img');
    function openLb(src) { lbImg.src = src; lb.classList.add('show'); body.style.overflow = 'hidden'; }
    function closeLb() { lb.classList.remove('show'); body.style.overflow = ''; }
    lbTriggers.forEach(function (el) {
      el.addEventListener('click', function (ev) {
        ev.preventDefault();
        openLb(el.getAttribute('data-lightbox') || el.src);
      });
    });
    lb.addEventListener('click', function (ev) { if (ev.target !== lbImg) closeLb(); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') closeLb(); });
  }

  /* ─── 12) ปุ่มกลับขึ้นบน + วงแหวน progress ─── */
  var totop = document.getElementById('totop');
  var ring = totop ? totop.querySelector('circle') : null;
  var CIRC = 2 * Math.PI * 22;
  if (ring) { ring.style.strokeDasharray = CIRC; ring.style.strokeDashoffset = CIRC; }
  function updateToTop() {
    if (!totop) return;
    var max = document.documentElement.scrollHeight - window.innerHeight;
    var p = max > 0 ? window.scrollY / max : 0;
    var shown = window.scrollY > 600;
    totop.classList.toggle('show', shown);
    body.classList.toggle('totop-on', shown); // เลื่อนปุ่มช่วยการเข้าถึงขึ้นเหนือปุ่มกลับขึ้นบน
    if (ring) ring.style.strokeDashoffset = CIRC * (1 - p);
  }
  if (totop) {
    totop.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: LEVEL === 0 ? 'auto' : 'smooth' });
    });
    updateToTop();
  }

  /* ─── 13) FAQ accordion ─── */
  document.querySelectorAll('.faq-q').forEach(function (q) {
    q.addEventListener('click', function () {
      q.closest('.faq-item').classList.toggle('open');
    });
  });

  /* ─── Cookie consent (PDPA) ───
     "ปฏิเสธ" ต้องมีผลจริง: ส่งค่าไปฝั่งเซิร์ฟเวอร์ผ่านคุกกี้ เพื่อให้ระบบงดเก็บสถิติผู้เข้าชม
     และต้องเพิกถอนความยินยอมภายหลังได้ (ปุ่ม "ตั้งค่าคุกกี้") */
  var cookiebar = document.getElementById('cookiebar');
  var cookieReopen = document.getElementById('cookieReopen');

  function readConsent() {
    try { return localStorage.getItem('cookieConsent'); } catch (e) { return null; }
  }
  function writeConsent(v) {
    try { localStorage.setItem('cookieConsent', v); } catch (e) {}
    /* คุกกี้นี้ให้ PHP อ่านได้ เพื่อตัดสินใจว่าจะนับสถิติหรือไม่ (1 ปี) */
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = 'cookie_consent=' + encodeURIComponent(v) +
      '; Max-Age=31536000; Path=/; SameSite=Lax' + secure;
  }

  if (cookiebar) {
    var current = readConsent();
    if (!current) cookiebar.classList.add('show');
    else { writeConsent(current); if (cookieReopen) cookieReopen.hidden = false; }  /* ซิงก์คุกกี้ให้เซิร์ฟเวอร์ */

    cookiebar.querySelectorAll('[data-consent]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        writeConsent(btn.getAttribute('data-consent'));
        cookiebar.classList.remove('show');
        if (cookieReopen) cookieReopen.hidden = false;
      });
    });
  }

  /* เปิดแถบขึ้นมาใหม่เพื่อเปลี่ยน/เพิกถอนความยินยอม */
  if (cookieReopen && cookiebar) {
    cookieReopen.addEventListener('click', function () {
      cookiebar.classList.add('show');
      cookieReopen.hidden = true;
    });
  }

  /* ─── แถบประกาศด่วน: ปิดได้ต่อเซสชัน (จำด้วย key ของข้อความ) ─── */
  var alertBar = document.getElementById('alertBar');
  if (alertBar) {
    var aKey = 'alertDismiss_' + (alertBar.getAttribute('data-key') || '');
    try { if (sessionStorage.getItem(aKey) === '1') alertBar.style.display = 'none'; } catch (e) {}
    var aClose = document.getElementById('alertClose');
    if (aClose) aClose.addEventListener('click', function () {
      alertBar.style.display = 'none';
      try { sessionStorage.setItem(aKey, '1'); } catch (e) {}
    });
  }

  /* ─── PWA: ลงทะเบียน service worker + ปุ่มติดตั้งแอป ─── */
  var BASE = (typeof window.APP_BASE === 'string' && window.APP_BASE) ? window.APP_BASE : './';
  if (BASE.charAt(BASE.length - 1) !== '/') BASE += '/';
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(BASE + 'sw.js', { scope: BASE }).catch(function () {});
    });
  }
  // ปุ่ม "ติดตั้งแอป" จะโผล่เมื่อเบราว์เซอร์พร้อมให้ติดตั้ง (beforeinstallprompt)
  var deferredPrompt = null;
  window.addEventListener('beforeinstallprompt', function (ev) {
    ev.preventDefault();
    deferredPrompt = ev;
    var btn = document.getElementById('pwaInstall');
    if (!btn) {
      btn = document.createElement('button');
      btn.id = 'pwaInstall';
      btn.className = 'pwa-install';
      btn.innerHTML = '<span class="material-symbols-rounded">install_mobile</span>ติดตั้งแอป';
      document.body.appendChild(btn);
    }
    btn.style.display = 'inline-flex';
    btn.addEventListener('click', function () {
      btn.style.display = 'none';
      if (!deferredPrompt) return;
      deferredPrompt.prompt();
      deferredPrompt.userChoice.finally(function () { deferredPrompt = null; });
    });
  });
  window.addEventListener('appinstalled', function () {
    var btn = document.getElementById('pwaInstall');
    if (btn) btn.style.display = 'none';
  });
})();

/* ── section วิดีโอ: สลับวิดีโอในจอใหญ่ + โหลดตัวเล่นเมื่อกดเล่นเท่านั้น ──
   ก่อนกดเล่นจะมีแค่ <img> ภาพปก ไม่มี iframe YouTube — หน้าแรกจึงเบาและไม่มีคุกกี้ติดตาม */
(function () {
  var sec = document.querySelector('[data-video-section]');
  if (!sec) return;

  var stage = sec.querySelector('[data-vid-stage]');
  if (!stage) return;

  function embedSrc(id) {
    return 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(id) +
           '?rel=0&modestbranding=1&playsinline=1&autoplay=1';
  }

  /* แทนภาพปกด้วย iframe จริง — เรียกเมื่อผู้ใช้กดเล่น */
  function play() {
    var id = stage.getAttribute('data-vid');
    if (!id || stage.querySelector('iframe')) return;
    var fr = document.createElement('iframe');
    fr.src = embedSrc(id);
    fr.title = (sec.querySelector('[data-vid-title]') || {}).textContent || 'วิดีโอ';
    fr.allow = 'accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture';
    fr.setAttribute('allowfullscreen', '');
    fr.className = 'vid-stage-frame';
    stage.appendChild(fr);
    stage.classList.add('is-playing');
  }

  /* กลับไปเป็นภาพปก (ใช้ตอนสลับวิดีโอ) — ถอด iframe ทิ้งเพื่อหยุดเสียงตัวเดิม */
  function reset(thumb, id, time) {
    var fr = stage.querySelector('iframe');
    if (fr) fr.remove();
    stage.classList.remove('is-playing');
    stage.setAttribute('data-vid', id);
    var img = stage.querySelector('.vid-stage-img');
    if (img && thumb) { img.classList.remove('is-missing'); img.src = thumb; }
    var t = stage.querySelector('[data-vid-time]');
    if (t) { t.textContent = time || ''; t.hidden = !time; }
  }

  var playBtn = stage.querySelector('[data-vid-play]');
  if (playBtn) playBtn.addEventListener('click', play);

  sec.querySelectorAll('[data-vid-pick]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var d = btn.dataset;
      reset(d.thumb, d.vid, d.time);

      var title = sec.querySelector('[data-vid-title]');
      if (title) title.textContent = d.title || '';

      var desc = sec.querySelector('[data-vid-desc]');
      if (desc) { desc.textContent = d.desc || ''; desc.hidden = !d.desc; }

      var date = sec.querySelector('[data-vid-date]');
      if (date) {
        date.hidden = !d.date;
        var span = date.querySelector('span:last-child');
        if (span && d.date) span.textContent = d.date;
      }

      sec.querySelectorAll('[data-vid-pick]').forEach(function (b) { b.classList.remove('is-active'); });
      btn.classList.add('is-active');

      play(); /* คลิกในรายการ = ตั้งใจดูอยู่แล้ว เล่นต่อทันที */
    });
  });

})();

/* ─── นิทรรศการโปสเตอร์ ───
   แยกเป็น IIFE ของตัวเองเหมือนบล็อกวิดีโอ ไม่ใช่ต่อท้ายบล็อกอื่น —
   บล็อกวิดีโอมี 'if (!sec) return;' อยู่บนสุด ถ้าเอาโค้ดนี้ไปวางไว้ข้างใน
   หน้าที่ไม่มี section วิดีโอจะไม่รันโค้ดนี้เลย (เจอจริงตอนทดสอบ) */
(function () {
  'use strict';
  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var LEVEL = (document.body.classList.contains('anim-off') || reducedMotion) ? 0 : 2;
  /* ─── นิทรรศการโปสเตอร์ ───────────────────────────────────
     รองรับสามแบบในตัวเดียว: เวที (stage) / แถบเลื่อน (strip) / จางสลับ (fade)
     สองแบบแรกใช้การเลื่อนแนวนอนจริงของเบราว์เซอร์ ไม่ได้ย้าย transform เอง
     จึงปัดนิ้ว เลื่อนเทร็กแพด และใช้คีย์บอร์ดได้ตามธรรมชาติโดยไม่ต้องเขียนเพิ่ม */
  document.querySelectorAll('[data-poster]').forEach(function (stage) {
    var track = stage.querySelector('.pstage-track');
    var cards = Array.prototype.slice.call(stage.querySelectorAll('.pcard'));
    var dots  = Array.prototype.slice.call(stage.querySelectorAll('.ps-dots button'));
    var thumbs = Array.prototype.slice.call(stage.querySelectorAll('.ps-thumb'));
    var caps   = Array.prototype.slice.call(stage.querySelectorAll('.ps-cap'));
    var strip  = stage.querySelector('.ps-thumbs');
    if (!track || cards.length === 0) return;

    var isFade   = stage.classList.contains('st-fade');
    var isStage  = stage.classList.contains('st-stage');
    var isCinema = stage.classList.contains('st-cinema');
    var swaps    = isFade || isCinema;      /* สองโหมดนี้สลับใบอยู่กับที่ ไม่ได้เลื่อนแถว */
    var cur = 0;

    /* เล่นอัตโนมัติ — หยุดเมื่อเมาส์อยู่บนกล่อง โฟกัสอยู่ข้างใน หรือแท็บถูกซ่อน
       คนกำลังอ่านโปสเตอร์อยู่แล้วภาพเลื่อนหนีคือสิ่งที่น่ารำคาญที่สุดของสไลด์โชว์ */
    function startAuto(step) {
      if (LEVEL === 0) return;                       /* ผู้ใช้ขอให้ลดการเคลื่อนไหว */
      if (stage.getAttribute('data-auto') !== '1') return;
      if (cards.length < 2) return;
      var ivl = Math.max(2000, parseInt(stage.getAttribute('data-interval') || '5000', 10));
      var hold = false;
      var setHold = function (v) { hold = v; stage.classList.toggle('paused', v); };
      stage.addEventListener('mouseenter', function () { setHold(true); });
      stage.addEventListener('mouseleave', function () { setHold(false); });
      stage.addEventListener('focusin',  function () { hold = true; });
      stage.addEventListener('focusout', function () { hold = false; });
      setInterval(function () { if (!hold && !document.hidden) step(); }, ivl);
    }

    function mark(i) {
      if (i < 0 || i >= cards.length) return;
      cur = i;
      cards.forEach(function (c, n) { c.classList.toggle('on', n === i); });
      dots.forEach(function (d, n) {
        d.classList.toggle('on', n === i);
        d.setAttribute('aria-selected', n === i ? 'true' : 'false');
      });
      caps.forEach(function (c, n) { c.classList.toggle('on', n === i); });
      thumbs.forEach(function (b, n) {
        b.classList.toggle('on', n === i);
        b.setAttribute('aria-selected', n === i ? 'true' : 'false');
      });
      /* เลื่อนแฟ้มภาพให้ใบที่กำลังฉายอยู่ในสายตาเสมอ — ใช้ scrollLeft ตรงๆ ไม่ใช้ scrollIntoView
         เพราะ scrollIntoView จะพาทั้งหน้าเว็บเลื่อนตามไปด้วยขณะที่คนกำลังอ่านอย่างอื่นอยู่ */
      if (strip && thumbs[i] && strip.scrollWidth > strip.clientWidth) {
        strip.scrollLeft = thumbs[i].offsetLeft + thumbs[i].offsetWidth / 2 - strip.clientWidth / 2;
      }
      /* โหมดสลับใบวนกลับมาใบแรกได้ ปุ่มจึงไม่ต้องถูกปิด ส่วนโหมดเลื่อนแถวมีจุดสุดทางจริง */
    }

    /* ── แบบสลับใบอยู่กับที่ (จางสลับ / โรงฉาย) ── */
    if (swaps) {
      mark(0);
      /* อนิเมชันซูมและแถบเวลาผูกกับคลาส .on และ .playing — พอเปลี่ยนใบต้องสั่งให้เริ่มรอบใหม่
         การอ่าน offsetWidth คือการบังคับให้เบราว์เซอร์คำนวณเลย์เอาต์ทันที ไม่งั้นการถอด
         แล้วใส่คลาสกลับในจังหวะเดียวกันจะถูกยุบรวมเป็นไม่มีอะไรเกิดขึ้น อนิเมชันก็ไม่เริ่มใหม่ */
      var replay = function () {
        if (!isCinema) return;
        stage.classList.remove('playing');
        void stage.offsetWidth;
        if (stage.getAttribute('data-auto') === '1' && LEVEL !== 0) stage.classList.add('playing');
      };
      var go = function (i) { mark((i + cards.length) % cards.length); replay(); };
      dots.forEach(function (d, n) { d.addEventListener('click', function () { go(n); }); });
      thumbs.forEach(function (b, n) { b.addEventListener('click', function () { go(n); }); });
      replay();
      startAuto(function () { go(cur + 1); });
      return;
    }

    /* ── แบบเลื่อน: ใบที่ "อยู่กลางเวที" คือใบที่กำลังถูกดู ── */
    function centerOf(el) { return el.offsetLeft + el.offsetWidth / 2; }
    function nearest() {
      var mid = track.scrollLeft + track.clientWidth / 2, best = 0, bd = Infinity;
      cards.forEach(function (c, n) {
        var d = Math.abs(centerOf(c) - mid);
        if (d < bd) { bd = d; best = n; }
      });
      return best;
    }
    /* เลื่อนเองทีละเฟรมแทนการพึ่ง scroll-behavior:smooth — บางเบราว์เซอร์ยกเลิกอนิเมชัน
       ของเบราว์เซอร์ทิ้งเมื่อใช้คู่กับ scroll snap แล้วเด้งกลับที่เดิม (ทดสอบเจอจริง) */
    var anim = null;
    function goTo(i) {
      i = Math.max(0, Math.min(cards.length - 1, i));
      var to = Math.max(0, Math.min(track.scrollWidth - track.clientWidth,
                                    centerOf(cards[i]) - track.clientWidth / 2));
      if (anim) cancelAnimationFrame(anim);
      if (LEVEL === 0) { track.scrollLeft = to; mark(i); return; }   /* ขอให้ลดการเคลื่อนไหว = ไปทันที */
      var from = track.scrollLeft, t0 = performance.now(), ms = 420, done = false;
      var land = function () {                 /* ปิดงานให้เรียบร้อยไม่ว่าจะมาทางไหน */
        if (done) return;
        done = true;
        if (anim) { cancelAnimationFrame(anim); anim = null; }
        track.scrollLeft = to;
        mark(i);
      };
      var step = function (now) {
        if (done) return;
        var k = Math.min(1, (now - t0) / ms);
        k = k < .5 ? 4 * k * k * k : 1 - Math.pow(-2 * k + 2, 3) / 2;   /* ease-in-out */
        track.scrollLeft = from + (to - from) * k;
        if (k < 1) anim = requestAnimationFrame(step); else land();
      };
      anim = requestAnimationFrame(step);
      /* บางเบราว์เซอร์ฝัง (webview/แท็บที่ถูกพักไว้) ไม่เรียก rAF เลย ถ้าไม่มีตัวรับประกันแบบนี้
         ปุ่มลูกศรกับจุดบอกตำแหน่งจะกดแล้วไม่เกิดอะไรขึ้นทั้งหมด — ยอมให้ไม่นุ่ม ดีกว่ากดไม่ติด */
      setTimeout(land, ms + 120);
    }

    /* ใบแรกและใบสุดท้ายต้องเลื่อนมาอยู่กึ่งกลางได้ด้วย จึงต้องเว้นขอบเท่าครึ่งการ์ดจริง
       คำนวณจาก JS เพราะการ์ดแต่ละใบกว้างไม่เท่ากัน (สัดส่วนภาพต่างกัน) CSS อย่างเดียวรู้ไม่ได้ */
    function pad() {
      if (!isStage) return;
      var half = track.clientWidth / 2;
      track.style.paddingLeft  = Math.max(0, half - cards[0].offsetWidth / 2) + 'px';
      track.style.paddingRight = Math.max(0, half - cards[cards.length - 1].offsetWidth / 2) + 'px';
    }

    var tick = null;
    track.addEventListener('scroll', function () {
      if (tick) return;                       /* จำกัดความถี่ด้วยเวลา ไม่ใช้ rAF ด้วยเหตุผลเดียวกับด้านบน */
      tick = setTimeout(function () { tick = null; mark(nearest()); }, 90);
    }, { passive: true });

    dots.forEach(function (d, n) { d.addEventListener('click', function () { goTo(n); }); });
    window.addEventListener('resize', function () { pad(); goTo(cur); });

    pad();
    mark(0);
    startAuto(function () { goTo(cur >= cards.length - 1 ? 0 : cur + 1); });
  });

  /* ─── ดูโปสเตอร์ขนาดเต็ม ─── */
  (function () {
    var zoomable = document.querySelectorAll('[data-ps-zoom]');
    if (!zoomable.length) return;
    var box = null;

    function onKey(e) { if (e.key === 'Escape') close(); }
    function close() {
      if (!box) return;
      var b = box; box = null;
      b.classList.remove('on');
      setTimeout(function () { if (b.parentNode) b.parentNode.removeChild(b); }, 250);
      document.removeEventListener('keydown', onKey);
    }

    zoomable.forEach(function (img) {
      img.addEventListener('click', function (e) {
        /* การ์ดที่ผูกลิงก์ไว้ ให้ลิงก์ทำงานตามปกติ ไม่แย่งคลิกไปเปิดภาพ */
        if (img.closest('a')) return;
        e.preventDefault();
        box = document.createElement('div');
        box.className = 'ps-lightbox';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ps-close';
        btn.setAttribute('aria-label', 'ปิด');
        btn.innerHTML = '<span class="material-symbols-rounded">close</span>';
        var big = document.createElement('img');
        big.src = img.currentSrc || img.src;
        big.alt = img.alt || '';
        box.appendChild(btn);
        box.appendChild(big);
        box.addEventListener('click', close);
        document.body.appendChild(box);
        requestAnimationFrame(function () { box.classList.add('on'); });
        document.addEventListener('keydown', onKey);
      });
    });
  })();})();

/* ════════════════════════════════════════════════════════════
   ข่าวด่วน · ประชาสัมพันธ์ — popup เล่นวิดีโอ
   iframe ของ Facebook/YouTube/TikTok ถูกสร้างตอนผู้ชมกดเท่านั้น ก่อนหน้านั้นเห็นแค่ภาพปกของเรา
   (หน้าเว็บไม่ต้องโหลดของหนักจากโซเชียล และโซเชียลไม่ได้เก็บข้อมูลผู้ชมแค่เพราะเปิดหน้า)
   ปิด popup = ลบ iframe ทิ้ง วิดีโอจึงหยุดเล่นจริง ไม่ค้างเสียงอยู่เบื้องหลัง
   ════════════════════════════════════════════════════════════ */
(function () {
  var modal = null, lastFocus = null;

  function close() {
    if (!modal) return;
    modal.remove(); modal = null;
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onKey);
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  function onKey(ev) { if (ev.key === 'Escape') close(); }

  function open(a) {
    lastFocus = a;
    var title = a.getAttribute('data-hl-title') || '';
    var src = a.getAttribute('data-hl-src') || '';
    var plat = a.getAttribute('data-hl-plat') || 'ต้นฉบับ';
    var vertical = a.getAttribute('data-hl-vertical') === '1';

    modal = document.createElement('div');
    modal.className = 'hl-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-label', title);
    modal.innerHTML =
      '<div class="hl-modal-box' + (vertical ? ' is-vertical' : '') + '">' +
        '<div class="hl-modal-bar"><b class="hl-modal-title"></b>' +
          '<a class="hl-modal-src" target="_blank" rel="noopener noreferrer"><span class="material-symbols-rounded icon-sm">open_in_new</span><span></span></a>' +
          '<button type="button" class="hl-modal-close" aria-label="ปิด"><span class="material-symbols-rounded">close</span></button></div>' +
        '<div class="hl-frame"><iframe allow="autoplay; encrypted-media; picture-in-picture; fullscreen; clipboard-write" allowfullscreen></iframe></div>' +
      '</div>';
    /* ใส่ข้อความด้วย textContent ไม่ประกอบลง innerHTML — หัวข้อมาจากผู้ดูแล แต่ไม่ควรไว้ใจให้เป็น HTML */
    modal.querySelector('.hl-modal-title').textContent = title;
    var s = modal.querySelector('.hl-modal-src');
    if (src) { s.href = src; s.lastChild.textContent = 'เปิดใน ' + plat; } else { s.remove(); }
    var f = modal.querySelector('iframe');
    f.title = title || 'วิดีโอ';
    f.src = a.getAttribute('data-hl-play');

    modal.addEventListener('click', function (ev) { if (ev.target === modal) close(); });
    modal.querySelector('.hl-modal-close').addEventListener('click', close);
    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onKey);
    modal.querySelector('.hl-modal-close').focus();
  }

  document.addEventListener('click', function (ev) {
    var a = ev.target.closest && ev.target.closest('[data-hl-play]');
    if (!a) return;
    /* กดค้าง Ctrl/⌘/Shift หรือคลิกกลาง = ตั้งใจเปิดแท็บใหม่ ปล่อยให้ลิงก์ทำงานปกติ */
    if (ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button === 1) return;
    ev.preventDefault();
    open(a);
  });

  /* หน้าคลัง: เปิดมาจากลิงก์แชร์ (?h=) → เลื่อนไปที่เรื่องนั้นแล้วไฮไลต์ให้เห็นชัด */
  var grid = document.querySelector('[data-hl-focus]');
  if (grid) {
    var card = document.getElementById('h-' + grid.getAttribute('data-hl-focus'));
    if (card) {
      card.classList.add('is-target');
      /* behavior: instant — html ตั้ง scroll-behavior: smooth ไว้ทั้งเว็บ การเลื่อนแบบนุ่มตอนหน้าเพิ่งโหลด
         ถูกขัดจังหวะได้ง่าย (รูปที่โหลดตามมาดันความสูงหน้า) เปิดจากลิงก์แชร์กระโดดไปทันทีชัวร์กว่า */
      setTimeout(function () { card.scrollIntoView({ block: 'center', behavior: 'instant' }); }, 150);
      setTimeout(function () { card.classList.remove('is-target'); }, 4000);
    }
  }
})();

/* ══ สารบัญเอกสารแบบหนังสือ — ค้นหา · กรองสถานะ · ขยาย/ย่อ · สารบัญด้านข้าง · ลิงก์ตรงหัวข้อ (#t<id>) ══
   เนื้อหาเป็น <details> ล้วน ปิด JS ก็ยังกางอ่านได้ ส่วนนี้แค่เพิ่มความสะดวก */
(function () {
  var root = document.querySelector('[data-bk]');
  if (!root) return;
  var parts = Array.prototype.slice.call(root.querySelectorAll('.bk-part'));
  var tools = document.querySelector('[data-bk-tools]');
  var input = document.querySelector('[data-bk-q]');
  var countEl = document.querySelector('[data-bk-count]');
  var noneEl = document.querySelector('[data-bk-none]');
  var navBox = document.querySelector('[data-bk-navbox]');
  var navOl = navBox ? navBox.querySelector('ol') : null;
  var navLinks = {};
  document.querySelectorAll('[data-bk-nav]').forEach(function (a) { navLinks[a.getAttribute('data-bk-nav')] = a; });
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var filter = 'all';
  var unit = root.getAttribute('data-bk-unit') || 'หัวข้อ';   /* ชื่อเรียกชั้น 1 ของหมวด (ลักษณะ / ปี / กฎหมาย …) */

  if (tools) tools.hidden = false;
  var printBtn = document.querySelector('[data-bk-print]');
  if (printBtn) { printBtn.hidden = false; printBtn.addEventListener('click', function () { window.print(); }); }

  /* ความสูงหัวเว็บที่ติดบนจอ (ผู้ดูแลตั้งหัวเว็บแบบไม่ติดได้ → 0) */
  function setTop() {
    var hd = document.querySelector('.topbar'), h = 0;
    if (hd) { var pos = getComputedStyle(hd).position; if (pos === 'sticky' || pos === 'fixed') h = Math.round(hd.getBoundingClientRect().height); }
    document.querySelectorAll('.bk-cover, .bk-tools, .bk-layout').forEach(function (el) { el.style.setProperty('--bk-top', h + 'px'); });
  }
  setTop();
  window.addEventListener('resize', setTop);
  if (navBox && window.matchMedia('(min-width: 961px)').matches) navBox.open = true;

  /* เทียบคำค้น: ตัวเล็ก + เลขไทยเป็นอารบิก (ความยาวเท่าเดิม ตำแหน่งตัวอักษรจึงตรงกับข้อความจริง) */
  var TH = '๐๑๒๓๔๕๖๗๘๙';
  function fold(s) { return String(s || '').toLowerCase().replace(/[๐-๙]/g, function (d) { return String(TH.indexOf(d)); }); }

  parts.forEach(function (p) {
    p._rows = Array.prototype.slice.call(p.querySelectorAll('.bk-chap, .bk-amend'));
    p._all = [p.getAttribute('data-s')].concat(p._rows.map(function (r) { return r.getAttribute('data-s'); })).join(' ');
    p._rep = !!p.querySelector('.bk-chap[data-status="repealed"]');
  });

  function clearMarks() {
    root.querySelectorAll('mark.bk-mark').forEach(function (m) {
      var par = m.parentNode; par.replaceChild(document.createTextNode(m.textContent), m); par.normalize();
    });
  }
  function markIn(el, terms) {
    var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null), nodes = [], n;
    while ((n = walker.nextNode())) nodes.push(n);
    nodes.forEach(function (node) {
      var text = node.nodeValue, f = fold(text), hits = [];
      terms.forEach(function (t) { var i = f.indexOf(t); while (i > -1) { hits.push([i, i + t.length]); i = f.indexOf(t, i + t.length); } });
      if (!hits.length) return;
      hits.sort(function (a, b) { return a[0] - b[0]; });
      var frag = document.createDocumentFragment(), pos = 0;
      hits.forEach(function (h) {
        if (h[0] < pos) return;                                   /* คำค้นซ้อนกัน ข้ามช่วงที่ทับ */
        if (h[0] > pos) frag.appendChild(document.createTextNode(text.slice(pos, h[0])));
        var mk = document.createElement('mark'); mk.className = 'bk-mark'; mk.textContent = text.slice(h[0], h[1]);
        frag.appendChild(mk); pos = h[1];
      });
      if (pos < text.length) frag.appendChild(document.createTextNode(text.slice(pos)));
      node.parentNode.replaceChild(frag, node);
    });
  }

  function apply() {
    var terms = fold(input ? input.value : '').replace(/\s+/g, ' ').trim().split(' ').filter(Boolean);
    clearMarks();
    var shown = 0, rowHits = 0;
    parts.forEach(function (p) {
      var st = p.getAttribute('data-status');
      var okStatus = filter === 'all' || (filter === 'active' ? st === 'active' : (st === 'repealed' || p._rep));
      var okText = !terms.length || terms.every(function (t) { return p._all.indexOf(t) > -1; });
      var vis = okStatus && okText;
      p.hidden = !vis;
      if (navLinks[p.id.slice(1)]) navLinks[p.id.slice(1)].parentNode.hidden = !vis;
      /* ชั้น 2 ที่ยกเลิก: ซ่อนตอนดูสถานะปกติ · ดูสถานะยกเลิกในชั้น 1 ที่ยังมีผล = โชว์เฉพาะชั้น 2 ที่ยกเลิก */
      p._rows.forEach(function (r) {
        if (!r.classList.contains('bk-chap')) return;
        var rs = r.getAttribute('data-status');
        r.hidden = (filter === 'active' && rs === 'repealed') || (filter === 'repealed' && st !== 'repealed' && rs !== 'repealed');
      });
      if (!vis) return;
      shown++;
      if (terms.length) {
        p._rows.forEach(function (r) {
          var s = r.getAttribute('data-s');
          if (terms.every(function (t) { return s.indexOf(t) > -1; })) rowHits++;
        });
        if (!p.open) { p.open = true; p.setAttribute('data-bk-auto', ''); }
        markIn(p, terms);
      } else if (p.hasAttribute('data-bk-auto')) {
        p.open = false; p.removeAttribute('data-bk-auto');
      }
    });
    if (noneEl) noneEl.hidden = shown > 0;
    if (countEl) {
      countEl.textContent = (terms.length || filter !== 'all')
        ? 'แสดง ' + shown + ' จาก ' + parts.length + ' ' + unit + (terms.length && rowHits ? ' · พบในหัวข้อย่อย ' + rowHits + ' รายการ' : '')
        : '';
    }
  }

  var tm = null;
  if (input) {
    input.addEventListener('input', function () { clearTimeout(tm); tm = setTimeout(apply, 140); });
    input.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && input.value) { input.value = ''; apply(); } });
  }
  document.querySelectorAll('[data-bk-f]').forEach(function (b) {
    b.addEventListener('click', function () {
      filter = b.getAttribute('data-bk-f');
      document.querySelectorAll('[data-bk-f]').forEach(function (x) { x.setAttribute('aria-checked', x === b ? 'true' : 'false'); });
      apply();
    });
  });
  document.querySelectorAll('[data-bk-all]').forEach(function (b) {
    b.addEventListener('click', function () {
      var open = b.getAttribute('data-bk-all') === '1';
      parts.forEach(function (p) { if (!p.hidden) p.open = open; p.removeAttribute('data-bk-auto'); });
    });
  });

  /* ไปที่หัวข้อ: กางลักษณะที่ครอบอยู่ก่อน แล้วค่อยเลื่อน (หัวข้อในลักษณะที่ปิดอยู่ไม่มีตำแหน่งบนจอ) */
  function go(id, smooth) {
    var el = document.getElementById(id);
    if (!el || !root.contains(el)) return false;
    var part = el.closest('.bk-part');
    if (part) { part.hidden = false; part.open = true; }
    el.scrollIntoView({ block: el === part ? 'start' : 'center', behavior: smooth && !reduce ? 'smooth' : 'instant' });
    var flash = el.classList.contains('bk-part') ? el.querySelector('.bk-sum') : el;
    flash.classList.remove('bk-flash'); void flash.offsetWidth; flash.classList.add('bk-flash');
    return true;
  }
  Object.keys(navLinks).forEach(function (id) {
    navLinks[id].addEventListener('click', function (ev) {
      if (ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button === 1) return;
      ev.preventDefault();
      if (go('t' + id, true)) history.replaceState(null, '', '#t' + id);
      if (navBox && !window.matchMedia('(min-width: 961px)').matches) navBox.open = false;
    });
  });
  if (/^#t\d+$/.test(location.hash)) setTimeout(function () { go(location.hash.slice(1), false); }, 120);
  window.addEventListener('hashchange', function () { if (/^#t\d+$/.test(location.hash)) go(location.hash.slice(1), true); });

  /* สารบัญด้านข้างไฮไลต์ลักษณะที่กำลังอ่าน — เลื่อนรายการในกรอบเอง ไม่ใช้ scrollIntoView (จะลากทั้งหน้าไปด้วย) */
  if ('IntersectionObserver' in window && navOl) {
    var current = null;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        var a = navLinks[en.target.id.slice(1)];
        if (!a || a === current) return;
        if (current) current.classList.remove('on');
        current = a; a.classList.add('on');
        var top = a.offsetTop - navOl.offsetTop, h = navOl.clientHeight;
        if (top < navOl.scrollTop || top > navOl.scrollTop + h - 40) navOl.scrollTop = Math.max(0, top - h / 3);
      });
    }, { rootMargin: '-35% 0px -60% 0px' });
    parts.forEach(function (p) { io.observe(p); });
  }

  /* พิมพ์: กางทุกลักษณะ แล้วคืนสภาพเดิมหลังพิมพ์ */
  var before = [];
  window.addEventListener('beforeprint', function () { before = parts.map(function (p) { return p.open; }); parts.forEach(function (p) { p.open = true; }); });
  window.addEventListener('afterprint', function () { parts.forEach(function (p, i) { p.open = !!before[i]; }); });
})();

/* ══ แชร์เอกสาร: คัดลอกลิงก์ · LINE · Facebook · แชร์ของเครื่อง · QR Code (ดาวน์โหลด PNG/SVG) ══
   ปุ่มสร้างด้วย share_btn() ใน PHP — ลิงก์เป็นแบบภายในเว็บ ต่อ origin ของหน้าที่เปิดอยู่ตรงนี้
   (ได้ https/โดเมนจริงเสมอ แม้เซิร์ฟเวอร์อยู่หลัง CDN) · QR สร้างที่ share-qr.php ในเว็บเราเอง */
(function () {
  var modal = null, lastFocus = null;

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text) n.textContent = text; return n; }
  function close() {
    if (!modal) return;
    modal.remove(); modal = null;
    document.removeEventListener('keydown', onKey);
    document.body.style.overflow = '';
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  function onKey(ev) { if (ev.key === 'Escape') close(); }

  function copy(text, btn) {
    var done = function () { var t = btn.textContent; btn.textContent = 'คัดลอกแล้ว ✓'; btn.classList.add('ok'); setTimeout(function () { btn.textContent = t; btn.classList.remove('ok'); }, 1800); };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, fallback); } else { fallback(); }
    function fallback() {
      var inp = modal && modal.querySelector('.sh-link input');
      if (!inp) return;
      inp.focus(); inp.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) {}
      if (ok) { done(); return; }
      /* เบราว์เซอร์/นโยบายองค์กรบางแห่งปิดคลิปบอร์ด — เลือกลิงก์ค้างไว้ให้ แล้วบอกวิธีคัดลอกเอง ไม่ปล่อยให้กดแล้วเงียบ */
      var hint = modal.querySelector('.sh-hint');
      if (!hint) { hint = el('p', 'sh-hint'); modal.querySelector('.sh-link').after(hint); }
      hint.textContent = 'เลือกลิงก์ไว้ให้แล้ว — กด Ctrl+C (Mac: ⌘+C) เพื่อคัดลอก';
    }
  }

  /* QR เป็น PNG สำหรับวางในเอกสาร Word/หนังสือเวียน: QR ใหญ่ + ชื่อเอกสารใต้ภาพ บนพื้นขาว */
  function savePng(src, title, name) {
    var img = new Image();
    img.onload = function () {
      var W = 1000, Q = 880, pad = 60, lineH = 46;
      var c = document.createElement('canvas'), x = c.getContext('2d');
      x.font = '600 34px Prompt, Tahoma, sans-serif';
      var words = String(title).split(/(\s+)/), lines = [], cur = '';
      words.forEach(function (w) {
        var t = cur + w;
        if (x.measureText(t).width > W - pad * 2 && cur.trim()) { lines.push(cur.trim()); cur = w; } else { cur = t; }
      });
      if (cur.trim()) lines.push(cur.trim());
      if (lines.length > 2) { lines = lines.slice(0, 2); lines[1] = lines[1].replace(/.{0,2}$/, '…'); }
      c.width = W; c.height = pad + Q + 24 + lines.length * lineH + pad;
      x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
      x.imageSmoothingEnabled = false;
      x.drawImage(img, (W - Q) / 2, pad, Q, Q);
      x.fillStyle = '#111827'; x.font = '600 34px Prompt, Tahoma, sans-serif'; x.textAlign = 'center'; x.textBaseline = 'top';
      lines.forEach(function (l, i) { x.fillText(l, W / 2, pad + Q + 24 + i * lineH); });
      c.toBlob(function (b) {
        if (!b) return;
        var a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = name + '.png';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
      }, 'image/png');
    };
    img.src = src;
  }

  function open(btn) {
    close();
    lastFocus = btn;
    var title = btn.getAttribute('data-share-title') || document.title;
    var url = new URL(btn.getAttribute('data-share-url') || location.href, location.href).href;
    var qr = btn.getAttribute('data-share-qr') || '';
    var enc = encodeURIComponent(url);
    var fileName = 'qr-' + (qr.split('?')[1] || 'link').replace(/[^a-z0-9]+/gi, '-');

    modal = el('div', 'sh-modal');
    modal.setAttribute('role', 'dialog'); modal.setAttribute('aria-modal', 'true'); modal.setAttribute('aria-labelledby', 'sh-h');
    var box = el('div', 'sh-box');
    var head = el('div', 'sh-head');
    var h = el('b', '', 'แชร์เอกสาร'); h.id = 'sh-h';
    var x = el('button', 'sh-x'); x.type = 'button'; x.setAttribute('aria-label', 'ปิด');
    x.innerHTML = '<span class="material-symbols-rounded" aria-hidden="true">close</span>';
    head.appendChild(h); head.appendChild(x);
    var doc = el('p', 'sh-doc', title);

    var link = el('div', 'sh-link');
    var inp = el('input'); inp.type = 'text'; inp.readOnly = true; inp.value = url; inp.setAttribute('aria-label', 'ลิงก์เอกสาร');
    var cp = el('button', 'btn small primary', 'คัดลอกลิงก์'); cp.type = 'button';
    link.appendChild(inp); link.appendChild(cp);

    var apps = el('div', 'sh-apps');
    if (navigator.share) {
      var ns = el('button', 'sh-app'); ns.type = 'button';
      ns.innerHTML = '<span class="material-symbols-rounded" aria-hidden="true">ios_share</span>แชร์ผ่านเครื่อง';
      ns.addEventListener('click', function () { navigator.share({ title: title, url: url }).catch(function () {}); });
      apps.appendChild(ns);
    }
    [['LINE', 'https://social-plugins.line.me/lineit/share?url=' + enc, 'line'],
     ['Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' + enc, 'fb']].forEach(function (a) {
      var l = el('a', 'sh-app ' + a[2]); l.href = a[1]; l.target = '_blank'; l.rel = 'noopener';
      l.innerHTML = '<span class="sh-dot" aria-hidden="true"></span>' + a[0];
      apps.appendChild(l);
    });

    box.appendChild(head); box.appendChild(doc); box.appendChild(link); box.appendChild(apps);

    if (qr) {
      var q = el('div', 'sh-qr');
      var im = el('img'); im.src = qr; im.alt = 'QR Code ลิงก์ ' + title; im.width = 168; im.height = 168;
      var side = el('div', 'sh-qr-t');
      side.appendChild(el('b', '', 'QR Code'));
      side.appendChild(el('p', '', 'สแกนเพื่อเปิดเอกสาร — ดาวน์โหลดไปแปะหนังสือเวียน บอร์ดประกาศ หรือสไลด์นำเสนอได้'));
      var png = el('button', 'btn small', 'ดาวน์โหลด PNG'); png.type = 'button';
      png.addEventListener('click', function () { savePng(qr, title, fileName); });
      var svg = el('a', 'btn small ghost', 'SVG (พิมพ์ขนาดใหญ่)'); svg.href = qr + (qr.indexOf('?') > -1 ? '&' : '?') + 'dl=1';
      var row = el('div', 'sh-qr-btns'); row.appendChild(png); row.appendChild(svg);
      side.appendChild(row);
      q.appendChild(im); q.appendChild(side);
      box.appendChild(q);
    }

    modal.appendChild(box);
    modal.addEventListener('click', function (ev) { if (ev.target === modal) close(); });
    x.addEventListener('click', close);
    cp.addEventListener('click', function () { copy(url, cp); });
    inp.addEventListener('focus', function () { inp.select(); });
    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onKey);
    cp.focus();
  }

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest && ev.target.closest('[data-share]');
    if (!b) return;
    /* ปุ่มอยู่ใน <summary> ของการ์ดสารบัญด้วย — กันไม่ให้การ์ดพับ/กางตอนกดแชร์ */
    ev.preventDefault(); ev.stopPropagation();
    open(b);
  });
})();

/* ══ ชั้นวางแบบมีปก — ค้นหาฉบับ · สลับใหม่/เก่า · กระโดดไปกลุ่ม (เนื้อหาครบโดยไม่ต้องมี JS) ══ */
(function () {
  var root = document.querySelector('[data-dsh]');
  if (!root) return;
  var tools = document.querySelector('[data-dsh-tools]');
  if (tools) tools.hidden = false;
  var input = document.querySelector('[data-dsh-q]');
  var countEl = document.querySelector('[data-dsh-count]');
  var none = root.querySelector('[data-dsh-none]');
  var groups = Array.prototype.slice.call(root.querySelectorAll('[data-dsh-group]'));
  var TH = '๐๑๒๓๔๕๖๗๘๙';
  function fold(s) { return String(s || '').toLowerCase().replace(/[๐-๙]/g, function (d) { return String(TH.indexOf(d)); }); }
  var total = root.querySelectorAll('.dsh-card').length;

  function apply() {
    var terms = fold(input ? input.value : '').replace(/\s+/g, ' ').trim().split(' ').filter(Boolean);
    var shown = 0;
    groups.forEach(function (g) {
      var n = 0;
      g.querySelectorAll('.dsh-card').forEach(function (c) {
        var s = c.getAttribute('data-s') || '';
        var ok = !terms.length || terms.every(function (t) { return s.indexOf(t) > -1; });
        c.hidden = !ok; if (ok) n++;
      });
      g.hidden = n === 0; shown += n;
    });
    if (none) none.hidden = shown > 0;
    if (countEl) countEl.textContent = terms.length ? 'พบ ' + shown + ' จาก ' + total + ' ฉบับ' : '';
  }
  var tm;
  if (input) {
    input.addEventListener('input', function () { clearTimeout(tm); tm = setTimeout(apply, 120); });
    input.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && input.value) { input.value = ''; apply(); } });
  }

  /* สลับลำดับ: กลับด้านทั้งลำดับกลุ่มและลำดับฉบับในกลุ่ม (เรียงไว้แล้วจากฝั่งเซิร์ฟเวอร์) */
  var order = root.getAttribute('data-sort') || 'new';
  document.querySelectorAll('[data-dsh-sort]').forEach(function (b) {
    b.addEventListener('click', function () {
      var want = b.getAttribute('data-dsh-sort');
      document.querySelectorAll('[data-dsh-sort]').forEach(function (x) { x.setAttribute('aria-checked', x === b ? 'true' : 'false'); });
      if (want === order) return;
      order = want;
      groups.slice().reverse().forEach(function (g) { root.insertBefore(g, none); });
      groups.forEach(function (g) {
        var grid = g.querySelector('.dsh-grid');
        Array.prototype.slice.call(grid.children).reverse().forEach(function (c) { grid.appendChild(c); });
      });
      groups.reverse();
      var jump = document.querySelector('.dsh-jump');
      if (jump) Array.prototype.slice.call(jump.children).reverse().forEach(function (a) { jump.appendChild(a); });
    });
  });

  /* กระโดดไปกลุ่ม — เลื่อนทันที (scroll-behavior: smooth ของทั้งเว็บถูกขัดจังหวะง่ายเมื่อรูปปกทยอยโหลด) */
  document.querySelectorAll('[data-dsh-jump]').forEach(function (a) {
    a.addEventListener('click', function (ev) {
      var t = document.getElementById(a.getAttribute('href').slice(1));
      if (!t) return;
      ev.preventDefault();
      if (input && input.value) { input.value = ''; apply(); }
      t.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
    });
  });
})();
