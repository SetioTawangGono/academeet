<?php
/**
 * Layanan bisnis yang butuh DB. Fungsi murni terpisah dari HTML agar bisa dites.
 * Setiap fungsi menerima mysqli, memakai transaksi, dan mengembalikan [tipe, pesan].
 */
require_once __DIR__ . '/bootstrap.php';

/**
 * Batalkan pendaftaran tiket milik seorang mahasiswa.
 * Syarat: seminar Aktif, tanggal > hari ini (paling lambat H-1), tiket Belum Hadir.
 * Mengembalikan kuota (tidak melebihi kuota_total).
 */
function batalkan_pendaftaran(mysqli $conn, int $id_registrasi, int $id_user): array
{
    if (!mysqli_begin_transaction($conn)) {
        return ['danger', 'Gagal memulai transaksi.'];
    }
    try {
        $stmt = mysqli_prepare($conn, "SELECT r.id_seminar, r.status_hadir, s.status, s.tanggal, s.kuota, s.kuota_total
            FROM registrations r JOIN seminars s ON r.id_seminar = s.id_seminar
            WHERE r.id_registrasi = ? AND r.id_user = ? FOR UPDATE");
        mysqli_stmt_bind_param($stmt, 'ii', $id_registrasi, $id_user);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$row) {
            throw new RuntimeException('Tiket tidak ditemukan.');
        }
        if ($row['status'] !== 'Aktif') {
            throw new RuntimeException('Seminar ini sudah ditutup.');
        }
        if ($row['tanggal'] <= date('Y-m-d')) {
            throw new RuntimeException('Pembatalan hanya sampai H-1 sebelum seminar.');
        }
        if ($row['status_hadir'] === 'Hadir') {
            throw new RuntimeException('Tiket yang sudah di-check-in tidak dapat dibatalkan.');
        }

        $stmt = mysqli_prepare($conn, "DELETE FROM registrations WHERE id_registrasi = ? AND id_user = ?");
        mysqli_stmt_bind_param($stmt, 'ii', $id_registrasi, $id_user);
        mysqli_stmt_execute($stmt);
        $deleted = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        if ($deleted !== 1) {
            throw new RuntimeException('Tiket tidak ditemukan.');
        }

        $stmt = mysqli_prepare($conn, "UPDATE seminars SET kuota = LEAST(kuota + 1, COALESCE(kuota_total, kuota + 1)) WHERE id_seminar = ?");
        mysqli_stmt_bind_param($stmt, 'i', $row['id_seminar']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        mysqli_commit($conn);
        return ['success', 'Pendaftaran berhasil dibatalkan.'];
    } catch (Throwable $ex) {
        mysqli_rollback($conn);
        return ['danger', $ex->getMessage()];
    }
}

/**
 * Ubah seminar (admin). Mengunci baris, memvalidasi, lalu UPDATE.
 * $data: judul, id_pemateri, tanggal, kuota_total (int).
 * $poster: nama file poster baru (hasil upload di luar transaksi) atau null.
 * Mengembalikan [tipe, pesan, commit_ok]; poster lama dihapus setelah commit oleh pemanggil.
 */
function ubah_seminar(mysqli $conn, int $id_seminar, array $data, ?string $poster = null): array
{
    if (!mysqli_begin_transaction($conn)) {
        return ['danger', 'Gagal memulai transaksi.', false];
    }
    try {
        $stmt = mysqli_prepare($conn, "SELECT judul, pemateri, tanggal, status FROM seminars WHERE id_seminar = ? FOR UPDATE");
        mysqli_stmt_bind_param($stmt, 'i', $id_seminar);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$row) {
            throw new RuntimeException('Seminar tidak ditemukan.');
        }

        $status_efektif = seminar_status_efektif((string) $row['status'], (string) $row['tanggal'], date('Y-m-d'));
        if ($status_efektif !== 'Aktif') {
            throw new RuntimeException('Seminar yang sudah selesai tidak dapat diedit.');
        }

        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM registrations WHERE id_seminar = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id_seminar);
        mysqli_stmt_execute($stmt);
        $pendaftar = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
        mysqli_stmt_close($stmt);

        $judul = trim((string) ($data['judul'] ?? ''));
        $id_pemateri = (int) ($data['id_pemateri'] ?? 0);
        $tanggal = (string) ($data['tanggal'] ?? '');
        $kuota_total = (int) ($data['kuota_total'] ?? 0);
        $jam_mulai = $data['jam_mulai'] ?? null;
        $jam_selesai = $data['jam_selesai'] ?? null;
        $lokasi = $data['lokasi'] ?? null;
        $link_online = $data['link_online'] ?? null;
        $kategori = (string) ($data['kategori'] ?? 'Umum');
        $deskripsi = $data['deskripsi'] ?? null;

        if ($judul === '') {
            throw new RuntimeException('Judul seminar wajib diisi.');
        }
        if (str_len($judul) > 200) {
            throw new RuntimeException('Judul seminar maksimal 200 karakter.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            throw new RuntimeException('Format tanggal tidak valid.');
        }
        [$y, $m, $d] = array_map('intval', explode('-', $tanggal));
        if (!checkdate($m, $d, $y)) {
            throw new RuntimeException('Tanggal tidak valid.');
        }
        if ($tanggal !== $row['tanggal'] && $tanggal < date('Y-m-d')) {
            throw new RuntimeException('Tanggal seminar tidak boleh di masa lalu.');
        }
        if ($kuota_total < 1) {
            throw new RuntimeException('Kuota total minimal 1.');
        }
        if ($kuota_total < $pendaftar) {
            throw new RuntimeException('Kuota total tidak boleh lebih kecil dari jumlah pendaftar (' . $pendaftar . ').');
        }

        $stmt = mysqli_prepare($conn, "SELECT nama FROM pemateri WHERE id_pemateri = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $id_pemateri);
        mysqli_stmt_execute($stmt);
        $pmt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$pmt) {
            throw new RuntimeException('Pemateri tidak ditemukan.');
        }

        $sisa = $kuota_total - $pendaftar;
        $nama_pemateri = $pmt['nama'];
        if ($poster !== null) {
            $stmt = mysqli_prepare($conn, "UPDATE seminars SET judul = ?, id_pemateri = ?, pemateri = ?, tanggal = ?, jam_mulai = ?, jam_selesai = ?, lokasi = ?, link_online = ?, kategori = ?, deskripsi = ?, kuota_total = ?, kuota = ?, poster = ? WHERE id_seminar = ?");
            mysqli_stmt_bind_param($stmt, 'sississsssissi', $judul, $id_pemateri, $nama_pemateri, $tanggal, $jam_mulai, $jam_selesai, $lokasi, $link_online, $kategori, $deskripsi, $kuota_total, $sisa, $poster, $id_seminar);
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE seminars SET judul = ?, id_pemateri = ?, pemateri = ?, tanggal = ?, jam_mulai = ?, jam_selesai = ?, lokasi = ?, link_online = ?, kategori = ?, deskripsi = ?, kuota_total = ?, kuota = ? WHERE id_seminar = ?");
            mysqli_stmt_bind_param($stmt, 'sississsssisi', $judul, $id_pemateri, $nama_pemateri, $tanggal, $jam_mulai, $jam_selesai, $lokasi, $link_online, $kategori, $deskripsi, $kuota_total, $sisa, $id_seminar);
        }
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('Gagal menyimpan perubahan seminar.');
        }
        mysqli_stmt_close($stmt);

        mysqli_commit($conn);
        return ['success', 'Seminar berhasil diperbarui.', true];
    } catch (Throwable $ex) {
        mysqli_rollback($conn);
        return ['danger', $ex->getMessage(), false];
    }
}

/**
 * Buat akun admin baru (dipakai tools/buat_admin.php). Tidak untuk DB live manual.
 * Validasi: nama <=100, email valid & <=100 & belum dipakai, password >=12.
 * Kolom wajib lain mengikuti skema: nim/prodi/foto NULL, role 'admin'.
 * Mengembalikan ['success'|'danger', pesan, id_user|null].
 */
function buat_admin(mysqli $conn, string $nama, string $email, string $password): array
{
    $nama = trim($nama);
    $email = trim($email);

    if ($nama === '' || $email === '') {
        return ['danger', 'Nama dan email wajib diisi.', null];
    }
    if (str_len($nama) > 100) {
        return ['danger', 'Nama maksimal 100 karakter.', null];
    }
    if (str_len($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['danger', 'Format email tidak valid atau lebih dari 100 karakter.', null];
    }
    if (!password_ok_admin($password)) {
        return ['danger', 'Password admin minimal 12 karakter.', null];
    }

    $stmt = mysqli_prepare($conn, "SELECT id_user FROM users WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $ada = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($ada) {
        return ['danger', 'Email sudah dipakai akun lain.', null];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $role = 'admin';
    $stmt = mysqli_prepare($conn, "INSERT INTO users (nama_lengkap, email, password, role) VALUES (?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'ssss', $nama, $email, $hash, $role);
    $ok = mysqli_stmt_execute($stmt);
    if (!$ok) {
        $errno = mysqli_errno($conn);
        mysqli_stmt_close($stmt);
        return ['danger', $errno === 1062 ? 'Email sudah dipakai akun lain.' : 'Gagal membuat akun admin.', null];
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return ['success', 'Akun admin berhasil dibuat.', $id];
}

/**
 * Throttle di database. Semua fungsi mengembalikan null bila query gagal (mis.
 * tabel belum ada), supaya pemanggil bisa jatuh kembali ke throttle sesi.
 * Jendela geser: hitung baris dengan attempted_at > sekarang - $detik.
 */

/** Apakah percobaan untuk kunci ini sudah mencapai batas dalam jendela $detik. */
function throttle_terlalu_banyak(mysqli $conn, string $scope, string $key_hash, int $batas, int $detik): ?bool
{
    throttle_bersihkan_oportunistik($conn);
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM auth_throttle
        WHERE scope = ? AND key_hash = ? AND attempted_at > (NOW() - INTERVAL ? SECOND)");
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'ssi', $scope, $key_hash, $detik);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }
    $c = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'] ?? 0);
    mysqli_stmt_close($stmt);
    return $c >= $batas;
}

/** Catat satu percobaan. Mengembalikan true bila tersimpan. */
function throttle_catat(mysqli $conn, string $scope, string $key_hash): bool
{
    $stmt = mysqli_prepare($conn, "INSERT INTO auth_throttle (scope, key_hash, attempted_at) VALUES (?, ?, NOW())");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $scope, $key_hash);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return (bool) $ok;
}

/** Hapus semua percobaan untuk kunci ini (mis. setelah login sukses). */
function throttle_hapus(mysqli $conn, string $scope, string $key_hash): bool
{
    $stmt = mysqli_prepare($conn, "DELETE FROM auth_throttle WHERE scope = ? AND key_hash = ?");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $scope, $key_hash);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return (bool) $ok;
}

/**
 * Bersihkan baris lebih tua dari 1 hari, oportunistik (~1% request). Kegagalan
 * diabaikan (bukan jalur kritis). Tidak butuh cron.
 */
function throttle_bersihkan_oportunistik(mysqli $conn): void
{
    if (random_int(1, 100) !== 1) {
        return;
    }
    @mysqli_query($conn, "DELETE FROM auth_throttle WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
}

/** Masa berlaku token reset (detik). */
const RESET_TOKEN_TTL = 1800; // 30 menit

/**
 * Buat token reset untuk satu user. Menghapus token lama user itu (satu aktif per user),
 * menyimpan hash SHA-256, dan mengembalikan token MENTAH sekali saja (untuk dikirim).
 * Mengembalikan token (string 64 hex) atau null bila gagal.
 */
function buat_token_reset(mysqli $conn, int $id_user): ?string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);

    if (!mysqli_begin_transaction($conn)) {
        return null;
    }
    try {
        $del = mysqli_prepare($conn, "DELETE FROM password_resets WHERE id_user = ?");
        mysqli_stmt_bind_param($del, 'i', $id_user);
        mysqli_stmt_execute($del);
        mysqli_stmt_close($del);

        $ttl = RESET_TOKEN_TTL;
        $ins = mysqli_prepare($conn, "INSERT INTO password_resets (id_user, token_hash, created_at, expires_at)
            VALUES (?, ?, NOW(), NOW() + INTERVAL ? SECOND)");
        mysqli_stmt_bind_param($ins, 'isi', $id_user, $hash, $ttl);
        if (!mysqli_stmt_execute($ins)) {
            throw new RuntimeException('Gagal menyimpan token.');
        }
        mysqli_stmt_close($ins);

        mysqli_commit($conn);
        return $token;
    } catch (Throwable $ex) {
        mysqli_rollback($conn);
        return null;
    }
}

/**
 * Pakai token reset: validasi (belum dipakai, belum kedaluwarsa), ubah password,
 * naikkan auth_version, tandai used_at, hapus token lain user itu — dalam satu transaksi.
 * Token tidak valid/kedaluwarsa/terpakai menghasilkan pesan generik yang sama.
 * Mengembalikan [tipe, pesan].
 */
function pakai_token_reset(mysqli $conn, string $token, string $passwordBaru): array
{
    $generik = 'Tautan reset tidak valid atau sudah kedaluwarsa.';
    if ($token === '' || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        return ['danger', $generik];
    }
    if (!password_ok($passwordBaru)) {
        return ['danger', 'Password baru minimal 8 karakter.'];
    }
    $hash = hash('sha256', $token);

    if (!mysqli_begin_transaction($conn)) {
        return ['danger', 'Gagal memulai transaksi.'];
    }
    try {
        $stmt = mysqli_prepare($conn, "SELECT pr.id, pr.id_user, pr.used_at, (pr.expires_at <= NOW()) AS kedaluwarsa, u.role
            FROM password_resets pr JOIN users u ON pr.id_user = u.id_user
            WHERE pr.token_hash = ? FOR UPDATE");
        mysqli_stmt_bind_param($stmt, 's', $hash);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$row || $row['used_at'] !== null || (int) $row['kedaluwarsa'] === 1) {
            throw new RuntimeException($generik);
        }
        if ($row['role'] !== 'mahasiswa') {
            throw new RuntimeException($generik);
        }

        $idUser = (int) $row['id_user'];
        $newHash = password_hash($passwordBaru, PASSWORD_DEFAULT);
        $up = mysqli_prepare($conn, "UPDATE users SET password = ?, auth_version = auth_version + 1 WHERE id_user = ?");
        mysqli_stmt_bind_param($up, 'si', $newHash, $idUser);
        mysqli_stmt_execute($up);
        mysqli_stmt_close($up);

        $mark = mysqli_prepare($conn, "UPDATE password_resets SET used_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($mark, 'i', $row['id']);
        mysqli_stmt_execute($mark);
        mysqli_stmt_close($mark);

        $del = mysqli_prepare($conn, "DELETE FROM password_resets WHERE id_user = ? AND id <> ?");
        mysqli_stmt_bind_param($del, 'ii', $idUser, $row['id']);
        mysqli_stmt_execute($del);
        mysqli_stmt_close($del);

        mysqli_commit($conn);
        return ['success', 'Password berhasil direset. Silakan login dengan password baru.'];
    } catch (Throwable $ex) {
        mysqli_rollback($conn);
        $pesan = $ex instanceof RuntimeException ? $ex->getMessage() : 'Gagal mereset password.';
        return ['danger', $pesan];
    }
}

/**
 * Ambil data satu registrasi milik user untuk pembuatan ICS.
 * Owner-only: baris harus milik $idUser, jika tidak → null.
 * Kolom detail opsional dipakai bila ada (Paket C).
 */
function ambil_data_ics(mysqli $conn, int $idUser, int $idRegistrasi): ?array
{
    if ($idUser <= 0 || $idRegistrasi <= 0) {
        return null;
    }
    // Kolom detail seminar hanya ada sejak Paket C; fallback bila belum dimigrasi.
    $stmt = mysqli_prepare($conn, "SELECT r.id_registrasi, r.kode_tiket, s.judul, s.pemateri, s.tanggal,
            s.jam_mulai, s.jam_selesai, s.lokasi, s.link_online, s.kategori, s.deskripsi
        FROM registrations r JOIN seminars s ON r.id_seminar = s.id_seminar
        WHERE r.id_registrasi = ? AND r.id_user = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $idRegistrasi, $idUser);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}
