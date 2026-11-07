-- Migrasi untuk database academeet yang sudah berjalan.
-- Aman dijalankan berulang: index dicek dulu lewat information_schema.
-- Jalankan: mysql -u root academeet < migrasi.sql
USE academeet;

-- Samakan lebar kolom snapshot nama pemateri dengan tabel pemateri.
ALTER TABLE seminars MODIFY pemateri VARCHAR(150) NOT NULL;

-- Index unik: satu mahasiswa hanya boleh mendaftar satu kali per seminar.
SET @exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'registrations' AND index_name = 'uq_reg_user_seminar');
SET @sql := IF(@exists = 0,
    'ALTER TABLE registrations ADD UNIQUE KEY uq_reg_user_seminar (id_user, id_seminar)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index pendukung pencarian & laporan.
SET @exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'seminars' AND index_name = 'idx_seminars_tanggal');
SET @sql := IF(@exists = 0,
    'ALTER TABLE seminars ADD KEY idx_seminars_tanggal (tanggal)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'seminars' AND index_name = 'idx_seminars_status');
SET @sql := IF(@exists = 0,
    'ALTER TABLE seminars ADD KEY idx_seminars_status (status)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'registrations' AND index_name = 'idx_reg_seminar');
SET @sql := IF(@exists = 0,
    'ALTER TABLE registrations ADD KEY idx_reg_seminar (id_seminar)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'registrations' AND index_name = 'idx_reg_status');
SET @sql := IF(@exists = 0,
    'ALTER TABLE registrations ADD KEY idx_reg_status (status_hadir)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_role');
SET @sql := IF(@exists = 0,
    'ALTER TABLE users ADD KEY idx_users_role (role)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Foreign key hanya ditambahkan jika tidak ada baris yatim.
SET @orphans := (SELECT COUNT(*) FROM registrations r
    LEFT JOIN users u ON r.id_user = u.id_user WHERE u.id_user IS NULL);
SET @sql := IF(@orphans = 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE() AND table_name = 'registrations' AND constraint_name = 'fk_reg_user') = 0,
    'ALTER TABLE registrations ADD CONSTRAINT fk_reg_user FOREIGN KEY (id_user) REFERENCES users(id_user) ON DELETE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @orphans := (SELECT COUNT(*) FROM registrations r
    LEFT JOIN seminars s ON r.id_seminar = s.id_seminar WHERE s.id_seminar IS NULL);
SET @sql := IF(@orphans = 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE() AND table_name = 'registrations' AND constraint_name = 'fk_reg_seminar') = 0,
    'ALTER TABLE registrations ADD CONSTRAINT fk_reg_seminar FOREIGN KEY (id_seminar) REFERENCES seminars(id_seminar) ON DELETE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @orphans := (SELECT COUNT(*) FROM seminars s
    LEFT JOIN pemateri p ON s.id_pemateri = p.id_pemateri WHERE s.id_pemateri IS NOT NULL AND p.id_pemateri IS NULL);
SET @sql := IF(@orphans = 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE() AND table_name = 'seminars' AND constraint_name = 'fk_seminars_pemateri') = 0,
    'ALTER TABLE seminars ADD CONSTRAINT fk_seminars_pemateri FOREIGN KEY (id_pemateri) REFERENCES pemateri(id_pemateri) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =====================================================
-- Paket A
-- =====================================================

-- kuota_total: kuota awal (total kursi). Sisa kuota tetap di kolom `kuota`.
SET @exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'seminars' AND column_name = 'kuota_total');
SET @sql := IF(@exists = 0,
    'ALTER TABLE seminars ADD COLUMN kuota_total INT NULL AFTER kuota',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Isi kuota_total untuk baris yang masih NULL: total = sisa + jumlah pendaftar.
UPDATE seminars s
SET s.kuota_total = s.kuota + (SELECT COUNT(*) FROM registrations r WHERE r.id_seminar = s.id_seminar)
WHERE s.kuota_total IS NULL;

-- UNIQUE nim: hanya bila belum ada DAN tidak ada NIM ganda.
SET @dupes := (SELECT COUNT(nim) - COUNT(DISTINCT nim) FROM users WHERE nim IS NOT NULL);
SET @exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'uq_users_nim');
SET @sql := IF(@exists = 0 AND @dupes = 0,
    'ALTER TABLE users ADD UNIQUE KEY uq_users_nim (nim)',
    IF(@dupes > 0,
        "SELECT 'PERINGATAN: NIM ganda, UNIQUE tidak ditambahkan' AS info",
        'SELECT 1'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =====================================================
-- Paket B
-- =====================================================

-- auth_version: naik saat password berubah/direset; sesi lama langsung tak berlaku.
SET @exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'auth_version');
SET @sql := IF(@exists = 0,
    'ALTER TABLE users ADD COLUMN auth_version INT NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Tabel throttle percobaan (login, verifikasi tiket, permintaan reset).
CREATE TABLE IF NOT EXISTS auth_throttle (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    scope        VARCHAR(20) NOT NULL,
    key_hash     CHAR(64) NOT NULL,
    attempted_at DATETIME NOT NULL,
    KEY idx_throttle_lookup (scope, key_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabel token reset password (sekali pakai, disimpan sebagai SHA-256).
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    id_user    INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    UNIQUE KEY uq_reset_token (token_hash),
    KEY idx_reset_user (id_user),
    CONSTRAINT fk_reset_user FOREIGN KEY (id_user)
        REFERENCES users (id_user) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
