<?php

declare(strict_types=1);

require_once __DIR__ . '/grooflow_menu_permissions.php';

function grooflow_menu_table_exists(PDO $pdo): bool
{
    return table_exists($pdo, 'grooflow_menu_opciones');
}

function grooflow_nivel_menu_table_exists(PDO $pdo): bool
{
    return table_exists($pdo, 'grooflow_nivel_menu');
}

function grooflow_menu_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_menu_opciones (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            texto VARCHAR(160) NOT NULL,
            icono VARCHAR(80) NULL,
            ruta VARCHAR(190) NOT NULL DEFAULT '',
            modulo_key VARCHAR(80) NOT NULL DEFAULT '',
            es_padre TINYINT(1) NOT NULL DEFAULT 0,
            padre_id INT UNSIGNED NULL,
            orden INT NOT NULL DEFAULT 0,
            estado ENUM('activo', 'inactivo') NOT NULL DEFAULT 'activo',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_gf_menu_padre (padre_id),
            KEY idx_gf_menu_orden (orden),
            KEY idx_gf_menu_modulo (modulo_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_nivel_menu (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nivel_id INT UNSIGNED NOT NULL,
            menu_id INT UNSIGNED NOT NULL,
            permiso_ver TINYINT(1) NOT NULL DEFAULT 1,
            estado ENUM('activo', 'inactivo') NOT NULL DEFAULT 'activo',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_gf_nivel_menu (nivel_id, menu_id),
            KEY idx_gf_nivel_menu_nivel (nivel_id),
            KEY idx_gf_nivel_menu_menu (menu_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** @return list<array{section:string,label:string,ruta:string,modulo_key:string,icono:string}> */
function grooflow_menu_default_leaves(): array
{
    return [
        ['section' => 'Principal', 'label' => 'Dashboard', 'ruta' => '/', 'modulo_key' => 'Dashboard', 'icono' => 'fa-gauge-high'],
        ['section' => 'Principal', 'label' => 'Alertas', 'ruta' => '/alertas', 'modulo_key' => 'Alertas', 'icono' => 'fa-bell'],
        ['section' => 'Principal', 'label' => 'Analítica', 'ruta' => '/analitica', 'modulo_key' => 'Analítica', 'icono' => 'fa-chart-line'],
        ['section' => 'Finanzas', 'label' => 'Tesorería', 'ruta' => '/tesoreria', 'modulo_key' => 'Tesorería', 'icono' => 'fa-landmark'],
        ['section' => 'Finanzas', 'label' => 'Transacciones', 'ruta' => '/transacciones', 'modulo_key' => 'Transacciones', 'icono' => 'fa-right-left'],
        ['section' => 'Finanzas', 'label' => 'Flujo de Caja', 'ruta' => '/flujo-caja', 'modulo_key' => 'Flujo de Caja', 'icono' => 'fa-water'],
        ['section' => 'Finanzas', 'label' => 'Estado de Resultados', 'ruta' => '/estado-resultados', 'modulo_key' => 'Estado de Resultados', 'icono' => 'fa-chart-pie'],
        ['section' => 'Finanzas', 'label' => 'Reportes', 'ruta' => '/reportes', 'modulo_key' => 'Reportes', 'icono' => 'fa-file-lines'],
        ['section' => 'Finanzas', 'label' => 'Caja Chica', 'ruta' => '/caja-chica', 'modulo_key' => 'Caja Chica', 'icono' => 'fa-wallet'],
        ['section' => 'Finanzas', 'label' => 'Honorarios', 'ruta' => '/honorarios', 'modulo_key' => 'Honorarios', 'icono' => 'fa-file-invoice-dollar'],
        ['section' => 'Gestión', 'label' => 'Proveedores', 'ruta' => '/proveedores', 'modulo_key' => 'Proveedores', 'icono' => 'fa-truck'],
        ['section' => 'Gestión', 'label' => 'Contabilidad', 'ruta' => '/contabilidad', 'modulo_key' => 'Contabilidad', 'icono' => 'fa-book'],
        ['section' => 'Gestión', 'label' => 'Compras', 'ruta' => '/solicitudes', 'modulo_key' => 'Compras', 'icono' => 'fa-cart-shopping'],
        ['section' => 'Gestión', 'label' => 'Productos', 'ruta' => '/productos', 'modulo_key' => 'Productos', 'icono' => 'fa-box'],
        ['section' => 'Gestión', 'label' => 'Auditoría', 'ruta' => '/auditoria', 'modulo_key' => 'Auditoría', 'icono' => 'fa-shield-halved'],
        ['section' => 'Gestión', 'label' => 'Flota clínica', 'ruta' => '/flota-clinica', 'modulo_key' => 'Gestión Vehicular', 'icono' => 'fa-car'],
        ['section' => 'Gestión', 'label' => 'Inventario equipos', 'ruta' => '/inventario-equipos', 'modulo_key' => 'Gestión de Inventario', 'icono' => 'fa-warehouse'],
        ['section' => 'Gestión', 'label' => 'Asistencia', 'ruta' => '/asistencia', 'modulo_key' => 'Asistencia', 'icono' => 'fa-user-clock'],
        ['section' => 'Gestión', 'label' => 'Turnos', 'ruta' => '/turnos', 'modulo_key' => 'Turnos', 'icono' => 'fa-calendar-days'],
        ['section' => 'Gestión', 'label' => 'Accidentes de Trabajo', 'ruta' => '/accidentes-trabajo', 'modulo_key' => 'Accidentes de Trabajo', 'icono' => 'fa-hard-hat'],
        ['section' => 'Gestión', 'label' => 'Entrega de Uniformes', 'ruta' => '/entrega-uniformes', 'modulo_key' => 'Entrega de Uniformes', 'icono' => 'fa-shirt'],
        ['section' => 'Gestión', 'label' => 'Conciliación', 'ruta' => '/conciliacion', 'modulo_key' => 'Conciliación', 'icono' => 'fa-scale-balanced'],
        ['section' => 'Administración', 'label' => 'Configuración', 'ruta' => '/configuracion', 'modulo_key' => 'Configuración', 'icono' => 'fa-sliders'],
        ['section' => 'Administración', 'label' => 'Configuración de menú', 'ruta' => '/config/menu', 'modulo_key' => 'Admin Menú GrooFlow', 'icono' => 'fa-list-tree'],
        ['section' => 'Administración', 'label' => 'Asignación de menú', 'ruta' => '/config/asignacion-menu', 'modulo_key' => 'Asignación Menú GrooFlow', 'icono' => 'fa-table-cells'],
    ];
}

function grooflow_menu_ensure_seed(PDO $pdo): void
{
    grooflow_menu_sync_catalog($pdo);
}

/** Sincroniza catálogo GrooFlow (idempotente): secciones, opciones y asignación base. */
function grooflow_menu_sync_catalog(PDO $pdo): void
{
    grooflow_menu_ensure_schema($pdo);

    $parents = [];
    $orderSection = 10;
    foreach (['Principal', 'Finanzas', 'Gestión', 'Administración'] as $section) {
        $stmt = $pdo->prepare('
            SELECT id FROM grooflow_menu_opciones
            WHERE es_padre = 1 AND texto = ?
            LIMIT 1
        ');
        $stmt->execute([$section]);
        $id = (int) $stmt->fetchColumn();
        if ($id <= 0) {
            $ins = $pdo->prepare('
                INSERT INTO grooflow_menu_opciones (texto, icono, ruta, modulo_key, es_padre, padre_id, orden, estado)
                VALUES (?, ?, ?, ?, 1, NULL, ?, \'activo\')
            ');
            $ins->execute([$section, 'fa-folder', '', '', $orderSection]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE grooflow_menu_opciones SET orden = ?, estado = \'activo\' WHERE id = ?')->execute([$orderSection, $id]);
        }
        $parents[$section] = $id;
        $orderSection += 10;
    }

    $orderLeaf = 1;
    $findLeaf = $pdo->prepare('
        SELECT id FROM grooflow_menu_opciones
        WHERE es_padre = 0 AND (modulo_key = ? OR ruta = ?)
        LIMIT 1
    ');
    $insertLeaf = $pdo->prepare('
        INSERT INTO grooflow_menu_opciones (texto, icono, ruta, modulo_key, es_padre, padre_id, orden, estado)
        VALUES (?, ?, ?, ?, 0, ?, ?, \'activo\')
    ');
    $updateLeaf = $pdo->prepare('
        UPDATE grooflow_menu_opciones
        SET texto = ?, icono = ?, ruta = ?, modulo_key = ?, padre_id = ?, orden = ?, estado = \'activo\', updated_at = NOW()
        WHERE id = ?
    ');

    foreach (grooflow_menu_default_leaves() as $leaf) {
        $padreId = $parents[$leaf['section']] ?? null;
        if ($padreId === null) {
            continue;
        }
        $findLeaf->execute([$leaf['modulo_key'], $leaf['ruta']]);
        $existingId = (int) $findLeaf->fetchColumn();
        if ($existingId > 0) {
            $updateLeaf->execute([
                $leaf['label'],
                $leaf['icono'],
                $leaf['ruta'],
                $leaf['modulo_key'],
                $padreId,
                $orderLeaf,
                $existingId,
            ]);
        } else {
            $insertLeaf->execute([
                $leaf['label'],
                $leaf['icono'],
                $leaf['ruta'],
                $leaf['modulo_key'],
                $padreId,
                $orderLeaf,
            ]);
        }
        $orderLeaf++;
    }

    grooflow_menu_assign_full_access_niveles($pdo);
}

function grooflow_menu_assign_full_access_niveles(PDO $pdo): void
{
    if (! table_exists($pdo, 'app_niveles') || ! grooflow_nivel_menu_table_exists($pdo)) {
        return;
    }

    $menuIds = $pdo->query('SELECT id FROM grooflow_menu_opciones WHERE es_padre = 0 AND estado = \'activo\'')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($menuIds === []) {
        return;
    }

    $assign = $pdo->prepare('
        INSERT IGNORE INTO grooflow_nivel_menu (nivel_id, menu_id, permiso_ver, estado)
        VALUES (?, ?, 1, \'activo\')
    ');
    foreach ([1, 2] as $nivelId) {
        $check = $pdo->prepare('SELECT id FROM app_niveles WHERE id = ? LIMIT 1');
        $check->execute([$nivelId]);
        if (! $check->fetchColumn()) {
            continue;
        }
        foreach ($menuIds as $menuId) {
            $assign->execute([$nivelId, (int) $menuId]);
        }
    }
}

function grooflow_menu_public_row(array $row, ?string $padreTexto = null): array
{
    $iconoRaw = (string) ($row['icono'] ?? '');
    $ruta = (string) ($row['ruta'] ?? '');
    $moduloKey = (string) ($row['modulo_key'] ?? '');
    $actions = grooflow_menu_actions_for_route($ruta, $moduloKey);

    return [
        'id' => (int) $row['id'],
        'texto' => (string) $row['texto'],
        'icono' => $iconoRaw,
        'icono_fa' => grooflow_menu_icon_normalize($iconoRaw),
        'ruta' => $ruta,
        'modulo_key' => $moduloKey,
        'es_padre' => (int) ($row['es_padre'] ?? 0),
        'padre_id' => isset($row['padre_id']) && $row['padre_id'] !== null ? (int) $row['padre_id'] : null,
        'padre_texto' => $padreTexto ?? '',
        'orden' => (int) ($row['orden'] ?? 0),
        'estado' => (string) ($row['estado'] ?? 'activo'),
        'acciones_disponibles' => array_map(static fn (string $key): array => [
            'clave' => $key,
            'texto' => grooflow_menu_action_label($key),
        ], $actions),
    ];
}

function grooflow_menu_icon_normalize(string $icono): string
{
    $icono = trim($icono);
    if ($icono === '') {
        return 'fa-circle';
    }
    if (str_starts_with($icono, 'fa-')) {
        return $icono;
    }

    return 'fa-' . ltrim($icono, 'fa-');
}

/** @return list<array<string, mixed>> */
function grooflow_menu_list_tree(PDO $pdo): array
{
    grooflow_menu_sync_catalog($pdo);
    $rows = $pdo->query('
        SELECT m.*, p.texto AS padre_texto
        FROM grooflow_menu_opciones m
        LEFT JOIN grooflow_menu_opciones p ON p.id = m.padre_id
        ORDER BY m.es_padre DESC, m.orden ASC, m.id ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(
        static fn (array $row): array => grooflow_menu_public_row($row, (string) ($row['padre_texto'] ?? '')),
        $rows
    );
}

/** @return list<array<string, mixed>> */
function grooflow_menu_list_all(PDO $pdo): array
{
    return grooflow_menu_list_tree($pdo);
}

/** @return array{sections:list<array>,orphans:list<array>,items:list<array>} */
function grooflow_menu_tree(PDO $pdo): array
{
    grooflow_menu_sync_catalog($pdo);
    $items = grooflow_menu_list_all($pdo);
    $parents = [];
    $childrenByParent = [];
    $orphans = [];

    foreach ($items as $item) {
        if ((int) $item['es_padre'] === 1) {
            $parents[(int) $item['id']] = $item;
        }
    }

    foreach ($items as $item) {
        if ((int) $item['es_padre'] === 1) {
            continue;
        }
        $padreId = (int) ($item['padre_id'] ?? 0);
        if ($padreId > 0 && isset($parents[$padreId])) {
            $childrenByParent[$padreId][] = $item;
        } else {
            $orphans[] = $item;
        }
    }

    $sections = [];
    uasort($parents, static fn (array $a, array $b): int => ((int) $a['orden']) <=> ((int) $b['orden']));
    foreach ($parents as $parentId => $parent) {
        $children = $childrenByParent[$parentId] ?? [];
        usort($children, static fn (array $a, array $b): int => ((int) $a['orden']) <=> ((int) $b['orden']));
        $sections[] = [
            'section' => $parent,
            'children' => $children,
        ];
    }

    usort($orphans, static fn (array $a, array $b): int => ((int) $a['orden']) <=> ((int) $b['orden']));

    return [
        'sections' => $sections,
        'orphans' => $orphans,
        'items' => $items,
    ];
}

/** @param array<string, mixed> $data */
function grooflow_menu_create(PDO $pdo, array $data): array
{
    grooflow_menu_ensure_seed($pdo);
    $texto = trim((string) ($data['texto'] ?? ''));
    if ($texto === '') {
        throw new InvalidArgumentException('El texto es obligatorio');
    }
    $esPadre = ! empty($data['es_padre']) ? 1 : 0;
    $padreId = isset($data['padre_id']) ? (int) $data['padre_id'] : 0;
    $ruta = trim((string) ($data['ruta'] ?? ''));
    $moduloKey = trim((string) ($data['modulo_key'] ?? ''));
    if ($esPadre === 0 && $ruta === '') {
        throw new InvalidArgumentException('La ruta es obligatoria para opciones de menú');
    }
    $orden = (int) ($data['orden'] ?? 0);
    if ($orden <= 0) {
        $orden = (int) $pdo->query('SELECT COALESCE(MAX(orden), 0) + 1 FROM grooflow_menu_opciones')->fetchColumn();
    }
    $stmt = $pdo->prepare('
        INSERT INTO grooflow_menu_opciones (texto, icono, ruta, modulo_key, es_padre, padre_id, orden, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $texto,
        trim((string) ($data['icono'] ?? 'fa-circle')),
        $ruta,
        $moduloKey !== '' ? $moduloKey : $texto,
        $esPadre,
        $padreId > 0 ? $padreId : null,
        $orden,
        (string) ($data['estado'] ?? 'activo') === 'inactivo' ? 'inactivo' : 'activo',
    ]);
    $id = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM grooflow_menu_opciones WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return grooflow_menu_public_row($row ?: []);
}

/** @param array<string, mixed> $data */
function grooflow_menu_update(PDO $pdo, int $id, array $data): array
{
    grooflow_menu_ensure_seed($pdo);
    if ($id <= 0) {
        throw new InvalidArgumentException('ID inválido');
    }
    $stmt = $pdo->prepare('SELECT * FROM grooflow_menu_opciones WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (! $current) {
        throw new RuntimeException('Opción de menú no encontrada');
    }
    $texto = trim((string) ($data['texto'] ?? $current['texto']));
    $icono = trim((string) ($data['icono'] ?? $current['icono'] ?? 'fa-circle'));
    $ruta = array_key_exists('ruta', $data) ? trim((string) $data['ruta']) : (string) ($current['ruta'] ?? '');
    $moduloKey = trim((string) ($data['modulo_key'] ?? $current['modulo_key'] ?? ''));
    $estado = (string) ($data['estado'] ?? $current['estado'] ?? 'activo');
    $padreId = array_key_exists('padre_id', $data) ? (int) $data['padre_id'] : (int) ($current['padre_id'] ?? 0);
    $orden = array_key_exists('orden', $data) ? (int) $data['orden'] : (int) ($current['orden'] ?? 0);
    $pdo->prepare('
        UPDATE grooflow_menu_opciones
        SET texto = ?, icono = ?, ruta = ?, modulo_key = ?, padre_id = ?, orden = ?, estado = ?, updated_at = NOW()
        WHERE id = ?
    ')->execute([
        $texto,
        $icono,
        $ruta,
        $moduloKey !== '' ? $moduloKey : $texto,
        $padreId > 0 ? $padreId : null,
        $orden,
        $estado === 'inactivo' ? 'inactivo' : 'activo',
        $id,
    ]);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return grooflow_menu_public_row($row ?: []);
}

function grooflow_menu_delete(PDO $pdo, int $id): void
{
    if ($id <= 0) {
        throw new InvalidArgumentException('ID inválido');
    }
    $pdo->prepare('DELETE FROM grooflow_nivel_menu WHERE menu_id = ?')->execute([$id]);
    $pdo->prepare('UPDATE grooflow_menu_opciones SET padre_id = NULL WHERE padre_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM grooflow_menu_opciones WHERE id = ?')->execute([$id]);
}

/** @param list<array{id:int,orden:int,padre_id?:int|null,es_padre?:int}> $items */
function grooflow_menu_reorder(PDO $pdo, array $items): array
{
    $stmt = $pdo->prepare('UPDATE grooflow_menu_opciones SET orden = ?, padre_id = ?, es_padre = ?, updated_at = NOW() WHERE id = ?');
    foreach ($items as $item) {
        $id = (int) ($item['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $padreId = isset($item['padre_id']) ? (int) $item['padre_id'] : 0;
        $esPadre = (int) ($item['es_padre'] ?? 0);
        $stmt->execute([
            (int) ($item['orden'] ?? 0),
            $padreId > 0 ? $padreId : null,
            $esPadre === 1 ? 1 : 0,
            $id,
        ]);
    }

    return grooflow_menu_list_tree($pdo);
}

/** @return list<array<string, mixed>> */
function grooflow_niveles_list(PDO $pdo): array
{
    if (! table_exists($pdo, 'app_niveles')) {
        return [];
    }
    $rows = $pdo->query('
        SELECT id, nombre, descripcion, estado
        FROM app_niveles
        ORDER BY id ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        $id = (int) $row['id'];

        return [
            'id' => $id,
            'nombre' => (string) $row['nombre'],
            'descripcion' => (string) ($row['descripcion'] ?? ''),
            'estado' => (string) ($row['estado'] ?? 'activo'),
            'full_access' => in_array($id, [1, 2], true),
        ];
    }, $rows);
}

/** @return array{niveles:list<array>,rows:list<array>} */
function grooflow_nivel_menu_matrix(PDO $pdo): array
{
    grooflow_menu_ensure_seed($pdo);
    $niveles = grooflow_niveles_list($pdo);
    $tree = grooflow_menu_tree($pdo);
    $assigned = [];
    if (grooflow_nivel_menu_table_exists($pdo)) {
        $stmt = $pdo->query("SELECT nivel_id, menu_id FROM grooflow_nivel_menu WHERE estado = 'activo' AND permiso_ver = 1");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $assigned[(int) $row['nivel_id']][(int) $row['menu_id']] = true;
        }
    }

    $rows = [];
    foreach ($tree['sections'] as $block) {
        $parent = $block['section'];
        $children = $block['children'];
        if ($children === []) {
            continue;
        }
        $rows[] = [
            'kind' => 'parent',
            'id' => (int) $parent['id'],
            'texto' => (string) $parent['texto'],
            'icono' => (string) ($parent['icono'] ?? ''),
        ];
        foreach ($children as $child) {
            $childId = (int) $child['id'];
            $assignedMap = [];
            foreach ($niveles as $nivel) {
                $nivelId = (int) $nivel['id'];
                $assignedMap[(string) $nivelId] = ! empty($nivel['full_access']) || ! empty($assigned[$nivelId][$childId]);
            }
            $rows[] = [
                'kind' => 'child',
                'id' => $childId,
                'padre_id' => (int) ($child['padre_id'] ?? 0),
                'texto' => (string) $child['texto'],
                'ruta' => (string) ($child['ruta'] ?? ''),
                'modulo_key' => (string) ($child['modulo_key'] ?? ''),
                'assigned' => $assignedMap,
            ];
        }
    }

    return ['niveles' => $niveles, 'rows' => $rows];
}

/** @return array{full_access:bool,menu_ids:list<int>,items:list<array>,usuarios_activos:int,nivel_id:int} */
function grooflow_nivel_menu_for_nivel(PDO $pdo, int $nivelId): array
{
    grooflow_menu_sync_catalog($pdo);
    grooflow_menu_permissions_ensure_schema($pdo);
    if ($nivelId <= 0) {
        return ['nivel_id' => $nivelId, 'full_access' => false, 'menu_ids' => [], 'items' => [], 'usuarios_activos' => 0];
    }

    require_once __DIR__ . '/grooflow_usuario_menu.php';

    $menus = grooflow_menu_list_tree($pdo);
    $fullAccess = in_array($nivelId, [1, 2], true);
    $map = [];
    if (grooflow_nivel_menu_table_exists($pdo)) {
        $assigned = $pdo->prepare('
            SELECT menu_id, estado, permiso_ver, permiso_agregar, permiso_editar,
                   permiso_eliminar, permiso_exportar, permiso_configurar
            FROM grooflow_nivel_menu
            WHERE nivel_id = ?
        ');
        $assigned->execute([$nivelId]);
        foreach ($assigned->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['menu_id']] = $row;
        }
    }

    $items = [];
    foreach ($menus as $menu) {
        $menuId = (int) $menu['id'];
        $row = $map[$menuId] ?? null;
        $permissions = grooflow_menu_permissions_from_row($row, $fullAccess);
        $available = array_column($menu['acciones_disponibles'] ?? [], 'clave');
        foreach (GROOFLOW_MENU_PERMISSION_ACTIONS as $action) {
            if (! in_array($action, $available, true)) {
                $permissions[$action] = false;
            }
        }
        $items[] = [
            ...$menu,
            'asignado' => $fullAccess || ($row !== null && ($row['estado'] ?? '') === 'activo' && (bool) ($row['permiso_ver'] ?? false)),
            'permiso_estado' => $fullAccess ? 'activo' : ($row['estado'] ?? null),
            'permisos' => $permissions,
        ];
    }

    $userCount = 0;
    if (table_exists($pdo, 'app_usuarios')) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM app_usuarios WHERE nivel_id = ? AND is_deleted = 0 AND estado = \'activo\'');
        $stmt->execute([$nivelId]);
        $userCount = (int) $stmt->fetchColumn();
    }

    $menuIds = $fullAccess
        ? grooflow_usuario_menu_filter_assignable_ids($pdo, array_map(static fn (array $m): int => (int) $m['id'], $menus))
        : grooflow_usuario_menu_filter_assignable_ids($pdo, array_map(
            'intval',
            array_keys(array_filter(
                $map,
                static fn (array $row): bool => ($row['estado'] ?? '') === 'activo' && (bool) ($row['permiso_ver'] ?? false),
            )),
        ));

    return [
        'nivel_id' => $nivelId,
        'full_access' => $fullAccess,
        'menu_ids' => $menuIds,
        'items' => $items,
        'menus' => $items,
        'usuarios_activos' => $userCount,
    ];
}

/**
 * @param list<int> $menuIds
 * @param array<int|string, array<string, mixed>> $permissionsByMenu
 */
function grooflow_nivel_menu_sync(PDO $pdo, int $nivelId, array $menuIds, array $permissionsByMenu = []): array
{
    grooflow_menu_ensure_seed($pdo);
    grooflow_menu_permissions_ensure_schema($pdo);
    require_once __DIR__ . '/grooflow_usuario_menu.php';

    if ($nivelId <= 0) {
        throw new InvalidArgumentException('Perfil inválido');
    }
    if (in_array($nivelId, [1, 2], true)) {
        return grooflow_nivel_menu_for_nivel($pdo, $nivelId);
    }

    $requested = array_values(array_unique(array_filter(array_map('intval', $menuIds), static fn (int $id): bool => $id > 0)));
    $menuIds = grooflow_usuario_menu_filter_assignable_ids($pdo, $requested);
    if ($requested !== [] && $menuIds === []) {
        throw new InvalidArgumentException(
            'Solo puede asignar opciones de menú con ruta (no secciones padre). Marque al menos un submenú.'
        );
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM grooflow_nivel_menu WHERE nivel_id = ?')->execute([$nivelId]);
        if ($menuIds !== []) {
            $stmt = $pdo->prepare('
                INSERT INTO grooflow_nivel_menu (
                    nivel_id, menu_id, estado, permiso_ver, permiso_agregar, permiso_editar,
                    permiso_eliminar, permiso_exportar, permiso_configurar
                ) VALUES (?, ?, \'activo\', 1, ?, ?, ?, ?, ?)
            ');
            foreach ($menuIds as $menuId) {
                $perm = is_array($permissionsByMenu[$menuId] ?? null)
                    ? $permissionsByMenu[$menuId]
                    : (is_array($permissionsByMenu[(string) $menuId] ?? null) ? $permissionsByMenu[(string) $menuId] : []);
                $stmt->execute([
                    $nivelId,
                    $menuId,
                    ! empty($perm['agregar']) ? 1 : 0,
                    ! empty($perm['editar']) ? 1 : 0,
                    ! empty($perm['eliminar']) ? 1 : 0,
                    ! empty($perm['exportar']) ? 1 : 0,
                    ! empty($perm['configurar']) ? 1 : 0,
                ]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return grooflow_nivel_menu_for_nivel($pdo, $nivelId);
}

/** @return array<string, bool> */
function grooflow_menu_permissions_for_nivel(PDO $pdo, int $nivelId): array
{
    grooflow_menu_ensure_seed($pdo);
    $permissions = [];
    foreach (grooflow_menu_default_leaves() as $leaf) {
        $key = (string) $leaf['modulo_key'];
        if ($key !== '') {
            $permissions[$key] = false;
        }
    }
    $permissions['Finanzas'] = false;
    $permissions['Cuentas por Pagar'] = false;

    if ($nivelId <= 0) {
        return $permissions;
    }

    if (in_array($nivelId, [1, 2], true)) {
        foreach (array_keys($permissions) as $key) {
            $permissions[$key] = true;
        }

        return $permissions;
    }

    $assignment = grooflow_nivel_menu_for_nivel($pdo, $nivelId);
    foreach ($assignment['items'] as $item) {
        $mod = trim((string) ($item['modulo_key'] ?? ''));
        if ($mod !== '') {
            $permissions[$mod] = true;
        }
    }

    if ($permissions['Dashboard'] ?? false) {
        $permissions['Alertas'] = true;
    }

    $financeChildren = ['Tesorería', 'Transacciones', 'Flujo de Caja', 'Estado de Resultados', 'Honorarios', 'Cuentas por Pagar', 'Caja Chica', 'Contabilidad', 'Reportes'];
    foreach ($financeChildren as $child) {
        if ($permissions[$child] ?? false) {
            $permissions['Finanzas'] = true;
            break;
        }
    }

    return $permissions;
}

/** @return list<array<string, mixed>> */
function grooflow_menu_nav_sections_for_user(PDO $pdo, int $nivelId): array
{
    grooflow_menu_sync_catalog($pdo);
    $assignment = grooflow_nivel_menu_for_nivel($pdo, $nivelId);
    $allowedIds = array_fill_keys($assignment['menu_ids'], true);
    $fullAccess = ! empty($assignment['full_access']);
    $tree = grooflow_menu_tree($pdo);
    $sections = [];

    foreach ($tree['sections'] as $block) {
        $items = [];
        foreach ($block['children'] as $child) {
            $childId = (int) $child['id'];
            if (! $fullAccess && ! isset($allowedIds[$childId])) {
                continue;
            }
            $items[] = [
                'id' => $childId,
                'label' => (string) $child['texto'],
                'route' => (string) $child['ruta'],
                'modulo_key' => (string) ($child['modulo_key'] ?? ''),
                'icono' => (string) ($child['icono'] ?? ''),
            ];
        }
        if ($items === []) {
            continue;
        }
        $sections[] = [
            'section' => (string) ($block['section']['texto'] ?? ''),
            'items' => $items,
        ];
    }

    if ($tree['orphans'] !== []) {
        $orphanItems = [];
        foreach ($tree['orphans'] as $child) {
            $childId = (int) $child['id'];
            if (! $fullAccess && ! isset($allowedIds[$childId])) {
                continue;
            }
            $orphanItems[] = [
                'id' => $childId,
                'label' => (string) $child['texto'],
                'route' => (string) $child['ruta'],
                'modulo_key' => (string) ($child['modulo_key'] ?? ''),
                'icono' => (string) ($child['icono'] ?? ''),
            ];
        }
        if ($orphanItems !== []) {
            $sections[] = [
                'section' => 'Otros',
                'items' => $orphanItems,
            ];
        }
    }

    return $sections;
}

/** @return list<array<string, mixed>> */
function grooflow_menu_nav_for_user(PDO $pdo, int $nivelId): array
{
    $nav = [];
    foreach (grooflow_menu_nav_sections_for_user($pdo, $nivelId) as $block) {
        foreach ($block['items'] as $item) {
            $nav[] = $item;
        }
    }

    return $nav;
}
