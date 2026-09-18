<?php

declare(strict_types=1);

namespace SitemapScout;

final class CurlClient implements HttpClient
{
    public const MAX_SITEMAP_BYTES = 2_097_152;
    private bool $started = false;

    public function __construct(private readonly int $timeoutSeconds = 10, private readonly int $delayMs = 150)
    {
    }

    public function request(string $url, string $method, bool $sitemap = false): Response
    {
        if ($this->started) {
            usleep($this->delayMs * 1000);
        }
        $this->started = true;
        $handle = curl_init($url);
        $body = '';
        $received = 0;
        $headerBytes = 0;
        $location = null;
        $limited = false;
        $limit = $sitemap ? self::MAX_SITEMAP_BYTES : 65_536;
        curl_setopt_array($handle, [
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'SitemapScout/0.1 (+https://github.com/MilanG-Ne/sitemap-scout)',
            CURLOPT_HTTPHEADER => ['Accept: ' . ($sitemap ? 'application/xml,text/xml,*/*;q=0.1' : '*/*')],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location, &$headerBytes): int {
                $headerBytes += strlen($line);
                if ($headerBytes > 65_536) {
                    return 0;
                }
                if (str_starts_with($line, 'HTTP/')) {
                    $location = null;
                } elseif (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$received, &$limited, $limit, $sitemap): int {
                $received += strlen($chunk);
                if ($received > $limit) {
                    $limited = true;
                    return 0;
                }
                if ($sitemap) {
                    $body .= $chunk;
                }
                return strlen($chunk);
            },
        ]);
        $success = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $duration = (int) round(curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000);
        $errno = curl_errno($handle);
        unset($handle);
        $error = null;
        if ($limited && $sitemap) {
            $error = 'Sitemap exceeds the 2 MiB download limit.';
        } elseif ($success === false && !($limited && !$sitemap && $status > 0)) {
            // Raw transport errors can contain URLs, addresses or certificate details.
            $error = $errno === CURLE_OPERATION_TIMEDOUT ? 'Request timed out.' : 'HTTP transport failed (cURL ' . $errno . ').';
        }
        return new Response($status, $body, $location, $duration, $error);
    }
}
