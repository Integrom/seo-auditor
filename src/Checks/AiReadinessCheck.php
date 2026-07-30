<?php
namespace SeoAuditor\Checks;

use GuzzleHttp\Client;

/**
 * Проверка готовности сайта к AI-поиску (AI Overviews, ChatGPT, Perplexity, Алиса).
 * Проверяет доступ AI-краулеров, llms.txt, покрытие Schema.org и структуру контента.
 */
class AiReadinessCheck extends BaseCheck
{
    private const AI_BOTS = [
        'GPTBot'            => 'OpenAI (ChatGPT)',
        'OAI-SearchBot'     => 'OpenAI Search',
        'ClaudeBot'         => 'Anthropic (Claude)',
        'PerplexityBot'     => 'Perplexity',
        'Google-Extended'   => 'Google Gemini',
        'Amazonbot'         => 'Amazon (Alexa)',
        'Applebot-Extended' => 'Apple Intelligence',
    ];

    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout'     => 8,
            'verify'      => false,
            'http_errors' => false,
        ]);
    }

    public function run(array $pages, array &$siteData): array
    {
        if (empty($pages)) return [];
        $url  = $pages[0]['url'] ?? '';
        $base = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);

        $blockedBots = $this->checkRobotsForAiBots($base, $url);
        $hasLlmsTxt  = $this->checkLlmsTxt($base, $url);
        $schema      = $this->checkSchemaCoverage($pages, $url);
        $structure   = $this->checkContentStructure($pages, $url);

        $siteData['ai_readiness'] = [
            'blocked_bots'    => $blockedBots,
            'llms_txt'        => $hasLlmsTxt,
            'schema_coverage' => $schema['coverage'],
            'schema_types'    => $schema['types'],
            'faq_found'       => $structure['faq'],
            'question_headings' => $structure['questions'],
        ];

        return $this->issues;
    }

    private function checkRobotsForAiBots(string $base, string $url): array
    {
        $blocked = [];
        try {
            $resp = $this->client->get($base . '/robots.txt');
            if ($resp->getStatusCode() !== 200) {
                $this->info('ai_readiness', 'robots.txt не найден — AI-боты не ограничены',
                    'Файл robots.txt отсутствует, все AI-краулеры (ChatGPT, Perplexity, Claude) могут индексировать сайт.',
                    'Создайте robots.txt и осознанно решите, разрешать ли AI-ботам доступ. Для видимости в AI-поиске доступ должен быть открыт.',
                    $base . '/robots.txt'
                );
                return $blocked;
            }

            $robots = (string)$resp->getBody();
            $groups = $this->parseRobotsGroups($robots);

            foreach (self::AI_BOTS as $bot => $label) {
                $botLower = strtolower($bot);
                if (isset($groups[$botLower]) && $this->groupBlocksAll($groups[$botLower])) {
                    $blocked[] = "$bot ($label)";
                }
            }
            // Полная блокировка через User-agent: *
            $starBlocksAll = isset($groups['*']) && $this->groupBlocksAll($groups['*']);

            if ($starBlocksAll) {
                $this->critical('ai_readiness', 'robots.txt блокирует всех роботов',
                    'Директива User-agent: * с Disallow: / закрывает сайт от всех краулеров, включая поисковые и AI-системы.',
                    'Откройте сайт для индексации: уберите Disallow: / для User-agent: * или задайте точечные правила.',
                    $base . '/robots.txt'
                );
            } elseif (!empty($blocked)) {
                $names = implode(', ', $blocked);
                $this->warning('ai_readiness', 'AI-краулеры заблокированы: ' . count($blocked),
                    "В robots.txt заблокированы AI-боты: $names. Сайт не попадёт в ответы соответствующих AI-ассистентов и AI-поиска.",
                    'Если хотите получать трафик из AI-поиска (ChatGPT, Perplexity, AI Overviews) — разрешите доступ этим ботам. Блокировка оправдана только если вы осознанно защищаете контент.',
                    $base . '/robots.txt'
                );
            } else {
                $this->info('ai_readiness', 'AI-краулеры имеют доступ к сайту',
                    'robots.txt не блокирует основные AI-боты (GPTBot, ClaudeBot, PerplexityBot и др.) — сайт может цитироваться в AI-ответах.',
                    'Следите за появлением новых AI-краулеров и держите доступ открытым для видимости в AI-поиске.',
                    $base . '/robots.txt'
                );
            }
        } catch (\Exception $e) {}
        return $blocked;
    }

    // Разбирает robots.txt на группы user-agent => массив правил disallow
    private function parseRobotsGroups(string $robots): array
    {
        $groups = [];
        $currentAgents = [];
        $lastWasAgent  = false;
        foreach (preg_split('/\r?\n/', $robots) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '') continue;
            if (preg_match('/^user-agent:\s*(.+)$/i', $line, $m)) {
                if (!$lastWasAgent) $currentAgents = [];
                $agent = strtolower(trim($m[1]));
                $currentAgents[] = $agent;
                $groups[$agent] = $groups[$agent] ?? [];
                $lastWasAgent = true;
            } elseif (preg_match('/^(dis)?allow:\s*(.*)$/i', $line, $m)) {
                foreach ($currentAgents as $agent) {
                    $groups[$agent][] = [strtolower($m[1] ?: '') === 'dis' ? 'disallow' : 'allow', trim($m[2])];
                }
                $lastWasAgent = false;
            } else {
                $lastWasAgent = false;
            }
        }
        return $groups;
    }

    private function groupBlocksAll(array $rules): bool
    {
        $blocksAll = false;
        foreach ($rules as [$type, $path]) {
            if ($type === 'disallow' && $path === '/') $blocksAll = true;
            if ($type === 'allow' && ($path === '/' || $path === '')) return false;
        }
        return $blocksAll;
    }

    private function checkLlmsTxt(string $base, string $url): bool
    {
        try {
            $resp = $this->client->get($base . '/llms.txt');
            $body = (string)$resp->getBody();
            $has  = $resp->getStatusCode() === 200
                && !str_contains(strtolower(substr($body, 0, 500)), '<html');
            if ($has) {
                $this->info('ai_readiness', 'llms.txt найден',
                    'Файл llms.txt помогает AI-агентам быстро понять структуру и назначение сайта.',
                    'Поддерживайте llms.txt в актуальном состоянии: краткое описание компании, ключевые страницы, контакты.',
                    $base . '/llms.txt'
                );
            } else {
                $this->info('ai_readiness', 'llms.txt отсутствует',
                    'Файл llms.txt не найден. Google заявляет, что он не обязателен для AI-поиска, но он полезен для AI-агентов, просматривающих сайт напрямую.',
                    'Опционально: создайте /llms.txt в формате Markdown с описанием компании и ссылками на ключевые страницы.',
                    $base . '/llms.txt'
                );
            }
            return $has;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkSchemaCoverage(array $pages, string $url): array
    {
        $withSchema = 0;
        $types      = [];
        $total      = 0;

        foreach ($pages as $page) {
            $html = $page['html'] ?? '';
            if (empty($html) || ($page['status_code'] ?? 0) !== 200) continue;
            $total++;

            if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>([\s\S]*?)<\/script>/i', $html, $m)) {
                $withSchema++;
                foreach ($m[1] as $json) {
                    if (preg_match_all('/"@type"\s*:\s*"([^"]+)"/', $json, $tm)) {
                        foreach ($tm[1] as $t) $types[$t] = true;
                    }
                }
            } elseif (str_contains($html, 'itemtype="http') || str_contains($html, "itemtype='http")) {
                $withSchema++;
                if (preg_match_all('/itemtype=["\']https?:\/\/schema\.org\/([^"\']+)["\']/i', $html, $tm)) {
                    foreach ($tm[1] as $t) $types[$t] = true;
                }
            }
        }

        $coverage = $total > 0 ? (int)round($withSchema / $total * 100) : 0;
        $typeList = array_keys($types);

        if ($coverage === 0) {
            $this->warning('ai_readiness', 'Структурированные данные Schema.org не найдены',
                'Ни на одной странице нет разметки Schema.org (JSON-LD или microdata). Поисковики и AI-системы хуже понимают контент без неё.',
                'Добавьте JSON-LD разметку: Organization на главную, BreadcrumbList на все страницы, Product/Service/Article — по типу контента, FAQPage для вопросов-ответов.',
                $url
            );
        } elseif ($coverage < 50) {
            $this->warning('ai_readiness', "Schema.org лишь на $coverage% страниц",
                "Структурированные данные найдены на $withSchema из $total страниц. Типы: " . (implode(', ', array_slice($typeList, 0, 8)) ?: '—'),
                'Расширьте покрытие разметки на все значимые страницы — это повышает шансы попасть в расширенные сниппеты и AI-ответы.',
                $url
            );
        } else {
            $this->info('ai_readiness', "Schema.org покрытие: $coverage% страниц",
                'Типы разметки: ' . (implode(', ', array_slice($typeList, 0, 10)) ?: '—'),
                'Проверьте разметку валидатором Schema.org и в Яндекс.Вебмастере, добавьте недостающие типы (FAQPage, HowTo) где уместно.',
                $url
            );
        }

        return ['coverage' => $coverage, 'types' => $typeList];
    }

    private function checkContentStructure(array $pages, string $url): array
    {
        $questionHeadings = 0;
        $hasFaqSchema     = false;
        $pagesWithLists   = 0;
        $total            = 0;

        foreach ($pages as $page) {
            $html = $page['html'] ?? '';
            if (empty($html) || ($page['status_code'] ?? 0) !== 200) continue;
            $total++;

            if (preg_match_all('/<h[23][^>]*>([^<]*\?)\s*<\/h[23]>/iu', $html, $m)) {
                $questionHeadings += count($m[1]);
            }
            if (stripos($html, 'FAQPage') !== false) $hasFaqSchema = true;
            if (preg_match('/<(ul|ol|table)[\s>]/i', $html)) $pagesWithLists++;
        }

        if (!$hasFaqSchema && $questionHeadings === 0) {
            $this->info('ai_readiness', 'Контент не структурирован под ответы',
                'На сайте нет FAQ-блоков и заголовков-вопросов. AI-системы и голосовые ассистенты предпочитают цитировать чётко структурированные ответы.',
                'Добавьте на ключевые страницы блок «Вопрос-ответ» с разметкой FAQPage: 3–5 реальных вопросов клиентов с краткими ответами (40–60 слов) в начале.',
                $url
            );
        } else {
            $this->info('ai_readiness', 'Контент частично готов к AI-цитированию',
                ($hasFaqSchema ? 'Найдена разметка FAQPage. ' : '') . ($questionHeadings > 0 ? "Заголовков-вопросов: $questionHeadings." : ''),
                'Усильте структуру: краткий прямой ответ в первом абзаце после заголовка-вопроса, далее детали списками и таблицами.',
                $url
            );
        }

        return ['faq' => $hasFaqSchema, 'questions' => $questionHeadings];
    }
}
