<?php
/**
 * Koneksi Database MySQL
 * Membaca kredensial dari `config.php` (atau nilai default pengembangan jika belum ada).
 */

$config_file = __DIR__ . '/config.php';
if (file_exists($config_file)) {
    require_once $config_file;
} else {
    require_once __DIR__ . '/config.example.php';
}

$host = defined('DB_HOST') ? DB_HOST : 'localhost';
$user = defined('DB_USER') ? DB_USER : 'root';
$pass = defined('DB_PASS') ? DB_PASS : '';
$db   = defined('DB_NAME') ? DB_NAME : 'traffic_db';
$port = defined('DB_PORT') ? DB_PORT : 3307;

$conn = new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

require_once __DIR__ . '/includes/db_init.php';
pastikanSkemaDatabase($conn);
?>
