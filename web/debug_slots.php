<?php
// debug_slots.php - เครื่องมือตรวจสอบช่องจอด (เฉพาะ Admin)
session_start();
require_once "db.php";
sp_require_admin_page($conn);

echo "<h2>🔍 ผลการตรวจสอบฐานข้อมูล</h2>";
echo "✅ เชื่อมต่อ DB สำเร็จ<br><br>";

$result = $conn->query("SELECT * FROM parking_slots ORDER BY slot_number");
echo "จำนวนช่องจอดทั้งหมดใน DB: <b>" . $result->num_rows . " ช่อง</b><br><br>";
echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
echo "<tr><th>ID</th><th>Slot Number</th><th>Status (ค่าจริง)</th><th>Distance (cm)</th><th>Entry time</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr><td>" . (int)$row['id'] . "</td><td>" . (int)$row['slot_number'] . "</td><td>'"
       . sp_h($row['status']) . "'</td><td>" . sp_h($row['distance_cm'] ?? '') . "</td><td>"
       . sp_h($row['entry_time'] ?? '') . "</td></tr>";
}
echo "</table>";
