<?php
include 'koneksi.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id    = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $mode  = isset($_POST['mode']) ? $conn->real_escape_string($_POST['mode']) : 'auto';
    
    if ($id > 0) {
        if (isset($_POST['lampu_manual']) && !empty($_POST['lampu_manual'])) {
            $lampu = $conn->real_escape_string($_POST['lampu_manual']);
            $conn->query("UPDATE status_persimpangan SET mode='$mode', lampu_aktif='$lampu' WHERE id='$id'");
        } else {
            $conn->query("UPDATE status_persimpangan SET mode='$mode' WHERE id='$id'");
        }
    }
    
    header("Location: index.php");
    exit;
}
?>
