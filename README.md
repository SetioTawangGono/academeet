# 📚 AcadeMeet

**Portal Seminar & Event Kampus** — sistem manajemen seminar berbasis web dengan dua peran: **Admin** (pengelola) dan **Mahasiswa** (peserta).

PHP Native + MySQL (XAMPP), UI **Bootstrap 5**, sertifikat **FPDF** dengan URL verifikasi, QR tiket dibuat di browser dengan **qrcodejs**, dan unduh jadwal `.ics` (RFC 5545).

---

## 📁 Struktur File

| File | Fungsi |
|------|--------|
| `bootstrap.php` | Fondasi bersama: session hardening, timezone, `e()`, CSRF, flash, `require_role()`, helper tiket/password/upload/pagination, validasi data diri (`validasi_data_diri()`, `prodi_terdaftar()`), jendela check-in, status efektif, header keamanan, timeout sesi (`idle_timeout_seconds()`, `sesi_kedaluwarsa()`), kunci throttle (`throttle_key()`). |
| `services.php` | Logika DB (transaksi): `batalkan_pendaftaran()`, `ubah_seminar()`, `buat_admin()`, throttle (`throttle_terlalu_banyak()`/`throttle_catat()`/`throttle_hapus()`), token reset (`buat_token_reset()`/`pakai_token_reset()`). Dipanggil `mahasiswa.php`, `admin.php`, `index.php`, `verifikasi.php`, `lupa_password.php`, `reset_password.php`, dan tes. |
| `index.php` | Login (throttle DB 3 kunci + throttle sesi, pesan generik, `session_regenerate_id`, rehash password, waktu respons diseragamkan). |
| `register.php` | Registrasi mahasiswa + validasi server + CSRF. |
| `logout.php` | Hapus session & cookie, redirect ke login. |
| `koneksi.php` | Koneksi MySQL via env (fallback `localhost`/`root`), charset `utf8mb4`. |
| `admin.php` | Dashboard admin multi-halaman via `?page=`. |
| `mahasiswa.php` | Portal mahasiswa: katalog, tiket, profil. |
| `lupa_password.php` | F5: form permintaan tautan reset (respons generik, throttle per email/IP). |
| `reset_password.php` | F5: halaman set password baru dari token (sekali pakai, 30 menit). |
| `mailer.php` | F5: kirim email via PHPMailer (`kirim_email()`, `isi_email_reset()`), konfigurasi dari env SMTP. |
| `sertifikat.php` | Preview & unduh sertifikat PDF (hanya jika `status_hadir='Hadir'`). Menyertakan URL verifikasi keaslian (F7). |
| `verifikasi.php` | Halaman publik verifikasi sertifikat berdasarkan kode tiket (throttle DB per IP + sesi). |
| `kalender.php` | Unduh `.ics` satu registrasi milik user (`?id=<id_registrasi>`, mahasiswa, owner-only) untuk "Tambah ke kalender". |
| `export_excel.php` | Ekspor peserta per seminar ke `.xls` (admin-only). |
| `fpdf.php` | Library FPDF. |
| `vendor/phpmailer/` | PHPMailer 7.1.1 (vendored, lihat `vendor/phpmailer/README.md`). |
| `database.sql` | Skema lengkap untuk instalasi baru (+ index & foreign key). |
| `migrasi.sql` | Migrasi idempoten untuk database yang sudah berjalan. |
| `tests/run.php` | Self-check helper tanpa dependency (`php tests/run.php`). |
| `tests/run_db.php` | Tes fungsi DB `services.php` (termasuk detail seminar, filter katalog, `.ics`). **Hanya** untuk database bernama `*_test` (mis. `academeet_paketc_test`); menolak berjalan di DB lain. |
| `tools/tutup_seminar.php` | CLI: tandai seminar `Aktif` yang tanggalnya lewat menjadi `Selesai`. |
| `tools/buat_admin.php` | CLI: buat akun admin (nama/email argumen/prompt, password prompt tersembunyi). |
| `uploads/.htaccess` | Blokir eksekusi skrip & directory listing di folder upload. |

---

## 🗄️ Database

Skema fresh install ada di `database.sql`; untuk DB lama jalankan `migrasi.sql` (aman diulang, index/FK dicek lewat `information_schema`).

```bash
mysql -u root < database.sql
# atau untuk DB yang sudah ada
mysql -u root academeet < migrasi.sql
```

> **Penting:** `migrasi.sql` mengawali dengan `USE academeet;`, jadi selalu menargetkan database live meski dijalankan dengan nama DB lain. Untuk menguji di database salinan, hapus baris `USE` pada salinan sementara lalu jalankan `mysql -D <nama_db_salinan> < salinan.sql`.
>
> ```powershell
> # Salin migrasi TANPA baris USE, lalu jalankan ke DB salinan (BUKAN live):
> Get-Content migrasi.sql | Where-Object { $_ -notmatch '^\s*USE\s+academeet\s*;' } | Set-Content "$env:TEMP\migrasi_salinan.sql"
> mysql -u root -D academeet_paketc_test < "$env:TEMP\migrasi_salinan.sql"
> ```
>
> Tanpa langkah itu, `USE academeet;` akan mengalihkan sesi ke database **live** dan menerapkan migrasi ke sana walaupun argumen CLI menyebut DB salinan.

Tabel: `users`, `pemateri`, `seminars`, `registrations`, `auth_throttle`, `password_resets` (maksimum 6 tabel; fitur baru sedapat mungkin menambah kolom/index pada tabel yang ada, bukan tabel baru).

Batasan penting:
- `registrations` punya `UNIQUE (id_user, id_seminar)` — satu mahasiswa satu tiket per seminar.
- `registrations.kode_tiket` unik, format `ACD-` + 6 hex acak.
- `users.nim` punya `UNIQUE KEY uq_users_nim` (NIM kosong disimpan `NULL`, boleh banyak NULL).
- `users.auth_version` (INT, default 0) naik saat password berubah/direset; dipakai membatalkan sesi lama.
- `auth_throttle` menyimpan percobaan (login/verifikasi/reset) sebagai `key_hash` SHA-256; baris >1 hari dibersihkan oportunistik (~1% request, tanpa cron).
- `password_resets` menyimpan token reset sebagai SHA-256 (sekali pakai, 30 menit, satu aktif per user, FK ke `users` `ON DELETE CASCADE`).
- Foreign key: registrasi → user/seminar (`ON DELETE CASCADE`), seminar → pemateri (`ON DELETE SET NULL`), password_resets → users (`ON DELETE CASCADE`).

Kolom `kuota_total` (di `seminars`) menyimpan kuota awal/total; kolom `kuota` menyimpan sisa kursi. Migrasi mengisinya dengan `kuota + jumlah pendaftar`. Bila ada NIM ganda, migrasi menampilkan `PERINGATAN: NIM ganda, UNIQUE tidak ditambahkan` dan melewati pembuatan indeks unik (tanpa mengubah data NIM).

### Detail seminar (Paket C)

`migrasi.sql` / `database.sql` menambahkan kolom detail ke `seminars` (semua opsional kecuali `kategori`; tanpa tabel baru):

| Kolom | Tipe | Catatan |
|-------|------|---------|
| `jam_mulai` | `TIME NULL` | Waktu mulai (zona Asia/Jakarta). |
| `jam_selesai` | `TIME NULL` | Waktu selesai; bila kosong ICS memakai mulai + 2 jam. |
| `lokasi` | `VARCHAR(150) NULL` | Lokasi fisik. |
| `link_online` | `VARCHAR(255) NULL` | Tautan acara daring. **Hanya** tampil di tiket peserta terdaftar (katalog hanya menampilkan badge "Online"). |
| `kategori` | `VARCHAR(30) NOT NULL DEFAULT 'Umum'` | Salah satu dari: Teknologi, Karier, Riset, Soft Skill, Kewirausahaan, Umum. |
| `deskripsi` | `TEXT NULL` | Ringkasan acara (maks 2000 karakter). |
| `idx_seminars_kategori` | index | Mempercepat filter katalog per kategori. |

Seminar lama otomatis menjadi `kategori='Umum'`, kolom lain `NULL`. Daftar kategori resmi di `kategori_seminar()`; nilai di luar daftar ditolak `validasi_detail_seminar()`.

### Kredensial database

`koneksi.php` membaca environment variable berikut (default untuk XAMPP):

| Env | Default |
|-----|---------|
| `ACADEMEET_DB_HOST` | `localhost` |
| `ACADEMEET_DB_USER` | `root` |
| `ACADEMEET_DB_PASS` | *(kosong)* |
| `ACADEMEET_DB_NAME` | `academeet` |

### Environment lain

| Env | Default | Fungsi |
|-----|---------|--------|
| `ACADEMEET_IDLE_ADMIN_MIN` | `60` | Timeout tidak aktif sesi admin (menit). |
| `ACADEMEET_IDLE_USER_MIN` | `240` | Timeout tidak aktif sesi mahasiswa (menit). |
| `ACADEMEET_BASE_URL` | *(wajib untuk F5 & F7)* | Basis URL absolut untuk tautan reset **dan** URL verifikasi di sertifikat (mis. `http://localhost/academeet`). Tautan **tidak** dibangun dari header `Host`. |
| `ACADEMEET_SMTP_HOST` | *(wajib untuk F5)* | Host SMTP (mis. `sandbox.smtp.mailtrap.io`). |
| `ACADEMEET_SMTP_PORT` | `587` | Port SMTP. |
| `ACADEMEET_SMTP_SECURE` | `tls` | Enkripsi: `tls` / `ssl` / *(kosong)*. |
| `ACADEMEET_SMTP_USER` | *(kosong)* | Username SMTP (kosong = tanpa auth). |
| `ACADEMEET_SMTP_PASS` | *(kosong)* | Password SMTP. **Jangan** di-commit. |
| `ACADEMEET_SMTP_FROM` | *(wajib untuk F5)* | Alamat pengirim. |

Bila `ACADEMEET_BASE_URL` atau SMTP belum diset, `kirim_email()` gagal dengan `error_log` (tanpa isi email) dan halaman tetap menampilkan respons generik.

#### Kredensial tanpa menyentuh repo

Env di atas bisa juga diletakkan di file **di luar repo** (default `C:\xampp\academeet-secrets.ini`, override dengan env `ACADEMEET_SECRETS_FILE`). Format tiap baris `KUNCI=nilai` (`#` untuk komentar). Env asli selalu menang atas file. Ini cara yang disarankan untuk password SMTP di XAMPP Windows (tak perlu ubah konfigurasi Apache, kredensial tak masuk git).

Contoh `C:\xampp\academeet-secrets.ini`:

```
ACADEMEET_BASE_URL=http://localhost/academeet
ACADEMEET_SMTP_HOST=sandbox.smtp.mailtrap.io
ACADEMEET_SMTP_PORT=587
ACADEMEET_SMTP_SECURE=tls
ACADEMEET_SMTP_FROM=noreply@academeet.local
ACADEMEET_SMTP_USER=isi-dari-mailtrap
ACADEMEET_SMTP_PASS=isi-dari-mailtrap
```

---

## 🔐 Keamanan

- Password `password_hash()` / `password_verify()`; password mahasiswa minimal 8 karakter, password admin minimal 12. Login sukses menjalankan `password_needs_rehash()`.
- Semua mutasi memakai **POST + token CSRF** (`csrf_field()` / `csrf_check()`); GET hanya untuk membaca.
- Query memakai **prepared statement**; output di-escape dengan `e()`.
- **Throttle di database** (`auth_throttle`, jendela geser 15 menit): login dibatasi per (email+IP) 5, per IP 30, per email global 20; verifikasi tiket 10 per IP; permintaan reset 3 per email & 10 per IP per jam. Kunci disimpan sebagai SHA-256; IP diambil dari `REMOTE_ADDR` (tidak mempercayai `X-Forwarded-For`). Throttle sesi lama tetap dipakai sebagai lapis kedua; bila query DB gagal, sistem `error_log` lalu jatuh ke throttle sesi.
- **Sesi**: cookie `HttpOnly`, `SameSite=Lax`, `Secure` saat HTTPS; ID diganti setelah login; timeout tidak aktif (admin 60 menit, mahasiswa 240 menit) dengan `session.gc_maxlifetime` diset ke batas terpanjang.
- **Pembatalan sesi**: `users.auth_version` disimpan di sesi saat login dan dicek `require_role()` tiap request; naik saat ganti password sendiri, reset oleh admin, atau reset via token. Akun yang dihapus langsung tak berlaku.
- Upload divalidasi dari isi file (`getimagesize`) dan ukuran, nama file di-random; folder `uploads/` diblokir dari eksekusi skrip lewat `.htaccess`.
  - Catatan deployment: proteksi ini butuh Apache dengan `AllowOverride` yang mengizinkan `.htaccess`. Di nginx atau `AllowOverride None`, nonaktifkan eksekusi PHP untuk folder `uploads/` di konfigurasi server.
- Export Excel menetralkan formula injection (`excel_cell()`).
- QR tiket dirender di browser (kode tiket tidak dikirim ke layanan pihak ketiga); avatar/placeholder dibuat lokal.
- **F5 lupa password**: token 32 byte acak, disimpan SHA-256, berlaku 30 menit, sekali pakai, satu aktif per user, hanya untuk role mahasiswa. Respons permintaan selalu sama (anti enumerasi email) dan waktu respons diseragamkan. Tautan dibangun dari `ACADEMEET_BASE_URL`, bukan header `Host`; halaman reset mengirim `Referrer-Policy: no-referrer` dan `Cache-Control: no-store`; tautan tidak pernah ditulis ke log.
- **Header keamanan HTTP** dikirim sekali di `bootstrap.php`: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(self), microphone=(), geolocation=()`, `Strict-Transport-Security` (hanya HTTPS), dan `Content-Security-Policy` (`default-src 'self'`; `script-src`/`style-src`/`font-src` mengizinkan `'unsafe-inline'` + `https://cdn.jsdelivr.net`; `img-src 'self' data: blob:`; `object-src 'none'`; `frame-ancestors 'self'`).

---

## ⚙️ Sistem Admin (`admin.php`)

| Parameter | Fitur |
|-----------|-------|
| `dashboard` | Statistik + daftar seminar (search & pagination server-side), aksi Edit/Selesai/Hapus. Status efektif: seminar bertanggal lampau dianggap Selesai dan tidak bisa diedit. Kolom Kategori + jam ditampilkan. |
| `tambah` | Tambah seminar: judul, pemateri, tanggal, jam mulai/selesai, kategori, lokasi, link online, deskripsi, kuota, poster. |
| `edit_seminar` | Edit seminar (hanya untuk seminar efektif Aktif). Kuota adalah **kuota total**; sisa = total − jumlah pendaftar, total tidak boleh kurang dari jumlah pendaftar. Detail (jam/kategori/lokasi/link/deskripsi) dapat diisi atau dikosongkan. Poster baru opsional. |
| `scan` | Check-in via kode tiket (manual atau scan kamera). Idempoten: tiket yang sudah hadir ditolak. |
| `mahasiswa` | Daftar mahasiswa (search & pagination), detail, hapus. |
| `detail_mahasiswa` | Profil, statistik, riwayat tiket, reset password. |
| `pemateri` | Daftar pemateri (search & pagination), tambah, detail, hapus. |
| `laporan` | Rekap pendaftar/hadir per seminar + export Excel. |

Penghapusan berjalan dalam transaksi:
- **Seminar** → registrasi + poster ikut dihapus.
- **Pemateri** → seminar terkait, registrasi, foto, dan poster ikut dihapus.
- **Mahasiswa** → registrasi dihapus, kuota seminar dikembalikan, foto profil dihapus.

---

## 🎓 Sistem Mahasiswa (`mahasiswa.php`)

| Parameter | Fitur |
|-----------|-------|
| `katalog` | Seminar aktif (`status='Aktif'`, tanggal belum lewat) + label "terisi/total" & badge "Penuh"; tombol daftar nonaktif saat kuota habis. Panel filter: kata kunci (judul/pemateri), kategori, rentang tanggal (`dari`/`sampai`), ketersediaan (masih tersedia/penuh), urutan; hasil dipaginasi server-side (9/halaman). Kategori & jam & lokasi/Online ditampilkan di kartu. |
| `tiket` | Tiket + QR, status kehadiran, unduh sertifikat bila sudah hadir, tombol **Batalkan** (seminar Aktif, tanggal > hari ini, tiket Belum Hadir), tombol **Tambah ke kalender** (`.ics`) untuk seminar Aktif, dan detail lengkap (kategori, jam, lokasi, deskripsi, tombol tautan online khusus peserta). |
| `profil` | Edit data diri (validasi sama dengan registrasi), upload foto (JPG/PNG/WebP maks 2MB), ubah password. |

Pendaftaran berjalan atomik (`SELECT ... FOR UPDATE`): kuota, status, tanggal, dan duplikasi dicek di dalam transaksi; kuota dikurangi hanya jika insert berhasil. Pembatalan (`batalkan_pendaftaran()`) juga atomik: baris tiket + seminar dikunci, lalu kuota dikembalikan dengan batas `LEAST(kuota+1, kuota_total)`.

Check-in hanya bisa pada hari H (zona Asia/Jakarta). Seminar yang tanggalnya sudah lewat dianggap Selesai saat dibaca (tanpa menulis DB); `tools/tutup_seminar.php` menyinkronkan status di DB via CLI.

---

## 🔄 Alur Kerja

1. Admin membuat pemateri, lalu seminar (termasuk kategori & detail jam/lokasi/deskripsi).
2. Mahasiswa mendaftar → mendapat kode tiket + QR.
3. Panitia check-in di menu **Scan** → status `Hadir`.
4. Mahasiswa mengunduh sertifikat PDF; peserta juga dapat menambahkan seminar ke kalender (`.ics`).
5. Siapa pun dapat memverifikasi sertifikat di `verifikasi.php?kode=ACD-XXXXXX` (URL ini juga tercetak di sertifikat).
6. Admin mengekspor laporan peserta ke Excel.

---

## 🚀 Cara Menjalankan

1. Letakkan folder di `C:\xampp\htdocs\`.
2. Nyalakan Apache & MySQL.
3. Import `database.sql` (instalasi baru) atau `migrasi.sql` (DB lama).
4. Sesuaikan env DB bila perlu.
5. Buka `http://localhost/academeet/`.

Akun admin pertama dibuat lewat CLI (tidak perlu SQL manual):

```bash
C:\xampp\php\php.exe tools\buat_admin.php
# atau: php tools/buat_admin.php "Nama Admin" admin@kampus.ac.id
```

Password ditanyakan lewat prompt tersembunyi (minimal 12 karakter) dan tidak boleh lewat argumen/env.

### Lupa password (F5)

Mahasiswa memakai **Lupa password?** di halaman login: isi email → tautan reset dikirim (bila `ACADEMEET_BASE_URL` + SMTP diset) → buka `reset_password.php?token=...` → buat password baru. Token berlaku 30 menit dan sekali pakai. Fitur ini hanya untuk role mahasiswa.

---

## 🧪 Tes

```bash
C:\xampp\php\php.exe tests/run.php
C:\xampp\php\php.exe -l admin.php   # cek sintaks

# Tes fungsi DB — HANYA di database *_test:
set ACADEMEET_DB_NAME=academeet_paketc_test
C:\xampp\php\php.exe tests/run_db.php
```

`tests/run_db.php` menolak berjalan pada DB yang namanya tidak berakhiran `_test`. Buat salinan dulu (mis. dari dump live), jalankan `migrasi.sql` padanya, lalu arahkan `ACADEMEET_DB_NAME` ke salinan itu. Tes membuat lalu membersihkan datanya sendiri (email berakhiran `@test.local`).
