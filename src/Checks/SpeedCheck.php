<?php
namespace SeoAuditor\Checks;

use GuzzleHttp\Client;
use SeoAuditor\Core\Config;

class SpeedCheck extends BaseCheck
{
    public function run(array $pages, array &$siteData): array
    {
        if (empty($pages)) return [];
        $url = $pages[0]['url'] ?? '';

        // Время ответа первой страницы
        $responseMs = $pages[0]['response_ms'] ?? 0;
        $siteData['response_ms'] = $responseMs;

        if ($responseMs > 3000) {
            $this->critical('speed', "Медленный ответ сервера: {$responseMs}мс",
                "Время ответа сервера превышает 3 секунды. Это критично для SEO и UX.",
                "Оптимизируйте сервер: PHP-FPM, кэширование, CDN. Целевое время: < 200мс.",
                $url
            );
        } elseif ($responseMs > 1000) {
            $this->warning('speed', "Медленный ответ сервера: {$responseMs}мс",
                "Время ответа сервера: {$responseMs}мс. Рекомендуется < 200мс.",
                "Включите OPcache PHP, настройте кэширование страниц.",
                $url
            );
        } else {
            $this->info('speed', "Время ответа сервера: {$responseMs}мс",
                "Сервер отвечает быстро: {$responseMs}мс.",
                "Поддерживайте время ответа < 200мс.",
                $url
            );
        }

        // Размер HTML
        $sizeKb = round(($pages[0]['size_bytes'] ?? 0) / 1024, 1);
        if ($sizeKb > 200) {
            $this->warning('speed', "Большой размер HTML: {$sizeKb}КБ",
                "HTML страница весит {$sizeKb}КБ. Рекомендуется < 100КБ.",
                "Минимизируйте HTML, уберите лишний код, используйте gzip-сжатие.",
                $url
            );
        }

        // PageSpeed Insights API (если есть ключ)
        $apiKey = Config::get('pagespeed.api_key', '');
        if ($apiKey) {
            $this->runPageSpeedApi($url, $apiKey, $siteData);
        }
        $this->checkResourceHints($pages[0]['html'] ?? '', $url, $siteData);

        return $this->issues;
    }

    private function runPageSpeedApi(string $url, string $apiKey, array &$siteData): void
    {
        try {
            $client   = new Client(['timeout' => 60, 'verify' => false]);
            $apiUrl   = "https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=" . urlencode($url) . "&key=$apiKey&strategy=mobile";
            $response = $client->get($apiUrl);
            $data     = json_decode((string)$response->getBody(), true);

            $score  = $data['lighthouseResult']['categories']['performance']['score'] ?? null;
            $audits = $data['lighthouseResult']['audits'] ?? [];

            if ($score !== null) {
                $scorePercent = (int)($score * 100);
                $siteData['pagespeed_mobile'] = $scorePercent;

                $severity = $scorePercent < 50 ? 'critical' : ($scorePercent < 90 ? 'warning' : 'info');
                $this->addIssue($severity, 'speed',
                    "PageSpeed Mobile: $scorePercent/100",
                    "Оценка производительности по Google PageSpeed Insights (мобильная версия).",
                    "Оценка ниже 90 требует оптимизации. Ключевые метрики: LCP, INP, CLS.",
                    $url
                );
            }

            // Полевые Core Web Vitals (реальные пользователи, CrUX)
            $this->checkFieldVitals($data['loadingExperience']['metrics'] ?? [], $url, $siteData);

            // Лабораторные метрики Lighthouse
            $vitals = [
                'largest-contentful-paint' => 'LCP (Largest Contentful Paint)',
                'cumulative-layout-shift'  => 'CLS (Cumulative Layout Shift)',
                'total-blocking-time'      => 'TBT (Total Blocking Time)',
            ];
            foreach ($vitals as $auditKey => $label) {
                if (isset($audits[$auditKey])) {
                    $audit    = $audits[$auditKey];
                    $display  = $audit['displayValue'] ?? '';
                    $audScore = $audit['score'] ?? 1;
                    $severity = $audScore < 0.5 ? 'critical' : ($audScore < 0.9 ? 'warning' : 'info');
                    $siteData['vitals'][$auditKey] = $display;
                    $this->addIssue($severity, 'speed', "$label: $display",
                        $audit['description'] ?? '',
                        "Улучшите показатель $label для роста позиций в Google и Яндекс.",
                        $url
                    );
                }
            }
        } catch (\Exception $e) {
            // API недоступен — пропускаем
        }
    }

    // Полевые данные CrUX: LCP < 2.5s, INP < 200ms, CLS < 0.1 (75-й перцентиль)
    private function checkFieldVitals(array $metrics, string $url, array &$siteData): void
    {
        $defs = [
            'LARGEST_CONTENTFUL_PAINT_MS' => [
                'label' => 'LCP (загрузка)', 'good' => 2500, 'poor' => 4000,
                'fmt'   => fn($v) => round($v / 1000, 2) . ' с',
                'rec'   => 'Оптимизируйте главное изображение/блок первого экрана: сжатие, preload, серверное кэширование. Цель: < 2.5 с.',
            ],
            'INTERACTION_TO_NEXT_PAINT' => [
                'label' => 'INP (отзывчивость)', 'good' => 200, 'poor' => 500,
                'fmt'   => fn($v) => $v . ' мс',
                'rec'   => 'Сократите тяжёлый JavaScript, разбейте длинные задачи, отложите неважные скрипты. Цель: < 200 мс. INP — самая часто проваливаемая метрика.',
            ],
            'CUMULATIVE_LAYOUT_SHIFT_SCORE' => [
                'label' => 'CLS (стабильность)', 'good' => 10, 'poor' => 25,
                'fmt'   => fn($v) => round($v / 100, 2),
                'rec'   => 'Задайте размеры изображениям и рекламным блокам, не вставляйте контент над уже отрисованным. Цель: < 0.1.',
            ],
        ];

        foreach ($defs as $key => $def) {
            if (!isset($metrics[$key]['percentile'])) continue;
            $val     = $metrics[$key]['percentile'];
            $display = $def['fmt']($val);
            $siteData['field_vitals'][$key] = $display;

            if ($val > $def['poor']) {
                $this->critical('speed', "{$def['label']}: $display — плохо",
                    "Полевые данные реальных пользователей (CrUX, 75-й перцентиль) показывают неудовлетворительное значение метрики {$def['label']}.",
                    $def['rec'], $url
                );
            } elseif ($val > $def['good']) {
                $this->warning('speed', "{$def['label']}: $display — требует улучшения",
                    "По данным реальных пользователей (CrUX) метрика {$def['label']} в жёлтой зоне.",
                    $def['rec'], $url
                );
            } else {
                $this->info('speed', "{$def['label']}: $display — хорошо",
                    "Метрика {$def['label']} по полевым данным реальных пользователей в зелёной зоне.",
                    'Поддерживайте показатель, следите за метрикой после крупных обновлений сайта.', $url
                );
            }
        }
    }

    private function checkResourceHints(string $html, string $url, array &$siteData): void
    {
        // Проверяем наличие оптимизаций в HTML
        $hasLazyLoad  = str_contains($html, 'loading="lazy"') || str_contains($html, "loading='lazy'");
        $hasPreload   = str_contains($html, 'rel="preload"');
        $hasWebP      = str_contains($html, '.webp') || str_contains($html, '.avif');

        if (!$hasLazyLoad) {
            $this->warning('speed', 'Lazy loading не используется',
                "Изображения загружаются без атрибута loading=\"lazy\".",
                "Добавьте loading=\"lazy\" ко всем изображениям ниже первого экрана.",
                $url
            );
        }
        if (!$hasPreload) {
            $this->info('speed', 'Preload ресурсов не настроен',
                "Критические ресурсы не предзагружаются через rel=\"preload\".",
                "Добавьте <link rel=\"preload\"> для критических CSS/шрифтов.",
                $url
            );
        }
        if (!$hasWebP) {
            $this->info('speed', 'Современные форматы изображений не обнаружены',
                "Сайт не использует WebP/AVIF.",
                "Конвертируйте изображения в WebP или AVIF — экономия 25–50% объёма.",
                $url
            );
        }
    }
}
