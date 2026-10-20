<?php
// [อธิบาย] API ที่แอดมินใช้อนุมัติ/ปฏิเสธ รับ POST: action + (reservation_id | session_id) + reject_reason
//   approve_booking          : การจอง pending -> reserved, ช่อง available -> reserved
//   reject_booking           : การจอง -> cancelled บันทึกเหตุผล แล้วคืนช่อง (ห้ามถ้ารถเข้าจอดแล้ว)
//   approve_service_payment  : สลิปค่าจอด -> approved, payments -> paid
//   reject_service_payment   : สลิปค่าจอด -> rejected ให้ผู้ใช้ส่งสลิปใหม่
// ทุก action ทำในทรานแซกชันเดียว ถ้าผิดเงื่อนไขจะ rollback และตอบ error
// api_process_approval.php - Admin อนุมัติ/ปฏิเสธ สลิปค่าจอง และสลิปค่าจอด
session_start();
require_once "db.php";
sp_json_api();
sp_require_admin_json($conn);

$action         = $_POST['action'] ?? '';
$reservation_id = (int)($_POST['reservation_id'] ?? 0);
$session_id     = (int)($_POST['session_id'] ?? 0);
$reason         = trim($_POST['reject_reason'] ?? '');

if (!$action) sp_json(['status' => 'error', 'message' => 'ไม่พบ action'], 400);

/* หา payment + สลิปที่รอตรวจของ session (ล็อกแถวไว้) */
function pending_slip(mysqli $conn, int $session_id): array {
    $st = $conn->prepare("SELECT pay.id AS payment_id, pay.payment_status, ps.status AS session_status
                          FROM payments pay JOIN parking_sessions ps ON ps.id = pay.session_id
                          WHERE ps.id = ? ORDER BY pay.id DESC LIMIT 1 FOR UPDATE");
    $st->bind_param("i", $session_id);
    $st->execute();
    $pay = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$pay) throw new Exception('ไม่พบรายการ Payment');

    $st = $conn->prepare("SELECT id FROM payment_slips WHERE payment_id = ? AND LOWER(status) = 'pending'
                          ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $st->bind_param("i", $pay['payment_id']);
    $st->execute();
    $slip = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$slip) throw new Exception('ไม่พบสลิปที่รอตรวจสอบ');

    return [$pay, $slip];
}

// เริ่มทรานแซกชัน: หลายตารางต้องเปลี่ยนพร้อมกันหรือไม่เปลี่ยนเลย
$conn->begin_transaction();

try {
    if ($action === 'approve_booking') {
        // อนุมัติค่าจอง: ล็อกแถวการจอง ตรวจว่ายัง pending และช่องยัง available แล้วจึงเปลี่ยนเป็น reserved ทั้งคู่
        if ($reservation_id <= 0) throw new Exception('ไม่พบรหัสการจอง');

        $st = $conn->prepare("SELECT r.id, r.slot_id, r.status, p.status AS slot_status
                              FROM reservations r JOIN parking_slots p ON p.id = r.slot_id
                              WHERE r.id = ? FOR UPDATE");
        $st->bind_param("i", $reservation_id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$r) throw new Exception('ไม่พบรายการจอง');
        if (strtolower($r['status']) !== 'pending') throw new Exception('รายการนี้ไม่ได้อยู่ในสถานะรออนุมัติ');
        // ช่องต้องเป็น available (โหมดเดิม) หรือ reserved ที่เกิดจากการกดจองนี้ (โหมด SP_RESERVE_ON_SUBMIT)
        // ถ้ารถเข้าจอดไปแล้ว (occupied) ยังอนุมัติได้ แต่จะไม่แตะสถานะช่อง
        $slotNow = sp_norm_slot_status($r['slot_status']);
        if (!in_array($slotNow, ['available', 'reserved', 'occupied'], true)) throw new Exception('ช่องจอดไม่ว่างแล้ว');
        if ($slotNow === 'reserved' && !SP_RESERVE_ON_SUBMIT) throw new Exception('ช่องจอดไม่ว่างแล้ว');

        $st = $conn->prepare("UPDATE reservations SET status='reserved', approved_at=NOW(), reject_reason=NULL WHERE id=?");
        $st->bind_param("i", $reservation_id);
        $st->execute();
        $st->close();

        if ($slotNow !== 'occupied') {   // รถเข้าจอดไปแล้ว: ไม่แตะสถานะช่อง
            $st = $conn->prepare("UPDATE parking_slots SET status='reserved' WHERE id=?");
            $st->bind_param("i", $r['slot_id']);
            $st->execute();
            $st->close();
        }

        $msg = 'อนุมัติการจองแล้ว';

    } elseif ($action === 'reject_booking') {
        // ปฏิเสธค่าจอง: ต้องมีเหตุผล และห้ามปฏิเสธถ้ามี session 'parking' ในช่องนั้น (รถเข้าจอดแล้ว)
        if ($reservation_id <= 0) throw new Exception('ไม่พบรหัสการจอง');
        if ($reason === '') throw new Exception('กรุณาระบุเหตุผล');

        $st = $conn->prepare("SELECT slot_id, status FROM reservations WHERE id = ? FOR UPDATE");
        $st->bind_param("i", $reservation_id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$r) throw new Exception('ไม่พบรายการจอง');
        if (!in_array(strtolower($r['status']), ['pending', 'reserved'], true)) throw new Exception('ไม่สามารถปฏิเสธรายการนี้ได้');

        $st = $conn->prepare("SELECT id FROM parking_sessions WHERE slot_id = (SELECT slot_id FROM reservations WHERE id=?) AND status = 'parking' LIMIT 1");
        $st->bind_param("i", $reservation_id);
        $st->execute();
        $busy = $st->get_result()->fetch_assoc();
        $st->close();
        if ($busy) throw new Exception('รถเข้าจอดตามการจองนี้แล้ว ไม่สามารถปฏิเสธได้');

        $st = $conn->prepare("UPDATE reservations SET status='cancelled', reject_reason=? WHERE id=?");
        $st->bind_param("si", $reason, $reservation_id);
        $st->execute();
        $st->close();

        sp_release_slot_if_free($conn, (int)$r['slot_id'], $reservation_id);
        $msg = 'ปฏิเสธการจองแล้ว';

    } elseif ($action === 'approve_service_payment') {
        // อนุมัติค่าจอด: ต้องเป็น session ที่ completed และมีสลิป pending → สลิป approved, payments paid, payment_qr paid
        if ($session_id <= 0) throw new Exception('ไม่พบรหัส session');
        [$pay, $slip] = pending_slip($conn, $session_id);

        if ($pay['session_status'] !== 'completed') throw new Exception('Parking Session ยังไม่เสร็จสิ้น');
        if (strtolower($pay['payment_status']) === 'paid') throw new Exception('รายการนี้ชำระเงินแล้ว');

        $st = $conn->prepare("UPDATE payment_slips SET status='approved' WHERE id=?");
        $st->bind_param("i", $slip['id']);
        $st->execute();
        $st->close();

        $st = $conn->prepare("UPDATE payments SET payment_status='paid', paid_at=NOW() WHERE id=?");
        $st->bind_param("i", $pay['payment_id']);
        $st->execute();
        $st->close();

        $st = $conn->prepare("UPDATE payment_qr SET qr_status='paid', paid_at=NOW() WHERE payment_id=?");
        $st->bind_param("i", $pay['payment_id']);
        $st->execute();
        $st->close();

        $msg = 'อนุมัติการชำระค่าจอดแล้ว';

    } elseif ($action === 'reject_service_payment') {
        // ปฏิเสธสลิปค่าจอด: สลิป rejected, payments กลับเป็น pending ผู้ใช้อัปโหลดสลิปใหม่ได้
        // (เหตุผลถูกรับมาแต่ไม่ได้เก็บลง DB เพราะตาราง payment_slips ไม่มีคอลัมน์ reject_reason)
        if ($session_id <= 0) throw new Exception('ไม่พบรหัส session');
        if ($reason === '') throw new Exception('กรุณาระบุเหตุผล');
        [$pay, $slip] = pending_slip($conn, $session_id);

        // เก็บเหตุผลไว้ที่สลิป เพื่อให้ผู้ใช้เห็นในหน้าชำระเงิน/Dashboard
        $st = $conn->prepare("UPDATE payment_slips SET status='rejected' WHERE id=?");
        $st->bind_param("i", $slip['id']);
        $st->execute();
        $st->close();

        $st = $conn->prepare("UPDATE payments SET payment_status='pending', paid_at=NULL WHERE id=?");
        $st->bind_param("i", $pay['payment_id']);
        $st->execute();
        $st->close();

        $st = $conn->prepare("UPDATE payment_qr SET qr_status='pending', paid_at=NULL WHERE payment_id=?");
        $st->bind_param("i", $pay['payment_id']);
        $st->execute();
        $st->close();

        $msg = 'ปฏิเสธสลิปค่าจอดแล้ว ผู้ใช้สามารถอัปโหลดสลิปใหม่ได้';

    } else {
        throw new Exception('action ไม่ถูกต้อง');
    }

    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => $msg], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
