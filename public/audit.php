<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SEO Аудит — результаты</title>
<link rel="stylesheet" href="/assets/css/app.css?v=2">
</head>
<body>

<header class="header">
  <div class="container">
    <a href="/" class="logo"><span class="logo-accent">▸</span> SEO Аудитор</a>
  </div>
</header>

<div class="container audit-page">
  <div id="progressSection" class="progress-card">
    <div class="progress-header">
      <h1 id="progressTitle">Запускаем аудит...</h1>
      <p id="progressUrl" class="progress-url"></p>
    </div>

    <div class="progress-bar-wrap">
      <div class="progress-bar-track">
        <div class="progress-bar-fill" id="progressBar" style="width: 0%"></div>
      </div>
      <div class="progress-percent" id="progressPercent">0%</div>
    </div>

    <div class="progress-steps" id="progressSteps">
      <div class="step" data-from="0"  data-to="30"  id="step-crawl">
        <div class="step-icon">🕷️</div>
        <div class="step-info">
          <div class="step-name">Обход сайта</div>
          <div class="step-desc" id="step-crawl-desc">Сбор страниц...</div>
        </div>
        <div class="step-status waiting">•</div>
      </div>
      <div class="step" data-from="30" data-to="40" id="step-cms">
        <div class="step-icon">🔍</div>
        <div class="step-info"><div class="step-name">CMS и технологии</div></div>
        <div class="step-status waiting">•</div>
      </div>
      <div class="step" data-from="40" data-to="55" id="step-seo">
        <div class="step-icon">📊</div>
        <div class="step-info"><div class="step-name">SEO аудит</div></div>
        <div class="step-status waiting">•</div>
      </div>
      <div class="step" data-from="55" data-to="65" id="step-tech">
        <div class="step-icon">⚙️</div>
        <div class="step-info"><div class="step-name">Технический аудит</div></div>
        <div class="step-status waiting">•</div>
      </div>
      <div class="step" data-from="65" data-to="75" id="step-speed">
        <div class="step-icon">⚡</div>
        <div class="step-info"><div class="step-name">Скорость и адаптивность</div></div>
        <div class="step-status waiting">•</div>
      </div>
      <div class="step" data-from="75" data-to="90" id="step-sec">
        <div class="step-icon">🛡️</div>
        <div class="step-info"><div class="step-name">Безопасность и ФЗ-152</div></div>
        <div class="step-status waiting">•</div>
      </div>
      <div class="step" data-from="90" data-to="100" id="step-report">
        <div class="step-icon">📄</div>
        <div class="step-info"><div class="step-name">Формирование отчёта</div></div>
        <div class="step-status waiting">•</div>
      </div>
    </div>

    <div class="progress-text-wrap">
      <div class="progress-spinner" id="progressSpinner"></div>
      <span id="progressText">Инициализация...</span>
    </div>
  </div>

  <!-- Результат -->
  <div id="resultSection" style="display:none">
    <div class="result-header">
      <div class="result-icon success">✓</div>
      <div>
        <h2>Аудит завершён!</h2>
        <p id="resultUrl" class="progress-url"></p>
      </div>
    </div>

    <div class="result-summary" id="resultSummary"></div>

    <div class="result-actions">
      <a href="#" id="btnViewReport" class="btn-primary" target="_blank">📊 Открыть отчёт</a>
      <a href="#" id="btnDownloadPdf" class="btn-secondary">⬇️ Скачать PDF</a>
      <a href="/" class="btn-outline">← Новый аудит</a>
    </div>

    <div class="report-embed" id="reportEmbed"></div>
  </div>

  <!-- Ошибка -->
  <div id="errorSection" style="display:none" class="error-card">
    <div class="result-icon error">✗</div>
    <h2>Ошибка аудита</h2>
    <p id="errorText"></p>
    <a href="/" class="btn-primary" style="margin-top:20px;display:inline-block">← Попробовать снова</a>
  </div>
</div>

<script>
const AUDIT_ID = <?= json_encode(htmlspecialchars($_GET['id'] ?? '')) ?>;
</script>
<script src="/assets/js/app.js"></script>
</body>
</html>
