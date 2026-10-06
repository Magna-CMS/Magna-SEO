<?php

declare(strict_types=1);

namespace Magna\Seo\Scan\Checks;

use Magna\Seo\Analysis\DocumentOutline;
use Magna\Seo\Analysis\PageWeight;
use Magna\Seo\Contracts\ScanCheck;
use Magna\Seo\Scan\ScanIssue;
use Magna\Seo\Scan\Severity;

/**
 * Pages heavy enough to hurt their own ranking.
 *
 * Page experience is a ranking signal, and the slowest pages on a site are
 * usually a handful of outliers rather than a site-wide problem — one post with
 * an enormous embedded table, one landing page with a library pasted inline.
 * Finding those needs no crawler: the markup says so.
 */
final class HeavyPageCheck implements ScanCheck
{
    public function __construct(
        private readonly int $maxHtmlBytes = 150000,
        private readonly int $maxInlineBytes = 50000,
        private readonly int $maxDomNodes = 1500,
    ) {}

    public function run(array $subjects): array
    {
        $issues = [];

        foreach ($subjects as $scanned) {
            $html = $scanned->subject->raw['html'] ?? null;

            if (! is_string($html) || $html === '') {
                continue;
            }

            $weight = PageWeight::of($html, new DocumentOutline($html, $scanned->subject->url));
            $problem = $this->worstProblem($weight);

            if ($problem === null) {
                continue;
            }

            [$severity, $message] = $problem;

            $issues[] = new ScanIssue(
                source: $scanned->source,
                subjectKey: $scanned->subject->key,
                url: $scanned->subject->url,
                check: 'heavy-page',
                severity: $severity,
                message: $message,
            );
        }

        return $issues;
    }

    /**
     * One finding per page, not four. A page with every problem at once is still
     * one page to go and fix, and the heaviest item is what to fix first.
     *
     * @return array{0: Severity, 1: string}|null
     */
    private function worstProblem(PageWeight $weight): ?array
    {
        $inline = $weight->inlineScriptBytes + $weight->inlineStyleBytes;

        if ($weight->htmlBytes > $this->maxHtmlBytes * 2) {
            return [Severity::Warning, "Very heavy page: {$weight->humanSize()} of markup."];
        }

        if ($inline > $this->maxInlineBytes) {
            return [Severity::Warning, number_format($inline / 1024, 0).' KB of scripts/styles are inlined instead of cached files.'];
        }

        if ($weight->imagesWithoutDimensions > 0) {
            return [Severity::Warning, "{$weight->imagesWithoutDimensions} image(s) have no width/height, causing layout shift as they load."];
        }

        if ($weight->domNodes > $this->maxDomNodes) {
            return [Severity::Notice, "Around {$weight->domNodes} elements on the page; heavy DOMs render slowly on phones."];
        }

        if ($weight->htmlBytes > $this->maxHtmlBytes) {
            return [Severity::Notice, "Heavier than average: {$weight->humanSize()} of markup."];
        }

        return null;
    }
}
