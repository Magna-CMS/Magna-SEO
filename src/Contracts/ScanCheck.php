<?php

declare(strict_types=1);

namespace Magna\Seo\Contracts;

use Magna\Seo\Scan\ScanIssue;
use Magna\Seo\Scan\ScannedSubject;

/**
 * One technical-SEO check run over the whole set of indexable subjects. A check
 * may look at each subject on its own (missing title, thin content) or across the
 * set (duplicate titles), and returns the issues it found. Splitting a class per
 * check keeps each one small and independently testable.
 */
interface ScanCheck
{
    /**
     * @param  list<ScannedSubject>  $subjects
     * @return list<ScanIssue>
     */
    public function run(array $subjects): array;
}
