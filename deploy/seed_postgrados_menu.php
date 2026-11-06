<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

$config = dirname(__DIR__, 2) . '/config.php';
$menu = dirname(__DIR__) . '/lib/grooflow_menu.php';
echo "config={$config} exists=" . (is_file($config) ? '1' : '0') . PHP_EOL;
echo "menu={$menu} exists=" . (is_file($menu) ? '1' : '0') . PHP_EOL;

require_once $config;
require_once dirname(__DIR__, 2) . '/backend/lib/dashboard_helpers.php';
require_once $menu;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "pdo missing\n");
    exit(2);
}

grooflow_menu_ensure_seed($pdo);

$stmt = $pdo->query(
    "SELECT id, texto, ruta, modulo_key, estado FROM grooflow_menu_opciones
     WHERE modulo_key = 'Postgrados' OR ruta = '/postgrados'"
);
$rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
echo 'postgrados_menu_rows=' . count($rows) . PHP_EOL;
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
