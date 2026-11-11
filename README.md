# Smart Traffic Light IoT Control System

Sistem monitoring dan kendali lampu lalu lintas adaptif berbasis IoT ESP32 dengan antarmuka web modern, minimalis, dan responsif.

## Fitur Utama
- **Real-Time Monitoring**: Menampilkan sinyal lampu lalu lintas aktif (Merah, Kuning, Hijau) dengan visualisasi realistis.
- **Adaptive Sensor Timing**: Durasi lampu hijau disesuaikan secara dinamis berdasarkan volume kendaraan terdeteksi.
- **Manual Override Control**: Kemampuan berpindah ke mode manual untuk mengatur warna lampu sesuai kebutuhan darurat atau pengalihan jalan.
- **Logging Siklus & Tracking**: Pencatatan riwayat volume kendaraan dan durasi hijau terpilih.
- **Live Auto-Refresh**: Pembaruan status secara otomatis dengan opsi jeda saat pengguna sedang mengisi form.

## Struktur Direktori
```text
Smart-Traffic/
├── assets/
│   ├── css/
│   │   └── style.css            # Styling CSS & tema warna
│   └── js/
│       └── main.js              # Script timer refresh & interaksi form
├── includes/
│   ├── header.php               # Navigasi & meta tags
│   ├── hero.php                 # Tampilan hero & floating status card
│   ├── simpang_grid.php         # Kartu persimpangan & form kontrol
│   ├── log_table.php            # Tabel log riwayat siklus
│   └── footer.php               # Script loader & penutup HTML
├── api_esp32.php                # Endpoint komunikasi ESP32 (POST & GET)
├── koneksi.php                  # Koneksi database MySQL
├── update_manual.php            # Handler pembaruan mode manual
├── traffic_db.sql               # Skema database MySQL
└── index.php                    # Halaman utama
```

## Cara Menjalankan
1. Pastikan XAMPP (Apache & MySQL) sudah berjalan.
2. Buka phpMyAdmin (`http://localhost/phpmyadmin`).
3. Buat database baru bernama `traffic_db` dan impor file `traffic_db.sql`.
4. Sesuaikan konfigurasi port dan kredensial database pada `koneksi.php` jika diperlukan.
5. Akses website melalui browser: `http://localhost/Smart-Traffic/Smart-Traffic/` atau `http://localhost/Smart-Traffic/`

