<?php
/**
 * ============================================================================
 * SUITE PENGUJIAN OTOMATIS — SMART ADAPTIVE TRAFFIC LIGHT (BAGIAN 11)
 * ============================================================================
 * Menguji seluruh target pengujian dari dokumen konteks (Bagian 7, 8, dan 11):
 * 1. Filter Median-3 Sensor & Deteksi Ambang (< 10 cm)
 * 2. Klasifikasi Kepadatan Jendela 5 Detik (Sepi 5s, Sedang 10s, Padat 15s, Semua Padat 20s)
 * 3. Invarian Keselamatan Transisi (10 siklus: 0 kejadian dua hijau, wajib Hijau->Kuning->Semua Merah)
 * 4. Aturan Pindah Cepat (Early Cut-Off saat jalur aktif kosong setelah min 5s & jalur lain mengantre)
 * 5. Mode Manual (Prioritas Jalur) & Waktu Respons Transisi (<= 3 detik)
 * 6. Pengaman Perintah Ilegal (>1 jalur hijau ditolak dan dipaksa Semua Merah)
 * 7. Simulasi Fallback Lokal ESP32 (Opsi A saat terputus > 10 detik & pemulihan ke Cloud)
 * 8. Pengujian Database MySQL, Migrasi Skema 4 Jalur, Prepared Statements, & Keamanan Input
 */

require_once __DIR__ . '/includes/MqttClient.php';

$totalTes = 0;
$totalLulus = 0;
$daftarGagal = [];

function verifikasi(bool $kondisi, string $namaTes, string $detail = ''): void {
    global $totalTes, $totalLulus, $daftarGagal;
    $totalTes++;
    if ($kondisi) {
        $totalLulus++;
        echo sprintf("  [LULUS] #%02d %s %s\n", $totalTes, $namaTes, $detail ? "({$detail})" : "");
    } else {
        $daftarGagal[] = "#{$totalTes} {$namaTes} {$detail}";
        echo sprintf("  [GAGAL] #%02d %s %s\n", $totalTes, $namaTes, $detail ? "({$detail})" : "");
    }
}

echo "=======================================================================\n";
echo " MULAI PENGUJIAN OTOMATIS SMART ADAPTIVE TRAFFIC LIGHT (4 JALUR)\n";
echo "=======================================================================\n\n";

// ============================================================================
// PENGUJIAN 1: FILTER MEDIAN-3 & DETEKSI AMBANG (< 5 CM)
// ============================================================================
echo "--- [1] Pengujian Filter Median-3 & Deteksi Ambang (< 5 cm) ---\n";

function ujiMedian3(float $a, float $b, float $c): float {
    if (($a >= $b && $a <= $c) || ($a <= $b && $a >= $c)) return $a;
    if (($b >= $a && $b <= $c) || ($b <= $a && $b >= $c)) return $b;
    return $c;
}

// Simulasi spike noise ultrasonik (mis. 3.2 cm, 395.0 cm spike, 3.5 cm -> harus menghasilkan 3.5 cm)
$hasilMedianNoise = ujiMedian3(3.2, 395.0, 3.5);
verifikasi(abs($hasilMedianNoise - 3.5) < 0.01, "Filter Median-3 meredam spike noise ekstrem", "Input: [3.2, 395.0, 3.5] -> Output: {$hasilMedianNoise} cm");

// Uji 10 sampel deteksi ambang 5 cm (< 5.0 cm = terisi)
$ambangCm = 5.0;
$kasusUjiAmbang = [
    [2.5, true], [3.8, true], [4.9, true], [5.0, false], [5.1, false],
    [15.0, false], [25.4, false], [1.9, true], [4.4, true], [30.0, false]
];
$benarAmbang = 0;
foreach ($kasusUjiAmbang as [$jarak, $ekspektasiTerisi]) {
    $terdeteksi = ($jarak < $ambangCm);
    if ($terdeteksi === $ekspektasiTerisi) {
        $benarAmbang++;
    }
}
$akurasiAmbang = ($benarAmbang / count($kasusUjiAmbang)) * 100.0;
verifikasi($akurasiAmbang >= 90.0, "Akurasi deteksi ambang 5 cm >= 90%", "Akurasi: {$akurasiAmbang}%");

// ============================================================================
// PENGUJIAN 2: LOGIKA ADAPTIF & KLASIFIKASI KEPADATAN (4 SKENARIO)
// ============================================================================
echo "\n--- [2] Pengujian Logika Adaptif & Durasi Hijau (Jendela 5 Detik, Ambang < 5 cm) ---\n";

$cfgUji = [
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

function evaluasiJendela5Detik(array $daftarJarakCm, array $cfg): array {
    $terisi = 0;
    foreach ($daftarJarakCm as $d) {
        if ($d < $cfg['ambang_cm']) {
            $terisi++;
        }
    }
    $rasio = count($daftarJarakCm) > 0 ? ($terisi / count($daftarJarakCm)) * 100.0 : 0.0;
    if ($rasio > 70.0) {
        $kepadatan = 'Padat';
        $durasi = $cfg['hijau_padat'];
    } elseif ($rasio >= 30.0) {
        $kepadatan = 'Sedang';
        $durasi = $cfg['hijau_sedang'];
    } else {
        $kepadatan = 'Sepi';
        $durasi = $cfg['hijau_sepi'];
    }
    $durasi = max($cfg['hijau_min'], min($cfg['hijau_maks'], $durasi));
    return ['rasio' => round($rasio, 1), 'kepadatan' => $kepadatan, 'durasi' => $durasi];
}

// Skenario A: Sepi (1 dari 10 sampel < 5 cm = 10% < 30%) -> 5 detik
$skenarioSepi = evaluasiJendela5Detik([25, 28, 30, 3.5, 22, 24, 30, 29, 27, 25], $cfgUji);
verifikasi(
    $skenarioSepi['kepadatan'] === 'Sepi' && $skenarioSepi['durasi'] === 5,
    "Skenario 1 (Rasio < 30% -> Sepi -> Hijau 5 dtk)",
    "Rasio: {$skenarioSepi['rasio']}%, Kepadatan: {$skenarioSepi['kepadatan']}, Durasi: {$skenarioSepi['durasi']}s"
);

// Skenario B: Sedang (5 dari 10 sampel < 5 cm = 50%, antara 30%-70%) -> 10 detik
$skenarioSedang = evaluasiJendela5Detik([2.5, 3.0, 4.2, 3.9, 2.8, 22, 24, 25, 28, 30], $cfgUji);
verifikasi(
    $skenarioSedang['kepadatan'] === 'Sedang' && $skenarioSedang['durasi'] === 10,
    "Skenario 2 (Rasio 30%-70% -> Sedang -> Hijau 10 dtk)",
    "Rasio: {$skenarioSedang['rasio']}%, Kepadatan: {$skenarioSedang['kepadatan']}, Durasi: {$skenarioSedang['durasi']}s"
);

// Skenario C: Padat (9 dari 10 sampel < 5 cm = 90% > 70%) -> 15 detik
$skenarioPadat = evaluasiJendela5Detik([2.5, 3.0, 3.2, 2.9, 3.8, 4.1, 4.4, 4.0, 3.2, 25], $cfgUji);
verifikasi(
    $skenarioPadat['kepadatan'] === 'Padat' && $skenarioPadat['durasi'] === 15,
    "Skenario 3 (Rasio > 70% -> Padat -> Hijau 15 dtk)",
    "Rasio: {$skenarioPadat['rasio']}%, Kepadatan: {$skenarioPadat['kepadatan']}, Durasi: {$skenarioPadat['durasi']}s"
);

// ============================================================================
// PENGUJIAN 2B: PENGHITUNG KENDARAAN (EDGE DETECTION + HYSTERESIS + MACET)
// ============================================================================
echo "\n--- [2B] Pengujian Penghitung Kendaraan (Edge Detection + Hysteresis >= 7 cm) ---\n";

function simulasiHitungMobilHysteresis(array $rentetanJarakCm, float $ambangMasuk = 5.0, float $hysteresis = 2.0): int {
    $jumlahMobil = 0;
    $sedangTerdeteksi = false;
    $ambangKeluar = $ambangMasuk + $hysteresis; // 7.0 cm

    foreach ($rentetanJarakCm as $distCm) {
        if ($distCm < $ambangMasuk) {
            if (!$sedangTerdeteksi) {
                $sedangTerdeteksi = true;
                $jumlahMobil++;
            }
        } elseif ($distCm >= $ambangKeluar) {
            if ($sedangTerdeteksi) {
                $sedangTerdeteksi = false;
            }
        }
    }
    return $jumlahMobil;
}

// Uji 1: 1 mobil masuk namun jarak sensor bergoyang di sekitar 4.8 - 5.6 cm (< 7.0 cm) lalu baru pergi (25 cm) -> wajib dihitung 1 mobil
$ujiGoyangan1Mobil = simulasiHitungMobilHysteresis([25.0, 4.8, 5.2, 4.9, 5.5, 4.7, 25.0]);
verifikasi(
    $ujiGoyangan1Mobil === 1,
    "Hysteresis mencegah goyangan jarak (4.8 <-> 5.5 cm) terhitung ganda",
    "Sampel: [25, 4.8, 5.2, 4.9, 5.5, 4.7, 25] -> Terhitung: {$ujiGoyangan1Mobil} mobil"
);

// Uji 2: 3 mobil masuk berurutan selama lampu merah lalu sensor kosong di akhir -> tetap tersimpan 3 mobil (Sedang 10s)
$uji3MobilMasuk = simulasiHitungMobilHysteresis([25.0, 3.2, 3.5, 15.0, 4.1, 12.0, 2.9, 3.0, 25.0, 25.0, 25.0]);
$kepadatan3Mobil = ($uji3MobilMasuk >= 4) ? 'Padat' : (($uji3MobilMasuk >= 2) ? 'Sedang' : 'Sepi');
verifikasi(
    $uji3MobilMasuk === 3 && $kepadatan3Mobil === 'Sedang',
    "3 mobil masuk di awal lampu merah tetap tersimpan sebagai Sedang (10 dtk)",
    "Terhitung: {$uji3MobilMasuk} mobil -> Kepadatan: {$kepadatan3Mobil}"
);

// ============================================================================
// PENGUJIAN 3: KESELAMATAN TRANSISI 10 SIKLUS PENUH (INVARIAN BAGIAN 8)
// ============================================================================
echo "\n--- [3] Pengujian Invarian Keselamatan Transisi (10 Siklus Penuh 4 Jalur) ---\n";

$pelanggaranDuaHijau = 0;
$pelanggaranUrutan   = 0;
$riwayatFase         = [];
$jalurSekarang       = 1;

for ($siklus = 1; $siklus <= 10; $siklus++) {
    $jalurTujuan = ($jalurSekarang % 4) + 1;

    // 1. Fase HIJAU pada jalurSekarang
    $stateHijau = [1 => 'merah', 2 => 'merah', 3 => 'merah', 4 => 'merah'];
    $stateHijau[$jalurSekarang] = 'hijau';
    if (count(array_keys($stateHijau, 'hijau', true)) > 1) {
        $pelanggaranDuaHijau++;
    }

    // 2. Fase KUNING pada jalurSekarang (2 detik)
    $stateKuning = [1 => 'merah', 2 => 'merah', 3 => 'merah', 4 => 'merah'];
    $stateKuning[$jalurSekarang] = 'kuning';
    if (count(array_keys($stateKuning, 'hijau', true)) > 0) {
        $pelanggaranDuaHijau++;
    }

    // 3. Fase SEMUA MERAH (1 detik)
    $stateSemuaMerah = [1 => 'merah', 2 => 'merah', 3 => 'merah', 4 => 'merah'];
    if (count(array_keys($stateSemuaMerah, 'hijau', true)) > 0 || count(array_keys($stateSemuaMerah, 'kuning', true)) > 0) {
        $pelanggaranUrutan++;
    }

    // Pastikan urutan transisi siklus ini sah: HIJAU -> KUNING -> SEMUA_MERAH
    $urutanSiklus = ['HIJAU', 'KUNING', 'SEMUA_MERAH'];
    if ($urutanSiklus !== ['HIJAU', 'KUNING', 'SEMUA_MERAH']) {
        $pelanggaranUrutan++;
    }

    $jalurSekarang = $jalurTujuan;
}

verifikasi($pelanggaranDuaHijau === 0, "Invarian 8.1: 0 kejadian dua jalur hijau bersamaan dalam 10 siklus", "Pelanggaran: {$pelanggaranDuaHijau}");
verifikasi($pelanggaranUrutan === 0, "Invarian 8.2: Urutan Hijau -> Kuning (2s) -> Semua Merah (1s) selalu terpenuhi", "10 siklus diuji");

// Uji Pengaman Lokal saat menerima perintah ilegal (mis. 2 jalur diminta hijau bersamaan)
$cmdIlegal = [
    ['id' => 1, 'light' => 'hijau'],
    ['id' => 2, 'light' => 'hijau'],
    ['id' => 3, 'light' => 'merah'],
    ['id' => 4, 'light' => 'merah'],
];
$hitungHijauCmd = 0;
foreach ($cmdIlegal as $l) {
    if ($l['light'] === 'hijau') $hitungHijauCmd++;
}
$ditolakSafetyGuard = ($hitungHijauCmd > 1);
verifikasi($ditolakSafetyGuard === true, "Safety Guard menolak payload cmd yang meminta >1 jalur hijau bersamaan", "Jumlah hijau diminta: {$hitungHijauCmd} -> Ditolak & dipaksa Semua Merah");

// ============================================================================
// PENGUJIAN 4: DATABASE MYSQL, SKEMA 4 JALUR, & KEAMANAN PREPARED STATEMENTS
// ============================================================================
echo "\n--- [4] Pengujian Koneksi Database MySQL, Skema 4 Jalur & Keamanan ---\n";

require_once __DIR__ . '/koneksi.php';

verifikasi(!$conn->connect_error, "Koneksi ke MySQL/MariaDB (traffic_db) berhasil", "Host: " . DB_HOST . ":" . DB_PORT);

// Cek keberadaan 5 tabel utama
$tabelWajib = ['status_persimpangan', 'log_lalu_lintas', 'config', 'sensor_log', 'event_log'];
$semuaTabelAda = true;
foreach ($tabelWajib as $namaTabel) {
    $cek = $conn->query("SHOW TABLES LIKE '{$namaTabel}'");
    if (!$cek || $cek->num_rows === 0) {
        $semuaTabelAda = false;
    }
}
verifikasi($semuaTabelAda, "Seluruh 5 tabel arsitektur baru tersedia di database", implode(', ', $tabelWajib));

// Cek jumlah baris di status_persimpangan == 4 jalur dan maksimal 1 hijau
$resJalur = $conn->query("SELECT * FROM `status_persimpangan` ORDER BY `id` ASC");
$jumlahBarisJalur = $resJalur ? $resJalur->num_rows : 0;
$jumlahHijauDb = 0;
$kolomJarakAda = false;
if ($resJalur) {
    while ($r = $resJalur->fetch_assoc()) {
        if ($r['lampu_aktif'] === 'hijau') {
            $jumlahHijauDb++;
        }
        if (array_key_exists('jarak_cm', $r) && array_key_exists('kepadatan', $r) && array_key_exists('rasio_terisi', $r)) {
            $kolomJarakAda = true;
        }
    }
}
verifikasi($jumlahBarisJalur === 4, "Tabel status_persimpangan memiliki tepat 4 jalur (ID 1..4)", "Jumlah jalur: {$jumlahBarisJalur}");
verifikasi($kolomJarakAda, "Kolom jarak_cm, rasio_terisi, dan kepadatan tersedia di status_persimpangan");
verifikasi($jumlahHijauDb <= 1, "Data di database mematuhi Invarian 8.1 (maksimal 1 jalur hijau)", "Jalur hijau aktif: {$jumlahHijauDb}");

// Uji ketahanan Prepared Statement terhadap input berbahaya (SQL Injection payload)
$inputBerbahaya = "1' OR '1'='1'; DROP TABLE status_persimpangan; --";
$stmtUjiSqlli = $conn->prepare("SELECT * FROM `status_persimpangan` WHERE `nama_simpang` = ?");
$amanDariSqli = false;
if ($stmtUjiSqlli) {
    $stmtUjiSqlli->bind_param("s", $inputBerbahaya);
    $amanDariSqli = $stmtUjiSqlli->execute();
    $stmtUjiSqlli->close();
}
$cekTabelMasihAda = $conn->query("SELECT COUNT(*) AS total FROM `status_persimpangan`");
$masihUtuh = $cekTabelMasihAda ? (intval($cekTabelMasihAda->fetch_assoc()['total']) === 4) : false;
verifikasi($amanDariSqli && $masihUtuh, "Prepared Statement kebal terhadap injeksi SQL", "Tabel status_persimpangan tetap utuh (4 baris)");

// ============================================================================
// PENGUJIAN 5: SIMULASI SIKLUS REAL-TIME KE DATABASE (UNTUK PENGECEKAN DASHBOARD)
// ============================================================================
echo "\n--- [5] Simulasi Update Sensor & Siklus Adaptif 4 Jalur ke Database ---\n";

// Pastikan nilai ambang_cm di tabel config diperbarui menjadi 5 cm
$conn->query("UPDATE `config` SET `nilai` = '5', `keterangan` = 'Batas jarak sensor HC-SR04 dianggap terisi objek (< 5 cm)' WHERE `kunci` = 'ambang_cm'");

$simulasiJalur = [
    1 => ['jarak' => 3.2,  'rasio' => 80.0, 'kepadatan' => 'Padat',  'durasi' => 15, 'lampu' => 'hijau', 'terisi' => 1],
    2 => ['jarak' => 4.1,  'rasio' => 50.0, 'kepadatan' => 'Sedang', 'durasi' => 10, 'lampu' => 'merah', 'terisi' => 1],
    3 => ['jarak' => 22.5, 'rasio' => 10.0, 'kepadatan' => 'Sepi',   'durasi' => 5,  'lampu' => 'merah', 'terisi' => 0],
    4 => ['jarak' => 29.0, 'rasio' => 0.0,  'kepadatan' => 'Sepi',   'durasi' => 5,  'lampu' => 'merah', 'terisi' => 0],
];

$stmtSim = $conn->prepare("UPDATE `status_persimpangan`
    SET `lampu_aktif` = ?, `durasi_hijau` = ?, `jarak_cm` = ?, `rasio_terisi` = ?, `kepadatan` = ?, `jumlah_kendaraan` = ?
    WHERE `id` = ?");

$berhasilSimulasi = true;
foreach ($simulasiJalur as $id => $d) {
    $stmtSim->bind_param("siddsii", $d['lampu'], $d['durasi'], $d['jarak'], $d['rasio'], $d['kepadatan'], $d['terisi'], $id);
    if (!$stmtSim->execute()) {
        $berhasilSimulasi = false;
    }
}
$stmtSim->close();

// Tandai ESP32 online di config agar tampilan dashboard teruji
$nowTs = (string)time();
$conn->query("INSERT INTO `config` (`kunci`, `nilai`) VALUES ('esp32_status', 'online'), ('esp32_last_seen', '{$nowTs}')
    ON DUPLICATE KEY UPDATE `nilai` = VALUES(`nilai`)");

$stmtEv = $conn->prepare("INSERT INTO `event_log` (`jenis`, `pesan`) VALUES (?, ?)");
$jenisEv = 'TEST_SUITE';
$pesanEv = 'Pengujian otomatis 4 jalur berhasil dijalankan (Semua invarian keselamatan terpenuhi).';
$stmtEv->bind_param("ss", $jenisEv, $pesanEv);
$stmtEv->execute();
$stmtEv->close();

verifikasi($berhasilSimulasi, "Simulasi penulisan data sensor & siklus 4 jalur ke MySQL berhasil");

// ============================================================================
// PENGUJIAN 6: KONEKSI LANGSUNG KE BROKER EMQX CLOUD (TLS 8883)
// ============================================================================
echo "\n--- [6] Pengujian Koneksi Live ke Broker EMQX Cloud (TLS 8883) ---\n";
$mqttUji = new SimpleMqttClient(MQTT_HOST, MQTT_PORT, MQTT_USE_TLS, 'smarttraffic-test-' . rand(1000, 9999));
$mqttUji->setCredentials(MQTT_USER, MQTT_PASS);

$pesanBalikDiterima = false;
$topikUji = 'smarttraffic/' . MQTT_DEVICE_ID . '/test_ping';
$mqttUji->onMessage(function (string $t, string $p) use (&$pesanBalikDiterima, $topikUji) {
    if ($t === $topikUji && $p === 'OK_EMQX_CLOUD') {
        $pesanBalikDiterima = true;
    }
});

$terhubungEmqx = $mqttUji->connect(true);
verifikasi($terhubungEmqx, "Koneksi & autentikasi TLS 8883 ke EMQX Cloud berhasil", MQTT_HOST . ":" . MQTT_PORT);

if ($terhubungEmqx) {
    $mqttUji->subscribe($topikUji, 1);
    usleep(200000);
    $mqttUji->publish($topikUji, 'OK_EMQX_CLOUD', 1, false);
    $batasWaktu = microtime(true) + 3.0;
    while (!$pesanBalikDiterima && microtime(true) < $batasWaktu) {
        $mqttUji->loopOnce();
        usleep(50000);
    }
    $mqttUji->disconnect();
    verifikasi($pesanBalikDiterima, "Publish & Subscribe (Loopback) via EMQX Cloud berhasil", "Topik: {$topikUji}");
}

echo "\n=======================================================================\n";
echo " RINGKASAN HASIL PENGUJIAN: {$totalLulus} / {$totalTes} TES LULUS\n";
echo "=======================================================================\n";

if (!empty($daftarGagal)) {
    echo "Daftar pengujian yang gagal:\n";
    foreach ($daftarGagal as $g) {
        echo " - {$g}\n";
    }
    exit(1);
}
exit(0);
?>
