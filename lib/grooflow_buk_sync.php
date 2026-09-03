<?php

declare(strict_types=1);

/**
 * Sincroniza datos laborales Buk Asistencia (Ctrlit) → app_usuarios.
 *
 * Fuentes:
 * - GET /ctrl/api/obtenerNominaColaborador (DNI, especialidad, contrato, obra)
 * - GET /ctrl/api/v2/asistencia-empresa (nombre, área, turno, código)
 * - GET /ctrl/api/getAsignacionTurnos?token=… (área, horario, nombre turno)
 *
 * Matching: identificacion / buk_dni = DNI (solo dígitos). No crea usuarios por defecto.
 */

require_once __DIR__ . '/grooflow_proxy.php';
require_once __DIR__ . '/grooflow_asistencia.php';

if (! function_exists('usuarios_ensure_columns')) {
    require_once (defined('CRON_ROOT') ? CRON_ROOT : dirname(__DIR__, 2)) . '/backend/lib/usuarios_api.php';
}

function grooflow_buk_api_root_from_base(string $baseUrl): string
{
    $base = grooflow_sanitize_buk_base_url($baseUrl);
    $root = preg_replace('#/v2/?$#i', '', $base) ?? $base;

    return rtrim($root, '/');
}

function grooflow_buk_normalize_dni(string $raw): string
{
    return preg_replace('/\D+/', '', trim($raw)) ?? '';
}

/** @return array{status:int,json:mixed,body:string,url:string} */
function grooflow_buk_http_get(string $url, string $apiToken, bool $tokenInQuery = false, int $timeoutSec = 90): array
{
    if ($tokenInQuery) {
        $sep = str_contains($url, '?') ? '&' : '?';
        $url .= $sep . 'token=' . rawurlencode($apiToken);
    }
    grooflow_assert_buk_url($url);
    $res = grooflow_proxy_fetch($url, [
        'token: ' . $apiToken,
        'Accept: application/json',
    ], $timeoutSec);
    $json = json_decode($res['body'], true);

    return [
        'status' => $res['status'],
        'json' => $json,
        'body' => $res['body'],
        'url' => $url,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function grooflow_buk_fetch_nomina_all(string $apiRoot, string $apiToken, int $pageSize = 100, int $maxPages = 40): array
{
    $all = [];
    $pageSize = max(1, min(100, $pageSize));
    for ($page = 1; $page <= $maxPages; $page++) {
        $url = rtrim($apiRoot, '/') . '/obtenerNominaColaborador?page=' . $page . '&page_size=' . $pageSize;
        $res = grooflow_buk_http_get($url, $apiToken, false, 90);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException('Nómina Buk HTTP ' . $res['status'] . ' — ' . $res['url']);
        }
        $records = grooflow_buk_extract_records($res['json']);
        if ($records === []) {
            break;
        }
        $all = array_merge($all, $records);
        $pag = is_array($res['json']) && is_array($res['json']['pagination'] ?? null)
            ? $res['json']['pagination']
            : [];
        $totalPages = max(1, (int) ($pag['totalPages'] ?? 1));
        if ($page >= $totalPages) {
            break;
        }
    }

    return $all;
}

/**
 * @return list<array<string, mixed>>
 */
function grooflow_buk_fetch_turnos_all(string $apiRoot, string $apiToken): array
{
    $url = rtrim($apiRoot, '/') . '/getAsignacionTurnos';
    $res = grooflow_buk_http_get($url, $apiToken, true, 120);
    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException('Turnos Buk HTTP ' . $res['status'] . ' — ' . $res['url']);
    }
    $records = grooflow_buk_extract_records($res['json']);
    if ($records === [] && is_array($res['json']) && array_is_list($res['json'])) {
        $records = $res['json'];
    }

    return $records;
}

/**
 * @return list<array<string, mixed>>
 */
function grooflow_buk_fetch_asistencia_today(string $v2Base, string $apiToken, int $maxPages = 20): array
{
    $pageRes = grooflow_buk_fetch_page($v2Base, $apiToken, 1, 100, 90);
    if ($pageRes['status'] < 200 || $pageRes['status'] >= 300) {
        throw new RuntimeException('Asistencia Buk HTTP ' . $pageRes['status'] . ' — ' . $pageRes['triedUrl']);
    }
    $all = $pageRes['records'];
    $totalPages = min($pageRes['totalPages'], $maxPages);
    for ($p = 2; $p <= $totalPages; $p++) {
        $next = grooflow_buk_fetch_page($v2Base, $apiToken, $p, 100, 90);
        if ($next['status'] < 200 || $next['status'] >= 300) {
            break;
        }
        $all = array_merge($all, $next['records']);
    }

    return $all;
}

/**
 * @param list<array<string, mixed>> $nomina
 * @param list<array<string, mixed>> $asistencia
 * @param list<array<string, mixed>> $turnos
 * @return array<string, array<string, mixed>> keyed by DNI
 */
function grooflow_buk_merge_staff_by_dni(array $nomina, array $asistencia, array $turnos): array
{
    $byDni = [];

    foreach ($nomina as $row) {
        if (! is_array($row)) {
            continue;
        }
        $dni = grooflow_buk_normalize_dni((string) ($row['DNI'] ?? $row['dni'] ?? ''));
        if ($dni === '') {
            continue;
        }
        $byDni[$dni] = [
            'dni' => $dni,
            'obra_id' => (int) ($row['obra_id'] ?? $row['obraId'] ?? 0) ?: null,
            'empresa' => trim((string) ($row['empresa'] ?? '')),
            'contrato' => trim((string) ($row['contrato'] ?? '')),
            'especialidad' => trim((string) ($row['especialidad'] ?? '')),
            'puesto' => trim((string) ($row['especialidad'] ?? '')),
            'estado_buk' => trim((string) ($row['estado'] ?? '')),
            'area' => '',
            'turno' => '',
            'turno_horario' => '',
            'turno_codigo' => '',
            'nombre' => '',
        ];
    }

    foreach ($asistencia as $row) {
        if (! is_array($row)) {
            continue;
        }
        $dni = grooflow_buk_normalize_dni((string) ($row['rut_trabajador'] ?? $row['DNI'] ?? $row['dni'] ?? ''));
        if ($dni === '') {
            continue;
        }
        if (! isset($byDni[$dni])) {
            $byDni[$dni] = [
                'dni' => $dni,
                'obra_id' => (int) ($row['id_recinto'] ?? 0) ?: null,
                'empresa' => '',
                'contrato' => trim((string) ($row['contrato'] ?? '')),
                'especialidad' => trim((string) ($row['especialidad'] ?? '')),
                'puesto' => trim((string) ($row['especialidad'] ?? '')),
                'estado_buk' => 'vinculado',
                'area' => '',
                'turno' => '',
                'turno_horario' => '',
                'turno_codigo' => '',
                'nombre' => '',
            ];
        }
        $name = trim(implode(' ', array_filter([
            (string) ($row['nombre'] ?? ''),
            (string) ($row['apellido_paterno'] ?? ''),
            (string) ($row['apellido_materno'] ?? ''),
        ])));
        if ($name !== '') {
            $byDni[$dni]['nombre'] = $name;
        }
        $area = trim((string) ($row['area'] ?? ''));
        if ($area !== '') {
            $byDni[$dni]['area'] = $area;
        }
        $esp = trim((string) ($row['especialidad'] ?? ''));
        if ($esp !== '') {
            $byDni[$dni]['especialidad'] = $esp;
            $byDni[$dni]['puesto'] = $esp;
        }
        $contrato = trim((string) ($row['contrato'] ?? ''));
        if ($contrato !== '') {
            $byDni[$dni]['contrato'] = $contrato;
        }
        $turnoHorario = trim((string) ($row['turno'] ?? ''));
        if ($turnoHorario !== '') {
            $byDni[$dni]['turno_horario'] = $turnoHorario;
        }
        $codigo = trim((string) ($row['codigo_turno'] ?? ''));
        if ($codigo !== '') {
            $byDni[$dni]['turno_codigo'] = $codigo;
            $byDni[$dni]['turno'] = $codigo;
        }
        $obra = (int) ($row['id_recinto'] ?? 0);
        if ($obra > 0) {
            $byDni[$dni]['obra_id'] = $obra;
        }
    }

    // Turnos: quedarse con el más reciente por DNI (lista suele venir cronológica).
    $turnosByDni = [];
    foreach ($turnos as $row) {
        if (! is_array($row)) {
            continue;
        }
        $dni = grooflow_buk_normalize_dni((string) ($row['dni'] ?? $row['DNI'] ?? ''));
        if ($dni === '') {
            continue;
        }
        $turnosByDni[$dni] = $row;
    }
    foreach ($turnosByDni as $dni => $row) {
        if (! isset($byDni[$dni])) {
            $byDni[$dni] = [
                'dni' => $dni,
                'obra_id' => (int) ($row['idRecinto'] ?? 0) ?: null,
                'empresa' => '',
                'contrato' => '',
                'especialidad' => '',
                'puesto' => '',
                'estado_buk' => 'vinculado',
                'area' => '',
                'turno' => '',
                'turno_horario' => '',
                'turno_codigo' => '',
                'nombre' => '',
            ];
        }
        $area = trim((string) ($row['areaTrabajador'] ?? ''));
        if ($area !== '') {
            $byDni[$dni]['area'] = $area;
        }
        $nombreTurno = trim((string) ($row['nombreTurno'] ?? ''));
        $horario = trim((string) ($row['horarioTurno'] ?? ''));
        $idTurno = trim((string) ($row['idTurno'] ?? ''));
        if ($nombreTurno !== '') {
            $byDni[$dni]['turno'] = $nombreTurno;
        } elseif ($idTurno !== '') {
            $byDni[$dni]['turno'] = $idTurno;
        }
        if ($horario !== '' && $horario !== '-') {
            $byDni[$dni]['turno_horario'] = $horario;
        }
        if ($idTurno !== '') {
            $byDni[$dni]['turno_codigo'] = $idTurno;
        }
        $name = trim((string) ($row['nombreTrabajador'] ?? ''));
        if ($name !== '' && ($byDni[$dni]['nombre'] ?? '') === '') {
            $byDni[$dni]['nombre'] = $name;
        }
    }

    return $byDni;
}

/**
 * @return array{matched:int,updated:int,skipped:int,unmatched_buk:int,users_scanned:int,by_source:array<string,int>,errors:list<string>,duration_ms:int,synced_at:string}
 */
function grooflow_buk_sync_usuarios(PDO $pdo, array $options = []): array
{
    $started = (int) round(microtime(true) * 1000);
    usuarios_ensure_columns($pdo);

    $settings = grooflow_asistencia_get_settings($pdo);
    $buk = is_array($settings['buk'] ?? null) ? $settings['buk'] : [];
    $baseUrl = grooflow_sanitize_buk_base_url((string) ($options['baseUrl'] ?? $buk['apiBaseUrl'] ?? ''));
    $apiToken = grooflow_resolve_buk_api_token($pdo, (string) ($options['apiToken'] ?? $buk['apiToken'] ?? ''));
    $apiRoot = grooflow_buk_api_root_from_base($baseUrl);
    $createMissing = ! empty($options['createMissing']);
    $onlyVinculados = ($options['onlyVinculados'] ?? true) !== false;

    $errors = [];
    $nomina = [];
    $asistencia = [];
    $turnos = [];
    $bySource = ['nomina' => 0, 'asistencia' => 0, 'turnos' => 0];

    try {
        $nomina = grooflow_buk_fetch_nomina_all($apiRoot, $apiToken);
        $bySource['nomina'] = count($nomina);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
    try {
        $asistencia = grooflow_buk_fetch_asistencia_today($baseUrl, $apiToken);
        $bySource['asistencia'] = count($asistencia);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
    try {
        $turnos = grooflow_buk_fetch_turnos_all($apiRoot, $apiToken);
        $bySource['turnos'] = count($turnos);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    if ($nomina === [] && $asistencia === [] && $turnos === []) {
        throw new RuntimeException(
            'No se pudo obtener datos de Buk (' . implode('; ', $errors ?: ['sin detalle']) . ')'
        );
    }

    $merged = grooflow_buk_merge_staff_by_dni($nomina, $asistencia, $turnos);
    if ($onlyVinculados) {
        $liveDnis = [];
        foreach ($asistencia as $a) {
            if (! is_array($a)) {
                continue;
            }
            $d = grooflow_buk_normalize_dni((string) ($a['rut_trabajador'] ?? ''));
            if ($d !== '') {
                $liveDnis[$d] = true;
            }
        }
        foreach ($turnos as $t) {
            if (! is_array($t)) {
                continue;
            }
            $d = grooflow_buk_normalize_dni((string) ($t['dni'] ?? ''));
            if ($d !== '') {
                $liveDnis[$d] = true;
            }
        }
        foreach ($merged as $dni => $row) {
            $estado = strtolower(trim((string) ($row['estado_buk'] ?? '')));
            if ($estado !== '' && $estado !== 'vinculado' && ! isset($liveDnis[$dni])) {
                unset($merged[$dni]);
            }
        }
    }

    $users = $pdo->query("
        SELECT id, identificacion, buk_dni, area, puesto, turno, turno_horario, turno_codigo,
               especialidad, contrato, nombre, apellido
        FROM app_usuarios
        WHERE is_deleted = 0
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /** @var array<string, list<array<string, mixed>>> */
    $usersByDni = [];
    foreach ($users as $u) {
        $seenKeys = [];
        foreach ([
            grooflow_buk_normalize_dni((string) ($u['buk_dni'] ?? '')),
            grooflow_buk_normalize_dni((string) ($u['identificacion'] ?? '')),
        ] as $dni) {
            if ($dni === '' || isset($seenKeys[$dni])) {
                continue;
            }
            $seenKeys[$dni] = true;
            $usersByDni[$dni][] = $u;
        }
    }

    $matched = 0;
    $updated = 0;
    $skipped = 0;
    $syncedAt = date('Y-m-d H:i:s');
    $upd = $pdo->prepare('
        UPDATE app_usuarios SET
            identificacion = COALESCE(NULLIF(?, ""), identificacion),
            area = COALESCE(NULLIF(?, ""), area),
            puesto = COALESCE(NULLIF(?, ""), puesto),
            turno = COALESCE(NULLIF(?, ""), turno),
            turno_horario = COALESCE(NULLIF(?, ""), turno_horario),
            turno_codigo = COALESCE(NULLIF(?, ""), turno_codigo),
            especialidad = COALESCE(NULLIF(?, ""), especialidad),
            contrato = COALESCE(NULLIF(?, ""), contrato),
            buk_dni = ?,
            buk_obra_id = COALESCE(?, buk_obra_id),
            buk_synced_at = ?
        WHERE id = ?
    ');

    foreach ($merged as $dni => $staff) {
        $matches = $usersByDni[$dni] ?? [];
        if ($matches === []) {
            if ($createMissing) {
                // Reservado: no crear automáticamente cuentas de panel.
                $skipped++;
            } else {
                $skipped++;
            }
            continue;
        }
        $matched += count($matches);
        foreach ($matches as $user) {
            $id = (int) $user['id'];
            $area = (string) ($staff['area'] ?? '');
            $puesto = (string) ($staff['puesto'] ?? $staff['especialidad'] ?? '');
            $turno = (string) ($staff['turno'] ?? '');
            $turnoHorario = (string) ($staff['turno_horario'] ?? '');
            $turnoCodigo = (string) ($staff['turno_codigo'] ?? '');
            $especialidad = (string) ($staff['especialidad'] ?? '');
            $contrato = (string) ($staff['contrato'] ?? '');
            $obraId = isset($staff['obra_id']) && $staff['obra_id'] ? (int) $staff['obra_id'] : null;

            $changed =
                grooflow_buk_normalize_dni((string) ($user['identificacion'] ?? '')) !== $dni
                || trim((string) ($user['area'] ?? '')) !== $area
                || trim((string) ($user['puesto'] ?? '')) !== $puesto
                || trim((string) ($user['turno'] ?? '')) !== $turno
                || trim((string) ($user['turno_horario'] ?? '')) !== $turnoHorario
                || trim((string) ($user['turno_codigo'] ?? '')) !== $turnoCodigo
                || trim((string) ($user['especialidad'] ?? '')) !== $especialidad
                || trim((string) ($user['contrato'] ?? '')) !== $contrato
                || grooflow_buk_normalize_dni((string) ($user['buk_dni'] ?? '')) !== $dni;

            $upd->execute([
                $dni,
                $area,
                $puesto,
                $turno,
                $turnoHorario,
                $turnoCodigo,
                $especialidad,
                $contrato,
                $dni,
                $obraId,
                $syncedAt,
                $id,
            ]);
            if ($changed || $upd->rowCount() > 0) {
                $updated++;
            }
        }
    }

    $unmatchedBuk = 0;
    foreach ($merged as $dni => $_) {
        if (! isset($usersByDni[$dni])) {
            $unmatchedBuk++;
        }
    }

    // Persistir meta de sync (intervalo / última ejecución) en settings Buk.
    $buk['lastStaffSyncAt'] = date('c');
    $buk['lastStaffSyncOk'] = true;
    $buk['lastStaffSyncMessage'] = sprintf(
        'Actualizados %d usuario(s); coincidencias %d; sin match en panel %d. Nómina %d · asistencia %d · turnos %d.',
        $updated,
        $matched,
        $unmatchedBuk,
        $bySource['nomina'],
        $bySource['asistencia'],
        $bySource['turnos']
    );
    if (! isset($buk['staffSyncIntervalMinutes']) || (int) $buk['staffSyncIntervalMinutes'] <= 0) {
        $buk['staffSyncIntervalMinutes'] = 60;
    }
    if (! array_key_exists('staffSyncEnabled', $buk)) {
        $buk['staffSyncEnabled'] = true;
    }
    $settings = is_array($settings) ? $settings : [];
    $settings['buk'] = $buk;
    grooflow_asistencia_set_settings($pdo, $settings);

    return [
        'matched' => $matched,
        'updated' => $updated,
        'skipped' => $skipped,
        'unmatched_buk' => $unmatchedBuk,
        'users_scanned' => count($users),
        'by_source' => $bySource,
        'errors' => $errors,
        'duration_ms' => (int) round(microtime(true) * 1000) - $started,
        'synced_at' => $syncedAt,
        'message' => (string) $buk['lastStaffSyncMessage'],
    ];
}

/**
 * Cron: ejecuta sync solo si pasó el intervalo configurable (default 60 min).
 *
 * @return array{ran:bool,reason?:string,result?:array<string,mixed>}
 */
function grooflow_buk_sync_usuarios_if_due(PDO $pdo): array
{
    $settings = grooflow_asistencia_get_settings($pdo);
    $buk = is_array($settings['buk'] ?? null) ? $settings['buk'] : [];
    if (empty($buk['enabled']) && empty($buk['staffSyncEnabled'])) {
        // Permitir sync programado si hay token aunque el panel de asistencia esté off,
        // siempre que staffSyncEnabled no esté explícitamente en false.
        if (($buk['staffSyncEnabled'] ?? null) === false) {
            return ['ran' => false, 'reason' => 'staff_sync_disabled'];
        }
    }
    if (($buk['staffSyncEnabled'] ?? true) === false) {
        return ['ran' => false, 'reason' => 'staff_sync_disabled'];
    }
    $token = grooflow_normalize_buk_token((string) ($buk['apiToken'] ?? ''));
    if ($token === '' || grooflow_buk_token_is_redacted($token)) {
        return ['ran' => false, 'reason' => 'missing_token'];
    }

    $interval = (int) ($buk['staffSyncIntervalMinutes'] ?? 60);
    $interval = max(15, min(24 * 60, $interval > 0 ? $interval : 60));
    $lastAt = trim((string) ($buk['lastStaffSyncAt'] ?? ''));
    if ($lastAt !== '') {
        $lastTs = strtotime($lastAt);
        if ($lastTs !== false && (time() - $lastTs) < ($interval * 60)) {
            return [
                'ran' => false,
                'reason' => 'not_due',
                'interval_minutes' => $interval,
                'seconds_remaining' => ($interval * 60) - (time() - $lastTs),
            ];
        }
    }

    try {
        $result = grooflow_buk_sync_usuarios($pdo, []);

        return ['ran' => true, 'result' => $result];
    } catch (Throwable $e) {
        $buk['lastStaffSyncAt'] = date('c');
        $buk['lastStaffSyncOk'] = false;
        $buk['lastStaffSyncMessage'] = $e->getMessage();
        $settings = is_array($settings) ? $settings : [];
        $settings['buk'] = $buk;
        try {
            grooflow_asistencia_set_settings($pdo, $settings);
        } catch (Throwable $ignored) {
        }

        return ['ran' => true, 'result' => ['ok' => false, 'error' => $e->getMessage()]];
    }
}
