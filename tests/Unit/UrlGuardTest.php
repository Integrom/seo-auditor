<?php
namespace SeoAuditor\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SeoAuditor\Core\UrlGuard;

class UrlGuardTest extends TestCase
{
    protected function setUp(): void
    {
        UrlGuard::resetCache();
    }

    #[DataProvider('внутренниеАдреса')]
    public function testВнутренниеАдресаЗапрещены(string $url, string $чтоПроверяем): void
    {
        $this->assertFalse(UrlGuard::isAllowed($url), $чтоПроверяем);
    }

    public static function внутренниеАдреса(): array
    {
        return [
            'localhost по имени'      => ['http://localhost/', 'localhost должен быть закрыт'],
            'localhost по IP'         => ['http://127.0.0.1/', 'петлевой адрес должен быть закрыт'],
            'localhost с портом'      => ['http://127.0.0.1:8080/admin', 'петля с портом тоже закрыта'],
            'частная сеть 10/8'       => ['http://10.0.0.5/', 'приватный диапазон 10/8'],
            'частная сеть 192.168/16' => ['http://192.168.1.1/', 'домашние роутеры'],
            'частная сеть 172.16/12'  => ['http://172.16.0.1/', 'приватный диапазон 172.16/12'],
            'метаданные облака'       => ['http://169.254.169.254/latest/meta-data/', 'сервис метаданных AWS/Yandex'],
            'нулевой адрес'           => ['http://0.0.0.0/', 'нулевой адрес'],
            'IPv6 петля'              => ['http://[::1]/', 'IPv6 localhost'],
            'домен .local'            => ['http://printer.local/', 'mDNS-имена локальной сети'],
            'домен .internal'         => ['http://db.internal/', 'внутренние корпоративные имена'],
            'метаданные Google'       => ['http://metadata.google.internal/', 'сервис метаданных GCP'],
        ];
    }

    #[DataProvider('опасныеСхемы')]
    public function testНеHttpСхемыЗапрещены(string $url): void
    {
        $this->assertFalse(UrlGuard::isAllowed($url));
    }

    public static function опасныеСхемы(): array
    {
        return [
            ['file:///etc/passwd'],
            ['gopher://example.com/'],
            ['ftp://example.com/'],
            ['dict://example.com:11211/'],
        ];
    }

    public function testНестандартныеПортыЗапрещены(): void
    {
        // Через нестандартные порты можно сканировать внутренние службы
        $this->assertFalse(UrlGuard::isAllowed('http://example.com:22/'),    'SSH');
        $this->assertFalse(UrlGuard::isAllowed('http://example.com:3306/'),  'MySQL');
        $this->assertFalse(UrlGuard::isAllowed('http://example.com:6379/'),  'Redis');
        $this->assertFalse(UrlGuard::isAllowed('http://example.com:11211/'), 'Memcached');
    }

    public function testСтандартныеВебПортыРазрешены(): void
    {
        $this->assertTrue(UrlGuard::isAllowed('https://example.com/'));
        $this->assertTrue(UrlGuard::isAllowed('http://example.com/'));
        $this->assertTrue(UrlGuard::isAllowed('https://example.com:443/'));
        $this->assertTrue(UrlGuard::isAllowed('http://example.com:8080/'));
    }

    public function testМусорВместоUrlЗапрещён(): void
    {
        $this->assertFalse(UrlGuard::isAllowed(''));
        $this->assertFalse(UrlGuard::isAllowed('не-адрес'));
        $this->assertFalse(UrlGuard::isAllowed('https://'));
    }

    public function testПричинаОтказаПонятна(): void
    {
        $reason = UrlGuard::validate('http://192.168.0.1/');
        $this->assertIsString($reason);
        $this->assertStringContainsString('внутренн', $reason, 'В сообщении должно быть понятно, что адрес внутренний');
    }

    public function testРазрешённыйАдресНеДаётПричины(): void
    {
        $this->assertNull(UrlGuard::validate('https://example.com/'));
    }

    /** Требует DNS: домен, который заведомо не существует */
    public function testНесуществующийДоменЗапрещён(): void
    {
        $url = 'https://этого-домена-точно-нет-' . md5('seo-auditor-test') . '.ru/';
        $this->assertFalse(UrlGuard::isAllowed($url));
    }
}
