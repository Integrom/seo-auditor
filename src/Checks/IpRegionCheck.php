<?php
namespace SeoAuditor\Checks;

use GuzzleHttp\Client;

class IpRegionCheck extends BaseCheck
{
    public function run(array $pages, array &$siteData): array
    {
        $url  = $pages[0]['url'] ?? '';
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return [];

        $ip = gethostbyname($host);
        $siteData['ip'] = $ip;

        $rdns = gethostbyaddr($ip);
        $siteData['rdns'] = $rdns !== $ip ? $rdns : '';

        // NS-серверы
        $this->checkNs($host, $url);

        // Геолокация
        try {
            $client   = new Client(['timeout' => 5, 'verify' => false]);
            $response = $client->get("http://ip-api.com/json/$ip?fields=status,country,countryCode,regionName,city,isp,org,as&lang=ru");
            $geo      = json_decode((string)$response->getBody(), true);

            if (($geo['status'] ?? '') === 'success') {
                $siteData['country']     = $geo['country'] ?? '';
                $siteData['countryCode'] = $geo['countryCode'] ?? '';
                $siteData['region']      = $geo['regionName'] ?? '';
                $siteData['city']        = $geo['city'] ?? '';
                $siteData['isp']         = $geo['isp'] ?? '';
                $siteData['org']         = $geo['org'] ?? '';
                $siteData['as']          = $geo['as'] ?? '';

                $this->info('ip_region',
                    "Хостинг: {$geo['org']}",
                    "Сервер расположен в {$geo['city']}, {$geo['regionName']}, {$geo['country']}. IP: $ip. Провайдер: {$geo['isp']}.",
                    "Для максимальной скорости выберите хостинг ближе к вашей целевой аудитории.",
                    $url
                );
            }
        } catch (\Exception $e) {
            $siteData['country'] = $siteData['region'] = $siteData['city'] = '';
        }

        if (!empty($siteData['countryCode']) && $siteData['countryCode'] !== 'RU') {
            $this->warning('ip_region',
                'Сервер расположен за рубежом',
                "Сервер с IP $ip находится вне России. Правовые риски при работе с персональными данными (ФЗ-152).",
                "Для работы с персональными данными граждан РФ сервер должен находиться в России (ФЗ-152, ст. 18).",
                $url
            );
        }

        // Возраст домена через WHOIS
        $this->checkWhois($host, $url);

        return $this->issues;
    }

    private function checkNs(string $host, string $url): void
    {
        // Берём корневой домен для DNS-запроса
        $parts      = explode('.', $host);
        $rootDomain = count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $host;

        $records = @dns_get_record($rootDomain, DNS_NS);
        if (!empty($records)) {
            $ns = array_column($records, 'target');
            $this->info('ip_region', 'NS-серверы: ' . implode(', ', $ns),
                "DNS-серверы домена: " . implode(', ', $ns),
                "Убедитесь что NS-серверы принадлежат вашему хостинг-провайдеру и дублируются (минимум 2).",
                $url
            );
        }
    }

    private function checkWhois(string $host, string $url): void
    {
        $parts      = explode('.', $host);
        $rootDomain = count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $host;

        $output = @shell_exec("whois " . escapeshellarg($rootDomain) . " 2>/dev/null");
        if (empty($output)) return;

        // Дата регистрации
        if (preg_match('/(?:Creation Date|Registered|created|Registration Date|Registered On):\s*(\d{4}-\d{2}-\d{2}|\d{2}\.\d{2}\.\d{4})/i', $output, $m)) {
            $dateStr   = str_replace('.', '-', $m[1]);
            // Нормализуем DD-MM-YYYY → YYYY-MM-DD
            if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $dateStr)) {
                [$d, $mo, $y] = explode('-', $dateStr);
                $dateStr = "$y-$mo-$d";
            }
            $timestamp = strtotime($dateStr);
            if ($timestamp && $timestamp < time()) {
                $ageYears  = (int)((time() - $timestamp) / (365.25 * 86400));
                $ageMonths = (int)((time() - $timestamp) / (30.5 * 86400)) % 12;
                $ageStr    = $ageYears > 0 ? "$ageYears лет $ageMonths мес." : "$ageMonths мес.";

                if ($ageYears < 1) {
                    $this->warning('ip_region', "Домен зарегистрирован менее года назад ($ageStr)",
                        "Новые домены имеют меньший авторитет в поисковых системах.",
                        "Авторитет домена растёт со временем. Сосредоточьтесь на качестве контента и ссылочной массе.",
                        $url
                    );
                } else {
                    $this->info('ip_region', "Возраст домена: $ageStr",
                        "Домен зарегистрирован $ageStr назад (дата: " . date('d.m.Y', $timestamp) . ").",
                        "Продлевайте регистрацию домена заранее (за 30+ дней до истечения).",
                        $url
                    );
                }
            }
        }

        // Дата истечения
        if (preg_match('/(?:Expiry Date|Expiration Date|paid-till|Registry Expiry Date):\s*(\d{4}-\d{2}-\d{2}|\d{2}\.\d{2}\.\d{4})/i', $output, $m)) {
            $dateStr = str_replace('.', '-', $m[1]);
            if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $dateStr)) {
                [$d, $mo, $y] = explode('-', $dateStr);
                $dateStr = "$y-$mo-$d";
            }
            $expTs = strtotime($dateStr);
            if ($expTs) {
                $daysLeft = (int)(($expTs - time()) / 86400);
                $expDate  = date('d.m.Y', $expTs);
                if ($daysLeft < 30) {
                    $this->critical('ip_region', "Домен истекает через $daysLeft дней ($expDate)",
                        "Срок регистрации домена заканчивается! После истечения домен перейдёт в открытую продажу.",
                        "Немедленно продлите регистрацию домена у вашего регистратора.",
                        $url
                    );
                } elseif ($daysLeft < 90) {
                    $this->warning('ip_region', "Домен истекает $expDate (через $daysLeft дней)",
                        "Срок регистрации домена заканчивается менее чем через 3 месяца.",
                        "Продлите регистрацию домена заранее.",
                        $url
                    );
                } else {
                    $this->info('ip_region', "Домен зарегистрирован до $expDate",
                        "До истечения регистрации $daysLeft дней.",
                        "Включите авто-продление у регистратора.",
                        $url
                    );
                }
            }
        }
    }
}
