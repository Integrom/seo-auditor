<?php
namespace SeoAuditor\Checks;

class CmsDetector extends BaseCheck
{
    private array $signatures = [
        'WordPress'  => ['/wp-content/', '/wp-includes/', 'wordpress', 'wp-json'],
        '1С-Битрикс' => ['/bitrix/', 'BX.message', 'bitrix/js', 'bitrix/cache'],
        'Joomla'     => ['/components/com_', '/media/jui/', 'Joomla!'],
        'Drupal'     => ['/sites/default/files/', 'Drupal.settings', 'drupal.js'],
        'OpenCart'   => ['/catalog/view/theme/', 'route=common/home'],
        'Tilda'      => ['tilda.ws', 'tildacdn.com', 't-body'],
        'WIX'        => ['wix.com', 'wixstatic.com', '_wixUtils'],
        'Shopify'    => ['shopify.com', 'Shopify.theme', 'cdn.shopify'],
        'MODX'       => ['[[+', 'modx', 'manager/controllers/default'],
        'PrestaShop' => ['prestashop', '/modules/blockcart/'],
    ];

    public function run(array $pages, array &$siteData): array
    {
        if (empty($pages)) return [];

        $firstPage = $pages[0];
        $html      = $firstPage['html'] ?? '';
        $headers   = $firstPage['headers'] ?? [];
        $detected  = [];

        // Метатег generator
        if (!empty($html)) {
            preg_match('/<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m);
            if (!empty($m[1])) {
                $siteData['meta_generator'] = $m[1];
            }
        }

        // Проверяем сигнатуры
        $haystack = strtolower($html . implode(' ', array_values($headers)));
        foreach ($this->signatures as $cms => $signs) {
            foreach ($signs as $sign) {
                if (str_contains($haystack, strtolower($sign))) {
                    $detected[] = $cms;
                    break;
                }
            }
        }

        // Из заголовков X-Powered-By
        $powered = $headers['x-powered-by'] ?? '';
        if ($powered) {
            $this->info('cms', "Технология сервера: $powered", "Заголовок X-Powered-By раскрывает технологии.", "Скройте заголовок X-Powered-By в конфигурации сервера.", $firstPage['url']);
        }

        $cms = !empty($detected) ? implode(', ', array_unique($detected)) : 'Не определена';
        $siteData['cms'] = $cms;

        $this->info('cms', "CMS сайта: $cms",
            "Определена система управления контентом: $cms.",
            "Убедитесь что CMS обновлена до актуальной версии.",
            $firstPage['url']
        );

        return $this->issues;
    }

    public static function detect(array $pages, array $headers = []): string
    {
        $check = new self();
        $data  = [];
        $check->run($pages, $data);
        return $data['cms'] ?? 'Не определена';
    }
}
