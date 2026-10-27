<?php
// Loads the project .env once and exposes env(). No secrets live in code.

if (!function_exists('env')) {
    function env($key, $default = null)
    {
        static $vars = null;
        if ($vars === null) {
            $vars = [];
            $file = dirname(__DIR__) . '/.env';
            if (is_readable($file)) {
                foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                        continue;
                    }
                    [$name, $value] = explode('=', $line, 2);
                    $value = trim($value);
                    if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
                        $value = substr($value, 1, -1);
                    }
                    $vars[trim($name)] = $value;
                }
            }
        }
        $real = getenv($key);
        if ($real !== false) {
            return $real;
        }
        return array_key_exists($key, $vars) ? $vars[$key] : $default;
    }
}
