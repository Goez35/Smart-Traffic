<?php
//test
include 'koneksi.php';
$result = $conn->query("SELECT * FROM status_persimpangan");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Smart Traffic Light</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <meta http-equiv="refresh" content="3"> <!-- Refresh otomatis setiap 3 detik -->
</head>
<body class="bg-light">
    <div class="container mt-5">
        <h2 class="text-center mb-4">🚦 Dashboard IoT Smart Traffic Light (Auto & Manual Control)</h2>
        
        <div class="row">
            <?php while($row = $result->fetch_assoc()): 
                $isManual = ($row['mode'] == 'manual');
            ?>
            <div class="col-md-6 mb-3">
                <div class="card shadow-sm">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><?= $row['nama_simpang']; ?></h5>
                        <span class="badge bg-<?= $isManual ? 'warning text-dark' : 'success'; ?> text-uppercase">
                            <?= $row['mode']; ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <p><strong>Status Lampu Aktif:</strong> 
                            <span class="badge bg-<?= ($row['lampu_aktif']=='hijau')?'success':(($row['lampu_aktif']=='kuning')?'warning text-dark':'danger'); ?> text-uppercase">
                                <?= $row['lampu_aktif']; ?>
                            </span>
                        </p>
                        <p><strong>Durasi Hijau Terakhir:</strong> <?= $row['durasi_hijau']; ?> Detik</p>
                        <p><strong>Tracking Kendaraan (Terakhir Tercatat):</strong> <span class="text-primary fw-bold"><?= $row['jumlah_kendaraan']; ?> Unit</span></p>
                        <p class="text-muted small">Terakhir Update: <?= $row['updated_at']; ?></p>
                        
                        <hr>
                        <!-- Form Kontrol Mode & Manual -->
                        <form action="update_manual.php" method="POST">
                            <input type="hidden" name="id" value="<?= $row['id']; ?>">
                            
                            <div class="mb-2">
                                <label class="form-label fw-bold">Pilih Mode:</label>
                                <select name="mode" class="form-select" onchange="this.form.submit()">
                                    <option value="auto" <?= !$isManual ? 'selected' : ''; ?>>Otomatis (Sensor)</option>
                                    <option value="manual" <?= $isManual ? 'selected' : ''; ?>>Manual</option>
                                </select>
                            </div>

                            <div class="mb-2">
                                <label class="form-label">Kontrol Lampu (Aktif jika Manual):</label>
                                <select name="lampu_manual" class="form-select" <?= !$isManual ? 'disabled' : ''; ?>>
                                    <option value="merah" <?= ($row['lampu_aktif']=='merah')?'selected':''; ?>>Merah</option>
                                    <option value="kuning" <?= ($row['lampu_aktif']=='kuning')?'selected':''; ?>>Kuning</option>
                                    <option value="hijau" <?= ($row['lampu_aktif']=='hijau')?'selected':''; ?>>Hijau</option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-primary w-100" <?= !$isManual ? 'disabled' : ''; ?>>
                                Terapkan Perubahan Manual
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>

        <!-- Tabel Riwayat Log Tracking -->
        <div class="card mt-4 shadow-sm">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0">📋 Riwayat Tracking Kendaraan & Log Siklus</h5>
            </div>
            <div class="card-body">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>No ID Log</th>
                            <th>ID Simpang</th>
                            <th>Jumlah Kendaraan Tercatat</th>
                            <th>Durasi Terpilih</th>
                            <th>Waktu Catat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $log_result = $conn->query("SELECT * FROM log_lalu_lintas ORDER BY id DESC LIMIT 5");
                        while($log = $log_result->fetch_assoc()):
                        ?>
                        <tr>
                            <td><?= $log['id']; ?></td>
                            <td>Simpang <?= $log['simpang_id']; ?></td>
                            <td><?= $log['jumlah_kendaraan']; ?> Unit</td>
                            <td><?= $log['durasi_terpilih']; ?> Detik</td>
                            <td><?= $log['waktu_catat']; ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</body>
</html>