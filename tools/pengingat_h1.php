<?php
/**
 * Skrip CLI pengingat H-1 (F6). HANYA CLI; tolak akses web.
 * Jalankan (uji manual): set env ACADEMEET_* lalu: php tools/pengingat_h1.php
 * Jadwal: lihat README (schtasks). Skrip aman dijalankan berulang pada hari yang sama.
 * Tanggal "besok" dihitung di zona Asia/Jakarta.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../koneksi.php';
require __DIR__ . '/../services.php';
require __DIR__ . '/../mailer.php';

date_default_timezone_set('Asia/Jakarta');
$besok = date('Y-m-d', strtotime('+1 day'));

$kirim = static function (string $ke, string $subjek, string $isi): bool {
    return kirim_email($ke, $subjek, $isi, 10);
};

$ringkas = proses_pengingat_h1($conn, $besok, $kirim, 50);

echo "Pengingat H-1 ($besok):\n";
echo "  terkirim : {$ringkas['terkirim']}\n";
echo "  gagal    : {$ringkas['gagal']}\n";
echo "  dilewati : {$ringkas['dilewati']}\n";
if (!empty($ringkas['dihentikan'])) {
    echo "  CATATAN  : dihentikan lebih awal (3 kegagalan berurutan; SMTP diduga mati)\n";
}

mysqli_close($conn);
