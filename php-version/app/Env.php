<?php

function bot_env_load(string $dir): void
{
    $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '.env';
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        if (!array_key_exists($key, $_ENV)) $_ENV[$key] = $value;
        if (!array_key_exists($key, $_SERVER)) $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }
}

function bot_env(string $key, $default = null)
{
    if (array_key_exists($key, $_ENV)) return $_ENV[$key];
    if (array_key_exists($key, $_SERVER)) return $_SERVER[$key];
    $v = getenv($key);
    if ($v !== false) return $v;
    return $default;
}
