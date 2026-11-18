        <!-- Hero Section -->
        <section class="hero-section">
            <!-- Left Hero Content -->
            <div class="hero-left">
                <div class="hero-tag">
                    <i class="bi bi-cloud-check"></i>
                    <span>Arsitektur MQTT EMQX Cloud &bull; 4 Jalur Adaptif</span>
                </div>

                <h1 class="hero-title">
                    Pantau &amp; atur<br>
                    lalu lintas cerdas<br>
                    secara real-time.
                </h1>

                <p class="hero-subtitle">
                    Penyesuaian durasi lampu hijau otomatis di Cloud Worker berbasis kepadatan antrean 5 detik terakhir dari 4 sensor ultrasonik HC-SR04 (ambang &lt; <?= htmlspecialchars($config_map['ambang_cm'] ?? '10'); ?> cm), dilengkapi pengaman transisi &amp; fallback lokal.
                </p>

                <div class="hero-actions">
                    <a href="#control-section" class="btn-hero-black">
                        <span>Monitor 4 Jalur</span>
                        <i class="bi bi-arrow-right"></i>
                    </a>
                    <a href="#log-section" class="btn-hero-ghost">
                        <i class="bi bi-clock-history"></i>
                        <span>Lihat Riwayat Siklus</span>
                    </a>
                </div>
            </div>

            <!-- Right Floating Card Widget -->
            <div class="hero-right">
                <div class="floating-card">
                    <!-- Top rounded box -->
                    <div class="card-prompt-box">
                        <div class="prompt-header">
                            Ringkasan Sistem &bull; Mode <?= htmlspecialchars($config_map['mode'] ?? 'AUTO'); ?>
                        </div>
                        <div class="prompt-icons">
                            <a href="#control-section" class="prompt-icon-btn" title="Kontrol 4 Jalur"><i class="bi bi-stoplights"></i></a>
                            <a href="#log-section" class="prompt-icon-btn" title="Riwayat & Log"><i class="bi bi-bar-chart"></i></a>
                            <span class="prompt-icon-btn" title="Jalur Terisi"><i class="bi bi-broadcast-pin"></i></span>
                            <span class="prompt-icon-btn" title="Pengaman Transisi Aktif"><i class="bi bi-shield-check"></i></span>
                        </div>
                    </div>

                    <!-- Search Input -->
                    <span class="card-section-label">Filter Jalur</span>
                    <div class="card-search-pill">
                        <i class="bi bi-search"></i>
                        <input type="text" placeholder="Cari nama jalur (Utara, Timur, Selatan, Barat)..." oninput="filterIntersections(this.value)">
                    </div>

                    <!-- Live 4 Lanes Preview -->
                    <div class="card-item-group">
                        <span class="card-section-label">Status Keempat Jalur Saat Ini</span>
                        <?php foreach ($persimpangan_list as $item): ?>
                        <a href="#simpang-<?= intval($item['id']); ?>" class="card-item-row">
                            <div class="item-thumb-signal">
                                <span class="signal-mini-dot red <?= ($item['lampu_aktif'] === 'merah') ? 'active' : ''; ?>"></span>
                                <span class="signal-mini-dot yellow <?= ($item['lampu_aktif'] === 'kuning') ? 'active' : ''; ?>"></span>
                                <span class="signal-mini-dot green <?= ($item['lampu_aktif'] === 'hijau') ? 'active' : ''; ?>"></span>
                            </div>
                            <div class="item-info">
                                <div class="item-title"><?= htmlspecialchars($item['nama_simpang']); ?></div>
                                <div class="item-desc">
                                    <span class="item-badge-mode <?= ($item['mode'] === 'manual') ? 'badge-mode-manual' : 'badge-mode-auto'; ?>">
                                        <?= ($item['mode'] === 'manual') ? 'PRIORITAS' : 'AUTO'; ?>
                                    </span>
                                    <span>&bull; <?= number_format(floatval($item['jarak_cm'] ?? 30), 1); ?> cm (<?= htmlspecialchars($item['kepadatan'] ?? 'Sepi'); ?>)</span>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>
