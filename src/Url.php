<?php

declare(strict_types=1);

namespace SitemapScout;

use InvalidArgumentException;

final class Url
{
    public static function normalize(string $url): string
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f-\xff\\\\]/', $url) || preg_match('/%(?![0-9a-f]{2})/i', $url)) {
            throw new InvalidArgumentException('Expected an absolute, encoded HTTP(S) URL without credentials or fragments.');
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Expected an absolute, encoded HTTP(S) URL without credentials or fragments.');
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port < 1) {
            throw new InvalidArgumentException('URL port must be between 1 and 65535.');
        }
        $authority = strtolower($parts['host']);
        if ($port !== ($scheme === 'https' ? 443 : 80)) {
            $authority .= ':' . $port;
        }
        return $scheme . '://' . $authority . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    public static function origin(string $url): string
    {
        $parts = parse_url(self::normalize($url));
        return $parts['scheme'] . '://' . $parts['host'] . ':' . ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
    }

    public static function display(string $url): string
    {
        // Query strings can contain signed tokens. Never include them in a report.
        return explode('?', $url, 2)[0] . (str_contains($url, '?') ? '?[redacted]' : '');
    }

    public static function resolve(string $base, string $location): string
    {
        if (preg_match('/\A[a-z][a-z0-9+.-]*:/i', $location)) {
            return self::normalize($location);
        }
        $parts = parse_url($base);
        if (str_starts_with($location, '//')) {
            return self::normalize($parts['scheme'] . ':' . $location);
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '?')) {
            return self::normalize($origin . ($parts['path'] ?? '/') . $location);
        }
        if ($location === '') {
            throw new InvalidArgumentException('Redirect has no destination.');
        }
        $relative = explode('?', $location, 2);
        $path = str_starts_with($location, '/') ? $relative[0] : substr($parts['path'] ?? '/', 0, strrpos($parts['path'] ?? '/', '/') + 1) . $relative[0];
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }
        if (str_ends_with($path, '/.') || str_ends_with($path, '/..')) {
            $segments[] = '';
        }
        return self::normalize($origin . '/' . ltrim(implode('/', $segments), '/') . (isset($relative[1]) ? '?' . $relative[1] : ''));
    }
}
