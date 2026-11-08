<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/koneksi.php';
require_role('mahasiswa');

$id_user = (int) $_SESSION['id_user'];
$id_seminar = (int) ($_GET['id'] ?? 0);

if ($id_seminar <= 0) {
    flash_set('danger', 'ID seminar tidak valid.');
    redirect('mahasiswa.php?page=tiket');
}

$stmt = mysqli_prepare($conn, "SELECT r.kode_tiket, u.nama_lengkap, s.judul, s.tanggal, s.pemateri, s.ttd_nama, s.ttd_jabatan
    FROM registrations r
    JOIN users u ON r.id_user = u.id_user
    JOIN seminars s ON r.id_seminar = s.id_seminar
    WHERE r.id_user = ? AND r.id_seminar = ? AND r.status_hadir = 'Hadir'
    LIMIT 1");
mysqli_stmt_bind_param($stmt, 'ii', $id_user, $id_seminar);
mysqli_stmt_execute($stmt);
$data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$data) {
    flash_set('danger', 'Sertifikat belum tersedia. Anda belum hadir di seminar ini.');
    redirect('mahasiswa.php?page=tiket');
}

$nama_mahasiswa = $data['nama_lengkap'];
$tanggal_format = date('d F Y', strtotime($data['tanggal']));
$tahun = date('Y', strtotime($data['tanggal']));

if (isset($_GET['download']) && $_GET['download'] === 'true') {
    require __DIR__ . '/fpdf.php';
    ob_start();

    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect(0, 0, 297, 210, 'F');

    $pdf->SetDrawColor(191, 219, 254);
    $pdf->SetLineWidth(1.5);
    $pdf->Rect(10, 10, 277, 190, 'D');

    $pdf->SetDrawColor(226, 232, 240);
    $pdf->SetLineWidth(0.5);
    $pdf->Rect(13, 13, 271, 184, 'D');

    $c_primary = [37, 99, 235];
    $c_muted   = [100, 116, 139];
    $c_warning = [217, 119, 6];

    $pdf->SetFont('Times', 'B', 28);
    $pdf->SetTextColor($c_primary[0], $c_primary[1], $c_primary[2]);
    $pdf->SetXY(0, 28);
    $pdf->Cell(297, 12, 'SERTIFIKAT PENGHARGAAN', 0, 1, 'C');

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor($c_warning[0], $c_warning[1], $c_warning[2]);
    $pdf->SetXY(0, 41);
    $pdf->Cell(297, 8, 'NOMOR: ' . pdf_text($data['kode_tiket']) . '/ACDM/' . $tahun, 0, 1, 'C');

    $pdf->SetFont('Times', 'I', 13);
    $pdf->SetTextColor($c_muted[0], $c_muted[1], $c_muted[2]);
    $pdf->SetXY(0, 56);
    $pdf->Cell(297, 8, 'Diberikan dengan penuh rasa bangga kepada:', 0, 1, 'C');

    $pdf->SetFont('Times', 'B', 32);
    $pdf->SetTextColor($c_primary[0], $c_primary[1], $c_primary[2]);
    $pdf->SetXY(0, 68);
    $pdf->Cell(297, 16, pdf_text(strtoupper($nama_mahasiswa)), 0, 1, 'C');

    $pdf->SetDrawColor(37, 99, 235);
    $pdf->SetLineWidth(0.8);
    $pdf->Line(85, 87, 212, 87);

    $pdf->SetFont('Times', 'I', 13);
    $pdf->SetTextColor($c_muted[0], $c_muted[1], $c_muted[2]);
    $pdf->SetXY(0, 94);
    $pdf->Cell(297, 8, 'Atas partisipasi dan kehadirannya sebagai Peserta dalam acara Seminar:', 0, 1, 'C');

    $pdf->SetFont('Times', 'B', 16);
    $pdf->SetTextColor($c_primary[0], $c_primary[1], $c_primary[2]);
    $pdf->SetXY(20, 104);
    $pdf->MultiCell(257, 8, pdf_text('"' . strtoupper($data['judul']) . '"'), 0, 'C');

    $pdf->SetFont('Times', 'I', 12);
    $pdf->SetTextColor($c_muted[0], $c_muted[1], $c_muted[2]);
    $pdf->SetXY(0, 118);
    $pdf->Cell(297, 7, 'Yang diselenggarakan pada tanggal ' . $tanggal_format, 0, 1, 'C');

    $y_ttd = 145;

    // F18: bila kolom ttd diisi, pakai nama/jabatan itu; jika kosong, teks lama persis.
    $ttd_nama_isi = trim((string) ($data['ttd_nama'] ?? ''));
    $ttd_jabatan_isi = trim((string) ($data['ttd_jabatan'] ?? ''));
    $pakai_ttd = ($ttd_nama_isi !== '');

    $pdf->SetFont('Times', '', 11);
    $pdf->SetTextColor($c_muted[0], $c_muted[1], $c_muted[2]);
    $pdf->SetXY(40, $y_ttd);
    $pdf->Cell(70, 6, $pakai_ttd ? 'Penandatangan' : 'Ketua Pelaksana', 0, 1, 'C');

    $pdf->SetDrawColor(203, 213, 225);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(50, $y_ttd + 20, 100, $y_ttd + 20);

    $pdf->SetFont('Times', 'B', 11);
    $pdf->SetTextColor($c_muted[0], $c_muted[1], $c_muted[2]);
    $pdf->SetXY(40, $y_ttd + 22);
    $pdf->Cell(70, 6, pdf_text($pakai_ttd ? strtoupper($ttd_nama_isi) : 'Panitia AcadeMeet'), 0, 1, 'C');
    if ($pakai_ttd && $ttd_jabatan_isi !== '') {
        $pdf->SetFont('Times', '', 9);
        $pdf->SetXY(40, $y_ttd + 28);
        $pdf->Cell(70, 5, pdf_text($ttd_jabatan_isi), 0, 1, 'C');
    }

    $pdf->SetFont('Times', '', 11);
    $pdf->SetTextColor($c_muted[0], $c_muted[1], $c_muted[2]);
    $pdf->SetXY(187, $y_ttd);
    $pdf->Cell(70, 6, 'Ketua Program Studi', 0, 1, 'C');

    $pdf->Line(197, $y_ttd + 20, 247, $y_ttd + 20);

    $pdf->SetFont('Times', 'B', 11);
    $pdf->SetTextColor($c_muted[0], $c_muted[1], $c_muted[2]);
    $pdf->SetXY(187, $y_ttd + 22);
    $pdf->Cell(70, 6, pdf_text($data['pemateri']), 0, 1, 'C');

    // F7: URL verifikasi keaslian sertifikat.
    // Tanpa QR (lib QR server-side belum ada); URL + kode dicetak sebagai teks.
    $base = trim(env_secret('ACADEMEET_BASE_URL'));
    $kode_tiket = (string) $data['kode_tiket'];
    if ($base !== '') {
        $url_verif = rtrim($base, '/') . '/verifikasi.php?kode=' . rawurlencode($kode_tiket);
    } else {
        $url_verif = 'verifikasi.php?kode=' . $kode_tiket;
        error_log('ACADEMEET_BASE_URL belum diset; URL verifikasi sertifikat tidak absolut.');
    }
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor($c_muted[0], $c_muted[1], $c_muted[2]);
    $pdf->SetXY(187, $y_ttd + 34);
    $pdf->Cell(70, 4, 'Verifikasi keaslian:', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetXY(160, $y_ttd + 38);
    $pdf->MultiCell(124, 3.5, pdf_text($url_verif), 0, 'C');

    ob_end_clean();
    $file_name = 'Sertifikat_' . (preg_replace('/[^A-Za-z0-9_-]+/', '_', $nama_mahasiswa) ?: 'peserta') . '.pdf';
    $pdf->Output(isset($_GET['inline']) ? 'I' : 'D', $file_name);
    exit();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview Sertifikat - AcadeMeet</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <?= theme_boot_script() ?>
    <link rel="stylesheet" href="style.css?v=2.13">
</head>
<body>
<?= theme_toggle_button('theme-toggle--floating') ?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card card-custom p-4 p-md-5 shadow-lg mb-4">
                <div class="text-center mb-4">
                    <div class="auth-brand-icon mx-auto mb-3">
                        <i class="bi bi-award-fill"></i>
                    </div>
                    <h1 class="h4 fw-bold text-dark">Sertifikat Siap Diunduh</h1>
                    <p class="text-muted small mb-0">Preview sertifikat kehadiran seminar</p>
                </div>

                <div class="certificate-paper bg-white rounded-3 p-3 p-md-5 mb-4 border shadow-sm" style="aspect-ratio: 1.414 / 1; position: relative;">
                    <div class="h-100 d-flex flex-column justify-content-between" style="font-family: 'Times New Roman', serif;">
                        <div class="text-center">
                            <div class="text-primary fw-bold mb-1 mb-md-2" style="font-size: clamp(0.8rem, 3.2vw, 1.5rem); letter-spacing: 0.1em;">SERTIFIKAT PENGHARGAAN</div>
                            <div class="text-warning fw-bold mb-3" style="color: #B45309 !important; font-size: clamp(0.55rem, 1.6vw, 0.85rem);">NOMOR: <?= e($data['kode_tiket']) ?>/ACDM/<?= e($tahun) ?></div>
                        </div>

                        <div class="text-center flex-grow-1 d-flex flex-column justify-content-center" style="min-height: 0;">
                            <p class="text-muted fst-italic mb-2 mb-md-3" style="font-size: clamp(0.6rem, 1.8vw, 1rem);">Diberikan dengan penuh rasa bangga kepada:</p>
                            <h2 class="fw-bold text-primary mb-2 text-break" style="font-size: clamp(1rem, 4.2vw, 2.2rem); letter-spacing: 0.02em; line-height: 1.15;"><?= e(strtoupper($nama_mahasiswa)) ?></h2>
                            <div class="mx-auto mb-2 mb-md-4" style="width: 40%; height: 2px; background: linear-gradient(90deg, transparent, var(--primary), transparent);"></div>

                            <p class="text-muted fst-italic mb-2" style="font-size: clamp(0.55rem, 1.9vw, 0.95rem);">Atas partisipasi dan kehadirannya sebagai Peserta dalam acara Seminar:</p>
                            <h3 class="fw-bold text-primary mb-2 text-break" style="font-size: clamp(0.7rem, 2.2vw, 1.1rem); line-height: 1.4;">"<?= e(strtoupper($data['judul'])) ?>"</h3>
                            <p class="text-muted fst-italic mb-0" style="font-size: clamp(0.55rem, 2vw, 0.95rem);">Yang diselenggarakan pada tanggal <?= e($tanggal_format) ?></p>
                        </div>

                        <div class="row text-center mt-3 mt-md-4 g-0">
                            <div class="col-6 border-end px-1 px-md-3">
                                <small class="text-muted d-block" style="font-size: clamp(0.5rem, 1.6vw, 0.85rem);">Ketua Pelaksana</small>
                                <div class="border-bottom fw-bold mt-2 mb-1" style="width: 60%; margin: 0.5rem auto;"></div>
                                <small class="text-muted" style="font-size: clamp(0.55rem, 1.6vw, 0.85rem);">Panitia AcadeMeet</small>
                            </div>
                            <div class="col-6 px-1 px-md-3">
                                <small class="text-muted d-block" style="font-size: clamp(0.55rem, 1.6vw, 0.85rem);">Ketua Program Studi</small>
                                <div class="border-bottom fw-bold mt-2 mb-1" style="width: 60%; margin: 0.5rem auto;"></div>
                                <small class="text-muted text-truncate d-block" style="font-size: clamp(0.55rem, 1.6vw, 0.85rem);"><?= e($data['pemateri']) ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                    <a href="sertifikat.php?id=<?= $id_seminar ?>&download=true" class="btn btn-primary px-4 px-md-5 shadow-sm">
                        <i class="bi bi-download me-2" aria-hidden="true"></i> Unduh Sertifikat (PDF)
                    </a>
                    <a href="sertifikat.php?id=<?= $id_seminar ?>&download=true&inline=1" target="_blank" rel="noopener" class="btn btn-light-primary px-4 px-md-5">
                        <i class="bi bi-eye me-2" aria-hidden="true"></i> Lihat di Peramban
                    </a>
                    <a href="verifikasi.php?kode=<?= e(urlencode($data['kode_tiket'])) ?>" target="_blank" rel="noopener" class="btn btn-light-primary px-4 px-md-5">
                        <i class="bi bi-patch-check me-2"></i> Verifikasi Publik
                    </a>
                    <a href="mahasiswa.php?page=tiket" class="btn btn-light px-4 px-md-5">
                        <i class="bi bi-arrow-left me-2"></i> Kembali
                    </a>
                </div>
            </div>

            <div class="card card-custom p-3">
                <div class="d-flex align-items-center">
                    <div class="bg-primary-light text-primary rounded-3 p-3 me-3">
                        <i class="bi bi-info-circle fs-4"></i>
                    </div>
                    <div>
                        <h2 class="fw-bold text-dark mb-1">Informasi Sertifikat</h2>
                        <ul class="small text-muted mb-0">
                            <li>Format: PDF A4 Landscape (297×210 mm)</li>
                            <li>Dapat diverifikasi via kode tiket: <strong class="font-monospace"><?= e($data['kode_tiket']) ?></strong></li>
                            <li>Pastikan data nama dan seminar sudah benar sebelum diunduh</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script><script src="theme.js?v=2.10" defer></script>
</body>
</html>
