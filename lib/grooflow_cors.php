<?php

declare(strict_types=1);

/**
 * Orígenes permitidos para el SPA (Hostinger, Vite local y previews Vercel).
 *
 * @return list<string>
 */
function grooflow_cors_allowed_origins(): array
{
    $fromEnv = array_filter(array_map('trim', explode(',', env_value('API_CORS_ORIGINS', '') ?? '')));
    $defaults = [
        'http://localhost:4200',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:4173',
        'http://127.0.0.1:4173',
        'https://gestionveterinariagroomers.com',
        'https://www.gestionveterinariagroomers.com',
        'http://gestionveterinariagroomers.com',
        'http://www.gestionveterinariagroomers.com',
        'https://grooflow.vercel.app',
        'https://*.vercel.app',
    ];

    return array_values(array_unique(array_merge($defaults, $fromEnv)));
}

function grooflow_cors_origin_is_allowed(string $origin, ?array $allowed = null): bool
{
    $origin = trim($origin);
    if ($origin === '') {
        return false;
    }

    $allowed ??= grooflow_cors_allowed_origins();
    foreach ($allowed as $pattern) {
        $pattern = trim((string) $pattern);
        if ($pattern === '') {
            continue;
        }
        if ($pattern === $origin) {
            return true;
        }
        if (! str_contains($pattern, '*')) {
            continue;
        }
        $regex = '#^' . str_replace('\\*', '[A-Za-z0-9.-]+', preg_quote($pattern, '#')) . '$#i';
        if (preg_match($regex, $origin) === 1) {
            return true;
        }
    }

    return false;
}

function grooflow_cors_headers(): void
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));

    if ($origin !== '' && grooflow_cors_origin_is_allowed($origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    } elseif ($origin === '') {
        header('Access-Control-Allow-Origin: *');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Groomers-Client');
    header('Access-Control-Max-Age: 86400');
    header('Vary: Origin');
}
