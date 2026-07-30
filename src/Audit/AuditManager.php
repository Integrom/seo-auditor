<?php
namespace SeoAuditor\Audit;

use SeoAuditor\Core\Config;
use SeoAuditor\Core\Crawler;
use SeoAuditor\Core\Database;
use SeoAuditor\Checks\CmsDetector;
use SeoAuditor\Checks\IpRegionCheck;
use SeoAuditor\Checks\TechStackCheck;
use SeoAuditor\Checks\SeoCheck;
use SeoAuditor\Checks\TechnicalCheck;
use SeoAuditor\Checks\SpeedCheck;
use SeoAuditor\Checks\AdaptiveCheck;
use SeoAuditor\Checks\FZ152Check;
use SeoAuditor\Checks\VulnerabilityCheck;
use SeoAuditor\Checks\YandexSeoCheck;
use SeoAuditor\Checks\CommercialFactorsCheck;
use SeoAuditor\Checks\LinksCheck;
use SeoAuditor\Checks\ResourceCheck;
use SeoAuditor\Checks\AiReadinessCheck;
use SeoAuditor\Report\ReportBuilder;
use SeoAuditor\Email\Mailer;

class AuditManager
{
    private int    $auditId;
    private string $auditUuid;
    private string $url;
    private string $email;
    private string $host;
    private ?int   $previousAuditId = null;

    public function __construct(int $auditId)
    {
        $this->auditId = $auditId;
        $row = Database::query('SELECT * FROM audits WHERE id = ?', [$auditId])->fetch();
        if (!$row) throw new \RuntimeException("Audit $auditId not found");
        $this->auditUuid       = $row['uuid'];
        $this->url             = $row['url'];
        $this->email           = $row['email'];
        $this->host            = $row['host'] ?: (parse_url($row['url'], PHP_URL_HOST) ?? '');
        $this->previousAuditId = $row['previous_audit_id'] ? (int)$row['previous_audit_id'] : null;
    }

    public function run(): void
    {
        try {
            $this->setProgress(2, 'Запуск аудита...');

            // ── 1. Краулинг ────────────────────────────────────────────────
            $this->setStatus('crawling');
            $this->setProgress(5, 'Обход страниц сайта...');

            $crawler = new Crawler();
            $pages   = $crawler->crawl($this->url, function ($page, $n) {
                Database::insert('audit_pages', [
                    'audit_id'    => $this->auditId,
                    'url'         => $page['url'],
                    'status_code' => $page['status_code'],
                    'title'       => '',
                    'crawled'     => 1,
                ]);
                Database::update('audits', [
                    'pages_crawled' => $n,
                    'progress'      => min(30, 5 + (int)($n / max(1, Config::get('crawler.max_pages', 100)) * 25)),
                    'progress_text' => "Обход сайта: $n страниц...",
                ], 'id = :id', [':id' => $this->auditId]);
            });

            Database::update('audits', ['pages_total' => count($pages)], 'id = :id', [':id' => $this->auditId]);

            if (empty($pages)) {
                throw new \RuntimeException('Не удалось загрузить ни одной страницы. Проверьте URL.');
            }

            // ── 2. Проверки ────────────────────────────────────────────────
            $this->setStatus('checking');
            $siteData  = [];
            $allIssues = [];

            $checks = [
                [6,  'CMS и технологии',      new CmsDetector()],
                [12, 'IP и регион',           new IpRegionCheck()],
                [18, 'Стек технологий',       new TechStackCheck()],
                [26, 'SEO аудит',             new SeoCheck()],
                [34, 'Технический аудит',     new TechnicalCheck()],
                [42, 'Ресурсы (JS/CSS/img)',  new ResourceCheck()],
                [50, 'Внутренние ссылки',     new LinksCheck()],
                [58, 'Скорость загрузки',     new SpeedCheck()],
                [66, 'Адаптивность',          new AdaptiveCheck()],
                [72, 'Коммерческие факторы',  new CommercialFactorsCheck()],
                [80, 'ФЗ-152',                new FZ152Check()],
                [86, 'Уязвимости',            new VulnerabilityCheck()],
                [92, 'Яндекс SEO',            new YandexSeoCheck()],
                [97, 'AI-готовность',         new AiReadinessCheck()],
            ];

            foreach ($checks as [$baseProgress, $label, $check]) {
                $this->setProgress(30 + (int)(($baseProgress / 100) * 60), "Проверка: $label...");
                try {
                    $issues    = $check->run($pages, $siteData);
                    $allIssues = array_merge($allIssues, $issues);
                } catch (\Exception $e) {
                    error_log("Check $label failed: " . $e->getMessage());
                }
            }

            // ── 3. Сохраняем проблемы + ключи для сравнения ───────────────
            $this->setProgress(92, 'Сохранение результатов...');
            $prevKeys = $this->getPreviousIssueKeys();

            foreach ($allIssues as &$issue) {
                $key           = md5(($issue['check_type'] ?? '') . '|' . ($issue['title'] ?? ''));
                $issue['is_new'] = isset($prevKeys[$key]) ? 0 : 1;
                Database::insert('audit_issues', [
                    'audit_id'       => $this->auditId,
                    'page_id'        => $issue['page_id'] ?? null,
                    'check_type'     => $issue['check_type'],
                    'severity'       => $issue['severity'],
                    'title'          => $issue['title'],
                    'description'    => $issue['description'],
                    'recommendation' => $issue['recommendation'],
                    'url'            => $issue['url'] ?? '',
                    'issue_key'      => $key,
                    'is_new'         => $issue['is_new'],
                ]);
            }
            unset($issue);

            // ── 4. Считаем сравнение ──────────────────────────────────────
            $comparison = $this->buildComparison($allIssues, $prevKeys);
            $score      = $this->calcScore($allIssues);

            Database::update('audits', ['score' => $score], 'id = :id', [':id' => $this->auditId]);

            // ── 5. Формируем отчёт ────────────────────────────────────────
            $this->setStatus('reporting');
            $this->setProgress(95, 'Формирование отчёта...');

            $builder    = new ReportBuilder();
            $htmlReport = $builder->build($this->auditId, $pages, $allIssues, $siteData, $comparison);
            $pdfHtml    = $builder->buildPdf($this->auditId, $pages, $allIssues, $siteData);
            $pdfPath    = $builder->exportPdf($pdfHtml, $this->auditUuid);

            Database::insert('audit_reports', [
                'audit_id'       => $this->auditId,
                'html_report'    => $htmlReport,
                'pdf_path'       => $pdfPath,
                'audit_data'     => json_encode($siteData, JSON_UNESCAPED_UNICODE),
                'fixed_count'    => $comparison['fixed_count'],
                'new_count'      => $comparison['new_count'],
                'unchanged_count' => $comparison['unchanged_count'],
            ]);

            // ── 6. Email ──────────────────────────────────────────────────
            $this->setProgress(98, 'Отправка email...');
            try {
                $mailer = new Mailer();
                $mailer->sendReport($this->email, $this->auditUuid, $this->url, $pdfPath);
            } catch (\Exception $e) {
                error_log("Email send failed: " . $e->getMessage());
            }

            // ── 7. Готово ─────────────────────────────────────────────────
            $this->setStatus('done');
            $this->setProgress(100, 'Аудит завершён!');
            Database::update('audits', ['completed_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $this->auditId]);

        } catch (\Exception $e) {
            Database::update('audits', [
                'status'        => 'error',
                'error_message' => $e->getMessage(),
            ], 'id = :id', [':id' => $this->auditId]);
            throw $e;
        }
    }

    // Ключи проблем из предыдущего аудита
    private function getPreviousIssueKeys(): array
    {
        if (!$this->previousAuditId) return [];
        $rows = Database::query(
            'SELECT issue_key FROM audit_issues WHERE audit_id = ?',
            [$this->previousAuditId]
        )->fetchAll();
        $keys = [];
        foreach ($rows as $r) $keys[$r['issue_key']] = true;
        return $keys;
    }

    // Считаем fixed / new / unchanged
    private function buildComparison(array $allIssues, array $prevKeys): array
    {
        if (!$this->previousAuditId || empty($prevKeys)) {
            return ['fixed_count' => 0, 'new_count' => 0, 'unchanged_count' => count($allIssues), 'has_prev' => false];
        }

        $currentKeys = [];
        $newCount    = 0;
        foreach ($allIssues as $issue) {
            $key = $issue['issue_key'] ?? md5(($issue['check_type'] ?? '') . '|' . ($issue['title'] ?? ''));
            $currentKeys[$key] = true;
            if ($issue['is_new']) $newCount++;
        }

        $fixedCount = 0;
        foreach ($prevKeys as $key => $_) {
            if (!isset($currentKeys[$key])) $fixedCount++;
        }

        $unchangedCount = count($allIssues) - $newCount;

        return [
            'has_prev'       => true,
            'fixed_count'    => $fixedCount,
            'new_count'      => $newCount,
            'unchanged_count' => $unchangedCount,
            'prev_audit_id'  => $this->previousAuditId,
        ];
    }

    private function calcScore(array $issues): int
    {
        return \SeoAuditor\Report\Score::overall($issues);
    }

    private function setProgress(int $progress, string $text): void
    {
        Database::update('audits', ['progress' => $progress, 'progress_text' => $text], 'id = :id', [':id' => $this->auditId]);
    }

    private function setStatus(string $status): void
    {
        Database::update('audits', ['status' => $status], 'id = :id', [':id' => $this->auditId]);
    }
}
