<?php

declare(strict_types=1);

function grooflow_caller_is_admin(PDO $pdo): bool
{
    $row = api_current_user();
    if (! is_array($row)) {
        return false;
    }
    grooflow_ensure_perfil($pdo, (int) $row['id'], (int) ($row['nivel_id'] ?? 0));
    $nivel = (int) ($row['nivel_id'] ?? 0);
    if ($nivel === 2) {
        return true;
    }
    $app = grooflow_user_to_app($pdo, $row);
    $role = (string) ($app['role'] ?? '');
    // El perfil KV se puede falsificar; super_admin solo cuenta con nivel 2 (arriba).
    return $role === 'admin' && in_array($nivel, [7, 11, 13], true);
}

function grooflow_assert_admin(PDO $pdo): void
{
    if (! grooflow_caller_is_admin($pdo)) {
        throw new RuntimeException('Se requieren permisos de administrador');
    }
}

/** @return list<string> */
function grooflow_allowed_roles(): array
{
    return ['super_admin', 'admin', 'auditoria', 'manager', 'groomer'];
}

function grooflow_clamp_assignable_role(string $role): string
{
    $role = strtolower(trim($role));
    if ($role === '' || ! in_array($role, grooflow_allowed_roles(), true)) {
        $role = 'groomer';
    }
    $row = api_current_user();
    $nivel = is_array($row) ? (int) ($row['nivel_id'] ?? 0) : 0;
    if ($role === 'super_admin' && $nivel !== 2) {
        return 'groomer';
    }
    if ($role === 'admin' && $nivel !== 2 && ! in_array($nivel, [7, 11, 13], true)) {
        return 'groomer';
    }

    return $role;
}

function grooflow_validate_password(string $password): void
{
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('La contraseña debe tener al menos 8 caracteres');
    }
    if (! preg_match('/[a-zA-Z]/', $password)) {
        throw new InvalidArgumentException('La contraseña debe incluir al menos una letra');
    }
    if (! preg_match('/[0-9]/', $password)) {
        throw new InvalidArgumentException('La contraseña debe incluir al menos un número');
    }
}

function grooflow_field_looks_secret(string $key): bool
{
    $lk = strtolower($key);

    return in_array($lk, ['apitoken', 'api_token', 'token', 'password', 'secret', 'clientsecret', 'client_secret'], true)
        || str_ends_with($lk, 'token')
        || str_ends_with($lk, 'password')
        || str_ends_with($lk, 'secret');
}

function grooflow_redact_secret_fields(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    $out = [];
    foreach ($value as $k => $v) {
        if (grooflow_field_looks_secret((string) $k) && is_string($v) && $v !== '') {
            $out[$k] = '********';
            continue;
        }
        $out[$k] = grooflow_redact_secret_fields($v);
    }

    return $out;
}

function grooflow_merge_keep_secrets(mixed $existing, mixed $incoming): mixed
{
    if (! is_array($incoming)) {
        return $incoming;
    }
    $existing = is_array($existing) ? $existing : [];
    $out = $incoming;
    foreach ($incoming as $k => $v) {
        if (is_array($v)) {
            $out[$k] = grooflow_merge_keep_secrets($existing[$k] ?? [], $v);
            continue;
        }
        if (! grooflow_field_looks_secret((string) $k) || ! is_string($v)) {
            continue;
        }
        $looksRedacted = $v === '' || str_contains($v, '*');
        if ($looksRedacted && array_key_exists($k, $existing)) {
            $out[$k] = $existing[$k];
        }
    }

    return $out;
}

function grooflow_kv_value_for_caller(PDO $pdo, string $key, mixed $value): mixed
{
    if (grooflow_caller_is_admin($pdo)) {
        return $value;
    }
    $key = grooflow_normalize_kv_key($key);
    if (in_array($key, ['settings:asistencia', 'settings:system'], true)) {
        return grooflow_redact_secret_fields($value);
    }

    return $value;
}

function grooflow_prepare_kv_write(PDO $pdo, string $key, mixed $value): mixed
{
    $key = grooflow_normalize_kv_key($key);
    if (in_array($key, ['data:users', 'data:roles'], true)) {
        grooflow_assert_admin($pdo);

        return $value;
    }
    if ($key === 'settings:asistencia') {
        // Gerentes/encargados pueden guardar staff/sede; el token Buk se preserva si llega redactado.
        return grooflow_merge_keep_secrets(grooflow_kv_get($pdo, $key), $value);
    }
    if (in_array($key, ['data:asistencia-snapshots', 'data:asistencia-operational'], true)) {
        return $value;
    }
    if ($key === 'settings:system' && ! grooflow_caller_is_admin($pdo)) {
        return grooflow_merge_keep_secrets(grooflow_kv_get($pdo, $key), $value);
    }

    return $value;
}

function grooflow_enforce_collection_write(PDO $pdo, string $name): void
{
    if (in_array($name, ['users', 'roles'], true)) {
        grooflow_assert_admin($pdo);
    }
}
