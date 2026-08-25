<?php

declare(strict_types=1);

function grooflow_collection_table(string $name): ?string
{
    return grooflow_collection_tables()[$name] ?? null;
}

function grooflow_collection_get_all(PDO $pdo, string $name): array
{
    if ($name === 'users') {
        return grooflow_list_users($pdo);
    }
    if ($name === 'roles') {
        return grooflow_list_roles($pdo);
    }
    $table = grooflow_collection_table($name);
    if ($table === null) {
        throw new InvalidArgumentException('Colección desconocida');
    }

    return grooflow_table_list($pdo, $table);
}

function grooflow_collection_get_one(PDO $pdo, string $name, string $id): ?array
{
    foreach (grooflow_collection_get_all($pdo, $name) as $item) {
        if ((string) ($item['id'] ?? '') === $id) {
            return $item;
        }
    }

    return null;
}

function grooflow_collection_create(PDO $pdo, string $name, array $record): array
{
    if ($name === 'users') {
        return grooflow_upsert_user_from_app($pdo, $record, true);
    }
    if ($name === 'roles') {
        return grooflow_upsert_role($pdo, $record);
    }
    $table = grooflow_collection_table($name);
    if ($table === null) {
        throw new InvalidArgumentException('Colección desconocida');
    }
    if (trim((string) ($record['id'] ?? '')) === '') {
        $record['id'] = bin2hex(random_bytes(8));
    }
    $saved = grooflow_table_upsert_one($pdo, $table, $record);
    grooflow_kv_set($pdo, grooflow_collection_kv_key($name), grooflow_table_list($pdo, $table));

    return $saved;
}

function grooflow_collection_update(PDO $pdo, string $name, string $id, array $partial): array
{
    $current = grooflow_collection_get_one($pdo, $name, $id);
    if ($current === null) {
        throw new RuntimeException('Registro no encontrado');
    }
    $merged = array_merge($current, $partial, ['id' => $id]);
    if ($name === 'users') {
        return grooflow_upsert_user_from_app($pdo, $merged, false);
    }
    if ($name === 'roles') {
        return grooflow_upsert_role($pdo, $merged);
    }
    $table = grooflow_collection_table($name);
    if ($table === null) {
        throw new InvalidArgumentException('Colección desconocida');
    }
    $all = grooflow_table_list($pdo, $table);
    foreach ($all as $i => $item) {
        if ((string) ($item['id'] ?? '') === $id) {
            $all[$i] = $merged;
        }
    }
    grooflow_table_replace($pdo, $table, $all);
    grooflow_kv_set($pdo, grooflow_collection_kv_key($name), grooflow_table_list($pdo, $table));

    return $merged;
}

function grooflow_collection_delete(PDO $pdo, string $name, string $id): void
{
    if ($name === 'users') {
        grooflow_soft_delete_user($pdo, $id);

        return;
    }
    if ($name === 'roles') {
        grooflow_delete_role($pdo, $id);

        return;
    }
    $table = grooflow_collection_table($name);
    if ($table === null) {
        throw new InvalidArgumentException('Colección desconocida');
    }
    grooflow_table_delete_one($pdo, $table, $id);
    grooflow_kv_set($pdo, grooflow_collection_kv_key($name), grooflow_table_list($pdo, $table));
}

function grooflow_collection_upsert_many(PDO $pdo, string $name, array $records): void
{
    if ($name === 'users') {
        grooflow_replace_users($pdo, $records);

        return;
    }
    if ($name === 'roles') {
        grooflow_replace_roles($pdo, $records);

        return;
    }
    $table = grooflow_collection_table($name);
    if ($table === null) {
        throw new InvalidArgumentException('Colección desconocida');
    }
    $map = [];
    foreach (grooflow_table_list($pdo, $table) as $item) {
        $map[(string) ($item['id'] ?? '')] = $item;
    }
    foreach ($records as $record) {
        if (! is_array($record) || empty($record['id'])) {
            continue;
        }
        $map[(string) $record['id']] = $record;
    }
    grooflow_table_replace($pdo, $table, array_values($map));
    grooflow_kv_set($pdo, grooflow_collection_kv_key($name), grooflow_table_list($pdo, $table));
}

function grooflow_collection_kv_key(string $name): string
{
    return match ($name) {
        'transactions' => 'data:transactions',
        'providers' => 'data:providers',
        'requests' => 'data:requests',
        'invoices' => 'data:invoices',
        'pettyCash' => 'data:pettyCash',
        'requisitions' => 'data:requisitions',
        'products' => 'data:products',
        'feeReceipts' => 'data:feeReceipts',
        'fleet' => 'data:fleet',
        'inventory' => 'data:inventory',
        default => 'data:' . $name,
    };
}
