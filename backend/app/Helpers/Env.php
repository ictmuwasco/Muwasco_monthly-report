<?php
/**
 * app/Helpers/Env.php — Minimal .env loader (no external dependencies).
 * Global helper functions, loaded via Composer "files" autoloading.
 * Loads key=value pairs from project .env into getenv()/$_ENV once.
 */

function env_load(string $path): void
{
    if (getenv('APP_ENV_LOADED')) return; // load only once
    if (!is_readable($path)) {
        // Fail loudly in dev, safely in prod is handled by init.php
        throw new \RuntimeException('.env file not found at ' . $path);
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value, " \t\"'");
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
    putenv('APP_ENV_LOADED=1');
}

function env_loaded(): bool
{
    return (bool) getenv('APP_ENV_LOADED');
}

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

