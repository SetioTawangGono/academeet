<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/koneksi.php';
require __DIR__ . '/services.php';
require_role('mahasiswa');

$id_user = (int) $_SESSION['id_user'];
$page = (string) ($_GET['page'] ?? 'katalog');
if (!in_array($page, ['katalog', 'tiket', 'profil', 'pemateri'], true)) {
    $page = 'katalog';
}

// ==========================================
// UPDATE PROFIL
// ==========================================
if (isset($_POST['update_profil'])) {
    csrf_check();

    $nama  = trim((string) ($_POST['nama_lengkap'] ?? ''));
    $nim   = trim((string) ($_POST['nim'] ?? ''));
    $prodi = trim((string) ($_POST['prodi'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));

    $row_now = mysqli_fetch_assoc(mysqli_query($conn, "SELECT prodi FROM users WHERE id_user = $id_user"));
    $prodi_lama = $row_now ? (string) $row_now['prodi'] : '';

    $err = validasi_data_diri($nama, $nim, $prodi, $email, [$prodi_lama]);
    if ($err !== '') {
        flash_set('danger', $err);
        redirect('mahasiswa.php?page=profil');
    }

    try {
        $stmt = mysqli_prepare($conn, "SELECT id_user FROM users WHERE (email = ? OR (nim = ? AND nim <> '')) AND id_user <> ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'ssi', $email, $nim, $id_user);
        mysqli_stmt_execute($stmt);
        $dupe = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($dupe) {
            throw new RuntimeException('Email atau NIM sudah digunakan akun lain.');
        }

        try {
            $new_foto = upload_image($_FILES['foto'] ?? [], 'profiles', 2000000);
        } catch (Throwable $ex) {
            throw new RuntimeException($ex->getMessage());
        }

        $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT foto FROM users WHERE id_user = $id_user"));
        $nim_simpan = $nim === '' ? null : $nim;
        $sql = "UPDATE users SET nama_lengkap = ?, nim = ?, prodi = ?, email = ?" . ($new_foto ? ", foto = ?" : "") . " WHERE id_user = ?";
        $stmt = mysqli_prepare($conn, $sql);
        if ($new_foto) {
            mysqli_stmt_bind_param($stmt, 'sssss' . 'i', $nama, $nim_simpan, $prodi, $email, $new_foto, $id_user);
        } else {
            mysqli_stmt_bind_param($stmt, 'ssss' . 'i', $nama, $nim_simpan, $prodi, $email, $id_user);
        }
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if ($ok) {
            $_SESSION['nama'] = $nama;
            if ($new_foto && !empty($old['foto']) && file_exists("uploads/profiles/" . $old['foto'])) {
                @unlink("uploads/profiles/" . $old['foto']);
            }
            flash_set('success', 'Profil berhasil diperbarui.');
        } else {
            throw new RuntimeException('Gagal memperbarui data ke database.');
        }
    } catch (Throwable $ex) {
        if (isset($new_foto) && $new_foto && file_exists("uploads/profiles/" . $new_foto)) {
            @unlink("uploads/profiles/" . $new_foto);
        }
        $errno = mysqli_errno($conn);
        flash_set('danger', $errno === 1062 ? 'Email atau NIM sudah digunakan.' : $ex->getMessage());
    }
    redirect('mahasiswa.php?page=profil');
}

// ==========================================
// UBAH PASSWORD
// ==========================================
if (isset($_POST['ubah_password'])) {
    csrf_check();

    $lama    = (string) ($_POST['password_lama'] ?? '');
    $baru    = (string) ($_POST['password_baru'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT password FROM users WHERE id_user = $id_user"));
    if (!$row || !password_verify($lama, $row['password'])) {
        flash_set('danger', 'Password lama salah.');
    } elseif (!password_ok($baru)) {
        flash_set('danger', 'Password baru minimal 8 karakter.');
    } elseif ($baru !== $confirm) {
        flash_set('danger', 'Konfirmasi password baru tidak cocok.');
    } else {
        $hash = password_hash($baru, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, "UPDATE users SET password = ?, auth_version = auth_version + 1 WHERE id_user = ?");
        mysqli_stmt_bind_param($stmt, 'si', $hash, $id_user);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        flash_set($ok ? 'success' : 'danger', $ok ? 'Password berhasil diubah.' : 'Gagal mengubah password.');
        if ($ok) {
            // Sesi aktif ikut naik agar tidak ter-logout sendiri.
            $_SESSION['auth_version'] = (int) ($_SESSION['auth_version'] ?? 0) + 1;
        }
    }
    redirect('mahasiswa.php?page=profil');
}

// ==========================================
// PENDAFTARAN SEMINAR (atomik, aman dari race condition)
// ==========================================
if (isset($_POST['daftar'])) {
    csrf_check();
    $id_seminar = (int) ($_POST['id_seminar'] ?? 0);

    if (!mysqli_begin_transaction($conn)) {
        flash_set('danger', 'Gagal memulai transaksi pendaftaran.');
        redirect('mahasiswa.php?page=katalog');
    }

    try {
        $stmt = mysqli_prepare($conn, "SELECT kuota, status, tanggal FROM seminars WHERE id_seminar = ? FOR UPDATE");
        mysqli_stmt_bind_param($stmt, 'i', $id_seminar);
        mysqli_stmt_execute($stmt);
        $sem = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$sem) {
            throw new RuntimeException('Seminar tidak ditemukan.');
        }
        if ($sem['status'] !== 'Aktif') {
            throw new RuntimeException('Seminar ini sudah ditutup.');
        }
        if ($sem['tanggal'] < date('Y-m-d')) {
            throw new RuntimeException('Seminar ini sudah lewat.');
        }
        if ((int) $sem['kuota'] <= 0) {
            throw new RuntimeException('Kuota seminar sudah habis.');
        }

        $stmt = mysqli_prepare($conn, "SELECT id_registrasi FROM registrations WHERE id_user = ? AND id_seminar = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'ii', $id_user, $id_seminar);
        mysqli_stmt_execute($stmt);
        $sudah = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($sudah) {
            throw new RuntimeException('Anda sudah terdaftar di seminar ini.');
        }

        $kode = '';
        $inserted = false;
        for ($i = 0; $i < 5; $i++) {
            $kode = ticket_code();
            $stmt = mysqli_prepare($conn, "INSERT INTO registrations (id_user, id_seminar, kode_tiket) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'iis', $id_user, $id_seminar, $kode);
            $inserted = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($inserted) {
                break;
            }
            if (mysqli_errno($conn) !== 1062) {
                throw new RuntimeException('Pendaftaran gagal. Coba lagi.');
            }

            // 1062 bisa berarti kode tiket bentrok, atau request lain sudah mendaftar.
            $stmt = mysqli_prepare($conn, "SELECT id_registrasi FROM registrations WHERE id_user = ? AND id_seminar = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt, 'ii', $id_user, $id_seminar);
            mysqli_stmt_execute($stmt);
            $race = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($race) {
                throw new RuntimeException('Anda sudah terdaftar di seminar ini.');
            }
        }

        if (!$inserted) {
            throw new RuntimeException('Gagal membuat kode tiket. Coba lagi.');
        }

        $stmt = mysqli_prepare($conn, "UPDATE seminars SET kuota = kuota - 1 WHERE id_seminar = ? AND kuota > 0");
        mysqli_stmt_bind_param($stmt, 'i', $id_seminar);
        mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        if ($affected !== 1) {
            throw new RuntimeException('Kuota seminar sudah habis.');
        }

        mysqli_commit($conn);
        flash_set('success', 'Pendaftaran berhasil! Kode tiket: ' . $kode);
        redirect('mahasiswa.php?page=tiket');
    } catch (Throwable $ex) {
        mysqli_rollback($conn);
        flash_set('danger', $ex->getMessage());
        redirect('mahasiswa.php?page=katalog');
    }
}

// ==========================================
// BATALKAN PENDAFTARAN
// ==========================================
if (isset($_POST['batal_daftar'])) {
    csrf_check();
    $id_registrasi = (int) ($_POST['id_registrasi'] ?? 0);
    [$type, $message] = batalkan_pendaftaran($conn, $id_registrasi, $id_user);
    flash_set($type, $message);
    redirect('mahasiswa.php?page=tiket');
}

// Data user terbaru
$user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id_user = $id_user"));
$avatar_url = (!empty($user['foto']) && file_exists("uploads/profiles/" . $user['foto']))
    ? "uploads/profiles/" . $user['foto']
    : initials_avatar($user['nama_lengkap']);

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total, SUM(status_hadir = 'Hadir') AS hadir, SUM(status_hadir = 'Belum Hadir') AS aktif FROM registrations WHERE id_user = ?");
mysqli_stmt_bind_param($stmt, 'i', $id_user);
mysqli_stmt_execute($stmt);
$stat = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
$stat_total = (int) ($stat['total'] ?? 0);
$stat_hadir = (int) ($stat['hadir'] ?? 0);
$count_aktif = (int) ($stat['aktif'] ?? 0);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(ucfirst($page)) ?> - AcadeMeet</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <?= theme_boot_script() ?>
    <link rel="stylesheet" href="style.css?v=2.10">
</head>
<body>
<a href="#konten" class="skip-link">Lewati ke konten utama</a>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg sticky-top glass-navbar">
  <div class="container">
    <div class="nav-glass">
      <a class="navbar-brand d-flex align-items-center fw-bold text-dark me-2" href="mahasiswa.php">
          <div class="auth-brand-icon mb-0 me-2" style="width: 34px; height: 34px; font-size: 1rem;">
              <i class="bi bi-mortarboard-fill"></i>
          </div>
          <span>AcadeMeet</span>
      </a>

      <div class="d-flex d-lg-none align-items-center gap-2 ms-auto">
          <?= theme_toggle_button() ?>
          <button class="navbar-toggler btn-glass border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#navRes" aria-label="Buka menu">
              <i class="bi bi-list fs-3"></i>
          </button>
      </div>

      <div class="collapse navbar-collapse" id="navRes">
          <ul class="navbar-nav mb-2 mb-lg-0 gap-1">
              <li class="nav-item">
                  <a class="nav-link py-2 px-3 rounded-pill <?= $page == 'katalog' ? 'active' : '' ?>" href="mahasiswa.php?page=katalog" <?= $page == 'katalog' ? 'aria-current="page"' : '' ?>>
                      <i class="bi bi-grid-1x2 me-1" aria-hidden="true"></i> Katalog Seminar
                  </a>
              </li>
              <li class="nav-item">
                  <a class="nav-link py-2 px-3 rounded-pill <?= $page == 'tiket' ? 'active' : '' ?>" href="mahasiswa.php?page=tiket" <?= $page == 'tiket' ? 'aria-current="page"' : '' ?>>
                      <i class="bi bi-ticket-perforated me-1" aria-hidden="true"></i> Tiket Saya
                      <?php if($count_aktif > 0): ?>
                          <span class="badge bg-danger rounded-pill ms-1" style="font-size: 0.65rem;" aria-label="<?= (int) $count_aktif ?> tiket belum hadir"><?= $count_aktif ?></span>
                      <?php endif; ?>
                  </a>
              </li>
              <li class="nav-item">
                  <a class="nav-link py-2 px-3 rounded-pill <?= $page == 'profil' ? 'active' : '' ?>" href="mahasiswa.php?page=profil" <?= $page == 'profil' ? 'aria-current="page"' : '' ?>>
                      <i class="bi bi-person-circle me-1" aria-hidden="true"></i> Profil Saya
                  </a>
              </li>
          </ul>
          <div class="d-flex align-items-center nav-right">
              <div class="d-none d-sm-flex align-items-center text-end gap-2 me-1">
                  <div class="d-none d-xl-block">
                      <span class="d-block small text-muted" style="line-height: 1;">Halo,</span>
                      <span class="fw-bold text-dark small"><?= e($user['nama_lengkap']) ?></span>
                  </div>
                  <img src="<?= e($avatar_url) ?>" class="rounded-circle border" width="34" height="34" decoding="async" style="object-fit: cover;" alt="Avatar <?= e($user['nama_lengkap']) ?>">
              </div>
              <div class="d-none d-lg-flex align-items-center">
                  <?= theme_toggle_button() ?>
              </div>
              <form method="POST" action="logout.php" class="d-inline m-0 ms-1">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-sm btn-light-danger px-3 rounded-pill fw-semibold">
                      <i class="bi bi-box-arrow-right me-1"></i> Keluar
                  </button>
              </form>
          </div>
      </div>
    </div>
  </div>
</nav>

<div class="container py-4" id="konten" tabindex="-1">
    <?= flash_html() ?>

    <?php if ($page == 'profil'): ?>
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card card-custom text-center p-4 h-100">
                    <div class="position-relative d-inline-block mx-auto mb-3">
                        <img src="<?= e($avatar_url) ?>" class="rounded-circle shadow-sm border p-1 bg-white" width="120" height="120" loading="lazy" decoding="async" style="object-fit: cover;" alt="Avatar <?= e($user['nama_lengkap']) ?>">
                        <span class="position-absolute bottom-0 end-0 bg-success border border-white border-2 rounded-circle p-2" title="Online"></span>
                    </div>
                    <h1 class="h5 fw-bold text-dark mb-1"><?= e($user['nama_lengkap']) ?></h1>
                    <p class="text-primary small fw-bold mb-4 px-3 py-1 bg-primary-light d-inline-block rounded-pill">Mahasiswa</p>

                    <div class="d-grid gap-2 text-start mb-4">
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="small text-muted mb-1"><i class="bi bi-card-text me-1"></i> NIM</div>
                            <div class="small fw-bold text-dark"><?= !empty($user['nim']) ? e($user['nim']) : '-' ?></div>
                        </div>
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="small text-muted mb-1"><i class="bi bi-award me-1"></i> Program Studi</div>
                            <div class="small fw-bold text-dark"><?= !empty($user['prodi']) ? e($user['prodi']) : '-' ?></div>
                        </div>
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="small text-muted mb-1"><i class="bi bi-envelope me-1"></i> Email</div>
                            <div class="small fw-bold text-dark text-truncate"><?= e($user['email']) ?></div>
                        </div>
                    </div>

                    <div class="row g-2">
                        <div class="col-6">
                            <div class="p-2 rounded-3 border bg-white">
                                <div class="fw-bold text-primary fs-5"><?= $stat_total ?></div>
                                <div class="small text-muted">Tiket</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-2 rounded-3 border bg-white">
                                <div class="fw-bold text-success fs-5"><?= $stat_hadir ?></div>
                                <div class="small text-muted">Hadir</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card card-custom card-static h-100">
                    <div class="card-header bg-white border-bottom py-3 px-4">
                        <h2 class="fw-bold mb-0 text-dark"><i class="bi bi-gear-fill text-primary me-2"></i>Pengaturan Profil</h2>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="mahasiswa.php?page=profil" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <label for="nama_lengkap" class="form-label small fw-bold text-secondary">Nama Lengkap</label>
                                    <input type="text" class="form-control" id="nama_lengkap" name="nama_lengkap" maxlength="100" value="<?= e($user['nama_lengkap']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="email" class="form-label small fw-bold text-secondary">Email Kampus</label>
                                    <input type="email" class="form-control" id="email" name="email" maxlength="100" value="<?= e($user['email']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="nim" class="form-label small fw-bold text-secondary">Nomor Induk Mahasiswa (NIM)</label>
                                    <input type="text" class="form-control" id="nim" name="nim" maxlength="20" value="<?= e($user['nim']) ?>" placeholder="Masukkan NIM">
                                </div>
                                <div class="col-md-6">
                                    <label for="prodi" class="form-label small fw-bold text-secondary">Program Studi</label>
                                    <?php $prodi_sekarang = (string) $user['prodi']; $prodi_asing = $prodi_sekarang !== '' && !in_array($prodi_sekarang, prodi_terdaftar(), true); ?>
                                    <select class="form-select" id="prodi" name="prodi" required>
                                        <option value="" disabled <?= $prodi_sekarang === '' ? 'selected' : '' ?>>Pilih Program Studi...</option>
                                        <?php foreach (prodi_terdaftar() as $opsi_prodi): ?>
                                            <option value="<?= e($opsi_prodi) ?>" <?= $prodi_sekarang === $opsi_prodi ? 'selected' : '' ?>><?= e($opsi_prodi) ?></option>
                                        <?php endforeach; ?>
                                        <?php if ($prodi_asing): ?>
                                            <option value="<?= e($prodi_sekarang) ?>" selected><?= e($prodi_sekarang) ?> (lama)</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label for="foto" class="form-label small fw-bold text-secondary">Foto Profil Baru</label>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="flex-grow-1">
                                            <input type="file" class="form-control" id="foto" name="foto" accept=".jpg,.jpeg,.png,.webp">
                                            <div class="form-text text-muted small">Maksimal 2MB. Format: JPG, PNG, atau WebP.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 pt-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <p class="text-muted small mb-0" style="font-size: 0.75rem;"><i class="bi bi-shield-lock me-1"></i> Keamanan data Anda terjaga.</p>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="flex-grow-1">
                                        <a href="mahasiswa.php" class="btn btn-sm btn-light fw-semibold w-100">Batal</a>
                                    </div>
                                    <div class="flex-grow-1">
                                        <button type="submit" name="update_profil" class="btn btn-sm btn-primary fw-semibold w-100">
                                            <i class="bi bi-save me-1"></i> Simpan Perubahan
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card card-custom card-static">
                    <div class="card-header bg-white border-bottom py-3 px-4">
                        <h2 class="fw-bold mb-0 text-dark"><i class="bi bi-key-fill text-primary me-2"></i>Ubah Password</h2>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="mahasiswa.php?page=profil">
                            <?= csrf_field() ?>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="password_lama" class="form-label small fw-bold text-secondary">Password Lama</label>
                                    <input type="password" class="form-control" id="password_lama" name="password_lama" autocomplete="current-password" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="password_baru" class="form-label small fw-bold text-secondary">Password Baru</label>
                                    <input type="password" class="form-control" id="password_baru" name="password_baru" minlength="8" autocomplete="new-password" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="password_confirm" class="form-label small fw-bold text-secondary">Ulangi Password Baru</label>
                                    <input type="password" class="form-control" id="password_confirm" name="password_confirm" minlength="8" autocomplete="new-password" required>
                                </div>
                            </div>
                            <div class="mt-4 pt-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
    <p class="text-muted small mb-0"><i class="bi bi-shield-lock me-1"></i> Minimal 8 karakter.</p>
    <div class="d-flex align-items-center gap-2">
        <a href="mahasiswa.php?page=profil" class="btn btn-sm btn-light fw-semibold">Batal</a>
        <button type="submit" name="ubah_password" class="btn btn-sm btn-primary fw-semibold">
            <i class="bi bi-key me-1"></i> Ubah Password
        </button>
    </div>
</div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($page == 'tiket'): ?>
        <div class="d-flex align-items-center justify-content-between mb-4">
            <h1 class="h5 fw-bold text-dark mb-0">
                <i class="bi bi-pass-fill text-primary me-2"></i>Riwayat Tiket Seminar Saya
            </h1>
            <span class="badge bg-primary text-white rounded-pill px-3 py-2 fw-bold shadow-sm">Total: <?= $stat_total ?> Tiket</span>
        </div>

        <?php
        // Filter pencarian & status tiket (GET, whitelist aman)
        $q_tiket = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $f_hadir = in_array($_GET['f_hadir'] ?? '', ['Hadir', 'Belum Hadir'], true) ? (string) $_GET['f_hadir'] : '';
        $filter_tiket_aktif = $q_tiket !== '' || $f_hadir !== '';
        ?>
        <form method="get" action="mahasiswa.php" class="filter-bar d-flex flex-wrap align-items-center gap-2 mb-4" role="search" aria-label="Filter tiket">
            <input type="hidden" name="page" value="tiket">
            <div class="input-group input-group-sm search-field">
                <button type="submit" class="input-group-text" aria-label="Cari tiket"><i class="bi bi-search"></i></button>
                <input type="search" name="q" class="form-control" value="<?= e($q_tiket) ?>" placeholder="Cari judul / pemateri / kode" aria-label="Cari judul, pemateri, atau kode tiket">
            </div>
            <label for="f_hadir" class="visually-hidden">Filter status kehadiran</label>
            <select name="f_hadir" id="f_hadir" class="form-select form-select-sm" style="width: auto;">
                <option value="">Semua status</option>
                <option value="Hadir" <?= $f_hadir === 'Hadir' ? 'selected' : '' ?>>Hadir</option>
                <option value="Belum Hadir" <?= $f_hadir === 'Belum Hadir' ? 'selected' : '' ?>>Belum Hadir</option>
            </select>
            <button type="submit" class="btn btn-sm btn-primary fw-semibold px-3">Filter</button>
            <?php if ($filter_tiket_aktif): ?>
                <a href="mahasiswa.php?page=tiket" class="btn btn-sm btn-light fw-semibold">Reset</a>
            <?php endif; ?>
        </form>

        <?php
        $sql_tiket = "SELECT s.id_seminar, s.judul, s.tanggal, s.pemateri, s.status AS status_seminar, s.jam_mulai, s.jam_selesai, s.lokasi, s.link_online, s.kategori, s.deskripsi, r.id_registrasi, r.kode_tiket, r.status_hadir
            FROM registrations r JOIN seminars s ON r.id_seminar = s.id_seminar
            WHERE r.id_user = ?";
        $params_tiket = [$id_user];
        $types_tiket = 'i';

        if ($q_tiket !== '') {
            $sql_tiket .= " AND (s.judul LIKE ? OR s.pemateri LIKE ? OR r.kode_tiket LIKE ?)";
            $like = '%' . $q_tiket . '%';
            $params_tiket[] = $like; $params_tiket[] = $like; $params_tiket[] = $like;
            $types_tiket .= 'sss';
        }
        if ($f_hadir !== '') {
            $sql_tiket .= " AND r.status_hadir = ?";
            $params_tiket[] = $f_hadir;
            $types_tiket .= 's';
        }
        $sql_tiket .= " ORDER BY s.tanggal DESC, r.id_registrasi DESC";

        $stmt_tiket = mysqli_prepare($conn, $sql_tiket);
        mysqli_stmt_bind_param($stmt_tiket, $types_tiket, ...$params_tiket);
        mysqli_stmt_execute($stmt_tiket);
        $tiket = mysqli_stmt_get_result($stmt_tiket);

        $tiket_belum = [];
        $tiket_hadir = [];
        while ($t = mysqli_fetch_assoc($tiket)) {
            if ($t['status_hadir'] === 'Hadir') { $tiket_hadir[] = $t; } else { $tiket_belum[] = $t; }
        }
        $total_tampil = count($tiket_belum) + count($tiket_hadir);

        // Helper render satu kartu tiket
        $render_kartu = function (array $t) {
            $is_h = ($t['status_hadir'] === 'Hadir');
            ?>
            <div class="col-lg-6 col-xl-4">
                <div class="card card-custom tiket-card h-100 overflow-hidden shadow-sm border-0">
                    <div class="p-4">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div class="badge badge-soft-<?= $is_h ? 'success' : 'warning' ?> px-3 py-2 rounded-pill fw-bold">
                                <i class="bi bi-<?= $is_h ? 'patch-check' : 'hourglass-split' ?> me-1"></i> <?= e($t['status_hadir']) ?>
                            </div>
                            <span class="small text-muted fw-semibold"><i class="bi bi-calendar3 me-1"></i> <?= e(date('d M Y', strtotime($t['tanggal']))) ?></span>
                        </div>
                        <h3 class="h6 fw-bold text-dark mb-2 text-truncate-2" style="min-height: 48px; line-height: 1.5;"><?= e($t['judul']) ?></h3>
                        <div class="small text-muted mb-3">
                            <i class="bi bi-person-fill text-primary me-2"></i><?= e($t['pemateri']) ?>
                        </div>
                        <?php
                        $t_kategori = (string) ($t['kategori'] ?? 'Umum');
                        $t_jam = !empty($t['jam_mulai'])
                            ? substr((string) $t['jam_mulai'], 0, 5) . (!empty($t['jam_selesai']) ? '-' . substr((string) $t['jam_selesai'], 0, 5) : '') . ' WIB'
                            : 'Jam akan diumumkan';
                        $t_lokasi = !empty($t['lokasi']) ? (string) $t['lokasi'] : '';
                        ?>
                        <div class="mb-3">
                            <span class="badge badge-soft-primary rounded-pill px-2 py-1 fw-semibold mb-2"><?= e($t_kategori) ?></span>
                            <div class="small text-muted d-flex align-items-center mb-1">
                                <i class="bi bi-clock text-primary me-2" aria-hidden="true"></i><span><?= e($t_jam) ?></span>
                            </div>
                            <?php if ($t_lokasi !== ''): ?>
                                <div class="small text-muted d-flex align-items-center mb-1">
                                    <i class="bi bi-geo-alt text-primary me-2" aria-hidden="true"></i><span><?= e($t_lokasi) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($t['deskripsi'])): ?>
                                <p class="small text-muted mt-2 mb-0" style="line-height: 1.5;"><?= nl2br(e($t['deskripsi'])) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($t['link_online'])): ?>
                                <a href="<?= e($t['link_online']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-light-primary fw-semibold rounded-pill mt-2">
                                    <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i> Buka tautan
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="bg-light p-4 rounded-4 text-center border dashed-border position-relative">
                            <div class="position-absolute ticket-notch ticket-notch--left rounded-circle" style="width: 20px; height: 20px; left: -10px; top: 50%; margin-top: -10px;"></div>
                            <div class="position-absolute ticket-notch ticket-notch--right rounded-circle" style="width: 20px; height: 20px; right: -10px; top: 50%; margin-top: -10px;"></div>
                            <small class="text-muted d-block mb-3 letter-spacing-1" style="font-size: 0.7rem; text-transform: uppercase;">QR Tiket Presensi</small>
                            <div class="qr-ticket bg-white rounded-3 shadow-sm border p-2 d-inline-block mb-3" data-code="<?= e($t['kode_tiket']) ?>" style="width: 140px; height: 140px;" role="img" aria-label="QR tiket <?= e($t['kode_tiket']) ?>"></div>
                            <div class="d-flex align-items-center justify-content-center gap-2">
                                <span class="font-monospace fw-bold text-primary fs-5"><?= e($t['kode_tiket']) ?></span>
                                <button type="button" class="btn btn-sm btn-light border js-salin-kode" data-kode="<?= e($t['kode_tiket']) ?>" aria-label="Salin kode tiket <?= e($t['kode_tiket']) ?>">
                                    <i class="bi bi-clipboard" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php if ($is_h): ?>
                        <div class="card-footer bg-primary border-0 p-0">
                            <a href="sertifikat.php?id=<?= (int) $t['id_seminar'] ?>" target="_blank" rel="noopener" class="btn btn-primary w-100 py-3 rounded-0 fw-bold">
                                <i class="bi bi-file-earmark-pdf-fill me-2"></i> Unduh Sertifikat
                            </a>
                        </div>
                    <?php elseif ($t['status_seminar'] === 'Aktif' && $t['tanggal'] > date('Y-m-d')): ?>
                        <div class="card-footer bg-light border-0 py-3 text-center">
                            <button type="button" class="btn btn-outline-danger btn-sm fw-semibold rounded-pill js-batal-daftar"
                                    data-bs-toggle="modal" data-bs-target="#modalBatal"
                                    data-id="<?= (int) $t['id_registrasi'] ?>"
                                    data-judul="<?= e($t['judul']) ?>">
                                <i class="bi bi-x-circle me-1"></i> Batalkan Pendaftaran
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="card-footer bg-light border-0 py-3 text-center">
                            <small class="text-muted"><i class="bi bi-info-circle me-1"></i> Sertifikat tersedia setelah hadir.</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        };

        if ($total_tampil === 0) {
            $pesan_kosong = $filter_tiket_aktif
                ? 'Tidak ada tiket yang cocok dengan pencarian/filter.'
                : 'Belum ada tiket terdaftar.';
            echo "<div class='col-12'><div class='card card-custom p-5 text-center'><i class='bi bi-ticket-slash text-muted display-4 mb-3 opacity-50'></i><h2 class='h6 fw-bold text-dark'>" . e($pesan_kosong) . "</h2>" . ($filter_tiket_aktif ? "<a href='mahasiswa.php?page=tiket' class='btn btn-sm btn-light mt-3'>Reset filter</a>" : "<p class='text-muted small mb-0'>Silakan pilih seminar di menu Katalog Seminar untuk mendaftar.</p><a href='mahasiswa.php?page=katalog' class='btn btn-primary mt-3 rounded-pill px-4'>Lihat Katalog</a>") . "</div></div>";
        } else {
        ?>

        <?php if (!empty($tiket_belum)): ?>
            <h2 class="h6 fw-bold text-dark mb-3 mt-1"><i class="bi bi-hourglass-split text-warning me-2"></i>Belum Hadir (<?= count($tiket_belum) ?>)</h2>
            <div class="row g-4 mb-4">
                <?php foreach ($tiket_belum as $t) { $render_kartu($t); } ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($tiket_hadir)): ?>
            <h2 class="h6 fw-bold text-dark mb-3 mt-1"><i class="bi bi-patch-check-fill text-success me-2"></i>Hadir (<?= count($tiket_hadir) ?>)</h2>
            <div class="row g-4">
                <?php foreach ($tiket_hadir as $t) { $render_kartu($t); } ?>
            </div>
        <?php endif; ?>
        <?php } ?>

        <?php elseif ($page == 'pemateri'):
            $id_pem = (int) ($_GET['id'] ?? 0);
            $stmt = mysqli_prepare($conn, "SELECT nama, keahlian, institusi, foto, bio FROM pemateri WHERE id_pemateri = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt, 'i', $id_pem);
            mysqli_stmt_execute($stmt);
            $pmt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            if (!$pmt) {
                flash_set('danger', 'Pemateri tidak ditemukan.');
                redirect('mahasiswa.php?page=katalog');
            }
            $foto_pem = (!empty($pmt['foto']) && file_exists("uploads/pemateri/" . $pmt['foto']))
                ? "uploads/pemateri/" . $pmt['foto']
                : placeholder_data_uri('Pemateri', 120, 120);
        ?>
            <nav aria-label="Breadcrumb" class="mb-3">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="mahasiswa.php?page=katalog" class="text-decoration-none">Katalog Seminar</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?= e($pmt['nama']) ?></li>
                </ol>
            </nav>
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="card card-custom text-center p-4 h-100">
                        <img src="<?= e($foto_pem) ?>" alt="Foto <?= e($pmt['nama']) ?>" class="rounded-circle mx-auto mb-3 shadow-sm border" width="100" height="100" loading="lazy" decoding="async" style="object-fit:cover;">
                        <h1 class="h5 fw-bold text-dark mb-1"><?= e($pmt['nama']) ?></h1>
                        <p class="text-primary small fw-semibold mb-3"><?= e($pmt['keahlian']) ?></p>
                        <div class="p-3 bg-light rounded-3 border text-start">
                            <div class="small text-muted mb-1"><i class="bi bi-building me-1"></i> Institusi</div>
                            <div class="small fw-bold text-dark"><?= !empty($pmt['institusi']) ? e($pmt['institusi']) : '-' ?></div>
                        </div>
                        <div class="mt-4 pt-2">
                            <a href="mahasiswa.php?page=katalog" class="btn btn-light border w-100 fw-semibold">
                                <i class="bi bi-arrow-left me-1"></i> Kembali ke Katalog
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="card card-custom mb-4">
                        <div class="card-header bg-white border-bottom py-3 px-4">
                            <h2 class="h6 fw-bold mb-0 text-dark"><i class="bi bi-person-lines-fill text-primary me-2"></i>Biografi</h2>
                        </div>
                        <div class="card-body p-4">
                            <p class="text-secondary mb-0" style="white-space: pre-line; line-height: 1.7;"><?= !empty($pmt['bio']) ? e($pmt['bio']) : '<span class="text-muted fst-italic">Belum ada biografi untuk pemateri ini.</span>' ?></p>
                        </div>
                    </div>
                    <div class="card card-custom card-static overflow-hidden">
                        <div class="card-header bg-white border-bottom py-3 px-4">
                            <h2 class="h6 fw-bold mb-0 text-dark"><i class="bi bi-calendar-event text-primary me-2"></i>Seminar yang Dibawakan</h2>
                        </div>
                        <div class="table-responsive" tabindex="0" role="region" aria-label="Tabel seminar pemateri">
                            <table class="table table-custom">
                                <caption class="visually-hidden">Seminar yang dibawakan pemateri</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Judul Seminar</th>
                                        <th scope="col">Tanggal</th>
                                        <th scope="col" class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $stmt2 = mysqli_prepare($conn, "SELECT id_seminar, judul, tanggal, status FROM seminars WHERE id_pemateri = ? ORDER BY tanggal DESC");
                                    mysqli_stmt_bind_param($stmt2, 'i', $id_pem);
                                    mysqli_stmt_execute($stmt2);
                                    $sem_list = mysqli_stmt_get_result($stmt2);
                                    if (mysqli_num_rows($sem_list) > 0) {
                                        while ($s = mysqli_fetch_assoc($sem_list)) {
                                            $badge = $s['status'] === 'Aktif'
                                                ? "<span class='badge badge-soft-success rounded-pill px-3 py-1'>Aktif</span>"
                                                : "<span class='badge badge-soft-warning rounded-pill px-3 py-1'>Selesai</span>";
                                            echo "<tr>
                                                    <td><span class='fw-semibold text-dark'>" . e($s['judul']) . "</span></td>
                                                    <td class='small text-muted'><i class='bi bi-calendar3 me-1'></i>" . e(date('d M Y', strtotime($s['tanggal']))) . "</td>
                                                    <td class='text-center'>{$badge}</td>
                                                  </tr>";
                                        }
                                    } else {
                                        echo "<tr><td colspan='3' class='text-center py-4 text-muted small'>Belum ada jadwal seminar.</td></tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
        <?php
        // Filter katalog (GET, whitelist lewat normalisasi_filter_katalog()).
        $f = normalisasi_filter_katalog($_GET);
        $q_kat = $f['q'];
        $kat_kat = $f['kat'];
        $dari_kat = $f['dari'];
        $sampai_kat = $f['sampai'];
        $sedia_kat = $f['sedia'];
        $f_kat = in_array($_GET['f_kat'] ?? '', ['belum', 'sudah'], true) ? (string) $_GET['f_kat'] : '';
        $sort_kat = in_array($_GET['sort'] ?? '', ['terdekat', 'terlama'], true) ? (string) $_GET['sort'] : 'terdekat';
        $filter_kat_aktif = $q_kat !== '' || $kat_kat !== '' || $dari_kat !== '' || $sampai_kat !== '' || $sedia_kat !== 'semua' || $f_kat !== '';

        // Bangun WHERE dinamis (prepared, tanpa konkatenasi nilai).
        $where_kat = ["s.status = 'Aktif'", 's.tanggal >= CURDATE()'];
        $params_kat = [];
        $types_kat = '';
        if ($q_kat !== '') {
            $where_kat[] = "(s.judul LIKE ? ESCAPE '\\\\' OR s.pemateri LIKE ? ESCAPE '\\\\')";
            $like = '%' . like_escape($q_kat) . '%';
            $params_kat[] = $like; $params_kat[] = $like;
            $types_kat .= 'ss';
        }
        if ($kat_kat !== '') {
            $where_kat[] = 's.kategori = ?';
            $params_kat[] = $kat_kat;
            $types_kat .= 's';
        }
        if ($dari_kat !== '') {
            $where_kat[] = 's.tanggal >= ?';
            $params_kat[] = $dari_kat;
            $types_kat .= 's';
        }
        if ($sampai_kat !== '') {
            $where_kat[] = 's.tanggal <= ?';
            $params_kat[] = $sampai_kat;
            $types_kat .= 's';
        }
        if ($sedia_kat === 'tersedia') {
            $where_kat[] = 's.kuota > 0';
        } elseif ($sedia_kat === 'penuh') {
            $where_kat[] = 's.kuota <= 0';
        }
        if ($f_kat === 'belum') {
            $where_kat[] = 'NOT EXISTS(SELECT 1 FROM registrations r WHERE r.id_seminar = s.id_seminar AND r.id_user = ?)';
            $params_kat[] = $id_user;
            $types_kat .= 'i';
        } elseif ($f_kat === 'sudah') {
            $where_kat[] = 'EXISTS(SELECT 1 FROM registrations r WHERE r.id_seminar = s.id_seminar AND r.id_user = ?)';
            $params_kat[] = $id_user;
            $types_kat .= 'i';
        }
        $where_sql_kat = implode(' AND ', $where_kat);

        // Hitung total untuk pagination.
        $sql_count_kat = "SELECT COUNT(*) AS c FROM seminars s WHERE $where_sql_kat";
        $stmt_count = mysqli_prepare($conn, $sql_count_kat);
        if ($params_kat) {
            mysqli_stmt_bind_param($stmt_count, $types_kat, ...$params_kat);
        }
        mysqli_stmt_execute($stmt_count);
        $total_kat = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_count))['c'];
        mysqli_stmt_close($stmt_count);

        $per_page_kat = 9;
        $total_page_kat = max(1, (int) ceil($total_kat / $per_page_kat));
        $p_kat = min($f['p'], $total_page_kat);
        $offset_kat = ($p_kat - 1) * $per_page_kat;

        // Ambil halaman saat ini.
        $sql_kat = "SELECT s.*, EXISTS(SELECT 1 FROM registrations r WHERE r.id_seminar = s.id_seminar AND r.id_user = ?) AS sudah_daftar
            FROM seminars s WHERE $where_sql_kat
            ORDER BY s.tanggal " . ($sort_kat === 'terlama' ? 'DESC' : 'ASC') . " LIMIT ? OFFSET ?";
        $params_page = array_merge([$id_user], $params_kat);
        $types_page = 'i' . $types_kat . 'ii';
        $params_page[] = $per_page_kat;
        $params_page[] = $offset_kat;

        $stmt_kat = mysqli_prepare($conn, $sql_kat);
        mysqli_stmt_bind_param($stmt_kat, $types_page, ...$params_page);
        mysqli_stmt_execute($stmt_kat);
        $result_kat = mysqli_stmt_get_result($stmt_kat);
        $seminar_rows = [];
        while ($row = mysqli_fetch_assoc($result_kat)) {
            $seminar_rows[] = $row;
        }
        ?>
        <div class="d-flex align-items-center justify-content-between mb-4">
            <h1 class="h5 fw-bold text-dark mb-0">
                <i class="bi bi-calendar-check-fill text-primary me-2"></i>Katalog Seminar Aktif
                <span class="badge badge-soft-primary rounded-pill ms-2 align-middle"><?= $total_kat ?></span>
            </h1>
        </div>

        <form method="get" action="mahasiswa.php" class="filter-bar mb-4" role="search" aria-label="Filter katalog">
            <input type="hidden" name="page" value="katalog">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <div class="input-group input-group-sm search-field">
                    <button type="submit" class="input-group-text" aria-label="Cari seminar"><i class="bi bi-search"></i></button>
                    <input type="search" name="q" class="form-control" value="<?= e($q_kat) ?>" placeholder="Cari judul / pemateri" aria-label="Cari judul atau pemateri">
                </div>
                <label for="kat" class="visually-hidden">Kategori</label>
                <select name="kat" id="kat" class="form-select form-select-sm" style="width: auto;">
                    <option value="">Semua kategori</option>
                    <?php foreach (kategori_seminar() as $kat): ?>
                        <option value="<?= e($kat) ?>" <?= $kat_kat === $kat ? 'selected' : '' ?>><?= e($kat) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="f_kat" class="visually-hidden">Filter pendaftaran</label>
                <select name="f_kat" id="f_kat" class="form-select form-select-sm" style="width: auto;">
                    <option value="">Semua seminar</option>
                    <option value="belum" <?= $f_kat === 'belum' ? 'selected' : '' ?>>Belum saya daftar</option>
                    <option value="sudah" <?= $f_kat === 'sudah' ? 'selected' : '' ?>>Sudah saya daftar</option>
                </select>
                <label for="sedia" class="visually-hidden">Ketersediaan</label>
                <select name="sedia" id="sedia" class="form-select form-select-sm" style="width: auto;">
                    <option value="semua" <?= $sedia_kat === 'semua' ? 'selected' : '' ?>>Semua kuota</option>
                    <option value="tersedia" <?= $sedia_kat === 'tersedia' ? 'selected' : '' ?>>Masih tersedia</option>
                    <option value="penuh" <?= $sedia_kat === 'penuh' ? 'selected' : '' ?>>Kuota penuh</option>
                </select>
                <label for="sort_kat" class="visually-hidden">Urutkan</label>
                <select name="sort" id="sort_kat" class="form-select form-select-sm" style="width: auto;">
                    <option value="terdekat" <?= $sort_kat === 'terdekat' ? 'selected' : '' ?>>Terdekat</option>
                    <option value="terlama" <?= $sort_kat === 'terlama' ? 'selected' : '' ?>>Terlama</option>
                </select>
                <button type="submit" class="btn btn-sm btn-primary fw-semibold px-3">Filter</button>
                <?php if ($filter_kat_aktif): ?>
                    <a href="mahasiswa.php?page=katalog" class="btn btn-sm btn-light fw-semibold">Reset</a>
                <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                <div class="input-group input-group-sm" style="width: auto;">
                    <span class="input-group-text"><i class="bi bi-calendar-range" aria-hidden="true"></i></span>
                    <input type="date" name="dari" class="form-control" value="<?= e($dari_kat) ?>" aria-label="Tanggal dari" title="Tanggal dari">
                    <span class="input-group-text">s/d</span>
                    <input type="date" name="sampai" class="form-control" value="<?= e($sampai_kat) ?>" aria-label="Tanggal sampai" title="Tanggal sampai">
                </div>
                <span class="small text-muted ms-auto">Menampilkan <?= count($seminar_rows) ?> dari <?= $total_kat ?> seminar</span>
            </div>
        </form>

        <div class="row g-4">
            <?php
            if (count($seminar_rows) > 0) {
                foreach ($seminar_rows as $row) {
                    $tgl = date('d M Y', strtotime($row['tanggal']));
                    $sudah_daftar = (int) $row['sudah_daftar'] === 1;
                    $kuota_total = (int) ($row['kuota_total'] ?? $row['kuota']);
                    $terisi = max(0, $kuota_total - (int) $row['kuota']);
                    $penuh = (int) $row['kuota'] <= 0;
                    $kategori_row = (string) ($row['kategori'] ?? 'Umum');
                    $jam_tampil = !empty($row['jam_mulai'])
                        ? substr((string) $row['jam_mulai'], 0, 5) . (!empty($row['jam_selesai']) ? '-' . substr((string) $row['jam_selesai'], 0, 5) : '') . ' WIB'
                        : 'Jam akan diumumkan';
                    $lokasi_tampil = !empty($row['lokasi'])
                        ? '<i class="bi bi-geo-alt text-primary me-2" aria-hidden="true"></i><span class="text-truncate">' . e($row['lokasi']) . '</span>'
                        : (!empty($row['link_online'])
                            ? '<i class="bi bi-camera-video text-primary me-2" aria-hidden="true"></i><span>Online</span>'
                            : '<i class="bi bi-geo-alt text-muted me-2" aria-hidden="true"></i><span class="text-muted">Lokasi akan diumumkan</span>');
                    $ringkas = '';
                    if (!empty($row['deskripsi'])) {
                        $teks = (string) $row['deskripsi'];
                        if (str_len($teks) > 120) {
                            $teks = (function_exists('mb_substr') ? mb_substr($teks, 0, 120, 'UTF-8') : substr($teks, 0, 120)) . '…';
                        }
                        $ringkas = $teks;
                    }
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card card-custom seminar-card h-100 overflow-hidden d-flex flex-column border-0 shadow-sm">
                    <div class="position-relative">
                        <?php if (!empty($row['poster'])): ?>
                            <img src="<?= e('uploads/posters/' . $row['poster']) ?>" class="card-img-top" width="400" height="200" loading="lazy" decoding="async" alt="Poster seminar <?= e($row['judul']) ?>" style="height: 200px; object-fit: cover;">
                        <?php else: ?>
                            <div class="bg-light text-primary d-flex flex-column align-items-center justify-content-center" style="height: 200px; border-bottom: 1px solid var(--border-color);">
                                <i class="bi bi-image fs-1 opacity-50" aria-hidden="true"></i>
                                <span class="small fw-semibold mt-2">AcadeMeet Seminar</span>
                            </div>
                        <?php endif; ?>
                        <div class="position-absolute top-0 end-0 m-3">
                            <?php if ($penuh): ?>
                                <span class="badge badge-soft-danger shadow-sm rounded-pill px-3 py-2 fw-bold">
                                    <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Penuh
                                </span>
                            <?php else: ?>
                                <span class="badge badge-soft-primary shadow-sm rounded-pill px-3 py-2 fw-bold">
                                    <i class="bi bi-ticket-detailed me-1" aria-hidden="true"></i><?= $terisi ?>/<?= $kuota_total ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body p-4 d-flex flex-column flex-grow-1">
                        <div class="mb-2">
                            <span class="badge badge-soft-primary rounded-pill px-2 py-1 fw-semibold"><?= e($kategori_row) ?></span>
                        </div>
                        <h3 class="h6 card-title fw-bold text-dark mb-3" style="min-height: 48px; line-height: 1.5;"><?= e($row['judul']) ?></h3>

                        <div class="mb-3">
                            <div class="small text-muted mb-2 d-flex align-items-center">
                                <i class="bi bi-person-circle text-primary me-2" aria-hidden="true"></i>
                                <span class="text-truncate"><?= e($row['pemateri']) ?></span>
                            </div>
                            <div class="small text-muted mb-2 d-flex align-items-center">
                                <i class="bi bi-calendar3 text-primary me-2" aria-hidden="true"></i>
                                <span><?= e($tgl) ?></span>
                                <span class="mx-1">·</span>
                                <span><?= e($jam_tampil) ?></span>
                            </div>
                            <div class="small text-muted d-flex align-items-center">
                                <?= $lokasi_tampil ?>
                            </div>
                            <?php if ($ringkas !== ''): ?>
                                <p class="small text-muted mt-2 mb-0" style="line-height: 1.5;"><?= e($ringkas) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($row['id_pemateri'])): ?>
                                <a href="mahasiswa.php?page=pemateri&id=<?= (int) $row['id_pemateri'] ?>" class="btn btn-sm btn-link p-0 text-primary fw-semibold mt-1">
                                    Lihat Profil Pemateri <i class="bi bi-arrow-right-short"></i>
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="mt-auto pt-3 border-top">
                            <?php if ($sudah_daftar): ?>
                                <span class="badge badge-soft-success d-block mb-2 py-2 rounded-pill">
                                    <i class="bi bi-check-circle-fill me-1"></i> Anda sudah terdaftar
                                </span>
                                <a href="mahasiswa.php?page=tiket" class="btn btn-light-primary w-100 fw-bold py-2 rounded-pill">
                                    <i class="bi bi-ticket-perforated me-1"></i> Lihat Tiket Saya
                                </a>
                            <?php else: ?>
                                <!-- badge setara dengan kartu "sudah terdaftar" -->
                                <?php if ($penuh): ?>
                                    <span class="badge badge-soft-danger d-block mb-2 py-2 rounded-pill">
                                        <i class="bi bi-x-circle me-1"></i> Kuota Penuh
                                    </span>
                                    <button type="button" class="btn btn-secondary w-100 fw-bold py-2 rounded-pill" disabled>
                                        Daftar Sekarang <i class="bi bi-arrow-right-short fs-5 ms-1" aria-hidden="true"></i>
                                    </button>
                                <?php else: ?>
                                    <span class="badge badge-soft-primary d-block mb-2 py-2 rounded-pill">
                                        <i class="bi bi-ticket-detailed me-1"></i> Masih tersedia
                                    </span>
                                    <button type="button" class="btn btn-primary w-100 fw-bold py-2 rounded-pill shadow-sm btn-daftar-seminar"
                                            data-bs-toggle="modal" data-bs-target="#confirmModal"
                                            data-id="<?= (int) $row['id_seminar'] ?>"
                                            data-judul="<?= e($row['judul']) ?>"
                                            data-pemateri="<?= e($row['pemateri']) ?>">
                                        Daftar Sekarang <i class="bi bi-arrow-right-short fs-5 ms-1" aria-hidden="true"></i>
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php
                }
            } else {
                if ($filter_kat_aktif) {
                    echo "<div class='col-12'><div class='card card-custom p-5 text-center'><i class='bi bi-search text-muted display-4 mb-3 opacity-50' aria-hidden='true'></i><h2 class='h6 fw-bold text-dark'>Tidak ada seminar yang cocok</h2><p class='text-muted small mb-0'>Coba ubah kata kunci, kategori, rentang tanggal, atau filter lainnya.</p><a href='mahasiswa.php?page=katalog' class='btn btn-sm btn-light mt-3'>Reset filter</a></div></div>";
                } else {
                    echo "<div class='col-12'><div class='card card-custom p-5 text-center'><i class='bi bi-calendar-x text-muted display-4 mb-3 opacity-50' aria-hidden='true'></i><h2 class='h6 fw-bold text-dark'>Belum Ada Seminar Tersedia</h2><p class='text-muted mb-0'>Kembali lagi nanti untuk informasi seminar terbaru.</p></div></div>";
                }
            }
            ?>
        </div>
        <?= pagination_html($total_kat, $p_kat, $per_page_kat) ?>
    <?php endif; ?>
</div>

<?php if ($page == 'katalog'): ?>
<!-- Modal Konfirmasi -->
<div class="modal fade" id="confirmModal" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="confirmModalLabel">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-body p-4 p-sm-5 text-center">
                <div class="auth-brand-icon mx-auto mb-4" style="width: 68px; height: 68px; font-size: 2rem; background: var(--primary-light); color: var(--primary); border: 1px solid var(--primary-border); border-radius: 20px;">
                    <i class="bi bi-ticket-perforated-fill"></i>
                </div>
                <h5 class="fw-bold text-body-emphasis mb-2" id="confirmModalLabel">Konfirmasi Pendaftaran</h5>
                <p class="text-muted mb-4">Apakah anda yakin ingin mendaftar pada seminar ini?</p>
                <div class="p-3 bg-light rounded-4 border text-start mb-4">
                    <div class="fw-bold text-dark mb-1" id="modalSeminarJudul">-</div>
                    <div class="small text-muted"><i class="bi bi-person-fill text-primary me-2"></i><span id="modalSeminarPemateri">-</span></div>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <button type="button" class="btn btn-light w-100 py-3 fw-bold rounded-pill text-secondary" data-bs-dismiss="modal">Batal</button>
                    </div>
                    <div class="col-6">
                        <form method="POST" action="mahasiswa.php?page=katalog" class="h-100">
                            <?= csrf_field() ?>
                            <input type="hidden" name="daftar" value="1">
                            <input type="hidden" name="id_seminar" id="modalSeminarId" value="">
                            <button type="submit" class="btn btn-primary w-100 py-3 fw-bold rounded-pill">Ya, Daftar</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($page == 'tiket'): ?>
<!-- Modal Konfirmasi Batal -->
<div class="modal fade" id="modalBatal" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="modalBatalLabel">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-body p-4 p-sm-5 text-center">
                <div class="auth-brand-icon mx-auto mb-4" style="width: 68px; height: 68px; font-size: 2rem; background: #fee2e2; color: #dc2626; border-radius: 20px;">
                    <i class="bi bi-x-circle-fill"></i>
                </div>
                <h5 class="fw-bold text-body-emphasis mb-2" id="modalBatalLabel">Batalkan Pendaftaran</h5>
                <p class="text-muted mb-4">Yakin ingin membatalkan pendaftaran pada seminar ini? Kuota akan dikembalikan.</p>
                <div class="p-3 bg-light rounded-4 border text-start mb-4">
                    <div class="fw-bold text-dark" id="modalBatalJudul">-</div>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <button type="button" class="btn btn-light w-100 py-3 fw-bold rounded-pill text-secondary" data-bs-dismiss="modal">Tidak</button>
                    </div>
                    <div class="col-6">
                        <form method="POST" action="mahasiswa.php?page=tiket" class="h-100">
                            <?= csrf_field() ?>
                            <input type="hidden" name="batal_daftar" value="1">
                            <input type="hidden" name="id_registrasi" id="modalBatalId" value="">
                            <button type="submit" class="btn btn-danger w-100 py-3 fw-bold rounded-pill">Ya, Batalkan</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
    const confirmModal = document.getElementById('confirmModal');
    if (confirmModal) {
        confirmModal.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            document.getElementById('modalSeminarJudul').textContent = btn.getAttribute('data-judul');
            document.getElementById('modalSeminarPemateri').textContent = btn.getAttribute('data-pemateri');
            document.getElementById('modalSeminarId').value = btn.getAttribute('data-id');
        });
    }

    const modalBatal = document.getElementById('modalBatal');
    if (modalBatal) {
        modalBatal.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            document.getElementById('modalBatalJudul').textContent = btn.getAttribute('data-judul');
            document.getElementById('modalBatalId').value = btn.getAttribute('data-id');
        });
    }

    // QR dibuat di browser; kode tiket tidak dikirim ke server pihak ketiga.
    if (typeof QRCode !== 'undefined') {
        document.querySelectorAll('.qr-ticket').forEach(function (el) {
            new QRCode(el, { text: el.dataset.code, width: 124, height: 124, correctLevel: QRCode.CorrectLevel.M });
            // Kontainer sudah berlabel role="img"; anak yang digenerate library
            // (img/canvas tanpa alt) disembunyikan dari AT agar tidak diumumkan ganda.
            el.querySelectorAll('img, canvas').forEach(function (anak) {
                anak.setAttribute('aria-hidden', 'true');
            });
        });
    }

    // Tombol salin kode tiket (mis. saat di pintu masuk acara).
    document.querySelectorAll('.js-salin-kode').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const kode = btn.dataset.kode;
            const selesai = function () {
                const ikon = btn.querySelector('i');
                if (!ikon) return;
                ikon.className = 'bi bi-check2';
                setTimeout(function () { ikon.className = 'bi bi-clipboard'; }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(kode).then(selesai).catch(function () {});
            }
        });
    });

    // Cegah double-submit pada form pendaftaran/profil/password.
    // Jangan pakai `disabled` — tombol disabled dikeluarkan dari FormData, flag POST bisa hilang.
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
</script><script src="theme.js?v=2.10" defer></script>
</body>
</html>
