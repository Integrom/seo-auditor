<?php
namespace SeoAuditor\Checks;

class AdaptiveCheck extends BaseCheck
{
    public function run(array $pages, array &$siteData): array
    {
        if (empty($pages)) return [];

        foreach ($pages as $page) {
            $html = $page['html'] ?? '';
            $url  = $page['url'] ?? '';
            if (empty($html)) continue;

            // Viewport meta
            $hasViewport = preg_match('/<meta[^>]+name=["\']viewport["\'][^>]*>/i', $html);
            if (!$hasViewport) {
                $this->critical('adaptive', 'Отсутствует meta viewport',
                    "Страница без тега viewport: $url. Сайт не будет корректно масштабироваться на мобильных.",
                    "Добавьте в <head>: <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">",
                    $url
                );
            }

            // Медиа-запросы в inline стилях или link
            $hasMediaQuery = str_contains($html, '@media') || str_contains($html, 'media=');
            if (!$hasMediaQuery) {
                $this->warning('adaptive', 'Медиа-запросы не обнаружены',
                    "В HTML не найдены CSS медиа-запросы (@media). Сайт может не быть адаптивным. ($url)",
                    "Используйте CSS медиа-запросы для адаптации под разные разрешения экрана.",
                    $url
                );
            }

            // Фиксированные размеры (признак не-адаптивности)
            if (preg_match('/width:\s*\d{3,4}px/i', $html)) {
                $this->warning('adaptive', 'Фиксированные размеры в стилях',
                    "Обнаружены фиксированные ширины в пикселях (например width: 960px). ($url)",
                    "Замените фиксированные размеры на процентные или используйте flexbox/grid.",
                    $url
                );
            }

            // Touch-события и мобильные классы
            $hasTouchFriendly = str_contains($html, 'touch') || str_contains($html, 'swipe') || str_contains($html, 'mobile');
            $siteData['mobile_friendly'] = $hasViewport;

            // Шрифт не слишком мелкий
            if (preg_match('/font-size:\s*([0-9]+)px/i', $html, $m) && (int)$m[1] < 12) {
                $this->warning('adaptive', "Слишком мелкий шрифт ({$m[1]}px)",
                    "Обнаружен шрифт размером {$m[1]}px, что неудобно для чтения на мобильных. ($url)",
                    "Используйте минимальный размер шрифта 14px (рекомендуется 16px).",
                    $url
                );
            }

            break; // Достаточно проверить главную страницу
        }

        return $this->issues;
    }
}
