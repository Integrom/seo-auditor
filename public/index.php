<?php
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use SeoAuditor\Core\Config;

Config::load(BASE_PATH . '/config/config.php');
$captchaSitekey = Config::get('captcha.sitekey', '');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SEO Аудитор — Бесплатный аудит вашего сайта</title>
<meta name="description" content="Автоматический SEO и технический аудит сайта. Проверка скорости, адаптивности, уязвимостей, ФЗ-152. Получите отчёт на email.">
<link rel="stylesheet" href="/assets/css/app.css?v=2">
<script src="https://smartcaptcha.yandexcloud.net/captcha.js" defer></script>
</head>
<body>

<header class="header">
  <div class="container">
    <div class="logo"><span class="logo-accent">▸</span> SEO Аудитор</div>
  </div>
</header>

<section class="hero">
  <div class="hero-bg"></div>
  <div class="container">
    <div class="hero-content">
      <div class="hero-eyebrow">Технический аудит сайта</div>
      <h1 class="hero-title">Полный аудит вашего сайта<br><span>за 5 минут</span></h1>
      <p class="hero-subtitle">SEO, технический аудит, скорость, адаптивность, безопасность и ФЗ-152.<br>Готовый отчёт и ТЗ на исправление — на ваш email.</p>

      <div class="audit-form-card">
        <form id="auditForm" class="audit-form">
          <div class="form-group">
            <label for="url">URL сайта</label>
            <input type="url" id="url" name="url" placeholder="https://example.ru" required autocomplete="off">
          </div>
          <div class="form-group">
            <label for="email">Email для отчёта</label>
            <input type="email" id="email" name="email" placeholder="you@example.ru" required>
          </div>
          <div class="form-group">
            <div id="captcha-container" class="smart-captcha" data-sitekey="<?= htmlspecialchars($captchaSitekey, ENT_QUOTES) ?>"></div>
          </div>
          <button type="submit" class="btn-submit" id="submitBtn">
            <span class="btn-text">Запустить аудит</span>
            <span class="btn-loading" style="display:none">Запускаем...</span>
          </button>
          <div class="form-error" id="formError" style="display:none"></div>
        </form>
      </div>
    </div>
  </div>
</section>

<section class="features">
  <div class="container">
    <h2 class="section-title">Что <span class="section-title-accent">проверяем</span></h2>
    <div class="features-grid">
      <div class="feature-card">
        <div class="feature-icon">🔍</div>
        <h3>SEO аудит</h3>
        <p>Title, meta description, H1–H6, alt-теги, canonical, Schema.org, Open Graph, sitemap, robots.txt</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">⚙️</div>
        <h3>Технический аудит</h3>
        <p>HTTPS, редиректы, HTTP/2, gzip-сжатие, кэширование, битые ссылки, страница 404</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">⚡</div>
        <h3>Скорость загрузки</h3>
        <p>Время ответа сервера, Core Web Vitals, оптимизация изображений, lazyload, WebP</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">📱</div>
        <h3>Адаптивность</h3>
        <p>Мобильная версия, viewport, медиа-запросы, размер шрифтов, touch-friendly</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">🛡️</div>
        <h3>Безопасность</h3>
        <p>CSP, HSTS, X-Frame-Options, открытые панели, .env и .git файлы, mixed content</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">📋</div>
        <h3>ФЗ-152</h3>
        <p>Политика конфиденциальности, cookie-баннер, согласие на обработку ПД, счётчики</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">🌐</div>
        <h3>CMS и технологии</h3>
        <p>Определение CMS, версии PHP/сервера, JS-фреймворки, системы аналитики</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">📍</div>
        <h3>IP и хостинг</h3>
        <p>IP-адрес, геолокация сервера, провайдер хостинга, AS-номер</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">🤖</div>
        <h3>Готовность к AI-поиску</h3>
        <p>Доступ AI-краулеров, Schema.org, llms.txt, структура контента под цитирование в AI-ответах</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">📄</div>
        <h3>Отчёт + ТЗ</h3>
        <p>Детальный HTML-отчёт и PDF с планом работ: приоритеты P1–P4 и оценка трудозатрат</p>
      </div>
    </div>
  </div>
</section>

<footer class="footer">
  <div class="container">
    <p>© 2025 SEO Аудитор &nbsp;|&nbsp; seo.magnit365.ru</p>
  </div>
</footer>

<script src="/assets/js/app.js?v=2"></script>
</body>
</html>
