<?php
// [อธิบาย] ขั้นที่ 1 ของการจอง: ผู้ใช้เลือกช่อง วันที่ เวลา และทะเบียน แล้วกดไป payment_booking.php (POST)
// ยังไม่เขียนฐานข้อมูลในหน้านี้
session_start();
require_once "db.php";
// ต้องล็อกอินก่อนจึงจะจองได้
if(!isset($_SESSION['user_id'])){header("Location: login.php");exit;}
$selected_slot_id=isset($_GET['slot_id'])?intval($_GET['slot_id']):0;
// ช่องที่เลือกจองได้ = สถานะ available และไม่มีการจอง pending/reserved ค้างอยู่
$available_slots=$conn->query("
SELECT p.id,p.slot_number
FROM parking_slots p
WHERE LOWER(p.status) IN ('available','empty')
  AND NOT EXISTS (
    SELECT 1 FROM reservations r
    WHERE r.slot_id=p.id
      AND LOWER(r.status) IN ('pending','reserved')
  )
ORDER BY p.slot_number
");
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>จองช่องจอด - Smart Parking</title><script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet"><style>body{font-family:Kanit,sans-serif}</style></head>
<body class="bg-slate-950 text-white min-h-screen flex items-center justify-center p-6">
<div class="w-full max-w-md bg-slate-900 border border-slate-800 rounded-3xl p-8">
<h1 class="text-2xl font-bold">จองช่องจอดรถ</h1>
<p class="text-sm text-slate-400 mt-1">ส่งสลิปแล้วรอแอดมินอนุมัติ จากนั้นช่องจะเป็น “จองแล้ว” จนกว่ารถจะเข้าจริง</p>
<div class="mt-5 p-4 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 text-sm">
ค่าธรรมเนียมจอง <b class="text-indigo-400"><?=number_format(SP_BOOKING_FEE,0)?> บาท</b><br>
ค่าจอดรถ <b class="text-amber-400"><?=rtrim(rtrim(number_format(SP_PARKING_RATE,2),'0'),'.')?> บาท/นาที</b> คำนวณจาก ESP32 เมื่อรถออก
</div>
<form action="payment_booking.php" method="post" class="mt-6 space-y-4">
<select name="slot_id" required class="w-full bg-slate-950 border border-slate-700 rounded-xl p-3">
<option value="">-- เลือกช่อง --</option>
<?php while($s=$available_slots->fetch_assoc()): ?>
<option value="<?=$s['id']?>" <?=$selected_slot_id==$s['id']?'selected':''?>>ช่อง #<?=htmlspecialchars($s['slot_number'])?> (ว่าง)</option>
<?php endwhile; ?>
</select>
<div class="grid grid-cols-2 gap-3">
<input type="date" name="reserve_date" value="<?=date('Y-m-d')?>" required class="bg-slate-950 border border-slate-700 rounded-xl p-3">
<input type="time" name="reserve_time" value="<?=date('H:i')?>" required class="bg-slate-950 border border-slate-700 rounded-xl p-3">
</div>
<input type="text" name="license_plate" maxlength="20" placeholder="ทะเบียนรถ เช่น กข 1234" class="w-full bg-slate-950 border border-slate-700 rounded-xl p-3">
<button class="w-full bg-indigo-600 hover:bg-indigo-500 p-3 rounded-xl font-bold">ไปชำระค่าจอง <?=number_format(SP_BOOKING_FEE,0)?> บาท</button>
</form>
<a href="dashboard.php" class="block text-center text-slate-500 mt-5 text-sm">กลับ Dashboard</a>
</div></body></html>
