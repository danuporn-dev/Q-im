# Deploy

ยังไม่เคย deploy จริง รายการนี้ให้ผู้ดูแลทำตามลำดับ

1. **Backup** database ก่อนทุกครั้ง: `mysqldump --single-transaction <db> > backup.sql` เก็บนอก web root และทดสอบ restore
2. PHP รุ่นที่ยังได้รับ security updates พร้อม `pdo_mysql`, `curl`, `gd`, `fileinfo`, `mbstring`; MariaDB 10.4+ หรือ MySQL 8.0.16+ (ดู [ฐานข้อมูล](#ฐานข้อมูล-mariadb-และ-mysql))
3. Document root = `public/` เท่านั้น, HTTPS บังคับ, เปิด `mod_rewrite` และ `AllowOverride All` (Nginx: ส่ง `/api/v1/` ไป `public/api/index.php`)
4. ตั้ง HSTS ที่ web server; ถ้าอยู่หลัง reverse proxy ต้องส่ง `Host` เดิม และระบบใช้ `REMOTE_ADDR` สำหรับ rate limit จึงควรให้ web server แปลง IP จริงจาก proxy ที่เชื่อถือ
5. สร้าง DB user ที่มีสิทธิ์เฉพาะ database นี้ ห้ามใช้ `root`
6. ตั้งค่า environment ตาม `.env.example` โดย `APP_ENV=production` ค่าอื่นที่ไม่ใช่ `development` ถือเป็น production เสมอ: ปิด mock login และ test hooks, cookie เป็น `Secure` (จึง login ผ่าน HTTP ธรรมดาไม่ได้)
7. ให้ PHP เขียนได้เฉพาะ `storage/logs`, `storage/sessions`, `storage/uploads`
8. รัน `php scripts/migrate.php` **ห้าม**รัน `seed.php`, `e2e.php` และ `tests/` บน production (ทั้งหมดปฏิเสธเองเมื่อไม่ใช่ development)
9. สร้างข้อมูลเริ่มต้นเอง เพราะไม่มี seed: แถว `roles` 3 แถว (`customer`, `merchant`, `admin`) และ admin คนแรก

   ```sql
   INSERT IGNORE INTO roles (name) VALUES ('customer'), ('merchant'), ('admin');
   INSERT INTO users (display_name) VALUES ('ผู้ดูแลระบบ');
   SET @u = LAST_INSERT_ID();
   INSERT INTO user_roles (user_id, role_id) SELECT @u, id FROM roles WHERE name = 'admin';
   -- hash จาก: php -r "echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT);"
   INSERT INTO admin_accounts (user_id, login_name, password_hash) VALUES (@u, 'admin', '<hash>');
   ```

10. ตั้ง LINE และ worker ตาม [line-setup.md](line-setup.md)
11. ตั้ง log rotation ให้ `storage/logs` และกำหนดระยะเวลาเก็บข้อมูลส่วนบุคคล

## ฐานข้อมูล: MariaDB และ MySQL

**ทดสอบจริงบน MariaDB 10.4.32 เท่านั้น MySQL ยังไม่ได้ทดสอบ** ตารางนี้มาจากการตรวจ SQL ทีละจุด ก่อนใช้ MySQL ให้รัน migration และ tests บน MySQL จริงก่อน

| จุด | MariaDB 10.4 | MySQL 8 | สิ่งที่ทำ |
|---|---|---|---|
| เพิ่มคอลัมน์แบบรันซ้ำได้ | มี `ADD COLUMN IF NOT EXISTS` | ไม่มี | `008` ตรวจ `information_schema` แล้ว `PREPARE` แทน |
| `CHECK` | บังคับใช้ | บังคับใช้ตั้งแต่ 8.0.16 | ต้องใช้ 8.0.16 ขึ้นไป รุ่นต่ำกว่าจะไม่ตรวจโดยไม่มี error |
| ชื่อ `CHECK`/foreign key | ไม่ซ้ำในตาราง/schema | ไม่ซ้ำทั้ง schema | ทุกชื่อไม่ซ้ำกันอยู่แล้ว |
| Foreign key หลายคอลัมน์ | อ้าง index ใดก็ได้ | 8.4 ต้องอ้าง unique key | อ้าง `UNIQUE (id, shop_id)` ทั้งหมด |
| `TINYINT(1)` และ `VALUES()` ใน `ON DUPLICATE KEY` | ปกติ | ใช้ได้ มี deprecation warning | คงไว้ |
| `LOCK IN SHARE MODE` | ปกติ | ยังรองรับ | คงไว้ |
| `sql_mode`, time zone, isolation | ตั้งต่อ connection | เหมือนกัน | `Database::connect()` กำหนดเอง ไม่ขึ้นกับค่า server |
| Login ของ DB user | native password | ค่าเริ่มต้น `caching_sha2_password` | `mysqlnd` ของ PHP 8.2 รองรับ |
| Binary log | ค่าเริ่มต้น `MIXED` | ค่าเริ่มต้น `ROW` | ใช้ `READ COMMITTED` ได้ทั้งคู่ ห้ามตั้งเป็น `STATEMENT` |

`008_runtime.sql` ถูกแก้หลังเคยรันใน `qim_dev` (checksum เปลี่ยน) database ที่ติดตั้ง `008` รุ่นเก่าไว้จะถูก `migrate.php` ปฏิเสธ ขณะนี้มีเพียงเครื่องพัฒนาซึ่ง reset แล้ว

## Rate limit

| รายการ | นับตาม | เพดาน |
|---|---|---|
| Login ร้าน/ผู้ดูแล | บัญชี + IP (login สำเร็จล้างตัวนับ) | 10 ต่อ 15 นาที |
| กันไล่เดาหลายบัญชี | IP | 300 ต่อ 15 นาที |
| Login ลูกค้า ยังไม่มี session | IP | 600 ต่อนาที |
| Login ลูกค้า มี session แล้ว | user id | 30 ต่อนาที |
| Checkout | user id | 20 ต่อนาที |
| อัปโหลดรูป | user id | 60 ต่อชั่วโมง |

ตัวนับเป็นช่วงเวลาคงที่ หมดพร้อมช่วงเวลา แถวที่เก่ากว่า 2 ชั่วโมงถูกล้างระหว่างใช้งาน ข้อจำกัด: คร่อมสองช่วงเวลาอาจทำได้ถึง 2 เท่าของเพดานในเวลาสั้น ๆ

## ข้อจำกัดที่ทราบ

- ยังไม่ทดสอบกับ LINE จริง, โทรศัพท์จริง, hosting จริง และ load สูง
- การทดสอบ concurrency ใช้ PHP server 8 process ในเครื่องเดียว
- Session เป็นไฟล์ในเครื่อง ถ้าใช้หลาย web server ต้องย้ายที่เก็บ
- ไม่มี refund, ออเดอร์หมดอายุอัตโนมัติ, การคืนสถานะ หรือสิทธิ์ย่อยของสมาชิกร้าน (ดู [decisions.md](decisions.md))
- ไม่มี UI ให้ลูกค้าลบบัญชี/ข้อมูล และยังไม่กำหนด retention
- รูปเก่าที่ถูกแทนที่ยังอยู่ใน `storage/uploads`
- Search ใช้ `LIKE` เหมาะกับร้านหลักร้อย
- ไม่มีเครื่องมือส่ง notification ที่ `FAILED` ซ้ำ
- ตัวเตือนออเดอร์ค้างแสดงเฉพาะหน้า "ออเดอร์" ของร้าน และคำนวณจากนาฬิกาของเครื่องร้าน
- ออเดอร์ `READY` ที่ไม่มีคนรับไม่มีทางปิดนอกจากรับเงินและส่งมอบ (รอนโยบาย)
