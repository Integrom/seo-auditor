<?php
namespace SeoAuditor\Checks;

class LinksCheck extends BaseCheck
{
    public function run(array $pages, array &$siteData): array
    {
        if (empty($pages)) return [];
        $baseHost = parse_url($pages[0]['url'] ?? '', PHP_URL_HOST);

        $externalLinks  = [];
        $incomingCounts = []; // url => кол-во входящих внутренних ссылок
        $outgoingCounts = []; // url => кол-во исходящих внутренних ссылок
        $pagesNoOutgoing     = [];
        $pagesTooManyLinks   = [];

        foreach ($pages as $page) {
            $html = $page['html'] ?? '';
            $url  = $page['url'] ?? '';
            if (empty($html)) continue;

            $outgoing = 0;
            try {
                $dom = $this->dom($html, $url);
                $dom->filter('a[href]')->each(function ($node) use ($baseHost, $url, &$externalLinks, &$incomingCounts, &$outgoing) {
                    $href = $node->attr('href') ?? '';
                    $href = trim($href);
                    if (empty($href) || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')) return;

                    if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
                        $linkHost = parse_url($href, PHP_URL_HOST);
                        if ($linkHost !== $baseHost) {
                            $externalLinks[$href] = true;
                        } else {
                            $outgoing++;
                            $normalized = rtrim($href, '/');
                            $incomingCounts[$normalized] = ($incomingCounts[$normalized] ?? 0) + 1;
                        }
                    } else {
                        // Относительная ссылка — внутренняя
                        $outgoing++;
                    }
                });
            } catch (\Exception $e) {}

            $outgoingCounts[$url] = $outgoing;
            if ($outgoing === 0) {
                $pagesNoOutgoing[] = $url;
            }
            if ($outgoing > 200) {
                $pagesTooManyLinks[] = $url;
            }
        }

        // Внешние ссылки
        $extCount = count($externalLinks);
        if ($extCount > 0) {
            $extList = implode(', ', array_slice(array_keys($externalLinks), 0, 10));
            $this->info('links', "Внешних ссылок: $extCount",
                "Найдено $extCount ссылок на внешние сайты. Примеры: $extList",
                "Проверьте все внешние ссылки. Нежелательные ссылки могут навредить репутации. Добавьте rel=\"nofollow\" к коммерческим ссылкам.",
                ''
            );
        }

        // Страницы без исходящих внутренних ссылок
        if (!empty($pagesNoOutgoing)) {
            $count = count($pagesNoOutgoing);
            $this->warning('links', "Страниц без исходящих внутренних ссылок: $count",
                "Страницы без внутренних ссылок: " . implode(', ', array_slice($pagesNoOutgoing, 0, 5)),
                "Добавьте внутренние ссылки на других страницах для улучшения перелинковки.",
                $pagesNoOutgoing[0]
            );
        }

        // Страницы с >200 ссылок
        if (!empty($pagesTooManyLinks)) {
            $count = count($pagesTooManyLinks);
            $this->warning('links', "Страниц с более 200 исходящими ссылками: $count",
                "Страницы: " . implode(', ', array_slice($pagesTooManyLinks, 0, 5)),
                "Сократите количество исходящих ссылок — PageRank размывается среди большого числа ссылок.",
                $pagesTooManyLinks[0]
            );
        }

        // Посадочные страницы с менее 5 входящими внутренними ссылками
        $lowIncoming = [];
        foreach ($pages as $page) {
            $url        = rtrim($page['url'] ?? '', '/');
            $incoming   = $incomingCounts[$url] ?? 0;
            // Главную страницу не считаем
            if (parse_url($url, PHP_URL_PATH) === '' || parse_url($url, PHP_URL_PATH) === '/') continue;
            if ($incoming < 5) {
                $lowIncoming[] = ['url' => $url, 'count' => $incoming];
            }
        }
        if (!empty($lowIncoming)) {
            $count   = count($lowIncoming);
            $details = implode(', ', array_map(fn($p) => "{$p['url']} ({$p['count']} ссылок)", array_slice($lowIncoming, 0, 5)));
            $this->warning('links', "Посадочных страниц с менее 5 входящих ссылок: $count",
                "Страницы (входящих внутренних ссылок): $details",
                "Добавьте внутренние ссылки на эти страницы из других разделов сайта для роста их авторитетности.",
                $lowIncoming[0]['url']
            );
        }

        $siteData['external_links']       = array_keys($externalLinks);
        $siteData['external_links_count'] = $extCount;

        return $this->issues;
    }
}
