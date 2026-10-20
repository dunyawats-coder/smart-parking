<?php
// [อธิบาย] ทางเข้าฝั่งแอดมิน: ตรวจสิทธิ์ (ไม่ใช่แอดมินเด้งไป login) แล้วส่งต่อไป admin_dashboard.php
session_start();
require_once "db.php";
sp_require_admin_page($conn);
header("Location: admin_dashboard.php");
exit;
