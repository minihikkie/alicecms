<?php
/** post.php — หน้าอ่านข่าว (นับยอดอ่าน + lightbox รูป) */
define('PUBLIC_PAGE', 'post');
require __DIR__ . '/includes/init.php';

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare("SELECT * FROM posts WHERE id = ? AND status = 'published'");
$st->execute([$id]);
$post = $st->fetch();

if (!$post) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

/* นับยอดอ่าน — ครั้งเดียวต่อ session ต่อข่าว กันรีเฟรชปั่นยอด */
if (empty($_SESSION['viewed_posts'][$id])) {
    db()->prepare('UPDATE posts SET views = views + 1 WHERE id = ?')->execute([$id]);
    $_SESSION['viewed_posts'][$id] = true;
    $post['views']++;
}

/* ไฟล์แนบหลายไฟล์ (เฟส 3) */
$st = db()->prepare('SELECT * FROM post_attachments WHERE post_id = ? ORDER BY id ASC');
$st->execute([$id]);
$attachments = $st->fetchAll();

/* วิดีโอ YouTube (ถ้ามี) */
$yt_id = youtube_id((string)($post['video_url'] ?? ''));

$page_title = $post['title'];
/* SEO/แชร์: คำอธิบายจากเนื้อข่าว + รูปปกเป็น og:image */
$meta_description = $post['body'];
if (!empty($post['image'])) $og_image = $post['image'];

/* SEO: ประกาศให้ Search Engine รู้ว่าหน้านี้เป็น "ข่าว" พร้อมเส้นทางนำทาง
   ทำให้แสดงผลในผลค้นหาได้ดีกว่าหน้าธรรมดา (rich result) */
$og_type = 'article';
$og_article = [
    'published_time' => !empty($post['published_at']) ? date('c', strtotime((string)$post['published_at'])) : '',
    'section'        => post_type_label((string)$post['type']),
];
$json_ld_extra = [
    [
        '@context'      => 'https://schema.org',
        '@type'         => 'NewsArticle',
        'headline'      => mb_substr((string)$post['title'], 0, 110),
        'datePublished' => !empty($post['published_at']) ? date('c', strtotime((string)$post['published_at'])) : '',
        'dateModified'  => !empty($post['published_at']) ? date('c', strtotime((string)$post['published_at'])) : '',
        'description'   => meta_excerpt((string)$post['body']),
        'image'         => !empty($post['image']) ? [abs_url($post['image'])] : [],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => abs_url('post.php?id=' . (int)$post['id'])],
        'publisher'     => [
            '@type' => 'GovernmentOrganization',
            'name'  => setting('site_name', 'หน่วยงานราชการ'),
            'logo'  => ['@type' => 'ImageObject', 'url' => abs_url(setting('logo') ?: 'assets/img/favicon.svg')],
        ],
    ],
    [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'หน้าแรก', 'item' => abs_url('index.php')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => post_type_label((string)$post['type']), 'item' => abs_url('news.php?type=' . urlencode((string)$post['type']))],
            ['@type' => 'ListItem', 'position' => 3, 'name' => mb_substr((string)$post['title'], 0, 90)],
        ],
    ],
];
require __DIR__ . '/includes/header.php';
$types = post_types();
$share_url = $canonical; // URL เต็มของหน้านี้ (จาก header.php)
?>
<div class="container">
  <div class="page-head">
    <div class="crumb">
      <a href="<?= e(url('index.php')) ?>">หน้าแรก</a>
      <span class="material-symbols-rounded icon-sm">chevron_right</span>
      <a href="<?= e(url('news.php?type=' . $post['type'])) ?>"><?= e(post_type_label($post['type'])) ?></a>
    </div>
    <h1 style="font-size:clamp(22px,2.6vw,30px);line-height:1.4;"><?= e($post['title']) ?></h1>
    <div class="post-meta">
      <span class="badge <?= e($types[$post['type']]['badge'] ?? '') ?>"><span class="material-symbols-rounded icon-sm"><?= e($types[$post['type']]['icon'] ?? 'article') ?></span><?= e(post_type_label($post['type'])) ?></span>
      <span><span class="material-symbols-rounded icon-sm">calendar_today</span> <?= e(thai_date($post['published_at'], false)) ?></span>
      <span><span class="material-symbols-rounded icon-sm">visibility</span> อ่าน <?= number_format((int)$post['views']) ?> ครั้ง</span>
    </div>
  </div>

  <?php if ($post['image']): ?>
  <div class="post-cover">
    <?php /* รูปปกข่าวคือภาพหลักของหน้า (LCP) — ต้องโหลดทันที ไม่ lazy
             และระบุขนาดไว้เพื่อจองพื้นที่ กันหน้ากระตุก (CLS) */ ?>
    <img src="<?= e(url($post['image'])) ?>" alt="<?= e($post['title']) ?>" data-lightbox="<?= e(url($post['image'])) ?>"
         width="1200" height="480" loading="eager" fetchpriority="high" decoding="async">
  </div>
  <?php endif; ?>

  <?php if ($yt_id !== ''): ?>
  <div class="video-embed mb-3">
    <iframe src="https://www.youtube-nocookie.com/embed/<?= e($yt_id) ?>" title="วิดีโอประกอบข่าว"
      loading="lazy" allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
  </div>
  <?php endif; ?>

  <div class="card card-spacious">
    <div class="post-body"><?= nl2br(e($post['body'])) ?></div>

    <?php if ($post['attachment'] || $attachments): ?>
    <div class="mt-3">
      <div class="lr-title mb-1" style="font-weight:600;"><span class="material-symbols-rounded icon-sm" style="vertical-align:-4px;color:var(--danger);">attach_file</span> ไฟล์แนบ</div>
      <?php if ($post['attachment']): ?>
      <div class="attach-box" style="margin:8px 0;">
        <div class="lr-icon" style="background:rgba(234,67,53,.08);color:var(--danger);"><span class="material-symbols-rounded">description</span></div>
        <div style="flex:1;"><div class="lr-title">ไฟล์แนบหลัก</div>
          <div class="lr-date"><?= e(strtoupper(pathinfo($post['attachment'], PATHINFO_EXTENSION))) ?> · <?= e(format_bytes((int)$post['attachment_size'])) ?></div></div>
        <a class="btn primary" href="<?= e(url($post['attachment'])) ?>" target="_blank" rel="noopener"><span class="material-symbols-rounded icon-sm">download</span>ดาวน์โหลด</a>
      </div>
      <?php endif; ?>
      <?php foreach ($attachments as $att): ?>
      <div class="attach-box" style="margin:8px 0;">
        <div class="lr-icon" style="background:rgba(234,67,53,.08);color:var(--danger);"><span class="material-symbols-rounded">description</span></div>
        <div style="flex:1;"><div class="lr-title"><?= e($att['name'] ?: basename($att['file'])) ?></div>
          <div class="lr-date"><?= e(strtoupper((string)$att['ext'])) ?> · <?= e(format_bytes((int)$att['file_size'])) ?></div></div>
        <a class="btn primary" href="<?= e(url($att['file'])) ?>" target="_blank" rel="noopener"><span class="material-symbols-rounded icon-sm">download</span>ดาวน์โหลด</a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- แชร์ + พิมพ์ -->
  <div class="share-bar mt-3">
    <span class="share-label"><span class="material-symbols-rounded icon-sm">share</span>แชร์ข่าวนี้</span>
    <a class="share-btn fb" href="https://www.facebook.com/sharer/sharer.php?u=<?= e(urlencode($share_url)) ?>" target="_blank" rel="noopener" aria-label="แชร์ Facebook"><span class="material-symbols-rounded icon-sm">thumb_up</span>Facebook</a>
    <a class="share-btn line" href="https://social-plugins.line.me/lineit/share?url=<?= e(urlencode($share_url)) ?>" target="_blank" rel="noopener" aria-label="แชร์ LINE"><span class="material-symbols-rounded icon-sm">chat</span>LINE</a>
    <a class="share-btn x" href="https://twitter.com/intent/tweet?text=<?= e(urlencode($post['title'])) ?>&url=<?= e(urlencode($share_url)) ?>" target="_blank" rel="noopener" aria-label="แชร์ X"><span class="material-symbols-rounded icon-sm">tag</span>X</a>
    <button class="share-btn copy" type="button" data-copy-link="<?= e($share_url) ?>" aria-label="คัดลอกลิงก์"><span class="material-symbols-rounded icon-sm">link</span>คัดลอกลิงก์</button>
    <button class="share-btn print" type="button" data-print aria-label="พิมพ์หน้านี้"><span class="material-symbols-rounded icon-sm">print</span>พิมพ์</button>
  </div>

  <div class="flex justify-between mt-3 mb-4">
    <a class="btn" href="<?= e(url('news.php?type=' . $post['type'])) ?>"><span class="material-symbols-rounded icon-sm">arrow_back</span>กลับหน้ารวมข่าว</a>
    <a class="btn" href="<?= e(url('index.php')) ?>">หน้าแรก</a>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
