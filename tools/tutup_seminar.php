<?php
/**
 * Tutup seminar yang tanggalnya sudah lewat (CLI saja).
 * Menandai status='Selesai' untuk seminar Aktif yang tanggal < hari ini.
 *
 * Pakai: php tools/tutup_seminar.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../koneksi.php';

$ok = mysqli_query($conn, "UPDATE seminars SET status = 'Selesai' WHERE status = 'Aktif' AND tanggal < CURDATE()");
if (!$ok) {
    fwrite(STDERR, "Gagal menutup seminar: " . mysqli_error($conn) . "\n");
    exit(1);
}

echo "Seminar ditutup: " . mysqli_affected_rows($conn) . " baris.\n";
exit(0);
