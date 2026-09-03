<?php
/**
 * Minimaler .env-Loader – bewusst ohne Composer-Abhängigkeit, damit die
 * Anwendung auch per reinem FTP-Upload lauffähig bleibt.
 */

$GLOBALS['__env'] = $GLOBALS['__env'] ?? [];

function env_load(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        // Optionales 'export '-Praefix entfernen (haendisch geschriebene
        // .env-Dateien uebernehmen oft Shell-Konventionen).
        $key = preg_replace('/^export\s+/', '', $key);
        $val = trim(substr($line, $pos + 1));

        // Umschließende Anführungszeichen entfernen; alles darin bleibt
        // unangetastet – auch ein '#'.
        $len = strlen($val);
        if ($len >= 2
            && (($val[0] === '"' && $val[$len - 1] === '"')
             || ($val[0] === "'" && $val[$len - 1] === "'"))) {
            $val = substr($val, 1, -1);
        } else {
            // Ohne Quotes gilt '#' als Beginn eines Zeilenkommentars.
            $hash = strpos($val, ' #');
            if ($hash !== false) {
                $val = rtrim(substr($val, 0, $hash));
            }
        }

        $GLOBALS['__env'][$key] = $val;
    }
}

function env(string $key, ?string $default = null): ?string
{
    return $GLOBALS['__env'][$key] ?? $default;
}

function env_bool(string $key, bool $default = false): bool
{
    $v = $GLOBALS['__env'][$key] ?? null;
    if ($v === null) {
        return $default;
    }
    return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
}
