<?php
// [อธิบาย] 'รับรายการจอด' สำหรับรถที่ไม่ได้จองล่วงหน้า (session ถูกสร้างด้วย user_id = NULL ตอนรถเข้า)
// ผู้ใช้เปิดหน้านี้พร้อม session_id แล้วกดยืนยัน -> ผูก session เข้ากับบัญชีตัวเอง -> ไปจ่ายเงินที่ payment_parking.php
// เงื่อนไข: session ต้อง completed (รถออกแล้ว) และยังไม่มีเจ้าของ
session_start();
require_once "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$message = "";
$message_type = "";

$session_id = isset($_GET['session_id'])
    ? (int)$_GET['session_id']
    : (isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0);

/*
|--------------------------------------------------------------------------
| Claim Parking Session
|--------------------------------------------------------------------------
| ใช้สำหรับรถที่ไม่ได้จอง
| parking_sessions.user_id ต้องเป็น NULL
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_session'])) {

    $session_id = (int)$_POST['session_id'];

    if ($session_id <= 0) {
        $message = "ไม่พบหมายเลข Parking Session";
        $message_type = "error";
    } else {

        // ตรวจสอบ Session
        $sql = "
            SELECT 
                ps.id,
                ps.user_id,
                ps.slot_id,
                ps.checkin_time,
                ps.checkout_time,
                ps.duration_minutes,
                ps.fee,
                ps.status,
                p.slot_number
            FROM parking_sessions ps
            INNER JOIN parking_slots p 
                ON p.id = ps.slot_id
            WHERE ps.id = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $session_id);
        $stmt->execute();

        $result = $stmt->get_result();
        $session = $result->fetch_assoc();

        $stmt->close();

        if (!$session) {

            $message = "ไม่พบข้อมูล Parking Session";
            $message_type = "error";

        } elseif ($session['user_id'] !== null) {

            if ((int)$session['user_id'] === $user_id) {

                // เป็นของผู้ใช้นี้อยู่แล้ว
                header(
                    "Location: payment_parking.php?session_id=" .
                    $session_id
                );
                exit;

            } else {

                $message = "Parking Session นี้ถูกผูกกับผู้ใช้อื่นแล้ว";
                $message_type = "error";
            }

        } elseif ($session['status'] !== 'completed') {

            $message = "รถคันนี้ยังไม่สิ้นสุดการจอด กรุณารอรถออกก่อน";
            $message_type = "error";

        } else {

            /*
            |--------------------------------------------------------------------------
            | ตรวจสอบอีกครั้งก่อน UPDATE
            |--------------------------------------------------------------------------
            | WHERE user_id IS NULL
            | ป้องกันการ Claim ซ้ำในกรณีมีหลายคนกดพร้อมกัน
            |--------------------------------------------------------------------------
            */

            $update_sql = "
                UPDATE parking_sessions
                SET user_id = ?
                WHERE id = ?
                  AND user_id IS NULL
                  AND status = 'completed'
                LIMIT 1
            ";

            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("ii", $user_id, $session_id);
            $stmt->execute();

            $affected = $stmt->affected_rows;

            $stmt->close();

            if ($affected === 1) {

                header(
                    "Location: payment_parking.php?session_id=" .
                    $session_id
                );
                exit;

            } else {

                $message = "ไม่สามารถรับรายการนี้ได้ อาจมีผู้ใช้อื่นรับรายการไปแล้ว";
                $message_type = "error";
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| ถ้ามี session_id ให้แสดงรายละเอียดก่อน Claim
|--------------------------------------------------------------------------
*/

// โหลดรายละเอียด session มาแสดงในหน้าก่อนกดยืนยัน
$parking = null;

if ($session_id > 0) {

    $sql = "
        SELECT
            ps.id,
            ps.user_id,
            ps.slot_id,
            ps.checkin_time,
            ps.checkout_time,
            ps.duration_minutes,
            ps.fee,
            ps.status,
            p.slot_number
        FROM parking_sessions ps
        INNER JOIN parking_slots p
            ON p.id = ps.slot_id
        WHERE ps.id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $session_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $parking = $result->fetch_assoc();

    $stmt->close();
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Claim Parking</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Tahoma, sans-serif;
            background: #f4f6f9;
            color: #333;
        }

        .container {
            width: 90%;
            max-width: 600px;
            margin: 50px auto;
        }

        .card {
            background: #fff;
            border-radius: 14px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }

        h1 {
            margin-top: 0;
            text-align: center;
            color: #333;
        }

        .description {
            text-align: center;
            color: #666;
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }

        input[type="number"] {
            width: 100%;
            padding: 13px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 16px;
            margin-bottom: 15px;
        }

        button {
            width: 100%;
            border: none;
            border-radius: 8px;
            padding: 13px;
            font-size: 16px;
            cursor: pointer;
            background: #007bff;
            color: white;
        }

        button:hover {
            background: #0056b3;
        }

        .message {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .error {
            background: #ffe5e5;
            color: #b30000;
        }

        .success {
            background: #e5ffe9;
            color: #087a21;
        }

        .parking-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 18px;
            margin-top: 20px;
            margin-bottom: 20px;
        }

        .row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #ddd;
        }

        .row:last-child {
            border-bottom: none;
        }

        .value {
            font-weight: bold;
        }

        .fee {
            font-size: 24px;
            color: #008000;
        }

        .back {
            display: block;
            text-align: center;
            margin-top: 20px;
            text-decoration: none;
            color: #007bff;
        }

        .warning {
            background: #fff3cd;
            color: #856404;
            padding: 12px;
            border-radius: 8px;
            margin-top: 15px;
        }
    </style>
</head>

<body>

<div class="container">

    <div class="card">

        <h1>🚗 รับรายการจอดรถ</h1>

        <p class="description">
            สำหรับรถที่ไม่ได้ทำการจองล่วงหน้า
        </p>

        <?php if ($message !== ""): ?>

            <div class="message <?= htmlspecialchars($message_type) ?>">
                <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>


        <?php if (!$parking): ?>

            <!-- ยังไม่ได้กรอก Session ID -->

            <form method="GET">

                <label for="session_id">
                    Parking Session ID
                </label>

                <input
                    type="number"
                    name="session_id"
                    id="session_id"
                    min="1"
                    placeholder="เช่น 15"
                    required
                >

                <button type="submit">
                    ตรวจสอบรายการ
                </button>

            </form>

            <div class="warning">
                กรุณาใช้หมายเลข Parking Session
                ที่ระบบออกให้กับรถของคุณ
            </div>


        <?php else: ?>

            <!-- แสดงรายละเอียด Session -->

            <div class="parking-info">

                <div class="row">
                    <span>Session ID</span>
                    <span class="value">
                        #<?= (int)$parking['id'] ?>
                    </span>
                </div>

                <div class="row">
                    <span>ช่องจอด</span>
                    <span class="value">
                        ช่อง <?= (int)$parking['slot_number'] ?>
                    </span>
                </div>

                <div class="row">
                    <span>เวลาเข้า</span>
                    <span class="value">
                        <?= htmlspecialchars($parking['checkin_time']) ?>
                    </span>
                </div>

                <div class="row">
                    <span>เวลาออก</span>
                    <span class="value">
                        <?= htmlspecialchars($parking['checkout_time'] ?? '-') ?>
                    </span>
                </div>

                <div class="row">
                    <span>ระยะเวลาจอด</span>
                    <span class="value">
                        <?= (int)$parking['duration_minutes'] ?> นาที
                    </span>
                </div>

                <div class="row">
                    <span>ค่าจอด</span>
                    <span class="value fee">
                        <?= number_format((float)$parking['fee'], 2) ?> บาท
                    </span>
                </div>

            </div>


            <?php if ($parking['user_id'] === null && $parking['status'] === 'completed'): ?>

                <form method="POST">

                    <input
                        type="hidden"
                        name="session_id"
                        value="<?= (int)$parking['id'] ?>"
                    >

                    <button
                        type="submit"
                        name="claim_session"
                    >
                        ยืนยันว่าเป็นรถของฉัน
                    </button>

                </form>

            <?php elseif ($parking['user_id'] !== null): ?>

                <?php if ((int)$parking['user_id'] === $user_id): ?>

                    <div class="message success">
                        Parking Session นี้เป็นของบัญชีของคุณแล้ว
                    </div>

                    <a
                        class="back"
                        href="payment_parking.php?session_id=<?= (int)$parking['id'] ?>"
                    >
                        ไปหน้าชำระเงิน
                    </a>

                <?php else: ?>

                    <div class="message error">
                        Parking Session นี้ถูกผูกกับผู้ใช้อื่นแล้ว
                    </div>

                <?php endif; ?>

            <?php else: ?>

                <div class="message error">
                    รถยังไม่ออกจากช่องจอด
                </div>

            <?php endif; ?>

        <?php endif; ?>


        <a class="back" href="dashboard.php">
            ← กลับ Dashboard
        </a>

    </div>

</div>

</body>
</html>