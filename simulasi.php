<?php
/**
 * Halaman Simulasi Lampu Lalu Lintas Perempatan 4 Jalur (Browser-Only)
 * ----------------------------------------------------------------------------
 * CATATAN PENTING:
 * - Simulasi ini berjalan 100% murni di browser menggunakan JavaScript (`assets/js/simulasi.js`).
 * - TIDAK terhubung ke broker MQTT, TIDAK mengubah isi database MySQL, dan
 *   TIDAK mengirim perintah apa pun ke perangkat keras ESP32 asli.
 */
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simulasi Perempatan 4 Jalur - SmartTraffic</title>
    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Style Utama Dashboard + Style Simulasi -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/simulasi.css">
</head>
<body>

    <!-- Header Navigation Bar -->
    <header class="nav-header">
        <div class="nav-left">
            <a href="index.php" class="brand-logo">
                <span class="dot"></span>
                <span>SmartTraffic</span>
            </a>
        </div>

        <div class="nav-right">
            <a href="index.php#control-section" class="nav-link-item">Monitoring 4 Jalur</a>
            <a href="index.php#log-section" class="nav-link-item">Riwayat &amp; Log</a>
            <a href="simulasi.php" class="nav-link-item" style="color: #141414; font-weight: 800;">Simulasi 4 Jalur</a>

            <div class="refresh-pill" title="Mode simulasi berjalan murni di browser tanpa menyentuh ESP32/Database">
                <span class="indicator-ring" style="background: #10b981;"></span>
                <span>Mode: Simulasi Browser</span>
            </div>

            <a href="index.php" class="btn-header-action">
                <i class="bi bi-arrow-left"></i> Kembali ke Dashboard
            </a>
        </div>
    </header>

    <div class="main-wrapper">

        <!-- Judul Halaman Simulasi -->
        <div class="sim-page-header">
            <div>
                <div class="sim-tag">
                    <i class="bi bi-stoplights"></i>
                    <span>Simulator Perempatan 4 Jalur &bull; Logika Adaptif HC-SR04</span>
                </div>
                <h1 class="section-title" style="font-size: 30px;">Simulasi Lalu Lintas Adaptif 4 Jalur</h1>
                <p class="section-desc">
                    Simulasi mandiri di browser untuk menguji algoritma kepadatan jendela 5 detik (ambang &lt; 10 cm), antrean mobil, serta invarian keselamatan transisi lampu tanpa memengaruhi perangkat ESP32 asli.
                </p>
            </div>
        </div>

        <!-- Banner Peringatan Pelanggaran Keselamatan (Tersembunyi jika aman) -->
        <div id="safetyAlertBanner" class="safety-alert-banner" role="alert">
            <i class="bi bi-exclamation-octagon-fill"></i>
            <span id="safetyAlertText">PERINGATAN KESELAMATAN: Terdeteksi pelanggaran invarian lampu lalu lintas!</span>
        </div>

        <!-- Bar Kontrol Utama Simulasi -->
        <div class="sim-control-bar">
            <!-- Tombol Mulai / Jeda / Reset -->
            <div class="sim-ctrl-group">
                <span class="sim-ctrl-label">Kendali:</span>
                <button type="button" id="btnToggleSim" class="btn-sim primary" onclick="toggleSimulasi()">
                    <i class="bi bi-pause-fill" id="iconToggleSim"></i>
                    <span id="textToggleSim">Jeda</span>
                </button>
                <button type="button" class="btn-sim danger" onclick="resetSimulasi()">
                    <i class="bi bi-arrow-counterclockwise"></i>
                    <span>Reset (Semua Merah)</span>
                </button>
            </div>

            <!-- Pilihan Kecepatan 1x / 2x / 5x -->
            <div class="sim-ctrl-group">
                <span class="sim-ctrl-label">Kecepatan:</span>
                <button type="button" id="btnSpeed1" class="btn-sim active-speed" onclick="setKecepatanSimulasi(1)">1x</button>
                <button type="button" id="btnSpeed2" class="btn-sim" onclick="setKecepatanSimulasi(2)">2x</button>
                <button type="button" id="btnSpeed5" class="btn-sim" onclick="setKecepatanSimulasi(5)">5x</button>
            </div>

            <!-- Pilihan Mode Otomatis / Manual Prioritas -->
            <div class="sim-ctrl-group">
                <span class="sim-ctrl-label">Mode Kerja:</span>
                <select id="selectModeSim" class="sim-select" onchange="ubahModeSimulasi(this.value)">
                    <option value="AUTO">Otomatis (Adaptif Sensor)</option>
                    <option value="MANUAL_1">Manual &mdash; Prioritas Jalur 1 (Utara)</option>
                    <option value="MANUAL_2">Manual &mdash; Prioritas Jalur 2 (Timur)</option>
                    <option value="MANUAL_3">Manual &mdash; Prioritas Jalur 3 (Selatan)</option>
                    <option value="MANUAL_4">Manual &mdash; Prioritas Jalur 4 (Barat)</option>
                </select>
            </div>

            <!-- Preset Skenario Uji Cepat -->
            <div class="sim-ctrl-group">
                <span class="sim-ctrl-label">Preset Skenario:</span>
                <button type="button" class="btn-sim" onclick="terapkanPresetSkenario('A')" title="Jalur 1 Padat, Jalur 2-4 Sepi">
                    (a) 1 Padat, 3 Sepi
                </button>
                <button type="button" class="btn-sim" onclick="terapkanPresetSkenario('B')" title="Keempat Jalur Padat">
                    (b) Semua Padat
                </button>
                <button type="button" class="btn-sim" onclick="terapkanPresetSkenario('C')" title="Keempat Jalur Sepi">
                    (c) Semua Sepi
                </button>
            </div>
        </div>

        <!-- Layout Utama: Kiri Visual Tampak Atas Perempatan, Kanan Panel Info & Kontrol Sensor -->
        <div class="sim-main-layout">

            <!-- KIRI: Tampak Atas Perempatan 4 Jalur -->
            <div class="sim-intersection-card">
                <div class="intersection-stage" id="intersectionStage">
                    <!-- Jalan Raya Vertikal & Horizontal -->
                    <div class="road-vertical"></div>
                    <div class="road-horizontal"></div>

                    <!-- Marka Jalan Tengah -->
                    <div class="center-line-v-top"></div>
                    <div class="center-line-v-bottom"></div>
                    <div class="center-line-h-left"></div>
                    <div class="center-line-h-right"></div>

                    <!-- Garis Henti (Stop Line) 4 Jalur -->
                    <div class="stop-line lane-1"></div>
                    <div class="stop-line lane-2"></div>
                    <div class="stop-line lane-3"></div>
                    <div class="stop-line lane-4"></div>

                    <!-- Zona Deteksi Sensor Virtual HC-SR04 (< 10 cm) -->
                    <div id="sensorZone1" class="sensor-zone lane-1" title="Zona Sensor HC-SR04 Jalur 1"></div>
                    <div id="sensorZone2" class="sensor-zone lane-2" title="Zona Sensor HC-SR04 Jalur 2"></div>
                    <div id="sensorZone3" class="sensor-zone lane-3" title="Zona Sensor HC-SR04 Jalur 3"></div>
                    <div id="sensorZone4" class="sensor-zone lane-4" title="Zona Sensor HC-SR04 Jalur 4"></div>

                    <!-- Kotak Tengah Perempatan -->
                    <div class="intersection-center-box">
                        <div class="center-phase-badge" id="centerStageBadge">
                            SEMUA MERAH<br>1.0 dtk
                        </div>
                    </div>

                    <!-- Label Sudut 4 Jalur -->
                    <div class="corner-label lane-1">
                        Jalur 1 (Utara)
                        <span class="sub" id="stageSub1">30.0 cm &bull; Sepi (0%)</span>
                    </div>
                    <div class="corner-label lane-2">
                        Jalur 2 (Timur)
                        <span class="sub" id="stageSub2">30.0 cm &bull; Sepi (0%)</span>
                    </div>
                    <div class="corner-label lane-3">
                        Jalur 3 (Selatan)
                        <span class="sub" id="stageSub3">30.0 cm &bull; Sepi (0%)</span>
                    </div>
                    <div class="corner-label lane-4">
                        Jalur 4 (Barat)
                        <span class="sub" id="stageSub4">30.0 cm &bull; Sepi (0%)</span>
                    </div>

                    <!-- Lampu Lalu Lintas di 4 Lengan Perempatan -->
                    <div class="stage-traffic-light vertical lane-1">
                        <span id="bulb-1-merah" class="stage-bulb merah active"></span>
                        <span id="bulb-1-kuning" class="stage-bulb kuning"></span>
                        <span id="bulb-1-hijau" class="stage-bulb hijau"></span>
                    </div>
                    <div class="stage-traffic-light horizontal lane-2">
                        <span id="bulb-2-merah" class="stage-bulb merah active"></span>
                        <span id="bulb-2-kuning" class="stage-bulb kuning"></span>
                        <span id="bulb-2-hijau" class="stage-bulb hijau"></span>
                    </div>
                    <div class="stage-traffic-light vertical lane-3">
                        <span id="bulb-3-merah" class="stage-bulb merah active"></span>
                        <span id="bulb-3-kuning" class="stage-bulb kuning"></span>
                        <span id="bulb-3-hijau" class="stage-bulb hijau"></span>
                    </div>
                    <div class="stage-traffic-light horizontal lane-4">
                        <span id="bulb-4-merah" class="stage-bulb merah active"></span>
                        <span id="bulb-4-kuning" class="stage-bulb kuning"></span>
                        <span id="bulb-4-hijau" class="stage-bulb hijau"></span>
                    </div>

                    <!-- Lapisan Render Mobil Animasi -->
                    <div id="carsLayer" class="cars-layer"></div>
                </div>

                <div style="margin-top: 12px; font-size: 12px; color: var(--text-muted); text-align: center;">
                    <i class="bi bi-info-circle"></i>
                    Kotak biru/oranye putus-putus menandakan area baca sensor ultrasonik HC-SR04 (&lt; 10 cm = Terisi).
                </div>
            </div>

            <!-- KANAN: Panel Informasi Siklus, Kontrol Sensor 4 Jalur, & Log Singkat -->
            <div class="sim-side-panel">

                <!-- 1. Ringkasan Status Siklus Saat Ini -->
                <div class="sim-info-card">
                    <div class="sim-info-header">
                        <div class="sim-info-title">
                            <i class="bi bi-activity"></i>
                            <span>Status Siklus &amp; Keselamatan</span>
                        </div>
                        <span id="safetyStatusBadge" class="pill-badge badge-auto">
                            <i class="bi bi-shield-check"></i> Invarian Aman (0 Pelanggaran)
                        </span>
                    </div>

                    <div class="sim-kpi-grid">
                        <div class="sim-kpi-box">
                            <div class="sim-kpi-label">Jalur Aktif</div>
                            <div class="sim-kpi-val" id="kpiActiveLane">Semua Merah</div>
                        </div>
                        <div class="sim-kpi-box">
                            <div class="sim-kpi-label">Fase Lampu</div>
                            <div class="sim-kpi-val" id="kpiPhaseName">SEMUA MERAH</div>
                        </div>
                        <div class="sim-kpi-box">
                            <div class="sim-kpi-label">Sisa Detik</div>
                            <div class="sim-kpi-val"><span id="kpiRemainingSec">1.0</span> <small style="font-size: 13px;">dtk</small></div>
                        </div>
                        <div class="sim-kpi-box">
                            <div class="sim-kpi-label">Siklus Selesai</div>
                            <div class="sim-kpi-val" id="kpiCycleCount">0</div>
                        </div>
                    </div>
                </div>

                <!-- 2. Kartu Sensor Virtual HC-SR04 & Antrean Mobil (Jalur 1 - 4) -->
                <div class="sim-lanes-grid">
                    <?php
                    $daftar_nama_jalur = [
                        1 => 'Jalur 1 (Utara)',
                        2 => 'Jalur 2 (Timur)',
                        3 => 'Jalur 3 (Selatan)',
                        4 => 'Jalur 4 (Barat)',
                    ];
                    foreach ($daftar_nama_jalur as $id => $nama):
                    ?>
                    <div class="sim-lane-card" id="laneCard-<?= $id; ?>">
                        <div class="sim-lane-top">
                            <div class="sim-lane-title">
                                <span><?= htmlspecialchars($nama); ?></span>
                            </div>
                            <span id="laneDensityBadge-<?= $id; ?>" class="pill-badge badge-auto">Sepi (0%)</span>
                        </div>

                        <div class="sim-lane-stats">
                            <div class="sim-mini-stat">
                                <div class="lbl">Lampu</div>
                                <div class="val" id="laneLightText-<?= $id; ?>" style="color: #dc2626;">Merah</div>
                            </div>
                            <div class="sim-mini-stat">
                                <div class="lbl">Antrean</div>
                                <div class="val"><span id="laneQueueCount-<?= $id; ?>">0</span> mobil</div>
                            </div>
                            <div class="sim-mini-stat">
                                <div class="lbl">Tunggu</div>
                                <div class="val"><span id="laneWaitSec-<?= $id; ?>">0</span> dtk</div>
                            </div>
                        </div>

                        <!-- Kontrol Sensor HC-SR04 Virtual (Slider 0-50 cm + Tombol Antrean Mobil) -->
                        <div class="sim-sensor-control">
                            <div class="sim-slider-row">
                                <span><i class="bi bi-broadcast"></i> Sensor HC-SR04: <strong id="laneDistLabel-<?= $id; ?>">30.0 cm</strong></span>
                                <span id="laneOccupiedTag-<?= $id; ?>" style="color: #6b6a67;">Kosong (&ge;10 cm)</span>
                            </div>
                            <input
                                type="range"
                                id="laneSlider-<?= $id; ?>"
                                class="sim-range-input"
                                min="0"
                                max="50"
                                step="0.5"
                                value="30"
                                oninput="ubahSliderJarak(<?= $id; ?>, this.value)"
                            >
                            <div class="sim-queue-btns">
                                <button type="button" class="btn-queue-step" onclick="ubahAntreanMobil(<?= $id; ?>, -1)">
                                    <i class="bi bi-dash-circle"></i> Kurangi Mobil
                                </button>
                                <button type="button" class="btn-queue-step" onclick="ubahAntreanMobil(<?= $id; ?>, 1)">
                                    <i class="bi bi-plus-circle"></i> Tambah Mobil
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- 3. Log Kejadian Singkat -->
                <div class="sim-info-card">
                    <div class="sim-info-header" style="margin-bottom: 10px;">
                        <div class="sim-info-title" style="font-size: 15px;">
                            <i class="bi bi-journal-text"></i>
                            <span>Log Kejadian &amp; Transisi Siklus</span>
                        </div>
                        <button type="button" class="btn-sim" style="padding: 4px 12px; font-size: 11.5px;" onclick="bersihkanLogSimulasi()">
                            Bersihkan Log
                        </button>
                    </div>
                    <div id="simLogList" class="sim-log-list"></div>
                </div>

            </div>
        </div>

    </div><!-- /.main-wrapper -->

    <!-- Script Simulasi Murni Browser -->
    <script src="assets/js/simulasi.js"></script>
</body>
</html>
