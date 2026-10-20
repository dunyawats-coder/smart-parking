<?php
// [อธิบาย] แดชบอร์ดแอดมิน (เฉพาะ Admin)
// JavaScript ด้านล่างดึง api_get_realtime.php เป็นระยะ (setInterval) เพื่อแสดงสถานะช่อง ผู้จอง/ผู้จอด ทะเบียน ระยะเซนเซอร์
// และตัวเลขแจ้งเตือนรายการรออนุมัติ; ปุ่มยกเลิกการจองเรียก api_cancel_booking.php
// ปุ่ม 'อนุมัติชำระเงิน' ไปที่ admin_payment_approval.php
// admin_dashboard.php

session_start();
require_once "db.php";

// อนุญาตเฉพาะ Admin
sp_require_admin_page($conn);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Smart Parking - Executive Multi-Cam Dashboard</title>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
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
            background: radial-gradient(
                circle at top right,
                #111827,
                #0b0f19,
                #05070d
            );
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

        .stat-card {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 1.25rem;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: rgba(0, 242, 254, 0.3);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        }

        .cam-feed-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            overflow: hidden;
            position: relative;
        }

        .cam-feed-wrapper {
            position: relative;
            background-color: #030712;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.12);
            aspect-ratio: 16 / 9;
        }

        .cam-feed-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            background: #030712;
        }

        .live-tag {
            position: absolute;
            top: 10px;
            left: 10px;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(6px);
            border: 1px solid rgba(239, 68, 68, 0.5);
            color: #f87171;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            z-index: 2;
        }

        .cam-action-btn {
            position: absolute;
            bottom: 8px;
            right: 8px;
            z-index: 5;

            background: rgba(0, 0, 0, 0.65);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            border-radius: 6px;
            padding: 5px 10px;
            font-size: 0.75rem;
            cursor: pointer;
        }

        .cam-action-btn:hover {
            background: rgba(0, 242, 254, 0.3);
            color: #fff;
        }

        .pulse-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            background-color: #ef4444;
            border-radius: 50%;
            margin-right: 5px;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% {
                opacity: 1;
                transform: scale(1);
            }

            50% {
                opacity: 0.4;
                transform: scale(1.2);
            }

            100% {
                opacity: 1;
                transform: scale(1);
            }
        }

        .glow-available {
            border-top: 4px solid #10b981 !important;
        }

        .glow-booked {
            border-top: 4px solid #f59e0b !important;
        }

        .glow-occupied {
            border-top: 4px solid #ef4444 !important;
        }

        .info-pill {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--card-border);
            border-radius: 10px;
            padding: 12px 14px;
        }

        .status-badge {
            font-size: 0.82rem;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 30px;
        }

        .ip-config-panel {
            background: rgba(15, 23, 42, 0.6);
            border: 1px dashed rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            padding: 12px 18px;
        }

        .view-btn-group .btn {
            border-radius: 20px;
            padding: 6px 18px;
            font-weight: 500;
            font-size: 0.88rem;
        }

        .btn-approval-nav {
            background: linear-gradient(
                135deg,
                var(--accent-cyan),
                var(--accent-blue)
            );

            color: #0b0f19;
            font-weight: 600;
            border: none;
            box-shadow: 0 0 12px rgba(0, 242, 254, 0.3);
        }

        .btn-approval-nav:hover {
            color: #000;
            transform: translateY(-1px);
            box-shadow: 0 0 18px rgba(0, 242, 254, 0.5);
        }

        /* สถานะกล้อง */
        .cam-offline {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            color: #64748b;
            background: #020617;
            font-size: 14px;
        }

        .camera-ip {
            font-size: 11px;
            color: #64748b;
            margin-top: 4px;
        }

    </style>
</head>

<body>

<!-- =========================================================
     NAVBAR
========================================================= -->

<nav class="navbar navbar-expand-lg glass-nav sticky-top py-3">

    <div class="container-fluid px-4">

        <a
            class="navbar-brand d-flex align-items-center gap-2 text-white fw-bold fs-4"
            href="admin_dashboard.php"
        >

            <div
                class="p-2 rounded-3 text-white d-flex align-items-center justify-content-center"
                style="
                    background: linear-gradient(
                        135deg,
                        var(--accent-cyan),
                        var(--accent-blue)
                    );
                    width:38px;
                    height:38px;
                "
            >
                <i class="bi bi-camera-video-fill fs-5 text-dark"></i>
            </div>

            <span>
                Smart Parking
                <span class="fs-6 text-info fw-normal">
                    | Central Security & Cam Monitor
                </span>
            </span>

        </a>


        <div class="d-flex flex-wrap align-items-center gap-2 gap-md-3">

    <!-- อนุมัติชำระเงิน -->
    <a
        href="admin_payment_approval.php"
        class="btn btn-sm btn-approval-nav rounded-pill px-3 py-1 d-flex align-items-center gap-1"
    >
        <i class="bi bi-shield-check fs-6"></i>
        <span>อนุมัติชำระเงิน</span>
        <span
            id="pending-badge"
            class="badge rounded-pill bg-danger d-none"
        >0</span>
    </a>

    <!-- สถิติและการคาดการณ์ -->
    <a
        href="analytics.php"
        class="btn btn-sm rounded-pill px-3 py-1 d-flex align-items-center gap-1"
        style="
            background: linear-gradient(135deg, #a78bfa, #6366f1);
            color: #fff;
            font-weight: 600;
            border: none;
        "
    >
        <i class="bi bi-graph-up-arrow fs-6"></i>
        <span>สถิติและการคาดการณ์</span>
    </a>

    <!-- ตั้งค่า IP กล้อง -->
    <button
        class="btn btn-sm btn-outline-info rounded-pill px-3"
        type="button"
        data-bs-toggle="collapse"
        data-bs-target="#camConfigCollapse"
        aria-expanded="false"
        aria-controls="camConfigCollapse"
    >
        <i class="bi bi-gear-fill me-1"></i>
        ตั้งค่า IP กล้อง
    </button>

    <!-- ประวัติการเข้าจอด -->
    <a
        href="history.php"
        class="btn btn-info text-dark fw-semibold btn-sm rounded-pill px-3"
    >
        <i class="bi bi-clock-history me-1"></i>
        ประวัติการเข้าจอด
    </a>

    <!-- ออกจากระบบ -->
    <a
        href="logout.php"
        class="btn btn-sm btn-outline-danger rounded-pill px-3 d-flex align-items-center gap-1"
        onclick="return confirm('คุณต้องการออกจากระบบใช่หรือไม่?');"
    >
        <i class="bi bi-box-arrow-right"></i>
        <span>ออกจากระบบ</span>
    </a>

</div>

    </div>

</nav>


<!-- =========================================================
     MAIN
========================================================= -->

<div class="container-fluid px-4 py-4">


    <!-- =====================================================
         CAMERA IP CONFIG
    ====================================================== -->

    <div class="collapse mb-4" id="camConfigCollapse">

        <div class="ip-config-panel">

            <h6 class="text-info fw-bold mb-3">

                <i class="bi bi-diagram-3-fill me-1"></i>

                กำหนด IP Address ของ ESP32-CAM

            </h6>


            <div class="row g-3 align-items-end">

                <!-- CAM1 -->

                <div class="col-md-3">

                    <label class="small text-secondary">
                        CAM SLOT 1
                    </label>

                    <input
                        type="text"
                        id="ip-cam-1"
                        class="form-control form-control-sm bg-dark text-white border-secondary"
                        value="http://192.168.1.129"
                    >

                </div>


                <!-- CAM2 -->

                <div class="col-md-3">

                    <label class="small text-secondary">
                        CAM SLOT 2
                    </label>

                    <input
                        type="text"
                        id="ip-cam-2"
                        class="form-control form-control-sm bg-dark text-white border-secondary"
                        value="http://192.168.1.102"
                    >

                </div>


                <!-- CAM3 -->

                <div class="col-md-3">

                    <label class="small text-secondary">
                        CAM SLOT 3
                    </label>

                    <input
                        type="text"
                        id="ip-cam-3"
                        class="form-control form-control-sm bg-dark text-white border-secondary"
                        value="http://192.168.1.103"
                    >

                </div>


                <div class="col-md-3">

                    <button
                        class="btn btn-info btn-sm w-100 fw-semibold"
                        onclick="updateCamURLs()"
                    >

                        <i class="bi bi-arrow-repeat me-1"></i>

                        อัปเดตวิดีโอสตรีม

                    </button>

                </div>

            </div>


            <div class="mt-2">

                <small class="text-secondary">

                    รูปแบบ:
                    <code>http://IP-ESP32-CAM</code>

                    ระบบจะเชื่อมต่อ Live Stream ที่

                    <code>:81/</code>

                </small>

            </div>

        </div>

    </div>


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">

        <div>

            <h3 class="fw-bold mb-1 text-white">

                ศูนย์มอนิเตอร์กล้องวงจรปิดและสถานะช่องจอด

            </h3>

            <p class="text-secondary mb-0 small">

                <i class="bi bi-broadcast text-info me-1"></i>

                เชื่อมต่อกล้องสตรีมสด Real-Time
                ร่วมกับระบบเซนเซอร์ตรวจจับช่องจอดอัตโนมัติ

            </p>

        </div>


        <div class="d-flex align-items-center gap-3">

            <div
                class="btn-group view-btn-group bg-dark p-1 rounded-pill border border-secondary border-opacity-25"
                role="group"
            >

                <button
                    type="button"
                    class="btn btn-outline-light text-white"
                    id="btn-view-all"
                    onclick="switchView('all')"
                >

                    <i class="bi bi-grid-3x3-gap-fill me-1"></i>

                    จอรวมกล้องทั้งหมด

                </button>


                <button
                    type="button"
                    class="btn btn-info text-dark active"
                    id="btn-view-slots"
                    onclick="switchView('slots')"
                >

                    <i class="bi bi-card-checklist me-1"></i>

                    รายละเอียดช่องจอด

                </button>

            </div>


            <div
                class="d-none d-xl-flex align-items-center gap-2 bg-dark bg-opacity-50 border border-secondary border-opacity-25 px-3 py-2 rounded-pill"
            >

                <span class="pulse-dot"></span>

                <small
                    class="text-secondary"
                    id="last-update"
                >
                    กำลังเชื่อมต่อ...
                </small>

            </div>

        </div>

    </div>


    <!-- =====================================================
         METRICS
    ====================================================== -->

    <div class="row g-3 mb-4">

        <div class="col-md-3">

            <div class="stat-card d-flex align-items-center gap-3">

                <div class="p-3 bg-success bg-opacity-10 text-success rounded-4 fs-3">

                    <i class="bi bi-check-circle-fill"></i>

                </div>

                <div>

                    <span class="text-secondary small d-block">
                        ช่องจอดว่าง
                    </span>

                    <h3
                        class="fw-bold mb-0 text-white"
                        id="cnt-available"
                    >
                        0
                    </h3>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <a
                href="admin_payment_approval.php"
                class="text-decoration-none"
            >

                <div class="stat-card d-flex align-items-center gap-3 border-warning border-opacity-50">

                    <div class="p-3 bg-warning bg-opacity-10 text-warning rounded-4 fs-3">

                        <i class="bi bi-bookmark-star-fill"></i>

                    </div>

                    <div>

                        <span class="text-secondary small d-block">
                            จองแล้ว (รอเข้าจอด)
                        </span>

                        <h3
                            class="fw-bold mb-0 text-white"
                            id="cnt-booked"
                        >
                            0
                        </h3>

                    </div>

                </div>

            </a>

        </div>


        <div class="col-md-3">

            <div class="stat-card d-flex align-items-center gap-3">

                <div class="p-3 bg-danger bg-opacity-10 text-danger rounded-4 fs-3">

                    <i class="bi bi-car-front-fill"></i>

                </div>

                <div>

                    <span class="text-secondary small d-block">
                        มีรถเข้าจอดแล้ว
                    </span>

                    <h3
                        class="fw-bold mb-0 text-white"
                        id="cnt-occupied"
                    >
                        0
                    </h3>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <a
                href="history.php"
                class="text-decoration-none"
            >

                <div class="stat-card d-flex align-items-center gap-3 border-info border-opacity-50">

                    <div class="p-3 bg-info bg-opacity-10 text-info rounded-4 fs-3">

                        <i class="bi bi-journal-check"></i>

                    </div>

                    <div>

                        <span class="text-secondary small d-block">
                            ประวัติการจองทั้งหมด
                        </span>

                        <h6 class="fw-bold mb-0 text-info mt-1">

                            <i class="bi bi-clock-history me-1"></i>

                            ดูรายงานประวัติ

                        </h6>

                    </div>

                </div>

            </a>

        </div>

    </div>


    <!-- =====================================================
         ALL CAMS
    ====================================================== -->

    <div
        id="section-all-cams"
        class="mb-4"
        style="display:none;"
    >

        <div class="d-flex justify-content-between align-items-center mb-3">

            <h5 class="text-info fw-bold mb-0">

                <i class="bi bi-camera-video me-2"></i>

                จอมอนิเตอร์กล้องทั้งหมด

            </h5>

        </div>


        <div
            class="row g-4"
            id="all-cams-container"
        >
        </div>

    </div>


    <!-- =====================================================
         SLOT VIEW
    ====================================================== -->

    <div
        id="section-slots-view"
        class="mb-4"
    >

        <div class="d-flex justify-content-between align-items-center mb-3">

            <h5 class="text-info fw-bold mb-0">

                <i class="bi bi-ui-checks-grid me-2"></i>

                จัดการข้อมูลการจองและสถานะเซนเซอร์แยกตาม Slot

            </h5>

        </div>


        <div
            class="row g-4"
            id="slots-container"
        >
        </div>

    </div>

</div>


<!-- =========================================================
     CAMERA MODAL
========================================================= -->

<div
    class="modal fade"
    id="camModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-xl modal-dialog-centered">

        <div class="modal-content bg-dark border-secondary text-white">

            <div class="modal-header border-secondary">

                <h5
                    class="modal-title"
                    id="camModalTitle"
                >

                    <i class="bi bi-camera-video-fill me-2 text-info"></i>

                    Camera Live Stream

                </h5>

                <button
                    type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <div class="modal-body p-0 text-center bg-black">

                <img
                    id="camModalImg"
                    src=""
                    class="img-fluid w-100"
                    style="
                        max-height:80vh;
                        object-fit:contain;
                    "
                >

            </div>


            <div class="modal-footer border-secondary justify-content-between">

                <span class="small text-secondary">

                    <i class="bi bi-circle-fill text-danger me-1"></i>

                    Live Feed

                </span>

                <button
                    type="button"
                    class="btn btn-secondary btn-sm"
                    data-bs-dismiss="modal"
                >
                    ปิดหน้าต่าง
                </button>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>


<script>

/* =========================================================
   ESP32-CAM IP
========================================================= */

let camBaseURLs = {

    1: "http://192.168.1.129",

    2: "http://192.168.1.102",

    3: "http://192.168.1.103"

};


/* =========================================================
   สร้าง URL LIVE STREAM
========================================================= */

function getStreamURL(slotId) {

    let base = camBaseURLs[slotId];

    if (!base) {
        return "";
    }

    base = base.trim();

    // เอา / ด้านท้ายออก
    base = base.replace(/\/+$/, "");

    /*
       ESP32-CAM Live Stream
       ใช้ port 81

       สำคัญ:
       โค้ด ESP32-CAM ของเราที่เป็น WiFiServer
       ใช้ :81/
    */

    return base + ":81/";

}


/* =========================================================
   เปลี่ยน IP กล้อง
========================================================= */

function updateCamURLs() {

    camBaseURLs[1] =
        $('#ip-cam-1').val().trim();

    camBaseURLs[2] =
        $('#ip-cam-2').val().trim();

    camBaseURLs[3] =
        $('#ip-cam-3').val().trim();


    // เปลี่ยนเฉพาะ src ของกล้อง
    // ไม่โหลด Dashboard ใหม่
    // เพื่อไม่ให้ Live Stream กระตุก

    for (let i = 1; i <= 3; i++) {

        let url = getStreamURL(i);

        $('#cam-grid-img-' + i).attr('src', url);

        $('#cam-slot-img-' + i).attr('src', url);

    }


    alert('อัปเดต IP กล้องเรียบร้อยแล้ว');

}


/* =========================================================
   SWITCH VIEW
========================================================= */

function switchView(mode) {

    if (mode === 'all') {

        $('#section-all-cams').show();

        $('#section-slots-view').hide();

        $('#btn-view-all')
            .addClass('btn-info text-dark')
            .removeClass('btn-outline-light text-white');

        $('#btn-view-slots')
            .addClass('btn-outline-light text-white')
            .removeClass('btn-info text-dark');

    }

    else {

        $('#section-all-cams').hide();

        $('#section-slots-view').show();

        $('#btn-view-slots')
            .addClass('btn-info text-dark')
            .removeClass('btn-outline-light text-white');

        $('#btn-view-all')
            .addClass('btn-outline-light text-white')
            .removeClass('btn-info text-dark');

    }

}


/* =========================================================
   FULLSCREEN CAMERA
========================================================= */

function openCamModal(slotId) {

    let streamUrl = getStreamURL(slotId);

    $('#camModalTitle').html(
        '<i class="bi bi-camera-video-fill me-2 text-info"></i>' +
        'กล้องวงจรปิด Slot #' +
        slotId +
        ' - Live Feed'
    );

    $('#camModalImg').attr(
        'src',
        streamUrl
    );


    let modal =
        new bootstrap.Modal(
            document.getElementById('camModal')
        );

    modal.show();

}


/* =========================================================
   สร้าง CAMERA HTML ครั้งแรกเท่านั้น
========================================================= */

let dashboardInitialized = false;


function createCameraHTML(slotId) {

    let streamUrl =
        getStreamURL(slotId);


    return `

        <div class="cam-feed-wrapper">

            <div class="live-tag">

                <span class="pulse-dot"></span>

                LIVE

            </div>


            <img
                id="cam-grid-img-${slotId}"
                src="${streamUrl}"
                alt="Camera Feed Slot ${slotId}"
                onerror="cameraOffline(this, ${slotId})"
            >


            <button
                class="cam-action-btn"
                onclick="openCamModal(${slotId})"
            >

                <i class="bi bi-arrows-angle-expand me-1"></i>

                Fullscreen

            </button>

        </div>

    `;

}


function createSlotCameraHTML(slotId) {

    let streamUrl =
        getStreamURL(slotId);


    return `

        <div class="cam-feed-wrapper mb-3">

            <div class="live-tag">

                <span class="pulse-dot"></span>

                CAM SLOT ${slotId}

            </div>


            <img
                id="cam-slot-img-${slotId}"
                src="${streamUrl}"
                alt="Camera Slot ${slotId}"
                onerror="cameraOffline(this, ${slotId})"
            >


            <button
                class="cam-action-btn"
                onclick="openCamModal(${slotId})"
            >

                <i class="bi bi-arrows-angle-expand me-1"></i>

                Fullscreen

            </button>

        </div>

    `;

}


/* =========================================================
   CAMERA OFFLINE
========================================================= */

function cameraOffline(img, slotId) {

    /*
       อย่าเปลี่ยน src ซ้ำ
       เพราะจะทำให้เกิด loop onerror
    */

    img.onerror = null;

    img.style.display = "none";

    let parent =
        img.parentElement;

    let old =
        parent.querySelector('.cam-offline');

    if (!old) {

        let div =
            document.createElement('div');

        div.className =
            'cam-offline';

        div.innerHTML = `

            <div class="text-center">

                <i
                    class="bi bi-camera-video-off fs-1"
                ></i>

                <div class="mt-2">
                    CAM SLOT ${slotId}
                </div>

                <small>
                    OFFLINE
                </small>

            </div>

        `;

        parent.insertBefore(
            div,
            img
        );

    }

}


/* =========================================================
   LOAD REALTIME DATA
========================================================= */

function loadDashboardData() {

    $.ajax({

        url: 'api_get_realtime.php',

        type: 'GET',

        dataType: 'json',

        cache: false,


        success: function(res) {

            if (
                !res ||
                res.status !== 'success'
            ) {

                return;

            }


            let availCount = 0;

            let bookedCount = 0;

            let occupiedCount = 0;


            /*
             =================================================
             ครั้งแรกเท่านั้น
             สร้าง HTML กล้อง
             =================================================
            */

            if (!dashboardInitialized) {

                let allCamsHtml = '';

                let slotsHtml = '';


                res.slots.forEach(function(slot) {

                    let slotId =
                        parseInt(slot.slot_id);


                    let glowClass =
                        'glow-available';


                    let statusText =
                        'ว่าง';


                    let badgeColor =
                        'bg-success';


                    /*
                     -----------------------------------------
                     ALL CAM
                     -----------------------------------------
                    */

                    allCamsHtml += `

                        <div
                            class="col-lg-4 col-md-6"
                            id="all-card-${slotId}"
                        >

                            <div
                                id="all-cam-card-${slotId}"
                                class="cam-feed-card p-3 glow-available"
                            >

                                <div
                                    class="d-flex justify-content-between align-items-center mb-2"
                                >

                                    <span
                                        class="fw-bold text-white fs-5"
                                    >

                                        <i
                                            class="bi bi-camera-video-fill text-info me-2"
                                        ></i>

                                        CAM SLOT ${slotId}

                                    </span>


                                    <span
                                        id="all-status-${slotId}"
                                        class="badge bg-success"
                                    >
                                        ว่าง
                                    </span>

                                </div>


                                ${createCameraHTML(slotId)}


                                <div
                                    class="mt-2 d-flex justify-content-between align-items-center small text-secondary"
                                >

                                    <span>
                                        ตำแหน่ง:
                                        ช่องจอด
                                        ${slot.slot_number}
                                    </span>


                                    <span>
                                        ทะเบียน:

                                        <strong
                                            id="all-plate-${slotId}"
                                            class="text-white"
                                        >
                                            -
                                        </strong>

                                    </span>

                                </div>

                            </div>

                        </div>

                    `;


                    /*
                     -----------------------------------------
                     SLOT VIEW
                     -----------------------------------------
                    */

                    slotsHtml += `

                        <div
                            class="col-lg-4 col-md-6"
                            id="slot-card-container-${slotId}"
                        >

                            <div
                                id="slot-card-${slotId}"
                                class="cam-feed-card glow-available p-3"
                            >

                                <div
                                    class="d-flex justify-content-between align-items-center mb-3"
                                >

                                    <div
                                        class="d-flex align-items-center gap-2"
                                    >

                                        <span
                                            class="fs-5 fw-bold text-white"
                                        >
                                            ช่องจอด
                                            ${slot.slot_number}
                                        </span>

                                        <small
                                            class="text-secondary"
                                        >
                                            (Slot #${slotId})
                                        </small>

                                    </div>


                                    <span
                                        id="slot-status-badge-${slotId}"
                                        class="status-badge bg-success bg-opacity-20 text-success border border-success border-opacity-25"
                                    >
                                        <i
                                            class="bi bi-check2-circle me-1"
                                        ></i>
                                        ว่าง
                                    </span>

                                </div>


                                ${createSlotCameraHTML(slotId)}


                                <div
                                    id="slot-details-${slotId}"
                                ></div>


                                <div
                                    id="slot-action-${slotId}"
                                ></div>

                            </div>

                        </div>

                    `;

                });


                $('#all-cams-container')
                    .html(allCamsHtml);


                $('#slots-container')
                    .html(slotsHtml);


                dashboardInitialized =
                    true;

            }


            /*
             =================================================
             อัปเดตเฉพาะข้อมูลสถานะ
             ไม่สร้าง IMG ใหม่
             =================================================
            */

            res.slots.forEach(function(slot) {

                let slotId =
                    parseInt(slot.slot_id);


                let isOccupied =
                    !!slot.is_occupied;


                let isBooked =
                    !!slot.is_booked;


                /*
                 -----------------------------------------
                 OCCUPIED
                 -----------------------------------------
                */

                if (isOccupied) {

                    occupiedCount++;


                    $('#all-cam-card-' + slotId)
                        .removeClass(
                            'glow-available glow-booked'
                        )
                        .addClass(
                            'glow-occupied'
                        );


                    $('#all-status-' + slotId)
                        .removeClass(
                            'bg-success bg-warning text-dark'
                        )
                        .addClass(
                            'bg-danger'
                        )
                        .text(
                            'มีรถจอด'
                        );


                    $('#all-plate-' + slotId)
                        .text(
                            slot.license_plate || '-'
                        );


                    $('#slot-card-' + slotId)
                        .removeClass(
                            'glow-available glow-booked'
                        )
                        .addClass(
                            'glow-occupied'
                        );


                    $('#slot-status-badge-' + slotId)
                        .attr(
                            'class',
                            'status-badge bg-danger bg-opacity-20 text-danger border border-danger border-opacity-25'
                        )
                        .html(
                            '<i class="bi bi-car-front-fill me-1"></i>' +
                            'ไม่ว่าง / มีรถจอด (Red LED)'
                        );


                    $('#slot-details-' + slotId)
                        .html(`

                            <div
                                class="info-pill border-danger border-opacity-25 bg-danger bg-opacity-10"
                            >

                                <div
                                    class="d-flex justify-content-between align-items-center mb-2"
                                >

                                    <span class="text-secondary small">

                                        <i class="bi bi-person-fill me-1"></i>

                                        ผู้เข้าจอด:

                                    </span>


                                    <span class="fw-bold text-white">

                                        ${slot.user_name || 'ไม่ได้จองล่วงหน้า (Drive-in)'}

                                    </span>

                                </div>


                                <div
                                    class="d-flex justify-content-between align-items-center mb-2"
                                >

                                    <span class="text-secondary small">

                                        <i class="bi bi-card-text me-1"></i>

                                        ทะเบียนรถ:

                                    </span>


                                    <span
                                        class="badge bg-danger text-white fs-6 px-3 py-1"
                                    >

                                        ${slot.license_plate || '-'}

                                    </span>

                                </div>


                                <div
                                    class="d-flex justify-content-between align-items-center mb-1"
                                >

                                    <span class="text-secondary small">

                                        <i class="bi bi-lightbulb me-1"></i>

                                        ไฟ LED:

                                    </span>


                                    <span class="text-danger fw-bold">

                                        <i class="bi bi-circle-fill me-1"></i>

                                        สีแดง (Red)

                                    </span>

                                </div>


                                <div
                                    class="d-flex justify-content-between align-items-center"
                                >

                                    <span class="text-secondary small">

                                        <i class="bi bi-cpu me-1"></i>

                                        เซนเซอร์:

                                    </span>


                                    <span class="text-danger fw-bold">

                                        ตรวจพบรถ (CAR DETECTED)

                                    </span>

                                </div>

                            </div>

                        `);


                    $('#slot-action-' + slotId)
                        .html('');

                }


                /*
                 -----------------------------------------
                 BOOKED
                 -----------------------------------------
                */

                else if (isBooked) {

                    bookedCount++;


                    $('#all-cam-card-' + slotId)
                        .removeClass(
                            'glow-available glow-occupied'
                        )
                        .addClass(
                            'glow-booked'
                        );


                    $('#all-status-' + slotId)
                        .removeClass(
                            'bg-success bg-danger'
                        )
                        .addClass(
                            'bg-warning text-dark'
                        )
                        .text(
                            'จองแล้ว'
                        );


                    $('#all-plate-' + slotId)
                        .text(
                            slot.license_plate || '-'
                        );


                    $('#slot-card-' + slotId)
                        .removeClass(
                            'glow-available glow-occupied'
                        )
                        .addClass(
                            'glow-booked'
                        );


                    $('#slot-status-badge-' + slotId)
                        .attr(
                            'class',
                            'status-badge bg-warning bg-opacity-20 text-warning border border-warning border-opacity-25'
                        )
                        .html(
                            '<i class="bi bi-bookmark-star-fill me-1"></i>' +
                            'จองแล้ว (Blue LED)'
                        );


                    $('#slot-details-' + slotId)
                        .html(`

                            <div
                                class="info-pill border-warning border-opacity-25 bg-warning bg-opacity-10"
                            >

                                <div
                                    class="d-flex justify-content-between align-items-center mb-2"
                                >

                                    <span class="text-secondary small">

                                        <i class="bi bi-person-fill me-1"></i>

                                        ผู้สิทธิ์จอง:

                                    </span>


                                    <span class="fw-bold text-white">

                                        ${slot.user_name || '-'}

                                    </span>

                                </div>


                                <div
                                    class="d-flex justify-content-between align-items-center mb-2"
                                >

                                    <span class="text-secondary small">

                                        <i class="bi bi-card-text me-1"></i>

                                        ทะเบียนรถ:

                                    </span>


                                    <span
                                        class="badge bg-warning text-dark fs-6 px-3 py-1"
                                    >

                                        ${slot.license_plate || '-'}

                                    </span>

                                </div>


                                <div
                                    class="d-flex justify-content-between align-items-center mb-1"
                                >

                                    <span class="text-secondary small">

                                        <i class="bi bi-lightbulb me-1"></i>

                                        ไฟ LED:

                                    </span>


                                    <span class="text-primary fw-bold">

                                        <i class="bi bi-circle-fill me-1"></i>

                                        สีน้ำเงิน (Blue)

                                    </span>

                                </div>


                                <div
                                    class="d-flex justify-content-between align-items-center"
                                >

                                    <span class="text-secondary small">

                                        <i class="bi bi-clock me-1"></i>

                                        สถานะ:

                                    </span>


                                    <span class="text-warning fw-bold">

                                        กำลังเดินทางมารับที่จอด

                                    </span>

                                </div>

                            </div>

                        `);


                    $('#slot-action-' + slotId)
                        .html(`

                            <button
                                class="btn btn-outline-danger btn-sm w-100 rounded-3 mt-3"
                                onclick="cancelReservation(${slot.reservation_id || 0})"
                            >

                                <i class="bi bi-x-circle me-1"></i>

                                ยกเลิกสิทธิ์การจองนี้

                            </button>

                        `);

                }


                /*
                 -----------------------------------------
                 AVAILABLE
                 -----------------------------------------
                */

                else {

                    availCount++;


                    $('#all-cam-card-' + slotId)
                        .removeClass(
                            'glow-booked glow-occupied'
                        )
                        .addClass(
                            'glow-available'
                        );


                    $('#all-status-' + slotId)
                        .attr(
                            'class',
                            'badge bg-success'
                        )
                        .text(
                            'ว่าง'
                        );


                    $('#all-plate-' + slotId)
                        .text('-');


                    $('#slot-card-' + slotId)
                        .removeClass(
                            'glow-booked glow-occupied'
                        )
                        .addClass(
                            'glow-available'
                        );


                    $('#slot-status-badge-' + slotId)
                        .attr(
                            'class',
                            'status-badge bg-success bg-opacity-20 text-success border border-success border-opacity-25'
                        )
                        .html(
                            '<i class="bi bi-check2-circle me-1"></i>' +
                            'ว่าง (Green LED)'
                        );


                    $('#slot-details-' + slotId)
                        .html(`

                            <div
                                class="info-pill border-success border-opacity-25 bg-success bg-opacity-10"
                            >

                                <div
                                    class="d-flex justify-content-between align-items-center mb-1"
                                >

                                    <span class="text-secondary small">

                                        <i class="bi bi-info-circle me-1"></i>

                                        สถานะช่อง:

                                    </span>


                                    <span class="badge bg-success text-white">

                                        พร้อมใช้งาน

                                    </span>

                                </div>


                                <div
                                    class="d-flex justify-content-between align-items-center mb-1"
                                >

                                    <span class="text-secondary small">

                                        <i class="bi bi-lightbulb me-1"></i>

                                        ไฟ LED:

                                    </span>


                                    <span class="text-success fw-bold">

                                        <i class="bi bi-circle-fill me-1"></i>

                                        สีเขียว (Green)

                                    </span>

                                </div>


                                <div
                                    class="d-flex justify-content-between align-items-center"
                                >

                                    <span class="text-secondary small">

                                        <i class="bi bi-cpu me-1"></i>

                                        เซนเซอร์:

                                    </span>


                                    <span class="text-secondary">

                                        ไม่พบวัตถุ (NO CAR)

                                    </span>

                                </div>

                            </div>

                        `);


                    $('#slot-action-' + slotId)
                        .html('');

                }

            });


            /*
             -----------------------------------------
             SUMMARY
             -----------------------------------------
            */

            $('#cnt-available')
                .text(availCount);

            $('#cnt-booked')
                .text(bookedCount);

            $('#cnt-occupied')
                .text(occupiedCount);


            /*
             -----------------------------------------
             PAYMENT APPROVAL
             -----------------------------------------
            */

            let pend =
                parseInt(
                    res.pending_approvals || 0,
                    10
                );


            $('#pending-badge')
                .text(pend)
                .toggleClass(
                    'd-none',
                    pend <= 0
                );


            /*
             -----------------------------------------
             UPDATE TIME
             -----------------------------------------
            */

            $('#last-update').html(

                '<i class="bi bi-sync text-info me-1"></i>' +

                'ซิงค์ล่าสุด: ' +

                new Date().toLocaleTimeString()

            );

        },


        error: function(xhr) {

            console.error(
                'Realtime API Error:',
                xhr.responseText
            );

        }

    });

}


/* =========================================================
   CANCEL RESERVATION
========================================================= */

function cancelReservation(resId) {

    if (!resId) {

        alert(
            'ไม่พบรหัสการจอง'
        );

        return;

    }


    if (
        !confirm(
            'ยืนยันการยกเลิกรายการจองนี้ใช่หรือไม่?'
        )
    ) {

        return;

    }


    $.ajax({

        url: 'api_cancel_booking.php',

        type: 'POST',

        dataType: 'json',

        data: {
            reservation_id: resId
        },


        success: function(res) {

            if (
                res &&
                res.status === 'success'
            ) {

                alert(
                    res.message ||
                    'ยกเลิกการจองเรียบร้อยแล้ว'
                );

            }

            else {

                alert(
                    (res && res.message)
                        ? res.message
                        : 'ไม่สามารถยกเลิกการจองได้'
                );

            }


            loadDashboardData();

        },


        error: function(xhr) {

            let message =
                'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์';


            try {

                const res =
                    JSON.parse(
                        xhr.responseText
                    );

                if (res.message) {

                    message =
                        res.message;

                }

            }

            catch (e) {

                console.error(
                    xhr.responseText
                );

            }


            alert(message);

            loadDashboardData();

        }

    });

}


/* =========================================================
   START
========================================================= */

$(document).ready(function() {

    // โหลดครั้งแรก
    loadDashboardData();

    /*
       อัปเดตข้อมูลเซนเซอร์ทุก 3 วินาที

       สำคัญ:
       จะไม่สร้าง <img> ใหม่
       ดังนั้น Live Camera จะไม่ถูกตัดทุก 3 วินาที
    */

    setInterval(
        loadDashboardData,
        3000
    );

});

</script>

</body>
</html>