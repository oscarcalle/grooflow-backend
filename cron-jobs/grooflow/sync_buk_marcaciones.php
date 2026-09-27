#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Pipeline programado Ctrlit (Buk Asistencia) → historial de marcaciones MySQL.
 * Se instala en public_html/cron/jobs/grooflow/ y el runner lo ejecuta cada 10 min;
 * el intervalo real lo define settings:asistencia.buk.marcacionesPipelineIntervalMinutes.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 3);
require_once $root . '/config.php';
require_once $root . '/grooflow-backend/lib/grooflow_schema.php';
require_once $root . '/grooflow-backend/lib/grooflow_pipelines.php';

grooflow_ensure_schema($pdo);
$result = grooflow_asistencia_marcaciones_pipeline_if_due($pdo);
$ok = ! isset($result['result']['ok']) || $result['result']['ok'] === true;
fwrite(STDOUT, json_encode(['ok' => $ok, ...$result], JSON_UNESCAPED_UNICODE) . PHP_EOL);
exit($ok ? 0 : 1);
