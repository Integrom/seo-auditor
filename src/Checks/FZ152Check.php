<?php
namespace SeoAuditor\Checks;

/**
 * Проверка соответствия ФЗ-152 с учётом требований 2025 года:
 * - политика конфиденциальности в один клик;
 * - согласие на cookie отдельным документом (с 01.09.2025);
 * - чекбоксы согласия в формах сбора ПД;
 * - трансграничная передача данных (иностранные счётчики);
 * - локализация хранения ПД в РФ (ФЗ-242);
 * - уведомление Роскомнадзора.
 */
class FZ152Check extends BaseCheck
{
    public function run(array $pages, array &$siteData): array
    {
        if (empty($pages)) return [];
        $firstPage = $pages[0];
        $html      = $firstPage['html'] ?? '';
        $url       = $firstPage['url'] ?? '';

        $this->checkPrivacyPolicy($html, $url, $pages);
        $this->checkCookieConsent($html, $url, $pages);
        $this->checkForms($pages);
        $this->checkForeignCounters($html, $url);
        $this->checkDataLocalization($siteData, $url, $pages);
        $this->checkRknNotice($url);

        return $this->issues;
    }

    private function checkPrivacyPolicy(string $html, string $url, array $pages): void
    {
        // Ссылка на политику с главной страницы («в один клик»)
        $linkOnHome = (bool)preg_match(
            '/<a[^>]+href=["\'][^"\']*(?:privacy|policy|polit|konfiden|personal)[^"\']*["\'][^>]*>|<a[^>]*>[^<]*(?:политик[аи]\s+(?:конфиденциальности|обработки)|обработк[аи]\s+персональных)[^<]*<\/a>/iu',
            $html
        );

        // Сама страница политики среди обойдённых
        $policyPage = null;
        foreach ($pages as $page) {
            $pu = strtolower($page['url'] ?? '');
            if (preg_match('/(privacy|policy|polit|konfiden|personal-data|pdn)/', $pu)) {
                $policyPage = $page;
                break;
            }
        }

        $mentioned = false;
        foreach ($pages as $page) {
            if (preg_match('/(политик[аи].{0,30}конфиденциальност|обработк[аи].{0,30}персональн)/ui', mb_strtolower($page['html'] ?? ''))) {
                $mentioned = true;
                break;
            }
        }

        if (!$linkOnHome && !$policyPage && !$mentioned) {
            $this->critical('fz152', 'Не найдена политика обработки персональных данных',
                'На сайте отсутствует политика конфиденциальности. Это прямое нарушение ст. 18.1 ФЗ-152. С 30.05.2025 штрафы за нарушения ужесточены (для юрлиц — до 300 000 ₽ за отсутствие документов).',
                'Опубликуйте «Политику обработки персональных данных» отдельной страницей и разместите ссылку на неё в подвале каждой страницы сайта.',
                $url
            );
            return;
        }

        if (!$linkOnHome) {
            $this->warning('fz152', 'Политика недоступна «в один клик» с главной',
                'Упоминание политики на сайте есть, но прямая ссылка с главной страницы не найдена. Требование РКН: политика должна открываться в один клик с каждой страницы, где собираются данные.',
                'Добавьте ссылку «Политика конфиденциальности» в подвал сайта — она должна вести на страницу с полным текстом (не PDF-файл).',
                $url
            );
        } else {
            $this->info('fz152', 'Политика конфиденциальности доступна с главной',
                'Ссылка на политику обработки персональных данных найдена на главной странице.',
                'Проверьте актуальность текста: с 2025 года политика должна содержать перечень обрабатываемых данных, цели, сроки хранения и сведения о трансграничной передаче.',
                $policyPage['url'] ?? $url
            );
        }
    }

    private function checkCookieConsent(string $html, string $url, array $pages): void
    {
        $lower = mb_strtolower($html);

        $hasCounters = str_contains($html, 'mc.yandex.ru') || str_contains($html, 'google-analytics')
            || str_contains($html, 'gtag(') || str_contains($html, 'top.mail.ru') || str_contains($html, 'vk.com/rtrg');

        $hasBanner = (bool)preg_match(
            '/(cookie[-_ ]?(banner|consent|notice|bar|popup|agree)|соглас.{0,40}(cookie|куки)|использу.{0,30}(cookie|куки)|файл[ыов]{1,2}\s+cookie)/ui',
            $lower
        );

        // Отдельный документ о cookie (требование с 01.09.2025)
        $hasCookieDoc = false;
        foreach ($pages as $page) {
            $pu = strtolower($page['url'] ?? '');
            if (str_contains($pu, 'cookie')) { $hasCookieDoc = true; break; }
        }
        if (!$hasCookieDoc) {
            $hasCookieDoc = (bool)preg_match('/<a[^>]+href=["\'][^"\']*cookie[^"\']*["\']/i', $html);
        }

        // Возможность отказа
        $hasDecline = (bool)preg_match('/(отклонить|отказаться|только необходимые|настроить cookie|reject|decline|necessary only)/ui', $lower);

        if ($hasCounters && !$hasBanner) {
            $this->critical('fz152', 'Нет согласия на использование cookie',
                'Сайт устанавливает cookie через счётчики аналитики без согласия пользователя. С 01.09.2025 согласие на cookie должно оформляться отдельным документом — это требование РКН, за нарушение предусмотрены штрафы.',
                'Установите cookie-баннер с кнопками «Принять» и «Отклонить» и отдельным документом «Согласие на обработку cookie». Аналитика должна запускаться только после согласия.',
                $url
            );
            return;
        }

        if ($hasBanner) {
            if (!$hasDecline) {
                $this->warning('fz152', 'Cookie-баннер без возможности отказа',
                    'Уведомление о cookie найдено, но кнопки «Отклонить» / «Только необходимые» не обнаружено. Согласие должно быть добровольным — пользователь должен иметь возможность отказаться.',
                    'Добавьте в баннер кнопку отказа от необязательных cookie. До получения согласия не запускайте счётчики и рекламные пиксели.',
                    $url
                );
            } else {
                $this->info('fz152', 'Cookie-баннер с возможностью выбора найден',
                    'На сайте есть уведомление о cookie с возможностью отказа.',
                    'Убедитесь, что до нажатия «Принять» аналитика и пиксели фактически не загружаются.',
                    $url
                );
            }

            if (!$hasCookieDoc) {
                $this->warning('fz152', 'Нет отдельного документа о cookie',
                    'С 01.09.2025 согласие на cookie оформляется отдельным документом, а не пунктом политики конфиденциальности. Отдельная страница о cookie не найдена.',
                    'Создайте страницу «Политика использования cookie» (какие cookie, зачем, сроки) и дайте на неё ссылку из cookie-баннера.',
                    $url
                );
            }
        }
    }

    private function checkForms(array $pages): void
    {
        $reported = 0;
        foreach ($pages as $page) {
            if ($reported >= 10) break;
            $html = $page['html'] ?? '';
            $url  = $page['url'] ?? '';
            if (stripos($html, '<form') === false) continue;

            // Формы с полями ПД (телефон, email, имя)
            $hasPdField = (bool)preg_match(
                '/<input[^>]+(?:type=["\'](?:tel|email)["\']|name=["\'][^"\']*(?:phone|tel|email|name|fio|fname)[^"\']*["\'])/i',
                $html
            );
            if (!$hasPdField) continue;

            $lower = mb_strtolower($html);
            $hasConsentText = (bool)preg_match('/(соглас.{0,60}обработк|обработк.{0,40}персональн|даю соглас)/ui', $lower);
            $hasCheckbox    = (bool)preg_match('/<input[^>]+type=["\']checkbox["\']/i', $html);

            if (!$hasConsentText) {
                $this->critical('fz152', 'Форма сбора ПД без согласия на обработку',
                    'Форма собирает персональные данные (телефон/email/имя), но текст согласия на обработку ПД рядом с ней не найден. Сбор данных без согласия — нарушение ст. 9 ФЗ-152.',
                    'Добавьте чекбокс «Даю согласие на обработку персональных данных» со ссылкой на политику. Чекбокс не должен быть отмечен по умолчанию.',
                    $url
                );
                $reported++;
            } elseif (!$hasCheckbox) {
                $this->warning('fz152', 'Согласие в форме без чекбокса',
                    'Возле формы есть текст о согласии, но чекбокс не найден. РКН требует активного действия пользователя — предустановленное или неявное согласие не считается действительным.',
                    'Замените пассивный текст на чекбокс (не отмеченный по умолчанию), без которого форма не отправляется.',
                    $url
                );
                $reported++;
            } else {
                $this->info('fz152', 'Форма с согласием на обработку ПД',
                    'Форма содержит текст согласия и чекбокс.',
                    'Убедитесь, что чекбокс не отмечен по умолчанию, а факт согласия сохраняется (дата, время, IP) — при проверке РКН это доказательная база.',
                    $url
                );
                $reported++;
            }
        }
    }

    private function checkForeignCounters(string $html, string $url): void
    {
        $foreign = [];
        if (str_contains($html, 'google-analytics.com') || str_contains($html, 'gtag(') || str_contains($html, 'googletagmanager.com')) {
            $foreign[] = 'Google Analytics / GTM';
        }
        if (str_contains($html, 'facebook.net') || str_contains($html, 'connect.facebook')) {
            $foreign[] = 'Meta Pixel (Facebook)';
        }
        if (str_contains($html, 'fonts.googleapis.com') || str_contains($html, 'fonts.gstatic.com')) {
            $foreign[] = 'Google Fonts';
        }
        if (str_contains($html, 'www.google.com/recaptcha') || str_contains($html, 'recaptcha.net')) {
            $foreign[] = 'Google reCAPTCHA';
        }

        if (!empty($foreign)) {
            $names = implode(', ', $foreign);
            $this->warning('fz152', "Трансграничная передача данных: $names",
                "Сайт использует иностранные сервисы, передающие данные пользователей за рубеж: $names. С 2025 года РКН усилил контроль за трансграничной передачей — она требует отдельного уведомления и правовых оснований.",
                'По возможности замените на российские аналоги (Яндекс.Метрика, VK Пиксель, SmartCaptcha, локальные шрифты). Если оставляете — отразите трансграничную передачу в политике и уведомлении РКН.',
                $url
            );
        }
    }

    private function checkDataLocalization(array $siteData, string $url, array $pages): void
    {
        $cc = $siteData['countryCode'] ?? '';
        if ($cc === '' || $cc === 'RU') return;

        // Есть ли на сайте формы сбора ПД
        $collectsPd = false;
        foreach ($pages as $page) {
            if (preg_match('/<input[^>]+type=["\'](?:tel|email)["\']/i', $page['html'] ?? '')) {
                $collectsPd = true;
                break;
            }
        }
        if (!$collectsPd) return;

        $country = $siteData['country'] ?? $cc;
        $this->critical('fz152', "Сервер за пределами РФ ($country) при сборе ПД",
            "Сайт собирает персональные данные россиян, но хостинг находится в стране: $country. ФЗ-242 требует первичную запись и хранение ПД граждан РФ в базах на территории России.",
            'Перенесите сайт (или как минимум БД с персональными данными) на хостинг в РФ, либо обеспечьте первичное хранение ПД на российском сервере.',
            $url
        );
    }

    private function checkRknNotice(string $url): void
    {
        $this->info('fz152', 'Проверьте уведомление Роскомнадзора',
            'С 30.05.2025 обработка ПД без подачи уведомления в РКН считается незаконной (штраф для юрлиц — до 300 000 ₽). Автоматически проверить факт подачи невозможно.',
            'Проверьте наличие компании в реестре операторов ПД на сайте pd.rkn.gov.ru. Если записи нет — подайте уведомление через личный кабинет.',
            $url
        );
    }
}
