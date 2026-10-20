<?php
// get_all_slots_status.php - ช่องจอดพร้อมสถานะการจองที่ยังใช้งานอยู่ (pending / reserved)
require_once "db.php";
sp_json_api();

// LEFT JOIN กับการจองที่ยังใช้งานอยู่ (pending/reserved) เพื่อรู้ว่าช่องไหน 'มีคนจองอยู่' (is_booked)
$result = $conn->query("
    SELECT p.id AS slot_id, p.slot_number, p.status AS slot_status,
           r.id AS reservation_id, LOWER(r.status) AS reservation_status
    FROM parking_slots p
    LEFT JOIN reservations r
           ON r.slot_id = p.id AND LOWER(r.status) IN ('pending','reserved')
    ORDER BY p.id ASC
");

$slots = [];
while ($row = $result->fetch_assoc()) {
    $slots[] = [
        'slot_id'            => (int)$row['slot_id'],
        'slot_number'        => (int)$row['slot_number'],
        'status'             => sp_norm_slot_status($row['slot_status']),
        'is_booked'          => !empty($row['reservation_id']),
        'reservation_id'     => (int)($row['reservation_id'] ?? 0),
        'reservation_status' => $row['reservation_status'],
    ];
}
echo json_encode(['status' => 'success', 'slots' => $slots], JSON_UNESCAPED_UNICODE);
