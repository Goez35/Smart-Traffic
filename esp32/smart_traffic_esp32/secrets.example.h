#ifndef SECRETS_H
#define SECRETS_H

/**
 * Contoh Konfigurasi Kredensial Wi-Fi & EMQX Cloud (ESP32)
 * CARA PAKAI:
 * 1. Salin file ini menjadi `secrets.h` di folder yang sama dengan `smart_traffic_esp32.ino`.
 * 2. Isi SSID Wi-Fi, password Wi-Fi, dan detail broker EMQX Cloud Anda.
 * 3. File `secrets.h` sudah masuk `.gitignore` sehingga aman dan tidak ikut ter-commit.
 */

// Kredensial Wi-Fi
#define WIFI_SSID     "NAMA_WIFI_ANDA"
#define WIFI_PASSWORD "PASSWORD_WIFI_ANDA"

// Konfigurasi Broker EMQX Cloud (TLS Port 8883)
#define MQTT_BROKER   "xxxx.emqxsl.com"
#define MQTT_PORT     8883
#define MQTT_USE_TLS  1   // 1 = Gunakan TLS/SSL (Port 8883 EMQX Cloud), 0 = Tanpa TLS (Port 1883 Lokal)
#define MQTT_USERNAME "smarttraffic_esp32"
#define MQTT_PASSWORD "password_emqx_anda"

// Identitas Perangkat
#define DEVICE_ID     "tl-01"

#endif
