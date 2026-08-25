<?php

declare(strict_types=1);

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
        if (is_array($json) && array_keys($json) === range(0, count($json) - 1)) {
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

function grooflow_handle_buk(string $action, array $data): array
{
    $baseUrl = rtrim(trim((string) ($data['baseUrl'] ?? $data['url'] ?? '')), '/');
    $apiToken = trim((string) ($data['apiToken'] ?? $data['token'] ?? ''));
    if (str_starts_with(strtolower($apiToken), 'bearer ')) {
        $apiToken = trim(substr($apiToken, 7));
    }
    if ($apiToken === '') {
        throw new InvalidArgumentException('Indica el token de la API.');
    }
    if ($baseUrl === '') {
        $baseUrl = 'https://app.ctrlit.cl/ctrl/api/v2';
    }
    $page = max(1, (int) ($data['page'] ?? 1));
    $perPage = max(1, min(200, (int) ($data['perPage'] ?? $data['pageSize'] ?? 100)));
    $url = $baseUrl . '/asistencia-empresa?page=' . $page . '&per_page=' . $perPage;
    grooflow_assert_buk_url($url);
    $started = (int) round(microtime(true) * 1000);
    $res = grooflow_proxy_fetch($url, [
        'token: ' . $apiToken,
        'Accept: application/json',
    ], $action === 'fetch-all' ? 120 : 45);
    $json = json_decode($res['body'], true);
    $duration = (int) round(microtime(true) * 1000) - $started;
    if ($action === 'test') {
        $count = 0;
        if (is_array($json)) {
            $count = (int) ($json['pagination']['count'] ?? (isset($json['data']) && is_array($json['data']) ? count($json['data']) : 0));
        }

        return [
            'ok' => $res['status'] >= 200 && $res['status'] < 300,
            'status' => $res['status'],
            'message' => $res['status'] >= 200 && $res['status'] < 300
                ? ('Conexión OK. ' . $count . ' registro(s) en asistencia.')
                : ('HTTP ' . $res['status'] . ' — URL: ' . $url),
            'recordHint' => $count ? ($count . ' registros') : null,
            'triedUrl' => $url,
            'durationMs' => $duration,
        ];
    }

    return [
        'ok' => $res['status'] >= 200 && $res['status'] < 300,
        'status' => $res['status'],
        'data' => is_array($json) ? $json : ['raw' => $res['body']],
        'triedUrl' => $url,
        'durationMs' => $duration,
    ];
}
