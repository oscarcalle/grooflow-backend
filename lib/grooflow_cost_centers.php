<?php

declare(strict_types=1);

/**
 * Módulo maestro: unidades de negocio, org (área/subárea/cargo), centros de costo.
 * Fase 1: catálogos + CC. Asignaciones/reglas/gastos en fases siguientes.
 * Migraciones solo aditivas (CREATE IF NOT EXISTS + seeds idempotentes).
 */

function grooflow_cost_centers_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_unidades_negocio (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(40) NOT NULL,
            nombre VARCHAR(160) NOT NULL,
            descripcion VARCHAR(255) NULL DEFAULT '',
            genera_ingreso ENUM('DIRECTO','INDIRECTO','NO') NOT NULL DEFAULT 'DIRECTO',
            estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_gf_bu_codigo (codigo),
            KEY idx_gf_bu_estado (estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_org_areas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(40) NOT NULL,
            nombre VARCHAR(160) NOT NULL,
            descripcion VARCHAR(255) NULL DEFAULT '',
            estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_gf_org_area_codigo (codigo),
            KEY idx_gf_org_area_estado (estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_org_subareas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            area_id INT UNSIGNED NOT NULL,
            codigo VARCHAR(40) NOT NULL,
            nombre VARCHAR(160) NOT NULL,
            descripcion VARCHAR(255) NULL DEFAULT '',
            estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_gf_org_sub_codigo (codigo),
            KEY idx_gf_org_sub_area (area_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_org_cargos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            area_id INT UNSIGNED NULL,
            subarea_id INT UNSIGNED NULL,
            codigo VARCHAR(40) NOT NULL DEFAULT '',
            nombre VARCHAR(160) NOT NULL,
            descripcion VARCHAR(255) NULL DEFAULT '',
            tipo_costo ENUM('DIRECTO','COMPARTIDO','CORPORATIVO','SOPORTE') NOT NULL DEFAULT 'DIRECTO',
            genera_ingreso ENUM('DIRECTO','INDIRECTO','NO') NOT NULL DEFAULT 'NO',
            estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_gf_org_cargo_nombre (nombre),
            KEY idx_gf_org_cargo_area (area_id),
            KEY idx_gf_org_cargo_sub (subarea_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_centros_costo (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(40) NOT NULL,
            nombre VARCHAR(160) NOT NULL,
            tipo ENUM('DIRECTO','COMPARTIDO','SEDE','CORPORATIVO','SOPORTE') NOT NULL DEFAULT 'DIRECTO',
            sede_key VARCHAR(80) NULL,
            sede_nombre VARCHAR(120) NULL,
            unidad_negocio_id INT UNSIGNED NULL,
            area_id INT UNSIGNED NULL,
            subarea_id INT UNSIGNED NULL,
            descripcion VARCHAR(255) NULL DEFAULT '',
            estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
            fecha_inicio DATE NULL,
            fecha_fin DATE NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_gf_cc_codigo (codigo),
            KEY idx_gf_cc_sede (sede_key),
            KEY idx_gf_cc_bu (unidad_negocio_id),
            KEY idx_gf_cc_area (area_id),
            KEY idx_gf_cc_tipo (tipo),
            KEY idx_gf_cc_estado (estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Placeholder para fases siguientes (no usado aún; evita migraciones futuras disruptivas).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_colaborador_centros_costo (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            colaborador_id VARCHAR(64) NOT NULL,
            centro_costo_id INT UNSIGNED NOT NULL,
            porcentaje DECIMAL(6,2) NOT NULL DEFAULT 100.00,
            fecha_inicio DATE NOT NULL,
            fecha_fin DATE NULL,
            es_principal TINYINT(1) NOT NULL DEFAULT 0,
            motivo VARCHAR(255) NULL DEFAULT '',
            estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_gf_ccc_colab (colaborador_id),
            KEY idx_gf_ccc_cc (centro_costo_id),
            KEY idx_gf_ccc_fechas (fecha_inicio, fecha_fin)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    grooflow_cost_centers_seed_catalogs($pdo);
}

/** @return array{codigo:string,nombre:string} */
function grooflow_cost_centers_sede_abbrev(string $sedeNombre): array
{
    $name = trim($sedeNombre);
    $map = [
        'miraflores' => 'MIR',
        'benavides' => 'BEN',
        'surco' => 'SUR',
        'la molina' => 'MOL',
        'san isidro' => 'ISI',
        'pet movil' => 'MOV',
        'petmóvil' => 'MOV',
        'pet movil / transporte' => 'MOV',
    ];
    $key = mb_strtolower($name);
    $key = strtr($key, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $abbr = $map[$key] ?? strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $key) ?: 'SED', 0, 3));

    return ['codigo' => $abbr, 'nombre' => $name];
}

function grooflow_cost_centers_seed_catalogs(PDO $pdo): void
{
    $buCount = (int) $pdo->query('SELECT COUNT(*) FROM grooflow_unidades_negocio WHERE is_deleted = 0')->fetchColumn();
    if ($buCount === 0) {
        $ins = $pdo->prepare('INSERT INTO grooflow_unidades_negocio (codigo, nombre, genera_ingreso, sort_order) VALUES (?,?,?,?)');
        $ins->execute(['MED', 'Medicina', 'DIRECTO', 10]);
        $ins->execute(['PEL', 'Peluquería', 'DIRECTO', 20]);
        $ins->execute(['PET', 'Petshop', 'DIRECTO', 30]);
        $ins->execute(['MOV', 'Pet Móvil', 'DIRECTO', 40]);
    }

    $areaCount = (int) $pdo->query('SELECT COUNT(*) FROM grooflow_org_areas WHERE is_deleted = 0')->fetchColumn();
    if ($areaCount === 0) {
        $areas = [
            ['GER', 'Gerencia', 10],
            ['MED', 'Médica', 20],
            ['PEL', 'Peluquería', 30],
            ['PET', 'Petshop', 40],
            ['ATC', 'Atención al Cliente', 50],
            ['ADF', 'Administración y Finanzas', 60],
            ['MKT', 'Marketing', 70],
            ['RRHH', 'Recursos Humanos', 80],
            ['COM', 'Compras y Logística', 90],
            ['OPE', 'Operaciones', 100],
            ['TRA', 'Transporte', 110],
            ['MSG', 'Mantenimiento y Servicios Generales', 120],
            ['AUD', 'Auditoría', 130],
        ];
        $ins = $pdo->prepare('INSERT INTO grooflow_org_areas (codigo, nombre, sort_order) VALUES (?,?,?)');
        foreach ($areas as $a) {
            $ins->execute($a);
        }
    }

    $subCount = (int) $pdo->query('SELECT COUNT(*) FROM grooflow_org_subareas WHERE is_deleted = 0')->fetchColumn();
    if ($subCount === 0) {
        $areaIds = [];
        foreach ($pdo->query('SELECT id, codigo FROM grooflow_org_areas WHERE is_deleted = 0') as $row) {
            $areaIds[(string) $row['codigo']] = (int) $row['id'];
        }
        $subs = [
            ['GER', 'GER-GG', 'Gerencia General', 10],
            ['GER', 'GER-GM', 'Gerencia Médica', 20],
            ['GER', 'GER-OP', 'Operaciones', 30],
            ['MED', 'MED-MV', 'Médicos Veterinarios', 10],
            ['MED', 'MED-AV', 'Asistentes Veterinarios', 20],
            ['PEL', 'PEL-PE', 'Peluqueros', 10],
            ['PEL', 'PEL-BA', 'Baño', 20],
            ['PET', 'PET-GT', 'Gestión de Tienda', 10],
            ['ATC', 'ATC-CA', 'Counter / Admisión', 10],
            ['ADF', 'ADF-CO', 'Contabilidad', 10],
            ['ADF', 'ADF-AD', 'Administración', 20],
            ['ADF', 'ADF-TE', 'Tesorería', 30],
            ['MKT', 'MKT-MK', 'Marketing', 10],
            ['MKT', 'MKT-MD', 'Marketing Digital', 20],
            ['MKT', 'MKT-CO', 'Contenido', 30],
            ['RRHH', 'RRHH-RH', 'Recursos Humanos', 10],
            ['COM', 'COM-CO', 'Compras', 10],
            ['COM', 'COM-LO', 'Logística', 20],
            ['COM', 'COM-AL', 'Almacén', 30],
            ['OPE', 'OPE-AS', 'Administración de Sede', 10],
            ['TRA', 'TRA-PM', 'Pet Móvil / Transporte', 10],
            ['MSG', 'MSG-MA', 'Mantenimiento', 10],
            ['MSG', 'MSG-LI', 'Limpieza', 20],
            ['MSG', 'MSG-SG', 'Servicios Generales', 30],
            ['AUD', 'AUD-AU', 'Auditoría', 10],
            ['AUD', 'AUD-CI', 'Control Interno', 20],
        ];
        $ins = $pdo->prepare('INSERT INTO grooflow_org_subareas (area_id, codigo, nombre, sort_order) VALUES (?,?,?,?)');
        foreach ($subs as [$ac, $code, $name, $ord]) {
            $aid = $areaIds[$ac] ?? null;
            if ($aid) {
                $ins->execute([$aid, $code, $name, $ord]);
            }
        }
    }

    $cargoCount = (int) $pdo->query('SELECT COUNT(*) FROM grooflow_org_cargos WHERE is_deleted = 0')->fetchColumn();
    if ($cargoCount === 0) {
        $areaIds = [];
        foreach ($pdo->query('SELECT id, codigo FROM grooflow_org_areas WHERE is_deleted = 0') as $row) {
            $areaIds[(string) $row['codigo']] = (int) $row['id'];
        }
        $subIds = [];
        foreach ($pdo->query('SELECT id, codigo FROM grooflow_org_subareas WHERE is_deleted = 0') as $row) {
            $subIds[(string) $row['codigo']] = (int) $row['id'];
        }
        // area_code, sub_code|null, nombre, tipo_costo, genera_ingreso, sort
        $cargos = [
            ['GER', 'GER-GG', 'Gerente General', 'CORPORATIVO', 'NO', 10],
            ['GER', 'GER-GM', 'Gerente Área Médica', 'CORPORATIVO', 'NO', 20],
            ['GER', 'GER-OP', 'Gerente de Operaciones', 'CORPORATIVO', 'NO', 30],
            ['AUD', 'AUD-AU', 'Jefe de Auditoría', 'CORPORATIVO', 'NO', 10],
            ['ADF', 'ADF-CO', 'Analista Contable', 'CORPORATIVO', 'NO', 10],
            ['ADF', 'ADF-CO', 'Asistente de Contabilidad', 'CORPORATIVO', 'NO', 20],
            ['MKT', 'MKT-MK', 'Jefe de Marketing', 'CORPORATIVO', 'NO', 10],
            ['MKT', 'MKT-MD', 'Trafficker Digital', 'CORPORATIVO', 'NO', 20],
            ['MKT', 'MKT-CO', 'Creador de Contenido / Asistente de Marketing', 'CORPORATIVO', 'NO', 30],
            ['MKT', 'MKT-CO', 'Creador de Contenido / Community Manager', 'CORPORATIVO', 'NO', 40],
            ['RRHH', 'RRHH-RH', 'Jefe de Recursos Humanos', 'CORPORATIVO', 'NO', 10],
            ['RRHH', 'RRHH-RH', 'Asistente de RRHH', 'CORPORATIVO', 'NO', 20],
            ['COM', 'COM-CO', 'Jefe de Compras', 'CORPORATIVO', 'NO', 10],
            ['MED', 'MED-MV', 'Médico Veterinario', 'DIRECTO', 'DIRECTO', 10],
            ['MED', 'MED-MV', 'Médico Veterinario Jr.', 'DIRECTO', 'DIRECTO', 20],
            ['MED', 'MED-MV', 'Médico C0/C0 Líder', 'DIRECTO', 'DIRECTO', 30],
            ['MED', 'MED-MV', 'Médico Jefe', 'DIRECTO', 'DIRECTO', 40],
            ['MED', 'MED-AV', 'Asistente Veterinario', 'DIRECTO', 'DIRECTO', 10],
            ['MED', 'MED-AV', 'Asistente Veterinario Principal', 'DIRECTO', 'DIRECTO', 20],
            ['ATC', 'ATC-CA', 'Counter Médico', 'COMPARTIDO', 'INDIRECTO', 10],
            ['ATC', 'ATC-CA', 'Counter', 'COMPARTIDO', 'INDIRECTO', 20],
            ['PEL', 'PEL-PE', 'Peluquero Principal', 'DIRECTO', 'DIRECTO', 10],
            ['PEL', 'PEL-PE', 'Peluquero', 'DIRECTO', 'DIRECTO', 20],
            ['PEL', 'PEL-PE', 'Peluquero Jr.', 'DIRECTO', 'DIRECTO', 30],
            ['PEL', 'PEL-BA', 'Bañador', 'DIRECTO', 'DIRECTO', 10],
            ['PEL', 'PEL-BA', 'Bañador Alistador', 'DIRECTO', 'DIRECTO', 20],
            ['PET', 'PET-GT', 'Gerente de Tienda', 'DIRECTO', 'INDIRECTO', 10],
            ['PET', 'PET-GT', 'Supervisor de Tienda', 'DIRECTO', 'INDIRECTO', 20],
            ['PET', 'PET-GT', 'Supervisor de Tienda Jr.', 'DIRECTO', 'INDIRECTO', 30],
            ['OPE', 'OPE-AS', 'Administrador de Sede', 'COMPARTIDO', 'INDIRECTO', 10],
            ['TRA', 'TRA-PM', 'Chofer', 'SOPORTE', 'INDIRECTO', 10],
            ['MSG', 'MSG-MA', 'Carpintero', 'SOPORTE', 'NO', 10],
            ['MSG', 'MSG-MA', 'Personal de Mantenimiento General', 'SOPORTE', 'NO', 20],
            ['MSG', 'MSG-LI', 'Personal de Limpieza', 'SOPORTE', 'NO', 10],
        ];
        $ins = $pdo->prepare('INSERT INTO grooflow_org_cargos (area_id, subarea_id, nombre, tipo_costo, genera_ingreso, sort_order) VALUES (?,?,?,?,?,?)');
        foreach ($cargos as [$ac, $sc, $name, $tipo, $gi, $ord]) {
            $ins->execute([$areaIds[$ac] ?? null, $subIds[$sc] ?? null, $name, $tipo, $gi, $ord]);
        }
    }

    grooflow_cost_centers_seed_corporate($pdo);
}

function grooflow_cost_centers_seed_corporate(PDO $pdo): void
{
    $exists = $pdo->prepare('SELECT id FROM grooflow_centros_costo WHERE codigo = ? AND is_deleted = 0 LIMIT 1');
    $ins = $pdo->prepare('INSERT INTO grooflow_centros_costo (codigo, nombre, tipo, descripcion, sort_order) VALUES (?,?,?,?,?)');
    $corp = [
        ['GER-CEN', 'Gerencia Central', 'CORPORATIVO', 'Centro corporativo de gerencia', 10],
        ['CON-CEN', 'Contabilidad Central', 'CORPORATIVO', 'Centro corporativo de contabilidad', 20],
        ['MKT-CEN', 'Marketing Central', 'CORPORATIVO', 'Centro corporativo de marketing', 30],
        ['RRHH-CEN', 'RRHH Central', 'CORPORATIVO', 'Centro corporativo de recursos humanos', 40],
        ['COM-CEN', 'Compras Central', 'CORPORATIVO', 'Centro corporativo de compras', 50],
        ['AUD-CEN', 'Auditoría Central', 'CORPORATIVO', 'Centro corporativo de auditoría', 60],
    ];
    foreach ($corp as $row) {
        $exists->execute([$row[0]]);
        if (!$exists->fetchColumn()) {
            $ins->execute($row);
        }
    }
}

/**
 * Genera centros por sede (MED/PEL/PET/CTR/ADM/MAN/LIM/MOV) sin duplicar códigos.
 *
 * @param list<string> $sedeNombres
 */
function grooflow_cost_centers_ensure_for_sedes(PDO $pdo, array $sedeNombres): int
{
    grooflow_cost_centers_ensure_schema($pdo);
    grooflow_cost_centers_seed_corporate($pdo);

    $buIds = [];
    foreach ($pdo->query('SELECT id, codigo FROM grooflow_unidades_negocio WHERE is_deleted = 0') as $row) {
        $buIds[(string) $row['codigo']] = (int) $row['id'];
    }
    $areaIds = [];
    foreach ($pdo->query('SELECT id, codigo FROM grooflow_org_areas WHERE is_deleted = 0') as $row) {
        $areaIds[(string) $row['codigo']] = (int) $row['id'];
    }

    $templates = [
        ['MED', 'Medicina', 'DIRECTO', 'MED', 'MED'],
        ['PEL', 'Peluquería', 'DIRECTO', 'PEL', 'PEL'],
        ['PET', 'Petshop', 'DIRECTO', 'PET', 'PET'],
        ['CTR', 'Counter / Admisión', 'COMPARTIDO', null, 'ATC'],
        ['ADM', 'Administración de Sede', 'COMPARTIDO', null, 'OPE'],
        ['MAN', 'Mantenimiento', 'SOPORTE', null, 'MSG'],
        ['LIM', 'Limpieza', 'SOPORTE', null, 'MSG'],
        ['MOV', 'Pet Móvil / Transporte', 'SOPORTE', 'MOV', 'TRA'],
        ['GEN', 'Gastos Generales de Sede', 'SEDE', null, null],
    ];

    $exists = $pdo->prepare('SELECT id FROM grooflow_centros_costo WHERE codigo = ? AND is_deleted = 0 LIMIT 1');
    $ins = $pdo->prepare('
        INSERT INTO grooflow_centros_costo
            (codigo, nombre, tipo, sede_key, sede_nombre, unidad_negocio_id, area_id, sort_order)
        VALUES (?,?,?,?,?,?,?,?)
    ');
    $created = 0;
    $ord = 100;
    foreach ($sedeNombres as $sedeNombre) {
        $sedeNombre = trim((string) $sedeNombre);
        if ($sedeNombre === '') {
            continue;
        }
        $abb = grooflow_cost_centers_sede_abbrev($sedeNombre);
        $sedeKey = mb_strtolower($abb['nombre']);
        foreach ($templates as [$pref, $label, $tipo, $buCode, $areaCode]) {
            $codigo = $pref . '-' . $abb['codigo'];
            $exists->execute([$codigo]);
            if ($exists->fetchColumn()) {
                continue;
            }
            $ins->execute([
                $codigo,
                $label . ' - ' . $abb['nombre'],
                $tipo,
                $sedeKey,
                $abb['nombre'],
                $buCode ? ($buIds[$buCode] ?? null) : null,
                $areaCode ? ($areaIds[$areaCode] ?? null) : null,
                $ord,
            ]);
            $created++;
            $ord += 10;
        }
    }

    return $created;
}

// --- CRUD helpers ---

function grooflow_cc_row_active_sql(string $alias = ''): string
{
    $p = $alias !== '' ? $alias . '.' : '';

    return $p . "is_deleted = 0";
}

/** @return list<array<string,mixed>> */
function grooflow_unidades_negocio_list(PDO $pdo, bool $onlyActive = true): array
{
    grooflow_cost_centers_ensure_schema($pdo);
    $sql = 'SELECT * FROM grooflow_unidades_negocio WHERE is_deleted = 0';
    if ($onlyActive) {
        $sql .= " AND estado = 'activo'";
    }

    return $pdo->query($sql . ' ORDER BY sort_order, nombre')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @param array<string,mixed> $data */
function grooflow_unidades_negocio_save(PDO $pdo, array $data, ?int $id = null): array
{
    grooflow_cost_centers_ensure_schema($pdo);
    $codigo = strtoupper(trim((string) ($data['codigo'] ?? '')));
    $nombre = trim((string) ($data['nombre'] ?? ''));
    if ($codigo === '' || $nombre === '') {
        throw new InvalidArgumentException('código y nombre son obligatorios');
    }
    $gi = (string) ($data['genera_ingreso'] ?? 'DIRECTO');
    if (!in_array($gi, ['DIRECTO', 'INDIRECTO', 'NO'], true)) {
        $gi = 'DIRECTO';
    }
    $estado = (($data['estado'] ?? 'activo') === 'inactivo') ? 'inactivo' : 'activo';
    $desc = trim((string) ($data['descripcion'] ?? ''));
    $sort = (int) ($data['sort_order'] ?? 0);
    if ($id) {
        $pdo->prepare('UPDATE grooflow_unidades_negocio SET codigo=?, nombre=?, descripcion=?, genera_ingreso=?, estado=?, sort_order=? WHERE id=? AND is_deleted=0')
            ->execute([$codigo, $nombre, $desc, $gi, $estado, $sort, $id]);
    } else {
        $pdo->prepare('INSERT INTO grooflow_unidades_negocio (codigo, nombre, descripcion, genera_ingreso, estado, sort_order) VALUES (?,?,?,?,?,?)')
            ->execute([$codigo, $nombre, $desc, $gi, $estado, $sort]);
        $id = (int) $pdo->lastInsertId();
    }
    $st = $pdo->prepare('SELECT * FROM grooflow_unidades_negocio WHERE id=?');
    $st->execute([$id]);

    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

function grooflow_unidades_negocio_delete(PDO $pdo, int $id): void
{
    $pdo->prepare("UPDATE grooflow_unidades_negocio SET is_deleted=1, estado='inactivo' WHERE id=?")->execute([$id]);
}

/** @return list<array<string,mixed>> */
function grooflow_org_areas_list(PDO $pdo, bool $onlyActive = true): array
{
    grooflow_cost_centers_ensure_schema($pdo);
    $sql = 'SELECT * FROM grooflow_org_areas WHERE is_deleted = 0';
    if ($onlyActive) {
        $sql .= " AND estado = 'activo'";
    }

    return $pdo->query($sql . ' ORDER BY sort_order, nombre')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @param array<string,mixed> $data */
function grooflow_org_areas_save(PDO $pdo, array $data, ?int $id = null): array
{
    grooflow_cost_centers_ensure_schema($pdo);
    $codigo = strtoupper(trim((string) ($data['codigo'] ?? '')));
    $nombre = trim((string) ($data['nombre'] ?? ''));
    if ($codigo === '' || $nombre === '') {
        throw new InvalidArgumentException('código y nombre son obligatorios');
    }
    $estado = (($data['estado'] ?? 'activo') === 'inactivo') ? 'inactivo' : 'activo';
    $desc = trim((string) ($data['descripcion'] ?? ''));
    $sort = (int) ($data['sort_order'] ?? 0);
    if ($id) {
        $pdo->prepare('UPDATE grooflow_org_areas SET codigo=?, nombre=?, descripcion=?, estado=?, sort_order=? WHERE id=? AND is_deleted=0')
            ->execute([$codigo, $nombre, $desc, $estado, $sort, $id]);
    } else {
        $pdo->prepare('INSERT INTO grooflow_org_areas (codigo, nombre, descripcion, estado, sort_order) VALUES (?,?,?,?,?)')
            ->execute([$codigo, $nombre, $desc, $estado, $sort]);
        $id = (int) $pdo->lastInsertId();
    }
    $st = $pdo->prepare('SELECT * FROM grooflow_org_areas WHERE id=?');
    $st->execute([$id]);

    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

function grooflow_org_areas_delete(PDO $pdo, int $id): void
{
    $pdo->prepare("UPDATE grooflow_org_areas SET is_deleted=1, estado='inactivo' WHERE id=?")->execute([$id]);
}

/** @return list<array<string,mixed>> */
function grooflow_org_subareas_list(PDO $pdo, bool $onlyActive = true, ?int $areaId = null): array
{
    grooflow_cost_centers_ensure_schema($pdo);
    $sql = 'SELECT s.*, a.nombre AS area_nombre, a.codigo AS area_codigo
            FROM grooflow_org_subareas s
            LEFT JOIN grooflow_org_areas a ON a.id = s.area_id
            WHERE s.is_deleted = 0';
    $params = [];
    if ($onlyActive) {
        $sql .= " AND s.estado = 'activo'";
    }
    if ($areaId) {
        $sql .= ' AND s.area_id = ?';
        $params[] = $areaId;
    }
    $sql .= ' ORDER BY s.sort_order, s.nombre';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @param array<string,mixed> $data */
function grooflow_org_subareas_save(PDO $pdo, array $data, ?int $id = null): array
{
    grooflow_cost_centers_ensure_schema($pdo);
    $areaId = (int) ($data['area_id'] ?? 0);
    $codigo = strtoupper(trim((string) ($data['codigo'] ?? '')));
    $nombre = trim((string) ($data['nombre'] ?? ''));
    if ($areaId <= 0 || $codigo === '' || $nombre === '') {
        throw new InvalidArgumentException('área, código y nombre son obligatorios');
    }
    $estado = (($data['estado'] ?? 'activo') === 'inactivo') ? 'inactivo' : 'activo';
    $desc = trim((string) ($data['descripcion'] ?? ''));
    $sort = (int) ($data['sort_order'] ?? 0);
    if ($id) {
        $pdo->prepare('UPDATE grooflow_org_subareas SET area_id=?, codigo=?, nombre=?, descripcion=?, estado=?, sort_order=? WHERE id=? AND is_deleted=0')
            ->execute([$areaId, $codigo, $nombre, $desc, $estado, $sort, $id]);
    } else {
        $pdo->prepare('INSERT INTO grooflow_org_subareas (area_id, codigo, nombre, descripcion, estado, sort_order) VALUES (?,?,?,?,?,?)')
            ->execute([$areaId, $codigo, $nombre, $desc, $estado, $sort]);
        $id = (int) $pdo->lastInsertId();
    }
    $rows = grooflow_org_subareas_list($pdo, false);
    foreach ($rows as $r) {
        if ((int) $r['id'] === $id) {
            return $r;
        }
    }

    return [];
}

function grooflow_org_subareas_delete(PDO $pdo, int $id): void
{
    $pdo->prepare("UPDATE grooflow_org_subareas SET is_deleted=1, estado='inactivo' WHERE id=?")->execute([$id]);
}

/** @return list<array<string,mixed>> */
function grooflow_org_cargos_list(PDO $pdo, bool $onlyActive = true, ?int $areaId = null): array
{
    grooflow_cost_centers_ensure_schema($pdo);
    $sql = 'SELECT c.*, a.nombre AS area_nombre, s.nombre AS subarea_nombre
            FROM grooflow_org_cargos c
            LEFT JOIN grooflow_org_areas a ON a.id = c.area_id
            LEFT JOIN grooflow_org_subareas s ON s.id = c.subarea_id
            WHERE c.is_deleted = 0';
    $params = [];
    if ($onlyActive) {
        $sql .= " AND c.estado = 'activo'";
    }
    if ($areaId) {
        $sql .= ' AND c.area_id = ?';
        $params[] = $areaId;
    }
    $sql .= ' ORDER BY c.sort_order, c.nombre';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @param array<string,mixed> $data */
function grooflow_org_cargos_save(PDO $pdo, array $data, ?int $id = null): array
{
    grooflow_cost_centers_ensure_schema($pdo);
    $nombre = trim((string) ($data['nombre'] ?? ''));
    if ($nombre === '') {
        throw new InvalidArgumentException('nombre es obligatorio');
    }
    $areaId = isset($data['area_id']) && $data['area_id'] !== '' && $data['area_id'] !== null
        ? (int) $data['area_id'] : null;
    $subId = isset($data['subarea_id']) && $data['subarea_id'] !== '' && $data['subarea_id'] !== null
        ? (int) $data['subarea_id'] : null;
    $tipo = (string) ($data['tipo_costo'] ?? 'DIRECTO');
    if (!in_array($tipo, ['DIRECTO', 'COMPARTIDO', 'CORPORATIVO', 'SOPORTE'], true)) {
        $tipo = 'DIRECTO';
    }
    $gi = (string) ($data['genera_ingreso'] ?? 'NO');
    if (!in_array($gi, ['DIRECTO', 'INDIRECTO', 'NO'], true)) {
        $gi = 'NO';
    }
    $estado = (($data['estado'] ?? 'activo') === 'inactivo') ? 'inactivo' : 'activo';
    $desc = trim((string) ($data['descripcion'] ?? ''));
    $codigo = strtoupper(trim((string) ($data['codigo'] ?? '')));
    $sort = (int) ($data['sort_order'] ?? 0);
    if ($id) {
        $pdo->prepare('UPDATE grooflow_org_cargos SET area_id=?, subarea_id=?, codigo=?, nombre=?, descripcion=?, tipo_costo=?, genera_ingreso=?, estado=?, sort_order=? WHERE id=? AND is_deleted=0')
            ->execute([$areaId, $subId, $codigo, $nombre, $desc, $tipo, $gi, $estado, $sort, $id]);
    } else {
        $pdo->prepare('INSERT INTO grooflow_org_cargos (area_id, subarea_id, codigo, nombre, descripcion, tipo_costo, genera_ingreso, estado, sort_order) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$areaId, $subId, $codigo, $nombre, $desc, $tipo, $gi, $estado, $sort]);
        $id = (int) $pdo->lastInsertId();
    }
    foreach (grooflow_org_cargos_list($pdo, false) as $r) {
        if ((int) $r['id'] === $id) {
            return $r;
        }
    }

    return [];
}

function grooflow_org_cargos_delete(PDO $pdo, int $id): void
{
    $pdo->prepare("UPDATE grooflow_org_cargos SET is_deleted=1, estado='inactivo' WHERE id=?")->execute([$id]);
}

/** @return list<array<string,mixed>> */
function grooflow_centros_costo_list(PDO $pdo, bool $onlyActive = true, ?string $sedeKey = null): array
{
    grooflow_cost_centers_ensure_schema($pdo);
    $sql = 'SELECT cc.*,
                bu.nombre AS unidad_negocio_nombre, bu.codigo AS unidad_negocio_codigo,
                a.nombre AS area_nombre, s.nombre AS subarea_nombre
            FROM grooflow_centros_costo cc
            LEFT JOIN grooflow_unidades_negocio bu ON bu.id = cc.unidad_negocio_id
            LEFT JOIN grooflow_org_areas a ON a.id = cc.area_id
            LEFT JOIN grooflow_org_subareas s ON s.id = cc.subarea_id
            WHERE cc.is_deleted = 0';
    $params = [];
    if ($onlyActive) {
        $sql .= " AND cc.estado = 'activo'";
    }
    if ($sedeKey !== null && $sedeKey !== '') {
        $sql .= ' AND (cc.sede_key = ? OR cc.tipo = \'CORPORATIVO\')';
        $params[] = mb_strtolower($sedeKey);
    }
    $sql .= ' ORDER BY cc.tipo, cc.sort_order, cc.codigo';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @param array<string,mixed> $data */
function grooflow_centros_costo_save(PDO $pdo, array $data, ?int $id = null): array
{
    grooflow_cost_centers_ensure_schema($pdo);
    $codigo = strtoupper(trim((string) ($data['codigo'] ?? '')));
    $nombre = trim((string) ($data['nombre'] ?? ''));
    if ($codigo === '' || $nombre === '') {
        throw new InvalidArgumentException('código y nombre son obligatorios');
    }
    $tipo = (string) ($data['tipo'] ?? 'DIRECTO');
    if (!in_array($tipo, ['DIRECTO', 'COMPARTIDO', 'SEDE', 'CORPORATIVO', 'SOPORTE'], true)) {
        $tipo = 'DIRECTO';
    }
    $sedeNombre = trim((string) ($data['sede_nombre'] ?? ''));
    $sedeKey = trim((string) ($data['sede_key'] ?? ''));
    if ($sedeKey === '' && $sedeNombre !== '') {
        $sedeKey = mb_strtolower($sedeNombre);
    }
    if ($tipo === 'CORPORATIVO') {
        $sedeKey = null;
        $sedeNombre = null;
    }
    $buId = isset($data['unidad_negocio_id']) && $data['unidad_negocio_id'] !== '' && $data['unidad_negocio_id'] !== null
        ? (int) $data['unidad_negocio_id'] : null;
    $areaId = isset($data['area_id']) && $data['area_id'] !== '' && $data['area_id'] !== null
        ? (int) $data['area_id'] : null;
    $subId = isset($data['subarea_id']) && $data['subarea_id'] !== '' && $data['subarea_id'] !== null
        ? (int) $data['subarea_id'] : null;
    $estado = (($data['estado'] ?? 'activo') === 'inactivo') ? 'inactivo' : 'activo';
    $desc = trim((string) ($data['descripcion'] ?? ''));
    $fi = !empty($data['fecha_inicio']) ? (string) $data['fecha_inicio'] : null;
    $ff = !empty($data['fecha_fin']) ? (string) $data['fecha_fin'] : null;
    $sort = (int) ($data['sort_order'] ?? 0);

    if ($id) {
        $pdo->prepare('UPDATE grooflow_centros_costo SET codigo=?, nombre=?, tipo=?, sede_key=?, sede_nombre=?, unidad_negocio_id=?, area_id=?, subarea_id=?, descripcion=?, estado=?, fecha_inicio=?, fecha_fin=?, sort_order=? WHERE id=? AND is_deleted=0')
            ->execute([$codigo, $nombre, $tipo, $sedeKey, $sedeNombre, $buId, $areaId, $subId, $desc, $estado, $fi, $ff, $sort, $id]);
    } else {
        $pdo->prepare('INSERT INTO grooflow_centros_costo (codigo, nombre, tipo, sede_key, sede_nombre, unidad_negocio_id, area_id, subarea_id, descripcion, estado, fecha_inicio, fecha_fin, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$codigo, $nombre, $tipo, $sedeKey, $sedeNombre, $buId, $areaId, $subId, $desc, $estado, $fi, $ff, $sort]);
        $id = (int) $pdo->lastInsertId();
    }
    foreach (grooflow_centros_costo_list($pdo, false) as $r) {
        if ((int) $r['id'] === $id) {
            return $r;
        }
    }

    return [];
}

function grooflow_centros_costo_delete(PDO $pdo, int $id): void
{
    $pdo->prepare("UPDATE grooflow_centros_costo SET is_deleted=1, estado='inactivo' WHERE id=?")->execute([$id]);
}

/** @return array<string,mixed> */
function grooflow_cost_centers_dashboard_stats(PDO $pdo): array
{
    grooflow_cost_centers_ensure_schema($pdo);
    $cc = (int) $pdo->query("SELECT COUNT(*) FROM grooflow_centros_costo WHERE is_deleted=0 AND estado='activo'")->fetchColumn();
    $bu = (int) $pdo->query("SELECT COUNT(*) FROM grooflow_unidades_negocio WHERE is_deleted=0 AND estado='activo'")->fetchColumn();
    $areas = (int) $pdo->query("SELECT COUNT(*) FROM grooflow_org_areas WHERE is_deleted=0 AND estado='activo'")->fetchColumn();
    $cargos = (int) $pdo->query("SELECT COUNT(*) FROM grooflow_org_cargos WHERE is_deleted=0 AND estado='activo'")->fetchColumn();
    $byTipo = $pdo->query("SELECT tipo, COUNT(*) AS c FROM grooflow_centros_costo WHERE is_deleted=0 AND estado='activo' GROUP BY tipo")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    return [
        'centros_activos' => $cc,
        'unidades_negocio' => $bu,
        'areas' => $areas,
        'cargos' => $cargos,
        'por_tipo' => $byTipo,
        'gastos_pendientes' => 0,
        'gastos_distribuidos' => 0,
        'nota' => 'KPIs de gasto se habilitan en la fase de distribución',
    ];
}
