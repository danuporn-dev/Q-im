# LINE setup checklist

**สถานะ: ยังไม่เคยทดสอบกับ LINE จริง** โค้ดผ่านการทดสอบกับ LINE API จำลอง (`tests/07_line.php`) เท่านั้น ทุกข้อด้านล่างต้องทำโดยเจ้าของโปรเจกต์ อ้างอิงเอกสาร LINE ณ 2026-11-25

## 1. สร้าง channel

- [ ] สร้าง LINE Official Account และ provider ใน [LINE Developers](https://developers.line.biz/console/)
- [ ] สร้าง **LINE Login channel** และ **Messaging API channel** ภายใต้ **provider เดียวกัน** (ต่าง provider จะได้ user ID คนละค่า และ push ไม่ถึงลูกค้า)
- [ ] ตรวจว่าโปรเจกต์ควรใช้ LIFF หรือ LINE MINI App ตามสิทธิ์ของบัญชี โค้ดนี้ใช้ LIFF SDK 2.31.1
- [ ] ผูก LINE Login channel กับ OA (Linked OA) และตั้ง Add friend option ตามต้องการ

## 2. LIFF

- [ ] เพิ่ม LIFF app: Endpoint URL = `https://<โดเมน>/customer/` (ต้องเป็น HTTPS และเป็น path นี้โดยตรง ห้ามชี้ไป `/` เพราะมี redirect ก่อน `liff.init()`)
- [ ] Scope: `openid` และ `profile` (ไม่มี `openid` จะไม่ได้ ID token)
- [ ] Size: Full

## 3. ตั้งค่า `.env` บน server (ห้าม commit)

| ตัวแปร | มาจาก |
|---|---|
| `LIFF_ID` | LIFF app |
| `LINE_LOGIN_CHANNEL_ID` | LINE Login channel → Channel ID |
| `LINE_CHANNEL_SECRET` | **Messaging API** channel → Channel secret |
| `LINE_CHANNEL_ACCESS_TOKEN` | **Messaging API** channel → long-lived token |
| `APP_URL` | `https://<โดเมน>` |

## 4. Webhook

- [ ] Webhook URL = `https://<โดเมน>/api/v1/line/webhook`, เปิด Use webhook แล้วกด Verify ต้องสำเร็จ
- [ ] เปิด Webhook redelivery ได้ ระบบกัน event ซ้ำแล้ว
- [ ] ปิด auto-reply / greeting ตามต้องการ

## 5. Rich Menu (URI action)

- [ ] สั่งอาหาร: `https://liff.line.me/<LIFF_ID>`
- [ ] ออเดอร์ของฉัน: `https://liff.line.me/<LIFF_ID>#/orders`
- [ ] วิธีใช้งาน/ติดต่อ: `https://liff.line.me/<LIFF_ID>#/help`

## 6. Worker

- [ ] ตั้งให้รันทุก 1 นาที

  ```
  * * * * * php /path/to/q-im/scripts/notify.php --watch
  ```

  Windows: Task Scheduler → `C:\xampp\php\php.exe` argument `C:\xampp\htdocs\Q-im\scripts\notify.php --watch` ทุก 1 นาที
- [ ] ตรวจโควตาข้อความของ OA; ออเดอร์หนึ่งใช้ปกติ 2 ข้อความ (รับ, พร้อมรับ)

## 7. ทดสอบกับ LINE จริง

- [ ] เปิดจาก Rich Menu บน iOS และ Android → เข้าบัญชีของตน (T01)
- [ ] เปิดลิงก์ `#/orders` จาก Rich Menu แล้วไปหน้าออเดอร์ (LIFF ส่ง fragment ผ่าน `liff.state`)
- [ ] เปิดใน browser ภายนอก → LINE Login → กลับหน้าเดิม
- [ ] ปิดแล้วเปิดใหม่ระหว่างมีออเดอร์ → สถานะเดิม (T18)
- [ ] เปิดค้างเกิน 1 ชั่วโมงแล้วเปิดใหม่ (ID token หมดอายุ) → ยังใช้งานหรือ login ใหม่ได้
- [ ] รับข้อความเมื่อร้านรับออเดอร์และเมื่อพร้อมรับ; กดลิงก์ในข้อความเปิดออเดอร์ถูกใบ
- [ ] บล็อก OA → ไม่มีข้อความ และหน้าเว็บยังติดตามได้ (T12)
- [ ] ยืนยัน HTTP status ของ token ผิด/หมดอายุจาก verify endpoint (เอกสารไม่ระบุเลข โค้ดถือทุก status ที่ไม่ใช่ 200 ว่าไม่ผ่าน)
- [ ] ตรวจ console ว่า Content-Security-Policy ใน `public/customer/index.html` ไม่บล็อก LIFF SDK
- [ ] ตรวจ privacy notice และข้อกำหนดของ LINE ก่อนเปิดใช้งาน
