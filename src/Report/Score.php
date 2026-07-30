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

    /** Общая оценка сайта 0–100 */
    public static function overall(array $issues): int
    {
        [$crit, $warn] = self::countUnique($issues);
        return self::clamp(100 - $crit * self::CRITICAL_PENALTY - $warn * self::WARNING_PENALTY);
    }

    /** Оценка раздела 0–100 — считается по уже подсчитанным группам */
    public static function category(int $criticalGroups, int $warningGroups): int
    {
        return self::clamp(100 - $criticalGroups * self::CATEGORY_CRITICAL_PENALTY
                               - $warningGroups * self::CATEGORY_WARNING_PENALTY);
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
