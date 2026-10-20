<?php

require_once "db.php";

sp_json_api();

/*
====================================================
 Smart Parking - Camera Trigger API
====================================================

CAM1 -> Slot 1
CAM2 -> Slot 2
CAM3 -> Slot 3

หลักการ:

1. รถเข้า
   parking_slots.status = occupied
   parking_sessions.status = parking
   camera_captured = 0
       ↓
   ส่ง capture:true

2. ESP32-CAM ถ่ายรูป + upload สำเร็จ
   camera_captured = 1
       ↓
   รอบต่อไปส่ง capture:false

3. รถยังจอดอยู่
   capture:false ตลอด

4. รถออก
   parking_sessions.status = completed
   parking_slots.status = available

5. รถคันใหม่เข้า
   สร้าง parking_sessions ใหม่
   camera_captured = 0
       ↓
   capture:true อีกครั้ง
*/

// ===============================
// รับ camera_id
// ===============================

$cameraId = isset($_GET['camera_id'])
    ? strtoupper(trim($_GET['camera_id']))
    : '';

if ($cameraId === '') {
    sp_json([
        "status" => "error",
        "message" => "camera_id is required"
    ], 400);
    exit;
}


// ===============================
// กำหนด Camera -> Slot
// ===============================

// แมปกล้อง -> หมายเลขช่อง (CAM1=ช่อง 1 ฯลฯ) ถ้าเพิ่มกล้องต้องเพิ่มทั้งที่นี่และใน camera_upload.php
$cameraMap = [
    "CAM1" => 1,
    "CAM2" => 2,
    "CAM3" => 3
];

if (!isset($cameraMap[$cameraId])) {
    sp_json([
        "status" => "error",
        "message" => "Invalid camera_id",
        "camera_id" => $cameraId
    ], 400);
    exit;
}

$slotNumber = $cameraMap[$cameraId];


// ===============================
// หา Slot
// ===============================

$stmt = $conn->prepare("
    SELECT
        id,
        slot_number,
        status
    FROM parking_slots
    WHERE slot_number = ?
    LIMIT 1
");

if (!$stmt) {
    sp_json([
        "status" => "error",
        "message" => "Prepare slot query failed"
    ], 500);
    exit;
}

$stmt->bind_param("i", $slotNumber);
$stmt->execute();

$result = $stmt->get_result();
$slot = $result->fetch_assoc();

$stmt->close();


if (!$slot) {
    sp_json([
        "status" => "error",
        "message" => "Parking slot not found",
        "slot_number" => $slotNumber
    ], 404);
    exit;
}


$slotId = (int)$slot['id'];
$slotStatus = strtolower(trim($slot['status']));


// ===============================
// รถยังไม่เข้า
// ===============================

// ช่องไม่ใช่ occupied = ไม่ต้องถ่ายรูป (ตอบ capture:false)
// หมายเหตุ: ชื่อ event 'vehicle_exit' ในคำตอบเป็นแค่ป้ายบอกสถานะ ระบบนี้ไม่ได้สั่งถ่ายรูปตอนรถออก
// (ต่างจาก README ของ esp32cam ที่เขียนว่าถ่ายตอนรถออกด้วย)
if ($slotStatus !== "occupied") {

    sp_json([
        "status" => "success",
        "capture" => false,
        "event" => "vehicle_exit",
        "camera_id" => $cameraId,
        "slot_id" => $slotId,
        "slot_number" => $slotNumber,
        "slot_status" => $slotStatus
    ]);

    exit;
}


// ===============================
// Slot = occupied
// หา Parking Session ปัจจุบัน
// ===============================

$stmt = $conn->prepare("
    SELECT
        id,
        user_id,
        slot_id,
        vehicle_plate,
        checkin_time,
        camera_captured
    FROM parking_sessions
    WHERE slot_id = ?
      AND status = 'parking'
    ORDER BY id DESC
    LIMIT 1
");

if (!$stmt) {
    sp_json([
        "status" => "error",
        "message" => "Prepare parking session query failed"
    ], 500);
    exit;
}

$stmt->bind_param("i", $slotId);
$stmt->execute();

$result = $stmt->get_result();
$session = $result->fetch_assoc();

$stmt->close();


// ===============================
// ไม่พบ Session
// ===============================

if (!$session) {

    /*
       ป้องกันการถ่ายรูปมั่ว
       ถ้า Slot occupied แต่ยังไม่มี
       parking_session ก็ยังไม่สั่งกล้อง
    */

    sp_json([
        "status" => "success",
        "capture" => false,
        "event" => "occupied_without_session",
        "camera_id" => $cameraId,
        "slot_id" => $slotId,
        "slot_number" => $slotNumber,
        "slot_status" => $slotStatus
    ]);

    exit;
}


// ===============================
// ตรวจสอบว่าถ่ายไปแล้วหรือยัง
// ===============================

$sessionId = (int)$session['id'];
// camera_captured = 1 แปลว่าถ่ายรูปของรอบการจอดนี้ไปแล้ว → ไม่ถ่ายซ้ำ (รถ 1 คัน = 1 รูป)
// ต้องมีคอลัมน์ parking_sessions.camera_captured ในฐานข้อมูล (database.sql ไม่มี ต้อง ALTER TABLE เพิ่มเอง)
$cameraCaptured = (int)$session['camera_captured'];


// ===============================
// ถ่ายไปแล้ว
// ===============================

if ($cameraCaptured === 1) {

    sp_json([
        "status" => "success",
        "capture" => false,
        "event" => "vehicle_still_parked",
        "camera_id" => $cameraId,
        "slot_id" => $slotId,
        "slot_number" => $slotNumber,
        "slot_status" => $slotStatus,
        "session_id" => $sessionId,
        "camera_captured" => true
    ]);

    exit;
}


// ===============================
// ยังไม่เคยถ่าย
// สั่ง ESP32-CAM ถ่าย
// ===============================

sp_json([
    "status" => "success",
    "capture" => true,
    "event" => "vehicle_enter",
    "camera_id" => $cameraId,
    "slot_id" => $slotId,
    "slot_number" => $slotNumber,
    "slot_status" => $slotStatus,
    "session_id" => $sessionId,
    "camera_captured" => false
]);

exit;
?>