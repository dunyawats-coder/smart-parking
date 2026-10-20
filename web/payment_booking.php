<?php
// [อธิบาย] ขั้นที่ 2 ของการจอง: ชำระค่าจอง (SP_BOOKING_FEE) ผ่าน PromptPay QR แล้วแนบสลิป
// ทำงาน 2 ขั้นในไฟล์เดียว: (ก) POST จาก reserve.php -> เก็บข้อมูลชั่วคราวใน $_SESSION['temp_booking']
// (ข) POST confirm_payment + ไฟล์สลิป -> บันทึก reservation สถานะ 'pending' แล้วรอแอดมินอนุมัติ
session_start();
require_once "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

/*
 * กันกรณีฐานข้อมูลรุ่นเก่าที่ยังไม่ได้รัน database.sql
 * (แนะนำให้รัน database.sql ก่อนใช้งานจริง)
 */
function ensure_booking_schema(mysqli $conn) {
    $required = [
        "slip_image"    => "VARCHAR(255) NULL",
        "approved_at"   => "DATETIME NULL",
    ];
    $existing = [];
    $result = $conn->query("SHOW COLUMNS FROM reservations");
    while ($row = $result->fetch_assoc()) $existing[strtolower($row["Field"])] = true;
    foreach ($required as $column => $definition) {
        if (!isset($existing[$column])) {
            $conn->query("ALTER TABLE reservations ADD COLUMN `$column` $definition");
        }
    }
}

try {
    ensure_booking_schema($conn);
} catch (Throwable $e) {
    die("ฐานข้อมูลยังไม่พร้อม กรุณารัน database.sql");
}

// ตัวแปรสถานะของหน้า: $booking = ข้อมูลจองชั่วคราวใน session, $message = ข้อความ error ที่จะแสดง
$user_id = (int)$_SESSION['user_id'];
$message = "";
$booking = $_SESSION['temp_booking'] ?? null;

/* ขั้นที่ 1: รับช่อง/วันเวลา/ทะเบียนจาก reserve.php */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['slot_id']) && !isset($_POST['confirm_payment'])) {
    $slot_id      = (int)$_POST['slot_id'];
    $reserve_date = trim($_POST['reserve_date'] ?? '');
    $reserve_time = trim($_POST['reserve_time'] ?? '');
    $plate        = trim($_POST['license_plate'] ?? '');

    if ($slot_id <= 0 || !$reserve_date || !$reserve_time) {
        $message = "กรุณาเลือกช่องจอด วันที่ และเวลาให้ครบ";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reserve_date) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $reserve_time)) {
        $message = "รูปแบบวันที่หรือเวลาไม่ถูกต้อง";
    } else {
        $stmt = $conn->prepare("SELECT id,slot_number,status FROM parking_slots WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $slot_id);
        $stmt->execute();
        $slot = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$slot) {
            $message = "ไม่พบช่องจอด";
        } elseif (sp_norm_slot_status($slot['status']) !== 'available') {
            $message = "ช่องนี้ไม่ว่างแล้ว กรุณาเลือกช่องอื่น";
        } else {
            // เก็บข้อมูลจองไว้ใน session ชั่วคราว (ยังไม่เขียนลง DB) จนกว่าผู้ใช้จะแนบสลิป
            $_SESSION['temp_booking'] = [
                'slot_id'       => $slot_id,
                'slot_number'   => $slot['slot_number'],
                'reserve_date'  => $reserve_date,
                'reserve_time'  => $reserve_time,
                'license_plate' => (preg_match('/^.{0,20}/su', $plate, $pm) ? $pm[0] : ''),  // ตัดที่ 20 ตัวอักษร (รองรับภาษาไทย ไม่ต้องใช้ mbstring)
            ];
            $booking = $_SESSION['temp_booking'];
        }
    }
}

/* ขั้นที่ 2: อัปโหลดสลิป แล้วสร้างการจองสถานะ pending (รอแอดมินอนุมัติ) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $booking = $_SESSION['temp_booking'] ?? null;
    if (!$booking) {
        $message = "ไม่พบข้อมูลการจอง กรุณากลับไปเลือกช่องใหม่";
    } else {
        [$ok, $filename] = sp_save_slip($_FILES['slip_image'] ?? [], 'slip_booking');
        if (!$ok) {
            $message = $filename;
        } else {
            $filepath = __DIR__ . "/uploads/" . $filename;
            // ทรานแซกชัน: ล็อกแถวช่องด้วย FOR UPDATE แล้วตรวจซ้ำ 3 อย่างก่อน INSERT
            //  1) ช่องยัง available  2) ไม่มีคำขอจอง pending/reserved ของช่องนี้  3) ผู้ใช้ไม่มีการจองค้างอยู่ (1 คน : 1 การจอง)
            // ถ้าไม่ผ่าน throw Exception แล้ว rollback + ลบไฟล์สลิปที่เพิ่งอัปโหลด
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT id,status FROM parking_slots WHERE id=? FOR UPDATE");
                $stmt->bind_param("i", $booking['slot_id']);
                $stmt->execute();
                $slot = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$slot) throw new Exception("ไม่พบช่องจอด");
                if (sp_norm_slot_status($slot['status']) !== 'available') {
                    throw new Exception("ช่องนี้ถูกจองไปแล้ว กรุณาเลือกช่องอื่น");
                }

                // ช่องนี้ต้องไม่มีคำขอจองอื่นค้างอยู่
                $stmt = $conn->prepare("SELECT id FROM reservations WHERE slot_id=? AND LOWER(status) IN ('pending','reserved') LIMIT 1");
                $stmt->bind_param("i", $booking['slot_id']);
                $stmt->execute();
                $taken = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($taken) throw new Exception("ช่องนี้มีผู้จองแล้ว กรุณาเลือกช่องอื่น");

                // ผู้ใช้หนึ่งคนมีการจองที่ยังไม่เสร็จได้ครั้งละหนึ่งรายการ
                $stmt = $conn->prepare("SELECT id FROM reservations WHERE user_id=? AND LOWER(status) IN ('pending','reserved') LIMIT 1");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($existing) throw new Exception("คุณมีรายการจองที่ยังไม่เสร็จสิ้นอยู่แล้ว");

                // สร้างการจองเป็น 'pending' — ช่องยังเป็น available จนกว่าแอดมินจะอนุมัติสลิป (กันสลิปปลอมล็อกช่อง)
                // ทะเบียนรถไม่ได้ถูกบันทึกลงตาราง reservations เพราะตารางนี้ไม่มีคอลัมน์ license_plate
                $status = 'pending';
                /* reservations in this DB has no license_plate column. */
                $stmt = $conn->prepare("INSERT INTO reservations
                    (user_id,slot_id,reserve_date,reserve_time,status,slip_image)
                    VALUES (?,?,?,?,?,?)");
                $stmt->bind_param("iissss", $user_id, $booking['slot_id'], $booking['reserve_date'], $booking['reserve_time'], $status, $filename);
                $stmt->execute();
                $stmt->close();

                // กดจองแล้วช่องเป็น reserved ทันที (ไฟ LED ฟ้า) ถ้าแอดมินปฏิเสธสลิปช่องจะกลับเป็น available
                if (SP_RESERVE_ON_SUBMIT) {
                    $stmt = $conn->prepare("UPDATE parking_slots SET status='reserved' WHERE id=?");
                    $stmt->bind_param("i", $booking['slot_id']);
                    $stmt->execute();
                    $stmt->close();
                }
                $conn->commit();
                unset($_SESSION['temp_booking']);
                header("Location: dashboard.php?msg=booking_pending");
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                if (is_file($filepath)) unlink($filepath);
                $message = $e->getMessage();
            }
        }
    }
}
$fee = number_format(SP_BOOKING_FEE, 2);
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ชำระค่าจอง <?=number_format(SP_BOOKING_FEE,0)?> บาท - Smart Parking</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>body{font-family:Kanit,sans-serif}</style>
</head>
<body class="bg-slate-950 text-white min-h-screen flex items-center justify-center p-6">
<div class="w-full max-w-md bg-slate-900 border border-slate-800 rounded-3xl p-8">
<h1 class="text-2xl font-bold">ยืนยันการจอง</h1>
<?php if(!$booking): ?>
<?php if($message): ?><div class="mt-4 p-3 rounded-xl bg-red-500/10 border border-red-500/30 text-red-300"><?=sp_h($message)?></div><?php endif; ?>
<p class="text-slate-400 mt-2">ไม่พบข้อมูลการจอง กรุณากลับไปเลือกช่องจอด</p>
<a href="reserve.php" class="block mt-5 text-center bg-indigo-600 p-3 rounded-xl">กลับไปเลือกช่อง</a>
<?php else: ?>
<p class="text-slate-400 text-sm mt-2">ช่อง #<?=sp_h($booking['slot_number'])?> • <?=sp_h($booking['reserve_date'])?> <?=sp_h($booking['reserve_time'])?><?php if($booking['license_plate']!==''): ?> • <?=sp_h($booking['license_plate'])?><?php endif; ?></p>
<div class="mt-5 p-4 bg-indigo-500/10 border border-indigo-500/20 rounded-2xl">
<div class="text-slate-300">ค่าธรรมเนียมการจอง</div>
<div class="text-3xl font-bold text-indigo-400"><?=$fee?> บาท</div>
<div class="text-xs text-slate-500 mt-1">หลังส่งสลิป ระบบจะขึ้น “รอตรวจสอบ” และแอดมินต้องอนุมัติก่อนช่องจะเป็น “จองแล้ว”</div>
</div>
<div class="bg-white rounded-2xl p-5 mt-5 text-center">
<img src="https://promptpay.io/<?=rawurlencode(SP_PROMPTPAY_ID)?>/<?=rawurlencode(number_format(SP_BOOKING_FEE,2,'.',''))?>.png" class="w-48 h-48 mx-auto" alt="PromptPay">
<div class="text-slate-900 font-bold mt-2">PromptPay <?=$fee?> บาท</div>
</div>
<?php if($message): ?><div class="mt-4 p-3 rounded-xl bg-red-500/10 border border-red-500/30 text-red-300"><?=sp_h($message)?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="mt-5 space-y-4">
<input type="hidden" name="confirm_payment" value="1">
<input type="file" name="slip_image" accept="image/jpeg,image/png,image/webp" required class="w-full bg-slate-950 border border-slate-700 rounded-xl p-3">
<button class="w-full bg-emerald-600 hover:bg-emerald-500 p-3 rounded-xl font-bold">อัปโหลดสลิปและส่งคำขอจอง</button>
</form>
<a href="reserve.php" class="block text-center text-slate-500 mt-5">ยกเลิก</a>
<?php endif; ?>
</div>
</body></html>
