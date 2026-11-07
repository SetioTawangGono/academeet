-- AcadeMeet — skema database untuk instalasi baru.
-- Jalankan: mysql -u root < database.sql
CREATE DATABASE IF NOT EXISTS academeet CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE academeet;

CREATE TABLE IF NOT EXISTS users (
    id_user      INT AUTO_INCREMENT PRIMARY KEY,
    nama_lengkap VARCHAR(100) NOT NULL,
    nim          VARCHAR(20)  NULL,
    prodi        VARCHAR(100) NULL,
    foto         VARCHAR(255) NULL,
    email        VARCHAR(100) NOT NULL,
    password     VARCHAR(255) NOT NULL,
    role         ENUM('admin','mahasiswa') NOT NULL DEFAULT 'mahasiswa',
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role),
    UNIQUE KEY uq_users_nim (nim)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pemateri (
    id_pemateri INT AUTO_INCREMENT PRIMARY KEY,
    nama        VARCHAR(150) NOT NULL,
    keahlian    VARCHAR(150) NULL,
    institusi   VARCHAR(150) NULL,
    email       VARCHAR(100) NULL,
    no_hp       VARCHAR(30)  NULL,
    foto        VARCHAR(255) NULL,
    bio         TEXT         NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seminars (
    id_seminar  INT AUTO_INCREMENT PRIMARY KEY,
    judul       VARCHAR(200) NOT NULL,
    id_pemateri INT NULL,
    pemateri    VARCHAR(150) NOT NULL,
    tanggal     DATE NOT NULL,
    kuota       INT NOT NULL,
    kuota_total INT NULL,
    status      ENUM('Aktif','Selesai') NOT NULL DEFAULT 'Aktif',
    poster      VARCHAR(255) NULL,
    KEY idx_seminars_tanggal (tanggal),
    KEY idx_seminars_status (status),
    KEY idx_seminars_pemateri (id_pemateri),
    CONSTRAINT fk_seminars_pemateri FOREIGN KEY (id_pemateri)
        REFERENCES pemateri (id_pemateri) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registrations (
    id_registrasi INT AUTO_INCREMENT PRIMARY KEY,
    id_user       INT NOT NULL,
    id_seminar    INT NOT NULL,
    kode_tiket    VARCHAR(20) NOT NULL,
    status_hadir  ENUM('Belum Hadir','Hadir') NOT NULL DEFAULT 'Belum Hadir',
    UNIQUE KEY uq_reg_kode (kode_tiket),
    UNIQUE KEY uq_reg_user_seminar (id_user, id_seminar),
    KEY idx_reg_seminar (id_seminar),
    KEY idx_reg_status (status_hadir),
    CONSTRAINT fk_reg_user FOREIGN KEY (id_user)
        REFERENCES users (id_user) ON DELETE CASCADE,
    CONSTRAINT fk_reg_seminar FOREIGN KEY (id_seminar)
        REFERENCES seminars (id_seminar) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
