<?php
namespace SeoAuditor\Report;

/**
 * Оценка проблемы: влияние × трудозатраты → приоритет P1–P4 и ориентировочные часы.
 * Детерминированная эвристика, не требует хранения в БД.
 */
class Priority
{
    // Базовые трудозатраты по типу проверки: 1 — просто, 2 — средне, 3 — сложно
    private const EFFORT_BY_TYPE = [
        'seo'           => 1,
        'yandex_seo'    => 1,
        'ai_readiness'  => 1,
        'fz152'         => 2,
        'technical'     => 2,
        'links'         => 2,
        'commercial'    => 2,
        'vulnerability' => 2,
        'speed'         => 3,
        'adaptive'      => 3,
        'cms'           => 2,
        'tech_stack'    => 2,
        'ip_region'     => 2,
    ];

    // Часы по трудозатратам
    private const HOURS_BY_EFFORT = [1 => 1.0, 2 => 4.0, 3 => 10.0];

    /**
     * @param array $issue    представитель группы (check_type, severity, title)
     * @param int   $count    сколько страниц затронуто
     * @return array{impact:int, effort:int, priority:int, hours:float, quick_win:bool}
     */
    public static function assess(array $issue, int $count = 1): array
    {
        $type     = $issue['check_type'] ?? '';
        $severity = $issue['severity'] ?? 'info';
        $title    = mb_strtolower($issue['title'] ?? '');

        // Влияние: базово от критичности
        $impact = match ($severity) {
            'critical' => 3,
            'warning'  => 2,
            default    => 1,
        };
        // Безопасность и закон бьют по бизнесу сильнее
        if (in_array($type, ['vulnerability', 'fz152']) && $severity === 'warning') {
            $impact = 3;
        }

        // Трудозатраты
        $effort = self::EFFORT_BY_TYPE[$type] ?? 2;

        // Точечные поправки по заголовку
        if (preg_match('/(title|description|alt|favicon|h1|robots\.txt|sitemap|canonical|og[ :-])/u', $title)) {
            $effort = 1;
        }
        if (preg_match('/(https|ssl|перенесите|миграц|редизайн|адаптивн)/u', $title)) {
            $effort = 3;
        }

        // Часы: база × поправка на массовость (правки чаще шаблонные, не постраничные)
        $hours = self::HOURS_BY_EFFORT[$effort];
        if ($count > 20)     $hours *= 2;
        elseif ($count > 5)  $hours *= 1.5;

        // Приоритет: матрица влияние × трудозатраты
        $priority = match (true) {
            $impact === 3 && $effort <= 2 => 1,
            $impact === 3                 => 2,
            $impact === 2 && $effort <= 2 => 2,
            $impact === 2                 => 3,
            $effort === 1                 => 3,
            default                       => 4,
        };

        return [
            'impact'    => $impact,
            'effort'    => $effort,
            'priority'  => $priority,
            'hours'     => round($hours, 1),
            'quick_win' => $impact >= 2 && $effort === 1,
        ];
    }

    public static function priorityLabel(int $p): string
    {
        return match ($p) {
            1 => 'P1 — срочно',
            2 => 'P2 — важно',
            3 => 'P3 — планово',
            default => 'P4 — по возможности',
        };
    }

    public static function impactLabel(int $i): string
    {
        return match ($i) { 3 => 'высокое', 2 => 'среднее', default => 'низкое' };
    }

    public static function effortLabel(int $e): string
    {
        return match ($e) { 1 => 'низкие', 2 => 'средние', default => 'высокие' };
    }
}
