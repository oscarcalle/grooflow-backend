-- GrooFlow Asistencia — esquema MySQL (referencia)
-- Aplicado automáticamente por grooflow_asistencia_ensure_schema() al arrancar el API.
-- Relaciones: sede_name es FK lógica (sin tabla sedes); usuario_id apunta a app_usuarios.id.

CREATE TABLE IF NOT EXISTS grooflow_asistencia_meta (
  id VARCHAR(40) NOT NULL,
  buk_json JSON NULL,
  area_keywords_json JSON NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS grooflow_asistencia_staff (
  id VARCHAR(80) NOT NULL,
  sede_name VARCHAR(160) NOT NULL,
  full_name VARCHAR(190) NOT NULL,
  cargo_label VARCHAR(160) NOT NULL DEFAULT '',
  area VARCHAR(80) NOT NULL DEFAULT 'administracion',
  rut VARCHAR(40) NULL,
  usuario_id INT UNSIGNED NULL,
  is_critical TINYINT(1) NOT NULL DEFAULT 0,
  is_manager TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  payload JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_asist_staff_sede (sede_name),
  KEY idx_asist_staff_rut (rut),
  KEY idx_asist_staff_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS grooflow_asistencia_requirements (
  id VARCHAR(80) NOT NULL,
  sede_name VARCHAR(160) NOT NULL,
  area_group VARCHAR(40) NOT NULL DEFAULT 'global',
  cargo_label VARCHAR(160) NOT NULL DEFAULT '',
  required_count INT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  payload JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_asist_req_sede (sede_name),
  KEY idx_asist_req_area (area_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS grooflow_asistencia_sede_profiles (
  sede_name VARCHAR(160) NOT NULL,
  buk_recinto_code VARCHAR(80) NULL,
  payload JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (sede_name),
  KEY idx_asist_profile_recinto (buk_recinto_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS grooflow_asistencia_sede_mappings (
  sede_name VARCHAR(160) NOT NULL,
  buk_recinto_code VARCHAR(80) NOT NULL,
  buk_recinto_name VARCHAR(160) NULL,
  payload JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (sede_name),
  KEY idx_asist_map_recinto (buk_recinto_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS grooflow_asistencia_snapshots (
  id VARCHAR(120) NOT NULL,
  date_ymd CHAR(10) NOT NULL,
  sede_name VARCHAR(160) NOT NULL,
  captured_at DATETIME NOT NULL,
  source ENUM('manual', 'auto') NOT NULL DEFAULT 'manual',
  working_count INT NOT NULL DEFAULT 0,
  absent_count INT NOT NULL DEFAULT 0,
  late_count INT NOT NULL DEFAULT 0,
  critical_absent_count INT NOT NULL DEFAULT 0,
  total_required INT NOT NULL DEFAULT 0,
  total_present INT NOT NULL DEFAULT 0,
  buk_records_on_date INT NOT NULL DEFAULT 0,
  payload JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_asist_snap_day_sede (date_ymd, sede_name),
  KEY idx_asist_snap_date (date_ymd),
  KEY idx_asist_snap_sede (sede_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS grooflow_asistencia_operational (
  id VARCHAR(40) NOT NULL,
  date_ymd CHAR(10) NOT NULL,
  cache_fetched_at BIGINT NULL,
  buk_enabled TINYINT(1) NOT NULL DEFAULT 0,
  payload JSON NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- KV keys asociadas:
--   settings:asistencia          → meta + staff + requirements + profiles + mappings
--   data:asistencia-snapshots     → snapshots
--   data:asistencia-operational   → operational
