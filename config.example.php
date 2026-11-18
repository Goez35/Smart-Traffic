<?php
/**
 * Contoh File Konfigurasi Lokal (Database & EMQX Cloud MQTT)
 * Salin file ini menjadi `config.php` dan sesuaikan dengan kredensial Anda.
 * File `config.php` sudah dimasukkan ke `.gitignore` agar kredensial aman.
 */

// 1. Konfigurasi Database MySQL / MariaDB (XAMPP)
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'traffic_db');
define('DB_PORT', 3307);

// 2. Konfigurasi Broker EMQX Cloud (Serverless / TLS)
define('MQTT_HOST', 'xxxx.s1.eu.hivemq.cloud'); // Ganti dengan alamat host EMQX Cloud Anda (mis. xxxx.emqxsl.com)
define('MQTT_PORT', 8883);                      // Port 8883 untuk TLS (EMQX Cloud) atau 1883 untuk non-TLS
define('MQTT_USE_TLS', true);                   // true = gunakan koneksi terenkripsi TLS/SSL
define('MQTT_USER', 'smarttraffic_worker');     // Username autentikasi di EMQX Cloud
define('MQTT_PASS', 'ganti_password_mqtt_anda');// Password autentikasi di EMQX Cloud
define('MQTT_DEVICE_ID', 'tl-01');              // ID perangkat ESP32 (topik: smarttraffic/tl-01/...)

// 3. Kunci API Cadangan untuk Endpoint HTTP Lama (Deprecated)
define('ESP32_API_KEY', 'ganti_api_key_rahasia_anda');
?>
