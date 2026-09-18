<?php

declare(strict_types=1);

namespace SitemapScout;

use InvalidArgumentException;
use Throwable;

final class Application
{
    public const VERSION = '0.1.0';

    public function run(array $arguments): int
    {
        $json = in_array('--json', $arguments, true);
        try {
            if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
                echo $this->help();
                return 0;
            }
            if ($arguments === ['--version']) {
                echo 'Sitemap Scout ' . self::VERSION . PHP_EOL;
                return 0;
            }
            foreach (['curl', 'dom', 'filter', 'zlib'] as $extension) {
                if (!extension_loaded($extension)) {
                    throw new InvalidArgumentException('PHP extension required: ' . $extension . '.');
                }
            }
            [$url, $options, $strict] = $this->options($arguments);
            $http = new CurlClient($options['timeout'], $options['delay-ms']);
            $report = (new Scanner($http, $options['max-urls'], $options['max-sitemaps'], $options['slow-ms']))->scan($url)->toArray($strict);
            if ($json) {
                echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            } else {
                echo "Sitemap Scout — " . Url::display($url) . "\n\n";
                foreach ($report['pages'] as $page) {
                    echo sprintf("%3s  %-4s  %6d ms  %s\n", $page['status'] ?: 'ERR', $page['method'], $page['duration_ms'], $page['url']);
                }
                if ($report['findings'] !== []) {
                    echo "\nFindings\n";
                    foreach ($report['findings'] as $finding) {
                        echo strtoupper($finding['severity']) . ' [' . $finding['code'] . '] ' . $finding['url'] . "\n  " . $finding['message'] . "\n";
                    }
                }
                $s = $report['summary'];
                echo "\n" . ($report['ok'] ? 'PASS' : 'FAIL') . ($report['complete'] ? '' : ' (INCOMPLETE)') . " — {$s['urls_checked']}/{$s['urls_discovered']} URLs checked; {$s['errors']} errors, {$s['warnings']} warnings.\n";
            }
            return $report['exit_code'];
        } catch (InvalidArgumentException $error) {
            return $this->failure($error->getMessage(), $json);
        } catch (Throwable) {
            return $this->failure('Could not complete the scan.', $json);
        }
    }

    private function options(array $arguments): array
    {
        $options = ['max-urls' => 100, 'max-sitemaps' => 10, 'timeout' => 10, 'delay-ms' => 150, 'slow-ms' => 1000];
        $bounds = ['max-urls' => [1, 1000], 'max-sitemaps' => [1, 50], 'timeout' => [1, 60], 'delay-ms' => [0, 5000], 'slow-ms' => [1, 60000]];
        $urls = [];
        $strict = false;
        foreach ($arguments as $argument) {
            if ($argument === '--json') {
                continue;
            }
            if ($argument === '--strict') {
                $strict = true;
                continue;
            }
            if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
                [$name, $value] = explode('=', substr($argument, 2), 2);
                if (!isset($bounds[$name]) || !preg_match('/\A[0-9]{1,5}\z/', $value) || (int) $value < $bounds[$name][0] || (int) $value > $bounds[$name][1]) {
                    throw new InvalidArgumentException('Invalid option or numeric limit. Run with --help for supported ranges.');
                }
                $options[$name] = (int) $value;
            } elseif (str_starts_with($argument, '-')) {
                throw new InvalidArgumentException('Unknown option. Run with --help for usage.');
            } else {
                $urls[] = $argument;
            }
        }
        if (count($urls) !== 1) {
            throw new InvalidArgumentException('Provide exactly one sitemap URL. Run with --help for usage.');
        }
        return [Url::normalize($urls[0]), $options, $strict];
    }

    private function failure(string $message, bool $json): int
    {
        if ($json) {
            echo json_encode(['version' => self::VERSION, 'ok' => false, 'complete' => false, 'exit_code' => 2, 'error' => $message], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        } else {
            fwrite(STDERR, 'Sitemap Scout: ' . $message . PHP_EOL);
        }
        return 2;
    }

    private function help(): string
    {
        return <<<'HELP'
Sitemap Scout — check sitemap URLs before your visitors find the broken ones.

Usage: php bin/sitemap-scout https://example.com/sitemap.xml [options]

  --max-urls=100        Unique pages to check (1–1000)
  --max-sitemaps=10     Sitemap documents to fetch (1–50)
  --timeout=10         Timeout per request, seconds (1–60)
  --delay-ms=150        Delay between requests (0–5000)
  --slow-ms=1000        Warn at this probe duration, milliseconds (1–60000)
  --strict             Treat warnings as failures
  --json               Write one JSON report to stdout
  --help, -h           Show help
  --version            Show version

Checks stay on the starting scheme, host, and port. Page redirects are reported,
not followed. Sitemaps may redirect up to three times within the same origin.
Incomplete scans always fail. Query strings are redacted in reports.
Exit codes: 0 passed, 1 unhealthy or incomplete scan, 2 usage/runtime error.

HELP;
    }
}
