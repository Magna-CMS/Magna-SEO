<?php

declare(strict_types=1);

namespace Magna\Seo\Scorecard;

/**
 * One pass/fail invariant in the "SEO-friendly by construction" scorecard, with a
 * short human-readable detail explaining the result.
 */
final readonly class ScorecardResult
{
    public function __construct(
        public string $id,
        public string $label,
        public bool $passed,
        public string $detail,
    ) {}
}
