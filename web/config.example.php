<?php
// คัดลอกไฟล์นี้เป็น config.php แล้วแก้ค่าให้ตรงกับเครื่องคุณ
// config.php ถูก .gitignore จึงไม่ขึ้น GitHub (หรือตั้งผ่าน environment variable ก็ได้ เช่นตอนใช้ Docker)
return [
    'db_host'  => 'localhost',
    'db_user'  => 'smartparking',
    'db_pass'  => 'CHANGE_ME',
    'db_name'  => 'smartparking',
    'promptpay_id' => '0000000000',   // เบอร์/เลขบัตรที่ผูก PromptPay สำหรับรับเงิน
    'camera_dir'   => __DIR__ . '/camera_images',  // โฟลเดอร์เก็บรูปจากกล้อง ESP32-CAM
    'admin_key'    => 'CHANGE_ME',    // รหัสลับสำหรับสมัครบัญชี Admin
];
