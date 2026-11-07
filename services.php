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
            $stmt = mysqli_prepare($conn, "UPDATE seminars SET judul = ?, id_pemateri = ?, pemateri = ?, tanggal = ?, kuota_total = ?, kuota = ?, poster = ? WHERE id_seminar = ?");
            mysqli_stmt_bind_param($stmt, 'sissiisi', $judul, $id_pemateri, $nama_pemateri, $tanggal, $kuota_total, $sisa, $poster, $id_seminar);
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE seminars SET judul = ?, id_pemateri = ?, pemateri = ?, tanggal = ?, kuota_total = ?, kuota = ? WHERE id_seminar = ?");
            mysqli_stmt_bind_param($stmt, 'sissiii', $judul, $id_pemateri, $nama_pemateri, $tanggal, $kuota_total, $sisa, $id_seminar);
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
