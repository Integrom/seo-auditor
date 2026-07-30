<?php
define('BASE_PATH', dirname(dirname(__DIR__)));
require BASE_PATH . '/vendor/autoload.php';

use SeoAuditor\Core\Config;
use SeoAuditor\Core\Database;

Config::load(BASE_PATH . '/config/config.php');

$uuid = trim($_GET['id'] ?? '');
if (empty($uuid)) { http_response_code(422); exit; }

$audit = Database::query('SELECT id FROM audits WHERE uuid = ?', [$uuid])->fetch();
if (!$audit) { http_response_code(404); exit; }

$report = Database::query('SELECT html_report FROM audit_reports WHERE audit_id = ?', [$audit['id']])->fetch();
if (!$report) { http_response_code(404); exit; }

$pdfUrl = '/api/pdf.php?id=' . htmlspecialchars($uuid, ENT_QUOTES);

// Новый шаблон (тема + кнопка PDF встроены) — отдаём как есть
if (str_contains($report['html_report'], 'id="pdf-btn"')) {
    header('Content-Type: text/html; charset=utf-8');
    echo $report['html_report'];
    exit;
}

// Легаси-инъекция для отчётов, сгенерированных старым шаблоном
$injection = '<style id="theme-dark">
:root {
  --c-red-bg:    rgba(239,68,68,.14);
  --c-yellow-bg: rgba(245,158,11,.12);
  --c-blue-bg:   rgba(59,130,246,.12);
  --c-green-bg:  rgba(34,197,94,.10);
  --c-purple-bg: rgba(139,92,246,.12);
  --text:   #d8e8f0;
  --text-2: #5f7a8a;
  --text-3: #2e4050;
  --border: #1a2535;
  --bg:     #07090c;
  --white:  #101820;
  --radius: 3px;
  --shadow: 0 4px 20px rgba(0,0,0,.5);
}
body { background:#07090c !important; color:#d8e8f0 !important; }
/* Заголовок отчёта */
.report-header { background: linear-gradient(135deg, #0d1117 0%, #101820 100%) !important; border-bottom:2px solid #f97316 !important; }
.report-header .report-score-badge { background:rgba(249,115,22,.15) !important; border:1px solid rgba(249,115,22,.3) !important; }
/* Инфо-карточки */
.info-card { background:#101820 !important; border-color:#1a2535 !important; color:#d8e8f0 !important; }
.info-card .info-label, .info-card .label { color:#5f7a8a !important; }
.info-card .info-value, .info-card .value { color:#d8e8f0 !important; }
/* Сводные карточки */
.summary-card { background:#101820 !important; border-color:#1a2535 !important; }
.summary-card .summary-label { color:#5f7a8a !important; }
/* Секции проверок */
.section { background:#101820 !important; border-color:#1a2535 !important; }
.check-section-head { background:#0d1117 !important; border-bottom-color:#1a2535 !important; }
.check-section-head:hover { background:rgba(255,255,255,.04) !important; }
/* TZ секции */
.tz-section { background:#101820 !important; border-color:#1a2535 !important; }
.tz-section h3, .tz-section .tz-head { background:#0d1117 !important; border-color:#1a2535 !important; }
/* Таблицы */
.toc-list li a.active { background:rgba(249,115,22,.15) !important; color:#f97316 !important; }
.pages-table tr:hover td { background:rgba(255,255,255,.03) !important; }
.pages-table th { background:#0d1117 !important; color:#5f7a8a !important; }
.pages-table td { border-color:#1a2535 !important; }
/* Категории дашборда */
.db-cat-card { background:#101820 !important; border-color:#1a2535 !important; }
.db-cat-card:hover { border-color:rgba(249,115,22,.35) !important; box-shadow:0 4px 20px rgba(249,115,22,.1) !important; }
/* Рекомендации */
.ic-rec, .issue-rec { background:rgba(34,197,94,.08) !important; color:#4ade80 !important; border-color:rgba(34,197,94,.2) !important; }
.tz-rec  { background:rgba(34,197,94,.07) !important; color:#4ade80 !important; border-color:rgba(34,197,94,.2) !important; }
.diff-section.fixed > h3    { background:rgba(34,197,94,.1)  !important; color:#4ade80 !important; border-color:rgba(34,197,94,.2)  !important; }
.diff-section.new-iss > h3  { background:rgba(59,130,246,.1) !important; color:#60a5fa !important; border-color:rgba(59,130,246,.2) !important; }
.no-prev-notice { background:#101820 !important; }
/* Бейджи */
.badge.info     { background:rgba(59,130,246,.15) !important; color:#60a5fa !important; }
.badge.warning  { background:rgba(245,158,11,.12) !important; color:#fbbf24 !important; }
.badge.error, .badge.critical { background:rgba(239,68,68,.12) !important; color:#f87171 !important; }
.badge.success  { background:rgba(34,197,94,.12) !important; color:#4ade80 !important; }
/* TZ элементы */
.tz-item { background:#101820 !important; border-color:#1a2535 !important; }
.tz-item.warning { background:rgba(245,158,11,.06) !important; border-color:rgba(245,158,11,.2) !important; }
.tz-item.critical, .tz-item.error { background:rgba(239,68,68,.06) !important; border-color:rgba(239,68,68,.2) !important; }
/* Вкладки */
.tab-btn { background:transparent !important; color:#5f7a8a !important; border-color:transparent !important; }
.tab-btn.active { border-bottom-color:#f97316 !important; color:#d8e8f0 !important; }
.tab-btn:hover { color:#d8e8f0 !important; }
.tabs-bar, .tabs-nav { background:#0d1117 !important; border-color:#1a2535 !important; }
/* Иконки категорий */
.db-cat-icon.seo       { background:rgba(139,92,246,.15) !important; }
.db-cat-icon.technical { background:rgba(59,130,246,.12) !important; }
.db-cat-icon.speed     { background:rgba(245,158,11,.12) !important; }
.db-cat-icon.security  { background:rgba(239,68,68,.12)  !important; }
.db-cat-icon.fz152     { background:rgba(34,197,94,.10)  !important; }
.db-cat-icon.yandex    { background:rgba(249,115,22,.12) !important; }
/* Тексты */
.section-title, .check-title, .tz-title, .check-name, .issue-title { color:#d8e8f0 !important; }
.check-desc, .issue-desc, .page-url { color:#5f7a8a !important; }
.text-muted, .meta, .hint { color:#5f7a8a !important; }
/* Анимации */
@keyframes tabSlideIn { from { opacity:0; transform:translateY(14px) } to { opacity:1; transform:translateY(0) } }
@keyframes barScaleX  { from { transform:scaleX(0) } to { transform:scaleX(1) } }
@keyframes numPop     { 0% { opacity:0; transform:scale(.8) } 70% { transform:scale(1.06) } 100% { opacity:1; transform:scale(1) } }
@keyframes cardIn     { from { opacity:0; transform:translateY(10px) } to { opacity:1; transform:translateY(0) } }
.tab-content.active { animation: tabSlideIn .32s ease both; }
.db-cat-bar-fill, .section-progress-fill { transform-origin:left; animation:barScaleX .85s cubic-bezier(.2,.8,.4,1) both; }
.hstat .num { animation:numPop .6s cubic-bezier(.2,.8,.4,1) both; }
.db-cat-card:nth-child(1) { animation:cardIn .4s ease .04s both; }
.db-cat-card:nth-child(2) { animation:cardIn .4s ease .09s both; }
.db-cat-card:nth-child(3) { animation:cardIn .4s ease .14s both; }
.db-cat-card:nth-child(4) { animation:cardIn .4s ease .19s both; }
.db-cat-card:nth-child(5) { animation:cardIn .4s ease .24s both; }
.db-cat-card:nth-child(6) { animation:cardIn .4s ease .29s both; }
.db-score-card, .db-radar-card { animation:cardIn .5s ease .05s both; }
/* PDF кнопка */
.pdf-fab {
  position:fixed; top:6px; right:14px; z-index:500;
  display:inline-flex; align-items:center; gap:6px;
  padding:7px 16px;
  background:#f97316; color:#fff;
  font-family:Segoe UI,system-ui,sans-serif;
  font-size:12px; font-weight:700; letter-spacing:.07em; text-transform:uppercase;
  border-radius:2px;
  text-decoration:none;
  box-shadow:0 4px 16px rgba(0,0,0,.5);
  transition:background .15s, box-shadow .15s;
  animation:cardIn .5s ease .4s both;
}
.pdf-fab:hover { background:#e06000; box-shadow:0 6px 24px rgba(249,115,22,.4); text-decoration:none; }
</style>
<a class="pdf-fab" href="' . $pdfUrl . '" target="_blank">
  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
  Скачать PDF
</a>
<script>
(function(){
  function animCount(el,target,dur){
    var t0=performance.now();
    function step(t){
      var p=Math.min((t-t0)/dur,1),e=p<.5?2*p*p:-1+(4-2*p)*p;
      el.textContent=Math.round(e*target);
      if(p<1)requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }
  var sn=document.querySelector(\'.db-score-num .sn\');
  if(sn){var v=parseInt(sn.textContent)||0;sn.textContent=\'0\';animCount(sn,v,1200);}
  document.querySelectorAll(\'.hstat .num\').forEach(function(el){
    var n=parseInt(el.textContent)||0;el.textContent=\'0\';animCount(el,n,800);
  });
  // Перезапуск анимации вкладок
  var _origActivate=window._tabActivate;
  document.querySelectorAll(\'.tab-btn\').forEach(function(btn){
    btn.addEventListener(\'click\',function(){
      var id=btn.dataset.tab;
      var tc=document.getElementById(\'tab-\'+id);
      if(tc){tc.style.animation=\'none\';tc.offsetHeight;tc.style.animation=\'\';}
    });
  });
})();
</script>';

$html = str_replace('<body>', '<body>' . $injection, $report['html_report']);

header('Content-Type: text/html; charset=utf-8');
echo $html;
