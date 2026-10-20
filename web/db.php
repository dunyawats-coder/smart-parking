<?php
// [อธิบาย] ไฟล์เชื่อมต่อฐานข้อมูล: ทุกหน้า/ทุก API เรียก require_once "db.php" เป็นบรรทัดแรก
// สิ่งที่ไฟล์นี้ทำ: (1) ตั้งเขตเวลาไทย (2) เชื่อม MySQL ด้วย mysqli แล้วเก็บไว้ในตัวแปร $conn
// (3) โหลด lib.php เพื่อให้ทุกไฟล์ใช้ค่าคงที่/ฟังก์ชันกลางชุดเดียวกัน
// db.php - การเชื่อมต่อฐานข้อมูล (ทุกหน้า/ทุก API เรียกใช้ไฟล์นี้)
date_default_timezone_set('Asia/Bangkok');

// ค่าเชื่อมต่อฐานข้อมูล: อ่านจาก environment variable (SP_DB_HOST ฯลฯ) ก่อน แล้วค่อยอ่านจาก config.php
// ดู config.example.php (ห้าม commit config.php ที่มีรหัสจริงขึ้น GitHub)
$SP_CFG = file_exists(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
function sp_cfg($key, $env, $default = '') {
    global $SP_CFG;
    $v = getenv($env);
    if ($v !== false && $v !== '') return $v;
    return $SP_CFG[$key] ?? $default;
}
$host   = sp_cfg('db_host', 'SP_DB_HOST', 'localhost');
$user   = sp_cfg('db_user', 'SP_DB_USER', 'smartparking');
$pass   = sp_cfg('db_pass', 'SP_DB_PASS', '');
$dbname = sp_cfg('db_name', 'SP_DB_NAME', 'smartparking');

// ให้พฤติกรรมเหมือนกันทุกเวอร์ชัน PHP: error ของ mysqli จะ throw exception
// ให้ mysqli โยน exception เมื่อ query ผิดพลาด (แทนการคืนค่า false เงียบ ๆ)
// ทำให้ try/catch และ rollback ในทรานแซกชันทำงานได้ถูกต้อง
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $pass, $dbname);
    // utf8mb4 = รองรับภาษาไทย/อีโมจิครบ
    $conn->set_charset("utf8mb4");
    // ให้เวลาใน MySQL (NOW()) ตรงกับเวลาใน PHP (date())
    $conn->query("SET time_zone = '+07:00'");
} catch (Throwable $e) {
    // เชื่อมต่อไม่ได้: ตอบ 500 และไม่แสดงรายละเอียด error ให้ผู้ใช้เห็น (กันข้อมูลระบบรั่ว)
    http_response_code(500);
    die("Connection failed");
}

// โหลดฟังก์ชัน/ค่าคงที่กลาง (sp_json, sp_is_admin, sp_handle_entry ฯลฯ) ให้ทุกไฟล์ที่เรียก db.php
require_once __DIR__ . '/lib.php';
