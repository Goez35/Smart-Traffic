<?php
/**
 * [DEPRECATED] Endpoint HTTP Lama untuk ESP32
 * ----------------------------------------------------------------------------
 * CATATAN ARSITEKTUR:
 * Komunikasi utama ESP32 <-> Server kini telah dipindahkan ke protokol MQTT
 * melalui Broker EMQX dan `worker_mqtt.php` (lihat konteks proyek Bagian 2 & 5).
 * File ini dipertahankan sementara sebagai cadangan kompatibilitas, dengan
 * perbaikan keamanan menggunakan Prepared Statements dan penjaga Invarian 8.1.
 */

require_once __DIR__ . '/koneksi.php';

header('Content-Type: application/json; charset=utf-8');

// Validasi API Key dasar bila header X-API-Key atau parameter api_key dikirim
$apiKeyConfig = defined('ESP32_API_KEY') ? ESP32_API_KEY : '';
if (isset($_SERVER['HTTP_X_API_KEY']) || isset($_REQUEST['api_key'])) {
    $diterima = $_SERVER['HTTP_X_API_KEY'] ?? ($_REQUEST['api_key'] ?? '');
    if ($apiKeyConfig !== '' && !hash_equals($apiKeyConfig, (string)$diterima)) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "API key tidak valid"]);
        exit;
    }
}

// 1. Jika ESP32 mengirim data sensor/log (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $simpang_id       = isset($_POST['simpang_id']) ? intval($_POST['simpang_id']) : 0;
    $jarak_cm         = isset($_POST['jarak_cm']) ? floatval($_POST['jarak_cm']) : 30.0;
    $jumlah_kendaraan = isset($_POST['jumlah_kendaraan']) ? intval($_POST['jumlah_kendaraan']) : (($jarak_cm < 5.0) ? 1 : 0);
    $durasi_terpilih  = isset($_POST['durasi_terpilih']) ? intval($_POST['durasi_terpilih']) : 5;
    $lampu_aktif      = isset($_POST['lampu_aktif']) ? strtolower(trim($_POST['lampu_aktif'])) : 'merah';

    if ($simpang_id < 1 || $simpang_id > 4 || !in_array($lampu_aktif, ['merah', 'kuning', 'hijau'], true)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Parameter simpang_id (1..4) atau lampu_aktif tidak valid"]);
        exit;
    }

    // Invarian Keselamatan 8.1: Jika jalur ini dilaporkan hijau/kuning, pastikan jalur lain merah
    if ($lampu_aktif !== 'merah') {
        $stmtReset = $conn->prepare("UPDATE `status_persimpangan` SET `lampu_aktif` = 'merah' WHERE `id` <> ?");
        if ($stmtReset) {
            $stmtReset->bind_param("i", $simpang_id);
            $stmtReset->execute();
            $stmtReset->close();
        }
    }

    // Update state real-time menggunakan Prepared Statement
    $stmtUpdate = $conn->prepare("UPDATE `status_persimpangan`
        SET `jarak_cm` = ?, `jumlah_kendaraan` = ?, `durasi_hijau` = ?, `lampu_aktif` = ?
        WHERE `id` = ?");
    if ($stmtUpdate) {
        $stmtUpdate->bind_param("diisi", $jarak_cm, $jumlah_kendaraan, $durasi_terpilih, $lampu_aktif, $simpang_id);
        $stmtUpdate->execute();
        $stmtUpdate->close();
    }

    // Masukkan ke tabel history log menggunakan Prepared Statement
    $stmtLog = $conn->prepare("INSERT INTO `log_lalu_lintas`
        (`simpang_id`, `jarak_cm`, `jumlah_kendaraan`, `durasi_terpilih`, `lampu_aktif`)
        VALUES (?, ?, ?, ?, ?)");
    if ($stmtLog) {
        $stmtLog->bind_param("idiis", $simpang_id, $jarak_cm, $jumlah_kendaraan, $durasi_terpilih, $lampu_aktif);
        $stmtLog->execute();
        $stmtLog->close();
    }

    echo json_encode([
        "status"     => "success",
        "deprecated" => true,
        "message"    => "Data berhasil disimpan (Endpoint HTTP ini berstatus deprecated; gunakan MQTT)"
    ]);
    exit;
}

// 2. Jika ESP32 meminta data status real-time untuk cek mode (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['simpang_id'])) {
    $simpang_id = intval($_GET['simpang_id']);
    $stmtGet = $conn->prepare("SELECT * FROM `status_persimpangan` WHERE `id` = ?");
    $data = null;
    if ($stmtGet) {
        $stmtGet->bind_param("i", $simpang_id);
        $stmtGet->execute();
        $res = $stmtGet->get_result();
        $data = $res ? $res->fetch_assoc() : null;
        $stmtGet->close();
    }

    echo json_encode($data ?: ["status" => "error", "message" => "Jalur tidak ditemukan"]);
    exit;
}

http_response_code(400);
echo json_encode(["status" => "error", "message" => "Permintaan tidak valid"]);
?>
