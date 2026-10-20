<?php
// [อธิบาย] ออกจากระบบ: ล้าง session ทั้งหมดแล้วกลับหน้า login
// logout.php
session_start();
session_destroy();
header("Location: login.php");
exit();
?>