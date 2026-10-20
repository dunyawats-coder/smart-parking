<?php
// user_cancel_reservation.php - ผู้ใช้ยกเลิกการจองของตัวเอง
session_start();
require_once "db.php";
sp_json_api();

if (!isset($_SESSION['user_id'])) sp_json(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบก่อน'], 401);

$user_id        = (int)$_SESSION['user_id'];
$reservation_id = (int)($_POST['reservation_id'] ?? 0);
if ($reservation_id <= 0) sp_json(['status' => 'error', 'message' => 'ไม่พบรหัสการจอง'], 400);

$conn->begin_transaction();
try {
    $st = $conn->prepare("SELECT r.id, r.slot_id, LOWER(TRIM(r.status)) AS reservation_status,
                                 LOWER(TRIM(p.status)) AS slot_status, p.slot_number
                          FROM reservations r JOIN parking_slots p ON p.id = r.slot_id
                          WHERE r.id = ? AND r.user_id = ? FOR UPDATE");
    $st->bind_param("ii", $reservation_id, $user_id);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$r) throw new Exception('ไม่พบรายการจองนี้ หรือรายการจองนี้ไม่ใช่ของคุณ');
    if (in_array($r['reservation_status'], ['cancelled', 'canceled'], true)) throw new Exception('รายการนี้ถูกยกเลิกไปแล้ว');
    if ($r['reservation_status'] === 'completed') throw new Exception('รายการนี้เสร็จสิ้นแล้ว ไม่สามารถยกเลิกได้');
    // ถ้ารถเข้าจอดในช่องแล้ว ห้ามยกเลิกการจอง
    if ($r['slot_status'] === 'occupied') throw new Exception('รถเข้าจอดในช่องนี้แล้ว ไม่สามารถยกเลิกการจองได้');

    $st = $conn->prepare("UPDATE reservations SET status='cancelled' WHERE id=? AND user_id=?");
    $st->bind_param("ii", $reservation_id, $user_id);
    $st->execute();
    $st->close();

    // คืนช่องเป็น available ถ้าไม่มีการจองอื่นค้างอยู่ (ฟังก์ชันกลางใน lib.php)
    sp_release_slot_if_free($conn, (int)$r['slot_id'], $reservation_id);

    $conn->commit();
    sp_json([
        'status'         => 'success',
        'message'        => 'ยกเลิกการจองช่อง ' . $r['slot_number'] . ' เรียบร้อยแล้ว',
        'reservation_id' => $reservation_id,
        'slot_id'        => (int)$r['slot_id'],
        'slot_number'    => $r['slot_number'],
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    sp_json(['status' => 'error', 'message' => $e->getMessage()], 400);
}
