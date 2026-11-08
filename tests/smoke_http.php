<?php
// Uji asap HTTP end-to-end: memastikan setiap halaman & handler tulis tidak fatal,
// terutama handler yang memanggil fungsi services.php. HANYA untuk database salinan.
// Jalankan: set ACADEMEET_DB_NAME=academeet_e1_test lalu php tests/smoke_http.php
require __DIR__ . '/../bootstrap.php';

$dbName = getenv('ACADEMEET_DB_NAME') ?: 'academeet';
if (!str_ends_with($dbName, '_test')) {
    fwrite(STDERR, "DITOLAK: tes ini hanya boleh dijalankan pada database *_test (sekarang: {$dbName}).\n");
    exit(1);
}

require __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../services.php';

$dbAktif = (string) (mysqli_fetch_assoc(mysqli_query($conn, 'SELECT DATABASE()'))['DATABASE()'] ?? '');
echo "DB uji: {$dbAktif}\n";

$failed = 0;
$check = static function (string $name, bool $ok) use (&$failed): void {
    if (!$ok) {
        $failed++;
        echo "FAIL: {$name}\n";
    } else {
        echo "ok:   {$name}\n";
    }
};

$ERROR_POLA = ['Fatal error', 'Call to undefined', 'Warning:', 'Notice:', 'Deprecated:', 'Parse error'];
$tanpaError = static function (string $body) use ($ERROR_POLA): bool {
    foreach ($ERROR_POLA as $p) {
        if (str_contains($body, $p)) {
            return false;
        }
    }
    return true;
};

// ---------- Server uji (proses anak php -S) ----------
$root = realpath(__DIR__ . '/..');
$port = 8100 + random_int(0, 800);
$sockTest = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
if ($sockTest) {
    fclose($sockTest);
    fwrite(STDERR, "DITOLAK: port {$port} sedang dipakai. Coba lagi.\n");
    exit(1);
}
$tmpDir = sys_get_temp_dir();
$srvOut = $tmpDir . '/acm_smoke_srv_out_' . getmypid() . '.log';
$srvErr = $tmpDir . '/acm_smoke_srv_err_' . getmypid() . '.log';
$envSrv = null;
$cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root, '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL'];
$descriptor = [1 => ['file', $srvOut, 'a'], 2 => ['file', $srvErr, 'a']];
$proc = proc_open($cmd, $descriptor, $pipes, $root, $envSrv);
if (!is_resource($proc)) {
    fwrite(STDERR, "GAGAL menjalankan server uji.\n");
    exit(1);
}
$base = 'http://127.0.0.1:' . $port;

// ---------- HTTP client ----------
$jar = $tmpDir . '/acm_smoke_cookie_' . getmypid() . '.txt';
@unlink($jar);
$http = static function (string $url, array $opt = []) use ($jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HEADER => true,
    ] + $opt);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsz = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $raw = (string) $raw;
    return [$code, substr($raw, $hsz), substr($raw, 0, $hsz)];
};
$csrf = null;
$ambilCsrf = static function (string $body): ?string {
    return preg_match('/name="_csrf" value="([^"]+)"/', $body, $m) ? $m[1] : null;
};
$login = static function (string $email, string $password) use ($http, $base, $ambilCsrf, &$csrf): array {
    [$c, $b] = $http("{$base}/index.php");
    $tok = $ambilCsrf($b);
    if ($tok === null) {
        return [0, ''];
    }
    $csrf = $tok;
    [$c, $b, $h] = $http("{$base}/index.php", [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['_csrf' => $tok, 'login' => '1', 'email' => $email, 'password' => $password]),
    ]);
    return [$c, $h];
};
$logout = static function () use ($http, $base, $jar): void {
    // Hapus cookie jar agar sesi bersih antar peran.
    @unlink($jar);
};

try {
    // Tunggu server siap.
    $siap = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(150000);
        $s = @fsockopen('127.0.0.1', $port, $e, $es, 0.3);
        if ($s) {
            fclose($s);
            $siap = true;
            break;
        }
    }
    if (!$siap) {
        $dbg = is_file($srvErr) ? (string) file_get_contents($srvErr) : '(kosong)';
        throw new RuntimeException('Server uji tidak siap. stderr: ' . trim($dbg));
    }

    // ---------- Akun uji acak ----------
    $tag = bin2hex(random_bytes(4));
    $passAdmin = bin2hex(random_bytes(16));
    $passPanitia = bin2hex(random_bytes(16));
    $passMhs = bin2hex(random_bytes(16));
    $emailAdmin = "smk_admin_{$tag}@example.com";
    $emailPanitia = "smk_panitia_{$tag}@example.com";
    $emailMhs = "smk_mhs_{$tag}@example.com";

    [$t, $m] = buat_admin($conn, 'Admin Smoke', $emailAdmin, $passAdmin);
    $check('setup buat_admin', $t === 'success');
    [$t, $m] = buat_panitia($conn, 'Panitia Smoke', $emailPanitia, $passPanitia);
    $check('setup buat_panitia', $t === 'success');
    $hashMhs = password_hash($passMhs, PASSWORD_DEFAULT);
    $ins = mysqli_prepare($conn, "INSERT INTO users (nama_lengkap, email, password, role) VALUES ('Mhs Smoke', ?, ?, 'mahasiswa')");
    mysqli_stmt_bind_param($ins, 'ss', $emailMhs, $hashMhs);
    mysqli_stmt_execute($ins);
    $idMhs = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($ins);
    $check('setup akun mahasiswa', $idMhs > 0);

    // Seminar uji hari ini + pemateri uji.
    $pem = mysqli_prepare($conn, "INSERT INTO pemateri (nama, keahlian) VALUES ('Pemateri Smoke', 'Uji')");
    mysqli_stmt_execute($pem);
    $idPemateri = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($pem);
    $hariIni = date('Y-m-d');
    $sem = mysqli_prepare($conn, "INSERT INTO seminars (judul, id_pemateri, pemateri, tanggal, jam_mulai, jam_selesai, lokasi, kategori, deskripsi, kuota, kuota_total, status, ttd_nama, ttd_jabatan) VALUES (?, ?, 'Pemateri Smoke', ?, '09:00:00', '11:00:00', 'Ruang Smoke', 'Umum', 'Uji smoke', 10, 10, 'Aktif', 'Nama TTD', 'Jabatan TTD')");
    mysqli_stmt_bind_param($sem, 'sis', $judulSem, $idPemateri, $hariIni);
    $judulSem = "Seminar Smoke {$tag}";
    mysqli_stmt_execute($sem);
    $idSeminar = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($sem);
    $check('setup seminar uji hari ini', $idSeminar > 0);

    // ============================================================
    // ADMIN
    // ============================================================
    [$c, $h] = $login($emailAdmin, $passAdmin);
    $check('login admin', $c === 302 && str_contains($h, 'admin.php'));

    $getAdmin = [
        'dashboard' => '/admin.php?page=dashboard',
        'laporan' => '/admin.php?page=laporan',
        'mahasiswa daftar' => '/admin.php?page=mahasiswa',
        'mahasiswa cari' => '/admin.php?page=mahasiswa&cari=a',
        'pemateri' => '/admin.php?page=pemateri',
        'tambah' => '/admin.php?page=tambah',
        'scan' => '/admin.php?page=scan',
        'peserta pemilih' => '/admin.php?page=peserta',
        'peserta id' => '/admin.php?page=peserta&id=' . $idSeminar,
        'panitia' => '/admin.php?page=panitia',
        'impor_mahasiswa' => '/admin.php?page=impor_mahasiswa',
        'edit_seminar' => '/admin.php?page=edit_seminar&id=' . $idSeminar,
        'ganti_password' => '/ganti_password.php',
    ];
    foreach ($getAdmin as $nama => $path) {
        [$c, $b] = $http($base . $path);
        $check("ADMIN GET {$nama} 200 & bersih", $c === 200 && $tanpaError($b));
    }

    // POST edit_seminar dengan ttd terisi.
    [$c, $b] = $http("{$base}/admin.php?page=edit_seminar&id={$idSeminar}");
    $tok = $ambilCsrf($b);
    $fields = [
        '_csrf' => (string) $tok, 'edit_seminar' => '1', 'id_seminar' => (string) $idSeminar,
        'judul' => $judulSem, 'id_pemateri' => (string) $idPemateri, 'tanggal' => $hariIni,
        'kuota_total' => '10', 'jam_mulai' => '', 'jam_selesai' => '', 'lokasi' => 'Ruang Smoke',
        'link_online' => '', 'kategori' => 'Umum', 'deskripsi' => 'Uji smoke',
        'ttd_nama' => 'TTD Baru', 'ttd_jabatan' => 'Jabatan Baru',
    ];
    [$c, $b, $h] = $http("{$base}/admin.php?page=edit_seminar&id={$idSeminar}", [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields,
    ]);
    $check('ADMIN POST edit_seminar 302 & tanpa fatal', $c === 302 && $tanpaError($b));
    $rowTtd = mysqli_fetch_assoc(mysqli_query($conn, "SELECT ttd_nama, ttd_jabatan FROM seminars WHERE id_seminar = {$idSeminar}"));
    $check('ADMIN edit_seminar ttd terisi tersimpan', ($rowTtd['ttd_nama'] ?? '') === 'TTD Baru' && ($rowTtd['ttd_jabatan'] ?? '') === 'Jabatan Baru');

    // POST edit_seminar, ttd dikosongkan.
    [$c, $b] = $http("{$base}/admin.php?page=edit_seminar&id={$idSeminar}");
    $fields['_csrf'] = (string) $ambilCsrf($b);
    $fields['ttd_nama'] = '';
    $fields['ttd_jabatan'] = '';
    [$c, $b] = $http("{$base}/admin.php?page=edit_seminar&id={$idSeminar}", [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields,
    ]);
    $rowTtd = mysqli_fetch_assoc(mysqli_query($conn, "SELECT ttd_nama FROM seminars WHERE id_seminar = {$idSeminar}"));
    $check('ADMIN edit_seminar ttd dikosongkan -> NULL', $c === 302 && $rowTtd['ttd_nama'] === null);

    // Impor CSV: pratinjau dengan 1 duplikat di dalam berkas -> kesalahan, tombol impor TIDAK ada.
    [$c, $b] = $http("{$base}/admin.php?page=impor_mahasiswa");
    $tokImpor = (string) $ambilCsrf($b);
    $csvFile = $tmpDir . '/acm_smoke_dupe_' . getmypid() . '.csv';
    file_put_contents($csvFile, "nim,nama,prodi,email\n${tag}A1,A,Teknik Informatika,dup_{$tag}@example.com\n${tag}B1,B,Teknik Informatika,dup_{$tag}@example.com\n");
    [$c, $b, $h] = $http("{$base}/admin.php?page=impor_mahasiswa", [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['_csrf' => $tokImpor, 'impor_pratinjau' => '1', 'csv' => new CURLFile($csvFile, 'text/csv', 'dupe.csv')],
    ]);
    [$c, $b] = $http("{$base}/admin.php?page=impor_mahasiswa");
    $check('ADMIN impor pratinjau duplikat -> pesan kesalahan', str_contains($b, 'kesalahan ditemukan'));
    $check('ADMIN impor duplikat -> tombol impor TIDAK ada', !str_contains($b, 'impor_terapkan'));

    // Impor CSV valid -> pratinjau sukses -> konfirmasi -> N akun wajib_ganti_password=1.
    $csvOk = $tmpDir . '/acm_smoke_ok_' . getmypid() . '.csv';
    $em1 = "imp1_{$tag}@example.com";
    $em2 = "imp2_{$tag}@example.com";
    file_put_contents($csvOk, "nim,nama,prodi,email\n${tag}I1,Impor Satu,Teknik Informatika,{$em1}\n${tag}I2,Impor Dua,Teknik Informatika,{$em2}\n");
    [$c, $b] = $http("{$base}/admin.php?page=impor_mahasiswa");
    $tokOk = (string) $ambilCsrf($b);
    [$c, $b, $h] = $http("{$base}/admin.php?page=impor_mahasiswa", [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['_csrf' => $tokOk, 'impor_pratinjau' => '1', 'csv' => new CURLFile($csvOk, 'text/csv', 'ok.csv')],
    ]);
    [$c, $b] = $http("{$base}/admin.php?page=impor_mahasiswa");
    $check('ADMIN impor CSV valid -> pratinjau siap', str_contains($b, 'siap diimpor') && str_contains($b, 'impor_terapkan'));
    // Ambil token impor dari form.
    preg_match('/name="impor_token" value="([^"]+)"/', $b, $mt);
    $imporToken = $mt[1] ?? '';
    $passAwal = $imporToken !== '' ? bin2hex(random_bytes(12)) : '';
    $tokTerapkan = (string) $ambilCsrf($b);
    [$c, $b, $h] = $http("{$base}/admin.php?page=impor_mahasiswa", [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['_csrf' => $tokTerapkan, 'impor_terapkan' => '1', 'impor_token' => $imporToken, 'password_awal' => $passAwal],
    ]);
    $nBaru = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM users WHERE email IN ('{$em1}','{$em2}') AND wajib_ganti_password = 1"))['c'];
    $check('ADMIN impor terapkan -> 2 akun wajib_ganti_password=1', $nBaru === 2);

    // checkin_manual + batalkan_checkin untuk seminar uji hari ini.
    $idReg = 0;
    $regIns = mysqli_prepare($conn, "INSERT INTO registrations (id_user, id_seminar, kode_tiket, status_hadir) VALUES (?, ?, ?, 'Belum Hadir')");
    $kodeTiket = 'SMK' . strtoupper($tag);
    mysqli_stmt_bind_param($regIns, 'iis', $idMhs, $idSeminar, $kodeTiket);
    mysqli_stmt_execute($regIns);
    $idReg = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($regIns);
    [$c, $b] = $http("{$base}/admin.php?page=peserta&id={$idSeminar}");
    $tokP = (string) $ambilCsrf($b);
    [$c, $b, $h] = $http("{$base}/admin.php?page=peserta&id={$idSeminar}", [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['_csrf' => $tokP, 'checkin_manual' => '1', 'id_registrasi' => (string) $idReg, 'id_seminar' => (string) $idSeminar],
    ]);
    $st = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status_hadir FROM registrations WHERE id_registrasi = {$idReg}"));
    $check('ADMIN checkin_manual 302 & status Hadir', $c === 302 && ($st['status_hadir'] ?? '') === 'Hadir');
    [$c, $b] = $http("{$base}/admin.php?page=peserta&id={$idSeminar}");
    $tokB = (string) $ambilCsrf($b);
    [$c, $b, $h] = $http("{$base}/admin.php?page=peserta&id={$idSeminar}", [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['_csrf' => $tokB, 'batalkan_checkin' => '1', 'id_registrasi' => (string) $idReg, 'id_seminar' => (string) $idSeminar],
    ]);
    $st = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status_hadir FROM registrations WHERE id_registrasi = {$idReg}"));
    $check('ADMIN batalkan_checkin 302 & status Belum Hadir', $c === 302 && ($st['status_hadir'] ?? '') === 'Belum Hadir');

    // Panitia: buat / reset / hapus.
    [$c, $b] = $http("{$base}/admin.php?page=panitia");
    $tokPan = (string) $ambilCsrf($b);
    $emailPanBaru = "smk_pan2_{$tag}@example.com";
    [$c, $b, $h] = $http("{$base}/admin.php?page=panitia", [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['_csrf' => $tokPan, 'tambah_panitia' => '1', 'nama' => 'Panitia Baru', 'email' => $emailPanBaru, 'password' => bin2hex(random_bytes(12))],
    ]);
    $idPanBaru = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT id_user FROM users WHERE email = '{$emailPanBaru}'"))['id_user'] ?? 0);
    $check('ADMIN tambah_panitia 302 & akun dibuat', $c === 302 && $idPanBaru > 0);
    if ($idPanBaru > 0) {
        [$c, $b] = $http("{$base}/admin.php?page=panitia");
        $tokRp = (string) $ambilCsrf($b);
        [$c, $b, $h] = $http("{$base}/admin.php?page=panitia", [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['_csrf' => $tokRp, 'reset_password_panitia' => '1', 'id_user' => (string) $idPanBaru, 'password_baru' => bin2hex(random_bytes(12))],
        ]);
        $check('ADMIN reset_password_panitia 302', $c === 302);
        [$c, $b] = $http("{$base}/admin.php?page=panitia");
        $tokHp = (string) $ambilCsrf($b);
        [$c, $b, $h] = $http("{$base}/admin.php?page=panitia", [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['_csrf' => $tokHp, 'hapus_panitia' => '1', 'id_user' => (string) $idPanBaru],
        ]);
        $sisa = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM users WHERE id_user = {$idPanBaru}"))['c'];
        $check('ADMIN hapus_panitia 302 & akun hilang', $c === 302 && $sisa === 0);
    }

    // ---------- PANITIA ----------
    $logout();
    [$c, $h] = $login($emailPanitia, $passPanitia);
    $check('login panitia', $c === 302 && str_contains($h, 'admin.php?page=scan'));
    // Panitia baru wajib ganti password dulu.
    [$c, $b] = $http("{$base}/ganti_password.php");
    $tokGpPan = $ambilCsrf($b);
    $passPanBaru = bin2hex(random_bytes(12));
    if ($tokGpPan !== null) {
        $http("{$base}/ganti_password.php", [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['_csrf' => $tokGpPan, 'ganti' => '1', 'password_baru' => $passPanBaru, 'password_confirm' => $passPanBaru],
        ]);
    }
    $flagPan = mysqli_fetch_assoc(mysqli_query($conn, "SELECT wajib_ganti_password FROM users WHERE email = '{$emailPanitia}'"));
    $check('PANITIA ganti password wajib selesai', ((int) ($flagPan['wajib_ganti_password'] ?? 1)) === 0);
    [$c, $b] = $http("{$base}/admin.php?page=scan");
    $check('PANITIA scan 200 & bersih', $c === 200 && $tanpaError($b));
    [$c, $b] = $http("{$base}/admin.php?page=peserta&id={$idSeminar}");
    $check('PANITIA peserta 200 & bersih', $c === 200 && $tanpaError($b));
    [$c, $b] = $http("{$base}/admin.php?page=mahasiswa");
    $check('PANITIA halaman admin-only 403', $c === 403);
    [$c, $b] = $http("{$base}/admin.php?page=peserta&id={$idSeminar}", [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['hapus' => '1', 'id_seminar' => (string) $idSeminar],
    ]);
    $check('PANITIA POST aksi admin-only 403', $c === 403);

    // ---------- MAHASISWA ----------
    $logout();
    // Akun hasil impor punya wajib_ganti_password=1; uji jalur wajib ganti password.
    [$c, $h] = $login($em1, $passAwal);
    $check('login mahasiswa hasil impor', $c === 302);
    [$c, $b] = $http("{$base}/mahasiswa.php");
    $adaKatalog = str_contains($b, 'ganti_password') || $c === 302;
    $check('MAHASISWA wajib ganti password diarahkan', $adaKatalog);
    // Ganti password wajib.
    [$c, $b] = $http("{$base}/ganti_password.php");
    $tokGp = $ambilCsrf($b);
    $passBaru = bin2hex(random_bytes(12));
    if ($tokGp !== null) {
        [$c, $b, $h] = $http("{$base}/ganti_password.php", [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['_csrf' => $tokGp, 'ganti' => '1', 'password_baru' => $passBaru, 'password_confirm' => $passBaru],
        ]);
    }
    $flag = mysqli_fetch_assoc(mysqli_query($conn, "SELECT wajib_ganti_password FROM users WHERE email = '{$em1}'"));
    $check('MAHASISWA ganti_password wajib 302 & flag turun', ((int) ($flag['wajib_ganti_password'] ?? 1)) === 0);
    [$c, $b] = $http("{$base}/mahasiswa.php");
    $check('MAHASISWA katalog 200 & bersih setelah ganti', $c === 200 && $tanpaError($b));

    $logout();
    [$c, $h] = $login($emailMhs, $passMhs);
    [$c, $b] = $http("{$base}/mahasiswa.php");
    $check('MAHASISWA katalog 200 & bersih', $c === 200 && $tanpaError($b));
    [$c, $b] = $http("{$base}/mahasiswa.php?page=tiket");
    $check('MAHASISWA tiket 200 & bersih', $c === 200 && $tanpaError($b));
    [$c, $b] = $http("{$base}/mahasiswa.php?page=profil");
    $check('MAHASISWA profil 200 & bersih', $c === 200 && $tanpaError($b));

    // ---------- Pemeriksaan statis: pemanggil fungsi services ----------
    $src = file_get_contents(__DIR__ . '/../services.php');
    $toks = token_get_all($src);
    $fungsi = [];
    $n = count($toks);
    for ($i = 0; $i < $n; $i++) {
        if (is_array($toks[$i]) && $toks[$i][0] === T_FUNCTION) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_array($toks[$j]) && $toks[$j][0] === T_STRING) {
                    $fungsi[$toks[$j][1]] = true;
                    break;
                }
                if (!is_array($toks[$j]) || $toks[$j][0] !== T_WHITESPACE) {
                    break;
                }
            }
        }
    }
    $entri = glob(__DIR__ . '/../*.php');
    $entri[] = __DIR__ . '/../tools/pengingat_h1.php';
    $entri[] = __DIR__ . '/../tools/buat_admin.php';
    $takAman = [];
    foreach ($entri as $file) {
        $isi = file_get_contents($file);
        // File yang memuat bootstrap (langsung) atau services dianggap aman.
        $punyaBootstrap = str_contains($isi, 'bootstrap.php') || str_contains($isi, 'services.php');
        if (!$punyaBootstrap) {
            continue;
        }
        foreach ($fungsi as $fn => $_) {
            if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/', $isi)) {
                // pastikan pemanggilan tidak dibatasi require bersyarat tak aman (heuristik sederhana).
                $aman = str_contains($isi, "require_once __DIR__ . '/services.php'")
                    || str_contains($isi, "require_once __DIR__ . '/../services.php'")
                    || str_contains($isi, 'bootstrap.php');
                if (!$aman) {
                    $takAman[] = basename($file) . " -> {$fn}()";
                }
            }
        }
    }
    $check('STATIS: semua pemanggil services memuat bootstrap/services', $takAman === []);
    if ($takAman !== []) {
        foreach ($takAman as $t) {
            echo "   - {$t}\n";
        }
    }
} catch (Throwable $ex) {
    $failed++;
    echo 'FAIL: pengecualian uji: ' . $ex->getMessage() . "\n";
} finally {
    if (isset($proc) && is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    @unlink($jar);
    // Pindai stderr server untuk error PHP.
    $errIsi = is_file($srvErr) ? (string) file_get_contents($srvErr) : '';
    $adaErr = false;
    foreach (explode("\n", $errIsi) as $line) {
        if (preg_match('/PHP (Fatal error|Warning|Notice|Deprecated|Parse error)/', $line)) {
            $adaErr = true;
            echo 'SERVER STDERR: ' . trim($line) . "\n";
        }
    }
    if ($adaErr) {
        $failed++;
        echo "FAIL: stderr server mengandung error PHP\n";
    } else {
        echo "ok:   stderr server bersih dari error PHP\n";
    }
    @unlink($srvOut);
    @unlink($srvErr);
    if (isset($csvFile)) {
        @unlink($csvFile);
    }
    if (isset($csvOk)) {
        @unlink($csvOk);
    }
}

if ($failed > 0) {
    echo "\nADA {$failed} KEGAGALAN.\n";
    exit(1);
}
echo "\nSemua tes smoke HTTP lulus.\n";
