# Q-im ERD (MVP)

แสดงเฉพาะ key หลัก รายละเอียดเต็มอยู่ใน `database/migrations/`

```mermaid
erDiagram
    users ||--o{ user_roles : has
    roles ||--o{ user_roles : grants
    users ||--o| merchant_accounts : "local login"
    users ||--o| admin_accounts : "local login"
    users ||--o{ shop_members : "works at"
    shops ||--o{ shop_members : has
    food_courts ||--o{ shops : contains
    shops ||--o{ shop_hours : opens
    shops ||--o| shop_settings : configures
    shops ||--o{ categories : has
    shops ||--o{ menus : sells
    categories |o--o{ menus : groups
    menus ||--o{ menu_option_groups : has
    menu_option_groups ||--o{ menu_options : has
    users ||--o| carts : owns
    shops ||--o{ carts : "ordered from"
    carts ||--o{ cart_items : contains
    menus ||--o{ cart_items : "selected as"
    cart_items ||--o{ cart_item_options : with
    menu_options ||--o{ cart_item_options : selected
    users ||--o{ orders : places
    shops ||--o{ orders : receives
    orders ||--|{ order_items : contains
    menus ||--o{ order_items : "snapshot of"
    order_items ||--o{ order_item_options : with
    menu_options ||--o{ order_item_options : "snapshot of"
    orders ||--o| queues : "gets on accept"
    shops ||--o{ queues : numbers
    shops ||--o{ queue_counters : "counts per day"
    orders ||--o{ order_status_logs : logs
    users |o--o{ order_status_logs : acts
    orders ||--o| payments : "paid by"
    users |o--o{ payments : confirms
    orders ||--o{ notifications : emits
    users ||--o{ notifications : receives
    users |o--o{ audit_logs : acts

    users {
        bigint id PK
        varchar line_user_id UK "nullable"
    }
    merchant_accounts {
        bigint user_id UK
        varchar login_name UK
    }
    admin_accounts {
        bigint user_id UK
        varchar login_name UK
    }
    shop_members {
        bigint shop_id FK "UK(shop_id,user_id)"
        bigint user_id FK
    }
    carts {
        bigint user_id UK "one cart per user"
        bigint shop_id FK
    }
    orders {
        bigint id PK
        varchar public_order_no UK
        bigint user_id FK "UK(user_id,idempotency_key)"
        varchar idempotency_key
        varchar status
        decimal total
    }
    queues {
        bigint order_id UK
        bigint shop_id FK "UK(shop_id,business_date,queue_number)"
        date business_date
        int queue_number
    }
    queue_counters {
        bigint shop_id PK
        date business_date PK
        int last_number
    }
    payments {
        bigint order_id UK "no duplicate payment"
        varchar status
    }
    notifications {
        varchar dedupe_key UK
        varchar status
    }
```

## ข้อสมมติ / Open decisions

> ทุกข้อที่ยังไม่ติ๊กใช้ค่าเริ่มต้นใน [decisions.md](decisions.md) แล้ว เจ้าของโปรเจกต์เปลี่ยนได้ภายหลัง

### จาก CLAUDE.md หัวข้อ 17 (รอเจ้าของโปรเจกต์ยืนยัน)

- [ ] เริ่มใช้กับโรงอาหารมหาวิทยาลัย
- [ ] หนึ่งตะกร้าต่อหนึ่งร้าน (schema ปัจจุบัน: `UNIQUE carts.user_id`)
- [ ] ชำระที่ร้านใน MVP (schema ปัจจุบัน: `PAY_AT_STORE` เท่านั้น)
- [ ] FIFO ตามเวลาที่ร้านยืนยัน
- [ ] ลูกค้ายกเลิกเองได้เฉพาะ `PENDING`
- [ ] UI เขียว/ขาว
- [ ] PHP/MySQL stack
- [ ] Merchant/Admin ใช้ local login
- [ ] AJAX polling
- [ ] QR pickup เป็น optional (ยังไม่มีตาราง)
- [ ] ข้อมูลที่ยังไม่มี: hosting, LINE credentials, payment provider, capacity ของร้าน, นโยบายการดำเนินงาน

### พบระหว่างออกแบบ schema (รอยืนยัน)

- [ ] `shops.capacity` กับ `shop_settings.max_active_orders`: §6 ใช้ capacity ทั้งคำนวณ ETA และจำกัดการรับออเดอร์ ต้องนิยามว่าค่าใดใช้ทำอะไร (ตอนนี้เก็บทั้งสองค่า เป็น NULL ได้ ยังไม่มี logic)
- [ ] วันตัดรอบ `business_date`: เที่ยงคืน Asia/Bangkok หรือเวลาอื่น และยึดวันที่ร้านยืนยันออเดอร์หรือวันที่สั่ง
- [ ] รูปแบบ `public_order_no` (ตอนนี้จองไว้ 32 ตัวอักษร)
- [ ] รูปแบบและค่าของ `shop_members.permissions` (ตอนนี้เป็น TEXT ว่างได้)
- [ ] การอนุมัติร้าน (§5 Admin): `active` เพียงพอ หรือต้องมีสถานะรออนุมัติแยก
- [ ] ออเดอร์ `PENDING` ที่ร้านไม่ตอบ: มีเวลาหมดอายุหรือไม่
- [ ] ออเดอร์ `CANCELLED` หลังชำระแล้ว: ใช้ `REFUNDED` เมื่อใด และใครทำได้
- [ ] LINE Login กับ Messaging API: ใช้ `LINE_CHANNEL_SECRET` ตัวเดียวหรือแยกสองตัว

### ยืนยันแล้ว

- [x] แยก `admin_accounts` จาก `merchant_accounts`
- [x] `merchant_accounts` และ `admin_accounts` เพิ่ม `login_name` (§9 ไม่มีคอลัมน์สำหรับ login)

### ส่วนที่เพิ่มจาก CLAUDE.md หัวข้อ 9

- `active` ใน `categories` และ `menu_option_groups` เพื่อเลิกใช้โดยไม่ลบ
- `shop_settings`, `queue_counters` และ `schema_migrations` ใช้ natural key เป็น PK แทน `id`
- `day_of_week`: 1 = จันทร์ ถึง 7 = อาทิตย์ (ISO-8601)
- `notifications.status`: `PENDING`, `SENDING`, `SENT`, `FAILED`
- ความสัมพันธ์ที่ database ไม่บังคับและ API ต้องตรวจ: เมนูในตะกร้า/ออเดอร์อยู่ร้านเดียวกัน, option เป็นของเมนูนั้น, เงื่อนไข min/max, `COMPLETED` ต้อง `PAID`, ลำดับ transition
