<?php
// api_exit.php - บันทึกรถออกแบบ manual (ใช้ตรรกะเดียวกับ sensor_update.php)
require_once "db.php";
sp_json_api();

$d = json_decode(file_get_contents("php://input"), true);
if (!is_array($d)) $d = $_POST;
$sn = (int)($d['slot_number'] ?? 0);
if ($sn <= 0) sp_json(['status' => 'error', 'message' => 'Missing slot_number'], 400);

// รถออกได้เฉพาะช่องที่เป็น occupied เท่านั้น
$st = $conn->prepare("SELECT id FROM parking_slots WHERE slot_number=? AND LOWER(status)='occupied' LIMIT 1");
$st->bind_param("i", $sn);
$st->execute();
$slot = $st->get_result()->fetch_assoc();
$st->close();
if (!$slot) sp_json(['status' => 'error', 'message' => 'No occupied vehicle found'], 404);

$conn->begin_transaction();
// ปิด session + คิดค่าจอดตามนาที + คืนช่องเป็น available (ฟังก์ชันกลางใน lib.php)
$r = sp_finish_session($conn, (int)$slot['id']);
$conn->commit();

if (!$r) sp_json(['status' => 'error', 'message' => 'No active session'], 404);

sp_json([
    'status'           => 'success',
    'slot_number'      => $sn,
    'session_id'       => $r['session_id'],
    'entry_time'       => $r['entry_time'],
    'exit_time'        => $r['exit_time'],
    'duration_minutes' => $r['duration_minutes'],
    'total_fee'        => $r['fee'],
]);
