<?php
namespace SeoAuditor\Checks;

class YandexSeoCheck extends BaseCheck
{
    public function run(array $pages, array &$siteData): array
    {
        $this->issues = [];
        $homeUrl  = $pages[0]['url']  ?? '';
        $homeHtml = $pages[0]['html'] ?? '';

        $this->checkMetrika($homeHtml, $siteData);
        $this->checkWebmaster($homeHtml, $siteData);
        $this->checkFavicon($homeHtml, $homeUrl, $siteData);
        $this->checkRobotsTxtYandex($homeUrl, $siteData);
        $this->checkGeoMeta($homeHtml, $siteData);
        $this->checkSchemaOrg($pages, $siteData);
        $this->checkTurboPages($homeHtml, $siteData);
        $this->checkRetargeting($homeHtml, $siteData);

        return $this->issues;
    }

    private function checkMetrika(string $html, array &$siteData): void
    {
        $has = stripos($html, 'mc.yandex.ru/metrika') !== false
            || stripos($html, 'mc.yandex.com/metrika') !== false
            || (bool)preg_match('/\bym\s*\(/', $html)
            || stripos($html, 'yandex_metrika_callbacks') !== false;

        $siteData['yandex_metrika'] = $has;

        if (!$has) {
            $this->critical('yandex_seo', 'Яндекс.Метрика не установлена',
                'Счётчик Яндекс.Метрики не обнаружен на главной странице.',
                'Установите счётчик Яндекс.Метрики. Поведенческие факторы (время на сайте, отказы, глубина просмотра) существенно влияют на ранжирование. Без Метрики Яндекс не учитывает их для данного сайта.'
            );
        } else {
            $this->info('yandex_seo', 'Яндекс.Метрика установлена',
                'Счётчик Яндекс.Метрики обнаружен.',
                'Проверьте в интерфейсе Метрики: настроены ли цели, включён ли Вебвизор и карта кликов для анализа поведения пользователей.'
            );
        }
    }

    private function checkWebmaster(string $html, array &$siteData): void
    {
        $has = (bool)preg_match('/<meta[^>]+name=["\']yandex-verification["\'][^>]*>/i', $html);
        $siteData['yandex_webmaster_verified'] = $has;

        if (!$has) {
            $this->warning('yandex_seo', 'Яндекс.Вебмастер: подтверждение не найдено',
                'Мета-тег <meta name="yandex-verification"> не обнаружен.',
                'Подтвердите сайт в Яндекс.Вебмастере. Это открывает доступ к статистике индексации, ошибкам краулера и инструментам диагностики.'
            );
        } else {
            $this->info('yandex_seo', 'Яндекс.Вебмастер: сайт подтверждён',
                'Найден тег подтверждения Яндекс.Вебмастера.',
                'Регулярно проверяйте Яндекс.Вебмастер: статус индексации, ошибки и рекомендации.'
            );
        }
    }

    private function checkFavicon(string $html, string $url, array &$siteData): void
    {
        $has = (bool)preg_match('/<link[^>]+rel=["\'][^"\']*icon[^"\']*["\'][^>]*>/i', $html);

        if (!$has) {
            $base = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
            try {
                $client = new \GuzzleHttp\Client(['timeout' => 5, 'verify' => false]);
                $resp   = $client->head("$base/favicon.ico", ['http_errors' => false]);
                $has    = $resp->getStatusCode() === 200;
            } catch (\Exception $e) {}
        }

        $siteData['has_favicon'] = $has;

        if (!$has) {
            $this->warning('yandex_seo', 'Favicon не найден',
                'Иконка сайта не обнаружена ни в HTML, ни по адресу /favicon.ico.',
                'Добавьте favicon.ico (32×32) и тег <link rel="icon" href="/favicon.ico"> в <head>. Яндекс показывает favicon в сниппете поисковой выдачи — иконка увеличивает кликабельность.'
            );
        }
    }

    private function checkRobotsTxtYandex(string $url, array &$siteData): void
    {
        $base = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 5, 'verify' => false]);
            $resp   = $client->get("$base/robots.txt", ['http_errors' => false]);
            $body   = (string)$resp->getBody();

            $hasHost    = (bool)preg_match('/^Host:/m', $body);
            $hasSitemap = stripos($body, 'Sitemap:') !== false;

            $siteData['robots_yandex_host']    = $hasHost;
            $siteData['robots_has_sitemap_ref'] = $hasSitemap;

            if (!$hasHost) {
                $this->info('yandex_seo', 'robots.txt: директива Host отсутствует',
                    'Директива Host не найдена в robots.txt.',
                    'Добавьте "Host: ' . parse_url($url, PHP_URL_HOST) . '" в robots.txt. Яндекс использует её для определения главного зеркала сайта при наличии нескольких версий домена (www / без www, http / https).',
                    "$base/robots.txt"
                );
            }

            if (!$hasSitemap) {
                $this->info('yandex_seo', 'robots.txt: ссылка на Sitemap не указана',
                    'В robots.txt не найдена строка Sitemap:',
                    'Добавьте "Sitemap: ' . $base . '/sitemap.xml" в robots.txt для ускорения индексации страниц в Яндексе.',
                    "$base/robots.txt"
                );
            }
        } catch (\Exception $e) {}
    }

    private function checkGeoMeta(string $html, array &$siteData): void
    {
        $has = (bool)preg_match('/<meta[^>]+name=["\']geo\.region["\'][^>]*>/i', $html);
        $siteData['has_geo_meta'] = $has;

        if (!$has) {
            $this->info('yandex_seo', 'Geo-теги не найдены',
                'Мета-теги geo.region / geo.placename / geo.position отсутствуют.',
                'Для локального бизнеса добавьте: <meta name="geo.region" content="RU-MOW">, <meta name="geo.placename" content="Москва">, <meta name="geo.position" content="55.7512;37.6184">. Усиливают региональные сигналы для Яндекса.'
            );
        }
    }

    private function checkSchemaOrg(array $pages, array &$siteData): void
    {
        $html = $pages[0]['html'] ?? '';

        $hasOrg = stripos($html, '"LocalBusiness"') !== false
            || stripos($html, '"Organization"') !== false
            || (bool)preg_match('/itemtype=["\'][^"\']*(?:LocalBusiness|Organization)/i', $html);

        $hasBreadcrumb = stripos($html, '"BreadcrumbList"') !== false
            || (bool)preg_match('/itemtype=["\'][^"\']*BreadcrumbList/i', $html);

        $siteData['yandex_schema'] = ['org' => $hasOrg, 'breadcrumb' => $hasBreadcrumb];

        if (!$hasOrg) {
            $this->warning('yandex_seo', 'Schema.org: разметка Organization/LocalBusiness отсутствует',
                'Структурированные данные об организации не найдены на главной странице.',
                'Добавьте JSON-LD разметку Organization или LocalBusiness (название, адрес, телефон, часы работы). Яндекс использует её для расширенного сниппета и Яндекс.Справочника.'
            );
        }

        if (!$hasBreadcrumb) {
            $this->info('yandex_seo', 'Schema.org: BreadcrumbList не найден',
                'Разметка навигационных цепочек не обнаружена.',
                'Добавьте JSON-LD разметку BreadcrumbList для отображения пути к странице в сниппете Яндекса.'
            );
        }
    }

    private function checkTurboPages(string $html, array &$siteData): void
    {
        $has = (bool)preg_match('/<link[^>]+rel=["\']amphtml["\'][^>]*>/i', $html)
            || stripos($html, 'turbo=true') !== false
            || (bool)preg_match('/turbo\.yandex/i', $html);

        $siteData['has_turbo'] = $has;

        if (!$has) {
            $this->info('yandex_seo', 'Яндекс.Турбо / AMP не обнаружены',
                'Турбо-страницы Яндекса или AMP-версия сайта не найдены.',
                'Рассмотрите внедрение Яндекс.Турбо для мобильных страниц. Турбо-страницы ускоряют загрузку и получают приоритет в мобильной выдаче Яндекса. Реализуется через RSS-ленту или плагин CMS.'
            );
        }
    }

    private function checkRetargeting(string $html, array &$siteData): void
    {
        $has = stripos($html, 'mc.yandex.ru/pixel') !== false
            || (stripos($html, 'yandex_metrika') !== false && stripos($html, 'reachGoal') !== false);

        $siteData['has_yandex_retargeting'] = $has;

        if (!$has) {
            $this->info('yandex_seo', 'Яндекс.Директ: цели/ретаргетинг не обнаружены',
                'Пиксель ретаргетинга или цели reachGoal Яндекс.Метрики не найдены.',
                'Настройте цели в Яндекс.Метрике для ретаргетинга в Яндекс.Директе — это позволяет возвращать пользователей, не совершивших целевое действие.'
            );
        }
    }
}
