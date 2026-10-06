<?php

declare(strict_types=1);

namespace Magna\Seo\Scan;

use Magna\Seo\Subjects\SeoSubject;

/**
 * A subject paired with the handle of the source it came from, so scan findings
 * can name which source (and which entry) an issue belongs to.
 */
final readonly class ScannedSubject
{
    public function __construct(
        public string $source,
        public SeoSubject $subject,
    ) {}
}
