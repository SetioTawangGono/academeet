<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/koneksi.php';

$old = ['nama_lengkap' => '', 'nim' => '', 'prodi' => '', 'email' => ''];
$field_error = '';

if (isset($_POST['register'])) {
    csrf_check();

    $nama  = trim((string) ($_POST['nama_lengkap'] ?? ''));
    $nim   = trim((string) ($_POST['nim'] ?? ''));
    $prodi = trim((string) ($_POST['prodi'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    if ($nama !== '') {
        $old['nama_lengkap'] = $nama;
    }
    if ($nim !== '') {
        $old['nim'] = $nim;
    }
    if ($email !== '') {
        $old['email'] = $email;
    }
    if (in_array($prodi, prodi_terdaftar(), true)) {
        $old['prodi'] = $prodi;
    }

    $field_error = validasi_data_diri($nama, $nim, $prodi, $email);
    if ($field_error === '' && !password_ok($password)) {
        $field_error = 'Password minimal 8 karakter.';
    } elseif ($field_error === '' && $password !== $confirm) {
        $field_error = 'Konfirmasi password tidak cocok.';
    } elseif ($field_error === '') {
        try {
            $stmt = mysqli_prepare($conn, "SELECT id_user FROM users WHERE email = ? OR (nim = ? AND nim <> '') LIMIT 1");
            mysqli_stmt_bind_param($stmt, 'ss', $email, $nim);
            mysqli_stmt_execute($stmt);
            $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if ($exists) {
                throw new RuntimeException('Email atau NIM sudah digunakan.');
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $role = 'mahasiswa';
            $nim_simpan = $nim === '' ? null : $nim;
            $stmt = mysqli_prepare($conn, "INSERT INTO users (nama_lengkap, nim, prodi, email, password, role) VALUES (?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'ssssss', $nama, $nim_simpan, $prodi, $email, $hash, $role);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($ok) {
                flash_set('success', 'Pendaftaran berhasil! Silakan login.');
                redirect('index.php');
            }
            throw new RuntimeException('Pendaftaran gagal. Coba lagi.');
        } catch (Throwable $ex) {
            $field_error = mysqli_errno($conn) === 1062 ? 'Email atau NIM sudah digunakan.' : $ex->getMessage();
        }
    }
    $_SESSION['_old'] = $old;
    $_SESSION['_flash_error'] = $field_error;
    redirect('register.php');
}

// Isi ulang form setelah validasi gagal, lalu bersihkan.
if (!empty($_SESSION['_old'])) {
    $old = array_merge($old, $_SESSION['_old']);
    unset($_SESSION['_old']);
}
if (isset($_SESSION['_flash_error'])) {
    $field_error = (string) $_SESSION['_flash_error'];
    unset($_SESSION['_flash_error']);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Mahasiswa - AcadeMeet</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <?= theme_boot_script() ?>
    <link rel="stylesheet" href="style.css?v=2.15">
</head>
<body class="auth-wrapper">
<?= theme_toggle_button('theme-toggle--floating') ?>

<div class="container py-4">    <div class="row justify-content-center">
        <div class="col-11 col-sm-10 col-md-8 col-lg-6">
            <div class="auth-card p-4 p-sm-5">
                <div class="text-center mb-4">
                    <div class="auth-brand-icon mx-auto">
                        <i class="bi bi-person-plus-fill" aria-hidden="true"></i>
                    </div>
                    <h1 class="h3 fw-bold text-dark mb-1">Registrasi Mahasiswa</h1>
                    <p class="text-muted small">Lengkapi data diri untuk mengikuti seminar kampus</p>
                </div>

                <?= flash_html() ?>
                <?php if ($field_error !== ''): ?>
                    <div class="alert alert-danger border-0 rounded-3" role="alert">
                        <i class="bi bi-exclamation-circle-fill me-1" aria-hidden="true"></i> <?= e($field_error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="nama_lengkap" class="form-label small fw-semibold text-secondary">Nama Lengkap</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-person"></i></span>
                            <input type="text" name="nama_lengkap" id="nama_lengkap" class="form-control border-start-0 ps-0" placeholder="Muhammad Fadhil" maxlength="100" autocomplete="name" value="<?= e($old['nama_lengkap']) ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="nim" class="form-label small fw-semibold text-secondary">NIM</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-card-text"></i></span>
                                <input type="text" name="nim" id="nim" class="form-control border-start-0 ps-0" placeholder="211011400..." maxlength="20" inputmode="numeric" value="<?= e($old['nim']) ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="prodi" class="form-label small fw-semibold text-secondary">Program Studi</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-mortarboard"></i></span>
                                <select name="prodi" id="prodi" class="form-select border-start-0 ps-0" required>
                                    <option value="" disabled <?= $old['prodi'] === '' ? 'selected' : '' ?>>Pilih Program Studi...</option>
                                    <?php foreach (prodi_terdaftar() as $opsi): ?>
                                        <option value="<?= e($opsi) ?>" <?= $old['prodi'] === $opsi ? 'selected' : '' ?>><?= e($opsi) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label small fw-semibold text-secondary">Email Kampus / Aktif</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                            <input type="email" name="email" id="email" class="form-control border-start-0 ps-0" placeholder="nama@kampus.ac.id" maxlength="100" autocomplete="username" value="<?= e($old['email']) ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <label for="password" class="form-label small fw-semibold text-secondary">Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                                <input type="password" name="password" id="password" class="form-control border-start-0 ps-0" placeholder="Minimal 8 karakter" minlength="8" autocomplete="new-password" required>
                            </div>
                        </div>
                        <div class="col-md-6 mb-4">
                            <label for="password_confirm" class="form-label small fw-semibold text-secondary">Ulangi Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-lock-fill"></i></span>
                                <input type="password" name="password_confirm" id="password_confirm" class="form-control border-start-0 ps-0" placeholder="Ulangi password" minlength="8" autocomplete="new-password" required>
                            </div>
                        </div>
                    </div>

                    <button type="submit" name="register" class="btn btn-primary w-100 py-2 shadow-sm mb-3">
                        <i class="bi bi-check2-circle me-1"></i> Buat Akun Sekarang
                    </button>
                </form>

                <div class="text-center mt-3 pt-3 border-top">
                    <p class="small text-muted mb-0">Sudah memiliki akun? <a href="index.php" class="text-decoration-none fw-semibold text-primary">Login di sini</a></p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="theme.js?v=2.10" defer></script>
</body>
</html>
