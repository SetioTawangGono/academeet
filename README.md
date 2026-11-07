# 📚 AcadeMeet

**Portal Seminar & Event Kampus** — sistem manajemen seminar berbasis web dengan dua peran: **Admin** (pengelola) dan **Mahasiswa** (peserta).

PHP Native + MySQL (XAMPP), UI **Bootstrap 5**, sertifikat **FPDF**, QR dibuat di browser dengan **qrcodejs**.

---

## 📁 Struktur File

| File | Fungsi |
|------|--------|
| `bootstrap.php` | Fondasi bersama: session hardening, timezone, `e()`, CSRF, flash, `require_role()`, helper tiket/password/upload/pagination, validasi data diri (`validasi_data_diri()`, `prodi_terdaftar()`), jendela check-in, status efektif, header keamanan. |
| `services.php` | Logika DB (transaksi): `batalkan_pendaftaran()`, `ubah_seminar()`. Dipanggil `mahasiswa.php`, `admin.php`, dan tes. |
| `index.php` | Login (throttle 5 percobaan/15 menit, pesan generik, `session_regenerate_id`). |
| `register.php` | Registrasi mahasiswa + validasi server + CSRF. |
| `logout.php` | Hapus session & cookie, redirect ke login. |
| `koneksi.php` | Koneksi MySQL via env (fallback `localhost`/`root`), charset `utf8mb4`. |
| `admin.php` | Dashboard admin multi-halaman via `?page=`. |
| `mahasiswa.php` | Portal mahasiswa: katalog, tiket, profil. |
| `sertifikat.php` | Preview & unduh sertifikat PDF (hanya jika `status_hadir='Hadir'`). |
| `verifikasi.php` | Halaman publik verifikasi sertifikat berdasarkan kode tiket. |
| `export_excel.php` | Ekspor peserta per seminar ke `.xls` (admin-only). |
| `fpdf.php` | Library FPDF. |
| `database.sql` | Skema lengkap untuk instalasi baru (+ index & foreign key). |
| `migrasi.sql` | Migrasi idempoten untuk database yang sudah berjalan. |
| `tests/run.php` | Self-check helper tanpa dependency (`php tests/run.php`). |
| `tests/run_db.php` | Tes fungsi DB `services.php`. **Hanya** untuk database bernama `*_test` (mis. `academeet_paketa_test`); menolak berjalan di DB lain. |
| `tools/tutup_seminar.php` | CLI: tandai seminar `Aktif` yang tanggalnya lewat menjadi `Selesai`. |
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

Tabel: `users`, `pemateri`, `seminars`, `registrations`.

Batasan penting:
- `registrations` punya `UNIQUE (id_user, id_seminar)` — satu mahasiswa satu tiket per seminar.
- `registrations.kode_tiket` unik, format `ACD-` + 6 hex acak.
- `users.nim` punya `UNIQUE KEY uq_users_nim` (NIM kosong disimpan `NULL`, boleh banyak NULL).
- Foreign key: registrasi → user/seminar (`ON DELETE CASCADE`), seminar → pemateri (`ON DELETE SET NULL`).

Kolom `kuota_total` (di `seminars`) menyimpan kuota awal/total; kolom `kuota` menyimpan sisa kursi. Migrasi mengisinya dengan `kuota + jumlah pendaftar`. Bila ada NIM ganda, migrasi menampilkan `PERINGATAN: NIM ganda, UNIQUE tidak ditambahkan` dan melewati pembuatan indeks unik (tanpa mengubah data NIM).

### Kredensial database

`koneksi.php` membaca environment variable berikut (default untuk XAMPP):

| Env | Default |
|-----|---------|
| `ACADEMEET_DB_HOST` | `localhost` |
| `ACADEMEET_DB_USER` | `root` |
| `ACADEMEET_DB_PASS` | *(kosong)* |
| `ACADEMEET_DB_NAME` | `academeet` |

---

## 🔐 Keamanan

- Password `password_hash()` / `password_verify()`; password baru minimal 8 karakter.
- Semua mutasi memakai **POST + token CSRF** (`csrf_field()` / `csrf_check()`); GET hanya untuk membaca.
- Query memakai **prepared statement**; output di-escape dengan `e()`.
- Login di-throttle dan memakai pesan error generik.
- Session cookie `HttpOnly`, `SameSite=Lax`, `Secure` saat HTTPS; ID sesi diganti setelah login.
- Upload divalidasi dari isi file (`getimagesize`) dan ukuran, nama file di-random; folder `uploads/` diblokir dari eksekusi skrip lewat `.htaccess`.
  - Catatan deployment: proteksi ini butuh Apache dengan `AllowOverride` yang mengizinkan `.htaccess`. Di nginx atau `AllowOverride None`, nonaktifkan eksekusi PHP untuk folder `uploads/` di konfigurasi server.
- Export Excel menetralkan formula injection (`excel_cell()`).
- QR tiket dirender di browser (kode tiket tidak dikirim ke layanan pihak ketiga); avatar/placeholder dibuat lokal.
- **Header keamanan HTTP** dikirim sekali di `bootstrap.php`: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(self), microphone=(), geolocation=()`, `Strict-Transport-Security` (hanya HTTPS), dan `Content-Security-Policy` (`default-src 'self'`; `script-src`/`style-src`/`font-src` mengizinkan `'unsafe-inline'` + `https://cdn.jsdelivr.net`; `img-src 'self' data: blob:`; `object-src 'none'`; `frame-ancestors 'self'`).

---

## ⚙️ Sistem Admin (`admin.php`)

| Parameter | Fitur |
|-----------|-------|
| `dashboard` | Statistik + daftar seminar (search & pagination server-side), aksi Edit/Selesai/Hapus. Status efektif: seminar bertanggal lampau dianggap Selesai dan tidak bisa diedit. |
| `tambah` | Tambah seminar: judul, pemateri, tanggal, kuota, poster. |
| `edit_seminar` | Edit seminar (hanya untuk seminar efektif Aktif). Kuota adalah **kuota total**; sisa = total − jumlah pendaftar, total tidak boleh kurang dari jumlah pendaftar. Poster baru opsional. |
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
| `katalog` | Seminar aktif (`status='Aktif'`, tanggal belum lewat) + label "terisi/total" & badge "Penuh"; tombol daftar nonaktif saat kuota habis. |
| `tiket` | Tiket + QR, status kehadiran, unduh sertifikat bila sudah hadir, tombol **Batalkan** (seminar Aktif, tanggal > hari ini, tiket Belum Hadir). |
| `profil` | Edit data diri (validasi sama dengan registrasi), upload foto (JPG/PNG/WebP maks 2MB), ubah password. |

Pendaftaran berjalan atomik (`SELECT ... FOR UPDATE`): kuota, status, tanggal, dan duplikasi dicek di dalam transaksi; kuota dikurangi hanya jika insert berhasil. Pembatalan (`batalkan_pendaftaran()`) juga atomik: baris tiket + seminar dikunci, lalu kuota dikembalikan dengan batas `LEAST(kuota+1, kuota_total)`.

Check-in hanya bisa pada hari H (zona Asia/Jakarta). Seminar yang tanggalnya sudah lewat dianggap Selesai saat dibaca (tanpa menulis DB); `tools/tutup_seminar.php` menyinkronkan status di DB via CLI.

---

## 🔄 Alur Kerja

1. Admin membuat pemateri, lalu seminar.
2. Mahasiswa mendaftar → mendapat kode tiket + QR.
3. Panitia check-in di menu **Scan** → status `Hadir`.
4. Mahasiswa mengunduh sertifikat PDF.
5. Siapa pun dapat memverifikasi sertifikat di `verifikasi.php?kode=ACD-XXXXXX`.
6. Admin mengekspor laporan peserta ke Excel.

---

## 🚀 Cara Menjalankan

1. Letakkan folder di `C:\xampp\htdocs\`.
2. Nyalakan Apache & MySQL.
3. Import `database.sql` (instalasi baru) atau `migrasi.sql` (DB lama).
4. Sesuaikan env DB bila perlu.
5. Buka `http://localhost/academeet/`.

Akun admin dibuat manual di tabel `users` dengan `role='admin'` dan password hasil `password_hash()`.

---

## 🧪 Tes

```bash
C:\xampp\php\php.exe tests/run.php
C:\xampp\php\php.exe -l admin.php   # cek sintaks

# Tes fungsi DB — HANYA di database *_test:
set ACADEMEET_DB_NAME=academeet_paketa_test
C:\xampp\php\php.exe tests/run_db.php
```
