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
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
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
    $host = (string) (parse_url($targetUrl, PHP_URL_HOST) ?: '');
    if ($host === '' || ! preg_match('/^[a-z0-9.-]+$/i', $host)) {
        throw new InvalidArgumentException('URL de destino no permitida.');
    }
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
