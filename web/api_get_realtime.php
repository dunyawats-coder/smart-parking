<?php
// api_get_realtime.php - ข้อมูล real-time สำหรับ admin_dashboard.php
session_start();
require_once "db.php";
sp_json_api();
sp_require_admin_json($conn);

$slots = [];
$res = $conn->query("SELECT id AS slot_id, slot_number, status, distance_cm FROM parking_slots ORDER BY slot_number ASC");
// วนทีละช่อง: เติมข้อมูลผู้จอง (จากการจองที่อนุมัติแล้ว) และผู้ที่จอดอยู่จริง (จาก parking_sessions)
// ยิง query ต่อช่องได้เพราะระบบมีช่องจอดแค่ไม่กี่ช่อง
while ($slot = $res->fetch_assoc()) {
    $slotId = (int)$slot['slot_id'];
    $status = sp_norm_slot_status($slot['status']);

    $slot['slot_id']        = $slotId;
    $slot['status']         = $status;
    $slot['is_occupied']    = ($status === 'occupied');
    $slot['is_booked']      = false;
    $slot['reservation_id'] = null;
    $slot['user_name']      = null;
    $slot['username']       = null;
    $slot['license_plate']  = null;
    $slot['reserve_date']   = null;
    $slot['reserve_time']   = null;

    // การจองที่ admin อนุมัติแล้ว (reserved)
    $st = $conn->prepare("SELECT r.id AS reservation_id, r.reserve_date, r.reserve_time,
                                 u.full_name, u.username
                          FROM reservations r LEFT JOIN users u ON u.id = r.user_id
                          WHERE r.slot_id = ? AND LOWER(r.status) = 'reserved'
                          ORDER BY r.id DESC LIMIT 1");
    $st->bind_param("i", $slotId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();

    if ($r) {
        $slot['reservation_id'] = (int)$r['reservation_id'];
        $slot['user_name']      = $r['full_name'] ?: ($r['username'] ?: 'ผู้ใช้');
        $slot['username']       = $r['username'] ?? '';
        $slot['license_plate']  = null;
        $slot['reserve_date']   = $r['reserve_date'];
        $slot['reserve_time']   = $r['reserve_time'];
        if ($status === 'reserved') $slot['is_booked'] = true;
    }

    // รถที่จอดอยู่จริง (ข้อมูลจาก parking session มาก่อนข้อมูลการจอง)
    if ($status === 'occupied') {
        $st = $conn->prepare("SELECT ps.vehicle_plate, ps.checkin_time, u.full_name, u.username
                              FROM parking_sessions ps LEFT JOIN users u ON u.id = ps.user_id
                              WHERE ps.slot_id = ? AND ps.status = 'parking'
                              ORDER BY ps.id DESC LIMIT 1");
        $st->bind_param("i", $slotId);
        $st->execute();
        $s = $st->get_result()->fetch_assoc();
        $st->close();

        $slot['user_name']     = ($s['full_name'] ?? null) ?: (($s['username'] ?? null) ?: null);
        $slot['username']      = $s['username'] ?? '';
        $slot['license_plate'] = ($s['vehicle_plate'] ?? null) ?: ($slot['license_plate'] ?? null);
        $slot['checkin_time']  = $s['checkin_time'] ?? null;
        $slot['is_booked']     = false;
    }

    $slots[] = $slot;
}

// จำนวนรายการที่รอแอดมินตรวจสลิป (ค่าจอง + ค่าจอด)
// นับรายการที่รอแอดมินตรวจสลิป (ค่าจอง + ค่าจอด) ใช้แสดงตัวเลขบนปุ่ม 'อนุมัติชำระเงิน'
$pendingBooking = (int)$conn->query("SELECT COUNT(*) c FROM reservations WHERE LOWER(status)='pending'")->fetch_assoc()['c'];
$pendingService = (int)$conn->query("
    SELECT COUNT(*) c
    FROM payment_slips s
    JOIN payments p ON p.id = s.payment_id
    WHERE LOWER(s.status)='pending' AND LOWER(p.payment_status)='pending'
")->fetch_assoc()['c'];

echo json_encode([
    'status'            => 'success',
    'slots'             => $slots,
    'pending_approvals' => $pendingBooking + $pendingService,
    'pending_booking'   => $pendingBooking,
    'pending_service'   => $pendingService,
    'server_time'       => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);
