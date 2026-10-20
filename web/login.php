<?php
session_start();
require_once "db.php";

$message = "";
$msg_type = "error";

if (isset($_SESSION['user_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . (sp_is_admin($conn) ? "admin_dashboard.php" : "dashboard.php"));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $message = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } elseif ($action === 'login') {
        $user = null;
        // รองรับบัญชีเดิมใน users (password เป็นข้อความเดิม หรือ password_hash แบบใหม่)
        $st = $conn->prepare("SELECT * FROM users WHERE username=? AND status='active' LIMIT 1");
        $st->bind_param('s', $username);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row) {
            $storedHash = (string)($row['password_hash'] ?? '');
            $storedPlain = (string)($row['password'] ?? $row['pass'] ?? '');
            $valid = ($storedHash !== '' && password_verify($password, $storedHash)) || ($storedPlain !== '' && hash_equals($storedPlain, $password));
            if ($valid) $user = $row;
        }

        // รองรับแอดมินที่อยู่ในตาราง admins แยกต่างหากด้วย
        if (!$user) {
            $st = $conn->prepare("SELECT * FROM admins WHERE username=? AND status='active' LIMIT 1");
            $st->bind_param('s', $username);
            $st->execute();
            $adminRow = $st->get_result()->fetch_assoc();
            $st->close();
            if ($adminRow && password_verify($password, (string)$adminRow['password_hash'])) {
                $user = $adminRow;
                $user['role'] = 'admin';
            }
        }

        if ($user) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = (string)$user['username'];
            $_SESSION['name'] = (string)($user['full_name'] ?? $user['fullname'] ?? $user['username']);
            $_SESSION['role'] = strtolower((string)($user['role'] ?? 'user'));
            header('Location: ' . (in_array($_SESSION['role'], ['admin','administrator','superadmin'], true) ? 'admin_dashboard.php' : 'dashboard.php'));
            exit;
        }
        $message = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง หรือบัญชีถูกปิดใช้งาน';
    } elseif ($action === 'register') {
        $role = (string)($_POST['role'] ?? 'user');
        $admin_key = (string)($_POST['admin_key'] ?? '');
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        if ($role !== 'admin') $role = 'user';
        if ($role === 'admin' && $admin_key !== SP_ADMIN_KEY) {
            $message = 'Admin Security Key ไม่ถูกต้อง';
        } else {
            $st = $conn->prepare("SELECT id FROM users WHERE username=? UNION SELECT id FROM admins WHERE username=? LIMIT 1");
            $st->bind_param('ss', $username, $username);
            $st->execute();
            $exists = $st->get_result()->num_rows > 0;
            $st->close();
            if ($exists) {
                $message = 'ชื่อผู้ใช้นี้มีอยู่ในระบบแล้ว';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                // Schema ของผู้ใช้ที่แนบมามีทั้ง password และ password_hash ซึ่งเป็น NOT NULL
                $st = $conn->prepare("INSERT INTO users (username,password,password_hash,full_name,phone,role,status) VALUES (?,?,?,?,?,?, 'active')");
                $plain_unused = ''; // ไม่เก็บรหัสผ่านแบบข้อความ เก็บเฉพาะ password_hash
                $st->bind_param('ssssss', $username, $plain_unused, $hash, $full_name, $phone, $role);
                if ($st->execute()) {
                    $message = 'สมัครสมาชิกสำเร็จ กรุณาเข้าสู่ระบบ';
                    $msg_type = 'success';
                } else {
                    $message = 'สมัครสมาชิกไม่สำเร็จ กรุณาตรวจสอบข้อมูลฐานข้อมูล';
                }
                $st->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - Smart Parking System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Kanit', sans-serif; } </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex items-center justify-center p-4 relative overflow-hidden">

    <div class="absolute -top-32 -left-32 w-96 h-96 bg-indigo-600/20 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute -bottom-32 -right-32 w-96 h-96 bg-violet-600/20 rounded-full blur-3xl pointer-events-none"></div>

    <div class="bg-slate-900/80 border border-slate-800 p-8 rounded-3xl w-full max-w-md shadow-2xl backdrop-blur-xl relative z-10">
        
        <div class="text-center mb-8">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center mx-auto mb-3 shadow-lg shadow-indigo-500/30">
                <i class="fa-solid fa-square-parking text-3xl text-white"></i>
            </div>
            <h2 class="text-2xl font-bold bg-gradient-to-r from-white via-slate-200 to-indigo-300 bg-clip-text text-transparent">SMART PARKING</h2>
            <p class="text-xs text-slate-400 mt-1">ระบบจองที่จอดรถและดูกล้องวงจรปิดอัจฉริยะ</p>
        </div>

        <?php if (!empty($message)): ?>
            <?php 
                $alert_box = "bg-rose-500/10 border-rose-500/30 text-rose-300";
                $alert_icon = "fa-circle-exclamation text-rose-400";
                if ($msg_type === "success") {
                    $alert_box = "bg-emerald-500/10 border-emerald-500/30 text-emerald-300";
                    $alert_icon = "fa-circle-check text-emerald-400";
                }
            ?>
            <div class="p-3.5 rounded-xl mb-6 text-xs font-medium flex items-center gap-2.5 border shadow-md <?php echo $alert_box; ?>">
                <i class="fa-solid <?php echo $alert_icon; ?> text-base"></i>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <div class="flex bg-slate-950/60 p-1 rounded-2xl border border-slate-800 mb-6">
            <button id="tabLogin" type="button" onclick="switchTab('login')" class="flex-1 py-2.5 text-xs font-semibold rounded-xl transition duration-200 bg-indigo-600 text-white shadow-md shadow-indigo-600/30">
                <i class="fa-solid fa-right-to-bracket mr-1"></i> เข้าสู่ระบบ
            </button>
            <button id="tabRegister" type="button" onclick="switchTab('register')" class="flex-1 py-2.5 text-xs font-semibold rounded-xl transition duration-200 text-slate-400 hover:text-white">
                <i class="fa-solid fa-user-plus mr-1"></i> สมัครสมาชิก
            </button>
        </div>

        <form id="formLogin" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="login">
            
            <div>
                <label class="block text-xs text-slate-400 mb-1.5 font-medium">ชื่อผู้ใช้ (Username)</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500 text-sm">
                        <i class="fa-solid fa-user"></i>
                    </span>
                    <input type="text" name="username" required placeholder="กรอกชื่อผู้ใช้งาน" class="w-full bg-slate-950/70 border border-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 pl-10 pr-4 py-2.5 rounded-xl text-sm text-white placeholder-slate-600 transition outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs text-slate-400 mb-1.5 font-medium">รหัสผ่าน (Password)</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500 text-sm">
                        <i class="fa-solid fa-lock"></i>
                    </span>
                    <input type="password" name="password" required placeholder="กรอกรหัสผ่าน" class="w-full bg-slate-950/70 border border-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 pl-10 pr-4 py-2.5 rounded-xl text-sm text-white placeholder-slate-600 transition outline-none">
                </div>
            </div>

            <button type="submit" class="w-full bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 py-3 rounded-xl font-bold text-sm text-white transition shadow-lg shadow-indigo-600/30 flex items-center justify-center gap-2 mt-2">
                <span>เข้าสู่ระบบ</span>
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </button>
        </form>

        <form id="formRegister" method="POST" class="space-y-4 hidden">
            <input type="hidden" name="action" value="register">
            
            <div>
                <label class="block text-xs text-slate-400 mb-1.5 font-medium">ประเภทบัญชี</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500 text-sm">
                        <i class="fa-solid fa-user-gear"></i>
                    </span>
                    <select name="role" id="regRole" onchange="toggleAdminKey()" class="w-full bg-slate-950/70 border border-slate-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 pl-10 pr-4 py-2.5 rounded-xl text-sm text-white transition outline-none">
                        <option value="user" class="bg-slate-900">ผู้ใช้งานทั่วไป (User)</option>
                        <option value="admin" class="bg-slate-900">ผู้ดูแลระบบ (Admin)</option>
                    </select>
                </div>
            </div>

            <div id="adminKeyBox" class="hidden">
                <label class="block text-xs text-emerald-400 mb-1.5 font-medium">Admin Security Key</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-emerald-500 text-sm">
                        <i class="fa-solid fa-key"></i>
                    </span>
                    <input type="password" name="admin_key" placeholder="กรอกรหัสสำหรับผู้ดูแลระบบ" class="w-full bg-slate-950/70 border border-emerald-500/50 focus:border-emerald-400 focus:ring-1 focus:ring-emerald-400 pl-10 pr-4 py-2.5 rounded-xl text-sm text-white placeholder-slate-600 transition outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs text-slate-400 mb-1.5 font-medium">ชื่อ-นามสกุล</label>
                <input type="text" name="full_name" placeholder="เช่น สมชาย ใจดี" class="w-full bg-slate-950/70 border border-slate-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 px-4 py-2.5 rounded-xl text-sm text-white placeholder-slate-600 transition outline-none">
            </div>

            <div>
                <label class="block text-xs text-slate-400 mb-1.5 font-medium">เบอร์โทรศัพท์</label>
                <input type="tel" name="phone" placeholder="เช่น 0812345678" class="w-full bg-slate-950/70 border border-slate-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 px-4 py-2.5 rounded-xl text-sm text-white placeholder-slate-600 transition outline-none">
            </div>

            <div>
                <label class="block text-xs text-slate-400 mb-1.5 font-medium">ชื่อผู้ใช้ (Username)</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500 text-sm">
                        <i class="fa-solid fa-user"></i>
                    </span>
                    <input type="text" name="username" required placeholder="ตั้งชื่อผู้ใช้งาน" class="w-full bg-slate-950/70 border border-slate-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 pl-10 pr-4 py-2.5 rounded-xl text-sm text-white placeholder-slate-600 transition outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs text-slate-400 mb-1.5 font-medium">รหัสผ่าน (Password)</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500 text-sm">
                        <i class="fa-solid fa-lock"></i>
                    </span>
                    <input type="password" name="password" required placeholder="ตั้งรหัสผ่าน" class="w-full bg-slate-950/70 border border-slate-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 pl-10 pr-4 py-2.5 rounded-xl text-sm text-white placeholder-slate-600 transition outline-none">
                </div>
            </div>

            <button type="submit" class="w-full bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 py-3 rounded-xl font-bold text-sm text-white transition shadow-lg shadow-emerald-600/30 flex items-center justify-center gap-2 mt-2">
                <span>ยืนยันสมัครสมาชิก</span>
                <i class="fa-solid fa-user-check text-xs"></i>
            </button>
        </form>

    </div>

    <script>
        function switchTab(type) {
            const formLogin = document.getElementById('formLogin');
            const formRegister = document.getElementById('formRegister');
            const tabLogin = document.getElementById('tabLogin');
            const tabRegister = document.getElementById('tabRegister');

            if (type === 'login') {
                formLogin.classList.remove('hidden');
                formRegister.classList.add('hidden');
                tabLogin.className = 'flex-1 py-2.5 text-xs font-semibold rounded-xl transition duration-200 bg-indigo-600 text-white shadow-md shadow-indigo-600/30';
                tabRegister.className = 'flex-1 py-2.5 text-xs font-semibold rounded-xl transition duration-200 text-slate-400 hover:text-white';
            } else {
                formLogin.classList.add('hidden');
                formRegister.classList.remove('hidden');
                tabRegister.className = 'flex-1 py-2.5 text-xs font-semibold rounded-xl transition duration-200 bg-emerald-600 text-white shadow-md shadow-emerald-600/30';
                tabLogin.className = 'flex-1 py-2.5 text-xs font-semibold rounded-xl transition duration-200 text-slate-400 hover:text-white';
            }
        }

        function toggleAdminKey() {
            const role = document.getElementById('regRole').value;
            const adminKeyBox = document.getElementById('adminKeyBox');
            if (role === 'admin') {
                adminKeyBox.classList.remove('hidden');
            } else {
                adminKeyBox.classList.add('hidden');
            }
        }
    </script>
</body>
</html>