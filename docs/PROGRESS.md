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
| 9 security, concurrency, responsive, docs | รอทำ |

## วิธีรันทดสอบ

```powershell
C:\xampp\php\php.exe tests\run.php        # ทุก test
C:\xampp\php\php.exe tests\run.php 05     # เฉพาะไฟล์ที่ชื่อมี 05
```

ใช้ port 8111 ขึ้นไป และ `qim_dev` เท่านั้น

## ค้างอยู่

- ยังไม่มี
