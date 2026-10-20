ESP32-CAM Smart Parking

CAM1: slot 1. Copy the sketch and change CAMERA_ID/TRIGGER_URL for CAM2/CAM3.
PHP endpoints: camera_trigger.php and camera_upload.php.
Images are saved to D:\SmartParking\Camera.
The camera polls the server every 2 seconds. It captures once when the slot changes to occupied (vehicle_enter) and once when it changes to available (vehicle_exit). Reserved does not trigger a capture.

Before use: ensure Apache has permission to create/write D:\SmartParking\Camera.
