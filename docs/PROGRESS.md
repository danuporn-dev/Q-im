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

## วิธีรันทดสอบ

```powershell
C:\xampp\php\php.exe tests\run.php        # ทุก test
C:\xampp\php\php.exe tests\run.php 05     # เฉพาะไฟล์ที่ชื่อมี 05
```

ใช้ port 8111 ขึ้นไป และ `qim_dev` เท่านั้น

## ค้างอยู่

- ทดสอบ LINE จริงตาม line-setup.md (T01, T02, T12 ผ่านกับ LINE จำลองเท่านั้น)
- T22 ไม่ทำ เพราะ QR pickup เป็น Extension
- UI ตรวจด้วย Chrome headless แบบ script นอก repo (320–1280 px, XSS, offline, ช้า); ยังไม่ตรวจบนโทรศัพท์จริง
- Clean install 001–008 ต่อเนื่องบน database ว่างยังไม่ได้รัน (รันจริงคือ 001–007 แล้วต่อ 008) เพราะห้าม DROP และห้ามใช้ DB อื่น
- ข้อจำกัดอื่นอยู่ใน deploy.md

## บันทึก

- Commit ของ Phase 8 (`da5dce9`) มี 2 tests ล้มจากตัวนับ rate limit ที่สะสมข้ามการรัน แก้ใน commit ของ Phase 9 ด้วย `RATE_LIMIT_SCOPE` (มีผลเฉพาะ development)
