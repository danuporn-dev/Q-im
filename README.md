# Q-im (คิวอิ่ม)

Smart Food Court Platform: สั่งอาหารล่วงหน้า คิวอัตโนมัติ รับเอง

**สถานะ:** MVP ครบ Phase 0–9 ในเครื่องพัฒนา ยังไม่ทดสอบกับ LINE จริงและยังไม่ deploy

เอกสาร: [CLAUDE.md](CLAUDE.md) · [ERD](docs/erd.md) · [decisions](docs/decisions.md) · [progress](docs/PROGRESS.md) · [LINE setup](docs/line-setup.md) · [deploy](docs/deploy.md)

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

## ใช้งาน

| หน้า | URL (built-in server) | เข้าใช้ |
|---|---|---|
| ลูกค้า | `http://localhost:8000/customer/` | ปุ่ม Demo (เฉพาะ development) หรือ LINE |
| ร้าน | `http://localhost:8000/merchant/` | `demo_merchant_1`, `demo_merchant_2` |
| ผู้ดูแล | `http://localhost:8000/admin/` | `demo_admin` |

รหัสผ่าน demo คือ `SEED_DEMO_PASSWORD` ใน `.env` ร้าน demo เปิดจันทร์–ศุกร์ 07:00–17:00

## ทดสอบ

```powershell
C:\xampp\php\php.exe tests\run.php       # เทสต์ทั้งหมด
C:\xampp\php\php.exe tests\run.php 05    # เฉพาะไฟล์ที่ชื่อมี 05
C:\xampp\php\php.exe scripts\e2e.php     # หนึ่งออเดอร์ครบวงจรผ่าน HTTP
```

ทั้งสองคำสั่งสร้าง database `qim_test` เองจาก migrations + seed แล้วลบเมื่อจบ ไม่เขียนลง `qim_dev` (ตรวจ checksum ก่อนและหลัง ถ้าเปิดเว็บใช้งานระหว่างเทสต์จะรายงานว่าเปลี่ยน) ไม่ต้องตั้ง `DB_NAME` เอง รันพร้อมกันสองคำสั่งไม่ได้ ต้องมี MariaDB ที่รันอยู่ในเครื่อง, `APP_ENV=development`, port 8111–8299, 9377–9381 ว่าง และ Chrome หรือ Edge สำหรับ T20 (ไม่มีจะรายงาน SKIPPED)

ล้าง `qim_dev` กลับเป็นข้อมูล seed (**ลบทุกแถว**): `C:\xampp\php\php.exe scripts\reset-dev.php --confirm`

สาธิตสด: `C:\xampp\php\php.exe scripts\demo-reset.php --confirm` (**ลบทุกแถว**ของ `qim_dev` แล้วใส่ข้อมูลสาธิต ห้ามรัน `seed.php` ต่อ) ดู [run sheet](docs/demo.md)

แจ้งเตือน LINE: `php scripts/notify.php [--watch]`

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

โปรเจกต์อยู่ใต้ `htdocs` จึงมี `.htaccess` ที่ root ปิดทุกไฟล์นอก `public/` ยกเว้นที่อยู่โฟลเดอร์ `http://localhost/Q-im/` ซึ่ง redirect ไปหน้าเลือก role `public/start.html` (ให้หน้า "Index of /" ของ XAMPP แสดงและคลิกเข้าได้) และ `public/.htaccess` เปิดเฉพาะ `public/` เป็นแนวป้องกันเสริม ไม่ใช่สิ่งทดแทนการตั้ง document root

เปิดผ่าน Apache ใน htdocs ได้โดยไม่ต้องตั้ง VirtualHost: `http://localhost/Q-im/public/customer/`, `.../merchant/`, `.../admin/` (เปิด Apache และ MySQL ใน XAMPP Control Panel ก่อน)
