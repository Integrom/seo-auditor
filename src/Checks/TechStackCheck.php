<?php
namespace SeoAuditor\Checks;

class TechStackCheck extends BaseCheck
{
    public function run(array $pages, array &$siteData): array
    {
        if (empty($pages)) return [];
        $page    = $pages[0];
        $html    = $page['html'] ?? '';
        $headers = $page['headers'] ?? [];
        $stack   = [];

        // Веб-сервер
        $server = $headers['server'] ?? '';
        if ($server) {
            $stack[] = "Сервер: $server";
            $siteData['server'] = $server;
            if (preg_match('/(\d+\.\d+)/', $server, $m)) {
                $this->warning('tech_stack', "Версия веб-сервера раскрыта",
                    "Заголовок Server раскрывает версию: $server.",
                    "Скройте версию сервера в nginx: `server_tokens off;`",
                    $page['url']
                );
            }
        }

        // PHP версия
        $powered = $headers['x-powered-by'] ?? '';
        if (str_contains(strtolower($powered), 'php')) {
            $stack[] = $powered;
            $siteData['php_version'] = $powered;
            if (preg_match('/PHP\/(\d+\.\d+\.\d+)/i', $powered, $m)) {
                $stack[] = "PHP $m[1]";
            }
        }

        // JS-фреймворки из HTML
        $jsFrameworks = [
            'React'      => ['react.', 'react-dom', '__REACT_DEVTOOLS', 'data-react'],
            'Vue.js'     => ['vue.js', 'vue.min.js', '__vue__', 'v-bind:', 'v-model='],
            'Angular'    => ['angular.js', 'ng-app=', 'ng-controller', 'angular.module'],
            'jQuery'     => ['jquery.min.js', 'jquery.js', '$.ajax', 'jQuery('],
            'Next.js'    => ['__NEXT_DATA__', '_next/static'],
            'Nuxt.js'    => ['__nuxt', '_nuxt/'],
            'Bootstrap'  => ['bootstrap.min.css', 'bootstrap.css', 'btn btn-'],
            'Tailwind'   => ['tailwind', 'tw-', 'class="text-'],
        ];

        $lowerHtml = strtolower($html);
        $detected  = [];
        foreach ($jsFrameworks as $name => $signs) {
            foreach ($signs as $s) {
                if (str_contains($lowerHtml, strtolower($s))) {
                    $detected[] = $name;
                    break;
                }
            }
        }

        // Аналитика
        $analytics = [];
        if (str_contains($html, 'google-analytics.com') || str_contains($html, 'gtag(') || str_contains($html, 'UA-')) {
            $analytics[] = 'Google Analytics';
        }
        if (str_contains($html, 'mc.yandex.ru') || str_contains($html, 'yaCounter') || str_contains($html, 'ym(')) {
            $analytics[] = 'Яндекс.Метрика';
        }
        if (str_contains($html, 'vk.com/js/api') || str_contains($html, 'VK.Retargeting')) {
            $analytics[] = 'VK Pixel';
        }

        $siteData['tech_stack']  = array_unique($stack);
        $siteData['js_frameworks'] = $detected;
        $siteData['analytics']   = $analytics;

        if (!empty($detected)) {
            $this->info('tech_stack', 'JS-фреймворки: ' . implode(', ', $detected),
                'Обнаружены JavaScript-библиотеки: ' . implode(', ', $detected),
                'Убедитесь что используемые версии библиотек актуальны и не содержат уязвимостей.',
                $page['url']
            );
        }

        if (empty($analytics)) {
            $this->warning('tech_stack', 'Не обнаружены системы аналитики',
                'На сайте не найдены Google Analytics или Яндекс.Метрика.',
                'Подключите систему аналитики для отслеживания трафика и поведения пользователей.',
                $page['url']
            );
        } else {
            $siteData['analytics_list'] = implode(', ', $analytics);
        }

        return $this->issues;
    }
}
