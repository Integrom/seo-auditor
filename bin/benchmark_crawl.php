<?php
/**
 * Замер скорости обхода при разной степени параллелизма.
 * Сеть даёт разброс, поэтому каждый режим прогоняется несколько раз
 * и берётся медиана — среднее слишком чувствительно к единичному выбросу.
 *
 * Запуск: php bin/benchmark_crawl.php <url> [--pages=N] [--runs=N] [--levels=1,2,4,8]
 */
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use SeoAuditor\Core\Config;
use SeoAuditor\Core\Crawler;

Config::load(BASE_PATH . '/config/config.php');

$args = array_slice($argv, 1);
$opts = [];
$pos  = [];
foreach ($args as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m)) $opts[$m[1]] = $m[2];
    else $pos[] = $a;
}

$url = $pos[0] ?? '';
if ($url === '' || in_array($url, ['-h', '--help'], true)) {
    echo <<<TXT

Замер скорости обхода сайта при разной параллельности.

  php bin/benchmark_crawl.php <url> [--pages=N] [--runs=N] [--levels=1,2,4,8]

  --pages=N    сколько страниц обходить в каждом прогоне (по умолчанию 20)
  --runs=N     прогонов на каждый режим (по умолчанию 3)
  --levels=... уровни параллельности через запятую (по умолчанию 1,2,4,8)


TXT;
    exit($url === '' ? 1 : 0);
}

if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;

$pages  = max(1, (int) ($opts['pages'] ?? 20));
$runs   = max(1, (int) ($opts['runs'] ?? 3));
$levels = array_map('intval', explode(',', $opts['levels'] ?? '1,2,4,8'));

// Ограничиваем число страниц и убираем паузу между волнами — меряем сеть, а не sleep
$ref  = new ReflectionProperty(Config::class, 'data');
$ref->setAccessible(true);
$data = $ref->getValue();
$data['crawler']['max_pages'] = $pages;
$data['crawler']['delay']     = 0;
$ref->setValue(null, $data);

function медиана(array $values): float
{
    sort($values);
    $n = count($values);
    return $n % 2 ? $values[intdiv($n, 2)]
                  : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
}

echo "\nЗамер обхода: $url\n";
echo "Страниц за прогон: $pages, прогонов на режим: $runs\n";
echo str_repeat('─', 66) . "\n";
printf("%-14s %10s %10s %10s %12s\n", 'Параллельно', 'медиана', 'минимум', 'максимум', 'страниц/с');
echo str_repeat('─', 66) . "\n";

$results = [];
foreach ($levels as $level) {
    $times = [];
    $got   = 0;

    for ($i = 0; $i < $runs; $i++) {
        $crawler = new Crawler($level);
        $start   = microtime(true);
        $result  = $crawler->crawl($url);
        $times[] = microtime(true) - $start;
        $got     = count($result);
    }

    $med = медиана($times);
    $results[$level] = ['time' => $med, 'pages' => $got];

    printf("%-14s %9.2fс %9.2fс %9.2fс %12.1f\n",
        $level === 1 ? '1 (было)' : $level,
        $med, min($times), max($times), $med > 0 ? $got / $med : 0);
}

echo str_repeat('─', 66) . "\n";

$base = $results[$levels[0]]['time'] ?? null;
if ($base && count($results) > 1) {
    echo "\nУскорение относительно последовательного обхода:\n";
    foreach ($results as $level => $r) {
        if ($level === $levels[0]) continue;
        printf("  %-3s потоков — в %.1f раза быстрее (%.2fс против %.2fс)\n",
            $level, $base / $r['time'], $r['time'], $base);
    }

    $times     = array_map(fn($r) => $r['time'], $results);
    $bestLevel = array_search(min($times), $times, true);
    echo "\nЛучший результат: {$bestLevel} потоков.\n";
    echo "Значение задаётся переменной CRAWLER_CONCURRENCY в .env\n";
    echo "Если ускорения нет — узкое место не в сети: сайт может ограничивать\n";
    echo "число одновременных соединений с одного адреса.\n";
}

$pagesGot = reset($results)['pages'] ?? 0;
echo "\nОбойдено страниц за прогон: $pagesGot";
echo $pagesGot < $pages ? " (на сайте меньше страниц, чем запрошено)\n\n" : "\n\n";
