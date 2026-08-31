<?php

declare(strict_types=1);

/**
 * Asistencia normalizada (MySQL):
 * - settings:asistencia → meta + staff + requirements + sede_profiles + sede_mappings
 * - data:asistencia-snapshots → snapshots diarios
 * - data:asistencia-operational → contexto de alertas
 *
 * El blob KV se mantiene por compatibilidad; las tablas son la fuente relacional.
 */

function grooflow_asistencia_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_asistencia_meta (
            id VARCHAR(40) NOT NULL,
            buk_json JSON NULL,
            area_keywords_json JSON NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_asistencia_sede_profiles (
            sede_name VARCHAR(160) NOT NULL,
            buk_recinto_code VARCHAR(80) NULL,
            payload JSON NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (sede_name),
            KEY idx_asist_profile_recinto (buk_recinto_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_asistencia_sede_mappings (
            sede_name VARCHAR(160) NOT NULL,
            buk_recinto_code VARCHAR(80) NOT NULL,
            buk_recinto_name VARCHAR(160) NULL,
            payload JSON NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (sede_name),
            KEY idx_asist_map_recinto (buk_recinto_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_asistencia_operational (
            id VARCHAR(40) NOT NULL,
            date_ymd CHAR(10) NOT NULL,
            cache_fetched_at BIGINT NULL,
            buk_enabled TINYINT(1) NOT NULL DEFAULT 0,
            payload JSON NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function grooflow_asistencia_table_count(PDO $pdo, string $table): int
{
    $table = str_replace('`', '', $table);
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function grooflow_asistencia_has_normalized_data(PDO $pdo): bool
{
    return grooflow_asistencia_table_count($pdo, 'grooflow_asistencia_meta') > 0
        || grooflow_asistencia_table_count($pdo, 'grooflow_asistencia_staff') > 0
        || grooflow_asistencia_table_count($pdo, 'grooflow_asistencia_requirements') > 0
        || grooflow_asistencia_table_count($pdo, 'grooflow_asistencia_sede_profiles') > 0
        || grooflow_asistencia_table_count($pdo, 'grooflow_asistencia_sede_mappings') > 0;
}

/** @return array<string, mixed> */
function grooflow_asistencia_compose_settings(PDO $pdo): array
{
    $settings = [
        'buk' => [
            'apiBaseUrl' => 'https://app.ctrlit.cl/ctrl/api/v2',
            'apiToken' => '',
            'enabled' => false,
            'autoRefreshEnabled' => false,
            'autoRefreshIntervalMinutes' => 30,
            'autoRefreshWindowStart' => '06:00',
            'autoRefreshWindowEnd' => '22:00',
        ],
        'requirements' => [],
        'staff' => [],
        'sedeProfiles' => [],
        'areaKeywords' => [
            'medica' => [],
            'peluqueria' => [],
        ],
        'sedeMappings' => [],
    ];

    $meta = $pdo->query("SELECT buk_json, area_keywords_json FROM grooflow_asistencia_meta WHERE id = 'default' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (is_array($meta)) {
        $buk = grooflow_json_decode(isset($meta['buk_json']) ? (string) $meta['buk_json'] : null);
        if (is_array($buk)) {
            $settings['buk'] = array_merge($settings['buk'], $buk);
        }
        $kw = grooflow_json_decode(isset($meta['area_keywords_json']) ? (string) $meta['area_keywords_json'] : null);
        if (is_array($kw)) {
            $settings['areaKeywords'] = $kw;
        }
    }

    $staffRows = $pdo->query('SELECT payload FROM grooflow_asistencia_staff ORDER BY sort_order, full_name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($staffRows as $row) {
        $item = grooflow_json_decode((string) ($row['payload'] ?? ''));
        if (is_array($item)) {
            $settings['staff'][] = $item;
        }
    }

    $reqRows = $pdo->query('SELECT payload FROM grooflow_asistencia_requirements ORDER BY sort_order, cargo_label')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($reqRows as $row) {
        $item = grooflow_json_decode((string) ($row['payload'] ?? ''));
        if (is_array($item)) {
            $settings['requirements'][] = $item;
        }
    }

    $profileRows = $pdo->query('SELECT payload FROM grooflow_asistencia_sede_profiles ORDER BY sede_name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($profileRows as $row) {
        $item = grooflow_json_decode((string) ($row['payload'] ?? ''));
        if (is_array($item)) {
            $settings['sedeProfiles'][] = $item;
        }
    }

    $mapRows = $pdo->query('SELECT payload FROM grooflow_asistencia_sede_mappings ORDER BY sede_name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($mapRows as $row) {
        $item = grooflow_json_decode((string) ($row['payload'] ?? ''));
        if (is_array($item)) {
            $settings['sedeMappings'][] = $item;
        }
    }

    return $settings;
}

/** @param array<string, mixed> $settings */
function grooflow_asistencia_sync_settings_tables(PDO $pdo, array $settings): void
{
    $buk = isset($settings['buk']) && is_array($settings['buk']) ? $settings['buk'] : [];
    $keywords = isset($settings['areaKeywords']) && is_array($settings['areaKeywords']) ? $settings['areaKeywords'] : [];

    $metaStmt = $pdo->prepare('
        INSERT INTO grooflow_asistencia_meta (id, buk_json, area_keywords_json)
        VALUES (\'default\', ?, ?)
        ON DUPLICATE KEY UPDATE buk_json = VALUES(buk_json), area_keywords_json = VALUES(area_keywords_json)
    ');
    $metaStmt->execute([
        grooflow_json_encode($buk),
        grooflow_json_encode($keywords),
    ]);

    // Staff
    $keepStaff = [];
    $staffStmt = $pdo->prepare('
        INSERT INTO grooflow_asistencia_staff
            (id, sede_name, full_name, cargo_label, area, rut, usuario_id, is_critical, is_manager, sort_order, payload)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            sede_name = VALUES(sede_name),
            full_name = VALUES(full_name),
            cargo_label = VALUES(cargo_label),
            area = VALUES(area),
            rut = VALUES(rut),
            usuario_id = VALUES(usuario_id),
            is_critical = VALUES(is_critical),
            is_manager = VALUES(is_manager),
            sort_order = VALUES(sort_order),
            payload = VALUES(payload)
    ');
    $staffList = isset($settings['staff']) && is_array($settings['staff']) ? $settings['staff'] : [];
    foreach ($staffList as $item) {
        if (! is_array($item)) {
            continue;
        }
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $keepStaff[] = $id;
        $usuarioId = null;
        if (isset($item['usuarioId']) && ctype_digit((string) $item['usuarioId'])) {
            $usuarioId = (int) $item['usuarioId'];
        } elseif (isset($item['userId']) && ctype_digit((string) $item['userId'])) {
            $usuarioId = (int) $item['userId'];
        }
        $staffStmt->execute([
            $id,
            (string) ($item['sedeName'] ?? ''),
            (string) ($item['fullName'] ?? ''),
            (string) ($item['cargoLabel'] ?? ''),
            (string) ($item['area'] ?? 'administracion'),
            isset($item['rut']) ? (string) $item['rut'] : null,
            $usuarioId,
            ! empty($item['isCritical']) ? 1 : 0,
            ! empty($item['isManager']) ? 1 : 0,
            (int) ($item['sortOrder'] ?? 0),
            grooflow_json_encode($item),
        ]);
    }
    if ($keepStaff === []) {
        $pdo->exec('DELETE FROM grooflow_asistencia_staff');
    } else {
        $ph = implode(',', array_fill(0, count($keepStaff), '?'));
        $pdo->prepare('DELETE FROM grooflow_asistencia_staff WHERE id NOT IN (' . $ph . ')')->execute($keepStaff);
    }

    // Requirements
    $keepReq = [];
    $reqStmt = $pdo->prepare('
        INSERT INTO grooflow_asistencia_requirements
            (id, sede_name, area_group, cargo_label, required_count, sort_order, payload)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            sede_name = VALUES(sede_name),
            area_group = VALUES(area_group),
            cargo_label = VALUES(cargo_label),
            required_count = VALUES(required_count),
            sort_order = VALUES(sort_order),
            payload = VALUES(payload)
    ');
    $reqList = isset($settings['requirements']) && is_array($settings['requirements']) ? $settings['requirements'] : [];
    foreach ($reqList as $item) {
        if (! is_array($item)) {
            continue;
        }
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $keepReq[] = $id;
        $reqStmt->execute([
            $id,
            (string) ($item['sedeName'] ?? ''),
            (string) ($item['areaGroup'] ?? 'global'),
            (string) ($item['cargoLabel'] ?? ''),
            (int) ($item['requiredCount'] ?? 0),
            (int) ($item['sortOrder'] ?? 0),
            grooflow_json_encode($item),
        ]);
    }
    if ($keepReq === []) {
        $pdo->exec('DELETE FROM grooflow_asistencia_requirements');
    } else {
        $ph = implode(',', array_fill(0, count($keepReq), '?'));
        $pdo->prepare('DELETE FROM grooflow_asistencia_requirements WHERE id NOT IN (' . $ph . ')')->execute($keepReq);
    }

    // Sede profiles
    $keepProfiles = [];
    $profStmt = $pdo->prepare('
        INSERT INTO grooflow_asistencia_sede_profiles (sede_name, buk_recinto_code, payload)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE buk_recinto_code = VALUES(buk_recinto_code), payload = VALUES(payload)
    ');
    $profiles = isset($settings['sedeProfiles']) && is_array($settings['sedeProfiles']) ? $settings['sedeProfiles'] : [];
    foreach ($profiles as $item) {
        if (! is_array($item)) {
            continue;
        }
        $sede = trim((string) ($item['sedeName'] ?? ''));
        if ($sede === '') {
            continue;
        }
        $keepProfiles[] = $sede;
        $profStmt->execute([
            $sede,
            isset($item['bukRecintoCode']) ? (string) $item['bukRecintoCode'] : null,
            grooflow_json_encode($item),
        ]);
    }
    if ($keepProfiles === []) {
        $pdo->exec('DELETE FROM grooflow_asistencia_sede_profiles');
    } else {
        $ph = implode(',', array_fill(0, count($keepProfiles), '?'));
        $pdo->prepare('DELETE FROM grooflow_asistencia_sede_profiles WHERE sede_name NOT IN (' . $ph . ')')->execute($keepProfiles);
    }

    // Sede mappings
    $keepMaps = [];
    $mapStmt = $pdo->prepare('
        INSERT INTO grooflow_asistencia_sede_mappings (sede_name, buk_recinto_code, buk_recinto_name, payload)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            buk_recinto_code = VALUES(buk_recinto_code),
            buk_recinto_name = VALUES(buk_recinto_name),
            payload = VALUES(payload)
    ');
    $maps = isset($settings['sedeMappings']) && is_array($settings['sedeMappings']) ? $settings['sedeMappings'] : [];
    foreach ($maps as $item) {
        if (! is_array($item)) {
            continue;
        }
        $sede = trim((string) ($item['sedeName'] ?? ''));
        $code = trim((string) ($item['bukRecintoCode'] ?? ''));
        if ($sede === '' || $code === '') {
            continue;
        }
        $keepMaps[] = $sede;
        $mapStmt->execute([
            $sede,
            $code,
            isset($item['bukRecintoName']) ? (string) $item['bukRecintoName'] : null,
            grooflow_json_encode($item),
        ]);
    }
    if ($keepMaps === []) {
        $pdo->exec('DELETE FROM grooflow_asistencia_sede_mappings');
    } else {
        $ph = implode(',', array_fill(0, count($keepMaps), '?'));
        $pdo->prepare('DELETE FROM grooflow_asistencia_sede_mappings WHERE sede_name NOT IN (' . $ph . ')')->execute($keepMaps);
    }
}

/** Migra blob KV → tablas si aún no hay filas normalizadas. */
function grooflow_asistencia_migrate_from_kv_blob(PDO $pdo, mixed $blob): void
{
    if (! is_array($blob) || grooflow_asistencia_has_normalized_data($pdo)) {
        return;
    }
    grooflow_asistencia_sync_settings_tables($pdo, $blob);
}

function grooflow_asistencia_get_settings(PDO $pdo): ?array
{
    grooflow_asistencia_ensure_schema($pdo);

    $stmt = $pdo->prepare('SELECT value FROM grooflow_kv WHERE k = ? LIMIT 1');
    $stmt->execute(['settings:asistencia']);
    $raw = $stmt->fetchColumn();
    $blob = $raw === false || $raw === null
        ? null
        : grooflow_json_decode(is_string($raw) ? $raw : (string) $raw);

    if (is_array($blob)) {
        grooflow_asistencia_migrate_from_kv_blob($pdo, $blob);
    }

    if (grooflow_asistencia_has_normalized_data($pdo)) {
        $composed = grooflow_asistencia_compose_settings($pdo);
        // Preferir token Buk del blob si las tablas lo tienen vacío (legacy).
        if (is_array($blob) && is_array($blob['buk'] ?? null)) {
            $blobToken = trim((string) ($blob['buk']['apiToken'] ?? ''));
            $compToken = trim((string) ($composed['buk']['apiToken'] ?? ''));
            if ($compToken === '' && $blobToken !== '') {
                $composed['buk'] = array_merge($composed['buk'], $blob['buk']);
            }
        }

        return $composed;
    }

    return is_array($blob) ? $blob : null;
}

/** @param array<string, mixed> $settings */
function grooflow_asistencia_set_settings(PDO $pdo, array $settings): void
{
    grooflow_asistencia_ensure_schema($pdo);
    grooflow_asistencia_sync_settings_tables($pdo, $settings);

    $stmt = $pdo->prepare('
        INSERT INTO grooflow_kv (k, value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE value = VALUES(value)
    ');
    $stmt->execute(['settings:asistencia', grooflow_json_encode($settings)]);
}

/** @return list<array<string, mixed>> */
function grooflow_asistencia_list_snapshots(PDO $pdo): array
{
    grooflow_asistencia_ensure_schema($pdo);
    $rows = $pdo->query('
        SELECT payload FROM grooflow_asistencia_snapshots
        ORDER BY date_ymd DESC, sede_name ASC
        LIMIT 500
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $item = grooflow_json_decode((string) ($row['payload'] ?? ''));
        if (is_array($item)) {
            $out[] = $item;
        }
    }

    return $out;
}

/** @param list<mixed> $items */
function grooflow_asistencia_replace_snapshots(PDO $pdo, array $items): void
{
    grooflow_asistencia_ensure_schema($pdo);
    $keep = [];
    $stmt = $pdo->prepare('
        INSERT INTO grooflow_asistencia_snapshots
            (id, date_ymd, sede_name, captured_at, source, working_count, absent_count, late_count,
             critical_absent_count, total_required, total_present, buk_records_on_date, payload)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            captured_at = VALUES(captured_at),
            source = VALUES(source),
            working_count = VALUES(working_count),
            absent_count = VALUES(absent_count),
            late_count = VALUES(late_count),
            critical_absent_count = VALUES(critical_absent_count),
            total_required = VALUES(total_required),
            total_present = VALUES(total_present),
            buk_records_on_date = VALUES(buk_records_on_date),
            payload = VALUES(payload)
    ');

    foreach ($items as $item) {
        if (! is_array($item)) {
            continue;
        }
        $dateYmd = trim((string) ($item['dateYmd'] ?? ''));
        $sede = trim((string) ($item['sedeName'] ?? ''));
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '' && $dateYmd !== '' && $sede !== '') {
            $id = $dateYmd . ':' . $sede;
            $item['id'] = $id;
        }
        if ($id === '' || $dateYmd === '' || $sede === '') {
            continue;
        }
        $keep[] = $id;
        $capturedAt = (string) ($item['capturedAt'] ?? date('c'));
        $capturedSql = date('Y-m-d H:i:s', strtotime($capturedAt) ?: time());
        $source = (($item['source'] ?? 'manual') === 'auto') ? 'auto' : 'manual';
        $stmt->execute([
            $id,
            $dateYmd,
            $sede,
            $capturedSql,
            $source,
            (int) ($item['workingCount'] ?? 0),
            (int) ($item['absentCount'] ?? 0),
            (int) ($item['lateCount'] ?? 0),
            (int) ($item['criticalAbsentCount'] ?? 0),
            (int) ($item['totalRequired'] ?? 0),
            (int) ($item['totalPresent'] ?? 0),
            (int) ($item['bukRecordsOnDate'] ?? 0),
            grooflow_json_encode($item),
        ]);
    }

    if ($keep === []) {
        $pdo->exec('DELETE FROM grooflow_asistencia_snapshots');
    } else {
        $ph = implode(',', array_fill(0, count($keep), '?'));
        $pdo->prepare('DELETE FROM grooflow_asistencia_snapshots WHERE id NOT IN (' . $ph . ')')->execute($keep);
    }

    $kv = $pdo->prepare('
        INSERT INTO grooflow_kv (k, value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE value = VALUES(value)
    ');
    $kv->execute(['data:asistencia-snapshots', grooflow_json_encode($items)]);
}

function grooflow_asistencia_get_operational(PDO $pdo): ?array
{
    grooflow_asistencia_ensure_schema($pdo);
    $row = $pdo->query("SELECT payload FROM grooflow_asistencia_operational WHERE id = 'current' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (! is_array($row)) {
        return null;
    }
    $item = grooflow_json_decode((string) ($row['payload'] ?? ''));

    return is_array($item) ? $item : null;
}

/** @param array<string, mixed> $ctx */
function grooflow_asistencia_set_operational(PDO $pdo, array $ctx): void
{
    grooflow_asistencia_ensure_schema($pdo);
    $stmt = $pdo->prepare('
        INSERT INTO grooflow_asistencia_operational (id, date_ymd, cache_fetched_at, buk_enabled, payload)
        VALUES (\'current\', ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            date_ymd = VALUES(date_ymd),
            cache_fetched_at = VALUES(cache_fetched_at),
            buk_enabled = VALUES(buk_enabled),
            payload = VALUES(payload)
    ');
    $stmt->execute([
        (string) ($ctx['dateYmd'] ?? date('Y-m-d')),
        isset($ctx['cacheFetchedAt']) && is_numeric($ctx['cacheFetchedAt']) ? (int) $ctx['cacheFetchedAt'] : null,
        ! empty($ctx['bukEnabled']) ? 1 : 0,
        grooflow_json_encode($ctx),
    ]);

    $kv = $pdo->prepare('
        INSERT INTO grooflow_kv (k, value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE value = VALUES(value)
    ');
    $kv->execute(['data:asistencia-operational', grooflow_json_encode($ctx)]);
}

/** Rellena permisos HR faltantes en roles ya existentes. */
function grooflow_asistencia_backfill_role_permissions(PDO $pdo): void
{
    $rows = $pdo->query('SELECT id, permissions FROM grooflow_roles')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $upd = $pdo->prepare('UPDATE grooflow_roles SET permissions = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    foreach ($rows as $row) {
        $perms = grooflow_json_decode((string) ($row['permissions'] ?? ''));
        if (! is_array($perms)) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        $changed = false;
        $defaults = match ($id) {
            'super_admin', 'manager' => [
                'Asistencia' => true,
                'Turnos' => true,
                'Accidentes de Trabajo' => true,
                'Entrega de Uniformes' => true,
            ],
            'auditoria' => [
                'Asistencia' => false,
                'Turnos' => false,
                'Accidentes de Trabajo' => true,
                'Entrega de Uniformes' => false,
            ],
            default => [
                'Asistencia' => $perms['Asistencia'] ?? false,
                'Turnos' => false,
                'Accidentes de Trabajo' => false,
                'Entrega de Uniformes' => false,
            ],
        };
        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $perms)) {
                $perms[$key] = $value;
                $changed = true;
            }
        }
        if ($changed) {
            $upd->execute([grooflow_json_encode($perms), $id]);
        }
    }
}
