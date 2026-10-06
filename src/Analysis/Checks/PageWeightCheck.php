<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis\Checks;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Analysis\PageWeight;
use Magna\Seo\Contracts\AnalysisCheck;

/**
 * Page weight, in the editor, while there is still time to do something about it.
 *
 * Reports the single worst problem rather than a list: an author who is told
 * four things at once fixes none of them, and the heaviest item is almost always
 * what actually costs the load time.
 */
final class PageWeightCheck implements AnalysisCheck
{
    public function __construct(
        private readonly int $maxHtmlBytes = 150000,
        private readonly int $maxInlineBytes = 50000,
        private readonly int $maxDomNodes = 1500,
    ) {}

    public function analyse(AnalysisInput $input): AnalysisResult
    {
        if (! $input->outline->hasMarkup) {
            return new AnalysisResult('page-weight', AnalysisStatus::Ok, 'No markup available to weigh this page.');
        }

        $weight = PageWeight::of($input->html, $input->outline);
        $inline = $weight->inlineScriptBytes + $weight->inlineStyleBytes;

        if ($weight->htmlBytes > $this->maxHtmlBytes * 2) {
            return new AnalysisResult('page-weight', AnalysisStatus::Bad,
                "This page is {$weight->humanSize()} of markup — far heavier than it needs to be. Split it, or move repeated blocks into includes.");
        }

        if ($inline > $this->maxInlineBytes) {
            return new AnalysisResult('page-weight', AnalysisStatus::Bad,
                number_format($inline / 1024, 0).' KB of scripts and styles are inlined in the body; move them to files the browser can cache.');
        }

        if ($weight->imagesWithoutDimensions > 0) {
            return new AnalysisResult('page-weight', AnalysisStatus::Bad,
                "{$weight->imagesWithoutDimensions} image(s) have no width and height, so the page jumps as they load.");
        }

        if ($weight->domNodes > $this->maxDomNodes) {
            return new AnalysisResult('page-weight', AnalysisStatus::Ok,
                "This page has around {$weight->domNodes} elements; deeply nested markup slows rendering on phones.");
        }

        if ($weight->htmlBytes > $this->maxHtmlBytes) {
            return new AnalysisResult('page-weight', AnalysisStatus::Ok,
                "This page is {$weight->humanSize()} of markup — on the heavy side, but not alarming.");
        }

        return new AnalysisResult('page-weight', AnalysisStatus::Good,
            "Page weight is fine ({$weight->humanSize()} of markup).");
    }
}
