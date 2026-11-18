-- Database: traffic_db
-- Skema Database Smart Adaptive Traffic Light (4 Jalur + MQTT Cloud Worker)
CREATE DATABASE IF NOT EXISTS `traffic_db`;
USE `traffic_db`;

-- =========================================================================
-- 1. Tabel: status_persimpangan (Dipertahankan + Diperluas untuk Sensor Jarak)
-- =========================================================================
CREATE TABLE IF NOT EXISTS `status_persimpangan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_simpang` varchar(100) NOT NULL,
  `mode` enum('auto','manual') NOT NULL DEFAULT 'auto',
  `lampu_aktif` enum('merah','kuning','hijau') NOT NULL DEFAULT 'merah',
  `durasi_hijau` int(11) NOT NULL DEFAULT 5,
  `jarak_cm` decimal(5,1) NOT NULL DEFAULT 30.0 COMMENT 'Jarak objek dari sensor HC-SR04 (cm)',
  `rasio_terisi` decimal(5,1) NOT NULL DEFAULT 0.0 COMMENT 'Persentase terisi dalam jendela 5 detik (%)',
  `kepadatan` enum('Sepi','Sedang','Padat') NOT NULL DEFAULT 'Sepi' COMMENT 'Klasifikasi kepadatan jalur',
  `jumlah_kendaraan` int(11) NOT NULL DEFAULT 0 COMMENT '[DEPRECATED] Dipertahankan untuk kompatibilitas lama',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrasi aman jika tabel sudah ada dari versi sebelumnya
ALTER TABLE `status_persimpangan`
  ADD COLUMN IF NOT EXISTS `jarak_cm` decimal(5,1) NOT NULL DEFAULT 30.0 COMMENT 'Jarak objek dari sensor HC-SR04 (cm)' AFTER `durasi_hijau`,
  ADD COLUMN IF NOT EXISTS `rasio_terisi` decimal(5,1) NOT NULL DEFAULT 0.0 COMMENT 'Persentase terisi dalam jendela 5 detik (%)' AFTER `jarak_cm`,
  ADD COLUMN IF NOT EXISTS `kepadatan` enum('Sepi','Sedang','Padat') NOT NULL DEFAULT 'Sepi' COMMENT 'Klasifikasi kepadatan jalur' AFTER `rasio_terisi`;

-- Data Awal 4 Jalur Persimpangan (Invarian Keselamatan: Hanya 1 jalur boleh hijau pada satu waktu)
INSERT INTO `status_persimpangan` (`id`, `nama_simpang`, `mode`, `lampu_aktif`, `durasi_hijau`, `jarak_cm`, `rasio_terisi`, `kepadatan`, `jumlah_kendaraan`) VALUES
(1, 'Jalur 1 (A) - Utara (Sudirman)', 'auto', 'hijau', 10, 3.8, 50.0, 'Sedang', 1),
(2, 'Jalur 2 (B) - Timur (Thamrin)', 'auto', 'merah', 5, 25.0, 0.0, 'Sepi', 0),
(3, 'Jalur 3 (C) - Selatan (Gatot Subroto)', 'auto', 'merah', 5, 28.0, 0.0, 'Sepi', 0),
(4, 'Jalur 4 (D) - Barat (Diponegoro)', 'auto', 'merah', 5, 30.0, 0.0, 'Sepi', 0)
ON DUPLICATE KEY UPDATE
  `nama_simpang` = VALUES(`nama_simpang`);

-- =========================================================================
-- 2. Tabel: log_lalu_lintas (Dipertahankan + Kolom Jarak & Kepadatan)
-- =========================================================================
CREATE TABLE IF NOT EXISTS `log_lalu_lintas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `simpang_id` int(11) NOT NULL,
  `jarak_cm` decimal(5,1) NOT NULL DEFAULT 30.0,
  `rasio_terisi` decimal(5,1) NOT NULL DEFAULT 0.0,
  `kepadatan` enum('Sepi','Sedang','Padat') NOT NULL DEFAULT 'Sepi',
  `jumlah_kendaraan` int(11) NOT NULL DEFAULT 0 COMMENT '[DEPRECATED] 1=terisi (<ambang), 0=kosong',
  `durasi_terpilih` int(11) NOT NULL,
  `lampu_aktif` enum('merah','kuning','hijau') NOT NULL DEFAULT 'hijau',
  `waktu_catat` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_simpang_waktu` (`simpang_id`, `waktu_catat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `log_lalu_lintas`
  ADD COLUMN IF NOT EXISTS `jarak_cm` decimal(5,1) NOT NULL DEFAULT 30.0 AFTER `simpang_id`,
  ADD COLUMN IF NOT EXISTS `rasio_terisi` decimal(5,1) NOT NULL DEFAULT 0.0 AFTER `jarak_cm`,
  ADD COLUMN IF NOT EXISTS `kepadatan` enum('Sepi','Sedang','Padat') NOT NULL DEFAULT 'Sepi' AFTER `rasio_terisi`,
  ADD COLUMN IF NOT EXISTS `lampu_aktif` enum('merah','kuning','hijau') NOT NULL DEFAULT 'hijau' AFTER `durasi_terpilih`;

-- Data Dummy log_lalu_lintas (Dipertahankan + disesuaikan)
INSERT INTO `log_lalu_lintas` (`simpang_id`, `jarak_cm`, `rasio_terisi`, `kepadatan`, `jumlah_kendaraan`, `durasi_terpilih`, `lampu_aktif`, `waktu_catat`) VALUES
(1, 3.2, 80.0, 'Padat', 1, 15, 'hijau', NOW() - INTERVAL 5 MINUTE),
(2, 18.5, 10.0, 'Sepi', 0, 5, 'hijau', NOW() - INTERVAL 10 MINUTE),
(3, 4.1, 55.0, 'Sedang', 1, 10, 'hijau', NOW() - INTERVAL 12 MINUTE),
(4, 24.0, 0.0, 'Sepi', 0, 5, 'hijau', NOW() - INTERVAL 15 MINUTE);

-- =========================================================================
-- 3. Tabel: config (Parameter Konfigurasi Adaptif & Status Sistem — Bagian 7)
-- =========================================================================
CREATE TABLE IF NOT EXISTS `config` (
  `kunci` varchar(50) NOT NULL,
  `nilai` varchar(100) NOT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`kunci`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `config` (`kunci`, `nilai`, `keterangan`) VALUES
('mode', 'AUTO', 'Mode kerja sistem: AUTO atau MANUAL'),
('prioritas', '0', 'Jalur prioritas saat mode MANUAL (0 = tidak ada, 1..4 = ID jalur)'),
('ambang_cm', '5', 'Batas jarak sensor HC-SR04 dianggap terisi objek (< 5 cm)'),
('hijau_sepi', '5', 'Durasi lampu hijau saat kepadatan Sepi (<30%) dalam detik'),
('hijau_sedang', '10', 'Durasi lampu hijau saat kepadatan Sedang (30%-70%) dalam detik'),
('hijau_padat', '15', 'Durasi lampu hijau saat kepadatan Padat (>70%) dalam detik'),
('hijau_min', '5', 'Batas minimum durasi lampu hijau (detik)'),
('hijau_maks', '20', 'Batas maksimum durasi lampu hijau (detik)'),
('kuning', '2', 'Durasi transisi lampu kuning (detik)'),
('semua_merah', '1', 'Durasi pengaman semua merah saat pergantian jalur (detik)'),
('esp32_status', 'offline', 'Status koneksi perangkat ESP32 (online/offline) via MQTT Last Will'),
('esp32_last_seen', '0', 'Unix timestamp terakhir kali data sensor/state diterima dari ESP32'),
('active_lane', '1', 'ID jalur yang sedang mendapat giliran siklus saat ini'),
('phase_state', 'HIJAU', 'Fase transisi siklus saat ini (HIJAU / KUNING / SEMUA_MERAH)')
ON DUPLICATE KEY UPDATE
  `keterangan` = VALUES(`keterangan`);

-- =========================================================================
-- 4. Tabel: sensor_log (Riwayat Pembacaan Jarak Sensor Mentah untuk Evaluasi)
-- =========================================================================
CREATE TABLE IF NOT EXISTS `sensor_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `simpang_id` int(11) NOT NULL,
  `jarak_cm` decimal(5,1) NOT NULL,
  `terisi` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 jika jarak_cm < ambang_cm, 0 jika tidak',
  `waktu_catat` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sensor_waktu` (`simpang_id`, `waktu_catat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================================================
-- 5. Tabel: event_log (Log Kejadian Sistem: Online/Offline, Mode, Transisi)
-- =========================================================================
CREATE TABLE IF NOT EXISTS `event_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `jenis` varchar(50) NOT NULL COMMENT 'MQTT_STATUS, MODE_CHANGE, PHASE_CHANGE, SAFETY_GUARD',
  `pesan` varchar(255) NOT NULL,
  `waktu_catat` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_waktu` (`waktu_catat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
