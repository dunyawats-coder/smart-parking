# ใช้เครื่องตัวเองเป็นเซิร์ฟเวอร์ + ฐานข้อมูล

หลักการ: **โค้ด** อยู่บน GitHub (คนอื่นแก้/เสนอ PR ได้) แต่ **ฐานข้อมูลจริง + รหัสผ่าน + สลิป** อยู่เฉพาะเครื่องคุณ ไม่ขึ้น GitHub

```
ผู้ร่วมพัฒนา ──PR──▶ GitHub (โค้ดเท่านั้น) ──git pull──▶ เครื่องคุณ (Apache/PHP + MariaDB + ข้อมูลจริง) ◀── ESP32 / ผู้ใช้ในเครือข่าย
```

## วิธี A: Docker (แนะนำ)
```bash
git clone https://github.com/<ชื่อคุณ>/smart-parking.git && cd smart-parking
cp .env.example .env        # แก้รหัสผ่านทุกตัว
docker compose up -d --build
# เปิด http://localhost:8080
```
ESP32 ชี้ไปที่ `http://<IP เครื่องคุณ>:8080/smart_parking_integrated/...` (แก้ `SECRET_SERVER_IP` และเพิ่ม `:8080` ถ้าใช้พอร์ตนี้ หรือตั้ง `WEB_PORT=80`)

## วิธี B: XAMPP (Windows)
1. คัดลอกโฟลเดอร์ `web/` ไปที่ `C:\xampp\htdocs\smart_parking_integrated\`
2. phpMyAdmin → Import `database/schema.sql` (+ `seed_demo.sql` ถ้าต้องการข้อมูลตัวอย่าง)
3. สร้างผู้ใช้ DB เฉพาะ: `CREATE USER 'smartparking'@'localhost' IDENTIFIED BY '...'; GRANT ALL ON smartparking.* TO 'smartparking'@'localhost';`
4. คัดลอก `config.example.php` → `config.php` แล้วใส่ค่าจริง
5. เปิด `http://localhost/smart_parking_integrated/login.php`

## อัปเดตโค้ดจาก GitHub
```bash
git pull && docker compose up -d --build     # หรือ git pull เฉย ๆ ถ้าใช้ XAMPP
```
ก่อนรัน migration ใหม่ให้สำรองฐานข้อมูลก่อนเสมอ: `docker compose exec db mariadb-dump -uroot -p smartparking > backup_$(date +%F).sql` (ไฟล์ backup ถูก .gitignore)

## เปิดให้คนนอกบ้านเข้าถึง (ไม่ต้องเปิดพอร์ตเราเตอร์)
ใช้ tunnel เช่น **Cloudflare Tunnel** (`cloudflared tunnel --url http://localhost:8080`) หรือ **Tailscale Funnel** จะได้ลิงก์ https ให้คนอื่นเปิดดูหน้าเว็บ
- เปิดเฉพาะพอร์ตเว็บ **ห้ามเปิดพอร์ต 3306 (MySQL) สู่อินเทอร์เน็ต**
- ESP32 ควรอยู่ใน LAN และใช้ IP ภายในเท่านั้น

## ความปลอดภัย (ต้องทำก่อนเปิดสาธารณะ)
- [ ] เปลี่ยนรหัส `admin` / `demo` จาก seed หรือลบ `seed_demo.sql` ออก
- [ ] ตั้ง `admin_key`, รหัส DB ใหม่ทั้งหมด (ค่าใน repo เป็นแค่ตัวอย่าง)
- [ ] **เปลี่ยนรหัส WiFi** และเปลี่ยนเบอร์ PromptPay ถ้าเคยใช้ค่าเดิมในไฟล์ที่เคยแชร์ (ไฟล์ต้นฉบับใช้เบอร์โทรเดียวกันเป็นทั้งรหัส WiFi และ PromptPay)
- [ ] `sensor_update.php`, `camera_upload.php`, `api_entry.php`, `api_exit.php` ไม่มีระบบยืนยันตัวตนอุปกรณ์ — ใครเข้าถึงเครือข่ายได้ก็ส่งข้อมูลปลอมได้ แนะนำเพิ่ม API key ต่ออุปกรณ์ก่อนเปิดนอก LAN
- [ ] รูปสลิป/รูปรถเป็นข้อมูลส่วนบุคคล (PDPA) — อย่าใส่ใน repo
