<?php
define('BASE_PATH', dirname(dirname(__DIR__)));
require BASE_PATH . '/vendor/autoload.php';

use SeoAuditor\Core\Config;
use SeoAuditor\Core\Database;

Config::load(BASE_PATH . '/config/config.php');

$uuid = trim($_GET['id'] ?? '');
if (empty($uuid)) { http_response_code(422); exit; }

$audit = Database::query('SELECT * FROM audits WHERE uuid = ?', [$uuid])->fetch();
if (!$audit) { http_response_code(404); exit; }

$report = Database::query('SELECT pdf_path FROM audit_reports WHERE audit_id = ?', [$audit['id']])->fetch();
$pdfPath = $report['pdf_path'] ?? '';

if (empty($pdfPath) || !file_exists($pdfPath)) {
    http_response_code(404);
    echo 'PDF ещё не готов';
    exit;
}

$host = parse_url($audit['url'], PHP_URL_HOST);
$filename = "seo-audit-$host.pdf";

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($pdfPath));
readfile($pdfPath);
