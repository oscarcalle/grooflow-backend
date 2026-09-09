<?php

declare(strict_types=1);

/**
 * CLI / Hostinger cron — ejecuta pipelines Fase 3.
 *
 * Ejemplo crontab (cada 15 min):
 *   Ejecutar /usr/bin/php /ruta/grooflow-backend/bin/run-pipelines.php cada 15 minutos.
 *
 * Opcional: export GROOFLOW_CRON_KEY=... (no obligatorio en CLI local).
 */

$root = dirname(__DIR__);
$grooflowRoot = dirname($root, 1);
if (! is_file($grooflowRoot . '/config.php')) {
    $grooflowRoot = dirname($root, 2);
}
if (! is_file($grooflowRoot . '/config.php')) {
    fwrite(STDERR, "config.php no encontrado\n");
    exit(1);
}

require_once $grooflowRoot . '/config.php';
require_once $grooflowRoot . '/backend/lib/dashboard_helpers.php';
require_once $grooflowRoot . '/backend/lib/api_request.php';
require_once $grooflowRoot . '/backend/lib/auth_api.php';
require_once $root . '/lib/grooflow_schema.php';
require_once $root . '/lib/grooflow_users.php';
require_once $root . '/lib/grooflow_kv.php';
require_once $root . '/lib/grooflow_pipelines.php';

if (! isset($pdo) || ! ($pdo instanceof PDO)) {
    fwrite(STDERR, "PDO no disponible\n");
    exit(1);
}

$force = in_array('--force', $argv ?? [], true);
$skipMarc = in_array('--skip-marcaciones', $argv ?? [], true);
$skipRrhh = in_array('--skip-rrhh', $argv ?? [], true);

try {
    grooflow_ensure_schema($pdo);
    $result = grooflow_pipelines_run($pdo, [
        'force' => $force,
        'skipMarcaciones' => $skipMarc,
        'skipRrhh' => $skipRrhh,
    ]);
    echo json_encode(['ok' => true, ...$result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(! empty($result['ok']) ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, '[pipelines] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
