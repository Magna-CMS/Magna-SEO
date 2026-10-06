<?php

declare(strict_types=1);

namespace Magna\Seo\Contracts;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;

/**
 * One deterministic content-analysis check. Given the page's text and focus
 * keyword it returns a single verdict with an actionable message. Checks are
 * pure and stateless, so the analysis is reproducible and unit-testable.
 */
interface AnalysisCheck
{
    public function analyse(AnalysisInput $input): AnalysisResult;
}
