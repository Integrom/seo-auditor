<?php
/**
 * Запуск аудита из командной строки — минуя веб-форму и капчу.
 * Удобно для локального тестирования и отладки проверок.
 *
 * Запуск: php bin/audit.php <url> [email] [--pages=N]
 */
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use SeoAuditor\Core\Config;
use SeoAuditor\Core\Database;
use SeoAuditor\Audit\AuditManager;

Config::load(BASE_PATH . '/config/config.php');

// ── Разбор аргументов ──────────────────────────────────────────────────
$args     = array_slice($argv, 1);
$options  = [];
$positional = [];
foreach ($args as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
    } else {
        $positional[] = $arg;
    }
}

$url   = $positional[0] ?? '';
$email = $positional[1] ?? 'test@example.com';

if ($url === '' || in_array($url, ['-h', '--help'], true)) {
    echo <<<TXT

Запуск аудита сайта из консоли.

  php bin/audit.php <url> [email] [--pages=N]

Аргументы:
  url        адрес сайта, например https://example.com
  email      куда отправить отчёт (по умолчанию test@example.com)
  --pages=N  ограничить обход N страницами (для быстрой проверки)

Пример быстрой проверки:
  php bin/audit.php https://example.com test@example.com --pages=5


TXT;
    exit($url === '' ? 1 : 0);
}

if (!preg_match('#^https?://#i', $url)) {
    $url = 'https://' . $url;
}
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    fwrite(STDERR, "Некорректный URL: $url\n");
    exit(1);
}

// Ограничение числа страниц — только для этого запуска
if (isset($options['pages'])) {
    $limit = max(1, (int)$options['pages']);
    $ref = new ReflectionClass(Config::class);
    $prop = $ref->getProperty('data');
    $prop->setAccessible(true);
    $data = $prop->getValue();
    $data['crawler']['max_pages'] = $limit;
    $prop->setValue(null, $data);
    echo "Обход ограничен: $limit страниц\n";
}

$host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

// ── Создаём задание ────────────────────────────────────────────────────
try {
    $prev = Database::query(
        "SELECT id FROM audits WHERE host = ? AND status = 'done' ORDER BY completed_at DESC LIMIT 1",
        [$host]
    )->fetch();

    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    $auditId = Database::insert('audits', [
        'uuid'              => $uuid,
        'url'               => $url,
        'host'              => $host,
        'email'             => $email,
        'status'            => 'pending',
        'progress'          => 0,
        'progress_text'     => 'Запуск из консоли...',
        'previous_audit_id' => $prev ? (int)$prev['id'] : null,
    ]);
} catch (\Throwable $e) {
    fwrite(STDERR, "Не удалось создать задание: {$e->getMessage()}\n");
    fwrite(STDERR, "Проверьте окружение: php bin/check_env.php\n");
    exit(1);
}

echo "\nАудит #$auditId — $url\n";
echo "UUID: $uuid\n";
if ($prev) echo "Найден предыдущий аудит #{$prev['id']} — будет сравнение\n";
echo str_repeat('─', 62) . "\n";

$started = microtime(true);

try {
    (new AuditManager($auditId))->run();
} catch (\Throwable $e) {
    fwrite(STDERR, "\nАудит завершился с ошибкой: {$e->getMessage()}\n");
    fwrite(STDERR, "{$e->getFile()}:{$e->getLine()}\n");
    exit(1);
}

$elapsed = round(microtime(true) - $started);

// ── Итоги ──────────────────────────────────────────────────────────────
$audit = Database::query('SELECT * FROM audits WHERE id = ?', [$auditId])->fetch();
$stats = Database::query(
    "SELECT severity, COUNT(*) c FROM audit_issues WHERE audit_id = ? GROUP BY severity",
    [$auditId]
)->fetchAll();
$bySeverity = array_column($stats, 'c', 'severity');
$report = Database::query('SELECT pdf_path, LENGTH(html_report) len FROM audit_reports WHERE audit_id = ?', [$auditId])->fetch();

printf("\nГотово за %d сек.\n\n", $elapsed);
printf("  Оценка сайта      %s / 100\n", $audit['score']);
printf("  Страниц обойдено  %s\n", $audit['pages_total']);
printf("  Критических       %s\n", $bySeverity['critical'] ?? 0);
printf("  Предупреждений    %s\n", $bySeverity['warning'] ?? 0);
printf("  Рекомендаций      %s\n", $bySeverity['info'] ?? 0);

echo "\nПо разделам:\n";
$byType = Database::query(
    "SELECT check_type, COUNT(*) c,
            SUM(severity='critical') crit, SUM(severity='warning') warn
     FROM audit_issues WHERE audit_id = ? GROUP BY check_type ORDER BY crit DESC, warn DESC",
    [$auditId]
)->fetchAll();
foreach ($byType as $r) {
    printf("  %-16s всего %-4s критичных %-4s важных %s\n", $r['check_type'], $r['c'], $r['crit'], $r['warn']);
}

echo "\nОтчёт:\n";
printf("  HTML  %s байт в БД\n", $report['len'] ?? 0);
printf("  PDF   %s\n", $report['pdf_path'] ?: 'не создан — смотрите logs/');
printf("  URL   %s/api/report.php?id=%s\n", rtrim(Config::get('app.url'), '/'), $uuid);
echo "\nЛокально: php -S localhost:8000 -t public, затем\n";
printf("  http://localhost:8000/api/report.php?id=%s\n\n", $uuid);
