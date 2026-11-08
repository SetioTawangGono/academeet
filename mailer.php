<?php
/**
 * Pengiriman email via SMTP (PHPMailer) untuk F5 lupa password.
 * Konfigurasi dari env — JANGAN ada default kredensial:
 *   ACADEMEET_SMTP_HOST, ACADEMEET_SMTP_PORT, ACADEMEET_SMTP_SECURE (tls|ssl|''),
 *   ACADEMEET_SMTP_USER, ACADEMEET_SMTP_PASS, ACADEMEET_SMTP_FROM, ACADEMEET_BASE_URL
 *
 * Email TIDAK dikirim saat pengujian otomatis — hanya isi_email_reset() (murni) yang diuji.
 */

require_once __DIR__ . '/vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/src/SMTP.php';

/**
 * Isi email reset (murni, tanpa efek samping). Dipakai tes + kirim_email().
 * $baseUrl tanpa trailing slash, mis. http://localhost/academeet
 */
function isi_email_reset(string $baseUrl, string $token): string
{
    $tautan = rtrim($baseUrl, '/') . '/reset_password.php?token=' . $token;
    return "Halo,\n\n"
        . "Kami menerima permintaan reset password untuk akun AcadeMeet Anda.\n"
        . "Buka tautan berikut untuk membuat password baru (berlaku 30 menit, hanya sekali pakai):\n\n"
        . $tautan . "\n\n"
        . "Jika Anda tidak meminta reset password, abaikan email ini; password Anda tidak berubah.\n\n"
        . "Salam,\nAcadeMeet";
}

/** Konfigurasi SMTP dari env (atau file secrets di luar repo). Null bila belum lengkap. */
function smtp_config(): ?array
{
    $host = env_secret('ACADEMEET_SMTP_HOST');
    $from = env_secret('ACADEMEET_SMTP_FROM');
    $base = env_secret('ACADEMEET_BASE_URL');
    if ($host === '' || $from === '' || $base === '') {
        return null;
    }
    return [
        'host'   => $host,
        'port'   => (int) (env_secret('ACADEMEET_SMTP_PORT', '587') ?: 587),
        'secure' => env_secret('ACADEMEET_SMTP_SECURE', 'tls'),
        'user'   => env_secret('ACADEMEET_SMTP_USER'),
        'pass'   => env_secret('ACADEMEET_SMTP_PASS'),
        'from'   => $from,
        'base'   => rtrim($base, '/'),
    ];
}

/**
 * Kirim email teks sederhana. Mengembalikan true bila terkirim.
 * Bila SMTP belum dikonfigurasi, gagal dengan error_log (TANPA isi email).
 * $timeout: batas waktu koneksi SMTP dalam detik (default PHPMailer bila 0).
 */
function kirim_email(string $ke, string $subjek, string $teks, int $timeout = 0): bool
{
    $cfg = smtp_config();
    if ($cfg === null) {
        error_log('SMTP belum dikonfigurasi (ACADEMEET_SMTP_HOST/FROM/BASE_URL); email tidak dikirim.');
        return false;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $cfg['host'];
        $mail->Port = $cfg['port'];
        if ($cfg['secure'] !== '') {
            $mail->SMTPSecure = $cfg['secure'];
        }
        $mail->SMTPAuth = $cfg['user'] !== '';
        if ($cfg['user'] !== '') {
            $mail->Username = $cfg['user'];
            $mail->Password = $cfg['pass'];
        }
        if ($timeout > 0) {
            $mail->Timeout = $timeout;
        }
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($cfg['from']);
        $mail->addAddress($ke);
        $mail->Subject = $subjek;
        $mail->Body = $teks;
        $mail->send();
        return true;
    } catch (\Throwable $ex) {
        // Jangan log isi email/tautan; cukup pesan error PHPMailer.
        error_log('Kirim email gagal: ' . $ex->getMessage());
        return false;
    }
}

/** Buang CR/LF agar nilai tak menyuntik header email (anti header injection). */
function bersihkan_header(string $nilai): string
{
    return trim(preg_replace('/[\r\n]+/', ' ', $nilai) ?? $nilai);
}

/** Format tanggal + jam seminar untuk isi email (bahasa Indonesia). */
function _email_jadwal(string $tanggal, ?string $jam_mulai, ?string $jam_selesai): string
{
    $ts = strtotime($tanggal);
    $hari = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
    $bulan = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
    $tgl = date('d', $ts) . ' ' . ($bulan[(int) date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
    $jam = '';
    if (!empty($jam_mulai)) {
        $jam = ' pukul ' . substr($jam_mulai, 0, 5);
        if (!empty($jam_selesai)) {
            $jam .= '-' . substr($jam_selesai, 0, 5);
        }
        $jam .= ' WIB';
    }
    return $tgl . $jam;
}

/**
 * Isi email konfirmasi pendaftaran (murni). Mengembalikan [subjek, isi].
 * $baseUrl: URL absolut APP (tanpa trailing slash) atau '' -> tautan dihilangkan.
 * TANPA link online seminar, token, atau password.
 */
function isi_email_konfirmasi(string $baseUrl, string $nama, array $sem, string $kodeTiket): array
{
    $subjek = 'Konfirmasi Pendaftaran: ' . bersihkan_header((string) ($sem['judul'] ?? ''));
    $lokasi = trim((string) ($sem['lokasi'] ?? ''));
    if ($lokasi === '') {
        $lokasi = 'akan diumumkan';
    }
    $tgl = _email_jadwal((string) ($sem['tanggal'] ?? ''), $sem['jam_mulai'] ?? null, $sem['jam_selesai'] ?? null);

    $isi = "Halo " . bersihkan_header($nama) . ",\n\n"
        . "Pendaftaran Anda pada seminar berikut berhasil:\n\n"
        . "Judul    : " . bersihkan_header((string) ($sem['judul'] ?? '')) . "\n"
        . "Pemateri : " . bersihkan_header((string) ($sem['pemateri'] ?? '')) . "\n"
        . "Tanggal  : " . $tgl . "\n"
        . "Lokasi   : " . bersihkan_header($lokasi) . "\n"
        . "Kode Tiket: " . bersihkan_header($kodeTiket) . "\n\n";
    if ($baseUrl !== '') {
        $isi .= "Lihat tiket Anda di:\n" . rtrim($baseUrl, '/') . "/mahasiswa.php?page=tiket\n\n";
    }
    $isi .= "Simpan email ini sebagai bukti pendaftaran.\n\nSalam,\nAcadeMeet";
    return [$subjek, $isi];
}

/**
 * Isi email pengingat H-1 (murni). Mengembalikan [subjek, isi].
 * TANPA kode tiket dan TANPA link online seminar.
 */
function isi_email_h1(string $baseUrl, string $nama, array $sem): array
{
    $subjek = 'Pengingat: ' . bersihkan_header((string) ($sem['judul'] ?? '')) . ' besok';
    $lokasi = trim((string) ($sem['lokasi'] ?? ''));
    if ($lokasi === '') {
        $lokasi = 'akan diumumkan';
    }
    $tgl = _email_jadwal((string) ($sem['tanggal'] ?? ''), $sem['jam_mulai'] ?? null, $sem['jam_selesai'] ?? null);

    $isi = "Halo " . bersihkan_header($nama) . ",\n\n"
        . "Pengingat: seminar berikut berlangsung BESOK.\n\n"
        . "Judul    : " . bersihkan_header((string) ($sem['judul'] ?? '')) . "\n"
        . "Tanggal  : " . $tgl . "\n"
        . "Lokasi   : " . bersihkan_header($lokasi) . "\n\n";
    if ($baseUrl !== '') {
        $isi .= "Detail tiket Anda:\n" . rtrim($baseUrl, '/') . "/mahasiswa.php?page=tiket\n\n";
    }
    $isi .= "Sampai jumpa!\n\nSalam,\nAcadeMeet";
    return [$subjek, $isi];
}
