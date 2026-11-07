<?php
// Tes mandiri tanpa dependency. Jalankan: php tests/run.php
require __DIR__ . '/../bootstrap.php';

$failed = 0;
$check = static function (string $name, bool $ok): void {
    global $failed;
    if (!$ok) {
        $failed++;
        echo "FAIL: {$name}\n";
    } else {
        echo "ok:   {$name}\n";
    }
};

$check('e() meng-escape HTML', e('<script>"&\'') === '&lt;script&gt;&quot;&amp;&#039;');
$check('ticket_code() format benar', (bool) preg_match('/^ACD-[0-9A-F]{6}$/', ticket_code()));
$check('ticket_code() acak', ticket_code() !== ticket_code());
$check('password < 8 ditolak', password_ok('1234567') === false);
$check('password 8 diterima', password_ok('12345678') === true);
$check('excel_cell() menetralkan formula', excel_cell('=1+1') === "'=1+1");
$check('excel_cell() membiarkan teks biasa', excel_cell('Budi') === 'Budi');
$check('excel_cell() menangkap formula ber-spasi', excel_cell('  =1+1') === "'  =1+1");
$check('avatar lokal berupa data URI', str_starts_with(initials_avatar('Budi'), 'data:image/svg+xml;base64,'));
$check('placeholder lokal berupa data URI', str_starts_with(placeholder_data_uri('Acara'), 'data:image/svg+xml;base64,'));
$check('upload tanpa file mengembalikan null', upload_image(['error' => UPLOAD_ERR_NO_FILE], 'posters', 1000) === null);
$check('csrf token 64 hex', (bool) preg_match('/^[0-9a-f]{64}$/', csrf_token()));
$check('csrf_field berisi token', str_contains(csrf_field(), csrf_token()));

$_GET = ['p' => 1, 'q' => ''];
$check('list_page() default', list_page() === [1, 10, '']);
$check('pagination_html() punya tautan halaman', str_contains(pagination_html(25, 1, 10), 'p=2'));
$check('table_footer() menghitung rentang', str_contains(table_footer(25, 1, 10), 'Menampilkan 1–10 dari 25 data'));

$check('theme_boot_script menyetel data-bs-theme', str_contains(theme_boot_script(), 'data-bs-theme'));
$check('theme_boot_script berbaca localStorage', str_contains(theme_boot_script(), 'academeet-theme'));
$check('theme_toggle_button punya js-theme-toggle + aria-pressed', str_contains(theme_toggle_button(), 'js-theme-toggle') && str_contains(theme_toggle_button(), 'aria-pressed'));
$check('theme_toggle_button terima extraClass', str_contains(theme_toggle_button('theme-toggle--floating'), 'theme-toggle--floating'));
$check('theme_toggle_button pakai .theme-switch + knob', str_contains(theme_toggle_button(), 'theme-switch') && str_contains(theme_toggle_button(), 'theme-switch__knob'));
$check('theme_toggle_button punya ikon sun & moon', str_contains(theme_toggle_button(), 'bi-sun-fill') && str_contains(theme_toggle_button(), 'bi-moon-stars-fill'));
$check('theme_toggle_button tak pakai btn-glass', !str_contains(theme_toggle_button(), 'btn-glass'));

// parse_ini_bytes(): shorthand php.ini ke byte.
$check('parse_ini_bytes("512M") = 536870912', parse_ini_bytes('512M') === 512 * 1024 * 1024);
$check('parse_ini_bytes("2G") = 2147483648', parse_ini_bytes('2G') === 2 * 1024 * 1024 * 1024);
$check('parse_ini_bytes("-1") = -1', parse_ini_bytes('-1') === -1);
$check('parse_ini_bytes("128K") = 131072', parse_ini_bytes('128K') === 128 * 1024);

// resize_image_file(): buat gambar 2752x1536, resize ke maks 1000 lebar.
if (extension_loaded('gd') && function_exists('imagecreatefromjpeg')) {
    $tmpSrc = sys_get_temp_dir() . '/academeet_test_src.jpg';
    $tmpDst = sys_get_temp_dir() . '/academeet_test_dst.jpg';
    $im = imagecreatetruecolor(2752, 1536);
    imagefilledrectangle($im, 0, 0, 2752, 1536, imagecolorallocate($im, 40, 90, 200));
    imagejpeg($im, $tmpSrc, 95);
    imagedestroy($im);

    $ok = resize_image_file($tmpSrc, $tmpDst, 1000, 0, 80);
    $check('resize_image_file() berhasil', $ok === true);
    if ($ok && is_file($tmpDst)) {
        $dim = @getimagesize($tmpDst);
        $check('resize_image_file() lebar <= 1000', $dim !== false && $dim[0] <= 1000);
        $check('resize_image_file() aspek rasio terjaga', $dim !== false && abs(($dim[0] / $dim[1]) - (2752 / 1536)) < 0.02);
        $check('resize_image_file() hasil lebih kecil', filesize($tmpDst) < filesize($tmpSrc));
    }
    @unlink($tmpSrc);
    @unlink($tmpDst);
} else {
    echo "skip: resize_image_file() (ekstensi GD tidak tersedia)\n";
}

echo $failed === 0 ? "\nSemua tes lulus.\n" : "\n{$failed} tes gagal.\n";
exit($failed === 0 ? 0 : 1);
