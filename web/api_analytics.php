<?php
// Smart Parking analytics API. Reads confirmed parking_sessions; does not count repeated sensor/MQTT messages.
session_start();
require_once __DIR__ . '/db.php';
sp_json_api();
sp_require_admin_json($conn);

function analytics_scalar(mysqli $conn, string $sql): int {
    $res = $conn->query($sql);
    $row = $res->fetch_row();
    return (int)($row[0] ?? 0);
}
function analytics_upsert_alert(mysqli $conn, string $type, string $severity, string $message, string $key, ?int $slotId = null): void {
    $st = $conn->prepare("INSERT INTO parking_alerts (alert_type,severity,message,slot_id,dedupe_key,status,created_at) VALUES (?,?,?,?,?,'unread',NOW()) ON DUPLICATE KEY UPDATE message=VALUES(message), severity=VALUES(severity)");
    $st->bind_param('sssis', $type, $severity, $message, $slotId, $key);
    $st->execute();
    $st->close();
}

// Aggregate the last 7 days into a persistent hourly table. Safe to run repeatedly.
$start = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
$start = $start->setTime((int)$start->format('H'), 0, 0)->modify('-167 hours');
$upsert = $conn->prepare("INSERT INTO parking_hourly_stats (hour_start,entry_count,exit_count,slot_usage_json,generated_at)
 VALUES (?,?,?, ?,NOW()) ON DUPLICATE KEY UPDATE entry_count=VALUES(entry_count),exit_count=VALUES(exit_count),slot_usage_json=VALUES(slot_usage_json),generated_at=NOW()");
for ($i=0; $i<168; $i++) {
    $hour = $start->modify("+$i hours");
    $hourStr = $hour->format('Y-m-d H:i:s');
    $nextHour = $hour->modify('+1 hour')->format('Y-m-d H:i:s');
    $st = $conn->prepare("SELECT
      (SELECT COUNT(*) FROM parking_sessions WHERE checkin_time >= ? AND checkin_time < ?) AS entries,
      (SELECT COUNT(*) FROM parking_sessions WHERE checkout_time >= ? AND checkout_time < ?) AS exits");
    $st->bind_param('ssss', $hourStr, $nextHour, $hourStr, $nextHour);
    $st->execute(); $counts = $st->get_result()->fetch_assoc(); $st->close();
    $entries = (int)$counts['entries']; $exits = (int)$counts['exits'];
    $slotUsage = '{}';
    $st = $conn->prepare("SELECT p.slot_number,COUNT(*) AS total FROM parking_sessions s JOIN parking_slots p ON p.id=s.slot_id WHERE s.checkin_time >= ? AND s.checkin_time < ? GROUP BY p.slot_number");
    $st->bind_param('ss', $hourStr, $nextHour); $st->execute(); $rs = $st->get_result(); $usage = [];
    while ($r = $rs->fetch_assoc()) $usage[(string)$r['slot_number']] = (int)$r['total'];
    $st->close(); $slotUsage = json_encode($usage, JSON_UNESCAPED_UNICODE);
    $upsert->bind_param('siis', $hourStr, $entries, $exits, $slotUsage); $upsert->execute();
}
$upsert->close();

// Last 24 complete/current hours.
$hourly = [];
$st = $conn->prepare("SELECT hour_start,entry_count,exit_count,slot_usage_json FROM parking_hourly_stats WHERE hour_start >= DATE_SUB(DATE_FORMAT(NOW(),'%Y-%m-%d %H:00:00'), INTERVAL 23 HOUR) ORDER BY hour_start ASC");
$st->execute(); $rs = $st->get_result();
while ($r = $rs->fetch_assoc()) {
    $r['entry_count'] = (int)$r['entry_count']; $r['exit_count'] = (int)$r['exit_count'];
    $r['slot_usage'] = json_decode($r['slot_usage_json'] ?: '{}', true) ?: []; unset($r['slot_usage_json']); $hourly[] = $r;
}
$st->close();

// Slot usage over the last 24 hours and all-time sessions.
$slotUsage = [];
$rs = $conn->query("SELECT p.id AS slot_id,p.slot_number,p.status,COUNT(s.id) AS total_sessions,
 SUM(CASE WHEN s.checkin_time >= DATE_SUB(NOW(),INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS sessions_last_24h,
 MAX(s.checkin_time) AS last_entry_time,MAX(s.checkout_time) AS last_exit_time
 FROM parking_slots p LEFT JOIN parking_sessions s ON s.slot_id=p.id GROUP BY p.id,p.slot_number,p.status ORDER BY p.slot_number");
while ($r = $rs->fetch_assoc()) { foreach (['slot_id','slot_number','total_sessions','sessions_last_24h'] as $k) $r[$k]=(int)$r[$k]; $slotUsage[]=$r; }

// Forecast the next hour using average counts from the same weekday/hour in the last 28 days.
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
$target = $now->setTime((int)$now->format('H'),0,0)->modify('+1 hour');
$weekday = (int)$target->format('N') - 1; // MySQL WEEKDAY(): Monday=0
$hourNum = (int)$target->format('G');
$st = $conn->prepare("SELECT DATE(checkin_time) AS d,COUNT(*) AS n FROM parking_sessions WHERE checkin_time >= DATE_SUB(NOW(),INTERVAL 28 DAY) AND WEEKDAY(checkin_time)=? AND HOUR(checkin_time)=? GROUP BY DATE(checkin_time)");
$st->bind_param('ii',$weekday,$hourNum); $st->execute(); $rs=$st->get_result(); $entrySamples=[]; while($r=$rs->fetch_assoc()) $entrySamples[]=(int)$r['n']; $st->close();
$st = $conn->prepare("SELECT DATE(checkout_time) AS d,COUNT(*) AS n FROM parking_sessions WHERE checkout_time IS NOT NULL AND checkout_time >= DATE_SUB(NOW(),INTERVAL 28 DAY) AND WEEKDAY(checkout_time)=? AND HOUR(checkout_time)=? GROUP BY DATE(checkout_time)");
$st->bind_param('ii',$weekday,$hourNum); $st->execute(); $rs=$st->get_result(); $exitSamples=[]; while($r=$rs->fetch_assoc()) $exitSamples[]=(int)$r['n']; $st->close();
$entriesMean = count($entrySamples) ? array_sum($entrySamples)/count($entrySamples) : 0;
$exitsMean = count($exitSamples) ? array_sum($exitSamples)/count($exitSamples) : 0;
$sampleCount = min(count($entrySamples),count($exitSamples));
$confidence = $sampleCount < 3 ? 'insufficient_data' : ($sampleCount < 5 ? 'low' : ($sampleCount < 10 ? 'medium' : 'high'));
$predEntries = round($entriesMean,1); $predExits=round($exitsMean,1);
$st = $conn->prepare("INSERT INTO parking_predictions (target_hour,predicted_entries,predicted_exits,method,sample_count,confidence,created_at) VALUES (?,?,?,'same_weekday_hour_average',?,?,NOW()) ON DUPLICATE KEY UPDATE predicted_entries=VALUES(predicted_entries),predicted_exits=VALUES(predicted_exits),sample_count=VALUES(sample_count),confidence=VALUES(confidence),created_at=NOW()");
$targetStr = $target->format('Y-m-d H:i:s');
$st->bind_param('sddis',$targetStr,$predEntries,$predExits,$sampleCount,$confidence); $st->execute(); $st->close();

// Generate deduplicated dashboard alerts.
$counts = ['available'=>0,'reserved'=>0,'occupied'=>0];
$rs=$conn->query('SELECT status,COUNT(*) AS n FROM parking_slots GROUP BY status');
while($r=$rs->fetch_assoc()){ $s=sp_norm_slot_status($r['status']); if(isset($counts[$s])) $counts[$s]=(int)$r['n']; }
$totalSlots=array_sum($counts); $occupiedPct=$totalSlots ? ($counts['occupied']/$totalSlots)*100 : 0;
$hourKey=$now->format('YmdH');
if($totalSlots>0 && $occupiedPct>=80){
    analytics_upsert_alert($conn,'parking_near_full','warning','ช่องจอดมีรถ '.round($occupiedPct).'% ('. $counts['occupied'].'/'.$totalSlots.' ช่อง)','parking_near_full_'.$hourKey);
}
if($counts['available']===0 && $totalSlots>0){
    analytics_upsert_alert($conn,'parking_full','critical','ไม่มีช่องจอดว่างในขณะนี้','parking_full_'.$hourKey);
}
if($confidence!=='insufficient_data' && $predEntries >= max(1,$counts['available'])){
    analytics_upsert_alert($conn,'forecast_high_demand','warning','คาดว่าชั่วโมงถัดไปจะมีรถเข้าเฉลี่ย '.number_format($predEntries,1).' คัน เทียบกับช่องว่าง '.$counts['available'].' ช่อง','forecast_high_demand_'.$target->format('YmdH'));
}
$alerts=[];
$rs=$conn->query("SELECT id,alert_type,severity,message,status,created_at FROM parking_alerts WHERE status='unread' ORDER BY created_at DESC LIMIT 20");
while($r=$rs->fetch_assoc()){ $r['id']=(int)$r['id']; $alerts[]=$r; }

sp_json([
 'status'=>'success',
 'generated_at'=>$now->format('Y-m-d H:i:s'),
 'slots'=>$counts,
 'hourly'=>$hourly,
 'slot_usage'=>$slotUsage,
 'prediction'=>['target_hour'=>$target->format('Y-m-d H:i:s'),'predicted_entries'=>$predEntries,'predicted_exits'=>$predExits,'sample_count'=>$sampleCount,'confidence'=>$confidence,'method'=>'ค่าเฉลี่ยของวันในสัปดาห์และชั่วโมงเดียวกันย้อนหลัง 28 วัน'],
 'alerts'=>$alerts,
 'note'=>'ค่าพยากรณ์เป็นค่าเฉลี่ยจากข้อมูลย้อนหลังที่มีอยู่ ไม่ใช่การรับประกัน และจะแสดงข้อมูลไม่เพียงพอเมื่อประวัติยังน้อย'
]);
