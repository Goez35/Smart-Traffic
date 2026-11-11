<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartTraffic - Adaptive Traffic Light & IoT Control</title>
    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Custom Style Sheet -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

    <!-- Header Navigation Bar -->
    <header class="nav-header">
        <div class="nav-left">
            <a href="index.php" class="brand-logo">
                <span class="dot"></span>
                <span>SmartTraffic</span>
            </a>
            <div class="header-search">
                <i class="bi bi-search"></i>
                <input type="text" id="globalSearch" placeholder="Cari simpang, sensor, atau status..." oninput="filterIntersections(this.value)">
            </div>
        </div>

        <div class="nav-right">
            <a href="#control-section" class="nav-link-item">Monitoring</a>
            <a href="#log-section" class="nav-link-item">Riwayat Log</a>
            
            <!-- Live Auto Refresh Toggle -->
            <div class="refresh-pill" id="refreshToggle" onclick="toggleAutoRefresh()" title="Klik untuk menjeda/melanjutkan refresh otomatis">
                <span class="indicator-ring" id="refreshDot"></span>
                <span id="refreshText">Live: 3s</span>
            </div>

            <a href="#control-section" class="btn-header-action">+ Kontrol Cepat</a>
        </div>
    </header>

    <div class="main-wrapper">

