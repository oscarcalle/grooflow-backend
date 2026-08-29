<?php

declare(strict_types=1);

const GROOFLOW_MENU_PERMISSION_ACTIONS = ['ver', 'agregar', 'editar', 'eliminar', 'exportar', 'configurar'];

/** @return list<string> */
function grooflow_menu_actions_for_route(?string $route, ?string $moduloKey = null): array
{
    $route = trim((string) $route);
    $mod = trim((string) $moduloKey);
    $actions = ['ver'];

    $exportRoutes = ['/reportes', '/auditoria', '/transacciones', '/caja-chica', '/conciliacion'];
    foreach ($exportRoutes as $prefix) {
        if ($route === $prefix || str_starts_with($route, $prefix . '/')) {
            $actions[] = 'exportar';
            break;
        }
    }

    $configRoutes = ['/configuracion', '/config/menu', '/config/asignacion-menu'];
    foreach ($configRoutes as $prefix) {
        if ($route === $prefix || str_starts_with($route, $prefix . '/')) {
            $actions[] = 'configurar';
            break;
        }
    }

    $crudModules = [
        'Proveedores', 'Productos', 'Compras', 'Gestión Vehicular', 'Gestión de Inventario',
        'Transacciones', 'Caja Chica', 'Honorarios',
    ];
    if (in_array($mod, $crudModules, true)) {
        $actions = array_merge($actions, ['agregar', 'editar', 'eliminar']);
    }

    if (in_array('exportar', $actions, true) === false && in_array($mod, ['Reportes', 'Auditoría', 'Conciliación'], true)) {
        $actions[] = 'exportar';
    }

    return array_values(array_unique($actions));
}

function grooflow_menu_action_label(string $action): string
{
    return match ($action) {
        'ver' => 'Ver',
        'agregar' => 'Agregar',
        'editar' => 'Editar',
        'eliminar' => 'Eliminar',
        'exportar' => 'Exportar',
        'configurar' => 'Configurar',
        default => ucfirst($action),
    };
}

/** @param array<string, mixed>|null $row */
function grooflow_menu_permissions_from_row(?array $row, bool $fullAccess = false): array
{
    if ($fullAccess) {
        $all = [];
        foreach (GROOFLOW_MENU_PERMISSION_ACTIONS as $action) {
            $all[$action] = true;
        }

        return $all;
    }

    return [
        'ver' => (bool) ($row['permiso_ver'] ?? false),
        'agregar' => (bool) ($row['permiso_agregar'] ?? false),
        'editar' => (bool) ($row['permiso_editar'] ?? false),
        'eliminar' => (bool) ($row['permiso_eliminar'] ?? false),
        'exportar' => (bool) ($row['permiso_exportar'] ?? false),
        'configurar' => (bool) ($row['permiso_configurar'] ?? false),
    ];
}

function grooflow_menu_permissions_ensure_schema(PDO $pdo): void
{
    if (! grooflow_nivel_menu_table_exists($pdo)) {
        return;
    }
    $cols = [
        'permiso_agregar' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'permiso_editar' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'permiso_eliminar' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'permiso_exportar' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'permiso_configurar' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ];
    foreach ($cols as $name => $def) {
        if (! grooflow_nivel_menu_column_exists($pdo, $name)) {
            $pdo->exec("ALTER TABLE grooflow_nivel_menu ADD COLUMN {$name} {$def}");
        }
    }
}

function grooflow_nivel_menu_column_exists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = "grooflow_nivel_menu"
          AND COLUMN_NAME = ?
    ');
    $stmt->execute([$column]);

    return (int) $stmt->fetchColumn() > 0;
}
