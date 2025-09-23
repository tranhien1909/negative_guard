<?php
// Simple .env loader (không cần composer)
function env_load($file = __DIR__ . '/../.env')
{
    if (!file_exists($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if (!array_key_exists($k, $_SERVER) && !array_key_exists($k, $_ENV)) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
    }
}

env_load();


function envv($key, $default = null)
{
    $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return $val !== false && $val !== null ? $val : $default;
}


// Security headers (CSP)
function send_security_headers()
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: interest-cohort=()');
    $csp = envv('CSP');
    if ($csp) header('Content-Security-Policy: ' . $csp);
}
