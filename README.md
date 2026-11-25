# Q-im (คิวอิ่ม)

Smart Food Court Platform: สั่งอาหารล่วงหน้า คิวอัตโนมัติ รับเอง

**สถานะ:** Phase 0 และฐานข้อมูลของ Phase 1 ยังไม่มี API และ UI รายละเอียดโปรเจกต์อยู่ใน [CLAUDE.md](CLAUDE.md), ERD อยู่ใน [docs/erd.md](docs/erd.md)

## ติดตั้งบน XAMPP / Windows

ต้องมี PHP 8.2+ (`pdo_mysql`) และ MariaDB 10.4+ ไม่ใช้ Composer ตัวอย่างใช้ PowerShell ที่โฟลเดอร์โปรเจกต์

1. Start MySQL ใน XAMPP Control Panel
2. สร้าง `.env` แล้วกรอก `DB_USER`, `DB_PASSWORD` และ `SEED_DEMO_PASSWORD` (อย่างน้อย 12 ตัวอักษร)

   ```powershell
   Copy-Item .env.example .env
   ```

3. สร้างตารางและข้อมูลตัวอย่าง

   ```powershell
   C:\xampp\php\php.exe scripts\migrate.php
   C:\xampp\php\php.exe scripts\seed.php
   ```

`migrate.php` สร้าง database ตาม `DB_NAME` (ค่าเริ่มต้น `qim_dev`) ถ้ายังไม่มี รันซ้ำได้ และปฏิเสธ database ที่มีตารางอื่นอยู่แล้ว ห้ามแก้ migration ที่รันแล้ว ให้เพิ่มไฟล์ใหม่

`seed.php` รันได้เฉพาะ `APP_ENV=development` รันซ้ำไม่แก้ข้อมูลเดิม รวมถึงรหัสผ่านเดิม บัญชี demo: `demo_admin`, `demo_merchant_1`, `demo_merchant_2`

## Document root ต้องเป็น `public/`

`.env`, `config/`, `app/`, `storage/` ต้องอยู่นอก web root เลือกวิธีใดวิธีหนึ่ง

**PHP built-in server**

```powershell
C:\xampp\php\php.exe -S localhost:8000 -t public
```

**Apache VirtualHost** เพิ่มใน `C:\xampp\apache\conf\extra\httpd-vhosts.conf` แล้ว restart Apache

```apache
# คงเว็บเดิมใน htdocs ไว้: VirtualHost แรกเป็นค่าเริ่มต้น (ข้ามถ้ามีอยู่แล้ว)
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot "C:/xampp/htdocs"
</VirtualHost>

<VirtualHost *:80>
    ServerName qim.localhost
    DocumentRoot "C:/xampp/htdocs/Q-im/public"
    <Directory "C:/xampp/htdocs/Q-im/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

เปิด `http://qim.localhost/` ถ้า browser หาไม่เจอ ให้เพิ่ม `127.0.0.1 qim.localhost` ใน `C:\Windows\System32\drivers\etc\hosts` (สิทธิ์ Administrator)

โปรเจกต์อยู่ใต้ `htdocs` จึงมี `.htaccess` ที่ root ปิด `http://localhost/Q-im/` ทุกไฟล์ และ `public/.htaccess` เปิดเฉพาะ `public/` เป็นแนวป้องกันเสริม ไม่ใช่สิ่งทดแทนการตั้ง document root
