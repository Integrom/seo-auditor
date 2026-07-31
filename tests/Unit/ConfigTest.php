<?php
namespace SeoAuditor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SeoAuditor\Core\Config;

class ConfigTest extends TestCase
{
    private string $file;
    private string $tzBackup;

    protected function setUp(): void
    {
        $this->file     = sys_get_temp_dir() . '/seo_config_test_' . uniqid() . '.php';
        $this->tzBackup = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        date_default_timezone_set($this->tzBackup);
    }

    private function загрузить(array $config): void
    {
        file_put_contents($this->file, '<?php return ' . var_export($config, true) . ';');
        Config::load($this->file);
    }

    public function testЗначенияЧитаютсяПоТочечномуКлючу(): void
    {
        $this->загрузить(['db' => ['host' => 'localhost', 'dbname' => 'seo_auditor']]);

        $this->assertSame('localhost', Config::get('db.host'));
        $this->assertSame('seo_auditor', Config::get('db.dbname'));
    }

    public function testВложенныйМассивВозвращаетсяЦеликом(): void
    {
        $this->загрузить(['db' => ['host' => 'localhost', 'user' => 'seo_user']]);

        $this->assertSame(['host' => 'localhost', 'user' => 'seo_user'], Config::get('db'));
    }

    public function testОтсутствующийКлючВозвращаетЗначениеПоУмолчанию(): void
    {
        $this->загрузить(['app' => ['url' => 'https://example.com']]);

        $this->assertSame('запас', Config::get('app.нет_такого', 'запас'));
        $this->assertSame('запас', Config::get('нет.такой.ветки', 'запас'));
        $this->assertNull(Config::get('нет_такого'));
    }

    /**
     * Сервер и MySQL работают по московскому времени, PHP по умолчанию в UTC.
     * Без явной установки пояса даты создания и завершения аудита расходились
     * на три часа, а в отчёте клиенту показывалось неверное время.
     */
    public function testЧасовойПоясПрименяетсяПриЗагрузкеКонфигурации(): void
    {
        $this->загрузить(['app' => ['timezone' => 'Europe/Moscow']]);

        $this->assertSame('Europe/Moscow', date_default_timezone_get());
    }

    public function testНекорректныйПоясНеЛомаетЗагрузку(): void
    {
        date_default_timezone_set('UTC');
        $this->загрузить(['app' => ['timezone' => 'Марс/Олимп']]);

        $this->assertSame('UTC', date_default_timezone_get(), 'Оставляем прежний пояс');
    }

    public function testБезУказанияПоясаНичегоНеМеняется(): void
    {
        date_default_timezone_set('UTC');
        $this->загрузить(['app' => ['url' => 'https://example.com']]);

        $this->assertSame('UTC', date_default_timezone_get());
    }
}
