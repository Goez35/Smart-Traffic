<?php
require_once __DIR__ . '/koneksi.php';

// 1. Ambil konfigurasi sistem & status koneksi ESP32 dari tabel `config`
$config_map = [
    'mode'            => 'AUTO',
    'prioritas'       => '0',
    'ambang_cm'       => '5',
    'hijau_sepi'      => '5',
    'hijau_sedang'    => '10',
    'hijau_padat'     => '15',
    'esp32_status'    => 'offline',
    'esp32_last_seen' => '0',
    'active_lane'     => '1',
    'phase_state'     => 'HIJAU',
];
$cfg_result = $conn->query("SELECT `kunci`, `nilai` FROM `config`");
if ($cfg_result) {
    while ($c = $cfg_result->fetch_assoc()) {
        $config_map[$c['kunci']] = $c['nilai'];
    }
}

$last_seen_ts = intval($config_map['esp32_last_seen'] ?? 0);
$esp32_online = (strtolower($config_map['esp32_status'] ?? 'offline') === 'online')
    && ($last_seen_ts > 0 && (time() - $last_seen_ts) <= 15);

// 2. Ambil data status 4 jalur persimpangan
$result = $conn->query("SELECT * FROM `status_persimpangan` ORDER BY `id` ASC LIMIT 4");
$persimpangan_list = [];
$total_terisi = 0;
$total_manual = 0;
$total_auto = 0;

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $persimpangan_list[] = $row;
        if (floatval($row['jarak_cm'] ?? 30.0) < floatval($config_map['ambang_cm'] ?? 5.0)) {
            $total_terisi++;
        }
        if ($row['mode'] === 'manual') {
            $total_manual++;
        } else {
            $total_auto++;
        }
    }
}

// 3. Ambil data riwayat siklus lampu terbaru
$log_result = $conn->query("SELECT l.*, s.nama_simpang
    FROM `log_lalu_lintas` l
    LEFT JOIN `status_persimpangan` s ON l.simpang_id = s.id
    ORDER BY l.id DESC LIMIT 8");
$logs = [];
if ($log_result) {
    while ($log = $log_result->fetch_assoc()) {
        $logs[] = $log;
    }
}

// 4. Ambil log kejadian sistem (MQTT Online/Offline, Transisi Mode, dsb.)
$event_result = $conn->query("SELECT * FROM `event_log` ORDER BY `id` DESC LIMIT 6");
$event_logs = [];
if ($event_result) {
    while ($ev = $event_result->fetch_assoc()) {
        $event_logs[] = $ev;
    }
}

// Render Modular Components
include 'includes/header.php';
include 'includes/hero.php';
include 'includes/simpang_grid.php';
include 'includes/log_table.php';
include 'includes/footer.php';
?>
