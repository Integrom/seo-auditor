<?php
namespace SeoAuditor\Checks;

class CommercialFactorsCheck extends BaseCheck
{
    public function run(array $pages, array &$siteData): array
    {
        if (empty($pages)) return [];
        $url = $pages[0]['url'] ?? '';

        // Берём текст первых 10 страниц для анализа
        $sample  = array_slice($pages, 0, 10);
        $allHtml = implode(' ', array_column($sample, 'html'));
        $allText = mb_strtolower(strip_tags($allHtml));
        $allHtmlLower = mb_strtolower($allHtml);

        $factors = $this->detectFactors($allHtml, $allText, $allHtmlLower, $url);

        $total        = count($factors);
        $presentCount = count(array_filter($factors));
        $absent       = array_keys(array_filter($factors, fn($v) => !$v));

        $siteData['commercial_factors'] = $factors;
        $siteData['commercial_score']   = $total > 0 ? round($presentCount / $total * 100) : 0;

        // Итоговый результат
        if ($presentCount < (int)($total * 0.5)) {
            $this->warning('commercial', "Коммерческие факторы: $presentCount из $total",
                "Обнаружено $presentCount из $total факторов. Отсутствуют: " . implode(', ', array_slice($absent, 0, 6)),
                "Добавьте недостающие коммерческие элементы для повышения доверия и конверсии.",
                $url
            );
        } else {
            $this->info('commercial', "Коммерческие факторы: $presentCount из $total",
                "Обнаружено $presentCount из $total коммерческих факторов.",
                "Добавьте недостающие элементы: " . implode(', ', array_slice($absent, 0, 4)),
                $url
            );
        }

        // Отдельный issue на каждый отсутствующий фактор
        foreach ($factors as $factor => $present) {
            if (!$present) {
                $this->info('commercial', "Отсутствует: $factor",
                    "На сайте не обнаружен коммерческий элемент «{$factor}».",
                    $this->getRecommendation($factor),
                    $url
                );
            }
        }

        return $this->issues;
    }

    private function detectFactors(string $allHtml, string $allText, string $allHtmlLower, string $url): array
    {
        $factors = [];

        // HTTPS
        $factors['HTTPS'] = str_starts_with($url, 'https://');

        // Телефон
        $factors['Телефон'] = (bool)preg_match(
            '/(?:\+7|8)[\s\-]?\(?\d{3}\)?[\s\-]?\d{3}[\s\-]?\d{2}[\s\-]?\d{2}/',
            $allText
        );

        // Адрес
        $factors['Адрес'] = (bool)preg_match(
            '/(?:ул\.|улица|проспект|пр-т|переулок|пер\.|бульвар|набережная|шоссе|ш\.|г\. |г\.)/iu',
            $allText
        );

        // Режим работы
        $factors['Режим работы'] = (bool)preg_match(
            '/(?:режим работы|часы работы|пн[-–]пт|пн[-–]сб|понедельник|ежедневно|без выходных|круглосуточно|working hours)/iu',
            $allText
        );

        // Отзывы
        $factors['Отзывы/рейтинг'] = (bool)preg_match('/(?:отзыв|review|rating|оценк|рейтинг|testimonial)/iu', $allHtmlLower);

        // Калькулятор
        $factors['Калькулятор'] = (bool)preg_match('/(?:калькулятор|calculator|рассчит|расчёт стоимости|расчет стоимости)/iu', $allText);

        // Карта / схема проезда
        $factors['Карта/схема проезда'] = (bool)preg_match(
            '/(?:yandex\.ru\/maps|maps\.google|2gis\.ru|схема проезда|как добраться|карта)/iu',
            $allHtmlLower
        );

        // Кнопка "Заказать"
        $factors['Кнопка «Заказать»'] = (bool)preg_match(
            '/(?:заказать|оставить заявку|оставить заявку|купить|оформить заказ|получить консультацию|отправить заявку)/iu',
            $allText
        );

        // Онлайн-консультант
        $factors['Онлайн-консультант'] = (bool)preg_match(
            '/(?:jivo|jivosite|livetex|verbox|omnidesk|talkme|callibri|livechat|helpcrunch|bitrix24.*chat|jivowidget)/iu',
            $allHtmlLower
        );

        // ВКонтакте
        $factors['ВКонтакте'] = str_contains($allHtmlLower, 'vk.com');

        // Telegram
        $factors['Telegram'] = str_contains($allHtmlLower, 't.me/') || str_contains($allHtmlLower, 'telegram');

        // YouTube
        $factors['YouTube'] = str_contains($allHtmlLower, 'youtube.com') || str_contains($allHtmlLower, 'youtu.be');

        // Способы оплаты
        $factors['Способы оплаты'] = (bool)preg_match(
            '/(?:способ.{0,25}оплат|оплата онлайн|visa|mastercard|мир|наличными|безналичн|sbp|qr-код)/iu',
            $allText
        );

        // ИНН/ОГРН/Реквизиты
        $factors['Реквизиты (ИНН/ОГРН)'] = (bool)preg_match('/(?:ИНН|ОГРН|ООО\s|ИП\s|ЗАО|АО\s)/iu', $allText);

        return $factors;
    }

    private function getRecommendation(string $factor): string
    {
        return match($factor) {
            'HTTPS'                   => 'Установите SSL-сертификат. Let\'s Encrypt — бесплатно.',
            'Телефон'                 => 'Добавьте номер телефона в шапку и на страницу контактов. Используйте формат +7 (000) 000-00-00.',
            'Адрес'                   => 'Укажите юридический или фактический адрес компании на странице контактов.',
            'Режим работы'            => 'Добавьте часы работы в шапку сайта и на страницу контактов.',
            'Отзывы/рейтинг'          => 'Добавьте раздел с отзывами клиентов, виджет Яндекс.Карт или 2ГИС с оценками.',
            'Калькулятор'             => 'Онлайн-калькулятор расчёта стоимости увеличивает конверсию до 30%.',
            'Карта/схема проезда'     => 'Добавьте интерактивную карту (Яндекс.Карты) на страницу контактов.',
            'Кнопка «Заказать»'       => 'Разместите заметную кнопку CTA («Заказать», «Оставить заявку») на каждой странице.',
            'Онлайн-консультант'      => 'Установите онлайн-чат (JivoSite, Callibri) — повышает конверсию на 10–25%.',
            'ВКонтакте'               => 'Создайте группу ВКонтакте и добавьте ссылку на сайт.',
            'Telegram'                => 'Создайте Telegram-канал или чат-бот для связи с клиентами.',
            'YouTube'                 => 'Создайте YouTube-канал с видео о ваших услугах/товарах.',
            'Способы оплаты'          => 'Укажите принимаемые способы оплаты: карта, наличные, онлайн-оплата.',
            'Реквизиты (ИНН/ОГРН)'   => 'Разместите реквизиты компании (ИНН, ОГРН, полное наименование) для повышения доверия.',
            default                   => "Добавьте элемент «{$factor}» для улучшения коммерческих характеристик сайта.",
        };
    }
}
