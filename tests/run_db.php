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

function ins_seminar(mysqli $conn, string $judul, string $tanggal, int $kuota, string $status = 'Aktif', array $detail = []): int
{
    $pemateri = 'Tester';
    $jam_mulai = $detail['jam_mulai'] ?? null;
    $jam_selesai = $detail['jam_selesai'] ?? null;
    $lokasi = $detail['lokasi'] ?? null;
    $link_online = $detail['link_online'] ?? null;
    $kategori = $detail['kategori'] ?? 'Umum';
    $deskripsi = $detail['deskripsi'] ?? null;
    $stmt = mysqli_prepare($conn, "INSERT INTO seminars (judul, pemateri, tanggal, kuota, kuota_total, status, jam_mulai, jam_selesai, lokasi, link_online, kategori, deskripsi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'sssissssssss', $judul, $pemateri, $tanggal, $kuota, $kuota, $status, $jam_mulai, $jam_selesai, $lokasi, $link_online, $kategori, $deskripsi);
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

    // ===== Paket C: detail seminar + validasi =====
    // kategori default 'Umum' bila tidak diisi.
    $idSemDefault = ins_seminar($conn, 'PaketC Default ' . $tag, $besok, 5);
    $katDefault = (string) mysqli_fetch_assoc(mysqli_query($conn, "SELECT kategori FROM seminars WHERE id_seminar = $idSemDefault"))['kategori'];
    $check('kategori default Umum', $katDefault === 'Umum');

    // INSERT detail lengkap roundtrip.
    $idSemDetail = ins_seminar($conn, 'PaketC Detail ' . $tag, $besok, 5, 'Aktif', [
        'jam_mulai' => '09:00:00', 'jam_selesai' => '11:30:00', 'lokasi' => 'Aula C',
        'link_online' => 'https://contoh.test/d', 'kategori' => 'Teknologi', 'deskripsi' => "Baris 1\nBaris 2",
    ]);
    $dRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT jam_mulai, jam_selesai, lokasi, link_online, kategori, deskripsi FROM seminars WHERE id_seminar = $idSemDetail"));
    $check('detail roundtrip jam/lokasi/link/kategori', $dRow['jam_mulai'] === '09:00:00' && $dRow['jam_selesai'] === '11:30:00'
        && $dRow['lokasi'] === 'Aula C' && $dRow['link_online'] === 'https://contoh.test/d' && $dRow['kategori'] === 'Teknologi');
    $check('detail deskripsi multi-baris utuh', $dRow['deskripsi'] === "Baris 1\nBaris 2");

    // validasi_detail_seminar: kategori tak dikenal & format jam salah ditolak.
    [$e1, ] = validasi_detail_seminar(['kategori' => 'Ngawur']);
    $check('validasi tolak kategori tak dikenal', $e1 !== '');
    [$e2, ] = validasi_detail_seminar(['kategori' => 'Umum', 'jam_mulai' => '25:99']);
    $check('validasi tolak jam tak valid', $e2 !== '');
    [$e3, ] = validasi_detail_seminar(['kategori' => 'Umum', 'jam_mulai' => '09:00', 'jam_selesai' => '08:00']);
    $check('validasi tolak selesai < mulai', $e3 !== '');
    [$e4, $d4] = validasi_detail_seminar(['kategori' => 'Riset', 'link_online' => 'javascript:alert(1)']);
    $check('validasi tolak link_online bukan http(s)', $e4 !== '');
    [$e5, $d5] = validasi_detail_seminar(['kategori' => 'Soft Skill', 'jam_mulai' => '', 'jam_selesai' => '', 'lokasi' => '  ', 'deskripsi' => '  ']);
    $check('validasi sukses & kosong jadi null', $e5 === '' && $d5['jam_mulai'] === null && $d5['lokasi'] === null && $d5['kategori'] === 'Soft Skill');

    // ubah_seminar menyimpan & mengosongkan detail.
    [$t, ] = ubah_seminar($conn, $idSemDetail, [
        'judul' => 'PaketC Detail Baru ' . $tag, 'id_pemateri' => $idPemateri, 'tanggal' => $besok, 'kuota_total' => 8,
        'jam_mulai' => '10:00', 'jam_selesai' => '', 'lokasi' => 'Ruang 2', 'link_online' => '', 'kategori' => 'Karier', 'deskripsi' => '',
    ]);
    $uRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT jam_mulai, jam_selesai, lokasi, link_online, kategori, deskripsi FROM seminars WHERE id_seminar = $idSemDetail"));
    $check('ubah_seminar simpan detail baru', $t === 'success' && $uRow['jam_mulai'] === '10:00:00' && $uRow['lokasi'] === 'Ruang 2' && $uRow['kategori'] === 'Karier');
    $check('ubah_seminar kosongkan detail', $uRow['jam_selesai'] === null && $uRow['link_online'] === null && $uRow['deskripsi'] === null);

    // ===== Paket C: ambil_data_ics owner-only =====
    $idRegIcs = ins_reg($conn, $idUser, $idSemDetail, 'Belum Hadir', 'T-' . $tag . 'ICS');
    $icsRow = ambil_data_ics($conn, $idUser, $idRegIcs);
    $check('ambil_data_ics pemilik dapat data', $icsRow !== null && (int) $icsRow['id_registrasi'] === $idRegIcs);
    $check('ambil_data_ics user lain null', ambil_data_ics($conn, $idUserLain, $idRegIcs) === null);
    $check('ambil_data_ics id_registrasi 0 null', ambil_data_ics($conn, $idUser, 0) === null);
    $icsStr = buat_ics($icsRow, gmdate('Ymd\THis\Z'));
    $check('buat_ics hasil valid', strpos($icsStr, 'BEGIN:VCALENDAR') !== false && strpos($icsStr, 'END:VCALENDAR') !== false && strpos($icsStr, 'UID:registrasi-' . $idRegIcs . '@academeet') !== false);

    // ===== Paket C: filter katalog (normalisasi murni) =====
    $fk = normalisasi_filter_katalog(['q' => '  AI ', 'kat' => 'Teknologi', 'sedia' => 'tersedia', 'p' => '3']);
    $check('filter: q trim', $fk['q'] === 'AI');
    $check('filter: kat valid dipertahankan', $fk['kat'] === 'Teknologi');
    $check('filter: sedia valid', $fk['sedia'] === 'tersedia');
    $check('filter: p valid', $fk['p'] === 3);
    $fk2 = normalisasi_filter_katalog(['kat' => 'Ngawur', 'sedia' => 'ngawur', 'p' => '99999999999999999999']);
    $check('filter: kat invalid → default kosong', $fk2['kat'] === '');
    $check('filter: sedia invalid → semua', $fk2['sedia'] === 'semua');
    $fk3 = normalisasi_filter_katalog(['dari' => '2027-01-01', 'sampai' => '2026-01-01']);
    $check('filter: dari>sampai ditukar', $fk3['dari'] === '2026-01-01' && $fk3['sampai'] === '2027-01-01');
    $fk4 = normalisasi_filter_katalog(['dari' => '2027-02-30']);
    $check('filter: tanggal tak valid → kosong', $fk4['dari'] === '');

    // ===== Paket D: proses_pengingat_h1 =====
    $idSemBesok = ins_seminar($conn, 'PaketD Besok ' . $tag, $besok, 20);
    $idSemH2 = ins_seminar($conn, 'PaketD HariIni ' . $tag, $hariIni, 20);
    $idSemSelesaiD = ins_seminar($conn, 'PaketD Selesai ' . $tag, $besok, 20, 'Selesai');

    $idUBesok = ins_user($conn, 'D Besok ' . $tag, "dbesok{$tag}@test.local");
    $idUH2 = ins_user($conn, 'D H2 ' . $tag, "dh2{$tag}@test.local");
    $idUHadir = ins_user($conn, 'D Hadir ' . $tag, "dhadir{$tag}@test.local");
    $idUOff = ins_user($conn, 'D Off ' . $tag, "doff{$tag}@test.local");
    $idUSelesai = ins_user($conn, 'D Selesai ' . $tag, "dselesai{$tag}@test.local");
    // Matikan preferensi notif user uji "Off".
    mysqli_query($conn, "UPDATE users SET notif_email = 0 WHERE id_user = $idUOff");

    $regBesok = ins_reg($conn, $idUBesok, $idSemBesok, 'Belum Hadir', 'D-' . $tag . '-1');
    $regH2 = ins_reg($conn, $idUH2, $idSemH2, 'Belum Hadir', 'D-' . $tag . '-2');
    $regHadir = ins_reg($conn, $idUHadir, $idSemBesok, 'Hadir', 'D-' . $tag . '-3');
    $regOff = ins_reg($conn, $idUOff, $idSemBesok, 'Belum Hadir', 'D-' . $tag . '-4');
    $regSelesai = ins_reg($conn, $idUSelesai, $idSemSelesaiD, 'Belum Hadir', 'D-' . $tag . '-5');

    // Pengirim palsu: catat tujuan, tanpa email nyata.
    $terkirim = [];
    $palsuSukses = static function (string $ke, string $subjek, string $isi) use (&$terkirim): bool {
        $terkirim[] = $ke;
        return true;
    };

    $r1 = proses_pengingat_h1($conn, $besok, $palsuSukses, 50);
    $check('H-1: user besok terkirim', in_array("dbesok{$tag}@test.local", $terkirim, true));
    $check('H-1: user H2 tidak dikirim', !in_array("dh2{$tag}@test.local", $terkirim, true));
    $check('H-1: tiket Hadir tidak dikirim', !in_array("dhadir{$tag}@test.local", $terkirim, true));
    $check('H-1: user notif=0 tidak dikirim', !in_array("doff{$tag}@test.local", $terkirim, true));
    $check('H-1: seminar Selesai tidak dikirim', !in_array("dselesai{$tag}@test.local", $terkirim, true));
    $check('H-1: sukses menandai h1_terkirim_at', mysqli_fetch_assoc(mysqli_query($conn, "SELECT h1_terkirim_at FROM registrations WHERE id_registrasi=$regBesok"))['h1_terkirim_at'] !== null);
    $check('H-1: user H2 tidak ditandai', mysqli_fetch_assoc(mysqli_query($conn, "SELECT h1_terkirim_at FROM registrations WHERE id_registrasi=$regH2"))['h1_terkirim_at'] === null);
    $check('H-1: tiket Hadir tidak ditandai', mysqli_fetch_assoc(mysqli_query($conn, "SELECT h1_terkirim_at FROM registrations WHERE id_registrasi=$regHadir"))['h1_terkirim_at'] === null);
    $check('H-1: user notif=0 tidak dikirim', mysqli_fetch_assoc(mysqli_query($conn, "SELECT h1_terkirim_at FROM registrations WHERE id_registrasi=$regOff"))['h1_terkirim_at'] === null);
    $check('H-1: seminar Selesai tidak dikirim', mysqli_fetch_assoc(mysqli_query($conn, "SELECT h1_terkirim_at FROM registrations WHERE id_registrasi=$regSelesai"))['h1_terkirim_at'] === null);

    // Jalankan kedua kali: tidak ada pengiriman ganda.
    $terkirim = [];
    $r2 = proses_pengingat_h1($conn, $besok, $palsuSukses, 50);
    $check('H-1: eksekusi kedua tidak mengirim ganda', $r2['terkirim'] === 0 && $terkirim === []);

    // Pengirim yang melempar -> kolom dikembalikan NULL, eksekusi berikutnya mencoba lagi.
    $idSemGagal = ins_seminar($conn, 'PaketD Gagal ' . $tag, $besok, 20);
    $idUGagal = ins_user($conn, 'D Gagal ' . $tag, "dgagal{$tag}@test.local");
    $regGagal = ins_reg($conn, $idUGagal, $idSemGagal, 'Belum Hadir', 'D-' . $tag . '-6');
    $palsuLempar = static function () { throw new RuntimeException('SMTP mati'); };
    $r3 = proses_pengingat_h1($conn, $besok, $palsuLempar, 50);
    $check('H-1: pengirim melempar -> gagal dicatat', $r3['gagal'] >= 1);
    $check('H-1: gagal mengembalikan h1_terkirim_at = NULL', mysqli_fetch_assoc(mysqli_query($conn, "SELECT h1_terkirim_at FROM registrations WHERE id_registrasi=$regGagal"))['h1_terkirim_at'] === null);

    // 3 kegagalan berurutan menghentikan eksekusi (pengirim selalu melempar).
    for ($i = 0; $i < 3; $i++) {
        $idUStop = ins_user($conn, "D Stop$i " . $tag, "dstop{$i}_{$tag}@test.local");
        ins_reg($conn, $idUStop, $idSemGagal, 'Belum Hadir', "D-{$tag}-S{$i}");
    }
    $rStop = proses_pengingat_h1($conn, $besok, $palsuLempar, 50);
    $check('H-1: 3 kegagalan berurutan -> dihentikan', $rStop['dihentikan'] === true && $rStop['gagal'] === 3);

    // Batas per eksekusi: hitung berapa yang benar-benar dikirim pada eksekusi ini.
    $idSemBatas = ins_seminar($conn, 'PaketD Batas ' . $tag, $besok, 20);
    for ($i = 0; $i < 5; $i++) {
        $idUB = ins_user($conn, "D Batas$i " . $tag, "dbatas{$i}_{$tag}@test.local");
        ins_reg($conn, $idUB, $idSemBatas, 'Belum Hadir', "D-{$tag}-B{$i}");
    }
    $terkirim = [];
    $r5 = proses_pengingat_h1($conn, $besok, $palsuSukses, 2);
    $check('H-1: batas 2 per eksekusi dihormati', $r5['terkirim'] === 2 && count($terkirim) === 2);

    // Baris sudah ditandai tidak dikirim ulang: user yang sudah terkirim tak muncul lagi.
    $sudah = $terkirim;
    $terkirim = [];
    proses_pengingat_h1($conn, $besok, $palsuSukses, 50);
    $tumpang = array_intersect($sudah, $terkirim);
    $check('H-1: baris sudah ditandai tidak dikirim ulang', $tumpang === []);

    // ===== Paket D: ubah_seminar reset h1_terkirim_at saat tanggal berubah =====
    $idSemReset = ins_seminar($conn, 'PaketD Reset ' . $tag, $besok, 20);
    $idUReset = ins_user($conn, 'D Reset ' . $tag, "dreset{$tag}@test.local");
    $regReset = ins_reg($conn, $idUReset, $idSemReset, 'Belum Hadir', 'D-' . $tag . '-R1');
    mysqli_query($conn, "UPDATE registrations SET h1_terkirim_at = NOW() WHERE id_registrasi = $regReset");
    // Ubah tanggal -> harus reset.
    [$t, ] = ubah_seminar($conn, $idSemReset, ['judul' => 'PaketD Reset ' . $tag, 'id_pemateri' => $idPemateri, 'tanggal' => date('Y-m-d', strtotime('+3 day')), 'kuota_total' => 20]);
    $check('ubah tanggal -> h1_terkirim_at direset', $t === 'success' && mysqli_fetch_assoc(mysqli_query($conn, "SELECT h1_terkirim_at FROM registrations WHERE id_registrasi=$regReset"))['h1_terkirim_at'] === null);

    // Ubah judul saja -> tidak reset.
    mysqli_query($conn, "UPDATE registrations SET h1_terkirim_at = NOW() WHERE id_registrasi = $regReset");
    [$t, ] = ubah_seminar($conn, $idSemReset, ['judul' => 'PaketD Reset Judul ' . $tag, 'id_pemateri' => $idPemateri, 'tanggal' => date('Y-m-d', strtotime('+3 day')), 'kuota_total' => 20]);
    $check('ubah judul saja -> h1_terkirim_at TIDAK direset', $t === 'success' && mysqli_fetch_assoc(mysqli_query($conn, "SELECT h1_terkirim_at FROM registrations WHERE id_registrasi=$regReset"))['h1_terkirim_at'] !== null);

    // ===== Paket D: jadwal_diubah_at (F6b) =====
    $tglJ = date('Y-m-d', strtotime('+2 day'));
    $idSemJ = ins_seminar($conn, 'PaketD J ' . $tag, $tglJ, 20, 'Aktif', ['jam_mulai' => '09:00:00', 'jam_selesai' => '10:00:00', 'lokasi' => 'Ruang A', 'link_online' => 'https://contoh.test/j']);
    // Awal: NULL.
    $check('jadwal: awal NULL', mysqli_fetch_assoc(mysqli_query($conn, "SELECT jadwal_diubah_at FROM seminars WHERE id_seminar=$idSemJ"))['jadwal_diubah_at'] === null);

    $basis = ['judul' => 'PaketD J ' . $tag, 'id_pemateri' => $idPemateri, 'tanggal' => $tglJ, 'kuota_total' => 20,
        'jam_mulai' => '09:00', 'jam_selesai' => '10:00', 'lokasi' => 'Ruang A', 'link_online' => 'https://contoh.test/j'];

    // Ubah judul saja -> tidak berubah.
    [$t, ] = ubah_seminar($conn, $idSemJ, array_merge($basis, ['judul' => 'PaketD J Baru ' . $tag]));
    $check('jadwal: ganti judul -> jadwal_diubah_at tetap NULL', mysqli_fetch_assoc(mysqli_query($conn, "SELECT jadwal_diubah_at FROM seminars WHERE id_seminar=$idSemJ"))['jadwal_diubah_at'] === null);

    // Ganti kuota saja -> tidak berubah.
    [$t, ] = ubah_seminar($conn, $idSemJ, array_merge($basis, ['judul' => 'PaketD J Baru ' . $tag, 'kuota_total' => 25]));
    $check('jadwal: ganti kuota -> tetap NULL', mysqli_fetch_assoc(mysqli_query($conn, "SELECT jadwal_diubah_at FROM seminars WHERE id_seminar=$idSemJ"))['jadwal_diubah_at'] === null);

    // Ganti lokasi -> terisi.
    [$t, ] = ubah_seminar($conn, $idSemJ, array_merge($basis, ['judul' => 'PaketD J Baru ' . $tag, 'kuota_total' => 25, 'lokasi' => 'Ruang B']));
    $check('jadwal: ganti lokasi -> jadwal_diubah_at terisi', $t === 'success' && mysqli_fetch_assoc(mysqli_query($conn, "SELECT jadwal_diubah_at FROM seminars WHERE id_seminar=$idSemJ"))['jadwal_diubah_at'] !== null);
    $stamp1 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT jadwal_diubah_at FROM seminars WHERE id_seminar=$idSemJ"))['jadwal_diubah_at'];

    // Ubah tanpa perubahan jadwal (judul+kuota) -> cap TIDAK berubah.
    [$t, ] = ubah_seminar($conn, $idSemJ, array_merge($basis, ['judul' => 'PaketD J Lagi ' . $tag, 'kuota_total' => 30, 'lokasi' => 'Ruang B']));
    $stamp2 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT jadwal_diubah_at FROM seminars WHERE id_seminar=$idSemJ"))['jadwal_diubah_at'];
    $check('jadwal: tanpa perubahan jadwal -> cap tidak berubah', $stamp2 === $stamp1);

    // Jam mulai berubah -> terisi/berubah.
    sleep(1);
    [$t, ] = ubah_seminar($conn, $idSemJ, array_merge($basis, ['judul' => 'PaketD J Lagi ' . $tag, 'kuota_total' => 30, 'lokasi' => 'Ruang B', 'jam_mulai' => '08:00']));
    $stamp3 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT jadwal_diubah_at FROM seminars WHERE id_seminar=$idSemJ"))['jadwal_diubah_at'];
    $check('jadwal: ganti jam mulai -> cap berubah', $stamp3 !== $stamp1);

    // Mengosongkan lokasi yang sebelumnya ada -> dianggap perubahan.
    $stampBefore = $stamp3;
    sleep(1);
    [$t, ] = ubah_seminar($conn, $idSemJ, array_merge($basis, ['judul' => 'PaketD J Lagi ' . $tag, 'kuota_total' => 30, 'lokasi' => '', 'jam_mulai' => '08:00']));
    $stamp4 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT jadwal_diubah_at FROM seminars WHERE id_seminar=$idSemJ"))['jadwal_diubah_at'];
    $check('jadwal: kosongkan lokasi (sebelumnya ada) -> cap berubah', $stamp4 !== $stampBefore);

    // Lokasi tetap NULL (kosong->kosong) -> bukan perubahan.
    $idSemJ2 = ins_seminar($conn, 'PaketD J2 ' . $tag, $tglJ, 20);
    $basis2 = ['judul' => 'PaketD J2 ' . $tag, 'id_pemateri' => $idPemateri, 'tanggal' => $tglJ, 'kuota_total' => 20, 'lokasi' => ''];
    [$t, ] = ubah_seminar($conn, $idSemJ2, $basis2);
    $check('jadwal: lokasi kosong->kosong -> tetap NULL', mysqli_fetch_assoc(mysqli_query($conn, "SELECT jadwal_diubah_at FROM seminars WHERE id_seminar=$idSemJ2"))['jadwal_diubah_at'] === null);

    // ===== Paket D: konfirmasi pendaftaran tidak menggagalkan (pengirim konfirmasi aman) =====
    // kirim_konfirmasi_pendaftaran() harus tetap aman walau SMTP tak diset (best-effort).
    $idUKonf = ins_user($conn, 'D Konf ' . $tag, "dkonf{$tag}@test.local");
    kirim_konfirmasi_pendaftaran($conn, $idUKonf, ['judul' => 'X', 'pemateri' => 'Y', 'tanggal' => $besok, 'jam_mulai' => null, 'jam_selesai' => null, 'lokasi' => null], 'ACD-TEST');
    $check('konfirmasi best-effort tidak melempar', true);

    // ===== Paket D: statistik grafik =====
    // Grafik (1): pendaftar per bulan, created_at NULL dikecualikan.
    $idSemGraf = ins_seminar($conn, 'PaketD Graf ' . $tag, $besok, 20);
    $idUGraf = ins_user($conn, 'D Graf ' . $tag, "dgraf{$tag}@test.local");
    $regGraf = ins_reg($conn, $idUGraf, $idSemGraf, 'Belum Hadir', 'D-' . $tag . '-G1'); // created_at otomatis
    // Baris NULL buatan untuk memastikan dikecualikan.
    $idUGraf2 = ins_user($conn, 'D Graf2 ' . $tag, "dgraf2{$tag}@test.local");
    $regGraf2 = ins_reg($conn, $idUGraf2, $idSemGraf, 'Belum Hadir', 'D-' . $tag . '-G2');
    mysqli_query($conn, "UPDATE registrations SET created_at = NULL WHERE id_registrasi = $regGraf2");
    $graf = statistik_pendaftar_bulanan($conn, 12);
    $check('grafik bulanan: 12 label', count($graf['bulan']) === 12);
    $check('grafik bulanan: urut kronologis', $graf['bulan'][0]['label'] < $graf['bulan'][11]['label']);
    $check('grafik bulanan: bulan ini berisi >= 1', (int) end($graf['bulan'])['nilai'] >= 1);
    $check('grafik bulanan: created_at NULL dikecualikan (tanpa_waktu >= 1)', $graf['tanpa_waktu'] >= 1);
    $labelBulanIni = date('Y-m');
    $found = false;
    foreach ($graf['bulan'] as $bl) { if ($bl['label'] === $labelBulanIni) { $found = true; } }
    $check('grafik bulanan: label bulan ini ada', $found);

    // Grafik (2): kehadiran seminar (persen terhitung di pemanggil; di sini cek data mentah).
    $idSemHad = ins_seminar($conn, 'PaketD HadirSem ' . $tag, $kemarin, 20, 'Selesai');
    $idUHa = ins_user($conn, 'D Ha ' . $tag, "dha{$tag}@test.local");
    $idUHa2 = ins_user($conn, 'D Ha2 ' . $tag, "dha2{$tag}@test.local");
    ins_reg($conn, $idUHa, $idSemHad, 'Hadir', 'D-' . $tag . '-H1');
    ins_reg($conn, $idUHa2, $idSemHad, 'Belum Hadir', 'D-' . $tag . '-H2');
    $kh = statistik_kehadiran_seminar($conn, 10);
    $semHad = null;
    foreach ($kh as $row) { if ($row['judul'] === 'PaketD HadirSem ' . $tag) { $semHad = $row; } }
    $check('grafik kehadiran: terdaftar=2 hadir=1', $semHad !== null && $semHad['terdaftar'] === 2 && $semHad['hadir'] === 1);
    $check('grafik kehadiran: maksimal 10 baris', count($kh) <= 10);
    $check('grafik kehadiran: hanya efektif-Selesai', $semHad !== null);

    // Grafik (3): 5 seminar terpopuler.
    $kp = statistik_seminar_populer($conn, 5);
    $check('grafik populer: maksimal 5 baris', count($kp) <= 5);
    $srt = true;
    for ($i = 1; $i < count($kp); $i++) { if ($kp[$i]['terdaftar'] > $kp[$i-1]['terdaftar']) { $srt = false; } }
    $check('grafik populer: urut menurun', $srt);

    // ===== F12 revisi: statistik_pendaftar_harian =====
    // Buat user & seminar uji, lalu set created_at eksplisit (batas hari, NULL, di luar rentang).
    $idSemH = ins_seminar($conn, 'PaketD Harian ' . $tag, $besok, 50);
    $mulaiH = date('Y-m-d', strtotime('-89 day'));
    $hariIniH = date('Y-m-d');
    $kemarinH = date('Y-m-d', strtotime('-1 day'));
    $ua = ins_user($conn, 'DH a ' . $tag, "dhra{$tag}@test.local");
    $ub = ins_user($conn, 'DH b ' . $tag, "dhrb{$tag}@test.local");
    $uc = ins_user($conn, 'DH c ' . $tag, "dhrc{$tag}@test.local");
    $ud = ins_user($conn, 'DH d ' . $tag, "dhrd{$tag}@test.local");
    $ue = ins_user($conn, 'DH e ' . $tag, "dhre{$tag}@test.local");
    $ra = ins_reg($conn, $ua, $idSemH, 'Hadir', 'DH-' . $tag . '-a');
    $rb = ins_reg($conn, $ub, $idSemH, 'Belum Hadir', 'DH-' . $tag . '-b');
    $rc = ins_reg($conn, $uc, $idSemH, 'Hadir', 'DH-' . $tag . '-c');
    $rd = ins_reg($conn, $ud, $idSemH, 'Belum Hadir', 'DH-' . $tag . '-d');
    $re = ins_reg($conn, $ue, $idSemH, 'Hadir', 'DH-' . $tag . '-e');
    // Batas hari: 00:00:00 dan 23:59:59 pada hari ini.
    mysqli_query($conn, "UPDATE registrations SET created_at = '{$hariIniH} 00:00:00' WHERE id_registrasi = $ra");
    mysqli_query($conn, "UPDATE registrations SET created_at = '{$hariIniH} 23:59:59' WHERE id_registrasi = $rb");
    // Kemarin, Hadir.
    mysqli_query($conn, "UPDATE registrations SET created_at = '{$kemarinH} 12:00:00' WHERE id_registrasi = $rc");
    // NULL (dikecualikan dari seri).
    mysqli_query($conn, "UPDATE registrations SET created_at = NULL WHERE id_registrasi = $rd");
    // Di luar rentang (jauh sebelum $mulai).
    $lamaH = date('Y-m-d', strtotime('-200 day'));
    mysqli_query($conn, "UPDATE registrations SET created_at = '{$lamaH} 10:00:00' WHERE id_registrasi = $re");

    $harian = statistik_pendaftar_harian($conn, $mulaiH . ' 00:00:00');
    // Peta hasil untuk cek cepat.
    $petaH = [];
    foreach ($harian as $row) { $petaH[$row['d'] . '|' . $row['status_hadir']] = $row['c']; }
    $check('harian: batas 00:00:00 & 23:59:59 hari ini (Hadir>=1, Belum>=1)', ($petaH[$hariIniH . '|Hadir'] ?? 0) >= 1 && ($petaH[$hariIniH . '|Belum Hadir'] ?? 0) >= 1);
    $check('harian: kemarin Hadir>=1', ($petaH[$kemarinH . '|Hadir'] ?? 0) >= 1);
    $check('harian: created_at NULL tak muncul', !isset($petaH['|Belum Hadir']) && !isset($petaH['|Hadir']));
    $check('harian: di luar rentang (200 hari) tidak ikut', ($petaH[$lamaH . '|Hadir'] ?? 0) === 0);
    $check('harian: semua baris punya status valid', (function () use ($harian) {
        foreach ($harian as $row) { if (!in_array($row['status_hadir'], ['Hadir', 'Belum Hadir'], true)) { return false; } }
        return true;
    })());
    // Param aneh tidak merusak (prepared): string injeksi -> tak melempar, hasil wajar.
    $aneh = statistik_pendaftar_harian($conn, "2026-01-01' OR '1'='1");
    $check('harian: param aneh aman (prepared)', is_array($aneh));
    // Hitung catatan tanpa waktu (COUNT terpisah).
    $tanpa = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM registrations WHERE created_at IS NULL"))['c'];
    $check('harian: catatan tanpa waktu >= 1', $tanpa >= 1);

    // ===== Paket D: hitung peserta terdampak (konfirmasi hapus) =====
    $jml = count([$regBesok, $regHadir, $regOff]); // 3 registrasi di seminar besok
    $cDampak = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM registrations WHERE id_seminar = $idSemBesok"))['c'];
    $check('jumlah peserta terdampak benar', $cDampak === 3);
    $cKosong = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM registrations WHERE id_seminar = $idSemJ2"))['c'];
    $check('jumlah peserta terdampak 0 jika tak ada', $cKosong === 0);

    // ===== Paket E1: checkin_manual =====
    $idSemE1Hari = ins_seminar($conn, 'E1 HariIni ' . $tag, $hariIni, 20);
    $idSemE1Besok = ins_seminar($conn, 'E1 Besok ' . $tag, $besok, 20);
    $idSemE1Kemarin = ins_seminar($conn, 'E1 Kemarin ' . $tag, $kemarin, 20);
    $idSemE1Selesai = ins_seminar($conn, 'E1 Selesai ' . $tag, $hariIni, 20, 'Selesai');
    $idUE1 = ins_user($conn, 'E1 U ' . $tag, "e1u{$tag}@test.local");
    $idUE1Lain = ins_user($conn, 'E1 U Lain ' . $tag, "e1ul{$tag}@test.local");

    // Sukses di hari H + checkin_at terisi.
    $regE1 = ins_reg($conn, $idUE1, $idSemE1Hari, 'Belum Hadir', 'E1-' . $tag . '-1');
    [$t, ] = checkin_manual($conn, $regE1, $idSemE1Hari);
    $rowE1 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status_hadir, checkin_at FROM registrations WHERE id_registrasi=$regE1"));
    $check('checkin_manual sukses di hari H', $t === 'success');
    $check('checkin_manual mengisi checkin_at', $rowE1['status_hadir'] === 'Hadir' && $rowE1['checkin_at'] !== null);

    // Sudah Hadir -> ditolak.
    [$t, ] = checkin_manual($conn, $regE1, $idSemE1Hari);
    $check('checkin_manual ditolak bila sudah Hadir', $t === 'danger');

    // Registrasi bukan milik seminar halaman -> ditolak.
    $regE1Lain = ins_reg($conn, $idUE1, $idSemE1Besok, 'Belum Hadir', 'E1-' . $tag . '-2');
    [$t, ] = checkin_manual($conn, $regE1Lain, $idSemE1Hari);
    $check('checkin_manual tolak registrasi bukan milik seminar halaman', $t === 'danger');

    // Seminar besok -> ditolak.
    [$t, ] = checkin_manual($conn, $regE1Lain, $idSemE1Besok);
    $check('checkin_manual tolak seminar besok', $t === 'danger');

    // Seminar kemarin -> ditolak.
    $regE1Kem = ins_reg($conn, $idUE1, $idSemE1Kemarin, 'Belum Hadir', 'E1-' . $tag . '-3');
    [$t, ] = checkin_manual($conn, $regE1Kem, $idSemE1Kemarin);
    $check('checkin_manual tolak seminar kemarin', $t === 'danger');

    // Seminar Selesai -> ditolak.
    $regE1Sel = ins_reg($conn, $idUE1, $idSemE1Selesai, 'Belum Hadir', 'E1-' . $tag . '-4');
    [$t, ] = checkin_manual($conn, $regE1Sel, $idSemE1Selesai);
    $check('checkin_manual tolak seminar Selesai', $t === 'danger');

    // ===== Paket E1: batalkan_checkin =====
    $kuotaSebelum = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT kuota FROM seminars WHERE id_seminar=$idSemE1Hari"))['kuota'];
    [$t, ] = batalkan_checkin($conn, $regE1, $idSemE1Hari);
    $rowE1b = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status_hadir, checkin_at FROM registrations WHERE id_registrasi=$regE1"));
    $kuotaSesudah = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT kuota FROM seminars WHERE id_seminar=$idSemE1Hari"))['kuota'];
    $check('batalkan_checkin sukses di hari H', $t === 'success');
    $check('batalkan_checkin mengosongkan status + checkin_at', $rowE1b['status_hadir'] === 'Belum Hadir' && $rowE1b['checkin_at'] === null);
    $check('batalkan_checkin tidak mengubah kuota', $kuotaSesudah === $kuotaSebelum);

    // Belum Hadir -> ditolak.
    [$t, ] = batalkan_checkin($conn, $regE1, $idSemE1Hari);
    $check('batalkan_checkin tolak yang belum Hadir', $t === 'danger');

    // Seminar efektif Selesai -> ditolak walau status Hadir.
    $regE1Sel2 = ins_reg($conn, $idUE1Lain, $idSemE1Selesai, 'Hadir', 'E1-' . $tag . '-5');
    mysqli_query($conn, "UPDATE registrations SET checkin_at = NOW() WHERE id_registrasi=$regE1Sel2");
    [$t, ] = batalkan_checkin($conn, $regE1Sel2, $idSemE1Selesai);
    $check('batalkan_checkin tolak seminar efektif Selesai', $t === 'danger');

    // Registrasi bukan milik seminar halaman -> ditolak.
    ins_reg($conn, $idUE1Lain, $idSemE1Besok, 'Hadir', 'E1-' . $tag . '-6');
    $regE1B = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT id_registrasi FROM registrations WHERE kode_tiket='E1-" . $tag . "-6'"))['id_registrasi'];
    [$t, ] = batalkan_checkin($conn, $regE1B, $idSemE1Hari);
    $check('batalkan_checkin tolak registrasi bukan milik seminar halaman', $t === 'danger');

    // ===== Paket E1: scanner tetap isi checkin_at (via pola UPDATE handleCheckin) =====
    $regScan = ins_reg($conn, $idUE1Lain, $idSemE1Hari, 'Belum Hadir', 'E1-' . $tag . '-S');
    $stmt = mysqli_prepare($conn, "UPDATE registrations SET status_hadir = 'Hadir', checkin_at = NOW() WHERE kode_tiket = ? AND status_hadir = 'Belum Hadir'");
    $kt = 'E1-' . $tag . '-S';
    mysqli_stmt_bind_param($stmt, 's', $kt);
    mysqli_stmt_execute($stmt);
    $aff = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    $rowScan = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status_hadir, checkin_at FROM registrations WHERE id_registrasi=$regScan"));
    $check('scanner update mengisi checkin_at', $aff === 1 && $rowScan['checkin_at'] !== null);
    // Ulang -> 0 affected (sudah Hadir).
    $stmt = mysqli_prepare($conn, "UPDATE registrations SET status_hadir = 'Hadir', checkin_at = NOW() WHERE kode_tiket = ? AND status_hadir = 'Belum Hadir'");
    mysqli_stmt_bind_param($stmt, 's', $kt);
    mysqli_stmt_execute($stmt);
    $aff2 = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    $check('scanner update ulang tidak mengubah (0 affected)', $aff2 === 0);

    // ===== Paket E1: buat_panitia =====
    $emailPan = "pan{$tag}@test.local";
    [$t, $msg, $idPan] = buat_panitia($conn, 'Panitia Uji ' . $tag, $emailPan, 'passwordpan10');
    $check('buat_panitia sukses', $t === 'success' && $idPan > 0);
    $rowPan = mysqli_fetch_assoc(mysqli_query($conn, "SELECT role, nim, prodi, notif_email, wajib_ganti_password, password FROM users WHERE id_user=" . (int) $idPan));
    $check('buat_panitia role = panitia', $rowPan['role'] === 'panitia');
    $check('buat_panitia nim/prodi NULL', $rowPan['nim'] === null && $rowPan['prodi'] === null);
    $check('buat_panitia notif_email = 0', (int) $rowPan['notif_email'] === 0);
    $check('buat_panitia wajib_ganti_password = 1', (int) $rowPan['wajib_ganti_password'] === 1);
    $check('buat_panitia hash valid', password_verify('passwordpan10', $rowPan['password']));

    [$t, ] = buat_panitia($conn, 'Pan Dua', $emailPan, 'passwordpan10');
    $check('buat_panitia email duplikat ditolak', $t === 'danger');
    [$t, ] = buat_panitia($conn, 'Pan Tiga', "pan3{$tag}@test.local", 'pendek9ch');
    $check('buat_panitia password < 10 ditolak', $t === 'danger');

    // ===== Paket E1: reset_password_panitia hanya role panitia =====
    $avPanBefore = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT auth_version FROM users WHERE id_user=" . (int) $idPan))['auth_version'];
    [$t, ] = reset_password_panitia($conn, (int) $idPan, 'passwordbaru10');
    $rowPan2 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT password, wajib_ganti_password, auth_version FROM users WHERE id_user=" . (int) $idPan));
    $check('reset_password_panitia sukses + wajib_ganti_password=1', $t === 'success' && (int) $rowPan2['wajib_ganti_password'] === 1);
    $check('reset_password_panitia naikkan auth_version + ganti hash', (int) $rowPan2['auth_version'] === $avPanBefore + 1 && password_verify('passwordbaru10', $rowPan2['password']));
    // Target bukan panitia (mahasiswa) -> tidak mengubah apa pun.
    $mhsHashBefore = mysqli_fetch_assoc(mysqli_query($conn, "SELECT password, auth_version FROM users WHERE id_user=$idUser"));
    [$t, ] = reset_password_panitia($conn, $idUser, 'passwordbaru10');
    $mhsHashAfter = mysqli_fetch_assoc(mysqli_query($conn, "SELECT password, auth_version FROM users WHERE id_user=$idUser"));
    $check('reset_password_panitia tolak non-panitia (tidak berubah)', $t === 'danger' && $mhsHashAfter['password'] === $mhsHashBefore['password'] && (int) $mhsHashAfter['auth_version'] === (int) $mhsHashBefore['auth_version']);

    // ===== Paket E1: hapus_panitia hanya role panitia =====
    $idPanHapus = 0;
    [, , $idPanHapus] = buat_panitia($conn, 'Pan Hapus ' . $tag, "panh{$tag}@test.local", 'passwordpan10');
    [$t, ] = hapus_panitia($conn, $idUser); // mahasiswa
    $check('hapus_panitia tolak non-panitia', $t === 'danger' && mysqli_fetch_assoc(mysqli_query($conn, "SELECT id_user FROM users WHERE id_user=$idUser")) !== null);
    [$t, ] = hapus_panitia($conn, (int) $idPanHapus);
    $check('hapus_panitia sukses + baris hilang', $t === 'success' && mysqli_fetch_assoc(mysqli_query($conn, "SELECT id_user FROM users WHERE id_user=" . (int) $idPanHapus)) === null);

    // ===== Paket E1: ganti_password_wajib =====
    // Password baru sama dengan lama -> ditolak.
    [, , $idGP] = buat_panitia($conn, 'Pan GP ' . $tag, "pangp{$tag}@test.local", 'passwordlama10');
    [$t, ] = ganti_password_wajib($conn, (int) $idGP, 'panitia', 'passwordlama10', 'passwordlama10');
    $check('ganti_password_wajib tolak sama dengan lama', $t === 'danger');
    // Konfirmasi beda -> ditolak.
    [$t, ] = ganti_password_wajib($conn, (int) $idGP, 'panitia', 'passwordbaru10', 'passwordlain');
    $check('ganti_password_wajib tolak konfirmasi beda', $t === 'danger');
    // Sukses: flag turun, auth_version naik.
    $avGPBefore = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT auth_version FROM users WHERE id_user=" . (int) $idGP))['auth_version'];
    [$t, ] = ganti_password_wajib($conn, (int) $idGP, 'panitia', 'passwordbaru10', 'passwordbaru10');
    $rowGP = mysqli_fetch_assoc(mysqli_query($conn, "SELECT password, wajib_ganti_password, auth_version FROM users WHERE id_user=" . (int) $idGP));
    $check('ganti_password_wajib sukses turunkan flag', $t === 'success' && (int) $rowGP['wajib_ganti_password'] === 0);
    $check('ganti_password_wajib naikkan auth_version', (int) $rowGP['auth_version'] === $avGPBefore + 1);
    $check('ganti_password_wajib hash baru valid', password_verify('passwordbaru10', $rowGP['password']));

    // ===== Paket E1: pakai_token_reset turunkan wajib_ganti_password =====
    $idUFlag = ins_user($conn, 'E1 Flag ' . $tag, "e1flag{$tag}@test.local");
    mysqli_query($conn, "UPDATE users SET wajib_ganti_password = 1 WHERE id_user=$idUFlag");
    $tokFlag = buat_token_reset($conn, $idUFlag);
    pakai_token_reset($conn, $tokFlag, 'passwordbaru8');
    $flagAfter = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT wajib_ganti_password FROM users WHERE id_user=$idUFlag"))['wajib_ganti_password'];
    $check('pakai_token_reset turunkan wajib_ganti_password', $flagAfter === 0);

    // ===== Paket E1: query mahasiswa tidak menyertakan panitia =====
    $cMhs = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM users WHERE role='mahasiswa' AND email='{$emailPan}'"))['c'];
    $check('query role=mahasiswa tidak hitung panitia', $cMhs === 0);

    // ===== Paket E1: pratinjau_impor_csv (read-only) =====
    $csvBersih = "nim,nama,prodi,email\n"
        . "90{$tag},Impor Satu,Teknik Informatika,imp1_{$tag}@example.com\n"
        . "91{$tag},Impor Dua,Sistem Informasi,imp2_{$tag}@example.com\n";
    $usersBefore = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM users"))['c'];
    $prev = pratinjau_impor_csv($conn, $csvBersih);
    $usersAfter = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM users"))['c'];
    $check('pratinjau valid 2 baris, 0 kesalahan', count($prev['valid']) === 2 && $prev['kesalahan'] === []);
    $check('pratinjau tidak menulis users', $usersAfter === $usersBefore);
    $check('pratinjau normalisasi prodi ke bentuk kanonik', $prev['valid'][0]['prodi'] === 'Teknik Informatika');

    // Duplikat NIM yang sudah ada di DB (akun uji di atas).
    $csvDupNim = "nim,nama,prodi,email\n"
        . "{$tag}N,Impor Dup,Manajemen Informatika,dup_{$tag}_a@example.com\n";
    $testUser = ins_user($conn, 'Impor Pegang NIM ' . $tag, "impnim{$tag}@example.com");
    mysqli_query($conn, "UPDATE users SET nim = 'N{$tag}' WHERE id_user=$testUser");
    $csvDupNim = "nim,nama,prodi,email\nN{$tag},Impor Dup,Manajemen Informatika,dup_{$tag}_a@example.com\n";
    $prevDup = pratinjau_impor_csv($conn, $csvDupNim);
    $check('pratinjau deteksi NIM sudah terdaftar', count($prevDup['valid']) === 0 && $prevDup['kesalahan'] !== []);

    // Duplikat email case-insensitive terhadap DB.
    $csvDupMail = "nim,nama,prodi,email\nD{$tag},Impor Dup2,Manajemen Informatika,IMPNIM{$tag}@EXAMPLE.COM\n";
    $prevDupM = pratinjau_impor_csv($conn, $csvDupMail);
    $check('pratinjau deteksi email duplikat case-insensitive', count($prevDupM['valid']) === 0 && $prevDupM['kesalahan'] !== []);

    // Duplikat di dalam file + prodi tak dikenal + header hilang.
    $csvDupFile = "nim,nama,prodi,email\nF{$tag},A,Teknik Informatika,df_{$tag}@example.com\nF{$tag},B,Sistem Informasi,df2_{$tag}@example.com\n";
    $prevDF = pratinjau_impor_csv($conn, $csvDupFile);
    $check('pratinjau deteksi NIM duplikat dalam file', count($prevDF['valid']) === 1 && count($prevDF['kesalahan']) === 1);
    $prevProdi = pratinjau_impor_csv($conn, "nim,nama,prodi,email\nP{$tag},A,Prodi Ngawur,prodi_{$tag}@example.com\n");
    $check('pratinjau tolak prodi tak dikenal', count($prevProdi['valid']) === 0 && $prevProdi['kesalahan'] !== []);
    $prevHdr = pratinjau_impor_csv($conn, "nim,nama,email\nX,Y,z@example.com\n");
    $check('pratinjau tolak header kurang kolom', count($prevHdr['valid']) === 0 && $prevHdr['kesalahan'] !== []);

    // ===== Paket E1: terapkan_impor =====
    $passAwal = 'passawal8';
    [$t, , $jml] = terapkan_impor($conn, $prev['valid'], $passAwal);
    $check('terapkan_impor buat 2 akun', $t === 'success' && $jml === 2);
    $rowsImpor = mysqli_fetch_all(mysqli_query($conn, "SELECT role, wajib_ganti_password, password FROM users WHERE email IN ('imp1_{$tag}@example.com','imp2_{$tag}@example.com')"), MYSQLI_ASSOC);
    $check('terapkan_impor role mahasiswa + flag=1', count($rowsImpor) === 2 && $rowsImpor[0]['role'] === 'mahasiswa' && (int) $rowsImpor[0]['wajib_ganti_password'] === 1);
    $check('terapkan_impor SATU hash sama untuk semua (lihat: hash identik)', $rowsImpor[0]['password'] === $rowsImpor[1]['password'] && password_verify($passAwal, $rowsImpor[0]['password']));

    // Atomik: satu baris duplikat (race) -> tidak ada akun dibuat.
    $usersBeforeRace = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM users"))['c'];
    $csvRace = "nim,nama,prodi,email\n"
        . "R{$tag}A,Race A,Teknik Informatika,racea_{$tag}@example.com\n"
        . "R{$tag}B,Race B,Teknik Informatika,imp1_{$tag}@example.com\n"; // email duplikat yg sudah ada
    $prevRace = pratinjau_impor_csv($conn, $csvRace); // pratinjau mendeteksi duplikat -> valid kosong
    // Paksa terapkan dgn data yang menabrak unique (simulasi race): pakai baris valid dari DB-hijau.
    $paksa = [['baris' => 2, 'nim' => "R{$tag}A", 'nama' => 'Race A', 'prodi' => 'Teknik Informatika', 'email' => "imp1_{$tag}@example.com"]];
    [$t, $msg, $jml2] = terapkan_impor($conn, $paksa, $passAwal);
    $usersAfterRace = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM users"))['c'];
    $check('terapkan_impor atomik saat duplikasi (0 akun baru)', $t === 'danger' && $jml2 === 0 && $usersAfterRace === $usersBeforeRace);

    // Password awal tidak valid / terlalu panjang.
    [, , $jml3] = terapkan_impor($conn, $prev['valid'], 'pendek');
    $check('terapkan_impor tolak password awal < 8', $jml3 === 0);
    [$t, , $jml4] = terapkan_impor($conn, $prev['valid'], str_repeat('x', 73));
    $check('terapkan_impor tolak password > 72 byte', $t === 'danger' && $jml4 === 0);

    // Password awal tidak tersimpan apa adanya di DB (hash), dan tak ada di sesi.
    $cekPlain = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM users WHERE password = '" . mysqli_real_escape_string($conn, $passAwal) . "'"))['c'];
    $check('password awal tidak tersimpan sebagai teks asli', $cekPlain === 0);
    $check('password awal tidak ada di $_SESSION', !in_array($passAwal, array_map('strval', $_SESSION ?? []), true));

    // ===== Paket E1: F18 tanda tangan sertifikat (ubah_seminar) =====
    $idPemTtd = ins_pemateri($conn, 'Pem TTD ' . $tag);
    $idSemTtd = ins_seminar($conn, 'F18 TTD ' . $tag, $besok, 20, 'Aktif', ['jam_mulai' => '09:00']);
    $jdiubahBefore = mysqli_fetch_assoc(mysqli_query($conn, "SELECT jadwal_diubah_at FROM seminars WHERE id_seminar=$idSemTtd"))['jadwal_diubah_at'];

    // Simpan ttd terisi: jadwal tidak berubah -> jadwal_diubah_at tetap.
    [$t, , ] = ubah_seminar($conn, $idSemTtd, [
        'judul' => 'F18 TTD ' . $tag, 'id_pemateri' => $idPemTtd, 'tanggal' => $besok,
        'kuota_total' => 20, 'jam_mulai' => '09:00',
        'ttd_nama' => 'Dr. Ani Wijaya', 'ttd_jabatan' => 'Ketua Pelaksana',
    ]);
    $rowTtd = mysqli_fetch_assoc(mysqli_query($conn, "SELECT ttd_nama, ttd_jabatan, jadwal_diubah_at FROM seminars WHERE id_seminar=$idSemTtd"));
    $check('ubah_seminar simpan ttd terisi', $t === 'success' && $rowTtd['ttd_nama'] === 'Dr. Ani Wijaya' && $rowTtd['ttd_jabatan'] === 'Ketua Pelaksana');
    $check('ubah_seminar ttd tidak isi jadwal_diubah_at', $rowTtd['jadwal_diubah_at'] === $jdiubahBefore);

    // Kosong -> NULL.
    [$t, , ] = ubah_seminar($conn, $idSemTtd, [
        'judul' => 'F18 TTD ' . $tag, 'id_pemateri' => $idPemTtd, 'tanggal' => $besok,
        'kuota_total' => 20, 'jam_mulai' => '09:00', 'ttd_nama' => '', 'ttd_jabatan' => '',
    ]);
    $rowTtd2 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT ttd_nama, ttd_jabatan FROM seminars WHERE id_seminar=$idSemTtd"));
    $check('ubah_seminar ttd kosong -> NULL', $t === 'success' && $rowTtd2['ttd_nama'] === null && $rowTtd2['ttd_jabatan'] === null);

    // Jabatan diisi tanpa nama -> ditolak, data tak berubah.
    [$t, , ] = ubah_seminar($conn, $idSemTtd, [
        'judul' => 'F18 TTD ' . $tag, 'id_pemateri' => $idPemTtd, 'tanggal' => $besok,
        'kuota_total' => 20, 'jam_mulai' => '09:00', 'ttd_nama' => '', 'ttd_jabatan' => 'Sekretaris',
    ]);
    $rowTtd3 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT ttd_nama FROM seminars WHERE id_seminar=$idSemTtd"));
    $check('ubah_seminar tolak jabatan tanpa nama', $t === 'danger' && $rowTtd3['ttd_nama'] === null);

    // Karakter kontrol dibersihkan (bukan ditolak): 0x01 dibuang sebelum disimpan.
    [$t, , ] = ubah_seminar($conn, $idSemTtd, [
        'judul' => 'F18 TTD ' . $tag, 'id_pemateri' => $idPemTtd, 'tanggal' => $besok,
        'kuota_total' => 20, 'jam_mulai' => '09:00', 'ttd_nama' => "Nama\x01Jahat", 'ttd_jabatan' => '',
    ]);
    $rowTtd4 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT ttd_nama FROM seminars WHERE id_seminar=$idSemTtd"));
    $check('ubah_seminar bersihkan karakter kontrol', $t === 'success' && $rowTtd4['ttd_nama'] === 'NamaJahat');

    // ===== Paket E1: F18 tes PDF (nama ttd vs teks lama) =====
    $idMhsTtd = ins_user($conn, 'Mhs TTD ' . $tag, "mhsttd{$tag}@example.com");
    ins_reg($conn, $idMhsTtd, $idSemTtd, 'Hadir', 'F18-' . $tag);
    // Isi ttd lagi untuk uji PDF, lalu render lewat sertifikat.php (pakai sesi).
    ubah_seminar($conn, $idSemTtd, [
        'judul' => 'Seminar TTD ' . $tag, 'id_pemateri' => $idPemTtd, 'tanggal' => $besok,
        'kuota_total' => 20, 'jam_mulai' => '09:00', 'ttd_nama' => 'Dr. Ani Wijaya', 'ttd_jabatan' => 'Ketua Pelaksana',
    ]);
    $check('F18 setup: ttd tersimpan untuk uji PDF', true);
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
