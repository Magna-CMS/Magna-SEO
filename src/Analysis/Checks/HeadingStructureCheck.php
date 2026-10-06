<?php

declare(strict_types=1);

namespace Magna\Seo\Analysis\Checks;

use Magna\Seo\Analysis\AnalysisInput;
use Magna\Seo\Analysis\AnalysisResult;
use Magna\Seo\Analysis\AnalysisStatus;
use Magna\Seo\Contracts\AnalysisCheck;

/**
 * Heading hygiene: exactly one H1, no skipped levels, and subheadings once the
 * page is long enough that a reader needs them to navigate.
 */
final class HeadingStructureCheck implements AnalysisCheck
{
    private const SUBHEADINGS_EXPECTED_AFTER = 300;

    public function analyse(AnalysisInput $input): AnalysisResult
    {
        $outline = $input->outline;

        if (! $outline->hasMarkup) {
            return new AnalysisResult('heading-structure', AnalysisStatus::Ok, 'No markup available to check heading structure.');
        }

        $h1 = $outline->headingsAtLevel(1);

        if ($h1 > 1) {
            return new AnalysisResult('heading-structure', AnalysisStatus::Bad, "There are {$h1} H1 headings; a page should have exactly one.");
        }

        $skipped = $this->firstSkippedLevel($outline->headings);

        if ($skipped !== null) {
            return new AnalysisResult('heading-structure', AnalysisStatus::Ok, "Heading levels jump straight to H{$skipped}; step down one level at a time.");
        }

        $subheadings = count($outline->headings) - $h1;

        if ($input->wordCount() >= self::SUBHEADINGS_EXPECTED_AFTER && $subheadings === 0) {
            return new AnalysisResult('heading-structure', AnalysisStatus::Bad, 'A page this long has no subheadings; break it up with H2s.');
        }

        return new AnalysisResult('heading-structure', AnalysisStatus::Good, 'Heading structure is sound.');
    }

    /**
     * @param  list<array{level: int, text: string}>  $headings
     */
    private function firstSkippedLevel(array $headings): ?int
    {
        $previous = 0;

        foreach ($headings as $heading) {
            if ($previous !== 0 && $heading['level'] > $previous + 1) {
                return $heading['level'];
            }

            $previous = $heading['level'];
        }

        return null;
    }
}
