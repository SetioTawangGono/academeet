<?php
// Fondasi keamanan & helper bersama. Semua halaman wajib require file ini paling awal.
date_default_timezone_set('Asia/Jakarta');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

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

function require_role(string $role): void
{
    if (($_SESSION['role'] ?? '') !== $role || empty($_SESSION['id_user'])) {
        redirect('index.php');
    }

    // Sesi bisa merujuk user yang sudah dihapus. Validasi ke DB sekali di sini
    // agar halaman tidak fatal saat $user null. Fail-closed: kalau koneksi tak
    // terduga, tetap tolak daripada meloloskan sesi basi.
    global $conn;
    $stmt = ($conn instanceof mysqli)
        ? mysqli_prepare($conn, 'SELECT 1 FROM users WHERE id_user = ? LIMIT 1')
        : false;
    if (!$stmt) {
        $_SESSION = [];
        session_destroy();
        redirect('index.php');
    }
    mysqli_stmt_bind_param($stmt, 'i', $_SESSION['id_user']);
    mysqli_stmt_execute($stmt);
    $ada = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$ada) {
        $_SESSION = [];
        session_destroy();
        redirect('index.php');
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
        return '?' . http_build_query($qs);
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
