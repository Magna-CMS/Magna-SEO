<?php

declare(strict_types=1);

namespace Magna\Seo\Scan\Checks;

use Magna\Seo\Subjects\SeoSubject;

/**
 * Flags pages that share an excerpt/description. Only the author-set excerpt is
 * compared; a subject with none is skipped (a missing description is the
 * MissingDescriptionCheck's concern, not this one).
 */
final class DuplicateDescriptionCheck extends DuplicateCheck
{
    protected function value(SeoSubject $subject): ?string
    {
        return $subject->excerpt;
    }

    protected function check(): string
    {
        return 'duplicate-description';
    }

    protected function label(): string
    {
        return 'description';
    }
}
