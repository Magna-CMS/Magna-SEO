<?php

declare(strict_types=1);

namespace Magna\Seo\Scan\Checks;

use Magna\Seo\Contracts\ScanCheck;
use Magna\Seo\Scan\ScanIssue;
use Magna\Seo\Scan\Severity;

/**
 * Flags subjects from which no meta description can be derived — neither an
 * excerpt nor body text — so search engines are left to invent a snippet.
 */
final class MissingDescriptionCheck implements ScanCheck
{
    public function run(array $subjects): array
    {
        $issues = [];
        foreach ($subjects as $scanned) {
            $subject = $scanned->subject;
            $hasExcerpt = $subject->excerpt !== null && trim($subject->excerpt) !== '';
            $hasBody = trim($subject->plainText) !== '';

            if (! $hasExcerpt && ! $hasBody) {
                $issues[] = new ScanIssue(
                    source: $scanned->source,
                    subjectKey: $subject->key,
                    url: $subject->url !== '' ? $subject->url : null,
                    check: 'missing-description',
                    severity: Severity::Warning,
                    message: 'Page has no description and no body text to derive one from.',
                );
            }
        }

        return $issues;
    }
}
