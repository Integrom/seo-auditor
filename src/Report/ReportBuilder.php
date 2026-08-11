<?php
namespace SeoAuditor\Report;

use SeoAuditor\Core\Config;
use SeoAuditor\Core\Database;
use SeoAuditor\Report\Score;

class ReportBuilder
{
    private array $checkLabels = [
        'cms'          => 'CMS и технологии',
        'ip_region'    => 'IP и регион',
        'tech_stack'   => 'Стек технологий',
        'seo'          => 'SEO аудит',
        'technical'    => 'Технический аудит',
        'speed'        => 'Скорость загрузки',
        'adaptive'     => 'Адаптивность',
        'fz152'        => 'Соответствие ФЗ-152',
        'vulnerability'=> 'Безопасность',
        'yandex_seo'   => 'Яндекс SEO',
        'links'        => 'Внутренние ссылки',
        'commercial'   => 'Коммерческие факторы',
        'ai_readiness' => 'AI-готовность',
    ];

    public function build(int $auditId, array $pages, array $issues, array $siteData, array $comparison = []): string
    {
        $audit = Database::query('SELECT * FROM audits WHERE id = ?', [$auditId])->fetch();
        $url   = $audit['url'] ?? '';
        $host  = parse_url($url, PHP_URL_HOST);
        $date  = date('d.m.Y H:i');

        $critical = array_filter($issues, fn($i) => $i['severity'] === 'critical');
        $warnings = array_filter($issues, fn($i) => $i['severity'] === 'warning');
        $infos    = array_filter($issues, fn($i) => $i['severity'] === 'info');

        $score = Score::overall($issues, count($pages));

        $grouped = [];
        foreach ($issues as $issue) {
            $grouped[$issue['check_type']][] = $issue;
        }

        ob_start();
        include __DIR__ . '/../../templates/report.php';
        return ob_get_clean();
    }

    public function buildPdf(int $auditId, array $pages, array $issues, array $siteData): string
    {
        $audit = Database::query('SELECT * FROM audits WHERE id = ?', [$auditId])->fetch();
        $url   = $audit['url'] ?? '';
        $host  = parse_url($url, PHP_URL_HOST);
        $date  = date('d.m.Y H:i');
        $score = Score::overall($issues, count($pages));

        ob_start();
        include __DIR__ . '/../../templates/report_pdf.php';
        return ob_get_clean();
    }

    public function exportPdf(string $html, string $uuid): string
    {
        $dir = Config::get('reports_dir', dirname(__DIR__, 2) . '/reports');
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $pdfPath = "$dir/$uuid.pdf";
        // html уже готов (простой PDF-шаблон), очищаем только JS на всякий случай
        $html    = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $html);

        try {
            $mpdf = new \Mpdf\Mpdf(array_merge([
                'mode'          => 'utf-8',
                'format'        => 'A4',
                'margin_top'    => 18,
                'margin_right'  => 14,
                'margin_bottom' => 18,
                'margin_left'   => 14,
                'tempDir'       => sys_get_temp_dir(),
            ], $this->fontConfig()));

            $mpdf->SetDisplayMode('fullpage');
            $mpdf->autoScriptToLang = true;
            $mpdf->autoLangToFont   = true;
            $mpdf->WriteHTML($html);
            $mpdf->Output($pdfPath, 'F');
        } catch (\Exception $e) {
            error_log("PDF export failed: " . $e->getMessage());
            $pdfPath = '';
        }

        return $pdfPath;
    }

    /**
     * Шрифты отчёта для mPDF. Он понимает только TrueType, поэтому рядом
     * с веб-версиями (woff2) лежат ttf. Если файлов нет, возвращаем пустую
     * настройку — mPDF возьмёт встроенный шрифт, отчёт соберётся всё равно.
     */
    private function fontConfig(): array
    {
        $dir      = dirname(__DIR__, 2) . '/resources/fonts';
        $required = ['Oswald-Medium.ttf', 'Oswald-SemiBold.ttf', 'IBMPlexSans-Regular.ttf', 'IBMPlexSans-SemiBold.ttf'];

        foreach ($required as $file) {
            if (!is_file("$dir/$file")) {
                error_log("[PDF] нет шрифта $file, использую встроенный");
                return [];
            }
        }

        $default = (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'];

        return [
            'fontDir'      => array_merge((new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'], [$dir]),
            'fontdata'     => $default + [
                'oswald' => ['R' => 'Oswald-Medium.ttf',       'B' => 'Oswald-SemiBold.ttf'],
                'plex'   => ['R' => 'IBMPlexSans-Regular.ttf', 'B' => 'IBMPlexSans-SemiBold.ttf'],
            ],
            'default_font' => 'plex',
        ];
    }

    public function getScoreColor(int $score): string
    {
        if ($score >= 80) return '#22c55e';
        if ($score >= 60) return '#f59e0b';
        return '#ef4444';
    }
}
