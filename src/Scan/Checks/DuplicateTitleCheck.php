<?php

declare(strict_types=1);

namespace Magna\Seo\Scan\Checks;

use Magna\Seo\Subjects\SeoSubject;

/**
 * Flags pages that share a title — duplicate titles compete with each other in
 * search results and blur which page should rank.
 */
final class DuplicateTitleCheck extends DuplicateCheck
{
    protected function value(SeoSubject $subject): string
    {
        return $subject->title;
    }

    protected function check(): string
    {
        return 'duplicate-title';
    }

    protected function label(): string
    {
        return 'title';
    }
}
