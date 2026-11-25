# PROGRESS

อ่านไฟล์นี้ก่อนทำต่อ คู่กับ [decisions.md](decisions.md) ทำงานที่ branch `feature/mvp`

| Phase | สถานะ |
|---|---|
| 0 scope, ERD | เสร็จ |
| 1 migrations, seed, API foundation | เสร็จ |
| 2 auth, session, RBAC | เสร็จ |
| 3 shop/menu management, discovery | เสร็จ |
| 4 customer UI, cart, checkout | เสร็จ |
| 5 accept/reject, queue, tracking | เสร็จ |
| 6 prepare/ready/pickup/payment, e2e | เสร็จ |
| 7 LINE | เสร็จ (LINE จำลอง; ยังไม่ทดสอบ LINE จริง) |
| 8 admin, reports, audit | เสร็จ |
| 9 security, concurrency, responsive, docs | เสร็จ |
| หลัง MVP: ปิดช่องว่าง | เสร็จ |

## วิธีรันทดสอบ

```powershell
C:\xampp\php\php.exe tests\run.php        # ทุก test
C:\xampp\php\php.exe tests\run.php 05     # เฉพาะไฟล์ที่ชื่อมี 05
C:\xampp\php\php.exe scripts\e2e.php
```

ใช้ `qim_test` ที่สร้างและลบเองเท่านั้น (รวม clean install, migration ซ้ำ และ seed ซ้ำ) รายละเอียดใน README

## ค้างอยู่

- ทดสอบ LINE จริงตาม line-setup.md (T01, T02, T12 ผ่านกับ LINE จำลองเท่านั้น)
- T22 ไม่ทำ เพราะ QR pickup เป็น Extension
- UI ตรวจด้วย Chrome headless (`tests/11_browser.php`) ยังไม่ตรวจบนโทรศัพท์จริง
- MySQL ยังไม่ได้ทดสอบ
- คำถาม: ออเดอร์ `READY` ที่ไม่มีคนรับจะปิดอย่างไร; การส่งมอบโดยไม่ใช้รหัสต้องให้ admin อนุมัติหรือไม่
- ข้อจำกัดอื่นอยู่ใน deploy.md

## บันทึก

- Review หลัง MVP พบและแก้: (1) login ด้วยอักษรมี accent ข้าม rate limit ต่อบัญชี (2) role ลูกค้าที่ admin ถอดกลับมาเมื่อ login ใหม่ (3) API ร้านส่งชื่อลูกค้าขัดกับ privacy notice (4) ข้อความ 404 ต่างกันเผยว่า order/menu ของร้านอื่นมีอยู่ (5) ยอดเกิน DECIMAL(10,2) ตอบ 500 (6) admin สองคนปิดบัญชีกันพร้อมกันได้ (7) แก้ตะกร้าล็อกแถวร้านโดยไม่จำเป็น เทสต์อยู่ใน `tests/10_review.php` (ยกเว้นข้อ 7)
- Commit ของ Phase 8 (`da5dce9`) มี 2 tests ล้มจากตัวนับ rate limit ที่สะสมข้ามการรัน แก้ใน commit ของ Phase 9 ด้วย `RATE_LIMIT_SCOPE` (มีผลเฉพาะ development)
