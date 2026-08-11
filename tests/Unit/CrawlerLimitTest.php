<?php
namespace SeoAuditor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SeoAuditor\Core\Config;
use SeoAuditor\Core\Crawler;

/**
 * Поведение ограничения по числу страниц. Сеть не задействуется:
 * проверяем разбор настройки и вычисление порога памяти.
 */
class CrawlerLimitTest extends TestCase
{
    private string $configFile;

    protected function setUp(): void
    {
        $this->configFile = sys_get_temp_dir() . '/seo_crawler_cfg_' . uniqid() . '.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->configFile);
    }

    private function загрузитьНастройки(array $crawler): void
    {
        $config = ['crawler' => $crawler + ['timeout' => 5, 'concurrency' => 4, 'delay' => 0]];
        file_put_contents($this->configFile, '<?php return ' . var_export($config, true) . ';');
        Config::load($this->configFile);
    }

    /** @return mixed значение приватного свойства краулера */
    private function свойство(Crawler $crawler, string $name): mixed
    {
        $prop = new \ReflectionProperty(Crawler::class, $name);
        $prop->setAccessible(true);
        return $prop->getValue($crawler);
    }

    public function testНольОзначаетОбходБезОграничения(): void
    {
        $this->загрузитьНастройки(['max_pages' => 0]);
        $this->assertSame(0, $this->свойство(new Crawler(), 'maxPages'));
    }

    public function testПоУмолчаниюОграниченияНет(): void
    {
        $this->загрузитьНастройки([]);
        $this->assertSame(0, $this->свойство(new Crawler(), 'maxPages'));
    }

    public function testПоложительноеЗначениеСохраняется(): void
    {
        $this->загрузитьНастройки(['max_pages' => 150]);
        $this->assertSame(150, $this->свойство(new Crawler(), 'maxPages'));
    }

    /**
     * Ограничение снято только по счётчику страниц: память конечна, поэтому
     * должен быть рассчитан порог, на котором обход остановится сам.
     */
    public function testПриСнятомОграниченииЕстьПорогПоПамяти(): void
    {
        $this->загрузитьНастройки(['max_pages' => 0]);
        $ceiling = $this->свойство(new Crawler(), 'memoryCeiling');

        if (trim((string) ini_get('memory_limit')) === '-1') {
            $this->assertSame(0, $ceiling, 'При memory_limit=-1 порог не нужен');
            return;
        }

        $this->assertGreaterThan(0, $ceiling);
        $this->assertLessThan($this->лимитПамятиВБайтах(), $ceiling, 'Порог должен быть ниже memory_limit');
    }

    public function testПорогПамятиНижеЛимитаНоНеСлишком(): void
    {
        if (trim((string) ini_get('memory_limit')) === '-1') {
            $this->markTestSkipped('memory_limit не задан');
        }

        $this->загрузитьНастройки(['max_pages' => 0]);
        $ceiling = $this->свойство(new Crawler(), 'memoryCeiling');
        $limit   = $this->лимитПамятиВБайтах();

        // Порог около 80% лимита: раньше — теряем страницы зря, позже — рискуем упасть
        $this->assertGreaterThan($limit * 0.5, $ceiling);
        $this->assertLessThanOrEqual($limit * 0.9, $ceiling);
    }

    public function testПричинаОстановкиЗаполненаСразу(): void
    {
        $this->загрузитьНастройки(['max_pages' => 0]);
        $this->assertNotSame('', (new Crawler())->getStopReason());
    }

    private function лимитПамятиВБайтах(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        $unit  = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($unit) {
            'g'     => $value * 1024 * 1024 * 1024,
            'm'     => $value * 1024 * 1024,
            'k'     => $value * 1024,
            default => $value,
        };
    }
}
