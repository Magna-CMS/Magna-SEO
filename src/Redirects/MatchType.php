<?php

declare(strict_types=1);

namespace Magna\Seo\Redirects;

/**
 * How a redirect rule's source is compared against an incoming path.
 */
enum MatchType: string
{
    /** Whole-path equality against the normalised request path. */
    case Exact = 'exact';

    /**
     * A regular expression over the normalised path, with capture groups usable
     * in the target as $1, $2, … Patterns are author-supplied, so the store
     * compiles them defensively and skips any that do not compile.
     */
    case Regex = 'regex';
}
