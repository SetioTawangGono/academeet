<?php
// Kredensial bisa dioverride lewat environment variable (produksi).
$host = getenv('ACADEMEET_DB_HOST') ?: 'localhost';
$user = getenv('ACADEMEET_DB_USER') ?: 'root';
$pass = getenv('ACADEMEET_DB_PASS') ?: '';
$db   = getenv('ACADEMEET_DB_NAME') ?: 'academeet';

$conn = @mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    error_log('Koneksi database gagal: ' . mysqli_connect_error());
    http_response_code(500);
    die('Koneksi database gagal. Hubungi administrator.');
}

mysqli_set_charset($conn, 'utf8mb4');
