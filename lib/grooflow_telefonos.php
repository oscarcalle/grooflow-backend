<?php

declare(strict_types=1);

/**
 * Directorio telefónico corporativo: líneas del operador asignadas a colaboradores (Buk),
 * bots o casos especiales.
 */

const GROOFLOW_TELEFONOS_MODULE = 'Directorio Telefónico';
const GROOFLOW_TELEFONOS_TIPOS = ['persona', 'bot', 'especial', 'sin_asignar'];
const GROOFLOW_TELEFONOS_ESTADOS = ['activo', 'suspendido', 'baja'];

function grooflow_telefonos_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done || $pdo->inTransaction()) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_telefonos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            numero VARCHAR(20) NOT NULL,
            tipo VARCHAR(20) NOT NULL DEFAULT 'sin_asignar',
            buk_id INT UNSIGNED NULL,
            etiqueta VARCHAR(190) NULL,
            responsable VARCHAR(190) NULL,
            operador VARCHAR(80) NULL,
            plan VARCHAR(120) NULL,
            costo_mensual DECIMAL(10,2) NULL,
            equipo VARCHAR(160) NULL,
            imei VARCHAR(40) NULL,
            iccid VARCHAR(40) NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'activo',
            notas VARCHAR(500) NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_gf_tel_numero (numero),
            KEY idx_gf_tel_buk (buk_id),
            KEY idx_gf_tel_tipo (tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $done = true;
}

function grooflow_telefonos_can(PDO $pdo, string $action): bool
{
    $ctx = grooflow_access_context($pdo);
    if (!empty($ctx['admin'])) {
        return true;
    }

    return !empty($ctx['actions'][GROOFLOW_TELEFONOS_MODULE][$action]);
}

function grooflow_telefonos_require(PDO $pdo, string $action): void
{
    if (!grooflow_telefonos_can($pdo, $action)) {
        throw new RuntimeException('No tienes permiso para esta acción del Directorio Telefónico');
    }
}

/** Solo dígitos; quita el prefijo de país 51 en móviles peruanos (51 + 9 dígitos). */
function grooflow_telefonos_normalize(string $raw): string
{
    $d = preg_replace('/\D/', '', $raw) ?? '';
    if (strlen($d) === 11 && str_starts_with($d, '51')) {
        $d = substr($d, 2);
    }

    return $d;
}

function grooflow_telefonos_str(mixed $v, int $max): ?string
{
    $s = trim((string) ($v ?? ''));

    return $s !== '' ? mb_substr($s, 0, $max) : null;
}

function grooflow_telefonos_money(mixed $v): ?float
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_string($v)) {
        $v = str_replace(['S/', 's/', ' ', "\u{00A0}"], '', $v);
        $v = str_contains($v, '.') ? str_replace(',', '', $v) : str_replace(',', '.', $v);
    }
    if (!is_numeric($v)) {
        return null;
    }
    $n = round((float) $v, 2);

    return $n >= 0 ? $n : null;
}

/** @return list<array<string, mixed>> colaboradores activos de Buk */
function grooflow_telefonos_colaboradores(PDO $pdo): array
{
    try {
        $rows = $pdo->query('
            SELECT buk_id, full_name, first_name, surname, document_number, cargo, area, sede, phone
            FROM grooflow_buk_empleados
            WHERE is_active = 1 AND is_terminated = 0
            ORDER BY full_name
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }

    return array_map(static fn (array $r) => [
        'bukId' => (int) $r['buk_id'],
        'nombreCompleto' => (string) $r['full_name'],
        'nombres' => $r['first_name'],
        'apellidos' => $r['surname'],
        'documento' => $r['document_number'],
        'cargo' => $r['cargo'],
        'area' => $r['area'],
        'sede' => $r['sede'],
        'telefono' => $r['phone'] !== null ? grooflow_telefonos_normalize((string) $r['phone']) : null,
    ], $rows);
}

/** @return array<string, mixed> */
function grooflow_telefonos_row_to_app(array $r): array
{
    return [
        'id' => (string) $r['id'],
        'numero' => (string) $r['numero'],
        'tipo' => (string) $r['tipo'],
        'bukId' => $r['buk_id'] !== null ? (int) $r['buk_id'] : null,
        'etiqueta' => $r['etiqueta'],
        'responsable' => $r['responsable'],
        'operador' => $r['operador'],
        'plan' => $r['plan'],
        'costoMensual' => $r['costo_mensual'] !== null ? (float) $r['costo_mensual'] : null,
        'equipo' => $r['equipo'],
        'imei' => $r['imei'],
        'iccid' => $r['iccid'],
        'estado' => (string) $r['estado'],
        'notas' => $r['notas'],
        'colaborador' => $r['buk_id'] !== null && ($r['c_full_name'] ?? null) !== null ? [
            'nombreCompleto' => (string) $r['c_full_name'],
            'nombres' => $r['c_first_name'],
            'apellidos' => $r['c_surname'],
            'cargo' => $r['c_cargo'],
            'area' => $r['c_area'],
            'sede' => $r['c_sede'],
            'activo' => (int) $r['c_is_active'] === 1 && (int) $r['c_is_terminated'] === 0,
        ] : null,
        'updatedAt' => (string) $r['updated_at'],
    ];
}

/** @return list<array<string, mixed>> */
function grooflow_telefonos_list(PDO $pdo): array
{
    $sql = '
        SELECT t.*, e.full_name AS c_full_name, e.first_name AS c_first_name, e.surname AS c_surname,
               e.cargo AS c_cargo, e.area AS c_area, e.sede AS c_sede,
               e.is_active AS c_is_active, e.is_terminated AS c_is_terminated
        FROM grooflow_telefonos t
        LEFT JOIN grooflow_buk_empleados e ON e.buk_id = t.buk_id
        ORDER BY t.numero
    ';
    try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        $rows = $pdo->query('SELECT t.*, NULL AS c_full_name FROM grooflow_telefonos t ORDER BY t.numero')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    return array_map('grooflow_telefonos_row_to_app', $rows);
}

function grooflow_telefonos_find(PDO $pdo, int $id): array
{
    foreach (grooflow_telefonos_list($pdo) as $row) {
        if ((int) $row['id'] === $id) {
            return $row;
        }
    }
    throw new RuntimeException('Número no encontrado');
}

/** @return array<string, mixed> columnas validadas */
function grooflow_telefonos_validate(PDO $pdo, array $in): array
{
    $errors = [];
    $numero = grooflow_telefonos_normalize((string) ($in['numero'] ?? ''));
    if (strlen($numero) < 3 || strlen($numero) > 15) {
        $errors['numero'] = 'Número inválido.';
    }
    $tipo = (string) ($in['tipo'] ?? 'sin_asignar');
    if (!in_array($tipo, GROOFLOW_TELEFONOS_TIPOS, true)) {
        $errors['tipo'] = 'Tipo de asignación inválido.';
    }
    $bukId = (int) ($in['bukId'] ?? 0);
    $etiqueta = grooflow_telefonos_str($in['etiqueta'] ?? null, 190);
    if ($tipo === 'persona') {
        if ($bukId <= 0) {
            $errors['bukId'] = 'Elige el colaborador al que se asignó el número.';
        } else {
            $stmt = $pdo->prepare('SELECT 1 FROM grooflow_buk_empleados WHERE buk_id = ?');
            $stmt->execute([$bukId]);
            if (!$stmt->fetchColumn()) {
                $errors['bukId'] = 'Colaborador no encontrado en Buk.';
            }
        }
    } else {
        $bukId = 0;
        if (in_array($tipo, ['bot', 'especial'], true) && $etiqueta === null) {
            $errors['etiqueta'] = 'Indica para qué se usa el número (ej. Bot WhatsApp citas).';
        }
    }
    $estado = (string) ($in['estado'] ?? 'activo');
    if (!in_array($estado, GROOFLOW_TELEFONOS_ESTADOS, true)) {
        $errors['estado'] = 'Estado inválido.';
    }
    $costoRaw = $in['costoMensual'] ?? null;
    $costo = grooflow_telefonos_money($costoRaw);
    if ($costoRaw !== null && $costoRaw !== '' && $costo === null) {
        $errors['costoMensual'] = 'Costo inválido.';
    }
    if ($errors !== []) {
        throw new GrooflowValidation($errors);
    }

    return [
        'numero' => $numero,
        'tipo' => $tipo,
        'buk_id' => $bukId > 0 ? $bukId : null,
        'etiqueta' => $etiqueta,
        'responsable' => grooflow_telefonos_str($in['responsable'] ?? null, 190),
        'operador' => grooflow_telefonos_str($in['operador'] ?? null, 80),
        'plan' => grooflow_telefonos_str($in['plan'] ?? null, 120),
        'costo_mensual' => $costo,
        'equipo' => grooflow_telefonos_str($in['equipo'] ?? null, 160),
        'imei' => grooflow_telefonos_str($in['imei'] ?? null, 40),
        'iccid' => grooflow_telefonos_str($in['iccid'] ?? null, 40),
        'estado' => $estado,
        'notas' => grooflow_telefonos_str($in['notas'] ?? null, 500),
    ];
}

function grooflow_telefonos_assert_unique(PDO $pdo, string $numero, int $exceptId): void
{
    $stmt = $pdo->prepare('SELECT id FROM grooflow_telefonos WHERE numero = ? AND id <> ?');
    $stmt->execute([$numero, $exceptId]);
    if ($stmt->fetchColumn()) {
        throw new GrooflowConflict("El número {$numero} ya está en el directorio.");
    }
}

function grooflow_telefonos_audit(PDO $pdo, string $event, array $payload): void
{
    if (function_exists('grooflow_audit_insert')) {
        grooflow_audit_insert($pdo, api_current_user() ?? [], $event, $payload + ['entity' => 'telefono']);
    }
}

/** @return array<string, mixed> */
function grooflow_telefonos_create(PDO $pdo, array $in): array
{
    grooflow_telefonos_require($pdo, 'agregar');
    $d = grooflow_telefonos_validate($pdo, $in);
    grooflow_telefonos_assert_unique($pdo, $d['numero'], 0);
    $d['created_by'] = (int) ((api_current_user() ?? [])['id'] ?? 0) ?: null;
    $cols = array_keys($d);
    $pdo->prepare('INSERT INTO grooflow_telefonos (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($d));
    $id = (int) $pdo->lastInsertId();
    grooflow_telefonos_audit($pdo, 'telefonos.create', ['entity_id' => (string) $id, 'numero' => $d['numero'], 'tipo' => $d['tipo']]);

    return grooflow_telefonos_find($pdo, $id);
}

/** @return array<string, mixed> */
function grooflow_telefonos_update(PDO $pdo, int $id, array $in): array
{
    grooflow_telefonos_require($pdo, 'editar');
    grooflow_telefonos_find($pdo, $id);
    $d = grooflow_telefonos_validate($pdo, $in);
    grooflow_telefonos_assert_unique($pdo, $d['numero'], $id);
    $set = implode(', ', array_map(static fn (string $c) => "{$c} = ?", array_keys($d)));
    $pdo->prepare("UPDATE grooflow_telefonos SET {$set} WHERE id = ?")->execute([...array_values($d), $id]);
    grooflow_telefonos_audit($pdo, 'telefonos.update', ['entity_id' => (string) $id, 'numero' => $d['numero'], 'tipo' => $d['tipo'], 'buk_id' => $d['buk_id']]);

    return grooflow_telefonos_find($pdo, $id);
}

function grooflow_telefonos_delete(PDO $pdo, int $id): void
{
    grooflow_telefonos_require($pdo, 'eliminar');
    $row = grooflow_telefonos_find($pdo, $id);
    $pdo->prepare('DELETE FROM grooflow_telefonos WHERE id = ?')->execute([$id]);
    grooflow_telefonos_audit($pdo, 'telefonos.delete', ['entity_id' => (string) $id, 'numero' => $row['numero']]);
}

/**
 * Importa la lista del operador. Agrega números nuevos y actualiza datos de línea (operador, plan,
 * costo, equipo) de los existentes sin tocar su asignación. Los nuevos se vinculan solos si el número
 * coincide con el teléfono de un colaborador en Buk.
 *
 * @return array{creados: int, actualizados: int, vinculados: int, omitidos: list<string>}
 */
function grooflow_telefonos_import(PDO $pdo, array $in): array
{
    grooflow_telefonos_require($pdo, 'agregar');
    $rows = is_array($in['rows'] ?? null) ? $in['rows'] : [];
    if ($rows === [] || count($rows) > 3000) {
        throw new InvalidArgumentException('El archivo debe tener entre 1 y 3000 líneas.');
    }
    $byPhone = [];
    foreach (grooflow_telefonos_colaboradores($pdo) as $c) {
        if (!empty($c['telefono']) && strlen((string) $c['telefono']) >= 7) {
            $byPhone[(string) $c['telefono']] ??= $c['bukId'];
        }
    }
    $userId = (int) ((api_current_user() ?? [])['id'] ?? 0) ?: null;
    $lineFields = ['operador' => 80, 'plan' => 120, 'equipo' => 160, 'imei' => 40, 'iccid' => 40];

    return grooflow_atomic($pdo, static function () use ($pdo, $rows, $byPhone, $userId, $lineFields): array {
        $res = ['creados' => 0, 'actualizados' => 0, 'vinculados' => 0, 'omitidos' => []];
        $find = $pdo->prepare('SELECT id FROM grooflow_telefonos WHERE numero = ?');
        foreach ($rows as $i => $r) {
            if (!is_array($r)) {
                continue;
            }
            $numero = grooflow_telefonos_normalize((string) ($r['numero'] ?? ''));
            if (strlen($numero) < 3 || strlen($numero) > 15) {
                $res['omitidos'][] = 'Fila ' . ($i + 2) . ': número inválido «' . mb_substr((string) ($r['numero'] ?? ''), 0, 30) . '»';
                continue;
            }
            $data = [];
            foreach ($lineFields as $f => $max) {
                $v = grooflow_telefonos_str($r[$f] ?? null, $max);
                if ($v !== null) {
                    $data[$f] = $v;
                }
            }
            $costo = grooflow_telefonos_money($r['costoMensual'] ?? null);
            if ($costo !== null) {
                $data['costo_mensual'] = $costo;
            }
            $find->execute([$numero]);
            $existingId = $find->fetchColumn();
            if ($existingId) {
                if ($data !== []) {
                    $set = implode(', ', array_map(static fn (string $c) => "{$c} = ?", array_keys($data)));
                    $pdo->prepare("UPDATE grooflow_telefonos SET {$set} WHERE id = ?")->execute([...array_values($data), (int) $existingId]);
                }
                $res['actualizados']++;
                continue;
            }
            $bukId = $byPhone[$numero] ?? null;
            $nota = grooflow_telefonos_str($r['nombre'] ?? null, 190);
            $data += [
                'numero' => $numero,
                'tipo' => $bukId !== null ? 'persona' : 'sin_asignar',
                'buk_id' => $bukId,
                'notas' => $nota !== null ? "Proveedor: {$nota}" : null,
                'created_by' => $userId,
            ];
            $cols = array_keys($data);
            $pdo->prepare('INSERT INTO grooflow_telefonos (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_values($data));
            $res['creados']++;
            if ($bukId !== null) {
                $res['vinculados']++;
            }
        }
        grooflow_telefonos_audit($pdo, 'telefonos.import', ['creados' => $res['creados'], 'actualizados' => $res['actualizados'], 'vinculados' => $res['vinculados']]);

        return $res;
    });
}

function grooflow_telefonos_dispatch(PDO $pdo, string $path, string $method): bool
{
    if ($path !== '/telefonos' && !str_starts_with($path, '/telefonos/')) {
        return false;
    }
    grooflow_assert_module($pdo, [GROOFLOW_TELEFONOS_MODULE]);
    grooflow_telefonos_ensure_schema($pdo);

    if ($path === '/telefonos' && $method === 'GET') {
        api_json_response([
            'ok' => true,
            'items' => grooflow_telefonos_list($pdo),
            'colaboradores' => grooflow_telefonos_colaboradores($pdo),
            'capabilities' => [
                'agregar' => grooflow_telefonos_can($pdo, 'agregar'),
                'editar' => grooflow_telefonos_can($pdo, 'editar'),
                'eliminar' => grooflow_telefonos_can($pdo, 'eliminar'),
                'exportar' => grooflow_telefonos_can($pdo, 'exportar'),
            ],
        ]);
        return true;
    }
    if ($path === '/telefonos' && $method === 'POST') {
        api_json_response(['ok' => true, 'item' => grooflow_telefonos_create($pdo, api_request_json())]);
        return true;
    }
    if ($path === '/telefonos/import' && $method === 'POST') {
        api_json_response(['ok' => true] + grooflow_telefonos_import($pdo, api_request_json()));
        return true;
    }
    if (preg_match('#^/telefonos/(\d+)$#', $path, $m)) {
        if ($method === 'PUT') {
            api_json_response(['ok' => true, 'item' => grooflow_telefonos_update($pdo, (int) $m[1], api_request_json())]);
            return true;
        }
        if ($method === 'DELETE') {
            grooflow_telefonos_delete($pdo, (int) $m[1]);
            api_json_response(['ok' => true]);
            return true;
        }
    }
    api_json_response(['ok' => false, 'error' => 'Ruta del directorio no encontrada'], 404);
    return true;
}
