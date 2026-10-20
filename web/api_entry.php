<?php
// api_entry.php - บันทึกรถเข้าแบบ manual (ใช้ตรรกะเดียวกับ sensor_update.php)
require_once "db.php";
sp_json_api();

// รับข้อมูลได้ทั้ง JSON body และ form (ถ้า decode JSON ไม่ได้จะใช้ $_POST)
// ฟิลด์: slot_number (จำเป็น), user_id, license_plate (ไม่บังคับ)
$d = json_decode(file_get_contents("php://input"), true);
if (!is_array($d)) $d = $_POST;

$sn    = (int)($d['slot_number'] ?? 0);
$uid   = isset($d['user_id']) && $d['user_id'] !== '' ? (int)$d['user_id'] : null;
$plate = trim((string)($d['license_plate'] ?? ''));

if ($sn <= 0) sp_json(['status' => 'error', 'message' => 'Missing slot_number'], 400);

$st = $conn->prepare("SELECT id,status FROM parking_slots WHERE slot_number=? LIMIT 1");
$st->bind_param("i", $sn);
$st->execute();
$slot = $st->get_result()->fetch_assoc();
$st->close();
if (!$slot) sp_json(['status' => 'error', 'message' => 'Slot not found'], 404);

// ใช้ sp_handle_entry() ตัวเดียวกับ sensor_update.php เพื่อให้ผลลัพธ์เหมือนกันทุกช่องทาง
$conn->begin_transaction();
$sid = sp_handle_entry($conn, (int)$slot['id'], $uid, $plate);
$conn->commit();

sp_json(['status' => 'success', 'session_id' => $sid, 'entry_time' => date('Y-m-d H:i:s')]);
