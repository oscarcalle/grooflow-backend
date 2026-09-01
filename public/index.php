<?php

declare(strict_types=1);

$grooflowRoot = dirname(__DIR__, 2);
if (! is_file($grooflowRoot . '/config.php')) {
    $grooflowRoot = dirname(__DIR__, 3);
}
require_once $grooflowRoot . '/config.php';
require_once $grooflowRoot . '/backend/lib/dashboard_helpers.php';
require_once $grooflowRoot . '/backend/lib/api_request.php';
require_once $grooflowRoot . '/backend/lib/auth_api.php';
require_once dirname(__DIR__) . '/lib/grooflow_cors.php';
require_once dirname(__DIR__) . '/lib/grooflow_schema.php';
require_once dirname(__DIR__) . '/lib/grooflow_users.php';
require_once dirname(__DIR__) . '/lib/grooflow_acl.php';
require_once dirname(__DIR__) . '/lib/grooflow_kv.php';
require_once dirname(__DIR__) . '/lib/grooflow_collections.php';
require_once dirname(__DIR__) . '/lib/grooflow_proxy.php';
require_once dirname(__DIR__) . '/lib/grooflow_audit.php';
require_once dirname(__DIR__) . '/lib/grooflow_menu.php';
require_once dirname(__DIR__) . '/lib/grooflow_usuario_menu.php';

unset($_GET['token']);

grooflow_cors_headers();

// Todas las rutas del API GrooFlow deben tratarse como cliente grooflow (permiso acceso_grooflow en login).
$_SERVER['HTTP_X_GROOMERS_CLIENT'] = 'grooflow';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    grooflow_dispatch($pdo);
} catch (AuthDailyLimitException $e) {
    api_json_response(['ok' => false, 'error' => $e->getMessage()], 429);
} catch (InvalidArgumentException $e) {
    api_json_response(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (RuntimeException $e) {
    $msg = $e->getMessage();
    $lower = strtolower($msg);
    $status = 400;
    if (str_contains($lower, 'no encontrado') || str_contains($lower, 'inválid')) {
        $status = 404;
    }
    if (str_contains($lower, 'sesión') || str_contains($lower, 'contraseña') || str_contains($lower, 'credencial')) {
        $status = 401;
    }
    if (str_contains($lower, 'permiso') || str_contains($lower, 'administrador')) {
        $status = 403;
    }
    api_json_response(['ok' => false, 'error' => $msg], $status);
} catch (Throwable $e) {
    error_log('[grooflow] ' . $e->getMessage());
    api_json_response(['ok' => false, 'error' => 'Error interno de GrooFlow'], 500);
}

function grooflow_request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = rawurldecode($path);
    foreach (['/grooflow/api', '/grooflow-backend/public'] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix)) ?: '/';
            break;
        }
    }
    $path = '/' . ltrim($path, '/');

    return $path === '/' ? '/' : rtrim($path, '/');
}

function grooflow_dispatch(PDO $pdo): void
{
    grooflow_ensure_schema($pdo);
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 8 * 1024 * 1024) {
        api_json_response(['ok' => false, 'error' => 'Payload demasiado grande'], 413);

        return;
    }
    $method = api_request_method();
    $path = grooflow_request_path();

    if ($path === '/' || $path === '/health') {
        api_json_response(['ok' => true, 'service' => 'grooflow', 'time' => date('c')]);

        return;
    }

    if ($path === '/auth/login' && $method === 'POST') {
        $data = api_request_json();
        $identifier = (string) ($data['username'] ?? $data['email'] ?? $data['identifier'] ?? '');
        $password = (string) ($data['password'] ?? '');
        $username = grooflow_resolve_username($pdo, $identifier);
        $result = auth_api_login($pdo, $username, $password);
        $row = auth_user_by_token($pdo, (string) ($result['token'] ?? ''));
        if ($row) {
            grooflow_ensure_perfil($pdo, (int) $row['id'], (int) $row['nivel_id']);
        }
        $appUser = $row ? grooflow_user_to_app($pdo, $row) : null;
        $nivelId = $row ? (int) ($row['nivel_id'] ?? 0) : 0;
        api_json_response([
            'ok' => true,
            'token' => $result['token'] ?? '',
            'user' => [
                'id' => (string) ($appUser['id'] ?? $result['user']['id'] ?? ''),
                'email' => (string) ($appUser['email'] ?? $result['user']['email'] ?? $username),
                'name' => (string) ($appUser['name'] ?? $result['user']['display_name'] ?? $username),
            ],
            'profile' => $appUser,
            'menu_permissions' => $row ? grooflow_menu_permissions_for_nivel($pdo, $nivelId) : [],
            'menu' => $row ? grooflow_menu_nav_for_user($pdo, $nivelId) : [],
            'menu_sections' => $row ? grooflow_menu_nav_sections_for_user($pdo, $nivelId) : [],
            'nivel_id' => $nivelId,
        ]);

        return;
    }

    if ($path === '/auth/logout' && $method === 'POST') {
        $token = api_bearer_token();
        if ($token !== '') {
            auth_api_logout($pdo, $token);
        }
        api_json_response(['ok' => true]);

        return;
    }

    api_require_auth($pdo);

    if ($path === '/bootstrap' && $method === 'GET') {
        $values = grooflow_kv_bootstrap($pdo);
        foreach ($values as $key => $value) {
            $values[$key] = grooflow_kv_value_for_caller($pdo, (string) $key, $value);
        }
        api_json_response(['ok' => true, 'values' => $values]);

        return;
    }

    if ($path === '/auth/me' && $method === 'GET') {
        $row = api_current_user();
        if (! is_array($row)) {
            throw new RuntimeException('Sesión inválida');
        }
        grooflow_ensure_perfil($pdo, (int) $row['id'], (int) ($row['nivel_id'] ?? 0));
        $appUser = grooflow_user_to_app($pdo, $row);
        $nivelId = (int) ($row['nivel_id'] ?? 0);
        $menuPermissions = grooflow_menu_permissions_for_nivel($pdo, $nivelId);
        api_json_response([
            'ok' => true,
            'user' => [
                'id' => (string) $appUser['id'],
                'email' => (string) $appUser['email'],
                'name' => (string) $appUser['name'],
            ],
            'profile' => $appUser,
            'menu_permissions' => $menuPermissions,
            'menu' => grooflow_menu_nav_for_user($pdo, $nivelId),
            'menu_sections' => grooflow_menu_nav_sections_for_user($pdo, $nivelId),
            'nivel_id' => $nivelId,
        ]);

        return;
    }

    if ($path === '/auth/theme' && $method === 'POST') {
        $row = api_current_user();
        if (! is_array($row)) {
            throw new RuntimeException('Sesión inválida');
        }
        $data = api_request_json();
        grooflow_set_own_theme($pdo, $row, (string) ($data['theme'] ?? ''));
        api_json_response(['ok' => true]);

        return;
    }

    if ($path === '/audit' && $method === 'POST') {
        $row = api_current_user();
        if (! is_array($row)) {
            throw new RuntimeException('Sesión inválida');
        }
        $data = api_request_json();
        $metadata = $data['metadata'] ?? [];
        grooflow_audit_insert(
            $pdo,
            $row,
            (string) ($data['action'] ?? ''),
            is_array($metadata) ? $metadata : [],
            isset($data['targetUserId']) ? (string) $data['targetUserId'] : null
        );
        api_json_response(['ok' => true]);

        return;
    }

    if ($path === '/audit' && $method === 'GET') {
        grooflow_assert_admin($pdo);
        $limit = (int) ($_GET['limit'] ?? 80);
        api_json_response(['ok' => true, 'rows' => grooflow_audit_list($pdo, $limit)]);

        return;
    }

    if ($path === '/auth/password' && $method === 'POST') {
        grooflow_assert_admin($pdo);
        $data = api_request_json();
        grooflow_set_password(
            $pdo,
            (string) ($data['userId'] ?? $data['email'] ?? $data['id'] ?? ''),
            (string) ($data['password'] ?? $data['newPassword'] ?? '')
        );
        api_json_response(['ok' => true]);

        return;
    }

    if ($path === '/auth/enabled' && $method === 'POST') {
        grooflow_assert_admin($pdo);
        $data = api_request_json();
        grooflow_set_enabled(
            $pdo,
            (string) ($data['userId'] ?? $data['email'] ?? $data['id'] ?? ''),
            (bool) ($data['enabled'] ?? true)
        );
        api_json_response(['ok' => true]);

        return;
    }

    if ($path === '/auth/create-user' && $method === 'POST') {
        grooflow_assert_admin($pdo);
        $data = api_request_json();
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $existing = $email !== '' ? grooflow_find_gestion_user($pdo, $email) : null;
        if ($existing) {
            $app = grooflow_user_to_app($pdo, $existing);
            api_json_response([
                'ok' => true,
                'user' => ['id' => (string) $app['id'], 'email' => (string) $app['email'], 'name' => (string) $app['name'], 'existing' => true],
            ]);

            return;
        }
        $row = grooflow_create_gestion_user($pdo, [
            'email' => $email,
            'name' => (string) ($data['name'] ?? $email),
            'role' => (string) ($data['role'] ?? 'groomer'),
            'allSedes' => true,
        ], (string) ($data['password'] ?? ''));
        $app = grooflow_user_to_app($pdo, $row);
        api_json_response([
            'ok' => true,
            'user' => ['id' => (string) $app['id'], 'email' => (string) $app['email'], 'name' => (string) $app['name']],
        ]);

        return;
    }

    if ($path === '/proxy/veterinari/test' && $method === 'POST') {
        grooflow_assert_admin($pdo);
        api_json_response(['ok' => true, ...grooflow_handle_veterinari_test(api_request_json())]);

        return;
    }

    if (preg_match('#^/proxy/buk/(test|fetch|fetch-all|probe)$#', $path, $m) && $method === 'POST') {
        if ($m[1] === 'test' || $m[1] === 'probe') {
            grooflow_assert_admin($pdo);
        }
        api_json_response(['ok' => true, ...grooflow_handle_buk($pdo, $m[1], api_request_json())]);

        return;
    }

    if ($path === '/menu/tree' && $method === 'GET') {
        grooflow_assert_admin($pdo);
        api_json_response(['ok' => true, 'items' => grooflow_menu_list_tree($pdo), ...grooflow_menu_tree($pdo)]);

        return;
    }

    if ($path === '/menu/reorder' && $method === 'POST') {
        grooflow_assert_admin($pdo);
        $data = api_request_json();
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $rows = grooflow_menu_reorder($pdo, $items);
        api_json_response(['ok' => true, 'items' => $rows]);

        return;
    }

    if ($path === '/menu' && $method === 'POST') {
        grooflow_assert_admin($pdo);
        $item = grooflow_menu_create($pdo, api_request_json());
        api_json_response(['ok' => true, 'item' => $item]);

        return;
    }

    if ($path === '/menu' && ($method === 'PUT' || $method === 'PATCH')) {
        grooflow_assert_admin($pdo);
        $data = api_request_json();
        $id = (int) ($data['id'] ?? 0);
        $item = grooflow_menu_update($pdo, $id, $data);
        api_json_response(['ok' => true, 'item' => $item]);

        return;
    }

    if ($path === '/menu' && $method === 'DELETE') {
        grooflow_assert_admin($pdo);
        $id = (int) ($_GET['id'] ?? 0);
        grooflow_menu_delete($pdo, $id);
        api_json_response(['ok' => true]);

        return;
    }

    if ($path === '/nivel-menu/matrix' && $method === 'GET') {
        grooflow_assert_admin($pdo);
        api_json_response(['ok' => true, ...grooflow_nivel_menu_matrix($pdo)]);

        return;
    }

    if ($path === '/nivel-menu/nivel' && $method === 'GET') {
        grooflow_assert_admin($pdo);
        $nivelId = (int) ($_GET['nivel_id'] ?? 0);
        api_json_response(['ok' => true, ...grooflow_nivel_menu_for_nivel($pdo, $nivelId)]);

        return;
    }

    if ($path === '/nivel-menu/sync' && ($method === 'PUT' || $method === 'POST')) {
        grooflow_assert_admin($pdo);
        $data = api_request_json();
        $nivelId = (int) ($data['nivel_id'] ?? 0);
        $menuIds = is_array($data['menu_ids'] ?? null) ? $data['menu_ids'] : [];
        $menuPermissions = is_array($data['menu_permissions'] ?? null) ? $data['menu_permissions'] : [];
        $result = grooflow_nivel_menu_sync($pdo, $nivelId, $menuIds, $menuPermissions);
        api_json_response(['ok' => true, ...$result]);

        return;
    }

    if ($path === '/nivel-menu/apply-users' && $method === 'POST') {
        grooflow_assert_admin($pdo);
        $data = api_request_json();
        $nivelId = (int) ($data['nivel_id'] ?? 0);
        $onlyWithExtras = ! array_key_exists('only_with_extras', $data) || ! empty($data['only_with_extras']);
        $cleared = grooflow_nivel_menu_apply_to_users($pdo, $nivelId, $onlyWithExtras);
        api_json_response(['ok' => true, 'cleared' => $cleared]);

        return;
    }

    if ($path === '/usuarios/list' && $method === 'GET') {
        grooflow_assert_admin($pdo);
        api_json_response(['ok' => true, 'items' => grooflow_usuarios_list($pdo)]);

        return;
    }

    if ($path === '/usuario-menu/unassigned' && $method === 'GET') {
        grooflow_assert_admin($pdo);
        api_json_response(['ok' => true, 'items' => grooflow_usuario_menu_unassigned_users($pdo)]);

        return;
    }

    if ($path === '/usuario-menu/user' && $method === 'GET') {
        grooflow_assert_admin($pdo);
        $usuarioId = (int) ($_GET['usuario_id'] ?? 0);
        api_json_response(['ok' => true, ...grooflow_usuario_menu_for_user($pdo, $usuarioId)]);

        return;
    }

    if ($path === '/usuario-menu/sync' && ($method === 'PUT' || $method === 'POST')) {
        grooflow_assert_admin($pdo);
        $data = api_request_json();
        $usuarioId = (int) ($data['usuario_id'] ?? 0);
        $menuIds = is_array($data['menu_ids'] ?? null) ? $data['menu_ids'] : [];
        $result = grooflow_usuario_menu_sync($pdo, $usuarioId, $menuIds);
        api_json_response(['ok' => true, ...$result]);

        return;
    }

    if ($path === '/usuario-menu/assign-dashboard' && $method === 'POST') {
        grooflow_assert_admin($pdo);
        $data = api_request_json();
        $usuarioId = isset($data['usuario_id']) ? (int) $data['usuario_id'] : null;
        if ($usuarioId !== null && $usuarioId <= 0) {
            $usuarioId = null;
        }
        $assigned = grooflow_usuario_menu_assign_dashboard($pdo, $usuarioId);
        api_json_response(['ok' => true, 'assigned' => $assigned]);

        return;
    }

    if ($path === '/niveles' && $method === 'GET') {
        grooflow_assert_admin($pdo);
        api_json_response(['ok' => true, 'items' => grooflow_niveles_list($pdo)]);

        return;
    }

    if (preg_match('#^/kv/(.+)$#', $path, $m)) {
        $key = grooflow_normalize_kv_key($m[1]);
        if ($method === 'GET') {
            $value = grooflow_kv_value_for_caller($pdo, $key, grooflow_kv_get($pdo, $key));
            api_json_response(['ok' => true, 'key' => $key, 'value' => $value]);

            return;
        }
        if ($method === 'PUT' || $method === 'POST') {
            $data = api_request_json();
            $value = array_key_exists('value', $data) ? $data['value'] : $data;
            grooflow_kv_set($pdo, $key, grooflow_prepare_kv_write($pdo, $key, $value));
            api_json_response(['ok' => true]);

            return;
        }
        if ($method === 'DELETE') {
            if (in_array($key, ['data:users', 'data:roles', 'settings:asistencia', 'settings:system'], true)) {
                grooflow_assert_admin($pdo);
            }
            grooflow_kv_delete($pdo, $key);
            api_json_response(['ok' => true]);

            return;
        }
    }

    if (preg_match('#^/collections/([A-Za-z0-9_-]+)$#', $path, $m)) {
        $name = $m[1];
        if ($method === 'GET') {
            api_json_response(['ok' => true, 'items' => grooflow_collection_get_all($pdo, $name)]);

            return;
        }
        if ($method === 'POST') {
            grooflow_enforce_collection_write($pdo, $name);
            $record = api_request_json();
            $created = grooflow_collection_create($pdo, $name, $record);
            api_json_response(['ok' => true, 'item' => $created]);

            return;
        }
    }

    if (preg_match('#^/collections/([A-Za-z0-9_-]+)/upsert$#', $path, $m) && $method === 'POST') {
        grooflow_enforce_collection_write($pdo, $m[1]);
        $data = api_request_json();
        $records = $data['records'] ?? $data['items'] ?? $data;
        grooflow_collection_upsert_many($pdo, $m[1], is_array($records) ? $records : []);
        api_json_response(['ok' => true]);

        return;
    }

    if (preg_match('#^/collections/([A-Za-z0-9_-]+)/([^/]+)$#', $path, $m)) {
        $name = $m[1];
        $id = rawurldecode($m[2]);
        if ($method === 'GET') {
            $item = grooflow_collection_get_one($pdo, $name, $id);
            if ($item === null) {
                api_json_response(['ok' => false, 'error' => 'No encontrado'], 404);

                return;
            }
            api_json_response(['ok' => true, 'item' => $item]);

            return;
        }
        if ($method === 'PUT' || $method === 'PATCH') {
            grooflow_enforce_collection_write($pdo, $name);
            $updated = grooflow_collection_update($pdo, $name, $id, api_request_json());
            api_json_response(['ok' => true, 'item' => $updated]);

            return;
        }
        if ($method === 'DELETE') {
            grooflow_enforce_collection_write($pdo, $name);
            grooflow_collection_delete($pdo, $name, $id);
            api_json_response(['ok' => true]);

            return;
        }
    }

    api_json_response(['ok' => false, 'error' => 'Ruta no encontrada'], 404);
}
