/*

   Smart Parking ESP32


   🟢 AVAILABLE

   🔵 RESERVED

   🔴 OCCUPIED


   รถเข้า:

   available -> occupied

   reserved  -> occupied

   
   รถออก:

   occupied -> available


   สถานะ/เกณฑ์ระยะ (ต่ำกว่า 25 cm = มีรถ, ตั้งแต่ 25 cm ขึ้นไป = ว่าง) ตรงกับ sensor_update.php

   และ lib.php บนเซิร์ฟเวอร์

   LED: เขียว=available, น้ำเงิน=reserved, แดง=occupied

*/


#include <WiFi.h>
#include "secrets.h"

#include <HTTPClient.h>

#include <ArduinoJson.h>
// ==================================================

// WiFi

// ==================================================


// ชื่อ/รหัส WiFi และ IP เซิร์ฟเวอร์อยู่ใน secrets.h (คัดลอกจาก secrets.h.example, ถูก .gitignore ไม่ขึ้น GitHub)

const char* WIFI_SSID = SECRET_WIFI_SSID;

const char* WIFI_PASSWORD = SECRET_WIFI_PASSWORD;


// ==================================================

// Server

// ==================================================


// URL ของเซิร์ฟเวอร์ (XAMPP) — IP ต้องตรงกับเครื่องที่รัน PHP จริง และอยู่ในวง WiFi เดียวกับบอร์ด

// SENSOR_URL = ส่งระยะขึ้นไป, STATUS_URL = ดึงสถานะ (จอง/ว่าง) ลงมา

const char* SENSOR_URL =

  "http://" SECRET_SERVER_IP "/smart_parking_integrated/sensor_update.php";


const char* STATUS_URL =

  "http://" SECRET_SERVER_IP "/smart_parking_integrated/api_slot_status.php";


// ==================================================

// HC-SR04

// ==================================================


#define TRIG1 34   // GPIO34 เป็น input-only ใช้เป็น TRIG ไม่ได้
#define ECHO1 19

#define TRIG2 2
#define ECHO2 36  // input-only ใช้เป็น ECHO ได้

#define TRIG3 15
#define ECHO3 35  // input-only ใช้เป็น ECHO ได้


// ==================================================

// RGB LED

// Common Cathode

// HIGH = ON

// LOW  = OFF

// ==================================================


// Slot 1

#define R1 13

#define G1 14

#define B1 27


// Slot 2

#define R2 26

#define G2 25

#define B2 33


// Slot 3

#define R3 32

#define G3 17

#define B3 18


// ==================================================

// Distance

// ==================================================


#define OCCUPIED_DISTANCE 25

#define AVAILABLE_DISTANCE 25


// ==================================================

// Status

// ==================================================


String slotStatus[3] = {

  "available",

  "available",

  "available"

};


// ==================================================

// Distance

// ==================================================


float distanceCM[3] = {

  -1,

  -1,

  -1

};


// ==================================================

// RGB

// ==================================================


void setRGB(

  int slot,

  String status

) {


  int r;

  int g;

  int b;


  if (slot == 0) {


    r = R1;

    g = G1;

    b = B1;


  } else if (slot == 1) {


    r = R2;

    g = G2;

    b = B2;


  } else {


    r = R3;

    g = G3;

    b = B3;

  }


  // ================================================

  // AVAILABLE = GREEN

  // ================================================


  if (status == "available") {


    digitalWrite(r, LOW);

    digitalWrite(g, HIGH);

    digitalWrite(b, LOW);

  }


  // ================================================

  // RESERVED = BLUE

  // ================================================


  else if (status == "reserved") {


    digitalWrite(r, LOW);

    digitalWrite(g, LOW);

    digitalWrite(b, HIGH);

  }


  // ================================================

  // OCCUPIED = RED

  // ================================================


  else if (status == "occupied") {


    digitalWrite(r, HIGH);

    digitalWrite(g, LOW);

    digitalWrite(b, LOW);

  }

}


// ==================================================

// Ultrasonic

// ==================================================


// อ่านระยะจาก HC-SR04: ส่งพัลส์ TRIG 10 ไมโครวินาที แล้ววัดเวลาที่ ECHO เป็น HIGH (timeout 30 ms)

// ระยะ (cm) = เวลา(us) x 0.0343 / 2  (หาร 2 เพราะเสียงไป-กลับ)

// คืน -1 เมื่ออ่านไม่ได้หรือเกิน 400 cm

float readDistanceOnce(int trigPin, int echoPin) {
  digitalWrite(trigPin, LOW);
  delayMicroseconds(3);
  digitalWrite(trigPin, HIGH);
  delayMicroseconds(10);
  digitalWrite(trigPin, LOW);

  unsigned long duration = pulseIn(echoPin, HIGH, 30000UL);
  if (duration == 0) return -1.0f;

  float distance = (duration * 0.0343f) / 2.0f;
  // HC-SR04 ปกติไม่ควรเชื่อค่าต่ำกว่า 2 cm
  if (distance < 2.0f || distance > 400.0f) return -1.0f;
  return distance;
}

// <<STABILIZE_BEGIN>>
// ปรับความนิ่งของค่า: อ่านหลายครั้ง -> มัธยฐาน -> ต้องยืนยันซ้ำหลายรอบก่อนเปลี่ยนสถานะ
#define SAMPLES            5     // จำนวนครั้งที่อ่านต่อ 1 รอบ
#define SAMPLE_GAP_MS      60    // เว้นระหว่างการอ่านแต่ละครั้ง (ลดเสียงสะท้อนซ้อน)
#define MIN_VALID_SAMPLES  3     // ต้องอ่านได้อย่างน้อยกี่ครั้งจึงเชื่อค่ารอบนั้น
#define CONFIRM_CYCLES     3     // โซนต้องเปลี่ยนต่อเนื่องกี่รอบจึงยอมรับ (กันค่ากระโดดครั้งเดียว)
#define FAIL_CYCLES_LIMIT  10    // อ่านไม่ได้ติดกันกี่รอบจึงรายงาน -1 (เซนเซอร์เสีย/สายหลวม)

// ใช้ int + #define แทน enum: Arduino IDE สร้างประกาศฟังก์ชันไว้บนสุดของไฟล์ ทำให้ชนิด enum ที่ประกาศทีหลังใช้ในฟังก์ชันไม่ได้
#define ZONE_UNKNOWN  0
#define ZONE_OCCUPIED 1
#define ZONE_FREE     2
#define ZONE_MID      3

int  confirmedZone[3]  = { ZONE_UNKNOWN, ZONE_UNKNOWN, ZONE_UNKNOWN };
int  candidateZone[3]  = { ZONE_UNKNOWN, ZONE_UNKNOWN, ZONE_UNKNOWN };
int   candidateCount[3] = { 0, 0, 0 };
int   failCount[3]      = { 0, 0, 0 };
float lastGoodDist[3]   = { -1, -1, -1 };

int zoneOf(float d) {
  if (d < OCCUPIED_DISTANCE) return ZONE_OCCUPIED;
  if (d >= AVAILABLE_DISTANCE) return ZONE_FREE;
  return ZONE_MID;
}

// รับค่าดิบ 1 รอบ (หรือ -1 ถ้าอ่านไม่ได้) คืนค่าระยะ 'ที่นิ่งแล้ว'
// ค่าจะข้ามโซน (มีรถ <-> ว่าง) ได้ก็ต่อเมื่อเห็นโซนใหม่ต่อเนื่อง CONFIRM_CYCLES รอบ
// ระหว่างรอยืนยันจะคืนค่าเดิมที่ยืนยันแล้ว; ตอนเพิ่งเปิดเครื่องคืน -1 จนกว่าจะยืนยันโซนแรกได้
float stabilizeDistance(int i, float raw) {
  if (raw < 0) {
    if (++failCount[i] >= FAIL_CYCLES_LIMIT) {
      confirmedZone[i] = ZONE_UNKNOWN;
      candidateZone[i] = ZONE_UNKNOWN;
      candidateCount[i] = 0;
      lastGoodDist[i] = -1;
      failCount[i] = FAIL_CYCLES_LIMIT;
      return -1;
    }
    return lastGoodDist[i];
  }
  failCount[i] = 0;
  int z = zoneOf(raw);

  if (z == ZONE_MID) {                     // 25-35 cm: ช่วงกันแกว่ง คงค่าเดิม
    return confirmedZone[i] == ZONE_UNKNOWN ? -1.0f : lastGoodDist[i];
  }

  if (confirmedZone[i] != ZONE_UNKNOWN && z == confirmedZone[i]) {
    candidateCount[i] = 0;                 // โซนเดิม: รีเซ็ตตัวนับ ใช้ค่าล่าสุดได้เลย
    lastGoodDist[i] = raw;
    return raw;
  }

  if (z == candidateZone[i]) candidateCount[i]++;
  else { candidateZone[i] = z; candidateCount[i] = 1; }

  if (candidateCount[i] >= CONFIRM_CYCLES) {
    confirmedZone[i] = z;
    candidateCount[i] = 0;
    lastGoodDist[i] = raw;
    return raw;
  }
  return confirmedZone[i] == ZONE_UNKNOWN ? -1.0f : lastGoodDist[i];
}
// <<STABILIZE_END>>

// อ่านซ้ำ SAMPLES ครั้ง ตัดค่านอกช่วง แล้วใช้ค่ามัธยฐาน ลดค่ากระโดดจากสัญญาณรบกวน
float readDistance(int trigPin, int echoPin) {
  float values[SAMPLES];
  int count = 0;

  for (int i = 0; i < SAMPLES; i++) {
    float d = readDistanceOnce(trigPin, echoPin);
    if (d >= 2.0f && d <= 400.0f) values[count++] = d;
    delay(SAMPLE_GAP_MS);
  }

  // ต้องอ่านสำเร็จอย่างน้อย MIN_VALID_SAMPLES ครั้ง ไม่เช่นนั้นถือว่ารอบนี้อ่านไม่ได้
  if (count < MIN_VALID_SAMPLES) return -1.0f;

  for (int i = 0; i < count - 1; i++) {
    for (int j = i + 1; j < count; j++) {
      if (values[i] > values[j]) {
        float temp = values[i];
        values[i] = values[j];
        values[j] = temp;
      }
    }
  }
  return values[count / 2];
}

// ==================================================
// อ่าน Sensor
// ==================================================

void readSensors() {
  for (int i = 0; i < 3; i++) {
    int trig = (i == 0) ? TRIG1 : (i == 1) ? TRIG2 : TRIG3;
    int echo = (i == 0) ? ECHO1 : (i == 1) ? ECHO2 : ECHO3;
    float raw = readDistance(trig, echo);
    distanceCM[i] = stabilizeDistance(i, raw);
    Serial.printf("Slot %d raw=%.1f stable=%.1f zone=%d cand=%d/%d\n",
                  i + 1, raw, distanceCM[i], (int)confirmedZone[i],
                  candidateCount[i], CONFIRM_CYCLES);
    delay(100);   // เว้นระหว่างเซนเซอร์แต่ละตัว กัน crosstalk
  }
}

// ==================================================

// อ่านสถานะจาก Server

// ==================================================


// ดึงสถานะจากเซิร์ฟเวอร์เพื่อให้ไฟ LED ตามคำสั่งฝั่งเว็บ (เช่น แอดมินอนุมัติจอง -> reserved)

// กฎ: มีรถจริง (< 25 cm) เป็น occupied เสมอ / ไม่มีรถแล้วเซิร์ฟเวอร์บอก reserved/available ให้ตามเซิร์ฟเวอร์

void getServerStatus() {


  if (WiFi.status() != WL_CONNECTED) {

    return;

  }


  HTTPClient http;


  http.begin(STATUS_URL);


  int httpCode =

    http.GET();


  if (httpCode != 200) {


    http.end();

    return;

  }


  String payload =

    http.getString();


  http.end();


  DynamicJsonDocument doc(2048);


  DeserializationError error =

    deserializeJson(doc, payload);


  if (error) {

    return;

  }


  if (doc["status"] != "success") {

    return;

  }


  // ----------------------------------------------

  // อ่านสถานะของแต่ละช่อง

  // ----------------------------------------------


  for (int i = 0; i < 3; i++) {


    String key =

      "slot" + String(i + 1);


    if (!doc["slots"][key]["status"]) {

      continue;

    }


    String serverStatus =

      doc["slots"][key]["status"]

        .as<String>();


    float d = distanceCM[i];


    // อ่านเซนเซอร์ไม่ได้ (เช่น เพิ่งเปิดเครื่อง) -> ถ้าไม่ใช่ occupied ให้ไฟตามเซิร์ฟเวอร์ (reserved=ฟ้า / available=เขียว)
    if (d < 0) {
      if (slotStatus[i] != "occupied" &&
          (serverStatus == "reserved" || serverStatus == "available")) {
        slotStatus[i] = serverStatus;
      }
      continue;
    }


    /*

       - มีรถจริง (< 25 cm)          -> OCCUPIED เสมอ

       - ไม่มีรถ และ Server = reserved/available

                                       -> ใช้ค่าจาก Server

         (Admin อนุมัติ/ยกเลิกการจอง แล้วไฟต้องเปลี่ยนตามทันที)

       - Server = occupied แต่รถออกแล้ว

                                       -> ปล่อยให้ updateStatus()

                                          เปลี่ยนเป็น AVAILABLE

                                          แล้วส่งขึ้น Server

    */

    if (d < OCCUPIED_DISTANCE) {


      slotStatus[i] = "occupied";

    }

    else if (

      serverStatus == "reserved" ||

      serverStatus == "available"

    ) {


      slotStatus[i] = serverStatus;

    }

  }

}


// ==================================================

// ประมวลผลสถานะจาก Sensor

// ==================================================


// ตัดสินสถานะจากระยะ: < 25 = occupied, >= 25 = รถออก (occupied -> available) แล้วอัปเดตสีไฟ

void updateStatus() {


  for (int i = 0; i < 3; i++) {


    float d = distanceCM[i];


    if (d < 0) {
      setRGB(i, slotStatus[i]);   // อ่านไม่ได้: ยังอัปเดตไฟตามสถานะล่าสุด
      continue;
    }


    // ==============================================

    // รถเข้า

    // ==============================================


    if (d < OCCUPIED_DISTANCE) {


      /*

         ไม่ว่าจะจองหรือไม่จอง

         ถ้ามีรถจริง = OCCUPIED

      */


      slotStatus[i] = "occupied";

    }


    // ==============================================

    // รถออก

    // ==============================================


    else if (d >= AVAILABLE_DISTANCE) {


      /*

         ถ้ารถออกจากช่อง

         กลับ AVAILABLE


         แต่ RESERVED ที่ยังไม่มีรถ

         ต้องยังเป็น RESERVED

      */


      if (slotStatus[i] == "occupied") {


        slotStatus[i] = "available";

      }

    }


    // ==============================================

    // 25-35 cm

    // ==============================================


    else {


      // คงสถานะเดิม

    }


    // อัปเดต LED

    setRGB(

      i,

      slotStatus[i]

    );

  }

}


// ==================================================

// ส่งข้อมูลไป PHP

// ==================================================


// ส่งระยะของทั้ง 3 ช่องเป็น JSON ไป SENSOR_URL (POST) — เซิร์ฟเวอร์ใช้ค่า distance ไปสร้าง/ปิด parking session

void sendToServer() {


  if (WiFi.status() != WL_CONNECTED) {

    return;

  }


  HTTPClient http;


  http.begin(SENSOR_URL);


  http.addHeader(

    "Content-Type",

    "application/json"

  );


  DynamicJsonDocument doc(1024);


  for (int i = 0; i < 3; i++) {


    String key =

      "slot" + String(i + 1);


    doc[key]["distance"] =

      distanceCM[i];


    doc[key]["status"] =

      slotStatus[i];

  }


  String json;


  serializeJson(

    doc,

    json

  );


  int httpCode =

    http.POST(json);


  Serial.println();

  Serial.println("================================");


  Serial.print("HTTP Code: ");

  Serial.println(httpCode);


  Serial.println("JSON:");

  Serial.println(json);


  if (httpCode > 0) {


    String response =

      http.getString();


    Serial.println("Server:");

    Serial.println(response);

  }


  http.end();

}


// ==================================================

// Setup

// ==================================================


// ตั้งค่าเริ่มต้น: ขา TRIG/ECHO, ขา RGB LED และเชื่อมต่อ WiFi

void setup() {


  Serial.begin(115200);


  // ----------------------------------------------

  // Ultrasonic

  // ----------------------------------------------


  pinMode(TRIG1, OUTPUT);

  pinMode(ECHO1, INPUT);


  pinMode(TRIG2, OUTPUT);

  pinMode(ECHO2, INPUT);


  pinMode(TRIG3, OUTPUT);

  pinMode(ECHO3, INPUT);


  // ----------------------------------------------

  // RGB

  // ----------------------------------------------


  pinMode(R1, OUTPUT);

  pinMode(G1, OUTPUT);

  pinMode(B1, OUTPUT);


  pinMode(R2, OUTPUT);

  pinMode(G2, OUTPUT);

  pinMode(B2, OUTPUT);


  pinMode(R3, OUTPUT);

  pinMode(G3, OUTPUT);

  pinMode(B3, OUTPUT);


  // ----------------------------------------------

  // เริ่มต้นเป็นเขียว

  // ----------------------------------------------


  setRGB(0, "available");

  setRGB(1, "available");

  setRGB(2, "available");


  // ----------------------------------------------

  // WiFi

  // ----------------------------------------------


  WiFi.begin(

    WIFI_SSID,

    WIFI_PASSWORD

  );


  Serial.print("Connecting WiFi");


  while (

    WiFi.status() != WL_CONNECTED

  ) {


    delay(500);


    Serial.print(".");

  }


  Serial.println();

  Serial.println("WiFi Connected");


  Serial.print("ESP32 IP: ");

  Serial.println(

    WiFi.localIP()

  );
}


// ==================================================

// Loop

// ==================================================


void loop() {


  // 1. อ่านระยะ

  readSensors();


  // 2. อ่านสถานะการจองจาก Server

  getServerStatus();


  // 3. ประมวลผลรถเข้า/ออก

  updateStatus();
  // 4. ส่งข้อมูลเข้า PHP

  sendToServer();


  Serial.println();

  Serial.println("----------- STATUS -----------");


  for (int i = 0; i < 3; i++) {


    Serial.print("Slot ");

    Serial.print(i + 1);


    Serial.print(" | Distance: ");

    Serial.print(distanceCM[i]);


    Serial.print(" cm | Status: ");

    Serial.println(slotStatus[i]);

  }


  // วนรอบละประมาณ 3 วินาที (อ่านเซนเซอร์ ~1.5 วิ + หน่วง 1.5 วิ) (ความถี่ในการอ่านเซนเซอร์และส่งข้อมูลขึ้นเซิร์ฟเวอร์)

  delay(1500);

}