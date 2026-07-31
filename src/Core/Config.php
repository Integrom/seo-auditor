<?php
namespace SeoAuditor\Core;

class Config
{
    private static array $data = [];

    public static function load(string $path): void
    {
        self::$data = require $path;

        // Часовой пояс задаём в приложении, а не в php.ini: сервер и MySQL
        // живут по московскому времени, а PHP по умолчанию в UTC — из-за
        // этого даты в отчётах расходились с реальными на три часа
        $tz = self::get('app.timezone');
        if (is_string($tz) && $tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            date_default_timezone_set($tz);
        }
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
