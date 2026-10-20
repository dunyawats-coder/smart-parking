#include "esp_camera.h"
#include <WiFi.h>
#include "secrets.h"
#include <WebServer.h>
#include <HTTPClient.h>

// =====================================================
// CAMERA CONFIG
// =====================================================

#define CAMERA_MODEL_AI_THINKER

// [ข้อควรระวัง] CAMERA_ID ต้องตรงกับ camera_id ใน TRIGGER_URL ด้านล่าง
// ตอนนี้ CAMERA_ID เป็น CAM3 แต่ TRIGGER_URL ใช้ camera_id=CAM1 — ควรแก้ให้ตรงกัน (CAM1=ช่อง 1, CAM2=ช่อง 2, CAM3=ช่อง 3)
#define CAMERA_ID "CAM1"
#define CAMERA_SLOT_ID 1

// =====================================================
// AI THINKER ESP32-CAM PIN
// =====================================================

#define PWDN_GPIO_NUM     32
#define RESET_GPIO_NUM    -1
#define XCLK_GPIO_NUM      0
#define SIOD_GPIO_NUM     26
#define SIOC_GPIO_NUM     27

#define Y9_GPIO_NUM       35
#define Y8_GPIO_NUM       34
#define Y7_GPIO_NUM       39
#define Y6_GPIO_NUM       36
#define Y5_GPIO_NUM       21
#define Y4_GPIO_NUM       19
#define Y3_GPIO_NUM       18
#define Y2_GPIO_NUM       5

#define VSYNC_GPIO_NUM    25
#define HREF_GPIO_NUM     23
#define PCLK_GPIO_NUM     22

// =====================================================
// FLASH LED
// =====================================================

#define FLASH_LED_PIN 4

// =====================================================
// WIFI
// =====================================================

const char* WIFI_SSID = SECRET_WIFI_SSID;
const char* WIFI_PASSWORD = SECRET_WIFI_PASSWORD;

// =====================================================
// STATIC IP
// =====================================================

// IP คงที่ของบอร์ดกล้อง (ต้องอยู่ในวงเดียวกับเซิร์ฟเวอร์ และไม่ชนกับอุปกรณ์อื่น)
IPAddress local_IP(10, 221, 222, 25);
IPAddress gateway(10, 221, 222, 169);
IPAddress subnet(255, 255, 255, 0);

IPAddress dns1(8, 8, 8, 8);
IPAddress dns2(1, 1, 1, 1);

// =====================================================
// SERVER
// =====================================================

// TRIGGER_URL = ถามเซิร์ฟเวอร์ว่าควรถ่ายรูปไหม (camera_trigger.php) / UPLOAD_URL = ส่งรูปขึ้นเซิร์ฟเวอร์ (camera_upload.php)
const char* TRIGGER_URL =
  "http://" SECRET_SERVER_IP "/smart_parking_integrated/camera_trigger.php?camera_id=CAM1";

const char* UPLOAD_URL =
  "http://" SECRET_SERVER_IP "/smart_parking_integrated/camera_upload.php";

// =====================================================
// WEB SERVER
// =====================================================

WebServer server(80);

// =====================================================
// STREAM SERVER
// =====================================================

WiFiServer streamServer(81);

// =====================================================
// TIMER
// =====================================================

unsigned long lastCheck = 0;

const unsigned long CHECK_INTERVAL = 2000;


// =====================================================
// CAMERA INITIALIZATION
// =====================================================

// ตั้งค่าและเริ่มโมดูลกล้อง (รุ่น AI-Thinker ESP32-CAM)
bool startCamera()
{
  camera_config_t config;

  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;

  config.pin_d0 = Y2_GPIO_NUM;
  config.pin_d1 = Y3_GPIO_NUM;
  config.pin_d2 = Y4_GPIO_NUM;
  config.pin_d3 = Y5_GPIO_NUM;
  config.pin_d4 = Y6_GPIO_NUM;
  config.pin_d5 = Y7_GPIO_NUM;
  config.pin_d6 = Y8_GPIO_NUM;
  config.pin_d7 = Y9_GPIO_NUM;

  config.pin_xclk = XCLK_GPIO_NUM;

  config.pin_pclk = PCLK_GPIO_NUM;
  config.pin_vsync = VSYNC_GPIO_NUM;
  config.pin_href = HREF_GPIO_NUM;

  config.pin_sccb_sda = SIOD_GPIO_NUM;
  config.pin_sccb_scl = SIOC_GPIO_NUM;

  config.pin_pwdn = PWDN_GPIO_NUM;
  config.pin_reset = RESET_GPIO_NUM;

  config.xclk_freq_hz = 20000000;

  config.pixel_format = PIXFORMAT_JPEG;

  // ===================================================
  // CAMERA QUALITY
  // ===================================================

  config.frame_size = FRAMESIZE_QVGA;
  config.jpeg_quality = 12;
  config.fb_count = 1;

  esp_err_t result = esp_camera_init(&config);

  if (result != ESP_OK)
  {
    Serial.print("Camera init failed: 0x");
    Serial.println(result, HEX);

    return false;
  }

  Serial.println("Camera initialized successfully.");

  return true;
}


// =====================================================
// FLASH
// =====================================================

void flashOn()
{
  digitalWrite(FLASH_LED_PIN, HIGH);
}

void flashOff()
{
  digitalWrite(FLASH_LED_PIN, LOW);
}


// =====================================================
// CAPTURE PHOTO
// =====================================================

camera_fb_t* takePhoto()
{
  Serial.println();
  Serial.println("--------------------------------");
  Serial.println("CAPTURE REQUEST RECEIVED");
  Serial.println("--------------------------------");

  Serial.println("Starting capture...");

  // เปิด Flash
  flashOn();

  delay(150);

  // ถ่ายภาพ
  camera_fb_t* fb = esp_camera_fb_get();

  // ปิด Flash
  flashOff();

  if (fb == NULL)
  {
    Serial.println("ERROR: Camera capture failed");

    return NULL;
  }

  Serial.print("Photo size: ");
  Serial.print(fb->len);
  Serial.println(" bytes");

  return fb;
}


// =====================================================
// UPLOAD PHOTO
// =====================================================

// ถ่ายรูปแล้วส่ง multipart/form-data ไป UPLOAD_URL (ฟิลด์ camera_id, slot_id, image)
bool uploadPhoto(
  camera_fb_t* fb,
  String event,
  int slotId
)
{
  if (fb == NULL)
  {
    Serial.println("ERROR: Frame buffer is NULL");

    return false;
  }

  WiFiClient client;
  HTTPClient http;

  Serial.println();
  Serial.println("Uploading photo...");

  // ===================================================
  // HTTP BEGIN
  // ===================================================

  if (!http.begin(client, UPLOAD_URL))
  {
    Serial.println("HTTP begin failed");

    return false;
  }

  http.setTimeout(15000);

  // ===================================================
  // MULTIPART BOUNDARY
  // ===================================================

  String boundary = "ESP32CAMBoundary";

  // ===================================================
  // FILENAME
  // ===================================================

  String filename =
    String(CAMERA_ID) +
    "_SLOT" +
    String(slotId) +
    "_" +
    event +
    "_" +
    String(millis()) +
    ".jpg";

  // ===================================================
  // MULTIPART START
  // ===================================================

  String bodyStart =
    "--" + boundary + "\r\n"
    "Content-Disposition: form-data; name=\"camera_id\"\r\n\r\n" +
    String(CAMERA_ID) +
    "\r\n"

    "--" + boundary + "\r\n"
    "Content-Disposition: form-data; name=\"slot_id\"\r\n\r\n" +
    String(slotId) +
    "\r\n"

    "--" + boundary + "\r\n"
    "Content-Disposition: form-data; name=\"event\"\r\n\r\n" +
    event +
    "\r\n"

    "--" + boundary + "\r\n"
    "Content-Disposition: form-data; name=\"image\"; filename=\"" +
    filename +
    "\"\r\n"
    "Content-Type: image/jpeg\r\n\r\n";

  // ===================================================
  // MULTIPART END
  // ===================================================

  String bodyEnd =
    "\r\n--" + boundary + "--\r\n";

  // ===================================================
  // TOTAL SIZE
  // ===================================================

  size_t totalSize =
    bodyStart.length() +
    fb->len +
    bodyEnd.length();

  Serial.print("Upload body size: ");
  Serial.print(totalSize);
  Serial.println(" bytes");

  // ===================================================
  // CONTENT TYPE
  // ===================================================

  String contentType =
    "multipart/form-data; boundary=" + boundary;

  http.addHeader(
    "Content-Type",
    contentType
  );

  // ===================================================
  // ALLOCATE MEMORY
  // ===================================================

  uint8_t* body =
    (uint8_t*)malloc(totalSize);

  if (body == NULL)
  {
    Serial.println(
      "ERROR: Not enough memory for upload"
    );

    http.end();

    return false;
  }

  // ===================================================
  // COPY HEADER
  // ===================================================

  size_t position = 0;

  memcpy(
    body + position,
    bodyStart.c_str(),
    bodyStart.length()
  );

  position += bodyStart.length();

  // ===================================================
  // COPY IMAGE
  // ===================================================

  memcpy(
    body + position,
    fb->buf,
    fb->len
  );

  position += fb->len;

  // ===================================================
  // COPY FOOTER
  // ===================================================

  memcpy(
    body + position,
    bodyEnd.c_str(),
    bodyEnd.length()
  );

  position += bodyEnd.length();

  // ===================================================
  // POST
  // ===================================================

  Serial.print("Sending ");
  Serial.print(position);
  Serial.println(" bytes...");

  int httpCode =
    http.POST(body, position);

  // Free memory
  free(body);

  Serial.print("HTTP code: ");
  Serial.println(httpCode);

  // ===================================================
  // SERVER RESPONSE
  // ===================================================

  if (httpCode > 0)
  {
    String response =
      http.getString();

    Serial.println();
    Serial.println("Server response:");
    Serial.println(response);

    http.end();

    if (httpCode == 200)
    {
      Serial.println();
      Serial.println("UPLOAD SUCCESS");
      Serial.println();

      return true;
    }
  }

  http.end();

  Serial.println();
  Serial.println("UPLOAD FAILED");
  Serial.println();

  return false;
}


// =====================================================
// JSON STRING
// =====================================================

String getString(
  String json,
  String key
)
{
  String findText =
    "\"" + key + "\":\"";

  int start =
    json.indexOf(findText);

  if (start < 0)
  {
    return "";
  }

  start += findText.length();

  int end =
    json.indexOf("\"", start);

  if (end < 0)
  {
    return "";
  }

  return json.substring(
    start,
    end
  );
}


// =====================================================
// JSON INT
// =====================================================

int getInt(
  String json,
  String key
)
{
  String findText =
    "\"" + key + "\":";

  int start =
    json.indexOf(findText);

  if (start < 0)
  {
    return -1;
  }

  start += findText.length();

  while (
    start < json.length() &&
    json[start] == ' '
  )
  {
    start++;
  }

  int end = start;

  while (
    end < json.length() &&
    isDigit(json[end])
  )
  {
    end++;
  }

  if (end == start)
  {
    return -1;
  }

  return json.substring(
    start,
    end
  ).toInt();
}


// =====================================================
// JSON BOOL
// =====================================================

bool getBool(
  String json,
  String key
)
{
  String findText =
    "\"" + key + "\":";

  int start =
    json.indexOf(findText);

  if (start < 0)
  {
    return false;
  }

  start += findText.length();

  while (
    start < json.length() &&
    json[start] == ' '
  )
  {
    start++;
  }

  if (
    start + 4 <= json.length()
  )
  {
    return
      json.substring(
        start,
        start + 4
      ) == "true";
  }

  return false;
}


// =====================================================
// CHECK CAMERA TRIGGER
// =====================================================

// เรียก TRIGGER_URL แล้ว parse JSON ฟิลด์ capture: ถ้า true จึงถ่ายและอัปโหลด (ถ้า false ไม่ทำอะไร)
void checkTrigger()
{
  // ===================================================
  // CHECK WIFI
  // ===================================================

  if (WiFi.status() != WL_CONNECTED)
  {
    Serial.println(
      "WiFi disconnected."
    );

    return;
  }

  WiFiClient client;
  HTTPClient http;

  // ===================================================
  // CONNECT TRIGGER SERVER
  // ===================================================

  if (!http.begin(
    client,
    TRIGGER_URL
  ))
  {
    Serial.println(
      "Cannot connect trigger server"
    );

    return;
  }

  http.setTimeout(5000);

  // ===================================================
  // GET
  // ===================================================

  int code = http.GET();

  if (code <= 0)
  {
    Serial.print(
      "Trigger error: "
    );

    Serial.println(
      http.errorToString(code)
    );

    http.end();

    return;
  }

  // ===================================================
  // READ JSON
  // ===================================================

  String json =
    http.getString();

  http.end();

  Serial.println();
  Serial.println("TRIGGER:");
  Serial.println(json);

  // ===================================================
  // PARSE EVENT
  // ===================================================

  String event =
    getString(
      json,
      "event"
    );

  // ===================================================
  // PARSE SLOT
  // ===================================================

  int slotId =
    getInt(
      json,
      "slot_id"
    );

  // ===================================================
  // PARSE CAPTURE
  // ===================================================

  bool capture =
    getBool(
      json,
      "capture"
    );

  // ===================================================
  // DEBUG
  // ===================================================

  Serial.print(
    "Parsed event: "
  );

  Serial.println(event);

  Serial.print(
    "Parsed slot_id: "
  );

  Serial.println(slotId);

  Serial.print(
    "Parsed capture: "
  );

  Serial.println(
    capture ? "true" : "false"
  );

  if (event == "vehicle_still_parked") {
    Serial.println("Vehicle is already parked; waiting for a new vehicle_enter event.");
  }

  // ===================================================
  // NO CAPTURE
  // ===================================================

  if (!capture)
  {
    Serial.println(
      "No capture command."
    );

    return;
  }

  // ===================================================
  // CHECK SLOT
  // ===================================================

  if (slotId != CAMERA_SLOT_ID)
  {
    Serial.println(
      "Slot does not belong to this camera"
    );

    return;
  }

  // ===================================================
  // ONLY VEHICLE ENTER
  // ===================================================

  if (event != "vehicle_enter")
  {
    Serial.println(
      "Capture command is not vehicle_enter."
    );

    return;
  }

  // ===================================================
  // CAPTURE
  // ===================================================

  camera_fb_t* fb =
    takePhoto();

  if (fb == NULL)
  {
    Serial.println(
      "Capture failed."
    );

    return;
  }

  // ===================================================
  // UPLOAD
  // ===================================================

  bool success =
    uploadPhoto(
      fb,
      event,
      slotId
    );

  // ===================================================
  // RETURN CAMERA BUFFER
  // ===================================================

  esp_camera_fb_return(fb);

  // ===================================================
  // RESULT
  // ===================================================

  if (success)
  {
    Serial.println(
      "================================"
    );

    Serial.println(
      "EVENT COMPLETED"
    );

    Serial.println(
      "PHOTO UPLOADED"
    );

    Serial.println(
      "================================"
    );
  }
  else
  {
    Serial.println(
      "================================"
    );

    Serial.println(
      "EVENT FAILED"
    );

    Serial.println(
      "UPLOAD FAILED - WILL RETRY"
    );

    Serial.println(
      "================================"
    );
  }
}


// =====================================================
// WEB ROOT
// =====================================================

// หน้าเว็บของบอร์ดกล้องเอง (เปิดผ่าน IP ของบอร์ด) มีลิงก์ถ่ายภาพ/สตรีมสำหรับทดสอบ
void handleRoot()
{
  String html;

  html += "<!DOCTYPE html>";
  html += "<html>";
  html += "<head>";
  html += "<meta charset='UTF-8'>";
  html += "<meta name='viewport' content='width=device-width'>";
  html += "<title>Smart Parking CAM1</title>";
  html += "</head>";

  html += "<body>";

  html += "<h1>Smart Parking CAM1</h1>";

  html += "<p><b>Camera:</b> CAM1</p>";

  html += "<p><b>Slot:</b> 1</p>";

  html += "<p><b>IP:</b> ";
  html += WiFi.localIP().toString();
  html += "</p>";

  html += "<hr>";

  html += "<h3>Live Camera</h3>";

  html += "<img src='http://";
  html += WiFi.localIP().toString();
  html += ":81/' width='320'>";

  html += "<hr>";

  html += "<p>";

  html += "<a href='/capture'>";
  html += "TEST CAPTURE";
  html += "</a>";

  html += "</p>";

  html += "</body>";
  html += "</html>";

  server.send(
    200,
    "text/html",
    html
  );
}


// =====================================================
// TEST CAPTURE
// =====================================================

// เส้นทาง /capture : ถ่ายภาพหนึ่งภาพแล้วส่งกลับให้เบราว์เซอร์
void handleCapture()
{
  camera_fb_t* fb =
    takePhoto();

  if (fb == NULL)
  {
    server.send(
      500,
      "text/plain",
      "Capture failed"
    );

    return;
  }

  server.sendHeader(
    "Content-Type",
    "image/jpeg"
  );

  server.send_P(
    200,
    "image/jpeg",
    (const char*)fb->buf,
    fb->len
  );

  esp_camera_fb_return(fb);
}


// =====================================================
// CAMERA STREAM
// =====================================================

// เส้นทาง /stream : ส่งภาพต่อเนื่องแบบ MJPEG สำหรับดูสด
void handleStream()
{
  WiFiClient client =
    streamServer.available();

  if (!client)
  {
    return;
  }

  Serial.println(
    "Stream client connected"
  );

  // ===================================================
  // HTTP HEADER
  // ===================================================

  client.println(
    "HTTP/1.1 200 OK"
  );

  client.println(
    "Content-Type: multipart/x-mixed-replace; boundary=frame"
  );

  client.println(
    "Cache-Control: no-cache"
  );

  client.println(
    "Access-Control-Allow-Origin: *"
  );

  client.println();

  // ===================================================
  // STREAM
  // ===================================================

  unsigned long startTime =
    millis();

  while (
    client.connected() &&
    millis() - startTime < 30000
  )
  {
    camera_fb_t* fb =
      esp_camera_fb_get();

    if (!fb)
    {
      Serial.println(
        "Stream camera capture failed"
      );

      break;
    }

    client.println(
      "--frame"
    );

    client.println(
      "Content-Type: image/jpeg"
    );

    client.print(
      "Content-Length: "
    );

    client.println(
      fb->len
    );

    client.println();

    client.write(
      fb->buf,
      fb->len
    );

    client.println();

    esp_camera_fb_return(fb);

    delay(100);
  }

  client.stop();

  Serial.println(
    "Stream client disconnected"
  );
}


// =====================================================
// WIFI CONNECT
// =====================================================

// เชื่อมต่อ WiFi ด้วย IP คงที่ (ถ้าไม่ติดจะลองใหม่)
bool connectWiFi()
{
  Serial.println();
  Serial.println(
    "Connecting WiFi..."
  );

  WiFi.mode(WIFI_STA);

  // ===================================================
  // STATIC IP
  // ===================================================

  if (!WiFi.config(
    local_IP,
    gateway,
    subnet,
    dns1,
    dns2
  ))
  {
    Serial.println(
      "Static IP configuration failed"
    );
  }

  // ===================================================
  // CONNECT
  // ===================================================

  WiFi.begin(
    WIFI_SSID,
    WIFI_PASSWORD
  );

  int count = 0;

  while (
    WiFi.status() != WL_CONNECTED &&
    count < 40
  )
  {
    delay(500);

    Serial.print(".");

    count++;
  }

  Serial.println();

  // ===================================================
  // FAILED
  // ===================================================

  if (
    WiFi.status() != WL_CONNECTED
  )
  {
    Serial.println(
      "WiFi connection FAILED"
    );

    return false;
  }

  // ===================================================
  // SUCCESS
  // ===================================================

  Serial.println(
    "WiFi connected"
  );

  Serial.print(
    "IP: "
  );

  Serial.println(
    WiFi.localIP()
  );

  return true;
}


// =====================================================
// SETUP
// =====================================================

void setup()
{
  Serial.begin(115200);

  delay(1000);

  Serial.println();
  Serial.println(
    "================================"
  );

  Serial.println(
    "SMART PARKING ESP32-CAM"
  );

  Serial.println(
    "================================"
  );

  // ===================================================
  // FLASH
  // ===================================================

  pinMode(
    FLASH_LED_PIN,
    OUTPUT
  );

  flashOff();

  // ===================================================
  // WIFI
  // ===================================================

  if (!connectWiFi())
  {
    Serial.println(
      "WiFi failed. Restarting..."
    );

    delay(3000);

    ESP.restart();
  }

  // ===================================================
  // CAMERA
  // ===================================================

  if (!startCamera())
  {
    Serial.println(
      "Camera failed."
    );

    while (true)
    {
      delay(1000);
    }
  }

  // ===================================================
  // WEB SERVER
  // ===================================================

  server.on(
    "/",
    HTTP_GET,
    handleRoot
  );

  server.on(
    "/capture",
    HTTP_GET,
    handleCapture
  );

  server.begin();

  // ===================================================
  // STREAM SERVER
  // ===================================================

  streamServer.begin();

  // ===================================================
  // READY
  // ===================================================

  Serial.println();

  Serial.println(
    "================================"
  );

  Serial.println(
    "SYSTEM READY"
  );

  Serial.print(
    "CAMERA: "
  );

  Serial.println(
    CAMERA_ID
  );

  Serial.print(
    "SLOT: "
  );

  Serial.println(
    CAMERA_SLOT_ID
  );

  Serial.print(
    "IP: "
  );

  Serial.println(
    WiFi.localIP()
  );

  Serial.print("WEB: http://");
  Serial.print(WiFi.localIP());
  Serial.println("/");

  Serial.print("STREAM: http://");
  Serial.print(WiFi.localIP());
  Serial.println(":81/");

  Serial.println(
    "TRIGGER: SERVER"
  );

  Serial.println(
    "CAPTURE MODE: 1 PHOTO / SESSION"
  );

  Serial.println(
    "================================"
  );
}


// =====================================================
// LOOP
// =====================================================

// ทุก ๆ CHECK_INTERVAL (ประมาณ 2 วินาทีตาม README) เรียก checkTrigger() หนึ่งครั้ง ส่วนที่เหลือคือให้เว็บเซิร์ฟเวอร์ของบอร์ดตอบ request
void loop()
{
  // ===================================================
  // WEB SERVER
  // ===================================================

  server.handleClient();

  // ===================================================
  // CAMERA STREAM
  // ===================================================

  handleStream();

  // ===================================================
  // TRIGGER POLLING
  // ===================================================

  if (
    millis() - lastCheck >=
    CHECK_INTERVAL
  )
  {
    lastCheck = millis();

    checkTrigger();
  }

  delay(2);
}