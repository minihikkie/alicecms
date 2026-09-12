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

      // ปุ่มลูกศร
      var prev = slider.querySelector('.hs-arrow.prev');
      var next = slider.querySelector('.hs-arrow.next');
      if (prev) prev.addEventListener('click', function () { show(cur - 1); });
      if (next) next.addEventListener('click', function () { show(cur + 1); });

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
