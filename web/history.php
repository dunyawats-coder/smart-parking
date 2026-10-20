<?php
// [อธิบาย] ประวัติการจองและการเข้าจอด (เฉพาะ Admin): รวมการจองทั้งหมด + รถที่เข้าจอดโดยไม่ได้จอง แล้วแสดงเป็นตาราง
// history.php - ประวัติการจองและการเข้าจอด (เฉพาะ Admin)
session_start();
require_once "db.php";
sp_require_admin_page($conn);

$rows = [];

/* 1) การจองทั้งหมด พร้อมข้อมูลการเข้าจอดที่ผูกกับการจองนั้น */
$res = $conn->query("
    SELECT r.id, r.status, r.created_at, r.reserve_date, r.reserve_time, r.slot_id,
           NULL AS license_plate,
           COALESCE(u.full_name, u.username) AS user_name, u.phone, p.slot_number,
           ps.id AS session_id, ps.checkin_time, ps.checkout_time, ps.fee,
           'reservation' AS kind
    FROM reservations r
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN parking_slots p ON r.slot_id = p.id
    LEFT JOIN parking_sessions ps ON ps.id = (
        SELECT x.id FROM parking_sessions x
        WHERE x.slot_id = r.slot_id AND x.user_id = r.user_id
        ORDER BY x.id DESC LIMIT 1
    )
");
while ($r = $res->fetch_assoc()) {
    $st = strtolower($r['status'] ?? 'pending');
    $r['start'] = $r['checkin_time'] ?: trim(($r['reserve_date'] ?? '') . ' ' . ($r['reserve_time'] ?? '')) ?: $r['created_at'];
    $r['end']   = $r['checkout_time'];
    // จำนวนเงิน: ค่าจอง (ถ้าไม่ถูกยกเลิก) + ค่าจอดที่คิดจากการเข้าจอดจริง
    $r['amount'] = ($st === 'cancelled' || $st === 'canceled' ? 0 : SP_BOOKING_FEE) + (float)($r['fee'] ?? 0);
    $rows[] = $r;
}

/* 2) รถที่เข้าจอดโดยไม่ได้จอง (Drive-in) */
$res = $conn->query("
    SELECT ps.id AS session_id, ps.status AS ps_status, ps.vehicle_plate AS license_plate,
           ps.checkin_time, ps.checkout_time, ps.fee,
           COALESCE(u.full_name, u.username) AS user_name, u.phone, p.slot_number, ps.slot_id
    FROM parking_sessions ps
    LEFT JOIN users u ON ps.user_id = u.id
    LEFT JOIN parking_slots p ON ps.slot_id = p.id
    WHERE ps.user_id IS NULL
");
while ($r = $res->fetch_assoc()) {
    $r['id']      = $r['session_id'];
    $r['kind']    = 'session';
    $r['status']  = $r['ps_status'] === 'parking' ? 'occupied' : 'completed';
    $r['start']   = $r['checkin_time'];
    $r['end']     = $r['checkout_time'];
    $r['amount']  = (float)$r['fee'];
    $r['created_at'] = $r['checkin_time'];
    $rows[] = $r;
}

usort($rows, function ($a, $b) {
    return strcmp((string)($b['start'] ?? ''), (string)($a['start'] ?? ''));
});
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Parking - ประวัติการจองทั้งหมด</title>
    <!-- Google Fonts & Bootstrap 5 & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <style>
        :root {
            --bg-main: #0b0f19;
            --card-bg: rgba(22, 30, 46, 0.85);
            --card-border: rgba(255, 255, 255, 0.08);
            --accent-cyan: #00f2fe;
            --accent-blue: #4facfe;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
        }

        body {
            background: radial-gradient(circle at top right, #111827, #0b0f19, #05070d);
            color: var(--text-primary);
            font-family: 'Prompt', sans-serif;
            min-height: 100vh;
        }

        .glass-nav {
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--card-border);
        }

        .glass-card {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--card-border);
            border-radius: 16px;
        }

        .stat-card {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 1.25rem;
        }

        .table-custom {
            color: var(--text-primary);
            vertical-align: middle;
        }

        .table-custom tbody tr {
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            transition: background 0.2s ease;
        }

        .table-custom tbody tr:hover {
            background: rgba(255, 255, 255, 0.03);
        }

        .badge-status {
            font-size: 0.78rem;
            font-weight: 500;
            padding: 6px 12px;
            border-radius: 20px;
        }

        .form-control, .form-select {
            background-color: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #fff;
        }

        .form-control:focus, .form-select:focus {
            background-color: rgba(15, 23, 42, 0.9);
            border-color: var(--accent-cyan);
            color: #fff;
            box-shadow: 0 0 10px rgba(0, 242, 254, 0.25);
        }

        @media print {
            body { background: #fff; color: #000; }
            .glass-nav, .no-print { display: none !important; }
            .glass-card { border: none; background: transparent; }
            .table-custom { color: #000; }
        }
    </style>
</head>
<body>

<!-- 🌐 Navigation Bar -->
<nav class="navbar navbar-expand-lg glass-nav sticky-top py-3 no-print">
    <div class="container-fluid px-4">
        <a class="navbar-brand d-flex align-items-center gap-2 text-white fw-bold fs-4" href="admin_dashboard.php">
            <div class="p-2 rounded-3 bg-gradient text-white d-flex align-items-center justify-content-center" style="background: linear-gradient(135deg, var(--accent-cyan), var(--accent-blue)); width: 38px; height: 38px;">
                <i class="bi bi-clock-history fs-5 text-dark"></i>
            </div>
            <span>Smart Parking <span class="fs-6 text-info fw-normal">| Booking History Logs</span></span>
        </a>
        <div class="d-flex align-items-center gap-2 gap-md-3">
            <a href="admin_dashboard.php" class="btn btn-sm btn-outline-info rounded-pill px-3">
                <i class="bi bi-speedometer2 me-1"></i> Dashboard
            </a>
            <a href="admin_payment_approval.php" class="btn btn-sm btn-outline-warning rounded-pill px-3">
                <i class="bi bi-shield-check me-1"></i> อนุมัติชำระเงิน
            </a>
            <a href="logout.php" class="btn btn-sm btn-outline-danger rounded-pill px-3 d-flex align-items-center gap-1" onclick="return confirm('คุณต้องการออกจากระบบใช่หรือไม่?');">
                <i class="bi bi-box-arrow-right"></i>
                <span>ออกจากระบบ</span>
            </a>
        </div>
    </div>
</nav>

<!-- 📊 Main Content -->
<div class="container-fluid px-4 py-4">

    <!-- Header Section -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
        <div>
            <h3 class="fw-bold mb-1 text-white"><i class="bi bi-journal-text text-info me-2"></i>ประวัติการจองและบันทึกการเข้าจอดทั้งหมด</h3>
            <p class="text-secondary mb-0 small">ตรวจสอบย้อนหลัง ข้อมูลผู้ใช้งาน เวลาเข้า-ออก และสถานะการชำระเงินในระบบ</p>
        </div>
        <div class="d-flex gap-2 no-print">
            <button class="btn btn-outline-light btn-sm rounded-pill px-3" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> พิมพ์รายงาน
            </button>
        </div>
    </div>

    <!-- 📈 Summary Cards -->
    <div class="row g-3 mb-4 no-print">
        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="p-3 bg-info bg-opacity-10 text-info rounded-4 fs-3"><i class="bi bi-receipt"></i></div>
                <div>
                    <span class="text-secondary small d-block">รายการจองทั้งหมด</span>
                    <h3 class="fw-bold mb-0 text-white" id="total-records">0</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="p-3 bg-success bg-opacity-10 text-success rounded-4 fs-3"><i class="bi bi-check-circle-fill"></i></div>
                <div>
                    <span class="text-secondary small d-block">เสร็จสิ้น / กำลังจอด</span>
                    <h3 class="fw-bold mb-0 text-white" id="completed-records">0</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="p-3 bg-warning bg-opacity-10 text-warning rounded-4 fs-3"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <span class="text-secondary small d-block">รอตรวจสอบ / รอเข้าจอด</span>
                    <h3 class="fw-bold mb-0 text-white" id="pending-records">0</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="p-3 bg-danger bg-opacity-10 text-danger rounded-4 fs-3"><i class="bi bi-x-circle-fill"></i></div>
                <div>
                    <span class="text-secondary small d-block">ยกเลิกรายการ</span>
                    <h3 class="fw-bold mb-0 text-white" id="cancelled-records">0</h3>
                </div>
            </div>
        </div>
    </div>

    <!-- 🔍 Filter Controls -->
    <div class="glass-card p-3 mb-4 no-print">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-transparent border-secondary text-secondary"><i class="bi bi-search"></i></span>
                    <input type="text" id="searchInput" class="form-control" placeholder="ค้นหาทะเบียนรถ, ชื่อผู้ใช้, เบอร์โทร...">
                </div>
            </div>
            <div class="col-md-3">
                <select id="statusFilter" class="form-select">
                    <option value="">-- แสดงสถานะทั้งหมด --</option>
                    <option value="completed">เสร็จสิ้น (Completed)</option>
                    <option value="occupied">กำลังจอด (Occupied)</option>
                    <option value="reserved">อนุมัติแล้ว/รอเข้าจอด (Reserved)</option>
                    <option value="pending">รออนุมัติ (Pending)</option>
                    <option value="cancelled">ยกเลิก (Cancelled)</option>
                </select>
            </div>
            <div class="col-md-3">
                <input type="date" id="dateFilter" class="form-control">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-outline-secondary w-100" onclick="resetFilters()">รีเซ็ต</button>
            </div>
        </div>
    </div>

    <!-- 📋 History Data Table -->
    <div class="glass-card p-4">
        <div class="table-responsive">
            <table class="table table-custom align-middle mb-0" id="historyTable">
                <thead>
                    <tr class="text-secondary border-bottom border-secondary border-opacity-25" style="font-size: 0.88rem;">
                        <th scope="col">#ID</th>
                        <th scope="col">ผู้จอง / เบอร์โทร</th>
                        <th scope="col">ช่องจอด</th>
                        <th scope="col">ทะเบียนรถ</th>
                        <th scope="col">เวลาเริ่มจอง</th>
                        <th scope="col">เวลาหมดอายุ/ออก</th>
                        <th scope="col" class="text-end">จำนวนเงิน</th>
                        <th scope="col" class="text-center">สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (count($rows) > 0):
                        foreach ($rows as $row):
                            $status = strtolower($row['status'] ?? 'pending');
                            $badgeClass = 'bg-secondary';
                            $statusText = $status;

                            if ($status === 'reserved') {
                                $badgeClass = 'bg-primary bg-opacity-20 text-primary border border-primary border-opacity-25';
                                $statusText = 'อนุมัติแล้ว/รอเข้าจอด';
                            } elseif ($status === 'occupied') {
                                $badgeClass = 'bg-danger bg-opacity-20 text-danger border border-danger border-opacity-25';
                                $statusText = 'กำลังจอด';
                            } elseif ($status === 'completed') {
                                $badgeClass = 'bg-success bg-opacity-20 text-success border border-success border-opacity-25';
                                $statusText = 'เสร็จสิ้น';
                            } elseif ($status === 'pending') {
                                $badgeClass = 'bg-warning bg-opacity-20 text-warning border border-warning border-opacity-25';
                                $statusText = 'รอตรวจสอบ';
                            } elseif ($status === 'cancelled' || $status === 'canceled') {
                                $status = 'cancelled';
                                $badgeClass = 'bg-danger bg-opacity-20 text-danger border border-danger border-opacity-25';
                                $statusText = 'ยกเลิก';
                            }
                            $idLabel = ($row['kind'] === 'session' ? 'S' : 'R') . str_pad($row['id'], 5, '0', STR_PAD_LEFT);
                            $startTs = $row['start'] ? strtotime($row['start']) : false;
                            $dateKey = $startTs ? date('Y-m-d', $startTs) : '';
                    ?>
                    <tr data-status="<?= sp_h($status) ?>" data-date="<?= sp_h($dateKey) ?>">
                        <td class="fw-semibold text-info">#<?= sp_h($idLabel) ?></td>
                        <td>
                            <div class="fw-bold text-white"><?= sp_h($row['user_name'] ?? 'Guest / ทั่วไป') ?></div>
                            <small class="text-secondary"><i class="bi bi-telephone me-1"></i><?= sp_h($row['phone'] ?? '-') ?></small>
                        </td>
                        <td>
                            <span class="badge bg-dark border border-secondary text-info px-2 py-1">
                                Slot <?= sp_h($row['slot_number'] ?? $row['slot_id']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-warning text-dark px-3 py-1 fs-6 fw-semibold">
                                <?= sp_h($row['license_plate'] ?: '-') ?>
                            </span>
                        </td>
                        <td class="small text-secondary">
                            <div><i class="bi bi-calendar-event me-1"></i><?= $startTs ? date('d/m/Y H:i', $startTs) : '-' ?></div>
                        </td>
                        <td class="small text-secondary">
                            <div><i class="bi bi-clock me-1"></i><?= !empty($row['end']) ? date('d/m/Y H:i', strtotime($row['end'])) : '-' ?></div>
                        </td>
                        <td class="text-end fw-bold text-white">
                            ฿<?= number_format((float)$row['amount'], 2) ?>
                        </td>
                        <td class="text-center">
                            <span class="badge-status <?= $badgeClass ?>"><?= sp_h($statusText) ?></span>
                        </td>
                    </tr>
                    <?php
                        endforeach;
                    else:
                    ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-secondary">
                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                            ไม่พบประวัติข้อมูลการจองในระบบ
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- 🛠️ JavaScript Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function updateCounters() {
        let total = 0, completed = 0, pending = 0, cancelled = 0;
        
        $('#historyTable tbody tr:visible').each(function() {
            if ($(this).find('td').length > 1) {
                total++;
                let status = $(this).data('status');
                if (status === 'completed' || status === 'occupied') completed++;
                else if (status === 'reserved') pending++;
                else if (status === 'pending') pending++;
                else if (status === 'cancelled' || status === 'rejected') cancelled++;
            }
        });

        $('#total-records').text(total);
        $('#completed-records').text(completed);
        $('#pending-records').text(pending);
        $('#cancelled-records').text(cancelled);
    }

    function filterData() {
        let search = $('#searchInput').val().toLowerCase();
        let status = $('#statusFilter').val().toLowerCase();
        let date = $('#dateFilter').val();

        $('#historyTable tbody tr').each(function() {
            let rowText = $(this).text().toLowerCase();
            let rowStatus = ($(this).data('status') || '').toString().toLowerCase();
            let rowDate = ($(this).data('date') || '').toString();

            let matchesSearch = rowText.indexOf(search) > -1;
            let matchesStatus = status === '' || rowStatus === status;
            let matchesDate = date === '' || rowDate === date;

            if (matchesSearch && matchesStatus && matchesDate) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });

        updateCounters();
    }

    function resetFilters() {
        $('#searchInput').val('');
        $('#statusFilter').val('');
        $('#dateFilter').val('');
        filterData();
    }

    $(document).ready(function() {
        $('#searchInput, #statusFilter, #dateFilter').on('keyup change', filterData);
        updateCounters();
    });
</script>
</body>
</html>