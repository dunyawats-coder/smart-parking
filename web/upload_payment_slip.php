<?php
// [อธิบาย] รับสลิปค่าจอดจาก payment_parking.php (POST: payment_id, session_id, slip)
// ตรวจ: เป็นเจ้าของ session / session จบแล้ว / ยังไม่จ่าย -> sp_save_slip() -> บันทึก payment_slips เป็น 'pending'
// ถ้า error ส่งกลับ payment_parking.php พร้อมข้อความ; สำเร็จกลับ dashboard.php?msg=parking_pending
session_start();
require_once "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$payment_id = (int)($_POST['payment_id'] ?? 0);
$session_id = (int)($_POST['session_id'] ?? 0);

function fail_back($message, $session_id) {
    header("Location: payment_parking.php?session_id=" . (int)$session_id . "&error=" . urlencode($message));
    exit;
}

if ($payment_id <= 0 || $session_id <= 0) {
    fail_back("ข้อมูลการชำระเงินไม่ถูกต้อง", $session_id);
}

/* ตรวจ payment + session + owner */
$sql = "
    SELECT p.id AS payment_id,p.session_id,p.payment_status,
           ps.user_id,ps.status AS session_status,ps.fee
    FROM payments p
    INNER JOIN parking_sessions ps ON ps.id=p.session_id
    WHERE p.id=? AND p.session_id=?
    LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $payment_id, $session_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) fail_back("ไม่พบรายการชำระเงิน", $session_id);

// ต้องเป็นเจ้าของ session (ผ่านการ claim แล้ว) จึงส่งสลิปได้
if ($payment['user_id'] === null || (int)$payment['user_id'] !== $user_id) {
    fail_back("บัญชีนี้ไม่มีสิทธิ์ส่งสลิปของรายการนี้", $session_id);
}

if ($payment['session_status'] !== 'completed') {
    fail_back("รถยังไม่ออกจากช่องจอด", $session_id);
}

if ($payment['payment_status'] === 'paid') {
    fail_back("รายการนี้ชำระเงินแล้ว", $session_id);
}

/* ตรวจชนิด/ขนาดไฟล์และบันทึก (ฟังก์ชันกลางใน lib.php ใช้ร่วมกับสลิปค่าจอง) */
[$saved, $filename] = sp_save_slip($_FILES['slip'] ?? [], 'slip_parking');
if (!$saved) fail_back($filename, $session_id);

$destination  = __DIR__ . '/uploads/' . $filename;
$relativePath = 'uploads/' . $filename;

/*
 * สลิป pending เดิมของ payment เดียวกันจะถูกปิดด้วย rejected
 * แล้วสร้างรายการใหม่เป็น pending
 */
$conn->begin_transaction();

try {
    $stmt = $conn->prepare("
        UPDATE payment_slips
        SET status='rejected'
        WHERE payment_id=? AND status='pending'
    ");
    $stmt->bind_param("i", $payment_id);
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO payment_slips
        (payment_id, slip_filename, slip_path, status)
        VALUES (?, ?, ?, 'pending')
    ");
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param("iss", $payment_id, $filename, $relativePath);
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $stmt->close();

    /* ให้ payment ยังเป็น pending */
    $stmt = $conn->prepare("UPDATE payments SET payment_status='pending', paid_at=NULL WHERE id=?");
    $stmt->bind_param("i", $payment_id);
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $stmt->close();

    /* QR กลับมาเป็น pending */
    $stmt = $conn->prepare("
        UPDATE payment_qr
        SET qr_status='pending', paid_at=NULL
        WHERE payment_id=?
    ");
    $stmt->bind_param("i", $payment_id);
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $stmt->close();

    $conn->commit();

    header("Location: dashboard.php?msg=parking_pending");
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    if (is_file($destination)) @unlink($destination);
    fail_back("บันทึกสลิปไม่สำเร็จ: " . $e->getMessage(), $session_id);
}
?>
