<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <title>Dashboard Kontrol 4 Jalur | SmartTraffic</title>
    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons (Secured with Subresource Integrity, CORS, and Referrer Policy) -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+"
        crossorigin="anonymous"
        referrerpolicy="no-referrer"
    >
    <!-- Custom Style Sheet -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <a href="#main-content" class="skip-link visually-hidden">Langsung ke konten utama</a>

    <!-- Header Navigation Bar -->
    <header class="nav-header">
        <div class="nav-left">
            <a href="index.php" class="brand-logo">
                <span class="dot" aria-hidden="true"></span>
                <span>SmartTraffic</span>
            </a>
            <search class="header-search">
                <label for="globalSearch" class="visually-hidden">Cari jalur, kepadatan, atau status</label>
                <i class="bi bi-search" aria-hidden="true"></i>
                <input
                    type="search"
                    id="globalSearch"
                    name="q"
                    placeholder="Cari jalur, kepadatan, atau status..."
                    autocorrect="off"
                    autocapitalize="none"
                    spellcheck="false"
                >
            </search>
        </div>

        <div class="nav-right">
            <nav aria-label="Utama" class="nav-links-group">
                <a href="#control-section" class="nav-link-item">Monitoring 4 Jalur</a>
                <a href="#log-section" class="nav-link-item">Riwayat &amp; Log</a>
                <a href="simulasi.php" class="nav-link-item">Simulasi 4 Jalur</a>
            </nav>

            <!-- Indikator Status Koneksi ESP32 via MQTT EMQX -->
            <div class="refresh-pill" role="status" aria-live="polite">
                <span class="indicator-ring" aria-hidden="true" style="background: <?= $esp32_online ? '#10b981' : '#ef4444'; ?>;"></span>
                <span>ESP32: <?= $esp32_online ? 'ONLINE' : 'OFFLINE (Fallback)'; ?></span>
            </div>

            <!-- Live Auto Refresh Toggle (Native Button) -->
            <button type="button" class="refresh-pill" id="refreshToggle" aria-pressed="true">
                <span class="indicator-ring" id="refreshDot" aria-hidden="true"></span>
                <span id="refreshText">Live: 3s</span>
            </button>

            <a href="#control-section" class="btn-header-action">+ Kontrol Prioritas</a>
        </div>
    </header>

    <main id="main-content" class="main-wrapper" tabindex="-1">
