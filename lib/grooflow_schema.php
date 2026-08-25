<?php

declare(strict_types=1);

function grooflow_json_encode(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function grooflow_json_decode(?string $json): mixed
{
    if ($json === null || $json === '') {
        return null;
    }
    $decoded = json_decode($json, true);

    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

/** @return array<string, string> */
function grooflow_collection_tables(): array
{
    return [
        'transactions' => 'grooflow_transacciones',
        'providers' => 'grooflow_proveedores',
        'requests' => 'grooflow_solicitudes',
        'invoices' => 'grooflow_facturas',
        'pettyCash' => 'grooflow_caja_chica',
        'requisitions' => 'grooflow_requisiciones',
        'products' => 'grooflow_productos',
        'feeReceipts' => 'grooflow_honorarios',
        'fleet' => 'grooflow_flota',
        'inventory' => 'grooflow_inventario',
    ];
}

/** @return array<string, string> */
function grooflow_kv_array_tables(): array
{
    return [
        'data:transactions' => 'grooflow_transacciones',
        'data:providers' => 'grooflow_proveedores',
        'data:requests' => 'grooflow_solicitudes',
        'data:invoices' => 'grooflow_facturas',
        'data:pettyCash' => 'grooflow_caja_chica',
        'data:requisitions' => 'grooflow_requisiciones',
        'data:products' => 'grooflow_productos',
        'data:feeReceipts' => 'grooflow_honorarios',
    ];
}

function grooflow_ensure_payload_table(PDO $pdo, string $table): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$table}` (
            id VARCHAR(80) NOT NULL,
            payload JSON NOT NULL,
            location VARCHAR(160) NULL,
            usuario_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_{$table}_location (location)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function grooflow_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_kv (
            k VARCHAR(190) NOT NULL,
            value JSON NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (k)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_roles (
            id VARCHAR(80) NOT NULL,
            name VARCHAR(160) NOT NULL,
            description TEXT NULL,
            color VARCHAR(120) NULL,
            bg_color VARCHAR(120) NULL,
            border_color VARCHAR(120) NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            permissions JSON NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_perfiles (
            usuario_id INT UNSIGNED NOT NULL,
            role_id VARCHAR(80) NOT NULL DEFAULT 'groomer',
            initials VARCHAR(16) NULL,
            sedes_json JSON NULL,
            all_sedes TINYINT(1) NOT NULL DEFAULT 0,
            petty_cash_fund_enabled TINYINT(1) NULL,
            petty_cash_limit DECIMAL(12,2) NULL,
            petty_cash_opening_carry_suggested DECIMAL(12,2) NULL,
            petty_cash_opening_carry_consumed_at VARCHAR(40) NULL,
            avatar_url MEDIUMTEXT NULL,
            location VARCHAR(160) NULL,
            extra_json JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (usuario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_caja_chica_meta (
            id VARCHAR(80) NOT NULL,
            payload JSON NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_auditoria (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            usuario_id INT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            entity VARCHAR(80) NULL,
            entity_id VARCHAR(80) NULL,
            payload JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_grooflow_aud_usuario (usuario_id),
            KEY idx_grooflow_aud_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    foreach (array_unique(array_values(grooflow_collection_tables())) as $table) {
        grooflow_ensure_payload_table($pdo, $table);
    }

    grooflow_seed_roles($pdo);
}

function grooflow_seed_roles(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM grooflow_roles')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $roles = grooflow_default_roles();
    $stmt = $pdo->prepare('
        INSERT INTO grooflow_roles
            (id, name, description, color, bg_color, border_color, is_system, permissions)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    foreach ($roles as $role) {
        $stmt->execute([
            $role['id'],
            $role['name'],
            $role['description'],
            $role['color'],
            $role['bgColor'],
            $role['borderColor'],
            ! empty($role['isSystem']) ? 1 : 0,
            grooflow_json_encode($role['permissions']),
        ]);
    }
}

/** @return list<array<string, mixed>> */
function grooflow_default_roles(): array
{
    $allTrue = [
        'Dashboard' => true, 'Alertas' => true, 'Analítica' => true, 'Finanzas' => true,
        'Tesorería' => true, 'Transacciones' => true, 'Flujo de Caja' => true,
        'Estado de Resultados' => true, 'Honorarios' => true, 'Cuentas por Pagar' => true,
        'Caja Chica' => true, 'Compras' => true, 'Productos' => true, 'Proveedores' => true,
        'Contabilidad' => true, 'Gestión Vehicular' => true, 'Gestión de Inventario' => true,
        'Asistencia' => true, 'Reportes' => true, 'Auditoría' => true, 'Conciliación' => true,
        'Usuarios' => true, 'Configuración' => true,
    ];

    return [
        [
            'id' => 'super_admin',
            'name' => 'Super Administrador',
            'description' => 'Acceso total al sistema sin restricciones',
            'color' => 'text-red-600 dark:text-red-400',
            'bgColor' => 'bg-red-50 dark:bg-red-900/20',
            'borderColor' => 'border-red-200 dark:border-red-800',
            'isSystem' => true,
            'permissions' => $allTrue,
        ],
        [
            'id' => 'manager',
            'name' => 'Gerente',
            'description' => 'Acceso a reportes, finanzas y operaciones',
            'color' => 'text-blue-600 dark:text-blue-400',
            'bgColor' => 'bg-blue-50 dark:bg-blue-900/20',
            'borderColor' => 'border-blue-200 dark:border-blue-800',
            'isSystem' => false,
            'permissions' => array_merge($allTrue, [
                'Tesorería' => false, 'Honorarios' => false, 'Compras' => false,
                'Productos' => false, 'Auditoría' => false, 'Conciliación' => false,
                'Usuarios' => false, 'Configuración' => false,
            ]),
        ],
        [
            'id' => 'auditoria',
            'name' => 'Auditoría',
            'description' => 'Revisa y aprueba movimientos de caja chica; acceso a módulo de auditoría',
            'color' => 'text-amber-700 dark:text-amber-400',
            'bgColor' => 'bg-amber-50 dark:bg-amber-900/20',
            'borderColor' => 'border-amber-200 dark:border-amber-800',
            'isSystem' => true,
            'permissions' => array_merge($allTrue, [
                'Analítica' => false, 'Tesorería' => false, 'Transacciones' => false,
                'Flujo de Caja' => false, 'Estado de Resultados' => false, 'Honorarios' => false,
                'Cuentas por Pagar' => false, 'Compras' => false, 'Productos' => false,
                'Proveedores' => false, 'Contabilidad' => false, 'Gestión Vehicular' => false,
                'Gestión de Inventario' => false, 'Asistencia' => false, 'Usuarios' => false,
                'Configuración' => false,
            ]),
        ],
        [
            'id' => 'groomer',
            'name' => 'Groomer',
            'description' => 'Acceso a citas y servicios',
            'color' => 'text-teal-600 dark:text-teal-400',
            'bgColor' => 'bg-teal-50 dark:bg-teal-900/20',
            'borderColor' => 'border-teal-200 dark:border-teal-800',
            'isSystem' => false,
            'permissions' => array_merge(array_fill_keys(array_keys($allTrue), false), [
                'Dashboard' => true,
            ]),
        ],
    ];
}
