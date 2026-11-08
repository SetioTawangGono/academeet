<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/koneksi.php';
require_once __DIR__ . '/services.php';

$kode = trim((string) ($_GET['kode'] ?? ''));
$data = null;
$error_verif = '';

if ($kode !== '') {
    // Throttle di DB (lapis 1): maksimal 10 percobaan per 15 menit per IP.
    // Bila DB tak tersedia, $ipBlokir null dan jatuh ke throttle sesi (lapis 2).
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $window = 900;
    $ipBlokir = null;
    if ($conn instanceof mysqli) {
        $ipBlokir = throttle_terlalu_banyak($conn, 'verif_ip', throttle_key('verif_ip', $ip), 10, $window);
        if ($ipBlokir === null) {
            error_log('Throttle DB verifikasi gagal; jatuh ke throttle sesi.');
        }
    }

    // Throttle sesi (lapis 2, lama): maksimal 10 percobaan per 15 menit per sesi.
    $now = time();
    $attempts = array_values(array_filter($_SESSION['_verif_attempts'] ?? [], static fn($t) => $t > $now - $window));
    $sesiBlokir = count($attempts) >= 10;

    if ($ipBlokir === true || ($ipBlokir === null && $sesiBlokir)) {
        $error_verif = 'Terlalu banyak percobaan verifikasi. Coba lagi dalam 15 menit.';
    } else {
        $stmt = mysqli_prepare($conn, "SELECT r.kode_tiket, r.status_hadir, u.nama_lengkap, s.judul, s.tanggal, s.pemateri
            FROM registrations r
            JOIN users u ON r.id_user = u.id_user
            JOIN seminars s ON r.id_seminar = s.id_seminar
            WHERE r.kode_tiket = ?
            LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $kode);
        mysqli_stmt_execute($stmt);
        $data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$data) {
            if ($conn instanceof mysqli) {
                throttle_catat($conn, 'verif_ip', throttle_key('verif_ip', $ip));
            }
            $attempts[] = $now;
            $_SESSION['_verif_attempts'] = $attempts;
        }
    }
}

// Privasi: halaman publik hanya menampilkan nama tersamar.
$nama_tersamar = '';
if ($data) {
    $nama_tersamar = preg_replace_callback('/\S+/u', static function (array $m): string {
        $kata = $m[0];
        $panjang = function_exists('mb_strlen') ? mb_strlen($kata, 'UTF-8') : strlen($kata);
        if ($panjang <= 1) {
            return $kata . '*';
        }
        $awal = function_exists('mb_substr') ? mb_substr($kata, 0, 1, 'UTF-8') : substr($kata, 0, 1);
        return $awal . str_repeat('*', $panjang - 1);
    }, (string) $data['nama_lengkap']);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Sertifikat - AcadeMeet</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <?= theme_boot_script() ?>
    <link rel="stylesheet" href="style.css?v=2.14">
</head>
<body class="auth-wrapper">
<?= theme_toggle_button('theme-toggle--floating') ?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-11 col-sm-9 col-md-7 col-lg-5">
            <div class="card card-custom p-4 p-sm-5">
                <div class="text-center mb-4">
                    <div class="auth-brand-icon mx-auto mb-3">
                        <i class="bi bi-patch-check-fill"></i>
                    </div>
                    <h1 class="h4 fw-bold text-dark mb-1">Verifikasi Sertifikat</h1>
                    <p class="text-muted small mb-0">Masukkan kode tiket yang tertera pada sertifikat.</p>
                </div>

                <form method="GET" action="verifikasi.php" class="mb-4">
                    <label for="kode" class="form-label small fw-semibold text-secondary">Kode Tiket</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-upc-scan"></i></span>
                        <input type="text" class="form-control border-start-0 ps-0 font-monospace" id="kode" name="kode" value="<?= e($kode) ?>" placeholder="ACD-XXXXXX" required autofocus pattern="ACD-[0-9A-Fa-f]{6}" spellcheck="false" autocapitalize="characters" style="text-transform: uppercase;">
                    </div>
                    <button type="submit" class="btn btn-primary w-100 mt-3">
                        <i class="bi bi-search me-1"></i> Cek Keaslian
                    </button>
                </form>

                <?php if ($error_verif !== ''): ?>
                    <div class="alert alert-warning border-0 rounded-3" role="alert">
                        <i class="bi bi-hourglass-split me-1"></i> <?= e($error_verif) ?>
                    </div>
                <?php elseif ($kode !== ''): ?>
                    <?php if ($data): ?>
                        <div class="alert alert-<?= $data['status_hadir'] === 'Hadir' ? 'success' : 'warning' ?> border-0 rounded-3" role="alert">
                            <div class="fw-bold mb-2">
                                <i class="bi bi-<?= $data['status_hadir'] === 'Hadir' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> me-1"></i>
                                <?= $data['status_hadir'] === 'Hadir' ? 'Sertifikat valid.' : 'Peserta terdaftar, tetapi belum tercatat hadir.' ?>
                            </div>
                            <div class="small">
                                <div><strong>Nama:</strong> <?= e($nama_tersamar) ?></div>
                                <div><strong>Seminar:</strong> <?= e($data['judul']) ?></div>
                                <div><strong>Tanggal:</strong> <?= e(date('d F Y', strtotime($data['tanggal']))) ?></div>
                                <div><strong>Pemateri:</strong> <?= e($data['pemateri']) ?></div>
                                <div><strong>Status:</strong> <?= e($data['status_hadir']) ?></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-danger border-0 rounded-3" role="alert">
                            <i class="bi bi-x-circle-fill me-1"></i> Kode tiket tidak ditemukan.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="text-center mt-3 pt-3 border-top">
                    <a href="index.php" class="text-decoration-none small fw-semibold text-primary">
                        <i class="bi bi-arrow-left me-1"></i> Kembali ke halaman login
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="theme.js?v=2.10" defer></script>
</body>
</html>
