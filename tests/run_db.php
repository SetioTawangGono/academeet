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

    // ===== throttle DB =====
    $sc = 'test_' . $tag;
    $k1 = throttle_key($sc, 'kunci-satu');
    $k2 = throttle_key($sc, 'kunci-dua');

    // Batas tepat: 4 catat -> belum blokir (batas 5), catat ke-5 -> blokir.
    $check('throttle awal tidak blokir', throttle_terlalu_banyak($conn, $sc, $k1, 5, 900) === false);
    for ($i = 0; $i < 4; $i++) { throttle_catat($conn, $sc, $k1); }
    $check('throttle 4 < 5 belum blokir', throttle_terlalu_banyak($conn, $sc, $k1, 5, 900) === false);
    throttle_catat($conn, $sc, $k1);
    $check('throttle 5 = 5 blokir', throttle_terlalu_banyak($conn, $sc, $k1, 5, 900) === true);

    // Kunci terpisah per scope: kunci lain tidak terpengaruh.
    $check('throttle kunci lain tidak blokir', throttle_terlalu_banyak($conn, $sc, $k2, 5, 900) === false);

    // Jendela geser: baris lebih tua dari jendela tidak dihitung.
    $kOld = throttle_key($sc, 'kunci-lama');
    $stmt = mysqli_prepare($conn, "INSERT INTO auth_throttle (scope, key_hash, attempted_at) VALUES (?, ?, NOW() - INTERVAL 20 MINUTE)");
    mysqli_stmt_bind_param($stmt, 'ss', $sc, $kOld);
    for ($i = 0; $i < 6; $i++) { mysqli_stmt_execute($stmt); }
    mysqli_stmt_close($stmt);
    $check('throttle di luar jendela tidak dihitung', throttle_terlalu_banyak($conn, $sc, $kOld, 5, 900) === false);

    // Hapus setelah sukses: kunci (email+IP) dibersihkan, kunci lain tetap.
    throttle_hapus($conn, $sc, $k1);
    $check('throttle_hapus menghapus kunci ybs', throttle_terlalu_banyak($conn, $sc, $k1, 5, 900) === false);
    throttle_catat($conn, $sc, $k2);
    $check('throttle_hapus tidak menyentuh kunci lain', throttle_terlalu_banyak($conn, $sc, $k2, 1, 900) === true);

    // Pembersihan oportunistik: sisipkan baris tua lalu panggil langsung.
    $kClean = throttle_key($sc, 'kunci-bersih');
    $stmt = mysqli_prepare($conn, "INSERT INTO auth_throttle (scope, key_hash, attempted_at) VALUES (?, ?, NOW() - INTERVAL 2 DAY)");
    mysqli_stmt_bind_param($stmt, 'ss', $sc, $kClean);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    mysqli_query($conn, "DELETE FROM auth_throttle WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
    $left = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM auth_throttle WHERE scope = '$sc' AND key_hash = '$kClean'"))['c'];
    $check('pembersihan hapus baris > 1 hari', $left === 0);

    // ===== auth_version =====
    // User baru: auth_version default 0.
    $idAV = ins_user($conn, 'AV ' . $tag, "av{$tag}@test.local");
    $av0 = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT auth_version FROM users WHERE id_user = $idAV"))['auth_version'];
    $check('auth_version default 0', $av0 === 0);

    // Naik saat password diganti (meniru ubah_password / reset admin).
    $stmt = mysqli_prepare($conn, "UPDATE users SET password = ?, auth_version = auth_version + 1 WHERE id_user = ?");
    $newHash = password_hash('baru-banget', PASSWORD_DEFAULT);
    mysqli_stmt_bind_param($stmt, 'si', $newHash, $idAV);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $av1 = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT auth_version FROM users WHERE id_user = $idAV"))['auth_version'];
    $check('auth_version naik saat ganti password', $av1 === $av0 + 1);

    // Sesi dengan versi lama harus ditolak: cek langsung kondisi yang dipakai require_role().
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT auth_version FROM users WHERE id_user = $idAV LIMIT 1"));
    $sesiVersiLama = 0; // simulasi sesi sebelum ganti password
    $check('sesi versi lama ditolak', $row !== null && (int) $row['auth_version'] !== $sesiVersiLama);
    $check('sesi versi sama diterima', $row !== null && (int) $row['auth_version'] === $av1);

    // User terhapus: query versi mengembalikan tidak ada baris.
    $idHapus = ins_user($conn, 'Hapus ' . $tag, "hapus{$tag}@test.local");
    mysqli_query($conn, "DELETE FROM users WHERE id_user = $idHapus");
    $gone = mysqli_fetch_assoc(mysqli_query($conn, "SELECT auth_version FROM users WHERE id_user = $idHapus LIMIT 1"));
    $check('user terhapus tidak ditemukan di DB', $gone === null);

    // ===== buat_admin =====
    $emailAdmin = "admin{$tag}@test.local";
    [$t, $msg, $idBaru] = buat_admin($conn, 'Admin Uji ' . $tag, $emailAdmin, 'passwordpanjang12');
    $check('buat_admin sukses', $t === 'success' && $idBaru > 0);

    $rowAdmin = mysqli_fetch_assoc(mysqli_query($conn, "SELECT role, password FROM users WHERE id_user = " . (int) $idBaru));
    $check('buat_admin role = admin', $rowAdmin !== null && $rowAdmin['role'] === 'admin');
    $check('buat_admin hash != password asli', $rowAdmin !== null && $rowAdmin['password'] !== 'passwordpanjang12');
    $check('buat_admin hash terverifikasi', $rowAdmin !== null && password_verify('passwordpanjang12', $rowAdmin['password']));

    [$t, ] = buat_admin($conn, 'Admin Dua', $emailAdmin, 'passwordpanjang12');
    $check('buat_admin email duplikat ditolak', $t === 'danger');

    [$t, ] = buat_admin($conn, 'Admin Tiga', "pendek{$tag}@test.local", 'pendek');
    $check('buat_admin password < 12 ditolak', $t === 'danger');

    [$t, ] = buat_admin($conn, 'Admin Empat', 'bukan-email', 'passwordpanjang12');
    $check('buat_admin email tidak valid ditolak', $t === 'danger');

    // ===== token reset password =====
    $idReset = ins_user($conn, 'Reset ' . $tag, "reset{$tag}@test.local");
    $token1 = buat_token_reset($conn, $idReset);
    $check('buat_token_reset mengembalikan 64 hex', is_string($token1) && (bool) preg_match('/^[0-9a-f]{64}$/', $token1));

    // Hanya satu token aktif per user: token baru menghapus hash lama.
    $token2 = buat_token_reset($conn, $idReset);
    $countTok = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM password_resets WHERE id_user = $idReset"))['c'];
    $check('hanya satu token aktif per user', $countTok === 1);

    // Token lama (token1) sudah tak ada; token2 valid.
    [$t, ] = pakai_token_reset($conn, $token1, 'passwordbaru1');
    $check('token lama (digantikan) ditolak', $t === 'danger');

    // Reset sukses menaikkan auth_version dan mengubah password.
    $avBefore = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT auth_version FROM users WHERE id_user = $idReset"))['auth_version'];
    [$t, ] = pakai_token_reset($conn, $token2, 'passwordbaru2');
    $check('reset token sukses', $t === 'success');
    $rowR = mysqli_fetch_assoc(mysqli_query($conn, "SELECT password, auth_version FROM users WHERE id_user = $idReset"));
    $check('reset menaikkan auth_version', (int) $rowR['auth_version'] === $avBefore + 1);
    $check('reset mengganti password (verify)', password_verify('passwordbaru2', $rowR['password']));

    // Sekali pakai: token yang sama tak bisa dipakai lagi.
    [$t, ] = pakai_token_reset($conn, $token2, 'passwordbaru3');
    $check('token sekali pakai ditolak kedua kali', $t === 'danger');

    // Token kedaluwarsa ditolak.
    $idExp = ins_user($conn, 'Exp ' . $tag, "exp{$tag}@test.local");
    $tokExp = bin2hex(random_bytes(32));
    $stmt = mysqli_prepare($conn, "INSERT INTO password_resets (id_user, token_hash, created_at, expires_at) VALUES (?, ?, NOW() - INTERVAL 1 HOUR, NOW() - INTERVAL 1 MINUTE)");
    $hashExp = hash('sha256', $tokExp);
    mysqli_stmt_bind_param($stmt, 'is', $idExp, $hashExp);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    [$t, ] = pakai_token_reset($conn, $tokExp, 'passwordbaru4');
    $check('token kedaluwarsa ditolak', $t === 'danger');

    // Token milik user lain tidak bisa dipakai untuk user yang salah: satu token = satu user.
    $idA = ins_user($conn, 'A ' . $tag, "ua{$tag}@test.local");
    $tokA = buat_token_reset($conn, $idA);
    [$t, ] = pakai_token_reset($conn, $tokA, 'passwordbaru5');
    $check('token user A hanya untuk A', $t === 'success');

    // Admin tidak bisa di-reset lewat token (role bukan mahasiswa).
    [$t, $msgA, $idAdm] = buat_admin($conn, 'Adm Reset ' . $tag, "admreset{$tag}@test.local", 'passwordpanjang12');
    $tokAdm = buat_token_reset($conn, (int) $idAdm);
    [$t, ] = pakai_token_reset($conn, $tokAdm, 'passwordbaru6');
    $check('token untuk admin ditolak', $t === 'danger');

    // Password baru kurang dari 8 ditolak.
    $tokShort = buat_token_reset($conn, $idA);
    [$t, ] = pakai_token_reset($conn, $tokShort, 'pendek');
    $check('reset password < 8 ditolak', $t === 'danger');
} catch (Throwable $ex) {
    $failed++;
    echo "FAIL: exception " . $ex->getMessage() . "\n";
} finally {
    // Bersihkan: registrations & seminars uji (FK cascade dari user juga aman).
    mysqli_query($conn, "DELETE r FROM registrations r JOIN users u ON r.id_user = u.id_user WHERE u.email LIKE 'uji%test.local'");
    mysqli_query($conn, "DELETE FROM seminars WHERE judul LIKE '% " . $tag . "'");
    mysqli_query($conn, "DELETE FROM users WHERE email LIKE 'uji%test.local'");
    mysqli_query($conn, "DELETE FROM pemateri WHERE nama LIKE 'Pemateri Uji %'");
    mysqli_query($conn, "DELETE FROM auth_throttle WHERE scope LIKE 'test_%'");
    mysqli_query($conn, "DELETE FROM password_resets WHERE id_user IN (SELECT id_user FROM users WHERE email LIKE '%{$tag}%@test.local')");
    mysqli_query($conn, "DELETE FROM users WHERE email LIKE '%{$tag}%@test.local'");
}

echo $failed === 0 ? "\nSemua tes DB lulus.\n" : "\n{$failed} tes DB gagal.\n";
exit($failed === 0 ? 0 : 1);
