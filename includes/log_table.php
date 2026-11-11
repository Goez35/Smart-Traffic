        <!-- History Log Section -->
        <section id="log-section">
            <div class="section-header-block">
                <div>
                    <h2 class="section-title">Riwayat Tracking & Siklus Lampu</h2>
                    <p class="section-desc">Catatan rekaman volume kendaraan dan kalkulasi durasi adaptif lampu lalu lintas.</p>
                </div>
            </div>

            <div class="log-container">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>No Log</th>
                            <th>Persimpangan</th>
                            <th>Volume Terdeteksi</th>
                            <th>Durasi Hijau Terpilih</th>
                            <th>Waktu Pencatatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: #9c9890; padding: 24px;">Belum ada riwayat aktivitas yang tercatat.</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach($logs as $log): ?>
                            <tr>
                                <td><span class="pill-id">#<?= $log['id']; ?></span></td>
                                <td><strong>Simpang <?= htmlspecialchars($log['simpang_id']); ?></strong></td>
                                <td><span class="pill-unit"><?= htmlspecialchars($log['jumlah_kendaraan']); ?> Unit Kendaraan</span></td>
                                <td><span class="pill-duration"><i class="bi bi-stopwatch"></i> <?= htmlspecialchars($log['durasi_terpilih']); ?> Detik</span></td>
                                <td style="color: #6c6962;"><?= htmlspecialchars($log['waktu_catat']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

