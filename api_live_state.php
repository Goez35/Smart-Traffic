<?php
/**
 * Endpoint JSON Real-Time untuk Halaman Visualisasi 4 Jalur (`simulasi.php`)
 * Mengambil status terkini dari tabel `config` dan `status_persimpangan`
 * yang diperbarui secara real-time oleh `worker_mqtt.php` dari sensor ESP32.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/koneksi.php';

// Jika request POST untuk menambah/mengurangi antrean mobil secara real-time dari halaman Simulasi 4 Jalur
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idJalur = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $delta   = isset($_POST['delta']) ? intval($_POST['delta']) : 0;

    if ($idJalur >= 1 && $idJalur <= 4 && $delta !== 0) {
        $kunciAdjust = "adjust_mobil_{$idJalur}";

        // 1. Tambahkan delta ke antrean perintah worker di tabel `config`
        $stmtGet = $conn->prepare("SELECT `nilai` FROM `config` WHERE `kunci` = ?");
        $nilaiSaatIni = 0;
        if ($stmtGet) {
            $stmtGet->bind_param("s", $kunciAdjust);
            $stmtGet->execute();
            $resGet = $stmtGet->get_result();
            if ($rowGet = $resGet->fetch_assoc()) {
                $nilaiSaatIni = intval($rowGet['nilai']);
            }
            $stmtGet->close();
        }

        $nilaiBaru = (string)($nilaiSaatIni + $delta);
        $stmtUpdCfg = $conn->prepare("INSERT INTO `config` (`kunci`, `nilai`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `nilai` = VALUES(`nilai`)");
        if ($stmtUpdCfg) {
            $stmtUpdCfg->bind_param("ss", $kunciAdjust, $nilaiBaru);
            $stmtUpdCfg->execute();
            $stmtUpdCfg->close();
        }

        // 2. Perbarui langsung `status_persimpangan` agar respons visual di web terasa instan (< 50ms)
        $stmtRow = $conn->prepare("SELECT `jumlah_kendaraan` FROM `status_persimpangan` WHERE `id` = ?");
        if ($stmtRow) {
            $stmtRow->bind_param("i", $idJalur);
            $stmtRow->execute();
            $resRow = $stmtRow->get_result();
            if ($r = $resRow->fetch_assoc()) {
                $jmlBaru = max(0, intval($r['jumlah_kendaraan']) + $delta);
                if ($jmlBaru >= 4) {
                    $kepBaru = 'Padat';
                    $durBaru = 15;
                } elseif ($jmlBaru >= 2) {
                    $kepBaru = 'Sedang';
                    $durBaru = 10;
                } else {
                    $kepBaru = 'Sepi';
                    $durBaru = 5;
                }
                $rasioBaru = min(100.0, $jmlBaru * 25.0);
                $stmtSave = $conn->prepare("UPDATE `status_persimpangan` SET `jumlah_kendaraan` = ?, `kepadatan` = ?, `rasio_terisi` = ?, `durasi_hijau` = ? WHERE `id` = ?");
                if ($stmtSave) {
                    $stmtSave->bind_param("isdii", $jmlBaru, $kepBaru, $rasioBaru, $durBaru, $idJalur);
                    $stmtSave->execute();
                    $stmtSave->close();
                }
            }
            $stmtRow->close();
        }
    }
}

$config_map = [
    'mode'            => 'AUTO',
    'prioritas'       => '0',
    'ambang_cm'       => '5',
    'hijau_sepi'      => '5',
    'hijau_sedang'    => '10',
    'hijau_padat'     => '15',
    'kuning'          => '2',
    'semua_merah'     => '1',
    'esp32_status'    => 'offline',
    'esp32_last_seen' => '0',
    'active_lane'     => '1',
    'phase_state'     => 'HIJAU',
];

$cfg_res = $conn->query("SELECT `kunci`, `nilai` FROM `config`");
if ($cfg_res) {
    while ($c = $cfg_res->fetch_assoc()) {
        $config_map[$c['kunci']] = $c['nilai'];
    }
}

$last_seen_ts = intval($config_map['esp32_last_seen'] ?? 0);
$esp32_online = (strtolower($config_map['esp32_status'] ?? 'offline') === 'online')
    && ($last_seen_ts > 0 && (time() - $last_seen_ts) <= 15);

$lanes = [];
$res = $conn->query("SELECT * FROM `status_persimpangan` ORDER BY `id` ASC LIMIT 4");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $lanes[] = [
            'id'               => intval($row['id']),
            'nama_simpang'     => $row['nama_simpang'],
            'mode'             => $row['mode'],
            'lampu_aktif'      => strtolower($row['lampu_aktif'] ?? 'merah'),
            'durasi_hijau'     => intval($row['durasi_hijau'] ?? 5),
            'jarak_cm'         => round(floatval($row['jarak_cm'] ?? 30.0), 1),
            'rasio_terisi'     => round(floatval($row['rasio_terisi'] ?? 0.0), 1),
            'kepadatan'        => $row['kepadatan'] ?? 'Sepi',
            'jumlah_kendaraan' => intval($row['jumlah_kendaraan'] ?? 0),
            'updated_at'       => $row['updated_at'] ?? '',
        ];
    }
}

echo json_encode([
    'ok'                => true,
    'timestamp'         => time(),
    'esp32_online'      => $esp32_online,
    'mode'              => strtoupper($config_map['mode'] ?? 'AUTO'),
    'prioritas'         => intval($config_map['prioritas'] ?? 0),
    'ambang_cm'         => floatval($config_map['ambang_cm'] ?? 5.0),
    'active_lane'       => intval($config_map['active_lane'] ?? 1),
    'phase_state'       => strtoupper($config_map['phase_state'] ?? 'HIJAU'),
    'phase_target_s'    => floatval($config_map['phase_target_s'] ?? 5.0),
    'phase_remaining_s' => floatval($config_map['phase_remaining_s'] ?? 5.0),
    'lanes'             => $lanes,
]);
?>
