<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis\Checks;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Contracts\AnalysisCheck;

/**
 * Internal and outbound links. Internal links are how a page passes authority to
 * the rest of the site and how crawlers find deeper content; a page with none is
 * a dead end. Outbound links to sources are a quality signal, not a leak.
 */
final class LinkCountCheck implements AnalysisCheck
{
    public function analyse(AnalysisInput $input): AnalysisResult
    {
        $outline = $input->outline;

        if (! $outline->hasMarkup) {
            return new AnalysisResult('links', AnalysisStatus::Ok, 'No markup available to count links.');
        }

        $internal = $outline->internalLinkCount();
        $outbound = $outline->outboundLinkCount();

        if ($internal === 0) {
            return new AnalysisResult('links', AnalysisStatus::Bad, 'No internal links; link to related pages so this one is not a dead end.');
        }

        if ($outbound === 0 && $input->wordCount() >= 600) {
            return new AnalysisResult('links', AnalysisStatus::Ok, "{$internal} internal link(s), no outbound ones; citing a source adds credibility.");
        }

        return new AnalysisResult('links', AnalysisStatus::Good, "{$internal} internal and {$outbound} outbound link(s).");
    }
}
