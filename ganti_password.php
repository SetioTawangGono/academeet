<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/koneksi.php';
require_once __DIR__ . '/services.php';

header('Cache-Control: no-store');

// Halaman ini untuk semua role yang login; wajib_ganti_password dicek di require_role.
require_role(['admin', 'mahasiswa', 'panitia']);

$role = (string) $_SESSION['role'];
$minPas = password_min_untuk_role($role);
$pesan = '';
$tipe = '';

if (isset($_POST['ganti'])) {
    csrf_check();
    $baru = (string) ($_POST['password_baru'] ?? '');
    $konfirmasi = (string) ($_POST['password_confirm'] ?? '');

    [$tipe, $pesan] = ganti_password_wajib($conn, (int) $_SESSION['id_user'], $role, $baru, $konfirmasi);
    if ($tipe === 'success') {
        // Perbarui sesi agar tidak ikut logout karena auth_version naik.
        $_SESSION['auth_version'] = (int) $_SESSION['auth_version'] + 1;
        flash_set('success', $pesan);
        $beranda = $role === 'admin' ? 'admin.php' : ($role === 'panitia' ? 'admin.php?page=scan' : 'mahasiswa.php');
        redirect($beranda);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ganti Password - AcadeMeet</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <?= theme_boot_script() ?>
    <link rel="stylesheet" href="style.css?v=2.15">
</head>
<body class="auth-wrapper">
<?= theme_toggle_button('theme-toggle--floating') ?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-11 col-sm-9 col-md-6 col-lg-4">
            <div class="auth-card p-4 p-sm-5">
                <div class="text-center mb-4">
                    <div class="auth-brand-icon mx-auto">
                        <i class="bi bi-shield-lock-fill"></i>
                    </div>
                    <h1 class="h3 fw-bold text-dark mb-1">Ganti Password</h1>
                    <p class="text-muted small">Demi keamanan, ganti password Anda sebelum melanjutkan.</p>
                </div>

                <?php if ($pesan !== ''): ?>
                    <div class="alert alert-<?= e($tipe === '' ? 'info' : $tipe) ?> d-flex align-items-center py-2 px-3 small border-0 shadow-sm rounded-3 mb-4" role="alert">
                        <i class="bi bi-exclamation-circle-fill me-2 fs-5"></i>
                        <div><?= e($pesan) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="password_baru" class="form-label small fw-semibold text-secondary">Password Baru</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                            <input type="password" name="password_baru" class="form-control border-start-0 ps-0" id="password_baru" placeholder="Minimal <?= (int) $minPas ?> karakter" minlength="<?= (int) $minPas ?>" autocomplete="new-password" required>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="password_confirm" class="form-label small fw-semibold text-secondary">Ulangi Password Baru</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" name="password_confirm" class="form-control border-start-0 ps-0" id="password_confirm" placeholder="Ulangi password" minlength="<?= (int) $minPas ?>" autocomplete="new-password" required>
                        </div>
                    </div>
                    <button type="submit" name="ganti" class="btn btn-primary w-100 py-2 shadow-sm mb-3">
                        <i class="bi bi-check2-circle me-1"></i> Simpan Password Baru
                    </button>
                </form>

                <div class="text-center mt-3 pt-3 border-top">
                    <form method="POST" action="logout.php" class="d-inline m-0">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-link text-decoration-none small fw-semibold text-primary p-0">
                            <i class="bi bi-box-arrow-right me-1"></i> Keluar
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="theme.js?v=2.10" defer></script>
</body>
</html>
