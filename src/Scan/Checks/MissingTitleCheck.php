<?php

declare(strict_types=1);

namespace Magna\Seo\Scan\Checks;

use Magna\Seo\Contracts\ScanCheck;
use Magna\Seo\Scan\ScanIssue;
use Magna\Seo\Scan\Severity;

/**
 * Flags indexable subjects with no title — the single most damaging on-page SEO
 * omission, so it is an error.
 */
final class MissingTitleCheck implements ScanCheck
{
    public function run(array $subjects): array
    {
        $issues = [];
        foreach ($subjects as $scanned) {
            if (trim($scanned->subject->title) === '') {
                $issues[] = new ScanIssue(
                    source: $scanned->source,
                    subjectKey: $scanned->subject->key,
                    url: $scanned->subject->url !== '' ? $scanned->subject->url : null,
                    check: 'missing-title',
                    severity: Severity::Error,
                    message: 'Page has no title.',
                );
            }
        }

        return $issues;
    }
}
