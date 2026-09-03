<?php

declare(strict_types=1);

function grooflow_kv_get(PDO $pdo, string $key): mixed
{
    grooflow_ensure_schema($pdo);
    $key = grooflow_normalize_kv_key($key);
    if ($key === 'data:sedes') {
        require_once __DIR__ . '/grooflow_sedes.php';

        return grooflow_list_sedes($pdo);
    }
    if ($key === 'data:users') {
        return grooflow_list_users($pdo);
    }
    if ($key === 'data:roles') {
        return grooflow_list_roles($pdo);
    }
    if ($key === 'settings:asistencia') {
        require_once __DIR__ . '/grooflow_asistencia.php';

        return grooflow_asistencia_get_settings($pdo);
    }
    if ($key === 'data:asistencia-snapshots') {
        require_once __DIR__ . '/grooflow_asistencia.php';

        return grooflow_asistencia_list_snapshots($pdo);
    }
    if ($key === 'data:asistencia-operational') {
        require_once __DIR__ . '/grooflow_asistencia.php';

        return grooflow_asistencia_get_operational($pdo);
    }

    $table = grooflow_kv_array_tables()[$key] ?? null;
    if ($table !== null) {
        $items = grooflow_table_list($pdo, $table);
        if ($items !== []) {
            return $items;
        }
    }

    $stmt = $pdo->prepare('SELECT value FROM grooflow_kv WHERE k = ? LIMIT 1');
    $stmt->execute([$key]);
    $raw = $stmt->fetchColumn();
    if ($raw === false || $raw === null) {
        if ($key === 'settings:system') {
            require_once __DIR__ . '/grooflow_sedes.php';

            return grooflow_merge_system_settings_sedes($pdo, null);
        }

        return null;
    }
    $decoded = grooflow_json_decode(is_string($raw) ? $raw : (string) $raw);

    if ($key === 'settings:system') {
        require_once __DIR__ . '/grooflow_sedes.php';

        return grooflow_merge_system_settings_sedes($pdo, is_array($decoded) ? $decoded : null);
    }

    return $decoded;
}

function grooflow_kv_set(PDO $pdo, string $key, mixed $value): void
{
    grooflow_ensure_schema($pdo);
    $key = grooflow_normalize_kv_key($key);
    if ($key === 'data:users') {
        grooflow_replace_users($pdo, is_array($value) ? $value : []);

        return;
    }
    if ($key === 'data:roles') {
        grooflow_replace_roles($pdo, is_array($value) ? $value : []);

        return;
    }
    if ($key === 'settings:asistencia') {
        require_once __DIR__ . '/grooflow_asistencia.php';
        grooflow_asistencia_set_settings($pdo, is_array($value) ? $value : []);

        return;
    }
    if ($key === 'data:asistencia-snapshots') {
        require_once __DIR__ . '/grooflow_asistencia.php';
        grooflow_asistencia_replace_snapshots($pdo, is_array($value) ? $value : []);

        return;
    }
    if ($key === 'data:asistencia-operational') {
        require_once __DIR__ . '/grooflow_asistencia.php';
        grooflow_asistencia_set_operational($pdo, is_array($value) ? $value : []);

        return;
    }

    $table = grooflow_kv_array_tables()[$key] ?? null;
    if ($table !== null && is_array($value)) {
        if ($value === []) {
            $safe = str_replace('`', '', $table);
            $pdo->exec('DELETE FROM `' . $safe . '`');
        } else {
            grooflow_table_replace($pdo, $table, $value);
        }
    }

    $stmt = $pdo->prepare('
        INSERT INTO grooflow_kv (k, value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE value = VALUES(value)
    ');
    $stmt->execute([$key, grooflow_json_encode($value)]);
}

function grooflow_kv_delete(PDO $pdo, string $key): void
{
    grooflow_ensure_schema($pdo);
    $key = grooflow_normalize_kv_key($key);
    if (in_array($key, ['data:users', 'data:roles'], true)) {
        return;
    }
    $pdo->prepare('DELETE FROM grooflow_kv WHERE k = ?')->execute([$key]);
    $table = grooflow_kv_array_tables()[$key] ?? null;
    if ($table !== null) {
        $pdo->exec('DELETE FROM `' . str_replace('`', '', $table) . '`');
    }
}

function grooflow_normalize_kv_key(string $key): string
{
    return rawurldecode(trim($key));
}

/** @return list<array<string, mixed>> */
function grooflow_table_list(PDO $pdo, string $table): array
{
    $table = str_replace('`', '', $table);
    $rows = $pdo->query('SELECT payload FROM `' . $table . '` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
function grooflow_table_replace(PDO $pdo, string $table, array $items): void
{
    $table = str_replace('`', '', $table);
    $keep = [];
    $sql = '
        INSERT INTO `' . $table . '` (id, payload, location, usuario_id)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE payload = VALUES(payload), location = VALUES(location), usuario_id = VALUES(usuario_id)
    ';
    $stmt = $pdo->prepare($sql);
    foreach ($items as $item) {
        if (! is_array($item)) {
            continue;
        }
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $keep[] = $id;
        $location = isset($item['location']) ? (string) $item['location'] : (isset($item['homeBase']) ? (string) $item['homeBase'] : null);
        $usuarioId = null;
        if (isset($item['userId']) && ctype_digit((string) $item['userId'])) {
            $usuarioId = (int) $item['userId'];
        }
        $stmt->execute([$id, grooflow_json_encode($item), $location !== '' ? $location : null, $usuarioId]);
    }
    if ($keep === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($keep), '?'));
    $pdo->prepare('DELETE FROM `' . $table . '` WHERE id NOT IN (' . $placeholders . ')')->execute($keep);
}

function grooflow_table_upsert_one(PDO $pdo, string $table, array $item): array
{
    $id = trim((string) ($item['id'] ?? ''));
    if ($id === '') {
        throw new InvalidArgumentException('El registro necesita id');
    }
    $map = [];
    foreach (grooflow_table_list($pdo, $table) as $row) {
        $rid = trim((string) ($row['id'] ?? ''));
        if ($rid !== '') {
            $map[$rid] = $row;
        }
    }
    $map[$id] = $item;
    grooflow_table_replace($pdo, $table, array_values($map));
    foreach (grooflow_table_list($pdo, $table) as $row) {
        if ((string) ($row['id'] ?? '') === $id) {
            return $row;
        }
    }

    return $item;
}

function grooflow_table_delete_one(PDO $pdo, string $table, string $id): void
{
    $table = str_replace('`', '', $table);
    $pdo->prepare('DELETE FROM `' . $table . '` WHERE id = ?')->execute([$id]);
}

/** @return list<string> */
function grooflow_kv_bootstrap_keys(): array
{
    return [
        'settings:config',
        'settings:system',
        'settings:asistencia',
        'settings:turnos',
        'settings:accidentes-trabajo',
        'settings:entrega-uniformes',
        'settings:theme',
        'settings:alertThresholds',
        'maintenance:transactionsClearedAt',
        'data:asistencia-snapshots',
        'data:asistencia-operational',
        'data:transactions',
        'data:invoices',
        'data:providers',
        'data:products',
        'data:requests',
        'data:pettyCash',
        'data:pettyCashMeta',
        'data:sedes',
        'data:users',
        'data:roles',
        'data:feeReceipts',
        'data:treasuryInvoices',
        'data:treasuryBankBalance',
        'data:treasuryPaidHistory',
        'data:treasurySubscriptions',
        'data:treasuryBankMovements',
        'data:fleet',
        'data:inventory',
        'data:reconciliation',
        'settings:alertReadState',
    ];
}

/** @return array<string, mixed> */
function grooflow_kv_bootstrap(PDO $pdo): array
{
    $out = [];
    foreach (grooflow_kv_bootstrap_keys() as $key) {
        $out[$key] = grooflow_kv_get($pdo, $key);
    }

    return $out;
}
