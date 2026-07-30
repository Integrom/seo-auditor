<?php
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use SeoAuditor\Core\Config;
use SeoAuditor\Core\Database;
use SeoAuditor\Audit\AuditManager;

Config::load(BASE_PATH . '/config/config.php');

// Проверяем lock-файл
$lockFile = sys_get_temp_dir() . '/seo_worker.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 300) {
    exit(0); // Воркер уже запущен
}
file_put_contents($lockFile, getmypid());

try {
    // Берём одно задание из очереди
    $job = Database::query(
        "SELECT id FROM audits WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1"
    )->fetch();

    if (!$job) {
        unlink($lockFile);
        exit(0);
    }

    $auditId = (int)$job['id'];
    error_log("[Worker] Starting audit #$auditId");

    $manager = new AuditManager($auditId);
    $manager->run();

    error_log("[Worker] Audit #$auditId completed");
} catch (\Exception $e) {
    error_log("[Worker] Error: " . $e->getMessage());
} finally {
    if (file_exists($lockFile)) unlink($lockFile);
}
