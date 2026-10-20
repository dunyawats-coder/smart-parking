<?php
// [อธิบาย] ไฟล์เก่าที่ถูกปิดการใช้งาน: redirect ไป reserve.php เพื่อให้ทุกการจองผ่านขั้นตรวจสลิปของแอดมินเสมอ
// payment.php (ระบบเดิม) ถูกยกเลิกแล้ว
// ของเดิมสร้างการจองสถานะ 'reserved' ทันทีโดยไม่ผ่านการตรวจสลิป ซึ่งขัดกับขั้นตอนอนุมัติของ Admin
// ขั้นตอนปัจจุบัน: reserve.php -> payment_booking.php (ค่าจอง + สลิป) -> Admin อนุมัติ -> รถเข้า -> payment_parking.php (ค่าจอด)
header("Location: reserve.php");
exit;
