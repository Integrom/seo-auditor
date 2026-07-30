<?php
namespace SeoAuditor\Core;

/**
 * Ограничение частоты запросов по «скользящему окну».
 *
 * Состояние хранится в файлах, БД не задействована. IP не сохраняется
 * в открытом виде — только его хеш: сам IP является персональными данными
 * по ФЗ-152, а для подсчёта запросов достаточно хеша.
 */
class RateLimiter
{
    private string $dir;
    private string $salt;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? dirname(__DIR__, 2) . '/logs/ratelimit';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        // Соль привязана к установке: без неё хеш IP можно перебрать за секунды
        $this->salt = (string) Config::get('db.pass', 'seo-auditor');
    }

    /**
     * Проверяет и сразу учитывает попытку.
     *
     * @param string $key    идентификатор клиента (обычно IP)
     * @param int    $limit  сколько попыток разрешено
     * @param int    $window окно в секундах
     * @return array{allowed:bool, remaining:int, retry_after:int}
     */
    public function hit(string $key, int $limit, int $window): array
    {
        $now  = time();
        $file = $this->fileFor($key, $window);

        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            // Не смогли открыть хранилище — не блокируем пользователя из-за своей поломки
            error_log("[RateLimiter] нет доступа к $file");
            return ['allowed' => true, 'remaining' => $limit, 'retry_after' => 0];
        }

        try {
            flock($handle, LOCK_EX);

            $raw        = stream_get_contents($handle) ?: '';
            $timestamps = array_filter(array_map('intval', explode(',', $raw)));

            // Скользящее окно: выбрасываем всё, что старше окна
            $timestamps = array_values(array_filter($timestamps, fn($t) => $t > $now - $window));

            if (count($timestamps) >= $limit) {
                $retryAfter = max(1, ($timestamps[0] + $window) - $now);
                return ['allowed' => false, 'remaining' => 0, 'retry_after' => $retryAfter];
            }

            $timestamps[] = $now;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, implode(',', $timestamps));
            fflush($handle);

            return [
                'allowed'     => true,
                'remaining'   => $limit - count($timestamps),
                'retry_after' => 0,
            ];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Удаляет счётчики, которые уже никому не нужны */
    public function cleanup(int $olderThan = 86400): int
    {
        $removed = 0;
        foreach (glob($this->dir . '/*.txt') ?: [] as $file) {
            if (filemtime($file) < time() - $olderThan) {
                @unlink($file) && $removed++;
            }
        }
        return $removed;
    }

    private function fileFor(string $key, int $window): string
    {
        $hash = substr(hash_hmac('sha256', $key, $this->salt), 0, 32);
        return $this->dir . "/{$hash}_{$window}.txt";
    }
}
