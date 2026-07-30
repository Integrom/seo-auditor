<?php
namespace SeoAuditor\Core;

/**
 * Минимальный загрузчик .env — без внешних зависимостей.
 * Значения кладутся в статический массив, $_ENV не засоряется.
 */
class Env
{
    private static array $data = [];
    private static bool  $loaded = false;

    public static function load(string $path): void
    {
        self::$loaded = true;
        if (!is_readable($path)) return;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (!str_contains($line, '=')) continue;

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Снимаем обрамляющие кавычки, если есть
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            self::$data[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            self::load(dirname(__DIR__, 2) . '/.env');
        }
        $value = self::$data[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') return $default;

        return match (strtolower((string)$value)) {
            'true'  => true,
            'false' => false,
            'null'  => null,
            default => $value,
        };
    }
}
