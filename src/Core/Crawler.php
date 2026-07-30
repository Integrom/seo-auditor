<?php
namespace SeoAuditor\Core;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

class Crawler
{
    private Client $client;
    private array $visited = [];
    private array $queued  = [];
    private array $queue   = [];
    private string $baseHost;
    private string $baseUrl;
    private int $maxPages;
    private array $pages = [];

    public function __construct()
    {
        $this->maxPages = Config::get('crawler.max_pages', 100);
        $this->client   = new Client([
            'timeout'         => Config::get('crawler.timeout', 10),
            'allow_redirects' => [
                'max'             => 5,
                'track_redirects' => true,
                // Редирект тоже может увести во внутреннюю сеть — проверяем каждый шаг
                'on_redirect'     => function ($request, $response, $uri) {
                    UrlGuard::assert((string) $uri);
                },
            ],
            'verify'          => false,
            'headers'         => [
                'User-Agent' => Config::get('crawler.user_agent', 'SeoAuditorBot/1.0'),
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
        ]);
    }

    public function crawl(string $startUrl, callable $onPage = null): array
    {
        UrlGuard::assert($startUrl);

        $parsed         = parse_url($startUrl);
        $this->baseHost = $parsed['host'];
        $this->baseUrl  = $parsed['scheme'] . '://' . $parsed['host'];

        $this->enqueue($this->normalizeUrl($startUrl));
        $this->seedFromSitemap();

        while (!empty($this->queue) && count($this->pages) < $this->maxPages) {
            $url = array_shift($this->queue);
            if (isset($this->visited[$url])) continue;
            $this->visited[$url] = true;

            $page = $this->fetchPage($url);
            if ($page) {
                $this->pages[] = $page;
                if ($onPage) $onPage($page, count($this->pages));
                $this->extractLinks($page['html'] ?? '', $url);
            }

            usleep((int)(Config::get('crawler.delay', 0.3) * 1_000_000));
        }

        return $this->pages;
    }

    private function enqueue(string $url): void
    {
        if (!isset($this->visited[$url]) && !isset($this->queued[$url]) && $this->isHtmlUrl($url)) {
            $this->queued[$url] = true;
            $this->queue[]      = $url;
        }
    }

    private function isHtmlUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '') return true;
        $nonHtml = ['xml','pdf','jpg','jpeg','png','gif','webp','svg','ico','bmp','tiff',
                    'zip','rar','tar','gz','7z','doc','docx','xls','xlsx','ppt','pptx',
                    'mp3','mp4','avi','mov','wmv','flv','css','js','json','txt','csv',
                    'woff','woff2','ttf','eot','otf'];
        return !in_array($ext, $nonHtml, true);
    }

    private function seedFromSitemap(): void
    {
        $candidates = [
            $this->baseUrl . '/sitemap.xml',
            $this->baseUrl . '/sitemap_index.xml',
        ];
        foreach ($candidates as $sitemapUrl) {
            try {
                $resp = $this->client->get($sitemapUrl, ['timeout' => 8, 'http_errors' => false]);
                if ($resp->getStatusCode() !== 200) continue;
                $xml = (string) $resp->getBody();
                preg_match_all('/<loc>\s*(https?:\/\/[^<]+)\s*<\/loc>/i', $xml, $m);
                foreach ($m[1] as $loc) {
                    $loc = trim($loc);
                    if ($this->isSameDomain($loc)) {
                        $this->enqueue($this->normalizeUrl($loc));
                    } elseif (str_ends_with($loc, '.xml')) {
                        // вложенный sitemap — парсим рекурсивно.
                        // Может указывать на чужой хост, поэтому проверяем адрес
                        if (!UrlGuard::isAllowed($loc)) continue;
                        try {
                            $sub = $this->client->get($loc, ['timeout' => 8, 'http_errors' => false]);
                            if ($sub->getStatusCode() === 200) {
                                preg_match_all('/<loc>\s*(https?:\/\/[^<]+)\s*<\/loc>/i', (string)$sub->getBody(), $sm);
                                foreach ($sm[1] as $subloc) {
                                    $subloc = trim($subloc);
                                    if ($this->isSameDomain($subloc)) $this->enqueue($this->normalizeUrl($subloc));
                                }
                            }
                        } catch (\Exception $e) {}
                    }
                }
                break; // нашли рабочий sitemap
            } catch (\Exception $e) {}
        }
    }

    public function fetchPage(string $url): ?array
    {
        try {
            UrlGuard::assert($url);
            $start    = microtime(true);
            $response = $this->client->get($url);
            $time     = round((microtime(true) - $start) * 1000);
            $html     = (string) $response->getBody();
            $headers  = [];
            foreach ($response->getHeaders() as $name => $values) {
                $headers[strtolower($name)] = implode(', ', $values);
            }

            return [
                'url'         => $url,
                'status_code' => $response->getStatusCode(),
                'html'        => $html,
                'headers'     => $headers,
                'response_ms' => $time,
                'size_bytes'  => strlen($html),
            ];
        } catch (RequestException $e) {
            $code = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            return [
                'url'         => $url,
                'status_code' => $code,
                'html'        => '',
                'headers'     => [],
                'response_ms' => 0,
                'size_bytes'  => 0,
                'error'       => $e->getMessage(),
            ];
        } catch (\RuntimeException $e) {
            // Ссылка ведёт на запрещённый адрес — пропускаем страницу, аудит продолжается
            error_log("[Crawler] пропущен $url: " . $e->getMessage());
            return null;
        }
    }

    private function extractLinks(string $html, string $currentUrl): void
    {
        if (empty($html)) return;
        try {
            $dom = new DomCrawler($html, $currentUrl);
            $dom->filter('a[href]')->each(function (DomCrawler $node) {
                $href = $node->attr('href');
                if (!$href) return;
                $abs = $this->toAbsolute($href, $this->baseUrl);
                if ($abs && $this->isSameDomain($abs)) $this->enqueue($abs);
            });
        } catch (\Exception $e) {}
    }

    private function toAbsolute(string $href, string $base): ?string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $this->normalizeUrl($href);
        }
        if (str_starts_with($href, '//')) {
            return $this->normalizeUrl('https:' . $href);
        }
        if (str_starts_with($href, '/')) {
            return $this->normalizeUrl($this->baseUrl . $href);
        }
        return null;
    }

    private function isSameDomain(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        return $host === $this->baseHost;
    }

    private function normalizeUrl(string $url): string
    {
        // Убираем фрагменты и нормализуем
        $url = strtok($url, '#');
        return rtrim($url, '/');
    }

    public function getBaseUrl(): string { return $this->baseUrl; }
}
