<?php

declare(strict_types=1);

namespace SitemapScout;

interface HttpClient
{
    public function request(string $url, string $method, bool $sitemap = false): Response;
}
