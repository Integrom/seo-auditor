<?php
namespace SeoAuditor\Checks;

class SeoCheck extends BaseCheck
{
    public function run(array $pages, array &$siteData): array
    {
        $titles       = [];
        $descriptions = [];
        $h1s          = [];
        $noTitle      = 0;
        $noDesc       = 0;
        $noH1         = 0;
        $multiH1      = 0;
        $noAlt        = 0;
        $noImgTitle   = 0;
        $totalImages  = 0;
        $noCanonical  = 0;
        $noIndexPages = 0;
        $thinPages    = 0;
        $titleEqH1    = 0;
        $titleEqDesc  = 0;
        $pagesNoImages = 0;
        $pageMetrics  = [];

        $this->checkRobots($pages[0]['url'] ?? '');
        $this->checkSitemap($pages[0]['url'] ?? '', $siteData);

        foreach ($pages as $page) {
            $html = $page['html'] ?? '';
            $url  = $page['url'] ?? '';
            if (empty($html) || ($page['status_code'] ?? 0) >= 400) {
                $pageMetrics[] = ['url' => $url, 'status' => $page['status_code'] ?? 0];
                continue;
            }

            try {
                $dom = $this->dom($html, $url);
            } catch (\Exception $e) {
                continue;
            }

            // noindex check
            $robotsMeta = '';
            try { $robotsMeta = strtolower($dom->filter('meta[name="robots"]')->first()->attr('content') ?? ''); } catch (\Exception $e) {}
            if (str_contains($robotsMeta, 'noindex')) {
                $noIndexPages++;
                $this->warning('seo', 'Страница закрыта от индексации (noindex)',
                    "Страница содержит meta robots noindex: $url",
                    "Проверьте, намеренно ли закрыта страница. Посадочные страницы должны быть открыты для индексации.",
                    $url
                );
            }

            // Title
            $title = '';
            try { $title = trim($dom->filter('title')->first()->text('')); } catch (\Exception $e) {}
            $titleLen = mb_strlen($title);

            if (empty($title)) {
                $noTitle++;
                $this->critical('seo', 'Отсутствует тег <title>',
                    "Страница без заголовка: $url",
                    "Добавьте уникальный тег <title> длиной 50–70 символов.",
                    $url
                );
            } elseif ($titleLen < 10) {
                $this->warning('seo', "Title слишком короткий: $titleLen симв.",
                    "«{$title}» ($url)",
                    "Оптимальная длина title: 50–70 символов.",
                    $url
                );
            } elseif ($titleLen > 80) {
                $this->warning('seo', "Title слишком длинный: $titleLen симв.",
                    "«" . mb_substr($title, 0, 50) . "…» ($url)",
                    "Сократите title до 70 символов.",
                    $url
                );
            }
            if ($title && in_array($title, $titles)) {
                $this->warning('seo', "Дублирующийся title",
                    "«" . mb_substr($title, 0, 50) . "» используется на нескольких страницах. URL: $url",
                    "Каждая страница должна иметь уникальный title.",
                    $url
                );
            }
            if ($title) $titles[] = $title;

            // Meta description
            $desc = '';
            try { $desc = trim($dom->filter('meta[name="description"]')->first()->attr('content') ?? ''); } catch (\Exception $e) {}
            $descLen = mb_strlen($desc);

            if (empty($desc)) {
                $noDesc++;
                $this->warning('seo', 'Отсутствует meta description',
                    "Страница без мета-описания: $url",
                    "Добавьте уникальное meta description длиной 120–160 символов.",
                    $url
                );
            } elseif ($descLen < 50) {
                $this->warning('seo', "Meta description слишком короткий: $descLen симв.",
                    "($url)",
                    "Оптимальная длина meta description: 120–160 символов.",
                    $url
                );
            } elseif ($descLen > 320) {
                $this->warning('seo', "Meta description слишком длинный: $descLen симв.",
                    "($url)",
                    "Сократите meta description до 160–280 символов.",
                    $url
                );
            }
            if ($desc && in_array($desc, $descriptions)) {
                $this->warning('seo', "Дублирующийся meta description",
                    "URL: $url",
                    "Каждая страница должна иметь уникальное мета-описание.",
                    $url
                );
            }
            if ($desc) $descriptions[] = $desc;

            // H1
            $h1Count = 0;
            $h1Text  = '';
            try {
                $h1Count = $dom->filter('h1')->count();
                if ($h1Count > 0) $h1Text = trim($dom->filter('h1')->first()->text(''));
            } catch (\Exception $e) {}

            if ($h1Count === 0) {
                $noH1++;
                $this->warning('seo', 'Отсутствует H1',
                    "Страница без заголовка H1: $url",
                    "Добавьте один тег H1 с основным ключевым словом.",
                    $url
                );
            } elseif ($h1Count > 1) {
                $multiH1++;
                $this->warning('seo', "Несколько H1 на странице: $h1Count шт.",
                    "URL: $url",
                    "На каждой странице должен быть ровно один H1.",
                    $url
                );
            }
            if ($h1Text && in_array($h1Text, $h1s)) {
                $this->warning('seo', "Дублирующийся H1",
                    "«" . mb_substr($h1Text, 0, 50) . "» — URL: $url",
                    "Каждая страница должна иметь уникальный H1.",
                    $url
                );
            }
            if ($h1Text) $h1s[] = $h1Text;

            // Title = H1
            if ($title && $h1Text && mb_strtolower(trim($title)) === mb_strtolower(trim($h1Text))) {
                $titleEqH1++;
                $this->info('seo', 'Title совпадает с H1',
                    "На странице $url значения title и H1 идентичны: «" . mb_substr($title, 0, 50) . "»",
                    "Рекомендуется делать title и H1 немного разными — title для поисковой выдачи, H1 для читателя.",
                    $url
                );
            }

            // Title = Description
            if ($title && $desc && mb_strtolower(trim($title)) === mb_strtolower(trim($desc))) {
                $titleEqDesc++;
                $this->warning('seo', 'Title совпадает с meta description',
                    "URL: $url — title и description идентичны.",
                    "Title и description должны содержать разный текст. Поисковики используют их независимо.",
                    $url
                );
            }

            // Images
            $pageImgsNoAlt  = 0;
            $pageImgNoTitle = 0;
            $pageTotalImgs  = 0;
            try {
                $dom->filter('img')->each(function ($img) use ($url, &$noAlt, &$noImgTitle, &$totalImages, &$pageImgsNoAlt, &$pageImgNoTitle, &$pageTotalImgs) {
                    $totalImages++;
                    $pageTotalImgs++;
                    $alt = $img->attr('alt');
                    if ($alt === null || $alt === '') {
                        $noAlt++;
                        $pageImgsNoAlt++;
                    }
                    $imgTitle = $img->attr('title');
                    if ($imgTitle === null || $imgTitle === '') {
                        $noImgTitle++;
                        $pageImgNoTitle++;
                    }
                });
            } catch (\Exception $e) {}

            if ($pageImgsNoAlt > 0) {
                $this->warning('seo', "Изображения без alt: $pageImgsNoAlt шт.",
                    "На странице $url найдено $pageImgsNoAlt изображений без атрибута alt.",
                    "Добавьте описательный alt к каждому изображению для SEO и доступности.",
                    $url
                );
            }
            if ($pageTotalImgs === 0) {
                $pagesNoImages++;
            }

            // Canonical
            $canonical = '';
            try { $canonical = $dom->filter('link[rel="canonical"]')->first()->attr('href') ?? ''; } catch (\Exception $e) {}
            if (empty($canonical)) {
                $noCanonical++;
                $this->info('seo', 'Отсутствует canonical',
                    "Страница без canonical тега: $url",
                    "Добавьте <link rel=\"canonical\" href=\"$url\">",
                    $url
                );
            }

            // Open Graph
            $ogTitle = '';
            try { $ogTitle = $dom->filter('meta[property="og:title"]')->first()->attr('content') ?? ''; } catch (\Exception $e) {}
            if (empty($ogTitle)) {
                $this->info('seo', 'Отсутствуют Open Graph теги',
                    "Страница без OG-тегов: $url",
                    "Добавьте og:title, og:description, og:image для красивого отображения в соцсетях.",
                    $url
                );
            }

            // Schema.org
            $hasSchema = str_contains($html, 'application/ld+json') || str_contains($html, 'itemscope');
            if (!$hasSchema) {
                $this->info('seo', 'Не найдена микроразметка Schema.org',
                    "URL: $url",
                    "Добавьте структурированные данные Schema.org для улучшения отображения в поисковой выдаче.",
                    $url
                );
            }

            // Thin pages (мало текста)
            $textContent = trim(strip_tags($html));
            $wordCount   = str_word_count(preg_replace('/\s+/', ' ', $textContent));
            if ($wordCount < 300 && $wordCount > 10) {
                $thinPages++;
                $this->warning('seo', "Мало контента на странице: $wordCount слов",
                    "Страница $url содержит менее 300 слов текста.",
                    "Добавьте уникальный текстовый контент. Минимальный рекомендуемый объём — 300–500 слов.",
                    $url
                );
            }

            $pageMetrics[] = [
                'url'           => $url,
                'status'        => $page['status_code'] ?? 200,
                'title_len'     => $titleLen,
                'desc_len'      => $descLen,
                'h1_count'      => $h1Count,
                'imgs_no_alt'   => $pageImgsNoAlt,
                'total_images'  => $pageTotalImgs,
                'has_canonical' => !empty($canonical),
                'has_og'        => !empty($ogTitle),
                'word_count'    => $wordCount,
            ];
        }

        if ($pagesNoImages > 0) {
            $this->info('seo', "Страниц без изображений: $pagesNoImages",
                "На $pagesNoImages страницах не найдено ни одного изображения.",
                "Добавьте изображения на страницы для улучшения пользовательского опыта и SEO.",
                ''
            );
        }

        $siteData['page_seo_metrics'] = $pageMetrics;
        $siteData['seo_summary'] = [
            'pages_checked'   => count($pages),
            'no_title'        => $noTitle,
            'no_description'  => $noDesc,
            'no_h1'           => $noH1,
            'multi_h1'        => $multiH1,
            'images_no_alt'   => $noAlt,
            'images_no_title' => $noImgTitle,
            'total_images'    => $totalImages,
            'no_canonical'    => $noCanonical,
            'noindex_pages'   => $noIndexPages,
            'thin_pages'      => $thinPages,
            'title_eq_h1'     => $titleEqH1,
            'title_eq_desc'   => $titleEqDesc,
            'pages_no_images' => $pagesNoImages,
        ];

        return $this->issues;
    }

    private function checkRobots(string $siteUrl): void
    {
        $base = parse_url($siteUrl, PHP_URL_SCHEME) . '://' . parse_url($siteUrl, PHP_URL_HOST);
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 5, 'verify' => false]);
            $resp   = $client->get("$base/robots.txt");
            $body   = (string)$resp->getBody();
            if (preg_match('/Disallow:\s*\/\s*$/m', $body)) {
                $this->critical('seo', 'robots.txt блокирует весь сайт',
                    "robots.txt содержит Disallow: / — весь сайт закрыт от индексации.",
                    "Проверьте robots.txt и уберите глобальный Disallow для разрешения индексации.",
                    "$base/robots.txt"
                );
            } else {
                $this->info('seo', 'robots.txt найден и настроен',
                    "Файл robots.txt присутствует на сайте.",
                    "Убедитесь что robots.txt разрешает индексацию нужных страниц.",
                    "$base/robots.txt"
                );
            }
        } catch (\Exception $e) {
            $this->critical('seo', 'robots.txt не найден',
                "Файл /robots.txt недоступен.",
                "Создайте файл /robots.txt с указаниями для поисковых роботов.",
                "$base/robots.txt"
            );
        }
    }

    private function checkSitemap(string $siteUrl, array &$siteData): void
    {
        $base      = parse_url($siteUrl, PHP_URL_SCHEME) . '://' . parse_url($siteUrl, PHP_URL_HOST);
        $locations = ["$base/sitemap.xml", "$base/sitemap_index.xml", "$base/sitemap/"];
        $found     = false;

        foreach ($locations as $loc) {
            try {
                $client = new \GuzzleHttp\Client(['timeout' => 5, 'verify' => false]);
                $resp   = $client->get($loc);
                if ($resp->getStatusCode() === 200) {
                    $body     = (string)$resp->getBody();
                    $urlCount = substr_count($body, '<url>');
                    $siteData['sitemap_url']   = $loc;
                    $siteData['sitemap_count'] = $urlCount;
                    $this->info('seo', "Sitemap найден: $urlCount URL",
                        "Sitemap доступен: $loc",
                        "Убедитесь что sitemap содержит все важные страницы и указан в robots.txt.",
                        $loc
                    );
                    $found = true;
                    break;
                }
            } catch (\Exception $e) {}
        }

        if (!$found) {
            $this->warning('seo', 'Sitemap.xml не найден',
                "Файл sitemap.xml недоступен по стандартным адресам.",
                "Создайте XML-карту сайта и укажите её в robots.txt: Sitemap: $base/sitemap.xml",
                "$base/sitemap.xml"
            );
        }
    }
}
