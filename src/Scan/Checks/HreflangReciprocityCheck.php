<?php

declare(strict_types=1);

namespace Magna\Seo\Scan\Checks;

use Magna\Seo\Contracts\ScanCheck;
use Magna\Seo\Scan\ScanIssue;
use Magna\Seo\Scan\Severity;

/**
 * Verifies hreflang reciprocity: if page A lists page B as an alternate, B must
 * list A back, or search engines ignore the pairing.
 *
 * Only alternates that point at another *known* subject URL are checked. An
 * alternate that is a query-parameter variant of the same page (as docs uses for
 * translations) is not a distinct subject and is intentionally skipped, so this
 * check never false-flags that model.
 */
final class HreflangReciprocityCheck implements ScanCheck
{
    public function run(array $subjects): array
    {
        /** @var array<string, array<string, string>> $alternatesByUrl */
        $alternatesByUrl = [];
        foreach ($subjects as $scanned) {
            $alternatesByUrl[$scanned->subject->url] = $scanned->subject->alternates;
        }

        $issues = [];
        foreach ($subjects as $scanned) {
            $subject = $scanned->subject;
            if (count($subject->alternates) < 2) {
                continue;
            }

            foreach ($subject->alternates as $altUrl) {
                if ($altUrl === $subject->url) {
                    continue;
                }

                $targetAlternates = $alternatesByUrl[$altUrl] ?? null;
                if ($targetAlternates === null) {
                    continue;
                }

                if (! in_array($subject->url, $targetAlternates, true)) {
                    $issues[] = new ScanIssue(
                        source: $scanned->source,
                        subjectKey: $subject->key,
                        url: $subject->url !== '' ? $subject->url : null,
                        check: 'hreflang-reciprocity',
                        severity: Severity::Warning,
                        message: "hreflang alternate {$altUrl} does not link back to this page.",
                    );
                }
            }
        }

        return $issues;
    }
}
