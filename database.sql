-- ============================================================
-- PROS AUTO - Application de gestion de garage automobile
-- Base de données : garage_db1
-- Importer ce fichier dans phpMyAdmin (ou : mysql -u root < database.sql)
-- Compatible MySQL 5.7+ / MariaDB 10.3+
-- ============================================================

CREATE DATABASE IF NOT EXISTS garage_db1
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE garage_db1;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS facture_items;
DROP TABLE IF EXISTS factures;
DROP TABLE IF EXISTS devis_items;
DROP TABLE IF EXISTS devis;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS attendance;
DROP TABLE IF EXISTS vehicle_status_history;
DROP TABLE IF EXISTS vehicle_assignments;
DROP TABLE IF EXISTS repair_parts;
DROP TABLE IF EXISTS repairs;
DROP TABLE IF EXISTS inspections;
DROP TABLE IF EXISTS vehicles;
DROP TABLE IF EXISTS clients;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS mechanics;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- Mécaniciens (profils métier)
-- ------------------------------------------------------------
CREATE TABLE mechanics (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(100) NOT NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(120) NULL,
  specialty VARCHAR(100) NULL,
  hire_date DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_mechanics_name (full_name),
  INDEX idx_mechanics_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Utilisateurs (comptes de connexion : admin / mécanicien)
-- ------------------------------------------------------------
CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(100) NOT NULL,
  username VARCHAR(50) NOT NULL,
  email VARCHAR(120) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','mechanic') NOT NULL DEFAULT 'mechanic',
  mechanic_id INT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  INDEX idx_users_role (role),
  INDEX idx_users_mechanic (mechanic_id),
  CONSTRAINT fk_users_mechanic FOREIGN KEY (mechanic_id)
    REFERENCES mechanics(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Clients
-- ------------------------------------------------------------
CREATE TABLE clients (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  civility ENUM('Monsieur','Madame') NOT NULL DEFAULT 'Monsieur',
  last_name VARCHAR(80) NOT NULL,
  first_name VARCHAR(80) NOT NULL,
  phone VARCHAR(30) NULL,
  phone2 VARCHAR(30) NULL,
  email VARCHAR(120) NULL,
  address VARCHAR(255) NULL,
  postal_code VARCHAR(10) NULL,
  city VARCHAR(80) NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_clients_name (last_name, first_name),
  INDEX idx_clients_phone (phone),
  INDEX idx_clients_email (email),
  INDEX idx_clients_city (city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Véhicules
-- ------------------------------------------------------------
CREATE TABLE vehicles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT UNSIGNED NOT NULL,
  license_plate VARCHAR(20) NOT NULL,
  brand VARCHAR(50) NOT NULL,
  model VARCHAR(50) NOT NULL,
  year SMALLINT UNSIGNED NULL,
  vin VARCHAR(17) NULL,
  mileage INT UNSIGNED NULL,
  fuel ENUM('Essence','Diesel','Electrique','Hybride','GPL') NULL,
  color VARCHAR(40) NULL,
  status ENUM('initial','en_cours','en_reparation','repare','livre','annule') NOT NULL DEFAULT 'initial',
  mechanic_id INT UNSIGNED NULL,
  entry_date DATE NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vehicles_plate (license_plate),
  UNIQUE KEY uq_vehicles_vin (vin),
  INDEX idx_vehicles_client (client_id),
  INDEX idx_vehicles_mechanic (mechanic_id),
  INDEX idx_vehicles_status (status),
  INDEX idx_vehicles_entry (entry_date),
  CONSTRAINT fk_vehicles_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE RESTRICT,
  CONSTRAINT fk_vehicles_mechanic FOREIGN KEY (mechanic_id)
    REFERENCES mechanics(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Inspections
-- ------------------------------------------------------------
CREATE TABLE inspections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  vehicle_type VARCHAR(50) NULL,
  mileage INT UNSIGNED NULL,
  inspection_date DATE NOT NULL,
  mechanic_id INT UNSIGNED NULL,
  problem_description TEXT NOT NULL,
  initial_diagnostic TEXT NULL,
  parts_to_check TEXT NULL,
  observations TEXT NULL,
  photo VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_insp_vehicle (vehicle_id),
  INDEX idx_insp_client (client_id),
  INDEX idx_insp_mechanic (mechanic_id),
  INDEX idx_insp_date (inspection_date),
  CONSTRAINT fk_insp_vehicle FOREIGN KEY (vehicle_id)
    REFERENCES vehicles(id) ON DELETE CASCADE,
  CONSTRAINT fk_insp_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_insp_mechanic FOREIGN KEY (mechanic_id)
    REFERENCES mechanics(id) ON DELETE SET NULL,
  CONSTRAINT fk_insp_created_by FOREIGN KEY (created_by)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Réparations
-- ------------------------------------------------------------
CREATE TABLE repairs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id INT UNSIGNED NOT NULL,
  inspection_id INT UNSIGNED NULL,
  client_id INT UNSIGNED NOT NULL,
  mechanic_id INT UNSIGNED NULL,
  diagnostic TEXT NULL,
  works_planned TEXT NULL,
  works_done TEXT NULL,
  labor_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  start_date DATE NULL,
  end_date DATE NULL,
  status ENUM('en_cours','terminee') NOT NULL DEFAULT 'en_cours',
  comments TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_repairs_vehicle (vehicle_id),
  INDEX idx_repairs_inspection (inspection_id),
  INDEX idx_repairs_client (client_id),
  INDEX idx_repairs_mechanic (mechanic_id),
  INDEX idx_repairs_status (status),
  CONSTRAINT fk_repairs_vehicle FOREIGN KEY (vehicle_id)
    REFERENCES vehicles(id) ON DELETE CASCADE,
  CONSTRAINT fk_repairs_inspection FOREIGN KEY (inspection_id)
    REFERENCES inspections(id) ON DELETE SET NULL,
  CONSTRAINT fk_repairs_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_repairs_mechanic FOREIGN KEY (mechanic_id)
    REFERENCES mechanics(id) ON DELETE SET NULL,
  CONSTRAINT fk_repairs_created_by FOREIGN KEY (created_by)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Pièces utilisées dans une réparation
-- ------------------------------------------------------------
CREATE TABLE repair_parts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  repair_id INT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_parts_repair (repair_id),
  CONSTRAINT fk_parts_repair FOREIGN KEY (repair_id)
    REFERENCES repairs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Affectations véhicule -> mécanicien
-- ------------------------------------------------------------
CREATE TABLE vehicle_assignments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id INT UNSIGNED NOT NULL,
  inspection_id INT UNSIGNED NULL,
  mechanic_id INT UNSIGNED NOT NULL,
  assigned_date DATE NOT NULL,
  assigned_time TIME NOT NULL,
  priority ENUM('basse','normale','haute','urgente') NOT NULL DEFAULT 'normale',
  instructions TEXT NULL,
  assigned_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_assign_vehicle (vehicle_id),
  INDEX idx_assign_mechanic (mechanic_id),
  INDEX idx_assign_date (assigned_date),
  CONSTRAINT fk_assign_vehicle FOREIGN KEY (vehicle_id)
    REFERENCES vehicles(id) ON DELETE CASCADE,
  CONSTRAINT fk_assign_inspection FOREIGN KEY (inspection_id)
    REFERENCES inspections(id) ON DELETE SET NULL,
  CONSTRAINT fk_assign_mechanic FOREIGN KEY (mechanic_id)
    REFERENCES mechanics(id) ON DELETE CASCADE,
  CONSTRAINT fk_assign_by FOREIGN KEY (assigned_by)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Historique des états du véhicule (jamais écrasé)
-- ------------------------------------------------------------
CREATE TABLE vehicle_status_history (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id INT UNSIGNED NOT NULL,
  status ENUM('initial','en_cours','en_reparation','repare','livre','annule') NOT NULL,
  previous_status ENUM('initial','en_cours','en_reparation','repare','livre','annule') NULL,
  comment TEXT NULL,
  mechanic_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_history_vehicle (vehicle_id, changed_at),
  CONSTRAINT fk_history_vehicle FOREIGN KEY (vehicle_id)
    REFERENCES vehicles(id) ON DELETE CASCADE,
  CONSTRAINT fk_history_mechanic FOREIGN KEY (mechanic_id)
    REFERENCES mechanics(id) ON DELETE SET NULL,
  CONSTRAINT fk_history_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Pointage (entrées / sorties)
-- ------------------------------------------------------------
CREATE TABLE attendance (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  mechanic_id INT UNSIGNED NULL,
  clock_type ENUM('IN','OUT') NOT NULL,
  clocked_at DATETIME NOT NULL,
  day_date DATE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_att_user_date (user_id, day_date),
  INDEX idx_att_mech_date (mechanic_id, day_date),
  INDEX idx_att_type (clock_type),
  CONSTRAINT fk_att_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_mechanic FOREIGN KEY (mechanic_id)
    REFERENCES mechanics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Journal d'activité
-- ------------------------------------------------------------
CREATE TABLE activity_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  username VARCHAR(50) NULL,
  action VARCHAR(50) NOT NULL,
  module VARCHAR(50) NOT NULL,
  record_id INT UNSIGNED NULL,
  description VARCHAR(500) NOT NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_logs_module (module),
  INDEX idx_logs_user (user_id),
  INDEX idx_logs_action (action),
  INDEX idx_logs_created (created_at),
  CONSTRAINT fk_logs_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Notifications internes
-- ------------------------------------------------------------
CREATE TABLE notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  message VARCHAR(500) NOT NULL,
  type ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
  ref VARCHAR(120) NULL,
  link VARCHAR(255) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_notif_user (user_id, is_read),
  INDEX idx_notif_ref (ref),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Devis
-- ------------------------------------------------------------
CREATE TABLE devis (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  quote_number VARCHAR(30) NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  vehicle_id INT UNSIGNED NULL,
  devis_date DATE NOT NULL,
  valid_until DATE NULL,
  status ENUM('brouillon','envoye','accepte','refuse','facture') NOT NULL DEFAULT 'brouillon',
  total_ht DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_tva DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_ttc DECIMAL(12,2) NOT NULL DEFAULT 0,
  doc_lang ENUM('fr','en') NOT NULL DEFAULT 'fr',
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_devis_number (quote_number),
  INDEX idx_devis_client (client_id),
  INDEX idx_devis_vehicle (vehicle_id),
  INDEX idx_devis_date (devis_date),
  INDEX idx_devis_status (status),
  CONSTRAINT fk_devis_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_devis_vehicle FOREIGN KEY (vehicle_id)
    REFERENCES vehicles(id) ON DELETE SET NULL,
  CONSTRAINT fk_devis_created_by FOREIGN KEY (created_by)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Lignes de devis
-- ------------------------------------------------------------
CREATE TABLE devis_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  devis_id INT UNSIGNED NOT NULL,
  position INT NOT NULL DEFAULT 1,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  tva_rate DECIMAL(5,2) NOT NULL DEFAULT 19,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_tva DECIMAL(12,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  INDEX idx_devis_items_devis (devis_id, position),
  CONSTRAINT fk_devis_items_devis FOREIGN KEY (devis_id)
    REFERENCES devis(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Factures
-- ------------------------------------------------------------
CREATE TABLE factures (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_number VARCHAR(30) NOT NULL,
  devis_id INT UNSIGNED NULL,
  client_id INT UNSIGNED NOT NULL,
  vehicle_id INT UNSIGNED NULL,
  invoice_date DATE NOT NULL,
  due_date DATE NULL,
  status ENUM('brouillon','envoyee','payee','partiellement_payee','annulee') NOT NULL DEFAULT 'brouillon',
  total_ht DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_tva DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_ttc DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
  doc_lang ENUM('fr','en') NOT NULL DEFAULT 'fr',
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_facture_number (invoice_number),
  INDEX idx_factures_client (client_id),
  INDEX idx_factures_vehicle (vehicle_id),
  INDEX idx_factures_devis (devis_id),
  INDEX idx_factures_date (invoice_date),
  INDEX idx_factures_status (status),
  CONSTRAINT fk_factures_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_factures_vehicle FOREIGN KEY (vehicle_id)
    REFERENCES vehicles(id) ON DELETE SET NULL,
  CONSTRAINT fk_factures_devis FOREIGN KEY (devis_id)
    REFERENCES devis(id) ON DELETE SET NULL,
  CONSTRAINT fk_factures_created_by FOREIGN KEY (created_by)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Lignes de facture
-- ------------------------------------------------------------
CREATE TABLE facture_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  facture_id INT UNSIGNED NOT NULL,
  position INT NOT NULL DEFAULT 1,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  tva_rate DECIMAL(5,2) NOT NULL DEFAULT 19,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_tva DECIMAL(12,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  INDEX idx_facture_items_facture (facture_id, position),
  CONSTRAINT fk_facture_items_facture FOREIGN KEY (facture_id)
    REFERENCES factures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tentatives de connexion (protection anti force brute)
-- ------------------------------------------------------------
CREATE TABLE login_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_address VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_attempts_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Paramètres de l'application
-- ------------------------------------------------------------
CREATE TABLE settings (
  setting_key VARCHAR(60) NOT NULL,
  setting_value TEXT NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('company_name', 'PROS AUTO'),
  ('company_address', '245 King Street West, Toronto, ON M5V 3K2, Canada'),
  ('company_phone', '+1 (416) 555-0147'),
  ('company_email', 'info@prosauto.ca'),
  ('company_web', 'www.prosauto.ca'),
  ('company_tax_id', 'HST 123456789 RT0001'),
  ('email_from', 'info@prosauto.ca'),
  ('app_timezone', 'America/Toronto'),
  ('default_tva', '13'),
  ('expected_in_time', '08:00'),
  ('normal_daily_hours', '8'),
  ('break_threshold_minutes', '30'),
  ('currency', 'CAD');

-- ============================================================
-- Données initiales
-- Administrateur : admin / Admin@2026
-- Mécaniciens    : mohamed / Mech@2026 , ahmed / Mech@2026
-- (changer ces mots de passe après la première connexion)
-- ============================================================

INSERT INTO mechanics (id, full_name, phone, email, specialty, hire_date, is_active) VALUES
  (1, 'Daniel Martin', '+1 (647) 555-2101', 'daniel@prosauto.ca', 'General maintenance', '2023-03-01', 1),
  (2, 'Amir Patel', '+1 (416) 555-3129', 'amir@prosauto.ca', 'Electrical diagnostics', '2024-01-15', 1);

INSERT INTO users (id, full_name, username, email, password, role, mechanic_id, is_active) VALUES
  (1, 'Administrator', 'admin', 'admin@prosauto.ca',
   '$2y$12$M7x3iX2htvFiJ3HLCaOvZuTlv0.uj6g31tyYjuLHYIr6HkyjvJDhO', 'admin', NULL, 1),
  (2, 'Daniel Martin', 'mohamed', 'daniel@prosauto.ca',
   '$2y$12$25CJSdcAQTa4MUjeUMXmje5xEJ9woUsS7qyQbXhVWo7gZPQld3XbK', 'mechanic', 1, 1),
  (3, 'Amir Patel', 'ahmed', 'amir@prosauto.ca',
   '$2y$12$25CJSdcAQTa4MUjeUMXmje5xEJ9woUsS7qyQbXhVWo7gZPQld3XbK', 'mechanic', 2, 1);
