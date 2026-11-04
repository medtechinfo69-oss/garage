-- ============================================================
-- Migration : gestion des rôles + e-mail optionnel
-- Exécuter une seule fois : mysql -u root garage_db1 < migration_roles.sql
-- 1) users.email devient NULLable (e-mail non obligatoire)
-- 2) users.role passe de ENUM à VARCHAR(50) (rôles dynamiques)
-- 3) nouvelle table user_roles pour créer autant de rôles que voulu
-- ============================================================

USE garage_db1;

ALTER TABLE users
  MODIFY email VARCHAR(120) NULL,
  MODIFY role VARCHAR(50) NOT NULL DEFAULT 'mechanic';

CREATE TABLE IF NOT EXISTS user_roles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_key VARCHAR(50) NOT NULL,
  role_label VARCHAR(60) NOT NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_roles_key (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO user_roles (role_key, role_label, is_admin, is_active) VALUES
  ('admin',    'Administrateur', 1, 1),
  ('mechanic', 'Mécanicien',     0, 1);
