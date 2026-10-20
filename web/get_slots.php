<?php
// get_slots.php - สถานะช่องจอดแบบสาธารณะ (index.html / dashboard.php)
require_once "db.php";
sp_json_api();

// ไม่ต้องล็อกอิน: ใช้กับ index.html (หน้าสาธารณะ) และ dashboard.php ที่ดึงทุก 2-3 วินาที
// คืนอาร์เรย์ของช่อง {id, slot_number, distance_cm, status}
$result = $conn->query("SELECT id, slot_number, distance_cm, status FROM parking_slots ORDER BY slot_number ASC");

$slots = [];
while ($row = $result->fetch_assoc()) {
    $row['status'] = sp_norm_slot_status($row['status']);
    $slots[] = $row;
}
echo json_encode($slots, JSON_UNESCAPED_UNICODE);
