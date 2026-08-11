<?php
use SeoAuditor\Report\Priority;
use SeoAuditor\Report\Score;

$grouped = [];
foreach ($issues as $issue) {
    $grouped[$issue['check_type']][] = $issue;
}

$checkLabels = [
    'cms'          => ['label'=>'CMS и технологии','icon'=>'01'],
    'ip_region'    => ['label'=>'IP и регион',      'icon'=>'02'],
    'tech_stack'   => ['label'=>'Технологии',       'icon'=>'03'],
    'seo'          => ['label'=>'SEO',              'icon'=>'04'],
    'links'        => ['label'=>'Внутренние ссылки','icon'=>'05'],
    'technical'    => ['label'=>'Технический',      'icon'=>'06'],
    'speed'        => ['label'=>'Скорость',         'icon'=>'07'],
    'adaptive'     => ['label'=>'Адаптивность',    'icon'=>'08'],
    'fz152'        => ['label'=>'ФЗ-152',          'icon'=>'09'],
    'vulnerability'=> ['label'=>'Безопасность',    'icon'=>'10'],
    'yandex_seo'   => ['label'=>'Яндекс SEO',      'icon'=>'11'],
    'commercial'   => ['label'=>'Коммерческие факторы','icon'=>'12'],
    'ai_readiness' => ['label'=>'AI-готовность',   'icon'=>'13'],
];

$tabs = [
    'seo'       => 'SEO',
    'yandex'    => 'Яндекс',
    'technical' => 'Технический',
    'speed'     => 'Скорость',
    'security'  => 'Безопасность',
    'fz152'     => 'ФЗ-152',
    'ai'        => 'AI-поиск',
];

$tabChecks = [
    'seo'       => ['seo','links'],
    'yandex'    => ['yandex_seo','commercial'],
    'technical' => ['cms','ip_region','tech_stack','technical'],
    'speed'     => ['speed','adaptive'],
    'security'  => ['vulnerability'],
    'fz152'     => ['fz152'],
    'ai'        => ['ai_readiness'],
];

$catLetters = ['seo'=>'A','yandex'=>'B','technical'=>'C','speed'=>'D','security'=>'E','fz152'=>'F','ai'=>'G'];

function countBySeverity(array $issues, array $types, string $sev): int {
    return count(array_filter($issues, fn($i) => in_array($i['check_type'], $types) && $i['severity'] === $sev));
}

function countGroupsBySeverity(array $allGroups, array $types, string $sev): int {
    return count(array_filter($allGroups, fn($g) => in_array($g['rep']['check_type'], $types) && $g['rep']['severity'] === $sev));
}

// Группировка однотипных проблем по базовому заголовку
function groupIssuesByTitle(array $issues): array {
    $groups = [];
    foreach ($issues as $issue) {
        $base = Score::baseTitle($issue['title']);
        $gkey = Score::groupKey($issue);
        if (!isset($groups[$gkey])) {
            $groups[$gkey] = ['rep'=>$issue,'base'=>$base,'count'=>0,'urls'=>[],'all'=>[]];
        }
        $groups[$gkey]['count']++;
        $groups[$gkey]['all'][] = $issue;
        if (!empty($issue['url'])) $groups[$gkey]['urls'][] = $issue['url'];
    }
    return array_values($groups);
}

function categoryHealthFromGroups(array $allGroups, array $types, int $totalPages): int {
    $groups = [];
    foreach ($allGroups as $g) {
        if (!in_array($g['rep']['check_type'], $types, true)) continue;
        $groups[] = ['severity' => $g['rep']['severity'], 'count' => $g['count']];
    }
    return Score::categoryFromGroups($groups, $totalPages);
}

$prevScore = null;
if (!empty($comparison['prev_audit_id'])) {
    $prevRow = \SeoAuditor\Core\Database::query('SELECT score FROM audits WHERE id = ?', [$comparison['prev_audit_id']])->fetch();
    $prevScore = $prevRow ? (int)$prevRow['score'] : null;
}
$scoreDelta = $prevScore !== null ? ($score - $prevScore) : null;
$scoreClass = $score >= 80 ? 'good' : ($score >= 60 ? 'warn' : 'bad');
$scoreLabel = $score >= 80 ? 'Хорошее состояние' : ($score >= 60 ? 'Требует улучшений' : 'Критические проблемы');

$allGroups  = groupIssuesByTitle($issues);
$totalPages = count($pages);

$catHealth = [];
foreach ($tabChecks as $tabId => $types) {
    $catHealth[$tabId] = [
        'h'    => categoryHealthFromGroups($allGroups, $types, $totalPages),
        'crit' => countGroupsBySeverity($allGroups, $types, 'critical'),
        'warn' => countGroupsBySeverity($allGroups, $types, 'warning'),
        'info' => countGroupsBySeverity($allGroups, $types, 'info'),
    ];
}

// ── Приоритизация ──────────────────────────────────────────────────────
$actionGroups = [];
foreach (groupIssuesByTitle(array_values(array_filter($issues, fn($i) => in_array($i['severity'],['critical','warning'])))) as $grp) {
    $grp['prio'] = Priority::assess($grp['rep'], $grp['count']);
    $actionGroups[] = $grp;
}
usort($actionGroups, fn($a, $b) =>
    [$a['prio']['priority'], -$a['prio']['impact'], $a['prio']['effort']]
    <=> [$b['prio']['priority'], -$b['prio']['impact'], $b['prio']['effort']]);

$byPriority = [1=>[],2=>[],3=>[],4=>[]];
foreach ($actionGroups as $grp) $byPriority[$grp['prio']['priority']][] = $grp;
$totalHours = array_sum(array_map(fn($g) => $g['prio']['hours'], $actionGroups));

$quickWins     = array_slice(array_values(array_filter($actionGroups, fn($g) => $g['prio']['quick_win'])), 0, 6);
$quickWinHours = array_sum(array_map(fn($g) => $g['prio']['hours'], $quickWins));
$topIssues     = array_slice(array_values(array_filter($actionGroups, fn($g) => $g['prio']['priority'] <= 2)), 0, 8);

$cntCrit = count(array_filter($allGroups, fn($g) => $g['rep']['severity']==='critical'));
$cntWarn = count(array_filter($allGroups, fn($g) => $g['rep']['severity']==='warning'));
$cntInfo = count(array_filter($allGroups, fn($g) => $g['rep']['severity']==='info'));

// ── Резюме ─────────────────────────────────────────────────────────────
$worstCats = array_filter($catHealth, fn($c) => $c['h'] < 60);
uasort($worstCats, fn($a,$b) => $a['h'] <=> $b['h']);
$worstNames = array_map(fn($k) => $tabs[$k], array_keys(array_slice($worstCats, 0, 3, true)));

$summaryParts = [];
$summaryParts[] = $score >= 80
    ? "Сайт в хорошем состоянии: $score баллов из 100."
    : ($score >= 60
        ? "Сайт в удовлетворительном состоянии ($score/100), но упускает трафик и позиции из-за найденных проблем."
        : "Состояние сайта требует срочного вмешательства: $score баллов из 100.");
if ($cntCrit > 0) {
    $summaryParts[] = "Обнаружено $cntCrit критических проблем" . ($worstNames ? ' — слабые зоны: ' . implode(', ', $worstNames) . '.' : '.');
} elseif ($worstNames) {
    $summaryParts[] = 'Слабые зоны: ' . implode(', ', $worstNames) . '.';
}
if (!empty($quickWins)) {
    $summaryParts[] = 'Начните с раздела «Быстрые победы»: ' . count($quickWins) . ' исправлений (~' . round($quickWinHours) . ' ч работы) дадут заметный эффект.';
}
if (!empty($byPriority[1])) {
    $summaryParts[] = 'Задач с приоритетом P1: ' . count($byPriority[1]) . ' (~' . round(array_sum(array_map(fn($g) => $g['prio']['hours'], $byPriority[1]))) . ' ч).';
}
$execSummary = implode(' ', $summaryParts);

$pdfUrl   = '/api/pdf.php?id=' . htmlspecialchars($audit['uuid'] ?? '', ENT_QUOTES);
$sevLabel = ['critical'=>'Критично','warning'=>'Важно','info'=>'Инфо'];
$sevOrder = ['critical'=>0,'warning'=>1,'info'=>2];
$dateLong = date('j') . ' ' . ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'][date('n')-1] . ' ' . date('Y');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SEO Аудит — <?= htmlspecialchars($host) ?></title>
<link rel="stylesheet" href="/assets/css/fonts.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --paper:   #f2f2f3;
  --ink:     #1d1f20;
  --ink-2:   #4a4d50;
  --ink-3:   #86898d;
  --rule:    #c9c9cc;
  --rule-2:  #dedee0;
  --label:   #5980a6;

  --crit:    #a32b23;
  --warn:    #8a5a12;
  --info:    #2f5d8a;
  --good:    #2f6b42;

  --crit-bg: #f0e2e0;
  --warn-bg: #f1e8d8;
  --info-bg: #e2e9f0;
  --good-bg: #e2ece5;

  --sans:  'IBM Plex Sans', -apple-system, 'Segoe UI', sans-serif;
  --cond:  'Oswald', 'Barlow Condensed', 'Arial Narrow', sans-serif;

  --cross: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11'%3E%3Cpath d='M5.5 0v11M0 5.5h11' stroke='%23b5b5b9' stroke-width='1'/%3E%3C/svg%3E");
}

body {
  background: var(--paper);
  color: var(--ink);
  font-family: var(--sans);
  font-size: 15px;
  line-height: 1.62;
  -webkit-font-smoothing: antialiased;
}
a { color: var(--ink); text-decoration: none; border-bottom: 1px solid var(--rule); }
a:hover { border-bottom-color: var(--ink); }

.wrap { max-width: 1240px; margin: 0 auto; padding: 0 40px 100px; }

/* ── Типографика ── */
.eyebrow {
  font-family: var(--cond);
  font-size: 13px; font-weight: 500;
  letter-spacing: .18em; text-transform: uppercase;
  color: var(--label);
}
.label {
  font-family: var(--cond);
  font-size: 12px; font-weight: 600;
  letter-spacing: .14em; text-transform: uppercase;
  color: var(--label);
}
h1, h2, h3 { font-family: var(--cond); font-weight: 600; letter-spacing: -.01em; line-height: 1.05; }

/* Типографские метки на углах блока */
.marked {
  background-image: var(--cross), var(--cross), var(--cross), var(--cross);
  background-position: -5.5px -5.5px, calc(100% + 5.5px) -5.5px, -5.5px calc(100% + 5.5px), calc(100% + 5.5px) calc(100% + 5.5px);
  background-repeat: no-repeat;
}

/* ── Шапка ── */
.masthead { padding: 46px 0 0; }
.masthead .kicker { display: flex; gap: 14px; align-items: baseline; flex-wrap: wrap; margin-bottom: 22px; }
.masthead .kicker span { color: var(--rule); }
.masthead h1 { font-size: 76px; text-transform: uppercase; margin-bottom: 18px; word-break: break-word; }
.masthead .standfirst { font-size: 19px; line-height: 1.55; max-width: 780px; color: var(--ink-2); }

.head-actions { display: flex; gap: 10px; margin-top: 26px; }
.btn {
  font-family: var(--cond); font-size: 13px; font-weight: 600;
  letter-spacing: .12em; text-transform: uppercase;
  padding: 11px 20px; border: 1px solid var(--ink); background: transparent;
  color: var(--ink); cursor: pointer; border-bottom-width: 1px;
}
.btn:hover { background: var(--ink); color: var(--paper); }

/* ── Полоса показателей ── */
.figures { display: grid; grid-template-columns: repeat(5, 1fr); border-top: 1px solid var(--ink); margin-top: 38px; }
.figures .cell { padding: 22px 22px 26px; border-right: 1px solid var(--rule); }
.figures .cell:last-child { border-right: none; }
.figures .num { font-family: var(--cond); font-size: 46px; font-weight: 600; line-height: 1; }
.figures .cap { font-size: 12.5px; color: var(--ink-3); margin-top: 6px; }
.figures .num.crit { color: var(--crit); }
.figures .num.warn { color: var(--warn); }
.figures .num.info { color: var(--info); }
.figures .num.good { color: var(--good); }

/* ── Вкладки ── */
.tabs { position: sticky; top: 0; z-index: 40; background: var(--paper); border-top: 1px solid var(--ink); border-bottom: 1px solid var(--ink); }
.tabs .inner { max-width: 1240px; margin: 0 auto; padding: 0 40px; display: flex; overflow-x: auto; scrollbar-width: none; }
.tabs .inner::-webkit-scrollbar { display: none; }
.tab-btn {
  font-family: var(--cond); font-size: 13.5px; font-weight: 500;
  letter-spacing: .1em; text-transform: uppercase;
  padding: 14px 18px; border: none; background: none; cursor: pointer;
  color: var(--ink-3); white-space: nowrap; border-bottom: 2px solid transparent;
}
.tab-btn:hover { color: var(--ink); }
.tab-btn.active { color: var(--ink); border-bottom-color: var(--ink); }
.tab-btn .n { font-size: 11px; color: var(--ink-3); margin-left: 6px; }
.tab-btn.has-crit .n { color: var(--crit); }
.tab-content { display: none; }
.tab-content.active { display: block; animation: fade .25s ease both; }
@keyframes fade { from { opacity: 0; transform: translateY(6px) } to { opacity: 1; transform: none } }

/* ── Резюме ── */
.summary { border-left: 3px solid var(--ink); padding: 4px 0 4px 22px; margin: 44px 0 40px; max-width: 900px; }
.summary .label { display: block; margin-bottom: 8px; }
.summary p { font-size: 18px; line-height: 1.6; }

/* ── Оценка и данные о сайте ── */
.overview-top { display: grid; grid-template-columns: 320px 1fr; gap: 0; border-top: 1px solid var(--ink); }
.score-block { padding: 30px 30px 30px 0; border-right: 1px solid var(--rule); }
.score-num { font-family: var(--cond); font-size: 116px; font-weight: 600; line-height: .88; }
.score-num.good { color: var(--good); }
.score-num.warn { color: var(--warn); }
.score-num.bad  { color: var(--crit); }
.score-of { font-size: 13px; color: var(--ink-3); margin-top: 4px; }
.score-verdict { font-family: var(--cond); font-size: 22px; font-weight: 600; text-transform: uppercase; margin-top: 14px; }
.score-bar { height: 6px; background: var(--rule-2); margin-top: 16px; }
.score-bar span { display: block; height: 100%; background: var(--ink); }
.score-delta { font-size: 13px; color: var(--ink-2); margin-top: 10px; }

.facts { display: grid; grid-template-columns: repeat(2, 1fr); }
.fact { padding: 16px 24px; border-bottom: 1px solid var(--rule-2); }
.fact:nth-child(odd) { border-right: 1px solid var(--rule-2); }
.fact .label { display: block; font-size: 11px; margin-bottom: 3px; }
.fact .val { font-size: 15px; word-break: break-word; }

/* ── Раздел ── */
.section-head { display: flex; align-items: baseline; gap: 20px; border-bottom: 2px solid var(--ink); padding-bottom: 12px; margin: 56px 0 0; }
.section-head .letter { font-family: var(--cond); font-size: 13px; color: var(--label); letter-spacing: .12em; }
.section-head h2 { font-size: 40px; text-transform: uppercase; }
.section-head .aside { margin-left: auto; font-size: 13.5px; color: var(--ink-3); text-align: right; }

/* ── Быстрые победы ── */
.wins { display: grid; grid-template-columns: repeat(2, 1fr); border-top: 1px solid var(--rule); margin-top: 0; }
.win { display: flex; gap: 16px; padding: 18px 22px 18px 0; border-bottom: 1px solid var(--rule-2); }
.win:nth-child(odd) { border-right: 1px solid var(--rule-2); padding-right: 22px; }
.win:nth-child(even) { padding-left: 22px; padding-right: 0; }
.win .idx { font-family: var(--cond); font-size: 26px; font-weight: 600; color: var(--rule); line-height: 1; min-width: 30px; }
.win .t { font-weight: 600; font-size: 15px; }
.win .m { font-size: 12.5px; color: var(--ink-3); margin-top: 3px; }

/* ── Категории ── */
.cats { border-top: 1px solid var(--rule); }
.cat-row { display: grid; grid-template-columns: 200px 1fr 190px 64px; gap: 20px; align-items: center; padding: 15px 0; border-bottom: 1px solid var(--rule-2); cursor: pointer; }
.cat-row:hover .cat-name { text-decoration: underline; }
.cat-name { font-family: var(--cond); font-size: 19px; font-weight: 500; text-transform: uppercase; }
.cat-bar { height: 5px; background: var(--rule-2); }
.cat-bar span { display: block; height: 100%; }
.cat-bar span.good { background: var(--good); }
.cat-bar span.warn { background: var(--warn); }
.cat-bar span.bad  { background: var(--crit); }
.cat-counts { font-size: 12.5px; color: var(--ink-3); }
.cat-pct { font-family: var(--cond); font-size: 24px; font-weight: 600; text-align: right; }
.cat-pct.good { color: var(--good); }
.cat-pct.warn { color: var(--warn); }
.cat-pct.bad  { color: var(--crit); }

/* ── Приоритетные проблемы ── */
.top-list { border-top: 1px solid var(--rule); }
.top-row { display: grid; grid-template-columns: 44px 1fr 150px; gap: 18px; padding: 16px 0; border-bottom: 1px solid var(--rule-2); }
.top-row .p { font-family: var(--cond); font-size: 13px; font-weight: 600; letter-spacing: .06em; }
.top-row .p.p1 { color: var(--crit); }
.top-row .p.p2 { color: var(--warn); }
.top-row .t { font-weight: 600; }
.top-row .d { font-size: 13.5px; color: var(--ink-2); margin-top: 3px; }
.top-row .cat { font-size: 12px; color: var(--ink-3); text-align: right; }

/* ── Мелкие метки ── */
.tag {
  display: inline-block; font-family: var(--cond); font-size: 11px; font-weight: 500;
  letter-spacing: .1em; text-transform: uppercase;
  padding: 2px 8px; border: 1px solid currentColor; white-space: nowrap;
}
.tag.critical { color: var(--crit); }
.tag.warning  { color: var(--warn); }
.tag.info     { color: var(--info); }
.tag.new      { color: var(--label); }
.count-note { font-size: 12px; color: var(--ink-3); }

/* ── Карточки проблем ── */
.checks { display: grid; grid-template-columns: 210px 1fr; gap: 40px; margin-top: 34px; }
.toc { position: sticky; top: 62px; align-self: start; }
.toc a { display: flex; gap: 10px; padding: 9px 0; font-size: 13.5px; border-bottom: 1px solid var(--rule-2); color: var(--ink-2); }
.toc a:hover, .toc a.active { color: var(--ink); }
.toc a .c { margin-left: auto; font-size: 11.5px; color: var(--ink-3); }

.check-block { margin-bottom: 44px; scroll-margin-top: 70px; }
.check-block > h3 { font-size: 26px; text-transform: uppercase; border-bottom: 1px solid var(--ink); padding-bottom: 9px; display: flex; align-items: baseline; gap: 12px; }
.check-block > h3 .n { font-family: var(--cond); font-size: 12px; color: var(--label); letter-spacing: .1em; }
.check-block > h3 .badges { margin-left: auto; display: flex; gap: 6px; }

.issue { padding: 20px 0; border-bottom: 1px solid var(--rule-2); }
.issue.is-new { border-left: 2px solid var(--label); padding-left: 16px; }
.issue-head { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; margin-bottom: 8px; }
.issue-title { font-family: var(--cond); font-size: 20px; font-weight: 500; text-transform: uppercase; flex: 1; }
.issue-desc { color: var(--ink-2); margin-bottom: 10px; }
.issue-rec { border-left: 2px solid var(--good); padding: 2px 0 2px 14px; color: var(--ink); }
.issue-rec b { font-family: var(--cond); font-weight: 600; letter-spacing: .04em; text-transform: uppercase; font-size: 12px; color: var(--good); display: block; margin-bottom: 2px; }
.issue-urls { margin-top: 12px; display: flex; flex-wrap: wrap; gap: 6px 14px; }
.issue-urls a { font-size: 12.5px; color: var(--ink-3); border-bottom-color: var(--rule-2); }
.issue-urls a:hover { color: var(--ink); }
.more-btn { font-family: var(--cond); font-size: 11.5px; letter-spacing: .1em; text-transform: uppercase; background: none; border: none; border-bottom: 1px solid var(--rule); color: var(--ink-3); cursor: pointer; padding: 0 0 1px; margin-top: 8px; }
.more-btn:hover { color: var(--ink); border-bottom-color: var(--ink); }

/* ── Таблица страниц ── */
.table-wrap { overflow-x: auto; border-top: 1px solid var(--ink); margin-bottom: 34px; }
table.pages { width: 100%; border-collapse: collapse; font-size: 13px; }
table.pages th {
  font-family: var(--cond); font-size: 11px; font-weight: 600; letter-spacing: .1em;
  text-transform: uppercase; color: var(--label); text-align: left;
  padding: 10px 12px 10px 0; border-bottom: 1px solid var(--rule); white-space: nowrap;
}
table.pages td { padding: 9px 12px 9px 0; border-bottom: 1px solid var(--rule-2); }
table.pages td.n, table.pages th.n { text-align: center; padding-right: 0; }
table.pages tr:hover td { background: #eaeaeb; }
.u-ok { color: var(--good); } .u-warn { color: var(--warn); } .u-bad { color: var(--crit); } .u-na { color: var(--ink-3); }
.path { display: inline-block; max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: middle; }

/* ── План работ ── */
.tz-figures { display: grid; grid-template-columns: repeat(4, 1fr); border-top: 1px solid var(--ink); border-bottom: 1px solid var(--rule); margin-bottom: 10px; }
.tz-figures .cell { padding: 20px 20px 22px 0; border-right: 1px solid var(--rule-2); }
.tz-figures .cell:last-child { border-right: none; }
.tz-figures .cell:not(:first-child) { padding-left: 20px; }
.tz-figures .num { font-family: var(--cond); font-size: 38px; font-weight: 600; line-height: 1; }
.tz-figures .cap { font-size: 12.5px; color: var(--ink-3); margin-top: 5px; }

.prio-head { display: flex; align-items: baseline; gap: 16px; border-bottom: 2px solid var(--ink); padding-bottom: 10px; margin: 40px 0 0; }
.prio-head .p { font-family: var(--cond); font-size: 28px; font-weight: 600; }
.prio-head .p.p1 { color: var(--crit); }
.prio-head .p.p2 { color: var(--warn); }
.prio-head .p.p3 { color: var(--info); }
.prio-head .p.p4 { color: var(--ink-3); }
.prio-head h3 { font-size: 26px; text-transform: uppercase; }
.prio-head .meta { margin-left: auto; font-size: 13px; color: var(--ink-3); text-align: right; }

.task { display: grid; grid-template-columns: 52px 1fr; gap: 18px; padding: 22px 0; border-bottom: 1px solid var(--rule-2); }
.task .num { font-family: var(--cond); font-size: 30px; font-weight: 600; color: var(--rule); line-height: 1; }
.task-meta { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 8px; }
.task-title { font-family: var(--cond); font-size: 22px; font-weight: 500; text-transform: uppercase; margin-bottom: 8px; }
.task-desc { color: var(--ink-2); margin-bottom: 10px; }

/* ── Прогресс ── */
.diff-figures { display: grid; grid-template-columns: repeat(3, 1fr); border-top: 1px solid var(--ink); margin-bottom: 34px; }
.diff-figures .cell { padding: 24px 24px 26px 0; border-right: 1px solid var(--rule-2); }
.diff-figures .cell:last-child { border-right: none; }
.diff-figures .cell:not(:first-child) { padding-left: 24px; }
.diff-figures .num { font-family: var(--cond); font-size: 52px; font-weight: 600; line-height: 1; }
.diff-figures .cap { font-size: 13px; color: var(--ink-3); margin-top: 5px; }
.empty-note { border: 1px solid var(--rule); padding: 44px; text-align: center; color: var(--ink-2); margin-top: 34px; }
.fixed-item { padding: 12px 0; border-bottom: 1px solid var(--rule-2); display: flex; gap: 12px; align-items: baseline; }
.fixed-item .t { text-decoration: line-through; color: var(--ink-3); }

/* ── Панели ── */
.panels { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 0; }
.panel { border-top: 1px solid var(--rule); }
.panel .row { display: flex; gap: 14px; padding: 11px 0; border-bottom: 1px solid var(--rule-2); font-size: 14px; }
.panel .row .k { color: var(--ink-2); }
.panel .row .v { margin-left: auto; font-weight: 600; }
.panel .row .v.no { color: var(--crit); }
.panel .row .v.yes { color: var(--good); }
.panel .row .v.meh { color: var(--warn); }

/* ── Подвал ── */
.colophon { border-top: 1px solid var(--ink); margin-top: 70px; padding: 26px 0 0; display: flex; justify-content: space-between; gap: 24px; flex-wrap: wrap; font-size: 13px; color: var(--ink-3); }
.colophon a { color: var(--ink-2); }

@media (max-width: 1000px) {
  .wrap, .tabs .inner { padding-left: 22px; padding-right: 22px; }
  .masthead h1 { font-size: 48px; }
  .figures { grid-template-columns: repeat(2, 1fr); }
  .figures .cell { border-bottom: 1px solid var(--rule-2); }
  .overview-top { grid-template-columns: 1fr; }
  .score-block { border-right: none; border-bottom: 1px solid var(--rule); padding-right: 0; }
  .facts, .wins, .panels { grid-template-columns: 1fr; }
  .win:nth-child(odd) { border-right: none; padding-right: 0; }
  .win:nth-child(even) { padding-left: 0; }
  .checks { grid-template-columns: 1fr; }
  .toc { display: none; }
  .cat-row { grid-template-columns: 1fr 60px; }
  .cat-bar, .cat-counts { display: none; }
  .tz-figures { grid-template-columns: repeat(2, 1fr); }
  .diff-figures { grid-template-columns: 1fr; }
}
@media print {
  .tabs, .head-actions, .toc { display: none !important; }
  .tab-content { display: block !important; }
  .checks { grid-template-columns: 1fr; }
  body { background: #fff; }
}
</style>
</head>
<body>

<div class="wrap">
  <header class="masthead">
    <div class="kicker eyebrow">
      <span style="color:var(--label)">SEO и технический аудит</span>
      <span>/</span>
      <span style="color:var(--label)"><?= htmlspecialchars($host) ?></span>
      <span>/</span>
      <span style="color:var(--label)"><?= $dateLong ?></span>
    </div>

    <h1><?= htmlspecialchars($host) ?></h1>

    <p class="standfirst">
      Проверено страниц: <?= $totalPages ?>. Найдено <?= $cntCrit ?> критических проблем,
      <?= $cntWarn ?> предупреждений и <?= $cntInfo ?> рекомендаций. Ниже — оценка по направлениям,
      разбор каждой находки и план работ, где задачи упорядочены по приоритету и оценены в часах.
    </p>

    <div class="head-actions">
      <a class="btn" id="pdf-btn" href="<?= $pdfUrl ?>" target="_blank">Скачать PDF</a>
      <button class="btn" onclick="window.print()" type="button">Печать</button>
    </div>

    <div class="figures marked">
      <div class="cell"><div class="num <?= $scoreClass === 'bad' ? 'crit' : ($scoreClass === 'warn' ? 'warn' : 'good') ?>"><?= $score ?></div><div class="cap">Оценка из 100</div></div>
      <div class="cell"><div class="num crit"><?= $cntCrit ?></div><div class="cap">Критических</div></div>
      <div class="cell"><div class="num warn"><?= $cntWarn ?></div><div class="cap">Предупреждений</div></div>
      <div class="cell"><div class="num info"><?= $cntInfo ?></div><div class="cap">Рекомендаций</div></div>
      <div class="cell"><div class="num"><?= round($totalHours) ?></div><div class="cap">Часов на исправление</div></div>
    </div>
  </header>
</div>

<nav class="tabs">
  <div class="inner">
    <button class="tab-btn active" data-tab="overview">Обзор</button>
    <?php foreach ($tabChecks as $tabId => $types):
      $c = countGroupsBySeverity($allGroups, $types, 'critical');
      $w = countGroupsBySeverity($allGroups, $types, 'warning');
    ?>
    <button class="tab-btn <?= $c > 0 ? 'has-crit' : '' ?>" data-tab="<?= $tabId ?>">
      <?= $tabs[$tabId] ?><?php if ($c + $w > 0): ?><span class="n"><?= $c + $w ?></span><?php endif; ?>
    </button>
    <?php endforeach; ?>
    <button class="tab-btn" data-tab="tz">План работ<span class="n"><?= count($actionGroups) ?></span></button>
    <button class="tab-btn" data-tab="progress">Прогресс<?php if ($comparison['has_prev'] ?? false): ?><span class="n"><?= $comparison['fixed_count'] ?></span><?php endif; ?></button>
  </div>
</nav>

<div class="wrap">

<!-- ══════════ ОБЗОР ══════════ -->
<section id="tab-overview" class="tab-content active">

  <div class="summary">
    <span class="label">Резюме для руководителя</span>
    <p><?= htmlspecialchars($execSummary) ?></p>
  </div>

  <div class="overview-top">
    <div class="score-block">
      <div class="score-num <?= $scoreClass ?>"><?= $score ?></div>
      <div class="score-of">из 100</div>
      <div class="score-verdict"><?= $scoreLabel ?></div>
      <div class="score-bar"><span style="width:<?= $score ?>%"></span></div>
      <?php if ($scoreDelta !== null): ?>
      <div class="score-delta">
        Предыдущий аудит: <?= $prevScore ?> —
        <?= $scoreDelta >= 0 ? 'рост на ' . $scoreDelta : 'снижение на ' . abs($scoreDelta) ?> пунктов
      </div>
      <?php endif; ?>
    </div>

    <div class="facts">
      <?php
      $ai = $siteData['ai_readiness'] ?? null;
      $factItems = [
        ['CMS',            $siteData['cms'] ?? '—'],
        ['IP и хостинг',   trim(($siteData['ip'] ?? '—') . ' · ' . mb_substr($siteData['isp'] ?? '', 0, 22))],
        ['Регион',         trim(($siteData['country'] ?? '') . ' ' . ($siteData['city'] ?? '')) ?: '—'],
        ['Веб-сервер',     mb_substr($siteData['server'] ?? '—', 0, 26)],
        ['Ответ сервера',  ($siteData['response_ms'] ?? 0) ? $siteData['response_ms'] . ' мс' : '—'],
        ['Мобильная версия', ($siteData['mobile_friendly'] ?? false) ? 'есть' : 'нет'],
        ['Аналитика',      ($siteData['analytics_list'] ?? '') ?: '—'],
        ['Карта сайта',    ($siteData['sitemap_url'] ?? '') ? (($siteData['sitemap_count'] ?? 0) . ' адресов') : 'не найдена'],
      ];
      foreach ($factItems as [$k, $v]):
      ?>
      <div class="fact">
        <span class="label"><?= htmlspecialchars($k) ?></span>
        <div class="val"><?= htmlspecialchars($v) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!empty($quickWins)): ?>
  <div class="section-head">
    <span class="letter">01</span>
    <h2>Быстрые победы</h2>
    <span class="aside">максимум эффекта за минимум усилий<br>~<?= round($quickWinHours) ?> ч суммарно</span>
  </div>
  <div class="wins">
    <?php foreach ($quickWins as $i => $qw): ?>
    <div class="win">
      <div class="idx"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></div>
      <div>
        <div class="t"><?= htmlspecialchars($qw['base']) ?></div>
        <div class="m">
          <?= htmlspecialchars($checkLabels[$qw['rep']['check_type']]['label'] ?? $qw['rep']['check_type']) ?>
          · ~<?= $qw['prio']['hours'] ?> ч<?php if ($qw['count'] > 1): ?> · <?= $qw['count'] ?> страниц<?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="section-head">
    <span class="letter">02</span>
    <h2>Оценка по направлениям</h2>
    <span class="aside">нажмите на строку, чтобы открыть раздел</span>
  </div>
  <div class="cats">
    <?php foreach ($catHealth as $tabId => $ch):
      $cls = $ch['h'] >= 80 ? 'good' : ($ch['h'] >= 50 ? 'warn' : 'bad');
      $parts = [];
      if ($ch['crit']) $parts[] = $ch['crit'] . ' критичных';
      if ($ch['warn']) $parts[] = $ch['warn'] . ' важных';
      if ($ch['info']) $parts[] = $ch['info'] . ' инфо';
    ?>
    <div class="cat-row" data-tab-link="<?= $tabId ?>">
      <div class="cat-name"><?= $catLetters[$tabId] ?> · <?= $tabs[$tabId] ?></div>
      <div class="cat-bar"><span class="<?= $cls ?>" style="width:<?= $ch['h'] ?>%"></span></div>
      <div class="cat-counts"><?= $parts ? implode(' · ', $parts) : 'без замечаний' ?></div>
      <div class="cat-pct <?= $cls ?>"><?= $ch['h'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($topIssues)): ?>
  <div class="section-head">
    <span class="letter">03</span>
    <h2>Приоритетные проблемы</h2>
    <span class="aside">полный перечень — во вкладке «План работ»</span>
  </div>
  <div class="top-list">
    <?php foreach ($topIssues as $ti): $rep = $ti['rep']; ?>
    <div class="top-row">
      <div class="p p<?= $ti['prio']['priority'] ?>">P<?= $ti['prio']['priority'] ?></div>
      <div>
        <div class="t"><?= htmlspecialchars($ti['base']) ?><?php if ($ti['count'] > 1): ?> <span class="count-note">— <?= $ti['count'] ?> страниц</span><?php endif; ?></div>
        <?php if (!empty($rep['recommendation'])): ?>
        <div class="d"><?= htmlspecialchars(mb_substr($rep['recommendation'], 0, 150)) ?><?= mb_strlen($rep['recommendation']) > 150 ? '…' : '' ?></div>
        <?php endif; ?>
      </div>
      <div class="cat"><?= htmlspecialchars($checkLabels[$rep['check_type']]['label'] ?? $rep['check_type']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (isset($siteData['yandex_metrika']) || $ai): ?>
  <div class="section-head">
    <span class="letter">04</span>
    <h2>Яндекс и AI-поиск</h2>
  </div>
  <div class="panels">
    <?php if (isset($siteData['yandex_metrika'])):
      $yRows = [
        ['Яндекс.Метрика',   ($siteData['yandex_metrika'] ?? false) ? ['установлена','yes'] : ['не найдена','no']],
        ['Яндекс.Вебмастер', ($siteData['yandex_webmaster_verified'] ?? false) ? ['подтверждён','yes'] : ['не подтверждён','meh']],
        ['Favicon',          ($siteData['has_favicon'] ?? false) ? ['есть','yes'] : ['нет','meh']],
        ['Schema.org Organization', ($siteData['yandex_schema']['org'] ?? false) ? ['есть','yes'] : ['нет','meh']],
      ];
      if (isset($siteData['commercial_score'])) {
          $cs = (int) $siteData['commercial_score'];
          $yRows[] = ['Коммерческие факторы', [$cs . '%', $cs >= 60 ? 'yes' : ($cs >= 40 ? 'meh' : 'no')]];
      }
    ?>
    <div>
      <span class="label">Яндекс</span>
      <div class="panel">
        <?php foreach ($yRows as [$k, $v]): ?>
        <div class="row"><span class="k"><?= $k ?></span><span class="v <?= $v[1] ?>"><?= $v[0] ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($ai):
      $blocked = count($ai['blocked_bots'] ?? []);
      $cov     = (int) ($ai['schema_coverage'] ?? 0);
      $aiRows = [
        ['Доступ AI-краулеров', $blocked === 0 ? ['открыт','yes'] : ["заблокировано: $blocked", 'meh']],
        ['llms.txt',            ($ai['llms_txt'] ?? false) ? ['есть','yes'] : ['нет','']],
        ['Покрытие Schema.org', ["$cov% страниц", $cov >= 50 ? 'yes' : ($cov > 0 ? 'meh' : 'no')]],
        ['FAQ-структура',       ($ai['faq_found'] ?? false) ? ['есть','yes'] : ['нет','']],
      ];
    ?>
    <div>
      <span class="label">Готовность к AI-поиску</span>
      <div class="panel">
        <?php foreach ($aiRows as [$k, $v]): ?>
        <div class="row"><span class="k"><?= $k ?></span><span class="v <?= $v[1] ?>"><?= $v[0] ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>

<!-- ══════════ РАЗДЕЛЫ ПРОВЕРОК ══════════ -->
<?php foreach ($tabChecks as $tabId => $types): ?>
<section id="tab-<?= $tabId ?>" class="tab-content">

  <div class="section-head">
    <span class="letter"><?= $catLetters[$tabId] ?></span>
    <h2><?= $tabs[$tabId] ?></h2>
    <span class="aside">
      оценка раздела: <?= $catHealth[$tabId]['h'] ?> из 100<br>
      проверено страниц: <?= $totalPages ?>
    </span>
  </div>

  <div class="checks">
    <aside class="toc">
      <?php foreach ($types as $type):
        if (empty($grouped[$type])) continue;
        $meta = $checkLabels[$type] ?? ['label'=>$type,'icon'=>'—'];
        $crit = count(array_filter($grouped[$type], fn($i) => $i['severity']==='critical'));
      ?>
      <a href="#sec-<?= $tabId ?>-<?= $type ?>"><?= $meta['label'] ?><span class="c"><?= $crit ?: count(groupIssuesByTitle($grouped[$type])) ?></span></a>
      <?php endforeach; ?>
    </aside>

    <div>
      <?php if ($tabId === 'seo' && !empty($siteData['page_seo_metrics'])): ?>
      <span class="label">Метрики страниц — <?= count($siteData['page_seo_metrics']) ?></span>
      <div class="table-wrap" style="margin-top:10px">
        <table class="pages">
          <thead><tr>
            <th>Страница</th><th class="n">Код</th><th class="n">Title</th>
            <th class="n">Description</th><th class="n">H1</th>
            <th class="n">Без alt</th><th class="n">Canonical</th><th class="n">OG</th>
          </tr></thead>
          <tbody>
          <?php foreach ($siteData['page_seo_metrics'] as $pm):
            // Приводим к числу: у недоступных страниц метрики приходят пустыми,
            // а строгое сравнение с нулём тогда не срабатывало
            $tLen   = (int)($pm['title_len'] ?? 0);
            $dLen   = (int)($pm['desc_len'] ?? 0);
            $h1     = (int)($pm['h1_count'] ?? 0);
            $imgs   = (int)($pm['total_images'] ?? 0);
            $noAlt  = (int)($pm['imgs_no_alt'] ?? 0);
            $status = (int)($pm['status'] ?? 0);
            $failed = $status === 0 || $status >= 400;

            $tCls = $tLen===0?'u-bad':($tLen>=50&&$tLen<=70?'u-ok':($tLen<10||$tLen>80?'u-bad':'u-warn'));
            $dCls = $dLen===0?'u-bad':($dLen>=120&&$dLen<=160?'u-ok':($dLen<50||$dLen>200?'u-bad':'u-warn'));
            $hCls = $h1===1?'u-ok':($h1===0?'u-bad':'u-warn');
            $aCls = $imgs===0?'u-na':($noAlt===0?'u-ok':($noAlt<=2?'u-warn':'u-bad'));
            $sCls = $status===200?'u-ok':($status>=300&&$status<400?'u-warn':'u-bad');
            $path = parse_url($pm['url'], PHP_URL_PATH) ?: '/';

            // У недоступной страницы содержимого нет — прочерк честнее нулей
            $altVal = $imgs===0 ? '—' : ($noAlt===0 ? '0' : $noAlt . '/' . $imgs);
          ?>
          <tr>
            <td><a class="path" href="<?= htmlspecialchars($pm['url']) ?>" target="_blank" rel="noopener" title="<?= htmlspecialchars($pm['url']) ?>"><?= htmlspecialchars($path) ?></a></td>
            <td class="n <?= $sCls ?>"><?= $status ?: '—' ?></td>
            <td class="n <?= $failed ? 'u-na' : $tCls ?>"><?= $failed ? '—' : ($tLen ?: '—') ?></td>
            <td class="n <?= $failed ? 'u-na' : $dCls ?>"><?= $failed ? '—' : ($dLen ?: '—') ?></td>
            <td class="n <?= $failed ? 'u-na' : $hCls ?>"><?= $failed ? '—' : $h1 ?></td>
            <td class="n <?= $failed ? 'u-na' : $aCls ?>"><?= $failed ? '—' : $altVal ?></td>
            <td class="n <?= $failed ? 'u-na' : ($pm['has_canonical']?'u-ok':'u-warn') ?>"><?= $failed ? '—' : ($pm['has_canonical']?'да':'нет') ?></td>
            <td class="n <?= $failed || !$pm['has_og'] ? 'u-na' : 'u-ok' ?>"><?= $failed ? '—' : ($pm['has_og']?'да':'—') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <?php foreach ($types as $type):
        if (empty($grouped[$type])) continue;
        $meta       = $checkLabels[$type] ?? ['label'=>$type,'icon'=>'—'];
        $typeIssues = $grouped[$type];
        usort($typeIssues, fn($a,$b) => $sevOrder[$a['severity']] - $sevOrder[$b['severity']]);
        $crit = count(array_filter($typeIssues, fn($i) => $i['severity']==='critical'));
        $warn = count(array_filter($typeIssues, fn($i) => $i['severity']==='warning'));
        $issueGroups = groupIssuesByTitle($typeIssues);
        $gIdx = 0;
      ?>
      <div class="check-block" id="sec-<?= $tabId ?>-<?= $type ?>">
        <h3>
          <span class="n"><?= $meta['icon'] ?></span>
          <?= htmlspecialchars($meta['label']) ?>
          <span class="badges">
            <?php if ($crit): ?><span class="tag critical"><?= $crit ?> критично</span><?php endif; ?>
            <?php if ($warn): ?><span class="tag warning"><?= $warn ?> важно</span><?php endif; ?>
          </span>
        </h3>

        <?php foreach ($issueGroups as $grp):
          $rep   = $grp['rep'];
          $isNew = ($rep['is_new'] ?? 0) && ($comparison['has_prev'] ?? false);
          $urls  = array_unique($grp['urls']);
          $gId   = "g-$tabId-$type-" . $gIdx++;
          $vis   = array_slice($urls, 0, 6);
          $more  = count($urls) - count($vis);
        ?>
        <div class="issue<?= $isNew ? ' is-new' : '' ?>">
          <div class="issue-head">
            <span class="tag <?= $rep['severity'] ?>"><?= $sevLabel[$rep['severity']] ?></span>
            <?php if ($isNew): ?><span class="tag new">новое</span><?php endif; ?>
            <span class="issue-title"><?= htmlspecialchars($grp['base']) ?></span>
            <?php if ($grp['count'] > 1): ?><span class="count-note"><?= $grp['count'] ?> страниц</span><?php endif; ?>
          </div>

          <?php if ($grp['count'] === 1 && !empty($rep['description'])): ?>
          <div class="issue-desc"><?= htmlspecialchars($rep['description']) ?></div>
          <?php endif; ?>

          <?php if (!empty($rep['recommendation'])): ?>
          <div class="issue-rec"><b>Что сделать</b><?= htmlspecialchars($rep['recommendation']) ?></div>
          <?php endif; ?>

          <?php if (!empty($urls)): ?>
          <div class="issue-urls">
            <?php foreach ($vis as $u): ?>
            <a href="<?= htmlspecialchars($u) ?>" target="_blank" rel="noopener" title="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars(parse_url($u, PHP_URL_PATH) ?: '/') ?></a>
            <?php endforeach; ?>
          </div>
          <?php if ($more > 0): ?>
          <div class="issue-urls" id="<?= $gId ?>" style="display:none">
            <?php foreach (array_slice($urls, 6) as $u): ?>
            <a href="<?= htmlspecialchars($u) ?>" target="_blank" rel="noopener" title="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars(parse_url($u, PHP_URL_PATH) ?: '/') ?></a>
            <?php endforeach; ?>
          </div>
          <button class="more-btn" data-target="<?= $gId ?>" data-more="<?= $more ?>">ещё <?= $more ?> страниц</button>
          <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endforeach; ?>

<!-- ══════════ ПЛАН РАБОТ ══════════ -->
<section id="tab-tz" class="tab-content">
  <div class="section-head">
    <span class="letter">ТЗ</span>
    <h2>План работ</h2>
    <span class="aside">задачи упорядочены по приоритету<br>оценка часов ориентировочная</span>
  </div>

  <div class="tz-figures">
    <div class="cell"><div class="num">~<?= round($totalHours) ?></div><div class="cap">Часов суммарно</div></div>
    <div class="cell"><div class="num" style="color:var(--crit)"><?= count($byPriority[1]) ?></div><div class="cap">P1 — срочно</div></div>
    <div class="cell"><div class="num" style="color:var(--warn)"><?= count($byPriority[2]) ?></div><div class="cap">P2 — важно</div></div>
    <div class="cell"><div class="num" style="color:var(--info)"><?= count($byPriority[3]) + count($byPriority[4]) ?></div><div class="cap">P3–P4 — планово</div></div>
  </div>

  <?php
  $prioDescr = [
    1 => 'критично для бизнеса при невысоких трудозатратах',
    2 => 'существенное влияние на трафик и конверсию',
    3 => 'улучшения с умеренным эффектом',
    4 => 'низкий приоритет',
  ];
  $n = 1;
  foreach ($byPriority as $p => $pGroups):
    if (empty($pGroups)) continue;
    $pHours = array_sum(array_map(fn($g) => $g['prio']['hours'], $pGroups));
  ?>
  <div class="prio-head">
    <span class="p p<?= $p ?>">P<?= $p ?></span>
    <h3><?= ['','Срочно','Важно','Планово','По возможности'][$p] ?></h3>
    <span class="meta"><?= count($pGroups) ?> задач · ~<?= round($pHours) ?> ч<br><?= $prioDescr[$p] ?></span>
  </div>

  <?php foreach ($pGroups as $grp):
    $rep   = $grp['rep'];
    $prio  = $grp['prio'];
    $urls  = array_unique($grp['urls']);
    $vis   = array_slice($urls, 0, 8);
    $more  = count($urls) - count($vis);
    $tzId  = 'tz-' . $n;
  ?>
  <div class="task">
    <div class="num"><?= str_pad((string)$n++, 2, '0', STR_PAD_LEFT) ?></div>
    <div>
      <div class="task-meta">
        <span class="tag <?= $rep['severity'] ?>"><?= $sevLabel[$rep['severity']] ?></span>
        <span class="count-note"><?= htmlspecialchars($checkLabels[$rep['check_type']]['label'] ?? $rep['check_type']) ?></span>
        <span class="count-note">·</span>
        <span class="count-note">влияние <?= Priority::impactLabel($prio['impact']) ?></span>
        <span class="count-note">·</span>
        <span class="count-note">затраты <?= Priority::effortLabel($prio['effort']) ?></span>
        <span class="count-note">·</span>
        <span class="count-note">~<?= $prio['hours'] ?> ч</span>
        <?php if ($grp['count'] > 1): ?><span class="count-note">· <?= $grp['count'] ?> страниц</span><?php endif; ?>
      </div>

      <div class="task-title"><?= htmlspecialchars($grp['base']) ?></div>

      <?php if (!empty($rep['description'])): ?>
      <div class="task-desc"><?= htmlspecialchars($rep['description']) ?></div>
      <?php endif; ?>

      <?php if (!empty($rep['recommendation'])): ?>
      <div class="issue-rec"><b>Что сделать</b><?= htmlspecialchars($rep['recommendation']) ?></div>
      <?php endif; ?>

      <?php if (!empty($urls)): ?>
      <div class="issue-urls">
        <?php foreach ($vis as $u): ?>
        <a href="<?= htmlspecialchars($u) ?>" target="_blank" rel="noopener" title="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars(parse_url($u, PHP_URL_PATH) ?: '/') ?></a>
        <?php endforeach; ?>
      </div>
      <?php if ($more > 0): ?>
      <div class="issue-urls" id="<?= $tzId ?>" style="display:none">
        <?php foreach (array_slice($urls, 8) as $u): ?>
        <a href="<?= htmlspecialchars($u) ?>" target="_blank" rel="noopener" title="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars(parse_url($u, PHP_URL_PATH) ?: '/') ?></a>
        <?php endforeach; ?>
      </div>
      <button class="more-btn" data-target="<?= $tzId ?>" data-more="<?= $more ?>">ещё <?= $more ?> страниц</button>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endforeach; ?>
</section>

<!-- ══════════ ПРОГРЕСС ══════════ -->
<section id="tab-progress" class="tab-content">
  <div class="section-head">
    <span class="letter">△</span>
    <h2>Прогресс</h2>
    <span class="aside">сравнение с предыдущим аудитом этого сайта</span>
  </div>

  <?php if (!($comparison['has_prev'] ?? false)): ?>
  <div class="empty-note">
    <p style="font-family:var(--cond);font-size:24px;text-transform:uppercase;margin-bottom:10px">Это первый аудит сайта</p>
    <p style="max-width:460px;margin:0 auto">После исправления ошибок запустите проверку повторно — здесь появится сравнение: что исправлено, что добавилось и что осталось.</p>
  </div>
  <?php else:
    $prevIssues  = \SeoAuditor\Core\Database::query('SELECT * FROM audit_issues WHERE audit_id = ?', [$comparison['prev_audit_id']])->fetchAll();
    $currentKeys = [];
    foreach ($issues as $issue) $currentKeys[$issue['issue_key'] ?? ''] = true;
    $fixedIssues = array_filter($prevIssues, fn($pi) => !isset($currentKeys[$pi['issue_key']]));
    $newIssues   = array_filter($issues, fn($i) => ($i['is_new'] ?? 0) == 1);
    $keptIssues  = array_filter($issues, fn($i) => ($i['is_new'] ?? 0) == 0);
  ?>
  <div class="diff-figures">
    <div class="cell"><div class="num" style="color:var(--good)"><?= count($fixedIssues) ?></div><div class="cap">Исправлено</div></div>
    <div class="cell"><div class="num" style="color:var(--info)"><?= count($newIssues) ?></div><div class="cap">Появилось</div></div>
    <div class="cell"><div class="num" style="color:var(--warn)"><?= count($keptIssues) ?></div><div class="cap">Осталось</div></div>
  </div>

  <?php if (!empty($fixedIssues)): ?>
  <span class="label">Исправленные проблемы</span>
  <div style="border-top:1px solid var(--rule);margin-top:10px;margin-bottom:34px">
    <?php foreach ($fixedIssues as $fi): ?>
    <div class="fixed-item">
      <span class="tag" style="color:var(--good)">исправлено</span>
      <span class="t"><?= htmlspecialchars($fi['title']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($newIssues)): ?>
  <span class="label">Новые проблемы</span>
  <div style="border-top:1px solid var(--rule);margin-top:10px">
    <?php foreach ($newIssues as $ni): ?>
    <div class="issue">
      <div class="issue-head">
        <span class="tag <?= $ni['severity'] ?>"><?= $sevLabel[$ni['severity']] ?></span>
        <span class="issue-title"><?= htmlspecialchars($ni['title']) ?></span>
      </div>
      <?php if (!empty($ni['recommendation'])): ?>
      <div class="issue-rec"><b>Что сделать</b><?= htmlspecialchars($ni['recommendation']) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</section>

<footer class="colophon">
  <div>
    Отчёт подготовлен <a href="https://integrom.ru" target="_blank" rel="noopener">integrom.ru</a> ·
    <?= $dateLong ?> · проверено страниц: <?= $totalPages ?>
  </div>
  <div>
    <a href="tel:+79290956393">+7 929 095-63-93</a> ·
    <a href="mailto:ai@integrom.ru">ai@integrom.ru</a> ·
    <a href="https://t.me/integrom" target="_blank" rel="noopener">@integrom</a>
  </div>
</footer>

</div><!-- /wrap -->

<script>
(function () {
  var buttons  = document.querySelectorAll('.tab-btn');
  var sections = document.querySelectorAll('.tab-content');

  function activate(id) {
    buttons.forEach(function (b) { b.classList.toggle('active', b.dataset.tab === id); });
    sections.forEach(function (s) { s.classList.toggle('active', s.id === 'tab-' + id); });
    history.replaceState(null, '', '#' + id);
  }

  buttons.forEach(function (b) {
    b.addEventListener('click', function () { activate(b.dataset.tab); });
  });

  document.querySelectorAll('[data-tab-link]').forEach(function (el) {
    el.addEventListener('click', function () {
      activate(el.dataset.tabLink);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  });

  var hash = location.hash.slice(1);
  if (hash && document.getElementById('tab-' + hash)) activate(hash);

  document.querySelectorAll('.more-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var box = document.getElementById(btn.dataset.target);
      var hidden = box.style.display === 'none';
      box.style.display = hidden ? 'flex' : 'none';
      btn.textContent = hidden ? 'свернуть' : 'ещё ' + btn.dataset.more + ' страниц';
    });
  });

  // Подсветка оглавления при прокрутке
  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (!e.isIntersecting) return;
      document.querySelectorAll('.toc a').forEach(function (a) {
        a.classList.toggle('active', a.getAttribute('href') === '#' + e.target.id);
      });
    });
  }, { rootMargin: '-25% 0px -65% 0px' });

  document.querySelectorAll('.check-block[id]').forEach(function (el) { observer.observe(el); });
})();
</script>
</body>
</html>
