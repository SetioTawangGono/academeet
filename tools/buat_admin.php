<?php
/**
 * Buat akun admin baru (CLI saja, tanpa SQL manual).
 *
 * Pakai:
 *   php tools/buat_admin.php
 *   php tools/buat_admin.php "Nama Admin" admin@kampus.ac.id
 *
 * Nama/email boleh lewat argumen; password SELALU lewat prompt interaktif
 * (di Windows memakai Read-Host -AsSecureString agar tidak terlihat).
 * Password TIDAK boleh lewat argumen CLI atau environment variable.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../services.php';

/** Baca input tanpa echo (Windows: PowerShell SecureString). Fallback: prompt biasa. */
function baca_password_tersembunyi(string $prompt): string
{
    if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
        $cmd = "powershell -NoProfile -Command "
            . "\"$p = Read-Host -Prompt '" . str_replace("'", "''", $prompt) . "' -AsSecureString; "
            . "[System.Runtime.InteropServices.Marshal]::PtrToStringAuto("
            . "[System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))\"";
        $out = shell_exec($cmd);
        if (is_string($out)) {
            return rtrim($out, "\r\n");
        }
    }
    fwrite(STDERR, "Peringatan: password akan terlihat saat diketik.\n");
    fwrite(STDOUT, $prompt);
    $line = fgets(STDIN);
    return $line === false ? '' : rtrim($line, "\r\n");
}

// Nama & email: dari argumen, atau prompt.
$nama = isset($argv[1]) ? trim((string) $argv[1]) : '';
if ($nama === '') {
    fwrite(STDOUT, 'Nama admin: ');
    $nama = rtrim((string) fgets(STDIN), "\r\n");
}

$email = isset($argv[2]) ? trim((string) $argv[2]) : '';
if ($email === '') {
    fwrite(STDOUT, 'Email admin: ');
    $email = rtrim((string) fgets(STDIN), "\r\n");
}

$password = baca_password_tersembunyi('Password admin (min. 12 karakter)');
$ulang = baca_password_tersembunyi('Ulangi password');

if ($password === '' || $password !== $ulang) {
    fwrite(STDERR, "Gagal: password kosong atau kedua isian tidak cocok.\n");
    exit(1);
}

[$tipe, $pesan, $id] = buat_admin($conn, $nama, $email, $password);

if ($tipe !== 'success') {
    fwrite(STDERR, "Gagal: {$pesan}\n");
    exit(1);
}

fwrite(STDOUT, "Berhasil: {$pesan} (id_user={$id}, email={$email})\n");
