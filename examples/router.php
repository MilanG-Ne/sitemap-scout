<?php

declare(strict_types=1);

// Local demo only: php -S 127.0.0.1:8787 examples/router.php
$base = 'http://' . $_SERVER['HTTP_HOST'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = getenv('SCOUT_TEST_LOG');
if ($log !== false) {
    file_put_contents($log, $_SERVER['REQUEST_METHOD'] . ' ' . $path . "\n", FILE_APPEND);
}
function sitemap(string $base, array $paths, bool $index = false): string
{
    $root = $index ? 'sitemapindex' : 'urlset';
    $entry = $index ? 'sitemap' : 'url';
    $xml = '<?xml version="1.0" encoding="UTF-8"?><' . $root . ' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($paths as $path) {
        $xml .= '<' . $entry . '><loc>' . htmlspecialchars($base . $path, ENT_XML1) . '</loc></' . $entry . '>';
    }
    return $xml . '</' . $root . '>';
}
header('Content-Type: application/xml; charset=utf-8');
switch ($path) {
    case '/sitemap.xml':
        echo sitemap($base, ['/pages.xml', '/extra.xml.gz'], true);
        break;
    case '/pages.xml':
        echo sitemap($base, ['/ok', '/old-page', '/missing', '/head-blocked', '/slow']);
        break;
    case '/extra.xml.gz':
        header('Content-Type: application/gzip');
        echo gzencode(sitemap($base, ['/ok', '/ok?token=fictional-demo-token']));
        break;
    case '/healthy.xml':
        echo sitemap($base, ['/ok', '/head-blocked']);
        break;
    case '/rate.xml':
        echo sitemap($base, ['/rate-limit', '/never-requested']);
        break;
    case '/redirect.xml':
        header('Location: healthy.xml', true, 302);
        break;
    case '/external.xml':
        header('Location: http://localhost:1/not-requested', true, 302);
        break;
    case '/bad.xml':
        echo '<urlset><secret-fragment>';
        break;
    case '/large.xml':
        echo str_repeat('x', 2_097_153);
        break;
    case '/encoded.xml':
        header('Content-Encoding: gzip');
        echo gzencode(sitemap($base, ['/ok']));
        break;
    case '/head-blocked':
        if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
            http_response_code(405);
        } else {
            header('Content-Type: text/plain');
            echo str_repeat('A', 100_000);
        }
        break;
    case '/old-page':
        header('Location: /redirect-destination', true, 301);
        break;
    case '/missing':
        http_response_code(404);
        break;
    case '/rate-limit':
        header('Retry-After: 60');
        http_response_code(429);
        break;
    case '/slow':
        usleep(200_000);
        echo 'Slow demo page';
        break;
    case '/timeout':
        usleep(1_300_000);
        break;
    default:
        header('Content-Type: text/plain');
        echo 'Sitemap Scout demo. Start with /sitemap.xml or /healthy.xml.';
}
