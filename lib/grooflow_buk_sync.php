<?php

declare(strict_types=1);

/**
 * Sincroniza ficha laboral Buk.pe → app_usuarios.
 *
 * Fuente principal: grooflow_buk_empleados (maestro sync desde Buk.pe /employees).
 * Turno operativo: columnas de turno ya enriquecidas desde Ctrlit en RRHH;
 * si faltan, se rellenan opcionalmente desde getAsignacionTurnos (Asistencia).
 *
 * Matching: linked_usuario_id → DNI → email (solo si DNI ausente). No crea usuarios.
 */

require_once __DIR__ . '/grooflow_proxy.php';
require_once __DIR__ . '/grooflow_asistencia.php';
require_once __DIR__ . '/grooflow_kv.php';

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

function grooflow_buk_normalize_email(string $raw): string
{
    return strtolower(trim($raw));
}

/**
 * Actualiza app_usuarios desde grooflow_buk_empleados (maestro Buk.pe).
 * Matching: linked_usuario_id → DNI (document_number ↔ identificacion / buk_dni)
 * → solo si el DNI Buk está ausente, email (email / personal_email ↔ username / email).
 * Mapeo: document_number→identificacion, cargo→puesto, contract_type→contrato,
 * turno/turno_horario/turno_codigo (enrich Ctrlit en colaboradores).
 * Incluye inactivos/bajas (p. ej. reingreso en panel con mismo DNI); prioriza activos.
 * No pisa app_usuarios.area (área canónica del panel ≠ familia de cargo Buk).
 *
 * @return array{matched:int,updated:int,empleados:int,skipped:int,by_dni:int,by_email:int}
 */
function grooflow_buk_sync_usuarios_from_empleados(PDO $pdo, string $syncedAt): array
{
    if (! table_exists($pdo, 'grooflow_buk_empleados')) {
        return ['matched' => 0, 'updated' => 0, 'empleados' => 0, 'skipped' => 0, 'by_dni' => 0, 'by_email' => 0];
    }

    // Incluir inactivos: el panel puede tener usuarios activos con DNI de un historial Buk.
    // Orden: activos primero, luego no terminados, luego más recientes.
    $empleados = $pdo->query("
        SELECT buk_id, document_number, email, personal_email, cargo, contract_type, turno, turno_horario,
               turno_codigo, especialidad, linked_usuario_id, is_active, is_terminated
        FROM grooflow_buk_empleados
        WHERE missing_from_source = 0
        ORDER BY is_active DESC, is_terminated ASC, last_updated_at DESC, buk_id DESC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($empleados === []) {
        return ['matched' => 0, 'updated' => 0, 'empleados' => 0, 'skipped' => 0, 'by_dni' => 0, 'by_email' => 0];
    }

    $users = $pdo->query("
        SELECT id, username, email, identificacion, buk_dni, puesto, contrato, turno, turno_horario, turno_codigo
        FROM app_usuarios
        WHERE is_deleted = 0
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /** @var array<int, array<string, mixed>> */
    $usersById = [];
    /** @var array<string, list<array<string, mixed>>> */
    $usersByDni = [];
    /** @var array<string, list<array<string, mixed>>> */
    $usersByEmail = [];
    foreach ($users as $u) {
        $uid = (int) ($u['id'] ?? 0);
        if ($uid > 0) {
            $usersById[$uid] = $u;
        }
        $seenDnis = [];
        foreach ([
            grooflow_buk_normalize_dni((string) ($u['identificacion'] ?? '')),
            grooflow_buk_normalize_dni((string) ($u['buk_dni'] ?? '')),
        ] as $dni) {
            if ($dni === '' || isset($seenDnis[$dni])) {
                continue;
            }
            $seenDnis[$dni] = true;
            $usersByDni[$dni][] = $u;
        }
        foreach ([(string) ($u['email'] ?? ''), (string) ($u['username'] ?? '')] as $rawEmail) {
            $email = grooflow_buk_normalize_email($rawEmail);
            if ($email === '' || ! str_contains($email, '@')) {
                continue;
            }
            $usersByEmail[$email][] = $u;
        }
    }

    $upd = $pdo->prepare('
        UPDATE app_usuarios SET
            identificacion = COALESCE(NULLIF(?, ""), identificacion),
            puesto = COALESCE(NULLIF(?, ""), puesto),
            contrato = COALESCE(NULLIF(?, ""), contrato),
            turno = COALESCE(NULLIF(?, ""), turno),
            turno_horario = COALESCE(NULLIF(?, ""), turno_horario),
            turno_codigo = COALESCE(NULLIF(?, ""), turno_codigo),
            buk_dni = COALESCE(NULLIF(?, ""), buk_dni),
            buk_synced_at = ?
        WHERE id = ?
    ');

    $matched = 0;
    $updated = 0;
    $skipped = 0;
    $byDni = 0;
    $byEmail = 0;
    /** @var array<int, true> primera coincidencia (conteo matched); huecos se pueden rellenar después */
    $seenUserIds = [];

    foreach ($empleados as $emp) {
        $matches = [];
        $matchVia = '';
        $linkedId = (int) ($emp['linked_usuario_id'] ?? 0);
        $doc = grooflow_buk_normalize_dni((string) ($emp['document_number'] ?? ''));

        if ($linkedId > 0 && isset($usersById[$linkedId])) {
            $matches[] = $usersById[$linkedId];
            $matchVia = 'linked';
        } elseif ($doc !== '' && isset($usersByDni[$doc])) {
            $matches = $usersByDni[$doc];
            $matchVia = 'dni';
        } elseif ($doc === '') {
            // Solo si el DNI Buk está ausente: unir por email.
            $seenEmails = [];
            foreach ([(string) ($emp['email'] ?? ''), (string) ($emp['personal_email'] ?? '')] as $rawEmail) {
                $email = grooflow_buk_normalize_email($rawEmail);
                if ($email === '' || isset($seenEmails[$email]) || ! isset($usersByEmail[$email])) {
                    continue;
                }
                $seenEmails[$email] = true;
                foreach ($usersByEmail[$email] as $u) {
                    $matches[] = $u;
                }
            }
            if ($matches !== []) {
                $matchVia = 'email';
            }
        }

        if ($matches === []) {
            $skipped++;
            continue;
        }

        // Puesto = cargo Buk (fallback especialidad).
        $puesto = trim((string) ($emp['cargo'] ?? ''));
        if ($puesto === '') {
            $puesto = trim((string) ($emp['especialidad'] ?? ''));
        }
        $contrato = trim((string) ($emp['contract_type'] ?? ''));
        $turno = trim((string) ($emp['turno'] ?? ''));
        $turnoHorario = trim((string) ($emp['turno_horario'] ?? ''));
        $turnoCodigo = trim((string) ($emp['turno_codigo'] ?? ''));

        // Sin datos útiles para el panel: no cuenta como match productivo.
        if ($doc === '' && $puesto === '' && $contrato === '' && $turno === '' && $turnoHorario === '' && $turnoCodigo === '') {
            continue;
        }

        foreach ($matches as $matchUser) {
            $id = (int) ($matchUser['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $user = $usersById[$id] ?? $matchUser;

            $isFirst = ! isset($seenUserIds[$id]);
            if ($isFirst) {
                $seenUserIds[$id] = true;
                $matched++;
                if ($matchVia === 'dni') {
                    $byDni++;
                } elseif ($matchVia === 'email') {
                    $byEmail++;
                }
            }

            // Buk.pe es fuente de verdad para puesto/contrato/DNI; turno solo rellena vacío.
            $needs =
                ($doc !== '' && grooflow_buk_normalize_dni((string) ($user['identificacion'] ?? '')) !== $doc)
                || ($puesto !== '' && trim((string) ($user['puesto'] ?? '')) !== $puesto)
                || ($contrato !== '' && trim((string) ($user['contrato'] ?? '')) !== $contrato)
                || ($turno !== '' && trim((string) ($user['turno'] ?? '')) === '')
                || ($turnoHorario !== '' && trim((string) ($user['turno_horario'] ?? '')) === '')
                || ($turnoCodigo !== '' && trim((string) ($user['turno_codigo'] ?? '')) === '')
                || ($doc !== '' && grooflow_buk_normalize_dni((string) ($user['buk_dni'] ?? '')) !== $doc);

            if (! $needs) {
                continue;
            }

            // Puesto/contrato/DNI: escribir valor Buk.pe. Turno: COALESCE (no pisar).
            $updPuesto = $puesto !== '' ? $puesto : '';
            $updContrato = $contrato !== '' ? $contrato : '';
            $updTurno = trim((string) ($user['turno'] ?? '')) === '' ? $turno : '';
            $updTurnoHorario = trim((string) ($user['turno_horario'] ?? '')) === '' ? $turnoHorario : '';
            $updTurnoCodigo = trim((string) ($user['turno_codigo'] ?? '')) === '' ? $turnoCodigo : '';

            $upd->execute([
                $doc,
                $updPuesto,
                $updContrato,
                $updTurno,
                $updTurnoHorario,
                $updTurnoCodigo,
                $doc,
                $syncedAt,
                $id,
            ]);

            if ($doc !== '') {
                $user['identificacion'] = $doc;
                $user['buk_dni'] = $doc;
            }
            if ($updPuesto !== '') {
                $user['puesto'] = $updPuesto;
            }
            if ($updContrato !== '') {
                $user['contrato'] = $updContrato;
            }
            if ($updTurno !== '') {
                $user['turno'] = $updTurno;
            }
            if ($updTurnoHorario !== '') {
                $user['turno_horario'] = $updTurnoHorario;
            }
            if ($updTurnoCodigo !== '') {
                $user['turno_codigo'] = $updTurnoCodigo;
            }
            $usersById[$id] = $user;

            if ($upd->rowCount() > 0 || $needs) {
                $updated++;
            }
        }
    }

    return [
        'matched' => $matched,
        'updated' => $updated,
        'empleados' => count($empleados),
        'skipped' => $skipped,
        'by_dni' => $byDni,
        'by_email' => $byEmail,
    ];
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
function grooflow_buk_fetch_asistencia_today(string $v2Base, string $apiToken, int $maxPages = 40): array
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

    $errors = [];
    $bySource = ['empleados' => 0, 'turnos' => 0];
    $syncedAt = date('Y-m-d H:i:s');

    // Fuente principal: maestro Buk.pe en grooflow_buk_empleados.
    $fromEmpleados = grooflow_buk_sync_usuarios_from_empleados($pdo, $syncedAt);
    $bySource['empleados'] = (int) ($fromEmpleados['empleados'] ?? 0);
    $matched = (int) ($fromEmpleados['matched'] ?? 0);
    $updated = (int) ($fromEmpleados['updated'] ?? 0);
    $skipped = (int) ($fromEmpleados['skipped'] ?? 0);

    // Complemento opcional: rellenar turnos vacíos desde Ctrlit (Asistencia).
    $turnosFilled = 0;
    $includeTurnos = ($options['includeTurnos'] ?? true) !== false;
    if ($includeTurnos) {
        try {
            $asistSettings = grooflow_asistencia_get_settings($pdo);
            $buk = is_array($asistSettings['buk'] ?? null) ? $asistSettings['buk'] : [];
            $asistToken = grooflow_normalize_buk_token((string) ($options['asistenciaApiToken'] ?? $buk['apiToken'] ?? ''));
            $asistBase = grooflow_sanitize_buk_base_url((string) ($options['asistenciaBaseUrl'] ?? $buk['apiBaseUrl'] ?? ''));
            if ($asistToken !== '' && ! grooflow_buk_token_is_redacted($asistToken)) {
                $apiRoot = grooflow_buk_api_root_from_base($asistBase);
                $turnos = grooflow_buk_fetch_turnos_all($apiRoot, $asistToken);
                $bySource['turnos'] = count($turnos);
                $turnosFilled = grooflow_buk_fill_empty_turnos_from_ctrlit($pdo, $turnos, $syncedAt);
                $updated += $turnosFilled;
            }
        } catch (Throwable $e) {
            $errors[] = 'Turnos Ctrlit: ' . $e->getMessage();
        }
    }

    if ($bySource['empleados'] === 0 && $matched === 0) {
        throw new RuntimeException(
            'No hay colaboradores Buk.pe en BD. Sincroniza RRHH (colaboradores) primero'
            . ($errors !== [] ? ' (' . implode('; ', $errors) . ')' : '')
        );
    }

    $usersCount = (int) $pdo->query('SELECT COUNT(*) FROM app_usuarios WHERE is_deleted = 0')->fetchColumn();

    $message = sprintf(
        'Actualizados %d usuario(s); coincidencias %d. Empleados Buk.pe %d (DNI %d · email %d)%s.',
        $updated,
        $matched,
        $bySource['empleados'],
        (int) ($fromEmpleados['by_dni'] ?? 0),
        (int) ($fromEmpleados['by_email'] ?? 0),
        $bySource['turnos'] > 0
            ? sprintf('; turnos Ctrlit %d (rellenos %d)', $bySource['turnos'], $turnosFilled)
            : ''
    );

    // Persistir meta en settings:system.bukPe (fuente canónica).
    $system = grooflow_kv_get($pdo, 'settings:system');
    $system = is_array($system) ? $system : [];
    $bukPe = is_array($system['bukPe'] ?? null) ? $system['bukPe'] : [];
    $bukPe['lastStaffSyncAt'] = date('c');
    $bukPe['lastStaffSyncOk'] = true;
    $bukPe['lastStaffSyncMessage'] = $message;
    if (! isset($bukPe['staffSyncIntervalMinutes']) || (int) $bukPe['staffSyncIntervalMinutes'] <= 0) {
        $bukPe['staffSyncIntervalMinutes'] = 60;
    }
    if (! array_key_exists('staffSyncEnabled', $bukPe)) {
        $bukPe['staffSyncEnabled'] = true;
    }
    $system['bukPe'] = $bukPe;
    grooflow_kv_set($pdo, 'settings:system', $system);

    return [
        'matched' => $matched,
        'updated' => $updated,
        'skipped' => $skipped,
        'unmatched_buk' => (int) ($fromEmpleados['skipped'] ?? 0),
        'users_scanned' => $usersCount,
        'by_source' => $bySource,
        'from_empleados' => $fromEmpleados,
        'errors' => $errors,
        'duration_ms' => (int) round(microtime(true) * 1000) - $started,
        'synced_at' => $syncedAt,
        'message' => $message,
    ];
}

/**
 * Rellena turno vacío en app_usuarios desde asignación de turnos Ctrlit (por DNI).
 *
 * @param list<array<string, mixed>> $turnosRows
 */
function grooflow_buk_fill_empty_turnos_from_ctrlit(PDO $pdo, array $turnosRows, string $syncedAt): int
{
    if ($turnosRows === []) {
        return 0;
    }
    $byDni = grooflow_buk_index_turnos_by_dni($turnosRows);
    if ($byDni === []) {
        return 0;
    }

    $users = $pdo->query("
        SELECT id, identificacion, buk_dni, turno, turno_horario, turno_codigo
        FROM app_usuarios
        WHERE is_deleted = 0
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $upd = $pdo->prepare('
        UPDATE app_usuarios SET
            turno = COALESCE(NULLIF(?, ""), turno),
            turno_horario = COALESCE(NULLIF(?, ""), turno_horario),
            turno_codigo = COALESCE(NULLIF(?, ""), turno_codigo),
            buk_synced_at = ?
        WHERE id = ?
    ');

    $filled = 0;
    foreach ($users as $user) {
        $id = (int) ($user['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $dni = grooflow_buk_normalize_dni((string) ($user['buk_dni'] ?? ''));
        if ($dni === '') {
            $dni = grooflow_buk_normalize_dni((string) ($user['identificacion'] ?? ''));
        }
        if ($dni === '' || ! isset($byDni[$dni])) {
            continue;
        }
        $staff = $byDni[$dni];
        $needsTurno = trim((string) ($user['turno'] ?? '')) === '' && trim((string) ($staff['turno'] ?? '')) !== '';
        $needsHorario = trim((string) ($user['turno_horario'] ?? '')) === '' && trim((string) ($staff['turno_horario'] ?? '')) !== '';
        $needsCodigo = trim((string) ($user['turno_codigo'] ?? '')) === '' && trim((string) ($staff['turno_codigo'] ?? '')) !== '';
        if (! $needsTurno && ! $needsHorario && ! $needsCodigo) {
            continue;
        }
        $upd->execute([
            $needsTurno ? (string) $staff['turno'] : '',
            $needsHorario ? (string) $staff['turno_horario'] : '',
            $needsCodigo ? (string) $staff['turno_codigo'] : '',
            $syncedAt,
            $id,
        ]);
        if ($upd->rowCount() > 0) {
            $filled++;
        }
    }

    return $filled;
}

/**
 * @param list<array<string, mixed>> $turnos
 * @return array<string, array{turno?:string,turno_horario?:string,turno_codigo?:string}>
 */
function grooflow_buk_index_turnos_by_dni(array $turnos): array
{
    $byDni = [];
    foreach ($turnos as $row) {
        if (! is_array($row)) {
            continue;
        }
        $dni = grooflow_buk_normalize_dni((string) ($row['dni'] ?? ''));
        if ($dni === '') {
            continue;
        }
        if (! isset($byDni[$dni])) {
            $byDni[$dni] = [];
        }
        $nombreTurno = trim((string) ($row['nombreTurno'] ?? ''));
        $horario = trim((string) ($row['horarioTurno'] ?? ''));
        $idTurno = trim((string) ($row['idTurno'] ?? ''));
        if ($nombreTurno !== '') {
            $byDni[$dni]['turno'] = $nombreTurno;
        }
        if ($horario !== '' && $horario !== '-') {
            $byDni[$dni]['turno_horario'] = $horario;
        }
        if ($idTurno !== '') {
            $byDni[$dni]['turno_codigo'] = $idTurno;
        }
    }

    return $byDni;
}

/**
 * Cron: ejecuta sync solo si pasó el intervalo configurable (default 60 min).
 * Preferencia: settings:system.bukPe; fallback legacy: settings:asistencia.buk.
 *
 * @return array{ran:bool,reason?:string,result?:array<string,mixed>}
 */
function grooflow_buk_sync_usuarios_if_due(PDO $pdo): array
{
    $system = grooflow_kv_get($pdo, 'settings:system');
    $system = is_array($system) ? $system : [];
    $bukPe = is_array($system['bukPe'] ?? null) ? $system['bukPe'] : [];

    $asistSettings = grooflow_asistencia_get_settings($pdo);
    $bukLegacy = is_array($asistSettings['buk'] ?? null) ? $asistSettings['buk'] : [];

    $enabled = array_key_exists('staffSyncEnabled', $bukPe)
        ? (($bukPe['staffSyncEnabled'] ?? true) !== false)
        : (($bukLegacy['staffSyncEnabled'] ?? true) !== false);

    if (! $enabled) {
        return ['ran' => false, 'reason' => 'staff_sync_disabled'];
    }

    // Requiere token Buk.pe O maestros ya en BD.
    $token = '';
    if (function_exists('grooflow_normalize_buk_pe_token')) {
        $token = grooflow_normalize_buk_pe_token((string) ($bukPe['apiToken'] ?? ''));
    } else {
        $token = trim((string) ($bukPe['apiToken'] ?? ''));
    }
    $hasEmpleados = table_exists($pdo, 'grooflow_buk_empleados')
        && (int) $pdo->query('SELECT COUNT(*) FROM grooflow_buk_empleados WHERE missing_from_source = 0')->fetchColumn() > 0;
    if (($token === '' || grooflow_buk_token_is_redacted($token)) && ! $hasEmpleados) {
        return ['ran' => false, 'reason' => 'missing_buk_pe_or_empleados'];
    }

    $interval = (int) ($bukPe['staffSyncIntervalMinutes'] ?? $bukLegacy['staffSyncIntervalMinutes'] ?? 60);
    $interval = max(15, min(24 * 60, $interval > 0 ? $interval : 60));
    $lastAt = trim((string) ($bukPe['lastStaffSyncAt'] ?? $bukLegacy['lastStaffSyncAt'] ?? ''));
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
        $bukPe['lastStaffSyncAt'] = date('c');
        $bukPe['lastStaffSyncOk'] = false;
        $bukPe['lastStaffSyncMessage'] = $e->getMessage();
        $system['bukPe'] = $bukPe;
        try {
            grooflow_kv_set($pdo, 'settings:system', $system);
        } catch (Throwable $ignored) {
        }

        return ['ran' => true, 'result' => ['ok' => false, 'error' => $e->getMessage()]];
    }
}
