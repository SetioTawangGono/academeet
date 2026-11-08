<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/koneksi.php';
require __DIR__ . '/services.php';
require_role('mahasiswa');

$id_user = (int) $_SESSION['id_user'];
$id_registrasi = (int) ($_GET['id'] ?? 0);

// Owner-only: registrasi bukan milik user → 404 (tidak bocorkan keberadaan).
$acara = ambil_data_ics($conn, $id_user, $id_registrasi);
if (!$acara) {
    http_response_code(404);
    die('Data seminar tidak ditemukan.');
}

$ics = buat_ics($acara, gmdate('Ymd\THis\Z'));
$nama_file = slug_nama_file((string) ($acara['judul'] ?? 'seminar')) . '.ics';

header('Content-Type: text/calendar; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nama_file . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Pragma: no-cache');
header('Expires: 0');

echo $ics;
