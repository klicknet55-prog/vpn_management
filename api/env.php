<?php
/**
 * Env Loader — reads .env file one level above /api/
 * Call loadEnv() once before using env().
 */

function loadEnv(): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    // Look for .env two levels up from this file (api/env.php → root/.env)
    $file = dirname(__DIR__) . '/.env';
    if (!is_file($file)) return;

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // Skip comments and blank lines
        if ($line === '' || $line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Strip inline comments (value ending in  # comment)
        if (($pos = strpos($value, ' #')) !== false) {
            $value = rtrim(substr($value, 0, $pos));
        }

        if ($key === '') continue;
        // Only set if not already defined via putenv/environment
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }
}

function env(string $key, mixed $default = null): mixed
{
    loadEnv();
    $val = getenv($key);
    return ($val !== false) ? $val : $default;
}
