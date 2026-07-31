<?php
namespace SeoAuditor\Report;

/**
 * Расчёт оценки сайта.
 *
 * Штрафуются УНИКАЛЬНЫЕ проблемы (тип проверки + базовый заголовок), а не
 * постраничные записи: одна ошибка на 78 страницах — это одна задача для
 * разработчика, а не 78 отдельных нарушений.
 */
class Score
{
    private const CRITICAL_PENALTY = 12;
    private const WARNING_PENALTY  = 2;

    private const CATEGORY_CRITICAL_PENALTY = 21;
    private const CATEGORY_WARNING_PENALTY  = 5;

    /**
     * Общая оценка сайта 0–100.
     *
     * @param int $totalPages сколько страниц обойдено — нужно, чтобы учесть
     *                        охват проблемы: одна ошибка на всём сайте весит
     *                        больше, чем такая же на одной странице
     */
    public static function overall(array $issues, int $totalPages = 0): int
    {
        $penalty = 0.0;
        foreach (self::groupStats($issues) as $group) {
            $base = $group['severity'] === 'critical' ? self::CRITICAL_PENALTY : self::WARNING_PENALTY;
            $penalty += $base * self::breadthFactor($group['pages'], $totalPages);
        }
        return self::clamp((int) round(100 - $penalty));
    }

    /** Оценка раздела 0–100 по количеству групп (без учёта охвата) */
    public static function category(int $criticalGroups, int $warningGroups): int
    {
        return self::clamp(100 - $criticalGroups * self::CATEGORY_CRITICAL_PENALTY
                               - $warningGroups * self::CATEGORY_WARNING_PENALTY);
    }

    /**
     * Оценка раздела с учётом охвата.
     *
     * @param array $groups     список групп вида ['severity' => ..., 'count' => N]
     * @param int   $totalPages сколько страниц обойдено
     */
    public static function categoryFromGroups(array $groups, int $totalPages = 0): int
    {
        $penalty = 0.0;
        foreach ($groups as $group) {
            $severity = $group['severity'] ?? '';
            if ($severity !== 'critical' && $severity !== 'warning') continue;

            $base = $severity === 'critical' ? self::CATEGORY_CRITICAL_PENALTY : self::CATEGORY_WARNING_PENALTY;
            $penalty += $base * self::breadthFactor((int) ($group['count'] ?? 1), $totalPages);
        }
        return self::clamp((int) round(100 - $penalty));
    }

    /**
     * Насколько усилить штраф из-за массовости проблемы.
     * Битая ссылка на одной странице и битые ссылки на всём сайте — разные
     * по тяжести вещи, но и штрафовать за каждую страницу отдельно нельзя:
     * для разработчика это всё равно одна задача.
     */
    public static function breadthFactor(int $affectedPages, int $totalPages): float
    {
        if ($totalPages < 5 || $affectedPages <= 1) return 1.0;

        $share = $affectedPages / $totalPages;
        if ($share >= 0.5) return 3.0;
        if ($share >= 0.2) return 2.0;
        if ($share >= 0.05) return 1.5;
        return 1.0;
    }

    /**
     * Группирует проблемы и считает, сколько страниц затронуто каждой.
     * @return array<string, array{severity:string, pages:int}>
     */
    public static function groupStats(array $issues): array
    {
        $groups = [];
        foreach ($issues as $issue) {
            $severity = $issue['severity'] ?? '';
            if ($severity !== 'critical' && $severity !== 'warning') continue;

            $key = self::groupKey($issue);
            if (!isset($groups[$key])) {
                $groups[$key] = ['severity' => $severity, 'pages' => 0];
            }
            $groups[$key]['pages']++;
        }
        return $groups;
    }

    /**
     * Приводит заголовок к базовому виду, отбрасывая числовые детали:
     * «Title слишком короткий: 8 симв.» → «Title слишком короткий»
     */
    public static function baseTitle(string $title): string
    {
        return trim(preg_replace('/:\s*\d.*$/u', '', $title));
    }

    /** Ключ группировки: одинаковые проблемы разных страниц дают один ключ */
    public static function groupKey(array $issue): string
    {
        return ($issue['check_type'] ?? '') . '|' . ($issue['severity'] ?? '')
             . '|' . self::baseTitle($issue['title'] ?? '');
    }

    /**
     * Считает уникальные критичные и важные проблемы.
     * @return array{0:int, 1:int} [критичных, важных]
     */
    public static function countUnique(array $issues): array
    {
        $groups = [];
        foreach ($issues as $issue) {
            $severity = $issue['severity'] ?? '';
            if ($severity !== 'critical' && $severity !== 'warning') continue;
            $groups[self::groupKey($issue)] = $severity;
        }

        $crit = count(array_filter($groups, fn($s) => $s === 'critical'));
        return [$crit, count($groups) - $crit];
    }

    private static function clamp(int $score): int
    {
        return max(0, min(100, $score));
    }
}
