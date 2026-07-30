<?php
namespace SeoAuditor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SeoAuditor\Core\Env;

class EnvTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/seo_env_test_' . uniqid() . '.env';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function загрузить(string $content): void
    {
        file_put_contents($this->file, $content);
        Env::load($this->file);
    }

    public function testПростоеЗначениеЧитается(): void
    {
        $this->загрузить("DB_USER=seo_user");
        $this->assertSame('seo_user', Env::get('DB_USER'));
    }

    public function testКомментарииИПустыеСтрокиПропускаются(): void
    {
        $this->загрузить(<<<TXT
        # Настройки базы
        DB_NAME=seo_auditor

        # Ещё комментарий
        DB_USER=seo_user
        TXT);

        $this->assertSame('seo_auditor', Env::get('DB_NAME'));
        $this->assertSame('seo_user', Env::get('DB_USER'));
    }

    public function testКавычкиСнимаются(): void
    {
        $this->загрузить("KEY1=\"значение в кавычках\"\nKEY2='одинарные'");
        $this->assertSame('значение в кавычках', Env::get('KEY1'));
        $this->assertSame('одинарные', Env::get('KEY2'));
    }

    public function testЗначениеСоЗнакомРавенстваНеОбрезается(): void
    {
        // Пароли и токены часто содержат «=» — например base64
        $this->загрузить('DB_PASS=abc==def=');
        $this->assertSame('abc==def=', Env::get('DB_PASS'));
    }

    public function testПробелыВокругЗначенияОбрезаются(): void
    {
        $this->загрузить("KEY =  значение  ");
        $this->assertSame('значение', Env::get('KEY'));
    }

    public function testЛогическиеЗначенияПриводятсяКТипу(): void
    {
        $this->загрузить("FLAG_ON=true\nFLAG_OFF=false\nNOTHING=null");
        $this->assertTrue(Env::get('FLAG_ON'));
        $this->assertFalse(Env::get('FLAG_OFF'));
        $this->assertNull(Env::get('NOTHING'));
    }

    public function testРегистрЛогическихЗначенийНеВажен(): void
    {
        $this->загрузить("A=TRUE\nB=False");
        $this->assertTrue(Env::get('A'));
        $this->assertFalse(Env::get('B'));
    }

    public function testОтсутствующийКлючВозвращаетЗначениеПоУмолчанию(): void
    {
        $this->загрузить('SOMETHING=1');
        $this->assertSame('по-умолчанию', Env::get('НЕТ_ТАКОГО', 'по-умолчанию'));
        $this->assertNull(Env::get('НЕТ_ТАКОГО'));
    }

    public function testПустоеЗначениеРавносильноОтсутствию(): void
    {
        // MAIL_USER= в .env означает «не задано», должен сработать default
        $this->загрузить('MAIL_USER=');
        $this->assertSame('fallback', Env::get('MAIL_USER', 'fallback'));
    }

    public function testОтсутствующийФайлНеЛомаетЗагрузку(): void
    {
        Env::load('/этого/файла/точно/нет/.env');
        $this->assertSame('default', Env::get('ANY_KEY', 'default'));
    }

    public function testСтрокаБезЗнакаРавенстваИгнорируется(): void
    {
        $this->загрузить("МУСОР\nVALID=ok");
        $this->assertSame('ok', Env::get('VALID'));
    }

    public function testЗначениеСРешёткойВнутриСохраняется(): void
    {
        // Решётка внутри значения — часть пароля, а не комментарий
        $this->загрузить('DB_PASS=pa#ss123');
        $this->assertSame('pa#ss123', Env::get('DB_PASS'));
    }
}
