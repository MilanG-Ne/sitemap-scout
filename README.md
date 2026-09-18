# Sitemap Scout

[![CI](https://github.com/MilanG-Ne/sitemap-scout/actions/workflows/ci.yml/badge.svg)](https://github.com/MilanG-Ne/sitemap-scout/actions/workflows/ci.yml)
![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)
[![MIT license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Your sitemap says these pages exist. Do they?**

A small PHP command-line tool that reads a sitemap and checks its pages. Find broken links, redirects, slow responses, duplicate URLs, and invalid modification dates before submitting a sitemap or deploying a site.

No framework, database, API key, Composer install, or hosted account needed. Requires **PHP 8.3+ with cURL, DOM, filter, and zlib** (filter is enabled in standard PHP distributions).

```sh
php bin/sitemap-scout https://example.com/sitemap.xml
```

Example output, abbreviated from the included local demo; timings vary:

```text
Sitemap Scout — http://127.0.0.1:8787/sitemap.xml

200  HEAD       1 ms  http://127.0.0.1:8787/ok
301  HEAD       0 ms  http://127.0.0.1:8787/old-page
404  HEAD       1 ms  http://127.0.0.1:8787/missing
200  GET        1 ms  http://127.0.0.1:8787/head-blocked
200  HEAD     201 ms  http://127.0.0.1:8787/slow
200  HEAD       1 ms  http://127.0.0.1:8787/ok?[redacted]

Findings
WARNING [duplicate_url] http://127.0.0.1:8787/ok
  URL appears more than once; only the first occurrence is checked.
WARNING [redirect] http://127.0.0.1:8787/old-page
  HTTP 301; list the final page URL in the sitemap.
ERROR [http_error] http://127.0.0.1:8787/missing
  Page returned HTTP 404.
WARNING [slow] http://127.0.0.1:8787/slow
  Probe took 201 ms (threshold 100 ms).

FAIL — 6/6 URLs checked; 1 errors, 3 warnings.
```

## Try the demo

```sh
git clone https://github.com/MilanG-Ne/sitemap-scout.git
cd sitemap-scout
php -S 127.0.0.1:8787 examples/router.php
```

In another terminal, from the same folder:

```sh
# A clean sitemap. Includes a server that rejects HEAD but accepts GET.
php bin/sitemap-scout http://127.0.0.1:8787/healthy.xml --strict

# An index, a gzip child sitemap, duplicates, a redirect, a 404, and a slow page.
php bin/sitemap-scout http://127.0.0.1:8787/sitemap.xml --slow-ms=100
```

The second command deliberately exits with code `1`. Stop the local demo with Ctrl+C. The router is a development fixture; the checker itself needs no web server.

## Use it before deployment

```sh
php bin/sitemap-scout https://your-site.example/sitemap.xml --strict --json > report.json
```

Use your actual sitemap URL. `--strict` makes warnings fail the check as well as errors. `--json` emits one JSON report to stdout; text usage errors go to stderr. Reports are not saved unless you redirect output.

| Option | Default | Range / purpose |
| --- | --- | --- |
| `--max-urls` | `100` | 1–1000 unique pages |
| `--max-sitemaps` | `10` | 1–50 sitemap documents, including the starting one |
| `--timeout` | `10` | 1–60 seconds per HTTP request |
| `--delay-ms` | `150` | 0–5000 ms between requests |
| `--slow-ms` | `1000` | 1–60000 ms probe-duration warning threshold |
| `--strict` | off | Fail on warnings |
| `--json` | off | Machine-readable output |

Numeric options use `=`, for example `--max-urls=250`. `--help` and `--version` are available too.

| Exit | Meaning |
| --- | --- |
| `0` | All selected work completed, with no errors; warnings allowed unless strict |
| `1` | Health errors, strict-mode warnings, or an incomplete scan |
| `2` | Invalid input, missing PHP extensions, or an unexpected runtime failure |

**A limited scan never receives a clean pass.** When a URL/sitemap limit is reached, or a sitemap cannot be read, `complete` is false and the exit code is `1`. `urls_discovered` counts unique in-scope page URLs from documents actually read; it is not an estimate of unread sitemaps.

## What gets checked

- Namespaced XML `urlset` and `sitemapindex` documents, including nested indexes and `.gz` payloads. Cycles and duplicate sitemap references are skipped and reported.
- Required absolute HTTP(S) `loc` values, duplicate page URLs, and optional `lastmod` date/date-time syntax, including calendar validity. Image/video extension URLs are not mistaken for pages.
- HTTP status and transport failures. A page probe starts with HEAD and falls back to a bounded GET only for `405` or `501`. `403` is reported as an access failure.
- Page redirects (`301`, `302`, `303`, `307`, `308`) are warnings. Their destinations are not followed or checked. Other non-2xx statuses are errors.
- Slow probes are warnings. Timing includes both requests when GET fallback is needed; it excludes deliberate pacing delays. This is response timing, not browser rendering or Core Web Vitals.

## Request behavior and boundaries

The starting URL defines the allowed **scheme, host, and port**. Every child sitemap and page must share that origin. Cross-origin entries are reported without requesting them. A sitemap may redirect up to three hops within the same origin. Start with the canonical HTTPS sitemap URL if the HTTP URL redirects to HTTPS.

Requests are sequential, with a delay between them and no automatic retries. A `429` response stops the scan immediately. TLS certificate verification stays enabled. No cookies, authentication, custom headers, or proxy environment settings are used. Local/internal origins work when explicitly supplied as the starting URL; this is a local CLI, not a public URL-scanning service.

Each sitemap download and expanded gzip payload is capped at **2 MiB**, deliberately below the protocol maximum. Page fallback reads stop at **64 KiB**, discard the content, and use the received HTTP status. The tool does not follow links found in HTML, read `robots.txt`, execute JavaScript, check canonical/noindex tags, detect soft 404s, validate credentials, or prove search-engine indexability. It is intended for checks on sites you operate. It sends HTTP requests to that site and consumes its normal request/bandwidth resources; no paid API service is involved.

XML uses the [sitemap protocol namespace](https://www.sitemaps.org/protocol.html). DTDs are rejected and external entity resolution is disabled. This checks the documented subset, not the entire XSD, sitemap path-placement rules, or every optional metadata field. Dates are checked for syntax and calendar validity, not whether the content really changed then. URLs must use ASCII hostnames (punycode for IDNs) and percent-encoded non-ASCII characters; credential-bearing and fragment URLs are rejected.

Query strings remain part of request and duplicate identity, but are replaced by `?[redacted]` in **all reports**. Different query variants can therefore have the same display URL. Hostnames and paths remain visible and can themselves contain private information; inspect a report before sharing it. Bodies, raw XML parser errors, and raw transport errors are not included. No scan data leaves the CLI except requests to the chosen origin and its usual DNS resolution.

## JSON contract

A scan report contains `version`, `ok`, `complete`, `exit_code`, `summary`, `pages`, and `findings`. Each page includes its redacted `url`, numeric `status` (`0` if none was received), `method`, and `duration_ms`. A finding has `severity`, `code`, `url`, and `message`.

Finding codes: `sitemap_limit`, `url_limit`, `sitemap_fetch`, `sitemap_xml`, `empty_sitemap`, `invalid_url`, `outside_origin`, `invalid_lastmod`, `duplicate_sitemap`, `duplicate_url`, `request_failed`, `redirect`, `http_error`, `slow`, `rate_limited`, and `sitemap_redirect`.

With exit `2`, JSON has `version`, `ok: false`, `complete: false`, `exit_code: 2`, and a safe `error` string. Integrations should branch on codes rather than message wording. Help/version are plain text. Missing maps and failed requests are scan findings (exit `1`), not usage errors.

## Development

```sh
php tests/run.php
```

The native-PHP test runner covers pure parsing and scan decisions with a fake HTTP client, then starts an ephemeral local server to exercise actual cURL requests and CLI subprocesses. It checks gzip limits, redirects, namespace handling, XML entity rejection, query redaction, rate-limit stopping, HEAD fallback, timeouts, and incomplete scans. No public-site traffic is needed. CI also validates Composer metadata and PHP syntax.

`HttpClient` is the only I/O seam in the scanner. `CurlClient` owns TLS, timeouts, pacing, and transfer limits; `SitemapParser` handles bounded XML; `Scanner` owns traversal and health decisions; `Application` handles arguments and output. No third-party PHP packages are required. Composer metadata is provided but installation is optional.

Issues and focused pull requests are welcome. Include a small fictional sitemap or local fixture for regression cases. License: [MIT](LICENSE).
