<?php
/**
 * Optimizer upload (CLI saja). Resize + re-encode gambar yang sudah ada
 * TANPA mengubah nama/ekstensi file (nama dipakai di database).
 * Hanya menimpa bila hasil lebih kecil.
 *
 * Pakai:
 *   php tools/optimize_uploads.php            (dry-run, hanya laporan)
 *   php tools/optimize_uploads.php --apply    (menulis perubahan)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "Hanya bisa dijalankan dari CLI.\n");
    exit(1);
}

require __DIR__ . '/../bootstrap.php';

@ini_set('memory_limit', '512M');

$apply = in_array('--apply', $argv, true);
$dry = !$apply;

$dirs = [
    'posters'  => [1000, 0],
    'profiles' => [400, 400],
    'pemateri' => [600, 600],
];

$totalBefore = 0;
$totalAfter = 0;
$changed = 0;
$skipped = 0;
$failed = 0;

echo $dry ? "MODE: DRY-RUN (tidak menulis)\n" : "MODE: APPLY (menulis bila lebih kecil)\n";
echo str_repeat('=', 92) . "\n";
printf("%-42s %-18s %-18s %10s %10s\n", 'FILE', 'DIMENSI SEBELUM', 'DIMENSI SESUDAH', 'SEBELUM', 'SESUDAH');
echo str_repeat('-', 92) . "\n";

foreach ($dirs as $subdir => [$maxW, $maxH]) {
    $base = __DIR__ . '/../uploads/' . $subdir;
    if (!is_dir($base)) {
        continue;
    }
    foreach (glob($base . '/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE) as $path) {
        $sizeBefore = filesize($path);
        $totalBefore += $sizeBefore;
        $info = @getimagesize($path);
        if (!$info) {
            $failed++;
            printf("%-42s  [BUKAN GAMBAR VALID — dilewati]\n", basename($path));
            $totalAfter += $sizeBefore;
            continue;
        }
        $dimBefore = $info[0] . 'x' . $info[1];

        $tmp = $path . '.opt.tmp';
        $ok = resize_image_file($path, $tmp, $maxW, $maxH, 80);

        if (!$ok || !is_file($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);
            $failed++;
            printf("%-42s %-18s  [gagal resize, file asli dipertahankan]\n", basename($path), $dimBefore);
            $totalAfter += $sizeBefore;
            continue;
        }

        $sizeAfter = filesize($tmp);
        $dimInfo = @getimagesize($tmp);
        $dimAfter = $dimInfo ? $dimInfo[0] . 'x' . $dimInfo[1] : '?';

        if ($sizeAfter < $sizeBefore) {
            $changed++;
            printf("%-42s %-18s %-18s %10s %10s  -%d%%\n",
                basename($path), $dimBefore, $dimAfter,
                number_format($sizeBefore), number_format($sizeAfter),
                (int) round((1 - $sizeAfter / $sizeBefore) * 100));
            if ($apply) {
                if (!@rename($tmp, $path)) {
                    @unlink($tmp);
                    $failed++;
                    fwrite(STDERR, "  !! gagal menimpa " . basename($path) . "\n");
                    $totalAfter += $sizeBefore;
                    continue;
                }
            } else {
                @unlink($tmp);
            }
            $totalAfter += $sizeAfter;
        } else {
            $skipped++;
            printf("%-42s %-18s %-18s %10s %10s  [tak lebih kecil, dilewati]\n",
                basename($path), $dimBefore, $dimAfter,
                number_format($sizeBefore), number_format($sizeAfter));
            @unlink($tmp);
            $totalAfter += $sizeBefore;
        }
    }
}

echo str_repeat('=', 92) . "\n";
printf("Total sebelum : %s bytes (%.2f MB)\n", number_format($totalBefore), $totalBefore / 1048576);
printf("Total sesudah : %s bytes (%.2f MB)  [%s]\n", number_format($totalAfter), $totalAfter / 1048576, $dry ? 'estimasi dry-run' : 'hasil apply');
printf("Penghematan   : %s bytes (%.2f MB, %d%%)\n",
    number_format($totalBefore - $totalAfter), ($totalBefore - $totalAfter) / 1048576,
    $totalBefore > 0 ? (int) round((1 - $totalAfter / $totalBefore) * 100) : 0);
printf("File diubah/akan diubah: %d | dilewati: %d | gagal: %d\n", $changed, $skipped, $failed);
if ($dry) {
    echo "\nJalankan dengan --apply untuk menerapkan perubahan.\n";
}
