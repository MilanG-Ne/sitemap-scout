<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SitemapScout\CurlClient;
use SitemapScout\HttpClient;
use SitemapScout\Response;
use SitemapScout\Scanner;
use SitemapScout\SitemapParser;
use SitemapScout\Url;

$tests = [];
function test(string $name, Closure $body): void
{
    global $tests;
    $tests[$name] = $body;
}
function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function rejects(Closure $body): void
{
    try {
        $body();
    } catch (InvalidArgumentException | RuntimeException) {
        return;
    }
    throw new RuntimeException('Expected an exception.');
}
function xml(array $urls, bool $index = false): string
{
    $root = $index ? 'sitemapindex' : 'urlset';
    $entry = $index ? 'sitemap' : 'url';
    $result = '<' . $root . ' xmlns="' . SitemapParser::NS . '">';
    foreach ($urls as $url) {
        $result .= '<' . $entry . '><loc>' . htmlspecialchars($url, ENT_XML1) . '</loc></' . $entry . '>';
    }
    return $result . '</' . $root . '>';
}
final class FakeHttp implements HttpClient
{
    public array $calls = [];
    public function __construct(private array $routes)
    {
    }
    public function request(string $url, string $method, bool $sitemap = false): Response
    {
        $this->calls[] = [$url, $method, $sitemap];
        return $this->routes[$method . ' ' . $url] ?? throw new RuntimeException('Unexpected request: ' . $method . ' ' . $url);
    }
}
function response(int $status = 200, string $body = '', ?string $location = null, int $ms = 10, ?string $error = null): Response
{
    return new Response($status, $body, $location, $ms, $error);
}
function cli(array $arguments): array
{
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/sitemap-scout', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out, $err];
}

test('normalization, origin, and query redaction', function (): void {
    same('https://example.test/', Url::normalize('HTTPS://EXAMPLE.TEST:443'));
    same('http://example.test:80', Url::origin('http://example.test/path?q=x'));
    same('https://example.test/path?[redacted]', Url::display('https://example.test/path?token=private&x=1'));
});
foreach (['file:///etc/passwd', 'ftp://example.test/a', 'https://user:pass@example.test/', 'https://example.test/#fragment', 'https://example.test/bad path', 'https://example.test/%zz', 'https://example.test/\\evil', '/relative', "https://example.test/\x1b[31m", 'http://example.test:0'] as $url) {
    test('reject unsafe or ambiguous URL ' . json_encode($url), function () use ($url): void {
        rejects(fn () => Url::normalize($url));
    });
}
foreach (['../b.xml' => 'https://example.test/b.xml', '/b.xml' => 'https://example.test/b.xml', '//example.test/b.xml' => 'https://example.test/b.xml', '?v=2' => 'https://example.test/a/map.xml?v=2', './b.xml' => 'https://example.test/a/b.xml', '../../b.xml' => 'https://example.test/b.xml'] as $relative => $expected) {
    test('resolve redirect ' . $relative, fn () => same($expected, Url::resolve('https://example.test/a/map.xml', $relative)));
}

test('parse URLs, entities, prefixed namespace and ignore image loc', function (): void {
    $input = '<s:urlset xmlns:s="' . SitemapParser::NS . '" xmlns:image="urn:image"><s:url><s:loc>https://example.test/?a=1&amp;b=2</s:loc><image:loc>https://ignored.test/</image:loc><s:lastmod>2026-09-18</s:lastmod></s:url></s:urlset>';
    $parsed = (new SitemapParser())->parse($input);
    same(false, $parsed['index']);
    same([['loc' => 'https://example.test/?a=1&b=2', 'lastmod' => '2026-09-18']], $parsed['entries']);
});
test('gzip parser and index detection', function (): void {
    same(true, (new SitemapParser())->parse(gzencode(xml(['https://example.test/pages.xml'], true)))['index']);
});
foreach (['', '<urlset>', '<urlset/>', '<!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/passwd">]><urlset/>', '<!DOCTYPE x SYSTEM "https://example.test/entity"><urlset/>', "\xff\xfe<urlset/>", "<urlset>\0</urlset>", '<other xmlns="' . SitemapParser::NS . '"/>'] as $i => $input) {
    test('reject invalid XML ' . $i, fn () => rejects(fn () => (new SitemapParser())->parse($input)));
}
test('gzip expansion is bounded', function (): void {
    rejects(fn () => (new SitemapParser())->parse(gzencode(str_repeat('x', CurlClient::MAX_SITEMAP_BYTES + 10))));
});
foreach (['2024-02-29', '2026-09-18T12:45Z', '2026-09-18T12:45:30+02:00', '2026-09-18T12:45:30.123Z'] as $date) {
    test('valid date ' . $date, fn () => same(true, SitemapParser::validDate($date)));
}
foreach (['2025-02-29', '2026-13-01', '2026-09-18T25:00:00Z', '2026-09-18T12:45:00', '2026-09-18T12:45:00+99:00', '', 'yesterday'] as $date) {
    test('invalid date ' . $date, fn () => same(false, SitemapParser::validDate($date)));
}

test('healthy scan performs one GET and one HEAD', function (): void {
    $http = new FakeHttp(['GET https://example.test/sitemap.xml' => response(body: xml(['https://example.test/ok'])), 'HEAD https://example.test/ok' => response()]);
    $report = (new Scanner($http))->scan('https://example.test/sitemap.xml')->toArray(false);
    same(true, $report['ok']);
    same(2, $report['summary']['requests']);
    same(1, $report['summary']['urls_checked']);
});
test('duplicates warn and strict mode fails', function (): void {
    $http = new FakeHttp(['GET https://example.test/map' => response(body: xml(['https://example.test/a', 'https://example.test/a'])), 'HEAD https://example.test/a' => response()]);
    $report = (new Scanner($http))->scan('https://example.test/map');
    same(['duplicate_url'], array_column($report->findings, 'code'));
    same(true, $report->toArray(false)['ok']);
    same(false, $report->toArray(true)['ok']);
    same(2, count($http->calls));
});
test('page redirects are reported without following destinations', function (): void {
    $http = new FakeHttp(['GET https://example.test/map' => response(body: xml(['https://example.test/a'])), 'HEAD https://example.test/a' => response(301, location: 'http://127.0.0.1/private')]);
    $report = (new Scanner($http))->scan('https://example.test/map');
    same(['redirect'], array_column($report->findings, 'code'));
    same(2, count($http->calls));
});
test('405 and 501 fall back to GET and retain total probe time', function (): void {
    foreach ([405, 501] as $status) {
        $http = new FakeHttp(['GET https://example.test/map' => response(body: xml(['https://example.test/a'])), 'HEAD https://example.test/a' => response($status, ms: 20), 'GET https://example.test/a' => response(ms: 30)]);
        $report = (new Scanner($http, slowMs: 50))->scan('https://example.test/map');
        same('GET', $report->pages[0]['method']);
        same(50, $report->pages[0]['duration_ms']);
        same(['slow'], array_column($report->findings, 'code'));
    }
});
test('403 does not bypass access denial with a second request', function (): void {
    $http = new FakeHttp(['GET https://example.test/map' => response(body: xml(['https://example.test/a'])), 'HEAD https://example.test/a' => response(403)]);
    same(['http_error'], array_column((new Scanner($http))->scan('https://example.test/map')->findings, 'code'));
    same(2, count($http->calls));
});
test('transport failure is an error even with a 200 status', function (): void {
    $http = new FakeHttp(['GET https://example.test/map' => response(body: xml(['https://example.test/a'])), 'HEAD https://example.test/a' => response(error: 'Request timed out.')]);
    same(false, (new Scanner($http))->scan('https://example.test/map')->toArray(false)['ok']);
});
test('304 is not a successful response or a redirect', function (): void {
    $http = new FakeHttp(['GET https://example.test/map' => response(body: xml(['https://example.test/a'])), 'HEAD https://example.test/a' => response(304)]);
    same(['http_error'], array_column((new Scanner($http))->scan('https://example.test/map')->findings, 'code'));
});
test('URL limits never pass as a complete scan', function (): void {
    $http = new FakeHttp(['GET https://example.test/map' => response(body: xml(['https://example.test/a', 'https://example.test/b'])), 'HEAD https://example.test/a' => response()]);
    $report = (new Scanner($http, maxUrls: 1))->scan('https://example.test/map')->toArray(false);
    same(false, $report['ok']);
    same(false, $report['complete']);
    same(2, $report['summary']['urls_discovered']);
    same(1, $report['summary']['urls_checked']);
});
test('sitemap limit bounds the queue and fails incomplete', function (): void {
    $http = new FakeHttp(['GET https://example.test/map' => response(body: xml(['https://example.test/a', 'https://example.test/b'], true))]);
    $report = (new Scanner($http, maxSitemaps: 1))->scan('https://example.test/map')->toArray(false);
    same(false, $report['complete']);
    same(['sitemap_limit'], array_column($report['findings'], 'code'));
    same(1, count($http->calls));
});
test('nested indexes terminate on circular references', function (): void {
    $http = new FakeHttp(['GET https://example.test/map' => response(body: xml(['https://example.test/nested'], true)), 'GET https://example.test/nested' => response(body: xml(['https://example.test/map', 'https://example.test/pages'], true)), 'GET https://example.test/pages' => response(body: xml(['https://example.test/a'])), 'HEAD https://example.test/a' => response()]);
    $report = (new Scanner($http))->scan('https://example.test/map');
    same(3, $report->sitemaps);
    same(['duplicate_sitemap'], array_column($report->findings, 'code'));
});
test('cross-origin entries are not requested', function (): void {
    $http = new FakeHttp(['GET https://example.test/map' => response(body: xml(['https://other.test/a', 'http://example.test/a', 'https://example.test:444/a']))]);
    $report = (new Scanner($http))->scan('https://example.test/map');
    same(3, count($report->findings));
    same(false, $report->complete);
    same(1, count($http->calls));
});
test('sitemap redirects stay in scope and loops stop', function (): void {
    foreach (['https://other.test/a', '/map'] as $location) {
        $http = new FakeHttp(['GET https://example.test/map' => response(302, location: $location)]);
        $report = (new Scanner($http))->scan('https://example.test/map');
        same(false, $report->complete);
        same(1, count($http->calls));
    }
});
test('rate limits halt discovery and page probes', function (): void {
    $http = new FakeHttp(['GET https://example.test/map' => response(429)]);
    same(['rate_limited'], array_column((new Scanner($http))->scan('https://example.test/map')->findings, 'code'));
    $http = new FakeHttp(['GET https://example.test/map' => response(body: xml(['https://example.test/a', 'https://example.test/b'])), 'HEAD https://example.test/a' => response(429)]);
    same(false, (new Scanner($http))->scan('https://example.test/map')->complete);
    same(2, count($http->calls));
});
test('malformed and missing loc entries cannot produce a pass', function (): void {
    $http = new FakeHttp(['GET https://example.test/map' => response(body: '<urlset xmlns="' . SitemapParser::NS . '"><url/><url><loc>relative</loc></url></urlset>')]);
    same(['invalid_url', 'invalid_url'], array_column((new Scanner($http))->scan('https://example.test/map')->findings, 'code'));
});
test('reports redact signed query strings everywhere', function (): void {
    $http = new FakeHttp(['GET https://example.test/map?token=PRIVATE' => response(body: xml(['https://example.test/a?token=PRIVATE'])), 'HEAD https://example.test/a?token=PRIVATE' => response(404)]);
    same(false, str_contains(json_encode((new Scanner($http))->scan('https://example.test/map?token=PRIVATE')->toArray(false)), 'PRIVATE'));
});
test('CLI argument errors are JSON and never reflect supplied secrets', function (): void {
    foreach ([[], ['--unknown=SECRET'], ['https://user:SECRET@example.test/'], ['https://example.test/map', '--max-urls=0']] as $args) {
        [$code, $out, $err] = cli([...$args, '--json']);
        same(2, $code);
        same('', $err);
        same(2, json_decode($out, true, flags: JSON_THROW_ON_ERROR)['exit_code']);
        same(false, str_contains($out, 'SECRET'));
    }
});

// Exercise the real cURL adapter and CLI against a local, deterministic HTTP server.
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
    throw new RuntimeException('Could not allocate a local fixture port.');
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$log = tempnam(sys_get_temp_dir(), 'scout-requests-');
$serverLog = tempnam(sys_get_temp_dir(), 'scout-server-');
$env = getenv();
$env['SCOUT_TEST_LOG'] = $log;
$server = proc_open([PHP_BINARY, '-S', $address, dirname(__DIR__) . '/examples/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']], $pipes, dirname(__DIR__), $env);
fclose($pipes[0]);
$base = 'http://' . $address;
try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = (new CurlClient(1, 0))->request($base . '/ready', 'HEAD');
        if ($probe->status === 200) {
            $ready = true;
            break;
        }
        usleep(20_000);
    }
    if (!$ready) {
        throw new RuntimeException('Local fixture server did not start.');
    }
    test('real HTTP: healthy CLI and bounded GET fallback', function () use ($base, $log): void {
        file_put_contents($log, '');
        [$code, $out, $err] = cli([$base . '/healthy.xml', '--delay-ms=0', '--json', '--strict']);
        same(0, $code);
        same('', $err);
        $report = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        same(true, $report['ok']);
        same(['HEAD', 'GET'], array_column($report['pages'], 'method'));
        same(true, str_contains(file_get_contents($log), "HEAD /head-blocked\nGET /head-blocked"));
    });
    test('real HTTP: nested gzip demo exposes expected problems', function () use ($base, $log): void {
        file_put_contents($log, '');
        [$code, $out, $err] = cli([$base . '/sitemap.xml', '--delay-ms=0', '--slow-ms=100', '--json']);
        same(1, $code);
        same('', $err);
        $report = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        same(3, $report['summary']['sitemaps']);
        same(6, $report['summary']['urls_checked']);
        $codes = array_column($report['findings'], 'code');
        foreach (['duplicate_url', 'redirect', 'http_error', 'slow'] as $expected) {
            same(true, in_array($expected, $codes, true));
        }
        same(false, str_contains($out, 'fictional-demo-token'));
        same(false, str_contains(file_get_contents($log), '/redirect-destination'));
    });
    test('real HTTP: 429 stops before the next page', function () use ($base, $log): void {
        file_put_contents($log, '');
        $report = (new Scanner(new CurlClient(1, 0)))->scan($base . '/rate.xml');
        same(false, $report->complete);
        same(false, str_contains(file_get_contents($log), 'never-requested'));
    });
    test('real HTTP: sitemap redirects, compression and errors', function () use ($base): void {
        $scanner = new Scanner(new CurlClient(1, 0));
        same(true, $scanner->scan($base . '/redirect.xml')->complete);
        same(false, $scanner->scan($base . '/external.xml')->complete);
        same(false, $scanner->scan($base . '/bad.xml')->complete);
        same(false, $scanner->scan($base . '/large.xml')->complete);
        same(true, $scanner->scan($base . '/encoded.xml')->toArray(false)['ok']);
    });
    test('real HTTP: timeout produces a transport error', function () use ($base): void {
        same('Request timed out.', (new CurlClient(1, 0))->request($base . '/timeout', 'HEAD')->error);
    });
    $failed = 0;
    foreach ($tests as $name => $body) {
        try {
            $body();
            echo '.';
        } catch (Throwable $error) {
            $failed++;
            fwrite(STDERR, "\nFAIL {$name}: {$error->getMessage()}\n");
        }
    }
    echo PHP_EOL . count($tests) . " tests, {$failed} failed.\n";
} finally {
    proc_terminate($server);
    proc_close($server);
    unlink($log);
    unlink($serverLog);
}
exit($failed === 0 ? 0 : 1);
