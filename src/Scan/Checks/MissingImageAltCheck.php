<?php

declare(strict_types=1);

namespace Magna\Seo\Scan\Checks;

use Magna\Seo\Analysis\DocumentOutline;
use Magna\Seo\Contracts\ScanCheck;
use Magna\Seo\Scan\ScanIssue;
use Magna\Seo\Scan\Severity;

/**
 * Images with no alt text, site-wide. Alt text is both an accessibility
 * requirement and the only thing that makes an image findable in image search.
 *
 * Images explicitly marked decorative are excluded — they are meant to carry no
 * alt text, and flagging them would push authors into writing noise that screen
 * readers then read out.
 */
final class MissingImageAltCheck implements ScanCheck
{
    public function run(array $subjects): array
    {
        $issues = [];

        foreach ($subjects as $scanned) {
            $html = $scanned->subject->raw['html'] ?? null;

            if (! is_string($html) || $html === '') {
                continue;
            }

            $images = (new DocumentOutline($html))->meaningfulImages();
            $missing = count(array_filter($images, static fn (array $image): bool => $image['alt'] === ''));

            if ($missing === 0) {
                continue;
            }

            $issues[] = new ScanIssue(
                source: $scanned->source,
                subjectKey: $scanned->subject->key,
                url: $scanned->subject->url,
                check: 'missing-image-alt',
                severity: Severity::Warning,
                message: "{$missing} image(s) on this page have no alt text.",
            );
        }

        return $issues;
    }
}
