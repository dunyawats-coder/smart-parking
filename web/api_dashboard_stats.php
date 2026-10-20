<?php
require_once "db.php";
sp_json_api();

// นับจำนวนช่องแต่ละสถานะ (available / reserved / occupied) สำหรับการ์ดสรุป
$out = ['available' => 0, 'reserved' => 0, 'occupied' => 0];
$q = $conn->query("SELECT status FROM parking_slots");
while ($r = $q->fetch_assoc()) {
    $s = sp_norm_slot_status($r['status']);
    if (isset($out[$s])) $out[$s]++;
}
echo json_encode(['status' => 'success', 'data' => $out], JSON_UNESCAPED_UNICODE);
