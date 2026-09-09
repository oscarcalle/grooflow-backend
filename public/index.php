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
require_once dirname(__DIR__) . '/lib/grooflow_rrhh.php';
require_once dirname(__DIR__) . '/lib/grooflow_lists.php';
require_once dirname(__DIR__) . '/lib/grooflow_pipelines.php';

require_once dirname(__DIR__) . '/lib/grooflow_resource_access.php';
require_once dirname(__DIR__) . '/lib/grooflow_resource_store.php';
require_once dirname(__DIR__) . '/lib/grooflow_self_service.php';
require_once dirname(__DIR__) . '/lib/grooflow_finance_operations.php';

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
} catch (GrooflowConflict $e) {
    api_json_response(['ok' => false, 'error' => $e->getMessage()], 409);
} catch (GrooflowValidation $e) {
    api_json_response(['ok' => false, 'error' => $e->getMessage(), 'errors' => $e->errors], 422);
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

    if (str_starts_with($path, '/proxy/sunat/')) {
        $data = $method === 'POST' ? api_request_json() : $_GET;
        $result = grooflow_handle_sunat('ruc', $data);
        api_json_response($result);

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

    // Jobs programados (Fase 3): clave cron O sesión admin — antes de auth obligatoria.
    if ($path === '/jobs/pipelines' && $method === 'POST') {
        $data = api_request_json();
        $bodyKey = trim((string) ($data['cronKey'] ?? $data['cron_key'] ?? $data['key'] ?? ''));
        $authVia = grooflow_assert_cron_or_admin($pdo, $bodyKey !== '' ? $bodyKey : null);
        $result = grooflow_pipelines_run($pdo, [
            'force' => ! empty($data['force']),
            'forceRrhh' => ! empty($data['forceRrhh']),
            'forceMarcaciones' => ! empty($data['forceMarcaciones']),
            'forceAsistenciaProject' => ! empty($data['forceAsistenciaProject']),
            'skipRrhh' => ! empty($data['skipRrhh']),
            'skipMarcaciones' => ! empty($data['skipMarcaciones']),
            'skipUsuariosEnrich' => ! empty($data['skipUsuariosEnrich']),
            'skipAsistenciaProject' => ! empty($data['skipAsistenciaProject']),
        ]);
        api_json_response([
            'ok' => true,
            'authVia' => $authVia,
            'pipelineOk' => ! empty($result['ok']),
            'ran_at' => $result['ran_at'] ?? null,
            'duration_ms' => $result['duration_ms'] ?? null,
            'steps' => $result['steps'] ?? null,
            'health' => $result['health'] ?? null,
            'policy' => $result['policy'] ?? null,
        ]);

        return;
    }

    if ($path === '/jobs/pipelines/health' && $method === 'GET') {
        grooflow_assert_cron_or_admin($pdo);
        $health = grooflow_pipelines_health($pdo);
        api_json_response([
            'ok' => true,
            'pipelineOk' => ! empty($health['ok']),
            'summary' => $health['summary'] ?? '',
            'issues' => $health['issues'] ?? [],
            'rrhh' => $health['rrhh'] ?? null,
            'marcaciones' => $health['marcaciones'] ?? null,
            'generatedAt' => $health['generatedAt'] ?? date('c'),
        ]);

        return;
    }

    api_require_auth($pdo);
    if (str_starts_with($path, '/rrhh/')) {
        grooflow_assert_module($pdo, ['Recursos Humanos']);
        if (!grooflow_access_context($pdo)['admin'] && !grooflow_access_context($pdo)['allSedes']) throw new RuntimeException('Sin permiso para consultar datos globales de RRHH');
    }
    if (str_starts_with($path, '/asistencia/buk-records')) {
        grooflow_assert_module($pdo, ['Asistencia', 'Recursos Humanos']);
        if (!grooflow_access_context($pdo)['admin'] && !grooflow_access_context($pdo)['allSedes']) throw new RuntimeException('Sin permiso para registros globales Buk');
    }
    if (str_starts_with($path, '/catalog/')) {
        $module = str_contains($path, '/areas') ? 'Catálogo Áreas' : (str_contains($path, '/puestos') ? 'Catálogo Puestos' : 'Catálogo Turnos');
        grooflow_assert_module($pdo, [$module, 'Recursos Humanos']);
    }

    if ($path === '/bootstrap' && $method === 'GET') {
        $values = []; $revisions = [];
        foreach (grooflow_kv_bootstrap_keys() as $key) {
            if (!grooflow_resource_allowed(grooflow_access_context($pdo), $key)) continue;
            $entry = grooflow_read_resource($pdo, $key);
            $values[$key] = $entry['value'];
            $revisions[$key] = $entry['revision'];
        }
        api_json_response(['ok' => true, 'values' => $values, 'revisions' => $revisions]);

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

    if ($path === '/treasury/pay' && $method === 'POST') {
        api_json_response(grooflow_pay_batch($pdo, api_request_json())); return;
    }
    if ($path === '/reports/month' && $method === 'POST') {
        api_json_response(grooflow_month_operation($pdo, api_request_json())); return;
    }

    if ($path === '/auth/profile' && $method === 'PUT') {
        $data = api_request_json();
        $profile = grooflow_atomic($pdo, fn () => grooflow_save_own_profile($pdo, $data));
        api_json_response(['ok' => true, 'profile' => $profile]); return;
    }
    if ($path === '/auth/own-password' && $method === 'POST') {
        $data = api_request_json();
        grooflow_atomic($pdo, fn () => grooflow_own_password($pdo, $data));
        api_json_response(['ok' => true]); return;
    }
    if ($path === '/auth/sessions' && $method === 'GET') {
        api_json_response(['ok' => true, 'items' => grooflow_own_sessions($pdo)]); return;
    }
    if ($path === '/auth/sessions/revoke-others' && $method === 'POST') {
        $count = grooflow_atomic($pdo, fn () => grooflow_revoke_other_sessions($pdo));
        api_json_response(['ok' => true, 'revoked' => $count]); return;
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
        grooflow_assert_module($pdo, ['Auditoría']);
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

    if (preg_match('#^/proxy/buk/(test|fetch|fetch-all|probe|sync-usuarios)$#', $path, $m) && $method === 'POST') {
        if ($m[1] === 'test' || $m[1] === 'probe' || $m[1] === 'sync-usuarios') {
            grooflow_assert_admin($pdo);
        }
        if ($m[1] === 'sync-usuarios') {
            require_once dirname(__DIR__) . '/lib/grooflow_buk_sync.php';
            $result = grooflow_buk_sync_usuarios($pdo, api_request_json());
            api_json_response(['ok' => true, ...$result]);

            return;
        }
        api_json_response(['ok' => true, ...grooflow_handle_buk($pdo, $m[1], api_request_json())]);

        return;
    }

    if (preg_match('#^/proxy/buk-pe/(test|fetch|fetch-all|probe)$#', $path, $m) && $method === 'POST') {
        if ($m[1] === 'test' || $m[1] === 'probe') {
            grooflow_assert_admin($pdo);
        }
        api_json_response(['ok' => true, ...grooflow_handle_buk_pe($pdo, $m[1], api_request_json())]);

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

    if ($path === '/asistencia/buk-records/upsert' && $method === 'POST') {
        require_once dirname(__DIR__) . '/lib/grooflow_asistencia.php';
        $data = api_request_json();
        $records = $data['records'] ?? [];
        if (! is_array($records)) {
            throw new InvalidArgumentException('records debe ser un arreglo');
        }
        $fetchedAt = isset($data['fetchedAt']) ? (string) $data['fetchedAt'] : null;
        $result = grooflow_asistencia_buk_records_upsert($pdo, $records, $fetchedAt);
        api_json_response(['ok' => true, ...$result]);

        return;
    }

    if ($path === '/asistencia/buk-records' && $method === 'GET') {
        require_once dirname(__DIR__) . '/lib/grooflow_asistencia.php';
        $from = (string) ($_GET['from'] ?? '');
        $to = (string) ($_GET['to'] ?? '');
        $recinto = isset($_GET['recinto']) ? (string) $_GET['recinto'] : null;
        if ($from === '' || $to === '') {
            throw new InvalidArgumentException('Parámetros from y to son obligatorios (YYYY-MM-DD)');
        }
        $records = grooflow_asistencia_buk_records_list($pdo, $from, $to, $recinto);
        api_json_response([
            'ok' => true,
            'from' => $from,
            'to' => $to,
            'count' => count($records),
            'data' => $records,
        ]);

        return;
    }

    if ($path === '/asistencia/buk-records/stats' && $method === 'GET') {
        require_once dirname(__DIR__) . '/lib/grooflow_asistencia.php';
        api_json_response(['ok' => true, ...grooflow_asistencia_buk_records_stats($pdo)]);

        return;
    }

    if (preg_match('#^/kv/(.+)$#', $path, $m)) {
        $key = grooflow_normalize_kv_key($m[1]);
        if ($method === 'GET') {
            api_json_response(grooflow_read_resource($pdo, $key));
            return;
        }
        if (in_array($method, ['PUT', 'POST', 'DELETE'], true)) {
            $data = api_request_json();
            if ($key === 'data:monthlyClosures') throw new RuntimeException('Sin permiso: utiliza la operación de cierre');
            $value = $method === 'DELETE' ? [] : ($data['value'] ?? $data);
            api_json_response(grooflow_write_resource($pdo, $key, $value, $data['revision'] ?? null));
            return;
        }
    }

    if (preg_match('#^/collections/([A-Za-z0-9_-]+)(?:/([^/]+))?$#', $path, $m)) {
        $name = $m[1]; $id = isset($m[2]) ? rawurldecode($m[2]) : null;
        $key = grooflow_collection_kv_key($name);
        $entry = grooflow_read_resource($pdo, $key);
        $items = is_array($entry['value']) ? $entry['value'] : [];
        if (!array_is_list($items)) throw new InvalidArgumentException('Utiliza KV para datasets de objetos');
        if ($method === 'GET') {
            if ($id === null) api_json_response(['ok' => true, 'items' => $items, 'revision' => $entry['revision']]);
            else {
                $found = array_values(array_filter($items, fn ($r) => (string) ($r['id'] ?? '') === $id));
                api_json_response($found ? ['ok' => true, 'item' => $found[0], 'revision' => $entry['revision']] : ['ok' => false, 'error' => 'No encontrado'], $found ? 200 : 404);
            }
            return;
        }
        grooflow_assert_resource($pdo, $key, true);
        $data = api_request_json();
        $revision = $_SERVER['HTTP_IF_MATCH'] ?? $data['revision'] ?? null;
        unset($data['revision']);
        if ($name === 'users' || $name === 'roles') {
            grooflow_assert_admin($pdo);
            $result = grooflow_atomic($pdo, function () use ($pdo, $name, $method, $id, $data) {
                if ($method === 'DELETE') { grooflow_collection_delete($pdo, $name, (string) $id); return null; }
                if ($id === 'upsert') { grooflow_collection_upsert_many($pdo, $name, $data['records'] ?? $data['items'] ?? $data); return null; }
                return $id === null ? grooflow_collection_create($pdo, $name, $data) : grooflow_collection_update($pdo, $name, $id, $data);
            });
            api_json_response(['ok' => true, 'item' => $result]); return;
        }
        $map = [];
        foreach ($items as $item) $map[(string) $item['id']] = $item;
        if ($id === 'upsert') {
            foreach (($data['records'] ?? $data['items'] ?? $data) as $record) {
                if (!is_array($record) || empty($record['id'])) throw new InvalidArgumentException('Registro sin id');
                $map[(string) $record['id']] = $record;
            }
        } elseif ($method === 'DELETE') {
            if (!isset($map[$id])) throw new RuntimeException('Registro no encontrado');
            unset($map[$id]);
        } elseif ($id !== null) {
            if (!isset($map[$id])) throw new RuntimeException('Registro no encontrado');
            $map[$id] = array_merge($map[$id], $data, ['id' => $id]);
        } else {
            $id = (string) ($data['id'] ?? bin2hex(random_bytes(8)));
            if (isset($map[$id])) throw new GrooflowConflict('Identificador ya existente');
            $map[$id] = array_merge($data, ['id' => $id]);
        }
        $result = grooflow_write_resource($pdo, $key, array_values($map), $revision);
        api_json_response([...$result, 'item' => $map[$id] ?? null]); return;
    }

    // --- RRHH / catálogos ---
    if ($path === '/rrhh/stats' && $method === 'GET') {
        grooflow_rrhh_ensure_schema($pdo);
        api_json_response(['ok' => true, 'stats' => grooflow_rrhh_stats($pdo)]);

        return;
    }

    if ($path === '/rrhh/identity-diagnosis' && $method === 'GET') {
        grooflow_rrhh_ensure_schema($pdo);
        $limit = (int) ($_GET['limit'] ?? 40);
        api_json_response(['ok' => true, ...grooflow_rrhh_identity_diagnosis($pdo, $limit)]);

        return;
    }

    if ($path === '/rrhh/pipeline-health' && $method === 'GET') {
        $health = grooflow_pipelines_health($pdo);
        api_json_response([
            'ok' => true,
            'pipelineOk' => ! empty($health['ok']),
            'summary' => $health['summary'] ?? '',
            'issues' => $health['issues'] ?? [],
            'rrhh' => $health['rrhh'] ?? null,
            'marcaciones' => $health['marcaciones'] ?? null,
            'generatedAt' => $health['generatedAt'] ?? date('c'),
        ]);

        return;
    }

    if ($path === '/rrhh/apply-terminations' && $method === 'POST') {
        grooflow_assert_rrhh_editor($pdo);
        $data = api_request_json();
        $result = grooflow_rrhh_apply_terminations($pdo, [
            'dryRun' => ! empty($data['dryRun']),
            'bukIds' => is_array($data['bukIds'] ?? null) ? $data['bukIds'] : null,
        ]);
        api_json_response(['ok' => true, ...$result]);

        return;
    }

    if ($path === '/rrhh/link-user' && $method === 'POST') {
        grooflow_assert_rrhh_editor($pdo);
        $data = api_request_json();
        $bukId = (int) ($data['bukId'] ?? $data['bukEmployeeId'] ?? 0);
        $userId = trim((string) ($data['userId'] ?? ''));
        $methodLink = trim((string) ($data['matchMethod'] ?? $data['method'] ?? 'manual'));
        $result = grooflow_rrhh_link_user($pdo, $bukId, $userId, $methodLink !== '' ? $methodLink : 'manual');
        api_json_response(['ok' => true, ...$result]);

        return;
    }

    if ($path === '/rrhh/project-asistencia-staff' && $method === 'POST') {
        grooflow_assert_rrhh_editor($pdo);
        $data = api_request_json();
        $onlySedes = is_array($data['onlySedes'] ?? null) ? $data['onlySedes'] : null;
        $result = grooflow_rrhh_project_asistencia_staff($pdo, [
            'pruneInactive' => ($data['pruneInactive'] ?? true) !== false,
            'onlySedes' => $onlySedes,
        ]);
        api_json_response(['ok' => true, ...$result]);

        return;
    }

    if ($path === '/rrhh/empleados' && $method === 'GET') {
        api_json_response(['ok' => true, ...grooflow_rrhh_list_employees($pdo, $_GET)]);

        return;
    }

    if ($path === '/rrhh/empleados/export' && $method === 'GET') {
        grooflow_rrhh_export_excel($pdo, $_GET);

        return;
    }

    if ($path === '/rrhh/sync' && $method === 'POST') {
        grooflow_assert_rrhh_editor($pdo);
        $result = grooflow_rrhh_sync_from_apis($pdo, api_request_json());
        api_json_response(['ok' => true, ...$result]);

        return;
    }

    if ($path === '/rrhh/links' && $method === 'POST') {
        grooflow_assert_rrhh_editor($pdo);
        $data = api_request_json();
        $links = is_array($data['userLinks'] ?? $data['links'] ?? null) ? ($data['userLinks'] ?? $data['links']) : [];
        $applied = grooflow_rrhh_apply_user_links($pdo, $links);
        $meta = grooflow_kv_get($pdo, 'settings:rrhh');
        $meta = is_array($meta) ? $meta : [];
        $meta['userLinks'] = $links;
        unset($meta['employees']);
        grooflow_kv_set($pdo, 'settings:rrhh', $meta);
        api_json_response(['ok' => true, 'linked' => $applied]);

        return;
    }

    if (preg_match('#^/lists/([A-Za-z0-9_-]+)(/delete)?$#', $path, $m)) {
        $name = $m[1];
        $key = $name === 'inventory-equipment' ? 'data:inventory' : ($name === 'chart-of-accounts' ? 'data:chartOfAccounts' : grooflow_collection_kv_key($name));
        $entry = grooflow_read_resource($pdo, $key);
        $items = $name === 'inventory-equipment' ? ($entry['value']['equipment'] ?? []) : ($entry['value'] ?? []);
        $query = $method === 'GET' ? $_GET : api_request_json();
        $filtered = grooflow_filter_list($items, $query);
        if ($method === 'GET') {
            $page = grooflow_lists_page_array($filtered, max(1, (int) ($query['page'] ?? 1)), max(5, min(100, (int) ($query['pageSize'] ?? 25))), trim((string) ($query['search'] ?? '')), [], !empty($query['idsOnly']));
            $page['total'] = count($items);
            api_json_response(['ok' => true, ...$page, 'revision' => $entry['revision']]); return;
        }
        if ($method === 'POST' && isset($m[2])) {
            $ids = !empty($query['allMatching']) ? array_column(grooflow_lists_page_array($filtered, 1, max(1, count($filtered)), trim((string) ($query['search'] ?? '')), [], false)['items'], 'id') : ($query['ids'] ?? []);
            $visibleIds = array_column($items, 'id');
            foreach ($ids as $id) if (!in_array($id, $visibleIds, true)) throw new RuntimeException('Sin permiso para eliminar ese registro');
            $remaining = array_values(array_filter($items, fn ($r) => !in_array($r['id'], $ids, true)));
            $value = $name === 'inventory-equipment' ? array_merge($entry['value'], ['equipment' => $remaining]) : $remaining;
            $result = grooflow_write_resource($pdo, $key, $value, $query['revision'] ?? null);
            api_json_response([...$result, 'deleted' => count($items) - count($remaining)]); return;
        }
    }

    if ($path === '/catalog/areas' && $method === 'GET') {
        if (! function_exists('areas_admin_list_active')) {
            require_once (defined('CRON_ROOT') ? CRON_ROOT : dirname(__DIR__, 2)) . '/backend/lib/areas_admin_api.php';
        }
        areas_admin_ensure_table($pdo);
        $onlyActive = ! isset($_GET['all']);
        if ($onlyActive) {
            api_json_response(['ok' => true, 'items' => areas_admin_list_active($pdo)]);
        } else {
            $items = $pdo->query("SELECT * FROM app_areas_admin WHERE is_deleted = 0 ORDER BY sort_order, nombre")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            api_json_response(['ok' => true, 'items' => $items]);
        }

        return;
    }

    if ($path === '/catalog/areas' && $method === 'POST') {

        if (! function_exists('areas_admin_create')) {
            require_once (defined('CRON_ROOT') ? CRON_ROOT : dirname(__DIR__, 2)) . '/backend/lib/areas_admin_api.php';
        }
        api_json_response(['ok' => true, 'item' => areas_admin_create($pdo, api_request_json())]);

        return;
    }

    if (preg_match('#^/catalog/areas/(\d+)$#', $path, $m) && ($method === 'PUT' || $method === 'PATCH')) {

        if (! function_exists('areas_admin_update')) {
            require_once (defined('CRON_ROOT') ? CRON_ROOT : dirname(__DIR__, 2)) . '/backend/lib/areas_admin_api.php';
        }
        api_json_response(['ok' => true, 'item' => areas_admin_update($pdo, (int) $m[1], api_request_json())]);

        return;
    }

    if (preg_match('#^/catalog/areas/(\d+)$#', $path, $m) && $method === 'DELETE') {

        if (! function_exists('areas_admin_delete')) {
            require_once (defined('CRON_ROOT') ? CRON_ROOT : dirname(__DIR__, 2)) . '/backend/lib/areas_admin_api.php';
        }
        areas_admin_delete($pdo, (int) $m[1]);
        api_json_response(['ok' => true]);

        return;
    }

    if ($path === '/catalog/puestos' && $method === 'GET') {
        api_json_response(['ok' => true, 'items' => grooflow_puestos_list($pdo, ! isset($_GET['all']))]);

        return;
    }

    if ($path === '/catalog/puestos' && $method === 'POST') {

        api_json_response(['ok' => true, 'item' => grooflow_puestos_save($pdo, api_request_json())]);

        return;
    }

    if (preg_match('#^/catalog/puestos/(\d+)$#', $path, $m) && ($method === 'PUT' || $method === 'PATCH')) {

        api_json_response(['ok' => true, 'item' => grooflow_puestos_save($pdo, api_request_json(), (int) $m[1])]);

        return;
    }

    if (preg_match('#^/catalog/puestos/(\d+)$#', $path, $m) && $method === 'DELETE') {

        grooflow_puestos_delete($pdo, (int) $m[1]);
        api_json_response(['ok' => true]);

        return;
    }

    if ($path === '/catalog/turnos' && $method === 'GET') {
        api_json_response(['ok' => true, 'items' => grooflow_turnos_catalog_list($pdo, ! isset($_GET['all']))]);

        return;
    }

    if ($path === '/catalog/turnos' && $method === 'POST') {

        api_json_response(['ok' => true, 'item' => grooflow_turnos_catalog_save($pdo, api_request_json())]);

        return;
    }

    if (preg_match('#^/catalog/turnos/(\d+)$#', $path, $m) && ($method === 'PUT' || $method === 'PATCH')) {

        api_json_response(['ok' => true, 'item' => grooflow_turnos_catalog_save($pdo, api_request_json(), (int) $m[1])]);

        return;
    }

    if (preg_match('#^/catalog/turnos/(\d+)$#', $path, $m) && $method === 'DELETE') {

        grooflow_turnos_catalog_delete($pdo, (int) $m[1]);
        api_json_response(['ok' => true]);

        return;
    }

    api_json_response(['ok' => false, 'error' => 'Ruta no encontrada'], 404);
}
