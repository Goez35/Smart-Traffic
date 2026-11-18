<?php
/**
 * ============================================================================
 * SMART ADAPTIVE TRAFFIC LIGHT — WORKER MQTT & LOGIKA ADAPTIF CLOUD (CLI)
 * ============================================================================
 * Cara menjalankan di terminal (PowerShell / CMD):
 *   C:\xampp\php\php.exe C:\xampp\htdocs\Smart-Traffic\Smart-Traffic\worker_mqtt.php
 * Atau klik dua kali file `jalankan_worker.bat`.
 *
 * Tugas Worker ini (Bagian 3, 5, 7, dan 8):
 * 1. Terhubung ke Broker EMQX Cloud (TLS 8883) dan subscribe ke:
 *    - `smarttraffic/{device_id}/sensor`
 *    - `smarttraffic/{device_id}/state`
 *    - `smarttraffic/{device_id}/status`
 * 2. Menghitung rasio terisi (`dist_cm < ambang_cm`) dalam jendela 5 detik
 *    dan menentukan klasifikasi kepadatan tiap jalur:
 *    - < 30%       : Sepi   (Hijau 5 detik)
 *    - 30% - 70%   : Sedang (Hijau 10 detik)
 *    - > 70%       : Padat  (Hijau 15 detik)
 * 3. Menjalankan penjadwalan adaptif & mode manual dengan mematuhi Invarian Keselamatan:
 *    - Tidak pernah mengizinkan 2 jalur hijau bersamaan.
 *    - Urutan transisi wajib: HIJAU -> KUNING (2s) -> SEMUA MERAH (1s) -> Jalur berikutnya HIJAU.
 *    - Durasi hijau dipagari antara `hijau_min` (5s) dan `hijau_maks` (20s).
 *    - Mode Manual tetap menunggu fase Kuning -> Semua Merah selesai sebelum pindah.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Script worker_mqtt.php hanya boleh dijalankan melalui CLI (Command Line).\n");
}

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/includes/MqttClient.php';

// ============================================================================
// 1. KONFIGURASI AWAL & STRUKTUR MEMORI WORKER
// ============================================================================
$deviceId    = defined('MQTT_DEVICE_ID') ? MQTT_DEVICE_ID : 'tl-01';
$topicSensor = "smarttraffic/{$deviceId}/sensor";
$topicCmd    = "smarttraffic/{$deviceId}/cmd";
$topicState  = "smarttraffic/{$deviceId}/state";
$topicStatus = "smarttraffic/{$deviceId}/status";

const JUMLAH_JALUR = 4;
const JENDELA_DETIK = 5.0; // Jendela waktu evaluasi kepadatan (5 detik terakhir)

// Buffer sampel sensor 5 detik terakhir per jalur: [ id => [ ['ts' => float, 'dist_cm' => float], ... ] ]
$sampelJendela = [
    1 => [],
    2 => [],
    3 => [],
    4 => [],
];

// Status hasil olahan terbaru per jalur
$dataJalur = [];
for ($i = 1; $i <= JUMLAH_JALUR; $i++) {
    $dataJalur[$i] = [
        'jarak_cm'        => 30.0,
        'rasio'           => 0.0,
        'kepadatan'       => 'Sepi',
        'terisi_sekarang' => 0,
        'lampu_aktual'    => 'merah',
    ];
}

// Parameter konfigurasi dari tabel `config`
$cfg = [
    'mode'         => 'AUTO',
    'prioritas'    => 0,
    'ambang_cm'    => 5.0,
    'hijau_sepi'   => 5,
    'hijau_sedang' => 10,
    'hijau_padat'  => 15,
    'hijau_min'    => 5,
    'hijau_maks'   => 20,
    'kuning'       => 2,
    'semua_merah'  => 1,
];

// State mesin siklus lampu di Cloud Worker
$faseSekarang        = 'HIJAU'; // 'HIJAU' | 'KUNING' | 'SEMUA_MERAH'
$jalurAktif          = 1;       // Jalur yang sedang mendapat giliran (1..4)
$jalurBerikutnya     = 2;       // Jalur tujuan setelah transisi selesai
$durasiHijauTarget   = 5;       // Durasi hijau terpilih untuk siklus saat ini (detik)
$waktuMulaiFase      = microtime(true);
$seqCmd              = 1;

// Pelacak waktu tunggu per jalur agar tidak ada jalur yang menunggu terus (anti-starvation)
$giliranTerakhir = [
    1 => microtime(true),
    2 => 0.0,
    3 => 0.0,
    4 => 0.0,
];

$waktuMuatConfigTerakhir = 0.0;
$waktuSimpanSensorLog    = 0.0;
$waktuUpdateDbTerakhir   = 0.0;
$esp32Status             = 'offline';

// ============================================================================
// 2. FUNGSI HELPER DATABASE (SEMUA MEMAKAI PREPARED STATEMENTS)
// ============================================================================
function catatEventLog(mysqli $conn, string $jenis, string $pesan): void {
    $stmt = $conn->prepare("INSERT INTO `event_log` (`jenis`, `pesan`) VALUES (?, ?)");
    if ($stmt) {
        $stmt->bind_param("ss", $jenis, $pesan);
        $stmt->execute();
        $stmt->close();
    }
    echo sprintf("[%s] [%s] %s\n", date('H:i:s'), $jenis, $pesan);
}

function simpanNilaiConfig(mysqli $conn, string $kunci, string $nilai): void {
    $stmt = $conn->prepare("INSERT INTO `config` (`kunci`, `nilai`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `nilai` = VALUES(`nilai`)");
    if ($stmt) {
        $stmt->bind_param("ss", $kunci, $nilai);
        $stmt->execute();
        $stmt->close();
    }
}

function muatConfigDariDb(mysqli $conn, array &$cfg): void {
    $res = $conn->query("SELECT `kunci`, `nilai` FROM `config`");
    if (!$res) {
        return;
    }
    while ($row = $res->fetch_assoc()) {
        $k = $row['kunci'];
        $v = $row['nilai'];
        switch ($k) {
            case 'mode':
                $cfg['mode'] = (strtoupper(trim($v)) === 'MANUAL') ? 'MANUAL' : 'AUTO';
                break;
            case 'prioritas':
                $cfg['prioritas'] = max(0, min(JUMLAH_JALUR, intval($v)));
                break;
            case 'ambang_cm':
                $cfg['ambang_cm'] = max(1.0, floatval($v));
                break;
            case 'hijau_sepi':
                $cfg['hijau_sepi'] = max(1, intval($v));
                break;
            case 'hijau_sedang':
                $cfg['hijau_sedang'] = max(1, intval($v));
                break;
            case 'hijau_padat':
                $cfg['hijau_padat'] = max(1, intval($v));
                break;
            case 'hijau_min':
                $cfg['hijau_min'] = max(1, intval($v));
                break;
            case 'hijau_maks':
                $cfg['hijau_maks'] = max($cfg['hijau_min'], intval($v));
                break;
            case 'kuning':
                $cfg['kuning'] = max(1, intval($v));
                break;
            case 'semua_merah':
                $cfg['semua_merah'] = max(1, intval($v));
                break;
        }
    }
}

function sinkronkanStatusPersimpanganKeDb(
    mysqli $conn,
    array $dataJalur,
    array $cfg,
    int $jalurAktif,
    string $faseSekarang,
    int $durasiHijauTarget
): void {
    $stmt = $conn->prepare("UPDATE `status_persimpangan`
        SET `mode` = ?,
            `lampu_aktif` = ?,
            `durasi_hijau` = ?,
            `jarak_cm` = ?,
            `rasio_terisi` = ?,
            `kepadatan` = ?,
            `jumlah_kendaraan` = ?
        WHERE `id` = ?");
    if (!$stmt) {
        return;
    }

    for ($id = 1; $id <= JUMLAH_JALUR; $id++) {
        $modeBaris = ($cfg['mode'] === 'MANUAL' && $cfg['prioritas'] === $id) ? 'manual' : 'auto';

        $warna = 'merah';
        if ($id === $jalurAktif) {
            if ($faseSekarang === 'HIJAU') {
                $warna = 'hijau';
            } elseif ($faseSekarang === 'KUNING') {
                $warna = 'kuning';
            }
        }

        $durasiTampil = ($id === $jalurAktif) ? $durasiHijauTarget : hitungDurasiHijauAdaptif($dataJalur[$id]['kepadatan'], $cfg);
        $jarak        = round($dataJalur[$id]['jarak_cm'], 1);
        $rasio        = round($dataJalur[$id]['rasio'], 1);
        $kepadatan    = $dataJalur[$id]['kepadatan'];
        $estKendaraan = (int)round($rasio / 10.0); // Estimasi kompatibilitas kolom lama

        $stmt->bind_param("ssiddsii", $modeBaris, $warna, $durasiTampil, $jarak, $rasio, $kepadatan, $estKendaraan, $id);
        $stmt->execute();
    }
    $stmt->close();

    simpanNilaiConfig($conn, 'active_lane', (string)$jalurAktif);
    simpanNilaiConfig($conn, 'phase_state', $faseSekarang);
}

function catatSiklusKeLogLaluLintas(
    mysqli $conn,
    int $idJalur,
    float $jarakCm,
    float $rasio,
    string $kepadatan,
    int $durasiTerpilih
): void {
    $estKendaraan = (int)round($rasio / 10.0);
    $warna        = 'hijau';
    $stmt = $conn->prepare("INSERT INTO `log_lalu_lintas`
        (`simpang_id`, `jarak_cm`, `rasio_terisi`, `kepadatan`, `jumlah_kendaraan`, `durasi_terpilih`, `lampu_aktif`)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("iddsiis", $idJalur, $jarakCm, $rasio, $kepadatan, $estKendaraan, $durasiTerpilih, $warna);
        $stmt->execute();
        $stmt->close();
    }
}

// ============================================================================
// 3. FUNGSI LOGIKA ADAPTIF & JENDELA 5 DETIK (BAGIAN 7 & 8)
// ============================================================================
function hitungKepadatanJalur(int $id, array &$sampelJendela, array &$dataJalur, array $cfg): void {
    $sekarang   = microtime(true);
    $batasBawah = $sekarang - JENDELA_DETIK;

    // Bersihkan sampel yang lebih tua dari 5 detik
    $sampelBaru   = [];
    $jumlahTerisi = 0;
    foreach ($sampelJendela[$id] as $s) {
        if ($s['ts'] >= $batasBawah) {
            $sampelBaru[] = $s;
            if ($s['dist_cm'] < $cfg['ambang_cm']) {
                $jumlahTerisi++;
            }
        }
    }
    $sampelJendela[$id] = $sampelBaru;

    $totalSampel = count($sampelBaru);
    if ($totalSampel > 0) {
        $rasio = ($jumlahTerisi / $totalSampel) * 100.0;
    } else {
        $rasio = ($dataJalur[$id]['jarak_cm'] < $cfg['ambang_cm']) ? 100.0 : 0.0;
    }

    if ($rasio > 70.0) {
        $kepadatan = 'Padat';
    } elseif ($rasio >= 30.0) {
        $kepadatan = 'Sedang';
    } else {
        $kepadatan = 'Sepi';
    }

    $dataJalur[$id]['rasio']           = round($rasio, 1);
    $dataJalur[$id]['kepadatan']       = $kepadatan;
    $dataJalur[$id]['terisi_sekarang'] = ($dataJalur[$id]['jarak_cm'] < $cfg['ambang_cm']) ? 1 : 0;
}

function hitungDurasiHijauAdaptif(string $kepadatan, array $cfg): int {
    switch ($kepadatan) {
        case 'Padat':
            $durasi = $cfg['hijau_padat'];
            break;
        case 'Sedang':
            $durasi = $cfg['hijau_sedang'];
            break;
        default:
            $durasi = $cfg['hijau_sepi'];
            break;
    }
    // Pagar durasi hijau (Invarian 8.3: hijau_min <= durasi <= hijau_maks)
    return max($cfg['hijau_min'], min($cfg['hijau_maks'], $durasi));
}

/**
 * Memilih jalur berikutnya yang akan diberi lampu hijau:
 * - Bila mode MANUAL dan prioritas (1..4) disetel: pilih jalur prioritas.
 * - Bila mode AUTO:
 *   - Prioritaskan jalur dengan kepadatan tertinggi & waktu tunggu terlama (anti-starvation).
 *   - Jika semua jalur sama-sama Sepi (rasio 0%) atau sama-sama Padat, maju berurutan Round-Robin (1 -> 2 -> 3 -> 4 -> 1).
 */
function pilihJalurBerikutnya(int $jalurSaatIni, array $dataJalur, array $cfg, array $giliranTerakhir): int {
    if ($cfg['mode'] === 'MANUAL' && $cfg['prioritas'] >= 1 && $cfg['prioritas'] <= JUMLAH_JALUR) {
        return $cfg['prioritas'];
    }

    // Kandidat urutan Round-Robin mulai dari jalur setelah jalurSaatIni
    $kandidatUrut = [];
    for ($step = 1; $step <= JUMLAH_JALUR; $step++) {
        $id = (($jalurSaatIni - 1 + $step) % JUMLAH_JALUR) + 1;
        if ($id !== $jalurSaatIni) {
            $kandidatUrut[] = $id;
        }
    }

    // Cek apakah semua jalur memiliki kategori kepadatan yang identik
    $kategoriPertama = $dataJalur[1]['kepadatan'];
    $semuaSama       = true;
    for ($id = 2; $id <= JUMLAH_JALUR; $id++) {
        if ($dataJalur[$id]['kepadatan'] !== $kategoriPertama) {
            $semuaSama = false;
            break;
        }
    }

    // Jika semua jalur sama-sama Sepi atau sama-sama Padat, gunakan Round-Robin murni agar adil
    if ($semuaSama && ($kategoriPertama === 'Sepi' || $kategoriPertama === 'Padat')) {
        return $kandidatUrut[0];
    }

    // Jika ada variasi kepadatan, hitung skor gabungan (rasio kepadatan + bonus waktu tunggu anti-starvation)
    $sekarang    = microtime(true);
    $idTerbaik   = $kandidatUrut[0];
    $skorTerbaik = -1.0;

    foreach ($kandidatUrut as $id) {
        $bobotKepadatan = 0.0;
        if ($dataJalur[$id]['kepadatan'] === 'Padat') {
            $bobotKepadatan = 200.0;
        } elseif ($dataJalur[$id]['kepadatan'] === 'Sedang') {
            $bobotKepadatan = 100.0;
        }

        $waktuTunggu = max(0.0, $sekarang - ($giliranTerakhir[$id] ?? 0.0));
        // Bonus waktu tunggu: setiap detik menunggu menambah 3.5 poin sehingga jalur sepi tetap mendapat giliran
        $skor = $bobotKepadatan + $dataJalur[$id]['rasio'] + ($waktuTunggu * 3.5);

        if ($skor > $skorTerbaik) {
            $skorTerbaik = $skor;
            $idTerbaik   = $id;
        }
    }

    return $idTerbaik;
}

/**
 * Mengirim perintah lampu (`cmd`) ke ESP32 melalui MQTT (QoS 1, Retain = true).
 * Menjamin Invarian Keselamatan 8.1: maksimal hanya 1 jalur yang bukan merah.
 */
function publishPerintahLampu(
    SimpleMqttClient $mqtt,
    string $topicCmd,
    int &$seqCmd,
    int $jalurAktif,
    string $faseSekarang,
    int $durasiHijauTarget
): void {
    $lanesPayload = [];
    $jumlahHijau  = 0;

    for ($id = 1; $id <= JUMLAH_JALUR; $id++) {
        $warna = 'merah';
        if ($id === $jalurAktif) {
            if ($faseSekarang === 'HIJAU') {
                $warna = 'hijau';
                $jumlahHijau++;
            } elseif ($faseSekarang === 'KUNING') {
                $warna = 'kuning';
            }
        }
        $lanesPayload[] = [
            'id'    => $id,
            'light' => $warna,
        ];
    }

    // Invarian Keselamatan 8.1: Jika karena alasan apa pun terdeteksi >1 hijau, paksa semua merah
    if ($jumlahHijau > 1) {
        foreach ($lanesPayload as &$l) {
            $l['light'] = 'merah';
        }
        unset($l);
    }

    $payload = json_encode([
        'seq'     => $seqCmd++,
        'lanes'   => $lanesPayload,
        'green_s' => $durasiHijauTarget,
    ]);

    if ($mqtt->isConnected() && $payload !== false) {
        $mqtt->publish($topicCmd, $payload, 1, true);
    }
}

// ============================================================================
// 4. INISIALISASI KLIEN MQTT & CALLBACK PESAN MASUK
// ============================================================================
$mqttHost = defined('MQTT_HOST') ? MQTT_HOST : 'localhost';
$mqttPort = defined('MQTT_PORT') ? (int)MQTT_PORT : 8883;
$mqttTls  = defined('MQTT_USE_TLS') ? (bool)MQTT_USE_TLS : true;
$mqttUser = defined('MQTT_USER') ? MQTT_USER : '';
$mqttPass = defined('MQTT_PASS') ? MQTT_PASS : '';

$mqtt = new SimpleMqttClient($mqttHost, $mqttPort, $mqttTls, 'smarttraffic-worker-' . getmypid());
$mqtt->setCredentials($mqttUser, $mqttPass);

$mqtt->onMessage(function (string $topic, string $payload) use (
    &$conn,
    $topicSensor,
    $topicState,
    $topicStatus,
    &$sampelJendela,
    &$dataJalur,
    &$cfg,
    &$waktuSimpanSensorLog,
    &$esp32Status
) {
    $sekarang = microtime(true);

    // 1. Pesan Status Online/Offline dari ESP32 (termasuk Last Will)
    if ($topic === $topicStatus) {
        $statusBaru = (strtolower(trim($payload)) === 'online') ? 'online' : 'offline';
        if ($statusBaru !== $esp32Status) {
            $esp32Status = $statusBaru;
            simpanNilaiConfig($conn, 'esp32_status', $esp32Status);
            catatEventLog($conn, 'MQTT_STATUS', "Status perangkat ESP32 berubah menjadi: " . strtoupper($esp32Status));
        }
        if ($statusBaru === 'online') {
            simpanNilaiConfig($conn, 'esp32_last_seen', (string)time());
        }
        return;
    }

    // 2. Pesan State Aktual (ACK) dari ESP32
    if ($topic === $topicState) {
        $json = json_decode($payload, true);
        if (is_array($json) && isset($json['lanes']) && is_array($json['lanes'])) {
            foreach ($json['lanes'] as $lane) {
                $id    = intval($lane['id'] ?? 0);
                $light = strtolower(trim($lane['light'] ?? 'merah'));
                if ($id >= 1 && $id <= JUMLAH_JALUR && in_array($light, ['merah', 'kuning', 'hijau'], true)) {
                    $dataJalur[$id]['lampu_aktual'] = $light;
                }
            }
            simpanNilaiConfig($conn, 'esp32_status', 'online');
            simpanNilaiConfig($conn, 'esp32_last_seen', (string)time());
        }
        return;
    }

    // 3. Pesan Data Sensor Jarak dari ESP32
    if ($topic === $topicSensor) {
        $json = json_decode($payload, true);
        if (!is_array($json) || !isset($json['lanes']) || !is_array($json['lanes'])) {
            return;
        }

        if ($esp32Status !== 'online') {
            $esp32Status = 'online';
            simpanNilaiConfig($conn, 'esp32_status', 'online');
        }
        simpanNilaiConfig($conn, 'esp32_last_seen', (string)time());

        $perluSimpanLogMentah = ($sekarang - $waktuSimpanSensorLog) >= 2.0;
        if ($perluSimpanLogMentah) {
            $waktuSimpanSensorLog = $sekarang;
            $stmtSensor           = $conn->prepare("INSERT INTO `sensor_log` (`simpang_id`, `jarak_cm`, `terisi`) VALUES (?, ?, ?)");
        } else {
            $stmtSensor = null;
        }

        foreach ($json['lanes'] as $lane) {
            $id = intval($lane['id'] ?? 0);
            if ($id < 1 || $id > JUMLAH_JALUR) {
                continue;
            }
            $distCm                    = max(1.0, min(400.0, floatval($lane['dist_cm'] ?? 100.0)));
            $dataJalur[$id]['jarak_cm'] = $distCm;

            $sampelJendela[$id][] = [
                'ts'      => $sekarang,
                'dist_cm' => $distCm,
            ];

            hitungKepadatanJalur($id, $sampelJendela, $dataJalur, $cfg);

            if ($stmtSensor) {
                $terisi = ($distCm < $cfg['ambang_cm']) ? 1 : 0;
                $stmtSensor->bind_param("idi", $id, $distCm, $terisi);
                $stmtSensor->execute();
            }
        }

        if ($stmtSensor) {
            $stmtSensor->close();
        }
    }
});

// ============================================================================
// 5. LOOP UTAMA WORKER (NON-BLOCKING)
// ============================================================================
echo "=================================================================\n";
echo " SMART ADAPTIVE TRAFFIC LIGHT — WORKER MQTT CLOUD (4 JALUR)\n";
echo "=================================================================\n";
echo "Broker EMQX : {$mqttHost}:{$mqttPort} (" . ($mqttTls ? 'TLS' : 'TCP') . ")\n";
echo "Device ID   : {$deviceId}\n";
echo "Tekan Ctrl+C untuk menghentikan worker.\n\n";

muatConfigDariDb($conn, $cfg);
$durasiHijauTarget = hitungDurasiHijauAdaptif($dataJalur[$jalurAktif]['kepadatan'], $cfg);
catatEventLog($conn, 'WORKER_START', "Worker PHP dimulai. Jalur awal: Jalur {$jalurAktif} (HIJAU {$durasiHijauTarget}s).");

$waktuCobaKonekMqtt = 0.0;

while (true) {
    $sekarang = microtime(true);

    // 1. Pastikan koneksi MQTT ke EMQX Cloud aktif
    if (!$mqtt->isConnected()) {
        if (($sekarang - $waktuCobaKonekMqtt) >= 3.0) {
            $waktuCobaKonekMqtt = $sekarang;
            echo sprintf("[%s] [MQTT] Mencoba koneksi ke %s:%d ...\n", date('H:i:s'), $mqttHost, $mqttPort);
            if ($mqtt->connect(true)) {
                echo sprintf("[%s] [MQTT] Berhasil terhubung ke EMQX! Subscribe topik perangkat %s...\n", date('H:i:s'), $deviceId);
                $mqtt->subscribe($topicSensor, 0);
                $mqtt->subscribe($topicState, 1);
                $mqtt->subscribe($topicStatus, 1);

                // Kirim perintah lampu awal
                publishPerintahLampu($mqtt, $topicCmd, $seqCmd, $jalurAktif, $faseSekarang, $durasiHijauTarget);
            }
        }
    } else {
        $mqtt->loopOnce();
    }

    // 2. Muat ulang parameter `config` dari DB tiap 1 detik (agar perubahan mode dari web segera terbaca)
    if (($sekarang - $waktuMuatConfigTerakhir) >= 1.0) {
        $waktuMuatConfigTerakhir = $sekarang;
        $modeLama      = $cfg['mode'];
        $prioritasLama = $cfg['prioritas'];

        muatConfigDariDb($conn, $cfg);

        // Segarkan perhitungan jendela 5 detik untuk semua jalur
        for ($id = 1; $id <= JUMLAH_JALUR; $id++) {
            hitungKepadatanJalur($id, $sampelJendela, $dataJalur, $cfg);
        }

        if ($cfg['mode'] !== $modeLama || $cfg['prioritas'] !== $prioritasLama) {
            catatEventLog(
                $conn,
                'MODE_CHANGE',
                "Mode: {$cfg['mode']}, Prioritas Jalur: {$cfg['prioritas']}"
            );
        }
    }

    // 3. Evaluasi Mesin Siklus & Invarian Keselamatan (Bagian 7 & 8)
    $durasiFaseBerjalan = $sekarang - $waktuMulaiFase;

    if ($faseSekarang === 'HIJAU') {
        $pindahSekarang = false;
        $alasanPindah   = '';

        // A. Mode MANUAL: Jika user memilih prioritas jalur lain, segera jadwalkan transisi aman (Kuning -> Semua Merah)
        if ($cfg['mode'] === 'MANUAL' && $cfg['prioritas'] >= 1 && $cfg['prioritas'] <= JUMLAH_JALUR) {
            if ($jalurAktif !== $cfg['prioritas']) {
                $jalurBerikutnya = $cfg['prioritas'];
                $pindahSekarang  = true;
                $alasanPindah    = "Override Manual ke Jalur {$jalurBerikutnya}";
            }
            // Jika jalur aktif sudah sesuai dengan prioritas manual, pertahankan hijau sampai mode dikembalikan ke AUTO
        } else {
            // B. Mode AUTO:
            // Aturan 1: Durasi hijau normal/maksimum sudah tercapai
            if ($durasiFaseBerjalan >= $durasiHijauTarget) {
                $jalurBerikutnya = pilihJalurBerikutnya($jalurAktif, $dataJalur, $cfg, $giliranTerakhir);
                $pindahSekarang  = true;
                $alasanPindah    = "Durasi hijau {$durasiHijauTarget}s selesai";
            }
            // Aturan 2 (Potong-Cepat): Jalur aktif sudah kosong (Sepi & tidak terisi) setelah melewati durasi minimum (`hijau_min`),
            //          DAN ada jalur lain yang sedang mengantre (terisi / tidak Sepi)
            elseif ($durasiFaseBerjalan >= $cfg['hijau_min'] && $dataJalur[$jalurAktif]['terisi_sekarang'] === 0) {
                $adaJalurLainMengantre = false;
                for ($id = 1; $id <= JUMLAH_JALUR; $id++) {
                    if ($id !== $jalurAktif && ($dataJalur[$id]['terisi_sekarang'] === 1 || $dataJalur[$id]['kepadatan'] !== 'Sepi')) {
                        $adaJalurLainMengantre = true;
                        break;
                    }
                }
                if ($adaJalurLainMengantre) {
                    $jalurBerikutnya = pilihJalurBerikutnya($jalurAktif, $dataJalur, $cfg, $giliranTerakhir);
                    $pindahSekarang  = true;
                    $alasanPindah    = "Jalur {$jalurAktif} kosong setelah min {$cfg['hijau_min']}s & jalur lain mengantre";
                }
            }
        }

        if ($pindahSekarang) {
            $faseSekarang   = 'KUNING';
            $waktuMulaiFase = $sekarang;
            publishPerintahLampu($mqtt, $topicCmd, $seqCmd, $jalurAktif, $faseSekarang, $durasiHijauTarget);
            sinkronkanStatusPersimpanganKeDb($conn, $dataJalur, $cfg, $jalurAktif, $faseSekarang, $durasiHijauTarget);
            echo sprintf(
                "[%s] [TRANSISI] Jalur %d -> KUNING (%ds) | Alasan: %s\n",
                date('H:i:s'),
                $jalurAktif,
                $cfg['kuning'],
                $alasanPindah
            );
        }
    } elseif ($faseSekarang === 'KUNING') {
        if ($durasiFaseBerjalan >= $cfg['kuning']) {
            $faseSekarang   = 'SEMUA_MERAH';
            $waktuMulaiFase = $sekarang;
            publishPerintahLampu($mqtt, $topicCmd, $seqCmd, $jalurAktif, $faseSekarang, $durasiHijauTarget);
            sinkronkanStatusPersimpanganKeDb($conn, $dataJalur, $cfg, $jalurAktif, $faseSekarang, $durasiHijauTarget);
            echo sprintf(
                "[%s] [TRANSISI] SEMUA MERAH (%ds) sebelum pindah ke Jalur %d\n",
                date('H:i:s'),
                $cfg['semua_merah'],
                $jalurBerikutnya
            );
        }
    } elseif ($faseSekarang === 'SEMUA_MERAH') {
        if ($durasiFaseBerjalan >= $cfg['semua_merah']) {
            // Evaluasi ulang jalur berikutnya jika masih mode AUTO agar data sensor terbaru selama transisi ikut diperhitungkan
            if ($cfg['mode'] === 'MANUAL' && $cfg['prioritas'] >= 1 && $cfg['prioritas'] <= JUMLAH_JALUR) {
                $jalurAktif = $cfg['prioritas'];
            } else {
                $jalurAktif = $jalurBerikutnya;
            }

            hitungKepadatanJalur($jalurAktif, $sampelJendela, $dataJalur, $cfg);
            $kepadatanAktif    = $dataJalur[$jalurAktif]['kepadatan'];
            $durasiHijauTarget = hitungDurasiHijauAdaptif($kepadatanAktif, $cfg);

            // Jika semua jalur sama-sama Padat, berikan durasi maksimum (`hijau_maks` = 20 detik) sesuai Skenario 3
            $semuaPadat = true;
            for ($id = 1; $id <= JUMLAH_JALUR; $id++) {
                if ($dataJalur[$id]['kepadatan'] !== 'Padat') {
                    $semuaPadat = false;
                    break;
                }
            }
            if ($semuaPadat && $cfg['mode'] === 'AUTO') {
                $durasiHijauTarget = $cfg['hijau_maks'];
            }

            $faseSekarang                 = 'HIJAU';
            $waktuMulaiFase               = $sekarang;
            $giliranTerakhir[$jalurAktif] = $sekarang;

            publishPerintahLampu($mqtt, $topicCmd, $seqCmd, $jalurAktif, $faseSekarang, $durasiHijauTarget);
            sinkronkanStatusPersimpanganKeDb($conn, $dataJalur, $cfg, $jalurAktif, $faseSekarang, $durasiHijauTarget);
            catatSiklusKeLogLaluLintas(
                $conn,
                $jalurAktif,
                $dataJalur[$jalurAktif]['jarak_cm'],
                $dataJalur[$jalurAktif]['rasio'],
                $kepadatanAktif,
                $durasiHijauTarget
            );

            echo sprintf(
                "[%s] [SIKLUS BARU] Jalur %d -> HIJAU (%ds) | Kepadatan: %s (%.1f%%, Jarak: %.1f cm)\n",
                date('H:i:s'),
                $jalurAktif,
                $durasiHijauTarget,
                $kepadatanAktif,
                $dataJalur[$jalurAktif]['rasio'],
                $dataJalur[$jalurAktif]['jarak_cm']
            );
        }
    }

    // 4. Sinkronkan data jarak & rasio terbaru ke DB tiap 1 detik
    if (($sekarang - $waktuUpdateDbTerakhir) >= 1.0) {
        $waktuUpdateDbTerakhir = $sekarang;
        sinkronkanStatusPersimpanganKeDb($conn, $dataJalur, $cfg, $jalurAktif, $faseSekarang, $durasiHijauTarget);
    }

    usleep(50000); // Tidur 50ms agar hemat CPU
}
?>
