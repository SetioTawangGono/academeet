<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/koneksi.php';
require_role('admin');

$id_seminar = (int) ($_GET['id'] ?? 0);

$stmt = mysqli_prepare($conn, "SELECT judul, tanggal, pemateri FROM seminars WHERE id_seminar = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $id_seminar);
mysqli_stmt_execute($stmt);
$data_sem = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$data_sem) {
    http_response_code(404);
    die('Data seminar tidak ditemukan.');
}

$nama_file = 'Laporan_Peserta_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $data_sem['judul']) . '_' . date('Ymd', strtotime($data_sem['tanggal'])) . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nama_file . '"');
header('X-Content-Type-Options: nosniff');
header('Pragma: no-cache');
header('Expires: 0');

$stmt = mysqli_prepare($conn, "SELECT u.nim, u.nama_lengkap, u.prodi, r.kode_tiket, r.status_hadir
    FROM registrations r
    JOIN users u ON r.id_user = u.id_user
    WHERE r.id_seminar = ?
    ORDER BY u.nama_lengkap ASC");
mysqli_stmt_bind_param($stmt, 'i', $id_seminar);
mysqli_stmt_execute($stmt);
$query_peserta = mysqli_stmt_get_result($stmt);
?>
<table border="1" cellpadding="5" cellspacing="0">
    <tr>
        <th colspan="6" style="font-size: 16px; background-color: #4CAF50; color: white;">LAPORAN DATA PESERTA &amp; KEHADIRAN SEMINAR</th>
    </tr>
    <tr>
        <th colspan="6" style="text-align: left;">Acara : <?= e(excel_cell($data_sem['judul'])) ?></th>
    </tr>
    <tr>
        <th colspan="6" style="text-align: left;">Tanggal : <?= e(date('d F Y', strtotime($data_sem['tanggal']))) ?></th>
    </tr>
    <tr>
        <th colspan="6" style="text-align: left;">Pemateri : <?= e(excel_cell($data_sem['pemateri'])) ?></th>
    </tr>
    <tr>
        <th colspan="6"></th>
    </tr>
    <tr>
        <th style="background-color: #f2f2f2;">No.</th>
        <th style="background-color: #f2f2f2;">NIM</th>
        <th style="background-color: #f2f2f2;">Nama Lengkap Mahasiswa</th>
        <th style="background-color: #f2f2f2;">Program Studi</th>
        <th style="background-color: #f2f2f2;">Kode Tiket</th>
        <th style="background-color: #f2f2f2;">Status Kehadiran</th>
    </tr>

    <?php
    $no = 1;
    if (mysqli_num_rows($query_peserta) > 0) {
        while ($p = mysqli_fetch_assoc($query_peserta)) {
            $warna_status = ($p['status_hadir'] === 'Hadir') ? 'color: green; font-weight: bold;' : 'color: red;';
            echo "<tr>
                    <td style='text-align: center;'>{$no}</td>
                    <td>" . e(excel_cell($p['nim'])) . "</td>
                    <td>" . e(excel_cell($p['nama_lengkap'])) . "</td>
                    <td>" . e(excel_cell($p['prodi'])) . "</td>
                    <td style='text-align: center;'>" . e(excel_cell($p['kode_tiket'])) . "</td>
                    <td style='text-align: center; {$warna_status}'>" . e($p['status_hadir']) . "</td>
                  </tr>";
            $no++;
        }
    } else {
        echo "<tr><td colspan='6' style='text-align: center;'>Belum ada pendaftar pada seminar ini.</td></tr>";
    }
    ?>
</table>
