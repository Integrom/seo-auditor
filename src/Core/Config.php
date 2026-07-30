<?php
namespace SeoAuditor\Core;

class Config
{
    private static array $data = [];

    public static function load(string $path): void
    {
        self::$data = require $path;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = self::$data;
        foreach ($keys as $k) {
            if (!isset($value[$k])) return $default;
            $value = $value[$k];
        }
        return $value;
    }
}
