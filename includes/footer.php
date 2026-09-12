<?php
/** footer.php — ส่วนท้ายของทุกหน้าฝั่งประชาชน */
if (!defined('APP_ROOT')) exit('Forbidden');
$site_name = setting('site_name', 'หน่วยงานราชการ');
/* footer แบบกำหนดเอง (admin/footer-menu.php) — ใช้ก็ต่อเมื่อเปิดโหมดไว้ "และ" มีลิงก์จริงอย่างน้อย 1 อัน
   ไม่งั้น (ปิดโหมด หรือ เปิดโหมดแต่ยังไม่ได้เพิ่มลิงก์) กลับไปใช้ 3 คอลัมน์อัตโนมัติเดิมเสมอ กันหน้า footer โล่งเปล่า */
$footer_links = footer_custom_on() ? footer_links_grouped() : null;
$footer_has_custom = $footer_links !== null && array_filter($footer_links);
?>
</main>

<!-- FOOTER -->
<footer>
  <div class="container">
    <div class="foot-grid">
      <div>
        <div class="flex items-center gap-1 mb-1">
          <div class="logo<?= setting('logo') ? ' has-img' : '' ?>">
            <?php if (setting('logo')): ?>
              <img src="<?= e(url(setting('logo'))) ?>" alt="โลโก้<?= e($site_name) ?>">
            <?php else: ?>
              <span class="material-symbols-rounded filled"><?= e(setting('logo_icon', 'account_balance')) ?></span>
            <?php endif; ?>
          </div>
          <div class="brand"><?= e($site_name) ?></div>
        </div>
        <?php if (setting('site_vision')): ?>
        <p class="text-muted" style="font-size:14px;max-width:34ch;">วิสัยทัศน์: "<?= e(setting('site_vision')) ?>"</p>
        <?php endif; ?>
        <?php if (setting('footer_about')): ?>
        <p class="text-muted" style="font-size:13.5px;max-width:40ch;margin-top:6px;line-height:1.7;"><?= nl2br(e(setting('footer_about'))) ?></p>
        <?php endif; ?>
        <div class="flex gap-1 mt-1" style="flex-wrap:wrap;">
          <?php if (setting('social_facebook')): ?><a class="btn small" href="<?= e(setting('social_facebook')) ?>" target="_blank" rel="noopener">Facebook</a><?php endif; ?>
          <?php if (setting('social_youtube')): ?><a class="btn small" href="<?= e(setting('social_youtube')) ?>" target="_blank" rel="noopener">YouTube</a><?php endif; ?>
          <?php if (setting('social_tiktok')): ?><a class="btn small" href="<?= e(setting('social_tiktok')) ?>" target="_blank" rel="noopener">TikTok</a><?php endif; ?>
          <?php if (setting('social_line')): ?><a class="btn small" href="<?= e(setting('social_line')) ?>" target="_blank" rel="noopener">LINE</a><?php endif; ?>
        </div>
      </div>
      <?php if ($footer_has_custom): ?>
      <?php for ($fc = 1; $fc <= 3; $fc++): ?>
      <div>
        <h4><?= e(footer_col_title($fc)) ?></h4>
        <?php foreach ($footer_links[$fc] as $fl): $fh = menu_href($fl['url']); ?>
        <a href="<?= e($fh['href']) ?>"<?= $fl['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($fl['label']) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endfor; ?>
      <?php else: ?>
      <div>
        <h4><?= e(footer_col_title(1)) ?></h4>
        <?php if (section_live('activity')): ?><a href="<?= e(url('news.php?type=activity')) ?>">กิจกรรม</a><?php endif; ?>
        <?php if (section_live('pr')): ?><a href="<?= e(url('news.php?type=pr')) ?>">ประชาสัมพันธ์</a><?php endif; ?>
        <?php if (section_live('announce')): ?><a href="<?= e(url('news.php?type=announce')) ?>">ประกาศ</a><?php endif; ?>
        <?php if (section_live('procurement')): ?><a href="<?= e(url('procurement.php')) ?>">จัดซื้อจัดจ้าง</a><?php endif; ?>
      </div>
      <div>
        <h4><?= e(footer_col_title(2)) ?></h4>
        <?php if (section_live('ita')): ?><a href="<?= e(url('ita.php')) ?>">ITA</a><?php endif; ?>
        <?php if (section_live('documents')): ?><a href="<?= e(url('documents.php')) ?>">เอกสารเผยแพร่</a><?php endif; ?>
        <a href="<?= e(url('services.php')) ?>">บริการออนไลน์</a>
        <?php if (section_live('faq')): ?><a href="<?= e(url('faq.php')) ?>">คำถามที่พบบ่อย</a><?php endif; ?>
        <a href="<?= e(url('about.php')) ?>">เกี่ยวกับหน่วยงาน</a>
        <a href="<?= e(url('contact.php')) ?>">ติดต่อ</a>
        <a href="<?= e(url('sitemap-page.php')) ?>">แผนผังเว็บไซต์</a>
      </div>
      <div>
        <h4><?= e(footer_col_title(3)) ?></h4>
        <a href="<?= e(url('complaint.php')) ?>">ร้องเรียน-ร้องทุกข์</a>
        <a href="<?= e(url('contact.php')) ?>">ช่องทางติดต่อ</a>
        <?php if (page_by_slug('privacy')): ?><a href="<?= e(url('page.php?slug=privacy')) ?>">นโยบายความเป็นส่วนตัว</a><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="foot-bottom">
      <span>© <?= date('Y') + 543 ?> <?= e($site_name) ?><?= setting('site_dept') ? ' ' . e(setting('site_dept')) : '' ?></span>
      <span><?= e(setting('site_phone')) ?><?= setting('site_phone') && setting('site_email') ? ' · ' : '' ?><?= e(setting('site_email')) ?></span>
    </div>
  </div>
</footer>

<!-- Cookie consent (PDPA) — ปฏิเสธแล้วต้องมีผลจริง และเพิกถอนความยินยอมภายหลังได้ -->
<div class="cookiebar" id="cookiebar" role="region" aria-label="การตั้งค่าคุกกี้">
  <span class="material-symbols-rounded" style="color:var(--blue);">cookie</span>
  <p>เว็บไซต์นี้ใช้<b>คุกกี้ที่จำเป็น</b>ต่อการทำงานของเว็บไซต์เสมอ และใช้<b>คุกกี้เพื่อวิเคราะห์การใช้งาน</b>
    (นับสถิติผู้เข้าชม) เมื่อได้รับความยินยอมจากท่านเท่านั้น
    <?php if (page_by_slug('privacy-policy')): ?>
    <a href="<?= e(url('page.php?slug=privacy-policy')) ?>">อ่านนโยบายคุ้มครองข้อมูลส่วนบุคคล</a>
    <?php endif; ?>
  </p>
  <div class="flex gap-1">
    <button class="btn small" data-consent="essential">ใช้เฉพาะที่จำเป็น</button>
    <button class="btn small primary" data-consent="all">ยอมรับทั้งหมด</button>
  </div>
</div>

<!-- ปุ่มเพิกถอน/เปลี่ยนการตั้งค่าคุกกี้ (PDPA กำหนดว่าต้องถอนความยินยอมได้ง่ายเท่าที่ให้) -->
<button type="button" class="cookie-reopen" id="cookieReopen" hidden>
  <span class="material-symbols-rounded icon-sm">cookie</span>ตั้งค่าคุกกี้
</button>

<!-- ปุ่มกลับขึ้นบน + วงแหวน progress -->
<button class="totop" id="totop" aria-label="กลับขึ้นด้านบน">
  <svg viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="22"></circle></svg>
  <span class="material-symbols-rounded">arrow_upward</span>
</button>

<script src="<?= e(asset_url('assets/js/dialog.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/main.js')) ?>"></script>
<?php if (setting('a11y_bar', '1') === '1'): ?><script src="<?= e(asset_url('assets/js/a11y.js')) ?>"></script><?php endif; ?>
</body>
</html>
