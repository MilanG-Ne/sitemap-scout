# Changelog

## 0.1.0 — 2026-09-18

First release.

- Read XML sitemaps and sitemap indexes, including bounded gzip payloads.
- Check pages with HEAD and a bounded GET fallback for servers returning 405/501.
- Report broken links, redirects, slow probes, duplicate URLs and invalid lastmod dates.
- Keep requests on the starting origin, pace requests, stop on 429, and fail incomplete scans.
- Produce terminal or JSON reports with query strings redacted.
- Include a local demo server and 60 tests, verified on PHP 8.3–8.5 on Linux and PHP 8.4 on Windows.
