<?php
namespace SeoAuditor\Core;

/**
 * Защита от SSRF: не даёт краулеру обращаться к внутренней инфраструктуре.
 *
 * Без такой проверки пользователь может указать http://localhost/,
 * http://192.168.1.1/ или адрес сервиса метаданных облака (169.254.169.254)
 * и приложение просканирует внутреннюю сеть от своего имени.
 */
class UrlGuard
{
    /** Схемы, по которым вообще имеет смысл ходить */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /** Порты веб-сервисов — остальные закрыты, чтобы нельзя было сканировать внутренние службы */
    private const ALLOWED_PORTS = [80, 443, 8080, 8443, 8000, 3000];

    /** Кэш проверенных хостов в рамках одного запуска: host => [bool, ?reason] */
    private static array $cache = [];

    /**
     * Проверяет URL. Возвращает null если всё в порядке,
     * либо строку с причиной отказа.
     */
    public static function validate(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return 'некорректный URL';
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return "схема «{$scheme}» не поддерживается, разрешены только http и https";
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, self::ALLOWED_PORTS, true)) {
            return "порт $port закрыт для проверки";
        }

        $host = strtolower($parts['host']);

        // Отсекаем служебные имена до обращения к DNS
        if (in_array($host, ['localhost', 'localhost.localdomain', '0.0.0.0', 'metadata.google.internal'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            return 'обращение к локальным адресам запрещено';
        }

        if (isset(self::$cache[$host])) {
            return self::$cache[$host];
        }

        $reason = self::validateHost($host);
        self::$cache[$host] = $reason;
        return $reason;
    }

    public static function isAllowed(string $url): bool
    {
        return self::validate($url) === null;
    }

    /** Бросает исключение, если URL запрещён — для использования внутри краулера */
    public static function assert(string $url): void
    {
        $reason = self::validate($url);
        if ($reason !== null) {
            throw new \RuntimeException("Адрес недоступен для аудита: $reason");
        }
    }

    private static function validateHost(string $host): ?string
    {
        // Хост задан IP-адресом напрямую
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host) ? null : 'адрес указывает на внутреннюю сеть';
        }

        $ips = self::resolve($host);
        if (empty($ips)) {
            return 'домен не разрешается в IP-адрес';
        }

        // Достаточно одного «внутреннего» адреса, чтобы отказать:
        // иначе через DNS с несколькими A-записями можно попасть внутрь
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return 'домен указывает на внутреннюю сеть';
            }
        }

        return null;
    }

    /** @return string[] все IPv4 и IPv6 адреса хоста */
    private static function resolve(string $host): array
    {
        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) $ips = $v4;

        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $rec) {
                if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Публичный ли адрес. Приватные и зарезервированные диапазоны отсекает
     * сам filter_var: 10/8, 172.16/12, 192.168/16, 127/8, 169.254/16
     * (включая сервис метаданных облаков), 0/8, ::1, fc00::/7 и прочие.
     */
    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /** Сброс кэша — нужен в тестах */
    public static function resetCache(): void
    {
        self::$cache = [];
    }
}
