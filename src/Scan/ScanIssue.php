<?php

declare(strict_types=1);

namespace Magna\Seo\Scan;

use Magna\Seo\Contracts\ScanCheck;

/**
 * One finding produced by a {@see ScanCheck}: what is wrong,
 * how serious, and which subject it concerns.
 */
final readonly class ScanIssue
{
    public function __construct(
        public string $source,
        public string $subjectKey,
        public ?string $url,
        public string $check,
        public Severity $severity,
        public string $message,
    ) {}
}
