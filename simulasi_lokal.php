<?php
/**
 * ============================================================================
 * SIMULATOR LOKAL 4 JALUR — SMART ADAPTIVE TRAFFIC LIGHT (TANPA HARDWARE ESP32)
 * ============================================================================
 * Digunakan untuk menguji dan mendemonstrasikan Dashboard Web secara langsung
 * sebelum perangkat fisik ESP32 atau Broker EMQX Cloud dinyalakan.
 *
 * Cara pakai:
 *   Klik dua kali `jalankan_simulasi.bat` atau jalankan di terminal:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\Smart-Traffic\Smart-Traffic\simulasi_lokal.php
 *
 * Fitur yang disimulasikan secara real-time tiap 1 detik:
 * - Perubahan jarak sensor HC-SR04 pada 4 jalur & perhitungan rasio terisi 5 detik.
 * - Klasifikasi kepadatan otomatis: Sepi (5s), Sedang (10s), Padat (15s).
 * - Siklus keselamatan penuh: HIJAU -> KUNING (2 dtk) -> SEMUA MERAH (1 dtk) -> Jalur berikutnya HIJAU.
 * - Respons langsung terhadap klik "Manual - Prioritaskan Jalur Ini Hijau" dari Dashboard Web.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Script simulasi_lokal.php hanya boleh dijalankan melalui CLI.\n");
}

require_once __DIR__ . '/koneksi.php';

echo "=================================================================\n";
echo " MODE SIMULASI LOKAL — SMART ADAPTIVE TRAFFIC LIGHT (4 JALUR)\n";
echo "=================================================================\n";
echo "Buka http://localhost/Smart-Traffic/ di browser Anda.\n";
echo "Anda dapat mencoba mengubah Mode Manual/Otomatis langsung dari Dashboard.\n";
echo "Tekan Ctrl+C untuk menghentikan simulasi.\n\n";

$jalurAktif        = 1;
$jalurBerikutnya   = 2;
$faseSekarang      = 'HIJAU';
$durasiHijauTarget = 10;
$waktuMulaiFase    = microtime(true);

// Profil jarak simulasi bergelombang agar terlihat variasi Sepi, Sedang, dan Padat
$tick = 0;

while (true) {
    $sekarang = microtime(true);
    $tick++;

    // 1. Baca mode & prioritas terbaru dari tabel `config` (diubah oleh Dashboard)
    $mode      = 'AUTO';
    $prioritas = 0;
    $ambangCm  = 5.0;
    $resCfg = $conn->query("SELECT `kunci`, `nilai` FROM `config` WHERE `kunci` IN ('mode', 'prioritas', 'ambang_cm')");
    if ($resCfg) {
        while ($c = $resCfg->fetch_assoc()) {
            if ($c['kunci'] === 'mode') $mode = strtoupper($c['nilai']);
            if ($c['kunci'] === 'prioritas') $prioritas = intval($c['nilai']);
            if ($c['kunci'] === 'ambang_cm') $ambangCm = floatval($c['nilai']);
        }
    }

    // 2. Bangkitkan simulasi jarak sensor & rasio 5 detik untuk 4 jalur (terisi bila < 5 cm)
    $dataSim = [
        1 => ['jarak' => ($tick % 20 < 14) ? 3.2 : 22.0,  'rasio' => ($tick % 20 < 14) ? 80.0 : 20.0],
        2 => ['jarak' => ($tick % 16 < 8)  ? 4.1 : 25.0,  'rasio' => ($tick % 16 < 8)  ? 50.0 : 15.0],
        3 => ['jarak' => ($tick % 24 < 6)  ? 3.8 : 28.5,  'rasio' => ($tick % 24 < 6)  ? 35.0 : 0.0],
        4 => ['jarak' => ($tick % 30 < 22) ? 2.9 : 26.0,  'rasio' => ($tick % 30 < 22) ? 85.0 : 10.0],
    ];

    foreach ($dataSim as $id => &$d) {
        if ($d['rasio'] > 70.0) {
            $d['kepadatan'] = 'Padat';
            $d['durasi']    = 15;
        } elseif ($d['rasio'] >= 30.0) {
            $d['kepadatan'] = 'Sedang';
            $d['durasi']    = 10;
        } else {
            $d['kepadatan'] = 'Sepi';
            $d['durasi']    = 5;
        }
        $d['terisi'] = ($d['jarak'] < $ambangCm) ? 1 : 0;
    }
    unset($d);

    // 3. Evaluasi transisi siklus lampu (Invarian Keselamatan: Hijau -> Kuning 2s -> Semua Merah 1s)
    $durasiFase = $sekarang - $waktuMulaiFase;

    if ($faseSekarang === 'HIJAU') {
        $harusPindah = false;
        if ($mode === 'MANUAL' && $prioritas >= 1 && $prioritas <= 4 && $jalurAktif !== $prioritas) {
            $jalurBerikutnya = $prioritas;
            $harusPindah     = true;
        } elseif ($mode === 'AUTO' && $durasiFase >= $durasiHijauTarget) {
            $jalurBerikutnya = ($jalurAktif % 4) + 1;
            $harusPindah     = true;
        }

        if ($harusPindah) {
            $faseSekarang   = 'KUNING';
            $waktuMulaiFase = $sekarang;
            echo sprintf("[%s] Jalur %d -> KUNING (2 detik)\n", date('H:i:s'), $jalurAktif);
        }
    } elseif ($faseSekarang === 'KUNING') {
        if ($durasiFase >= 2.0) {
            $faseSekarang   = 'SEMUA_MERAH';
            $waktuMulaiFase = $sekarang;
            echo sprintf("[%s] SEMUA MERAH (1 detik)\n", date('H:i:s'));
        }
    } elseif ($faseSekarang === 'SEMUA_MERAH') {
        if ($durasiFase >= 1.0) {
            $jalurAktif        = ($mode === 'MANUAL' && $prioritas >= 1 && $prioritas <= 4) ? $prioritas : $jalurBerikutnya;
            $durasiHijauTarget = $dataSim[$jalurAktif]['durasi'];
            $faseSekarang      = 'HIJAU';
            $waktuMulaiFase    = $sekarang;

            // Catat siklus baru ke log_lalu_lintas
            $stmtLog = $conn->prepare("INSERT INTO `log_lalu_lintas`
                (`simpang_id`, `jarak_cm`, `rasio_terisi`, `kepadatan`, `jumlah_kendaraan`, `durasi_terpilih`, `lampu_aktif`)
                VALUES (?, ?, ?, ?, ?, ?, 'hijau')");
            if ($stmtLog) {
                $stmtLog->bind_param(
                    "iddsii",
                    $jalurAktif,
                    $dataSim[$jalurAktif]['jarak'],
                    $dataSim[$jalurAktif]['rasio'],
                    $dataSim[$jalurAktif]['kepadatan'],
                    $dataSim[$jalurAktif]['terisi'],
                    $durasiHijauTarget
                );
                $stmtLog->execute();
                $stmtLog->close();
            }

            echo sprintf(
                "[%s] Jalur %d -> HIJAU (%d detik | %s - %.0f%%)\n",
                date('H:i:s'),
                $jalurAktif,
                $durasiHijauTarget,
                $dataSim[$jalurAktif]['kepadatan'],
                $dataSim[$jalurAktif]['rasio']
            );
        }
    }

    // 4. Perbarui tabel status_persimpangan & config
    $stmtUp = $conn->prepare("UPDATE `status_persimpangan`
        SET `mode` = ?, `lampu_aktif` = ?, `durasi_hijau` = ?, `jarak_cm` = ?, `rasio_terisi` = ?, `kepadatan` = ?, `jumlah_kendaraan` = ?
        WHERE `id` = ?");
    for ($id = 1; $id <= 4; $id++) {
        $modeBaris = ($mode === 'MANUAL' && $prioritas === $id) ? 'manual' : 'auto';
        $warna     = 'merah';
        if ($id === $jalurAktif) {
            if ($faseSekarang === 'HIJAU') $warna = 'hijau';
            elseif ($faseSekarang === 'KUNING') $warna = 'kuning';
        }
        $durTampil = ($id === $jalurAktif) ? $durasiHijauTarget : $dataSim[$id]['durasi'];
        $stmtUp->bind_param(
            "ssiddsii",
            $modeBaris,
            $warna,
            $durTampil,
            $dataSim[$id]['jarak'],
            $dataSim[$id]['rasio'],
            $dataSim[$id]['kepadatan'],
            $dataSim[$id]['terisi'],
            $id
        );
        $stmtUp->execute();
    }
    $stmtUp->close();

    $nowTs = (string)time();
    $conn->query("INSERT INTO `config` (`kunci`, `nilai`) VALUES ('esp32_status', 'online'), ('esp32_last_seen', '{$nowTs}')
        ON DUPLICATE KEY UPDATE `nilai` = VALUES(`nilai`)");

    sleep(1);
}
?>
