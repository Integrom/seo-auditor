<?php
namespace SeoAuditor\Core;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Обход сайта. Страницы загружаются параллельно (Guzzle Pool) волнами в ширину:
 * очередь текущего уровня скачивается одновременно, найденные на ней ссылки
 * образуют следующую волну. Последовательный обход 500 страниц занимал минуты —
 * основное время уходило на ожидание ответа, а не на разбор HTML.
 */
class Crawler
{
    private Client $client;
    private array  $visited = [];
    private array  $queued  = [];
    private array  $queue   = [];
    private string $baseHost;
    private string $baseUrl;
    /** 0 или меньше — обходить сайт целиком, без ограничения по числу страниц */
    private int    $maxPages;
    private int    $concurrency;
    private float  $waveDelay;
    private array  $pages = [];

    /** Сколько адресов берём в одну волну, когда лимит страниц не задан */
    private const WAVE_CAP = 200;

    /** Доля memory_limit, после которой обход останавливается */
    private const MEMORY_THRESHOLD = 0.8;

    private int    $memoryCeiling = 0;
    private string $stopReason    = 'сайт обойдён полностью';

    /** Время ответа по адресам, заполняется обработчиком статистики Guzzle */
    private array $timings = [];

    public function __construct(?int $concurrency = null)
    {
        $this->maxPages    = (int) Config::get('crawler.max_pages', 0);
        $this->concurrency = max(1, $concurrency ?? (int) Config::get('crawler.concurrency', 8));
        $this->waveDelay   = (float) Config::get('crawler.delay', 0.3);
        $this->memoryCeiling = $this->resolveMemoryCeiling();

        $this->client = new Client([
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
                'User-Agent'      => Config::get('crawler.user_agent', 'SeoAuditorBot/1.0'),
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Encoding' => 'gzip, deflate',
            ],
        ]);
    }

    public function crawl(string $startUrl, ?callable $onPage = null): array
    {
        UrlGuard::assert($startUrl);

        $parsed         = parse_url($startUrl);
        $this->baseHost = strtolower($parsed['host']);
        $this->baseUrl  = strtolower($parsed['scheme']) . '://' . $this->baseHost;

        $this->enqueue(UrlTools::normalize($startUrl));
        $this->seedFromSitemap();

        while (!empty($this->queue) && $this->hasCapacity()) {
            $wave = $this->takeWave();
            if (empty($wave)) break;

            $this->fetchWave($wave, $onPage);

            if ($this->waveDelay > 0 && !empty($this->queue) && $this->hasCapacity()) {
                usleep((int) ($this->waveDelay * 1_000_000));
            }
        }

        if (!empty($this->queue) && $this->stopReason === 'сайт обойдён полностью') {
            $this->stopReason = 'обход прерван, в очереди осталось ' . count($this->queue) . ' адресов';
        }

        return $this->pages;
    }

    /**
     * Можно ли продолжать обход.
     *
     * Ограничение по числу страниц может быть снято (max_pages = 0), но память
     * не бесконечна: HTML всех страниц хранится в массиве до конца аудита,
     * поэтому обход останавливается, не доводя процесс до аварийного завершения.
     */
    private function hasCapacity(): bool
    {
        if ($this->maxPages > 0 && count($this->pages) >= $this->maxPages) {
            $this->stopReason = "достигнут лимит в {$this->maxPages} страниц";
            return false;
        }

        if ($this->memoryCeiling > 0 && memory_get_usage(true) >= $this->memoryCeiling) {
            $used = round(memory_get_usage(true) / 1048576);
            $this->stopReason = "обход остановлен на " . count($this->pages)
                              . " странице: израсходована память ({$used} МБ)";
            error_log('[Crawler] ' . $this->stopReason);
            return false;
        }

        return true;
    }

    /** Берёт из очереди следующую волну, не превышая остаток лимита страниц */
    private function takeWave(): array
    {
        $capacity = $this->maxPages > 0
            ? min(self::WAVE_CAP, $this->maxPages - count($this->pages))
            : self::WAVE_CAP;

        if ($capacity <= 0) return [];

        $wave = [];
        while (!empty($this->queue) && count($wave) < $capacity) {
            $url = array_shift($this->queue);
            if (isset($this->visited[$url])) continue;
            // Проверяем прямо перед запросом: ссылка могла прийти со страницы сайта
            if (!UrlGuard::isAllowed($url)) {
                error_log("[Crawler] пропущен запрещённый адрес: $url");
                continue;
            }
            $this->visited[$url] = true;
            $wave[] = $url;
        }
        return $wave;
    }

    /**
     * Скачивает волну параллельно.
     *
     * Ответы приходят в произвольном порядке — какой сервер отдал первым.
     * Складывать их в этом порядке нельзя: проверки считают $pages[0] главной
     * страницей, и при параллельной загрузке туда попадала случайная страница.
     * Поэтому результаты собираются в отображение по адресу, а в общий список
     * добавляются строго в порядке очереди.
     */
    private function fetchWave(array $urls, ?callable $onPage): void
    {
        $results = [];

        $requests = function () use ($urls) {
            foreach ($urls as $url) {
                yield $url => new Request('GET', $url);
            }
        };

        $pool = new Pool($this->client, $requests(), [
            'concurrency' => $this->concurrency,
            'options'     => [
                'on_stats' => function (\GuzzleHttp\TransferStats $stats) {
                    $this->timings[(string) $stats->getEffectiveUri()] = round($stats->getTransferTime() * 1000);
                },
            ],
            'fulfilled' => function (ResponseInterface $response, string $url) use (&$results) {
                $results[$url] = $this->buildPage($url, $response);
            },
            'rejected' => function ($reason, string $url) use (&$results) {
                $results[$url] = $this->buildFailedPage($url, $reason);
            },
        ]);

        $pool->promise()->wait();

        // Порядок обхода сохраняем: сначала стартовая страница, дальше по очереди
        foreach ($urls as $url) {
            if (!isset($results[$url])) continue;
            if ($this->maxPages > 0 && count($this->pages) >= $this->maxPages) break;

            $page = $results[$url];
            $this->pages[] = $page;
            if ($onPage) $onPage($page, count($this->pages));

            if (!empty($page['html'])) {
                $this->extractLinks($page['html'], $url);
            }
        }
    }

    private function buildPage(string $url, ResponseInterface $response): array
    {
        $html    = (string) $response->getBody();
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        return [
            'url'         => $url,
            'status_code' => $response->getStatusCode(),
            'html'        => $html,
            'headers'     => $headers,
            'response_ms' => $this->timings[$url] ?? 0,
            'size_bytes'  => strlen($html),
        ];
    }

    private function buildFailedPage(string $url, mixed $reason): array
    {
        $code = 0;
        if ($reason instanceof RequestException && $reason->getResponse()) {
            $code = $reason->getResponse()->getStatusCode();
        }

        return [
            'url'         => $url,
            'status_code' => $code,
            'html'        => '',
            'headers'     => [],
            'response_ms' => $this->timings[$url] ?? 0,
            'size_bytes'  => 0,
            'error'       => $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason,
        ];
    }

    /** Одиночная загрузка страницы — используется вне обхода */
    public function fetchPage(string $url): ?array
    {
        try {
            UrlGuard::assert($url);
            $start    = microtime(true);
            $response = $this->client->get($url);
            $html     = (string) $response->getBody();

            $headers = [];
            foreach ($response->getHeaders() as $name => $values) {
                $headers[strtolower($name)] = implode(', ', $values);
            }

            return [
                'url'         => $url,
                'status_code' => $response->getStatusCode(),
                'html'        => $html,
                'headers'     => $headers,
                'response_ms' => (int) round((microtime(true) - $start) * 1000),
                'size_bytes'  => strlen($html),
            ];
        } catch (RequestException $e) {
            return [
                'url'         => $url,
                'status_code' => $e->getResponse() ? $e->getResponse()->getStatusCode() : 0,
                'html'        => '',
                'headers'     => [],
                'response_ms' => 0,
                'size_bytes'  => 0,
                'error'       => $e->getMessage(),
            ];
        } catch (\RuntimeException $e) {
            error_log("[Crawler] пропущен $url: " . $e->getMessage());
            return null;
        }
    }

    private function enqueue(string $url): void
    {
        if ($url === '') return;
        if (isset($this->visited[$url]) || isset($this->queued[$url])) return;
        if (!UrlTools::isHtmlUrl($url)) return;

        $this->queued[$url] = true;
        $this->queue[]      = $url;
    }

    private function extractLinks(string $html, string $currentUrl): void
    {
        foreach (UrlTools::extractHrefs($html) as $href) {
            $abs = UrlTools::resolve($href, $currentUrl);
            if ($abs !== null && UrlTools::isSameHost($abs, $this->baseHost)) {
                $this->enqueue($abs);
            }
        }
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

                $locs = $this->extractLocs((string) $resp->getBody());
                $nested = [];

                foreach ($locs as $loc) {
                    // Сначала распознаём вложенную карту: индексы sitemap почти всегда
                    // лежат на том же домене, и проверка домена перехватывала бы их
                    // раньше, а фильтр расширений затем молча выбрасывал .xml
                    if (self::isSitemapUrl($loc)) {
                        $nested[] = $loc;
                    } elseif (UrlTools::isSameHost($loc, $this->baseHost)) {
                        $this->enqueue(UrlTools::normalize($loc));
                    }
                }

                // Вложенные карты сайта тоже забираем параллельно
                $this->seedFromNested(array_slice($nested, 0, 50));
                break;
            } catch (\Exception $e) {
                // Карты сайта нет или она недоступна — обойдёмся ссылками
            }
        }
    }

    /** Ссылка ведёт на карту сайта, а не на страницу */
    private static function isSitemapUrl(string $url): bool
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        return str_ends_with($path, '.xml') || str_ends_with($path, '.xml.gz');
    }

    private function seedFromNested(array $urls): void
    {
        $urls = array_values(array_filter($urls, fn($u) => UrlGuard::isAllowed($u)));
        if (empty($urls)) return;

        $requests = function () use ($urls) {
            foreach ($urls as $url) {
                yield $url => new Request('GET', $url);
            }
        };

        $pool = new Pool($this->client, $requests(), [
            'concurrency' => min($this->concurrency, 4),
            'options'     => ['timeout' => 8, 'http_errors' => false],
            'fulfilled'   => function (ResponseInterface $response) {
                if ($response->getStatusCode() !== 200) return;
                foreach ($this->extractLocs((string) $response->getBody()) as $loc) {
                    if (UrlTools::isSameHost($loc, $this->baseHost)) {
                        $this->enqueue(UrlTools::normalize($loc));
                    }
                }
            },
            'rejected' => function () {
                // Недоступная вложенная карта не должна ломать обход
            },
        ]);

        $pool->promise()->wait();
    }

    /** @return string[] адреса из тегов <loc> */
    private function extractLocs(string $xml): array
    {
        preg_match_all('/<loc>\s*(https?:\/\/[^<]+)\s*<\/loc>/i', $xml, $m);
        return array_map('trim', $m[1] ?? []);
    }

    public function getBaseUrl(): string { return $this->baseUrl; }

    public function getConcurrency(): int { return $this->concurrency; }

    /** Почему обход завершился — для отчёта и логов */
    public function getStopReason(): string { return $this->stopReason; }

    /** Сколько адресов осталось необойдёнными */
    public function getQueueSize(): int { return count($this->queue); }

    /**
     * Порог памяти, после которого обход прекращается.
     * Возвращает 0, если memory_limit не задан — тогда проверка не нужна.
     */
    private function resolveMemoryCeiling(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') return 0;

        $unit  = strtolower(substr($limit, -1));
        $value = (int) $limit;
        $bytes = match ($unit) {
            'g'     => $value * 1024 * 1024 * 1024,
            'm'     => $value * 1024 * 1024,
            'k'     => $value * 1024,
            default => $value,
        };

        return $bytes > 0 ? (int) ($bytes * self::MEMORY_THRESHOLD) : 0;
    }
}
