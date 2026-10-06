<?php

declare(strict_types=1);

namespace Magna\Seo\Scorecard;

/**
 * The result of running the scorecard: whether every invariant held, and the
 * per-check outcomes. Intended to run in CI against a seeded site — if it fails,
 * the "SEO-friendly by construction" claim is false and the build knows.
 */
final readonly class ScorecardReport
{
    /**
     * @param  list<ScorecardResult>  $results
     */
    public function __construct(
        public bool $passed,
        public array $results,
    ) {}

    /**
     * @return array{passed: bool, results: list<array{id: string, label: string, passed: bool, detail: string}>}
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'results' => array_map(
                static fn (ScorecardResult $r): array => [
                    'id' => $r->id,
                    'label' => $r->label,
                    'passed' => $r->passed,
                    'detail' => $r->detail,
                ],
                $this->results,
            ),
        ];
    }
}
