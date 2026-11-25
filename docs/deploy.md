# Deploy

ยังไม่เคย deploy จริง รายการนี้ให้ผู้ดูแลทำตามลำดับ

1. **Backup** database ก่อนทุกครั้ง: `mysqldump --single-transaction <db> > backup.sql` เก็บนอก web root และทดสอบ restore
2. PHP รุ่นที่ยังได้รับ security updates พร้อม `pdo_mysql`, `curl`, `gd`, `fileinfo`, `mbstring`; MariaDB 10.4+ (MySQL: `008_runtime.sql` ใช้ `ADD COLUMN IF NOT EXISTS` ต้องปรับก่อนรัน)
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

## ข้อจำกัดที่ทราบ

- ยังไม่ทดสอบกับ LINE จริง, โทรศัพท์จริง, hosting จริง และ load สูง
- การทดสอบ concurrency ใช้ PHP server 8 process ในเครื่องเดียว
- Session เป็นไฟล์ในเครื่อง ถ้าใช้หลาย web server ต้องย้ายที่เก็บ
- ไม่มี refund, ออเดอร์หมดอายุอัตโนมัติ, การคืนสถานะ หรือสิทธิ์ย่อยของสมาชิกร้าน (ดู [decisions.md](decisions.md))
- ไม่มี UI ให้ลูกค้าลบบัญชี/ข้อมูล และยังไม่กำหนด retention
- รูปเก่าที่ถูกแทนที่ยังอยู่ใน `storage/uploads`
- Search ใช้ `LIKE` เหมาะกับร้านหลักร้อย
- ไม่มีเครื่องมือส่ง notification ที่ `FAILED` ซ้ำ
- `qim_dev` มีร้าน ผู้ใช้ และออเดอร์ทดสอบสะสมจากการรัน tests (ร้านถูกปิดไว้ ไม่ลบ)
