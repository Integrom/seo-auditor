<?php
define('BASE_PATH', dirname(dirname(__DIR__)));
require BASE_PATH . '/vendor/autoload.php';

use SeoAuditor\Core\Config;
use SeoAuditor\Core\Database;
use SeoAuditor\Core\RateLimiter;
use SeoAuditor\Core\UrlGuard;

Config::load(BASE_PATH . '/config/config.php');
header('Content-Type: application/json; charset=utf-8');

function fail(int $code, string $message, array $headers = []): never
{
    http_response_code($code);
    foreach ($headers as $h) header($h);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Method Not Allowed');
}

// X-Forwarded-For может содержать цепочку прокси — берём первый адрес
$ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')[0]);
if ($ip === '') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

$limiter = new RateLimiter();

/**
 * Лимиты двухуровневые. Мягкий ограничивает сам поток запросов (в том числе
 * неудачные попытки капчи — каждая стоит нам исходящего запроса к Яндексу),
 * строгий считает только реально созданные аудиты. Иначе пользователь,
 * пять раз не прошедший капчу, оказался бы заблокирован на час.
 */
$soft = $limiter->hit("req:$ip", 30, 3600);
if (!$soft['allowed']) {
    fail(429, 'Слишком много запросов. Попробуйте позже.', ['Retry-After: ' . $soft['retry_after']]);
}

$body  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$url   = trim($body['url'] ?? '');
$email = trim($body['email'] ?? '');

// ── Валидация ввода: делаем до капчи, чтобы не ходить к Яндексу из-за опечатки ──
if ($url === '') {
    fail(422, 'URL обязателен');
}
if (!preg_match('/^https?:\/\//i', $url)) {
    $url = 'https://' . $url;
}
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    fail(422, 'Некорректный URL');
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail(422, 'Некорректный email');
}

// Защита от SSRF: не пускаем краулер во внутреннюю сеть сервера
$guardReason = UrlGuard::validate($url);
if ($guardReason !== null) {
    fail(422, 'Этот адрес нельзя проверить: ' . $guardReason);
}

// ── Проверка SmartCaptcha ──────────────────────────────────────────────
$captchaToken = $body['captchaToken'] ?? '';
if (empty($captchaToken)) {
    fail(422, 'Необходимо пройти проверку капчи');
}
$captchaSecret = Config::get('captcha.secret', '');
if (empty($captchaSecret)) {
    error_log('[start] CAPTCHA_SECRET не задан в .env');
    fail(500, 'Капча не настроена на сервере');
}
$captchaResp = @file_get_contents(
    'https://smartcaptcha.yandexcloud.net/validate?secret=' . urlencode($captchaSecret)
    . '&token=' . urlencode($captchaToken)
    . '&ip=' . urlencode($ip)
);
$captchaData = $captchaResp ? json_decode($captchaResp, true) : null;
if (!$captchaData || ($captchaData['status'] ?? '') !== 'ok') {
    fail(422, 'Проверка капчи не пройдена. Попробуйте снова.');
}

// ── Строгие лимиты: считают только реально создаваемые аудиты ───────────
foreach ([[5, 3600, 'час'], [20, 86400, 'сутки']] as [$limit, $window, $label]) {
    $rl = $limiter->hit("audit:$ip", $limit, $window);
    if (!$rl['allowed']) {
        $minutes = (int) ceil($rl['retry_after'] / 60);
        fail(429, "Превышен лимит: не более $limit аудитов за $label. Попробуйте через $minutes мин.",
            ['Retry-After: ' . $rl['retry_after']]);
    }
}

// Больше трёх заданий в работе одновременно сервер не переварит
$active = (int) Database::query(
    "SELECT COUNT(*) c FROM audits WHERE status IN ('pending','crawling','checking','reporting')
     AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
)->fetch()['c'];
if ($active >= 3) {
    fail(503, 'Сейчас выполняются другие аудиты. Попробуйте через пару минут.', ['Retry-After: 120']);
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
        'uuid'              => $uuid,
        'url'               => $url,
        'host'              => $host,
        'email'             => $email,
        'status'            => 'pending',
        'progress'          => 0,
        'progress_text'     => 'В очереди...',
        'previous_audit_id' => $prevAudit ? (int)$prevAudit['id'] : null,
    ]);

    // Запускаем воркер в фоне
    $workerPath = BASE_PATH . '/jobs/worker.php';
    $logPath    = BASE_PATH . '/logs/worker.log';
    exec('php ' . escapeshellarg($workerPath) . ' >> ' . escapeshellarg($logPath) . ' 2>&1 &');

    // Раз в сутки подчищаем счётчики лимитов
    if (mt_rand(1, 50) === 1) {
        $limiter->cleanup();
    }

    echo json_encode([
        'success'   => true,
        'audit_id'  => $uuid,
        'url'       => $url,
        'is_repeat' => $prevAudit ? true : false,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Exception $e) {
    error_log('[start] ' . $e->getMessage());
    fail(500, 'Не удалось создать задание. Попробуйте позже.');
}
