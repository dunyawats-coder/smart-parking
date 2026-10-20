<?php
// [อธิบาย] หน้าอนุมัติการชำระเงิน (เฉพาะ Admin) — เฉพาะส่วน HTML/JS
// JavaScript ดึงรายการจาก api_get_approvals.php ทุก 3 วินาที แล้วส่งผลอนุมัติ/ปฏิเสธไป api_process_approval.php
// แท็บ: ค่าจองรอตรวจ / จองแล้วรอรถเข้า / กำลังจอด / ค่าจอดรอตรวจ
session_start();
require_once "db.php";
sp_require_admin_page($conn);
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>อนุมัติการชำระเงิน - Smart Parking</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
body{font-family:Prompt,sans-serif;background:#080d16;color:#e5e7eb}.cardx{background:#111827;border:1px solid #243244;border-radius:16px}.table{--bs-table-bg:transparent;--bs-table-color:#e5e7eb}.slip{max-width:70px;max-height:70px;object-fit:cover;border-radius:8px}
</style></head><body>
<nav class="navbar navbar-dark border-bottom border-secondary"><div class="container-fluid"><a class="navbar-brand fw-bold" href="admin_dashboard.php">Smart Parking | ศูนย์อนุมัติ</a><div class="d-flex gap-2"><a href="admin_dashboard.php" class="btn btn-outline-light btn-sm">Dashboard</a><a href="history.php" class="btn btn-outline-info btn-sm">ประวัติ</a><a href="logout.php" class="btn btn-outline-danger btn-sm">ออกจากระบบ</a></div></div></nav>
<div class="container-fluid p-4">
<div class="d-flex justify-content-between align-items-center mb-4"><div><h3>ตรวจสอบการจองและค่าจอด</h3><div class="text-secondary">ค่าจอง <?=number_format(SP_BOOKING_FEE,0)?> บาท • ค่าจอด <?=rtrim(rtrim(number_format(SP_PARKING_RATE,2),'0'),'.')?> บาท/นาที</div></div>
<div><button id="bookingTab" class="btn btn-warning me-2" onclick="switchStage('booking')">การจอง <span id="cntBooking" class="badge text-bg-dark">0</span></button><button id="serviceTab" class="btn btn-outline-info" onclick="switchStage('service')">ค่าจอด <span id="cntService" class="badge text-bg-dark">0</span></button></div></div>
<div class="cardx p-3 table-responsive"><table class="table align-middle"><thead><tr><th>ID</th><th>ผู้ใช้</th><th>ช่อง</th><th>วันที่/เวลา</th><th>ยอด</th><th>สลิป</th><th>สถานะ</th><th></th></tr></thead><tbody id="tbody"></tbody></table></div>
</div>
<div class="modal fade" id="modal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content bg-dark text-white"><div class="modal-header"><h5 id="title">ตรวจสอบ</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><img id="img" class="img-fluid rounded mb-3" style="max-height:450px"><div id="detail"></div><input id="reason" class="form-control mt-3" placeholder="เหตุผลกรณีปฏิเสธ"></div><div class="modal-footer" id="actions"></div></div></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let data=[],stage='booking',selected=null;
function load(){
 fetch('api_get_approvals.php',{cache:'no-store'})
 .then(async r=>{const t=await r.text();let x;try{x=JSON.parse(t)}catch(e){throw new Error(t||('HTTP '+r.status))}
   if(!r.ok||x.status!=='success') throw new Error(x.message||('HTTP '+r.status));
   data=Array.isArray(x.data)?x.data:[]; render();
 })
 .catch(e=>{
   cntBooking.textContent='!';
   cntService.textContent='!';
   tbody.innerHTML='<tr><td colspan="8" class="text-center text-danger p-5">โหลดข้อมูลไม่สำเร็จ<br><small>'+esc(e.message)+'</small><br><button class="btn btn-sm btn-outline-light mt-3" onclick="load()">ลองใหม่</button></td></tr>';
 });
}
function switchStage(s){stage=s;document.getElementById('bookingTab').className=s==='booking'?'btn btn-warning me-2':'btn btn-outline-warning me-2';document.getElementById('serviceTab').className=s==='service'?'btn btn-info':'btn btn-outline-info';render()}
function render(){
 let b=data.filter(x=>x.stage==='booking_pending').length,s=data.filter(x=>x.stage==='service_payment_pending').length;
 cntBooking.textContent=b;cntService.textContent=s;
 let rows=data.filter(x=>stage==='booking'?(x.stage==='booking_pending'||x.stage==='booking_approved'):(x.stage==='occupied'||x.stage==='service_payment_pending'));
 if(!rows.length){tbody.innerHTML='<tr><td colspan="8" class="text-center text-secondary p-5">ไม่มีรายการ</td></tr>';return}
 tbody.innerHTML=rows.map(x=>{
   let status=x.stage==='booking_pending'?'รอตรวจสลิปจอง':x.stage==='booking_approved'?'อนุมัติจองแล้ว':x.stage==='occupied'?'กำลังจอด':'รอตรวจสลิปค่าจอด';
   let amount=x.stage==='service_payment_pending'?(x.amount||x.fee||0):(x.amount||0);
   let when=x.reserve_date?(x.reserve_date+' '+x.reserve_time):(x.check_in_at||'-');
   let action=x.stage==='booking_pending'?`<button class="btn btn-warning btn-sm" onclick="openItem(${x.reservation_id},0)">ตรวจสลิป</button>`:x.stage==='service_payment_pending'?`<button class="btn btn-info btn-sm" onclick="openItem(${x.reservation_id},${x.session_id})">ตรวจสลิปค่าจอด</button>`:'<span class="text-secondary">กำลังทำงาน</span>';
   return `<tr><td>#${x.reservation_id||('S'+x.session_id)}</td><td>${esc(x.user_name)}<br><small>${esc(x.phone_number)}</small></td><td>ช่อง ${esc(x.slot_number)}<br>${esc(x.license_plate)}</td><td>${esc(when)}</td><td>฿${Number(amount).toFixed(2)}</td><td>${x.slip_url?`<img class="slip" src="${esc(x.slip_url)}">`:'-'}</td><td>${status}</td><td>${action}</td></tr>`
 }).join('')
}
function openItem(rid,sid){selected={rid,sid};let x=data.find(a=>(a.reservation_id==rid&&a.session_id==sid));if(!x)return;title.textContent=x.stage==='booking_pending'?'อนุมัติการจอง':'อนุมัติค่าจอด';img.src=x.slip_url||'';img.style.display=x.slip_url?'block':'none';detail.innerHTML=`ผู้ใช้: ${esc(x.user_name)}<br>ช่อง: ${esc(x.slot_number)}<br>ยอด: ฿${Number(x.stage==='service_payment_pending'?(x.amount||x.fee||0):(x.amount||0)).toFixed(2)}<br>เวลาเข้า: ${esc(x.check_in_at||'-')}<br>เวลาออก: ${esc(x.check_out_at||'-')}`;reason.value='';actions.innerHTML=x.stage==='booking_pending'?`<button class="btn btn-danger" onclick="process('reject_booking')">ปฏิเสธ</button><button class="btn btn-success" onclick="process('approve_booking')">อนุมัติการจอง</button>`:`<button class="btn btn-danger" onclick="process('reject_service_payment')">ปฏิเสธ</button><button class="btn btn-success" onclick="process('approve_service_payment')">อนุมัติชำระ</button>`;new bootstrap.Modal(modal).show()}
function process(action){let rs=reason.value.trim();if(action.startsWith('reject')&&!rs){alert('กรุณาระบุเหตุผล');return}let fd=new FormData();fd.append('reservation_id',selected.rid);fd.append('session_id',selected.sid);fd.append('action',action);fd.append('reject_reason',rs);fetch('api_process_approval.php',{method:'POST',body:fd}).then(r=>r.json()).then(x=>{if(x.status==='success'){bootstrap.Modal.getInstance(modal).hide();load()}else alert(x.message)})}
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
load();setInterval(load,3000);
</script></body></html>