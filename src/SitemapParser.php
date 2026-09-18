<?php

declare(strict_types=1);

namespace SitemapScout;

use DOMDocument;
use DOMElement;
use RuntimeException;

final class SitemapParser
{
    public const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /** @return array{index: bool, entries: list<array{loc: ?string, lastmod: ?string}>} */
    public function parse(string $xml): array
    {
        if (str_starts_with($xml, "\x1f\x8b")) {
            $xml = @gzdecode($xml, CurlClient::MAX_SITEMAP_BYTES + 1);
            if ($xml === false) {
                throw new RuntimeException('Invalid or oversized gzip sitemap.');
            }
        }
        if (strlen($xml) > CurlClient::MAX_SITEMAP_BYTES) {
            throw new RuntimeException('Expanded sitemap exceeds the 2 MiB limit.');
        }
        if (trim($xml) === '') {
            throw new RuntimeException('Malformed sitemap XML.');
        }
        if (str_contains($xml, "\0") || preg_match('//u', $xml) !== 1 || preg_match('/<!DOCTYPE/i', $xml)) {
            throw new RuntimeException('Expected UTF-8 XML without a document type declaration.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                throw new RuntimeException('Malformed sitemap XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->documentElement;
        if ($root === null || $root->namespaceURI !== self::NS || !in_array($root->localName, ['urlset', 'sitemapindex'], true)) {
            throw new RuntimeException('Expected urlset or sitemapindex in the standard sitemap namespace.');
        }
        $index = $root->localName === 'sitemapindex';
        $entries = [];
        foreach ($root->childNodes as $node) {
            if (!$node instanceof DOMElement || $node->namespaceURI !== self::NS) {
                continue;
            }
            if ($node->localName !== ($index ? 'sitemap' : 'url')) {
                throw new RuntimeException('Unexpected element in the sitemap root.');
            }
            $locations = [];
            $dates = [];
            foreach ($node->childNodes as $child) {
                if (!$child instanceof DOMElement || $child->namespaceURI !== self::NS) {
                    continue;
                }
                if ($child->localName === 'loc') {
                    $locations[] = trim($child->textContent);
                } elseif ($child->localName === 'lastmod') {
                    $dates[] = trim($child->textContent);
                }
            }
            $entries[] = ['loc' => count($locations) === 1 ? $locations[0] : null, 'lastmod' => count($dates) > 1 ? '' : ($dates[0] ?? null)];
        }
        return ['index' => $index, 'entries' => $entries];
    }

    public static function validDate(string $value): bool
    {
        if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(Z|[+-](\d{2}):(\d{2})))?\z/', $value, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && (!isset($m[4]) || ((int) $m[4] < 24 && (int) $m[5] < 60 && (int) ($m[6] ?? 0) < 60 && (int) ($m[8] ?? 0) < 24 && (int) ($m[9] ?? 0) < 60));
    }
}
