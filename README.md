# 📚 AcadeMeet

**Portal Seminar & Event Kampus** — sistem manajemen seminar berbasis web dengan peran **Admin** (pengelola), **Panitia** (check-in lapangan, akses terbatas), dan **Mahasiswa** (peserta).

PHP Native + MySQL (XAMPP), UI **Bootstrap 5**, sertifikat **FPDF** dengan URL verifikasi, QR tiket dibuat di browser dengan **qrcodejs**, ekspor laporan **`.xlsx`/CSV**, grafik dashboard **area chart SVG server-side + JS vanilla** (tanpa library/CDN baru), dan email ringan (konfirmasi + pengingat H-1 via CLI) tanpa antrean.

---

## 📁 Struktur File

| File | Fungsi |
|------|--------|
| `bootstrap.php` | Fondasi bersama: session hardening, timezone, `e()`, CSRF, flash, `require_role()`, helper tiket/password/upload/pagination, validasi data diri (`validasi_data_diri()`, `prodi_terdaftar()`), jendela check-in, status efektif, header keamanan, timeout sesi (`idle_timeout_seconds()`, `sesi_kedaluwarsa()`), kunci throttle (`throttle_key()`). Juga helper Paket E1: izin peran (`role_boleh_halaman()`, `role_boleh_aksi()`), aturan password per peran (`password_min_untuk_role()`, `password_ok_untuk_role()`, `password_max_bytes()`), parse/validasi CSV (`parse_csv_mahasiswa()`, `validasi_baris_impor()`, `csv_sel_bahaya()`), ttd sertifikat (`validasi_ttd()`, `muat_teks()`). |
| `services.php` | Logika DB (transaksi): `batalkan_pendaftaran()`, `ubah_seminar()`, `buat_admin()`, throttle (`throttle_terlalu_banyak()`/`throttle_catat()`/`throttle_hapus()`), token reset (`buat_token_reset()`/`pakai_token_reset()`), dan Paket E1: `checkin_manual()`, `batalkan_checkin()`, `buat_panitia()`/`reset_password_panitia()`/`hapus_panitia()`, `ganti_password_wajib()`, `pratinjau_impor_csv()`/`terapkan_impor()`. Dipanggil `mahasiswa.php`, `admin.php`, `index.php`, `verifikasi.php`, `lupa_password.php`, `reset_password.php`, dan tes. |
| `index.php` | Login (throttle DB 3 kunci + throttle sesi, pesan generik, `session_regenerate_id`, rehash password, waktu respons diseragamkan). |
| `register.php` | Registrasi mahasiswa + validasi server + CSRF. |
| `logout.php` | Hapus session & cookie, redirect ke login. |
| `koneksi.php` | Koneksi MySQL via env (fallback `localhost`/`root`), charset `utf8mb4`. |
| `admin.php` | Dashboard admin multi-halaman via `?page=`. Sejak Paket E1: juga dipakai peran **Panitia** dengan daftar halaman & aksi default-deny (hanya `scan`/`peserta` + check-in manual), plus halaman `panitia` (kelola akun panitia) dan `impor_mahasiswa` (admin-only). |
| `ganti_password.php` | Paket E1: halaman wajib ganti password (semua peran). Akun ber-`wajib_ganti_password=1` dialihkan ke sini sebelum bisa membuka halaman lain. |
| `grafik.php` | Grafik dashboard (F12): helper murni (`serie_harian()`, `monotone_path()` Fritsch–Carlson, `skala_y_ramah()`, `pilih_label_x()`, `nama_bulan_singkat()`) + render `grafik_area_html()` (kartu area bertumpuk) dan `grafik_daftar_html()` (baris ringkas). Tanpa library. |
| `grafik.js` | Interaksi grafik area (vanilla): dropdown rentang, tooltip lewat `textContent`, titik aktif, tombol panah/Home/End/Esc, `aria-live`. Dimuat `<script src="grafik.js?v=…" defer>`. |
| `mahasiswa.php` | Portal mahasiswa: katalog, tiket, profil. |
| `lupa_password.php` | F5: form permintaan tautan reset (respons generik, throttle per email/IP). |
| `reset_password.php` | F5: halaman set password baru dari token (sekali pakai, 30 menit). |
| `mailer.php` | F5: kirim email via PHPMailer (`kirim_email()`, `isi_email_reset()`), konfigurasi dari env SMTP. |
| `sertifikat.php` | Preview & unduh sertifikat PDF (hanya jika `status_hadir='Hadir'`). Menyertakan URL verifikasi keaslian (F7). Sejak Paket E1 memakai `seminars.ttd_nama`/`ttd_jabatan` bila terisi, atau teks bawaan bila kosong. |
| `verifikasi.php` | Halaman publik verifikasi sertifikat berdasarkan kode tiket (throttle DB per IP + sesi). |
| `kalender.php` | Unduh `.ics` satu registrasi milik user (`?id=<id_registrasi>`, mahasiswa, owner-only) untuk "Tambah ke kalender". |
| `export_excel.php` | Ekspor peserta per seminar sebagai **`.xlsx` asli** (penulis minimal `ZipArchive`, tanpa library; NIM tersimpan sebagai teks) atau **CSV UTF-8** via `?format=xlsx|csv` (default `xlsx`), admin-only. |
| `fpdf.php` | Library FPDF. |
| `vendor/phpmailer/` | PHPMailer 7.1.1 (vendored, lihat `vendor/phpmailer/README.md`). |
| `database.sql` | Skema lengkap untuk instalasi baru (+ index & foreign key). |
| `migrasi.sql` | Migrasi idempoten untuk database yang sudah berjalan (tidak memilih database; sebut nama DB di CLI). |
| `tests/run.php` | Self-check helper tanpa dependency (`php tests/run.php`). |
| `tests/run_db.php` | Tes fungsi DB `services.php` (termasuk detail seminar, filter katalog, `.ics`, pengingat H-1, grafik). **Hanya** untuk database bernama `*_test`; menolak berjalan di DB lain. |
| `tests/smoke_http.php` | Tes asap HTTP end-to-end (menjalankan `php -S`, login peran admin/panitia/mahasiswa, uji handler tulis). **Hanya** untuk database bernama `*_test`. |
| `tools/tutup_seminar.php` | CLI: tandai seminar `Aktif` yang tanggalnya lewat menjadi `Selesai`. |
| `tools/pengingat_h1.php` | CLI: kirim pengingat H-1 (email) untuk seminar `Aktif` yang berlangsung besok. CLI-only (ditolak via web). Lihat bagian Email. |
| `tools/buat_admin.php` | CLI: buat akun admin (nama/email argumen/prompt, password prompt tersembunyi). |
| `uploads/.htaccess` | Blokir eksekusi skrip & directory listing di folder upload. |

---

## 🗄️ Database

Skema fresh install ada di `database.sql`; untuk DB lama jalankan `migrasi.sql` (aman diulang, index/FK/kolom dicek lewat `information_schema`).

```bash
mysql -u root < database.sql          # instalasi baru (database.sql membuat & memilih db academeet)
mysql -u root academeet < migrasi.sql # DB lama — migrasi.sql TIDAK memilih database; sebut nama db di CLI
```

> **Penting:** mulai Paket D, `migrasi.sql` **tidak** memuat baris `USE`; database tujuan ditentukan dari baris perintah (`mysql ... <db> < migrasi.sql`). Tanpa nama database, MySQL gagal `No database selected` dan tidak menyentuh database mana pun. Untuk menguji di database salinan cukup sebut namanya (`-D academeet_paketd_test`); DB live tidak akan tersentuh.

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

### Kolom Paket D (laporan & komunikasi)

`migrasi.sql` / `database.sql` menambahkan **empat kolom** (tanpa tabel baru; batas tetap **maksimum 6 tabel**):

| Kolom | Tipe | Catatan |
|-------|------|---------|
| `registrations.h1_terkirim_at` | `DATETIME NULL` | Penanda pengingat H-1 sudah dikirim (klaim atomik; di-`NULL`-kan lagi bila kirim gagal atau tanggal seminar berubah). |
| `registrations.created_at` | `DATETIME NULL DEFAULT CURRENT_TIMESTAMP` | Waktu pendaftaran untuk grafik "Pendaftar Seminar" (per hari). **Baris lama dibiarkan `NULL`** (tidak di-backfill); baris baru terisi otomatis. |
| `users.notif_email` | `TINYINT(1) NOT NULL DEFAULT 1` | Preferensi pengingat email (checkbox di halaman profil). Hanya memengaruhi pengingat H-1; email konfirmasi tetap dikirim. |
| `seminars.jadwal_diubah_at` | `DATETIME NULL` | Diisi saat jadwal (tanggal/jam/lokasi/link) berubah → pita "Jadwal diperbarui" di tiket peserta. |

`created_at` ditambahkan **tanpa** default lebih dulu, lalu `ALTER ... SET DEFAULT CURRENT_TIMESTAMP` terpisah, agar baris lama tetap `NULL` saat migrasi. Keduanya idempoten (dicek via `information_schema`).

### Kolom Paket E1 (operasional admin)

`migrasi.sql` / `database.sql` menambahkan **lima perubahan** (tanpa tabel baru; batas tetap **maksimum 6 tabel**):

| Objek | Tipe | Catatan |
|-------|------|---------|
| `registrations.checkin_at` | `DATETIME NULL` | Waktu check-in sebenarnya (scanner maupun manual). Di-`NULL`-kan lagi saat koreksi (admin membatalkan check-in). |
| `users.role` | `ENUM('admin','mahasiswa','panitia')` | Nilai `'panitia'` ditambahkan. Panitia hanya boleh membuka Scanner Check-in & daftar peserta hari ini. |
| `users.wajib_ganti_password` | `TINYINT(1) NOT NULL DEFAULT 0` | Bila `1`, user dipaksa mengganti password di `ganti_password.php` sebelum halaman lain. Diisi saat akun panitia dibuat/direset admin, atau saat impor CSV massal. Akun lama tetap `0`. |
| `seminars.ttd_nama` | `VARCHAR(100) NULL` | Nama penandatangan pada sertifikat. Kosong (`NULL`) → sertifikat memakai teks bawaan. |
| `seminars.ttd_jabatan` | `VARCHAR(100) NULL` | Jabatan penandatangan (boleh kosong, tetapi nama wajib diisi bila jabatan diisi). Mengubah `ttd_*` **tidak** mengisi `jadwal_diubah_at`. |

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
| `ACADEMEET_IDLE_PANITIA_MIN` | `120` | Timeout tidak aktif sesi panitia (menit). |
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

## ✉️ Email (F5 lupa password & F6)

Semua email berformat **teks biasa** (tanpa HTML/gambar pelacak), bahasa Indonesia, tujuan divalidasi `FILTER_VALIDATE_EMAIL`, subjek/nama dibersihkan dari CR/LF (anti header injection). Data pengguna hanya sebagai teks; tautan hanya ke halaman aplikasi lewat `ACADEMEET_BASE_URL` (env kosong → tautan dihilangkan). Email **tidak** memuat link online seminar, token, atau password.

### Konfirmasi pendaftaran (F6a)

Setelah transaksi pendaftaran ter-commit, email konfirmasi dikirim **langsung, best-effort** (`kirim_konfirmasi_pendaftaran()`, timeout 5 detik, tanpa percobaan ulang). Isi: judul, pemateri, tanggal + jam, lokasi, kode tiket, dan tautan ke halaman tiket. Bila SMTP/`ACADEMEET_BASE_URL` belum diset atau pengiriman gagal/timeout → dilewati diam-diam (hanya `error_log` singkat tanpa alamat), **pendaftaran tetap sukses**.

### Pengingat H-1 (F6b) — tanpa antrean

`tools/pengingat_h1.php` (CLI-only) mengirim pengingat untuk registrasi `Belum Hadir` pada seminar `Aktif` yang tanggalnya **besok**, milik user dengan `notif_email=1` dan `h1_terkirim_at IS NULL`, maksimal 50 per eksekusi. Klaim ditandai atomik (`h1_terkirim_at`) agar dua proses tidak mengirim ganda; bila kirim gagal, penanda dikembalikan ke `NULL` agar dicoba lagi. Setelah 3 kegagalan berurutan, eksekusi dihentikan (SMTP diduga mati). Aman dijalankan berulang pada hari yang sama. Isi: judul, tanggal + jam, lokasi, tautan tiket; **tanpa** kode tiket.

Uji manual:

```bash
C:\xampp\php\php.exe tools\pengingat_h1.php
```

### Menjadwalkan pengingat (Windows Task Scheduler)

**Jangan** dibuat otomatis oleh aplikasi. Buat manual (contoh: tiap jam, 07:00–20:00). Perintah `schtasks` (verifikasi sintaks dengan `schtasks /Create /?` sebelum dijalankan):

```powershell
schtasks /Create /TN "AcadeMeet Pengingat H-1" /SC HOURLY /MO 1 /ST 07:00 /ET 20:00 /TR "C:\xampp\php\php.exe C:\xampp\htdocs\academeet\tools\pengingat_h1.php" /RL HIGHEST /F
```

Catatan:
- Env `ACADEMEET_*` (DB & SMTP) harus berupa **System variable** agar terbaca Task Scheduler (task tidak mewarisi env shell Anda). Alternatif: pakai file secrets `C:\xampp\academeet-secrets.ini` (lihat "Kredensial tanpa menyentuh repo").
- Jalankan beberapa kali sehari (mis. tiap jam) supaya tiket yang dibuat setelah jadwal pertama tetap dapat pengingat.
- Pengiriman nyata sebaiknya diuji dulu dengan **Mailtrap Sandbox**; perhatikan **batas kirim** penyedia (kuota harian/bulanan).
- Bila `ACADEMEET_BASE_URL` kosong, tautan di email dihilangkan (email tetap dikirim).

---

## 🔐 Keamanan

- Password `password_hash()` / `password_verify()`; password minimal per peran: mahasiswa 8, panitia 10, admin 12 karakter (batas atas 72 byte). Login sukses menjalankan `password_needs_rehash()`.
- **Peran panitia (F15)**: akses dibatasi dengan pendekatan *default-deny* di `admin.php` (`role_boleh_halaman()`/`role_boleh_aksi()`) — hanya Scanner Check-in, daftar peserta hari ini, dan check-in manual; aksi lain (hapus/edit, kelola panitia, impor) ditolak `403`. Menu & label disesuaikan per peran. Tidak ada percabangan yang memperlakukan panitia sebagai mahasiswa atau admin.
- **Wajib ganti password (F15)**: akun ber-`wajib_ganti_password=1` (dibuat admin atau hasil impor CSV) dialihkan `require_role()` ke `ganti_password.php`; hanya halaman ini dan `logout.php` yang boleh dibuka. Penggantian sukses menurunkan flag dan menaikkan `auth_version`.
- Semua mutasi memakai **POST + token CSRF** (`csrf_field()` / `csrf_check()`); GET hanya untuk membaca.
- Query memakai **prepared statement**; output di-escape dengan `e()`.
- **Throttle di database** (`auth_throttle`, jendela geser 15 menit): login dibatasi per (email+IP) 5, per IP 30, per email global 20; verifikasi tiket 10 per IP; permintaan reset 3 per email & 10 per IP per jam. Kunci disimpan sebagai SHA-256; IP diambil dari `REMOTE_ADDR` (tidak mempercayai `X-Forwarded-For`). Throttle sesi lama tetap dipakai sebagai lapis kedua; bila query DB gagal, sistem `error_log` lalu jatuh ke throttle sesi.
- **Sesi**: cookie `HttpOnly`, `SameSite=Lax`, `Secure` saat HTTPS; ID diganti setelah login; timeout tidak aktif (admin 60 menit, mahasiswa 240 menit) dengan `session.gc_maxlifetime` diset ke batas terpanjang.
- **Pembatalan sesi**: `users.auth_version` disimpan di sesi saat login dan dicek `require_role()` tiap request; naik saat ganti password sendiri, reset oleh admin, atau reset via token. Akun yang dihapus langsung tak berlaku.
- Upload divalidasi dari isi file (`getimagesize`) dan ukuran, nama file di-random; folder `uploads/` diblokir dari eksekusi skrip lewat `.htaccess`.
  - Catatan deployment: proteksi ini butuh Apache dengan `AllowOverride` yang mengizinkan `.htaccess`. Di nginx atau `AllowOverride None`, nonaktifkan eksekusi PHP untuk folder `uploads/` di konfigurasi server.
- Export `.xlsx`/CSV menetralkan formula injection (`excel_cell()`); sel XLSX bertipe teks sehingga NIM berawalan nol utuh.
- QR tiket dirender di browser (kode tiket tidak dikirim ke layanan pihak ketiga); avatar/placeholder dibuat lokal.
- **F5 lupa password**: token 32 byte acak, disimpan SHA-256, berlaku 30 menit, sekali pakai, satu aktif per user, hanya untuk role mahasiswa. Respons permintaan selalu sama (anti enumerasi email) dan waktu respons diseragamkan. Tautan dibangun dari `ACADEMEET_BASE_URL`, bukan header `Host`; halaman reset mengirim `Referrer-Policy: no-referrer` dan `Cache-Control: no-store`; tautan tidak pernah ditulis ke log.
- **Header keamanan HTTP** dikirim sekali di `bootstrap.php`: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(self), microphone=(), geolocation=()`, `Strict-Transport-Security` (hanya HTTPS), dan `Content-Security-Policy` (`default-src 'self'`; `script-src`/`style-src`/`font-src` mengizinkan `'unsafe-inline'` + `https://cdn.jsdelivr.net`; `img-src 'self' data: blob:`; `object-src 'none'`; `frame-ancestors 'self'`).

- **Impor mahasiswa CSV (F16)**: halaman `impor_mahasiswa` (hanya admin). Langkah 1 unggah & pratinjau (read-only, tanpa menulis `users`): maks 1 MB / 500 baris, header wajib `nim,nama,prodi,email` (urutan bebas, pemisah koma/titik-koma, BOM ditoleransi), validasi per baris memakai aturan registrasi + deteksi duplikat NIM/email (case-insensitive) terhadap DB. Bila **ada satu kesalahan**, tidak ada akun dibuat (all-or-nothing). Langkah 2 memakai token sesi sekali pakai (kedaluwarsa 15 menit) + password awal; semua akun ditulis dalam satu transaksi dengan satu hash (dihitung sekali), `wajib_ganti_password=1`. Password awal **tidak** masuk CSV, sesi, log, atau disimpan sebagai teks asli.

---

## ⚙️ Sistem Admin (`admin.php`)

| Parameter | Fitur |
|-----------|-------|
| `dashboard` | Statistik + **grafik area interaktif** "Pendaftar Seminar" (per hari, dua seri bertumpuk Hadir/Belum hadir, rentang 90/30/7 hari lewat dropdown, tooltip & keyboard, `<details>` data angka) + dua kartu ringkas baris HTML/CSS (Tingkat Kehadiran per Seminar, 5 Seminar Terpopuler) + daftar seminar (search & pagination server-side), aksi Edit/Selesai/Hapus. Grafik dirender server-side sebagai SVG+HTML (tanpa React/Tailwind/recharts/Chart.js/CDN baru); interaksi lewat `grafik.js` vanilla. Tiap grafik punya `role="group"` + wilayah `aria-live`. Status efektif: seminar bertanggal lampau dianggap Selesai dan tidak bisa diedit. Kolom Kategori + jam ditampilkan. |
| `tambah` | Tambah seminar: judul, pemateri, tanggal, jam mulai/selesai, kategori, lokasi, link online, deskripsi, kuota, poster, **nama/jabatan penandatangan sertifikat** (F18, opsional). |
| `edit_seminar` | Edit seminar (hanya untuk seminar efektif Aktif). Kuota adalah **kuota total**; sisa = total − jumlah pendaftar, total tidak boleh kurang dari jumlah pendaftar. Detail (jam/kategori/lokasi/link/deskripsi/ttd) dapat diisi atau dikosongkan. Bila tanggal/jam/lokasi/link berubah, `jadwal_diubah_at` diisi (pita di tiket peserta) dan `h1_terkirim_at` direset saat tanggal berubah. Poster baru opsional. Mengubah `ttd_nama`/`ttd_jabatan` tidak mengisi `jadwal_diubah_at`. |
| `scan` | Check-in via kode tiket (manual atau scan kamera). Idempoten: tiket yang sudah hadir ditolak. Mengisi `checkin_at`. |
| `peserta` | Daftar peserta per seminar + **check-in manual/koreksi** (F13). Admin: semua seminar + tombol "Tandai hadir"/"Batalkan". Panitia: hanya seminar **Aktif hari ini**; tanpa kode tiket, tanpa tombol Batalkan. |
| `panitia` | Kelola akun panitia (admin-only): tambah (password awal min 10, wajib diganti saat login pertama), reset password, hapus. |
| `impor_mahasiswa` | Impor mahasiswa massal dari CSV (admin-only), 2 langkah: unggah+pratinjau (read-only) lalu konfirmasi dengan password awal. Semua-atau-tidak-sama-sekali. |
| `mahasiswa` | Daftar mahasiswa (search & pagination), detail, hapus, tombol **Impor CSV**. |
| `detail_mahasiswa` | Profil, statistik, riwayat tiket, reset password. |
| `pemateri` | Daftar pemateri (search & pagination), tambah, detail, hapus. |
| `laporan` | Rekap pendaftar/hadir per seminar + export **`.xlsx`/CSV**. |

Penghapusan berjalan dalam transaksi:
- **Seminar** → registrasi + poster ikut dihapus. Dialog konfirmasi menampilkan jumlah peserta terdampak ("N peserta terdaftar akan kehilangan tiket mereka") dan menyarankan **Edit** bila hanya jadwal yang berubah.
- **Pemateri** → seminar terkait, registrasi, foto, dan poster ikut dihapus.
- **Mahasiswa** → registrasi dihapus, kuota seminar dikembalikan, foto profil dihapus.

---

## 🎓 Sistem Mahasiswa (`mahasiswa.php`)

| Parameter | Fitur |
|-----------|-------|
| `katalog` | Seminar aktif (`status='Aktif'`, tanggal belum lewat) + label "terisi/total" & badge "Penuh"; tombol daftar nonaktif saat kuota habis. Panel filter: kata kunci (judul/pemateri), kategori, rentang tanggal (`dari`/`sampai`), ketersediaan (masih tersedia/penuh), urutan; hasil dipaginasi server-side (9/halaman). Kategori & jam & lokasi/Online ditampilkan di kartu. |
| `tiket` | Tiket + QR, status kehadiran, unduh sertifikat bila sudah hadir, tombol **Batalkan** (seminar Aktif, tanggal > hari ini, tiket Belum Hadir), tombol **Tambah ke kalender** (`.ics`) untuk seminar Aktif, dan detail lengkap (kategori, jam, lokasi, deskripsi, tombol tautan online khusus peserta). Pita **"Jadwal diperbarui"** muncul bila jadwal seminar berubah (seminar Aktif, belum lewat). |
| `profil` | Edit data diri (validasi sama dengan registrasi), upload foto (JPG/PNG/WebP maks 2MB), ubah password, dan checkbox **"Kirim pengingat seminar lewat email"** (`notif_email`). |

Pendaftaran berjalan atomik (`SELECT ... FOR UPDATE`): kuota, status, tanggal, dan duplikasi dicek di dalam transaksi; kuota dikurangi hanya jika insert berhasil. Pembatalan (`batalkan_pendaftaran()`) juga atomik: baris tiket + seminar dikunci, lalu kuota dikembalikan dengan batas `LEAST(kuota+1, kuota_total)`.

Check-in hanya bisa pada hari H (zona Asia/Jakarta). Seminar yang tanggalnya sudah lewat dianggap Selesai saat dibaca (tanpa menulis DB); `tools/tutup_seminar.php` menyinkronkan status di DB via CLI.

**Aturan check-in: scanner vs manual (F13).** Scanner (`handleCheckin`) menolak tanggal lampau lewat guard eksplisit "Jadwal seminar sudah lewat" **sebelum** cek jendela, lalu `checkin_window_error` menolak tanggal masa depan — jadi scanner hanya mengizinkan hari H. Check-in manual (`checkin_manual`) memakai cek eksplisit `tanggal == hari ini` + `checkin_window_error`, juga hanya hari H; fungsi `checkin_window_error` sendiri hanya menolak tanggal **masa depan** (aman karena kedua pemanggil menegakkan batas lampau masing-masing).

---

## 🔄 Alur Kerja

1. Admin membuat pemateri, lalu seminar (termasuk kategori & detail jam/lokasi/deskripsi, dan opsional nama/jabatan penandatangan sertifikat).
2. Mahasiswa mendaftar → mendapat kode tiket + QR + **email konfirmasi** (best-effort). Admin juga bisa mengimpor mahasiswa massal lewat CSV.
3. Panitia (atau admin) check-in di menu **Scan**; admin dapat mengoreksi check-in di halaman **Daftar Peserta** (F13). Akun panitia dikelola admin di halaman **Data Panitia**, dan wajib mengganti password saat login pertama.
4. Mahasiswa mengunduh sertifikat PDF; peserta juga dapat menambahkan seminar ke kalender (`.ics`).
5. Siapa pun dapat memverifikasi sertifikat di `verifikasi.php?kode=ACD-XXXXXX` (URL ini juga tercetak di sertifikat).
6. Admin mengekspor laporan peserta ke **`.xlsx`/CSV**; dashboard menampilkan grafik.
7. Sehari sebelum acara, pengingat **H-1** dikirim via `tools/pengingat_h1.php` (dijadwalkan Task Scheduler).

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
set ACADEMEET_DB_NAME=academeet_paketd_test
C:\xampp\php\php.exe tests/run_db.php

# Tes asap HTTP end-to-end (server php -S + login peran) — HANYA di *_test:
C:\xampp\php\php.exe tests/smoke_http.php
```

`tests/run_db.php` menolak berjalan pada DB yang namanya tidak berakhiran `_test`. Buat salinan dulu (mis. dari dump live), jalankan `migrasi.sql` ke salinan itu (`mysql -u root -D academeet_paketd_test < migrasi.sql`), lalu arahkan `ACADEMEET_DB_NAME` ke salinan itu. Tes membuat lalu membersihkan datanya sendiri (email berakhiran `@test.local`). Pengiriman email di tes memakai **pengirim palsu** (tidak ada email sungguhan terkirim).
