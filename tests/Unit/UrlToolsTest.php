<?php
namespace SeoAuditor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SeoAuditor\Core\UrlTools;

class UrlToolsTest extends TestCase
{
    // ── Нормализация ───────────────────────────────────────────────────

    public function testЯкорьОтбрасывается(): void
    {
        $this->assertSame('https://example.com/page', UrlTools::normalize('https://example.com/page#section'));
    }

    public function testЗавершающийСлэшУбирается(): void
    {
        $this->assertSame('https://example.com/page', UrlTools::normalize('https://example.com/page/'));
    }

    public function testХостПриводитсяКНижнемуРегистру(): void
    {
        $this->assertSame('https://example.com/Page', UrlTools::normalize('https://EXAMPLE.COM/Page'));
    }

    public function testПортПоУмолчаниюУбирается(): void
    {
        $this->assertSame('https://example.com/a', UrlTools::normalize('https://example.com:443/a'));
        $this->assertSame('http://example.com/a', UrlTools::normalize('http://example.com:80/a'));
    }

    public function testНестандартныйПортСохраняется(): void
    {
        $this->assertSame('http://example.com:8080/a', UrlTools::normalize('http://example.com:8080/a'));
    }

    public function testСтрокаЗапросаСохраняется(): void
    {
        $this->assertSame('https://example.com/search?q=тест', UrlTools::normalize('https://example.com/search?q=тест'));
    }

    public function testРазныеЗаписиОдногоАдресаСовпадают(): void
    {
        $a = UrlTools::normalize('https://Example.com/catalog/');
        $b = UrlTools::normalize('https://example.com/catalog#top');
        $this->assertSame($a, $b, 'Иначе одна страница обойдётся дважды');
    }

    // ── Тип ссылки ─────────────────────────────────────────────────────

    public function testАдресБезРасширенияСчитаетсяСтраницей(): void
    {
        $this->assertTrue(UrlTools::isHtmlUrl('https://example.com/about'));
        $this->assertTrue(UrlTools::isHtmlUrl('https://example.com/'));
    }

    public function testСтраничныеРасширенияРазрешены(): void
    {
        foreach (['html', 'php', 'aspx', 'htm'] as $ext) {
            $this->assertTrue(UrlTools::isHtmlUrl("https://example.com/page.$ext"), $ext);
        }
    }

    public function testФайлыНеСчитаютсяСтраницами(): void
    {
        foreach (['pdf', 'jpg', 'png', 'css', 'js', 'zip', 'xml', 'woff2', 'mp4', 'avif'] as $ext) {
            $this->assertFalse(UrlTools::isHtmlUrl("https://example.com/file.$ext"), $ext);
        }
    }

    // ── Разрешение ссылок ──────────────────────────────────────────────

    public function testАбсолютнаяСсылкаОстаётсяСобой(): void
    {
        $this->assertSame(
            'https://other.com/page',
            UrlTools::resolve('https://other.com/page', 'https://example.com/')
        );
    }

    public function testКорневаяСсылка(): void
    {
        $this->assertSame(
            'https://example.com/contacts',
            UrlTools::resolve('/contacts', 'https://example.com/catalog/item')
        );
    }

    /**
     * Раньше относительные ссылки просто отбрасывались, и сайты
     * с такой навигацией обходились не полностью.
     */
    public function testОтносительнаяСсылкаОтКаталогаСтраницы(): void
    {
        $this->assertSame(
            'https://example.com/catalog/item2',
            UrlTools::resolve('item2', 'https://example.com/catalog/item1')
        );
    }

    public function testОтносительнаяСсылкаОтКаталогаСоСлэшем(): void
    {
        $this->assertSame(
            'https://example.com/catalog/item',
            UrlTools::resolve('item', 'https://example.com/catalog/')
        );
    }

    public function testТочкаОзначаетТекущийКаталог(): void
    {
        $this->assertSame(
            'https://example.com/catalog/page',
            UrlTools::resolve('./page', 'https://example.com/catalog/index.html')
        );
    }

    public function testДвеТочкиПоднимаютНаУровеньВыше(): void
    {
        $this->assertSame(
            'https://example.com/about',
            UrlTools::resolve('../about', 'https://example.com/catalog/item')
        );
    }

    public function testНесколькоПодъёмовПодряд(): void
    {
        $this->assertSame(
            'https://example.com/x',
            UrlTools::resolve('../../x', 'https://example.com/a/b/c')
        );
    }

    public function testПодъёмВышеКорняНеЛомаетАдрес(): void
    {
        $this->assertSame(
            'https://example.com/page',
            UrlTools::resolve('../../../../page', 'https://example.com/a/b')
        );
    }

    public function testПротоколОтносительнаяСсылка(): void
    {
        $this->assertSame(
            'https://cdn.example.com/page',
            UrlTools::resolve('//cdn.example.com/page', 'https://example.com/')
        );
    }

    public function testСлужебныеСхемыПропускаются(): void
    {
        foreach (['mailto:a@b.ru', 'tel:+79001234567', 'javascript:void(0)',
                  'data:text/html,x', 'sms:123', 'WhatsApp://send'] as $href) {
            $this->assertNull(UrlTools::resolve($href, 'https://example.com/'), $href);
        }
    }

    public function testПустаяСсылкаИЧистыйЯкорьПропускаются(): void
    {
        $this->assertNull(UrlTools::resolve('', 'https://example.com/'));
        $this->assertNull(UrlTools::resolve('#', 'https://example.com/'));
        $this->assertNull(UrlTools::resolve('#section', 'https://example.com/'));
    }

    public function testПробелыВокругСсылкиОбрезаются(): void
    {
        $this->assertSame(
            'https://example.com/page',
            UrlTools::resolve('  /page  ', 'https://example.com/')
        );
    }

    public function testСтрокаЗапросаВОтносительнойСсылке(): void
    {
        $this->assertSame(
            'https://example.com/catalog/filter?color=red',
            UrlTools::resolve('filter?color=red', 'https://example.com/catalog/')
        );
    }

    public function testТочкиВСтрокеЗапросаНеСхлопываются(): void
    {
        $this->assertSame(
            'https://example.com/search?file=../secret',
            UrlTools::resolve('/search?file=../secret', 'https://example.com/')
        );
    }

    // ── Извлечение ссылок из разметки ──────────────────────────────────

    public function testСсылкиИзвлекаютсяИзРазметки(): void
    {
        $html = '<a href="/one">Раз</a> текст <a class="x" href="/two" title="t">Два</a>';
        $this->assertSame(['/one', '/two'], UrlTools::extractHrefs($html));
    }

    public function testПоддерживаютсяРазныеКавычки(): void
    {
        $html = '<a href="/dq">1</a><a href=\'/sq\'>2</a><a href=/noq>3</a>';
        $this->assertSame(['/dq', '/sq', '/noq'], UrlTools::extractHrefs($html));
    }

    public function testПовторяющиесяСсылкиНеДублируются(): void
    {
        $html = '<a href="/page">1</a><a href="/page">2</a><a href="/page">3</a>';
        $this->assertSame(['/page'], UrlTools::extractHrefs($html));
    }

    public function testСсылкиВКомментарияхИгнорируются(): void
    {
        $html = '<a href="/real">да</a><!-- <a href="/commented">нет</a> -->';
        $this->assertSame(['/real'], UrlTools::extractHrefs($html));
    }

    public function testСсылкиВСкриптахИгнорируются(): void
    {
        $html = '<a href="/real">да</a><script>var s = \'<a href="/injected">нет</a>\';</script>';
        $this->assertSame(['/real'], UrlTools::extractHrefs($html));
    }

    public function testHtmlСущностиРаскодируются(): void
    {
        $html = '<a href="/search?a=1&amp;b=2">поиск</a>';
        $this->assertSame(['/search?a=1&b=2'], UrlTools::extractHrefs($html));
    }

    public function testТегиБезHrefНеМешают(): void
    {
        $html = '<a name="anchor">якорь</a><a href="/page">ссылка</a><link href="/style.css">';
        $this->assertSame(['/page'], UrlTools::extractHrefs($html));
    }

    public function testПустаяРазметкаДаётПустойСписок(): void
    {
        $this->assertSame([], UrlTools::extractHrefs(''));
        $this->assertSame([], UrlTools::extractHrefs('<p>без ссылок</p>'));
    }

    public function testПереносыСтрокВнутриТегаНеМешают(): void
    {
        $html = "<a\n  class=\"btn\"\n  href=\"/page\"\n>Ссылка</a>";
        $this->assertSame(['/page'], UrlTools::extractHrefs($html));
    }

    // ── Сравнение хостов ───────────────────────────────────────────────

    public function testСвойИЧужойХостРазличаются(): void
    {
        $this->assertTrue(UrlTools::isSameHost('https://example.com/page', 'example.com'));
        $this->assertTrue(UrlTools::isSameHost('https://EXAMPLE.com/page', 'example.com'));
        $this->assertFalse(UrlTools::isSameHost('https://other.com/page', 'example.com'));
        $this->assertFalse(UrlTools::isSameHost('https://sub.example.com/', 'example.com'),
            'Поддомен — отдельный сайт');
    }
}
