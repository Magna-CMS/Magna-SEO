<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis;

use Magna\Seo\Contracts\AnalysisCheck;

/**
 * Runs the content-analysis checks over a page and rolls their verdicts into a
 * 0–100 score. Deterministic and stateless: the same input always yields the
 * same score and the same per-check explanations, so results are reproducible
 * and never a black box.
 */
final class ContentAnalyser
{
    /**
     * @param  list<AnalysisCheck>  $checks
     */
    public function __construct(private readonly array $checks) {}

    public function analyse(AnalysisInput $input): AnalysisReport
    {
        $results = [];
        foreach ($this->checks as $check) {
            $results[] = $check->analyse($input);
        }

        return AnalysisReport::fromResults($results);
    }

    /**
     * The check identifiers this analyser runs, so callers (and tests) can see
     * the shipped set without instantiating every check.
     *
     * @return list<string>
     */
    public function checkIds(AnalysisInput $probe): array
    {
        return array_map(
            static fn (AnalysisCheck $check): string => $check->analyse($probe)->check,
            $this->checks,
        );
    }
}
