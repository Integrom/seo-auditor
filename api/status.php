<?php
define('BASE_PATH', dirname(dirname(__DIR__)));
require BASE_PATH . '/vendor/autoload.php';

use SeoAuditor\Core\Config;
use SeoAuditor\Core\Database;

Config::load(BASE_PATH . '/config/config.php');
header('Content-Type: application/json; charset=utf-8');

$uuid = trim($_GET['id'] ?? '');
if (empty($uuid)) {
    http_response_code(422);
    echo json_encode(['error' => 'ID обязателен']);
    exit;
}

$audit = Database::query('SELECT * FROM audits WHERE uuid = ?', [$uuid])->fetch();
if (!$audit) {
    http_response_code(404);
    echo json_encode(['error' => 'Аудит не найден']);
    exit;
}

$result = [
    'status'        => $audit['status'],
    'progress'      => (int)$audit['progress'],
    'progress_text' => $audit['progress_text'],
    'pages_total'   => (int)$audit['pages_total'],
    'pages_crawled' => (int)$audit['pages_crawled'],
];

if ($audit['status'] === 'done') {
    $counts = Database::query(
        "SELECT severity, COUNT(*) as cnt FROM audit_issues WHERE audit_id = ? GROUP BY severity",
        [$audit['id']]
    )->fetchAll();
    $summary = [];
    foreach ($counts as $row) $summary[$row['severity']] = (int)$row['cnt'];
    $result['summary'] = $summary;
}

if ($audit['status'] === 'error') {
    $result['error_message'] = $audit['error_message'];
}

echo json_encode($result);
