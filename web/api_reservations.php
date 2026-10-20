<?php
// api_reservations.php - ผู้ใช้เห็นเฉพาะการจองของตัวเอง / Admin เห็นทั้งหมด
session_start();
require_once "db.php";
sp_json_api();

if (!isset($_SESSION['user_id'])) {
    sp_json(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบก่อน'], 401);
}

// แอดมินเห็นการจองทั้งหมด (พร้อมชื่อผู้ใช้) / ผู้ใช้ทั่วไปเห็นเฉพาะของตัวเอง (WHERE r.user_id = ?)
if (sp_is_admin($conn)) {
    $stmt = $conn->prepare("SELECT r.*, u.username, p.slot_number
                            FROM reservations r
                            LEFT JOIN users u ON r.user_id = u.id
                            JOIN parking_slots p ON r.slot_id = p.id
                            ORDER BY r.id DESC");
} else {
    $uid = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT r.*, p.slot_number
                            FROM reservations r
                            JOIN parking_slots p ON r.slot_id = p.id
                            WHERE r.user_id = ? ORDER BY r.id DESC");
    $stmt->bind_param("i", $uid);
}
$stmt->execute();
$data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
echo json_encode($data, JSON_UNESCAPED_UNICODE);
