<?php

declare(strict_types=1);

if (! function_exists('usuarios_create')) {
    require_once (defined('CRON_ROOT') ? CRON_ROOT : dirname(__DIR__, 2)) . '/backend/lib/usuarios_api.php';
}

function grooflow_default_role_for_nivel(int $nivelId): string
{
    if ($nivelId === 2) {
        return 'super_admin';
    }
    if (in_array($nivelId, [8, 12], true)) {
        return 'auditoria';
    }
    if (in_array($nivelId, [7, 11, 13], true)) {
        return 'manager';
    }

    return 'groomer';
}

function grooflow_default_nivel_for_role(string $role): int
{
    return match ($role) {
        'super_admin' => 2,
        'auditoria' => 12,
        'manager' => 7,
        default => 1,
    };
}

function grooflow_split_name(string $name): array
{
    $name = trim($name);
    if ($name === '') {
        return ['', ''];
    }
    $parts = preg_split('/\s+/', $name) ?: [$name];
    $nombre = (string) array_shift($parts);
    $apellido = trim(implode(' ', $parts));

    return [$nombre, $apellido];
}

function grooflow_display_name(array $row): string
{
    $full = trim(((string) ($row['nombre'] ?? '')) . ' ' . ((string) ($row['apellido'] ?? '')));

    return $full !== '' ? $full : (string) ($row['username'] ?? '');
}

function grooflow_initials(string $name, string $fallback = ''): string
{
    $name = trim($name);
    if ($name === '') {
        return strtoupper(substr($fallback !== '' ? $fallback : 'GF', 0, 2));
    }
    $parts = preg_split('/\s+/', $name) ?: [$name];
    $out = '';
    foreach ($parts as $part) {
        $out .= function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1);
        if (strlen($out) >= 2) {
            break;
        }
    }

    return strtoupper($out !== '' ? $out : 'GF');
}

function grooflow_resolve_username(PDO $pdo, string $identifier): string
{
    $identifier = strtolower(trim($identifier));
    if ($identifier === '') {
        return '';
    }

    $stmt = $pdo->prepare('SELECT username FROM app_usuarios WHERE username = ? AND is_deleted = 0 LIMIT 1');
    $stmt->execute([$identifier]);
    $username = $stmt->fetchColumn();
    if (is_string($username) && $username !== '') {
        return $username;
    }

    $stmt = $pdo->prepare('SELECT username FROM app_usuarios WHERE LOWER(email) = ? AND is_deleted = 0 LIMIT 1');
    $stmt->execute([$identifier]);
    $username = $stmt->fetchColumn();

    return is_string($username) ? $username : $identifier;
}

function grooflow_is_admin_user(array $row): bool
{
    $nivel = (int) ($row['nivel_id'] ?? 0);
    $role = (string) ($row['grooflow_role'] ?? '');

    return $nivel === 2 || in_array($role, ['super_admin', 'admin'], true);
}

/** @return list<string> */
function grooflow_sede_names(PDO $pdo, int $userId, int $nivelId, ?array $overlaySedes, ?bool $overlayAllSedes): array
{
    if ($overlayAllSedes === true) {
        return [];
    }
    if (is_array($overlaySedes) && $overlaySedes !== []) {
        return array_values(array_map('strval', $overlaySedes));
    }
    $sedes = auth_user_sedes($pdo, $userId);
    $names = [];
    foreach ($sedes as $sede) {
        $nombre = trim((string) ($sede['nombre'] ?? ''));
        if ($nombre !== '') {
            $names[] = $nombre;
        }
    }
    if ($names === [] && function_exists('auth_nivel_has_full_menu') && auth_nivel_has_full_menu($pdo, $nivelId)) {
        return [];
    }

    return $names;
}

function grooflow_all_sedes_flag(PDO $pdo, int $userId, int $nivelId, ?array $overlaySedes, ?bool $overlayAllSedes): bool
{
    if ($overlayAllSedes !== null) {
        return $overlayAllSedes;
    }
    if (is_array($overlaySedes) && $overlaySedes !== []) {
        return false;
    }
    $assigned = auth_user_sedes_assigned($pdo, $userId);

    return $assigned === [] && auth_nivel_has_full_menu($pdo, $nivelId);
}

/** @return list<array<string, mixed>> */
function grooflow_list_users(PDO $pdo): array
{
    grooflow_ensure_schema($pdo);
    if (! table_exists($pdo, 'app_usuarios')) {
        return [];
    }

    $sql = '
        SELECT u.*, n.nombre AS nivel_nombre
        FROM app_usuarios u
        LEFT JOIN app_niveles n ON n.id = u.nivel_id
        WHERE u.is_deleted = 0
        ORDER BY u.id ASC
    ';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $out[] = grooflow_user_to_app($pdo, $row);
    }

    return $out;
}

/** @param array<string, mixed> $row */
function grooflow_user_to_app(PDO $pdo, array $row): array
{
    $id = (int) $row['id'];
    $perfil = grooflow_load_perfil($pdo, $id);
    $nivelId = (int) ($row['nivel_id'] ?? 0);
    $role = (string) ($perfil['role_id'] ?? '');
    if ($role === '') {
        $role = grooflow_default_role_for_nivel($nivelId);
    }
    if ($nivelId === 2) {
        $role = 'super_admin';
    }

    $name = grooflow_display_name($row);
    $email = strtolower(trim((string) ($row['email'] ?? '')));
    $username = strtolower(trim((string) ($row['username'] ?? '')));
    $overlaySedes = isset($perfil['sedes_json']) ? grooflow_json_decode((string) $perfil['sedes_json']) : null;
    $overlaySedes = is_array($overlaySedes) ? $overlaySedes : null;
    $overlayAll = isset($perfil['all_sedes']) ? ((int) $perfil['all_sedes'] === 1) : null;
    $extra = isset($perfil['extra_json']) ? grooflow_json_decode((string) $perfil['extra_json']) : [];
    $extra = is_array($extra) ? $extra : [];

    $user = [
        'id' => (string) $id,
        'name' => $name,
        'initials' => (string) ($perfil['initials'] ?? '') ?: grooflow_initials($name, $username),
        'role' => $role,
        'email' => $email !== '' ? $email : $username,
        'location' => $perfil['location'] ?? ($extra['location'] ?? null),
        'sedes' => grooflow_sede_names($pdo, $id, $nivelId, $overlaySedes, $overlayAll),
        'allSedes' => grooflow_all_sedes_flag($pdo, $id, $nivelId, $overlaySedes, $overlayAll),
        'status' => ((string) ($row['estado'] ?? 'activo')) === 'activo' ? 'active' : 'inactive',
        'lastLogin' => ! empty($row['ultima_sesion'])
            ? date('c', strtotime((string) $row['ultima_sesion']))
            : ($extra['lastLogin'] ?? null),
    ];

    if (isset($perfil['petty_cash_fund_enabled']) && $perfil['petty_cash_fund_enabled'] !== null) {
        $user['pettyCashFundEnabled'] = (int) $perfil['petty_cash_fund_enabled'] === 1;
    } elseif (array_key_exists('pettyCashFundEnabled', $extra)) {
        $user['pettyCashFundEnabled'] = (bool) $extra['pettyCashFundEnabled'];
    }
    if ($perfil['petty_cash_limit'] !== null && $perfil['petty_cash_limit'] !== '') {
        $user['pettyCashLimit'] = (float) $perfil['petty_cash_limit'];
    } elseif (isset($extra['pettyCashLimit'])) {
        $user['pettyCashLimit'] = $extra['pettyCashLimit'];
    }
    if (! empty($perfil['petty_cash_opening_carry_suggested'])) {
        $user['pettyCashOpeningCarrySuggested'] = (float) $perfil['petty_cash_opening_carry_suggested'];
    }
    if (! empty($perfil['petty_cash_opening_carry_consumed_at'])) {
        $user['pettyCashOpeningCarryConsumedAt'] = (string) $perfil['petty_cash_opening_carry_consumed_at'];
    }
    if (! empty($perfil['avatar_url'])) {
        $user['avatarUrl'] = (string) $perfil['avatar_url'];
    } elseif (! empty($extra['avatarUrl'])) {
        $user['avatarUrl'] = (string) $extra['avatarUrl'];
    }
    $theme = $extra['theme'] ?? null;
    if ($theme === 'light' || $theme === 'dark') {
        $user['theme'] = $theme;
    }

    return $user;
}

function grooflow_load_perfil(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT * FROM grooflow_perfiles WHERE usuario_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
}

function grooflow_ensure_perfil(PDO $pdo, int $userId, int $nivelId): void
{
    $existing = grooflow_load_perfil($pdo, $userId);
    if ($existing !== []) {
        if ($nivelId === 2 && ($existing['role_id'] ?? '') !== 'super_admin') {
            $pdo->prepare('UPDATE grooflow_perfiles SET role_id = ? WHERE usuario_id = ?')
                ->execute(['super_admin', $userId]);
        }

        return;
    }
    $role = grooflow_default_role_for_nivel($nivelId);
    $pdo->prepare('
        INSERT INTO grooflow_perfiles (usuario_id, role_id, all_sedes)
        VALUES (?, ?, ?)
    ')->execute([$userId, $role, $role === 'super_admin' ? 1 : 0]);
}

/** @param array<string, mixed> $user */
function grooflow_upsert_user_from_app(PDO $pdo, array $user, bool $allowCreate = false): array
{
    grooflow_ensure_schema($pdo);
    $id = (int) preg_replace('/\D+/', '', (string) ($user['id'] ?? '0'));
    $email = strtolower(trim((string) ($user['email'] ?? '')));
    $name = trim((string) ($user['name'] ?? ''));
    $role = grooflow_clamp_assignable_role((string) ($user['role'] ?? 'groomer'));
    $status = (($user['status'] ?? 'active') === 'inactive') ? 'inactivo' : 'activo';

    $row = null;
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM app_usuarios WHERE id = ? AND is_deleted = 0 LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (! $row && $email !== '') {
        $stmt = $pdo->prepare('SELECT * FROM app_usuarios WHERE LOWER(email) = ? AND is_deleted = 0 LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) {
            $id = (int) $row['id'];
        }
    }

    if (! $row) {
        if (! $allowCreate) {
            throw new RuntimeException('Usuario no encontrado en Gestión');
        }
        $created = grooflow_create_gestion_user($pdo, $user);

        return grooflow_user_to_app($pdo, $created);
    }

    $nivelId = (int) $row['nivel_id'];
    if ($nivelId === 2) {
        $role = 'super_admin';
    }

    [$nombre, $apellido] = grooflow_split_name($name !== '' ? $name : grooflow_display_name($row));
    $pdo->prepare('
        UPDATE app_usuarios
        SET nombre = ?, apellido = ?, email = ?, estado = ?, ultima_sesion = ultima_sesion
        WHERE id = ?
    ')->execute([
        $nombre,
        $apellido,
        $email !== '' ? $email : ($row['email'] ?? null),
        $status,
        $id,
    ]);

    grooflow_save_perfil($pdo, $id, $user, $role, $nivelId);
    $stmt = $pdo->prepare('SELECT u.*, n.nombre AS nivel_nombre FROM app_usuarios u LEFT JOIN app_niveles n ON n.id = u.nivel_id WHERE u.id = ?');
    $stmt->execute([$id]);

    return grooflow_user_to_app($pdo, $stmt->fetch(PDO::FETCH_ASSOC) ?: $row);
}

/** @param array<string, mixed> $user */
function grooflow_save_perfil(PDO $pdo, int $userId, array $user, string $role, int $nivelId): void
{
    grooflow_ensure_perfil($pdo, $userId, $nivelId);
    $existingPerfil = grooflow_load_perfil($pdo, $userId);
    $existingExtra = isset($existingPerfil['extra_json'])
        ? grooflow_json_decode((string) $existingPerfil['extra_json'])
        : [];
    $existingExtra = is_array($existingExtra) ? $existingExtra : [];
    $sedes = isset($user['sedes']) && is_array($user['sedes']) ? array_values($user['sedes']) : null;
    $allSedes = array_key_exists('allSedes', $user) ? (! empty($user['allSedes']) ? 1 : 0) : null;
    $extra = $user;
    unset($extra['id'], $extra['name'], $extra['email'], $extra['role'], $extra['status'], $extra['sedes'], $extra['allSedes']);
    if (! array_key_exists('theme', $extra) && isset($existingExtra['theme'])) {
        $extra['theme'] = $existingExtra['theme'];
    }

    $pdo->prepare('
        UPDATE grooflow_perfiles
        SET role_id = ?,
            initials = ?,
            sedes_json = ?,
            all_sedes = COALESCE(?, all_sedes),
            petty_cash_fund_enabled = ?,
            petty_cash_limit = ?,
            petty_cash_opening_carry_suggested = ?,
            petty_cash_opening_carry_consumed_at = ?,
            avatar_url = ?,
            location = ?,
            extra_json = ?
        WHERE usuario_id = ?
    ')->execute([
        $role,
        isset($user['initials']) ? (string) $user['initials'] : null,
        $sedes !== null ? grooflow_json_encode($sedes) : null,
        $allSedes,
        array_key_exists('pettyCashFundEnabled', $user) ? (! empty($user['pettyCashFundEnabled']) ? 1 : 0) : null,
        array_key_exists('pettyCashLimit', $user) && $user['pettyCashLimit'] !== null && $user['pettyCashLimit'] !== ''
            ? (float) $user['pettyCashLimit']
            : null,
        array_key_exists('pettyCashOpeningCarrySuggested', $user) && $user['pettyCashOpeningCarrySuggested'] !== null
            ? (float) $user['pettyCashOpeningCarrySuggested']
            : null,
        isset($user['pettyCashOpeningCarryConsumedAt']) ? (string) $user['pettyCashOpeningCarryConsumedAt'] : null,
        isset($user['avatarUrl']) ? (string) $user['avatarUrl'] : null,
        isset($user['location']) ? (string) $user['location'] : null,
        grooflow_json_encode($extra),
        $userId,
    ]);
}

/** @param array<string, mixed> $user */
function grooflow_create_gestion_user(PDO $pdo, array $user, string $password = ''): array
{
    $email = strtolower(trim((string) ($user['email'] ?? '')));
    $name = trim((string) ($user['name'] ?? $email));
    $role = grooflow_clamp_assignable_role((string) ($user['role'] ?? 'groomer'));
    $username = $email !== '' ? explode('@', $email)[0] : ('gf' . substr(bin2hex(random_bytes(4)), 0, 8));
    $username = strtolower(preg_replace('/[^a-z0-9._-]+/', '', $username) ?: ('gf' . time()));
    $base = $username;
    $n = 1;
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM app_usuarios WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        if (! $stmt->fetchColumn()) {
            break;
        }
        $n++;
        $username = $base . $n;
    }
    if ($password === '') {
        $password = (string) ($user['tempPassword'] ?? '');
    }
    if ($password === '') {
        $password = bin2hex(random_bytes(6));
    } else {
        grooflow_validate_password($password);
    }

    [$nombre, $apellido] = grooflow_split_name($name);
    $nivelId = grooflow_default_nivel_for_role($role);
    $nivelOk = $pdo->prepare('SELECT id FROM app_niveles WHERE id = ? LIMIT 1');
    $nivelOk->execute([$nivelId]);
    if (! $nivelOk->fetchColumn()) {
        $nivelId = (int) $pdo->query('SELECT id FROM app_niveles ORDER BY id ASC LIMIT 1')->fetchColumn();
    }
    if ($nivelId <= 0) {
        throw new RuntimeException('No hay niveles de usuario en Gestión');
    }
    $sedes = [];
    if (empty($user['allSedes']) && ! empty($user['sedes']) && is_array($user['sedes'])) {
        $sedes = grooflow_sede_ids_from_names($pdo, $user['sedes']);
    }
    if ($sedes === [] && table_exists($pdo, 'tenants')) {
        $all = auth_all_tenant_sedes($pdo);
        foreach ($all as $sede) {
            $sedes[] = (int) ($sede['id'] ?? $sede['centro_id'] ?? 0);
        }
        $sedes = array_values(array_filter($sedes));
    }

    $created = usuarios_create($pdo, [
        'username' => $username,
        'password' => $password,
        'nivel_id' => $nivelId,
        'nombre' => $nombre,
        'apellido' => $apellido,
        'email' => $email !== '' ? $email : null,
        'estado' => (($user['status'] ?? 'active') === 'inactive') ? 'inactivo' : 'activo',
        'sedes' => $sedes,
    ]);
    $id = (int) ($created['id'] ?? 0);
    grooflow_save_perfil($pdo, $id, $user, $role, $nivelId);
    $stmt = $pdo->prepare('SELECT u.*, n.nombre AS nivel_nombre FROM app_usuarios u LEFT JOIN app_niveles n ON n.id = u.nivel_id WHERE u.id = ?');
    $stmt->execute([$id]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: $created;
}

/** @param list<string> $names @return list<int> */
function grooflow_sede_ids_from_names(PDO $pdo, array $names): array
{
    if (! table_exists($pdo, 'tenants') || $names === []) {
        return [];
    }
    $ids = [];
    foreach (auth_all_tenant_sedes($pdo) as $sede) {
        $nombre = trim((string) ($sede['nombre'] ?? ''));
        if ($nombre !== '' && in_array($nombre, $names, true)) {
            $ids[] = (int) ($sede['id'] ?? $sede['centro_id'] ?? 0);
        }
    }

    return array_values(array_filter($ids));
}

function grooflow_soft_delete_user(PDO $pdo, string $idOrEmail): void
{
    $user = grooflow_find_gestion_user($pdo, $idOrEmail);
    if (! $user) {
        return;
    }
    $id = (int) $user['id'];
    if ((int) $user['nivel_id'] === 2) {
        throw new RuntimeException('No se puede eliminar al Administrador del Sistema');
    }
    $pdo->prepare('UPDATE app_usuarios SET is_deleted = 1, estado = ? WHERE id = ?')
        ->execute(['inactivo', $id]);
}

function grooflow_find_gestion_user(PDO $pdo, string $idOrEmail): ?array
{
    $idOrEmail = trim($idOrEmail);
    if ($idOrEmail === '') {
        return null;
    }
    $id = (int) $idOrEmail;
    if ($id > 0 && (string) $id === $idOrEmail) {
        $stmt = $pdo->prepare('SELECT * FROM app_usuarios WHERE id = ? AND is_deleted = 0 LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
    $key = strtolower($idOrEmail);
    $stmt = $pdo->prepare('
        SELECT * FROM app_usuarios
        WHERE is_deleted = 0 AND (LOWER(email) = ? OR username = ?)
        LIMIT 1
    ');
    $stmt->execute([$key, $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function grooflow_set_password(PDO $pdo, string $idOrEmail, string $password): void
{
    grooflow_validate_password($password);
    $user = grooflow_find_gestion_user($pdo, $idOrEmail);
    if (! $user) {
        throw new RuntimeException('Usuario no encontrado');
    }
    $pdo->prepare('UPDATE app_usuarios SET password_hash = ? WHERE id = ?')
        ->execute([api_password_hash($password), (int) $user['id']]);
}

function grooflow_set_enabled(PDO $pdo, string $idOrEmail, bool $enabled): void
{
    $user = grooflow_find_gestion_user($pdo, $idOrEmail);
    if (! $user) {
        throw new RuntimeException('Usuario no encontrado');
    }
    $pdo->prepare('UPDATE app_usuarios SET estado = ? WHERE id = ?')
        ->execute([$enabled ? 'activo' : 'inactivo', (int) $user['id']]);
}

/** @return list<array<string, mixed>> */
function grooflow_list_roles(PDO $pdo): array
{
    grooflow_ensure_schema($pdo);
    $rows = $pdo->query('SELECT * FROM grooflow_roles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $out[] = grooflow_role_to_app($row);
    }

    return $out !== [] ? $out : grooflow_default_roles();
}

function grooflow_role_to_app(array $row): array
{
    $permissions = grooflow_json_decode((string) ($row['permissions'] ?? '{}'));

    return [
        'id' => (string) $row['id'],
        'name' => (string) $row['name'],
        'description' => (string) ($row['description'] ?? ''),
        'color' => (string) ($row['color'] ?? ''),
        'bgColor' => (string) ($row['bg_color'] ?? ''),
        'borderColor' => (string) ($row['border_color'] ?? ''),
        'isSystem' => (int) ($row['is_system'] ?? 0) === 1,
        'permissions' => is_array($permissions) ? $permissions : [],
    ];
}

/** @param array<string, mixed> $role */
function grooflow_upsert_role(PDO $pdo, array $role): array
{
    grooflow_ensure_schema($pdo);
    $id = trim((string) ($role['id'] ?? ''));
    if ($id === '') {
        throw new InvalidArgumentException('El rol necesita id');
    }
    $stmt = $pdo->prepare('SELECT id FROM grooflow_roles WHERE id = ?');
    $stmt->execute([$id]);
    $exists = (bool) $stmt->fetchColumn();
    $params = [
        (string) ($role['name'] ?? $id),
        (string) ($role['description'] ?? ''),
        (string) ($role['color'] ?? ''),
        (string) ($role['bgColor'] ?? $role['bg_color'] ?? ''),
        (string) ($role['borderColor'] ?? $role['border_color'] ?? ''),
        ! empty($role['isSystem']) ? 1 : 0,
        grooflow_json_encode($role['permissions'] ?? []),
        $id,
    ];
    if ($exists) {
        $pdo->prepare('
            UPDATE grooflow_roles
            SET name = ?, description = ?, color = ?, bg_color = ?, border_color = ?, is_system = ?, permissions = ?
            WHERE id = ?
        ')->execute($params);
    } else {
        $pdo->prepare('
            INSERT INTO grooflow_roles (name, description, color, bg_color, border_color, is_system, permissions, id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute($params);
    }
    $stmt = $pdo->prepare('SELECT * FROM grooflow_roles WHERE id = ?');
    $stmt->execute([$id]);

    return grooflow_role_to_app($stmt->fetch(PDO::FETCH_ASSOC) ?: $role);
}

function grooflow_delete_role(PDO $pdo, string $id): void
{
    if (in_array($id, ['super_admin', 'auditoria'], true)) {
        throw new RuntimeException('No se puede eliminar un rol de sistema');
    }
    $pdo->prepare('DELETE FROM grooflow_roles WHERE id = ? AND is_system = 0')->execute([$id]);
}

/** @param list<array<string, mixed>> $users */
function grooflow_replace_users(PDO $pdo, array $users): void
{
    foreach ($users as $user) {
        if (! is_array($user)) {
            continue;
        }
        try {
            grooflow_upsert_user_from_app($pdo, $user, false);
        } catch (Throwable $e) {
            // Autosave no debe crear usuarios fantasma ni borrar Gestión.
        }
    }
}

/** @param list<array<string, mixed>> $roles */
function grooflow_replace_roles(PDO $pdo, array $roles): void
{
    foreach ($roles as $role) {
        if (is_array($role) && ! empty($role['id'])) {
            grooflow_upsert_role($pdo, $role);
        }
    }
}

function grooflow_set_own_theme(PDO $pdo, array $row, string $theme): void
{
    if ($theme !== 'light' && $theme !== 'dark') {
        throw new InvalidArgumentException('Tema inválido');
    }
    $userId = (int) ($row['id'] ?? 0);
    if ($userId <= 0) {
        throw new RuntimeException('Sesión inválida');
    }
    grooflow_ensure_schema($pdo);
    grooflow_ensure_perfil($pdo, $userId, (int) ($row['nivel_id'] ?? 0));
    $perfil = grooflow_load_perfil($pdo, $userId);
    $extra = isset($perfil['extra_json']) ? grooflow_json_decode((string) $perfil['extra_json']) : [];
    $extra = is_array($extra) ? $extra : [];
    $extra['theme'] = $theme;
    $pdo->prepare('UPDATE grooflow_perfiles SET extra_json = ? WHERE usuario_id = ?')
        ->execute([grooflow_json_encode($extra), $userId]);
}

