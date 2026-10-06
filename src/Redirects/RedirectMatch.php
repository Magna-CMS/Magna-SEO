<?php

declare(strict_types=1);

namespace Magna\Seo\Redirects;

/**
 * The resolved outcome of matching a path against the redirect rules: where to
 * send the visitor and with which status. A 410 carries no target.
 */
final readonly class RedirectMatch
{
    /**
     * @param  int  $ruleId  The rule that produced this outcome, for hit counting.
     * @param  int  $hops  How many rules were followed; > 1 means a chain was collapsed.
     */
    public function __construct(
        public ?string $target,
        public int $status,
        public int $ruleId,
        public int $hops = 1,
    ) {}

    public function isGone(): bool
    {
        return $this->status === 410;
    }
}
