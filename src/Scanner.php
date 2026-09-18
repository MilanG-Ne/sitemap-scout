<?php

declare(strict_types=1);

namespace SitemapScout;

use InvalidArgumentException;
use RuntimeException;

final class Scanner
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly int $maxUrls = 100,
        private readonly int $maxSitemaps = 10,
        private readonly int $slowMs = 1000,
    ) {
    }

    public function scan(string $root): Report
    {
        $root = Url::normalize($root);
        $origin = Url::origin($root);
        $report = new Report();
        $queue = [$root];
        $queued = [$root => true];
        $urls = [];
        $pages = [];
        $halted = false;
        while ($queue !== []) {
            if ($report->sitemaps >= $this->maxSitemaps) {
                $report->complete = false;
                $report->add('warning', 'sitemap_limit', $root, 'Sitemap limit reached; some referenced sitemaps were not read.');
                break;
            }
            $url = array_shift($queue);
            $report->sitemaps++;
            $response = $this->sitemap($url, $origin, $report);
            if ($response->status === 429) {
                $halted = true;
                break;
            }
            if ($response->error !== null || $response->status < 200 || $response->status >= 300) {
                $report->complete = false;
                $report->add('error', 'sitemap_fetch', $url, $response->error ?? 'Sitemap returned HTTP ' . $response->status . '.');
                continue;
            }
            try {
                $document = (new SitemapParser())->parse($response->body);
            } catch (RuntimeException $error) {
                $report->complete = false;
                $report->add('error', 'sitemap_xml', $url, $error->getMessage());
                continue;
            }
            if ($document['entries'] === []) {
                $report->add('warning', 'empty_sitemap', $url, 'Sitemap contains no entries.');
            }
            foreach ($document['entries'] as $i => $entry) {
                try {
                    $target = Url::normalize($entry['loc'] ?? '');
                } catch (InvalidArgumentException) {
                    $report->complete = false;
                    $report->add('error', 'invalid_url', $url, 'Entry ' . ($i + 1) . ' needs exactly one absolute HTTP(S) loc without credentials or fragments.');
                    continue;
                }
                if (Url::origin($target) !== $origin) {
                    $report->complete = false;
                    $report->add('error', 'outside_origin', $target, 'URL is outside the starting origin and was not requested.');
                    continue;
                }
                if ($entry['lastmod'] !== null && !SitemapParser::validDate($entry['lastmod'])) {
                    $report->add('warning', 'invalid_lastmod', $target, 'lastmod is not a valid date or a date-time with a timezone.');
                }
                if ($document['index']) {
                    if (isset($queued[$target])) {
                        $report->add('warning', 'duplicate_sitemap', $target, 'Repeated or circular sitemap reference was skipped.');
                    } else {
                        $queued[$target] = true;
                        // Keep the pending queue bounded as well as the number of requests.
                        if (count($queue) + $report->sitemaps < $this->maxSitemaps) {
                            $queue[] = $target;
                        } else {
                            $report->complete = false;
                            if (!in_array('sitemap_limit', array_column($report->findings, 'code'), true)) {
                                $report->add('warning', 'sitemap_limit', $root, 'Sitemap limit reached; some referenced sitemaps were not read.');
                            }
                        }
                    }
                } elseif (isset($urls[$target])) {
                    $report->add('warning', 'duplicate_url', $target, 'URL appears more than once; only the first occurrence is checked.');
                } else {
                    $urls[$target] = true;
                    if (count($pages) < $this->maxUrls) {
                        $pages[] = $target;
                    }
                }
            }
        }
        $report->discovered = count($urls);
        if ($report->discovered > $this->maxUrls) {
            $report->complete = false;
            $report->add('warning', 'url_limit', $root, 'URL limit reached; only the first ' . $this->maxUrls . ' unique in-scope URLs are checked.');
        }
        if (!$halted) {
            foreach ($pages as $url) {
                $method = 'HEAD';
                $response = $this->request($url, $method, false, $report);
                $duration = $response->durationMs;
                if ($response->error === null && in_array($response->status, [405, 501], true)) {
                    $method = 'GET';
                    $response = $this->request($url, $method, false, $report);
                    $duration += $response->durationMs;
                }
                $report->pages[] = ['url' => Url::display($url), 'status' => $response->status, 'method' => $method, 'duration_ms' => $duration];
                if ($response->status === 429) {
                    $halted = true;
                    break;
                }
                if ($response->error !== null) {
                    $report->add('error', 'request_failed', $url, $response->error);
                } elseif (in_array($response->status, [301, 302, 303, 307, 308], true)) {
                    $message = 'HTTP ' . $response->status . '; list the final page URL in the sitemap.';
                    $report->add('warning', 'redirect', $url, $message);
                } elseif ($response->status < 200 || $response->status >= 300) {
                    $report->add('error', 'http_error', $url, 'Page returned HTTP ' . $response->status . '.');
                }
                if ($duration >= $this->slowMs) {
                    $report->add('warning', 'slow', $url, 'Probe took ' . $duration . ' ms (threshold ' . $this->slowMs . ' ms).');
                }
            }
        }
        if ($halted) {
            $report->complete = false;
            $report->add('error', 'rate_limited', $root, 'Server returned HTTP 429. Scan stopped without retrying.');
        }
        return $report;
    }

    private function request(string $url, string $method, bool $sitemap, Report $report): Response
    {
        $report->requests++;
        return $this->http->request($url, $method, $sitemap);
    }

    private function sitemap(string $url, string $origin, Report $report): Response
    {
        $seen = [];
        for ($hop = 0; $hop <= 3; $hop++) {
            if (isset($seen[$url])) {
                return new Response(0, '', null, 0, 'Sitemap redirect loop.');
            }
            $seen[$url] = true;
            $response = $this->request($url, 'GET', true, $report);
            if ($response->error !== null || !in_array($response->status, [301, 302, 303, 307, 308], true)) {
                return $response;
            }
            try {
                $target = Url::resolve($url, $response->location ?? '');
            } catch (InvalidArgumentException) {
                return new Response(0, '', null, 0, 'Sitemap redirect has an invalid or missing destination.');
            }
            if (Url::origin($target) !== $origin) {
                return new Response(0, '', null, 0, 'Sitemap redirect leaves the starting origin; destination was not requested.');
            }
            $report->add('warning', 'sitemap_redirect', $url, 'Sitemap redirects; use its final URL when possible.');
            $url = $target;
        }
        return new Response(0, '', null, 0, 'Sitemap exceeded the three-redirect limit.');
    }
}
