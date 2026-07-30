<?php
chdir('/var/www/seo.magnit365.ru');
require '/var/www/seo.magnit365.ru/vendor/autoload.php';
use SeoAuditor\Core\Config;
use SeoAuditor\Core\Database;
Config::load('/var/www/seo.magnit365.ru/config/config.php');
// Database подключается лениво при первом запросе

$audit = Database::query('SELECT * FROM audits WHERE status="done" ORDER BY id DESC LIMIT 1')->fetch();
if (!$audit) { echo "No audit found\n"; exit(1); }
echo "Audit: " . $audit['url'] . " (id=" . $audit['id'] . ")\n";

$issues   = Database::query('SELECT * FROM audit_issues WHERE audit_id = ?', [$audit['id']])->fetchAll();
$pages    = Database::query('SELECT * FROM audit_pages  WHERE audit_id = ?', [$audit['id']])->fetchAll();
$arRow    = Database::query('SELECT audit_data FROM audit_reports WHERE audit_id = ?', [$audit['id']])->fetch();
$siteData = json_decode($arRow['audit_data'] ?? '{}', true) ?: [];

$builder = new \SeoAuditor\Report\ReportBuilder();
$pdfHtml = $builder->buildPdf($audit['id'], $pages, $issues, $siteData);
$pdfPath = $builder->exportPdf($pdfHtml, $audit['uuid'] . '_v2');

echo "PDF: $pdfPath\n";
if ($pdfPath && file_exists($pdfPath)) {
    echo "Size: " . filesize($pdfPath) . " bytes\n";
} else {
    echo "PDF not created or empty path\n";
}
