<?php

declare(strict_types=1);

namespace Magna\Seo\Scan\Checks;

use Magna\Seo\Contracts\ScanCheck;
use Magna\Seo\Scan\ScanIssue;
use Magna\Seo\Scan\ScannedSubject;
use Magna\Seo\Scan\Severity;

/**
 * Two indexable pages claiming the same canonical URL. Whichever one a crawler
 * picks, the other is dropped from the index — and because the choice is not the
 * site owner's, this fails silently and is invisible without a check like this.
 */
final class CanonicalConflictCheck implements ScanCheck
{
    public function run(array $subjects): array
    {
        /** @var array<string, list<ScannedSubject>> $byUrl */
        $byUrl = [];

        foreach ($subjects as $scanned) {
            if ($scanned->subject->url !== '') {
                $byUrl[$scanned->subject->url][] = $scanned;
            }
        }

        $issues = [];

        foreach ($byUrl as $url => $group) {
            if (count($group) < 2) {
                continue;
            }

            $others = count($group) - 1;

            foreach ($group as $scanned) {
                $issues[] = new ScanIssue(
                    source: $scanned->source,
                    subjectKey: $scanned->subject->key,
                    url: $url,
                    check: 'canonical-conflict',
                    severity: Severity::Error,
                    message: "{$others} other page(s) claim this same canonical URL; all but one will be dropped from the index.",
                );
            }
        }

        return $issues;
    }
}
