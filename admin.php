<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/koneksi.php';
require_role(['admin', 'panitia']);

$allowed_pages = ['dashboard', 'tambah', 'edit_seminar', 'scan', 'mahasiswa', 'detail_mahasiswa', 'pemateri', 'tambah_pemateri', 'detail_pemateri', 'laporan', 'peserta', 'panitia', 'impor_mahasiswa'];
$page = (string) ($_GET['page'] ?? 'dashboard');
if (!in_array($page, $allowed_pages, true)) {
    $page = 'dashboard';
}

$role_aktif = (string) ($_SESSION['role'] ?? '');

// ==========================================================
// DEFAULT DENY untuk panitia: sebelum handler POST/GET apa pun.
// Halaman & aksi POST yang diizinkan ditentukan oleh helper murni.
// ==========================================================
if ($role_aktif === 'panitia') {
    $aksi_post = '';
    foreach (['checkin', 'checkin_manual', 'batalkan_checkin', 'tambah', 'selesai', 'edit_seminar', 'hapus', 'tambah_pemateri', 'hapus_pemateri', 'hapus_mahasiswa', 'reset_password', 'tambah_panitia', 'reset_password_panitia', 'hapus_panitia', 'impor_pratinjau', 'impor_terapkan'] as $kandidat) {
        if (isset($_POST[$kandidat])) {
            $aksi_post = $kandidat;
            break;
        }
    }
    $boleh_halaman = role_boleh_halaman($role_aktif, $page);
    $boleh_aksi = ($aksi_post === '') || role_boleh_aksi($role_aktif, $aksi_post);
    if (!$boleh_halaman || !$boleh_aksi) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Akses Ditolak - AcadeMeet</title>'
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">'
            . '<link rel="stylesheet" href="style.css?v=2.13"></head><body class="auth-wrapper">'
            . '<div class="container text-center"><div class="auth-card p-4 p-sm-5 mx-auto" style="max-width:480px;">'
            . '<div class="auth-brand-icon mx-auto mb-3"><i class="bi bi-shield-exclamation"></i></div>'
            . '<h1 class="h4 fw-bold text-dark mb-2">Akses ditolak</h1>'
            . '<p class="text-muted small mb-4">Anda tidak memiliki izin untuk membuka halaman ini.</p>'
            . '<a href="admin.php?page=scan" class="btn btn-primary"><i class="bi bi-qr-code-scan me-1"></i> Ke Scanner Check-in</a>'
            . '</div></div></body></html>';
        exit;
    }
}

// Halaman detail butuh id valid sebelum HTML dikirim, supaya redirect benar-benar 302.
if ($page === 'detail_pemateri' || $page === 'detail_mahasiswa') {
    $detail_id = (int) ($_GET['id'] ?? 0);
    $tabel = $page === 'detail_pemateri' ? 'pemateri' : 'users';
    $kolom = $page === 'detail_pemateri' ? 'id_pemateri' : 'id_user';
    $syarat = $page === 'detail_mahasiswa' ? " AND role = 'mahasiswa'" : '';
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM $tabel WHERE $kolom = ?$syarat LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $detail_id);
    mysqli_stmt_execute($stmt);
    $ada = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$ada) {
        redirect('admin.php?page=' . ($page === 'detail_pemateri' ? 'pemateri' : 'mahasiswa'));
    }
}

// Judul halaman untuk <title> dan topbar.
$judul_halaman = [
    'dashboard' => 'Dashboard',
    'tambah' => 'Buat Seminar',
    'edit_seminar' => 'Edit Seminar',
    'scan' => 'Scanner Check-in',
    'mahasiswa' => 'Data Mahasiswa',
    'detail_mahasiswa' => 'Detail Mahasiswa',
    'pemateri' => 'Data Pemateri',
    'tambah_pemateri' => 'Tambah Pemateri',
    'detail_pemateri' => 'Detail Pemateri',
    'laporan' => 'Laporan',
    'peserta' => 'Daftar Peserta',
    'panitia' => 'Data Panitia',
    'impor_mahasiswa' => 'Impor Mahasiswa',
][$page] ?? 'Dashboard';

/** Check-in aman dari double submit. Mengembalikan [tipe, pesan]. */
function handleCheckin(mysqli $conn, string $kode): array
{
    $kode = trim($kode);
    $stmt = mysqli_prepare($conn, "SELECT r.status_hadir, u.nama_lengkap, s.judul, s.status, s.tanggal
        FROM registrations r
        JOIN users u ON r.id_user = u.id_user
        JOIN seminars s ON r.id_seminar = s.id_seminar
        WHERE r.kode_tiket = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $kode);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        return ['danger', 'Kode tiket tidak ditemukan dalam sistem.'];
    }
    if ($row['status'] !== 'Aktif') {
        return ['warning', 'Seminar ini sudah ditutup. Check-in tidak dapat dilakukan.'];
    }
    if ($row['tanggal'] < date('Y-m-d')) {
        return ['warning', 'Jadwal seminar sudah lewat.'];
    }
    $jendela = checkin_window_error((string) $row['tanggal'], date('Y-m-d'));
    if ($jendela !== null) {
        return ['warning', $jendela];
    }
    if ($row['status_hadir'] === 'Hadir') {
        return ['warning', 'Tiket ini sudah pernah di-check-in sebelumnya.'];
    }

    $stmt = mysqli_prepare($conn, "UPDATE registrations SET status_hadir = 'Hadir', checkin_at = NOW() WHERE kode_tiket = ? AND status_hadir = 'Belum Hadir'");
    mysqli_stmt_bind_param($stmt, 's', $kode);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($affected !== 1) {
        return ['warning', 'Tiket ini sudah pernah di-check-in sebelumnya.'];
    }
    return ['success', 'Check-in berhasil: ' . $row['nama_lengkap'] . ' - ' . $row['judul']];
}

// ==========================================
// TAMBAH SEMINAR
// ==========================================
if (isset($_POST['tambah'])) {
    csrf_check();
    $judul = trim((string) ($_POST['judul'] ?? ''));
    $id_pemateri = (int) ($_POST['id_pemateri'] ?? 0);
    $tanggal = (string) ($_POST['tanggal'] ?? '');
    $kuota = (int) ($_POST['kuota'] ?? 0);
    $poster = null;
    $tujuan = 'admin.php?page=tambah';

    try {
        $panjang_judul = function_exists('mb_strlen') ? mb_strlen($judul) : strlen($judul);
        if ($judul === '' || $id_pemateri <= 0 || $kuota < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            throw new RuntimeException('Data seminar belum lengkap atau tidak valid.');
        }
        if ($panjang_judul > 200) {
            throw new RuntimeException('Judul seminar maksimal 200 karakter.');
        }
        if ($tanggal < date('Y-m-d')) {
            throw new RuntimeException('Tanggal seminar tidak boleh di masa lalu.');
        }
        $ttd_nama = trim(buang_karakter_kontrol((string) ($_POST['ttd_nama'] ?? '')));
        $ttd_jabatan = trim(buang_karakter_kontrol((string) ($_POST['ttd_jabatan'] ?? '')));
        $err_ttd = validasi_ttd($ttd_nama, $ttd_jabatan);
        if ($err_ttd !== '') {
            throw new RuntimeException($err_ttd);
        }
        $ttd_nama = $ttd_nama === '' ? null : $ttd_nama;
        $ttd_jabatan = $ttd_jabatan === '' ? null : $ttd_jabatan;
        [$err_detail, $detail] = validasi_detail_seminar([
            'jam_mulai'   => $_POST['jam_mulai'] ?? null,
            'jam_selesai' => $_POST['jam_selesai'] ?? null,
            'lokasi'      => $_POST['lokasi'] ?? null,
            'link_online' => $_POST['link_online'] ?? null,
            'kategori'    => $_POST['kategori'] ?? null,
            'deskripsi'   => $_POST['deskripsi'] ?? null,
        ]);
        if ($err_detail !== '') {
            throw new RuntimeException($err_detail);
        }
        $stmt = mysqli_prepare($conn, "SELECT nama FROM pemateri WHERE id_pemateri = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $id_pemateri);
        mysqli_stmt_execute($stmt);
        $pmt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$pmt) {
            throw new RuntimeException('Pemateri tidak ditemukan.');
        }

        $poster = upload_image($_FILES['poster'] ?? [], 'posters', 5000000);
        $nama_pemateri = $pmt['nama'];
        $status = 'Aktif';
        $stmt = mysqli_prepare($conn, "INSERT INTO seminars (judul, id_pemateri, pemateri, tanggal, jam_mulai, jam_selesai, lokasi, link_online, kategori, deskripsi, kuota, kuota_total, status, ttd_nama, ttd_jabatan, poster) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'sissssssssiissss', $judul, $id_pemateri, $nama_pemateri, $tanggal, $detail['jam_mulai'], $detail['jam_selesai'], $detail['lokasi'], $detail['link_online'], $detail['kategori'], $detail['deskripsi'], $kuota, $kuota, $status, $ttd_nama, $ttd_jabatan, $poster);
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('Gagal menyimpan seminar.');
        }
        mysqli_stmt_close($stmt);
        flash_set('success', 'Seminar baru berhasil ditambahkan.');
        $tujuan = 'admin.php?page=dashboard';
    } catch (Throwable $ex) {
        if ($poster && file_exists("uploads/posters/" . $poster)) {
            @unlink("uploads/posters/" . $poster);
        }
        $_SESSION['_old'] = [
            'judul' => $judul,
            'id_pemateri' => (string) $id_pemateri,
            'tanggal' => $tanggal,
            'kuota' => (string) $kuota,
            'jam_mulai' => (string) ($_POST['jam_mulai'] ?? ''),
            'jam_selesai' => (string) ($_POST['jam_selesai'] ?? ''),
            'lokasi' => (string) ($_POST['lokasi'] ?? ''),
            'link_online' => (string) ($_POST['link_online'] ?? ''),
            'kategori' => (string) ($_POST['kategori'] ?? ''),
            'deskripsi' => (string) ($_POST['deskripsi'] ?? ''),
            'ttd_nama' => (string) ($_POST['ttd_nama'] ?? ''),
            'ttd_jabatan' => (string) ($_POST['ttd_jabatan'] ?? ''),
        ];
        flash_set('danger', $ex->getMessage());
    }
    redirect($tujuan);
}

// ==========================================
// TANDAI SEMINAR SELESAI
// ==========================================
if (isset($_POST['selesai'])) {
    csrf_check();
    $id_sem = (int) ($_POST['id_seminar'] ?? 0);
    $stmt = mysqli_prepare($conn, "UPDATE seminars SET status = 'Selesai' WHERE id_seminar = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id_sem);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    flash_set($ok ? 'success' : 'danger', $ok ? 'Seminar ditandai sebagai selesai.' : 'Gagal mengubah status seminar.');
    redirect('admin.php?page=dashboard');
}

// ==========================================
// EDIT SEMINAR
// ==========================================
if (isset($_POST['edit_seminar'])) {
    csrf_check();
    $id_seminar = (int) ($_POST['id_seminar'] ?? 0);
    $judul = trim((string) ($_POST['judul'] ?? ''));
    $id_pemateri = (int) ($_POST['id_pemateri'] ?? 0);
    $tanggal = (string) ($_POST['tanggal'] ?? '');
    $kuota_total = (int) ($_POST['kuota_total'] ?? 0);
    $tujuan = 'admin.php?page=edit_seminar&id=' . $id_seminar;
    $poster_baru = null;
    $poster_lama = null;

    try {
        $row_sem = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status, tanggal, poster FROM seminars WHERE id_seminar = $id_seminar"));
        if (!$row_sem) {
            throw new RuntimeException('Seminar tidak ditemukan.');
        }
        if (seminar_status_efektif((string) $row_sem['status'], (string) $row_sem['tanggal'], date('Y-m-d')) !== 'Aktif') {
            flash_set('danger', 'Seminar yang sudah selesai tidak dapat diedit.');
            redirect('admin.php?page=dashboard');
        }
        $poster_lama = $row_sem['poster'];

        // Validasi form dasar sebelum upload.
        $err = '';
        if ($judul === '') {
            $err = 'Judul seminar wajib diisi.';
        } elseif (str_len($judul) > 200) {
            $err = 'Judul seminar maksimal 200 karakter.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            $err = 'Format tanggal tidak valid.';
        } elseif ($kuota_total < 1) {
            $err = 'Kuota total minimal 1.';
        } elseif ($id_pemateri <= 0) {
            $err = 'Pemateri wajib dipilih.';
        }
        if ($err !== '') {
            throw new RuntimeException($err);
        }
        [$err_detail, $detail] = validasi_detail_seminar([
            'jam_mulai'   => $_POST['jam_mulai'] ?? null,
            'jam_selesai' => $_POST['jam_selesai'] ?? null,
            'lokasi'      => $_POST['lokasi'] ?? null,
            'link_online' => $_POST['link_online'] ?? null,
            'kategori'    => $_POST['kategori'] ?? null,
            'deskripsi'   => $_POST['deskripsi'] ?? null,
        ]);
        if ($err_detail !== '') {
            throw new RuntimeException($err_detail);
        }

        $poster_baru = upload_image($_FILES['poster'] ?? [], 'posters', 5000000);

        [$type, $message, $commit_ok] = ubah_seminar($conn, $id_seminar, [
            'judul' => $judul,
            'id_pemateri' => $id_pemateri,
            'tanggal' => $tanggal,
            'kuota_total' => $kuota_total,
            'jam_mulai' => $detail['jam_mulai'],
            'jam_selesai' => $detail['jam_selesai'],
            'lokasi' => $detail['lokasi'],
            'link_online' => $detail['link_online'],
            'kategori' => $detail['kategori'],
            'deskripsi' => $detail['deskripsi'],
            'ttd_nama' => (string) ($_POST['ttd_nama'] ?? ''),
            'ttd_jabatan' => (string) ($_POST['ttd_jabatan'] ?? ''),
        ], $poster_baru);

        if ($type === 'success') {
            if ($poster_baru && !empty($poster_lama) && file_exists("uploads/posters/" . $poster_lama)) {
                @unlink("uploads/posters/" . $poster_lama);
            }
            flash_set('success', $message);
            $tujuan = 'admin.php?page=dashboard';
        } else {
            throw new RuntimeException($message);
        }
    } catch (Throwable $ex) {
        if ($poster_baru && file_exists("uploads/posters/" . $poster_baru)) {
            @unlink("uploads/posters/" . $poster_baru);
        }
        $_SESSION['_old'] = [
            'judul' => $judul,
            'id_pemateri' => (string) $id_pemateri,
            'tanggal' => $tanggal,
            'kuota_total' => (string) $kuota_total,
            'jam_mulai' => (string) ($_POST['jam_mulai'] ?? ''),
            'jam_selesai' => (string) ($_POST['jam_selesai'] ?? ''),
            'lokasi' => (string) ($_POST['lokasi'] ?? ''),
            'link_online' => (string) ($_POST['link_online'] ?? ''),
            'kategori' => (string) ($_POST['kategori'] ?? ''),
            'deskripsi' => (string) ($_POST['deskripsi'] ?? ''),
            'ttd_nama' => (string) ($_POST['ttd_nama'] ?? ''),
            'ttd_jabatan' => (string) ($_POST['ttd_jabatan'] ?? ''),
        ];
        flash_set('danger', $ex->getMessage());
    }
    redirect($tujuan);
}

// ==========================================
// CHECK-IN (manual & hasil scan kamera)
// ==========================================
if (isset($_POST['checkin'])) {
    csrf_check();
    [$type, $message] = handleCheckin($conn, (string) ($_POST['kode_tiket'] ?? ''));
    flash_set($type, $message);
    redirect('admin.php?page=scan');
}

// ==========================================
// HAPUS SEMINAR
// ==========================================
if (isset($_POST['hapus'])) {
    csrf_check();
    $id_sem = (int) ($_POST['id_seminar'] ?? 0);
    $poster = null;

    try {
        if (!mysqli_begin_transaction($conn)) {
            throw new RuntimeException('Gagal memulai transaksi.');
        }
        $stmt = mysqli_prepare($conn, "SELECT poster FROM seminars WHERE id_seminar = ? FOR UPDATE");
        mysqli_stmt_bind_param($stmt, 'i', $id_sem);
        mysqli_stmt_execute($stmt);
        $sem = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$sem) {
            throw new RuntimeException('Seminar tidak ditemukan.');
        }
        $poster = $sem['poster'];

        $stmt = mysqli_prepare($conn, "DELETE FROM registrations WHERE id_seminar = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id_sem);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($conn, "DELETE FROM seminars WHERE id_seminar = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id_sem);
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('Gagal menghapus seminar.');
        }
        mysqli_stmt_close($stmt);

        mysqli_commit($conn);
        if ($poster && file_exists("uploads/posters/" . $poster)) {
            @unlink("uploads/posters/" . $poster);
        }
        flash_set('success', 'Seminar berhasil dihapus beserta seluruh data pendaftar.');
    } catch (Throwable $ex) {
        mysqli_rollback($conn);
        flash_set('danger', $ex->getMessage());
    }
    redirect('admin.php?page=dashboard');
}

// ==========================================
// TAMBAH PEMATERI
// ==========================================
if (isset($_POST['tambah_pemateri'])) {
    csrf_check();
    $nama = trim((string) ($_POST['nama'] ?? ''));
    $keahlian = trim((string) ($_POST['keahlian'] ?? ''));
    $institusi = trim((string) ($_POST['institusi'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $no_hp = trim((string) ($_POST['no_hp'] ?? ''));
    $bio = trim((string) ($_POST['bio'] ?? ''));
    $foto = null;
    $tujuan = 'admin.php?page=tambah_pemateri';

    try {
        $panjang = static function (string $teks): int {
            return function_exists('mb_strlen') ? mb_strlen($teks) : strlen($teks);
        };
        if ($nama === '') {
            throw new RuntimeException('Nama pemateri wajib diisi.');
        }
        if ($panjang($nama) > 150 || $panjang($keahlian) > 150 || $panjang($institusi) > 150) {
            throw new RuntimeException('Nama, keahlian, dan institusi maksimal 150 karakter.');
        }
        if ($panjang($email) > 100) {
            throw new RuntimeException('Email maksimal 100 karakter.');
        }
        if ($panjang($no_hp) > 30) {
            throw new RuntimeException('Nomor HP maksimal 30 karakter.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Format email pemateri tidak valid.');
        }
        $foto = upload_image($_FILES['foto'] ?? [], 'pemateri', 2000000);
        $stmt = mysqli_prepare($conn, "INSERT INTO pemateri (nama, keahlian, institusi, email, no_hp, foto, bio) VALUES (?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'sssssss', $nama, $keahlian, $institusi, $email, $no_hp, $foto, $bio);
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('Gagal menyimpan pemateri.');
        }
        mysqli_stmt_close($stmt);
        flash_set('success', 'Pemateri baru berhasil ditambahkan.');
        $tujuan = 'admin.php?page=pemateri';
    } catch (Throwable $ex) {
        if ($foto && file_exists("uploads/pemateri/" . $foto)) {
            @unlink("uploads/pemateri/" . $foto);
        }
        $_SESSION['_old'] = [
            'nama' => $nama,
            'keahlian' => $keahlian,
            'institusi' => $institusi,
            'email' => $email,
            'no_hp' => $no_hp,
            'bio' => $bio,
        ];
        flash_set('danger', $ex->getMessage());
    }
    redirect($tujuan);
}

// ==========================================
// HAPUS PEMATERI (beserta seminar & registrasinya)
// ==========================================
if (isset($_POST['hapus_pemateri'])) {
    csrf_check();
    $id_pem = (int) ($_POST['id_pemateri'] ?? 0);
    $foto = null;
    $posters = [];

    try {
        if (!mysqli_begin_transaction($conn)) {
            throw new RuntimeException('Gagal memulai transaksi.');
        }
        $stmt = mysqli_prepare($conn, "SELECT foto FROM pemateri WHERE id_pemateri = ? FOR UPDATE");
        mysqli_stmt_bind_param($stmt, 'i', $id_pem);
        mysqli_stmt_execute($stmt);
        $pem = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$pem) {
            throw new RuntimeException('Pemateri tidak ditemukan.');
        }
        $foto = $pem['foto'];

        $sem_list = mysqli_query($conn, "SELECT poster FROM seminars WHERE id_pemateri = $id_pem");
        while ($s = mysqli_fetch_assoc($sem_list)) {
            if (!empty($s['poster'])) {
                $posters[] = $s['poster'];
            }
        }

        $stmt = mysqli_prepare($conn, "DELETE r FROM registrations r INNER JOIN seminars s ON r.id_seminar = s.id_seminar WHERE s.id_pemateri = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id_pem);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($conn, "DELETE FROM seminars WHERE id_pemateri = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id_pem);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($conn, "DELETE FROM pemateri WHERE id_pemateri = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id_pem);
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('Gagal menghapus pemateri.');
        }
        mysqli_stmt_close($stmt);

        mysqli_commit($conn);
        if ($foto && file_exists("uploads/pemateri/" . $foto)) {
            @unlink("uploads/pemateri/" . $foto);
        }
        foreach ($posters as $p) {
            if (file_exists("uploads/posters/" . $p)) {
                @unlink("uploads/posters/" . $p);
            }
        }
        flash_set('success', 'Pemateri berhasil dihapus beserta semua seminar terkait.');
    } catch (Throwable $ex) {
        mysqli_rollback($conn);
        flash_set('danger', $ex->getMessage());
    }
    redirect('admin.php?page=pemateri');
}

// ==========================================
// HAPUS MAHASISWA
// ==========================================
if (isset($_POST['hapus_mahasiswa'])) {
    csrf_check();
    $id_mhs = (int) ($_POST['id_user'] ?? 0);
    $foto = null;

    try {
        if (!mysqli_begin_transaction($conn)) {
            throw new RuntimeException('Gagal memulai transaksi.');
        }
        $stmt = mysqli_prepare($conn, "SELECT foto, role FROM users WHERE id_user = ? FOR UPDATE");
        mysqli_stmt_bind_param($stmt, 'i', $id_mhs);
        mysqli_stmt_execute($stmt);
        $mhs = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$mhs || $mhs['role'] !== 'mahasiswa') {
            throw new RuntimeException('Data mahasiswa tidak ditemukan.');
        }
        $foto = $mhs['foto'];

        // Kembalikan kuota seminar yang tiketnya dihapus.
        $regs = mysqli_query($conn, "SELECT id_seminar, COUNT(*) AS jml FROM registrations WHERE id_user = $id_mhs GROUP BY id_seminar");
        while ($r = mysqli_fetch_assoc($regs)) {
            $sid = (int) $r['id_seminar'];
            $jml = (int) $r['jml'];
            mysqli_query($conn, "UPDATE seminars SET kuota = LEAST(kuota + $jml, COALESCE(kuota_total, kuota + $jml)) WHERE id_seminar = $sid");
        }

        $stmt = mysqli_prepare($conn, "DELETE FROM registrations WHERE id_user = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id_mhs);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id_user = ? AND role = 'mahasiswa'");
        mysqli_stmt_bind_param($stmt, 'i', $id_mhs);
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('Gagal menghapus mahasiswa.');
        }
        mysqli_stmt_close($stmt);

        mysqli_commit($conn);
        if ($foto && file_exists("uploads/profiles/" . $foto)) {
            @unlink("uploads/profiles/" . $foto);
        }
        flash_set('success', 'Data mahasiswa beserta seluruh tiket pendaftarannya berhasil dihapus.');
    } catch (Throwable $ex) {
        mysqli_rollback($conn);
        flash_set('danger', $ex->getMessage());
    }
    redirect('admin.php?page=mahasiswa');
}

// ==========================================
// RESET PASSWORD MAHASISWA (oleh admin)
// ==========================================
if (isset($_POST['reset_password'])) {
    csrf_check();
    $id_mhs = (int) ($_POST['id_user'] ?? 0);
    $baru = (string) ($_POST['password_baru'] ?? '');

    if (strlen($baru) > password_max_bytes()) {
        flash_set('danger', 'Password terlalu panjang (maksimal 72 byte).');
    } elseif (!password_ok_untuk_role('mahasiswa', $baru)) {
        flash_set('danger', 'Password baru minimal 8 karakter.');
    } else {
        $hash = password_hash($baru, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, "UPDATE users SET password = ?, auth_version = auth_version + 1 WHERE id_user = ? AND role = 'mahasiswa'");
        mysqli_stmt_bind_param($stmt, 'si', $hash, $id_mhs);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        flash_set($ok ? 'success' : 'danger', $ok ? 'Password mahasiswa berhasil direset.' : 'Gagal mereset password.');
    }
    redirect('admin.php?page=detail_mahasiswa&id=' . $id_mhs);
}

// ==========================================
// CHECK-IN MANUAL (admin & panitia) dari daftar peserta
// ==========================================
if (isset($_POST['checkin_manual'])) {
    csrf_check();
    $id_reg = (int) ($_POST['id_registrasi'] ?? 0);
    $id_sem = (int) ($_POST['id_seminar'] ?? 0);
    [$type, $message] = checkin_manual($conn, $id_reg, $id_sem);
    flash_set($type, $message);
    redirect('admin.php?page=peserta&id=' . $id_sem);
}

// ==========================================
// BATALKAN CHECK-IN (HANYA admin) dari daftar peserta
// ==========================================
if (isset($_POST['batalkan_checkin'])) {
    csrf_check();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        flash_set('danger', 'Hanya admin yang dapat membatalkan check-in.');
        redirect('admin.php?page=peserta');
    }
    $id_reg = (int) ($_POST['id_registrasi'] ?? 0);
    $id_sem = (int) ($_POST['id_seminar'] ?? 0);
    [$type, $message] = batalkan_checkin($conn, $id_reg, $id_sem);
    flash_set($type, $message);
    redirect('admin.php?page=peserta&id=' . $id_sem);
}

// ==========================================
// TAMBAH PANITIA (HANYA admin)
// ==========================================
if (isset($_POST['tambah_panitia'])) {
    csrf_check();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Akses ditolak.');
    }
    $nama = (string) ($_POST['nama'] ?? '');
    $email = (string) ($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    [$type, $message] = buat_panitia($conn, $nama, $email, $password);
    flash_set($type, $message);
    redirect('admin.php?page=panitia');
}

// ==========================================
// RESET PASSWORD PANITIA (HANYA admin)
// ==========================================
if (isset($_POST['reset_password_panitia'])) {
    csrf_check();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Akses ditolak.');
    }
    $id_pan = (int) ($_POST['id_user'] ?? 0);
    $baru = (string) ($_POST['password_baru'] ?? '');
    [$type, $message] = reset_password_panitia($conn, $id_pan, $baru);
    flash_set($type, $message);
    redirect('admin.php?page=panitia');
}

// ==========================================
// HAPUS PANITIA (HANYA admin)
// ==========================================
if (isset($_POST['hapus_panitia'])) {
    csrf_check();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Akses ditolak.');
    }
    $id_pan = (int) ($_POST['id_user'] ?? 0);
    [$type, $message] = hapus_panitia($conn, $id_pan);
    flash_set($type, $message);
    redirect('admin.php?page=panitia');
}

// ==========================================
// IMPOR MAHASISWA CSV (HANYA admin) — LANGKAH 1: PRATINJAU (tanpa tulis DB)
// ==========================================
if (isset($_POST['impor_pratinjau'])) {
    csrf_check();
    if ($role_aktif !== 'admin') {
        http_response_code(403);
        exit('Akses ditolak.');
    }
    $hasil = null;
    $pesan = '';
    try {
        $berkas = $_FILES['csv'] ?? null;
        if (!$berkas || !isset($berkas['tmp_name']) || !is_uploaded_file($berkas['tmp_name'])) {
            throw new RuntimeException('Pilih berkas CSV untuk diunggah.');
        }
        if ((int) $berkas['size'] > 1024 * 1024) {
            throw new RuntimeException('Ukuran berkas maksimal 1 MB.');
        }
        $isi = (string) file_get_contents($berkas['tmp_name']);
        $hasil = pratinjau_impor_csv($conn, $isi);

        // Bersihkan token pratinjau lama.
        unset($_SESSION['impor_token'], $_SESSION['impor_valid'], $_SESSION['impor_kedaluwarsa']);

        if ($hasil['valid'] !== [] && $hasil['kesalahan'] === []) {
            // Hanya simpan bila BERSIH total: all-or-nothing pada baris valid.
            $_SESSION['impor_valid'] = $hasil['valid'];
            $_SESSION['impor_token'] = bin2hex(random_bytes(16));
            $_SESSION['impor_kedaluwarsa'] = time() + 900;
            $pesan = count($hasil['valid']) . ' baris siap diimpor. Konfirmasi untuk membuat akun.';
        } elseif ($hasil['valid'] === [] && $hasil['kesalahan'] === []) {
            $pesan = 'Tidak ada baris data pada berkas.';
        } else {
            $pesan = count($hasil['kesalahan']) . ' kesalahan ditemukan. Perbaiki berkas lalu unggah ulang (tidak ada akun dibuat).';
        }
    } catch (Throwable $ex) {
        $pesan = $ex->getMessage();
    }
    $_SESSION['_old']['impor_pesan'] = $pesan;
    redirect('admin.php?page=impor_mahasiswa');
}

// ==========================================
// IMPOR MAHASISWA CSV — LANGKAH 2: TERAPKAN (token sekali pakai)
// ==========================================
if (isset($_POST['impor_terapkan'])) {
    csrf_check();
    if ($role_aktif !== 'admin') {
        http_response_code(403);
        exit('Akses ditolak.');
    }
    $tokenPost = (string) ($_POST['impor_token'] ?? '');
    $tokenSesi = (string) ($_SESSION['impor_token'] ?? '');
    $valid = $_SESSION['impor_valid'] ?? null;
    $kedaluwarsa = (int) ($_SESSION['impor_kedaluwarsa'] ?? 0);

    // Pakai-token: hapus dari sesi apa pun hasilnya (sekali pakai).
    unset($_SESSION['impor_token'], $_SESSION['impor_valid'], $_SESSION['impor_kedaluwarsa']);

    if ($tokenSesi === '' || !hash_equals($tokenSesi, $tokenPost) || !is_array($valid) || time() > $kedaluwarsa) {
        flash_set('danger', 'Sesi pratinjau tidak berlaku lagi. Unggah ulang berkas.');
        redirect('admin.php?page=impor_mahasiswa');
    }
    $passwordAwal = (string) ($_POST['password_awal'] ?? '');
    [$type, $message, ] = terapkan_impor($conn, $valid, $passwordAwal);
    flash_set($type, $message);
    redirect('admin.php?page=impor_mahasiswa');
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($judul_halaman) ?> - AcadeMeet</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <?= theme_boot_script() ?>
    <link rel="stylesheet" href="style.css?v=2.13">
</head>
<body>
<a href="#konten" class="skip-link">Lewati ke konten utama</a>

<!-- Mobile Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="d-flex position-relative">
    <!-- ========================================== -->
    <!-- SIDEBAR KIRI                               -->
    <!-- ========================================== -->
    <aside class="app-sidebar" id="appSidebar">
        <div class="sidebar-header d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <div class="auth-brand-icon mb-0 me-2" style="width: 38px; height: 38px; font-size: 1.2rem;">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>
                <div>
                    <div class="fw-bold mb-0 text-dark">AcadeMeet</div>
                    <small class="text-muted" style="font-size: 0.75rem;"><?= $role_aktif === 'panitia' ? 'Panel Panitia' : 'Admin Panel' ?></small>
                </div>
            </div>
            <!-- Close Button for Mobile -->
            <button class="btn btn-sm btn-glass d-lg-none" id="sidebarCloseBtn" type="button" aria-label="Tutup menu">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        
        <div class="px-3 pt-3 pb-1">
            <small class="text-uppercase text-muted fw-bold" style="font-size: 0.7rem; letter-spacing: 0.05em;">Menu Navigasi</small>
        </div>
        <ul class="nav flex-column mb-auto">
            <?php if ($role_aktif === 'admin'): ?>
            <li class="nav-item">
                <a href="?page=dashboard" class="nav-link <?= ($page == 'dashboard') ? 'active' : '' ?>" <?= $page == 'dashboard' ? 'aria-current="page"' : '' ?>>
                    <i class="bi bi-grid-1x2"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="?page=mahasiswa" class="nav-link <?= (in_array($page, ['mahasiswa', 'detail_mahasiswa'])) ? 'active' : '' ?>" <?= in_array($page, ['mahasiswa', 'detail_mahasiswa']) ? 'aria-current="page"' : '' ?>>
                    <i class="bi bi-people"></i> Data Mahasiswa
                </a>
            </li>
            <li class="nav-item">
                <a href="?page=panitia" class="nav-link <?= ($page == 'panitia') ? 'active' : '' ?>" <?= $page == 'panitia' ? 'aria-current="page"' : '' ?>>
                    <i class="bi bi-person-vcard"></i> Data Panitia
                </a>
            </li>
            <li class="nav-item">
                <a href="?page=pemateri" class="nav-link <?= (in_array($page, ['pemateri', 'tambah_pemateri', 'detail_pemateri'])) ? 'active' : '' ?>" <?= in_array($page, ['pemateri', 'tambah_pemateri', 'detail_pemateri']) ? 'aria-current="page"' : '' ?>>
                    <i class="bi bi-person-badge"></i> Data Pemateri
                </a>
            </li>
            <li class="nav-item">
                <a href="?page=tambah" class="nav-link <?= ($page == 'tambah') ? 'active' : '' ?>" <?= $page == 'tambah' ? 'aria-current="page"' : '' ?>>
                    <i class="bi bi-plus-circle"></i> Buat Seminar
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a href="?page=scan" class="nav-link <?= ($page == 'scan') ? 'active' : '' ?>" <?= $page == 'scan' ? 'aria-current="page"' : '' ?>>
                    <i class="bi bi-qr-code-scan"></i> Scanner Check-in
                </a>
            </li>
            <li class="nav-item">
                <a href="?page=peserta" class="nav-link <?= ($page == 'peserta') ? 'active' : '' ?>" <?= $page == 'peserta' ? 'aria-current="page"' : '' ?>>
                    <i class="bi bi-clipboard-check"></i> <?= $role_aktif === 'panitia' ? 'Peserta Hari Ini' : 'Daftar Peserta' ?>
                </a>
            </li>
            <?php if ($role_aktif === 'admin'): ?>
            <li class="nav-item">
                <a href="?page=laporan" class="nav-link <?= ($page == 'laporan') ? 'active' : '' ?>" <?= $page == 'laporan' ? 'aria-current="page"' : '' ?>>
                    <i class="bi bi-file-earmark-spreadsheet"></i> Laporan
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <div class="p-3 border-top mt-auto">
            <div class="d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <div class="bg-primary-light text-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 36px; height: 36px;">
                        <i class="bi bi-person-check-fill"></i>
                    </div>
                    <div>
                        <div class="small fw-bold text-dark text-truncate" style="max-width: 120px;"><?= htmlspecialchars($_SESSION['nama']); ?></div>
                        <span class="badge bg-light text-muted border" style="font-size: 0.65rem;"><?= $role_aktif === 'panitia' ? 'Panitia' : 'Administrator' ?></span>
                    </div>
                </div>
                <form method="POST" action="logout.php" class="d-inline m-0">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-glass p-2" title="Logout" aria-label="Keluar dari akun">
                        <i class="bi bi-box-arrow-right"></i>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <!-- ========================================== -->
    <!-- KONTEN UTAMA KANAN                         -->
    <!-- ========================================== -->
    <main class="flex-grow-1 position-relative" style="min-width: 0; background: transparent;">
        <!-- Topbar -->
        <header class="app-topbar d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <!-- Hamburger Button for Mobile -->
                <button class="btn btn-sm btn-glass d-lg-none" id="sidebarToggleBtn" type="button" aria-label="Buka menu navigasi">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <h1 class="h5 mb-0 fw-bold text-dark">
                    <?= e($page == 'dashboard' ? 'Overview Dashboard' : $judul_halaman) ?>
                </h1>
            </div>
            <div class="d-flex align-items-center gap-3">
                <?= theme_toggle_button() ?>
                <span class="badge badge-soft-primary px-3 py-2 rounded-pill">
                    <i class="bi bi-calendar3 me-1"></i> <?= date('d M Y') ?>
                </span>
            </div>
        </header>

        <!-- Inner Content Area -->
        <div class="admin-content p-3" id="konten" tabindex="-1">

            <?= flash_html() ?>

            <?php
            // ---------------------------------------------------------
            // MENU 1: DASHBOARD
            // ---------------------------------------------------------
            if ($page == 'dashboard') {
                // Single query for all dashboard stats
                $stats = mysqli_fetch_assoc(mysqli_query($conn, "
                    SELECT 
                        (SELECT COUNT(*) FROM users WHERE role='mahasiswa') AS tot_mhs,
                        (SELECT COUNT(*) FROM seminars) AS tot_sem,
                        (SELECT COUNT(*) FROM registrations) AS tot_reg,
                        (SELECT COUNT(*) FROM registrations WHERE status_hadir='Hadir') AS tot_hadir
                "));
                $tot_mhs = $stats['tot_mhs'];
                $tot_sem = $stats['tot_sem'];
                $tot_reg = $stats['tot_reg'];
                $tot_hadir = $stats['tot_hadir'];
            ?>
                <!-- 4 Kotak Statistik -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <span class="stat-label">Total Mahasiswa</span>
                            <div class="stat-value"><?= $tot_mhs ?></div>
                            <div class="stat-icon stat-icon--primary">
                                <i class="bi bi-people-fill"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <span class="stat-label">Total Seminar</span>
                            <div class="stat-value"><?= $tot_sem ?></div>
                            <div class="stat-icon stat-icon--success">
                                <i class="bi bi-calendar-event-fill"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <span class="stat-label">Tiket Terdaftar</span>
                            <div class="stat-value"><?= $tot_reg ?></div>
                            <div class="stat-icon stat-icon--warning">
                                <i class="bi bi-ticket-perforated-fill"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <span class="stat-label">Kehadiran</span>
                            <div class="stat-value"><?= $tot_hadir ?></div>
                            <div class="stat-icon stat-icon--pink">
                                <i class="bi bi-check-circle-fill"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Grafik dashboard -->
                <?php
                require_once __DIR__ . '/services.php';
                require_once __DIR__ . '/grafik.php';
                $hariIniG = date('Y-m-d');
                $serie90 = serie_harian(statistik_pendaftar_harian($conn, date('Y-m-d', strtotime('-89 day')) . ' 00:00:00'), date('Y-m-d', strtotime('-89 day')), 90);
                $serie30 = serie_harian(statistik_pendaftar_harian($conn, date('Y-m-d', strtotime('-29 day')) . ' 00:00:00'), date('Y-m-d', strtotime('-29 day')), 30);
                $serie7 = serie_harian(statistik_pendaftar_harian($conn, date('Y-m-d', strtotime('-6 day')) . ' 00:00:00'), date('Y-m-d', strtotime('-6 day')), 7);
                $tanpa_waktu = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM registrations WHERE created_at IS NULL"))['c'];

                $grafik_populer = statistik_seminar_populer($conn, 5);
                $grafik_kehadiran = statistik_kehadiran_seminar($conn, 10);
                $maksPopuler = 0;
                foreach ($grafik_populer as $g) { $maksPopuler = max($maksPopuler, (int) $g['terdaftar']); }
                $baris_populer = [];
                $nomor = 0;
                foreach ($grafik_populer as $g) {
                    $nomor++;
                    $baris_populer[] = [
                        'judul' => $g['judul'], 'nilai' => $g['terdaftar'], 'maks' => $maksPopuler,
                        'teks' => (string) $g['terdaftar'], 'nomor' => $nomor,
                    ];
                }
                $baris_kehadiran = [];
                foreach ($grafik_kehadiran as $g) {
                    $persen = $g['terdaftar'] > 0 ? round($g['hadir'] / $g['terdaftar'] * 100) : 0;
                    $baris_kehadiran[] = [
                        'judul' => $g['judul'], 'nilai' => $persen, 'maks' => 100,
                        'teks' => $persen . '%', 'sub' => $g['hadir'] . ' dari ' . $g['terdaftar'] . ' terdaftar hadir',
                    ];
                }
                ?>
                <div class="mb-4">
                    <?= grafik_area_html($serie90, $serie30, $serie7, $tanpa_waktu) ?>
                </div>
                <div class="paketd-cards mb-4">
                    <div class="card card-custom card-static h-100">
                        <div class="card-body">
                            <h6 class="fw-bold mb-3">Tingkat Kehadiran per Seminar</h6>
                            <?= grafik_daftar_html($baris_kehadiran) ?>
                        </div>
                    </div>
                    <div class="card card-custom card-static h-100">
                        <div class="card-body">
                            <h6 class="fw-bold mb-3">5 Seminar Terpopuler (Pendaftar)</h6>
                            <?= grafik_daftar_html($baris_populer) ?>
                        </div>
                    </div>
                </div>

                <!-- Tabel Seminar Aktif -->
                <div class="card card-custom card-static overflow-hidden">
                    <div class="card-header bg-white border-bottom py-3 px-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h6 class="fw-bold mb-0 text-dark">Daftar Seminar</h6>
                        <div class="admin-toolbar">
                            <?php
                            $f_status = (string) ($_GET['f_status'] ?? '');
                            if (!in_array($f_status, ['Aktif', 'Selesai'], true)) {
                                $f_status = '';
                            }
                            $f_tanggal = (string) ($_GET['f_tanggal'] ?? '');
                            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_tanggal)) {
                                $f_tanggal = '';
                            }
                            $q_dashboard = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
                            $filter_aktif = $f_status !== '' || $f_tanggal !== '';
                            ?>
                            <form method="get" action="admin.php" class="input-group input-group-sm" style="width: 220px;" role="search">
                                <input type="hidden" name="page" value="dashboard">
                                <button type="submit" class="input-group-text bg-white border-end-0 text-muted" aria-label="Cari"><i class="bi bi-search"></i></button>
                                <input type="search" name="q" class="form-control border-start-0 ps-0" value="<?= e($q_dashboard) ?>" placeholder="Cari seminar..." aria-label="Cari seminar">
                            </form>
                            <form method="get" action="admin.php" class="d-flex align-items-center gap-2 flex-wrap">
                                <input type="hidden" name="page" value="dashboard">
                                <?php if ($q_dashboard !== ''): ?>
                                    <input type="hidden" name="q" value="<?= e($q_dashboard) ?>">
                                <?php endif; ?>
                                <label for="f_status" class="visually-hidden">Filter status seminar</label>
                                <select name="f_status" id="f_status" class="form-select form-select-sm" style="width: 130px;">
                                    <option value="">Semua status</option>
                                    <option value="Aktif" <?= $f_status === 'Aktif' ? 'selected' : '' ?>>Aktif</option>
                                    <option value="Selesai" <?= $f_status === 'Selesai' ? 'selected' : '' ?>>Selesai</option>
                                </select>
                                <label for="f_tanggal" class="visually-hidden">Filter tanggal seminar</label>
                                <input type="date" name="f_tanggal" id="f_tanggal" class="form-control form-control-sm" style="width: 150px;" value="<?= e($f_tanggal) ?>">
                                <button type="submit" class="btn btn-sm btn-light-primary fw-semibold">Filter</button>
                                <?php if ($filter_aktif): ?>
                                    <a href="?page=dashboard<?= $q_dashboard !== '' ? '&q=' . e(urlencode($q_dashboard)) : '' ?>" class="btn btn-sm btn-light fw-semibold">Reset filter</a>
                                <?php endif; ?>
                            </form>
                            <a href="?page=tambah" class="btn btn-sm btn-primary">
                                <i class="bi bi-plus-lg me-1"></i> Tambah Seminar
                            </a>
                        </div>
                    </div>
                    <div class="table-responsive" tabindex="0" role="region" aria-label="Tabel daftar seminar">
                        <table class="table table-custom">
                            <caption class="visually-hidden">Daftar seminar beserta kuota dan status</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Poster</th>
                                    <th scope="col">Judul Seminar</th>
                                    <th scope="col">Kategori</th>
                                    <th scope="col">Pemateri</th>
                                    <th scope="col">Tanggal</th>
                                    <th scope="col">Kuota (sisa/total)</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                [$p, $per, $q] = list_page();
                                $like_raw = mysqli_real_escape_string($conn, $q);
                                $kondisi = [];
                                if ($q !== '') {
                                    $kondisi[] = "(judul LIKE '%$like_raw%' OR pemateri LIKE '%$like_raw%')";
                                }
                                if ($f_status !== '') {
                                    if ($f_status === 'Aktif') {
                                        $kondisi[] = "status = 'Aktif' AND tanggal >= CURDATE()";
                                    } else {
                                        $kondisi[] = "(status = 'Selesai' OR tanggal < CURDATE())";
                                    }
                                }
                                if ($f_tanggal !== '') {
                                    $kondisi[] = "tanggal = '$f_tanggal'";
                                }
                                $where = $kondisi ? 'WHERE ' . implode(' AND ', $kondisi) : '';
                                $total = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM seminars $where"))['c'];
                                $p = min($p, max(1, (int) ceil($total / $per)));
                                $offset = ($p - 1) * $per;
                                $seminar = mysqli_query($conn, "SELECT *, (SELECT COUNT(*) FROM registrations r WHERE r.id_seminar = seminars.id_seminar) AS jml_peserta FROM seminars $where ORDER BY tanggal ASC LIMIT $offset, $per");
                                $ada_filter = $q !== '' || $filter_aktif;
                                if (mysqli_num_rows($seminar) > 0) {
                                    while ($row = mysqli_fetch_assoc($seminar)) {
                                        $img_src = !empty($row['poster'])
                                            ? "uploads/posters/" . $row['poster']
                                            : placeholder_data_uri('Acara');
                                        $status_efektif = seminar_status_efektif((string) $row['status'], (string) $row['tanggal'], date('Y-m-d'));
                                        $status_badge = $status_efektif === 'Aktif'
                                            ? "<span class='badge badge-soft-success px-2 py-1 rounded-pill'><i class='bi bi-circle-fill me-1' style='font-size:.5rem;'></i>Aktif</span>"
                                            : "<span class='badge badge-soft-warning px-2 py-1 rounded-pill'><i class='bi bi-check-circle me-1'></i>Selesai</span>";

                                        $id_sem = (int) $row['id_seminar'];
                                        $judul_attr = e($row['judul']);

                                        $kategori_row = (string) ($row['kategori'] ?? 'Umum');
                                        $kategori_badge = "<span class='badge badge-soft-primary px-2 py-1 rounded-pill'>" . e($kategori_row) . "</span>";
                                        $jam_row = '';
                                        if (!empty($row['jam_mulai'])) {
                                            $jm = substr((string) $row['jam_mulai'], 0, 5);
                                            $js = !empty($row['jam_selesai']) ? '-' . substr((string) $row['jam_selesai'], 0, 5) : '';
                                            $jam_row = " <span class='text-primary fw-semibold'>" . e($jm . $js) . "</span>";
                                        }

                                        $aksi_selesai = $status_efektif === 'Aktif'
                                            ? "<button type='button' class='btn btn-sm btn-light-success fw-semibold js-selesai'
                                                data-id='$id_sem' data-judul=\"$judul_attr\">
                                                <i class='bi bi-check-circle me-1'></i>Selesai
                                               </button>"
                                            : "";

                                        $aksi_edit = $status_efektif === 'Aktif'
                                            ? "<a href='admin.php?page=edit_seminar&id=$id_sem' class='btn btn-sm btn-light-primary fw-semibold'>
                                                <i class='bi bi-pencil me-1'></i>Edit
                                               </a>"
                                            : "";

                                        $aksi_peserta = "<a href='admin.php?page=peserta&id=$id_sem' class='btn btn-sm btn-light-info fw-semibold'>
                                                <i class='bi bi-clipboard-check me-1'></i>Peserta
                                               </a>";

                                        echo "<tr>
                                                <td><img src='" . e($img_src) . "' alt='Poster " . e($row['judul']) . "' class='rounded-3 shadow-sm border' width='80' height='50' loading='lazy' decoding='async' style='object-fit:cover;'></td>
                                                <td><span class='fw-semibold text-dark'>" . e($row['judul']) . "</span></td>
                                                <td>$kategori_badge</td>
                                                <td class='text-secondary'>" . e($row['pemateri']) . "</td>
                                                <td class='text-muted small'><i class='bi bi-calendar3 me-1'></i>" . e(date('d M Y', strtotime($row['tanggal']))) . "$jam_row</td>
                                                <td><span class='badge badge-soft-primary px-3 py-2 rounded-pill'>" . (int) $row['kuota'] . "/" . (int) ($row['kuota_total'] ?? $row['kuota']) . " Kursi</span></td>
                                                <td>$status_badge</td>
                                                <td class='text-center'>
                                                    <div class='d-flex justify-content-center gap-1'>
                                                        $aksi_peserta
                                                        $aksi_edit
                                                        $aksi_selesai
                                                        <button type='button'
                                                            class='btn btn-sm btn-light-danger fw-semibold js-hapus-seminar'
                                                            data-id='$id_sem' data-judul=\"$judul_attr\" data-peserta='" . (int) ($row['jml_peserta'] ?? 0) . "'>
                                                            <i class='bi bi-trash me-1'></i>Hapus
                                                        </button>
                                                    </div>
                                                </td>
                                              </tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='8' class='text-center py-4 text-muted'>" . ($ada_filter ? 'Tidak ada seminar yang cocok dengan filter/pencarian saat ini.' : 'Belum ada data seminar.') . "</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <?= table_footer($total, $p, $per) ?>
                </div>

                <!-- Modal Konfirmasi Hapus Seminar -->
                <div class="modal fade" id="modalHapus" tabindex="-1" aria-labelledby="modalHapusLabel" aria-modal="true" role="dialog">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg rounded-4">
                            <div class="modal-header border-0 pb-0">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="icon-tint icon-tint--danger">
                                        <i class="bi bi-trash-fill fs-5"></i>
                                    </div>
                                    <div>
                                        <h5 class="modal-title fw-bold text-body-emphasis mb-0" id="modalHapusLabel">Hapus Seminar?</h5>
                                        <p class="text-muted small mb-0">Tindakan ini tidak dapat dibatalkan</p>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-body pt-3">
                                <p class="text-secondary mb-0">Seminar <strong id="judulHapus"></strong> beserta seluruh data pendaftar akan dihapus permanen.</p>
                                <p class="text-danger small mb-0 mt-2" id="pesertaHapus"></p>
                                <p class="text-muted small mb-0 mt-2">Bila hanya jadwal/detail yang berubah, sebaiknya gunakan <strong>Edit</strong> agar tiket peserta tetap berlaku.</p>
                            </div>
                            <div class="modal-footer border-0 pt-0">
                                <button type="button" class="btn btn-light fw-semibold px-4" data-bs-dismiss="modal">Batal</button>
                                <form method="POST" action="admin.php?page=dashboard" class="m-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id_seminar" id="inputHapusId" value="">
                                    <button type="submit" name="hapus" class="btn btn-danger fw-semibold px-4">
                                        <i class="bi bi-trash me-1"></i>Ya, Hapus
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Konfirmasi Selesai Seminar -->
                <div class="modal fade" id="modalSelesai" tabindex="-1" aria-labelledby="modalSelesaiLabel" aria-modal="true" role="dialog">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg rounded-4">
                            <div class="modal-header border-0 pb-0">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="icon-tint icon-tint--success">
                                        <i class="bi bi-check2-circle fs-4"></i>
                                    </div>
                                    <div>
                                        <h5 class="modal-title fw-bold text-body-emphasis mb-0" id="modalSelesaiLabel">Tandai Seminar Selesai?</h5>
                                        <p class="text-muted small mb-0">Status seminar akan diubah menjadi Selesai</p>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-body pt-3">
                                <p class="text-secondary mb-0">Apakah Anda yakin ingin menandai seminar <strong id="judulSelesai"></strong> sebagai selesai? Pendaftaran baru akan otomatis ditutup.</p>
                            </div>
                            <div class="modal-footer border-0 pt-0">
                                <form method="POST" action="admin.php?page=dashboard" class="w-100 d-flex justify-content-end gap-2 m-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id_seminar" id="inputSelesaiId" value="">
                                    <button type="button" class="btn btn-light fw-semibold px-4" data-bs-dismiss="modal">Batal</button>
                                    <button type="submit" name="selesai" class="btn btn-success fw-semibold px-4">
                                        <i class="bi bi-check-circle me-1"></i>Ya, Selesai
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php
            // ---------------------------------------------------------
            // MENU 2: BUAT SEMINAR
            // ---------------------------------------------------------
            } elseif ($page == 'tambah') {
            ?>
                <div class="card card-custom card-static">
                    <div class="card-header bg-white border-bottom py-3 px-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; background: var(--primary-light); color: var(--primary);">
                                <i class="bi bi-plus-circle-fill fs-5"></i>
                            </div>
                            <div>
                                <div class="fw-bold mb-0 text-dark">Buat Seminar Baru</div>
                                <p class="text-muted small mb-0">Isi detail seminar dan pilih pemateri terdaftar</p>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="admin.php?page=tambah" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <div class="row g-4">
                                <div class="col-lg-6">
                                    <label for="judul" class="form-label small fw-semibold text-secondary">Judul Seminar</label>
                                    <input type="text" class="form-control" id="judul" name="judul" maxlength="200" value="<?= e(old('judul')) ?>" placeholder="Contoh: Workshop Artificial Intelligence" required>
                                </div>
                                <div class="col-lg-6">
                                    <label for="id_pemateri" class="form-label small fw-semibold text-secondary">Pilih Pemateri</label>
                                    <select class="form-select" id="id_pemateri" name="id_pemateri" required>
                                        <option value="">-- Pilih Pemateri --</option>
                                        <?php
                                        $pemateri_list = mysqli_query($conn, "SELECT id_pemateri, nama, keahlian, institusi FROM pemateri ORDER BY nama ASC");
                                        while ($pmt = mysqli_fetch_assoc($pemateri_list)) {
                                            $terpilih = old('id_pemateri') === (string) $pmt['id_pemateri'] ? ' selected' : '';
                                            echo "<option value='" . (int) $pmt['id_pemateri'] . "'$terpilih>" . e($pmt['nama']) . ' - ' . e($pmt['keahlian']) . ' (' . e($pmt['institusi']) . ")</option>";
                                        }
                                        ?>
                                    </select>
                                    <div class="form-text text-muted small">Pemateri belum ada? <a href="?page=tambah_pemateri" class="text-decoration-none">Tambah Pemateri Baru</a></div>
                                </div>
                                <div class="col-lg-6">
                                    <label for="tanggal" class="form-label small fw-semibold text-secondary">Tanggal Pelaksanaan</label>
                                    <input type="date" class="form-control" id="tanggal" name="tanggal" value="<?= e(old('tanggal')) ?>" min="<?= e(date('Y-m-d')) ?>" required>
                                </div>
                                <div class="col-lg-3">
                                    <label for="jam_mulai" class="form-label small fw-semibold text-secondary">Jam Mulai</label>
                                    <input type="time" class="form-control" id="jam_mulai" name="jam_mulai" value="<?= e(normalisasi_jam(old('jam_mulai')) ?? '') ?>">
                                </div>
                                <div class="col-lg-3">
                                    <label for="jam_selesai" class="form-label small fw-semibold text-secondary">Jam Selesai</label>
                                    <input type="time" class="form-control" id="jam_selesai" name="jam_selesai" value="<?= e(normalisasi_jam(old('jam_selesai')) ?? '') ?>">
                                </div>
                                <div class="col-lg-6">
                                    <label for="kategori" class="form-label small fw-semibold text-secondary">Kategori</label>
                                    <select class="form-select" id="kategori" name="kategori">
                                        <?php foreach (kategori_seminar() as $kat): ?>
                                            <option value="<?= e($kat) ?>" <?= old('kategori', 'Umum') === $kat ? 'selected' : '' ?>><?= e($kat) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-6">
                                    <label for="lokasi" class="form-label small fw-semibold text-secondary">Lokasi (opsional)</label>
                                    <input type="text" class="form-control" id="lokasi" name="lokasi" maxlength="150" value="<?= e(old('lokasi')) ?>" placeholder="Contoh: Aula Gedung A / Zoom">
                                </div>
                                <div class="col-lg-6">
                                    <label for="link_online" class="form-label small fw-semibold text-secondary">Link Online (opsional)</label>
                                    <input type="url" class="form-control" id="link_online" name="link_online" maxlength="255" value="<?= e(old('link_online')) ?>" placeholder="https://...">
                                    <div class="form-text text-muted small">Hanya terlihat oleh peserta yang terdaftar (di halaman tiket).</div>
                                </div>
                                <div class="col-12">
                                    <label for="deskripsi" class="form-label small fw-semibold text-secondary">Deskripsi (opsional)</label>
                                    <textarea class="form-control" id="deskripsi" name="deskripsi" rows="3" maxlength="2000" placeholder="Ringkasan acara, agenda, dsb."><?= e(old('deskripsi')) ?></textarea>
                                    <div class="form-text text-muted small">Maksimal 2000 karakter.</div>
                                </div>
                                <div class="col-lg-6">
                                    <label for="ttd_nama" class="form-label small fw-semibold text-secondary">Nama Penandatangan (opsional)</label>
                                    <input type="text" class="form-control" id="ttd_nama" name="ttd_nama" maxlength="100" value="<?= e(old('ttd_nama')) ?>" placeholder="Nama pada sertifikat">
                                    <div class="form-text text-muted small">Kosongkan untuk teks bawaan sertifikat.</div>
                                </div>
                                <div class="col-lg-6">
                                    <label for="ttd_jabatan" class="form-label small fw-semibold text-secondary">Jabatan Penandatangan (opsional)</label>
                                    <input type="text" class="form-control" id="ttd_jabatan" name="ttd_jabatan" maxlength="100" value="<?= e(old('ttd_jabatan')) ?>" placeholder="Contoh: Ketua Pelaksana">
                                    <div class="form-text text-muted small">Bila diisi, nama penandatangan wajib diisi.</div>
                                </div>
                                <div class="col-lg-6">
                                    <label for="kuota" class="form-label small fw-semibold text-secondary">Kuota Peserta</label>
                                    <input type="number" class="form-control" id="kuota" name="kuota" value="<?= e(old('kuota')) ?>" placeholder="Contoh: 100" min="1" inputmode="numeric" required>
                                </div>
                                <div class="col-lg-6">
                                    <label for="poster" class="form-label small fw-semibold text-secondary">Poster Seminar (Max 5MB)</label>
                                    <input type="file" class="form-control" id="poster" name="poster" accept=".jpg,.jpeg,.png,.webp">
                                </div>
                            </div>
                            <div class="mt-4 pt-3 border-top d-flex justify-content-end gap-2">
                                <a href="admin.php?page=dashboard" class="btn btn-light fw-semibold px-4">Batal</a>
                                <button type="submit" name="tambah" class="btn btn-primary px-4">
                                    <i class="bi bi-cloud-arrow-up me-1"></i> Publikasikan Seminar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php
            // ---------------------------------------------------------
            // MENU 2b: EDIT SEMINAR
            // ---------------------------------------------------------
            } elseif ($page == 'edit_seminar') {
                $id_edit = (int) ($_GET['id'] ?? 0);
                $sem_edit = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM seminars WHERE id_seminar = $id_edit"));
                if (!$sem_edit || seminar_status_efektif((string) $sem_edit['status'], (string) $sem_edit['tanggal'], date('Y-m-d')) !== 'Aktif') {
                    flash_set('danger', 'Seminar tidak ditemukan atau sudah selesai (tidak dapat diedit).');
                    redirect('admin.php?page=dashboard');
                }
                $pendaftar_edit = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM registrations WHERE id_seminar = $id_edit"))['c'];
                $jam_mulai_edit = normalisasi_jam((string) ($sem_edit['jam_mulai'] ?? '')) ?? '';
                $jam_selesai_edit = normalisasi_jam((string) ($sem_edit['jam_selesai'] ?? '')) ?? '';
                $kategori_edit = (string) ($sem_edit['kategori'] ?? 'Umum');
            ?>
                <div class="card card-custom mb-4">
                    <div class="card-header bg-white border-bottom py-3 px-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; background: var(--primary-light); color: var(--primary);">
                                <i class="bi bi-pencil-square fs-5"></i>
                            </div>
                            <div>
                                <div class="fw-bold mb-0 text-dark">Edit Seminar</div>
                                <p class="text-muted small mb-0">Perbarui detail seminar. Pendaftar saat ini: <?= $pendaftar_edit ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="admin.php?page=edit_seminar&id=<?= $id_edit ?>" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id_seminar" value="<?= $id_edit ?>">
                            <div class="row g-4">
                                <div class="col-lg-6">
                                    <label for="judul" class="form-label small fw-semibold text-secondary">Judul Seminar</label>
                                    <input type="text" class="form-control" id="judul" name="judul" maxlength="200" value="<?= e(old('judul', (string) $sem_edit['judul'])) ?>" required>
                                </div>
                                <div class="col-lg-6">
                                    <label for="id_pemateri" class="form-label small fw-semibold text-secondary">Pilih Pemateri</label>
                                    <select class="form-select" id="id_pemateri" name="id_pemateri" required>
                                        <option value="">-- Pilih Pemateri --</option>
                                        <?php
                                        $terpilih_id = old('id_pemateri', (string) $sem_edit['id_pemateri']);
                                        $pemateri_list2 = mysqli_query($conn, "SELECT id_pemateri, nama, keahlian, institusi FROM pemateri ORDER BY nama ASC");
                                        while ($pmt = mysqli_fetch_assoc($pemateri_list2)) {
                                            $terpilih = $terpilih_id === (string) $pmt['id_pemateri'] ? ' selected' : '';
                                            echo "<option value='" . (int) $pmt['id_pemateri'] . "'$terpilih>" . e($pmt['nama']) . ' - ' . e($pmt['keahlian']) . ' (' . e($pmt['institusi']) . ")</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-lg-6">
                                    <label for="tanggal" class="form-label small fw-semibold text-secondary">Tanggal Pelaksanaan</label>
                                    <input type="date" class="form-control" id="tanggal" name="tanggal" value="<?= e(old('tanggal', (string) $sem_edit['tanggal'])) ?>" required>
                                </div>
                                <div class="col-lg-3">
                                    <label for="jam_mulai" class="form-label small fw-semibold text-secondary">Jam Mulai</label>
                                    <input type="time" class="form-control" id="jam_mulai" name="jam_mulai" value="<?= e(normalisasi_jam(old('jam_mulai', $jam_mulai_edit)) ?? '') ?>">
                                </div>
                                <div class="col-lg-3">
                                    <label for="jam_selesai" class="form-label small fw-semibold text-secondary">Jam Selesai</label>
                                    <input type="time" class="form-control" id="jam_selesai" name="jam_selesai" value="<?= e(normalisasi_jam(old('jam_selesai', $jam_selesai_edit)) ?? '') ?>">
                                </div>
                                <div class="col-lg-6">
                                    <label for="kategori" class="form-label small fw-semibold text-secondary">Kategori</label>
                                    <select class="form-select" id="kategori" name="kategori">
                                        <?php foreach (kategori_seminar() as $kat): ?>
                                            <option value="<?= e($kat) ?>" <?= old('kategori', $kategori_edit) === $kat ? 'selected' : '' ?>><?= e($kat) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-6">
                                    <label for="lokasi" class="form-label small fw-semibold text-secondary">Lokasi (opsional)</label>
                                    <input type="text" class="form-control" id="lokasi" name="lokasi" maxlength="150" value="<?= e(old('lokasi', (string) ($sem_edit['lokasi'] ?? ''))) ?>" placeholder="Contoh: Aula Gedung A / Zoom">
                                </div>
                                <div class="col-lg-6">
                                    <label for="link_online" class="form-label small fw-semibold text-secondary">Link Online (opsional)</label>
                                    <input type="url" class="form-control" id="link_online" name="link_online" maxlength="255" value="<?= e(old('link_online', (string) ($sem_edit['link_online'] ?? ''))) ?>" placeholder="https://...">
                                    <div class="form-text text-muted small">Hanya terlihat oleh peserta yang terdaftar (di halaman tiket).</div>
                                </div>
                                <div class="col-12">
                                    <label for="deskripsi" class="form-label small fw-semibold text-secondary">Deskripsi (opsional)</label>
                                    <textarea class="form-control" id="deskripsi" name="deskripsi" rows="3" maxlength="2000" placeholder="Ringkasan acara, agenda, dsb."><?= e(old('deskripsi', (string) ($sem_edit['deskripsi'] ?? ''))) ?></textarea>
                                    <div class="form-text text-muted small">Maksimal 2000 karakter.</div>
                                </div>
                                <div class="col-lg-6">
                                    <label for="ttd_nama" class="form-label small fw-semibold text-secondary">Nama Penandatangan (opsional)</label>
                                    <input type="text" class="form-control" id="ttd_nama" name="ttd_nama" maxlength="100" value="<?= e(old('ttd_nama', (string) ($sem_edit['ttd_nama'] ?? ''))) ?>" placeholder="Nama pada sertifikat">
                                    <div class="form-text text-muted small">Kosongkan untuk teks bawaan sertifikat.</div>
                                </div>
                                <div class="col-lg-6">
                                    <label for="ttd_jabatan" class="form-label small fw-semibold text-secondary">Jabatan Penandatangan (opsional)</label>
                                    <input type="text" class="form-control" id="ttd_jabatan" name="ttd_jabatan" maxlength="100" value="<?= e(old('ttd_jabatan', (string) ($sem_edit['ttd_jabatan'] ?? ''))) ?>" placeholder="Contoh: Ketua Pelaksana">
                                    <div class="form-text text-muted small">Bila diisi, nama penandatangan wajib diisi.</div>
                                </div>
                                <div class="col-lg-6">
                                    <label for="kuota_total" class="form-label small fw-semibold text-secondary">Kuota Total Peserta</label>
                                    <input type="number" class="form-control" id="kuota_total" name="kuota_total" value="<?= e(old('kuota_total', (string) ($sem_edit['kuota_total'] ?? $sem_edit['kuota']))) ?>" min="<?= max(1, $pendaftar_edit) ?>" inputmode="numeric" required>
                                    <div class="form-text text-muted small">Minimal <?= max(1, $pendaftar_edit) ?> (jumlah pendaftar saat ini). Sisa kuota dihitung otomatis.</div>
                                </div>
                                <div class="col-lg-6">
                                    <label for="poster" class="form-label small fw-semibold text-secondary">Poster Baru (Max 5MB, opsional)</label>
                                    <input type="file" class="form-control" id="poster" name="poster" accept=".jpg,.jpeg,.png,.webp">
                                    <?php if (!empty($sem_edit['poster'])): ?>
                                        <div class="form-text text-muted small">Poster saat ini: <?= e($sem_edit['poster']) ?>. Biarkan kosong bila tidak ingin mengganti.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mt-4 pt-3 border-top d-flex justify-content-end gap-2">
                                <a href="admin.php?page=dashboard" class="btn btn-light fw-semibold px-4">Batal</a>
                                <button type="submit" name="edit_seminar" class="btn btn-primary px-4">
                                    <i class="bi bi-save me-1"></i> Simpan Perubahan
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php
            // ---------------------------------------------------------
            // MENU 3: SCANNER
            // ---------------------------------------------------------
            } elseif ($page == 'scan') {
            ?>
                <script src="vendor/html5-qrcode.min.js"></script>
                <div class="row justify-content-center">
                    <div class="col-md-7 col-lg-5">
                        <div class="card card-custom text-center p-4 p-md-5">
                            <div class="auth-brand-icon icon-tint--success mx-auto mb-3" style="width: 64px; height: 64px; font-size: 2rem;">
                                <i class="bi bi-qr-code-scan"></i>
                            </div>
                            <h4 class="fw-bold text-dark mb-1">Check-in Kehadiran</h4>
                            <p class="text-muted small mb-4">Scan QR code atau input kode tiket untuk memvalidasi peserta.</p>

                            <!-- Tab Switcher -->
                            <div class="d-flex gap-2 mb-4" role="group" aria-label="Mode check-in">
                                <button class="btn btn-outline-primary flex-fill active" id="tabManual" type="button" aria-pressed="true" data-mode="manual">
                                    <i class="bi bi-keyboard me-1"></i> Manual
                                </button>
                                <button class="btn btn-outline-primary flex-fill" id="tabCamera" type="button" aria-pressed="false" data-mode="camera">
                                    <i class="bi bi-camera me-1"></i> Scan Kamera
                                </button>
                            </div>

                            <!-- Mode Manual -->
                            <div id="modeManual">
                                <form method="POST" action="admin.php?page=scan">
                                    <?= csrf_field() ?>
                                    <div class="mb-3">
                                        <label for="kode_tiket" class="visually-hidden">Kode tiket</label>
                                        <input type="text" class="form-control form-control-lg text-center fw-bold letter-spacing-1" id="kode_tiket" name="kode_tiket" placeholder="ACD-XXXXXX" required autocomplete="off" pattern="ACD-[0-9A-Fa-f]{6}" spellcheck="false" style="text-transform: uppercase;" autofocus>
                                    </div>
                                    <button class="btn btn-primary btn-lg w-100 shadow-sm" type="submit" name="checkin">
                                        <i class="bi bi-check-lg me-1"></i> Validasi Kehadiran
                                    </button>
                                </form>
                            </div>

                            <!-- Mode Camera -->
                            <div id="modeCamera" style="display:none;">
                                <div id="qr-reader" class="w-100" style="border-radius: 12px; overflow:hidden;"></div>
                                <div id="qr-reader-results" class="mt-3"></div>
                                <button class="btn btn-danger btn-lg w-100 mt-3" id="btnStopScan" type="button" style="display:none;">
                                    <i class="bi bi-stop-circle me-1"></i> Stop Scanner
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                let html5QrCode = null;
                // getState() tetap NOT_STARTED selama start() in-flight, jadi tidak bisa
                // dipakai sebagai guard. Pakai flag busy sampai promise start/stop settle.
                let scannerBusy = false;

                function setStopButton(visible) {
                    document.getElementById('btnStopScan').style.display = visible ? 'block' : 'none';
                }

                function switchMode(mode) {
                    const modeManual = document.getElementById('modeManual');
                    const modeCamera = document.getElementById('modeCamera');
                    const tabManual = document.getElementById('tabManual');
                    const tabCamera = document.getElementById('tabCamera');

                    if (mode === 'manual') {
                        modeManual.style.display = 'block';
                        modeCamera.style.display = 'none';
                        tabManual.classList.add('btn-primary');
                        tabManual.classList.remove('btn-outline-primary');
                        tabManual.setAttribute('aria-pressed', 'true');
                        tabCamera.classList.remove('btn-primary');
                        tabCamera.classList.add('btn-outline-primary');
                        tabCamera.setAttribute('aria-pressed', 'false');
                        stopScanner();
                    } else {
                        modeManual.style.display = 'none';
                        modeCamera.style.display = 'block';
                        tabCamera.classList.add('btn-primary');
                        tabCamera.classList.remove('btn-outline-primary');
                        tabCamera.setAttribute('aria-pressed', 'true');
                        tabManual.classList.remove('btn-primary');
                        tabManual.classList.add('btn-outline-primary');
                        tabManual.setAttribute('aria-pressed', 'false');
                        startScanner();
                    }
                }

                document.getElementById('tabManual').addEventListener('click', function () { switchMode('manual'); });
                document.getElementById('tabCamera').addEventListener('click', function () { switchMode('camera'); });
                document.getElementById('btnStopScan').addEventListener('click', stopScanner);

                let sudahKirimCheckin = false;

                function onScanSuccess(decodedText) {
                    // Cegah kirim ganda; decoder bisa memanggil callback beberapa kali.
                    if (sudahKirimCheckin) return;
                    sudahKirimCheckin = true;
                    stopScanner();

                    // Kirim lewat POST + token CSRF, bukan URL GET.
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'admin.php?page=scan';

                    const token = document.createElement('input');
                    token.type = 'hidden';
                    token.name = '_csrf';
                    token.value = '<?= e(csrf_token()) ?>';

                    const code = document.createElement('input');
                    code.type = 'hidden';
                    code.name = 'kode_tiket';
                    code.value = decodedText;

                    const submit = document.createElement('input');
                    submit.type = 'hidden';
                    submit.name = 'checkin';
                    submit.value = '1';

                    form.appendChild(token);
                    form.appendChild(code);
                    form.appendChild(submit);
                    document.body.appendChild(form);
                    form.submit();
                }

                function startScanner() {
                    // Abaikan selama transisi start/stop belum selesai.
                    if (scannerBusy) return;
                    if (html5QrCode === null) {
                        html5QrCode = new Html5Qrcode("qr-reader");
                    }
                    scannerBusy = true;
                    document.getElementById('qr-reader-results').innerHTML = '';
                    setStopButton(true);

                    html5QrCode.start(
                        { facingMode: "environment" },
                        {
                            fps: 10,
                            qrbox: { width: 250, height: 250 }
                        },
                        onScanSuccess,
                        (errorMessage) => {} // abaikan frame tanpa kode
                    ).then(function () {
                        scannerBusy = false;
                    }).catch(function () {
                        scannerBusy = false;
                        setStopButton(false);
                        document.getElementById('qr-reader-results').innerHTML =
                            '<div class="alert alert-danger" role="alert">Kamera tidak dapat diakses. Pastikan izin kamera diberikan.</div>';
                    });
                }

                function stopScanner() {
                    if (scannerBusy) return;
                    // Hanya stop kalau lib benar-benar sedang memindai (SCANNING).
                    if (!html5QrCode || html5QrCode.getState() !== 2) {
                        setStopButton(false);
                        return;
                    }
                    scannerBusy = true;
                    html5QrCode.stop().then(function () {
                        scannerBusy = false;
                        setStopButton(false);
                    }).catch(function () {
                        scannerBusy = false;
                        setStopButton(false);
                    });
                }

                // Check-in dari hasil scan ditangani via POST di atas.
                </script>
            <?php
            // ---------------------------------------------------------
            // MENU 4: DATA MAHASISWA
            // ---------------------------------------------------------
            } elseif ($page == 'mahasiswa') {
            ?>
                <div class="card card-custom card-static overflow-hidden">
                    <div class="card-header bg-white border-bottom py-3 px-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h6 class="fw-bold mb-0 text-dark">Data Mahasiswa Terdaftar</h6>
                        <div class="admin-toolbar">
                        <a href="admin.php?page=impor_mahasiswa" class="btn btn-sm btn-primary fw-semibold"><i class="bi bi-file-earmark-arrow-up me-1"></i>Impor CSV</a>
                        <form method="get" action="admin.php" class="input-group input-group-sm" style="width: 240px;" role="search">
                            <input type="hidden" name="page" value="mahasiswa">
                            <button type="submit" class="input-group-text bg-white border-end-0 text-muted" aria-label="Cari"><i class="bi bi-search"></i></button>
                            <input type="search" name="q" class="form-control border-start-0 ps-0" value="<?= e(trim((string) ($_GET['q'] ?? ''))) ?>" placeholder="Cari nama / NIM / prodi..." aria-label="Cari mahasiswa">
                        </form>
                        </div>
                    </div>
                    <div class="table-responsive" tabindex="0" role="region" aria-label="Tabel data mahasiswa">
                        <table class="table table-custom">
                            <caption class="visually-hidden">Daftar mahasiswa terdaftar</caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="text-center">No</th>
                                    <th scope="col">NIM</th>
                                    <th scope="col">Nama Lengkap</th>
                                    <th scope="col">Program Studi</th>
                                    <th scope="col">Email Kampus</th>
                                    <th scope="col" class="text-center">Tiket Dimiliki</th>
                                    <th scope="col" class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                [$p, $per, $q] = list_page();
                                $where = "u.role = 'mahasiswa'";
                                if ($q !== '') {
                                    $like = mysqli_real_escape_string($conn, $q);
                                    $where .= " AND (u.nama_lengkap LIKE '%$like%' OR u.nim LIKE '%$like%' OR u.prodi LIKE '%$like%' OR u.email LIKE '%$like%')";
                                }
                                $total = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM users u WHERE $where"))['c'];
                                $p = min($p, max(1, (int) ceil($total / $per)));
                                $offset = ($p - 1) * $per;
                                $query_mhs = mysqli_query($conn, "
                                    SELECT u.id_user, u.nama_lengkap, u.nim, u.prodi, u.email, COUNT(r.id_registrasi) AS total_tiket
                                    FROM users u
                                    LEFT JOIN registrations r ON u.id_user = r.id_user
                                    WHERE $where
                                    GROUP BY u.id_user
                                    ORDER BY u.nama_lengkap ASC
                                    LIMIT $offset, $per
                                ");

                                $no = $offset + 1;
                                if (mysqli_num_rows($query_mhs) > 0) {
                                    while ($mhs = mysqli_fetch_assoc($query_mhs)) {
                                        $nim_tampil = !empty($mhs['nim']) ? e($mhs['nim']) : '<span class="text-muted fst-italic">-</span>';
                                        $prodi_tampil = !empty($mhs['prodi']) ? e($mhs['prodi']) : '<span class="text-muted fst-italic">-</span>';
                                        $id_mhs = (int) $mhs['id_user'];
                                        $nama_attr = e($mhs['nama_lengkap']);

                                        echo "<tr>
                                                <td class='text-center text-muted'>{$no}</td>
                                                <td><span class='badge bg-light text-dark border px-2 py-1 font-monospace'>{$nim_tampil}</span></td>
                                                <td><span class='fw-semibold text-dark'>" . e($mhs['nama_lengkap']) . "</span></td>
                                                <td class='text-secondary'>{$prodi_tampil}</td>
                                                <td class='text-muted small'>" . e($mhs['email']) . "</td>
                                                <td class='text-center'><span class='badge badge-soft-primary px-3 py-2 rounded-pill'>" . (int) $mhs['total_tiket'] . " Tiket</span></td>
                                                <td class='text-center'>
                                                    <div class='d-flex justify-content-center gap-1'>
                                                        <a href='?page=detail_mahasiswa&id={$id_mhs}' class='btn btn-sm btn-light-primary fw-semibold'>
                                                            <i class='bi bi-eye me-1'></i>Detail
                                                        </a>
                                                        <button type='button'
                                                            class='btn btn-sm btn-light-danger fw-semibold js-hapus-mahasiswa'
                                                            data-id='{$id_mhs}' data-nama=\"$nama_attr\">
                                                            <i class='bi bi-trash me-1'></i>Hapus
                                                        </button>
                                                    </div>
                                                </td>
                                              </tr>";
                                        $no++;
                                    }
                                } else {
                                    echo "<tr><td colspan='7' class='text-center py-4 text-muted'>" . ($q !== '' ? 'Tidak ada mahasiswa yang cocok dengan pencarian "' . e($q) . '".' : 'Belum ada mahasiswa yang mendaftar.') . "</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <?= table_footer($total, $p, $per) ?>
                </div>

                <!-- Modal Konfirmasi Hapus Mahasiswa -->
                <div class="modal fade" id="modalHapusMhs" tabindex="-1" aria-labelledby="modalHapusMhsLabel" aria-modal="true" role="dialog">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg rounded-4">
                            <div class="modal-header border-0 pb-0">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="icon-tint icon-tint--danger">
                                        <i class="bi bi-trash-fill fs-5"></i>
                                    </div>
                                    <div>
                                        <h5 class="modal-title fw-bold text-body-emphasis mb-0" id="modalHapusMhsLabel">Hapus Mahasiswa?</h5>
                                        <p class="text-muted small mb-0">Tindakan ini tidak dapat dibatalkan</p>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-body pt-3">
                                <p class="text-secondary mb-0">Mahasiswa <strong id="judulHapusMhs"></strong> dan seluruh tiket pendaftarannya akan dihapus permanen.</p>
                            </div>
                            <div class="modal-footer border-0 pt-0">
                                <button type="button" class="btn btn-light fw-semibold px-4" data-bs-dismiss="modal">Batal</button>
                                <form method="POST" action="admin.php?page=mahasiswa" class="m-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id_user" id="inputHapusMhsId" value="">
                                    <button type="submit" name="hapus_mahasiswa" class="btn btn-danger fw-semibold px-4">
                                        <i class="bi bi-trash me-1"></i>Ya, Hapus
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php
            // ---------------------------------------------------------
            // MENU 5: DATA PEMATERI
            // ---------------------------------------------------------
            } elseif ($page == 'pemateri') {
            ?>
                <div class="card card-custom card-static overflow-hidden">
                    <div class="card-header bg-white border-bottom py-3 px-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h6 class="fw-bold mb-0 text-dark">Data Pemateri Seminar</h6>
                        <div class="admin-toolbar">
                            <form method="get" action="admin.php" class="input-group input-group-sm" style="width: 220px;" role="search">
                                <input type="hidden" name="page" value="pemateri">
                                <button type="submit" class="input-group-text bg-white border-end-0 text-muted" aria-label="Cari"><i class="bi bi-search"></i></button>
                                <input type="search" name="q" class="form-control border-start-0 ps-0" value="<?= e(trim((string) ($_GET['q'] ?? ''))) ?>" placeholder="Cari pemateri..." aria-label="Cari pemateri">
                            </form>
                            <a href="?page=tambah_pemateri" class="btn btn-sm btn-primary">
                                <i class="bi bi-person-plus-fill me-1"></i> Tambah Pemateri
                            </a>
                        </div>
                    </div>
                    <div class="table-responsive" tabindex="0" role="region" aria-label="Tabel data pemateri">
                        <table class="table table-custom">
                            <caption class="visually-hidden">Daftar pemateri seminar</caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="text-center">No</th>
                                    <th scope="col">Foto</th>
                                    <th scope="col">Nama Pemateri</th>
                                    <th scope="col">Institusi / Lembaga</th>
                                    <th scope="col">Email</th>
                                    <th scope="col" class="text-center">No HP</th>
                                    <th scope="col" class="text-center">Jumlah Seminar</th>
                                    <th scope="col" class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                [$p, $per, $q] = list_page();
                                $where = "1=1";
                                if ($q !== '') {
                                    $like = mysqli_real_escape_string($conn, $q);
                                    $where = "p.nama LIKE '%$like%' OR p.keahlian LIKE '%$like%' OR p.institusi LIKE '%$like%' OR p.email LIKE '%$like%'";
                                }
                                $total = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM pemateri p WHERE $where"))['c'];
                                $p = min($p, max(1, (int) ceil($total / $per)));
                                $offset = ($p - 1) * $per;
                                $query_pem = mysqli_query($conn, "
                                    SELECT p.id_pemateri, p.nama, p.keahlian, p.institusi, p.email, p.no_hp, p.foto,
                                           COUNT(s.id_seminar) AS total_acara
                                    FROM pemateri p
                                    LEFT JOIN seminars s ON p.id_pemateri = s.id_pemateri
                                    WHERE $where
                                    GROUP BY p.id_pemateri
                                    ORDER BY p.nama ASC
                                    LIMIT $offset, $per
                                ");

                                $no = $offset + 1;
                                if (mysqli_num_rows($query_pem) > 0) {
                                    while ($pmt = mysqli_fetch_assoc($query_pem)) {
                                        $foto_path = !empty($pmt['foto']) ? "uploads/pemateri/" . $pmt['foto'] : placeholder_data_uri('Pemateri', 85, 85);

                                        $nama_attr = e($pmt['nama']);
                                        $id_pem_attr = (int) $pmt['id_pemateri'];
                                        $institusi_tampil = !empty($pmt['institusi']) ? e($pmt['institusi']) : '<span class="text-muted fst-italic">-</span>';
                                        $email_tampil = !empty($pmt['email']) ? e($pmt['email']) : '<span class="text-muted fst-italic">-</span>';
                                        $nohp_tampil = !empty($pmt['no_hp']) ? e($pmt['no_hp']) : '<span class="text-muted fst-italic">-</span>';

                                        echo "<tr>
                                                <td class='text-center text-muted'>{$no}</td>
                                                <td class='text-center' style='width:95px;'>
                                                    <img src='" . e($foto_path) . "' alt='Foto pemateri' class='rounded-1 border shadow-sm' width='85' height='85' loading='lazy' decoding='async' style='object-fit:cover;'>
                                                </td>
                                                <td>
                                                    <span class='fw-semibold text-dark d-block'>" . e($pmt['nama']) . "</span>
                                                    <small class='text-muted'>" . e($pmt['keahlian']) . "</small>
                                                </td>
                                                <td class='text-secondary'>{$institusi_tampil}</td>
                                                <td class='text-muted small'>{$email_tampil}</td>
                                                <td class='text-center'><span class='badge bg-light text-dark border px-2 py-1 font-monospace'>{$nohp_tampil}</span></td>
                                                <td class='text-center'><span class='badge badge-soft-primary px-3 py-2 rounded-pill'>" . (int) $pmt['total_acara'] . " Acara</span></td>
                                                <td class='text-center'>
                                                    <div class='d-flex justify-content-center gap-1'>
                                                        <a href='?page=detail_pemateri&id=" . (int) $pmt['id_pemateri'] . "' class='btn btn-sm btn-light-primary fw-semibold'>
                                                            <i class='bi bi-eye me-1'></i>Detail
                                                        </a>
                                                        <button type='button'
                                                            class='btn btn-sm btn-light-danger fw-semibold js-hapus-pemateri'
                                                            data-id='$id_pem_attr' data-nama=\"$nama_attr\">
                                                            <i class='bi bi-trash me-1'></i>Hapus
                                                        </button>
                                                    </div>
                                                </td>
                                              </tr>";
                                        $no++;
                                    }
                                } else {
                                    echo "<tr><td colspan='8' class='text-center py-4 text-muted'>" . ($q !== '' ? 'Tidak ada pemateri yang cocok dengan pencarian "' . e($q) . '".' : 'Belum ada data pemateri.') . "</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <?= table_footer($total, $p, $per) ?>
                </div>

                <!-- Modal Konfirmasi Hapus Pemateri -->
                <div class="modal fade" id="modalHapusPem" tabindex="-1" aria-labelledby="modalHapusPemLabel" aria-modal="true" role="dialog">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg rounded-4">
                            <div class="modal-header border-0 pb-0">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="icon-tint icon-tint--danger">
                                        <i class="bi bi-trash-fill fs-5"></i>
                                    </div>
                                    <div>
                                        <h5 class="modal-title fw-bold text-body-emphasis mb-0" id="modalHapusPemLabel">Hapus Pemateri?</h5>
                                        <p class="text-muted small mb-0">Tindakan ini tidak dapat dibatalkan</p>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-body pt-3">
                                <p class="text-secondary mb-0">Pemateri <strong id="judulHapusPem"></strong> dan seminar yang terhubung dengannya akan dihapus permanen.</p>
                            </div>
                            <div class="modal-footer border-0 pt-0">
                                <button type="button" class="btn btn-light fw-semibold px-4" data-bs-dismiss="modal">Batal</button>
                                <form method="POST" action="admin.php?page=pemateri" class="m-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id_pemateri" id="inputHapusPemId" value="">
                                    <button type="submit" name="hapus_pemateri" class="btn btn-danger fw-semibold px-4">
                                        <i class="bi bi-trash me-1"></i>Ya, Hapus
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

            <?php
            // ---------------------------------------------------------
            // MENU 5.1: TAMBAH PEMATERI
            // ---------------------------------------------------------
            } elseif ($page == 'tambah_pemateri') {
            ?>
                <div class="card card-custom card-static">
                    <div class="card-header bg-white border-bottom py-3 px-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; background: var(--primary-light); color: var(--primary);">
                                <i class="bi bi-person-plus-fill fs-5"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark">Tambah Pemateri Baru</h6>
                                <p class="text-muted small mb-0">Lengkapi data profil pemateri acara</p>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="admin.php?page=pemateri" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="nama_pemateri" class="form-label small fw-semibold text-secondary">Nama Lengkap Pemateri</label>
                                    <input type="text" class="form-control" id="nama_pemateri" name="nama" maxlength="150" value="<?= e(old('nama')) ?>" placeholder="Contoh: Dr. Budi Santoso, M.Kom" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="keahlian" class="form-label small fw-semibold text-secondary">Keahlian / Gelar / Jabatan</label>
                                    <input type="text" class="form-control" id="keahlian" name="keahlian" maxlength="150" value="<?= e(old('keahlian')) ?>" placeholder="Contoh: AI Specialist / Senior Data Scientist">
                                </div>
                                <div class="col-md-6">
                                    <label for="institusi" class="form-label small fw-semibold text-secondary">Institusi / Perusahaan</label>
                                    <input type="text" class="form-control" id="institusi" name="institusi" maxlength="150" value="<?= e(old('institusi')) ?>" placeholder="Contoh: Universitas Indonesia / Tech Corp">
                                </div>
                                <div class="col-md-6">
                                    <label for="email_pemateri" class="form-label small fw-semibold text-secondary">Email</label>
                                    <input type="email" class="form-control" id="email_pemateri" name="email" maxlength="100" value="<?= e(old('email')) ?>" placeholder="contoh: budi@kampus.ac.id">
                                </div>
                                <div class="col-md-6">
                                    <label for="no_hp" class="form-label small fw-semibold text-secondary">No WhatsApp / HP</label>
                                    <input type="text" class="form-control" id="no_hp" name="no_hp" maxlength="30" value="<?= e(old('no_hp')) ?>" inputmode="tel" placeholder="Contoh: 08123456789">
                                </div>
                                <div class="col-md-6">
                                    <label for="foto_pemateri" class="form-label small fw-semibold text-secondary">Foto Profil (Max 2MB)</label>
                                    <input type="file" class="form-control" id="foto_pemateri" name="foto" accept=".jpg,.jpeg,.png,.webp">
                                </div>
                                <div class="col-12">
                                    <label for="bio" class="form-label small fw-semibold text-secondary">Biografi Singkat</label>
                                    <textarea class="form-control" id="bio" name="bio" rows="4" placeholder="Tuliskan pengalaman, rekam jejak, dan biografi singkat pemateri..."><?= e(old('bio')) ?></textarea>
                                </div>
                            </div>
                            <div class="mt-4 pt-3 border-top">
                                <button type="submit" name="tambah_pemateri" class="btn btn-primary px-4">
                                    <i class="bi bi-save me-1"></i> Simpan Pemateri
                                </button>
                                <a href="?page=pemateri" class="btn btn-light px-4 ms-2">Batal</a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php
            // ---------------------------------------------------------
            // MENU 5.2: DETAIL PEMATERI
            // ---------------------------------------------------------
            } elseif ($page == 'detail_pemateri') {
                $id_pem = (int) ($_GET['id'] ?? 0);
                $pmt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM pemateri WHERE id_pemateri='$id_pem'"));
                if (!$pmt) {
                    flash_set('danger', 'Pemateri tidak ditemukan.');
                    redirect('admin.php?page=pemateri');
                }
                $foto_path = (!empty($pmt['foto']) && file_exists("uploads/pemateri/" . $pmt['foto']))
                    ? "uploads/pemateri/" . $pmt['foto']
                    : placeholder_data_uri('Pemateri', 150, 150);
            ?>
                <nav aria-label="Breadcrumb" class="mb-3">
                    <ol class="breadcrumb mb-0 small">
                        <li class="breadcrumb-item"><a href="?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="?page=pemateri" class="text-decoration-none">Data Pemateri</a></li>
                        <li class="breadcrumb-item active" aria-current="page"><?= e($pmt['nama']) ?></li>
                    </ol>
                </nav>
                <div class="row g-4">
                    <div class="col-lg-4">
                        <div class="card card-custom text-center p-4 h-100">
                            <img src="<?= e($foto_path) ?>" alt="Foto <?= htmlspecialchars($pmt['nama']) ?>" class="rounded-circle mx-auto mb-3 shadow-sm border" width="100" height="100" loading="lazy" decoding="async" style="object-fit:cover;">
                            <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($pmt['nama']) ?></h5>
                            <p class="text-primary small fw-semibold mb-3"><?= htmlspecialchars($pmt['keahlian']) ?></p>
                            
                            <div class="d-grid gap-2 text-start">
                                <div class="p-3 bg-light rounded-3 border">
                                    <div class="small text-muted mb-1"><i class="bi bi-building me-1"></i> Institusi</div>
                                    <div class="small fw-bold text-dark"><?= !empty($pmt['institusi']) ? htmlspecialchars($pmt['institusi']) : '-' ?></div>
                                </div>
                                <div class="p-3 bg-light rounded-3 border">
                                    <div class="small text-muted mb-1"><i class="bi bi-envelope me-1"></i> Email</div>
                                    <div class="small fw-bold text-dark"><?= !empty($pmt['email']) ? htmlspecialchars($pmt['email']) : '-' ?></div>
                                </div>
                                <div class="p-3 bg-light rounded-3 border">
                                    <div class="small text-muted mb-1"><i class="bi bi-whatsapp me-1"></i> No WhatsApp / HP</div>
                                    <div class="small fw-bold text-dark"><?= !empty($pmt['no_hp']) ? htmlspecialchars($pmt['no_hp']) : '-' ?></div>
                                </div>
                            </div>
                            
                            <div class="mt-4 pt-2">
                                <a href="?page=pemateri" class="btn btn-light border w-100 fw-semibold">
                                    <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-8">
                        <div class="card card-custom mb-4">
                            <div class="card-header bg-white border-bottom py-3 px-4">
                                <h6 class="fw-bold mb-0 text-dark">Biografi Pemateri</h6>
                            </div>
                            <div class="card-body p-4">
                                <p class="text-secondary mb-0" style="white-space: pre-line; line-height: 1.7;"><?= !empty($pmt['bio']) ? htmlspecialchars($pmt['bio']) : '<span class="text-muted fst-italic">Belum ada biografi yang ditambahkan untuk pemateri ini.</span>' ?></p>
                            </div>
                        </div>

                        <div class="card card-custom card-static overflow-hidden">
                            <div class="card-header bg-white border-bottom py-3 px-4">
                                <h6 class="fw-bold mb-0 text-dark">Daftar Seminar yang Dibawakan</h6>
                            </div>
                            <div class="table-responsive" tabindex="0" role="region" aria-label="Tabel seminar pemateri">
                                <table class="table table-custom">
                                    <caption class="visually-hidden">Seminar yang dibawakan pemateri</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col">Judul Seminar</th>
                                            <th scope="col">Tanggal</th>
                                            <th scope="col">Kuota</th>
                                            <th scope="col" class="text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $sem_list = mysqli_query($conn, "SELECT judul, tanggal, kuota, status FROM seminars WHERE id_pemateri='$id_pem' ORDER BY tanggal DESC");
                                        if (mysqli_num_rows($sem_list) > 0) {
                                            while ($s = mysqli_fetch_assoc($sem_list)) {
                                                $badge_status = $s['status'] == 'Aktif'
                                                    ? "<span class='badge badge-soft-success rounded-pill px-3 py-1'>Aktif</span>"
                                                    : "<span class='badge badge-soft-warning rounded-pill px-3 py-1'>Selesai</span>";
                                                echo "<tr>
                                                        <td><span class='fw-semibold text-dark'>" . e($s['judul']) . "</span></td>
                                                        <td class='small text-muted'><i class='bi bi-calendar3 me-1'></i>" . e(date('d M Y', strtotime($s['tanggal']))) . "</td>
                                                        <td><span class='badge bg-light text-dark border px-2 py-1'>" . (int) $s['kuota'] . " Kursi</span></td>
                                                        <td class='text-center'>{$badge_status}</td>
                                                      </tr>";
                                            }
                                        } else {
                                            echo "<tr><td colspan='4' class='text-center py-4 text-muted small'>Pemateri ini belum memiliki jadwal seminar.</td></tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            <?php
            // ---------------------------------------------------------
            // MENU 4.1: DETAIL MAHASISWA
            // ---------------------------------------------------------
            } elseif ($page == 'detail_mahasiswa') {
                $id_mhs = (int) ($_GET['id'] ?? 0);
                $mhs = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id_user='$id_mhs' AND role='mahasiswa'"));
                if (!$mhs) {
                    flash_set('danger', 'Mahasiswa tidak ditemukan.');
                    redirect('admin.php?page=mahasiswa');
                }
                // Statistik tiket
                $tot_tiket = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as jml FROM registrations WHERE id_user='$id_mhs'"))['jml'];
                $tot_hadir = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as jml FROM registrations WHERE id_user='$id_mhs' AND status_hadir='Hadir'"))['jml'];
            ?>
                <nav aria-label="Breadcrumb" class="mb-3">
                    <ol class="breadcrumb mb-0 small">
                        <li class="breadcrumb-item"><a href="?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="?page=mahasiswa" class="text-decoration-none">Data Mahasiswa</a></li>
                        <li class="breadcrumb-item active" aria-current="page"><?= e($mhs['nama_lengkap'] ?? 'Tidak ditemukan') ?></li>
                    </ol>
                </nav>
                <div class="row g-4">
                    <div class="col-lg-4">
                        <div class="card card-custom text-center p-4 h-100">
                            <?php 
                            $mhs_foto = (!empty($mhs['foto']) && file_exists("uploads/profiles/" . $mhs['foto'])) 
                                ? "uploads/profiles/" . $mhs['foto'] 
                                : null;
                            if ($mhs_foto): ?>
                                <img src="<?= e($mhs_foto) ?>" alt="Foto <?= htmlspecialchars($mhs['nama_lengkap']) ?>" class="rounded-circle shadow-sm border p-1 bg-white mb-3 mx-auto d-block" width="100" height="100" loading="lazy" decoding="async" style="object-fit: cover;">
                            <?php else: ?>
                                <div class="rounded-circle bg-primary-light text-primary d-flex align-items-center justify-content-center mb-3 shadow-sm border mx-auto" style="width: 100px; height: 100px; font-size: 2.5rem;">
                                    <i class="bi bi-person-fill"></i>
                                </div>
                            <?php endif; ?>
                            <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($mhs['nama_lengkap']) ?></h5>
                            <p class="text-muted small mb-3"><?= !empty($mhs['nim']) ? htmlspecialchars($mhs['nim']) : '<span class="text-muted fst-italic">Belum diisi NIM</span>' ?></p>
                            
                            <div class="d-grid gap-2 text-start">
                                <div class="p-3 bg-light rounded-3 border">
                                    <div class="small text-muted mb-1"><i class="bi bi-award me-1"></i> Program Studi</div>
                                    <div class="small fw-bold text-dark"><?= !empty($mhs['prodi']) ? htmlspecialchars($mhs['prodi']) : '-' ?></div>
                                </div>
                                <div class="p-3 bg-light rounded-3 border">
                                    <div class="small text-muted mb-1"><i class="bi bi-envelope me-1"></i> Email</div>
                                    <div class="small fw-bold text-dark"><?= htmlspecialchars($mhs['email']) ?></div>
                                </div>
                            </div>
                            
                            <div class="row g-2 text-center mt-3">
                                <div class="col-6">
                                    <div class="p-2 bg-light rounded-3 border">
                                        <div class="fw-bold text-dark fs-5"><?= $tot_tiket ?></div>
                                        <div class="small text-muted">Total Tiket</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="p-2 bg-light rounded-3 border">
                                        <div class="fw-bold text-dark fs-5"><?= $tot_hadir ?></div>
                                        <div class="small text-muted">Hadir</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-4 pt-2">
                                <a href="?page=mahasiswa" class="btn btn-light border w-100 fw-semibold">
                                    <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar
                                </a>
                            </div>

                            <div class="mt-4 pt-3 border-top text-start">
                                <h6 class="fw-bold text-dark small mb-2"><i class="bi bi-key me-1"></i>Reset Password</h6>
                                <form method="POST" action="admin.php?page=detail_mahasiswa&id=<?= (int) $id_mhs ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id_user" value="<?= (int) $id_mhs ?>">
                                    <div class="input-group input-group-sm">
                                        <label for="password_baru_reset" class="visually-hidden">Password baru mahasiswa</label>
                                        <input type="password" class="form-control" id="password_baru_reset" name="password_baru" placeholder="Password baru (min. 8)" minlength="8" autocomplete="new-password" required>
                                        <button type="submit" name="reset_password" class="btn btn-outline-primary">Reset</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-8">
                        <div class="card card-custom">
                            <div class="card-header bg-white border-bottom py-3 px-4">
                                <h6 class="fw-bold mb-0 text-dark">Riwayat Pendaftaran Seminar</h6>
                            </div>
                            <div class="table-responsive" tabindex="0" role="region" aria-label="Tabel riwayat pendaftaran">
                                <table class="table table-custom">
                                    <caption class="visually-hidden">Riwayat pendaftaran seminar mahasiswa</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col">Judul Seminar</th>
                                            <th scope="col">Tanggal</th>
                                            <th scope="col">Kode Tiket</th>
                                            <th scope="col" class="text-center">Status Kehadiran</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $reg_list = mysqli_query($conn, "
                                            SELECT s.judul, s.tanggal, r.kode_tiket, r.status_hadir
                                            FROM registrations r
                                            JOIN seminars s ON r.id_seminar = s.id_seminar
                                            WHERE r.id_user = '$id_mhs'
                                            ORDER BY s.tanggal DESC
                                        ");
                                        if (mysqli_num_rows($reg_list) > 0) {
                                            while ($reg = mysqli_fetch_assoc($reg_list)) {
                                                $badge_hadir = $reg['status_hadir'] == 'Hadir'
                                                    ? "<span class='badge badge-soft-success rounded-pill px-3 py-1'>Hadir</span>"
                                                    : "<span class='badge badge-soft-warning rounded-pill px-3 py-1'>Belum Hadir</span>";
                                                echo "<tr>
                                                        <td><span class='fw-semibold text-dark'>" . e($reg['judul']) . "</span></td>
                                                        <td class='small text-muted'><i class='bi bi-calendar3 me-1'></i>" . e(date('d M Y', strtotime($reg['tanggal']))) . "</td>
                                                        <td><code class='text-primary'>" . e($reg['kode_tiket']) . "</code></td>
                                                        <td class='text-center'>{$badge_hadir}</td>
                                                      </tr>";
                                            }
                                        } else {
                                            echo "<tr><td colspan='4' class='text-center py-4 text-muted small'>Belum memiliki tiket seminar.</td></tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            <?php
            // ---------------------------------------------------------
            // MENU 6: LAPORAN
            // ---------------------------------------------------------
            } elseif ($page == 'laporan') {
            ?>
                <div class="card card-custom card-static overflow-hidden">
                    <div class="card-header bg-white border-bottom py-3 px-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h6 class="fw-bold mb-0 text-dark">Laporan Presensi & Kehadiran Peserta</h6>
                        <div class="admin-toolbar">
                        <form method="get" action="admin.php" class="input-group input-group-sm" style="width: 220px;" role="search">
                            <input type="hidden" name="page" value="laporan">
                            <button type="submit" class="input-group-text bg-white border-end-0 text-muted" aria-label="Cari"><i class="bi bi-search"></i></button>
                            <input type="search" name="q" class="form-control border-start-0 ps-0" value="<?= e(trim((string) ($_GET['q'] ?? ''))) ?>" placeholder="Cari seminar..." aria-label="Cari seminar pada laporan">
                        </form>
                        </div>
                    </div>
                    <div class="table-responsive" tabindex="0" role="region" aria-label="Tabel laporan presensi">
                        <table class="table table-custom">
                            <caption class="visually-hidden">Laporan presensi dan kehadiran peserta</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Judul Seminar</th>
                                    <th scope="col">Tanggal Pelaksanaan</th>
                                    <th scope="col" class="text-center">Pendaftar</th>
                                    <th scope="col" class="text-center">Hadir</th>
                                    <th scope="col" class="text-center">Aksi Export</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                [$p, $per, $q] = list_page();
                                $where = '';
                                if ($q !== '') {
                                    $like = mysqli_real_escape_string($conn, $q);
                                    $where = "WHERE s.judul LIKE '%$like%' OR s.pemateri LIKE '%$like%'";
                                }
                                $total = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM seminars s $where"))['c'];
                                $p = min($p, max(1, (int) ceil($total / $per)));
                                $offset = ($p - 1) * $per;
                                $query_lap = mysqli_query($conn, "
                                    SELECT s.id_seminar, s.judul, s.tanggal,
                                           COUNT(r.id_registrasi) AS tot_daftar,
                                           COALESCE(SUM(r.status_hadir = 'Hadir'), 0) AS tot_hadir
                                    FROM seminars s
                                    LEFT JOIN registrations r ON r.id_seminar = s.id_seminar
                                    $where
                                    GROUP BY s.id_seminar, s.judul, s.tanggal
                                    ORDER BY s.tanggal DESC
                                    LIMIT $offset, $per
                                ");

                                if (mysqli_num_rows($query_lap) > 0) {
                                    while ($lap = mysqli_fetch_assoc($query_lap)) {
                                        $id_sem = (int) $lap['id_seminar'];
                                        $tot_daftar = (int) $lap['tot_daftar'];
                                        $tot_hadir = (int) $lap['tot_hadir'];

                                        echo "<tr>
                                                <td><span class='fw-semibold text-dark'>" . e($lap['judul']) . "</span></td>
                                                <td class='text-muted small'><i class='bi bi-calendar3 me-1'></i>" . e(date('d M Y', strtotime($lap['tanggal']))) . "</td>
                                                <td class='text-center'><span class='badge bg-light text-muted border px-2 py-1'>{$tot_daftar} Peserta</span></td>
                                                <td class='text-center'><span class='badge badge-soft-success px-3 py-1 rounded-pill'>{$tot_hadir} Hadir</span></td>
                                                <td class='text-center'>
                                                    <a href='export_excel.php?id={$id_sem}&format=xlsx' class='btn btn-sm btn-light-primary fw-semibold me-1'>
                                                        <i class='bi bi-file-earmark-excel me-1'></i> Excel .xlsx
                                                    </a>
                                                    <a href='export_excel.php?id={$id_sem}&format=csv' class='btn btn-sm btn-light-secondary fw-semibold'>
                                                        <i class='bi bi-filetype-csv me-1'></i> CSV
                                                    </a>
                                                </td>
                                              </tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='5' class='text-center py-4 text-muted'>" . ($q !== '' ? 'Tidak ada seminar yang cocok dengan pencarian "' . e($q) . '".' : 'Belum ada data seminar.') . "</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <?= table_footer($total, $p, $per) ?>
                </div>
            <?php
            // ---------------------------------------------------------
            // MENU 7: DAFTAR PESERTA (admin & panitia)
            // ---------------------------------------------------------
            } elseif ($page == 'peserta') {
                $id_sem = (int) ($_GET['id'] ?? 0);
                $hariIniP = date('Y-m-d');
                $boleh_lihat = false;
                $sem = null;

                if ($id_sem > 0) {
                    $stmt = mysqli_prepare($conn, "SELECT id_seminar, judul, tanggal, jam_mulai, jam_selesai, status FROM seminars WHERE id_seminar = ? LIMIT 1");
                    mysqli_stmt_bind_param($stmt, 'i', $id_sem);
                    mysqli_stmt_execute($stmt);
                    $sem = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                    mysqli_stmt_close($stmt);
                    if ($sem) {
                        $status_efektif_p = seminar_status_efektif((string) $sem['status'], (string) $sem['tanggal'], $hariIniP);
                        if ($role_aktif === 'admin') {
                            $boleh_lihat = true;
                        } else {
                            $boleh_lihat = $status_efektif_p === 'Aktif' && (string) $sem['tanggal'] === $hariIniP;
                        }
                    }
                }

                if ($id_sem > 0 && !$boleh_lihat) {
                    echo '<div class="alert alert-warning border-0 rounded-3" role="alert"><i class="bi bi-exclamation-triangle-fill me-2"></i>'
                        . ($sem ? 'Seminar ini bukan seminar hari ini.' : 'Seminar tidak ditemukan.') . '</div>';
                    echo '<a href="admin.php?page=peserta" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left me-1"></i>Kembali</a>';
                } elseif ($sem) {
                    $status_efektif_p = seminar_status_efektif((string) $sem['status'], (string) $sem['tanggal'], $hariIniP);
                    $jendela_terbuka = $status_efektif_p === 'Aktif' && checkin_window_error((string) $sem['tanggal'], $hariIniP) === null;

                    [$p, $per, $q] = list_page(20);
                    $where = "r.id_seminar = " . (int) $sem['id_seminar'];
                    $argsType = '';
                    $argsVal = [];
                    if ($q !== '') {
                        $like = '%' . like_escape($q) . '%';
                        $where .= " AND (u.nama_lengkap LIKE ? ESCAPE '\\\\' OR u.nim LIKE ? ESCAPE '\\\\')";
                        $argsType = 'ss';
                        $argsVal = [$like, $like];
                    }
                    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM registrations r JOIN users u ON r.id_user = u.id_user WHERE $where");
                    if ($argsType !== '') { mysqli_stmt_bind_param($stmt, $argsType, ...$argsVal); }
                    mysqli_stmt_execute($stmt);
                    $total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
                    mysqli_stmt_close($stmt);
                    $p = min($p, max(1, (int) ceil($total / $per)));
                    $offset = ($p - 1) * $per;

                    $stmt = mysqli_prepare($conn, "SELECT r.id_registrasi, r.kode_tiket, r.status_hadir, r.checkin_at,
                            u.nama_lengkap, u.nim, u.prodi
                        FROM registrations r JOIN users u ON r.id_user = u.id_user
                        WHERE $where
                        ORDER BY u.nama_lengkap ASC LIMIT " . (int) $per . " OFFSET " . (int) $offset);
                    if ($argsType !== '') { mysqli_stmt_bind_param($stmt, $argsType, ...$argsVal); }
                    mysqli_stmt_execute($stmt);
                    $peserta_rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
                    mysqli_stmt_close($stmt);

                    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c, COALESCE(SUM(r.status_hadir = 'Hadir'),0) AS hadir FROM registrations r WHERE r.id_seminar = ?");
                    mysqli_stmt_bind_param($stmt, 'i', $sem['id_seminar']);
                    mysqli_stmt_execute($stmt);
                    $ringkas = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                    mysqli_stmt_close($stmt);
            ?>
                <div class="card card-custom card-static overflow-hidden">
                    <div class="card-header bg-white border-bottom py-3 px-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <h6 class="fw-bold mb-0 text-dark"><?= e($sem['judul']) ?></h6>
                                <small class="text-muted"><i class="bi bi-calendar3 me-1"></i><?= e(date('d M Y', strtotime($sem['tanggal']))) ?><?= $sem['jam_mulai'] ? ' · ' . e(substr((string) $sem['jam_mulai'], 0, 5)) : '' ?><?= $sem['jam_selesai'] ? '-' . e(substr((string) $sem['jam_selesai'], 0, 5)) : '' ?></small>
                            </div>
                            <span class="badge badge-soft-primary px-3 py-2 rounded-pill">Hadir <?= (int) $ringkas['hadir'] ?> dari <?= (int) $ringkas['c'] ?> terdaftar</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="get" action="admin.php" class="input-group input-group-sm mb-3" style="max-width: 320px;" role="search">
                            <input type="hidden" name="page" value="peserta">
                            <input type="hidden" name="id" value="<?= (int) $sem['id_seminar'] ?>">
                            <button type="submit" class="input-group-text bg-white border-end-0 text-muted" aria-label="Cari"><i class="bi bi-search"></i></button>
                            <input type="search" name="q" class="form-control border-start-0 ps-0" value="<?= e($q) ?>" placeholder="Cari nama atau NIM..." aria-label="Cari peserta">
                        </form>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">Nama</th>
                                        <th scope="col">NIM</th>
                                        <th scope="col">Prodi</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Check-in</th>
                                        <th scope="col" class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($peserta_rows): foreach ($peserta_rows as $row): ?>
                                        <tr>
                                            <td class="fw-semibold text-dark"><?= e($row['nama_lengkap']) ?></td>
                                            <td class="text-muted"><?= e((string) $row['nim']) ?></td>
                                            <td class="text-muted small"><?= e((string) $row['prodi']) ?></td>
                                            <td>
                                                <?php if ($row['status_hadir'] === 'Hadir'): ?>
                                                    <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle rounded-pill px-3 py-1">Hadir</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary-emphasis border rounded-pill px-3 py-1">Belum Hadir</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-muted small"><?= $row['checkin_at'] ? e(date('H:i', strtotime((string) $row['checkin_at']))) : '—' ?></td>
                                            <td class="text-center">
                                                <div class="d-flex justify-content-center gap-1">
                                                    <?php if ($row['status_hadir'] === 'Belum Hadir' && $jendela_terbuka): ?>
                                                        <form method="POST" action="admin.php?page=peserta&id=<?= (int) $sem['id_seminar'] ?>" class="d-inline m-0">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="id_registrasi" value="<?= (int) $row['id_registrasi'] ?>">
                                                            <input type="hidden" name="id_seminar" value="<?= (int) $sem['id_seminar'] ?>">
                                                            <button type="submit" name="checkin_manual" class="btn btn-sm btn-light-success fw-semibold"><i class="bi bi-check-circle me-1"></i>Tandai hadir</button>
                                                        </form>
                                                    <?php elseif ($row['status_hadir'] === 'Hadir' && $role_aktif === 'admin' && $jendela_terbuka): ?>
                                                        <button type="button" class="btn btn-sm btn-light-danger fw-semibold js-batal-checkin"
                                                            data-id="<?= (int) $row['id_registrasi'] ?>"
                                                            data-nama="<?= e($row['nama_lengkap']) ?>"
                                                            data-seminar="<?= (int) $sem['id_seminar'] ?>">
                                                            <i class="bi bi-arrow-counterclockwise me-1"></i>Batalkan
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-muted small">—</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; else: ?>
                                        <tr><td colspan="6" class="text-center py-4 text-muted"><?= $q !== '' ? 'Tidak ada peserta yang cocok dengan pencarian.' : 'Belum ada peserta terdaftar.' ?></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?= table_footer($total, $p, $per) ?>
                </div>

                <?php if ($role_aktif === 'admin'): ?>
                <!-- Modal Batalkan Check-in -->
                <div class="modal fade" id="modalBatalCheckin" tabindex="-1" aria-labelledby="modalBatalCheckinLabel" aria-modal="true" role="dialog">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg rounded-4">
                            <div class="modal-header border-0 pb-0">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="icon-tint icon-tint--danger"><i class="bi bi-arrow-counterclockwise fs-5"></i></div>
                                    <div>
                                        <h5 class="modal-title fw-bold text-body-emphasis mb-0" id="modalBatalCheckinLabel">Batalkan Check-in?</h5>
                                        <p class="text-muted small mb-0">Status peserta kembali ke Belum Hadir</p>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-body pt-3">
                                <p class="text-secondary mb-0">Check-in <strong id="namaBatalCheckin"></strong> akan dibatalkan dan waktu check-in dihapus.</p>
                            </div>
                            <div class="modal-footer border-0 pt-0">
                                <button type="button" class="btn btn-light fw-semibold px-4" data-bs-dismiss="modal">Batal</button>
                                <form method="POST" action="admin.php?page=peserta&id=<?= (int) $sem['id_seminar'] ?>" class="m-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id_registrasi" id="inputBatalCheckinId">
                                    <input type="hidden" name="id_seminar" value="<?= (int) $sem['id_seminar'] ?>">
                                    <button type="submit" name="batalkan_checkin" class="btn btn-danger fw-semibold px-4">Ya, Batalkan</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        var el = document.getElementById('modalBatalCheckin');
                        if (!el) return;
                        var modal = new bootstrap.Modal(el);
                        document.querySelectorAll('.js-batal-checkin').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                document.getElementById('inputBatalCheckinId').value = btn.dataset.id;
                                document.getElementById('namaBatalCheckin').textContent = btn.dataset.nama;
                                modal.show();
                            });
                        });
                    });
                </script>
                <?php endif; ?>
            <?php
                } else {
                    // Tanpa id: admin lihat pemilih seminar; panitia lihat seminar hari ini.
                    if ($role_aktif === 'admin') {
                        [$p, $per, $q] = list_page(15);
                        $where = "1=1";
                        if ($q !== '') {
                            $like = '%' . like_escape($q) . '%';
                            $where .= " AND judul LIKE ? ESCAPE '\\\\'";
                        }
                        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM seminars WHERE $where");
                        if ($q !== '') { mysqli_stmt_bind_param($stmt, 's', $like); }
                        mysqli_stmt_execute($stmt);
                        $total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
                        mysqli_stmt_close($stmt);
                        $p = min($p, max(1, (int) ceil($total / $per)));
                        $offset = ($p - 1) * $per;
                        $stmt = mysqli_prepare($conn, "SELECT id_seminar, judul, tanggal, jam_mulai, status FROM seminars WHERE $where ORDER BY tanggal DESC, id_seminar DESC LIMIT " . (int) $per . " OFFSET " . (int) $offset);
                        if ($q !== '') { mysqli_stmt_bind_param($stmt, 's', $like); }
                        mysqli_stmt_execute($stmt);
                        $daftar_sem = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
                        mysqli_stmt_close($stmt);
            ?>
                <div class="card card-custom card-static overflow-hidden">
                    <div class="card-header bg-white border-bottom py-3 px-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h6 class="fw-bold mb-0 text-dark">Pilih Seminar</h6>
                        <form method="get" action="admin.php" class="input-group input-group-sm" style="width: 220px;" role="search">
                            <input type="hidden" name="page" value="peserta">
                            <button type="submit" class="input-group-text bg-white border-end-0 text-muted" aria-label="Cari"><i class="bi bi-search"></i></button>
                            <input type="search" name="q" class="form-control border-start-0 ps-0" value="<?= e($q) ?>" placeholder="Cari seminar..." aria-label="Cari seminar">
                        </form>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th scope="col">Judul</th><th scope="col">Tanggal</th><th scope="col">Status</th><th scope="col" class="text-center">Aksi</th></tr></thead>
                            <tbody>
                                <?php if ($daftar_sem): foreach ($daftar_sem as $s): $se = seminar_status_efektif((string) $s['status'], (string) $s['tanggal'], $hariIniP); ?>
                                    <tr>
                                        <td class="fw-semibold text-dark"><?= e($s['judul']) ?></td>
                                        <td class="text-muted small"><i class="bi bi-calendar3 me-1"></i><?= e(date('d M Y', strtotime($s['tanggal']))) ?></td>
                                        <td><span class="badge <?= $se === 'Aktif' ? 'badge-soft-primary' : 'bg-secondary-subtle text-secondary-emphasis' ?> rounded-pill px-3 py-1"><?= e($se) ?></span></td>
                                        <td class="text-center"><a href="admin.php?page=peserta&id=<?= (int) $s['id_seminar'] ?>" class="btn btn-sm btn-light-primary fw-semibold"><i class="bi bi-clipboard-check me-1"></i>Lihat Peserta</a></td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted"><?= $q !== '' ? 'Tidak ada seminar yang cocok.' : 'Belum ada seminar.' ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?= table_footer($total, $p, $per) ?>
                </div>
            <?php
                    } else {
                        // Panitia: seminar Aktif hari ini saja.
                        $stmt = mysqli_prepare($conn, "SELECT id_seminar, judul, tanggal, jam_mulai, jam_selesai FROM seminars WHERE tanggal = ? AND status = 'Aktif' ORDER BY jam_mulai ASC, id_seminar ASC");
                        mysqli_stmt_bind_param($stmt, 's', $hariIniP);
                        mysqli_stmt_execute($stmt);
                        $daftar_hari = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
                        mysqli_stmt_close($stmt);
            ?>
                <div class="card card-custom card-static overflow-hidden">
                    <div class="card-header bg-white border-bottom py-3 px-4">
                        <h6 class="fw-bold mb-0 text-dark">Seminar Hari Ini</h6>
                        <small class="text-muted"><?= e(date('d M Y')) ?></small>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th scope="col">Judul</th><th scope="col">Jam</th><th scope="col" class="text-center">Aksi</th></tr></thead>
                            <tbody>
                                <?php if ($daftar_hari): foreach ($daftar_hari as $s): ?>
                                    <tr>
                                        <td class="fw-semibold text-dark"><?= e($s['judul']) ?></td>
                                        <td class="text-muted small"><?= $s['jam_mulai'] ? e(substr((string) $s['jam_mulai'], 0, 5)) : '—' ?><?= $s['jam_selesai'] ? '-' . e(substr((string) $s['jam_selesai'], 0, 5)) : '' ?></td>
                                        <td class="text-center"><a href="admin.php?page=peserta&id=<?= (int) $s['id_seminar'] ?>" class="btn btn-sm btn-light-primary fw-semibold"><i class="bi bi-clipboard-check me-1"></i>Peserta</a></td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="3" class="text-center py-4 text-muted">Tidak ada seminar hari ini.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php
                    }
                }
            // ---------------------------------------------------------
            // MENU 8: DATA PANITIA (HANYA admin)
            // ---------------------------------------------------------
            } elseif ($page == 'panitia') {
                $panitia_rows = mysqli_fetch_all(mysqli_query($conn, "SELECT id_user, nama_lengkap, email FROM users WHERE role = 'panitia' ORDER BY nama_lengkap ASC"), MYSQLI_ASSOC);
            ?>
                <div class="row g-3">
                    <div class="col-lg-7">
                        <div class="card card-custom card-static overflow-hidden">
                            <div class="card-header bg-white border-bottom py-3 px-4">
                                <h6 class="fw-bold mb-0 text-dark">Akun Panitia</h6>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light"><tr><th scope="col">Nama</th><th scope="col">Email</th><th scope="col" class="text-center">Aksi</th></tr></thead>
                                    <tbody>
                                        <?php if ($panitia_rows): foreach ($panitia_rows as $r): ?>
                                            <tr>
                                                <td class="fw-semibold text-dark"><?= e($r['nama_lengkap']) ?></td>
                                                <td class="text-muted small"><?= e($r['email']) ?></td>
                                                <td class="text-center">
                                                    <div class="d-flex justify-content-center gap-1">
                                                        <button type="button" class="btn btn-sm btn-light-primary fw-semibold js-reset-panitia" data-id="<?= (int) $r['id_user'] ?>" data-nama="<?= e($r['nama_lengkap']) ?>"><i class="bi bi-key me-1"></i>Reset</button>
                                                        <button type="button" class="btn btn-sm btn-light-danger fw-semibold js-hapus-panitia" data-id="<?= (int) $r['id_user'] ?>" data-nama="<?= e($r['nama_lengkap']) ?>"><i class="bi bi-trash me-1"></i>Hapus</button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                            <tr><td colspan="3" class="text-center py-4 text-muted">Belum ada akun panitia.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="card card-custom card-static">
                            <div class="card-header bg-white border-bottom py-3 px-4">
                                <h6 class="fw-bold mb-0 text-dark">Tambah Panitia</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="admin.php?page=panitia">
                                    <?= csrf_field() ?>
                                    <div class="mb-3">
                                        <label for="nama_panitia" class="form-label small fw-semibold text-secondary">Nama</label>
                                        <input type="text" name="nama" id="nama_panitia" class="form-control" maxlength="100" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="email_panitia" class="form-label small fw-semibold text-secondary">Email</label>
                                        <input type="email" name="email" id="email_panitia" class="form-control" maxlength="100" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="password_panitia" class="form-label small fw-semibold text-secondary">Password Awal</label>
                                        <input type="password" name="password" id="password_panitia" class="form-control" minlength="10" autocomplete="new-password" required>
                                        <small class="text-muted">Minimal 10 karakter. Panitia wajib menggantinya saat login pertama.</small>
                                    </div>
                                    <button type="submit" name="tambah_panitia" class="btn btn-primary w-100 fw-semibold"><i class="bi bi-person-plus me-1"></i>Tambah Panitia</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Reset Password Panitia -->
                <div class="modal fade" id="modalResetPanitia" tabindex="-1" aria-labelledby="modalResetPanitiaLabel" aria-modal="true" role="dialog">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg rounded-4">
                            <form method="POST" action="admin.php?page=panitia" class="m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id_user" id="inputResetPanitiaId">
                                <div class="modal-header border-0 pb-0">
                                    <h5 class="modal-title fw-bold" id="modalResetPanitiaLabel">Reset Password Panitia</h5>
                                </div>
                                <div class="modal-body pt-3">
                                    <p class="text-secondary small">Reset password <strong id="namaResetPanitia"></strong>. Panitia wajib menggantinya saat login.</p>
                                    <input type="password" name="password_baru" class="form-control" minlength="10" placeholder="Password baru (min 10 karakter)" autocomplete="new-password" required>
                                </div>
                                <div class="modal-footer border-0 pt-0">
                                    <button type="button" class="btn btn-light fw-semibold px-4" data-bs-dismiss="modal">Batal</button>
                                    <button type="submit" name="reset_password_panitia" class="btn btn-primary fw-semibold px-4">Simpan</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Modal Hapus Panitia -->
                <div class="modal fade" id="modalHapusPanitia" tabindex="-1" aria-labelledby="modalHapusPanitiaLabel" aria-modal="true" role="dialog">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg rounded-4">
                            <form method="POST" action="admin.php?page=panitia" class="m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id_user" id="inputHapusPanitiaId">
                                <div class="modal-header border-0 pb-0">
                                    <h5 class="modal-title fw-bold" id="modalHapusPanitiaLabel">Hapus Panitia?</h5>
                                </div>
                                <div class="modal-body pt-3">
                                    <p class="text-secondary mb-0">Akun <strong id="namaHapusPanitia"></strong> akan dihapus permanen dan sesinya tidak berlaku lagi.</p>
                                </div>
                                <div class="modal-footer border-0 pt-0">
                                    <button type="button" class="btn btn-light fw-semibold px-4" data-bs-dismiss="modal">Batal</button>
                                    <button type="submit" name="hapus_panitia" class="btn btn-danger fw-semibold px-4">Ya, Hapus</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        var mr = document.getElementById('modalResetPanitia');
                        var mh = document.getElementById('modalHapusPanitia');
                        var modalReset = mr ? new bootstrap.Modal(mr) : null;
                        var modalHapus = mh ? new bootstrap.Modal(mh) : null;
                        document.querySelectorAll('.js-reset-panitia').forEach(function (b) {
                            b.addEventListener('click', function () {
                                document.getElementById('inputResetPanitiaId').value = b.dataset.id;
                                document.getElementById('namaResetPanitia').textContent = b.dataset.nama;
                                if (modalReset) modalReset.show();
                            });
                        });
                        document.querySelectorAll('.js-hapus-panitia').forEach(function (b) {
                            b.addEventListener('click', function () {
                                document.getElementById('inputHapusPanitiaId').value = b.dataset.id;
                                document.getElementById('namaHapusPanitia').textContent = b.dataset.nama;
                                if (modalHapus) modalHapus.show();
                            });
                        });
                    });
                </script>
            <?php
            // ---------------------------------------------------------
            // MENU 9: IMPOR MAHASISWA CSV (HANYA admin)
            // ---------------------------------------------------------
            } elseif ($page == 'impor_mahasiswa') {
                $pesan_impor = (string) ($_SESSION['_old']['impor_pesan'] ?? '');
                $token_pratinjau = (string) ($_SESSION['impor_token'] ?? '');
                $valid_pratinjau = $_SESSION['impor_valid'] ?? null;
                $kedaluwarsa_pratinjau = (int) ($_SESSION['impor_kedaluwarsa'] ?? 0);
                $siap_terapkan = ($token_pratinjau !== '' && is_array($valid_pratinjau) && time() <= $kedaluwarsa_pratinjau);
            ?>
                <div class="row g-3">
                    <div class="col-lg-7">
                        <div class="card card-custom card-static">
                            <div class="card-header bg-white border-bottom py-3 px-4">
                                <h6 class="fw-bold mb-0 text-dark">Impor Data Mahasiswa (CSV)</h6>
                            </div>
                            <div class="card-body">
                                <?php if ($pesan_impor !== ''): ?>
                                    <div class="alert alert-info border-0 rounded-3 small" role="alert"><i class="bi bi-info-circle-fill me-2"></i><?= e($pesan_impor) ?></div>
                                <?php endif; ?>

                                <form method="POST" action="admin.php?page=impor_mahasiswa" enctype="multipart/form-data" class="mb-0">
                                    <?= csrf_field() ?>
                                    <div class="mb-3">
                                        <label for="csv_file" class="form-label small fw-semibold text-secondary">Berkas CSV</label>
                                        <input type="file" name="csv" id="csv_file" class="form-control" accept=".csv,text/csv" required>
                                        <small class="text-muted">Header wajib: nim, nama, prodi, email (urutan bebas). Maks 1 MB / 500 baris.</small>
                                    </div>
                                    <button type="submit" name="impor_pratinjau" class="btn btn-primary fw-semibold"><i class="bi bi-search me-1"></i>Pratinjau</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="card card-custom card-static">
                            <div class="card-header bg-white border-bottom py-3 px-4">
                                <h6 class="fw-bold mb-0 text-dark">Format Berkas</h6>
                            </div>
                            <div class="card-body">
                                <pre class="small bg-light rounded-3 p-3 mb-3">nim,nama,prodi,email
2210012345,Budi Santoso,Teknik Informatika,budi@example.com</pre>
                                <ul class="small text-muted mb-0 ps-3">
                                    <li>Pemisah koma atau titik-koma.</li>
                                    <li>Program studi harus dikenali sistem.</li>
                                    <li>NIM &amp; email tidak boleh duplikat (di dalam file maupun di sistem).</li>
                                    <li>Pratinjau tidak mengubah data. Akun dibuat hanya setelah konfirmasi.</li>
                                    <li>Jika ada satu kesalahan, tidak ada akun yang dibuat (all-or-nothing).</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($siap_terapkan): ?>
                <div class="card card-custom card-static mt-3">
                    <div class="card-header bg-white border-bottom py-3 px-4">
                        <h6 class="fw-bold mb-0 text-dark">Konfirmasi Impor — <?= count($valid_pratinjau) ?> baris valid</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive mb-3">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light"><tr><th scope="col">Baris</th><th scope="col">NIM</th><th scope="col">Nama</th><th scope="col">Prodi</th><th scope="col">Email</th></tr></thead>
                                <tbody>
                                    <?php foreach (array_slice($valid_pratinjau, 0, 10) as $r): ?>
                                        <tr><td class="text-muted"><?= (int) $r['baris'] ?></td><td><?= e($r['nim']) ?></td><td class="fw-semibold text-dark"><?= e($r['nama']) ?></td><td class="text-muted small"><?= e($r['prodi']) ?></td><td class="text-muted small"><?= e($r['email']) ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($valid_pratinjau) > 10): ?><p class="text-muted small">+ <?= count($valid_pratinjau) - 10 ?> baris lainnya.</p><?php endif; ?>
                        <form method="POST" action="admin.php?page=impor_mahasiswa" class="row g-2 align-items-end m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="impor_token" value="<?= e($token_pratinjau) ?>">
                            <div class="col-sm-6">
                                <label for="password_awal" class="form-label small fw-semibold text-secondary">Password Awal (untuk semua akun)</label>
                                <input type="password" name="password_awal" id="password_awal" class="form-control" minlength="8" autocomplete="new-password" required>
                                <small class="text-muted">Minimal 8 karakter. Mahasiswa wajib menggantinya saat login pertama.</small>
                            </div>
                            <div class="col-sm-6">
                                <button type="submit" name="impor_terapkan" class="btn btn-success fw-semibold"><i class="bi bi-check2-circle me-1"></i>Buat <?= count($valid_pratinjau) ?> Akun</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            <?php
            }
            // Nilai isian lama hanya dipakai saat render form; bersihkan agar tidak bocor ke form lain.
            unset($_SESSION['_old']);
            ?>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Hamburger Sidebar Toggle for Mobile
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    const closeBtn = document.getElementById('sidebarCloseBtn');

    function openSidebar() {
        sidebar.classList.add('show');
        sidebar.removeAttribute('aria-hidden');
        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';
        if (closeBtn) closeBtn.focus();
    }

    function closeSidebar() {
        sidebar.classList.remove('show');
        sidebar.setAttribute('aria-hidden', 'true');
        overlay.classList.remove('show');
        document.body.style.overflow = '';
        if (toggleBtn) toggleBtn.focus();
    }

    if (toggleBtn) toggleBtn.addEventListener('click', openSidebar);
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sidebar.classList.contains('show')) closeSidebar();
    });

    // Modal Hapus Seminar
    const modalHapusEl = document.getElementById('modalHapus');
    const modalHapus = modalHapusEl ? new bootstrap.Modal(modalHapusEl) : null;
    function konfirmasiHapus(id, judul, peserta) {
        if (!modalHapus) return;
        const elJudul = document.getElementById('judulHapus');
        const elId = document.getElementById('inputHapusId');
        const elPeserta = document.getElementById('pesertaHapus');
        if (!elJudul || !elId) return;
        elJudul.textContent = judul;
        elId.value = id;
        if (elPeserta) {
            const n = parseInt(peserta, 10) || 0;
            elPeserta.textContent = n > 0
                ? n + ' peserta terdaftar akan kehilangan tiket mereka.'
                : 'Belum ada peserta terdaftar pada seminar ini.';
        }
        modalHapus.show();
    }

    // Modal Selesai Seminar
    const modalSelesaiEl = document.getElementById('modalSelesai');
    const modalSelesai = modalSelesaiEl ? new bootstrap.Modal(modalSelesaiEl) : null;
    function konfirmasiSelesai(id, judul) {
        if (!modalSelesai) return;
        document.getElementById('inputSelesaiId').value = id;
        document.getElementById('judulSelesai').textContent = judul;
        modalSelesai.show();
    }

    // Modal Hapus Pemateri
    const modalHapusPemEl = document.getElementById('modalHapusPem');
    const modalHapusPem = modalHapusPemEl ? new bootstrap.Modal(modalHapusPemEl) : null;
    function konfirmasiHapusPem(id, nama) {
        if (!modalHapusPem) return;
        const elJudul = document.getElementById('judulHapusPem');
        const elId = document.getElementById('inputHapusPemId');
        if (!elJudul || !elId) return;
        elJudul.textContent = nama;
        elId.value = id;
        modalHapusPem.show();
    }

    // Modal Hapus Mahasiswa
    const modalHapusMhsEl = document.getElementById('modalHapusMhs');
    const modalHapusMhs = modalHapusMhsEl ? new bootstrap.Modal(modalHapusMhsEl) : null;
    function konfirmasiHapusMhs(id, nama) {
        if (!modalHapusMhs) return;
        const elJudul = document.getElementById('judulHapusMhs');
        const elId = document.getElementById('inputHapusMhsId');
        if (!elJudul || !elId) return;
        elJudul.textContent = nama;
        elId.value = id;
        modalHapusMhs.show();
    }

    // Data dari server masuk lewat dataset (bukan onclick) agar tidak bisa jadi JS.
    function bindAksi(selector, handler) {
        document.querySelectorAll(selector).forEach(function (btn) {
            btn.addEventListener('click', function () {
                handler(parseInt(btn.dataset.id, 10), btn.dataset.judul || btn.dataset.nama || '', btn.dataset.peserta);
            });
        });
    }
    bindAksi('.js-hapus-seminar', konfirmasiHapus);
    bindAksi('.js-selesai', konfirmasiSelesai);
    bindAksi('.js-hapus-pemateri', konfirmasiHapusPem);
    bindAksi('.js-hapus-mahasiswa', konfirmasiHapusMhs);

    // Cegah double-submit: spinner + blokir klik. Jangan pakai `disabled` —
    // tombol disabled dikeluarkan dari FormData, sehingga flag POST (mis. name="reset_password") hilang.
    // Ganti innerHTML saja (bukan outerHTML) agar atribut name tombol tetap utuh.
    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.dataset.submitting === '1') return;
            const tombol = e.submitter || form.querySelector('button[type="submit"]');
            if (!tombol) return;
            form.dataset.submitting = '1';
            tombol.classList.add('is-submitting');
            tombol.setAttribute('aria-disabled', 'true');
            tombol.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Memproses...';
        });
    });
</script>
<script src="theme.js?v=2.10" defer></script>
<script src="grafik.js?v=1.1" defer></script>
</body>
</html>