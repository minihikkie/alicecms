<?php
/** contact.php — ติดต่อหน่วยงาน */
define('PUBLIC_PAGE', 'contact');
require __DIR__ . '/includes/init.php';
$page_title = 'ติดต่อหน่วยงาน';
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="page-head reveal">
    <div class="crumb"><a href="<?= e(url('index.php')) ?>">หน้าแรก</a> <span class="material-symbols-rounded icon-sm">chevron_right</span> ติดต่อ</div>
    <h1>ติดต่อหน่วยงาน</h1>
  </div>

  <?php
  $map_src     = map_embed_url();
  $map_url     = setting('map_url');
  $social_html = social_icons_row();
  /* ลิงก์เปิดแผนที่ภายนอก — ถ้าไม่ได้ตั้ง map_url ไว้ ให้ค้นจากที่อยู่แทน */
  $map_link = $map_url ?: (setting('site_address')
      ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(trim(preg_replace('/\s+/u', ' ', (string)setting('site_address'))))
      : '');
  ?>
  <div class="card contact-panel mb-3 reveal">
    <div class="contact-split<?= $map_src === '' ? ' no-map' : '' ?>">

      <div class="contact-side">
        <div class="contact-side-head">
          <h2>ข้อมูลติดต่อ</h2>
        </div>

        <div class="contact-rows">
          <?php if (setting('site_address')): ?>
          <div class="contact-row">
            <div class="lr-icon"><span class="material-symbols-rounded">location_on</span></div>
            <div class="contact-row-body">
              <b>ที่อยู่</b>
              <p><?= nl2br(e(setting('site_address'))) ?></p>
              <?php if ($map_link): ?>
              <a class="contact-map-link" href="<?= e($map_link) ?>" target="_blank" rel="noopener">
                <span class="material-symbols-rounded icon-sm">directions</span>เปิดเส้นทางใน Google Maps
              </a>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php if (setting('site_phone')): ?>
          <div class="contact-row">
            <div class="lr-icon" style="background:rgba(52,168,83,.1);color:var(--success);"><span class="material-symbols-rounded">call</span></div>
            <div class="contact-row-body">
              <b>โทรศัพท์</b>
              <p><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', setting('site_phone'))) ?>"><?= e(setting('site_phone')) ?></a></p>
            </div>
          </div>
          <?php endif; ?>

          <?php if (setting('site_email')): ?>
          <div class="contact-row">
            <div class="lr-icon" style="background:rgba(249,171,0,.12);color:#B07000;"><span class="material-symbols-rounded">mail</span></div>
            <div class="contact-row-body">
              <b>อีเมล</b>
              <p><a href="mailto:<?= e(setting('site_email')) ?>"><?= e(setting('site_email')) ?></a></p>
            </div>
          </div>
          <?php endif; ?>

          <?php if (setting('site_hours')): ?>
          <div class="contact-row">
            <div class="lr-icon" style="background:rgba(156,39,176,.1);color:var(--special);"><span class="material-symbols-rounded">schedule</span></div>
            <div class="contact-row-body">
              <b>เวลาทำการ</b>
              <p><?= e(setting('site_hours')) ?></p>
            </div>
          </div>
          <?php endif; ?>

          <?php if (setting('site_directions')): ?>
          <div class="contact-row">
            <div class="lr-icon" style="background:rgba(26,115,232,.1);color:var(--blue);"><span class="material-symbols-rounded">directions</span></div>
            <div class="contact-row-body">
              <b>วิธีเดินทาง</b>
              <p><?= nl2br(e(setting('site_directions'))) ?></p>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($social_html !== ''): ?>
        <div class="contact-social">
          <b>ช่องทางโซเชียลมีเดีย</b>
          <?= $social_html ?>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($map_src !== ''): ?>
      <div class="contact-map">
        <iframe src="<?= e($map_src) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen title="แผนที่ที่ตั้งหน่วยงาน"></iframe>
      </div>
      <?php endif; ?>

    </div>
  </div>

  <?php if (section_live('complaint')): ?>
  <div class="card card-spacious text-center mb-4 reveal" style="border:2px solid rgba(234,67,53,.18);">
    <span class="material-symbols-rounded icon-lg" style="color:var(--danger);">support_agent</span>
    <h3 style="margin:8px 0 4px;">ร้องเรียน-ร้องทุกข์</h3>
    <p class="text-muted" style="font-size:14px;margin:0 0 16px;">แจ้งเรื่องร้องเรียนการทุจริต ประพฤติมิชอบ หรือข้อเสนอแนะ — ข้อมูลผู้แจ้งถูกเก็บเป็นความลับ</p>
    <a class="btn danger large" href="<?= e(url('complaint.php')) ?>"><span class="material-symbols-rounded">edit_note</span>แจ้งเรื่องร้องเรียนออนไลน์</a>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
