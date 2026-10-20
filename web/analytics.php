
<?php
session_start();
require_once __DIR__ . '/db.php';
sp_require_admin_page($conn);
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Smart Parking | Analytics Dashboard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet">

    <style>
        :root {
            --bg: #0b1220;
            --panel: #111c2e;
            --panel-soft: #16243a;
            --border: #26364d;
            --text: #edf4ff;
            --muted: #93a4bb;
            --blue: #38bdf8;
            --purple: #a78bfa;
            --green: #34d399;
            --orange: #fbbf24;
            --red: #fb7185;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(ellipse at top left, #172d4b 0, transparent 42%),
                var(--bg);
            color: var(--text);
            font-family: "Segoe UI", Tahoma, sans-serif;
        }

        .topbar {
            background: rgba(11, 18, 32, .88);
            border-bottom: 1px solid var(--border);
            backdrop-filter: blur(12px);
        }

        .brand-icon {
            width: 44px;
            height: 44px;
            display: grid;
            place-items: center;
            border-radius: 14px;
            background: linear-gradient(135deg, #38bdf8, #6366f1);
            color: #fff;
            font-size: 1.4rem;
            box-shadow: 0 8px 24px #38bdf826;
        }

        .brand-title {
            font-weight: 800;
            letter-spacing: .2px;
        }

        .brand-subtitle,
        .muted {
            color: var(--muted);
        }

        .page-title {
            font-size: clamp(1.5rem, 3vw, 2rem);
            font-weight: 800;
            letter-spacing: -.5px;
        }

        .section-card,
        .metric-card {
            height: 100%;
            background: linear-gradient(145deg, var(--panel), #0f1929);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 12px 35px #00000015;
        }

        .metric-card {
            position: relative;
            overflow: hidden;
            padding: 20px;
            transition: transform .2s, border-color .2s;
        }

        .metric-card:hover {
            transform: translateY(-3px);
            border-color: #4c6587;
        }

        .metric-card::after {
            content: "";
            position: absolute;
            right: -25px;
            top: -35px;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: var(--accent);
            opacity: .08;
        }

        .metric-icon {
            display: grid;
            place-items: center;
            width: 46px;
            height: 46px;
            border-radius: 14px;
            color: var(--accent);
            background: color-mix(in srgb, var(--accent) 13%, transparent);
            font-size: 1.35rem;
        }

        .metric-label {
            color: var(--muted);
            font-size: .9rem;
        }

        .metric-value {
            margin-top: 8px;
            font-size: 2rem;
            line-height: 1.2;
            font-weight: 800;
            color: var(--text);
        }

        .section-card {
            padding: 20px;
        }

        .section-heading {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
            font-size: 1.05rem;
            font-weight: 750;
        }

        .section-heading i {
            color: var(--blue);
            font-size: 1.25rem;
        }

        .table {
            --bs-table-bg: transparent;
            --bs-table-color: var(--text);
            --bs-table-border-color: var(--border);
            margin-bottom: 0;
        }

        .table thead th {
            background: #18263b;
            color: #b9c9df;
            font-size: .82rem;
            font-weight: 700;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
            padding: 13px 12px;
        }

        .table tbody td {
            padding: 13px 12px;
            vertical-align: middle;
            font-size: .9rem;
        }

        .table tbody tr:hover td {
            background: #ffffff06;
        }

        .table-responsive {
            border-radius: 12px;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: .78rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-available {
            color: #6ee7b7;
            background: #064e3b66;
        }

        .status-reserved {
            color: #fcd34d;
            background: #78350f66;
        }

        .status-occupied {
            color: #fda4af;
            background: #88133766;
        }

        .prediction-number {
            font-size: 2rem;
            font-weight: 800;
        }

        .prediction-box {
            padding: 15px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: #ffffff04;
        }

        .confidence-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 12px;
            border-radius: 999px;
            color: #c4b5fd;
            background: #6d28d933;
            font-size: .82rem;
            font-weight: 700;
        }

        .alert-item {
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: #ffffff04;
        }

        .alert-item.critical {
            border-left: 3px solid var(--red);
        }

        .alert-item.warning {
            border-left: 3px solid var(--orange);
        }

        .alert-item.info {
            border-left: 3px solid var(--blue);
        }

        .severity-badge {
            font-size: .73rem;
            font-weight: 700;
            padding: 5px 8px;
            border-radius: 7px;
        }

        .severity-critical {
            background: #7f1d1d66;
            color: #fda4af;
        }

        .severity-warning {
            background: #78350f66;
            color: #fcd34d;
        }

        .severity-info {
            background: #07598566;
            color: #7dd3fc;
        }

        .btn-back {
            color: #dbeafe;
            border: 1px solid #344760;
            background: #ffffff06;
        }

        .btn-back:hover {
            color: #fff;
            border-color: var(--blue);
            background: #38bdf81a;
        }

        .btn-refresh {
            border: 0;
            color: #071525;
            font-weight: 750;
            background: linear-gradient(135deg, #38bdf8, #818cf8);
        }

        .btn-refresh:hover {
            color: #071525;
            filter: brightness(1.08);
        }

        .btn-refresh:disabled {
            opacity: .65;
        }

        .live-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            margin-right: 6px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 12px #34d39988;
        }

        .footer-note {
            border-top: 1px solid var(--border);
            color: var(--muted);
        }

        .empty-state {
            padding: 20px !important;
            color: var(--muted) !important;
            text-align: center;
        }

        .error-alert {
            color: #fecdd3;
            background: #4c1525;
            border: 1px solid #9f354e;
            border-radius: 14px;
        }

        .loading {
            color: var(--muted);
        }

        @media (max-width: 576px) {
            .section-card {
                padding: 14px;
            }

            .metric-card {
                padding: 15px;
            }

            .metric-value {
                font-size: 1.65rem;
            }

            .topbar .container {
                gap: 12px;
            }
        }
    </style>
</head>

<body>

<nav class="topbar py-3">
    <div class="container d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="brand-icon">
                <i class="bi bi-p-square-fill"></i>
            </div>
            <div>
                <div class="brand-title">SMART PARKING</div>
                <div class="brand-subtitle small">Management & Analytics</div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-back rounded-pill px-3"
               href="admin_dashboard.php">
                <i class="bi bi-arrow-left me-1"></i>
                กลับหน้า Admin
            </a>

            <button class="btn btn-refresh rounded-pill px-3"
                    id="refresh" type="button">
                <i class="bi bi-arrow-clockwise me-1" id="refreshIcon"></i>
                <span id="refreshText">รีเฟรชข้อมูล</span>
            </button>
        </div>
    </div>
</nav>

<main class="container py-4 py-lg-5">

    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <div class="small text-uppercase fw-bold mb-2"
                 style="color: var(--blue); letter-spacing: 2px;">
                <i class="bi bi-bar-chart-line me-1"></i>
                ANALYTICS OVERVIEW
            </div>

            <h1 class="page-title mb-2">
                สถิติและการคาดการณ์
            </h1>

            <div class="muted">
                วิเคราะห์การเข้า–ออก การใช้ช่องจอด และแนวโน้มในชั่วโมงถัดไป
            </div>
        </div>

        <div class="muted small pb-1">
            <span class="live-dot"></span>
            <span id="connectionStatus">รอโหลดข้อมูล</span>
        </div>
    </div>

    <div id="error" class="alert error-alert d-none mb-4" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <span id="errorMessage"></span>
    </div>

    <div class="row g-3 mb-4" id="metrics">
        <div class="col-12">
            <div class="section-card loading">
                <span class="spinner-border spinner-border-sm me-2"></span>
                กำลังโหลดข้อมูลสถิติ...
            </div>
        </div>
    </div>

    <div class="row g-4">

        <div class="col-lg-8">

            <section class="section-card mb-4">
                <div class="section-heading">
                    <i class="bi bi-clock-history"></i>
                    จำนวนรถเข้า–ออกย้อนหลัง 24 ชั่วโมง
                </div>

                <div class="muted small mb-3">
                    แสดงจำนวนรายการเข้าและออกตามชั่วโมงจากฐานข้อมูล
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                        <tr>
                            <th>ชั่วโมง</th>
                            <th>รถเข้า</th>
                            <th>รถออก</th>
                            <th>ช่องที่มีรถเข้า</th>
                        </tr>
                        </thead>
                        <tbody id="hourly">
                        <tr><td colspan="4" class="empty-state">กำลังโหลด...</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="section-card">
                <div class="section-heading">
                    <i class="bi bi-p-square"></i>
                    สถิติการใช้ช่องจอด
                </div>

                <div class="muted small mb-3">
                    สถานะปัจจุบันและจำนวนครั้งที่มีการเข้าใช้ช่องจอด
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                        <tr>
                            <th>ช่องจอด</th>
                            <th>สถานะ</th>
                            <th>เข้า 24 ชม.</th>
                            <th>รวมทั้งหมด</th>
                            <th>เข้าล่าสุด</th>
                        </tr>
                        </thead>
                        <tbody id="slots">
                        <tr><td colspan="5" class="empty-state">กำลังโหลด...</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

        </div>

        <div class="col-lg-4">

            <section class="section-card mb-4">
                <div class="section-heading">
                    <i class="bi bi-graph-up-arrow"></i>
                    คาดการณ์ชั่วโมงถัดไป
                </div>

                <div id="prediction" class="loading">
                    <span class="spinner-border spinner-border-sm me-2"></span>
                    กำลังคำนวณ...
                </div>
            </section>

            <section class="section-card">
                <div class="section-heading">
                    <i class="bi bi-bell"></i>
                    การแจ้งเตือนที่ยังไม่อ่าน
                    <span class="badge rounded-pill ms-auto"
                          id="alertCount"
                          style="background:#7f1d1d;color:#fda4af;">0</span>
                </div>

                <div id="alerts" class="d-grid gap-3">
                    <span class="muted small">กำลังโหลด...</span>
                </div>
            </section>

        </div>
    </div>

    <div class="footer-note mt-4 pt-3 d-flex flex-wrap justify-content-between gap-2">
        <span class="small">
            <i class="bi bi-database me-1"></i>
            แหล่งข้อมูล: Smart Parking Database
        </span>
        <span class="small" id="updated">
            ข้อมูลล่าสุด: —
        </span>
    </div>

</main>

<script>
'use strict';

const $ = (id) => document.getElementById(id);

const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
}[char]));

const numberFormat = (value) => {
    const number = Number(value ?? 0);
    return Number.isFinite(number) ? number.toLocaleString('th-TH') : '0';
};

function statusInfo(status) {
    const normalized = String(status ?? '').toLowerCase();

    const map = {
        available: { label: 'ว่าง', className: 'status-available', icon: 'bi-check-circle-fill' },
        reserved: { label: 'จองแล้ว', className: 'status-reserved', icon: 'bi-bookmark-check-fill' },
        occupied: { label: 'มีรถจอด', className: 'status-occupied', icon: 'bi-car-front-fill' }
    };

    return map[normalized] || {
        label: status || 'ไม่ทราบ',
        className: '',
        icon: 'bi-question-circle'
    };
}

async function loadAnalytics() {
    const refreshButton = $('refresh');
    const refreshText = $('refreshText');
    const refreshIcon = $('refreshIcon');

    $('error').classList.add('d-none');
    $('errorMessage').textContent = '';

    refreshButton.disabled = true;
    refreshText.textContent = 'กำลังโหลด...';
    refreshIcon.classList.add('spin-icon');
    $('connectionStatus').textContent = 'กำลังเชื่อมต่อฐานข้อมูล';

    try {
        const response = await fetch('api_analytics.php', {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        });

        const responseText = await response.text();
        let data;

        try {
            data = JSON.parse(responseText);
        } catch (_) {
            throw new Error(
                'API ไม่ได้ส่ง JSON ที่ถูกต้อง กรุณาตรวจสอบ api_analytics.php และ PHP error'
            );
        }

        if (!response.ok) {
            throw new Error(data.message || ('HTTP Error ' + response.status));
        }

        if (data.status !== 'success') {
            throw new Error(data.message || 'API ไม่สามารถโหลดข้อมูลได้');
        }

        renderMetrics(data);
        renderHourly(data.hourly || []);
        renderSlots(data.slot_usage || []);
        renderPrediction(data.prediction || {}, data.note || '');
        renderAlerts(data.alerts || []);

        $('updated').textContent =
            'อัปเดตล่าสุด: ' + (data.generated_at || '—');

        $('connectionStatus').textContent = 'เชื่อมต่อฐานข้อมูลแล้ว';

    } catch (error) {
        console.error('Smart Parking Analytics:', error);

        $('errorMessage').textContent = 'โหลดข้อมูลไม่สำเร็จ: ' + error.message;
        $('error').classList.remove('d-none');
        $('connectionStatus').textContent = 'เกิดข้อผิดพลาดในการเชื่อมต่อ';

        $('metrics').innerHTML = `
            <div class="col-12">
                <div class="section-card">
                    <i class="bi bi-exclamation-circle me-2" style="color:var(--red)"></i>
                    ไม่สามารถโหลดสถิติได้ กรุณาตรวจสอบข้อความผิดพลาดด้านบน
                </div>
            </div>`;
    } finally {
        refreshButton.disabled = false;
        refreshText.textContent = 'รีเฟรชข้อมูล';
        refreshIcon.classList.remove('spin-icon');
    }
}

function renderMetrics(data) {
    const slots = data.slots || {};
    const alerts = data.alerts || [];

    const metrics = [
        {
            label: 'รถจอดอยู่',
            value: slots.occupied,
            icon: 'bi-car-front-fill',
            color: 'var(--red)',
            hint: 'ช่องที่มีรถใช้งาน'
        },
        {
            label: 'ช่องว่าง',
            value: slots.available,
            icon: 'bi-p-square',
            color: 'var(--green)',
            hint: 'ช่องที่พร้อมใช้งาน'
        },
        {
            label: 'ช่องจอง',
            value: slots.reserved,
            icon: 'bi-bookmark-check-fill',
            color: 'var(--orange)',
            hint: 'ช่องที่มีการจอง'
        },
        {
            label: 'แจ้งเตือน',
            value: alerts.length,
            icon: 'bi-bell-fill',
            color: 'var(--purple)',
            hint: 'รายการที่ API ส่งกลับมา'
        }
    ];

    $('metrics').innerHTML = metrics.map((item) => `
        <div class="col-6 col-xl-3">
            <div class="metric-card" style="--accent:${item.color}">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="metric-label">${esc(item.label)}</div>
                    <div class="metric-icon">
                        <i class="bi ${esc(item.icon)}"></i>
                    </div>
                </div>
                <div class="metric-value">${numberFormat(item.value)}</div>
                <div class="muted small mt-2">${esc(item.hint)}</div>
            </div>
        </div>
    `).join('');

    $('alertCount').textContent = numberFormat(alerts.length);
}

function renderHourly(hourly) {
    const tbody = $('hourly');

    if (!hourly.length) {
        tbody.innerHTML =
            '<tr><td colspan="4" class="empty-state">ยังไม่มีข้อมูลรถเข้า–ออก</td></tr>';
        return;
    }

    tbody.innerHTML = hourly.map((item) => {
        const usage = item.slot_usage || {};

        const usedSlots = Object.keys(usage)
            .filter((key) => Number(usage[key]) > 0)
            .map((key) => 'ช่อง ' + key + ' (' + numberFormat(usage[key]) + ')')
            .join(', ') || '—';

        return `
            <tr>
                <td class="text-nowrap">${esc(item.hour_start || '—')}</td>
                <td><span style="color:var(--green);font-weight:750">
                    <i class="bi bi-box-arrow-in-right me-1"></i>
                    ${numberFormat(item.entry_count)}
                </span></td>
                <td><span style="color:var(--orange);font-weight:750">
                    <i class="bi bi-box-arrow-right me-1"></i>
                    ${numberFormat(item.exit_count)}
                </span></td>
                <td>${esc(usedSlots)}</td>
            </tr>`;
    }).join('');
}

function renderSlots(slots) {
    const tbody = $('slots');

    if (!slots.length) {
        tbody.innerHTML =
            '<tr><td colspan="5" class="empty-state">ยังไม่มีข้อมูลช่องจอด</td></tr>';
        return;
    }

    tbody.innerHTML = slots.map((slot) => {
        const status = statusInfo(slot.status);

        return `
            <tr>
                <td class="fw-bold">P-${esc(slot.slot_number ?? '—')}</td>
                <td>
                    <span class="status-pill ${esc(status.className)}">
                        <i class="bi ${esc(status.icon)}"></i>
                        ${esc(status.label)}
                    </span>
                </td>
                <td>${numberFormat(slot.sessions_last_24h)}</td>
                <td>${numberFormat(slot.total_sessions)}</td>
                <td class="small text-nowrap">${esc(slot.last_entry_time || '—')}</td>
            </tr>`;
    }).join('');
}

function renderPrediction(prediction, note) {
    const confidenceLabels = {
        insufficient_data: 'ข้อมูลยังไม่เพียงพอ',
        low: 'ความเชื่อมั่นต่ำ',
        medium: 'ความเชื่อมั่นปานกลาง',
        high: 'ความเชื่อมั่นสูง'
    };

    const confidence =
        confidenceLabels[prediction.confidence] ||
        prediction.confidence ||
        'ไม่ทราบ';

    const insufficient = prediction.confidence === 'insufficient_data';

    $('prediction').innerHTML = `
        <div class="muted small mb-3">
            <i class="bi bi-calendar3 me-1"></i>
            เป้าหมาย: ${esc(prediction.target_hour || 'ไม่มีข้อมูลเวลา')}
        </div>

        <div class="prediction-box mb-3">
            <div class="muted small mb-1">รถเข้าที่คาดการณ์</div>
            <div class="prediction-number" style="color:var(--green)">
                ${numberFormat(prediction.predicted_entries)}
                <span class="fs-6 fw-normal">คัน</span>
            </div>
        </div>

        <div class="prediction-box mb-3">
            <div class="muted small mb-1">รถออกที่คาดการณ์</div>
            <div class="prediction-number" style="color:var(--orange)">
                ${numberFormat(prediction.predicted_exits)}
                <span class="fs-6 fw-normal">คัน</span>
            </div>
        </div>

        <div class="confidence-pill mb-3">
            <i class="bi ${insufficient ? 'bi-info-circle' : 'bi-activity'}"></i>
            ${esc(confidence)}
        </div>

        <div class="muted small mb-3">
            จำนวนตัวอย่างย้อนหลัง ${numberFormat(prediction.sample_count)} วัน
        </div>

        <div class="small muted">
            ${esc(note || 'ค่าคาดการณ์อ้างอิงจากข้อมูลย้อนหลังที่มีอยู่')}
        </div>`;
}

function renderAlerts(alerts) {
    const container = $('alerts');

    if (!alerts.length) {
        container.innerHTML = `
            <div class="text-center py-4">
                <i class="bi bi-shield-check fs-2 d-block mb-2"
                   style="color:var(--green)"></i>
                <div>ไม่มีการแจ้งเตือนใหม่</div>
                <div class="muted small mt-1">ระบบไม่พบรายการแจ้งเตือนจาก API</div>
            </div>`;
        return;
    }

    container.innerHTML = alerts.map((alert) => {
        const severity = String(alert.severity || 'info').toLowerCase();
        const allowed = ['critical', 'warning', 'info'].includes(severity)
            ? severity : 'info';

        const labels = {
            critical: 'วิกฤต',
            warning: 'คำเตือน',
            info: 'ข้อมูล'
        };

        return `
            <div class="alert-item ${allowed}">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                    <span class="severity-badge severity-${allowed}">
                        ${esc(labels[allowed])}
                    </span>
                    <span class="muted small">${esc(alert.created_at || '')}</span>
                </div>
                <div class="fw-bold mb-1">
                    ${esc(alert.alert_type || 'แจ้งเตือนระบบ')}
                </div>
                <div class="small muted">
                    ${esc(alert.message || 'ไม่มีรายละเอียด')}
                </div>
            </div>`;
    }).join('');
}

$('refresh').addEventListener('click', loadAnalytics);

loadAnalytics();
</script>

</body>
</html>
