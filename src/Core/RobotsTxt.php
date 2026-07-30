<?php
namespace SeoAuditor\Core;

/**
 * Разбор robots.txt: группы User-agent и правила Allow/Disallow.
 *
 * Учитывает, что несколько подряд идущих директив User-agent образуют
 * одну группу с общими правилами — как это описано в стандарте.
 */
class RobotsTxt
{
    /** @var array<string, array<int, array{0:string, 1:string}>> агент => [[тип, путь], ...] */
    private array $groups = [];

    private array $sitemaps = [];

    public function __construct(string $content)
    {
        $this->parse($content);
    }

    private function parse(string $content): void
    {
        $currentAgents = [];
        $lastWasAgent  = false;

        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '') continue;

            if (preg_match('/^user-agent\s*:\s*(.+)$/i', $line, $m)) {
                // Новая группа начинается, только если предыдущая строка не была User-agent
                if (!$lastWasAgent) $currentAgents = [];
                $agent = strtolower(trim($m[1]));
                $currentAgents[] = $agent;
                $this->groups[$agent] ??= [];
                $lastWasAgent = true;
                continue;
            }

            if (preg_match('/^(disallow|allow)\s*:\s*(.*)$/i', $line, $m)) {
                $type = strtolower($m[1]);
                $path = trim($m[2]);
                foreach ($currentAgents as $agent) {
                    $this->groups[$agent][] = [$type, $path];
                }
                $lastWasAgent = false;
                continue;
            }

            if (preg_match('/^sitemap\s*:\s*(.+)$/i', $line, $m)) {
                $this->sitemaps[] = trim($m[1]);
            }

            $lastWasAgent = false;
        }
    }

    /** Есть ли в файле явная группа для этого агента */
    public function hasGroup(string $agent): bool
    {
        return isset($this->groups[strtolower($agent)]);
    }

    /**
     * Закрыт ли весь сайт для агента.
     * Проверяет только явную группу агента — без наследования от «*»,
     * так как в robots.txt более специфичная группа полностью заменяет общую.
     */
    public function blocksEverything(string $agent): bool
    {
        $rules = $this->groups[strtolower($agent)] ?? null;
        if ($rules === null) return false;

        $blocked = false;
        foreach ($rules as [$type, $path]) {
            if ($type === 'disallow' && $path === '/') {
                $blocked = true;
            }
            // Allow: / переопределяет запрет — сайт открыт
            if ($type === 'allow' && ($path === '/' || $path === '')) {
                return false;
            }
        }
        return $blocked;
    }

    /** Из перечисленных агентов возвращает тех, кому сайт полностью закрыт */
    public function blockedAgents(array $agents): array
    {
        return array_values(array_filter($agents, fn($a) => $this->blocksEverything($a)));
    }

    /** @return string[] адреса карт сайта из директив Sitemap */
    public function sitemaps(): array
    {
        return $this->sitemaps;
    }
}
