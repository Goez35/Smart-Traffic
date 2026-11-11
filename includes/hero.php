        <!-- Hero Section -->
        <section class="hero-section">
            <!-- Left Hero Content -->
            <div class="hero-left">
                <div class="hero-tag">
                    <i class="bi bi-cpu"></i>
                    <span>Sistem IoT ESP32 Adaptive Control</span>
                </div>

                <h1 class="hero-title">
                    Pantau & atur<br>
                    lalu lintas cerdas<br>
                    secara real-time.
                </h1>

                <p class="hero-subtitle">
                    Otomatisasi durasi lampu hijau berdasarkan volume kendaraan jalan dengan sensor HC-SR04, didukung kendali manual dan pencatatan siklus komprehensif.
                </p>

                <div class="hero-actions">
                    <a href="#control-section" class="btn-hero-black">
                        <span>Jelajahi Simpang</span>
                        <i class="bi bi-arrow-right"></i>
                    </a>
                    <a href="#log-section" class="btn-hero-ghost">
                        <i class="bi bi-clock-history"></i>
                        <span>Lihat Riwayat Log</span>
                    </a>
                </div>
            </div>

            <!-- Right Floating Card Widget -->
            <div class="hero-right">
                <div class="floating-card">
                    <!-- Top rounded box -->
                    <div class="card-prompt-box">
                        <div class="prompt-header">
                            Status Sistem & Navigasi Cepat
                        </div>
                        <div class="prompt-icons">
                            <a href="#control-section" class="prompt-icon-btn" title="Kontrol Lampu"><i class="bi bi-stoplights"></i></a>
                            <a href="#log-section" class="prompt-icon-btn" title="Statistik & Log"><i class="bi bi-bar-chart"></i></a>
                            <span class="prompt-icon-btn" title="Volume Kendaraan"><i class="bi bi-car-front"></i></span>
                            <span class="prompt-icon-btn" title="Mode Manual"><i class="bi bi-sliders"></i></span>
                        </div>
                    </div>

                    <!-- Search Input -->
                    <span class="card-section-label">Filter Simpang</span>
                    <div class="card-search-pill">
                        <i class="bi bi-search"></i>
                        <input type="text" placeholder="Cari nama persimpangan..." oninput="filterIntersections(this.value)">
                    </div>

                    <!-- Recent Intersections -->
                    <div class="card-item-group">
                        <span class="card-section-label">Terkini</span>
                        <?php if (!empty($persimpangan_list)): 
                            $first = $persimpangan_list[0];
                        ?>
                        <a href="#simpang-<?= $first['id']; ?>" class="card-item-row">
                            <div class="item-thumb-signal">
                                <span class="signal-mini-dot red <?= ($first['lampu_aktif'] == 'merah') ? 'active' : ''; ?>"></span>
                                <span class="signal-mini-dot yellow <?= ($first['lampu_aktif'] == 'kuning') ? 'active' : ''; ?>"></span>
                                <span class="signal-mini-dot green <?= ($first['lampu_aktif'] == 'hijau') ? 'active' : ''; ?>"></span>
                            </div>
                            <div class="item-info">
                                <div class="item-title"><?= htmlspecialchars($first['nama_simpang']); ?></div>
                                <div class="item-desc">
                                    <span class="item-badge-mode <?= ($first['mode'] == 'manual') ? 'badge-mode-manual' : 'badge-mode-auto'; ?>">
                                        <?= strtoupper($first['mode']); ?>
                                    </span>
                                    <span>• <?= $first['jumlah_kendaraan']; ?> Kendaraan</span>
                                </div>
                            </div>
                        </a>
                        <?php endif; ?>
                    </div>

                    <!-- Intersections List Preview -->
                    <div class="card-item-group">
                        <span class="card-section-label">Daftar Persimpangan</span>
                        <?php foreach (array_slice($persimpangan_list, 1, 2) as $item): ?>
                        <a href="#simpang-<?= $item['id']; ?>" class="card-item-row">
                            <div class="item-thumb-signal">
                                <span class="signal-mini-dot red <?= ($item['lampu_aktif'] == 'merah') ? 'active' : ''; ?>"></span>
                                <span class="signal-mini-dot yellow <?= ($item['lampu_aktif'] == 'kuning') ? 'active' : ''; ?>"></span>
                                <span class="signal-mini-dot green <?= ($item['lampu_aktif'] == 'hijau') ? 'active' : ''; ?>"></span>
                            </div>
                            <div class="item-info">
                                <div class="item-title"><?= htmlspecialchars($item['nama_simpang']); ?></div>
                                <div class="item-desc">
                                    <span class="item-badge-mode <?= ($item['mode'] == 'manual') ? 'badge-mode-manual' : 'badge-mode-auto'; ?>">
                                        <?= strtoupper($item['mode']); ?>
                                    </span>
                                    <span>• <?= $item['jumlah_kendaraan']; ?> Unit</span>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>

