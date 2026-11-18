        <!-- Intersections Control Grid -->
        <section id="control-section">
            <div class="section-header-block">
                <div>
                    <h2 class="section-title">Kendali 4 Jalur Persimpangan</h2>
                    <p class="section-desc">
                        Invarian keselamatan aktif: hanya <strong>satu jalur</strong> yang boleh hijau pada satu waktu. Pergantian jalur wajib melewati urutan <strong>Hijau &rarr; Kuning (<?= intval($config_map['kuning'] ?? 2); ?> dtk) &rarr; Semua Merah (<?= intval($config_map['semua_merah'] ?? 1); ?> dtk)</strong>.
                    </p>
                </div>
                <div class="d-flex gap-2" style="align-items: center; flex-wrap: wrap;">
                    <span class="pill-badge badge-auto">Mode Sistem: <?= htmlspecialchars($config_map['mode'] ?? 'AUTO'); ?></span>
                    <span class="pill-badge badge-manual">Jalur Terisi (&lt;<?= htmlspecialchars($config_map['ambang_cm'] ?? '10'); ?> cm): <?= intval($total_terisi); ?>/4</span>
                    <?php if (($config_map['mode'] ?? 'AUTO') === 'MANUAL'): ?>
                    <form action="update_manual.php" method="POST" style="margin: 0;" onsubmit="pauseRefreshDuringSubmit()">
                        <input type="hidden" name="id" value="0">
                        <input type="hidden" name="mode" value="auto">
                        <button type="submit" class="btn-header-action" style="border: none; cursor: pointer;">
                            <i class="bi bi-arrow-repeat"></i> Kembalikan ke Otomatis (AUTO)
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="intersections-grid" id="gridCards">
                <?php foreach ($persimpangan_list as $row):
                    $idJalur   = intval($row['id']);
                    $isManual  = ($row['mode'] === 'manual');
                    $lampu     = strtolower($row['lampu_aktif'] ?? 'merah');
                    $jarakCm   = floatval($row['jarak_cm'] ?? 30.0);
                    $rasio     = floatval($row['rasio_terisi'] ?? 0.0);
                    $kepadatan = $row['kepadatan'] ?? 'Sepi';
                    $ambangCm  = floatval($config_map['ambang_cm'] ?? 10.0);
                    $terisiSkr = ($jarakCm < $ambangCm);

                    $badgeKepadatanClass = 'badge-auto';
                    if ($kepadatan === 'Padat') {
                        $badgeKepadatanClass = 'badge-padat';
                    } elseif ($kepadatan === 'Sedang') {
                        $badgeKepadatanClass = 'badge-manual';
                    }
                ?>
                <div class="simpang-card" id="simpang-<?= $idJalur; ?>" data-name="<?= strtolower(htmlspecialchars($row['nama_simpang'] . ' ' . $kepadatan)); ?>">
                    <!-- Card Top Bar -->
                    <div class="card-top-bar">
                        <div class="simpang-name">
                            <i class="bi bi-geo-alt text-muted"></i>
                            <?= htmlspecialchars($row['nama_simpang']); ?>
                        </div>
                        <span class="pill-badge <?= $isManual ? 'badge-manual' : 'badge-auto'; ?>">
                            <?= $isManual ? 'PRIORITAS MANUAL' : 'AUTO ADAPTIF'; ?>
                        </span>
                    </div>

                    <!-- Realistic Traffic Light Display -->
                    <div class="traffic-light-visual-box">
                        <div class="physical-traffic-light">
                            <div class="light-bulb merah <?= ($lampu === 'merah') ? 'active' : ''; ?>" title="Lampu Merah"></div>
                            <div class="light-bulb kuning <?= ($lampu === 'kuning') ? 'active' : ''; ?>" title="Lampu Kuning"></div>
                            <div class="light-bulb hijau <?= ($lampu === 'hijau') ? 'active' : ''; ?>" title="Lampu Hijau"></div>
                        </div>
                        <div class="light-status-label">
                            <div class="label-text">Sinyal Aktif</div>
                            <div class="active-color-name <?= htmlspecialchars($lampu); ?>"><?= ucfirst(htmlspecialchars($lampu)); ?></div>
                            <div style="margin-top: 6px;">
                                <span class="pill-badge <?= $badgeKepadatanClass; ?>" style="font-size: 11px;">
                                    <?= htmlspecialchars($kepadatan); ?> &bull; <?= intval($row['jumlah_kendaraan'] ?? 0); ?> Mobil
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Metrics Info (Jarak Sensor & Durasi Hijau) -->
                    <div class="metrics-row">
                        <div class="metric-chip">
                            <div class="metric-label">
                                <i class="bi bi-stopwatch"></i> Durasi Hijau
                            </div>
                            <div class="metric-value">
                                <?= intval($row['durasi_hijau']); ?>
                                <span style="font-size: 13px; font-weight: 500;">dtk</span>
                            </div>
                        </div>

                        <div class="metric-chip">
                            <div class="metric-label">
                                <i class="bi bi-broadcast"></i> Jarak &amp; Antrean
                            </div>
                            <div class="metric-value">
                                <?= number_format($jarakCm, 1); ?>
                                <span style="font-size: 13px; font-weight: 500;">cm (<?= intval($row['jumlah_kendaraan'] ?? 0); ?> unit)</span>
                            </div>
                        </div>
                    </div>

                    <div class="timestamp-info">
                        <i class="bi bi-clock"></i>
                        <span>Pembaruan: <?= htmlspecialchars($row['updated_at']); ?></span>
                    </div>

                    <!-- Safe Priority Control Form (Bagian 7, 8.1, 8.5, 9.10) -->
                    <div class="control-form-wrapper">
                        <form action="update_manual.php" method="POST" onsubmit="pauseRefreshDuringSubmit()">
                            <input type="hidden" name="id" value="<?= $idJalur; ?>">
                            
                            <div class="form-row">
                                <label class="form-label-custom">Mode Kendali Jalur <?= $idJalur; ?>:</label>
                                <select name="mode" class="form-select-custom" onchange="handleModeChange(this, <?= $idJalur; ?>)">
                                    <option value="auto" <?= !$isManual ? 'selected' : ''; ?>>Otomatis (Adaptif Sensor Cloud)</option>
                                    <option value="manual" <?= $isManual ? 'selected' : ''; ?>>Manual &mdash; Prioritaskan Jalur Ini Hijau</option>
                                </select>
                            </div>

                            <div class="form-row" style="font-size: 12px; color: #6c6962; line-height: 1.45; margin-bottom: 10px;">
                                <i class="bi bi-shield-lock"></i>
                                Memilih <strong>Manual</strong> akan menginstruksikan Worker MQTT untuk memberi lampu Hijau pada jalur ini setelah jalur sebelumnya menyelesaikan transisi <em>Kuning &rarr; Semua Merah</em>.
                            </div>

                            <button type="submit" id="btn-submit-<?= $idJalur; ?>" class="btn-apply-custom">
                                <i class="bi bi-check2-circle"></i>
                                <?= $isManual ? 'Perbarui / Terapkan Mode' : 'Jadikan Prioritas / Terapkan'; ?>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
