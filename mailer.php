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
 */
function kirim_email(string $ke, string $subjek, string $teks): bool
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
