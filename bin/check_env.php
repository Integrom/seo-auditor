<?php
/**
 * Диагностика окружения: PHP, расширения, конфигурация, БД, права на каталоги.
 * Запуск: php bin/check_env.php
 */
define('BASE_PATH', dirname(__DIR__));

$ok = 0; $fail = 0; $warn = 0;

function line(string $status, string $label, string $detail = ''): void
{
    global $ok, $fail, $warn;
    $mark = match ($status) { 'ok' => '[ OK ]', 'fail' => '[FAIL]', default => '[WARN]' };
    match ($status) { 'ok' => $ok++, 'fail' => $fail++, default => $warn++ };
    // printf выравнивает по байтам, кириллица занимает по 2 — считаем символы вручную
    $pad = max(1, 34 - mb_strlen($label));
    echo $mark . ' ' . $label . str_repeat(' ', $pad) . $detail . "\n";
}

echo "\n=== SEO Аудитор: проверка окружения ===\n\n";

// ── PHP ────────────────────────────────────────────────────────────────
echo "PHP\n";
version_compare(PHP_VERSION, '8.1', '>=')
    ? line('ok', 'Версия PHP', PHP_VERSION)
    : line('fail', 'Версия PHP', PHP_VERSION . ' — требуется 8.1 или выше');

foreach (['pdo_mysql' => 'работа с MySQL', 'curl' => 'HTTP-запросы', 'mbstring' => 'кириллица',
          'dom' => 'парсинг HTML', 'json' => 'обмен данными', 'gd' => 'PDF через mPDF'] as $ext => $why) {
    extension_loaded($ext)
        ? line('ok', "Расширение $ext", $why)
        : line('fail', "Расширение $ext", "не установлено — нужно для: $why");
}

// ── Зависимости ────────────────────────────────────────────────────────
echo "\nЗависимости\n";
if (!is_file(BASE_PATH . '/vendor/autoload.php')) {
    line('fail', 'Composer-зависимости', 'нет vendor/ — выполните: composer install');
    echo "\nПроверка прервана: без зависимостей дальше нельзя.\n";
    exit(1);
}
line('ok', 'Composer-зависимости', 'vendor/autoload.php найден');
require BASE_PATH . '/vendor/autoload.php';

foreach (['GuzzleHttp\Client' => 'Guzzle', 'Symfony\Component\DomCrawler\Crawler' => 'DomCrawler',
          'Mpdf\Mpdf' => 'mPDF', 'PHPMailer\PHPMailer\PHPMailer' => 'PHPMailer'] as $class => $name) {
    class_exists($class) ? line('ok', "Библиотека $name", 'загружается')
                         : line('fail', "Библиотека $name", 'класс не найден — composer install');
}
class_exists('SeoAuditor\Checks\AiReadinessCheck')
    ? line('ok', 'Автолоадер проекта', 'классы SeoAuditor доступны')
    : line('fail', 'Автолоадер проекта', 'выполните: composer dump-autoload -o');

// ── Конфигурация ───────────────────────────────────────────────────────
echo "\nКонфигурация\n";
if (!is_file(BASE_PATH . '/.env')) {
    line('fail', 'Файл .env', 'отсутствует — выполните: cp .env.example .env');
} else {
    line('ok', 'Файл .env', 'найден');
}

use SeoAuditor\Core\Config;
use SeoAuditor\Core\Database;

Config::load(BASE_PATH . '/config/config.php');

$db = Config::get('db');
!empty($db['pass']) ? line('ok', 'Пароль БД (DB_PASS)', 'задан')
                    : line('warn', 'Пароль БД (DB_PASS)', 'пустой — если у пользователя БД есть пароль, укажите его');
line('ok', 'База данных', "{$db['user']}@{$db['host']}/{$db['dbname']}");

Config::get('captcha.secret')
    ? line('ok', 'SmartCaptcha', 'ключи заданы — работает веб-форма')
    : line('warn', 'SmartCaptcha', 'ключей нет — веб-форма не примет заявку, тестируйте через bin/audit.php');
Config::get('pagespeed.api_key')
    ? line('ok', 'PageSpeed API', 'ключ задан — будут полевые Core Web Vitals')
    : line('warn', 'PageSpeed API', 'ключа нет — Core Web Vitals не собираются (не критично)');

// ── Каталоги ───────────────────────────────────────────────────────────
echo "\nКаталоги\n";
foreach (['reports' => Config::get('reports_dir'), 'logs' => BASE_PATH . '/logs'] as $name => $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir)) {
        line('fail', "Каталог $name", "не существует и не создаётся: $dir");
    } elseif (!is_writable($dir)) {
        line('fail', "Каталог $name", "нет прав на запись: $dir");
    } else {
        line('ok', "Каталог $name", $dir);
    }
}

// ── Подключение к БД и схема ───────────────────────────────────────────
echo "\nСоединение с БД\n";
try {
    Database::query('SELECT 1');
    line('ok', 'Подключение к MySQL', 'установлено');

    $tables = ['audits', 'audit_pages', 'audit_issues', 'audit_reports'];
    $missing = [];
    foreach ($tables as $t) {
        if (Database::query("SHOW TABLES LIKE '$t'")->rowCount() === 0) $missing[] = $t;
    }
    $missing ? line('fail', 'Таблицы схемы', 'нет: ' . implode(', ', $missing) . ' — примените sql/schema.sql')
             : line('ok', 'Таблицы схемы', 'все 4 таблицы на месте');

    if (!$missing) {
        $cols = array_column(Database::query('SHOW COLUMNS FROM audits')->fetchAll(), 'Field');
        $needed = array_diff(['host', 'previous_audit_id', 'score'], $cols);
        $needed ? line('fail', 'Миграция 001', 'нет колонок: ' . implode(', ', $needed) . ' — примените sql/migration_001_comparison.sql')
                : line('ok', 'Миграция 001', 'колонки сравнения аудитов на месте');
    }
} catch (\Throwable $e) {
    line('fail', 'Подключение к MySQL', $e->getMessage());
}

// ── Внешняя сеть ───────────────────────────────────────────────────────
echo "\nВнешняя сеть\n";
try {
    $client = new GuzzleHttp\Client(['timeout' => 10, 'verify' => false, 'http_errors' => false]);
    $code = $client->get('https://example.com')->getStatusCode();
    $code === 200 ? line('ok', 'Исходящие HTTP-запросы', 'example.com отвечает 200')
                  : line('warn', 'Исходящие HTTP-запросы', "example.com вернул $code");
} catch (\Throwable $e) {
    line('fail', 'Исходящие HTTP-запросы', 'нет доступа в интернет: ' . $e->getMessage());
}

// ── Итог ───────────────────────────────────────────────────────────────
echo "\n" . str_repeat('─', 62) . "\n";
printf("Успешно: %d   Предупреждений: %d   Ошибок: %d\n\n", $ok, $warn, $fail);

if ($fail > 0) {
    echo "Есть блокирующие проблемы — исправьте их перед запуском аудита.\n";
    exit(1);
}
echo "Окружение готово. Запустите тестовый аудит:\n";
echo "  php bin/audit.php https://example.com test@example.com\n\n";
exit(0);
