<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/koneksi.php';
require __DIR__ . '/services.php';
require __DIR__ . '/mailer.php';

header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

// Rate limit permintaan reset: per email 3/jam, per IP 10/jam.
$pesan = '';
$email = '';

if (isset($_POST['minta_reset'])) {
    csrf_check();

    $email = trim((string) ($_POST['email'] ?? ''));
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $emailLower = strtolower($email);

    $kEmail = throttle_key('reset_em', $emailLower);
    $kIp    = throttle_key('reset_ip', $ip);

    $blokir = false;
    if ($conn instanceof mysqli) {
        $c1 = throttle_terlalu_banyak($conn, 'reset_em', $kEmail, 3, 3600);
        $c2 = throttle_terlalu_banyak($conn, 'reset_ip', $kIp, 10, 3600);
        // Bila DB tak tersedia, jangan blokir (respons tetap generik).
        $blokir = ($c1 === true) || ($c2 === true);
    }

    if (!$blokir && $conn instanceof mysqli) {
        throttle_catat($conn, 'reset_em', $kEmail);
        throttle_catat($conn, 'reset_ip', $kIp);

        // Hanya role mahasiswa yang boleh reset mandiri.
        $stmt = mysqli_prepare($conn, "SELECT id_user, email FROM users WHERE email = ? AND role = 'mahasiswa' LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $email);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($row) {
            $token = buat_token_reset($conn, (int) $row['id_user']);
            if ($token !== null) {
                $base = env_secret('ACADEMEET_BASE_URL');
                if ($base !== '') {
                    kirim_email($row['email'], 'Reset Password AcadeMeet', isi_email_reset($base, $token));
                } else {
                    error_log('ACADEMEET_BASE_URL belum diset; tautan reset tidak dibuat.');
                }
            }
        }
    }
    // Respons SELALU sama apa pun hasilnya.
    $pesan = 'Jika email terdaftar, tautan reset telah dikirim.';
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Password - AcadeMeet</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <?= theme_boot_script() ?>
    <link rel="stylesheet" href="style.css?v=2.12">
</head>
<body class="auth-wrapper">
<?= theme_toggle_button('theme-toggle--floating') ?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-11 col-sm-9 col-md-6 col-lg-4">
            <div class="auth-card p-4 p-sm-5">
                <div class="text-center mb-4">
                    <div class="auth-brand-icon mx-auto">
                        <i class="bi bi-key-fill"></i>
                    </div>
                    <h1 class="h3 fw-bold text-dark mb-1">Lupa Password</h1>
                    <p class="text-muted small">Masukkan email akun mahasiswa Anda.</p>
                </div>

                <?php if ($pesan !== ''): ?>
                    <div class="alert alert-info d-flex align-items-center py-2 px-3 small border-0 shadow-sm rounded-3 mb-4" role="alert">
                        <i class="bi bi-info-circle-fill me-2 fs-5"></i>
                        <div><?= e($pesan) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="email" class="form-label small fw-semibold text-secondary">Email Kampus</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                            <input type="email" name="email" class="form-control border-start-0 ps-0" id="email" placeholder="nama@kampus.ac.id" autocomplete="username" value="<?= e($email) ?>" required>
                        </div>
                    </div>
                    <button type="submit" name="minta_reset" class="btn btn-primary w-100 py-2 shadow-sm mb-3">
                        <i class="bi bi-send me-1"></i> Kirim Tautan Reset
                    </button>
                </form>

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
