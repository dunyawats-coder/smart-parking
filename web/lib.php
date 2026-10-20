<?php
// [อธิบาย] lib.php = ศูนย์กลางของระบบ ทุกไฟล์ได้ไฟล์นี้ผ่าน db.php
// แบ่งเป็น 5 ส่วน:
//   1) ค่าคงที่ (ค่าจอง/ค่าจอด/PromptPay/เกณฑ์ระยะเซนเซอร์)  2) helper ทั่วไป (sp_h, sp_json)
//   3) ตรวจสิทธิ์ Admin  4) อัปโหลดสลิป  5) ตรรกะ session จอดรถ: รถเข้า / รถออก / คืนช่อง
// แก้ตัวเลขหรือกฎของระบบที่นี่ที่เดียว แล้วทุกหน้าจะเปลี่ยนตาม
/*
 * lib.php - ค่าคงที่และฟังก์ชันกลางของระบบ Smart Parking
 * ทุกไฟล์ใช้ค่าชุดเดียวกันจากที่นี่ (ผ่าน db.php) เพื่อไม่ให้ตัวเลข/สถานะไม่ตรงกัน
 *
 * สถานะมาตรฐาน
 *   parking_slots.status       : available | reserved | occupied
 *   reservations.status        : pending (รอแอดมินตรวจสลิป) -> reserved (อนุมัติแล้ว)
 *                                -> completed (รถเข้าแล้วออก) | cancelled (ยกเลิก/ปฏิเสธ)
 *   parking_sessions.status    : parking | completed
 *   payments.payment_status    : pending | paid
 *   payment_slips.status       : pending | approved | rejected
 */

// ค่าคงที่ของระบบ
// SP_OCCUPIED_DISTANCE / SP_AVAILABLE_DISTANCE ต้องเท่ากับ OCCUPIED_DISTANCE / AVAILABLE_DISTANCE ในสเก็ตช์ ESP32
// เกณฑ์เดียว: ต่ำกว่า 25 cm = มีรถ / ตั้งแต่ 25 cm ขึ้นไป = ว่าง (ไม่มีช่วงกันแกว่ง; ความนิ่งทำที่ฝั่ง ESP32 ด้วยการยืนยันหลายรอบ)
const SP_BOOKING_FEE        = 20.00;        // ค่าจอง (บาท)
const SP_PARKING_RATE       = 1.00;         // ค่าจอด (บาท/นาที)
define('SP_PROMPTPAY_ID', sp_cfg('promptpay_id', 'SP_PROMPTPAY_ID', '0000000000')); // ตั้งใน config.php
define('SP_ADMIN_KEY', sp_cfg('admin_key', 'SP_ADMIN_KEY', bin2hex(random_bytes(16)))); // ไม่ตั้ง = สุ่มทุกครั้ง (สมัคร admin ไม่ได้)
const SP_OCCUPIED_DISTANCE  = 25;           // cm : ต่ำกว่าค่านี้ = มีรถ
const SP_AVAILABLE_DISTANCE = 25;           // cm : ตั้งแต่ค่านี้ขึ้นไป = ไม่มีรถ
const SP_MAX_SLIP_BYTES     = 5242880;      // 5 MB
// true  = กดจอง (ส่งสลิป) แล้วช่องเป็น reserved ทันที -> ไฟ LED ฟ้าติดเลย (แอดมินตรวจสลิปทีหลัง ถ้าปฏิเสธช่องจะกลับเป็นว่าง)
// false = ช่องยังว่าง (ไฟเขียว) จนกว่าแอดมินจะอนุมัติสลิป
const SP_RESERVE_ON_SUBMIT  = true;

// ป้องกัน XSS: แปลงอักขระพิเศษของ HTML ก่อนแสดงค่าที่มาจากผู้ใช้/ฐานข้อมูล
function sp_h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* ทำให้สถานะช่องจอดเป็นรูปแบบเดียวกัน (รองรับค่าเก่า EMPTY) */
function sp_norm_slot_status($s) {
    $s = strtolower(trim((string)$s));
    if ($s === '' || $s === 'empty') return 'available';
    return $s;
}

/* ---------- JSON API helper ---------- */
// เรียกที่ต้นไฟล์ API: ตั้ง Content-Type เป็น JSON และดัก exception ที่ไม่ได้จับ
// ให้ตอบ JSON {status:'error'} พร้อมรหัส 500 แทนหน้า error ของ PHP
function sp_json_api() {
    header('Content-Type: application/json; charset=utf-8');
    set_exception_handler(function ($e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดของระบบ: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    });
}

// ตอบ JSON พร้อมรหัส HTTP แล้วจบการทำงานทันที (exit) — ใช้เป็น return ของ API
function sp_json($arr, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- สิทธิ์ Admin (ใช้ตัวเดียวกันทุกหน้า) ---------- */
function sp_is_admin(mysqli $conn) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    // role ที่ถือว่าเป็นแอดมิน: ตรวจจาก $_SESSION['role'] ก่อน (เร็ว)
    $admins = ['admin', 'administrator', 'superadmin'];
    $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
    if (in_array($role, $admins, true)) return true;

    // ถ้าใน session ไม่มี role ที่เป็นแอดมิน ให้ตรวจซ้ำจากตาราง users
    // (กรณีมีการเปลี่ยน role ในฐานข้อมูลหลังผู้ใช้ล็อกอินแล้ว) แล้วเก็บผลกลับเข้า session
    if (isset($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $st = $conn->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
        $st->bind_param("i", $uid);
        $st->execute();
        $u = $st->get_result()->fetch_assoc();
        $st->close();
        if ($u && in_array(strtolower(trim((string)($u['role'] ?? ''))), $admins, true)) {
            $_SESSION['role'] = 'admin';
            return true;
        }
    }
    return false;
}

// ใช้กับ 'หน้าเว็บ': ไม่ใช่แอดมินให้เด้งไป login.php
function sp_require_admin_page(mysqli $conn) {
    if (!sp_is_admin($conn)) {
        header('Location: login.php');
        exit;
    }
}

// ใช้กับ 'API': ไม่ใช่แอดมินตอบ JSON 403 (ไม่ redirect เพราะผู้เรียกคือ JavaScript)
function sp_require_admin_json(mysqli $conn) {
    if (!sp_is_admin($conn)) {
        sp_json(['status' => 'error', 'message' => 'ไม่มีสิทธิ์: กรุณาเข้าสู่ระบบด้วยบัญชี Admin'], 403);
    }
}

/* ---------- อัปโหลดสลิป (ใช้ร่วมกันทั้งสลิปค่าจองและค่าจอด) ---------- */
// รับไฟล์สลิปจาก $_FILES แล้วบันทึกลงโฟลเดอร์ uploads/ — ใช้ร่วมกันทั้งสลิปค่าจองและค่าจอด
// คืนค่า [true, ชื่อไฟล์ที่บันทึก] เมื่อสำเร็จ หรือ [false, ข้อความ error] เมื่อไม่ผ่าน
function sp_save_slip(array $file, $prefix) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return [false, 'กรุณาแนบไฟล์สลิป'];
    }
    if ($file['size'] > SP_MAX_SLIP_BYTES) {
        return [false, 'ไฟล์สลิปต้องมีขนาดไม่เกิน 5 MB'];
    }
    // ตรวจชนิดไฟล์จาก 'เนื้อไฟล์จริง' ไม่เชื่อนามสกุลหรือค่าที่เบราว์เซอร์ส่งมา
    // กันการอัปโหลดสคริปต์ปลอมเป็นรูปภาพ (getimagesize ช่วยยืนยันอีกชั้น)
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
        return [false, 'รองรับเฉพาะรูปภาพ JPG, PNG และ WEBP'];
    }
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return [false, 'ไม่สามารถสร้างโฟลเดอร์ uploads'];
    }
    // ตั้งชื่อไฟล์ใหม่ = prefix + วันเวลา + เลขสุ่ม ไม่ใช้ชื่อเดิมของผู้ใช้ (กันชื่อซ้ำ/เดาชื่อ/แทรกอักขระอันตราย)
    $filename = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
        return [false, 'ไม่สามารถบันทึกไฟล์สลิปได้'];
    }
    return [true, $filename];
}

/* ---------- Parking session (ใช้ร่วมกัน: sensor_update / api_entry / api_exit) ---------- */

/* รถเข้า: สร้าง session (ถ้ายังไม่มี) ผูกกับการจองที่อนุมัติแล้วของช่องนี้ และตั้งช่องเป็น occupied */
function sp_handle_entry(mysqli $conn, $slotId, $userId = null, $plate = null) {
    $slotId = (int)$slotId;
    $now = date('Y-m-d H:i:s');

    // ขั้น 1: กัน session ซ้ำ — ถ้าช่องนี้มี session ที่ยัง 'parking' อยู่แล้วให้ใช้อันเดิม
    $st = $conn->prepare("SELECT id FROM parking_sessions WHERE slot_id=? AND status='parking' ORDER BY id DESC LIMIT 1");
    $st->bind_param("i", $slotId);
    $st->execute();
    $existing = $st->get_result()->fetch_assoc();
    $st->close();

    if ($existing) {
        $sessionId = (int)$existing['id'];
    } else {
        // ขั้น 2: ยังไม่มี session → หาการจองที่แอดมินอนุมัติแล้ว (reserved) ของช่องนี้ เพื่อเอา user_id ไปผูกกับ session
        // ถ้ารถไม่ได้จอง ($res ว่าง) user_id จะเป็น NULL แล้วให้ผู้ใช้กด 'รับรายการจอด' (claim_parking.php) ภายหลัง
        /* reservations table does not contain license_plate/reservation_id in this DB.
           Use the latest approved reservation only to obtain user_id. */
        $st = $conn->prepare("SELECT id,user_id FROM reservations
                              WHERE slot_id=? AND LOWER(status)='reserved'
                              ORDER BY id DESC LIMIT 1");
        $st->bind_param("i", $slotId);
        $st->execute();
        $res = $st->get_result()->fetch_assoc();
        $st->close();

        if ($res) {
            if ($userId === null) $userId = (int)$res['user_id'];
        }

        $plate = ($plate === null || $plate === '') ? null : $plate;
        // ขั้น 3: สร้าง session ใหม่ เวลาเข้า = ตอนนี้ ค่าจอด 0 สถานะ parking (ค่าจอดจะถูกคิดตอนรถออก)
        $st = $conn->prepare("INSERT INTO parking_sessions
            (user_id,slot_id,vehicle_plate,checkin_time,duration_minutes,fee,status)
            VALUES (?,?,?,?,0,0,'parking')");
        $st->bind_param("iiss", $userId, $slotId, $plate, $now);
        $st->execute();
        $sessionId = (int)$st->insert_id;
        $st->close();
    }

    // ขั้น 4: ตั้งช่องเป็น occupied (ทำทุกครั้ง แม้ใช้ session เดิม เพื่อให้สถานะช่องสอดคล้องกับ session)
    $st = $conn->prepare("UPDATE parking_slots SET status='occupied' WHERE id=?");
    $st->bind_param("i", $slotId);
    $st->execute();
    $st->close();

    return $sessionId;
}

/* รถออก: ปิด session คิดค่าจอดตามนาที จบการจองล่าสุดของผู้ใช้ และคืนช่องเป็น available */
function sp_finish_session(mysqli $conn, $slotId) {
    $slotId = (int)$slotId;
    $result = null;

    $st = $conn->prepare("SELECT id,user_id,checkin_time,vehicle_plate FROM parking_sessions
                          WHERE slot_id=? AND status='parking' ORDER BY id DESC LIMIT 1");
    $st->bind_param("i", $slotId);
    $st->execute();
    $s = $st->get_result()->fetch_assoc();
    $st->close();

    if ($s) {
        $now = date('Y-m-d H:i:s');
        // คิดนาทีแบบปัดขึ้น (ceil) ขั้นต่ำ 1 นาที ค่าจอด = นาที x SP_PARKING_RATE
        // ตัวอย่าง: จอด 10 นาที 5 วินาที = 11 นาที = 11 บาท
        $minutes = max(1, (int)ceil((strtotime($now) - strtotime($s['checkin_time'])) / 60));
        $fee = round($minutes * SP_PARKING_RATE, 2);

        // ปิด session: บันทึกเวลาออก/จำนวนนาที/ค่าจอด และเปลี่ยนสถานะเป็น completed
        $st = $conn->prepare("UPDATE parking_sessions
                              SET checkout_time=?, duration_minutes=?, fee=?, status='completed' WHERE id=?");
        $st->bind_param("sidi", $now, $minutes, $fee, $s['id']);
        $st->execute();
        $st->close();

        /* This DB has no reservation_id in parking_sessions.
           Complete the latest reservation for this slot/user instead. */
        // ปิดการจอง (reserved -> completed): ถ้ารู้ตัวผู้ใช้ให้จับคู่ด้วย ช่อง+ผู้ใช้ ถ้าไม่รู้ใช้ 'การจองล่าสุดของช่อง'
        // ที่ต้องซ้อน subquery (AS x) เพราะ MySQL ไม่อนุญาตให้ UPDATE ตารางเดียวกับที่กำลัง SELECT ตรง ๆ
        if (!empty($s['user_id'])) {
            $st = $conn->prepare("UPDATE reservations
                                  SET status='completed'
                                  WHERE id=(SELECT rid FROM
                                      (SELECT id AS rid FROM reservations
                                       WHERE slot_id=? AND user_id=? AND LOWER(status)='reserved'
                                       ORDER BY id DESC LIMIT 1) AS x)");
            $st->bind_param("ii", $slotId, $s['user_id']);
            $st->execute();
            $st->close();
        } else {
            $st = $conn->prepare("UPDATE reservations
                                  SET status='completed'
                                  WHERE id=(SELECT rid FROM
                                      (SELECT id AS rid FROM reservations
                                       WHERE slot_id=? AND LOWER(status)='reserved'
                                       ORDER BY id DESC LIMIT 1) AS x)");
            $st->bind_param("i", $slotId);
            $st->execute();
            $st->close();
        }

        $result = [
            'session_id'       => (int)$s['id'],
            'entry_time'       => $s['checkin_time'],
            'exit_time'        => $now,
            'duration_minutes' => $minutes,
            'fee'              => $fee,
        ];
    }

    // รถออกแล้ว: ปกติคืนช่องเป็น available (ไฟเขียว) แต่ถ้ายังมีคนจองช่องนี้ค้างอยู่ ให้เป็น reserved (ไฟฟ้า) ต่อ
    // (แม้ไม่พบ session ค้างอยู่ ช่องก็ต้องไม่ติดสถานะ occupied)
    $keep = SP_RESERVE_ON_SUBMIT ? "'pending','reserved'" : "'reserved'";
    $st = $conn->prepare("UPDATE parking_slots
                          SET status = IF(EXISTS(SELECT 1 FROM reservations
                                                 WHERE slot_id=? AND LOWER(status) IN ($keep)),
                                          'reserved','available')
                          WHERE id=?");
    $st->bind_param("ii", $slotId, $slotId);
    $st->execute();
    $st->close();

    return $result;
}

/* คืนช่องเป็น available เมื่อยกเลิก/ปฏิเสธการจอง ถ้าไม่มีการจองอื่นค้างอยู่และไม่มีรถจอด */
function sp_release_slot_if_free(mysqli $conn, $slotId, $excludeReservationId = 0) {
    $slotId = (int)$slotId;
    $excludeReservationId = (int)$excludeReservationId;

    $st = $conn->prepare("SELECT id FROM reservations
                          WHERE slot_id=? AND id<>? AND LOWER(status) IN ('pending','reserved') LIMIT 1");
    $st->bind_param("ii", $slotId, $excludeReservationId);
    $st->execute();
    $other = $st->get_result()->fetch_assoc();
    $st->close();
    // ถ้ายังมีการจองอื่น (pending/reserved) ของช่องนี้ค้างอยู่ ห้ามคืนช่อง
    // ถ้าไม่มี จึงเปลี่ยนเฉพาะช่องที่เป็น reserved กลับเป็น available (ไม่แตะช่อง occupied เพราะมีรถอยู่จริง)
    if ($other) return false;

    $st = $conn->prepare("UPDATE parking_slots SET status='available'
                          WHERE id=? AND LOWER(status)='reserved'");
    $st->bind_param("i", $slotId);
    $st->execute();
    $st->close();
    return true;
}
