<?php
declare(strict_types=1);

namespace Sigep\Core;

/** Leitor simples de .env. Nada sensível fica no código: tudo vem do ambiente. */
final class Env
{
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $v = trim($v);
            if ($v !== '' && ($v[0] === '"' || $v[0] === "'") && preg_match('/^(["\'])(.*?)\1(?:\s+#.*)?$/', $v, $m)) {
                $v = $m[2];                          // valor entre aspas (pode conter #)
            } else {
                $v = trim(preg_replace('/\s+#.*$/', '', $v) ?? $v); // remove comentário na mesma linha
            }
            self::$vars[trim($k)] = $v;
        }
    }

    public static function set(string $key, string $value): void
    {
        self::$vars[$key] = $value;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$vars)) {
            return self::$vars[$key];
        }
        $env = getenv($key);
        return $env !== false ? $env : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null || $v === '' ? $default : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === null || $v === '' ? $default : (int) $v;
    }
}
