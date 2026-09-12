<?php
/**
 * orgchart.php — ผังโครงสร้างหน่วยงาน (org_chart_nodes)
 * สร้าง/แก้ไขได้เองในแอดมิน ไม่ต้องอัปโหลดรูปจากข้างนอก — รองรับความลึกไม่จำกัดชั้น
 */
if (!defined('APP_ROOT')) exit('Forbidden');

/**
 * ดึงทุก node แล้วประกอบเป็นต้นไม้ (array ของ root แต่ละอันมี key 'children' เป็นลูกของมัน)
 * เรียงตาม sort_order ทั้งในแต่ละชั้น
 */
function org_chart_tree(): array {
    $rows = db()->query('SELECT * FROM org_chart_nodes ORDER BY sort_order ASC, id ASC')->fetchAll();
    $byId = [];
    foreach ($rows as $r) { $r['children'] = []; $byId[(int)$r['id']] = $r; }
    $roots = [];
    foreach ($byId as $id => $node) {
        $pid = (int)($node['parent_id'] ?? 0);
        if ($pid && isset($byId[$pid])) {
            $byId[$pid]['children'][] = &$byId[$id];
        } else {
            $roots[] = &$byId[$id];
        }
    }
    unset($node);
    return $roots;
}

/**
 * ดึงทุก node แบบ flat list พร้อมระดับความลึก (ใช้ทำ dropdown/แถวย่อหน้าในแอดมิน)
 * คืน array ของ ['id'=>, 'label'=>, 'parent_id'=>, 'sort_order'=>, 'depth'=>]
 */
function org_chart_flat_with_depth(): array {
    $out = [];
    $walk = function (array $nodes, int $depth) use (&$walk, &$out) {
        foreach ($nodes as $n) {
            $out[] = ['id' => (int)$n['id'], 'label' => $n['label'], 'parent_id' => $n['parent_id'], 'sort_order' => (int)$n['sort_order'], 'depth' => $depth];
            if ($n['children']) $walk($n['children'], $depth + 1);
        }
    };
    $walk(org_chart_tree(), 0);
    return $out;
}

/** id ทั้งหมดที่เป็นลูกหลานของ $id (รวมทุกชั้น) — ใช้กันเลือกลูกหลานตัวเองเป็นพ่อแม่ (กันวนเป็นวง) */
function org_chart_descendant_ids(int $id): array {
    $all = db()->query('SELECT id, parent_id FROM org_chart_nodes')->fetchAll();
    $childrenOf = [];
    foreach ($all as $r) { $childrenOf[(int)($r['parent_id'] ?? 0)][] = (int)$r['id']; }
    $out = [];
    $stack = $childrenOf[$id] ?? [];
    while ($stack) {
        $cur = array_pop($stack);
        $out[] = $cur;
        foreach ($childrenOf[$cur] ?? [] as $c) $stack[] = $c;
    }
    return $out;
}
