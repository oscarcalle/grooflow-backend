<?php

declare(strict_types=1);

/**
 * Listados paginados en servidor (tablas payload / KV / usuarios).
 *
 * @return array{items:list<mixed>,total:int,filtered:int,page:int,pageSize:int,totalPages:int,ids?:list<string>}
 */
function grooflow_lists_page(PDO $pdo, string $name, array $query): array
{
    grooflow_ensure_schema($pdo);
    $page = max(1, (int) ($query['page'] ?? 1));
    $pageSize = max(5, min(100, (int) ($query['pageSize'] ?? 25)));
    $search = trim((string) ($query['search'] ?? $query['q'] ?? ''));
    $idsOnly = ! empty($query['idsOnly']) || ! empty($query['ids_only']);

    if ($name === 'users') {
        return grooflow_lists_page_users($pdo, $page, $pageSize, $search, $idsOnly, $query);
    }
    if ($name === 'chartOfAccounts' || $name === 'chart-of-accounts') {
        return grooflow_lists_page_array(grooflow_kv_get($pdo, 'data:chartOfAccounts'), $page, $pageSize, $search, ['code', 'name', 'centroCosto', 'parentCode'], $idsOnly);
    }
    if ($name === 'inventory-equipment') {
        $raw = grooflow_kv_get($pdo, 'data:inventory');
        $equipment = is_array($raw) && isset($raw['equipment']) && is_array($raw['equipment']) ? $raw['equipment'] : [];

        return grooflow_lists_page_array($equipment, $page, $pageSize, $search, ['name', 'code', 'serial', 'sede', 'category', 'status'], $idsOnly);
    }

    $table = grooflow_collection_table($name);
    if ($table === null) {
        throw new InvalidArgumentException('Listado desconocido');
    }

    return grooflow_lists_page_payload_table($pdo, $table, $page, $pageSize, $search, $idsOnly, $query);
}

/**
 * @param array<string, mixed> $query
 * @return array{items:list<mixed>,total:int,filtered:int,page:int,pageSize:int,totalPages:int,ids?:list<string>}
 */
function grooflow_lists_page_payload_table(
    PDO $pdo,
    string $table,
    int $page,
    int $pageSize,
    string $search,
    bool $idsOnly,
    array $query = []
): array {
    $table = str_replace('`', '', $table);
    $where = ['1=1'];
    $params = [];
    if ($search !== '') {
        $where[] = 'CAST(payload AS CHAR) LIKE ?';
        $params[] = '%' . $search . '%';
    }
    $status = trim((string) ($query['status'] ?? ''));
    $tab = trim((string) ($query['tab'] ?? ''));
    if (in_array($tab, ['pending', 'approved', 'rejected'], true)) {
        $status = $tab;
    }
    if ($status !== '' && $status !== 'all') {
        $where[] = "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.status')) = ?";
        $params[] = $status;
    } elseif ($tab === 'history') {
        $where[] = "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.status')) <> 'pending'";
    }
    foreach (['week' => '$.week', 'weekNumber' => '$.weekNumber', 'sede' => '$.sede'] as $extra => $jsonPath) {
        $val = trim((string) ($query[$extra] ?? ''));
        if ($val === '' || $val === 'all') {
            continue;
        }
        $where[] = 'JSON_UNQUOTE(JSON_EXTRACT(payload, \'' . $jsonPath . '\')) = ?';
        $params[] = $val;
    }
    $whereSql = implode(' AND ', $where);

    $total = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$whereSql}");
    $countStmt->execute($params);
    $filtered = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil(($filtered > 0 ? $filtered : 1) / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    if ($idsOnly) {
        $stmt = $pdo->prepare("SELECT id FROM `{$table}` WHERE {$whereSql} ORDER BY id");
        $stmt->execute($params);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[] = (string) $id;
        }

        return [
            'items' => [],
            'ids' => $ids,
            'total' => $total,
            'filtered' => $filtered,
            'page' => 1,
            'pageSize' => $filtered,
            'totalPages' => 1,
        ];
    }

    $offset = ($page - 1) * $pageSize;
    $stmt = $pdo->prepare("SELECT payload FROM `{$table}` WHERE {$whereSql} ORDER BY updated_at DESC, id DESC LIMIT {$pageSize} OFFSET {$offset}");
    $stmt->execute($params);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $item = grooflow_json_decode((string) ($row['payload'] ?? ''));
        if (is_array($item)) {
            $items[] = $item;
        }
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

/**
 * @param mixed $raw
 * @param list<string> $fields
 * @return array{items:list<mixed>,total:int,filtered:int,page:int,pageSize:int,totalPages:int,ids?:list<string>}
 */
function grooflow_lists_page_array($raw, int $page, int $pageSize, string $search, array $fields, bool $idsOnly): array
{
    $all = is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    $q = mb_strtolower($search);
    $filtered = $all;
    if ($q !== '') {
        $filtered = array_values(array_filter($all, static function ($row) use ($q, $fields) {
            foreach ($fields as $f) {
                $v = mb_strtolower((string) ($row[$f] ?? ''));
                if ($v !== '' && str_contains($v, $q)) {
                    return true;
                }
            }
            $blob = mb_strtolower(json_encode($row, JSON_UNESCAPED_UNICODE) ?: '');

            return str_contains($blob, $q);
        }));
    }
    $total = count($all);
    $filteredCount = count($filtered);
    $totalPages = max(1, (int) ceil(($filteredCount > 0 ? $filteredCount : 1) / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    if ($idsOnly) {
        $ids = [];
        foreach ($filtered as $row) {
            $ids[] = (string) ($row['id'] ?? $row['code'] ?? '');
        }

        return [
            'items' => [],
            'ids' => array_values(array_filter($ids, static fn ($id) => $id !== '')),
            'total' => $total,
            'filtered' => $filteredCount,
            'page' => 1,
            'pageSize' => $filteredCount,
            'totalPages' => 1,
        ];
    }
    $offset = ($page - 1) * $pageSize;
    $items = array_slice($filtered, $offset, $pageSize);

    return [
        'items' => $items,
        'total' => $total,
        'filtered' => $filteredCount,
        'page' => $page,
        'pageSize' => $pageSize,
        'totalPages' => $totalPages,
    ];
}

/**
 * @return array{items:list<mixed>,total:int,filtered:int,page:int,pageSize:int,totalPages:int,ids?:list<string>}
 */
function grooflow_lists_page_users(PDO $pdo, int $page, int $pageSize, string $search, bool $idsOnly, array $query = []): array
{
    if (! table_exists($pdo, 'app_usuarios')) {
        return ['items' => [], 'total' => 0, 'filtered' => 0, 'page' => 1, 'pageSize' => $pageSize, 'totalPages' => 1];
    }
    $where = ['u.is_deleted = 0'];
    $params = [];
    if ($search !== '') {
        $where[] = '(u.nombre LIKE ? OR u.apellido LIKE ? OR u.email LIKE ? OR u.username LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $status = trim((string) ($query['status'] ?? ''));
    if ($status === 'active') {
        $where[] = "u.estado = 'activo'";
    } elseif ($status === 'inactive') {
        $where[] = "u.estado = 'inactivo'";
    }
    $role = trim((string) ($query['role'] ?? ''));
    if ($role !== '' && $role !== 'all') {
        $where[] = 'EXISTS (SELECT 1 FROM grooflow_perfiles gp WHERE gp.usuario_id = u.id AND gp.role_id = ?)';
        $params[] = $role;
    }
    $whereSql = implode(' AND ', $where);
    $total = (int) $pdo->query('SELECT COUNT(*) FROM app_usuarios WHERE is_deleted = 0')->fetchColumn();
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM app_usuarios u WHERE {$whereSql}");
    $countStmt->execute($params);
    $filtered = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil(($filtered > 0 ? $filtered : 1) / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    if ($idsOnly) {
        $stmt = $pdo->prepare("SELECT u.id FROM app_usuarios u WHERE {$whereSql} ORDER BY u.id");
        $stmt->execute($params);
        $ids = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        return [
            'items' => [],
            'ids' => $ids,
            'total' => $total,
            'filtered' => $filtered,
            'page' => 1,
            'pageSize' => $filtered,
            'totalPages' => 1,
        ];
    }
    $offset = ($page - 1) * $pageSize;
    $stmt = $pdo->prepare("
        SELECT u.*, n.nombre AS nivel_nombre
        FROM app_usuarios u
        LEFT JOIN app_niveles n ON n.id = u.nivel_id
        WHERE {$whereSql}
        ORDER BY u.id ASC
        LIMIT {$pageSize} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = grooflow_user_to_app($pdo, $row);
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

/**
 * @param list<string> $ids
 * @return array{deleted:int}
 */
function grooflow_lists_delete(PDO $pdo, string $name, array $ids, bool $allMatching, string $search): array
{
    grooflow_ensure_schema($pdo);
    grooflow_enforce_collection_write($pdo, $name);

    if ($name === 'users') {
        throw new RuntimeException('Los usuarios se eliminan desde el módulo de usuarios');
    }
    if ($name === 'chartOfAccounts' || $name === 'inventory-equipment') {
        throw new RuntimeException('Este listado no admite borrado masivo por API');
    }

    $table = grooflow_collection_table($name);
    if ($table === null) {
        throw new InvalidArgumentException('Listado desconocido');
    }
    $table = str_replace('`', '', $table);

    if ($allMatching) {
        $page = grooflow_lists_page_payload_table($pdo, $table, 1, 25, $search, true, []);
        $ids = $page['ids'] ?? [];
    }
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static fn ($id) => $id !== '')));
    if ($ids === []) {
        return ['deleted' => 0];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE id IN ({$placeholders})");
    $stmt->execute($ids);
    $deleted = $stmt->rowCount();
    grooflow_kv_set($pdo, grooflow_collection_kv_key($name), grooflow_table_list($pdo, $table));

    return ['deleted' => $deleted];
}
