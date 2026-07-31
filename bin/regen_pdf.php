<?php
/**
 * Пересобирает PDF существующего аудита без повторного обхода сайта.
 * Нужен после правок шаблона report_pdf.php.
 *
 * Запуск: php bin/regen_pdf.php <uuid|last>
 */
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use SeoAuditor\Core\Config;
use SeoAuditor\Core\Database;
use SeoAuditor\Report\ReportBuilder;

Config::load(BASE_PATH . '/config/config.php');

$arg = $argv[1] ?? '';
if ($arg === '' || in_array($arg, ['-h', '--help'], true)) {
    echo "\nПересборка PDF существующего аудита.\n\n";
    echo "  php bin/regen_pdf.php <uuid>   — конкретный аудит\n";
    echo "  php bin/regen_pdf.php last     — последний завершённый\n\n";
    exit($arg === '' ? 1 : 0);
}

$audit = $arg === 'last'
    ? Database::query("SELECT * FROM audits WHERE status='done' ORDER BY id DESC LIMIT 1")->fetch()
    : Database::query('SELECT * FROM audits WHERE uuid = ?', [$arg])->fetch();

if (!$audit) {
    fwrite(STDERR, "Аудит не найден: $arg\n");
    exit(1);
}

$issues   = Database::query('SELECT * FROM audit_issues WHERE audit_id = ?', [$audit['id']])->fetchAll();
$pages    = Database::query('SELECT * FROM audit_pages  WHERE audit_id = ?', [$audit['id']])->fetchAll();
$reportRow = Database::query('SELECT audit_data FROM audit_reports WHERE audit_id = ?', [$audit['id']])->fetch();
$siteData = json_decode($reportRow['audit_data'] ?? '{}', true) ?: [];

echo "Аудит #{$audit['id']} — {$audit['url']}\n";
echo "Проблем: " . count($issues) . ", страниц: " . count($pages) . "\n";

$builder = new ReportBuilder();
$pdfHtml = $builder->buildPdf((int)$audit['id'], $pages, $issues, $siteData);
$pdfPath = $builder->exportPdf($pdfHtml, $audit['uuid']);

if ($pdfPath === '' || !is_file($pdfPath)) {
    fwrite(STDERR, "PDF не создан — смотрите logs/\n");
    exit(1);
}

Database::query('UPDATE audit_reports SET pdf_path = ? WHERE audit_id = ?', [$pdfPath, $audit['id']]);

printf("Готово: %s (%s КБ)\n", $pdfPath, number_format(filesize($pdfPath) / 1024, 0, '.', ' '));
printf("Скачать: %s/api/pdf.php?id=%s\n", rtrim(Config::get('app.url'), '/'), $audit['uuid']);
