<?php
// api_get_approvals.php - รายการที่ Admin ต้องตรวจ/ติดตาม
//   booking_pending          : สลิปค่าจองรอตรวจ
//   booking_approved         : จองแล้ว รอรถเข้า
//   occupied                 : รถกำลังจอดอยู่
//   service_payment_pending  : ส่งสลิปค่าจอดแล้ว รอตรวจ
session_start();
require_once "db.php";
sp_json_api();
sp_require_admin_json($conn);

$data = [];

/* ---------- การจอง ---------- */
$sql = "SELECT r.id AS reservation_id, r.user_id, r.slot_id, r.reserve_date, r.reserve_time,
               r.status AS reservation_status, r.slip_image, r.reject_reason,
               p.slot_number, COALESCE(u.full_name, u.username) AS full_name, COALESCE(u.phone,'-') AS phone
        FROM reservations r
        JOIN parking_slots p ON p.id = r.slot_id
        LEFT JOIN users u ON u.id = r.user_id
        WHERE LOWER(r.status) IN ('pending','reserved')
          AND NOT EXISTS (SELECT 1 FROM parking_sessions x WHERE x.slot_id = r.slot_id AND x.user_id = r.user_id AND x.status = 'parking')
        ORDER BY r.id DESC";
$res = $conn->query($sql);
while ($r = $res->fetch_assoc()) {
    $status = strtolower($r['reservation_status']);
    $data[] = [
        'stage'          => $status === 'pending' ? 'booking_pending' : 'booking_approved',
        'reservation_id' => (int)$r['reservation_id'],
        'session_id'     => 0,
        'user_name'      => $r['full_name'] ?: ('User #' . $r['user_id']),
        'phone_number'   => $r['phone'] ?: '-',
        'slot_number'    => $r['slot_number'] ?? '-',
        'license_plate'  => '-',
        'check_in_at'    => null,
        'check_out_at'   => null,
        'duration_minutes' => 0,
        'fee'            => 0,
        'amount'         => SP_BOOKING_FEE,
        'payment_status' => $status === 'reserved' ? 'paid' : 'pending',
        'slip_url'       => $r['slip_image'] ? 'uploads/' . rawurlencode($r['slip_image']) : null,
        'reserve_date'   => $r['reserve_date'],
        'reserve_time'   => $r['reserve_time'],
    ];
}

/* ---------- รถที่กำลังจอด ---------- */
$res = $conn->query("SELECT ps.id AS session_id, ps.user_id, ps.vehicle_plate, ps.checkin_time,
                            p.slot_number, COALESCE(u.full_name, u.username) AS full_name, COALESCE(u.phone,'-') AS phone
                     FROM parking_sessions ps
                     JOIN parking_slots p ON p.id = ps.slot_id
                     LEFT JOIN users u ON u.id = ps.user_id
                     WHERE ps.status = 'parking'
                     ORDER BY ps.id DESC");
while ($r = $res->fetch_assoc()) {
    $data[] = [
        'stage'          => 'occupied',
        'reservation_id' => 0,
        'session_id'     => (int)$r['session_id'],
        'user_name'      => $r['full_name'] ?: 'ไม่ได้ระบุผู้ใช้ (Drive-in)',
        'phone_number'   => $r['phone'] ?: '-',
        'slot_number'    => $r['slot_number'] ?? '-',
        'license_plate'  => $r['vehicle_plate'] ?: '-',
        'check_in_at'    => $r['checkin_time'],
        'check_out_at'   => null,
        'duration_minutes' => 0,
        'fee'            => 0,
        'amount'         => 0,
        'payment_status' => 'pending',
        'slip_url'       => null,
        'reserve_date'   => null,
        'reserve_time'   => null,
    ];
}

/* ---------- ค่าจอด: เฉพาะรายการที่ผู้ใช้ส่งสลิปแล้ว (รอตรวจ) ---------- */
$sql = "
SELECT ps.id AS session_id, ps.user_id, ps.vehicle_plate, ps.checkin_time, ps.checkout_time,
       ps.duration_minutes, ps.fee, p.slot_number,
       COALESCE(u.full_name, u.username) AS full_name, COALESCE(u.phone,'-') AS phone,
       pay.id AS payment_id, pay.payment_status, pay.amount,
       sl.id AS slip_id, sl.slip_filename, sl.slip_path, sl.status AS slip_status, sl.uploaded_at,
       q.qr_reference
FROM parking_sessions ps
JOIN parking_slots p ON p.id = ps.slot_id
LEFT JOIN users u ON u.id = ps.user_id
JOIN payments pay ON pay.id = (SELECT p2.id FROM payments p2 WHERE p2.session_id = ps.id ORDER BY p2.id DESC LIMIT 1)
JOIN payment_slips sl ON sl.id = (SELECT s2.id FROM payment_slips s2 WHERE s2.payment_id = pay.id ORDER BY s2.id DESC LIMIT 1)
LEFT JOIN payment_qr q ON q.id = (SELECT q2.id FROM payment_qr q2 WHERE q2.payment_id = pay.id ORDER BY q2.id DESC LIMIT 1)
WHERE ps.status = 'completed'
  AND LOWER(pay.payment_status) = 'pending'
  AND LOWER(sl.status) = 'pending'
ORDER BY ps.id DESC";
$res = $conn->query($sql);
while ($r = $res->fetch_assoc()) {
    $data[] = [
        'stage'          => 'service_payment_pending',
        'reservation_id' => 0,
        'session_id'     => (int)$r['session_id'],
        'payment_id'     => (int)$r['payment_id'],
        'user_name'      => $r['full_name'] ?: ('User #' . $r['user_id']),
        'phone_number'   => $r['phone'] ?: '-',
        'slot_number'    => $r['slot_number'] ?? '-',
        'license_plate'  => $r['vehicle_plate'] ?: '-',
        'check_in_at'    => $r['checkin_time'],
        'check_out_at'   => $r['checkout_time'],
        'duration_minutes' => (int)$r['duration_minutes'],
        'fee'            => (float)$r['fee'],
        'amount'         => (float)$r['amount'],
        'payment_status' => $r['payment_status'],
        'slip_status'    => $r['slip_status'],
        'slip_url'       => $r['slip_path'] ?: ($r['slip_filename'] ? 'uploads/' . rawurlencode($r['slip_filename']) : null),
        'slip_filename'  => $r['slip_filename'],
        'uploaded_at'    => $r['uploaded_at'],
        'qr_reference'   => $r['qr_reference'],
    ];
}

echo json_encode(['status' => 'success', 'data' => $data, 'count' => count($data)], JSON_UNESCAPED_UNICODE);
