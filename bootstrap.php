<?php
// Fondasi keamanan & helper bersama. Semua halaman wajib require file ini paling awal.
date_default_timezone_set('Asia/Jakarta');

/**
 * Timeout tidak aktif (detik) per role. Admin 60 menit, panitia 120 menit,
 * mahasiswa 240 menit. Bisa dioverride lewat env ACADEMEET_IDLE_ADMIN_MIN /
 * ACADEMEET_IDLE_PANITIA_MIN / ACADEMEET_IDLE_USER_MIN (untuk pengujian).
 * Role tak dikenal memakai batas mahasiswa.
 */
function idle_timeout_seconds(string $role): int
{
    if ($role === 'admin') {
        $envName = 'ACADEMEET_IDLE_ADMIN_MIN';
        $default = 60;
    } elseif ($role === 'panitia') {
        $envName = 'ACADEMEET_IDLE_PANITIA_MIN';
        $default = 120;
    } else {
        $envName = 'ACADEMEET_IDLE_USER_MIN';
        $default = 240;
    }
    $env = getenv($envName);
    $menit = ($env !== false && $env !== '' && is_numeric($env)) ? (int) $env : $default;
    return max(1, $menit) * 60;
}

/** Sesi kedaluwarsa bila $lastActive null atau jarak ($now - $lastActive) melebihi $timeout. */
function sesi_kedaluwarsa(?int $lastActive, int $now, int $timeout): bool
{
    if ($lastActive === null) {
        return true;
    }
    return ($now - $lastActive) > $timeout;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Simpan sesi minimal selama timeout terpanjang (admin bisa di-override naik).
    $gcMax = max(idle_timeout_seconds('admin'), idle_timeout_seconds('mahasiswa'));
    ini_set('session.gc_maxlifetime', (string) $gcMax);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Peta header keamanan HTTP. HSTS hanya saat HTTPS. */
function security_headers(bool $https): array
{
    $cdn = 'https://cdn.jsdelivr.net';
    $csp = "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' $cdn; "
        . "style-src 'self' 'unsafe-inline' $cdn; "
        . "font-src 'self' data: $cdn; "
        . "img-src 'self' data: blob:; "
        . "media-src 'self' blob:; "
        . "connect-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'";

    $headers = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=()',
        'Content-Security-Policy' => $csp,
    ];
    if ($https) {
        $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
    }
    return $headers;
}

/** Kirim header keamanan sekali. Dipanggil sebelum output apa pun. */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    foreach (security_headers($https) as $name => $value) {
        header($name . ': ' . $value);
    }
}

send_security_headers();

/** Escape output HTML. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Tolak request non-POST atau token salah. */
function csrf_check(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit('Metode tidak diizinkan.');
    }
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        exit('Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash_set(string $type, string $message): void
{
    $_SESSION['_flash'] = ['type' => $type, 'msg' => $message];
}

function flash_html(): string
{
    if (empty($_SESSION['_flash'])) {
        return '';
    }
    $flash = $_SESSION['_flash'];
    unset($_SESSION['_flash']);

    $icons = [
        'success' => 'check-circle-fill',
        'danger'  => 'exclamation-circle-fill',
        'warning' => 'exclamation-triangle-fill',
        'info'    => 'info-circle-fill',
    ];
    $type = isset($icons[$flash['type']]) ? $flash['type'] : 'info';

    return "<div class=\"alert alert-{$type} d-flex align-items-center border-0 shadow-sm rounded-3 mb-4\" role=\"alert\" aria-live=\"polite\" tabindex=\"-1\">"
        . "<i class=\"bi bi-{$icons[$type]} fs-5 me-2\"></i>"
        . "<div>" . e($flash['msg']) . "</div></div>";
}

/** Nilai isian lama setelah validasi gagal. */
function old(string $key, string $default = ''): string
{
    return isset($_SESSION['_old'][$key]) ? (string) $_SESSION['_old'][$key] : $default;
}

function require_role($role): void
{
    $roles = is_array($role) ? $role : [$role];
    if (!in_array($_SESSION['role'] ?? '', $roles, true) || empty($_SESSION['id_user'])) {
        redirect('index.php');
    }

    // Timeout tidak aktif: perbarui penanda, tolak bila sudah lewat batas.
    $now = time();
    $timeout = idle_timeout_seconds((string) ($_SESSION['role'] ?? ''));
    if (sesi_kedaluwarsa($_SESSION['_last_active'] ?? null, $now, $timeout)) {
        $_SESSION = [];
        session_destroy();
        session_start();
        flash_set('warning', 'Sesi berakhir karena tidak aktif. Silakan login lagi.');
        redirect('index.php');
    }
    $_SESSION['_last_active'] = $now;

    // Sesi bisa merujuk user yang sudah dihapus, atau akun yang auth_version-nya
    // sudah dinaikkan (password berubah/direset). Validasi ke DB sekali di sini
    // per primary key. Fail-closed: kalau koneksi tak terduga, tetap tolak.
    global $conn;
    $stmt = ($conn instanceof mysqli)
        ? mysqli_prepare($conn, 'SELECT auth_version, wajib_ganti_password FROM users WHERE id_user = ? LIMIT 1')
        : false;
    if (!$stmt) {
        $_SESSION = [];
        session_destroy();
        redirect('index.php');
    }
    mysqli_stmt_bind_param($stmt, 'i', $_SESSION['id_user']);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || (int) $row['auth_version'] !== (int) ($_SESSION['auth_version'] ?? -1)) {
        $_SESSION = [];
        session_destroy();
        session_start();
        flash_set('warning', 'Sesi tidak berlaku lagi, silakan login ulang.');
        redirect('index.php');
    }

    // Wajib ganti password: cegat semua halaman kecuali penggantian & logout.
    if ((int) $row['wajib_ganti_password'] === 1) {
        $skrip = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($skrip !== 'ganti_password.php' && $skrip !== 'logout.php') {
            redirect('ganti_password.php');
        }
    }
}

/** Kode tiket acak 6 hex; unik + sulit ditebak. */
function ticket_code(): string
{
    return 'ACD-' . strtoupper(bin2hex(random_bytes(3)));
}

function password_ok(string $password): bool
{
    return strlen($password) >= 8;
}

/** Password akun admin baru: minimal 12 karakter (lebih ketat dari mahasiswa). */
function password_ok_admin(string $password): bool
{
    return strlen($password) >= 12;
}

/**
 * Ambil env var, fallback ke file secrets DI LUAR repo (default C:\xampp\academeet-secrets.ini).
 * Format tiap baris: KUNCI=nilai (# untuk komentar). Dipakai agar kredensial (SMTP) tak
 * masuk repo. File dibaca sekali per request; nilai env asli selalu menang.
 */
function env_secret(string $key, string $default = ''): string
{
    static $file = null;
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return $val;
    }
    if ($file === null) {
        $path = getenv('ACADEMEET_SECRETS_FILE') ?: 'C:/xampp/academeet-secrets.ini';
        $file = [];
        if (is_file($path) && is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $file[trim($k)] = trim($v);
            }
        }
    }
    return $file[$key] ?? $default;
}

/**
 * Kunci throttle: SHA-256 dari "scope|nilai". Email/nilai dinormalisasi lowercase
 * oleh pemanggil. Disimpan sebagai hash agar nilai asli (email/IP) tak tersimpan mentah.
 */
function throttle_key(string $scope, string $nilai): string
{
    return hash('sha256', $scope . '|' . strtolower(trim($nilai)));
}

/**
 * Jendela check-in: hanya boleh pada hari H. Mengembalikan pesan error tanggal,
 * atau null bila boleh check-in. Tanggal tidak valid dianggap jauh di masa depan.
 */
function checkin_window_error(string $tanggal, string $hariIni): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return 'Check-in baru dibuka pada hari seminar.';
    }
    if ($tanggal > $hariIni) {
        $ts = strtotime($tanggal);
        $tampil = $ts !== false ? date('d M Y', $ts) : $tanggal;
        return 'Check-in baru dibuka pada hari seminar (' . $tampil . ').';
    }
    return null;
}

/**
 * Status efektif seminar: "Selesai" bila status='Selesai' ATAU tanggal < hari ini.
 * Dihitung saat dibaca; tidak menulis ke DB.
 */
function seminar_status_efektif(string $status, string $tanggal, string $hariIni): string
{
    if ($status === 'Selesai') {
        return 'Selesai';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) && $tanggal < $hariIni) {
        return 'Selesai';
    }
    return 'Aktif';
}

/** Daftar program studi resmi. */
function prodi_terdaftar(): array
{
    return ['Teknik Informatika', 'Sistem Informasi', 'Manajemen Informatika', 'Desain Komunikasi Visual'];
}

/** Panjang string, memakai mb_strlen bila tersedia. */
function str_len(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

/**
 * Validasi data diri (register & profil). Mengembalikan pesan error, atau '' bila valid.
 * $prodiBolehLama: nilai prodi lama akun yang tetap boleh dipakai walau tak ada di daftar.
 */
function validasi_data_diri(string $nama, string $nim, string $prodi, string $email, array $prodiBolehLama = []): string
{
    if ($nama === '' || $email === '') {
        return 'Nama dan email wajib diisi.';
    }
    if (str_len($nama) > 100) {
        return 'Nama maksimal 100 karakter.';
    }
    if (str_len($nim) > 20) {
        return 'NIM maksimal 20 karakter.';
    }
    if (str_len($email) > 100) {
        return 'Email maksimal 100 karakter.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Format email tidak valid.';
    }
    if ($prodi === '') {
        return 'Program studi wajib dipilih.';
    }
    if (!in_array($prodi, prodi_terdaftar(), true) && !in_array($prodi, $prodiBolehLama, true)) {
        return 'Program studi tidak dikenali. Pilih dari daftar.';
    }
    return '';
}

/** Halaman admin.php yang boleh dibuka sebuah role. Admin boleh semua. Panitia hanya scan & peserta. */
function role_boleh_halaman(string $role, string $page): bool
{
    if ($role === 'admin') {
        return true;
    }
    if ($role === 'panitia') {
        return in_array($page, ['scan', 'peserta'], true);
    }
    return false;
}

/** Aksi POST yang boleh dilakukan sebuah role. Admin boleh semua; panitia hanya check-in. */
function role_boleh_aksi(string $role, string $aksi): bool
{
    if ($role === 'admin') {
        return true;
    }
    if ($role === 'panitia') {
        return in_array($aksi, ['checkin', 'checkin_manual'], true);
    }
    return false;
}

/** Panjang minimal password per role. Admin 12, panitia 10, mahasiswa 8. */
function password_min_untuk_role(string $role): int
{
    if ($role === 'admin') {
        return 12;
    }
    if ($role === 'panitia') {
        return 10;
    }
    return 8;
}

/** Batas atas panjang password: bcrypt memotong di 72 byte, jadi lebih panjang ditolak. */
function password_max_bytes(): int
{
    return 72;
}

/** Password valid untuk role: panjang minimal role + tidak melebihi 72 byte. */
function password_ok_untuk_role(string $role, string $password): bool
{
    $len = strlen($password);
    return $len >= password_min_untuk_role($role) && $len <= password_max_bytes();
}

/** Sel CSV yang diawali karakter formula/tab/CR tidak boleh (formula injection). */
function csv_sel_bahaya(string $value): bool
{
    return preg_match('/^[=+\-@\t\r]/', $value) === 1;
}

/** Buang karakter kontrol kecuali tab; kembalikan string bersih. */
function buang_karakter_kontrol(string $value): string
{
    return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
}

/**
 * Parse isi CSV impor mahasiswa. Murni (tanpa DB).
 * Mengembalikan [array $barisValid, array $kesalahan]. $kesalahan tiap item:
 * ['baris' => int, 'kolom' => string, 'alasan' => string]. Baris header bukan data.
 * Kolom wajib (case-insensitive, urutan bebas): nim, nama, prodi, email.
 */
function parse_csv_mahasiswa(string $isi): array
{
    $barisValid = [];
    $kesalahan = [];

    if (!mb_check_encoding($isi, 'UTF-8')) {
        return [[], [['baris' => 0, 'kolom' => '', 'alasan' => 'File bukan teks UTF-8 yang valid.']]];
    }
    // Buang BOM UTF-8 di awal.
    if (str_starts_with($isi, "\xEF\xBB\xBF")) {
        $isi = substr($isi, 3);
    }

    $isi = str_replace(["\r\n", "\r"], "\n", $isi);
    // fgetcsv butuh stream; pakai php://temp agar konsisten (kutip & pemisah).
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $isi);
    rewind($stream);

    // Deteksi pemisah dari baris header mentah.
    $headerLine = (string) fgets($stream);
    rewind($stream);
    $pemisah = (substr_count($headerLine, ';') > substr_count($headerLine, ',')) ? ';' : ',';

    $header = fgetcsv($stream, 0, $pemisah);
    if ($header === false || $header === null) {
        fclose($stream);
        return [[], [['baris' => 1, 'kolom' => '', 'alasan' => 'Header CSV tidak ditemukan.']]];
    }
    $peta = [];
    foreach ($header as $i => $kolom) {
        $nama = strtolower(trim((string) $kolom));
        if (in_array($nama, ['nim', 'nama', 'prodi', 'email'], true) && !isset($peta[$nama])) {
            $peta[$nama] = $i;
        }
    }
    $hilang = array_diff(['nim', 'nama', 'prodi', 'email'], array_keys($peta));
    if ($hilang) {
        fclose($stream);
        return [[], [['baris' => 1, 'kolom' => '', 'alasan' => 'Header harus memuat kolom: nim, nama, prodi, email (kolom hilang: ' . implode(', ', $hilang) . ').']]];
    }

    $lihatNim = [];
    $lihatEmail = [];
    $nomor = 1; // baris header = 1, data mulai 2.
    while (($row = fgetcsv($stream, 0, $pemisah)) !== false) {
        $nomor++;
        if ($row === null || (count($row) === 1 && trim((string) $row[0]) === '')) {
            continue; // baris kosong
        }
        if (count($barisValid) + count($kesalahan) >= 500) {
            $kesalahan[] = ['baris' => $nomor, 'kolom' => '', 'alasan' => 'Maksimal 500 baris data per impor.'];
            break;
        }
        $sel = static function (array $row, array $peta, string $kunci): string {
            return trim((string) ($row[$peta[$kunci]] ?? ''));
        };
        $nim = $sel($row, $peta, 'nim');
        $nama = $sel($row, $peta, 'nama');
        $prodi = $sel($row, $peta, 'prodi');
        $email = $sel($row, $peta, 'email');

        $bad = false;
        foreach (['nim' => $nim, 'nama' => $nama, 'prodi' => $prodi, 'email' => $email] as $kolom => $v) {
            if (csv_sel_bahaya($v)) {
                $kesalahan[] = ['baris' => $nomor, 'kolom' => $kolom, 'alasan' => 'Nilai tidak boleh diawali =, +, -, @, tab, atau CR.'];
                $bad = true;
            }
        }
        $nim = buang_karakter_kontrol($nim);
        $nama = buang_karakter_kontrol($nama);
        $prodi = buang_karakter_kontrol($prodi);
        $email = buang_karakter_kontrol($email);
        if ($bad) {
            continue;
        }
        if ($nim === '' || $nama === '' || $prodi === '' || $email === '') {
            $kesalahan[] = ['baris' => $nomor, 'kolom' => '', 'alasan' => 'Sel nim, nama, prodi, email wajib terisi.'];
            continue;
        }
        $nimKey = strtolower($nim);
        $emailKey = strtolower($email);
        if (isset($lihatNim[$nimKey])) {
            $kesalahan[] = ['baris' => $nomor, 'kolom' => 'nim', 'alasan' => 'NIM duplikat di dalam file (baris ' . $lihatNim[$nimKey] . ').'];
            continue;
        }
        if (isset($lihatEmail[$emailKey])) {
            $kesalahan[] = ['baris' => $nomor, 'kolom' => 'email', 'alasan' => 'Email duplikat di dalam file (baris ' . $lihatEmail[$emailKey] . ').'];
            continue;
        }
        $lihatNim[$nimKey] = $nomor;
        $lihatEmail[$emailKey] = $nomor;
        $barisValid[] = ['baris' => $nomor, 'nim' => $nim, 'nama' => $nama, 'prodi' => $prodi, 'email' => $email];
    }
    fclose($stream);
    return [$barisValid, $kesalahan];
}

/**
 * Validasi baris hasil parse terhadap aturan registrasi (murni + prodi resmi).
 * Mengembalikan [array $siap, array $kesalahan].
 */
function validasi_baris_impor(array $baris, array $prodi): array
{
    $siap = [];
    $kesalahan = [];
    foreach ($baris as $b) {
        $nama = (string) ($b['nama'] ?? '');
        $nim = (string) ($b['nim'] ?? '');
        $prodiInput = (string) ($b['prodi'] ?? '');
        $email = (string) ($b['email'] ?? '');
        $nomor = (int) ($b['baris'] ?? 0);

        // Cocokkan prodi tanpa membedakan huruf besar/kecil, simpan bentuk kanonik.
        $prodiKanon = '';
        foreach ($prodi as $p) {
            if (strcasecmp($p, $prodiInput) === 0) {
                $prodiKanon = $p;
                break;
            }
        }
        if ($prodiKanon === '') {
            $kesalahan[] = ['baris' => $nomor, 'kolom' => 'prodi', 'alasan' => 'Program studi tidak dikenali: ' . $prodiInput . '.'];
            continue;
        }
        $err = validasi_data_diri($nama, $nim, $prodiKanon, $email);
        if ($err !== '') {
            $kesalahan[] = ['baris' => $nomor, 'kolom' => '', 'alasan' => $err];
            continue;
        }
        $siap[] = ['baris' => $nomor, 'nim' => $nim, 'nama' => $nama, 'prodi' => $prodiKanon, 'email' => $email];
    }
    return [$siap, $kesalahan];
}

/**
 * Muat teks dalam lebar maksimum: kecilkan font sampai minimum, lalu potong "…".
 * $ukur(string $teks, float $ukuran): float. Mengembalikan [string $teks, float $ukuran].
 */
function muat_teks(callable $ukur, string $teks, float $lebarMaks, float $ukuranAwal, float $ukuranMin): array
{
    if ($teks === '' || $lebarMaks <= 0) {
        return [$teks, $ukuranAwal];
    }
    $ukuran = $ukuranAwal;
    while ($ukuran > $ukuranMin && $ukur($teks, $ukuran) > $lebarMaks) {
        $ukuran = max($ukuranMin, $ukuran - 0.5);
    }
    if ($ukur($teks, $ukuran) <= $lebarMaks) {
        return [$teks, $ukuran];
    }
    $potong = $teks;
    while ($potong !== '' && $ukur($potong . '…', $ukuran) > $lebarMaks) {
        $potong = function_exists('mb_substr') ? mb_substr($potong, 0, -1, 'UTF-8') : substr($potong, 0, -1);
    }
    return [$potong . '…', $ukuran];
}

/** Validasi field tanda tangan sertifikat. Mengembalikan pesan error, atau ''. */
function validasi_ttd(string $nama, string $jabatan): string
{
    if (str_len($nama) > 100) {
        return 'Nama penandatangan maksimal 100 karakter.';
    }
    if (str_len($jabatan) > 100) {
        return 'Jabatan penandatangan maksimal 100 karakter.';
    }
    if ($jabatan !== '' && $nama === '') {
        return 'Nama penandatangan wajib diisi bila jabatan diisi.';
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $nama . $jabatan) === 1) {
        return 'Nama dan jabatan tidak boleh memuat karakter kontrol.';
    }
    return '';
}

/** Avatar lokal (SVG data URI) pengganti layanan pihak ketiga. */
function initials_avatar(string $name): string
{
    $name = trim($name);
    $initial = function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1);
    $initial = strtoupper($initial !== '' ? $initial : '?');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120">'
        . '<rect width="120" height="120" rx="60" fill="#2563EB"/>'
        . '<text x="60" y="60" dy=".35em" text-anchor="middle" font-family="Arial,sans-serif" font-size="52" fill="#ffffff">'
        . htmlspecialchars($initial, ENT_QUOTES | ENT_XML1, 'UTF-8')
        . '</text></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/** Placeholder lokal pengganti via.placeholder.com. */
function placeholder_data_uri(string $text, int $width = 80, int $height = 50): string
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '">'
        . '<rect width="100%" height="100%" fill="#EFF6FF"/>'
        . '<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="Arial,sans-serif" font-size="11" fill="#2563EB">'
        . htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8')
        . '</text></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/** Cegah formula injection saat sel Excel dibuka. */
function excel_cell($value): string
{
    $value = (string) $value;
    if (preg_match('/^\s*[=+\-@\t\r]/', $value)) {
        $value = "'" . $value;
    }
    return $value;
}

/** Netralkan formula + escape teks untuk sel XML. Karakter kontrol ilegal dibuang. */
function sel_aman(string $value): string
{
    $value = excel_cell($value);
    // Buang karakter kontrol yang tidak valid di XML 1.0 (kecuali \t \n \r).
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Bangun CSV UTF-8 (dengan BOM) ke file temp. Mengembalikan path.
 * $header: label kolom. $baris: array of array (tiap baris = list sel).
 */
function csv_bangun(array $header, array $baris): string
{
    $path = tempnam(sys_get_temp_dir(), 'acdm_csv_');
    $fh = fopen($path, 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, array_map('excel_cell', $header), ',');
    foreach ($baris as $b) {
        fputcsv($fh, array_map(static fn($v) => excel_cell((string) $v), $b), ',');
    }
    fclose($fh);
    return $path;
}

/**
 * Bangun XLSX minimal (tanpa library) memakai ZipArchive. Mengembalikan path temp.
 * Semua sel bertipe inlineStr sehingga NIM berawalan nol tetap teks. Header tebal + beku.
 */
function xlsx_bangun(array $header, array $baris): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Ekstensi zip tidak tersedia.');
    }
    $cols = count($header);
    $kolom_xml = static function (int $i): string {
        $s = '';
        while ($i > 0) {
            $i--;
            $s = chr(65 + ($i % 26)) . $s;
            $i = intdiv($i, 26);
        }
        return $s;
    };

    // sheetData
    $sheet = '';
    $row = 1;
    // Baris header (style 1 = tebal), beku di baris 1.
    $sheet .= '<row r="' . $row . '">';
    foreach ($header as $i => $h) {
        $c = $kolom_xml($i + 1) . $row;
        $sheet .= '<c r="' . $c . '" s="1" t="inlineStr"><is><t xml:space="preserve">' . sel_aman((string) $h) . '</t></is></c>';
    }
    $sheet .= '</row>';
    foreach ($baris as $b) {
        $row++;
        $sheet .= '<row r="' . $row . '">';
        for ($i = 0; $i < $cols; $i++) {
            $val = (string) ($b[$i] ?? '');
            $c = $kolom_xml($i + 1) . $row;
            $sheet .= '<c r="' . $c . '" t="inlineStr"><is><t xml:space="preserve">' . sel_aman($val) . '</t></is></c>';
        }
        $sheet .= '</row>';
    }

    // Lebar kolom (default 20, sedikit lebih lebar untuk nama).
    $colsXml = '';
    for ($i = 1; $i <= $cols; $i++) {
        $colsXml .= '<col min="' . $i . '" max="' . $i . '" width="22" customWidth="1"/>';
    }

    // Dimensi.
    $dim = 'A1:' . $kolom_xml(max($cols, 1)) . max($row, 1);

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<dimension ref="' . $dim . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<cols>' . $colsXml . '</cols>'
        . '<sheetData>' . $sheet . '</sheetData>'
        . '</worksheet>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $path = tempnam(sys_get_temp_dir(), 'acdm_xlsx_');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($path);
        throw new RuntimeException('Gagal membuat arsip xlsx.');
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->close();
    return $path;
}

/**
 * Ubah shorthand ukuran php.ini (mis. "512M", "2G", "-1") ke byte.
 * Mengembalikan nilai <= 0 bila tak terbatas.
 */
function pdf_text(string $value): string
{
    return function_exists('iconv')
        ? (iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $value) ?: $value)
        : $value;
}

/**
 * Ubah shorthand ukuran php.ini (mis. "512M", "2G", "-1") ke byte.
 * Mengembalikan nilai <= 0 bila tak terbatas.
 */
function parse_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return -1;
    }
    $unit = strtoupper(substr($value, -1));
    $num = (int) $value;
    return match ($unit) {
        'G'     => $num * 1024 * 1024 * 1024,
        'M'     => $num * 1024 * 1024,
        'K'     => $num * 1024,
        default => $num,
    };
}

/**
 * Batas dimensi maks per subdir upload. Sisi terpanjang (kecuali poster: lebar).
 * Mengembalikan [maxWidth, maxHeight]; 0 berarti tidak dibatasi di sisi itu.
 */
function upload_max_dimensions(string $subdir): array
{
    return [
        'posters'  => [1000, 0],   // poster: maks lebar 1000px
        'profiles' => [400, 400],  // profil: sisi terpanjang 400px
        'pemateri' => [600, 600],  // pemateri: sisi terpanjang 600px
    ][$subdir] ?? [0, 0];
}

/**
 * Resize + re-encode gambar dengan GD. Tidak upscale, aspek rasio dijaga.
 * Ekstensi output mengikuti format sumber (jpg/png/webp). JPEG: quality + progressive.
 * Mengembalikan true jika berhasil menulis $dst, false jika GD/format tak didukung.
 */
function resize_image_file(string $src, string $dst, int $maxWidth, int $maxHeight, int $quality = 80): bool
{
    if (!extension_loaded('gd') || !function_exists('imagecreatefromjpeg')) {
        return false;
    }
    $info = @getimagesize($src);
    if (!$info) {
        return false;
    }
    [$w, $h] = $info;
    $type = $info[2];
    if ($type === IMAGETYPE_JPEG) {
        $im = @imagecreatefromjpeg($src);
    } elseif ($type === IMAGETYPE_PNG) {
        $im = @imagecreatefrompng($src);
    } elseif ($type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
        $im = @imagecreatefromwebp($src);
    } else {
        return false;
    }
    if (!$im) {
        return false;
    }

    // Koreksi orientasi EXIF (JPEG) karena resize membuang metadata EXIF.
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $orient = is_array($exif) ? ($exif['Orientation'] ?? 0) : 0;
        $deg = ['3' => 180, '6' => -90, '8' => 90][(string) $orient] ?? null;
        if ($deg !== null) {
            $rotated = imagerotate($im, $deg, 0);
            if ($rotated !== false) {
                imagedestroy($im);
                $im = $rotated;
            }
        }
    }

    $w = imagesx($im);
    $h = imagesy($im);

    // Hitung skala, jangan upscale.
    $scale = 1.0;
    if ($maxWidth > 0 && $w > $maxWidth) {
        $scale = min($scale, $maxWidth / $w);
    }
    if ($maxHeight > 0 && $h > $maxHeight) {
        $scale = min($scale, $maxHeight / $h);
    }
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));

    $out = ($scale < 1.0) ? imagescale($im, $nw, $nh, IMG_BICUBIC) : $im;
    if (!$out) {
        imagedestroy($im);
        return false;
    }

    if ($type === IMAGETYPE_PNG) {
        imagesavealpha($out, true);
        $ok = imagepng($out, $dst, 8);
    } elseif ($type === IMAGETYPE_WEBP && function_exists('imagewebp')) {
        $ok = imagewebp($out, $dst, $quality);
    } else {
        imageinterlace($out, true); // progressive JPEG
        $ok = imagejpeg($out, $dst, $quality);
    }

    if ($out !== $im) {
        imagedestroy($out);
    }
    imagedestroy($im);
    return (bool) $ok;
}

/**
 * Validasi upload gambar berdasarkan isi file (bukan ekstensi).
 * Mengembalikan nama file baru, atau null jika tidak ada file.
 */
function upload_image(array $file, string $subdir, int $maxBytes): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload gambar gagal. Coba lagi.');
    }
    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('File upload tidak valid.');
    }
    if (($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('Ukuran gambar melebihi batas ' . round($maxBytes / 1000000, 1) . 'MB.');
    }

    $info = @getimagesize($file['tmp_name']);
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($allowed[$info[2]])) {
        throw new RuntimeException('File harus berupa gambar JPG, PNG, atau WebP.');
    }

    // Tolak gambar absurd sebelum decode agar server tak kehabisan memori.
    $w = (int) $info[0];
    $h = (int) $info[1];
    $limit = 10000;
    $memLimit = parse_ini_bytes((string) ini_get('memory_limit')); // byte; <=0 berarti tak terbatas
    $estMem = $w * $h * 5;
    if ($w > $limit || $h > $limit || ($memLimit > 0 && $estMem > $memLimit * 0.6)) {
        throw new RuntimeException('Dimensi gambar terlalu besar untuk diproses. Mohon unggah gambar yang lebih kecil.');
    }

    $prefix = ['posters' => 'poster_', 'pemateri' => 'pemateri_', 'profiles' => 'profile_'][$subdir] ?? 'img_';
    $name = $prefix . bin2hex(random_bytes(8)) . '.' . $allowed[$info[2]];
    $dir = __DIR__ . '/uploads/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('Folder upload tidak tersedia.');
    }

    // Resize + re-encode di temp; kalau gagal, simpan file asli (jangan gagalkan upload).
    $source = $file['tmp_name'];
    $isResized = false;
    $tmpResized = $file['tmp_name'] . '.opt.' . $allowed[$info[2]];
    [$maxW, $maxH] = upload_max_dimensions($subdir);
    if (($maxW > 0 || $maxH > 0) && resize_image_file($source, $tmpResized, $maxW, $maxH, 80)) {
        if (@filesize($tmpResized) > 0 && @filesize($tmpResized) < @filesize($source)) {
            $source = $tmpResized;
            $isResized = true;
        } else {
            @unlink($tmpResized);
        }
    } elseif (is_file($tmpResized)) {
        @unlink($tmpResized);
    }

    $dest = $dir . '/' . $name;
    if ($isResized) {
        // rename cepat bila satu filesystem; fallback copy untuk tmp berbeda mount.
        $moved = @rename($source, $dest) || (@copy($source, $dest) && @unlink($source));
    } else {
        $moved = move_uploaded_file($source, $dest);
    }
    if (!$moved) {
        @unlink($dest); // buang hasil parsial bila copy gagal di tengah
        @unlink($tmpResized);
        throw new RuntimeException('Gagal menyimpan gambar.');
    }
    @unlink($tmpResized);
    return $name;
}

/** Parameter pagination server-side: [halaman, perPage, q]. */
function list_page(int $perPage = 10): array
{
    $page = max(1, (int) ($_GET['p'] ?? 1));
    $q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
    return [$page, $perPage, $q];
}

function pagination_html(int $total, int $page, int $perPage): string
{
    $pages = max(1, (int) ceil($total / $perPage));
    if ($pages <= 1) {
        return '';
    }
    $page = min($page, $pages);
    $link = static function (int $n): string {
        $qs = $_GET;
        $qs['p'] = $n;
        // Anchor hanya untuk dashboard admin (kartu Daftar Seminar), jangan halaman lain.
        $hash = (($qs['page'] ?? '') === 'dashboard') ? '#daftar-seminar' : '';
        return '?' . http_build_query($qs) . $hash;
    };

    $html = '<nav aria-label="Navigasi halaman"><ul class="pagination pagination-sm mb-0">';
    $html .= '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '">'
        . ($page <= 1
            ? '<span class="page-link" aria-hidden="true">Prev</span>'
            : '<a class="page-link" href="' . e($link($page - 1)) . '" rel="prev">Prev</a>')
        . '</li>';
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) {
        $active = $i === $page;
        $html .= '<li class="page-item' . ($active ? ' active' : '') . '"><a class="page-link" href="'
            . e($link($i)) . '"' . ($active ? ' aria-current="page"' : '') . '>' . $i . '</a></li>';
    }
    $html .= '<li class="page-item' . ($page >= $pages ? ' disabled' : '') . '">'
        . ($page >= $pages
            ? '<span class="page-link" aria-hidden="true">Next</span>'
            : '<a class="page-link" href="' . e($link($page + 1)) . '" rel="next">Next</a>')
        . '</li>';
    return $html . '</ul></nav>';
}

function table_footer(int $total, int $page, int $perPage): string
{
    $start = $total > 0 ? ($page - 1) * $perPage + 1 : 0;
    $end = min($page * $perPage, $total);
    return '<div class="table-footer border-top px-4 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">'
        . '<span class="small text-muted">Menampilkan ' . $start . '–' . $end . ' dari ' . $total . ' data</span>'
        . pagination_html($total, $page, $perPage)
        . '</div>';
}

/** Skrip kecil untuk <head>: set data-bs-theme SEBELUM CSS dimuat, cegah kilatan putih. */
function theme_boot_script(): string
{
    return '<script>(function(){try{var t=localStorage.getItem("academeet-theme");if(t!=="light"&&t!=="dark"){t=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light";}document.documentElement.setAttribute("data-bs-theme",t);}catch(e){}})();</script>';
}

/** Switch tema (pill + knob geser). */
function theme_toggle_button(string $extraClass = ''): string
{
    $class = trim('js-theme-toggle theme-switch ' . $extraClass);
    return '<button type="button" class="' . e($class) . '"'
        . ' aria-pressed="false" aria-label="Ganti ke mode gelap">'
        . '<span class="theme-switch__knob">'
        . '<i class="bi bi-sun-fill" aria-hidden="true"></i>'
        . '<i class="bi bi-moon-stars-fill" aria-hidden="true"></i>'
        . '</span>'
        . '</button>';
}

/** Daftar kategori seminar resmi. */
function kategori_seminar(): array
{
    return ['Teknologi', 'Karier', 'Riset', 'Soft Skill', 'Kewirausahaan', 'Umum'];
}

/** Validasi URL online (murni, tanpa DB). */
function validasi_link_online(string $url): bool
{
    if ($url === '') {
        return false;
    }
    // Tolak karakter kontrol (termasuk newline/tab/spasi)
    if (preg_match('/[\x00-\x20\x7F]/', $url)) {
        return false;
    }
    // Parse URL
    $parts = parse_url($url);
    if ($parts === false) {
        return false;
    }
    // Skema harus http atau https (case-insensitive)
    $scheme = $parts['scheme'] ?? '';
    if (!in_array(strtolower($scheme), ['http', 'https'], true)) {
        return false;
    }
    // Harus ada host non-kosong
    $host = $parts['host'] ?? '';
    if ($host === '') {
        return false;
    }
    // Tolak userinfo (user:pass@ atau user@)
    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    return true;
}

/**
 * Normalisasi jam ke "HH:MM".
 * Menerima "HH:MM" atau "HH:MM:SS"; rentang HH 00-23, MM/SS 00-59; wajib 2 digit.
 * Kosong atau format tidak valid -> null.
 */
function normalisasi_jam(string $nilai): ?string
{
    $nilai = trim($nilai);
    if ($nilai === '') {
        return null;
    }
    if (!preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $nilai, $m)) {
        return null;
    }
    $hh = (int) $m[1];
    $mm = (int) $m[2];
    $ss = isset($m[3]) ? (int) $m[3] : 0;
    if ($hh > 23 || $mm > 59 || $ss > 59) {
        return null;
    }
    return sprintf('%02d:%02d', $hh, $mm);
}

/** Validasi detail seminar (murni, tanpa DB). */
function validasi_detail_seminar(array $in): array
{
    $error = '';
    $data = [
        'jam_mulai' => null,
        'jam_selesai' => null,
        'lokasi' => null,
        'link_online' => null,
        'deskripsi' => null,
        'kategori' => '',
    ];

    // 1. Cek kategori (dulu agar error pertama kalau salah)
    if (!array_key_exists('kategori', $in)) {
        $data['kategori'] = 'Umum';
    } else {
        $kat = is_string($in['kategori']) ? trim($in['kategori']) : '';
        if ($kat === '' || !in_array($kat, kategori_seminar(), true)) {
            $error = 'Kategori tidak dikenali.';
            return [$error, $data];
        }
        $data['kategori'] = $kat;
    }

    // 2. Jam mulai
    if (isset($in['jam_mulai']) && $in['jam_mulai'] !== '' && $in['jam_mulai'] !== null) {
        $jm = is_string($in['jam_mulai']) ? trim($in['jam_mulai']) : '';
        if ($jm === '') {
            $data['jam_mulai'] = null;
        } else {
            $hasil = normalisasi_jam($jm);
            if ($hasil === null) {
                $error = 'Jam mulai tidak valid.';
                return [$error, $data];
            }
            $data['jam_mulai'] = sprintf('%s:00', $hasil);
        }
    }

    // 3. Jam selesai
    if (isset($in['jam_selesai']) && $in['jam_selesai'] !== '' && $in['jam_selesai'] !== null) {
        $js = is_string($in['jam_selesai']) ? trim($in['jam_selesai']) : '';
        if ($js === '') {
            $data['jam_selesai'] = null;
        } else {
            if ($data['jam_mulai'] === null) {
                $error = 'Jam selesai diisi tetapi jam mulai kosong.';
                return [$error, $data];
            }
            $hasil = normalisasi_jam($js);
            if ($hasil === null) {
                $error = 'Jam selesai tidak valid.';
                return [$error, $data];
            }
            $jamSelesaiStr = sprintf('%s:00', $hasil);
            if ($jamSelesaiStr <= $data['jam_mulai']) {
                $error = 'Jam selesai harus lebih besar dari jam mulai.';
                return [$error, $data];
            }
            $data['jam_selesai'] = $jamSelesaiStr;
        }
    }

    // 4. Lokasi
    if (isset($in['lokasi']) && $in['lokasi'] !== '' && $in['lokasi'] !== null) {
        $lok = is_string($in['lokasi']) ? trim($in['lokasi']) : '';
        if ($lok === '') {
            $data['lokasi'] = null;
        } elseif (str_len($lok) > 150) {
            $error = 'Lokasi maksimal 150 karakter.';
            return [$error, $data];
        } else {
            $data['lokasi'] = $lok;
        }
    }

    // 5. Link online
    if (isset($in['link_online']) && $in['link_online'] !== '' && $in['link_online'] !== null) {
        $lo = is_string($in['link_online']) ? trim($in['link_online']) : '';
        if ($lo === '') {
            $data['link_online'] = null;
        } elseif (str_len($lo) > 255) {
            $error = 'Link online maksimal 255 karakter.';
            return [$error, $data];
        } elseif (!validasi_link_online($lo)) {
            $error = 'Link online tidak valid.';
            return [$error, $data];
        } else {
            $data['link_online'] = $lo;
        }
    }

    // 6. Deskripsi
    if (isset($in['deskripsi']) && $in['deskripsi'] !== '' && $in['deskripsi'] !== null) {
        $desk = is_string($in['deskripsi']) ? trim($in['deskripsi']) : '';
        if ($desk === '') {
            $data['deskripsi'] = null;
        } elseif (str_len($desk) > 2000) {
            $error = 'Deskripsi maksimal 2000 karakter.';
            return [$error, $data];
        } else {
            $data['deskripsi'] = $desk;
        }
    }

    return [$error, $data];
}

/** Escape string untuk LIKE (RFC pattern ESCAPE '\\'). */
function like_escape(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
}

/** Normalisasi filter katalog (murni, tanpa DB). */
function normalisasi_filter_katalog(array $get): array
{
    $q = '';
    if (isset($get['q']) && is_string($get['q'])) {
        $q = trim($get['q']);
    }

    $kat = '';
    if (isset($get['kat']) && is_string($get['kat'])) {
        $kat = trim($get['kat']);
        if ($kat === '' || !in_array($kat, kategori_seminar(), true)) {
            $kat = '';
        }
    }

    $dari = '';
    if (isset($get['dari']) && is_string($get['dari'])) {
        $d = trim($get['dari']);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $parts = explode('-', $d);
            $y = (int) $parts[0];
            $m = (int) $parts[1];
            $d_val = (int) $parts[2];
            if (checkdate($m, $d_val, $y)) {
                $dari = $d;
            }
        }
    }

    $sampai = '';
    if (isset($get['sampai']) && is_string($get['sampai'])) {
        $s = trim($get['sampai']);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            $parts = explode('-', $s);
            $y = (int) $parts[0];
            $m = (int) $parts[1];
            $d_val = (int) $parts[2];
            if (checkdate($m, $d_val, $y)) {
                $sampai = $s;
            }
        }
    }

    // Jika keduanya terisi dan dari > sampai, tukar
    if ($dari !== '' && $sampai !== '' && $dari > $sampai) {
        $tmp = $dari;
        $dari = $sampai;
        $sampai = $tmp;
    }

    $sedia = 'semua';
    if (isset($get['sedia'])) {
        $s = strtolower(trim((string) $get['sedia']));
        if (in_array($s, ['semua', 'tersedia', 'penuh'], true)) {
            $sedia = $s;
        }
    }

    $p = 1;
    if (isset($get['p'])) {
        $val = $get['p'];
        if (is_int($val) && $val >= 1) {
            $p = $val;
        } elseif (is_numeric($val)) {
            $num = (int) $val;
            if ($num >= 1) {
                $p = $num;
            }
        }
    }

    return [
        'q' => $q,
        'kat' => $kat,
        'dari' => $dari,
        'sampai' => $sampai,
        'sedia' => $sedia,
        'p' => $p,
    ];
}

/** Escape string untuk ICS (RFC 5545). */
function ics_escape(string $s): string
{
    return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $s);
}

/** Lipat baris ICS pada 75 oktet tanpa memotong UTF-8. */
function ics_lipat_baris(string $baris): string
{
    $len = strlen($baris);
    if ($len <= 75) {
        return $baris;
    }
    $result = '';
    $pos = 0;
    while ($pos < $len) {
        $max = ($pos === 0) ? 75 : 74;
        $chunkLen = min($max, $len - $pos);
        if ($pos + $chunkLen < $len) {
            while ($chunkLen > 0 && (ord($baris[$pos + $chunkLen]) & 0xC0) === 0x80) {
                $chunkLen--;
            }
            // Jangan biarkan satu chunk habis di tengah karakter (loop tak maju).
            if ($chunkLen === 0) {
                $chunkLen = min($max, $len - $pos);
            }
        }
        if ($pos > 0) {
            $result .= "\r\n ";
        }
        $result .= substr($baris, $pos, $chunkLen);
        $pos += $chunkLen;
    }
    return $result;
}

/** Slug nama file ICS (ASCII, lowercase, ganti non-[a-z0-9] dengan -). */
function slug_nama_file(string $s): string
{
    $s = trim($s);
    if (function_exists('iconv')) {
        $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($trans !== false) {
            $s = $trans;
        }
    }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    if (strlen($s) > 60) {
        $s = substr($s, 0, 60);
        $s = rtrim($s, '-');
    }
    return $s === '' ? 'seminar' : $s;
}

/** Buat file ICS RFC 5545 (murni, tanpa DB). */
function buat_ics(array $acara, string $sekarangUtc): string
{
    $dtstamp = '';
    if ($sekarangUtc !== '' && preg_match('/^\d{8}T\d{6}Z$/', $sekarangUtc)) {
        $dtstamp = $sekarangUtc;
    } elseif ($sekarangUtc !== '') {
        $ts = strtotime($sekarangUtc . ' UTC');
        $dtstamp = $ts !== false ? gmdate('Ymd\THis\Z', $ts) : gmdate('Ymd\THis\Z');
    } else {
        $dtstamp = gmdate('Ymd\THis\Z');
    }

    $id = (string) ($acara['id_registrasi'] ?? $acara['id'] ?? '0');
    $judul = (string) ($acara['judul'] ?? '');
    $pemateri = (string) ($acara['pemateri'] ?? '');
    $kategori = (string) ($acara['kategori'] ?? '');
    $deskripsi = (string) ($acara['deskripsi'] ?? '');
    $lokasi = (string) ($acara['lokasi'] ?? '');
    $link_online = (string) ($acara['link_online'] ?? '');
    $tanggal = (string) ($acara['tanggal'] ?? '');
    $jamMulai = $acara['jam_mulai'] ?? null;
    $jamSelesai = $acara['jam_selesai'] ?? null;

    $descParts = [];
    if ($pemateri !== '') $descParts[] = 'Pemateri: ' . $pemateri;
    if ($kategori !== '') $descParts[] = 'Kategori: ' . $kategori;
    if ($deskripsi !== '') $descParts[] = $deskripsi;
    if ($link_online !== '') $descParts[] = 'Link: ' . $link_online;
    $desc = implode("\n\n", $descParts);

    $loc = '';
    if ($lokasi !== '') $loc = $lokasi;
    elseif ($link_online !== '') $loc = 'Online';

    $lines = [];
    $lines[] = 'BEGIN:VCALENDAR';
    $lines[] = 'VERSION:2.0';
    $lines[] = 'PRODID:-//AcadeMeet//Seminar//ID';
    $lines[] = 'CALSCALE:GREGORIAN';
    $lines[] = 'METHOD:PUBLISH';

    $hasJam = ($jamMulai !== null && $jamMulai !== '' && preg_match('/^\d{2}:\d{2}/', $jamMulai));
    if ($hasJam) {
        $lines[] = 'BEGIN:VTIMEZONE';
        $lines[] = 'TZID:Asia/Jakarta';
        $lines[] = 'BEGIN:STANDARD';
        $lines[] = 'DTSTART:19700101T000000';
        $lines[] = 'TZOFFSETFROM:+0700';
        $lines[] = 'TZOFFSETTO:+0700';
        $lines[] = 'TZNAME:WIB';
        $lines[] = 'END:STANDARD';
        $lines[] = 'END:VTIMEZONE';
    }

    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:registrasi-' . $id . '@academeet';
    $lines[] = 'DTSTAMP:' . $dtstamp;
    $lines[] = ics_lipat_baris('SUMMARY:' . ics_escape($judul));
    $lines[] = ics_lipat_baris('LOCATION:' . ics_escape($loc));
    $lines[] = ics_lipat_baris('DESCRIPTION:' . ics_escape($desc));

    $tglComp = str_replace('-', '', $tanggal);
    if ($hasJam) {
        $jm = preg_match('/^(\d{2}):(\d{2})/', $jamMulai, $m) ? sprintf('%02d%02d00', $m[1], $m[2]) : '000000';
        $lines[] = 'DTSTART;TZID=Asia/Jakarta:' . $tglComp . 'T' . $jm;

        if ($jamSelesai && preg_match('/^(\d{2}):(\d{2})/', $jamSelesai, $m)) {
            $js = sprintf('%02d%02d00', $m[1], $m[2]);
        } else {
            $h = (int) substr($jm, 0, 2) + 2;
            $js = ($h >= 24) ? '235959' : sprintf('%02d%s', $h, substr($jm, 2, 4));
        }
        $lines[] = 'DTEND;TZID=Asia/Jakarta:' . $tglComp . 'T' . $js;

        $lines[] = 'BEGIN:VALARM';
        $lines[] = 'ACTION:DISPLAY';
        $lines[] = ics_lipat_baris('DESCRIPTION:' . ics_escape($judul !== '' ? $judul : 'Pengingat seminar'));
        $lines[] = 'TRIGGER:-PT1H';
        $lines[] = 'END:VALARM';
    } else {
        $lines[] = 'DTSTART;VALUE=DATE:' . $tglComp;
        $lines[] = 'DTEND;VALUE=DATE:' . date('Ymd', strtotime($tanggal . ' +1 day'));
    }

    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", $lines) . "\r\n";
}

// Semua halaman wajib memuat bootstrap paling awal, jadi services ikut terpusat di sini.
// Fungsi di services.php hanya definisi (menerima $conn sebagai parameter) sehingga aman
// dimuat tanpa koneksi.
require_once __DIR__ . '/services.php';
