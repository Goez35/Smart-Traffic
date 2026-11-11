        <!-- Intersections Control Grid -->
        <section id="control-section">
            <div class="section-header-block">
                <div>
                    <h2 class="section-title">Kendali Persimpangan Lalu Lintas</h2>
                    <p class="section-desc">Pilih mode otomatis sensor IoT atau lakukan penyesuaian lampu secara manual.</p>
                </div>
                <div class="d-flex gap-2">
                    <span class="pill-badge badge-auto">Auto Sensor: <?= $total_auto; ?></span>
                    <span class="pill-badge badge-manual">Manual Override: <?= $total_manual; ?></span>
                </div>
            </div>

            <div class="intersections-grid" id="gridCards">
                <?php foreach($persimpangan_list as $row): 
                    $isManual = ($row['mode'] == 'manual');
                    $lampu = strtolower($row['lampu_aktif']);
                ?>
                <div class="simpang-card" id="simpang-<?= $row['id']; ?>" data-name="<?= strtolower(htmlspecialchars($row['nama_simpang'])); ?>">
                    <!-- Card Top Bar -->
                    <div class="card-top-bar">
                        <div class="simpang-name">
                            <i class="bi bi-geo-alt text-muted"></i>
                            <?= htmlspecialchars($row['nama_simpang']); ?>
                        </div>
                        <span class="pill-badge <?= $isManual ? 'badge-manual' : 'badge-auto'; ?>">
                            <?= strtoupper($row['mode']); ?>
                        </span>
                    </div>

                    <!-- Realistic Traffic Light Display -->
                    <div class="traffic-light-visual-box">
                        <div class="physical-traffic-light">
                            <div class="light-bulb merah <?= ($lampu == 'merah') ? 'active' : ''; ?>" title="Lampu Merah"></div>
                            <div class="light-bulb kuning <?= ($lampu == 'kuning') ? 'active' : ''; ?>" title="Lampu Kuning"></div>
                            <div class="light-bulb hijau <?= ($lampu == 'hijau') ? 'active' : ''; ?>" title="Lampu Hijau"></div>
                        </div>
                        <div class="light-status-label">
                            <div class="label-text">Sinyal Aktif</div>
                            <div class="active-color-name <?= $lampu; ?>"><?= ucfirst($lampu); ?></div>
                        </div>
                    </div>

                    <!-- Metrics Info -->
                    <div class="metrics-row">
                        <div class="metric-chip">
                            <div class="metric-label">
                                <i class="bi bi-stopwatch"></i> Durasi Hijau
                            </div>
                            <div class="metric-value"><?= htmlspecialchars($row['durasi_hijau']); ?> <span style="font-size: 13px; font-weight: 500;">dtk</span></div>
                        </div>

                        <div class="metric-chip">
                            <div class="metric-label">
                                <i class="bi bi-car-front"></i> Kendaraan
                            </div>
                            <div class="metric-value"><?= htmlspecialchars($row['jumlah_kendaraan']); ?> <span style="font-size: 13px; font-weight: 500;">unit</span></div>
                        </div>
                    </div>

                    <div class="timestamp-info">
                        <i class="bi bi-clock"></i>
                        <span>Pembaruan: <?= htmlspecialchars($row['updated_at']); ?></span>
                    </div>

                    <!-- Control Form -->
                    <div class="control-form-wrapper">
                        <form action="update_manual.php" method="POST" onsubmit="pauseRefreshDuringSubmit()">
                            <input type="hidden" name="id" value="<?= $row['id']; ?>">
                            
                            <div class="form-row">
                                <label class="form-label-custom">Pilih Mode Kerja:</label>
                                <select name="mode" class="form-select-custom" onchange="handleModeChange(this, <?= $row['id']; ?>)">
                                    <option value="auto" <?= !$isManual ? 'selected' : ''; ?>>Otomatis (Adaptif Sensor)</option>
                                    <option value="manual" <?= $isManual ? 'selected' : ''; ?>>Manual (Override)</option>
                                </select>
                            </div>

                            <div class="form-row">
                                <label class="form-label-custom">Setel Lampu Manual:</label>
                                <select name="lampu_manual" id="select-lampu-<?= $row['id']; ?>" class="form-select-custom" <?= !$isManual ? 'disabled' : ''; ?>>
                                    <option value="merah" <?= ($lampu == 'merah') ? 'selected' : ''; ?>>Merah (Berhenti)</option>
                                    <option value="kuning" <?= ($lampu == 'kuning') ? 'selected' : ''; ?>>Kuning (Hati-hati)</option>
                                    <option value="hijau" <?= ($lampu == 'hijau') ? 'selected' : ''; ?>>Hijau (Jalan)</option>
                                </select>
                            </div>

                            <button type="submit" id="btn-submit-<?= $row['id']; ?>" class="btn-apply-custom" <?= !$isManual ? 'disabled' : ''; ?>>
                                <i class="bi bi-check2"></i>
                                Terapkan Perubahan
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

