<?php
namespace SeoAuditor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SeoAuditor\Core\RobotsTxt;

class RobotsTxtTest extends TestCase
{
    public function testПолноеЗакрытиеСайтаОпределяется(): void
    {
        $robots = new RobotsTxt("User-agent: *\nDisallow: /");
        $this->assertTrue($robots->blocksEverything('*'));
    }

    public function testОткрытыйСайтНеСчитаетсяЗакрытым(): void
    {
        $robots = new RobotsTxt("User-agent: *\nDisallow: /admin/\nDisallow: /cart/");
        $this->assertFalse($robots->blocksEverything('*'));
    }

    public function testПустойФайлНичегоНеЗакрывает(): void
    {
        $robots = new RobotsTxt('');
        $this->assertFalse($robots->blocksEverything('*'));
        $this->assertFalse($robots->blocksEverything('GPTBot'));
    }

    public function testБлокировкаКонкретногоБота(): void
    {
        $robots = new RobotsTxt(<<<TXT
        User-agent: *
        Disallow: /admin/

        User-agent: GPTBot
        Disallow: /
        TXT);

        $this->assertTrue($robots->blocksEverything('GPTBot'));
        $this->assertFalse($robots->blocksEverything('*'));
        $this->assertFalse($robots->blocksEverything('ClaudeBot'), 'Правил для ClaudeBot нет — он не заблокирован');
    }

    public function testРегистрИмениБотаНеВажен(): void
    {
        $robots = new RobotsTxt("User-agent: gptbot\nDisallow: /");
        $this->assertTrue($robots->blocksEverything('GPTBot'));
        $this->assertTrue($robots->blocksEverything('GPTBOT'));
    }

    /** Несколько User-agent подряд образуют одну группу с общими правилами */
    public function testНесколькоАгентовПодрядДелятОдниПравила(): void
    {
        $robots = new RobotsTxt(<<<TXT
        User-agent: GPTBot
        User-agent: ClaudeBot
        User-agent: PerplexityBot
        Disallow: /
        TXT);

        $this->assertTrue($robots->blocksEverything('GPTBot'));
        $this->assertTrue($robots->blocksEverything('ClaudeBot'));
        $this->assertTrue($robots->blocksEverything('PerplexityBot'));
    }

    public function testAllowПереопределяетЗапрет(): void
    {
        $robots = new RobotsTxt("User-agent: *\nDisallow: /\nAllow: /");
        $this->assertFalse($robots->blocksEverything('*'));
    }

    public function testКомментарииИгнорируются(): void
    {
        $robots = new RobotsTxt(<<<TXT
        # Закрываем сайт от всех
        User-agent: *   # общая группа
        Disallow: /     # полный запрет
        TXT);

        $this->assertTrue($robots->blocksEverything('*'));
    }

    public function testЛишниеПробелыИРегистрДирективНеМешают(): void
    {
        $robots = new RobotsTxt("USER-AGENT:   *  \n   DISALLOW:   /   ");
        $this->assertTrue($robots->blocksEverything('*'));
    }

    public function testПереносыСтрокWindowsОбрабатываются(): void
    {
        $robots = new RobotsTxt("User-agent: *\r\nDisallow: /\r\n");
        $this->assertTrue($robots->blocksEverything('*'));
    }

    public function testСписокЗаблокированныхБотов(): void
    {
        $robots = new RobotsTxt(<<<TXT
        User-agent: GPTBot
        Disallow: /

        User-agent: PerplexityBot
        Disallow: /

        User-agent: ClaudeBot
        Disallow: /private/
        TXT);

        $blocked = $robots->blockedAgents(['GPTBot', 'ClaudeBot', 'PerplexityBot', 'Amazonbot']);
        $this->assertSame(['GPTBot', 'PerplexityBot'], $blocked);
    }

    public function testАдресаSitemapИзвлекаются(): void
    {
        $robots = new RobotsTxt(<<<TXT
        User-agent: *
        Disallow:

        Sitemap: https://example.com/sitemap.xml
        Sitemap: https://example.com/sitemap-news.xml
        TXT);

        $this->assertSame([
            'https://example.com/sitemap.xml',
            'https://example.com/sitemap-news.xml',
        ], $robots->sitemaps());
    }

    public function testНаличиеГруппыОпределяется(): void
    {
        $robots = new RobotsTxt("User-agent: Yandex\nDisallow: /tmp/");
        $this->assertTrue($robots->hasGroup('Yandex'));
        $this->assertTrue($robots->hasGroup('yandex'));
        $this->assertFalse($robots->hasGroup('Googlebot'));
    }

    /** Реальный robots.txt, из-за которого сайт был невидим в поиске */
    public function testРеальныйСлучайПолностьюЗакрытогоСайта(): void
    {
        $robots = new RobotsTxt(<<<TXT
        User-Agent: *
        Disallow: /

        Sitemap: https://integrom.ru/sitemap.xml
        TXT);

        $this->assertTrue($robots->blocksEverything('*'), 'Сайт закрыт от всех роботов');
        $this->assertCount(1, $robots->sitemaps());
    }
}
