# วิธีร่วมพัฒนา (Contributing)

1. กด **Fork** repo นี้ แล้ว `git clone` ของตัวเอง
2. สร้าง branch: `git checkout -b feature/ชื่อฟีเจอร์`
3. รันระบบทดสอบในเครื่องตัวเองด้วย `docker compose up` (ดู README) — **ใช้ฐานข้อมูลของตัวเอง ไม่ใช่ของเจ้าของ repo**
4. แก้โค้ด แล้ว `git commit` / `git push`
5. เปิด **Pull Request** อธิบายว่าแก้อะไรและทดสอบอย่างไร

## กติกา
- ห้าม commit ข้อมูลจริง: รหัสผ่าน, รหัส WiFi, เบอร์โทร, สลิปโอนเงิน, รูปจากกล้อง, dump ฐานข้อมูลจริง
- แก้โครงสร้างฐานข้อมูล → แก้ `database/schema.sql` และเพิ่มไฟล์ migration ใน `database/migrations/` (เช่น `2026-11-01_add_column.sql`)
- ใช้ prepared statement (`$conn->prepare`) เสมอ ห้ามต่อ string เข้า SQL
- เจ้าของ repo เป็นผู้ merge เท่านั้น; ผู้ร่วมพัฒนา **ไม่ได้เข้าถึงเซิร์ฟเวอร์/ฐานข้อมูลจริง**
