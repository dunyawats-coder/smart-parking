<?php
// [อธิบาย] หน้าชำระค่าจอดหลังรถออก (session สถานะ completed)
// ลำดับ: โหลด session -> หา/สร้าง payments -> หา/สร้าง payment_qr -> สร้าง URL QR PromptPay ตามยอด -> แสดงสลิปล่าสุด/ฟอร์มส่งสลิป
// ฟอร์มส่งสลิปจะ POST ไป upload_payment_slip.php
session_start();
require_once "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;

if ($session_id <= 0) {
    die("ไม่พบ Parking Session");
}

/* PromptPay / อัตราค่าบริการ ใช้ค่าเดียวกับทั้งระบบจาก lib.php (SP_PROMPTPAY_ID, SP_PARKING_RATE) */
function h($v) {
    return sp_h($v);
}
$page_error = trim((string)($_GET['error'] ?? ''));

/* -----------------------------------------------------------
   โหลด Parking Session
----------------------------------------------------------- */
$sql = "
    SELECT
        ps.id AS session_id,
        ps.user_id,
        ps.slot_id,
        ps.vehicle_plate,
        ps.checkin_time,
        ps.checkout_time,
        ps.duration_minutes,
        ps.fee,
        ps.status,
        p.slot_number
    FROM parking_sessions ps
    INNER JOIN parking_slots p ON p.id = ps.slot_id
    WHERE ps.id = ?
    LIMIT 1
";
$stmt = $conn->prepare($sql);
if (!$stmt) die("Database Error: " . $conn->error);
$stmt->bind_param("i", $session_id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$session) die("ไม่พบ Parking Session");

// กันผู้ใช้คนอื่นเข้ามาจ่าย/ดูรายการของคนอื่น
// session ที่ user_id เป็น NULL (รถไม่ได้จอง และยังไม่ได้ claim) ยังเข้าได้ — ควร claim ก่อนจ่าย (ดู claim_parking.php)
if ($session['user_id'] !== null && (int)$session['user_id'] !== $user_id) {
    die("คุณไม่มีสิทธิ์ชำระเงินของ Parking Session นี้");
}

if ($session['status'] !== 'completed') {
    die("Parking Session นี้ยังไม่เสร็จสิ้นการจอด");
}

// ยอดค่าจอด = parking_sessions.fee ที่คำนวณไว้ตอนรถออก (นาที x อัตรา)
$amount = (float)$session['fee'];
if ($amount < 0) die("ยอดค่าจอดไม่ถูกต้อง");

/* -----------------------------------------------------------
   หา/สร้าง payments
----------------------------------------------------------- */
$sql = "SELECT * FROM payments WHERE session_id=? ORDER BY id DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $session_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ยังไม่มี payments ของ session นี้ -> สร้างใหม่เป็น pending (เกิดตอนเปิดหน้านี้ครั้งแรก)
// ถ้ามีแล้วแต่ยอดต่างจาก fee และยังไม่จ่าย ให้ปรับยอดให้ตรง
if (!$payment) {
    $method = 'promptpay';
    $status = 'pending';
    $stmt = $conn->prepare("
        INSERT INTO payments (session_id, amount, payment_method, payment_status)
        VALUES (?, ?, ?, ?)
    ");
    if (!$stmt) die("สร้างรายการชำระเงินไม่ได้: " . $conn->error);
    $stmt->bind_param("idss", $session_id, $amount, $method, $status);
    if (!$stmt->execute()) die("สร้างรายการชำระเงินไม่ได้: " . $stmt->error);
    $payment_id = $stmt->insert_id;
    $stmt->close();
} else {
    $payment_id = (int)$payment['id'];
    if ((float)$payment['amount'] !== $amount && $payment['payment_status'] !== 'paid') {
        $stmt = $conn->prepare("UPDATE payments SET amount=? WHERE id=?");
        $stmt->bind_param("di", $amount, $payment_id);
        $stmt->execute();
        $stmt->close();
    }
}

/* โหลด payment ปัจจุบัน */
$stmt = $conn->prepare("SELECT * FROM payments WHERE id=? LIMIT 1");
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* -----------------------------------------------------------
   หา/สร้าง payment_qr
----------------------------------------------------------- */
$stmt = $conn->prepare("SELECT * FROM payment_qr WHERE payment_id=? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$payment_qr = $stmt->get_result()->fetch_assoc();
$stmt->close();

// payment_qr เก็บเลขอ้างอิง (qr_reference) + PromptPay ID + ยอด ผูกกับ payments
if (!$payment_qr) {
    $qr_reference = 'SP' . date('YmdHis') . str_pad((string)$payment_id, 5, '0', STR_PAD_LEFT);
    $qr_status = 'generated';

    $stmt = $conn->prepare("
        INSERT INTO payment_qr
        (payment_id, qr_reference, promptpay_id, amount, qr_status)
        VALUES (?, ?, ?, ?, ?)
    ");
    if (!$stmt) die("สร้าง QR ไม่ได้: " . $conn->error);
    $promptpay = SP_PROMPTPAY_ID;
    $stmt->bind_param("issds", $payment_id, $qr_reference, $promptpay, $amount, $qr_status);
    if (!$stmt->execute()) die("สร้าง QR ไม่ได้: " . $stmt->error);
    $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM payment_qr WHERE id=LAST_INSERT_ID() LIMIT 1");
    $stmt->execute();
    $payment_qr = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

/*
 * ใช้บริการเดียวกับ payment_booking.php ที่มีอยู่ในโปรเจกต์
 * URL นี้สร้าง PromptPay QR ตามหมายเลขและยอดเงิน
 */
$qr_url = 'https://promptpay.io/' . rawurlencode(SP_PROMPTPAY_ID) . '/' . rawurlencode(number_format($amount, 2, '.', '')) . '.png';

if (empty($payment_qr['qr_image_path']) || $payment_qr['qr_image_path'] !== $qr_url) {
    $stmt = $conn->prepare("UPDATE payment_qr SET qr_image_path=? WHERE id=?");
    $stmt->bind_param("si", $qr_url, $payment_qr['id']);
    $stmt->execute();
    $stmt->close();
    $payment_qr['qr_image_path'] = $qr_url;
}

/* -----------------------------------------------------------
   สลิปล่าสุด
----------------------------------------------------------- */
$stmt = $conn->prepare("SELECT * FROM payment_slips WHERE payment_id=? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$slip = $stmt->get_result()->fetch_assoc();
$stmt->close();

$paid = ($payment['payment_status'] === 'paid');
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ชำระค่าจอดรถ - Smart Parking</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,Tahoma,sans-serif;background:#f3f4f6;color:#111827}
.container{max-width:720px;width:94%;margin:30px auto}
.card{background:#fff;border-radius:18px;padding:24px;margin-bottom:18px;box-shadow:0 6px 24px rgba(0,0,0,.08)}
h1,h2{margin-top:0}
.info{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.info-box{background:#f8fafc;border-radius:12px;padding:14px}
.label{font-size:13px;color:#64748b}.value{font-weight:700;margin-top:5px}
.amount{text-align:center;font-size:40px;font-weight:800;margin:22px 0;color:#047857}
.qr{text-align:center}
.qr img{display:block;width:300px;max-width:100%;margin:15px auto;border:1px solid #e5e7eb;border-radius:14px;padding:10px;background:#fff}
.ref{background:#f1f5f9;padding:12px;border-radius:10px;word-break:break-all}
.status{padding:14px;border-radius:10px;background:#fef3c7;color:#92400e}
.success{background:#dcfce7;color:#166534}
.error{background:#fee2e2;color:#991b1b}
input[type=file]{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px;margin:10px 0}
button{width:100%;border:0;border-radius:10px;padding:14px;background:#059669;color:#fff;font-size:17px;font-weight:700;cursor:pointer}
button:hover{background:#047857}
.small{font-size:13px;color:#64748b}
a.back{display:block;text-align:center;margin-top:14px;color:#2563eb;text-decoration:none}
@media(max-width:600px){.info{grid-template-columns:1fr}.amount{font-size:34px}}
</style>
</head>
<body>
<div class="container">

<div class="card">
<h1>💳 ชำระค่าจอดรถ</h1>
<div class="info">
<div class="info-box"><div class="label">Parking Session</div><div class="value">#<?=h($session['session_id'])?></div></div>
<div class="info-box"><div class="label">ช่องจอด</div><div class="value">Slot <?=h($session['slot_number'])?></div></div>
<div class="info-box"><div class="label">เวลาเข้า</div><div class="value"><?=h($session['checkin_time'])?></div></div>
<div class="info-box"><div class="label">เวลาออก</div><div class="value"><?=h($session['checkout_time'])?></div></div>
<div class="info-box"><div class="label">เวลาจอด</div><div class="value"><?=h($session['duration_minutes'])?> นาที</div></div>
<div class="info-box"><div class="label">ทะเบียนรถ</div><div class="value"><?=h($session['vehicle_plate'] ?: '-')?></div></div>
</div>
<div class="amount"><?=number_format($amount,2)?> บาท</div>
<div class="small" style="text-align:center">อัตราค่าจอด <?=h(rtrim(rtrim(number_format(SP_PARKING_RATE,2),"0"),"."))?> บาท/นาที</div>
</div>

<?php if($paid): ?>
<div class="card"><div class="status success">✅ ชำระเงินเรียบร้อยแล้ว</div></div>
<?php else: ?>

<div class="card qr">
<h2>สแกน PromptPay</h2>
<p>สแกน QR Code เพื่อชำระเงินจำนวน <strong><?=number_format($amount,2)?> บาท</strong></p>
<img src="<?=h($payment_qr['qr_image_path'])?>" alt="PromptPay QR">
<div class="ref"><strong>QR Reference</strong><br><?=h($payment_qr['qr_reference'])?></div>
<p class="small">PromptPay: <?=h(SP_PROMPTPAY_ID)?></p>
</div>

<div class="card">
<h2>📤 อัปโหลดสลิป</h2>

<?php if($page_error !== ''): ?>
<div class="status error">⚠️ <?=h($page_error)?></div>
<?php endif; ?>
<?php if($slip && strtolower($slip['status'])==='pending'): ?>
<div class="status">⏳ ส่งสลิปแล้ว กำลังรอ Admin ตรวจสอบ</div>
<?php elseif($slip && strtolower($slip['status'])==='rejected'): ?>
<div class="status error">❌ สลิปถูกปฏิเสธ<?php if(!empty($slip['reject_reason'])): ?> เนื่องจาก: <?=h($slip['reject_reason'])?><?php endif; ?> กรุณาอัปโหลดสลิปใหม่</div>
<?php endif; ?>

<form action="upload_payment_slip.php" method="post" enctype="multipart/form-data">
<input type="hidden" name="payment_id" value="<?=h($payment_id)?>">
<input type="hidden" name="session_id" value="<?=h($session_id)?>">
<input type="file" name="slip" accept="image/jpeg,image/png,image/webp" required>
<div class="small">รองรับ JPG, PNG, WEBP ขนาดไม่เกิน 5 MB</div>
<br>
<button type="submit">ส่งสลิปให้ Admin ตรวจสอบ</button>
</form>
</div>
<?php endif; ?>

<a class="back" href="dashboard.php">← กลับ Dashboard</a>
</div>
</body>
</html>
