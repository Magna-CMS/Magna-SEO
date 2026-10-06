<?php

declare(strict_types=1);

namespace Magna\Seo\Contracts;

use Magna\Seo\Schema\SchemaContext;

/**
 * Builds zero or more schema.org nodes for one aspect of a subject (the website,
 * the site identity, the page, the article, breadcrumbs, an image). Splitting a
 * factory per type keeps each one small and independently testable, and lets a
 * factory opt out (return []) when it does not apply to the current subject.
 */
interface SchemaNodeFactory
{
    /**
     * @return list<array<string, mixed>>
     */
    public function make(SchemaContext $context): array;
}
