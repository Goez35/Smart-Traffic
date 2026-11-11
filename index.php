<?php
//test
include 'koneksi.php';

// Ambil data status persimpangan
$result = $conn->query("SELECT * FROM status_persimpangan");
$persimpangan_list = [];
$total_kendaraan = 0;
$total_manual = 0;
$total_auto = 0;

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $persimpangan_list[] = $row;
        $total_kendaraan += intval($row['jumlah_kendaraan']);
        if ($row['mode'] === 'manual') {
            $total_manual++;
        } else {
            $total_auto++;
        }
    }
}

// Ambil data riwayat log terbaru
$log_result = $conn->query("SELECT * FROM log_lalu_lintas ORDER BY id DESC LIMIT 8");
$logs = [];
if ($log_result) {
    while ($log = $log_result->fetch_assoc()) {
        $logs[] = $log;
    }
}

// Render Modular Components
include 'includes/header.php';
include 'includes/hero.php';
include 'includes/simpang_grid.php';
include 'includes/log_table.php';
include 'includes/footer.php';
?>
