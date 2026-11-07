<?php
// Tes fungsi DB (services.php). HANYA untuk database salinan.
// Jalankan: set ACADEMEET_DB_NAME=academeet_paketa_test lalu php tests/run_db.php
require __DIR__ . '/../bootstrap.php';

$dbName = getenv('ACADEMEET_DB_NAME') ?: 'academeet';
if (!str_ends_with($dbName, '_test')) {
    fwrite(STDERR, "DITOLAK: tes ini hanya boleh dijalankan pada database *_test (sekarang: {$dbName}).\n");
    exit(1);
}

require __DIR__ . '/../koneksi.php';
require __DIR__ . '/../services.php';

$failed = 0;
$check = static function (string $name, bool $ok) use (&$failed): void {
    if (!$ok) {
        $failed++;
        echo "FAIL: {$name}\n";
    } else {
        echo "ok:   {$name}\n";
    }
};

$hariIni = date('Y-m-d');
$besok = date('Y-m-d', strtotime('+1 day'));
$kemarin = date('Y-m-d', strtotime('-1 day'));

// --- Siapkan data uji (id unik pakai timestamp agar aman berulang) ---
$tag = (string) time();
$idUser = 0;
$idPemateri = 0;
$idSeminar = 0;

function ins_user(mysqli $conn, string $nama, string $email): int
{
    $hash = password_hash('x', PASSWORD_DEFAULT);
    $role = 'mahasiswa';
    $stmt = mysqli_prepare($conn, "INSERT INTO users (nama_lengkap, email, password, role) VALUES (?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'ssss', $nama, $email, $hash, $role);
    mysqli_stmt_execute($stmt);
    $id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return (int) $id;
}

function ins_seminar(mysqli $conn, string $judul, string $tanggal, int $kuota, string $status = 'Aktif'): int
{
    $pemateri = 'Tester';
    $stmt = mysqli_prepare($conn, "INSERT INTO seminars (judul, pemateri, tanggal, kuota, kuota_total, status) VALUES (?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'sssiss', $judul, $pemateri, $tanggal, $kuota, $kuota, $status);
    mysqli_stmt_execute($stmt);
    $id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return (int) $id;
}

function ins_pemateri(mysqli $conn, string $nama): int
{
    $stmt = mysqli_prepare($conn, "INSERT INTO pemateri (nama) VALUES (?)");
    mysqli_stmt_bind_param($stmt, 's', $nama);
    mysqli_stmt_execute($stmt);
    $id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return (int) $id;
}

function ins_reg(mysqli $conn, int $idUser, int $idSeminar, string $hadir, string $kode): int
{
    $stmt = mysqli_prepare($conn, "INSERT INTO registrations (id_user, id_seminar, kode_tiket, status_hadir) VALUES (?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'iiss', $idUser, $idSeminar, $kode, $hadir);
    mysqli_stmt_execute($stmt);
    $id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return (int) $id;
}

try {
    // ===== batalkan_pendaftaran =====
    $idUser = ins_user($conn, 'Uji ' . $tag, "uji{$tag}@test.local");
    $idUserLain = ins_user($conn, 'Uji Lain ' . $tag, "uji{$tag}b@test.local");
    $idUserKetiga = ins_user($conn, 'Uji Ketiga ' . $tag, "uji{$tag}c@test.local");
    $idSeminar = ins_seminar($conn, 'Seminar Batal ' . $tag, $besok, 5);
    $idReg = ins_reg($conn, $idUser, $idSeminar, 'Belum Hadir', 'T-' . $tag . 'A');
    // Simulasikan kuota terpakai 1 (insert lewat layanan tidak diuji di sini).
    mysqli_query($conn, "UPDATE seminars SET kuota = kuota_total - 1 WHERE id_seminar = $idSeminar");

    // Bukan pemilik tiket
    [$t, ] = batalkan_pendaftaran($conn, $idReg, $idUserLain);
    $check('batal ditolak bila bukan pemilik tiket', $t === 'danger');

    // Tiket Hadir
    $idRegHadir = ins_reg($conn, $idUserLain, $idSeminar, 'Hadir', 'T-' . $tag . 'B');
    [$t, ] = batalkan_pendaftaran($conn, $idRegHadir, $idUserLain);
    $check('batal ditolak untuk tiket Hadir', $t === 'danger');

    // H atau lewat
    $idSemHariIni = ins_seminar($conn, 'Seminar Hari Ini ' . $tag, $hariIni, 5);
    $idRegHari = ins_reg($conn, $idUser, $idSemHariIni, 'Belum Hadir', 'T-' . $tag . 'C');
    [$t, ] = batalkan_pendaftaran($conn, $idRegHari, $idUser);
    $check('batal ditolak untuk H', $t === 'danger');

    $idSemLewat = ins_seminar($conn, 'Seminar Lewat ' . $tag, $kemarin, 5);
    $idRegLewat = ins_reg($conn, $idUser, $idSemLewat, 'Belum Hadir', 'T-' . $tag . 'D');
    [$t, ] = batalkan_pendaftaran($conn, $idRegLewat, $idUser);
    $check('batal ditolak untuk tanggal lewat', $t === 'danger');

    // Sukses + kuota kembali
    $kuota_before = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT kuota FROM seminars WHERE id_seminar = $idSeminar"))['kuota'];
    [$t, ] = batalkan_pendaftaran($conn, $idReg, $idUser);
    $kuota_after = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT kuota FROM seminars WHERE id_seminar = $idSeminar"))['kuota'];
    $check('batal sukses', $t === 'success');
    $check('batal mengembalikan kuota', $kuota_after === $kuota_before + 1);

    // Tidak melebihi kuota_total
    $stmt = mysqli_prepare($conn, "UPDATE seminars SET kuota = kuota_total WHERE id_seminar = ?");
    mysqli_stmt_bind_param($stmt, 'i', $idSeminar);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $idReg2 = ins_reg($conn, $idUserKetiga, $idSeminar, 'Belum Hadir', 'T-' . $tag . 'E');
    [$t, ] = batalkan_pendaftaran($conn, $idReg2, $idUserKetiga);
    $kuota_cap = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT kuota FROM seminars WHERE id_seminar = $idSeminar"))['kuota'];
    $total_cap = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT kuota_total FROM seminars WHERE id_seminar = $idSeminar"))['kuota_total'];
    $check('kuota tidak melebihi kuota_total', $kuota_cap === $total_cap);

    // ===== ubah_seminar =====
    $idPemateri = ins_pemateri($conn, 'Pemateri Uji ' . $tag);
    $idSemEdit = ins_seminar($conn, 'Seminar Edit ' . $tag, $besok, 5);
    ins_reg($conn, $idUser, $idSemEdit, 'Belum Hadir', 'T-' . $tag . 'F');
    ins_reg($conn, $idUserLain, $idSemEdit, 'Belum Hadir', 'T-' . $tag . 'G');

    // Tolak total < jumlah pendaftar
    [$t, ] = ubah_seminar($conn, $idSemEdit, ['judul' => 'X', 'id_pemateri' => $idPemateri, 'tanggal' => $besok, 'kuota_total' => 1]);
    $check('edit menolak total < jumlah pendaftar', $t === 'danger');

    // Hitung ulang sisa kuota (2 pendaftar, total 10 -> kuota 8)
    [$t, ] = ubah_seminar($conn, $idSemEdit, ['judul' => 'Seminar Edit Baru ' . $tag, 'id_pemateri' => $idPemateri, 'tanggal' => date('Y-m-d', strtotime('+2 day')), 'kuota_total' => 10]);
    $edit_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT kuota, kuota_total, judul FROM seminars WHERE id_seminar = $idSemEdit"));
    $check('edit sukses', $t === 'success');
    $check('edit menghitung ulang sisa kuota', (int) $edit_row['kuota'] === 8 && (int) $edit_row['kuota_total'] === 10);

    // Tolak seminar Selesai
    $idSemSelesai = ins_seminar($conn, 'Seminar Selesai ' . $tag, $besok, 5, 'Selesai');
    [$t, ] = ubah_seminar($conn, $idSemSelesai, ['judul' => 'Y', 'id_pemateri' => $idPemateri, 'tanggal' => $besok, 'kuota_total' => 5]);
    $check('edit menolak seminar Selesai', $t === 'danger');
} catch (Throwable $ex) {
    $failed++;
    echo "FAIL: exception " . $ex->getMessage() . "\n";
} finally {
    // Bersihkan: registrations & seminars uji (FK cascade dari user juga aman).
    mysqli_query($conn, "DELETE r FROM registrations r JOIN users u ON r.id_user = u.id_user WHERE u.email LIKE 'uji%test.local'");
    mysqli_query($conn, "DELETE FROM seminars WHERE judul LIKE '% " . $tag . "'");
    mysqli_query($conn, "DELETE FROM users WHERE email LIKE 'uji%test.local'");
    mysqli_query($conn, "DELETE FROM pemateri WHERE nama LIKE 'Pemateri Uji %'");
}

echo $failed === 0 ? "\nSemua tes DB lulus.\n" : "\n{$failed} tes DB gagal.\n";
exit($failed === 0 ? 0 : 1);
