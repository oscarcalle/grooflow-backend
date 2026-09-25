<?php

declare(strict_types=1);

/**
 * Contabilidad gerencial: naturalezas, estructura P&L 01–08, mapping cuenta→dims,
 * drivers, gastos compartidos, QA y reportes A–L.
 * No multiplica el plan contable; clasificación gerencial aditiva.
 */

function grooflow_mgr_pnl_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    require_once __DIR__ . '/grooflow_cost_centers.php';
    grooflow_cost_centers_ensure_schema($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_mgr_naturalezas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(10) NOT NULL,
            nombre VARCHAR(160) NOT NULL,
            descripcion VARCHAR(255) NULL DEFAULT '',
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_gf_nat_codigo (codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_mgr_pnl_lineas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(20) NOT NULL,
            nombre VARCHAR(200) NOT NULL,
            parent_codigo VARCHAR(20) NULL,
            nivel TINYINT UNSIGNED NOT NULL DEFAULT 1,
            es_calculo TINYINT(1) NOT NULL DEFAULT 0,
            formula VARCHAR(80) NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_gf_pnl_codigo (codigo),
            KEY idx_gf_pnl_parent (parent_codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_mgr_drivers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(40) NOT NULL,
            nombre VARCHAR(160) NOT NULL,
            descripcion VARCHAR(255) NULL DEFAULT '',
            unidad VARCHAR(40) NULL DEFAULT '',
            estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_gf_drv_codigo (codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_mgr_cuenta_mapping (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cuenta_codigo VARCHAR(40) NOT NULL,
            cuenta_nombre VARCHAR(255) NULL DEFAULT '',
            naturaleza_codigo VARCHAR(10) NULL,
            pnl_codigo VARCHAR(20) NULL,
            area_id INT UNSIGNED NULL,
            subarea_id INT UNSIGNED NULL,
            centro_costo_id INT UNSIGNED NULL,
            tipo_costo ENUM('DIRECTO','INDIRECTO','NA') NOT NULL DEFAULT 'NA',
            driver_id INT UNSIGNED NULL,
            keyword_match VARCHAR(255) NULL DEFAULT '',
            prioridad SMALLINT NOT NULL DEFAULT 100,
            vigencia_desde DATE NULL,
            vigencia_hasta DATE NULL,
            estado ENUM('activo','inactivo','borrador') NOT NULL DEFAULT 'activo',
            notas VARCHAR(255) NULL DEFAULT '',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_gf_map_cuenta (cuenta_codigo),
            KEY idx_gf_map_pnl (pnl_codigo),
            KEY idx_gf_map_nat (naturaleza_codigo),
            KEY idx_gf_map_estado (estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_mgr_keyword_rules (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            keyword VARCHAR(120) NOT NULL,
            naturaleza_codigo VARCHAR(10) NULL,
            pnl_codigo VARCHAR(20) NULL,
            area_codigo VARCHAR(40) NULL,
            centro_codigo VARCHAR(40) NULL,
            tipo_costo ENUM('DIRECTO','INDIRECTO','NA') NOT NULL DEFAULT 'DIRECTO',
            prioridad SMALLINT NOT NULL DEFAULT 50,
            vigencia_desde DATE NULL,
            vigencia_hasta DATE NULL,
            estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_gf_kw_kw (keyword),
            KEY idx_gf_kw_estado (estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_mgr_shared_dist (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(40) NOT NULL,
            nombre VARCHAR(160) NOT NULL,
            area_origen_id INT UNSIGNED NULL,
            centro_origen_id INT UNSIGNED NULL,
            driver_id INT UNSIGNED NULL,
            vigencia_desde DATE NULL,
            vigencia_hasta DATE NULL,
            estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_gf_shared_codigo (codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_mgr_shared_dist_detalle (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            shared_id INT UNSIGNED NOT NULL,
            area_destino_id INT UNSIGNED NULL,
            centro_destino_id INT UNSIGNED NOT NULL,
            porcentaje DECIMAL(6,2) NOT NULL DEFAULT 0,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_gf_shared_det (shared_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_mgr_reclass_hist (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entidad_tipo VARCHAR(40) NOT NULL,
            entidad_id VARCHAR(80) NOT NULL,
            campo VARCHAR(80) NOT NULL,
            valor_antes TEXT NULL,
            valor_despues TEXT NULL,
            motivo VARCHAR(255) NULL,
            created_by VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_gf_reclass_ent (entidad_tipo, entidad_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    grooflow_mgr_pnl_seed($pdo);
    grooflow_mgr_align_org_and_cc($pdo);
}

function grooflow_mgr_pnl_seed(PDO $pdo): void
{
    $natCount = (int) $pdo->query('SELECT COUNT(*) FROM grooflow_mgr_naturalezas WHERE is_deleted=0')->fetchColumn();
    if ($natCount === 0) {
        $nats = [
            ['N01', 'Ingresos', 10],
            ['N02', 'Personal', 20],
            ['N03', 'Servicios médicos directos', 30],
            ['N04', 'Insumos médicos', 40],
            ['N05', 'Grooming / Peluquería', 50],
            ['N06', 'Petshop', 60],
            ['N07', 'Locales y servicios básicos', 70],
            ['N08', 'Logística y transporte', 80],
            ['N09', 'Tecnología y sistemas', 90],
            ['N10', 'Marketing y ventas', 100],
            ['N11', 'Administración y profesionales', 110],
            ['N12', 'Limpieza y servicios generales', 120],
            ['N13', 'Gastos financieros', 130],
            ['N14', 'Otros gastos', 140],
            ['N15', 'Otros ingresos', 150],
        ];
        $ins = $pdo->prepare('INSERT INTO grooflow_mgr_naturalezas (codigo, nombre, sort_order) VALUES (?,?,?)');
        foreach ($nats as $n) {
            $ins->execute($n);
        }
    }

    $pnlCount = (int) $pdo->query('SELECT COUNT(*) FROM grooflow_mgr_pnl_lineas WHERE is_deleted=0')->fetchColumn();
    if ($pnlCount === 0) {
        $lines = [
            ['01', 'VENTAS', null, 1, 0, null, 10],
            ['01.01', 'Medicina', '01', 2, 0, null, 11],
            ['01.02', 'Peluquería', '01', 2, 0, null, 12],
            ['01.03', 'Petshop', '01', 2, 0, null, 13],
            ['01.04', 'Servicios complementarios', '01', 2, 0, null, 14],
            ['01.05', 'Pet Móvil', '01', 2, 0, null, 15],
            ['02', 'COSTO DE VENTAS', null, 1, 0, null, 20],
            ['02.01', 'Medicina', '02', 2, 0, null, 21],
            ['02.02', 'Peluquería', '02', 2, 0, null, 22],
            ['02.03', 'Petshop', '02', 2, 0, null, 23],
            ['02.04', 'Pet Móvil', '02', 2, 0, null, 24],
            ['02.05', 'Atención al Cliente (directa)', '02', 2, 0, null, 25],
            ['03', 'MARGEN BRUTO', null, 1, 1, '01-02', 30],
            ['04', 'GASTOS DE VENTAS', null, 1, 0, null, 40],
            ['04.01', 'Marketing', '04', 2, 0, null, 41],
            ['04.02', 'Atención al Cliente (compartida)', '04', 2, 0, null, 42],
            ['04.03', 'Comercial', '04', 2, 0, null, 43],
            ['04.04', 'Logística Comercial', '04', 2, 0, null, 44],
            ['05', 'GASTOS ADMINISTRATIVOS', null, 1, 0, null, 50],
            ['05.01', 'Gerencia', '05', 2, 0, null, 51],
            ['05.02', 'Auditoría', '05', 2, 0, null, 52],
            ['05.03', 'Administración y Finanzas', '05', 2, 0, null, 53],
            ['05.04', 'Recursos Humanos', '05', 2, 0, null, 54],
            ['05.05', 'Compras', '05', 2, 0, null, 55],
            ['05.06', 'Tecnología', '05', 2, 0, null, 56],
            ['05.07', 'Mantenimiento y Servicios Generales', '05', 2, 0, null, 57],
            ['06', 'OTROS INGRESOS / GASTOS OPERATIVOS', null, 1, 0, null, 60],
            ['07', 'RESULTADO FINANCIERO', null, 1, 0, null, 70],
            ['08', 'RESULTADO ANTES DE IMPUESTOS', null, 1, 1, '03-04-05+06+07', 80],
        ];
        $ins = $pdo->prepare('INSERT INTO grooflow_mgr_pnl_lineas (codigo, nombre, parent_codigo, nivel, es_calculo, formula, sort_order) VALUES (?,?,?,?,?,?,?)');
        foreach ($lines as $l) {
            $ins->execute($l);
        }
    }

    $drvCount = (int) $pdo->query('SELECT COUNT(*) FROM grooflow_mgr_drivers WHERE is_deleted=0')->fetchColumn();
    if ($drvCount === 0) {
        $drvs = [
            ['HORAS', 'Horas trabajadas', 'h'],
            ['DEDICACION', '% dedicación del colaborador', '%'],
            ['ATENCIONES', 'Número de atenciones', 'n'],
            ['TRANSACCIONES', 'Número de transacciones', 'n'],
            ['VENTAS', 'Ventas', 'PEN'],
            ['M2', 'Metros cuadrados', 'm2'],
            ['CONSUMO', 'Consumo real', 'u'],
            ['HEADCOUNT', 'Número de colaboradores', 'n'],
            ['EQUIPOS', 'Número de equipos', 'n'],
            ['USO_SERVICIO', 'Uso del servicio', 'u'],
            ['KM', 'Kilómetros', 'km'],
            ['ORDENES', 'Número de órdenes', 'n'],
            ['COMPRAS', 'Compras', 'PEN'],
            ['OTRO', 'Otro driver definido por administración', ''],
        ];
        $ins = $pdo->prepare('INSERT INTO grooflow_mgr_drivers (codigo, nombre, unidad) VALUES (?,?,?)');
        foreach ($drvs as $d) {
            $ins->execute($d);
        }
    }

    $kwCount = (int) $pdo->query('SELECT COUNT(*) FROM grooflow_mgr_keyword_rules WHERE is_deleted=0')->fetchColumn();
    if ($kwCount === 0) {
        $rules = [
            // Medicina / CV
            ['ecograf', 'N03', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['neurolog', 'N03', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['radiograf', 'N03', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['dermatolog', 'N03', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['oncolog', 'N03', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['anestesi', 'N03', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['cardiolog', 'N03', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['fisioterap', 'N03', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['endocrin', 'N03', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['cirug', 'N03', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['histopatolog', 'N03', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['geriatr', 'N03', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['traumatolog', 'N03', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['oftalmolog', 'N03', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['endoscop', 'N03', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['odontolog', 'N03', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['laboratorio', 'N03', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['examen', 'N03', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 20],
            ['oxigeno', 'N04', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['material medico', 'N04', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['medicamento', 'N04', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 10],
            ['insumo', 'N04', '02.01', 'MED', 'CC-MEDICINA', 'DIRECTO', 30],
            // Peluquería
            ['grooming', 'N05', '02.02', 'PEL', 'CC-PELUQUERIA', 'DIRECTO', 10],
            ['peluquer', 'N05', '02.02', 'PEL', 'CC-PELUQUERIA', 'DIRECTO', 10],
            ['baño', 'N05', '02.02', 'PEL', 'CC-PELUQUERIA', 'DIRECTO', 20],
            ['corte', 'N05', '02.02', 'PEL', 'CC-PELUQUERIA', 'DIRECTO', 30],
            // Petshop
            ['petshop', 'N06', '02.03', 'PET', 'CC-PETSHOP', 'DIRECTO', 10],
            ['tienda', 'N06', '02.03', 'PET', 'CC-PETSHOP', 'DIRECTO', 30],
            // Marketing / GV
            ['marketing', 'N10', '04.01', 'MKT', 'CC-MARKETING', 'INDIRECTO', 10],
            ['publicidad', 'N10', '04.01', 'MKT', 'CC-MARKETING', 'INDIRECTO', 10],
            ['campaña', 'N10', '04.01', 'MKT', 'CC-MARKETING', 'INDIRECTO', 10],
            ['comision', 'N10', '04.03', 'ATC', 'CC-ATENCION-CLIENTE', 'DIRECTO', 40],
            ['yape', 'N10', '04.03', null, null, 'DIRECTO', 40],
            ['plin', 'N10', '04.03', null, null, 'DIRECTO', 40],
            ['tarjeta', 'N10', '04.03', null, null, 'DIRECTO', 40],
            // Admin
            ['contabil', 'N11', '05.03', 'CON', 'CC-CONTABILIDAD', 'INDIRECTO', 10],
            ['auditor', 'N11', '05.02', 'AUD', 'CC-AUDITORIA', 'INDIRECTO', 10],
            ['gerencia general', 'N11', '05.01', 'GER', 'CC-GERENCIA', 'INDIRECTO', 10],
            ['gerencia medica', 'N11', '05.01', 'GER', 'CC-GERENCIA-MED', 'INDIRECTO', 10],
            ['gerencia de operaciones', 'N11', '05.01', 'GER', 'CC-GERENCIA-OPS', 'INDIRECTO', 10],
            ['recursos humanos', 'N02', '05.04', 'RRHH', 'CC-RRHH', 'INDIRECTO', 10],
            ['planilla', 'N02', '05.04', 'RRHH', 'CC-RRHH', 'INDIRECTO', 20],
            ['remuneracion', 'N02', null, null, null, 'NA', 5],
            ['compra', 'N11', '05.05', 'COM', 'CC-COMPRAS', 'INDIRECTO', 30],
            ['software', 'N09', '05.06', 'TEC', 'CC-TECNOLOGIA', 'INDIRECTO', 10],
            ['licencia', 'N09', '05.06', 'TEC', 'CC-TECNOLOGIA', 'INDIRECTO', 10],
            ['sistema', 'N09', '05.06', 'TEC', 'CC-TECNOLOGIA', 'INDIRECTO', 20],
            ['mantenimiento', 'N12', '05.07', 'MAN', 'CC-MANTENIMIENTO', 'INDIRECTO', 20],
            ['limpieza', 'N12', '05.07', 'LIM', 'CC-LIMPIEZA', 'INDIRECTO', 10],
            ['alquiler', 'N07', '05.07', 'SSE', 'CC-SERVICIOS-SEDE', 'INDIRECTO', 10],
            ['electricidad', 'N07', '05.07', 'SSE', 'CC-SERVICIOS-SEDE', 'INDIRECTO', 10],
            ['agua', 'N07', '05.07', 'SSE', 'CC-SERVICIOS-SEDE', 'INDIRECTO', 10],
            ['internet', 'N07', '05.07', 'SSE', 'CC-SERVICIOS-SEDE', 'INDIRECTO', 10],
            // Financiero
            ['interes', 'N13', '07', 'FIN', 'CC-FINANZAS', 'INDIRECTO', 10],
            ['bancario', 'N13', '07', 'FIN', 'CC-FINANZAS', 'INDIRECTO', 10],
            ['itf', 'N13', '07', 'FIN', 'CC-FINANZAS', 'INDIRECTO', 10],
            ['diferencia de cambio', 'N13', '07', 'FIN', 'CC-FINANZAS', 'INDIRECTO', 10],
            // Cuenta 62
            ['62', 'N02', null, null, null, 'NA', 1],
        ];
        $ins = $pdo->prepare('
            INSERT INTO grooflow_mgr_keyword_rules
                (keyword, naturaleza_codigo, pnl_codigo, area_codigo, centro_codigo, tipo_costo, prioridad)
            VALUES (?,?,?,?,?,?,?)
        ');
        foreach ($rules as $r) {
            $ins->execute($r);
        }
    }
}

/**
 * Extiende áreas y CC corporativos del brief (aditivo, por código).
 */
function grooflow_mgr_align_org_and_cc(PDO $pdo): void
{
    $areas = [
        ['MED', 'Medicina', 20],
        ['PEL', 'Peluquería', 30],
        ['PET', 'Petshop', 40],
        ['MOV', 'Pet Móvil', 45],
        ['ATC', 'Atención al Cliente', 50],
        ['MKT', 'Marketing', 70],
        ['GER', 'Gerencia', 10],
        ['AUD', 'Auditoría', 130],
        ['CON', 'Contabilidad', 61],
        ['FIN', 'Finanzas', 62],
        ['RRHH', 'RRHH', 80],
        ['COM', 'Compras', 90],
        ['TEC', 'Tecnología', 95],
        ['MAN', 'Mantenimiento', 121],
        ['LIM', 'Limpieza', 122],
        ['SSE', 'Servicios de Sede', 123],
        ['LOG', 'Logística', 91],
    ];
    $chk = $pdo->prepare('SELECT id FROM grooflow_org_areas WHERE codigo=? AND is_deleted=0 LIMIT 1');
    $ins = $pdo->prepare('INSERT INTO grooflow_org_areas (codigo, nombre, sort_order) VALUES (?,?,?)');
    foreach ($areas as $a) {
        $chk->execute([$a[0]]);
        if (!$chk->fetchColumn()) {
            $ins->execute($a);
        }
    }

    // Alias: ADF Contabilidad/Finanzas ya pueden existir; CON/FIN se agregan sin borrar ADF.

    $ccs = [
        ['CC-MEDICINA', 'Medicina', 'DIRECTO', 10],
        ['CC-PELUQUERIA', 'Peluquería', 'DIRECTO', 20],
        ['CC-PETSHOP', 'Petshop', 'DIRECTO', 30],
        ['CC-PET-MOVIL', 'Pet Móvil', 'DIRECTO', 40],
        ['CC-ATENCION-CLIENTE', 'Atención al Cliente', 'COMPARTIDO', 50],
        ['CC-MARKETING', 'Marketing', 'CORPORATIVO', 60],
        ['CC-GERENCIA', 'Gerencia General', 'CORPORATIVO', 70],
        ['CC-GERENCIA-MED', 'Gerencia Médica', 'CORPORATIVO', 71],
        ['CC-GERENCIA-OPS', 'Gerencia de Operaciones', 'CORPORATIVO', 72],
        ['CC-AUDITORIA', 'Auditoría', 'CORPORATIVO', 80],
        ['CC-CONTABILIDAD', 'Contabilidad', 'CORPORATIVO', 90],
        ['CC-FINANZAS', 'Finanzas', 'CORPORATIVO', 91],
        ['CC-RRHH', 'RRHH', 'CORPORATIVO', 100],
        ['CC-COMPRAS', 'Compras', 'CORPORATIVO', 110],
        ['CC-TECNOLOGIA', 'Tecnología', 'CORPORATIVO', 120],
        ['CC-MANTENIMIENTO', 'Mantenimiento', 'SOPORTE', 130],
        ['CC-LIMPIEZA', 'Limpieza', 'SOPORTE', 140],
        ['CC-SERVICIOS-SEDE', 'Servicios de Sede', 'COMPARTIDO', 150],
        ['CC-LOGISTICA', 'Logística', 'SOPORTE', 160],
    ];
    $areaIds = [];
    foreach ($pdo->query('SELECT id, codigo FROM grooflow_org_areas WHERE is_deleted=0') as $row) {
        $areaIds[(string) $row['codigo']] = (int) $row['id'];
    }
    $ccArea = [
        'CC-MEDICINA' => 'MED',
        'CC-PELUQUERIA' => 'PEL',
        'CC-PETSHOP' => 'PET',
        'CC-PET-MOVIL' => 'MOV',
        'CC-ATENCION-CLIENTE' => 'ATC',
        'CC-MARKETING' => 'MKT',
        'CC-GERENCIA' => 'GER',
        'CC-GERENCIA-MED' => 'GER',
        'CC-GERENCIA-OPS' => 'GER',
        'CC-AUDITORIA' => 'AUD',
        'CC-CONTABILIDAD' => 'CON',
        'CC-FINANZAS' => 'FIN',
        'CC-RRHH' => 'RRHH',
        'CC-COMPRAS' => 'COM',
        'CC-TECNOLOGIA' => 'TEC',
        'CC-MANTENIMIENTO' => 'MAN',
        'CC-LIMPIEZA' => 'LIM',
        'CC-SERVICIOS-SEDE' => 'SSE',
        'CC-LOGISTICA' => 'LOG',
    ];
    $exists = $pdo->prepare('SELECT id FROM grooflow_centros_costo WHERE codigo=? AND is_deleted=0 LIMIT 1');
    $insCc = $pdo->prepare('INSERT INTO grooflow_centros_costo (codigo, nombre, tipo, area_id, sort_order) VALUES (?,?,?,?,?)');
    foreach ($ccs as [$code, $name, $tipo, $ord]) {
        $exists->execute([$code]);
        if (!$exists->fetchColumn()) {
            $ac = $ccArea[$code] ?? null;
            $insCc->execute([$code, $name, $tipo, $ac ? ($areaIds[$ac] ?? null) : null, $ord]);
        }
    }
}

/** @return list<array<string,mixed>> */
function grooflow_mgr_naturalezas_list(PDO $pdo): array
{
    grooflow_mgr_pnl_ensure_schema($pdo);

    return $pdo->query("SELECT * FROM grooflow_mgr_naturalezas WHERE is_deleted=0 ORDER BY sort_order, codigo")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string,mixed>> */
function grooflow_mgr_pnl_lineas_list(PDO $pdo): array
{
    grooflow_mgr_pnl_ensure_schema($pdo);

    return $pdo->query("SELECT * FROM grooflow_mgr_pnl_lineas WHERE is_deleted=0 ORDER BY sort_order, codigo")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string,mixed>> */
function grooflow_mgr_drivers_list(PDO $pdo): array
{
    grooflow_mgr_pnl_ensure_schema($pdo);

    return $pdo->query("SELECT * FROM grooflow_mgr_drivers WHERE is_deleted=0 ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string,mixed>> */
function grooflow_mgr_mappings_list(PDO $pdo, bool $onlyActive = true): array
{
    grooflow_mgr_pnl_ensure_schema($pdo);
    $sql = "
        SELECT m.*,
               a.nombre AS area_nombre, a.codigo AS area_codigo,
               sa.nombre AS subarea_nombre,
               cc.codigo AS centro_codigo, cc.nombre AS centro_nombre,
               d.codigo AS driver_codigo, d.nombre AS driver_nombre
        FROM grooflow_mgr_cuenta_mapping m
        LEFT JOIN grooflow_org_areas a ON a.id = m.area_id
        LEFT JOIN grooflow_org_subareas sa ON sa.id = m.subarea_id
        LEFT JOIN grooflow_centros_costo cc ON cc.id = m.centro_costo_id
        LEFT JOIN grooflow_mgr_drivers d ON d.id = m.driver_id
        WHERE m.is_deleted=0
    ";
    if ($onlyActive) {
        $sql .= " AND m.estado='activo'";
    }
    $sql .= ' ORDER BY m.prioridad ASC, m.cuenta_codigo ASC';

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function grooflow_mgr_mapping_save(PDO $pdo, array $data, ?int $id = null): array
{
    grooflow_mgr_pnl_ensure_schema($pdo);
    $cuenta = preg_replace('/\D+/', '', (string) ($data['cuenta_codigo'] ?? '')) ?: trim((string) ($data['cuenta_codigo'] ?? ''));
    if ($cuenta === '') {
        throw new InvalidArgumentException('cuenta_codigo obligatorio');
    }
    $nombre = trim((string) ($data['cuenta_nombre'] ?? ''));
    $nat = trim((string) ($data['naturaleza_codigo'] ?? '')) ?: null;
    $pnl = trim((string) ($data['pnl_codigo'] ?? '')) ?: null;
    $areaId = isset($data['area_id']) && $data['area_id'] !== '' && $data['area_id'] !== null ? (int) $data['area_id'] : null;
    $subId = isset($data['subarea_id']) && $data['subarea_id'] !== '' && $data['subarea_id'] !== null ? (int) $data['subarea_id'] : null;
    $ccId = isset($data['centro_costo_id']) && $data['centro_costo_id'] !== '' && $data['centro_costo_id'] !== null ? (int) $data['centro_costo_id'] : null;
    $driverId = isset($data['driver_id']) && $data['driver_id'] !== '' && $data['driver_id'] !== null ? (int) $data['driver_id'] : null;
    $tipo = (string) ($data['tipo_costo'] ?? 'NA');
    if (!in_array($tipo, ['DIRECTO', 'INDIRECTO', 'NA'], true)) {
        $tipo = 'NA';
    }
    $estado = (string) ($data['estado'] ?? 'activo');
    if (!in_array($estado, ['activo', 'inactivo', 'borrador'], true)) {
        $estado = 'activo';
    }
    $prio = (int) ($data['prioridad'] ?? 100);
    $kw = trim((string) ($data['keyword_match'] ?? '')) ?: null;
    $vd = trim((string) ($data['vigencia_desde'] ?? '')) ?: null;
    $vh = trim((string) ($data['vigencia_hasta'] ?? '')) ?: null;
    $notas = trim((string) ($data['notas'] ?? '')) ?: null;

    if ($id) {
        $before = $pdo->prepare('SELECT * FROM grooflow_mgr_cuenta_mapping WHERE id=? AND is_deleted=0');
        $before->execute([$id]);
        $old = $before->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare('
            UPDATE grooflow_mgr_cuenta_mapping SET
                cuenta_codigo=?, cuenta_nombre=?, naturaleza_codigo=?, pnl_codigo=?,
                area_id=?, subarea_id=?, centro_costo_id=?, tipo_costo=?, driver_id=?,
                keyword_match=?, prioridad=?, vigencia_desde=?, vigencia_hasta=?, estado=?, notas=?
            WHERE id=?
        ')->execute([
            $cuenta, $nombre ?: null, $nat, $pnl, $areaId, $subId, $ccId, $tipo, $driverId,
            $kw, $prio, $vd, $vh, $estado, $notas, $id,
        ]);
        if ($old) {
            grooflow_mgr_reclass_log($pdo, 'cuenta_mapping', (string) $id, 'snapshot', json_encode($old, JSON_UNESCAPED_UNICODE), json_encode($data, JSON_UNESCAPED_UNICODE), $data['created_by'] ?? null, $data['motivo'] ?? 'Actualización mapping');
        }
    } else {
        $pdo->prepare('
            INSERT INTO grooflow_mgr_cuenta_mapping
                (cuenta_codigo, cuenta_nombre, naturaleza_codigo, pnl_codigo, area_id, subarea_id,
                 centro_costo_id, tipo_costo, driver_id, keyword_match, prioridad, vigencia_desde, vigencia_hasta, estado, notas, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ')->execute([
            $cuenta, $nombre ?: null, $nat, $pnl, $areaId, $subId, $ccId, $tipo, $driverId,
            $kw, $prio, $vd, $vh, $estado, $notas, trim((string) ($data['created_by'] ?? '')) ?: null,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    $rows = array_values(array_filter(grooflow_mgr_mappings_list($pdo, false), fn ($r) => (int) $r['id'] === $id));

    return $rows[0] ?? ['id' => $id];
}

function grooflow_mgr_mapping_delete(PDO $pdo, int $id): void
{
    $pdo->prepare("UPDATE grooflow_mgr_cuenta_mapping SET is_deleted=1, estado='inactivo' WHERE id=?")->execute([$id]);
}

function grooflow_mgr_reclass_log(
    PDO $pdo,
    string $tipo,
    string $entidadId,
    string $campo,
    ?string $antes,
    ?string $despues,
    mixed $by,
    ?string $motivo
): void {
    grooflow_mgr_pnl_ensure_schema($pdo);
    $pdo->prepare('
        INSERT INTO grooflow_mgr_reclass_hist (entidad_tipo, entidad_id, campo, valor_antes, valor_despues, motivo, created_by)
        VALUES (?,?,?,?,?,?,?)
    ')->execute([$tipo, $entidadId, $campo, $antes, $despues, $motivo, $by !== null ? (string) $by : null]);
}

/**
 * Propone clasificación gerencial a partir de cuenta + texto.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function grooflow_mgr_classify_propose(PDO $pdo, array $input): array
{
    grooflow_mgr_pnl_ensure_schema($pdo);
    $cuenta = preg_replace('/\D+/', '', (string) ($input['cuenta_codigo'] ?? '')) ?: trim((string) ($input['cuenta_codigo'] ?? ''));
    $texto = mb_strtolower(trim((string) ($input['texto'] ?? $input['concepto'] ?? $input['cuenta_nombre'] ?? '')));
    $fecha = trim((string) ($input['fecha'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        $fecha = date('Y-m-d');
    }

    $proposal = [
        'cuenta_codigo' => $cuenta,
        'naturaleza_codigo' => null,
        'pnl_codigo' => null,
        'area_id' => null,
        'area_codigo' => null,
        'subarea_id' => null,
        'centro_costo_id' => null,
        'centro_codigo' => null,
        'tipo_costo' => 'NA',
        'driver_id' => null,
        'fuente' => null,
        'confianza' => 'baja',
        'es_remuneracion' => false,
        'requiere_driver' => false,
        'notas' => [],
    ];

    // Remuneraciones 62*
    if ($cuenta !== '' && str_starts_with($cuenta, '62')) {
        $proposal['naturaleza_codigo'] = 'N02';
        $proposal['es_remuneracion'] = true;
        $proposal['fuente'] = 'cuenta_62';
        $proposal['confianza'] = 'alta';
        $proposal['notas'][] = 'Cuenta 62: naturaleza PERSONAL. Área/CC desde colaborador/cargo.';
        $colab = trim((string) ($input['colaborador_id'] ?? ''));
        if ($colab !== '') {
            $resolved = grooflow_ccc_resolve($pdo, $colab, $fecha);
            if (!empty($resolved['lines'])) {
                $principal = $resolved['lines'][0];
                foreach ($resolved['lines'] as $ln) {
                    if (!empty($ln['es_principal'])) {
                        $principal = $ln;
                        break;
                    }
                }
                $proposal['centro_costo_id'] = (int) $principal['centro_costo_id'];
                $proposal['centro_codigo'] = $principal['centro_codigo'] ?? null;
                $proposal['notas'][] = 'CC propuesto desde asignación vigente del colaborador.';
                // Infer P&L from CC code
                $ccCode = (string) ($principal['centro_codigo'] ?? '');
                $proposal = array_merge($proposal, grooflow_mgr_infer_from_cc_code($pdo, $ccCode));
                $proposal['confianza'] = 'alta';
            }
        }
    }

    // Mapping exacto por cuenta
    if ($cuenta !== '') {
        $st = $pdo->prepare("
            SELECT m.*, a.codigo AS area_codigo, cc.codigo AS centro_codigo
            FROM grooflow_mgr_cuenta_mapping m
            LEFT JOIN grooflow_org_areas a ON a.id = m.area_id
            LEFT JOIN grooflow_centros_costo cc ON cc.id = m.centro_costo_id
            WHERE m.is_deleted=0 AND m.estado='activo' AND m.cuenta_codigo=?
              AND (m.vigencia_desde IS NULL OR m.vigencia_desde <= ?)
              AND (m.vigencia_hasta IS NULL OR m.vigencia_hasta >= ?)
            ORDER BY m.prioridad ASC, m.id DESC
            LIMIT 1
        ");
        $st->execute([$cuenta, $fecha, $fecha]);
        $map = $st->fetch(PDO::FETCH_ASSOC);
        if ($map) {
            $proposal['naturaleza_codigo'] = $map['naturaleza_codigo'] ?: $proposal['naturaleza_codigo'];
            $proposal['pnl_codigo'] = $map['pnl_codigo'] ?: $proposal['pnl_codigo'];
            $proposal['area_id'] = $map['area_id'] !== null ? (int) $map['area_id'] : $proposal['area_id'];
            $proposal['area_codigo'] = $map['area_codigo'] ?: $proposal['area_codigo'];
            $proposal['subarea_id'] = $map['subarea_id'] !== null ? (int) $map['subarea_id'] : $proposal['subarea_id'];
            $proposal['centro_costo_id'] = $map['centro_costo_id'] !== null ? (int) $map['centro_costo_id'] : $proposal['centro_costo_id'];
            $proposal['centro_codigo'] = $map['centro_codigo'] ?: $proposal['centro_codigo'];
            $proposal['tipo_costo'] = $map['tipo_costo'] ?: $proposal['tipo_costo'];
            $proposal['driver_id'] = $map['driver_id'] !== null ? (int) $map['driver_id'] : $proposal['driver_id'];
            $proposal['fuente'] = 'mapping_cuenta';
            $proposal['confianza'] = 'alta';
        }
    }

    // Keyword rules
    if ($texto !== '' || $cuenta !== '') {
        $hay = $texto . ' ' . mb_strtolower($cuenta);
        $kws = $pdo->query("
            SELECT * FROM grooflow_mgr_keyword_rules
            WHERE is_deleted=0 AND estado='activo'
            ORDER BY prioridad ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($kws as $kw) {
            $needle = mb_strtolower((string) $kw['keyword']);
            if ($needle === '' || !str_contains($hay, $needle)) {
                continue;
            }
            if ($proposal['fuente'] === 'mapping_cuenta' && (int) $kw['prioridad'] > 20) {
                // mapping exacto gana salvo keywords muy prioritarias
                continue;
            }
            if (!$proposal['naturaleza_codigo'] && $kw['naturaleza_codigo']) {
                $proposal['naturaleza_codigo'] = $kw['naturaleza_codigo'];
            }
            if (!$proposal['pnl_codigo'] && $kw['pnl_codigo']) {
                $proposal['pnl_codigo'] = $kw['pnl_codigo'];
            }
            if (!$proposal['tipo_costo'] || $proposal['tipo_costo'] === 'NA') {
                $proposal['tipo_costo'] = $kw['tipo_costo'];
            }
            if (!$proposal['area_codigo'] && $kw['area_codigo']) {
                $proposal['area_codigo'] = $kw['area_codigo'];
                $aid = $pdo->prepare('SELECT id FROM grooflow_org_areas WHERE codigo=? AND is_deleted=0 LIMIT 1');
                $aid->execute([$kw['area_codigo']]);
                $proposal['area_id'] = ($v = $aid->fetchColumn()) ? (int) $v : $proposal['area_id'];
            }
            if (!$proposal['centro_codigo'] && $kw['centro_codigo']) {
                $proposal['centro_codigo'] = $kw['centro_codigo'];
                $cid = $pdo->prepare('SELECT id FROM grooflow_centros_costo WHERE codigo=? AND is_deleted=0 LIMIT 1');
                $cid->execute([$kw['centro_codigo']]);
                $proposal['centro_costo_id'] = ($v = $cid->fetchColumn()) ? (int) $v : $proposal['centro_costo_id'];
            }
            if (!$proposal['fuente']) {
                $proposal['fuente'] = 'keyword:' . $kw['keyword'];
                $proposal['confianza'] = 'media';
            }
            $proposal['notas'][] = 'Regla keyword: ' . $kw['keyword'];
            break;
        }
    }

    if ($proposal['tipo_costo'] === 'INDIRECTO' && empty($proposal['driver_id'])) {
        $proposal['requiere_driver'] = true;
        $proposal['notas'][] = 'Indirecto: configurar driver o regla de distribución compartida.';
    }

    return $proposal;
}

/**
 * @return array<string,mixed>
 */
function grooflow_mgr_infer_from_cc_code(PDO $pdo, string $ccCode): array
{
    $map = [
        'CC-MEDICINA' => ['pnl_codigo' => '02.01', 'area_codigo' => 'MED', 'tipo_costo' => 'DIRECTO'],
        'CC-PELUQUERIA' => ['pnl_codigo' => '02.02', 'area_codigo' => 'PEL', 'tipo_costo' => 'DIRECTO'],
        'CC-PETSHOP' => ['pnl_codigo' => '02.03', 'area_codigo' => 'PET', 'tipo_costo' => 'DIRECTO'],
        'CC-PET-MOVIL' => ['pnl_codigo' => '02.04', 'area_codigo' => 'MOV', 'tipo_costo' => 'DIRECTO'],
        'CC-ATENCION-CLIENTE' => ['pnl_codigo' => '04.02', 'area_codigo' => 'ATC', 'tipo_costo' => 'INDIRECTO'],
        'CC-MARKETING' => ['pnl_codigo' => '04.01', 'area_codigo' => 'MKT', 'tipo_costo' => 'INDIRECTO'],
        'CC-GERENCIA' => ['pnl_codigo' => '05.01', 'area_codigo' => 'GER', 'tipo_costo' => 'INDIRECTO'],
        'CC-GERENCIA-MED' => ['pnl_codigo' => '05.01', 'area_codigo' => 'GER', 'tipo_costo' => 'INDIRECTO'],
        'CC-GERENCIA-OPS' => ['pnl_codigo' => '05.01', 'area_codigo' => 'GER', 'tipo_costo' => 'INDIRECTO'],
        'CC-AUDITORIA' => ['pnl_codigo' => '05.02', 'area_codigo' => 'AUD', 'tipo_costo' => 'INDIRECTO'],
        'CC-CONTABILIDAD' => ['pnl_codigo' => '05.03', 'area_codigo' => 'CON', 'tipo_costo' => 'INDIRECTO'],
        'CC-FINANZAS' => ['pnl_codigo' => '07', 'area_codigo' => 'FIN', 'tipo_costo' => 'INDIRECTO'],
        'CC-RRHH' => ['pnl_codigo' => '05.04', 'area_codigo' => 'RRHH', 'tipo_costo' => 'INDIRECTO'],
        'CC-COMPRAS' => ['pnl_codigo' => '05.05', 'area_codigo' => 'COM', 'tipo_costo' => 'INDIRECTO'],
        'CC-TECNOLOGIA' => ['pnl_codigo' => '05.06', 'area_codigo' => 'TEC', 'tipo_costo' => 'INDIRECTO'],
        'CC-MANTENIMIENTO' => ['pnl_codigo' => '05.07', 'area_codigo' => 'MAN', 'tipo_costo' => 'INDIRECTO'],
        'CC-LIMPIEZA' => ['pnl_codigo' => '05.07', 'area_codigo' => 'LIM', 'tipo_costo' => 'INDIRECTO'],
        'CC-SERVICIOS-SEDE' => ['pnl_codigo' => '05.07', 'area_codigo' => 'SSE', 'tipo_costo' => 'INDIRECTO'],
        'CC-LOGISTICA' => ['pnl_codigo' => '04.04', 'area_codigo' => 'LOG', 'tipo_costo' => 'INDIRECTO'],
    ];
    $out = $map[$ccCode] ?? [];
    if (!empty($out['area_codigo'])) {
        $st = $pdo->prepare('SELECT id FROM grooflow_org_areas WHERE codigo=? AND is_deleted=0 LIMIT 1');
        $st->execute([$out['area_codigo']]);
        $out['area_id'] = ($v = $st->fetchColumn()) ? (int) $v : null;
    }

    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function grooflow_mgr_shared_list(PDO $pdo): array
{
    grooflow_mgr_pnl_ensure_schema($pdo);
    $rows = $pdo->query("
        SELECT s.*,
               ao.nombre AS area_origen_nombre, ao.codigo AS area_origen_codigo,
               co.codigo AS centro_origen_codigo, co.nombre AS centro_origen_nombre,
               d.codigo AS driver_codigo, d.nombre AS driver_nombre
        FROM grooflow_mgr_shared_dist s
        LEFT JOIN grooflow_org_areas ao ON ao.id = s.area_origen_id
        LEFT JOIN grooflow_centros_costo co ON co.id = s.centro_origen_id
        LEFT JOIN grooflow_mgr_drivers d ON d.id = s.driver_id
        WHERE s.is_deleted=0
        ORDER BY s.codigo
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $st = $pdo->prepare('
            SELECT d.*, a.nombre AS area_destino_nombre, a.codigo AS area_destino_codigo,
                   c.codigo AS centro_destino_codigo, c.nombre AS centro_destino_nombre
            FROM grooflow_mgr_shared_dist_detalle d
            LEFT JOIN grooflow_org_areas a ON a.id = d.area_destino_id
            LEFT JOIN grooflow_centros_costo c ON c.id = d.centro_destino_id
            WHERE d.shared_id=? AND d.is_deleted=0
            ORDER BY d.sort_order, d.id
        ');
        $st->execute([(int) $r['id']]);
        $r['detalle'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out[] = $r;
    }

    return $out;
}

/**
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function grooflow_mgr_shared_save(PDO $pdo, array $data, ?int $id = null): array
{
    grooflow_mgr_pnl_ensure_schema($pdo);
    $codigo = strtoupper(trim((string) ($data['codigo'] ?? '')));
    $nombre = trim((string) ($data['nombre'] ?? ''));
    if ($codigo === '' || $nombre === '') {
        throw new InvalidArgumentException('codigo y nombre obligatorios');
    }
    $detalle = $data['detalle'] ?? [];
    if (!is_array($detalle) || $detalle === []) {
        throw new InvalidArgumentException('detalle de destinos obligatorio');
    }
    $sum = 0.0;
    $lines = [];
    foreach ($detalle as $i => $d) {
        if (!is_array($d)) {
            continue;
        }
        $ccId = (int) ($d['centro_destino_id'] ?? 0);
        $pct = round((float) ($d['porcentaje'] ?? 0), 2);
        if ($ccId <= 0 || $pct <= 0) {
            throw new InvalidArgumentException('Cada destino requiere centro y % > 0');
        }
        $sum += $pct;
        $lines[] = [
            'centro_destino_id' => $ccId,
            'area_destino_id' => isset($d['area_destino_id']) && $d['area_destino_id'] !== '' ? (int) $d['area_destino_id'] : null,
            'porcentaje' => $pct,
            'sort_order' => (int) ($d['sort_order'] ?? $i),
        ];
    }
    if (abs($sum - 100.0) > 0.02) {
        throw new InvalidArgumentException('Los porcentajes de distribución compartida deben sumar 100%');
    }
    $areaOrigen = isset($data['area_origen_id']) && $data['area_origen_id'] !== '' ? (int) $data['area_origen_id'] : null;
    $ccOrigen = isset($data['centro_origen_id']) && $data['centro_origen_id'] !== '' ? (int) $data['centro_origen_id'] : null;
    $driverId = isset($data['driver_id']) && $data['driver_id'] !== '' ? (int) $data['driver_id'] : null;
    $vd = trim((string) ($data['vigencia_desde'] ?? '')) ?: null;
    $vh = trim((string) ($data['vigencia_hasta'] ?? '')) ?: null;

    $pdo->beginTransaction();
    try {
        if ($id) {
            $pdo->prepare('
                UPDATE grooflow_mgr_shared_dist
                SET codigo=?, nombre=?, area_origen_id=?, centro_origen_id=?, driver_id=?, vigencia_desde=?, vigencia_hasta=?, estado=?
                WHERE id=?
            ')->execute([$codigo, $nombre, $areaOrigen, $ccOrigen, $driverId, $vd, $vh, 'activo', $id]);
            $pdo->prepare('UPDATE grooflow_mgr_shared_dist_detalle SET is_deleted=1 WHERE shared_id=?')->execute([$id]);
        } else {
            $pdo->prepare('
                INSERT INTO grooflow_mgr_shared_dist
                    (codigo, nombre, area_origen_id, centro_origen_id, driver_id, vigencia_desde, vigencia_hasta, estado)
                VALUES (?,?,?,?,?,?,?,\'activo\')
            ')->execute([$codigo, $nombre, $areaOrigen, $ccOrigen, $driverId, $vd, $vh]);
            $id = (int) $pdo->lastInsertId();
        }
        $ins = $pdo->prepare('
            INSERT INTO grooflow_mgr_shared_dist_detalle (shared_id, area_destino_id, centro_destino_id, porcentaje, sort_order)
            VALUES (?,?,?,?,?)
        ');
        foreach ($lines as $ln) {
            $ins->execute([$id, $ln['area_destino_id'], $ln['centro_destino_id'], $ln['porcentaje'], $ln['sort_order']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $all = grooflow_mgr_shared_list($pdo);
    foreach ($all as $row) {
        if ((int) $row['id'] === $id) {
            return $row;
        }
    }

    return ['id' => $id];
}

function grooflow_mgr_shared_delete(PDO $pdo, int $id): void
{
    $pdo->prepare("UPDATE grooflow_mgr_shared_dist SET is_deleted=1, estado='inactivo' WHERE id=?")->execute([$id]);
}

/**
 * Control de calidad / pendientes de parametrización.
 *
 * @param list<array<string,mixed>> $chartAccounts  Plan de cuentas desde frontend (KV)
 * @return array<string,mixed>
 */
function grooflow_mgr_qa_report(PDO $pdo, array $chartAccounts = []): array
{
    grooflow_mgr_pnl_ensure_schema($pdo);
    $mappings = grooflow_mgr_mappings_list($pdo, true);
    $mappedCodes = [];
    foreach ($mappings as $m) {
        $mappedCodes[(string) $m['cuenta_codigo']] = true;
    }

    $sinClasificacion = [];
    foreach ($chartAccounts as $acc) {
        if (!is_array($acc)) {
            continue;
        }
        if (isset($acc['active']) && !$acc['active']) {
            continue;
        }
        $code = preg_replace('/\D+/', '', (string) ($acc['code'] ?? '')) ?: (string) ($acc['code'] ?? '');
        if ($code === '') {
            continue;
        }
        // Solo hojas tipicas de gasto/ingreso (nivel alto o sin hijos) — reportar todas activas sin mapping
        if (!isset($mappedCodes[$code])) {
            $sinClasificacion[] = [
                'cuenta_codigo' => $code,
                'cuenta_nombre' => $acc['name'] ?? '',
                'plFuncionGroo' => $acc['plFuncionGroo'] ?? null,
            ];
        }
    }

    $dupCc = $pdo->query("
        SELECT codigo, COUNT(*) AS n FROM grooflow_centros_costo
        WHERE is_deleted=0 GROUP BY codigo HAVING n > 1
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $cuentas62SinArea = [];
    foreach ($mappings as $m) {
        $c = (string) $m['cuenta_codigo'];
        if (str_starts_with($c, '62') && empty($m['area_id']) && empty($m['centro_costo_id'])) {
            $cuentas62SinArea[] = $m;
        }
    }

    $sharedSinDriver = $pdo->query("
        SELECT id, codigo, nombre FROM grooflow_mgr_shared_dist
        WHERE is_deleted=0 AND estado='activo' AND driver_id IS NULL
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $indirectosSinDriver = [];
    foreach ($mappings as $m) {
        if (($m['tipo_costo'] ?? '') === 'INDIRECTO' && empty($m['driver_id'])) {
            $indirectosSinDriver[] = [
                'id' => $m['id'],
                'cuenta_codigo' => $m['cuenta_codigo'],
                'cuenta_nombre' => $m['cuenta_nombre'],
            ];
        }
    }

    $matrix = [];
    foreach ($mappings as $m) {
        $matrix[] = [
            'cuenta_codigo' => $m['cuenta_codigo'],
            'cuenta_nombre' => $m['cuenta_nombre'],
            'naturaleza' => $m['naturaleza_codigo'],
            'pnl' => $m['pnl_codigo'],
            'area' => $m['area_nombre'] ?? null,
            'subarea' => $m['subarea_nombre'] ?? null,
            'centro_costo' => $m['centro_codigo'] ?? null,
            'tipo_costo' => $m['tipo_costo'],
            'driver' => $m['driver_codigo'] ?? null,
            'vigencia_desde' => $m['vigencia_desde'],
            'vigencia_hasta' => $m['vigencia_hasta'],
            'estado' => $m['estado'],
            'parametrizacion' => (
                $m['naturaleza_codigo'] && $m['pnl_codigo'] && ($m['centro_costo_id'] || str_starts_with((string) $m['cuenta_codigo'], '62'))
            ) ? 'completo' : 'incompleto',
        ];
    }

    return [
        'cuentas_sin_clasificacion' => array_slice($sinClasificacion, 0, 500),
        'cuentas_sin_clasificacion_total' => count($sinClasificacion),
        'centros_duplicados' => $dupCc,
        'cuentas_62_sin_area_cc' => $cuentas62SinArea,
        'compartidos_sin_driver' => $sharedSinDriver,
        'indirectos_sin_driver' => $indirectosSinDriver,
        'matriz' => $matrix,
        'resumen' => [
            'mappings_activos' => count($mappings),
            'pendientes_cuenta' => count($sinClasificacion),
            'pendientes_62' => count($cuentas62SinArea),
            'pendientes_driver' => count($sharedSinDriver) + count($indirectosSinDriver),
        ],
    ];
}

/**
 * P&L gerencial agregado desde gastos CC distribuidos + mapping.
 *
 * @return array<string,mixed>
 */
function grooflow_mgr_pnl_statement(PDO $pdo, string $periodo): array
{
    grooflow_mgr_pnl_ensure_schema($pdo);
    if (!preg_match('/^\d{4}-\d{2}$/', $periodo)) {
        throw new InvalidArgumentException('periodo inválido (YYYY-MM)');
    }

    $lineas = grooflow_mgr_pnl_lineas_list($pdo);
    $byPnl = [];
    foreach ($lineas as $l) {
        $byPnl[(string) $l['codigo']] = [
            'codigo' => $l['codigo'],
            'nombre' => $l['nombre'],
            'parent_codigo' => $l['parent_codigo'],
            'nivel' => (int) $l['nivel'],
            'es_calculo' => (int) $l['es_calculo'] === 1,
            'formula' => $l['formula'],
            'monto' => 0.0,
        ];
    }

    // Gastos distribuidos → priorizar mapping por cuenta; fallback inferencia por CC
    $st = $pdo->prepare("
        SELECT
            g.cuenta_codigo,
            cc.codigo AS centro_codigo,
            ROUND(SUM(d.monto),2) AS total
        FROM grooflow_gasto_distribucion d
        JOIN grooflow_gastos_cc g ON g.id = d.gasto_id AND g.is_deleted=0 AND g.estado='distribuido'
        JOIN grooflow_centros_costo cc ON cc.id = d.centro_costo_id
        WHERE d.is_reversed=0 AND g.periodo=?
        GROUP BY g.cuenta_codigo, cc.codigo
    ");
    $st->execute([$periodo]);

    $mapByCuenta = [];
    foreach (grooflow_mgr_mappings_list($pdo, true) as $m) {
        $code = (string) ($m['cuenta_codigo'] ?? '');
        if ($code === '') {
            continue;
        }
        if (!isset($mapByCuenta[$code]) || (int) ($m['prioridad'] ?? 100) < (int) ($mapByCuenta[$code]['prioridad'] ?? 100)) {
            $mapByCuenta[$code] = $m;
        }
    }

    $detail = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $cuenta = preg_replace('/\D+/', '', (string) ($row['cuenta_codigo'] ?? '')) ?: (string) ($row['cuenta_codigo'] ?? '');
        $cc = (string) $row['centro_codigo'];
        $amt = (float) $row['total'];
        $pnlCode = null;
        $tipo = 'NA';
        $fuente = 'cc_infer';

        if ($cuenta !== '' && isset($mapByCuenta[$cuenta]) && !empty($mapByCuenta[$cuenta]['pnl_codigo'])) {
            $pnlCode = (string) $mapByCuenta[$cuenta]['pnl_codigo'];
            $tipo = (string) ($mapByCuenta[$cuenta]['tipo_costo'] ?? 'NA');
            $fuente = 'mapping_cuenta';
        } else {
            $inf = grooflow_mgr_infer_from_cc_code($pdo, $cc);
            $pnlCode = $inf['pnl_codigo'] ?? '05.07';
            $tipo = $inf['tipo_costo'] ?? 'NA';
        }

        if (isset($byPnl[$pnlCode])) {
            $byPnl[$pnlCode]['monto'] += $amt;
        }
        $detail[] = [
            'cuenta_codigo' => $cuenta !== '' ? $cuenta : null,
            'centro_codigo' => $cc,
            'pnl_codigo' => $pnlCode,
            'monto' => $amt,
            'tipo_costo' => $tipo,
            'fuente' => $fuente,
        ];
    }

    // Roll-up a padres no cálculo
    foreach (array_reverse($byPnl) as $code => $row) {
        if ($row['es_calculo']) {
            continue;
        }
        $parent = $row['parent_codigo'];
        if ($parent && isset($byPnl[$parent]) && !$byPnl[$parent]['es_calculo']) {
            $byPnl[$parent]['monto'] += $row['monto'];
        }
    }

    // Cálculos
    $get = static function (array $by, string $c): float {
        return isset($by[$c]) ? (float) $by[$c]['monto'] : 0.0;
    };
    if (isset($byPnl['03'])) {
        $byPnl['03']['monto'] = $get($byPnl, '01') - $get($byPnl, '02');
    }
    if (isset($byPnl['08'])) {
        $byPnl['08']['monto'] = $get($byPnl, '03') - $get($byPnl, '04') - $get($byPnl, '05')
            + $get($byPnl, '06') + $get($byPnl, '07');
    }

    foreach ($byPnl as &$r) {
        $r['monto'] = round((float) $r['monto'], 2);
    }
    unset($r);

    return [
        'periodo' => $periodo,
        'lineas' => array_values($byPnl),
        'detalle_centros' => $detail,
        'nota' => 'P&L gerencial: prioriza mapping por cuenta contable; si falta, infiere por centro de costo. Ventas (01) se alimentarán con reconocimiento de ingresos.',
    ];
}

/**
 * Propone (y opcionalmente aplica) mappings desde el plan Starsoft.
 * Usa classify + metadatos plFuncionGroo / centroCosto. No sobrescribe mappings activos salvo overwrite.
 *
 * @param list<array<string,mixed>> $accounts
 * @param array<string,mixed> $opts apply?, overwrite?, min_confianza? (alta|media|baja), created_by?
 * @return array<string,mixed>
 */
function grooflow_mgr_auto_map_from_chart(PDO $pdo, array $accounts, array $opts = []): array
{
    grooflow_mgr_pnl_ensure_schema($pdo);
    $apply = !empty($opts['apply']);
    $overwrite = !empty($opts['overwrite']);
    $minConf = (string) ($opts['min_confianza'] ?? 'media');
    $rank = ['baja' => 1, 'media' => 2, 'alta' => 3];
    $minRank = $rank[$minConf] ?? 2;
    $createdBy = trim((string) ($opts['created_by'] ?? '')) ?: null;

    $existing = [];
    foreach (grooflow_mgr_mappings_list($pdo, false) as $m) {
        $existing[(string) $m['cuenta_codigo']] = $m;
    }

    $pnlCodes = [];
    foreach (grooflow_mgr_pnl_lineas_list($pdo) as $ln) {
        $pnlCodes[(string) $ln['codigo']] = true;
    }

    $ccByCode = [];
    $ccByName = [];
    $ccRows = $pdo->query("SELECT id, codigo, nombre FROM grooflow_centros_costo WHERE is_deleted=0")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($ccRows as $cc) {
        $ccByCode[mb_strtoupper((string) $cc['codigo'])] = $cc;
        $ccByName[mb_strtolower(trim((string) $cc['nombre']))] = $cc;
    }

    $proposals = [];
    $applied = 0;
    $skipped = 0;
    $reviewed = 0;

    foreach ($accounts as $acc) {
        if (!is_array($acc)) {
            continue;
        }
        if (isset($acc['active']) && !$acc['active']) {
            continue;
        }
        $code = preg_replace('/\D+/', '', (string) ($acc['code'] ?? '')) ?: trim((string) ($acc['code'] ?? ''));
        if ($code === '') {
            continue;
        }
        // Priorizar clases de gasto/ingreso/costo (6*, 7*, 9*); omitir activos/pasivos típicos 1–5 sin hint
        $first = substr($code, 0, 1);
        $plHint = trim((string) ($acc['plFuncionGroo'] ?? $acc['plplFuncionGoo'] ?? ''));
        $ccHint = trim((string) ($acc['centroCosto'] ?? ''));
        $name = trim((string) ($acc['name'] ?? ''));
        if (!in_array($first, ['6', '7', '9'], true) && $plHint === '' && $ccHint === '') {
            continue;
        }
        $reviewed++;

        $hasExisting = isset($existing[$code]) && (string) ($existing[$code]['estado'] ?? '') === 'activo' && (int) ($existing[$code]['is_deleted'] ?? 0) === 0;
        if ($hasExisting && !$overwrite) {
            $skipped++;
            continue;
        }

        $texto = trim($name . ' ' . $plHint . ' ' . $ccHint);
        $proposal = grooflow_mgr_classify_propose($pdo, [
            'cuenta_codigo' => $code,
            'cuenta_nombre' => $name,
            'texto' => $texto,
        ]);

        // Enriquecer con metadatos Starsoft
        if ($plHint !== '') {
            $plNorm = preg_replace('/\s+/', '', $plHint);
            if (isset($pnlCodes[$plNorm])) {
                $proposal['pnl_codigo'] = $plNorm;
                if (($proposal['confianza'] ?? 'baja') === 'baja') {
                    $proposal['confianza'] = 'media';
                }
                $proposal['notas'][] = 'P&L desde PL FUNCION GROO del plan.';
            }
        }
        if ($ccHint !== '') {
            $ccKey = mb_strtoupper($ccHint);
            $ccRow = $ccByCode[$ccKey] ?? null;
            if (!$ccRow) {
                $ccRow = $ccByName[mb_strtolower($ccHint)] ?? null;
            }
            if ($ccRow) {
                $proposal['centro_costo_id'] = (int) $ccRow['id'];
                $proposal['centro_codigo'] = $ccRow['codigo'];
                $inf = grooflow_mgr_infer_from_cc_code($pdo, (string) $ccRow['codigo']);
                if (empty($proposal['pnl_codigo']) && !empty($inf['pnl_codigo'])) {
                    $proposal['pnl_codigo'] = $inf['pnl_codigo'];
                }
                if (empty($proposal['area_id']) && !empty($inf['area_id'])) {
                    $proposal['area_id'] = $inf['area_id'];
                    $proposal['area_codigo'] = $inf['area_codigo'] ?? null;
                }
                if (($proposal['tipo_costo'] ?? 'NA') === 'NA' && !empty($inf['tipo_costo'])) {
                    $proposal['tipo_costo'] = $inf['tipo_costo'];
                }
                if (($proposal['confianza'] ?? 'baja') !== 'alta') {
                    $proposal['confianza'] = 'alta';
                }
                $proposal['fuente'] = ($proposal['fuente'] ?? 'starsoft_cc');
                $proposal['notas'][] = 'Centro desde metadato Starsoft CENTRO DE COSTO.';
            }
        }

        // Prefijos de clase contable si aún no hay naturaleza
        if (empty($proposal['naturaleza_codigo'])) {
            if (str_starts_with($code, '70') || str_starts_with($code, '75')) {
                $proposal['naturaleza_codigo'] = 'N01';
                $proposal['pnl_codigo'] = $proposal['pnl_codigo'] ?: '01';
                $proposal['confianza'] = $proposal['confianza'] === 'baja' ? 'media' : $proposal['confianza'];
            } elseif (str_starts_with($code, '62')) {
                $proposal['naturaleza_codigo'] = 'N02';
                $proposal['confianza'] = 'alta';
            } elseif (str_starts_with($code, '63') || str_starts_with($code, '65') || str_starts_with($code, '68')) {
                $proposal['naturaleza_codigo'] = $proposal['naturaleza_codigo'] ?: 'N11';
            }
        }

        $confRank = $rank[(string) ($proposal['confianza'] ?? 'baja')] ?? 1;
        $usable = $confRank >= $minRank && (!empty($proposal['naturaleza_codigo']) || !empty($proposal['pnl_codigo']) || !empty($proposal['centro_costo_id']));
        $item = [
            'cuenta_codigo' => $code,
            'cuenta_nombre' => $name,
            'proposal' => $proposal,
            'usable' => $usable,
            'applied' => false,
            'skipped_existing' => false,
        ];

        if ($usable && $apply) {
            $payload = [
                'cuenta_codigo' => $code,
                'cuenta_nombre' => $name,
                'naturaleza_codigo' => $proposal['naturaleza_codigo'] ?? null,
                'pnl_codigo' => $proposal['pnl_codigo'] ?? null,
                'area_id' => $proposal['area_id'] ?? null,
                'subarea_id' => $proposal['subarea_id'] ?? null,
                'centro_costo_id' => $proposal['centro_costo_id'] ?? null,
                'tipo_costo' => $proposal['tipo_costo'] ?? 'NA',
                'driver_id' => $proposal['driver_id'] ?? null,
                'estado' => 'activo',
                'prioridad' => 50,
                'notas' => 'Auto-map desde plan Starsoft (' . ($proposal['fuente'] ?? 'classify') . ')',
                'created_by' => $createdBy,
                'motivo' => 'auto_map_from_chart',
            ];
            $id = null;
            if ($hasExisting && $overwrite) {
                $id = (int) $existing[$code]['id'];
            }
            $saved = grooflow_mgr_mapping_save($pdo, $payload, $id);
            $existing[$code] = $saved;
            $item['applied'] = true;
            $item['mapping_id'] = $saved['id'] ?? null;
            $applied++;
        }

        $proposals[] = $item;
    }

    return [
        'reviewed' => $reviewed,
        'proposed' => count($proposals),
        'usable' => count(array_filter($proposals, static fn ($p) => !empty($p['usable']))),
        'applied' => $applied,
        'skipped_existing' => $skipped,
        'items' => $proposals,
        'apply' => $apply,
    ];
}

/**
 * Ingesta un gasto operativo (caja, factura, honorarios, etc.) hacia gastos_cc + classify.
 * Idempotente por origen_tipo + origen_id.
 *
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function grooflow_mgr_ingest_expense(PDO $pdo, array $data): array
{
    grooflow_mgr_pnl_ensure_schema($pdo);
    require_once __DIR__ . '/grooflow_cost_centers_ops.php';

    $fecha = trim((string) ($data['fecha'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        // Aceptar ISO datetime
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $fecha, $m)) {
            $fecha = $m[1];
        } else {
            $fecha = date('Y-m-d');
        }
    }
    $monto = round((float) ($data['monto'] ?? 0), 2);
    if ($monto <= 0) {
        throw new InvalidArgumentException('monto debe ser > 0');
    }
    $concepto = trim((string) ($data['concepto'] ?? $data['description'] ?? ''));
    if ($concepto === '') {
        $concepto = 'Gasto operativo';
    }
    $cuenta = preg_replace('/\D+/', '', (string) ($data['cuenta_codigo'] ?? $data['accountingAccount'] ?? '')) ?: '';
    $origenTipo = (string) ($data['origen_tipo'] ?? 'manual');
    $allowedOrigen = ['manual', 'caja', 'transaccion', 'factura', 'personal'];
    if (!in_array($origenTipo, $allowedOrigen, true)) {
        $origenTipo = 'manual';
    }
    $origenId = trim((string) ($data['origen_id'] ?? '')) ?: null;
    $sede = trim((string) ($data['sede_nombre'] ?? $data['location'] ?? '')) ?: null;
    $colab = trim((string) ($data['colaborador_id'] ?? '')) ?: null;
    $autoDist = array_key_exists('auto_distribute', $data) ? !empty($data['auto_distribute']) : true;
    $createdBy = trim((string) ($data['created_by'] ?? '')) ?: null;

    if ($origenId !== null) {
        $dup = $pdo->prepare('SELECT id FROM grooflow_gastos_cc WHERE origen_tipo=? AND origen_id=? AND is_deleted=0 LIMIT 1');
        $dup->execute([$origenTipo, $origenId]);
        $dupId = $dup->fetchColumn();
        if ($dupId !== false) {
            $existing = grooflow_gastos_cc_get($pdo, (int) $dupId);
            return [
                'ok' => true,
                'duplicated' => true,
                'gasto' => $existing,
                'proposal' => null,
                'distributed' => $existing && (string) ($existing['estado'] ?? '') === 'distribuido',
            ];
        }
    }

    $proposal = grooflow_mgr_classify_propose($pdo, [
        'cuenta_codigo' => $cuenta,
        'texto' => $concepto,
        'cuenta_nombre' => $concepto,
        'fecha' => $fecha,
        'colaborador_id' => $colab ?? '',
    ]);

    $tipo = 'SIN_ASIGNAR';
    $ccOrigen = null;
    $reglaId = null;
    if ($colab) {
        $tipo = 'PERSONAL';
    } elseif (!empty($proposal['centro_costo_id'])) {
        $tipo = 'DIRECTO';
        $ccOrigen = (int) $proposal['centro_costo_id'];
    } elseif (!empty($data['centro_costo_origen_id'])) {
        $tipo = 'DIRECTO';
        $ccOrigen = (int) $data['centro_costo_origen_id'];
    } elseif (!empty($data['regla_id'])) {
        $tipo = 'REGLA';
        $reglaId = (int) $data['regla_id'];
    }

    $gasto = grooflow_gastos_cc_save($pdo, [
        'fecha' => $fecha,
        'monto' => $monto,
        'concepto' => $concepto,
        'cuenta_codigo' => $cuenta !== '' ? $cuenta : null,
        'sede_nombre' => $sede,
        'origen_tipo' => $origenTipo,
        'origen_id' => $origenId,
        'tipo_asignacion' => $tipo,
        'centro_costo_origen_id' => $ccOrigen,
        'regla_id' => $reglaId,
        'colaborador_id' => $colab,
        'notas' => trim((string) ($data['notas'] ?? '')) ?: ('Ingest ' . $origenTipo . ' · conf=' . ($proposal['confianza'] ?? '')),
        'created_by' => $createdBy,
        'moneda' => trim((string) ($data['moneda'] ?? 'PEN')) ?: 'PEN',
    ], null);

    $distributed = false;
    $distError = null;
    if ($autoDist && $tipo !== 'SIN_ASIGNAR') {
        try {
            $gasto = grooflow_gasto_distribute($pdo, (int) $gasto['id'], $createdBy);
            $distributed = true;
        } catch (Throwable $e) {
            $distError = $e->getMessage();
        }
    }

    return [
        'ok' => true,
        'duplicated' => false,
        'gasto' => $gasto,
        'proposal' => $proposal,
        'distributed' => $distributed,
        'dist_error' => $distError,
    ];
}

/** @return array<string,mixed> */
function grooflow_mgr_dashboard(PDO $pdo): array
{
    grooflow_mgr_pnl_ensure_schema($pdo);
    $nat = (int) $pdo->query("SELECT COUNT(*) FROM grooflow_mgr_naturalezas WHERE is_deleted=0 AND estado='activo'")->fetchColumn();
    $pnl = (int) $pdo->query("SELECT COUNT(*) FROM grooflow_mgr_pnl_lineas WHERE is_deleted=0")->fetchColumn();
    $map = (int) $pdo->query("SELECT COUNT(*) FROM grooflow_mgr_cuenta_mapping WHERE is_deleted=0 AND estado='activo'")->fetchColumn();
    $drv = (int) $pdo->query("SELECT COUNT(*) FROM grooflow_mgr_drivers WHERE is_deleted=0 AND estado='activo'")->fetchColumn();
    $shr = (int) $pdo->query("SELECT COUNT(*) FROM grooflow_mgr_shared_dist WHERE is_deleted=0 AND estado='activo'")->fetchColumn();
    $kw = (int) $pdo->query("SELECT COUNT(*) FROM grooflow_mgr_keyword_rules WHERE is_deleted=0 AND estado='activo'")->fetchColumn();

    return [
        'naturalezas' => $nat,
        'pnl_lineas' => $pnl,
        'mappings' => $map,
        'drivers' => $drv,
        'compartidos' => $shr,
        'keyword_rules' => $kw,
        'nota' => 'Contabilidad gerencial lista: mapping, clasificación automática, P&L 01–08 y QA.',
    ];
}
