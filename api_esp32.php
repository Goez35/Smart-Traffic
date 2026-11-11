<?php
include 'koneksi.php';

// 1. Jika ESP32 mengirim data sensor/log (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $simpang_id       = $_POST['simpang_id'];
    $jumlah_kendaraan = $_POST['jumlah_kendaraan'];
    $durasi_terpilih  = $_POST['durasi_terpilih'];
    $lampu_aktif      = $_POST['lampu_aktif'];

    // Update state real-time
    $sql = "UPDATE status_persimpangan SET jumlah_kendaraan='$jumlah_kendaraan', durasi_hijau='$durasi_terpilih', lampu_aktif='$lampu_aktif' WHERE id='$simpang_id'";
    $conn->query($sql);

    // Masukkan ke tabel history log untuk tracking
    $sql_log = "INSERT INTO log_lalu_lintas (simpang_id, jumlah_kendaraan, durasi_terpilih) VALUES ('$simpang_id', '$jumlah_kendaraan', '$durasi_terpilih')";
    $conn->query($sql_log);

    echo json_encode(["status" => "success", "message" => "Data berhasil disimpan"]);
} 

// 2. Jika ESP32 meminta data status real-time untuk cek mode (GET)
else if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['simpang_id'])) {
    $simpang_id = $_GET['simpang_id'];
    $result = $conn->query("SELECT * FROM status_persimpangan WHERE id='$simpang_id'");
    $data = $result->fetch_assoc();
    
    header('Content-Type: application/json');
    echo json_encode($data);
}
?>