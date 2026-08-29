<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/grooflow_cors.php';

if (! function_exists('env_value')) {
    function env_value(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }

        return $value;
    }
}

$failed = 0;
$passed = 0;

function assert_true(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) {
        echo "[OK] $msg\n";
        $passed++;
    } else {
        echo "[FAIL] $msg\n";
        $failed++;
    }
}

$patterns = ['https://*.vercel.app', 'https://gestionveterinariagroomers.com'];

assert_true(
    grooflow_cors_origin_is_allowed('https://grooflow.vercel.app', $patterns),
    'permite grooflow.vercel.app'
);
assert_true(
    grooflow_cors_origin_is_allowed('https://grooflow-git-lbarco-luis-barco-projects.vercel.app', $patterns),
    'permite preview Vercel'
);
assert_true(
    grooflow_cors_origin_is_allowed('https://gestionveterinariagroomers.com', $patterns),
    'permite Hostinger'
);
assert_true(
    ! grooflow_cors_origin_is_allowed('https://evil.example.com', $patterns),
    'rechaza origen ajeno'
);
assert_true(
    ! grooflow_cors_origin_is_allowed('https://vercel.app.evil.com', $patterns),
    'rechaza spoof de vercel.app'
);

echo "\nResultado: $passed ok, $failed fallos\n";
exit($failed > 0 ? 1 : 0);
