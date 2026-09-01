<?php

declare(strict_types=1);

require_once __DIR__ . '/grooflow_asistencia.php';

function grooflow_proxy_fetch(string $url, array $headers, int $timeoutSec = 45): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('No se pudo iniciar cURL');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $errno) {
        throw new RuntimeException($error !== '' ? $error : 'Error de red al contactar el servicio externo');
    }

    return ['status' => $status, 'body' => (string) $body];
}

function grooflow_ip_is_public(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function grooflow_host_resolves_public(string $host): bool
{
    if ($host === '' || strcasecmp($host, 'localhost') === 0) {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return grooflow_ip_is_public($host);
    }
    $ips = gethostbynamel($host);
    if (! is_array($ips) || $ips === []) {
        return false;
    }
    foreach ($ips as $ip) {
        if (! grooflow_ip_is_public($ip)) {
            return false;
        }
    }

    return true;
}

/** @return array<string, mixed> */
function grooflow_assert_https_public_url(string $url): array
{
    $parts = parse_url($url);
    if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
        throw new InvalidArgumentException('URL de destino no permitida.');
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '' || ! preg_match('/^[a-z0-9.-]+$/', $host)) {
        throw new InvalidArgumentException('URL de destino no permitida.');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        throw new InvalidArgumentException('URL de destino no permitida.');
    }
    if (! grooflow_host_resolves_public($host)) {
        throw new InvalidArgumentException('URL de destino no permitida.');
    }

    return $parts;
}

function grooflow_assert_veterinari_url(string $url): void
{
    $parts = grooflow_assert_https_public_url($url);
    $host = strtolower((string) $parts['host']);
    if (! str_contains($host, 'veterinari') && ! str_ends_with($host, 'azurewebsites.net')) {
        throw new InvalidArgumentException('URL de destino no permitida.');
    }
}

function grooflow_assert_buk_url(string $url): void
{
    $parts = grooflow_assert_https_public_url($url);
    $host = strtolower((string) $parts['host']);
    if ($host !== 'app.ctrlit.cl' && ! str_ends_with($host, '.ctrlit.cl')) {
        throw new InvalidArgumentException('URL de destino no permitida.');
    }
}

function grooflow_count_hint(mixed $json): ?string
{
    if (is_array($json)) {
        if (array_keys($json) === range(0, count($json) - 1)) {
            return count($json) . ' registro(s) en esta página';
        }
        if (isset($json['data']) && is_array($json['data'])) {
            return count($json['data']) . ' registro(s)';
        }
        if (isset($json['pagination']['count'])) {
            return (string) $json['pagination']['count'] . ' registro(s)';
        }
    }

    return null;
}

function grooflow_normalize_buk_token(string $apiToken): string
{
    $apiToken = trim($apiToken);
    if (str_starts_with(strtolower($apiToken), 'bearer ')) {
        $apiToken = trim(substr($apiToken, 7));
    }

    return $apiToken;
}

function grooflow_normalize_buk_pe_token(string $apiToken): string
{
    $apiToken = grooflow_normalize_buk_token($apiToken);
    $apiToken = preg_replace('/^auth_token\s*:\s*/i', '', $apiToken) ?? $apiToken;
    if (
        (str_starts_with($apiToken, '"') && str_ends_with($apiToken, '"')) ||
        (str_starts_with($apiToken, "'") && str_ends_with($apiToken, "'"))
    ) {
        $apiToken = trim(substr($apiToken, 1, -1));
    }

    return trim($apiToken);
}

function grooflow_buk_pe_failure_message(int $status, string $targetUrl, string $body): string
{
    $trimmed = trim($body);
    if ($status === 401) {
        if ($trimmed === '"no_authorize"' || str_contains($trimmed, 'no_authorize')) {
            return 'HTTP 401 — Buk.pe rechazó el auth_token ("no_authorize"). Genera un token nuevo en Buk.pe → Configuración → Accesos API y pégalo aquí (no uses IDs de usuario ni claves de otro módulo). URL: ' . $targetUrl;
        }

        return 'HTTP 401 — auth_token inválido. Verifica en Buk.pe → Configuración → Accesos API que el token sea el de API (no un UUID de otro servicio). URL: ' . $targetUrl;
    }
    if ($status === 403) {
        return 'HTTP 403 — sin permiso para este recurso en Buk.pe. URL: ' . $targetUrl;
    }
    $snippet = substr(preg_replace('/\s+/', ' ', $body) ?? '', 0, 200);

    return 'HTTP ' . $status . ' — URL: ' . $targetUrl . ($snippet !== '' ? (' — ' . $snippet) : '');
}

function grooflow_buk_token_is_redacted(string $apiToken): bool
{
    $t = trim($apiToken);
    if ($t === '') {
        return true;
    }
    if ($t === '********') {
        return true;
    }

    return preg_match('/^\*+$/', $t) === 1;
}

/** Usa token almacenado en servidor si el cliente envía valor redactado. */
function grooflow_resolve_buk_api_token(PDO $pdo, string $apiToken): string
{
    $apiToken = grooflow_normalize_buk_token($apiToken);
    if (! grooflow_buk_token_is_redacted($apiToken)) {
        return $apiToken;
    }
    $settings = grooflow_asistencia_get_settings($pdo);
    if (! is_array($settings)) {
        throw new InvalidArgumentException('Configura Buk Asistencia en Integraciones antes de actualizar.');
    }
    $stored = grooflow_normalize_buk_token((string) ($settings['buk']['apiToken'] ?? ''));
    if ($stored === '' || grooflow_buk_token_is_redacted($stored)) {
        throw new InvalidArgumentException('Token Buk no disponible. Un administrador debe probar la conexión en Integraciones.');
    }

    return $stored;
}

function grooflow_sanitize_buk_base_url(string $raw): string
{
    $s = trim($raw);
    if ($s === '') {
        return 'https://app.ctrlit.cl/ctrl/api/v2';
    }
    $s = preg_split('/[#?]/', $s)[0] ?? $s;
    $s = rtrim($s, '/');
    $s = preg_replace('#/asistencia-empresa/?$#i', '', $s);
    $s = rtrim($s, '/');
    if (! str_starts_with(strtolower($s), 'http')) {
        $s = 'https://' . $s;
    }
    $parts = parse_url($s);
    if (! is_array($parts)) {
        return 'https://app.ctrlit.cl/ctrl/api/v2';
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = rtrim((string) ($parts['path'] ?? ''), '/');
    if ($host === 'app.ctrlit.cl' || str_ends_with($host, '.ctrlit.cl')) {
        if ($path === '' || $path === '/ctrl' || $path === '/ctrl/api' || ! str_contains($path, '/api/v2')) {
            return 'https://app.ctrlit.cl/ctrl/api/v2';
        }
    }

    return rtrim(((string) ($parts['scheme'] ?? 'https')) . '://' . $host . $path, '/');
}

function grooflow_build_buk_asistencia_url(string $baseUrl, int $page, int $pageSize): string
{
    $base = grooflow_sanitize_buk_base_url($baseUrl);
    $url = $base . '/asistencia-empresa?page=' . max(1, $page) . '&page_size=' . max(1, min(200, $pageSize));

    return $url;
}

/** @return array{records: list<array<string, mixed>>, totalPages: int, count: int} */
function grooflow_parse_buk_asistencia_page(mixed $json): array
{
    if (! is_array($json)) {
        return ['records' => [], 'totalPages' => 1, 'count' => 0];
    }
    $records = [];
    if (isset($json['data']) && is_array($json['data'])) {
        $records = $json['data'];
    }
    $pagination = is_array($json['pagination'] ?? null) ? $json['pagination'] : [];
    $totalPages = max(1, (int) ($pagination['totalPages'] ?? 1));
    $count = (int) ($pagination['count'] ?? count($records));

    return ['records' => $records, 'totalPages' => $totalPages, 'count' => $count];
}

/** @return array{status: int, records: list<array<string, mixed>>, totalPages: int, count: int, triedUrl: string} */
function grooflow_buk_fetch_page(
    string $baseUrl,
    string $apiToken,
    int $page,
    int $pageSize,
    int $timeoutSec = 45
): array {
    $url = grooflow_build_buk_asistencia_url($baseUrl, $page, $pageSize);
    grooflow_assert_buk_url($url);
    $res = grooflow_proxy_fetch($url, [
        'token: ' . $apiToken,
        'Accept: application/json',
    ], $timeoutSec);
    $json = json_decode($res['body'], true);
    $parsed = grooflow_parse_buk_asistencia_page($json);

    return [
        'status' => $res['status'],
        'records' => $parsed['records'],
        'totalPages' => $parsed['totalPages'],
        'count' => $parsed['count'],
        'triedUrl' => $url,
    ];
}

function grooflow_handle_veterinari_test(array $data): array
{
    $targetUrl = trim((string) ($data['targetUrl'] ?? ''));
    $apiToken = trim((string) ($data['apiToken'] ?? ''));
    if (str_starts_with(strtolower($apiToken), 'bearer ')) {
        $apiToken = trim(substr($apiToken, 7));
    }
    if ($targetUrl === '' || $apiToken === '') {
        throw new InvalidArgumentException('Faltan targetUrl o apiToken.');
    }
    grooflow_assert_veterinari_url($targetUrl);
    $started = (int) round(microtime(true) * 1000);
    $res = grooflow_proxy_fetch($targetUrl, [
        'Authorization: Bearer ' . $apiToken,
        'Accept: application/json',
    ], 45);
    $json = json_decode($res['body'], true);
    $hint = grooflow_count_hint($json);
    $ok = $res['status'] >= 200 && $res['status'] < 300;
    $snippet = substr(preg_replace('/\s+/', ' ', $res['body']) ?? '', 0, 200);

    return [
        'ok' => $ok,
        'status' => $res['status'],
        'authMethod' => 'Authorization: Bearer',
        'message' => $ok
            ? ($hint ? ('Conexión exitosa (servidor GrooFlow → Veterinari). ' . $hint . '.') : ('Conexión exitosa. HTTP ' . $res['status']))
            : ('HTTP ' . $res['status'] . ': ' . ($snippet !== '' ? $snippet : 'sin detalle')),
        'recordHint' => $hint,
        'durationMs' => (int) round(microtime(true) * 1000) - $started,
        'attempts' => 1,
    ];
}

function grooflow_build_buk_target_url(string $baseUrl, string $targetUrl, string $pathOrUrl): string
{
    $targetUrl = trim($targetUrl);
    if ($targetUrl !== '') {
        if (! str_starts_with(strtolower($targetUrl), 'http')) {
            $base = grooflow_sanitize_buk_base_url($baseUrl);

            return rtrim($base, '/') . '/' . ltrim($targetUrl, '/');
        }

        return $targetUrl;
    }
    $pathOrUrl = trim($pathOrUrl);
    if ($pathOrUrl === '') {
        throw new InvalidArgumentException('Indica targetUrl o path.');
    }
    if (str_starts_with(strtolower($pathOrUrl), 'http')) {
        return $pathOrUrl;
    }
    $base = grooflow_sanitize_buk_base_url($baseUrl);

    return rtrim($base, '/') . '/' . ltrim($pathOrUrl, '/');
}

/** @return list<array<string, mixed>> */
function grooflow_buk_extract_records(mixed $json): array
{
    if (! is_array($json)) {
        return [];
    }
    if (isset($json['data']) && is_array($json['data'])) {
        $data = $json['data'];
        if ($data === [] || array_keys($data) === range(0, count($data) - 1)) {
            return $data;
        }
    }
    if (array_keys($json) === range(0, count($json) - 1)) {
        return $json;
    }

    return [];
}

function grooflow_handle_buk(PDO $pdo, string $action, array $data): array
{
    $baseUrl = grooflow_sanitize_buk_base_url((string) ($data['baseUrl'] ?? $data['url'] ?? ''));
    $apiToken = grooflow_resolve_buk_api_token($pdo, (string) ($data['apiToken'] ?? $data['token'] ?? ''));
    $page = max(1, (int) ($data['page'] ?? 1));
    $pageSize = max(1, min(200, (int) ($data['pageSize'] ?? $data['perPage'] ?? 100)));
    $maxPages = max(1, min(50, (int) ($data['maxPages'] ?? 15)));
    $started = (int) round(microtime(true) * 1000);

    if ($action === 'test') {
        $targetUrl = trim((string) ($data['targetUrl'] ?? ''));
        $testUrl = $targetUrl !== '' ? $targetUrl : grooflow_build_buk_asistencia_url($baseUrl, 1, 5);
        grooflow_assert_buk_url($testUrl);
        $res = grooflow_proxy_fetch($testUrl, [
            'token: ' . $apiToken,
            'Accept: application/json',
        ], 45);
        $json = json_decode($res['body'], true);
        $parsed = grooflow_parse_buk_asistencia_page($json);
        $count = $parsed['count'] > 0 ? $parsed['count'] : count($parsed['records']);
        $duration = (int) round(microtime(true) * 1000) - $started;

        return [
            'ok' => $res['status'] >= 200 && $res['status'] < 300,
            'status' => $res['status'],
            'message' => $res['status'] >= 200 && $res['status'] < 300
                ? ('Conexión OK. ' . $count . ' registro(s) en asistencia.')
                : ('HTTP ' . $res['status'] . ' — URL: ' . $testUrl),
            'recordHint' => $count ? ($count . ' registros') : null,
            'triedUrl' => $testUrl,
            'durationMs' => $duration,
        ];
    }

    if ($action === 'probe') {
        $targetUrl = grooflow_build_buk_target_url(
            $baseUrl,
            (string) ($data['targetUrl'] ?? ''),
            (string) ($data['path'] ?? $data['pathOrUrl'] ?? '')
        );
        grooflow_assert_buk_url($targetUrl);
        $res = grooflow_proxy_fetch($targetUrl, [
            'token: ' . $apiToken,
            'Accept: application/json',
        ], 60);
        $json = json_decode($res['body'], true);
        $records = grooflow_buk_extract_records($json);
        $parsed = grooflow_parse_buk_asistencia_page($json);
        $count = $parsed['count'] > 0 ? $parsed['count'] : count($records);
        $duration = (int) round(microtime(true) * 1000) - $started;
        $ok = $res['status'] >= 200 && $res['status'] < 300;
        $snippet = substr(preg_replace('/\s+/', ' ', $res['body']) ?? '', 0, 300);

        return [
            'ok' => $ok,
            'status' => $res['status'],
            'message' => $ok
                ? ($count > 0 ? ('OK. ' . $count . ' registro(s) detectados.') : 'OK. Respuesta sin arreglo de registros (revisa campos en el explorador).')
                : ('HTTP ' . $res['status'] . ': ' . ($snippet !== '' ? $snippet : 'sin detalle')),
            'data' => $records,
            'sample' => array_slice($records, 0, 3),
            'recordCount' => $count,
            'pagination' => is_array($json) && is_array($json['pagination'] ?? null) ? $json['pagination'] : null,
            'rawPreview' => is_array($json) ? array_slice($json, 0, 20, true) : null,
            'triedUrl' => $targetUrl,
            'durationMs' => $duration,
        ];
    }

    if ($action === 'fetch') {
        $pageRes = grooflow_buk_fetch_page($baseUrl, $apiToken, $page, $pageSize, 45);
        $duration = (int) round(microtime(true) * 1000) - $started;
        if ($pageRes['status'] < 200 || $pageRes['status'] >= 300) {
            return [
                'ok' => false,
                'status' => $pageRes['status'],
                'message' => 'HTTP ' . $pageRes['status'] . ' — URL: ' . $pageRes['triedUrl'],
                'data' => [],
                'totalPages' => 1,
                'triedUrl' => $pageRes['triedUrl'],
                'durationMs' => $duration,
            ];
        }

        return [
            'ok' => true,
            'status' => $pageRes['status'],
            'data' => $pageRes['records'],
            'totalPages' => $pageRes['totalPages'],
            'triedUrl' => $pageRes['triedUrl'],
            'durationMs' => $duration,
        ];
    }

    // fetch-all: paginar hasta maxPages
    $all = [];
    $first = grooflow_buk_fetch_page($baseUrl, $apiToken, 1, $pageSize, 120);
    if ($first['status'] < 200 || $first['status'] >= 300) {
        $duration = (int) round(microtime(true) * 1000) - $started;

        return [
            'ok' => false,
            'status' => $first['status'],
            'message' => 'HTTP ' . $first['status'] . ' — URL: ' . $first['triedUrl'],
            'data' => [],
            'triedUrl' => $first['triedUrl'],
            'durationMs' => $duration,
        ];
    }
    $all = array_merge($all, $first['records']);
    $totalPages = min($first['totalPages'], $maxPages);
    for ($p = 2; $p <= $totalPages; $p++) {
        $next = grooflow_buk_fetch_page($baseUrl, $apiToken, $p, $pageSize, 120);
        if ($next['status'] < 200 || $next['status'] >= 300) {
            break;
        }
        $all = array_merge($all, $next['records']);
    }
    $duration = (int) round(microtime(true) * 1000) - $started;

    return [
        'ok' => true,
        'status' => $first['status'],
        'data' => $all,
        'totalPages' => $totalPages,
        'triedUrl' => $first['triedUrl'],
        'durationMs' => $duration,
    ];
}

function grooflow_assert_buk_pe_url(string $url): void
{
    $parts = grooflow_assert_https_public_url($url);
    $host = strtolower((string) $parts['host']);
    $allowed =
        str_ends_with($host, '.buk.pe') ||
        str_ends_with($host, '.buk.cl') ||
        str_ends_with($host, '.buk.co') ||
        str_ends_with($host, '.buk.com.br');
    if (! $allowed) {
        throw new InvalidArgumentException('URL de destino no permitida para Buk.pe.');
    }
}

function grooflow_sanitize_buk_pe_base_url(string $raw): string
{
    $s = trim($raw);
    if ($s === '') {
        return 'https://veterinariagroomers.buk.pe/api/v1/peru';
    }
    $s = preg_split('/[#?]/', $s)[0] ?? $s;
    $s = rtrim($s, '/');
    $s = preg_replace('#/employees(/.*)?$#i', '', $s);
    $s = rtrim($s, '/');
    if (! str_starts_with(strtolower($s), 'http')) {
        $s = 'https://' . $s;
    }
    $parts = parse_url($s);
    if (! is_array($parts)) {
        return 'https://veterinariagroomers.buk.pe/api/v1/peru';
    }
    $path = rtrim((string) ($parts['path'] ?? ''), '/');
    if (! str_contains($path, '/api/v1/')) {
        return 'https://veterinariagroomers.buk.pe/api/v1/peru';
    }

    return rtrim(((string) ($parts['scheme'] ?? 'https')) . '://' . ((string) ($parts['host'] ?? '')) . $path, '/');
}

function grooflow_build_buk_pe_target_url(string $baseUrl, string $targetUrl, string $pathOrUrl): string
{
    $targetUrl = trim($targetUrl);
    if ($targetUrl !== '') {
        if (! str_starts_with(strtolower($targetUrl), 'http')) {
            $base = grooflow_sanitize_buk_pe_base_url($baseUrl);

            return rtrim($base, '/') . '/' . ltrim($targetUrl, '/');
        }

        return $targetUrl;
    }
    $pathOrUrl = trim($pathOrUrl);
    if ($pathOrUrl === '') {
        throw new InvalidArgumentException('Indica targetUrl o path.');
    }
    if (str_starts_with(strtolower($pathOrUrl), 'http')) {
        return $pathOrUrl;
    }
    $base = grooflow_sanitize_buk_pe_base_url($baseUrl);

    return rtrim($base, '/') . '/' . ltrim($pathOrUrl, '/');
}

function grooflow_resolve_buk_pe_api_token(PDO $pdo, string $apiToken): string
{
    $apiToken = grooflow_normalize_buk_pe_token($apiToken);
    if (! grooflow_buk_token_is_redacted($apiToken)) {
        return $apiToken;
    }
    $raw = grooflow_kv_get($pdo, 'settings:system');
    if (! is_array($raw)) {
        throw new InvalidArgumentException('Configura Buk.pe en Integraciones antes de consultar.');
    }
    $bukPe = is_array($raw['bukPe'] ?? null) ? $raw['bukPe'] : [];
    $stored = grooflow_normalize_buk_pe_token((string) ($bukPe['apiToken'] ?? ''));
    if ($stored === '' || grooflow_buk_token_is_redacted($stored)) {
        throw new InvalidArgumentException('Token Buk.pe no disponible. Un administrador debe guardarlo en Integraciones.');
    }

    return $stored;
}

/** @return list<string> */
function grooflow_buk_pe_auth_headers(string $apiToken): array
{
    return [
        'auth_token: ' . $apiToken,
        'Accept: application/json',
    ];
}

/** @return array{records: list<array<string, mixed>>, totalPages: int, count: int} */
function grooflow_parse_buk_pe_page(mixed $json): array
{
    if (! is_array($json)) {
        return ['records' => [], 'totalPages' => 1, 'count' => 0];
    }
    $records = grooflow_buk_extract_records($json);
    $pagination = is_array($json['pagination'] ?? null) ? $json['pagination'] : [];
    $totalPages = max(1, (int) ($pagination['total_pages'] ?? $pagination['totalPages'] ?? 1));
    $count = (int) ($pagination['count'] ?? count($records));

    return ['records' => $records, 'totalPages' => $totalPages, 'count' => $count];
}

/** @return array{status: int, records: list<array<string, mixed>>, totalPages: int, count: int, triedUrl: string} */
function grooflow_buk_pe_fetch_page(
    string $baseUrl,
    string $apiToken,
    int $page,
    int $pageSize,
    int $timeoutSec = 60
): array {
    $url = grooflow_build_buk_pe_target_url($baseUrl, '', 'employees?page=' . max(1, $page) . '&page_size=' . max(1, min(200, $pageSize)));
    grooflow_assert_buk_pe_url($url);
    $res = grooflow_proxy_fetch($url, grooflow_buk_pe_auth_headers($apiToken), $timeoutSec);
    $json = json_decode($res['body'], true);
    $parsed = grooflow_parse_buk_pe_page($json);

    return [
        'status' => $res['status'],
        'records' => $parsed['records'],
        'totalPages' => $parsed['totalPages'],
        'count' => $parsed['count'],
        'triedUrl' => $url,
    ];
}

/** @param array<string, mixed> $data */
function grooflow_handle_buk_pe(PDO $pdo, string $action, array $data): array
{
    $baseUrl = grooflow_sanitize_buk_pe_base_url((string) ($data['baseUrl'] ?? $data['url'] ?? ''));
    $apiToken = grooflow_resolve_buk_pe_api_token($pdo, (string) ($data['apiToken'] ?? $data['token'] ?? ''));
    $page = max(1, (int) ($data['page'] ?? 1));
    $pageSize = max(1, min(200, (int) ($data['pageSize'] ?? $data['perPage'] ?? 100)));
    $maxPages = max(1, min(50, (int) ($data['maxPages'] ?? 30)));
    $started = (int) round(microtime(true) * 1000);

    if ($action === 'test') {
        $targetUrl = trim((string) ($data['targetUrl'] ?? ''));
        $testUrl = $targetUrl !== '' ? $targetUrl : grooflow_build_buk_pe_target_url($baseUrl, '', 'employees?page=1&page_size=5');
        grooflow_assert_buk_pe_url($testUrl);
        $res = grooflow_proxy_fetch($testUrl, grooflow_buk_pe_auth_headers($apiToken), 45);
        $json = json_decode($res['body'], true);
        $records = grooflow_buk_extract_records($json);
        $count = count($records);
        if (is_array($json) && isset($json['pagination']['total'])) {
            $count = max($count, (int) $json['pagination']['total']);
        }
        $duration = (int) round(microtime(true) * 1000) - $started;

        return [
            'ok' => $res['status'] >= 200 && $res['status'] < 300,
            'status' => $res['status'],
            'message' => $res['status'] >= 200 && $res['status'] < 300
                ? ('Conexión OK. ' . $count . ' empleado(s) detectados.')
                : grooflow_buk_pe_failure_message($res['status'], $testUrl, $res['body']),
            'recordHint' => $count ? ($count . ' registros') : null,
            'triedUrl' => $testUrl,
            'durationMs' => $duration,
        ];
    }

    if ($action === 'probe') {
        $targetUrl = grooflow_build_buk_pe_target_url(
            $baseUrl,
            (string) ($data['targetUrl'] ?? ''),
            (string) ($data['path'] ?? $data['pathOrUrl'] ?? '')
        );
        grooflow_assert_buk_pe_url($targetUrl);
        $res = grooflow_proxy_fetch($targetUrl, grooflow_buk_pe_auth_headers($apiToken), 60);
        $json = json_decode($res['body'], true);
        $records = grooflow_buk_extract_records($json);
        $count = count($records);
        if (is_array($json) && isset($json['pagination']['total'])) {
            $count = max($count, (int) $json['pagination']['total']);
        }
        $duration = (int) round(microtime(true) * 1000) - $started;
        $ok = $res['status'] >= 200 && $res['status'] < 300;

        return [
            'ok' => $ok,
            'status' => $res['status'],
            'message' => $ok
                ? ($count > 0 ? ('OK. ' . $count . ' registro(s) detectados.') : 'OK. Respuesta sin arreglo de registros (revisa campos en el explorador).')
                : grooflow_buk_pe_failure_message($res['status'], $targetUrl, $res['body']),
            'data' => $records,
            'sample' => array_slice($records, 0, 3),
            'recordCount' => $count,
            'pagination' => is_array($json) && is_array($json['pagination'] ?? null) ? $json['pagination'] : null,
            'rawPreview' => is_array($json) ? array_slice($json, 0, 20, true) : null,
            'triedUrl' => $targetUrl,
            'durationMs' => $duration,
        ];
    }

    if ($action === 'fetch') {
        $pageRes = grooflow_buk_pe_fetch_page($baseUrl, $apiToken, $page, $pageSize, 60);
        $duration = (int) round(microtime(true) * 1000) - $started;
        if ($pageRes['status'] < 200 || $pageRes['status'] >= 300) {
            return [
                'ok' => false,
                'status' => $pageRes['status'],
                'message' => grooflow_buk_pe_failure_message($pageRes['status'], $pageRes['triedUrl'], ''),
                'data' => [],
                'totalPages' => 1,
                'triedUrl' => $pageRes['triedUrl'],
                'durationMs' => $duration,
            ];
        }

        return [
            'ok' => true,
            'status' => $pageRes['status'],
            'data' => $pageRes['records'],
            'totalPages' => $pageRes['totalPages'],
            'triedUrl' => $pageRes['triedUrl'],
            'durationMs' => $duration,
        ];
    }

    if ($action === 'fetch-all') {
        $all = [];
        $first = grooflow_buk_pe_fetch_page($baseUrl, $apiToken, 1, $pageSize, 120);
        if ($first['status'] < 200 || $first['status'] >= 300) {
            $duration = (int) round(microtime(true) * 1000) - $started;

            return [
                'ok' => false,
                'status' => $first['status'],
                'message' => grooflow_buk_pe_failure_message($first['status'], $first['triedUrl'], ''),
                'data' => [],
                'triedUrl' => $first['triedUrl'],
                'durationMs' => $duration,
            ];
        }
        $all = array_merge($all, $first['records']);
        $totalPages = min($first['totalPages'], $maxPages);
        for ($p = 2; $p <= $totalPages; $p++) {
            $next = grooflow_buk_pe_fetch_page($baseUrl, $apiToken, $p, $pageSize, 120);
            if ($next['status'] < 200 || $next['status'] >= 300) {
                break;
            }
            $all = array_merge($all, $next['records']);
        }
        $duration = (int) round(microtime(true) * 1000) - $started;

        return [
            'ok' => true,
            'status' => 200,
            'data' => $all,
            'recordCount' => count($all),
            'totalPages' => $totalPages,
            'durationMs' => $duration,
        ];
    }

    throw new InvalidArgumentException('Acción Buk.pe no soportada.');
}
