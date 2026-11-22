        <!-- History Log Section -->
        <section id="log-section">
            <div class="section-header-block">
                <div>
                    <h2 class="section-title">Riwayat Siklus &amp; Antrean Kendaraan</h2>
                    <p class="section-desc">Catatan pembacaan jarak sensor HC-SR04, jumlah kendaraan terhitung selama lampu merah, klasifikasi kepadatan, dan durasi hijau adaptif.</p>
                </div>
            </div>

            <div class="log-container">
                <table class="custom-table">
                    <caption class="visually-hidden">Riwayat Siklus Lampu Hijau dan Antrean Kendaraan</caption>
                    <thead>
                        <tr>
                            <th scope="col">No Log</th>
                            <th scope="col">Jalur Persimpangan</th>
                            <th scope="col">Jarak Sensor</th>
                            <th scope="col">Kepadatan &amp; Antrean</th>
                            <th scope="col">Durasi Hijau Terpilih</th>
                            <th scope="col">Waktu Pencatatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #9c9890; padding: 24px;">Belum ada riwayat siklus yang tercatat.</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log):
                                $namaJalurLog = !empty($log['nama_simpang']) ? $log['nama_simpang'] : ('Jalur ' . intval($log['simpang_id']));
                                $jarakLog     = isset($log['jarak_cm']) ? number_format(floatval($log['jarak_cm']), 1) . ' cm' : '-';
                                $jmlMobilLog  = intval($log['jumlah_kendaraan'] ?? 0);
                                $kepadatanLog = $log['kepadatan'] ?? 'Sepi';
                            ?>
                            <tr>
                                <td><span class="pill-id">#<?= intval($log['id']); ?></span></td>
                                <td><strong><?= htmlspecialchars($namaJalurLog); ?></strong></td>
                                <td><span class="pill-unit"><i class="bi bi-rulers"></i> <?= htmlspecialchars($jarakLog); ?></span></td>
                                <td>
                                    <span class="pill-badge <?= ($kepadatanLog === 'Padat') ? 'badge-padat' : (($kepadatanLog === 'Sedang') ? 'badge-manual' : 'badge-auto'); ?>">
                                        <?= htmlspecialchars($kepadatanLog); ?> (<?= $jmlMobilLog; ?> Mobil)
                                    </span>
                                </td>
                                <td><span class="pill-duration"><i class="bi bi-stopwatch"></i> <?= intval($log['durasi_terpilih']); ?> Detik</span></td>
                                <td style="color: #6c6962;"><?= htmlspecialchars($log['waktu_catat']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Event Log (MQTT Status & Mode Changes) -->
            <div class="section-header-block" style="margin-top: 36px;">
                <div>
                    <h2 class="section-title" style="font-size: 20px;">Log Kejadian Sistem &amp; Status MQTT</h2>
                    <p class="section-desc">Rekaman status online/offline ESP32 (Last Will), pergantian mode manual/otomatis, dan aktivitas Worker PHP.</p>
                </div>
            </div>

            <div class="log-container">
                <table class="custom-table">
                    <caption class="visually-hidden">Log Kejadian Sistem dan Status Koneksi MQTT ESP32</caption>
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Jenis Kejadian</th>
                            <th scope="col">Keterangan</th>
                            <th scope="col">Waktu</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($event_logs)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: #9c9890; padding: 20px;">Belum ada kejadian sistem yang tercatat.</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($event_logs as $ev): ?>
                            <tr>
                                <td><span class="pill-id">#<?= intval($ev['id']); ?></span></td>
                                <td><span class="pill-badge badge-auto"><?= htmlspecialchars($ev['jenis']); ?></span></td>
                                <td><?= htmlspecialchars($ev['pesan']); ?></td>
                                <td style="color: #6c6962;"><?= htmlspecialchars($ev['waktu_catat']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
