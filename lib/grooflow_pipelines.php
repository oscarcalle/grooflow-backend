<?php

declare(strict_types=1);

/**
 * Fase 3 — Pipelines Buk automatizados.
 * - Maestros Buk.pe → RRHH (MySQL) + vínculos / bajas
 * - Marcaciones Ctrlit → historial Asistencia (MySQL)
 * - Opcional: enriquecimiento app_usuarios desde Buk Asistencia
 */

require_once __DIR__ . '/grooflow_kv.php';
require_once __DIR__ . '/grooflow_rrhh.php';
require_once __DIR__ . '/grooflow_asistencia.php';
require_once __DIR__ . '/grooflow_buk_sync.php';

function grooflow_cron_key_expected(): string
{
    if (defined('GROOFLOW_CRON_KEY')) {
        $k = trim((string) constant('GROOFLOW_CRON_KEY'));
        if ($k !== '') {
            return $k;
        }
    }
    $env = getenv('GROOFLOW_CRON_KEY');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }

    return '';
}

function grooflow_cron_key_from_request(): string
{
    $hdr = $_SERVER['HTTP_X_GROOFLOW_CRON_KEY'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
    if (is_string($hdr) && trim($hdr) !== '') {
        return trim($hdr);
    }
    $q = $_GET['cron_key'] ?? $_GET['key'] ?? '';
    if (is_string($q) && trim($q) !== '') {
        return trim($q);
    }

    return '';
}

/**
 * Autoriza job programado: clave cron configurada O sesión admin.
 */
function grooflow_assert_cron_or_admin(PDO $pdo, ?string $providedOverride = null): string
{
    $expected = grooflow_cron_key_expected();
    $provided = $providedOverride !== null && $providedOverride !== ''
        ? trim($providedOverride)
        : grooflow_cron_key_from_request();
    if ($expected !== '' && $provided !== '' && hash_equals($expected, $provided)) {
        return 'cron_key';
    }

    $token = function_exists('api_bearer_token') ? api_bearer_token() : '';
    if ($token !== '') {
        if (function_exists('api_require_auth')) {
            api_require_auth($pdo);
        }
        grooflow_assert_admin($pdo);

        return 'admin';
    }

    if ($expected === '') {
        throw new RuntimeException('Cron no configurado (defina GROOFLOW_CRON_KEY) y no hay sesión admin');
    }

    throw new RuntimeException('Clave de cron inválida o sesión no autorizada');
}

/**
 * Sync Buk.pe → maestro RRHH si pasó el intervalo (settings:rrhh).
 *
 * @param array{force?:bool,includeAsistencia?:bool} $options
 * @return array<string, mixed>
 */
function grooflow_rrhh_sync_if_due(PDO $pdo, array $options = []): array
{
    grooflow_rrhh_ensure_schema($pdo);
    $meta = grooflow_kv_get($pdo, 'settings:rrhh');
    $meta = is_array($meta) ? $meta : [];

    if (($meta['staffSyncEnabled'] ?? true) === false && empty($options['force'])) {
        return ['ran' => false, 'reason' => 'staff_sync_disabled'];
    }

    $interval = (int) ($meta['staffSyncIntervalMinutes'] ?? 60);
    $interval = max(15, min(24 * 60, $interval > 0 ? $interval : 60));
    $lastAt = trim((string) ($meta['lastSyncAt'] ?? ''));
    $force = ! empty($options['force']);

    if (! $force && $lastAt !== '') {
        $lastTs = strtotime($lastAt);
        if ($lastTs !== false && (time() - $lastTs) < ($interval * 60)) {
            return [
                'ran' => false,
                'reason' => 'not_due',
                'interval_minutes' => $interval,
                'seconds_remaining' => ($interval * 60) - (time() - $lastTs),
                'lastSyncAt' => $lastAt,
            ];
        }
    }

    $includeAsistencia = ($options['includeAsistencia'] ?? $meta['includeAsistenciaEnrichment'] ?? true) !== false;

    try {
        $result = grooflow_rrhh_sync_from_apis($pdo, [
            'includeAsistencia' => $includeAsistencia,
            'autoDisableOnTermination' => ($meta['autoDisableOnTermination'] ?? true) !== false,
        ]);

        return ['ran' => true, 'pipeline' => 'rrhh_buk_pe', 'result' => $result];
    } catch (Throwable $e) {
        $meta['lastSyncAt'] = date('c');
        $meta['lastSyncOk'] = false;
        $meta['lastSyncMessage'] = 'Pipeline RRHH: ' . $e->getMessage();
        $log = is_array($meta['syncLog'] ?? null) ? $meta['syncLog'] : [];
        array_unshift($log, [
            'at' => $meta['lastSyncAt'],
            'ok' => false,
            'message' => $meta['lastSyncMessage'],
            'source' => 'pipeline',
        ]);
        $meta['syncLog'] = array_slice($log, 0, 30);
        unset($meta['employees']);
        grooflow_kv_set($pdo, 'settings:rrhh', $meta);

        return [
            'ran' => true,
            'pipeline' => 'rrhh_buk_pe',
            'result' => ['ok' => false, 'error' => $e->getMessage()],
        ];
    }
}

/**
 * Marcaciones Buk Asistencia (Ctrlit) → historial MySQL.
 *
 * @param array{force?:bool,maxPages?:int} $options
 * @return array<string, mixed>
 */
function grooflow_asistencia_marcaciones_pipeline_if_due(PDO $pdo, array $options = []): array
{
    grooflow_asistencia_ensure_schema($pdo);
    $settings = grooflow_asistencia_get_settings($pdo);
    $buk = is_array($settings['buk'] ?? null) ? $settings['buk'] : [];

    $enabled = ($buk['marcacionesPipelineEnabled'] ?? true) !== false;
    if (! $enabled && empty($options['force'])) {
        return ['ran' => false, 'reason' => 'marcaciones_pipeline_disabled'];
    }
    if (empty($buk['enabled']) && empty($options['force'])) {
        return ['ran' => false, 'reason' => 'buk_asistencia_disabled'];
    }

    $token = grooflow_normalize_buk_token((string) ($buk['apiToken'] ?? ''));
    if ($token === '' || grooflow_buk_token_is_redacted($token)) {
        return ['ran' => false, 'reason' => 'missing_token'];
    }

    $interval = (int) ($buk['marcacionesPipelineIntervalMinutes'] ?? 30);
    $interval = max(10, min(24 * 60, $interval > 0 ? $interval : 30));
    $lastAt = trim((string) ($buk['lastMarcacionesPipelineAt'] ?? ''));
    $force = ! empty($options['force']);

    if (! $force && $lastAt !== '') {
        $lastTs = strtotime($lastAt);
        if ($lastTs !== false && (time() - $lastTs) < ($interval * 60)) {
            return [
                'ran' => false,
                'reason' => 'not_due',
                'interval_minutes' => $interval,
                'seconds_remaining' => ($interval * 60) - (time() - $lastTs),
                'lastMarcacionesPipelineAt' => $lastAt,
            ];
        }
    }

    $maxPages = max(1, min(40, (int) ($options['maxPages'] ?? 20)));
    $started = (int) round(microtime(true) * 1000);

    try {
        $base = grooflow_sanitize_buk_base_url((string) ($buk['apiBaseUrl'] ?? ''));
        $records = grooflow_buk_fetch_asistencia_today($base, $token, $maxPages);
        $upsert = grooflow_asistencia_buk_records_upsert($pdo, $records);
        $at = date('c');
        $buk['lastMarcacionesPipelineAt'] = $at;
        $buk['lastMarcacionesPipelineOk'] = true;
        $buk['lastMarcacionesPipelineMessage'] = sprintf(
            'Marcaciones: %d registros upsert (%d ms)',
            (int) ($upsert['upserted'] ?? 0),
            (int) round(microtime(true) * 1000) - $started
        );
        $buk['lastMarcacionesPipelineCount'] = (int) ($upsert['upserted'] ?? 0);
        $settings['buk'] = $buk;
        grooflow_asistencia_set_settings($pdo, $settings);

        return [
            'ran' => true,
            'pipeline' => 'marcaciones',
            'result' => [
                'ok' => true,
                'upserted' => (int) ($upsert['upserted'] ?? 0),
                'fetched' => count($records),
                'duration_ms' => (int) round(microtime(true) * 1000) - $started,
                'synced_at' => $at,
                'message' => $buk['lastMarcacionesPipelineMessage'],
            ],
        ];
    } catch (Throwable $e) {
        $buk['lastMarcacionesPipelineAt'] = date('c');
        $buk['lastMarcacionesPipelineOk'] = false;
        $buk['lastMarcacionesPipelineMessage'] = $e->getMessage();
        $settings['buk'] = $buk;
        try {
            grooflow_asistencia_set_settings($pdo, $settings);
        } catch (Throwable) {
        }

        return [
            'ran' => true,
            'pipeline' => 'marcaciones',
            'result' => ['ok' => false, 'error' => $e->getMessage()],
        ];
    }
}

/**
 * Ejecuta pipelines de identidad (Fase 3).
 *
 * @param array{
 *   force?:bool,
 *   forceRrhh?:bool,
 *   forceMarcaciones?:bool,
 *   skipRrhh?:bool,
 *   skipMarcaciones?:bool,
 *   skipUsuariosEnrich?:bool
 * } $options
 * @return array<string, mixed>
 */
function grooflow_pipelines_run(PDO $pdo, array $options = []): array
{
    $started = (int) round(microtime(true) * 1000);
    $steps = [];

    if (empty($options['skipRrhh'])) {
        $steps['rrhh'] = grooflow_rrhh_sync_if_due($pdo, [
            'force' => ! empty($options['force']) || ! empty($options['forceRrhh']),
        ]);
    } else {
        $steps['rrhh'] = ['ran' => false, 'reason' => 'skipped'];
    }

    if (empty($options['skipMarcaciones'])) {
        $steps['marcaciones'] = grooflow_asistencia_marcaciones_pipeline_if_due($pdo, [
            'force' => ! empty($options['force']) || ! empty($options['forceMarcaciones']),
        ]);
    } else {
        $steps['marcaciones'] = ['ran' => false, 'reason' => 'skipped'];
    }

    if (empty($options['skipUsuariosEnrich'])) {
        $steps['usuariosEnrich'] = grooflow_buk_sync_usuarios_if_due($pdo);
    } else {
        $steps['usuariosEnrich'] = ['ran' => false, 'reason' => 'skipped'];
    }

    // Fase 4: proyectar organigrama si hubo sync RRHH (o force) y no se omitió.
    $projectEnabled = ($options['skipAsistenciaProject'] ?? null) !== true;
    $rrhhRan = ! empty($steps['rrhh']['ran']);
    $rrhhOk = $rrhhRan && (
        isset($steps['rrhh']['result']['stats'])
        || (($steps['rrhh']['result']['ok'] ?? true) !== false)
    );
    $forceProject = ! empty($options['force']) || ! empty($options['forceAsistenciaProject']);
    if ($projectEnabled && ($forceProject || $rrhhOk)) {
        try {
            $proj = grooflow_rrhh_project_asistencia_staff($pdo, [
                'pruneInactive' => ($options['pruneInactive'] ?? true) !== false,
            ]);
            $steps['asistenciaProject'] = ['ran' => true, 'pipeline' => 'asistencia_project', 'result' => $proj];
        } catch (Throwable $e) {
            $steps['asistenciaProject'] = [
                'ran' => true,
                'pipeline' => 'asistencia_project',
                'result' => ['ok' => false, 'error' => $e->getMessage()],
            ];
        }
    } elseif (! $projectEnabled) {
        $steps['asistenciaProject'] = ['ran' => false, 'reason' => 'skipped'];
    } else {
        $steps['asistenciaProject'] = ['ran' => false, 'reason' => 'rrhh_not_ran'];
    }

    $health = grooflow_pipelines_health($pdo);
    $at = date('c');

    $meta = grooflow_kv_get($pdo, 'settings:rrhh');
    $meta = is_array($meta) ? $meta : [];
    $meta['lastPipelineAt'] = $at;
    $meta['lastPipelineOk'] = $health['ok'];
    $meta['lastPipelineSummary'] = $health['summary'];
    $meta['lastPipelineSteps'] = [
        'rrhh' => [
            'ran' => ! empty($steps['rrhh']['ran']),
            'reason' => $steps['rrhh']['reason'] ?? null,
            'ok' => isset($steps['rrhh']['result']['ok'])
                ? (bool) $steps['rrhh']['result']['ok']
                : (isset($steps['rrhh']['result']['stats']) ? true : null),
        ],
        'marcaciones' => [
            'ran' => ! empty($steps['marcaciones']['ran']),
            'reason' => $steps['marcaciones']['reason'] ?? null,
            'ok' => isset($steps['marcaciones']['result']['ok']) ? (bool) $steps['marcaciones']['result']['ok'] : null,
        ],
        'usuariosEnrich' => [
            'ran' => ! empty($steps['usuariosEnrich']['ran']),
            'reason' => $steps['usuariosEnrich']['reason'] ?? null,
        ],
        'asistenciaProject' => [
            'ran' => ! empty($steps['asistenciaProject']['ran']),
            'reason' => $steps['asistenciaProject']['reason'] ?? null,
            'ok' => isset($steps['asistenciaProject']['result']['error'])
                ? false
                : (isset($steps['asistenciaProject']['result']['added']) ? true : null),
        ],
    ];
    unset($meta['employees']);
    grooflow_kv_set($pdo, 'settings:rrhh', $meta);

    return [
        'ok' => $health['ok'],
        'ran_at' => $at,
        'duration_ms' => (int) round(microtime(true) * 1000) - $started,
        'steps' => $steps,
        'health' => $health,
        'policy' => [
            'altaSinUsuario' => 'pendiente_notificacion',
            'sourceOfTruth' => 'buk.pe',
            'marcacionesSource' => 'ctrlit',
        ],
    ];
}

/**
 * Salud de pipelines para UI / alertas.
 *
 * @return array<string, mixed>
 */
function grooflow_pipelines_health(PDO $pdo): array
{
    grooflow_rrhh_ensure_schema($pdo);
    $meta = grooflow_kv_get($pdo, 'settings:rrhh');
    $meta = is_array($meta) ? $meta : [];
    $settings = grooflow_asistencia_get_settings($pdo);
    $buk = is_array($settings['buk'] ?? null) ? $settings['buk'] : [];

    $today = date('Y-m-d');
    $lastSyncAt = trim((string) ($meta['lastSyncAt'] ?? ''));
    $lastSyncOk = ($meta['lastSyncOk'] ?? null) !== false && ($meta['lastSyncOk'] ?? null) !== 0;
    $syncDay = $lastSyncAt !== '' ? date('Y-m-d', strtotime($lastSyncAt) ?: 0) : '';
    $syncedToday = $syncDay === $today;

    $pending = (int) ($meta['pendingAccessCount'] ?? 0);
    if ($pending <= 0 && is_array($meta['pendingAccess'] ?? null)) {
        $pending = count($meta['pendingAccess']);
    }

    $activos = 0;
    $linked = 0;
    try {
        $activos = (int) ($pdo->query('SELECT COUNT(*) FROM grooflow_buk_empleados WHERE is_active = 1')->fetchColumn() ?: 0);
        $linked = (int) ($pdo->query('SELECT COUNT(*) FROM grooflow_buk_empleados WHERE is_active = 1 AND linked_usuario_id IS NOT NULL')->fetchColumn() ?: 0);
    } catch (Throwable) {
    }
    $unmatchedPct = $activos > 0 ? round((($activos - $linked) / $activos) * 100, 1) : 0.0;

    $marcAt = trim((string) ($buk['lastMarcacionesPipelineAt'] ?? ''));
    $marcOk = ($buk['lastMarcacionesPipelineOk'] ?? null) !== false;
    $marcDay = $marcAt !== '' ? date('Y-m-d', strtotime($marcAt) ?: 0) : '';

    $issues = [];
    if ($lastSyncAt === '') {
        $issues[] = 'never_synced_rrhh';
    } elseif (! $lastSyncOk) {
        $issues[] = 'rrhh_sync_failed';
    } elseif (! $syncedToday) {
        $issues[] = 'rrhh_no_sync_today';
    }
    if ($pending > 0) {
        $issues[] = 'pending_access';
    }
    if ($unmatchedPct >= 25 && $activos >= 10) {
        $issues[] = 'high_unmatched_pct';
    }
    if (! empty($buk['enabled']) && ($buk['marcacionesPipelineEnabled'] ?? true) !== false) {
        if ($marcAt !== '' && ! $marcOk) {
            $issues[] = 'marcaciones_failed';
        } elseif ($marcAt === '' || $marcDay !== $today) {
            $issues[] = 'marcaciones_stale';
        }
    }

    $ok = ! in_array('rrhh_sync_failed', $issues, true)
        && ! in_array('marcaciones_failed', $issues, true)
        && ! in_array('never_synced_rrhh', $issues, true);

    $summaryParts = [];
    if ($lastSyncAt !== '') {
        $summaryParts[] = $lastSyncOk ? 'RRHH OK' : 'RRHH error';
    } else {
        $summaryParts[] = 'RRHH sin sync';
    }
    if ($pending > 0) {
        $summaryParts[] = "{$pending} pendientes acceso";
    }
    if ($unmatchedPct > 0) {
        $summaryParts[] = "{$unmatchedPct}% sin vínculo";
    }

    return [
        'ok' => $ok,
        'summary' => implode(' · ', $summaryParts) ?: 'Sin datos',
        'issues' => $issues,
        'rrhh' => [
            'lastSyncAt' => $lastSyncAt !== '' ? $lastSyncAt : null,
            'lastSyncOk' => $lastSyncAt !== '' ? $lastSyncOk : null,
            'lastSyncMessage' => isset($meta['lastSyncMessage']) ? (string) $meta['lastSyncMessage'] : null,
            'syncedToday' => $syncedToday,
            'staffSyncEnabled' => ($meta['staffSyncEnabled'] ?? true) !== false,
            'intervalMinutes' => (int) ($meta['staffSyncIntervalMinutes'] ?? 60),
            'pendingAccess' => $pending,
            'activos' => $activos,
            'linked' => $linked,
            'unmatchedPct' => $unmatchedPct,
            'lastPipelineAt' => isset($meta['lastPipelineAt']) ? (string) $meta['lastPipelineAt'] : null,
        ],
        'marcaciones' => [
            'enabled' => ! empty($buk['enabled']) && ($buk['marcacionesPipelineEnabled'] ?? true) !== false,
            'lastAt' => $marcAt !== '' ? $marcAt : null,
            'lastOk' => $marcAt !== '' ? $marcOk : null,
            'lastMessage' => isset($buk['lastMarcacionesPipelineMessage']) ? (string) $buk['lastMarcacionesPipelineMessage'] : null,
            'lastCount' => (int) ($buk['lastMarcacionesPipelineCount'] ?? 0),
            'intervalMinutes' => (int) ($buk['marcacionesPipelineIntervalMinutes'] ?? 30),
            'syncedToday' => $marcDay === $today,
        ],
        'generatedAt' => date('c'),
    ];
}
