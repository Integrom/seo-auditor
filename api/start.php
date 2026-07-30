<?php
define('BASE_PATH', dirname(dirname(__DIR__)));
require BASE_PATH . '/vendor/autoload.php';

use SeoAuditor\Core\Config;
use SeoAuditor\Core\Database;

Config::load(BASE_PATH . '/config/config.php');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$url  = trim($body['url'] ?? '');
$email = trim($body['email'] ?? '');

// Проверка SmartCaptcha
$captchaToken = $body['captchaToken'] ?? '';
if (empty($captchaToken)) {
    http_response_code(422);
    echo json_encode(['error' => 'Необходимо пройти проверку капчи']);
    exit;
}
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$captchaSecret = Config::get('captcha.secret', '');
if (empty($captchaSecret)) {
    http_response_code(500);
    echo json_encode(['error' => 'Капча не настроена на сервере']);
    exit;
}
$captchaResp = @file_get_contents(
    'https://smartcaptcha.yandexcloud.net/validate?secret=' . urlencode($captchaSecret)
    . '&token=' . urlencode($captchaToken)
    . '&ip=' . urlencode($ip)
);
$captchaData = $captchaResp ? json_decode($captchaResp, true) : null;
if (!$captchaData || ($captchaData['status'] ?? '') !== 'ok') {
    http_response_code(422);
    echo json_encode(['error' => 'Проверка капчи не пройдена. Попробуйте снова.']);
    exit;
}

// Валидация URL
if (empty($url)) {
    http_response_code(422);
    echo json_encode(['error' => 'URL обязателен']);
    exit;
}
if (!preg_match('/^https?:\/\//i', $url)) {
    $url = 'https://' . $url;
}
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(422);
    echo json_encode(['error' => 'Некорректный URL']);
    exit;
}
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['error' => 'Некорректный email']);
    exit;
}

// Генерируем UUID
$uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);

try {
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

    // Ищем предыдущий завершённый аудит этого домена
    $prevAudit = Database::query(
        "SELECT id, score FROM audits WHERE host = ? AND status = 'done' ORDER BY completed_at DESC LIMIT 1",
        [$host]
    )->fetch();

    $auditId = Database::insert('audits', [
        'uuid'             => $uuid,
        'url'              => $url,
        'host'             => $host,
        'email'            => $email,
        'status'           => 'pending',
        'progress'         => 0,
        'progress_text'    => 'В очереди...',
        'previous_audit_id' => $prevAudit ? (int)$prevAudit['id'] : null,
    ]);

    // Запускаем воркер в фоне
    $workerPath = BASE_PATH . '/jobs/worker.php';
    $logPath    = '/var/www/seo.magnit365.ru/logs/worker.log';
    exec("php $workerPath >> $logPath 2>&1 &");

    echo json_encode([
        'success'   => true,
        'audit_id'  => $uuid,
        'url'       => $url,
        'is_repeat' => $prevAudit ? true : false,
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка при создании задания: ' . $e->getMessage()]);
}
