<?php

declare(strict_types=1);

namespace SitemapScout;

final class Report
{
    public bool $complete = true;
    public int $sitemaps = 0;
    public int $discovered = 0;
    public int $requests = 0;
    /** @var list<array{url: string, status: int, method: string, duration_ms: int}> */
    public array $pages = [];
    /** @var list<array{severity: string, code: string, url: string, message: string}> */
    public array $findings = [];

    public function add(string $severity, string $code, string $url, string $message): void
    {
        $this->findings[] = compact('severity', 'code') + ['url' => Url::display($url), 'message' => $message];
    }

    public function toArray(bool $strict): array
    {
        $errors = count(array_filter($this->findings, static fn (array $item): bool => $item['severity'] === 'error'));
        $warnings = count($this->findings) - $errors;
        $ok = $errors === 0 && $this->complete && (!$strict || $warnings === 0);
        return [
            'version' => Application::VERSION,
            'ok' => $ok,
            'complete' => $this->complete,
            'exit_code' => $ok ? 0 : 1,
            'summary' => ['sitemaps' => $this->sitemaps, 'urls_discovered' => $this->discovered, 'urls_checked' => count($this->pages), 'requests' => $this->requests, 'errors' => $errors, 'warnings' => $warnings],
            'pages' => $this->pages,
            'findings' => $this->findings,
        ];
    }
}
