<?php

declare(strict_types=1);

/**
 * Maestro RRHH Buk.pe + catálogos (área / puesto / turno) en MySQL.
 * settings:rrhh solo guarda preferencias UI (columnas, flags, syncLog) — no el array de empleados.
 */

require_once __DIR__ . '/grooflow_proxy.php';
require_once __DIR__ . '/grooflow_kv.php';
require_once __DIR__ . '/grooflow_asistencia.php';
require_once __DIR__ . '/grooflow_buk_sync.php';

function grooflow_rrhh_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_buk_empleados (
            buk_id INT UNSIGNED NOT NULL,
            person_id INT UNSIGNED NULL,
            full_name VARCHAR(190) NOT NULL DEFAULT '',
            first_name VARCHAR(120) NULL,
            surname VARCHAR(120) NULL,
            document_type VARCHAR(40) NULL,
            document_number VARCHAR(40) NULL,
            email VARCHAR(190) NULL,
            personal_email VARCHAR(190) NULL,
            phone VARCHAR(60) NULL,
            status VARCHAR(40) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_terminated TINYINT(1) NOT NULL DEFAULT 0,
            cargo VARCHAR(160) NULL,
            cargo_code VARCHAR(60) NULL,
            area VARCHAR(160) NULL,
            sede VARCHAR(160) NULL,
            contract_type VARCHAR(120) NULL,
            start_date DATE NULL,
            end_date DATE NULL,
            area_asistencia VARCHAR(160) NULL,
            especialidad VARCHAR(160) NULL,
            supervisor VARCHAR(190) NULL,
            turno VARCHAR(120) NULL,
            turno_horario VARCHAR(80) NULL,
            turno_codigo VARCHAR(40) NULL,
            recinto_nombre VARCHAR(160) NULL,
            recinto_codigo VARCHAR(80) NULL,
            ultima_marcacion_entrada VARCHAR(40) NULL,
            ultima_marcacion_salida VARCHAR(40) NULL,
            ultima_asistencia_dia VARCHAR(40) NULL,
            asistencia_enriched TINYINT(1) NOT NULL DEFAULT 0,
            content_hash VARCHAR(64) NULL,
            missing_from_source TINYINT(1) NOT NULL DEFAULT 0,
            linked_usuario_id INT UNSIGNED NULL,
            payload JSON NULL,
            first_synced_at DATETIME NULL,
            last_updated_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (buk_id),
            KEY idx_gf_buk_emp_doc (document_number),
            KEY idx_gf_buk_emp_email (email),
            KEY idx_gf_buk_emp_active (is_active),
            KEY idx_gf_buk_emp_area (area),
            KEY idx_gf_buk_emp_sede (sede),
            KEY idx_gf_buk_emp_name (full_name),
            KEY idx_gf_buk_emp_linked (linked_usuario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    grooflow_rrhh_ensure_indexes($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_puestos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(160) NOT NULL,
            descripcion VARCHAR(255) NULL DEFAULT '',
            area_id INT UNSIGNED NULL,
            estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_gf_puesto_nombre (nombre),
            KEY idx_gf_puesto_area (area_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_turnos_catalog (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(40) NOT NULL DEFAULT '',
            nombre VARCHAR(160) NOT NULL,
            horario VARCHAR(80) NULL DEFAULT '',
            descripcion VARCHAR(255) NULL DEFAULT '',
            estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_gf_turno_codigo (codigo),
            KEY idx_gf_turno_nombre (nombre)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Semillas mínimas de turnos si vacío.
    $count = (int) $pdo->query('SELECT COUNT(*) FROM grooflow_turnos_catalog')->fetchColumn();
    if ($count === 0) {
        $ins = $pdo->prepare('INSERT INTO grooflow_turnos_catalog (codigo, nombre, horario, sort_order) VALUES (?, ?, ?, ?)');
        $ins->execute(['MEM01', 'Turno día', '08:30-19:30', 10]);
        $ins->execute(['BASE', 'Turno base', '08:00-18:00', 20]);
        $ins->execute(['NOCHE', 'Turno noche', '20:00-08:00', 30]);
    }
}


function grooflow_rrhh_ensure_indexes(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $wanted = [
        'idx_gf_buk_emp_missing' => 'missing_from_source',
        'idx_gf_buk_emp_term' => 'is_terminated',
        'idx_gf_buk_emp_enriched' => 'asistencia_enriched',
        'idx_gf_buk_emp_updated' => 'last_updated_at',
        'idx_gf_buk_emp_active_term' => 'is_active, is_terminated',
    ];
    $existing = [];
    try {
        $rows = $pdo->query("SHOW INDEX FROM grooflow_buk_empleados")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $existing[(string) ($r['Key_name'] ?? '')] = true;
        }
    } catch (Throwable $e) {
        return;
    }
    foreach ($wanted as $name => $cols) {
        if (isset($existing[$name])) {
            continue;
        }
        try {
            $pdo->exec("ALTER TABLE grooflow_buk_empleados ADD INDEX {$name} ({$cols})");
        } catch (Throwable $e) {
            // ignore race / already exists
        }
    }
}

function grooflow_rrhh_terminated(string $status): bool
{
    $s = mb_strtolower(trim($status));

    return in_array($s, ['inactivo', 'desvinculado', 'terminated', 'inactive', 'baja'], true);
}

function grooflow_rrhh_str(mixed $v): string
{
    if ($v === null || $v === '') {
        return '';
    }
    if (is_bool($v)) {
        return $v ? '1' : '0';
    }
    if (is_scalar($v)) {
        return trim((string) $v);
    }
    if (is_array($v)) {
        foreach (['name', 'nombre', 'label', 'code', 'codigo', 'id'] as $k) {
            if (isset($v[$k]) && is_scalar($v[$k])) {
                return trim((string) $v[$k]);
            }
        }
    }

    return '';
}

/** DNI/RUC/etc. canónico para cruce Buk ↔ Gestión ↔ Asistencia. */
function grooflow_rrhh_doc_key(?string $documentNumber): string
{
    if ($documentNumber === null || $documentNumber === '') {
        return '';
    }
    $digits = preg_replace('/\D+/', '', $documentNumber);

    return is_string($digits) ? $digits : '';
}

/**
 * Estado de identidad del colaborador maestro.
 * linked | pending_access | terminated_still_active | terminated | unmatched_doc | unmatched
 *
 * @param array<string, mixed> $row fila BD o employee app
 */
function grooflow_rrhh_identity_status(array $row): string
{
    $linked = $row['linkedUsuarioId'] ?? $row['linked_usuario_id'] ?? null;
    $linkedStr = $linked !== null && $linked !== '' ? (string) $linked : '';
    $isTerminated = array_key_exists('isTerminated', $row)
        ? (bool) $row['isTerminated']
        : ((int) ($row['is_terminated'] ?? 0) === 1 || grooflow_rrhh_terminated((string) ($row['status'] ?? '')));
    $doc = grooflow_rrhh_doc_key(
        isset($row['documentNumber']) ? (string) $row['documentNumber'] : (isset($row['document_number']) ? (string) $row['document_number'] : null)
    );

    if ($isTerminated) {
        return $linkedStr !== '' ? 'terminated_still_active' : 'terminated';
    }
    if ($linkedStr !== '') {
        return 'linked';
    }
    if ($doc === '') {
        return 'unmatched_doc';
    }

    return 'pending_access';
}

/** @param array<string, mixed> $raw */
function grooflow_rrhh_normalize_buk_pe_employee(array $raw): array
{
    $currentJob = is_array($raw['current_job'] ?? null) ? $raw['current_job'] : [];
    $role = is_array($currentJob['role'] ?? null) ? $currentJob['role'] : [];
    $roleFamily = is_array($role['role_family'] ?? null) ? $role['role_family'] : [];
    $status = grooflow_rrhh_str($raw['status'] ?? 'desconocido') ?: 'desconocido';
    $endDate = grooflow_rrhh_str($currentJob['end_date'] ?? $raw['active_until'] ?? '');
    $fullName = grooflow_rrhh_str($raw['full_name'] ?? $raw['first_name'] ?? 'Sin nombre') ?: 'Sin nombre';

    return [
        'bukId' => (int) ($raw['id'] ?? 0),
        'personId' => isset($raw['person_id']) ? (int) $raw['person_id'] : null,
        'fullName' => $fullName,
        'firstName' => grooflow_rrhh_str($raw['first_name'] ?? '') ?: null,
        'surname' => grooflow_rrhh_str($raw['surname'] ?? '') ?: null,
        'documentType' => grooflow_rrhh_str($raw['document_type'] ?? '') ?: null,
        'documentNumber' => grooflow_rrhh_str($raw['document_number'] ?? '') ?: null,
        'email' => grooflow_rrhh_str($raw['email'] ?? '') ?: null,
        'personalEmail' => grooflow_rrhh_str($raw['personal_email'] ?? '') ?: null,
        'phone' => grooflow_rrhh_str($raw['phone'] ?? $raw['office_phone'] ?? '') ?: null,
        'status' => $status,
        'isActive' => ! grooflow_rrhh_terminated($status),
        'isTerminated' => grooflow_rrhh_terminated($status),
        'cargo' => grooflow_rrhh_str($role['name'] ?? '') ?: null,
        'cargoCode' => grooflow_rrhh_str($role['code'] ?? '') ?: null,
        'area' => grooflow_rrhh_str($roleFamily['name'] ?? '') ?: null,
        'sede' => grooflow_rrhh_str($currentJob['recinto_primario'] ?? $raw['location_id'] ?? '') ?: null,
        'contractType' => grooflow_rrhh_str($currentJob['contract_type'] ?? '') ?: null,
        'startDate' => grooflow_rrhh_str($currentJob['start_date'] ?? $raw['active_since'] ?? '') ?: null,
        'endDate' => $endDate !== '' ? $endDate : null,
        'raw' => $raw,
    ];
}

/** @param array<string, mixed> $emp */
function grooflow_rrhh_employee_hash(array $emp): string
{
    // Incluye raw completo para que cualquier cambio de la API actualice BD (payload).
    $slice = [
        'fields' => $emp,
        'raw' => $emp['raw'] ?? null,
        'asistencia' => [
            'areaAsistencia' => $emp['areaAsistencia'] ?? null,
            'especialidad' => $emp['especialidad'] ?? null,
            'supervisor' => $emp['supervisor'] ?? null,
            'turnoAsistencia' => $emp['turnoAsistencia'] ?? null,
            'codigoTurno' => $emp['codigoTurno'] ?? null,
            'turnoHorario' => $emp['turnoHorario'] ?? null,
            'recintoNombre' => $emp['recintoNombre'] ?? null,
            'recintoCodigo' => $emp['recintoCodigo'] ?? null,
            'ultimaMarcacionEntrada' => $emp['ultimaMarcacionEntrada'] ?? null,
            'ultimaMarcacionSalida' => $emp['ultimaMarcacionSalida'] ?? null,
            'ultimaAsistenciaDia' => $emp['ultimaAsistenciaDia'] ?? null,
            'asistenciaEnriched' => ! empty($emp['asistenciaEnriched']),
        ],
    ];
    unset(
        $slice['fields']['raw'],
        $slice['fields']['firstSyncedAt'],
        $slice['fields']['lastUpdatedAt'],
        $slice['fields']['contentHash'],
        $slice['fields']['asistenciaSyncedAt']
    );

    return hash('sha256', json_encode($slice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
}

/**
 * @param array<string, mixed> $row
 * @param array{includeRaw?:bool} $opts
 */
function grooflow_rrhh_row_to_app(array $row, array $opts = []): array
{
    $includeRaw = ! empty($opts['includeRaw']);
    $payload = [];
    if ($includeRaw && ! empty($row['payload'])) {
        $decoded = grooflow_json_decode(is_string($row['payload']) ? $row['payload'] : null);
        $payload = is_array($decoded) ? $decoded : [];
    }

    $out = [
        'bukId' => (int) $row['buk_id'],
        'personId' => $row['person_id'] !== null ? (int) $row['person_id'] : null,
        'fullName' => (string) $row['full_name'],
        'firstName' => $row['first_name'] ?? null,
        'surname' => $row['surname'] ?? null,
        'documentType' => $row['document_type'] ?? null,
        'documentNumber' => $row['document_number'] ?? null,
        'email' => $row['email'] ?? null,
        'personalEmail' => $row['personal_email'] ?? null,
        'phone' => $row['phone'] ?? null,
        'status' => (string) ($row['status'] ?? ''),
        'isActive' => (int) ($row['is_active'] ?? 0) === 1,
        'isTerminated' => (int) ($row['is_terminated'] ?? 0) === 1,
        'cargo' => $row['cargo'] ?? null,
        'cargoCode' => $row['cargo_code'] ?? null,
        'area' => $row['area'] ?? null,
        'sede' => $row['sede'] ?? null,
        'contractType' => $row['contract_type'] ?? null,
        'startDate' => $row['start_date'] ?? null,
        'endDate' => $row['end_date'] ?? null,
        'areaAsistencia' => $row['area_asistencia'] ?? null,
        'especialidad' => $row['especialidad'] ?? null,
        'supervisor' => $row['supervisor'] ?? null,
        'turnoAsistencia' => $row['turno'] ?? null,
        'shiftLabel' => $row['turno'] ?? null,
        'turnoHorario' => $row['turno_horario'] ?? null,
        'codigoTurno' => $row['turno_codigo'] ?? null,
        'recintoNombre' => $row['recinto_nombre'] ?? null,
        'recintoCodigo' => $row['recinto_codigo'] ?? null,
        'ultimaMarcacionEntrada' => $row['ultima_marcacion_entrada'] ?? null,
        'ultimaMarcacionSalida' => $row['ultima_marcacion_salida'] ?? null,
        'ultimaAsistenciaDia' => $row['ultima_asistencia_dia'] ?? null,
        'asistenciaEnriched' => (int) ($row['asistencia_enriched'] ?? 0) === 1,
        'contentHash' => $row['content_hash'] ?? null,
        'missingFromSource' => (int) ($row['missing_from_source'] ?? 0) === 1,
        'linkedUsuarioId' => $row['linked_usuario_id'] !== null ? (string) $row['linked_usuario_id'] : null,
        'documentKey' => grooflow_rrhh_doc_key(isset($row['document_number']) ? (string) $row['document_number'] : null),
        'firstSyncedAt' => ! empty($row['first_synced_at']) ? date('c', strtotime((string) $row['first_synced_at'])) : null,
        'lastUpdatedAt' => ! empty($row['last_updated_at']) ? date('c', strtotime((string) $row['last_updated_at'])) : null,
    ];
    $out['identityStatus'] = grooflow_rrhh_identity_status($out);
    if ($includeRaw) {
        $out['raw'] = $payload['raw'] ?? null;
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $employees
 * @return array{added:int,updated:int,unchanged:int,removedFromSource:int,total:int}
 */
function grooflow_rrhh_upsert_employees(PDO $pdo, array $employees, bool $markMissing = true): array
{
    grooflow_rrhh_ensure_schema($pdo);
    $added = 0;
    $updated = 0;
    $unchanged = 0;
    $seen = [];

    $existingById = [];
    try {
        foreach ($pdo->query('SELECT buk_id, content_hash, first_synced_at FROM grooflow_buk_empleados')->fetchAll(PDO::FETCH_ASSOC) as $er) {
            $existingById[(int) $er['buk_id']] = $er;
        }
    } catch (Throwable $e) {
        $existingById = [];
    }
    $touch = $pdo->prepare('UPDATE grooflow_buk_empleados SET missing_from_source = 0 WHERE buk_id = ?');
    $ins = $pdo->prepare('
        INSERT INTO grooflow_buk_empleados (
            buk_id, person_id, full_name, first_name, surname, document_type, document_number,
            email, personal_email, phone, status, is_active, is_terminated, cargo, cargo_code,
            area, sede, contract_type, start_date, end_date, area_asistencia, especialidad,
            supervisor, turno, turno_horario, turno_codigo, recinto_nombre, recinto_codigo,
            ultima_marcacion_entrada, ultima_marcacion_salida, ultima_asistencia_dia,
            asistencia_enriched, content_hash, missing_from_source, payload, first_synced_at, last_updated_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            person_id = VALUES(person_id),
            full_name = VALUES(full_name),
            first_name = VALUES(first_name),
            surname = VALUES(surname),
            document_type = VALUES(document_type),
            document_number = VALUES(document_number),
            email = VALUES(email),
            personal_email = VALUES(personal_email),
            phone = VALUES(phone),
            status = VALUES(status),
            is_active = VALUES(is_active),
            is_terminated = VALUES(is_terminated),
            cargo = VALUES(cargo),
            cargo_code = VALUES(cargo_code),
            area = VALUES(area),
            sede = VALUES(sede),
            contract_type = VALUES(contract_type),
            start_date = VALUES(start_date),
            end_date = VALUES(end_date),
            area_asistencia = VALUES(area_asistencia),
            especialidad = VALUES(especialidad),
            supervisor = VALUES(supervisor),
            turno = VALUES(turno),
            turno_horario = VALUES(turno_horario),
            turno_codigo = VALUES(turno_codigo),
            recinto_nombre = VALUES(recinto_nombre),
            recinto_codigo = VALUES(recinto_codigo),
            ultima_marcacion_entrada = VALUES(ultima_marcacion_entrada),
            ultima_marcacion_salida = VALUES(ultima_marcacion_salida),
            ultima_asistencia_dia = VALUES(ultima_asistencia_dia),
            asistencia_enriched = VALUES(asistencia_enriched),
            content_hash = VALUES(content_hash),
            missing_from_source = 0,
            payload = VALUES(payload),
            last_updated_at = VALUES(last_updated_at)
    ');

    $now = date('Y-m-d H:i:s');
    foreach ($employees as $emp) {
        if (! is_array($emp)) {
            continue;
        }
        $bukId = (int) ($emp['bukId'] ?? 0);
        if ($bukId <= 0) {
            continue;
        }
        $seen[$bukId] = true;
        $hash = grooflow_rrhh_employee_hash($emp);
        $existing = $existingById[$bukId] ?? null;
        if ($existing && (string) ($existing['content_hash'] ?? '') === $hash) {
            $unchanged++;
            $touch->execute([$bukId]);
            continue;
        }
        if ($existing) {
            $updated++;
            $first = (string) ($existing['first_synced_at'] ?? $now);
        } else {
            $added++;
            $first = $now;
        }

        $startDate = ! empty($emp['startDate']) ? substr((string) $emp['startDate'], 0, 10) : null;
        $endDate = ! empty($emp['endDate']) ? substr((string) $emp['endDate'], 0, 10) : null;

        $ins->execute([
            $bukId,
            isset($emp['personId']) ? (int) $emp['personId'] : null,
            (string) ($emp['fullName'] ?? ''),
            $emp['firstName'] ?? null,
            $emp['surname'] ?? null,
            $emp['documentType'] ?? null,
            $emp['documentNumber'] ?? null,
            $emp['email'] ?? null,
            $emp['personalEmail'] ?? null,
            $emp['phone'] ?? null,
            (string) ($emp['status'] ?? ''),
            ! empty($emp['isActive']) ? 1 : 0,
            ! empty($emp['isTerminated']) ? 1 : 0,
            $emp['cargo'] ?? null,
            $emp['cargoCode'] ?? null,
            $emp['area'] ?? ($emp['areaAsistencia'] ?? null),
            $emp['sede'] ?? ($emp['recintoNombre'] ?? null),
            $emp['contractType'] ?? null,
            $startDate,
            $endDate,
            $emp['areaAsistencia'] ?? null,
            $emp['especialidad'] ?? null,
            $emp['supervisor'] ?? null,
            $emp['turnoAsistencia'] ?? ($emp['shiftLabel'] ?? null),
            $emp['turnoHorario'] ?? ($emp['shiftSchedule'] ?? null),
            $emp['codigoTurno'] ?? ($emp['shiftCode'] ?? null),
            $emp['recintoNombre'] ?? null,
            $emp['recintoCodigo'] ?? null,
            $emp['ultimaMarcacionEntrada'] ?? null,
            $emp['ultimaMarcacionSalida'] ?? null,
            $emp['ultimaAsistenciaDia'] ?? null,
            ! empty($emp['asistenciaEnriched']) ? 1 : 0,
            $hash,
            0,
            grooflow_json_encode([
                // Respuesta completa de Buk.pe (todos los campos de la API).
                'raw' => $emp['raw'] ?? null,
                // Snapshot de enriquecimiento Ctrlit (asistencia).
                'asistencia' => [
                    'areaAsistencia' => $emp['areaAsistencia'] ?? null,
                    'especialidad' => $emp['especialidad'] ?? null,
                    'supervisor' => $emp['supervisor'] ?? null,
                    'turnoAsistencia' => $emp['turnoAsistencia'] ?? null,
                    'codigoTurno' => $emp['codigoTurno'] ?? null,
                    'turnoHorario' => $emp['turnoHorario'] ?? null,
                    'recintoNombre' => $emp['recintoNombre'] ?? null,
                    'recintoCodigo' => $emp['recintoCodigo'] ?? null,
                    'ultimaMarcacionEntrada' => $emp['ultimaMarcacionEntrada'] ?? null,
                    'ultimaMarcacionSalida' => $emp['ultimaMarcacionSalida'] ?? null,
                    'ultimaAsistenciaDia' => $emp['ultimaAsistenciaDia'] ?? null,
                    'asistenciaEnriched' => ! empty($emp['asistenciaEnriched']),
                    'asistenciaSyncedAt' => $emp['asistenciaSyncedAt'] ?? null,
                ],
                'syncedAt' => date('c'),
            ]),
            $first,
            $now,
        ]);
    }

    $removed = 0;
    if ($markMissing && $seen !== []) {
        $ids = array_keys($seen);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE grooflow_buk_empleados SET missing_from_source = 1 WHERE buk_id NOT IN ({$placeholders})");
        $stmt->execute($ids);
        $removed = $stmt->rowCount();
    }

    return [
        'added' => $added,
        'updated' => $updated,
        'unchanged' => $unchanged,
        'removedFromSource' => $removed,
        'total' => count($seen),
    ];
}

/**
 * Enriquece empleados con asistencia del día (Ctrlit).
 *
 * @param list<array<string, mixed>> $employees
 * @param list<array<string, mixed>> $asistencia
 * @return array{employees:list<array<string,mixed>>,matched:int}
 */
function grooflow_rrhh_enrich_with_asistencia(array $employees, array $asistencia): array
{
    $byDni = [];
    foreach ($asistencia as $row) {
        if (! is_array($row)) {
            continue;
        }
        $dni = grooflow_buk_normalize_dni((string) ($row['rut_trabajador'] ?? ''));
        if ($dni === '') {
            continue;
        }
        $byDni[$dni] = $row;
    }
    $matched = 0;
    $out = [];
    foreach ($employees as $emp) {
        $dni = grooflow_buk_normalize_dni((string) ($emp['documentNumber'] ?? ''));
        if ($dni !== '' && isset($byDni[$dni])) {
            $a = $byDni[$dni];
            $emp['areaAsistencia'] = trim((string) ($a['area'] ?? '')) ?: ($emp['areaAsistencia'] ?? null);
            $emp['especialidad'] = trim((string) ($a['especialidad'] ?? '')) ?: ($emp['especialidad'] ?? null);
            $emp['supervisor'] = trim((string) ($a['supervisor'] ?? '')) ?: null;
            $emp['turnoAsistencia'] = trim((string) ($a['turno'] ?? '')) ?: null;
            $emp['codigoTurno'] = trim((string) ($a['codigo_turno'] ?? '')) ?: null;
            $emp['turnoHorario'] = trim((string) ($a['horario'] ?? $a['turno_horario'] ?? $a['turno'] ?? '')) ?: null;
            $emp['recintoNombre'] = trim((string) ($a['nombre_recinto'] ?? '')) ?: null;
            $emp['recintoCodigo'] = trim((string) ($a['codigo_recinto'] ?? '')) ?: null;
            $emp['ultimaMarcacionEntrada'] = trim((string) ($a['entrada_format'] ?? $a['entrada'] ?? '')) ?: null;
            $emp['ultimaMarcacionSalida'] = trim((string) ($a['salida_format'] ?? $a['salida'] ?? '')) ?: null;
            $emp['ultimaAsistenciaDia'] = trim((string) ($a['dia_entrada'] ?? '')) ?: null;
            $emp['asistenciaEnriched'] = true;
            $emp['asistenciaSyncedAt'] = date('c');
            if (empty($emp['area']) && ! empty($emp['areaAsistencia'])) {
                $emp['area'] = $emp['areaAsistencia'];
            }
            if (empty($emp['cargo']) && ! empty($emp['especialidad'])) {
                $emp['cargo'] = $emp['especialidad'];
            }
            $matched++;
        }
        $out[] = $emp;
    }

    return ['employees' => $out, 'matched' => $matched];
}

/** @return array<string, mixed> */
function grooflow_rrhh_sync_from_apis(PDO $pdo, array $options = []): array
{
    grooflow_rrhh_ensure_schema($pdo);
    $started = (int) round(microtime(true) * 1000);

    $system = grooflow_kv_get($pdo, 'settings:system');
    $system = is_array($system) ? $system : [];
    $bukPe = is_array($system['bukPe'] ?? null) ? $system['bukPe'] : [];
    $baseUrl = grooflow_sanitize_buk_pe_base_url((string) ($options['baseUrl'] ?? $bukPe['apiBaseUrl'] ?? ''));
    $apiToken = grooflow_resolve_buk_pe_api_token($pdo, (string) ($options['apiToken'] ?? $bukPe['apiToken'] ?? ''));
    $includeAsistencia = ($options['includeAsistencia'] ?? true) !== false;
    $maxPages = max(1, min(100, (int) ($options['maxPages'] ?? 100)));

    $all = [];
    $first = grooflow_buk_pe_fetch_page($baseUrl, $apiToken, 1, 100, 120);
    if ($first['status'] < 200 || $first['status'] >= 300) {
        throw new RuntimeException('Buk.pe HTTP ' . $first['status'] . ' — ' . $first['triedUrl']);
    }
    $all = array_merge($all, $first['records']);
    $apiTotalPages = max(1, (int) ($first['totalPages'] ?? 1));
    $pagesToFetch = min($apiTotalPages, $maxPages);
    $pagesFetched = 1;
    $pageErrors = 0;
    for ($p = 2; $p <= $pagesToFetch; $p++) {
        $next = grooflow_buk_pe_fetch_page($baseUrl, $apiToken, $p, 100, 120);
        if ($next['status'] < 200 || $next['status'] >= 300) {
            $pageErrors++;
            break;
        }
        $all = array_merge($all, $next['records']);
        $pagesFetched++;
    }

    $truncated = $apiTotalPages > $maxPages || $pageErrors > 0 || $pagesFetched < $pagesToFetch;
    $complete = ! $truncated;

    $employees = [];
    foreach ($all as $raw) {
        if (! is_array($raw)) {
            continue;
        }
        $emp = grooflow_rrhh_normalize_buk_pe_employee($raw);
        if ((int) $emp['bukId'] > 0) {
            $employees[] = $emp;
        }
    }

    $asistenciaMatched = 0;
    if ($includeAsistencia) {
        $asistSettings = grooflow_asistencia_get_settings($pdo);
        $buk = is_array($asistSettings['buk'] ?? null) ? $asistSettings['buk'] : [];
        $asistToken = grooflow_normalize_buk_token((string) ($buk['apiToken'] ?? ''));
        if ($asistToken !== '' && ! grooflow_buk_token_is_redacted($asistToken) && ! empty($buk['enabled'])) {
            try {
                $asistBase = grooflow_sanitize_buk_base_url((string) ($buk['apiBaseUrl'] ?? ''));
                $records = grooflow_buk_fetch_asistencia_today($asistBase, $asistToken, 20);
                $enriched = grooflow_rrhh_enrich_with_asistencia($employees, $records);
                $employees = $enriched['employees'];
                $asistenciaMatched = $enriched['matched'];
            } catch (Throwable $e) {
                // Sync Buk.pe no falla por asistencia.
            }
        }
    }

    // Nunca marcar ausentes si el fetch fue incompleto/truncado.
    $stats = grooflow_rrhh_upsert_employees($pdo, $employees, $complete);
    grooflow_rrhh_seed_catalogs_from_employees($pdo);

    // Preferencias UI (sin empleados).
    $meta = grooflow_kv_get($pdo, 'settings:rrhh');
    $meta = is_array($meta) ? $meta : [];
    unset($meta['employees']);
    $links = is_array($meta['userLinks'] ?? null) ? $meta['userLinks'] : [];
    $links = grooflow_rrhh_auto_link_users($pdo, $links);
    $meta['userLinks'] = $links;
    grooflow_rrhh_apply_user_links($pdo, $links);

    $pendingSnap = grooflow_rrhh_build_pending_access_snapshot($pdo, $links);
    $meta['pendingAccessCount'] = $pendingSnap['count'];
    $meta['pendingAccess'] = $pendingSnap['items'];
    $meta['pendingAccessAt'] = date('c');

    $terminations = null;
    $autoDisable = ($options['autoDisableOnTermination'] ?? $meta['autoDisableOnTermination'] ?? true) !== false;
    if ($autoDisable) {
        $terminations = grooflow_rrhh_apply_terminations($pdo, [
            'dryRun' => false,
            'skipMetaWrite' => true,
            'userLinks' => $links,
        ]);
        $meta['lastTerminationsApply'] = $terminations;
    }

    $at = date('c');
    $termMsg = '';
    if (is_array($terminations)) {
        $td = (int) ($terminations['usersDisabled'] ?? 0);
        $sr = (int) ($terminations['staffRemoved'] ?? 0);
        if ($td > 0 || $sr > 0) {
            $termMsg = sprintf(' · bajas: %d acceso, %d organigrama', $td, $sr);
        }
    }
    $message = sprintf(
        'RRHH sync: +%d · ~%d · =%d · ausentes %d · total %d · pendientes %d%s%s%s',
        $stats['added'],
        $stats['updated'],
        $stats['unchanged'],
        $stats['removedFromSource'],
        $stats['total'],
        $pendingSnap['count'],
        $asistenciaMatched > 0 ? (" · asistencia {$asistenciaMatched}") : '',
        $termMsg,
        $truncated ? ' · INCOMPLETO (no se marcaron ausentes)' : ''
    );
    $meta['lastSyncAt'] = $at;
    $meta['lastSyncOk'] = true;
    $meta['lastSyncMessage'] = $message;
    $meta['lastSyncStats'] = $stats;
    $meta['lastSyncTruncated'] = $truncated;
    $meta['lastSyncPages'] = [
        'fetched' => $pagesFetched,
        'apiTotal' => $apiTotalPages,
        'maxPages' => $maxPages,
        'pageErrors' => $pageErrors,
    ];
    $log = is_array($meta['syncLog'] ?? null) ? $meta['syncLog'] : [];
    array_unshift($log, [
        'at' => $at,
        'ok' => true,
        'message' => $message,
        'employeesLoaded' => $stats['total'],
        'stats' => $stats,
        'asistenciaMatched' => $asistenciaMatched,
        'pendingAccess' => $pendingSnap['count'],
        'terminations' => $terminations,
        'truncated' => $truncated,
        'durationMs' => (int) round(microtime(true) * 1000) - $started,
    ]);
    $meta['syncLog'] = array_slice($log, 0, 30);
    grooflow_kv_set($pdo, 'settings:rrhh', $meta);

    return [
        'stats' => $stats,
        'asistenciaMatched' => $asistenciaMatched,
        'pendingAccess' => $pendingSnap['count'],
        'terminations' => $terminations,
        'message' => $message,
        'duration_ms' => (int) round(microtime(true) * 1000) - $started,
        'synced_at' => $at,
        'truncated' => $truncated,
        'pages' => [
            'fetched' => $pagesFetched,
            'apiTotal' => $apiTotalPages,
            'maxPages' => $maxPages,
        ],
    ];
}

function grooflow_rrhh_seed_catalogs_from_employees(PDO $pdo): void
{
    grooflow_rrhh_ensure_schema($pdo);
    if (! function_exists('areas_admin_ensure_table')) {
        $root = defined('CRON_ROOT') ? CRON_ROOT : dirname(__DIR__, 2);
        require_once $root . '/backend/lib/areas_admin_api.php';
    }
    areas_admin_ensure_table($pdo);

    $areas = $pdo->query("
        SELECT DISTINCT TRIM(area) AS v FROM grooflow_buk_empleados
        WHERE area IS NOT NULL AND TRIM(area) <> '' AND is_active = 1
    ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $insArea = $pdo->prepare('INSERT IGNORE INTO app_areas_admin (nombre, descripcion, sort_order) VALUES (?, ?, ?)');
    $i = 100;
    foreach ($areas as $area) {
        $insArea->execute([(string) $area, 'Importado desde Buk RRHH', $i]);
        $i += 10;
    }

    $puestos = $pdo->query("
        SELECT DISTINCT TRIM(cargo) AS v FROM grooflow_buk_empleados
        WHERE cargo IS NOT NULL AND TRIM(cargo) <> '' AND is_active = 1
    ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $insPuesto = $pdo->prepare('INSERT IGNORE INTO grooflow_puestos (nombre, descripcion, sort_order) VALUES (?, ?, ?)');
    $i = 100;
    foreach ($puestos as $p) {
        $insPuesto->execute([(string) $p, 'Importado desde Buk RRHH', $i]);
        $i += 10;
    }

    $turnos = $pdo->query("
        SELECT DISTINCT TRIM(turno_codigo) AS codigo, TRIM(COALESCE(turno, '')) AS nombre, TRIM(COALESCE(turno_horario, '')) AS horario
        FROM grooflow_buk_empleados
        WHERE turno_codigo IS NOT NULL AND TRIM(turno_codigo) <> ''
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $insTurno = $pdo->prepare('
        INSERT INTO grooflow_turnos_catalog (codigo, nombre, horario, sort_order)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            nombre = IF(VALUES(nombre) <> "", VALUES(nombre), nombre),
            horario = IF(VALUES(horario) <> "", VALUES(horario), horario)
    ');
    $i = 100;
    foreach ($turnos as $t) {
        $codigo = (string) ($t['codigo'] ?? '');
        if ($codigo === '') {
            continue;
        }
        $nombre = (string) ($t['nombre'] ?? '');
        if ($nombre === '') {
            $nombre = $codigo;
        }
        $insTurno->execute([$codigo, $nombre, (string) ($t['horario'] ?? ''), $i]);
        $i += 10;
    }
}

/**
 * @return array{
 *   total:int,activos:int,bajas:int,enriched:int,missing:int,
 *   by_area:list<array{area:string,count:int}>,
 *   by_cargo:list<array{cargo:string,count:int}>,
 *   by_recinto:list<array{recinto:string,count:int}>
 * }
 */
function grooflow_rrhh_stats(PDO $pdo): array
{
    grooflow_rrhh_ensure_schema($pdo);
    $row = $pdo->query('
        SELECT
            COUNT(*) AS total,
            SUM(is_active = 1) AS activos,
            SUM(is_terminated = 1 OR is_active = 0) AS bajas,
            SUM(asistencia_enriched = 1) AS enriched,
            SUM(missing_from_source = 1) AS missing
        FROM grooflow_buk_empleados
    ')->fetch(PDO::FETCH_ASSOC) ?: [];

    $top = static function (PDO $pdo, string $expr, string $key): array {
        $sql = "
            SELECT COALESCE(NULLIF(TRIM({$expr}), ''), 'Sin dato') AS label, COUNT(*) AS cnt
            FROM grooflow_buk_empleados
            WHERE is_active = 1
            GROUP BY label
            ORDER BY cnt DESC
            LIMIT 8
        ";
        $out = [];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [$key => (string) ($r['label'] ?? 'Sin dato'), 'count' => (int) ($r['cnt'] ?? 0)];
        }

        return $out;
    };

    $activos = (int) ($row['activos'] ?? 0);
    $linkedCol = (int) ($pdo->query('SELECT COUNT(*) FROM grooflow_buk_empleados WHERE linked_usuario_id IS NOT NULL AND is_active = 1')->fetchColumn() ?: 0);
    $meta = grooflow_kv_get($pdo, 'settings:rrhh');
    $meta = is_array($meta) ? $meta : [];
    $links = is_array($meta['userLinks'] ?? null) ? $meta['userLinks'] : [];
    $linkedBuk = [];
    foreach ($links as $link) {
        if (! is_array($link)) {
            continue;
        }
        $bid = (int) ($link['bukEmployeeId'] ?? 0);
        if ($bid > 0) {
            $linkedBuk[$bid] = true;
        }
    }
    $linkedFromKv = 0;
    if ($linkedBuk !== []) {
        $ids = array_keys($linkedBuk);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT COUNT(*) FROM grooflow_buk_empleados WHERE is_active = 1 AND buk_id IN ({$ph})");
        $st->execute($ids);
        $linkedFromKv = (int) $st->fetchColumn();
    }
    $linkedActivos = max($linkedCol, $linkedFromKv);

    return [
        'total' => (int) ($row['total'] ?? 0),
        'activos' => $activos,
        'bajas' => (int) ($row['bajas'] ?? 0),
        'enriched' => (int) ($row['enriched'] ?? 0),
        'missing' => (int) ($row['missing'] ?? 0),
        'linked_activos' => $linkedActivos,
        'unlinked_activos' => max(0, $activos - $linkedActivos),
        'by_area' => $top($pdo, 'area', 'area'),
        'by_cargo' => $top($pdo, 'cargo', 'cargo'),
        'by_recinto' => $top($pdo, 'COALESCE(recinto_nombre, sede)', 'recinto'),
    ];
}

/**
 * Listado paginado para la UI (estilo datatable).
 *
 * @return array{items:list<array<string,mixed>>,total:int,filtered:int,page:int,pageSize:int}
 */
function grooflow_rrhh_list_employees(PDO $pdo, array $query): array
{
    grooflow_rrhh_ensure_schema($pdo);
    $page = max(1, (int) ($query['page'] ?? 1));
    $pageSize = max(5, min(100, (int) ($query['pageSize'] ?? $query['length'] ?? 15)));
    $search = trim((string) ($query['search'] ?? $query['q'] ?? ''));
    $tab = trim((string) ($query['tab'] ?? 'activos')); // activos|bajas|all
    $orderBy = (string) ($query['orderBy'] ?? 'full_name');
    $orderDir = strtoupper((string) ($query['orderDir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
    $allowedOrder = [
        'full_name' => 'full_name',
        'document_number' => 'document_number',
        'email' => 'email',
        'cargo' => 'cargo',
        'area' => 'area',
        'sede' => 'sede',
        'status' => 'status',
        'turno' => 'turno',
        'last_updated_at' => 'last_updated_at',
    ];
    $orderCol = $allowedOrder[$orderBy] ?? 'full_name';

    $where = ['1=1'];
    $params = [];
    if ($tab === 'activos') {
        $where[] = 'is_active = 1';
    } elseif ($tab === 'bajas') {
        $where[] = '(is_terminated = 1 OR is_active = 0)';
    }
    if ($search !== '') {
        $where[] = '(full_name LIKE ? OR email LIKE ? OR document_number LIKE ? OR cargo LIKE ? OR area LIKE ? OR sede LIKE ? OR turno LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);

    $total = (int) $pdo->query('SELECT COUNT(*) FROM grooflow_buk_empleados')->fetchColumn();
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM grooflow_buk_empleados WHERE {$whereSql}");
    $countStmt->execute($params);
    $filtered = (int) $countStmt->fetchColumn();

    $includeRaw = ! empty($query['includeRaw']) || ! empty($query['include_raw']);
    $offset = ($page - 1) * $pageSize;
    $cols = $includeRaw
        ? '*'
        : 'buk_id, person_id, full_name, first_name, surname, document_type, document_number, email, personal_email, phone, status, is_active, is_terminated, cargo, cargo_code, area, sede, contract_type, start_date, end_date, area_asistencia, especialidad, supervisor, turno, turno_horario, turno_codigo, recinto_nombre, recinto_codigo, ultima_marcacion_entrada, ultima_marcacion_salida, ultima_asistencia_dia, asistencia_enriched, content_hash, missing_from_source, linked_usuario_id, first_synced_at, last_updated_at';
    $sql = "
        SELECT {$cols} FROM grooflow_buk_empleados
        WHERE {$whereSql}
        ORDER BY {$orderCol} {$orderDir}, buk_id ASC
        LIMIT {$pageSize} OFFSET {$offset}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = grooflow_rrhh_row_to_app($row, ['includeRaw' => $includeRaw]);
    }

    // Clamp página fuera de rango.
    $totalPages = max(1, (int) ceil($filtered / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    return [
        'items' => $items,
        'total' => $total,
        'filtered' => $filtered,
        'page' => $page,
        'pageSize' => $pageSize,
        'totalPages' => $totalPages,
    ];
}

function grooflow_rrhh_export_excel(PDO $pdo, array $query): void
{
    $root = defined('CRON_ROOT') ? CRON_ROOT : dirname(__DIR__, 2);
    require_once $root . '/backend/lib/excel_xlsx.php';
    $out = excel_export_begin('rrhh-colaboradores.xlsx');
    fputcsv($out, [
        'buk_id', 'nombre', 'documento', 'email', 'cargo', 'area', 'sede', 'estado',
        'turno', 'horario', 'codigo_turno', 'especialidad', 'supervisor', 'recinto',
    ]);

    $page = 1;
    $pageSize = 500;
    $exported = 0;
    while (true) {
        $chunk = grooflow_rrhh_list_employees($pdo, array_merge($query, [
            'page' => $page,
            'pageSize' => $pageSize,
            'includeRaw' => 0,
        ]));
        $items = $chunk['items'] ?? [];
        if ($items === []) {
            break;
        }
        foreach ($items as $e) {
            fputcsv($out, [
                $e['bukId'] ?? '',
                $e['fullName'] ?? '',
                $e['documentNumber'] ?? '',
                $e['email'] ?? '',
                $e['cargo'] ?? '',
                $e['area'] ?? '',
                $e['sede'] ?? '',
                $e['status'] ?? '',
                $e['turnoAsistencia'] ?? '',
                $e['turnoHorario'] ?? '',
                $e['codigoTurno'] ?? '',
                $e['especialidad'] ?? '',
                $e['supervisor'] ?? '',
                $e['recintoNombre'] ?? '',
            ]);
            $exported++;
        }
        if ($exported >= (int) ($chunk['filtered'] ?? 0) || count($items) < $pageSize) {
            break;
        }
        $page++;
        if ($page > 500) {
            break;
        }
    }
    excel_export_end($out);
}

// ---- Catálogos CRUD genéricos ----

/** @return list<array<string,mixed>> */
function grooflow_puestos_list(PDO $pdo, bool $onlyActive = true): array
{
    grooflow_rrhh_ensure_schema($pdo);
    $sql = 'SELECT * FROM grooflow_puestos WHERE is_deleted = 0';
    if ($onlyActive) {
        $sql .= " AND estado = 'activo'";
    }
    $sql .= ' ORDER BY sort_order ASC, nombre ASC';

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<string,mixed> */
function grooflow_puestos_save(PDO $pdo, array $data, ?int $id = null): array
{
    grooflow_rrhh_ensure_schema($pdo);
    $nombre = trim((string) ($data['nombre'] ?? ''));
    if ($nombre === '') {
        throw new InvalidArgumentException('El nombre del puesto es obligatorio');
    }
    $descripcion = trim((string) ($data['descripcion'] ?? ''));
    $estado = (($data['estado'] ?? 'activo') === 'inactivo') ? 'inactivo' : 'activo';
    $areaId = isset($data['area_id']) && $data['area_id'] !== '' ? (int) $data['area_id'] : null;
    if ($id) {
        $pdo->prepare('UPDATE grooflow_puestos SET nombre=?, descripcion=?, area_id=?, estado=? WHERE id=? AND is_deleted=0')
            ->execute([$nombre, $descripcion, $areaId, $estado, $id]);
    } else {
        $pdo->prepare('INSERT INTO grooflow_puestos (nombre, descripcion, area_id, estado) VALUES (?,?,?,?)')
            ->execute([$nombre, $descripcion, $areaId, $estado]);
        $id = (int) $pdo->lastInsertId();
    }
    $stmt = $pdo->prepare('SELECT * FROM grooflow_puestos WHERE id = ?');
    $stmt->execute([$id]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function grooflow_puestos_delete(PDO $pdo, int $id): void
{
    grooflow_rrhh_ensure_schema($pdo);
    $pdo->prepare('UPDATE grooflow_puestos SET is_deleted = 1 WHERE id = ?')->execute([$id]);
}

/** @return list<array<string,mixed>> */
function grooflow_turnos_catalog_list(PDO $pdo, bool $onlyActive = true): array
{
    grooflow_rrhh_ensure_schema($pdo);
    $sql = 'SELECT * FROM grooflow_turnos_catalog WHERE is_deleted = 0';
    if ($onlyActive) {
        $sql .= " AND estado = 'activo'";
    }
    $sql .= ' ORDER BY sort_order ASC, nombre ASC';

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<string,mixed> */
function grooflow_turnos_catalog_save(PDO $pdo, array $data, ?int $id = null): array
{
    grooflow_rrhh_ensure_schema($pdo);
    $nombre = trim((string) ($data['nombre'] ?? ''));
    $codigo = trim((string) ($data['codigo'] ?? ''));
    if ($nombre === '') {
        throw new InvalidArgumentException('El nombre del turno es obligatorio');
    }
    if ($codigo === '') {
        $codigo = strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '', $nombre) ?: ('T' . time()));
    }
    $horario = trim((string) ($data['horario'] ?? ''));
    $descripcion = trim((string) ($data['descripcion'] ?? ''));
    $estado = (($data['estado'] ?? 'activo') === 'inactivo') ? 'inactivo' : 'activo';
    if ($id) {
        $pdo->prepare('UPDATE grooflow_turnos_catalog SET codigo=?, nombre=?, horario=?, descripcion=?, estado=? WHERE id=? AND is_deleted=0')
            ->execute([$codigo, $nombre, $horario, $descripcion, $estado, $id]);
    } else {
        $pdo->prepare('INSERT INTO grooflow_turnos_catalog (codigo, nombre, horario, descripcion, estado) VALUES (?,?,?,?,?)')
            ->execute([$codigo, $nombre, $horario, $descripcion, $estado]);
        $id = (int) $pdo->lastInsertId();
    }
    $stmt = $pdo->prepare('SELECT * FROM grooflow_turnos_catalog WHERE id = ?');
    $stmt->execute([$id]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function grooflow_turnos_catalog_delete(PDO $pdo, int $id): void
{
    grooflow_rrhh_ensure_schema($pdo);
    $pdo->prepare('UPDATE grooflow_turnos_catalog SET is_deleted = 1 WHERE id = ?')->execute([$id]);
}

/**
 * @param list<mixed> $links
 */
function grooflow_rrhh_apply_user_links(PDO $pdo, array $links): int
{
    grooflow_rrhh_ensure_schema($pdo);
    $pdo->exec('UPDATE grooflow_buk_empleados SET linked_usuario_id = NULL');
    $upd = $pdo->prepare('UPDATE grooflow_buk_empleados SET linked_usuario_id = ? WHERE buk_id = ?');
    $n = 0;
    foreach ($links as $link) {
        if (! is_array($link)) {
            continue;
        }
        $bukId = (int) ($link['bukEmployeeId'] ?? 0);
        $userId = (int) ($link['userId'] ?? 0);
        if ($bukId <= 0 || $userId <= 0) {
            continue;
        }
        $upd->execute([$userId, $bukId]);
        $n += $upd->rowCount() > 0 ? 1 : 0;
    }

    return $n;
}

/**
 * Completa userLinks por email / DNI contra usuarios GrooFlow.
 *
 * @param list<mixed> $existing
 * @return list<array<string, mixed>>
 */
function grooflow_rrhh_auto_link_users(PDO $pdo, array $existing): array
{
    $byBuk = [];
    foreach ($existing as $link) {
        if (! is_array($link)) {
            continue;
        }
        $bukId = (int) ($link['bukEmployeeId'] ?? 0);
        if ($bukId > 0) {
            $byBuk[$bukId] = $link;
        }
    }
    $users = grooflow_list_users($pdo);
    $byEmail = [];
    $byDoc = [];
    foreach ($users as $u) {
        if (! is_array($u)) {
            continue;
        }
        $uid = (string) ($u['id'] ?? '');
        if ($uid === '') {
            continue;
        }
        $email = strtolower(trim((string) ($u['email'] ?? '')));
        if ($email !== '') {
            $byEmail[$email] = $uid;
        }
        $doc = preg_replace('/\D+/', '', (string) ($u['documentNumber'] ?? $u['dni'] ?? $u['document'] ?? '')) ?? '';
        if ($doc !== '') {
            $byDoc[$doc] = $uid;
        }
    }
    $rows = $pdo->query('SELECT buk_id, email, personal_email, document_number, full_name FROM grooflow_buk_empleados WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $bukId = (int) ($row['buk_id'] ?? 0);
        if ($bukId <= 0 || isset($byBuk[$bukId])) {
            continue;
        }
        $userId = null;
        $method = 'email';
        foreach (['email', 'personal_email'] as $col) {
            $em = strtolower(trim((string) ($row[$col] ?? '')));
            if ($em !== '' && isset($byEmail[$em])) {
                $userId = $byEmail[$em];
                $method = $col === 'personal_email' ? 'personal_email' : 'email';
                break;
            }
        }
        if ($userId === null) {
            $doc = preg_replace('/\D+/', '', (string) ($row['document_number'] ?? '')) ?? '';
            if ($doc !== '' && isset($byDoc[$doc])) {
                $userId = $byDoc[$doc];
                $method = 'document';
            }
        }
        if ($userId === null) {
            continue;
        }
        $byBuk[$bukId] = [
            'userId' => (string) $userId,
            'bukEmployeeId' => $bukId,
            'matchMethod' => $method,
            'linkedAt' => date('c'),
            'employeeName' => (string) ($row['full_name'] ?? ''),
            'employeeEmail' => (string) ($row['email'] ?? ''),
        ];
    }

    return array_values($byBuk);
}

/**
 * Cola de activos Buk.pe sin usuario Gestión (política: pendiente, no auto-crear).
 *
 * @param list<mixed> $links
 * @return array{count:int,items:list<array<string,mixed>>}
 */
function grooflow_rrhh_build_pending_access_snapshot(PDO $pdo, array $links = []): array
{
    grooflow_rrhh_ensure_schema($pdo);
    $linkedBuk = [];
    foreach ($links as $link) {
        if (! is_array($link)) {
            continue;
        }
        $bukId = (int) ($link['bukEmployeeId'] ?? 0);
        $userId = (string) ($link['userId'] ?? '');
        if ($bukId > 0 && $userId !== '') {
            $linkedBuk[$bukId] = true;
        }
    }

    $rows = $pdo->query('
        SELECT buk_id, full_name, document_number, email, personal_email, sede, cargo, linked_usuario_id
        FROM grooflow_buk_empleados
        WHERE is_active = 1 AND is_terminated = 0
        ORDER BY full_name
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    foreach ($rows as $row) {
        $bukId = (int) ($row['buk_id'] ?? 0);
        $linkedId = trim((string) ($row['linked_usuario_id'] ?? ''));
        if ($linkedId !== '' || isset($linkedBuk[$bukId])) {
            continue;
        }
        $items[] = [
            'bukId' => $bukId,
            'fullName' => (string) ($row['full_name'] ?? ''),
            'documentNumber' => (string) ($row['document_number'] ?? ''),
            'documentKey' => grooflow_rrhh_doc_key(isset($row['document_number']) ? (string) $row['document_number'] : null),
            'email' => (string) ($row['email'] ?? $row['personal_email'] ?? ''),
            'sede' => (string) ($row['sede'] ?? ''),
            'cargo' => (string) ($row['cargo'] ?? ''),
            'identityStatus' => 'pending_access',
        ];
    }

    return ['count' => count($items), 'items' => array_slice($items, 0, 200)];
}

/**
 * Aplica política de baja: desactiva acceso Gestión y saca del organigrama Asistencia.
 *
 * @param array{dryRun?:bool,skipMetaWrite?:bool,userLinks?:list<mixed>,bukIds?:list<int>} $options
 * @return array<string, mixed>
 */
function grooflow_rrhh_apply_terminations(PDO $pdo, array $options = []): array
{
    grooflow_rrhh_ensure_schema($pdo);
    grooflow_asistencia_ensure_schema($pdo);

    $dryRun = ! empty($options['dryRun']);
    $filterBuk = [];
    if (isset($options['bukIds']) && is_array($options['bukIds'])) {
        foreach ($options['bukIds'] as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $filterBuk[$n] = true;
            }
        }
    }

    $links = is_array($options['userLinks'] ?? null) ? $options['userLinks'] : null;
    if ($links === null) {
        $meta = grooflow_kv_get($pdo, 'settings:rrhh');
        $meta = is_array($meta) ? $meta : [];
        $links = is_array($meta['userLinks'] ?? null) ? $meta['userLinks'] : [];
    }
    $linkByBuk = [];
    foreach ($links as $link) {
        if (! is_array($link)) {
            continue;
        }
        $bukId = (int) ($link['bukEmployeeId'] ?? 0);
        $userId = (string) ($link['userId'] ?? '');
        if ($bukId > 0 && $userId !== '') {
            $linkByBuk[$bukId] = $userId;
        }
    }

    $rows = $pdo->query('
        SELECT buk_id, full_name, document_number, linked_usuario_id, is_active, is_terminated, status
        FROM grooflow_buk_empleados
        WHERE is_terminated = 1 OR is_active = 0
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $users = grooflow_list_users($pdo);
    $usersById = [];
    foreach ($users as $u) {
        if (! is_array($u)) {
            continue;
        }
        $uid = (string) ($u['id'] ?? '');
        if ($uid !== '') {
            $usersById[$uid] = $u;
        }
    }

    $staffRows = [];
    try {
        $staffRows = $pdo->query('SELECT id, rut, usuario_id, full_name FROM grooflow_asistencia_staff')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        $staffRows = [];
    }
    $staffByRut = [];
    $staffByUser = [];
    foreach ($staffRows as $s) {
        $sid = (string) ($s['id'] ?? '');
        if ($sid === '') {
            continue;
        }
        $rut = grooflow_rrhh_doc_key(isset($s['rut']) ? (string) $s['rut'] : null);
        if ($rut !== '') {
            $staffByRut[$rut][] = $s;
        }
        $uid = trim((string) ($s['usuario_id'] ?? ''));
        if ($uid !== '' && $uid !== '0') {
            $staffByUser[$uid][] = $s;
        }
    }

    $usersDisabled = [];
    $staffRemoved = [];
    $errors = [];
    $candidates = 0;
    $seenStaff = [];
    $seenUsers = [];

    $delStaff = $dryRun ? null : $pdo->prepare('DELETE FROM grooflow_asistencia_staff WHERE id = ?');

    foreach ($rows as $row) {
        $bukId = (int) ($row['buk_id'] ?? 0);
        if ($bukId <= 0) {
            continue;
        }
        if ($filterBuk !== [] && ! isset($filterBuk[$bukId])) {
            continue;
        }
        $candidates++;
        $linkedId = trim((string) ($row['linked_usuario_id'] ?? ''));
        if ($linkedId === '' && isset($linkByBuk[$bukId])) {
            $linkedId = (string) $linkByBuk[$bukId];
        }
        $doc = grooflow_rrhh_doc_key(isset($row['document_number']) ? (string) $row['document_number'] : null);

        if ($linkedId !== '' && ! isset($seenUsers[$linkedId])) {
            $u = $usersById[$linkedId] ?? null;
            if (is_array($u) && (string) ($u['status'] ?? '') !== 'inactive') {
                $seenUsers[$linkedId] = true;
                if (! $dryRun) {
                    try {
                        grooflow_set_enabled($pdo, $linkedId, false);
                        $usersDisabled[] = [
                            'userId' => $linkedId,
                            'bukId' => $bukId,
                            'fullName' => (string) ($row['full_name'] ?? ''),
                            'userName' => (string) ($u['name'] ?? ''),
                        ];
                    } catch (Throwable $e) {
                        $errors[] = 'usuario ' . $linkedId . ': ' . $e->getMessage();
                    }
                } else {
                    $usersDisabled[] = [
                        'userId' => $linkedId,
                        'bukId' => $bukId,
                        'fullName' => (string) ($row['full_name'] ?? ''),
                        'userName' => (string) ($u['name'] ?? ''),
                    ];
                }
            }
        }

        $toRemove = [];
        if ($doc !== '' && isset($staffByRut[$doc])) {
            foreach ($staffByRut[$doc] as $s) {
                $toRemove[(string) $s['id']] = $s;
            }
        }
        if ($linkedId !== '' && isset($staffByUser[$linkedId])) {
            foreach ($staffByUser[$linkedId] as $s) {
                $toRemove[(string) $s['id']] = $s;
            }
        }
        foreach ($toRemove as $sid => $s) {
            if (isset($seenStaff[$sid])) {
                continue;
            }
            $seenStaff[$sid] = true;
            if (! $dryRun && $delStaff) {
                try {
                    $delStaff->execute([$sid]);
                } catch (Throwable $e) {
                    $errors[] = 'staff ' . $sid . ': ' . $e->getMessage();
                    continue;
                }
            }
            $staffRemoved[] = [
                'staffId' => $sid,
                'fullName' => (string) ($s['full_name'] ?? ''),
                'bukId' => $bukId,
                'documentNumber' => (string) ($row['document_number'] ?? ''),
            ];
        }
    }

    $result = [
        'dryRun' => $dryRun,
        'candidates' => $candidates,
        'usersDisabled' => count($usersDisabled),
        'staffRemoved' => count($staffRemoved),
        'errors' => $errors,
        'samples' => [
            'usersDisabled' => array_slice($usersDisabled, 0, 40),
            'staffRemoved' => array_slice($staffRemoved, 0, 40),
        ],
        'appliedAt' => date('c'),
    ];

    if (empty($options['skipMetaWrite'])) {
        $meta = grooflow_kv_get($pdo, 'settings:rrhh');
        $meta = is_array($meta) ? $meta : [];
        $meta['lastTerminationsApply'] = $result;
        grooflow_kv_set($pdo, 'settings:rrhh', $meta);
    }

    return $result;
}

/**
 * Vincula un colaborador Buk.pe a un usuario Gestión (manual o por DNI/email).
 *
 * @return array<string, mixed>
 */
function grooflow_rrhh_link_user(PDO $pdo, int $bukId, string $userId, string $method = 'manual'): array
{
    grooflow_rrhh_ensure_schema($pdo);
    if ($bukId <= 0 || $userId === '') {
        throw new InvalidArgumentException('bukId y userId son obligatorios');
    }

    $st = $pdo->prepare('SELECT buk_id, full_name, email, document_number, is_active, is_terminated FROM grooflow_buk_empleados WHERE buk_id = ?');
    $st->execute([$bukId]);
    $emp = $st->fetch(PDO::FETCH_ASSOC);
    if (! $emp) {
        throw new RuntimeException('Colaborador Buk.pe no encontrado');
    }

    $user = grooflow_find_gestion_user($pdo, $userId);
    if (! $user) {
        throw new RuntimeException('Usuario Gestión no encontrado');
    }
    $uid = (string) ($user['id'] ?? $userId);

    $meta = grooflow_kv_get($pdo, 'settings:rrhh');
    $meta = is_array($meta) ? $meta : [];
    $links = is_array($meta['userLinks'] ?? null) ? $meta['userLinks'] : [];
    $byBuk = [];
    foreach ($links as $link) {
        if (! is_array($link)) {
            continue;
        }
        $b = (int) ($link['bukEmployeeId'] ?? 0);
        if ($b > 0) {
            $byBuk[$b] = $link;
        }
    }
    $byBuk[$bukId] = [
        'userId' => $uid,
        'bukEmployeeId' => $bukId,
        'matchMethod' => $method !== '' ? $method : 'manual',
        'linkedAt' => date('c'),
        'employeeName' => (string) ($emp['full_name'] ?? ''),
        'employeeEmail' => (string) ($emp['email'] ?? ''),
    ];
    $links = array_values($byBuk);
    $meta['userLinks'] = $links;
    unset($meta['employees']);
    grooflow_rrhh_apply_user_links($pdo, $links);

    $pendingSnap = grooflow_rrhh_build_pending_access_snapshot($pdo, $links);
    $meta['pendingAccessCount'] = $pendingSnap['count'];
    $meta['pendingAccess'] = $pendingSnap['items'];
    $meta['pendingAccessAt'] = date('c');
    grooflow_kv_set($pdo, 'settings:rrhh', $meta);

    return [
        'bukId' => $bukId,
        'userId' => $uid,
        'matchMethod' => $method !== '' ? $method : 'manual',
        'employeeName' => (string) ($emp['full_name'] ?? ''),
        'pendingAccess' => $pendingSnap['count'],
        'identityStatus' => grooflow_rrhh_identity_status([
            'linked_usuario_id' => $uid,
            'is_terminated' => (int) ($emp['is_terminated'] ?? 0),
            'document_number' => $emp['document_number'] ?? null,
        ]),
    ];
}

/**
 * Heurística área organigrama (solo para altas; no pisa overrides).
 */
function grooflow_rrhh_guess_asistencia_area(?string $area, ?string $cargo): string
{
    $w = mb_strtolower(trim(($area ?? '') . ' ' . ($cargo ?? '')));
    if ($w === '') {
        return 'administracion';
    }
    if (preg_match('/m[eé]dic|veterin|asistente\s*vet|counter/u', $w)) {
        return 'medica';
    }
    if (preg_match('/groom|pelu|ba[nñ]ad|alistador/u', $w)) {
        return 'peluqueria';
    }

    return 'administracion';
}

/**
 * Resuelve sede GrooFlow desde sede/recinto Buk usando mappings/perfiles.
 *
 * @param array<string, mixed> $emp
 * @param array<string, mixed> $settings
 */
function grooflow_rrhh_resolve_asistencia_sede(array $emp, array $settings): string
{
    $recintoCode = trim((string) ($emp['recinto_codigo'] ?? ''));
    $recintoName = trim((string) ($emp['recinto_nombre'] ?? ''));
    $sede = trim((string) ($emp['sede'] ?? ''));
    $mappings = is_array($settings['sedeMappings'] ?? null) ? $settings['sedeMappings'] : [];
    $profiles = is_array($settings['sedeProfiles'] ?? null) ? $settings['sedeProfiles'] : [];

    $norm = static function (string $s): string {
        return mb_strtolower(trim($s));
    };

    foreach ($mappings as $m) {
        if (! is_array($m)) {
            continue;
        }
        $code = trim((string) ($m['bukRecintoCode'] ?? ''));
        $name = trim((string) ($m['sedeName'] ?? ''));
        if ($name === '') {
            continue;
        }
        if ($recintoCode !== '' && $code !== '' && strcasecmp($code, $recintoCode) === 0) {
            return $name;
        }
    }

    foreach ($profiles as $p) {
        if (! is_array($p)) {
            continue;
        }
        $code = trim((string) ($p['bukRecintoCode'] ?? ''));
        $name = trim((string) ($p['sedeName'] ?? ''));
        if ($name === '') {
            continue;
        }
        if ($recintoCode !== '' && $code !== '' && strcasecmp($code, $recintoCode) === 0) {
            return $name;
        }
    }

    $candidates = [];
    foreach ($mappings as $m) {
        if (is_array($m) && trim((string) ($m['sedeName'] ?? '')) !== '') {
            $candidates[] = trim((string) $m['sedeName']);
        }
    }
    foreach ($profiles as $p) {
        if (is_array($p) && trim((string) ($p['sedeName'] ?? '')) !== '') {
            $candidates[] = trim((string) $p['sedeName']);
        }
    }
    $candidates = array_values(array_unique($candidates));

    foreach ($candidates as $name) {
        $nn = $norm($name);
        if ($sede !== '' && (str_contains($nn, $norm($sede)) || str_contains($norm($sede), $nn))) {
            return $name;
        }
        if ($recintoName !== '' && (str_contains($nn, $norm($recintoName)) || str_contains($norm($recintoName), $nn))) {
            return $name;
        }
    }

    if ($sede !== '') {
        return $sede;
    }
    if ($candidates !== []) {
        return $candidates[0];
    }

    return 'Principal';
}

/**
 * Fase 4: proyecta organigrama Asistencia desde colaboradores activos Buk.pe.
 * Preserva overrides: area, isCritical, isManager, turnos/expectedTime.
 *
 * @param array{pruneInactive?:bool,onlySedes?:list<string>} $options
 * @return array<string, mixed>
 */
function grooflow_rrhh_project_asistencia_staff(PDO $pdo, array $options = []): array
{
    grooflow_rrhh_ensure_schema($pdo);
    grooflow_asistencia_ensure_schema($pdo);

    $settings = grooflow_asistencia_get_settings($pdo);
    $settings = is_array($settings) ? $settings : [
        'buk' => [],
        'requirements' => [],
        'staff' => [],
        'sedeProfiles' => [],
        'sedeMappings' => [],
        'areaKeywords' => ['medica' => [], 'peluqueria' => []],
    ];

    $staff = is_array($settings['staff'] ?? null) ? $settings['staff'] : [];
    $byId = [];
    $byRut = [];
    $byUser = [];
    $byBuk = [];
    foreach ($staff as $idx => $item) {
        if (! is_array($item)) {
            continue;
        }
        $id = trim((string) ($item['id'] ?? ''));
        if ($id !== '') {
            $byId[$id] = $idx;
        }
        $rut = grooflow_rrhh_doc_key(isset($item['rut']) ? (string) $item['rut'] : null);
        if ($rut !== '') {
            $byRut[$rut] = $idx;
        }
        $uid = trim((string) ($item['usuarioId'] ?? $item['userId'] ?? ''));
        if ($uid !== '' && $uid !== '0') {
            $byUser[$uid] = $idx;
        }
        $bukEmp = (int) ($item['bukEmployeeId'] ?? 0);
        if ($bukEmp > 0) {
            $byBuk[$bukEmp] = $idx;
        } elseif (preg_match('/^buk_(\d+)$/', $id, $m)) {
            $byBuk[(int) $m[1]] = $idx;
        }
    }

    $rows = $pdo->query('
        SELECT buk_id, full_name, document_number, email, personal_email, phone,
               cargo, area, sede, recinto_codigo, recinto_nombre, linked_usuario_id,
               is_active, is_terminated
        FROM grooflow_buk_empleados
        WHERE is_active = 1 AND is_terminated = 0
        ORDER BY full_name
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $onlySedes = [];
    if (isset($options['onlySedes']) && is_array($options['onlySedes'])) {
        foreach ($options['onlySedes'] as $s) {
            $t = trim((string) $s);
            if ($t !== '') {
                $onlySedes[mb_strtolower($t)] = true;
            }
        }
    }

    $added = 0;
    $updated = 0;
    $unchanged = 0;
    $skipped = 0;
    $seenStaffIds = [];
    $activeBukIds = [];

    foreach ($rows as $emp) {
        $bukId = (int) ($emp['buk_id'] ?? 0);
        if ($bukId <= 0) {
            continue;
        }
        $activeBukIds[$bukId] = true;
        $doc = grooflow_rrhh_doc_key(isset($emp['document_number']) ? (string) $emp['document_number'] : null);
        $sedeName = grooflow_rrhh_resolve_asistencia_sede($emp, $settings);
        if ($onlySedes !== [] && ! isset($onlySedes[mb_strtolower($sedeName)])) {
            $skipped++;
            continue;
        }

        $linkedId = trim((string) ($emp['linked_usuario_id'] ?? ''));
        $idx = null;
        if (isset($byBuk[$bukId])) {
            $idx = $byBuk[$bukId];
        } elseif ($doc !== '' && isset($byRut[$doc])) {
            $idx = $byRut[$doc];
        } elseif ($linkedId !== '' && isset($byUser[$linkedId])) {
            $idx = $byUser[$linkedId];
        } elseif (isset($byId['buk_' . $bukId])) {
            $idx = $byId['buk_' . $bukId];
        }

        $official = [
            'fullName' => (string) ($emp['full_name'] ?? ''),
            'cargoLabel' => trim((string) ($emp['cargo'] ?? '')) !== '' ? (string) $emp['cargo'] : 'Colaborador',
            'sedeName' => $sedeName,
            'rut' => $doc !== '' ? $doc : null,
            'email' => trim((string) ($emp['email'] ?? $emp['personal_email'] ?? '')) ?: null,
            'phone' => trim((string) ($emp['phone'] ?? '')) ?: null,
            'bukEmployeeId' => $bukId,
            'source' => 'buk_pe',
        ];
        if ($linkedId !== '') {
            $official['usuarioId'] = $linkedId;
        }

        if ($idx !== null && isset($staff[$idx]) && is_array($staff[$idx])) {
            $existing = $staff[$idx];
            $next = $existing;
            foreach (['fullName', 'sedeName', 'rut', 'email', 'phone', 'bukEmployeeId', 'source', 'usuarioId'] as $k) {
                if (array_key_exists($k, $official) && $official[$k] !== null) {
                    $next[$k] = $official[$k];
                }
            }
            // Cargo operativo = Gestión. Buk solo comparativa: no pisar cargoLabel.
            $hasGestionLink = trim((string) ($existing['usuarioId'] ?? '')) !== ''
                || (($existing['source'] ?? '') === 'users');
            if (! $hasGestionLink && empty($existing['cargoLabel'])) {
                $next['cargoLabel'] = $official['cargoLabel'];
            } else {
                $next['cargoLabel'] = (string) ($existing['cargoLabel'] ?? $official['cargoLabel']);
            }
            // Preservar overrides operativos (Fase 0).
            $next['area'] = (string) ($existing['area'] ?? 'administracion');
            $next['isCritical'] = ! empty($existing['isCritical']);
            if (array_key_exists('isManager', $existing)) {
                $next['isManager'] = ! empty($existing['isManager']);
            }
            foreach (['expectedTime', 'shift', 'shiftMode', 'weeklyShifts', 'expectedTimeNight', 'sortOrder', 'avatarUrl', 'matchArea', 'matchSpecialty'] as $keep) {
                if (array_key_exists($keep, $existing)) {
                    $next[$keep] = $existing[$keep];
                }
            }
            $sid = trim((string) ($next['id'] ?? ''));
            if ($sid === '') {
                $next['id'] = 'buk_' . $bukId;
                $sid = $next['id'];
            }
            $changed = json_encode($existing) !== json_encode($next);
            $staff[$idx] = $next;
            $seenStaffIds[$sid] = true;
            if ($changed) {
                $updated++;
            } else {
                $unchanged++;
            }
            continue;
        }

        $member = [
            'id' => 'buk_' . $bukId,
            'sedeName' => $sedeName,
            'fullName' => $official['fullName'],
            'cargoLabel' => $official['cargoLabel'],
            'area' => grooflow_rrhh_guess_asistencia_area(
                isset($emp['area']) ? (string) $emp['area'] : null,
                isset($emp['cargo']) ? (string) $emp['cargo'] : null
            ),
            'expectedTime' => '08:00',
            'shift' => 'day',
            'isCritical' => false,
            'isManager' => false,
            'rut' => $official['rut'],
            'email' => $official['email'],
            'phone' => $official['phone'],
            'bukEmployeeId' => $bukId,
            'source' => 'buk_pe',
            'sortOrder' => 0,
        ];
        if ($linkedId !== '') {
            $member['usuarioId'] = $linkedId;
        }
        $staff[] = $member;
        $seenStaffIds[$member['id']] = true;
        $added++;
    }

    $pruned = 0;
    $prune = ($options['pruneInactive'] ?? true) !== false;
    if ($prune) {
        $kept = [];
        foreach ($staff as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            $bukEmp = (int) ($item['bukEmployeeId'] ?? 0);
            if ($bukEmp <= 0 && preg_match('/^buk_(\d+)$/', $id, $m)) {
                $bukEmp = (int) $m[1];
            }
            $source = (string) ($item['source'] ?? '');
            $isProjected = $source === 'buk_pe' || $bukEmp > 0 || str_starts_with($id, 'buk_');
            if ($isProjected && $bukEmp > 0 && ! isset($activeBukIds[$bukEmp])) {
                $pruned++;
                continue;
            }
            $kept[] = $item;
        }
        $staff = $kept;
    }

    $settings['staff'] = array_values($staff);
    grooflow_asistencia_set_settings($pdo, $settings);

    $meta = grooflow_kv_get($pdo, 'settings:rrhh');
    $meta = is_array($meta) ? $meta : [];
    $result = [
        'added' => $added,
        'updated' => $updated,
        'unchanged' => $unchanged,
        'skipped' => $skipped,
        'pruned' => $pruned,
        'staffTotal' => count($settings['staff']),
        'activeEmployees' => count($activeBukIds),
        'projectedAt' => date('c'),
    ];
    $meta['lastAsistenciaProject'] = $result;
    unset($meta['employees']);
    grooflow_kv_set($pdo, 'settings:rrhh', $meta);

    return $result;
}

/**
 * Diagnóstico de identidad Fase 1 (Buk.pe ↔ Gestión ↔ Asistencia).
 * Política Fase 0: alta Buk.pe; pendientes de acceso (no auto-crear usuario);
 * cesados → desactivar acceso y sacar de organigrama; turnos los publica el encargado.
 *
 * @return array<string, mixed>
 */
function grooflow_rrhh_identity_diagnosis(PDO $pdo, int $sampleLimit = 40): array
{
    grooflow_rrhh_ensure_schema($pdo);
    require_once __DIR__ . '/grooflow_asistencia.php';
    grooflow_asistencia_ensure_schema($pdo);

    $sampleLimit = max(5, min(100, $sampleLimit));
    $policy = [
        'sourceOfTruth' => 'buk.pe',
        'altaSinUsuario' => 'pendiente_notificacion',
        'cesadoDesactivaAccesoYOrganigrama' => true,
        'turnosPublica' => 'encargado_sede',
        'camposOficialesBuk' => ['dni', 'cargo', 'sede_obra', 'activo'],
        'camposEditablesGrooflow' => ['area_organigrama', 'critico', 'manager'],
    ];

    $meta = grooflow_kv_get($pdo, 'settings:rrhh');
    $meta = is_array($meta) ? $meta : [];
    $kvLinks = is_array($meta['userLinks'] ?? null) ? $meta['userLinks'] : [];
    $linkedByBuk = [];
    foreach ($kvLinks as $link) {
        if (! is_array($link)) {
            continue;
        }
        $bukId = (int) ($link['bukEmployeeId'] ?? 0);
        $userId = (string) ($link['userId'] ?? '');
        if ($bukId > 0 && $userId !== '') {
            $linkedByBuk[$bukId] = $userId;
        }
    }

    $empRows = $pdo->query('
        SELECT buk_id, full_name, document_number, email, personal_email, sede, cargo, area,
               is_active, is_terminated, linked_usuario_id, status
        FROM grooflow_buk_empleados
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $users = grooflow_list_users($pdo);
    $usersById = [];
    $usersWithoutDni = [];
    $activeUserIds = [];
    foreach ($users as $u) {
        if (! is_array($u)) {
            continue;
        }
        $uid = (string) ($u['id'] ?? '');
        if ($uid === '') {
            continue;
        }
        $usersById[$uid] = $u;
        $status = (string) ($u['status'] ?? 'active');
        if ($status === 'active') {
            $activeUserIds[$uid] = true;
        }
        $doc = preg_replace('/\D+/', '', (string) ($u['documentNumber'] ?? $u['dni'] ?? '')) ?? '';
        if ($status === 'active' && $doc === '') {
            $usersWithoutDni[] = [
                'userId' => $uid,
                'name' => trim((string) ($u['name'] ?? '') . ' ' . (string) ($u['lastName'] ?? '')),
                'email' => (string) ($u['email'] ?? ''),
            ];
        }
    }

    $matched = [];
    $pendingAccess = [];
    $terminatedStillActive = [];

    foreach ($empRows as $row) {
        $bukId = (int) ($row['buk_id'] ?? 0);
        if ($bukId <= 0) {
            continue;
        }
        $isActive = (int) ($row['is_active'] ?? 0) === 1;
        $isTerm = (int) ($row['is_terminated'] ?? 0) === 1 || ! $isActive;
        $linkedId = trim((string) ($row['linked_usuario_id'] ?? ''));
        if ($linkedId === '' && isset($linkedByBuk[$bukId])) {
            $linkedId = (string) $linkedByBuk[$bukId];
        }
        $item = [
            'bukId' => $bukId,
            'fullName' => (string) ($row['full_name'] ?? ''),
            'documentNumber' => (string) ($row['document_number'] ?? ''),
            'documentKey' => grooflow_rrhh_doc_key(isset($row['document_number']) ? (string) $row['document_number'] : null),
            'email' => (string) ($row['email'] ?? $row['personal_email'] ?? ''),
            'sede' => (string) ($row['sede'] ?? ''),
            'cargo' => (string) ($row['cargo'] ?? ''),
            'linkedUsuarioId' => $linkedId !== '' ? $linkedId : null,
            'identityStatus' => grooflow_rrhh_identity_status([
                'linked_usuario_id' => $linkedId !== '' ? $linkedId : null,
                'is_terminated' => $isTerm ? 1 : 0,
                'document_number' => $row['document_number'] ?? null,
            ]),
        ];

        if ($isActive && $linkedId !== '') {
            $matched[] = $item;
        } elseif ($isActive && $linkedId === '') {
            $pendingAccess[] = $item;
        }

        if ($isTerm && $linkedId !== '' && isset($activeUserIds[$linkedId])) {
            $u = $usersById[$linkedId] ?? [];
            $terminatedStillActive[] = array_merge($item, [
                'userName' => (string) ($u['name'] ?? ''),
                'userEmail' => (string) ($u['email'] ?? ''),
                'userStatus' => (string) ($u['status'] ?? ''),
            ]);
        }
    }

    // Asistencia: staff con/sin RUT y cruce vs Buk.pe por DNI
    $staffInOrg = 0;
    $staffWithRut = 0;
    $staffMatchedBuk = 0;
    $staffWithoutRutCount = 0;
    $staffWithoutRut = [];
    $bukDocs = [];
    foreach ($empRows as $row) {
        if ((int) ($row['is_active'] ?? 0) !== 1) {
            continue;
        }
        $doc = preg_replace('/\D+/', '', (string) ($row['document_number'] ?? '')) ?? '';
        if ($doc !== '') {
            $bukDocs[$doc] = true;
        }
    }
    try {
        $staffRows = $pdo->query('
            SELECT id, full_name, rut, sede_name, cargo_label
            FROM grooflow_asistencia_staff
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($staffRows as $s) {
            $staffInOrg++;
            $rut = preg_replace('/\D+/', '', (string) ($s['rut'] ?? '')) ?? '';
            if ($rut === '') {
                $staffWithoutRutCount++;
                if (count($staffWithoutRut) < $sampleLimit) {
                    $staffWithoutRut[] = [
                        'staffId' => (string) ($s['id'] ?? ''),
                        'fullName' => (string) ($s['full_name'] ?? ''),
                        'sedeName' => (string) ($s['sede_name'] ?? ''),
                        'cargoLabel' => (string) ($s['cargo_label'] ?? ''),
                    ];
                }
                continue;
            }
            $staffWithRut++;
            if (isset($bukDocs[$rut])) {
                $staffMatchedBuk++;
            }
        }
    } catch (Throwable) {
        /* tabla asistencia aún no disponible */
    }

    $take = static function (array $rows, int $limit): array {
        return array_slice($rows, 0, $limit);
    };

    return [
        'policy' => $policy,
        'generatedAt' => date('c'),
        'pendingAccessAt' => isset($meta['pendingAccessAt']) ? (string) $meta['pendingAccessAt'] : null,
        'lastTerminationsApply' => is_array($meta['lastTerminationsApply'] ?? null) ? $meta['lastTerminationsApply'] : null,
        'counts' => [
            'bukActivos' => count(array_filter($empRows, static fn ($r) => (int) ($r['is_active'] ?? 0) === 1)),
            'bukTotal' => count($empRows),
            'matched' => count($matched),
            'pendingAccess' => count($pendingAccess),
            'usersWithoutDni' => count($usersWithoutDni),
            'terminatedStillActive' => count($terminatedStillActive),
            'staffInOrganigrama' => $staffInOrg,
            'staffWithRut' => $staffWithRut,
            'staffMatchedBuk' => $staffMatchedBuk,
            'staffWithoutRut' => $staffWithoutRutCount,
        ],
        'samples' => [
            'matched' => $take($matched, $sampleLimit),
            'pendingAccess' => $take($pendingAccess, $sampleLimit),
            'usersWithoutDni' => $take($usersWithoutDni, $sampleLimit),
            'terminatedStillActive' => $take($terminatedStillActive, $sampleLimit),
            'staffWithoutRut' => $take($staffWithoutRut, $sampleLimit),
        ],
    ];
}

