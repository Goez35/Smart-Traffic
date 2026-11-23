<?php
/**
 * Handler Kontrol Mode & Prioritas Jalur dari Dashboard
 * ----------------------------------------------------------------------------
 * Mematuhi Invarian Keselamatan (Bagian 3, 7, 8.1, 8.5, dan 9.10):
 * - Dashboard TIDAK mengubah warna lampu secara paksa/langsung per simpang.
 * - Dashboard hanya menetapkan Mode Sistem (`AUTO` / `MANUAL`) dan Jalur Prioritas (`1..4`)
 *   ke tabel `config` menggunakan Prepared Statements.
 * - Worker MQTT (`worker_mqtt.php`) yang membaca konfigurasi ini dan mengeksekusi
 *   transisi aman: Hijau -> Kuning (2 dtk) -> Semua Merah (1 dtk) -> Jalur Prioritas Hijau.
 */

require_once __DIR__ . '/koneksi.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $mode = isset($_POST['mode']) ? strtolower(trim($_POST['mode'])) : 'auto';

    if (!in_array($mode, ['auto', 'manual'], true)) {
        $mode = 'auto';
    }

    if ($mode === 'manual' && $id >= 1 && $id <= 4) {
        // 1. Setel konfigurasi global ke MANUAL dengan prioritas pada jalur $id
        $modeCfg      = 'MANUAL';
        $prioritasCfg = (string)$id;

        $stmtCfg = $conn->prepare("INSERT INTO `config` (`kunci`, `nilai`) VALUES ('mode', ?), ('prioritas', ?)
            ON DUPLICATE KEY UPDATE `nilai` = VALUES(`nilai`)");
        if ($stmtCfg) {
            $stmtCfg->bind_param("ss", $modeCfg, $prioritasCfg);
            $stmtCfg->execute();
            $stmtCfg->close();
        }

        // 2. Tandai hanya jalur prioritas ini yang berstatus 'manual', jalur lain 'auto'
        $conn->query("UPDATE `status_persimpangan` SET `mode` = 'auto'");
        $stmtSetManual = $conn->prepare("UPDATE `status_persimpangan` SET `mode` = 'manual' WHERE `id` = ?");
        if ($stmtSetManual) {
            $stmtSetManual->bind_param("i", $id);
            $stmtSetManual->execute();
            $stmtSetManual->close();
        }

        // 3. Catat ke event_log
        $jenis = 'MANUAL_OVERRIDE';
        $pesan = "Dashboard mengaktifkan Prioritas Manual untuk Jalur {$id} (transisi aman via Worker).";
        $stmtLog = $conn->prepare("INSERT INTO `event_log` (`jenis`, `pesan`) VALUES (?, ?)");
        if ($stmtLog) {
            $stmtLog->bind_param("ss", $jenis, $pesan);
            $stmtLog->execute();
            $stmtLog->close();
        }
    } else {
        // Kembali ke Mode Otomatis (Adaptif Sensor) untuk seluruh jalur
        $modeCfg      = 'AUTO';
        $prioritasCfg = '0';

        $stmtCfg = $conn->prepare("INSERT INTO `config` (`kunci`, `nilai`) VALUES ('mode', ?), ('prioritas', ?)
            ON DUPLICATE KEY UPDATE `nilai` = VALUES(`nilai`)");
        if ($stmtCfg) {
            $stmtCfg->bind_param("ss", $modeCfg, $prioritasCfg);
            $stmtCfg->execute();
            $stmtCfg->close();
        }

        $conn->query("UPDATE `status_persimpangan` SET `mode` = 'auto'");

        $jenis = 'AUTO_MODE';
        $pesan = "Dashboard mengembalikan sistem ke Mode Otomatis (Adaptif Sensor 4 Jalur).";
        $stmtLog = $conn->prepare("INSERT INTO `event_log` (`jenis`, `pesan`) VALUES (?, ?)");
        if ($stmtLog) {
            $stmtLog->bind_param("ss", $jenis, $pesan);
            $stmtLog->execute();
            $stmtLog->close();
        }
    }

    // Jika dipanggil secara asynchronous (fetch/AJAX dari simulasi.php), balas dengan JSON ringan tanpa redirect HTML penuh
    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'        => true,
            'mode'      => $modeCfg,
            'prioritas' => intval($prioritasCfg),
        ]);
        exit;
    }

    header("Location: index.php#control-section");
    exit;
}

header("Location: index.php");
exit;
?>
