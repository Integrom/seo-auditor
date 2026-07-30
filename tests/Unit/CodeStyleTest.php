<?php
namespace SeoAuditor\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Сторож против ловушек, на которые проект уже наступал.
 * Дешевле проверить один раз тестом, чем ловить в проде по логам.
 */
class CodeStyleTest extends TestCase
{
    /** @return string[] все PHP-файлы проекта */
    private function phpFiles(): array
    {
        $root  = dirname(__DIR__, 2);
        $files = [];
        foreach (['src', 'api', 'bin', 'jobs', 'config', 'templates', 'tests'] as $dir) {
            $path = "$root/$dir";
            if (!is_dir($path)) continue;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        return $files;
    }

    /**
     * В двойных кавычках запись «$var» ломается: закрывающая ёлочка состоит
     * из байтов ≥ 0x80, а PHP считает их допустимыми в имени переменной —
     * и ищет несуществующую переменную $var». Нужно писать «{$var}».
     */
    public function testНетИнтерполяцииПеременнойВнутриЁлочек(): void
    {
        $нарушения = [];
        foreach ($this->phpFiles() as $file) {
            foreach (file($file) as $n => $line) {
                if ($this->isComment($line)) continue;
                if (preg_match('/«\$[a-zA-Z_][a-zA-Z0-9_]*/u', $line)) {
                    $нарушения[] = basename($file) . ':' . ($n + 1) . ' — ' . trim($line);
                }
            }
        }

        $this->assertSame([], $нарушения,
            "Внутри «...» переменную нужно оборачивать в фигурные скобки: «{\$var}».\nНайдено:\n" . implode("\n", $нарушения)
        );
    }

    /** Строка целиком является комментарием — в ней примеры допустимы */
    private function isComment(string $line): bool
    {
        $t = ltrim($line);
        return $t === '' || str_starts_with($t, '*') || str_starts_with($t, '//')
            || str_starts_with($t, '/*') || str_starts_with($t, '#');
    }

    /** Секреты должны приходить из .env, а не лежать в коде */
    public function testСекретыНеЗахардкоженыВКоде(): void
    {
        $шаблоны = [
            '/ysc[12]_[A-Za-z0-9]{20,}/' => 'ключ Яндекс SmartCaptcha',
            '/ctx7sk-[a-f0-9-]{20,}/'    => 'ключ Context7',
            '/AIza[A-Za-z0-9_-]{30,}/'   => 'ключ Google API',
        ];

        $нарушения = [];
        foreach ($this->phpFiles() as $file) {
            $content = (string) file_get_contents($file);
            foreach ($шаблоны as $regex => $что) {
                if (preg_match($regex, $content)) {
                    $нарушения[] = basename($file) . " — похоже на $что";
                }
            }
        }

        $this->assertSame([], $нарушения,
            "Секреты должны читаться через Env/Config, а не быть в коде:\n" . implode("\n", $нарушения)
        );
    }

    /** Отладочные функции не должны попадать в рабочий код */
    public function testНетОтладочныхВызовов(): void
    {
        $нарушения = [];
        foreach ($this->phpFiles() as $file) {
            if (str_contains($file, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) continue;
            foreach (file($file) as $n => $line) {
                if (preg_match('/\b(var_dump|print_r|die\s*\()\s*\(/', $line) && !str_contains($line, '//')) {
                    $нарушения[] = basename($file) . ':' . ($n + 1);
                }
            }
        }
        $this->assertSame([], $нарушения, "Остались отладочные вызовы:\n" . implode("\n", $нарушения));
    }
}
