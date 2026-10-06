<?php

declare(strict_types=1);

namespace Magna\Seo\Subjects;

/**
 * The author of a subject, used for article metadata and Person schema.
 */
final readonly class SeoAuthor
{
    public function __construct(
        public string $name,
        public ?string $url = null,
        public ?string $imageUrl = null,
    ) {}
}
