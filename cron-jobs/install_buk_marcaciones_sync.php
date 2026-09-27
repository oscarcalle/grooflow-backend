#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Copia el job de marcaciones a public_html/cron/jobs/grooflow/ y lo registra en cron_jobs.
 * Uso (en el servidor): php grooflow-backend/cron-jobs/install_buk_marcaciones_sync.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
require $root . '/config.php';
require_once $root . '/functions.php';

$source = __DIR__ . '/grooflow/sync_buk_marcaciones.php';
$targetDir = CRON_ROOT . '/' . CRON_JOBS_PATH . '/grooflow';
if (! is_dir($targetDir) && ! mkdir($targetDir, 0755, true) && ! is_dir($targetDir)) {
    fwrite(STDERR, "No se pudo crear {$targetDir}\n");
    exit(1);
}
if (! copy($source, $targetDir . '/sync_buk_marcaciones.php')) {
    fwrite(STDERR, "No se pudo copiar el job a {$targetDir}\n");
    exit(1);
}

$command = CRON_JOBS_PATH . '/grooflow/sync_buk_marcaciones.php';
$name = 'GrooFlow sync Buk marcaciones';
$schedule = '*/10 * * * *';

$exists = $pdo->prepare('SELECT id FROM cron_jobs WHERE command = ? LIMIT 1');
$exists->execute([$command]);
$jobId = (int) ($exists->fetchColumn() ?: 0);
$nextRun = calculateNextRun($schedule)->format('Y-m-d H:i:s');

if ($jobId <= 0) {
    $pdo->prepare('
        INSERT INTO cron_jobs (name, command, schedule, active, next_run, timeout_seconds, memory_limit_mb)
        VALUES (?, ?, ?, 1, ?, 300, 512)
    ')->execute([$name, $command, $schedule, $nextRun]);
    echo "Job creado: {$name}\n";
} else {
    $pdo->prepare('
        UPDATE cron_jobs
        SET name = ?, schedule = ?, active = 1,
            next_run = COALESCE(next_run, ?),
            timeout_seconds = GREATEST(timeout_seconds, 300),
            memory_limit_mb = GREATEST(memory_limit_mb, 512)
        WHERE id = ?
    ')->execute([$name, $schedule, $nextRun, $jobId]);
    echo "Job actualizado: {$name} (#{$jobId})\n";
}
