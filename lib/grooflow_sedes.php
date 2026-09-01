<?php

declare(strict_types=1);

function grooflow_sedes_tenants_api(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $root = defined('CRON_ROOT') ? CRON_ROOT : dirname(__DIR__, 2);
    require_once $root . '/backend/lib/tenants_api.php';
    $loaded = true;
}

function grooflow_normalize_sede_key(string $name): string
{
    $name = mb_strtolower(trim($name));
    $name = preg_replace('/^\d+\.\s*/u', '', $name) ?? $name;
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

    return match ($name) {
        'petmovil', 'pet móvil' => 'pet movil',
        'chavez', 'jorge chávez' => 'jorge chavez',
        'gm', 'groomers memorial' => 'memorial',
        'miraflores' => 'miraflores',
        default => $name,
    };
}

/** @return list<array{tenant_id:int,centro_id:int,nombre:string,slug:string,nombre_codigo:string}> */
function grooflow_list_sedes(PDO $pdo): array
{
    grooflow_sedes_tenants_api();
    if (! function_exists('tenants_list_active')) {
        return [];
    }

    $out = [];
    foreach (tenants_list_active($pdo) as $row) {
        $nombre = trim((string) ($row['nombre'] ?? ''));
        if ($nombre === '') {
            continue;
        }
        $out[] = [
            'tenant_id' => (int) ($row['tenant_id'] ?? 0),
            'centro_id' => (int) ($row['centro_id'] ?? 0),
            'nombre' => $nombre,
            'slug' => (string) ($row['slug'] ?? ''),
            'nombre_codigo' => (string) ($row['nombre_codigo'] ?? ''),
        ];
    }

    return $out;
}

/** @param mixed $storedCatalog */
function grooflow_sedes_catalog_from_tenants(PDO $pdo, mixed $storedCatalog = null): array
{
    $enabledByKey = [];
    if (is_array($storedCatalog)) {
        foreach ($storedCatalog as $entry) {
            $name = is_array($entry) ? trim((string) ($entry['name'] ?? '')) : trim((string) $entry);
            if ($name === '') {
                continue;
            }
            $key = grooflow_normalize_sede_key($name);
            $enabled = ! is_array($entry) || ($entry['enabled'] ?? true) !== false;
            if (! array_key_exists($key, $enabledByKey) || $enabled) {
                $enabledByKey[$key] = $enabled;
            }
        }
    }

    $catalog = [];
    foreach (grooflow_list_sedes($pdo) as $tenant) {
        $nombre = trim((string) ($tenant['nombre'] ?? ''));
        if ($nombre === '') {
            continue;
        }
        $key = grooflow_normalize_sede_key($nombre);
        $catalog[] = [
            'name' => $nombre,
            'enabled' => $enabledByKey[$key] ?? true,
            'tenant_id' => (int) ($tenant['tenant_id'] ?? 0),
            'centro_id' => (int) ($tenant['centro_id'] ?? 0),
        ];
    }

    return $catalog;
}

function grooflow_resolve_sede_canonical(PDO $pdo, string $name): string
{
    $needle = grooflow_normalize_sede_key($name);
    if ($needle === '') {
        return trim($name);
    }
    foreach (grooflow_list_sedes($pdo) as $tenant) {
        $nombre = trim((string) ($tenant['nombre'] ?? ''));
        if ($nombre !== '' && grooflow_normalize_sede_key($nombre) === $needle) {
            return $nombre;
        }
    }

    return trim($name);
}

/** @param array<string, mixed>|null $settings */
function grooflow_merge_system_settings_sedes(PDO $pdo, ?array $settings): array
{
    $settings = is_array($settings) ? $settings : [];
    $settings['sedesCatalog'] = grooflow_sedes_catalog_from_tenants($pdo, $settings['sedesCatalog'] ?? null);

    return $settings;
}
