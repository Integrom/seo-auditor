<?php
namespace SeoAuditor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SeoAuditor\Report\Score;

class ScoreTest extends TestCase
{
    private function issue(string $severity, string $type, string $title): array
    {
        return ['severity' => $severity, 'check_type' => $type, 'title' => $title];
    }

    public function testИдеальныйСайтПолучаетСтоБаллов(): void
    {
        $this->assertSame(100, Score::overall([]));
    }

    public function testРекомендацииНеСнижаютОценку(): void
    {
        $issues = [
            $this->issue('info', 'seo', 'Sitemap найден'),
            $this->issue('info', 'speed', 'Время ответа сервера: 53мс'),
        ];
        $this->assertSame(100, Score::overall($issues));
    }

    public function testКритичнаяПроблемаСтоитДвенадцатьБаллов(): void
    {
        $issues = [$this->issue('critical', 'seo', 'Отсутствует тег title')];
        $this->assertSame(88, Score::overall($issues));
    }

    public function testВажнаяПроблемаСтоитДваБалла(): void
    {
        $issues = [$this->issue('warning', 'seo', 'Короткий description')];
        $this->assertSame(98, Score::overall($issues));
    }

    /**
     * Ключевое поведение: одна и та же проблема на множестве страниц —
     * это одна задача для разработчика, а не отдельный штраф за каждую страницу.
     */
    public function testОднаПроблемаНаМножествеСтраницШтрафуетсяОдинРаз(): void
    {
        $issues = [];
        for ($i = 0; $i < 78; $i++) {
            $issues[] = $this->issue('warning', 'seo', 'Изображения без alt');
        }
        $this->assertSame(98, Score::overall($issues), 'Ожидался один штраф за 78 страниц');
    }

    public function testЧисловыеДеталиВЗаголовкеНеСоздаютНовуюПроблему(): void
    {
        $issues = [
            $this->issue('warning', 'seo', 'Title слишком короткий: 8 симв.'),
            $this->issue('warning', 'seo', 'Title слишком короткий: 12 симв.'),
            $this->issue('warning', 'seo', 'Title слишком короткий: 3 симв.'),
        ];
        [$crit, $warn] = Score::countUnique($issues);
        $this->assertSame(0, $crit);
        $this->assertSame(1, $warn, 'Разная длина в заголовке — всё равно одна проблема');
    }

    public function testРазныеТипыПроверокСчитаютсяОтдельно(): void
    {
        $issues = [
            $this->issue('warning', 'seo', 'Нет описания'),
            $this->issue('warning', 'fz152', 'Нет описания'),
        ];
        [, $warn] = Score::countUnique($issues);
        $this->assertSame(2, $warn);
    }

    public function testОценкаНеУходитВМинус(): void
    {
        $issues = [];
        for ($i = 0; $i < 20; $i++) {
            $issues[] = $this->issue('critical', "type$i", "Критичная проблема $i");
        }
        $this->assertSame(0, Score::overall($issues));
    }

    public function testОценкаРазделаУчитываетТолькоГруппы(): void
    {
        $this->assertSame(100, Score::category(0, 0));
        $this->assertSame(79,  Score::category(1, 0));
        $this->assertSame(95,  Score::category(0, 1));
        $this->assertSame(74,  Score::category(1, 1));
        $this->assertSame(0,   Score::category(5, 0));
    }

    public function testЛюбаяКритичнаяПроблемаВыводитРазделИзЗелёнойЗоны(): void
    {
        // В отчёте зелёная зона — от 80%. Одна критичная проблема должна её пробивать,
        // иначе раздел с критичной ошибкой выглядит благополучным
        $this->assertLessThan(80, Score::category(1, 0));
    }

    public function testБазовыйЗаголовокОтсекаетЧисловойХвост(): void
    {
        $this->assertSame('Title слишком короткий', Score::baseTitle('Title слишком короткий: 8 симв.'));
        $this->assertSame('Битых изображений', Score::baseTitle('Битых изображений: 4'));
        $this->assertSame('Отсутствует тег title', Score::baseTitle('Отсутствует тег title'));
    }

    public function testБазовыйЗаголовокНеЛомаетДвоеточиеБезЦифр(): void
    {
        $this->assertSame(
            'Трансграничная передача данных: Google Fonts',
            Score::baseTitle('Трансграничная передача данных: Google Fonts')
        );
    }

    public function testСмешанныйНаборПроблем(): void
    {
        $issues = [
            $this->issue('critical', 'seo', 'robots.txt блокирует весь сайт'),
            $this->issue('critical', 'seo', 'Отсутствует тег title'),
            $this->issue('critical', 'fz152', 'Нет согласия на cookie'),
            $this->issue('warning', 'speed', 'Медленный ответ сервера: 3400мс'),
            $this->issue('warning', 'speed', 'Медленный ответ сервера: 2100мс'),
            $this->issue('info', 'cms', 'CMS: 1С-Битрикс'),
        ];
        // 3 критичных × 12 + 1 важная × 2 = 38 штрафа
        $this->assertSame(62, Score::overall($issues));
    }
}
