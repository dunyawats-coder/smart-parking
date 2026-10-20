<?php

require_once "db.php";

sp_json_api();

/*
    Smart Parking Sensor API

    ESP32 -> sensor_update.php

    ระยะ:
        < 25 cm  = รถเข้า
        >= 25 cm = รถออก/ว่าง

    Logic:

        AVAILABLE -> OCCUPIED
            สร้าง parking session 1 ครั้ง

        OCCUPIED -> OCCUPIED
            ไม่สร้าง session ใหม่

        RESERVED -> OCCUPIED
            สร้าง parking session 1 ครั้ง

        OCCUPIED -> AVAILABLE
            ปิด parking session
            คำนวณค่าจอด

        AVAILABLE -> AVAILABLE
            ไม่ทำอะไร

        RESERVED -> RESERVED
            ไม่ทำอะไร
*/


// =====================================================
// READ JSON
// =====================================================

$raw = file_get_contents("php://input");

// รูปแบบ JSON ที่ ESP32 ส่งมา (ทุก 3 วินาที):
//   {"slot1":{"distance":18.5,"status":"occupied"},"slot2":{...},"slot3":{...}}
// หมายเหตุ: เซิร์ฟเวอร์ตัดสินจากฟิลด์ distance เท่านั้น (ไม่เชื่อฟิลด์ status ที่บอร์ดส่งมา)
$data = json_decode($raw, true);


// =====================================================
// SUPPORT FORM DATA
// =====================================================

if (!is_array($data)) {

    if (isset($_REQUEST['slot_number'])) {

        $n = (int)$_REQUEST['slot_number'];

        $d =
            $_REQUEST['distance_cm']
            ?? $_REQUEST['distance']
            ?? -1;

        $data = [
            "slot" . $n => [
                "distance" => (float)$d
            ]
        ];

    } else {

        sp_json([
            "status" => "error",
            "message" => "Invalid JSON"
        ], 400);

        exit;
    }
}


// =====================================================
// PROCESS SLOT
// =====================================================

function processSlot(
    mysqli $conn,
    int $slotNumber,
    float $distance
): array
{
    // ค่าระยะผิดปกติ (< 2 cm หรือ > 400 cm) เป็นข้อจำกัดของ HC-SR04 = เซนเซอร์อ่านผิด/สายหลวม/ไม่มีเอคโค่
    // ข้ามไปเลย: ไม่เปลี่ยนสถานะ ไม่สร้าง/ปิด session และไม่เขียนทับ distance_cm เดิม
    // (distance = -1 คือบอร์ดอ่านเซนเซอร์ไม่ได้ ก็ถูกข้ามด้วยเช่นกัน)
    if ($distance < 2 || $distance > 400) {
        return [
            "success" => true,
            "slot_number" => $slotNumber,
            "distance_cm" => $distance,
            "ignored" => true,
            "message" => "Invalid distance ignored"
        ];
    }

    // เริ่มทรานแซกชันต่อ 1 ช่อง — SELECT ... FOR UPDATE ด้านล่างจะล็อกแถวของช่อง
    // กัน request สองตัวที่มาพร้อมกันสร้าง parking_session ซ้ำ
    $conn->begin_transaction();

    try {

        // =================================================
        // GET SLOT
        // =================================================

        $st = $conn->prepare("
            SELECT
                id,
                slot_number,
                status
            FROM parking_slots
            WHERE slot_number = ?
            LIMIT 1
            FOR UPDATE
        ");

        if (!$st) {
            throw new Exception(
                "Prepare slot query failed"
            );
        }

        $st->bind_param(
            "i",
            $slotNumber
        );

        $st->execute();

        $slot =
            $st
                ->get_result()
                ->fetch_assoc();

        $st->close();


        // =================================================
        // SLOT NOT FOUND
        // =================================================

        if (!$slot) {

            $conn->rollback();

            return [
                "success" => false,
                "message" =>
                    "Slot $slotNumber not found"
            ];
        }


        $slotId =
            (int)$slot['id'];

        $oldStatus =
            sp_norm_slot_status(
                $slot['status']
            );

        $newStatus =
            $oldStatus;

        $carDetected =
            null;

        $session =
            null;


        // =================================================
        // CAR ENTER
        // < 25 CM
        // =================================================

        if (
            $distance >= 0 &&
            $distance < SP_OCCUPIED_DISTANCE
        ) {

            $carDetected = true;

            $newStatus = "occupied";


            // ---------------------------------------------
            // สำคัญ:
            // สร้าง Session เฉพาะตอนที่สถานะเดิม
            // ยังไม่ใช่ occupied
            // ---------------------------------------------

            if (
                $oldStatus !== "occupied"
            ) {

                $sid =
                    sp_handle_entry(
                        $conn,
                        $slotId
                    );

                $session = [
                    "session_id" => $sid,
                    "event" => "vehicle_enter",
                    "new_entry" => true
                ];

            } else {

                // -----------------------------------------
                // รถยังจอดอยู่
                // ไม่สร้าง Session ซ้ำ
                // -----------------------------------------

                $session = [
                    "event" => "vehicle_still_parked",
                    "new_entry" => false
                ];
            }
        }


        // =================================================
        // CAR EXIT
        // >= 25 CM
        // =================================================

        elseif (
            $distance >= SP_AVAILABLE_DISTANCE
        ) {

            $carDetected = false;


            // ---------------------------------------------
            // รถออกเฉพาะเมื่อก่อนหน้านี้ occupied
            // ---------------------------------------------

            if (
                $oldStatus === "occupied"
            ) {

                $session =
                    sp_finish_session(
                        $conn,
                        $slotId
                    );

                // sp_finish_session ตั้งสถานะช่องให้แล้ว (available หรือ reserved ถ้ายังมีคนจองค้าง)
                // อ่านค่าจริงกลับมา กันไม่ให้ UPDATE ด้านล่างเขียนทับ
                $st = $conn->prepare("SELECT status FROM parking_slots WHERE id = ?");
                $st->bind_param("i", $slotId);
                $st->execute();
                $rowNow = $st->get_result()->fetch_assoc();
                $st->close();
                $newStatus = sp_norm_slot_status($rowNow['status'] ?? 'available');

            }


            // ---------------------------------------------
            // RESERVED
            // ยังไม่มีรถ
            // ---------------------------------------------

            elseif (
                $oldStatus === "reserved"
            ) {

                $newStatus =
                    "reserved";
            }


            // ---------------------------------------------
            // AVAILABLE
            // ---------------------------------------------

            else {

                $newStatus =
                    "available";
            }
        }


        // =================================================
        // (ไม่ถูกใช้เมื่อเกณฑ์เป็น 25 เดียว)
        // KEEP STATUS
        // =================================================

        else {

            $carDetected =
                null;

            $newStatus =
                $oldStatus;
        }


        // =================================================
        // UPDATE SLOT
        // =================================================

        // distance < 0 แปลว่าบอร์ดอ่านเซนเซอร์ไม่ได้ (timeout) → อัปเดตเฉพาะ status ไม่เขียนทับค่า distance_cm เดิม
        if ($distance >= 0) {

            $st = $conn->prepare("
                UPDATE parking_slots
                SET
                    distance_cm = ?,
                    status = ?
                WHERE id = ?
            ");

            if (!$st) {
                throw new Exception(
                    "Prepare slot update failed"
                );
            }

            $st->bind_param(
                "dsi",
                $distance,
                $newStatus,
                $slotId
            );

        } else {

            $st = $conn->prepare("
                UPDATE parking_slots
                SET status = ?
                WHERE id = ?
            ");

            if (!$st) {
                throw new Exception(
                    "Prepare slot update failed"
                );
            }

            $st->bind_param(
                "si",
                $newStatus,
                $slotId
            );
        }


        $st->execute();

        $st->close();


        // =================================================
        // COMMIT
        // =================================================

        $conn->commit();


        // =================================================
        // RETURN
        // =================================================

        return [

            "success" =>
                true,

            "slot_number" =>
                $slotNumber,

            "distance_cm" =>
                $distance,

            "old_status" =>
                $oldStatus,

            "new_status" =>
                $newStatus,

            "car_detected" =>
                $carDetected,

            "session" =>
                $session
        ];


    } catch (Throwable $e) {

        $conn->rollback();

        return [

            "success" =>
                false,

            "message" =>
                $e->getMessage()
        ];
    }
}


// =====================================================
// PROCESS ALL SLOTS
// =====================================================

$response = [

    "status" =>
        "success",

    "message" =>
        "Parking data updated",

    "slots" =>
        []
];


// =====================================================
// LOOP
// =====================================================

foreach (
    $data as $key => $value
) {

    if (
        !preg_match(
            '/^slot(\d+)$/',
            (string)$key,
            $m
        )
        ||
        !is_array($value)
    ) {

        continue;
    }


    $distance =
        isset($value['distance'])
            ? (float)$value['distance']
            : -1;


    $slotNumber =
        (int)$m[1];


    $response["slots"][$key] =
        processSlot(
            $conn,
            $slotNumber,
            $distance
        );
}


// =====================================================
// RESPONSE
// =====================================================

echo json_encode(
    $response,
    JSON_UNESCAPED_UNICODE
);

?>