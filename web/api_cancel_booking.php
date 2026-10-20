<?php
// api_cancel_booking.php - Admin ยกเลิกการจอง (จากหน้า admin_dashboard.php)
session_start();
require_once "db.php";
sp_json_api();
sp_require_admin_json($conn);

$reservation_id = (int)($_POST['reservation_id'] ?? 0);
if ($reservation_id <= 0) sp_json(['status' => 'error', 'message' => 'ไม่พบรหัสการจอง'], 400);

$conn->begin_transaction();
try {
    $st = $conn->prepare("SELECT r.id, r.slot_id, LOWER(COALESCE(r.status,'')) AS reservation_status,
                                 LOWER(COALESCE(p.status,'')) AS slot_status, p.slot_number
                          FROM reservations r JOIN parking_slots p ON p.id = r.slot_id
                          WHERE r.id = ? FOR UPDATE");
    $st->bind_param("i", $reservation_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) throw new Exception('ไม่พบรายการจอง');
    if (in_array($row['reservation_status'], ['cancelled', 'canceled', 'completed'], true)) {
        throw new Exception('รายการจองนี้ถูกยกเลิกหรือเสร็จสิ้นไปแล้ว');
    }
    // รถเข้าจอดแล้ว ห้ามยกเลิกการจอง
    if ($row['slot_status'] === 'occupied') {
        throw new Exception('ช่องจอดนี้มีรถเข้าจอดแล้ว ไม่สามารถยกเลิกการจองจากหน้านี้ได้');
    }

    $st = $conn->prepare("UPDATE reservations SET status='cancelled', reject_reason='ยกเลิกโดยผู้ดูแลระบบ' WHERE id=?");
    $st->bind_param("i", $reservation_id);
    $st->execute();
    $st->close();

    // คืนช่องเป็น available ถ้าไม่มีการจองอื่นค้างอยู่
    sp_release_slot_if_free($conn, (int)$row['slot_id'], $reservation_id);

    $conn->commit();
    sp_json([
        'status'         => 'success',
        'message'        => 'ยกเลิกการจองช่อง ' . $row['slot_number'] . ' เรียบร้อยแล้ว',
        'reservation_id' => $reservation_id,
        'slot_id'        => (int)$row['slot_id'],
        'slot_number'    => $row['slot_number'],
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    sp_json(['status' => 'error', 'message' => $e->getMessage()], 400);
}
