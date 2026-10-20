<?php
// [อธิบาย] หน้าหลักของผู้ใช้ (ต้องล็อกอิน)
// PHP ฝั่งบนโหลดข้อมูลการจอง/รายการจอด/สถานะการชำระเงินของผู้ใช้ ส่วน JavaScript ด้านล่าง
// ดึง get_slots.php ทุก 3 วินาที (setInterval) เพื่ออัปเดตสถานะช่องแบบ real-time และเรียก user_cancel_reservation.php เมื่อกดยกเลิกการจอง
// ปุ่มที่เกี่ยวข้อง: จองช่อง (reserve.php), จ่ายค่าจอด (payment_parking.php), รับรายการจอด (claim_parking.php)
session_start();
require_once "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$name = $_SESSION['name'] ?? $_SESSION['username'] ?? 'User';

function status_th($status) {
    $status = strtolower(trim((string)$status));

    switch ($status) {
        case 'pending':
            return 'รอตรวจสอบ';
        case 'reserved':
            return 'จองแล้ว';
        case 'occupied':
            return 'มีรถจอด';
        case 'cancelled':
        case 'canceled':
            return 'ยกเลิกแล้ว';
        case 'available':
        case 'empty':
        default:
            return 'ว่าง';
    }
}

function status_class($status) {
    $status = strtolower(trim((string)$status));

    switch ($status) {
        case 'reserved':
            return 'reserved';
        case 'occupied':
            return 'occupied';
        case 'cancelled':
        case 'canceled':
            return 'cancelled';
        default:
            return 'available';
    }
}

function money($amount) {
    return number_format((float)$amount, 2);
}

/* ช่องจอด */
$slots = [];
$result = $conn->query("
    SELECT id, slot_number, status, distance_cm
    FROM parking_slots
    ORDER BY slot_number ASC
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['status'] = sp_norm_slot_status($row['status']);
        $slots[] = $row;
    }
}

/* รายการจองของ User */
$reservations = [];

$stmt = $conn->prepare("
    SELECT
        r.id,
        r.slot_id,
        r.reserve_date,
        r.reserve_time,
        r.status,
        r.reject_reason,
        p.slot_number,
        p.status AS slot_status
    FROM reservations r
    INNER JOIN parking_slots p ON p.id = r.slot_id
    WHERE r.user_id = ?
    ORDER BY r.id DESC
");

if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $reservations[] = $row;
    }

    $stmt->close();
}

/* ประวัติการจอด */
$sessions = [];

$stmt = $conn->prepare("
    SELECT
        ps.id,
        ps.slot_id,
        ps.vehicle_plate,
        ps.checkin_time,
        ps.checkout_time,
        ps.duration_minutes,
        ps.fee,
        ps.status,
        COALESCE(
            (SELECT pay.payment_status
             FROM payments pay
             WHERE pay.session_id = ps.id
             ORDER BY pay.id DESC
             LIMIT 1),
            'pending'
        ) AS payment_status,
        (SELECT sl.status
         FROM payment_slips sl
         JOIN payments pay2 ON pay2.id = sl.payment_id
         WHERE pay2.session_id = ps.id
         ORDER BY sl.id DESC
         LIMIT 1) AS slip_status,
        p.slot_number
    FROM parking_sessions ps
    INNER JOIN parking_slots p ON p.id = ps.slot_id
    WHERE ps.user_id = ?
    ORDER BY ps.id DESC
    LIMIT 20
");

if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $sessions[] = $row;
    }

    $stmt->close();
}

/* สรุปจำนวนช่อง */
$available_count = 0;
$reserved_count = 0;
$occupied_count = 0;

foreach ($slots as $slot) {
    $s = strtolower(trim((string)$slot['status']));

    if ($s === 'occupied') {
        $occupied_count++;
    } elseif ($s === 'reserved') {
        $reserved_count++;
    } else {
        $available_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Smart Parking - User Dashboard</title>

    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Prompt', sans-serif;
            background: #07111f;
            color: #e5e7eb;
        }

        .container {
            width: min(1200px, 94%);
            margin: auto;
        }

        .navbar {
            background: #0b1728;
            border-bottom: 1px solid #1e334d;
            padding: 16px 0;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .brand {
            color: #fff;
            font-size: 22px;
            font-weight: 700;
        }

        .brand span {
            color: #38bdf8;
        }

        .user-box {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-name {
            color: #cbd5e1;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: 0;
            border-radius: 9px;
            padding: 9px 14px;
            cursor: pointer;
            font-family: inherit;
            text-decoration: none;
            font-size: 14px;
        }

        .btn-danger {
            background: #dc2626;
            color: #fff;
        }

        .btn-danger:hover {
            background: #b91c1c;
        }

        .btn-warning {
            background: #f59e0b;
            color: #111827;
        }

        .btn-primary {
            background: #0ea5e9;
            color: #fff;
        }

        .btn-primary:hover {
            background: #0284c7;
        }

        .btn-secondary {
            background: #334155;
            color: #fff;
        }

        .hero {
            padding: 30px 0 20px;
        }

        .hero h1 {
            margin: 0 0 5px;
            font-size: 30px;
        }

        .hero p {
            margin: 0;
            color: #94a3b8;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 25px;
        }

        .stat {
            background: #0c1a2c;
            border: 1px solid #1d334d;
            border-radius: 14px;
            padding: 20px;
        }

        .stat-title {
            color: #94a3b8;
            font-size: 14px;
        }

        .stat-value {
            font-size: 30px;
            font-weight: 700;
            margin-top: 5px;
        }

        .green { color: #22c55e; }
        .yellow { color: #f59e0b; }
        .red { color: #ef4444; }
        .blue { color: #38bdf8; }

        .section {
            margin: 28px 0;
        }

        .section-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .section-title h2 {
            margin: 0;
            font-size: 21px;
        }

        .card {
            background: #0c1a2c;
            border: 1px solid #1d334d;
            border-radius: 14px;
            padding: 18px;
        }

        .slots {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }

        .slot {
            border-radius: 14px;
            padding: 20px;
            background: #0a1626;
            border: 2px solid #1e334d;
        }

        .slot.available {
            border-color: rgba(34, 197, 94, .45);
        }

        .slot.reserved {
            border-color: rgba(245, 158, 11, .55);
        }

        .slot.occupied {
            border-color: rgba(239, 68, 68, .55);
        }

        .slot.cancelled {
            border-color: #475569;
        }

        .slot-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .slot-number {
            font-size: 20px;
            font-weight: 700;
        }

        .badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-green {
            color: #22c55e;
            background: rgba(34,197,94,.12);
        }

        .badge-yellow {
            color: #f59e0b;
            background: rgba(245,158,11,.12);
        }

        .badge-red {
            color: #ef4444;
            background: rgba(239,68,68,.12);
        }

        .badge-gray {
            color: #94a3b8;
            background: rgba(148,163,184,.12);
        }

        .distance {
            color: #94a3b8;
            margin-bottom: 14px;
        }

        .reservation-list,
        .session-list {
            display: grid;
            gap: 12px;
        }

        .reservation-item,
        .session-item {
            background: #0a1626;
            border: 1px solid #1e334d;
            border-radius: 12px;
            padding: 16px;
        }

        .item-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .item-info {
            color: #94a3b8;
            font-size: 14px;
            line-height: 1.8;
        }

        .empty {
            text-align: center;
            padding: 30px;
            color: #64748b;
        }

        .action-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .payment-box {
            background: rgba(245,158,11,.08);
            border: 1px solid rgba(245,158,11,.25);
            padding: 10px;
            border-radius: 9px;
            margin-top: 10px;
        }

        @media (max-width: 800px) {
            .stats,
            .slots {
                grid-template-columns: 1fr;
            }

            .nav-inner {
                align-items: flex-start;
                flex-direction: column;
            }

            .user-box {
                width: 100%;
                justify-content: space-between;
            }
        }
    </style>
</head>

<body>

<nav class="navbar">
    <div class="container nav-inner">

        <div class="brand">
            Smart <span>Parking</span>
        </div>

        <div class="user-box">
            <div class="user-name">
                👤 <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
            </div>

            <a href="logout.php"
               class="btn btn-danger"
               onclick="return confirm('ต้องการออกจากระบบใช่หรือไม่?');">
                ออกจากระบบ
            </a>
        </div>

    </div>
</nav>

<main class="container">

    <section class="hero">
        <h1>Dashboard</h1>
        <p>ระบบจัดการที่จอดรถ Smart Parking</p>
    </section>
<div class="action-row" style="margin-bottom:18px;">
    <a href="reserve.php" class="btn btn-primary">📅 จองช่องจอด</a>
    <a href="claim_parking.php" class="btn btn-warning">🚗 รับรายการจอด (รถที่ไม่ได้จอง)</a>
</div>
<?php if (($_GET['msg'] ?? '') === 'booking_pending'): ?>
<div class="card" style="border-color:#f59e0b;color:#fbbf24;margin-bottom:20px;">
    🕐 ส่งคำขอจองและสลิปแล้ว — รอแอดมินตรวจสอบก่อนช่องจะเปลี่ยนเป็น “จองแล้ว”
</div>
<?php elseif (($_GET['msg'] ?? '') === 'parking_pending'): ?>
<div class="card" style="border-color:#38bdf8;color:#38bdf8;margin-bottom:20px;">
    🕐 ส่งสลิปค่าจอดแล้ว — รอแอดมินตรวจสอบและอนุมัติการชำระเงิน
</div>
<?php endif; ?>

    <!-- สรุปสถานะ -->
    <section class="stats">

        <div class="stat">
            <div class="stat-title">ช่องว่าง</div>
            <div class="stat-value green" id="availableCount">
                <?= $available_count ?>
            </div>
        </div>

        <div class="stat">
            <div class="stat-title">จองแล้ว</div>
            <div class="stat-value yellow" id="reservedCount">
                <?= $reserved_count ?>
            </div>
        </div>

        <div class="stat">
            <div class="stat-title">มีรถจอด</div>
            <div class="stat-value red" id="occupiedCount">
                <?= $occupied_count ?>
            </div>
        </div>

    </section>

    <!-- ช่องจอด -->
    <section class="section">

        <div class="section-title">
            <h2>🚗 สถานะช่องจอด</h2>
            <span class="blue">Real-Time</span>
        </div>

        <div class="slots" id="slotsContainer">

            <?php foreach ($slots as $slot): ?>

                <?php
                $status = strtolower(trim((string)$slot['status']));
                $class = status_class($status);
                ?>

                <div class="slot <?= $class ?>"
                     data-slot-id="<?= (int)$slot['id'] ?>">

                    <div class="slot-head">

                        <div class="slot-number">
                            ช่อง <?= htmlspecialchars($slot['slot_number']) ?>
                        </div>

                        <?php if ($status === 'occupied'): ?>

                            <span class="badge badge-red">
                                🔴 มีรถจอด
                            </span>

                        <?php elseif ($status === 'reserved'): ?>

                            <span class="badge badge-yellow">
                                🟡 จองแล้ว
                            </span>

                        <?php else: ?>

                            <span class="badge badge-green">
                                🟢 ว่าง
                            </span>

                        <?php endif; ?>

                    </div>

                    <div class="distance">
                        ระยะเซนเซอร์:
                        <strong>
                            <?= htmlspecialchars((string)$slot['distance_cm']) ?> cm
                        </strong>
                    </div>

                    <div class="slot-action">
                    <?php if ($status === 'available'): ?>

                        <a href="reserve.php?slot_id=<?= (int)$slot['id'] ?>"
                           class="btn btn-primary">
                            จองช่องนี้
                        </a>

                    <?php elseif ($status === 'reserved'): ?>

                        <span class="badge badge-yellow">
                            ช่องนี้มีผู้จองแล้ว
                        </span>

                    <?php else: ?>

                        <span class="badge badge-red">
                            ไม่สามารถจองได้
                        </span>

                    <?php endif; ?>
                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    </section>

    <!-- รายการจอง -->
    <section class="section">

        <div class="section-title">
            <h2>📋 การจองของฉัน</h2>
        </div>

        <?php if (count($reservations) === 0): ?>

            <div class="card empty">
                ยังไม่มีรายการจอง
            </div>

        <?php else: ?>

            <div class="reservation-list">

                <?php foreach ($reservations as $r): ?>

                    <?php
                    $reservation_status =
                        strtolower(trim((string)$r['status']));
                    ?>

                    <div class="reservation-item">

                        <div class="item-top">

                            <strong>
                                ช่อง <?= htmlspecialchars($r['slot_number']) ?>
                            </strong>

                            <?php if ($reservation_status === 'pending'): ?>

                                <span class="badge badge-yellow">
                                    🕐 รอแอดมินตรวจสลิป
                                </span>

                            <?php elseif ($reservation_status === 'reserved'): ?>

                                <span class="badge badge-yellow">
                                    🟡 จองแล้ว (อนุมัติแล้ว)
                                </span>

                            <?php elseif ($reservation_status === 'cancelled' ||
                                        $reservation_status === 'canceled'): ?>

                                <span class="badge badge-gray">
                                    ยกเลิกแล้ว
                                </span>

                            <?php elseif ($reservation_status === 'completed'): ?>

                                <span class="badge badge-green">
                                    เสร็จสิ้น
                                </span>

                            <?php else: ?>

                                <span class="badge badge-gray">
                                    <?= htmlspecialchars($r['status']) ?>
                                </span>

                            <?php endif; ?>

                        </div>

                        <div class="item-info">
                            วันที่จอง:
                            <?= htmlspecialchars($r['reserve_date'] ?? '-') ?>
                            <br>

                            เวลา:
                            <?= htmlspecialchars($r['reserve_time'] ?? '-') ?>
                            <?php if (!empty($r['license_plate'])): ?>
                                <br>
                                ทะเบียน:
                                <?= htmlspecialchars($r['license_plate']) ?>
                            <?php endif; ?>
                            <?php if (in_array($reservation_status, ['cancelled', 'canceled'], true) && !empty($r['reject_reason'])): ?>
                                <br>
                                <span style="color:#f87171;">เหตุผล: <?= htmlspecialchars($r['reject_reason']) ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if (
                            $reservation_status === 'reserved' ||
                            $reservation_status === 'pending'
                        ): ?>

                            <div class="action-row">

                                <button
                                    type="button"
                                    class="btn btn-danger"
                                    onclick="cancelMyReservation(<?= (int)$r['id'] ?>)">
                                    ❌ ยกเลิกการจอง
                                </button>

                            </div>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

    <!-- ประวัติการจอด -->
    <section class="section">

        <div class="section-title">
            <h2>🅿️ ประวัติการเข้าจอด</h2>
        </div>

        <?php if (count($sessions) === 0): ?>

            <div class="card empty">
                ยังไม่มีประวัติการเข้าจอด
            </div>

        <?php else: ?>

            <div class="session-list">

                <?php foreach ($sessions as $session): ?>

                    <div class="session-item">

                        <div class="item-top">

                            <strong>
                                ช่อง <?= htmlspecialchars($session['slot_number']) ?>
                            </strong>

                            <?php if (
                                strtolower((string)$session['status']) === 'parking'
                            ): ?>

                                <span class="badge badge-red">
                                    🔴 กำลังจอด
                                </span>

                            <?php else: ?>

                                <span class="badge badge-green">
                                    เสร็จสิ้น
                                </span>

                            <?php endif; ?>

                        </div>

                        <div class="item-info">

                            ทะเบียน:
                            <?= htmlspecialchars($session['vehicle_plate'] ?? '-') ?>
                            <br>

                            เวลาเข้า:
                            <?= htmlspecialchars($session['checkin_time'] ?? '-') ?>
                            <br>

                            เวลาออก:
                            <?= htmlspecialchars($session['checkout_time'] ?? '-') ?>
                            <br>

                            ระยะเวลาจอด:
                            <?= htmlspecialchars($session['duration_minutes'] ?? '0') ?>
                            นาที
                            <br>

                            ค่าจอด:
                            <strong class="blue">
                                <?= money($session['fee'] ?? 0) ?> บาท
                            </strong>

                        </div>

                        <?php
                        $payment_status =
                            strtolower(trim((string)($session['payment_status'] ?? '')));
                        ?>

                        <?php
                        $slip_status = strtolower(trim((string)($session['slip_status'] ?? '')));
                        $is_completed = strtolower((string)$session['status']) === 'completed';
                        ?>

                        <?php if ($is_completed && $payment_status !== 'paid' && $slip_status === 'pending'): ?>

                            <div class="payment-box" style="color:#38bdf8;">
                                🕐 ส่งสลิปแล้ว รอแอดมินตรวจสอบ
                            </div>

                        <?php elseif ($is_completed && $payment_status !== 'paid'): ?>

                            <div class="payment-box">

                                <strong>
                                    ⚠️ ยังไม่ได้ชำระค่าจอด
                                </strong>

                                <?php if ($slip_status === 'rejected'): ?>
                                    <div style="color:#f87171;margin-top:6px;">
                                        ❌ สลิปถูกปฏิเสธ<?= !empty($session['slip_reject_reason']) ? ': ' . htmlspecialchars($session['slip_reject_reason']) : '' ?>
                                    </div>
                                <?php endif; ?>

                                <div class="action-row">

                                    <a
                                        href="payment_parking.php?session_id=<?= (int)$session['id'] ?>"
                                        class="btn btn-warning">
                                        💳 <?= $slip_status === 'rejected' ? 'ส่งสลิปใหม่' : 'ชำระค่าจอด' ?>
                                    </a>

                                </div>

                            </div>

                        <?php elseif ($payment_status === 'paid'): ?>

                            <div class="payment-box"
                                 style="color:#22c55e;">
                                ✅ ชำระเงินแล้ว
                            </div>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

</main>

<script>
/*
|--------------------------------------------------------------------------
| ยกเลิกการจองของ User
|--------------------------------------------------------------------------
*/
function cancelMyReservation(reservationId) {

    if (!reservationId) {
        alert('ไม่พบรหัสการจอง');
        return;
    }

    const confirmCancel = confirm(
        'ยืนยันการยกเลิกการจองนี้ใช่หรือไม่?\n\n' +
        'เมื่อยกเลิกแล้ว ช่องจอดจะกลับมาเป็นช่องว่าง'
    );

    if (!confirmCancel) {
        return;
    }

    const formData = new URLSearchParams();

    formData.append(
        'reservation_id',
        reservationId
    );

    fetch('user_cancel_reservation.php', {
        method: 'POST',
        headers: {
            'Content-Type':
                'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: formData.toString()
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {

        if (data.status === 'success') {

            alert(data.message);

            window.location.reload();

        } else {

            alert(
                data.message ||
                'ไม่สามารถยกเลิกการจองได้'
            );
        }

    })
    .catch(function(error) {

        console.error(error);

        alert(
            'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์'
        );
    });
}

/*
|--------------------------------------------------------------------------
| Real-Time สถานะช่องจอด
|--------------------------------------------------------------------------
*/
function loadSlots() {

    fetch('get_slots.php?ts=' + Date.now())
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {

            /*
             * รองรับทั้งกรณี API ส่ง array โดยตรง
             * และกรณีส่ง {slots: [...]}
             */
            let slots = Array.isArray(data)
                ? data
                : (Array.isArray(data.slots) ? data.slots : []);

            if (!slots.length) {
                return;
            }

            let available = 0;
            let reserved = 0;
            let occupied = 0;

            slots.forEach(function(slot) {

                let status =
                    String(slot.status || '')
                    .toLowerCase();

                if (status === 'occupied') {
                    occupied++;
                }
                else if (status === 'reserved') {
                    reserved++;
                }
                else {
                    available++;
                }

                let element =
                    document.querySelector(
                        '[data-slot-id="' +
                        slot.id +
                        '"]'
                    );

                if (!element) {
                    return;
                }

                /*
                 * เปลี่ยนสีกรอบ
                 */
                element.classList.remove(
                    'available',
                    'reserved',
                    'occupied',
                    'cancelled'
                );

                if (status === 'occupied') {
                    element.classList.add('occupied');
                }
                else if (status === 'reserved') {
                    element.classList.add('reserved');
                }
                else {
                    element.classList.add('available');
                }

                /*
                 * อัปเดตป้ายสถานะและปุ่มจอง ให้ตรงกับสถานะล่าสุด
                 */
                let badge = element.querySelector('.slot-head .badge');
                let action = element.querySelector('.slot-action');

                if (status === 'occupied') {
                    if (badge) { badge.className = 'badge badge-red'; badge.textContent = '🔴 มีรถจอด'; }
                    if (action) action.innerHTML = '<span class="badge badge-red">ไม่สามารถจองได้</span>';
                }
                else if (status === 'reserved') {
                    if (badge) { badge.className = 'badge badge-yellow'; badge.textContent = '🟡 จองแล้ว'; }
                    if (action) action.innerHTML = '<span class="badge badge-yellow">ช่องนี้มีผู้จองแล้ว</span>';
                }
                else {
                    if (badge) { badge.className = 'badge badge-green'; badge.textContent = '🟢 ว่าง'; }
                    if (action) action.innerHTML = '<a href="reserve.php?slot_id=' + slot.id + '" class="btn btn-primary">จองช่องนี้</a>';
                }

                /*
                 * อัปเดตระยะทาง
                 */
                let distance =
                    element.querySelector('.distance strong');

                if (distance && slot.distance_cm !== undefined) {
                    distance.textContent =
                        slot.distance_cm + ' cm';
                }

            });

            document.getElementById('availableCount')
                .textContent = available;

            document.getElementById('reservedCount')
                .textContent = reserved;

            document.getElementById('occupiedCount')
                .textContent = occupied;

        })
        .catch(function(error) {
            console.log(
                'Real-time slot update:',
                error
            );
        });
}

/*
 * อัปเดตทุก 3 วินาที
 */
setInterval(loadSlots, 3000);

</script>

</body>
</html>
