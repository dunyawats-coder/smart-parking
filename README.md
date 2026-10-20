# 🅿️ Smart Parking

ระบบที่จอดรถอัจฉริยะแบบโอเพนซอร์ส: ESP32 + เซนเซอร์อัลตราโซนิกตรวจช่องจอด, ESP32-CAM ถ่ายรูปรถ, เว็บ PHP/MySQL สำหรับจองช่อง ชำระเงิน PromptPay แนบสลิป แอดมินอนุมัติ และแดชบอร์ดวิเคราะห์การใช้งาน

> โค้ดทั้งหมดเปิดให้ทุกคนแก้ไขผ่าน Pull Request ส่วนฐานข้อมูลจริงรันบนเครื่องของเจ้าของโปรเจกต์เท่านั้น (ดู [docs/SELF_HOSTING.md](docs/SELF_HOSTING.md))

## โครงสร้าง
| โฟลเดอร์ | หน้าที่ |
|---|---|
| `web/` | เว็บ PHP + API (login, จอง, ชำระเงิน, แอดมิน, analytics) |
| `database/` | `schema.sql` โครงสร้าง, `seed_demo.sql` ข้อมูลตัวอย่าง, `migrations/` |
| `firmware/` | โค้ด Arduino: `esp32_smart_parking` (เซนเซอร์+LED), `esp32cam_*` (กล้อง 3 ช่อง) |
| `docs/` | คู่มือติดตั้งบนเครื่องตัวเอง |

## เริ่มใช้งานเร็ว ๆ (Docker)
```bash
cp .env.example .env     # ตั้งรหัสผ่านใหม่
docker compose up -d --build
# เปิด http://localhost:8080   (ทดลอง: admin / admin1234  หรือ demo / demo1234)
```
ติดตั้งแบบ XAMPP และเปิดให้คนนอกเข้าถึงผ่าน tunnel: [docs/SELF_HOSTING.md](docs/SELF_HOSTING.md)

## ตั้งค่า ESP32
ในแต่ละโฟลเดอร์ใน `firmware/` คัดลอก `secrets.h.example` → `secrets.h` แล้วใส่ WiFi + IP เซิร์ฟเวอร์ (ไฟล์นี้ไม่ขึ้น GitHub)

## ข้อจำกัดที่ทราบ
- ESP32 รุ่นทั่วไป GPIO34 เป็นขา input-only ใช้เป็น TRIG ไม่ได้ (ถ้าช่อง 1 อ่านค่าไม่ได้ ให้ย้ายขา)
- Endpoint ของอุปกรณ์ยังไม่มี API key — ดูรายการความปลอดภัยใน `docs/SELF_HOSTING.md` (งานที่ยินดีรับ PR)

## ร่วมพัฒนา
อ่าน [CONTRIBUTING.md](CONTRIBUTING.md) • License: [MIT](LICENSE)
