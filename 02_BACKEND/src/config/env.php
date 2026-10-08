<?php
// 02_BACKEND/src/config/env.php - Carga variables desde .env sin pisar las del servidor.

function sgs_load_env(string $file): void
{
    if (!is_file($file) || !is_readable($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) continue;
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[strlen($value) - 1] === $value[0]) {
            $value = substr($value, 1, -1);
        } else {
            $value = trim(preg_replace('/(^|\s+)#.*$/', '', $value)); // comentario al final de la línea (también si el valor está vacío)
        }
        if (getenv($key) === false) putenv("$key=$value");
    }
}
