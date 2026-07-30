<?php
namespace SeoAuditor\Checks;

use GuzzleHttp\Client;

class ResourceCheck extends BaseCheck
{
    private Client $client;
    private const MAX_PER_TYPE = 60;

    public function __construct()
    {
        $this->client = new Client([
            'timeout'         => 5,
            'verify'          => false,
            'allow_redirects' => true,
            'http_errors'     => false,
        ]);
    }

    public function run(array $pages, array &$siteData): array
    {
        if (empty($pages)) return [];
        $base = parse_url($pages[0]['url'] ?? '', PHP_URL_SCHEME) . '://' . parse_url($pages[0]['url'] ?? '', PHP_URL_HOST);

        $jsUrls  = [];
        $cssUrls = [];
        $imgUrls = [];

        foreach ($pages as $page) {
            $html = $page['html'] ?? '';
            if (empty($html)) continue;

            // JS-файлы
            preg_match_all('/<script[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $jsM);
            foreach ($jsM[1] as $src) {
                $abs = $this->toAbsolute($src, $base);
                if ($abs) $jsUrls[$abs] = true;
            }

            // CSS-файлы
            preg_match_all('/<link[^>]+rel=["\']stylesheet["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $cssM1);
            preg_match_all('/<link[^>]+href=["\']([^"\']+\.css[^"\']*)["\'][^>]+rel=["\']stylesheet["\'][^>]*>/i', $html, $cssM2);
            foreach (array_merge($cssM1[1] ?? [], $cssM2[1] ?? []) as $href) {
                $abs = $this->toAbsolute($href, $base);
                if ($abs) $cssUrls[$abs] = true;
            }

            // Изображения
            preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $imgM);
            foreach ($imgM[1] as $src) {
                $abs = $this->toAbsolute($src, $base);
                if ($abs) $imgUrls[$abs] = true;
            }
        }

        $brokenJs  = $this->checkUrls(array_keys($jsUrls),  'JS-файл');
        $brokenCss = $this->checkUrls(array_keys($cssUrls), 'CSS-файл');
        $brokenImg = $this->checkUrls(array_keys($imgUrls), 'Изображение');

        if (!empty($brokenJs)) {
            $count = count($brokenJs);
            $this->warning('technical', "Битых JS-файлов: $count",
                "Файлы JS, недоступные (4xx): " . implode(', ', array_slice($brokenJs, 0, 5)),
                "Удалите или исправьте ссылки на несуществующие скрипты. Битые JS нарушают работу сайта.",
                $brokenJs[0]
            );
        } else {
            $this->info('technical', 'Битые JS-файлы не обнаружены',
                "Все подключённые JS-файлы доступны.",
                "Регулярно проверяйте доступность подключённых ресурсов.",
                ''
            );
        }

        if (!empty($brokenCss)) {
            $count = count($brokenCss);
            $this->warning('technical', "Битых CSS-файлов: $count",
                "Файлы CSS, недоступные (4xx): " . implode(', ', array_slice($brokenCss, 0, 5)),
                "Удалите или исправьте ссылки на несуществующие стили. Битые CSS нарушают оформление сайта.",
                $brokenCss[0]
            );
        } else {
            $this->info('technical', 'Битые CSS-файлы не обнаружены',
                "Все подключённые CSS-файлы доступны.",
                "Регулярно проверяйте доступность подключённых ресурсов.",
                ''
            );
        }

        if (!empty($brokenImg)) {
            $count = count($brokenImg);
            $this->warning('technical', "Битых изображений: $count",
                "Изображения, недоступные (4xx): " . implode(', ', array_slice($brokenImg, 0, 5)),
                "Удалите или исправьте ссылки на несуществующие изображения.",
                $brokenImg[0]
            );
        } else {
            $this->info('technical', 'Битые изображения не обнаружены',
                "Все изображения доступны.",
                "Регулярно проверяйте доступность изображений на сайте.",
                ''
            );
        }

        $siteData['resources'] = [
            'js_total'    => count($jsUrls),
            'css_total'   => count($cssUrls),
            'img_total'   => count($imgUrls),
            'js_broken'   => count($brokenJs),
            'css_broken'  => count($brokenCss),
            'img_broken'  => count($brokenImg),
        ];

        return $this->issues;
    }

    private function checkUrls(array $urls, string $type): array
    {
        $broken = [];
        $checked = 0;
        foreach ($urls as $url) {
            if ($checked >= self::MAX_PER_TYPE) break;
            $checked++;
            try {
                $resp = $this->client->head($url);
                if ($resp->getStatusCode() >= 400) {
                    $broken[] = $url;
                }
            } catch (\Exception $e) {
                // Если HEAD не поддерживается — пробуем GET
                try {
                    $resp = $this->client->get($url, ['stream' => true]);
                    if ($resp->getStatusCode() >= 400) {
                        $broken[] = $url;
                    }
                } catch (\Exception $e2) {}
            }
        }
        return $broken;
    }

    private function toAbsolute(string $src, string $base): ?string
    {
        $src = trim($src);
        if (empty($src) || str_starts_with($src, 'data:') || str_starts_with($src, '//') && !str_contains($src, '.')) return null;
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) return $src;
        if (str_starts_with($src, '//')) return 'https:' . $src;
        if (str_starts_with($src, '/')) return $base . $src;
        return null;
    }
}
