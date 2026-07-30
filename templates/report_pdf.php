<?php
use SeoAuditor\Report\Priority;

// Счётчики — уникальные проблемы (тип + базовый заголовок), не постраничные записи
$sevGroups = [];
foreach ($issues as $i) {
    $base = trim(preg_replace('/:\s*\d.*$/u', '', $i['title']));
    $sevGroups[$i['check_type'] . '|' . $i['severity'] . '|' . $base] = $i['severity'];
}
$cntCrit    = count(array_filter($sevGroups, fn($s) => $s === 'critical'));
$cntWarn    = count(array_filter($sevGroups, fn($s) => $s === 'warning'));
$cntInfo    = count(array_filter($sevGroups, fn($s) => $s === 'info'));
$scoreColor = $score >= 80 ? '#16a34a' : ($score >= 60 ? '#d97706' : '#dc2626');
$scoreLabel = $score >= 80 ? 'Хорошее состояние' : ($score >= 60 ? 'Требует улучшений' : 'Критические проблемы');

$checkLabels = [
    'seo'          => 'SEO аудит',
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
        $base = trim(preg_replace('/:\s*\d.*$/u', '', $i['title']));
        $key  = $i['severity'] . '|' . $base;
        if (!isset($g[$key])) $g[$key] = ['rep'=>$i, 'base'=>$base, 'cnt'=>0, 'urls'=>[]];
        $g[$key]['cnt']++;
        if (!empty($i['url'])) $g[$key]['urls'][] = $i['url'];
    }
    usort($g, fn($a,$b) => ['critical'=>0,'warning'=>1,'info'=>2][$a['rep']['severity']] - ['critical'=>0,'warning'=>1,'info'=>2][$b['rep']['severity']]);
    return array_values($g);
}

$sevLabel = ['critical' => 'Критично', 'warning' => 'Важно', 'info' => 'Инфо'];

// ── Приоритизация для ТЗ ──
$tzList = array_values(array_filter($issues, fn($i) => in_array($i['severity'], ['critical','warning'])));
$tzGroupsRaw = pdfGroup($tzList);
$byPriority = [1=>[],2=>[],3=>[],4=>[]];
$totalHours = 0;
foreach ($tzGroupsRaw as $g) {
    $g['prio'] = Priority::assess($g['rep'], $g['cnt']);
    $byPriority[$g['prio']['priority']][] = $g;
    $totalHours += $g['prio']['hours'];
}
foreach ($byPriority as $p => &$list) {
    usort($list, fn($a,$b) => [-$a['prio']['impact'], $a['prio']['effort']] <=> [-$b['prio']['impact'], $b['prio']['effort']]);
}
unset($list);
$tzTotal = count($tzGroupsRaw);

// ── Резюме ──
$summary = $score >= 80
    ? "Сайт в хорошем состоянии: $score баллов из 100."
    : ($score >= 60
        ? "Сайт в удовлетворительном состоянии ($score/100), но упускает трафик и позиции из-за найденных проблем."
        : "Состояние сайта требует срочного вмешательства: $score баллов из 100.");
if ($cntCrit > 0) $summary .= " Обнаружено критических проблем: $cntCrit.";
if (!empty($byPriority[1])) {
    $p1h = array_sum(array_map(fn($g) => $g['prio']['hours'], $byPriority[1]));
    $summary .= ' Первоочередные задачи (P1): ' . count($byPriority[1]) . ' (~' . round($p1h) . ' ч).';
}
$summary .= ' Общая оценка трудозатрат на устранение: ~' . round($totalHours) . ' ч.';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<style>
@page {
    margin: 20mm 16mm 24mm 16mm;
    header: page-header;
    footer: page-footer;
}
@page :first {
    margin: 0;
    header: _blank;
    footer: _blank;
}

htmlpageheader#page-header { border-bottom: 2px solid #4f46e5; padding-bottom: 5px; }
htmlpagefooter#page-footer { border-top: 1px solid #e4e9f0; padding-top: 5px; }

body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 10pt;
    color: #1e293b;
    line-height: 1.6;
    background: #ffffff;
}

/* ── Обложка ── */
.cover {
    background: #131c33;
    color: #fff;
    width: 100%;
    height: 277mm;
    padding: 0;
    margin: 0;
}
.cover-stripe { width: 100%; height: 5px; background: #4f46e5; margin: 0; }
.cover-inner { padding: 48mm 22mm 22mm; }
.cover-eyebrow { font-size: 8pt; letter-spacing: 4px; text-transform: uppercase; color: #818cf8; margin-bottom: 16px; }
.cover-title { font-size: 32pt; font-weight: bold; color: #ffffff; margin-bottom: 6px; line-height: 1.15; }
.cover-url   { font-size: 11pt; color: #94a7bd; margin-bottom: 6px; }
.cover-date  { font-size: 9pt;  color: #5d7089; margin-bottom: 44px; }

.cover-score-wrap {
    display: inline-block;
    border: 1px solid #2c3a57;
    padding: 20px 36px;
    margin-bottom: 40px;
    background: #1b2745;
}
.cover-score-num { font-size: 56pt; font-weight: bold; line-height: 1; }
.cover-score-lbl { font-size: 9pt; color: #94a7bd; margin-top: 6px; text-transform: uppercase; letter-spacing: 2px; }

.cover-stats { width: 100%; border-collapse: collapse; margin-bottom: 40px; }
.cover-stats td { padding: 16px 18px; text-align: center; border-right: 1px solid #2c3a57; background: #1b2745; }
.cover-stats td:last-child { border-right: none; }
.cover-stats .snum { font-size: 26pt; font-weight: bold; display: block; line-height: 1; }
.cover-stats .slbl { font-size: 7pt; color: #5d7089; text-transform: uppercase; letter-spacing: 2px; margin-top: 5px; }
.cover-stats .crit .snum { color: #f87171; }
.cover-stats .warn .snum { color: #fbbf24; }
.cover-stats .info .snum { color: #60a5fa; }
.cover-stats .hours .snum { color: #a78bfa; }

.cover-summary {
    font-size: 10pt; color: #c6d2e4; line-height: 1.7;
    border-left: 3px solid #4f46e5;
    padding: 8px 16px;
    margin-bottom: 40px;
    background: #17213c;
}
.cover-brand { font-size: 8.5pt; color: #5d7089; border-top: 1px solid #2c3a57; padding-top: 14px; }
.cover-brand strong { color: #818cf8; }

/* ── Колонтитулы ── */
.hdr-left  { font-size: 7.5pt; color: #64748b; }
.hdr-right { font-size: 7.5pt; color: #4f46e5; text-align: right; }
.ftr-left  { font-size: 7.5pt; color: #94a3b8; }
.ftr-mid   { font-size: 7.5pt; color: #94a3b8; text-align: center; }
.ftr-right { font-size: 7.5pt; color: #94a3b8; text-align: right; }

/* ── Типографика ── */
h1 { font-size: 18pt; font-weight: bold; color: #0f172a; margin: 0 0 8px; }
h2 {
    font-size: 13pt; font-weight: bold;
    color: #0f172a;
    margin: 0 0 14px;
    padding: 10px 14px;
    background: #eef1f7;
    border-left: 3px solid #4f46e5;
    text-transform: uppercase;
    letter-spacing: 1px;
}
p { margin: 0 0 8px; }

/* ── Информационная таблица ── */
.info-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
.info-table td { padding: 8px 12px; border-bottom: 1px solid #e4e9f0; font-size: 9.5pt; vertical-align: middle; }
.info-table .k { color: #64748b; font-weight: bold; width: 36%; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.5px; }
.info-table .v { color: #1e293b; }
.info-table .ok  { color: #16a34a; font-weight: bold; }
.info-table .bad { color: #dc2626; font-weight: bold; }

/* ── Заголовок раздела ── */
.section-head {
    background: #eef1f7;
    color: #0f172a;
    padding: 10px 16px;
    margin-bottom: 0;
    font-size: 11pt;
    font-weight: bold;
    border-left: 3px solid #4f46e5;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.section-meta {
    background: #f8fafc;
    border: 1px solid #e4e9f0;
    border-top: none;
    color: #94a3b8;
    font-size: 7.5pt;
    padding: 5px 16px 6px;
    margin-bottom: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* ── Карточки проблем ── */
.issue-card { margin-bottom: 8px; border: 1px solid #e4e9f0; page-break-inside: avoid; background: #ffffff; }
.issue-card-head { padding: 7px 12px 7px 16px; display: block; background: #fbfcfe; }
.issue-card-head.critical { border-left: 3px solid #dc2626; }
.issue-card-head.warning  { border-left: 3px solid #d97706; }
.issue-card-head.info     { border-left: 3px solid #2563eb; }

.badge {
    display: inline-block;
    padding: 2px 9px;
    border-radius: 2px;
    font-size: 7.5pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
    vertical-align: middle;
    margin-right: 6px;
}
.badge.critical { background: #fde8e8; color: #b91c1c; }
.badge.warning  { background: #fdf0dd; color: #b45309; }
.badge.info     { background: #e3edfd; color: #1d4ed8; }
.badge.prio     { background: #e7e5fb; color: #4f46e5; }
.badge.hours    { background: #e3edfd; color: #1d4ed8; }

.issue-title  { font-size: 10pt; font-weight: bold; color: #0f172a; vertical-align: middle; }
.issue-count  { font-size: 7.5pt; color: #94a3b8; margin-left: 6px; }
.issue-body   { padding: 6px 12px 8px 16px; }
.issue-desc   { font-size: 9pt; color: #475569; margin-bottom: 5px; }
.issue-rec    {
    font-size: 9pt; color: #15803d;
    background: #ecf8ef;
    border-left: 2px solid #16a34a;
    padding: 5px 10px;
    margin-bottom: 5px;
}
.issue-urls   { font-size: 7.5pt; color: #94a3b8; margin-top: 3px; }

/* ── ТЗ ── */
.tz-prio-head {
    padding: 9px 14px;
    margin: 14px 0 8px;
    font-size: 11pt;
    font-weight: bold;
    color: #0f172a;
    background: #eef1f7;
    page-break-after: avoid;
}
.tz-prio-head.p1 { border-left: 4px solid #dc2626; }
.tz-prio-head.p2 { border-left: 4px solid #d97706; }
.tz-prio-head.p3 { border-left: 4px solid #2563eb; }
.tz-prio-head.p4 { border-left: 4px solid #94a3b8; }
.tz-prio-sub { font-size: 8pt; color: #64748b; font-weight: normal; }

.tz-card { margin-bottom: 10px; border: 1px solid #e4e9f0; page-break-inside: avoid; background: #ffffff; }
.tz-num-row { padding: 8px 14px; display: block; background: #fbfcfe; }
.tz-num-row.critical { border-left: 3px solid #dc2626; }
.tz-num-row.warning  { border-left: 3px solid #d97706; }
.tz-num { font-size: 17pt; font-weight: bold; color: #cbd5e1; float: left; line-height: 1; margin-right: 12px; }
.tz-num.critical { color: #dc2626; }
.tz-num.warning  { color: #d97706; }
.tz-title-wrap { overflow: hidden; }
.tz-meta-line { font-size: 7.5pt; margin-bottom: 3px; }
.tz-title { font-size: 10.5pt; font-weight: bold; color: #0f172a; }
.tz-body  { padding: 6px 14px 9px 14px; clear: both; }
.tz-desc  { font-size: 9pt; color: #475569; margin-bottom: 6px; }
.tz-rec   {
    font-size: 9pt; color: #15803d;
    background: #ecf8ef;
    border-left: 2px solid #16a34a;
    padding: 5px 10px;
    margin-bottom: 6px;
}
.tz-urls  { font-size: 7.5pt; color: #94a3b8; }

.page-break { page-break-before: always; }
h2 { page-break-after: avoid; }
.section-head { page-break-after: avoid; }
</style>
</head>
<body>

<!-- Колонтитулы -->
<htmlpageheader name="page-header">
<table width="100%"><tr>
  <td class="hdr-left">SEO Аудит &nbsp;/&nbsp; <?= htmlspecialchars($host) ?></td>
  <td class="hdr-right">integrom.ru</td>
</tr></table>
</htmlpageheader>

<htmlpagefooter name="page-footer">
<table width="100%"><tr>
  <td class="ftr-left"><?= $date ?></td>
  <td class="ftr-mid">Страница {PAGENO} из {nbpg}</td>
  <td class="ftr-right">Оценка: <?= $score ?>/100</td>
</tr></table>
</htmlpagefooter>

<!-- ═══ ОБЛОЖКА ═══ -->
<div class="cover">
  <div class="cover-stripe"></div>
  <div class="cover-inner">
    <div class="cover-eyebrow">SEO &amp; Технический Аудит</div>
    <div class="cover-title"><?= htmlspecialchars($host) ?></div>
    <div class="cover-url"><?= htmlspecialchars($url) ?></div>
    <div class="cover-date"><?= $date ?> &nbsp;·&nbsp; Страниц проверено: <?= count($pages) ?></div>

    <div class="cover-score-wrap">
      <div class="cover-score-num" style="color:<?= $score >= 80 ? '#4ade80' : ($score >= 60 ? '#fbbf24' : '#f87171') ?>"><?= $score ?></div>
      <div class="cover-score-lbl"><?= $scoreLabel ?> · /100</div>
    </div>

    <table class="cover-stats">
    <tr>
      <td class="crit"><span class="snum"><?= $cntCrit ?></span><span class="slbl">Критических</span></td>
      <td class="warn"><span class="snum"><?= $cntWarn ?></span><span class="slbl">Предупреждений</span></td>
      <td class="info"><span class="snum"><?= $cntInfo ?></span><span class="slbl">Рекомендаций</span></td>
      <td class="hours"><span class="snum">~<?= round($totalHours) ?></span><span class="slbl">Часов работ</span></td>
    </tr>
    </table>

    <div class="cover-summary"><?= htmlspecialchars($summary) ?></div>

    <div class="cover-brand">
      Подготовлено: <strong>integrom.ru</strong> &nbsp;&nbsp;+7 929-095-63-93 &nbsp;&nbsp;ai@integrom.ru
    </div>
  </div>
</div>

<!-- ═══ ИНФОРМАЦИЯ О САЙТЕ ═══ -->
<pagebreak />
<h2>Общая информация</h2>

<table class="info-table">
<?php
$ai = $siteData['ai_readiness'] ?? null;
$rows = [
    ['CMS',              $siteData['cms'] ?? '—', null],
    ['IP / Хостинг',     ($siteData['ip']??'—').' · '.mb_substr($siteData['isp']??'—',0,35), null],
    ['Регион',           trim(($siteData['country']??'').(!empty($siteData['city']) ? ' '.$siteData['city'] : '')) ?: '—', null],
    ['Веб-сервер',       mb_substr($siteData['server']??'—',0,40), null],
    ['Ответ сервера',    ($siteData['response_ms']??0) ? $siteData['response_ms'].' мс' : '—', null],
    ['Мобильная версия', ($siteData['mobile_friendly']??false) ? 'Есть' : 'Нет', ($siteData['mobile_friendly']??false)],
    ['Аналитика',        ($siteData['analytics_list']??'') ?: '—', null],
    ['Sitemap',          ($siteData['sitemap_url']??'') ? 'Найден ('.(($siteData['sitemap_count']??0)).' URL)' : 'Не найден', ($siteData['sitemap_url']??'')!=''],
    ['Яндекс.Метрика',   ($siteData['yandex_metrika']??false) ? 'Установлена' : 'Не найдена', ($siteData['yandex_metrika']??false)],
    ['Яндекс.Вебмастер', ($siteData['yandex_webmaster_verified']??false) ? 'Подтверждён' : 'Не подтверждён', ($siteData['yandex_webmaster_verified']??false)],
    ['Favicon',          ($siteData['has_favicon']??false) ? 'Есть' : 'Нет', ($siteData['has_favicon']??false)],
    ['Schema.org',       ($siteData['yandex_schema']['org']??false) ? 'Organization найдена' : 'Не найдена', ($siteData['yandex_schema']['org']??false)],
];
if (isset($siteData['commercial_score'])) {
    $rows[] = ['Коммерческие факторы', $siteData['commercial_score'].'%', $siteData['commercial_score'] >= 50];
}
if ($ai) {
    $rows[] = ['Доступ AI-ботов', empty($ai['blocked_bots']) ? 'Открыт' : 'Заблокировано: '.count($ai['blocked_bots']), empty($ai['blocked_bots'])];
    $rows[] = ['Schema.org покрытие', ($ai['schema_coverage'] ?? 0).'% страниц', ($ai['schema_coverage'] ?? 0) >= 50];
}
foreach ($rows as [$k, $v, $ok]):
    $cls = $ok === null ? 'v' : ($ok ? 'ok' : 'bad');
?>
<tr>
  <td class="k"><?= htmlspecialchars($k) ?></td>
  <td class="<?= $cls ?>"><?= htmlspecialchars($v) ?></td>
</tr>
<?php endforeach; ?>
</table>

<!-- ═══ РАЗДЕЛЫ АУДИТА ═══ -->
<?php foreach ($checkLabels as $type => $label):
    if (empty($grouped[$type])) continue;
    $groups = pdfGroup($grouped[$type]);
    $crit = count(array_filter($grouped[$type], fn($i) => $i['severity']==='critical'));
    $warn = count(array_filter($grouped[$type], fn($i) => $i['severity']==='warning'));
    $info = count(array_filter($grouped[$type], fn($i) => $i['severity']==='info'));
?>
<pagebreak />
<div class="section-head"><?= htmlspecialchars($label) ?></div>
<div class="section-meta">
  <?php if ($crit): ?><?= $crit ?> критических &nbsp;&nbsp;<?php endif; ?>
  <?php if ($warn): ?><?= $warn ?> предупреждений &nbsp;&nbsp;<?php endif; ?>
  <?php if ($info): ?><?= $info ?> рекомендаций &nbsp;&nbsp;<?php endif; ?>
  Всего: <?= count($grouped[$type]) ?>
</div>

<?php foreach ($groups as $g):
    $rep  = $g['rep'];
    $urls = array_unique(array_slice($g['urls'], 0, 3));
    $sev  = $rep['severity'];
?>
<div class="issue-card">
  <div class="issue-card-head <?= $sev ?>">
    <span class="badge <?= $sev ?>"><?= $sevLabel[$sev] ?></span>
    <span class="issue-title"><?= htmlspecialchars($g['base']) ?></span>
    <?php if ($g['cnt'] > 1): ?>
      <span class="issue-count">× <?= $g['cnt'] ?> стр.</span>
    <?php endif; ?>
  </div>
  <?php if (!empty($rep['description']) || !empty($rep['recommendation']) || $urls): ?>
  <div class="issue-body">
    <?php if (!empty($rep['description'])): ?>
      <div class="issue-desc"><?= htmlspecialchars($rep['description']) ?></div>
    <?php endif; ?>
    <?php if (!empty($rep['recommendation'])): ?>
      <div class="issue-rec">&#10003; <?= htmlspecialchars($rep['recommendation']) ?></div>
    <?php endif; ?>
    <?php if ($urls): ?>
      <div class="issue-urls"><?= implode('  ·  ', array_map('htmlspecialchars', $urls)) ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<?php endforeach; ?>

<!-- ═══ ПЛАН РАБОТ (ТЗ) ═══ -->
<?php if ($tzTotal > 0): ?>
<pagebreak />
<h2>Техническое задание на исправление</h2>
<p style="font-size:9pt;color:#64748b;margin-bottom:16px">
  <?= $tzTotal ?> задач, сгруппированы по приоритету (влияние × трудозатраты). Общая оценка: ~<?= round($totalHours) ?> ч.
  Оценка часов ориентировочная, уточняется после осмотра кода сайта.
</p>

<?php
$prioDescr = [
    1 => 'исправить в первую очередь',
    2 => 'включить в ближайший спринт',
    3 => 'выполнять планово',
    4 => 'по остаточному принципу',
];
$n = 1;
foreach ($byPriority as $p => $pGroups):
    if (empty($pGroups)) continue;
    $pHours = array_sum(array_map(fn($g) => $g['prio']['hours'], $pGroups));
?>
<div class="tz-prio-head p<?= $p ?>">
  <?= htmlspecialchars(Priority::priorityLabel($p)) ?>
  <span class="tz-prio-sub">&nbsp;&nbsp;<?= count($pGroups) ?> задач · ~<?= round($pHours) ?> ч · <?= $prioDescr[$p] ?></span>
</div>

<?php foreach ($pGroups as $g):
    $rep  = $g['rep'];
    $prio = $g['prio'];
    $urls = array_unique(array_slice($g['urls'], 0, 5));
    $sev  = $rep['severity'];
?>
<div class="tz-card">
  <div class="tz-num-row <?= $sev ?>">
    <div class="tz-num <?= $sev ?>"><?= $n++ ?>.</div>
    <div class="tz-title-wrap">
      <div class="tz-meta-line">
        <span class="badge <?= $sev ?>"><?= $sevLabel[$sev] ?></span>
        <span class="badge prio">влияние: <?= Priority::impactLabel($prio['impact']) ?> · затраты: <?= Priority::effortLabel($prio['effort']) ?></span>
        <span class="badge hours">~<?= $prio['hours'] ?> ч</span>
        <?php if ($g['cnt'] > 1): ?><span class="issue-count"><?= $g['cnt'] ?> страниц</span><?php endif; ?>
      </div>
      <div class="tz-title"><?= htmlspecialchars($g['base']) ?></div>
    </div>
  </div>
  <div class="tz-body">
    <?php if (!empty($rep['description'])): ?>
      <div class="tz-desc"><?= htmlspecialchars($rep['description']) ?></div>
    <?php endif; ?>
    <?php if (!empty($rep['recommendation'])): ?>
      <div class="tz-rec"><strong>Что сделать:</strong> <?= htmlspecialchars($rep['recommendation']) ?></div>
    <?php endif; ?>
    <?php if ($urls): ?>
      <div class="tz-urls"><?= implode('  ·  ', array_map('htmlspecialchars', $urls)) ?></div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endforeach; ?>
<?php endif; ?>

</body>
</html>
