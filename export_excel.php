<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/koneksi.php';
require_role('admin');

$id_seminar = (int) ($_GET['id'] ?? 0);
$format = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';

$stmt = mysqli_prepare($conn, "SELECT judul, tanggal, pemateri FROM seminars WHERE id_seminar = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $id_seminar);
mysqli_stmt_execute($stmt);
$data_sem = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$data_sem) {
    http_response_code(404);
    die('Data seminar tidak ditemukan.');
}

$stmt = mysqli_prepare($conn, "SELECT u.nim, u.nama_lengkap, u.prodi, r.kode_tiket, r.status_hadir
    FROM registrations r
    JOIN users u ON r.id_user = u.id_user
    WHERE r.id_seminar = ?
    ORDER BY u.nama_lengkap ASC");
mysqli_stmt_bind_param($stmt, 'i', $id_seminar);
mysqli_stmt_execute($stmt);
$query_peserta = mysqli_stmt_get_result($stmt);

$header = ['No.', 'NIM', 'Nama Lengkap Mahasiswa', 'Program Studi', 'Kode Tiket', 'Status Kehadiran'];
$baris = [];
$no = 1;
while ($p = mysqli_fetch_assoc($query_peserta)) {
    $baris[] = [$no, $p['nim'], $p['nama_lengkap'], $p['prodi'], $p['kode_tiket'], $p['status_hadir']];
    $no++;
}
mysqli_stmt_close($stmt);

$nama_dasar = 'Laporan_Peserta_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $data_sem['judul']) . '_' . date('Ymd', strtotime($data_sem['tanggal']));

$path = null;
try {
    if ($format === 'csv') {
        $ext = 'csv';
        $mime = 'text/csv; charset=utf-8';
        $path = csv_bangun($header, $baris);
    } else {
        $ext = 'xlsx';
        $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $path = xlsx_bangun($header, $baris);
    }

    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $nama_dasar . '.' . $ext . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($path);
} finally {
    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}
