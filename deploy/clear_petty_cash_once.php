#!/usr/bin/env php
<?php
/**
 * One-shot: vaciar caja chica en producción (KV + tabla).
 * Ejecutar una vez por CLI y eliminar el archivo.
 */
declare(strict_types=1);

$roots = [
    dirname(__DIR__, 2),
    dirname(__DIR__, 3),
    '/home/u592431387/domains/gestionveterinariagroomers.com/public_html',
];
$config = null;
foreach ($roots as $root) {
    if (is_file($root . '/config.php')) {
        $config = $root . '/config.php';
        break;
    }
}
if ($config === null) {
    fwrite(STDERR, "config.php no encontrado\n");
    exit(1);
}
require_once $config;
require_once dirname(__DIR__) . '/lib/grooflow_kv.php';
require_once dirname(__DIR__) . '/lib/grooflow_schema.php';

/** @var PDO $pdo */
grooflow_ensure_schema($pdo);
$beforeTable = (int) $pdo->query('SELECT COUNT(*) FROM grooflow_caja_chica')->fetchColumn();
$metaEmpty = [
    'weekClosures' => [],
    'weekPreClosures' => [],
    'fundDeliveries' => [],
];
grooflow_kv_set($pdo, 'data:pettyCash', []);
grooflow_kv_set($pdo, 'data:pettyCashMeta', $metaEmpty);
$afterTable = (int) $pdo->query('SELECT COUNT(*) FROM grooflow_caja_chica')->fetchColumn();
echo "OK petty cash cleared. table before={$beforeTable} after={$afterTable}\n";
