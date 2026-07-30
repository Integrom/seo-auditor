<?php
use SeoAuditor\Core\Env;

Env::load(dirname(__DIR__) . '/.env');

return [
    'app' => [
        'url'   => Env::get('APP_URL', 'https://seo.magnit365.ru'),
        'name'  => 'SEO Аудитор',
        'debug' => (bool) Env::get('APP_DEBUG', false),
    ],
    'db' => [
        'host'    => Env::get('DB_HOST', 'localhost'),
        'dbname'  => Env::get('DB_NAME', 'seo_auditor'),
        'user'    => Env::get('DB_USER', 'seo_user'),
        'pass'    => Env::get('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        'host'      => Env::get('MAIL_HOST', 'localhost'),
        'port'      => (int) Env::get('MAIL_PORT', 25),
        'username'  => Env::get('MAIL_USER', ''),
        'password'  => Env::get('MAIL_PASS', ''),
        'from'      => Env::get('MAIL_FROM', 'noreply@magnit365.ru'),
        'from_name' => Env::get('MAIL_FROM_NAME', 'SEO Аудитор'),
    ],
    'crawler' => [
        'max_pages'  => (int) Env::get('CRAWLER_MAX_PAGES', 500),
        'timeout'    => (int) Env::get('CRAWLER_TIMEOUT', 10),
        'delay'      => (float) Env::get('CRAWLER_DELAY', 0.3),
        'user_agent' => Env::get('CRAWLER_USER_AGENT', 'SeoAuditorBot/1.0 (+https://seo.magnit365.ru)'),
    ],
    'captcha' => [
        'sitekey' => Env::get('CAPTCHA_SITEKEY', ''),
        'secret'  => Env::get('CAPTCHA_SECRET', ''),
    ],
    'pagespeed' => [
        'api_key' => Env::get('PAGESPEED_API_KEY', ''),
    ],
    'reports_dir' => Env::get('REPORTS_DIR', dirname(__DIR__) . '/reports'),
];
