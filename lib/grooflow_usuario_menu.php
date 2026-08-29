<?php

declare(strict_types=1);

require_once __DIR__ . '/grooflow_menu_permissions.php';

function grooflow_usuario_menu_table_exists(PDO $pdo): bool
{
    return table_exists($pdo, 'grooflow_usuario_menu');
}

function grooflow_usuario_menu_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_usuario_menu (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT UNSIGNED NOT NULL,
            menu_id INT UNSIGNED NOT NULL,
            estado ENUM('activo', 'inactivo') NOT NULL DEFAULT 'activo',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_gf_usuario_menu (usuario_id, menu_id),
            KEY idx_gf_usuario_menu_user (usuario_id),
            KEY idx_gf_usuario_menu_menu (menu_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function grooflow_usuario_menu_default_dashboard_id(PDO $pdo): ?int
{
    $stmt = $pdo->query('
        SELECT id FROM grooflow_menu_opciones
        WHERE es_padre = 0
          AND (
            LOWER(TRIM(modulo_key)) = \'dashboard\'
            OR LOWER(TRIM(texto)) = \'dashboard\'
            OR TRIM(ruta) IN (\'/\', \'/grooflow\', \'/grooflow/\')
          )
        ORDER BY CASE WHEN estado = \'activo\' THEN 0 ELSE 1 END, id ASC
        LIMIT 1
    ');
    $id = $stmt->fetchColumn();

    return $id ? (int) $id : null;
}

/** @return list<int> */
function grooflow_usuario_menu_filter_assignable_ids(PDO $pdo, array $menuIds): array
{
    if ($menuIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($menuIds), '?'));
    $stmt = $pdo->prepare("
        SELECT id FROM grooflow_menu_opciones
        WHERE id IN ({$placeholders})
          AND es_padre = 0
          AND estado = 'activo'
          AND TRIM(ruta) <> ''
    ");
    $stmt->execute(array_values($menuIds));

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/** @return list<int> */
function grooflow_usuario_menu_nivel_template_ids(PDO $pdo, int $nivelId): array
{
    if ($nivelId <= 0 || ! grooflow_nivel_menu_table_exists($pdo)) {
        return [];
    }
    $stmt = $pdo->prepare('
        SELECT nm.menu_id
        FROM grooflow_nivel_menu nm
        INNER JOIN grooflow_menu_opciones m ON m.id = nm.menu_id
        WHERE nm.nivel_id = ?
          AND nm.estado = \'activo\'
          AND nm.permiso_ver = 1
          AND m.es_padre = 0
          AND TRIM(m.ruta) <> \'\'
    ');
    $stmt->execute([$nivelId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/** @return list<array<string, mixed>> */
function grooflow_usuario_menu_unassigned_users(PDO $pdo): array
{
    if (! table_exists($pdo, 'app_usuarios')) {
        return [];
    }
    grooflow_usuario_menu_ensure_schema($pdo);
    $parts = [];
    if (grooflow_usuario_menu_table_exists($pdo)) {
        $parts[] = 'EXISTS (
            SELECT 1 FROM grooflow_usuario_menu um
            INNER JOIN grooflow_menu_opciones m ON m.id = um.menu_id AND m.es_padre = 0
            WHERE um.usuario_id = u.id AND um.estado = \'activo\'
              AND TRIM(m.ruta) <> \'\'
        )';
    }
    if (grooflow_nivel_menu_table_exists($pdo)) {
        $parts[] = 'EXISTS (
            SELECT 1 FROM grooflow_nivel_menu nm
            INNER JOIN grooflow_menu_opciones m ON m.id = nm.menu_id AND m.es_padre = 0
            WHERE nm.nivel_id = u.nivel_id AND nm.estado = \'activo\' AND nm.permiso_ver = 1
              AND TRIM(m.ruta) <> \'\'
        )';
    }
    $notExists = $parts === [] ? '1=1' : 'NOT (' . implode(' OR ', $parts) . ')';
    $rows = $pdo->query('
        SELECT u.id, u.username, u.nombre, u.apellido, u.email
        FROM app_usuarios u
        WHERE u.is_deleted = 0 AND u.estado = \'activo\'
          AND ' . $notExists . '
        ORDER BY u.nombre ASC, u.apellido ASC, u.username ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'nombre' => (string) ($row['nombre'] ?? ''),
        'apellido' => (string) ($row['apellido'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'nombre_completo' => trim(((string) ($row['nombre'] ?? '')) . ' ' . ((string) ($row['apellido'] ?? ''))),
    ], $rows);
}

function grooflow_usuario_menu_for_user(PDO $pdo, int $usuarioId): array
{
    grooflow_menu_sync_catalog($pdo);
    grooflow_usuario_menu_ensure_schema($pdo);
    grooflow_menu_permissions_ensure_schema($pdo);

    if ($usuarioId <= 0 || ! table_exists($pdo, 'app_usuarios')) {
        return [
            'usuario_id' => $usuarioId,
            'menus' => [],
            'menu_ids' => [],
            'nivel_menu_ids' => [],
            'extra_menu_ids' => [],
            'menu_source' => 'none',
            'nivel_nombre' => '',
            'has_personal_menu' => false,
        ];
    }

    $userStmt = $pdo->prepare('
        SELECT u.id, u.nivel_id, n.nombre AS nivel_nombre
        FROM app_usuarios u
        LEFT JOIN app_niveles n ON n.id = u.nivel_id
        WHERE u.id = ? AND u.is_deleted = 0
        LIMIT 1
    ');
    $userStmt->execute([$usuarioId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (! $user) {
        throw new InvalidArgumentException('Usuario no encontrado');
    }

    $nivelId = (int) ($user['nivel_id'] ?? 0);
    $nivelMenuIds = grooflow_usuario_menu_nivel_template_ids($pdo, $nivelId);
    $extraIds = [];
    if (grooflow_usuario_menu_table_exists($pdo)) {
        $stmt = $pdo->prepare('
            SELECT menu_id FROM grooflow_usuario_menu
            WHERE usuario_id = ? AND estado = \'activo\'
        ');
        $stmt->execute([$usuarioId]);
        $extraIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    $mergedIds = array_values(array_unique(array_merge($nivelMenuIds, $extraIds)));
    $menuSource = 'none';
    if ($extraIds !== [] && $nivelMenuIds !== []) {
        $menuSource = 'merged';
    } elseif ($extraIds !== []) {
        $menuSource = 'personal';
    } elseif ($nivelMenuIds !== []) {
        $menuSource = 'nivel';
    }

    $menus = grooflow_menu_list_tree($pdo);
    $menuIds = grooflow_usuario_menu_filter_assignable_ids($pdo, $mergedIds);

    return [
        'usuario_id' => $usuarioId,
        'menus' => $menus,
        'menu_ids' => $menuIds,
        'nivel_menu_ids' => $nivelMenuIds,
        'extra_menu_ids' => array_values(array_diff($extraIds, $nivelMenuIds)),
        'menu_source' => $menuSource,
        'nivel_nombre' => (string) ($user['nivel_nombre'] ?? ''),
        'has_personal_menu' => $extraIds !== [],
    ];
}

/** @param list<int> $menuIds */
function grooflow_usuario_menu_sync(PDO $pdo, int $usuarioId, array $menuIds): array
{
    grooflow_usuario_menu_ensure_schema($pdo);
    if ($usuarioId <= 0) {
        throw new InvalidArgumentException('Usuario inválido');
    }

    $userStmt = $pdo->prepare('SELECT nivel_id FROM app_usuarios WHERE id = ? AND is_deleted = 0 LIMIT 1');
    $userStmt->execute([$usuarioId]);
    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (! $userRow) {
        throw new InvalidArgumentException('Usuario no encontrado');
    }
    $nivelId = (int) ($userRow['nivel_id'] ?? 0);

    $nivelTemplate = grooflow_usuario_menu_nivel_template_ids($pdo, $nivelId);
    $requested = grooflow_usuario_menu_filter_assignable_ids($pdo, $menuIds);
    $extras = array_values(array_diff($requested, $nivelTemplate));

    $pdo->prepare('DELETE FROM grooflow_usuario_menu WHERE usuario_id = ?')->execute([$usuarioId]);
    if ($extras !== []) {
        $ins = $pdo->prepare('
            INSERT INTO grooflow_usuario_menu (usuario_id, menu_id, estado)
            VALUES (?, ?, \'activo\')
        ');
        foreach ($extras as $menuId) {
            $ins->execute([$usuarioId, $menuId]);
        }
    }

    return grooflow_usuario_menu_for_user($pdo, $usuarioId);
}

function grooflow_usuario_menu_assign_dashboard(PDO $pdo, ?int $usuarioId = null): int
{
    grooflow_usuario_menu_ensure_schema($pdo);
    $dashboardId = grooflow_usuario_menu_default_dashboard_id($pdo);
    if ($dashboardId === null) {
        throw new RuntimeException('No se encontró la opción Dashboard en el menú GrooFlow');
    }

    $assigned = 0;
    if ($usuarioId !== null && $usuarioId > 0) {
        $userStmt = $pdo->prepare('SELECT nivel_id FROM app_usuarios WHERE id = ? AND is_deleted = 0 LIMIT 1');
        $userStmt->execute([$usuarioId]);
        $nivelId = (int) $userStmt->fetchColumn();
        $nivelIds = grooflow_usuario_menu_nivel_template_ids($pdo, $nivelId);
        if (in_array($dashboardId, $nivelIds, true)) {
            return 0;
        }
        $pdo->prepare('
            INSERT INTO grooflow_usuario_menu (usuario_id, menu_id, estado)
            VALUES (?, ?, \'activo\')
            ON DUPLICATE KEY UPDATE estado = \'activo\', updated_at = NOW()
        ')->execute([$usuarioId, $dashboardId]);

        return 1;
    }

    foreach (grooflow_usuario_menu_unassigned_users($pdo) as $user) {
        grooflow_usuario_menu_assign_dashboard($pdo, (int) $user['id']);
        $assigned++;
    }

    return $assigned;
}

function grooflow_nivel_menu_apply_to_users(PDO $pdo, int $nivelId, bool $onlyWithExtras = true): int
{
    grooflow_usuario_menu_ensure_schema($pdo);
    if ($nivelId <= 0 || ! table_exists($pdo, 'app_usuarios')) {
        return 0;
    }
    $sql = 'SELECT id FROM app_usuarios WHERE is_deleted = 0 AND estado = \'activo\' AND nivel_id = ?';
    if ($onlyWithExtras) {
        $sql .= ' AND EXISTS (SELECT 1 FROM grooflow_usuario_menu um WHERE um.usuario_id = app_usuarios.id)';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$nivelId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($ids === []) {
        return 0;
    }
    $del = $pdo->prepare('DELETE FROM grooflow_usuario_menu WHERE usuario_id = ?');

    return array_reduce($ids, static function (int $count, int $uid) use ($del): int {
        $del->execute([$uid]);

        return $count + 1;
    }, 0);
}

/** @return list<array<string, mixed>> */
function grooflow_usuarios_list(PDO $pdo): array
{
    if (! table_exists($pdo, 'app_usuarios')) {
        return [];
    }
    $rows = $pdo->query('
        SELECT u.id, u.username, u.nombre, u.apellido, u.email, u.nivel_id, n.nombre AS nivel_nombre
        FROM app_usuarios u
        LEFT JOIN app_niveles n ON n.id = u.nivel_id
        WHERE u.is_deleted = 0
        ORDER BY u.nombre ASC, u.apellido ASC, u.username ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'nombre' => (string) ($row['nombre'] ?? ''),
        'apellido' => (string) ($row['apellido'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'nombre_completo' => trim(((string) ($row['nombre'] ?? '')) . ' ' . ((string) ($row['apellido'] ?? ''))),
        'nivel_id' => (int) ($row['nivel_id'] ?? 0),
        'nivel_nombre' => (string) ($row['nivel_nombre'] ?? ''),
    ], $rows);
}
