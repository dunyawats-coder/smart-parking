-- ข้อมูลตัวอย่างสำหรับทดลอง (ไม่ใช่ข้อมูลจริง)  นำเข้าหลัง schema.sql
-- บัญชีทดลอง: admin / admin1234   และ demo / demo1234  -> ต้องเปลี่ยนรหัสผ่านทันทีถ้าเปิดให้คนอื่นเข้าถึงจากอินเทอร์เน็ต
USE `smartparking`;
INSERT INTO `parking_slots` (`id`,`slot_number`,`status`,`distance_cm`,`camera_id`) VALUES
(1,1,'available',NULL,'CAM1'),(2,2,'available',NULL,'CAM2'),(3,3,'available',NULL,'CAM3'),(4,4,'available',NULL,NULL);
INSERT INTO `users` (`username`,`password`,`password_hash`,`full_name`,`phone`,`status`,`role`) VALUES
('admin','','$2b$10$I8u/Q1pC5lUt9CWCRLpoyOH71GCeJUQcB5xjdtMpWAQFMPNCnitAu','Demo Admin',NULL,'active','admin'),
('demo','','$2b$10$dR/7zkTHXmD9uhyFWRhYFOKNHvOJoXUZNQg8SL0ikmWibVGwoaJmS','Demo User',NULL,'active','user');
