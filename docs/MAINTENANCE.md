# คู่มือตรวจผลกระทบเวลาแก้เว็บ (Cross-Impact Checklist)

> เปิดอ่านไฟล์นี้**ก่อนแก้โครงสร้าง**ของระบบ — เพื่อกันแก้จุดเดียวแล้วลืมจุดที่เกี่ยวข้อง
> โดยเฉพาะ **ฟีเจอร์โอนย้ายเว็บ (Site Transfer)** ที่ต้องครอบคลุมข้อมูลทุกอย่างเสมอ

อัปเดตล่าสุด: v1.15.0 (2026-06-16)

---

## 🔐 กุญแจเซ็นแพ็กเกจ (ห้ามทำหาย)

ตั้งแต่ v1.17.0 ทุกแพ็กเกจอัปเดต**ต้องมีลายเซ็นดิจิทัล** ไม่งั้นปลายทางปฏิเสธการติดตั้ง

- กุญแจลับ: `tools/release-key/private.pem` — **อยู่เครื่องผู้พัฒนาเท่านั้น** (โฟลเดอร์ `tools/` ไม่ถูกรวมในแพ็กเกจ)
- กุญแจสาธารณะ: ฝังเป็นค่าคงที่ `UPDATE_PUBLIC_KEY` ใน `includes/updater.php`
- **สำรองกุญแจลับไว้ที่ปลอดภัย** — ถ้าหาย จะออกอัปเดตให้เว็บที่ติดตั้งไปแล้วไม่ได้อีก
  (ทางแก้กรณีหาย: สร้างคู่ใหม่ → แก้ `UPDATE_PUBLIC_KEY` → ผู้ใช้ต้องอัปเดตด้วยมือครั้งเดียว)
- `build-release.php` จะ**ไม่ยอมสร้างแพ็กเกจ**ถ้าไม่พบกุญแจ (กันการปล่อยของที่ไม่มีลายเซ็น)

## 🛡️ ไฟล์ .htaccess ต้องติดไปกับแพ็กเกจ

- `build-release.php` ข้ามไฟล์ที่ขึ้นต้นด้วยจุด **ยกเว้น `.htaccess`** (regex `/^\.(?!htaccess$)/`) — อย่าแก้กฎนี้ให้ตัด .htaccess อีก
- ไฟล์ใน `uploads/` `storage/` `backups/` ไม่ได้มากับแพ็กเกจ (เป็นโฟลเดอร์ข้อมูล) จึงต้องสร้างที่ปลายทางด้วย `includes/hardening.php`
  - เรียกจาก `install.php` (ติดตั้งใหม่) และ `includes/updater.php` (ซ่อมอัตโนมัติทุกครั้งที่อัปเดต)
  - **เพิ่มโฟลเดอร์ข้อมูลใหม่ที่ต้องกันการเข้าถึง → เพิ่มใน `hardening_files()` ด้วย**

## 🟡 กฎทอง 5 ข้อ (ท่องไว้)

1. **ไฟล์ที่ผู้ใช้อัปโหลด/สร้าง ต้องอยู่ใน `uploads/` เท่านั้น** — ห้ามเขียนลง `assets/` หรือที่อื่น
   ถ้าจำเป็นต้องเขียนที่อื่น → ต้องไปแก้ฟีเจอร์โอนย้าย + สำรอง + updater ด้วย (ดูหัวข้อ "โอนย้าย")
2. **ข้อมูลที่ผู้ใช้ตั้งค่า ต้องอยู่ในฐานข้อมูล (ตาราง)** — ระบบโอนย้าย/สำรองดึงทุกตารางอัตโนมัติ จึงครอบคลุมเอง
3. **ตารางใหม่ทุกตาราง** ต้องประกาศทั้งใน `install.php` (ติดตั้งใหม่) **และ** `includes/migrations.php` (อัปเกรดของเดิม) — และ migration ต้อง**รันซ้ำได้ปลอดภัย (idempotent)**
4. **ทุก migration ต้องปลอดภัยเมื่อรันซ้ำ** — เพราะ `run_migrations()` ถูกเรียกทั้งตอนอัปเดต **และตอน import โอนย้าย**
5. **bump `version.php` (APP_VERSION + APP_BUILD) ทุกครั้งที่ปล่อย** แล้ว build + upload manifest

---

## 📋 ตารางผลกระทบ: แก้ตรงนี้ → ต้องตรวจตรงนั้น

| เมื่อแก้ / เพิ่ม | ต้องไปตรวจ/แก้ด้วย |
|---|---|
| **เพิ่มตาราง DB ใหม่** | `install.php` (CREATE) + `includes/migrations.php` (CREATE IF NOT EXISTS). โอนย้าย/สำรองครอบคลุมเอง (ใช้ `SHOW TABLES`) ✅ |
| **เพิ่มที่เก็บไฟล์อัปโหลดใหม่** | ต้องอยู่ใต้ `uploads/` (subdir ใหม่ ใช้ได้เลย โอนย้ายเก็บแบบ recursive). **อย่าเก็บนอก `uploads/`** |
| **เขียนไฟล์ผู้ใช้ลงนอก `uploads/`** (เช่น gen ภาพลง `assets/`) | ⚠️ โอนย้าย/สำรอง **จะตกหล่น** → ต้องแก้ `transfer_add_uploads()` + `transfer_apply_uploads()` + `up_backup_files`/`up_is_protected` |
| **เพิ่ม setting ใหม่** | อยู่ในตาราง `settings` → โอนย้ายครอบคลุมเอง ✅ (ตั้งค่าเริ่มต้นใน `migrations.php`/`install.php`) |
| **เพิ่ม/แก้ section หน้าแรกหรือเมนูอัตโนมัติ** | ดูหัวข้อ "ระบบ section/เมนู" ด้านล่าง — มีหลายไฟล์ผูกกัน |
| **เพิ่มโฟลเดอร์ข้อมูลที่ต้องกันไม่ให้อัปเดตทับ** | `includes/updater.php` → `up_is_protected()` (เพิ่ม path) + `tools/build-release.php` → `$skip_prefix` |
| **เปลี่ยนรูปแบบ dump SQL** (`up_backup_db`) | ต้องเข้ากันได้กับตัวอ่าน `transfer_run_sql()` (parser แยกคำสั่งที่ `;` นอก string) |
| **เพิ่มไฟล์ dev/ทดสอบชั่วคราว** | ต้องลบก่อน build (เช่น `admin/_vh.php`) — build-release ข้ามแค่ `^_tmp` ไม่ได้ข้าม `_xxx` ทั้งหมด |

---

## 🔄 ฟีเจอร์โอนย้ายเว็บ (Site Transfer) — จุดที่ต้องระวัง

ไฟล์หลัก: `includes/transfer.php`, `admin/transfer.php`
แพ็กเกจโอนย้าย = `database.sql` (ทุกตาราง) + `uploads/` (recursive) + `transfer.json`

### ✅ ครอบคลุมอัตโนมัติ (ไม่ต้องแก้อะไร)
- **ตารางใหม่** — `up_backup_db()` ใช้ `SHOW TABLES` ดึงทุกตาราง
- **subdir ใหม่ใน `uploads/`** — `transfer_add_uploads()` เดินแบบ recursive
- **setting ใหม่** — อยู่ในตาราง `settings`

### ⚠️ จะตกหล่น ต้องแก้โอนย้ายด้วย ถ้า...
- **เก็บไฟล์ผู้ใช้นอก `uploads/`** → เพิ่มการเก็บ+คืนไฟล์นั้นใน `transfer_add_uploads()` และ `transfer_apply_uploads()`
- **ข้อมูลสำคัญไปเก็บในไฟล์บนดิสก์แทน DB** (เช่น json config บนไฟล์) → ต้องบรรจุเข้าแพ็กเกจเอง
- **เปลี่ยนวิธี hash/เข้ารหัสที่ผูกกับ `config.php`** (เช่น เข้ารหัสค่าด้วยคีย์ใน config) → ข้อมูลที่เข้ารหัสจะถอดไม่ออกบนเครื่องปลายทาง เพราะ **config.php ไม่ถูกโอน** (ตั้งใจ)

### กฎที่ต้องคงไว้
- **ห้ามโอน `config.php`** (รหัส DB ของแต่ละเครื่อง) — import เข้า DB ของเครื่องปลายทางเอง
- คงการ **ตรวจ SHA-256 + สำรองก่อนเขียนทับ + rollback + version guard** ไว้เสมอ
- `transfer_run_sql()` เป็น parser แบบรู้ขอบเขต string — **ห้ามเปลี่ยนเป็น `explode(';')`** (จะพังถ้าข้อมูลมี `;`)

### ทดสอบโอนย้ายหลังแก้ (round-trip)
1. ใส่ค่า canary โหด (`;`, ขึ้นบรรทัด, `'`, `\`) ในตาราง + วางไฟล์ใน uploads
2. `transfer_export()` → เปลี่ยนข้อมูล/เพิ่มไฟล์แปลกปลอม → `transfer_import()`
3. ตรวจ: canary กลับมาเป๊ะ · ไฟล์แปลกปลอมถูกลบ (mirror) · จำนวนแถวตรง `transfer.json`

---

## 🧭 ระบบ section หน้าแรก / เมนู (3 สวิตช์ผูกกันหลายไฟล์)

ตาราง `sections`: `enabled` (หน้าแรก) · `in_menu` (เมนูบน) · `sort_order` · `custom_title`

ตัวช่วยใน `includes/functions.php`:
- `section_on($k)` = แสดงบน**หน้าแรก** (ใช้: `index.php`)
- `section_in_menu($k)` = แสดงใน**เมนูบน** (ใช้: `header.php`, `admin/menu.php` seed)
- `section_live($k)` = `on || in_menu` = "เปิดให้ประชาชน" (ใช้: `footer.php`, `sitemap.php`, `news.php` แท็บ, `about.php`, `contact.php`)
- `nav_section_defs()` / `section_nav_target()` = นิยามลิงก์เมนูอัตโนมัติ ↔ section (จุดเดียว)

| เมื่อแก้ | ตรวจ |
|---|---|
| **เพิ่ม section หน้าแรกใหม่** | `section_defaults()` + `install.php`/`migrations.php` (insert row) + ไฟล์ `includes/sections/<key>.php` + `admin/homepage.php` (แสดงในตาราง) |
| **เพิ่มลิงก์เมนูอัตโนมัติใหม่** | แก้ `nav_section_defs()` **จุดเดียว** → header + menu seed + homepage admin อัปเดตตามเอง |
| **ถามว่า "ฟีเจอร์เปิดอยู่ไหม"** ในหน้า public | ใช้ `section_live()` ไม่ใช่ `section_on()` (กันลิงก์หายเมื่อใส่เมนูแต่ไม่โชว์หน้าแรก) |

---

## 🚀 ระบบอัปเดต / migration / build (ต้องสอดคล้องกัน)

| ไฟล์ | หน้าที่ | กฎ |
|---|---|---|
| `version.php` | เวอร์ชัน | bump APP_VERSION + APP_BUILD ทุก release |
| `includes/migrations.php` | อัปเกรด schema | idempotent เสมอ · backfill ค่าครั้งเดียว (เช็คก่อนว่าคอลัมน์มีไหม) |
| `install.php` | ติดตั้งใหม่ | ต้องมี schema ตรงกับ migrations |
| `includes/updater.php` | อัปเดตผ่าน admin | `up_is_protected()` = path ที่ห้ามทับ (config/uploads/storage/tools) |
| `tools/build-release.php` | แพ็ก zip + manifest | `$skip_*` = ไฟล์ที่ไม่แพ็ก — ตรวจว่าไม่หลุดไฟล์ dev/ความลับ |

### ⚠️ build-release ข้อควรระวัง
- ข้ามด้วย: prefix (`uploads/ storage/ backups/ dist/ tools/ .git/ .claude/ node_modules/`), ชื่อตรง (`config.php install.lock`), pattern (`^_tmp`, `^.` dotfiles, `.zip`, `.sql`)
- **ไฟล์ dev ที่ขึ้นต้น `_` (เช่น `_vh.php`) ไม่ถูกข้าม** → ต้องลบเองก่อน build
  (ถ้าอยากกันถาวร: เพิ่ม `/^_/` ใน `$skip_match`)

---

## ✅ Checklist ก่อนปล่อยเวอร์ชันใหม่

- [ ] `php -l` ผ่านทุกไฟล์ที่แก้
- [ ] ถ้าแตะ schema → migration idempotent + ทดสอบ migrate ในเครื่อง
- [ ] ถ้าแตะที่เก็บไฟล์/ตาราง → **ทดสอบ round-trip โอนย้าย** (export→import) ผ่าน
- [ ] ถ้าแตะ section/เมนู → เช็ค header + footer + sitemap + homepage admin
- [ ] ลบไฟล์ dev/ทดสอบ (`admin/_vh.php`, `_ttest.php`, ฯลฯ)
- [ ] bump `version.php`
- [ ] `php tools/build-release.php` → ตรวจรายการไฟล์ในแพ็กเกจ (ไม่มีไฟล์ dev/ความลับ)
- [ ] upload zip + update.json ขึ้น update server → ตรวจ manifest `latest` + SHA ตรง
