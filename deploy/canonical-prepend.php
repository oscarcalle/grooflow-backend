<?php

declare(strict_types=1);

/**
 * Auto-heal mínimo. Vive en public_html/.grooflow-canonical-backup/prepend.php
 * y se carga desde config.php (fuera del árbol que suelen sincronizar por SFTP).
 *
 * No depende de que public/index.php esté intacto.
 */

if (defined('GROOFLOW_DEPLOY_PREPEND_DONE')) {
    return;
}
define('GROOFLOW_DEPLOY_PREPEND_DONE', true);

try {
    $backupRoot = __DIR__;
    $publicHtml = dirname($backupRoot);
    $frontend = $publicHtml . '/grooflow';
    $backend = $publicHtml . '/grooflow-backend';
    $throttle = $backupRoot . '/last_check.txt';
    $fpFile = $backupRoot . '/.grooflow-canonical.json';
    if (!is_file($fpFile)) {
        $fpFile = $frontend . '/.grooflow-canonical.json';
    }
    if (!is_file($fpFile)) {
        return;
    }

    if (is_file($throttle)) {
        $last = (int) @file_get_contents($throttle);
        if ($last > 0 && (time() - $last) < 45) {
            return;
        }
    }
    @file_put_contents($throttle, (string) time());

    $fp = json_decode((string) @file_get_contents($fpFile), true);
    if (!is_array($fp)) {
        return;
    }
    $expected = (string) ($fp['indexAsset'] ?? '');
    $indexHtml = $frontend . '/index.html';
    $actual = '';
    if (is_file($indexHtml)) {
        $html = (string) @file_get_contents($indexHtml);
        if (preg_match('#assets/(index-[A-Za-z0-9_-]+\.js)#', $html, $m)) {
            $actual = $m[1];
        }
    }
    $menuFile = $backend . '/lib/grooflow_menu.php';
    $routerFile = $backend . '/public/index.php';
    $menuOk = is_file($menuFile) && str_contains((string) @file_get_contents($menuFile), 'Postgrados') && str_contains((string) @file_get_contents($menuFile), 'banco-firmas');
    $routerOk = is_file($routerFile) && str_contains((string) @file_get_contents($routerFile), '/postgrados') && str_contains((string) @file_get_contents($routerFile), '/firmas');
    $drifted = ($expected !== '' && $actual !== '' && $expected !== $actual) || !$menuOk || !$routerOk;
    if (!$drifted) {
        return;
    }

    $map = [
        'frontend/index.html' => $frontend . '/index.html',
        'backend/public/index.php' => $backend . '/public/index.php',
        'backend/lib/grooflow_menu.php' => $backend . '/lib/grooflow_menu.php',
        'backend/lib/grooflow_menu_permissions.php' => $backend . '/lib/grooflow_menu_permissions.php',
        'backend/lib/grooflow_schema.php' => $backend . '/lib/grooflow_schema.php',
        'backend/lib/grooflow_postgrados.php' => $backend . '/lib/grooflow_postgrados.php',
        'backend/lib/grooflow_extornos.php' => $backend . '/lib/grooflow_extornos.php',
        'backend/lib/grooflow_nomina_descuentos.php' => $backend . '/lib/grooflow_nomina_descuentos.php',
        'backend/lib/grooflow_firmas.php' => $backend . '/lib/grooflow_firmas.php',
        'backend/lib/grooflow_descuentos_clinica.php' => $backend . '/lib/grooflow_descuentos_clinica.php',
        'backend/lib/grooflow_deploy_guard.php' => $backend . '/lib/grooflow_deploy_guard.php',
    ];
    $restored = [];
    foreach ($map as $rel => $target) {
        $src = $backupRoot . '/' . $rel;
        if (!is_file($src)) {
            continue;
        }
        $bytes = @file_get_contents($src);
        if ($bytes === false) {
            continue;
        }
        $cur = is_file($target) ? @file_get_contents($target) : false;
        if ($cur !== false && hash('sha256', (string) $cur) === hash('sha256', $bytes)) {
            continue;
        }
        @chmod($target, 0644);
        if (@file_put_contents($target, $bytes) !== false) {
            @chmod($target, 0444);
            $restored[] = $rel;
        }
    }

    $issues = [];
    if ($expected !== '' && $actual !== '' && $expected !== $actual) {
        $issues[] = "Frontend pisado: remoto={$actual} canónico={$expected}";
    }
    if (!$menuOk) {
        $issues[] = 'menu.php sin Postgrados';
    }
    if (!$routerOk) {
        $issues[] = 'index.php sin /postgrados';
    }
    $line = sprintf(
        "[%s] PREPEND_HEAL expected=%s actual=%s restored=%s issues=%s\n",
        date('c'),
        $expected,
        $actual,
        implode(',', $restored),
        implode(' | ', $issues)
    );
    @file_put_contents($backupRoot . '/incidents.log', $line, FILE_APPEND);

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
    $subject = '[GrooFlow] ALERTA: sobrescritura detectada (auto-restauración)';
    $body = "Se detectó sobrescritura de GrooFlow en producción.\n\n"
        . "Esperado: {$expected}\nActual: {$actual}\n"
        . 'Restaurados: ' . implode(', ', $restored) . "\n"
        . 'Detalle: ' . implode(' | ', $issues) . "\n\n"
        . "Oscar/equipo: NO suban por SFTP/File Manager a /grooflow ni /grooflow-backend.\n"
        . 'Leer LEEME_OSCAR_NO_SOBRESCRIBIR.txt en esas carpetas.\n';
    $headers = "From: noreply@gestionveterinariagroomers.com\r\nContent-Type: text/plain; charset=UTF-8";
    foreach (array_unique($emails) as $to) {
        @mail($to, $subject, $body, $headers);
    }
} catch (Throwable $e) {
    error_log('[grooflow_canonical_prepend] ' . $e->getMessage());
}
