<?php
namespace SeoAuditor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SeoAuditor\Core\RateLimiter;

class RateLimiterTest extends TestCase
{
    private string $dir;
    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/seo_ratelimit_test_' . uniqid();
        $this->limiter = new RateLimiter($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir);
    }

    public function testПервыйЗапросРазрешён(): void
    {
        $r = $this->limiter->hit('192.0.2.1', 5, 3600);
        $this->assertTrue($r['allowed']);
        $this->assertSame(4, $r['remaining']);
    }

    public function testЗапросыСверхЛимитаБлокируются(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->limiter->hit('192.0.2.2', 3, 3600)['allowed'], "Запрос $i должен пройти");
        }

        $r = $this->limiter->hit('192.0.2.2', 3, 3600);
        $this->assertFalse($r['allowed'], 'Четвёртый запрос при лимите 3 должен быть отклонён');
        $this->assertSame(0, $r['remaining']);
    }

    public function testОстатокПопытокУменьшается(): void
    {
        $this->assertSame(2, $this->limiter->hit('192.0.2.3', 3, 3600)['remaining']);
        $this->assertSame(1, $this->limiter->hit('192.0.2.3', 3, 3600)['remaining']);
        $this->assertSame(0, $this->limiter->hit('192.0.2.3', 3, 3600)['remaining']);
    }

    public function testЛимитыРазныхКлиентовНезависимы(): void
    {
        $this->limiter->hit('192.0.2.10', 1, 3600);
        $this->assertFalse($this->limiter->hit('192.0.2.10', 1, 3600)['allowed'], 'Первый клиент исчерпал лимит');
        $this->assertTrue($this->limiter->hit('192.0.2.11', 1, 3600)['allowed'], 'Второй клиент не должен страдать');
    }

    public function testРазныеОкнаСчитаютсяОтдельно(): void
    {
        // Часовой лимит исчерпан, но суточный ещё нет
        $this->limiter->hit('192.0.2.20', 1, 3600);
        $this->assertFalse($this->limiter->hit('192.0.2.20', 1, 3600)['allowed']);
        $this->assertTrue($this->limiter->hit('192.0.2.20', 10, 86400)['allowed']);
    }

    public function testПриБлокировкеСообщаетсяВремяОжидания(): void
    {
        $this->limiter->hit('192.0.2.30', 1, 60);
        $r = $this->limiter->hit('192.0.2.30', 1, 60);

        $this->assertFalse($r['allowed']);
        $this->assertGreaterThan(0, $r['retry_after']);
        $this->assertLessThanOrEqual(60, $r['retry_after']);
    }

    public function testСтарыеПопыткиВыпадаютИзОкна(): void
    {
        // Эмулируем попытки, сделанные два часа назад
        $ref = new \ReflectionMethod(RateLimiter::class, 'fileFor');
        $ref->setAccessible(true);
        $file = $ref->invoke($this->limiter, '192.0.2.40', 3600);

        $старые = (time() - 7200) . ',' . (time() - 5400);
        file_put_contents($file, $старые);

        $r = $this->limiter->hit('192.0.2.40', 2, 3600);
        $this->assertTrue($r['allowed'], 'Попытки старше окна не должны учитываться');
    }

    /** IP — персональные данные по ФЗ-152, в файлах он не должен появляться */
    public function testIpНеХранитсяВОткрытомВиде(): void
    {
        $ip = '203.0.113.77';
        $this->limiter->hit($ip, 5, 3600);

        $files = glob($this->dir . '/*') ?: [];
        $this->assertNotEmpty($files, 'Файл счётчика должен быть создан');

        foreach ($files as $file) {
            $this->assertStringNotContainsString($ip, basename($file), 'IP не должен быть в имени файла');
            $this->assertStringNotContainsString($ip, (string) file_get_contents($file), 'IP не должен быть внутри файла');
        }
    }

    public function testОчисткаУдаляетУстаревшиеФайлы(): void
    {
        $this->limiter->hit('192.0.2.50', 5, 3600);
        $files = glob($this->dir . '/*.txt') ?: [];
        $this->assertNotEmpty($files);

        // Помечаем файл как созданный три дня назад
        touch($files[0], time() - 3 * 86400);

        $this->assertSame(1, $this->limiter->cleanup(86400));
        $this->assertCount(0, glob($this->dir . '/*.txt') ?: []);
    }

    public function testСвежиеФайлыПриОчисткеНеТрогаются(): void
    {
        $this->limiter->hit('192.0.2.60', 5, 3600);
        $this->assertSame(0, $this->limiter->cleanup(86400));
        $this->assertCount(1, glob($this->dir . '/*.txt') ?: []);
    }
}
