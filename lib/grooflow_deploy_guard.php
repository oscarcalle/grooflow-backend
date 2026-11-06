<?php

declare(strict_types=1);

/**
 * Detecta sobrescrituras del deploy canónico (p. ej. subidas SFTP ajenas),
 * intenta auto-restaurar archivos críticos y notifica por correo / auditoría.
 */

function grooflow_deploy_guard_paths(): array
{
    $backendRoot = dirname(__DIR__);
    $publicHtml = dirname($backendRoot);
    $frontend = $publicHtml . '/grooflow';
    $backup = $publicHtml . '/.grooflow-canonical-backup';

    return [
        'backend' => $backendRoot,
        'frontend' => $frontend,
        'backup' => $backup,
        'fingerprint' => $frontend . '/.grooflow-canonical.json',
        'notice_fe' => $frontend . '/LEEME_OSCAR_NO_SOBRESCRIBIR.txt',
        'notice_be' => $backendRoot . '/LEEME_OSCAR_NO_SOBRESCRIBIR.txt',
        'throttle' => $backup . '/last_check.txt',
        'incident_log' => $backup . '/incidents.log',
    ];
}

function grooflow_deploy_guard_read_fingerprint(): ?array
{
    $paths = grooflow_deploy_guard_paths();
    $file = $paths['fingerprint'];
    if (!is_file($file)) {
        // Fallback: backup copy
        $alt = $paths['backup'] . '/.grooflow-canonical.json';
        if (!is_file($alt)) {
            return null;
        }
        $file = $alt;
    }
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return null;
    }
    $json = json_decode($raw, true);

    return is_array($json) ? $json : null;
}

function grooflow_deploy_guard_current_index_asset(string $frontendDir): ?string
{
    $index = $frontendDir . '/index.html';
    if (!is_file($index)) {
        return null;
    }
    $html = (string) @file_get_contents($index);
    if (preg_match('#assets/(index-[A-Za-z0-9_-]+\.js)#', $html, $m)) {
        return $m[1];
    }

    return null;
}

/** @return list<string> */
function grooflow_deploy_guard_critical_relpaths(): array
{
    return [
        'frontend/index.html',
        'backend/public/index.php',
        'backend/lib/grooflow_menu.php',
        'backend/lib/grooflow_menu_permissions.php',
        'backend/lib/grooflow_schema.php',
        'backend/lib/grooflow_postgrados.php',
        'backend/lib/grooflow_descuentos_clinica.php',
        'backend/lib/grooflow_deploy_guard.php',
    ];
}

function grooflow_deploy_guard_abs(string $rel): ?string
{
    $paths = grooflow_deploy_guard_paths();
    if (str_starts_with($rel, 'frontend/')) {
        return $paths['frontend'] . '/' . substr($rel, strlen('frontend/'));
    }
    if (str_starts_with($rel, 'backend/')) {
        return $paths['backend'] . '/' . substr($rel, strlen('backend/'));
    }

    return null;
}

/**
 * @return array{ok:bool, drifted:bool, restored:list<string>, issues:list<string>, expected:?string, actual:?string}
 */
function grooflow_deploy_guard_check_and_heal(bool $force = false): array
{
    $paths = grooflow_deploy_guard_paths();
    $result = [
        'ok' => true,
        'drifted' => false,
        'restored' => [],
        'issues' => [],
        'expected' => null,
        'actual' => null,
    ];

    if (!$force && is_file($paths['throttle'])) {
        $last = (int) @file_get_contents($paths['throttle']);
        if ($last > 0 && (time() - $last) < 45) {
            $result['ok'] = true;
            $result['issues'][] = 'throttled';

            return $result;
        }
    }
    @mkdir($paths['backup'], 0755, true);
    @file_put_contents($paths['throttle'], (string) time());

    $fp = grooflow_deploy_guard_read_fingerprint();
    if ($fp === null) {
        $result['issues'][] = 'Sin fingerprint canónico.';

        return $result;
    }

    $expected = (string) ($fp['indexAsset'] ?? '');
    $actual = grooflow_deploy_guard_current_index_asset($paths['frontend']) ?? '';
    $result['expected'] = $expected !== '' ? $expected : null;
    $result['actual'] = $actual !== '' ? $actual : null;

    $menuOk = is_file($paths['backend'] . '/lib/grooflow_menu.php')
        && str_contains((string) @file_get_contents($paths['backend'] . '/lib/grooflow_menu.php'), 'Postgrados');
    $routerOk = is_file($paths['backend'] . '/public/index.php')
        && str_contains((string) @file_get_contents($paths['backend'] . '/public/index.php'), '/postgrados');

    $drifted = ($expected !== '' && $actual !== '' && $expected !== $actual) || !$menuOk || !$routerOk;
    $result['drifted'] = $drifted;

    if (!$drifted) {
        return $result;
    }

    $result['ok'] = false;
    if ($expected !== '' && $actual !== '' && $expected !== $actual) {
        $result['issues'][] = "Frontend pisado: remoto={$actual} canónico={$expected}";
    }
    if (!$menuOk) {
        $result['issues'][] = 'Backend menu.php sin Postgrados (posible restore antiguo).';
    }
    if (!$routerOk) {
        $result['issues'][] = 'Backend public/index.php sin ruta /postgrados.';
    }

    foreach (grooflow_deploy_guard_critical_relpaths() as $rel) {
        $backupFile = $paths['backup'] . '/' . $rel;
        $target = grooflow_deploy_guard_abs($rel);
        if ($target === null || !is_file($backupFile)) {
            continue;
        }
        $backupBytes = @file_get_contents($backupFile);
        $currentBytes = is_file($target) ? @file_get_contents($target) : false;
        if ($backupBytes === false) {
            continue;
        }
        if ($currentBytes !== false && hash('sha256', $currentBytes) === hash('sha256', $backupBytes)) {
            continue;
        }
        $dir = dirname($target);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // Intentar hacer escribible, restaurar y volver a solo lectura.
        @chmod($target, 0644);
        if (@file_put_contents($target, $backupBytes) !== false) {
            @chmod($target, 0444);
            $result['restored'][] = $rel;
        } else {
            $result['issues'][] = "No se pudo restaurar {$rel}";
        }
    }

    grooflow_deploy_guard_notify($fp, $result);

    return $result;
}

function grooflow_deploy_guard_notify(array $fp, array $result): void
{
    $paths = grooflow_deploy_guard_paths();
    $line = sprintf(
        "[%s] DRIFT expected=%s actual=%s restored=%s issues=%s\n",
        date('c'),
        (string) ($result['expected'] ?? ''),
        (string) ($result['actual'] ?? ''),
        implode(',', $result['restored'] ?? []),
        implode(' | ', $result['issues'] ?? [])
    );
    @file_put_contents($paths['incident_log'], $line, FILE_APPEND);

    $emails = [];
    foreach ((array) ($fp['alertEmails'] ?? []) as $email) {
        $email = trim((string) $email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }
    if ($emails === []) {
        return;
    }

    $subject = '[GrooFlow] ALERTA: alguien sobrescribió el sistema publicado';
    $body = "Hola,\n\n"
        . "Se detectó que los archivos publicados de GrooFlow fueron modificados/sobrescritos.\n\n"
        . "Asset esperado: " . ($result['expected'] ?? '(desconocido)') . "\n"
        . "Asset actual:    " . ($result['actual'] ?? '(desconocido)') . "\n"
        . "Restaurados:     " . implode(', ', $result['restored'] ?? []) . "\n"
        . "Detalle:         " . implode(' | ', $result['issues'] ?? []) . "\n\n"
        . "Oscar / equipo de desarrollo: NO suban por SFTP ni File Manager sobre /grooflow ni /grooflow-backend.\n"
        . "Esos directorios están protegidos. Coordinen con el administrador antes de cualquier deploy.\n\n"
        . "Archivo de aviso en el servidor: LEEME_OSCAR_NO_SOBRESCRIBIR.txt\n"
        . "Fecha UTC: " . gmdate('c') . "\n";

    $headers = 'From: noreply@gestionveterinariagroomers.com' . "\r\n"
        . 'Content-Type: text/plain; charset=UTF-8';

    foreach (array_unique($emails) as $to) {
        @mail($to, $subject, $body, $headers);
    }
}

/**
 * Hook liviano para el API. No rompe la request si falla.
 *
 * @return array<string,mixed>|null
 */
function grooflow_deploy_guard_boot(?PDO $pdo = null): ?array
{
    try {
        $result = grooflow_deploy_guard_check_and_heal(false);
        if ($pdo instanceof PDO && !empty($result['drifted'])) {
            try {
                if (function_exists('grooflow_audit_insert')) {
                    [$user] = function_exists('grooflow_postgrados_user_name')
                        ? [api_current_user() ?? []]
                        : [api_current_user() ?? []];
                    grooflow_audit_insert($pdo, is_array($user) ? $user : [], 'deploy.guard_drift', [
                        'expected' => $result['expected'],
                        'actual' => $result['actual'],
                        'restored' => $result['restored'],
                        'issues' => $result['issues'],
                    ]);
                }
            } catch (Throwable $e) {
                // ignore audit failures
            }
        }

        return $result;
    } catch (Throwable $e) {
        error_log('[grooflow_deploy_guard] ' . $e->getMessage());

        return null;
    }
}
