<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/koneksi.php';
require __DIR__ . '/services.php';

header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

// Token diambil dari GET (tautan email) atau tersembunyi di form POST.
$token = (string) ($_GET['token'] ?? ($_POST['token'] ?? ''));
if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    $token = '';
}

$pesan = '';
$tipe = '';
$sukses = false;
$token_invalid = ($token === '');

if (isset($_POST['reset'])) {
    csrf_check();
    $baru    = (string) ($_POST['password_baru'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if ($baru !== $confirm) {
        $tipe = 'danger';
        $pesan = 'Konfirmasi password baru tidak cocok.';
    } else {
        [$tipe, $pesan] = pakai_token_reset($conn, $token, $baru);
        $sukses = ($tipe === 'success');
        if (!$sukses) {
            $token_invalid = true; // token ditolak: jangan tampilkan form lagi
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - AcadeMeet</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <?= theme_boot_script() ?>
    <link rel="stylesheet" href="style.css?v=2.11">
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
                    <h1 class="h3 fw-bold text-dark mb-1">Reset Password</h1>
                    <p class="text-muted small">Buat password baru untuk akun Anda.</p>
                </div>

                <?php if ($pesan !== ''): ?>
                    <div class="alert alert-<?= e($tipe === '' ? 'info' : $tipe) ?> d-flex align-items-center py-2 px-3 small border-0 shadow-sm rounded-3 mb-4" role="alert">
                        <i class="bi bi-<?= $sukses ? 'check-circle-fill' : 'exclamation-circle-fill' ?> me-2 fs-5"></i>
                        <div><?= e($pesan) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($sukses): ?>
                    <a href="index.php" class="btn btn-primary w-100 py-2 shadow-sm">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Ke Halaman Login
                    </a>
                <?php elseif ($token_invalid): ?>
                    <div class="alert alert-warning border-0 rounded-3 small" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        Tautan reset tidak valid atau sudah kedaluwarsa. Minta tautan baru.
                    </div>
                    <a href="lupa_password.php" class="btn btn-outline-primary w-100 py-2 mt-2">
                        <i class="bi bi-arrow-repeat me-1"></i> Minta Tautan Baru
                    </a>
                <?php else: ?>
                    <form method="POST" action="">
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                        <div class="mb-3">
                            <label for="password_baru" class="form-label small fw-semibold text-secondary">Password Baru</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                                <input type="password" name="password_baru" class="form-control border-start-0 ps-0" id="password_baru" placeholder="Minimal 8 karakter" minlength="8" autocomplete="new-password" required>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label for="password_confirm" class="form-label small fw-semibold text-secondary">Ulangi Password Baru</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-lock-fill"></i></span>
                                <input type="password" name="password_confirm" class="form-control border-start-0 ps-0" id="password_confirm" placeholder="Ulangi password" minlength="8" autocomplete="new-password" required>
                            </div>
                        </div>
                        <button type="submit" name="reset" class="btn btn-primary w-100 py-2 shadow-sm mb-3">
                            <i class="bi bi-check2-circle me-1"></i> Simpan Password Baru
                        </button>
                    </form>
                <?php endif; ?>

                <div class="text-center mt-3 pt-3 border-top">
                    <a href="index.php" class="text-decoration-none small fw-semibold text-primary">
                        <i class="bi bi-arrow-left me-1"></i> Kembali ke Login
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="theme.js?v=2.10" defer></script>
</body>
</html>
