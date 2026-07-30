<?php
namespace SeoAuditor\Checks;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class TechnicalCheck extends BaseCheck
{
    private Client $client;
    private Client $noRedirectClient;

    public function __construct()
    {
        $this->client = new Client([
            'timeout'         => 10,
            'verify'          => false,
            'allow_redirects' => ['max' => 5],
        ]);
        $this->noRedirectClient = new Client([
            'timeout'         => 10,
            'verify'          => false,
            'allow_redirects' => false,
            'http_errors'     => false,
        ]);
    }

    public function run(array $pages, array &$siteData): array
    {
        if (empty($pages)) return [];
        $firstPage = $pages[0];
        $url       = $firstPage['url'] ?? '';
        $headers   = $firstPage['headers'] ?? [];
        $base      = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
        $ip        = $siteData['ip'] ?? gethostbyname(parse_url($url, PHP_URL_HOST) ?? '');

        $this->checkHttps($base, $url);
        $this->checkSslCert($base, $url);
        $this->checkWww($base);
        $this->checkRedirects($base);
        $this->check404($base);
        $this->checkCompression($headers, $url);
        $this->checkCacheHeaders($headers, $url);
        $this->checkHttp2($url);
        $this->checkBrokenLinks($pages);
        $this->checkDnsbl($ip, $url);
        $this->checkMixedContent($pages);
        $this->checkIframes($pages);
        $this->checkCanonicalDeep($pages);

        return $this->issues;
    }

    private function checkHttps(string $base, string $url): void
    {
        if (!str_starts_with($url, 'https://')) {
            $this->critical('technical', 'Сайт не использует HTTPS',
                "Сайт работает на HTTP без шифрования.",
                "Установите SSL-сертификат и настройте принудительный редирект HTTP → HTTPS.",
                $url
            );
            return;
        }

        $httpUrl = str_replace('https://', 'http://', $base);
        try {
            $resp = $this->noRedirectClient->get($httpUrl);
            $code = $resp->getStatusCode();
            if ($code >= 200 && $code < 300) {
                $this->warning('technical', 'HTTP не редиректит на HTTPS',
                    "Сайт доступен по HTTP без редиректа ($httpUrl → $code).",
                    "Настройте 301-редирект с http:// на https:// в Nginx.",
                    $httpUrl
                );
            } else {
                $this->info('technical', 'HTTPS настроен корректно',
                    "HTTP редиректит на HTTPS (код $code).",
                    "HTTPS работает правильно.",
                    $url
                );
            }
        } catch (\Exception $e) {
            $this->info('technical', 'HTTPS настроен корректно',
                "HTTP-версия недоступна, сайт работает только по HTTPS.",
                "HTTPS работает правильно.",
                $url
            );
        }
    }

    private function checkSslCert(string $base, string $url): void
    {
        if (!str_starts_with($base, 'https://')) return;
        $host = parse_url($base, PHP_URL_HOST);
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer'       => false,
            'verify_peer_name'  => false,
        ]]);
        $conn = @stream_socket_client("ssl://$host:443", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
        if (!$conn) return;
        $params = stream_context_get_params($conn);
        $cert   = $params['options']['ssl']['peer_certificate'] ?? null;
        fclose($conn);
        if (!$cert) return;

        $info     = openssl_x509_parse($cert);
        $validTo  = $info['validTo_time_t'] ?? 0;
        $daysLeft = (int)(($validTo - time()) / 86400);
        $expiryDate = date('d.m.Y', $validTo);

        if ($daysLeft < 0) {
            $this->critical('technical', 'SSL-сертификат истёк',
                "Сертификат истёк $expiryDate. Браузеры блокируют доступ к сайту.",
                "Немедленно обновите SSL-сертификат: sudo certbot renew",
                $url
            );
        } elseif ($daysLeft < 30) {
            $this->warning('technical', "SSL истекает через $daysLeft дней ($expiryDate)",
                "Срок действия сертификата заканчивается. После истечения сайт станет недоступен.",
                "Обновите сертификат: sudo certbot renew",
                $url
            );
        } else {
            $this->info('technical', "SSL действителен до $expiryDate ($daysLeft дней)",
                "SSL-сертификат в порядке.",
                "Следите за сроком действия и настройте авто-обновление (certbot).",
                $url
            );
        }

        // Самоподписанный сертификат
        $issuer  = $info['issuer'] ?? [];
        $subject = $info['subject'] ?? [];
        if (empty($issuer['O']) || (($issuer['CN'] ?? '') === ($subject['CN'] ?? ''))) {
            $this->critical('technical', 'Самоподписанный SSL-сертификат',
                "Сертификат выдан самим сервером. Браузеры показывают предупреждение безопасности.",
                "Получите бесплатный сертификат Let's Encrypt: sudo certbot --nginx -d " . $host,
                $url
            );
        }
    }

    private function checkWww(string $base): void
    {
        $host   = parse_url($base, PHP_URL_HOST);
        $scheme = parse_url($base, PHP_URL_SCHEME);
        $altUrl = str_starts_with($host, 'www.')
            ? "$scheme://" . substr($host, 4)
            : "$scheme://www.$host";

        try {
            $resp = $this->noRedirectClient->get($altUrl);
            $code = $resp->getStatusCode();
            if ($code >= 200 && $code < 300) {
                $this->warning('technical', 'Нет канонизации www/не-www',
                    "Сайт доступен по обоим адресам: $base и $altUrl. Создаются дубли страниц.",
                    "Настройте 301-редирект на один вариант. Основной: $base.",
                    $altUrl
                );
            }
        } catch (\Exception $e) {}
    }

    private function checkRedirects(string $base): void
    {
        $host = parse_url($base, PHP_URL_HOST);
        $checkUrls = [
            str_replace('https://', 'http://', $base),
            str_starts_with($host, 'www.') ? 'https://' . substr($host, 4) : 'https://www.' . $host,
        ];

        foreach ($checkUrls as $checkUrl) {
            try {
                $r    = $this->noRedirectClient->get($checkUrl);
                $code = $r->getStatusCode();
                $loc  = $r->getHeaderLine('Location');
                if ($code >= 300 && $code < 400 && $loc) {
                    $type = $code === 301 ? 'постоянный' : 'временный';
                    $this->info('technical', "Редирект $code ({$type}): $checkUrl → $loc",
                        "Переадресация с $checkUrl на $loc (HTTP $code).",
                        $code === 302
                            ? "Замените 302 на 301 — временный редирект не передаёт SEO-вес страницы."
                            : "Редирект 301 настроен корректно.",
                        $checkUrl
                    );
                }
            } catch (\Exception $e) {}
        }
    }

    private function check404(string $base): void
    {
        $testUrl = "$base/seo-audit-test-404-" . mt_rand(100000, 999999);
        try {
            $resp = $this->noRedirectClient->get($testUrl);
            $code = $resp->getStatusCode();
            if ($code === 200) {
                $this->warning('technical', 'Мягкий 404 (Soft 404)',
                    "Несуществующие страницы возвращают HTTP 200 вместо 404.",
                    "Настройте корректный код ответа 404 для несуществующих страниц.",
                    $testUrl
                );
            } elseif ($code === 404) {
                $this->info('technical', 'Страница 404 настроена корректно',
                    "Несуществующие страницы возвращают HTTP 404.",
                    "Добавьте на страницу 404 ссылки на главные разделы сайта.",
                    $testUrl
                );
            }
        } catch (RequestException $e) {
            $code = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            if ($code === 404) {
                $this->info('technical', 'Страница 404 настроена корректно',
                    "Код ответа 404 для несуществующих страниц.",
                    "Добавьте на страницу 404 ссылки на главные разделы.",
                    $testUrl
                );
            }
        }
    }

    private function checkCompression(array $headers, string $url): void
    {
        $encoding = $headers['content-encoding'] ?? '';
        if ($encoding && (str_contains($encoding, 'gzip') || str_contains($encoding, 'br'))) {
            $this->info('technical', "Сжатие включено ($encoding)",
                "Сервер использует сжатие $encoding.",
                "Сжатие настроено правильно — экономия трафика до 70%.",
                $url
            );
        } else {
            $this->warning('technical', 'Сжатие gzip/brotli не включено',
                "Сервер не сжимает HTML/CSS/JS перед отправкой. Это замедляет загрузку.",
                "Включите gzip в Nginx: `gzip on; gzip_types text/plain text/css application/javascript;`",
                $url
            );
        }
    }

    private function checkCacheHeaders(array $headers, string $url): void
    {
        $cc      = $headers['cache-control'] ?? '';
        $etag    = $headers['etag'] ?? '';
        $expires = $headers['expires'] ?? '';

        if (empty($cc) && empty($etag) && empty($expires)) {
            $this->warning('technical', 'Заголовки кэширования не настроены',
                "Сервер не отправляет Cache-Control, ETag или Expires.",
                "Настройте кэширование статических ресурсов для ускорения повторных загрузок.",
                $url
            );
        } else {
            $this->info('technical', 'Кэширование настроено',
                "Cache-Control: $cc" . ($etag ? ", ETag присутствует" : ''),
                "Убедитесь что TTL кэша достаточен для статики (CSS, JS, изображений).",
                $url
            );
        }
    }

    private function checkHttp2(string $url): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_NOBODY         => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
            CURLOPT_TIMEOUT        => 5,
        ]);
        curl_exec($ch);
        $version = curl_getinfo($ch, CURLINFO_HTTP_VERSION);
        curl_close($ch);

        if ($version >= 3) {
            $this->info('technical', 'HTTP/2 поддерживается',
                "Сервер использует HTTP/2.",
                "HTTP/2 ускоряет загрузку за счёт мультиплексирования.",
                $url
            );
        } else {
            $this->warning('technical', 'HTTP/2 не обнаружен',
                "Сервер использует HTTP/1.1. HTTP/2 значительно быстрее.",
                "Включите HTTP/2 в Nginx: добавьте `http2` в директиву listen.",
                $url
            );
        }
    }

    private function checkBrokenLinks(array $pages): void
    {
        foreach ($pages as $page) {
            if (($page['status_code'] ?? 0) >= 400) {
                $this->warning('technical', "Битая ссылка (HTTP {$page['status_code']})",
                    "Страница недоступна: {$page['url']}",
                    "Исправьте или удалите ссылки на несуществующие страницы.",
                    $page['url']
                );
            }
        }
    }

    private function checkDnsbl(string $ip, string $url): void
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return;
        $reversed = implode('.', array_reverse(explode('.', $ip)));
        $lists = [
            'zen.spamhaus.org'       => 'Spamhaus',
            'bl.spamcop.net'         => 'SpamCop',
            'b.barracudacentral.org' => 'Barracuda',
        ];
        $blacklisted = [];
        foreach ($lists as $list => $name) {
            if (@checkdnsrr("$reversed.$list", 'A')) {
                $blacklisted[] = $name;
            }
        }
        if (!empty($blacklisted)) {
            $this->critical('technical', 'IP найден в чёрных списках DNSBL',
                "IP $ip в списках: " . implode(', ', $blacklisted) . ". Ухудшает доставку email и может влиять на репутацию домена.",
                "Обратитесь к хостинг-провайдеру или запросите делистинг на сайтах DNSBL.",
                $url
            );
        } else {
            $this->info('technical', 'IP не в чёрных списках DNSBL',
                "IP $ip проверен в Spamhaus, SpamCop, Barracuda — не найден.",
                "Регулярно проверяйте IP на наличие в спам-листах.",
                $url
            );
        }
    }

    private function checkMixedContent(array $pages): void
    {
        $mixedPages = [];
        foreach ($pages as $page) {
            if (!str_starts_with($page['url'] ?? '', 'https://')) continue;
            $html = $page['html'] ?? '';
            if (empty($html)) continue;
            if (preg_match('/(?:src|href|action)=["\']http:\/\//i', $html)) {
                $mixedPages[] = $page['url'];
            }
        }
        if (!empty($mixedPages)) {
            $count = count($mixedPages);
            $this->warning('technical', "Смешанный контент: $count страниц",
                "Найдены HTTP-ресурсы на HTTPS-страницах. Браузеры блокируют такой контент. Примеры: " . implode(', ', array_slice($mixedPages, 0, 3)),
                "Замените все http:// ссылки на ресурсы на https://.",
                $mixedPages[0]
            );
        } else {
            $this->info('technical', 'Смешанный контент не обнаружен',
                "Все внешние ресурсы загружаются по HTTPS.",
                "Продолжайте использовать HTTPS для всех ресурсов.",
                ''
            );
        }
    }

    private function checkIframes(array $pages): void
    {
        $iframePages = [];
        foreach ($pages as $page) {
            $html = $page['html'] ?? '';
            if (empty($html)) continue;
            if (stripos($html, '<iframe') !== false || stripos($html, '<frame') !== false) {
                $iframePages[] = $page['url'];
            }
        }
        $count = count($iframePages);
        if ($count > 0) {
            $this->info('technical', "Страниц с тегом iframe/frame: $count",
                "Найдены iframe на страницах: " . implode(', ', array_slice($iframePages, 0, 5)),
                "Убедитесь что iframe используются только для легитимных целей (карты, видео). Добавьте атрибут sandbox.",
                $iframePages[0]
            );
        }
    }

    private function checkCanonicalDeep(array $pages): void
    {
        $multipleCanonical = [];
        $canonicalInBody   = [];

        foreach ($pages as $page) {
            $html = $page['html'] ?? '';
            $url  = $page['url'] ?? '';
            if (empty($html)) continue;

            preg_match('/<head[^>]*>(.*?)<\/head>/is', $html, $headMatch);
            $headHtml = $headMatch[1] ?? '';

            $totalCount = (int)preg_match_all('/<link[^>]+rel=["\']canonical["\'][^>]*>/i', $html);
            $headCount  = (int)preg_match_all('/<link[^>]+rel=["\']canonical["\'][^>]*>/i', $headHtml);
            $bodyCount  = $totalCount - $headCount;

            if ($totalCount > 1) $multipleCanonical[] = $url;
            if ($bodyCount > 0)  $canonicalInBody[] = $url;
        }

        if (!empty($multipleCanonical)) {
            $this->warning('technical', 'Несколько rel=canonical: ' . count($multipleCanonical) . ' страниц',
                "Страницы с несколькими canonical: " . implode(', ', array_slice($multipleCanonical, 0, 5)),
                "Оставьте ровно один rel=canonical на каждой странице. Google игнорирует canonical при наличии нескольких.",
                $multipleCanonical[0]
            );
        }

        if (!empty($canonicalInBody)) {
            $this->warning('technical', 'rel=canonical в <body>: ' . count($canonicalInBody) . ' страниц',
                "canonical должен быть только в <head>. Страницы: " . implode(', ', array_slice($canonicalInBody, 0, 5)),
                "Перенесите тег rel=canonical из <body> в секцию <head>.",
                $canonicalInBody[0]
            );
        }
    }
}
