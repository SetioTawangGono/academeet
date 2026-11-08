<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/koneksi.php';
require_once __DIR__ . '/services.php';

if (isset($_SESSION['role'])) {
    $beranda = $_SESSION['role'] === 'admin'
        ? 'admin.php'
        : ($_SESSION['role'] === 'panitia' ? 'admin.php?page=scan' : 'mahasiswa.php');
    redirect($beranda);
}

if (isset($_POST['login'])) {
    csrf_check();

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    // Throttle di DB (lapis 1): kunci per (email+IP) 5/15m, per IP 30/15m,
    // per email global 20/15m. Bila DB tak tersedia, $dbBlokir tetap null dan
    // logika jatuh ke throttle sesi lama (lapis 2) di bawah.
    $window = 900;
    $kEmailIp = throttle_key('login_ep', $email . '|' . $ip);
    $kIp      = throttle_key('login_ip', $ip);
    $kEmail   = throttle_key('login_em', $email);

    $dbBlokir = null;
    if ($conn instanceof mysqli) {
        $c1 = throttle_terlalu_banyak($conn, 'login_ep', $kEmailIp, 5, $window);
        $c2 = throttle_terlalu_banyak($conn, 'login_ip', $kIp, 30, $window);
        $c3 = throttle_terlalu_banyak($conn, 'login_em', $kEmail, 20, $window);
        if ($c1 === null || $c2 === null || $c3 === null) {
            $dbBlokir = null; // gagal query: pakai throttle sesi
            error_log('Throttle DB login gagal; jatuh ke throttle sesi.');
        } else {
            $dbBlokir = ($c1 || $c2 || $c3);
        }
    }

    // Throttle sesi (lapis 2, lama): maksimal 5 percobaan per 15 menit per sesi.
    $now = time();
    $attempts = array_values(array_filter($_SESSION['_login_attempts'] ?? [], static fn($t) => $t > $now - $window));
    $sesiBlokir = count($attempts) >= 5;

    if ($dbBlokir === true || ($dbBlokir === null && $sesiBlokir)) {
        $error = 'Terlalu banyak percobaan login. Coba lagi dalam 15 menit.';
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id_user, nama_lengkap, role, password, auth_version FROM users WHERE email = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $email);
        mysqli_stmt_execute($stmt);
        $data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$data) {
            // Penyeragaman waktu: hash dummy agar waktu respons tak membedakan
            // email terdaftar atau tidak (anti user-enumeration).
            // Bukan kredensial: hash dari string acak sekali pakai.
            password_verify($password, '$2y$10$emJNftDZGeDf17dfkBWxceefV7Uf/fMM49zGcDtd6Z2MQ2XVs4Wzq');
        }

        if ($data && password_verify($password, $data['password'])) {
            // Rehash bila algoritma/biaya sudah usang.
            if (password_needs_rehash($data['password'], PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $up = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id_user = ?");
                mysqli_stmt_bind_param($up, 'si', $newHash, $data['id_user']);
                mysqli_stmt_execute($up);
                mysqli_stmt_close($up);
            }
            session_regenerate_id(true);
            $_SESSION['id_user'] = (int) $data['id_user'];
            $_SESSION['nama']    = $data['nama_lengkap'];
            $_SESSION['role']    = $data['role'];
            $_SESSION['auth_version'] = (int) $data['auth_version'];
            $_SESSION['_last_active'] = time();
            // Login sukses hanya menghapus kunci (email+IP), bukan kunci IP.
            if ($conn instanceof mysqli) {
                throttle_hapus($conn, 'login_ep', $kEmailIp);
            }
            unset($_SESSION['_login_attempts'], $_SESSION['_csrf']);
            redirect($data['role'] === 'admin' ? 'admin.php' : ($data['role'] === 'panitia' ? 'admin.php?page=scan' : 'mahasiswa.php'));
        }

        // Catat percobaan gagal ke DB (semua kunci) dan sesi.
        if ($conn instanceof mysqli) {
            throttle_catat($conn, 'login_ep', $kEmailIp);
            throttle_catat($conn, 'login_ip', $kIp);
            throttle_catat($conn, 'login_em', $kEmail);
        }
        $attempts[] = $now;
        $_SESSION['_login_attempts'] = $attempts;
        $error = 'Email atau password salah.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - AcadeMeet</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <?= theme_boot_script() ?>
    <link rel="stylesheet" href="style.css?v=2.14">
</head>
<body class="auth-wrapper">
<?= theme_toggle_button('theme-toggle--floating') ?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-11 col-sm-9 col-md-6 col-lg-4">
            <div class="auth-card p-4 p-sm-5">
                <div class="text-center mb-4">
                    <div class="auth-brand-icon mx-auto">
                        <i class="bi bi-mortarboard-fill"></i>
                    </div>
                    <h1 class="h3 fw-bold text-dark mb-1">AcadeMeet</h1>
                    <p class="text-muted small">Portal Seminar & Event Kampus</p>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center py-2 px-3 small border-0 shadow-sm rounded-3 mb-4" role="alert">
                        <i class="bi bi-exclamation-circle-fill me-2 fs-5"></i>
                        <div><?= e($error) ?></div>
                    </div>
                <?php endif; ?>
                <?= flash_html() ?>

                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="email" class="form-label small fw-semibold text-secondary">Email Kampus</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                            <input type="email" name="email" class="form-control border-start-0 ps-0" id="email" placeholder="nama@kampus.ac.id" autocomplete="username" value="<?= e($email ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label small fw-semibold text-secondary">Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                            <input type="password" name="password" class="form-control border-start-0 ps-0" id="password" placeholder="&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;" autocomplete="current-password" required>
                        </div>
                    </div>
                    <button type="submit" name="login" class="btn btn-primary w-100 py-2 shadow-sm mb-3">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Masuk Sekarang
                    </button>
                </form>

                <div class="text-center mt-3 pt-3 border-top">
                    <p class="small text-muted mb-0">Belum memiliki akun? <a href="register.php" class="text-decoration-none fw-semibold text-primary">Daftar Akun Mahasiswa</a></p>
                    <p class="small mb-0 mt-2"><a href="lupa_password.php" class="text-decoration-none fw-semibold text-secondary">Lupa password?</a></p>
                </div>
                <div class="text-center mt-2">
                    <a href="verifikasi.php" class="text-decoration-none small fw-semibold text-muted">
                        <i class="bi bi-patch-check me-1" aria-hidden="true"></i> Verifikasi Sertifikat
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="theme.js?v=2.10" defer></script>
</body>
</html>
