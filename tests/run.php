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

// idle_timeout_seconds(): default admin 3600, mahasiswa 14400; role tak dikenal = mahasiswa.
$check('idle_timeout_seconds() admin default 3600', idle_timeout_seconds('admin') === 3600);
$check('idle_timeout_seconds() mahasiswa default 14400', idle_timeout_seconds('mahasiswa') === 14400);
$check('idle_timeout_seconds() role tak dikenal = mahasiswa', idle_timeout_seconds('dosen') === 14400);

// sesi_kedaluwarsa(): null selalu kedaluwarsa; batas tepat belum, lewat sedikit kedaluwarsa.
$check('sesi_kedaluwarsa() null = kedaluwarsa', sesi_kedaluwarsa(null, 1000, 900) === true);
$check('sesi_kedaluwarsa() tepat di batas = belum', sesi_kedaluwarsa(100, 1000, 900) === false);
$check('sesi_kedaluwarsa() lewat batas = kedaluwarsa', sesi_kedaluwarsa(100, 1001, 900) === true);
$check('sesi_kedaluwarsa() baru aktif = belum', sesi_kedaluwarsa(999, 1000, 900) === false);

// throttle_key(): SHA-256 dari "scope|nilai" (lowercase + trim), 64 hex.
$check('throttle_key() 64 hex', (bool) preg_match('/^[0-9a-f]{64}$/', throttle_key('login_ep', 'A@B.com')));
$check('throttle_key() lowercase sama', throttle_key('login_ep', 'A@B.com') === throttle_key('login_ep', 'a@b.com'));
$check('throttle_key() scope beda -> hash beda', throttle_key('login_ep', 'a@b.com') !== throttle_key('login_ip', 'a@b.com'));
$check('throttle_key() nilai beda -> hash beda', throttle_key('login_ep', 'a@b.com') !== throttle_key('login_ep', 'c@d.com'));

// isi_email_reset(): murni; menyusun tautan dari base URL + token.
require __DIR__ . '/../mailer.php';
$isiEmail = isi_email_reset('http://localhost/academeet', str_repeat('a', 64));
$check('isi_email_reset() memuat tautan reset', str_contains($isiEmail, 'reset_password.php?token=' . str_repeat('a', 64)));
$check('isi_email_reset() tak dobel slash', str_contains($isiEmail, 'http://localhost/academeet/reset_password.php'));
$check('isi_email_reset() sebut masa berlaku', str_contains($isiEmail, '30 menit'));
$isiEmailSlash = isi_email_reset('http://localhost/academeet/', 'tok');
$check('isi_email_reset() base trailing slash tetap rapi', str_contains($isiEmailSlash, 'academeet/reset_password.php?token=tok'));

// env_secret(): env asli menang; fallback ke file (diuji via file sementara).
$tmpSecret = sys_get_temp_dir() . '/academeet_secret_test.ini';
file_put_contents($tmpSecret, "ACADEMEET_TEST_KEY=nilai-file\n# komentar\nACADEMEET_TEST_EMPTY=\n");
$oldSecretFile = getenv('ACADEMEET_SECRETS_FILE');
putenv('ACADEMEET_SECRETS_FILE=' . $tmpSecret);
$check('env_secret() baca dari file', env_secret('ACADEMEET_TEST_KEY') === 'nilai-file');
$check('env_secret() missing = default', env_secret('ACADEMEET_TIDAK_ADA', 'def') === 'def');
putenv('ACADEMEET_TEST_ENV=env-menang');
$check('env_secret() env asli menang atas file', env_secret('ACADEMEET_TEST_ENV') === 'env-menang');
if ($oldSecretFile === false) { putenv('ACADEMEET_SECRETS_FILE'); } else { putenv('ACADEMEET_SECRETS_FILE=' . $oldSecretFile); }
putenv('ACADEMEET_TEST_ENV');
@unlink($tmpSecret);

// checkin_window_error(): kemarin boleh, hari ini boleh, besok ditolak, format salah ditolak.
$hariIni = '2026-11-07';
$check('checkin_window_error() kemarin = boleh', checkin_window_error('2026-11-06', $hariIni) === null);
$check('checkin_window_error() hari ini = boleh', checkin_window_error($hariIni, $hariIni) === null);
$check('checkin_window_error() besok = ditolak', checkin_window_error('2026-11-08', $hariIni) !== null);
$check('checkin_window_error() besok menyebut tanggal', str_contains((string) checkin_window_error('2026-11-08', $hariIni), '08 Nov 2026'));
$check('checkin_window_error() format salah = ditolak', checkin_window_error('besok', $hariIni) !== null);

// seminar_status_efektif(): Selesai bila status='Selesai' ATAU tanggal < hari ini.
$check('seminar_status_efektif() status Selesai', seminar_status_efektif('Selesai', '2026-12-01', $hariIni) === 'Selesai');
$check('seminar_status_efektif() tanggal lewat', seminar_status_efektif('Aktif', '2026-11-06', $hariIni) === 'Selesai');
$check('seminar_status_efektif() hari ini tetap Aktif', seminar_status_efektif('Aktif', $hariIni, $hariIni) === 'Aktif');
$check('seminar_status_efektif() besok Aktif', seminar_status_efektif('Aktif', '2026-11-08', $hariIni) === 'Aktif');
$check('seminar_status_efektif() tanggal invalid tetap Aktif', seminar_status_efektif('Aktif', 'besok', $hariIni) === 'Aktif');

// validasi_data_diri()
$check('validasi_data_diri() valid', validasi_data_diri('Budi', '123', 'Teknik Informatika', 'budi@kampus.ac.id') === '');
$check('validasi_data_diri() email salah', validasi_data_diri('Budi', '123', 'Teknik Informatika', 'bukan-email') !== '');
$check('validasi_data_diri() prodi tak dikenal ditolak', validasi_data_diri('Budi', '123', 'Astronomi', 'budi@kampus.ac.id') !== '');
$check('validasi_data_diri() prodi lama dipertahankan', validasi_data_diri('Budi', '123', 'Astronomi Lama', 'budi@kampus.ac.id', ['Astronomi Lama']) === '');
$check('validasi_data_diri() nama > 100 ditolak', validasi_data_diri(str_repeat('a', 101), '123', 'Teknik Informatika', 'budi@kampus.ac.id') !== '');
$check('validasi_data_diri() nama 100 diterima', validasi_data_diri(str_repeat('a', 100), '123', 'Teknik Informatika', 'budi@kampus.ac.id') === '');
$check('validasi_data_diri() nim > 20 ditolak', validasi_data_diri('Budi', str_repeat('9', 21), 'Teknik Informatika', 'budi@kampus.ac.id') !== '');
$check('validasi_data_diri() email > 100 ditolak', validasi_data_diri('Budi', '123', 'Teknik Informatika', str_repeat('a', 95) . '@x.com') !== '');
$check('prodi_terdaftar() non-kosong', count(prodi_terdaftar()) >= 4);

// security_headers()
$sh_http = security_headers(false);
$sh_https = security_headers(true);
$check('security_headers() nosniff', ($sh_http['X-Content-Type-Options'] ?? '') === 'nosniff');
$check('security_headers() X-Frame-Options SAMEORIGIN', ($sh_http['X-Frame-Options'] ?? '') === 'SAMEORIGIN');
$check('security_headers() HSTS absen tanpa HTTPS', !isset($sh_http['Strict-Transport-Security']));
$check('security_headers() HSTS ada saat HTTPS', isset($sh_https['Strict-Transport-Security']));
$check('security_headers() CSP frame-ancestors', str_contains($sh_http['Content-Security-Policy'] ?? '', "frame-ancestors 'self'"));
$check('security_headers() CSP object-src none', str_contains($sh_http['Content-Security-Policy'] ?? '', "object-src 'none'"));
$check('security_headers() CSP punya host CDN', str_contains($sh_http['Content-Security-Policy'] ?? '', 'https://cdn.jsdelivr.net'));
$check('excel_cell() menetralkan formula', excel_cell('=1+1') === "'=1+1");
$check('excel_cell() membiarkan teks biasa', excel_cell('Budi') === 'Budi');
$check('excel_cell() menangkap formula ber-spasi', excel_cell('  =1+1') === "'  =1+1");
$check('avatar lokal berupa data URI', str_starts_with(initials_avatar('Budi'), 'data:image/svg+xml;base64,'));
$check('placeholder lokal berupa data URI', str_starts_with(placeholder_data_uri('Acara'), 'data:image/svg+xml;base64,'));
$check('upload tanpa file mengembalikan null', upload_image(['error' => UPLOAD_ERR_NO_FILE], 'posters', 1000) === null);
$check('csrf token 64 hex', (bool) preg_match('/^[0-9a-f]{64}$/', csrf_token()));
$check('csrf_field berisi token', str_contains(csrf_field(), csrf_token()));

// --- Export (P4): helper murni xlsx/csv ---
$exHeader = ['No.', 'NIM', 'Nama', 'Prodi', 'Kode Tiket', 'Status'];
$exBaris = [
    [1, '0123456', 'Budi & <Sari>', 'Teknik "A"', 'ACD-1A2B3C', 'Hadir'],
    [2, '=cmd|calc', '+1', '-1', '@SUM', "koma,kutip\ndanbaris"],
    [3, '0789', 'Émile 😀', 'D3', 'ACD-FFFFFF', 'Belum Hadir'],
];

$xslxPath = xlsx_bangun($exHeader, $exBaris);
$check('xlsx_bangun() menghasilkan file', is_file($xslxPath));
$zipChk = new ZipArchive();
$check('xlsx_bangun() file ZIP valid', $zipChk->open($xslxPath) === true);
$need = ['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml', 'xl/styles.xml'];
$allParts = true;
foreach ($need as $part) {
    if ($zipChk->locateName($part) === false) { $allParts = false; }
}
$check('xlsx_bangun() semua bagian wajib ada', $allParts);
$domOk = true;
foreach ($need as $part) {
    $xml = $zipChk->getFromName($part);
    $d = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    if (!@$d->loadXML($xml)) { $domOk = false; }
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
}
$check('xlsx_bangun() tiap XML well-formed', $domOk);
$sheetSrc = $zipChk->getFromName('xl/worksheets/sheet1.xml');
$zipChk->close();
$check('xlsx_bangun() NIM nol depan utuh sebagai teks', str_contains($sheetSrc, '<t xml:space="preserve">0123456</t>'));
$check('xlsx_bangun() formula =cmd dinetralkan', str_contains($sheetSrc, "<t xml:space=\"preserve\">&apos;=cmd|calc</t>"));
$check('xlsx_bangun() formula +1 dinetralkan', str_contains($sheetSrc, "<t xml:space=\"preserve\">&apos;+1</t>"));
$check('xlsx_bangun() formula -1 dinetralkan', str_contains($sheetSrc, "<t xml:space=\"preserve\">&apos;-1</t>"));
$check('xlsx_bangun() formula @SUM dinetralkan', str_contains($sheetSrc, "<t xml:space=\"preserve\">&apos;@SUM</t>"));
$check('xlsx_bangun() & < > " di-escape', str_contains($sheetSrc, 'Budi &amp; &lt;Sari&gt;'));
$check('xlsx_bangun() emoji aman (UTF-8 utuh)', str_contains($sheetSrc, 'Émile 😀'));
$check('xlsx_bangun() baris header + 3 data', substr_count($sheetSrc, '<row ') === 4);
$check('xlsx_bangun() frozen pane dipasang', str_contains($sheetSrc, 'state="frozen"'));
$check('xlsx_bangun() style header tebal dipakai (s="1")', str_contains($sheetSrc, 's="1"'));
// Karakter kontrol dibuang.
$ctrlPath = xlsx_bangun(['A'], [["a\x01b\x1Fc"]]);
$cz = new ZipArchive(); $cz->open($ctrlPath);
$ctrlSheet = $cz->getFromName('xl/worksheets/sheet1.xml'); $cz->close();
$check('xlsx_bangun() karakter kontrol dibuang', str_contains($ctrlSheet, '<t xml:space="preserve">abc</t>'));
@unlink($ctrlPath);
@unlink($xslxPath);

$csvPath = csv_bangun($exHeader, $exBaris);
$check('csv_bangun() menghasilkan file', is_file($csvPath));
$csvRaw = file_get_contents($csvPath);
$check('csv_bangun() diawali BOM UTF-8', str_starts_with($csvRaw, "\xEF\xBB\xBF"));
$csvBody = substr($csvRaw, 3);
$fh = fopen($csvPath, 'r');
fgetcsv($fh, 0, ','); // lewati BOM/header (BOM nempel di header, dibaca ulang di bawah)
rewind($fh);
$csvRows = [];
while (($r = fgetcsv($fh, 0, ',')) !== false) { $csvRows[] = $r; }
fclose($fh);
$check('csv_bangun() jumlah baris = header + 3', count($csvRows) === 4);
// Fputcsv membungkus sel ber-koma/kutip/newline; parse balik harus benar.
$row2 = $csvRows[2];
$check('csv_bangun() sel ber-koma/kutip/newline utuh', $row2[5] === "koma,kutip\ndanbaris");
$check('csv_bangun() nilai ber-koma tidak pecah kolom', count($row2) === 6);
$check('csv_bangun() formula dinetralkan', $csvRows[2][1] === "'=cmd|calc");
@unlink($csvPath);

// --- Chart (F12): helper murni svg_bar_chart ---
$svgKosong = svg_bar_chart([], ['judul' => 'A']);
$check('svg_bar_chart() data kosong -> "Belum ada data"', str_contains($svgKosong, 'Belum ada data'));
$check('svg_bar_chart() data kosong tetap SVG well-formed', (bool) @simplexml_load_string($svgKosong));
$svgNol = svg_bar_chart([['label' => 'A', 'nilai' => 0], ['label' => 'B', 'nilai' => 0]], ['judul' => 'Z']);
$check('svg_bar_chart() semua nol tidak membagi nol (well-formed)', (bool) @simplexml_load_string($svgNol));
$check('svg_bar_chart() semua nol tetap SVG valid', str_contains($svgNol, '<svg'));
$svgOk = svg_bar_chart([['label' => 'Hadir', 'nilai' => 5], ['label' => '<X>&', 'nilai' => 10]], ['judul' => 'Judul <chart> & me', 'warna' => 'var(--bs-primary)']);
$check('svg_bar_chart() output well-formed', (bool) @simplexml_load_string($svgOk));
$check('svg_bar_chart() label di-escape', str_contains($svgOk, '&lt;X&gt;&amp;'));
$check('svg_bar_chart() judul di-escape', str_contains($svgOk, 'Judul &lt;chart&gt; &amp; me'));
$check('svg_bar_chart() punya role=img', str_contains($svgOk, 'role="img"'));
$check('svg_bar_chart() punya <title>', str_contains($svgOk, '<title'));
$check('svg_bar_chart() warna dari token tema', str_contains($svgOk, 'var(--bs-primary)'));
$panjangLbl = str_repeat('judul seminar sangat panjang ', 5);
$svgPotong = svg_bar_chart([['label' => $panjangLbl, 'nilai' => 1]]);
$check('svg_bar_chart() label panjang dipotong (… muncul)', str_contains($svgPotong, '…'));
$svgBesar = svg_bar_chart([['label' => 'A', 'nilai' => 999999]], ['judul' => 'B']);
$check('svg_bar_chart() angka besar tetap well-formed', (bool) @simplexml_load_string($svgBesar));
// Angka besar tidak keluar kanvas -> koordinat y positif.
preg_match('/<rect x="[0-9.]+" y="(-?[0-9.]+)"/', $svgBesar, $m);
$check('svg_bar_chart() bar angka besar tidak keluar kanvas (y>=0)', isset($m[1]) && (float) $m[1] >= 0);
$detKosong = svg_chart_details([]);
$check('svg_chart_details() data kosong -> pesan', str_contains($detKosong, 'Belum ada data'));
$detOk = svg_chart_details([['label' => '<a>', 'nilai' => 3, 'teks' => '3']]);
$check('svg_chart_details() label di-escape', str_contains($detOk, '&lt;a&gt;'));
$check('svg_chart_details() pakai <details>', str_contains($detOk, '<details'));

// --- Email (F6): helper murni, TANPA pengiriman ---
$semEmail = [
    'judul' => 'Workshop PHP', 'pemateri' => 'Dr. Budi', 'tanggal' => '2026-11-09',
    'jam_mulai' => '09:00:00', 'jam_selesai' => '11:00:00', 'lokasi' => 'Aula', 'link_online' => 'https://meet.example.com/x',
];
[$subjK, $bodyK] = isi_email_konfirmasi('https://app.example.com', 'Budi', $semEmail, 'ACD-1A2B3C');
$check('konfirmasi: subjek tanpa CR/LF', !str_contains($subjK, "\r") && !str_contains($subjK, "\n"));
$check('konfirmasi: memuat kode tiket', str_contains($bodyK, 'ACD-1A2B3C'));
$check('konfirmasi: TIDAK memuat link online', !str_contains($bodyK, 'meet.example.com'));
$check('konfirmasi: tautan app muncul bila BASE_URL ada', str_contains($bodyK, 'https://app.example.com/mahasiswa.php?page=tiket'));
$check('konfirmasi: memuat judul & lokasi', str_contains($bodyK, 'Workshop PHP') && str_contains($bodyK, 'Aula'));
[, $bodyNoBase] = isi_email_konfirmasi('', 'Budi', $semEmail, 'ACD-1A2B3C');
$check('konfirmasi: tanpa BASE_URL -> tautan dihilangkan', !str_contains($bodyNoBase, 'http'));
[$subjH, $bodyH] = isi_email_h1('https://app.example.com', 'Budi', $semEmail);
$check('H-1: TIDAK memuat kode tiket', !str_contains($bodyH, 'ACD-'));
$check('H-1: TIDAK memuat link online', !str_contains($bodyH, 'meet.example.com'));
$check('H-1: subjek tanpa CR/LF', !str_contains($subjH, "\r") && !str_contains($subjH, "\n"));
$check('H-1: tautan app muncul', str_contains($bodyH, 'https://app.example.com/mahasiswa.php?page=tiket'));
$semInjeksi = $semEmail;
$semInjeksi['judul'] = "Judul\r\nBcc: evil@example.com";
[$subjInj, $bodyInj] = isi_email_konfirmasi('https://app.example.com', "Nama\r\nBcc: evil@example.com", $semInjeksi, 'ACD-1A2B3C');
$check('konfirmasi: subjek ber-CR/LF disanitasi (anti header injection)', !str_contains($subjInj, "\n") && !str_contains($subjInj, "\r"));
$check('konfirmasi: nama ber-CR/LF disanitasi di isi', !str_contains(explode("\n", $bodyInj)[0], "\r"));
$check('bersihkan_header buang CR/LF', bersihkan_header("a\r\nb") === 'a b');
$semNonLatin = $semEmail;
$semNonLatin['judul'] = 'Seminar Émoji 😀 & Sains';
[$subjNL, $bodyNL] = isi_email_konfirmasi('', 'Ünïcode', $semNonLatin, 'ACD-FFFFFF');
$check('konfirmasi: teks non-Latin utuh', str_contains($bodyNL, 'Émoji 😀') && str_contains($bodyNL, 'Ünïcode'));




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

// 1. kategori_seminar()
$kats = kategori_seminar();
$check('kategori_seminar() memuat 6 nilai', count($kats) === 6 && $kats === ['Teknologi', 'Karier', 'Riset', 'Soft Skill', 'Kewirausahaan', 'Umum']);
[$errKat, $dataKat] = validasi_detail_seminar(['kategori' => 'Luar Daftar']);
$check('kategori di luar daftar ditolak', $errKat !== '');

// 2. validasi_detail_seminar()
[$errJam1] = validasi_detail_seminar(['jam_mulai' => '9:00']);
$check('validasi_detail_seminar jam_mulai 9:00 ditolak', $errJam1 !== '');
[$errJam2] = validasi_detail_seminar(['jam_mulai' => '25:00']);
$check('validasi_detail_seminar jam_mulai 25:00 ditolak', $errJam2 !== '');
[$errJamS1] = validasi_detail_seminar(['jam_selesai' => '10:00']);
$check('validasi_detail_seminar jam_selesai tanpa jam_mulai ditolak', $errJamS1 !== '');
[$errJamS2] = validasi_detail_seminar(['jam_mulai' => '08:00', 'jam_selesai' => '08:00']);
$check('validasi_detail_seminar jam_selesai == jam_mulai ditolak', $errJamS2 !== '');
[$errJamS3] = validasi_detail_seminar(['jam_mulai' => '10:00', 'jam_selesai' => '08:00']);
$check('validasi_detail_seminar jam_selesai < jam_mulai ditolak', $errJamS3 !== '');
[$errJamOk, $dataJamOk] = validasi_detail_seminar(['jam_mulai' => '08:00', 'jam_selesai' => '10:00']);
$check('validasi_detail_seminar 08:00+10:00 diterima', $errJamOk === '' && $dataJamOk['jam_mulai'] === '08:00:00' && $dataJamOk['jam_selesai'] === '10:00:00');
[$errLok] = validasi_detail_seminar(['lokasi' => str_repeat('a', 151)]);
$check('validasi_detail_seminar lokasi 151 karakter ditolak', $errLok !== '');
[$errDesk1] = validasi_detail_seminar(['deskripsi' => str_repeat('a', 2001)]);
$check('validasi_detail_seminar deskripsi 2001 karakter ditolak', $errDesk1 !== '');
[$errDesk2] = validasi_detail_seminar(['deskripsi' => str_repeat('🚀', 1500)]);
$check('validasi_detail_seminar deskripsi emoji 1500 karakter diterima', $errDesk2 === '');

// 3. validasi_link_online()
$check('validasi_link_online javascript ditolak', validasi_link_online('javascript:alert(1)') === false);
$check('validasi_link_online data uri ditolak', validasi_link_online('data:text/html,x') === false);
$check('validasi_link_online ftp ditolak', validasi_link_online('ftp://x.com') === false);
$check('validasi_link_online tanpa host ditolak', validasi_link_online('http:///tanpa-host') === false);
$check('validasi_link_online userinfo ditolak', validasi_link_online('http://user:pass@host.com') === false);
$check('validasi_link_online spasi ditolak', validasi_link_online('http://host.com/spasi ada') === false);
$check('validasi_link_online newline ditolak', validasi_link_online("http://x.com\n") === false);
$check('validasi_link_online https valid diterima', validasi_link_online('https://example.com/x?y=1') === true);
$check('validasi_link_online http local diterima', validasi_link_online('http://host.local') === true);

// 4. like_escape()
$check('like_escape 50%_a\\b', like_escape('50%_a\b') === '50\%\_a\\\\b');
$check('like_escape biasa', like_escape('biasa') === 'biasa');

// 5. normalisasi_filter_katalog()
$f1 = normalisasi_filter_katalog(['kat' => 'NonExist', 'p' => 'abc', 'sedia' => 'invalid']);
$check('normalisasi_filter_katalog invalid ke default', $f1['kat'] === '' && $f1['p'] === 1 && $f1['sedia'] === 'semua');
$f2 = normalisasi_filter_katalog(['dari' => '2026-13-40', 'sampai' => 'bukan-tanggal']);
$check('normalisasi_filter_katalog tanggal invalid ke empty', $f2['dari'] === '' && $f2['sampai'] === '');
$f3 = normalisasi_filter_katalog(['dari' => '2026-12-01', 'sampai' => '2026-11-01']);
$check('normalisasi_filter_katalog dari > sampai ditukar', $f3['dari'] === '2026-11-01' && $f3['sampai'] === '2026-12-01');
$f4 = normalisasi_filter_katalog(['q' => '  tes  ', 'kat' => 'Teknologi', 'dari' => '2026-11-01', 'sampai' => '2026-11-10', 'sedia' => 'tersedia', 'p' => '2']);
$check('normalisasi_filter_katalog kombinasi valid', $f4['q'] === 'tes' && $f4['kat'] === 'Teknologi' && $f4['dari'] === '2026-11-01' && $f4['sampai'] === '2026-11-10' && $f4['sedia'] === 'tersedia' && $f4['p'] === 2);

// 6. ICS helpers & buat_ics()
$acaraTes = [
    'id_registrasi' => 123,
    'judul' => 'A, B; C',
    'pemateri' => 'Dr. Alpha',
    'kategori' => 'Teknologi',
    'deskripsi' => 'Deskripsi singkat',
    'lokasi' => 'Ruang 101',
    'link_online' => 'https://example.com/zoom',
    'tanggal' => '2026-11-15',
    'jam_mulai' => '08:00:00',
    'jam_selesai' => null,
];
$ics1 = buat_ics($acaraTes, '2026-11-08 10:00:00');
$check('ICS semua baris diakhiri CRLF', (bool) preg_match('/^([^\r\n]*\r\n)+$/s', $ics1) && !str_contains(str_replace("\r\n", '', $ics1), "\n"));
$check('ICS escape koma/titik-koma', str_contains($ics1, 'SUMMARY:A\, B\; C'));

$acaraPanjang = [
    'id_registrasi' => 456,
    'judul' => 'Judul Seminar Sangat Panjang Sekali Dengan Huruf UTF-8 🔥 Berisi Emoji Dan Karakter Multibyte Yang Melebihi Batas 75 Oktet Per Baris Fisik',
    'tanggal' => '2026-11-20',
];
$icsPanjang = buat_ics($acaraPanjang, '2026-11-08 10:00:00');
$barisFisik = explode("\r\n", rtrim($icsPanjang, "\r\n"));
$pelipatanOk = true;
foreach ($barisFisik as $b) {
    if (strlen($b) > 75) {
        $pelipatanOk = false;
        break;
    }
}
$check('ICS pelipatan 75 oktet per baris fisik', $pelipatanOk);

// Lipatan tidak boleh memotong karakter UTF-8 di tengah maupun loop tak maju.
// Isi 74 byte ASCII lalu emoji 4-byte yang melewati batas 75.
$acaraBatas = ['id_registrasi' => 1, 'judul' => str_repeat('a', 66) . '🔥🔥🔥🔥🔥🔥🔥🔥', 'tanggal' => '2026-11-20'];
$icsBatas = buat_ics($acaraBatas, '2026-11-08 10:00:00');
$check('ICS lipatan batas UTF-8 tetap UTF-8 valid', mb_check_encoding($icsBatas, 'UTF-8'));
$cekBaris = true;
foreach (explode("\r\n", rtrim($icsBatas, "\r\n")) as $b) { if (strlen($b) > 75) { $cekBaris = false; break; } }
$check('ICS lipatan batas UTF-8 tiap baris <=75 oktet', $cekBaris);

$check('ICS acara berjam start/end/vtimezone/valarm', str_contains($ics1, 'DTSTART;TZID=Asia/Jakarta:20261115T080000') && str_contains($ics1, 'DTEND;TZID=Asia/Jakarta:20261115T100000') && str_contains($ics1, 'BEGIN:VTIMEZONE') && str_contains($ics1, 'BEGIN:VALARM'));

$acaraDenganSelesai = $acaraTes;
$acaraDenganSelesai['jam_selesai'] = '09:30:00';
$icsSelesai = buat_ics($acaraDenganSelesai, '2026-11-08 10:00:00');
$check('ICS acara berjam dengan jam_selesai 09:30:00', str_contains($icsSelesai, 'DTEND;TZID=Asia/Jakarta:20261115T093000'));

$acaraSeharian = [
    'id_registrasi' => 789,
    'judul' => 'Workshop Seharian',
    'tanggal' => '2026-11-25',
    'jam_mulai' => null,
];
$icsSeharian = buat_ics($acaraSeharian, '2026-11-08 10:00:00');
$check('ICS acara seharian DATE format tanpa timezone & alarm', str_contains($icsSeharian, 'DTSTART;VALUE=DATE:20261125') && str_contains($icsSeharian, 'DTEND;VALUE=DATE:20261126') && !str_contains($icsSeharian, 'BEGIN:VTIMEZONE') && !str_contains($icsSeharian, 'BEGIN:VALARM'));

$acaraKodeTiket = $acaraTes;
$acaraKodeTiket['kode_tiket'] = 'ACD-123456';
$icsKode = buat_ics($acaraKodeTiket, '2026-11-08 10:00:00');
$check('ICS tidak memuat kode tiket', !str_contains($icsKode, 'ACD-123456'));

$icsUid1 = buat_ics($acaraTes, '2026-11-08 10:00:00');
$icsUid2 = buat_ics($acaraTes, '2026-11-08 10:00:00');
$check('ICS UID stabil', str_contains($icsUid1, 'UID:registrasi-123@academeet') && $icsUid1 === $icsUid2);

echo $failed === 0 ? "\nSemua tes lulus.\n" : "\n{$failed} tes gagal.\n";
exit($failed === 0 ? 0 : 1);
