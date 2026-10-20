<?php
// api_slot_status.php - ESP32 ดึงสถานะช่องจอดจากเซิร์ฟเวอร์ (ใช้ชื่อ slot1, slot2, ...)
require_once "db.php";
sp_json_api();

// ESP32 เรียกไฟล์นี้เพื่อรู้สถานะของทุกช่องตามเซิร์ฟเวอร์ (เช่น แอดมินอนุมัติจอง → reserved ไฟจะเปลี่ยนเป็นสีน้ำเงิน)
// คีย์ผลลัพธ์เป็น slot1, slot2, slot3 ตรงกับที่ getServerStatus() ในสเก็ตช์อ่าน
$result = $conn->query("SELECT slot_number, status FROM parking_slots ORDER BY slot_number ASC");

$slots = [];
while ($row = $result->fetch_assoc()) {
    $n = (int)$row['slot_number'];
    $slots["slot" . $n] = [
        "slot_number" => $n,
        "status"      => sp_norm_slot_status($row['status']),
    ];
}

echo json_encode(["status" => "success", "slots" => $slots], JSON_UNESCAPED_UNICODE);
