<?php

declare(strict_types=1);

namespace SitemapScout;

final readonly class Response
{
    public function __construct(
        public int $status,
        public string $body,
        public ?string $location,
        public int $durationMs,
        public ?string $error = null,
    ) {
    }
}
