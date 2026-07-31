<?php
namespace SeoAuditor\Core;

/**
 * Работа с адресами: нормализация и разрешение относительных ссылок.
 * Чистые функции без обращений к сети — вынесены отдельно ради тестируемости.
 */
class UrlTools
{
    /** Расширения, за которыми заведомо не HTML-страница */
    private const NON_HTML = [
        'xml','pdf','jpg','jpeg','png','gif','webp','svg','ico','bmp','tiff','avif',
        'zip','rar','tar','gz','7z','doc','docx','xls','xlsx','ppt','pptx','rtf',
        'mp3','mp4','avi','mov','wmv','flv','webm','wav','ogg',
        'css','js','json','txt','csv','woff','woff2','ttf','eot','otf','map',
        'apk','exe','dmg','iso',
    ];

    /** Схемы ссылок, по которым ходить не нужно */
    private const SKIP_PREFIXES = ['mailto:', 'tel:', 'javascript:', 'data:', 'sms:', 'callto:', 'viber:', 'whatsapp:'];

    /**
     * Приводит адрес к каноническому виду: убирает якорь, лишний слэш в конце,
     * приводит хост к нижнему регистру и отбрасывает порт по умолчанию.
     */
    public static function normalize(string $url): string
    {
        $url = trim($url);
        $url = strtok($url, '#');
        if ($url === false || $url === '') return '';

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return rtrim($url, '/');
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host   = strtolower($parts['host']);

        $port = '';
        if (!empty($parts['port'])
            && !($scheme === 'http' && $parts['port'] === 80)
            && !($scheme === 'https' && $parts['port'] === 443)) {
            $port = ':' . $parts['port'];
        }

        $path  = $parts['path'] ?? '';
        $path  = rtrim($path, '/');
        $query = !empty($parts['query']) ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $port . $path . $query;
    }

    /** Похоже ли, что по адресу отдаётся HTML-страница */
    public static function isHtmlUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '') return true;
        return !in_array($ext, self::NON_HTML, true);
    }

    /**
     * Превращает значение href в абсолютный адрес относительно страницы.
     *
     * Поддерживает абсолютные ссылки, протокол-относительные (//host/path),
     * корневые (/path) и обычные относительные (page.html, ../up, ./here) —
     * последние раньше терялись, из-за чего сайты с относительной навигацией
     * обходились не полностью.
     *
     * @param string $href    значение атрибута href
     * @param string $pageUrl абсолютный адрес страницы, на которой найдена ссылка
     */
    public static function resolve(string $href, string $pageUrl): ?string
    {
        $href = trim($href);
        if ($href === '' || $href === '#') return null;

        $lower = strtolower($href);
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) return null;
        }

        // Только якорь — это та же страница
        if (str_starts_with($href, '#')) return null;

        if (preg_match('#^https?://#i', $href)) {
            return self::normalize($href);
        }

        $base = parse_url($pageUrl);
        if ($base === false || empty($base['host'])) return null;
        $scheme = strtolower($base['scheme'] ?? 'https');
        $host   = strtolower($base['host']);
        $port   = !empty($base['port']) ? ':' . $base['port'] : '';
        $origin = $scheme . '://' . $host . $port;

        // Протокол-относительная ссылка: //cdn.example.com/page
        if (str_starts_with($href, '//')) {
            return self::normalize($scheme . ':' . $href);
        }

        // Ссылка от корня сайта
        if (str_starts_with($href, '/')) {
            return self::normalize($origin . self::resolvePath($href));
        }

        // Обычная относительная ссылка — считаем от каталога текущей страницы
        $basePath = $base['path'] ?? '/';
        $dir = str_ends_with($basePath, '/') ? $basePath : (dirname($basePath) ?: '/');
        if ($dir !== '/' && !str_ends_with($dir, '/')) $dir .= '/';
        if ($dir === '\\' || $dir === '.') $dir = '/';

        return self::normalize($origin . self::resolvePath($dir . $href));
    }

    /** Схлопывает «.» и «..» в пути: /a/b/../c → /a/c */
    private static function resolvePath(string $path): string
    {
        // Отделяем строку запроса — точки в ней трогать нельзя
        $query = '';
        if (($pos = strpos($path, '?')) !== false) {
            $query = substr($path, $pos);
            $path  = substr($path, 0, $pos);
        }

        $segments = explode('/', $path);
        $result   = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') {
                array_pop($result);
                continue;
            }
            $result[] = $segment;
        }

        $resolved = '/' . implode('/', $result);
        // Сохраняем завершающий слэш каталога
        if (str_ends_with($path, '/') && $resolved !== '/') {
            $resolved .= '/';
        }
        return $resolved . $query;
    }

    /**
     * Вытаскивает значения href из разметки.
     *
     * Намеренно без построения DOM: профилирование показало, что на разбор
     * документа уходило больше времени, чем на саму загрузку страниц.
     * Для поиска ссылок точность DOM не нужна — ложные срабатывания
     * всё равно отсеются проверками домена и расширения.
     *
     * @return string[] значения href в порядке появления, без повторов
     */
    public static function extractHrefs(string $html): array
    {
        if ($html === '') return [];

        // Ссылки внутри комментариев и <script> не считаем
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;

        preg_match_all('/<a\s[^>]*href\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $html, $m);

        $hrefs = [];
        foreach ($m[0] as $i => $_) {
            $value = $m[2][$i] !== '' ? $m[2][$i]
                   : ($m[3][$i] !== '' ? $m[3][$i] : $m[4][$i]);
            if ($value === '') continue;
            $hrefs[html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')] = true;
        }

        return array_keys($hrefs);
    }

    /** Один ли это домен (без учёта регистра) */
    public static function isSameHost(string $url, string $host): bool
    {
        $urlHost = parse_url($url, PHP_URL_HOST);
        return $urlHost !== null && strtolower($urlHost) === strtolower($host);
    }
}
