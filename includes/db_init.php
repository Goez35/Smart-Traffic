<?php
/**
 * Inisialisasi & Migrasi Skema Database Otomatis
 * Memastikan tabel `config`, `sensor_log`, `event_log`, kolom jarak/kepadatan,
 * serta 4 baris jalur persimpangan sudah tersedia tanpa merusak data lama.
 */

function pastikanSkemaDatabase(mysqli $conn): void {
    // 1. Pastikan kolom jarak_cm, rasio_terisi, kepadatan ada di status_persimpangan
    $conn->query("ALTER TABLE `status_persimpangan`
        ADD COLUMN IF NOT EXISTS `jarak_cm` decimal(5,1) NOT NULL DEFAULT 30.0 AFTER `durasi_hijau`,
        ADD COLUMN IF NOT EXISTS `rasio_terisi` decimal(5,1) NOT NULL DEFAULT 0.0 AFTER `jarak_cm`,
        ADD COLUMN IF NOT EXISTS `kepadatan` enum('Sepi','Sedang','Padat') NOT NULL DEFAULT 'Sepi' AFTER `rasio_terisi`");

    // 2. Pastikan 4 jalur tersedia di status_persimpangan
    $jalur_default = [
        [1, 'Jalur 1 (A) - Utara (Sudirman)', 'auto', 'hijau', 10, 3.8, 50.0, 'Sedang', 1],
        [2, 'Jalur 2 (B) - Timur (Thamrin)', 'auto', 'merah', 5, 25.0, 0.0, 'Sepi', 0],
        [3, 'Jalur 3 (C) - Selatan (Gatot Subroto)', 'auto', 'merah', 5, 28.0, 0.0, 'Sepi', 0],
        [4, 'Jalur 4 (D) - Barat (Diponegoro)', 'auto', 'merah', 5, 30.0, 0.0, 'Sepi', 0],
    ];
    $stmt_jalur = $conn->prepare("INSERT IGNORE INTO `status_persimpangan`
        (`id`, `nama_simpang`, `mode`, `lampu_aktif`, `durasi_hijau`, `jarak_cm`, `rasio_terisi`, `kepadatan`, `jumlah_kendaraan`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt_jalur) {
        foreach ($jalur_default as $j) {
            $stmt_jalur->bind_param("isssiddsi", $j[0], $j[1], $j[2], $j[3], $j[4], $j[5], $j[6], $j[7], $j[8]);
            $stmt_jalur->execute();
        }
        $stmt_jalur->close();
    }

    // 3. Pastikan kolom baru ada di log_lalu_lintas
    $conn->query("ALTER TABLE `log_lalu_lintas`
        ADD COLUMN IF NOT EXISTS `jarak_cm` decimal(5,1) NOT NULL DEFAULT 30.0 AFTER `simpang_id`,
        ADD COLUMN IF NOT EXISTS `rasio_terisi` decimal(5,1) NOT NULL DEFAULT 0.0 AFTER `jarak_cm`,
        ADD COLUMN IF NOT EXISTS `kepadatan` enum('Sepi','Sedang','Padat') NOT NULL DEFAULT 'Sepi' AFTER `rasio_terisi`,
        ADD COLUMN IF NOT EXISTS `lampu_aktif` enum('merah','kuning','hijau') NOT NULL DEFAULT 'hijau' AFTER `durasi_terpilih`");

    // 4. Pastikan tabel config tersedia
    $conn->query("CREATE TABLE IF NOT EXISTS `config` (
        `kunci` varchar(50) NOT NULL,
        `nilai` varchar(100) NOT NULL,
        `keterangan` varchar(255) DEFAULT NULL,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`kunci`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $config_defaults = [
        ['mode', 'AUTO', 'Mode kerja sistem: AUTO atau MANUAL'],
        ['prioritas', '0', 'Jalur prioritas saat mode MANUAL (0 = tidak ada, 1..4 = ID jalur)'],
        ['ambang_cm', '5', 'Batas jarak sensor HC-SR04 dianggap terisi objek (< 5 cm)'],
        ['hijau_sepi', '5', 'Durasi lampu hijau saat kepadatan Sepi (<30%) dalam detik'],
        ['hijau_sedang', '10', 'Durasi lampu hijau saat kepadatan Sedang (30%-70%) dalam detik'],
        ['hijau_padat', '15', 'Durasi lampu hijau saat kepadatan Padat (>70%) dalam detik'],
        ['hijau_min', '5', 'Batas minimum durasi lampu hijau (detik)'],
        ['hijau_maks', '20', 'Batas maksimum durasi lampu hijau (detik)'],
        ['kuning', '2', 'Durasi transisi lampu kuning (detik)'],
        ['semua_merah', '1', 'Durasi pengaman semua merah saat pergantian jalur (detik)'],
        ['esp32_status', 'offline', 'Status koneksi perangkat ESP32 (online/offline) via MQTT Last Will'],
        ['esp32_last_seen', '0', 'Unix timestamp terakhir kali data sensor/state diterima dari ESP32'],
        ['active_lane', '1', 'ID jalur yang sedang mendapat giliran siklus saat ini'],
        ['phase_state', 'HIJAU', 'Fase transisi siklus saat ini (HIJAU / KUNING / SEMUA_MERAH)'],
    ];
    $stmt_cfg = $conn->prepare("INSERT IGNORE INTO `config` (`kunci`, `nilai`, `keterangan`) VALUES (?, ?, ?)");
    if ($stmt_cfg) {
        foreach ($config_defaults as $c) {
            $stmt_cfg->bind_param("sss", $c[0], $c[1], $c[2]);
            $stmt_cfg->execute();
        }
        $stmt_cfg->close();
    }

    // 5. Pastikan tabel sensor_log tersedia
    $conn->query("CREATE TABLE IF NOT EXISTS `sensor_log` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `simpang_id` int(11) NOT NULL,
        `jarak_cm` decimal(5,1) NOT NULL,
        `terisi` tinyint(1) NOT NULL DEFAULT 0,
        `waktu_catat` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_sensor_waktu` (`simpang_id`, `waktu_catat`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 6. Pastikan tabel event_log tersedia
    $conn->query("CREATE TABLE IF NOT EXISTS `event_log` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `jenis` varchar(50) NOT NULL,
        `pesan` varchar(255) NOT NULL,
        `waktu_catat` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_waktu` (`waktu_catat`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
?>
