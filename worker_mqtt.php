<?php
/**
 * ============================================================================
 * SMART ADAPTIVE TRAFFIC LIGHT — WORKER MQTT & LOGIKA ADAPTIF CLOUD (CLI)
 * ============================================================================
 * Cara menjalankan di terminal (PowerShell / CMD):
 *   C:\xampp\php\php.exe C:\xampp\htdocs\Smart-Traffic\Smart-Traffic\worker_mqtt.php
 * Atau klik dua kali file `jalankan_worker.bat`.
 *
 * Tugas Worker ini (Bagian 3, 5, 7, dan 8 + Akumulasi Hitungan Mobil):
 * 1. Terhubung ke Broker EMQX Cloud (TLS 8883) dan subscribe ke:
 *    - `smarttraffic/{device_id}/sensor`
 *    - `smarttraffic/{device_id}/state`
 *    - `smarttraffic/{device_id}/status`
 * 2. Menghitung akumulasi kendaraan masuk per jalur dengan Edge Detection + Hysteresis:
 *    - Masuk (< ambang_cm, default 5.0 cm) -> hitung +1 mobil & kunci status deteksi.
 *    - Keluar (>= ambang_cm + 2.0 cm, mis. 7.0 cm) -> buka kunci deteksi (normalisasi
 *      agar goyangan jarak kecil pada 1 mobil tidak terhitung ganda).
 *    - Pengaman antrean menumpuk: jika sensor tertutup terus-menerus >= 3.5 detik,
 *      otomatis diklasifikasikan sebagai Padat.
 *    - Klasifikasi durasi hijau saat giliran tiba:
 *      * 0 - 1 mobil : Sepi   (Hijau 5 detik)
 *      * 2 - 3 mobil : Sedang (Hijau 10 detik)
 *      * >= 4 mobil (atau tertahan macet >= 3.5 dtk) : Padat (Hijau 15 detik)
 *    - Tabungan hitungan mobil TIDAK hangus selama menunggu lampu merah, dan baru
 *      di-reset setelah jalur tersebut selesai mendapat giliran lampu Hijau.
 * 3. Menjalankan penjadwalan adaptif & mode manual dengan mematuhi Invarian Keselamatan:
 *    - Tidak pernah mengizinkan 2 jalur hijau bersamaan.
 *    - Urutan transisi wajib: HIJAU -> KUNING (2s) -> SEMUA MERAH (1s) -> Jalur berikutnya HIJAU.
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
const JENDELA_DETIK = 5.0;            // Jendela waktu 5 detik untuk pemantauan tumpukan macet
const HYSTERESIS_CM = 2.0;            // Margin normalisasi jarak (keluar = ambang_cm + 2.0 cm)
const DURASI_TERTAHAN_PADAT_S = 3.5;  // Sensor tertutup terus >= 3.5 detik dianggap antrean menumpuk (Padat)

// Buffer sampel sensor 5 detik terakhir per jalur
$sampelJendela = [
    1 => [],
    2 => [],
    3 => [],
    4 => [],
];

// Status hasil olahan & penghitung kendaraan per jalur
$dataJalur = [];
for ($i = 1; $i <= JUMLAH_JALUR; $i++) {
    $dataJalur[$i] = [
        'jarak_cm'             => 30.0,
        'rasio'                => 0.0,
        'kepadatan'            => 'Sepi',
        'terisi_sekarang'      => 0,
        'jumlah_mobil'         => 0,     // Tabungan jumlah kendaraan yang terdeteksi masuk
        'sedang_terdeteksi'    => false, // Kunci hysteresis (true saat objek masih di depan sensor)
        'waktu_mulai_tertahan' => 0.0,   // Timestamp mulai tertutup tanpa putus
        'lampu_aktual'         => 'merah',
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
$waktuHeartbeatCmd       = 0.0;
$waktuStateEsp32Terakhir = 0.0;
$menungguAckHijauEsp32   = false;
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

function muatConfigDariDb(mysqli $conn, array &$cfg, array &$dataJalur, array &$sampelJendela): void {
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
            case 'adjust_mobil_1':
            case 'adjust_mobil_2':
            case 'adjust_mobil_3':
            case 'adjust_mobil_4':
                $deltaMobil = intval($v);
                if ($deltaMobil !== 0) {
                    $idJalurAdj = intval(substr($k, -1));
                    if ($idJalurAdj >= 1 && $idJalurAdj <= JUMLAH_JALUR) {
                        $dataJalur[$idJalurAdj]['jumlah_mobil'] = max(0, intval($dataJalur[$idJalurAdj]['jumlah_mobil']) + $deltaMobil);
                        simpanNilaiConfig($conn, $k, '0');
                        hitungKepadatanJalur($idJalurAdj, $sampelJendela, $dataJalur, $cfg);
                        echo sprintf(
                            "[%s] [ANTREAN WEB] Jalur %d: %+d mobil dari tombol web (Total: %d mobil | Kepadatan: %s)\n",
                            date('H:i:s'),
                            $idJalurAdj,
                            $deltaMobil,
                            $dataJalur[$idJalurAdj]['jumlah_mobil'],
                            $dataJalur[$idJalurAdj]['kepadatan']
                        );
                    }
                }
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
    int $durasiHijauTarget,
    float $waktuMulaiFase = 0.0,
    float $waktuStateEsp32Terakhir = 0.0
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

    // Jika ESP32 mengirim laporan state fisik aktual (< 8 detik terakhir), gunakan warna LED fisik maket agar 100% sinkron
    $gunakanStateFisikEsp32 = ($waktuStateEsp32Terakhir > 0.0 && (microtime(true) - $waktuStateEsp32Terakhir) <= 8.0);
    $jalurTampilAktif       = $jalurAktif;
    $faseTampilSekarang     = $faseSekarang;

    if ($gunakanStateFisikEsp32) {
        $faseTampilSekarang = 'SEMUA_MERAH';
        for ($id = 1; $id <= JUMLAH_JALUR; $id++) {
            $wAktual = $dataJalur[$id]['lampu_aktual'] ?? 'merah';
            if ($wAktual === 'hijau') {
                $jalurTampilAktif   = $id;
                $faseTampilSekarang = 'HIJAU';
                break;
            } elseif ($wAktual === 'kuning') {
                $jalurTampilAktif   = $id;
                $faseTampilSekarang = 'KUNING';
                break;
            }
        }
    }

    for ($id = 1; $id <= JUMLAH_JALUR; $id++) {
        $modeBaris = ($cfg['mode'] === 'MANUAL' && $cfg['prioritas'] === $id) ? 'manual' : 'auto';

        if ($gunakanStateFisikEsp32) {
            $warna = $dataJalur[$id]['lampu_aktual'] ?? 'merah';
        } else {
            $warna = 'merah';
            if ($id === $jalurAktif) {
                if ($faseSekarang === 'HIJAU') {
                    $warna = 'hijau';
                } elseif ($faseSekarang === 'KUNING') {
                    $warna = 'kuning';
                }
            }
        }

        $durasiTampil = ($id === $jalurTampilAktif) ? $durasiHijauTarget : hitungDurasiHijauAdaptif($dataJalur[$id]['kepadatan'], $cfg);
        $jarak        = round($dataJalur[$id]['jarak_cm'], 1);
        $rasio        = round($dataJalur[$id]['rasio'], 1);
        $kepadatan    = $dataJalur[$id]['kepadatan'];
        $jumlahMobil  = intval($dataJalur[$id]['jumlah_mobil']);

        $stmt->bind_param("ssiddsii", $modeBaris, $warna, $durasiTampil, $jarak, $rasio, $kepadatan, $jumlahMobil, $id);
        $stmt->execute();
    }
    $stmt->close();

    $targetFaseS = $durasiHijauTarget;
    if ($faseTampilSekarang === 'KUNING') {
        $targetFaseS = $cfg['kuning'];
    } elseif ($faseTampilSekarang === 'SEMUA_MERAH') {
        $targetFaseS = $cfg['semua_merah'];
    }
    $sisaFaseS = ($waktuMulaiFase > 0.0)
        ? round(max(0.0, $targetFaseS - (microtime(true) - $waktuMulaiFase)), 1)
        : (float)$targetFaseS;

    simpanNilaiConfig($conn, 'active_lane', (string)$jalurTampilAktif);
    simpanNilaiConfig($conn, 'phase_state', $faseTampilSekarang);
    simpanNilaiConfig($conn, 'phase_target_s', (string)$targetFaseS);
    simpanNilaiConfig($conn, 'phase_remaining_s', (string)$sisaFaseS);
}

function catatSiklusKeLogLaluLintas(
    mysqli $conn,
    int $idJalur,
    float $jarakCm,
    float $rasio,
    string $kepadatan,
    int $jumlahMobil,
    int $durasiTerpilih
): void {
    $warna = 'hijau';
    $stmt = $conn->prepare("INSERT INTO `log_lalu_lintas`
        (`simpang_id`, `jarak_cm`, `rasio_terisi`, `kepadatan`, `jumlah_kendaraan`, `durasi_terpilih`, `lampu_aktif`)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("iddsiis", $idJalur, $jarakCm, $rasio, $kepadatan, $jumlahMobil, $durasiTerpilih, $warna);
        $stmt->execute();
        $stmt->close();
    }
}

// ============================================================================
// 3. FUNGSI PENGHITUNG MOBIL (EDGE DETECTION + HYSTERESIS) & KLASIFIKASI
// ============================================================================
/**
 * Memproses setiap pembacaan jarak baru dari ESP32 pada suatu jalur:
 * - Saat transisi dari kosong ke `< ambang_cm`:
 *   * Kunci `sedang_terdeteksi = true` dan catat `waktu_mulai_tertahan`.
 *   * Jika jalur tersebut sedang TIDAK HIJAU (sedang menunggu di fase MERAH / KUNING),
 *     tambahkan `jumlah_mobil` (+1) ke dalam tabungan antrean jalur tersebut.
 * - Kunci deteksi baru dibuka kembali jika jarak naik melewati `>= ambang_cm + HYSTERESIS_CM` (mis. >= 7.0 cm),
 *   sehingga fluktuasi jarak kecil pada mobil yang sama tidak terhitung ganda.
 */
function prosesDeteksiMobilBaru(
    int $id,
    float $distCm,
    float $sekarang,
    array &$dataJalur,
    array $cfg,
    int $jalurAktif,
    string $faseSekarang
): void {
    $ambangMasuk  = $cfg['ambang_cm'];
    $ambangKeluar = $cfg['ambang_cm'] + HYSTERESIS_CM;
    $sedangHijau  = ($id === $jalurAktif && $faseSekarang === 'HIJAU');

    if ($distCm < $ambangMasuk) {
        if (!$dataJalur[$id]['sedang_terdeteksi']) {
            $dataJalur[$id]['sedang_terdeteksi']    = true;
            $dataJalur[$id]['waktu_mulai_tertahan'] = $sekarang;

            if (!$sedangHijau) {
                $dataJalur[$id]['jumlah_mobil']++;
                echo sprintf(
                    "[%s] [DETEKSI MOBIL] Jalur %d: +1 kendaraan masuk (Tabungan: %d mobil | Jarak: %.1f cm)\n",
                    date('H:i:s'),
                    $id,
                    $dataJalur[$id]['jumlah_mobil'],
                    $distCm
                );
            }
        }
    } elseif ($distCm >= $ambangKeluar) {
        if ($dataJalur[$id]['sedang_terdeteksi']) {
            $dataJalur[$id]['sedang_terdeteksi']    = false;
            $dataJalur[$id]['waktu_mulai_tertahan'] = 0.0;
        }
    }
}

/**
 * Mengevaluasi tingkat kepadatan jalur berdasarkan:
 * 1. Tabungan `jumlah_mobil` yang terakumulasi selama lampu merah/kuning (tidak hangus setelah 5 detik):
 *    - 0 - 1 mobil : Sepi   (5 dtk)
 *    - 2 - 3 mobil : Sedang (10 dtk)
 *    - >= 4 mobil  : Padat  (15 dtk)
 * 2. Pengaman tumpukan macet: jika objek berhenti tepat di depan sensor (`< ambang_cm`)
 *    terus-menerus selama >= 3.5 detik (antrean mengular sampai depan sensor),
 *    otomatis dinaikkan menjadi `Padat` (dan minimal terhitung 4 mobil setara antrean penuh).
 */
function hitungKepadatanJalur(int $id, array &$sampelJendela, array &$dataJalur, array $cfg): void {
    $sekarang   = microtime(true);
    $batasBawah = $sekarang - JENDELA_DETIK;

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

    $jumlahMobil  = intval($dataJalur[$id]['jumlah_mobil']);
    $lamaTertahan = ($dataJalur[$id]['sedang_terdeteksi'] && $dataJalur[$id]['waktu_mulai_tertahan'] > 0)
        ? ($sekarang - $dataJalur[$id]['waktu_mulai_tertahan'])
        : 0.0;

    // Jika mobil berhenti menumpuk tepat di depan sensor >= 3.5 detik tanpa putus, anggap antrean penuh (Padat)
    $tertahanMacet = ($lamaTertahan >= DURASI_TERTAHAN_PADAT_S);
    if ($tertahanMacet && $jumlahMobil < 4) {
        $jumlahMobil = 4;
        $dataJalur[$id]['jumlah_mobil'] = 4;
        echo sprintf(
            "[%s] [DETEKSI MACET] Jalur %d: Sensor tertutup terus >= %.1fs -> Dikunci Padat (setara 4 mobil)\n",
            date('H:i:s'),
            $id,
            DURASI_TERTAHAN_PADAT_S
        );
    }

    if ($jumlahMobil >= 4 || $tertahanMacet) {
        $kepadatan = 'Padat';
    } elseif ($jumlahMobil >= 2) {
        $kepadatan = 'Sedang';
    } else {
        $kepadatan = 'Sepi';
    }

    // Persentase indikator visual berdasarkan tabungan mobil (25% per mobil, atau 100% jika tertahan macet)
    $rasioTabungan = $tertahanMacet ? 100.0 : min(100.0, $jumlahMobil * 25.0);

    $dataJalur[$id]['rasio']           = round($rasioTabungan, 1);
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
    return max($cfg['hijau_min'], min($cfg['hijau_maks'], $durasi));
}

/**
 * Memilih jalur berikutnya yang akan diberi lampu hijau:
 * - Bila mode MANUAL dan prioritas (1..4) disetel: pilih jalur prioritas.
 * - Bila mode AUTO:
 *   - Prioritaskan jalur dengan kepadatan & tabungan `jumlah_mobil` tertinggi,
 *     dikombinasikan dengan waktu tunggu (anti-starvation).
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

    // Cek apakah semua jalur memiliki jumlah mobil & kepadatan yang identik (mis. semua 0 mobil Sepi)
    $semuaSamaKosong = true;
    for ($id = 1; $id <= JUMLAH_JALUR; $id++) {
        if ($dataJalur[$id]['jumlah_mobil'] > 0 || $dataJalur[$id]['kepadatan'] !== 'Sepi') {
            $semuaSamaKosong = false;
            break;
        }
    }
    if ($semuaSamaKosong) {
        return $kandidatUrut[0];
    }

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

        $bobotMobil  = intval($dataJalur[$id]['jumlah_mobil']) * 35.0;
        $waktuTunggu = max(0.0, $sekarang - ($giliranTerakhir[$id] ?? 0.0));
        $skor        = $bobotKepadatan + $bobotMobil + $dataJalur[$id]['rasio'] + ($waktuTunggu * 3.5);

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
    &$esp32Status,
    &$jalurAktif,
    &$faseSekarang,
    &$durasiHijauTarget,
    &$waktuMulaiFase,
    &$waktuStateEsp32Terakhir,
    &$menungguAckHijauEsp32
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

    // 2. Pesan State Aktual (ACK) dari ESP32 — Langsung sinkronkan ke DB begitu LED fisik di maket berubah!
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
            $waktuStateEsp32Terakhir = $sekarang;
            $esp32Status             = 'online';

            // Jika kita baru meminta jalurAktif menjadi HIJAU dan maket ESP32 baru saja benar-benar menyalakan LED Hijau tersebut,
            // mulai hitung mundur durasi hijau dari detik ini agar maket tidak terpotong waktu transisi!
            if ($menungguAckHijauEsp32 && $faseSekarang === 'HIJAU' && ($dataJalur[$jalurAktif]['lampu_aktual'] ?? '') === 'hijau') {
                $menungguAckHijauEsp32 = false;
                $waktuMulaiFase        = $sekarang;
            }

            simpanNilaiConfig($conn, 'esp32_status', 'online');
            simpanNilaiConfig($conn, 'esp32_last_seen', (string)time());
            sinkronkanStatusPersimpanganKeDb($conn, $dataJalur, $cfg, $jalurAktif, $faseSekarang, $durasiHijauTarget, $waktuMulaiFase, $waktuStateEsp32Terakhir);
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

            // Hitung deteksi mobil masuk dengan Edge Detection + Hysteresis
            prosesDeteksiMobilBaru($id, $distCm, $sekarang, $dataJalur, $cfg, $jalurAktif, $faseSekarang);

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

        // Langsung sinkronkan pembacaan sensor terbaru ke DB begitu paket MQTT tiba
        sinkronkanStatusPersimpanganKeDb($conn, $dataJalur, $cfg, $jalurAktif, $faseSekarang, $durasiHijauTarget, $waktuMulaiFase, $waktuStateEsp32Terakhir);
    }
});

// ============================================================================
// 5. LOOP UTAMA WORKER (NON-BLOCKING)
// ============================================================================
echo "=================================================================\n";
echo " SMART ADAPTIVE TRAFFIC LIGHT — WORKER MQTT CLOUD (4 JALUR)\n";
echo " Mode Deteksi: Hitung Kendaraan (Hysteresis) + Deteksi Macet\n";
echo "=================================================================\n";
echo "Broker EMQX : {$mqttHost}:{$mqttPort} (" . ($mqttTls ? 'TLS' : 'TCP') . ")\n";
echo "Device ID   : {$deviceId}\n";
echo "Tekan Ctrl+C untuk menghentikan worker.\n\n";

muatConfigDariDb($conn, $cfg, $dataJalur, $sampelJendela);
$durasiHijauTarget = hitungDurasiHijauAdaptif($dataJalur[$jalurAktif]['kepadatan'], $cfg);
catatEventLog($conn, 'WORKER_START', "Worker PHP dimulai (Mode Hitung Kendaraan + Hysteresis). Jalur awal: Jalur {$jalurAktif}.");

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

                publishPerintahLampu($mqtt, $topicCmd, $seqCmd, $jalurAktif, $faseSekarang, $durasiHijauTarget);
            }
        }
    } else {
        $mqtt->loopOnce();
    }

    // 2. Muat ulang parameter `config` dari DB tiap 0.25 detik (respons instan untuk tombol Manual)
    if (($sekarang - $waktuMuatConfigTerakhir) >= 0.25) {
        $waktuMuatConfigTerakhir = $sekarang;
        $modeLama      = $cfg['mode'];
        $prioritasLama = $cfg['prioritas'];

        muatConfigDariDb($conn, $cfg, $dataJalur, $sampelJendela);

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

    // 3. Evaluasi Mesin Siklus & Invarian Keselamatan
    $durasiFaseBerjalan = $sekarang - $waktuMulaiFase;

    if ($faseSekarang === 'HIJAU') {
        $pindahSekarang = false;
        $alasanPindah   = '';

        // A. Mode MANUAL
        if ($cfg['mode'] === 'MANUAL' && $cfg['prioritas'] >= 1 && $cfg['prioritas'] <= JUMLAH_JALUR) {
            if ($jalurAktif !== $cfg['prioritas']) {
                $jalurBerikutnya = $cfg['prioritas'];
                $pindahSekarang  = true;
                $alasanPindah    = "Override Manual ke Jalur {$jalurBerikutnya}";
            }
        } else {
            // B. Mode AUTO:
            // Aturan 1: Durasi hijau normal/maksimum sudah tercapai
            if ($durasiFaseBerjalan >= $durasiHijauTarget) {
                $jalurBerikutnya = pilihJalurBerikutnya($jalurAktif, $dataJalur, $cfg, $giliranTerakhir);
                $pindahSekarang  = true;
                $alasanPindah    = "Durasi hijau {$durasiHijauTarget}s selesai";
            }
            // Aturan 2 (Potong-Cepat): Jalur aktif sudah kosong di depan sensor setelah durasi minimum (hijau_min)
            //          DAN ada jalur lain yang sudah memiliki tabungan mobil (>0) atau sedang terisi
            elseif ($durasiFaseBerjalan >= $cfg['hijau_min'] && $dataJalur[$jalurAktif]['terisi_sekarang'] === 0) {
                $adaJalurLainMengantre = false;
                for ($id = 1; $id <= JUMLAH_JALUR; $id++) {
                    if ($id !== $jalurAktif && ($dataJalur[$id]['jumlah_mobil'] > 0 || $dataJalur[$id]['terisi_sekarang'] === 1)) {
                        $adaJalurLainMengantre = true;
                        break;
                    }
                }
                if ($adaJalurLainMengantre) {
                    $jalurBerikutnya = pilihJalurBerikutnya($jalurAktif, $dataJalur, $cfg, $giliranTerakhir);
                    $pindahSekarang  = true;
                    $alasanPindah    = "Jalur {$jalurAktif} kosong setelah min {$cfg['hijau_min']}s & jalur lain memiliki antrean mobil";
                }
            }
        }

        if ($pindahSekarang) {
            // Reset tabungan mobil jalur yang baru saja selesai mendapat lampu Hijau
            // (Jika saat masuk kuning masih ada mobil yang berhenti tepat di depan sensor, sisakan 1)
            if ($dataJalur[$jalurAktif]['sedang_terdeteksi']) {
                $dataJalur[$jalurAktif]['jumlah_mobil']         = 1;
                $dataJalur[$jalurAktif]['waktu_mulai_tertahan'] = $sekarang;
            } else {
                $dataJalur[$jalurAktif]['jumlah_mobil'] = 0;
            }
            hitungKepadatanJalur($jalurAktif, $sampelJendela, $dataJalur, $cfg);

            $faseSekarang   = 'KUNING';
            $waktuMulaiFase = $sekarang;
            publishPerintahLampu($mqtt, $topicCmd, $seqCmd, $jalurAktif, $faseSekarang, $durasiHijauTarget);
            sinkronkanStatusPersimpanganKeDb($conn, $dataJalur, $cfg, $jalurAktif, $faseSekarang, $durasiHijauTarget, $waktuMulaiFase, $waktuStateEsp32Terakhir);
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
            sinkronkanStatusPersimpanganKeDb($conn, $dataJalur, $cfg, $jalurAktif, $faseSekarang, $durasiHijauTarget, $waktuMulaiFase, $waktuStateEsp32Terakhir);
            echo sprintf(
                "[%s] [TRANSISI] SEMUA MERAH (%ds) sebelum pindah ke Jalur %d\n",
                date('H:i:s'),
                $cfg['semua_merah'],
                $jalurBerikutnya
            );
        }
    } elseif ($faseSekarang === 'SEMUA_MERAH') {
        if ($durasiFaseBerjalan >= $cfg['semua_merah']) {
            if ($cfg['mode'] === 'MANUAL' && $cfg['prioritas'] >= 1 && $cfg['prioritas'] <= JUMLAH_JALUR) {
                $jalurAktif = $cfg['prioritas'];
            } else {
                // Evaluasi ulang jalur terbaik tepat sebelum Hijau agar tabungan mobil selama Kuning/Merah ikut terhitung
                $jalurAktif = pilihJalurBerikutnya($jalurAktif, $dataJalur, $cfg, $giliranTerakhir);
            }

            hitungKepadatanJalur($jalurAktif, $sampelJendela, $dataJalur, $cfg);
            $kepadatanAktif    = $dataJalur[$jalurAktif]['kepadatan'];
            $jumlahMobilAktif  = intval($dataJalur[$jalurAktif]['jumlah_mobil']);
            $durasiHijauTarget = hitungDurasiHijauAdaptif($kepadatanAktif, $cfg);

            // Jika semua jalur sama-sama Padat, berikan durasi maksimum
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
            $menungguAckHijauEsp32        = true;
            $giliranTerakhir[$jalurAktif] = $sekarang;

            publishPerintahLampu($mqtt, $topicCmd, $seqCmd, $jalurAktif, $faseSekarang, $durasiHijauTarget);
            sinkronkanStatusPersimpanganKeDb($conn, $dataJalur, $cfg, $jalurAktif, $faseSekarang, $durasiHijauTarget, $waktuMulaiFase, $waktuStateEsp32Terakhir);
            catatSiklusKeLogLaluLintas(
                $conn,
                $jalurAktif,
                $dataJalur[$jalurAktif]['jarak_cm'],
                $dataJalur[$jalurAktif]['rasio'],
                $kepadatanAktif,
                $jumlahMobilAktif,
                $durasiHijauTarget
            );

            echo sprintf(
                "[%s] [SIKLUS BARU] Jalur %d -> HIJAU (%ds) | Tabungan: %d mobil | Kepadatan: %s (Jarak: %.1f cm)\n",
                date('H:i:s'),
                $jalurAktif,
                $durasiHijauTarget,
                $jumlahMobilAktif,
                $kepadatanAktif,
                $dataJalur[$jalurAktif]['jarak_cm']
            );
        }
    }

    // 4. Sinkronkan data terbaru ke DB tiap 0.2 detik (200 ms) agar web selalu real-time dengan maket
    if (($sekarang - $waktuUpdateDbTerakhir) >= 0.2) {
        $waktuUpdateDbTerakhir = $sekarang;
        sinkronkanStatusPersimpanganKeDb($conn, $dataJalur, $cfg, $jalurAktif, $faseSekarang, $durasiHijauTarget, $waktuMulaiFase, $waktuStateEsp32Terakhir);
    }

    // 5. Kirim heartbeat `cmd` ke ESP32 tiap 4 detik agar fase Hijau 15s/20s atau Mode Manual tidak memicu Fallback 10s di ESP32
    if (($sekarang - $waktuHeartbeatCmd) >= 4.0) {
        $waktuHeartbeatCmd = $sekarang;
        publishPerintahLampu($mqtt, $topicCmd, $seqCmd, $jalurAktif, $faseSekarang, $durasiHijauTarget);
    }

    usleep(20000);
}
?>
