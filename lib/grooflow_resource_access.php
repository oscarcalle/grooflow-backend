<?php

declare(strict_types=1);

/** Resource policy used by bootstrap, KV, collections and paginated lists. */
function grooflow_resource_modules(string $key, bool $write = false): array
{
    return match ($key) {
        'data:transactions' => $write ? ['Transacciones'] : ['Transacciones', 'Dashboard', 'Analítica', 'Flujo de Caja', 'Estado de Resultados', 'Reportes'],
        'data:providers' => ['Proveedores', 'Compras', 'Honorarios', 'Tesorería'],
        'data:products' => ['Productos', 'Compras'],
        'data:requests', 'data:requisitions' => ['Compras'],
        'data:invoices' => ['Cuentas por Pagar', 'Tesorería'],
        'data:pettyCash', 'data:pettyCashMeta' => ['Caja Chica'],
        'data:feeReceipts' => ['Honorarios', 'Tesorería'],
        'data:fleet' => ['Gestión Vehicular'],
        'data:inventory' => ['Gestión de Inventario'],
        'data:chartOfAccounts' => ['Contabilidad'],
        'data:reconciliation' => ['Conciliación'],
        'settings:asistencia', 'data:asistencia-snapshots', 'data:asistencia-operational' => ['Asistencia', 'Recursos Humanos'],
        'settings:rrhh' => ['Recursos Humanos'],
        'settings:turnos' => ['Turnos'],
        'settings:accidentes-trabajo' => ['Accidentes de Trabajo'],
        'settings:entrega-uniformes' => ['Entrega de Uniformes'],
        'data:treasuryUsdBalance', 'data:treasuryInvoices', 'data:treasuryBankBalance', 'data:treasuryPaidHistory', 'data:treasurySubscriptions', 'data:treasuryBankMovements' => ['Tesorería'],
        'data:monthlyClosures' => ['Reportes'],
        'settings:config', 'settings:system', 'settings:theme', 'settings:alertThresholds', 'data:sedes', 'settings:alertReadState' => $write ? ['Configuración'] : ['*'],
        'maintenance:transactionsClearedAt' => $write ? [] : ['*'],
        'data:users', 'data:roles' => ['Usuarios'],
        default => [],
    };
}

function grooflow_access_context(PDO $pdo): array
{
    static $cache = [];
    $row = api_current_user() ?? [];
    $id = (int) ($row['id'] ?? 0);
    if (!isset($cache[$id])) {
        $profile = grooflow_user_to_app($pdo, $row);
        $actions = [];
        foreach (grooflow_nivel_menu_for_nivel($pdo, (int) ($row['nivel_id'] ?? 0))['items'] as $item) {
            if (!empty($item['asignado']) && !empty($item['permisos']['ver'])) $actions[$item['modulo_key']] = $item['permisos'];
        }
        $cache[$id] = [
            'actions' => $actions,
            'id' => (string) $id,
            'admin' => grooflow_caller_is_admin($pdo),
            'allSedes' => !empty($profile['allSedes']),
            'sedes' => $profile['sedes'] ?? [],
            'permissions' => grooflow_menu_permissions_for_nivel($pdo, (int) ($row['nivel_id'] ?? 0)),
            'role' => (string) ($profile['role'] ?? ''),
            'nivelNombre' => (string) ($profile['nivelNombre'] ?? ''),
            'roleLabel' => (string) ($profile['roleLabel'] ?? ''),
        ];
    }
    return $cache[$id];
}

/** Normaliza texto para comparar nivel/rol (sin acentos). */
function grooflow_petty_cash_identity_blob(array $ctx): string
{
    $raw = strtolower(trim(
        (string) ($ctx['role'] ?? '') . ' ' .
        (string) ($ctx['nivelNombre'] ?? '') . ' ' .
        (string) ($ctx['roleLabel'] ?? '')
    ));
    $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'];

    return strtr($raw, $map);
}

/** Ve fondos de otros responsables (Auditoría, Contabilidad, Jefes, Gerencia). */
function grooflow_petty_cash_can_view_all(array $ctx): bool
{
    if (!empty($ctx['admin'])) return true;
    $role = strtolower(trim((string) ($ctx['role'] ?? '')));
    if (in_array($role, ['auditoria', 'admin', 'super_admin', 'manager'], true)) return true;
    $blob = grooflow_petty_cash_identity_blob($ctx);
    foreach (['auditor', 'auditoria', 'contabilidad', 'contador', 'contadur', 'jefes', 'jefe', 'gerencia', 'gerente', 'manager'] as $hint) {
        if ($hint !== '' && str_contains($blob, $hint)) return true;
    }
    $perms = $ctx['permissions'] ?? [];
    if (!empty($perms['Auditoría']) && !empty($perms['Caja Chica'])) return true;
    // Perfil Contabilidad (menú Gestión) aunque el nivel no traiga la palabra en el nombre.
    if (!empty($perms['Contabilidad'])) return true;

    return false;
}

/** Aprueba / dotación / refuerzo (Auditoría + admin). */
function grooflow_petty_cash_can_audit(array $ctx): bool
{
    if (!empty($ctx['admin'])) return true;
    $role = strtolower(trim((string) ($ctx['role'] ?? '')));
    if (in_array($role, ['auditoria', 'admin', 'super_admin'], true)) return true;
    $blob = grooflow_petty_cash_identity_blob($ctx);
    foreach (['auditor', 'auditoria'] as $hint) {
        if (str_contains($blob, $hint)) return true;
    }
    $perms = $ctx['permissions'] ?? [];
    if (!empty($perms['Auditoría']) && !empty($perms['Caja Chica'])) return true;

    return false;
}

function grooflow_petty_cash_row_in_scope(array $ctx, array $row): bool
{
    if (grooflow_petty_cash_can_view_all($ctx)) {
        if (!empty($ctx['allSedes'])) return true;

        return grooflow_row_in_scope($ctx, $row);
    }
    $uid = (string) ($ctx['id'] ?? '');
    $cid = trim((string) ($row['custodianId'] ?? ''));
    if ($cid !== '') return $cid === $uid;
    $owner = trim((string) ($row['userId'] ?? ''));

    return $owner !== '' && $owner === $uid;
}

/**
 * Impide que un responsable auto-apruebe o edite movimientos ajenos.
 *
 * @param list<array<string, mixed>> $before
 * @param list<array<string, mixed>> $after
 */
function grooflow_assert_petty_cash_write(array $ctx, array $before, array $after): void
{
    $byId = [];
    foreach ($before as $row) {
        if (is_array($row) && isset($row['id'])) $byId[(string) $row['id']] = $row;
    }
    $canAudit = grooflow_petty_cash_can_audit($ctx);
    $canViewAll = grooflow_petty_cash_can_view_all($ctx);
    $uid = (string) ($ctx['id'] ?? '');

    foreach ($after as $row) {
        if (!is_array($row)) continue;
        $id = (string) ($row['id'] ?? '');
        $prev = $byId[$id] ?? null;
        $cid = trim((string) ($row['custodianId'] ?? ($prev['custodianId'] ?? '')));
        if ($cid === '') $cid = trim((string) ($row['userId'] ?? ($prev['userId'] ?? '')));

        if (!$canViewAll && $cid !== '' && $cid !== $uid) {
            throw new RuntimeException('Sin permiso para modificar movimientos de otro responsable');
        }

        $prevStatus = is_array($prev) ? (string) ($prev['status'] ?? '') : '';
        $nextStatus = (string) ($row['status'] ?? '');
        if ($prevStatus !== $nextStatus && in_array($nextStatus, ['approved', 'rejected'], true) && !$canAudit) {
            throw new RuntimeException('Sin permiso para aprobar o rechazar movimientos de caja chica');
        }
        if (
            !$canAudit
            && is_array($prev)
            && in_array($prevStatus, ['approved', 'rejected'], true)
            && $nextStatus !== $prevStatus
        ) {
            throw new RuntimeException('Sin permiso para alterar un movimiento ya auditado');
        }
    }
}

function grooflow_resource_allowed(array $ctx, string $key, bool $write = false): bool
{
    if (!empty($ctx['admin'])) return true;
    // User/role administration must never be inferred from a menu assignment.
    if (in_array($key, ['data:users', 'data:roles'], true)) return !$write;
    if ($write && in_array($key, ['settings:system', 'settings:asistencia', 'settings:rrhh', 'data:sedes'], true)) return false;
    foreach (grooflow_resource_modules($key, $write) as $module) {
        if ($module === '*' || ($ctx['permissions'][$module] ?? false) === true) return true;
    }
    return false;
}

function grooflow_assert_resource(PDO $pdo, string $key, bool $write = false): void
{
    if (!grooflow_resource_allowed(grooflow_access_context($pdo), $key, $write)) {
        throw new RuntimeException('Sin permiso para ' . ($write ? 'modificar ' : 'consultar ') . $key);
    }
}

function grooflow_assert_module(PDO $pdo, array $modules): void
{
    $ctx = grooflow_access_context($pdo);
    if ($ctx['admin']) return;
    foreach ($modules as $module) {
        if (($ctx['permissions'][$module] ?? false) === true) return;
    }
    throw new RuntimeException('Sin permiso para esta operación');
}

function grooflow_row_in_scope(array $ctx, array $row): bool
{
    if (!empty($ctx['admin']) || !empty($ctx['allSedes'])) return true;
    // Work location is authoritative for an inter-branch assignment.
    foreach (['workSede', 'sede', 'location', 'branchId', 'homeBase', 'homeSede'] as $field) {
        if (isset($row[$field]) && is_string($row[$field]) && trim($row[$field]) !== '') {
            return in_array(trim($row[$field]), $ctx['sedes'], true);
        }
    }
    return isset($row['userId']) && (string) $row['userId'] === $ctx['id'];
}

/** Scope records before counting, searching or paging; global catalogues are explicit. */
function grooflow_project_resource(array $ctx, string $key, mixed $value): mixed
{
    if ($ctx['admin']) return $value;
    if ($key === 'data:users') {
        // Elevados de caja chica necesitan el directorio para elegir responsables.
        if (grooflow_petty_cash_can_view_all($ctx)) return $value;

        return array_values(array_filter(is_array($value) ? $value : [], fn ($u) => (string) ($u['id'] ?? '') === $ctx['id']));
    }
    if ($key === 'data:roles') return $value; // Permission definitions contain no credentials.
    if (str_starts_with($key, 'settings:')) $value = grooflow_redact_secret_fields($value);
    // Catálogos y ajustes globales compartidos (no filtrar por sede).
    // settings:system incluye providers.categories/areas usados en Proveedores por todos los perfiles.
    if ($ctx['allSedes'] || in_array($key, [
        'data:treasuryBankBalance',
        'data:treasuryUsdBalance',
        'data:providers',
        'data:products',
        'data:chartOfAccounts',
        'settings:config',
        'settings:system',
        'settings:theme',
        'settings:alertThresholds',
        'settings:alertReadState',
    ], true)) {
        return $value;
    }
    if ($key === 'data:sedes') return array_values(array_filter(is_array($value) ? $value : [], fn ($s) => in_array(is_string($s) ? $s : ($s['name'] ?? $s['nombre'] ?? ''), $ctx['sedes'], true)));
    if (!is_array($value)) return null;
    if ($key === 'data:fleet' || $key === 'data:inventory') {
        $main = $key === 'data:fleet' ? 'vehicles' : 'equipment';
        $ref = $key === 'data:fleet' ? 'vehicleId' : 'equipmentId';
        $rows = array_values(array_filter($value[$main] ?? [], fn ($r) => grooflow_row_in_scope($ctx, $r)));
        $ids = array_column($rows, 'id');
        $out = $value;
        $out[$main] = $rows;
        foreach (['maintenance', 'fuelEntries', 'inspections'] as $field) {
            if (isset($value[$field])) $out[$field] = array_values(array_filter($value[$field], fn ($r) => in_array($r[$ref] ?? null, $ids, true)));
        }
        return $out;
    }
    if ($key === 'data:pettyCash' && array_is_list($value)) {
        return array_values(array_filter($value, fn ($r) => is_array($r) && grooflow_petty_cash_row_in_scope($ctx, $r)));
    }
    if (array_is_list($value)) return array_values(array_filter($value, fn ($r) => is_array($r) && grooflow_row_in_scope($ctx, $r)));
    // Object datasets: expose scoped record arrays only; credentials and global settings stay server-owned.
    $out = [];
    foreach ($value as $field => $child) {
        if (is_array($child) && array_is_list($child)) {
            $out[$field] = array_values(array_filter($child, fn ($r) => is_array($r) && grooflow_row_in_scope($ctx, $r)));
        } elseif (is_array($child) && in_array((string) $field, $ctx['sedes'], true)) {
            $out[$field] = $child;
        }
    }
    return $out;
}

/** Merge a scoped replacement without deleting or overwriting another branch. */
function grooflow_merge_scoped(array $full, array $visible, array $incoming): array
{
    if (array_is_list($full) && array_is_list($incoming)) {
        $visibleIds = array_map(fn ($r) => (string) ($r['id'] ?? ''), $visible);
        $hidden = array_values(array_filter($full, fn ($r) => !in_array((string) ($r['id'] ?? ''), $visibleIds, true)));
        $hiddenIds = array_map(fn ($r) => (string) ($r['id'] ?? ''), $hidden);
        foreach ($incoming as $row) {
            if (in_array((string) ($row['id'] ?? ''), $hiddenIds, true)) throw new RuntimeException('Sin permiso para sobrescribir un registro de otra sede');
        }
        return [...$hidden, ...$incoming];
    }
    $out = $full;
    foreach ($incoming as $field => $child) {
        if (!array_key_exists($field, $visible)) throw new RuntimeException('Sin permiso para modificar el campo ' . $field);
        if (is_array($child) && is_array($full[$field] ?? null)) {
            $out[$field] = grooflow_merge_scoped($full[$field], $visible[$field], $child);
        } elseif ($child !== $visible[$field]) {
            throw new RuntimeException('Sin permiso para modificar configuración compartida');
        }
    }
    return $out;
}

function grooflow_assert_resource_action(PDO $pdo, string $key, string $action): void
{
    $ctx = grooflow_access_context($pdo);
    if ($ctx['admin']) return;
    foreach (grooflow_resource_modules($key, $action !== 'ver') as $module) {
        if (($ctx['actions'][$module][$action] ?? false) === true) return;
    }
    throw new RuntimeException('Sin permiso para la acción ' . $action);
}

function grooflow_required_actions(mixed $before, mixed $after): array
{
    if (! is_array($before) || ! is_array($after)) {
        return ['editar'];
    }

    // Listas de registros (transacciones, ítems, vehículos, …).
    if (array_is_list($before) && array_is_list($after)) {
        $old = [];
        $new = [];
        $actions = [];
        foreach ($before as $r) {
            $old[(string) ($r['id'] ?? '')] = $r;
        }
        foreach ($after as $r) {
            $new[(string) ($r['id'] ?? '')] = $r;
        }
        foreach ($new as $id => $r) {
            if (! isset($old[$id])) {
                $actions[] = 'agregar';
            } elseif ($old[$id] != $r) {
                $actions[] = 'editar';
            }
        }
        foreach ($old as $id => $_) {
            if (! isset($new[$id])) {
                $actions[] = 'eliminar';
            }
        }

        return array_values(array_unique($actions));
    }

    // Objetos anidados (data:fleet, data:inventory, …): derivar CRUD de listas hijas.
    $actions = [];
    $keys = array_unique([...array_keys($before), ...array_keys($after)]);
    foreach ($keys as $key) {
        $b = $before[$key] ?? null;
        $a = $after[$key] ?? null;
        if (is_array($b) && is_array($a) && array_is_list($b) && array_is_list($a)) {
            $actions = array_merge($actions, grooflow_required_actions($b, $a));
        } elseif ($b != $a) {
            $actions[] = 'editar';
        }
    }

    return array_values(array_unique($actions));
}
