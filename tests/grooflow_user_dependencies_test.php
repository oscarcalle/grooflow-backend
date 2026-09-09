<?php

declare(strict_types=1);

// Run each entry point in a fresh process: index.php used to hide the missing
// dependencies by loading users first, while the scheduled RRHH job did not.
foreach (['grooflow_kv.php', 'grooflow_rrhh.php'] as $entry) {
    $path = dirname(__DIR__) . '/lib/' . $entry;
    $code = 'require ' . var_export($path, true) . ';'
        . 'foreach (["grooflow_list_users", "grooflow_list_roles",'
        . ' "grooflow_replace_users", "grooflow_replace_roles",'
        . ' "auth_user_sedes_assigned"] as $function) {'
        . 'if (!function_exists($function)) {fwrite(STDERR, $function . " missing\\n"); exit(1);}}';
    passthru(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code), $status);
    if ($status !== 0) {
        fwrite(STDERR, "[FAIL] $entry dependencies\n");
        exit(1);
    }
    echo "[OK] $entry loads user dependencies independently\n";
}
