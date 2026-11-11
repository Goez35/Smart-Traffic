<?php
include 'koneksi.php';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id    = $_POST['id'];
    $mode  = $_POST['mode'];
    $lampu = $_POST['lampu_manual'];

    $conn->query("UPDATE status_persimpangan SET mode='$mode', lampu_aktif='$lampu' WHERE id='$id'");
    header("Location: index.php");
}
?>