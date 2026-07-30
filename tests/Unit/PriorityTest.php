<?php
namespace SeoAuditor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SeoAuditor\Report\Priority;

class PriorityTest extends TestCase
{
    private function issue(string $severity, string $type, string $title = 'Проблема'): array
    {
        return ['severity' => $severity, 'check_type' => $type, 'title' => $title];
    }

    public function testКритичнаяПроблемаПолучаетВысокоеВлияние(): void
    {
        $p = Priority::assess($this->issue('critical', 'seo'));
        $this->assertSame(3, $p['impact']);
    }

    public function testРекомендацияПолучаетНизкоеВлияние(): void
    {
        $p = Priority::assess($this->issue('info', 'commercial'));
        $this->assertSame(1, $p['impact']);
    }

    /**
     * Безопасность и закон бьют по бизнесу сильнее обычных предупреждений:
     * штраф РКН или взлом дороже просадки в выдаче.
     */
    public function testПредупрежденияБезопасностиИЗаконаПовышаютсяДоВысокогоВлияния(): void
    {
        $this->assertSame(3, Priority::assess($this->issue('warning', 'vulnerability'))['impact']);
        $this->assertSame(3, Priority::assess($this->issue('warning', 'fz152'))['impact']);
        $this->assertSame(2, Priority::assess($this->issue('warning', 'seo'))['impact'],
            'Обычное SEO-предупреждение остаётся средним');
    }

    public function testКритичнаяПростаяЗадачаПолучаетПервыйПриоритет(): void
    {
        $p = Priority::assess($this->issue('critical', 'seo', 'Отсутствует тег title'));
        $this->assertSame(1, $p['priority']);
    }

    public function testКритичнаяТрудоёмкаяЗадачаПолучаетВторойПриоритет(): void
    {
        // Переход на HTTPS — критично, но это не правка шаблона
        $p = Priority::assess($this->issue('critical', 'technical', 'Сайт не использует HTTPS'));
        $this->assertSame(3, $p['effort'], 'Миграция на HTTPS трудоёмка');
        $this->assertSame(2, $p['priority']);
    }

    public function testПриоритетВсегдаВДиапазонеОтОдногоДоЧетырёх(): void
    {
        foreach (['critical', 'warning', 'info'] as $sev) {
            foreach (['seo', 'speed', 'fz152', 'adaptive', 'vulnerability', 'неизвестный_тип'] as $type) {
                $p = Priority::assess($this->issue($sev, $type));
                $this->assertGreaterThanOrEqual(1, $p['priority']);
                $this->assertLessThanOrEqual(4, $p['priority']);
            }
        }
    }

    public function testПравкаМетатеговСчитаетсяПростой(): void
    {
        foreach (['Отсутствует title', 'Нет meta description', 'Изображения без alt',
                  'Отсутствует favicon', 'Нет canonical', 'robots.txt блокирует сайт'] as $title) {
            $p = Priority::assess($this->issue('warning', 'seo', $title));
            $this->assertSame(1, $p['effort'], "«{$title}» должна быть простой задачей");
        }
    }

    public function testМассовыеПроблемыОцениваютсяДороже(): void
    {
        $одна   = Priority::assess($this->issue('warning', 'seo', 'Изображения без alt'), 1);
        $немного = Priority::assess($this->issue('warning', 'seo', 'Изображения без alt'), 10);
        $много  = Priority::assess($this->issue('warning', 'seo', 'Изображения без alt'), 50);

        $this->assertGreaterThan($одна['hours'], $немного['hours']);
        $this->assertGreaterThan($немного['hours'], $много['hours']);
    }

    public function testБыстройПобедойСчитаетсяЗначимаяНоДешёваяЗадача(): void
    {
        $p = Priority::assess($this->issue('critical', 'seo', 'Отсутствует тег title'));
        $this->assertTrue($p['quick_win'], 'Критичная правка метатега — быстрая победа');

        $дорогая = Priority::assess($this->issue('critical', 'speed', 'Медленный ответ сервера'));
        $this->assertFalse($дорогая['quick_win'], 'Оптимизация сервера не быстрая победа');

        $мелочь = Priority::assess($this->issue('info', 'seo', 'Нет alt у одной картинки'));
        $this->assertFalse($мелочь['quick_win'], 'Рекомендация без влияния не быстрая победа');
    }

    public function testОценкаЧасовВсегдаПоложительна(): void
    {
        foreach ([1, 5, 20, 100] as $count) {
            $p = Priority::assess($this->issue('warning', 'seo'), $count);
            $this->assertGreaterThan(0, $p['hours']);
        }
    }

    public function testНеизвестныйТипПроверкиПолучаетСредниеТрудозатраты(): void
    {
        $p = Priority::assess($this->issue('warning', 'совершенно_новый_чек'));
        $this->assertSame(2, $p['effort']);
    }

    public function testПодписиПриоритетовЗаполнены(): void
    {
        foreach ([1, 2, 3, 4] as $p) {
            $this->assertStringContainsString("P$p", Priority::priorityLabel($p));
        }
        $this->assertNotSame('', Priority::impactLabel(3));
        $this->assertNotSame('', Priority::effortLabel(1));
    }
}
