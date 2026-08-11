<?php
use SeoAuditor\Report\Priority;
use SeoAuditor\Report\Score;

// Счётчики — уникальные проблемы (тип + базовый заголовок), не постраничные записи
$sevGroups = [];
foreach ($issues as $i) {
    $sevGroups[Score::groupKey($i)] = $i['severity'];
}
$cntCrit = count(array_filter($sevGroups, fn($s) => $s === 'critical'));
$cntWarn = count(array_filter($sevGroups, fn($s) => $s === 'warning'));
$cntInfo = count(array_filter($sevGroups, fn($s) => $s === 'info'));

$scoreLabel = $score >= 80 ? 'Хорошее состояние' : ($score >= 60 ? 'Требует улучшений' : 'Критические проблемы');

$checkLabels = [
    'seo'          => 'SEO',
    'links'        => 'Внутренние ссылки',
    'yandex_seo'   => 'Яндекс SEO',
    'commercial'   => 'Коммерческие факторы',
    'technical'    => 'Технический аудит',
    'cms'          => 'CMS и технологии',
    'ip_region'    => 'IP и регион',
    'tech_stack'   => 'Технологии',
    'speed'        => 'Скорость загрузки',
    'adaptive'     => 'Адаптивность',
    'vulnerability'=> 'Безопасность',
    'fz152'        => 'Соответствие ФЗ-152',
    'ai_readiness' => 'Готовность к AI-поиску',
];

$grouped = [];
foreach ($issues as $iss) {
    $grouped[$iss['check_type']][] = $iss;
}

function pdfGroup(array $list): array {
    $g = [];
    foreach ($list as $i) {
        $base = Score::baseTitle($i['title']);
        $key  = $i['severity'] . '|' . $base;
        if (!isset($g[$key])) $g[$key] = ['rep'=>$i, 'base'=>$base, 'cnt'=>0, 'urls'=>[]];
        $g[$key]['cnt']++;
        if (!empty($i['url'])) $g[$key]['urls'][] = $i['url'];
    }
    usort($g, fn($a,$b) => ['critical'=>0,'warning'=>1,'info'=>2][$a['rep']['severity']] - ['critical'=>0,'warning'=>1,'info'=>2][$b['rep']['severity']]);
    return array_values($g);
}

$sevLabel = ['critical' => 'Критично', 'warning' => 'Важно', 'info' => 'Инфо'];

// ── План работ ─────────────────────────────────────────────────────────
$tzGroups   = pdfGroup(array_values(array_filter($issues, fn($i) => in_array($i['severity'], ['critical','warning']))));
$byPriority = [1=>[],2=>[],3=>[],4=>[]];
$totalHours = 0;
foreach ($tzGroups as $g) {
    $g['prio'] = Priority::assess($g['rep'], $g['cnt']);
    $byPriority[$g['prio']['priority']][] = $g;
    $totalHours += $g['prio']['hours'];
}
foreach ($byPriority as &$list) {
    usort($list, fn($a,$b) => [-$a['prio']['impact'], $a['prio']['effort']] <=> [-$b['prio']['impact'], $b['prio']['effort']]);
}
unset($list);

$summary = $score >= 80
    ? "Сайт в хорошем состоянии: $score баллов из 100."
    : ($score >= 60
        ? "Сайт в удовлетворительном состоянии ($score из 100), но упускает трафик и позиции из-за найденных проблем."
        : "Состояние сайта требует срочного вмешательства: $score баллов из 100.");
if ($cntCrit > 0) $summary .= " Критических проблем: $cntCrit.";
if (!empty($byPriority[1])) {
    $summary .= ' Первоочередных задач: ' . count($byPriority[1])
              . ' (~' . round(array_sum(array_map(fn($g) => $g['prio']['hours'], $byPriority[1]))) . ' ч).';
}
$summary .= ' Общая оценка трудозатрат: ~' . round($totalHours) . ' ч.';

$dateLong = date('j') . ' ' . ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'][date('n')-1] . ' ' . date('Y');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<style>
@page {
    margin: 20mm 16mm 22mm 16mm;
    header: page-header;
    footer: page-footer;
}
@page :first { header: _blank; footer: _blank; }

htmlpageheader#page-header { border-bottom: 0.5pt solid #1d1f20; padding-bottom: 4px; }
htmlpagefooter#page-footer { border-top: 0.5pt solid #c9c9cc; padding-top: 4px; }

/* Бумажный фон на весь документ: залить только первую страницу mPDF
   надёжно не даёт, а сплошной тон отвечает исходной вёрстке отчёта */
body { font-family: plex; font-size: 9.5pt; color: #1d1f20; line-height: 1.55; background: #f2f2f3; }

/* ── Обложка ── */
.cover-rule { height: 3px; background: #1d1f20; margin-bottom: 34mm; }
.cover-inner { padding: 0; }
.cover-kicker { font-family: oswald; font-size: 9pt; letter-spacing: 3px; text-transform: uppercase; color: #5980a6; margin-bottom: 14px; }
.cover-title { font-family: oswald; font-size: 44pt; font-weight: bold; text-transform: uppercase; color: #1d1f20; line-height: 1.02; margin-bottom: 10px; }
.cover-sub { font-size: 10.5pt; color: #4a4d50; margin-bottom: 34px; }

.cover-score { font-family: oswald; font-size: 92pt; font-weight: bold; line-height: 0.9; color: #1d1f20; }
.cover-score-of { font-size: 9pt; color: #86898d; margin-bottom: 4px; }
.cover-verdict { font-family: oswald; font-size: 16pt; font-weight: bold; text-transform: uppercase; margin-bottom: 30px; }

.cover-stats { width: 100%; border-collapse: collapse; border-top: 0.5pt solid #1d1f20; border-bottom: 0.5pt solid #c9c9cc; margin-bottom: 30px; }
.cover-stats td { padding: 12px 10px 14px 0; border-right: 0.5pt solid #dedee0; vertical-align: top; }
.cover-stats td:last-child { border-right: none; }
/* Только блочные элементы: mPDF не применяет display:block к span,
   и подпись прилипала к числу */
.cover-stats .n { font-family: oswald; font-size: 26pt; font-weight: bold; line-height: 1; }
.cover-stats .c { font-size: 8pt; color: #86898d; }
.cover-stats .crit .n { color: #a32b23; }
.cover-stats .warn .n { color: #8a5a12; }
.cover-stats .info .n { color: #2f5d8a; }

.cover-summary { font-size: 10pt; color: #1d1f20; border-left: 2pt solid #1d1f20; padding: 2px 0 2px 12px; margin-bottom: 34px; }
.cover-brand { font-size: 8.5pt; color: #86898d; border-top: 0.5pt solid #c9c9cc; padding-top: 10px; }

/* ── Колонтитулы ── */
.hdr-l { font-size: 7.5pt; color: #86898d; }
.hdr-r { font-size: 7.5pt; color: #5980a6; text-align: right; }
.ftr-l, .ftr-c, .ftr-r { font-size: 7.5pt; color: #86898d; }
.ftr-c { text-align: center; }
.ftr-r { text-align: right; }

/* ── Заголовки разделов ── */
h1 { font-family: oswald; font-size: 20pt; font-weight: bold; text-transform: uppercase; margin: 0 0 4px; }
h2 {
    font-family: oswald; font-size: 17pt; font-weight: bold; text-transform: uppercase;
    color: #1d1f20; margin: 0 0 12px; padding-bottom: 5px;
    border-bottom: 1.5pt solid #1d1f20;
}
.section-note { font-size: 8.5pt; color: #86898d; margin: -8px 0 14px; }
.label { font-family: oswald; font-size: 8.5pt; letter-spacing: 1.5px; text-transform: uppercase; color: #5980a6; }
p { margin: 0 0 7px; }

/* ── Таблица фактов ── */
.facts { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
.facts td { padding: 7px 10px 7px 0; border-bottom: 0.5pt solid #dedee0; font-size: 9pt; vertical-align: top; }
.facts .k { color: #5980a6; font-family: oswald; font-size: 8pt; letter-spacing: 1px; text-transform: uppercase; width: 36%; }
.facts .ok  { color: #2f6b42; }
.facts .bad { color: #a32b23; }

/* ── Оценка по направлениям ── */
.cats { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
.cats td { padding: 8px 10px 8px 0; border-bottom: 0.5pt solid #dedee0; font-size: 9.5pt; }
.cats .name { font-family: oswald; font-size: 11pt; text-transform: uppercase; }
.cats .pct { font-family: oswald; font-size: 14pt; font-weight: bold; text-align: right; width: 50px; }
.cats .good { color: #2f6b42; } .cats .warn { color: #8a5a12; } .cats .bad { color: #a32b23; }

/* ── Находки ── */
.issue { margin-bottom: 10px; page-break-inside: avoid; }
.issue-meta { font-size: 8pt; margin-bottom: 3px; }
.tag { font-family: oswald; font-size: 7.5pt; letter-spacing: 1px; text-transform: uppercase; padding: 1px 5px; border: 0.5pt solid #86898d; }
.tag-critical { color: #a32b23; border-color: #a32b23; }
.tag-warning  { color: #8a5a12; border-color: #8a5a12; }
.tag-info     { color: #2f5d8a; border-color: #2f5d8a; }
.issue-title { font-family: oswald; font-size: 12pt; text-transform: uppercase; margin-bottom: 3px; }
.issue-desc { font-size: 9pt; color: #4a4d50; margin-bottom: 5px; }
.issue-rec { font-size: 9pt; border-left: 1.5pt solid #2f6b42; padding: 2px 0 2px 9px; margin-bottom: 4px; }
.issue-rec .h { font-family: oswald; font-size: 7.5pt; letter-spacing: 1px; text-transform: uppercase; color: #2f6b42; }
.issue-urls { font-size: 7.5pt; color: #86898d; }

/* ── План работ ── */
.prio-head { margin: 16px 0 10px; padding-bottom: 4px; border-bottom: 1.5pt solid #1d1f20; page-break-after: avoid; }
.prio-head .p { font-family: oswald; font-size: 15pt; font-weight: bold; }
.prio-head .p1 { color: #a32b23; } .prio-head .p2 { color: #8a5a12; }
.prio-head .p3 { color: #2f5d8a; } .prio-head .p4 { color: #86898d; }
.prio-head .t { font-family: oswald; font-size: 15pt; font-weight: bold; text-transform: uppercase; }
.prio-head .m { font-size: 8pt; color: #86898d; }

.task { margin-bottom: 12px; page-break-inside: avoid; }
.task-num { font-family: oswald; font-size: 15pt; font-weight: bold; color: #c9c9cc; }
</style>
</head>
<body>

<htmlpageheader name="page-header">
<table width="100%"><tr>
  <td class="hdr-l">SEO и технический аудит — <?= htmlspecialchars($host) ?></td>
  <td class="hdr-r">integrom.ru</td>
</tr></table>
</htmlpageheader>

<htmlpagefooter name="page-footer">
<table width="100%"><tr>
  <td class="ftr-l"><?= $dateLong ?></td>
  <td class="ftr-c">{PAGENO} / {nbpg}</td>
  <td class="ftr-r">Оценка: <?= $score ?> из 100</td>
</tr></table>
</htmlpagefooter>

<!-- ═══ ОБЛОЖКА ═══
     Размеры шрифтов заданы в разметке: mPDF не применяет к вложенным
     элементам таблицы селекторы вида «.cover-stats .n» -->
<div class="cover-rule"></div>

<div class="cover-kicker">SEO и технический аудит &nbsp;/&nbsp; <?= $dateLong ?></div>
<div class="cover-title"><?= htmlspecialchars($host) ?></div>
<div class="cover-sub"><?= htmlspecialchars($url) ?> &nbsp;·&nbsp; проверено страниц: <?= count($pages) ?></div>

<div class="cover-score"><?= $score ?></div>
<div class="cover-score-of">из 100</div>
<div class="cover-verdict"><?= $scoreLabel ?></div>

<?php
$coverStats = [
    ['#a32b23', $cntCrit,                 'Критических'],
    ['#8a5a12', $cntWarn,                 'Предупреждений'],
    ['#2f5d8a', $cntInfo,                 'Рекомендаций'],
    ['#1d1f20', '~' . round($totalHours), 'Часов на исправление'],
];
?>
<table class="cover-stats"><tr>
  <?php foreach ($coverStats as $i => [$color, $value, $caption]): ?>
  <td<?= $i < 3 ? '' : ' style="border-right:none"' ?>>
    <div style="font-family:oswald; font-size:26pt; font-weight:bold; line-height:1; color:<?= $color ?>"><?= $value ?></div>
    <div style="font-size:8pt; color:#86898d"><?= $caption ?></div>
  </td>
  <?php endforeach; ?>
</tr></table>

<div class="cover-summary"><?= htmlspecialchars($summary) ?></div>

<div class="cover-brand">
  Подготовлено: <b>integrom.ru</b> &nbsp;&nbsp; +7 929 095-63-93 &nbsp;&nbsp; ai@integrom.ru
</div>

<!-- ═══ ОБЩАЯ ИНФОРМАЦИЯ ═══ -->
<pagebreak />
<h2>Общая информация</h2>

<table class="facts">
<?php
$ai   = $siteData['ai_readiness'] ?? null;
$rows = [
    ['CMS',              $siteData['cms'] ?? '—', null],
    ['IP и хостинг',     ($siteData['ip'] ?? '—') . ' · ' . mb_substr($siteData['isp'] ?? '', 0, 34), null],
    ['Регион',           trim(($siteData['country'] ?? '') . ' ' . ($siteData['city'] ?? '')) ?: '—', null],
    ['Веб-сервер',       mb_substr($siteData['server'] ?? '—', 0, 40), null],
    ['Ответ сервера',    ($siteData['response_ms'] ?? 0) ? $siteData['response_ms'] . ' мс' : '—', null],
    ['Мобильная версия', ($siteData['mobile_friendly'] ?? false) ? 'есть' : 'нет', (bool)($siteData['mobile_friendly'] ?? false)],
    ['Аналитика',        ($siteData['analytics_list'] ?? '') ?: '—', null],
    ['Карта сайта',      ($siteData['sitemap_url'] ?? '') ? (($siteData['sitemap_count'] ?? 0) . ' адресов') : 'не найдена', ($siteData['sitemap_url'] ?? '') !== ''],
    ['Яндекс.Метрика',   ($siteData['yandex_metrika'] ?? false) ? 'установлена' : 'не найдена', (bool)($siteData['yandex_metrika'] ?? false)],
    ['Яндекс.Вебмастер', ($siteData['yandex_webmaster_verified'] ?? false) ? 'подтверждён' : 'не подтверждён', (bool)($siteData['yandex_webmaster_verified'] ?? false)],
    ['Schema.org',       ($siteData['yandex_schema']['org'] ?? false) ? 'Organization найдена' : 'не найдена', (bool)($siteData['yandex_schema']['org'] ?? false)],
];
if (isset($siteData['commercial_score'])) {
    $cs = (int) $siteData['commercial_score'];
    $rows[] = ['Коммерческие факторы', $cs . '%', $cs >= 50];
}
if ($ai) {
    $blocked = count($ai['blocked_bots'] ?? []);
    $rows[] = ['Доступ AI-краулеров',  $blocked === 0 ? 'открыт' : "заблокировано: $blocked", $blocked === 0];
    $rows[] = ['Покрытие Schema.org',  ($ai['schema_coverage'] ?? 0) . '% страниц', ($ai['schema_coverage'] ?? 0) >= 50];
}
foreach ($rows as [$k, $v, $ok]):
    $cls = $ok === null ? '' : ($ok ? 'ok' : 'bad');
?>
<tr>
  <td class="k"><?= htmlspecialchars($k) ?></td>
  <td class="<?= $cls ?>"><?= htmlspecialchars($v) ?></td>
</tr>
<?php endforeach; ?>
</table>

<!-- ═══ РАЗДЕЛЫ ═══ -->
<?php foreach ($checkLabels as $type => $label):
    if (empty($grouped[$type])) continue;
    $groups = pdfGroup($grouped[$type]);
    $crit = count(array_filter($grouped[$type], fn($i) => $i['severity']==='critical'));
    $warn = count(array_filter($grouped[$type], fn($i) => $i['severity']==='warning'));
    $info = count(array_filter($grouped[$type], fn($i) => $i['severity']==='info'));
?>
<pagebreak />
<h2><?= htmlspecialchars($label) ?></h2>
<div class="section-note">
  <?php
  $noteParts = [];
  if ($crit) $noteParts[] = "$crit критических";
  if ($warn) $noteParts[] = "$warn предупреждений";
  if ($info) $noteParts[] = "$info рекомендаций";
  echo implode(' · ', $noteParts) ?: 'замечаний нет';
  ?>
</div>

<?php foreach ($groups as $g):
    $rep  = $g['rep'];
    $urls = array_unique(array_slice($g['urls'], 0, 3));
    $sev  = $rep['severity'];
?>
<div class="issue">
  <div class="issue-meta">
    <span class="tag tag-<?= $sev ?>"><?= $sevLabel[$sev] ?></span>
    <?php if ($g['cnt'] > 1): ?>&nbsp;&nbsp;<span style="color:#86898d"><?= $g['cnt'] ?> страниц</span><?php endif; ?>
  </div>
  <div class="issue-title"><?= htmlspecialchars($g['base']) ?></div>
  <?php if (!empty($rep['description'])): ?>
  <div class="issue-desc"><?= htmlspecialchars($rep['description']) ?></div>
  <?php endif; ?>
  <?php if (!empty($rep['recommendation'])): ?>
  <div class="issue-rec"><div class="h">Что сделать</div><?= htmlspecialchars($rep['recommendation']) ?></div>
  <?php endif; ?>
  <?php if ($urls): ?>
  <div class="issue-urls"><?= implode(' · ', array_map('htmlspecialchars', $urls)) ?></div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endforeach; ?>

<!-- ═══ ПЛАН РАБОТ ═══ -->
<?php if (count($tzGroups) > 0): ?>
<pagebreak />
<h2>План работ</h2>
<div class="section-note">
  <?= count($tzGroups) ?> задач, сгруппированы по приоритету (влияние × трудозатраты).
  Общая оценка: ~<?= round($totalHours) ?> ч. Оценка часов ориентировочная и уточняется после осмотра кода сайта.
</div>

<?php
$prioName  = [1 => 'Срочно', 2 => 'Важно', 3 => 'Планово', 4 => 'По возможности'];
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
  <table width="100%"><tr>
    <td><span class="p p<?= $p ?>">P<?= $p ?></span> &nbsp; <span class="t"><?= $prioName[$p] ?></span></td>
    <td style="text-align:right"><span class="m"><?= count($pGroups) ?> задач · ~<?= round($pHours) ?> ч<br><?= $prioDescr[$p] ?></span></td>
  </tr></table>
</div>

<?php foreach ($pGroups as $g):
    $rep  = $g['rep'];
    $prio = $g['prio'];
    $urls = array_unique(array_slice($g['urls'], 0, 4));
?>
<div class="task">
  <div class="issue-meta">
    <span class="task-num"><?= str_pad((string)$n++, 2, '0', STR_PAD_LEFT) ?></span> &nbsp;
    <span class="tag tag-<?= $rep['severity'] ?>"><?= $sevLabel[$rep['severity']] ?></span> &nbsp;
    <span style="color:#86898d">
      <?= htmlspecialchars($checkLabels[$rep['check_type']] ?? $rep['check_type']) ?> ·
      влияние <?= Priority::impactLabel($prio['impact']) ?> ·
      затраты <?= Priority::effortLabel($prio['effort']) ?> ·
      ~<?= $prio['hours'] ?> ч<?php if ($g['cnt'] > 1): ?> · <?= $g['cnt'] ?> страниц<?php endif; ?>
    </span>
  </div>
  <div class="issue-title"><?= htmlspecialchars($g['base']) ?></div>
  <?php if (!empty($rep['description'])): ?>
  <div class="issue-desc"><?= htmlspecialchars($rep['description']) ?></div>
  <?php endif; ?>
  <?php if (!empty($rep['recommendation'])): ?>
  <div class="issue-rec"><div class="h">Что сделать</div><?= htmlspecialchars($rep['recommendation']) ?></div>
  <?php endif; ?>
  <?php if ($urls): ?>
  <div class="issue-urls"><?= implode(' · ', array_map('htmlspecialchars', $urls)) ?></div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endforeach; ?>
<?php endif; ?>

</body>
</html>
