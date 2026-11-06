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
