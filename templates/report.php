<?php
use SeoAuditor\Report\Priority;
use SeoAuditor\Report\Score;

$grouped = [];
foreach ($issues as $issue) {
    $grouped[$issue['check_type']][] = $issue;
}

$checkLabels = [
    'cms'          => ['label'=>'CMS и технологии','icon'=>'🔍'],
    'ip_region'    => ['label'=>'IP и регион',      'icon'=>'🌐'],
    'tech_stack'   => ['label'=>'Технологии',       'icon'=>'⚙️'],
    'seo'          => ['label'=>'SEO',              'icon'=>'📊'],
    'links'        => ['label'=>'Внутренние ссылки','icon'=>'🔗'],
    'technical'    => ['label'=>'Технический',      'icon'=>'🔧'],
    'speed'        => ['label'=>'Скорость',         'icon'=>'⚡'],
    'adaptive'     => ['label'=>'Адаптивность',    'icon'=>'📱'],
    'fz152'        => ['label'=>'ФЗ-152',          'icon'=>'📋'],
    'vulnerability'=> ['label'=>'Безопасность',    'icon'=>'🛡️'],
    'yandex_seo'   => ['label'=>'Яндекс SEO',      'icon'=>'Я'],
    'commercial'   => ['label'=>'Коммерческие факторы','icon'=>'💼'],
    'ai_readiness' => ['label'=>'AI-готовность',   'icon'=>'🤖'],
];

$tabs = [
    'seo'       => ['label'=>'SEO',          'icon'=>'🔍'],
    'yandex'    => ['label'=>'Яндекс',       'icon'=>'Я'],
    'technical' => ['label'=>'Технический',  'icon'=>'⚙️'],
    'speed'     => ['label'=>'Скорость',     'icon'=>'⚡'],
    'security'  => ['label'=>'Безопасность', 'icon'=>'🛡️'],
    'fz152'     => ['label'=>'ФЗ-152',      'icon'=>'📋'],
    'ai'        => ['label'=>'AI-поиск',     'icon'=>'🤖'],
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

function countBySeverity(array $issues, array $types, string $sev): int {
    return count(array_filter($issues, fn($i) => in_array($i['check_type'], $types) && $i['severity'] === $sev));
}

// Уникальные проблемы (группы по типу + базовому заголовку) — для счётчиков и оценок
function countGroupsBySeverity(array $allGroups, array $types, string $sev): int {
    return count(array_filter($allGroups, fn($g) => in_array($g['rep']['check_type'], $types) && $g['rep']['severity'] === $sev));
}

// Группировка однотипных issues по базовому заголовку (убираем числовые детали)
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

// Здоровье категории 0–100 — формула в Report\Score, учитывает охват проблем
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
$scoreDelta  = $prevScore !== null ? ($score - $prevScore) : null;
$scoreColor  = $score >= 80 ? 'var(--c-green)' : ($score >= 60 ? 'var(--c-yellow)' : 'var(--c-red)');
$scoreHex    = $score >= 80 ? '#22c55e' : ($score >= 60 ? '#f59e0b' : '#ef4444');
$scoreLabel  = $score >= 80 ? 'Хорошее состояние' : ($score >= 60 ? 'Требует улучшений' : 'Критические проблемы');

// Здоровье категорий
$catMeta = [
    'seo'       => ['name'=>'SEO',          'icon'=>'🔍','cls'=>'seo'],
    'yandex'    => ['name'=>'Яндекс',       'icon'=>'Я', 'cls'=>'yandex'],
    'technical' => ['name'=>'Технический',  'icon'=>'⚙️','cls'=>'technical'],
    'speed'     => ['name'=>'Скорость',     'icon'=>'⚡','cls'=>'speed'],
    'security'  => ['name'=>'Безопасность', 'icon'=>'🛡️','cls'=>'security'],
    'fz152'     => ['name'=>'ФЗ-152',      'icon'=>'📋','cls'=>'fz152'],
    'ai'        => ['name'=>'AI-поиск',     'icon'=>'🤖','cls'=>'ai'],
];
$allGroups = groupIssuesByTitle($issues);

$totalPages = count($pages);

$catHealth = [];
foreach ($tabChecks as $tabId => $types) {
    $crit = countGroupsBySeverity($allGroups, $types, 'critical');
    $warn = countGroupsBySeverity($allGroups, $types, 'warning');
    $info = countGroupsBySeverity($allGroups, $types, 'info');
    $catHealth[$tabId] = [
        'h'    => categoryHealthFromGroups($allGroups, $types, $totalPages),
        'crit' => $crit, 'warn' => $warn, 'info' => $info,
    ];
}

// Радар
$cx = 160; $cy = 140; $R = 96;
$radarAxes = array_keys($catMeta);
$radarPts  = [];
$radarAx   = [];
$radarGrid = [];
$axCount   = count($radarAxes);
foreach ($radarAxes as $i => $axId) {
    $ang = (-90 + $i * 360 / $axCount) * M_PI / 180;
    $h   = $catHealth[$axId]['h'];
    $r   = $h / 100 * $R;
    $radarPts[] = [$cx + $r * cos($ang), $cy + $r * sin($ang)];
    $radarAx[]  = ['x'=>$cx + ($R+24)*cos($ang), 'y'=>$cy + ($R+24)*sin($ang), 'label'=>$catMeta[$axId]['name']];
}
foreach ([25,50,75,100] as $pct) {
    $ring = [];
    foreach ($radarAxes as $i => $axId) {
        $ang    = (-90 + $i * 360 / $axCount) * M_PI / 180;
        $ring[] = [$cx + $pct/100*$R * cos($ang), $cy + $pct/100*$R * sin($ang)];
    }
    $radarGrid[] = $ring;
}
function svgPts(array $pts): string {
    return implode(' ', array_map(fn($p) => round($p[0],1).','.round($p[1],1), $pts));
}

// ── Приоритизация: группы + оценка ──
$actionGroupsRaw = groupIssuesByTitle(array_values(array_filter($issues, fn($i) => in_array($i['severity'],['critical','warning']))));
$actionGroups = [];
foreach ($actionGroupsRaw as $grp) {
    $grp['prio'] = Priority::assess($grp['rep'], $grp['count']);
    $actionGroups[] = $grp;
}
usort($actionGroups, function($a, $b) {
    return [$a['prio']['priority'], -$a['prio']['impact'], $a['prio']['effort']]
       <=> [$b['prio']['priority'], -$b['prio']['impact'], $b['prio']['effort']];
});
$byPriority = [1=>[],2=>[],3=>[],4=>[]];
foreach ($actionGroups as $grp) $byPriority[$grp['prio']['priority']][] = $grp;
$totalHours = array_sum(array_map(fn($g) => $g['prio']['hours'], $actionGroups));

// Quick wins: значимый эффект при минимальных затратах
$quickWins = array_values(array_filter($actionGroups, fn($g) => $g['prio']['quick_win']));
$quickWins = array_slice($quickWins, 0, 6);
$quickWinHours = array_sum(array_map(fn($g) => $g['prio']['hours'], $quickWins));

// Топ проблем для дашборда
$topIssues = array_slice(array_values(array_filter($actionGroups, fn($g) => $g['prio']['priority'] <= 2)), 0, 8);

// check_type → tab
$typeToTab = [];
foreach ($tabChecks as $tabId => $types) {
    foreach ($types as $t) $typeToTab[$t] = $tabId;
}

// Счётчики шапки — уникальные проблемы, не постраничные записи
$cntCrit = count(array_filter($allGroups, fn($g) => $g['rep']['severity']==='critical'));
$cntWarn = count(array_filter($allGroups, fn($g) => $g['rep']['severity']==='warning'));
$cntInfo = count(array_filter($allGroups, fn($g) => $g['rep']['severity']==='info'));

// ── Executive summary ──
$worstCats = array_filter($catHealth, fn($c) => $c['h'] < 60);
uasort($worstCats, fn($a,$b) => $a['h'] <=> $b['h']);
$worstNames = array_map(fn($k) => $catMeta[$k]['name'], array_keys(array_slice($worstCats, 0, 3, true)));

$summaryParts = [];
if ($score >= 80) {
    $summaryParts[] = "Сайт в хорошем состоянии: $score баллов из 100.";
} elseif ($score >= 60) {
    $summaryParts[] = "Сайт в удовлетворительном состоянии ($score/100), но упускает трафик и позиции из-за найденных проблем.";
} else {
    $summaryParts[] = "Состояние сайта требует срочного вмешательства: $score баллов из 100.";
}
if ($cntCrit > 0) {
    $summaryParts[] = "Обнаружено $cntCrit критических проблем" . ($worstNames ? " — слабые зоны: " . implode(', ', $worstNames) . "." : ".");
} elseif ($worstNames) {
    $summaryParts[] = "Слабые зоны: " . implode(', ', $worstNames) . ".";
}
if (!empty($quickWins)) {
    $summaryParts[] = "Начните с раздела «Быстрые победы»: " . count($quickWins) . " исправлений (~" . round($quickWinHours) . " ч работы) дадут заметный эффект.";
}
if (!empty($byPriority[1])) {
    $p1Hours = array_sum(array_map(fn($g) => $g['prio']['hours'], $byPriority[1]));
    $summaryParts[] = "Задач с приоритетом P1: " . count($byPriority[1]) . " (~" . round($p1Hours) . " ч).";
}
$execSummary = implode(' ', $summaryParts);

$pdfUrl = '/api/pdf.php?id=' . htmlspecialchars($audit['uuid'] ?? '', ENT_QUOTES);

$sevLabel = ['critical'=>'Критично','warning'=>'Важно','info'=>'Инфо'];
$sevOrder = ['critical'=>0,'warning'=>1,'info'=>2];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SEO Аудит — <?= htmlspecialchars($host) ?></title>
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
:root {
  --c-red:#dc2626;    --c-red-bg:rgba(220,38,38,.08);
  --c-yellow:#d97706; --c-yellow-bg:rgba(217,119,6,.10);
  --c-blue:#2563eb;   --c-blue-bg:rgba(37,99,235,.08);
  --c-green:#16a34a;  --c-green-bg:rgba(22,163,74,.08);
  --c-purple:#7c3aed; --c-purple-bg:rgba(124,58,237,.09);
  --accent:#4f46e5;   --accent-bg:rgba(79,70,229,.08);
  --text:#0f172a; --text-2:#5b6b7f; --text-3:#94a3b8;
  --border:#e4e9f0; --bg:#f3f5f9; --surface:#ffffff;
  --hero-1:#111c33; --hero-2:#1b2a4a; --hero-3:#312e81;
  --radius:14px; --radius-s:9px;
  --shadow:0 1px 2px rgba(15,23,42,.04), 0 8px 24px -12px rgba(15,23,42,.12);
  --shadow-lg:0 4px 12px rgba(15,23,42,.06), 0 20px 44px -20px rgba(15,23,42,.18);
  --radar-grid:#dbe3ee; --radar-lbl:#5b6b7f;
  --hover:rgba(15,23,42,.03);
}
html[data-theme="dark"] {
  --c-red:#f87171;    --c-red-bg:rgba(248,113,113,.12);
  --c-yellow:#fbbf24; --c-yellow-bg:rgba(251,191,36,.12);
  --c-blue:#60a5fa;   --c-blue-bg:rgba(96,165,250,.12);
  --c-green:#4ade80;  --c-green-bg:rgba(74,222,128,.10);
  --c-purple:#a78bfa; --c-purple-bg:rgba(167,139,250,.12);
  --accent:#818cf8;   --accent-bg:rgba(129,140,248,.12);
  --text:#e5edf6; --text-2:#94a7bd; --text-3:#5d7089;
  --border:#22304a; --bg:#0b1220; --surface:#131d31;
  --hero-1:#0d1526; --hero-2:#14203a; --hero-3:#241f5e;
  --shadow:0 1px 2px rgba(0,0,0,.3), 0 10px 28px -12px rgba(0,0,0,.5);
  --shadow-lg:0 4px 14px rgba(0,0,0,.35), 0 24px 48px -20px rgba(0,0,0,.6);
  --radar-grid:#26344e; --radar-lbl:#94a7bd;
  --hover:rgba(255,255,255,.04);
}
@keyframes tabSlideIn { from { opacity:0; transform:translateY(12px) } to { opacity:1; transform:translateY(0) } }
@keyframes barScaleX  { from { transform:scaleX(0) } to { transform:scaleX(1) } }
@keyframes numPop     { 0% { opacity:0; transform:scale(.85) } 70% { transform:scale(1.05) } 100% { opacity:1; transform:scale(1) } }
@keyframes cardIn     { from { opacity:0; transform:translateY(10px) } to { opacity:1; transform:translateY(0) } }
.tab-content.active { animation:tabSlideIn .3s ease both; }
.cat-bar-fill, .section-progress-fill { transform-origin:left; animation:barScaleX .8s cubic-bezier(.2,.8,.4,1) both; }
.hstat .num { animation:numPop .55s cubic-bezier(.2,.8,.4,1) both; }
.cat-card:nth-child(1){animation:cardIn .4s ease .03s both}.cat-card:nth-child(2){animation:cardIn .4s ease .07s both}
.cat-card:nth-child(3){animation:cardIn .4s ease .11s both}.cat-card:nth-child(4){animation:cardIn .4s ease .15s both}
.cat-card:nth-child(5){animation:cardIn .4s ease .19s both}.cat-card:nth-child(6){animation:cardIn .4s ease .23s both}
.cat-card:nth-child(7){animation:cardIn .4s ease .27s both}
.exec-card, .score-card, .radar-card { animation:cardIn .45s ease .04s both; }

body { font-family:-apple-system,'Segoe UI',system-ui,Roboto,sans-serif; background:var(--bg); color:var(--text); line-height:1.6; font-size:14.5px; transition:background .25s,color .25s; }
a { color:var(--c-blue); text-decoration:none; }
a:hover { text-decoration:underline; }
.wrap { max-width:1240px; margin:0 auto; padding:0 20px 80px; }
.card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); }

/* ── Шапка ── */
.report-header { background:linear-gradient(130deg,var(--hero-1) 0%,var(--hero-2) 55%,var(--hero-3) 100%); color:#fff; padding:38px 0 0; }
.report-header .inner { max-width:1240px; margin:0 auto; padding:0 20px; }
.report-meta { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:24px; margin-bottom:30px; }
.report-site h1 { font-size:25px; font-weight:800; margin-bottom:6px; letter-spacing:-.01em; }
.report-site .sub { opacity:.65; font-size:13px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.report-site .sub a { color:#a5b8fc; }
.report-prepared { display:flex; align-items:center; gap:6px; margin-top:10px; font-size:12px; opacity:.55; }
.report-prepared a { color:#a5b8fc; opacity:1; }
.header-actions { display:flex; gap:10px; align-items:center; }
.hbtn { display:inline-flex; align-items:center; gap:7px; padding:9px 16px; border-radius:10px; font-size:12.5px; font-weight:700; cursor:pointer; border:1px solid rgba(255,255,255,.22); background:rgba(255,255,255,.1); color:#fff; text-decoration:none !important; transition:background .15s; }
.hbtn:hover { background:rgba(255,255,255,.18); }
.hbtn.primary { background:#f97316; border-color:#f97316; }
.hbtn.primary:hover { background:#ea680c; }
.score-wrap { display:flex; align-items:center; gap:20px; background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.13); border-radius:16px; padding:18px 26px; backdrop-filter:blur(4px); }
.score-ring { position:relative; width:80px; height:80px; flex-shrink:0; }
.score-ring svg { transform:rotate(-90deg); }
.score-ring .score-num { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:24px; font-weight:900; }
.score-info .score-label { font-size:17px; font-weight:700; display:block; }
.score-info .score-sub { font-size:12px; opacity:.6; }
.score-history { display:flex; align-items:center; gap:8px; margin-top:6px; font-size:12px; opacity:.85; }
.score-delta.up { color:#4ade80; font-weight:700; }
.score-delta.down { color:#f87171; font-weight:700; }
.header-stats { display:flex; border-top:1px solid rgba(255,255,255,.08); margin-top:6px; }
.hstat { flex:1; padding:16px 20px; border-right:1px solid rgba(255,255,255,.06); text-align:center; }
.hstat:last-child { border-right:none; }
.hstat .num { font-size:29px; font-weight:800; line-height:1; }
.hstat .lbl { font-size:11px; opacity:.5; text-transform:uppercase; letter-spacing:.06em; margin-top:3px; }
.hstat.critical .num { color:#fca5a5; }
.hstat.warning .num  { color:#fde68a; }
.hstat.info .num     { color:#93c5fd; }
.hstat.fixed .num    { color:#4ade80; }

/* ── Вкладки ── */
.tabs-bar { background:var(--surface); position:sticky; top:0; z-index:50; box-shadow:0 1px 0 var(--border), 0 4px 16px -8px rgba(15,23,42,.12); }
.tabs-bar .inner { max-width:1240px; margin:0 auto; padding:0 20px; display:flex; overflow-x:auto; scrollbar-width:none; }
.tabs-bar .inner::-webkit-scrollbar { display:none; }
.tab-btn { padding:15px 17px; font-size:13px; font-weight:600; color:var(--text-2); border:none; background:none; cursor:pointer; white-space:nowrap; border-bottom:3px solid transparent; transition:color .15s,border-color .15s; font-family:inherit; }
.tab-btn:hover { color:var(--text); }
.tab-btn.active { color:var(--accent); border-bottom-color:var(--accent); }
.tab-btn .tb { display:inline-block; margin-left:6px; padding:1px 7px; border-radius:20px; font-size:10px; font-weight:700; background:var(--bg); color:var(--text-2); vertical-align:middle; }
.tab-btn.has-critical .tb { background:var(--c-red-bg); color:var(--c-red); }
.tab-content { display:none; }
.tab-content.active { display:block; }

/* ── Executive summary ── */
.exec-card { border-left:4px solid var(--accent); padding:22px 26px; margin-bottom:20px; }
.exec-card h2 { font-size:12px; font-weight:800; color:var(--accent); text-transform:uppercase; letter-spacing:.08em; margin-bottom:8px; }
.exec-card p { font-size:15px; line-height:1.75; }

/* ── Обзор ── */
.db-top { display:grid; grid-template-columns:5fr 4fr; gap:20px; margin-bottom:20px; }
.score-card { padding:26px; display:flex; align-items:center; gap:28px; }
.db-score-ring { position:relative; width:130px; height:130px; flex-shrink:0; }
.db-score-ring svg { display:block; transform:rotate(-90deg); }
.db-score-ring .track { stroke:var(--border); }
.db-score-num { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; }
.db-score-num .sn { font-size:37px; font-weight:900; line-height:1; }
.db-score-num .sl { font-size:11px; color:var(--text-3); }
.db-score-details h2 { font-size:20px; font-weight:800; margin-bottom:3px; letter-spacing:-.01em; }
.db-score-details p  { font-size:13px; color:var(--text-2); }
.db-score-stats { display:flex; gap:22px; margin-top:16px; }
.db-ss .snum { font-size:22px; font-weight:800; line-height:1; }
.db-ss .slbl { font-size:10px; color:var(--text-3); text-transform:uppercase; letter-spacing:.04em; }
.db-ss.crit .snum { color:var(--c-red); }
.db-ss.warn .snum { color:var(--c-yellow); }
.db-ss.info .snum { color:var(--c-blue); }

.db-info-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; align-content:start; }
.db-info-item { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-s); padding:11px 15px; display:flex; gap:12px; align-items:center; box-shadow:var(--shadow); }
.db-info-icon { font-size:19px; flex-shrink:0; width:26px; text-align:center; }
.db-info-lbl  { font-size:10px; color:var(--text-3); text-transform:uppercase; letter-spacing:.05em; }
.db-info-val  { font-size:13px; font-weight:600; word-break:break-word; }

.db-mid { display:grid; grid-template-columns:330px 1fr; gap:20px; margin-bottom:20px; }
.radar-card { padding:20px; display:flex; flex-direction:column; align-items:center; }
.card-title { font-size:11.5px; font-weight:800; color:var(--text-2); text-transform:uppercase; letter-spacing:.07em; }
.radar-card .card-title { margin-bottom:10px; align-self:flex-start; }
.radar-grid-line { stroke:var(--radar-grid); }
.radar-label { fill:var(--radar-lbl); }

.cat-cards { display:grid; grid-template-columns:1fr 1fr; gap:12px; align-content:start; }
.cat-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-s); padding:14px 17px; cursor:pointer; transition:border-color .15s, box-shadow .15s; display:block; box-shadow:var(--shadow); }
.cat-card:hover { border-color:var(--accent); box-shadow:var(--shadow-lg); text-decoration:none; }
.cat-head { display:flex; align-items:center; gap:10px; margin-bottom:9px; }
.cat-icon { font-size:16px; width:32px; height:32px; border-radius:9px; display:flex; align-items:center; justify-content:center; flex-shrink:0; background:var(--accent-bg); }
.cat-name { font-weight:700; font-size:13.5px; flex:1; color:var(--text); }
.cat-health { font-size:18px; font-weight:900; }
.cat-health.h-good { color:var(--c-green); }
.cat-health.h-ok   { color:var(--c-yellow); }
.cat-health.h-bad  { color:var(--c-red); }
.cat-bar { height:5px; background:var(--bg); border-radius:3px; overflow:hidden; margin-bottom:7px; }
.cat-bar-fill { height:100%; border-radius:3px; }
.cat-counts { display:flex; gap:11px; font-size:11.5px; color:var(--text-2); flex-wrap:wrap; }
.cat-cnt { display:flex; align-items:center; gap:4px; }
.cat-cnt .dot { width:6px; height:6px; border-radius:50%; }
.cat-cnt .dot.c { background:var(--c-red); }
.cat-cnt .dot.w { background:var(--c-yellow); }
.cat-cnt .dot.i { background:var(--c-blue); }

/* ── Quick wins ── */
.qw-card { border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; box-shadow:var(--shadow); margin-bottom:20px; background:var(--surface); }
.qw-head { display:flex; align-items:center; gap:12px; padding:15px 22px; background:linear-gradient(90deg, var(--c-green-bg), transparent); border-bottom:1px solid var(--border); }
.qw-head .card-title { color:var(--c-green); }
.qw-head .qw-sub { font-size:12px; color:var(--text-2); margin-left:auto; }
.qw-grid { display:grid; grid-template-columns:1fr 1fr; }
.qw-item { display:flex; gap:12px; padding:14px 22px; border-bottom:1px solid var(--border); align-items:flex-start; }
.qw-item:nth-child(odd) { border-right:1px solid var(--border); }
.qw-num { width:26px; height:26px; border-radius:50%; background:var(--c-green-bg); color:var(--c-green); font-weight:800; font-size:13px; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:2px; }
.qw-title { font-weight:600; font-size:13.5px; margin-bottom:2px; }
.qw-meta { font-size:11.5px; color:var(--text-3); }

/* ── Топ проблем ── */
.top-issues { overflow:hidden; margin-bottom:20px; }
.top-issues-head { background:var(--bg); border-bottom:1px solid var(--border); padding:14px 22px; }
.top-row { display:flex; align-items:flex-start; gap:14px; padding:13px 22px; border-bottom:1px solid var(--border); }
.top-row:last-child { border-bottom:none; }
.top-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; margin-top:6px; }
.top-dot.critical { background:var(--c-red); }
.top-dot.warning  { background:var(--c-yellow); }
.top-body { flex:1; min-width:0; }
.top-title { font-weight:600; font-size:14px; margin-bottom:2px; }
.top-sub   { font-size:12px; color:var(--text-3); }
.top-cat   { font-size:11px; font-weight:600; padding:2px 9px; border-radius:20px; background:var(--bg); color:var(--text-2); white-space:nowrap; flex-shrink:0; }
.pr-badge { font-size:10px; font-weight:800; padding:2px 7px; border-radius:6px; letter-spacing:.03em; flex-shrink:0; margin-top:3px; }
.pr-1 { background:var(--c-red-bg); color:var(--c-red); }
.pr-2 { background:var(--c-yellow-bg); color:var(--c-yellow); }
.pr-3 { background:var(--c-blue-bg); color:var(--c-blue); }
.pr-4 { background:var(--bg); color:var(--text-3); }

/* ── Оглавление / секции ── */
.report-layout { display:grid; grid-template-columns:230px 1fr; gap:26px; margin-top:26px; }
.toc { position:sticky; top:58px; height:calc(100vh - 80px); overflow-y:auto; scrollbar-width:thin; }
.toc-list { list-style:none; border:1px solid var(--border); border-radius:var(--radius-s); background:var(--surface); overflow:hidden; box-shadow:var(--shadow); }
.toc-list li a { display:flex; align-items:center; gap:8px; padding:11px 14px; font-size:13px; color:var(--text-2); text-decoration:none; border-bottom:1px solid var(--border); transition:background .1s; }
.toc-list li:last-child a { border-bottom:none; }
.toc-list li a:hover { background:var(--hover); color:var(--accent); }
.toc-list li a.active { background:var(--accent-bg); color:var(--accent); font-weight:600; }
.toc-count { margin-left:auto; font-size:11px; font-weight:700; padding:2px 7px; border-radius:20px; }
.toc-count.critical { background:var(--c-red-bg); color:var(--c-red); }
.toc-count.warning  { background:var(--c-yellow-bg); color:var(--c-yellow); }
.toc-count.info     { background:var(--c-blue-bg); color:var(--c-blue); }

.check-section { margin-bottom:22px; scroll-margin-top:66px; }
.check-section-head { display:flex; align-items:center; gap:10px; padding:15px 20px; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-s) var(--radius-s) 0 0; cursor:pointer; user-select:none; transition:background .15s; flex-wrap:wrap; }
.check-section-head:hover { background:var(--hover); }
.check-section-head h3 { font-size:15px; font-weight:700; flex:1; }
.collapse-icon { font-size:13px; color:var(--text-3); margin-left:auto; transition:transform .2s; }
.check-section.is-collapsed .collapse-icon { transform:rotate(-90deg); }
.check-section.is-collapsed .check-section-body { display:none; }
.check-section-body { border:1px solid var(--border); border-top:none; border-radius:0 0 var(--radius-s) var(--radius-s); background:var(--surface); overflow:hidden; }

.issue-card { display:flex; border-bottom:1px solid var(--border); }
.issue-card:last-child { border-bottom:none; }
.issue-card.is-new { background:var(--accent-bg); }
.ic-stripe { width:4px; flex-shrink:0; }
.ic-stripe.critical { background:var(--c-red); }
.ic-stripe.warning  { background:var(--c-yellow); }
.ic-stripe.info     { background:var(--c-blue); }
.ic-body { flex:1; padding:14px 18px; min-width:0; }
.ic-head { display:flex; align-items:baseline; gap:8px; flex-wrap:wrap; margin-bottom:6px; }
.ic-badge { font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; white-space:nowrap; flex-shrink:0; }
.ic-badge.critical { background:var(--c-red-bg); color:var(--c-red); }
.ic-badge.warning  { background:var(--c-yellow-bg); color:var(--c-yellow); }
.ic-badge.info     { background:var(--c-blue-bg); color:var(--c-blue); }
.ic-badge.new-tag  { background:var(--c-purple-bg); color:var(--c-purple); }
.ic-title { font-weight:600; font-size:14px; flex:1; }
.ic-count { font-size:11px; font-weight:700; color:var(--text-3); background:var(--bg); padding:2px 8px; border-radius:20px; white-space:nowrap; }
.ic-desc  { font-size:13px; color:var(--text-2); margin-bottom:7px; }
.ic-rec   { font-size:13px; color:var(--c-green); background:var(--c-green-bg); border-radius:7px; padding:9px 13px; border-left:3px solid var(--c-green); }
.ic-url   { font-size:11px; color:var(--text-3); margin-top:6px; word-break:break-all; }
.ic-url a { color:var(--text-3); }
.ic-url a:hover { color:var(--c-blue); }
.ic-pages { margin-top:8px; }
.ic-pages-list { display:flex; flex-wrap:wrap; gap:4px; }
.ic-pages-list a { font-size:11px; color:var(--c-blue); background:var(--c-blue-bg); border-radius:5px; padding:2px 7px; }
.ic-pages-list a:hover { text-decoration:none; opacity:.8; }
.ic-expand-btn { background:none; border:1px solid var(--border); border-radius:7px; padding:5px 12px; font-size:12px; cursor:pointer; color:var(--text-2); margin-top:6px; font-family:inherit; }
.ic-expand-btn:hover { background:var(--bg); }

.badge { display:inline-flex; align-items:center; padding:3px 9px; border-radius:20px; font-size:11px; font-weight:700; white-space:nowrap; flex-shrink:0; }
.badge.critical { background:var(--c-red-bg); color:var(--c-red); }
.badge.warning  { background:var(--c-yellow-bg); color:var(--c-yellow); }
.badge.info     { background:var(--c-blue-bg); color:var(--c-blue); }

/* ── Таблица страниц ── */
.pages-table-wrap { overflow-x:auto; margin-bottom:24px; border:1px solid var(--border); border-radius:var(--radius-s); background:var(--surface); box-shadow:var(--shadow); }
.pages-table { width:100%; border-collapse:collapse; font-size:12px; }
.pages-table th { background:var(--bg); padding:9px 12px; text-align:left; font-weight:700; color:var(--text-2); border-bottom:2px solid var(--border); white-space:nowrap; font-size:11px; text-transform:uppercase; letter-spacing:.04em; }
.pages-table td { padding:8px 12px; border-bottom:1px solid var(--border); vertical-align:middle; }
.pages-table tr:last-child td { border-bottom:none; }
.pages-table tr:hover td { background:var(--hover); }
.pt-url { max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:inline-block; vertical-align:middle; }
.pt-num { text-align:center !important; font-weight:600; }
.c-ok  { color:var(--c-green); }
.c-warn{ color:var(--c-yellow); }
.c-bad { color:var(--c-red); }
.c-na  { color:var(--text-3); }

.section-progress-bar  { height:4px; background:var(--bg); border-radius:2px; overflow:hidden; margin-top:8px; margin-bottom:3px; }
.section-progress-fill { height:100%; border-radius:2px; }
.section-progress-lbl  { font-size:11px; color:var(--text-3); }

/* ── ТЗ ── */
.tz-summary { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:20px; }
.tz-sum-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-s); padding:18px 20px; box-shadow:var(--shadow); border-top:4px solid var(--border); }
.tz-sum-card.p1 { border-top-color:var(--c-red); }
.tz-sum-card.p2 { border-top-color:var(--c-yellow); }
.tz-sum-card.p3 { border-top-color:var(--c-blue); }
.tz-sum-card.total { border-top-color:var(--accent); }
.tz-sum-num { font-size:28px; font-weight:900; line-height:1.1; }
.tz-sum-lbl { font-size:11.5px; color:var(--text-2); margin-top:3px; }
.tz-prio-section { margin-bottom:22px; }
.tz-prio-head { display:flex; align-items:center; gap:12px; padding:14px 20px; border-radius:var(--radius-s) var(--radius-s) 0 0; border:1px solid var(--border); background:var(--surface); border-left:4px solid var(--border); }
.tz-prio-section.p1 .tz-prio-head { border-left-color:var(--c-red); }
.tz-prio-section.p2 .tz-prio-head { border-left-color:var(--c-yellow); }
.tz-prio-section.p3 .tz-prio-head { border-left-color:var(--c-blue); }
.tz-prio-section.p4 .tz-prio-head { border-left-color:var(--text-3); }
.tz-prio-head h3 { font-size:15px; font-weight:800; }
.tz-prio-head .tz-prio-meta { margin-left:auto; font-size:12px; color:var(--text-2); }
.tz-prio-body { border:1px solid var(--border); border-top:none; border-radius:0 0 var(--radius-s) var(--radius-s); background:var(--surface); overflow:hidden; box-shadow:var(--shadow); }
.tz-item { display:flex; border-bottom:1px solid var(--border); }
.tz-item:last-child { border-bottom:none; }
.tz-inner { display:flex; gap:16px; padding:17px 20px; flex:1; align-items:flex-start; }
.tz-num { font-size:17px; font-weight:900; color:var(--text-3); min-width:32px; text-align:right; flex-shrink:0; padding-top:2px; }
.tz-body { flex:1; min-width:0; }
.tz-meta { display:flex; align-items:center; gap:7px; margin-bottom:7px; flex-wrap:wrap; }
.tz-tag  { font-size:11px; color:var(--text-2); background:var(--bg); padding:2px 9px; border-radius:20px; }
.tz-chip { font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:6px; }
.tz-chip.impact-3 { background:var(--c-red-bg); color:var(--c-red); }
.tz-chip.impact-2 { background:var(--c-yellow-bg); color:var(--c-yellow); }
.tz-chip.impact-1 { background:var(--bg); color:var(--text-2); }
.tz-chip.effort { background:var(--accent-bg); color:var(--accent); }
.tz-chip.hours { background:var(--c-blue-bg); color:var(--c-blue); }
.tz-pages-cnt { font-size:11px; font-weight:700; background:var(--c-blue-bg); color:var(--c-blue); padding:2px 8px; border-radius:20px; }
.tz-title { font-weight:700; font-size:15px; margin-bottom:6px; }
.tz-desc  { font-size:13px; color:var(--text-2); margin-bottom:8px; line-height:1.6; }
.tz-rec   { font-size:13px; color:var(--c-green); background:var(--c-green-bg); border-radius:7px; padding:9px 13px; border-left:3px solid var(--c-green); margin-bottom:8px; }
.tz-rec::before { content:'✓ Что сделать: '; font-weight:700; }
.tz-pages-lbl { font-size:11px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:.04em; margin-bottom:5px; margin-top:8px; }
.tz-page-chips { display:flex; flex-wrap:wrap; gap:4px; }
.tz-page-chips a { font-size:11px; color:var(--c-blue); background:var(--c-blue-bg); border-radius:5px; padding:2px 8px; text-decoration:none; }
.tz-page-chips a:hover { opacity:.8; }
.tz-more-btn { background:none; border:1px solid var(--border); border-radius:7px; padding:4px 10px; font-size:11px; cursor:pointer; color:var(--text-2); margin-top:5px; font-family:inherit; }
.tz-more-btn:hover { background:var(--bg); }

/* ── Прогресс ── */
.progress-banner { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:24px; }
.progress-stat { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-s); padding:24px; text-align:center; box-shadow:var(--shadow); }
.progress-stat .num { font-size:42px; font-weight:900; line-height:1; }
.progress-stat .lbl { font-size:13px; color:var(--text-2); margin-top:8px; }
.progress-stat.fixed      { border-top:4px solid var(--c-green); }
.progress-stat.fixed .num { color:var(--c-green); }
.progress-stat.new-issues { border-top:4px solid var(--c-blue); }
.progress-stat.new-issues .num { color:var(--c-blue); }
.progress-stat.unchanged  { border-top:4px solid var(--c-yellow); }
.progress-stat.unchanged .num { color:var(--c-yellow); }
.diff-section { margin-bottom:20px; }
.diff-section > h3 { font-size:15px; font-weight:700; padding:14px 20px; border-radius:var(--radius-s) var(--radius-s) 0 0; border:1px solid var(--border); }
.diff-section.fixed > h3   { background:var(--c-green-bg); color:var(--c-green); }
.diff-section.new-iss > h3 { background:var(--c-blue-bg); color:var(--c-blue); }
.diff-section .diff-body { border:1px solid var(--border); border-top:none; border-radius:0 0 var(--radius-s) var(--radius-s); background:var(--surface); overflow:hidden; }
.no-prev-notice { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:48px; text-align:center; color:var(--text-2); box-shadow:var(--shadow); }
.no-prev-notice .icon { font-size:48px; margin-bottom:16px; }

/* ── Футер ── */
.report-footer { background:var(--hero-1); color:rgba(255,255,255,.6); padding:28px 0 24px; margin-top:40px; }
.report-footer .inner { max-width:1240px; margin:0 auto; padding:0 20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:20px; }
.rf-brand { display:flex; align-items:center; gap:12px; }
.rf-logo { display:flex; align-items:center; gap:8px; text-decoration:none; }
.rf-logo svg { width:30px; height:30px; flex-shrink:0; }
.rf-logo-name { font-size:15px; font-weight:700; color:#fff; letter-spacing:.02em; }
.rf-tagline { font-size:11px; opacity:.45; margin-top:2px; }
.rf-contacts { display:flex; gap:18px; flex-wrap:wrap; align-items:center; }
.rf-contact { font-size:12px; display:flex; align-items:center; gap:5px; }
.rf-contact a { color:#4ade80; text-decoration:none; }
.rf-contact a:hover { text-decoration:underline; }
.rf-sep { opacity:.2; }

/* ── Адаптив ── */
@media (max-width:1020px) {
  .db-top { grid-template-columns:1fr; }
  .db-mid { grid-template-columns:1fr; }
  .radar-card { display:none; }
  .tz-summary { grid-template-columns:1fr 1fr; }
}
@media (max-width:860px) {
  .report-layout { grid-template-columns:1fr; }
  .toc { display:none; }
  .header-stats { flex-wrap:wrap; }
  .hstat { min-width:50%; }
  .progress-banner { grid-template-columns:1fr; }
  .db-info-grid { grid-template-columns:1fr; }
  .qw-grid { grid-template-columns:1fr; }
  .qw-item:nth-child(odd) { border-right:none; }
  .cat-cards { grid-template-columns:1fr; }
  .score-card { flex-direction:column; text-align:center; }
  .db-score-stats { justify-content:center; }
}
@media print {
  .tabs-bar,.toc,.header-actions { display:none !important; }
  .tab-content { display:block !important; }
  .report-layout { grid-template-columns:1fr; }
  body { background:#fff; }
}
</style>
</head>
<body>

<!-- ── Шапка ── -->
<div class="report-header">
  <div class="inner">
    <div class="report-meta">
      <div class="report-site">
        <h1>SEO Аудит: <?= htmlspecialchars($host) ?></h1>
        <div class="sub">
          <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($url) ?></a>
          <span>·</span><span><?= $date ?></span>
          <span>·</span><span>Страниц: <?= count($pages) ?></span>
        </div>
        <div class="report-prepared">Подготовлено компанией <a href="https://integrom.ru" target="_blank" rel="noopener">integrom.ru</a></div>
        <div class="header-actions" style="margin-top:14px">
          <a class="hbtn primary" id="pdf-btn" href="<?= $pdfUrl ?>" target="_blank">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Скачать PDF
          </a>
          <button class="hbtn" id="theme-toggle" type="button" title="Переключить тему">
            <span class="tt-icon">🌙</span> <span class="tt-label">Тёмная</span>
          </button>
        </div>
      </div>
      <div class="score-wrap">
        <?php $scoreCirc = 2 * M_PI * 34; $scoreDash = $scoreCirc * $score / 100; ?>
        <div class="score-ring">
          <svg width="80" height="80" viewBox="0 0 80 80">
            <circle cx="40" cy="40" r="34" fill="none" stroke="rgba(255,255,255,.14)" stroke-width="7"/>
            <circle cx="40" cy="40" r="34" fill="none" stroke="<?= $scoreHex ?>" stroke-width="7"
              stroke-dasharray="<?= round($scoreDash,2) ?> <?= round($scoreCirc,2) ?>" stroke-linecap="round"/>
          </svg>
          <div class="score-num" style="color:<?= $scoreHex ?>"><?= $score ?></div>
        </div>
        <div class="score-info">
          <span class="score-label"><?= $scoreLabel ?></span>
          <span class="score-sub">Оценка сайта / 100</span>
          <?php if ($scoreDelta !== null): ?>
          <div class="score-history">
            <span>Было: <?= $prevScore ?></span>
            <span class="score-delta <?= $scoreDelta >= 0 ? 'up' : 'down' ?>"><?= $scoreDelta >= 0 ? '↑' : '↓' ?><?= abs($scoreDelta) ?></span>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <div class="header-stats">
    <div class="hstat critical"><div class="num"><?= $cntCrit ?></div><div class="lbl">Критических</div></div>
    <div class="hstat warning"><div class="num"><?= $cntWarn ?></div><div class="lbl">Предупреждений</div></div>
    <div class="hstat info"><div class="num"><?= $cntInfo ?></div><div class="lbl">Рекомендаций</div></div>
    <div class="hstat"><div class="num" style="color:#a5b8fc"><?= round($totalHours) ?></div><div class="lbl">Часов на исправление</div></div>
    <?php if ($comparison['has_prev'] ?? false): ?>
    <div class="hstat fixed"><div class="num"><?= $comparison['fixed_count'] ?></div><div class="lbl">Исправлено</div></div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Вкладки ── -->
<div class="tabs-bar">
  <div class="inner">
    <button class="tab-btn active" data-tab="overview">📊 Обзор</button>
    <?php foreach ($tabChecks as $tabId => $types):
      $critInTab = countGroupsBySeverity($allGroups, $types, 'critical');
      $warnInTab = countGroupsBySeverity($allGroups, $types, 'warning');
      $cnt = $critInTab + $warnInTab;
    ?>
    <button class="tab-btn <?= $critInTab > 0 ? 'has-critical' : '' ?>" data-tab="<?= $tabId ?>">
      <?= $tabs[$tabId]['icon'] ?> <?= $tabs[$tabId]['label'] ?>
      <?php if ($cnt > 0): ?><span class="tb"><?= $cnt ?></span><?php endif; ?>
    </button>
    <?php endforeach; ?>
    <button class="tab-btn" data-tab="tz">📝 План работ <span class="tb"><?= count($actionGroups) ?></span></button>
    <button class="tab-btn" data-tab="progress">📈 Прогресс
      <?php if ($comparison['has_prev'] ?? false): ?>
      <span class="tb" style="background:var(--c-green-bg);color:var(--c-green)"><?= $comparison['fixed_count'] ?> ✓</span>
      <?php endif; ?>
    </button>
  </div>
</div>

<div class="wrap">

<!-- ══════════ ОБЗОР ══════════ -->
<div id="tab-overview" class="tab-content active" style="padding-top:26px">

  <!-- Резюме -->
  <div class="exec-card card">
    <h2>Резюме для руководителя</h2>
    <p><?= htmlspecialchars($execSummary) ?></p>
  </div>

  <!-- Оценка + Информация о сайте -->
  <div class="db-top">
    <div class="score-card card">
      <?php $dbCirc = 2 * M_PI * 52; $dbDash = $dbCirc * $score / 100; ?>
      <div class="db-score-ring">
        <svg width="130" height="130" viewBox="0 0 130 130">
          <circle class="track" cx="65" cy="65" r="52" fill="none" stroke-width="11"/>
          <circle cx="65" cy="65" r="52" fill="none" stroke="<?= $scoreHex ?>" stroke-width="11"
            stroke-dasharray="<?= round($dbDash,2) ?> <?= round($dbCirc,2) ?>" stroke-linecap="round"/>
        </svg>
        <div class="db-score-num">
          <span class="sn" style="color:<?= $scoreHex ?>"><?= $score ?></span>
          <span class="sl">/100</span>
        </div>
      </div>
      <div class="db-score-details">
        <h2><?= $scoreLabel ?></h2>
        <p><?= htmlspecialchars($host) ?></p>
        <?php if ($scoreDelta !== null): ?>
        <p style="margin-top:4px;font-size:13px;color:<?= $scoreDelta >= 0 ? 'var(--c-green)' : 'var(--c-red)' ?>">
          <?= $scoreDelta >= 0 ? '↑' : '↓' ?> <?= abs($scoreDelta) ?> пунктов к предыдущему
        </p>
        <?php endif; ?>
        <div class="db-score-stats">
          <div class="db-ss crit"><div class="snum"><?= $cntCrit ?></div><div class="slbl">Критично</div></div>
          <div class="db-ss warn"><div class="snum"><?= $cntWarn ?></div><div class="slbl">Важно</div></div>
          <div class="db-ss info"><div class="snum"><?= $cntInfo ?></div><div class="slbl">Инфо</div></div>
        </div>
      </div>
    </div>

    <div class="db-info-grid">
      <?php
      $infoItems = [
        ['🖥️','CMS',         $siteData['cms'] ?? '—'],
        ['🌍','IP / хостинг', ($siteData['ip'] ?? '—').' · '.mb_substr($siteData['isp'] ?? '—',0,18)],
        ['📍','Регион',       trim(($siteData['country']??'').' '.($siteData['city']??'')) ?: '—'],
        ['🖧', 'Сервер',      mb_substr($siteData['server'] ?? '—',0,22)],
        ['⚡','Ответ сервера', ($siteData['response_ms']??0) ? $siteData['response_ms'].' мс' : '—'],
        ['📱','Мобильная',    ($siteData['mobile_friendly']??false) ? '✅ Есть' : '❌ Нет'],
        ['📊','Аналитика',    ($siteData['analytics_list']??'') ?: '—'],
        ['🗺️','Sitemap',     ($siteData['sitemap_url']??'') ? '✅ '.($siteData['sitemap_count']??0).' URL' : '❌ Не найден'],
      ];
      foreach ($infoItems as [$ic,$lb,$vl]):
      ?>
      <div class="db-info-item">
        <div class="db-info-icon"><?= $ic ?></div>
        <div>
          <div class="db-info-lbl"><?= htmlspecialchars($lb) ?></div>
          <div class="db-info-val"><?= htmlspecialchars($vl) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Быстрые победы -->
  <?php if (!empty($quickWins)): ?>
  <div class="qw-card">
    <div class="qw-head">
      <span style="font-size:18px">🎯</span>
      <span class="card-title">Быстрые победы — максимум эффекта за минимум усилий</span>
      <span class="qw-sub">~<?= round($quickWinHours) ?> ч суммарно</span>
    </div>
    <div class="qw-grid">
      <?php foreach ($quickWins as $i => $qw): ?>
      <div class="qw-item">
        <div class="qw-num"><?= $i + 1 ?></div>
        <div>
          <div class="qw-title"><?= htmlspecialchars($qw['base']) ?></div>
          <div class="qw-meta">
            <?= htmlspecialchars($checkLabels[$qw['rep']['check_type']]['label'] ?? $qw['rep']['check_type']) ?>
            · ~<?= $qw['prio']['hours'] ?> ч
            <?php if ($qw['count'] > 1): ?> · <?= $qw['count'] ?> стр.<?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Радар + Карточки категорий -->
  <div class="db-mid">
    <div class="radar-card card">
      <div class="card-title">Здоровье сайта</div>
      <svg viewBox="0 0 320 280" width="300" height="263">
        <?php foreach ($radarGrid as $ri => $ring): ?>
        <polygon class="radar-grid-line" points="<?= svgPts($ring) ?>" fill="none" stroke-width="<?= $ri===3?'1.5':'1' ?>"/>
        <?php endforeach; ?>
        <?php foreach ($radarAx as $i => $ap):
          $ang = (-90 + $i * 360 / $axCount) * M_PI / 180;
          $axX = round($cx + $R * cos($ang), 1);
          $axY = round($cy + $R * sin($ang), 1);
        ?>
        <line class="radar-grid-line" x1="<?= $cx ?>" y1="<?= $cy ?>" x2="<?= $axX ?>" y2="<?= $axY ?>" stroke-width="1"/>
        <?php endforeach; ?>
        <polygon points="<?= svgPts($radarPts) ?>" fill="<?= $scoreHex ?>22" stroke="<?= $scoreHex ?>" stroke-width="2.5" stroke-linejoin="round"/>
        <?php foreach ($radarPts as $pt): ?>
        <circle cx="<?= round($pt[0],1) ?>" cy="<?= round($pt[1],1) ?>" r="4.5" fill="<?= $scoreHex ?>" stroke="#fff" stroke-width="2"/>
        <?php endforeach; ?>
        <?php foreach ($radarAx as $i => $ap):
          $ta = 'middle';
          if ($ap['x'] < $cx-8) $ta = 'end';
          elseif ($ap['x'] > $cx+8) $ta = 'start';
          $dy = $ap['y'] < $cy-8 ? '-0.4em' : ($ap['y'] > $cy+8 ? '1em' : '0.35em');
        ?>
        <text class="radar-label" x="<?= round($ap['x'],1) ?>" y="<?= round($ap['y'],1) ?>" dy="<?= $dy ?>"
          text-anchor="<?= $ta ?>" font-size="11" font-family="Segoe UI,system-ui,sans-serif"
          font-weight="600"><?= htmlspecialchars($ap['label']) ?></text>
        <?php endforeach; ?>
      </svg>
    </div>

    <div class="cat-cards">
      <?php foreach ($catHealth as $tabId => $ch):
        $meta   = $catMeta[$tabId];
        $hCls   = $ch['h'] >= 80 ? 'h-good' : ($ch['h'] >= 50 ? 'h-ok' : 'h-bad');
        $barClr = $ch['h'] >= 80 ? 'var(--c-green)' : ($ch['h'] >= 50 ? 'var(--c-yellow)' : 'var(--c-red)');
      ?>
      <a class="cat-card" data-tab-link="<?= $tabId ?>" href="#<?= $tabId ?>">
        <div class="cat-head">
          <div class="cat-icon"><?= $meta['icon'] ?></div>
          <div class="cat-name"><?= htmlspecialchars($meta['name']) ?></div>
          <div class="cat-health <?= $hCls ?>"><?= $ch['h'] ?>%</div>
        </div>
        <div class="cat-bar"><div class="cat-bar-fill" style="width:<?= $ch['h'] ?>%;background:<?= $barClr ?>"></div></div>
        <div class="cat-counts">
          <?php if ($ch['crit']>0): ?><span class="cat-cnt"><span class="dot c"></span><?= $ch['crit'] ?> критичных</span><?php endif; ?>
          <?php if ($ch['warn']>0): ?><span class="cat-cnt"><span class="dot w"></span><?= $ch['warn'] ?> важных</span><?php endif; ?>
          <?php if ($ch['info']>0): ?><span class="cat-cnt"><span class="dot i"></span><?= $ch['info'] ?> инфо</span><?php endif; ?>
          <?php if ($ch['crit']===0 && $ch['warn']===0 && $ch['info']===0): ?>
          <span style="color:var(--c-green);font-weight:600">✓ Нет проблем</span>
          <?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Топ приоритетных проблем -->
  <?php if (!empty($topIssues)): ?>
  <div class="top-issues card">
    <div class="top-issues-head"><span class="card-title">Приоритетные проблемы</span></div>
    <?php foreach ($topIssues as $ti):
      $rep = $ti['rep'];
      $catLabel = $checkLabels[$rep['check_type']]['label'] ?? $rep['check_type'];
    ?>
    <div class="top-row">
      <span class="pr-badge pr-<?= $ti['prio']['priority'] ?>">P<?= $ti['prio']['priority'] ?></span>
      <div class="top-dot <?= $rep['severity'] ?>"></div>
      <div class="top-body">
        <div class="top-title"><?= htmlspecialchars($ti['base']) ?><?php if ($ti['count'] > 1): ?> <span style="font-size:11px;font-weight:600;color:var(--c-blue);background:var(--c-blue-bg);padding:1px 7px;border-radius:20px;margin-left:5px"><?= $ti['count'] ?> стр.</span><?php endif; ?></div>
        <?php if (!empty($rep['recommendation'])): ?>
        <div class="top-sub"><?= htmlspecialchars(mb_substr($rep['recommendation'],0,130)) ?><?= mb_strlen($rep['recommendation'])>130 ? '…' : '' ?></div>
        <?php endif; ?>
      </div>
      <span class="top-cat"><?= htmlspecialchars($catLabel) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Панель Яндекс + AI -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px" class="db-panels">
    <?php if (isset($siteData['yandex_metrika'])):
      $yItems = [
        ['📊','Яндекс.Метрика',  ($siteData['yandex_metrika']??false) ? '✅ Установлена' : '❌ Не найдена'],
        ['🔧','Яндекс.Вебмастер',($siteData['yandex_webmaster_verified']??false) ? '✅ Подтверждён' : '⚠️ Не подтверждён'],
        ['🖼️','Favicon',         ($siteData['has_favicon']??false) ? '✅ Есть' : '⚠️ Нет'],
        ['📍','Schema.org Org',  ($siteData['yandex_schema']['org']??false) ? '✅ Есть' : '⚠️ Нет'],
      ];
      if (isset($siteData['commercial_score'])) {
          $yItems[] = ['💼','Коммерческие факторы', $siteData['commercial_score'].'%'];
      }
    ?>
    <div class="card" style="overflow:hidden">
      <div style="background:var(--c-yellow-bg);padding:11px 18px;font-size:11px;font-weight:800;color:var(--c-yellow);text-transform:uppercase;letter-spacing:.07em">Яндекс</div>
      <?php foreach ($yItems as $yi): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:10px 18px;border-bottom:1px solid var(--border)">
        <span style="font-size:16px"><?= $yi[0] ?></span>
        <span style="font-size:12.5px;color:var(--text-2);flex:1"><?= $yi[1] ?></span>
        <span style="font-size:13px;font-weight:600"><?= $yi[2] ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (isset($siteData['ai_readiness'])):
      $ai = $siteData['ai_readiness'];
      $aiItems = [
        ['🤖','Доступ AI-ботов',  empty($ai['blocked_bots']) ? '✅ Открыт' : '⚠️ Заблокировано: '.count($ai['blocked_bots'])],
        ['📄','llms.txt',         ($ai['llms_txt']??false) ? '✅ Есть' : '— Нет'],
        ['🧩','Schema.org',       ($ai['schema_coverage']??0).'% страниц'],
        ['❓','FAQ-структура',    ($ai['faq_found']??false) ? '✅ Есть' : '— Нет'],
      ];
    ?>
    <div class="card" style="overflow:hidden">
      <div style="background:var(--accent-bg);padding:11px 18px;font-size:11px;font-weight:800;color:var(--accent);text-transform:uppercase;letter-spacing:.07em">Готовность к AI-поиску</div>
      <?php foreach ($aiItems as $aii): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:10px 18px;border-bottom:1px solid var(--border)">
        <span style="font-size:16px"><?= $aii[0] ?></span>
        <span style="font-size:12.5px;color:var(--text-2);flex:1"><?= $aii[1] ?></span>
        <span style="font-size:13px;font-weight:600"><?= $aii[2] ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ══════════ ВКЛАДКИ КАТЕГОРИЙ ══════════ -->
<?php foreach ($tabChecks as $tabId => $types): ?>
<div id="tab-<?= $tabId ?>" class="tab-content">
  <div class="report-layout">
    <aside class="toc">
      <ul class="toc-list">
        <?php foreach ($types as $type):
          if (empty($grouped[$type])) continue;
          $meta = $checkLabels[$type] ?? ['label'=>$type,'icon'=>'•'];
          $crit = count(array_filter($grouped[$type], fn($i) => $i['severity']==='critical'));
          $warn = count(array_filter($grouped[$type], fn($i) => $i['severity']==='warning'));
          $sev  = $crit ? 'critical' : ($warn ? 'warning' : 'info');
          $cnt  = $crit ?: $warn ?: count($grouped[$type]);
        ?>
        <li>
          <a href="#section-<?= $tabId ?>-<?= $type ?>">
            <?= $meta['icon'] ?> <?= $meta['label'] ?>
            <span class="toc-count <?= $sev ?>"><?= $cnt ?></span>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </aside>
    <div class="tab-main">
      <?php foreach ($types as $type):
        if (empty($grouped[$type])) continue;
        $meta       = $checkLabels[$type] ?? ['label'=>$type,'icon'=>'•'];
        $typeIssues = $grouped[$type];
        usort($typeIssues, fn($a,$b) => $sevOrder[$a['severity']] - $sevOrder[$b['severity']]);
        $crit = count(array_filter($typeIssues, fn($i) => $i['severity']==='critical'));
        $warn = count(array_filter($typeIssues, fn($i) => $i['severity']==='warning'));

        $pageUrlIndex     = array_flip(array_column($pages, 'url'));
        $affectedPageUrls = array_unique(array_filter(array_column($typeIssues,'url'), fn($u) => $u && isset($pageUrlIndex[$u])));
        $totalPgs   = count($pages);
        $cleanCount = $totalPgs - count($affectedPageUrls);
        $cleanPct   = $totalPgs > 0 ? round($cleanCount / $totalPgs * 100) : 100;
        $barClr     = $cleanPct >= 80 ? 'var(--c-green)' : ($cleanPct >= 50 ? 'var(--c-yellow)' : 'var(--c-red)');
        $showProg   = $totalPgs > 0 && count($affectedPageUrls) > 0;

        $issueGroups = groupIssuesByTitle($typeIssues);
      ?>
      <?php if ($type === 'seo' && !empty($siteData['page_seo_metrics'])): ?>
      <div style="margin:24px 0 10px">
        <h3 class="card-title">📋 Метрики страниц (<?= count($siteData['page_seo_metrics']) ?>)</h3>
      </div>
      <div class="pages-table-wrap">
        <table class="pages-table">
          <thead><tr>
            <th>Страница</th><th class="pt-num">Статус</th><th class="pt-num">Title, симв.</th>
            <th class="pt-num">Description, симв.</th><th class="pt-num">H1</th>
            <th class="pt-num">Без alt / всего</th><th class="pt-num">Canonical</th><th class="pt-num">OG</th>
          </tr></thead>
          <tbody>
          <?php foreach ($siteData['page_seo_metrics'] as $pm):
            $tLen = $pm['title_len']; $dLen = $pm['desc_len'];
            $tCls = $tLen===0?'c-bad':($tLen>=50&&$tLen<=70?'c-ok':($tLen<10||$tLen>80?'c-bad':'c-warn'));
            $dCls = $dLen===0?'c-bad':($dLen>=120&&$dLen<=160?'c-ok':($dLen<50||$dLen>200?'c-bad':'c-warn'));
            $hCls = $pm['h1_count']===1?'c-ok':($pm['h1_count']===0?'c-bad':'c-warn');
            $aCls = $pm['total_images']===0?'c-na':($pm['imgs_no_alt']===0?'c-ok':($pm['imgs_no_alt']<=2?'c-warn':'c-bad'));
            $sCls = $pm['status']===200?'c-ok':($pm['status']>=300&&$pm['status']<400?'c-warn':'c-bad');
            $path = parse_url($pm['url'], PHP_URL_PATH) ?: '/';
            $altVal = $pm['total_images']===0?'—':($pm['imgs_no_alt']===0?'✓':$pm['imgs_no_alt'].' / '.$pm['total_images']);
          ?>
          <tr>
            <td><a href="<?= htmlspecialchars($pm['url']) ?>" target="_blank" rel="noopener" class="pt-url" title="<?= htmlspecialchars($pm['url']) ?>"><?= htmlspecialchars($path) ?> ↗</a></td>
            <td class="pt-num <?= $sCls ?>"><?= $pm['status'] ?></td>
            <td class="pt-num <?= $tCls ?>"><?= $tLen ?: '—' ?></td>
            <td class="pt-num <?= $dCls ?>"><?= $dLen ?: '—' ?></td>
            <td class="pt-num <?= $hCls ?>"><?= $pm['h1_count'] ?></td>
            <td class="pt-num <?= $aCls ?>"><?= $altVal ?></td>
            <td class="pt-num <?= $pm['has_canonical']?'c-ok':'c-warn' ?>"><?= $pm['has_canonical']?'✓':'✗' ?></td>
            <td class="pt-num <?= $pm['has_og']?'c-ok':'c-na' ?>"><?= $pm['has_og']?'✓':'—' ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
      <div class="check-section" id="section-<?= $tabId ?>-<?= $type ?>">
        <div class="check-section-head">
          <span style="font-size:20px"><?= $meta['icon'] ?></span>
          <h3><?= htmlspecialchars($meta['label']) ?></h3>
          <?php if ($crit>0): ?><span class="badge critical"><?= $crit ?> крит.</span><?php endif; ?>
          <?php if ($warn>0): ?><span class="badge warning"><?= $warn ?> важно</span><?php endif; ?>
          <span class="badge info"><?= count($typeIssues) ?> всего</span>
          <span class="collapse-icon">▾</span>
          <?php if ($showProg): ?>
          <div style="flex-basis:100%;padding-top:6px">
            <div class="section-progress-bar"><div class="section-progress-fill" style="width:<?= $cleanPct ?>%;background:<?= $barClr ?>"></div></div>
            <div class="section-progress-lbl"><?= $cleanPct ?>% страниц без проблем · <?= $cleanCount ?> из <?= $totalPgs ?></div>
          </div>
          <?php endif; ?>
        </div>
        <div class="check-section-body">
          <?php $gIdx = 0; foreach ($issueGroups as $grp):
            $rep   = $grp['rep'];
            $isNew = ($rep['is_new']??0) && ($comparison['has_prev']??false);
            $isGrp = $grp['count'] > 1;
            $urls  = array_unique($grp['urls']);
            $gId   = 'grp-'.$tabId.'-'.$type.'-'.$gIdx++;
          ?>
          <div class="issue-card<?= $isNew?' is-new':'' ?>">
            <div class="ic-stripe <?= $rep['severity'] ?>"></div>
            <div class="ic-body">
              <div class="ic-head">
                <span class="ic-badge <?= $rep['severity'] ?>"><?= $sevLabel[$rep['severity']] ?></span>
                <?php if ($isNew): ?><span class="ic-badge new-tag">🆕 Новое</span><?php endif; ?>
                <span class="ic-title"><?= htmlspecialchars($grp['base']) ?></span>
                <?php if ($isGrp): ?><span class="ic-count">× <?= $grp['count'] ?> стр.</span><?php endif; ?>
              </div>
              <?php if (!$isGrp && !empty($rep['description'])): ?>
              <div class="ic-desc"><?= htmlspecialchars($rep['description']) ?></div>
              <?php endif; ?>
              <?php if (!empty($rep['recommendation'])): ?>
              <div class="ic-rec"><?= htmlspecialchars($rep['recommendation']) ?></div>
              <?php endif; ?>
              <?php if (!$isGrp && !empty($rep['url'])): ?>
              <div class="ic-url"><a href="<?= htmlspecialchars($rep['url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($rep['url']) ?> ↗</a></div>
              <?php elseif ($isGrp && !empty($urls)):
                $visUrls  = array_slice($urls, 0, 5);
                $moreUrls = count($urls) - count($visUrls);
              ?>
              <div class="ic-pages">
                <div class="ic-pages-list">
                  <?php foreach ($visUrls as $u): $p = parse_url($u, PHP_URL_PATH) ?: '/'; ?>
                  <a href="<?= htmlspecialchars($u) ?>" target="_blank" rel="noopener" title="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($p) ?></a>
                  <?php endforeach; ?>
                </div>
                <?php if ($moreUrls > 0): ?>
                <div class="ic-pages-list" id="<?= $gId ?>-more" style="display:none;margin-top:4px">
                  <?php foreach (array_slice($urls, 5) as $u): $p = parse_url($u, PHP_URL_PATH) ?: '/'; ?>
                  <a href="<?= htmlspecialchars($u) ?>" target="_blank" rel="noopener" title="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($p) ?></a>
                  <?php endforeach; ?>
                </div>
                <button class="ic-expand-btn" data-target="<?= $gId ?>-more" data-more="<?= $moreUrls ?>">+ ещё <?= $moreUrls ?> страниц</button>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- ══════════ ПЛАН РАБОТ (ТЗ) ══════════ -->
<div id="tab-tz" class="tab-content" style="padding-top:26px">

  <div class="tz-summary">
    <div class="tz-sum-card total">
      <div class="tz-sum-num">~<?= round($totalHours) ?> ч</div>
      <div class="tz-sum-lbl">Общая оценка трудозатрат</div>
    </div>
    <div class="tz-sum-card p1">
      <div class="tz-sum-num"><?= count($byPriority[1]) ?></div>
      <div class="tz-sum-lbl">P1 — срочные задачи</div>
    </div>
    <div class="tz-sum-card p2">
      <div class="tz-sum-num"><?= count($byPriority[2]) ?></div>
      <div class="tz-sum-lbl">P2 — важные задачи</div>
    </div>
    <div class="tz-sum-card p3">
      <div class="tz-sum-num"><?= count($byPriority[3]) + count($byPriority[4]) ?></div>
      <div class="tz-sum-lbl">P3–P4 — плановые задачи</div>
    </div>
  </div>

  <?php
  $prioDescr = [
    1 => 'Критично для бизнеса, невысокие трудозатраты — исправить в первую очередь',
    2 => 'Существенное влияние на трафик и конверсию — включить в ближайший спринт',
    3 => 'Улучшения с умеренным эффектом — выполнять планово',
    4 => 'Низкий приоритет — по остаточному принципу',
  ];
  $n = 1;
  foreach ($byPriority as $p => $pGroups):
    if (empty($pGroups)) continue;
    $pHours = array_sum(array_map(fn($g) => $g['prio']['hours'], $pGroups));
  ?>
  <div class="tz-prio-section p<?= $p ?>">
    <div class="tz-prio-head">
      <span class="pr-badge pr-<?= $p ?>" style="font-size:12px;margin-top:0">P<?= $p ?></span>
      <h3><?= htmlspecialchars(\SeoAuditor\Report\Priority::priorityLabel($p)) ?></h3>
      <span class="tz-prio-meta"><?= count($pGroups) ?> задач · ~<?= round($pHours) ?> ч — <?= $prioDescr[$p] ?></span>
    </div>
    <div class="tz-prio-body">
      <?php foreach ($pGroups as $grp):
        $rep      = $grp['rep'];
        $prio     = $grp['prio'];
        $tzUrls   = array_unique($grp['urls']);
        $visUrls  = array_slice($tzUrls, 0, 8);
        $moreUrls = count($tzUrls) - count($visUrls);
        $tzGid    = 'tzg-' . $n;
      ?>
      <div class="tz-item">
        <div class="ic-stripe <?= $rep['severity'] ?>"></div>
        <div class="tz-inner">
          <div class="tz-num"><?= $n++ ?></div>
          <div class="tz-body">
            <div class="tz-meta">
              <span class="tz-tag"><?= htmlspecialchars($checkLabels[$rep['check_type']]['label'] ?? $rep['check_type']) ?></span>
              <span class="tz-chip impact-<?= $prio['impact'] ?>">влияние: <?= \SeoAuditor\Report\Priority::impactLabel($prio['impact']) ?></span>
              <span class="tz-chip effort">затраты: <?= \SeoAuditor\Report\Priority::effortLabel($prio['effort']) ?></span>
              <span class="tz-chip hours">~<?= $prio['hours'] ?> ч</span>
              <?php if ($grp['count']>1): ?>
              <span class="tz-pages-cnt"><?= $grp['count'] ?> страниц</span>
              <?php endif; ?>
            </div>
            <div class="tz-title"><?= htmlspecialchars($grp['base']) ?></div>
            <?php if (!empty($rep['description'])): ?>
            <div class="tz-desc"><?= htmlspecialchars($rep['description']) ?></div>
            <?php endif; ?>
            <?php if (!empty($rep['recommendation'])): ?>
            <div class="tz-rec"><?= htmlspecialchars($rep['recommendation']) ?></div>
            <?php endif; ?>
            <?php if (!empty($tzUrls)): ?>
            <div class="tz-pages-lbl">Затронутые страницы</div>
            <div class="tz-page-chips">
              <?php foreach ($visUrls as $u): $p2 = parse_url($u, PHP_URL_PATH) ?: '/'; ?>
              <a href="<?= htmlspecialchars($u) ?>" target="_blank" rel="noopener" title="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($p2) ?></a>
              <?php endforeach; ?>
            </div>
            <?php if ($moreUrls > 0): ?>
            <div class="tz-page-chips" id="<?= $tzGid ?>-hid" style="display:none;margin-top:4px">
              <?php foreach (array_slice($tzUrls, 8) as $u): $p2 = parse_url($u, PHP_URL_PATH) ?: '/'; ?>
              <a href="<?= htmlspecialchars($u) ?>" target="_blank" rel="noopener" title="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($p2) ?></a>
              <?php endforeach; ?>
            </div>
            <button class="tz-more-btn" data-target="<?= $tzGid ?>-hid" data-more="<?= $moreUrls ?>">+ ещё <?= $moreUrls ?> страниц</button>
            <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <p style="font-size:12px;color:var(--text-3);margin-top:4px">* Оценка часов ориентировочная: рассчитана по типу задачи и количеству затронутых страниц, уточняется после осмотра кода сайта.</p>
</div>

<!-- ══════════ ПРОГРЕСС ══════════ -->
<div id="tab-progress" class="tab-content" style="padding-top:26px">
<?php if (!($comparison['has_prev'] ?? false)): ?>
  <div class="no-prev-notice">
    <div class="icon">📈</div>
    <h3 style="font-size:18px;font-weight:700;margin-bottom:8px">Это первый аудит данного сайта</h3>
    <p style="max-width:420px;margin:0 auto">После исправления ошибок запустите повторный аудит — здесь будет показано, что исправлено, что добавилось и что осталось.</p>
  </div>
<?php else:
  $prevIssues  = \SeoAuditor\Core\Database::query('SELECT * FROM audit_issues WHERE audit_id = ?', [$comparison['prev_audit_id']])->fetchAll();
  $currentKeys = [];
  foreach ($issues as $issue) $currentKeys[$issue['issue_key'] ?? ''] = true;
  $fixedIssues    = array_filter($prevIssues, fn($pi) => !isset($currentKeys[$pi['issue_key']]));
  $newIssues      = array_filter($issues, fn($i) => ($i['is_new']??0) == 1);
  $unchangedIssues= array_filter($issues, fn($i) => ($i['is_new']??0) == 0);
?>
  <div class="progress-banner">
    <div class="progress-stat fixed"><div class="num"><?= count($fixedIssues) ?></div><div class="lbl">✅ Исправлено</div></div>
    <div class="progress-stat new-issues"><div class="num"><?= count($newIssues) ?></div><div class="lbl">🆕 Новых проблем</div></div>
    <div class="progress-stat unchanged"><div class="num"><?= count($unchangedIssues) ?></div><div class="lbl">⚠️ Осталось</div></div>
  </div>
  <?php if (!empty($fixedIssues)): ?>
  <div class="diff-section fixed">
    <h3>✅ Исправленные проблемы (<?= count($fixedIssues) ?>)</h3>
    <div class="diff-body">
      <?php foreach ($fixedIssues as $fi): ?>
      <div class="issue-card">
        <div class="ic-stripe" style="background:var(--c-green)"></div>
        <div class="ic-body">
          <div class="ic-head">
            <span class="ic-badge" style="background:var(--c-green-bg);color:var(--c-green)">✅ Исправлено</span>
            <span class="ic-title" style="text-decoration:line-through;opacity:.55"><?= htmlspecialchars($fi['title']) ?></span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php if (!empty($newIssues)): ?>
  <div class="diff-section new-iss">
    <h3>🆕 Новые проблемы (<?= count($newIssues) ?>)</h3>
    <div class="diff-body">
      <?php foreach ($newIssues as $ni): ?>
      <div class="issue-card">
        <div class="ic-stripe <?= $ni['severity'] ?>"></div>
        <div class="ic-body">
          <div class="ic-head">
            <span class="ic-badge new-tag">🆕 Новое</span>
            <span class="ic-badge <?= $ni['severity'] ?>"><?= $sevLabel[$ni['severity']] ?></span>
            <span class="ic-title"><?= htmlspecialchars($ni['title']) ?></span>
          </div>
          <?php if (!empty($ni['recommendation'])): ?>
          <div class="ic-rec"><?= htmlspecialchars($ni['recommendation']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>
</div>

</div><!-- /wrap -->

<div class="report-footer">
  <div class="inner">
    <div class="rf-brand">
      <a href="https://integrom.ru" target="_blank" rel="noopener" class="rf-logo">
        <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M9 11 L22 20 L9 29" stroke="#4ade80" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/>
          <rect x="25" y="26" width="11" height="5" rx="1" fill="#22c55e"/>
        </svg>
        <span class="rf-logo-name">интегром</span>
      </a>
      <div class="rf-tagline">AI-агенты · Битрикс24 · 1С-Битрикс</div>
    </div>
    <div class="rf-contacts">
      <span class="rf-contact">📞 <a href="tel:+79290956393">+7 929-095-63-93</a></span>
      <span class="rf-sep">|</span>
      <span class="rf-contact">✉️ <a href="mailto:ai@integrom.ru">ai@integrom.ru</a></span>
      <span class="rf-sep">|</span>
      <span class="rf-contact">💬 <a href="https://t.me/integrom" target="_blank" rel="noopener">@integrom</a></span>
      <span class="rf-sep">|</span>
      <span class="rf-contact">🌐 <a href="https://integrom.ru" target="_blank" rel="noopener">integrom.ru</a></span>
    </div>
  </div>
</div>

<script>
(function(){
  // ── Тема ──
  const root   = document.documentElement;
  const toggle = document.getElementById('theme-toggle');
  function applyTheme(t) {
    root.setAttribute('data-theme', t);
    if (toggle) {
      toggle.querySelector('.tt-icon').textContent  = t === 'dark' ? '☀️' : '🌙';
      toggle.querySelector('.tt-label').textContent = t === 'dark' ? 'Светлая' : 'Тёмная';
    }
  }
  let theme = 'light';
  try { theme = localStorage.getItem('report-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); } catch(e) {}
  applyTheme(theme);
  if (toggle) toggle.addEventListener('click', () => {
    theme = theme === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem('report-theme', theme); } catch(e) {}
    applyTheme(theme);
  });

  // ── Вкладки ──
  const btns     = document.querySelectorAll('.tab-btn');
  const contents = document.querySelectorAll('.tab-content');
  function activate(tabId) {
    btns.forEach(b => b.classList.toggle('active', b.dataset.tab === tabId));
    contents.forEach(c => {
      const isActive = c.id === 'tab-' + tabId;
      c.classList.toggle('active', isActive);
      if (isActive) { c.style.animation = 'none'; c.offsetHeight; c.style.animation = ''; }
    });
    history.replaceState(null, '', '#' + tabId);
  }
  btns.forEach(btn => btn.addEventListener('click', () => activate(btn.dataset.tab)));
  document.querySelectorAll('[data-tab-link]').forEach(el => {
    el.addEventListener('click', e => {
      e.preventDefault();
      activate(el.dataset.tabLink);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  });
  const hash = location.hash.slice(1);
  if (hash && document.getElementById('tab-' + hash)) activate(hash);

  // ── Анимация счётчиков ──
  function animCount(el, target, dur) {
    const t0 = performance.now();
    function step(t) {
      const p = Math.min((t - t0) / dur, 1), e = p < .5 ? 2*p*p : -1+(4-2*p)*p;
      el.textContent = Math.round(e * target);
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }
  const sn = document.querySelector('.db-score-num .sn');
  if (sn) { const v = parseInt(sn.textContent) || 0; sn.textContent = '0'; animCount(sn, v, 1200); }
  document.querySelectorAll('.hstat .num').forEach(el => {
    const n = parseInt(el.textContent) || 0; el.textContent = '0'; animCount(el, n, 800);
  });

  // ── Collapse секций ──
  document.querySelectorAll('.check-section-head').forEach(head => {
    head.addEventListener('click', () => head.closest('.check-section').classList.toggle('is-collapsed'));
  });

  // ── Раскрыть страницы ──
  document.querySelectorAll('.ic-expand-btn, .tz-more-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const el = document.getElementById(btn.dataset.target);
      const more = btn.dataset.more;
      const hidden = el.style.display === 'none';
      el.style.display = hidden ? 'flex' : 'none';
      btn.textContent = hidden ? 'Скрыть' : '+ ещё ' + more + ' страниц';
    });
  });

  // ── TOC подсветка ──
  const observer = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        const id = e.target.id;
        document.querySelectorAll('.toc-list a').forEach(a => {
          a.classList.toggle('active', a.getAttribute('href') === '#' + id);
        });
      }
    });
  }, { rootMargin: '-30% 0px -60% 0px' });
  document.querySelectorAll('.check-section[id]').forEach(el => observer.observe(el));
})();
</script>
</body>
</html>
