<?php

require_once "db.php";

sp_json_api();

/*
====================================================
 Smart Parking - Camera Upload API
====================================================

ESP32-CAM
    |
    | POST multipart/form-data
    |
    +-- camera_id
    +-- slot_id
    +-- image
          |
          v
camera_upload.php
          |
          +-- บันทึกรูป
          |
          +-- บันทึก camera_logs
          |
          +-- UPDATE parking_sessions
              SET camera_captured = 1

หลักการ:
- รถ 1 คัน = รูป 1 รูป
- Upload สำเร็จ = camera_captured = 1
- รถยังจอดอยู่ = ไม่ถ่ายซ้ำ
- รถออก + รถคันใหม่เข้า = session ใหม่ และ camera_captured = 0
*/


// ==================================================
// 1. รับค่า Camera ID
// ==================================================

$cameraId = isset($_POST['camera_id'])
    ? strtoupper(trim($_POST['camera_id']))
    : '';

if ($cameraId === '') {
    sp_json([
        "status" => "error",
        "message" => "camera_id is required"
    ], 400);
    exit;
}


// ==================================================
// 2. Camera -> Slot
// ==================================================

// แมปกล้อง -> หมายเลขช่อง (ต้องตรงกับ camera_trigger.php)
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


// ==================================================
// 3. รับ slot_id
// ==================================================

$slotId = isset($_POST['slot_id'])
    ? (int)$_POST['slot_id']
    : 0;


// ==================================================
// 4. ถ้า ESP32-CAM ไม่ส่ง slot_id
//    ให้ค้นหาจาก slot_number
// ==================================================

if ($slotId <= 0) {

    $stmt = $conn->prepare("
        SELECT id
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
            "message" => "Parking slot not found"
        ], 404);
        exit;
    }

    $slotId = (int)$slot['id'];
}


// ==================================================
// 5. ตรวจสอบไฟล์รูป
// ==================================================

if (!isset($_FILES['image'])) {

    sp_json([
        "status" => "error",
        "message" => "Image file is required"
    ], 400);

    exit;
}


$file = $_FILES['image'];


// ==================================================
// 6. ตรวจสอบ Upload Error
// ==================================================

if ($file['error'] !== UPLOAD_ERR_OK) {

    sp_json([
        "status" => "error",
        "message" => "Image upload failed",
        "upload_error" => $file['error']
    ], 400);

    exit;
}


// ==================================================
// 7. ตรวจสอบขนาดไฟล์
// ==================================================

$maxFileSize = 5 * 1024 * 1024; // 5 MB

if ($file['size'] <= 0) {

    sp_json([
        "status" => "error",
        "message" => "Empty image file"
    ], 400);

    exit;
}

if ($file['size'] > $maxFileSize) {

    sp_json([
        "status" => "error",
        "message" => "Image file is too large"
    ], 400);

    exit;
}


// ==================================================
// 8. ตรวจสอบ MIME Type
// ==================================================

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);


$allowedTypes = [
    "image/jpeg" => "jpg",
    "image/jpg"  => "jpg",
    "image/png"  => "png"
];

if (!isset($allowedTypes[$mimeType])) {

    sp_json([
        "status" => "error",
        "message" => "Invalid image type",
        "mime_type" => $mimeType
    ], 400);

    exit;
}


$extension = $allowedTypes[$mimeType];


// ==================================================
// 9. สร้างโฟลเดอร์เก็บรูป
// ==================================================

// โฟลเดอร์เก็บรูปบนเครื่องเซิร์ฟเวอร์ (อยู่นอก htdocs จึงเปิดดูผ่านเว็บตรง ๆ ไม่ได้)
// แก้ path ให้ตรงเครื่องจริง และ Apache ต้องมีสิทธิ์เขียน; ภายในแยกโฟลเดอร์ตามวันที่ (YYYY-MM-DD)
$baseDir = sp_cfg('camera_dir', 'SP_CAMERA_DIR', __DIR__ . '/camera_images'); // ตั้งใน config.php ได้ (ควรอยู่นอก web root ถ้าทำได้)


// สร้างโฟลเดอร์หลัก
if (!is_dir($baseDir)) {

    if (!mkdir($baseDir, 0777, true)) {

        sp_json([
            "status" => "error",
            "message" => "Cannot create camera directory"
        ], 500);

        exit;
    }
}


// ==================================================
// 10. สร้างโฟลเดอร์ตามวันที่
// ==================================================

$dateFolder = date("Y-m-d");

$saveDir = $baseDir . "/" . $dateFolder;


if (!is_dir($saveDir)) {

    if (!mkdir($saveDir, 0777, true)) {

        sp_json([
            "status" => "error",
            "message" => "Cannot create date directory"
        ], 500);

        exit;
    }
}


// ==================================================
// 11. สร้างชื่อไฟล์
// ==================================================

$timestamp = date("Ymd_His");

$random = bin2hex(random_bytes(4));

$fileName =
    $cameraId
    . "_SLOT"
    . $slotNumber
    . "_"
    . $timestamp
    . "_"
    . $random
    . "."
    . $extension;


$filePath = $saveDir . "/" . $fileName;


// ==================================================
// 12. ย้ายไฟล์รูป
// ==================================================

if (!move_uploaded_file($file['tmp_name'], $filePath)) {

    sp_json([
        "status" => "error",
        "message" => "Cannot save image"
    ], 500);

    exit;
}


// ==================================================
// 13. หา Parking Session ปัจจุบัน
// ==================================================

$stmt = $conn->prepare("
    SELECT
        id,
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


// ==================================================
// 14. ถ้ามี Session
// ==================================================

$sessionId = null;

if ($session) {

    $sessionId = (int)$session['id'];

    /*
       สำคัญมาก

       หลังจากรูปถูกบันทึกสำเร็จ
       ให้กำหนด camera_captured = 1

       เพื่อป้องกันการถ่ายซ้ำ
    */

    $stmt = $conn->prepare("
        UPDATE parking_sessions
        SET camera_captured = 1
        WHERE id = ?
          AND status = 'parking'
    ");

    if (!$stmt) {

        sp_json([
            "status" => "error",
            "message" => "Prepare session update failed"
        ], 500);

        exit;
    }

    $stmt->bind_param("i", $sessionId);
    $stmt->execute();

    $stmt->close();
}


// ==================================================
// 15. บันทึก Camera Log
// ==================================================

/*
   camera_logs ที่ใช้:

   slot_id
   camera_id
   event
   image_filename
   image_path
*/

// บันทึกเป็น vehicle_enter เสมอ เพราะระบบสั่งถ่ายเฉพาะตอนรถเข้า (ดู camera_trigger.php)
$event = "vehicle_enter";

$imageRelativePath =
    "Camera/"
    . $dateFolder
    . "/"
    . $fileName;


// ตรวจสอบว่า table camera_logs มีอยู่
$checkTable = $conn->query("SHOW TABLES LIKE 'camera_logs'");

if ($checkTable && $checkTable->num_rows > 0) {

    $stmt = $conn->prepare("
        INSERT INTO camera_logs
        (
            slot_id,
            camera_id,
            event,
            image_filename,
            image_path
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ");

    if ($stmt) {

        $stmt->bind_param(
            "issss",
            $slotId,
            $cameraId,
            $event,
            $fileName,
            $imageRelativePath
        );

        $stmt->execute();

        $stmt->close();
    }
}


// ==================================================
// 16. ส่งผลกลับ ESP32-CAM
// ==================================================

sp_json([
    "status" => "success",
    "message" => "Image uploaded successfully",

    "camera_id" => $cameraId,

    "slot_id" => $slotId,

    "slot_number" => $slotNumber,

    "session_id" => $sessionId,

    "camera_captured" => true,

    "event" => $event,

    "filename" => $fileName,

    "path" => $imageRelativePath,

    "size" => $file['size'],

    "mime_type" => $mimeType
]);

exit;

?>