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
        $stmt = mysqli_prepare($conn, "SELECT judul, pemateri, tanggal, jam_mulai, jam_selesai, lokasi, link_online, status FROM seminars WHERE id_seminar = ? FOR UPDATE");
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
        $in_detail = [
            'jam_mulai'   => $data['jam_mulai'] ?? null,
            'jam_selesai' => $data['jam_selesai'] ?? null,
            'lokasi'      => $data['lokasi'] ?? null,
            'link_online' => $data['link_online'] ?? null,
            'deskripsi'   => $data['deskripsi'] ?? null,
        ];
        if (array_key_exists('kategori', $data) && is_string($data['kategori'])) {
            $in_detail['kategori'] = $data['kategori'];
        }
        [$err_detail, $detail] = validasi_detail_seminar($in_detail);
        if ($err_detail !== '') {
            throw new RuntimeException($err_detail);
        }
        $jam_mulai = $detail['jam_mulai'];
        $jam_selesai = $detail['jam_selesai'];
        $lokasi = $detail['lokasi'];
        $link_online = $detail['link_online'];
        $kategori = $detail['kategori'];
        $deskripsi = $detail['deskripsi'];

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

        // Deteksi perubahan jadwal (tanggal/jam/lokasi/link). NULL dan '' dianggap sama.
        $norm = static fn($v): string => trim((string) $v);
        $jadwal_berubah = $norm($tanggal) !== $norm($row['tanggal'])
            || $norm($jam_mulai) !== $norm($row['jam_mulai'])
            || $norm($jam_selesai) !== $norm($row['jam_selesai'])
            || $norm($lokasi) !== $norm($row['lokasi'])
            || $norm($link_online) !== $norm($row['link_online']);

        if ($poster !== null) {
            $stmt = mysqli_prepare($conn, "UPDATE seminars SET judul = ?, id_pemateri = ?, pemateri = ?, tanggal = ?, jam_mulai = ?, jam_selesai = ?, lokasi = ?, link_online = ?, kategori = ?, deskripsi = ?, kuota_total = ?, kuota = ?, poster = ?, jadwal_diubah_at = " . ($jadwal_berubah ? 'NOW()' : 'jadwal_diubah_at') . " WHERE id_seminar = ?");
            mysqli_stmt_bind_param($stmt, 'sissssssssiisi', $judul, $id_pemateri, $nama_pemateri, $tanggal, $jam_mulai, $jam_selesai, $lokasi, $link_online, $kategori, $deskripsi, $kuota_total, $sisa, $poster, $id_seminar);
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE seminars SET judul = ?, id_pemateri = ?, pemateri = ?, tanggal = ?, jam_mulai = ?, jam_selesai = ?, lokasi = ?, link_online = ?, kategori = ?, deskripsi = ?, kuota_total = ?, kuota = ?, jadwal_diubah_at = " . ($jadwal_berubah ? 'NOW()' : 'jadwal_diubah_at') . " WHERE id_seminar = ?");
            mysqli_stmt_bind_param($stmt, 'sissssssssiii', $judul, $id_pemateri, $nama_pemateri, $tanggal, $jam_mulai, $jam_selesai, $lokasi, $link_online, $kategori, $deskripsi, $kuota_total, $sisa, $id_seminar);
        }
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('Gagal menyimpan perubahan seminar.');
        }
        mysqli_stmt_close($stmt);

        // Bila tanggal berubah, pengingat H-1 harus bisa dikirim ulang untuk tanggal baru.
        if ($tanggal !== (string) $row['tanggal']) {
            $st_rst = mysqli_prepare($conn, "UPDATE registrations SET h1_terkirim_at = NULL WHERE id_seminar = ?");
            mysqli_stmt_bind_param($st_rst, 'i', $id_seminar);
            mysqli_stmt_execute($st_rst);
            mysqli_stmt_close($st_rst);
        }

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
        $up = mysqli_prepare($conn, "UPDATE users SET password = ?, wajib_ganti_password = 0, auth_version = auth_version + 1 WHERE id_user = ?");
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

/**
 * Statistik kehadiran: 10 seminar efektif-Selesai terakhir (status Selesai atau tanggal < hari ini),
 * dengan jumlah terdaftar & hadir. Read-only, satu query agregat (tanpa N+1).
 * Mengembalikan list ['judul'=>, 'terdaftar'=>int, 'hadir'=>int] urut seminar terbaru.
 */
function statistik_kehadiran_seminar(mysqli $conn, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    $sql = "SELECT s.judul,
                   COUNT(r.id_registrasi) AS terdaftar,
                   COALESCE(SUM(r.status_hadir = 'Hadir'), 0) AS hadir
            FROM seminars s
            LEFT JOIN registrations r ON r.id_seminar = s.id_seminar
            WHERE s.status = 'Selesai' OR s.tanggal < CURDATE()
            GROUP BY s.id_seminar, s.judul, s.tanggal
            ORDER BY s.tanggal DESC, s.id_seminar DESC
            LIMIT " . (int) $limit;
    $res = mysqli_query($conn, $sql);
    $out = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $out[] = [
            'judul' => (string) $row['judul'],
            'terdaftar' => (int) $row['terdaftar'],
            'hadir' => (int) $row['hadir'],
        ];
    }
    return $out;
}

/**
 * Statistik 5 seminar terpopuler berdasarkan jumlah pendaftar (semua seminar). Read-only.
 * Mengembalikan list ['judul'=>, 'terdaftar'=>int] urut terbanyak.
 */
function statistik_seminar_populer(mysqli $conn, int $limit = 5): array
{
    $limit = max(1, min(50, $limit));
    $sql = "SELECT s.judul, COUNT(r.id_registrasi) AS terdaftar
            FROM seminars s
            LEFT JOIN registrations r ON r.id_seminar = s.id_seminar
            GROUP BY s.id_seminar, s.judul
            ORDER BY terdaftar DESC, s.judul ASC
            LIMIT " . (int) $limit;
    $res = mysqli_query($conn, $sql);
    $out = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $out[] = [
            'judul' => (string) $row['judul'],
            'terdaftar' => (int) $row['terdaftar'],
        ];
    }
    return $out;
}

/**
 * Pendaftar per bulan 12 bulan terakhir (berdasarkan registrations.created_at).
 * Bulan tanpa data = 0. Baris created_at NULL dikecualikan dan dilaporkan terpisah.
 * Mengembalikan ['bulan'=>[['label'=>'2026-08','nilai'=>int,'teks'=>int],...], 'tanpa_waktu'=>int].
 */
function statistik_pendaftar_bulanan(mysqli $conn, int $bulan = 12): array
{
    $bulan = max(1, min(24, $bulan));
    // Hitung jendela 12 bulan terakhir (termasuk bulan ini) di PHP agar label pasti ada walau 0.
    $labels = [];
    $nowY = (int) date('Y');
    $nowM = (int) date('n');
    for ($i = $bulan - 1; $i >= 0; $i--) {
        $ts = mktime(0, 0, 0, $nowM - $i, 1, $nowY);
        $labels[date('Y-m', $ts)] = 0;
    }
    $awal = array_key_first($labels) . '-01 00:00:00';

    $stmt = mysqli_prepare($conn, "SELECT DATE_FORMAT(created_at, '%Y-%m') AS bln, COUNT(*) AS c
        FROM registrations WHERE created_at IS NOT NULL AND created_at >= ?
        GROUP BY bln");
    mysqli_stmt_bind_param($stmt, 's', $awal);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        if (array_key_exists($row['bln'], $labels)) {
            $labels[$row['bln']] = (int) $row['c'];
        }
    }
    mysqli_stmt_close($stmt);

    $out = [];
    foreach ($labels as $bln => $n) {
        $out[] = ['label' => $bln, 'nilai' => $n, 'teks' => (string) $n];
    }

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM registrations WHERE created_at IS NULL");
    mysqli_stmt_execute($stmt);
    $tanpa = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    mysqli_stmt_close($stmt);

    return ['bulan' => $out, 'tanpa_waktu' => $tanpa];
}

/**
 * Pendaftar per HARI (agregat) untuk grafik area (F12 revisi). Read-only, satu query prepared.
 * $mulai: tanggal awal 'Y-m-d' (dihitung di PHP, zona Asia/Jakarta). Mengembalikan list
 * ['d'=>'Y-m-d', 'status_hadir'=>string, 'c'=>int] untuk created_at >= $mulai, GROUP BY hari+status.
 * Baris created_at NULL tidak ikut (dihitung terpisah oleh pemanggil bila perlu).
 */
function statistik_pendaftar_harian(mysqli $conn, string $mulai): array
{
    $stmt = mysqli_prepare($conn, "SELECT DATE(created_at) AS d, status_hadir, COUNT(*) AS c
        FROM registrations
        WHERE created_at >= ?
        GROUP BY d, status_hadir");
    mysqli_stmt_bind_param($stmt, 's', $mulai);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $out = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $out[] = [
            'd' => (string) $row['d'],
            'status_hadir' => (string) $row['status_hadir'],
            'c' => (int) $row['c'],
        ];
    }
    mysqli_stmt_close($stmt);
    return $out;
}

/**
 * Kirim email konfirmasi pendaftaran (best-effort). TIDAK pernah melempar / menggagalkan pendaftaran.
 * $sem: baris seminar (judul, pemateri, tanggal, jam_mulai, jam_selesai, lokasi).
 */
function kirim_konfirmasi_pendaftaran(mysqli $conn, int $idUser, array $sem, string $kodeTiket): void
{
    try {
        require_once __DIR__ . '/mailer.php';
        if (smtp_config() === null) {
            return; // SMTP belum diset -> lewati diam-diam.
        }
        $stmt = mysqli_prepare($conn, "SELECT nama_lengkap, email FROM users WHERE id_user = ? LIMIT 1");
        if (!$stmt) {
            return;
        }
        mysqli_stmt_bind_param($stmt, 'i', $idUser);
        mysqli_stmt_execute($stmt);
        $u = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$u || !filter_var((string) $u['email'], FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $base = (string) env_secret('ACADEMEET_BASE_URL');
        [$subjek, $isi] = isi_email_konfirmasi($base, (string) $u['nama_lengkap'], $sem, $kodeTiket);
        kirim_email((string) $u['email'], $subjek, $isi, 5);
    } catch (Throwable $ex) {
        error_log('Konfirmasi pendaftaran dilewati: ' . $ex->getMessage());
    }
}

/**
 * Proses pengingat H-1. Mengembalikan ringkasan ['terkirim'=>int,'gagal'=>int,'dilewati'=>int,'dihentikan'=>bool].
 * - Seleksi: registrasi Belum Hadir, seminar Aktif, tanggal = $besok, user notif_email=1, h1_terkirim_at NULL, max $batas.
 * - Klaim atomik agar dua proses tidak mengirim ganda.
 * - Gagal kirim -> kembalikan h1_terkirim_at = NULL (dicoba lagi nanti).
 * - 3 kegagalan berurutan -> hentikan eksekusi (SMTP dianggap mati).
 * $kirim: callable(ke, subjek, isi): bool (pengirim asli di CLI, palsu di tes).
 */
function proses_pengingat_h1(mysqli $conn, string $besok, callable $kirim, int $batas = 50): array
{
    $batas = max(1, $batas);
    $ringkas = ['terkirim' => 0, 'gagal' => 0, 'dilewati' => 0, 'dihentikan' => false];

    require_once __DIR__ . '/mailer.php';
    $base = (string) env_secret('ACADEMEET_BASE_URL');

    $sql = "SELECT r.id_registrasi, r.kode_tiket, u.nama_lengkap, u.email,
                   s.judul, s.pemateri, s.tanggal, s.jam_mulai, s.jam_selesai, s.lokasi
            FROM registrations r
            JOIN seminars s ON r.id_seminar = s.id_seminar
            JOIN users u ON r.id_user = u.id_user
            WHERE r.status_hadir = 'Belum Hadir'
              AND s.status = 'Aktif'
              AND s.tanggal = ?
              AND u.notif_email = 1
              AND r.h1_terkirim_at IS NULL
            ORDER BY r.id_registrasi ASC
            LIMIT " . (int) $batas;
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 's', $besok);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    if (empty($rows)) {
        return $ringkas;
    }

    $kegagalan_beruntun = 0;
    $klaim = mysqli_prepare($conn, "UPDATE registrations SET h1_terkirim_at = NOW() WHERE id_registrasi = ? AND h1_terkirim_at IS NULL");
    $lepas = mysqli_prepare($conn, "UPDATE registrations SET h1_terkirim_at = NULL WHERE id_registrasi = ?");

    foreach ($rows as $r) {
        // Klaim atomik: hanya lanjut bila kita yang berhasil menandai.
        mysqli_stmt_bind_param($klaim, 'i', $r['id_registrasi']);
        mysqli_stmt_execute($klaim);
        if (mysqli_stmt_affected_rows($klaim) !== 1) {
            $ringkas['dilewati']++;
            continue;
        }

        if (!filter_var((string) $r['email'], FILTER_VALIDATE_EMAIL)) {
            // Tanpa alamat valid: lepas klaim agar tidak "terkirim" palsu.
            mysqli_stmt_bind_param($lepas, 'i', $r['id_registrasi']);
            mysqli_stmt_execute($lepas);
            $ringkas['dilewati']++;
            continue;
        }

        [$subjek, $isi] = isi_email_h1($base, (string) $r['nama_lengkap'], $r);
        $ok = false;
        try {
            $ok = (bool) $kirim((string) $r['email'], $subjek, $isi);
        } catch (Throwable $ex) {
            error_log('Pengingat H-1 gagal: ' . $ex->getMessage());
            $ok = false;
        }

        if ($ok) {
            $ringkas['terkirim']++;
            $kegagalan_beruntun = 0;
        } else {
            $ringkas['gagal']++;
            $kegagalan_beruntun++;
            // Kembalikan penanda agar dicoba pada eksekusi berikutnya.
            mysqli_stmt_bind_param($lepas, 'i', $r['id_registrasi']);
            mysqli_stmt_execute($lepas);
            if ($kegagalan_beruntun >= 3) {
                $ringkas['dihentikan'] = true;
                break;
            }
        }
    }

    mysqli_stmt_close($klaim);
    mysqli_stmt_close($lepas);
    return $ringkas;
}

/**
 * Check-in manual oleh admin/panitia untuk satu registrasi di seminar halaman.
 * Aturan sama seperti scanner: seminar efektif Aktif, tanggal == hari ini, belum Hadir.
 * Cegah manipulasi: registrasi harus benar milik $idSeminarHalaman.
 * Mengembalikan [tipe, pesan].
 */
function checkin_manual(mysqli $conn, int $idRegistrasi, int $idSeminarHalaman): array
{
    if (!mysqli_begin_transaction($conn)) {
        return ['danger', 'Gagal memulai transaksi.'];
    }
    try {
        $stmt = mysqli_prepare($conn, "SELECT r.status_hadir, r.id_seminar, u.nama_lengkap, s.judul, s.status, s.tanggal
            FROM registrations r
            JOIN users u ON r.id_user = u.id_user
            JOIN seminars s ON r.id_seminar = s.id_seminar
            WHERE r.id_registrasi = ? FOR UPDATE");
        mysqli_stmt_bind_param($stmt, 'i', $idRegistrasi);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$row) {
            throw new RuntimeException('Peserta tidak ditemukan.');
        }
        if ((int) $row['id_seminar'] !== $idSeminarHalaman) {
            throw new RuntimeException('Peserta bukan bagian dari seminar ini.');
        }
        if ($row['status'] !== 'Aktif') {
            throw new RuntimeException('Seminar ini sudah ditutup. Check-in tidak dapat dilakukan.');
        }
        if ((string) $row['tanggal'] !== date('Y-m-d')) {
            throw new RuntimeException('Check-in hanya dapat dilakukan pada hari seminar.');
        }
        $jendela = checkin_window_error((string) $row['tanggal'], date('Y-m-d'));
        if ($jendela !== null) {
            throw new RuntimeException($jendela);
        }
        if ($row['status_hadir'] === 'Hadir') {
            throw new RuntimeException('Peserta ini sudah pernah di-check-in.');
        }

        $stmt = mysqli_prepare($conn, "UPDATE registrations SET status_hadir = 'Hadir', checkin_at = NOW() WHERE id_registrasi = ? AND status_hadir = 'Belum Hadir'");
        mysqli_stmt_bind_param($stmt, 'i', $idRegistrasi);
        mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        if ($affected !== 1) {
            throw new RuntimeException('Peserta ini sudah pernah di-check-in.');
        }

        mysqli_commit($conn);
        return ['success', 'Check-in berhasil: ' . $row['nama_lengkap'] . ' - ' . $row['judul']];
    } catch (Throwable $ex) {
        mysqli_rollback($conn);
        return ['danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'Gagal check-in.'];
    }
}

/**
 * Koreksi check-in (HANYA admin): kembalikan status Hadir → Belum Hadir + checkin_at NULL.
 * Syarat: seminar efektif Aktif dan tanggal >= hari ini (hari H). Seminar efektif Selesai
 * tidak bisa dikoreksi. Kuota tidak berubah. Mengembalikan [tipe, pesan].
 */
function batalkan_checkin(mysqli $conn, int $idRegistrasi, int $idSeminarHalaman): array
{
    if (!mysqli_begin_transaction($conn)) {
        return ['danger', 'Gagal memulai transaksi.'];
    }
    try {
        $stmt = mysqli_prepare($conn, "SELECT r.status_hadir, r.id_seminar, u.nama_lengkap, s.status, s.tanggal
            FROM registrations r
            JOIN users u ON r.id_user = u.id_user
            JOIN seminars s ON r.id_seminar = s.id_seminar
            WHERE r.id_registrasi = ? FOR UPDATE");
        mysqli_stmt_bind_param($stmt, 'i', $idRegistrasi);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$row) {
            throw new RuntimeException('Peserta tidak ditemukan.');
        }
        if ((int) $row['id_seminar'] !== $idSeminarHalaman) {
            throw new RuntimeException('Peserta bukan bagian dari seminar ini.');
        }
        $hariIni = date('Y-m-d');
        if (seminar_status_efektif((string) $row['status'], (string) $row['tanggal'], $hariIni) !== 'Aktif') {
            throw new RuntimeException('Seminar yang sudah selesai tidak dapat dikoreksi.');
        }
        if ((string) $row['tanggal'] < $hariIni) {
            throw new RuntimeException('Koreksi check-in hanya dapat dilakukan pada hari seminar.');
        }
        if ($row['status_hadir'] !== 'Hadir') {
            throw new RuntimeException('Peserta ini belum check-in.');
        }

        $stmt = mysqli_prepare($conn, "UPDATE registrations SET status_hadir = 'Belum Hadir', checkin_at = NULL WHERE id_registrasi = ? AND status_hadir = 'Hadir'");
        mysqli_stmt_bind_param($stmt, 'i', $idRegistrasi);
        mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        if ($affected !== 1) {
            throw new RuntimeException('Peserta ini belum check-in.');
        }

        mysqli_commit($conn);
        return ['success', 'Check-in ' . $row['nama_lengkap'] . ' dibatalkan.'];
    } catch (Throwable $ex) {
        mysqli_rollback($conn);
        return ['danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'Gagal membatalkan check-in.'];
    }
}

/**
 * Buat akun panitia. Nama <=100, email valid & <=100 belum dipakai, password sesuai aturan
 * panitia (min 10, maks 72 byte). nim/prodi NULL, notif_email 0, wajib_ganti_password 1.
 * Mengembalikan [tipe, pesan, id_user|null].
 */
function buat_panitia(mysqli $conn, string $nama, string $email, string $password): array
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
    if (strlen($password) > password_max_bytes()) {
        return ['danger', 'Password terlalu panjang (maksimal 72 byte).', null];
    }
    if (!password_ok_untuk_role('panitia', $password)) {
        return ['danger', 'Password panitia minimal 10 karakter.', null];
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
    $role = 'panitia';
    $stmt = mysqli_prepare($conn, "INSERT INTO users (nama_lengkap, email, password, role, notif_email, wajib_ganti_password) VALUES (?, ?, ?, ?, 0, 1)");
    mysqli_stmt_bind_param($stmt, 'ssss', $nama, $email, $hash, $role);
    $ok = mysqli_stmt_execute($stmt);
    if (!$ok) {
        $errno = mysqli_errno($conn);
        mysqli_stmt_close($stmt);
        return ['danger', $errno === 1062 ? 'Email sudah dipakai akun lain.' : 'Gagal membuat akun panitia.', null];
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return ['success', 'Akun panitia berhasil dibuat. Minta panitia mengganti password saat login pertama.', $id];
}

/**
 * Reset password akun panitia (HANYA role panitia). password sesuai aturan panitia.
 * Mengisi wajib_ganti_password = 1 dan menaikkan auth_version. Mengembalikan [tipe, pesan].
 */
function reset_password_panitia(mysqli $conn, int $idPanitia, string $passwordBaru): array
{
    if (strlen($passwordBaru) > password_max_bytes()) {
        return ['danger', 'Password terlalu panjang (maksimal 72 byte).'];
    }
    if (!password_ok_untuk_role('panitia', $passwordBaru)) {
        return ['danger', 'Password panitia minimal 10 karakter.'];
    }
    $hash = password_hash($passwordBaru, PASSWORD_DEFAULT);
    $stmt = mysqli_prepare($conn, "UPDATE users SET password = ?, wajib_ganti_password = 1, auth_version = auth_version + 1 WHERE id_user = ? AND role = 'panitia'");
    mysqli_stmt_bind_param($stmt, 'si', $hash, $idPanitia);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok || $affected !== 1) {
        return ['danger', 'Akun panitia tidak ditemukan.'];
    }
    return ['success', 'Password panitia berhasil direset. Panitia wajib menggantinya saat login.'];
}

/** Hapus akun panitia (HANYA role panitia). Mengembalikan [tipe, pesan]. */
function hapus_panitia(mysqli $conn, int $idPanitia): array
{
    $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id_user = ? AND role = 'panitia'");
    mysqli_stmt_bind_param($stmt, 'i', $idPanitia);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok || $affected !== 1) {
        return ['danger', 'Akun panitia tidak ditemukan.'];
    }
    return ['success', 'Akun panitia berhasil dihapus.'];
}

/**
 * Ganti password wajib (semua role login). Password baru harus valid untuk role,
 * berbeda dari password sekarang, dan maksimal 72 byte. Menurunkan wajib_ganti_password
 * dan menaikkan auth_version; pemanggil memperbarui sesi aktif. Mengembalikan [tipe, pesan].
 */
function ganti_password_wajib(mysqli $conn, int $idUser, string $role, string $passwordBaru, string $konfirmasi): array
{
    if (strlen($passwordBaru) > password_max_bytes()) {
        return ['danger', 'Password terlalu panjang (maksimal 72 byte).'];
    }
    if (!password_ok_untuk_role($role, $passwordBaru)) {
        return ['danger', 'Password minimal ' . password_min_untuk_role($role) . ' karakter.'];
    }
    if ($passwordBaru !== $konfirmasi) {
        return ['danger', 'Konfirmasi password tidak sama.'];
    }

    $stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id_user = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $idUser);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row) {
        return ['danger', 'Akun tidak ditemukan.'];
    }
    if (password_verify($passwordBaru, (string) $row['password'])) {
        return ['danger', 'Password baru harus berbeda dari password sekarang.'];
    }

    $hash = password_hash($passwordBaru, PASSWORD_DEFAULT);
    $stmt = mysqli_prepare($conn, "UPDATE users SET password = ?, wajib_ganti_password = 0, auth_version = auth_version + 1 WHERE id_user = ?");
    mysqli_stmt_bind_param($stmt, 'si', $hash, $idUser);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['danger', 'Gagal menyimpan password baru.'];
    }
    mysqli_stmt_close($stmt);
    return ['success', 'Password berhasil diganti.'];
}

/**
 * Pratinjau impor CSV (READ-ONLY). Parse + validasi baris (murni) + cek duplikat
 * terhadap DB (NIM & email case-insensitive) dalam batch <= 200. Tidak menulis apa pun.
 * Mengembalikan ['valid'=>[['baris','nim','nama','prodi','email'],...],
 *                   'kesalahan'=>[['baris','kolom','alasan'],...]].
 */
function pratinjau_impor_csv(mysqli $conn, string $isi): array
{
    [$baris, $kesalahan] = parse_csv_mahasiswa($isi);
    [$siap, $err2] = validasi_baris_impor($baris, prodi_terdaftar());
    $kesalahan = array_merge($kesalahan, $err2);

    if ($siap !== []) {
        $nimAda = [];
        $emailAda = [];
        foreach (array_chunk($siap, 200) as $chunk) {
            $nims = array_column($chunk, 'nim');
            $emails = array_map(static fn($r) => strtolower((string) $r['email']), $chunk);

            $ph = implode(',', array_fill(0, count($nims), '?'));
            $stmt = mysqli_prepare($conn, "SELECT LOWER(nim) AS n FROM users WHERE LOWER(nim) IN ($ph)");
            mysqli_stmt_bind_param($stmt, str_repeat('s', count($nims)), ...$nims);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($r = mysqli_fetch_assoc($res)) { $nimAda[$r['n']] = true; }
            mysqli_stmt_close($stmt);

            $ph2 = implode(',', array_fill(0, count($emails), '?'));
            $stmt = mysqli_prepare($conn, "SELECT LOWER(email) AS e FROM users WHERE LOWER(email) IN ($ph2)");
            mysqli_stmt_bind_param($stmt, str_repeat('s', count($emails)), ...$emails);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($r = mysqli_fetch_assoc($res)) { $emailAda[$r['e']] = true; }
            mysqli_stmt_close($stmt);
        }
        $bersih = [];
        foreach ($siap as $r) {
            if (isset($nimAda[strtolower($r['nim'])])) {
                $kesalahan[] = ['baris' => $r['baris'], 'kolom' => 'nim', 'alasan' => 'NIM sudah terdaftar di sistem.'];
                continue;
            }
            if (isset($emailAda[strtolower($r['email'])])) {
                $kesalahan[] = ['baris' => $r['baris'], 'kolom' => 'email', 'alasan' => 'Email sudah terdaftar di sistem.'];
                continue;
            }
            $bersih[] = $r;
        }
        $siap = $bersih;
    }

    usort($kesalahan, static fn($a, $b) => ($a['baris'] ?? 0) <=> ($b['baris'] ?? 0));
    return ['valid' => $siap, 'kesalahan' => $kesalahan];
}

/**
 * Terapkan impor: buat akun mahasiswa dari baris valid dalam SATU transaksi.
 * Satu password_hash dihitung SEKALI untuk semua baris (500 bcrypt terlalu lambat).
 * $barisValid: dari pratinjau (['baris','nim','nama','prodi','email']).
 * Mengembalikan ['success'|'danger', pesan, int jumlah].
 */
function terapkan_impor(mysqli $conn, array $barisValid, string $passwordAwal): array
{
    if ($barisValid === []) {
        return ['danger', 'Tidak ada baris valid untuk diimpor.', 0];
    }
    if (strlen($passwordAwal) > password_max_bytes()) {
        return ['danger', 'Password terlalu panjang (maksimal 72 byte).', 0];
    }
    if (!password_ok_untuk_role('mahasiswa', $passwordAwal)) {
        return ['danger', 'Password awal minimal 8 karakter.', 0];
    }

    $hash = password_hash($passwordAwal, PASSWORD_DEFAULT);
    $role = 'mahasiswa';

    if (!mysqli_begin_transaction($conn)) {
        return ['danger', 'Gagal memulai transaksi.', 0];
    }
    $jumlah = 0;
    try {
        $stmt = mysqli_prepare($conn, "INSERT INTO users (nama_lengkap, nim, prodi, email, password, role, wajib_ganti_password) VALUES (?, ?, ?, ?, ?, ?, 1)");
        foreach ($barisValid as $r) {
            $nama = (string) $r['nama'];
            $nim = (string) $r['nim'];
            $prodi = (string) $r['prodi'];
            $email = (string) $r['email'];
            mysqli_stmt_bind_param($stmt, 'ssssss', $nama, $nim, $prodi, $email, $hash, $role);
            if (!mysqli_stmt_execute($stmt)) {
                throw new RuntimeException('INSERT:' . mysqli_errno($conn));
            }
            $jumlah++;
        }
        mysqli_stmt_close($stmt);
        mysqli_commit($conn);
        return ['success', $jumlah . ' akun dibuat.', $jumlah];
    } catch (Throwable $ex) {
        mysqli_rollback($conn);
        $pesan = str_contains($ex->getMessage(), 'INSERT:1062')
            ? 'Terjadi duplikasi saat menulis, tidak ada akun yang dibuat. Unggah ulang.'
            : 'Gagal membuat akun. Tidak ada akun yang dibuat.';
        return ['danger', $pesan, 0];
    }
}
