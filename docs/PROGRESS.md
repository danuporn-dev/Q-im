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
| 6 prepare/ready/pickup/payment, e2e | เสร็จ (ลดเหลือ 3 ขั้น + QR ตาม D31) |
| 7 LINE | เสร็จ (LINE จำลอง; ยังไม่ทดสอบ LINE จริง) |
| 8 admin, reports, audit | เสร็จ |
| 9 security, concurrency, responsive, docs | เสร็จ |
| หลัง MVP: ปิดช่องว่าง | เสร็จ |
| เทสต์แยกบน `qim_test` | เสร็จ |
| READY no-show (D26) | เสร็จ |
| Audit การส่งมอบไม่สแกน QR (D27) | เสร็จ |
| เครื่องมือสาธิต (D28, [demo.md](demo.md)) | เสร็จ (ยังไม่ซ้อมบนโทรศัพท์จริงและโปรเจกเตอร์จริง) |
| API ลูกค้าไม่ส่งข้อมูลภายในร้าน (D29) | เสร็จ |
| เปิดสาธิตผ่าน HTTPS proxy/ngrok (D30) | เสร็จ (proxy จำลอง; ยังไม่ทดสอบ ngrok จริง) |
| A0: ร้าน 3 ขั้น + QR (D31), ช่องทำอาหาร/ETA, ยืนยันรับเงิน, จำกัด QR ผิด (D32) | เสร็จ (เทสต์เต็มชุดผ่านแล้ว) |
| A1 โรงอาหาร, ความหนาแน่น, หน้าข้อมูล (D34) | เสร็จ (หน้าข้อมูลเป็นฉบับร่าง) |
| A2 รายการโปรด, รีวิว (D35, migration 010) | เสร็จ |
| A3 แจ้งเตือนในเว็บ + กระดิ่งร้าน (D36, migration 011) | เสร็จ |
| A4 สถิติร้าน + `demo-history.php` (D37) | เสร็จ (กราฟแท่ง CSS อย่างง่ายตาม D33) |
| A6 PromptPay แบบร้านยืนยัน + QR token ใช้ครั้งเดียว (D38, migration 012) | เสร็จ (ยังไม่ทดสอบกับแอปธนาคารและกล้องจริง) |
| รีวิวความปลอดภัยตอนจบ (endpoint/ตารางใหม่) | เสร็จ: พบและแก้ race ของ pickup token เมื่อคีย์เปลี่ยน (`tests/24_security_review.php`) |
| ปิดงาน: เทสต์ 19 นิ่ง, คีย์ QR, e2e PromptPay + token (D39) | เสร็จ |
| หน้าตาตาม Figma (D40, [ui-compare](ui-compare/README.md)) | เสร็จ (ยังไม่ตรวจบนโทรศัพท์จริง) |
| A5 จอแสดงคิวทีวี | **เลื่อน** (ตัดสินใจเรื่องเวลา D33 ไม่ใช่ข้อบกพร่อง) |

## วิธีรันทดสอบ

```powershell
C:\xampp\php\php.exe tests\run.php        # ทุก test
C:\xampp\php\php.exe tests\run.php 05     # เฉพาะไฟล์ที่ชื่อมี 05
C:\xampp\php\php.exe scripts\e2e.php
```

ใช้ `qim_test` ที่สร้างและลบเองเท่านั้น (รวม clean install, migration ซ้ำ และ seed ซ้ำ) รายละเอียดใน README

## ค้างอยู่

- ทดสอบ LINE จริงตาม line-setup.md (T01, T02, T12 ผ่านกับ LINE จำลองเท่านั้น)
- QR PromptPay ต้องสแกนด้วยแอปธนาคารจริงเพื่อตรวจชื่อผู้รับและยอดก่อนใช้งานจริง
- กล้องสแกน QR ทดสอบเฉพาะการพิมพ์/ถอดรหัสภาพใน Chrome headless ยังไม่ได้ใช้กล้องจริง
- UI ตรวจด้วย Chrome headless (`tests/11_browser.php`) ยังไม่ตรวจบนโทรศัพท์จริง
- MySQL ยังไม่ได้ทดสอบ
- ข้อจำกัดอื่นอยู่ใน deploy.md

## บันทึก

- Review หลัง MVP พบและแก้: (1) login ด้วยอักษรมี accent ข้าม rate limit ต่อบัญชี (2) role ลูกค้าที่ admin ถอดกลับมาเมื่อ login ใหม่ (3) API ร้านส่งชื่อลูกค้าขัดกับ privacy notice (4) ข้อความ 404 ต่างกันเผยว่า order/menu ของร้านอื่นมีอยู่ (5) ยอดเกิน DECIMAL(10,2) ตอบ 500 (6) admin สองคนปิดบัญชีกันพร้อมกันได้ (7) แก้ตะกร้าล็อกแถวร้านโดยไม่จำเป็น เทสต์อยู่ใน `tests/10_review.php` (ยกเว้นข้อ 7)
- Commit ของ Phase 8 (`da5dce9`) มี 2 tests ล้มจากตัวนับ rate limit ที่สะสมข้ามการรัน แก้ใน commit ของ Phase 9 ด้วย `RATE_LIMIT_SCOPE` (มีผลเฉพาะ development)
