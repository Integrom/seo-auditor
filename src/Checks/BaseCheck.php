<?php
namespace SeoAuditor\Checks;

abstract class BaseCheck
{
    protected array $issues = [];

    abstract public function run(array $pages, array &$siteData): array;

    protected function addIssue(
        string $severity,
        string $checkType,
        string $title,
        string $description,
        string $recommendation,
        string $url = '',
        ?int   $pageId = null
    ): void {
        $this->issues[] = [
            'check_type'     => $checkType,
            'severity'       => $severity,
            'title'          => $title,
            'description'    => $description,
            'recommendation' => $recommendation,
            'url'            => $url,
            'page_id'        => $pageId,
        ];
    }

    protected function critical(string $type, string $title, string $desc, string $rec, string $url = '', ?int $pid = null): void
    {
        $this->addIssue('critical', $type, $title, $desc, $rec, $url, $pid);
    }

    protected function warning(string $type, string $title, string $desc, string $rec, string $url = '', ?int $pid = null): void
    {
        $this->addIssue('warning', $type, $title, $desc, $rec, $url, $pid);
    }

    protected function info(string $type, string $title, string $desc, string $rec, string $url = '', ?int $pid = null): void
    {
        $this->addIssue('info', $type, $title, $desc, $rec, $url, $pid);
    }

    protected function dom(string $html, string $baseUrl = ''): \Symfony\Component\DomCrawler\Crawler
    {
        return new \Symfony\Component\DomCrawler\Crawler($html, $baseUrl);
    }
}
