<?php

declare(strict_types=1);

/**
 * Extornos: solicitud por sede → auditoría (firma) → tesorería (pago) → KPI / planilla.
 * Plantilla de campos editable por admin del sistema.
 */

const GROOFLOW_EXTORNOS_MODULE = 'Extornos';
const GROOFLOW_EXTORNOS_ESTADOS = [
    'borrador',
    'enviado',
    'observado',
    'aprobado',
    'rechazado',
    'pagado',
    'anulado',
];
const GROOFLOW_EXTORNOS_PHOTO_MAX = 3_500_000;
const GROOFLOW_EXTORNOS_METODOS = ['transferencia', 'visa', 'efectivo', 'otro'];

function grooflow_extornos_default_template(): array
{
    return [
        'version' => 1,
        'title' => 'SOLICITUD DE EXTORNO',
        'fields' => [
            ['key' => 'sede', 'label' => 'Sede', 'type' => 'sede', 'section' => 'admin', 'required' => true, 'enabled' => true, 'orden' => 10],
            ['key' => 'fechaSolicitud', 'label' => 'Fecha de solicitud', 'type' => 'date', 'section' => 'admin', 'required' => true, 'enabled' => true, 'orden' => 20],
            ['key' => 'clienteNombre', 'label' => 'Nombre y apellido del cliente', 'type' => 'text', 'section' => 'admin', 'required' => true, 'enabled' => true, 'orden' => 30],
            ['key' => 'clienteDocumento', 'label' => 'DNI / C.E / Pasaporte', 'type' => 'text', 'section' => 'admin', 'required' => true, 'enabled' => true, 'orden' => 40],
            ['key' => 'clienteCorreo', 'label' => 'Correo', 'type' => 'email', 'section' => 'admin', 'required' => false, 'enabled' => true, 'orden' => 50],
            ['key' => 'mascotaNombre', 'label' => 'Mascota', 'type' => 'text', 'section' => 'admin', 'required' => true, 'enabled' => true, 'orden' => 60],
            ['key' => 'parentesco', 'label' => 'Parentesco con dueño de la mascota', 'type' => 'text', 'section' => 'admin', 'required' => false, 'enabled' => true, 'orden' => 70],
            ['key' => 'boleta', 'label' => 'Boleta', 'type' => 'text', 'section' => 'admin', 'required' => true, 'enabled' => true, 'orden' => 80],
            ['key' => 'monto', 'label' => 'Monto', 'type' => 'money', 'section' => 'admin', 'required' => true, 'enabled' => true, 'orden' => 90],
            ['key' => 'motivo', 'label' => 'Motivo', 'type' => 'textarea', 'section' => 'admin', 'required' => true, 'enabled' => true, 'orden' => 100],
            ['key' => 'cuentaBancaria', 'label' => 'Cuenta bancaria', 'type' => 'text', 'section' => 'admin', 'required' => false, 'enabled' => true, 'orden' => 110],
            ['key' => 'entidadBancaria', 'label' => 'Entidad bancaria', 'type' => 'text', 'section' => 'admin', 'required' => false, 'enabled' => true, 'orden' => 120],
            ['key' => 'metodoPagoSolicitado', 'label' => 'Método de pago solicitado', 'type' => 'select', 'section' => 'admin', 'required' => false, 'enabled' => true, 'orden' => 130, 'options' => GROOFLOW_EXTORNOS_METODOS],
            ['key' => 'adminSolicitaNombre', 'label' => 'Administrador que solicita', 'type' => 'text', 'section' => 'admin', 'required' => true, 'enabled' => true, 'orden' => 140],
            ['key' => 'fechaRecepcion', 'label' => 'Fecha de recepción', 'type' => 'date', 'section' => 'auditoria', 'required' => false, 'enabled' => true, 'orden' => 210],
            ['key' => 'validacionNota', 'label' => 'Validación de extorno', 'type' => 'textarea', 'section' => 'auditoria', 'required' => false, 'enabled' => true, 'orden' => 220],
            ['key' => 'auditorNombre', 'label' => 'Nombre del auditor', 'type' => 'text', 'section' => 'auditoria', 'required' => false, 'enabled' => true, 'orden' => 230],
            ['key' => 'fechaProceso', 'label' => 'Fecha de proceso', 'type' => 'date', 'section' => 'finanzas', 'required' => false, 'enabled' => true, 'orden' => 310],
            ['key' => 'metodoPagoReal', 'label' => 'Método de pago', 'type' => 'select', 'section' => 'finanzas', 'required' => false, 'enabled' => true, 'orden' => 320, 'options' => GROOFLOW_EXTORNOS_METODOS],
            ['key' => 'autorizadoPor', 'label' => 'Autorizado por', 'type' => 'text', 'section' => 'finanzas', 'required' => false, 'enabled' => true, 'orden' => 330],
            ['key' => 'referenciaPago', 'label' => 'Referencia / nro. operación', 'type' => 'text', 'section' => 'finanzas', 'required' => false, 'enabled' => true, 'orden' => 340],
        ],
    ];
}

function grooflow_extornos_ensure_schema(PDO $pdo): void
{
    static $done = false;
    // No abortar si hay transacción: CREATE IF NOT EXISTS es seguro; el seed usa INSERT IGNORE.
    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_extornos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            codigo VARCHAR(40) NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'borrador',
            plantilla_version INT UNSIGNED NOT NULL DEFAULT 1,
            sede VARCHAR(120) NOT NULL,
            fecha_solicitud DATE NOT NULL,
            cliente_nombre VARCHAR(190) NOT NULL,
            cliente_documento VARCHAR(40) NOT NULL,
            cliente_correo VARCHAR(190) NULL,
            mascota_nombre VARCHAR(190) NOT NULL,
            parentesco VARCHAR(120) NULL,
            boleta VARCHAR(80) NOT NULL,
            monto DECIMAL(12,2) NOT NULL,
            motivo TEXT NOT NULL,
            cuenta_bancaria VARCHAR(80) NULL,
            entidad_bancaria VARCHAR(120) NULL,
            metodo_pago_solicitado VARCHAR(30) NULL,
            admin_solicita_nombre VARCHAR(190) NOT NULL,
            fecha_recepcion DATE NULL,
            validacion_nota TEXT NULL,
            auditor_nombre VARCHAR(190) NULL,
            fecha_proceso DATE NULL,
            metodo_pago_real VARCHAR(30) NULL,
            autorizado_por VARCHAR(190) NULL,
            referencia_pago VARCHAR(120) NULL,
            nota_observacion VARCHAR(800) NULL,
            nota_rechazo VARCHAR(800) NULL,
            firma_auditor_mime VARCHAR(80) NULL,
            firma_auditor_at DATETIME NULL,
            firma_auditor_by INT UNSIGNED NULL,
            firma_auditor_by_nombre VARCHAR(190) NULL,
            firma_hash CHAR(64) NULL,
            revisado_by INT UNSIGNED NULL,
            revisado_by_nombre VARCHAR(190) NULL,
            revisado_at DATETIME NULL,
            pagado_by INT UNSIGNED NULL,
            pagado_by_nombre VARCHAR(190) NULL,
            pagado_at DATETIME NULL,
            created_by INT UNSIGNED NULL,
            created_by_nombre VARCHAR(190) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_gf_ext_codigo (codigo),
            KEY idx_gf_ext_estado (estado),
            KEY idx_gf_ext_sede (sede),
            KEY idx_gf_ext_fecha (fecha_solicitud),
            KEY idx_gf_ext_boleta (boleta),
            KEY idx_gf_ext_doc (cliente_documento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_extornos_adjuntos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            extorno_id BIGINT UNSIGNED NOT NULL,
            tipo VARCHAR(40) NOT NULL,
            nombre VARCHAR(190) NOT NULL,
            mime VARCHAR(80) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            data LONGBLOB NOT NULL,
            hash CHAR(64) NOT NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_gf_ext_adj_ext (extorno_id, tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_extornos_historial (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            extorno_id BIGINT UNSIGNED NOT NULL,
            from_estado VARCHAR(20) NULL,
            to_estado VARCHAR(20) NOT NULL,
            accion VARCHAR(40) NOT NULL,
            nota VARCHAR(800) NULL,
            user_id INT UNSIGNED NULL,
            user_nombre VARCHAR(190) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_gf_ext_hist (extorno_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_extornos_firmas_usuario (
            user_id INT UNSIGNED NOT NULL,
            mime VARCHAR(80) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            data LONGBLOB NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_extornos_config (
            id TINYINT UNSIGNED NOT NULL DEFAULT 1,
            plantilla_json LONGTEXT NOT NULL,
            updated_by INT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_extornos_plantillas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(120) NOT NULL,
            titulo VARCHAR(190) NOT NULL,
            descripcion VARCHAR(500) NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            es_default TINYINT(1) NOT NULL DEFAULT 0,
            plantilla_json LONGTEXT NOT NULL,
            created_by INT UNSIGNED NULL,
            updated_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_gf_ext_tpl_def (es_default, activo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_extornos_firmas_blob (
            extorno_id BIGINT UNSIGNED NOT NULL,
            mime VARCHAR(80) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            data LONGBLOB NOT NULL,
            PRIMARY KEY (extorno_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    try {
        if (!$pdo->query("SHOW COLUMNS FROM grooflow_extornos LIKE 'plantilla_id'")->fetch()) {
            $pdo->exec('ALTER TABLE grooflow_extornos ADD COLUMN plantilla_id BIGINT UNSIGNED NULL AFTER plantilla_version');
        }
    } catch (Throwable $e) {
        // columna ya existe / carrera entre requests
    }
    try {
        if (!$pdo->query("SHOW COLUMNS FROM grooflow_extornos LIKE 'campos_extra'")->fetch()) {
            $pdo->exec('ALTER TABLE grooflow_extornos ADD COLUMN campos_extra LONGTEXT NULL AFTER motivo');
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        if (!$pdo->query("SHOW COLUMNS FROM grooflow_extornos LIKE 'firma_auditor_cargo'")->fetch()) {
            $pdo->exec('ALTER TABLE grooflow_extornos ADD COLUMN firma_auditor_cargo VARCHAR(190) NULL AFTER firma_auditor_by_nombre');
        }
    } catch (Throwable $e) {
        // ignore
    }
    foreach ([
        'firma_modo' => 'VARCHAR(16) NULL',
        'firma_e_documento' => 'VARCHAR(40) NULL',
        'firma_e_email' => 'VARCHAR(160) NULL',
        'firma_e_ip' => 'VARCHAR(64) NULL',
        'firma_e_token' => 'CHAR(32) NULL',
    ] as $col => $def) {
        try {
            if (!$pdo->query("SHOW COLUMNS FROM grooflow_extornos LIKE " . $pdo->quote($col))->fetch()) {
                $pdo->exec("ALTER TABLE grooflow_extornos ADD COLUMN {$col} {$def}");
            }
        } catch (Throwable $e) {
            // columna ya existe
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_firmas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            nombre VARCHAR(190) NOT NULL,
            cargo VARCHAR(190) NULL,
            mime VARCHAR(80) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            data LONGBLOB NOT NULL,
            es_predeterminada TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_gf_firmas_user (user_id, es_predeterminada)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Evita SQLSTATE 23000 por carrera: varios requests paralelos (list+stats+template) al primer uso.
    try {
        $pdo->prepare('INSERT IGNORE INTO grooflow_extornos_config (id, plantilla_json) VALUES (1, ?)')
            ->execute([grooflow_json_encode(grooflow_extornos_default_template())]);
    } catch (Throwable $e) {
        try {
            $pdo->prepare('INSERT IGNORE INTO grooflow_extornos_config (id, plantilla_json) VALUES (1, ?)')
                ->execute([grooflow_json_encode(grooflow_extornos_default_template())]);
        } catch (Throwable $e2) {
            // ignore
        }
    }

    try {
        grooflow_extornos_migrate_plantillas($pdo);
    } catch (Throwable $e) {
        // no bloquear el módulo si la migración de plantillas falla
    }

    $done = true;
}

function grooflow_extornos_migrate_plantillas(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM grooflow_extornos_plantillas')->fetchColumn();
    if ($count > 0) {
        return;
    }
    $raw = '';
    try {
        $raw = (string) ($pdo->query('SELECT plantilla_json FROM grooflow_extornos_config WHERE id = 1')->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $raw = '';
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || empty($decoded['fields'])) {
        $decoded = grooflow_extornos_default_template();
    }
    $titulo = (string) ($decoded['title'] ?? 'SOLICITUD DE EXTORNO');
    $pdo->prepare('
        INSERT INTO grooflow_extornos_plantillas
            (nombre, titulo, descripcion, activo, es_default, plantilla_json)
        VALUES (?, ?, ?, 1, 1, ?)
    ')->execute([
        'Formulario estándar',
        $titulo,
        'Plantilla inicial migrada desde la configuración clásica.',
        grooflow_json_encode($decoded),
    ]);
}

/** @return list<string> */
function grooflow_extornos_builtin_keys(): array
{
    $keys = [];
    foreach (grooflow_extornos_default_template()['fields'] as $f) {
        $keys[] = (string) $f['key'];
    }

    return $keys;
}

function grooflow_extornos_normalize_fields(array $fieldsIn): array
{
    $defaults = grooflow_extornos_default_template();
    $byKey = [];
    foreach ($defaults['fields'] as $f) {
        $byKey[$f['key']] = $f;
    }
    $out = [];
    $seen = [];
    foreach ($fieldsIn as $i => $f) {
        if (!is_array($f)) {
            continue;
        }
        $key = trim((string) ($f['key'] ?? ''));
        $isCustom = !empty($f['custom']) || ($key !== '' && !isset($byKey[$key]));
        if ($isCustom) {
            if ($key === '' || isset($byKey[$key]) || isset($seen[$key])) {
                $key = 'extra_' . substr(sha1((string) ($f['label'] ?? '') . microtime(true) . $i), 0, 10);
            }
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,40}$/', $key)) {
                $key = 'extra_' . substr(sha1($key . $i), 0, 10);
            }
            $type = (string) ($f['type'] ?? 'text');
            if (!in_array($type, ['text', 'email', 'date', 'money', 'textarea', 'select', 'sede'], true)) {
                $type = 'text';
            }
            $section = (string) ($f['section'] ?? 'admin');
            if (!in_array($section, ['admin', 'auditoria', 'finanzas'], true)) {
                $section = 'admin';
            }
            $opts = null;
            if ($type === 'select') {
                $rawOpts = $f['options'] ?? [];
                if (is_string($rawOpts)) {
                    $rawOpts = array_values(array_filter(array_map('trim', explode(',', $rawOpts))));
                }
                if (is_array($rawOpts)) {
                    $opts = [];
                    foreach ($rawOpts as $o) {
                        $o = grooflow_extornos_str($o, 80);
                        if ($o !== null) {
                            $opts[] = $o;
                        }
                    }
                    if ($opts === []) {
                        $opts = ['Opción 1', 'Opción 2'];
                    }
                }
            }
            $out[] = [
                'key' => $key,
                'label' => grooflow_extornos_str($f['label'] ?? 'Campo extra', 120) ?? 'Campo extra',
                'type' => $type,
                'section' => $section,
                'required' => !empty($f['required']),
                'enabled' => array_key_exists('enabled', $f) ? !empty($f['enabled']) : true,
                'orden' => isset($f['orden']) ? (int) $f['orden'] : (($i + 1) * 10),
                'options' => $opts,
                'custom' => true,
                'placeholder' => grooflow_extornos_str($f['placeholder'] ?? null, 120),
            ];
            $seen[$key] = true;
            continue;
        }
        if ($key === '' || !isset($byKey[$key]) || isset($seen[$key])) {
            continue;
        }
        $base = $byKey[$key];
        $out[] = [
            'key' => $key,
            'label' => grooflow_extornos_str($f['label'] ?? $base['label'], 120) ?? $base['label'],
            'type' => $base['type'],
            'section' => $base['section'],
            'required' => !empty($f['required']),
            'enabled' => array_key_exists('enabled', $f) ? !empty($f['enabled']) : true,
            'orden' => isset($f['orden']) ? (int) $f['orden'] : (($i + 1) * 10),
            'options' => $base['options'] ?? null,
            'custom' => false,
            'placeholder' => grooflow_extornos_str($f['placeholder'] ?? null, 120),
        ];
        $seen[$key] = true;
    }
    if ($out === []) {
        throw new GrooflowValidation(['fields' => 'La plantilla debe tener al menos un campo.']);
    }
    usort($out, static fn ($a, $b) => ($a['orden'] <=> $b['orden']));

    return $out;
}

function grooflow_extornos_plantilla_row_to_app(array $row): array
{
    $json = json_decode((string) ($row['plantilla_json'] ?? ''), true);
    if (!is_array($json)) {
        $json = grooflow_extornos_default_template();
    }
    $fields = is_array($json['fields'] ?? null) ? $json['fields'] : [];
    // Plantillas antiguas pueden no traer correo: se agrega sin borrar campos ya guardados.
    $present = [];
    foreach ($fields as $f) {
        if (is_array($f) && isset($f['key'])) {
            $present[(string) $f['key']] = true;
        }
    }
    foreach (grooflow_extornos_default_template()['fields'] as $base) {
        if (empty($present[$base['key']])) {
            $fields[] = $base;
        }
    }

    return [
        'id' => (string) $row['id'],
        'nombre' => (string) $row['nombre'],
        'titulo' => (string) ($row['titulo'] ?: ($json['title'] ?? 'SOLICITUD DE EXTORNO')),
        'descripcion' => $row['descripcion'],
        'activo' => !empty($row['activo']),
        'esDefault' => !empty($row['es_default']),
        'version' => (int) ($json['version'] ?? 1),
        'fields' => $fields,
        'camposActivos' => count(array_filter($fields, static fn ($f) => !empty($f['enabled']))),
        'updatedAt' => (string) ($row['updated_at'] ?? ''),
        'createdAt' => (string) ($row['created_at'] ?? ''),
    ];
}

function grooflow_extornos_plantillas_list(PDO $pdo): array
{
    grooflow_extornos_ensure_schema($pdo);
    grooflow_extornos_require($pdo, 'ver');
    $stmt = $pdo->query('SELECT * FROM grooflow_extornos_plantillas ORDER BY es_default DESC, activo DESC, nombre ASC, id ASC');
    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $items[] = grooflow_extornos_plantilla_row_to_app($row);
    }

    return $items;
}

function grooflow_extornos_plantilla_get(PDO $pdo, int $id): array
{
    grooflow_extornos_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM grooflow_extornos_plantillas WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Plantilla no encontrada');
    }

    return grooflow_extornos_plantilla_row_to_app($row);
}

function grooflow_extornos_plantilla_default(PDO $pdo): array
{
    grooflow_extornos_ensure_schema($pdo);
    $stmt = $pdo->query('SELECT * FROM grooflow_extornos_plantillas WHERE es_default = 1 AND activo = 1 ORDER BY id ASC LIMIT 1');
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!is_array($row)) {
        $stmt = $pdo->query('SELECT * FROM grooflow_extornos_plantillas WHERE activo = 1 ORDER BY id ASC LIMIT 1');
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    }
    if (!is_array($row)) {
        $def = grooflow_extornos_default_template();

        return [
            'id' => '0',
            'nombre' => 'Formulario estándar',
            'titulo' => (string) $def['title'],
            'descripcion' => null,
            'activo' => true,
            'esDefault' => true,
            'version' => (int) ($def['version'] ?? 1),
            'fields' => $def['fields'],
            'camposActivos' => count($def['fields']),
            'updatedAt' => '',
            'createdAt' => '',
        ];
    }

    return grooflow_extornos_plantilla_row_to_app($row);
}

function grooflow_extornos_plantilla_create(PDO $pdo, array $in): array
{
    grooflow_extornos_ensure_schema($pdo);
    grooflow_extornos_require($pdo, 'configurar');
    [, $userId] = grooflow_extornos_user();
    $nombre = grooflow_extornos_str($in['nombre'] ?? null, 120) ?? 'Nueva plantilla';
    $titulo = grooflow_extornos_str($in['titulo'] ?? null, 190) ?? 'SOLICITUD DE EXTORNO';
    $descripcion = grooflow_extornos_str($in['descripcion'] ?? null, 500);
    $fromId = (int) ($in['fromId'] ?? 0);
    if ($fromId > 0) {
        $src = grooflow_extornos_plantilla_get($pdo, $fromId);
        $fields = $src['fields'];
        if (($in['titulo'] ?? null) === null || trim((string) $in['titulo']) === '') {
            $titulo = (string) $src['titulo'];
        }
    } elseif (isset($in['fields']) && is_array($in['fields']) && $in['fields'] !== []) {
        $fields = grooflow_extornos_normalize_fields($in['fields']);
    } else {
        $fields = grooflow_extornos_default_template()['fields'];
    }
    $makeDefault = !empty($in['esDefault']);
    $tplJson = [
        'version' => 1,
        'title' => $titulo,
        'fields' => $fields,
    ];
    $id = grooflow_atomic($pdo, static function () use ($pdo, $nombre, $titulo, $descripcion, $tplJson, $makeDefault, $userId): int {
        if ($makeDefault) {
            $pdo->exec('UPDATE grooflow_extornos_plantillas SET es_default = 0');
        }
        $hasAny = (int) $pdo->query('SELECT COUNT(*) FROM grooflow_extornos_plantillas')->fetchColumn();
        $isDef = $makeDefault || $hasAny === 0 ? 1 : 0;
        $pdo->prepare('
            INSERT INTO grooflow_extornos_plantillas
                (nombre, titulo, descripcion, activo, es_default, plantilla_json, created_by, updated_by)
            VALUES (?, ?, ?, 1, ?, ?, ?, ?)
        ')->execute([
            $nombre, $titulo, $descripcion, $isDef, grooflow_json_encode($tplJson), $userId ?: null, $userId ?: null,
        ]);

        return (int) $pdo->lastInsertId();
    });

    return grooflow_extornos_plantilla_get($pdo, $id);
}

function grooflow_extornos_plantilla_update(PDO $pdo, int $id, array $in): array
{
    grooflow_extornos_ensure_schema($pdo);
    grooflow_extornos_require($pdo, 'configurar');
    [, $userId] = grooflow_extornos_user();
    grooflow_atomic($pdo, static function () use ($pdo, $id, $in, $userId): void {
        $stmt = $pdo->prepare('SELECT * FROM grooflow_extornos_plantillas WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Plantilla no encontrada');
        }
        $nombre = grooflow_extornos_str($in['nombre'] ?? $row['nombre'], 120) ?? (string) $row['nombre'];
        $titulo = grooflow_extornos_str($in['titulo'] ?? $row['titulo'], 190) ?? (string) $row['titulo'];
        $descripcion = array_key_exists('descripcion', $in)
            ? grooflow_extornos_str($in['descripcion'], 500)
            : $row['descripcion'];
        $activo = array_key_exists('activo', $in) ? (!empty($in['activo']) ? 1 : 0) : (int) $row['activo'];
        $json = json_decode((string) $row['plantilla_json'], true);
        if (!is_array($json)) {
            $json = grooflow_extornos_default_template();
        }
        if (isset($in['fields']) && is_array($in['fields'])) {
            $json['fields'] = grooflow_extornos_normalize_fields($in['fields']);
            $json['version'] = ((int) ($json['version'] ?? 1)) + 1;
        }
        $json['title'] = $titulo;
        if (!empty($in['esDefault'])) {
            $pdo->exec('UPDATE grooflow_extornos_plantillas SET es_default = 0');
            $esDefault = 1;
            $activo = 1;
        } else {
            $esDefault = (int) $row['es_default'];
            if ($activo === 0 && $esDefault === 1) {
                throw new GrooflowValidation(['activo' => 'No puedes desactivar la plantilla predeterminada. Elige otra como predeterminada primero.']);
            }
        }
        $pdo->prepare('
            UPDATE grooflow_extornos_plantillas
            SET nombre=?, titulo=?, descripcion=?, activo=?, es_default=?, plantilla_json=?, updated_by=?
            WHERE id=?
        ')->execute([
            $nombre, $titulo, $descripcion, $activo, $esDefault, grooflow_json_encode($json), $userId ?: null, $id,
        ]);
    });

    return grooflow_extornos_plantilla_get($pdo, $id);
}

function grooflow_extornos_plantilla_delete(PDO $pdo, int $id): void
{
    grooflow_extornos_ensure_schema($pdo);
    grooflow_extornos_require($pdo, 'configurar');
    grooflow_atomic($pdo, static function () use ($pdo, $id): void {
        $stmt = $pdo->prepare('SELECT * FROM grooflow_extornos_plantillas WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Plantilla no encontrada');
        }
        $total = (int) $pdo->query('SELECT COUNT(*) FROM grooflow_extornos_plantillas')->fetchColumn();
        if ($total <= 1) {
            throw new GrooflowValidation(['id' => 'Debe existir al menos una plantilla.']);
        }
        if (!empty($row['es_default'])) {
            throw new GrooflowValidation(['id' => 'No puedes eliminar la plantilla predeterminada. Marca otra primero.']);
        }
        $pdo->prepare('DELETE FROM grooflow_extornos_plantillas WHERE id = ?')->execute([$id]);
    });
}

function grooflow_extornos_can(PDO $pdo, string $action): bool
{
    $ctx = grooflow_access_context($pdo);
    if (!empty($ctx['admin'])) {
        return true;
    }

    return !empty($ctx['actions'][GROOFLOW_EXTORNOS_MODULE][$action]);
}

function grooflow_extornos_require(PDO $pdo, string $action): void
{
    if (!grooflow_extornos_can($pdo, $action)) {
        throw new RuntimeException('No tienes permiso para esta acción de Extornos');
    }
}

function grooflow_extornos_can_review(PDO $pdo): bool
{
    // Auditoría / admin sistema: acción configurar en Extornos.
    return grooflow_extornos_can($pdo, 'configurar');
}

function grooflow_extornos_can_pay(PDO $pdo): bool
{
    if (grooflow_extornos_can($pdo, 'editar') || grooflow_extornos_can($pdo, 'configurar')) {
        return true;
    }
    $ctx = grooflow_access_context($pdo);

    return !empty($ctx['actions']['Tesorería']['ver']);
}

function grooflow_extornos_str(mixed $v, int $max): ?string
{
    $s = trim((string) ($v ?? ''));

    return $s !== '' ? mb_substr($s, 0, $max) : null;
}

function grooflow_extornos_date(?string $raw, string $field, bool $required = false): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        if ($required) {
            throw new GrooflowValidation([$field => 'Fecha requerida.']);
        }

        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    if (!$dt || $dt->format('Y-m-d') !== $raw) {
        throw new GrooflowValidation([$field => 'Fecha inválida.']);
    }

    return $raw;
}

function grooflow_extornos_user(): array
{
    $user = api_current_user() ?? [];
    $userId = (int) ($user['id'] ?? 0);
    $userName = trim(((string) ($user['nombre'] ?? '')) . ' ' . ((string) ($user['apellido'] ?? '')));
    if ($userName === '') {
        $userName = (string) ($user['usuario'] ?? $user['email'] ?? ('Usuario ' . $userId));
    }

    return [$user, $userId, $userName];
}

function grooflow_extornos_next_codigo(PDO $pdo): string
{
    $year = (new DateTimeImmutable('now'))->format('Y');
    $prefix = 'EXT-' . $year . '-';
    // MAX numérico evita choques si el último id no es el mayor correlativo.
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING(codigo, ?) AS UNSIGNED)), 0)
        FROM grooflow_extornos
        WHERE codigo LIKE ?
    ");
    $stmt->execute([strlen($prefix) + 1, $prefix . '%']);
    $n = ((int) $stmt->fetchColumn()) + 1;

    return $prefix . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
}

/** @return array{mime: string, bytes: string, hash: string, nombre: string} */
function grooflow_extornos_decode_file(mixed $dataUrl, string $nombre, bool $required, string $field = 'file'): ?array
{
    $dataUrl = is_string($dataUrl) ? trim($dataUrl) : '';
    if ($dataUrl === '') {
        if ($required) {
            throw new GrooflowValidation([$field => 'Adjunta el archivo requerido.']);
        }

        return null;
    }
    if (!preg_match('#^data:(image/jpeg|image/png|image/webp|application/pdf);base64,(.+)$#s', $dataUrl, $m)) {
        throw new GrooflowValidation([$field => 'Formato no admitido (JPG, PNG, WEBP o PDF).']);
    }
    $bytes = base64_decode($m[2], true);
    if ($bytes === false || $bytes === '') {
        throw new GrooflowValidation([$field => 'No se pudo leer el archivo.']);
    }
    if (strlen($bytes) > GROOFLOW_EXTORNOS_PHOTO_MAX) {
        throw new GrooflowValidation([$field => 'El archivo supera 3.5 MB.']);
    }
    $nombre = grooflow_extornos_str($nombre, 180) ?? 'adjunto';

    return ['mime' => $m[1], 'bytes' => $bytes, 'hash' => hash('sha256', $bytes), 'nombre' => $nombre];
}

function grooflow_extornos_template(PDO $pdo, ?int $plantillaId = null): array
{
    grooflow_extornos_ensure_schema($pdo);
    if ($plantillaId && $plantillaId > 0) {
        $p = grooflow_extornos_plantilla_get($pdo, $plantillaId);
    } else {
        $p = grooflow_extornos_plantilla_default($pdo);
    }

    return [
        'id' => $p['id'],
        'nombre' => $p['nombre'],
        'version' => $p['version'],
        'title' => $p['titulo'],
        'fields' => $p['fields'],
        'esDefault' => $p['esDefault'],
        'descripcion' => $p['descripcion'],
    ];
}

/** Compat: actualizar la plantilla predeterminada (API antigua /extornos/template PUT). */
function grooflow_extornos_save_template(PDO $pdo, array $in): array
{
    $def = grooflow_extornos_plantilla_default($pdo);
    $id = (int) $def['id'];
    if ($id <= 0) {
        $created = grooflow_extornos_plantilla_create($pdo, [
            'nombre' => 'Formulario estándar',
            'titulo' => $in['title'] ?? 'SOLICITUD DE EXTORNO',
            'fields' => $in['fields'] ?? [],
            'esDefault' => true,
        ]);

        return grooflow_extornos_template($pdo, (int) $created['id']);
    }
    $updated = grooflow_extornos_plantilla_update($pdo, $id, [
        'titulo' => $in['title'] ?? $def['titulo'],
        'nombre' => $in['nombre'] ?? $def['nombre'],
        'fields' => $in['fields'] ?? $def['fields'],
        'esDefault' => true,
    ]);

    return grooflow_extornos_template($pdo, (int) $updated['id']);
}

function grooflow_extornos_find(PDO $pdo, int $id, bool $forUpdate = false): array
{
    $sql = 'SELECT * FROM grooflow_extornos WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Extorno no encontrado');
    }

    return $row;
}

function grooflow_extornos_adjuntos_meta(PDO $pdo, int $extornoId): array
{
    $stmt = $pdo->prepare('SELECT id, tipo, nombre, mime, size_bytes, created_at FROM grooflow_extornos_adjuntos WHERE extorno_id = ? ORDER BY id');
    $stmt->execute([$extornoId]);
    $items = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $items[] = [
            'id' => (string) $r['id'],
            'tipo' => (string) $r['tipo'],
            'nombre' => (string) $r['nombre'],
            'mime' => (string) $r['mime'],
            'sizeBytes' => (int) $r['size_bytes'],
            'createdAt' => (string) $r['created_at'],
        ];
    }

    return $items;
}

function grooflow_extornos_historial(PDO $pdo, int $extornoId): array
{
    $stmt = $pdo->prepare('SELECT * FROM grooflow_extornos_historial WHERE extorno_id = ? ORDER BY id ASC');
    $stmt->execute([$extornoId]);
    $items = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $items[] = [
            'id' => (string) $r['id'],
            'fromEstado' => $r['from_estado'],
            'toEstado' => (string) $r['to_estado'],
            'accion' => (string) $r['accion'],
            'nota' => $r['nota'],
            'userNombre' => $r['user_nombre'],
            'createdAt' => (string) $r['created_at'],
        ];
    }

    return $items;
}

function grooflow_extornos_row_to_app(PDO $pdo, array $row, bool $withDetail = false): array
{
    $id = (int) $row['id'];
    $item = [
        'id' => (string) $id,
        'codigo' => (string) ($row['codigo'] ?? ''),
        'estado' => (string) $row['estado'],
        'plantillaVersion' => (int) $row['plantilla_version'],
        'plantillaId' => isset($row['plantilla_id']) && $row['plantilla_id'] !== null ? (string) $row['plantilla_id'] : null,
        'camposExtra' => (static function () use ($row): array {
            $raw = $row['campos_extra'] ?? null;
            if (!is_string($raw) || $raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        })(),
        'sede' => (string) $row['sede'],
        'fechaSolicitud' => (string) $row['fecha_solicitud'],
        'clienteNombre' => (string) $row['cliente_nombre'],
        'clienteDocumento' => (string) $row['cliente_documento'],
        'clienteCorreo' => $row['cliente_correo'],
        'mascotaNombre' => (string) $row['mascota_nombre'],
        'parentesco' => $row['parentesco'],
        'boleta' => (string) $row['boleta'],
        'monto' => (float) $row['monto'],
        'motivo' => (string) $row['motivo'],
        'cuentaBancaria' => $row['cuenta_bancaria'],
        'entidadBancaria' => $row['entidad_bancaria'],
        'metodoPagoSolicitado' => $row['metodo_pago_solicitado'],
        'adminSolicitaNombre' => (string) $row['admin_solicita_nombre'],
        'fechaRecepcion' => $row['fecha_recepcion'],
        'validacionNota' => $row['validacion_nota'],
        'auditorNombre' => $row['auditor_nombre'],
        'fechaProceso' => $row['fecha_proceso'],
        'metodoPagoReal' => $row['metodo_pago_real'],
        'autorizadoPor' => $row['autorizado_por'],
        'referenciaPago' => $row['referencia_pago'],
        'notaObservacion' => $row['nota_observacion'],
        'notaRechazo' => $row['nota_rechazo'],
        'tieneFirmaAuditor' => !empty($row['firma_auditor_at']),
        'firmaAuditorAt' => $row['firma_auditor_at'],
        'firmaAuditorByNombre' => $row['firma_auditor_by_nombre'],
        'firmaAuditorCargo' => $row['firma_auditor_cargo'] ?? null,
        'firmaModo' => $row['firma_modo'] ?? null,
        'firmaElectronica' => !empty($row['firma_e_token']) ? [
            'nombre' => (string) ($row['firma_auditor_by_nombre'] ?? ''),
            'documento' => (string) ($row['firma_e_documento'] ?? ''),
            'email' => (string) ($row['firma_e_email'] ?? ''),
            'fecha' => (string) ($row['firma_auditor_at'] ?? ''),
            'ip' => (string) ($row['firma_e_ip'] ?? ''),
            'token' => (string) $row['firma_e_token'],
        ] : null,
        'revisadoByNombre' => $row['revisado_by_nombre'],
        'revisadoAt' => $row['revisado_at'],
        'pagadoByNombre' => $row['pagado_by_nombre'],
        'pagadoAt' => $row['pagado_at'],
        'createdByNombre' => $row['created_by_nombre'],
        'createdAt' => (string) $row['created_at'],
        'updatedAt' => (string) $row['updated_at'],
        'adjuntos' => grooflow_extornos_adjuntos_meta($pdo, $id),
    ];
    if ($withDetail) {
        $item['historial'] = grooflow_extornos_historial($pdo, $id);
        if (!empty($row['firma_auditor_at'])) {
            $fs = $pdo->prepare('SELECT mime, data FROM grooflow_extornos_firmas_blob WHERE extorno_id = ?');
            $fs->execute([$id]);
            $fr = $fs->fetch(PDO::FETCH_ASSOC);
            if (is_array($fr)) {
                $item['firmaAuditorDataUrl'] = 'data:' . $fr['mime'] . ';base64,' . base64_encode((string) $fr['data']);
            }
        }
    }

    return $item;
}

function grooflow_extornos_log(PDO $pdo, int $id, ?string $from, string $to, string $accion, ?string $nota, array $user, int $userId, string $userName): void
{
    $pdo->prepare('
        INSERT INTO grooflow_extornos_historial
            (extorno_id, from_estado, to_estado, accion, nota, user_id, user_nombre)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ')->execute([$id, $from, $to, $accion, $nota, $userId ?: null, mb_substr($userName, 0, 190)]);
    grooflow_audit_insert($pdo, $user, 'extornos.' . $accion, [
        'entity' => 'extorno',
        'entity_id' => (string) $id,
        'from' => $from,
        'to' => $to,
    ]);
}

function grooflow_extornos_parse_payload(array $in, array $template, bool $strictRequired): array
{
    $enabled = [];
    foreach ($template['fields'] as $f) {
        if (!empty($f['enabled'])) {
            $enabled[$f['key']] = $f;
        }
    }
    $get = static function (string $key) use ($in): mixed {
        return $in[$key] ?? null;
    };
    $req = static function (string $key, ?string $val) use ($enabled, $strictRequired): void {
        if (!$strictRequired) {
            return;
        }
        $f = $enabled[$key] ?? null;
        if ($f && !empty($f['required']) && ($val === null || $val === '')) {
            throw new GrooflowValidation([$key => ($f['label'] ?? $key) . ' es obligatorio.']);
        }
    };

    $sede = grooflow_extornos_str($get('sede'), 120);
    $req('sede', $sede);
    $fecha = grooflow_extornos_date(is_string($get('fechaSolicitud')) ? $get('fechaSolicitud') : null, 'fechaSolicitud', $strictRequired && !empty($enabled['fechaSolicitud']['required']));
    $cliente = grooflow_extornos_str($get('clienteNombre'), 190);
    $req('clienteNombre', $cliente);
    $doc = grooflow_extornos_str($get('clienteDocumento'), 40);
    $req('clienteDocumento', $doc);
    $correo = grooflow_extornos_str($get('clienteCorreo'), 190);
    $req('clienteCorreo', $correo);
    $mascota = grooflow_extornos_str($get('mascotaNombre'), 190);
    $req('mascotaNombre', $mascota);
    $parentesco = grooflow_extornos_str($get('parentesco'), 120);
    $boleta = grooflow_extornos_str($get('boleta'), 80);
    $req('boleta', $boleta);
    $motivo = grooflow_extornos_str($get('motivo'), 4000);
    $req('motivo', $motivo);
    $admin = grooflow_extornos_str($get('adminSolicitaNombre'), 190);
    $req('adminSolicitaNombre', $admin);

    $montoRaw = $get('monto');
    $monto = $montoRaw === null || $montoRaw === '' ? null : round((float) $montoRaw, 2);
    if ($strictRequired && !empty($enabled['monto']['required']) && ($monto === null || $monto <= 0)) {
        throw new GrooflowValidation(['monto' => 'El monto debe ser mayor a 0.']);
    }
    if ($monto !== null && $monto < 0) {
        throw new GrooflowValidation(['monto' => 'Monto inválido.']);
    }

    $metodoSol = grooflow_extornos_str($get('metodoPagoSolicitado'), 30);
    if ($metodoSol !== null && !in_array($metodoSol, GROOFLOW_EXTORNOS_METODOS, true)) {
        throw new GrooflowValidation(['metodoPagoSolicitado' => 'Método inválido.']);
    }
    if ($strictRequired && $metodoSol === 'transferencia') {
        if (!grooflow_extornos_str($get('cuentaBancaria'), 80)) {
            throw new GrooflowValidation(['cuentaBancaria' => 'Indica la cuenta bancaria.']);
        }
        if (!grooflow_extornos_str($get('entidadBancaria'), 120)) {
            throw new GrooflowValidation(['entidadBancaria' => 'Indica la entidad bancaria.']);
        }
    }

    $builtin = array_flip(grooflow_extornos_builtin_keys());
    $extrasIn = is_array($in['camposExtra'] ?? null) ? $in['camposExtra'] : [];
    $extras = [];
    foreach ($enabled as $key => $f) {
        if (empty($f['custom']) && isset($builtin[$key])) {
            continue;
        }
        $val = $extrasIn[$key] ?? $get($key);
        if (is_array($val) || is_object($val)) {
            $val = grooflow_json_encode($val);
        }
        $valStr = grooflow_extornos_str($val, 2000);
        $req($key, $valStr);
        if ($valStr !== null) {
            $extras[$key] = $valStr;
        }
    }

    return [
        'sede' => $sede ?? '',
        'fecha_solicitud' => $fecha ?? date('Y-m-d'),
        'cliente_nombre' => $cliente ?? '',
        'cliente_documento' => $doc ?? '',
        'cliente_correo' => $correo,
        'mascota_nombre' => $mascota ?? '',
        'parentesco' => $parentesco,
        'boleta' => $boleta ?? '',
        'monto' => $monto ?? 0,
        'motivo' => $motivo ?? '',
        'cuenta_bancaria' => grooflow_extornos_str($get('cuentaBancaria'), 80),
        'entidad_bancaria' => grooflow_extornos_str($get('entidadBancaria'), 120),
        'metodo_pago_solicitado' => $metodoSol,
        'admin_solicita_nombre' => $admin ?? '',
        'campos_extra' => $extras,
    ];
}

function grooflow_extornos_save_adjunto(PDO $pdo, int $extornoId, string $tipo, array $file, int $userId): void
{
    if ($tipo === 'boleta' || $tipo === 'comprobante_pago') {
        $pdo->prepare('DELETE FROM grooflow_extornos_adjuntos WHERE extorno_id = ? AND tipo = ?')->execute([$extornoId, $tipo]);
    }
    $pdo->prepare('
        INSERT INTO grooflow_extornos_adjuntos
            (extorno_id, tipo, nombre, mime, size_bytes, data, hash, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([
        $extornoId, $tipo, $file['nombre'], $file['mime'], strlen($file['bytes']), $file['bytes'], $file['hash'], $userId ?: null,
    ]);
}

function grooflow_extornos_fill_draft_defaults(array $data, string $userName): array
{
    if ($data['sede'] === '') {
        $data['sede'] = 'Por definir';
    }
    if ($data['cliente_nombre'] === '') {
        $data['cliente_nombre'] = 'Por definir';
    }
    if ($data['cliente_documento'] === '') {
        $data['cliente_documento'] = '—';
    }
    if ($data['mascota_nombre'] === '') {
        $data['mascota_nombre'] = 'Por definir';
    }
    if ($data['boleta'] === '') {
        $data['boleta'] = 'Pendiente';
    }
    if ($data['motivo'] === '') {
        $data['motivo'] = 'Pendiente de detalle';
    }
    if ($data['admin_solicita_nombre'] === '') {
        $data['admin_solicita_nombre'] = mb_substr($userName !== '' ? $userName : 'Administrador', 0, 190);
    }
    if (($data['monto'] ?? 0) < 0) {
        $data['monto'] = 0;
    }

    return $data;
}

function grooflow_extornos_create(PDO $pdo, array $in): array
{
    grooflow_extornos_ensure_schema($pdo);
    grooflow_extornos_require($pdo, 'agregar');
    $plantillaId = (int) ($in['plantillaId'] ?? 0);
    $tpl = grooflow_extornos_template($pdo, $plantillaId > 0 ? $plantillaId : null);
    $plantillaId = (int) ($tpl['id'] ?? 0);
    $submit = !empty($in['submit']);
    $data = grooflow_extornos_parse_payload($in, $tpl, $submit);
    $boletaFile = grooflow_extornos_decode_file($in['boletaAdjunto'] ?? null, (string) ($in['boletaAdjuntoNombre'] ?? 'boleta.pdf'), $submit, 'boletaAdjunto');
    [$user, $userId, $userName] = grooflow_extornos_user();
    if (!$submit) {
        $data = grooflow_extornos_fill_draft_defaults($data, $userName);
    } elseif ($data['admin_solicita_nombre'] === '') {
        $data['admin_solicita_nombre'] = mb_substr($userName, 0, 190);
    }
    $extrasJson = $data['campos_extra'] !== [] ? grooflow_json_encode($data['campos_extra']) : null;

    $id = grooflow_atomic($pdo, static function () use ($pdo, $data, $tpl, $plantillaId, $extrasJson, $submit, $boletaFile, $user, $userId, $userName): int {
        $estado = $submit ? 'enviado' : 'borrador';
        // codigo NULL primero (unique permite varios NULL) → luego se fija con el id (sin carrera 23000).
        $pdo->prepare('
            INSERT INTO grooflow_extornos (
                codigo, estado, plantilla_version, plantilla_id, sede, fecha_solicitud, cliente_nombre, cliente_documento,
                cliente_correo, mascota_nombre, parentesco, boleta, monto, motivo, campos_extra, cuenta_bancaria,
                entidad_bancaria, metodo_pago_solicitado, admin_solicita_nombre, created_by, created_by_nombre
            ) VALUES (NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ')->execute([
            $estado, (int) ($tpl['version'] ?? 1), $plantillaId > 0 ? $plantillaId : null,
            $data['sede'], $data['fecha_solicitud'], $data['cliente_nombre'],
            $data['cliente_documento'], $data['cliente_correo'], $data['mascota_nombre'], $data['parentesco'],
            $data['boleta'], $data['monto'], $data['motivo'], $extrasJson, $data['cuenta_bancaria'], $data['entidad_bancaria'],
            $data['metodo_pago_solicitado'], $data['admin_solicita_nombre'], $userId ?: null, mb_substr($userName, 0, 190),
        ]);
        $newId = (int) $pdo->lastInsertId();
        $year = (new DateTimeImmutable('now'))->format('Y');
        $codigo = 'EXT-' . $year . '-' . str_pad((string) $newId, 5, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE grooflow_extornos SET codigo = ? WHERE id = ?')->execute([$codigo, $newId]);
        if ($boletaFile !== null) {
            grooflow_extornos_save_adjunto($pdo, $newId, 'boleta', $boletaFile, $userId);
        }
        grooflow_extornos_log($pdo, $newId, null, $estado, $submit ? 'enviar' : 'crear', null, $user, $userId, $userName);

        return $newId;
    });

    return grooflow_extornos_row_to_app($pdo, grooflow_extornos_find($pdo, $id), true);
}

function grooflow_extornos_update(PDO $pdo, int $id, array $in): array
{
    grooflow_extornos_ensure_schema($pdo);
    $asAuditor = grooflow_extornos_can_review($pdo);
    if (!$asAuditor && !grooflow_extornos_can($pdo, 'editar')) {
        throw new RuntimeException('No tienes permiso para editar extornos');
    }
    $existing = grooflow_extornos_find($pdo, $id);
    $plantillaId = (int) ($in['plantillaId'] ?? $existing['plantilla_id'] ?? 0);
    $tpl = grooflow_extornos_template($pdo, $plantillaId > 0 ? $plantillaId : null);
    $submit = !empty($in['submit']);
    $data = grooflow_extornos_parse_payload($in, $tpl, $submit);
    $boletaFile = grooflow_extornos_decode_file($in['boletaAdjunto'] ?? null, (string) ($in['boletaAdjuntoNombre'] ?? 'boleta.pdf'), false, 'boletaAdjunto');
    [$user, $userId, $userName] = grooflow_extornos_user();
    if (!$submit) {
        $data = grooflow_extornos_fill_draft_defaults($data, $userName);
    }
    $extrasJson = $data['campos_extra'] !== [] ? grooflow_json_encode($data['campos_extra']) : null;
    $plantillaId = (int) ($tpl['id'] ?? 0);

    grooflow_atomic($pdo, static function () use ($pdo, $id, $data, $submit, $boletaFile, $extrasJson, $plantillaId, $user, $userId, $userName, $asAuditor): void {
        $row = grooflow_extornos_find($pdo, $id, true);
        $estado = (string) $row['estado'];
        $editable = $asAuditor
            ? ['borrador', 'observado', 'enviado', 'aprobado', 'rechazado']
            : ['borrador', 'observado'];
        if (!in_array($estado, $editable, true)) {
            throw new InvalidArgumentException(
                $asAuditor
                    ? 'Auditoría no puede editar un extorno ya pagado o anulado.'
                    : 'Solo se editan borradores u observados.'
            );
        }
        if ($submit) {
            $hasBoleta = $boletaFile !== null;
            if (!$hasBoleta) {
                $c = $pdo->prepare('SELECT COUNT(*) FROM grooflow_extornos_adjuntos WHERE extorno_id = ? AND tipo = ?');
                $c->execute([$id, 'boleta']);
                $hasBoleta = ((int) $c->fetchColumn()) > 0;
            }
            if (!$hasBoleta) {
                throw new GrooflowValidation(['boletaAdjunto' => 'Adjunta la boleta firmada y sellada.']);
            }
        }
        // Reenvío desde observado vuelve a auditoría. Si auditoría corrige un aprobado, se mantiene aprobado.
        $next = $submit && in_array($estado, ['borrador', 'observado', 'rechazado'], true) ? 'enviado' : $estado;
        $pdo->prepare('
            UPDATE grooflow_extornos SET
                sede=?, fecha_solicitud=?, cliente_nombre=?, cliente_documento=?, cliente_correo=?,
                mascota_nombre=?, parentesco=?, boleta=?, monto=?, motivo=?, campos_extra=?, cuenta_bancaria=?,
                entidad_bancaria=?, metodo_pago_solicitado=?, admin_solicita_nombre=?,
                plantilla_id=COALESCE(?, plantilla_id),
                estado=?, nota_observacion = IF(?, NULL, nota_observacion)
            WHERE id=?
        ')->execute([
            $data['sede'], $data['fecha_solicitud'], $data['cliente_nombre'], $data['cliente_documento'], $data['cliente_correo'],
            $data['mascota_nombre'], $data['parentesco'], $data['boleta'], $data['monto'], $data['motivo'], $extrasJson,
            $data['cuenta_bancaria'], $data['entidad_bancaria'], $data['metodo_pago_solicitado'], $data['admin_solicita_nombre'],
            $plantillaId > 0 ? $plantillaId : null,
            $next, $submit ? 1 : 0, $id,
        ]);
        if ($boletaFile !== null) {
            grooflow_extornos_save_adjunto($pdo, $id, 'boleta', $boletaFile, $userId);
        }
        if ($submit || $estado !== $next) {
            grooflow_extornos_log($pdo, $id, $estado, $next, $submit ? 'enviar' : 'actualizar', null, $user, $userId, $userName);
        }
    });

    return grooflow_extornos_row_to_app($pdo, grooflow_extornos_find($pdo, $id), true);
}

/** @param list<string> $sections */
function grooflow_extornos_merge_section_extras(PDO $pdo, array $row, array $in, array $sections, bool $strict): ?string
{
    $plantillaId = (int) ($row['plantilla_id'] ?? 0);
    $tpl = grooflow_extornos_template($pdo, $plantillaId > 0 ? $plantillaId : null);
    $existing = [];
    $raw = $row['campos_extra'] ?? null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $existing = $decoded;
        }
    }
    $incoming = is_array($in['camposExtra'] ?? null) ? $in['camposExtra'] : [];
    $builtin = array_flip(grooflow_extornos_builtin_keys());
    $aplicaKey = null;
    $montoKey = null;
    $detalleKey = null;
    foreach ($tpl['fields'] as $f) {
        if (empty($f['enabled']) || !is_array($f)) {
            continue;
        }
        $key = (string) ($f['key'] ?? '');
        if ($key === '' || isset($builtin[$key])) {
            continue;
        }
        if (!in_array((string) ($f['section'] ?? ''), $sections, true)) {
            continue;
        }
        $label = (string) ($f['label'] ?? $key);
        $fold = mb_strtolower($label);
        if ($aplicaKey === null && str_contains($fold, 'aplica') && (str_contains($fold, 'descuento') || str_contains($fold, 'nómina') || str_contains($fold, 'nomina'))) {
            $aplicaKey = $key;
        }
        if ($montoKey === null && str_contains($fold, 'monto') && str_contains($fold, 'descuento')) {
            $montoKey = $key;
        }
        if ($detalleKey === null && $aplicaKey !== $key && (str_contains($fold, 'nombres') || str_contains($fold, 'a descontar'))) {
            $detalleKey = $key;
        }
        if (!array_key_exists($key, $incoming)) {
            continue;
        }
        $val = grooflow_extornos_str($incoming[$key], 8000);
        if ($strict && !empty($f['required']) && ($val === null || $val === '')) {
            throw new GrooflowValidation([$key => $label . ' es obligatorio.']);
        }
        if ($val === null) {
            unset($existing[$key]);
        } else {
            $existing[$key] = $val;
        }
    }
    if ($strict && $aplicaKey !== null && $montoKey !== null) {
        $aplica = mb_strtolower(trim((string) ($existing[$aplicaKey] ?? '')));
        $yes = in_array($aplica, ['si', 'sí', 's', 'yes', 'aplica', '1'], true) || str_starts_with($aplica, 'si');
        $monto = trim((string) ($existing[$montoKey] ?? ''));
        if ($yes && $monto === '') {
            throw new GrooflowValidation([$montoKey => 'Indica el monto del descuento en nómina.']);
        }
        if ($yes && $detalleKey !== null) {
            $rawDet = (string) ($existing[$detalleKey] ?? '');
            $plan = json_decode($rawDet, true);
            $lines = is_array($plan) && is_array($plan['lines'] ?? null) ? $plan['lines'] : [];
            if ($lines === []) {
                throw new GrooflowValidation([$detalleKey => 'Selecciona al menos un colaborador activo.']);
            }
            foreach ($lines as $i => $line) {
                if (!is_array($line)) {
                    continue;
                }
                $nombre = trim((string) ($line['nombre'] ?? ''));
                $lineMonto = (float) ($line['monto'] ?? 0);
                $cuotas = (int) ($line['cuotas'] ?? 0);
                if ($nombre === '' || $lineMonto <= 0 || $cuotas < 1) {
                    throw new GrooflowValidation([$detalleKey => 'Completa colaborador, monto y cuotas en la fila ' . ($i + 1) . '.']);
                }
            }
        }
    }

    return $existing === [] ? null : grooflow_json_encode($existing);
}

function grooflow_extornos_transition(PDO $pdo, int $id, array $in): array
{
    grooflow_extornos_ensure_schema($pdo);
    $action = (string) ($in['action'] ?? '');
    [$user, $userId, $userName] = grooflow_extornos_user();
    $note = grooflow_extornos_str($in['note'] ?? null, 800);

    if (in_array($action, ['approve', 'reject', 'observe'], true)) {
        if (!grooflow_extornos_can_review($pdo)) {
            throw new RuntimeException('No tienes permiso para revisar extornos');
        }
    } elseif ($action === 'pay' || $action === 'devolver') {
        if (!grooflow_extornos_can_pay($pdo)) {
            throw new RuntimeException('No tienes permiso para procesar el pago');
        }
    } elseif ($action === 'annul') {
        grooflow_extornos_require($pdo, 'eliminar');
    } else {
        throw new InvalidArgumentException('Acción inválida.');
    }

    if ($action === 'reject' && ($note === null || mb_strlen($note) < 4)) {
        throw new GrooflowValidation(['note' => 'Indica el motivo del rechazo.']);
    }
    if ($action === 'observe' && ($note === null || mb_strlen($note) < 4)) {
        throw new GrooflowValidation(['note' => 'Indica la observación.']);
    }
    if ($action === 'devolver' && ($note === null || mb_strlen($note) < 4)) {
        throw new GrooflowValidation(['note' => 'Indica el motivo para devolver la solicitud a la sede.']);
    }

    $firmaFile = null;
    $sello = null;
    if ($action === 'approve') {
        $modoFirma = (string) ($in['modoFirma'] ?? '');
        if ($modoFirma === 'electronica' || $modoFirma === 'registrada') {
            require_once __DIR__ . '/grooflow_firmas.php';
            grooflow_firmas_ensure_schema($pdo);
            $bank = grooflow_firmas_banco_get($pdo, $userId);
            if (!$bank || trim((string) $bank['nombre']) === '' || trim((string) $bank['documento']) === '' || trim((string) $bank['email']) === '') {
                throw new GrooflowValidation(['firma' => 'Primero registra tu identidad en Banco de firmas.']);
            }
            $in['firmaCargo'] = (string) ($bank['cargo'] ?? '');
            $in['auditorNombre'] = (string) $bank['nombre'];
            if ($modoFirma === 'electronica') {
                $sello = [
                    'documento' => (string) $bank['documento'],
                    'email' => (string) $bank['email'],
                    'ip' => grooflow_firmas_ip(),
                    'token' => bin2hex(random_bytes(16)),
                ];
            } else {
                $png = $bank['firma_png'] ?? null;
                if (!is_string($png) || $png === '') {
                    throw new GrooflowValidation(['firma' => 'No tienes una firma dibujada en el banco de firmas.']);
                }
                $firmaFile = [
                    'mime' => 'image/png',
                    'bytes' => $png,
                    'hash' => hash('sha256', $png),
                    'nombre' => 'firma.png',
                ];
            }
        }
        $firmaId = (int) ($in['firmaId'] ?? 0);
        if ($modoFirma !== 'electronica' && $modoFirma !== 'registrada' && $firmaId > 0 && $userId > 0) {
            $fsCat = $pdo->prepare('SELECT mime, data, cargo, nombre FROM grooflow_firmas WHERE id = ? AND user_id = ?');
            $fsCat->execute([$firmaId, $userId]);
            $cat = $fsCat->fetch(PDO::FETCH_ASSOC);
            if (is_array($cat)) {
                $firmaFile = [
                    'mime' => (string) $cat['mime'],
                    'bytes' => (string) $cat['data'],
                    'hash' => hash('sha256', (string) $cat['data']),
                    'nombre' => 'firma.png',
                ];
                if (empty($in['firmaCargo']) && !empty($cat['cargo'])) {
                    $in['firmaCargo'] = (string) $cat['cargo'];
                }
            }
        }
        if ($firmaFile === null && $modoFirma !== 'electronica' && $modoFirma !== 'registrada') {
            $firmaFile = grooflow_extornos_decode_file($in['firma'] ?? null, 'firma.png', false, 'firma');
        }
        if ($firmaFile === null && $modoFirma !== 'electronica' && $modoFirma !== 'registrada') {
            // usar firma guardada del usuario
            $fs = $pdo->prepare('SELECT mime, data FROM grooflow_extornos_firmas_usuario WHERE user_id = ?');
            $fs->execute([$userId]);
            $fr = $fs->fetch(PDO::FETCH_ASSOC);
            if (is_array($fr)) {
                $firmaFile = [
                    'mime' => (string) $fr['mime'],
                    'bytes' => (string) $fr['data'],
                    'hash' => hash('sha256', (string) $fr['data']),
                    'nombre' => 'firma.png',
                ];
            }
        }
        if ($firmaFile === null && $sello === null) {
            throw new GrooflowValidation(['firma' => 'Elige firma electrónica o tu firma registrada en el banco.']);
        }
    }

    $comprobante = null;
    if ($action === 'pay') {
        $comprobante = grooflow_extornos_decode_file($in['comprobante'] ?? null, (string) ($in['comprobanteNombre'] ?? 'comprobante.pdf'), true, 'comprobante');
    }

    grooflow_atomic($pdo, static function () use (
        $pdo, $id, $action, $note, $in, $user, $userId, $userName, $firmaFile, $comprobante, $sello
    ): void {
        $row = grooflow_extornos_find($pdo, $id, true);
        $estado = (string) $row['estado'];
        $fechaRecepcion = grooflow_extornos_date(is_string($in['fechaRecepcion'] ?? null) ? $in['fechaRecepcion'] : null, 'fechaRecepcion');
        $validacion = grooflow_extornos_str($in['validacionNota'] ?? null, 2000);
        $auditorNombre = grooflow_extornos_str($in['auditorNombre'] ?? null, 190) ?? mb_substr($userName, 0, 190);
        $fechaProceso = grooflow_extornos_date(is_string($in['fechaProceso'] ?? null) ? $in['fechaProceso'] : null, 'fechaProceso');
        $metodoReal = grooflow_extornos_str($in['metodoPagoReal'] ?? null, 30);
        if ($metodoReal !== null && !in_array($metodoReal, GROOFLOW_EXTORNOS_METODOS, true)) {
            throw new GrooflowValidation(['metodoPagoReal' => 'Método inválido.']);
        }
        $autorizado = grooflow_extornos_str($in['autorizadoPor'] ?? null, 190);
        $referencia = grooflow_extornos_str($in['referenciaPago'] ?? null, 120);

        if ($action === 'approve') {
            if ($estado !== 'enviado') {
                throw new InvalidArgumentException('Solo se aprueban solicitudes enviadas.');
            }
            $next = 'aprobado';
            $signHash = $firmaFile['hash'] ?? hash('sha256', (string) ($sello['token'] ?? ''));
            $docHash = hash('sha256', implode('|', [
                $row['id'], $row['boleta'], $row['monto'], $row['cliente_documento'], $signHash,
            ]));
            $firmaCargo = grooflow_extornos_str($in['firmaCargo'] ?? null, 190);
            $signerName = $sello ? (string) $auditorNombre : mb_substr($userName, 0, 190);
            $pdo->prepare('
                UPDATE grooflow_extornos SET
                    estado=?, fecha_recepcion=COALESCE(?, fecha_recepcion, CURDATE()), validacion_nota=COALESCE(?, validacion_nota),
                    auditor_nombre=?, nota_rechazo=NULL, nota_observacion=NULL,
                    firma_auditor_mime=?, firma_auditor_at=NOW(), firma_auditor_by=?, firma_auditor_by_nombre=?, firma_auditor_cargo=?, firma_hash=?,
                    firma_modo=?, firma_e_documento=?, firma_e_email=?, firma_e_ip=?, firma_e_token=?,
                    revisado_by=?, revisado_by_nombre=?, revisado_at=NOW()
                WHERE id=?
            ')->execute([
                $next, $fechaRecepcion, $validacion, $auditorNombre,
                $firmaFile['mime'] ?? null, $userId ?: null, mb_substr($signerName, 0, 190), $firmaCargo, $docHash,
                $sello ? 'electronica' : ($firmaFile ? 'registrada' : null),
                $sello['documento'] ?? null,
                $sello['email'] ?? null,
                $sello['ip'] ?? null,
                $sello['token'] ?? null,
                $userId ?: null, mb_substr($userName, 0, 190), $id,
            ]);
            if ($firmaFile !== null) {
                $pdo->prepare('REPLACE INTO grooflow_extornos_firmas_blob (extorno_id, mime, size_bytes, data) VALUES (?,?,?,?)')
                    ->execute([$id, $firmaFile['mime'], strlen($firmaFile['bytes']), $firmaFile['bytes']]);
            }
            if (!empty($in['guardarFirma']) && $userId > 0 && $firmaFile !== null) {
                $pdo->prepare('REPLACE INTO grooflow_extornos_firmas_usuario (user_id, mime, size_bytes, data) VALUES (?,?,?,?)')
                    ->execute([$userId, $firmaFile['mime'], strlen($firmaFile['bytes']), $firmaFile['bytes']]);
            }
        } elseif ($action === 'reject') {
            if ($estado !== 'enviado') {
                throw new InvalidArgumentException('Solo se rechazan solicitudes enviadas.');
            }
            $next = 'rechazado';
            $pdo->prepare('
                UPDATE grooflow_extornos SET estado=?, nota_rechazo=?, auditor_nombre=?,
                    revisado_by=?, revisado_by_nombre=?, revisado_at=NOW()
                WHERE id=?
            ')->execute([$next, $note, $auditorNombre, $userId ?: null, mb_substr($userName, 0, 190), $id]);
        } elseif ($action === 'observe') {
            if ($estado !== 'enviado') {
                throw new InvalidArgumentException('Solo se observan solicitudes enviadas.');
            }
            $next = 'observado';
            $pdo->prepare('
                UPDATE grooflow_extornos SET estado=?, nota_observacion=?, auditor_nombre=?,
                    revisado_by=?, revisado_by_nombre=?, revisado_at=NOW()
                WHERE id=?
            ')->execute([$next, $note, $auditorNombre, $userId ?: null, mb_substr($userName, 0, 190), $id]);
        } elseif ($action === 'devolver') {
            if ($estado !== 'aprobado') {
                throw new InvalidArgumentException('Tesorería solo devuelve solicitudes ya aprobadas por auditoría.');
            }
            $next = 'observado';
            $pdo->prepare('
                UPDATE grooflow_extornos SET estado=?, nota_observacion=?, nota_rechazo=NULL
                WHERE id=?
            ')->execute([$next, $note, $id]);
        } elseif ($action === 'pay') {
            if ($estado !== 'aprobado') {
                throw new InvalidArgumentException('Tesorería solo procesa extornos aprobados por auditoría.');
            }
            $next = 'pagado';
            $pdo->prepare('
                UPDATE grooflow_extornos SET
                    estado=?, fecha_proceso=COALESCE(?, CURDATE()), metodo_pago_real=COALESCE(?, metodo_pago_solicitado),
                    autorizado_por=COALESCE(?, ?), referencia_pago=?,
                    pagado_by=?, pagado_by_nombre=?, pagado_at=NOW()
                WHERE id=?
            ')->execute([
                $next, $fechaProceso, $metodoReal, $autorizado, mb_substr($userName, 0, 190), $referencia,
                $userId ?: null, mb_substr($userName, 0, 190), $id,
            ]);
            if ($comprobante !== null) {
                grooflow_extornos_save_adjunto($pdo, $id, 'comprobante_pago', $comprobante, $userId);
            }
        } else { // annul
            if (in_array($estado, ['pagado', 'anulado'], true)) {
                throw new InvalidArgumentException('No se puede anular en este estado.');
            }
            $next = 'anulado';
            $pdo->prepare('UPDATE grooflow_extornos SET estado=? WHERE id=?')->execute([$next, $id]);
        }

        if (in_array($action, ['approve', 'observe', 'reject'], true)) {
            $extras = grooflow_extornos_merge_section_extras($pdo, $row, $in, ['auditoria'], $action === 'approve');
            $pdo->prepare('UPDATE grooflow_extornos SET campos_extra = ? WHERE id = ?')->execute([$extras, $id]);
        }

        grooflow_extornos_log($pdo, $id, $estado, $next, $action, $note, $user, $userId, $userName);
    });

    if ($action === 'approve' || $action === 'annul') {
        require_once __DIR__ . '/grooflow_nomina_descuentos.php';
        try {
            if ($action === 'approve') {
                grooflow_nomina_from_extorno($pdo, $id);
            } else {
                grooflow_nomina_anular_por_extorno($pdo, $id);
            }
        } catch (Throwable $e) {
            // El extorno ya quedó guardado. El descuento se puede crear a mano si este paso falla.
        }
    }

    return grooflow_extornos_row_to_app($pdo, grooflow_extornos_find($pdo, $id), true);
}

function grooflow_extornos_delete(PDO $pdo, int $id): void
{
    grooflow_extornos_ensure_schema($pdo);
    $asAuditor = grooflow_extornos_can_review($pdo);
    if (!$asAuditor && !grooflow_extornos_can($pdo, 'eliminar')) {
        throw new RuntimeException('No tienes permiso para eliminar extornos');
    }
    [$user, $userId, $userName] = grooflow_extornos_user();
    grooflow_atomic($pdo, static function () use ($pdo, $id, $user, $userId, $userName, $asAuditor): void {
        $row = grooflow_extornos_find($pdo, $id, true);
        $estado = (string) $row['estado'];
        if ($estado === 'pagado') {
            throw new InvalidArgumentException('No se elimina un extorno ya pagado.');
        }
        if (!$asAuditor && !in_array($estado, ['borrador', 'rechazado', 'anulado'], true)) {
            throw new InvalidArgumentException('Solo se eliminan borradores, rechazados o anulados.');
        }
        $pdo->prepare('DELETE FROM grooflow_extornos_adjuntos WHERE extorno_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM grooflow_extornos_historial WHERE extorno_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM grooflow_extornos_firmas_blob WHERE extorno_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM grooflow_extornos WHERE id = ?')->execute([$id]);
        grooflow_audit_insert($pdo, $user, 'extornos.delete', ['entity' => 'extorno', 'entity_id' => (string) $id]);
    });
}

function grooflow_extornos_list(PDO $pdo, array $q): array
{
    grooflow_extornos_ensure_schema($pdo);
    grooflow_extornos_require($pdo, 'ver');
    $where = ['1=1'];
    $params = [];
    $estado = trim((string) ($q['estado'] ?? ''));
    if ($estado !== '' && in_array($estado, GROOFLOW_EXTORNOS_ESTADOS, true)) {
        $where[] = 'estado = ?';
        $params[] = $estado;
    }
    $sede = trim((string) ($q['sede'] ?? ''));
    if ($sede !== '') {
        $where[] = 'sede = ?';
        $params[] = $sede;
    }
    $desde = trim((string) ($q['desde'] ?? ''));
    if ($desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
        $where[] = 'fecha_solicitud >= ?';
        $params[] = $desde;
    }
    $hasta = trim((string) ($q['hasta'] ?? ''));
    if ($hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        $where[] = 'fecha_solicitud <= ?';
        $params[] = $hasta;
    }
    $search = trim((string) ($q['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(cliente_nombre LIKE ? OR cliente_documento LIKE ? OR boleta LIKE ? OR codigo LIKE ? OR mascota_nombre LIKE ? OR motivo LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    $sql = 'SELECT * FROM grooflow_extornos WHERE ' . implode(' AND ', $where)
        . " ORDER BY FIELD(estado,'enviado','observado','aprobado','borrador','pagado','rechazado','anulado'), fecha_solicitud DESC, id DESC LIMIT 1500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $items[] = grooflow_extornos_row_to_app($pdo, $row, false);
    }

    return $items;
}

function grooflow_extornos_get(PDO $pdo, int $id): array
{
    grooflow_extornos_ensure_schema($pdo);
    grooflow_extornos_require($pdo, 'ver');

    return grooflow_extornos_row_to_app($pdo, grooflow_extornos_find($pdo, $id), true);
}

function grooflow_extornos_stats(PDO $pdo, array $q = []): array
{
    grooflow_extornos_ensure_schema($pdo);
    grooflow_extornos_require($pdo, 'ver');
    $where = ['1=1'];
    $params = [];
    $sede = trim((string) ($q['sede'] ?? ''));
    if ($sede !== '') {
        $where[] = 'sede = ?';
        $params[] = $sede;
    }
    $desde = trim((string) ($q['desde'] ?? ''));
    if ($desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
        $where[] = 'fecha_solicitud >= ?';
        $params[] = $desde;
    }
    $hasta = trim((string) ($q['hasta'] ?? ''));
    if ($hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        $where[] = 'fecha_solicitud <= ?';
        $params[] = $hasta;
    }
    $w = implode(' AND ', $where);
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(estado = 'borrador'), 0) AS borradores,
            COALESCE(SUM(estado = 'enviado'), 0) AS enviados,
            COALESCE(SUM(estado = 'observado'), 0) AS observados,
            COALESCE(SUM(estado = 'aprobado'), 0) AS aprobados,
            COALESCE(SUM(estado = 'rechazado'), 0) AS rechazados,
            COALESCE(SUM(estado = 'pagado'), 0) AS pagados,
            COALESCE(SUM(estado = 'anulado'), 0) AS anulados,
            COALESCE(SUM(CASE WHEN estado = 'pagado' THEN monto ELSE 0 END), 0) AS monto_pagado,
            COALESCE(SUM(CASE WHEN estado IN ('enviado','observado','aprobado') THEN monto ELSE 0 END), 0) AS monto_pendiente,
            COALESCE(SUM(monto), 0) AS monto_total
        FROM grooflow_extornos WHERE {$w}
    ");
    $stmt->execute($params);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $porSedeStmt = $pdo->prepare("
        SELECT sede,
            COUNT(*) AS total,
            COALESCE(SUM(estado = 'pagado'), 0) AS pagados,
            COALESCE(SUM(CASE WHEN estado = 'pagado' THEN monto ELSE 0 END), 0) AS monto_pagado
        FROM grooflow_extornos WHERE {$w}
        GROUP BY sede ORDER BY total DESC LIMIT 30
    ");
    $porSedeStmt->execute($params);
    $porSede = [];
    while ($s = $porSedeStmt->fetch(PDO::FETCH_ASSOC)) {
        $porSede[] = [
            'sede' => (string) $s['sede'],
            'total' => (int) $s['total'],
            'pagados' => (int) $s['pagados'],
            'montoPagado' => (float) $s['monto_pagado'],
        ];
    }

    return [
        'total' => (int) ($r['total'] ?? 0),
        'borradores' => (int) ($r['borradores'] ?? 0),
        'enviados' => (int) ($r['enviados'] ?? 0),
        'observados' => (int) ($r['observados'] ?? 0),
        'aprobados' => (int) ($r['aprobados'] ?? 0),
        'rechazados' => (int) ($r['rechazados'] ?? 0),
        'pagados' => (int) ($r['pagados'] ?? 0),
        'anulados' => (int) ($r['anulados'] ?? 0),
        'montoPagado' => (float) ($r['monto_pagado'] ?? 0),
        'montoPendiente' => (float) ($r['monto_pendiente'] ?? 0),
        'montoTotal' => (float) ($r['monto_total'] ?? 0),
        'porSede' => $porSede,
    ];
}

function grooflow_extornos_adjunto(PDO $pdo, int $adjuntoId): array
{
    grooflow_extornos_ensure_schema($pdo);
    grooflow_extornos_require($pdo, 'ver');
    $stmt = $pdo->prepare('SELECT * FROM grooflow_extornos_adjuntos WHERE id = ?');
    $stmt->execute([$adjuntoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Adjunto no encontrado');
    }

    return [
        'id' => (string) $row['id'],
        'tipo' => (string) $row['tipo'],
        'nombre' => (string) $row['nombre'],
        'mime' => (string) $row['mime'],
        'dataUrl' => 'data:' . $row['mime'] . ';base64,' . base64_encode((string) $row['data']),
    ];
}

function grooflow_extornos_my_signature(PDO $pdo): array
{
    grooflow_extornos_ensure_schema($pdo);
    [, $userId] = grooflow_extornos_user();
    if ($userId <= 0) {
        return ['hasFirma' => false];
    }
    $stmt = $pdo->prepare('SELECT mime, data FROM grooflow_extornos_firmas_usuario WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['hasFirma' => false];
    }

    return [
        'hasFirma' => true,
        'dataUrl' => 'data:' . $row['mime'] . ';base64,' . base64_encode((string) $row['data']),
    ];
}

function grooflow_extornos_save_my_signature(PDO $pdo, array $in): array
{
    grooflow_extornos_ensure_schema($pdo);
    if (!grooflow_extornos_can_review($pdo)) {
        throw new RuntimeException('No tienes permiso para guardar firma de auditoría');
    }
    [, $userId] = grooflow_extornos_user();
    if ($userId <= 0) {
        throw new RuntimeException('Usuario inválido');
    }
    $file = grooflow_extornos_decode_file($in['firma'] ?? null, 'firma.png', true, 'firma');
    $pdo->prepare('REPLACE INTO grooflow_extornos_firmas_usuario (user_id, mime, size_bytes, data) VALUES (?,?,?,?)')
        ->execute([$userId, $file['mime'], strlen($file['bytes']), $file['bytes']]);

    return ['ok' => true, 'hasFirma' => true];
}

function grooflow_extornos_planilla(PDO $pdo, int $id): array
{
    grooflow_extornos_ensure_schema($pdo);
    grooflow_extornos_require($pdo, 'ver');
    $item = grooflow_extornos_row_to_app($pdo, grooflow_extornos_find($pdo, $id), true);
    $adjuntos = [];
    $stmt = $pdo->prepare('SELECT id, tipo, nombre, mime, data FROM grooflow_extornos_adjuntos WHERE extorno_id = ?');
    $stmt->execute([$id]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $adjuntos[] = [
            'id' => (string) $r['id'],
            'tipo' => (string) $r['tipo'],
            'nombre' => (string) $r['nombre'],
            'mime' => (string) $r['mime'],
            'dataUrl' => 'data:' . $r['mime'] . ';base64,' . base64_encode((string) $r['data']),
        ];
    }
    $tpl = grooflow_extornos_template($pdo);

    return ['item' => $item, 'adjuntos' => $adjuntos, 'template' => $tpl];
}

function grooflow_extornos_capabilities(PDO $pdo): array
{
    return [
        'canVer' => grooflow_extornos_can($pdo, 'ver'),
        'canAdd' => grooflow_extornos_can($pdo, 'agregar'),
        'canEdit' => grooflow_extornos_can($pdo, 'editar'),
        'canDelete' => grooflow_extornos_can($pdo, 'eliminar'),
        'canReview' => grooflow_extornos_can_review($pdo),
        'canPay' => grooflow_extornos_can_pay($pdo),
        'canConfigure' => grooflow_extornos_can($pdo, 'configurar'),
        'canExport' => grooflow_extornos_can($pdo, 'exportar') || grooflow_extornos_can($pdo, 'ver'),
    ];
}

function grooflow_firmas_row(array $row, bool $withImage = true): array
{
    $item = [
        'id' => (string) $row['id'],
        'nombre' => (string) $row['nombre'],
        'cargo' => $row['cargo'],
        'mime' => (string) $row['mime'],
        'esPredeterminada' => !empty($row['es_predeterminada']),
        'updatedAt' => (string) ($row['updated_at'] ?? ''),
    ];
    if ($withImage) {
        $item['dataUrl'] = 'data:' . $row['mime'] . ';base64,' . base64_encode((string) $row['data']);
    }

    return $item;
}

function grooflow_firmas_list(PDO $pdo): array
{
    grooflow_extornos_ensure_schema($pdo);
    [, $userId] = grooflow_extornos_user();
    if ($userId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT * FROM grooflow_firmas WHERE user_id = ? ORDER BY es_predeterminada DESC, id DESC');
    $stmt->execute([$userId]);
    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $items[] = grooflow_firmas_row($row, true);
    }

    return $items;
}

function grooflow_firmas_save(PDO $pdo, array $in, ?int $id = null): array
{
    grooflow_extornos_ensure_schema($pdo);
    [, $userId, $userName] = grooflow_extornos_user();
    if ($userId <= 0) {
        throw new RuntimeException('Usuario inválido');
    }
    $nombre = grooflow_extornos_str($in['nombre'] ?? null, 190) ?? $userName;
    $cargo = grooflow_extornos_str($in['cargo'] ?? null, 190);
    $file = grooflow_extornos_decode_file($in['firma'] ?? null, 'firma.png', $id === null, 'firma');
    $pred = !empty($in['esPredeterminada']);

    grooflow_atomic($pdo, static function () use ($pdo, $userId, $nombre, $cargo, $file, $pred, $id): void {
        if ($pred) {
            $pdo->prepare('UPDATE grooflow_firmas SET es_predeterminada = 0 WHERE user_id = ?')->execute([$userId]);
        }
        if ($id === null) {
            $count = $pdo->prepare('SELECT COUNT(*) FROM grooflow_firmas WHERE user_id = ?');
            $count->execute([$userId]);
            if ((int) $count->fetchColumn() >= 20) {
                throw new GrooflowValidation(['firma' => 'Máximo 20 firmas guardadas. Elimina una para agregar otra.']);
            }
            if ($file === null) {
                throw new GrooflowValidation(['firma' => 'Dibuja o sube la firma.']);
            }
            $pdo->prepare('
                INSERT INTO grooflow_firmas (user_id, nombre, cargo, mime, size_bytes, data, es_predeterminada)
                VALUES (?,?,?,?,?,?,?)
            ')->execute([
                $userId, $nombre, $cargo, $file['mime'], strlen($file['bytes']), $file['bytes'], $pred ? 1 : 0,
            ]);
        } else {
            $own = $pdo->prepare('SELECT id FROM grooflow_firmas WHERE id = ? AND user_id = ?');
            $own->execute([$id, $userId]);
            if (!$own->fetch()) {
                throw new RuntimeException('Firma no encontrada');
            }
            if ($file !== null) {
                $pdo->prepare('
                    UPDATE grooflow_firmas SET nombre=?, cargo=?, mime=?, size_bytes=?, data=?, es_predeterminada=IF(?,1,es_predeterminada)
                    WHERE id=? AND user_id=?
                ')->execute([$nombre, $cargo, $file['mime'], strlen($file['bytes']), $file['bytes'], $pred ? 1 : 0, $id, $userId]);
            } else {
                $pdo->prepare('
                    UPDATE grooflow_firmas SET nombre=?, cargo=?, es_predeterminada=IF(?,1,es_predeterminada)
                    WHERE id=? AND user_id=?
                ')->execute([$nombre, $cargo, $pred ? 1 : 0, $id, $userId]);
            }
        }
    });

    $items = grooflow_firmas_list($pdo);

    return ['items' => $items];
}

function grooflow_firmas_delete(PDO $pdo, int $id): array
{
    grooflow_extornos_ensure_schema($pdo);
    [, $userId] = grooflow_extornos_user();
    $pdo->prepare('DELETE FROM grooflow_firmas WHERE id = ? AND user_id = ?')->execute([$id, $userId]);

    return ['items' => grooflow_firmas_list($pdo)];
}

function grooflow_extornos_dispatch(PDO $pdo, string $path, string $method): bool
{
    if ($path !== '/extornos' && !str_starts_with($path, '/extornos/')) {
        return false;
    }

    try {
        if ($path === '/extornos/capabilities' && $method === 'GET') {
            api_json_response(['ok' => true] + grooflow_extornos_capabilities($pdo));

            return true;
        }
        if ($path === '/extornos/template' && $method === 'GET') {
            grooflow_extornos_require($pdo, 'ver');
            $pid = (int) ($_GET['id'] ?? 0);
            api_json_response(['ok' => true, 'template' => grooflow_extornos_template($pdo, $pid > 0 ? $pid : null)]);

            return true;
        }
        if ($path === '/extornos/template' && $method === 'PUT') {
            api_json_response(['ok' => true, 'template' => grooflow_extornos_save_template($pdo, api_request_json())]);

            return true;
        }
        if ($path === '/extornos/plantillas' && $method === 'GET') {
            api_json_response(['ok' => true, 'items' => grooflow_extornos_plantillas_list($pdo)]);

            return true;
        }
        if ($path === '/extornos/plantillas' && $method === 'POST') {
            api_json_response(['ok' => true, 'item' => grooflow_extornos_plantilla_create($pdo, api_request_json())]);

            return true;
        }
        if (preg_match('#^/extornos/plantillas/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
            if ($method === 'GET') {
                grooflow_extornos_require($pdo, 'ver');
                api_json_response(['ok' => true, 'item' => grooflow_extornos_plantilla_get($pdo, $id)]);

                return true;
            }
            if ($method === 'PUT') {
                api_json_response(['ok' => true, 'item' => grooflow_extornos_plantilla_update($pdo, $id, api_request_json())]);

                return true;
            }
            if ($method === 'DELETE') {
                grooflow_extornos_plantilla_delete($pdo, $id);
                api_json_response(['ok' => true]);

                return true;
            }
        }
        if (preg_match('#^/extornos/plantillas/(\d+)/duplicar$#', $path, $m) && $method === 'POST') {
            $src = grooflow_extornos_plantilla_get($pdo, (int) $m[1]);
            $in = api_request_json();
            api_json_response(['ok' => true, 'item' => grooflow_extornos_plantilla_create($pdo, [
                'nombre' => grooflow_extornos_str($in['nombre'] ?? null, 120) ?? ($src['nombre'] . ' (copia)'),
                'titulo' => $src['titulo'],
                'descripcion' => $src['descripcion'],
                'fromId' => (int) $src['id'],
                'esDefault' => false,
            ])]);

            return true;
        }
        if ($path === '/extornos/stats' && $method === 'GET') {
            api_json_response(['ok' => true, 'stats' => grooflow_extornos_stats($pdo, $_GET)]);

            return true;
        }
        if ($path === '/extornos/firma' && $method === 'GET') {
            api_json_response(['ok' => true] + grooflow_extornos_my_signature($pdo));

            return true;
        }
        if ($path === '/extornos/firma' && $method === 'PUT') {
            api_json_response(['ok' => true] + grooflow_extornos_save_my_signature($pdo, api_request_json()));

            return true;
        }
        if ($path === '/extornos/firmas' && $method === 'GET') {
            api_json_response(['ok' => true, 'items' => grooflow_firmas_list($pdo)]);

            return true;
        }
        if ($path === '/extornos/firmas' && $method === 'POST') {
            api_json_response(['ok' => true] + grooflow_firmas_save($pdo, api_request_json()));

            return true;
        }
        if (preg_match('#^/extornos/firmas/(\d+)$#', $path, $m)) {
            $fid = (int) $m[1];
            if ($method === 'PUT') {
                api_json_response(['ok' => true] + grooflow_firmas_save($pdo, api_request_json(), $fid));

                return true;
            }
            if ($method === 'DELETE') {
                api_json_response(['ok' => true] + grooflow_firmas_delete($pdo, $fid));

                return true;
            }
        }
        if ($path === '/extornos/solicitudes' && $method === 'GET') {
            api_json_response(['ok' => true, 'items' => grooflow_extornos_list($pdo, $_GET)]);

            return true;
        }
        if ($path === '/extornos/solicitudes' && $method === 'POST') {
            api_json_response(['ok' => true, 'item' => grooflow_extornos_create($pdo, api_request_json())]);

            return true;
        }
        if (preg_match('#^/extornos/solicitudes/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
            if ($method === 'GET') {
                api_json_response(['ok' => true, 'item' => grooflow_extornos_get($pdo, $id)]);

                return true;
            }
            if ($method === 'PUT') {
                api_json_response(['ok' => true, 'item' => grooflow_extornos_update($pdo, $id, api_request_json())]);

                return true;
            }
            if ($method === 'DELETE') {
                grooflow_extornos_delete($pdo, $id);
                api_json_response(['ok' => true]);

                return true;
            }
        }
        if (preg_match('#^/extornos/solicitudes/(\d+)/transition$#', $path, $m) && $method === 'POST') {
            api_json_response(['ok' => true, 'item' => grooflow_extornos_transition($pdo, (int) $m[1], api_request_json())]);

            return true;
        }
        if (preg_match('#^/extornos/solicitudes/(\d+)/planilla$#', $path, $m) && $method === 'GET') {
            api_json_response(['ok' => true] + grooflow_extornos_planilla($pdo, (int) $m[1]));

            return true;
        }
        if (preg_match('#^/extornos/adjuntos/(\d+)$#', $path, $m) && $method === 'GET') {
            api_json_response(['ok' => true] + grooflow_extornos_adjunto($pdo, (int) $m[1]));

            return true;
        }

        api_json_response(['ok' => false, 'error' => 'Ruta de Extornos no encontrada'], 404);
    } catch (GrooflowValidation $e) {
        api_json_response(['ok' => false, 'error' => 'Validación', 'errors' => $e->errors], 422);
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, '23000')) {
            api_json_response([
                'ok' => false,
                'error' => 'Conflicto de datos (registro duplicado o restricción). Recarga e intenta de nuevo.',
            ], 409);
        } else {
            api_json_response(['ok' => false, 'error' => 'Error de base de datos en Extornos'], 500);
        }
    } catch (InvalidArgumentException $e) {
        api_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    } catch (RuntimeException $e) {
        $code = str_contains(mb_strtolower($e->getMessage()), 'permiso') ? 403 : 404;
        api_json_response(['ok' => false, 'error' => $e->getMessage()], $code);
    } catch (Throwable $e) {
        api_json_response(['ok' => false, 'error' => $e->getMessage() ?: 'Error en Extornos'], 500);
    }

    return true;
}
