<?php

declare(strict_types=1);

namespace Magna\Seo\Scan\Checks;

use Magna\Seo\Contracts\ScanCheck;
use Magna\Seo\Scan\ScanIssue;
use Magna\Seo\Scan\Severity;

/**
 * Flags subjects whose body is below a word-count threshold. Thin pages rarely
 * rank and can dilute a site's quality signal. The threshold is configurable,
 * since a short doc page can be legitimately terse.
 */
final class ThinContentCheck implements ScanCheck
{
    public function __construct(private readonly int $minWords) {}

    public function run(array $subjects): array
    {
        $issues = [];
        foreach ($subjects as $scanned) {
            $subject = $scanned->subject;
            $words = (int) preg_match_all('/\S+/u', $subject->plainText);

            if ($words < $this->minWords) {
                $issues[] = new ScanIssue(
                    source: $scanned->source,
                    subjectKey: $subject->key,
                    url: $subject->url !== '' ? $subject->url : null,
                    check: 'thin-content',
                    severity: Severity::Notice,
                    message: "Page body is thin ({$words} words, under {$this->minWords}).",
                );
            }
        }

        return $issues;
    }
}
