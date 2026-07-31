<?php
/**
 * Установщик SEO Аудитора: создаёт .env, базу данных и каталоги,
 * применяет схему и проверяет результат.
 *
 * Интерактивно:      php bin/install.php
 * Без вопросов:      php bin/install.php --db-name=seo_auditor --db-user=seo_user --db-pass=секрет --no-interaction
 *
 * Полный список ключей: php bin/install.php --help
 */
define('BASE_PATH', dirname(__DIR__));

// ── Разбор аргументов ──────────────────────────────────────────────────
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/i', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}

if (isset($opts['help']) || isset($opts['h'])) {
    echo <<<TXT

Установка SEO Аудитора.

  php bin/install.php [ключи]

Ключи (все необязательны — что не задано, будет спрошено):
  --url=            адрес будущего сайта, например https://seo.example.ru
  --db-host=        сервер БД (по умолчанию localhost)
  --db-name=        имя базы (по умолчанию seo_auditor)
  --db-user=        пользователь БД
  --db-pass=        пароль пользователя БД
  --db-root-user=   администратор MySQL — если базу нужно создать
  --db-root-pass=   его пароль
  --timezone=       часовой пояс, по умолчанию Europe/Moscow
  --no-interaction  ничего не спрашивать, брать значения по умолчанию
  --force           перезаписать существующий .env
  --skip-db         не трогать базу, только .env и каталоги

Пример полностью автоматической установки:
  php bin/install.php --no-interaction \\
      --url=https://seo.example.ru --db-name=seo_auditor \\
      --db-user=seo_user --db-pass=секрет


TXT;
    exit(0);
}

$interactive = !isset($opts['no-interaction']);

function шаг(string $title): void { echo "\n► $title\n"; }
function ок(string $msg): void    { echo "  [ OK ] $msg\n"; }
function внимание(string $msg): void { echo "  [WARN] $msg\n"; }
function провал(string $msg): never
{
    fwrite(STDERR, "  [FAIL] $msg\n\n");
    fwrite(STDERR, "Установка прервана. Исправьте причину и запустите снова.\n\n");
    exit(1);
}

function спросить(string $question, string $default, bool $interactive, bool $secret = false): string
{
    if (!$interactive) return $default;

    $hint = $default !== '' ? " [$default]" : '';
    echo "  $question$hint: ";

    if ($secret && DIRECTORY_SEPARATOR !== '\\') {
        shell_exec('stty -echo 2>/dev/null');
        $answer = trim((string) fgets(STDIN));
        shell_exec('stty echo 2>/dev/null');
        echo "\n";
    } else {
        $answer = trim((string) fgets(STDIN));
    }

    return $answer !== '' ? $answer : $default;
}

echo "\n══════════════════════════════════════════\n";
echo "  SEO Аудитор — установка\n";
echo "══════════════════════════════════════════\n";

// ── 1. Требования ──────────────────────────────────────────────────────
шаг('Проверяем требования');

version_compare(PHP_VERSION, '8.2', '>=')
    ? ок('PHP ' . PHP_VERSION)
    : провал('нужен PHP 8.2 или новее, установлен ' . PHP_VERSION);

$missing = [];
foreach (['pdo_mysql', 'curl', 'mbstring', 'dom', 'json', 'gd'] as $ext) {
    if (!extension_loaded($ext)) $missing[] = $ext;
}
$missing ? провал('не хватает расширений PHP: ' . implode(', ', $missing))
         : ок('расширения PHP на месте');

if (!is_file(BASE_PATH . '/vendor/autoload.php')) {
    провал('нет каталога vendor — сначала выполните: composer install');
}
require BASE_PATH . '/vendor/autoload.php';
ок('зависимости Composer установлены');

// ── 2. Настройки ───────────────────────────────────────────────────────
шаг('Настройки');

$envFile = BASE_PATH . '/.env';
if (is_file($envFile) && !isset($opts['force'])) {
    if ($interactive) {
        $answer = спросить('Файл .env уже есть. Перезаписать? (да/нет)', 'нет', true);
        if (mb_strtolower($answer) !== 'да' && mb_strtolower($answer) !== 'yes') {
            внимание('оставляю существующий .env без изменений');
            $keepEnv = true;
        }
    } else {
        внимание('.env уже существует, пропускаю его создание (--force чтобы перезаписать)');
        $keepEnv = true;
    }
}

$cfg = [
    'url'      => $opts['url']      ?? спросить('Адрес сайта', 'http://localhost:8000', $interactive),
    'timezone' => $opts['timezone'] ?? спросить('Часовой пояс', 'Europe/Moscow', $interactive),
    'db_host'  => $opts['db-host']  ?? спросить('Сервер БД', 'localhost', $interactive),
    'db_name'  => $opts['db-name']  ?? спросить('Имя базы', 'seo_auditor', $interactive),
    'db_user'  => $opts['db-user']  ?? спросить('Пользователь БД', 'seo_user', $interactive),
    'db_pass'  => $opts['db-pass']  ?? спросить('Пароль пользователя БД', '', $interactive, true),
];

if (!in_array($cfg['timezone'], timezone_identifiers_list(), true)) {
    провал("неизвестный часовой пояс: {$cfg['timezone']}");
}

// ── 3. Файл .env ───────────────────────────────────────────────────────
шаг('Файл конфигурации');

if (empty($keepEnv)) {
    $example = BASE_PATH . '/.env.example';
    if (!is_file($example)) провал('нет файла .env.example');

    $env = (string) file_get_contents($example);
    $replacements = [
        'APP_URL'      => $cfg['url'],
        'APP_TIMEZONE' => $cfg['timezone'],
        'DB_HOST'      => $cfg['db_host'],
        'DB_NAME'      => $cfg['db_name'],
        'DB_USER'      => $cfg['db_user'],
        'DB_PASS'      => $cfg['db_pass'],
        'REPORTS_DIR'  => BASE_PATH . '/reports',
    ];
    foreach ($replacements as $key => $value) {
        $env = preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', "$key=$value", $env, 1);
    }

    file_put_contents($envFile, $env);
    @chmod($envFile, 0640);
    ок('.env создан (права 640)');
} else {
    ок('используется существующий .env');
}

// ── 4. Каталоги ────────────────────────────────────────────────────────
шаг('Каталоги');

foreach (['reports', 'logs'] as $dir) {
    $path = BASE_PATH . '/' . $dir;
    if (!is_dir($path) && !@mkdir($path, 0775, true)) {
        провал("не удалось создать каталог $path");
    }
    is_writable($path) ? ок("$dir/ доступен на запись") : провал("нет прав на запись в $path");
}

// ── 5. База данных ─────────────────────────────────────────────────────
use SeoAuditor\Core\Config;
use SeoAuditor\Core\Database;

Config::load(BASE_PATH . '/config/config.php');

if (isset($opts['skip-db'])) {
    внимание('работа с базой пропущена (--skip-db)');
} else {
    шаг('База данных');

    $dsnNoDb = "mysql:host={$cfg['db_host']};charset=utf8mb4";

    // Пытаемся подключиться пользователем приложения
    $pdo = null;
    try {
        $pdo = new PDO("$dsnNoDb;dbname={$cfg['db_name']}", $cfg['db_user'], $cfg['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        ок("подключение к базе {$cfg['db_name']} установлено");
    } catch (PDOException $e) {
        внимание('подключиться пользователем приложения не удалось: ' . $e->getMessage());

        $rootUser = $opts['db-root-user'] ?? спросить('Администратор MySQL для создания базы (пусто — пропустить)', '', $interactive);
        if ($rootUser === '') {
            провал("создайте базу и пользователя вручную, затем запустите установщик снова:\n"
                 . "         CREATE DATABASE `{$cfg['db_name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
                 . "         CREATE USER '{$cfg['db_user']}'@'localhost' IDENTIFIED BY '...';\n"
                 . "         GRANT ALL PRIVILEGES ON `{$cfg['db_name']}`.* TO '{$cfg['db_user']}'@'localhost';");
        }

        $rootPass = $opts['db-root-pass'] ?? спросить("Пароль пользователя $rootUser", '', $interactive, true);

        try {
            $root = new PDO($dsnNoDb, $rootUser, $rootPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $root->exec("CREATE DATABASE IF NOT EXISTS `{$cfg['db_name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $root->exec("CREATE USER IF NOT EXISTS '{$cfg['db_user']}'@'localhost' IDENTIFIED BY " . $root->quote($cfg['db_pass']));
            $root->exec("GRANT ALL PRIVILEGES ON `{$cfg['db_name']}`.* TO '{$cfg['db_user']}'@'localhost'");
            $root->exec('FLUSH PRIVILEGES');
            ок("база {$cfg['db_name']} и пользователь {$cfg['db_user']} созданы");

            $pdo = new PDO("$dsnNoDb;dbname={$cfg['db_name']}", $cfg['db_user'], $cfg['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e2) {
            провал('не удалось создать базу: ' . $e2->getMessage());
        }
    }

    // Схема
    $schema = BASE_PATH . '/sql/schema.sql';
    if (!is_file($schema)) провал('не найден sql/schema.sql');

    try {
        $pdo->exec((string) file_get_contents($schema));
        ок('схема применена');
    } catch (PDOException $e) {
        провал('ошибка применения схемы: ' . $e->getMessage());
    }

    // Старые установки: добираем колонки, которых нет в древней схеме
    $columns = $pdo->query('SHOW COLUMNS FROM `audits`')->fetchAll(PDO::FETCH_COLUMN);
    $legacy  = array_diff(['host', 'previous_audit_id', 'score'], $columns);
    if ($legacy) {
        внимание('база от старой версии, применяю миграцию: ' . implode(', ', $legacy));
        $migration = BASE_PATH . '/sql/migration_001_comparison.sql';
        if (is_file($migration)) {
            try {
                $pdo->exec((string) file_get_contents($migration));
                ок('миграция применена');
            } catch (PDOException $e) {
                внимание('миграция не применилась: ' . $e->getMessage());
            }
        }
    }

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $need   = array_diff(['audits', 'audit_pages', 'audit_issues', 'audit_reports'], $tables);
    $need ? провал('в базе нет таблиц: ' . implode(', ', $need))
          : ок('все четыре таблицы на месте');
}

// ── 6. Итог ────────────────────────────────────────────────────────────
шаг('Проверка окружения');
passthru('php ' . escapeshellarg(BASE_PATH . '/bin/check_env.php') . ' | tail -n 6');

echo "\n══════════════════════════════════════════\n";
echo "  Установка завершена\n";
echo "══════════════════════════════════════════\n\n";
echo "Первый аудит из консоли (капча не нужна):\n";
echo "  php bin/audit.php https://example.com you@example.com --pages=5\n\n";
echo "Локальный веб-сервер:\n";
echo "  php -S localhost:8000 -t public\n\n";
echo "Чтобы работала форма на сайте, добавьте в .env ключи Яндекс SmartCaptcha\n";
echo "(CAPTCHA_SITEKEY и CAPTCHA_SECRET) — получить их можно в Yandex Cloud.\n\n";
