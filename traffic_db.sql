-- Database: traffic_db
CREATE DATABASE IF NOT EXISTS `traffic_db`;
USE `traffic_db`;

-- Tabel: status_persimpangan
CREATE TABLE IF NOT EXISTS `status_persimpangan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_simpang` varchar(100) NOT NULL,
  `mode` enum('auto','manual') NOT NULL DEFAULT 'auto',
  `lampu_aktif` enum('merah','kuning','hijau') NOT NULL DEFAULT 'merah',
  `durasi_hijau` int(11) NOT NULL DEFAULT 30,
  `jumlah_kendaraan` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Data Dummy status_persimpangan
INSERT INTO `status_persimpangan` (`id`, `nama_simpang`, `mode`, `lampu_aktif`, `durasi_hijau`, `jumlah_kendaraan`) VALUES
(1, 'Simpang 1 - Sudirman', 'auto', 'hijau', 45, 28),
(2, 'Simpang 2 - Thamrin', 'manual', 'merah', 30, 15)
ON DUPLICATE KEY UPDATE `id`=`id`;

-- Tabel: log_lalu_lintas
CREATE TABLE IF NOT EXISTS `log_lalu_lintas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `simpang_id` int(11) NOT NULL,
  `jumlah_kendaraan` int(11) NOT NULL,
  `durasi_terpilih` int(11) NOT NULL,
  `waktu_catat` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Data Dummy log_lalu_lintas
INSERT INTO `log_lalu_lintas` (`simpang_id`, `jumlah_kendaraan`, `durasi_terpilih`, `waktu_catat`) VALUES
(1, 28, 45, NOW() - INTERVAL 5 MINUTE),
(2, 15, 30, NOW() - INTERVAL 10 MINUTE),
(1, 35, 50, NOW() - INTERVAL 15 MINUTE);
