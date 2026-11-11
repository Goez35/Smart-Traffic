<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "traffic_db";
$port = 3307;

$conn = new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}
?>