<?php

declare(strict_types=1);

namespace Magna\Seo\Subjects;

/**
 * One node in a subject's breadcrumb trail, ordered root-first. The last crumb
 * is the current page and conventionally carries no url.
 */
final readonly class SeoCrumb
{
    public function __construct(
        public string $label,
        public ?string $url = null,
    ) {}
}
