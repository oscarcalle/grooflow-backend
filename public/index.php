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
require_once dirname(__DIR__) . '/lib/grooflow_schema.php';
require_once dirname(__DIR__) . '/lib/grooflow_users.php';
require_once dirname(__DIR__) . '/lib/grooflow_kv.php';
require_once dirname(__DIR__) . '/lib/grooflow_collections.php';
require_once dirname(__DIR__) . '/lib/grooflow_proxy.php';

grooflow_cors_headers();

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
    $status = str_contains(strtolower($msg), 'no encontrado') || str_contains(strtolower($msg), 'inválid')
        ? 404
        : 400;
    if (stripos($msg, 'sesión') !== false || stripos($msg, 'contraseña') !== false || stripos($msg, 'credencial') !== false) {
        $status = 401;
    }
    api_json_response(['ok' => false, 'error' => $msg], $status);
} catch (Throwable $e) {
    error_log('[grooflow] ' . $e->getMessage());
    api_json_response(['ok' => false, 'error' => 'Error interno de GrooFlow'], 500);
}

function grooflow_cors_headers(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = array_filter(array_map('trim', explode(',', env_value('API_CORS_ORIGINS', 'http://localhost:4200') ?? 'http://localhost:4200')));
    $allowed[] = 'http://localhost:5173';
    $allowed[] = 'http://127.0.0.1:5173';
    $allowed[] = 'https://gestionveterinariagroomers.com';
    $allowed[] = 'http://gestionveterinariagroomers.com';
    $allowed = array_values(array_unique($allowed));

    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    } elseif ($origin === '') {
        header('Access-Control-Allow-Origin: *');
    } elseif ($allowed) {
        header('Access-Control-Allow-Origin: ' . $allowed[0]);
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Vary: Origin');
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
        api_json_response([
            'ok' => true,
            'token' => $result['token'] ?? '',
            'user' => [
                'id' => (string) ($appUser['id'] ?? $result['user']['id'] ?? ''),
                'email' => (string) ($appUser['email'] ?? $result['user']['email'] ?? $username),
                'name' => (string) ($appUser['name'] ?? $result['user']['display_name'] ?? $username),
            ],
            'profile' => $appUser,
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
        api_json_response(['ok' => true, 'values' => grooflow_kv_bootstrap($pdo)]);

        return;
    }

    if ($path === '/auth/me' && $method === 'GET') {
        $row = api_current_user();
        if (! is_array($row)) {
            throw new RuntimeException('Sesión inválida');
        }
        grooflow_ensure_perfil($pdo, (int) $row['id'], (int) ($row['nivel_id'] ?? 0));
        $appUser = grooflow_user_to_app($pdo, $row);
        api_json_response([
            'ok' => true,
            'user' => [
                'id' => (string) $appUser['id'],
                'email' => (string) $appUser['email'],
                'name' => (string) $appUser['name'],
            ],
            'profile' => $appUser,
        ]);

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

    if (preg_match('#^/proxy/buk/(test|fetch|fetch-all)$#', $path, $m) && $method === 'POST') {
        grooflow_assert_admin($pdo);
        api_json_response(['ok' => true, ...grooflow_handle_buk($m[1], api_request_json())]);

        return;
    }

    if (preg_match('#^/kv/(.+)$#', $path, $m)) {
        $key = grooflow_normalize_kv_key($m[1]);
        if ($method === 'GET') {
            $value = grooflow_kv_get($pdo, $key);
            api_json_response(['ok' => true, 'key' => $key, 'value' => $value]);

            return;
        }
        if ($method === 'PUT' || $method === 'POST') {
            $data = api_request_json();
            $value = array_key_exists('value', $data) ? $data['value'] : $data;
            grooflow_kv_set($pdo, $key, $value);
            api_json_response(['ok' => true]);

            return;
        }
        if ($method === 'DELETE') {
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
            $record = api_request_json();
            $created = grooflow_collection_create($pdo, $name, $record);
            api_json_response(['ok' => true, 'item' => $created]);

            return;
        }
    }

    if (preg_match('#^/collections/([A-Za-z0-9_-]+)/upsert$#', $path, $m) && $method === 'POST') {
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
            $updated = grooflow_collection_update($pdo, $name, $id, api_request_json());
            api_json_response(['ok' => true, 'item' => $updated]);

            return;
        }
        if ($method === 'DELETE') {
            grooflow_collection_delete($pdo, $name, $id);
            api_json_response(['ok' => true]);

            return;
        }
    }

    api_json_response(['ok' => false, 'error' => 'Ruta no encontrada'], 404);
}

function grooflow_assert_admin(PDO $pdo): void
{
    $row = api_current_user();
    if (! is_array($row)) {
        throw new RuntimeException('Sesión inválida');
    }
    grooflow_ensure_perfil($pdo, (int) $row['id'], (int) ($row['nivel_id'] ?? 0));
    $app = grooflow_user_to_app($pdo, $row);
    $role = (string) ($app['role'] ?? '');
    if ((int) ($row['nivel_id'] ?? 0) !== 2 && ! in_array($role, ['super_admin', 'admin'], true)) {
        throw new RuntimeException('Se requieren permisos de administrador');
    }
}
